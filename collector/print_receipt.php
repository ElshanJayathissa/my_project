<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('collector');
$pdo = db();

$receipt_number = $_GET['receipt_number'] ?? '';
$receipt = null;

if ($receipt_number) {
    $stmt = $pdo->prepare("
        SELECT r.*, s.full_name, s.student_id, c.name as class_name
        FROM receipts r
        JOIN students s ON r.student_id = s.id
        JOIN classes c ON r.class_id = c.id
        WHERE r.receipt_number = ?
    ");
    $stmt->execute([$receipt_number]);
    $receipt = $stmt->fetch();
}

if (!$receipt) {
    http_response_code(404);
    die('Receipt not found');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo sanitize($receipt['receipt_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            padding: 20px;
        }
        .receipt-wrapper {
            max-width: 500px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .receipt-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }
        .receipt-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }
        .receipt-header p {
            margin: 5px 0 0;
            opacity: 0.9;
            font-size: 0.85rem;
        }
        .receipt-body {
            padding: 25px;
        }
        .receipt-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .receipt-row:last-child {
            border-bottom: none;
        }
        .receipt-label {
            font-weight: 600;
            color: #666;
        }
        .receipt-value {
            text-align: right;
            color: #333;
        }
        .receipt-amount {
            font-size: 1.5rem;
            font-weight: bold;
            color: #28a745;
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 20px 0;
        }
        .receipt-footer {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-top: 2px dashed #dee2e6;
        }
        .receipt-footer p {
            margin: 5px 0;
            font-size: 0.85rem;
            color: #666;
        }
        .no-print {
            max-width: 500px;
            margin: 20px auto;
            text-align: center;
        }
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .receipt-wrapper {
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
            }
            .no-print {
                display: none !important;
            }
            .receipt-header {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="bi bi-printer me-2"></i>Print Receipt
        </button>
        <button onclick="window.close()" class="btn btn-secondary">
            <i class="bi bi-x-circle me-2"></i>Close
        </button>
    </div>

    <div class="receipt-wrapper">
        <div class="receipt-header">
            <h2><?php echo sanitize($receipt['institute_name'] ?? 'Institute'); ?></h2>
            <?php if (!empty($receipt['institute_address'])): ?>
                <p><?php echo nl2br(sanitize($receipt['institute_address'])); ?></p>
            <?php endif; ?>
            <?php if (!empty($receipt['institute_phone'])): ?>
                <p>Phone: <?php echo sanitize($receipt['institute_phone']); ?></p>
            <?php endif; ?>
        </div>
        <div class="receipt-body">
            <h5 class="text-center mb-4">RECEIPT</h5>
            <div class="receipt-row">
                <span class="receipt-label">Receipt No:</span>
                <span class="receipt-value"><?php echo sanitize($receipt['receipt_number']); ?></span>
            </div>
            <div class="receipt-row">
                <span class="receipt-label">Date:</span>
                <span class="receipt-value"><?php echo formatDate($receipt['payment_date']); ?></span>
            </div>
            <div class="receipt-row">
                <span class="receipt-label">Student:</span>
                <span class="receipt-value"><?php echo sanitize($receipt['full_name']); ?></span>
            </div>
            <div class="receipt-row">
                <span class="receipt-label">Student ID:</span>
                <span class="receipt-value"><?php echo sanitize($receipt['student_id']); ?></span>
            </div>
            <div class="receipt-row">
                <span class="receipt-label">Class:</span>
                <span class="receipt-value"><?php echo sanitize($receipt['class_name']); ?></span>
            </div>
            <div class="receipt-amount">
                <?php echo formatCurrency($receipt['amount']); ?>
            </div>
            <div class="receipt-row">
                <span class="receipt-label">Payment Method:</span>
                <span class="receipt-value"><?php echo sanitize($receipt['payment_method'] ?? 'N/A'); ?></span>
            </div>
            <?php if (!empty($receipt['billing_details'])): ?>
                <div class="receipt-row">
                    <span class="receipt-label">Months:</span>
                    <span class="receipt-value"><?php echo sanitize($receipt['billing_details']); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <div class="receipt-footer">
            <p class="mb-0"><strong>Thank you for your payment!</strong></p>
            <p class="mb-0 text-muted">Generated: <?php echo date('d M Y h:i A'); ?></p>
        </div>
    </div>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
