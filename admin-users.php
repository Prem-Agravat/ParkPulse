<?php
/**
 * Administrator Staff & User Management (RBAC)
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireRole('admin');

$db = Database::getInstance()->getConnection();

// Handle Actions (Create Attendant, Update Status, Reassign Facility, Reset Password)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        Flash::set('danger', 'Security session expired.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_staff') {
            $name = Security::sanitizeString($_POST['name'] ?? '', 100);
            $email = Security::sanitizeEmail($_POST['email'] ?? '');
            $phoneInput = Security::sanitizeString($_POST['phone'] ?? '', 30);
            $phone = !empty($phoneInput) ? Security::normalizeIndianMobile($phoneInput) : null;
            $role = Security::sanitizeString($_POST['role'] ?? 'attendant');
            $password = (string)($_POST['password'] ?? 'Attendant@2026');
            $facilityId = (int)($_POST['facility_id'] ?? 0);
            $shiftName = Security::sanitizeString($_POST['shift_name'] ?? 'General Shift', 50);

            if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Flash::set('danger', 'Valid name and email are required.');
            } elseif (!empty($phoneInput) && !Security::validateIndianMobile($phoneInput)) {
                Flash::set('danger', 'Please enter a valid 10-digit Indian mobile number (e.g. +91 98250 12345).');
            } else {
                try {
                    $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
                    $chk->execute([$email]);
                    if ($chk->fetch()) {
                        Flash::set('danger', 'Email already exists.');
                    } else {
                        $hash = Security::hashPassword($password);
                        $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, phone, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
                        $stmt->execute([$name, $email, $hash, $phone, $role]);
                        $newUserId = (int)$db->lastInsertId();

                        if ($role === 'attendant' && $facilityId > 0) {
                            $assign = $db->prepare("INSERT INTO attendant_assignments (attendant_id, facility_id, shift_name, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE facility_id = VALUES(facility_id), shift_name = VALUES(shift_name)");
                            $assign->execute([$newUserId, $facilityId, $shiftName]);
                        }

                        AuditLogger::log('USER_STAFF_CREATED', 'users', $newUserId, "Created staff user {$email} with role [{$role}].");
                        Flash::set('success', "Staff user '{$name}' created successfully.");
                    }
                } catch (\Throwable $e) {
                    Flash::set('danger', 'DB Error: ' . $e->getMessage());
                }
            }
            header('Location: admin-users.php');
            exit;
        }

        if ($action === 'toggle_user_status') {
            $uId = (int)($_POST['user_id'] ?? 0);
            $newStatus = Security::sanitizeString($_POST['new_status'] ?? 'active');
            if ($uId === Auth::id()) {
                Flash::set('danger', 'You cannot alter your own admin account status.');
            } else {
                $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $uId]);
                AuditLogger::log('USER_STATUS_TOGGLED', 'users', $uId, "Status updated to {$newStatus}.");
                Flash::set('success', "User account status updated to {$newStatus}.");
            }
            header('Location: admin-users.php');
            exit;
        }

        if ($action === 'reset_password') {
            $uId = (int)($_POST['user_id'] ?? 0);
            $newPass = (string)($_POST['new_password'] ?? 'ParkPulse@2026');
            if (strlen($newPass) < 8) {
                Flash::set('danger', 'Password must be at least 8 characters.');
            } else {
                $hash = Security::hashPassword($newPass);
                $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $uId]);
                AuditLogger::log('USER_PASSWORD_RESET', 'users', $uId, "Admin reset password for user ID {$uId}.");
                Flash::set('success', 'User password has been reset successfully.');
            }
            header('Location: admin-users.php');
            exit;
        }
    }
}

// Fetch users with attendant facility assignments
$roleFilter = Security::sanitizeString($_GET['role'] ?? '');
$sql = "SELECT u.*, 
        f.name AS assigned_facility_name, f.id AS assigned_facility_id, aa.shift_name,
        (SELECT COUNT(*) FROM vehicles v WHERE v.user_id = u.id) AS vehicle_count,
        (SELECT COUNT(*) FROM reservations r WHERE r.user_id = u.id) AS booking_count
        FROM users u 
        LEFT JOIN attendant_assignments aa ON u.id = aa.attendant_id AND aa.is_active = 1
        LEFT JOIN facilities f ON aa.facility_id = f.id";

if (!empty($roleFilter) && in_array($roleFilter, ['admin', 'attendant', 'user'], true)) {
    $sql .= " WHERE u.role = '{$roleFilter}'";
}
$sql .= " ORDER BY u.created_at DESC";

$users = $db->query($sql)->fetchAll();
$facilities = $db->query("SELECT id, name FROM facilities WHERE status = 'active' ORDER BY name ASC")->fetchAll();

$pageTitle = 'Staff & User Management';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 px-3 py-4">
    <!-- Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark">Staff & User Management</h1>
            <p class="text-secondary small mb-0">Role-based access control (RBAC), attendant station assignments & account security</p>
        </div>
        <button type="button" class="btn btn-primary-custom shadow-sm" data-bs-toggle="modal" data-bs-target="#createStaffModal">
            <i class="bi bi-person-plus-fill me-1"></i> Add Attendant / Staff
        </button>
    </div>

    <!-- Filter Tabs -->
    <div class="d-flex gap-2 mb-3">
        <a href="admin-users.php" class="btn btn-sm <?= empty($roleFilter) ? 'btn-dark' : 'btn-outline-secondary' ?>">All Users (<?= count($users) ?>)</a>
        <a href="admin-users.php?role=attendant" class="btn btn-sm <?= $roleFilter === 'attendant' ? 'btn-warning text-dark fw-bold' : 'btn-outline-secondary' ?>">Gate Attendants</a>
        <a href="admin-users.php?role=user" class="btn btn-sm <?= $roleFilter === 'user' ? 'btn-primary' : 'btn-outline-secondary' ?>">Drivers / Customers</a>
        <a href="admin-users.php?role=admin" class="btn btn-sm <?= $roleFilter === 'admin' ? 'btn-danger' : 'btn-outline-secondary' ?>">Administrators</a>
    </div>

    <!-- Users Table -->
    <div class="card card-custom border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-custom mb-0">
                <thead>
                    <tr>
                        <th>User / Contact</th>
                        <th>Role</th>
                        <th>Station / Facility Assignment</th>
                        <th>Vehicles & Activity</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><?= Security::escape($u['name']) ?></div>
                                <small class="text-muted"><?= Security::escape($u['email']) ?> • <?= Security::escape(Security::formatIndianMobile((string)($u['phone'] ?? ''))) ?: 'No phone' ?></small>
                            </td>
                            <td>
                                <span class="badge <?= match($u['role']) {
                                    'admin' => 'bg-danger',
                                    'attendant' => 'bg-warning text-dark',
                                    default => 'bg-primary'
                                } ?> text-uppercase">
                                    <?= Security::escape($u['role']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'attendant' && !empty($u['assigned_facility_name'])): ?>
                                    <div class="fw-semibold text-dark small"><i class="bi bi-building text-primary me-1"></i> <?= Security::escape($u['assigned_facility_name']) ?></div>
                                    <small class="text-muted"><?= Security::escape($u['shift_name'] ?? 'General Shift') ?></small>
                                <?php elseif ($u['role'] === 'attendant'): ?>
                                    <span class="badge bg-secondary bg-opacity-25 text-secondary">Unassigned</span>
                                <?php else: ?>
                                    <span class="text-muted small">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'user'): ?>
                                    <div class="small"><i class="bi bi-car-front text-primary me-1"></i> <?= $u['vehicle_count'] ?> vehicles</div>
                                    <small class="text-muted"><i class="bi bi-ticket text-info me-1"></i> <?= $u['booking_count'] ?> bookings</small>
                                <?php else: ?>
                                    <span class="text-muted small">Staff Member</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $u['status'] === 'active' ? 'badge-available' : 'badge-occupied' ?> text-uppercase">
                                    <?= Security::escape($u['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <?php if ($u['id'] !== Auth::id()): ?>
                                        <form method="POST" action="admin-users.php" class="d-inline">
                                            <?= Security::csrfInput() ?>
                                            <input type="hidden" name="action" value="toggle_user_status">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $u['status'] === 'active' ? 'suspended' : 'active' ?>">
                                            <button type="submit" class="btn btn-sm <?= $u['status'] === 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="Toggle Active / Suspended">
                                                <i class="bi <?= $u['status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.openResetModal(<?= (int)$u['id'] ?>, '<?= Security::escape($u['name']) ?>')" title="Reset Password">
                                        <i class="bi bi-key"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Staff Modal -->
<div class="modal fade" id="createStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">Create Staff / Attendant</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="admin-users.php">
                <div class="modal-body p-4">
                    <?= Security::csrfInput() ?>
                    <input type="hidden" name="action" value="create_staff">

                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Full Name *</label>
                        <input type="text" class="form-control" name="name" placeholder="e.g. Krunal Solanki" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-7">
                            <label class="form-label small text-secondary fw-semibold">Email Address *</label>
                            <input type="email" class="form-control" name="email" placeholder="krunal@parkpulse.com" required>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small text-secondary fw-semibold">Indian Mobile (+91)</label>
                            <input type="tel" class="form-control font-monospace" name="phone" placeholder="+91 98250 12345">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small text-secondary fw-semibold">Role *</label>
                            <select class="form-select" name="role" id="staff_role">
                                <option value="attendant" selected>Gate Attendant</option>
                                <option value="admin">Administrator</option>
                                <option value="user">Driver / Customer</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small text-secondary fw-semibold">Default Password *</label>
                            <input type="text" class="form-control font-monospace" name="password" value="Attendant@2026" required>
                        </div>
                    </div>

                    <div id="attendantAssignmentBox" class="bg-light p-3 rounded-3 border mb-3">
                        <h6 class="fw-bold text-dark small mb-2"><i class="bi bi-geo-alt-fill text-primary"></i> Station Facility Assignment</h6>
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-0">Assigned Facility</label>
                            <select class="form-select form-select-sm" name="facility_id">
                                <option value="0">-- Select Station --</option>
                                <?php foreach ($facilities as $fac): ?>
                                    <option value="<?= (int)$fac['id'] ?>"><?= Security::escape($fac['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-0">Shift Name</label>
                            <input type="text" class="form-control form-control-sm" name="shift_name" value="Morning Shift (07:00 - 15:00)">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h6 class="modal-title fw-bold" id="resetModalTitle">Reset Password</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="admin-users.php">
                <div class="modal-body p-3">
                    <?= Security::csrfInput() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" id="reset_user_id" value="0">

                    <label class="form-label small text-secondary fw-semibold">New Password</label>
                    <input type="password" class="form-control font-monospace" name="new_password" value="ParkPulse@2026" required minlength="8">
                    <small class="text-muted d-block mt-1">Minimum 8 characters.</small>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">Set Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const resetModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
    window.openResetModal = function(id, name) {
        document.getElementById('reset_user_id').value = id;
        document.getElementById('resetModalTitle').innerText = `Reset: ${name}`;
        resetModal.show();
    };
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
