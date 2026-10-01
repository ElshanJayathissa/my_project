<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = db();
$csrf_token = csrf_token();

$students = $pdo->query("SELECT id, student_id, full_name FROM students WHERE status = 'active' ORDER BY full_name")->fetchAll();
$classes = $pdo->query("SELECT id, name FROM classes WHERE status = 'active' ORDER BY name")->fetchAll();
$collectors = $pdo->query("SELECT id, full_name FROM users WHERE role = 'collector' AND status = 'active' ORDER BY full_name")->fetchAll();

$student_filter = '';
$class_filter = '';
$collector_filter = '';
$date_from = date('Y-m-01');
$date_to = date('Y-m-t');
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
    } else {
        $student_filter = sanitize($_POST['student'] ?? '');
        $class_filter = sanitize($_POST['class'] ?? '');
        $collector_filter = sanitize($_POST['collector'] ?? '');
        $date_from = sanitize($_POST['date_from'] ?? $date_from);
        $date_to = sanitize($_POST['date_to'] ?? $date_to);
    }
}

$sql = "
    SELECT p.*, s.full_name, s.student_id, c.name as class_name, u.full_name as collector_name, pm.name as payment_method
    FROM payments p
    JOIN students s ON p.student_id = s.id
    JOIN classes c ON p.class_id = c.id
    LEFT JOIN users u ON p.collector_id = u.id
    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
    WHERE p.payment_date BETWEEN ? AND ?
";
$params = [$date_from, $date_to];

if ($student_filter !== '') {
    $sql .= " AND (s.full_name LIKE ? OR s.student_id LIKE ?)";
    $params[] = "%$student_filter%";
    $params[] = "%$student_filter%";
}
if ($class_filter !== '') {
    $sql .= " AND p.class_id = ?";
    $params[] = $class_filter;
}
if ($collector_filter !== '') {
    $sql .= " AND p.collector_id = ?";
    $params[] = $collector_filter;
}

$sql .= " ORDER BY p.payment_date DESC, p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!verify_csrf($_GET['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payment_history.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Receipt', 'Date', 'Student ID', 'Student Name', 'Class', 'Collector', 'Payment Method', 'Total Amount', 'Paid Amount', 'Status']);
    foreach ($results as $row) {
        fputcsv($output, [
            $row['receipt_number'],
            $row['payment_date'],
            $row['student_id'],
            $row['full_name'],
            $row['class_name'],
            $row['collector_name'] ?? 'N/A',
            $row['payment_method'] ?? 'N/A',
            number_format($row['total_amount'], 2),
            number_format($row['paid_amount'], 2),
            ucfirst($row['status'])
        ]);
    }
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card card-custom p-4 mb-4">
    <h4 class="mb-3">Payment History</h4>
    <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="col-md-3">
            <label class="form-label">Student</label>
            <input type="text" name="student" class="form-control" placeholder="Name or ID" value="<?php echo sanitize($student_filter); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Class</label>
            <select name="class" class="form-select">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $class_filter == $c['id'] ? 'selected' : ''; ?>>
                    <?php echo sanitize($c['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Collector</label>
            <select name="collector" class="form-select">
                <option value="">All Collectors</option>
                <?php foreach ($collectors as $col): ?>
                <option value="<?php echo $col['id']; ?>" <?php echo $collector_filter == $col['id'] ? 'selected' : ''; ?>>
                    <?php echo sanitize($col['full_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">From</label>
            <input type="date" name="date_from" class="form-control" value="<?php echo sanitize($date_from); ?>">
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <a href="?export=csv&csrf_token=<?php echo $csrf_token; ?>&student=<?php echo urlencode($student_filter); ?>&class=<?php echo urlencode($class_filter); ?>&collector=<?php echo urlencode($collector_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="btn btn-success w-100" title="Export CSV">
                <i class="bi bi-download"></i>
            </a>
        </div>
    </form>
</div>

<?php if (!empty($results)): ?>
<div class="card card-custom p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>Transactions</h5>
        <span class="text-muted"><?php echo count($results); ?> records found</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-custom">
            <thead>
                <tr>
                    <th>Receipt</th>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Collector</th>
                    <th>Method</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                <tr>
                    <td><?php echo sanitize($row['receipt_number']); ?></td>
                    <td><?php echo formatDate($row['payment_date']); ?></td>
                    <td><?php echo sanitize($row['full_name']); ?> (<?php echo sanitize($row['student_id']); ?>)</td>
                    <td><?php echo sanitize($row['class_name']); ?></td>
                    <td><?php echo sanitize($row['collector_name'] ?? 'N/A'); ?></td>
                    <td><?php echo sanitize($row['payment_method'] ?? 'N/A'); ?></td>
                    <td><?php echo formatCurrency($row['total_amount']); ?></td>
                    <td><?php echo formatCurrency($row['paid_amount']); ?></td>
                    <td>
                        <span class="badge bg-<?php echo $row['status'] == 'completed' ? 'success' : ($row['status'] == 'partial' ? 'warning' : 'secondary'); ?>">
                            <?php echo ucfirst($row['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info">No transactions found for the selected filters.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

