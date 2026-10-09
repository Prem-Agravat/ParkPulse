<?php
/**
 * Administrator Master Reservations & Payments Inspector
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireRole('admin');

$db = Database::getInstance()->getConnection();

$statusFilter = Security::sanitizeString($_GET['status'] ?? '');
$search = Security::sanitizeString($_GET['q'] ?? '');
$export = !empty($_GET['export']) && $_GET['export'] === 'csv';

// Base Query
$sql = "SELECT r.*, 
        f.name AS facility_name, f.city AS facility_city,
        s.slot_number, s.floor_level, s.zone_name,
        u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
        v.make_model,
        p.transaction_ref, p.payment_method, p.status AS payment_status, p.gateway_provider
        FROM reservations r
        JOIN facilities f ON r.facility_id = f.id
        JOIN slots s ON r.slot_id = s.id
        JOIN users u ON r.user_id = u.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN payments p ON r.id = p.reservation_id AND p.payment_type = 'reservation_fee'";

$params = [];
$conditions = [];

if (!empty($statusFilter) && in_array($statusFilter, ['confirmed', 'checked_in', 'completed', 'cancelled'], true)) {
    $conditions[] = "r.status = ?";
    $params[] = $statusFilter;
}

if (!empty($search)) {
    $conditions[] = "(r.booking_reference LIKE ? OR r.vehicle_plate LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR f.name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY r.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

// CSV Export Handler
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=parkpulse_reservations_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Booking Ref', 'Driver Name', 'Email', 'Plate', 'Facility', 'Slot', 'Start Time', 'End Time', 'Status', 'Paid Amount', 'Txn Ref', 'Created At']);
    foreach ($reservations as $row) {
        fputcsv($out, [
            $row['booking_reference'],
            $row['user_name'],
            $row['user_email'],
            $row['vehicle_plate'],
            $row['facility_name'],
            $row['slot_number'],
            $row['start_time'],
            $row['end_time'],
            $row['status'],
            $row['total_amount'],
            $row['transaction_ref'] ?? 'N/A',
            $row['created_at']
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'All Bookings & Payments';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 px-3 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark">Master Bookings & Payments Inspector</h1>
            <p class="text-secondary small mb-0">Audit live parking tickets, transaction records, overstay fees, and customer bookings</p>
        </div>
        <div class="d-flex gap-2">
            <a href="admin-reservations.php?export=csv<?= !empty($statusFilter) ? '&status=' . urlencode($statusFilter) : '' ?><?= !empty($search) ? '&q=' . urlencode($search) : '' ?>" class="btn btn-outline-success btn-sm">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
            </a>
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="card card-custom border-0 p-3 shadow-sm mb-4">
        <form method="GET" action="admin-reservations.php">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Search reference, plate, customer, or facility..." value="<?= Security::escape($search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- All Statuses --</option>
                        <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed (Upcoming)</option>
                        <option value="checked_in" <?= $statusFilter === 'checked_in' ? 'selected' : '' ?>>Checked-In (On-Premise)</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-dark-custom btn-sm flex-grow-1">Filter</button>
                    <a href="admin-reservations.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Master Bookings Table -->
    <div class="card card-custom border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-custom mb-0">
                <thead>
                    <tr>
                        <th>Booking Ref</th>
                        <th>Customer / Driver</th>
                        <th>Vehicle Plate</th>
                        <th>Facility & Slot</th>
                        <th>Scheduled Window</th>
                        <th>Status</th>
                        <th>Amount Paid</th>
                        <th>Pass</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reservations)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-secondary">No bookings match the search criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reservations as $r): ?>
                            <tr>
                                <td>
                                    <span class="font-monospace fw-bold text-primary"><?= Security::escape($r['booking_reference']) ?></span>
                                    <div class="text-muted small"><?= date('M d, Y', strtotime($r['created_at'])) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= Security::escape($r['user_name']) ?></div>
                                    <small class="text-muted"><?= Security::escape($r['user_email']) ?></small>
                                </td>
                                <td>
                                    <span class="font-monospace fw-bold text-dark bg-light px-2 py-1 rounded border"><?= Security::escape($r['vehicle_plate']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= Security::escape($r['facility_name']) ?></div>
                                    <small class="badge bg-light text-secondary border font-monospace"><?= Security::escape($r['slot_number']) ?> (<?= Security::escape($r['floor_level']) ?>)</small>
                                </td>
                                <td>
                                    <div class="small"><?= date('M d, h:i A', strtotime($r['start_time'])) ?></div>
                                    <div class="small text-muted">to <?= date('M d, h:i A', strtotime($r['end_time'])) ?></div>
                                </td>
                                <td>
                                    <span class="badge <?= match($r['status']) {
                                        'confirmed' => 'bg-warning text-dark',
                                        'checked_in' => 'bg-success',
                                        'completed' => 'bg-secondary',
                                        'cancelled' => 'bg-danger',
                                        default => 'bg-info'
                                    } ?> text-uppercase">
                                        <?= Security::escape($r['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success">₹<?= number_format((float)$r['total_amount'], 2) ?></span>
                                    <?php if ($r['overtime_amount'] > 0): ?>
                                        <div class="badge bg-danger bg-opacity-10 text-danger">+₹<?= number_format((float)$r['overtime_amount'], 2) ?> overstay</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="pass.php?ref=<?= urlencode($r['booking_reference']) ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                        <i class="bi bi-qr-code"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
