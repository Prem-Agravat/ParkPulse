<?php
/**
 * User Profile & Password Management
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireLogin();

$userId = Auth::id();
$db = Database::getInstance()->getConnection();

$error = null;
$success = null;

// Fetch fresh user record
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        $error = 'Security token invalid. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $name = Security::sanitizeString($_POST['name'] ?? '', 100);
            $phoneRaw = Security::sanitizeString($_POST['phone'] ?? '', 20);

            if (empty($name)) {
                $error = 'Name cannot be empty.';
            } elseif (!empty($phoneRaw) && !Security::validateIndianMobile($phoneRaw)) {
                $error = 'Please enter a valid 10-digit Indian mobile number (e.g. +91 98765 43210).';
            } else {
                $normalizedPhone = !empty($phoneRaw) ? Security::normalizeIndianMobile($phoneRaw) : null;
                $updateStmt = $db->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?");
                $updateStmt->execute([$name, $normalizedPhone, $userId]);
                
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['phone'] = $normalizedPhone;
                $currentUser['name'] = $name;
                $currentUser['phone'] = $normalizedPhone;

                AuditLogger::log('PROFILE_UPDATED', 'users', $userId, 'User updated profile details.');
                Flash::set('success', 'Profile information updated successfully.');
                header('Location: profile.php');
                exit;
            }
        } elseif ($action === 'change_password') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if (!Security::verifyPassword($currentPassword, $currentUser['password_hash'])) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($newPassword) < 8) {
                $error = 'New password must be at least 8 characters long.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'New passwords do not match.';
            } else {
                $newHash = Security::hashPassword($newPassword);
                $pwdStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $pwdStmt->execute([$newHash, $userId]);

                AuditLogger::log('PASSWORD_CHANGED', 'users', $userId, 'User changed password.');
                Flash::set('success', 'Password changed successfully.');
                header('Location: profile.php');
                exit;
            }
        }
    }
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h1 class="h3 fw-bold mb-1">Account Profile</h1>
                    <p class="text-secondary small mb-0">Manage your profile details and security settings</p>
                </div>
                <span class="badge rounded-pill <?= $currentUser['role'] === 'admin' ? 'bg-danger' : ($currentUser['role'] === 'attendant' ? 'bg-warning text-dark' : 'bg-primary') ?> px-3 py-2 fs-6 text-uppercase">
                    <?= Security::escape($currentUser['role']) ?>
                </span>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
                    <div><?= Security::escape($error) ?></div>
                </div>
            <?php endif; ?>

            <div class="card card-custom border-0 mb-4 shadow-sm">
                <div class="card-header card-header-custom d-flex align-items-center gap-2">
                    <i class="bi bi-person-lines-fill text-primary"></i>
                    <span>Personal Information</span>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="profile.php">
                        <?= Security::csrfInput() ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label fw-semibold small text-secondary">Full Name</label>
                                <input type="text" class="form-control" id="name" name="name" value="<?= Security::escape($currentUser['name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-semibold small text-secondary">Email Address (Immutable)</label>
                                <input type="email" class="form-control bg-light" id="email" value="<?= Security::escape($currentUser['email']) ?>" readonly disabled>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="phone" class="form-label fw-semibold small text-secondary">Contact Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone" value="<?= Security::escape($currentUser['phone'] ?? '') ?>" placeholder="+1 555-0100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-secondary">Member Since</label>
                                <input type="text" class="form-control bg-light" value="<?= date('M d, Y', strtotime($currentUser['created_at'])) ?>" readonly disabled>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary-custom">
                            <i class="bi bi-floppy me-1"></i> Save Profile Details
                        </button>
                    </form>
                </div>
            </div>

            <div class="card card-custom border-0 shadow-sm">
                <div class="card-header card-header-custom d-flex align-items-center gap-2">
                    <i class="bi bi-key-fill text-warning"></i>
                    <span>Change Password</span>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="profile.php">
                        <?= Security::csrfInput() ?>
                        <input type="hidden" name="action" value="change_password">

                        <div class="mb-3">
                            <label for="current_password" class="form-label fw-semibold small text-secondary">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="new_password" class="form-label fw-semibold small text-secondary">New Password (Min 8 chars)</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                            </div>
                            <div class="col-md-6">
                                <label for="confirm_password" class="form-label fw-semibold small text-secondary">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-dark-custom">
                            <i class="bi bi-shield-check me-1"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
