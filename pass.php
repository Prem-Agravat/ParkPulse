<?php
/**
 * Digital QR Parking Ticket Pass
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireLogin();

$ref = Security::sanitizeString($_GET['ref'] ?? '');
if (empty($ref)) {
    Flash::set('danger', 'Invalid booking reference.');
    header('Location: my-bookings.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$userId = Auth::id();
$role = Auth::role();

// Fetch reservation details
$sql = "SELECT r.*, 
        f.name AS facility_name, f.address AS facility_address, f.city AS facility_city, f.latitude, f.longitude, f.grace_period_mins,
        s.slot_number, s.floor_level, s.zone_name, s.slot_type, s.is_ev_charging,
        v.make_model, v.color,
        u.name AS driver_name, u.phone AS driver_phone, u.email AS driver_email
        FROM reservations r
        JOIN facilities f ON r.facility_id = f.id
        JOIN slots s ON r.slot_id = s.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        JOIN users u ON r.user_id = u.id
        WHERE r.booking_reference = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$ref]);
$booking = $stmt->fetch();

if (!$booking) {
    Flash::set('danger', 'Parking reservation pass not found.');
    header('Location: my-bookings.php');
    exit;
}

// Authorization check: User can only see their own pass, Attendant/Admin can view any pass
if ($role !== 'admin' && $role !== 'attendant' && (int)$booking['user_id'] !== $userId) {
    Flash::set('danger', 'Unauthorized to view this reservation pass.');
    header('Location: index.php');
    exit;
}

$pageTitle = 'Digital Pass — ' . $booking['booking_reference'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <a href="my-bookings.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back to My Bookings
        </a>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-dark-custom btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print / Save PDF
            </button>
            <?php if ($booking['status'] === 'confirmed'): ?>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="window.promptCancelBooking('<?= Security::escape($booking['booking_reference']) ?>')">
                    <i class="bi bi-x-circle me-1"></i> Cancel Booking
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ticket Pass Card Component -->
    <div class="ticket-pass">
        <!-- Ticket Header -->
        <div class="ticket-pass-header">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="badge bg-primary px-3 py-1 font-monospace fw-bold">
                    <?= Security::escape($booking['booking_reference']) ?>
                </span>
                <span class="badge <?= match($booking['status']) {
                    'confirmed' => 'bg-warning text-dark',
                    'checked_in' => 'bg-success',
                    'completed' => 'bg-secondary',
                    'cancelled' => 'bg-danger',
                    default => 'bg-info'
                } ?> text-uppercase px-2 py-1">
                    <?= Security::escape($booking['status']) ?>
                </span>
            </div>
            <h2 class="h5 fw-bold text-white mb-1"><?= Security::escape($booking['facility_name']) ?></h2>
            <p class="small text-light text-opacity-75 mb-0">
                <i class="bi bi-geo-alt-fill text-primary"></i> <?= Security::escape($booking['facility_address']) ?>, <?= Security::escape($booking['facility_city']) ?>
            </p>
        </div>

        <!-- Ticket Body with QR -->
        <div class="ticket-pass-body text-center">
            <!-- QR Code Box -->
            <div class="qr-display-box mb-3">
                <div id="qrcode"></div>
            </div>

            <p class="small text-muted mb-3 font-monospace">
                Scan at Gate Barrier or present to Attendant
            </p>

            <!-- Dynamic Countdown Alert -->
            <div id="passCountdownBanner" class="alert alert-light border py-2 px-3 mb-3 small d-flex align-items-center justify-content-center gap-2">
                <i class="bi bi-stopwatch text-primary fs-5"></i>
                <span id="countdownText" class="fw-semibold text-dark">Calculating schedule...</span>
            </div>

            <!-- Stalls & Vehicle Grid -->
            <div class="row g-2 text-start bg-light p-3 rounded-4 border mb-3">
                <div class="col-6">
                    <small class="text-secondary d-block">Allocated Slot</small>
                    <strong class="fs-5 text-primary font-monospace"><?= Security::escape($booking['slot_number']) ?></strong>
                    <div class="small text-muted"><?= Security::escape($booking['floor_level']) ?> (<?= Security::escape($booking['zone_name']) ?>)</div>
                </div>
                <div class="col-6">
                    <small class="text-secondary d-block">Vehicle Plate</small>
                    <strong class="fs-6 font-monospace text-dark bg-dark text-white px-2 py-1 rounded d-inline-block">
                        <?= Security::escape($booking['vehicle_plate']) ?>
                    </strong>
                    <div class="small text-muted"><?= Security::escape($booking['make_model'] ?? 'Vehicle') ?></div>
                </div>
            </div>

            <!-- Timing Details -->
            <div class="row g-2 text-start small mb-3">
                <div class="col-6">
                    <span class="text-secondary d-block">Check-In From:</span>
                    <strong class="text-dark"><?= date('D, M d • h:i A', strtotime($booking['start_time'])) ?></strong>
                </div>
                <div class="col-6">
                    <span class="text-secondary d-block">Valid Until:</span>
                    <strong class="text-dark"><?= date('D, M d • h:i A', strtotime($booking['end_time'])) ?></strong>
                </div>
            </div>

            <!-- Price & Gate Info -->
            <div class="d-flex justify-content-between align-items-center pt-2 border-top small">
                <span class="text-secondary">Paid Amount (Simulated):</span>
                <span class="fw-bold text-success fs-6">₹<?= number_format((float)$booking['total_amount'], 2) ?></span>
            </div>
        </div>

        <!-- Ticket Perforated Divider -->
        <div class="ticket-notch-divider">
            <div class="ticket-dash"></div>
        </div>

        <!-- Ticket Footer Directions CTA -->
        <div class="p-3 bg-light text-center">
            <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $booking['latitude'] ?>,<?= $booking['longitude'] ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm w-100 fw-semibold">
                <i class="bi bi-map me-1"></i> Open Navigation in Maps
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Render high-res QR code
    const qrToken = <?= json_encode($booking['qr_token']) ?>;
    ParkPulse.renderQrCode('qrcode', qrToken, 180);

    // Live Countdown Timer
    const startTime = new Date(<?= json_encode(date('c', strtotime($booking['start_time']))) ?>).getTime();
    const endTime = new Date(<?= json_encode(date('c', strtotime($booking['end_time']))) ?>).getTime();
    const status = <?= json_encode($booking['status']) ?>;

    function updateCountdown() {
        const now = new Date().getTime();
        const countdownEl = document.getElementById('countdownText');

        if (status === 'completed') {
            countdownEl.innerText = 'Completed • Vehicle Exited';
            return;
        }
        if (status === 'cancelled') {
            countdownEl.innerText = 'Reservation Cancelled';
            return;
        }

        if (now < startTime) {
            const diff = startTime - now;
            const hours = Math.floor(diff / (1000 * 60 * 60));
            const mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            countdownEl.innerText = `Starts in ${hours}h ${mins}m`;
        } else if (now >= startTime && now <= endTime) {
            const diff = endTime - now;
            const hours = Math.floor(diff / (1000 * 60 * 60));
            const mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            countdownEl.innerText = `Active Session • ${hours}h ${mins}m remaining`;
        } else {
            const over = now - endTime;
            const mins = Math.floor(over / (1000 * 60));
            countdownEl.innerHTML = `<span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> Exceeded by ${mins}m</span>`;
        }
    }

    updateCountdown();
    setInterval(updateCountdown, 30000);

    // Cancellation prompt handler
    window.promptCancelBooking = async function(ref) {
        if (!confirm(`Are you sure you want to cancel booking ${ref}? A full refund will be simulated back to your account.`)) {
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
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
