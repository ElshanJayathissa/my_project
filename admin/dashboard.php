<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'Dashboard';
$pdo = db();
$user_id = $_SESSION['user_id'];

$total_students = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
$total_classes = $pdo->query("SELECT COUNT(*) FROM classes WHERE status = 'active'")->fetchColumn();
$total_teachers = $pdo->query("SELECT COUNT(*) FROM teachers WHERE status = 'active'")->fetchColumn();
$today_collection = getTodayCollection();
$today_total = $today_collection['total'] ?? 0;
$today_count = $today_collection['count'] ?? 0;

$outstanding = $pdo->query("
    SELECT SUM(pi.amount_due - pi.amount_paid) as total_outstanding,
           COUNT(DISTINCT p.student_id) as students_with_outstanding
    FROM payment_items pi
    JOIN payments p ON pi.payment_id = p.id
    WHERE pi.status != 'paid'
")->fetch();

$reminders = getPaymentReminders($pdo);

$recent_payments = getRecentTransactions(10);

$monthly_data = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('m', strtotime("-$i months"));
    $year = date('Y', strtotime("-$i months"));
    $stmt = $pdo->prepare("
        SELECT SUM(paid_amount) as total, COUNT(*) as count
        FROM payments
        WHERE MONTH(payment_date) = ? AND YEAR(payment_date) = ? AND status = 'completed'
    ");
    $stmt->execute([$month, $year]);
    $row = $stmt->fetch();
    $monthly_data[] = [
        'label' => date('M Y', strtotime("-$i months")),
        'total' => $row['total'] ?? 0,
        'count' => $row['count'] ?? 0
    ];
}

include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card card-custom stat-card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Total Students</p>
                        <h3 class="mb-0"><?php echo $total_students; ?></h3>
                    </div>
                    <div class="fs-1 text-primary opacity-25"><i class="bi bi-people"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom stat-card mb-3" style="border-left-color: #28a745;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Total Classes</p>
                        <h3 class="mb-0"><?php echo $total_classes; ?></h3>
                    </div>
                    <div class="fs-1 text-success opacity-25"><i class="bi bi-book"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom stat-card mb-3" style="border-left-color: #ffc107;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Total Teachers</p>
                        <h3 class="mb-0"><?php echo $total_teachers; ?></h3>
                    </div>
                    <div class="fs-1 text-warning opacity-25"><i class="bi bi-person-badge"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom stat-card mb-3" style="border-left-color: #17a2b8;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Today's Collection</p>
                        <h3 class="mb-0"><?php echo formatCurrency($today_total); ?></h3>
                        <small class="text-muted"><?php echo $today_count; ?> transactions</small>
                    </div>
                    <div class="fs-1 text-info opacity-25"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card card-custom h-100">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Outstanding Payments</h5>
            </div>
            <div class="card-body">
                <div class="text-center py-4">
                    <h2 class="text-warning"><?php echo formatCurrency($outstanding['total_outstanding'] ?? 0); ?></h2>
                    <p class="text-muted"><?php echo $outstanding['students_with_outstanding'] ?? 0; ?> students have outstanding balances</p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card card-custom h-100">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0"><i class="bi bi-graph-up me-2 text-primary"></i>Monthly Collection Trend</h5>
            </div>
            <div class="card-body">
                <canvas id="collectionChart" height="120"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card card-custom">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0"><i class="bi bi-bell me-2 text-warning"></i>Payment Reminders</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="card border-0 bg-light">
                            <div class="card-body text-center">
                                <i class="bi bi-calendar-check fs-1 text-info"></i>
                                <h4 class="mt-2"><?php echo $reminders['upcoming']; ?></h4>
                                <p class="text-muted mb-0">Upcoming Payments<br><small>Due within 7 days</small></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card border-0 bg-light">
                            <div class="card-body text-center">
                                <i class="bi bi-clock fs-1 text-warning"></i>
                                <h4 class="mt-2"><?php echo $reminders['pending']; ?></h4>
                                <p class="text-muted mb-0">Pending Payments<br><small>Not yet paid this month</small></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card border-0 bg-light">
                            <div class="card-body text-center">
                                <i class="bi bi-exclamation-triangle fs-1 text-danger"></i>
                                <h4 class="mt-2"><?php echo $reminders['overdue']; ?></h4>
                                <p class="text-muted mb-0">Overdue Payments<br><small>Past due date</small></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-2">
                    <small class="text-muted">Payment due date: <strong><?php echo formatDate($reminders['due_date']); ?></strong></small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card card-custom">
    <div class="card-header bg-white border-0 py-3">
        <h5 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Recent Payments</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-custom mb-0">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_payments as $payment): ?>
                        <tr>
                            <td><?php echo sanitize($payment['receipt_number']); ?></td>
                            <td><?php echo sanitize($payment['full_name']); ?><br><small class="text-muted"><?php echo sanitize($payment['student_id']); ?></small></td>
                            <td><?php echo sanitize($payment['class_name']); ?></td>
                            <td><strong><?php echo formatCurrency($payment['paid_amount']); ?></strong></td>
                            <td><?php echo formatDate($payment['payment_date']); ?></td>
                            <td>
                                <?php
                                $badge_class = [
                                    'completed' => 'success',
                                    'pending' => 'warning',
                                    'partial' => 'info',
                                    'refunded' => 'danger',
                                    'cancelled' => 'secondary'
                                ][$payment['status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?php echo $badge_class; ?>"><?php echo ucfirst($payment['status']); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recent_payments)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No payments found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const ctx = document.getElementById('collectionChart').getContext('2d');
const labels = <?php echo json_encode(array_column($monthly_data, 'label')); ?>;
const data = <?php echo json_encode(array_column($monthly_data, 'total')); ?>;
new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Collection (Rs.)',
            data: data,
            borderColor: '#667eea',
            backgroundColor: 'rgba(102,126,234,0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#667eea'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: function(v) { return 'Rs. ' + v; } } },
            x: { ticks: { maxRotation: 45 } }
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>

