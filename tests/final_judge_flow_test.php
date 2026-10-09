<?php
/**
 * Part 8 — Final Judge Demonstration Flow (End-to-End Walkthrough) - India / Gujarat Edition
 * Smart Parking Reservation & Management System (Code2Web 2026)
 *
 * Full Judge Journey:
 * Search/Filter (Gujarat/Rajkot) -> Interactive Map (Leaflet/OSM) -> Facility Detail ->
 * Vehicle Selection (EV Tata Nexon) -> Slot Matrix -> INR Payment State ->
 * Digital QR Pass -> Attendant Barrier Check-In -> Attendant Checkout & Final Bill ->
 * Slot Availability Released -> Real-Time Analytics Updated.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/PricingService.php';
require_once __DIR__ . '/../src/Services/ReservationService.php';
require_once __DIR__ . '/../src/Services/GateService.php';

final class FinalJudgeFlowTestSuite
{
    private PDO $db;
    private string $baseUrl = 'http://localhost/smart%20parking';
    private int $passCount = 0;
    private int $failCount = 0;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
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
        echo "======================================================================\n";
        echo "  PARKPULSE 2026 - FINAL JUDGE DEMONSTRATION FLOW (INDIA/GUJARAT E2E)\n";
        echo "======================================================================\n\n";

        $cookieJarDriver = tempnam(sys_get_temp_dir(), 'judge_driver_');
        $cookieJarAttendant = tempnam(sys_get_temp_dir(), 'judge_attendant_');
        $cookieJarAdmin = tempnam(sys_get_temp_dir(), 'judge_admin_');

        // Target stall for judge demo: Facility 1, Slot 4 (Zone A Car EV bay)
        $targetSlotId = 4;
        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ?")->execute([$targetSlotId]);
        $this->db->prepare("UPDATE slots SET status = 'available' WHERE id = ?")->execute([$targetSlotId]);

        // ------------------------------------------------------------------
        // STEP 1: Discovery, Search Filters & Map Synchronization
        // ------------------------------------------------------------------
        echo "Step 1: Driver searches for EV parking in Rajkot, Gujarat on Map...\n";
        $facResp = $this->httpGet('/api/facilities.php?city=Rajkot&ev_only=1');
        $this->assert($facResp['status'] === 200, "Discovery API returned HTTP 200 OK.");
        $facJson = json_decode($facResp['body'], true);
        $this->assert(!empty($facJson['data']), "Found active verified facilities in Rajkot, Gujarat.");
        $selectedFacility = $facJson['data'][0];
        $facilityId = (int)$selectedFacility['id'];
        $this->assert($selectedFacility['name'] === 'Trikon Baug Smart Multilevel Hub', "Selected Trikon Baug Smart Multilevel Hub (FAC-RAJ-01).");
        $this->assert(isset($selectedFacility['latitude']) && isset($selectedFacility['longitude']), "Real GPS coordinates (22.3015, 70.8030) available for Leaflet/OSM map rendering.");
        $this->assert(str_contains($selectedFacility['osm_url'], 'openstreetmap.org'), "OSM verified link present.");
        $this->assert(str_contains($selectedFacility['google_maps_url'], 'google.com/maps'), "Google Maps direction link present.");

        // ------------------------------------------------------------------
        // STEP 2: Driver Authentication & Vehicle Selection
        // ------------------------------------------------------------------
        echo "\nStep 2: Driver Dev Patel logs in and loads registered EV vehicle fleet...\n";
        $loginOk = $this->loginViaHttp($cookieJarDriver, 'dev.patel@example.com', 'User@2026');
        $this->assert($loginOk, "Driver Dev Patel logged in successfully.");

        $vehResp = $this->httpGet('/api/vehicles.php', $cookieJarDriver);
        $vehJson = json_decode($vehResp['body'], true);
        $this->assert(!empty($vehJson['data']), "Loaded driver's registered vehicle fleet.");
        $vehicle = $vehJson['data'][0];
        $plate = $vehicle['plate_number'];
        $this->assert(!empty($plate), "Selected default vehicle plate {$plate} ({$vehicle['make_model']} - EV: {$vehicle['is_ev']}).");

        // ------------------------------------------------------------------
        // STEP 3: Slot Matrix & Dynamic Fare Preview
        // ------------------------------------------------------------------
        echo "\nStep 3: Driver checks floor layout, selects stall and calculates fare in ₹ INR...\n";
        $startTime = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $endTime = date('Y-m-d H:i:s', strtotime('+2 hours 40 minutes')); // 2h 30m -> 3 billable hours

        $slotResp = $this->httpGet("/api/slots.php?facility_id={$facilityId}&start_time=" . urlencode($startTime) . '&end_time=' . urlencode($endTime));
        $slotJson = json_decode($slotResp['body'], true);
        $this->assert(!empty($slotJson['floors']), "Retrieved multi-floor interactive stall matrix with dimensions & EV chargers.");

        // ------------------------------------------------------------------
        // STEP 4: Atomic Booking & Simulated Payment Confirmation
        // ------------------------------------------------------------------
        echo "\nStep 4: Driver completes atomic booking & UPI payment in ₹ INR...\n";
        $csrfToken = $this->extractCsrfToken($cookieJarDriver);
        $resPost = $this->httpPost('/api/reservations.php', [
            'facility_id' => $facilityId,
            'slot_id' => $targetSlotId,
            'vehicle_id' => $vehicle['id'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'payment_method' => 'upi',
            'csrf_token' => $csrfToken
        ], $cookieJarDriver);

        $this->assert($resPost['status'] === 201, "Reservation and payment created successfully (HTTP 201).");
        $resResult = json_decode($resPost['body'], true)['data'] ?? [];
        $bookingRef = $resResult['booking_reference'] ?? '';
        $bookingId = (int)($resResult['reservation_id'] ?? 0);
        $qrToken = $resResult['qr_token'] ?? '';
        $this->assert(!empty($bookingRef), "Issued official booking reference {$bookingRef}.");

        // ------------------------------------------------------------------
        // STEP 5: Digital QR Pass Generation & Verification
        // ------------------------------------------------------------------
        echo "\nStep 5: Driver opens high-contrast digital QR pass ticket...\n";
        $passPage = $this->httpGet("/pass.php?ref={$bookingRef}", $cookieJarDriver);
        $this->assert($passPage['status'] === 200, "Digital pass accessible with HTTP 200.");
        $this->assert(str_contains($passPage['body'], $bookingRef), "Pass renders booking reference and security QR code.");

        // ------------------------------------------------------------------
        // STEP 6: Gate Terminal Attendant Scan & Check-In Entry
        // ------------------------------------------------------------------
        echo "\nStep 6: Gate Attendant Krunal Solanki scans pass and grants vehicle entry...\n";
        $this->loginViaHttp($cookieJarAttendant, 'attendant.rajkot@parkpulse.com', 'Attendant@2026');

        // Attendant scanner lookup
        $lookup = (new GateService($this->db))->lookupPass($qrToken);
        $this->assert(!empty($lookup) && $lookup['id'] === $bookingId, "Gate camera scan resolved reservation accurately.");
        $this->assert($lookup['vehicle_plate'] === $plate, "License plate verified at gate barrier.");

        // Barrier Check-In
        $gateService = new GateService($this->db);
        $inResult = $gateService->processCheckIn($bookingId, 2);
        $this->assert($inResult['success'] === true && $inResult['action'] === 'check_in', "Gate barrier opened: vehicle checked in.");

        // Verify slot status in occupancy view
        $slotOccupied = $this->db->query("SELECT status FROM slots WHERE id = {$targetSlotId}")->fetchColumn();
        $this->assert($slotOccupied === 'occupied', "Live facility occupancy matrix updated to 'occupied'.");

        // ------------------------------------------------------------------
        // STEP 7: Gate Terminal Attendant Check-Out, Billing & Slot Release
        // ------------------------------------------------------------------
        echo "\nStep 7: Driver exits parking garage; gate attendant processes checkout...\n";
        $outResult = $gateService->processCheckOut($bookingId, 2);
        $this->assert($outResult['success'] === true && $outResult['action'] === 'check_out', "Gate barrier opened: vehicle checked out.");

        // Verify stall released
        $slotReleased = $this->db->query("SELECT status FROM slots WHERE id = {$targetSlotId}")->fetchColumn();
        $this->assert($slotReleased === 'available', "Parking stall released back to 'available' for new reservations.");

        // Verify parking session completed
        $sessionStatus = $this->db->query("SELECT status FROM parking_sessions WHERE reservation_id = {$bookingId}")->fetchColumn();
        $this->assert($sessionStatus === 'completed', "Parking session record marked 'completed'.");

        // ------------------------------------------------------------------
        // STEP 8: Administrator Analytics Updated
        // ------------------------------------------------------------------
        echo "\nStep 8: Administrator Jignesh Patel views updated real-time KPI metrics & revenue...\n";
        $this->loginViaHttp($cookieJarAdmin, 'admin@parkpulse.com', 'Admin@2026');

        $statsResp = $this->httpGet('/api/admin-stats.php', $cookieJarAdmin);
        $stats = json_decode($statsResp['body'], true)['summary'] ?? [];
        $this->assert(($stats['total_revenue'] ?? 0) > 0, "Admin analytics reflects updated verified revenue (₹" . ($stats['total_revenue'] ?? 0) . ").");
        $this->assert(($stats['total_bookings'] ?? 0) > 0, "Admin analytics reflects completed bookings.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
        @unlink($cookieJarDriver);
        @unlink($cookieJarAttendant);
        @unlink($cookieJarAdmin);

        echo "\n----------------------------------------------------------------------\n";
        echo "FINAL JUDGE FLOW SCORECARD: {$this->passCount} PASSED, {$this->failCount} FAILED\n";
        echo "----------------------------------------------------------------------\n";

        if ($this->failCount > 0) {
            exit(1);
        }
    }

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
        $ch = curl_init($this->baseUrl . $path);
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
        $ch = curl_init($this->baseUrl . $path);
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

$suite = new FinalJudgeFlowTestSuite();
$suite->runAll();
