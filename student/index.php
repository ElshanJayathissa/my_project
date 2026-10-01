<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';

$error = '';
$verification_enabled = getSetting(db(), 'enable_student_lookup_by_phone', '1') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_type = sanitize($_POST['login_type'] ?? 'student_id');
    $identifier = sanitize($_POST['identifier'] ?? '');
    $verification = sanitize($_POST['verification'] ?? '');

    if (empty($identifier)) {
        $error = 'Please enter your Student ID or Card ID.';
    } elseif ($verification_enabled && empty($verification)) {
        $error = 'Please enter your phone number or guardian name for verification.';
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $now = time();

        if (!isset($_SESSION['rate_limit_student'][$ip])) {
            $_SESSION['rate_limit_student'][$ip] = ['attempts' => 0, 'first_attempt' => $now];
        }

        $rl = &$_SESSION['rate_limit_student'][$ip];
        if ($now - $rl['first_attempt'] > 900) {
            $rl = ['attempts' => 0, 'first_attempt' => $now];
        }

        if ($rl['attempts'] >= 5) {
            $wait = 900 - ($now - $rl['first_attempt']);
            $minutes = ceil($wait / 60);
            $error = "Too many failed attempts. Please try again in {$minutes} minute(s).";
        } else {
            $pdo = db();

            if ($login_type === 'card_id') {
                $stmt = $pdo->prepare("
                    SELECT s.*, sc.card_id
                    FROM students s
                    JOIN student_cards sc ON s.id = sc.student_id
                    WHERE sc.card_id = ? AND s.status = 'active'
                ");
                $stmt->execute([$identifier]);
                $student = $stmt->fetch();
            } else {
                $stmt = $pdo->prepare("
                    SELECT s.*, NULL as card_id
                    FROM students s
                    WHERE s.student_id = ? AND s.status = 'active'
                ");
                $stmt->execute([$identifier]);
                $student = $stmt->fetch();
            }

            if ($student) {
                if ($verification_enabled && !empty($verification)) {
                    $v_lower = strtolower($verification);
                    $phone_match = !empty($student['phone']) && stripos($student['phone'], $verification) !== false;
                    $guardian_phone_match = !empty($student['guardian_phone']) && stripos($student['guardian_phone'], $verification) !== false;
                    $guardian_name_match = !empty($student['guardian_name']) && stripos($student['guardian_name'], $verification) !== false;

                    if (!$phone_match && !$guardian_phone_match && !$guardian_name_match) {
                        $rl['attempts']++;
                        $remaining = 5 - $rl['attempts'];
                        $error = "Verification failed. Invalid phone number or guardian name. ({$remaining} attempts remaining)";
                    } else {
                        loginStudent($student);
                    }
                } else {
                    loginStudent($student);
                }
            } else {
                $rl['attempts']++;
                $remaining = 5 - $rl['attempts'];
                $error = "Invalid Student ID or Card ID. ({$remaining} attempts remaining)";
            }
        }
    }
}

function loginStudent($student) {
    unset($_SESSION['rate_limit_student']);
    session_regenerate_id(true);
    $_SESSION['student_id'] = $student['id'];
    $_SESSION['student_name'] = $student['full_name'];
    $_SESSION['student_identifier'] = $student['student_id'];
    redirect('dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        .login-wrapper {
            width: 100%;
            max-width: 440px;
        }
        .brand-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .brand-icon {
            width: 64px;
            height: 64px;
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #e0e0ff;
            margin-bottom: 12px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.15);
        }
        .brand-header h1 {
            color: #ffffff;
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0;
        }
        .brand-header p {
            color: rgba(255,255,255,0.6);
            margin: 6px 0 0;
            font-size: 0.9rem;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.97);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 36px 32px;
            backdrop-filter: blur(10px);
        }
        .login-card h3 {
            color: #1a1a2e;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .login-card .subtitle {
            color: #888;
            font-size: 0.85rem;
            margin-bottom: 24px;
        }
        .tab-selector {
            display: flex;
            background: #f0f2f5;
            border-radius: 10px;
            padding: 4px;
            margin-bottom: 20px;
        }
        .tab-selector button {
            flex: 1;
            border: none;
            background: transparent;
            padding: 10px 8px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #777;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .tab-selector button.active {
            background: white;
            color: #1a1a2e;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .form-floating-custom { margin-bottom: 16px; position: relative; }
        .form-floating-custom label {
            position: absolute;
            top: -8px;
            left: 14px;
            background: white;
            padding: 0 6px;
            font-size: 0.78rem;
            color: #888;
            font-weight: 600;
            z-index: 2;
        }
        .form-floating-custom input {
            width: 100%;
            padding: 14px 14px;
            border: 2px solid #e0e5ec;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fafbfc;
            outline: none;
        }
        .form-floating-custom input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
            background: white;
        }
        .verification-hint {
            background: #f0f4ff;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.8rem;
            color: #555;
            margin-bottom: 16px;
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }
        .verification-hint i { color: #667eea; margin-top: 2px; flex-shrink: 0; }
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            margin-top: 8px;
        }
        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(102,126,234,0.4);
            color: white;
        }
        .btn-login:active { transform: translateY(0); }
        .footer-note {
            text-align: center;
            margin-top: 20px;
            color: rgba(255,255,255,0.5);
            font-size: 0.8rem;
        }
        .rate-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 10px;
            padding: 12px 16px;
            color: #856404;
            font-size: 0.88rem;
            margin-bottom: 16px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .rate-warning i { margin-top: 2px; flex-shrink: 0; }
        @media (max-width: 480px) {
            .login-card { padding: 28px 20px; }
            .brand-header h1 { font-size: 1.3rem; }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="brand-header">
            <div class="brand-icon"><i class="bi bi-mortarboard"></i></div>
            <h1><?php echo APP_NAME; ?></h1>
            <p>Student Portal</p>
        </div>

        <div class="login-card">
            <h3>Welcome Student</h3>
            <p class="subtitle">Sign in to view your class and payment details</p>

            <?php if ($error): ?>
                <div class="alert alert-danger border-0 rounded-3 mb-3" style="font-size:0.88rem;">
                    <i class="bi bi-exclamation-circle me-1"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="login_type" id="loginType" value="student_id">

                <div class="tab-selector">
                    <button type="button" id="tabStudentId" class="active" onclick="switchTab('student_id')">
                        <i class="bi bi-person-badge"></i> Student ID
                    </button>
                    <button type="button" id="tabCardId" onclick="switchTab('card_id')">
                        <i class="bi bi-upc-scan"></i> Card ID
                    </button>
                </div>

                <div class="form-floating-custom">
                    <input type="text" name="identifier" id="identifier" placeholder=" " required autofocus
                           value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier'], ENT_QUOTES) : ''; ?>">
                    <label id="idLabel">Student ID</label>
                </div>

                <?php if ($verification_enabled): ?>
                <div class="verification-hint">
                    <i class="bi bi-shield-check"></i>
                    <span>Enter your registered phone number or guardian name to verify your identity.</span>
                </div>
                <div class="form-floating-custom">
                    <input type="text" name="verification" placeholder=" " autocomplete="off">
                    <label>Phone or Guardian Name</label>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-login">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </button>
            </form>
        </div>

        <p class="footer-note">Session-based access &middot; Secure &middot; No persistent login</p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function switchTab(type) {
            document.getElementById('loginType').value = type;
            document.getElementById('tabStudentId').classList.toggle('active', type === 'student_id');
            document.getElementById('tabCardId').classList.toggle('active', type === 'card_id');
            document.getElementById('identifier').placeholder = type === 'student_id' ? 'e.g. STU2025001' : 'e.g. CRD00000001';
            document.getElementById('idLabel').textContent = type === 'student_id' ? 'Student ID' : 'Card ID';
            document.getElementById('identifier').focus();
        }
    </script>
</body>
</html>

