<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = db();
$csrf_token = csrf_token();

$months = getBillingMonths();
$selected_month = (int)date('m');
$selected_year = (int)date('Y');
$class_results = [];
$teacher_results = [];
$class_total = 0;
$teacher_total = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
    } else {
        $selected_month = (int)$_POST['month'];
        $selected_year = (int)$_POST['year'];
    }
}

$stmt = $pdo->prepare("
    SELECT c.id, c.name, SUM(p.paid_amount) as total
    FROM payments p
    JOIN classes c ON p.class_id = c.id
    WHERE MONTH(p.payment_date) = ? AND YEAR(p.payment_date) = ? AND p.status IN ('completed','partial')
    GROUP BY c.id, c.name
    ORDER BY total DESC
");
$stmt->execute([$selected_month, $selected_year]);
$class_results = $stmt->fetchAll();
foreach ($class_results as $row) {
    $class_total += $row['total'] ?? 0;
}

$stmt = $pdo->prepare("
    SELECT t.id, t.name, SUM(p.paid_amount) as total
    FROM payments p
    JOIN classes c ON p.class_id = c.id
    JOIN teachers t ON c.teacher_id = t.id
    WHERE MONTH(p.payment_date) = ? AND YEAR(p.payment_date) = ? AND p.status IN ('completed','partial')
    GROUP BY t.id, t.name
    ORDER BY total DESC
");
$stmt->execute([$selected_month, $selected_year]);
$teacher_results = $stmt->fetchAll();
foreach ($teacher_results as $row) {
    $teacher_total += $row['total'] ?? 0;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!verify_csrf($_GET['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $export_month = (int)($_GET['month'] ?? $selected_month);
    $export_year = (int)($_GET['year'] ?? $selected_year);
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, SUM(p.paid_amount) as total
        FROM payments p
        JOIN classes c ON p.class_id = c.id
        WHERE MONTH(p.payment_date) = ? AND YEAR(p.payment_date) = ? AND p.status IN ('completed','partial')
        GROUP BY c.id, c.name
        ORDER BY total DESC
    ");
    $stmt->execute([$export_month, $export_year]);
    $export_class = $stmt->fetchAll();
    $export_class_total = 0;
    foreach ($export_class as $row) {
        $export_class_total += $row['total'] ?? 0;
    }
    $stmt = $pdo->prepare("
        SELECT t.id, t.name, SUM(p.paid_amount) as total
        FROM payments p
        JOIN classes c ON p.class_id = c.id
        JOIN teachers t ON c.teacher_id = t.id
        WHERE MONTH(p.payment_date) = ? AND YEAR(p.payment_date) = ? AND p.status IN ('completed','partial')
        GROUP BY t.id, t.name
        ORDER BY total DESC
    ");
    $stmt->execute([$export_month, $export_year]);
    $export_teacher = $stmt->fetchAll();
    $export_teacher_total = 0;
    foreach ($export_teacher as $row) {
        $export_teacher_total += $row['total'] ?? 0;
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="monthly_report_' . $export_year . '_' . str_pad($export_month, 2, '0', STR_PAD_LEFT) . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Monthly Collection Report - ' . $months[$export_month] . ' ' . $export_year]);
    fputcsv($output, []);
    fputcsv($output, ['Class-wise Breakdown']);
    fputcsv($output, ['Class', 'Total Collection (Rs.)']);
    foreach ($export_class as $row) {
        fputcsv($output, [$row['name'], number_format($row['total'] ?? 0, 2)]);
    }
    fputcsv($output, ['Total', number_format($export_class_total, 2)]);
    fputcsv($output, []);
    fputcsv($output, ['Teacher-wise Breakdown']);
    fputcsv($output, ['Teacher', 'Total Collection (Rs.)']);
    foreach ($export_teacher as $row) {
        fputcsv($output, [$row['name'], number_format($row['total'] ?? 0, 2)]);
    }
    fputcsv($output, ['Total', number_format($export_teacher_total, 2)]);
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card card-custom p-4 mb-4">
    <h4 class="mb-3">Monthly Collection Report</h4>
    <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="col-md-4">
            <label class="form-label">Month</label>
            <select name="month" class="form-select">
                <?php foreach ($months as $num => $name): ?>
                <option value="<?php echo $num; ?>" <?php echo $selected_month == $num ? 'selected' : ''; ?>>
                    <?php echo $name; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Year</label>
            <select name="year" class="form-select">
                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $selected_year == $y ? 'selected' : ''; ?>>
                    <?php echo $y; ?>
                </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <a href="?export=csv&csrf_token=<?php echo $csrf_token; ?>&month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" class="btn btn-success w-100">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
    </form>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card card-custom p-4">
            <h5 class="mb-3">Class-wise Breakdown</h5>
            <div class="table-responsive">
                <table class="table table-hover table-custom">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Total Collection</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($class_results as $row): ?>
                        <tr>
                            <td><?php echo sanitize($row['name']); ?></td>
                            <td><?php echo formatCurrency($row['total'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-active">
                            <td><strong>Total</strong></td>
                            <td><strong><?php echo formatCurrency($class_total); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card card-custom p-4">
            <h5 class="mb-3">Teacher-wise Breakdown</h5>
            <div class="table-responsive">
                <table class="table table-hover table-custom">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Total Collection</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teacher_results as $row): ?>
                        <tr>
                            <td><?php echo sanitize($row['name']); ?></td>
                            <td><?php echo formatCurrency($row['total'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-active">
                            <td><strong>Total</strong></td>
                            <td><strong><?php echo formatCurrency($teacher_total); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($class_results) || !empty($teacher_results)): ?>
<div class="row">
    <div class="col-md-6">
        <div class="card card-custom p-4">
            <h5 class="mb-3">Class Collection Chart</h5>
            <canvas id="classChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card card-custom p-4">
            <h5 class="mb-3">Teacher Collection Chart</h5>
            <canvas id="teacherChart" height="200"></canvas>
        </div>
    </div>
</div>

<script>
const classCtx = document.getElementById('classChart');
if (classCtx) {
    new Chart(classCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($class_results, 'name')); ?>,
            datasets: [{
                label: 'Collection (Rs.)',
                data: <?php echo json_encode(array_map('floatval', array_column($class_results, 'total'))); ?>,
                backgroundColor: '#667eea'
            }]
        },
        options: {
            responsive: true,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true } }
        }
    });
}
const teacherCtx = document.getElementById('teacherChart');
if (teacherCtx) {
    new Chart(teacherCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($teacher_results, 'name')); ?>,
            datasets: [{
                label: 'Collection (Rs.)',
                data: <?php echo json_encode(array_map('floatval', array_column($teacher_results, 'total'))); ?>,
                backgroundColor: '#28a745'
            }]
        },
        options: {
            responsive: true,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true } }
        }
    });
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

