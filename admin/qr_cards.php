<?php

require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');
$page_title = 'QR Cards Management';
$pdo = db();
$user_id = $_SESSION['user_id'];

$action = $_GET['action'] ?? 'list';
$card_id = $_GET['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        flashMessage('error', 'Invalid CSRF token');
        redirect('qr_cards.php');
    }

    if (isset($_POST['generate_qr']) && $card_id) {
        $stmt = $pdo->prepare("SELECT id FROM student_cards WHERE student_id = ? AND status = 'active'");
        $stmt->execute([$card_id]);
        if ($stmt->fetch()) {
            flashMessage('error', 'Student already has an active QR card');
        } else {
            $card_id_val = generateCardId($pdo);
            $qr_token = generateQrToken();
            $stmt = $pdo->prepare("INSERT INTO student_cards (student_id, card_id, qr_token) VALUES (?, ?, ?)");
            $stmt->execute([$card_id, $card_id_val, $qr_token]);
            logAudit($pdo, $user_id, 'generate_qr_card', 'Generated QR card for student ID: ' . $card_id);
            flashMessage('success', 'QR card generated successfully');
        }
        redirect('qr_cards.php');
    }

    if (isset($_POST['regenerate_qr']) && $card_id) {
        $qr_token = generateQrToken();
        $stmt = $pdo->prepare("UPDATE student_cards SET qr_token = ?, status = 'replaced' WHERE student_id = ? AND status = 'active'");
        $stmt->execute([$qr_token, $card_id]);
        logAudit($pdo, $user_id, 'regenerate_qr_token', 'Regenerated QR token for student ID: ' . $card_id);
        flashMessage('success', 'QR token regenerated successfully');
        redirect('qr_cards.php');
    }

    if (isset($_POST['deactivate_card']) && $card_id) {
        $stmt = $pdo->prepare("UPDATE student_cards SET status = 'inactive' WHERE student_id = ? AND status = 'active'");
        $stmt->execute([$card_id]);
        logAudit($pdo, $user_id, 'deactivate_qr_card', 'Deactivated QR card for student ID: ' . $card_id);
        flashMessage('success', 'QR card deactivated');
        redirect('qr_cards.php');
    }
}

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$query = "SELECT sc.*, s.full_name, s.student_id, s.phone FROM student_cards sc JOIN students s ON sc.student_id = s.id WHERE 1=1";
$params = [];
if ($search) {
    $query .= " AND (s.full_name LIKE ? OR s.student_id LIKE ? OR sc.card_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $query .= " AND sc.status = ?";
    $params[] = $status_filter;
}
$query .= " ORDER BY sc.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$qr_cards = $stmt->fetchAll();

$students_without_cards = [];
if ($action === 'generate') {
    $stmt = $pdo->prepare("
        SELECT s.id, s.student_id, s.full_name FROM students s
        WHERE s.status = 'active' AND s.id NOT IN (SELECT student_id FROM student_cards WHERE status = 'active')
    ");
    $stmt->execute();
    $students_without_cards = $stmt->fetchAll();
}

$view_card = null;
if ($action === 'view' && $card_id) {
    $stmt = $pdo->prepare("
        SELECT sc.*, s.full_name, s.student_id, s.phone, s.email
        FROM student_cards sc
        JOIN students s ON sc.student_id = s.id
        WHERE sc.id = ?
    ");
    $stmt->execute([$card_id]);
    $view_card = $stmt->fetch();
}

include '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>QR Cards</h2>
    <a href="?action=generate" class="btn btn-primary btn-action"><i class="bi bi-plus-lg me-2"></i>Generate New Card</a>
</div>

<?php if ($action === 'generate'): ?>
    <div class="card card-custom mb-4">
        <div class="card-header bg-white py-3"><h5 class="mb-0">Generate QR Card</h5></div>
        <div class="card-body">
            <?php if (empty($students_without_cards)): ?>
                <p class="text-muted text-center py-4 mb-0">All active students have QR cards assigned.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom mb-0">
                        <thead><tr><th>Student ID</th><th>Name</th><th>Phone</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($students_without_cards as $student): ?>
                                <tr>
                                    <td><?php echo sanitize($student['student_id']); ?></td>
                                    <td><?php echo sanitize($student['full_name']); ?></td>
                                    <td><?php echo sanitize($student['phone'] ?: 'N/A'); ?></td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="generate_qr" value="1">
                                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success btn-action"><i class="bi bi-qr-code me-1"></i>Generate</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="mt-3">
                <a href="qr_cards.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Back to List</a>
            </div>
        </div>
    </div>
<?php elseif ($action === 'view' && $view_card): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>QR Card Details</h2>
        <a href="qr_cards.php" class="btn btn-secondary btn-action"><i class="bi bi-arrow-left me-2"></i>Back to List</a>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card card-custom mb-4">
                <div class="card-body text-center">
                    <div class="bg-white border rounded p-3 d-inline-block mb-3">
                        <?php
                        $qr_data = getQrCodeUrl($view_card['qr_token'], 200);
                        echo '<img src="' . $qr_data . '" style="width:200px;height:200px;" alt="QR Code">';
                        ?>
                    </div>
                    <h5 class="mt-2"><?php echo sanitize($view_card['card_id']); ?></h5>
                    <p class="text-muted mb-2"><?php echo sanitize($view_card['full_name']); ?></p>
                    <span class="badge bg-<?php echo $view_card['status'] === 'active' ? 'success' : ($view_card['status'] === 'replaced' ? 'warning' : 'secondary'); ?>">
                        <?php echo ucfirst($view_card['status']); ?>
                    </span>
                </div>
            </div>
            <div class="card card-custom">
                <div class="card-header bg-white py-3"><h6 class="mb-0">Actions</h6></div>
                <div class="card-body d-grid gap-2">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="regenerate_qr" value="1">
                        <input type="hidden" name="student_id" value="<?php echo $view_card['student_id']; ?>">
                        <button type="submit" class="btn btn-warning btn-action w-100"><i class="bi bi-arrow-repeat me-2"></i>Regenerate QR Token</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="deactivate_card" value="1">
                        <input type="hidden" name="student_id" value="<?php echo $view_card['student_id']; ?>">
                        <button type="submit" class="btn btn-danger btn-action w-100" onclick="return confirm('Deactivate this QR card?')"><i class="bi bi-x-circle me-2"></i>Deactivate Card</button>
                    </form>
<a href="javascript:void(0)" onclick="openQrCardModal('<?php echo urlencode($view_card['qr_token']); ?>', false)" class="btn btn-info btn-action w-100"><i class="bi bi-eye me-2"></i>View Full Card</a>
                    <a href="javascript:void(0)" onclick="openQrCardModal('<?php echo urlencode($view_card['qr_token']); ?>', true)" class="btn btn-primary btn-action w-100"><i class="bi bi-printer me-2"></i>Print Card</a>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card card-custom">
                <div class="card-body">
                    <h5 class="mb-3">Student Information</h5>
                    <div class="row g-3">
                        <div class="col-md-6"><strong>Student ID:</strong> <?php echo sanitize($view_card['student_id']); ?></div>
                        <div class="col-md-6"><strong>Full Name:</strong> <?php echo sanitize($view_card['full_name']); ?></div>
                        <div class="col-md-6"><strong>Email:</strong> <?php echo sanitize($view_card['email'] ?: 'N/A'); ?></div>
                        <div class="col-md-6"><strong>Phone:</strong> <?php echo sanitize($view_card['phone'] ?: 'N/A'); ?></div>
                        <div class="col-md-6"><strong>Card ID:</strong> <?php echo sanitize($view_card['card_id']); ?></div>
                        <div class="col-md-6"><strong>Issue Date:</strong> <?php echo formatDate($view_card['issue_date']); ?></div>
                        <div class="col-12"><strong>QR Token:</strong> <code><?php echo sanitize($view_card['qr_token']); ?></code></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card card-custom mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control" placeholder="Search by student name, ID, or card ID..." value="<?php echo sanitize($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="replaced" <?php echo $status_filter === 'replaced' ? 'selected' : ''; ?>>Replaced</option>
                        <option value="lost" <?php echo $status_filter === 'lost' ? 'selected' : ''; ?>>Lost</option>
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
                    <thead><tr><th>Card ID</th><th>Student</th><th>Student ID</th><th>Phone</th><th>Issue Date</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($qr_cards as $card): ?>
                            <tr>
                                <td><?php echo sanitize($card['card_id']); ?></td>
                                <td><?php echo sanitize($card['full_name']); ?></td>
                                <td><?php echo sanitize($card['student_id']); ?></td>
                                <td><?php echo sanitize($card['phone'] ?: 'N/A'); ?></td>
                                <td><?php echo formatDate($card['issue_date']); ?></td>
                                <td><span class="badge bg-<?php echo $card['status'] === 'active' ? 'success' : ($card['status'] === 'replaced' ? 'warning' : 'secondary'); ?>"><?php echo ucfirst($card['status']); ?></span></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="?id=<?php echo $card['id']; ?>&action=view" class="btn btn-info"><i class="bi bi-eye"></i></a>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Regenerate QR token?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                            <input type="hidden" name="regenerate_qr" value="1">
                                            <input type="hidden" name="student_id" value="<?php echo $card['student_id']; ?>">
                                            <button type="submit" class="btn btn-warning" title="Regenerate"><i class="bi bi-arrow-repeat"></i></button>
                                        </form>
                                        <a href="javascript:void(0)" onclick="openQrCardModal('<?php echo urlencode($card['qr_token']); ?>', true)" class="btn btn-primary" title="Print"><i class="bi bi-printer"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($qr_cards)) echo '<tr><td colspan="7" class="text-center py-4 text-muted">No QR cards found</td></tr>'; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

