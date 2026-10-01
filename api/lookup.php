<?php
require_once __DIR__ . '/../includes/functions.php';

function checkRateLimit($maxRequests = 30, $windowSeconds = 60) {
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

if (!checkRateLimit()) {
    jsonResponse(['error' => 'Rate limit exceeded. Please try again later.'], 429);
}

$pdo = db();
$token = $_GET['token'] ?? null;
$card_id = $_GET['card_id'] ?? null;
$student_id = $_GET['student_id'] ?? null;
$q = $_GET['q'] ?? null;

if (!$token && !$card_id && !$student_id && !$q) {
    jsonResponse(['error' => 'Missing required parameter: token, card_id, student_id, or q'], 400);
}

if ($q) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.student_id, s.full_name, s.email, s.phone, s.status
        FROM students s
        WHERE s.full_name LIKE ? OR s.student_id LIKE ? OR s.phone LIKE ?
        ORDER BY s.full_name ASC
        LIMIT 20
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $students = $stmt->fetchAll();
    jsonResponse(['success' => true, 'students' => $students]);
}

$sql = "SELECT s.id, s.student_id, s.full_name, s.email, s.phone, s.address, s.date_of_birth, 
               s.guardian_name, s.guardian_phone, s.status,
               sc.card_id, sc.qr_token, sc.issue_date, sc.status as card_status
        FROM students s
        LEFT JOIN student_cards sc ON s.id = sc.student_id AND sc.status = 'active'
        WHERE 1=0";
$params = [];

if ($token) {
    $sql .= " OR sc.qr_token = ?";
    $params[] = $token;
}
if ($card_id) {
    $sql .= " OR sc.card_id = ?";
    $params[] = $card_id;
}
if ($student_id) {
    $sql .= " OR s.student_id = ?";
    $params[] = $student_id;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$student = $stmt->fetch();

if (!$student) {
    jsonResponse(['error' => 'Student not found'], 404);
}

$class_stmt = $pdo->prepare("
    SELECT c.id, c.name, c.monthly_fee, ce.enrollment_date, ce.status as enrollment_status
    FROM class_enrollments ce
    JOIN classes c ON ce.class_id = c.id
    WHERE ce.student_id = ? AND ce.status = 'active'
");
$class_stmt->execute([$student['id']]);
$classes = $class_stmt->fetchAll();

$payment_summary = [];
foreach ($classes as $class) {
    $outstanding = getStudentOutstanding($pdo, $student['id'], $class['id']);
    $unpaid_months = getUnpaidMonths($pdo, $student['id'], $class['id']);
    $current_month = (int)date('n');
    $current_year = (int)date('Y');
    $payment_status = getStudentPaymentStatusForMonth($pdo, $student['id'], $class['id'], $current_year, $current_month);
    $payment_summary[] = [
        'class_id' => (int)$class['id'],
        'class_name' => $class['name'],
        'monthly_fee' => (float)$class['monthly_fee'],
        'outstanding' => (float)$outstanding,
        'unpaid_months' => (int)$unpaid_months,
        'payment_status' => $payment_status['status'],
        'payment_label' => $payment_status['label'],
        'due_date' => $payment_status['due_date'],
        'days_text' => $payment_status['days_text'] ?? ''
    ];
}

$response = [
    'student' => [
        'id' => (int)$student['id'],
        'student_id' => $student['student_id'],
        'full_name' => $student['full_name'],
        'email' => $student['email'],
        'phone' => $student['phone'],
        'address' => $student['address'],
        'date_of_birth' => $student['date_of_birth'],
        'guardian_name' => $student['guardian_name'],
        'guardian_phone' => $student['guardian_phone'],
        'status' => $student['status']
    ],
    'card' => $student['card_id'] ? [
        'card_id' => $student['card_id'],
        'qr_token' => $student['qr_token'],
        'issue_date' => $student['issue_date'],
        'status' => $student['card_status']
    ] : null,
    'classes' => $payment_summary,
    'total_outstanding' => array_sum(array_column($payment_summary, 'outstanding'))
];

jsonResponse($response);
