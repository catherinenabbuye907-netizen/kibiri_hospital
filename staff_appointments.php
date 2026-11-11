<?php
declare(strict_types=1);
require_once __DIR__ . '/configdatabase.php';
require_once __DIR__ . '/auth_check.php';

if (!in_array($_SESSION['role_name'] ?? '', ['Receptionist', 'Administrator'], true)) {
    http_response_code(403);
    exit('Access denied. This page is restricted to reception staff.');
}

// Handle status updates from staff
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appointment_id'], $_POST['status'])) {
    $apptId = (int)$_POST['appointment_id'];
    $newStatus = $_POST['status'];

    $allowedStatuses = ['Pending', 'Confirmed', 'Completed', 'Cancelled'];
    if (in_array($newStatus, $allowedStatuses, true)) {
        $update = $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
        $update->execute([$newStatus, $apptId]);
        header('Location: staff_appointments.php?msg=Status updated successfully');
        exit;
    }
}

// Fetch all appointments with patient details
$stmt = $pdo->query(
    "SELECT a.appointment_id, a.department, a.preferred_date, a.preferred_time, a.reason, a.status, a.created_at,
            p.full_name, p.hospital_number, p.phone, p.email
     FROM appointments a
     JOIN patients p ON a.patient_id = p.patient_id
     ORDER BY a.preferred_date ASC, a.preferred_time ASC"
);
$appointments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Appointments - Kibiri Hospital HMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark hospital-navbar px-4 bg-success">
    <span class="navbar-brand fw-bold">KIBIRI HOSPITAL HMIS | Staff Portal</span>
    <a href="index.php" class="btn btn-outline-light btn-sm">Back to Dashboard</a>
</nav>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Appointment Management Module</h2>
        <span class="text-muted">Total Bookings: <?= count($appointments) ?></span>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <?php if (empty($appointments)): ?>
                <p class="text-muted mb-0">No appointment requests found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Patient</th>
                                <th>Department</th>
                                <th>Date & Time</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $appt): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($appt['full_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($appt['hospital_number'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($appt['phone'], ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($appt['department'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?= htmlspecialchars($appt['preferred_date'], ENT_QUOTES, 'UTF-8') ?><br>
                                        <small class="text-muted"><?= htmlspecialchars($appt['preferred_time'], ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
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
                                    <td>
                                        <form method="post" class="d-inline-flex gap-1">
                                            <input type="hidden" name="appointment_id" value="<?= $appt['appointment_id'] ?>">
                                            <select name="status" class="form-select form-select-sm" style="width: 120px;">
                                                <option value="Pending" <?= $appt['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="Confirmed" <?= $appt['status'] === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                <option value="Completed" <?= $appt['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                                <option value="Cancelled" <?= $appt['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-success">Update</button>
                                        </form>
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

</body>
</html>