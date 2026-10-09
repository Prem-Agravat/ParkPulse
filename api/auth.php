<?php
/**
 * Authentication JSON API Endpoint
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance()->getConnection();

if ($method === 'GET') {
    // Current logged-in user profile probe
    if (!Auth::check()) {
        Security::jsonResponse(['success' => false, 'authenticated' => false], 401);
    }

    $userId = Auth::id();
    $stmt = $db->prepare("SELECT id, name, email, phone, role, status, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    Security::jsonResponse([
        'success' => true,
        'authenticated' => true,
        'user' => $user
    ]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = Security::sanitizeString($input['action'] ?? 'login');
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if ($action === 'login') {
        $email = Security::sanitizeEmail($input['email'] ?? '');
        $password = (string)($input['password'] ?? '');

        $rateKey = 'login_' . Security::getClientIp();
        if (RateLimiter::isRateLimited($rateKey, 5, 300)) {
            Security::jsonResponse(['success' => false, 'message' => 'Too many login attempts. Please wait 5 minutes.'], 429);
        }

        if (empty($email) || empty($password)) {
            Security::jsonResponse(['success' => false, 'message' => 'Email and password are required.'], 400);
        }

        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && Security::verifyPassword($password, $user['password_hash'])) {
            if ($user['status'] !== 'active') {
                Security::jsonResponse(['success' => false, 'message' => 'Account is deactivated or suspended.'], 403);
            }

            RateLimiter::reset($rateKey);
            Auth::login($user);

            Security::jsonResponse([
                'success' => true,
                'message' => 'Login successful.',
                'user' => [
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ]);
        } else {
            RateLimiter::hit($rateKey);
            Security::jsonResponse(['success' => false, 'message' => 'Invalid email or password.'], 401);
        }
    }

    if ($action === 'register') {
        $name = Security::sanitizeString($input['name'] ?? '', 100);
        $email = Security::sanitizeEmail($input['email'] ?? '');
        $phone = Security::sanitizeString($input['phone'] ?? '', 20);
        $password = (string)($input['password'] ?? '');

        if (empty($name) || empty($email) || empty($password)) {
            Security::jsonResponse(['success' => false, 'message' => 'All fields are required.'], 400);
        }

        if (strlen($password) < 8) {
            Security::jsonResponse(['success' => false, 'message' => 'Password must be at least 8 characters.'], 400);
        }

        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            Security::jsonResponse(['success' => false, 'message' => 'An account with this email already exists.'], 400);
        }

        $hash = Security::hashPassword($password);
        $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, phone, role, status) VALUES (?, ?, ?, ?, 'user', 'active')");
        $stmt->execute([$name, $email, $hash, $phone ?: null]);
        $newId = (int)$db->lastInsertId();

        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$newId]);
        $newUser = $stmt->fetch();

        Auth::login($newUser);

        Security::jsonResponse([
            'success' => true,
            'message' => 'Registration successful.',
            'user' => [
                'id' => $newId,
                'name' => $name,
                'email' => $email,
                'role' => 'user'
            ]
        ], 201);
    }

    if ($action === 'logout') {
        Auth::logout();
        Security::jsonResponse(['success' => true, 'message' => 'Logged out safely.']);
    }
}
