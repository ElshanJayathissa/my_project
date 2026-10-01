<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('collector');
$page_title = 'Collection History';
require_once __DIR__ . '/../includes/header.php';
$pdo = db();
$collector_id = $_SESSION['user_id'];

$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT p.*, s.full_name, s.student_id, c.name as class_name, pm.name as method_name,
           u.full_name as collector_name
    FROM payments p
    JOIN students s ON p.student_id = s.id
    JOIN classes c ON p.class_id = c.id
    LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
    LEFT JOIN users u ON p.collector_id = u.id
    WHERE p.collector_id = ? AND p.payment_date BETWEEN ? AND ? AND p.status != 'cancelled'
    ORDER BY p.payment_date DESC, p.created_at DESC
");
$stmt->execute([$collector_id, $start_date, $end_date]);
$payments = $stmt->fetchAll();

$total_collected = 0;
foreach ($payments as $p) {
    $total_collected += $p['paid_amount'];
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'collection_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Receipt No', 'Student ID', 'Student Name', 'Class', 'Method', 'Amount', 'Status', 'Notes']);
    foreach ($payments as $p) {
        fputcsv($output, [
            $p['payment_date'],
            $p['receipt_number'],
            $p['student_id'],
            $p['full_name'],
            $p['class_name'],
            $p['method_name'] ?? 'N/A',
            $p['paid_amount'],
            ucfirst($p['status']),
            $p['notes'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

$today_stmt = $pdo->prepare("
    SELECT COUNT(*) as count, SUM(paid_amount) as total
    FROM payments
    WHERE collector_id = ? AND payment_date = CURDATE() AND status = 'completed'
");
$today_stmt->execute([$collector_id]);
$today = $today_stmt->fetch();
?>

<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <div class="card card-custom p-4">
            <h6 class="text-muted mb-1"><i class="bi bi-calendar-check me-2"></i>Today's Collection</h6>
            <h3 class="text-success fw-bold"><?php echo formatCurrency($today['total'] ?? 0); ?></h3>
            <small class="text-muted"><?php echo $today['count'] ?? 0; ?> payment(s)</small>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card card-custom p-4">
            <h6 class="text-muted mb-1"><i class="bi bi-calendar-range me-2"></i>Period Total</h6>
            <h3 class="text-primary fw-bold"><?php echo formatCurrency($total_collected); ?></h3>
            <small class="text-muted"><?php echo count($payments); ?> payment(s) from <?php echo formatDate($start_date); ?> to <?php echo formatDate($end_date); ?></small>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filter by Date</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">From</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo sanitize($start_date); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">To</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo sanitize($end_date); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-2"></i>Apply Filter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Payments (<?php echo count($payments); ?>)</h5>
                <a href="?start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&export=csv" class="btn btn-sm btn-success">
                    <i class="bi bi-download me-1"></i>Export CSV
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($payments)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        No payments found for the selected period.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt No</th>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Method</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td><?php echo formatDate($p['payment_date']); ?></td>
                                        <td class="fw-bold"><?php echo sanitize($p['receipt_number']); ?></td>
                                        <td><?php echo sanitize($p['full_name']); ?><br><small class="text-muted"><?php echo sanitize($p['student_id']); ?></small></td>
                                        <td><?php echo sanitize($p['class_name']); ?></td>
                                        <td><?php echo sanitize($p['method_name'] ?? 'N/A'); ?></td>
                                        <td class="fw-bold text-success"><?php echo formatCurrency($p['paid_amount']); ?></td>
                                        <td><span class="badge bg-<?php echo $p['status'] === 'completed' ? 'success' : ($p['status'] === 'partial' ? 'warning' : 'secondary'); ?>"><?php echo ucfirst($p['status']); ?></span></td>
                                        <td><small><?php echo sanitize($p['notes'] ?? '-'); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-active">
                                    <td colspan="5" class="text-end fw-bold">Total:</td>
                                    <td class="fw-bold text-success"><?php echo formatCurrency($total_collected); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

