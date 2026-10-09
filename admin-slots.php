<?php
/**
 * Administrator Slot & Zone Management & Batch Generator - India / Gujarat Edition
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireRole('admin');

$db = Database::getInstance()->getConnection();
$vehicleCategories = Security::getVehicleCategories();

// Fetch facilities
$facStmt = $db->query("SELECT id, name, code, city, state FROM facilities ORDER BY name ASC");
$facilities = $facStmt->fetchAll();

$facilityId = (int)($_GET['facility_id'] ?? ($facilities[0]['id'] ?? 1));

// Handle Add / Batch / Edit / Delete Slot actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        Flash::set('danger', 'Security token invalid.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_single_slot') {
            $fId = (int)($_POST['facility_id'] ?? $facilityId);
            $floor = Security::sanitizeString($_POST['floor_level'] ?? 'Floor 1', 20);
            $zone = Security::sanitizeString($_POST['zone_name'] ?? 'Zone A', 50);
            $slotNo = strtoupper(Security::sanitizeString($_POST['slot_number'] ?? '', 20));
            $slotType = Security::sanitizeString($_POST['slot_type'] ?? 'car', 30);
            $allowedTypes = Security::sanitizeString($_POST['allowed_vehicle_types'] ?? $slotType, 255);
            $dims = Security::sanitizeString($_POST['slot_dimensions'] ?? ($vehicleCategories[$slotType]['default_dims'] ?? '5.0m x 2.4m'), 50);
            $isEv = !empty($_POST['is_ev_charging']) ? 1 : 0;
            $chargerType = $isEv ? Security::sanitizeString($_POST['ev_charger_type'] ?? 'Type 2 AC', 50) : 'None';
            $chargerRate = $isEv ? (float)($_POST['ev_charging_rate'] ?? 20.0) : 0.0;
            $isAcc = !empty($_POST['is_accessible']) ? 1 : 0;
            $status = Security::sanitizeString($_POST['status'] ?? 'available');

            if (empty($slotNo)) {
                Flash::set('danger', 'Slot number is required.');
            } else {
                try {
                    $stmt = $db->prepare("INSERT INTO slots 
                        (facility_id, floor_level, zone_name, slot_number, slot_type, allowed_vehicle_types, slot_dimensions, is_ev_charging, ev_charger_type, ev_charging_rate, is_accessible, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$fId, $floor, $zone, $slotNo, $slotType, $allowedTypes, $dims, $isEv, $chargerType, $chargerRate, $isAcc, $status]);
                    AuditLogger::log('SLOT_CREATED', 'slots', (int)$db->lastInsertId(), "Created {$slotType} slot {$slotNo} in facility ID {$fId}.");
                    Flash::set('success', "Stall {$slotNo} ({$slotType}) added successfully.");
                } catch (\PDOException $e) {
                    Flash::set('danger', 'Slot number already exists on this floor or DB error: ' . $e->getMessage());
                }
            }
            header("Location: admin-slots.php?facility_id={$fId}");
            exit;
        }

        if ($action === 'batch_generate') {
            $fId = (int)($_POST['facility_id'] ?? $facilityId);
            $floor = Security::sanitizeString($_POST['floor_level'] ?? 'Floor 1', 20);
            $zone = Security::sanitizeString($_POST['zone_name'] ?? 'Zone A', 50);
            $prefix = strtoupper(Security::sanitizeString($_POST['prefix'] ?? 'A', 5));
            $startNum = (int)($_POST['start_num'] ?? 1);
            $count = min(50, max(1, (int)($_POST['count'] ?? 10)));
            $slotType = Security::sanitizeString($_POST['slot_type'] ?? 'car', 30);
            $allowedTypes = Security::sanitizeString($_POST['allowed_vehicle_types'] ?? $slotType, 255);
            $dims = Security::sanitizeString($_POST['slot_dimensions'] ?? ($vehicleCategories[$slotType]['default_dims'] ?? '5.0m x 2.4m'), 50);
            $isEv = !empty($_POST['is_ev_charging']) ? 1 : 0;
            $chargerType = $isEv ? Security::sanitizeString($_POST['ev_charger_type'] ?? 'Type 2 AC', 50) : 'None';
            $chargerRate = $isEv ? (float)($_POST['ev_charging_rate'] ?? 20.0) : 0.0;

            $createdCount = 0;
            $stmt = $db->prepare("INSERT IGNORE INTO slots 
                (facility_id, floor_level, zone_name, slot_number, slot_type, allowed_vehicle_types, slot_dimensions, is_ev_charging, ev_charger_type, ev_charging_rate, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')");

            for ($i = 0; $i < $count; $i++) {
                $num = $startNum + $i;
                $slotNo = sprintf("%s-%02d", $prefix, $num);
                $stmt->execute([$fId, $floor, $zone, $slotNo, $slotType, $allowedTypes, $dims, $isEv, $chargerType, $chargerRate]);
                if ($stmt->rowCount() > 0) {
                    $createdCount++;
                }
            }

            AuditLogger::log('SLOTS_BATCH_GENERATED', 'slots', null, "Batch generated {$createdCount} {$slotType} slots in facility ID {$fId}.");
            Flash::set('success', "Successfully generated {$createdCount} {$slotType} parking stalls.");
            header("Location: admin-slots.php?facility_id={$fId}");
            exit;
        }

        if ($action === 'delete_slot') {
            $slotId = (int)($_POST['slot_id'] ?? 0);
            $fId = (int)($_POST['facility_id'] ?? $facilityId);
            $db->prepare("DELETE FROM slots WHERE id = ?")->execute([$slotId]);
            AuditLogger::log('SLOT_DELETED', 'slots', $slotId, "Deleted slot ID {$slotId}.");
            Flash::set('success', "Slot removed.");
            header("Location: admin-slots.php?facility_id={$fId}");
            exit;
        }
    }
}

// Fetch slots for active facility
$slotStmt = $db->prepare("SELECT * FROM slots WHERE facility_id = ? ORDER BY floor_level ASC, zone_name ASC, slot_number ASC");
$slotStmt->execute([$facilityId]);
$slots = $slotStmt->fetchAll();

$pageTitle = 'Manage Slots & Zones';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 px-3 py-4">
    <!-- Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark">Stalls & Zones Configuration</h1>
            <p class="text-secondary small mb-0">Configure multi-vehicle category bays, dimensions, EV charger types, and batch generate stalls</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#batchModal">
                <i class="bi bi-grid-fill me-1"></i> Batch Generator
            </button>
            <button type="button" class="btn btn-primary-custom btn-sm" data-bs-toggle="modal" data-bs-target="#addSlotModal">
                <i class="bi bi-plus-lg me-1"></i> Add Single Stall
            </button>
        </div>
    </div>

    <!-- Facility Picker Bar -->
    <div class="card card-custom border-0 p-3 shadow-sm mb-4">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-2">
                <label for="facSelect" class="fw-semibold text-secondary small text-nowrap">Active Facility:</label>
                <select id="facSelect" class="form-select form-select-sm border-primary fw-bold" onchange="window.location.href='admin-slots.php?facility_id=' + this.value;">
                    <?php foreach ($facilities as $fac): ?>
                        <option value="<?= (int)$fac['id'] ?>" <?= $fac['id'] == $facilityId ? 'selected' : '' ?>>
                            <?= Security::escape($fac['name']) ?> (<?= Security::escape($fac['code']) ?> - <?= Security::escape($fac['city']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="text-secondary small">
                Total Configured: <strong class="text-dark"><?= count($slots) ?> stalls</strong>
            </div>
        </div>
    </div>

    <!-- Slots Table -->
    <div class="card card-custom border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-custom mb-0">
                <thead>
                    <tr>
                        <th>Stall Number</th>
                        <th>Floor & Zone</th>
                        <th>Vehicle Category</th>
                        <th>Dimensions</th>
                        <th>EV Stall / Charger</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($slots)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-secondary">No stalls configured for this facility. Use the Batch Generator above to seed slots.</td></tr>
                    <?php else: ?>
                        <?php foreach ($slots as $s): 
                            $cat = $vehicleCategories[$s['slot_type']] ?? ['name' => ucfirst($s['slot_type']), 'icon' => '🚗'];
                        ?>
                            <tr>
                                <td>
                                    <span class="font-monospace fw-bold fs-6 text-primary bg-light px-2 py-1 rounded border">
                                        <?= Security::escape($s['slot_number']) ?>
                                    </span>
                                    <?php if (!empty($s['is_accessible'])): ?>
                                        <span class="badge bg-info text-dark ms-1" title="Disability Accessible"><i class="bi bi-universal-access"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= Security::escape($s['floor_level']) ?></div>
                                    <small class="text-secondary"><?= Security::escape($s['zone_name']) ?></small>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= $cat['icon'] ?> <?= Security::escape($cat['name']) ?></div>
                                    <small class="text-muted font-monospace">Accepts: <?= Security::escape($s['allowed_vehicle_types'] ?? $s['slot_type']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace"><?= Security::escape($s['slot_dimensions'] ?? '5.0m x 2.4m') ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($s['is_ev_charging'])): ?>
                                        <span class="badge badge-status badge-ev">
                                            <i class="bi bi-lightning-charge-fill"></i> <?= Security::escape($s['ev_charger_type'] ?? 'Type 2 AC') ?>
                                        </span>
                                        <small class="text-muted d-block mt-1">+₹<?= number_format((float)($s['ev_charging_rate'] ?? 20.0), 2) ?>/hr</small>
                                    <?php else: ?>
                                        <span class="text-muted small">Standard (No EV)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= match($s['status']) {
                                        'available' => 'badge-available',
                                        'occupied' => 'badge-occupied',
                                        'reserved' => 'badge-reserved',
                                        default => 'badge-maintenance'
                                    } ?>">
                                        <?= strtoupper(Security::escape($s['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" action="admin-slots.php" onsubmit="return confirm('Remove slot <?= Security::escape($s['slot_number']) ?>?');">
                                        <?= Security::csrfInput() ?>
                                        <input type="hidden" name="action" value="delete_slot">
                                        <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
                                        <input type="hidden" name="facility_id" value="<?= $facilityId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Slot">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Single Slot Modal -->
<div class="modal fade" id="addSlotModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">Add Single Parking Stall</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="admin-slots.php">
                <div class="modal-body p-4">
                    <?= Security::csrfInput() ?>
                    <input type="hidden" name="action" value="add_single_slot">
                    <input type="hidden" name="facility_id" value="<?= $facilityId ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small text-secondary fw-semibold">Floor Level</label>
                            <input type="text" class="form-control" name="floor_level" value="Floor 1" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small text-secondary fw-semibold">Zone Name</label>
                            <input type="text" class="form-control" name="zone_name" value="Zone A" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small text-secondary fw-semibold">Stall Number *</label>
                            <input type="text" class="form-control font-monospace text-uppercase" name="slot_number" placeholder="e.g. A-101" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small text-secondary fw-semibold">Vehicle Category</label>
                            <select class="form-select" name="slot_type">
                                <?php foreach ($vehicleCategories as $catKey => $catInfo): ?>
                                    <option value="<?= Security::escape($catKey) ?>">
                                        <?= $catInfo['icon'] ?> <?= Security::escape($catInfo['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Stall Dimensions (Length x Width)</label>
                        <input type="text" class="form-control font-monospace" name="slot_dimensions" value="5.0m x 2.4m" placeholder="e.g. 5.0m x 2.4m">
                    </div>

                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="singleEvSwitch" name="is_ev_charging" value="1">
                            <label class="form-check-label fw-bold text-dark small" for="singleEvSwitch">
                                <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Dedicated EV Charging Stall
                            </label>
                        </div>
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label small text-muted mb-0">Charger Type</label>
                                <select class="form-select form-select-sm" name="ev_charger_type">
                                    <option value="Type 2 AC">Type 2 AC (7.4kW / 22kW)</option>
                                    <option value="CCS2 DC">CCS2 DC Fast (30kW - 60kW)</option>
                                    <option value="Bharat AC-001">Bharat AC-001 (3.3kW)</option>
                                    <option value="15A 3-Pin">15A 3-Pin Standard Socket</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small text-muted mb-0">Charging Surcharge (₹ / hr)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" name="ev_charging_rate" value="20.00">
                            </div>
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_accessible" id="singleAccSwitch" value="1">
                        <label class="form-check-label small" for="singleAccSwitch">
                            <i class="bi bi-universal-access text-info me-1"></i> Disability Accessible Stall
                        </label>
                    </div>

                    <div>
                        <label class="form-label small text-secondary fw-semibold">Initial Status</label>
                        <select class="form-select" name="status">
                            <option value="available">Available (Open for Booking)</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="occupied">Occupied</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom px-4 fw-bold">Create Stall</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Batch Slot Generator Modal -->
<div class="modal fade" id="batchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">Batch Stall Generator</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="admin-slots.php">
                <div class="modal-body p-4">
                    <?= Security::csrfInput() ?>
                    <input type="hidden" name="action" value="batch_generate">
                    <input type="hidden" name="facility_id" value="<?= $facilityId ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small text-secondary fw-semibold">Floor Level</label>
                            <input type="text" class="form-control" name="floor_level" value="Floor 1" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small text-secondary fw-semibold">Zone Name</label>
                            <input type="text" class="form-control" name="zone_name" value="Zone A" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-4">
                            <label class="form-label small text-secondary fw-semibold">Prefix</label>
                            <input type="text" class="form-control font-monospace text-uppercase" name="prefix" value="A" maxlength="5" required>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small text-secondary fw-semibold">Start Number</label>
                            <input type="number" class="form-control" name="start_num" value="1" min="1" required>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small text-secondary fw-semibold">Count (Max 50)</label>
                            <input type="number" class="form-control" name="count" value="10" min="1" max="50" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small text-secondary fw-semibold">Vehicle Category</label>
                            <select class="form-select" name="slot_type">
                                <?php foreach ($vehicleCategories as $catKey => $catInfo): ?>
                                    <option value="<?= Security::escape($catKey) ?>">
                                        <?= $catInfo['icon'] ?> <?= Security::escape($catInfo['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small text-secondary fw-semibold">Dimensions</label>
                            <input type="text" class="form-control font-monospace" name="slot_dimensions" value="5.0m x 2.4m">
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="batchEvSwitch" name="is_ev_charging" value="1">
                            <label class="form-check-label fw-bold text-dark small" for="batchEvSwitch">
                                <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Equip Batch with EV Chargers
                            </label>
                        </div>
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label small text-muted mb-0">Charger Type</label>
                                <select class="form-select form-select-sm" name="ev_charger_type">
                                    <option value="Type 2 AC">Type 2 AC</option>
                                    <option value="CCS2 DC">CCS2 DC Fast</option>
                                    <option value="Bharat AC-001">Bharat AC-001</option>
                                    <option value="15A 3-Pin">15A 3-Pin Standard</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small text-muted mb-0">Charging Surcharge (₹ / hr)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" name="ev_charging_rate" value="20.00">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom px-4 fw-bold">Generate Stalls</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
