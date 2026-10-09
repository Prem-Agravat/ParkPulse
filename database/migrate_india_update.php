<?php
/**
 * Safe Database Migration & Schema Upgrade Script
 * India / Gujarat Update (Code2Web 2026)
 *
 * Preserves all existing data, safely adds new columns and updates constraints.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

echo ">>> Starting Safe Database Migration for India / Gujarat Update...\n";

$db = Database::getInstance()->getConnection();

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetch();
}

try {
    // 1. Users table phone formatting & index
    echo "• Checking `users` table...\n";
    if (!columnExists($db, 'users', 'phone')) {
        $db->exec("ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) NULL AFTER `email`");
        echo "  + Added `phone` column to users\n";
    }

    // 2. Facilities table: locality, district, country
    echo "• Checking `facilities` table...\n";
    if (!columnExists($db, 'facilities', 'locality')) {
        $db->exec("ALTER TABLE `facilities` ADD COLUMN `locality` VARCHAR(150) NULL AFTER `address`");
        echo "  + Added `locality` to facilities\n";
    }
    if (!columnExists($db, 'facilities', 'district')) {
        $db->exec("ALTER TABLE `facilities` ADD COLUMN `district` VARCHAR(100) NULL AFTER `city`");
        echo "  + Added `district` to facilities\n";
    }
    if (!columnExists($db, 'facilities', 'country')) {
        $db->exec("ALTER TABLE `facilities` ADD COLUMN `country` VARCHAR(50) NOT NULL DEFAULT 'India' AFTER `state`");
        echo "  + Added `country` to facilities\n";
    }

    // 3. Vehicles table: vehicle_type VARCHAR(30), is_ev
    echo "• Checking `vehicles` table...\n";
    $db->exec("ALTER TABLE `vehicles` MODIFY COLUMN `vehicle_type` VARCHAR(30) NOT NULL DEFAULT 'car'");
    if (!columnExists($db, 'vehicles', 'is_ev')) {
        $db->exec("ALTER TABLE `vehicles` ADD COLUMN `is_ev` TINYINT(1) NOT NULL DEFAULT 0 AFTER `color`");
        echo "  + Added `is_ev` to vehicles\n";
    }

    // 4. Slots table: multi-category support, dimensions, EV charger type & rates
    echo "• Checking `slots` table...\n";
    $db->exec("ALTER TABLE `slots` MODIFY COLUMN `slot_type` VARCHAR(30) NOT NULL DEFAULT 'car'");
    if (!columnExists($db, 'slots', 'allowed_vehicle_types')) {
        $db->exec("ALTER TABLE `slots` ADD COLUMN `allowed_vehicle_types` VARCHAR(255) NULL AFTER `slot_type`");
        echo "  + Added `allowed_vehicle_types` to slots\n";
    }
    if (!columnExists($db, 'slots', 'ev_charger_type')) {
        $db->exec("ALTER TABLE `slots` ADD COLUMN `ev_charger_type` VARCHAR(100) NULL DEFAULT 'None' AFTER `is_ev_charging`");
        echo "  + Added `ev_charger_type` to slots\n";
    }
    if (!columnExists($db, 'slots', 'ev_charging_rate')) {
        $db->exec("ALTER TABLE `slots` ADD COLUMN `ev_charging_rate` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `ev_charger_type`");
        echo "  + Added `ev_charging_rate` to slots\n";
    }
    if (!columnExists($db, 'slots', 'slot_dimensions')) {
        $db->exec("ALTER TABLE `slots` ADD COLUMN `slot_dimensions` VARCHAR(50) NULL DEFAULT '5.0m × 2.4m' AFTER `ev_charging_rate`");
        echo "  + Added `slot_dimensions` to slots\n";
    }

    // 5. Pricing Rules table: vehicle_type, ev_surcharge, min_booking_hours
    echo "• Checking `pricing_rules` table...\n";
    $db->exec("ALTER TABLE `pricing_rules` MODIFY COLUMN `vehicle_type` VARCHAR(30) NOT NULL DEFAULT 'all'");
    if (!columnExists($db, 'pricing_rules', 'ev_surcharge_per_hour')) {
        $db->exec("ALTER TABLE `pricing_rules` ADD COLUMN `ev_surcharge_per_hour` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `weekend_hourly_rate`");
        echo "  + Added `ev_surcharge_per_hour` to pricing_rules\n";
    }
    if (!columnExists($db, 'pricing_rules', 'min_booking_hours')) {
        $db->exec("ALTER TABLE `pricing_rules` ADD COLUMN `min_booking_hours` DECIMAL(4,2) NOT NULL DEFAULT 1.00 AFTER `ev_surcharge_per_hour`");
        echo "  + Added `min_booking_hours` to pricing_rules\n";
    }

    // 6. Reservations table: vehicle_type, is_ev, ev_charging_requested
    echo "• Checking `reservations` table...\n";
    if (!columnExists($db, 'reservations', 'vehicle_type')) {
        $db->exec("ALTER TABLE `reservations` ADD COLUMN `vehicle_type` VARCHAR(30) NULL AFTER `vehicle_plate`");
        echo "  + Added `vehicle_type` to reservations\n";
    }
    if (!columnExists($db, 'reservations', 'is_ev')) {
        $db->exec("ALTER TABLE `reservations` ADD COLUMN `is_ev` TINYINT(1) NOT NULL DEFAULT 0 AFTER `vehicle_type`");
        echo "  + Added `is_ev` to reservations\n";
    }
    if (!columnExists($db, 'reservations', 'ev_charging_requested')) {
        $db->exec("ALTER TABLE `reservations` ADD COLUMN `ev_charging_requested` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_ev`");
        echo "  + Added `ev_charging_requested` to reservations\n";
    }

    // 7. Payments table: gateway_order_id, gateway_payment_id, gateway_signature, currency
    echo "• Checking `payments` table...\n";
    if (!columnExists($db, 'payments', 'gateway_order_id')) {
        $db->exec("ALTER TABLE `payments` ADD COLUMN `gateway_order_id` VARCHAR(100) NULL AFTER `transaction_ref`");
        echo "  + Added `gateway_order_id` to payments\n";
    }
    if (!columnExists($db, 'payments', 'gateway_payment_id')) {
        $db->exec("ALTER TABLE `payments` ADD COLUMN `gateway_payment_id` VARCHAR(100) NULL AFTER `gateway_order_id`");
        echo "  + Added `gateway_payment_id` to payments\n";
    }
    if (!columnExists($db, 'payments', 'gateway_signature')) {
        $db->exec("ALTER TABLE `payments` ADD COLUMN `gateway_signature` VARCHAR(255) NULL AFTER `gateway_payment_id`");
        echo "  + Added `gateway_signature` to payments\n";
    }
    if (!columnExists($db, 'payments', 'currency')) {
        $db->exec("ALTER TABLE `payments` ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'INR' AFTER `amount`");
        echo "  + Added `currency` to payments\n";
    }

    echo "✔ Safe schema migration completed successfully without dropping any data!\n";
} catch (\Throwable $e) {
    echo "✖ Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
