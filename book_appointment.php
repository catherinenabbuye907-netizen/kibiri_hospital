<?php
declare(strict_types=1);
require_once __DIR__ . '/configdatabase.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php?portal=patient');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = $_SESSION['patient_id'];
    $department = trim($_POST['department'] ?? '');
    $date = $_POST['preferred_date'] ?? '';
    $time = $_POST['preferred_time'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($department === '' || $date === '' || $time === '' || $reason === '') {
        header('Location: patient_dashboard.php?error=Please fill in all appointment fields.');
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO appointments (patient_id, department, preferred_date, preferred_time, reason, status)
         VALUES (?, ?, ?, ?, ?, 'Pending')"
    );

    try {
        $stmt->execute([$patientId, $department, $date, $time, $reason]);
        header('Location: patient_dashboard.php?success=Appointment booked successfully and is pending confirmation.');
        exit;
    } catch (PDOException $e) {
        error_log($e->getMessage());
        header('Location: patient_dashboard.php?error=Failed to book appointment. Please try again.');
        exit;
    }
}