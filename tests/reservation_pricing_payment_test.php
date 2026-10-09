<?php
/**
 * Part 5 — Reservation, Dynamic Pricing & Payment Test Suite
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/PricingService.php';
require_once __DIR__ . '/../src/Services/ReservationService.php';
require_once __DIR__ . '/../src/Services/GateService.php';

final class ReservationPricingPaymentTestSuite
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
        echo "  PARKPULSE 2026 - PART 5 RESERVATION, PRICING & PAYMENT\n";
        echo "=========================================================\n\n";

        $this->testSuccessfulReservationAndPayment();
        $this->testServerSidePricingAndTariffs();
        $this->testInvalidBookingValidations();
        $this->testConcurrentOverlapAndDoubleBookingPrevention();
        $this->testSlotStatusVsTimeSpecificAvailability();
        $this->testFailedPaymentAndAtomicRollback();
        $this->testCancellationAndRefundPolicy();
        $this->testOvertimeChargesAtGateExit();

        echo "\n---------------------------------------------------------\n";
        echo "PART 5 TEST SUMMARY: {$this->passCount} PASSED, {$this->failCount} FAILED\n";
        echo "---------------------------------------------------------\n";

        if ($this->failCount > 0) {
            exit(1);
        }
    }

    private function testSuccessfulReservationAndPayment(): void
    {
        echo "1. Testing Successful Reservation & Simulated Payment Workflow...\n";

        $userId = 4; // Alex Morgan
        $facilityId = 1; // Metro Tech Park
        $slotId = 2; // Floor 1, Slot A-102
        $plate = 'KA-01-SUCC-26';
        $start = date('Y-m-d H:i:s', strtotime('+2 days 10:00:00'));
        $end = date('Y-m-d H:i:s', strtotime('+2 days 13:30:00'));

        $booking = $this->resService->createReservation($userId, $facilityId, $slotId, $plate, 1, $start, $end, 'card');

        $this->assert(!empty($booking['booking_reference']), "Generated unique booking reference {$booking['booking_reference']}.");
        $this->assert($booking['total_amount'] > 0, "Calculated total fare amount ₹{$booking['total_amount']}.");
        $this->assert(!empty($booking['qr_token']), "Generated unguessable cryptographic QR token.");

        // Check reservations table
        $res = $this->db->query("SELECT * FROM reservations WHERE id = {$booking['reservation_id']}")->fetch();
        $this->assert($res && $res['status'] === 'confirmed', "Reservation status is explicitly 'confirmed'.");

        // Check qr_passes table
        $pass = $this->db->query("SELECT * FROM qr_passes WHERE reservation_id = {$booking['reservation_id']}")->fetch();
        $this->assert($pass && $pass['status'] === 'active', "QR pass generated and active.");

        // Check payments table
        $pay = $this->db->query("SELECT * FROM payments WHERE reservation_id = {$booking['reservation_id']}")->fetch();
        $this->assert($pay && $pay['status'] === 'success' && $pay['payment_type'] === 'reservation_fee', "Simulated payment recorded with success status.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$booking['reservation_id']]);
    }

    private function testServerSidePricingAndTariffs(): void
    {
        echo "\n2. Testing Server-Side Dynamic Tariff Calculations...\n";

        $facilityId = 1;

        // 1. Standard 2.5 hours -> 3 billable hours @ ₹30/hr = ₹90.00
        $start = '2026-11-10 14:00:00'; // Tuesday
        $end = '2026-11-10 16:30:00';
        $fare1 = $this->pricingService->calculateFare($facilityId, $start, $end, 'car', false);
        $this->assert($fare1['billable_hours'] === 3, "2.5h correctly rounds up to 3 billable hours.");
        $this->assert($fare1['total_amount'] == 90.00, "Calculated 3h standard fee @ ₹30/hr = ₹90.00.");

        // 2. EV Stall Tariff (₹30 + ₹20 = ₹50/hr -> ₹150.00)
        $fareEv = $this->pricingService->calculateFare($facilityId, $start, $end, 'car', true, true);
        $this->assert($fareEv['ev_surcharge_per_hour'] == 20.00, "EV stall applied dedicated EV surcharge of ₹20.00/hr.");
        $this->assert($fareEv['total_amount'] == 150.00, "Calculated 3h EV fee @ ₹50/hr = ₹150.00.");

        // 3. Peak Hour Tariff (08:00 - 11:00) -> Peak rate ₹39/hr
        $startPeak = '2026-11-10 09:00:00';
        $endPeak = '2026-11-10 11:00:00';
        $farePeak = $this->pricingService->calculateFare($facilityId, $startPeak, $endPeak, 'car', false);
        $this->assert($farePeak['is_peak'] === true, "Identified morning rush peak hour.");
        $this->assert($farePeak['hourly_rate'] == 39.00, "Applied peak hour hourly rate of ₹39.00/hr.");
        $this->assert($farePeak['total_amount'] == 78.00, "Calculated 2h peak fee @ ₹39/hr = ₹78.00.");

        // 4. Weekend Tariff (Saturday) -> Weekend rate ₹36/hr
        $startWknd = '2026-11-14 14:00:00'; // Saturday
        $endWknd = '2026-11-14 17:00:00';
        $fareWknd = $this->pricingService->calculateFare($facilityId, $startWknd, $endWknd, 'car', false);
        $this->assert($fareWknd['is_weekend'] === true, "Identified weekend calendar day.");
        $this->assert($fareWknd['hourly_rate'] == 36.00, "Applied weekend rate of ₹36.00/hr.");
        $this->assert($fareWknd['total_amount'] == 108.00, "Calculated 3h weekend fee @ ₹36/hr = ₹108.00.");
    }

    private function testInvalidBookingValidations(): void
    {
        echo "\n3. Testing Invalid Booking Validations...\n";

        $userId = 4;
        $facilityId = 1;
        $slotId = 3;

        // 1. Check-in time in the past
        $pastCaught = false;
        try {
            $this->resService->createReservation($userId, $facilityId, $slotId, 'KA-01-PAST', null, '2020-01-01 10:00:00', '2020-01-01 12:00:00');
        } catch (\InvalidArgumentException $e) {
            $pastCaught = true;
        }
        $this->assert($pastCaught, "Check-in time in the past rejected.");

        // 2. End time before start time
        $endEarlyCaught = false;
        try {
            $this->resService->createReservation($userId, $facilityId, $slotId, 'KA-01-TIME', null, '2026-11-10 14:00:00', '2026-11-10 12:00:00');
        } catch (\InvalidArgumentException $e) {
            $endEarlyCaught = true;
        }
        $this->assert($endEarlyCaught, "Check-out time earlier than check-in time rejected.");

        // 3. Minimum duration < 30 minutes
        $shortCaught = false;
        try {
            $this->resService->createReservation($userId, $facilityId, $slotId, 'KA-01-SHORT', null, '2026-11-10 14:00:00', '2026-11-10 14:15:00');
        } catch (\InvalidArgumentException $e) {
            $shortCaught = true;
        }
        $this->assert($shortCaught, "Reservation shorter than minimum 30 minutes rejected.");

        // 4. Invalid license plate
        $badPlateCaught = false;
        try {
            $this->resService->createReservation($userId, $facilityId, $slotId, '??', null, '2026-11-10 14:00:00', '2026-11-10 16:00:00');
        } catch (\InvalidArgumentException $e) {
            $badPlateCaught = true;
        }
        $this->assert($badPlateCaught, "Malformed license plate rejected.");
    }

    private function testConcurrentOverlapAndDoubleBookingPrevention(): void
    {
        echo "\n4. Testing Concurrent Overlap & Double Booking Prevention...\n";

        $userId1 = 4;
        $userId2 = 5;
        $facilityId = 1;
        $slotId = 4; // Floor 1, Slot A-104

        $start1 = '2026-12-01 14:00:00';
        $end1 = '2026-12-01 18:00:00';

        // Pre-clean any test bookings for slot 4 on this date
        $this->db->prepare("DELETE FROM reservations WHERE slot_id = ? AND start_time >= '2026-12-01 00:00:00'")->execute([$slotId]);

        // 1. Initial Booking (14:00 - 18:00)
        $booking1 = $this->resService->createReservation($userId1, $facilityId, $slotId, 'KA-01-FIRST', null, $start1, $end1, 'card');
        $this->assert(!empty($booking1['reservation_id']), "First booking created for window 14:00 - 18:00.");

        // 2. Overlapping Booking Attempt (15:00 - 17:00)
        $start2 = '2026-12-01 15:00:00';
        $end2 = '2026-12-01 17:00:00';
        $overlapCaught = false;

        try {
            $this->resService->createReservation($userId2, $facilityId, $slotId, 'MH-02-CONFLICT', null, $start2, $end2, 'upi');
        } catch (\RuntimeException $e) {
            $overlapCaught = true;
        }
        $this->assert($overlapCaught, "Second overlapping booking (15:00 - 17:00) blocked via row-level locks.");

        // 3. Partial Overlap at Start (13:00 - 15:00)
        $start3 = '2026-12-01 13:00:00';
        $end3 = '2026-12-01 15:00:00';
        $overlapStartCaught = false;

        try {
            $this->resService->createReservation($userId2, $facilityId, $slotId, 'MH-02-OVERLAP', null, $start3, $end3, 'upi');
        } catch (\RuntimeException $e) {
            $overlapStartCaught = true;
        }
        $this->assert($overlapStartCaught, "Partial start overlap (13:00 - 15:00) blocked.");

        // 4. Non-Overlapping Booking in Future (19:00 - 21:00)
        $start4 = '2026-12-01 19:00:00';
        $end4 = '2026-12-01 21:00:00';
        $booking4 = $this->resService->createReservation($userId2, $facilityId, $slotId, 'DL-03-VALID-NEXT', null, $start4, $end4, 'upi');
        $this->assert(!empty($booking4['reservation_id']), "Non-overlapping future booking (19:00 - 21:00) on same slot succeeded.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id IN (?, ?)")->execute([$booking1['reservation_id'], $booking4['reservation_id']]);
    }

    private function testSlotStatusVsTimeSpecificAvailability(): void
    {
        echo "\n5. Testing Physical Status vs Time-Specific Reservation Availability...\n";

        // Slot 5 is physically 'available' right now
        $slot = $this->db->query("SELECT * FROM slots WHERE id = 5")->fetch();
        $this->assert($slot['status'] === 'available', "Slot 5 physical database status is 'available'.");

        // Pre-clean any test bookings for slot 5 on this date
        $this->db->prepare("DELETE FROM reservations WHERE slot_id = 5 AND start_time >= '2026-12-15 00:00:00'")->execute();

        // Book Slot 5 for next month: 2026-12-15 10:00 to 14:00
        $start = '2026-12-15 10:00:00';
        $end = '2026-12-15 14:00:00';
        $booking = $this->resService->createReservation(4, 1, 5, 'KA-05-FUTURE', null, $start, $end);

        // Fetch api/slots.php for general view (without time params)
        $ch1 = curl_init('http://localhost/smart%20parking/api/slots.php?facility_id=1');
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
        $json1 = json_decode((string)curl_exec($ch1), true);
        curl_close($ch1);

        // Find slot 5 in general view
        $statusGeneral = null;
        foreach ($json1['floors'] as $f) {
            foreach ($f['zones'] as $z) {
                foreach ($z['slots'] as $s) {
                    if ($s['id'] === 5) $statusGeneral = $s['status'];
                }
            }
        }
        $this->assert($statusGeneral === 'available', "General query shows slot is physically available right now.");

        // Fetch api/slots.php for the conflicting window (2026-12-15 11:00 - 13:00)
        $ch2 = curl_init('http://localhost/smart%20parking/api/slots.php?facility_id=1&start_time=' . urlencode('2026-12-15 11:00:00') . '&end_time=' . urlencode('2026-12-15 13:00:00'));
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        $json2 = json_decode((string)curl_exec($ch2), true);
        curl_close($ch2);

        $statusSpecific = null;
        foreach ($json2['floors'] as $f) {
            foreach ($f['zones'] as $z) {
                foreach ($z['slots'] as $s) {
                    if ($s['id'] === 5) $statusSpecific = $s['status'];
                }
            }
        }
        $this->assert($statusSpecific === 'reserved', "Time-scoped query accurately evaluates slot as 'reserved' for that future window.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$booking['reservation_id']]);
    }

    private function testFailedPaymentAndAtomicRollback(): void
    {
        echo "\n6. Testing Failed Payment & Atomic Rollback...\n";

        $userId = 4;
        $facilityId = 1;
        $slotId = 6;
        $start = date('Y-m-d H:i:s', strtotime('+5 days 10:00:00'));
        $end = date('Y-m-d H:i:s', strtotime('+5 days 12:00:00'));

        $initialResCount = (int)$this->db->query("SELECT COUNT(*) FROM reservations WHERE slot_id = {$slotId}")->fetchColumn();
        $initialPayCount = (int)$this->db->query("SELECT COUNT(*) FROM payments")->fetchColumn();

        $declinedCaught = false;
        try {
            // Trigger simulated payment failure
            $this->resService->createReservation($userId, $facilityId, $slotId, 'KA-01-FAIL', null, $start, $end, 'simulate_failure');
        } catch (\RuntimeException $e) {
            $declinedCaught = true;
        }

        $this->assert($declinedCaught, "Payment failure simulation intercepted and rejected.");

        $finalResCount = (int)$this->db->query("SELECT COUNT(*) FROM reservations WHERE slot_id = {$slotId}")->fetchColumn();
        $finalPayCount = (int)$this->db->query("SELECT COUNT(*) FROM payments")->fetchColumn();

        $this->assert($finalResCount === $initialResCount, "Atomic transaction rollback: zero orphan reservations created.");
        $this->assert($finalPayCount === $initialPayCount, "Atomic transaction rollback: zero orphan payments created.");
    }

    private function testCancellationAndRefundPolicy(): void
    {
        echo "\n7. Testing Cancellation & Automated Refund Policy...\n";

        $userId = 4;
        $facilityId = 1;
        $slotId = 7;
        $start = date('Y-m-d H:i:s', strtotime('+3 days 10:00:00'));
        $end = date('Y-m-d H:i:s', strtotime('+3 days 14:00:00'));

        $booking = $this->resService->createReservation($userId, $facilityId, $slotId, 'KA-01-REFUND', null, $start, $end, 'card');
        $bookingRef = $booking['booking_reference'];
        $bookingId = $booking['reservation_id'];

        // Cancel the booking
        $cancelResult = $this->resService->cancelReservation($bookingRef, $userId, false, 'Trip rescheduled');

        $this->assert($cancelResult['success'] === true, "Cancellation processed successfully.");
        $this->assert($cancelResult['refund_amount'] == $booking['total_amount'], "Full refund amount ₹{$cancelResult['refund_amount']} matched total booking fare.");

        // Verify status in DB
        $res = $this->db->query("SELECT status, cancellation_reason FROM reservations WHERE id = {$bookingId}")->fetch();
        $this->assert($res['status'] === 'cancelled' && $res['cancellation_reason'] === 'Trip rescheduled', "Reservation status updated to 'cancelled'.");

        $pass = $this->db->query("SELECT status FROM qr_passes WHERE reservation_id = {$bookingId}")->fetch();
        $this->assert($pass['status'] === 'revoked', "QR pass status updated to 'revoked'.");

        $refundPay = $this->db->query("SELECT * FROM payments WHERE reservation_id = {$bookingId} AND payment_type = 'refund'")->fetch();
        $this->assert($refundPay && $refundPay['status'] === 'refunded', "Refund transaction record logged in payments table.");

        // Clean up
        $this->db->prepare("DELETE FROM reservations WHERE id = ?")->execute([$bookingId]);
    }

    private function testOvertimeChargesAtGateExit(): void
    {
        echo "\n8. Testing Overstay & Additional Charges on Exit...\n";

        $facilityId = 1;
        $scheduledEnd = '2026-10-09 10:00:00';

        // 1. Exit within 15 min grace period (10:10:00) -> ₹0 charge
        $exitWithinGrace = strtotime('2026-10-09 10:10:00');
        $otGrace = $this->pricingService->calculateOvertime($facilityId, $scheduledEnd, $exitWithinGrace);
        $this->assert($otGrace['is_overstay'] === false && $otGrace['overtime_charges'] == 0, "No overtime charge within 15-minute grace period.");

        // 2. Exit 1 hour 45 minutes late (11:45:00) -> 2 billable overtime hours @ ₹60/hr = ₹120
        $exitLate = strtotime('2026-10-09 11:45:00');
        $otLate = $this->pricingService->calculateOvertime($facilityId, $scheduledEnd, $exitLate);
        $this->assert($otLate['is_overstay'] === true, "Identified overstay past grace period.");
        $this->assert($otLate['overtime_hours'] === 2, "1h 45m calculated as 2 billable overtime hours.");
        $this->assert($otLate['overtime_charges'] == 120.00, "Calculated ₹120.00 penalty fee.");
    }
}

$suite = new ReservationPricingPaymentTestSuite();
$suite->runAll();
