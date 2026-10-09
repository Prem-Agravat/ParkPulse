<?php
/**
 * Administrator Security & Operational Audit Log Explorer
 * Smart Parking Reservation & Management System (Code2Web 2026)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
Auth::requireRole('admin');

$db = Database::getInstance()->getConnection();

$actionFilter = Security::sanitizeString($_GET['action'] ?? '');
$search = Security::sanitizeString($_GET['q'] ?? '');

$sql = "SELECT a.*, u.name AS user_name, u.email AS user_email, u.role AS user_role 
        FROM audit_logs a 
        LEFT JOIN users u ON a.user_id = u.id";

$params = [];
$conditions = [];

if (!empty($actionFilter)) {
    $conditions[] = "a.action = ?";
    $params[] = $actionFilter;
}

if (!empty($search)) {
    $conditions[] = "(a.details LIKE ? OR a.ip_address LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY a.created_at DESC LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get distinct action types for filter dropdown
$distinctActions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Security & Audit Logs';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-lg-5 px-3 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 text-dark">Security & Operational Audit Log</h1>
            <p class="text-secondary small mb-0">Immutable record of authentication, gate barrier activities, slot updates & administrative changes</p>
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="card card-custom border-0 p-3 shadow-sm mb-4">
        <form method="GET" action="admin-audit.php">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Search details, IP address or user name..." value="<?= Security::escape($search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="action" class="form-select form-select-sm">
                        <option value="">-- All Audit Action Types --</option>
                        <?php foreach ($distinctActions as $act): ?>
                            <option value="<?= Security::escape($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>>
                                <?= Security::escape($act) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-dark-custom btn-sm flex-grow-1">Filter</button>
                    <a href="admin-audit.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Audit Logs Table -->
    <div class="card card-custom border-0 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-custom mb-0">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Action</th>
                        <th>User / Actor</th>
                        <th>Target Entity</th>
                        <th>Event Details</th>
                        <th>IP & Client</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-secondary">No audit logs matching query.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark small"><?= date('M d, Y', strtotime($l['created_at'])) ?></div>
                                    <div class="text-muted font-monospace" style="font-size: 0.75rem;"><?= date('H:i:s', strtotime($l['created_at'])) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-dark font-monospace" style="font-size: 0.72rem;">
                                        <?= Security::escape($l['action']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($l['user_name'])): ?>
                                        <div class="fw-semibold text-dark small"><?= Security::escape($l['user_name']) ?></div>
                                        <small class="badge bg-light text-secondary border"><?= Security::escape($l['user_role'] ?? 'user') ?></small>
                                    <?php else: ?>
                                        <span class="text-muted small">System / Guest</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="font-monospace text-secondary small"><?= Security::escape($l['entity_type']) ?> #<?= (int)$l['entity_id'] ?></span>
                                </td>
                                <td>
                                    <div class="small text-dark" style="max-width: 380px; line-height: 1.35;"><?= Security::escape($l['details']) ?></div>
                                </td>
                                <td>
                                    <span class="font-monospace text-secondary small d-block"><?= Security::escape($l['ip_address']) ?></span>
                                    <small class="text-muted text-truncate d-block" style="max-width: 160px;" title="<?= Security::escape($l['user_agent'] ?? '') ?>">
                                        <?= Security::escape($l['user_agent'] ?? 'N/A') ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
