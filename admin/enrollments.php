<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'Student Enrollments';
$pdo = db();
$user_id = $_SESSION['user_id'];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        $error = 'Invalid CSRF token.';
    } elseif (isset($_POST['enroll_student'])) {
        $student_id = intval($_POST['student_id']);
        $class_id = intval($_POST['class_id']);
        $enrollment_date = $_POST['enrollment_date'] ?: date('Y-m-d');

        if (!$student_id || !$class_id) {
            $error = 'Please select both student and class.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO class_enrollments (student_id, class_id, enrollment_date, status) VALUES (?, ?, ?, 'active') ON DUPLICATE KEY UPDATE status = 'active', enrollment_date = VALUES(enrollment_date), updated_at = NOW()");
            $stmt->execute([$student_id, $class_id, $enrollment_date]);
            logAudit($pdo, $user_id, 'enroll_student', 'Enrolled student ID: ' . $student_id . ' in class ID: ' . $class_id);
            $success = 'Student enrolled successfully!';
        }
    } elseif (isset($_POST['unenroll_student'])) {
        $student_id = intval($_POST['student_id']);
        $class_id = intval($_POST['class_id']);
        $stmt = $pdo->prepare("UPDATE class_enrollments SET status = 'inactive' WHERE student_id = ? AND class_id = ? AND status = 'active'");
        $stmt->execute([$student_id, $class_id]);
        logAudit($pdo, $user_id, 'unenroll_student', 'Removed student ID: ' . $student_id . ' from class ID: ' . $class_id);
        $success = 'Student removed from class successfully!';
    }
}

$search_student = $_GET['search_student'] ?? '';
$filter_class = $_GET['filter_class'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

$query = "SELECT ce.*, s.id as student_numeric_id, s.student_id, s.full_name, s.phone, c.name as class_name, t.name as teacher_name
          FROM class_enrollments ce
          JOIN students s ON ce.student_id = s.id
          JOIN classes c ON ce.class_id = c.id
          LEFT JOIN teachers t ON c.teacher_id = t.id
          WHERE 1=1";
$params = [];

if ($search_student) {
    $query .= " AND (s.full_name LIKE ? OR s.student_id LIKE ? OR s.phone LIKE ?)";
    $params[] = "%$search_student%";
    $params[] = "%$search_student%";
    $params[] = "%$search_student%";
}
if ($filter_class) {
    $query .= " AND c.id = ?";
    $params[] = $filter_class;
}
if ($filter_status) {
    $query .= " AND ce.status = ?";
    $params[] = $filter_status;
}

$query .= " ORDER BY ce.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$enrollments = $stmt->fetchAll();

$classes = $pdo->query("SELECT c.id, c.name, t.name as teacher_name FROM classes c JOIN teachers t ON c.teacher_id = t.id WHERE c.status = 'active' ORDER BY c.name")->fetchAll();

include '../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-person-plus me-2 text-primary"></i>Enroll Student in Class</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="col-md-4">
                        <label class="form-label">Search Student *</label>
                        <input type="text" name="search_student_select" class="form-control" placeholder="Type name, ID or phone..." id="studentSearch" autocomplete="off" required>
                        <input type="hidden" name="student_id" id="selectedStudentId" required>
                        <div id="studentSearchResults" class="list-group mt-1" style="position:absolute;z-index:1000;width:calc(33.333% - 12px);max-height:200px;overflow-y:auto;display:none;"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Select Class *</label>
                        <select name="class_id" class="form-select" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo sanitize($class['name'] . ' - ' . $class['teacher_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Enrollment Date</label>
                        <input type="date" name="enrollment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" name="enroll_student" class="btn btn-primary w-100">
                            <i class="bi bi-person-plus"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-list me-2 text-primary"></i>All Enrollments</h5>
                <form method="GET" class="d-flex gap-2">
                    <select name="filter_class" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $filter_class == $class['id'] ? 'selected' : ''; ?>><?php echo sanitize($class['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="filter_status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom mb-0">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Class</th>
                                <th>Teacher</th>
                                <th>Enrolled On</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($enrollments as $enrollment): ?>
                                <tr>
                                    <td><?php echo sanitize($enrollment['student_id']); ?></td>
                                    <td>
                                        <?php echo sanitize($enrollment['full_name']); ?>
                                        <?php if ($enrollment['phone']): ?>
                                            <br><small class="text-muted"><?php echo sanitize($enrollment['phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo sanitize($enrollment['class_name']); ?></td>
                                    <td><?php echo sanitize($enrollment['teacher_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo formatDate($enrollment['enrollment_date']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $enrollment['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($enrollment['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($enrollment['status'] === 'active'): ?>
                                            <form method="POST" onsubmit="return confirm('Remove this student from class?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="unenroll_student" value="1">
                                                <input type="hidden" name="student_id" value="<?php echo $enrollment['student_numeric_id']; ?>">
                                                <input type="hidden" name="class_id" value="<?php echo $enrollment['class_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-person-x"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" onsubmit="return confirm('Re-enroll this student?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="enroll_student" value="1">
                                                <input type="hidden" name="student_id" value="<?php echo $enrollment['student_numeric_id']; ?>">
                                                <input type="hidden" name="class_id" value="<?php echo $enrollment['class_id']; ?>">
                                                <input type="hidden" name="enrollment_date" value="<?php echo $enrollment['enrollment_date']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success">
                                                    <i class="bi bi-person-plus"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($enrollments)) echo '<tr><td colspan="7" class="text-center py-4 text-muted">No enrollments found</td></tr>'; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('studentSearch').addEventListener('input', function() {
    const query = this.value.trim();
    const resultsDiv = document.getElementById('studentSearchResults');
    const hiddenInput = document.getElementById('selectedStudentId');

    if (query.length < 2) {
        resultsDiv.style.display = 'none';
        hiddenInput.value = '';
        return;
    }

    fetch('../api/lookup.php?q=' + encodeURIComponent(query) + '&type=student')
        .then(function(response) { 
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json(); 
        })
        .then(function(data) {
            resultsDiv.innerHTML = '';
            if (data.success && data.students.length > 0) {
                data.students.forEach(function(student) {
                    const item = document.createElement('a');
                    item.href = '#';
                    item.className = 'list-group-item list-group-item-action';
                    item.innerHTML = '<strong>' + student.student_id + '</strong> - ' + student.full_name + ' <small class="text-muted">' + (student.phone || '') + '</small>';
                    item.addEventListener('click', function(e) {
                        e.preventDefault();
                        document.getElementById('studentSearch').value = student.full_name + ' (' + student.student_id + ')';
                        document.getElementById('selectedStudentId').value = student.id;
                        resultsDiv.style.display = 'none';
                    });
                    resultsDiv.appendChild(item);
                });
                resultsDiv.style.display = 'block';
            } else {
                resultsDiv.innerHTML = '<span class="list-group-item text-muted">No students found</span>';
                resultsDiv.style.display = 'block';
            }
        })
        .catch(function(error) {
            console.error('Search error:', error);
            resultsDiv.innerHTML = '<span class="list-group-item text-danger">Error: ' + error.message + '</span>';
            resultsDiv.style.display = 'block';
        });
});

document.addEventListener('click', function(e) {
    const resultsDiv = document.getElementById('studentSearchResults');
    const searchInput = document.getElementById('studentSearch');
    if (!resultsDiv.contains(e.target) && e.target !== searchInput) {
        resultsDiv.style.display = 'none';
    }
});
</script>

<?php include '../includes/footer.php'; ?>
