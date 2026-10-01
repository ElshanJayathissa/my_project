<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
if (isLoggedIn()) {
    $role = $_SESSION['role'];
    if ($role === 'admin') redirect('admin/dashboard.php');
    if ($role === 'teacher') redirect('teacher/dashboard.php');
    if ($role === 'collector') redirect('collector/dashboard.php');
}
redirect('login.php');
