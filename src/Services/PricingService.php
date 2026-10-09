<?php
/**
 * Pricing & Tariff Calculation Service
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

final class PricingService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * Calculate dynamic tariff for a given facility, vehicle category, EV charging request, and time window
     */
    public function calculateFare(
        int $facilityId,
        string $startTime,
        string $endTime,
        string $vehicleType = 'car',
        bool $isEv = false,
        bool $evChargingRequested = false
    ): array {
        $stmt = $this->db->prepare("SELECT * FROM facilities WHERE id = ?");
        $stmt->execute([$facilityId]);
        $facility = $stmt->fetch();

        if (!$facility) {
            throw new \InvalidArgumentException("Facility ID {$facilityId} not found.");
        }

        $startTs = strtotime($startTime);
        $endTs = strtotime($endTime);

        if (!$startTs || !$endTs || $endTs <= $startTs) {
            throw new \InvalidArgumentException("Invalid check-in/check-out timestamps.");
        }

        $durationSecs = $endTs - $startTs;
        $durationHours = max(0.5, round($durationSecs / 3600, 2));

        // Check if custom pricing rule exists for this facility and vehicle category
        $ruleStmt = $this->db->prepare("SELECT * FROM pricing_rules WHERE facility_id = ? AND is_active = 1 AND (vehicle_type = ? OR vehicle_type = 'all') ORDER BY (vehicle_type = ?) DESC LIMIT 1");
        $ruleStmt->execute([$facilityId, $vehicleType, $vehicleType]);
        $customRule = $ruleStmt->fetch();

        $minBookingHours = $customRule ? (float)($customRule['min_booking_hours'] ?? 1.0) : 1.0;
        $billableHours = (int)ceil(max($durationHours, $minBookingHours));

        // Category base rate
        $categoryMultipliers = [
            'bicycle' => 0.3,
            'bike' => 0.4,
            'auto_rickshaw' => 0.6,
            'car' => 1.0,
            'van' => 1.4,
            'truck' => 2.2,
            'bus' => 2.4,
            'other' => 1.0
        ];

        $facilityBaseRate = (float)$facility['hourly_rate'];
        $multiplier = $categoryMultipliers[$vehicleType] ?? 1.0;
        $baseRate = $customRule ? (float)$customRule['base_hourly_rate'] : round($facilityBaseRate * $multiplier, 2);

        // Check if weekend (Saturday or Sunday)
        $dayOfWeek = (int)date('w', $startTs);
        $isWeekend = ($dayOfWeek === 0 || $dayOfWeek === 6);
        if ($isWeekend) {
            if ($customRule && (float)$customRule['weekend_hourly_rate'] > 0) {
                $baseRate = (float)$customRule['weekend_hourly_rate'];
            } elseif ((float)$facility['weekend_rate'] > $facilityBaseRate) {
                $baseRate = round((float)$facility['weekend_rate'] * $multiplier, 2);
            }
        }

        // Check if peak hour (8:00 - 11:00 AM or 17:00 - 20:00)
        $startHour = (int)date('H', $startTs);
        $isPeak = ($startHour >= 8 && $startHour <= 11) || ($startHour >= 17 && $startHour <= 20);
        if ($isPeak) {
            if ($customRule && (float)$customRule['peak_hourly_rate'] > 0) {
                $baseRate = (float)$customRule['peak_hourly_rate'];
            } elseif ((float)$facility['peak_rate'] > $facilityBaseRate) {
                $baseRate = round((float)$facility['peak_rate'] * $multiplier, 2);
            }
        }

        // EV Charging Surcharge (if charging is requested or EV bay reserved)
        $evSurchargePerHour = 0.00;
        if ($evChargingRequested || $isEv || $vehicleType === 'ev') {
            if ($customRule && (float)($customRule['ev_surcharge_per_hour'] ?? 0) > 0) {
                $evSurchargePerHour = (float)$customRule['ev_surcharge_per_hour'];
            } else {
                $evDiff = max(0.0, (float)$facility['ev_rate'] - (float)$facility['hourly_rate']);
                $evSurchargePerHour = $evDiff > 0 ? $evDiff : 25.00;
            }
        }

        $basePaise = (int)round($billableHours * $baseRate * 100);
        $evPaise = (int)round($billableHours * $evSurchargePerHour * 100);
        $totalPaise = $basePaise + $evPaise;

        $baseAmount = round($basePaise / 100, 2);
        $evAmount = round($evPaise / 100, 2);
        $totalAmount = round($totalPaise / 100, 2);

        return [
            'duration_hours' => $durationHours,
            'billable_hours' => $billableHours,
            'hourly_rate' => $baseRate,
            'ev_surcharge_per_hour' => $evSurchargePerHour,
            'base_amount' => $baseAmount,
            'extra_amount' => $evAmount,
            'total_amount' => $totalAmount,
            'is_peak' => $isPeak,
            'is_weekend' => $isWeekend,
            'is_ev' => $isEv,
            'ev_charging_requested' => $evChargingRequested,
            'grace_period_mins' => (int)$facility['grace_period_mins'],
            'overtime_rate_per_hour' => (float)$facility['overtime_rate_per_hour']
        ];
    }

    /**
     * Calculate overtime penalty charges at exit
     */
    public function calculateOvertime(int $facilityId, string $scheduledEndTime, int $actualExitTs): array
    {
        $stmt = $this->db->prepare("SELECT * FROM facilities WHERE id = ?");
        $stmt->execute([$facilityId]);
        $facility = $stmt->fetch();

        $scheduledEndTs = strtotime($scheduledEndTime);
        $graceSecs = ((int)($facility['grace_period_mins'] ?? 15)) * 60;
        $overtimeRate = (float)($facility['overtime_rate_per_hour'] ?? 100.00);

        if ($actualExitTs <= ($scheduledEndTs + $graceSecs)) {
            return [
                'is_overstay' => false,
                'overtime_seconds' => 0,
                'overtime_minutes' => 0,
                'overtime_hours' => 0,
                'overtime_charges' => 0.00
            ];
        }

        $overtimeSecs = $actualExitTs - $scheduledEndTs;
        $overtimeMins = (int)ceil($overtimeSecs / 60);
        $overtimeHours = (int)ceil($overtimeSecs / 3600);
        $overtimeCharges = round($overtimeHours * $overtimeRate, 2);

        return [
            'is_overstay' => true,
            'overtime_seconds' => $overtimeSecs,
            'overtime_minutes' => $overtimeMins,
            'overtime_hours' => $overtimeHours,
            'overtime_charges' => $overtimeCharges
        ];
    }
}
