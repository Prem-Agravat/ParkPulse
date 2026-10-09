<?php
/**
 * Real-Time Facility Occupancy Matrix & Slot Health Monitor
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireRole(['attendant', 'admin']);

$db = Database::getInstance()->getConnection();
$attendantId = Auth::id();

// Fetch all active facilities for switching
$facStmt = $db->query("SELECT * FROM facilities WHERE status = 'active' ORDER BY name ASC");
$facilities = $facStmt->fetchAll();

// Determine default selected facility (attendant assignment or first facility)
$selectedFacilityId = (int)($_GET['facility_id'] ?? 0);
if ($selectedFacilityId <= 0) {
    $attAssignment = $db->prepare("SELECT facility_id FROM attendant_assignments WHERE attendant_id = ? AND is_active = 1 LIMIT 1");
    $attAssignment->execute([$attendantId]);
    $assignedId = $attAssignment->fetchColumn();
    $selectedFacilityId = $assignedId ? (int)$assignedId : (int)($facilities[0]['id'] ?? 1);
}

// Fetch current facility details
$currStmt = $db->prepare("SELECT * FROM facilities WHERE id = ?");
$currStmt->execute([$selectedFacilityId]);
$currentFacility = $currStmt->fetch() ?: ($facilities[0] ?? null);

$pageTitle = 'Live Facility Occupancy';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 px-3 py-4">
    <!-- Top Bar with Facility Selector -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark">Live Facility Occupancy Matrix</h1>
            <p class="text-secondary small mb-0">Real-time IoT stall monitoring, bay health, and attendant maintenance controls</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <label for="facilitySwitcher" class="small text-secondary fw-semibold text-nowrap">Switch Facility:</label>
            <select id="facilitySwitcher" class="form-select form-select-sm fw-bold border-primary" onchange="window.location.href='facility-occupancy.php?facility_id=' + this.value;">
                <?php foreach ($facilities as $fac): ?>
                    <option value="<?= (int)$fac['id'] ?>" <?= $fac['id'] == $selectedFacilityId ? 'selected' : '' ?>>
                        <?= Security::escape($fac['name']) ?> (<?= Security::escape($fac['city']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Live Stat Metric Cards -->
    <div class="row g-3 mb-4" id="statsRow">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card border-0 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label">Available Bays</div>
                        <div class="stat-value text-success" id="statAvail">--</div>
                    </div>
                    <div class="stat-icon-wrapper bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                </div>
                <div class="small text-muted mt-2">Ready for incoming vehicles</div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card border-0 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label">Currently Occupied</div>
                        <div class="stat-value text-danger" id="statOcc">--</div>
                    </div>
                    <div class="stat-icon-wrapper bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-car-front-fill"></i>
                    </div>
                </div>
                <div class="small text-muted mt-2">Parked vehicles on premises</div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card border-0 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label">Reserved (Upcoming)</div>
                        <div class="stat-value text-warning" id="statRes">--</div>
                    </div>
                    <div class="stat-icon-wrapper bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-clock-fill"></i>
                    </div>
                </div>
                <div class="small text-muted mt-2">Pre-booked online slots</div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card border-0 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label">Occupancy Rate</div>
                        <div class="stat-value text-primary" id="statRate">--%</div>
                    </div>
                    <div class="stat-icon-wrapper bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-pie-chart-fill"></i>
                    </div>
                </div>
                <div class="progress mt-2" style="height: 6px;">
                    <div id="statProgressBar" class="progress-bar bg-primary" role="progressbar" style="width: 0%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Visual Matrix Section -->
    <div class="card card-custom border-0 shadow-sm">
        <div class="card-header card-header-custom d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-grid-fill text-primary"></i>
                <span class="fw-bold"><?= Security::escape($currentFacility['name'] ?? 'Facility') ?> — Stalls Matrix</span>
            </div>
            <div class="d-flex align-items-center gap-3 small">
                <span class="d-flex align-items-center gap-1"><span class="badge-status badge-available p-1 px-2">●</span> Available</span>
                <span class="d-flex align-items-center gap-1"><span class="badge-status badge-occupied p-1 px-2">●</span> Occupied</span>
                <span class="d-flex align-items-center gap-1"><span class="badge-status badge-reserved p-1 px-2">●</span> Reserved</span>
                <span class="d-flex align-items-center gap-1"><span class="badge-status badge-maintenance p-1 px-2">●</span> Maintenance</span>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="window.loadOccupancyMatrix()">
                    <i class="bi bi-arrow-repeat"></i> Refresh
                </button>
            </div>
        </div>

        <div class="card-body p-4">
            <!-- Floor Navigation Tabs -->
            <ul class="nav nav-pills mb-3" id="occupancyFloorTabs" role="tablist">
                <!-- Dynamic tabs -->
            </ul>

            <div class="slot-grid-container position-relative">
                <div id="occupancyLoader" class="text-center py-5">
                    <div class="spinner-border text-info" role="status"></div>
                    <p class="text-light small mt-2">Loading live stall grid...</p>
                </div>

                <div id="occupancyTabContent" class="tab-content d-none">
                    <!-- Dynamic floors -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Slot Maintenance Modal -->
<div class="modal fade" id="slotMaintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h6 class="modal-title fw-bold" id="slotModalTitle">Manage Slot</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 text-center">
                <p class="text-secondary small mb-3">Update stall operational status or trigger maintenance mode lock.</p>
                <div class="d-grid gap-2">
                    <button type="button" id="btnSetAvailable" class="btn btn-success btn-sm fw-bold" onclick="window.updateSlotStatus('available')">
                        <i class="bi bi-check-circle me-1"></i> Mark as Available
                    </button>
                    <button type="button" id="btnSetMaintenance" class="btn btn-secondary btn-sm fw-bold" onclick="window.updateSlotStatus('maintenance')">
                        <i class="bi bi-tools me-1"></i> Mark Under Maintenance
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const facilityId = <?= (int)$selectedFacilityId ?>;
    let selectedSlotForModal = null;
    const maintenanceModal = new bootstrap.Modal(document.getElementById('slotMaintenanceModal'));

    window.loadOccupancyMatrix = async function() {
        const loader = document.getElementById('occupancyLoader');
        const content = document.getElementById('occupancyTabContent');
        const tabsNav = document.getElementById('occupancyFloorTabs');

        loader.classList.remove('d-none');
        content.classList.add('d-none');
        tabsNav.innerHTML = '';
        content.innerHTML = '';

        try {
            // Load current real-time state (without filter times to get raw current slot statuses)
            const res = await ParkPulse.fetchJson(`api/slots.php?facility_id=${facilityId}`);
            
            loader.classList.add('d-none');
            content.classList.remove('d-none');

            // Update stats
            const stats = res.stats;
            document.getElementById('statAvail').innerText = stats.available;
            document.getElementById('statOcc').innerText = stats.occupied;
            document.getElementById('statRes').innerText = stats.reserved;

            const occupiedTotal = stats.occupied + stats.reserved;
            const rate = stats.total > 0 ? Math.round((occupiedTotal / stats.total) * 100) : 0;
            document.getElementById('statRate').innerText = `${rate}%`;
            document.getElementById('statProgressBar').style.width = `${rate}%`;

            renderMatrix(res.floors);
        } catch (err) {
            loader.classList.add('d-none');
            ParkPulse.showToast('danger', err.message, 'Matrix Error');
        }
    };

    function renderMatrix(floors) {
        const tabsNav = document.getElementById('occupancyFloorTabs');
        const tabContent = document.getElementById('occupancyTabContent');

        tabsNav.innerHTML = '';
        tabContent.innerHTML = '';

        floors.forEach((fl, idx) => {
            const tabId = `occ-tab-${idx}`;
            const paneId = `occ-pane-${idx}`;
            const isActive = idx === 0;

            const navItem = document.createElement('li');
            navItem.className = 'nav-item';
            navItem.innerHTML = `
                <button class="nav-link ${isActive ? 'active' : ''} fw-bold" id="${tabId}" data-bs-toggle="pill" data-bs-target="#${paneId}" type="button" role="tab">
                    <i class="bi bi-layers-half me-1"></i> ${fl.floor_name}
                </button>
            `;
            tabsNav.appendChild(navItem);

            const pane = document.createElement('div');
            pane.className = `tab-pane fade ${isActive ? 'show active' : ''}`;
            pane.id = paneId;

            let zonesHtml = '';
            fl.zones.forEach(zn => {
                let slotsHtml = '';
                zn.slots.forEach(s => {
                    let typeIcon = s.slot_type === 'bike' ? 'bi-bicycle' : (s.slot_type === 'suv' ? 'bi-truck' : 'bi-car-front');
                    if (s.is_ev_charging) typeIcon = 'bi-lightning-charge-fill';
                    if (s.is_accessible) typeIcon = 'bi-universal-access';

                    slotsHtml += `
                        <div class="slot-box status-${s.status}" 
                             data-slot-id="${s.id}" 
                             data-slot-number="${s.slot_number}" 
                             data-status="${s.status}"
                             onclick="window.openSlotModal(${s.id}, '${s.slot_number}', '${s.status}')"
                             title="Click to manage Slot ${s.slot_number}">
                            <div class="slot-number">${s.slot_number}</div>
                            <div class="slot-icon"><i class="bi ${typeIcon}"></i></div>
                            <div class="slot-tag bg-dark bg-opacity-50 text-white">${s.status.toUpperCase()}</div>
                        </div>
                    `;
                });

                zonesHtml += `
                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-light fw-bold small"><i class="bi bi-signpost-2 text-info me-1"></i> ${zn.zone_name}</span>
                            <span class="badge bg-dark border border-secondary text-secondary small">${zn.slots.length} bays</span>
                        </div>
                        <div class="slots-matrix">
                            ${slotsHtml}
                        </div>
                    </div>
                `;
            });

            pane.innerHTML = zonesHtml;
            tabContent.appendChild(pane);
        });
    }

    window.openSlotModal = function(id, number, status) {
        selectedSlotForModal = { id, number, status };
        document.getElementById('slotModalTitle').innerText = `Slot ${number} (${status.toUpperCase()})`;
        
        if (status === 'occupied') {
            document.getElementById('btnSetMaintenance').setAttribute('disabled', 'true');
        } else {
            document.getElementById('btnSetMaintenance').removeAttribute('disabled');
        }

        maintenanceModal.show();
    };

    window.updateSlotStatus = async function(newStatus) {
        if (!selectedSlotForModal) return;
        try {
            const res = await ParkPulse.fetchJson('api/toggle-slot.php', {
                method: 'POST',
                body: JSON.stringify({
                    slot_id: selectedSlotForModal.id,
                    status: newStatus
                })
            });

            if (res.success) {
                maintenanceModal.hide();
                ParkPulse.showToast('success', res.message, 'Slot Updated');
                window.loadOccupancyMatrix();
            }
        } catch (err) {
            ParkPulse.showToast('danger', err.message, 'Update Failed');
        }
    };

    window.loadOccupancyMatrix();
    // Auto-refresh occupancy every 45 seconds
    setInterval(window.loadOccupancyMatrix, 45000);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
