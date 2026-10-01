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
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Rate limit exceeded. Please try again later.']);
    exit;
}

$student_id = $_GET['student_id'] ?? null;
$card_id = $_GET['card_id'] ?? null;

if (!$student_id && !$card_id) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameter: student_id or card_id']);
    exit;
}

$pdo = db();
$qr_token = null;

if ($student_id) {
    $stmt = $pdo->prepare("
        SELECT sc.qr_token FROM student_cards sc
        JOIN students s ON sc.student_id = s.id
        WHERE s.student_id = ? AND sc.status = 'active'
    ");
    $stmt->execute([$student_id]);
    $qr_token = $stmt->fetchColumn();
} elseif ($card_id) {
    $stmt = $pdo->prepare("
        SELECT qr_token FROM student_cards WHERE card_id = ? AND status = 'active'
    ");
    $stmt->execute([$card_id]);
    $qr_token = $stmt->fetchColumn();
}

if (!$qr_token) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'QR token not found or card is inactive']);
    exit;
}

$qr_image_url = getQrCodeUrl($qr_token, 300);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('Location: ' . $qr_image_url);
exit;
