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

$student_id = $_GET['student_id'] ?? null;

if (!$student_id) {
    jsonResponse(['error' => 'Missing required parameter: student_id'], 400);
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT s.id, s.student_id, s.full_name, s.email, s.phone, s.address, s.date_of_birth, 
           s.guardian_name, s.guardian_phone, s.status, s.created_at
    FROM students s
    WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    jsonResponse(['error' => 'Student not found'], 404);
}

$class_stmt = $pdo->prepare("
    SELECT c.id, c.name, c.monthly_fee, c.description, c.status as class_status,
           t.name as teacher_name, t.email as teacher_email, t.phone as teacher_phone,
           ce.enrollment_date, ce.status as enrollment_status
    FROM class_enrollments ce
    JOIN classes c ON ce.class_id = c.id
    LEFT JOIN teachers t ON c.teacher_id = t.id
    WHERE ce.student_id = ? AND ce.status = 'active'
");
$class_stmt->execute([$student['id']]);
$classes = $class_stmt->fetchAll();

$classes_data = [];
foreach ($classes as $class) {
    $outstanding = getStudentOutstanding($pdo, $student['id'], $class['id']);
    $unpaid_months = getUnpaidMonths($pdo, $student['id'], $class['id']);
    
    $payment_stmt = $pdo->prepare("
        SELECT p.id, p.total_amount, p.paid_amount, p.status, p.payment_date, p.receipt_number,
               pm.name as payment_method, u.full_name as collector_name
        FROM payments p
        LEFT JOIN payment_methods pm ON p.payment_method_id = pm.id
        LEFT JOIN users u ON p.collector_id = u.id
        WHERE p.student_id = ? AND p.class_id = ?
        ORDER BY p.payment_date DESC
    ");
    $payment_stmt->execute([$student['id'], $class['id']]);
    $payments = $payment_stmt->fetchAll();
    
    $classes_data[] = [
        'class_id' => (int)$class['id'],
        'class_name' => $class['name'],
        'monthly_fee' => (float)$class['monthly_fee'],
        'description' => $class['description'],
        'status' => $class['class_status'],
        'teacher' => [
            'name' => $class['teacher_name'],
            'email' => $class['teacher_email'],
            'phone' => $class['teacher_phone']
        ],
        'enrollment_date' => $class['enrollment_date'],
        'outstanding' => (float)$outstanding,
        'unpaid_months' => (int)$unpaid_months,
        'payment_history' => array_map(function($p) {
            return [
                'payment_id' => (int)$p['id'],
                'total_amount' => (float)$p['total_amount'],
                'paid_amount' => (float)$p['paid_amount'],
                'status' => $p['status'],
                'payment_date' => $p['payment_date'],
                'receipt_number' => $p['receipt_number'],
                'payment_method' => $p['payment_method'],
                'collector_name' => $p['collector_name']
            ];
        }, $payments)
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
        'status' => $student['status'],
        'created_at' => $student['created_at']
    ],
    'classes' => $classes_data,
    'total_outstanding' => array_sum(array_column($classes_data, 'outstanding'))
];

jsonResponse($response);
