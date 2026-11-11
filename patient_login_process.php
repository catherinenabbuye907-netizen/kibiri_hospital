<?php
declare(strict_types=1);
require_once __DIR__ . '/configdatabase.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        header('Location: login.php?portal=patient&patient_error=' . urlencode('Please enter your credentials.'));
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT patient_id, hospital_number, full_name, password_hash 
         FROM patients 
         WHERE (hospital_number = ? OR email = ?) AND status = 'active'"
    );
    $stmt->execute([$identifier, $identifier]);
    $patient = $stmt->fetch();

    if ($patient && password_verify($password, $patient['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['patient_id'] = $patient['patient_id'];
        $_SESSION['hospital_number'] = $patient['hospital_number'];
        $_SESSION['full_name'] = $patient['full_name'];
        $_SESSION['role'] = 'Patient';

        header('Location: patient_dashboard.php');
        exit;
    }

    header('Location: login.php?portal=patient&patient_error=' . urlencode('Invalid Hospital ID/Email or Password.'));
    exit;
}

header('Location: login.php?portal=patient');
exit;