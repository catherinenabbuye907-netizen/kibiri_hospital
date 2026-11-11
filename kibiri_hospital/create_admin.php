<?php
declare(strict_types=1);
require_once __DIR__ . '/configdatabase.php';
header('Content-Type: text/html; charset=UTF-8');
$stmt = $pdo->prepare(
    "SELECT role_id FROM roles WHERE role_name = ?"
);
$stmt->execute(['Administrator']);

$role = $stmt->fetch();

if (!$role) {
    exit('Administrator role not found.');
}

$stmt = $pdo->prepare(
    "SELECT user_id FROM users WHERE username = ? OR email = ?"
);

$stmt->execute(['admin', 'admin@kibirihospital.local']);

if ($stmt->fetch()) {
    exit('Administrator account already exists.');
}

$fullName = 'System Administrator';
$username = 'admin';
$email = 'admin@kibirihospital.local';

$password = admin123(random_bytes(8));
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    "INSERT INTO users
    (role_id, full_name, username, email, password_hash)
    VALUES (?, ?, ?, ?, ?)"
);

$stmt->execute([
    $role['role_id'],
    $fullName,
    $username,
    $email,
    $passwordHash
]);

echo '<h2>Administrator created successfully!</h2>';
echo '<p>Username: admin</p>';
echo '<p>Email: admin@kibirihospital.local</p>';
echo '<p>Password: <strong>' .
    htmlspecialchars($password, ENT_QUOTES, 'UTF-8') .
    '</strong></p>';
echo '<p>Save this password securely. It will not be displayed again.</p>';
echo '<p><strong>Delete create_admin.php immediately after setup.</strong></p>';

