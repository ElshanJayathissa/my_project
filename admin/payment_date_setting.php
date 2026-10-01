<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'Set Payment Date';
$pdo = db();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        flashMessage('error', 'Invalid CSRF token');
        redirect('payment_date_setting.php');
    }

    $payment_day = intval($_POST['monthly_payment_day']);
    if ($payment_day < 1 || $payment_day > 31) {
        $payment_day = 25;
    }

    setSetting($pdo, 'monthly_payment_day', (string)$payment_day);
    logAudit($pdo, $user_id, 'update_payment_date', 'Updated monthly payment day to: ' . $payment_day);
    flashMessage('success', 'Payment date updated successfully');
    redirect('payment_date_setting.php');
}

$monthly_payment_day = getMonthlyPaymentDay($pdo);

include '../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-calendar-check me-2 text-primary"></i>Set Monthly Payment Due Date</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="mb-3">
                        <label class="form-label">Monthly Payment Due Date</label>
                        <select name="monthly_payment_day" class="form-select">
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?php echo $d; ?>" <?php echo $monthly_payment_day == $d ? 'selected' : ''; ?>>
                                    <?php echo $d; ?><?php echo $d == 1 ? 'st' : ($d == 2 ? 'nd' : ($d == 3 ? 'rd' : 'th')); ?> of every month
                                </option>
                            <?php endfor; ?>
                        </select>
                        <small class="text-muted">This date will be used as the monthly payment due date for all classes. The system will automatically calculate due dates based on this setting.</small>
                    </div>
                    <button type="submit" class="btn btn-primary btn-action"><i class="bi bi-save me-2"></i>Save Payment Date</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
