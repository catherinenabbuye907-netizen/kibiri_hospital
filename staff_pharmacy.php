<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Security check: ensure user is logged in as Pharmacist, Administrator, or Staff
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'] ?? '', ['Pharmacist', 'Administrator', 'Pharmacy Staff', 'Doctor'], true)) {
    header('Location: login.php');
    exit;
}

$fullName = htmlspecialchars($_SESSION['full_name'] ?? 'Pharmacy Staff', ENT_QUOTES, 'UTF-8');
$departmentName = htmlspecialchars($_SESSION['department_name'] ?? 'Pharmacy', ENT_QUOTES, 'UTF-8');
$userRole = $_SESSION['role_name'] ?? 'Pharmacist';

require_once __DIR__ . '/configdatabase.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Add New Stock / Restock Medication
    if (isset($_POST['action']) && $_POST['action'] === 'add_stock') {
        $medName = trim($_POST['med_name'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $quantity = filter_var($_POST['quantity'], FILTER_VALIDATE_INT);
        $unitPrice = filter_var($_POST['unit_price'], FILTER_VALIDATE_FLOAT);

        if (!empty($medName) && $quantity !== false && $unitPrice !== false) {
            try {
                $stmtCheck = $pdo->prepare("SELECT medicine_id, stock_quantity FROM medicines WHERE medicine_name = ?");
                $stmtCheck->execute([$medName]);
                $existingMed = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($existingMed) {
                    $newQty = $existingMed['stock_quantity'] + $quantity;
                    $stmtUpdate = $pdo->prepare("UPDATE medicines SET stock_quantity = ?, unit_price = ? WHERE medicine_id = ?");
                    $stmtUpdate->execute([$newQty, $unitPrice, $existingMed['medicine_id']]);
                } else {
                    $stmtInsert = $pdo->prepare("INSERT INTO medicines (medicine_name, category, stock_quantity, unit_price) VALUES (?, ?, ?, ?)");
                    $stmtInsert->execute([$medName, $category, $quantity, $unitPrice]);
                }
                $message = "Stock successfully updated / added!";
            } catch (Exception $e) {
                $error = "Error saving stock: " . $e->getMessage();
            }
        } else {
            $error = "Please provide valid stock details.";
        }
    }

    // 2. Prescribing Drug, Linking Patient from Database, & Sending Bill to Cashier
    if (isset($_POST['action']) && $_POST['action'] === 'prescribe_drug') {
        $patientDbId = filter_var($_POST['patient_db_id'], FILTER_VALIDATE_INT);
        $medId = filter_var($_POST['medication_id'], FILTER_VALIDATE_INT);
        $qtyPrescribed = filter_var($_POST['quantity_prescribed'], FILTER_VALIDATE_INT);
        $dosage = trim($_POST['dosage'] ?? '1 tab');

        if ($patientDbId && $medId && $qtyPrescribed && $qtyPrescribed > 0) {
            try {
                // Fetch Patient details from database
                $stmtPat = $pdo->prepare("SELECT hospital_number, full_name FROM patients WHERE patient_id = ?");
                $stmtPat->execute([$patientDbId]);
                $patient = $stmtPat->fetch(PDO::FETCH_ASSOC);

                // Fetch Medicine details
                $stmtMed = $pdo->prepare("SELECT * FROM medicines WHERE medicine_id = ?");
                $stmtMed->execute([$medId]);
                $med = $stmtMed->fetch(PDO::FETCH_ASSOC);

                if ($patient && $med && $med['stock_quantity'] >= $qtyPrescribed) {
                    $totalAmount = $qtyPrescribed * (float)$med['unit_price'];
                    $patientDisplayName = $patient['full_name'] . " (" . $patient['hospital_number'] . ")";

                    $pdo->beginTransaction();

                    // Insert into pharmacy prescriptions
                    $stmtRx = $pdo->prepare("INSERT INTO pharmacy_prescriptions (patient_name, medication, dosage, status, created_at) VALUES (?, ?, ?, 'Pending', NOW())");
                    $stmtRx->execute([$patientDisplayName, $qtyPrescribed . "x " . $med['medicine_name'], $dosage]);
                    $prescriptionId = $pdo->lastInsertId();

                    // Send bill to Cashier / Staff Billing Department
                    $itemDesc = "Hospital No: [" . $patient['hospital_number'] . "] - " . $med['medicine_name'] . " (Qty: {$qtyPrescribed})";
                    $stmtBill = $pdo->prepare("INSERT INTO billing_queue (prescription_id, patient_name, item_description, amount_due, status, created_at) VALUES (?, ?, ?, ?, 'Unpaid', NOW())");
                    $stmtBill->execute([$prescriptionId, $patient['full_name'], $itemDesc, $totalAmount]);

                    // Deduct stock from inventory
                    $stmtStock = $pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity - ? WHERE medicine_id = ?");
                    $stmtStock->execute([$qtyPrescribed, $medId]);

                    $pdo->commit();
                    $message = "Prescription successfully linked to patient {$patient['full_name']} ({$patient['hospital_number']}) and billed to cashier (Total: UGX " . number_format($totalAmount) . ").";
                } else {
                    $error = "Selected patient not found or insufficient medicine stock available.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error processing prescription: " . $e->getMessage();
            }
        } else {
            $error = "Please select a valid patient, medicine, and quantity.";
        }
    }
}

// Fetch patients from database for dropdown selection
$patients = [];
try {
    $stmtPatients = $pdo->query("SELECT patient_id, hospital_number, full_name FROM patients ORDER BY full_name ASC");
    $patients = $stmtPatients->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $patients = [];
}

// Fetch available medicines
$medicines = [];
try {
    $stmt = $pdo->query("SELECT * FROM medicines ORDER BY medicine_name ASC");
    $medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $medicines = [];
}

// Low-Stock Alert Query
$lowStockMeds = [];
try {
    $stmtLow = $pdo->query("SELECT * FROM medicines WHERE stock_quantity <= 20 ORDER BY stock_quantity ASC");
    $lowStockMeds = $stmtLow->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lowStockMeds = [];
}

// Fetch prescriptions
$prescriptions = [];
try {
    $stmtRxQ = $pdo->query("SELECT * FROM pharmacy_prescriptions ORDER BY created_at DESC LIMIT 20");
    $prescriptions = $stmtRxQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $prescriptions = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy & Inventory Module - Kibiri Hospital HMIS</title>
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
        .sidebar {
            width: 260px;
            background-color: var(--primary);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 25px 20px;
        }
        .sidebar h2 { font-size: 1.2rem; margin: 0 0 5px 0; }
        .sidebar p { font-size: 0.85rem; color: #a7f3d0; margin: 0 0 30px 0; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; flex-grow: 1; }
        .sidebar-menu li { margin-bottom: 12px; }
        .sidebar-menu a {
            color: white;
            text-decoration: none;
            display: block;
            padding: 10px 14px;
            border-radius: 6px;
            transition: background 0.2s;
            font-size: 0.95rem;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active { background-color: var(--primary-dark); }
        .logout-btn {
            background-color: #dc2626;
            color: white;
            text-align: center;
            padding: 10px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            display: block;
        }
        .main-content { flex-grow: 1; padding: 30px; overflow-y: auto; }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: var(--card-bg);
            padding: 20px 25px;
            border-radius: 10px;
            border: 1px solid var(--border);
        }
        .alert-success { background: #d1fae5; color: #065f46; padding: 12px 20px; border-radius: 6px; margin-bottom: 20px; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 12px 20px; border-radius: 6px; margin-bottom: 20px; }
        .alert-warning { background: #fef3c7; color: #92400e; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; border-left: 5px solid #d97706; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        @media (max-width: 1024px) { .grid-2 { grid-template-columns: 1fr; } }
        
        .card {
            background: var(--card-bg);
            border-radius: 10px;
            border: 1px solid var(--border);
            padding: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        .card h3 { margin-top: 0; font-size: 1.1rem; border-bottom: 2px solid var(--border); padding-bottom: 10px; }
        
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; margin-top: 10px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid var(--border); }
        th { background-color: #f8fafc; color: #475569; font-weight: 600; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 5px; color: #475569; }
        .form-control { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; box-sizing: border-box; }
        .btn-primary { background-color: var(--primary); color: white; border: none; padding: 10px 18px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-primary:hover { background-color: var(--primary-dark); }
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
        .badge.dispensed { background-color: #d1fae5; color: #065f46; }
        .badge.pending { background-color: #fef3c7; color: #92400e; }
        .badge.low-stock { background-color: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div>
            <h2>Kibiri Hospital HMIS</h2>
            <p>Pharmacy & Doctor Portal</p>
            <ul class="sidebar-menu">
                <li><a href="staff_pharmacy.php" class="active">Pharmacy Dashboard</a></li>
            </ul>
        </div>
        <div>
            <div style="margin-bottom: 15px; font-size: 0.9rem;">
                Logged in as:<br><strong><?= $fullName ?></strong><br>
                <span style="color: #a7f3d0; font-size: 0.8rem;">Role: <?= $userRole ?></span>
            </div>
            <a href="logout.php" class="logout-btn">Sign Out</a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        
        <div class="top-bar">
            <h1>Pharmacy Inventory & Patient Prescription Module</h1>
            <span style="color: #64748b; font-size: 0.9rem;">Station: <strong><?= $departmentName ?></strong></span>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- LOW STOCK ALERT WARNING BANNER -->
        <?php if (!empty($lowStockMeds)): ?>
            <div class="alert-warning">
                <strong>⚠️ Low Stock Alert:</strong> The following medicines are running low and require restocking:
                <ul style="margin: 5px 0 0 20px; padding: 0;">
                    <?php foreach ($lowStockMeds as $low): ?>
                        <li><?= htmlspecialchars($low['medicine_name'], ENT_QUOTES, 'UTF-8') ?> — <strong>Only <?= $low['stock_quantity'] ?> left</strong></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- SECTION 1: PRESCRIBE & BILL LINKED TO PATIENTS TABLE -->
        <div class="card" style="border-left: 4px solid #0284c7;">
            <h3>🩺 Patient Prescription & Cashier Billing Station</h3>
            <p style="font-size: 0.9rem; color: #64748b;">Select a registered patient from the database, pick available medicine, specify quantity, and send the bill directly to the cashier queue.</p>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="prescribe_drug">
                <div style="display: grid; grid-template-columns: 2fr 2fr 1fr 2fr auto; gap: 15px; align-items: flex-end;">
                    <div class="form-group" style="margin:0;">
                        <label>Select Registered Patient</label>
                        <select name="patient_db_id" class="form-control" required>
                            <option value="">-- Choose Patient --</option>
                            <?php foreach ($patients as $pat): ?>
                                <option value="<?= $pat['patient_id'] ?>">
                                    <?= htmlspecialchars($pat['full_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($pat['hospital_number'], ENT_QUOTES, 'UTF-8') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Select Medicine & Stock</label>
                        <select name="medication_id" class="form-control" required>
                            <option value="">-- Choose Medication --</option>
                            <?php foreach ($medicines as $med): ?>
                                <option value="<?= $med['medicine_id'] ?>">
                                    <?= htmlspecialchars($med['medicine_name'], ENT_QUOTES, 'UTF-8') ?> 
                                    (Stock: <?= $med['stock_quantity'] ?> | UGX <?= number_format((float)$med['unit_price']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Quantity</label>
                        <input type="number" name="quantity_prescribed" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Dosage</label>
                        <input type="text" name="dosage" class="form-control" placeholder="1 tab TID" required>
                    </div>
                    <div>
                        <button type="submit" class="btn-primary" style="background-color: #0284c7; height: 42px;">Prescribe & Bill</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="grid-2">
            <!-- SECTION 2: INVENTORY SEARCH & STOCK TABLE -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="margin:0; border:none; padding:0;">💊 Pharmacy Inventory Search</h3>
                    <input type="text" id="inventorySearch" onkeyup="filterInventory()" placeholder="🔍 Filter medicines..." class="form-control" style="width: 200px; padding: 6px 10px; font-size: 0.85rem;">
                </div>
                <table id="inventoryTable">
                    <thead>
                        <tr>
                            <th>Medicine Name</th>
                            <th>Category</th>
                            <th>Stock Qty</th>
                            <th>Unit Price (UGX)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medicines as $med): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($med['medicine_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td><?= htmlspecialchars($med['category'] ?? 'General', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($med['stock_quantity'] <= 20): ?>
                                        <span class="badge low-stock"><?= $med['stock_quantity'] ?> (Low)</span>
                                    <?php else: ?>
                                        <span class="badge dispensed"><?= $med['stock_quantity'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format((float)$med['unit_price']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- SECTION 3: ADD NEW STOCK / RESTOCK -->
            <div class="card">
                <h3>📦 Add / Restock Drugs</h3>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_stock">
                    <div class="form-group">
                        <label>Medication Name</label>
                        <input type="text" name="med_name" class="form-control" placeholder="e.g. Ibuprofen 400mg" required>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" name="category" class="form-control" placeholder="e.g. Analgesics" required>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label>Quantity to Add</label>
                            <input type="number" name="quantity" class="form-control" min="1" placeholder="100" required>
                        </div>
                        <div class="form-group">
                            <label>Unit Price (UGX)</label>
                            <input type="number" step="0.01" name="unit_price" class="form-control" placeholder="500" required>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary" style="margin-top: 15px;">Save / Restock Drug</button>
                </form>
            </div>
        </div>

        <!-- SECTION 4: PRESCRIPTION & BILLING QUEUE -->
        <div class="card">
            <h3>📋 Patient Prescription & Cashier Billing Queue</h3>
            <?php if (empty($prescriptions)): ?>
                <p style="color: #64748b; text-align: center; padding: 20px;">No prescriptions in the queue.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Rx ID</th>
                            <th>Patient Name & Hospital No.</th>
                            <th>Prescribed Medication</th>
                            <th>Dosage</th>
                            <th>Status</th>
                            <th>Date / Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prescriptions as $rx): ?>
                            <tr>
                                <td>#RX-<?= htmlspecialchars((string)($rx['prescription_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($rx['patient_name'] ?? 'Walk-in', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($rx['medication'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($rx['dosage'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php $status = $rx['status'] ?? 'Pending'; ?>
                                    <span class="badge <?= strtolower($status) ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td><?= htmlspecialchars($rx['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>

    <!-- SCRIPT FOR LIVE INVENTORY SEARCH -->
    <script>
        function filterInventory() {
            const input = document.getElementById('inventorySearch');
            const filter = input.value.toLowerCase().trim();
            const table = document.getElementById('inventoryTable');
            const tbody = table.getElementsByTagName('tbody')[0];
            const rows = tbody.getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const rowText = row.textContent.toLowerCase();
                if (rowText.indexOf(filter) > -1) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>