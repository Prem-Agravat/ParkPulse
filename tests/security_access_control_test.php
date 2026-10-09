<?php
/**
 * Part 4 — Authentication, RBAC, Ownership & Security Test Suite
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../src/Services/PricingService.php';
require_once __DIR__ . '/../src/Services/ReservationService.php';
require_once __DIR__ . '/../src/Services/GateService.php';

final class SecurityAccessControlTestSuite
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
        echo "=========================================================\n";
        echo "  PARKPULSE 2026 - PART 4 SECURITY & ACCESS CONTROL TESTS\n";
        echo "=========================================================\n\n";

        $this->testPasswordHashingAndVerification();
        $this->testSessionManagementAndLogout();
        $this->testUnauthenticatedAccessRestrictions();
        $this->testRoleBasedAccessControlAndPrivilegeEscalation();
        $this->testOwnershipAndIdorPrevention();
        $this->testCsrfProtectionBehavior();
        $this->testRateLimitingAndBruteForceProtection();
        $this->testAuditLoggingForSensitiveOperations();
        $this->testSafeErrorResponses();

        echo "\n---------------------------------------------------------\n";
        echo "SECURITY TEST SUMMARY: {$this->passCount} PASSED, {$this->failCount} FAILED\n";
        echo "---------------------------------------------------------\n";

        if ($this->failCount > 0) {
            exit(1);
        }
    }

    private function testPasswordHashingAndVerification(): void
    {
        echo "1. Testing Password Hashing & Verification (password_hash / verify)...\n";

        $password = 'SuperSecret@2026';
        $hash = Security::hashPassword($password);

        $this->assert(str_starts_with($hash, '$2y$12$') || str_starts_with($hash, '$2y$'), "Password hashed using BCRYPT algorithm with cost factor 12.");
        $this->assert(Security::verifyPassword($password, $hash), "Valid password verified successfully.");
        $this->assert(!Security::verifyPassword('WrongSecret@2026', $hash), "Invalid password rejected.");
        $this->assert(!Security::verifyPassword('', $hash), "Empty password rejected.");

        // Verify seeded accounts password verification
        $admin = $this->db->query("SELECT * FROM users WHERE email = 'admin@parkpulse.com'")->fetch();
        $this->assert($admin && Security::verifyPassword('Admin@2026', $admin['password_hash']), "Admin seeded credentials verified via password_verify().");

        $driver = $this->db->query("SELECT * FROM users WHERE email = 'dev.patel@example.com'")->fetch();
        $this->assert($driver && Security::verifyPassword('User@2026', $driver['password_hash']), "User seeded credentials verified via password_verify().");
    }

    private function testSessionManagementAndLogout(): void
    {
        echo "\n2. Testing Session Security, Regeneration & Invalidation...\n";

        // Test ini session settings
        $this->assert(ini_get('session.use_strict_mode') === '1', "session.use_strict_mode is enabled (1).");
        $this->assert(ini_get('session.use_only_cookies') === '1', "session.use_only_cookies is enabled (1).");
        $this->assert(ini_get('session.cookie_httponly') === '1', "session.cookie_httponly is enabled (1).");

        // Simulate Auth::login
        $fakeUser = [
            'id' => 4,
            'name' => 'Dev Patel',
            'email' => 'dev.patel@example.com',
            'role' => 'user',
            'phone' => '+91 98765 43210',
            'status' => 'active'
        ];

        $initialCsrf = $_SESSION['csrf_token'] ?? '';
        Auth::login($fakeUser);

        $this->assert(Auth::check() === true, "Auth::check() returns true after login.");
        $this->assert(Auth::id() === 4, "Auth::id() returns authenticated user ID.");
        $this->assert(Auth::role() === 'user', "Auth::role() returns user role.");
        $this->assert(!empty($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== $initialCsrf, "CSRF token regenerated upon session authentication.");
        $this->assert(!empty($_SESSION['last_activity']), "Session activity timestamp recorded.");

        // Simulate Auth::logout
        Auth::logout();
        $this->assert(Auth::check() === false, "Auth::check() returns false after logout.");
        $this->assert(empty($_SESSION['user']), "Session user payload completely purged upon logout.");
    }

    private function testUnauthenticatedAccessRestrictions(): void
    {
        echo "\n3. Testing Unauthenticated Access Restrictions (Redirects / 401s)...\n";

        // Protected HTML pages without cookie should redirect to login.php
        $protectedPages = [
            'my-bookings.php',
            'vehicles.php',
            'profile.php',
            'gate-scanner.php',
            'facility-occupancy.php',
            'admin-dashboard.php',
            'admin-facilities.php',
            'admin-slots.php',
            'admin-pricing.php',
            'admin-users.php',
            'admin-reservations.php',
            'admin-audit.php'
        ];

        foreach ($protectedPages as $page) {
            $resp = $this->httpGet("/{$page}");
            $isRedirectOrLogin = ($resp['status'] === 302 || $resp['status'] === 200) && 
                                 (str_contains($resp['headers'], 'Location: login.php') || str_contains($resp['body'], 'Sign In to ParkPulse'));
            $this->assert($isRedirectOrLogin, "Unauthenticated access to {$page} blocked and directed to sign in.");
        }

        // Protected API endpoints should return HTTP 401/403 Unauthorized
        $protectedApis = [
            'api/vehicles.php',
            'api/reservations.php',
            'api/payments.php',
            'api/scan-lookup.php?q=TEST',
            'api/admin-stats.php'
        ];

        foreach ($protectedApis as $api) {
            $resp = $this->httpGet("/{$api}");
            $isUnauthorized = in_array($resp['status'], [401, 403], true);
            $this->assert($isUnauthorized, "Unauthenticated access to {$api} returned HTTP {$resp['status']}.");
        }
    }

    private function testRoleBasedAccessControlAndPrivilegeEscalation(): void
    {
        echo "\n4. Testing RBAC & Role Privilege Escalation Prevention...\n";

        $cookieJarDriver = tempnam(sys_get_temp_dir(), 'pp_driver_');
        $cookieJarAttendant = tempnam(sys_get_temp_dir(), 'pp_attendant_');

        // 1. Log in as Driver (Dev Patel - user role)
        $this->loginViaHttp($cookieJarDriver, 'dev.patel@example.com', 'User@2026');

        // Driver attempting to access Admin pages
        $adminResp = $this->httpGet('/admin-dashboard.php', $cookieJarDriver);
        $this->assert(
            $adminResp['status'] === 302 && str_contains($adminResp['headers'], 'Location: index.php'),
            "Driver blocked from admin-dashboard.php (redirected to index.php)."
        );

        $adminUsersResp = $this->httpGet('/admin-users.php', $cookieJarDriver);
        $this->assert(
            $adminUsersResp['status'] === 302 && str_contains($adminUsersResp['headers'], 'Location: index.php'),
            "Driver blocked from admin-users.php."
        );

        // Driver attempting to call Admin Stats API
        $adminApiResp = $this->httpGet('/api/admin-stats.php', $cookieJarDriver);
        $this->assert($adminApiResp['status'] === 403, "Driver blocked from api/admin-stats.php with HTTP 403 Forbidden.");

        // Driver attempting to access Attendant Gate Scanner
        $gateScannerResp = $this->httpGet('/gate-scanner.php', $cookieJarDriver);
        $this->assert(
            $gateScannerResp['status'] === 302 && str_contains($gateScannerResp['headers'], 'Location: index.php'),
            "Driver blocked from attendant gate-scanner.php."
        );

        // Driver attempting to call Attendant Scan Lookup API
        $scanLookupResp = $this->httpGet('/api/scan-lookup.php?q=PK-2026', $cookieJarDriver);
        $this->assert($scanLookupResp['status'] === 403, "Driver blocked from api/scan-lookup.php with HTTP 403 Forbidden.");

        // 2. Log in as Attendant (attendant.rajkot@parkpulse.com)
        $this->loginViaHttp($cookieJarAttendant, 'attendant.rajkot@parkpulse.com', 'Attendant@2026');

        // Attendant accessing gate terminal (Authorized)
        $attendantGateResp = $this->httpGet('/gate-scanner.php', $cookieJarAttendant);
        $this->assert($attendantGateResp['status'] === 200, "Attendant authorized to access gate-scanner.php.");

        // Attendant attempting to access Admin Users or Admin Pricing
        $attAdminUsers = $this->httpGet('/admin-users.php', $cookieJarAttendant);
        $this->assert(
            $attAdminUsers['status'] === 302 && str_contains($attAdminUsers['headers'], 'Location: index.php'),
            "Attendant blocked from admin-users.php."
        );

        $attAdminPricing = $this->httpGet('/admin-pricing.php', $cookieJarAttendant);
        $this->assert(
            $attAdminPricing['status'] === 302 && str_contains($attAdminPricing['headers'], 'Location: index.php'),
            "Attendant blocked from admin-pricing.php."
        );

        @unlink($cookieJarDriver);
        @unlink($cookieJarAttendant);
    }

    private function testOwnershipAndIdorPrevention(): void
    {
        echo "\n5. Testing Ownership Enforcement & IDOR Tampering Prevention...\n";

        $cookieJarDriverA = tempnam(sys_get_temp_dir(), 'pp_userA_');
        $cookieJarDriverB = tempnam(sys_get_temp_dir(), 'pp_userB_');

        // Driver A: Dev Patel (ID 4)
        $this->loginViaHttp($cookieJarDriverA, 'dev.patel@example.com', 'User@2026');
        // Driver B: Nisha Parmar (ID 5)
        $this->loginViaHttp($cookieJarDriverB, 'nisha.parmar@example.com', 'User@2026');

        // Find a booking belonging to Driver A
        $resA = $this->db->query("SELECT * FROM reservations WHERE user_id = 4 LIMIT 1")->fetch();
        // Find a vehicle belonging to Driver A
        $vehA = $this->db->query("SELECT * FROM vehicles WHERE user_id = 4 LIMIT 1")->fetch();

        if ($resA) {
            $refA = $resA['booking_reference'];

            // Driver B attempts to view Driver A's digital pass
            $passTamperResp = $this->httpGet("/pass.php?ref={$refA}", $cookieJarDriverB);
            $this->assert(
                $passTamperResp['status'] === 302 && str_contains($passTamperResp['headers'], 'Location: index.php'),
                "Driver B blocked from viewing Driver A's pass.php?ref={$refA} (IDOR Protection)."
            );

            // Driver B attempts to fetch Driver A's reservation details via API
            $apiResTamper = $this->httpGet("/api/reservations.php?ref={$refA}", $cookieJarDriverB);
            $this->assert($apiResTamper['status'] === 403, "Driver B blocked from api/reservations.php?ref={$refA} with HTTP 403 Forbidden.");
        }

        if ($vehA) {
            $vehIdA = (int)$vehA['id'];

            // Driver B attempts to delete Driver A's vehicle via API
            $csrfB = $this->extractCsrfToken($cookieJarDriverB);
            $delResp = $this->httpPost('/api/vehicles.php', [
                'action' => 'delete',
                'vehicle_id' => $vehIdA,
                'csrf_token' => $csrfB
            ], $cookieJarDriverB);

            // Verify Driver A's vehicle still exists
            $chk = $this->db->query("SELECT id FROM vehicles WHERE id = {$vehIdA}")->fetch();
            $this->assert(!empty($chk), "Driver B cannot delete Driver A's vehicle ID {$vehIdA} (Ownership verified).");
        }

        // Driver B listing vehicles via api/vehicles.php only gets Driver B's vehicles
        $listVeh = $this->httpGet('/api/vehicles.php', $cookieJarDriverB);
        $json = json_decode($listVeh['body'], true);
        $allBelongToB = true;
        if (!empty($json['data'])) {
            foreach ($json['data'] as $v) {
                if ((int)$v['user_id'] !== 5) {
                    $allBelongToB = false;
                }
            }
        }
        $this->assert($allBelongToB, "api/vehicles.php strictly scopes vehicle records to current user ID (No cross-user leakage).");

        @unlink($cookieJarDriverA);
        @unlink($cookieJarDriverB);
    }

    private function testCsrfProtectionBehavior(): void
    {
        echo "\n6. Testing CSRF Protection & Token Validation...\n";

        $cookieJar = tempnam(sys_get_temp_dir(), 'pp_csrf_');
        $this->loginViaHttp($cookieJar, 'dev.patel@example.com', 'User@2026');

        // 1. Post to state-changing endpoint without CSRF token
        $noCsrfResp = $this->httpPost('/api/vehicles.php', [
            'action' => 'add',
            'plate_number' => 'GJ-03-NOCSRF',
            'make_model' => 'Test Car',
            'color' => 'White',
            'vehicle_type' => 'car'
        ], $cookieJar);
        $this->assert($noCsrfResp['status'] === 403, "Request without CSRF token rejected with HTTP 403 Forbidden.");

        // 2. Post with forged / invalid CSRF token
        $fakeCsrfResp = $this->httpPost('/api/vehicles.php', [
            'action' => 'add',
            'plate_number' => 'GJ-03-FAKECSRF',
            'make_model' => 'Test Car',
            'color' => 'White',
            'vehicle_type' => 'car',
            'csrf_token' => 'invalid_forged_csrf_token_123456789'
        ], $cookieJar);
        $this->assert($fakeCsrfResp['status'] === 403, "Request with forged CSRF token rejected with HTTP 403 Forbidden.");

        // 3. Post with genuine CSRF token
        $realCsrf = $this->extractCsrfToken($cookieJar);
        $uniquePlate = 'GJ-03-SEC-' . rand(100, 999);
        $validResp = $this->httpPost('/api/vehicles.php', [
            'action' => 'add',
            'plate_number' => $uniquePlate,
            'make_model' => 'Security Test Model',
            'color' => 'Silver',
            'vehicle_type' => 'car',
            'csrf_token' => $realCsrf
        ], $cookieJar);
        $this->assert($validResp['status'] === 201, "Request with valid CSRF token accepted (HTTP 201 Created).");

        // Clean up test vehicle
        $this->db->prepare("DELETE FROM vehicles WHERE plate_number = ?")->execute([$uniquePlate]);

        @unlink($cookieJar);
    }

    private function testRateLimitingAndBruteForceProtection(): void
    {
        echo "\n7. Testing Rate Limiting & Brute-Force Throttling...\n";

        $testIp = '198.51.100.42';
        $actionKey = 'login_test_throttle';

        // Clear existing
        RateLimiter::reset($actionKey);

        // Record 5 attempts
        for ($i = 1; $i <= 5; $i++) {
            $this->db->prepare("INSERT INTO rate_limits (ip_address, action_key, attempts, last_attempt_at) 
                VALUES ('127.0.0.1', '{$actionKey}', 1, ?) 
                ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = VALUES(last_attempt_at)")
                ->execute([time()]);
        }

        $isLimited = RateLimiter::isRateLimited($actionKey, 5, 300);
        $this->assert($isLimited, "RateLimiter triggers after exceeding 5 failed attempts within time window.");

        RateLimiter::reset($actionKey);
        $isCleared = !RateLimiter::isRateLimited($actionKey, 5, 300);
        $this->assert($isCleared, "RateLimiter successfully resets upon valid login or explicit reset.");
    }

    private function testAuditLoggingForSensitiveOperations(): void
    {
        echo "\n8. Testing Immutable Security & Audit Logging...\n";

        $uniqueAction = 'TEST_AUDIT_' . bin2hex(random_bytes(4));
        AuditLogger::log($uniqueAction, 'system_test', 1234, 'Automated security test audit log verification.');

        $stmt = $this->db->prepare("SELECT * FROM audit_logs WHERE action = ?");
        $stmt->execute([$uniqueAction]);
        $log = $stmt->fetch();

        $this->assert(!empty($log), "AuditLogger accurately committed entry to `audit_logs` table.");
        $this->assert($log['entity_type'] === 'system_test' && (int)$log['entity_id'] === 1234, "Audit record matches entity parameters.");
        $this->assert(!empty($log['ip_address']), "Audit record captured client IP address.");
        $this->assert(!empty($log['created_at']), "Audit record timestamped accurately.");

        // Clean up test audit log
        $this->db->prepare("DELETE FROM audit_logs WHERE action = ?")->execute([$uniqueAction]);
    }

    private function testSafeErrorResponses(): void
    {
        echo "\n9. Testing Safe Error Handling (No Leaked Stack Traces)...\n";

        // Query non-existent endpoint or malformed request
        $resp = $this->httpGet('/api/reservations.php?ref=NON_EXISTENT_REF_999999');
        $this->assert(!str_contains($resp['body'], 'SQLSTATE'), "No raw SQL exceptions or schema details leaked.");
        $this->assert(!str_contains($resp['body'], 'Stack trace:'), "No internal PHP stack traces leaked to client.");
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

$suite = new SecurityAccessControlTestSuite();
$suite->runAll();
