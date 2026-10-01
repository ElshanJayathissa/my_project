<?php
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Access Denied</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .error-card { background: white; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); padding: 40px; text-align: center; max-width: 500px; }
        .error-code { font-size: 6rem; font-weight: bold; color: #dc3545; }
        .error-msg { font-size: 1.5rem; color: #333; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-code">403</div>
        <div class="error-msg">Access Denied</div>
        <p class="text-muted mb-4">You do not have permission to access this page.</p>
        <a href="login.php" class="btn btn-primary">Go to Login</a>
    </div>
</body>
</html>
