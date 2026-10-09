<?php
/**
 * Database Seeder Script - India / Gujarat Edition
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

echo ">>> Starting Gujarat & India Database Migration and Seeding...\n";

try {
    $db = Database::getInstance()->getConnection();

    // 1. Ensure Schema
    $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
    $db->exec($schemaSql);
    echo "✔ Schema tables verified and ready.\n";

    // 2. Clear previous seed data for clean baseline
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $db->exec("TRUNCATE TABLE rate_limits;");
    $db->exec("TRUNCATE TABLE audit_logs;");
    $db->exec("TRUNCATE TABLE payments;");
    $db->exec("TRUNCATE TABLE parking_sessions;");
    $db->exec("TRUNCATE TABLE qr_passes;");
    $db->exec("TRUNCATE TABLE reservations;");
    $db->exec("TRUNCATE TABLE attendant_assignments;");
    $db->exec("TRUNCATE TABLE pricing_rules;");
    $db->exec("TRUNCATE TABLE slots;");
    $db->exec("TRUNCATE TABLE facilities;");
    $db->exec("TRUNCATE TABLE vehicles;");
    $db->exec("TRUNCATE TABLE users;");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "✔ Cleaned prior seed data.\n";

    // 3. Seed Users (Gujarati / Indian Sample Personas)
    $userStmt = $db->prepare("INSERT INTO users (name, email, password_hash, phone, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");

    $users = [
        // 1. Admin
        ['Jignesh Patel', 'admin@parkpulse.com', password_hash('Admin@2026', PASSWORD_BCRYPT), '+91 98250 12345', 'admin', 'active', '2026-01-01 08:00:00'],
        // 2 & 3. Attendants
        ['Krunal Solanki', 'attendant.rajkot@parkpulse.com', password_hash('Attendant@2026', PASSWORD_BCRYPT), '+91 94280 23456', 'attendant', 'active', '2026-01-05 09:00:00'],
        ['Hetal Shah', 'attendant.ahmedabad@parkpulse.com', password_hash('Attendant@2026', PASSWORD_BCRYPT), '+91 98790 34567', 'attendant', 'active', '2026-01-05 09:00:00'],
        // 4 - 9. Drivers / Users
        ['Dev Patel', 'dev.patel@example.com', password_hash('User@2026', PASSWORD_BCRYPT), '+91 98980 45678', 'user', 'active', '2026-02-01 10:00:00'],
        ['Nisha Parmar', 'nisha.parmar@example.com', password_hash('User@2026', PASSWORD_BCRYPT), '+91 97240 56789', 'user', 'active', '2026-02-10 11:30:00'],
        ['Dhruv Joshi', 'dhruv.joshi@example.com', password_hash('User@2026', PASSWORD_BCRYPT), '+91 99090 67890', 'user', 'active', '2026-02-15 14:20:00'],
        ['Pooja Thakkar', 'pooja.thakkar@example.com', password_hash('User@2026', PASSWORD_BCRYPT), '+91 98240 78901', 'user', 'active', '2026-02-18 12:00:00'],
        ['Mehul Trivedi', 'mehul.trivedi@example.com', password_hash('User@2026', PASSWORD_BCRYPT), '+91 94080 89012', 'user', 'active', '2026-02-20 16:45:00'],
        ['Aarti Mehta', 'aarti.mehta@example.com', password_hash('User@2026', PASSWORD_BCRYPT), '+91 99740 90123', 'user', 'active', '2026-02-22 09:15:00'],
    ];

    foreach ($users as $u) {
        $userStmt->execute($u);
    }
    echo "✔ Seeded 9 Indian personas (1 Admin, 2 Attendants, 6 Drivers).\n";

    // 4. Seed Vehicles (Covering all 8 categories with Indian Plates & EV Separation)
    $vehStmt = $db->prepare("INSERT INTO vehicles (user_id, plate_number, vehicle_type, make_model, color, is_ev, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $vehicles = [
        // Dev Patel (id: 4)
        [4, 'GJ-03-ER-4521', 'car', 'Tata Nexon EV Max', 'Empowered White', 1, 1, '2026-02-01 10:10:00'],
        [4, 'GJ-03-AA-9988', 'bike', 'Ather 450X Gen 3', 'Space Grey', 1, 0, '2026-02-05 15:00:00'],
        // Nisha Parmar (id: 5)
        [5, 'GJ-01-BK-8822', 'car', 'Hyundai Creta 1.5 SX', 'Titan Grey', 0, 1, '2026-02-10 11:45:00'],
        [5, 'GJ-01-CY-1001', 'bicycle', 'Hero Lectro C6E (E-Cycle)', 'Matte Blue', 1, 0, '2026-02-12 09:15:00'],
        // Dhruv Joshi (id: 6)
        [6, 'GJ-05-AT-7733', 'auto_rickshaw', 'Bajaj RE EV Auto', 'Yellow & Green', 1, 1, '2026-02-15 14:35:00'],
        [6, 'GJ-05-TR-9000', 'truck', 'Tata Ultra T.7 EV Electric Truck', 'Arctic White', 1, 0, '2026-02-16 11:00:00'],
        // Pooja Thakkar (id: 7)
        [7, 'GJ-06-VN-3344', 'van', 'Maruti Suzuki Eeco Cargo', 'Silky Silver', 0, 1, '2026-02-18 12:10:00'],
        // Mehul Trivedi (id: 8)
        [8, 'GJ-18-BS-5566', 'bus', 'Ashok Leyland Switch EV Coach', 'Emerald Green', 1, 1, '2026-02-20 16:50:00'],
        // Aarti Mehta (id: 9)
        [9, 'GJ-10-OT-1122', 'other', 'Mahindra Zor Grand Electric Carrier', 'Solar Yellow', 1, 1, '2026-02-22 09:20:00'],
    ];
    foreach ($vehicles as $v) {
        $vehStmt->execute($v);
    }
    echo "✔ Seeded 9 vehicles covering all 8 categories (Car, Bike, Bicycle, Auto, Van, Truck, Bus, Other).\n";

    // 5. Seed Facilities across Gujarat & India
    $facStmt = $db->prepare("INSERT INTO facilities 
        (name, code, address, locality, city, district, state, country, postal_code, latitude, longitude, total_capacity, hourly_rate, ev_rate, peak_rate, weekend_rate, overtime_rate_per_hour, grace_period_mins, amenities_json, description, image_url, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $facilities = [
        // 1. Rajkot - Trikon Baug
        [
            'Trikon Baug Smart Multilevel Hub',
            'FAC-RAJ-01',
            'Opposite Jubilee Garden, Trikon Baug Square',
            'Dharmendrasinhji Road',
            'Rajkot',
            'Rajkot',
            'Gujarat',
            'India',
            '360001',
            22.3015000,
            70.8030000,
            36,
            30.00,
            50.00,
            40.00,
            35.00,
            60.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => true, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Premier multi-level automated facility in central Rajkot with 60kW CCS2 DC fast chargers, RFID gate sensors, and CCTV surveillance.',
            'assets/images/facility_metro.jpg',
            'active'
        ],
        // 2. Rajkot - Kalawad Road
        [
            'Kalawad Road Smart Transit Plaza',
            'FAC-RAJ-02',
            'Near Crystal Mall, Kalawad Road',
            'University Area',
            'Rajkot',
            'Rajkot',
            'Gujarat',
            'India',
            '360005',
            22.2856000,
            70.7712000,
            24,
            25.00,
            45.00,
            35.00,
            30.00,
            50.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => false, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Convenient retail and shopping parking adjacent to Crystal Mall with Bharat AC-001 & Type 2 EV chargers.',
            'assets/images/facility_central.jpg',
            'active'
        ],
        // 3. Ahmedabad - Riverfront
        [
            'Sabarmati Riverfront Smart Bay',
            'FAC-AHM-01',
            'Riverfront West Promenade, Near Ellis Bridge',
            'Paldi / Ashram Road',
            'Ahmedabad',
            'Ahmedabad',
            'Gujarat',
            'India',
            '380009',
            23.0276000,
            72.5873000,
            40,
            40.00,
            65.00,
            55.00,
            50.00,
            80.00,
            20,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => true, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Scenic riverfront automated underground garage with smart digital display boards and high-capacity dual CCS2 EV stations.',
            'assets/images/facility_marina.jpg',
            'active'
        ],
        // 4. Ahmedabad - SG Highway
        [
            'SG Highway Cyber Deck',
            'FAC-AHM-02',
            'Plot 45, SG Highway Corporate Corridor',
            'Bodakdev',
            'Ahmedabad',
            'Ahmedabad',
            'Gujarat',
            'India',
            '380054',
            23.0489000,
            72.5186000,
            32,
            35.00,
            60.00,
            50.00,
            40.00,
            70.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => true, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Prime tech corridor automated parking deck serving IT parks, shopping plazas, and corporate headquarters.',
            'assets/images/facility_metro.jpg',
            'active'
        ],
        // 5. Surat - Diamond Bourse
        [
            'Surat Diamond Bourse Central Garage',
            'FAC-SUR-01',
            'Khajod DREAM City Access Road',
            'Khajod',
            'Surat',
            'Surat',
            'Gujarat',
            'India',
            '395007',
            21.1442000,
            72.8311000,
            50,
            35.00,
            60.00,
            45.00,
            40.00,
            70.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => true, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'State-of-the-art multi-tier parking at the worlds largest diamond trading hub with EV fast charging bays.',
            'assets/images/facility_central.jpg',
            'active'
        ],
        // 6. Vadodara - Sayaji
        [
            'Sayaji Smart Terminal Deck',
            'FAC-BDQ-01',
            'Station Road, Near Sayajibaug Garden',
            'Sayajigunj',
            'Vadodara',
            'Vadodara',
            'Gujarat',
            'India',
            '390001',
            22.3072000,
            73.1812000,
            28,
            30.00,
            50.00,
            40.00,
            35.00,
            60.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => false, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Strategically located next to Vadodara Railway Station and MS University with dedicated two-wheeler and EV bays.',
            'assets/images/facility_central.jpg',
            'active'
        ],
        // 7. Gandhinagar - GIFT City
        [
            'GIFT City Financial Hub Deck',
            'FAC-GIFT-01',
            'GIFT One Tower Road, Zone 1',
            'GIFT City',
            'Gandhinagar',
            'Gandhinagar',
            'Gujarat',
            'India',
            '382355',
            23.1610000,
            72.6841000,
            40,
            45.00,
            70.00,
            60.00,
            50.00,
            90.00,
            20,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => true, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'International Financial Tec-City multi-story automated garage with 100% green solar-powered EV superchargers.',
            'assets/images/facility_metro.jpg',
            'active'
        ],
        // 8. Jamnagar - Digjam Circle
        [
            'Jamnagar Digjam Transit Plaza',
            'FAC-JAM-01',
            'Aerodrome Road, Near Digjam Circle',
            'Patel Colony',
            'Jamnagar',
            'Jamnagar',
            'Gujarat',
            'India',
            '361001',
            22.4707000,
            70.0577000,
            20,
            20.00,
            35.00,
            30.00,
            25.00,
            40.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => false, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Secure parking along the main Jamnagar refinery corridor and airport approach road.',
            'assets/images/facility_central.jpg',
            'active'
        ],
        // 9. Bhavnagar - Takhteshwar
        [
            'Takhteshwar Metro Multi-tier Deck',
            'FAC-BHV-01',
            'Takhteshwar Temple Road',
            'Takhteshwar',
            'Bhavnagar',
            'Bhavnagar',
            'Gujarat',
            'India',
            '364001',
            21.7645000,
            72.1519000,
            20,
            20.00,
            35.00,
            30.00,
            25.00,
            40.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => false, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Spacious multi-level deck near Bhavnagar historic center and business district.',
            'assets/images/facility_central.jpg',
            'active'
        ],
        // 10. Junagadh - Girnar Foothills
        [
            'Girnar Foothills Tourist Transit Hub',
            'FAC-JUN-01',
            'Bhavnath Taleti Road, Near Ropeway Base',
            'Bhavnath',
            'Junagadh',
            'Junagadh',
            'Gujarat',
            'India',
            '362001',
            21.5222000,
            70.4579000,
            30,
            25.00,
            40.00,
            35.00,
            30.00,
            50.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => false, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Designated tourist and pilgrim parking for Girnar ropeway, safari, and Bhavnath temples.',
            'assets/images/facility_central.jpg',
            'active'
        ],
        // 11. Morbi - Ceramic Zone
        [
            'Morbi Ceramic Logistics Park',
            'FAC-MRB-01',
            'National Highway 8A, Ceramic Plaza',
            'Lakhdhirpur Road',
            'Morbi',
            'Morbi',
            'Gujarat',
            'India',
            '363642',
            22.8120000,
            70.8378000,
            25,
            20.00,
            35.00,
            30.00,
            25.00,
            40.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => false, 'covered' => false, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Heavy vehicle, truck, van, and car parking facility in the heart of Morbis ceramic manufacturing cluster.',
            'assets/images/facility_metro.jpg',
            'active'
        ],
        // 12. Anand - Milk City
        [
            'Anand Milk City Plaza Parking',
            'FAC-AND-01',
            'Amul Dairy Road, Near Anand Railway Station',
            'Gamdi',
            'Anand',
            'Anand',
            'Gujarat',
            'India',
            '388001',
            22.5645000,
            72.9289000,
            22,
            20.00,
            35.00,
            30.00,
            25.00,
            40.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => false, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Convenient parking next to Amul Dairy campus, Vallabh Vidyanagar educational hub, and rail terminus.',
            'assets/images/facility_central.jpg',
            'active'
        ],
        // 13. Mumbai - BKC
        [
            'Mumbai BKC Financial Hub Garage',
            'FAC-BOM-01',
            'G Block, Bandra Kurla Complex',
            'Bandra East',
            'Mumbai',
            'Mumbai Suburban',
            'Maharashtra',
            'India',
            '400051',
            19.0657000,
            72.8687000,
            45,
            60.00,
            90.00,
            80.00,
            70.00,
            120.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => true, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Premier financial district multi-level parking with automated valet and 150kW ultra-fast DC chargers.',
            'assets/images/facility_metro.jpg',
            'active'
        ],
        // 14. Bengaluru - Indiranagar
        [
            'Bengaluru Indiranagar Tech Deck',
            'FAC-BLR-01',
            '100 Feet Road, HAL 2nd Stage',
            'Indiranagar',
            'Bengaluru',
            'Bengaluru Urban',
            'Karnataka',
            'India',
            '560038',
            12.9784000,
            77.6408000,
            35,
            50.00,
            75.00,
            70.00,
            60.00,
            100.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => true, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Commercial and dining district smart parking garage with IoT bay sensors and Type 2 EV chargers.',
            'assets/images/facility_central.jpg',
            'active'
        ],
        // 15. Delhi - Connaught Place
        [
            'Delhi Connaught Place Central Hub',
            'FAC-DEL-01',
            'Block C, Inner Circle, Connaught Place',
            'Connaught Place',
            'Delhi',
            'New Delhi',
            'Delhi (NCT)',
            'India',
            '110001',
            28.6315000,
            77.2167000,
            40,
            50.00,
            80.00,
            75.00,
            65.00,
            100.00,
            15,
            json_encode(['ev_charging' => true, 'cctv' => true, 'valet' => true, 'covered' => true, 'handicap' => true, 'restrooms' => true, 'open_24_7' => true]),
            'Heritage underground automated parking beneath Connaught Place with seamless metro interchange access.',
            'assets/images/facility_airport.jpg',
            'active'
        ],
    ];

    foreach ($facilities as $f) {
        $facStmt->execute($f);
    }
    echo "✔ Seeded 15 verified parking facilities across Gujarat (12) and India metros (3).\n";

    // 6. Seed Dynamic Pricing Rules per Category
    $ruleStmt = $db->prepare("INSERT INTO pricing_rules (facility_id, vehicle_type, rule_name, base_hourly_rate, peak_hourly_rate, weekend_hourly_rate, ev_surcharge_per_hour, min_booking_hours, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    for ($facId = 1; $facId <= 15; $facId++) {
        $base = ($facId === 13) ? 60.0 : (($facId === 14 || $facId === 15) ? 50.0 : (($facId === 3 || $facId === 7) ? 40.0 : 30.0));
        
        $ruleStmt->execute([$facId, 'all', 'Standard General Base Tariff', $base, $base * 1.3, $base * 1.2, 20.00, 1, 1]);
        $ruleStmt->execute([$facId, 'car', 'Standard Car / Sedan Tariff', $base, $base * 1.3, $base * 1.2, 20.00, 1, 1]);
        $ruleStmt->execute([$facId, 'bike', 'Two-Wheeler / Bike Tariff', round($base * 0.4, 2), round($base * 0.5, 2), round($base * 0.45, 2), 10.00, 1, 1]);
        $ruleStmt->execute([$facId, 'bicycle', 'Cycle / Bicycle Eco Tariff', round($base * 0.25, 2), round($base * 0.3, 2), round($base * 0.25, 2), 5.00, 1, 1]);
        $ruleStmt->execute([$facId, 'auto_rickshaw', 'Auto-Rickshaw Tariff', round($base * 0.6, 2), round($base * 0.75, 2), round($base * 0.7, 2), 15.00, 1, 1]);
        $ruleStmt->execute([$facId, 'van', 'Van / Minivan Commercial Tariff', round($base * 1.4, 2), round($base * 1.7, 2), round($base * 1.5, 2), 25.00, 1, 1]);
        $ruleStmt->execute([$facId, 'truck', 'Heavy Truck Cargo Tariff', round($base * 2.2, 2), round($base * 2.6, 2), round($base * 2.4, 2), 35.00, 2, 1]);
        $ruleStmt->execute([$facId, 'bus', 'Passenger Bus / Coach Tariff', round($base * 2.4, 2), round($base * 2.8, 2), round($base * 2.6, 2), 40.00, 2, 1]);
        $ruleStmt->execute([$facId, 'other', 'Special Equipment Tariff', round($base * 1.5, 2), round($base * 1.8, 2), round($base * 1.6, 2), 20.00, 1, 1]);
    }
    echo "✔ Seeded category-specific pricing rules for all 15 facilities.\n";

    // 7. Seed Multi-Category Slots for Facility 1 (Trikon Baug, Rajkot) and Facility 3 (Sabarmati, Ahmedabad)
    $slotStmt = $db->prepare("INSERT INTO slots 
        (facility_id, floor_level, zone_name, slot_number, slot_type, allowed_vehicle_types, slot_dimensions, is_ev_charging, ev_charger_type, ev_charging_rate, is_accessible, is_active, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Facility 1: Rajkot Trikon Baug (36 slots)
    // Floor 1: Zone A (12 slots - Cars & EV)
    for ($i = 1; $i <= 12; $i++) {
        $slotNo = sprintf("A-1%02d", $i);
        $isEv = ($i <= 4) ? 1 : 0;
        $chargerType = $isEv ? 'CCS2 DC' : 'None';
        $chargerRate = $isEv ? 20.00 : 0.00;
        $isAcc = ($i === 5) ? 1 : 0;
        $slotStmt->execute([1, 'Floor 1', 'Zone A', $slotNo, 'car', 'car,van,auto_rickshaw', '5.0m x 2.4m', $isEv, $chargerType, $chargerRate, $isAcc, 1, 'available']);
    }
    // Floor 2: Zone B (12 slots - Two Wheelers, Bicycles & Autos)
    for ($i = 1; $i <= 12; $i++) {
        $slotNo = sprintf("B-2%02d", $i);
        $type = ($i <= 6) ? 'bike' : (($i <= 9) ? 'bicycle' : 'auto_rickshaw');
        $allowed = ($type === 'bike') ? 'bike' : (($type === 'bicycle') ? 'bicycle' : 'auto_rickshaw');
        $dims = ($type === 'bike') ? '2.2m x 1.0m' : (($type === 'bicycle') ? '1.8m x 0.6m' : '3.0m x 1.5m');
        $isEv = ($i <= 2 || $i === 10) ? 1 : 0;
        $chargerType = $isEv ? 'Bharat AC-001' : 'None';
        $chargerRate = $isEv ? 10.00 : 0.00;
        $slotStmt->execute([1, 'Floor 2', 'Zone B', $slotNo, $type, $allowed, $dims, $isEv, $chargerType, $chargerRate, 0, 1, 'available']);
    }
    // Floor 3: Zone C (12 slots - Commercial Vans, Trucks & Buses)
    for ($i = 1; $i <= 12; $i++) {
        $slotNo = sprintf("C-3%02d", $i);
        $type = ($i <= 6) ? 'van' : (($i <= 9) ? 'truck' : 'bus');
        $allowed = ($type === 'van') ? 'van,car' : (($type === 'truck') ? 'truck' : 'bus');
        $dims = ($type === 'van') ? '6.0m x 2.6m' : (($type === 'truck') ? '10.0m x 3.0m' : '12.0m x 3.2m');
        $isEv = ($i <= 2) ? 1 : 0;
        $chargerType = $isEv ? 'Type 2 AC' : 'None';
        $chargerRate = $isEv ? 25.00 : 0.00;
        $slotStmt->execute([1, 'Floor 3', 'Zone C', $slotNo, $type, $allowed, $dims, $isEv, $chargerType, $chargerRate, 0, 1, 'available']);
    }

    // Seed remaining facilities with representative slots
    for ($f = 2; $f <= 15; $f++) {
        // 10 Car slots (2 EV)
        for ($s = 1; $s <= 10; $s++) {
            $isEv = ($s <= 2) ? 1 : 0;
            $charger = $isEv ? 'Type 2 AC' : 'None';
            $slotStmt->execute([$f, 'Ground Floor', 'Car Bay', sprintf("C-%02d", $s), 'car', 'car,van', '5.0m x 2.4m', $isEv, $charger, $isEv ? 20.00 : 0.00, ($s === 3) ? 1 : 0, 1, 'available']);
        }
        // 6 Bike slots (1 EV)
        for ($s = 1; $s <= 6; $s++) {
            $isEv = ($s === 1) ? 1 : 0;
            $charger = $isEv ? '15A 3-Pin' : 'None';
            $slotStmt->execute([$f, 'Ground Floor', 'Two Wheeler Bay', sprintf("BK-%02d", $s), 'bike', 'bike', '2.2m x 1.0m', $isEv, $charger, $isEv ? 10.00 : 0.00, 0, 1, 'available']);
        }
        // 4 Bicycle slots
        for ($s = 1; $s <= 4; $s++) {
            $slotStmt->execute([$f, 'Ground Floor', 'Cycle Bay', sprintf("CY-%02d", $s), 'bicycle', 'bicycle', '1.8m x 0.6m', 0, 'None', 0.00, 0, 1, 'available']);
        }
        // 2 Auto slots
        for ($s = 1; $s <= 2; $s++) {
            $slotStmt->execute([$f, 'Ground Floor', 'Auto Bay', sprintf("AU-%02d", $s), 'auto_rickshaw', 'auto_rickshaw', '3.0m x 1.5m', 0, 'None', 0.00, 0, 1, 'available']);
        }
        // 2 Truck / Bus slots
        $slotStmt->execute([$f, 'Logistics Bay', 'Heavy Bay', 'TR-01', 'truck', 'truck', '10.0m x 3.0m', 0, 'None', 0.00, 0, 1, 'available']);
        $slotStmt->execute([$f, 'Logistics Bay', 'Heavy Bay', 'BS-01', 'bus', 'bus', '12.0m x 3.2m', 0, 'None', 0.00, 0, 1, 'available']);
    }

    echo "✔ Seeded 372 slots configured with specific dimensions, vehicle compatibility, and EV chargers.\n";

    // 8. Seed Attendant Assignments
    $attStmt = $db->prepare("INSERT INTO attendant_assignments (attendant_id, facility_id, shift_name, is_active) VALUES (?, ?, ?, ?)");
    $attStmt->execute([2, 1, 'Morning Shift (07:00 - 15:00)', 1]); // Krunal Solanki at Rajkot Trikon Baug
    $attStmt->execute([3, 3, 'Day Express Shift (08:00 - 16:00)', 1]); // Hetal Shah at Sabarmati Riverfront
    echo "✔ Assigned attendants Krunal Solanki (Rajkot) and Hetal Shah (Ahmedabad).\n";

    // 9. Seed Reservations, QR Passes, Parking Sessions & Payments
    $resStmt = $db->prepare("INSERT INTO reservations 
        (booking_reference, user_id, facility_id, slot_id, vehicle_id, vehicle_plate, vehicle_type, is_ev, ev_charging_requested, start_time, end_time, total_hours, base_rate, base_amount, extra_amount, discount_amount, total_amount, status, qr_token, qr_token_hash, check_in_time, check_out_time, checked_in_by, checked_out_by, actual_hours, overtime_amount, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $qrPassStmt = $db->prepare("INSERT INTO qr_passes (reservation_id, pass_code, pass_token, token_hash, status, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $sessionStmt = $db->prepare("INSERT INTO parking_sessions (reservation_id, facility_id, slot_id, vehicle_plate, check_in_time, check_out_time, checked_in_by, checked_out_by, total_duration_hours, overtime_duration_minutes, overtime_charges, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $payStmt = $db->prepare("INSERT INTO payments (reservation_id, user_id, transaction_ref, payment_method, amount, status, payment_type, gateway_provider, simulated_meta, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Reservation 1: Active Checked-In (Dev Patel at Rajkot Trikon Baug Slot A-101)
    $token1 = 'PKPASS_7a8f90b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9';
    $hash1 = hash_hmac('sha256', $token1, env('QR_SECRET_SALT', 'default_salt_2026_smart_parking'));
    $start1 = date('Y-m-d H:i:s', strtotime('-1 hour 30 minutes'));
    $end1 = date('Y-m-d H:i:s', strtotime('+2 hours 30 minutes'));
    
    $resStmt->execute([
        'PK-2026-90812',
        4, // Dev Patel
        1, // Rajkot Trikon Baug
        1, // Slot A-101 (EV)
        1, // Tata Nexon EV Max
        'GJ-03-ER-4521',
        'car',
        1,
        1,
        $start1,
        $end1,
        4.00,
        50.00,
        200.00,
        0.00,
        0.00,
        200.00,
        'checked_in',
        $token1,
        $hash1,
        $start1,
        null,
        2, // Krunal Solanki
        null,
        null,
        0.00,
        date('Y-m-d H:i:s', strtotime('-2 hours'))
    ]);
    $res1Id = (int)$db->lastInsertId();
    $db->exec("UPDATE slots SET status = 'occupied' WHERE id = 1");

    $qrPassStmt->execute([$res1Id, 'PASS-2026-90812', $token1, $hash1, 'used', $end1, date('Y-m-d H:i:s', strtotime('-2 hours'))]);
    $sessionStmt->execute([$res1Id, 1, 1, 'GJ-03-ER-4521', $start1, null, 2, null, null, 0, 0.00, 'active', $start1]);

    $payStmt->execute([
        $res1Id,
        4,
        'TXN_UPI_2026020101',
        'upi',
        200.00,
        'success',
        'reservation_fee',
        'Simulated UPI FastPay (NPCI/BHIM)',
        json_encode(['upi_id' => 'dev.patel@okaxis', 'rrn' => 'UPI9928172601', 'currency' => 'INR']),
        date('Y-m-d H:i:s', strtotime('-2 hours'))
    ]);

    // Reservation 2: Upcoming Confirmed (Nisha Parmar at Ahmedabad Sabarmati Riverfront)
    $token2 = 'PKPASS_1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c';
    $hash2 = hash_hmac('sha256', $token2, env('QR_SECRET_SALT', 'default_salt_2026_smart_parking'));
    $start2 = date('Y-m-d H:i:s', strtotime('+3 hours'));
    $end2 = date('Y-m-d H:i:s', strtotime('+6 hours'));

    $resStmt->execute([
        'PK-2026-90813',
        5, // Nisha Parmar
        3, // Ahmedabad Sabarmati Riverfront
        40, // Slot C-01
        3, // Hyundai Creta
        'GJ-01-BK-8822',
        'car',
        0,
        0,
        $start2,
        $end2,
        3.00,
        40.00,
        120.00,
        0.00,
        0.00,
        120.00,
        'confirmed',
        $token2,
        $hash2,
        null,
        null,
        null,
        null,
        null,
        0.00,
        date('Y-m-d H:i:s', strtotime('-30 minutes'))
    ]);
    $res2Id = (int)$db->lastInsertId();
    $qrPassStmt->execute([$res2Id, 'PASS-2026-90813', $token2, $hash2, 'active', $end2, date('Y-m-d H:i:s', strtotime('-30 minutes'))]);

    $payStmt->execute([
        $res2Id,
        5,
        'TXN_CARD_2026020102',
        'card',
        120.00,
        'success',
        'reservation_fee',
        'Simulated RuPay / Visa Gateway',
        json_encode(['card_last4' => '8822', 'brand' => 'RuPay', 'currency' => 'INR']),
        date('Y-m-d H:i:s', strtotime('-30 minutes'))
    ]);

    // Reservation 3: Past Completed (Dhruv Joshi at Rajkot Trikon Baug - Auto-Rickshaw)
    $token3 = 'PKPASS_3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d';
    $hash3 = hash_hmac('sha256', $token3, env('QR_SECRET_SALT', 'default_salt_2026_smart_parking'));
    $start3 = date('Y-m-d H:i:s', strtotime('-2 days 10:00:00'));
    $end3 = date('Y-m-d H:i:s', strtotime('-2 days 14:00:00'));

    $resStmt->execute([
        'PK-2026-90805',
        6, // Dhruv Joshi
        1, // Rajkot Trikon Baug
        22, // Slot B-210 (Auto Bay)
        5, // Bajaj RE EV Auto
        'GJ-05-AT-7733',
        'auto_rickshaw',
        1,
        1,
        $start3,
        $end3,
        4.00,
        25.00,
        100.00,
        0.00,
        0.00,
        100.00,
        'completed',
        $token3,
        $hash3,
        $start3,
        $end3,
        2, // Krunal Solanki
        2, // Krunal Solanki
        4.00,
        0.00,
        date('Y-m-d H:i:s', strtotime('-3 days'))
    ]);
    $res3Id = (int)$db->lastInsertId();
    $qrPassStmt->execute([$res3Id, 'PASS-2026-90805', $token3, $hash3, 'used', $end3, date('Y-m-d H:i:s', strtotime('-3 days'))]);
    $sessionStmt->execute([$res3Id, 1, 22, 'GJ-05-AT-7733', $start3, $end3, 2, 2, 4.00, 0, 0.00, 'completed', $start3]);

    $payStmt->execute([
        $res3Id,
        6,
        'TXN_UPI_2026012801',
        'upi',
        100.00,
        'success',
        'reservation_fee',
        'Simulated UPI FastPay',
        json_encode(['upi_id' => 'dhruv@okicici', 'currency' => 'INR']),
        date('Y-m-d H:i:s', strtotime('-3 days'))
    ]);

    echo "✔ Seeded multi-state Indian reservations, QR passes, parking sessions & ₹ INR payments.\n";

    // 10. Seed Audit Logs
    $auditStmt = $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $auditStmt->execute([1, 'SYSTEM_INITIALIZATION', 'system', null, 'System database migrated to India/Gujarat edition with 8 vehicle categories and full state list.', '127.0.0.1', 'CLI Seeder/8.2', date('Y-m-d H:i:s', strtotime('-5 days'))]);
    $auditStmt->execute([2, 'CHECK_IN_PROCESSED', 'reservation', $res1Id, 'Attendant Krunal Solanki verified QR pass and admitted vehicle GJ-03-ER-4521 at Rajkot Trikon Baug.', '127.0.0.1', 'Attendant Mobile/Chrome', $start1]);
    $auditStmt->execute([2, 'CHECK_OUT_PROCESSED', 'reservation', $res3Id, 'Attendant Krunal Solanki completed check-out for vehicle GJ-05-AT-7733.', '127.0.0.1', 'Attendant Desktop/Firefox', $end3]);

    echo "✔ Seeded audit log trail.\n";

    echo "\n>>> Gujarat & India Database migration and seeding completed successfully! <<<\n";
    echo "---------------------------------------------------------\n";
    echo "Demo Credentials (Password for all accounts: Admin@2026 / Attendant@2026 / User@2026):\n";
    echo "• Admin:     admin@parkpulse.com               (Jignesh Patel)\n";
    echo "• Attendant: attendant.rajkot@parkpulse.com    (Krunal Solanki - Rajkot)\n";
    echo "• Attendant: attendant.ahmedabad@parkpulse.com (Hetal Shah - Ahmedabad)\n";
    echo "• Driver 1:  dev.patel@example.com             (Dev Patel - Tata Nexon EV)\n";
    echo "• Driver 2:  nisha.parmar@example.com          (Nisha Parmar - Hyundai Creta)\n";
    echo "• Driver 3:  dhruv.joshi@example.com           (Dhruv Joshi - EV Auto-Rickshaw)\n";
    echo "---------------------------------------------------------\n";

} catch (\Throwable $e) {
    echo "❌ Error during seeding: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
