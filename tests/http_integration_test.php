<?php
/**
 * Real HTTP & Session Integration Test Suite - India / Gujarat Edition
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

class HttpIntegrationTester
{
    private string $baseUrl = 'http://localhost/smart%20parking/';
    private string $cookieFile;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'pp_http_test_');
        $db = Database::getInstance()->getConnection();
        $db->exec("TRUNCATE TABLE rate_limits;");
    }

    public function __destruct()
    {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function run(): void
    {
        echo "=========================================================\n";
        echo "  PARKPULSE 2026 - REAL HTTP & SESSION INTEGRATION TEST\n";
        echo "=========================================================\n\n";

        $this->testPublicPages();
        $this->testUserLoginBookingAndPass();
        $this->testAttendantGateOperations();
        $this->testAdminPortalAccess();

        echo "\n---------------------------------------------------------\n";
        echo "HTTP TEST SUMMARY: {$this->passed} PASSED, {$this->failed} FAILED\n";
        echo "---------------------------------------------------------\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert(bool $condition, string $testName, string $failMsg = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$testName} : {$failMsg}\n";
        }
    }

    private function request(string $url, string $method = 'GET', array $data = []): array
    {
        $ch = curl_init();
        $fullUrl = str_starts_with($url, 'http') ? $url : $this->baseUrl . ltrim($url, '/');

        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error
        ];
    }

    private function extractCsrfToken(string $html): string
    {
        if (preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $html, $matches)) {
            return $matches[1];
        }
        if (preg_match('/csrf-token"\s+content="([a-f0-9]+)"/i', $html, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private function testPublicPages(): void
    {
        echo "1. Testing Public Discovery & Map Pages...\n";
        $home = $this->request('index.php');
        $this->assert($home['status'] === 200, "Home page (index.php) returned HTTP 200");
        $this->assert(str_contains($home['body'], 'ParkPulse'), "Home page contains branding");
        $this->assert(str_contains($home['body'], 'facilitiesMap'), "Home page includes Leaflet map container");

        $facApi = $this->request('api/facilities.php');
        $this->assert($facApi['status'] === 200, "api/facilities.php returned HTTP 200");
        $json = json_decode($facApi['body'], true);
        $this->assert(!empty($json['data']) && count($json['data']) >= 4, "api/facilities.php returns active verified facilities");
    }

    private function testUserLoginBookingAndPass(): void
    {
        echo "\n2. Testing User Login, Slot Matrix & Reservation Workflow...\n";
        
        // 1. Get Login Page & CSRF
        $loginPage = $this->request('login.php');
        $csrf = $this->extractCsrfToken($loginPage['body']);
        $this->assert(!empty($csrf), "Extracted CSRF token from login form");

        // 2. Perform Login as Dev Patel
        $loginRes = $this->request('login.php', 'POST', [
            'csrf_token' => $csrf,
            'email' => 'dev.patel@example.com',
            'password' => 'User@2026'
        ]);
        $this->assert($loginRes['status'] === 200, "Login submission returned HTTP 200");
        $this->assert(str_contains($loginRes['body'], 'Dev Patel') || str_contains($loginRes['body'], 'My Bookings') || str_contains($loginRes['body'], 'Find Parking'), "User session authenticated and authorized");

        // 3. Check Vehicles Page
        $vehPage = $this->request('vehicles.php');
        $this->assert(str_contains($vehPage['body'], 'GJ-03-ER-4521'), "User vehicles list accessible (Tata Nexon EV Max plate found)");

        // 4. Check Slot Layout API
        $slotApi = $this->request('api/slots.php?facility_id=1');
        $this->assert($slotApi['status'] === 200, "api/slots.php returned HTTP 200");
        $slotJson = json_decode($slotApi['body'], true);
        $this->assert(!empty($slotJson['floors']), "Facility slot floors and zones layout returned");

        // 5. Submit a Reservation on Facility 1
        $reservePage = $this->request('reserve.php?facility_id=1');
        $resCsrf = $this->extractCsrfToken($reservePage['body']);

        $randomDayOffset = rand(10, 50);
        $start = date('Y-m-d H:00:00', strtotime("+{$randomDayOffset} days 10:00:00"));
        $end = date('Y-m-d H:00:00', strtotime("+{$randomDayOffset} days 13:00:00"));

        $bookRes = $this->request('reserve.php?facility_id=1', 'POST', [
            'csrf_token' => $resCsrf,
            'slot_id' => '10', // Slot A-110 (Car slot)
            'vehicle_id' => '1',
            'start_time' => $start,
            'end_time' => $end,
            'payment_method' => 'upi'
        ]);
        $this->assert($bookRes['status'] === 200, "Reservation submission processed");
        $this->assert(str_contains($bookRes['body'], 'Digital') || str_contains($bookRes['body'], 'PASS') || str_contains($bookRes['body'], 'PK-2026-'), "Digital QR Pass page generated and displayed");
    }

    private function testAttendantGateOperations(): void
    {
        echo "\n3. Testing Attendant Authentication & Gate Operations...\n";

        // Logout user first
        $this->request('logout.php');

        // Login as Attendant Krunal Solanki
        $loginPage = $this->request('login.php');
        $csrf = $this->extractCsrfToken($loginPage['body']);

        $loginRes = $this->request('login.php', 'POST', [
            'csrf_token' => $csrf,
            'email' => 'attendant.rajkot@parkpulse.com',
            'password' => 'Attendant@2026'
        ]);
        $this->assert(str_contains($loginRes['body'], 'Gate Scanner') || str_contains($loginRes['body'], 'Live Occupancy') || str_contains($loginRes['body'], 'Scanner'), "Attendant logged in with role access");

        // Gate Scanner Lookup API for Seed Reservation 1
        $lookup = $this->request('api/scan-lookup.php?q=PK-2026-90812');
        $this->assert($lookup['status'] === 200, "api/scan-lookup.php returned HTTP 200 for active booking");
        $lookupJson = json_decode($lookup['body'], true);
        $this->assert($lookupJson['success'] === true && $lookupJson['data']['vehicle_plate'] === 'GJ-03-ER-4521', "Lookup returned vehicle details (GJ-03-ER-4521)");

        // Facility Occupancy View
        $occPage = $this->request('facility-occupancy.php?facility_id=1');
        $this->assert($occPage['status'] === 200, "Live Facility Occupancy page accessible");
        $this->assert(str_contains($occPage['body'], 'Live Facility Occupancy Matrix'), "Occupancy matrix loaded");
    }

    private function testAdminPortalAccess(): void
    {
        echo "\n4. Testing Admin Command Center & RBAC...\n";

        // Logout attendant
        $this->request('logout.php');

        // Login as Admin
        $loginPage = $this->request('login.php');
        $csrf = $this->extractCsrfToken($loginPage['body']);

        $loginRes = $this->request('login.php', 'POST', [
            'csrf_token' => $csrf,
            'email' => 'admin@parkpulse.com',
            'password' => 'Admin@2026'
        ]);
        $this->assert(str_contains($loginRes['body'], 'Command Center') || str_contains($loginRes['body'], 'Executive Command Center') || str_contains($loginRes['body'], 'Analytics'), "Admin logged in successfully");

        // Test Admin Dashboard & Stats API
        $statsApi = $this->request('api/admin-stats.php');
        $this->assert($statsApi['status'] === 200, "api/admin-stats.php returned HTTP 200");
        $statsJson = json_decode($statsApi['body'], true);
        $this->assert(isset($statsJson['summary']['total_revenue']), "Admin stats API calculated revenue metrics");

        // Test Admin Facilities Page
        $facPage = $this->request('admin-facilities.php');
        $this->assert($facPage['status'] === 200 && str_contains($facPage['body'], 'Parking Facilities Management'), "Admin Facilities CRUD page accessible");

        // Test Admin Slots Page
        $slotPage = $this->request('admin-slots.php?facility_id=1');
        $this->assert($slotPage['status'] === 200 && (str_contains($slotPage['body'], 'Generator') || str_contains($slotPage['body'], 'Stall')), "Admin Slots & Batch Generator accessible");

        // Test Admin Pricing Page
        $pricePage = $this->request('admin-pricing.php');
        $this->assert($pricePage['status'] === 200 && str_contains($pricePage['body'], 'Tariffs & Dynamic Pricing Rules'), "Admin Dynamic Pricing manager accessible");

        // Test Admin Users Page
        $usersPage = $this->request('admin-users.php');
        $this->assert($usersPage['status'] === 200 && str_contains($usersPage['body'], 'Staff & User Management'), "Admin Staff & User RBAC page accessible");

        // Test Admin Audit Logs Page
        $auditPage = $this->request('admin-audit.php');
        $this->assert($auditPage['status'] === 200 && str_contains($auditPage['body'], 'Security & Operational Audit Log'), "Admin Security & Audit log explorer accessible");
    }
}

$tester = new HttpIntegrationTester();
$tester->run();
