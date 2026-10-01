<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = db();

$total_students = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$total_classes = (int)$pdo->query("SELECT COUNT(*) FROM classes WHERE status = 'active'")->fetchColumn();
$total_teachers = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE status = 'active'")->fetchColumn();
$total_collectors = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'collector' AND status = 'active'")->fetchColumn();

$today_stats = $pdo->query("SELECT COUNT(*) as count, SUM(paid_amount) as total FROM payments WHERE payment_date = CURDATE() AND status IN ('completed','partial')")->fetch();
$today_count = (int)($today_stats['count'] ?? 0);
$today_total = $today_stats['total'] ?? 0;

$outstanding = $pdo->query("SELECT SUM(pi.amount_due - pi.amount_paid) as total FROM payment_items pi WHERE pi.status IN ('unpaid','partial')")->fetchColumn();
$total_outstanding = $outstanding ?? 0;

$chart_labels = [];
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('d M', strtotime($date));
    $stmt = $pdo->prepare("SELECT SUM(paid_amount) as total FROM payments WHERE payment_date = ? AND status IN ('completed','partial')");
    $stmt->execute([$date]);
    $chart_data[] = (float)($stmt->fetchColumn() ?? 0);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card card-custom stat-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="text-muted mb-1">Total Students</p>
                    <h3 class="mb-0"><?php echo number_format($total_students); ?></h3>
                </div>
                <i class="bi bi-people text-primary fs-1"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom stat-card p-3" style="border-left-color: #28a745;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="text-muted mb-1">Active Classes</p>
                    <h3 class="mb-0"><?php echo number_format($total_classes); ?></h3>
                </div>
                <i class="bi bi-book text-success fs-1"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom stat-card p-3" style="border-left-color: #ffc107;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="text-muted mb-1">Today's Collection</p>
                    <h3 class="mb-0"><?php echo formatCurrency($today_total); ?></h3>
                    <small class="text-muted"><?php echo $today_count; ?> transactions</small>
                </div>
                <i class="bi bi-cash text-warning fs-1"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom stat-card p-3" style="border-left-color: #dc3545;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="text-muted mb-1">Total Outstanding</p>
                    <h3 class="mb-0"><?php echo formatCurrency($total_outstanding); ?></h3>
                </div>
                <i class="bi bi-exclamation-triangle text-danger fs-1"></i>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card card-custom p-4">
            <h5 class="mb-3">Collection Trend (Last 7 Days)</h5>
            <canvas id="collectionChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card card-custom p-4">
            <h5 class="mb-3">Report Types</h5>
            <div class="list-group">
                <a href="daily.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-day me-2"></i>Daily Collection</span>
                    <i class="bi bi-chevron-right"></i>
                </a>
                <a href="monthly.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-month me-2"></i>Monthly Collection</span>
                    <i class="bi bi-chevron-right"></i>
                </a>
                <a href="outstanding.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-exclamation-circle me-2"></i>Outstanding Payments</span>
                    <i class="bi bi-chevron-right"></i>
                </a>
                <a href="payment_history.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-2"></i>Payment History</span>
                    <i class="bi bi-chevron-right"></i>
                </a>
                <a href="teacher_income.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-graph-up me-2"></i>Teacher Income</span>
                    <i class="bi bi-chevron-right"></i>
                </a>
                <a href="collector_report.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-person-badge me-2"></i>Collector Report</span>
                    <i class="bi bi-chevron-right"></i>
                </a>
                <a href="student_report.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2"></i>Student Report</span>
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
const ctx = document.getElementById('collectionChart');
if (ctx) {
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Collection (Rs.)',
                data: <?php echo json_encode($chart_data); ?>,
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

