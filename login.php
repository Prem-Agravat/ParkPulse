<?php
/**
 * User Authentication - Sign In
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';

// If already logged in, redirect to respective dashboard
if (Auth::check()) {
    $role = Auth::role();
    if ($role === 'admin') {
        header('Location: admin-dashboard.php');
    } elseif ($role === 'attendant') {
        header('Location: gate-scanner.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

$error = null;
$redirect = Security::sanitizeString($_GET['redirect'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $email = Security::sanitizeEmail($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        // Rate limiting check
        $rateKey = 'login_' . Security::getClientIp();
        $maxAttempts = (int)env('RATE_LIMIT_LOGIN_MAX_ATTEMPTS', 5);
        $windowSec = (int)env('RATE_LIMIT_LOGIN_WINDOW_SECONDS', 300);

        if (RateLimiter::isRateLimited($rateKey, $maxAttempts, $windowSec)) {
            $error = "Too many failed login attempts. For security, please wait 5 minutes before trying again.";
        } elseif (empty($email) || empty($password)) {
            $error = 'Please enter both your email address and password.';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && Security::verifyPassword($password, $user['password_hash'])) {
                    if ($user['status'] !== 'active') {
                        $error = 'Your account has been suspended or deactivated. Please contact administration.';
                    } else {
                        // Reset rate limit on success
                        RateLimiter::reset($rateKey);
                        Auth::login($user);
                        Flash::set('success', 'Welcome back, ' . $user['name'] . '!');

                        // Handle redirection
                        if (!empty($redirect) && !str_starts_with($redirect, 'http://') && !str_starts_with($redirect, 'https://')) {
                            header('Location: ' . $redirect);
                        } else {
                            if ($user['role'] === 'admin') {
                                header('Location: admin-dashboard.php');
                            } elseif ($user['role'] === 'attendant') {
                                header('Location: gate-scanner.php');
                            } else {
                                header('Location: index.php');
                            }
                        }
                        exit;
                    }
                } else {
                    RateLimiter::hit($rateKey);
                    $error = 'Invalid email address or password.';
                }
            } catch (\Throwable $e) {
                error_log("Login Error: " . $e->getMessage());
                $error = 'A system error occurred. Please try again shortly.';
            }
        }
    }
}

$pageTitle = 'Sign In';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card card-custom border-0 shadow-lg">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle mb-3" style="width: 64px; height: 64px;">
                            <i class="bi bi-shield-lock-fill fs-2"></i>
                        </div>
                        <h2 class="fw-bold h4 mb-1">Sign In to ParkPulse</h2>
                        <p class="text-secondary small">Access smart parking reservations, passes & operations</p>
                    </div>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                            <i class="bi bi-exclamation-circle-fill me-2 fs-5 flex-shrink-0"></i>
                            <div><?= Security::escape($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php<?= !empty($redirect) ? '?redirect=' . urlencode($redirect) : '' ?>" novalidate>
                        <?= Security::csrfInput() ?>

                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold small text-secondary">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-secondary"></i></span>
                                <input type="email" class="form-control border-start-0" id="email" name="email" placeholder="name@example.com" value="<?= Security::escape($_POST['email'] ?? '') ?>" required autofocus autocomplete="email">
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label for="password" class="form-label fw-semibold small text-secondary mb-0">Password</label>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-key text-secondary"></i></span>
                                <input type="password" class="form-control border-start-0" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary-custom w-100 py-2 fs-6 fw-bold shadow-sm">
                            <i class="bi bi-box-arrow-in-right me-2"></i> Sign In
                        </button>
                    </form>

                    <!-- Fast Demo Account Selector for Reviewers -->
                    <div class="mt-4 pt-3 border-top">
                        <p class="text-center text-secondary small fw-bold text-uppercase mb-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">Quick Demo Credentials</p>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-sm btn-outline-danger text-start d-flex justify-content-between align-items-center demo-fill-btn" data-email="admin@parkpulse.com" data-pass="Admin@2026">
                                <span><i class="bi bi-shield-fill me-1"></i> Admin Portal</span>
                                <code class="small text-danger">admin@parkpulse.com</code>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning text-dark text-start d-flex justify-content-between align-items-center demo-fill-btn" data-email="attendant.rajkot@parkpulse.com" data-pass="Attendant@2026">
                                <span><i class="bi bi-qr-code-scan me-1"></i> Gate Attendant</span>
                                <code class="small text-dark">attendant.rajkot@...</code>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary text-start d-flex justify-content-between align-items-center demo-fill-btn" data-email="dev.patel@example.com" data-pass="User@2026">
                                <span><i class="bi bi-car-front-fill me-1"></i> Driver / User (Dev Patel)</span>
                                <code class="small text-primary">dev.patel@example.com</code>
                            </button>
                        </div>
                    </div>

                    <div class="text-center mt-4 pt-2">
                        <p class="text-secondary small mb-0">Don't have an account? <a href="register.php" class="text-primary fw-bold text-decoration-none">Create an Account</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.demo-fill-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('email').value = btn.dataset.email;
        document.getElementById('password').value = btn.dataset.pass;
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
