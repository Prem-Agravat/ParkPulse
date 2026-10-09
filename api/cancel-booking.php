<?php
/**
 * Reservation Cancellation API
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/ReservationService.php';

if (!Auth::check()) {
    Security::jsonResponse(['success' => false, 'message' => 'Unauthorized access.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!Security::validateCsrfToken($csrfToken)) {
    Security::jsonResponse(['success' => false, 'message' => 'Security session expired. Please refresh.'], 403);
}

$bookingRef = Security::sanitizeString($input['booking_reference'] ?? '');
$reason = Security::sanitizeString($input['reason'] ?? 'Customer requested cancellation', 250);

if (empty($bookingRef)) {
    Security::jsonResponse(['success' => false, 'message' => 'Booking reference is required.'], 400);
}

$userId = Auth::id();
$isAdmin = Auth::isAdmin();
$db = Database::getInstance()->getConnection();
$resService = new ReservationService($db);

try {
    $result = $resService->cancelReservation($bookingRef, $userId, $isAdmin, $reason);

    Security::jsonResponse([
        'success' => true,
        'message' => "Reservation {$bookingRef} has been cancelled and refund processed.",
        'refund_amount' => $result['refund_amount'],
        'refund_transaction_ref' => $result['refund_transaction_ref']
    ]);
} catch (\InvalidArgumentException $e) {
    Security::jsonResponse(['success' => false, 'message' => $e->getMessage()], 404);
} catch (\RuntimeException $e) {
    Security::jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log("Cancellation Error: " . $e->getMessage());
    Security::jsonResponse(['success' => false, 'message' => 'A system error occurred during cancellation.'], 500);
}
