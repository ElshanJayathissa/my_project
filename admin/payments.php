<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'Payments Management';
$pdo = db();
$user_id = $_SESSION['user_id'];

$action = $_GET['action'] ?? 'list';
$payment_id = $_GET['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        flashMessage('error', 'Invalid CSRF token');
        redirect('payments.php');
    }

    if (isset($_POST['refund_payment']) && $payment_id) {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch();
        if ($payment && $payment['status'] === 'completed') {
            $stmt = $pdo->prepare("UPDATE payments SET status = 'refunded', notes = CONCAT(IFNULL(notes, ''), '\nRefunded by admin on ', NOW()) WHERE id = ?");
            $stmt->execute([$payment_id]);
            $stmt = $pdo->prepare("UPDATE payment_items SET status = 'unpaid', amount_paid = 0 WHERE payment_id = ?");
            $stmt->execute([$payment_id]);
            logAudit($pdo, $user_id, 'refund_payment', 'Refunded payment ID: ' . $payment_id . ', amount: ' . $payment['paid_amount']);
            flashMessage('success', 'Payment refunded successfully');
        } else {
            flashMessage('error', 'Payment cannot be refunded');
        }
        redirect('payments.php?id=' . $payment_id . '&action=view');
    }

    if (isset($_POST['cancel_payment']) && $payment_id) {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch();
        if ($payment && $payment['status'] === 'pending') {
            $stmt = $pdo->prepare("UPDATE payments SET status = 'cancelled', notes = CONCAT(IFNULL(notes, ''), '\nCancelled by admin on ', NOW()) WHERE id = ?");
            $stmt->execute([$payment_id]);
            logAudit($pdo, $user_id, 'cancel_payment', 'Cancelled payment ID: ' . $payment_id);
            flashMessage('success', 'Payment cancelled successfully');
        } else {
            flashMessage('error', 'Payment cannot be cancelled');
        }
        redirect('payments.php?id=' . $payment_id . '&action=view');
    }
}

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$class_filter = $_GET['class'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$query = "SELECT p.*, s.full_name, s.student_id, c.name as class_name, u.full_name as collector_name
          FROM payments p
          JOIN students s ON p.student_id = s.id
          JOIN classes c ON p.class_id = c.id
          LEFT JOIN users u ON p.collector_id = u.id
          WHERE 1=1";
$params = [];
if ($search) {
    $query .= " AND (s.full_name LIKE ? OR s.student_id LIKE ? OR p.receipt_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $query .= " AND p.status = ?";
    $params[] = $status_filter;
}
if ($class_filter) {
    $query .= " AND p.class_id = ?";
    $params[] = $class_filter;
}
if ($date_from) {
    $query .= " AND p.payment_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $query .= " AND p.payment_date <= ?";
    $params[] = $date_to;
}
$query .= " ORDER BY p.created_at DESC LIMIT 200";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$view_payment = null;
$payment_items = [];
if ($action === 'view' && $payment_id) {
    $stmt = $pdo->prepare("
        SELECT p.*, s.full_name, s.student_id, s.email, s.phone, c.name as class_name, u.full_name as collector_name
        FROM payments p
        JOIN students s ON p.student_id = s.id
        JOIN classes c ON p.class_id = c.id
        LEFT JOIN users u ON p.collector_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$payment_id]);
    $view_payment = $stmt->fetch();
    if ($view_payment) {
        $stmt = $pdo->prepare("SELECT * FROM payment_items WHERE payment_id = ?");
        $stmt->execute([$payment_id]);
        $payment_items = $stmt->fetchAll();
    }
}

$classes = $pdo->query("SELECT id, name FROM classes WHERE status = 'active' ORDER BY name")->fetchAll();

include '../includes/header.php';
?>

<?php if ($action === 'view' && $view_payment): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Payment Details</h2>
        <a href="payments.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Back to List</a>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card card-custom mb-4">
                <div class="card-header bg-white py-3"><h5 class="mb-0">Payment Information</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6"><strong>Receipt #:</strong> <?php echo sanitize($view_payment['receipt_number']); ?></div>
                        <div class="col-sm-6"><strong>Date:</strong> <?php echo formatDate($view_payment['payment_date']); ?></div>
                        <div class="col-sm-6"><strong>Total Amount:</strong> <?php echo formatCurrency($view_payment['total_amount']); ?></div>
                        <div class="col-sm-6"><strong>Paid Amount:</strong> <strong class="text-success"><?php echo formatCurrency($view_payment['paid_amount']); ?></strong></div>
                        <div class="col-sm-6"><strong>Status:</strong>
                            <span class="badge bg-<?php echo $view_payment['status'] === 'completed' ? 'success' : ($view_payment['status'] === 'pending' ? 'warning' : ($view_payment['status'] === 'refunded' ? 'danger' : 'secondary')); ?>">
                                <?php echo ucfirst($view_payment['status']); ?>
                            </span>
                        </div>
                        <div class="col-sm-6"><strong>Collector:</strong> <?php echo sanitize($view_payment['collector_name'] ?: 'N/A'); ?></div>
                        <?php if ($view_payment['notes']): ?>
                            <div class="col-12"><strong>Notes:</strong> <?php echo sanitize($view_payment['notes']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card card-custom">
                <div class="card-header bg-white py-3"><h5 class="mb-0">Student Information</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6"><strong>Name:</strong> <?php echo sanitize($view_payment['full_name']); ?></div>
                        <div class="col-sm-6"><strong>Student ID:</strong> <?php echo sanitize($view_payment['student_id']); ?></div>
                        <div class="col-sm-6"><strong>Email:</strong> <?php echo sanitize($view_payment['email'] ?: 'N/A'); ?></div>
                        <div class="col-sm-6"><strong>Phone:</strong> <?php echo sanitize($view_payment['phone'] ?: 'N/A'); ?></div>
                        <div class="col-12"><strong>Class:</strong> <?php echo sanitize($view_payment['class_name']); ?></div>
                    </div>
                </div>
            </div>
            <div class="card card-custom mt-4">
                <div class="card-header bg-white py-3"><h6 class="mb-0">Actions</h6></div>
                <div class="card-body d-grid gap-2">
                    <?php if ($view_payment['status'] === 'completed'): ?>
                        <form method="POST" onsubmit="return confirm('Refund this payment?');">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="refund_payment" value="1">
                            <button type="submit" class="btn btn-danger btn-action w-100"><i class="bi bi-arrow-return-left me-2"></i>Refund Payment</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($view_payment['status'] === 'pending'): ?>
                        <form method="POST" onsubmit="return confirm('Cancel this payment?');">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="cancel_payment" value="1">
                            <button type="submit" class="btn btn-secondary btn-action w-100"><i class="bi bi-x-circle me-2"></i>Cancel Payment</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card card-custom">
                <div class="card-header bg-white py-3"><h5 class="mb-0">Billing Items</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead><tr><th>Month</th><th>Year</th><th>Due</th><th>Paid</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($payment_items as $item): ?>
                                    <tr>
                                        <td><?php echo date('F', mktime(0, 0, 0, $item['billing_month'], 1)); ?></td>
                                        <td><?php echo $item['billing_year']; ?></td>
                                        <td><?php echo formatCurrency($item['amount_due']); ?></td>
                                        <td><?php echo formatCurrency($item['amount_paid']); ?></td>
                                        <td><span class="badge bg-<?php echo $item['status'] === 'paid' ? 'success' : ($item['status'] === 'partial' ? 'info' : 'warning'); ?>"><?php echo ucfirst($item['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payment_items)) echo '<tr><td colspan="5" class="text-center py-4 text-muted">No billing items</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Payments</h2>
    </div>

    <div class="card card-custom mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control" placeholder="Search student, receipt..." value="<?php echo sanitize($search); ?>">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="refunded" <?php echo $status_filter === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="class" class="form-select">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo sanitize($class['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_from" class="form-control" value="<?php echo sanitize($date_from); ?>" placeholder="From">
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_to" class="form-control" value="<?php echo sanitize($date_to); ?>" placeholder="To">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary btn-action w-100"><i class="bi bi-search"></i></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-custom">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead><tr><th>Receipt #</th><th>Student</th><th>Class</th><th>Amount</th><th>Date</th><th>Collector</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo sanitize($payment['receipt_number']); ?></td>
                                <td><?php echo sanitize($payment['full_name']); ?><br><small class="text-muted"><?php echo sanitize($payment['student_id']); ?></small></td>
                                <td><?php echo sanitize($payment['class_name']); ?></td>
                                <td><?php echo formatCurrency($payment['paid_amount']); ?></td>
                                <td><?php echo formatDate($payment['payment_date']); ?></td>
                                <td><?php echo sanitize($payment['collector_name'] ?: 'N/A'); ?></td>
                                <td><span class="badge bg-<?php echo $payment['status'] === 'completed' ? 'success' : ($payment['status'] === 'pending' ? 'warning' : ($payment['status'] === 'refunded' ? 'danger' : 'secondary')); ?>"><?php echo ucfirst($payment['status']); ?></span></td>
                                <td><a href="?id=<?php echo $payment['id']; ?>&action=view" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payments)) echo '<tr><td colspan="8" class="text-center py-4 text-muted">No payments found</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

