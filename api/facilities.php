<?php
/**
 * Facilities Search & Availability API
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

$db = Database::getInstance()->getConnection();

$city = Security::sanitizeString($_GET['city'] ?? '');
$state = Security::sanitizeString($_GET['state'] ?? '');
$query = Security::sanitizeString($_GET['q'] ?? '');
$evOnly = !empty($_GET['ev_only']) || !empty($_GET['ev_charger']);
$vehicleType = Security::sanitizeString($_GET['vehicle_type'] ?? 'all');
$maxPrice = !empty($_GET['max_price']) ? (float)$_GET['max_price'] : 0.0;
$sortBy = Security::sanitizeString($_GET['sort'] ?? 'avail_desc');
$startTime = Security::sanitizeString($_GET['start_time'] ?? '');
$endTime = Security::sanitizeString($_GET['end_time'] ?? '');

// Base query for active facilities
$sql = "SELECT f.*,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1) AS total_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.is_ev_charging = 1) AS total_ev_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'car') AS total_car_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'bike') AS total_bike_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'bicycle') AS total_bicycle_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'auto_rickshaw') AS total_auto_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'van') AS total_van_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'truck') AS total_truck_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.slot_type = 'bus') AS total_bus_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.is_active = 1 AND s.is_accessible = 1) AS total_accessible_slots,
    (SELECT COUNT(*) FROM slots s WHERE s.facility_id = f.id AND s.status IN ('occupied', 'reserved')) AS realtime_occupied_slots
    FROM facilities f 
    WHERE f.status = 'active'";

$params = [];

if (!empty($state)) {
    $sql .= " AND f.state LIKE ?";
    $params[] = "%{$state}%";
}

if (!empty($city)) {
    $sql .= " AND f.city LIKE ?";
    $params[] = "%{$city}%";
}

if (!empty($query)) {
    $sql .= " AND (f.name LIKE ? OR f.address LIKE ? OR f.locality LIKE ? OR f.city LIKE ? OR f.district LIKE ? OR f.state LIKE ? OR f.postal_code LIKE ? OR f.code LIKE ?)";
    $params[] = "%{$query}%";
    $params[] = "%{$query}%";
    $params[] = "%{$query}%";
    $params[] = "%{$query}%";
    $params[] = "%{$query}%";
    $params[] = "%{$query}%";
    $params[] = "%{$query}%";
    $params[] = "%{$query}%";
}

if ($maxPrice > 0) {
    $sql .= " AND f.hourly_rate <= ?";
    $params[] = $maxPrice;
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$facilities = $stmt->fetchAll();

// If time window is provided, fetch time-window occupancy in a single batch query
$windowOccupancy = [];
if (!empty($startTime) && !empty($endTime)) {
    $occStmt = $db->prepare("SELECT facility_id, COUNT(DISTINCT slot_id) AS occ_count FROM reservations 
        WHERE status IN ('confirmed', 'checked_in')
        AND (start_time < ? AND end_time > ?)
        GROUP BY facility_id");
    $occStmt->execute([$endTime, $startTime]);
    $windowRows = $occStmt->fetchAll();
    foreach ($windowRows as $wr) {
        $windowOccupancy[(int)$wr['facility_id']] = (int)$wr['occ_count'];
    }
}

$results = [];
foreach ($facilities as $fac) {
    $facId = (int)$fac['id'];
    $amenities = json_decode($fac['amenities_json'] ?? '[]', true) ?: [];

    // Filter by vehicle type capability if specific category requested
    if ($vehicleType !== 'all') {
        $countKey = match($vehicleType) {
            'bike' => 'total_bike_slots',
            'bicycle' => 'total_bicycle_slots',
            'auto_rickshaw' => 'total_auto_slots',
            'van' => 'total_van_slots',
            'truck' => 'total_truck_slots',
            'bus' => 'total_bus_slots',
            'handicap' => 'total_accessible_slots',
            default => 'total_car_slots'
        };
        // If facility has no slots for this vehicle type, skip
        if (empty($fac[$countKey]) && empty($fac['total_slots'])) {
            continue;
        }
    }

    if ($evOnly && empty($fac['total_ev_slots'])) {
        continue;
    }

    // Determine occupied count
    if (!empty($startTime) && !empty($endTime)) {
        $occupiedCount = $windowOccupancy[$facId] ?? 0;
    } else {
        $occupiedCount = (int)($fac['realtime_occupied_slots'] ?? 0);
    }

    $availableSlots = max(0, (int)$fac['total_slots'] - $occupiedCount);
    $lat = (float)$fac['latitude'];
    $lng = (float)$fac['longitude'];

    $results[] = [
        'id' => $facId,
        'name' => $fac['name'],
        'code' => $fac['code'],
        'address' => $fac['address'],
        'locality' => $fac['locality'] ?? '',
        'city' => $fac['city'],
        'district' => $fac['district'] ?? '',
        'state' => $fac['state'],
        'country' => $fac['country'] ?? 'India',
        'postal_code' => $fac['postal_code'],
        'latitude' => $lat,
        'longitude' => $lng,
        'osm_url' => Security::buildOsmUrl($lat, $lng),
        'google_maps_url' => Security::buildGoogleMapsUrl($lat, $lng),
        'total_capacity' => (int)$fac['total_capacity'],
        'total_slots' => (int)$fac['total_slots'],
        'total_ev_slots' => (int)$fac['total_ev_slots'],
        'total_car_slots' => (int)$fac['total_car_slots'],
        'total_bike_slots' => (int)$fac['total_bike_slots'],
        'total_bicycle_slots' => (int)$fac['total_bicycle_slots'],
        'total_auto_slots' => (int)$fac['total_auto_slots'],
        'total_van_slots' => (int)$fac['total_van_slots'],
        'total_truck_slots' => (int)$fac['total_truck_slots'],
        'total_bus_slots' => (int)$fac['total_bus_slots'],
        'available_slots' => $availableSlots,
        'occupied_slots' => $occupiedCount,
        'hourly_rate' => (float)$fac['hourly_rate'],
        'ev_rate' => (float)$fac['ev_rate'],
        'peak_rate' => (float)$fac['peak_rate'],
        'weekend_rate' => (float)$fac['weekend_rate'],
        'overtime_rate_per_hour' => (float)$fac['overtime_rate_per_hour'],
        'grace_period_mins' => (int)$fac['grace_period_mins'],
        'amenities' => $amenities,
        'description' => $fac['description'],
        'image_url' => $fac['image_url'],
        'status' => $fac['status'],
        'is_system_facility' => true
    ];
}

// Client-requested sorting
usort($results, function($a, $b) use ($sortBy) {
    return match($sortBy) {
        'price_asc' => $a['hourly_rate'] <=> $b['hourly_rate'],
        'price_desc' => $b['hourly_rate'] <=> $a['hourly_rate'],
        'name_asc' => strcmp($a['name'], $b['name']),
        'avail_asc' => $a['available_slots'] <=> $b['available_slots'],
        default => $b['available_slots'] <=> $a['available_slots'] // avail_desc
    };
});

Security::jsonResponse([
    'success' => true,
    'count' => count($results),
    'data' => $results
]);
