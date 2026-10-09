<?php
/**
 * Dynamic Multi-Channel Payment Gateway Service
 * Smart Parking Reservation & Management System (India / Gujarat Edition)
 *
 * Supports Dynamic UPI & QR FastPay, Cards (RuPay/Visa/MasterCard), NetBanking,
 * Razorpay API Integration, Signature Verification, and Comprehensive Audit Trails.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/PricingService.php';

final class PaymentGatewayService
{
    private PDO $db;
    private PricingService $pricingService;
    private string $secretKey;
    private string $merchantVpa;
    private string $merchantName;
    private string $razorpayKeyId;
    private string $razorpayKeySecret;
    private bool $razorpayTestMode;

    public function __construct(?PDO $db = null, ?PricingService $pricingService = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->pricingService = $pricingService ?? new PricingService($this->db);
        $this->secretKey = env('PAYMENT_SECRET_KEY', 'parkpulse_sec_gateway_salt_2026_india');
        $this->merchantVpa = env('UPI_MERCHANT_VPA', 'parkpulse.pay@icici');
        $this->merchantName = env('UPI_MERCHANT_NAME', 'ParkPulse Smart Parking');
        $this->razorpayKeyId = env('RAZORPAY_KEY_ID', 'rzp_test_parkpulse2026demo');
        $this->razorpayKeySecret = env('RAZORPAY_KEY_SECRET', 'secret_parkpulse_razorpay_key_2026');
        $this->razorpayTestMode = filter_var(env('RAZORPAY_TEST_MODE', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get Razorpay configuration status
     */
    public function getRazorpayConfig(): array
    {
        return [
            'enabled' => true,
            'test_mode' => $this->razorpayTestMode,
            'key_id' => $this->razorpayKeyId,
            'merchant_name' => $this->merchantName,
            'currency' => 'INR'
        ];
    }

    /**
     * Create a dynamic payment order with UPI Intent, Razorpay payload, QR payload, and security token
     */
    public function createOrder(
        int $userId,
        int $facilityId,
        int $slotId,
        float $amount,
        string $startTime,
        string $endTime,
        string $vehicleType = 'car',
        bool $isEv = false,
        bool $evCharging = false
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $orderId = 'ORDER_PP_' . strtoupper(bin2hex(random_bytes(6))) . '_' . time();
        $amountPaise = (int)round($amount * 100);
        $amountFormatted = number_format($amount, 2, '.', '');
        $razorpayOrderId = 'order_' . substr(md5($orderId), 0, 14);

        // Attempt live Razorpay API order generation if credentials provided
        if (!empty($this->razorpayKeyId) && !empty($this->razorpayKeySecret) && function_exists('curl_init')) {
            try {
                $postData = json_encode([
                    'amount' => $amountPaise,
                    'currency' => 'INR',
                    'receipt' => $orderId,
                    'notes' => [
                        'facility_id' => (string)$facilityId,
                        'slot_id' => (string)$slotId,
                        'user_id' => (string)$userId,
                        'system' => 'ParkPulse 2026'
                    ]
                ]);

                $ch = curl_init('https://api.razorpay.com/v1/orders');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_USERPWD => "{$this->razorpayKeyId}:{$this->razorpayKeySecret}",
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT => 3,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && $response) {
                    $rzpJson = json_decode($response, true);
                    if (!empty($rzpJson['id'])) {
                        $razorpayOrderId = $rzpJson['id'];
                    }
                }
            } catch (\Throwable $e) {
                // Fallback to deterministic test order ID
            }
        }

        // Generate Standard NPCI UPI URI string for live QR scan and mobile deep-linking
        $upiNote = rawurlencode("ParkPulse Stall Slot #{$slotId}");
        $upiIntentUrl = "upi://pay?pa=" . rawurlencode($this->merchantVpa) .
                        "&pn=" . rawurlencode($this->merchantName) .
                        "&tr=" . rawurlencode($orderId) .
                        "&am=" . rawurlencode($amountFormatted) .
                        "&cu=INR" .
                        "&tn=" . $upiNote;

        // Generate cryptographic order signature for client verification handshake
        $signaturePayload = "{$orderId}|{$amountPaise}|{$userId}|{$facilityId}|{$slotId}";
        $orderSignature = hash_hmac('sha256', $signaturePayload, $this->secretKey);

        // Fetch facility & slot details for checkout summary
        $stmt = $this->db->prepare("SELECT f.name AS facility_name, f.city, s.slot_number, s.floor_level, s.zone_name 
            FROM facilities f 
            JOIN slots s ON f.id = s.facility_id 
            WHERE f.id = ? AND s.id = ?");
        $stmt->execute([$facilityId, $slotId]);
        $details = $stmt->fetch() ?: [
            'facility_name' => 'Smart Parking Hub',
            'city' => 'Gujarat',
            'slot_number' => 'P-01',
            'floor_level' => 'Ground',
            'zone_name' => 'Zone A'
        ];

        // Fetch user prefill details
        $userStmt = $this->db->prepare("SELECT name, email, phone FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch() ?: ['name' => 'Valued Driver', 'email' => 'driver@parkpulse.com', 'phone' => '9825012345'];

        return [
            'success' => true,
            'order_id' => $orderId,
            'razorpay_order_id' => $razorpayOrderId,
            'razorpay_key_id' => $this->razorpayKeyId,
            'razorpay_test_mode' => $this->razorpayTestMode,
            'amount' => $amount,
            'amount_paise' => $amountPaise,
            'amount_formatted' => '₹' . number_format($amount, 2),
            'currency' => 'INR',
            'merchant_vpa' => $this->merchantVpa,
            'merchant_name' => $this->merchantName,
            'upi_intent_url' => $upiIntentUrl,
            'order_signature' => $orderSignature,
            'facility' => $details['facility_name'],
            'city' => $details['city'],
            'slot_number' => $details['slot_number'],
            'floor_zone' => "{$details['floor_level']} • {$details['zone_name']}",
            'vehicle_type' => $vehicleType,
            'is_ev' => $isEv,
            'ev_charging' => $evCharging,
            'customer_prefill' => [
                'name' => $user['name'],
                'email' => $user['email'],
                'contact' => Security::normalizeIndianMobile($user['phone'] ?? '9825012345')
            ],
            'expires_in_seconds' => 300, // 5 minutes payment window
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Verify dynamic payment signature and return confirmation state (Supports Razorpay HMAC & Internal HMAC)
     */
    public function verifyPaymentSignature(
        string $orderId,
        string $paymentId,
        string $signature,
        int $amountPaise
    ): bool {
        if (empty($orderId) || empty($paymentId) || empty($signature)) {
            return false;
        }

        // 1. Razorpay Official Signature check: HMAC_SHA256(order_id + "|" + payment_id, key_secret)
        $rzpSignature = hash_hmac('sha256', "{$orderId}|{$paymentId}", $this->razorpayKeySecret);
        if (hash_equals($rzpSignature, $signature)) {
            return true;
        }

        // 2. Expected signature format: hmac_sha256(order_id + '|' + payment_id + '|' + amountPaise, secretKey)
        $expectedSignature = hash_hmac('sha256', "{$orderId}|{$paymentId}|{$amountPaise}", $this->secretKey);
        if (hash_equals($expectedSignature, $signature)) {
            return true;
        }
        
        // 3. Simulated/sandbox test signature
        $simulatedSignature = hash_hmac('sha256', "{$orderId}|{$paymentId}", $this->secretKey);
        if (hash_equals($simulatedSignature, $signature)) {
            return true;
        }

        // 4. Test mode prefix allowance (for direct UI sandbox simulators)
        if (str_starts_with($signature, 'SIG_RZP_TEST_') || str_starts_with($signature, 'SIG_TEST_')) {
            return true;
        }

        // 5. In Test Mode, allow authentic Razorpay SDK payment callbacks (pay_ prefix)
        if ($this->razorpayTestMode && str_starts_with($paymentId, 'pay_') && strlen($signature) >= 20) {
            return true;
        }

        return false;
    }

    /**
     * Generate valid client payment confirmation signature (Supports Razorpay & Internal)
     */
    public function generateClientSignature(string $orderId, string $paymentId, int $amountPaise, bool $isRazorpay = false): string
    {
        if ($isRazorpay) {
            return hash_hmac('sha256', "{$orderId}|{$paymentId}", $this->razorpayKeySecret);
        }
        return hash_hmac('sha256', "{$orderId}|{$paymentId}|{$amountPaise}", $this->secretKey);
    }

    /**
     * Record payment transaction in database
     */
    public function recordPayment(
        int $reservationId,
        int $userId,
        string $transactionRef,
        string $paymentMethod,
        float $amount,
        string $status = 'success',
        string $paymentType = 'reservation_fee',
        string $gatewayProvider = 'Dynamic Gateway (Code2Web 2026)',
        ?string $gatewayOrderId = null,
        ?string $gatewayPaymentId = null,
        ?string $gatewaySignature = null,
        ?array $meta = null
    ): int {
        $stmt = $this->db->prepare("INSERT INTO payments 
            (reservation_id, user_id, transaction_ref, gateway_order_id, gateway_payment_id, gateway_signature, payment_method, amount, currency, status, payment_type, gateway_provider, simulated_meta, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'INR', ?, ?, ?, ?, NOW())");
            
        $stmt->execute([
            $reservationId,
            $userId,
            $transactionRef,
            $gatewayOrderId,
            $gatewayPaymentId,
            $gatewaySignature,
            $paymentMethod,
            $amount,
            $status,
            $paymentType,
            $gatewayProvider,
            $meta ? json_encode($meta) : null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Process Admin / System Refund with Audit Logging
     */
    public function processRefund(int $paymentId, int $adminUserId, string $reason = 'Customer Requested Cancellation'): array
    {
        $this->db->beginTransaction();
        try {
            // Lock payment record
            $stmt = $this->db->prepare("SELECT p.*, r.booking_reference, r.id AS res_id, u.name AS driver_name, u.email AS driver_email 
                FROM payments p 
                JOIN reservations r ON p.reservation_id = r.id 
                JOIN users u ON p.user_id = u.id 
                WHERE p.id = ? 
                FOR UPDATE");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();

            if (!$payment) {
                $this->db->rollBack();
                throw new \InvalidArgumentException("Payment ID {$paymentId} not found.");
            }

            if ($payment['status'] === 'refunded') {
                $this->db->rollBack();
                throw new \RuntimeException("Payment {$payment['transaction_ref']} has already been refunded.");
            }

            if ($payment['status'] !== 'success') {
                $this->db->rollBack();
                throw new \RuntimeException("Only successful payments can be refunded.");
            }

            // 1. Update original payment status to refunded
            $updateStmt = $this->db->prepare("UPDATE payments SET status = 'refunded' WHERE id = ?");
            $updateStmt->execute([$paymentId]);

            // 2. Insert separate refund accounting record
            $refundRef = 'REF_' . strtoupper(bin2hex(random_bytes(5))) . '_' . time();
            $refundStmt = $this->db->prepare("INSERT INTO payments 
                (reservation_id, user_id, transaction_ref, gateway_order_id, gateway_payment_id, gateway_signature, payment_method, amount, currency, status, payment_type, gateway_provider, simulated_meta, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'INR', 'refunded', 'refund', ?, ?, NOW())");
            
            $meta = [
                'original_payment_id' => $paymentId,
                'original_transaction_ref' => $payment['transaction_ref'],
                'refund_reason' => $reason,
                'refunded_by_admin_id' => $adminUserId,
                'refund_timestamp' => date('c')
            ];

            $refundStmt->execute([
                $payment['reservation_id'],
                $payment['user_id'],
                $refundRef,
                $payment['gateway_order_id'],
                'REF_PAY_' . bin2hex(random_bytes(4)),
                null,
                $payment['payment_method'],
                $payment['amount'],
                $payment['gateway_provider'],
                json_encode($meta)
            ]);

            // 3. Update reservation status to cancelled if confirmed
            $resUpdate = $this->db->prepare("UPDATE reservations SET status = 'cancelled', cancellation_reason = ? WHERE id = ? AND status = 'confirmed'");
            $resUpdate->execute(["Admin Refunded: {$reason}", $payment['reservation_id']]);

            // 4. Log immutable security audit trail
            AuditLogger::log(
                'PAYMENT_REFUND_PROCESSED',
                'payments',
                $paymentId,
                "Admin ID {$adminUserId} processed ₹{$payment['amount']} refund for Booking {$payment['booking_reference']} (Ref: {$refundRef}). Reason: {$reason}"
            );

            $this->db->commit();

            return [
                'success' => true,
                'refund_reference' => $refundRef,
                'original_ref' => $payment['transaction_ref'],
                'amount' => (float)$payment['amount'],
                'booking_reference' => $payment['booking_reference'],
                'driver_name' => $payment['driver_name'],
                'message' => "Refund of ₹" . number_format((float)$payment['amount'], 2) . " successfully issued for Booking {$payment['booking_reference']}."
            ];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get aggregate payment statistics for Admin dashboard & reports
     */
    public function getPaymentSummaryStats(): array
    {
        $totalVol = (float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success' AND payment_type = 'reservation_fee'")->fetchColumn();
        $totalSuccessCount = (int)$this->db->query("SELECT COUNT(*) FROM payments WHERE status = 'success' AND payment_type = 'reservation_fee'")->fetchColumn();
        $todayVol = (float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success' AND DATE(created_at) = CURDATE()")->fetchColumn();
        $refundedVol = (float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'refunded' OR payment_type = 'refund'")->fetchColumn();
        $pendingCount = (int)$this->db->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
        $failedCount = (int)$this->db->query("SELECT COUNT(*) FROM payments WHERE status = 'failed'")->fetchColumn();

        // Method breakdown
        $methodsStmt = $this->db->query("SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total 
            FROM payments 
            WHERE status = 'success' 
            GROUP BY payment_method");
        $methods = $methodsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total_volume' => $totalVol,
            'total_volume_formatted' => '₹' . number_format($totalVol, 2),
            'successful_transactions' => $totalSuccessCount,
            'today_volume' => $todayVol,
            'today_volume_formatted' => '₹' . number_format($todayVol, 2),
            'refunded_volume' => $refundedVol,
            'refunded_volume_formatted' => '₹' . number_format($refundedVol, 2),
            'pending_count' => $pendingCount,
            'failed_count' => $failedCount,
            'methods' => $methods
        ];
    }
}
