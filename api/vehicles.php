<?php
/**
 * Vehicle Management JSON API
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

if (!Auth::check()) {
    Security::jsonResponse(['success' => false, 'message' => 'Unauthorized access.'], 401);
}

$userId = Auth::id();
$role = Auth::role();
$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// GET: List user vehicles (or all if admin)
if ($method === 'GET') {
    $targetUserId = ($role === 'admin' && !empty($_GET['user_id'])) ? (int)$_GET['user_id'] : $userId;
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
    $stmt->execute([$targetUserId]);
    $vehicles = $stmt->fetchAll();

    Security::jsonResponse([
        'success' => true,
        'count' => count($vehicles),
        'data' => $vehicles
    ]);
}

// POST: Add, Edit, Set Default, or Delete Vehicle
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!Security::validateCsrfToken($csrfToken)) {
        Security::jsonResponse(['success' => false, 'message' => 'Security session expired. Please refresh.'], 403);
    }

    $action = Security::sanitizeString($input['action'] ?? 'add');

    if ($action === 'add') {
        $plate = strtoupper(trim(Security::sanitizeString($input['plate_number'] ?? '', 20)));
        $type = Security::sanitizeString($input['vehicle_type'] ?? 'car', 20);
        $makeModel = Security::sanitizeString($input['make_model'] ?? '', 100);
        $color = Security::sanitizeString($input['color'] ?? '', 50);
        $isEv = !empty($input['is_ev']) ? 1 : 0;
        $isDefault = !empty($input['is_default']) ? 1 : 0;

        if (empty($plate) || empty($makeModel) || empty($color)) {
            Security::jsonResponse(['success' => false, 'message' => 'All vehicle fields are required.'], 400);
        }

        if (!Security::validatePlate($plate)) {
            Security::jsonResponse(['success' => false, 'message' => 'Invalid license plate format.'], 400);
        }

        try {
            // Check uniqueness for this user
            $chk = $db->prepare("SELECT id FROM vehicles WHERE user_id = ? AND plate_number = ?");
            $chk->execute([$userId, $plate]);
            if ($chk->fetch()) {
                Security::jsonResponse(['success' => false, 'message' => 'You have already added this license plate.'], 400);
            }

            if ($isDefault) {
                $db->prepare("UPDATE vehicles SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
            }

            $stmt = $db->prepare("INSERT INTO vehicles (user_id, plate_number, vehicle_type, make_model, color, is_ev, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $plate, $type, $makeModel, $color, $isEv, $isDefault]);
            $vehId = (int)$db->lastInsertId();

            AuditLogger::log('VEHICLE_ADDED', 'vehicles', $vehId, "Added vehicle plate {$plate}");

            Security::jsonResponse([
                'success' => true,
                'message' => "Vehicle {$plate} registered successfully.",
                'data' => [
                    'id' => $vehId,
                    'plate_number' => $plate,
                    'vehicle_type' => $type,
                    'is_ev' => (bool)$isEv,
                    'is_default' => (bool)$isDefault
                ]
            ], 201);
        } catch (\Throwable $e) {
            Security::jsonResponse(['success' => false, 'message' => 'Database error registering vehicle.'], 500);
        }
    }

    if ($action === 'set_default') {
        $vehId = (int)($input['vehicle_id'] ?? 0);
        $db->prepare("UPDATE vehicles SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
        $stmt = $db->prepare("UPDATE vehicles SET is_default = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehId, $userId]);
        Security::jsonResponse(['success' => true, 'message' => 'Primary vehicle updated.']);
    }

    if ($action === 'delete') {
        $vehId = (int)($input['vehicle_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$vehId, $userId]);
        AuditLogger::log('VEHICLE_DELETED', 'vehicles', $vehId, "Deleted vehicle ID {$vehId}");
        Security::jsonResponse(['success' => true, 'message' => 'Vehicle removed.']);
    }
}
