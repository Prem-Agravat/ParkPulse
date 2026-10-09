<?php
/**
 * Attendant Gate Barrier & Parking Session Service
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/PricingService.php';

final class GateService
{
    private PDO $db;
    private PricingService $pricingService;

    public function __construct(?PDO $db = null, ?PricingService $pricingService = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->pricingService = $pricingService ?? new PricingService($this->db);
    }

    /**
     * Search and validate booking pass by QR token, hash, booking ref, URL, or plate number
     */
    public function lookupPass(string $query): ?array
    {
        $query = trim($query);
        if (empty($query)) return null;

        // If a full URL was scanned (e.g. pass.php?ref=PK-2026-...), extract the ref parameter
        $extractedRef = $query;
        if (preg_match('/[?&]ref=([a-zA-Z0-9_\-]+)/i', $query, $urlMatches)) {
            $extractedRef = $urlMatches[1];
        } elseif (preg_match('/[?&]token=([a-zA-Z0-9_\-]+)/i', $query, $tokenMatches)) {
            $extractedRef = $tokenMatches[1];
        } elseif (str_starts_with($query, '{') && str_ends_with($query, '}')) {
            $decoded = json_decode($query, true);
            if (is_array($decoded)) {
                $extractedRef = (string)($decoded['ref'] ?? $decoded['booking_reference'] ?? $decoded['qr_token'] ?? $query);
            }
        }

        $salt = env('QR_SECRET_SALT', 'default_salt_2026_smart_parking');
        $hashedToken = hash_hmac('sha256', $query, $salt);
        $hashedExtracted = hash_hmac('sha256', $extractedRef, $salt);

        // Clean and prepare search patterns
        $cleanPlate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $query));
        $likeRef = '%' . strtoupper($extractedRef) . '%';
        $likePlate = '%' . $cleanPlate . '%';

        $sql = "SELECT r.*, 
                f.name AS facility_name, f.code AS facility_code, f.grace_period_mins, f.overtime_rate_per_hour,
                s.slot_number, s.floor_level, s.zone_name, s.slot_type, s.is_ev_charging,
                v.make_model, v.color,
                u.name AS driver_name, u.phone AS driver_phone, u.email AS driver_email,
                qp.pass_code, qp.status AS pass_status
                FROM reservations r
                JOIN facilities f ON r.facility_id = f.id
                JOIN slots s ON r.slot_id = s.id
                LEFT JOIN vehicles v ON r.vehicle_id = v.id
                JOIN users u ON r.user_id = u.id
                LEFT JOIN qr_passes qp ON r.id = qp.reservation_id
                WHERE (
                    r.qr_token = ? 
                    OR r.qr_token_hash = ? 
                    OR r.qr_token = ?
                    OR r.qr_token_hash = ?
                    OR UPPER(r.booking_reference) = UPPER(?) 
                    OR UPPER(r.booking_reference) = UPPER(?)
                    OR UPPER(r.booking_reference) LIKE ?
                    OR qp.pass_code = ?
                    OR qp.pass_code = ?
                    OR qp.pass_token = ?
                    OR (LENGTH(?) >= 3 AND REPLACE(REPLACE(UPPER(r.vehicle_plate), '-', ''), ' ', '') LIKE ?)
                    OR (LENGTH(?) >= 3 AND UPPER(r.vehicle_plate) LIKE ?)
                    " . (ctype_digit($query) ? " OR r.id = " . (int)$query : "") . "
                )
                ORDER BY r.created_at DESC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $query,
            $hashedToken,
            $extractedRef,
            $hashedExtracted,
            $query,
            $extractedRef,
            $likeRef,
            $query,
            $extractedRef,
            $query,
            $cleanPlate,
            $likePlate,
            $query,
            '%' . strtoupper($query) . '%'
        ]);
        $booking = $stmt->fetch();

        if (!$booking) return null;

        $nowTs = time();
        $startTs = strtotime($booking['start_time']);
        $endTs = strtotime($booking['end_time']);
        $graceMins = (int)$booking['grace_period_mins'];

        $isEarly = ($startTs - $nowTs) > 1800; // Arrived > 30 mins early
        $isExpired = ($booking['status'] === 'confirmed' && $nowTs > ($endTs + ($graceMins * 60)));

        $overtimeInfo = $this->pricingService->calculateOvertime((int)$booking['facility_id'], $booking['end_time'], $nowTs);

        return [
            'id' => (int)$booking['id'],
            'booking_reference' => $booking['booking_reference'],
            'status' => $booking['status'],
            'facility_id' => (int)$booking['facility_id'],
            'facility_name' => $booking['facility_name'],
            'slot_id' => (int)$booking['slot_id'],
            'slot_number' => $booking['slot_number'],
            'floor_level' => $booking['floor_level'],
            'zone_name' => $booking['zone_name'],
            'is_ev_charging' => (bool)$booking['is_ev_charging'],
            'driver_name' => $booking['driver_name'],
            'driver_phone' => $booking['driver_phone'],
            'vehicle_plate' => $booking['vehicle_plate'],
            'make_model' => $booking['make_model'] ?? 'Standard Vehicle',
            'color' => $booking['color'] ?? 'N/A',
            'start_time' => $booking['start_time'],
            'end_time' => $booking['end_time'],
            'check_in_time' => $booking['check_in_time'],
            'check_out_time' => $booking['check_out_time'],
            'total_amount' => (float)$booking['total_amount'],
            'grace_period_mins' => $graceMins,
            'is_early' => $isEarly,
            'is_expired' => $isExpired,
            'is_overstay' => $booking['status'] === 'checked_in' && $overtimeInfo['is_overstay'],
            'overtime_mins' => $overtimeInfo['overtime_minutes'],
            'overtime_charge' => $overtimeInfo['overtime_charges']
        ];
    }

    /**
     * Authorize vehicle entry and process gate check-in
     */
    public function processCheckIn(int $bookingId, int $attendantId): array
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT r.*, s.slot_number FROM reservations r JOIN slots s ON r.slot_id = s.id WHERE r.id = ? FOR UPDATE");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                $this->db->rollBack();
                throw new \InvalidArgumentException('Booking record not found.');
            }

            if ($booking['status'] !== 'confirmed') {
                $this->db->rollBack();
                throw new \RuntimeException("Cannot check-in reservation with status '{$booking['status']}'.");
            }

            $now = date('Y-m-d H:i:s');

            // 1. Update reservation
            $upd = $this->db->prepare("UPDATE reservations SET status = 'checked_in', check_in_time = ?, checked_in_by = ? WHERE id = ?");
            $upd->execute([$now, $attendantId, $bookingId]);

            // 2. Mark QR pass as used
            $this->db->prepare("UPDATE qr_passes SET status = 'used' WHERE reservation_id = ?")->execute([$bookingId]);

            // 3. Mark Slot as occupied
            $this->db->prepare("UPDATE slots SET status = 'occupied' WHERE id = ?")->execute([$booking['slot_id']]);

            // 4. Create active parking session record
            $sessStmt = $this->db->prepare("INSERT INTO parking_sessions 
                (reservation_id, facility_id, slot_id, vehicle_plate, check_in_time, checked_in_by, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
            $sessStmt->execute([$bookingId, $booking['facility_id'], $booking['slot_id'], $booking['vehicle_plate'], $now, $attendantId]);

            $this->db->commit();

            AuditLogger::log('GATE_CHECK_IN', 'reservations', $bookingId, "Vehicle {$booking['vehicle_plate']} admitted to Slot {$booking['slot_number']} by Attendant ID {$attendantId}.");

            return [
                'success' => true,
                'action' => 'check_in',
                'vehicle_plate' => $booking['vehicle_plate'],
                'slot_number' => $booking['slot_number'],
                'check_in_time' => $now
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Process vehicle exit, calculate overtime fee, and release slot
     */
    public function processCheckOut(int $bookingId, int $attendantId): array
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT r.*, s.slot_number, f.overtime_rate_per_hour, f.grace_period_mins 
                FROM reservations r 
                JOIN slots s ON r.slot_id = s.id 
                JOIN facilities f ON r.facility_id = f.id
                WHERE r.id = ? FOR UPDATE");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                $this->db->rollBack();
                throw new \InvalidArgumentException('Booking record not found.');
            }

            if ($booking['status'] !== 'checked_in') {
                $this->db->rollBack();
                throw new \RuntimeException("Cannot check-out reservation with status '{$booking['status']}'.");
            }

            $nowTs = time();
            $now = date('Y-m-d H:i:s', $nowTs);
            $checkInTs = strtotime($booking['check_in_time'] ?: $booking['start_time']);
            $actualDurationHours = max(0.2, round(($nowTs - $checkInTs) / 3600, 2));

            // Calculate overtime
            $overtimeInfo = $this->pricingService->calculateOvertime((int)$booking['facility_id'], $booking['end_time'], $nowTs);
            $overtimeCharge = $overtimeInfo['overtime_charges'];
            $overtimeMins = $overtimeInfo['overtime_minutes'];

            // 1. Update reservation to completed
            $upd = $this->db->prepare("UPDATE reservations SET 
                status = 'completed', 
                check_out_time = ?, 
                checked_out_by = ?,
                actual_hours = ?,
                overtime_amount = ? 
                WHERE id = ?");
            $upd->execute([$now, $attendantId, $actualDurationHours, $overtimeCharge, $bookingId]);

            // 2. Update parking session record
            $sessUpd = $this->db->prepare("UPDATE parking_sessions SET 
                check_out_time = ?,
                checked_out_by = ?,
                total_duration_hours = ?,
                overtime_duration_minutes = ?,
                overtime_charges = ?,
                status = 'completed'
                WHERE reservation_id = ? AND status = 'active'");
            $sessUpd->execute([$now, $attendantId, $actualDurationHours, $overtimeMins, $overtimeCharge, $bookingId]);

            // 3. Record penalty payment if assessed
            if ($overtimeCharge > 0) {
                $txnRef = 'TXN_OVERSTAY_' . strtoupper(bin2hex(random_bytes(5)));
                $payStmt = $this->db->prepare("INSERT INTO payments 
                    (reservation_id, user_id, transaction_ref, payment_method, amount, status, payment_type, gateway_provider, simulated_meta, created_at)
                    VALUES (?, ?, ?, 'simulation', ?, 'success', 'overstay_penalty', 'Gate Overstay Collection', ?, NOW())");
                $payStmt->execute([
                    $bookingId,
                    $booking['user_id'],
                    $txnRef,
                    $overtimeCharge,
                    json_encode(['overtime_mins' => $overtimeMins, 'collected_by' => $attendantId])
                ]);
            }

            // 4. Free slot back to available
            $this->db->prepare("UPDATE slots SET status = 'available' WHERE id = ?")->execute([$booking['slot_id']]);

            $this->db->commit();

            AuditLogger::log('GATE_CHECK_OUT', 'reservations', $bookingId, "Vehicle {$booking['vehicle_plate']} checked out from Slot {$booking['slot_number']}. Duration: {$actualDurationHours}h, Overtime: ₹{$overtimeCharge}.");

            return [
                'success' => true,
                'action' => 'check_out',
                'vehicle_plate' => $booking['vehicle_plate'],
                'slot_number' => $booking['slot_number'],
                'actual_hours' => $actualDurationHours,
                'overtime_mins' => $overtimeMins,
                'overtime_charges' => $overtimeCharge,
                'check_out_time' => $now
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
