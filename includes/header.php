<?php
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'User';
$pdo = db();
$user_id = $_SESSION['user_id'] ?? 0;
$today_stats = getTodayCollection($user_role === 'collector' ? $user_id : null);
$unread_count = 0;
if ($user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%); color: white; }
        .sidebar a { color: #bdc3c7; text-decoration: none; padding: 12px 20px; display: block; }
        .sidebar a:hover, .sidebar a.active { color: white; background: rgba(255,255,255,0.1); }
        .sidebar .brand { padding: 20px; font-size: 1.3rem; font-weight: bold; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .main-content { min-height: 100vh; }
        .navbar-custom { background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card-custom { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-radius: 10px; }
        .stat-card { border-left: 4px solid #667eea; }
        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.85rem; }
        .table-custom th { background: #f8f9fa; font-weight: 600; }
        .btn-action { border-radius: 8px; padding: 8px 16px; }
        @media (max-width: 768px) {
            .sidebar { min-height: auto; }
            .sidebar .brand { display: none; }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-2 d-md-block sidebar">
                <div class="brand"><?php echo APP_NAME; ?></div>
                <a href="../<?php echo $user_role; ?>/dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
                <?php if ($user_role === 'admin'): ?>
                    <a href="students.php" class="<?php echo in_array($current_page, ['students.php', 'add_student.php', 'edit_student.php']) ? 'active' : ''; ?>">
                        <i class="bi bi-people me-2"></i>Students
                    </a>
                    <a href="classes.php" class="<?php echo in_array($current_page, ['classes.php', 'add_class.php', 'edit_class.php']) ? 'active' : ''; ?>">
                        <i class="bi bi-book me-2"></i>Classes
                    </a>
                    <a href="enrollments.php" class="<?php echo $current_page == 'enrollments.php' ? 'active' : ''; ?>">
                        <i class="bi bi-person-plus me-2"></i>Enrollments
                    </a>
                    <a href="teachers.php" class="<?php echo $current_page == 'teachers.php' ? 'active' : ''; ?>">
                        <i class="bi bi-person-badge me-2"></i>Teachers
                    </a>
                    <a href="collectors.php" class="<?php echo $current_page == 'collectors.php' ? 'active' : ''; ?>">
                        <i class="bi bi-cash me-2"></i>Collectors
                    </a>
                    <a href="qr_cards.php" class="<?php echo $current_page == 'qr_cards.php' ? 'active' : ''; ?>">
                        <i class="bi bi-qr-code me-2"></i>QR Cards
                    </a>
                    <a href="payments.php" class="<?php echo $current_page == 'payments.php' ? 'active' : ''; ?>">
                        <i class="bi bi-credit-card me-2"></i>Payments
                    </a>
                    <a href="reports.php" class="<?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
                        <i class="bi bi-bar-chart me-2"></i>Reports
                    </a>
                    <a href="settings.php" class="<?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
                        <i class="bi bi-gear me-2"></i>Settings
                    </a>
                    <a href="payment_date_setting.php" class="<?php echo $current_page == 'payment_date_setting.php' ? 'active' : ''; ?>">
                        <i class="bi bi-calendar-check me-2"></i>Set Payment Date
                    </a>
                <?php elseif ($user_role === 'teacher'): ?>
                    <a href="my_classes.php" class="<?php echo $current_page == 'my_classes.php' ? 'active' : ''; ?>">
                        <i class="bi bi-book me-2"></i>My Classes
                    </a>
                    <a href="my_students.php" class="<?php echo $current_page == 'my_students.php' ? 'active' : ''; ?>">
                        <i class="bi bi-people me-2"></i>My Students
                    </a>
                    <a href="income_report.php" class="<?php echo $current_page == 'income_report.php' ? 'active' : ''; ?>">
                        <i class="bi bi-graph-up me-2"></i>Income Report
                    </a>
                    <a href="reports.php" class="<?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
                        <i class="bi bi-file-earmark-text me-2"></i>Reports
                    </a>
                <?php elseif ($user_role === 'collector'): ?>
                    <a href="scan.php" class="<?php echo $current_page == 'scan.php' ? 'active' : ''; ?>">
                        <i class="bi bi-qr-code-scan me-2"></i>Scan Card
                    </a>
                    <a href="search.php" class="<?php echo $current_page == 'search.php' ? 'active' : ''; ?>">
                        <i class="bi bi-search me-2"></i>Search Student
                    </a>
                    <a href="payments.php" class="<?php echo $current_page == 'payments.php' ? 'active' : ''; ?>">
                        <i class="bi bi-credit-card me-2"></i>Payments
                    </a>
                    <a href="collection_history.php" class="<?php echo $current_page == 'collection_history.php' ? 'active' : ''; ?>">
                        <i class="bi bi-clock-history me-2"></i>History
                    </a>
                <?php endif; ?>
                <a href="../logout.php" class="text-danger">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                </a>
            </nav>
            <main class="col-md-10 ms-sm-auto main-content">
                <nav class="navbar navbar-expand-lg navbar-custom px-4 py-3">
                    <div class="container-fluid">
                        <span class="navbar-brand mb-0 h5"><?php echo $page_title ?? 'Dashboard'; ?></span>
                        <div class="ms-auto d-flex align-items-center">
                            <?php if ($user_role === 'collector'): ?>
                                <div class="me-3 text-success">
                                    <i class="bi bi-calendar-check me-1"></i>Today: <?php echo formatCurrency($today_stats['total'] ?? 0); ?>
                                    <span class="badge bg-success ms-1"><?php echo $today_stats['count'] ?? 0; ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="dropdown">
                                <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-person-circle me-1"></i><?php echo sanitize($full_name); ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </nav>
                <div class="container-fluid p-4">
                    <?php $flash = getFlashMessage(); if ($flash): ?>
                        <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show">
                            <?php echo $flash['message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
