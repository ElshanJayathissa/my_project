<?php
requireRole('teacher');
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();
$user_id = $_SESSION['user_id'];
$page_title = 'My Students';

$teacher = $pdo->prepare("SELECT id FROM teachers WHERE user_id = ? AND status = 'active'");
$teacher->execute([$user_id]);
$teacher = $teacher->fetch();
$teacher_id = $teacher['id'] ?? 0;

if (!$teacher_id) {
    flashMessage('error', 'Teacher profile not found.');
    redirect('dashboard.php');
}

$class_filter = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

$stmt = $pdo->prepare("SELECT c.id, c.name FROM classes c WHERE c.teacher_id = ? AND c.status = 'active' ORDER BY c.name ASC");
$stmt->execute([$teacher_id]);
$my_classes = $stmt->fetchAll();

$class_ids = array_column($my_classes, 'id');
$students = [];

if (!empty($class_ids)) {
    $in = implode(',', array_map('intval', $class_ids));
    $sql = "
        SELECT DISTINCT s.id, s.full_name, s.student_id, s.email, s.phone,
               (SELECT SUM(pi.amount_due - pi.amount_paid) FROM payment_items pi JOIN payments p2 ON pi.payment_id = p2.id WHERE p2.student_id = s.id AND p2.class_id IN ($in) AND pi.status != 'paid') as total_outstanding
        FROM students s
        JOIN class_enrollments ce ON s.id = ce.student_id
        WHERE ce.class_id IN ($in) AND ce.status = 'active'
    ";
    $params = [];
    if ($class_filter > 0 && in_array($class_filter, $class_ids)) {
        $sql .= " AND ce.class_id = ?";
        $params[] = $class_filter;
    }
    $sql .= " ORDER BY s.full_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
}

logAudit($pdo, $user_id, 'view_my_students', 'Teacher viewed my students list');
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-3">My Students</h2>
        <form method="GET" class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label">Filter by Class</label>
                <select name="class_id" class="form-select" onchange="this.form.submit()">
                    <option value="">All Classes</option>
                    <?php foreach ($my_classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($class_filter == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo sanitize($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <div class="card card-custom">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom mb-0">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Class</th>
                                <th>Monthly Fee</th>
                                <th>Payment Status</th>
                                <th>Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr><td colspan="6" class="text-center py-4">No students found.</td></tr>
                            <?php else: ?>
                                <?php
                                foreach ($students as $student):
                                    $student_id = $student['id'];
                                    $classes_info = [];
                                    if ($class_filter > 0) {
                                        $stmt2 = $pdo->prepare("SELECT c.name, c.monthly_fee, p.status FROM classes c JOIN payments p ON c.id = p.class_id WHERE p.student_id = ? AND c.id = ? AND p.status != 'cancelled' LIMIT 1");
                                        $stmt2->execute([$student_id, $class_filter]);
                                        $classes_info = $stmt2->fetch();
                                    } else {
                                        $stmt2 = $pdo->prepare("SELECT c.name, c.monthly_fee, p.status FROM classes c JOIN payments p ON c.id = p.class_id WHERE p.student_id = ? AND p.status != 'cancelled' LIMIT 1");
                                        $stmt2->execute([$student_id]);
                                        $classes_info = $stmt2->fetch();
                                    }
                                    $class_name = $classes_info['name'] ?? 'N/A';
                                    $monthly_fee = $classes_info['monthly_fee'] ?? 0;
                                    $payment_status = $classes_info['status'] ?? 'unpaid';
                                ?>
                                    <tr>
                                        <td><?php echo sanitize($student['student_id']); ?></td>
                                        <td><?php echo sanitize($student['full_name']); ?></td>
                                        <td><?php echo sanitize($class_name); ?></td>
                                        <td><?php echo formatCurrency($monthly_fee); ?></td>
                                        <td>
                                            <?php if ($payment_status === 'completed'): ?>
                                                <span class="badge bg-success status-badge">Paid</span>
                                            <?php elseif ($payment_status === 'partial'): ?>
                                                <span class="badge bg-warning status-badge">Partial</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger status-badge">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="<?php echo ($student['total_outstanding'] > 0) ? 'text-danger' : 'text-muted'; ?>">
                                            <?php echo formatCurrency($student['total_outstanding'] ?? 0); ?>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
