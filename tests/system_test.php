<?php
/**
 * Automated Comprehensive Test Suite - India / Gujarat Edition
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/PricingService.php';
require_once __DIR__ . '/../src/Services/ReservationService.php';
require_once __DIR__ . '/../src/Services/GateService.php';
require_once __DIR__ . '/../src/Services/PaymentGatewayService.php';

class SystemTestSuite
{
    private PDO $db;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function runAll(): void
    {
        echo "=========================================================\n";
        echo "  PARKPULSE 2026 - COMPREHENSIVE OOP & SYSTEM TEST SUITE (INDIA/GUJARAT)\n";
        echo "=========================================================\n\n";

        $this->testDatabaseConnectivityAndSchema();
        $this->testIndiaLocalizationAndValidation();
        $this->testPricingServiceDomainLogic();
        $this->testReservationServiceAtomicCreation();
        $this->testVehicleCategoryCompatibility();
        $this->testGateServiceWorkflowAndOvertime();
        $this->testSecurityAndCryptographicPassTokens();
        $this->testCsrfAndInputValidation();
        $this->testAuthenticationAndRateLimiting();
        $this->testVehicleManagement();
        $this->testAuditTrailIntegrity();
        $this->testPaymentGatewayService();
        $this->testAdminAnalyticsMetrics();

        echo "\n---------------------------------------------------------\n";
        echo "TEST SUMMARY: {$this->passed} PASSED, {$this->failed} FAILED\n";
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

    private function testDatabaseConnectivityAndSchema(): void
    {
        echo "1. Testing Database & Relational Schema (12 Tables)...\n";
        $tables = [
            'users', 'vehicles', 'facilities', 'slots', 'pricing_rules',
            'attendant_assignments', 'reservations', 'qr_passes',
            'parking_sessions', 'payments', 'audit_logs', 'rate_limits'
        ];
        foreach ($tables as $t) {
            $res = $this->db->query("SHOW TABLES LIKE '{$t}'")->fetch();
            $this->assert(!empty($res), "Table `{$t}` exists in InnoDB schema.");
        }
    }

    private function testIndiaLocalizationAndValidation(): void
    {
        echo "\n2. Testing India Localization & Mobile/PIN/GPS Validation...\n";

        // 1. Mobile Phone Normalization & Validation
        $raw1 = "+91 98250 12345";
        $norm1 = Security::normalizeIndianMobile($raw1);
        $this->assert($norm1 === '9825012345' && Security::validateIndianMobile($raw1), "Normalized standard +91 98250 12345 to 10-digit mobile '9825012345'.");
        $this->assert(Security::formatIndianMobile($norm1) === '+91 98250 12345', "Formatted as '+91 98250 12345'.");

        $raw2 = "09428023456";
        $this->assert(Security::normalizeIndianMobile($raw2) === '9428023456', "Normalized leading-zero mobile number.");

        $raw3 = "9898045678";
        $this->assert(Security::normalizeIndianMobile($raw3) === '9898045678', "Normalized 10-digit mobile without country code.");

        $invalidPhone = "1234567890"; // Does not start with 6-9
        $this->assert(!Security::validateIndianMobile($invalidPhone), "Rejected invalid mobile number starting with 1.");

        // 2. Indian PIN Code Validation
        $this->assert(Security::validatePinCode('360001'), "Valid Rajkot PIN 360001 accepted.");
        $this->assert(Security::validatePinCode('380009'), "Valid Ahmedabad PIN 380009 accepted.");
        $this->assert(!Security::validatePinCode('012345'), "Rejected PIN code starting with 0.");
        $this->assert(!Security::validatePinCode('12345'), "Rejected 5-digit PIN code.");
        $this->assert(!Security::validatePinCode('36000A'), "Rejected alphanumeric PIN code.");

        // 3. GPS Coordinates & Trusted Maps URLs
        $this->assert(Security::validateCoordinates(22.3015, 70.8030), "Valid Rajkot GPS coordinates accepted.");
        $osmUrl = Security::buildOsmUrl(22.3015, 70.8030);
        $this->assert(str_contains($osmUrl, 'openstreetmap.org/?mlat=22.3015'), "Generated safe OSM URL with coordinates.");
        $gmapsUrl = Security::buildGoogleMapsUrl(22.3015, 70.8030);
        $this->assert(str_contains($gmapsUrl, 'google.com/maps/search/?api=1&query=22.3015,70.803'), "Generated safe Google Maps URL with coordinates.");

        // 4. 28 States & 8 UTs
        $statesAndUts = Security::getIndianStatesAndUTs();
        $this->assert(count($statesAndUts['states']) === 28, "All 28 Indian States present.");
        $this->assert(count($statesAndUts['union_territories']) === 8, "All 8 Union Territories present.");
        $this->assert(in_array('Gujarat', $statesAndUts['states'], true), "Gujarat included in state list.");

        // 5. 8 Vehicle Categories
        $cats = Security::getVehicleCategories();
        $expectedCats = ['car', 'bike', 'bicycle', 'auto_rickshaw', 'van', 'truck', 'bus', 'other'];
        $this->assert(count($cats) === 8, "All 8 vehicle categories supported.");
        foreach ($expectedCats as $ec) {
            $this->assert(isset($cats[$ec]), "Category '{$ec}' defined with metadata.");
        }
    }

    private function testPricingServiceDomainLogic(): void
    {
        echo "\n3. Testing PricingService Domain Logic (INR ₹ & EV Surcharge)...\n";
        $pricing = new PricingService($this->db);

        // Standard car fare calculation for Facility 1 (Trikon Baug, Rajkot: ₹30/hr off-peak)
        $start = date('Y-m-d 14:00:00', strtotime('next Wednesday'));
        $end = date('Y-m-d 16:30:00', strtotime('next Wednesday')); // 2.5 hours -> 3 billable hrs
        $fare = $pricing->calculateFare(1, $start, $end, 'car', false);

        $this->assert($fare['billable_hours'] === 3, "2.5 hour duration accurately ceils to 3 billable hours.");
        $this->assert($fare['hourly_rate'] == 30.00, "Base car hourly rate is ₹30.00.");
        $this->assert($fare['total_amount'] == 90.00, "Calculated 3 hrs x ₹30.00 = ₹90.00.");

        // Test EV charging surcharge for Car (₹30 base + ₹20 EV surcharge = ₹50/hr -> ₹150 for 3 hrs)
        $evFare = $pricing->calculateFare(1, $start, $end, 'car', true, true);
        $this->assert($evFare['ev_surcharge_per_hour'] == 20.00, "Applied dedicated EV charging surcharge (₹20.00/hr).");
        $this->assert($evFare['total_amount'] == 150.00, "Calculated EV fare 3 hrs x (₹30 + ₹20) = ₹150.00.");

        // Two-wheeler fare calculation (₹12/hr)
        $bikeFare = $pricing->calculateFare(1, $start, $end, 'bike', false);
        $this->assert($bikeFare['hourly_rate'] == 12.00, "Two-wheeler tariff is ₹12.00/hr.");
        $this->assert($bikeFare['total_amount'] == 36.00, "Calculated 3 hrs x ₹12.00 = ₹36.00.");

        // Test overtime calculation within grace period (15 mins)
        $schedEnd = date('Y-m-d 14:00:00');
        $exitWithinGrace = strtotime('14:10:00'); // 10 mins
        $ot1 = $pricing->calculateOvertime(1, $schedEnd, $exitWithinGrace);
        $this->assert(!$ot1['is_overstay'] && $ot1['overtime_charges'] == 0, "No overtime charge within 15-minute grace period.");

        // Test overtime calculation beyond grace period (Overtime rate: ₹60/hr -> 2 hrs = ₹120.00)
        $exitBeyondGrace = strtotime('15:45:00'); // 1h 45m past end -> 2 billable overtime hours
        $ot2 = $pricing->calculateOvertime(1, $schedEnd, $exitBeyondGrace);
        $this->assert($ot2['is_overstay'] && $ot2['overtime_charges'] == 120.00, "Calculated 2 overtime hours penalty fee (₹120.00).");
    }

    private function testReservationServiceAtomicCreation(): void
    {
        echo "\n4. Testing ReservationService & Concurrency Protection...\n";
        $resService = new ReservationService($this->db);

        $uniqueDay = rand(60, 90);
        $start = date('Y-m-d 11:00:00', strtotime("+{$uniqueDay} days"));
        $end = date('Y-m-d 14:00:00', strtotime("+{$uniqueDay} days"));

        $booking = $resService->createReservation(4, 1, 5, 'GJ-03-OOP-TEST', 1, $start, $end, 'upi');
        $this->assert(str_starts_with($booking['booking_reference'], 'PK-2026-'), "Created booking with reference {$booking['booking_reference']}.");
        $this->assert(str_starts_with($booking['qr_pass_code'], 'PASS-'), "Created linked QR pass {$booking['qr_pass_code']}.");

        // Verify duplicate reservation attempt on the same slot/time is blocked
        $caught = false;
        try {
            $resService->createReservation(5, 1, 5, 'GJ-01-CONFLICT', 3, $start, $end, 'upi');
        } catch (\RuntimeException $e) {
            $caught = true;
        }
        $this->assert($caught, "ReservationService correctly blocked conflicting overlapping reservation.");

        // Test cancellation and refund
        $cancel = $resService->cancelReservation($booking['booking_reference'], 4, false, 'Testing cancellation');
        $this->assert($cancel['success'] && $cancel['refund_amount'] > 0, "ReservationService cancelled booking and simulated refund.");
    }

    private function testVehicleCategoryCompatibility(): void
    {
        echo "\n5. Testing Vehicle Category to Slot Compatibility Enforcement...\n";
        $resService = new ReservationService($this->db);

        $start = date('Y-m-d 10:00:00', strtotime('+15 days'));
        $end = date('Y-m-d 12:00:00', strtotime('+15 days'));

        // Slot 13 in Facility 1 is Zone B (bike slot)
        $slot13 = $this->db->query("SELECT * FROM slots WHERE facility_id = 1 AND slot_type = 'bike' LIMIT 1")->fetch();

        if ($slot13) {
            // Attempt to book a truck or bus into bike slot -> must throw exception
            $blocked = false;
            try {
                $resService->createReservation(6, 1, (int)$slot13['id'], 'GJ-05-TR-9000', 6, $start, $end, 'upi');
            } catch (\InvalidArgumentException $e) {
                $blocked = true;
            }
            $this->assert($blocked, "Server-side compatibility blocked heavy truck from reserving a bike stall.");
        }
    }

    private function testGateServiceWorkflowAndOvertime(): void
    {
        echo "\n6. Testing GateService Barrier Operations...\n";
        $gate = new GateService($this->db);
        $resService = new ReservationService($this->db);

        $start = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $end = date('Y-m-d H:i:s', strtotime('+2 hours'));

        $booking = $resService->createReservation(4, 1, 6, 'GJ-03-GATE-TEST', 1, $start, $end, 'upi', true);
        $bookingId = $booking['reservation_id'];

        // 1. Pass Lookup
        $pass = $gate->lookupPass($booking['qr_token']);
        $this->assert(!empty($pass) && $pass['vehicle_plate'] === 'GJ-03-GATE-TEST', "GateService lookup resolved reservation by QR token.");

        // 2. Process Check-In
        $in = $gate->processCheckIn($bookingId, 2);
        $this->assert($in['success'] && $in['action'] === 'check_in', "GateService admitted vehicle and started parking session.");

        $sessionStatus = $this->db->query("SELECT status FROM parking_sessions WHERE reservation_id = {$bookingId}")->fetchColumn();
        $this->assert($sessionStatus === 'active', "parking_sessions record active.");

        // 3. Process Check-Out
        $out = $gate->processCheckOut($bookingId, 2);
        $this->assert($out['success'] && $out['action'] === 'check_out', "GateService processed vehicle exit.");

        $sessionStatus2 = $this->db->query("SELECT status FROM parking_sessions WHERE reservation_id = {$bookingId}")->fetchColumn();
        $slotStatus = $this->db->query("SELECT status FROM slots WHERE id = 6")->fetchColumn();
        $this->assert($sessionStatus2 === 'completed', "parking_sessions completed.");
        $this->assert($slotStatus === 'available', "Stall released back to available.");

        // Clean up test booking
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
    }

    private function testSecurityAndCryptographicPassTokens(): void
    {
        echo "\n7. Testing Password Hashing & QR Token Cryptography...\n";
        $pass = 'SecurePass@2026';
        $hash = Security::hashPassword($pass);
        $this->assert(Security::verifyPassword($pass, $hash), "password_hash() verifies correctly.");
        $this->assert(!Security::verifyPassword('WrongPass', $hash), "Incorrect password rejected.");

        $qrToken = Security::generateQrToken();
        $qrHash1 = Security::hashQrToken($qrToken);
        $qrHash2 = Security::hashQrToken($qrToken);
        $this->assert($qrHash1 === $qrHash2, "HMAC-SHA256 QR token hashing is deterministic and valid.");
    }

    private function testCsrfAndInputValidation(): void
    {
        echo "\n8. Testing CSRF & Input Sanitization...\n";
        $token = Security::generateCsrfToken();
        $this->assert(Security::validateCsrfToken($token), "CSRF token generated and verified.");
        $this->assert(!Security::validateCsrfToken('tampered_token'), "Forged CSRF token rejected.");

        $malicious = "<script>alert('XSS')</script>";
        $escaped = Security::escape($malicious);
        $this->assert(!str_contains($escaped, '<script>'), "XSS payload escaped.");
    }

    private function testAuthenticationAndRateLimiting(): void
    {
        echo "\n9. Testing Authentication & Rate Limiting...\n";
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute(['admin@parkpulse.com']);
        $admin = $stmt->fetch();
        $this->assert(!empty($admin) && $admin['role'] === 'admin', "Admin account found.");
        $this->assert(Security::verifyPassword('Admin@2026', $admin['password_hash']), "Admin credentials verified.");

        $rateKey = 'test_action_' . bin2hex(random_bytes(4));
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($rateKey);
        }
        $isLimited = RateLimiter::isRateLimited($rateKey, 5, 300);
        $this->assert($isLimited, "Rate limiter throttles after threshold attempts.");
        RateLimiter::reset($rateKey);
    }

    private function testVehicleManagement(): void
    {
        echo "\n10. Testing Vehicle Management & EV Separation...\n";
        $stmt = $this->db->prepare("SELECT * FROM vehicles WHERE plate_number = ?");
        $stmt->execute(['GJ-03-ER-4521']);
        $veh = $stmt->fetch();
        $this->assert(!empty($veh) && $veh['is_ev'] == 1 && $veh['vehicle_type'] === 'car', "Dev Patel's Tata Nexon EV Max found (type: car, is_ev: 1).");
    }

    private function testAuditTrailIntegrity(): void
    {
        echo "\n11. Testing Security Audit Logs...\n";
        AuditLogger::log('TEST_EVENT', 'system', 999, 'Test audit payload verification.');
        $stmt = $this->db->prepare("SELECT * FROM audit_logs WHERE action = 'TEST_EVENT' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $log = $stmt->fetch();

        $this->assert(!empty($log), "AuditLogger records event to audit_logs table.");
    }

    private function testPaymentGatewayService(): void
    {
        echo "\n12. Testing PaymentGatewayService & Dynamic Checkout API...\n";
        $paymentService = new PaymentGatewayService($this->db);

        // 1. Create Dynamic Order
        $order = $paymentService->createOrder(
            userId: 4,
            facilityId: 1,
            slotId: 1,
            amount: 150.00,
            startTime: date('Y-m-d H:i:s'),
            endTime: date('Y-m-d H:i:s', strtotime('+3 hours')),
            vehicleType: 'car',
            isEv: true,
            evCharging: true
        );

        $this->assert(!empty($order['order_id']) && str_starts_with($order['order_id'], 'ORDER_PP_'), "Dynamic Order ID generated ({$order['order_id']}).");
        $this->assert($order['amount'] === 150.00 && $order['amount_paise'] === 15000, "Integer paise math accurate (₹150.00 = 15000 paise).");
        $this->assert(str_starts_with($order['upi_intent_url'], 'upi://pay?pa='), "NPCI compliant UPI URI generated.");
        $this->assert(str_contains($order['upi_intent_url'], 'cu=INR'), "Dynamic QR code payload embeds valid UPI INR string.");

        // 2. Test Cryptographic HMAC-SHA256 Signature Verification
        $fakePaymentId = 'PAY_' . bin2hex(random_bytes(6));
        $validSignature = $paymentService->generateClientSignature($order['order_id'], $fakePaymentId, $order['amount_paise']);
        $this->assert($paymentService->verifyPaymentSignature($order['order_id'], $fakePaymentId, $validSignature, $order['amount_paise']), "HMAC-SHA256 signature verified successfully.");
        $this->assert(!$paymentService->verifyPaymentSignature($order['order_id'], $fakePaymentId, 'invalid_signature_hash', $order['amount_paise']), "Tampered signature rejected.");

        // 3. Test Payment KPI Calculations
        $kpis = $paymentService->getPaymentSummaryStats();
        $this->assert(isset($kpis['total_volume']) && $kpis['total_volume'] >= 0, "Payment KPI revenue aggregation computed (₹{$kpis['total_volume']}).");
        $this->assert(isset($kpis['successful_transactions']) && $kpis['successful_transactions'] >= 0, "Payment KPI transaction count computed ({$kpis['successful_transactions']} payments).");
    }

    private function testAdminAnalyticsMetrics(): void
    {
        echo "\n13. Testing Administrative Metrics Aggregation (INR ₹)...\n";
        $totalRev = (float)$this->db->query("SELECT SUM(amount) FROM payments WHERE status = 'success'")->fetchColumn();
        $this->assert($totalRev > 0, "Calculated non-zero verified simulated revenue (₹{$totalRev}).");
        $totalRes = (int)$this->db->query("SELECT COUNT(*) FROM reservations")->fetchColumn();
        $this->assert($totalRes >= 1, "Tracked historical reservation records ({$totalRes} total).");
    }
}

$suite = new SystemTestSuite();
$suite->runAll();
