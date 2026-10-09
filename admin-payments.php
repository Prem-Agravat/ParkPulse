<?php
/**
 * Master Admin Payments & Revenue Portal
 * Smart Parking Reservation & Management System (India / Gujarat Edition)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/src/Services/PaymentGatewayService.php';

Auth::requireRole('admin');

$db = Database::getInstance()->getConnection();
$paymentService = new PaymentGatewayService($db);

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=parkpulse_payments_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Transaction Ref', 'Gateway Order ID', 'Gateway Payment ID', 'Booking Reference', 'Customer Name', 'Phone', 'Facility', 'Slot', 'Method', 'Amount (INR)', 'Status', 'Type', 'Provider', 'Timestamp (IST)']);
    
    $exportSql = "SELECT p.*, r.booking_reference, r.vehicle_plate, u.name AS driver_name, u.phone AS driver_phone, f.name AS facility_name, s.slot_number 
        FROM payments p 
        JOIN reservations r ON p.reservation_id = r.id 
        JOIN users u ON p.user_id = u.id 
        JOIN facilities f ON r.facility_id = f.id 
        JOIN slots s ON r.slot_id = s.id 
        ORDER BY p.id DESC LIMIT 1000";
    $rows = $db->query($exportSql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['transaction_ref'],
            $r['gateway_order_id'] ?? 'N/A',
            $r['gateway_payment_id'] ?? 'N/A',
            $r['booking_reference'],
            $r['driver_name'],
            $r['driver_phone'],
            $r['facility_name'],
            $r['slot_number'],
            strtoupper($r['payment_method']),
            $r['amount'],
            strtoupper($r['status']),
            $r['payment_type'],
            $r['gateway_provider'],
            $r['created_at']
        ]);
    }
    fclose($out);
    exit;
}

// Fetch aggregate KPI metrics
$stats = $paymentService->getPaymentSummaryStats();

// Fetch facilities for dropdown filter
$facilities = $db->query("SELECT id, name, city FROM facilities ORDER BY city, name")->fetchAll();

// Filter & Pagination parameters
$q = Security::sanitizeString($_GET['q'] ?? '');
$statusFilter = Security::sanitizeString($_GET['status'] ?? 'all');
$methodFilter = Security::sanitizeString($_GET['method'] ?? 'all');
$facilityFilter = (int)($_GET['facility_id'] ?? 0);
$startDate = Security::sanitizeString($_GET['start_date'] ?? '');
$endDate = Security::sanitizeString($_GET['end_date'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Construct filtered SQL query
$conditions = [];
$params = [];

if (!empty($q)) {
    $cleanPlate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q));
    $conditions[] = "(p.transaction_ref LIKE ? OR p.gateway_order_id LIKE ? OR r.booking_reference LIKE ? OR u.name LIKE ? OR REPLACE(r.vehicle_plate, '-', '') LIKE ?)";
    $searchWildcard = "%{$q}%";
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
    $params[] = "%{$cleanPlate}%";
}

if ($statusFilter !== 'all') {
    $conditions[] = "p.status = ?";
    $params[] = $statusFilter;
}

if ($methodFilter !== 'all') {
    $conditions[] = "p.payment_method = ?";
    $params[] = $methodFilter;
}

if ($facilityFilter > 0) {
    $conditions[] = "r.facility_id = ?";
    $params[] = $facilityFilter;
}

if (!empty($startDate)) {
    $conditions[] = "DATE(p.created_at) >= ?";
    $params[] = $startDate;
}

if (!empty($endDate)) {
    $conditions[] = "DATE(p.created_at) <= ?";
    $params[] = $endDate;
}

$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Total count for pagination
$countStmt = $db->prepare("SELECT COUNT(*) FROM payments p 
    JOIN reservations r ON p.reservation_id = r.id 
    JOIN users u ON p.user_id = u.id 
    JOIN facilities f ON r.facility_id = f.id 
    {$whereClause}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $limit));

// Fetch page records
$sql = "SELECT p.*, r.booking_reference, r.vehicle_plate, r.vehicle_type, u.name AS driver_name, u.phone AS driver_phone, u.email AS driver_email, f.name AS facility_name, f.city, s.slot_number, s.floor_level, s.zone_name 
    FROM payments p 
    JOIN reservations r ON p.reservation_id = r.id 
    JOIN users u ON p.user_id = u.id 
    JOIN facilities f ON r.facility_id = f.id 
    JOIN slots s ON r.slot_id = s.id 
    {$whereClause} 
    ORDER BY p.id DESC 
    LIMIT {$limit} OFFSET {$offset}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$pageTitle = 'Payments & Revenue Command Center';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Top Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 mb-1 small fw-bold">
                <i class="bi bi-shield-lock-fill"></i> Financial Portal · India (₹ INR)
            </div>
            <h1 class="h3 fw-bold mb-0 text-dark">Payments & Revenue Command Center</h1>
            <p class="text-secondary small mb-0">Inspect real-time gateway transactions, dynamic UPI/QR collections, card settlements, and process automated refunds.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="admin-payments.php?export=csv<?= !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="btn btn-outline-success btn-sm fw-bold">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export to CSV
            </a>
            <a href="admin-dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Analytics Dashboard
            </a>
        </div>
    </div>

    <!-- Razorpay & Gateway Status Banner -->
    <div class="alert alert-primary bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4 p-3 shadow-sm">
        <div class="d-flex align-items-center gap-3">
            <div class="p-2 bg-primary text-white rounded-3">
                <i class="bi bi-shield-check fs-4"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h6 class="fw-bold mb-0 text-dark">Razorpay Testing Mode Gateway</h6>
                    <span class="badge bg-success">ACTIVE & SECURED</span>
                </div>
                <p class="small text-secondary mb-0">
                    Live Web SDK test environment enabled · Key ID: <code class="font-monospace text-primary fw-bold"><?= Security::escape(env('RAZORPAY_KEY_ID', 'rzp_test_parkpulse2026demo')) ?></code> · HMAC-SHA256 Cryptographic Signature Handshake
                </p>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="reserve.php" target="_blank" class="btn btn-sm btn-primary fw-semibold text-nowrap">
                <i class="bi bi-box-arrow-up-right me-1"></i> Test Customer Checkout
            </a>
        </div>
    </div>

    <!-- KPI Summary Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-sm-6">
            <div class="card card-custom border-0 shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase d-block mb-1">Total Gross Revenue</span>
                        <h2 class="h3 fw-bold text-dark mb-0"><?= $stats['total_volume_formatted'] ?></h2>
                        <span class="badge bg-success bg-opacity-10 text-success small mt-2">
                            <i class="bi bi-check-circle me-1"></i> <?= number_format($stats['successful_transactions']) ?> Settled Transactions
                        </span>
                    </div>
                    <div class="p-3 bg-primary bg-opacity-10 text-primary rounded-4">
                        <i class="bi bi-currency-rupee fs-2"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="card card-custom border-0 shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase d-block mb-1">Today's Collections</span>
                        <h2 class="h3 fw-bold text-success mb-0"><?= $stats['today_volume_formatted'] ?></h2>
                        <span class="text-muted small mt-2 d-block">Instant settlements (IST)</span>
                    </div>
                    <div class="p-3 bg-success bg-opacity-10 text-success rounded-4">
                        <i class="bi bi-lightning-charge fs-2"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="card card-custom border-0 shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase d-block mb-1">Total Refunded Volume</span>
                        <h2 class="h3 fw-bold text-danger mb-0"><?= $stats['refunded_volume_formatted'] ?></h2>
                        <span class="text-muted small mt-2 d-block">Automatic cancellations</span>
                    </div>
                    <div class="p-3 bg-danger bg-opacity-10 text-danger rounded-4">
                        <i class="bi bi-arrow-counterclockwise fs-2"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="card card-custom border-0 shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase d-block mb-1">Payment Success Rate</span>
                        <h2 class="h3 fw-bold text-dark mb-0">
                            <?php 
                            $totalAttempts = $stats['successful_transactions'] + $stats['failed_count'];
                            echo $totalAttempts > 0 ? round(($stats['successful_transactions'] / $totalAttempts) * 100, 1) . '%' : '100%';
                            ?>
                        </h2>
                        <span class="badge bg-info bg-opacity-10 text-info small mt-2">
                            <i class="bi bi-shield-check me-1"></i> Multi-Channel Gateway
                        </span>
                    </div>
                    <div class="p-3 bg-info bg-opacity-10 text-info rounded-4">
                        <i class="bi bi-graph-up-arrow fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & Filter Card -->
    <div class="card card-custom border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="GET" action="admin-payments.php" class="row g-2 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label for="filterQuery" class="form-label small text-secondary fw-semibold mb-1">Search Keywords</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" id="filterQuery" name="q" value="<?= Security::escape($q) ?>" placeholder="Ref / Order / Driver / Plate">
                    </div>
                </div>

                <div class="col-lg-2 col-md-3 col-6">
                    <label for="filterStatus" class="form-label small text-secondary fw-semibold mb-1">Status</label>
                    <select class="form-select form-select-sm" id="filterStatus" name="status">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>Success (Paid)</option>
                        <option value="refunded" <?= $statusFilter === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-3 col-6">
                    <label for="filterMethod" class="form-label small text-secondary fw-semibold mb-1">Method</label>
                    <select class="form-select form-select-sm" id="filterMethod" name="method">
                        <option value="all" <?= $methodFilter === 'all' ? 'selected' : '' ?>>All Channels</option>
                        <option value="razorpay" <?= $methodFilter === 'razorpay' ? 'selected' : '' ?>>Razorpay Test Gateway</option>
                        <option value="upi" <?= $methodFilter === 'upi' ? 'selected' : '' ?>>UPI / Dynamic QR</option>
                        <option value="card" <?= $methodFilter === 'card' ? 'selected' : '' ?>>Credit / Debit Card</option>
                        <option value="netbanking" <?= $methodFilter === 'netbanking' ? 'selected' : '' ?>>Net Banking</option>
                        <option value="cash_on_arrival" <?= $methodFilter === 'cash_on_arrival' ? 'selected' : '' ?>>Cash on Arrival</option>
                        <option value="simulation" <?= $methodFilter === 'simulation' ? 'selected' : '' ?>>Sandbox Test</option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-4 col-6">
                    <label for="filterFacility" class="form-label small text-secondary fw-semibold mb-1">Facility</label>
                    <select class="form-select form-select-sm" id="filterFacility" name="facility_id">
                        <option value="0">All Facilities</option>
                        <?php foreach ($facilities as $fac): ?>
                            <option value="<?= $fac['id'] ?>" <?= $facilityFilter === (int)$fac['id'] ? 'selected' : '' ?>>
                                <?= Security::escape($fac['name']) ?> (<?= Security::escape($fac['city']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-3 col-md-8 col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary-custom btn-sm w-100 fw-bold">
                        <i class="bi bi-funnel-fill me-1"></i> Apply Filter
                    </button>
                    <a href="admin-payments.php" class="btn btn-outline-secondary btn-sm px-3" title="Reset Filters">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Payments Data Table -->
    <div class="card card-custom border-0 shadow-sm">
        <div class="card-header bg-white border-bottom p-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-table text-primary"></i>
                <span class="fw-bold text-dark">Transaction Records</span>
                <span class="badge bg-secondary bg-opacity-25 text-dark small ms-1"><?= number_format($totalRecords) ?> total</span>
            </div>
            <span class="small text-muted">Showing page <?= $page ?> of <?= $totalPages ?></span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th>Transaction Ref / Order</th>
                        <th>Booking / Facility</th>
                        <th>Customer / Driver</th>
                        <th>Channel / Method</th>
                        <th>Amount (INR)</th>
                        <th>Status</th>
                        <th>Timestamp (IST)</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-secondary">
                                <i class="bi bi-receipt-cutoff fs-1 d-block mb-2 text-muted"></i>
                                <strong>No payment records found.</strong>
                                <p class="small text-muted mb-0">Try clearing filters or search queries.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td>
                                    <span class="font-monospace fw-bold text-dark d-block"><?= Security::escape($p['transaction_ref']) ?></span>
                                    <?php if (!empty($p['gateway_order_id'])): ?>
                                        <code class="small text-muted d-block" style="font-size: 0.72rem;"><?= Security::escape($p['gateway_order_id']) ?></code>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="pass.php?ref=<?= urlencode($p['booking_reference']) ?>" target="_blank" class="fw-semibold text-primary text-decoration-none font-monospace">
                                        <?= Security::escape($p['booking_reference']) ?>
                                    </a>
                                    <div class="small text-secondary text-truncate" style="max-width: 200px;">
                                        <?= Security::escape($p['facility_name']) ?> · <strong>Stall <?= Security::escape($p['slot_number']) ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-semibold text-dark d-block"><?= Security::escape($p['driver_name']) ?></span>
                                    <span class="small text-muted"><?= Security::formatIndianMobile($p['driver_phone']) ?></span>
                                </td>
                                <td>
                                    <?php
                                    $method = $p['payment_method'];
                                    $badgeClass = match ($method) {
                                        'razorpay' => 'bg-primary text-white shadow-sm',
                                        'upi' => 'bg-info bg-opacity-10 text-info border border-info border-opacity-25',
                                        'card' => 'bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25',
                                        'netbanking' => 'bg-warning bg-opacity-10 text-dark border border-warning border-opacity-25',
                                        'cash_on_arrival' => 'bg-secondary bg-opacity-10 text-dark border',
                                        default => 'bg-light text-secondary border'
                                    };
                                    $methodIcon = match ($method) {
                                        'razorpay' => 'bi-lightning-charge-fill',
                                        'upi' => 'bi-qr-code-scan',
                                        'card' => 'bi-credit-card-2-front',
                                        'netbanking' => 'bi-bank',
                                        'cash_on_arrival' => 'bi-cash',
                                        default => 'bi-cpu'
                                    };
                                    ?>
                                    <span class="badge <?= $badgeClass ?> px-2 py-1">
                                        <i class="bi <?= $methodIcon ?> me-1"></i> <?= $method === 'razorpay' ? 'RAZORPAY TEST' : strtoupper(str_replace('_', ' ', $method)) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong class="fs-6 <?= $p['status'] === 'refunded' ? 'text-decoration-line-through text-muted' : 'text-dark' ?>">
                                        <?= Security::formatInr((float)$p['amount']) ?>
                                    </strong>
                                    <?php if ($p['payment_type'] === 'refund'): ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger d-block small mt-1">Refund Outflow</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $statusBadge = match ($p['status']) {
                                        'success' => 'bg-success',
                                        'refunded' => 'bg-danger',
                                        'pending' => 'bg-warning text-dark',
                                        'failed' => 'bg-dark',
                                        default => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge <?= $statusBadge ?> text-uppercase">
                                        <?= Security::escape($p['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="small text-dark d-block"><?= date('d M Y, h:i A', strtotime($p['created_at'])) ?></span>
                                    <span class="small text-muted"><?= Security::escape($p['gateway_provider']) ?></span>
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            Actions
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                            <li>
                                                <button class="dropdown-item" type="button" onclick="inspectPayment(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">
                                                    <i class="bi bi-eye text-primary me-2"></i> Inspect Metadata
                                                </button>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="pass.php?ref=<?= urlencode($p['booking_reference']) ?>" target="_blank">
                                                    <i class="bi bi-qr-code text-secondary me-2"></i> View Digital Pass
                                                </a>
                                            </li>
                                            <?php if ($p['status'] === 'success' && $p['payment_type'] !== 'refund'): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button class="dropdown-item text-danger" type="button" onclick="openRefundModal(<?= (int)$p['id'] ?>, '<?= Security::escape($p['transaction_ref']) ?>', <?= (float)$p['amount'] ?>, '<?= Security::escape($p['booking_reference']) ?>')">
                                                        <i class="bi bi-arrow-counterclockwise me-2"></i> Issue Full Refund
                                                    </button>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Footer -->
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white border-top p-3 d-flex justify-content-between align-items-center">
                <span class="small text-muted">Showing <?= $offset + 1 ?> to <?= min($totalRecords, $offset + $limit) ?> of <?= $totalRecords ?> items</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= min($totalPages, 7); $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Inspect Payment Metadata -->
<div class="modal fade" id="inspectModal" tabindex="-1" aria-labelledby="inspectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white p-3">
                <h5 class="modal-title h6 fw-bold" id="inspectModalLabel">
                    <i class="bi bi-shield-check text-primary me-2"></i> Payment Gateway Metadata Inspector
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <small class="text-secondary d-block">Transaction Reference</small>
                        <strong class="font-monospace text-dark" id="modalTxnRef">--</strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-secondary d-block">Gateway Order ID</small>
                        <strong class="font-monospace text-dark" id="modalOrderId">--</strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-secondary d-block">Settlement Amount</small>
                        <strong class="fs-5 text-success" id="modalAmount">₹0.00</strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-secondary d-block">Gateway Provider</small>
                        <strong class="text-dark" id="modalProvider">--</strong>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-secondary fw-semibold">Simulated / Gateway Raw Metadata (JSON)</label>
                    <pre class="bg-light p-3 rounded-3 border text-dark font-monospace small mb-0" id="modalJsonMeta" style="max-height: 200px; overflow-y: auto;">{}</pre>
                </div>
            </div>
            <div class="modal-footer bg-light p-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close Inspector</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Issue Refund Confirmation -->
<div class="modal fade" id="refundModal" tabindex="-1" aria-labelledby="refundModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white p-3">
                <h5 class="modal-title h6 fw-bold" id="refundModalLabel">
                    <i class="bi bi-arrow-counterclockwise me-2"></i> Issue Payment Refund
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="refundPaymentId" value="">
                <p class="text-secondary mb-3">
                    Are you sure you want to process a full refund of <strong class="text-dark" id="refundAmountText">₹0.00</strong> for Booking Reference <strong class="font-monospace text-dark" id="refundBookingRef">--</strong>?
                </p>
                <div class="mb-3">
                    <label for="refundReasonInput" class="form-label small text-secondary fw-semibold">Refund Reason / Audit Note</label>
                    <input type="text" id="refundReasonInput" class="form-control form-control-sm" placeholder="e.g. Driver cancelled before check-in time" value="Driver requested cancellation refund">
                </div>
                <div class="alert alert-warning small mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
                    <div>This action marks the payment as refunded, releases the stall reservation, and logs an immutable audit event.</div>
                </div>
            </div>
            <div class="modal-footer bg-light p-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="btnConfirmRefund" class="btn btn-danger btn-sm fw-bold px-4" onclick="executeRefund()">
                    <i class="bi bi-check-circle me-1"></i> Confirm & Process Refund
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let inspectModalInstance = null;
let refundModalInstance = null;

document.addEventListener('DOMContentLoaded', () => {
    inspectModalInstance = new bootstrap.Modal(document.getElementById('inspectModal'));
    refundModalInstance = new bootstrap.Modal(document.getElementById('refundModal'));
});

function inspectPayment(p) {
    document.getElementById('modalTxnRef').innerText = p.transaction_ref || 'N/A';
    document.getElementById('modalOrderId').innerText = p.gateway_order_id || 'N/A';
    document.getElementById('modalAmount').innerText = '₹' + parseFloat(p.amount).toFixed(2);
    document.getElementById('modalProvider').innerText = p.gateway_provider || 'Standard Gateway';
    
    let metaObj = {};
    try {
        metaObj = p.simulated_meta ? (typeof p.simulated_meta === 'string' ? JSON.parse(p.simulated_meta) : p.simulated_meta) : {
            status: p.status,
            channel: p.payment_method,
            currency: p.currency || 'INR',
            signature: p.gateway_signature || 'Verified',
            created_at: p.created_at
        };
    } catch(e) {
        metaObj = { raw: p.simulated_meta };
    }
    document.getElementById('modalJsonMeta').innerText = JSON.stringify(metaObj, null, 2);
    inspectModalInstance.show();
}

function openRefundModal(paymentId, txnRef, amount, bookingRef) {
    document.getElementById('refundPaymentId').value = paymentId;
    document.getElementById('refundAmountText').innerText = '₹' + parseFloat(amount).toFixed(2);
    document.getElementById('refundBookingRef').innerText = bookingRef;
    refundModalInstance.show();
}

async function executeRefund() {
    const paymentId = parseInt(document.getElementById('refundPaymentId').value);
    const reason = document.getElementById('refundReasonInput').value.trim();
    const btn = document.getElementById('btnConfirmRefund');

    if (!paymentId) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

    try {
        const res = await ParkPulse.fetchJson('api/process-refund.php', {
            method: 'POST',
            body: JSON.stringify({
                payment_id: paymentId,
                reason: reason
            })
        });

        if (res.success) {
            ParkPulse.showToast('success', res.message, 'Refund Processed');
            refundModalInstance.hide();
            setTimeout(() => window.location.reload(), 1200);
        }
    } catch(err) {
        ParkPulse.showToast('danger', err.message, 'Refund Failed');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Confirm & Process Refund';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
