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
    SELECT t.id, t.name, t.commission_percentage,
           SUM(p.paid_amount) as total_collected,
           SUM(p.paid_amount * t.commission_percentage / 100) as teacher_share,
           SUM(p.paid_amount * (100 - t.commission_percentage) / 100) as institute_share
    FROM teachers t
    JOIN classes c ON c.teacher_id = t.id
    JOIN payments p ON p.class_id = c.id
    WHERE p.status IN ('completed','partial')
    AND p.payment_date BETWEEN ? AND ?
    GROUP BY t.id, t.name, t.commission_percentage
    ORDER BY total_collected DESC
");
$stmt->execute([$date_from, $date_to]);
$results = $stmt->fetchAll();

$grand_total = 0;
$grand_teacher = 0;
$grand_institute = 0;
foreach ($results as $row) {
    $grand_total += $row['total_collected'] ?? 0;
    $grand_teacher += $row['teacher_share'] ?? 0;
    $grand_institute += $row['institute_share'] ?? 0;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!verify_csrf($_GET['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="teacher_income_report.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Teacher Income Report']);
    fputcsv($output, ['Period', $date_from . ' to ' . $date_to]);
    fputcsv($output, []);
    fputcsv($output, ['Teacher', 'Commission %', 'Total Collected (Rs.)', 'Teacher Share (Rs.)', 'Institute Share (Rs.)']);
    foreach ($results as $row) {
        fputcsv($output, [
            $row['name'],
            $row['commission_percentage'] . '%',
            number_format($row['total_collected'] ?? 0, 2),
            number_format($row['teacher_share'] ?? 0, 2),
            number_format($row['institute_share'] ?? 0, 2)
        ]);
    }
    fputcsv($output, ['Grand Total', '', number_format($grand_total, 2), number_format($grand_teacher, 2), number_format($grand_institute, 2)]);
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card card-custom p-4 mb-4">
    <h4 class="mb-3">Teacher Income Report</h4>
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
<div class="card card-custom p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>Results</h5>
        <span class="text-muted"><?php echo formatDate($date_from); ?> - <?php echo formatDate($date_to); ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-custom">
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>Commission %</th>
                    <th>Total Collected</th>
                    <th>Teacher Share</th>
                    <th>Institute Share</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                <tr>
                    <td><?php echo sanitize($row['name']); ?></td>
                    <td><?php echo $row['commission_percentage']; ?>%</td>
                    <td><?php echo formatCurrency($row['total_collected'] ?? 0); ?></td>
                    <td><?php echo formatCurrency($row['teacher_share'] ?? 0); ?></td>
                    <td><?php echo formatCurrency($row['institute_share'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-active">
                    <td colspan="2"><strong>Grand Total</strong></td>
                    <td><strong><?php echo formatCurrency($grand_total); ?></strong></td>
                    <td><strong><?php echo formatCurrency($grand_teacher); ?></strong></td>
                    <td><strong><?php echo formatCurrency($grand_institute); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card card-custom p-4">
            <h5 class="mb-3">Income Distribution</h5>
            <canvas id="incomeChart" height="100"></canvas>
        </div>
    </div>
</div>

<script>
const incomeCtx = document.getElementById('incomeChart');
if (incomeCtx) {
    new Chart(incomeCtx, {
        type: 'pie',
        data: {
            labels: <?php echo json_encode(array_column($results, 'name')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map('floatval', array_column($results, 'total_collected'))); ?>,
                backgroundColor: [
                    '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8',
                    '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#343a40'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'right' },
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

