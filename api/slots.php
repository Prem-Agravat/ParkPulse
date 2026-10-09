<?php
/**
 * Facility Slots Matrix & Real-time Availability API
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

$facilityId = (int)($_GET['facility_id'] ?? 0);
$startTime = Security::sanitizeString($_GET['start_time'] ?? '');
$endTime = Security::sanitizeString($_GET['end_time'] ?? '');

if ($facilityId <= 0) {
    Security::jsonResponse(['success' => false, 'message' => 'Invalid facility identifier.'], 400);
}

$db = Database::getInstance()->getConnection();

// Fetch facility info
$facStmt = $db->prepare("SELECT * FROM facilities WHERE id = ? AND status != 'inactive'");
$facStmt->execute([$facilityId]);
$facility = $facStmt->fetch();

if (!$facility) {
    Security::jsonResponse(['success' => false, 'message' => 'Facility not found.'], 404);
}

// Fetch all active slots for this facility
$slotStmt = $db->prepare("SELECT * FROM slots WHERE facility_id = ? AND is_active = 1 ORDER BY floor_level ASC, zone_name ASC, slot_number ASC");
$slotStmt->execute([$facilityId]);
$slots = $slotStmt->fetchAll();

// If time window is given, fetch reserved / checked_in slots during that time
$reservedSlotIds = [];
$checkedInSlotIds = [];

if (!empty($startTime) && !empty($endTime)) {
    $resStmt = $db->prepare("SELECT slot_id, status FROM reservations 
        WHERE facility_id = ? 
        AND status IN ('confirmed', 'checked_in')
        AND (start_time < ? AND end_time > ?)");
    $resStmt->execute([$facilityId, $endTime, $startTime]);
    while ($row = $resStmt->fetch()) {
        $sid = (int)$row['slot_id'];
        if ($row['status'] === 'checked_in') {
            $checkedInSlotIds[$sid] = true;
        } else {
            $reservedSlotIds[$sid] = true;
        }
    }
}

// Group slots by Floor -> Zone
$floors = [];
$stats = [
    'total' => count($slots),
    'available' => 0,
    'occupied' => 0,
    'reserved' => 0,
    'maintenance' => 0,
    'ev' => 0
];

foreach ($slots as $slot) {
    $slotId = (int)$slot['id'];
    $floor = $slot['floor_level'];
    $zone = $slot['zone_name'];

    // Determine effective status for the given time frame
    $computedStatus = $slot['status']; // default DB status ('available', 'occupied', 'reserved', 'maintenance')

    if ($computedStatus === 'maintenance') {
        $stats['maintenance']++;
    } elseif (isset($checkedInSlotIds[$slotId])) {
        $computedStatus = 'occupied';
        $stats['occupied']++;
    } elseif (isset($reservedSlotIds[$slotId])) {
        $computedStatus = 'reserved';
        $stats['reserved']++;
    } elseif ($computedStatus === 'available') {
        $stats['available']++;
    } else {
        $stats[$computedStatus] = ($stats[$computedStatus] ?? 0) + 1;
    }

    if ($slot['is_ev_charging']) {
        $stats['ev']++;
    }

    if (!isset($floors[$floor])) {
        $floors[$floor] = [
            'floor_name' => $floor,
            'zones' => []
        ];
    }

    if (!isset($floors[$floor]['zones'][$zone])) {
        $floors[$floor]['zones'][$zone] = [
            'zone_name' => $zone,
            'slots' => []
        ];
    }

    $floors[$floor]['zones'][$zone]['slots'][] = [
        'id' => $slotId,
        'slot_number' => $slot['slot_number'],
        'slot_type' => $slot['slot_type'],
        'is_ev_charging' => (bool)$slot['is_ev_charging'],
        'is_accessible' => (bool)$slot['is_accessible'],
        'status' => $computedStatus
    ];
}

// Re-index floors and zones arrays for JSON output
$structuredFloors = [];
foreach ($floors as $fName => $fData) {
    $structuredZones = [];
    foreach ($fData['zones'] as $zName => $zData) {
        $structuredZones[] = $zData;
    }
    $structuredFloors[] = [
        'floor_name' => $fName,
        'zones' => $structuredZones
    ];
}

Security::jsonResponse([
    'success' => true,
    'facility' => [
        'id' => (int)$facility['id'],
        'name' => $facility['name'],
        'hourly_rate' => (float)$facility['hourly_rate'],
        'ev_rate' => (float)$facility['ev_rate'],
        'peak_rate' => (float)$facility['peak_rate'],
        'weekend_rate' => (float)$facility['weekend_rate']
    ],
    'stats' => $stats,
    'floors' => $structuredFloors
]);
