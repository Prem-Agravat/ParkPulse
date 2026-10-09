<?php
/**
 * Administrator Executive Command Center & Analytics
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireRole('admin');

$db = Database::getInstance()->getConnection();

// Fetch recent reservations
$resStmt = $db->query("SELECT r.*, f.name AS facility_name, s.slot_number, u.name AS user_name 
    FROM reservations r
    JOIN facilities f ON r.facility_id = f.id
    JOIN slots s ON r.slot_id = s.id
    JOIN users u ON r.user_id = u.id
    ORDER BY r.created_at DESC LIMIT 6");
$recentBookings = $resStmt->fetchAll();

// Fetch recent audit activity
$auditStmt = $db->query("SELECT a.*, u.name as user_name, u.role as user_role 
    FROM audit_logs a 
    LEFT JOIN users u ON a.user_id = u.id 
    ORDER BY a.created_at DESC LIMIT 6");
$recentAudits = $auditStmt->fetchAll();

$pageTitle = 'Admin Command Center & Analytics';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 px-3 py-4">
    <!-- Top Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 mb-1 small fw-bold">
                <i class="bi bi-shield-lock-fill"></i> System Administrator Control
            </div>
            <h1 class="h3 fw-bold mb-0 text-dark">Executive Command Center</h1>
            <p class="text-secondary small mb-0">Live system performance, financial analytics, facility occupancy & audit trail</p>
        </div>
        <div class="d-flex gap-2">
            <a href="admin-payments.php" class="btn btn-outline-success btn-sm fw-bold">
                <i class="bi bi-credit-card-2-front-fill me-1"></i> Payments & Revenue
            </a>
            <a href="admin-facilities.php" class="btn btn-primary-custom btn-sm">
                <i class="bi bi-buildings me-1"></i> Manage Facilities
            </a>
            <a href="admin-reservations.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-journal-text me-1"></i> All Bookings
            </a>
        </div>
    </div>

    <!-- Live KPI Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <a href="admin-payments.php" class="text-decoration-none d-block h-100">
                <div class="stat-card border-0 shadow-sm h-100" style="transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='none'">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">Total Gross Revenue</div>
                            <div class="stat-value text-success" id="kpiRevenue">₹0.00</div>
                        </div>
                        <div class="stat-icon-wrapper bg-success bg-opacity-10 text-success">
                            <i class="bi bi-currency-rupee"></i>
                        </div>
                    </div>
                    <div class="small text-primary mt-2 fw-semibold"><i class="bi bi-box-arrow-up-right me-1"></i> View Payments Portal →</div>
                </div>
            </a>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card border-0 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label">Total Bookings</div>
                        <div class="stat-value text-primary" id="kpiBookings">0</div>
                    </div>
                    <div class="stat-icon-wrapper bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-ticket-perforated-fill"></i>
                    </div>
                </div>
                <div class="small text-muted mt-2">Reservations processed</div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card border-0 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label">Vehicles On-Premise</div>
                        <div class="stat-value text-info" id="kpiActiveVehicles">0</div>
                    </div>
                    <div class="stat-icon-wrapper bg-info bg-opacity-10 text-info">
                        <i class="bi bi-car-front-fill"></i>
                    </div>
                </div>
                <div class="small text-muted mt-2">Currently checked-in</div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card border-0 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="stat-label">Network Occupancy</div>
                        <div class="stat-value text-warning" id="kpiOccupancy">0%</div>
                    </div>
                    <div class="stat-icon-wrapper bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-pie-chart-fill"></i>
                    </div>
                </div>
                <div class="small text-muted mt-2">Across all active facilities</div>
            </div>
        </div>
    </div>

    <!-- Charts Row 1: Revenue Trend & Facility Occupancy -->
    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-graph-up text-primary"></i>
                        <span class="fw-bold">Revenue Trend (Last 7 Days)</span>
                    </div>
                    <span class="badge bg-light text-secondary border">Daily Breakdown</span>
                </div>
                <div class="card-body p-3">
                    <div style="height: 280px;">
                        <canvas id="chartRevenueTrend"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-bar-chart-fill text-info"></i>
                        <span class="fw-bold">Occupancy by Facility</span>
                    </div>
                    <span class="badge bg-light text-secondary border">Real-Time</span>
                </div>
                <div class="card-body p-3">
                    <div style="height: 280px;">
                        <canvas id="chartFacilityOccupancy"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row 2: Fleet Types & Peak Booking Hours -->
    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header card-header-custom d-flex align-items-center gap-2">
                    <i class="bi bi-pie-chart-fill text-warning"></i>
                    <span class="fw-bold">Vehicle Fleet Breakdown</span>
                </div>
                <div class="card-body p-3">
                    <div style="height: 240px;">
                        <canvas id="chartVehicleTypes"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header card-header-custom d-flex align-items-center gap-2">
                    <i class="bi bi-clock-history text-danger"></i>
                    <span class="fw-bold">Peak Booking Hours Distribution (24-Hour)</span>
                </div>
                <div class="card-body p-3">
                    <div style="height: 240px;">
                        <canvas id="chartPeakHours"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Reservations & Live Audit Trail Row -->
    <div class="row g-4">
        <!-- Recent Reservations -->
        <div class="col-lg-7">
            <div class="card card-custom border-0 shadow-sm">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-journal-text text-primary"></i>
                        <span class="fw-bold">Recent Reservations</span>
                    </div>
                    <a href="admin-reservations.php" class="small text-primary text-decoration-none fw-bold">View All &rarr;</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Ref</th>
                                    <th>Driver & Vehicle</th>
                                    <th>Facility / Slot</th>
                                    <th>Status</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentBookings)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-secondary">No reservations found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentBookings as $rb): ?>
                                        <tr>
                                            <td>
                                                <a href="pass.php?ref=<?= urlencode($rb['booking_reference']) ?>" class="font-monospace fw-bold text-primary text-decoration-none">
                                                    <?= Security::escape($rb['booking_reference']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?= Security::escape($rb['user_name']) ?></div>
                                                <small class="font-monospace text-muted"><?= Security::escape($rb['vehicle_plate']) ?></small>
                                            </td>
                                            <td>
                                                <div class="text-dark small text-truncate" style="max-width: 140px;"><?= Security::escape($rb['facility_name']) ?></div>
                                                <small class="badge bg-light text-secondary border font-monospace"><?= Security::escape($rb['slot_number']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge <?= match($rb['status']) {
                                                    'confirmed' => 'bg-warning text-dark',
                                                    'checked_in' => 'bg-success',
                                                    'completed' => 'bg-secondary',
                                                    'cancelled' => 'bg-danger',
                                                    default => 'bg-info'
                                                } ?> text-uppercase small">
                                                    <?= Security::escape($rb['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="fw-bold text-dark">₹<?= number_format((float)$rb['total_amount'], 2) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Audit Log Feed -->
        <div class="col-lg-5">
            <div class="card card-custom border-0 shadow-sm">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-shield-check text-danger"></i>
                        <span class="fw-bold">Security & Audit Feed</span>
                    </div>
                    <a href="admin-audit.php" class="small text-primary text-decoration-none fw-bold">Full Logs &rarr;</a>
                </div>
                <div class="card-body p-3">
                    <?php if (empty($recentAudits)): ?>
                        <p class="text-secondary small text-center my-3">No audit events recorded.</p>
                    <?php else: ?>
                        <div class="d-grid gap-2">
                            <?php foreach ($recentAudits as $al): ?>
                                <div class="p-2 rounded bg-light border d-flex align-items-start gap-2 small">
                                    <div class="p-1 rounded bg-white border text-primary">
                                        <i class="bi bi-activity"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="badge bg-dark font-monospace" style="font-size: 0.65rem;"><?= Security::escape($al['action']) ?></span>
                                            <span class="text-muted" style="font-size: 0.72rem;"><?= date('h:i A', strtotime($al['created_at'])) ?></span>
                                        </div>
                                        <p class="text-secondary mb-0 mt-1" style="font-size: 0.8rem; line-height: 1.3;">
                                            <?= Security::escape($al['details'] ?? 'Action performed') ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Dashboard Script -->
<script>
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const res = await ParkPulse.fetchJson('api/admin-stats.php');
        if (!res.success) return;

        // 1. Populate KPIs
        const s = res.summary;
        document.getElementById('kpiRevenue').innerText = `₹${s.total_revenue.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
        document.getElementById('kpiBookings').innerText = s.total_bookings;
        document.getElementById('kpiActiveVehicles').innerText = s.active_vehicles;
        document.getElementById('kpiOccupancy').innerText = `${s.overall_occupancy_pct}%`;

        // 2. Chart: 7-Day Revenue Trend
        const revCtx = document.getElementById('chartRevenueTrend').getContext('2d');
        new Chart(revCtx, {
            type: 'line',
            data: {
                labels: res.revenue_trend.map(d => d.date),
                datasets: [{
                    label: 'Revenue (₹)',
                    data: res.revenue_trend.map(d => d.revenue),
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 3,
                    pointBackgroundColor: '#2563eb',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                    x: { grid: { display: false } }
                }
            }
        });

        // 3. Chart: Facility Occupancy
        const occCtx = document.getElementById('chartFacilityOccupancy').getContext('2d');
        new Chart(occCtx, {
            type: 'bar',
            data: {
                labels: res.facility_occupancy.map(f => f.name.length > 15 ? f.name.substring(0, 15) + '...' : f.name),
                datasets: [
                    {
                        label: 'Occupied',
                        data: res.facility_occupancy.map(f => f.occupied),
                        backgroundColor: '#ef4444'
                    },
                    {
                        label: 'Reserved',
                        data: res.facility_occupancy.map(f => f.reserved),
                        backgroundColor: '#f59e0b'
                    },
                    {
                        label: 'Available',
                        data: res.facility_occupancy.map(f => f.available),
                        backgroundColor: '#10b981'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, grid: { color: '#f1f5f9' } }
                },
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // 4. Chart: Vehicle Types Doughnut
        const vehCtx = document.getElementById('chartVehicleTypes').getContext('2d');
        new Chart(vehCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(res.vehicle_types),
                datasets: [{
                    data: Object.values(res.vehicle_types),
                    backgroundColor: ['#2563eb', '#06b6d4', '#8b5cf6', '#10b981', '#f59e0b', '#64748b']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12 } }
                }
            }
        });

        // 5. Chart: Peak Booking Hours
        const peakCtx = document.getElementById('chartPeakHours').getContext('2d');
        new Chart(peakCtx, {
            type: 'bar',
            data: {
                labels: Array.from({length: 24}, (_, i) => `${i}:00`),
                datasets: [{
                    label: 'Bookings Created',
                    data: res.peak_hours,
                    backgroundColor: 'rgba(239, 68, 68, 0.75)',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                    x: { grid: { display: false } }
                }
            }
        });

    } catch (err) {
        console.error('Failed to load admin stats:', err);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
