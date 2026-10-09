<?php
/**
 * Interactive Slot Selection, Dynamic Tariff & Race-Condition Safe Reservation
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireLogin();

$userId = Auth::id();
$db = Database::getInstance()->getConnection();

$facilityId = (int)($_GET['facility_id'] ?? 0);
$startTimeReq = Security::sanitizeString($_GET['start_time'] ?? '');
$endTimeReq = Security::sanitizeString($_GET['end_time'] ?? '');

// Fetch facility
$stmt = $db->prepare("SELECT * FROM facilities WHERE id = ? AND status = 'active'");
$stmt->execute([$facilityId]);
$facility = $stmt->fetch();

if (!$facility) {
    Flash::set('danger', 'Selected parking facility is currently unavailable or invalid.');
    header('Location: index.php');
    exit;
}

// Fetch user registered vehicles
$vehStmt = $db->prepare("SELECT * FROM vehicles WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
$vehStmt->execute([$userId]);
$userVehicles = $vehStmt->fetchAll();

// Handle Server-Side Reservation Submission
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $slotId = (int)($_POST['slot_id'] ?? 0);
        $vehicleId = !empty($_POST['vehicle_id']) && $_POST['vehicle_id'] !== 'custom' ? (int)$_POST['vehicle_id'] : null;
        $customPlate = strtoupper(trim(Security::sanitizeString($_POST['custom_plate'] ?? '', 20)));
        $customCategory = Security::sanitizeString($_POST['custom_vehicle_category'] ?? 'car', 30);
        $customIsEv = !empty($_POST['custom_is_ev']);
        $evChargingRequested = !empty($_POST['ev_charging_requested']);
        $startTime = Security::sanitizeString($_POST['start_time'] ?? '');
        $endTime = Security::sanitizeString($_POST['end_time'] ?? '');
        $paymentMethod = Security::sanitizeString($_POST['payment_method'] ?? 'simulation');

        // Determine final plate and category
        $finalPlate = '';
        $finalCategory = 'car';
        $finalIsEv = false;

        if ($vehicleId) {
            $chk = $db->prepare("SELECT plate_number, vehicle_type, is_ev FROM vehicles WHERE id = ? AND user_id = ?");
            $chk->execute([$vehicleId, $userId]);
            $vRow = $chk->fetch();
            if ($vRow) {
                $finalPlate = $vRow['plate_number'];
                $finalCategory = $vRow['vehicle_type'];
                $finalIsEv = (bool)$vRow['is_ev'];
            } else {
                $error = 'Selected vehicle not found in your account.';
            }
        } else {
            if (empty($customPlate) || !Security::validatePlate($customPlate)) {
                $error = 'Please enter a valid vehicle license plate number.';
            } else {
                $finalPlate = $customPlate;
                $finalCategory = $customCategory;
                $finalIsEv = $customIsEv;
            }
        }

        if (!$error) {
            try {
                require_once __DIR__ . '/src/Services/ReservationService.php';
                $resService = new ReservationService($db);
                
                $booking = $resService->createReservation(
                    $userId,
                    $facilityId,
                    $slotId,
                    $finalPlate,
                    $vehicleId,
                    $startTime,
                    $endTime,
                    $paymentMethod,
                    false, // allowPast = false
                    $finalCategory,
                    $finalIsEv,
                    $evChargingRequested
                );

                Flash::set('success', "Stall {$booking['slot_number']} reserved successfully! Your digital QR pass is ready.");
                header('Location: pass.php?ref=' . urlencode($booking['booking_reference']));
                exit;
            } catch (\InvalidArgumentException $ie) {
                $error = $ie->getMessage();
            } catch (\RuntimeException $re) {
                $error = $re->getMessage();
            } catch (\Throwable $e) {
                error_log("Reservation Creation Error: " . $e->getMessage());
                $error = 'An error occurred during booking. ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Reserve Slot — ' . $facility['name'];
require_once __DIR__ . '/includes/header.php';

$defaultStartVal = !empty($startTimeReq) ? $startTimeReq : date('Y-m-d\TH:00', strtotime('+1 hour'));
$defaultEndVal = !empty($endTimeReq) ? $endTimeReq : date('Y-m-d\TH:00', strtotime('+3 hours'));
?>

<div class="container-fluid px-lg-5 px-3 py-4">
    <!-- Breadcrumb & Back Navigation -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i> Back to Facilities Map</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= Security::escape($facility['name']) ?></li>
        </ol>
    </nav>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4 shadow-sm" role="alert">
            <i class="bi bi-exclamation-octagon-fill fs-4 me-2 flex-shrink-0"></i>
            <div><?= Security::escape($error) ?></div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Left Side: Interactive Slot Map Visualizer -->
        <div class="col-lg-8">
            <div class="card card-custom border-0 shadow-sm mb-4">
                <div class="card-header card-header-custom d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h1 class="h5 fw-bold mb-0 text-dark"><?= Security::escape($facility['name']) ?></h1>
                            <span class="badge bg-dark font-monospace text-light"><?= Security::escape($facility['code']) ?></span>
                        </div>
                        <small class="text-secondary d-block">
                            <i class="bi bi-geo-alt-fill text-primary"></i> 
                            <?= Security::escape($facility['address']) ?><?= !empty($facility['locality']) ? ', ' . Security::escape($facility['locality']) : '' ?>, 
                            <?= Security::escape($facility['city']) ?>, <?= Security::escape($facility['state']) ?> — <?= Security::escape($facility['postal_code']) ?>
                        </small>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="<?= Security::buildOsmUrl((float)$facility['latitude'], (float)$facility['longitude']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm" title="View location on OpenStreetMap">
                            <i class="bi bi-map me-1"></i> OSM
                        </a>
                        <a href="<?= Security::buildGoogleMapsUrl((float)$facility['latitude'], (float)$facility['longitude']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm" title="Open directions in Google Maps">
                            <i class="bi bi-geo-alt me-1"></i> Google Maps
                        </a>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1">
                            Base: ₹<?= number_format((float)$facility['hourly_rate'], 2) ?>/hr
                        </span>
                        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">
                            EV Bay: ₹<?= number_format((float)$facility['ev_rate'], 2) ?>/hr
                        </span>
                    </div>
                </div>

                <div class="card-body p-4">
                    <!-- Date & Time Window Selector -->
                    <div class="bg-light p-3 rounded-4 border mb-4">
                        <h6 class="fw-bold text-dark mb-2 d-flex align-items-center gap-2">
                            <i class="bi bi-clock-history text-primary"></i> Select Your Parking Time Frame
                        </h6>
                        <div class="row g-2">
                            <div class="col-md-5">
                                <label class="form-label small text-secondary fw-semibold mb-1">Check-In Time</label>
                                <input type="datetime-local" id="slotStartTime" class="form-control" value="<?= Security::escape($defaultStartVal) ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small text-secondary fw-semibold mb-1">Check-Out Time</label>
                                <input type="datetime-local" id="slotEndTime" class="form-control" value="<?= Security::escape($defaultEndVal) ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-dark-custom w-100 py-2" onclick="window.reloadSlotsMatrix()">
                                    <i class="bi bi-arrow-repeat"></i> Update
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Slot Legend -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-2 px-3 bg-dark text-white rounded-3 mb-3 small">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <span class="d-flex align-items-center gap-1"><span class="badge-status badge-available p-1 px-2">●</span> Available</span>
                            <span class="d-flex align-items-center gap-1"><span class="badge-status badge-occupied p-1 px-2">●</span> Occupied</span>
                            <span class="d-flex align-items-center gap-1"><span class="badge-status badge-reserved p-1 px-2">●</span> Reserved</span>
                            <span class="d-flex align-items-center gap-1"><span class="badge-status badge-maintenance p-1 px-2">●</span> Maintenance</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-info"><i class="bi bi-lightning-charge-fill"></i> EV Charger</span>
                            <span class="text-warning"><i class="bi bi-universal-access"></i> Accessible</span>
                        </div>
                    </div>

                    <!-- Floor Navigation Tabs -->
                    <ul class="nav nav-pills mb-3" id="floorTabs" role="tablist">
                        <!-- Dynamic Tabs populated via JS -->
                    </ul>

                    <!-- Slots Visualizer Layout Container -->
                    <div class="slot-grid-container position-relative">
                        <div id="slotsMatrixLoader" class="text-center py-5">
                            <div class="spinner-border text-info" role="status"></div>
                            <p class="text-light small mt-2">Loading interactive slot layout...</p>
                        </div>

                        <div id="floorTabContent" class="tab-content d-none">
                            <!-- Dynamic Floor & Zone Stalls rendered via JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side: Booking Details & Checkout Card -->
        <div class="col-lg-4">
            <div class="card card-custom border-0 shadow-lg sticky-top" style="top: 80px; z-index: var(--z-sticky-element, 10);">
                <div class="card-header bg-dark text-white p-3 d-flex align-items-center gap-2">
                    <i class="bi bi-receipt text-primary"></i>
                    <span class="fw-bold">Reservation Summary</span>
                </div>
                <div class="card-body p-4">
                    <form id="bookingForm" method="POST" action="reserve.php?facility_id=<?= (int)$facility['id'] ?>">
                        <?= Security::csrfInput() ?>
                        <input type="hidden" name="slot_id" id="formSlotId" value="">
                        <input type="hidden" name="start_time" id="formStartTime" value="<?= Security::escape($defaultStartVal) ?>">
                        <input type="hidden" name="end_time" id="formEndTime" value="<?= Security::escape($defaultEndVal) ?>">

                        <!-- Selected Slot Highlight Banner -->
                        <div id="selectedSlotNotice" class="alert alert-primary d-flex align-items-center justify-content-between p-3 mb-3 border-0 bg-primary bg-opacity-10 text-primary">
                            <div>
                                <small class="d-block text-secondary">Selected Stall</small>
                                <strong class="fs-5" id="displaySlotNumber">No slot chosen</strong>
                                <div class="mt-1">
                                    <span class="badge bg-primary d-none" id="displaySlotTypeBadge">Standard</span>
                                    <span class="badge bg-secondary text-light d-none" id="displaySlotDimBadge">5.0m × 2.4m</span>
                                </div>
                            </div>
                            <i class="bi bi-p-circle-fill fs-2"></i>
                        </div>

                        <!-- Vehicle Selection -->
                        <div class="mb-3">
                            <label class="form-label small text-secondary fw-semibold">Assign Vehicle</label>
                            <?php if (!empty($userVehicles)): ?>
                                <select class="form-select mb-2" id="vehicle_id" name="vehicle_id" onchange="window.handleVehicleSelection(this.value)">
                                    <?php 
                                    $catMeta = Security::getVehicleCategories();
                                    foreach ($userVehicles as $uv): 
                                        $vCat = $uv['vehicle_type'];
                                        $catLabel = $catMeta[$vCat]['short_label'] ?? ucfirst($vCat);
                                    ?>
                                        <option value="<?= (int)$uv['id'] ?>" data-type="<?= Security::escape($vCat) ?>" data-ev="<?= $uv['is_ev'] ? '1' : '0' ?>" <?= $uv['is_default'] ? 'selected' : '' ?>>
                                            <?= Security::escape($uv['plate_number']) ?> (<?= Security::escape($uv['make_model']) ?>) [<?= $catLabel ?><?= $uv['is_ev'] ? ' • ⚡ EV' : '' ?>]
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="custom">+ Use different license plate</option>
                                </select>
                            <?php endif; ?>

                            <div id="customPlateBox" class="<?= !empty($userVehicles) ? 'd-none' : '' ?> p-3 bg-light rounded-3 border mb-2">
                                <div class="mb-2">
                                    <label for="custom_plate" class="form-label small text-secondary fw-semibold">License Plate / Reg Number *</label>
                                    <input type="text" class="form-control font-monospace text-uppercase form-control-sm" id="custom_plate" name="custom_plate" placeholder="e.g. GJ-03-ER-2026">
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-8">
                                        <label for="custom_vehicle_category" class="form-label small text-secondary fw-semibold">Category</label>
                                        <select class="form-select form-select-sm" id="custom_vehicle_category" name="custom_vehicle_category" onchange="window.calculateDynamicTariff()">
                                            <option value="car" selected>Car / SUV</option>
                                            <option value="bike">Two-Wheeler / Bike</option>
                                            <option value="bicycle">Bicycle / Cycle</option>
                                            <option value="auto_rickshaw">Auto-rickshaw</option>
                                            <option value="van">Van / Mini-bus</option>
                                            <option value="truck">Truck / Commercial</option>
                                            <option value="bus">Bus / Heavy Vehicle</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div class="col-4 d-flex align-items-end">
                                        <div class="form-check form-switch mb-1">
                                            <input class="form-check-input" type="checkbox" id="custom_is_ev" name="custom_is_ev" value="1" onchange="window.calculateDynamicTariff()">
                                            <label class="form-check-label small" for="custom_is_ev">Is EV</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- EV Charging Connection Option -->
                        <div class="mb-3 p-3 bg-info bg-opacity-10 border border-info border-opacity-25 rounded-3" id="evChargingSection">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="ev_charging_requested" name="ev_charging_requested" value="1" onchange="window.calculateDynamicTariff()">
                                <label class="form-check-label small fw-semibold text-dark" for="ev_charging_requested">
                                    <i class="bi bi-plug-fill text-info me-1"></i> Request EV Charging Service
                                </label>
                                <small class="text-muted d-block ps-1" style="font-size: 0.75rem;">Requires an EV charging stall. Adds EV charging surcharge per hour.</small>
                            </div>
                        </div>

                        <!-- Duration & Tariff Breakdown Table -->
                        <div class="bg-light p-3 rounded-3 border mb-3">
                            <div class="d-flex justify-content-between small text-secondary mb-1">
                                <span>Duration:</span>
                                <span class="fw-bold text-dark" id="calcDuration">2.0 hrs (2 billable hrs)</span>
                            </div>
                            <div class="d-flex justify-content-between small text-secondary mb-1">
                                <span>Base Hourly Rate:</span>
                                <span class="fw-bold text-dark" id="calcRate">₹<?= number_format((float)$facility['hourly_rate'], 2) ?>/hr</span>
                            </div>
                            <div class="d-flex justify-content-between small text-secondary mb-1" id="calcEvRow">
                                <span>EV Charging Surcharge:</span>
                                <span class="fw-bold text-info" id="calcEvSurcharge">₹0.00</span>
                            </div>
                            <div class="d-flex justify-content-between small text-secondary mb-2">
                                <span>Estimated Base Fare:</span>
                                <span class="fw-bold text-dark" id="calcBaseFare">₹0.00</span>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-dark">Total Amount:</span>
                                <span class="fs-4 fw-extrabold text-primary" id="calcTotalAmount">₹0.00</span>
                            </div>
                        </div>

                        <!-- 2 Payment Options: Razorpay Test Mode OR Offline at Station -->
                        <div class="mb-4">
                            <label class="form-label small text-secondary fw-bold text-uppercase mb-2">Payment Option</label>
                            
                            <!-- Option 1: Razorpay (Test Mode) -->
                            <div class="form-check p-3 bg-light rounded-3 border mb-2 payment-choice-card border-primary" id="choiceRazorpayCard" style="cursor: pointer;" onclick="selectPaymentMode('razorpay')">
                                <input class="form-check-input" type="radio" name="payment_mode_selection" id="payModeRazorpay" value="razorpay" checked>
                                <label class="form-check-label w-100 ps-2" for="payModeRazorpay" style="cursor: pointer;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold text-dark"><i class="bi bi-lightning-charge-fill text-primary me-1"></i> Razorpay (Test Mode)</span>
                                        <span class="badge bg-primary">Online Instant</span>
                                    </div>
                                    <p class="small text-secondary mb-0 mt-1">Official Razorpay Test Gateway (Cards, UPI, NetBanking). Instant advance slot lock & verified QR Entry Pass.</p>
                                </label>
                            </div>

                            <!-- Option 2: Offline / Pay at Station -->
                            <div class="form-check p-3 bg-light rounded-3 border payment-choice-card" id="choiceOfflineCard" style="cursor: pointer;" onclick="selectPaymentMode('offline')">
                                <input class="form-check-input" type="radio" name="payment_mode_selection" id="payModeOffline" value="offline">
                                <label class="form-check-label w-100 ps-2" for="payModeOffline" style="cursor: pointer;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold text-dark"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Pay Offline at Station</span>
                                        <span class="badge bg-secondary">Walk-In / Drive-In</span>
                                    </div>
                                    <p class="small text-secondary mb-0 mt-1">No advance online reservation. Drive directly to facility entrance and pay gate attendant in cash/UPI on arrival.</p>
                                </label>
                            </div>
                        </div>

                        <!-- Razorpay Online Action Button -->
                        <div id="razorpayActionContainer">
                            <button type="button" id="btnSubmitReservation" class="btn btn-primary-custom w-100 py-3 fw-bold shadow-sm fs-5" onclick="launchRazorpayCheckout()" disabled>
                                <i class="bi bi-credit-card-2-front-fill me-2"></i> Pay with Razorpay (Test Mode)
                            </button>
                        </div>

                        <!-- Offline Drive-In Action Container -->
                        <div id="offlineActionContainer" class="d-none">
                            <a href="<?= !empty($facility['google_maps_url']) ? Security::escape($facility['google_maps_url']) : ('https://www.google.com/maps/search/?api=1&query=' . urlencode($facility['latitude'] . ',' . $facility['longitude'])) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-danger w-100 py-3 fw-bold shadow-sm fs-6 mb-2">
                                <i class="bi bi-google me-2"></i> Get Google Maps Directions to Station
                            </a>
                            <div class="alert alert-info py-2 px-3 small mb-0 rounded-3">
                                <i class="bi bi-info-circle-fill me-1"></i> <strong>Drive-In Notice:</strong> Offline entry is subject to live on-premise bay availability upon arrival. No advance online slot lock is created.
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Load Razorpay Official Web Checkout SDK -->
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>

<!-- Modal: Comprehensive Razorpay Test Mode Gateway Dialog -->
<div class="modal fade" id="dynamicPaymentModal" tabindex="-1" aria-labelledby="dynamicPaymentModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-2xl" style="border-radius: 18px; overflow: hidden;">
            <!-- Modal Header with Razorpay Branding -->
            <div class="modal-header bg-dark text-white p-3 border-0" style="background: linear-gradient(135deg, #0c2340 0%, #111827 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-2 bg-primary bg-opacity-25 text-primary rounded-3 border border-primary border-opacity-25">
                        <i class="bi bi-shield-check fs-4 text-info"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="modal-title h6 fw-bold mb-0 text-white" id="dynamicPaymentModalLabel">Razorpay Test Mode Gateway</h5>
                            <span class="badge bg-warning text-dark fw-bold font-monospace" style="font-size: 0.68rem;">TEST ENVIRONMENT</span>
                        </div>
                        <small class="text-secondary" style="font-size: 0.72rem;">256-Bit SSL Encrypted Sandbox · ParkPulse 2026 India</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Order Summary Strip -->
            <div class="bg-light p-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <span class="text-secondary small d-block">Reserved Stall:</span>
                    <strong class="text-dark" id="modalStallSummary">Stall -- · Ground Floor</strong>
                </div>
                <div>
                    <span class="text-secondary small d-block">Razorpay Order ID:</span>
                    <code class="font-monospace text-primary fw-bold small" id="modalOrderIdText">order_...</code>
                </div>
                <div class="text-md-end">
                    <span class="text-secondary small d-block">Payable Amount:</span>
                    <span class="fs-4 fw-extrabold text-primary" id="modalPayableAmount">₹0.00</span>
                </div>
            </div>

            <!-- Modal Body with Test Methods & Simulated Flow -->
            <div class="modal-body p-4" id="razorpayModalMainBody">
                <!-- Gateway Status Alert -->
                <div class="alert alert-info py-2 px-3 small mb-3 rounded-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <i class="bi bi-info-circle-fill me-1"></i>
                        Key: <strong class="font-monospace" id="razorpayKeyBadge">rzp_test_...</strong> · Test orders auto-approved or simulated.
                    </div>
                    <span class="badge bg-light text-dark border">Session: <span id="qrCountdown" class="fw-bold text-danger">04:59</span></span>
                </div>

                <!-- Interactive Tabs: Cards, UPI, Web SDK -->
                <ul class="nav nav-pills nav-fill mb-3 p-1 bg-light rounded-3 border" id="razorpayMethodTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold py-2 small" id="tab-rzp-card" data-bs-toggle="pill" data-bs-target="#pane-rzp-card" type="button" role="tab">
                            <i class="bi bi-credit-card-2-front-fill me-1"></i> Test Cards
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold py-2 small" id="tab-rzp-upi" data-bs-toggle="pill" data-bs-target="#pane-rzp-upi" type="button" role="tab">
                            <i class="bi bi-qr-code-scan me-1"></i> Test UPI
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold py-2 small" id="tab-rzp-sdk" data-bs-toggle="pill" data-bs-target="#pane-rzp-sdk" type="button" role="tab">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Razorpay Popup
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="razorpayMethodContent">
                    <!-- Tab 1: Test Cards -->
                    <div class="tab-pane fade show active" id="pane-rzp-card" role="tabpanel">
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label small fw-bold text-dark mb-0">Card Details (Test Mode)</label>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 small" onclick="fillTestCard('visa')">Visa</button>
                                    <button type="button" class="btn btn-outline-success btn-sm py-0 px-2 small" onclick="fillTestCard('rupay')">RuPay</button>
                                    <button type="button" class="btn btn-outline-warning btn-sm py-0 px-2 small" onclick="fillTestCard('mastercard')">MasterCard</button>
                                </div>
                            </div>
                            <div class="mb-2">
                                <input type="text" id="testCardNumber" class="form-control font-monospace" placeholder="4111 1111 1111 1111" value="4111 1111 1111 1111" maxlength="19">
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <input type="text" id="testCardExpiry" class="form-control font-monospace" placeholder="MM/YY" value="12/30" maxlength="5">
                                </div>
                                <div class="col-6">
                                    <input type="password" id="testCardCvv" class="form-control font-monospace" placeholder="CVV" value="123" maxlength="4">
                                </div>
                            </div>
                            <div class="mb-3">
                                <input type="text" id="testCardName" class="form-control" placeholder="Cardholder Name" value="Driver">
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary py-2 fw-bold" onclick="showOtpScreen('card')">
                                    <i class="bi bi-shield-check me-1"></i> Proceed to Bank OTP Verification &rarr;
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 2: Test UPI -->
                    <div class="tab-pane fade" id="pane-rzp-upi" role="tabpanel">
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <label class="form-label small fw-bold text-dark">Enter Test UPI VPA / Handle</label>
                            <div class="input-group mb-2">
                                <span class="input-group-text bg-white"><i class="bi bi-at"></i></span>
                                <input type="text" id="testUpiVpa" class="form-control font-monospace" value="success@razorpay" placeholder="success@razorpay">
                            </div>
                            <div class="d-flex gap-2 mb-3">
                                <button type="button" class="btn btn-outline-success btn-sm flex-fill fw-bold" onclick="document.getElementById('testUpiVpa').value = 'success@razorpay'">
                                    <i class="bi bi-check-circle me-1"></i> success@razorpay (Pass)
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm flex-fill fw-bold" onclick="document.getElementById('testUpiVpa').value = 'failure@razorpay'">
                                    <i class="bi bi-x-circle me-1"></i> failure@razorpay (Decline)
                                </button>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary py-2 fw-bold" onclick="processUpiTestPayment()">
                                    <i class="bi bi-send-check me-1"></i> Verify & Authorize UPI Transaction
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 3: Official Razorpay Web SDK Launcher -->
                    <div class="tab-pane fade" id="pane-rzp-sdk" role="tabpanel">
                        <div class="p-3 bg-light rounded-3 border mb-3 text-center">
                            <p class="small text-secondary mb-3">Launch Razorpay's official checkout popup window using your active test key.</p>
                            <button type="button" class="btn btn-primary py-3 px-4 fw-bold shadow-sm" onclick="triggerNativeRazorpayPopup()">
                                <i class="bi bi-box-arrow-up-right me-2"></i> Launch Official Razorpay Web Checkout
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Simulation Action Fast Bar (Success vs Decline) -->
                <div class="border-top pt-3 mt-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-bold text-dark"><i class="bi bi-sliders me-1 text-primary"></i> Direct Flow Simulator:</span>
                        <span class="badge bg-secondary small">Developer / Judge Mode</span>
                    </div>
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <button type="button" class="btn btn-success w-100 py-2 fw-bold shadow-sm" onclick="finalizePayment('razorpay')">
                                <i class="bi bi-check-circle-fill me-1"></i> Simulate Payment Success
                            </button>
                        </div>
                        <div class="col-sm-6">
                            <button type="button" class="btn btn-outline-danger w-100 py-2 fw-bold shadow-sm" onclick="simulateRazorpayFailure('Card declined: Insufficient balance or OTP verification timeout by bank.')">
                                <i class="bi bi-x-circle me-1"></i> Simulate Payment Failure
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Simulated Bank OTP Verification Sub-Screen -->
            <div class="modal-body p-4 d-none" id="razorpayOtpScreen">
                <div class="text-center mb-3">
                    <div class="p-3 bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex mb-2">
                        <i class="bi bi-shield-lock-fill fs-2"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Bank OTP Verification</h5>
                    <p class="text-secondary small mb-0">Enter the 6-digit one-time password sent by your bank to authenticate payment of <strong class="text-primary" id="otpAmountText">₹0.00</strong>.</p>
                </div>

                <div class="p-3 bg-light rounded-3 border mb-3">
                    <div class="mb-3 text-center">
                        <label class="form-label small fw-bold text-muted">Test Environment OTP</label>
                        <input type="text" id="testOtpInput" class="form-control form-control-lg text-center fw-extrabold font-monospace letter-spacing-2" value="123456" maxlength="6" style="letter-spacing: 0.3em; font-size: 1.5rem;">
                        <small class="text-muted d-block mt-1">Default test OTP: <code>123456</code> (or <code>333333</code>)</small>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-success py-2 fw-bold" onclick="submitTestOtp()">
                            <i class="bi bi-check-circle-fill me-1"></i> Verify OTP & Authorize Payment
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="simulateRazorpayFailure('Incorrect OTP entered 3 times. Bank rejected transaction.')">
                            <i class="bi bi-x-circle me-1"></i> Simulate Incorrect OTP (Decline)
                        </button>
                        <button type="button" class="btn btn-link text-secondary btn-sm" onclick="hideOtpScreen()">
                            &larr; Back to Payment Methods
                        </button>
                    </div>
                </div>
            </div>

            <!-- Simulated Payment Failure Sub-Screen -->
            <div class="modal-body p-4 d-none" id="razorpayFailureScreen">
                <div class="text-center mb-3">
                    <div class="p-3 bg-danger bg-opacity-10 text-danger rounded-circle d-inline-flex mb-2">
                        <i class="bi bi-exclamation-triangle-fill fs-2"></i>
                    </div>
                    <h5 class="fw-bold text-danger mb-1">Payment Declined / Failed</h5>
                    <p class="text-secondary small mb-0" id="failureReasonText">Bank server declined transaction: Insufficient funds or invalid credentials.</p>
                </div>

                <div class="alert alert-warning py-2 px-3 small rounded-3 mb-3">
                    <i class="bi bi-info-circle-fill me-1"></i> <strong>Slot Preserved:</strong> Your selected stall is still held for you. You can retry with another test card or payment method.
                </div>

                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-primary py-2 fw-bold" onclick="resetToPaymentMethods()">
                        <i class="bi bi-arrow-clockwise me-1"></i> Try Again with Razorpay (Test Mode)
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                        Close & Change Reservation Details
                    </button>
                </div>
            </div>

            <!-- Modal Loading Overlay -->
            <div id="paymentLoadingOverlay" class="position-absolute top-0 start-0 w-100 h-100 bg-white bg-opacity-95 d-flex flex-column align-items-center justify-content-center d-none" style="z-index: 1060;">
                <div class="spinner-border text-primary mb-3" style="width: 3.5rem; height: 3.5rem;" role="status"></div>
                <h5 class="fw-bold text-dark mb-1" id="paymentLoadingTitle">Authorizing Razorpay Transaction...</h5>
                <p class="text-secondary small mb-0" id="paymentLoadingSubtitle">Verifying HMAC-SHA256 signature and locking reserved parking bay...</p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    let facilityData = <?= json_encode($facility) ?>;
    let selectedSlot = null;
    let loadedFloors = [];

    window.handleVehicleSelection = function(val) {
        const box = document.getElementById('customPlateBox');
        if (val === 'custom') {
            box.classList.remove('d-none');
            document.getElementById('custom_plate').focus();
        } else {
            box.classList.add('d-none');
        }
        calculateDynamicTariff();
    };

    window.reloadSlotsMatrix = async function() {
        const startTime = document.getElementById('slotStartTime').value;
        const endTime = document.getElementById('slotEndTime').value;

        if (new Date(startTime) >= new Date(endTime)) {
            ParkPulse.showToast('danger', 'Check-out time must be later than check-in time.', 'Time Error');
            return;
        }

        document.getElementById('formStartTime').value = startTime;
        document.getElementById('formEndTime').value = endTime;

        const loader = document.getElementById('slotsMatrixLoader');
        const content = document.getElementById('floorTabContent');
        const tabsNav = document.getElementById('floorTabs');

        loader.classList.remove('d-none');
        content.classList.add('d-none');
        tabsNav.innerHTML = '';
        content.innerHTML = '';

        try {
            const url = `api/slots.php?facility_id=${facilityData.id}&start_time=${encodeURIComponent(startTime)}&end_time=${encodeURIComponent(endTime)}`;
            const res = await ParkPulse.fetchJson(url);

            loadedFloors = res.floors || [];
            loader.classList.add('d-none');
            content.classList.remove('d-none');

            renderFloorTabs(loadedFloors);
            calculateDynamicTariff();
        } catch (err) {
            loader.classList.add('d-none');
            ParkPulse.showToast('danger', 'Failed to load parking slots: ' + err.message, 'Error');
        }
    };

    function renderFloorTabs(floors) {
        const tabsNav = document.getElementById('floorTabs');
        const tabContent = document.getElementById('floorTabContent');

        tabsNav.innerHTML = '';
        tabContent.innerHTML = '';

        floors.forEach((fl, idx) => {
            const tabId = `floor-tab-${idx}`;
            const paneId = `floor-pane-${idx}`;
            const isActive = idx === 0;

            // Nav Tab Button
            const navItem = document.createElement('li');
            navItem.className = 'nav-item';
            navItem.innerHTML = `
                <button class="nav-link ${isActive ? 'active' : ''} fw-bold" id="${tabId}" data-bs-toggle="pill" data-bs-target="#${paneId}" type="button" role="tab">
                    <i class="bi bi-layers-half me-1"></i> ${fl.floor_name}
                </button>
            `;
            tabsNav.appendChild(navItem);

            // Tab Content Pane
            const pane = document.createElement('div');
            pane.className = `tab-pane fade ${isActive ? 'show active' : ''}`;
            pane.id = paneId;

            let zonesHtml = '';
            fl.zones.forEach(zn => {
                let slotsHtml = '';
                zn.slots.forEach(s => {
                    const isAvail = s.status === 'available';
                    let typeIcon = 'bi-car-front';
                    if (s.slot_type === 'bike') typeIcon = 'bi-bicycle';
                    else if (s.slot_type === 'bicycle') typeIcon = 'bi-bicycle';
                    else if (s.slot_type === 'auto_rickshaw') typeIcon = 'bi-truck-flatbed';
                    else if (s.slot_type === 'van') typeIcon = 'bi-bus-front-fill';
                    else if (s.slot_type === 'truck') typeIcon = 'bi-truck';
                    else if (s.slot_type === 'bus') typeIcon = 'bi-bus-front';

                    if (s.is_ev_charging) typeIcon = 'bi-lightning-charge-fill';
                    if (s.is_accessible) typeIcon = 'bi-universal-access';

                    const dimensions = s.slot_dimensions || '5.0m × 2.4m';
                    const chargerInfo = s.is_ev_charging ? (s.ev_charger_type || 'EV Charger') : '';
                    const ariaLabel = `Slot ${s.slot_number}, Status: ${s.status}, Type: ${s.slot_type}, Dim: ${dimensions}${s.is_ev_charging ? ', ' + chargerInfo : ''}`;
                    const isSelectable = s.status === 'available';

                    slotsHtml += `
                        <div class="slot-box status-${s.status} ${selectedSlot?.id === s.id ? 'selected' : ''}" 
                             data-slot-id="${s.id}" 
                             data-slot-number="${s.slot_number}" 
                             data-slot-type="${s.slot_type}" 
                             data-slot-dimensions="${dimensions}"
                             data-is-ev="${s.is_ev_charging ? '1' : '0'}"
                             data-charger-type="${chargerInfo}"
                             data-status="${s.status}"
                             role="button"
                             tabindex="${isSelectable ? '0' : '-1'}"
                             aria-label="${ariaLabel}"
                             aria-pressed="${selectedSlot?.id === s.id ? 'true' : 'false'}"
                             title="${ariaLabel}"
                             onclick="window.selectSlot(this)"
                             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault(); window.selectSlot(this);}">
                            <div class="slot-number">${s.slot_number}</div>
                            <div class="slot-icon"><i class="bi ${typeIcon}"></i></div>
                            <div class="slot-tag bg-dark bg-opacity-50 text-white">${s.slot_type.toUpperCase()}</div>
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

    window.selectSlot = function(el) {
        const status = el.dataset.status;
        if (status !== 'available') {
            ParkPulse.showToast('warning', `Stall ${el.dataset.slotNumber} is currently ${status} for this timeframe.`, 'Unavailable');
            return;
        }

        // Deselect previous
        document.querySelectorAll('.slot-box.selected').forEach(box => box.classList.remove('selected'));
        el.classList.add('selected');

        selectedSlot = {
            id: parseInt(el.dataset.slotId),
            number: el.dataset.slotNumber,
            type: el.dataset.slotType,
            dimensions: el.dataset.slotDimensions,
            isEv: el.dataset.isEv === '1',
            chargerType: el.dataset.chargerType
        };

        // Update form & sidebar notice
        document.getElementById('formSlotId').value = selectedSlot.id;
        document.getElementById('displaySlotNumber').innerText = `Stall ${selectedSlot.number}`;
        
        const typeBadge = document.getElementById('displaySlotTypeBadge');
        typeBadge.classList.remove('d-none');
        typeBadge.innerText = selectedSlot.isEv ? `⚡ ${selectedSlot.chargerType || 'EV Charging Stall'}` : (selectedSlot.type.toUpperCase() + ' Stall');
        
        const dimBadge = document.getElementById('displaySlotDimBadge');
        dimBadge.classList.remove('d-none');
        dimBadge.innerText = selectedSlot.dimensions;

        document.getElementById('btnSubmitReservation').removeAttribute('disabled');
        calculateDynamicTariff();
    };

    window.calculateDynamicTariff = function() {
        const startTime = new Date(document.getElementById('slotStartTime').value);
        const endTime = new Date(document.getElementById('slotEndTime').value);

        if (isNaN(startTime.getTime()) || isNaN(endTime.getTime()) || endTime <= startTime) {
            return;
        }

        const durationMs = endTime - startTime;
        const durationHours = Math.max(0.5, (durationMs / (1000 * 60 * 60)));
        const billableHours = Math.ceil(durationHours);

        // Determine vehicle category
        let vType = 'car';
        let isEv = false;
        const vehSelect = document.getElementById('vehicle_id');
        if (vehSelect && vehSelect.value !== 'custom') {
            const opt = vehSelect.options[vehSelect.selectedIndex];
            vType = opt?.dataset?.type || 'car';
            isEv = opt?.dataset?.ev === '1';
        } else {
            vType = document.getElementById('custom_vehicle_category')?.value || 'car';
            isEv = document.getElementById('custom_is_ev')?.checked || false;
        }

        const evChargingRequested = document.getElementById('ev_charging_requested')?.checked || false;

        const categoryMultipliers = {
            bicycle: 0.3,
            bike: 0.4,
            auto_rickshaw: 0.6,
            car: 1.0,
            van: 1.4,
            truck: 2.2,
            bus: 2.4,
            other: 1.0
        };

        const facilityBaseRate = parseFloat(facilityData.hourly_rate);
        const multiplier = categoryMultipliers[vType] || 1.0;
        let baseRate = facilityBaseRate * multiplier;

        let evSurcharge = 0.0;
        if (evChargingRequested || (selectedSlot && selectedSlot.isEv)) {
            const evDiff = Math.max(0, parseFloat(facilityData.ev_rate) - facilityBaseRate);
            evSurcharge = evDiff > 0 ? evDiff : 25.00;
        }

        const totalFare = (billableHours * baseRate) + (billableHours * evSurcharge);

        document.getElementById('calcDuration').innerText = `${durationHours.toFixed(1)} hrs (${billableHours} billable hrs)`;
        document.getElementById('calcRate').innerText = `₹${baseRate.toFixed(2)}/hr`;
        document.getElementById('calcEvSurcharge').innerText = evSurcharge > 0 ? `+₹${evSurcharge.toFixed(2)}/hr` : '₹0.00';
        document.getElementById('calcBaseFare').innerText = `₹${(billableHours * baseRate).toFixed(2)}`;
        document.getElementById('calcTotalAmount').innerText = `₹${totalFare.toFixed(2)}`;
    };

    // Payment Selection & Razorpay Controller
    let currentOrder = null;
    let qrTimerInterval = null;
    let paymentModalInstance = null;
    let currentPaymentMode = 'razorpay';

    window.selectPaymentMode = function(mode) {
        currentPaymentMode = mode;
        const razorpayCard = document.getElementById('choiceRazorpayCard');
        const offlineCard = document.getElementById('choiceOfflineCard');
        const razorpayAction = document.getElementById('razorpayActionContainer');
        const offlineAction = document.getElementById('offlineActionContainer');
        const radioRazorpay = document.getElementById('payModeRazorpay');
        const radioOffline = document.getElementById('payModeOffline');

        if (mode === 'razorpay') {
            if (radioRazorpay) radioRazorpay.checked = true;
            razorpayCard?.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
            offlineCard?.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
            razorpayAction?.classList.remove('d-none');
            offlineAction?.classList.add('d-none');
        } else {
            if (radioOffline) radioOffline.checked = true;
            offlineCard?.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
            razorpayCard?.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
            offlineAction?.classList.remove('d-none');
            razorpayAction?.classList.add('d-none');
        }
    };

    window.launchRazorpayCheckout = async function() {
        if (!selectedSlot) {
            ParkPulse.showToast('warning', 'Please click and select an available parking stall on the matrix first.', 'Slot Required');
            return;
        }

        const startTime = document.getElementById('slotStartTime').value;
        const endTime = document.getElementById('slotEndTime').value;
        const vehSelect = document.getElementById('vehicle_id');
        const vehicleId = vehSelect ? vehSelect.value : 'custom';
        const customPlate = document.getElementById('custom_plate')?.value || '';
        const customCategory = document.getElementById('custom_vehicle_category')?.value || 'car';
        const customIsEv = document.getElementById('custom_is_ev')?.checked || false;
        const evChargingRequested = document.getElementById('ev_charging_requested')?.checked || false;

        const btn = document.getElementById('btnSubmitReservation');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Generating Razorpay Order...';

        try {
            const res = await ParkPulse.fetchJson('api/create-payment-order.php', {
                method: 'POST',
                body: JSON.stringify({
                    facility_id: facilityData.id,
                    slot_id: selectedSlot.id,
                    vehicle_id: vehicleId,
                    custom_plate: customPlate,
                    custom_vehicle_category: customCategory,
                    custom_is_ev: customIsEv,
                    ev_charging_requested: evChargingRequested,
                    start_time: startTime,
                    end_time: endTime
                })
            });

            currentOrder = res;
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-credit-card-2-front-fill me-2"></i> Pay with Razorpay (Test Mode)';

            // Populate Modal Fields
            document.getElementById('modalStallSummary').innerText = `Stall ${res.slot_number} · ${res.floor_zone} (${res.facility})`;
            document.getElementById('modalOrderIdText').innerText = res.razorpay_order_id || res.order_id;
            document.getElementById('modalPayableAmount').innerText = res.amount_formatted;
            
            const rzpBadge = document.getElementById('razorpayKeyBadge');
            if (rzpBadge) rzpBadge.innerText = res.razorpay_key_id || 'rzp_test_...';

            // Start 5-Minute Expiry Timer
            startQrExpiryCountdown(res.expires_in_seconds || 300);

            // Open Modal
            if (!paymentModalInstance) {
                paymentModalInstance = new bootstrap.Modal(document.getElementById('dynamicPaymentModal'));
            }
            paymentModalInstance.show();

        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-credit-card-2-front-fill me-2"></i> Pay with Razorpay (Test Mode)';
            ParkPulse.showToast('danger', err.message, 'Order Creation Failed');
        }
    };

    function startQrExpiryCountdown(durationSeconds) {
        clearInterval(qrTimerInterval);
        let remaining = durationSeconds;
        const countdownEl = document.getElementById('qrCountdown');

        qrTimerInterval = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(qrTimerInterval);
                if (countdownEl) countdownEl.innerText = '00:00 (Expired)';
                ParkPulse.showToast('warning', 'Payment session expired. Please regenerate your booking order.', 'Order Expired');
                return;
            }
            const mins = String(Math.floor(remaining / 60)).padStart(2, '0');
            const secs = String(remaining % 60).padStart(2, '0');
            if (countdownEl) countdownEl.innerText = `${mins}:${secs}`;
        }, 1000);
    }

    // Razorpay Test Mode Flow Functions
    let selectedTestBankName = 'HDFC Bank';

    window.fillTestCard = function(brand) {
        const num = document.getElementById('testCardNumber');
        const exp = document.getElementById('testCardExpiry');
        const cvv = document.getElementById('testCardCvv');
        const name = document.getElementById('testCardName');

        if (brand === 'visa') {
            num.value = '4111 1111 1111 1111';
            exp.value = '12/30';
            cvv.value = '123';
            name.value = 'Test Visa Driver';
        } else if (brand === 'rupay') {
            num.value = '6070 1234 5678 9010';
            exp.value = '11/29';
            cvv.value = '123';
            name.value = 'Test RuPay Driver';
        } else if (brand === 'mastercard') {
            num.value = '5123 4567 8901 2345';
            exp.value = '10/31';
            cvv.value = '123';
            name.value = 'Test Mastercard Driver';
        }
    };

    window.showOtpScreen = function(source) {
        document.getElementById('razorpayModalMainBody').classList.add('d-none');
        document.getElementById('razorpayFailureScreen').classList.add('d-none');
        document.getElementById('razorpayOtpScreen').classList.remove('d-none');
        if (currentOrder) {
            document.getElementById('otpAmountText').innerText = currentOrder.amount_formatted;
        }
    };

    window.hideOtpScreen = function() {
        document.getElementById('razorpayOtpScreen').classList.add('d-none');
        document.getElementById('razorpayFailureScreen').classList.add('d-none');
        document.getElementById('razorpayModalMainBody').classList.remove('d-none');
    };

    window.submitTestOtp = function() {
        const otp = document.getElementById('testOtpInput').value.trim();
        if (!otp || otp.length < 4) {
            ParkPulse.showToast('warning', 'Please enter a valid 6-digit test OTP.', 'Invalid OTP');
            return;
        }
        if (otp === '000000') {
            simulateRazorpayFailure('Bank rejected OTP: Invalid credentials entered.');
            return;
        }
        finalizePayment('razorpay');
    };

    window.processUpiTestPayment = function() {
        const vpa = document.getElementById('testUpiVpa').value.trim().toLowerCase();
        if (!vpa) {
            ParkPulse.showToast('warning', 'Please enter a UPI VPA ID.', 'VPA Required');
            return;
        }
        if (vpa.includes('fail') || vpa.includes('decline')) {
            simulateRazorpayFailure(`UPI Payment Declined: PSP Bank reported 'TRANSACTION_NOT_PERMITTED' for VPA ${vpa}.`);
            return;
        }
        finalizePayment('razorpay');
    };

    window.selectTestBank = function(btn, bankName) {
        document.querySelectorAll('.bank-select-btn').forEach(b => b.classList.remove('btn-primary', 'text-white', 'active'));
        btn.classList.add('btn-primary', 'text-white', 'active');
        selectedTestBankName = bankName;
        document.getElementById('btnTestNetBankingSubmit').innerHTML = `<i class="bi bi-box-arrow-up-right me-1"></i> Authorize via ${bankName} Portal`;
    };

    window.showNetBankingScreen = function() {
        finalizePayment('razorpay');
    };

    window.simulateRazorpayFailure = function(reason = 'Payment declined by issuing bank (Simulated 402).') {
        document.getElementById('razorpayModalMainBody').classList.add('d-none');
        document.getElementById('razorpayOtpScreen').classList.add('d-none');
        document.getElementById('paymentLoadingOverlay').classList.add('d-none');
        document.getElementById('razorpayFailureScreen').classList.remove('d-none');
        document.getElementById('failureReasonText').innerText = reason;
        ParkPulse.showToast('danger', reason, 'Razorpay Payment Declined');
    };

    window.resetToPaymentMethods = function() {
        document.getElementById('razorpayFailureScreen').classList.add('d-none');
        document.getElementById('razorpayOtpScreen').classList.add('d-none');
        document.getElementById('razorpayModalMainBody').classList.remove('d-none');
    };

    window.triggerNativeRazorpayPopup = function() {
        if (!currentOrder) {
            ParkPulse.showToast('warning', 'No active payment order session found.', 'Order Required');
            return;
        }

        // Check if Razorpay Web SDK is loaded
        if (typeof Razorpay !== 'undefined') {
            try {
                const options = {
                    "key": currentOrder.razorpay_key_id || 'rzp_test_parkpulse2026demo',
                    "amount": currentOrder.amount_paise,
                    "currency": "INR",
                    "name": "ParkPulse Smart Parking",
                    "description": `Reservation for Stall ${currentOrder.slot_number} (${currentOrder.facility})`,
                    "image": "https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/1f17f.png",
                    "order_id": currentOrder.razorpay_order_id,
                    "handler": function (response) {
                        finalizePayment('razorpay', response.razorpay_payment_id, response.razorpay_signature);
                    },
                    "prefill": currentOrder.customer_prefill || {
                        "name": "Driver",
                        "email": "driver@parkpulse.com",
                        "contact": "9825012345"
                    },
                    "notes": {
                        "facility": currentOrder.facility,
                        "slot": currentOrder.slot_number,
                        "system": "ParkPulse 2026 India"
                    },
                    "theme": {
                        "color": "#0d6efd"
                    },
                    "modal": {
                        "ondismiss": function() {
                            ParkPulse.showToast('info', 'Razorpay test checkout window dismissed.', 'Notice');
                        }
                    }
                };
                const rzp = new Razorpay(options);
                rzp.on('payment.failed', function (response) {
                    simulateRazorpayFailure(response.error?.description || 'Payment declined by Razorpay test gateway.');
                });
                rzp.open();
                return;
            } catch (sdkErr) {
                console.warn('Razorpay SDK init fallback:', sdkErr);
            }
        }

        // Direct test simulation fallback if external CDN blocked
        const fakeRzpPaymentId = 'pay_rzp_test_' + Math.random().toString(36).substring(2, 10);
        const fakeRzpSignature = 'SIG_RZP_TEST_' + Math.random().toString(36).substring(2, 14);
        finalizePayment('razorpay', fakeRzpPaymentId, fakeRzpSignature);
    };

    window.finalizePayment = async function(paymentChannel, customPaymentId = null, customSignature = null) {
        if (!currentOrder) {
            ParkPulse.showToast('danger', 'No active payment session found. Please try again.', 'Error');
            return;
        }

        const overlay = document.getElementById('paymentLoadingOverlay');
        const overlayTitle = document.getElementById('paymentLoadingTitle');
        const overlaySub = document.getElementById('paymentLoadingSubtitle');

        overlay.classList.remove('d-none');
        overlayTitle.innerText = `Authorizing ₹${currentOrder.amount.toFixed(2)} via ${paymentChannel.toUpperCase()}...`;
        overlaySub.innerText = 'Verifying cryptographic signature and locking reserved parking bay...';

        const startTime = document.getElementById('slotStartTime').value;
        const endTime = document.getElementById('slotEndTime').value;
        const vehSelect = document.getElementById('vehicle_id');
        const vehicleId = vehSelect ? vehSelect.value : 'custom';
        const customPlate = document.getElementById('custom_plate')?.value || '';
        const customCategory = document.getElementById('custom_vehicle_category')?.value || 'car';
        const customIsEv = document.getElementById('custom_is_ev')?.checked || false;
        const evChargingRequested = document.getElementById('ev_charging_requested')?.checked || false;

        const effectivePaymentId = customPaymentId || ('PAY_' + paymentChannel.toUpperCase() + '_' + Math.random().toString(36).substring(2, 10).toUpperCase());
        const effectiveSignature = customSignature || currentOrder.order_signature;

        try {
            const res = await ParkPulse.fetchJson('api/verify-payment.php', {
                method: 'POST',
                body: JSON.stringify({
                    order_id: currentOrder.order_id,
                    payment_id: effectivePaymentId,
                    signature: effectiveSignature,
                    payment_method: paymentChannel,
                    facility_id: facilityData.id,
                    slot_id: selectedSlot.id,
                    vehicle_id: vehicleId,
                    custom_plate: customPlate,
                    custom_vehicle_category: customCategory,
                    custom_is_ev: customIsEv,
                    ev_charging_requested: evChargingRequested,
                    start_time: startTime,
                    end_time: endTime
                })
            });

            if (res.success) {
                overlayTitle.innerText = 'Payment Settled Successfully!';
                overlaySub.innerText = `Confirmed Stall ${res.slot_number} · Redirecting to Digital Pass...`;
                ParkPulse.showToast('success', res.message, 'Payment Verified');
                clearInterval(qrTimerInterval);

                setTimeout(() => {
                    window.location.href = res.redirect_url;
                }, 1200);
            }
        } catch (err) {
            overlay.classList.add('d-none');
            simulateRazorpayFailure(err.message || 'Payment authorization failed.');
        }
    };

    // Initialize matrix
    window.reloadSlotsMatrix();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
