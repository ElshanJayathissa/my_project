<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = db();
$user_id = $_SESSION['user_id'];
logAudit($pdo, $user_id, 'logout', 'User logged out');

$_SESSION = [];
session_destroy();
redirect('login.php');
