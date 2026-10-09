<?php
/**
 * User Registration
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $name = Security::sanitizeString($_POST['name'] ?? '', 100);
        $email = Security::sanitizeEmail($_POST['email'] ?? '');
        $phoneRaw = Security::sanitizeString($_POST['phone'] ?? '', 20);
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (empty($name) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!empty($phoneRaw) && !Security::validateIndianMobile($phoneRaw)) {
            $error = 'Please enter a valid 10-digit Indian mobile number (e.g. +91 98765 43210).';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                $normalizedPhone = !empty($phoneRaw) ? Security::normalizeIndianMobile($phoneRaw) : null;
                
                // Check if email already exists
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'An account with this email address already exists.';
                } else {
                    $passwordHash = Security::hashPassword($password);
                    $insertStmt = $db->prepare("INSERT INTO users (name, email, password_hash, phone, role, status) VALUES (?, ?, ?, ?, 'user', 'active')");
                    $insertStmt->execute([$name, $email, $passwordHash, $normalizedPhone]);
                    
                    $userId = (int)$db->lastInsertId();
                    
                    // Fetch newly created user and log in automatically
                    $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                    $userStmt->execute([$userId]);
                    $newUser = $userStmt->fetch();

                    Auth::login($newUser);
                    Flash::set('success', 'Registration successful! Welcome to ParkPulse.');
                    header('Location: vehicles.php');
                    exit;
                }
            } catch (\Throwable $e) {
                error_log("Registration Error: " . $e->getMessage());
                $error = 'A database error occurred during registration. Please try again.';
            }
        }
    }
}

$pageTitle = 'Create Account';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card card-custom border-0 shadow-lg">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle mb-3" style="width: 64px; height: 64px;">
                            <i class="bi bi-person-plus-fill fs-2"></i>
                        </div>
                        <h2 class="fw-bold h4 mb-1">Create Driver Account</h2>
                        <p class="text-secondary small">Reserve parking slots across Gujarat and India with guaranteed spots</p>
                    </div>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                            <i class="bi bi-exclamation-circle-fill me-2 fs-5 flex-shrink-0"></i>
                            <div><?= Security::escape($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="register.php" novalidate>
                        <?= Security::csrfInput() ?>

                        <div class="mb-3">
                            <label for="name" class="form-label fw-semibold small text-secondary">Full Name *</label>
                            <input type="text" class="form-control" id="name" name="name" placeholder="e.g. Dev Patel or Hetal Shah" value="<?= Security::escape($_POST['name'] ?? '') ?>" required autofocus>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label for="email" class="form-label fw-semibold small text-secondary">Email Address *</label>
                                <input type="email" class="form-control" id="email" name="email" placeholder="name@example.com" value="<?= Security::escape($_POST['email'] ?? '') ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label for="phone" class="form-label fw-semibold small text-secondary">Indian Mobile Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" placeholder="+91 98765 43210" value="<?= Security::escape($_POST['phone'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <label for="password" class="form-label fw-semibold small text-secondary">Password *</label>
                                <input type="password" class="form-control" id="password" name="password" placeholder="Min. 8 characters" required minlength="8">
                            </div>
                            <div class="col-sm-6">
                                <label for="confirm_password" class="form-label fw-semibold small text-secondary">Confirm Password *</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required minlength="8">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary-custom w-100 py-2 fs-6 fw-bold shadow-sm">
                            <i class="bi bi-check2-circle me-2"></i> Complete Registration
                        </button>
                    </form>

                    <div class="text-center mt-4 pt-2 border-top">
                        <p class="text-secondary small mb-0">Already registered? <a href="login.php" class="text-primary fw-bold text-decoration-none">Sign In</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
