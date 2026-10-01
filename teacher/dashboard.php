<?php
requireRole('teacher');
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();
$user_id = $_SESSION['user_id'];
$page_title = 'Dashboard';

$teacher = $pdo->prepare("SELECT id, name, commission_percentage FROM teachers WHERE user_id = ? AND status = 'active'")->execute([$user_id]);
$teacher = $pdo->fetch();
$teacher_id = $teacher['id'] ?? 0;

if (!$teacher_id) {
    flashMessage('error', 'Teacher profile not found.');
    redirect('my_classes.php');
}

$current_month = date('m');
$current_year = date('Y');

$classes = $pdo->prepare("SELECT c.id, c.name, c.monthly_fee FROM classes c WHERE c.teacher_id = ? AND c.status = 'active'");
$classes->execute([$teacher_id]);
$my_classes = $classes->fetchAll();

$class_ids = array_column($my_classes, 'id');
$total_students = 0;
if (!empty($class_ids)) {
    $in = implode(',', array_map('intval', $class_ids));
    $stmt = $pdo->query("SELECT COUNT(DISTINCT student_id) as count FROM class_enrollments WHERE class_id IN ($in) AND status = 'active'");
    $total_students = $stmt->fetchColumn();
}

$income = calculateTeacherIncome($pdo, $teacher_id, "$current_year-$current_month-01", "$current_year-$current_month-" . date('t', mktime(0, 0, 0, $current_month, 1, $current_year)));

$paid_count = 0;
$unpaid_count = 0;
$outstanding = 0;
if (!empty($class_ids)) {
    $in = implode(',', array_map('intval', $class_ids));
    $stmt = $pdo->query("SELECT p.status, SUM(pi.amount_due - pi.amount_paid) as bal FROM payments p JOIN payment_items pi ON p.id = pi.payment_id WHERE p.class_id IN ($in) AND p.status != 'cancelled' GROUP BY p.status");
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        if ($row['status'] === 'completed') {
            $paid_count++;
        } else {
            $unpaid_count++;
            $outstanding += $row['bal'];
        }
    }
}

$recent_payments = [];
if (!empty($class_ids)) {
    $in = implode(',', array_map('intval', $class_ids));
    $stmt = $pdo->query("SELECT p.id, p.paid_amount, p.payment_date, s.full_name, c.name as class_name FROM payments p JOIN students s ON p.student_id = s.id JOIN classes c ON p.class_id = c.id WHERE p.class_id IN ($in) AND p.status = 'completed' ORDER BY p.payment_date DESC LIMIT 10");
    $recent_payments = $stmt->fetchAll();
}

logAudit($pdo, $user_id, 'view_dashboard', 'Teacher viewed dashboard');
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card card-custom stat-card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">My Classes</p>
                        <h3 class="mb-0"><?php echo count($my_classes); ?></h3>
                    </div>
                    <div class="fs-1 text-primary opacity-25"><i class="bi bi-book"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom stat-card mb-3" style="border-left-color: #28a745;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Total Students</p>
                        <h3 class="mb-0"><?php echo $total_students; ?></h3>
                    </div>
                    <div class="fs-1 text-success opacity-25"><i class="bi bi-people"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom stat-card mb-3" style="border-left-color: #ffc107;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Paid / Unpaid</p>
                        <h3 class="mb-0"><?php echo $paid_count; ?> / <?php echo $unpaid_count; ?></h3>
                    </div>
                    <div class="fs-1 text-warning opacity-25"><i class="bi bi-check-circle"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-custom stat-card mb-3" style="border-left-color: #dc3545;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted mb-1">Outstanding</p>
                        <h3 class="mb-0"><?php echo formatCurrency($outstanding); ?></h3>
                    </div>
                    <div class="fs-1 text-danger opacity-25"><i class="bi bi-exclamation-triangle"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card card-custom mb-3">
            <div class="card-header bg-white fw-semibold">Income This Month</div>
            <div class="card-body text-center">
                <h2 class="text-success mb-1"><?php echo formatCurrency($income['total_collected'] ?? 0); ?></h2>
                <p class="text-muted mb-0">Your Share: <?php echo formatCurrency($income['teacher_share'] ?? 0); ?></p>
                <small class="text-muted">Institute: <?php echo formatCurrency($income['institute_share'] ?? 0); ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card card-custom mb-3">
            <div class="card-header bg-white fw-semibold">Recent Payments</div>
            <div class="card-body">
                <canvas id="recentPaymentsChart" height="120"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card card-custom">
            <div class="card-header bg-white fw-semibold">Recent Payments History</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_payments)): ?>
                                <tr><td colspan="4" class="text-center py-4">No payments yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_payments as $rp): ?>
                                    <tr>
                                        <td><?php echo formatDate($rp['payment_date']); ?></td>
                                        <td><?php echo sanitize($rp['full_name']); ?></td>
                                        <td><?php echo sanitize($rp['class_name']); ?></td>
                                        <td class="text-success fw-semibold"><?php echo formatCurrency($rp['paid_amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($recent_payments)): ?>
    <script>
        const ctx = document.getElementById('recentPaymentsChart').getContext('2d');
        const labels = <?php echo json_encode(array_reverse(array_column($recent_payments, 'payment_date'))); ?>;
        const data = <?php echo json_encode(array_reverse(array_map(function($p){ return (float)$p['paid_amount']; }, $recent_payments))); ?>;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Payments (Rs.)',
                    data: data,
                    borderColor: '#667eea',
                            backgroundColor: 'rgba(102,126,234,0.1)',
                            fill: true,
                            tension: 0.3
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
