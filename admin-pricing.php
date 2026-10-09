<?php
/**
 * Administrator Tariffs & Dynamic Pricing Rules Manager
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireRole('admin');

$db = Database::getInstance()->getConnection();

// Handle Tariff Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        Flash::set('danger', 'Security token invalid.');
    } else {
        $facilityId = (int)($_POST['facility_id'] ?? 0);
        $hourlyRate = (float)($_POST['hourly_rate'] ?? 50.0);
        $evRate = (float)($_POST['ev_rate'] ?? 75.0);
        $peakRate = (float)($_POST['peak_rate'] ?? 70.0);
        $weekendRate = (float)($_POST['weekend_rate'] ?? 60.0);
        $overtimeRate = (float)($_POST['overtime_rate_per_hour'] ?? 100.0);
        $graceMins = (int)($_POST['grace_period_mins'] ?? 15);

        try {
            $stmt = $db->prepare("UPDATE facilities SET 
                hourly_rate = ?, ev_rate = ?, peak_rate = ?, weekend_rate = ?, 
                overtime_rate_per_hour = ?, grace_period_mins = ? 
                WHERE id = ?");
            $stmt->execute([$hourlyRate, $evRate, $peakRate, $weekendRate, $overtimeRate, $graceMins, $facilityId]);

            AuditLogger::log('TARIFF_UPDATED', 'facilities', $facilityId, "Updated tariff rates. Base: ₹{$hourlyRate}, EV: ₹{$evRate}, Peak: ₹{$peakRate}, Overtime: ₹{$overtimeRate}/hr.");
            Flash::set('success', 'Tariff rules updated successfully.');
        } catch (\Throwable $e) {
            Flash::set('danger', 'Database error: ' . $e->getMessage());
        }
        header('Location: admin-pricing.php');
        exit;
    }
}

// Fetch all facilities
$facilities = $db->query("SELECT * FROM facilities ORDER BY name ASC")->fetchAll();

$pageTitle = 'Tariffs & Dynamic Pricing';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 px-3 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark">Tariffs & Dynamic Pricing Rules</h1>
            <p class="text-secondary small mb-0">Configure hourly parking rates, EV charging surcharges, peak-hour multipliers, and overtime penalties</p>
        </div>
    </div>

    <!-- Facility Pricing Cards -->
    <div class="row g-4">
        <?php foreach ($facilities as $f): ?>
            <div class="col-lg-6">
                <div class="card card-custom border-0 shadow-sm h-100">
                    <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-bold mb-0 text-white"><?= Security::escape($f['name']) ?></h2>
                            <small class="text-secondary font-monospace"><?= Security::escape($f['code']) ?> • <?= Security::escape($f['city']) ?></small>
                        </div>
                        <span class="badge <?= $f['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?> text-uppercase">
                            <?= Security::escape($f['status']) ?>
                        </span>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="admin-pricing.php">
                            <?= Security::csrfInput() ?>
                            <input type="hidden" name="facility_id" value="<?= (int)$f['id'] ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-sm-6">
                                    <label class="form-label small text-secondary fw-semibold">Base Standard Rate (₹ / hr)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">₹</span>
                                        <input type="number" step="0.01" class="form-control fw-bold text-primary" name="hourly_rate" value="<?= number_format((float)$f['hourly_rate'], 2, '.', '') ?>" required>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small text-secondary fw-semibold">EV Charging Bay Rate (₹ / hr)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">₹</span>
                                        <input type="number" step="0.01" class="form-control fw-bold text-info" name="ev_rate" value="<?= number_format((float)$f['ev_rate'], 2, '.', '') ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-sm-6">
                                    <label class="form-label small text-secondary fw-semibold">Peak Hours Rate (₹ / hr)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">₹</span>
                                        <input type="number" step="0.01" class="form-control" name="peak_rate" value="<?= number_format((float)$f['peak_rate'], 2, '.', '') ?>" required>
                                    </div>
                                    <small class="text-muted" style="font-size: 0.72rem;">Peak: 8:00 - 11:00 AM & 5:00 - 8:00 PM</small>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small text-secondary fw-semibold">Weekend Rate (₹ / hr)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light">₹</span>
                                        <input type="number" step="0.01" class="form-control" name="weekend_rate" value="<?= number_format((float)$f['weekend_rate'], 2, '.', '') ?>" required>
                                    </div>
                                    <small class="text-muted" style="font-size: 0.72rem;">Applies Saturday - Sunday</small>
                                </div>
                            </div>

                            <div class="p-3 bg-light rounded-3 border mb-3">
                                <h6 class="fw-bold text-dark small mb-2"><i class="bi bi-clock-history text-danger"></i> Overstay & Grace Period Policy</h6>
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <label class="form-label small text-muted mb-0">Grace Period (Minutes)</label>
                                        <input type="number" class="form-control form-control-sm" name="grace_period_mins" value="<?= (int)$f['grace_period_mins'] ?>" required>
                                        <small class="text-muted" style="font-size: 0.7rem;">Free exit buffer after booked time</small>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label small text-muted mb-0">Overtime Penalty Rate (₹ / hr)</label>
                                        <input type="number" step="0.01" class="form-control form-control-sm text-danger fw-bold" name="overtime_rate_per_hour" value="<?= number_format((float)$f['overtime_rate_per_hour'], 2, '.', '') ?>" required>
                                        <small class="text-muted" style="font-size: 0.7rem;">Charged per extra elapsed hour</small>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-dark-custom w-100 fw-bold btn-sm py-2">
                                <i class="bi bi-check2-circle me-1"></i> Update Tariff Policy
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
