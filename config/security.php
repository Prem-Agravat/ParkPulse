<?php
/**
 * Security, Auth, CSRF, Rate Limiting & Audit System
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Safe Timezone Configuration (India Standard Time - IST / Asia/Kolkata)
date_default_timezone_set('Asia/Kolkata');

// Safe Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    // Session cookie parameters
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($isSecure) {
        ini_set('session.cookie_secure', '1');
    }
    
    session_start();
}

// Global HTTP Security Headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Session Inactivity Timeout (7200s / 2 hours)
if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > 7200)) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    session_start();
}
if (!empty($_SESSION['user'])) {
    $_SESSION['last_activity'] = time();
}

/**
 * Helper to fetch environment variable with fallback
 */
function env(string $key, $default = null)
{
    $val = getenv($key);
    if ($val === false) {
        return $_ENV[$key] ?? $default;
    }
    return $val;
}

/**
 * Core Security Helpers & Indian Localization Utilities
 */
class Security
{
    public static function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function getCsrfToken(): string
    {
        return self::generateCsrfToken();
    }

    public static function validateCsrfToken(?string $token): bool
    {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function csrfInput(): string
    {
        $token = self::generateCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . self::escape($token) . '">';
    }

    public static function escape(?string $data): string
    {
        return htmlspecialchars((string)($data ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function generateQrToken(): string
    {
        return 'PKPASS_' . bin2hex(random_bytes(24)) . '_' . time();
    }

    public static function hashQrToken(string $token): string
    {
        $salt = env('QR_SECRET_SALT', 'default_salt_2026_smart_parking');
        return hash_hmac('sha256', $token, $salt);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function sanitizeString(string $input, int $maxLength = 255): string
    {
        $cleaned = trim(strip_tags($input));
        return mb_substr($cleaned, 0, $maxLength, 'UTF-8');
    }

    public static function sanitizeEmail(string $email): string
    {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL) ?: '';
    }

    public static function validatePlate(string $plate): bool
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate));
        return strlen($clean) >= 4 && strlen($clean) <= 15;
    }

    /**
     * Normalize Indian Mobile Number
     * Removes +91, 0, spaces, hyphens, parentheses
     */
    public static function normalizeIndianMobile(?string $phone): string
    {
        if ($phone === null) return '';
        $digits = preg_replace('/\D/', '', $phone);
        // If 12 digits and starts with 91, strip 91
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    /**
     * Validate Indian 10-digit mobile number with leading digit 6-9
     */
    public static function validateIndianMobile(?string $phone): bool
    {
        $normalized = self::normalizeIndianMobile($phone);
        return (bool)preg_match('/^[6-9]\d{9}$/', $normalized);
    }

    /**
     * Format Indian Mobile Number consistently as +91 XXXXX XXXXX
     */
    public static function formatIndianMobile(?string $phone): string
    {
        $normalized = self::normalizeIndianMobile($phone);
        if (strlen($normalized) === 10) {
            return '+91 ' . substr($normalized, 0, 5) . ' ' . substr($normalized, 5, 5);
        }
        return $phone ?? '';
    }

    /**
     * Validate Indian 6-digit PIN code (does not start with 0)
     */
    public static function validatePinCode(?string $pin): bool
    {
        if (empty($pin)) return false;
        $clean = trim($pin);
        return (bool)preg_match('/^[1-9][0-9]{5}$/', $clean);
    }

    /**
     * Validate Geographic Latitude & Longitude
     */
    public static function validateCoordinates(float|string $lat, float|string $lng): bool
    {
        $lat = (float)$lat;
        $lng = (float)$lng;
        return ($lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0 && ($lat != 0.0 || $lng != 0.0));
    }

    /**
     * Format INR Currency (₹) with precise paise / 2 decimals
     */
    public static function formatInr(float|int $amount): string
    {
        return '₹' . number_format((float)$amount, 2, '.', ',');
    }

    /**
     * Construct safe trusted OpenStreetMap link with coordinates
     */
    public static function buildOsmUrl(float $lat, float $lng, int $zoom = 17): string
    {
        $lat = round($lat, 6);
        $lng = round($lng, 6);
        return "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lng}#map={$zoom}/{$lat}/{$lng}";
    }

    /**
     * Construct safe trusted Google Maps link with coordinates
     */
    public static function buildGoogleMapsUrl(float $lat, float $lng): string
    {
        $lat = round($lat, 6);
        $lng = round($lng, 6);
        return "https://www.google.com/maps/search/?api=1&query={$lat},{$lng}";
    }

    /**
     * Return all 28 Indian States and 8 Union Territories
     */
    public static function getIndianStatesAndUTs(): array
    {
        $states = [
            'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
            'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand',
            'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
            'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
            'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
            'Uttar Pradesh', 'Uttarakhand', 'West Bengal'
        ];
        $uts = [
            'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu',
            'Delhi (NCT)', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry'
        ];

        return [
            'states' => $states,
            'union_territories' => $uts,
            'States' => $states,
            'Union Territories' => $uts
        ];
    }

    /**
     * Return Supported Vehicle Categories & Metadata
     */
    public static function getVehicleCategories(): array
    {
        return [
            'car' => [
                'label' => 'Car / Sedan / Hatchback / SUV',
                'short_label' => 'Car',
                'icon' => 'bi-car-front-fill',
                'ev_eligible' => true,
                'min_slot_type' => 'car',
                'default_dimensions' => '5.0m × 2.4m'
            ],
            'bike' => [
                'label' => 'Two-Wheeler / Bike / Scooter',
                'short_label' => 'Bike',
                'icon' => 'bi-bicycle',
                'ev_eligible' => true,
                'min_slot_type' => 'bike',
                'default_dimensions' => '2.2m × 1.0m'
            ],
            'bicycle' => [
                'label' => 'Bicycle / Cycle',
                'short_label' => 'Bicycle',
                'icon' => 'bi-bicycle',
                'ev_eligible' => false,
                'min_slot_type' => 'bicycle',
                'default_dimensions' => '1.8m × 0.6m'
            ],
            'auto_rickshaw' => [
                'label' => 'Auto-rickshaw / 3-Wheeler',
                'short_label' => 'Auto-rickshaw',
                'icon' => 'bi-truck-flatbed',
                'ev_eligible' => true,
                'min_slot_type' => 'auto_rickshaw',
                'default_dimensions' => '3.0m × 1.5m'
            ],
            'van' => [
                'label' => 'Van / Mini-bus / Multi-Utility',
                'short_label' => 'Van',
                'icon' => 'bi-bus-front-fill',
                'ev_eligible' => true,
                'min_slot_type' => 'van',
                'default_dimensions' => '6.0m × 2.6m'
            ],
            'truck' => [
                'label' => 'Truck / Commercial Goods Carrier',
                'short_label' => 'Truck',
                'icon' => 'bi-truck',
                'ev_eligible' => true,
                'min_slot_type' => 'truck',
                'default_dimensions' => '10.0m × 3.2m'
            ],
            'bus' => [
                'label' => 'Bus / Heavy Passenger Vehicle',
                'short_label' => 'Bus',
                'icon' => 'bi-bus-front',
                'ev_eligible' => true,
                'min_slot_type' => 'bus',
                'default_dimensions' => '12.0m × 3.5m'
            ],
            'other' => [
                'label' => 'Other Custom Vehicle',
                'short_label' => 'Other',
                'icon' => 'bi-gear-wide-connected',
                'ev_eligible' => true,
                'min_slot_type' => 'other',
                'default_dimensions' => '5.0m × 2.5m'
            ]
        ];
    }

    public static function jsonResponse(array $data, int $statusCode = 200): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '127.0.0.1';
    }
}

/**
 * Authentication and RBAC Manager
 */
class Auth
{
    public static function check(): bool
    {
        return !empty($_SESSION['user']) && !empty($_SESSION['user']['id']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    }

    public static function name(): string
    {
        return $_SESSION['user']['name'] ?? 'Guest';
    }

    public static function role(): string
    {
        return $_SESSION['user']['role'] ?? 'guest';
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function isAttendant(): bool
    {
        return in_array(self::role(), ['attendant', 'admin'], true);
    }

    public static function isUser(): bool
    {
        return self::role() === 'user';
    }

    public static function login(array $user): void
    {
        // Prevent session fixation
        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'phone' => $user['phone'] ?? null,
            'status' => $user['status']
        ];
        
        // Regenerate CSRF token on auth change
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();

        AuditLogger::log('USER_LOGIN', 'users', (int)$user['id'], "User logged in with role [{$user['role']}].");
    }

    public static function logout(): void
    {
        if (self::check()) {
            AuditLogger::log('USER_LOGOUT', 'users', self::id(), "User logged out.");
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies") && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    public static function requireLogin(string $redirectUrl = 'login.php'): void
    {
        if (!self::check()) {
            Flash::set('warning', 'Please sign in to access this page.');
            $currentUri = $_SERVER['REQUEST_URI'] ?? '';
            header('Location: ' . $redirectUrl . (!empty($currentUri) ? '?redirect=' . urlencode($currentUri) : ''));
            exit;
        }
    }

    public static function requireRole(array|string $allowedRoles, string $unauthorizedUrl = 'index.php'): void
    {
        self::requireLogin();
        $roles = (array)$allowedRoles;
        if (!in_array(self::role(), $roles, true)) {
            Flash::set('danger', 'Access denied. You do not have permission to view that section.');
            header('Location: ' . $unauthorizedUrl);
            exit;
        }
    }
}

/**
 * Rate Limiter for Login & High Frequency Operations
 */
class RateLimiter
{
    public static function isRateLimited(string $actionKey, int $maxAttempts = 5, int $windowSeconds = 300): bool
    {
        try {
            $db = Database::getInstance()->getConnection();
            $ip = Security::getClientIp();
            $now = time();

            // Clear expired records older than window
            $cleanup = $db->prepare("DELETE FROM rate_limits WHERE last_attempt_at < ?");
            $cleanup->execute([$now - $windowSeconds]);

            // Check current attempts
            $stmt = $db->prepare("SELECT attempts, last_attempt_at FROM rate_limits WHERE ip_address = ? AND action_key = ?");
            $stmt->execute([$ip, $actionKey]);
            $row = $stmt->fetch();

            if ($row) {
                if ($row['attempts'] >= $maxAttempts && ($now - (int)$row['last_attempt_at']) < $windowSeconds) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            // Fail open gracefully if rate limit table is momentarily locked
            return false;
        }
    }

    public static function hit(string $actionKey): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            $ip = Security::getClientIp();
            $now = time();

            $stmt = $db->prepare("INSERT INTO rate_limits (ip_address, action_key, attempts, last_attempt_at) 
                VALUES (?, ?, 1, ?) 
                ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt_at = VALUES(last_attempt_at)");
            $stmt->execute([$ip, $actionKey, $now]);
        } catch (\Throwable $e) {
            // Silence DB logging error
        }
    }

    public static function reset(string $actionKey): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            $ip = Security::getClientIp();
            $stmt = $db->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND action_key = ?");
            $stmt->execute([$ip, $actionKey]);
        } catch (\Throwable $e) {
            // Silence
        }
    }
}

/**
 * Audit Logger
 */
class AuditLogger
{
    public static function log(string $action, string $entityType, ?int $entityId, ?string $details = null): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            $userId = Auth::id();
            $ip = Security::getClientIp();
            $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250);

            $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$userId, $action, $entityType, $entityId, $details, $ip, $ua]);
        } catch (\Throwable $e) {
            error_log("Audit Log Error: " . $e->getMessage());
        }
    }
}

/**
 * Flash Message Handler
 */
class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['flash_messages'][] = [
            'type' => $type, // 'success', 'danger', 'warning', 'info'
            'message' => $message
        ];
    }

    public static function display(): string
    {
        if (empty($_SESSION['flash_messages'])) {
            return '';
        }

        $html = '<div class="flash-container my-3">';
        foreach ($_SESSION['flash_messages'] as $flash) {
            $type = Security::escape($flash['type']);
            $msg = Security::escape($flash['message']);
            $icon = match($type) {
                'success' => 'bi-check-circle-fill',
                'danger' => 'bi-exclamation-octagon-fill',
                'warning' => 'bi-exclamation-triangle-fill',
                default => 'bi-info-circle-fill'
            };
            $html .= "<div class=\"alert alert-{$type} alert-dismissible fade show d-flex align-items-center shadow-sm\" role=\"alert\">
                <i class=\"bi {$icon} me-2 fs-5\"></i>
                <div class=\"flex-grow-1\">{$msg}</div>
                <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\" aria-label=\"Close\"></button>
            </div>";
        }
        $html .= '</div>';

        unset($_SESSION['flash_messages']);
        return $html;
    }
}
