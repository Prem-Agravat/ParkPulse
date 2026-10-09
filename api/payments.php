<?php
/**
 * Payments & Financial State JSON API
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

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;
$resId = !empty($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : 0;

$sql = "SELECT p.*, r.booking_reference, r.vehicle_plate, f.name AS facility_name 
        FROM payments p
        JOIN reservations r ON p.reservation_id = r.id
        JOIN facilities f ON r.facility_id = f.id";

$params = [];
$conditions = [];

if ($role !== 'admin') {
    $conditions[] = "p.user_id = ?";
    $params[] = $userId;
}

if ($resId > 0) {
    $conditions[] = "p.reservation_id = ?";
    $params[] = $resId;
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

// Total count
$countSql = "SELECT COUNT(*) FROM (" . $sql . ") AS total_rows";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql .= " ORDER BY p.created_at DESC LIMIT {$limit} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

Security::jsonResponse([
    'success' => true,
    'page' => $page,
    'limit' => $limit,
    'total' => $total,
    'data' => $payments
]);
