<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('collector');
$page_title = 'Collect Payment';
require_once __DIR__ . '/../includes/header.php';
$pdo = db();
$collector_id = $_SESSION['user_id'];
$student = null;
$enrollments = [];
$error = '';
$success = '';
$token = $_GET['token'] ?? null;
$student_id_param = $_GET['student_id'] ?? null;
$current_month = (int)date('n');
$current_year = (int)date('Y');
$months = getBillingMonths();
$payment_methods = [];
$enable_partial = getSetting($pdo, 'enable_partial_payment', '1');

if ($token) {
    $stmt = $pdo->prepare("
        SELECT s.*, sc.qr_token, sc.card_id, sc.status as card_status
        FROM student_cards sc
        JOIN students s ON sc.student_id = s.id
        WHERE sc.qr_token = ? AND sc.status = 'active'
    ");
    $stmt->execute([$token]);
    $student = $stmt->fetch();
} elseif ($student_id_param) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND status = 'active'");
    $stmt->execute([$student_id_param]);
    $student = $stmt->fetch();
}

if (!$student && !$token && !$student_id_param) {
    redirect('search.php');
}

if (!$student && ($token || $student_id_param)) {
    $error = 'Student not found or card is inactive. Please try again or search manually.';
}

if ($student) {
    $stmt = $pdo->prepare("
        SELECT ce.*, c.name as class_name, c.monthly_fee, c.teacher_id,
               t.name as teacher_name,
               (SELECT COUNT(*) FROM payments p WHERE p.student_id = ? AND p.class_id = c.id AND p.status != 'cancelled') as payment_count
        FROM class_enrollments ce
        JOIN classes c ON ce.class_id = c.id
        LEFT JOIN teachers t ON c.teacher_id = t.id
        WHERE ce.student_id = ? AND ce.status = 'active'
        ORDER BY c.name ASC
    ");
    $stmt->execute([$student['id'], $student['id']]);
    $enrollments = $stmt->fetchAll();

    foreach ($enrollments as $key => $enrollment) {
        $stmt = $pdo->prepare("
            SELECT pi.*, p.status as payment_status, p.id as payment_id, p.receipt_number, p.paid_amount, p.payment_date
            FROM payment_items pi
            JOIN payments p ON pi.payment_id = p.id
            WHERE p.student_id = ? AND p.class_id = ? AND pi.status != 'paid'
            ORDER BY pi.billing_year DESC, pi.billing_month DESC
            LIMIT 1
        ");
        $stmt->execute([$student['id'], $enrollment['class_id']]);
        $enrollment['latest_payment'] = $stmt->fetch();
        
        $payment_status_info = getStudentPaymentStatusForMonth($pdo, $student['id'], $enrollment['class_id'], $current_year, $current_month);
        $enrollments[$key]['payment_status_info'] = $payment_status_info;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        $error = 'Invalid CSRF token.';
    } else {
        $class_id = intval($_POST['class_id']);
        $billing_months = $_POST['billing_months'] ?? [];
        if (!is_array($billing_months)) {
            $billing_months = explode(',', (string)$billing_months);
        }
        $billing_months = array_filter(array_map('trim', $billing_months));
        $payment_method_id = intval($_POST['payment_method_id']);
        $receipt_number = trim($_POST['receipt_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $custom_amount = floatval($_POST['custom_amount'] ?? 0);

        if (empty($billing_months)) {
            $error = 'Please select at least one billing month.';
        } elseif (!$class_id) {
            $error = 'Please select a class.';
        } else {
            $stmt = $pdo->prepare("SELECT monthly_fee FROM classes WHERE id = ?");
            $stmt->execute([$class_id]);
            $monthly_fee = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE id = ? AND status = 'active'");
            $stmt->execute([$payment_method_id]);
            if (!$stmt->fetch()) {
                $error = 'Invalid payment method.';
            } else {
                $total_due = 0;
                foreach ($billing_months as $month_year) {
                    list($month, $year) = explode('-', $month_year);
                    $total_due += $monthly_fee;
                }

                $paid_amount = $custom_amount > 0 ? min($custom_amount, $total_due) : $total_due;
                $status = $paid_amount >= $total_due ? 'completed' : 'partial';

                $pdo->beginTransaction();
                try {
                    $receipt_number = $receipt_number ?: generateReceiptNumber();

                    $stmt = $pdo->prepare("
                        INSERT INTO payments (student_id, class_id, collector_id, payment_method_id, total_amount, paid_amount, status, payment_date, receipt_number, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)
                    ");
                    $stmt->execute([$student['id'], $class_id, $collector_id, $payment_method_id, $total_due, $paid_amount, $status, $receipt_number, $notes]);
                    $payment_id = $pdo->lastInsertId();

                    foreach ($billing_months as $month_year) {
                        $parts = explode('-', (string)$month_year);
                        if (count($parts) < 2) continue;
                        $month = (int)$parts[0];
                        $year = (int)$parts[1];
                        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2030) continue;
                        
                        $item_status = $paid_amount >= $total_due ? 'paid' : 'partial';
                        $item_paid = $paid_amount >= $total_due ? $monthly_fee : ($paid_amount / count($billing_months));

                        $stmt = $pdo->prepare("
                            SELECT pi.id, pi.amount_paid, pi.amount_due FROM payment_items pi
                            JOIN payments p ON pi.payment_id = p.id
                            WHERE p.student_id = ? AND p.class_id = ? AND pi.billing_month = ? AND pi.billing_year = ? AND pi.status != 'paid'
                            LIMIT 1
                        ");
                        $stmt->execute([$student['id'], $class_id, $month, $year]);
                        $existing_item = $stmt->fetch();

                        if ($existing_item) {
                            $new_paid = $existing_item['amount_paid'] + $item_paid;
                            $new_status = $new_paid >= $monthly_fee ? 'paid' : 'partial';
                            $stmt = $pdo->prepare("UPDATE payment_items SET amount_paid = ?, status = ? WHERE id = ?");
                            $stmt->execute([$new_paid, $new_status, $existing_item['id']]);
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO payment_items (payment_id, billing_month, billing_year, amount_due, amount_paid, status)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$payment_id, $month, $year, $monthly_fee, $item_paid, $item_status]);
                        }
                    }

                    $billing_details = 'Months: ' . implode(', ', array_map(function($my) {
                        list($m, $y) = explode('-', $my);
                        $months = getBillingMonths();
                        return $months[$m] . ' ' . $y;
                    }, $billing_months));

                    $institute_name = getSetting($pdo, 'institute_name', 'Institute');
                    $institute_address = getSetting($pdo, 'institute_address', '');
                    $institute_phone = getSetting($pdo, 'institute_phone', '');
                    $institute_logo = getSetting($pdo, 'institute_logo', '');
                    
                    $stmt = $pdo->prepare("SELECT name FROM payment_methods WHERE id = ?");
                    $stmt->execute([$payment_method_id]);
                    $payment_method_name = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("
                        INSERT INTO receipts (payment_id, receipt_number, student_id, class_id, amount, payment_date, collector_id, payment_method, billing_details, institute_name, institute_address, institute_phone, institute_logo)
                        VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$payment_id, $receipt_number, $student['id'], $class_id, $paid_amount, $collector_id, $payment_method_name, $billing_details, $institute_name, $institute_address, $institute_phone, $institute_logo]);

                    logAudit($pdo, $collector_id, 'payment_collected', 'Payment ID: ' . $payment_id . ', Student: ' . $student['full_name'] . ', Amount: ' . $paid_amount . ', Receipt: ' . $receipt_number);

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Payment failed: ' . $e->getMessage();
                }

                if (empty($error) && isset($payment_id)) {
                    $success = 'Payment recorded successfully! Receipt: ' . $receipt_number;
                    $_SESSION['last_receipt_id'] = $receipt_number;

                    $stmt = $pdo->prepare("
                        SELECT ce.*, c.name as class_name, c.monthly_fee
                        FROM class_enrollments ce
                        JOIN classes c ON ce.class_id = c.id
                        WHERE ce.student_id = ? AND ce.status = 'active'
                        ORDER BY c.name ASC
                    ");
                    $stmt->execute([$student['id']]);
                    $enrollments = $stmt->fetchAll();

                    foreach ($enrollments as $enrollment) {
                        $stmt = $pdo->prepare("
                            SELECT pi.*, p.status as payment_status, p.id as payment_id, p.receipt_number, p.paid_amount, p.payment_date
                            FROM payment_items pi
                            JOIN payments p ON pi.payment_id = p.id
                            WHERE p.student_id = ? AND p.class_id = ? AND pi.status != 'paid'
                            ORDER BY pi.billing_year DESC, pi.billing_month DESC
                            LIMIT 1
                        ");
                        $stmt->execute([$student['id'], $enrollment['class_id']]);
                        $enrollment['latest_payment'] = $stmt->fetch();
                    }
                }
            }
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE status = 'active' ORDER BY name ASC");
$stmt->execute();
$payment_methods = $stmt->fetchAll();
?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($student): ?>
    <div class="card card-custom mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-2 text-center mb-3 mb-md-0">
                    <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px; font-size: 2rem;">
                        <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                    </div>
                </div>
                <div class="col-md-5 mb-3 mb-md-0">
                    <h5 class="mb-1"><?php echo sanitize($student['full_name']); ?></h5>
                    <p class="text-muted mb-1"><i class="bi bi-card-identification me-1"></i>ID: <?php echo sanitize($student['student_id']); ?></p>
                    <p class="text-muted mb-0"><i class="bi bi-phone me-1"></i><?php echo sanitize($student['phone'] ?? 'N/A'); ?></p>
                </div>
                <div class="col-md-5">
                    <h6 class="text-muted">Guardian</h6>
                    <p class="mb-1"><?php echo sanitize($student['guardian_name'] ?? 'N/A'); ?></p>
                    <p class="text-muted mb-0"><?php echo sanitize($student['guardian_phone'] ?? 'N/A'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5 mb-4">
            <div class="card card-custom h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="bi bi-collection-play me-2"></i>Classes & Payments</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($enrollments)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-book d-block mb-2 fs-1"></i>
                            No classes enrolled.
                        </div>
                    <?php else: ?>
                        <?php foreach ($enrollments as $enrollment):
                            $outstanding = getStudentOutstanding($pdo, $student['id'], $enrollment['class_id']);
                            $unpaid_months = getUnpaidMonths($pdo, $student['id'], $enrollment['class_id']);
                            $is_paid = $outstanding <= 0;
                            
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) as count FROM payment_items pi
                                JOIN payments p ON pi.payment_id = p.id
                                WHERE p.student_id = ? AND p.class_id = ? AND pi.billing_month = ? AND pi.billing_year = ? AND pi.status != 'paid'
                            ");
                            $stmt->execute([$student['id'], $enrollment['class_id'], $current_month, $current_year]);
                            $current_month_unpaid = $stmt->fetchColumn() > 0;
                        ?>
                            <div class="card mb-3 border">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="fw-bold mb-0"><?php echo sanitize($enrollment['class_name']); ?></h6>
                                            <small class="text-muted">Teacher: <?php echo sanitize($enrollment['teacher_name'] ?? 'N/A'); ?></small>
                                        </div>
                                        <?php if ($is_paid): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php elseif ($outstanding < $enrollment['monthly_fee']): ?>
                                            <span class="badge bg-warning text-dark">Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Unpaid</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex justify-content-between small text-muted mb-2">
                                        <span>Monthly Fee: <?php echo formatCurrency($enrollment['monthly_fee']); ?></span>
                                        <span>Outstanding: <strong class="<?php echo $outstanding > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo formatCurrency($outstanding); ?></strong></span>
                                    </div>
                                    <?php if ($current_month_unpaid): ?>
                                        <div class="alert alert-warning py-2 mb-2 small">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            <strong><?php echo $months[$current_month] . ' ' . $current_year; ?></strong> payment is due
                                            <?php if ($enrollment['payment_status_info']['days_text']): ?>
                                                <br><strong><?php echo $enrollment['payment_status_info']['days_text']; ?></strong>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($unpaid_months > 0): ?>
                                        <div class="text-muted small mb-2">
                                            <i class="bi bi-calendar-x me-1"></i>
                                            Unpaid months: <strong class="text-danger"><?php echo $unpaid_months; ?></strong>
                                            <?php if ($enrollment['payment_status_info']['days_text'] && !$current_month_unpaid): ?>
                                                <br><strong><?php echo $enrollment['payment_status_info']['days_text']; ?></strong>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!$is_paid): ?>
                                        <button class="btn btn-primary btn-sm w-100" data-bs-toggle="collapse" data-bs-target="#pay-form-<?php echo $enrollment['class_id']; ?>">
                                            <i class="bi bi-credit-card me-1"></i>Collect Payment
                                        </button>
                                    <?php else: ?>
                                        <small class="text-success"><i class="bi bi-check-circle me-1"></i>All payments completed</small>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$is_paid): ?>
                                    <div id="pay-form-<?php echo $enrollment['class_id']; ?>" class="collapse">
                                        <div class="card-body border-top bg-light">
                                            <form method="POST" action="">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="class_id" value="<?php echo $enrollment['class_id']; ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">Select Months</label>
                                                    <select name="billing_months[]" class="form-select" multiple size="5">
                                                        <?php for ($i = 0; $i < 12; $i++): ?>
                                                            <?php $m = $current_month - $i; $y = $current_year; if ($m < 1) { $m += 12; $y--; } ?>
                                                            <option value="<?php echo $m . '-' . $y; ?>">
                                                                <?php echo $months[$m] . ' ' . $y; ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <small class="text-muted">Hold Ctrl/Cmd to select multiple</small>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Payment Method</label>
                                                    <select name="payment_method_id" class="form-select" required>
                                                        <option value="">Select Method</option>
                                                        <?php foreach ($payment_methods as $method): ?>
                                                            <option value="<?php echo $method['id']; ?>"><?php echo sanitize($method['name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <?php if ($enable_partial === '1'): ?>
                                                    <div class="mb-3">
                                                        <label class="form-label">Amount (Rs.)</label>
                                                        <input type="number" name="custom_amount" class="form-control" step="0.01" min="0" max="<?php echo $outstanding; ?>" value="<?php echo number_format($outstanding, 2, '.', ''); ?>">
                                                    </div>
                                                <?php endif; ?>
                                                <div class="mb-3">
                                                    <label class="form-label">Receipt Number (optional)</label>
                                                    <input type="text" name="receipt_number" class="form-control" placeholder="Auto-generated if blank">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Notes</label>
                                                    <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                                                </div>
                                                <button type="submit" class="btn btn-success w-100">
                                                    <i class="bi bi-check-lg me-1"></i>Confirm Payment
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-7 mb-4">
            <div class="card card-custom h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Payment History</h5>
                </div>
                <div class="card-body p-0">
                    <?php
                    $stmt = $pdo->prepare("
                        SELECT p.*, c.name as class_name, pm.name as method_name
                        FROM payments p
                        JOIN classes c ON p.class_id = c.id
                        LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
                        WHERE p.student_id = ? AND p.status != 'cancelled'
                        ORDER BY p.payment_date DESC, p.created_at DESC
                        LIMIT 20
                    ");
                    $stmt->execute([$student['id']]);
                    $history = $stmt->fetchAll();
                    ?>
                    <?php if (empty($history)): ?>
                        <div class="p-4 text-center text-muted">No payment history found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Class</th>
                                        <th>Method</th>
                                        <th>Amount</th>
                                        <th>Receipt</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $h): ?>
                                        <tr>
                                            <td><?php echo formatDate($h['payment_date']); ?></td>
                                            <td><?php echo sanitize($h['class_name']); ?></td>
                                            <td><?php echo sanitize($h['method_name'] ?? 'N/A'); ?></td>
                                            <td class="fw-bold text-success"><?php echo formatCurrency($h['paid_amount']); ?></td>
                                            <td><?php echo sanitize($h['receipt_number'] ?? 'N/A'); ?></td>
                                            <td><span class="badge bg-<?php echo $h['status'] === 'completed' ? 'success' : ($h['status'] === 'partial' ? 'warning' : 'secondary'); ?>"><?php echo ucfirst($h['status']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['last_receipt_id'])): ?>
        <?php $receipt_num = $_SESSION['last_receipt_id']; unset($_SESSION['last_receipt_id']); ?>
        <div class="row mt-3" id="receipt-section">
            <div class="col-12">
                <div class="card card-custom">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-printer me-2"></i>Last Receipt</h5>
                        <div>
                            <a href="print_receipt.php?receipt_number=<?php echo urlencode($receipt_num); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-printer me-1"></i>Print Receipt
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php
                        $stmt = $pdo->prepare("
                            SELECT r.*, s.full_name, s.student_id, c.name as class_name
                            FROM receipts r
                            JOIN students s ON r.student_id = s.id
                            JOIN classes c ON r.class_id = c.id
                            WHERE r.receipt_number = ?
                        ");
                        $stmt->execute([$receipt_num]);
                        $receipt = $stmt->fetch();
                        ?>
                        <?php if ($receipt): ?>
                            <div class="receipt-container" id="printable-receipt">
                                <div class="text-center mb-3">
                                    <h4 class="fw-bold"><?php echo sanitize($receipt['institute_name']); ?></h4>
                                    <p class="mb-0 small"><?php echo nl2br(sanitize($receipt['institute_address'] ?? '')); ?></p>
                                    <p class="mb-0 small">Phone: <?php echo sanitize($receipt['institute_phone'] ?? ''); ?></p>
                                </div>
                                <hr>
                                <h5 class="text-center">RECEIPT</h5>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>Receipt No:</strong></div>
                                    <div class="col-6 text-end"><?php echo sanitize($receipt['receipt_number']); ?></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>Date:</strong></div>
                                    <div class="col-6 text-end"><?php echo formatDate($receipt['payment_date']); ?></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>Student:</strong></div>
                                    <div class="col-6 text-end"><?php echo sanitize($receipt['full_name']); ?></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>Student ID:</strong></div>
                                    <div class="col-6 text-end"><?php echo sanitize($receipt['student_id']); ?></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>Class:</strong></div>
                                    <div class="col-6 text-end"><?php echo sanitize($receipt['class_name']); ?></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>Amount:</strong></div>
                                    <div class="col-6 text-end fw-bold"><?php echo formatCurrency($receipt['amount']); ?></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>Method:</strong></div>
                                    <div class="col-6 text-end"><?php echo sanitize($receipt['payment_method'] ?? 'N/A'); ?></div>
                                </div>
                                <?php if ($receipt['billing_details']): ?>
                                    <div class="row mb-2">
                                        <div class="col-6"><strong>Months:</strong></div>
                                        <div class="col-6 text-end"><?php echo sanitize($receipt['billing_details']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <hr>
                                <div class="text-center">
                                    <p class="mb-0 small">Thank you for your payment!</p>
                                    <p class="mb-0 small text-muted">Generated: <?php echo date('d M Y h:i A'); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php elseif (!$student && $token): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Invalid or inactive QR code. Please try again or search manually.
        <a href="search.php" class="alert-link">Search manually</a>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

