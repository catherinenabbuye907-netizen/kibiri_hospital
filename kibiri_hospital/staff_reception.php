<?php
declare(strict_types=1);
require_once __DIR__ . '/configdatabase.php';
require_once __DIR__ . '/auth_check.php';

// Enforce role authorization (Receptionist or Administrator)
if (!in_array($_SESSION['role_name'] ?? '', ['Receptionist', 'Nurse', 'Administrator'], true)) {
    http_response_code(403);
    exit('Access denied. This portal is restricted to reception staff.');
}

$staffId = (int)$_SESSION['user_id'];
$staffName = $_SESSION['full_name'];
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register_patient') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $dob = $_POST['dob'] ?? null;
        $gender = $_POST['gender'] ?? null;
        $address = trim($_POST['address'] ?? '');

        if ($fullName === '' || $phone === '') {
            $error = 'Patient full name and phone number are required.';
        } else {
            // Generate unique email if blank
            if ($email === '') {
                $email = 'patient_' . random_int(1000, 99999) . '@kibirihospital.local';
            }

            // Generate unique Hospital ID
            $year = date('Y');
            $hospitalNumber = "KBH-{$year}-" . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $tempPassword = password_hash('password123', PASSWORD_DEFAULT);

            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO patients (hospital_number, full_name, email, phone, password_hash, date_of_birth, gender, address)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$hospitalNumber, $fullName, $email, $phone, $tempPassword, $dob ?: null, $gender, $address]);
                $message = "Patient registered successfully! Hospital ID: {$hospitalNumber}";
            } catch (PDOException $e) {
                $error = 'Registration failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'record_triage') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $temp = trim($_POST['temperature'] ?? '');
        $bp = trim($_POST['blood_pressure'] ?? '');
        $pulse = trim($_POST['pulse_rate'] ?? '');
        $weight = trim($_POST['weight'] ?? '');
        $height = trim($_POST['height'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($patientId > 0 && $temp !== '' && $bp !== '' && $pulse !== '') {
            try {
                $triageStmt = $pdo->prepare(
                    "INSERT INTO triage_records (patient_id, receptionist_id, temperature, blood_pressure, pulse_rate, weight, height, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $triageStmt->execute([
                    $patientId,
                    $staffId,
                    (float)$temp,
                    $bp,
                    (int)$pulse,
                    $weight !== '' ? (float)$weight : null,
                    $height !== '' ? (float)$height : null,
                    $notes
                ]);
                $message = 'Vital signs and triage recorded successfully.';
            } catch (PDOException $e) {
                $error = 'Failed to record vitals: ' . $e->getMessage();
            }
        } else {
            $error = 'Please complete all required vital signs fields (Temperature, BP, Pulse).';
        }
    }
}

// Fetch active patients list
$patientsStmt = $pdo->query("SELECT patient_id, hospital_number, full_name, phone FROM patients WHERE status = 'active' ORDER BY full_name ASC");
$patients = $patientsStmt->fetchAll();

// Fetch recent triage records
$triageStmt = $pdo->query(
    "SELECT t.triage_id, t.temperature, t.blood_pressure, t.pulse_rate, t.weight, t.height, t.created_at,
            p.full_name, p.hospital_number, u.full_name AS receptionist_name
     FROM triage_records t
     JOIN patients p ON t.patient_id = p.patient_id
     LEFT JOIN users u ON t.receptionist_id = u.user_id
     ORDER BY t.created_at DESC LIMIT 15"
);
$triageLogs = $triageStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reception & Triage - Kibiri Hospital HMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark hospital-navbar px-4 bg-success">
    <span class="navbar-brand fw-bold">KIBIRI HOSPITAL HMIS | Reception Desk</span>
    <div class="d-flex align-items-center gap-3">
        <span class="text-white"><?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></span>
        <a href="staff_appointments.php" class="btn btn-outline-light btn-sm">Appointments</a>
        <a href="index.php" class="btn btn-outline-light btn-sm">Main Dashboard</a>
        <a href="logout.php" class="btn btn-light btn-sm">Sign Out</a>
    </div>
</nav>

<div class="container py-4">

    <?php if ($message !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Column 1: Patient Registration & Triage Forms -->
        <div class="col-lg-5">
            <!-- Patient Quick Registration Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 text-success fw-bold">New Patient Registration</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="register_patient">
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Full Name *</label>
                            <input type="text" name="full_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Phone Number *</label>
                            <input type="text" name="phone" class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Email Address</label>
                            <input type="email" name="email" class="form-control form-control-sm" placeholder="Optional">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Date of Birth</label>
                                <input type="date" name="dob" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Gender</label>
                                <select name="gender" class="form-select form-select-sm">
                                    <option value="">Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Physical Address</label>
                            <input type="text" name="address" class="form-control form-control-sm" placeholder="e.g., Kampala">
                        </div>
                        <button type="submit" class="btn btn-success btn-sm w-100">Register & Generate ID</button>
                    </form>
                </div>
            </div>

            <!-- Vital Signs & Triage Card -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 text-success fw-bold">Record Triage & Vitals</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="record_triage">
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Select Patient *</label>
                            <select name="patient_id" class="form-select form-select-sm" required>
                                <option value="">-- Choose Patient --</option>
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?= $p['patient_id'] ?>">
                                        <?= htmlspecialchars($p['full_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($p['hospital_number'], ENT_QUOTES, 'UTF-8') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Temp (°C) *</label>
                                <input type="number" step="0.1" name="temperature" class="form-control form-control-sm" placeholder="36.8" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Blood Pressure *</label>
                                <input type="text" name="blood_pressure" class="form-control form-control-sm" placeholder="120/80" required>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-4">
                                <label class="form-label small fw-bold">Pulse (bpm) *</label>
                                <input type="number" name="pulse_rate" class="form-control form-control-sm" placeholder="72" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-bold">Weight (kg)</label>
                                <input type="number" step="0.1" name="weight" class="form-control form-control-sm" placeholder="70">
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-bold">Height (cm)</label>
                                <input type="number" step="0.1" name="height" class="form-control form-control-sm" placeholder="170">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Triage Notes</label>
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Chief complaints, observations...">
                        </div>
                        <button type="submit" class="btn btn-outline-success btn-sm w-100">Save Triage Record</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Column 2: Recent Triage Activity -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-success fw-bold">Recent Triage Logs</h5>
                    <span class="badge bg-success"><?= count($triageLogs) ?> Records</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($triageLogs)): ?>
                        <div class="p-4 text-muted">No triage records found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Patient</th>
                                        <th>Vitals (Temp / BP / Pulse)</th>
                                        <th>Measurements</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($triageLogs as $t): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($t['full_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($t['hospital_number'], ENT_QUOTES, 'UTF-8') ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border">🌡 <?= $t['temperature'] ?>°C</span>
                                                <span class="badge bg-light text-dark border">❤️ <?= $t['blood_pressure'] ?></span>
                                                <span class="badge bg-light text-dark border">⚡ <?= $t['pulse_rate'] ?> bpm</span>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    Wt: <?= $t['weight'] ? $t['weight'] . 'kg' : 'N/A' ?> | 
                                                    Ht: <?= $t['height'] ? $t['height'] . 'cm' : 'N/A' ?>
                                                </small>
                                            </td>
                                            <td><small class="text-muted"><?= htmlspecialchars($t['created_at'], ENT_QUOTES, 'UTF-8') ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>