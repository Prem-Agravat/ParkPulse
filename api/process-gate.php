<?php
/**
 * Attendant Gate Entry / Exit Processor API
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/GateService.php';

if (!Auth::check()) {
    Security::jsonResponse(['success' => false, 'message' => 'Unauthorized gate access.'], 401);
}
if (!Auth::isAttendant() && !Auth::isAdmin()) {
    Security::jsonResponse(['success' => false, 'message' => 'Forbidden gate access.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'message' => 'Invalid method.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!Security::validateCsrfToken($csrfToken)) {
    Security::jsonResponse(['success' => false, 'message' => 'Security token invalid.'], 403);
}

$bookingId = (int)($input['booking_id'] ?? 0);
$action = Security::sanitizeString($input['action'] ?? '');

if ($bookingId <= 0 || !in_array($action, ['check_in', 'check_out'], true)) {
    Security::jsonResponse(['success' => false, 'message' => 'Invalid gate request parameters.'], 400);
}

$attendantId = Auth::id();
$db = Database::getInstance()->getConnection();
$gateService = new GateService($db);

try {
    if ($action === 'check_in') {
        $result = $gateService->processCheckIn($bookingId, $attendantId);
        Security::jsonResponse([
            'success' => true,
            'action' => 'check_in',
            'data' => $result,
            'message' => "Vehicle {$result['vehicle_plate']} successfully checked in at Slot {$result['slot_number']}."
        ]);
    } else {
        $result = $gateService->processCheckOut($bookingId, $attendantId);
        $overtimeAmount = $result['overtime_amount'] ?? 0.0;
        Security::jsonResponse([
            'success' => true,
            'action' => 'check_out',
            'overtime_amount' => $overtimeAmount,
            'overtime_mins' => $result['overtime_mins'] ?? 0,
            'data' => $result,
            'message' => "Vehicle {$result['vehicle_plate']} checked out successfully." . ($overtimeAmount > 0 ? " Overtime fee of ₹{$overtimeAmount} collected." : "")
        ]);
    }
} catch (\InvalidArgumentException $e) {
    Security::jsonResponse(['success' => false, 'message' => $e->getMessage()], 404);
} catch (\RuntimeException $e) {
    Security::jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log("Gate Processing Error: " . $e->getMessage());
    Security::jsonResponse(['success' => false, 'message' => 'System error during gate processing.'], 500);
}
