<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Security check: ensure user is logged in as Cashier, Accountant, or Administrator
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'] ?? '', ['Cashier', 'Administrator', 'Billing Staff', 'Accountant'], true)) {
    header('Location: login.php');
    exit;
}

$fullName = htmlspecialchars($_SESSION['full_name'] ?? 'Billing Staff', ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($_SESSION['username'] ?? $_SESSION['full_name'] ?? 'cashier_user', ENT_QUOTES, 'UTF-8');
$departmentName = htmlspecialchars($_SESSION['department_name'] ?? 'Billing & Cashier', ENT_QUOTES, 'UTF-8');

require_once __DIR__ . '/configdatabase.php';

$message = '';
$error = '';

// Handle Direct Counter Bill Creation & Receipt Generation by Cashier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_counter_bill') {
    $patientDbId = filter_var($_POST['patient_db_id'], FILTER_VALIDATE_INT);
    $medicationId = filter_var($_POST['medication_id'], FILTER_VALIDATE_INT);
    $quantity = filter_var($_POST['quantity'], FILTER_VALIDATE_INT);
    $amountPaid = filter_var($_POST['amount_paid'], FILTER_VALIDATE_FLOAT);

    if ($patientDbId && $medicationId && $quantity && $quantity > 0 && $amountPaid !== false) {
        try {
            $pdo->beginTransaction();

            // 1. Fetch patient details from database
            $stmtPat = $pdo->prepare("SELECT hospital_number, full_name FROM patients WHERE patient_id = ?");
            $stmtPat->execute([$patientDbId]);
            $patient = $stmtPat->fetch(PDO::FETCH_ASSOC);

            if (!$patient) {
                throw new Exception("Selected patient record not found.");
            }

            // 2. Fetch medicine stock and unit price
            $stmtMed = $pdo->prepare("SELECT * FROM medicines WHERE medicine_id = ?");
            $stmtMed->execute([$medicationId]);
            $med = $stmtMed->fetch(PDO::FETCH_ASSOC);

            if (!$med) {
                throw new Exception("Selected medication not found in inventory.");
            }

            if ($med['stock_quantity'] < $quantity) {
                throw new Exception("Insufficient stock available! Current stock: " . $med['stock_quantity']);
            }

            $unitPrice = (float)$med['unit_price'];
            $totalAmountDue = $unitPrice * $quantity;

            if ($amountPaid < $totalAmountDue) {
                throw new Exception("Amount paid (UGX " . number_format($amountPaid) . ") is less than total due (UGX " . number_format($totalAmountDue) . ").");
            }

            // 3. Deduct stock from inventory
            $stmtUpdateStock = $pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity - ? WHERE medicine_id = ?");
            $stmtUpdateStock->execute([$quantity, $medicationId]);

            // 4. Insert into billing_queue as Paid (Direct Cashier Bill)
            $itemDesc = "Hospital No: [" . $patient['hospital_number'] . "] - " . $med['medicine_name'] . " (Qty: {$quantity} @ {$unitPrice})";
            $stmtBill = $pdo->prepare("
                INSERT INTO billing_queue 
                (prescription_id, patient_name, item_description, amount_due, amount_paid, status, cashier_name, created_at) 
                VALUES (NULL, ?, ?, ?, ?, 'Paid', ?, NOW())
            ");
            $stmtBill->execute([$patient['full_name'], $itemDesc, $totalAmountDue, $amountPaid, $fullName]);
            $newBillId = $pdo->lastInsertId();

            $pdo->commit();
            $message = "Bill and receipt generated successfully for {$patient['full_name']} ({$patient['hospital_number']}). Total Paid: UGX " . number_format($amountPaid);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error creating bill: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields with valid values.";
    }
}

// Handle Standard Payment Processing for existing unpaid bills
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_payment') {
    $billId = filter_var($_POST['bill_id'], FILTER_VALIDATE_INT);
    $amountPaid = filter_var($_POST['amount_paid'], FILTER_VALIDATE_FLOAT);

    if ($billId && $amountPaid !== false && $amountPaid > 0) {
        try {
            $pdo->beginTransaction();

            $stmtBill = $pdo->prepare("SELECT * FROM billing_queue WHERE id = ?");
            $stmtBill->execute([$billId]);
            $bill = $stmtBill->fetch(PDO::FETCH_ASSOC);

            if ($bill && $bill['status'] === 'Unpaid') {
                $stmtUpdate = $pdo->prepare("UPDATE billing_queue SET status = 'Paid', amount_paid = ?, paid_at = NOW(), cashier_name = ? WHERE id = ?");
                $stmtUpdate->execute([$amountPaid, $fullName, $billId]);

                if (!empty($bill['prescription_id'])) {
                    $stmtRx = $pdo->prepare("UPDATE pharmacy_prescriptions SET status = 'Paid/Ready for Dispensing' WHERE prescription_id = ?");
                    $stmtRx->execute([$bill['prescription_id']]);
                }

                $pdo->commit();
                $message = "Payment of UGX " . number_format($amountPaid) . " successfully processed and receipt generated.";
            } else {
                $pdo->rollBack();
                $error = "Bill not found or already paid.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error processing payment: " . $e->getMessage();
        }
    } else {
        $error = "Please enter a valid payment amount.";
    }
}

// Fetch Active Patients List from Database
$patients = [];
try {
    $patientsStmt = $pdo->query("SELECT patient_id, hospital_number, full_name FROM patients ORDER BY full_name ASC");
    $patients = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $patients = [];
}

// Fetch available medicines for cashier lookup
$medicines = [];
try {
    $stmtMeds = $pdo->query("SELECT * FROM medicines ORDER BY medicine_name ASC");
    $medicines = $stmtMeds->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $medicines = [];
}

// Fetch Bills
$bills = [];
try {
    $stmt = $pdo->query("SELECT * FROM billing_queue ORDER BY created_at DESC LIMIT 50");
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $bills = [];
}

// Calculate totals
$totalUnpaid = 0;
$totalCollectedToday = 0;
foreach ($bills as $b) {
    if (($b['status'] ?? '') === 'Unpaid') {
        $totalUnpaid += (float)($b['amount_due'] ?? 0);
    }
    if (($b['status'] ?? '') === 'Paid') {
        $totalCollectedToday += (float)($b['amount_paid'] ?? $b['amount_due'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier & Billing Department - Kibiri Hospital HMIS</title>
    <style>
        :root {
            --primary: #1e3a8a;
            --primary-dark: #1e40af;
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
        .sidebar p { font-size: 0.85rem; color: #93c5fd; margin: 0 0 30px 0; }
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
        
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-box { background: var(--card-bg); padding: 20px; border-radius: 8px; border: 1px solid var(--border); }
        .stat-box h4 { margin: 0 0 8px 0; color: #64748b; font-size: 0.85rem; text-transform: uppercase; }
        .stat-box .val { font-size: 1.6rem; font-weight: bold; color: var(--primary); margin: 0; }
        
        .card { background: var(--card-bg); border-radius: 10px; border: 1px solid var(--border); padding: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .card h3 { margin-top: 0; font-size: 1.1rem; border-bottom: 2px solid var(--border); padding-bottom: 10px; }
        
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; margin-top: 10px; }
        th, td { padding: 12px 15px; border-bottom: 1px solid var(--border); }
        th { background-color: #f8fafc; color: #475569; font-weight: 600; }
        
        .btn-pay { background-color: #16a34a; color: white; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 0.85rem; }
        .btn-pay:hover { background-color: #15803d; }
        .btn-print { background-color: #0284c7; color: white; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 0.85rem; text-decoration: none; display: inline-block; }
        .btn-print:hover { background-color: #0369a1; }
        
        .badge { padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
        .badge.paid { background-color: #d1fae5; color: #065f46; }
        .badge.unpaid { background-color: #fee2e2; color: #991b1b; }

        .form-grid { display: grid; grid-template-columns: 2fr 2fr 1fr 1fr auto; gap: 15px; align-items: flex-end; }
        @media (max-width: 1024px) { .form-grid { grid-template-columns: 1fr; } }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 5px; color: #475569; }
        .form-control { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.95rem; box-sizing: border-box; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 8px; width: 400px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div>
            <h2>Kibiri Hospital HMIS</h2>
            <p>Cashier & Billing Portal</p>
            <ul class="sidebar-menu">
                <li><a href="staff_billing.php" class="active">Billing & Payments</a></li>
            </ul>
        </div>
        <div>
            <div style="margin-bottom: 15px; font-size: 0.9rem;">
                Logged in as: <strong><?= $fullName ?></strong><br>
                <span style="color: #93c5fd; font-size: 0.8rem;">Username: @<?= $username ?></span>
            </div>
            <a href="logout.php" class="logout-btn">Sign Out</a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        
        <div class="top-bar">
            <h1>Cashier & Staff Billing Department</h1>
            <span style="color: #64748b; font-size: 0.9rem;">Station: <strong><?= $departmentName ?></strong></span>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- STATS -->
        <div class="stats-row">
            <div class="stat-box">
                <h4>Pending Unpaid Bills</h4>
                <p class="val" style="color: #dc2626;">UGX <?= number_format($totalUnpaid) ?></p>
            </div>
            <div class="stat-box">
                <h4>Revenue Collected Today</h4>
                <p class="val" style="color: #16a34a;">UGX <?= number_format($totalCollectedToday) ?></p>
            </div>
        </div>

        <!-- DIRECT COUNTER BILLING & RECEIPT CREATION FORM -->
        <div class="card" style="border-left: 4px solid #16a34a;">
            <h3>🧾 Direct Counter Billing & Receipt Generation (Database Patient Lookup)</h3>
            <p style="font-size: 0.9rem; color: #64748b;">Select a registered patient from the database, choose medicine stock, collect payment, and issue official receipts directly.</p>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_counter_bill">
                <div class="form-grid">
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
                        <label>Select Medicine & Price</label>
                        <select name="medication_id" id="medicationSelect" class="form-control" onchange="updatePrice()" required>
                            <option value="">-- Choose Medicine --</option>
                            <?php foreach ($medicines as $m): ?>
                                <option value="<?= $m['medicine_id'] ?>" data-price="<?= $m['unit_price'] ?>" data-stock="<?= $m['stock_quantity'] ?>">
                                    <?= htmlspecialchars($m['medicine_name'], ENT_QUOTES, 'UTF-8') ?> (Stock: <?= $m['stock_quantity'] ?> | UGX <?= number_format((float)$m['unit_price']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Quantity</label>
                        <input type="number" name="quantity" id="qtyInput" class="form-control" min="1" value="1" oninput="calculateTotal()" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Amount Tendered (UGX)</label>
                        <input type="number" step="0.01" name="amount_paid" id="amountPaidInput" class="form-control" placeholder="Total Amount" required>
                    </div>
                    <div>
                        <button type="submit" class="btn-pay" style="padding: 10px 20px; height: 42px;">Create Bill</button>
                    </div>
                </div>
                <div style="margin-top: 15px; font-size: 0.95rem; font-weight: 600; color: #1e3a8a;">
                    Calculated Total Due: UGX <span id="displayTotal">0</span>
                </div>
            </form>
        </div>

        <!-- BILLS TABLE & LIVE SEARCH -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid var(--border); padding-bottom: 10px;">
                <h3 style="margin: 0; border: none; padding: 0;">Patient Prescription & Service Billing Queue</h3>
                <div>
                    <input type="text" id="billingSearchInput" onkeyup="filterBillingTable()" placeholder="🔍 Search Patient Name or Details..." class="form-control" style="width: 280px; padding: 8px 12px; font-size: 0.85rem;">
                </div>
            </div>

            <table id="billingTable">
                <thead>
                    <tr>
                        <th>Bill ID</th>
                        <th>Rx ID</th>
                        <th>Patient Name</th>
                        <th>Item / Medication Description</th>
                        <th>Amount Due (UGX)</th>
                        <th>Cashier</th>
                        <th>Status</th>
                        <th>Date / Time</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bills as $b): ?>
                        <tr>
                            <td>#BIL-<?= htmlspecialchars((string)($b['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>#RX-<?= htmlspecialchars((string)($b['prescription_id'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($b['patient_name'] ?? 'Walk-in Patient', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($b['item_description'] ?? 'General Consultation / Pharmacy', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><strong><?= number_format((float)($b['amount_due'] ?? 0)) ?></strong></td>
                            <td><?= htmlspecialchars($b['cashier_name'] ?? 'Pending', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php $status = $b['status'] ?? 'Unpaid'; ?>
                                <span class="badge <?= strtolower($status) ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td><?= htmlspecialchars($b['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($status === 'Unpaid'): ?>
                                    <button onclick="openPaymentModal(<?= $b['id'] ?>, '<?= htmlspecialchars($b['patient_name'], ENT_QUOTES, 'UTF-8') ?>', <?= $b['amount_due'] ?>)" class="btn-pay">💳 Accept Payment</button>
                                <?php else: ?>
                                    <button onclick="printReceipt('BIL-<?= $b['id'] ?>', '<?= htmlspecialchars($b['patient_name'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($b['item_description'], ENT_QUOTES, 'UTF-8') ?>', <?= $b['amount_due'] ?>)" class="btn-print">🖨️ Print Receipt</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- PAYMENT MODAL -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <h3>Process Patient Payment</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="process_payment">
                <input type="hidden" name="bill_id" id="modalBillId">
                
                <div class="form-group">
                    <label>Patient Name</label>
                    <input type="text" id="modalPatientName" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>Amount Due (UGX)</label>
                    <input type="text" id="modalAmountDueDisplay" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>Amount Tendered / Paid (UGX)</label>
                    <input type="number" step="0.01" name="amount_paid" id="modalAmountPaid" class="form-control" required>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="closePaymentModal()" style="background: #cbd5e1; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer;">Cancel</button>
                    <button type="submit" class="btn-pay" style="padding: 10px 20px;">Confirm & Print Receipt</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        function filterBillingTable() {
            const input = document.getElementById('billingSearchInput');
            const filter = input.value.toLowerCase().trim();
            const table = document.getElementById('billingTable');
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

        function updatePrice() {
            calculateTotal();
        }

        function calculateTotal() {
            const select = document.getElementById('medicationSelect');
            const qty = document.getElementById('qtyInput').value || 1;
            const selectedOption = select.options[select.selectedIndex];
            const price = selectedOption.getAttribute('data-price') || 0;
            
            const total = price * qty;
            document.getElementById('displayTotal').innerText = Number(total).toLocaleString();
            document.getElementById('amountPaidInput').value = total;
        }

        function openPaymentModal(billId, patientName, amountDue) {
            document.getElementById('modalBillId').value = billId;
            document.getElementById('modalPatientName').value = patientName;
            document.getElementById('modalAmountDueDisplay').value = Number(amountDue).toLocaleString();
            document.getElementById('modalAmountPaid').value = amountDue;
            document.getElementById('paymentModal').style.display = 'flex';
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }

        function printReceipt(billId, patientName, description, amount) {
            const cashierUsername = "<?= $username ?>";
            const receiptWindow = window.open('', '_blank', 'width=400,height=600');
            receiptWindow.document.write(`
                <html>
                <head>
                    <title>Official Payment Receipt - Kibiri Hospital</title>
                    <style>
                        body { font-family: monospace; padding: 20px; font-size: 13px; color: #000; }
                        .center { text-align: center; }
                        .line { border-bottom: 1px dashed #000; margin: 10px 0; }
                    </style>
                </head>
                <body>
                    <div class="center">
                        <strong>KIBIRI HOSPITAL HMIS</strong><br>
                        Cashier & Billing Department<br>
                        Official Receipt
                    </div>
                    <div class="line"></div>
                    <p><strong>Receipt ID:</strong> REC-${billId}</p>
                    <p><strong>Date/Time:</strong> ${new Date().toLocaleString()}</p>
                    <p><strong>Patient:</strong> ${patientName}</p>
                    <p><strong>Cashier:</strong> @${cashierUsername}</p>
                    <div class="line"></div>
                    <p><strong>Item / Drug Details:</strong><br>${description}</p>
                    <p><strong>Total Paid:</strong> UGX ${Number(amount).toLocaleString()}</p>
                    <div class="line"></div>
                    <p class="center">Thank you!<br>Status: PAID & DISPENSED</p>
                </body>
                </html>
            `);
            receiptWindow.document.close();
            receiptWindow.focus();
            setTimeout(() => { receiptWindow.print(); }, 500);
        }
    </script>
</body>
</html>