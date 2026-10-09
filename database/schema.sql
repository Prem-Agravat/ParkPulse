-- Smart Parking Reservation & Management System (Code2Web 2026)
-- Database Schema: MariaDB / MySQL with InnoDB

CREATE DATABASE IF NOT EXISTS `smart_parking_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `smart_parking_db`;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `role` ENUM('admin', 'attendant', 'user') NOT NULL DEFAULT 'user',
    `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Vehicles Table
CREATE TABLE IF NOT EXISTS `vehicles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `plate_number` VARCHAR(20) NOT NULL,
    `vehicle_type` VARCHAR(30) NOT NULL DEFAULT 'car',
    `make_model` VARCHAR(100) NOT NULL,
    `color` VARCHAR(50) NOT NULL,
    `is_ev` TINYINT(1) NOT NULL DEFAULT 0,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_plate` (`user_id`, `plate_number`),
    INDEX `idx_vehicles_plate` (`plate_number`),
    INDEX `idx_vehicles_type` (`vehicle_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Facilities Table
CREATE TABLE IF NOT EXISTS `facilities` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `address` VARCHAR(255) NOT NULL,
    `locality` VARCHAR(150) NULL,
    `city` VARCHAR(100) NOT NULL,
    `district` VARCHAR(100) NULL,
    `state` VARCHAR(100) NOT NULL,
    `country` VARCHAR(50) NOT NULL DEFAULT 'India',
    `postal_code` VARCHAR(20) NOT NULL,
    `latitude` DECIMAL(10, 7) NOT NULL,
    `longitude` DECIMAL(10, 7) NOT NULL,
    `total_capacity` INT UNSIGNED NOT NULL DEFAULT 0,
    `hourly_rate` DECIMAL(8, 2) NOT NULL DEFAULT 50.00,
    `ev_rate` DECIMAL(8, 2) NOT NULL DEFAULT 75.00,
    `peak_rate` DECIMAL(8, 2) NOT NULL DEFAULT 70.00,
    `weekend_rate` DECIMAL(8, 2) NOT NULL DEFAULT 60.00,
    `overtime_rate_per_hour` DECIMAL(8, 2) NOT NULL DEFAULT 100.00,
    `grace_period_mins` INT UNSIGNED NOT NULL DEFAULT 15,
    `amenities_json` JSON NULL,
    `description` TEXT NULL,
    `image_url` VARCHAR(255) NULL,
    `status` ENUM('active', 'maintenance', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_facilities_city` (`city`),
    INDEX `idx_facilities_state` (`state`),
    INDEX `idx_facilities_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Slots Table
CREATE TABLE IF NOT EXISTS `slots` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `facility_id` INT UNSIGNED NOT NULL,
    `floor_level` VARCHAR(20) NOT NULL DEFAULT 'Floor 1',
    `zone_name` VARCHAR(50) NOT NULL DEFAULT 'Zone A',
    `slot_number` VARCHAR(20) NOT NULL,
    `slot_type` VARCHAR(30) NOT NULL DEFAULT 'car',
    `allowed_vehicle_types` VARCHAR(255) NULL,
    `is_ev_charging` TINYINT(1) NOT NULL DEFAULT 0,
    `ev_charger_type` VARCHAR(100) NULL DEFAULT 'None',
    `ev_charging_rate` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `slot_dimensions` VARCHAR(50) NULL DEFAULT '5.0m × 2.4m',
    `is_accessible` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('available', 'occupied', 'reserved', 'maintenance') NOT NULL DEFAULT 'available',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`facility_id`) REFERENCES `facilities`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_facility_slot` (`facility_id`, `floor_level`, `slot_number`),
    INDEX `idx_slots_facility` (`facility_id`),
    INDEX `idx_slots_status` (`status`),
    INDEX `idx_slots_type` (`slot_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Pricing Rules Table
CREATE TABLE IF NOT EXISTS `pricing_rules` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `facility_id` INT UNSIGNED NOT NULL,
    `vehicle_type` VARCHAR(30) NOT NULL DEFAULT 'all',
    `rule_name` VARCHAR(100) NOT NULL,
    `base_hourly_rate` DECIMAL(8, 2) NOT NULL,
    `peak_hourly_rate` DECIMAL(8, 2) NOT NULL,
    `weekend_hourly_rate` DECIMAL(8, 2) NOT NULL,
    `ev_surcharge_per_hour` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `min_booking_hours` DECIMAL(4, 2) NOT NULL DEFAULT 1.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`facility_id`) REFERENCES `facilities`(`id`) ON DELETE CASCADE,
    INDEX `idx_pricing_fac` (`facility_id`),
    INDEX `idx_pricing_type` (`vehicle_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Attendant Assignments Table
CREATE TABLE IF NOT EXISTS `attendant_assignments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `attendant_id` INT UNSIGNED NOT NULL,
    `facility_id` INT UNSIGNED NOT NULL,
    `shift_name` VARCHAR(50) NOT NULL DEFAULT 'General Shift',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`attendant_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`facility_id`) REFERENCES `facilities`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_attendant_facility` (`attendant_id`, `facility_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Reservations Table
CREATE TABLE IF NOT EXISTS `reservations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `booking_reference` VARCHAR(30) NOT NULL UNIQUE,
    `user_id` INT UNSIGNED NOT NULL,
    `facility_id` INT UNSIGNED NOT NULL,
    `slot_id` INT UNSIGNED NOT NULL,
    `vehicle_id` INT UNSIGNED NULL,
    `vehicle_plate` VARCHAR(20) NOT NULL,
    `vehicle_type` VARCHAR(30) NULL,
    `is_ev` TINYINT(1) NOT NULL DEFAULT 0,
    `ev_charging_requested` TINYINT(1) NOT NULL DEFAULT 0,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `total_hours` DECIMAL(5, 2) NOT NULL,
    `base_rate` DECIMAL(8, 2) NOT NULL,
    `base_amount` DECIMAL(8, 2) NOT NULL,
    `extra_amount` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(8, 2) NOT NULL,
    `status` ENUM('pending', 'confirmed', 'checked_in', 'completed', 'cancelled', 'expired', 'no_show') NOT NULL DEFAULT 'confirmed',
    `qr_token` VARCHAR(64) NOT NULL UNIQUE,
    `qr_token_hash` VARCHAR(64) NOT NULL,
    `check_in_time` DATETIME NULL,
    `check_out_time` DATETIME NULL,
    `checked_in_by` INT UNSIGNED NULL,
    `checked_out_by` INT UNSIGNED NULL,
    `actual_hours` DECIMAL(5, 2) NULL,
    `overtime_amount` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `cancellation_reason` VARCHAR(255) NULL,
    `cancelled_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`facility_id`) REFERENCES `facilities`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`slot_id`) REFERENCES `slots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`checked_in_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`checked_out_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_res_user` (`user_id`),
    INDEX `idx_res_facility` (`facility_id`),
    INDEX `idx_res_slot` (`slot_id`),
    INDEX `idx_res_time` (`start_time`, `end_time`),
    INDEX `idx_res_qr_hash` (`qr_token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. QR Passes Table
CREATE TABLE IF NOT EXISTS `qr_passes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reservation_id` INT UNSIGNED NOT NULL,
    `pass_code` VARCHAR(50) NOT NULL UNIQUE,
    `pass_token` VARCHAR(64) NOT NULL UNIQUE,
    `token_hash` VARCHAR(64) NOT NULL,
    `status` ENUM('active', 'used', 'expired', 'revoked') NOT NULL DEFAULT 'active',
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`reservation_id`) REFERENCES `reservations`(`id`) ON DELETE CASCADE,
    INDEX `idx_qr_pass_code` (`pass_code`),
    INDEX `idx_qr_hash` (`token_hash`),
    INDEX `idx_qr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Parking Sessions Table
CREATE TABLE IF NOT EXISTS `parking_sessions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reservation_id` INT UNSIGNED NOT NULL,
    `facility_id` INT UNSIGNED NOT NULL,
    `slot_id` INT UNSIGNED NOT NULL,
    `vehicle_plate` VARCHAR(20) NOT NULL,
    `check_in_time` DATETIME NOT NULL,
    `check_out_time` DATETIME NULL,
    `checked_in_by` INT UNSIGNED NULL,
    `checked_out_by` INT UNSIGNED NULL,
    `total_duration_hours` DECIMAL(6, 2) NULL,
    `overtime_duration_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `overtime_charges` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `status` ENUM('active', 'completed', 'overstay') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`reservation_id`) REFERENCES `reservations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`facility_id`) REFERENCES `facilities`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`slot_id`) REFERENCES `slots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`checked_in_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`checked_out_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_session_res` (`reservation_id`),
    INDEX `idx_session_fac` (`facility_id`),
    INDEX `idx_session_slot` (`slot_id`),
    INDEX `idx_session_status` (`status`),
    INDEX `idx_session_plate` (`vehicle_plate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Payments Table
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reservation_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `transaction_ref` VARCHAR(50) NOT NULL UNIQUE,
    `gateway_order_id` VARCHAR(100) NULL,
    `gateway_payment_id` VARCHAR(100) NULL,
    `gateway_signature` VARCHAR(255) NULL,
    `payment_method` ENUM('card', 'upi', 'netbanking', 'wallet', 'cash_on_arrival', 'simulation') NOT NULL DEFAULT 'simulation',
    `amount` DECIMAL(8, 2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
    `status` ENUM('success', 'pending', 'failed', 'refunded') NOT NULL DEFAULT 'success',
    `payment_type` ENUM('reservation_fee', 'overstay_penalty', 'refund') NOT NULL DEFAULT 'reservation_fee',
    `gateway_provider` VARCHAR(50) NOT NULL DEFAULT 'Simulated Gateway (Code2Web 2026)',
    `simulated_meta` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`reservation_id`) REFERENCES `reservations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_payments_res` (`reservation_id`),
    INDEX `idx_payments_user` (`user_id`),
    INDEX `idx_payments_status` (`status`),
    INDEX `idx_payments_order` (`gateway_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Audit Logs Table
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT UNSIGNED NULL,
    `details` TEXT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
    INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Rate Limits Table
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `action_key` VARCHAR(50) NOT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
    `last_attempt_at` INT UNSIGNED NOT NULL,
    UNIQUE KEY `unique_ip_action` (`ip_address`, `action_key`),
    INDEX `idx_rate_limit_time` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
