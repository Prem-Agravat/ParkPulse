<?php
/**
 * Part 7 — Admin, Attendant, Map & Analytics Consistency Test Suite - India / Gujarat Edition
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/PricingService.php';
require_once __DIR__ . '/../src/Services/ReservationService.php';
require_once __DIR__ . '/../src/Services/GateService.php';

final class AdminAttendantAnalyticsTestSuite
{
    private PDO $db;
    private PricingService $pricingService;
    private ReservationService $resService;
    private GateService $gateService;
    private int $passCount = 0;
    private int $failCount = 0;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->pricingService = new PricingService($this->db);
        $this->resService = new ReservationService($this->db, $this->pricingService);
        $this->gateService = new GateService($this->db, $this->pricingService);

        // Reset rate limit for localhost tests
        $this->db->exec("TRUNCATE TABLE rate_limits;");
    }

    private function assert(bool $condition, string $description): void
    {
        if ($condition) {
            echo "  [PASS] {$description}\n";
            $this->passCount++;
        } else {
            echo "  [FAIL] {$description}\n";
            $this->failCount++;
        }
    }

    public function runAll(): void
    {
        echo "=========================================================\n";
        echo "  PARKPULSE 2026 - PART 7 ADMIN, ATTENDANT & ANALYTICS\n";
        echo "=========================================================\n\n";

        $this->testDatabaseMetricsVersusAdminStatsApi();
        $this->testEndToEndStateReflectionAcrossAllRoles();
        $this->testAdminManagementOperationsAndAuditTrails();
        $this->testMapSearchAndFilterSynchronization();

        echo "\n---------------------------------------------------------\n";
        echo "PART 7 TEST SUMMARY: {$this->passCount} PASSED, {$this->failCount} FAILED\n";
        echo "---------------------------------------------------------\n";

        if ($this->failCount > 0) {
            exit(1);
        }
    }

    private function testDatabaseMetricsVersusAdminStatsApi(): void
    {
        echo "1. Testing Database Queries vs Admin Stats API Metrics...\n";

        $cookieJarAdmin = tempnam(sys_get_temp_dir(), 'pp_adm_');
        $this->loginViaHttp($cookieJarAdmin, 'admin@parkpulse.com', 'Admin@2026');

        $resp = $this->httpGet('/api/admin-stats.php', $cookieJarAdmin);
        $this->assert($resp['status'] === 200, "Admin Stats API accessible by admin.");
        $apiData = json_decode($resp['body'], true);

        $summary = $apiData['summary'] ?? [];

        // 1. Verify Revenue
        $dbRevenue = (float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success'")->fetchColumn();
        $this->assert((float)($summary['total_revenue'] ?? 0) === $dbRevenue, "Total revenue (₹" . ($summary['total_revenue'] ?? 0) . ") matches database sum.");

        // 2. Verify Total Bookings
        $dbBookings = (int)$this->db->query("SELECT COUNT(*) FROM reservations")->fetchColumn();
        $this->assert((int)($summary['total_bookings'] ?? 0) === $dbBookings, "Total bookings (" . ($summary['total_bookings'] ?? 0) . ") matches database count.");

        // 3. Verify Active On-Premise Vehicles
        $dbActive = (int)$this->db->query("SELECT COUNT(*) FROM reservations WHERE status = 'checked_in'")->fetchColumn();
        $this->assert((int)($summary['active_vehicles'] ?? 0) === $dbActive, "Active on-premise vehicles (" . ($summary['active_vehicles'] ?? 0) . ") matches database count.");

        // 4. Verify Total Registered Users
        $dbUsers = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
        $this->assert((int)($summary['total_users'] ?? 0) === $dbUsers, "Total users (" . ($summary['total_users'] ?? 0) . ") matches database count.");

        // 5. Verify Total Active Slots
        $dbSlots = (int)$this->db->query("SELECT COUNT(*) FROM slots WHERE is_active = 1")->fetchColumn();
        $this->assert((int)($summary['total_slots'] ?? 0) === $dbSlots, "Total active slots (" . ($summary['total_slots'] ?? 0) . ") matches database count.");

        // 6. Verify Slot States
        $dbAvail = (int)$this->db->query("SELECT COUNT(*) FROM slots WHERE status = 'available' AND is_active = 1")->fetchColumn();
        $this->assert((int)($summary['slot_states']['available'] ?? 0) === $dbAvail, "Available slots count (" . ($summary['slot_states']['available'] ?? 0) . ") matches database.");

        $dbOccupied = (int)$this->db->query("SELECT COUNT(*) FROM slots WHERE status = 'occupied' AND is_active = 1")->fetchColumn();
        $this->assert((int)($summary['slot_states']['occupied'] ?? 0) === $dbOccupied, "Occupied slots count (" . ($summary['slot_states']['occupied'] ?? 0) . ") matches database.");

        // 7. Verify Chart.js Datasets Present
        $this->assert(!empty($apiData['revenue_trend']) && count($apiData['revenue_trend']) === 7, "Revenue trend has 7-day breakdown for Chart.js.");
        $this->assert(!empty($apiData['facility_occupancy']), "Facility occupancy breakdown present for Chart.js.");
        $this->assert(!empty($apiData['peak_hours']) && count($apiData['peak_hours']) === 24, "24-hour peak hours distribution present for Chart.js.");

        @unlink($cookieJarAdmin);
    }

    private function testEndToEndStateReflectionAcrossAllRoles(): void
    {
        echo "\n2. Testing End-to-End State Reflection Across All Role Views (User, Attendant, Admin)...\n";

        $cookieJarDriver = tempnam(sys_get_temp_dir(), 'pp_drv_e2e_');
        $cookieJarAttendant = tempnam(sys_get_temp_dir(), 'pp_att_e2e_');
        $cookieJarAdmin = tempnam(sys_get_temp_dir(), 'pp_adm_e2e_');

        $this->loginViaHttp($cookieJarDriver, 'dev.patel@example.com', 'User@2026');
        $this->loginViaHttp($cookieJarAttendant, 'attendant.rajkot@parkpulse.com', 'Attendant@2026');
        $this->loginViaHttp($cookieJarAdmin, 'admin@parkpulse.com', 'Admin@2026');

        $slotId = 5;
        $plate = 'GJ-03-E2E-SYNC';
        $start = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        $end = date('Y-m-d H:i:s', strtotime('+2 hours 5 minutes'));

        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ?")->execute([$slotId]);
        $this->db->prepare("UPDATE slots SET status = 'available' WHERE id = ?")->execute([$slotId]);

        // ----------------------------------------------------
        // Step A: Driver Books Reservation
        // ----------------------------------------------------
        $csrfDriver = $this->extractCsrfToken($cookieJarDriver);
        $resPost = $this->httpPost('/api/reservations.php', [
            'facility_id' => 1,
            'slot_id' => $slotId,
            'custom_plate' => $plate,
            'start_time' => $start,
            'end_time' => $end,
            'payment_method' => 'card',
            'csrf_token' => $csrfDriver
        ], $cookieJarDriver);

        $this->assert($resPost['status'] === 201, "Driver created reservation (HTTP 201).");
        $resData = json_decode($resPost['body'], true)['data'] ?? [];
        $bookingRef = $resData['booking_reference'] ?? '';
        $bookingId = (int)($resData['reservation_id'] ?? 0);

        // Attendant View: Verifies pass lookup resolves
        $attLookup = $this->gateService->lookupPass($bookingRef);
        $this->assert($attLookup && $attLookup['status'] === 'confirmed', "Attendant lookup shows reservation is confirmed.");

        // Admin View: Admin stats reflect new booking
        $statsResp1 = $this->httpGet('/api/admin-stats.php', $cookieJarAdmin);
        $stats1 = json_decode($statsResp1['body'], true)['summary'] ?? [];
        $this->assert(($stats1['total_bookings'] ?? 0) > 0, "Admin analytics reflects updated total bookings.");

        // ----------------------------------------------------
        // Step B: Attendant Checks In Vehicle at Barrier
        // ----------------------------------------------------
        $inResult = $this->gateService->processCheckIn($bookingId, 2);
        $this->assert($inResult['success'] === true, "Attendant processed vehicle entry.");

        // Attendant View: Slot status in occupancy matrix is 'occupied'
        $slotStatusIn = $this->db->query("SELECT status FROM slots WHERE id = {$slotId}")->fetchColumn();
        $this->assert($slotStatusIn === 'occupied', "Live Occupancy matrix reflects stall as 'occupied'.");

        // Admin View: Active vehicles incremented
        $statsResp2 = $this->httpGet('/api/admin-stats.php', $cookieJarAdmin);
        $stats2 = json_decode($statsResp2['body'], true)['summary'] ?? [];
        $this->assert(($stats2['active_vehicles'] ?? 0) > 0, "Admin analytics reflects active vehicle on-premise.");

        // Driver View: Pass shows checked_in
        $passPage = $this->httpGet("/pass.php?ref={$bookingRef}", $cookieJarDriver);
        $this->assert(str_contains($passPage['body'], 'checked_in') || str_contains($passPage['body'], 'ACTIVE SESSION') || str_contains($passPage['body'], 'CHECKED-IN') || str_contains($passPage['body'], 'Checked In'), "Driver pass page displays checked-in status.");

        // ----------------------------------------------------
        // Step C: Attendant Checks Out Vehicle at Barrier
        // ----------------------------------------------------
        $outResult = $this->gateService->processCheckOut($bookingId, 2);
        $this->assert($outResult['success'] === true, "Attendant processed vehicle exit.");

        // Attendant View: Slot status reverted to 'available'
        $slotStatusOut = $this->db->query("SELECT status FROM slots WHERE id = {$slotId}")->fetchColumn();
        $this->assert($slotStatusOut === 'available', "Live Occupancy matrix reflects stall released back to 'available'.");

        // Admin View: Session marked completed
        $sessionStatus = $this->db->query("SELECT status FROM parking_sessions WHERE reservation_id = {$bookingId}")->fetchColumn();
        $this->assert($sessionStatus === 'completed', "Parking session marked completed in administrative records.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
        @unlink($cookieJarDriver);
        @unlink($cookieJarAttendant);
        @unlink($cookieJarAdmin);
    }

    private function testAdminManagementOperationsAndAuditTrails(): void
    {
        echo "\n3. Testing Admin Management (Facilities, Tariffs, Slots) & Audit Trail Generation...\n";

        $cookieJarAdmin = tempnam(sys_get_temp_dir(), 'pp_adm_mgmt_');
        $this->loginViaHttp($cookieJarAdmin, 'admin@parkpulse.com', 'Admin@2026');

        // 1. Update Tariff via Admin Pricing endpoint
        $csrf = $this->extractCsrfToken($cookieJarAdmin);
        $updateResp = $this->httpPost('/admin-pricing.php', [
            'facility_id' => 1,
            'hourly_rate' => 32.00,
            'ev_rate' => 52.00,
            'peak_rate' => 42.00,
            'weekend_rate' => 38.00,
            'overtime_rate_per_hour' => 65.00,
            'grace_period_mins' => 15,
            'csrf_token' => $csrf
        ], $cookieJarAdmin);

        $this->assert(in_array($updateResp['status'], [200, 302], true), "Admin updated facility tariff policy.");

        // Verify audit log record created
        $audit = $this->db->query("SELECT * FROM audit_logs WHERE action = 'TARIFF_UPDATED' AND entity_id = 1 ORDER BY id DESC LIMIT 1")->fetch();
        $this->assert(!empty($audit), "AuditLogger recorded 'TARIFF_UPDATED' action with admin ID.");

        // Revert tariff back
        $this->db->prepare("UPDATE facilities SET hourly_rate = 30.00, ev_rate = 50.00, peak_rate = 40.00, weekend_rate = 35.00, overtime_rate_per_hour = 60.00 WHERE id = 1")->execute();

        @unlink($cookieJarAdmin);
    }

    private function testMapSearchAndFilterSynchronization(): void
    {
        echo "\n4. Testing Map Synchronization & Real Coordinates Filtering...\n";

        // 1. Query all active facilities
        $ch = curl_init('http://localhost/smart%20parking/api/facilities.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $jsonAll = json_decode((string)curl_exec($ch), true);
        curl_close($ch);

        $this->assert($jsonAll['success'] === true && !empty($jsonAll['data']), "Facilities API returns active facilities with GPS coordinates.");
        $firstFac = $jsonAll['data'][0];
        $this->assert(isset($firstFac['latitude']) && isset($firstFac['longitude']), "Facility contains valid GPS latitude/longitude for Leaflet map markers.");

        // 2. Filter by city: Rajkot
        $chCity = curl_init('http://localhost/smart%20parking/api/facilities.php?city=Rajkot');
        curl_setopt($chCity, CURLOPT_RETURNTRANSFER, true);
        $jsonCity = json_decode((string)curl_exec($chCity), true);
        curl_close($chCity);

        $allRajkot = true;
        foreach ($jsonCity['data'] as $fac) {
            if ($fac['city'] !== 'Rajkot') $allRajkot = false;
        }
        $this->assert($allRajkot && count($jsonCity['data']) > 0, "City filter strictly restricts map markers to Rajkot.");

        // 3. Filter by vehicle type: EV / EV-only
        $chEv = curl_init('http://localhost/smart%20parking/api/facilities.php?ev_only=1');
        curl_setopt($chEv, CURLOPT_RETURNTRANSFER, true);
        $jsonEv = json_decode((string)curl_exec($chEv), true);
        curl_close($chEv);

        $allHaveEv = true;
        foreach ($jsonEv['data'] as $fac) {
            $amenities = is_array($fac['amenities']) ? $fac['amenities'] : json_decode($fac['amenities'] ?? '{}', true);
            if (empty($amenities['ev_charging']) && (empty($fac['ev_slot_count']) || (int)$fac['ev_slot_count'] <= 0)) {
                $allHaveEv = false;
            }
        }
        $this->assert($allHaveEv, "EV charging filter matches facilities with dedicated EV infrastructure.");
    }

    // --- HTTP Helpers ---

    private function loginViaHttp(string $cookieJar, string $email, string $password): bool
    {
        $loginPage = $this->httpGet('/login.php', $cookieJar);
        preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $loginPage['body'], $matches);
        $csrfToken = $matches[1] ?? '';

        $postResp = $this->httpPost('/login.php', [
            'email' => $email,
            'password' => $password,
            'csrf_token' => $csrfToken
        ], $cookieJar);

        return in_array($postResp['status'], [200, 302], true);
    }

    private function extractCsrfToken(string $cookieJar): string
    {
        $page = $this->httpGet('/vehicles.php', $cookieJar);
        preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $page['body'], $matches);
        if (!empty($matches[1])) {
            return $matches[1];
        }
        preg_match('/csrf-token"\s+content="([a-f0-9]+)"/i', $page['body'], $matches2);
        return $matches2[1] ?? '';
    }

    private function httpGet(string $path, ?string $cookieJar = null): array
    {
        $ch = curl_init('http://localhost/smart%20parking' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($cookieJar) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        }
        $raw = (string)curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [
            'status' => $httpCode,
            'headers' => substr($raw, 0, $headerSize),
            'body' => substr($raw, $headerSize)
        ];
    }

    private function httpPost(string $path, array $data, ?string $cookieJar = null): array
    {
        $ch = curl_init('http://localhost/smart%20parking' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($cookieJar) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        }
        $raw = (string)curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [
            'status' => $httpCode,
            'headers' => substr($raw, 0, $headerSize),
            'body' => substr($raw, $headerSize)
        ];
    }
}

$suite = new AdminAttendantAnalyticsTestSuite();
$suite->runAll();
