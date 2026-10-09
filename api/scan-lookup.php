<?php
/**
 * Attendant Gate Scanner Lookup API
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/GateService.php';

// Attendant or Admin only
if (!Auth::check()) {
    Security::jsonResponse(['success' => false, 'message' => 'Unauthorized gate access.'], 401);
}
if (!Auth::isAttendant() && !Auth::isAdmin()) {
    Security::jsonResponse(['success' => false, 'message' => 'Forbidden gate access.'], 403);
}

$query = Security::sanitizeString($_GET['q'] ?? '');
if (empty($query)) {
    Security::jsonResponse(['success' => false, 'message' => 'Please provide a QR token, booking reference or license plate.'], 400);
}

$db = Database::getInstance()->getConnection();
$gateService = new GateService($db);

$booking = $gateService->lookupPass($query);

if (!$booking) {
    Security::jsonResponse(['success' => false, 'message' => 'No active reservation found matching the provided QR or Plate.'], 404);
}

Security::jsonResponse([
    'success' => true,
    'data' => $booking
]);
