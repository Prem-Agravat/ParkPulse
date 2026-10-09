<?php
/**
 * Reservation & Concurrency Management Service
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/PricingService.php';

final class ReservationService
{
    private PDO $db;
    private PricingService $pricingService;

    public function __construct(?PDO $db = null, ?PricingService $pricingService = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->pricingService = $pricingService ?? new PricingService($this->db);
    }

    /**
     * Create an atomic reservation with row locking to prevent double booking race conditions
     */
    public function createReservation(
        int $userId,
        int $facilityId,
        int $slotId,
        string $vehiclePlate,
        ?int $vehicleId,
        string $startTime,
        string $endTime,
        string $paymentMethod = 'card',
        bool $allowPast = false,
        string $vehicleCategory = 'car',
        bool $isEv = false,
        bool $evChargingRequested = false
    ): array {
        $startTs = strtotime($startTime);
        $endTs = strtotime($endTime);
        $nowTs = time() - 300;

        if (!$startTs || !$endTs || (!$allowPast && $startTs < $nowTs)) {
            throw new \InvalidArgumentException('Check-in time cannot be in the past.');
        }
        if ($endTs <= $startTs) {
            throw new \InvalidArgumentException('Check-out time must be after check-in time.');
        }
        if (($endTs - $startTs) < 1800) {
            throw new \InvalidArgumentException('Minimum reservation duration is 30 minutes.');
        }
        if (!Security::validatePlate($vehiclePlate)) {
            throw new \InvalidArgumentException('Invalid license plate format.');
        }

        try {
            $this->db->beginTransaction();

            // 1. Verify Facility Status
            $facStmt = $this->db->prepare("SELECT * FROM facilities WHERE id = ? FOR UPDATE");
            $facStmt->execute([$facilityId]);
            $facility = $facStmt->fetch();

            if (!$facility) {
                $this->db->rollBack();
                throw new \InvalidArgumentException('Selected facility does not exist.');
            }
            if ($facility['status'] === 'maintenance' || $facility['status'] === 'inactive') {
                $this->db->rollBack();
                throw new \RuntimeException("Facility '{$facility['name']}' is currently closed for maintenance.");
            }

            // 2. Lock slot row and verify active status
            $slotStmt = $this->db->prepare("SELECT * FROM slots WHERE id = ? AND facility_id = ? AND is_active = 1 FOR UPDATE");
            $slotStmt->execute([$slotId, $facilityId]);
            $slot = $slotStmt->fetch();

            if (!$slot) {
                $this->db->rollBack();
                throw new \RuntimeException('Selected parking slot is invalid or inactive.');
            }
            if ($slot['status'] === 'maintenance') {
                $this->db->rollBack();
                throw new \RuntimeException("Slot {$slot['slot_number']} is currently out of service for maintenance.");
            }

            // 3. Vehicle compatibility & EV validation
            if ($vehicleId) {
                $vStmt = $this->db->prepare("SELECT vehicle_type, is_ev FROM vehicles WHERE id = ? AND user_id = ?");
                $vStmt->execute([$vehicleId, $userId]);
                $veh = $vStmt->fetch();
                if ($veh) {
                    $vehicleCategory = $veh['vehicle_type'];
                    $isEv = (bool)$veh['is_ev'];
                }
            }

            // Enforce size/category compatibility
            $slotType = $slot['slot_type'];
            if (in_array($vehicleCategory, ['truck', 'bus'], true) && in_array($slotType, ['bike', 'bicycle', 'auto_rickshaw', 'car'], true)) {
                $this->db->rollBack();
                throw new \InvalidArgumentException("Vehicle category '{$vehicleCategory}' cannot fit into '{$slotType}' stall {$slot['slot_number']}.");
            }
            if ($vehicleCategory === 'van' && in_array($slotType, ['bike', 'bicycle'], true)) {
                $this->db->rollBack();
                throw new \InvalidArgumentException("Van cannot fit into '{$slotType}' stall {$slot['slot_number']}.");
            }
            if ($vehicleCategory === 'car' && in_array($slotType, ['bike', 'bicycle'], true)) {
                $this->db->rollBack();
                throw new \InvalidArgumentException("Car cannot fit into two-wheeler / cycle stall {$slot['slot_number']}.");
            }

            // Enforce EV charging requirement
            if ($evChargingRequested && empty($slot['is_ev_charging'])) {
                $this->db->rollBack();
                throw new \InvalidArgumentException("Stall {$slot['slot_number']} does not have an EV charger. Please select an EV-enabled bay.");
            }

            // 4. Check for overlapping reservations for this slot (Row Lock Revalidation)
            $overlapStmt = $this->db->prepare("SELECT id FROM reservations 
                WHERE slot_id = ? 
                AND status IN ('confirmed', 'checked_in') 
                AND (start_time < ? AND end_time > ?) 
                FOR UPDATE");
            $overlapStmt->execute([$slotId, $endTime, $startTime]);
            if ($overlapStmt->fetch()) {
                $this->db->rollBack();
                throw new \RuntimeException("Slot {$slot['slot_number']} was just booked by another driver for this time window. Please select another slot.");
            }

            // 5. Compute Fare using PricingService
            $fare = $this->pricingService->calculateFare($facilityId, $startTime, $endTime, $vehicleCategory, $isEv, $evChargingRequested);

            // Handle explicit failed payment simulation
            if ($paymentMethod === 'simulate_failure') {
                $this->db->rollBack();
                throw new \RuntimeException('Simulated payment declined by bank gateway. Reservation was not confirmed.');
            }

            // 6. Generate Unguessable Booking Reference & QR Pass Token
            $bookingRef = 'PK-2026-' . strtoupper(bin2hex(random_bytes(3))) . rand(10, 99);
            $qrToken = Security::generateQrToken();
            $qrTokenHash = Security::hashQrToken($qrToken);

            // 7. Insert into reservations
            $resStmt = $this->db->prepare("INSERT INTO reservations 
                (booking_reference, user_id, facility_id, slot_id, vehicle_id, vehicle_plate, vehicle_type, is_ev, ev_charging_requested, start_time, end_time, total_hours, base_rate, base_amount, extra_amount, total_amount, status, qr_token, qr_token_hash, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, NOW())");
            $resStmt->execute([
                $bookingRef,
                $userId,
                $facilityId,
                $slotId,
                $vehicleId,
                $vehiclePlate,
                $vehicleCategory,
                $isEv ? 1 : 0,
                $evChargingRequested ? 1 : 0,
                $startTime,
                $endTime,
                $fare['duration_hours'],
                $fare['hourly_rate'],
                $fare['base_amount'],
                $fare['extra_amount'] ?? 0.00,
                $fare['total_amount'],
                $qrToken,
                $qrTokenHash
            ]);
            $reservationId = (int)$this->db->lastInsertId();

            // 8. Insert into qr_passes table
            $passCode = 'PASS-' . substr($bookingRef, 3);
            $passStmt = $this->db->prepare("INSERT INTO qr_passes (reservation_id, pass_code, pass_token, token_hash, status, expires_at, created_at) VALUES (?, ?, ?, ?, 'active', ?, NOW())");
            $passStmt->execute([$reservationId, $passCode, $qrToken, $qrTokenHash, $endTime]);

            // 9. Record Simulated Payment
            $txnRef = 'TXN_SIM_' . strtoupper(bin2hex(random_bytes(6)));
            $payStmt = $this->db->prepare("INSERT INTO payments 
                (reservation_id, user_id, transaction_ref, payment_method, amount, status, payment_type, gateway_provider, simulated_meta, created_at)
                VALUES (?, ?, ?, ?, ?, 'success', 'reservation_fee', 'Simulated Gateway (Code2Web 2026)', ?, NOW())");
            
            $simMeta = json_encode([
                'method' => $paymentMethod,
                'simulation' => true,
                'fare_breakdown' => $fare,
                'vehicle_category' => $vehicleCategory,
                'is_ev' => $isEv,
                'ev_charging_requested' => $evChargingRequested,
                'currency' => 'INR',
                'currency_symbol' => '₹',
                'ip_address' => Security::getClientIp(),
            ]);
            $payStmt->execute([$reservationId, $userId, $txnRef, $paymentMethod, $fare['total_amount'], $simMeta]);

            $this->db->commit();

            AuditLogger::log('RESERVATION_CREATED', 'reservations', $reservationId, "Booking {$bookingRef} created for Slot {$slot['slot_number']} ({$vehiclePlate}). Total: ₹{$fare['total_amount']}");

            return [
                'reservation_id' => $reservationId,
                'booking_reference' => $bookingRef,
                'slot_number' => $slot['slot_number'],
                'total_amount' => $fare['total_amount'],
                'qr_token' => $qrToken,
                'qr_pass_code' => $passCode,
                'start_time' => $startTime,
                'end_time' => $endTime
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel an active reservation and initiate refund
     */
    public function cancelReservation(string $bookingReference, int $actorUserId, bool $isAdmin = false, string $reason = 'Driver requested cancellation'): array
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT * FROM reservations WHERE booking_reference = ? FOR UPDATE");
            $stmt->execute([$bookingReference]);
            $booking = $stmt->fetch();

            if (!$booking) {
                $this->db->rollBack();
                throw new \InvalidArgumentException('Reservation not found.');
            }

            if (!$isAdmin && (int)$booking['user_id'] !== $actorUserId) {
                $this->db->rollBack();
                throw new \RuntimeException('Unauthorized to cancel this booking.');
            }

            if (!in_array($booking['status'], ['confirmed', 'pending'], true)) {
                $this->db->rollBack();
                throw new \RuntimeException("Cannot cancel booking with current status '{$booking['status']}'.");
            }

            $bookingId = (int)$booking['id'];

            // 1. Update reservation status
            $upd = $this->db->prepare("UPDATE reservations SET status = 'cancelled', cancellation_reason = ?, cancelled_at = NOW() WHERE id = ?");
            $upd->execute([$reason, $bookingId]);

            // 2. Revoke QR pass
            $this->db->prepare("UPDATE qr_passes SET status = 'revoked' WHERE reservation_id = ?")->execute([$bookingId]);

            // 3. Free up slot status
            $this->db->prepare("UPDATE slots SET status = 'available' WHERE id = ? AND status = 'reserved'")->execute([$booking['slot_id']]);

            // 4. Create Refund Payment Record
            $refundRef = 'REF_' . strtoupper(bin2hex(random_bytes(6)));
            $refundStmt = $this->db->prepare("INSERT INTO payments (reservation_id, user_id, transaction_ref, payment_method, amount, status, payment_type, gateway_provider, simulated_meta, created_at) 
                VALUES (?, ?, ?, 'simulation', ?, 'refunded', 'refund', 'Simulated Refund Gateway', ?, NOW())");
            $refundStmt->execute([
                $bookingId,
                $booking['user_id'],
                $refundRef,
                $booking['total_amount'],
                json_encode(['cancellation_reason' => $reason, 'refund_mode' => 'original_source'])
            ]);

            $this->db->commit();

            AuditLogger::log('RESERVATION_CANCELLED', 'reservations', $bookingId, "Reservation {$bookingReference} cancelled by User ID {$actorUserId}. Refund: ₹{$booking['total_amount']}");

            return [
                'success' => true,
                'booking_reference' => $bookingReference,
                'refund_amount' => (float)$booking['total_amount'],
                'refund_transaction_ref' => $refundRef
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
