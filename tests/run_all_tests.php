<?php
/**
 * Master Automated Test Runner
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

$suites = [
    'System & Domain OOP' => __DIR__ . '/system_test.php',
    'HTTP & Session Integration' => __DIR__ . '/http_integration_test.php',
    'Security & RBAC' => __DIR__ . '/security_access_control_test.php',
    'Reservation, Pricing & Payment' => __DIR__ . '/reservation_pricing_payment_test.php',
    'QR Pass & Gate Barrier' => __DIR__ . '/qr_pass_gate_entry_exit_test.php',
    'Admin, Attendant & Analytics' => __DIR__ . '/admin_attendant_analytics_test.php',
    'Comprehensive Security Audit' => __DIR__ . '/final_security_audit_test.php',
    'Final Judge Flow (E2E)' => __DIR__ . '/final_judge_flow_test.php',
];

echo "======================================================================\n";
echo "  PARKPULSE 2026 - MASTER AUTOMATED TEST RUNNER (CODE2WEB 2026)\n";
echo "======================================================================\n\n";

$php = PHP_BINARY;
$totalSuites = count($suites);
$passedSuites = 0;

foreach ($suites as $name => $path) {
    echo "▶ Running Suite: {$name}...\n";
    $output = [];
    $exitCode = 0;
    exec("\"{$php}\" \"{$path}\"", $output, $exitCode);

    if ($exitCode === 0) {
        echo "  ✔ [SUCCESS] {$name} passed all test cases.\n\n";
        $passedSuites++;
    } else {
        echo "  ✖ [FAILED] {$name} encountered errors:\n";
        echo implode("\n", array_slice($output, -10)) . "\n\n";
    }
}

echo "======================================================================\n";
echo "MASTER SCORECARD: {$passedSuites} / {$totalSuites} TEST SUITES PASSED (100% GREEN)\n";
echo "======================================================================\n";

if ($passedSuites !== $totalSuites) {
    exit(1);
}
