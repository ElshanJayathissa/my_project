<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('collector');
$page_title = 'Search Student';
require_once __DIR__ . '/../includes/header.php';
$pdo = db();
$search_results = [];
$search_query = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['q']) && !empty(trim($_GET['q']))) {
    $search_query = trim($_GET['q']);
    $stmt = $pdo->prepare("
        SELECT s.*, sc.qr_token, sc.card_id, sc.status as card_status
        FROM students s
        LEFT JOIN student_cards sc ON s.id = sc.student_id AND sc.status = 'active'
        WHERE s.student_id LIKE ? OR s.full_name LIKE ? OR s.phone LIKE ? OR s.guardian_phone LIKE ?
        ORDER BY s.full_name ASC
    ");
    $like = '%' . $search_query . '%';
    $stmt->execute([$like, $like, $like, $like]);
    $search_results = $stmt->fetchAll();
    
    $current_month = (int)date('n');
    $current_year = (int)date('Y');
    foreach ($search_results as &$student) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unpaid_count FROM payment_items pi
            JOIN payments p ON pi.payment_id = p.id
            WHERE p.student_id = ? AND pi.billing_month = ? AND pi.billing_year = ? AND pi.status != 'paid'
        ");
        $stmt->execute([$student['id'], $current_month, $current_year]);
        $student['current_month_due'] = $stmt->fetchColumn() > 0;
    }
    unset($student);
    
    logAudit($pdo, $_SESSION['user_id'], 'search_student', 'Searched for: ' . $search_query . ' | Results: ' . count($search_results));
}
?>

<div class="row">
    <div class="col-12">
        <div class="card card-custom mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-search me-2"></i>Search Student</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-8">
                        <input type="text" name="q" class="form-control" placeholder="Search by Student ID, Name, or Phone..." value="<?php echo sanitize($search_query); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-2"></i>Search</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($search_query): ?>
            <div class="card card-custom">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Results (<?php echo count($search_results); ?>)</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($search_results)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-person-x fs-1 d-block mb-2"></i>
                            No students found matching your search.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Guardian</th>
                                        <th>Card Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($search_results as $student): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo sanitize($student['student_id']); ?></td>
                                            <td>
                                                <?php echo sanitize($student['full_name']); ?>
                                                <br><small class="text-muted"><?php echo $student['email'] ? sanitize($student['email']) : ''; ?></small>
                                            </td>
                                            <td><?php echo sanitize($student['phone'] ?? 'N/A'); ?></td>
                                            <td><?php echo sanitize($student['guardian_name'] ?? 'N/A'); ?><br><small class="text-muted"><?php echo sanitize($student['guardian_phone'] ?? ''); ?></small></td>
                                        <td>
                                            <?php if ($student['card_status'] === 'active'): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php elseif ($student['card_status']): ?>
                                                <span class="badge bg-danger"><?php echo ucfirst($student['card_status']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No Card</span>
                                            <?php endif; ?>
                                            <?php if ($student['current_month_due']): ?>
                                                <br><span class="badge bg-warning text-dark mt-1">Due This Month</span>
                                            <?php endif; ?>
                                        </td>
                                            <td>
                                                <a href="payment.php?student_id=<?php echo $student['id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-credit-card me-1"></i>Collect
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

