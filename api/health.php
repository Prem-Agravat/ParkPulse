<?php
/**
 * System Health & Database Connectivity Probe Endpoint
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

$startTime = microtime(true);
$dbStatus = 'disconnected';
$dbDetails = [];

try {
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->query("SELECT 1");
    if ($stmt && $stmt->fetchColumn() == 1) {
        $dbStatus = 'connected';
        
        // Fetch DB version and quick count
        $version = $pdo->query("SELECT VERSION()")->fetchColumn();
        $facCount = (int)$pdo->query("SELECT COUNT(*) FROM facilities")->fetchColumn();
        $slotCount = (int)$pdo->query("SELECT COUNT(*) FROM slots")->fetchColumn();
        $dbDetails = [
            'engine' => 'MariaDB / MySQL (InnoDB)',
            'version' => $version,
            'total_facilities' => $facCount,
            'total_slots' => $slotCount
        ];
    }
} catch (\Throwable $e) {
    $dbStatus = 'error: ' . $e->getMessage();
}

$latencyMs = round((microtime(true) - $startTime) * 1000, 2);
$isHealthy = $dbStatus === 'connected';

Security::jsonResponse([
    'status' => $isHealthy ? 'UP' : 'DEGRADED',
    'app_name' => env('APP_NAME', 'ParkPulse 2026'),
    'environment' => env('APP_ENV', 'development'),
    'php_version' => PHP_VERSION,
    'server_time' => date('c'),
    'database' => [
        'status' => $dbStatus,
        'details' => $dbDetails
    ],
    'latency_ms' => $latencyMs
], $isHealthy ? 200 : 503);
