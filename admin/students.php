<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'Students Management';
$pdo = db();
$user_id = $_SESSION['user_id'];

$action = $_GET['action'] ?? 'list';
$student_id = $_GET['id'] ?? null;
$edit_id = $_GET['edit'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        flashMessage('error', 'Invalid CSRF token');
        redirect('students.php');
    }

    if (isset($_POST['add_student'])) {
        $missing = validateRequired(['student_id', 'full_name']);
        if (!empty($missing)) {
            flashMessage('error', 'Please fill: ' . implode(', ', $missing));
        } else {
            $stmt = $pdo->prepare("INSERT INTO students (student_id, full_name, email, phone, address, guardian_name, guardian_phone) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                sanitize($_POST['student_id']),
                sanitize($_POST['full_name']),
                sanitize($_POST['email']),
                sanitize($_POST['phone']),
                sanitize($_POST['address']),
                sanitize($_POST['guardian_name']),
                sanitize($_POST['guardian_phone'])
            ]);
            logAudit($pdo, $user_id, 'add_student', 'Added student: ' . $_POST['full_name']);
            flashMessage('success', 'Student added successfully');
            redirect('students.php');
        }
    }

    if (isset($_POST['edit_student']) && $edit_id) {
        $stmt = $pdo->prepare("UPDATE students SET full_name = ?, email = ?, phone = ?, address = ?, guardian_name = ?, guardian_phone = ? WHERE id = ?");
        $stmt->execute([
            sanitize($_POST['full_name']),
            sanitize($_POST['email']),
            sanitize($_POST['phone']),
            sanitize($_POST['address']),
            sanitize($_POST['guardian_name']),
            sanitize($_POST['guardian_phone']),
            $edit_id
        ]);
        logAudit($pdo, $user_id, 'edit_student', 'Updated student ID: ' . $edit_id);
        flashMessage('success', 'Student updated successfully');
        redirect('students.php');
    }

    if (isset($_POST['toggle_status']) && $student_id) {
        $stmt = $pdo->prepare("UPDATE students SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
        $stmt->execute([$student_id]);
        logAudit($pdo, $user_id, 'toggle_student_status', 'Toggled status for student ID: ' . $student_id);
        flashMessage('success', 'Student status updated');
        redirect('students.php');
    }

    if (isset($_POST['generate_qr']) && $student_id) {
        $stmt = $pdo->prepare("SELECT id FROM student_cards WHERE student_id = ? AND status = 'active'");
        $stmt->execute([$student_id]);
        if ($stmt->fetch()) {
            flashMessage('error', 'Student already has an active QR card');
        } else {
            $card_id = generateCardId($pdo);
            $qr_token = generateQrToken();
            $stmt = $pdo->prepare("INSERT INTO student_cards (student_id, card_id, qr_token) VALUES (?, ?, ?)");
            $stmt->execute([$student_id, $card_id, $qr_token]);
            logAudit($pdo, $user_id, 'generate_qr_card', 'Generated QR card for student ID: ' . $student_id);
            flashMessage('success', 'QR card generated successfully');
        }
        redirect('students.php?id=' . $student_id . '&action=view');
    }

    if (isset($_POST['regenerate_qr']) && $student_id) {
        $qr_token = generateQrToken();
        $stmt = $pdo->prepare("UPDATE student_cards SET qr_token = ?, status = 'replaced' WHERE student_id = ? AND status = 'active'");
        $stmt->execute([$qr_token, $student_id]);
        logAudit($pdo, $user_id, 'regenerate_qr_token', 'Regenerated QR token for student ID: ' . $student_id);
        flashMessage('success', 'QR token regenerated successfully');
        redirect('students.php?id=' . $student_id . '&action=view');
    }

    if (isset($_POST['enroll_in_class']) && $student_id) {
        $class_id = intval($_POST['class_id']);
        $enrollment_date = $_POST['enrollment_date'] ?: date('Y-m-d');
        $stmt = $pdo->prepare("INSERT INTO class_enrollments (student_id, class_id, enrollment_date, status) VALUES (?, ?, ?, 'active') ON DUPLICATE KEY UPDATE status = 'active', enrollment_date = VALUES(enrollment_date), updated_at = NOW()");
        $stmt->execute([$student_id, $class_id, $enrollment_date]);
        logAudit($pdo, $user_id, 'enroll_student', 'Enrolled student ID: ' . $student_id . ' in class ID: ' . $class_id);
        flashMessage('success', 'Student enrolled in class successfully');
        redirect('students.php?id=' . $student_id . '&action=view');
    }

    if (isset($_POST['unenroll_from_class']) && $student_id) {
        $class_id = intval($_POST['class_id']);
        $stmt = $pdo->prepare("UPDATE class_enrollments SET status = 'inactive' WHERE student_id = ? AND class_id = ? AND status = 'active'");
        $stmt->execute([$student_id, $class_id]);
        logAudit($pdo, $user_id, 'unenroll_student', 'Removed student ID: ' . $student_id . ' from class ID: ' . $class_id);
        flashMessage('success', 'Student removed from class');
        redirect('students.php?id=' . $student_id . '&action=view');
    }
}

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$query = "SELECT s.*, COUNT(DISTINCT sc.id) as card_count FROM students s LEFT JOIN student_cards sc ON s.id = sc.student_id WHERE 1=1";
$params = [];
if ($search) {
    $query .= " AND (s.full_name LIKE ? OR s.student_id LIKE ? OR s.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $query .= " AND s.status = ?";
    $params[] = $status_filter;
}
$query .= " GROUP BY s.id ORDER BY s.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

$view_student = null;
$enrolled_classes = [];
$payment_history = [];
if ($action === 'view' && $student_id) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $view_student = $stmt->fetch();
    if ($view_student) {
        $stmt = $pdo->prepare("
            SELECT c.*, t.name as teacher_name, ce.enrollment_date
            FROM class_enrollments ce
            JOIN classes c ON ce.class_id = c.id
            JOIN teachers t ON c.teacher_id = t.id
            WHERE ce.student_id = ? AND ce.status = 'active'
        ");
        $stmt->execute([$student_id]);
        $enrolled_classes = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT p.*, c.name as class_name, u.full_name as collector_name
            FROM payments p
            JOIN classes c ON p.class_id = c.id
            LEFT JOIN users u ON p.collector_id = u.id
            WHERE p.student_id = ?
            ORDER BY p.payment_date DESC
        ");
        $stmt->execute([$student_id]);
        $payment_history = $stmt->fetchAll();
    }
}

$edit_student = null;
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_student = $stmt->fetch();
}

include '../includes/header.php';
?>

<?php if ($action === 'view' && $view_student): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Student Details</h2>
        <a href="students.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Back to List</a>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card card-custom mb-4">
                <div class="card-body text-center">
                    <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:100px;height:100px;">
                        <i class="bi bi-person fs-1 text-primary"></i>
                    </div>
                    <h4><?php echo sanitize($view_student['full_name']); ?></h4>
                    <p class="text-muted"><?php echo sanitize($view_student['student_id']); ?></p>
                    <span class="badge bg-<?php echo $view_student['status'] === 'active' ? 'success' : 'secondary'; ?>">
                        <?php echo ucfirst($view_student['status']); ?>
                    </span>
                </div>
            </div>
            <div class="card card-custom mb-4">
                <div class="card-header bg-white py-3"><h6 class="mb-0">Contact Information</h6></div>
                <div class="card-body">
                    <p class="mb-2"><i class="bi bi-envelope me-2 text-muted"></i><?php echo sanitize($view_student['email'] ?: 'N/A'); ?></p>
                    <p class="mb-2"><i class="bi bi-phone me-2 text-muted"></i><?php echo sanitize($view_student['phone'] ?: 'N/A'); ?></p>
                    <p class="mb-0"><i class="bi bi-geo-alt me-2 text-muted"></i><?php echo sanitize($view_student['address'] ?: 'N/A'); ?></p>
                </div>
            </div>
            <div class="card card-custom mb-4">
                <div class="card-header bg-white py-3"><h6 class="mb-0">Guardian Information</h6></div>
                <div class="card-body">
                    <p class="mb-2"><i class="bi bi-person me-2 text-muted"></i><?php echo sanitize($view_student['guardian_name'] ?: 'N/A'); ?></p>
                    <p class="mb-0"><i class="bi bi-telephone me-2 text-muted"></i><?php echo sanitize($view_student['guardian_phone'] ?: 'N/A'); ?></p>
                </div>
            </div>
            <div class="card card-custom">
                <div class="card-header bg-white py-3"><h6 class="mb-0">Actions</h6></div>
                <div class="card-body d-grid gap-2">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="toggle_status" value="1">
                        <button type="submit" class="btn btn-<?php echo $view_student['status'] === 'active' ? 'warning' : 'success'; ?> w-100 btn-action">
                            <i class="bi bi-<?php echo $view_student['status'] === 'active' ? 'pause' : 'play'; ?> me-2"></i>
                            <?php echo $view_student['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                    <a href="?edit=<?php echo $view_student['id']; ?>" class="btn btn-primary btn-action"><i class="bi bi-pencil me-2"></i>Edit Student</a>
                    <?php
                    $stmt = $pdo->prepare("SELECT id FROM student_cards WHERE student_id = ? AND status = 'active'");
                    $stmt->execute([$student_id]);
                    $has_card = $stmt->fetch();
                    if (!$has_card):
                    ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="generate_qr" value="1">
                            <button type="submit" class="btn btn-success btn-action w-100"><i class="bi bi-qr-code me-2"></i>Generate QR Card</button>
                        </form>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="regenerate_qr" value="1">
                            <button type="submit" class="btn btn-warning btn-action w-100"><i class="bi bi-arrow-repeat me-2"></i>Regenerate QR Token</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($has_card): ?>
                        <a href="javascript:void(0)" onclick="openQrCardModal('<?php echo urlencode($pdo->query("SELECT qr_token FROM student_cards WHERE student_id = $student_id AND status = 'active'")->fetchColumn()); ?>', false)" class="btn btn-info btn-action w-100"><i class="bi bi-eye me-2"></i>View QR Card</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card card-custom mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-book me-2 text-primary"></i>Enrolled Classes</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#enrollClassModal">
                        <i class="bi bi-plus-lg me-1"></i>Enroll in Class
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead><tr><th>Class</th><th>Teacher</th><th>Monthly Fee</th><th>Enrolled On</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach ($enrolled_classes as $class): ?>
                                    <tr>
                                        <td><?php echo sanitize($class['name']); ?></td>
                                        <td><?php echo sanitize($class['teacher_name']); ?></td>
                                        <td><?php echo formatCurrency($class['monthly_fee']); ?></td>
                                        <td><?php echo formatDate($class['enrollment_date']); ?></td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Remove student from this class?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="unenroll_from_class" value="1">
                                                <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-book-x"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($enrolled_classes)) echo '<tr><td colspan="5" class="text-center py-4 text-muted">Not enrolled in any classes</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="enrollClassModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Enroll in Class</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="enroll_in_class" value="1">
                                <div class="mb-3">
                                    <label class="form-label">Select Class</label>
                                    <select name="class_id" class="form-select" required>
                                        <option value="">Select Class</option>
                                        <?php
                                        $all_classes = $pdo->query("SELECT c.id, c.name, t.name as teacher_name FROM classes c JOIN teachers t ON c.teacher_id = t.id WHERE c.status = 'active' ORDER BY c.name")->fetchAll();
                                        foreach ($all_classes as $c):
                                            $already_enrolled = false;
                                            foreach ($enrolled_classes as $ec) {
                                                if ($ec['id'] == $c['id']) { $already_enrolled = true; break; }
                                            }
                                            if (!$already_enrolled):
                                        ?>
                                            <option value="<?php echo $c['id']; ?>"><?php echo sanitize($c['name'] . ' - ' . $c['teacher_name']); ?></option>
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
                                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Enroll</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card card-custom">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="bi bi-credit-card me-2 text-primary"></i>Payment History</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead><tr><th>Receipt #</th><th>Class</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($payment_history as $payment): ?>
                                    <tr>
                                        <td><?php echo sanitize($payment['receipt_number']); ?></td>
                                        <td><?php echo sanitize($payment['class_name']); ?></td>
                                        <td><?php echo formatCurrency($payment['paid_amount']); ?></td>
                                        <td><?php echo formatDate($payment['payment_date']); ?></td>
                                        <td><span class="badge bg-<?php echo $payment['status'] === 'completed' ? 'success' : ($payment['status'] === 'pending' ? 'warning' : 'secondary'); ?>"><?php echo ucfirst($payment['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payment_history)) echo '<tr><td colspan="5" class="text-center py-4 text-muted">No payment history</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($edit_id && $edit_student): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit Student</h2>
        <a href="students.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Cancel</a>
    </div>
    <div class="card card-custom">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Student ID</label>
                        <input type="text" class="form-control" value="<?php echo sanitize($edit_student['student_id']); ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" value="<?php echo sanitize($edit_student['full_name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo sanitize($edit_student['email']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo sanitize($edit_student['phone']); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2"><?php echo sanitize($edit_student['address']); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Guardian Name</label>
                        <input type="text" name="guardian_name" class="form-control" value="<?php echo sanitize($edit_student['guardian_name']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Guardian Phone</label>
                        <input type="text" name="guardian_phone" class="form-control" value="<?php echo sanitize($edit_student['guardian_phone']); ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" name="edit_student" class="btn btn-primary btn-action"><i class="bi bi-save me-2"></i>Update Student</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Students</h2>
        <button class="btn btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#addStudentModal"><i class="bi bi-plus-lg me-2"></i>Add Student</button>
    </div>

    <div class="card card-custom mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" placeholder="Search by name, ID, or phone..." value="<?php echo sanitize($search); ?>">
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
                    <thead><tr><th>Student ID</th><th>Name</th><th>Phone</th><th>Guardian</th><th>QR Card</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?php echo sanitize($student['student_id']); ?></td>
                                <td><?php echo sanitize($student['full_name']); ?></td>
                                <td><?php echo sanitize($student['phone'] ?: 'N/A'); ?></td>
                                <td><?php echo sanitize($student['guardian_name'] ?: 'N/A'); ?></td>
                                <td>
                                    <?php if ($student['card_count'] > 0): ?>
                                        <span class="badge bg-success"><i class="bi bi-qr-code me-1"></i>Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">None</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-<?php echo $student['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($student['status']); ?></span></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="?id=<?php echo $student['id']; ?>&action=view" class="btn btn-info"><i class="bi bi-eye"></i></a>
                                        <a href="?edit=<?php echo $student['id']; ?>" class="btn btn-primary"><i class="bi bi-pencil"></i></a>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="toggle_status" value="1">
                                            <button type="submit" class="btn btn-<?php echo $student['status'] === 'active' ? 'warning' : 'success'; ?>" title="<?php echo $student['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="bi bi-<?php echo $student['status'] === 'active' ? 'pause' : 'play'; ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($students)) echo '<tr><td colspan="7" class="text-center py-4 text-muted">No students found</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Student ID</label>
                            <input type="text" name="student_id" class="form-control" value="<?php echo sanitize(generateStudentId($pdo)); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Guardian Name</label>
                            <input type="text" name="guardian_name" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Guardian Phone</label>
                            <input type="text" name="guardian_phone" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_student" class="btn btn-primary"><i class="bi bi-save me-2"></i>Save Student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

