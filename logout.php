<?php
/**
 * User Logout
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';

Auth::logout();
Flash::set('info', 'You have been logged out safely.');
header('Location: login.php');
exit;
