<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'Classes Management';
$pdo = db();
$user_id = $_SESSION['user_id'];

$action = $_GET['action'] ?? 'list';
$class_id = $_GET['id'] ?? null;
$edit_id = $_GET['edit'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        flashMessage('error', 'Invalid CSRF token');
        redirect('classes.php');
    }

    if (isset($_POST['add_class'])) {
        $missing = validateRequired(['name', 'teacher_id', 'monthly_fee']);
        if (!empty($missing)) {
            flashMessage('error', 'Please fill: ' . implode(', ', $missing));
        } else {
            $stmt = $pdo->prepare("INSERT INTO classes (name, teacher_id, monthly_fee, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                sanitize($_POST['name']),
                $_POST['teacher_id'],
                $_POST['monthly_fee'],
                sanitize($_POST['description'])
            ]);
            logAudit($pdo, $user_id, 'add_class', 'Added class: ' . $_POST['name']);
            flashMessage('success', 'Class added successfully');
            redirect('classes.php');
        }
    }

    if (isset($_POST['edit_class']) && $edit_id) {
        $stmt = $pdo->prepare("UPDATE classes SET name = ?, teacher_id = ?, monthly_fee = ?, description = ? WHERE id = ?");
        $stmt->execute([
            sanitize($_POST['name']),
            $_POST['teacher_id'],
            $_POST['monthly_fee'],
            sanitize($_POST['description']),
            $edit_id
        ]);
        logAudit($pdo, $user_id, 'edit_class', 'Updated class ID: ' . $edit_id);
        flashMessage('success', 'Class updated successfully');
        redirect('classes.php');
    }

    if (isset($_POST['enroll_student']) && $class_id) {
        $enroll_student_id = intval($_POST['enroll_student_id']);
        $enroll_date = $_POST['enrollment_date'] ?: date('Y-m-d');
        $stmt = $pdo->prepare("INSERT INTO class_enrollments (student_id, class_id, enrollment_date, status) VALUES (?, ?, ?, 'active') ON DUPLICATE KEY UPDATE status = 'active', enrollment_date = VALUES(enrollment_date), updated_at = NOW()");
        $stmt->execute([$enroll_student_id, $class_id, $enroll_date]);
        logAudit($pdo, $user_id, 'enroll_student', 'Enrolled student ID: ' . $enroll_student_id . ' in class ID: ' . $class_id);
        flashMessage('success', 'Student enrolled successfully');
        redirect('classes.php?id=' . $class_id . '&action=view');
    }

    if (isset($_POST['unenroll_student']) && $class_id) {
        $unenroll_student_id = intval($_POST['unenroll_student_id']);
        $stmt = $pdo->prepare("UPDATE class_enrollments SET status = 'inactive' WHERE student_id = ? AND class_id = ? AND status = 'active'");
        $stmt->execute([$unenroll_student_id, $class_id]);
        logAudit($pdo, $user_id, 'unenroll_student', 'Removed student ID: ' . $unenroll_student_id . ' from class ID: ' . $class_id);
        flashMessage('success', 'Student removed from class');
        redirect('classes.php?id=' . $class_id . '&action=view');
    }

    if (isset($_POST['toggle_status']) && $class_id) {
        $stmt = $pdo->prepare("UPDATE classes SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
        $stmt->execute([$class_id]);
        logAudit($pdo, $user_id, 'toggle_class_status', 'Toggled class ID: ' . $class_id);
        flashMessage('success', 'Class status updated');
        redirect('classes.php');
    }
}

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$query = "SELECT c.*, t.name as teacher_name FROM classes c JOIN teachers t ON c.teacher_id = t.id WHERE 1=1";
$params = [];
if ($search) {
    $query .= " AND (c.name LIKE ? OR t.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $query .= " AND c.status = ?";
    $params[] = $status_filter;
}
$query .= " ORDER BY c.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$classes = $stmt->fetchAll();

$view_class = null;
$enrolled_students = [];
$outstanding_payments = [];
if ($action === 'view' && $class_id) {
    $stmt = $pdo->prepare("SELECT c.*, t.name as teacher_name, t.commission_percentage FROM classes c JOIN teachers t ON c.teacher_id = t.id WHERE c.id = ?");
    $stmt->execute([$class_id]);
    $view_class = $stmt->fetch();
    if ($view_class) {
        $stmt = $pdo->prepare("
            SELECT s.*, ce.enrollment_date
            FROM class_enrollments ce
            JOIN students s ON ce.student_id = s.id
            WHERE ce.class_id = ? AND ce.status = 'active'
        ");
        $stmt->execute([$class_id]);
        $enrolled_students = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT s.id, s.full_name, s.student_id,
                   SUM(pi.amount_due - pi.amount_paid) as outstanding
            FROM payment_items pi
            JOIN payments p ON pi.payment_id = p.id
            JOIN students s ON p.student_id = s.id
            WHERE p.class_id = ? AND pi.status != 'paid'
            GROUP BY s.id
        ");
        $stmt->execute([$class_id]);
        $outstanding_payments = $stmt->fetchAll();
    }
}

$edit_class = null;
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_class = $stmt->fetch();
}

$teachers = $pdo->query("SELECT id, name FROM teachers WHERE status = 'active' ORDER BY name")->fetchAll();

include '../includes/header.php';
?>

<?php if ($action === 'view' && $view_class): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Class Details</h2>
        <a href="classes.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Back to List</a>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card card-custom mb-4">
                <div class="card-body">
                    <h4 class="mb-3"><?php echo sanitize($view_class['name']); ?></h4>
                    <p class="text-muted mb-2"><i class="bi bi-person-badge me-2"></i><?php echo sanitize($view_class['teacher_name']); ?></p>
                    <p class="text-muted mb-2"><i class="bi bi-currency-rupee me-2"></i><?php echo formatCurrency($view_class['monthly_fee']); ?>/month</p>
                    <p class="text-muted mb-2"><i class="bi bi-people me-2"></i><?php echo count($enrolled_students); ?> students enrolled</p>
                </div>
            </div>
            <div class="card card-custom mb-4">
                <div class="card-header bg-white py-3"><h6 class="mb-0">Outstanding Payments</h6></div>
                <div class="card-body">
                    <?php if (!empty($outstanding_payments)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Student</th><th>Outstanding</th></tr></thead>
                                <tbody>
                                    <?php foreach ($outstanding_payments as $op): ?>
                                        <tr>
                                            <td><?php echo sanitize($op['full_name']); ?></td>
                                            <td><strong class="text-warning"><?php echo formatCurrency($op['outstanding']); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center py-3 mb-0">No outstanding payments</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card card-custom">
                <div class="card-header bg-white py-3"><h6 class="mb-0">Actions</h6></div>
                <div class="card-body d-grid gap-2">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="toggle_status" value="1">
                        <button type="submit" class="btn btn-<?php echo $view_class['status'] === 'active' ? 'warning' : 'success'; ?> btn-action w-100">
                            <i class="bi bi-<?php echo $view_class['status'] === 'active' ? 'pause' : 'play'; ?> me-2"></i>
                            <?php echo $view_class['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                    <a href="?edit=<?php echo $view_class['id']; ?>" class="btn btn-primary btn-action"><i class="bi bi-pencil me-2"></i>Edit Class</a>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card card-custom mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-people me-2 text-primary"></i>Enrolled Students (<?php echo count($enrolled_students); ?>)</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#enrollStudentModal">
                        <i class="bi bi-person-plus me-1"></i>Enroll Student
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead><tr><th>Student ID</th><th>Name</th><th>Phone</th><th>Status</th><th>Enrolled</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach ($enrolled_students as $student): ?>
                                    <tr>
                                        <td><?php echo sanitize($student['student_id']); ?></td>
                                        <td><?php echo sanitize($student['full_name']); ?></td>
                                        <td><?php echo sanitize($student['phone'] ?: 'N/A'); ?></td>
                                        <td><span class="badge bg-<?php echo $student['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($student['status']); ?></span></td>
                                        <td><?php echo formatDate($student['enrollment_date']); ?></td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Remove this student from class?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="unenroll_student" value="1">
                                                <input type="hidden" name="unenroll_student_id" value="<?php echo $student['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-person-x"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($enrolled_students)) echo '<tr><td colspan="6" class="text-center py-4 text-muted">No students enrolled</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="enrollStudentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Enroll Student in <?php echo sanitize($view_class['name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="enroll_student" value="1">
                        <div class="mb-3">
                            <label class="form-label">Select Student</label>
                            <select name="enroll_student_id" class="form-select" required>
                                <option value="">Select Student</option>
                                <?php
                                $all_students = $pdo->query("SELECT id, student_id, full_name FROM students WHERE status = 'active' ORDER BY full_name")->fetchAll();
                                foreach ($all_students as $s):
                                    $already_enrolled = false;
                                    foreach ($enrolled_students as $es) {
                                        if ($es['id'] == $s['id']) { $already_enrolled = true; break; }
                                    }
                                    if (!$already_enrolled):
                                ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo sanitize($s['student_id'] . ' - ' . $s['full_name']); ?></option>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Enrollment Date</label>
                            <input type="date" name="enrollment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-2"></i>Enroll Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php elseif ($edit_id && $edit_class): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit Class</h2>
        <a href="classes.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Cancel</a>
    </div>
    <div class="card card-custom">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Class Name *</label>
                        <input type="text" name="name" class="form-control" value="<?php echo sanitize($edit_class['name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Teacher *</label>
                        <select name="teacher_id" class="form-select" required>
                            <option value="">Select Teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>" <?php echo $edit_class['teacher_id'] == $teacher['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($teacher['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Monthly Fee *</label>
                        <input type="number" step="0.01" name="monthly_fee" class="form-control" value="<?php echo sanitize($edit_class['monthly_fee']); ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?php echo sanitize($edit_class['description']); ?></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="edit_class" class="btn btn-primary btn-action"><i class="bi bi-save me-2"></i>Update Class</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Classes</h2>
        <button class="btn btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#addClassModal"><i class="bi bi-plus-lg me-2"></i>Add Class</button>
    </div>

    <div class="card card-custom mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" placeholder="Search by class or teacher name..." value="<?php echo sanitize($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-action w-100"><i class="bi bi-search me-2"></i>Search</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-custom">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead><tr><th>Class Name</th><th>Teacher</th><th>Monthly Fee</th><th>Students</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($classes as $class): ?>
                            <tr>
                                <td><?php echo sanitize($class['name']); ?></td>
                                <td><?php echo sanitize($class['teacher_name']); ?></td>
                                <td><?php echo formatCurrency($class['monthly_fee']); ?></td>
                                <td>
                                    <?php
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM class_enrollments WHERE class_id = ? AND status = 'active'");
                                    $stmt->execute([$class['id']]);
                                    echo $stmt->fetchColumn();
                                    ?>
                                </td>
                                <td><span class="badge bg-<?php echo $class['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($class['status']); ?></span></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="?id=<?php echo $class['id']; ?>&action=view" class="btn btn-info"><i class="bi bi-eye"></i></a>
                                        <a href="?edit=<?php echo $class['id']; ?>" class="btn btn-primary"><i class="bi bi-pencil"></i></a>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="toggle_status" value="1">
                                            <button type="submit" class="btn btn-<?php echo $class['status'] === 'active' ? 'warning' : 'success'; ?>" title="<?php echo $class['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="bi bi-<?php echo $class['status'] === 'active' ? 'pause' : 'play'; ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($classes)) echo '<tr><td colspan="6" class="text-center py-4 text-muted">No classes found</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="addClassModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Class</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Class Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Teacher *</label>
                            <select name="teacher_id" class="form-select" required>
                                <option value="">Select Teacher</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>"><?php echo sanitize($teacher['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Monthly Fee *</label>
                            <input type="number" step="0.01" name="monthly_fee" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_class" class="btn btn-primary"><i class="bi bi-save me-2"></i>Save Class</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

