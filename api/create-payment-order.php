<?php
/**
 * Dynamic Payment Order Creation API
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/PricingService.php';
require_once __DIR__ . '/../src/Services/PaymentGatewayService.php';

if (!Auth::check()) {
    Security::jsonResponse(['success' => false, 'message' => 'Please sign in to proceed with booking payment.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'message' => 'Invalid HTTP request method.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!Security::validateCsrfToken($csrfToken)) {
    Security::jsonResponse(['success' => false, 'message' => 'Security token invalid or expired. Please refresh the page.'], 403);
}

$userId = Auth::id();
$facilityId = (int)($input['facility_id'] ?? 0);
$slotId = (int)($input['slot_id'] ?? 0);
$vehicleId = !empty($input['vehicle_id']) && $input['vehicle_id'] !== 'custom' ? (int)$input['vehicle_id'] : null;
$customPlate = strtoupper(trim(Security::sanitizeString($input['custom_plate'] ?? '', 20)));
$customCategory = Security::sanitizeString($input['custom_vehicle_category'] ?? 'car', 30);
$customIsEv = !empty($input['custom_is_ev']);
$evChargingRequested = !empty($input['ev_charging_requested']);
$startTime = Security::sanitizeString($input['start_time'] ?? '');
$endTime = Security::sanitizeString($input['end_time'] ?? '');

if ($facilityId <= 0 || $slotId <= 0 || empty($startTime) || empty($endTime)) {
    Security::jsonResponse(['success' => false, 'message' => 'Missing required reservation schedule parameters.'], 400);
}

$startTs = strtotime($startTime);
$endTs = strtotime($endTime);

if ($startTs === false || $endTs === false || $endTs <= $startTs) {
    Security::jsonResponse(['success' => false, 'message' => 'Check-out time must be after check-in time.'], 400);
}

$db = Database::getInstance()->getConnection();

// Determine vehicle info
$finalPlate = '';
$finalCategory = 'car';
$finalIsEv = false;

if ($vehicleId) {
    $vStmt = $db->prepare("SELECT plate_number, vehicle_type, is_ev FROM vehicles WHERE id = ? AND user_id = ?");
    $vStmt->execute([$vehicleId, $userId]);
    $veh = $vStmt->fetch();
    if ($veh) {
        $finalPlate = $veh['plate_number'];
        $finalCategory = $veh['vehicle_type'];
        $finalIsEv = (bool)$veh['is_ev'];
    } else {
        Security::jsonResponse(['success' => false, 'message' => 'Selected vehicle does not belong to your account.'], 400);
    }
} else {
    if (empty($customPlate) || !Security::validatePlate($customPlate)) {
        Security::jsonResponse(['success' => false, 'message' => 'Please provide a valid Indian vehicle license plate number.'], 400);
    }
    $finalPlate = $customPlate;
    $finalCategory = $customCategory;
    $finalIsEv = $customIsEv;
}

try {
    // 1. Verify Slot Status
    $slotStmt = $db->prepare("SELECT * FROM slots WHERE id = ? AND facility_id = ? AND status != 'maintenance'");
    $slotStmt->execute([$slotId, $facilityId]);
    $slot = $slotStmt->fetch();

    if (!$slot) {
        Security::jsonResponse(['success' => false, 'message' => 'Selected parking slot is invalid, inactive, or under maintenance.'], 404);
    }

    // 2. Validate Vehicle Category Compatibility
    $slotType = $slot['slot_type'];
    if (in_array($finalCategory, ['truck', 'bus'], true) && in_array($slotType, ['bike', 'bicycle', 'auto_rickshaw', 'car'], true)) {
        Security::jsonResponse(['success' => false, 'message' => "Vehicle category '{$finalCategory}' cannot fit into '{$slotType}' stall {$slot['slot_number']}."], 400);
    }
    if ($finalCategory === 'van' && in_array($slotType, ['bike', 'bicycle'], true)) {
        Security::jsonResponse(['success' => false, 'message' => "Van cannot fit into '{$slotType}' stall {$slot['slot_number']}."], 400);
    }
    if ($finalCategory === 'car' && in_array($slotType, ['bike', 'bicycle'], true)) {
        Security::jsonResponse(['success' => false, 'message' => "Car cannot fit into two-wheeler stall {$slot['slot_number']}."], 400);
    }

    // 3. Validate EV Charger Requirement
    if ($evChargingRequested && empty($slot['is_ev_charging'])) {
        Security::jsonResponse(['success' => false, 'message' => "Stall {$slot['slot_number']} does not have an EV charger. Please select an EV-enabled bay."], 400);
    }

    // 4. Calculate Fare
    $pricingService = new PricingService($db);
    $fare = $pricingService->calculateFare($facilityId, $startTime, $endTime, $finalCategory, $finalIsEv, $evChargingRequested);

    // 5. Create Dynamic Payment Order
    $paymentService = new PaymentGatewayService($db, $pricingService);
    $order = $paymentService->createOrder(
        $userId,
        $facilityId,
        $slotId,
        $fare['total_amount'],
        $startTime,
        $endTime,
        $finalCategory,
        $finalIsEv,
        $evChargingRequested
    );

    // Attach fare breakdown
    $order['fare_breakdown'] = [
        'duration_hours' => $fare['duration_hours'],
        'hourly_rate' => $fare['hourly_rate'],
        'base_amount' => $fare['base_amount'],
        'extra_amount' => $fare['extra_amount'] ?? 0.0,
        'total_amount' => $fare['total_amount']
    ];
    $order['vehicle_plate'] = $finalPlate;

    Security::jsonResponse($order, 201);

} catch (\Throwable $e) {
    Security::jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
}
