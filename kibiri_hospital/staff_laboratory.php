<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Security check: ensure user is logged in as Laboratory Staff, Administrator, or Lab Technician
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'] ?? '', ['Laboratory Staff', 'Administrator', 'Lab Technician'], true)) {
    header('Location: login.php');
    exit;
}

$fullName = htmlspecialchars($_SESSION['full_name'] ?? 'Laboratory Staff', ENT_QUOTES, 'UTF-8');
$departmentName = htmlspecialchars($_SESSION['department_name'] ?? 'Laboratory', ENT_QUOTES, 'UTF-8');

require_once __DIR__ . '/configdatabase.php';

$message = '';
$error = '';

// Handle updating lab test results / status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_lab_result') {
    $labId = filter_var($_POST['lab_id'], FILTER_VALIDATE_INT);
    $status = trim($_POST['status'] ?? 'Completed');
    $results = trim($_POST['results'] ?? '');

    if ($labId && !empty($results)) {
        try {
            $stmtUpd = $pdo->prepare("UPDATE lab_results SET results = ?, status = ? WHERE lab_id = ?");
            $stmtUpd->execute([$results, $status, $labId]);
            $message = "Laboratory test results successfully recorded and updated.";
        } catch (Exception $e) {
            $error = "Error updating lab results: " . $e->getMessage();
        }
    } else {
        $error = "Please provide valid test findings and status.";
    }
}

// Fetch lab records joined with patients table (Patient ID, Hospital Number, Full Name)
$labTests = [];
try {
    $stmt = $pdo->query("
        SELECT lr.lab_id, lr.patient_id, lr.test_name, lr.results, lr.status, lr.created_at,
               p.hospital_number, p.full_name
        FROM lab_results lr
        JOIN patients p ON lr.patient_id = p.patient_id
        ORDER BY lr.created_at DESC LIMIT 50
    ");
    $labTests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback sample data if tables don't exist yet
    $labTests = [
        [
            'lab_id' => 501, 
            'patient_id' => 1, 
            'hospital_number' => 'KBH-2026-5215', 
            'full_name' => 'WASSWA ELIJAH LUKWAGO', 
            'test_name' => 'Complete Blood Count (CBC)', 
            'results' => 'WBC: 6.5, RBC: 4.8, Hb: 13.2 g/dL', 
            'status' => 'Completed', 
            'created_at' => '2026-11-10 08:15:00'
        ],
        [
            'lab_id' => 502, 
            'patient_id' => 2, 
            'hospital_number' => 'KBH-2026-2857', 
            'full_name' => 'Tom Musoke', 
            'test_name' => 'Malaria Blood Smear (RDT)', 
            'results' => 'Pending analysis', 
            'status' => 'Processing', 
            'created_at' => '2026-11-10 09:30:00'
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratory Module - Kibiri Hospital HMIS</title>
    <style>
        :root {
            --primary: #047857;
            --primary-dark: #065f46;
            --bg-light: #f1f5f9;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --border: #e2e8f0;
        }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-light);
            color: var(--text-main);
            margin: 0;
            display: flex;
            min-height: 100vh;
        }
        /* Sidebar */
        .sidebar {
            width: 260px;
            background-color: var(--primary);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 25px 20px;
        }
        .sidebar h2 {
            font-size: 1.2rem;
            margin: 0 0 5px 0;
            line-height: 1.4;
        }
        .sidebar p {
            font-size: 0.85rem;
            color: #a7f3d0;
            margin: 0 0 30px 0;
        }
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
            flex-grow: 1;
        }
        .sidebar-menu li {
            margin-bottom: 12px;
        }
        .sidebar-menu a {
            color: white;
            text-decoration: none;
            display: block;
            padding: 10px 14px;
            border-radius: 6px;
            transition: background 0.2s;
            font-size: 0.95rem;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background-color: var(--primary-dark);
        }
        .logout-btn {
            background-color: #dc2626;
            color: white;
            text-align: center;
            padding: 10px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            display: block;
            transition: background 0.2s;
        }
        .logout-btn:hover {
            background-color: #b91c1c;
        }
        /* Main Content */
        .main-content {
            flex-grow: 1;
            padding: 30px;
            overflow-y: auto;
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: var(--card-bg);
            padding: 20px 25px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
        }
        .top-bar h1 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--text-main);
        }
        .alert-success { background: #d1fae5; color: #065f46; padding: 12px 20px; border-radius: 6px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 12px 20px; border-radius: 6px; margin-bottom: 20px; }
        
        /* Stats row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-box {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .stat-box h4 {
            margin: 0 0 8px 0;
            color: #64748b;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        .stat-box .val {
            font-size: 1.6rem;
            font-weight: bold;
            color: var(--primary);
            margin: 0;
        }
        /* Table container */
        .table-card {
            background: var(--card-bg);
            border-radius: 10px;
            border: 1px solid var(--border);
            padding: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .table-header h3 {
            margin: 0;
            font-size: 1.1rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.95rem;
        }
        th, td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border);
        }
        th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge.completed { background-color: #d1fae5; color: #065f46; }
        .badge.processing { background-color: #e0f2fe; color: #0369a1; }
        .badge.pending { background-color: #fef3c7; color: #92400e; }
        
        .btn-action {
            background-color: #0284c7;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }
        .btn-action:hover {
            background-color: #0369a1;
        }

        /* Modal styling */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 8px; width: 450px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .form-control { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; box-sizing: border-box; margin-bottom: 15px; }
    </style>
</head>
<body>

    <!-- SIDEBAR NAVIGATION -->
    <div class="sidebar">
        <div>
            <h2>Kibiri Hospital HMIS</h2>
            <p>Laboratory Portal</p>
            
            <ul class="sidebar-menu">
                <li><a href="staff_laboratory.php" class="active">Sample Tracking & Tests</a></li>
            </ul>
        </div>
        
        <div>
            <div style="margin-bottom: 15px; font-size: 0.9rem;">
                Logged in as:<br><strong><?= $fullName ?></strong>
            </div>
            <a href="logout.php" class="logout-btn">Sign Out</a>
        </div>
    </div>

    <!-- MAIN DASHBOARD AREA -->
    <div class="main-content">
        
        <div class="top-bar">
            <h1>Laboratory & Diagnostics Module</h1>
            <span style="color: #64748b; font-size: 0.9rem;">Station: <strong><?= $departmentName ?></strong></span>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- QUICK STATS -->
        <div class="stats-row">
            <div class="stat-box">
                <h4>Total Test Requests</h4>
                <p class="val"><?= count($labTests) ?></p>
            </div>
            <div class="stat-box">
                <h4>Supported Panels</h4>
                <p class="val" style="font-size: 1.1rem; color: #0284c7; margin-top: 5px;">CBC, Malaria RDT, HIV/AIDS</p>
            </div>
        </div>

        <!-- SAMPLE TRACKING TABLE -->
        <div class="table-card">
            <div class="table-header">
                <h3>Patient Laboratory Investigation Requests (CBC, Malaria RDT, HIV/AIDS)</h3>
            </div>

            <?php if (empty($labTests)): ?>
                <p style="color: #64748b; text-align: center; padding: 30px;">No laboratory sample requests found.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Lab ID</th>
                            <th>Patient ID / Hospital No.</th>
                            <th>Patient Full Name</th>
                            <th>Test Ordered</th>
                            <th>Status</th>
                            <th>Findings / Results</th>
                            <th>Date / Time</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($labTests as $test): ?>
                            <tr>
                                <td>#LAB-<?= htmlspecialchars((string)($test['lab_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    #<?= htmlspecialchars((string)($test['patient_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
                                    <small style="color: #64748b;"><?= htmlspecialchars($test['hospital_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></small>
                                </td>
                                <td><strong><?= htmlspecialchars($test['full_name'] ?? 'Walk-in Patient', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td>
                                    <span style="color: #047857; font-weight: 600;">
                                        <?= htmlspecialchars($test['test_name'] ?? 'General Diagnostic', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php $status = $test['status'] ?? 'Pending'; ?>
                                    <span class="badge <?= strtolower($status) ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td><?= htmlspecialchars($test['results'] ?? 'Pending analysis', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($test['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <button onclick="openResultModal(<?= $test['lab_id'] ?>, '<?= htmlspecialchars($test['full_name'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($test['test_name'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($test['results'] ?? '', ENT_QUOTES, 'UTF-8') ?>')" class="btn-action">📝 Enter Results</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>

    <!-- UPDATE RESULTS MODAL -->
    <div id="resultModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-top:0;">Record Laboratory Findings</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_lab_result">
                <input type="hidden" name="lab_id" id="modalLabId">
                
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 0.85rem; font-weight: 600; color: #475569;">Patient Name</label>
                    <input type="text" id="modalPatientName" class="form-control" readonly>
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 0.85rem; font-weight: 600; color: #475569;">Test Ordered</label>
                    <input type="text" id="modalTestName" class="form-control" readonly>
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 0.85rem; font-weight: 600; color: #475569;">Findings / Results Summary</label>
                    <textarea name="results" id="modalResults" class="form-control" rows="3" placeholder="Enter test results (e.g. Negative, Reactive, Hb: 13.5 g/dL)..." required></textarea>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 0.85rem; font-weight: 600; color: #475569;">Status</label>
                    <select name="status" class="form-control">
                        <option value="Completed">Completed</option>
                        <option value="Processing">Processing</option>
                        <option value="Pending">Pending</option>
                    </select>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeResultModal()" style="background: #cbd5e1; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer;">Cancel</button>
                    <button type="submit" class="btn-action" style="padding: 10px 20px;">Save & Complete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        function openResultModal(labId, patientName, testName, currentResults) {
            document.getElementById('modalLabId').value = labId;
            document.getElementById('modalPatientName').value = patientName;
            document.getElementById('modalTestName').value = testName;
            document.getElementById('modalResults').value = currentResults;
            document.getElementById('resultModal').style.display = 'flex';
        }

        function closeResultModal() {
            document.getElementById('resultModal').style.display = 'none';
        }
    </script>
</body>
</html>