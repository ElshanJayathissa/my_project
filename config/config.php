<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'qr_class_card');
define('DB_USER', 'root');
define('DB_PASS', '');

// App Configuration
define('APP_NAME', 'QR Class Card');
define('APP_URL', 'http://192.168.137.1/QR');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('QR_DIR', __DIR__ . '/../qr_cards/');
define('RECEIPT_DIR', __DIR__ . '/../receipts/');

// Ensure upload directories exist
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
if (!is_dir(QR_DIR)) mkdir(QR_DIR, 0755, true);
if (!is_dir(RECEIPT_DIR)) mkdir(RECEIPT_DIR, 0755, true);

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

// Timezone
date_default_timezone_set('Asia/Kolkata');
