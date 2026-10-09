<?php
/**
 * Navigation Bar Component with Role-Based Controls
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

$user = Auth::user();
$role = Auth::role();
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
?>
<nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
    <div class="container-fluid px-lg-4 px-3">
        <!-- Brand Logo -->
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <i class="bi bi-p-square-fill text-primary fs-3 me-2"></i>
            <span>ParkPulse</span>
            <span class="brand-badge">2026</span>
        </a>

        <!-- Mobile Hamburger Toggle -->
        <button class="navbar-toggler border-0" type="button" data-bs-dismiss="false" data-bs-toggle="collapse" data-bs-target="#parkNavbar" aria-controls="parkNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="parkNavbar">
            <!-- Left Side Links -->
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'index.php' || $currentPage === '') ? 'active' : '' ?>" href="index.php">
                        <i class="bi bi-geo-alt-fill me-1"></i> Find Parking
                    </a>
                </li>

                <?php if (Auth::check()): ?>
                    <?php if ($role === 'user'): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= ($currentPage === 'my-bookings.php') ? 'active' : '' ?>" href="my-bookings.php">
                                <i class="bi bi-ticket-perforated me-1"></i> My Bookings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($currentPage === 'vehicles.php') ? 'active' : '' ?>" href="vehicles.php">
                                <i class="bi bi-car-front me-1"></i> My Vehicles
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($role === 'attendant' || $role === 'admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link text-warning-emphasis <?= ($currentPage === 'gate-scanner.php') ? 'active' : '' ?>" href="gate-scanner.php">
                                <i class="bi bi-qr-code-scan me-1"></i> Gate Scanner
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($currentPage === 'facility-occupancy.php') ? 'active' : '' ?>" href="facility-occupancy.php">
                                <i class="bi bi-grid-3x3-gap-fill me-1"></i> Live Occupancy
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($role === 'admin'): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle <?= in_array($currentPage, ['admin-dashboard.php', 'admin-facilities.php', 'admin-slots.php', 'admin-pricing.php', 'admin-payments.php', 'admin-users.php', 'admin-reservations.php', 'admin-audit.php']) ? 'active' : '' ?>" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-shield-lock-fill me-1"></i> Admin Portal
                            </a>
                            <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-secondary" aria-labelledby="adminDropdown">
                                <li><a class="dropdown-item" href="admin-dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Command Center & Analytics</a></li>
                                <li><hr class="dropdown-divider border-secondary"></li>
                                <li><a class="dropdown-item" href="admin-facilities.php"><i class="bi bi-buildings me-2"></i> Facilities Management</a></li>
                                <li><a class="dropdown-item" href="admin-slots.php"><i class="bi bi-grid me-2"></i> Slots & Zones</a></li>
                                <li><a class="dropdown-item" href="admin-pricing.php"><i class="bi bi-cash-coin me-2"></i> Tariffs & Pricing</a></li>
                                <li><hr class="dropdown-divider border-secondary"></li>
                                <li><a class="dropdown-item" href="admin-payments.php"><i class="bi bi-credit-card-2-front-fill text-success me-2"></i> Payments & Gateway Transactions</a></li>
                                <li><a class="dropdown-item" href="admin-reservations.php"><i class="bi bi-journal-bookmark me-2"></i> All Bookings Master List</a></li>
                                <li><a class="dropdown-item" href="admin-users.php"><i class="bi bi-people me-2"></i> Staff & Users</a></li>
                                <li><a class="dropdown-item" href="admin-audit.php"><i class="bi bi-clock-history me-2"></i> Audit Logs</a></li>
                            </ul>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>

            <!-- Right Side Auth & Profile -->
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <?php if (Auth::check()): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="userMenuDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="badge rounded-pill <?= $role === 'admin' ? 'bg-danger' : ($role === 'attendant' ? 'bg-warning text-dark' : 'bg-primary') ?>">
                                <?= strtoupper(Security::escape($role)) ?>
                            </span>
                            <span class="text-white fw-semibold"><?= Security::escape(Auth::name()) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark shadow-lg border-secondary" aria-labelledby="userMenuDropdown">
                            <li class="px-3 py-2 text-secondary small">
                                Signed in as<br><strong class="text-light"><?= Security::escape($user['email'] ?? '') ?></strong>
                            </li>
                            <li><hr class="dropdown-divider border-secondary"></li>
                            <li>
                                <a class="dropdown-item" href="profile.php">
                                    <i class="bi bi-person-gear me-2"></i> Account Profile
                                </a>
                            </li>
                            <li><hr class="dropdown-divider border-secondary"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="logout.php">
                                    <i class="bi bi-box-arrow-right me-2"></i> Sign Out
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item me-2">
                        <a class="btn btn-outline-light btn-sm px-3" href="login.php">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-primary btn-sm px-3" href="register.php">
                            <i class="bi bi-person-plus me-1"></i> Register
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
