<?php
require_once __DIR__ . '/../includes/functions.php';

function checkRateLimit($maxRequests = 10, $windowSeconds = 60) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cacheDir = sys_get_temp_dir() . '/qr_api_rate_limit/';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $file = $cacheDir . md5($ip) . '.json';
    $now = time();
    $data = ['count' => 0, 'start' => $now];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($now - $data['start'] >= $windowSeconds) {
            $data = ['count' => 0, 'start' => $now];
        }
    }
    if ($data['count'] >= $maxRequests) {
        return false;
    }
    $data['count']++;
    file_put_contents($file, json_encode($data));
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

if (!checkRateLimit(10, 60)) {
    jsonResponse(['error' => 'Rate limit exceeded. Please try again later.'], 429);
}

$pdo = db();

$required = ['student_id', 'class_id', 'amount', 'payment_method_id', 'billing_months'];
$missing = [];
foreach ($required as $field) {
    if (!isset($_POST[$field]) || $_POST[$field] === '') {
        $missing[] = $field;
    }
}
if ($missing) {
    jsonResponse(['error' => 'Missing required fields', 'missing' => $missing], 400);
}

$student_id = $_POST['student_id'];
$class_id = $_POST['class_id'];
$amount = floatval($_POST['amount']);
$payment_method_id = intval($_POST['payment_method_id']);
$billing_months = $_POST['billing_months'];

if (!is_array($billing_months)) {
    $billing_months = json_decode($billing_months, true);
}

if (!is_array($billing_months) || empty($billing_months)) {
    jsonResponse(['error' => 'Invalid billing_months format. Expected JSON array.'], 400);
}

foreach ($billing_months as $month) {
    if (!isset($month['month']) || !isset($month['year'])) {
        jsonResponse(['error' => 'Invalid billing month format. Each item must have month and year.'], 400);
    }
    $m = intval($month['month']);
    if ($m < 1 || $m > 12) {
        jsonResponse(['error' => 'Invalid billing month: ' . $m], 400);
    }
}

if ($amount <= 0) {
    jsonResponse(['error' => 'Amount must be greater than zero'], 400);
}

$student_stmt = $pdo->prepare("SELECT id, student_id, full_name, status FROM students WHERE id = ?");
$student_stmt->execute([$student_id]);
$student = $student_stmt->fetch();

if (!$student) {
    jsonResponse(['error' => 'Student not found'], 404);
}

if ($student['status'] !== 'active') {
    jsonResponse(['error' => 'Student is inactive'], 400);
}

$class_stmt = $pdo->prepare("SELECT id, name, monthly_fee, status FROM classes WHERE id = ?");
$class_stmt->execute([$class_id]);
$class = $class_stmt->fetch();

if (!$class) {
    jsonResponse(['error' => 'Class not found'], 404);
}

if ($class['status'] !== 'active') {
    jsonResponse(['error' => 'Class is inactive'], 400);
}

$method_stmt = $pdo->prepare("SELECT id, name, status FROM payment_methods WHERE id = ?");
$method_stmt->execute([$payment_method_id]);
$method = $method_stmt->fetch();

if (!$method) {
    jsonResponse(['error' => 'Payment method not found'], 404);
}

if ($method['status'] !== 'active') {
    jsonResponse(['error' => 'Payment method is inactive'], 400);
}

$expected_total = $class['monthly_fee'] * count($billing_months);
if ($amount > $expected_total) {
    jsonResponse(['error' => 'Amount exceeds expected total', 'expected' => (float)$expected_total, 'received' => (float)$amount], 400);
}

$receipt_number = generateReceiptNumber();
$collector_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

try {
    $pdo->beginTransaction();
    
    $payment_stmt = $pdo->prepare("
        INSERT INTO payments (student_id, class_id, collector_id, payment_method_id, total_amount, paid_amount, status, receipt_number, notes)
        VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, ?)
    ");
    $payment_stmt->execute([$student_id, $class_id, $collector_id, $payment_method_id, $amount, $amount, $receipt_number, 'API Payment']);
    $payment_id = $pdo->lastInsertId();
    
    $amount_per_month = $amount / count($billing_months);
    $billing_details = [];
    
    foreach ($billing_months as $month) {
        $billing_month = intval($month['month']);
        $billing_year = intval($month['year']);
        $status = ($amount_per_month >= $class['monthly_fee']) ? 'paid' : 'partial';
        
        $item_stmt = $pdo->prepare("
            INSERT INTO payment_items (payment_id, billing_month, billing_year, amount_due, amount_paid, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $item_stmt->execute([$payment_id, $billing_month, $billing_year, $class['monthly_fee'], $amount_per_month, $status]);
        
        $billing_details[] = getBillingMonths()[$billing_month] . ' ' . $billing_year;
    }
    
    $institute_name = getSetting($pdo, 'institute_name', 'Institute');
    $institute_address = getSetting($pdo, 'institute_address', '');
    $institute_phone = getSetting($pdo, 'institute_phone', '');
    $institute_logo = getSetting($pdo, 'institute_logo', '');
    
    $receipt_stmt = $pdo->prepare("
        INSERT INTO receipts (payment_id, receipt_number, student_id, class_id, amount, payment_date, collector_id, payment_method, billing_details, institute_name, institute_address, institute_phone, institute_logo)
        VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)
    ");
    $receipt_stmt->execute([
        $payment_id, $receipt_number, $student_id, $class_id, $amount, 
        $collector_id, $method['name'], implode(', ', $billing_details),
        $institute_name, $institute_address, $institute_phone, $institute_logo
    ]);
    
    if ($collector_id) {
        logAudit($pdo, $collector_id, 'payment_processed', "Payment of $amount for student $student_id in class $class_id");
    }
    
    $pdo->commit();
    
    jsonResponse([
        'success' => true,
        'message' => 'Payment processed successfully',
        'payment' => [
            'payment_id' => (int)$payment_id,
            'receipt_number' => $receipt_number,
            'student_id' => $student['student_id'],
            'student_name' => $student['full_name'],
            'class_id' => (int)$class_id,
            'class_name' => $class['name'],
            'total_amount' => (float)$amount,
            'paid_amount' => (float)$amount,
            'status' => 'completed',
            'payment_method' => $method['name'],
            'billing_details' => $billing_details,
            'payment_date' => date('Y-m-d')
        ]
    ], 201);
    
} catch (Exception $e) {
    $pdo->rollBack();
    jsonResponse(['error' => 'Payment processing failed', 'message' => $e->getMessage()], 500);
}
