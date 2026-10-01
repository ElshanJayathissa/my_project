<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = db();
$csrf_token = csrf_token();

$selected_date = date('Y-m-d');
$results = [];
$total_collection = 0;
$total_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
    } else {
        $selected_date = sanitize($_POST['date'] ?? date('Y-m-d'));
    }
}

$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, COUNT(p.id) as transaction_count, SUM(p.paid_amount) as total
    FROM users u
    LEFT JOIN payments p ON p.collector_id = u.id
        AND p.payment_date = ?
        AND p.status IN ('completed','partial')
    WHERE u.role = 'collector' AND u.status = 'active'
    GROUP BY u.id, u.full_name
    ORDER BY total DESC
");
$stmt->execute([$selected_date]);
$results = $stmt->fetchAll();
foreach ($results as $row) {
    $total_collection += $row['total'] ?? 0;
    $total_count += $row['transaction_count'];
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!verify_csrf($_GET['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $export_date = sanitize($_GET['date'] ?? $selected_date);
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, COUNT(p.id) as transaction_count, SUM(p.paid_amount) as total
        FROM users u
        LEFT JOIN payments p ON p.collector_id = u.id
            AND p.payment_date = ?
            AND p.status IN ('completed','partial')
        WHERE u.role = 'collector' AND u.status = 'active'
        GROUP BY u.id, u.full_name
        ORDER BY total DESC
    ");
    $stmt->execute([$export_date]);
    $export_results = $stmt->fetchAll();
    $export_total = 0;
    $export_count = 0;
    foreach ($export_results as $row) {
        $export_total += $row['total'] ?? 0;
        $export_count += $row['transaction_count'];
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="daily_collection_' . $export_date . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Collector', 'Transactions', 'Total Collection (Rs.)']);
    foreach ($export_results as $row) {
        fputcsv($output, [$row['full_name'] ?? 'Unknown', $row['transaction_count'], number_format($row['total'] ?? 0, 2)]);
    }
    fputcsv($output, ['Total', $export_count, number_format($export_total, 2)]);
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card card-custom p-4 mb-4">
    <h4 class="mb-3">Daily Collection Report</h4>
    <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="col-md-4">
            <label class="form-label">Date</label>
            <input type="date" name="date" class="form-control" value="<?php echo sanitize($selected_date); ?>" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <a href="?export=csv&csrf_token=<?php echo $csrf_token; ?>&date=<?php echo urlencode($selected_date); ?>" class="btn btn-success w-100">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
    </form>
</div>

<?php if (!empty($results)): ?>
<div class="card card-custom p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>Results for <?php echo formatDate($selected_date); ?></h5>
        <div class="text-muted">
            <strong>Total: <?php echo formatCurrency($total_collection); ?></strong> 
            <span class="ms-3">Transactions: <?php echo $total_count; ?></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-custom">
            <thead>
                <tr>
                    <th>Collector</th>
                    <th>Transactions</th>
                    <th>Total Collection</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                <tr>
                    <td><?php echo sanitize($row['full_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo $row['transaction_count']; ?></td>
                    <td><?php echo formatCurrency($row['total'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-active">
                    <td><strong>Total</strong></td>
                    <td><strong><?php echo $total_count; ?></strong></td>
                    <td><strong><?php echo formatCurrency($total_collection); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

