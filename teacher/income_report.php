<?php
requireRole('teacher');
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();
$user_id = $_SESSION['user_id'];
$page_title = 'Income Report';

$teacher = $pdo->prepare("SELECT id, name, commission_percentage FROM teachers WHERE user_id = ? AND status = 'active'");
$teacher->execute([$user_id]);
$teacher = $teacher->fetch();
$teacher_id = $teacher['id'] ?? 0;

if (!$teacher_id) {
    flashMessage('error', 'Teacher profile not found.');
    redirect('dashboard.php');
}

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

$income = calculateTeacherIncome($pdo, $teacher_id, $start_date, $end_date);

$classes = $pdo->prepare("SELECT c.id, c.name, c.monthly_fee FROM classes c WHERE c.teacher_id = ? AND c.status = 'active' ORDER BY c.name ASC");
$classes->execute([$teacher_id]);
$my_classes = $classes->fetchAll();

$class_income = [];
foreach ($my_classes as $class) {
    $class_id = $class['id'];
    $stmt = $pdo->prepare("SELECT SUM(p.paid_amount) as total_collected, SUM(p.paid_amount * (t.commission_percentage / 100)) as teacher_share, SUM(p.paid_amount * ((100 - t.commission_percentage) / 100)) as institute_share FROM payments p JOIN teachers t ON p.class_id IN (SELECT id FROM classes WHERE teacher_id = t.id) WHERE p.class_id = ? AND p.status = 'completed' AND p.payment_date >= ? AND p.payment_date <= ?");
    $stmt->execute([$class_id, $start_date, $end_date]);
    $row = $stmt->fetch();
    $class_income[] = [
        'id' => $class_id,
        'name' => $class['name'],
        'total' => $row['total_collected'] ?? 0,
        'teacher_share' => $row['teacher_share'] ?? 0,
        'institute_share' => $row['institute_share'] ?? 0,
    ];
}

$history = [];
$stmt = $pdo->prepare("
    SELECT MONTH(p.payment_date) as month, YEAR(p.payment_date) as year, SUM(p.paid_amount) as total, SUM(p.paid_amount * (t.commission_percentage / 100)) as teacher_share
    FROM payments p
    JOIN classes c ON p.class_id = c.id
    JOIN teachers t ON c.teacher_id = t.id
    WHERE c.teacher_id = ? AND p.status = 'completed' AND p.payment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(p.payment_date), MONTH(p.payment_date)
    ORDER BY p.payment_date ASC
");
$stmt->execute([$teacher_id]);
$history = $stmt->fetchAll();

logAudit($pdo, $user_id, 'view_income_report', 'Teacher viewed income report from ' . $start_date . ' to ' . $end_date);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-3">Income Report</h2>
        <form method="GET" class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Apply Filter</button>
            </div>
        </form>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card card-custom stat-card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1">Total Income</p>
                                <h3 class="mb-0"><?php echo formatCurrency($income['total_collected'] ?? 0); ?></h3>
                            </div>
                            <div class="fs-1 text-primary opacity-25"><i class="bi bi-currency-rupee"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom stat-card mb-3" style="border-left-color: #28a745;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1">Your Share (<?php echo $teacher['commission_percentage']; ?>%)</p>
                                <h3 class="mb-0 text-success"><?php echo formatCurrency($income['teacher_share'] ?? 0); ?></h3>
                            </div>
                            <div class="fs-1 text-success opacity-25"><i class="bi bi-graph-up"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom stat-card mb-3" style="border-left-color: #6c757d;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="text-muted mb-1">Institute Share (<?php echo 100 - $teacher['commission_percentage']; ?>%)</p>
                                <h3 class="mb-0 text-muted"><?php echo formatCurrency($income['institute_share'] ?? 0); ?></h3>
                            </div>
                            <div class="fs-1 text-muted opacity-25"><i class="bi bi-building"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="card card-custom mb-3">
                    <div class="card-header bg-white fw-semibold">Income History (Last 6 Months)</div>
                    <div class="card-body">
                        <canvas id="incomeHistoryChart" height="100"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card card-custom">
                    <div class="card-header bg-white fw-semibold">Per-Class Income Breakdown</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>Class</th>
                                        <th>Total Collected</th>
                                        <th>Your Share</th>
                                        <th>Institute Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($class_income)): ?>
                                        <tr><td colspan="4" class="text-center py-4">No income data for this period.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($class_income as $ci): ?>
                                            <tr>
                                                <td><?php echo sanitize($ci['name']); ?></td>
                                                <td><?php echo formatCurrency($ci['total']); ?></td>
                                                <td class="text-success fw-semibold"><?php echo formatCurrency($ci['teacher_share']); ?></td>
                                                <td class="text-muted"><?php echo formatCurrency($ci['institute_share']); ?></td>
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
    </div>
</div>

<?php if (!empty($history)): ?>
    <script>
        const ctx = document.getElementById('incomeHistoryChart').getContext('2d');
        const labels = <?php echo json_encode(array_map(function($h){ return date('M Y', mktime(0,0,0,$h['month'],1,$h['year'])); }, $history)); ?>;
        const data = <?php echo json_encode(array_map(function($h){ return (float)$h['teacher_share']; }, $history)); ?>;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Your Share (Rs.)',
                    data: data,
                    backgroundColor: 'rgba(40, 167, 69, 0.8)',
                            borderRadius: 5
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
