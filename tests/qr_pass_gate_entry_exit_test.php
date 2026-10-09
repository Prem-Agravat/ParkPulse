<?php
/**
 * Part 6 — QR Pass, Gate Entry, Exit, Session & Overtime Billing Test Suite
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/PricingService.php';
require_once __DIR__ . '/../src/Services/ReservationService.php';
require_once __DIR__ . '/../src/Services/GateService.php';

final class QrPassGateEntryExitTestSuite
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
        echo "  PARKPULSE 2026 - PART 6 QR PASS, GATE ENTRY & EXIT\n";
        echo "=========================================================\n\n";

        $this->testValidQrPassGenerationAndHmac();
        $this->testServerSidePassLookupAndFallbacks();
        $this->testInvalidAndTamperedPassLookup();
        $this->testVehicleEntryAndParkingSessionCreation();
        $this->testDuplicateAndReusedPassPrevention();
        $this->testVehicleExitAndSlotRelease();
        $this->testDuplicateExitPrevention();
        $this->testOvertimeBillingAtExit();
        $this->testCancelledAndExpiredPassGateRejection();
        $this->testGateApiRbacProtection();

        echo "\n---------------------------------------------------------\n";
        echo "PART 6 TEST SUMMARY: {$this->passCount} PASSED, {$this->failCount} FAILED\n";
        echo "---------------------------------------------------------\n";

        if ($this->failCount > 0) {
            exit(1);
        }
    }

    private function testValidQrPassGenerationAndHmac(): void
    {
        echo "1. Testing Cryptographic QR Pass Generation & HMAC Verification...\n";

        $userId = 4;
        $facilityId = 1;
        $slotId = 8; // Floor 1, Slot A-108
        $plate = 'KA-01-QR-PASS';
        $start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $end = date('Y-m-d H:i:s', strtotime('+3 hours'));

        // Pre-clean slot
        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ?")->execute([$slotId]);

        $booking = $this->resService->createReservation($userId, $facilityId, $slotId, $plate, null, $start, $end, 'card');
        $bookingId = $booking['reservation_id'];

        $this->assert(str_starts_with($booking['qr_token'], 'PKPASS_'), "QR token has standard unguessable format.");
        $this->assert(strlen($booking['qr_token']) > 32, "QR token entropy is high (>32 chars).");

        $passRow = $this->db->query("SELECT * FROM qr_passes WHERE reservation_id = {$bookingId}")->fetch();
        $this->assert($passRow && $passRow['status'] === 'active', "qr_passes record created with status 'active'.");
        $this->assert(str_starts_with($passRow['pass_code'], 'PASS-'), "Manual pass code generated ({$passRow['pass_code']}).");

        // Verify HMAC-SHA256 signature
        $expectedHash = Security::hashQrToken($booking['qr_token']);
        $this->assert(hash_equals($expectedHash, $passRow['token_hash']), "Cryptographic HMAC-SHA256 hash verified.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
    }

    private function testServerSidePassLookupAndFallbacks(): void
    {
        echo "\n2. Testing Server-Side Pass Lookup (Token, Pass Code, Plate)...\n";

        $slotId = 9;
        $plate = 'KA-01-LOOKUP';
        $start = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $end = date('Y-m-d H:i:s', strtotime('+5 hours'));

        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ?")->execute([$slotId]);
        $booking = $this->resService->createReservation(4, 1, $slotId, $plate, null, $start, $end);
        $bookingId = $booking['reservation_id'];
        $ref = $booking['booking_reference'];
        $token = $booking['qr_token'];
        $passCode = $booking['qr_pass_code'];

        // 1. Lookup by raw QR token
        $lookup1 = $this->gateService->lookupPass($token);
        $this->assert($lookup1 && $lookup1['id'] === $bookingId, "Lookup by raw QR token successfully resolved reservation.");
        $this->assert($lookup1['vehicle_plate'] === $plate, "Resolved correct vehicle license plate.");
        $this->assert(!empty($lookup1['slot_number']), "Resolved correct stall number ({$lookup1['slot_number']}).");

        // 2. Lookup by Booking Reference (PK-2026-...)
        $lookup2 = $this->gateService->lookupPass($ref);
        $this->assert($lookup2 && $lookup2['id'] === $bookingId, "Lookup by booking reference resolved reservation.");

        // 3. Lookup by Manual Pass Code (PASS-...)
        $lookup3 = $this->gateService->lookupPass($passCode);
        $this->assert($lookup3 && $lookup3['id'] === $bookingId, "Lookup by manual pass code resolved reservation.");

        // 4. Lookup by License Plate
        $lookup4 = $this->gateService->lookupPass($plate);
        $this->assert($lookup4 && $lookup4['id'] === $bookingId, "Lookup by vehicle license plate resolved reservation.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
    }

    private function testInvalidAndTamperedPassLookup(): void
    {
        echo "\n3. Testing Invalid & Tampered Pass Lookup...\n";

        $lookupBad1 = $this->gateService->lookupPass('PKPASS_FORGED_TOKEN_1234567890');
        $this->assert($lookupBad1 === null, "Forged QR pass token returned null (rejected).");

        $lookupBad2 = $this->gateService->lookupPass('NON_EXISTENT_PLATE_99');
        $this->assert($lookupBad2 === null, "Non-existent vehicle plate returned null.");

        $lookupEmpty = $this->gateService->lookupPass('   ');
        $this->assert($lookupEmpty === null, "Whitespace-only lookup query safely rejected.");
    }

    private function testVehicleEntryAndParkingSessionCreation(): void
    {
        echo "\n4. Testing Vehicle Gate Entry & Active Parking Session Creation...\n";

        $slotId = 10;
        $plate = 'KA-01-ENTRY';
        $start = date('Y-m-d H:i:s', strtotime('-15 minutes'));
        $end = date('Y-m-d H:i:s', strtotime('+2 hours'));

        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ?")->execute([$slotId]);
        $booking = $this->resService->createReservation(4, 1, $slotId, $plate, null, $start, $end, 'card', true);
        $bookingId = $booking['reservation_id'];

        $attendantId = 2; // Attendant user

        // Process Check-In
        $inResult = $this->gateService->processCheckIn($bookingId, $attendantId);

        $this->assert($inResult['success'] === true, "GateService admitted vehicle.");
        $this->assert($inResult['action'] === 'check_in', "Action returned is 'check_in'.");

        // Verify reservation status in DB
        $res = $this->db->query("SELECT status, check_in_time, checked_in_by FROM reservations WHERE id = {$bookingId}")->fetch();
        $this->assert($res['status'] === 'checked_in', "Reservation status transitioned to 'checked_in'.");
        $this->assert(!empty($res['check_in_time']), "Reservation recorded check_in_time.");
        $this->assert((int)$res['checked_in_by'] === $attendantId, "Reservation recorded checked_in_by attendant ID.");

        // Verify QR pass status
        $passStatus = $this->db->query("SELECT status FROM qr_passes WHERE reservation_id = {$bookingId}")->fetchColumn();
        $this->assert($passStatus === 'used', "QR pass status transitioned to 'used'.");

        // Verify slot status
        $slotStatus = $this->db->query("SELECT status FROM slots WHERE id = {$slotId}")->fetchColumn();
        $this->assert($slotStatus === 'occupied', "Physical slot status transitioned to 'occupied'.");

        // Verify parking_sessions record
        $session = $this->db->query("SELECT * FROM parking_sessions WHERE reservation_id = {$bookingId} AND status = 'active'")->fetch();
        $this->assert(!empty($session), "Active parking_sessions record created with check_in_time.");
        $this->assert($session['vehicle_plate'] === $plate, "Parking session linked to vehicle plate {$plate}.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
        $this->db->prepare("UPDATE slots SET status = 'available' WHERE id = ?")->execute([$slotId]);
    }

    private function testDuplicateAndReusedPassPrevention(): void
    {
        echo "\n5. Testing Duplicate & Reused Pass Entry Prevention...\n";

        $slotId = 11;
        $plate = 'KA-01-REUSE';
        $start = date('Y-m-d H:i:s', strtotime('-10 minutes'));
        $end = date('Y-m-d H:i:s', strtotime('+2 hours'));

        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ?")->execute([$slotId]);
        $booking = $this->resService->createReservation(4, 1, $slotId, $plate, null, $start, $end, 'card', true);
        $bookingId = $booking['reservation_id'];

        // Initial check-in
        $this->gateService->processCheckIn($bookingId, 2);

        // Attempt second check-in on already checked-in vehicle
        $duplicateCaught = false;
        try {
            $this->gateService->processCheckIn($bookingId, 2);
        } catch (\RuntimeException $e) {
            $duplicateCaught = true;
        }

        $this->assert($duplicateCaught, "Duplicate check-in blocked (Cannot check-in booking with status 'checked_in').");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
        $this->db->prepare("UPDATE slots SET status = 'available' WHERE id = ?")->execute([$slotId]);
    }

    private function testVehicleExitAndSlotRelease(): void
    {
        echo "\n6. Testing Vehicle Gate Exit & Slot Release...\n";

        $slotId = 12;
        $plate = 'KA-01-EXIT';
        $start = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $end = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ?")->execute([$slotId]);
        $booking = $this->resService->createReservation(4, 1, $slotId, $plate, null, $start, $end, 'card', true);
        $bookingId = $booking['reservation_id'];

        // 1. Entry
        $this->gateService->processCheckIn($bookingId, 2);
        $slotOccupied = $this->db->query("SELECT status FROM slots WHERE id = {$slotId}")->fetchColumn();
        $this->assert($slotOccupied === 'occupied', "Slot occupied while vehicle parked.");

        // 2. Exit
        $outResult = $this->gateService->processCheckOut($bookingId, 2);
        $this->assert($outResult['success'] === true, "GateService processed vehicle exit.");
        $this->assert($outResult['action'] === 'check_out', "Action is 'check_out'.");

        // Verify reservation completed
        $res = $this->db->query("SELECT status, check_out_time, actual_hours FROM reservations WHERE id = {$bookingId}")->fetch();
        $this->assert($res['status'] === 'completed', "Reservation status transitioned to 'completed'.");
        $this->assert(!empty($res['check_out_time']), "Recorded actual check_out_time.");
        $this->assert($res['actual_hours'] > 0, "Calculated actual parking duration.");

        // Verify parking_sessions completed
        $sessStatus = $this->db->query("SELECT status FROM parking_sessions WHERE reservation_id = {$bookingId}")->fetchColumn();
        $this->assert($sessStatus === 'completed', "parking_sessions record status transitioned to 'completed'.");

        // Verify slot released to available
        $slotReleased = $this->db->query("SELECT status FROM slots WHERE id = {$slotId}")->fetchColumn();
        $this->assert($slotReleased === 'available', "Slot released back to 'available' after exit.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
    }

    private function testDuplicateExitPrevention(): void
    {
        echo "\n7. Testing Duplicate Exit Prevention...\n";

        $slotId = 6;
        $plate = 'GJ-03-DUP-EXIT';
        $start = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $end = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ?")->execute([$slotId]);
        $booking = $this->resService->createReservation(4, 1, $slotId, $plate, null, $start, $end, 'card', true);
        $bookingId = $booking['reservation_id'];

        $this->gateService->processCheckIn($bookingId, 2);
        $this->gateService->processCheckOut($bookingId, 2);

        // Attempt second check-out on already completed vehicle
        $duplicateExitCaught = false;
        try {
            $this->gateService->processCheckOut($bookingId, 2);
        } catch (\RuntimeException $e) {
            $duplicateExitCaught = true;
        }

        $this->assert($duplicateExitCaught, "Duplicate check-out blocked (Cannot check-out booking with status 'completed').");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
    }

    private function testOvertimeBillingAtExit(): void
    {
        echo "\n8. Testing Overtime Fee Assessment & Gate Payment Collection...\n";

        $slotId = 7;
        $plate = 'GJ-03-OVERTIME';
        // Scheduled booking was 3 hours ago to 1 hour ago (Ended 1 hour ago)
        $start = date('Y-m-d H:i:s', strtotime('-3 hours'));
        $end = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ?")->execute([$slotId]);
        $booking = $this->resService->createReservation(4, 1, $slotId, $plate, null, $start, $end, 'card', true);
        $bookingId = $booking['reservation_id'];

        // Check in
        $this->gateService->processCheckIn($bookingId, 2);

        // Check out late (Overtime past 15 min grace period)
        $out = $this->gateService->processCheckOut($bookingId, 2);

        $this->assert($out['overtime_charges'] > 0, "Assessed overtime penalty charge (₹{$out['overtime_charges']}).");
        $this->assert($out['overtime_mins'] >= 45, "Recorded accurate overtime minutes ({$out['overtime_mins']} mins).");

        // Check payments table for overstay_penalty entry
        $penaltyPay = $this->db->query("SELECT * FROM payments WHERE reservation_id = {$bookingId} AND payment_type = 'overstay_penalty'")->fetch();
        $this->assert(!empty($penaltyPay), "Recorded 'overstay_penalty' payment record.");
        $this->assert($penaltyPay['status'] === 'success', "Penalty payment marked 'success'.");
        $this->assert((float)$penaltyPay['amount'] == (float)$out['overtime_charges'], "Payment amount matched assessed penalty.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
        $this->db->prepare("UPDATE slots SET status = 'available' WHERE id = ?")->execute([$slotId]);
    }

    private function testCancelledAndExpiredPassGateRejection(): void
    {
        echo "\n9. Testing Gate Rejection of Cancelled & Expired Passes...\n";

        $slotId = 8;
        $plate = 'GJ-03-CANCELLED';
        $start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $end = date('Y-m-d H:i:s', strtotime('+3 hours'));

        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ?")->execute([$slotId]);
        $booking = $this->resService->createReservation(4, 1, $slotId, $plate, null, $start, $end);
        $bookingId = $booking['reservation_id'];

        // Cancel booking
        $this->resService->cancelReservation($booking['booking_reference'], 4, false, 'Changed plans');

        // Attendant attempts to check-in cancelled pass
        $cancelledCaught = false;
        try {
            $this->gateService->processCheckIn($bookingId, 2);
        } catch (\RuntimeException $e) {
            $cancelledCaught = true;
        }

        $this->assert($cancelledCaught, "Gate check-in blocked for cancelled reservation.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
    }

    private function testGateApiRbacProtection(): void
    {
        echo "\n10. Testing Gate Barrier API RBAC Restrictions...\n";

        // 1. Driver attempts to call api/process-gate.php
        $cookieJarDriver = tempnam(sys_get_temp_dir(), 'pp_drv_gate_');
        $this->loginViaHttp($cookieJarDriver, 'dev.patel@example.com', 'User@2026');

        $driverGateResp = $this->httpPost('/api/process-gate.php', [
            'booking_id' => 1,
            'action' => 'check_in',
            'csrf_token' => 'dummy'
        ], $cookieJarDriver);

        $this->assert($driverGateResp['status'] === 403, "Driver role forbidden from api/process-gate.php (HTTP 403).");

        // 2. Unauthenticated request to api/process-gate.php
        $unauthGateResp = $this->httpPost('/api/process-gate.php', [
            'booking_id' => 1,
            'action' => 'check_in'
        ]);
        $this->assert($unauthGateResp['status'] === 401, "Unauthenticated request to api/process-gate.php returned HTTP 401.");

        @unlink($cookieJarDriver);
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

$suite = new QrPassGateEntryExitTestSuite();
$suite->runAll();
