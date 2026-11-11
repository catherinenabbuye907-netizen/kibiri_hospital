<?php

declare(strict_types=1);

$host = '127.0.0.1';
$dbname = 'kibiri_hospital';
$username = 'root';
$password = ''; // Default local XAMPP configuration

try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {

    error_log($e->getMessage());

    http_response_code(500);

    exit('Database connection failed. Please check the server configuration.');

}
