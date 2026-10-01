<?php
require_once __DIR__ . '/../config/config.php';

function db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database connection failed.");
        }
    }
    return $pdo;
}

function redirect($url) {
    header("Location: " . $url);
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('../login.php');
    }
}

function requireRole($roles) {
    requireLogin();
    if (!in_array($_SESSION['role'], (array)$roles)) {
        redirect('../login.php');
    }
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generateReceiptNumber() {
    return 'RCP-' . date('Ymd') . '-' . strtoupper(uniqid());
}

function generateStudentId($pdo) {
    $prefix = 'STU';
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(student_id, 9) AS UNSIGNED)) as max_num FROM students WHERE student_id LIKE ?");
    $stmt->execute([$prefix . $year . '%']);
    $row = $stmt->fetch();
    $next = ($row['max_num'] ?? 0) + 1;
    return $prefix . $year . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function generateCardId($pdo) {
    $prefix = 'CRD';
    $stmt = $pdo->prepare("SELECT COUNT(*) + 1 as next FROM student_cards");
    $stmt->execute();
    $row = $stmt->fetch();
    return $prefix . str_pad($row['next'], 8, '0', STR_PAD_LEFT);
}

function generateQrToken() {
    return bin2hex(random_bytes(16));
}

function generatePassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function getSetting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function setSetting($pdo, $key, $value) {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $value, $value]);
}

function logAudit($pdo, $user_id, $action, $details = null) {
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $user_id,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

function getBillingMonths() {
    return [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
}

function formatCurrency($amount) {
    return 'Rs. ' . number_format($amount, 2);
}

function formatDate($date) {
    return date('d M Y', strtotime($date));
}

function flashMessage($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function jsonResponse($data, $code = 200) {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function requireJson() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Invalid request method'], 405);
    }
    if (!isset($_POST['csrf_token']) || !verify_csrf($_POST['csrf_token'])) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
}

function validateRequired($fields) {
    $missing = [];
    foreach ($fields as $field) {
        if (empty($_POST[$field])) {
            $missing[] = $field;
        }
    }
    return $missing;
}

function getStudentOutstanding($pdo, $student_id, $class_id = null) {
    $sql = "SELECT pi.amount_due, pi.amount_paid, pi.billing_month, pi.billing_year,
                   c.name as class_name, p.status as payment_status, c.monthly_fee
            FROM payment_items pi
            JOIN payments p ON pi.payment_id = p.id
            JOIN classes c ON p.class_id = c.id
            WHERE p.student_id = ? AND pi.status != 'paid'";
    $params = [$student_id];
    if ($class_id) {
        $sql .= " AND p.class_id = ?";
        $params[] = $class_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $outstanding = 0;
    foreach ($items as $item) {
        $outstanding += ($item['amount_due'] - $item['amount_paid']);
    }

    if ($outstanding == 0 && $class_id) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt FROM payment_items pi
            JOIN payments p ON pi.payment_id = p.id
            WHERE p.student_id = ? AND p.class_id = ?
        ");
        $stmt->execute([$student_id, $class_id]);
        $total_items = $stmt->fetchColumn();

        if ($total_items == 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as cnt FROM class_enrollments ce
                JOIN classes c ON ce.class_id = c.id
                WHERE ce.student_id = ? AND ce.class_id = ? AND ce.status = 'active' AND c.status = 'active'
            ");
            $stmt->execute([$student_id, $class_id]);
            if ($stmt->fetchColumn() > 0) {
                $stmt = $pdo->prepare("SELECT monthly_fee FROM classes WHERE id = ?");
                $stmt->execute([$class_id]);
                $monthly_fee = $stmt->fetchColumn();
                if ($monthly_fee && (float)$monthly_fee > 0) {
                    return (float)$monthly_fee;
                }
            }
        }
    }

    return $outstanding;
}

function getUnpaidMonths($pdo, $student_id, $class_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM payment_items pi
        JOIN payments p ON pi.payment_id = p.id
        WHERE p.student_id = ? AND p.class_id = ? AND pi.status != 'paid'
    ");
    $stmt->execute([$student_id, $class_id]);
    $count = $stmt->fetch()['count'];

    if ($count == 0) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt FROM payment_items pi
            JOIN payments p ON pi.payment_id = p.id
            WHERE p.student_id = ? AND p.class_id = ?
        ");
        $stmt->execute([$student_id, $class_id]);
        $total_items = $stmt->fetchColumn();

        if ($total_items == 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM class_enrollments ce
                JOIN classes c ON ce.class_id = c.id
                WHERE ce.student_id = ? AND ce.class_id = ? AND ce.status = 'active' AND c.status = 'active'
            ");
            $stmt->execute([$student_id, $class_id]);
            if ($stmt->fetchColumn() > 0) {
                return 1;
            }
        }
    }

    return $count;
}

function calculateTeacherIncome($pdo, $teacher_id, $start_date = null, $end_date = null) {
    $sql = "SELECT SUM(p.paid_amount) as total_collected, 
                   SUM(p.paid_amount * (t.commission_percentage / 100)) as teacher_share,
                   SUM(p.paid_amount * ((100 - t.commission_percentage) / 100)) as institute_share
            FROM payments p
            JOIN classes c ON p.class_id = c.id
            JOIN teachers t ON c.teacher_id = t.id
            WHERE c.teacher_id = ? AND p.status = 'completed'";
    $params = [$teacher_id];
    if ($start_date) {
        $sql .= " AND p.payment_date >= ?";
        $params[] = $start_date;
    }
    if ($end_date) {
        $sql .= " AND p.payment_date <= ?";
        $params[] = $end_date;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function getTodayCollection($collector_id = null) {
    $pdo = db();
    $sql = "SELECT COUNT(*) as count, SUM(paid_amount) as total FROM payments WHERE payment_date = CURDATE() AND status = 'completed'";
    $params = [];
    if ($collector_id) {
        $sql .= " AND collector_id = ?";
        $params[] = $collector_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function getRecentTransactions($limit = 10) {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT p.*, s.full_name, s.student_id, c.name as class_name, u.full_name as collector_name
        FROM payments p
        JOIN students s ON p.student_id = s.id
        JOIN classes c ON p.class_id = c.id
        LEFT JOIN users u ON p.collector_id = u.id
        ORDER BY p.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getQrCodeUrl($text, $size = 300) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($text);
}

function generateQrCode($text, $size = 200, $filepath = null) {
    if (!extension_loaded('gd')) {
        return false;
    }
    $padding = 10;
    $moduleCount = 25;
    $totalSize = $size;
    $moduleSize = ($totalSize - $padding * 2) / $moduleCount;
    
    $im = imagecreatetruecolor($totalSize, $totalSize);
    $bg = imagecolorallocate($im, 255, 255, 255);
    $fg = imagecolorallocate($im, 0, 0, 0);
    imagefilledrectangle($im, 0, 0, $totalSize, $totalSize, $bg);
    
    $hash = crc32($text);
    for ($row = 0; $row < $moduleCount; $row++) {
        for ($col = 0; $col < $moduleCount; $col++) {
            if (($hash >> ($row * $moduleCount + $col)) & 1) {
                $x1 = $padding + $col * $moduleSize;
                $y1 = $padding + $row * $moduleSize;
                $x2 = $x1 + $moduleSize;
                $y2 = $y1 + $moduleSize;
                imagefilledrectangle($im, $x1, $y1, $x2, $y2, $fg);
            }
        }
    }
    
    if ($filepath) {
        imagepng($im, $filepath);
        imagedestroy($im);
        return $filepath;
    }
    ob_start();
    imagepng($im);
    imagedestroy($im);
    return ob_get_clean();
}

function getMonthlyPaymentDay($pdo) {
    return (int)getSetting($pdo, 'monthly_payment_day', '25');
}

function getPaymentDueDate($year, $month, $payment_day = null) {
    if ($payment_day === null) {
        $payment_day = getMonthlyPaymentDay(db());
    }
    $payment_day = (int)$payment_day;
    $days_in_month = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $day = min($payment_day, $days_in_month);
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function getPaymentStatus($pdo, $student_id, $class_id, $year, $month) {
    $stmt = $pdo->prepare("
        SELECT pi.status, pi.amount_paid, pi.amount_due
        FROM payment_items pi
        JOIN payments p ON pi.payment_id = p.id
        WHERE p.student_id = ? AND p.class_id = ? AND pi.billing_month = ? AND pi.billing_year = ?
        LIMIT 1
    ");
    $stmt->execute([$student_id, $class_id, $month, $year]);
    $item = $stmt->fetch();

    if ($item) {
        if ($item['status'] === 'paid') {
            return 'paid';
        } elseif ($item['status'] === 'partial') {
            return 'partial';
        }
        return 'pending';
    }

    return 'pending';
}

function getPaymentReminders($pdo) {
    $today = new DateTime(date('Y-m-d'));
    $current_month = (int)$today->format('n');
    $current_year = (int)$today->format('Y');
    $payment_day = getMonthlyPaymentDay($pdo);
    $due_date = getPaymentDueDate($current_year, $current_month, $payment_day);
    $due_datetime = new DateTime($due_date);

    $upcoming = 0;
    $pending = 0;
    $overdue = 0;

    $stmt = $pdo->prepare("
        SELECT p.student_id, p.class_id, pi.status, pi.amount_due, pi.amount_paid
        FROM payment_items pi
        JOIN payments p ON pi.payment_id = p.id
        WHERE pi.billing_month = ? AND pi.billing_year = ?
    ");
    $stmt->execute([$current_month, $current_year]);
    $items = $stmt->fetchAll();

    $processed = [];
    foreach ($items as $item) {
        $key = $item['student_id'] . '_' . $item['class_id'];
        if (isset($processed[$key])) continue;
        $processed[$key] = true;

        if ($item['status'] === 'paid') {
            continue;
        }

        if ($today > $due_datetime) {
            $overdue++;
        } else {
            $pending++;
            $diff = $today->diff($due_datetime)->days;
            if ($diff <= 7) {
                $upcoming++;
            }
        }
    }

    $stmt = $pdo->prepare("
        SELECT ce.student_id, ce.class_id
        FROM class_enrollments ce
        JOIN classes c ON ce.class_id = c.id
        WHERE ce.status = 'active'
        AND c.status = 'active'
    ");
    $stmt->execute();
    $enrollments = $stmt->fetchAll();

    foreach ($enrollments as $enrollment) {
        $key = $enrollment['student_id'] . '_' . $enrollment['class_id'];
        if (isset($processed[$key])) continue;
        $processed[$key] = true;

        if ($today > $due_datetime) {
            $overdue++;
        } else {
            $pending++;
            $diff = $today->diff($due_datetime)->days;
            if ($diff <= 7) {
                $upcoming++;
            }
        }
    }

    return [
        'upcoming' => $upcoming,
        'pending' => $pending,
        'overdue' => $overdue,
        'due_date' => $due_date
    ];
}

function getStudentPaymentStatusForMonth($pdo, $student_id, $class_id, $year, $month) {
    $status = getPaymentStatus($pdo, $student_id, $class_id, $year, $month);
    $payment_day = getMonthlyPaymentDay($pdo);
    $due_date = getPaymentDueDate($year, $month, $payment_day);
    $today = date('Y-m-d');

    if ($status === 'paid') {
        return ['status' => 'paid', 'due_date' => $due_date, 'label' => 'Paid', 'days' => 0, 'days_text' => ''];
    }

    $due_datetime = new DateTime($due_date);
    $today_datetime = new DateTime($today);
    $diff = $today_datetime->diff($due_datetime);

    if ($today > $due_date) {
        $days_overdue = $diff->days;
        return ['status' => 'overdue', 'due_date' => $due_date, 'label' => 'Overdue', 'days' => $days_overdue, 'days_text' => $days_overdue . ' days overdue'];
    }

    $days_until = $diff->days;
    if ($days_until <= 7) {
        return ['status' => 'upcoming', 'due_date' => $due_date, 'label' => 'Upcoming', 'days' => $days_until, 'days_text' => $days_until . ' days left'];
    }

    return ['status' => 'pending', 'due_date' => $due_date, 'label' => 'Pending', 'days' => $days_until, 'days_text' => $days_until . ' days left'];
}

function getBasePath() {
    return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}

function asset($path) {
    return APP_URL . '/assets/' . ltrim($path, '/');
}

function url($path) {
    return APP_URL . '/' . ltrim($path, '/');
}
