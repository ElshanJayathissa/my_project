<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'Teachers Management';
$pdo = db();
$user_id = $_SESSION['user_id'];

$action = $_GET['action'] ?? 'list';
$teacher_id = $_GET['id'] ?? null;
$edit_id = $_GET['edit'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        flashMessage('error', 'Invalid CSRF token');
        redirect('teachers.php');
    }

    if (isset($_POST['add_teacher'])) {
        $missing = validateRequired(['name']);
        if (!empty($missing)) {
            flashMessage('error', 'Please fill: ' . implode(', ', $missing));
        } else {
            $stmt = $pdo->prepare("INSERT INTO teachers (name, email, phone, commission_percentage) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                sanitize($_POST['name']),
                sanitize($_POST['email']),
                sanitize($_POST['phone']),
                $_POST['commission_percentage'] ?? 80
            ]);
            logAudit($pdo, $user_id, 'add_teacher', 'Added teacher: ' . $_POST['name']);
            flashMessage('success', 'Teacher added successfully');
            redirect('teachers.php');
        }
    }

    if (isset($_POST['edit_teacher']) && $edit_id) {
        $stmt = $pdo->prepare("UPDATE teachers SET name = ?, email = ?, phone = ?, commission_percentage = ? WHERE id = ?");
        $stmt->execute([
            sanitize($_POST['name']),
            sanitize($_POST['email']),
            sanitize($_POST['phone']),
            $_POST['commission_percentage'],
            $edit_id
        ]);
        logAudit($pdo, $user_id, 'edit_teacher', 'Updated teacher ID: ' . $edit_id);
        flashMessage('success', 'Teacher updated successfully');
        redirect('teachers.php');
    }

    if (isset($_POST['toggle_status']) && $teacher_id) {
        $stmt = $pdo->prepare("UPDATE teachers SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
        $stmt->execute([$teacher_id]);
        logAudit($pdo, $user_id, 'toggle_teacher_status', 'Toggled teacher ID: ' . $teacher_id);
        flashMessage('success', 'Teacher status updated');
        redirect('teachers.php');
    }
}

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$query = "SELECT t.*, COUNT(DISTINCT c.id) as class_count FROM teachers t LEFT JOIN classes c ON t.id = c.teacher_id WHERE 1=1";
$params = [];
if ($search) {
    $query .= " AND (t.name LIKE ? OR t.email LIKE ? OR t.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
}
$query .= " GROUP BY t.id ORDER BY t.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$teachers_list = $stmt->fetchAll();

$view_teacher = null;
$teacher_classes = [];
$teacher_income = [];
if ($action === 'view' && $teacher_id) {
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $view_teacher = $stmt->fetch();
    if ($view_teacher) {
        $stmt = $pdo->prepare("SELECT c.*, COUNT(DISTINCT ce.student_id) as student_count FROM classes c LEFT JOIN class_enrollments ce ON c.id = ce.class_id WHERE c.teacher_id = ? GROUP BY c.id");
        $stmt->execute([$teacher_id]);
        $teacher_classes = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT SUM(p.paid_amount) as total_collected,
                   SUM(p.paid_amount * (t.commission_percentage / 100)) as teacher_share,
                   SUM(p.paid_amount * ((100 - t.commission_percentage) / 100)) as institute_share,
                   COUNT(*) as payment_count
            FROM payments p
            JOIN classes c ON p.class_id = c.id
            JOIN teachers t ON c.teacher_id = t.id
            WHERE c.teacher_id = ? AND p.status = 'completed'
        ");
        $stmt->execute([$teacher_id]);
        $teacher_income = $stmt->fetch();
    }
}

$edit_teacher = null;
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_teacher = $stmt->fetch();
}

include '../includes/header.php';
?>

<?php if ($action === 'view' && $view_teacher): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Teacher Details</h2>
        <a href="teachers.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Back to List</a>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card card-custom mb-4">
                <div class="card-body text-center">
                    <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:100px;height:100px;">
                        <i class="bi bi-person-badge fs-1 text-warning"></i>
                    </div>
                    <h4><?php echo sanitize($view_teacher['name']); ?></h4>
                    <p class="text-muted"><?php echo sanitize($view_teacher['email'] ?: 'No email'); ?></p>
                    <span class="badge bg-<?php echo $view_teacher['status'] === 'active' ? 'success' : 'secondary'; ?> mb-2">
                        <?php echo ucfirst($view_teacher['status']); ?>
                    </span>
                    <p class="mb-0"><small class="text-muted">Commission: <?php echo $view_teacher['commission_percentage']; ?>%</small></p>
                </div>
            </div>
            <div class="card card-custom mb-4">
                <div class="card-header bg-white py-3"><h6 class="mb-0">Income Summary</h6></div>
                <div class="card-body">
                    <div class="mb-2"><small class="text-muted">Total Collected</small><h4 class="mb-0"><?php echo formatCurrency($teacher_income['total_collected'] ?? 0); ?></h4></div>
                    <div class="mb-2"><small class="text-muted">Teacher Share (<?php echo $view_teacher['commission_percentage']; ?>%)</small><h5 class="text-success mb-0"><?php echo formatCurrency($teacher_income['teacher_share'] ?? 0); ?></h5></div>
                    <div class="mb-0"><small class="text-muted">Institute Share</small><h5 class="text-primary mb-0"><?php echo formatCurrency($teacher_income['institute_share'] ?? 0); ?></h5></div>
                </div>
            </div>
            <div class="card card-custom">
                <div class="card-header bg-white py-3"><h6 class="mb-0">Actions</h6></div>
                <div class="card-body d-grid gap-2">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="toggle_status" value="1">
                        <button type="submit" class="btn btn-<?php echo $view_teacher['status'] === 'active' ? 'warning' : 'success'; ?> btn-action w-100">
                            <i class="bi bi-<?php echo $view_teacher['status'] === 'active' ? 'pause' : 'play'; ?> me-2"></i>
                            <?php echo $view_teacher['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                    <a href="?edit=<?php echo $view_teacher['id']; ?>" class="btn btn-primary btn-action"><i class="bi bi-pencil me-2"></i>Edit Teacher</a>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card card-custom mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="bi bi-book me-2 text-primary"></i>Assigned Classes (<?php echo count($teacher_classes); ?>)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead><tr><th>Class</th><th>Monthly Fee</th><th>Students</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($teacher_classes as $class): ?>
                                    <tr>
                                        <td><?php echo sanitize($class['name']); ?></td>
                                        <td><?php echo formatCurrency($class['monthly_fee']); ?></td>
                                        <td><?php echo $class['student_count']; ?></td>
                                        <td><span class="badge bg-<?php echo $class['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($class['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($teacher_classes)) echo '<tr><td colspan="4" class="text-center py-4 text-muted">No classes assigned</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($edit_id && $edit_teacher): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit Teacher</h2>
        <a href="teachers.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Cancel</a>
    </div>
    <div class="card card-custom">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="name" class="form-control" value="<?php echo sanitize($edit_teacher['name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo sanitize($edit_teacher['email']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo sanitize($edit_teacher['phone']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Commission Percentage (%)</label>
                        <input type="number" step="0.01" name="commission_percentage" class="form-control" value="<?php echo $edit_teacher['commission_percentage']; ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" name="edit_teacher" class="btn btn-primary btn-action"><i class="bi bi-save me-2"></i>Update Teacher</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Teachers</h2>
        <button class="btn btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#addTeacherModal"><i class="bi bi-plus-lg me-2"></i>Add Teacher</button>
    </div>

    <div class="card card-custom mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" placeholder="Search by name, email, or phone..." value="<?php echo sanitize($search); ?>">
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
                    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Commission</th><th>Classes</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($teachers_list as $teacher): ?>
                            <tr>
                                <td><?php echo sanitize($teacher['name']); ?></td>
                                <td><?php echo sanitize($teacher['email'] ?: 'N/A'); ?></td>
                                <td><?php echo sanitize($teacher['phone'] ?: 'N/A'); ?></td>
                                <td><?php echo $teacher['commission_percentage']; ?>%</td>
                                <td><?php echo $teacher['class_count']; ?></td>
                                <td><span class="badge bg-<?php echo $teacher['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($teacher['status']); ?></span></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="?id=<?php echo $teacher['id']; ?>&action=view" class="btn btn-info"><i class="bi bi-eye"></i></a>
                                        <a href="?edit=<?php echo $teacher['id']; ?>" class="btn btn-primary"><i class="bi bi-pencil"></i></a>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="toggle_status" value="1">
                                            <button type="submit" class="btn btn-<?php echo $teacher['status'] === 'active' ? 'warning' : 'success'; ?>" title="<?php echo $teacher['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="bi bi-<?php echo $teacher['status'] === 'active' ? 'pause' : 'play'; ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($teachers_list)) echo '<tr><td colspan="7" class="text-center py-4 text-muted">No teachers found</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="addTeacherModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Teacher</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Commission Percentage (%)</label>
                            <input type="number" step="0.01" name="commission_percentage" class="form-control" value="80">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_teacher" class="btn btn-primary"><i class="bi bi-save me-2"></i>Save Teacher</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

