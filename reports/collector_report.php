<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = db();
$csrf_token = csrf_token();

$date_from = date('Y-m-01');
$date_to = date('Y-m-t');
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
    } else {
        $date_from = sanitize($_POST['date_from'] ?? $date_from);
        $date_to = sanitize($_POST['date_to'] ?? $date_to);
    }
}

$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, COUNT(p.id) as count, SUM(p.paid_amount) as total
    FROM users u
    LEFT JOIN payments p ON p.collector_id = u.id
        AND p.status IN ('completed','partial')
        AND p.payment_date BETWEEN ? AND ?
    WHERE u.role = 'collector' AND u.status = 'active'
    GROUP BY u.id, u.full_name
    ORDER BY total DESC
");
$stmt->execute([$date_from, $date_to]);
$results = $stmt->fetchAll();

$grand_total = 0;
$grand_count = 0;
foreach ($results as $row) {
    $grand_total += $row['total'] ?? 0;
    $grand_count += $row['count'];
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!verify_csrf($_GET['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="collector_report.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Collector Report', $date_from . ' to ' . $date_to]);
    fputcsv($output, []);
    fputcsv($output, ['Collector', 'Transactions', 'Total Collection (Rs.)']);
    foreach ($results as $row) {
        fputcsv($output, [$row['full_name'], $row['count'], number_format($row['total'] ?? 0, 2)]);
    }
    fputcsv($output, ['Grand Total', $grand_count, number_format($grand_total, 2)]);
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card card-custom p-4 mb-4">
    <h4 class="mb-3">Collector Collection Report</h4>
    <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="col-md-3">
            <label class="form-label">Date From</label>
            <input type="date" name="date_from" class="form-control" value="<?php echo sanitize($date_from); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Date To</label>
            <input type="date" name="date_to" class="form-control" value="<?php echo sanitize($date_to); ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <a href="?export=csv&csrf_token=<?php echo $csrf_token; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="btn btn-success w-100">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
    </form>
</div>

<?php if (!empty($results)): ?>
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card card-custom p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5>Results</h5>
                <span class="text-muted"><?php echo formatDate($date_from); ?> - <?php echo formatDate($date_to); ?></span>
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
                            <td><?php echo sanitize($row['full_name']); ?></td>
                            <td><?php echo $row['count']; ?></td>
                            <td><?php echo formatCurrency($row['total'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-active">
                            <td><strong>Grand Total</strong></td>
                            <td><strong><?php echo $grand_count; ?></strong></td>
                            <td><strong><?php echo formatCurrency($grand_total); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-custom p-4">
            <h5 class="mb-3">Collection Chart</h5>
            <canvas id="collectorChart" height="250"></canvas>
        </div>
    </div>
</div>

<script>
const collectorCtx = document.getElementById('collectorChart');
if (collectorCtx) {
    new Chart(collectorCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($results, 'full_name')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map('floatval', array_column($results, 'total'))); ?>,
                backgroundColor: [
                    '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8',
                    '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#343a40'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': Rs. ' + context.raw.toFixed(2);
                        }
                    }
                }
            }
        }
    });
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

