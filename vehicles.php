<?php
/**
 * User Vehicle Management
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireLogin();

$userId = Auth::id();
$db = Database::getInstance()->getConnection();
$error = null;

// Handle Form Actions (Add, Edit, Set Default, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        Flash::set('danger', 'Security token invalid. Please refresh the page.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $plate = strtoupper(trim(Security::sanitizeString($_POST['plate_number'] ?? '', 20)));
            $type = Security::sanitizeString($_POST['vehicle_type'] ?? 'car', 20);
            $makeModel = Security::sanitizeString($_POST['make_model'] ?? '', 100);
            $color = Security::sanitizeString($_POST['color'] ?? '', 50);
            $isEv = !empty($_POST['is_ev']) ? 1 : 0;
            $isDefault = !empty($_POST['is_default']) ? 1 : 0;

            if (empty($plate) || empty($makeModel) || empty($color)) {
                Flash::set('danger', 'All vehicle fields are required.');
            } elseif (!Security::validatePlate($plate)) {
                Flash::set('danger', 'Invalid license plate format.');
            } else {
                try {
                    // Check if plate already registered for this user
                    $chk = $db->prepare("SELECT id FROM vehicles WHERE user_id = ? AND plate_number = ?");
                    $chk->execute([$userId, $plate]);
                    if ($chk->fetch()) {
                        Flash::set('danger', 'You have already added this license plate.');
                    } else {
                        if ($isDefault) {
                            $db->prepare("UPDATE vehicles SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
                        }
                        $stmt = $db->prepare("INSERT INTO vehicles (user_id, plate_number, vehicle_type, make_model, color, is_ev, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$userId, $plate, $type, $makeModel, $color, $isEv, $isDefault]);
                        AuditLogger::log('VEHICLE_ADDED', 'vehicles', (int)$db->lastInsertId(), "Added vehicle plate: {$plate}");
                        Flash::set('success', "Vehicle {$plate} added successfully.");
                    }
                } catch (\Throwable $e) {
                    Flash::set('danger', 'Database error adding vehicle: ' . $e->getMessage());
                }
            }
            header('Location: vehicles.php');
            exit;
        }

        if ($action === 'set_default') {
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $db->prepare("UPDATE vehicles SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
            $stmt = $db->prepare("UPDATE vehicles SET is_default = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$vehicleId, $userId]);
            Flash::set('success', 'Default vehicle updated.');
            header('Location: vehicles.php');
            exit;
        }

        if ($action === 'delete') {
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM vehicles WHERE id = ? AND user_id = ?");
            $stmt->execute([$vehicleId, $userId]);
            AuditLogger::log('VEHICLE_DELETED', 'vehicles', $vehicleId, "Deleted vehicle ID {$vehicleId}");
            Flash::set('success', 'Vehicle removed from your account.');
            header('Location: vehicles.php');
            exit;
        }
    }
}

// Fetch all user vehicles
$stmt = $db->prepare("SELECT * FROM vehicles WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

$pageTitle = 'My Vehicles';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">My Registered Vehicles</h1>
            <p class="text-secondary small mb-0">Manage your cars, two-wheelers, auto-rickshaws, cycles, or commercial vehicles with EV charging preferences</p>
        </div>
        <button type="button" class="btn btn-primary-custom shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
            <i class="bi bi-plus-lg"></i>
            <span>Add New Vehicle</span>
        </button>
    </div>

    <?php if (empty($vehicles)): ?>
        <div class="card card-custom border-0 p-5 text-center shadow-sm">
            <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle mx-auto mb-3" style="width: 80px; height: 80px;">
                <i class="bi bi-car-front text-secondary fs-1"></i>
            </div>
            <h3 class="h5 fw-bold text-dark">No Vehicles Registered Yet</h3>
            <p class="text-secondary small mb-4">Add your vehicle (Car, Bike, Auto, Bicycle, Van, Truck or Bus) to reserve guaranteed smart parking.</p>
            <div>
                <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
                    <i class="bi bi-plus-circle me-1"></i> Add Your First Vehicle
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php 
            $catMeta = Security::getVehicleCategories();
            foreach ($vehicles as $v): 
                $vType = $v['vehicle_type'];
                $meta = $catMeta[$vType] ?? $catMeta['car'];
                $iconClass = $v['is_ev'] ? 'bi-lightning-charge-fill' : ($meta['icon'] ?? 'bi-car-front-fill');
            ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card card-custom h-100 border-0 shadow-sm position-relative">
                        <?php if ($v['is_default']): ?>
                            <div class="position-absolute top-0 end-0 m-3">
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1">
                                    <i class="bi bi-star-fill me-1"></i> Default
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <div class="card-body p-4 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="stat-icon-wrapper <?= $v['is_ev'] ? 'bg-info bg-opacity-10 text-info' : 'bg-primary bg-opacity-10 text-primary' ?>">
                                        <i class="bi <?= $iconClass ?>"></i>
                                    </div>
                                    <div>
                                        <h2 class="h5 fw-bold mb-0 text-dark"><?= Security::escape($v['make_model']) ?></h2>
                                        <span class="text-secondary small text-capitalize"><?= Security::escape($v['color']) ?> • <?= Security::escape($meta['short_label'] ?? $vType) ?></span>
                                    </div>
                                </div>

                                <div class="bg-dark text-light font-monospace fw-bold text-center py-2 px-3 rounded-3 mb-3 border border-secondary border-opacity-25 letter-spacing-1">
                                    <?= Security::escape($v['plate_number']) ?>
                                </div>

                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi <?= $meta['icon'] ?? 'bi-car-front' ?> me-1"></i> <?= Security::escape($meta['short_label'] ?? ucfirst($vType)) ?>
                                    </span>
                                    <?php if ($v['is_ev']): ?>
                                        <span class="badge badge-status badge-ev"><i class="bi bi-lightning-charge-fill"></i> EV (Electric)</span>
                                    <?php endif; ?>
                                    <span class="badge bg-light text-secondary border"><i class="bi bi-clock me-1"></i> Added <?= date('M Y', strtotime($v['created_at'])) ?></span>
                                </div>
                            </div>

                            <div class="pt-3 border-top d-flex align-items-center justify-content-between">
                                <?php if (!$v['is_default']): ?>
                                    <form method="POST" action="vehicles.php" class="d-inline">
                                        <?= Security::csrfInput() ?>
                                        <input type="hidden" name="action" value="set_default">
                                        <input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-star me-1"></i> Make Default
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small"><i class="bi bi-check2 text-success"></i> Primary vehicle</span>
                                <?php endif; ?>

                                <form method="POST" action="vehicles.php" class="d-inline" onsubmit="return confirm('Remove vehicle <?= Security::escape($v['plate_number']) ?>?');">
                                    <?= Security::csrfInput() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Add Vehicle Modal -->
<div class="modal fade" id="addVehicleModal" tabindex="-1" aria-labelledby="addVehicleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-lg);">
            <div class="modal-header bg-dark text-white border-bottom border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold" id="addVehicleModalLabel">
                    <i class="bi bi-car-front text-primary me-2"></i> Register New Vehicle
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="vehicles.php">
                <div class="modal-body p-4">
                    <?= Security::csrfInput() ?>
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                        <label for="plate_number" class="form-label fw-semibold small text-secondary">License Plate / Registration Number *</label>
                        <input type="text" class="form-control font-monospace text-uppercase" id="plate_number" name="plate_number" placeholder="e.g. GJ-03-ER-2026 or GJ-01-AB-1234" required>
                        <div class="form-text">Enter state code & number (e.g. GJ-03-XX-1234, MH-12-XX-5678, or cycle frame ID).</div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="vehicle_type" class="form-label fw-semibold small text-secondary">Vehicle Category *</label>
                            <select class="form-select" id="vehicle_type" name="vehicle_type" required onchange="handleCategoryChange(this.value)">
                                <option value="car" selected>🚗 Car / Sedan / SUV / Hatchback</option>
                                <option value="bike">🏍️ Two-Wheeler / Bike / Scooter</option>
                                <option value="bicycle">🚲 Bicycle / Cycle</option>
                                <option value="auto_rickshaw">🛺 Auto-rickshaw (3-Wheeler)</option>
                                <option value="van">🚐 Van / Mini-Bus</option>
                                <option value="truck">🚚 Truck / Commercial Vehicle</option>
                                <option value="bus">🚌 Bus / Heavy Vehicle</option>
                                <option value="other">⚙️ Other Vehicle</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label for="color" class="form-label fw-semibold small text-secondary">Exterior Color *</label>
                            <input type="text" class="form-control" id="color" name="color" placeholder="e.g. White, Silver, Black" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="make_model" class="form-label fw-semibold small text-secondary">Make & Model *</label>
                        <input type="text" class="form-control" id="make_model" name="make_model" placeholder="e.g. Tata Nexon EV, Honda Activa, Swift" required>
                    </div>

                    <div class="bg-light p-3 rounded-3 mb-3 border">
                        <div class="form-check form-switch mb-2" id="ev_switch_wrapper">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_ev" name="is_ev" value="1">
                            <label class="form-check-label fw-semibold small text-dark" for="is_ev">
                                <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Electric Vehicle (EV / Electric Motor)
                            </label>
                            <small class="text-muted d-block ps-1" style="font-size: 0.75rem;">Check if this is an electric vehicle eligible for EV charging bays.</small>
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_default" name="is_default" value="1" <?= empty($vehicles) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold small text-dark" for="is_default">
                                Set as primary / default vehicle
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save Vehicle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function handleCategoryChange(cat) {
    const evSwitch = document.getElementById('is_ev');
    const evWrapper = document.getElementById('ev_switch_wrapper');
    if (cat === 'bicycle') {
        evSwitch.checked = false;
        evSwitch.disabled = true;
        evWrapper.classList.add('opacity-50');
    } else {
        evSwitch.disabled = false;
        evWrapper.classList.remove('opacity-50');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
