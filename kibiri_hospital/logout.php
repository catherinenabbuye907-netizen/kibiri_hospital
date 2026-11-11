<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/configdatabase.php';

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs
         (user_id, action, description, ip_address)
         VALUES (?, ?, ?, ?)'
    );

    $stmt->execute([
        $_SESSION['user_id'],
        'LOGOUT',
        'User logged out',
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax'
        ]
    );
}

session_destroy();

header('Location: login.php');
exit;
