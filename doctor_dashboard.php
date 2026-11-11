<?php
declare(strict_types=1);
require_once __DIR__ . '/configdatabase.php';
require_once __DIR__ . '/auth_check.php';

// Enforce role authorization (Ensure user is a Doctor or Administrator)
if (!in_array($_SESSION['role_name'] ?? '', ['Doctor', 'Administrator'], true)) {
    http_response_code(403);
    exit('Access denied. This dashboard is restricted to clinical doctors.');
}

$doctorId = (int)$_SESSION['user_id'];
$doctorName = $_SESSION['full_name'];
$message = '';
$error = '';

// Ensure doctor_feedback table exists for replies
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doctor_feedback (
            feedback_id INT AUTO_INCREMENT PRIMARY KEY,
            note_id INT NOT NULL,
            doctor_id INT NOT NULL,
            feedback_text TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (note_id) REFERENCES patient_consultation_notes(note_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    // Handled silently
}

// Handle Actions (Consultation saving, Appointment confirmation & Doctor Feedback sending)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_consultation') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $prescription = trim($_POST['prescription'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $medicineId = !empty($_POST['medicine_id']) ? (int)$_POST['medicine_id'] : null;
        $quantity = !empty($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

        if ($patientId > 0 && $diagnosis !== '') {
            try {
                $pdo->beginTransaction();

                // 1. Record Consultation Record
                $stmt = $pdo->prepare(
                    "INSERT INTO consultations (patient_id, doctor_id, diagnosis, prescription, notes, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())"
                );
                $stmt->execute([$patientId, $doctorId, $diagnosis, $prescription, $notes]);

                // 2. Queue Prescription in Pharmacy if medicine selected
                if ($medicineId && $prescription !== '') {
                    $rxStmt = $pdo->prepare(
                        "INSERT INTO prescriptions (patient_id, doctor_id, medicine_id, dosage, quantity, status, created_at)
                         VALUES (?, ?, ?, ?, ?, 'Pending', NOW())"
                    );
                    $rxStmt->execute([$patientId, $doctorId, $medicineId, $prescription, $quantity]);
                }

                $pdo->commit();
                $message = 'Consultation record and prescription logged successfully.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Failed to record consultation: ' . $e->getMessage();
            }
        } else {
            $error = 'Please select a patient and enter a valid diagnosis.';
        }
    } elseif ($_POST['action'] === 'update_appointment') {
        $appId = (int)($_POST['appointment_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'Confirmed');

        if ($appId > 0) {
            $updStmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
            $updStmt->execute([$newStatus, $appId]);
            $message = "Appointment status successfully updated to {$newStatus}.";
        }
    } elseif ($_POST['action'] === 'send_doctor_feedback') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $feedbackText = trim($_POST['feedback_text'] ?? '');

        if ($noteId > 0 && $feedbackText !== '') {
            try {
                $fbStmt = $pdo->prepare(
                    "INSERT INTO doctor_feedback (note_id, doctor_id, feedback_text, created_at)
                     VALUES (?, ?, ?, NOW())"
                );
                $fbStmt->execute([$noteId, $doctorId, $feedbackText]);
                $message = 'Clinical feedback sent successfully to the patient.';
            } catch (Exception $e) {
                $error = 'Failed to send feedback: ' . $e->getMessage();
            }
        } else {
            $error = 'Please enter your feedback message before submitting.';
        }
    }
}

// Fetch Active Patients List
$patientsStmt = $pdo->query("SELECT patient_id, hospital_number, full_name, gender, date_of_birth FROM patients WHERE status = 'active' ORDER BY full_name ASC");
$patients = $patientsStmt->fetchAll();

// Fetch Medicines for Doctor Prescription Dropdown
$medsStmt = $pdo->query("SELECT medicine_id, medicine_name, stock_quantity FROM medicines WHERE stock_quantity > 0 ORDER BY medicine_name ASC");
$medicines = $medsStmt->fetchAll();

// Fetch Patient Appointments for Doctor Review
$appointmentsStmt = $pdo->query(
    "SELECT a.appointment_id, a.department, a.preferred_date, a.preferred_time, a.reason, a.status, a.created_at,
            p.full_name AS patient_name, p.hospital_number, p.phone
     FROM appointments a
     JOIN patients p ON a.patient_id = p.patient_id
     ORDER BY a.preferred_date DESC LIMIT 25"
);
$appointments = $appointmentsStmt->fetchAll();

// Fetch Patient Consultation History for Review View
$historyStmt = $pdo->query(
    "SELECT c.consultation_id, c.diagnosis, c.prescription, c.notes, c.created_at,
            p.full_name AS patient_name, p.hospital_number
     FROM consultations c
     JOIN patients p ON c.patient_id = p.patient_id
     ORDER BY c.created_at DESC LIMIT 20"
);
$consultationHistory = $historyStmt->fetchAll();

// Fetch Patient Consultation Notes / Inbox sent by patients
$patientNotes = [];
try {
    $notesStmt = $pdo->query(
        "SELECT n.note_id, n.patient_id, n.message, n.created_at,
                p.full_name AS patient_name, p.hospital_number, p.phone,
                df.feedback_text, df.created_at AS feedback_date, u.full_name AS feedback_doctor
         FROM patient_consultation_notes n
         JOIN patients p ON n.patient_id = p.patient_id
         LEFT JOIN doctor_feedback df ON n.note_id = df.note_id
         LEFT JOIN users u ON df.doctor_id = u.user_id
         ORDER BY n.created_at DESC LIMIT 20"
    );
    $patientNotes = $notesStmt->fetchAll();
} catch (Exception $e) {
    $patientNotes = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Doctor Clinical Console - Kibiri Hospital HMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark hospital-navbar px-4 bg-success">
    <span class="navbar-brand fw-bold">KIBIRI HOSPITAL HMIS | Doctor Portal</span>
    <div class="d-flex align-items-center gap-3">
        <span class="text-white">Dr. <?= htmlspecialchars($doctorName, ENT_QUOTES, 'UTF-8') ?></span>
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
        <!-- Column 1: Patient Consultation Form & History Archive -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h4 class="mb-0 text-success fw-bold">New Clinical Consultation Note</h4>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="save_consultation">

                        <div class="mb-3">
                            <label for="patient_id" class="form-label fw-bold">Select Patient</label>
                            <select name="patient_id" id="patient_id" class="form-select" required>
                                <option value="">-- Choose Patient --</option>
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?= $p['patient_id'] ?>">
                                        <?= htmlspecialchars($p['full_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($p['hospital_number'], ENT_QUOTES, 'UTF-8') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="diagnosis" class="form-label fw-bold">Clinical Diagnosis</label>
                            <textarea name="diagnosis" id="diagnosis" class="form-control" rows="2" placeholder="Primary diagnosis and findings..." required></textarea>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-7">
                                <label for="medicine_id" class="form-label fw-bold">Prescribe Medicine (Optional)</label>
                                <select name="medicine_id" id="medicine_id" class="form-select">
                                    <option value="">-- Select Available Drug --</option>
                                    <?php foreach ($medicines as $m): ?>
                                        <option value="<?= $m['medicine_id'] ?>">
                                            <?= htmlspecialchars($m['medicine_name'], ENT_QUOTES, 'UTF-8') ?> (In Stock: <?= $m['stock_quantity'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label for="quantity" class="form-label fw-bold">Quantity</label>
                                <input type="number" min="1" name="quantity" id="quantity" class="form-control" value="1">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="prescription" class="form-label fw-bold">Dosage & Instructions</label>
                            <input type="text" name="prescription" id="prescription" class="form-control" placeholder="e.g., 500mg twice daily for 7 days">
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label fw-bold">Additional Clinical Notes</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Follow-up instructions, observations..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-success w-100">Save Consultation & Send Prescription</button>
                    </form>
                </div>
            </div>

            <!-- Patient Consultation Notes Archive & Feedback Dispatcher -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 text-success fw-bold">💬 Patient Consultation Notes Archive & Feedback</h5>
                </div>
                <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                    <?php if (empty($patientNotes)): ?>
                        <div class="p-3 text-muted">No consultation notes submitted by patients yet.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($patientNotes as $pnote): ?>
                                <div class="list-group-item p-3 border-bottom">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <strong class="text-dark"><?= htmlspecialchars($pnote['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <small class="text-muted"><?= htmlspecialchars($pnote['created_at'], ENT_QUOTES, 'UTF-8') ?></small>
                                    </div>
                                    <div class="small text-muted mb-2">Hospital ID: <?= htmlspecialchars($pnote['hospital_number'], ENT_QUOTES, 'UTF-8') ?> | Phone: <?= htmlspecialchars($pnote['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
                                    
                                    <div class="p-2 bg-light rounded border text-dark small mb-3">
                                        <strong>Patient Note:</strong> <?= htmlspecialchars($pnote['message'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>

                                    <?php if (!empty($pnote['feedback_text'])): ?>
                                        <div class="p-2 bg-success bg-opacity-10 rounded border border-success small mb-2">
                                            <strong>Doctor Feedback (<?= htmlspecialchars($pnote['feedback_doctor'] ?? 'Doctor', ENT_QUOTES, 'UTF-8') ?>):</strong> 
                                            <?= htmlspecialchars($pnote['feedback_text'], ENT_QUOTES, 'UTF-8') ?>
                                            <div class="text-muted mt-1" style="font-size: 0.75rem;">Sent on: <?= htmlspecialchars($pnote['feedback_date'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    <?php else: ?>
                                        <form method="post" class="mt-2">
                                            <input type="hidden" name="action" value="send_doctor_feedback">
                                            <input type="hidden" name="note_id" value="<?= $pnote['note_id'] ?>">
                                            <div class="input-group input-group-sm">
                                                <input type="text" name="feedback_text" class="form-control" placeholder="Type feedback reply for patient..." required>
                                                <button type="submit" class="btn btn-outline-success">Send Feedback</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Patient Consultation History & Notes View -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 text-success fw-bold">Doctor's Past Consultation History Archive</h5>
                </div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($consultationHistory)): ?>
                        <div class="p-3 text-muted">No consultation records found.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($consultationHistory as $hist): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <strong class="text-dark"><?= htmlspecialchars($hist['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <small class="text-muted"><?= htmlspecialchars($hist['created_at'], ENT_QUOTES, 'UTF-8') ?></small>
                                    </div>
                                    <div class="small text-muted mb-1">Hospital Number: <?= htmlspecialchars($hist['hospital_number'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="small fw-bold text-success mb-1">Diagnosis: <?= htmlspecialchars($hist['diagnosis'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($hist['prescription'])): ?>
                                        <div class="small text-secondary mb-1">Prescription: <?= htmlspecialchars($hist['prescription'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($hist['notes'])): ?>
                                        <div class="p-2 bg-light rounded border text-muted small">Notes: <?= htmlspecialchars($hist['notes'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Column 2: Manage Booked Patient Appointments -->
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h4 class="mb-0 text-success fw-bold">Patient Appointments</h4>
                    <span class="badge bg-success"><?= count($appointments) ?> Booked</span>
                </div>
                <div class="card-body p-0" style="max-height: 800px; overflow-y: auto;">
                    <?php if (empty($appointments)): ?>
                        <div class="p-4 text-muted">No patient appointments booked at this time.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($appointments as $app): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <strong class="text-dark"><?= htmlspecialchars($app['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span class="badge bg-<?= $app['status'] === 'Confirmed' ? 'success' : ($app['status'] === 'Pending' ? 'warning text-dark' : 'secondary') ?>">
                                            <?= htmlspecialchars($app['status'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                    <div class="text-muted small mb-1">
                                        ID: <?= htmlspecialchars($app['hospital_number'], ENT_QUOTES, 'UTF-8') ?> | Phone: <?= htmlspecialchars($app['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="fw-bold text-success small mb-1">
                                        Dept: <?= htmlspecialchars($app['department'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="text-dark small mb-2">
                                        <strong>Preferred Time:</strong> <?= htmlspecialchars($app['preferred_date'], ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars($app['preferred_time'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="p-2 bg-light rounded border text-secondary small mb-2">
                                        <strong>Reason:</strong> <?= htmlspecialchars($app['reason'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <form method="post" class="d-flex gap-2">
                                        <input type="hidden" name="action" value="update_appointment">
                                        <input type="hidden" name="appointment_id" value="<?= $app['appointment_id'] ?>">
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="Confirmed" <?= $app['status'] === 'Confirmed' ? 'selected' : '' ?>>Confirm</option>
                                            <option value="Completed" <?= $app['status'] === 'Completed' ? 'selected' : '' ?>>Complete</option>
                                            <option value="Cancelled" <?= $app['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancel</option>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-success">Update</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
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