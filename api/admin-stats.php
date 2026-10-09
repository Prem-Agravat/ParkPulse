<?php
/**
 * Administrator Analytics & Chart.js Metrics API
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

if (!Auth::check()) {
    Security::jsonResponse(['success' => false, 'message' => 'Unauthorized access.'], 401);
}
if (!Auth::isAdmin()) {
    Security::jsonResponse(['success' => false, 'message' => 'Forbidden.'], 403);
}

$db = Database::getInstance()->getConnection();

// 1. Overall Totals & Today's Metrics
$totalRevenue = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success'")->fetchColumn();
$todayRevenue = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success' AND DATE(created_at) = CURDATE()")->fetchColumn();

$totalBookings = (int)$db->query("SELECT COUNT(*) FROM reservations")->fetchColumn();
$todayBookings = (int)$db->query("SELECT COUNT(*) FROM reservations WHERE DATE(created_at) = CURDATE()")->fetchColumn();

$activeVehicles = (int)$db->query("SELECT COUNT(*) FROM reservations WHERE status = 'checked_in'")->fetchColumn();
$totalUsers = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$totalSlots = (int)$db->query("SELECT COUNT(*) FROM slots WHERE is_active = 1")->fetchColumn();

$slotStates = [
    'available' => (int)$db->query("SELECT COUNT(*) FROM slots WHERE status = 'available' AND is_active = 1")->fetchColumn(),
    'occupied' => (int)$db->query("SELECT COUNT(*) FROM slots WHERE status = 'occupied' AND is_active = 1")->fetchColumn(),
    'reserved' => (int)$db->query("SELECT COUNT(*) FROM slots WHERE status = 'reserved' AND is_active = 1")->fetchColumn(),
    'maintenance' => (int)$db->query("SELECT COUNT(*) FROM slots WHERE status = 'maintenance' OR is_active = 0")->fetchColumn()
];

$occupiedSlots = $slotStates['occupied'] + $slotStates['reserved'];
$overallOccupancyPct = $totalSlots > 0 ? round(($occupiedSlots / $totalSlots) * 100, 1) : 0;

// 2. Revenue Trend - Last 7 Days
$revenueTrend = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success' AND DATE(created_at) = ?");
    $stmt->execute([$date]);
    $rev = (float)$stmt->fetchColumn();
    $revenueTrend[] = [
        'date' => date('M d', strtotime($date)),
        'revenue' => $rev
    ];
}

// 3. Occupancy by Facility
$facOccupancy = [];
$facStmt = $db->query("SELECT f.id, f.name, f.total_capacity,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1) as total_bays,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.status = 'occupied') as occupied_bays,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.status = 'reserved') as reserved_bays
    FROM facilities f WHERE f.status = 'active'");
while ($row = $facStmt->fetch()) {
    $facOccupancy[] = [
        'name' => $row['name'],
        'total' => (int)$row['total_bays'],
        'occupied' => (int)$row['occupied_bays'],
        'reserved' => (int)$row['reserved_bays'],
        'available' => max(0, (int)$row['total_bays'] - ((int)$row['occupied_bays'] + (int)$row['reserved_bays']))
    ];
}

// 4. Vehicle Type Breakdown
$vehicleTypes = [
    'Car / Sedan' => 0,
    'Electric (EV)' => 0,
    'SUV' => 0,
    'Motorcycle' => 0,
    'Accessible' => 0,
    'Truck' => 0
];
$vehStmt = $db->query("SELECT vehicle_type, is_ev, COUNT(*) as count FROM vehicles GROUP BY vehicle_type, is_ev");
while ($row = $vehStmt->fetch()) {
    $count = (int)$row['count'];
    if ($row['is_ev']) {
        $vehicleTypes['Electric (EV)'] += $count;
    } else {
        match($row['vehicle_type']) {
            'suv' => $vehicleTypes['SUV'] += $count,
            'bike' => $vehicleTypes['Motorcycle'] += $count,
            'handicap' => $vehicleTypes['Accessible'] += $count,
            'truck' => $vehicleTypes['Truck'] += $count,
            default => $vehicleTypes['Car / Sedan'] += $count
        };
    }
}

// 5. Peak Booking Hours Distribution (0-23)
$peakHours = array_fill(0, 24, 0);
$peakStmt = $db->query("SELECT HOUR(start_time) as hr, COUNT(*) as cnt FROM reservations GROUP BY HOUR(start_time)");
while ($row = $peakStmt->fetch()) {
    $hr = (int)$row['hr'];
    $peakHours[$hr] = (int)$row['cnt'];
}

Security::jsonResponse([
    'success' => true,
    'summary' => [
        'total_revenue' => $totalRevenue,
        'today_revenue' => $todayRevenue,
        'total_bookings' => $totalBookings,
        'today_bookings' => $todayBookings,
        'active_vehicles' => $activeVehicles,
        'total_users' => $totalUsers,
        'total_slots' => $totalSlots,
        'slot_states' => $slotStates,
        'overall_occupancy_pct' => $overallOccupancyPct
    ],
    'revenue_trend' => $revenueTrend,
    'facility_occupancy' => $facOccupancy,
    'vehicle_types' => $vehicleTypes,
    'peak_hours' => $peakHours
]);
