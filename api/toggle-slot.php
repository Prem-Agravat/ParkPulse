<?php
/**
 * Slot Status & Maintenance Override API
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

if (!Auth::check()) {
    Security::jsonResponse(['success' => false, 'message' => 'Unauthorized access.'], 401);
}
if (!Auth::isAttendant() && !Auth::isAdmin()) {
    Security::jsonResponse(['success' => false, 'message' => 'Forbidden.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Security::jsonResponse(['success' => false, 'message' => 'Invalid method.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!Security::validateCsrfToken($csrfToken)) {
    Security::jsonResponse(['success' => false, 'message' => 'Security token invalid.'], 403);
}

$slotId = (int)($input['slot_id'] ?? 0);
$newStatus = Security::sanitizeString($input['status'] ?? '');

if ($slotId <= 0 || !in_array($newStatus, ['available', 'maintenance'], true)) {
    Security::jsonResponse(['success' => false, 'message' => 'Invalid slot parameters.'], 400);
}

$db = Database::getInstance()->getConnection();

try {
    $stmt = $db->prepare("SELECT * FROM slots WHERE id = ?");
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch();

    if (!$slot) {
        Security::jsonResponse(['success' => false, 'message' => 'Slot not found.'], 404);
    }

    if ($slot['status'] === 'occupied' && $newStatus === 'maintenance') {
        Security::jsonResponse(['success' => false, 'message' => 'Cannot put occupied slot into maintenance while vehicle is parked.'], 400);
    }

    $update = $db->prepare("UPDATE slots SET status = ? WHERE id = ?");
    $update->execute([$newStatus, $slotId]);

    AuditLogger::log('SLOT_STATUS_TOGGLED', 'slots', $slotId, "Slot {$slot['slot_number']} status changed from {$slot['status']} to {$newStatus} by User ID " . Auth::id());

    Security::jsonResponse([
        'success' => true,
        'message' => "Slot {$slot['slot_number']} updated to {$newStatus}."
    ]);
} catch (\Throwable $e) {
    error_log("Toggle Slot Error: " . $e->getMessage());
    Security::jsonResponse(['success' => false, 'message' => 'System error updating slot.'], 500);
}
