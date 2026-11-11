<?php
declare(strict_types=1);
require_once __DIR__ . '/configdatabase.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit;
}

$patientId = $_SESSION['patient_id'];
$fullName = $_SESSION['full_name'];
$hospitalNumber = $_SESSION['hospital_number'];

// Ensure patient_consultation_notes table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS patient_consultation_notes (
            note_id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    // Table creation handled silently
}

$msg = '';
$error = '';

// Handle appointment booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_appointment') {
    $department = trim($_POST['department'] ?? '');
    $date = $_POST['preferred_date'] ?? '';
    $time = $_POST['preferred_time'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($department === '' || $date === '' || $time === '' || $reason === '') {
        $error = 'Please fill in all appointment fields.';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO appointments (patient_id, department, preferred_date, preferred_time, reason, status)
             VALUES (?, ?, ?, ?, ?, 'Pending')"
        );
        try {
            $stmt->execute([$patientId, $department, $date, $time, $reason]);
            $msg = 'Appointment booked successfully and is pending confirmation.';
        } catch (PDOException $e) {
            $error = 'Failed to book appointment. Please try again.';
        }
    }
}

// Handle Patient Laboratory Investigation Request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_lab_test') {
    $testName = trim($_POST['test_name'] ?? '');
    $clinicalNotes = trim($_POST['clinical_notes'] ?? '');

    if (!empty($testName)) {
        try {
            $stmtLabReq = $pdo->prepare("
                INSERT INTO lab_results (patient_id, test_name, results, status, created_at) 
                VALUES (?, ?, 'Pending laboratory processing', 'Pending', NOW())
            ");
            $stmtLabReq->execute([$patientId, $testName . (!empty($clinicalNotes) ? ' (Notes: ' . $clinicalNotes . ')' : '')]);
            $msg = 'Laboratory investigation request successfully sent to the laboratory technician.';
        } catch (Exception $e) {
            $error = 'Failed to submit laboratory request. Please try again.';
        }
    } else {
        $error = 'Please select a valid laboratory test.';
    }
}

// Handle Patient Consultation / Feedback Note submission to Doctor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_patient_note') {
    $patientNote = trim($_POST['patient_note'] ?? '');

    if (!empty($patientNote)) {
        try {
            $stmtNote = $pdo->prepare("INSERT INTO patient_consultation_notes (patient_id, message, created_at) VALUES (?, ?, NOW())");
            $stmtNote->execute([$patientId, $patientNote]);
            $msg = 'Your consultation feedback/note has been successfully sent to your doctors.';
        } catch (Exception $e) {
            $error = 'Failed to send consultation note. Please try again.';
        }
    } else {
        $error = 'Please enter your message or question before submitting.';
    }
}

// Fetch patient medical history (consultations)
$histStmt = $pdo->prepare(
    "SELECT c.diagnosis, c.prescription, c.notes, c.created_at, u.full_name as doctor_name 
     FROM consultations c 
     LEFT JOIN users u ON c.doctor_id = u.user_id 
     WHERE c.patient_id = ? 
     ORDER BY c.created_at DESC"
);
$histStmt->execute([$patientId]);
$consultations = $histStmt->fetchAll();

// Fetch patient's submitted consultation notes
$patientNotesStmt = $pdo->prepare("SELECT message, created_at FROM patient_consultation_notes WHERE patient_id = ? ORDER BY created_at DESC");
$patientNotesStmt->execute([$patientId]);
$patientSubmittedNotes = $patientNotesStmt->fetchAll();

// Fetch laboratory results
$labStmt = $pdo->prepare(
    "SELECT test_name, results, status, created_at 
     FROM lab_results 
     WHERE patient_id = ? 
     ORDER BY created_at DESC"
);
$labStmt->execute([$patientId]);
$labResults = $labStmt->fetchAll();

// Fetch appointments
$apptStmt = $pdo->prepare(
    "SELECT department, preferred_date, preferred_time, reason, status, created_at 
     FROM appointments 
     WHERE patient_id = ? 
     ORDER BY preferred_date DESC"
);
$apptStmt->execute([$patientId]);
$appointments = $apptStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patient Dashboard - Kibiri Hospital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .patient-portal-header {
            min-height: 1300px;
            background:
                linear-gradient(90deg, rgba(8, 55, 37, 0.88), rgba(8, 55, 37, 0.48)),
                url('images/sick.jpg') center 42% / cover;
            background-repeat: no-repeat;
        }

        .mental-health-card {
            background: linear-gradient(135deg, #e0f2fe 0%, #f0fdf4 100%);
            border-left: 5px solid #0d9488;
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark patient-portal-header px-4 justify-content-between">
    <span class="navbar-brand fw-bold">KIBIRI HOSPITAL | Patient Portal</span>
    <a href="logout.php" class="btn btn-outline-light btn-sm">Sign Out</a>
</nav>

<div class="container py-4">
    <!-- Welcome Banner -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-7">
            <h2>Welcome, <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="text-muted mb-0">Hospital ID: <strong><?= htmlspecialchars($hospitalNumber, ENT_QUOTES, 'UTF-8') ?></strong></p>
        </div>
        <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex gap-2 justify-content-md-end">
            <button class="btn btn-outline-success bg-black fw-bold" data-bs-toggle="modal" data-bs-target="#labRequestModal"> Request Lab Test</button>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#appointmentModal">Book Appointment</button>
        </div>
    </div>

    <!-- AI Mental Health & Wellness Encouragement Widget -->
    <div class="card shadow-sm border-0 mb-4 mental-health-card">
        <div class="card-body p-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h5 class="card-title text-teal fw-bold mb-0" style="color: #0f766e;">🧠 AI Daily Wellness & Mental Health Encouragement</h5>
            </div>
            <p id="aiHealthTipText" class="fs-6 text-dark mb-3 fst-italic">
                "Take a deep breath. Your mental health is just as important as your physical health. Remember to take things one step at a time today."
            </p>
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">Powered by Kibiri Hospital Care Assistant</small>
            </div>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Medical History Section -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <h4 class="mb-0 text-success">My Medical History & Consultations</h4>
        </div>
        <div class="card-body">
            <?php if (empty($consultations)): ?>
                <p class="text-muted mb-0">No medical consultation records found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Attending Doctor</th>
                                <th>Diagnosis</th>
                                <th>Prescription</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($consultations as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>Dr. <?= htmlspecialchars($row['doctor_name'] ?? 'General Practitioner', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($row['diagnosis'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($row['prescription'] ?? 'None', ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Patient Consultation Feedback / Notes to Doctor Section -->
    <div class="card shadow-sm border-0 mb-4" style="border-left: 4px solid #0d9488;">
        <div class="card-header bg-white py-3">
            <h4 class="mb-0 text-success">💬 Send Consultation Notes or Feedback to Doctors</h4>
        </div>
        <div class="card-body">
            <p class="text-muted small">Have questions after reviewing doctor feedback or need to update your care team on your symptoms? Send a note directly to your doctor.</p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="send_patient_note">
                <div class="mb-3">
                    <textarea name="patient_note" class="form-control" rows="3" placeholder="Type your symptoms update or feedback for your doctor here..." required></textarea>
                </div>
                <button type="submit" class="btn btn-success">Send Note to Doctor</button>
            </form>

            <?php if (!empty($patientSubmittedNotes)): ?>
                <hr class="my-4">
                <h5 class="fw-bold text-dark mb-3" style="font-size: 1rem;">Your Sent Notes & History</h5>
                <div class="list-group">
                    <?php foreach ($patientSubmittedNotes as $pnote): ?>
                        <div class="list-group-item bg-light p-3 mb-2 rounded border">
                            <small class="text-muted float-end"><?= htmlspecialchars($pnote['created_at'], ENT_QUOTES, 'UTF-8') ?></small>
                            <p class="mb-0 text-dark"><?= htmlspecialchars($pnote['message'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Laboratory Results Section -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h4 class="mb-0 text-success">Laboratory Results & Investigation Status</h4>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#labRequestModal">Request New Test</button>
        </div>
        <div class="card-body">
            <?php if (empty($labResults)): ?>
                <p class="text-muted mb-0">No lab results or pending requests available at this time.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Test Name</th>
                                <th>Status</th>
                                <th>Results / Findings</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($labResults as $lab): ?>
                                <tr>
                                    <td><?= htmlspecialchars($lab['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($lab['test_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge bg-<?= $lab['status'] === 'Completed' ? 'success' : 'warning text-dark' ?>">
                                            <?= htmlspecialchars($lab['status'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($lab['results'] ?? 'Pending analysis', ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- My Appointments Section -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h4 class="mb-0 text-success">My Appointments</h4>
        </div>
        <div class="card-body">
            <?php if (empty($appointments)): ?>
                <p class="text-muted mb-0">You have not booked any appointments yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Department</th>
                                <th>Preferred Date & Time</th>
                                <th>Reason</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $appt): ?>
                                <tr>
                                    <td><?= htmlspecialchars($appt['department'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($appt['preferred_date'], ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars($appt['preferred_time'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($appt['reason'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $appt['status'] === 'Confirmed' ? 'success' : (
                                            $appt['status'] === 'Pending' ? 'warning text-dark' : (
                                            $appt['status'] === 'Completed' ? 'primary' : 'danger')) 
                                        ?>">
                                            <?= htmlspecialchars($appt['status'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Laboratory Request Modal -->
<div class="modal fade" id="labRequestModal" tabindex="-1" aria-labelledby="labRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="request_lab_test">
                <div class="modal-header">
                    <h5 class="modal-title" id="labRequestModalLabel">Request Laboratory Investigation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="test_name" class="form-label fw-bold">Select Diagnostic Test</label>
                        <select name="test_name" id="test_name" class="form-select" required>
                            <option value="">-- Choose Test --</option>
                            <option value="Complete Blood Count (CBC)">Complete Blood Count (CBC)</option>
                            <option value="Malaria Blood Smear (RDT)">Malaria Blood Smear (RDT)</option>
                            <option value="HIV/AIDS Test">HIV/AIDS Test</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="clinical_notes" class="form-label fw-bold">Clinical Notes / Symptoms (Optional)</label>
                        <textarea name="clinical_notes" id="clinical_notes" class="form-control" rows="3" placeholder="Describe any relevant symptoms or reasons for this test..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Submit Request to Lab</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Appointment Booking Modal -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="book_appointment">
                <div class="modal-header">
                    <h5 class="modal-title" id="appointmentModalLabel">Book New Appointment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="department" class="form-label fw-bold">Department</label>
                        <select name="department" id="department" class="form-select" required>
                            <option value="">-- Select Department --</option>
                            <option value="General Medicine">General Medicine</option>
                            <option value="Pediatrics">Pediatrics</option>
                            <option value="Obstetrics & Gynecology">Obstetrics & Gynecology</option>
                            <option value="Dental">Dental</option>
                            <option value="Laboratory">Laboratory</option>
                            <option value="Mental Health & Counseling">Mental Health & Counseling</option>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="preferred_date" class="form-label fw-bold">Preferred Date</label>
                            <input type="date" name="preferred_date" id="preferred_date" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label for="preferred_time" class="form-label fw-bold">Preferred Time</label>
                            <input type="time" name="preferred_time" id="preferred_time" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="reason" class="form-label fw-bold">Reason / Symptoms</label>
                        <textarea name="reason" id="reason" class="form-control" rows="3" placeholder="Describe your symptoms or reason for visit..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Submit Booking</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript for Auto-Rotating AI Mental Health Tips in Motion -->
<script>
    const mentalHealthTips = [
        "\"Take a deep breath. Today every small step forward is a victory for your healing.\"",
        "\"Your mental health is just as important as your physical health. Take things one calm moment at a time.\"",
        "\"It’s okay to pause and rest when you feel overwhelmed. Gentle progress is still progress.\"",
        "\"Connecting with someone you trust can lighten any burden. Reach out to our hospital counselors whenever you need support.\"",
        "\"Practice self-compassion today. Treat yourself with the same kindness and patience you would offer to a dear friend.\"",
        "\"Physical health and mental wellness go hand in hand. Breathe deeply, stay hydrated, and honor your pace today.\""
    ];

    let currentTipIndex = 0;
    const tipElement = document.getElementById('aiHealthTipText');

    function nextMentalHealthTip() {
        if (!tipElement) return;
        tipElement.style.opacity = 0;
        
        setTimeout(() => {
            currentTipIndex = (currentTipIndex + 1) % mentalHealthTips.length;
            tipElement.innerText = mentalHealthTips[currentTipIndex];
            tipElement.style.opacity = 1;
        }, 300);
    }

    document.addEventListener("DOMContentLoaded", function() {
        if (tipElement) {
            tipElement.style.transition = "opacity 0.5s ease-in-out";
        }
        setInterval(nextMentalHealthTip, 6000);
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>