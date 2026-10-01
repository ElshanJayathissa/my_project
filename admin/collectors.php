<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'Collectors Management';
$pdo = db();
$user_id = $_SESSION['user_id'];

$action = $_GET['action'] ?? 'list';
$collector_id = $_GET['id'] ?? null;
$edit_id = $_GET['edit'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        flashMessage('error', 'Invalid CSRF token');
        redirect('collectors.php');
    }

    if (isset($_POST['add_collector'])) {
        $missing = validateRequired(['username', 'full_name', 'password']);
        if (!empty($missing)) {
            flashMessage('error', 'Please fill: ' . implode(', ', $missing));
        } else {
            $hashed = generatePassword($_POST['password']);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, phone, role) VALUES (?, ?, ?, ?, ?, 'collector')");
            $stmt->execute([
                sanitize($_POST['username']),
                sanitize($_POST['email']),
                $hashed,
                sanitize($_POST['full_name']),
                sanitize($_POST['phone'])
            ]);
            logAudit($pdo, $user_id, 'add_collector', 'Added collector: ' . $_POST['full_name']);
            flashMessage('success', 'Collector added successfully');
            redirect('collectors.php');
        }
    }

    if (isset($_POST['edit_collector']) && $edit_id) {
        $sql = "UPDATE users SET full_name = ?, email = ?, phone = ?";
        $params = [sanitize($_POST['full_name']), sanitize($_POST['email']), sanitize($_POST['phone'])];
        if (!empty($_POST['password'])) {
            $sql .= ", password = ?";
            $params[] = generatePassword($_POST['password']);
        }
        $sql .= " WHERE id = ? AND role = 'collector'";
        $params[] = $edit_id;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        logAudit($pdo, $user_id, 'edit_collector', 'Updated collector ID: ' . $edit_id);
        flashMessage('success', 'Collector updated successfully');
        redirect('collectors.php');
    }

    if (isset($_POST['toggle_status']) && $collector_id) {
        $stmt = $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ? AND role = 'collector'");
        $stmt->execute([$collector_id]);
        logAudit($pdo, $user_id, 'toggle_collector_status', 'Toggled collector ID: ' . $collector_id);
        flashMessage('success', 'Collector status updated');
        redirect('collectors.php');
    }
}

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$query = "SELECT u.*, COUNT(p.id) as payment_count, SUM(p.paid_amount) as total_collected FROM users u LEFT JOIN payments p ON u.id = p.collector_id AND p.status = 'completed' WHERE u.role = 'collector'";
$params = [];
if ($search) {
    $query .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $query .= " AND u.status = ?";
    $params[] = $status_filter;
}
$query .= " GROUP BY u.id ORDER BY u.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$collectors = $stmt->fetchAll();

$view_collector = null;
$collection_history = [];
if ($action === 'view' && $collector_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'collector'");
    $stmt->execute([$collector_id]);
    $view_collector = $stmt->fetch();
    if ($view_collector) {
        $stmt = $pdo->prepare("
            SELECT p.*, s.full_name, s.student_id, c.name as class_name
            FROM payments p
            JOIN students s ON p.student_id = s.id
            JOIN classes c ON p.class_id = c.id
            WHERE p.collector_id = ? AND p.status = 'completed'
            ORDER BY p.payment_date DESC
            LIMIT 50
        ");
        $stmt->execute([$collector_id]);
        $collection_history = $stmt->fetchAll();
    }
}

$edit_collector = null;
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'collector'");
    $stmt->execute([$edit_id]);
    $edit_collector = $stmt->fetch();
}

include '../includes/header.php';
?>

<?php if ($action === 'view' && $view_collector): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Collector Details</h2>
        <a href="collectors.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Back to List</a>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card card-custom mb-4">
                <div class="card-body text-center">
                    <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:100px;height:100px;">
                        <i class="bi bi-person-check fs-1 text-info"></i>
                    </div>
                    <h4><?php echo sanitize($view_collector['full_name']); ?></h4>
                    <p class="text-muted">@<?php echo sanitize($view_collector['username']); ?></p>
                    <p class="text-muted"><?php echo sanitize($view_collector['email'] ?: 'No email'); ?></p>
                    <span class="badge bg-<?php echo $view_collector['status'] === 'active' ? 'success' : 'secondary'; ?>">
                        <?php echo ucfirst($view_collector['status']); ?>
                    </span>
                </div>
            </div>
            <div class="card card-custom">
                <div class="card-header bg-white py-3"><h6 class="mb-0">Actions</h6></div>
                <div class="card-body d-grid gap-2">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="toggle_status" value="1">
                        <button type="submit" class="btn btn-<?php echo $view_collector['status'] === 'active' ? 'warning' : 'success'; ?> btn-action w-100">
                            <i class="bi bi-<?php echo $view_collector['status'] === 'active' ? 'pause' : 'play'; ?> me-2"></i>
                            <?php echo $view_collector['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                    <a href="?edit=<?php echo $view_collector['id']; ?>" class="btn btn-primary btn-action"><i class="bi bi-pencil me-2"></i>Edit Collector</a>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card card-custom">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Collection History</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead><tr><th>Receipt #</th><th>Student</th><th>Class</th><th>Amount</th><th>Date</th></tr></thead>
                            <tbody>
                                <?php foreach ($collection_history as $payment): ?>
                                    <tr>
                                        <td><?php echo sanitize($payment['receipt_number']); ?></td>
                                        <td><?php echo sanitize($payment['full_name']); ?></td>
                                        <td><?php echo sanitize($payment['class_name']); ?></td>
                                        <td><?php echo formatCurrency($payment['paid_amount']); ?></td>
                                        <td><?php echo formatDate($payment['payment_date']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($collection_history)) echo '<tr><td colspan="5" class="text-center py-4 text-muted">No collection history</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($edit_id && $edit_collector): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Edit Collector</h2>
        <a href="collectors.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Cancel</a>
    </div>
    <div class="card card-custom">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?php echo sanitize($edit_collector['username']); ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" value="<?php echo sanitize($edit_collector['full_name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo sanitize($edit_collector['email']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo sanitize($edit_collector['phone']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">New Password (leave blank to keep)</label>
                        <input type="password" name="password" class="form-control">
                    </div>
                    <div class="col-12">
                        <button type="submit" name="edit_collector" class="btn btn-primary btn-action"><i class="bi bi-save me-2"></i>Update Collector</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Collectors</h2>
        <button class="btn btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#addCollectorModal"><i class="bi bi-plus-lg me-2"></i>Add Collector</button>
    </div>

    <div class="card card-custom mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" placeholder="Search by name, username, or email..." value="<?php echo sanitize($search); ?>">
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
                    <thead><tr><th>Username</th><th>Full Name</th><th>Email</th><th>Phone</th><th>Payments</th><th>Collected</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($collectors as $collector): ?>
                            <tr>
                                <td><?php echo sanitize($collector['username']); ?></td>
                                <td><?php echo sanitize($collector['full_name']); ?></td>
                                <td><?php echo sanitize($collector['email'] ?: 'N/A'); ?></td>
                                <td><?php echo sanitize($collector['phone'] ?: 'N/A'); ?></td>
                                <td><?php echo $collector['payment_count'] ?? 0; ?></td>
                                <td><?php echo formatCurrency($collector['total_collected'] ?? 0); ?></td>
                                <td><span class="badge bg-<?php echo $collector['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($collector['status']); ?></span></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="?id=<?php echo $collector['id']; ?>&action=view" class="btn btn-info"><i class="bi bi-eye"></i></a>
                                        <a href="?edit=<?php echo $collector['id']; ?>" class="btn btn-primary"><i class="bi bi-pencil"></i></a>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="toggle_status" value="1">
                                            <button type="submit" class="btn btn-<?php echo $collector['status'] === 'active' ? 'warning' : 'success'; ?>" title="<?php echo $collector['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="bi bi-<?php echo $collector['status'] === 'active' ? 'pause' : 'play'; ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($collectors)) echo '<tr><td colspan="8" class="text-center py-4 text-muted">No collectors found</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="addCollectorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Collector</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" required>
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
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_collector" class="btn btn-primary"><i class="bi bi-save me-2"></i>Save Collector</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

