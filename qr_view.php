<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$token = $_GET['token'] ?? '';
$print = isset($_GET['print']);

if (empty($token)) {
    http_response_code(400);
    die('Invalid request. Token is required.');
}

$pdo = db();
$stmt = $pdo->prepare("
    SELECT sc.*, s.full_name, s.student_id, s.phone, s.guardian_name, s.guardian_phone
    FROM student_cards sc
    JOIN students s ON sc.student_id = s.id
    WHERE sc.qr_token = ? AND sc.status = 'active'
");
$stmt->execute([$token]);
$card = $stmt->fetch();

if (!$card) {
    http_response_code(404);
    die('QR card not found or inactive.');
}

$qr_url = getQrCodeUrl($card['qr_token'], 300);
$institute_name = getSetting($pdo, 'institute_name', 'Institute');
$institute_address = getSetting($pdo, 'institute_address', '');
$institute_phone = getSetting($pdo, 'institute_phone', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Card - <?php echo htmlspecialchars($card['full_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f6fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; }
        .qr-card-container {
            max-width: 420px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .qr-card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }
        .qr-card-header h2 { margin: 0; font-size: 1.6rem; }
        .qr-card-header p { margin: 5px 0 0; opacity: 0.9; font-size: 0.9rem; }
        .qr-card-body { padding: 25px; text-align: center; }
        .qr-image {
            width: 220px;
            height: 220px;
            margin: 0 auto 20px;
            border: 3px solid #667eea;
            border-radius: 15px;
            padding: 10px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qr-image img { width: 100%; height: 100%; object-fit: contain; }
        .student-name {
            font-size: 1.4rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }
        .card-id-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #667eea;
            color: white;
            padding: 10px 24px;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 600;
            margin: 10px 0;
        }
        .student-info {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 18px;
            margin: 20px 0;
            text-align: left;
        }
        .student-info h6 { color: #667eea; margin-bottom: 12px; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .student-info .info-row {
            display: flex;
            justify-content: space-between;
            padding: 7px 0;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.9rem;
        }
        .student-info .info-row:last-child { border-bottom: none; }
        .student-info .info-label { font-weight: 600; color: #495057; }
        .student-info .info-value { color: #212529; }
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        @media print {
            body { background: white; padding: 0; }
            .qr-card-container { box-shadow: none; margin: 0; max-width: 100%; border-radius: 0; }
            .no-print { display: none !important; }
            .qr-card-header { background: #667eea !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media (max-width: 480px) {
            body { padding: 10px; }
            .qr-image { width: 180px; height: 180px; }
        }
    </style>
</head>
<body>
    <div class="qr-card-container">
        <div class="qr-card-header">
            <h2><?php echo htmlspecialchars($institute_name); ?></h2>
            <?php if ($institute_address): ?>
                <p><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($institute_address); ?></p>
            <?php endif; ?>
            <?php if ($institute_phone): ?>
                <p><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($institute_phone); ?></p>
            <?php endif; ?>
        </div>
        <div class="qr-card-body">
            <div class="qr-image">
                <img src="<?php echo $qr_url; ?>" alt="QR Code" onerror="this.src='https://via.placeholder.com/200x200?text=QR+Code'">
            </div>
            <div class="student-name"><?php echo htmlspecialchars($card['full_name']); ?></div>
            <div class="card-id-badge">
                <i class="bi bi-credit-card"></i>
                <?php echo htmlspecialchars($card['card_id']); ?>
            </div>
            <div class="student-info">
                <h6><i class="bi bi-person me-2"></i>Student Information</h6>
                <div class="info-row">
                    <span class="info-label">Student ID:</span>
                    <span class="info-value"><?php echo htmlspecialchars($card['student_id']); ?></span>
                </div>
                <?php if ($card['phone']): ?>
                    <div class="info-row">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?php echo htmlspecialchars($card['phone']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($card['guardian_name']): ?>
                    <div class="info-row">
                        <span class="info-label">Guardian:</span>
                        <span class="info-value"><?php echo htmlspecialchars($card['guardian_name']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($card['guardian_phone']): ?>
                    <div class="info-row">
                        <span class="info-label">Guardian Phone:</span>
                        <span class="info-value"><?php echo htmlspecialchars($card['guardian_phone']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Issue Date:</span>
                    <span class="info-value"><?php echo formatDate($card['issue_date']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status:</span>
                    <span class="badge bg-<?php echo $card['status'] === 'active' ? 'success' : 'secondary'; ?>">
                        <?php echo ucfirst($card['status']); ?>
                    </span>
                </div>
            </div>
            <div class="action-buttons no-print">
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="bi bi-printer me-2"></i>Print
                </button>
                <button onclick="window.close()" class="btn btn-secondary">
                    <i class="bi bi-x-circle me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
    <?php if ($print): ?>
        <script>window.print();</script>
    <?php endif; ?>
    <script>
        if (window.opener && !window.opener.closed && window.location.search.indexOf('print=') === -1) {
            document.querySelector('.action-buttons').innerHTML = '';
        }
    </script>
</body>
</html>
