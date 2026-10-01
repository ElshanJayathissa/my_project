<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = db();
$csrf_token = csrf_token();

$status_filter = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
    } else {
        $status_filter = sanitize($_POST['status'] ?? '');
    }
}

$sql = "
    SELECT s.*, 
           COUNT(DISTINCT ce.class_id) as enrolled_count,
           COALESCE(o.outstanding, 0) as outstanding
    FROM students s
    LEFT JOIN class_enrollments ce ON s.id = ce.student_id AND ce.status = 'active'
    LEFT JOIN (
        SELECT p.student_id, SUM(pi.amount_due - pi.amount_paid) as outstanding
        FROM payment_items pi
        JOIN payments p ON pi.payment_id = p.id
        WHERE pi.status IN ('unpaid','partial')
        GROUP BY p.student_id
    ) o ON s.id = o.student_id
";
$params = [];
if ($status_filter !== '') {
    $sql .= " WHERE s.status = ?";
    $params[] = $status_filter;
}
$sql .= " GROUP BY s.id ORDER BY s.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

$total_students = count($results);
$active_students = 0;
$inactive_students = 0;
$total_outstanding = 0;
foreach ($results as $row) {
    if ($row['status'] === 'active') $active_students++;
    else $inactive_students++;
    $total_outstanding += $row['outstanding'];
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!verify_csrf($_GET['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_report.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'Full Name', 'Status', 'Enrolled Classes', 'Outstanding (Rs.)']);
    foreach ($results as $row) {
        fputcsv($output, [
            $row['student_id'],
            $row['full_name'],
            ucfirst($row['status']),
            $row['enrolled_count'],
            number_format($row['outstanding'], 2)
        ]);
    }
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card card-custom stat-card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="text-muted mb-1">Total Students</p>
                    <h3 class="mb-0"><?php echo $total_students; ?></h3>
                </div>
                <i class="bi bi-people text-primary fs-1"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-custom stat-card p-3" style="border-left-color: #28a745;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="text-muted mb-1">Active Students</p>
                    <h3 class="mb-0"><?php echo $active_students; ?></h3>
                </div>
                <i class="bi bi-check-circle text-success fs-1"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-custom stat-card p-3" style="border-left-color: #dc3545;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="text-muted mb-1">Inactive Students</p>
                    <h3 class="mb-0"><?php echo $inactive_students; ?></h3>
                </div>
                <i class="bi bi-x-circle text-danger fs-1"></i>
            </div>
        </div>
    </div>
</div>

<div class="card card-custom p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Student Report</h4>
        <a href="?export=csv&csrf_token=<?php echo $csrf_token; ?>&status=<?php echo urlencode($status_filter); ?>" class="btn btn-success">
            <i class="bi bi-download me-1"></i>Export CSV
        </a>
    </div>
    <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
    </form>
</div>

<?php if (!empty($results)): ?>
<div class="card card-custom p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>Students</h5>
        <span class="text-muted">Total Outstanding: <?php echo formatCurrency($total_outstanding); ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-custom">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Full Name</th>
                    <th>Status</th>
                    <th>Enrolled Classes</th>
                    <th>Outstanding</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row): ?>
                <tr>
                    <td><?php echo sanitize($row['student_id']); ?></td>
                    <td><?php echo sanitize($row['full_name']); ?></td>
                    <td>
                        <span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'secondary'; ?>">
                            <?php echo ucfirst($row['status']); ?>
                        </span>
                    </td>
                    <td><?php echo $row['enrolled_count']; ?></td>
                    <td><?php echo formatCurrency($row['outstanding']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info">No students found.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

