<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['student_id'])) {
    redirect('index.php');
}

$pdo = db();
$student_id = $_SESSION['student_id'];
$institute_name = getSetting($pdo, 'institute_name', APP_NAME);
$institute_address = getSetting($pdo, 'institute_address', '');
$institute_phone = getSetting($pdo, 'institute_phone', '');

$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    session_destroy();
    redirect('index.php');
}

$stmt = $pdo->prepare("
    SELECT ce.*, c.name as class_name, c.monthly_fee, t.name as teacher_name, t.email as teacher_email
    FROM class_enrollments ce
    JOIN classes c ON ce.class_id = c.id
    JOIN teachers t ON c.teacher_id = t.id
    WHERE ce.student_id = ? AND ce.status = 'active' AND c.status = 'active'
    ORDER BY c.name ASC
");
$stmt->execute([$student_id]);
$enrollments = $stmt->fetchAll();

$active_classes = [];
$total_outstanding = 0;

foreach ($enrollments as $enrollment) {
    $class_id = $enrollment['class_id'];
    $stmt = $pdo->prepare("
        SELECT pi.billing_month, pi.billing_year, pi.amount_due, pi.amount_paid, pi.status,
               p.payment_date, p.receipt_number, p.id as payment_id
        FROM payment_items pi
        JOIN payments p ON pi.payment_id = p.id
        WHERE p.student_id = ? AND p.class_id = ? AND p.status IN ('completed', 'partial', 'pending')
        ORDER BY pi.billing_year DESC, pi.billing_month DESC
    ");
    $stmt->execute([$student_id, $class_id]);
    $payment_items = $stmt->fetchAll();

    $paid_months = [];
    $unpaid_months = [];
    $partial_months = [];
    $class_outstanding = 0;

    foreach ($payment_items as $item) {
        $month_name = date('F Y', mktime(0, 0, 0, $item['billing_month'], 1, $item['billing_year']));
        $entry = [
            'month' => $month_name,
            'amount_due' => (float)$item['amount_due'],
            'amount_paid' => (float)$item['amount_paid'],
            'status' => $item['status'],
            'receipt_number' => $item['receipt_number'],
            'payment_date' => $item['payment_date'],
            'payment_id' => $item['payment_id']
        ];

        if ($item['status'] === 'paid') {
            $paid_months[] = $entry;
        } elseif ($item['status'] === 'partial') {
            $partial_months[] = $entry;
            $class_outstanding += ($item['amount_due'] - $item['amount_paid']);
        } else {
            $unpaid_months[] = $entry;
            $class_outstanding += $item['amount_due'];
        }
    }

    $stmt2 = $pdo->prepare("
        SELECT p.id, p.total_amount, p.paid_amount, p.status, p.payment_date, p.receipt_number,
               pm.name as payment_method, u.full_name as collector_name
        FROM payments p
        LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
        LEFT JOIN users u ON p.collector_id = u.id
        WHERE p.student_id = ? AND p.class_id = ?
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $stmt2->execute([$student_id, $class_id]);
    $payments = $stmt2->fetchAll();

    $total_outstanding += $class_outstanding;

    $current_month = (int)date('n');
    $current_year = (int)date('Y');
    $payment_status = getStudentPaymentStatusForMonth($pdo, $student_id, $class_id, $current_year, $current_month);

    $active_classes[] = [
        'id' => $class_id,
        'name' => $enrollment['class_name'],
        'monthly_fee' => (float)$enrollment['monthly_fee'],
        'teacher_name' => $enrollment['teacher_name'],
        'enrollment_date' => $enrollment['enrollment_date'],
        'paid_months' => $paid_months,
        'unpaid_months' => $unpaid_months,
        'partial_months' => $partial_months,
        'outstanding' => $class_outstanding,
        'payments' => $payments,
        'current_month_status' => $payment_status['status'],
        'current_month_label' => $payment_status['label'],
        'current_month_due_date' => $payment_status['due_date'],
        'current_month_days_text' => $payment_status['days_text'] ?? ''
    ];
}

function getPaymentStatusBadge($status) {
    $map = [
        'paid' => 'success',
        'partial' => 'warning',
        'unpaid' => 'danger',
        'pending' => 'secondary',
        'completed' => 'success',
        'refunded' => 'info',
        'cancelled' => 'dark'
    ];
    return $map[$status] ?? 'secondary';
}

function getPaymentStatusLabel($status) {
    return ucfirst($status);
}

function formatCurrency($amount) {
    return 'Rs. ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #1a1a2e;
            --accent: #667eea;
            --accent2: #764ba2;
            --surface: #ffffff;
            --bg: #f0f2f8;
        }
        * { box-sizing: border-box; }
        body {
            background: var(--bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
        }

        /* Navbar */
        .navbar-student {
            background: linear-gradient(135deg, var(--primary) 0%, #16213e 100%);
            padding: 14px 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .navbar-student .brand {
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .navbar-student .brand .icon-circle {
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.12);
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        .navbar-student .nav-actions { display: flex; align-items: center; gap: 12px; }
        .navbar-student .student-badge {
            color: rgba(255,255,255,0.8);
            font-size: 0.82rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-logout {
            background: rgba(255,255,255,0.1);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            padding: 6px 16px;
            font-size: 0.85rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
        }
        .btn-logout:hover { background: rgba(255,255,255,0.2); color: white; }

        /* Main container */
        .main-container { max-width: 960px; margin: 0 auto; padding: 24px 16px 48px; }

        /* Student info card */
        .info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 28px 32px;
            color: white;
            box-shadow: 0 8px 30px rgba(102,126,234,0.3);
            margin-bottom: 24px;
        }
        .info-card .student-name { font-size: 1.5rem; font-weight: 700; margin-bottom: 4px; }
        .info-card .student-meta { opacity: 0.85; font-size: 0.9rem; display: flex; flex-wrap: wrap; gap: 16px; }
        .info-card .student-meta span { display: flex; align-items: center; gap: 6px; }
        .info-card .outstanding-badge {
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            padding: 10px 18px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            margin-top: 14px;
            backdrop-filter: blur(4px);
        }
        .info-card .outstanding-badge.zero { background: rgba(255,255,255,0.15); opacity: 0.8; }

        /* Section headings */
        .section-heading {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-heading i { color: var(--accent); }

        /* Class card */
        .class-card {
            background: white;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            margin-bottom: 20px;
            overflow: hidden;
            transition: box-shadow 0.2s;
        }
        .class-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,0.1); }
        .class-card-header {
            background: linear-gradient(135deg, #f8f9fd 0%, #eef0f8 100%);
            padding: 18px 24px;
            border-bottom: 1px solid #eef0f5;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .class-name { font-weight: 700; font-size: 1.05rem; color: #1a1a2e; margin: 0; }
        .class-details { color: #666; font-size: 0.85rem; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 12px; }
        .class-details span { display: flex; align-items: center; gap: 4px; }
        .class-card-body { padding: 20px 24px; }

        /* Payment status */
        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .status-paid { background: #d4edda; color: #155724; }
        .status-partial { background: #fff3cd; color: #856404; }
        .status-unpaid { background: #f8d7da; color: #721c24; }
        .status-clear { background: #d1ecf1; color: #0c5460; }

        /* Month items */
        .month-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.88rem;
            margin-bottom: 4px;
            background: #fafbfc;
            border: 1px solid #f0f2f5;
        }
        .month-row .month-name { font-weight: 500; color: #333; }
        .month-row .month-amount { font-weight: 600; color: #444; }
        .month-row .month-balance { font-size: 0.8rem; color: #e65100; font-weight: 600; }
        .month-row.partial-row { border-left: 3px solid #ffc107; }
        .month-row.unpaid-row { border-left: 3px solid #dc3545; }
        .month-row.paid-row { border-left: 3px solid #28a745; background: #f9fdf9; }
        .no-months { color: #aaa; font-style: italic; font-size: 0.85rem; padding: 8px 0; }

        /* Tabs */
        .payment-tabs {
            border-bottom: 2px solid #eef0f5;
            margin-bottom: 16px;
            display: flex;
            gap: 0;
            flex-wrap: wrap;
        }
        .payment-tab {
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.88rem;
            color: #888;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            background: none;
            border-top: none;
            border-left: none;
            border-right: none;
            transition: color 0.2s;
            white-space: nowrap;
        }
        .payment-tab:hover { color: #667eea; }
        .payment-tab.active { color: #667eea; border-bottom-color: #667eea; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* Payment history table */
        .table-custom { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        .table-custom thead th {
            background: #f0f2f8;
            color: #555;
            font-weight: 600;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px 14px;
            border-bottom: 2px solid #e2e5f0;
        }
        .table-custom tbody td {
            padding: 11px 14px;
            border-bottom: 1px solid #f0f2f5;
            vertical-align: middle;
        }
        .table-custom tbody tr:hover { background: #fafbfc; }
        .table-custom tbody tr:last-child td { border-bottom: none; }
        .text-amount { font-weight: 600; font-variant-numeric: tabular-nums; }

        .section-divider { border: none; border-top: 1px solid #eef0f5; margin: 20px 0; }

        /* Alert boxes */
        .alert-info-custom {
            background: #f0f4ff;
            border: 1px solid #d0d8ff;
            border-radius: 10px;
            padding: 14px 18px;
            color: #333;
            font-size: 0.88rem;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .alert-info-custom i { color: #667eea; margin-top: 2px; }

        .empty-state {
            text-align: center;
            padding: 32px;
            color: #aaa;
        }
        .empty-state i { font-size: 2.5rem; display: block; margin-bottom: 10px; }
        .empty-state p { margin: 0; font-size: 0.9rem; }

        .receipt-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.83rem;
        }
        .receipt-link:hover { color: #764ba2; text-decoration: underline; }

        /* Responsive */
        @media (max-width: 576px) {
            .info-card { padding: 20px; }
            .info-card .student-name { font-size: 1.2rem; }
            .class-card-header { padding: 14px 16px; }
            .class-card-body { padding: 14px 16px; }
            .class-details { flex-direction: column; gap: 4px; }
            .main-container { padding: 16px 10px 40px; }
            .table-custom { font-size: 0.8rem; }
            .table-custom thead th, .table-custom tbody td { padding: 8px 10px; }
            .month-row { font-size: 0.82rem; padding: 6px 10px; }
        }

        /* Print styles */
        @media print {
            .navbar-student, .btn-logout { display: none !important; }
            body { background: white; }
            .main-container { padding: 0; max-width: 100%; }
            .class-card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

    <nav class="navbar-student">
        <div class="main-container" style="padding-top:0;padding-bottom:0;">
            <div class="d-flex justify-content-between align-items-center">
                <a href="dashboard.php" class="brand">
                    <span class="icon-circle"><i class="bi bi-mortarboard"></i></span>
                    <?php echo htmlspecialchars(APP_NAME); ?>
                </a>
                <div class="nav-actions">
                    <span class="student-badge d-none d-sm-inline">
                        <i class="bi bi-person-circle"></i>
                        <?php echo htmlspecialchars($student['full_name']); ?>
                    </span>
                    <a href="../logout.php" class="btn-logout">
                        <i class="bi bi-box-arrow-right"></i><span class="d-none d-sm-inline"> Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="main-container">

        <div class="info-card">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                    <div class="student-meta mt-2">
                        <span><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($student['student_id']); ?></span>
                        <?php if (!empty($student['email'])): ?>
                        <span><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($student['email']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($student['phone'])): ?>
                        <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($student['phone']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($student['guardian_name'])): ?>
                        <span><i class="bi bi-people"></i> Guardian: <?php echo htmlspecialchars($student['guardian_name']); ?></span>
                        <?php endif; ?>
                        <span>
                            <span class="status-pill <?php echo $student['status'] === 'active' ? 'status-paid' : 'status-unpaid'; ?>">
                                <?php echo ucfirst($student['status']); ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>
            <div class="outstanding-badge <?php echo $total_outstanding > 0 ? '' : 'zero'; ?>">
                <i class="bi bi-<?php echo $total_outstanding > 0 ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                Total Outstanding:
                <strong><?php echo formatCurrency($total_outstanding); ?></strong>
            </div>
        </div>

        <h2 class="section-heading">
            <i class="bi bi-book"></i> My Active Classes
            <span class="badge bg-primary rounded-pill ms-1"><?php echo count($active_classes); ?></span>
        </h2>

        <?php if (empty($active_classes)): ?>
            <div class="alert-info-custom">
                <i class="bi bi-info-circle fs-5"></i>
                <span>You are not currently enrolled in any active classes. Please contact the administration for enrollment.</span>
            </div>
        <?php endif; ?>

        <?php foreach ($active_classes as $class): ?>
        <div class="class-card" id="class-<?php echo $class['id']; ?>">
            <div class="class-card-header">
                <div>
                    <div class="class-name"><?php echo htmlspecialchars($class['name']); ?></div>
                    <div class="class-details">
                        <span><i class="bi bi-person-workspace"></i> <?php echo htmlspecialchars($class['teacher_name']); ?></span>
                        <span><i class="bi bi-currency-rupee"></i> Monthly Fee: <strong><?php echo formatCurrency($class['monthly_fee']); ?></strong></span>
                        <span><i class="bi bi-calendar3"></i> Since: <?php echo date('d M Y', strtotime($class['enrollment_date'])); ?></span>
                    </div>
                </div>
                <?php if ($class['outstanding'] > 0): ?>
                    <span class="status-pill status-unpaid">
                        <i class="bi bi-exclamation-circle me-1"></i>Rs. <?php echo number_format($class['outstanding'], 2); ?> Due
                    </span>
                <?php else: ?>
                    <span class="status-pill status-clear">
                        <i class="bi bi-check-circle me-1"></i>All Paid
                    </span>
                <?php endif; ?>
                <?php
                    $status_class = [
                        'paid' => 'status-paid',
                        'partial' => 'status-partial',
                        'upcoming' => 'status-partial',
                        'pending' => 'status-unpaid',
                        'overdue' => 'status-unpaid'
                    ][$class['current_month_status']] ?? 'status-clear';
                ?>
                <span class="status-pill <?php echo $status_class; ?>">
                    <i class="bi bi-calendar3 me-1"></i><?php echo htmlspecialchars($class['current_month_label']); ?>
                    <small>(<?php echo date('d M', strtotime($class['current_month_due_date'])); ?>)</small>
                    <?php if (!empty($class['current_month_days_text'])): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($class['current_month_days_text']); ?></small>
                    <?php endif; ?>
                </span>
            </div>

            <div class="class-card-body">
                <?php
                $total_class_items = count($class['paid_months']) + count($class['partial_months']) + count($class['unpaid_months']);
                $paid_count = count($class['paid_months']);
                $partial_count = count($class['partial_months']);
                $unpaid_count = count($class['unpaid_months']);
                ?>

                <?php if ($total_class_items === 0): ?>
                    <div class="empty-state">
                        <i class="bi bi-receipt"></i>
                        <p>No billing records yet for this class.</p>
                    </div>
                <?php else: ?>
                    <ul class="nav payment-tabs">
                        <li class="nav-item">
                            <button class="payment-tab active" onclick="switchTab(<?php echo $class['id']; ?>, 'paid-months-<?php echo $class['id']; ?>', this)">
                                Paid <span class="badge bg-success rounded-pill ms-1"><?php echo $paid_count; ?></span>
                            </button>
                        </li>
                        <?php if ($partial_count > 0): ?>
                        <li class="nav-item">
                            <button class="payment-tab" onclick="switchTab(<?php echo $class['id']; ?>, 'partial-months-<?php echo $class['id']; ?>', this)">
                                Partial <span class="badge bg-warning rounded-pill ms-1"><?php echo $partial_count; ?></span>
                            </button>
                        </li>
                        <?php endif; ?>
                        <?php if ($unpaid_count > 0): ?>
                        <li class="nav-item">
                            <button class="payment-tab" onclick="switchTab(<?php echo $class['id']; ?>, 'unpaid-months-<?php echo $class['id']; ?>', this)">
                                Unpaid <span class="badge bg-danger rounded-pill ms-1"><?php echo $unpaid_count; ?></span>
                            </button>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <button class="payment-tab" onclick="switchTab(<?php echo $class['id']; ?>, 'payment-history-<?php echo $class['id']; ?>', this)">
                                <i class="bi bi-clock-history me-1"></i>History
                            </button>
                        </li>
                    </ul>

                    <div class="tab-panel active" id="paid-months-<?php echo $class['id']; ?>">
                        <?php if (empty($class['paid_months'])): ?>
                            <p class="no-months">No fully paid months yet.</p>
                        <?php else: ?>
                            <?php foreach ($class['paid_months'] as $month): ?>
                            <div class="month-row paid-row">
                                <span class="month-name"><i class="bi bi-check-circle text-success me-2"></i><?php echo $month['month']; ?></span>
                                <span class="month-amount"><?php echo formatCurrency($month['amount_due']); ?></span>
                                <?php if (!empty($month['receipt_number'])): ?>
                                <a href="receipt.php?receipt_number=<?php echo urlencode($month['receipt_number']); ?>" class="receipt-link" target="_blank" title="View Receipt">
                                    <i class="bi bi-receipt"></i> <?php echo htmlspecialchars($month['receipt_number']); ?>
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="tab-panel" id="partial-months-<?php echo $class['id']; ?>">
                        <?php if (empty($class['partial_months'])): ?>
                            <p class="no-months">No partial payments.</p>
                        <?php else: ?>
                            <?php foreach ($class['partial_months'] as $month): ?>
                            <div class="month-row partial-row">
                                <div>
                                    <div class="month-name"><i class="bi bi-dash-circle text-warning me-2"></i><?php echo $month['month']; ?></div>
                                    <div class="month-balance">Balance: <?php echo formatCurrency($month['amount_due'] - $month['amount_paid']); ?></div>
                                </div>
                                <span class="month-amount text-warning"><?php echo formatCurrency($month['amount_paid']); ?> / <?php echo formatCurrency($month['amount_due']); ?></span>
                                <?php if (!empty($month['receipt_number'])): ?>
                                <a href="receipt.php?receipt_number=<?php echo urlencode($month['receipt_number']); ?>" class="receipt-link" target="_blank" title="View Receipt">
                                    <i class="bi bi-receipt"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="tab-panel" id="unpaid-months-<?php echo $class['id']; ?>">
                        <?php if (empty($class['unpaid_months'])): ?>
                            <p class="no-months">No unpaid months. Great job!</p>
                        <?php else: ?>
                            <div class="alert alert-warning border-0 rounded-3 mb-2" style="font-size:0.85rem;">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Total outstanding for unpaid months: <strong><?php echo formatCurrency($class['outstanding'] - ($class['partial_months'] ? array_sum(array_map(fn($m) => $m['amount_due'] - $m['amount_paid'], $class['partial_months'])) : 0)); ?></strong>
                            </div>
                            <?php foreach ($class['unpaid_months'] as $month): ?>
                            <div class="month-row unpaid-row">
                                <span class="month-name"><i class="bi bi-x-circle text-danger me-2"></i><?php echo $month['month']; ?></span>
                                <span class="month-amount text-danger"><?php echo formatCurrency($month['amount_due']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="tab-panel" id="payment-history-<?php echo $class['id']; ?>">
                        <?php if (empty($class['payments'])): ?>
                            <div class="empty-state">
                                <i class="bi bi-clock-history"></i>
                                <p>No payment records found.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-custom">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt</th>
                                            <th>Method</th>
                                            <th>Total</th>
                                            <th>Paid</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($class['payments'] as $payment): ?>
                                        <tr>
                                            <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                            <td>
                                                <?php if (!empty($payment['receipt_number'])): ?>
                                                <a href="receipt.php?receipt_number=<?php echo urlencode($payment['receipt_number']); ?>" class="receipt-link" target="_blank">
                                                    <?php echo htmlspecialchars($payment['receipt_number']); ?>
                                                </a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['payment_method'] ?? '—'); ?></td>
                                            <td class="text-amount"><?php echo formatCurrency($payment['total_amount']); ?></td>
                                            <td class="text-amount"><?php echo formatCurrency($payment['paid_amount']); ?></td>
                                            <td>
                                                <span class="status-pill status-<?php echo getPaymentStatusBadge($payment['status']); ?>">
                                                    <?php echo getPaymentStatusLabel($payment['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <hr class="section-divider">
        <p class="text-center text-muted" style="font-size:0.8rem;">
            <i class="bi bi-shield-lock me-1"></i>
            Only your academic and payment information is visible here. Login is session-based and expires on logout.
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function switchTab(classId, tabId, btn) {
            var card = document.getElementById('class-' + classId);
            var tabs = card.querySelectorAll('.tab-panel');
            var tabBtns = card.querySelectorAll('.payment-tab');
            tabs.forEach(function(p) { p.classList.remove('active'); });
            tabBtns.forEach(function(b) { b.classList.remove('active'); });
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
        }
    </script>
</body>
</html>

