<?php
/**
 * Reservations JSON API Endpoint
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/ReservationService.php';

if (!Auth::check()) {
    Security::jsonResponse(['success' => false, 'message' => 'Unauthorized access.'], 401);
}

$userId = Auth::id();
$role = Auth::role();
$db = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// GET: Paginated list of reservations
if ($method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $status = Security::sanitizeString($_GET['status'] ?? '');
    $bookingRef = Security::sanitizeString($_GET['ref'] ?? '');

    if (!empty($bookingRef)) {
        // Single reservation fetch
        $stmt = $db->prepare("SELECT r.*, f.name AS facility_name, f.city, s.slot_number, s.floor_level, s.zone_name 
            FROM reservations r
            JOIN facilities f ON r.facility_id = f.id
            JOIN slots s ON r.slot_id = s.id
            WHERE r.booking_reference = ?");
        $stmt->execute([$bookingRef]);
        $res = $stmt->fetch();

        if (!$res) {
            Security::jsonResponse(['success' => false, 'message' => 'Reservation not found.'], 404);
        }

        if ($role !== 'admin' && $role !== 'attendant' && (int)$res['user_id'] !== $userId) {
            Security::jsonResponse(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        Security::jsonResponse(['success' => true, 'data' => $res]);
    }

    $sql = "SELECT r.*, f.name AS facility_name, f.city, s.slot_number, s.floor_level, s.zone_name 
            FROM reservations r
            JOIN facilities f ON r.facility_id = f.id
            JOIN slots s ON r.slot_id = s.id";

    $params = [];
    $conditions = [];

    if ($role !== 'admin') {
        $conditions[] = "r.user_id = ?";
        $params[] = $userId;
    }

    if (!empty($status) && in_array($status, ['confirmed', 'checked_in', 'completed', 'cancelled', 'expired'], true)) {
        $conditions[] = "r.status = ?";
        $params[] = $status;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    // Count total
    $countSql = "SELECT COUNT(*) FROM (" . $sql . ") AS total_rows";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    $sql .= " ORDER BY r.created_at DESC LIMIT {$limit} OFFSET {$offset}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    Security::jsonResponse([
        'success' => true,
        'page' => $page,
        'limit' => $limit,
        'total' => $totalCount,
        'total_pages' => (int)ceil($totalCount / $limit),
        'data' => $data
    ]);
}

// POST: Create Reservation
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!Security::validateCsrfToken($csrfToken)) {
        Security::jsonResponse(['success' => false, 'message' => 'Security session expired.'], 403);
    }

    $facilityId = (int)($input['facility_id'] ?? 0);
    $slotId = (int)($input['slot_id'] ?? 0);
    $vehicleId = !empty($input['vehicle_id']) ? (int)$input['vehicle_id'] : null;
    $customPlate = strtoupper(trim(Security::sanitizeString($input['custom_plate'] ?? '', 20)));
    $startTime = Security::sanitizeString($input['start_time'] ?? '');
    $endTime = Security::sanitizeString($input['end_time'] ?? '');
    $paymentMethod = Security::sanitizeString($input['payment_method'] ?? 'card');

    if ($facilityId <= 0 || $slotId <= 0 || empty($startTime) || empty($endTime)) {
        Security::jsonResponse(['success' => false, 'message' => 'Facility, slot, and time range are required.'], 400);
    }

    $finalPlate = '';
    if ($vehicleId) {
        $chk = $db->prepare("SELECT plate_number FROM vehicles WHERE id = ? AND user_id = ?");
        $chk->execute([$vehicleId, $userId]);
        $row = $chk->fetch();
        if ($row) {
            $finalPlate = $row['plate_number'];
        } else {
            Security::jsonResponse(['success' => false, 'message' => 'Selected vehicle not found.'], 404);
        }
    } else {
        if (!Security::validatePlate($customPlate)) {
            Security::jsonResponse(['success' => false, 'message' => 'Invalid license plate entered.'], 400);
        }
        $finalPlate = $customPlate;
    }

    try {
        $reservationService = new ReservationService($db);
        $result = $reservationService->createReservation(
            $userId,
            $facilityId,
            $slotId,
            $finalPlate,
            $vehicleId,
            $startTime,
            $endTime,
            $paymentMethod
        );

        Security::jsonResponse([
            'success' => true,
            'message' => "Slot {$result['slot_number']} reserved successfully!",
            'data' => $result
        ], 201);
    } catch (\Throwable $e) {
        Security::jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
}
