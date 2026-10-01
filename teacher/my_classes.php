<?php
requireRole('teacher');
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();
$user_id = $_SESSION['user_id'];
$page_title = 'My Classes';

$teacher = $pdo->prepare("SELECT id, name, commission_percentage FROM teachers WHERE user_id = ? AND status = 'active'");
$teacher->execute([$user_id]);
$teacher = $teacher->fetch();
$teacher_id = $teacher['id'] ?? 0;

if (!$teacher_id) {
    flashMessage('error', 'Teacher profile not found.');
    redirect('dashboard.php');
}

$classes = $pdo->prepare("SELECT c.id, c.name, c.monthly_fee, c.description, c.created_at FROM classes c WHERE c.teacher_id = ? AND c.status = 'active' ORDER BY c.name ASC");
$classes->execute([$teacher_id]);
$my_classes = $classes->fetchAll();

$class_data = [];
foreach ($my_classes as $class) {
    $class_id = $class['id'];
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as total_students FROM class_enrollments WHERE class_id = ? AND status = 'active'");
    $stmt->execute([$class_id]);
    $total_students = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) as paid_count, SUM(pi.amount_due - pi.amount_paid) as outstanding FROM payments p JOIN payment_items pi ON p.id = pi.payment_id WHERE p.class_id = ? AND p.status = 'completed' AND pi.status = 'paid'");
    $stmt->execute([$class_id]);
    $paid_info = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT p.student_id) as unpaid_count FROM payments p JOIN payment_items pi ON p.id = pi.payment_id WHERE p.class_id = ? AND p.status != 'cancelled' AND pi.status != 'paid'");
    $stmt->execute([$class_id]);
    $unpaid_info = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT SUM(pi.amount_due - pi.amount_paid) as outstanding FROM payments p JOIN payment_items pi ON p.id = pi.payment_id WHERE p.class_id = ? AND p.status != 'cancelled' AND pi.status != 'paid'");
    $stmt->execute([$class_id]);
    $outstanding_row = $stmt->fetch();
    $outstanding = $outstanding_row['outstanding'] ?? 0;

    $class_data[] = [
        'id' => $class_id,
        'name' => $class['name'],
        'monthly_fee' => $class['monthly_fee'],
        'description' => $class['description'],
        'total_students' => $total_students,
        'paid_count' => $paid_info['paid_count'] ?? 0,
        'unpaid_count' => $unpaid_info['unpaid_count'] ?? 0,
        'outstanding' => $outstanding,
    ];
}

logAudit($pdo, $user_id, 'view_my_classes', 'Teacher viewed my classes');
?>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="mb-0">My Classes</h2>
            <a href="dashboard.php" class="btn btn-outline-primary btn-action"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        </div>
        <?php if (empty($class_data)): ?>
            <div class="alert alert-info">No classes assigned to you yet.</div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($class_data as $cd): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="card card-custom h-100">
                            <div class="card-body">
                                <h5 class="card-title text-primary"><?php echo sanitize($cd['name']); ?></h5>
                                <p class="text-muted small mb-3"><?php echo sanitize($cd['description'] ?? 'No description'); ?></p>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <div class="p-2 bg-light rounded">
                                            <small class="text-muted d-block">Monthly Fee</small>
                                            <span class="fw-semibold"><?php echo formatCurrency($cd['monthly_fee']); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-2 bg-light rounded">
                                            <small class="text-muted d-block">Students</small>
                                            <span class="fw-semibold"><?php echo $cd['total_students']; ?></span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-2 bg-light rounded">
                                            <small class="text-muted d-block">Paid</small>
                                            <span class="fw-semibold text-success"><?php echo $cd['paid_count']; ?></span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-2 bg-light rounded">
                                            <small class="text-muted d-block">Unpaid</small>
                                            <span class="fw-semibold text-danger"><?php echo $cd['unpaid_count']; ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-danger fw-semibold">Outstanding: <?php echo formatCurrency($cd['outstanding']); ?></span>
                                    <a href="?class_id=<?php echo $cd['id']; ?>" class="btn btn-primary btn-sm btn-action">View Details</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_GET['class_id'])): ?>
    <?php
    $class_id = (int)$_GET['class_id'];
    $stmt = $pdo->prepare("SELECT c.id, c.name, c.monthly_fee, c.description FROM classes c WHERE c.id = ? AND c.teacher_id = ?");
    $stmt->execute([$class_id, $teacher_id]);
    $class = $stmt->fetch();
    if ($class):
    ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card card-custom">
                <div class="card-header bg-white fw-semibold">Class Details: <?php echo sanitize($class['name']); ?></div>
                <div class="card-body">
                    <?php
                    $stmt = $pdo->prepare("
                        SELECT s.id, s.full_name, s.student_id, p.status as payment_status,
                               (SELECT SUM(pi.amount_due - pi.amount_paid) FROM payment_items pi JOIN payments p2 ON pi.payment_id = p2.id WHERE p2.student_id = s.id AND p2.class_id = c.id AND pi.status != 'paid') as outstanding
                        FROM class_enrollments ce
                        JOIN students s ON ce.student_id = s.id
                        LEFT JOIN payments p ON p.student_id = s.id AND p.class_id = c.id AND p.status != 'cancelled'
                        WHERE ce.class_id = ? AND ce.status = 'active'
                        ORDER BY s.full_name ASC
                    ");
                    $stmt->execute([$class_id]);
                    $students = $stmt->fetchAll();
                    ?>
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Monthly Fee</th>
                                    <th>Status</th>
                                    <th>Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($students)): ?>
                                    <tr><td colspan="5" class="text-center py-4">No students enrolled.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td><?php echo sanitize($student['student_id']); ?></td>
                                            <td><?php echo sanitize($student['full_name']); ?></td>
                                            <td><?php echo formatCurrency($class['monthly_fee']); ?></td>
                                            <td>
                                                <?php if ($student['payment_status'] === 'completed'): ?>
                                                    <span class="badge bg-success status-badge">Paid</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning status-badge">Unpaid</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="<?php echo ($student['outstanding'] > 0) ? 'text-danger' : 'text-muted'; ?>">
                                                <?php echo formatCurrency($student['outstanding'] ?? 0); ?>
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
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
