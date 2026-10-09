<?php
/**
 * Global Header Component
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

$pageTitle = $pageTitle ?? 'Smart Parking System';
$appName = env('APP_NAME', 'ParkPulse 2026');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Security::escape($pageTitle) ?> — <?= Security::escape($appName) ?></title>
    
    <!-- Meta & SEO -->
    <meta name="csrf-token" content="<?= Security::getCsrfToken() ?>">
    <meta name="description" content="ParkPulse Smart Parking Reservation & Management System for Code2Web 2026. Real-time availability, interactive slot booking, digital QR gate validation, and facility analytics.">
    <meta name="author" content="ParkPulse Engineering">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%232563eb'><path d='M6 3h7a6 6 0 0 1 6 6 6 6 0 0 1-6 6H10v6H6V3zm4 4v4h3a2 2 0 0 0 0-4h-3z'/></svg>">

    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Leaflet 1.9.4 CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Leaflet 1.9.4 JS & ParkPulse Core JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="assets/js/main.js"></script>
</head>
<body>
    <?php require_once __DIR__ . '/navbar.php'; ?>
    <main class="main-content">
        <div class="container-fluid px-lg-4 px-3">
            <?= Flash::display() ?>
        </div>
