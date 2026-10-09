<?php
/**
 * Administrator Facility Management CRUD - India / Gujarat Edition
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireRole('admin');

$db = Database::getInstance()->getConnection();
$error = null;
$statesAndUts = Security::getIndianStatesAndUTs();

// Handle Add / Edit / Status Toggle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        Flash::set('danger', 'Security session expired. Please refresh.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_facility') {
            $facId = (int)($_POST['facility_id'] ?? 0);
            $name = Security::sanitizeString($_POST['name'] ?? '', 150);
            $code = strtoupper(Security::sanitizeString($_POST['code'] ?? '', 20));
            $address = Security::sanitizeString($_POST['address'] ?? '', 255);
            $locality = Security::sanitizeString($_POST['locality'] ?? '', 100);
            $city = Security::sanitizeString($_POST['city'] ?? '', 100);
            $district = Security::sanitizeString($_POST['district'] ?? '', 100);
            $state = Security::sanitizeString($_POST['state'] ?? 'Gujarat', 100);
            $country = 'India';
            $postalCode = Security::sanitizeString($_POST['postal_code'] ?? '', 10);
            $lat = (float)($_POST['latitude'] ?? 0.0);
            $lng = (float)($_POST['longitude'] ?? 0.0);
            $totalCapacity = (int)($_POST['total_capacity'] ?? 20);
            $hourlyRate = (float)($_POST['hourly_rate'] ?? 30.0);
            $evRate = (float)($_POST['ev_rate'] ?? 50.0);
            $peakRate = (float)($_POST['peak_rate'] ?? 40.0);
            $weekendRate = (float)($_POST['weekend_rate'] ?? 35.0);
            $overtimeRate = (float)($_POST['overtime_rate_per_hour'] ?? 60.0);
            $graceMins = (int)($_POST['grace_period_mins'] ?? 15);
            $description = Security::sanitizeString($_POST['description'] ?? '', 1000);
            $status = Security::sanitizeString($_POST['status'] ?? 'active');

            // Amenities JSON
            $amenities = [
                'ev_charging' => !empty($_POST['amenity_ev']),
                'cctv' => !empty($_POST['amenity_cctv']),
                'covered' => !empty($_POST['amenity_covered']),
                'valet' => !empty($_POST['amenity_valet']),
                'handicap' => !empty($_POST['amenity_handicap']),
                'restrooms' => !empty($_POST['amenity_restrooms']),
                'open_24_7' => !empty($_POST['amenity_247'])
            ];
            $amenitiesJson = json_encode($amenities);

            if (empty($name) || empty($code) || empty($address) || empty($city)) {
                Flash::set('danger', 'Name, unique code, street address, and city are required.');
            } elseif (!empty($postalCode) && !Security::validatePinCode($postalCode)) {
                Flash::set('danger', 'Please enter a valid 6-digit Indian PIN code (e.g. 360001).');
            } elseif (!Security::validateCoordinates($lat, $lng)) {
                Flash::set('danger', 'Please provide valid latitude (-90 to 90) and longitude (-180 to 180) coordinates.');
            } else {
                try {
                    if ($facId > 0) {
                        // Update
                        $stmt = $db->prepare("UPDATE facilities SET 
                            name = ?, code = ?, address = ?, locality = ?, city = ?, district = ?, state = ?, country = ?, postal_code = ?,
                            latitude = ?, longitude = ?, total_capacity = ?, hourly_rate = ?, ev_rate = ?,
                            peak_rate = ?, weekend_rate = ?, overtime_rate_per_hour = ?, grace_period_mins = ?,
                            amenities_json = ?, description = ?, status = ?
                            WHERE id = ?");
                        $stmt->execute([
                            $name, $code, $address, $locality, $city, $district, $state, $country, $postalCode,
                            $lat, $lng, $totalCapacity, $hourlyRate, $evRate,
                            $peakRate, $weekendRate, $overtimeRate, $graceMins,
                            $amenitiesJson, $description, $status, $facId
                        ]);
                        AuditLogger::log('FACILITY_UPDATED', 'facilities', $facId, "Updated facility {$name} ({$code}) in {$city}, {$state}.");
                        Flash::set('success', "Facility '{$name}' updated successfully.");
                    } else {
                        // Insert
                        $stmt = $db->prepare("INSERT INTO facilities 
                            (name, code, address, locality, city, district, state, country, postal_code, latitude, longitude, total_capacity, hourly_rate, ev_rate, peak_rate, weekend_rate, overtime_rate_per_hour, grace_period_mins, amenities_json, description, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $name, $code, $address, $locality, $city, $district, $state, $country, $postalCode,
                            $lat, $lng, $totalCapacity, $hourlyRate, $evRate,
                            $peakRate, $weekendRate, $overtimeRate, $graceMins,
                            $amenitiesJson, $description, $status
                        ]);
                        $newId = (int)$db->lastInsertId();
                        AuditLogger::log('FACILITY_CREATED', 'facilities', $newId, "Created new facility {$name} ({$code}) in {$city}, {$state}.");
                        Flash::set('success', "Facility '{$name}' created successfully.");
                    }
                } catch (\Throwable $e) {
                    Flash::set('danger', 'Database error: ' . $e->getMessage());
                }
            }
            header('Location: admin-facilities.php');
            exit;
        }

        if ($action === 'toggle_status') {
            $facId = (int)($_POST['facility_id'] ?? 0);
            $newStatus = Security::sanitizeString($_POST['new_status'] ?? 'active');
            $db->prepare("UPDATE facilities SET status = ? WHERE id = ?")->execute([$newStatus, $facId]);
            AuditLogger::log('FACILITY_STATUS_TOGGLED', 'facilities', $facId, "Status changed to {$newStatus}.");
            Flash::set('success', "Facility status updated to {$newStatus}.");
            header('Location: admin-facilities.php');
            exit;
        }
    }
}

// Fetch all facilities with slot counts
$stmt = $db->query("SELECT f.*, 
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id) AS slot_count,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_ev_charging = 1) AS ev_slot_count
    FROM facilities f ORDER BY f.created_at DESC");
$facilities = $stmt->fetchAll();

$pageTitle = 'Manage Facilities';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 px-3 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark">Parking Facilities Management (Gujarat & India)</h1>
            <p class="text-secondary small mb-0">Configure multi-level parking structures, Indian address fields, GPS coordinates, tariffs & amenities</p>
        </div>
        <button type="button" class="btn btn-primary-custom shadow-sm" onclick="window.openFacilityModal()">
            <i class="bi bi-plus-lg me-1"></i> Add New Facility
        </button>
    </div>

    <!-- Facilities Table -->
    <div class="card card-custom border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-custom mb-0">
                <thead>
                    <tr>
                        <th>Facility / Code</th>
                        <th>Address & Locality</th>
                        <th>State / City</th>
                        <th>Bays / EV</th>
                        <th>Tariffs (Std / EV)</th>
                        <th>Maps</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($facilities)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-secondary">No facilities configured.</td></tr>
                    <?php else: ?>
                        <?php foreach ($facilities as $f): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?= Security::escape($f['name']) ?></div>
                                    <span class="badge bg-dark font-monospace" style="font-size: 0.72rem;"><?= Security::escape($f['code']) ?></span>
                                </td>
                                <td>
                                    <div class="small text-dark"><?= Security::escape($f['address']) ?></div>
                                    <?php if (!empty($f['locality'])): ?>
                                        <small class="text-secondary d-block"><?= Security::escape($f['locality']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= Security::escape($f['city']) ?>, <?= Security::escape($f['state']) ?></div>
                                    <small class="text-muted font-monospace">PIN: <?= Security::escape($f['postal_code'] ?? 'N/A') ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-primary font-monospace"><?= $f['slot_count'] ?> configured</div>
                                    <small class="text-muted">⚡ <?= $f['ev_slot_count'] ?> EV chargers</small>
                                </td>
                                <td>
                                    <div class="small">Std: <strong class="text-dark">₹<?= number_format((float)$f['hourly_rate'], 2) ?>/hr</strong></div>
                                    <div class="small">EV: <strong class="text-info">₹<?= number_format((float)$f['ev_rate'], 2) ?>/hr</strong></div>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="<?= Security::escape(Security::buildGoogleMapsUrl((float)$f['latitude'], (float)$f['longitude'])) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary" title="Open in Google Maps">
                                            <i class="bi bi-google"></i>
                                        </a>
                                        <a href="<?= Security::escape(Security::buildOsmUrl((float)$f['latitude'], (float)$f['longitude'])) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary" title="View on OpenStreetMap">
                                            <i class="bi bi-map"></i>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?= match($f['status']) {
                                        'active' => 'badge-available',
                                        'maintenance' => 'badge-reserved',
                                        default => 'badge-occupied'
                                    } ?>">
                                        <?= strtoupper(Security::escape($f['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick='window.editFacility(<?= json_encode($f) ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a href="admin-slots.php?facility_id=<?= (int)$f['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Manage Slots">
                                            <i class="bi bi-grid-3x3"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / Edit Facility Modal -->
<div class="modal fade" id="facilityModal" tabindex="-1" aria-labelledby="facilityModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold" id="facilityModalLabel">Add Facility</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="admin-facilities.php">
                <div class="modal-body p-4">
                    <?= Security::csrfInput() ?>
                    <input type="hidden" name="action" value="save_facility">
                    <input type="hidden" name="facility_id" id="fac_id" value="0">

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small text-secondary fw-semibold">Facility Name *</label>
                            <input type="text" class="form-control" name="name" id="fac_name" placeholder="e.g. Trikon Baug Smart Multilevel Hub" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">Unique Code *</label>
                            <input type="text" class="form-control font-monospace text-uppercase" name="code" id="fac_code" placeholder="FAC-RAJ-01" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label small text-secondary fw-semibold">Street Address *</label>
                            <input type="text" class="form-control" name="address" id="fac_address" placeholder="e.g. Opposite Jubilee Garden, Trikon Baug" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small text-secondary fw-semibold">Locality / Area</label>
                            <input type="text" class="form-control" name="locality" id="fac_locality" placeholder="e.g. Dharmendrasinhji Road">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small text-secondary fw-semibold">City *</label>
                            <input type="text" class="form-control" name="city" id="fac_city" placeholder="e.g. Rajkot" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary fw-semibold">District</label>
                            <input type="text" class="form-control" name="district" id="fac_district" placeholder="e.g. Rajkot">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary fw-semibold">State / UT *</label>
                            <select class="form-select" name="state" id="fac_state" required>
                                <optgroup label="Default State">
                                    <option value="Gujarat" selected>Gujarat</option>
                                </optgroup>
                                <optgroup label="States">
                                    <?php foreach ($statesAndUts['states'] as $st): ?>
                                        <?php if ($st !== 'Gujarat'): ?>
                                            <option value="<?= Security::escape($st) ?>"><?= Security::escape($st) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Union Territories">
                                    <?php foreach ($statesAndUts['union_territories'] as $ut): ?>
                                        <option value="<?= Security::escape($ut) ?>"><?= Security::escape($ut) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary fw-semibold">PIN Code (6 Digits) *</label>
                            <input type="text" class="form-control font-monospace" name="postal_code" id="fac_postal_code" placeholder="360001" maxlength="6" pattern="^[1-9][0-9]{5}$" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">GPS Latitude (-90 to 90)</label>
                            <input type="number" step="0.0000001" class="form-control font-monospace" name="latitude" id="fac_lat" value="22.3015000" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">GPS Longitude (-180 to 180)</label>
                            <input type="number" step="0.0000001" class="form-control font-monospace" name="longitude" id="fac_lng" value="70.8030000" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary fw-semibold">Total Capacity (Bays)</label>
                            <input type="number" class="form-control" name="total_capacity" id="fac_capacity" value="36" min="1">
                        </div>
                    </div>

                    <!-- Pricing Rules -->
                    <div class="bg-light p-3 rounded-3 border mb-3">
                        <h6 class="fw-bold text-dark mb-2 small"><i class="bi bi-currency-rupee text-primary"></i> Hourly Rates & Tariffs (INR ₹)</h6>
                        <div class="row g-2">
                            <div class="col-sm-3">
                                <label class="form-label small text-muted mb-0">Base Rate (₹ / hr)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" name="hourly_rate" id="fac_hourly_rate" value="30.00" required>
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label small text-muted mb-0">EV Stall Rate (₹ / hr)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" name="ev_rate" id="fac_ev_rate" value="50.00" required>
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label small text-muted mb-0">Peak Rate (₹ / hr)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" name="peak_rate" id="fac_peak_rate" value="40.00" required>
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label small text-muted mb-0">Weekend Rate (₹ / hr)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" name="weekend_rate" id="fac_weekend_rate" value="35.00" required>
                            </div>
                        </div>
                        <div class="row g-2 mt-2">
                            <div class="col-sm-6">
                                <label class="form-label small text-muted mb-0">Grace Period (Minutes)</label>
                                <input type="number" class="form-control form-control-sm" name="grace_period_mins" id="fac_grace_mins" value="15" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small text-muted mb-0">Overtime Penalty Rate (₹ / hr)</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" name="overtime_rate_per_hour" id="fac_overtime_rate" value="60.00" required>
                            </div>
                        </div>
                    </div>

                    <!-- Amenities Checkboxes -->
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold d-block">Facility Amenities</label>
                        <div class="row g-2">
                            <div class="col-6 col-sm-4">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="amenity_ev" id="am_ev" value="1"><label class="form-check-label small" for="am_ev">EV Charging</label></div>
                            </div>
                            <div class="col-6 col-sm-4">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="amenity_cctv" id="am_cctv" value="1"><label class="form-check-label small" for="am_cctv">24/7 CCTV</label></div>
                            </div>
                            <div class="col-6 col-sm-4">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="amenity_covered" id="am_covered" value="1"><label class="form-check-label small" for="am_covered">Covered Bay</label></div>
                            </div>
                            <div class="col-6 col-sm-4">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="amenity_valet" id="am_valet" value="1"><label class="form-check-label small" for="am_valet">Valet Service</label></div>
                            </div>
                            <div class="col-6 col-sm-4">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="amenity_handicap" id="am_handicap" value="1"><label class="form-check-label small" for="am_handicap">Accessible / Disability</label></div>
                            </div>
                            <div class="col-6 col-sm-4">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="amenity_247" id="am_247" value="1"><label class="form-check-label small" for="am_247">Open 24/7</label></div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Facility Description</label>
                        <textarea class="form-control" name="description" id="fac_desc" rows="2" placeholder="Describe access instructions, landmarks, security..."></textarea>
                    </div>

                    <div>
                        <label class="form-label small text-secondary fw-semibold">Operational Status</label>
                        <select class="form-select" name="status" id="fac_status">
                            <option value="active">Active & Bookable</option>
                            <option value="maintenance">Under Maintenance</option>
                            <option value="inactive">Inactive / Closed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom px-4 fw-bold">Save Facility</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.openFacilityModal = function() {
    document.getElementById('facilityModalLabel').innerText = 'Add New Parking Facility';
    document.getElementById('fac_id').value = '0';
    document.getElementById('fac_name').value = '';
    document.getElementById('fac_code').value = '';
    document.getElementById('fac_address').value = '';
    document.getElementById('fac_locality').value = '';
    document.getElementById('fac_city').value = 'Rajkot';
    document.getElementById('fac_district').value = 'Rajkot';
    document.getElementById('fac_state').value = 'Gujarat';
    document.getElementById('fac_postal_code').value = '360001';
    document.getElementById('fac_lat').value = '22.3015000';
    document.getElementById('fac_lng').value = '70.8030000';
    document.getElementById('fac_capacity').value = '30';
    document.getElementById('fac_hourly_rate').value = '30.00';
    document.getElementById('fac_ev_rate').value = '50.00';
    document.getElementById('fac_peak_rate').value = '40.00';
    document.getElementById('fac_weekend_rate').value = '35.00';
    document.getElementById('fac_grace_mins').value = '15';
    document.getElementById('fac_overtime_rate').value = '60.00';
    document.getElementById('fac_desc').value = '';
    document.getElementById('fac_status').value = 'active';

    ['am_ev', 'am_cctv', 'am_covered', 'am_valet', 'am_handicap', 'am_247'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.checked = false;
    });

    const modal = new bootstrap.Modal(document.getElementById('facilityModal'));
    modal.show();
};

window.editFacility = function(fac) {
    document.getElementById('facilityModalLabel').innerText = 'Edit Parking Facility';
    document.getElementById('fac_id').value = fac.id;
    document.getElementById('fac_name').value = fac.name;
    document.getElementById('fac_code').value = fac.code;
    document.getElementById('fac_address').value = fac.address;
    document.getElementById('fac_locality').value = fac.locality || '';
    document.getElementById('fac_city').value = fac.city;
    document.getElementById('fac_district').value = fac.district || '';
    document.getElementById('fac_state').value = fac.state || 'Gujarat';
    document.getElementById('fac_postal_code').value = fac.postal_code || '';
    document.getElementById('fac_lat').value = fac.latitude;
    document.getElementById('fac_lng').value = fac.longitude;
    document.getElementById('fac_capacity').value = fac.total_capacity;
    document.getElementById('fac_hourly_rate').value = parseFloat(fac.hourly_rate).toFixed(2);
    document.getElementById('fac_ev_rate').value = parseFloat(fac.ev_rate).toFixed(2);
    document.getElementById('fac_peak_rate').value = parseFloat(fac.peak_rate).toFixed(2);
    document.getElementById('fac_weekend_rate').value = parseFloat(fac.weekend_rate).toFixed(2);
    document.getElementById('fac_grace_mins').value = fac.grace_period_mins;
    document.getElementById('fac_overtime_rate').value = parseFloat(fac.overtime_rate_per_hour).toFixed(2);
    document.getElementById('fac_desc').value = fac.description || '';
    document.getElementById('fac_status').value = fac.status;

    let am = {};
    try {
        am = typeof fac.amenities_json === 'string' ? JSON.parse(fac.amenities_json) : (fac.amenities_json || {});
    } catch(e) {}

    document.getElementById('am_ev').checked = !!am.ev_charging;
    document.getElementById('am_cctv').checked = !!am.cctv;
    document.getElementById('am_covered').checked = !!am.covered;
    document.getElementById('am_valet').checked = !!am.valet;
    document.getElementById('am_handicap').checked = !!am.handicap;
    document.getElementById('am_247').checked = !!am.open_24_7;

    const modal = new bootstrap.Modal(document.getElementById('facilityModal'));
    modal.show();
};
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
