<?php
/**
 * Smart Parking Discovery, Search & Interactive Map
 * Smart Parking Reservation & Management System - India / Gujarat Edition (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';

$pageTitle = 'Find & Reserve Smart Parking in Gujarat & India';
require_once __DIR__ . '/includes/header.php';

// Initial default time window: Next full hour to +2 hours
$defaultStart = date('Y-m-d\TH:00', strtotime('+1 hour'));
$defaultEnd = date('Y-m-d\TH:00', strtotime('+3 hours'));

$statesAndUts = Security::getIndianStatesAndUTs();
$vehicleCategories = Security::getVehicleCategories();

// Default quick Gujarat cities
$gujaratCities = [
    'Rajkot', 'Ahmedabad', 'Surat', 'Vadodara', 'Gandhinagar',
    'Jamnagar', 'Bhavnagar', 'Junagadh', 'Morbi', 'Anand'
];

// Pre-load active facilities server-side for INSTANT zero-delay page render
$db = Database::getInstance()->getConnection();
$initialStmt = $db->query("SELECT f.*,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1) AS total_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.is_ev_charging = 1) AS total_ev_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'car') AS total_car_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'bike') AS total_bike_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'bicycle') AS total_bicycle_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'auto_rickshaw') AS total_auto_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'van') AS total_van_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'truck') AS total_truck_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'bus') AS total_bus_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.is_accessible = 1) AS total_accessible_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.status IN ('occupied', 'reserved')) AS realtime_occupied_slots
    FROM facilities f 
    WHERE f.status = 'active'
    ORDER BY (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1) - (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.status IN ('occupied', 'reserved')) DESC");

$initialFacilitiesRaw = $initialStmt->fetchAll();
$initialFacilities = [];
foreach ($initialFacilitiesRaw as $fac) {
    $facId = (int)$fac['id'];
    $amenities = json_decode($fac['amenities_json'] ?? '[]', true) ?: [];
    $occ = (int)($fac['realtime_occupied_slots'] ?? 0);
    $avail = max(0, (int)$fac['total_slots'] - $occ);
    $lat = (float)$fac['latitude'];
    $lng = (float)$fac['longitude'];

    $initialFacilities[] = [
        'id' => $facId,
        'name' => $fac['name'],
        'code' => $fac['code'],
        'address' => $fac['address'],
        'locality' => $fac['locality'] ?? '',
        'city' => $fac['city'],
        'district' => $fac['district'] ?? '',
        'state' => $fac['state'],
        'country' => $fac['country'] ?? 'India',
        'postal_code' => $fac['postal_code'],
        'latitude' => $lat,
        'longitude' => $lng,
        'osm_url' => Security::buildOsmUrl($lat, $lng),
        'google_maps_url' => Security::buildGoogleMapsUrl($lat, $lng),
        'total_capacity' => (int)$fac['total_capacity'],
        'total_slots' => (int)$fac['total_slots'],
        'total_ev_slots' => (int)$fac['total_ev_slots'],
        'total_car_slots' => (int)$fac['total_car_slots'],
        'total_bike_slots' => (int)$fac['total_bike_slots'],
        'total_bicycle_slots' => (int)$fac['total_bicycle_slots'],
        'total_auto_slots' => (int)$fac['total_auto_slots'],
        'total_van_slots' => (int)$fac['total_van_slots'],
        'total_truck_slots' => (int)$fac['total_truck_slots'],
        'total_bus_slots' => (int)$fac['total_bus_slots'],
        'available_slots' => $avail,
        'occupied_slots' => $occ,
        'hourly_rate' => (float)$fac['hourly_rate'],
        'ev_rate' => (float)$fac['ev_rate'],
        'peak_rate' => (float)$fac['peak_rate'],
        'weekend_rate' => (float)$fac['weekend_rate'],
        'overtime_rate_per_hour' => (float)$fac['overtime_rate_per_hour'],
        'grace_period_mins' => (int)$fac['grace_period_mins'],
        'amenities' => $amenities,
        'description' => $fac['description'],
        'image_url' => $fac['image_url'],
        'status' => $fac['status'],
        'is_system_facility' => true
    ];
}
?>

<!-- Hero & Search Filter Section -->
<section class="hero-banner">
    <div class="container-fluid px-lg-5 px-3">
        <div class="row align-items-center g-4">
            <div class="col-lg-6">
                <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-primary bg-opacity-25 text-info border border-primary border-opacity-25 mb-3 small fw-semibold">
                    <i class="bi bi-geo-alt-fill text-warning"></i> Gujarat & India Smart Parking Network
                </div>
                <h1 class="display-5 fw-extrabold text-white mb-3">Guaranteed Smart Parking Across Gujarat & India.</h1>
                <p class="lead text-light text-opacity-75 mb-3" style="max-width: 620px;">
                    Search verified parking facilities in Rajkot, Ahmedabad, Surat, and all Indian cities. Check live bay occupancy by vehicle category, view real OpenStreetMap layouts, and get instant digital QR gate passes.
                </p>

                <!-- Quick Gujarat City Filter Chips -->
                <div class="mb-3">
                    <div class="small text-white-50 fw-semibold mb-2">
                        <i class="bi bi-pin-map text-info me-1"></i> Quick Cities (Gujarat & Metros):
                    </div>
                    <div class="city-chips-wrapper">
                        <button type="button" class="city-chip active" onclick="window.selectQuickCity('', this)">All India</button>
                        <button type="button" class="city-chip" onclick="window.selectQuickCity('Gujarat', this, true)">All Gujarat</button>
                        <?php foreach ($gujaratCities as $gCity): ?>
                            <button type="button" class="city-chip" onclick="window.selectQuickCity('<?= Security::escape($gCity) ?>', this)">
                                <?= Security::escape($gCity) ?>
                            </button>
                        <?php endforeach; ?>
                        <button type="button" class="city-chip" onclick="window.selectQuickCity('Mumbai', this)">Mumbai</button>
                        <button type="button" class="city-chip" onclick="window.selectQuickCity('Bengaluru', this)">Bengaluru</button>
                        <button type="button" class="city-chip" onclick="window.selectQuickCity('Delhi', this)">Delhi</button>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card bg-dark bg-opacity-85 text-white border border-secondary border-opacity-50 shadow-xl rounded-4 p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h2 class="h5 fw-bold mb-0 d-flex align-items-center gap-2 text-white">
                            <i class="bi bi-search text-primary"></i> Search Parking Facilities
                        </h2>
                        <button type="button" class="btn btn-outline-info btn-sm" id="btnNearMe" onclick="window.locateNearMe()">
                            <i class="bi bi-crosshair me-1"></i> Near Me (GPS)
                        </button>
                    </div>
                    
                    <form id="searchForm" onsubmit="event.preventDefault(); window.applySearchFilters();">
                        <div class="row g-2 mb-3">
                            <div class="col-md-7">
                                <label for="filterQuery" class="form-label small text-secondary fw-semibold">Location / Area / Facility</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-dark text-secondary border-secondary"><i class="bi bi-search"></i></span>
                                    <input type="text" id="filterQuery" class="form-control bg-dark text-white border-secondary" placeholder="e.g. Kalawad Road, SG Highway, Airport..." aria-label="Location search keyword">
                                </div>
                            </div>
                            <div class="col-md-5">
                                <label for="filterState" class="form-label small text-secondary fw-semibold">State / UT (India)</label>
                                <select id="filterState" class="form-select form-select-sm bg-dark text-white border-secondary" aria-label="Filter by Indian State or Union Territory">
                                    <option value="" selected>All States / UTs</option>
                                    <optgroup label="Default State">
                                        <option value="Gujarat">Gujarat</option>
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
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label for="filterStartTime" class="form-label small text-secondary fw-semibold">Check-In Time (IST)</label>
                                <input type="datetime-local" id="filterStartTime" class="form-control form-control-sm bg-dark text-white border-secondary" value="<?= $defaultStart ?>" aria-label="Check-in date and time in IST">
                            </div>
                            <div class="col-sm-6">
                                <label for="filterEndTime" class="form-label small text-secondary fw-semibold">Check-Out Time (IST)</label>
                                <input type="datetime-local" id="filterEndTime" class="form-control form-control-sm bg-dark text-white border-secondary" value="<?= $defaultEnd ?>" aria-label="Check-out date and time in IST">
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label for="filterVehicleType" class="form-label small text-secondary fw-semibold">Vehicle Classification</label>
                                <select id="filterVehicleType" class="form-select form-select-sm bg-dark text-white border-secondary" aria-label="Vehicle classification filter">
                                    <option value="all" selected>All Compatible Categories</option>
                                    <?php foreach ($vehicleCategories as $catKey => $catInfo): ?>
                                        <option value="<?= Security::escape($catKey) ?>">
                                            <?= $catInfo['icon'] ?> <?= Security::escape($catInfo['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="filterSort" class="form-label small text-secondary fw-semibold">Sort Results By</label>
                                <select id="filterSort" class="form-select form-select-sm bg-dark text-white border-secondary" aria-label="Sort facilities order">
                                    <option value="distance_asc">Nearest Distance (Near Me / GPS)</option>
                                    <option value="avail_desc" selected>Most Available Bays</option>
                                    <option value="price_asc">Lowest Hourly Rate (₹)</option>
                                    <option value="price_desc">Highest Hourly Rate (₹)</option>
                                    <option value="name_asc">Facility Name (A-Z)</option>
                                </select>
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="filterEvOnly">
                                <label class="form-check-label small text-light" for="filterEvOnly">
                                    <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Dedicated EV Charging Stall Required
                                </label>
                            </div>
                            <button type="button" class="btn btn-link text-info text-decoration-none small p-0" onclick="window.resetFilters()">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset Filters
                            </button>
                        </div>

                        <button type="submit" class="btn btn-primary-custom w-100 py-2 fw-bold shadow-sm">
                            <i class="bi bi-compass me-1"></i> Search Available Smart Parking
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Main Map & Facilities Grid Section -->
<section class="py-4">
    <div class="container-fluid px-lg-5 px-3">
        <div class="row g-4">
            <!-- Left Side: Interactive Leaflet Map -->
            <div class="col-lg-6">
                <div class="card card-custom border-0 shadow-sm h-100">
                    <div class="card-header card-header-custom d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-map-fill text-primary"></i>
                            <span class="fw-bold">Gujarat & India Live Parking Map</span>
                        </div>
                        <span id="mapStatusBadge" class="badge bg-light text-secondary border"><?= count($initialFacilities) ?> facilities active</span>
                    </div>
                    <div class="card-body p-2">
                        <div id="facilitiesMap" class="map-container" tabindex="0" aria-label="Interactive Leaflet map showing parking facility locations and real-time open spots"></div>
                        <div class="d-flex align-items-center justify-content-between p-2 mt-2 bg-light rounded-3 small text-secondary flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-3">
                                <span><i class="bi bi-circle-fill text-success me-1"></i> High Availability (&gt;10)</span>
                                <span><i class="bi bi-circle-fill text-warning me-1"></i> Few Spots (1-10)</span>
                                <span><i class="bi bi-circle-fill text-danger me-1"></i> Full (0)</span>
                            </div>
                            <span class="text-muted"><i class="bi bi-hand-index-thumb me-1"></i> Click pin or card to inspect</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side: Facilities List -->
            <div class="col-lg-6">
                <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                    <h2 class="h5 fw-bold mb-0 text-dark">
                        <i class="bi bi-buildings-fill text-primary me-2"></i> Verified Parking Facilities
                        <span id="facilityCountBadge" class="badge bg-primary bg-opacity-10 text-primary ms-1"><?= count($initialFacilities) ?></span>
                    </h2>
                    <div class="small text-secondary" id="searchWindowLabel">
                        Showing live availability (Asia/Kolkata IST)
                    </div>
                </div>

                <!-- Fast Spinner for dynamic AJAX queries -->
                <div id="facilitiesLoading" class="text-center py-5 d-none">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Searching facilities...</span>
                    </div>
                    <p class="text-secondary small mt-2">Connecting to IoT sensors & calculating live capacity...</p>
                </div>

                <div id="facilitiesEmpty" class="card card-custom border-0 p-5 text-center shadow-sm <?= empty($initialFacilities) ? '' : 'd-none' ?>">
                    <i class="bi bi-geo-alt-slash text-secondary fs-1 mb-2"></i>
                    <h4 class="h6 fw-bold">No parking facilities match your search criteria</h4>
                    <p class="text-secondary small mb-3">Try adjusting your filters, location keywords, vehicle category, or reservation times.</p>
                    <button type="button" class="btn btn-outline-primary btn-sm mx-auto" onclick="window.resetFilters()">Clear Filters</button>
                </div>

                <div id="facilitiesList" class="d-grid gap-3">
                    <?php foreach ($initialFacilities as $fac): ?>
                        <?php 
                            $amenities = $fac['amenities'];
                            $localityStr = !empty($fac['locality']) ? Security::escape($fac['locality']) . ', ' : '';
                            $fullAddress = $localityStr . Security::escape($fac['address']) . ', ' . Security::escape($fac['city']) . ', ' . Security::escape($fac['state']) . ' - ' . Security::escape($fac['postal_code']);
                            $gmapsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($fac['latitude'] . ',' . $fac['longitude']);
                        ?>
                        <div class="card card-custom facility-card-item border-0 shadow-sm" id="facility-card-<?= (int)$fac['id'] ?>">
                            <div class="card-body p-4" onclick="window.focusMapMarker(<?= (int)$fac['id'] ?>)">
                                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start gap-2 mb-2">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                            <span class="badge-source-system"><i class="bi bi-shield-check"></i> System Facility</span>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1 dist-badge d-none" id="dist-badge-<?= (int)$fac['id'] ?>"></span>
                                            <h3 class="h5 fw-bold mb-0 text-dark"><?= Security::escape($fac['name']) ?></h3>
                                            <span class="badge bg-dark font-monospace text-light" style="font-size: 0.68rem;"><?= Security::escape($fac['code']) ?></span>
                                        </div>
                                        <p class="text-secondary small mb-2"><i class="bi bi-geo-alt text-primary me-1"></i> <?= $fullAddress ?></p>
                                    </div>
                                    <div class="text-sm-end">
                                        <div class="fs-5 fw-bold text-primary">₹<?= number_format((float)$fac['hourly_rate'], 2) ?><span class="fs-6 text-secondary fw-normal">/hr</span></div>
                                        <small class="text-muted d-block">EV Bay: ₹<?= number_format((float)($fac['ev_rate'] ?? $fac['hourly_rate']), 2) ?>/hr</small>
                                    </div>
                                </div>

                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <?php if (!empty($amenities['ev_charging'])): ?>
                                        <span class="badge badge-status badge-ev"><i class="bi bi-lightning-charge-fill"></i> EV Fast Charging</span>
                                    <?php endif; ?>
                                    <?php if (!empty($amenities['cctv'])): ?>
                                        <span class="badge bg-light text-secondary border"><i class="bi bi-camera-video me-1"></i> 24/7 CCTV</span>
                                    <?php endif; ?>
                                    <?php if (!empty($amenities['covered'])): ?>
                                        <span class="badge bg-light text-secondary border"><i class="bi bi-shield-shaded me-1"></i> Covered</span>
                                    <?php endif; ?>
                                    <?php if (!empty($amenities['valet'])): ?>
                                        <span class="badge bg-light text-secondary border"><i class="bi bi-person-check me-1"></i> Valet</span>
                                    <?php endif; ?>
                                    <?php if (!empty($amenities['handicap'])): ?>
                                        <span class="badge bg-light text-secondary border"><i class="bi bi-universal-access me-1"></i> Accessible</span>
                                    <?php endif; ?>
                                </div>

                                <p class="text-secondary small mb-3 line-clamp-2"><?= Security::escape($fac['description'] ?? '') ?></p>

                                <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between pt-3 border-top gap-2">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="badge <?= $fac['available_slots'] > 0 ? 'badge-available' : 'badge-occupied' ?>">
                                            <i class="bi <?= $fac['available_slots'] > 0 ? 'bi-check-circle' : 'bi-x-circle' ?>"></i>
                                            <?= (int)$fac['available_slots'] ?> / <?= (int)$fac['total_slots'] ?> Bays Available
                                        </span>
                                        <small class="text-muted"><?= (int)($fac['grace_period_mins'] ?? 15) ?>m grace period</small>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <a href="<?= $gmapsUrl ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-danger btn-sm fw-bold" onclick="event.stopPropagation();">
                                            <i class="bi bi-google me-1"></i> Google Maps Directions
                                        </a>
                                        <a href="reserve.php?facility_id=<?= (int)$fac['id'] ?>&start_time=<?= urlencode($defaultStart) ?>&end_time=<?= urlencode($defaultEnd) ?>&vehicle_type=car" class="btn btn-primary-custom btn-sm px-3 fw-bold shadow-sm" onclick="event.stopPropagation();">
                                            Select Slot & Book <i class="bi bi-arrow-right ms-1"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Embedded Initial Data -->
<script>
window.initialFacilitiesData = <?= json_encode($initialFacilities, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>

<!-- Frontend Map & Search Logic -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    let map = null;
    let markersLayer = null;
    let markersMap = {};
    let currentFacilities = window.initialFacilitiesData || [];
    let userLocation = null;

    // Haversine distance calculator in kilometers
    function calculateDistanceKm(lat1, lon1, lat2, lon2) {
        if (!lat1 || !lon1 || !lat2 || !lon2) return null;
        const R = 6371; // Earth's radius in km
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon/2) * Math.sin(dLon/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    // Initialize Leaflet Map
    function initMap() {
        if (map) return;
        const mapEl = document.getElementById('facilitiesMap');
        if (!mapEl) return;

        map = L.map('facilitiesMap', {
            zoomControl: true,
            scrollWheelZoom: true
        }).setView([22.3039, 70.8022], 7);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
            maxZoom: 19
        }).addTo(map);

        markersLayer = L.layerGroup().addTo(map);
    }

    // Custom Map Marker Icons based on availability
    function createMarkerIcon(availCount, isSystem = true) {
        let colorClass = availCount > 10 ? '#10b981' : (availCount > 0 ? '#f59e0b' : '#ef4444');
        let label = isSystem ? availCount : '?';
        let bgStyle = isSystem ? colorClass : '#64748b';

        return L.divIcon({
            className: 'custom-map-pin',
            html: `
                <div style="background-color: ${bgStyle}; width: 36px; height: 36px; border-radius: 50%; border: 3px solid #ffffff; box-shadow: 0 4px 10px rgba(0,0,0,0.35); display: flex; align-items: center; justify-content: center; color: #ffffff; font-weight: 800; font-size: 13px; font-family: monospace; transition: transform 0.2s ease;">
                    ${label}
                </div>
            `,
            iconSize: [36, 36],
            iconAnchor: [18, 18],
            popupAnchor: [0, -18]
        });
    }

    // Helper to safely escape HTML
    function esc(str) {
        if (window.ParkPulse && typeof window.ParkPulse.escapeHtml === 'function') {
            return window.ParkPulse.escapeHtml(str);
        }
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // Render Leaflet Markers and Facility Cards
    function renderFacilities(facilitiesList, fitBounds = true) {
        const listContainer = document.getElementById('facilitiesList');
        const emptyEl = document.getElementById('facilitiesEmpty');
        const countBadge = document.getElementById('facilityCountBadge');
        const mapStatus = document.getElementById('mapStatusBadge');

        const startTime = document.getElementById('filterStartTime').value;
        const endTime = document.getElementById('filterEndTime').value;
        const vehicleType = document.getElementById('filterVehicleType').value;

        if (countBadge) countBadge.innerText = facilitiesList.length;
        if (mapStatus) mapStatus.innerText = `${facilitiesList.length} facilities active`;

        if (markersLayer) {
            markersLayer.clearLayers();
        }
        markersMap = {};

        if (facilitiesList.length === 0) {
            if (emptyEl) emptyEl.classList.remove('d-none');
            if (listContainer) listContainer.innerHTML = '';
            return;
        }

        if (emptyEl) emptyEl.classList.add('d-none');
        if (listContainer) listContainer.innerHTML = '';

        const bounds = [];

        facilitiesList.forEach(fac => {
            const gmapsDirectionUrl = userLocation 
                ? `https://www.google.com/maps/dir/?api=1&origin=${userLocation.lat},${userLocation.lng}&destination=${fac.latitude},${fac.longitude}`
                : `https://www.google.com/maps/dir/?api=1&destination=${fac.latitude},${fac.longitude}`;

            // Add marker to map
            if (fac.latitude && fac.longitude && map && markersLayer) {
                const latLng = [parseFloat(fac.latitude), parseFloat(fac.longitude)];
                bounds.push(latLng);

                const marker = L.marker(latLng, {
                    icon: createMarkerIcon(fac.available_slots, true),
                    title: fac.name
                });

                const distHtml = fac.distance_km !== undefined && fac.distance_km < 1000 
                    ? `<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 ms-1"><i class="bi bi-geo-alt-fill"></i> ${fac.distance_km.toFixed(1)} km</span>` 
                    : '';

                const popupContent = `
                    <div style="min-width: 240px; font-family: inherit;">
                        <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                            <span class="badge-source-system"><i class="bi bi-shield-check"></i> System Facility</span>
                            ${distHtml}
                        </div>
                        <div style="font-weight: 800; font-size: 14px; margin-bottom: 2px; color: #0f172a;">${esc(fac.name)}</div>
                        <div style="font-size: 12px; color: #64748b; margin-bottom: 6px;">${esc(fac.locality ? fac.locality + ', ' : '')}${esc(fac.city)}, ${esc(fac.state)} - ${esc(fac.postal_code || '')}</div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 13px;">
                            <span style="font-weight: 700; color: #2563eb;">₹${parseFloat(fac.hourly_rate).toFixed(2)}/hr</span>
                            <span class="badge ${fac.available_slots > 0 ? 'bg-success' : 'bg-danger'}">${fac.available_slots} spots open</span>
                        </div>
                        <div class="d-flex gap-1 mb-2">
                            <a href="${gmapsDirectionUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-danger btn-sm flex-fill fw-bold" style="font-size: 0.75rem;">
                                <i class="bi bi-google me-1"></i> Google Directions
                            </a>
                            <a href="${fac.google_maps_url}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm flex-fill" style="font-size: 0.75rem;">
                                <i class="bi bi-pin-map"></i> View Pin
                            </a>
                        </div>
                        <a href="reserve.php?facility_id=${fac.id}&start_time=${encodeURIComponent(startTime)}&end_time=${encodeURIComponent(endTime)}&vehicle_type=${encodeURIComponent(vehicleType !== 'all' ? vehicleType : 'car')}" class="btn btn-primary btn-sm w-100 text-white" style="font-weight: 600; text-decoration: none;">
                            Select Slot & Book &rarr;
                        </a>
                    </div>
                `;

                marker.bindPopup(popupContent);
                marker.on('click', () => window.focusFacilityCard(fac.id));
                markersLayer.addLayer(marker);
                markersMap[fac.id] = marker;
            }

            // Render Card
            const card = document.createElement('div');
            card.className = 'card card-custom facility-card-item border-0 shadow-sm';
            card.id = `facility-card-${fac.id}`;

            let amenitiesBadges = '';
            if (fac.amenities) {
                if (fac.amenities.ev_charging) amenitiesBadges += `<span class="badge badge-status badge-ev"><i class="bi bi-lightning-charge-fill"></i> EV Fast Charging</span> `;
                if (fac.amenities.cctv) amenitiesBadges += `<span class="badge bg-light text-secondary border"><i class="bi bi-camera-video me-1"></i> 24/7 CCTV</span> `;
                if (fac.amenities.covered) amenitiesBadges += `<span class="badge bg-light text-secondary border"><i class="bi bi-shield-shaded me-1"></i> Covered</span> `;
                if (fac.amenities.valet) amenitiesBadges += `<span class="badge bg-light text-secondary border"><i class="bi bi-person-check me-1"></i> Valet</span> `;
                if (fac.amenities.handicap) amenitiesBadges += `<span class="badge bg-light text-secondary border"><i class="bi bi-universal-access me-1"></i> Accessible</span> `;
            }

            const localityStr = fac.locality ? `${esc(fac.locality)}, ` : '';
            const fullAddress = `${localityStr}${esc(fac.address)}, ${esc(fac.city)}, ${esc(fac.state)} - ${esc(fac.postal_code || '')}`;
            
            const distBadgeHtml = fac.distance_km !== undefined && fac.distance_km < 1000
                ? `<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1"><i class="bi bi-geo-alt-fill me-1"></i> ${fac.distance_km.toFixed(1)} km away</span>`
                : '';

            card.innerHTML = `
                <div class="card-body p-4" onclick="window.focusMapMarker(${fac.id})">
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <span class="badge-source-system"><i class="bi bi-shield-check"></i> System Facility</span>
                                ${distBadgeHtml}
                                <h3 class="h5 fw-bold mb-0 text-dark">${esc(fac.name)}</h3>
                                <span class="badge bg-dark font-monospace text-light" style="font-size: 0.68rem;">${esc(fac.code)}</span>
                            </div>
                            <p class="text-secondary small mb-2"><i class="bi bi-geo-alt text-primary me-1"></i> ${fullAddress}</p>
                        </div>
                        <div class="text-sm-end">
                            <div class="fs-5 fw-bold text-primary">₹${parseFloat(fac.hourly_rate).toFixed(2)}<span class="fs-6 text-secondary fw-normal">/hr</span></div>
                            <small class="text-muted d-block">EV Bay: ₹${parseFloat(fac.ev_rate || fac.hourly_rate).toFixed(2)}/hr</small>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-1 mb-2">
                        ${amenitiesBadges}
                    </div>

                    <p class="text-secondary small mb-3 line-clamp-2">${esc(fac.description || '')}</p>

                    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between pt-3 border-top gap-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge ${fac.available_slots > 0 ? 'badge-available' : 'badge-occupied'}">
                                <i class="bi ${fac.available_slots > 0 ? 'bi-check-circle' : 'bi-x-circle'}"></i>
                                ${fac.available_slots} / ${fac.total_slots} Bays Available
                            </span>
                            <small class="text-muted">${fac.grace_period_mins || 15}m grace period</small>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <a href="${gmapsDirectionUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-danger btn-sm fw-bold" onclick="event.stopPropagation();">
                                <i class="bi bi-google me-1"></i> Google Maps Directions
                            </a>
                            <a href="reserve.php?facility_id=${fac.id}&start_time=${encodeURIComponent(startTime)}&end_time=${encodeURIComponent(endTime)}&vehicle_type=${encodeURIComponent(vehicleType !== 'all' ? vehicleType : 'car')}" class="btn btn-primary-custom btn-sm px-3 fw-bold shadow-sm" onclick="event.stopPropagation();">
                                Select Slot & Book <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            `;

            if (listContainer) listContainer.appendChild(card);
        });

        if (fitBounds && bounds.length > 0 && map) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
        }
    }

    // Quick City Selection
    window.selectQuickCity = function(cityName, btnElement, isState = false) {
        document.querySelectorAll('.city-chip').forEach(el => el.classList.remove('active'));
        if (btnElement) btnElement.classList.add('active');

        if (isState) {
            document.getElementById('filterState').value = 'Gujarat';
            document.getElementById('filterQuery').value = '';
        } else if (cityName) {
            document.getElementById('filterQuery').value = cityName;
            if (['Rajkot', 'Ahmedabad', 'Surat', 'Vadodara', 'Gandhinagar', 'Jamnagar', 'Bhavnagar', 'Junagadh', 'Morbi', 'Anand'].includes(cityName)) {
                document.getElementById('filterState').value = 'Gujarat';
            } else if (cityName === 'Mumbai') {
                document.getElementById('filterState').value = 'Maharashtra';
            } else if (cityName === 'Bengaluru') {
                document.getElementById('filterState').value = 'Karnataka';
            } else if (cityName === 'Delhi') {
                document.getElementById('filterState').value = 'Delhi (NCT)';
            }
        } else {
            document.getElementById('filterQuery').value = '';
            document.getElementById('filterState').value = '';
        }

        window.applySearchFilters();
    };

    // Geolocation "Near Me" (Google Maps & Device GPS)
    window.locateNearMe = function() {
        const btn = document.getElementById('btnNearMe');
        if (!navigator.geolocation) {
            if (window.ParkPulse) ParkPulse.showToast('warning', 'Browser geolocation is not supported on this device.', 'Location Unavailable');
            return;
        }

        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Locating...';
        btn.disabled = true;

        navigator.geolocation.getCurrentPosition(
            (position) => {
                btn.innerHTML = '<i class="bi bi-geo-alt-fill text-success me-1"></i> Near Me (Active)';
                btn.disabled = false;
                const userLat = position.coords.latitude;
                const userLng = position.coords.longitude;
                userLocation = { lat: userLat, lng: userLng };

                document.getElementById('filterSort').value = 'distance_asc';

                if (map) {
                    map.setView([userLat, userLng], 14);
                    L.circleMarker([userLat, userLng], {
                        radius: 9,
                        fillColor: '#2563eb',
                        color: '#ffffff',
                        weight: 3,
                        opacity: 1,
                        fillOpacity: 0.95
                    }).addTo(map).bindPopup('<strong>📍 You are here</strong><br><small class="text-muted">Calculating closest smart parking bays...</small>').openPopup();
                }

                if (window.ParkPulse) {
                    ParkPulse.showToast('success', `Found your location (${userLat.toFixed(4)}, ${userLng.toFixed(4)}). Sorting parking by nearest distance!`, 'Location Detected');
                }
                window.applySearchFilters();
            },
            (error) => {
                btn.innerHTML = '<i class="bi bi-crosshair me-1"></i> Near Me (GPS)';
                btn.disabled = false;
                let msg = 'Could not retrieve your location. Please check browser permissions.';
                if (error.code === error.PERMISSION_DENIED) {
                    msg = 'Location permission was denied. You can still search by city or state.';
                }
                if (window.ParkPulse) ParkPulse.showToast('warning', msg, 'Location Error');
            },
            { timeout: 8000, enableHighAccuracy: true }
        );
    };

    // Highlight card when marker clicked
    window.focusFacilityCard = function(facilityId) {
        document.querySelectorAll('.facility-card-item').forEach(card => card.classList.remove('border-primary', 'shadow-lg'));
        const targetCard = document.getElementById(`facility-card-${facilityId}`);
        if (targetCard) {
            targetCard.classList.add('border-primary', 'shadow-lg');
            targetCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    };

    // Pan map when card clicked
    window.focusMapMarker = function(facilityId) {
        const marker = markersMap[facilityId];
        if (marker && map) {
            map.panTo(marker.getLatLng(), { animate: true });
            marker.openPopup();
        }
    };

    // Fetch and render facilities via AJAX for searches
    window.applySearchFilters = async function() {
        const query = document.getElementById('filterQuery').value.trim();
        const state = document.getElementById('filterState').value;
        const startTime = document.getElementById('filterStartTime').value;
        const endTime = document.getElementById('filterEndTime').value;
        const evOnly = document.getElementById('filterEvOnly').checked ? '1' : '';
        const vehicleType = document.getElementById('filterVehicleType').value;
        const sort = document.getElementById('filterSort').value;

        if (startTime && endTime && new Date(startTime) >= new Date(endTime)) {
            if (window.ParkPulse) ParkPulse.showToast('danger', 'Check-out time must be after check-in time.', 'Time Error');
            return;
        }

        const loadingEl = document.getElementById('facilitiesLoading');
        const emptyEl = document.getElementById('facilitiesEmpty');
        const mapStatus = document.getElementById('mapStatusBadge');

        if (loadingEl) loadingEl.classList.remove('d-none');
        if (emptyEl) emptyEl.classList.add('d-none');
        if (mapStatus) mapStatus.innerText = 'Updating verified pins...';

        try {
            const url = `api/facilities.php?q=${encodeURIComponent(query)}&state=${encodeURIComponent(state)}&start_time=${encodeURIComponent(startTime)}&end_time=${encodeURIComponent(endTime)}&ev_only=${evOnly}&vehicle_type=${encodeURIComponent(vehicleType)}&sort=${encodeURIComponent(sort)}`;
            
            let rawFacilities = [];
            if (window.ParkPulse && typeof window.ParkPulse.fetchJson === 'function') {
                const res = await ParkPulse.fetchJson(url);
                rawFacilities = res.data || [];
            } else {
                const resp = await fetch(url);
                const res = await resp.json();
                rawFacilities = res.data || [];
            }

            if (loadingEl) loadingEl.classList.add('d-none');

            // Calculate distance to each facility if user location is available
            if (userLocation) {
                rawFacilities.forEach(f => {
                    if (f.latitude && f.longitude) {
                        f.distance_km = calculateDistanceKm(userLocation.lat, userLocation.lng, parseFloat(f.latitude), parseFloat(f.longitude));
                    } else {
                        f.distance_km = 99999;
                    }
                });

                if (sort === 'distance_asc') {
                    rawFacilities.sort((a, b) => (a.distance_km || 99999) - (b.distance_km || 99999));
                }
            }

            currentFacilities = rawFacilities;
            renderFacilities(currentFacilities, true);

        } catch (err) {
            if (loadingEl) loadingEl.classList.add('d-none');
            if (window.ParkPulse) ParkPulse.showToast('danger', 'Failed to load parking facilities: ' + err.message, 'Network Error');
            if (mapStatus) mapStatus.innerText = 'Failed to load';
        }
    };

    window.resetFilters = function() {
        document.getElementById('filterQuery').value = '';
        document.getElementById('filterState').value = '';
        document.getElementById('filterEvOnly').checked = false;
        document.getElementById('filterVehicleType').value = 'all';
        document.getElementById('filterSort').value = 'avail_desc';
        document.querySelectorAll('.city-chip').forEach(el => el.classList.remove('active'));
        const allIndiaBtn = document.querySelector('.city-chip:first-child');
        if (allIndiaBtn) allIndiaBtn.classList.add('active');
        window.applySearchFilters();
    };

    // Initialize Map and Render initial facilities immediately
    initMap();
    if (window.initialFacilitiesData && window.initialFacilitiesData.length > 0) {
        renderFacilities(window.initialFacilitiesData, true);
    } else {
        window.applySearchFilters();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
