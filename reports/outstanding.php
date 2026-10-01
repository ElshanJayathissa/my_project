<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = db();
$csrf_token = csrf_token();

$results = [];
$total_outstanding = 0;

$stmt = $pdo->query("
    SELECT s.id, s.student_id, s.full_name, c.id as class_id, c.name as class_name,
           SUM(pi.amount_due - pi.amount_paid) as outstanding_amount
    FROM payment_items pi
    JOIN payments p ON pi.payment_id = p.id
    JOIN students s ON p.student_id = s.id
    JOIN classes c ON p.class_id = c.id
    WHERE pi.status IN ('unpaid','partial')
    GROUP BY p.student_id, p.class_id
    ORDER BY outstanding_amount DESC
");
$results = $stmt->fetchAll();
foreach ($results as $row) {
    $total_outstanding += $row['outstanding_amount'] ?? 0;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && verify_csrf($_GET['csrf_token'] ?? '')) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="outstanding_payments.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'Student Name', 'Class', 'Outstanding Amount (Rs.)']);
    foreach ($results as $row) {
        fputcsv($output, [$row['student_id'], $row['full_name'], $row['class_name'], number_format($row['outstanding_amount'] ?? 0, 2)]);
    }
    fputcsv($output, ['Total', '', '', number_format($total_outstanding, 2)]);
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card card-custom p-4 mb-3">
    <div class="d-flex justify-content-between align-items-center">
        <h4 class="mb-0">Outstanding Payments</h4>
        <a href="?export=csv&csrf_token=<?php echo $csrf_token; ?>" class="btn btn-success">
            <i class="bi bi-download me-1"></i>Export CSV
        </a>
    </div>
</div>

<?php if (!empty($results)): ?>
<div class="card card-custom p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>All Outstanding Payments</h5>
        <div class="text-muted">
            <strong>Total Outstanding: <?php echo formatCurrency($total_outstanding); ?></strong>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-custom">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Student Name</th>
                    <th>Class</th>
                    <th>Outstanding Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                <tr>
                    <td><?php echo sanitize($row['student_id']); ?></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td><?php echo sanitize($row['class_name']); ?></td>
                    <td><?php echo formatCurrency($row['outstanding_amount'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-active">
                    <td colspan="3"><strong>Total</strong></td>
                    <td><strong><?php echo formatCurrency($total_outstanding); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php else: ?>
<div class="alert alert-success">No outstanding payments found.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

