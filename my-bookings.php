<?php
/**
 * User Reservations & Pass History
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireLogin();

$userId = Auth::id();
$db = Database::getInstance()->getConnection();

// Fetch all reservations for this driver
$stmt = $db->prepare("SELECT r.*, 
    f.name AS facility_name, f.city AS facility_city, f.address AS facility_address,
    s.slot_number, s.floor_level, s.zone_name, s.is_ev_charging,
    v.make_model
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    JOIN slots s ON r.slot_id = s.id
    LEFT JOIN vehicles v ON r.vehicle_id = v.id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC");
$stmt->execute([$userId]);
$bookings = $stmt->fetchAll();

$upcoming = [];
$completed = [];
$cancelled = [];

foreach ($bookings as $b) {
    if (in_array($b['status'], ['confirmed', 'checked_in', 'pending'], true)) {
        $upcoming[] = $b;
    } elseif ($b['status'] === 'completed') {
        $completed[] = $b;
    } else {
        $cancelled[] = $b;
    }
}

$pageTitle = 'My Parking Bookings';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">My Parking Bookings</h1>
            <p class="text-secondary small mb-0">Manage active passes, view digital QR tickets, or review your parking receipts</p>
        </div>
        <a href="index.php" class="btn btn-primary-custom shadow-sm">
            <i class="bi bi-plus-lg me-1"></i> Book New Parking Slot
        </a>
    </div>

    <!-- Bookings Tabs -->
    <ul class="nav nav-pills mb-4" id="bookingHistoryTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active fw-bold position-relative" id="upcoming-tab" data-bs-toggle="pill" data-bs-target="#upcoming-pane" type="button" role="tab">
                <i class="bi bi-ticket-perforated me-1"></i> Active & Upcoming
                <?php if (count($upcoming) > 0): ?>
                    <span class="badge bg-primary ms-1"><?= count($upcoming) ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold" id="completed-tab" data-bs-toggle="pill" data-bs-target="#completed-pane" type="button" role="tab">
                <i class="bi bi-check2-circle me-1"></i> Completed (<?= count($completed) ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold" id="cancelled-tab" data-bs-toggle="pill" data-bs-target="#cancelled-pane" type="button" role="tab">
                <i class="bi bi-x-circle me-1"></i> Cancelled (<?= count($cancelled) ?>)
            </button>
        </li>
    </ul>

    <div class="tab-content" id="bookingHistoryContent">
        <!-- 1. Upcoming & Active Bookings -->
        <div class="tab-pane fade show active" id="upcoming-pane" role="tabpanel">
            <?php if (empty($upcoming)): ?>
                <div class="card card-custom border-0 p-5 text-center shadow-sm">
                    <i class="bi bi-calendar-x text-secondary fs-1 mb-2"></i>
                    <h2 class="h5 fw-bold text-dark">No Active or Upcoming Reservations</h2>
                    <p class="text-secondary small mb-3">You do not have any active parking passes right now.</p>
                    <div>
                        <a href="index.php" class="btn btn-primary-custom btn-sm">Find Parking Near You</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($upcoming as $b): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card card-custom h-100 border-0 shadow-sm">
                                <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
                                    <span class="font-monospace fw-bold small text-primary"><?= Security::escape($b['booking_reference']) ?></span>
                                    <span class="badge <?= $b['status'] === 'checked_in' ? 'bg-success' : 'bg-warning text-dark' ?> text-uppercase">
                                        <?= Security::escape($b['status']) ?>
                                    </span>
                                </div>
                                <div class="card-body p-4 d-flex flex-column justify-content-between">
                                    <div>
                                        <h2 class="h5 fw-bold mb-1 text-dark"><?= Security::escape($b['facility_name']) ?></h2>
                                        <p class="text-secondary small mb-3"><i class="bi bi-geo-alt text-primary"></i> <?= Security::escape($b['facility_city']) ?></p>

                                        <div class="bg-light p-3 rounded-3 border mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="small text-secondary">Slot:</span>
                                                <strong class="font-monospace text-primary"><?= Security::escape($b['slot_number']) ?> (<?= Security::escape($b['floor_level']) ?>)</strong>
                                            </div>
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="small text-secondary">Vehicle:</span>
                                                <strong class="font-monospace text-dark"><?= Security::escape($b['vehicle_plate']) ?></strong>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="small text-secondary">Check-In:</span>
                                                <strong class="small text-dark"><?= date('M d, h:i A', strtotime($b['start_time'])) ?></strong>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="pt-3 border-top d-flex gap-2">
                                        <a href="pass.php?ref=<?= urlencode($b['booking_reference']) ?>" class="btn btn-primary-custom btn-sm flex-grow-1 fw-bold">
                                            <i class="bi bi-qr-code me-1"></i> Open QR Pass
                                        </a>
                                        <?php if ($b['status'] === 'confirmed'): ?>
                                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="window.cancelBookingCard('<?= Security::escape($b['booking_reference']) ?>')">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2. Completed Bookings -->
        <div class="tab-pane fade" id="completed-pane" role="tabpanel">
            <?php if (empty($completed)): ?>
                <div class="card card-custom border-0 p-5 text-center shadow-sm">
                    <p class="text-secondary small mb-0">No completed parking sessions yet.</p>
                </div>
            <?php else: ?>
                <div class="card card-custom border-0 shadow-sm overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Ref / Booking</th>
                                    <th>Facility & Slot</th>
                                    <th>Vehicle</th>
                                    <th>Check-in / Check-out</th>
                                    <th>Paid Amount</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($completed as $b): ?>
                                    <tr>
                                        <td>
                                            <span class="font-monospace fw-bold text-dark"><?= Security::escape($b['booking_reference']) ?></span>
                                            <div class="text-muted small"><?= date('M d, Y', strtotime($b['created_at'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= Security::escape($b['facility_name']) ?></div>
                                            <small class="text-muted font-monospace">Slot <?= Security::escape($b['slot_number']) ?> (<?= Security::escape($b['floor_level']) ?>)</small>
                                        </td>
                                        <td>
                                            <span class="font-monospace text-dark fw-bold"><?= Security::escape($b['vehicle_plate']) ?></span>
                                        </td>
                                        <td>
                                            <div class="small"><?= date('M d, h:i A', strtotime($b['start_time'])) ?></div>
                                            <div class="small text-muted">to <?= date('M d, h:i A', strtotime($b['end_time'])) ?></div>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-success">₹<?= number_format((float)$b['total_amount'], 2) ?></span>
                                        </td>
                                        <td>
                                            <a href="pass.php?ref=<?= urlencode($b['booking_reference']) ?>" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-receipt"></i> Pass
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 3. Cancelled Bookings -->
        <div class="tab-pane fade" id="cancelled-pane" role="tabpanel">
            <?php if (empty($cancelled)): ?>
                <div class="card card-custom border-0 p-5 text-center shadow-sm">
                    <p class="text-secondary small mb-0">No cancelled reservations.</p>
                </div>
            <?php else: ?>
                <div class="card card-custom border-0 shadow-sm overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Ref / Booking</th>
                                    <th>Facility</th>
                                    <th>Vehicle</th>
                                    <th>Original Date</th>
                                    <th>Cancellation Reason</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cancelled as $b): ?>
                                    <tr>
                                        <td>
                                            <span class="font-monospace fw-bold text-dark"><?= Security::escape($b['booking_reference']) ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= Security::escape($b['facility_name']) ?></div>
                                            <small class="text-muted">Slot <?= Security::escape($b['slot_number']) ?></small>
                                        </td>
                                        <td>
                                            <span class="font-monospace text-dark"><?= Security::escape($b['vehicle_plate']) ?></span>
                                        </td>
                                        <td>
                                            <div class="small text-muted"><?= date('M d, Y h:i A', strtotime($b['start_time'])) ?></div>
                                        </td>
                                        <td>
                                            <span class="small text-secondary"><?= Security::escape($b['cancellation_reason'] ?? 'User cancelled') ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25">Refunded</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
window.cancelBookingCard = async function(ref) {
    if (!confirm(`Cancel reservation ${ref}? A full refund will be simulated back to your payment source.`)) {
        return;
    }
    try {
        const res = await ParkPulse.fetchJson('api/cancel-booking.php', {
            method: 'POST',
            body: JSON.stringify({ booking_reference: ref })
        });
        if (res.success) {
            ParkPulse.showToast('success', res.message, 'Cancelled');
            setTimeout(() => window.location.reload(), 1200);
        }
    } catch (err) {
        ParkPulse.showToast('danger', err.message, 'Cancellation Failed');
    }
};
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
