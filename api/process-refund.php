<?php
/**
 * Admin Payment Refund Processor API
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/PaymentGatewayService.php';

if (!Auth::check() || !Auth::isAdmin()) {
    Security::jsonResponse(['success' => false, 'message' => 'Unauthorized. Admin privileges required.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'message' => 'Invalid HTTP method.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!Security::validateCsrfToken($csrfToken)) {
    Security::jsonResponse(['success' => false, 'message' => 'Security token invalid or expired. Please refresh the page.'], 403);
}

$paymentId = (int)($input['payment_id'] ?? 0);
$reason = Security::sanitizeString($input['reason'] ?? 'Administrator Processed Cancellation Refund', 255);

if ($paymentId <= 0) {
    Security::jsonResponse(['success' => false, 'message' => 'Valid payment ID is required.'], 400);
}

$db = Database::getInstance()->getConnection();
$paymentService = new PaymentGatewayService($db);

try {
    $result = $paymentService->processRefund($paymentId, Auth::id(), $reason);
    Security::jsonResponse($result, 200);
} catch (\Throwable $e) {
    Security::jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
}
