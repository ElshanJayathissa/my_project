<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('collector');
$page_title = 'Collector Dashboard';
require_once __DIR__ . '/../includes/header.php';
$pdo = db();
$collector_id = $_SESSION['user_id'];
$today_stats = getTodayCollection($collector_id);
$recent_transactions = getRecentTransactions(10);

$stmt = $pdo->prepare("
    SELECT p.*, s.full_name, s.student_id, c.name as class_name
    FROM payments p
    JOIN students s ON p.student_id = s.id
    JOIN classes c ON p.class_id = c.id
    WHERE p.collector_id = ? AND p.payment_date = CURDATE()
    ORDER BY p.created_at DESC
");
$stmt->execute([$collector_id]);
$today_transactions = $stmt->fetchAll();
?>

<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <a href="scan.php" class="text-decoration-none">
            <div class="card card-custom stat-card bg-success p-4 text-white text-center" style="cursor:pointer;">
                <i class="bi bi-qr-code-scan icon"></i>
                <h4 class="mt-2">Scan Card</h4>
                <p class="mb-0">Scan QR to collect payment</p>
            </div>
        </a>
    </div>
    <div class="col-md-4 mb-3">
        <a href="search.php" class="text-decoration-none">
            <div class="card card-custom stat-card bg-primary p-4 text-white text-center" style="cursor:pointer;">
                <i class="bi bi-search icon"></i>
                <h4 class="mt-2">Search Student</h4>
                <p class="mb-0">Find by ID, name, or phone</p>
            </div>
        </a>
    </div>
    <div class="col-md-4 mb-3">
        <a href="collection_history.php" class="text-decoration-none">
            <div class="card card-custom stat-card bg-info p-4 text-white text-center" style="cursor:pointer;">
                <i class="bi bi-clock-history icon"></i>
                <h4 class="mt-2">View History</h4>
                <p class="mb-0">Today's collections</p>
            </div>
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <div class="card card-custom p-4">
            <h6 class="text-muted mb-1"><i class="bi bi-calendar-check me-2"></i>Today's Collection</h6>
            <h3 class="text-success fw-bold"><?php echo formatCurrency($today_stats['total'] ?? 0); ?></h3>
            <small class="text-muted"><?php echo $today_stats['count'] ?? 0; ?> payment(s) collected</small>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card card-custom p-4">
            <h6 class="text-muted mb-1"><i class="bi bi-receipt me-2"></i>Payment Count</h6>
            <h3 class="text-primary fw-bold"><?php echo $today_stats['count'] ?? 0; ?></h3>
            <small class="text-muted">Transactions today</small>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card card-custom p-4">
            <h5 class="mb-3"><i class="bi bi-lightning me-2"></i>Quick Search</h5>
            <form method="GET" action="search.php" class="d-flex gap-2">
                <input type="text" name="q" class="form-control" placeholder="Search by Student ID, Name, or Phone..." required>
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
            </form>
        </div>
    </div>
</div>

<?php
$reminders = getPaymentReminders($pdo);
?>

<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card card-custom p-3">
            <div class="d-flex align-items-center">
                <div class="fs-1 text-info me-3"><i class="bi bi-calendar-check"></i></div>
                <div>
                    <h6 class="mb-0">Upcoming</h6>
                    <h4 class="mb-0"><?php echo $reminders['upcoming']; ?></h4>
                    <small class="text-muted">Due within 7 days</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card card-custom p-3">
            <div class="d-flex align-items-center">
                <div class="fs-1 text-warning me-3"><i class="bi bi-clock"></i></div>
                <div>
                    <h6 class="mb-0">Pending</h6>
                    <h4 class="mb-0"><?php echo $reminders['pending']; ?></h4>
                    <small class="text-muted">Not yet paid</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card card-custom p-3">
            <div class="d-flex align-items-center">
                <div class="fs-1 text-danger me-3"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <h6 class="mb-0">Overdue</h6>
                    <h4 class="mb-0"><?php echo $reminders['overdue']; ?></h4>
                    <small class="text-muted">Past due date</small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$current_month = (int)date('n');
$current_year = (int)date('Y');
$payment_day = getMonthlyPaymentDay($pdo);
$due_date = getPaymentDueDate($current_year, $current_month, $payment_day);
$today = date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT s.id, s.full_name, s.student_id, c.name as class_name, c.monthly_fee, pi.amount_due, pi.amount_paid
    FROM payment_items pi
    JOIN payments p ON pi.payment_id = p.id
    JOIN students s ON p.student_id = s.id
    JOIN classes c ON p.class_id = c.id
    WHERE pi.billing_month = ? AND pi.billing_year = ? AND pi.status != 'paid'
    ORDER BY s.full_name ASC
    LIMIT 20
");
$stmt->execute([$current_month, $current_year]);
$pending_students = $stmt->fetchAll();

foreach ($pending_students as &$ps) {
    $due_datetime = new DateTime($due_date);
    $today_datetime = new DateTime($today);
    $diff = $today_datetime->diff($due_datetime);
    
    if ($today > $due_date) {
        $ps['days_text'] = $diff->days . ' days overdue';
    } else {
        $ps['days_text'] = $diff->days . ' days left';
    }
}
unset($ps);
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Pending This Month (<?php echo date('F Y'); ?>)</h5>
                <span class="badge bg-warning text-dark"><?php echo count($pending_students); ?> Pending</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($pending_students)): ?>
                    <div class="p-4 text-center text-success">
                        <i class="bi bi-check-circle fs-1 d-block mb-2"></i>
                        All payments collected for this month!
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Monthly Fee</th>
                                    <th>Days</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_students as $ps): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo sanitize($ps['full_name']); ?><br><small class="text-muted"><?php echo sanitize($ps['student_id']); ?></small></td>
                                        <td><?php echo sanitize($ps['class_name']); ?></td>
                                        <td><?php echo formatCurrency($ps['monthly_fee']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo strpos($ps['days_text'], 'overdue') !== false ? 'danger' : 'warning'; ?>">
                                                <?php echo htmlspecialchars($ps['days_text']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="payment.php?student_id=<?php echo $ps['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-credit-card me-1"></i>Collect
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Today's Transactions</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($today_transactions)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        No payments collected today yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($today_transactions as $tx): ?>
                                    <tr>
                                        <td><?php echo date('h:i A', strtotime($tx['created_at'])); ?></td>
                                        <td><?php echo sanitize($tx['full_name']); ?><br><small class="text-muted"><?php echo sanitize($tx['student_id']); ?></small></td>
                                        <td><?php echo sanitize($tx['class_name']); ?></td>
                                        <td class="fw-bold text-success"><?php echo formatCurrency($tx['paid_amount']); ?></td>
                                        <td><span class="badge bg-<?php echo $tx['status'] === 'completed' ? 'success' : ($tx['status'] === 'partial' ? 'warning' : 'secondary'); ?>"><?php echo ucfirst($tx['status']); ?></span></td>
                                        <td><?php echo sanitize($tx['receipt_number'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <div class="card card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Recent Transactions (All)</h5>
                <a href="collection_history.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recent_transactions)): ?>
                    <div class="p-4 text-center text-muted">No transactions found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Collector</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_transactions as $tx): ?>
                                    <tr>
                                        <td><?php echo formatDate($tx['payment_date']); ?></td>
                                        <td><?php echo sanitize($tx['full_name']); ?><br><small class="text-muted"><?php echo sanitize($tx['student_id']); ?></small></td>
                                        <td><?php echo sanitize($tx['class_name']); ?></td>
                                        <td class="fw-bold"><?php echo formatCurrency($tx['paid_amount']); ?></td>
                                        <td><span class="badge bg-<?php echo $tx['status'] === 'completed' ? 'success' : ($tx['status'] === 'partial' ? 'warning' : 'secondary'); ?>"><?php echo ucfirst($tx['status']); ?></span></td>
                                        <td><?php echo sanitize($tx['collector_name'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

