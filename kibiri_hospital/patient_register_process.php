<?php
declare(strict_types=1);
require_once __DIR__ . '/configdatabase.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $dob = $_POST['dob'] ?? null;
    $gender = $_POST['gender'] ?? null;
    $address = trim($_POST['address'] ?? '');

    if ($fullName === '' || $email === '' || $phone === '' || $password === '' || $passwordConfirm === '') {
        header('Location: login.php?portal=register&patient_error=' . urlencode('Please fill in all required fields.'));
        exit;
    }

    if ($password !== $passwordConfirm) {
        header('Location: login.php?portal=register&patient_error=' . urlencode('Passwords do not match.'));
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: login.php?portal=register&patient_error=' . urlencode('Please enter a valid email address.'));
        exit;
    }

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT patient_id FROM patients WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        header('Location: login.php?portal=register&patient_error=' . urlencode('Email address is already registered.'));
        exit;
    }

    // Generate unique Hospital Patient ID (e.g., KBH-2026-0042)
    $year = date('Y');
    $randNum = str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    $hospitalNumber = "KBH-{$year}-{$randNum}";

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insert = $pdo->prepare(
        "INSERT INTO patients (hospital_number, full_name, email, phone, password_hash, date_of_birth, gender, address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    try {
        $insert->execute([
            $hospitalNumber,
            $fullName,
            $email,
            $phone,
            $passwordHash,
            $dob ?: null,
            $gender ?: null,
            $address
        ]);

        header('Location: login.php?portal=patient&patient_success=' . urlencode($hospitalNumber));
        exit;
    } catch (PDOException $e) {
        error_log($e->getMessage());
        header('Location: login.php?portal=register&patient_error=' . urlencode('Registration failed. Please try again.'));
        exit;
    }
}

header('Location: login.php?portal=register');
exit;