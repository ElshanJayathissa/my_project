<?php
requireRole('teacher');
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();
$user_id = $_SESSION['user_id'];
$page_title = 'Reports';

$teacher = $pdo->prepare("SELECT id, name FROM teachers WHERE user_id = ? AND status = 'active'");
$teacher->execute([$user_id]);
$teacher = $teacher->fetch();
$teacher_id = $teacher['id'] ?? 0;

if (!$teacher_id) {
    flashMessage('error', 'Teacher profile not found.');
    redirect('dashboard.php');
}

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

$export = isset($_GET['export']) ? $_GET['export'] : '';

if ($export === 'collection') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=class_collection_report_' . date('Ymd') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Class', 'Total Students', 'Paid Count', 'Unpaid Count', 'Outstanding', 'Monthly Fee', 'Total Expected']);
    $classes = $pdo->prepare("SELECT c.id, c.name, c.monthly_fee FROM classes c WHERE c.teacher_id = ? AND c.status = 'active'");
    $classes->execute([$teacher_id]);
    $my_classes = $classes->fetchAll();
    foreach ($my_classes as $class) {
        $class_id = $class['id'];
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as total FROM class_enrollments WHERE class_id = ? AND status = 'active'");
        $stmt->execute([$class_id]);
        $total = $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) as paid, SUM(pi.amount_due - pi.amount_paid) as outstanding FROM payments p JOIN payment_items pi ON p.id = pi.payment_id WHERE p.class_id = ? AND p.status = 'completed' AND pi.status = 'paid'");
        $stmt->execute([$class_id]);
        $paid_info = $stmt->fetch();
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT p.student_id) as unpaid FROM payments p JOIN payment_items pi ON p.id = pi.payment_id WHERE p.class_id = ? AND p.status != 'cancelled' AND pi.status != 'paid'");
        $stmt->execute([$class_id]);
        $unpaid_info = $stmt->fetch();
        $stmt = $pdo->prepare("SELECT SUM(pi.amount_due - pi.amount_paid) as outstanding FROM payments p JOIN payment_items pi ON p.id = pi.payment_id WHERE p.class_id = ? AND p.status != 'cancelled' AND pi.status != 'paid'");
        $stmt->execute([$class_id]);
        $outstanding_row = $stmt->fetch();
        fputcsv($output, [
            $class['name'],
            $total,
            $paid_info['paid'] ?? 0,
            $unpaid_info['unpaid'] ?? 0,
            $outstanding_row['outstanding'] ?? 0,
            $class['monthly_fee'],
            $total * $class['monthly_fee']
        ]);
    }
    fclose($output);
    logAudit($pdo, $user_id, 'export_collection_report', 'Exported class collection report CSV');
    exit;
}

if ($export === 'payment_status') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=payment_status_report_' . date('Ymd') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'Student Name', 'Class', 'Monthly Fee', 'Payment Status', 'Outstanding']);
    $classes = $pdo->prepare("SELECT c.id, c.name, c.monthly_fee FROM classes c WHERE c.teacher_id = ? AND c.status = 'active'");
    $classes->execute([$teacher_id]);
    $my_classes = $classes->fetchAll();
    $class_ids = array_column($my_classes, 'id');
    if (!empty($class_ids)) {
        $in = implode(',', array_map('intval', $class_ids));
        $stmt = $pdo->query("SELECT s.id, s.full_name, s.student_id, c.name as class_name, c.monthly_fee, p.status as payment_status, (SELECT SUM(pi.amount_due - pi.amount_paid) FROM payment_items pi JOIN payments p2 ON pi.payment_id = p2.id WHERE p2.student_id = s.id AND p2.class_id = c.id AND pi.status != 'paid') as outstanding FROM students s JOIN class_enrollments ce ON s.id = ce.student_id JOIN classes c ON ce.class_id = c.id LEFT JOIN payments p ON p.student_id = s.id AND p.class_id = c.id AND p.status != 'cancelled' WHERE ce.class_id IN ($in) AND ce.status = 'active' ORDER BY s.full_name ASC");
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                $row['student_id'],
                $row['full_name'],
                $row['class_name'],
                $row['monthly_fee'],
                ucfirst($row['payment_status']),
                $row['outstanding'] ?? 0
            ]);
        }
    }
    fclose($output);
    logAudit($pdo, $user_id, 'export_payment_status_report', 'Exported payment status report CSV');
    exit;
}

if ($export === 'outstanding') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=outstanding_report_' . date('Ymd') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Class', 'Student ID', 'Student Name', 'Outstanding Amount', 'Billing Month']);
    $classes = $pdo->prepare("SELECT c.id, c.name FROM classes c WHERE c.teacher_id = ? AND c.status = 'active'");
    $classes->execute([$teacher_id]);
    $my_classes = $classes->fetchAll();
    foreach ($my_classes as $class) {
        $class_id = $class['id'];
        $stmt = $pdo->prepare("SELECT s.student_id, s.full_name, pi.billing_month, pi.billing_year, (pi.amount_due - pi.amount_paid) as outstanding FROM payment_items pi JOIN payments p ON pi.payment_id = p.id JOIN students s ON p.student_id = s.id WHERE p.class_id = ? AND p.status != 'cancelled' AND pi.status != 'paid' ORDER BY pi.billing_year DESC, pi.billing_month DESC");
        $stmt->execute([$class_id]);
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                $class['name'],
                $row['student_id'],
                $row['full_name'],
                $row['outstanding'],
                date('F Y', mktime(0,0,0,$row['billing_month'],1,$row['billing_year']))
            ]);
        }
    }
    fclose($output);
    logAudit($pdo, $user_id, 'export_outstanding_report', 'Exported outstanding payments report CSV');
    exit;
}

$classes = $pdo->prepare("SELECT c.id, c.name, c.monthly_fee FROM classes c WHERE c.teacher_id = ? AND c.status = 'active'");
$classes->execute([$teacher_id]);
$my_classes = $classes->fetchAll();

$class_collection = [];
foreach ($my_classes as $class) {
    $class_id = $class['id'];
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as total FROM class_enrollments WHERE class_id = ? AND status = 'active'");
    $stmt->execute([$class_id]);
    $total = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) as paid, SUM(pi.amount_due - pi.amount_paid) as outstanding FROM payments p JOIN payment_items pi ON p.id = pi.payment_id WHERE p.class_id = ? AND p.status = 'completed' AND pi.status = 'paid'");
    $stmt->execute([$class_id]);
    $paid_info = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT p.student_id) as unpaid FROM payments p JOIN payment_items pi ON p.id = pi.payment_id WHERE p.class_id = ? AND p.status != 'cancelled' AND pi.status != 'paid'");
    $stmt->execute([$class_id]);
    $unpaid_info = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT SUM(pi.amount_due - pi.amount_paid) as outstanding FROM payments p JOIN payment_items pi ON p.id = pi.payment_id WHERE p.class_id = ? AND p.status != 'cancelled' AND pi.status != 'paid'");
    $stmt->execute([$class_id]);
    $outstanding_row = $stmt->fetch();
    $class_collection[] = [
        'id' => $class_id,
        'name' => $class['name'],
        'monthly_fee' => $class['monthly_fee'],
        'total' => $total,
        'paid' => $paid_info['paid'] ?? 0,
        'unpaid' => $unpaid_info['unpaid'] ?? 0,
        'outstanding' => $outstanding_row['outstanding'] ?? 0,
    ];
}

$student_payments = [];
$class_ids = array_column($my_classes, 'id');
if (!empty($class_ids)) {
    $in = implode(',', array_map('intval', $class_ids));
    $stmt = $pdo->query("SELECT s.id, s.full_name, s.student_id, c.name as class_name, c.monthly_fee, p.status as payment_status, (SELECT SUM(pi.amount_due - pi.amount_paid) FROM payment_items pi JOIN payments p2 ON pi.payment_id = p2.id WHERE p2.student_id = s.id AND p2.class_id = c.id AND pi.status != 'paid') as outstanding FROM students s JOIN class_enrollments ce ON s.id = ce.student_id JOIN classes c ON ce.class_id = c.id LEFT JOIN payments p ON p.student_id = s.id AND p.class_id = c.id AND p.status != 'cancelled' WHERE ce.class_id IN ($in) AND ce.status = 'active' ORDER BY s.full_name ASC");
    while ($row = $stmt->fetch()) {
        $student_payments[] = $row;
    }
}

$outstanding_payments = [];
foreach ($my_classes as $class) {
    $class_id = $class['id'];
    $stmt = $pdo->prepare("SELECT s.student_id, s.full_name, pi.billing_month, pi.billing_year, (pi.amount_due - pi.amount_paid) as outstanding FROM payment_items pi JOIN payments p ON pi.payment_id = p.id JOIN students s ON p.student_id = s.id WHERE p.class_id = ? AND p.status != 'cancelled' AND pi.status != 'paid' ORDER BY pi.billing_year DESC, pi.billing_month DESC");
    $stmt->execute([$class_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $outstanding_payments[] = [
            'class_name' => $class['name'],
            'student_id' => $row['student_id'],
            'full_name' => $row['full_name'],
            'outstanding' => $row['outstanding'],
            'billing_month' => date('F Y', mktime(0,0,0,$row['billing_month'],1,$row['billing_year']))
        ];
    }
}

logAudit($pdo, $user_id, 'view_reports', 'Teacher viewed reports');
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-3">Reports</h2>
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
                <button type="submit" class="btn btn-primary w-100 me-2"><i class="bi bi-funnel me-1"></i>Apply Filter</button>
            </div>
        </form>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <a href="?export=collection&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-success btn-action"><i class="bi bi-download me-1"></i>Export Collection CSV</a>
            <a href="?export=payment_status&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-info btn-action"><i class="bi bi-download me-1"></i>Export Payment Status CSV</a>
            <a href="?export=outstanding&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-warning btn-action"><i class="bi bi-download me-1"></i>Export Outstanding CSV</a>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="card card-custom">
                    <div class="card-header bg-white fw-semibold">Class Collection Report</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>Class</th>
                                        <th>Monthly Fee</th>
                                        <th>Total Students</th>
                                        <th>Paid</th>
                                        <th>Unpaid</th>
                                        <th>Outstanding</th>
                                        <th>Expected</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($class_collection)): ?>
                                        <tr><td colspan="7" class="text-center py-4">No classes assigned.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($class_collection as $cc): ?>
                                            <tr>
                                                <td><?php echo sanitize($cc['name']); ?></td>
                                                <td><?php echo formatCurrency($cc['monthly_fee']); ?></td>
                                                <td><?php echo $cc['total']; ?></td>
                                                <td class="text-success fw-semibold"><?php echo $cc['paid']; ?></td>
                                                <td class="text-danger"><?php echo $cc['unpaid']; ?></td>
                                                <td class="text-danger"><?php echo formatCurrency($cc['outstanding']); ?></td>
                                                <td><?php echo formatCurrency($cc['total'] * $cc['monthly_fee']); ?></td>
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

        <div class="row mb-4">
            <div class="col-12">
                <div class="card card-custom">
                    <div class="card-header bg-white fw-semibold">Student Payment Status</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Class</th>
                                        <th>Monthly Fee</th>
                                        <th>Status</th>
                                        <th>Outstanding</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($student_payments)): ?>
                                        <tr><td colspan="6" class="text-center py-4">No students found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($student_payments as $sp): ?>
                                            <tr>
                                                <td><?php echo sanitize($sp['student_id']); ?></td>
                                                <td><?php echo sanitize($sp['full_name']); ?></td>
                                                <td><?php echo sanitize($sp['class_name']); ?></td>
                                                <td><?php echo formatCurrency($sp['monthly_fee']); ?></td>
                                                <td>
                                                    <?php if ($sp['payment_status'] === 'completed'): ?>
                                                        <span class="badge bg-success status-badge">Paid</span>
                                                    <?php elseif ($sp['payment_status'] === 'partial'): ?>
                                                        <span class="badge bg-warning status-badge">Partial</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger status-badge">Unpaid</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="<?php echo ($sp['outstanding'] > 0) ? 'text-danger' : 'text-muted'; ?>">
                                                    <?php echo formatCurrency($sp['outstanding'] ?? 0); ?>
                                                </td>
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

        <div class="row">
            <div class="col-12">
                <div class="card card-custom">
                    <div class="card-header bg-white fw-semibold">Outstanding Payments by Class</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>Class</th>
                                        <th>Student ID</th>
                                        <th>Student Name</th>
                                        <th>Billing Month</th>
                                        <th>Outstanding</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($outstanding_payments)): ?>
                                        <tr><td colspan="5" class="text-center py-4">No outstanding payments.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($outstanding_payments as $op): ?>
                                            <tr>
                                                <td><?php echo sanitize($op['class_name']); ?></td>
                                                <td><?php echo sanitize($op['student_id']); ?></td>
                                                <td><?php echo sanitize($op['full_name']); ?></td>
                                                <td><?php echo $op['billing_month']; ?></td>
                                                <td class="text-danger fw-semibold"><?php echo formatCurrency($op['outstanding']); ?></td>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
