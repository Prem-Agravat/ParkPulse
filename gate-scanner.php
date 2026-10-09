<?php
/**
 * Attendant Gate Terminal & Camera QR Scanner
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireRole(['attendant', 'admin']);

$db = Database::getInstance()->getConnection();
$attendantId = Auth::id();

// Fetch attendant assigned facility if any
$stmt = $db->prepare("SELECT f.* FROM facilities f 
    JOIN attendant_assignments aa ON f.id = aa.facility_id 
    WHERE aa.attendant_id = ? AND aa.is_active = 1 LIMIT 1");
$stmt->execute([$attendantId]);
$assignedFacility = $stmt->fetch();

// Fetch latest active/confirmed/checked-in reservations for instant verification pills
$recentBookingsStmt = $db->query("SELECT r.booking_reference, r.vehicle_plate, r.status, f.name AS facility_name, u.name AS driver_name 
    FROM reservations r 
    JOIN facilities f ON r.facility_id = f.id 
    JOIN users u ON r.user_id = u.id 
    WHERE r.status IN ('confirmed', 'checked_in') 
    ORDER BY r.id DESC 
    LIMIT 4");
$recentBookings = $recentBookingsStmt->fetchAll();

$pageTitle = 'Gate Terminal & QR Scanner';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Header banner -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-warning bg-opacity-10 text-warning-emphasis border border-warning border-opacity-25 mb-1 small fw-bold">
                <i class="bi bi-shield-check"></i> Attendant Terminal Mode
            </div>
            <h1 class="h3 fw-bold mb-0 text-dark">Gate Barrier Scanner & Vehicle Verification</h1>
            <?php if ($assignedFacility): ?>
                <p class="text-secondary small mb-0"><i class="bi bi-building text-primary"></i> Stationed at: <strong><?= Security::escape($assignedFacility['name']) ?></strong></p>
            <?php endif; ?>
        </div>
        <a href="facility-occupancy.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-grid-3x3-gap-fill me-1"></i> Live Facility Matrix
        </a>
    </div>

    <div class="row g-4">
        <!-- Left: Camera Scanner & Manual Input -->
        <div class="col-lg-6">
            <!-- Camera Scanner Card -->
            <div class="card card-custom border-0 shadow-sm mb-4">
                <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-camera-video-fill text-primary"></i>
                        <span class="fw-bold">Camera QR Scanner</span>
                        <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25 ms-1 small" style="font-size: 0.7rem;">Auto-Detect Active</span>
                    </div>
                    <div class="d-flex gap-1">
                        <button type="button" id="btnStartScanner" class="btn btn-primary btn-sm px-3 fw-bold" onclick="window.startQrScanner()">
                            <i class="bi bi-play-circle me-1"></i> Start Camera
                        </button>
                        <button type="button" id="btnStopScanner" class="btn btn-outline-light btn-sm d-none" onclick="window.stopQrScanner()">
                            <i class="bi bi-stop-circle me-1"></i> Pause
                        </button>
                    </div>
                </div>
                <div class="card-body p-3 bg-dark text-center">
                    <div id="qr-reader" class="rounded-3 overflow-hidden" style="min-height: 280px; display: flex; align-items: center; justify-content: center; background: #0f172a;">
                        <div id="scannerPlaceholder" class="text-secondary p-4">
                            <i class="bi bi-qr-code-scan fs-1 d-block mb-2 text-primary"></i>
                            <span class="small d-block text-white fw-semibold mb-1">Point Camera at Driver QR Ticket</span>
                            <span class="small text-secondary">The system will automatically detect the QR pass without pressing any buttons.</span>
                        </div>
                    </div>
                    <div id="scannerFeedback" class="small text-light text-opacity-75 mt-2">
                        <i class="bi bi-magic text-warning me-1"></i> Auto-Detection: Show QR code in frame to verify instantly.
                    </div>
                </div>
            </div>

            <!-- Manual Plate / Token Search Card -->
            <div class="card card-custom border-0 shadow-sm mb-4">
                <div class="card-header card-header-custom d-flex align-items-center gap-2">
                    <i class="bi bi-input-cursor-text text-primary"></i>
                    <span>Instant Lookup (Auto-Verifies as you type)</span>
                </div>
                <div class="card-body p-3">
                    <form id="manualLookupForm" onsubmit="event.preventDefault(); window.searchBooking(document.getElementById('manualQuery').value);">
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                            <input type="text" id="manualQuery" class="form-control font-monospace text-uppercase" placeholder="Enter Booking Ref (PK-2026-...) or Plate Number (GJ-03...)" autocomplete="off">
                            <button type="submit" class="btn btn-primary-custom px-4 fw-bold">Verify</button>
                        </div>
                        <div class="form-text text-muted small mt-1">
                            <i class="bi bi-lightning-charge text-warning"></i> Auto-lookup triggers automatically on QR scan, paste, or plate entry.
                        </div>
                    </form>

                    <!-- Fast Reviewer Demo Fast-Fill Pills -->
                    <?php if (!empty($recentBookings)): ?>
                        <div class="mt-3 pt-2 border-top">
                            <small class="text-secondary fw-bold text-uppercase d-block mb-2" style="font-size: 0.72rem;">Live Active Bookings (Click to Test Instantly)</small>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($recentBookings as $rb): ?>
                                    <button type="button" class="btn btn-sm <?= $rb['status'] === 'confirmed' ? 'btn-outline-warning' : 'btn-outline-success' ?> text-dark font-monospace" onclick="window.searchBooking('<?= Security::escape($rb['booking_reference']) ?>')">
                                        <i class="bi <?= $rb['status'] === 'confirmed' ? 'bi-arrow-right-circle text-warning' : 'bi-arrow-left-circle text-success' ?> me-1"></i>
                                        <?= Security::escape($rb['booking_reference']) ?> (<?= Security::escape($rb['vehicle_plate']) ?> - <?= ucfirst(Security::escape($rb['status'])) ?>)
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Real-Time Gate Verification & Action Card -->
        <div class="col-lg-6">
            <div class="card card-custom border-0 shadow-lg h-100" id="verificationContainer">
                <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-shield-check text-primary fs-5"></i>
                        <span class="fw-bold">Gate Authorization Card</span>
                    </div>
                    <span id="gateStatusBadge" class="badge bg-secondary text-uppercase">Awaiting Scan</span>
                </div>

                <div class="card-body p-4 d-flex flex-column justify-content-between" id="verificationBody">
                    <!-- Idle State -->
                    <div id="idleNotice" class="text-center py-5 my-auto">
                        <div class="d-inline-flex align-items-center justify-content-center bg-light text-secondary rounded-circle mb-3" style="width: 80px; height: 80px;">
                            <i class="bi bi-qr-code fs-1"></i>
                        </div>
                        <h4 class="h5 fw-bold text-dark">No Pass Selected</h4>
                        <p class="text-secondary small mb-0" style="max-width: 320px; margin: 0 auto;">
                            Scan a QR code from a customer's phone or enter a license plate number to verify booking status and admit/release vehicles.
                        </p>
                    </div>

                    <!-- Populated Details State (Hidden initially) -->
                    <div id="passDetailsView" class="d-none">
                        <!-- Alert Banners -->
                        <div id="overstayAlert" class="alert alert-danger d-none d-flex align-items-center mb-3">
                            <i class="bi bi-exclamation-triangle-fill fs-4 me-2"></i>
                            <div>
                                <strong>Overstay Penalty Triggered!</strong><br>
                                <span id="overstayMsg">Exceeded booked duration.</span>
                            </div>
                        </div>

                        <div id="earlyAlert" class="alert alert-info d-none d-flex align-items-center mb-3">
                            <i class="bi bi-info-circle-fill fs-5 me-2"></i>
                            <div>Driver arrived early. You may admit vehicle if spot is clear.</div>
                        </div>

                        <!-- Driver & Plate Row -->
                        <div class="d-flex align-items-center justify-content-between bg-light p-3 rounded-3 border mb-3">
                            <div>
                                <small class="text-secondary d-block">Vehicle & License Plate</small>
                                <span class="fs-5 font-monospace fw-bold text-dark bg-dark text-white px-2 py-1 rounded d-inline-block" id="detailPlate">
                                    --
                                </span>
                                <div class="small text-muted mt-1" id="detailMakeModel">--</div>
                            </div>
                            <div class="text-end">
                                <small class="text-secondary d-block">Driver</small>
                                <strong class="text-dark" id="detailDriverName">--</strong>
                                <div class="small text-muted" id="detailDriverPhone">--</div>
                            </div>
                        </div>

                        <!-- Slot and Facility -->
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3 border h-100">
                                    <small class="text-secondary d-block">Allocated Stall</small>
                                    <span class="fs-4 font-monospace fw-bold text-primary" id="detailSlotNumber">--</span>
                                    <div class="small text-muted" id="detailFloorZone">--</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-3 border h-100">
                                    <small class="text-secondary d-block">Facility Location</small>
                                    <strong class="text-dark d-block text-truncate" id="detailFacilityName">--</strong>
                                    <span class="badge bg-secondary font-monospace small" id="detailBookingRef">--</span>
                                </div>
                            </div>
                        </div>

                        <!-- Schedule & Financials -->
                        <div class="bg-light p-3 rounded-3 border mb-4 small">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-secondary">Booked Schedule:</span>
                                <span class="fw-semibold text-dark" id="detailSchedule">--</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1" id="checkInRow">
                                <span class="text-secondary">Actual Entry Time:</span>
                                <span class="fw-semibold text-dark" id="detailActualCheckIn">--</span>
                            </div>
                            <div class="d-flex justify-content-between pt-1 border-top mt-2">
                                <span class="text-secondary">Prepaid Base Amount:</span>
                                <span class="fw-bold text-success" id="detailPaidAmount">₹0.00</span>
                            </div>
                            <div class="d-flex justify-content-between mt-1 d-none" id="overtimeChargeRow">
                                <span class="text-danger fw-bold">Overtime Extra Fee:</span>
                                <span class="fw-bold text-danger fs-6" id="detailOvertimeAmount">₹0.00</span>
                            </div>
                        </div>

                        <!-- Action Action CTA Button -->
                        <div id="actionButtonContainer">
                            <!-- Dynamic CTA rendered via JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    let html5QrScanner = null;
    let activeBooking = null;
    let isScanning = false;
    let debounceTimer = null;

    // Start Camera Scanner with continuous auto-detection
    window.startQrScanner = function() {
        const placeholder = document.getElementById('scannerPlaceholder');
        if (placeholder) placeholder.classList.add('d-none');

        document.getElementById('btnStartScanner').classList.add('d-none');
        document.getElementById('btnStopScanner').classList.remove('d-none');

        html5QrScanner = new Html5Qrcode("qr-reader");
        const config = { 
            fps: 15, 
            qrbox: { width: 240, height: 240 },
            aspectRatio: 1.0
        };

        isScanning = true;
        html5QrScanner.start(
            { facingMode: "environment" },
            config,
            (decodedText, decodedResult) => {
                if (!isScanning) return;
                
                // Success QR scanned - Automatic immediate detection!
                console.log("QR Auto-Detected:", decodedText);
                document.getElementById('manualQuery').value = decodedText;
                
                // Visual feedback pulse
                const readerBox = document.getElementById('qr-reader');
                readerBox.style.outline = '4px solid #10b981';
                setTimeout(() => { if (readerBox) readerBox.style.outline = 'none'; }, 800);

                ParkPulse.showToast('success', 'QR Pass Auto-Detected!', 'Gate Scanner');
                
                // Auto verify immediately without any button click
                window.searchBooking(decodedText);
            },
            (errorMessage) => {
                // Ignore per-frame parse attempts
            }
        ).catch(err => {
            console.error("Camera start error:", err);
            ParkPulse.showToast('warning', 'Could not open camera stream. Use manual entry or test pills below.', 'Camera Notice');
            document.getElementById('btnStartScanner').classList.remove('d-none');
            document.getElementById('btnStopScanner').classList.add('d-none');
            const placeholder = document.getElementById('scannerPlaceholder');
            if (placeholder) placeholder.classList.remove('d-none');
        });
    };

    window.stopQrScanner = function() {
        isScanning = false;
        if (html5QrScanner) {
            html5QrScanner.stop().then(() => {
                html5QrScanner.clear();
                document.getElementById('btnStartScanner').classList.remove('d-none');
                document.getElementById('btnStopScanner').classList.add('d-none');
                const placeholder = document.getElementById('scannerPlaceholder');
                if (placeholder) placeholder.classList.remove('d-none');
            }).catch(err => console.error(err));
        }
    };

    // Instant Automatic Detection on typing or pasting into input box
    const manualInput = document.getElementById('manualQuery');
    if (manualInput) {
        manualInput.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            const val = e.target.value.trim();
            if (val.length >= 3) {
                debounceTimer = setTimeout(() => {
                    window.searchBooking(val, true); // Silent mode: match without toast error on partial typing
                }, 250);
            } else if (val.length === 0) {
                // Reset card to idle if cleared
                document.getElementById('idleNotice').classList.remove('d-none');
                document.getElementById('passDetailsView').classList.add('d-none');
                document.getElementById('gateStatusBadge').innerText = 'Awaiting Scan';
                document.getElementById('gateStatusBadge').className = 'badge bg-secondary text-uppercase';
            }
        });

        manualInput.addEventListener('paste', (e) => {
            setTimeout(() => {
                const val = manualInput.value.trim();
                if (val.length >= 3) {
                    window.searchBooking(val, false);
                }
            }, 60);
        });
    }

    // Auto-search if URL contains ref or q query parameter
    const urlParams = new URLSearchParams(window.location.search);
    const initialQuery = urlParams.get('q') || urlParams.get('ref') || '';
    if (initialQuery) {
        if (manualInput) manualInput.value = initialQuery;
        window.searchBooking(initialQuery, false);
    }

    // Lookup Booking from API (with optional silent mode for typing)
    window.searchBooking = async function(query, isSilent = false) {
        if (!query) return;
        query = query.trim();

        try {
            const res = await ParkPulse.fetchJson(`api/scan-lookup.php?q=${encodeURIComponent(query)}`);
            if (res.success && res.data) {
                renderBookingCard(res.data);
            }
        } catch (err) {
            if (!isSilent) {
                ParkPulse.showToast('danger', err.message, 'Lookup Failed');
            }
        }
    };

    function renderBookingCard(b) {
        activeBooking = b;
        document.getElementById('idleNotice').classList.add('d-none');
        document.getElementById('passDetailsView').classList.remove('d-none');

        // Badges
        const statusBadge = document.getElementById('gateStatusBadge');
        statusBadge.innerText = b.status.toUpperCase();
        statusBadge.className = 'badge text-uppercase ' + (
            b.status === 'confirmed' ? 'bg-warning text-dark' : 
            (b.status === 'checked_in' ? 'bg-success' : 
            (b.status === 'completed' ? 'bg-secondary' : 'bg-danger'))
        );

        // Populate fields
        document.getElementById('detailPlate').innerText = b.vehicle_plate;
        document.getElementById('detailMakeModel').innerText = `${b.make_model} (${b.color})`;
        document.getElementById('detailDriverName').innerText = b.driver_name;
        document.getElementById('detailDriverPhone').innerText = b.driver_phone || 'No phone';

        document.getElementById('detailSlotNumber').innerText = b.slot_number;
        document.getElementById('detailFloorZone').innerText = `${b.floor_level} • ${b.zone_name} ${b.is_ev_charging ? '⚡' : ''}`;
        document.getElementById('detailFacilityName').innerText = b.facility_name;
        document.getElementById('detailBookingRef').innerText = b.booking_reference;

        document.getElementById('detailSchedule').innerText = `${new Date(b.start_time).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})} - ${new Date(b.end_time).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}`;
        
        if (b.check_in_time) {
            document.getElementById('checkInRow').classList.remove('d-none');
            document.getElementById('detailActualCheckIn').innerText = new Date(b.check_in_time).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        } else {
            document.getElementById('checkInRow').classList.add('d-none');
        }

        document.getElementById('detailPaidAmount').innerText = `₹${b.total_amount.toFixed(2)}`;

        // Alerts
        const overAlert = document.getElementById('overstayAlert');
        const overtimeRow = document.getElementById('overtimeChargeRow');
        if (b.is_overstay) {
            overAlert.classList.remove('d-none');
            document.getElementById('overstayMsg').innerText = `Vehicle overstayed by ${b.overtime_mins} minutes. Charge ₹${b.overtime_charge.toFixed(2)} at exit.`;
            overtimeRow.classList.remove('d-none');
            document.getElementById('detailOvertimeAmount').innerText = `₹${b.overtime_charge.toFixed(2)}`;
        } else {
            overAlert.classList.add('d-none');
            overtimeRow.classList.add('d-none');
        }

        const earlyAlert = document.getElementById('earlyAlert');
        if (b.is_early) earlyAlert.classList.remove('d-none');
        else earlyAlert.classList.add('d-none');

        // Dynamic Action Button
        const btnContainer = document.getElementById('actionButtonContainer');
        btnContainer.innerHTML = '';

        if (b.status === 'confirmed') {
            btnContainer.innerHTML = `
                <button type="button" class="btn btn-success w-100 py-3 fw-bold shadow-sm fs-5 d-flex align-items-center justify-content-center gap-2" onclick="window.processGateAction('check_in')">
                    <i class="bi bi-box-arrow-in-right fs-4"></i>
                    <span>Authorize Vehicle Entry (Check-In)</span>
                </button>
            `;
        } else if (b.status === 'checked_in') {
            btnContainer.innerHTML = `
                <button type="button" class="btn btn-primary w-100 py-3 fw-bold shadow-sm fs-5 d-flex align-items-center justify-content-center gap-2" onclick="window.processGateAction('check_out')">
                    <i class="bi bi-box-arrow-right fs-4"></i>
                    <span>Process Vehicle Exit (Check-Out)</span>
                </button>
            `;
        } else {
            btnContainer.innerHTML = `
                <div class="alert alert-secondary text-center mb-0 fw-semibold">
                    <i class="bi bi-info-circle me-1"></i> Pass session is already ${b.status}. No gate action required.
                </div>
            `;
        }
    }

    // Process Check-In or Check-Out
    window.processGateAction = async function(action) {
        if (!activeBooking) return;

        try {
            const res = await ParkPulse.fetchJson('api/process-gate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': ParkPulse.getCsrfToken()
                },
                body: JSON.stringify({
                    booking_id: activeBooking.id,
                    action: action,
                    csrf_token: ParkPulse.getCsrfToken()
                })
            });

            if (res.success) {
                ParkPulse.showToast('success', res.message, 'Gate Action Success');
                // Reload booking state
                setTimeout(() => window.searchBooking(activeBooking.booking_reference), 800);
            }
        } catch (err) {
            ParkPulse.showToast('danger', err.message, 'Gate Action Error');
        }
    };
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
