<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'Settings';
$pdo = db();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        flashMessage('error', 'Invalid CSRF token');
        redirect('settings.php');
    }

    if (isset($_POST['save_institute_settings'])) {
        setSetting($pdo, 'institute_name', sanitize($_POST['institute_name']));
        setSetting($pdo, 'institute_address', sanitize($_POST['institute_address']));
        setSetting($pdo, 'institute_phone', sanitize($_POST['institute_phone']));
        if (!empty($_FILES['institute_logo']['name'])) {
            $upload_dir = UPLOAD_DIR;
            $ext = pathinfo($_FILES['institute_logo']['name'], PATHINFO_EXTENSION);
            $filename = 'logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['institute_logo']['tmp_name'], $upload_dir . $filename)) {
                setSetting($pdo, 'institute_logo', $filename);
            }
        }
        logAudit($pdo, $user_id, 'update_settings', 'Updated institute settings');
        flashMessage('success', 'Institute settings saved successfully');
        redirect('settings.php');
    }

    if (isset($_POST['save_commission_settings'])) {
        setSetting($pdo, 'institute_commission_percentage', $_POST['institute_commission_percentage']);
        setSetting($pdo, 'teacher_default_commission_percentage', $_POST['teacher_default_commission_percentage']);
        logAudit($pdo, $user_id, 'update_settings', 'Updated commission settings');
        flashMessage('success', 'Commission settings saved successfully');
        redirect('settings.php');
    }

    if (isset($_POST['save_toggle_settings'])) {
        setSetting($pdo, 'enable_partial_payment', isset($_POST['enable_partial_payment']) ? '1' : '0');
        setSetting($pdo, 'enable_student_lookup_by_phone', isset($_POST['enable_student_lookup_by_phone']) ? '1' : '0');
        setSetting($pdo, 'enable_qr_scan', isset($_POST['enable_qr_scan']) ? '1' : '0');
        logAudit($pdo, $user_id, 'update_settings', 'Updated toggle settings');
        flashMessage('success', 'Toggle settings saved successfully');
        redirect('settings.php');
    }

    if (isset($_POST['save_payment_date_settings'])) {
        $payment_day = intval($_POST['monthly_payment_day']);
        if ($payment_day < 1 || $payment_day > 31) {
            $payment_day = 25;
        }
        setSetting($pdo, 'monthly_payment_day', (string)$payment_day);
        logAudit($pdo, $user_id, 'update_settings', 'Updated monthly payment day to: ' . $payment_day);
        flashMessage('success', 'Payment date updated successfully');
        redirect('settings.php');
    }
}

$institute_name = getSetting($pdo, 'institute_name', 'Institute Name');
$institute_address = getSetting($pdo, 'institute_address', 'Institute Address');
$institute_phone = getSetting($pdo, 'institute_phone', '');
$institute_logo = getSetting($pdo, 'institute_logo', '');
$institute_commission = getSetting($pdo, 'institute_commission_percentage', '20.00');
$teacher_commission = getSetting($pdo, 'teacher_default_commission_percentage', '80.00');
$enable_partial = getSetting($pdo, 'enable_partial_payment', '1');
$enable_phone_lookup = getSetting($pdo, 'enable_student_lookup_by_phone', '1');
$enable_qr_scan = getSetting($pdo, 'enable_qr_scan', '1');
$monthly_payment_day = getSetting($pdo, 'monthly_payment_day', '25');

include '../includes/header.php';
?>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-building me-2 text-primary"></i>Institute Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="mb-3">
                        <label class="form-label">Institute Name *</label>
                        <input type="text" name="institute_name" class="form-control" value="<?php echo sanitize($institute_name); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="institute_address" class="form-control" rows="3"><?php echo sanitize($institute_address); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="institute_phone" class="form-control" value="<?php echo sanitize($institute_phone); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Logo</label>
                        <?php if ($institute_logo && file_exists(UPLOAD_DIR . $institute_logo)): ?>
                            <div class="mb-2">
                                <img src="<?php echo asset('../uploads/' . $institute_logo); ?>" style="max-height:80px;" alt="Logo">
                            </div>
                        <?php endif; ?>
                        <input type="file" name="institute_logo" class="form-control" accept="image/*">
                    </div>
                    <button type="submit" name="save_institute_settings" class="btn btn-primary btn-action"><i class="bi bi-save me-2"></i>Save Settings</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-percent me-2 text-primary"></i>Commission Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="mb-3">
                        <label class="form-label">Institute Commission (%)</label>
                        <input type="number" step="0.01" name="institute_commission_percentage" class="form-control" value="<?php echo $institute_commission; ?>">
                        <small class="text-muted">Percentage of payment that goes to institute</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Teacher Default Commission (%)</label>
                        <input type="number" step="0.01" name="teacher_default_commission_percentage" class="form-control" value="<?php echo $teacher_commission; ?>">
                        <small class="text-muted">Default commission percentage for new teachers</small>
                    </div>
                    <button type="submit" name="save_commission_settings" class="btn btn-primary btn-action"><i class="bi bi-save me-2"></i>Save Commission</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="card card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-toggle-on me-2 text-primary"></i>Feature Toggles</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="enable_partial_payment" id="enable_partial_payment" <?php echo $enable_partial ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="enable_partial_payment">
                            <strong>Enable Partial Payment</strong>
                            <p class="text-muted mb-0">Allow students to make partial payments for fees</p>
                        </label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="enable_student_lookup_by_phone" id="enable_phone_lookup" <?php echo $enable_phone_lookup ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="enable_phone_lookup">
                            <strong>Enable Phone Lookup</strong>
                            <p class="text-muted mb-0">Allow collectors to search students by phone number</p>
                        </label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="enable_qr_scan" id="enable_qr_scan" <?php echo $enable_qr_scan ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="enable_qr_scan">
                            <strong>Enable QR Scan</strong>
                            <p class="text-muted mb-0">Allow QR code scanning for payment collection</p>
                        </label>
                    </div>
                    <button type="submit" name="save_toggle_settings" class="btn btn-primary btn-action"><i class="bi bi-save me-2"></i>Save Toggles</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6 mb-4">
        <div class="card card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-calendar-check me-2 text-primary"></i>Payment Date Settings</h5>
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
                    <button type="submit" name="save_payment_date_settings" class="btn btn-primary btn-action"><i class="bi bi-save me-2"></i>Save Payment Date</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

