<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';

$pdo = db();
$institute_name = getSetting($pdo, 'institute_name', APP_NAME);
$institute_address = getSetting($pdo, 'institute_address', '');
$institute_phone = getSetting($pdo, 'institute_phone', '');
$institute_logo = getSetting($pdo, 'institute_logo', '');

$receipt_number = $_GET['receipt_number'] ?? '';
$receipt_number = preg_replace('/[^A-Za-z0-9\-_]/', '', $receipt_number);

if (empty($receipt_number)) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.*, p.payment_date as payment_date, p.status as payment_status,
           p.total_amount as payment_total, p.paid_amount as payment_paid,
           s.full_name as student_name, s.student_id,
           c.name as class_name,
           t.name as teacher_name
    FROM receipts r
    JOIN payments p ON r.payment_id = p.id
    JOIN students s ON r.student_id = s.id
    JOIN classes c ON r.class_id = c.id
    LEFT JOIN teachers t ON c.teacher_id = t.id
    WHERE r.receipt_number = ?
    LIMIT 1
");
$stmt->execute([$receipt_number]);
$receipt = $stmt->fetch();

if (!$receipt) {
    header('Location: dashboard.php');
    exit;
}

$billing_details = [];
if (!empty($receipt['billing_details'])) {
    $decoded = json_decode($receipt['billing_details'], true);
    if (is_array($decoded)) {
        $billing_details = $decoded;
    }
}

$display_date = !empty($receipt['payment_date']) ? date('d M Y', strtotime($receipt['payment_date'])) : date('d M Y');
$payment_method = $receipt['payment_method'] ?? 'Cash';
$amount = (float)$receipt['amount'];
$status = $receipt['payment_status'] ?? 'completed';
$balance_due = max(0, (float)$receipt['payment_total'] - (float)$receipt['payment_paid']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?php echo htmlspecialchars($receipt_number); ?> - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary: #1a1a2e; --accent: #667eea; }
        * { box-sizing: border-box; }
        body {
            background: #f0f2f8;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }

        /* Top bar */
        .top-bar {
            background: var(--primary);
            padding: 12px 0;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
        }
        .top-bar-inner {
            max-width: 780px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .top-bar .brand-text { color: white; font-weight: 700; font-size: 1rem; }
        .top-bar-actions { display: flex; gap: 8px; }
        .btn-sm-custom {
            background: rgba(255,255,255,0.12);
            color: white;
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 0.83rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
            cursor: pointer;
        }
        .btn-sm-custom:hover { background: rgba(255,255,255,0.25); color: white; }

        /* Receipt */
        .receipt-wrap {
            max-width: 780px;
            margin: 24px auto;
            padding: 0 16px;
        }
        .receipt-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        /* Receipt header */
        .receipt-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: white;
            padding: 28px 32px;
            text-align: center;
        }
        .receipt-header .inst-logo {
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 10px;
        }
        .receipt-header h1 { font-size: 1.4rem; font-weight: 700; margin: 0; }
        .receipt-header .inst-details {
            font-size: 0.82rem;
            opacity: 0.75;
            margin-top: 6px;
            line-height: 1.6;
        }

        /* Receipt number bar */
        .receipt-number-bar {
            background: #f8f9fd;
            border-bottom: 1px solid #eef0f5;
            padding: 14px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .receipt-number-bar .rn-label {
            font-size: 0.78rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .receipt-number-bar .rn-value {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary);
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
        }
        .status-pill-lg {
            display: inline-flex;
            align-items: center;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .st-paid { background: #d4edda; color: #155724; }
        .st-partial { background: #fff3cd; color: #856404; }
        .st-pending { background: #e2e3e5; color: #383d41; }
        .st-default { background: #d1ecf1; color: #0c5460; }

        /* Receipt body */
        .receipt-body { padding: 28px 32px; }
        .receipt-section { margin-bottom: 24px; }
        .receipt-section:last-child { margin-bottom: 0; }

        .party-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 576px) {
            .party-grid { grid-template-columns: 1fr; }
        }
        .party-box {
            background: #f8f9fd;
            border-radius: 10px;
            padding: 16px;
            border: 1px solid #eef0f5;
        }
        .party-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            color: #888;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .party-name { font-weight: 700; color: #1a1a2e; font-size: 0.95rem; }
        .party-detail { font-size: 0.85rem; color: #555; margin-top: 2px; }

        /* Payment summary */
        .summary-table { width: 100%; border-collapse: collapse; }
        .summary-table td {
            padding: 8px 4px;
            font-size: 0.9rem;
            border-bottom: 1px solid #f0f2f5;
        }
        .summary-table td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
        .summary-table tr.total-row td {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary);
            border-bottom: none;
            border-top: 2px solid #eef0f5;
            padding-top: 12px;
        }
        .summary-table tr.balance-row td {
            font-size: 0.9rem;
            color: #e65100;
            font-weight: 600;
        }
        .summary-table tr.balance-row.zero td { color: #28a745; }

        /* Billing details */
        .billing-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .billing-table thead th {
            background: #f0f2f8;
            color: #555;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 9px 12px;
            border-bottom: 2px solid #e2e5f0;
        }
        .billing-table tbody td {
            padding: 9px 12px;
            border-bottom: 1px solid #f0f2f5;
        }
        .billing-table tbody tr:last-child td { border-bottom: none; }

        /* Footer */
        .receipt-footer {
            background: #f8f9fd;
            border-top: 1px solid #eef0f5;
            padding: 18px 32px;
            text-align: center;
        }
        .receipt-footer p { margin: 0; font-size: 0.82rem; color: #888; }
        .receipt-footer .thank-you { font-weight: 600; color: #1a1a2e; font-size: 0.9rem; margin-bottom: 4px; }

        /* Print button */
        .btn-print {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 22px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .btn-print:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(102,126,234,0.4);
            color: white;
        }

        /* Watermark */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-35deg);
            font-size: 6rem;
            font-weight: 900;
            color: rgba(0,0,0,0.03);
            pointer-events: none;
            z-index: 0;
            letter-spacing: 4px;
            white-space: nowrap;
        }

        /* Print */
        @media print {
            body { background: white !important; }
            .top-bar, .btn-print, .watermark { display: none !important; }
            .receipt-wrap { padding: 0; margin: 0; max-width: 100%; }
            .receipt-card { box-shadow: none; border-radius: 0; }
            .receipt-header { background: #1a1a2e !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .receipt-number-bar { background: #f8f9fd !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .receipt-footer { background: #f8f9fd !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }

        @media (max-width: 576px) {
            .receipt-header { padding: 20px 18px; }
            .receipt-header h1 { font-size: 1.1rem; }
            .receipt-number-bar { padding: 12px 18px; }
            .receipt-body { padding: 20px 18px; }
            .receipt-footer { padding: 14px 18px; }
        }
    </style>
</head>
<body>

    <div class="watermark">PAID</div>

    <div class="top-bar">
        <div class="top-bar-inner">
            <div class="brand-text">
                <i class="bi bi-receipt me-1"></i>Receipt Viewer
            </div>
            <div class="top-bar-actions">
                <a href="dashboard.php" class="btn-sm-custom">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
                <button class="btn-sm-custom" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>

    <div class="receipt-wrap">
        <div class="receipt-card">

            <div class="receipt-header">
                <div class="inst-logo">
                    <?php if (!empty($institute_logo) && file_exists(__DIR__ . '/../' . $institute_logo)): ?>
                        <img src="<?php echo htmlspecialchars($institute_logo); ?>" alt="Logo" style="max-height:40px;max-width:40px;border-radius:50%;">
                    <?php else: ?>
                        <i class="bi bi-mortarboard"></i>
                    <?php endif; ?>
                </div>
                <h1><?php echo htmlspecialchars($institute_name); ?></h1>
                <div class="inst-details">
                    <?php if ($institute_address): ?>
                        <?php echo nl2br(htmlspecialchars($institute_address)); ?><br>
                    <?php endif; ?>
                    <?php if ($institute_phone): ?>
                        <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($institute_phone); ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="receipt-number-bar">
                <div>
                    <div class="rn-label">Receipt Number</div>
                    <div class="rn-value"><?php echo htmlspecialchars($receipt['receipt_number']); ?></div>
                </div>
                <div style="text-align:right;">
                    <div class="rn-label">Date</div>
                    <div style="font-weight:700;color:var(--primary);font-size:0.95rem;"><?php echo $display_date; ?></div>
                </div>
                <div>
                    <?php
                    $st_class = 'st-default';
                    if ($status === 'completed') $st_class = 'st-paid';
                    elseif ($status === 'partial') $st_class = 'st-partial';
                    elseif ($status === 'pending') $st_class = 'st-pending';
                    ?>
                    <span class="status-pill-lg <?php echo $st_class; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                    </span>
                </div>
            </div>

            <div class="receipt-body">

                <div class="receipt-section">
                    <div class="party-grid">
                        <div class="party-box">
                            <div class="party-label"><i class="bi bi-person me-1"></i>Received From</div>
                            <div class="party-name"><?php echo htmlspecialchars($receipt['student_name']); ?></div>
                            <div class="party-detail">ID: <?php echo htmlspecialchars($receipt['student_id']); ?></div>
                        </div>
                        <div class="party-box">
                            <div class="party-label"><i class="bi bi-book me-1"></i>Class Details</div>
                            <div class="party-name"><?php echo htmlspecialchars($receipt['class_name']); ?></div>
                            <?php if (!empty($receipt['teacher_name'])): ?>
                            <div class="party-detail"><i class="bi bi-person-workspace me-1"></i><?php echo htmlspecialchars($receipt['teacher_name']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="receipt-section">
                    <div class="party-box">
                        <div class="party-label"><i class="bi bi-currency-rupee me-1"></i>Payment Summary</div>
                        <table class="summary-table">
                            <tr>
                                <td>Payment Method</td>
                                <td><?php echo htmlspecialchars($payment_method); ?></td>
                            </tr>
                            <tr>
                                <td>Total Amount</td>
                                <td><?php echo formatCurrency((float)$receipt['payment_total']); ?></td>
                            </tr>
                            <?php if ($balance_due > 0): ?>
                            <tr class="balance-row">
                                <td>Previous Balance</td>
                                <td><?php echo formatCurrency($balance_due); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="total-row">
                                <td>Amount Paid</td>
                                <td><?php echo formatCurrency($amount); ?></td>
                            </tr>
                            <?php if ($balance_due > 0): ?>
                            <tr class="balance-row zero">
                                <td>Remaining Balance</td>
                                <td><?php echo formatCurrency(max(0, $balance_due - $amount)); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <?php if (!empty($billing_details)): ?>
                <div class="receipt-section">
                    <div class="party-box">
                        <div class="party-label"><i class="bi bi-calendar3 me-1"></i>Billing Details</div>
                        <table class="billing-table">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th style="text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($billing_details as $detail): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($detail['month'] ?? 'N/A'); ?></td>
                                    <td style="text-align:right;font-weight:600;font-variant-numeric:tabular-nums;">
                                        <?php echo formatCurrency((float)($detail['amount'] ?? 0)); ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php
                                        $item_status = $detail['status'] ?? 'paid';
                                        if ($item_status === 'paid') echo '<span class="status-pill st-paid">Paid</span>';
                                        elseif ($item_status === 'partial') echo '<span class="status-pill st-partial">Partial</span>';
                                        elseif ($item_status === 'unpaid') echo '<span class="status-pill st-pending">Unpaid</span>';
                                        else echo '<span class="status-pill st-default">' . htmlspecialchars($item_status) . '</span>';
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <div class="receipt-footer">
                <p class="thank-you">Thank you for your payment!</p>
                <p>
                    This is a system-generated receipt. For queries, contact <?php echo htmlspecialchars($institute_name); ?>.
                    <?php if ($institute_phone): ?>
                        Call: <?php echo htmlspecialchars($institute_phone); ?>
                    <?php endif; ?>
                </p>
                <p style="margin-top:6px;font-size:0.75rem;color:#bbb;">
                    Receipt No: <?php echo htmlspecialchars($receipt['receipt_number']); ?> &middot;
                    Generated: <?php echo date('d M Y H:i'); ?>
                </p>
            </div>

        </div>

        <div class="text-center mt-3 mb-4">
            <button class="btn-print" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Receipt
            </button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

