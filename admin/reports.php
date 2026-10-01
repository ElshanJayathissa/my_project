<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'Reports';
$pdo = db();
$user_id = $_SESSION['user_id'];

$report_type = $_GET['type'] ?? 'daily';
$export = $_GET['export'] ?? '';

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$class_filter = $_GET['class'] ?? '';
$teacher_filter = $_GET['teacher'] ?? '';

$classes = $pdo->query("SELECT id, name FROM classes WHERE status = 'active' ORDER BY name")->fetchAll();
$teachers_list = $pdo->query("SELECT id, name FROM teachers WHERE status = 'active' ORDER BY name")->fetchAll();

$daily_report = [];
$monthly_report = [];
$outstanding_report = [];
$teacher_income_report = [];

if ($report_type === 'daily' || $export === 'daily') {
    $query = "SELECT p.payment_date, p.class_id, c.name as class_name, SUM(p.paid_amount) as total, COUNT(*) as count FROM payments p JOIN classes c ON p.class_id = c.id WHERE p.payment_date >= ? AND p.payment_date <= ? AND p.status = 'completed'";
    $params = [$date_from, $date_to];
    if ($class_filter) { $query .= " AND p.class_id = ?"; $params[] = $class_filter; }
    $query .= " GROUP BY p.payment_date, p.class_id ORDER BY p.payment_date DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $daily_report = $stmt->fetchAll();
}

if ($report_type === 'monthly' || $export === 'monthly') {
    $query = "SELECT MONTH(p.payment_date) as month, YEAR(p.payment_date) as year, SUM(p.paid_amount) as total, COUNT(*) as count FROM payments p WHERE p.payment_date >= ? AND p.payment_date <= ? AND p.status = 'completed'";
    $params = [$date_from, $date_to];
    if ($class_filter) { $query .= " AND p.class_id = ?"; $params[] = $class_filter; }
    $query .= " GROUP BY YEAR(p.payment_date), MONTH(p.payment_date) ORDER BY year DESC, month DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $monthly_report = $stmt->fetchAll();
}

if ($report_type === 'outstanding' || $export === 'outstanding') {
    $query = "SELECT s.id, s.full_name, s.student_id, c.name as class_name, SUM(pi.amount_due - pi.amount_paid) as outstanding FROM payment_items pi JOIN payments p ON pi.payment_id = p.id JOIN students s ON p.student_id = s.id JOIN classes c ON p.class_id = c.id WHERE pi.status != 'paid'";
    $params = [];
    if ($class_filter) { $query .= " AND p.class_id = ?"; $params[] = $class_filter; }
    $query .= " GROUP BY s.id, c.id ORDER BY outstanding DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $outstanding_report = $stmt->fetchAll();
}

if ($report_type === 'teacher_income' || $export === 'teacher_income') {
    $query = "SELECT t.id, t.name, t.commission_percentage, SUM(p.paid_amount) as total_collected, SUM(p.paid_amount * (t.commission_percentage / 100)) as teacher_share, SUM(p.paid_amount * ((100 - t.commission_percentage) / 100)) as institute_share, COUNT(*) as payment_count FROM payments p JOIN classes c ON p.class_id = c.id JOIN teachers t ON c.teacher_id = t.id WHERE p.payment_date >= ? AND p.payment_date <= ? AND p.status = 'completed'";
    $params = [$date_from, $date_to];
    if ($teacher_filter) { $query .= " AND t.id = ?"; $params[] = $teacher_filter; }
    if ($class_filter) { $query .= " AND p.class_id = ?"; $params[] = $class_filter; }
    $query .= " GROUP BY t.id ORDER BY total_collected DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $teacher_income_report = $stmt->fetchAll();
}

if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $export . '_report_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    if ($export === 'daily' || $export === 'monthly') {
        fputcsv($output, ['Date', 'Class', 'Amount', 'Transactions']);
        foreach (${$export . '_report'} as $row) {
            fputcsv($output, [$row['payment_date'] ?? $row['month'] . '/' . $row['year'], $row['class_name'] ?? 'All Classes', $row['total'], $row['count']]);
        }
    } elseif ($export === 'outstanding') {
        fputcsv($output, ['Student ID', 'Name', 'Class', 'Outstanding Amount']);
        foreach ($outstanding_report as $row) {
            fputcsv($output, [$row['student_id'], $row['full_name'], $row['class_name'], $row['outstanding']]);
        }
    } elseif ($export === 'teacher_income') {
        fputcsv($output, ['Teacher', 'Commission %', 'Total Collected', 'Teacher Share', 'Institute Share', 'Payments']);
        foreach ($teacher_income_report as $row) {
            fputcsv($output, [$row['name'], $row['commission_percentage'], $row['total_collected'], $row['teacher_share'], $row['institute_share'], $row['payment_count']]);
        }
    }
    fclose($output);
    exit;
}

include '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Reports</h2>
</div>

<div class="card card-custom mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Report Type</label>
                <select name="type" class="form-select" onchange="this.form.submit()">
                    <option value="daily" <?php echo $report_type === 'daily' ? 'selected' : ''; ?>>Daily Collection</option>
                    <option value="monthly" <?php echo $report_type === 'monthly' ? 'selected' : ''; ?>>Monthly Collection</option>
                    <option value="outstanding" <?php echo $report_type === 'outstanding' ? 'selected' : ''; ?>>Outstanding Payments</option>
                    <option value="teacher_income" <?php echo $report_type === 'teacher_income' ? 'selected' : ''; ?>>Teacher Income</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo sanitize($date_from); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo sanitize($date_to); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Class</label>
                <select name="class" class="form-select">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo sanitize($class['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($report_type === 'teacher_income'): ?>
                <div class="col-md-2">
                    <label class="form-label">Teacher</label>
                    <select name="teacher" class="form-select">
                        <option value="">All Teachers</option>
                        <?php foreach ($teachers_list as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>" <?php echo $teacher_filter == $teacher['id'] ? 'selected' : ''; ?>><?php echo sanitize($teacher['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-<?php echo $report_type === 'teacher_income' ? '1' : '3'; ?>">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-action flex-grow-1"><i class="bi bi-search me-1"></i>Generate</button>
                    <a href="?export=<?php echo $report_type; ?>&date_from=<?php echo sanitize($date_from); ?>&date_to=<?php echo sanitize($date_to); ?><?php echo $class_filter ? '&class=' . $class_filter : ''; ?><?php echo $teacher_filter ? '&teacher=' . $teacher_filter : ''; ?>" class="btn btn-success btn-action"><i class="bi bi-download me-1"></i>Export</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($report_type === 'daily'): ?>
    <div class="card card-custom">
        <div class="card-header bg-white py-3"><h5 class="mb-0">Daily Collection Report</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead><tr><th>Date</th><th>Class</th><th>Amount</th><th>Transactions</th></tr></thead>
                    <tbody>
                        <?php foreach ($daily_report as $row): ?>
                            <tr>
                                <td><?php echo formatDate($row['payment_date']); ?></td>
                                <td><?php echo sanitize($row['class_name']); ?></td>
                                <td><?php echo formatCurrency($row['total']); ?></td>
                                <td><?php echo $row['count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($daily_report)) echo '<tr><td colspan="4" class="text-center py-4 text-muted">No data found</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php elseif ($report_type === 'monthly'): ?>
    <div class="card card-custom">
        <div class="card-header bg-white py-3"><h5 class="mb-0">Monthly Collection Report</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead><tr><th>Month</th><th>Year</th><th>Amount</th><th>Transactions</th></tr></thead>
                    <tbody>
                        <?php foreach ($monthly_report as $row): ?>
                            <tr>
                                <td><?php echo date('F', mktime(0, 0, 0, $row['month'], 1)); ?></td>
                                <td><?php echo $row['year']; ?></td>
                                <td><?php echo formatCurrency($row['total']); ?></td>
                                <td><?php echo $row['count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($monthly_report)) echo '<tr><td colspan="4" class="text-center py-4 text-muted">No data found</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php elseif ($report_type === 'outstanding'): ?>
    <div class="card card-custom">
        <div class="card-header bg-white py-3"><h5 class="mb-0">Outstanding Payments Report</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead><tr><th>Student ID</th><th>Name</th><th>Class</th><th>Outstanding Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($outstanding_report as $row): ?>
                            <tr>
                                <td><?php echo sanitize($row['student_id']); ?></td>
                                <td><?php echo sanitize($row['full_name']); ?></td>
                                <td><?php echo sanitize($row['class_name']); ?></td>
                                <td><strong class="text-warning"><?php echo formatCurrency($row['outstanding']); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($outstanding_report)) echo '<tr><td colspan="4" class="text-center py-4 text-muted">No outstanding payments</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php elseif ($report_type === 'teacher_income'): ?>
    <div class="card card-custom">
        <div class="card-header bg-white py-3"><h5 class="mb-0">Teacher Income Report</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead><tr><th>Teacher</th><th>Commission %</th><th>Total Collected</th><th>Teacher Share</th><th>Institute Share</th><th>Payments</th></tr></thead>
                    <tbody>
                        <?php foreach ($teacher_income_report as $row): ?>
                            <tr>
                                <td><?php echo sanitize($row['name']); ?></td>
                                <td><?php echo $row['commission_percentage']; ?>%</td>
                                <td><?php echo formatCurrency($row['total_collected']); ?></td>
                                <td class="text-success"><?php echo formatCurrency($row['teacher_share']); ?></td>
                                <td class="text-primary"><?php echo formatCurrency($row['institute_share']); ?></td>
                                <td><?php echo $row['payment_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($teacher_income_report)) echo '<tr><td colspan="6" class="text-center py-4 text-muted">No data found</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

