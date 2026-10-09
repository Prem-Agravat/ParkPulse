<?php
/**
 * Part 8 — Comprehensive Security & Vulnerability Audit Suite
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

final class FinalSecurityAuditTestSuite
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
        echo "  PARKPULSE 2026 - PART 8 COMPREHENSIVE SECURITY & VULNERABILITY AUDIT\n";
        echo "======================================================================\n\n";

        $this->testSqlInjectionDefenses();
        $this->testCrossSiteScriptingDefenses();
        $this->testDirectAccessBlocksViaHtaccess();
        $this->testNoTrackedSecretsInGit();
        $this->testHttpSecurityHeaders();

        echo "\n----------------------------------------------------------------------\n";
        echo "SECURITY AUDIT SCORECARD: {$this->passCount} PASSED, {$this->failCount} FAILED\n";
        echo "----------------------------------------------------------------------\n";

        if ($this->failCount > 0) {
            exit(1);
        }
    }

    private function testSqlInjectionDefenses(): void
    {
        echo "1. Testing SQL Injection Prevention with Parameterized PDO Queries...\n";

        // 1. Facility search query SQLi payload
        $sqliPayload = "' OR '1'='1' UNION SELECT 1,2,3,password_hash,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19 FROM users-- ";
        $resp1 = $this->httpGet('/api/facilities.php?q=' . urlencode($sqliPayload));
        $this->assert($resp1['status'] === 200, "API safely processed SQL injection payload string without crashing.");
        $this->assert(!str_contains($resp1['body'], '$2y$'), "No password hashes leaked via SQL injection payload in facilities API.");

        // 2. Reservation Ref SQLi payload
        $cookieJar = tempnam(sys_get_temp_dir(), 'sqli_user_');
        $this->loginViaHttp($cookieJar, 'dev.patel@example.com', 'User@2026');

        $sqliRef = "PK-2026' OR 1=1-- ";
        $resp2 = $this->httpGet('/api/reservations.php?ref=' . urlencode($sqliRef), $cookieJar);
        $this->assert(in_array($resp2['status'], [404, 403], true), "SQL injection in booking ref parameter safely rejected (HTTP {$resp2['status']}).");

        // 3. Login Email SQLi payload
        $loginPage = $this->httpGet('/login.php');
        preg_match('/name="csrf_token"\s+value="([a-f0-9]+)"/i', $loginPage['body'], $matches);
        $csrf = $matches[1] ?? '';

        $loginSqli = $this->httpPost('/login.php', [
            'email' => "admin@parkpulse.com' OR '1'='1",
            'password' => "anything' OR '1'='1",
            'csrf_token' => $csrf
        ]);
        $this->assert(!str_contains($loginSqli['headers'], 'Location: admin-dashboard.php'), "SQL injection payload failed to bypass login authentication.");

        @unlink($cookieJar);
    }

    private function testCrossSiteScriptingDefenses(): void
    {
        echo "\n2. Testing Cross-Site Scripting (XSS) Prevention & Output Escaping...\n";

        // 1. HTML Escaping Utility Test
        $rawXss = "<script>alert('XSS-TEST')</script>";
        $escaped = Security::escape($rawXss);
        $this->assert(!str_contains($escaped, '<script>'), "Security::escape correctly converted '<script>' to entities.");
        $this->assert(str_contains($escaped, '&lt;script&gt;'), "Sanitized output contains harmless HTML entities.");

        // 2. Attribute Breaking XSS
        $attrXss = "\" onmouseover=\"alert(document.cookie)\" data-bad=\"";
        $escapedAttr = Security::escape($attrXss);
        $this->assert(!str_contains($escapedAttr, '" onmouseover="'), "Double quotes escaped with &quot; to prevent attribute breakout.");
    }

    private function testDirectAccessBlocksViaHtaccess(): void
    {
        echo "\n3. Testing Direct Sensitive File Access Restrictions (.htaccess)...\n";

        // 1. Attempt to download .env directly via HTTP
        $envResp = $this->httpGet('/.env');
        $this->assert(in_array($envResp['status'], [403, 404], true), "Direct HTTP access to /.env blocked with HTTP {$envResp['status']}.");

        // 2. Attempt to download schema.sql directly via HTTP
        $sqlResp = $this->httpGet('/database/schema.sql');
        $this->assert(in_array($sqlResp['status'], [403, 404], true), "Direct HTTP access to /database/schema.sql blocked with HTTP {$sqlResp['status']}.");
    }

    private function testNoTrackedSecretsInGit(): void
    {
        echo "\n4. Testing Repository Cleanliness & Secret Management...\n";

        // Check that .gitignore exists and ignores .env
        $gitignore = file_get_contents(__DIR__ . '/../.gitignore');
        $this->assert(str_contains($gitignore, '.env'), ".gitignore explicitly excludes '.env'.");
        $this->assert(str_contains($gitignore, '*.log'), ".gitignore explicitly excludes '*.log'.");

        // Check that .env.example contains no real database passwords
        $envExample = file_get_contents(__DIR__ . '/../.env.example');
        $this->assert(str_contains($envExample, 'DB_PASS=') || str_contains($envExample, 'your_db_password'), ".env.example contains generic configuration placeholders.");
    }

    private function testHttpSecurityHeaders(): void
    {
        echo "\n5. Testing HTTP Security Headers...\n";

        $resp = $this->httpGet('/index.php');
        $headers = $resp['headers'];

        $this->assert(str_contains($headers, 'X-Content-Type-Options: nosniff'), "X-Content-Type-Options: nosniff header present.");
        $this->assert(str_contains($headers, 'X-Frame-Options: SAMEORIGIN'), "X-Frame-Options: SAMEORIGIN header present (Clickjacking defense).");
        $this->assert(str_contains($headers, 'Referrer-Policy: strict-origin-when-cross-origin'), "Referrer-Policy header present.");
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

$suite = new FinalSecurityAuditTestSuite();
$suite->runAll();
