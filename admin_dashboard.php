<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Security check: strict administrator role enforcement
if (!isset($_SESSION['user_id']) || ($_SESSION['role_name'] ?? '') !== 'Administrator') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/configdatabase.php';

$adminName = htmlspecialchars($_SESSION['full_name'] ?? 'System Administrator', ENT_QUOTES, 'UTF-8');
$message = '';
$error = '';

// Handle New Staff Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $roleId = (int)($_POST['role_id'] ?? 0);

    if ($username === '' || $password === '' || $roleId <= 0) {
        $error = 'Username, password, and valid role are required.';
    } else {
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, email, full_name, password_hash, role_id, status) 
                 VALUES (?, ?, ?, ?, ?, 'active')"
            );
            $stmt->execute([$username, $email, $fullName, $passwordHash, $roleId]);
            $message = 'Staff account successfully created and secured!';
        } catch (PDOException $e) {
            $error = 'Database Error (Username or email may already exist): ' . $e->getMessage();
        }
    }
}

// Handle Staff Deletion / Removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_staff_id'])) {
    $deleteId = (int)$_POST['delete_staff_id'];
    
    // Prevent admin from deleting their own active session account
    if ($deleteId === (int)$_SESSION['user_id']) {
        $error = 'Security Error: You cannot delete your own active administrator account.';
    } else {
        try {
            $delStmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $delStmt->execute([$deleteId]);
            $message = 'Staff member successfully removed from the system.';
        } catch (PDOException $e) {
            $error = 'Failed to delete staff member: ' . $e->getMessage();
        }
    }
}

// Fetch statistics and tables data
$staffList = [];
$rolesList = [];
$deptList = [];
$auditLogs = [];

try {
    // 1. Fetch Staff with Roles
    $staffStmt = $pdo->query(
        "SELECT u.user_id, u.username, u.email, u.full_name, u.status, r.role_name 
         FROM users u 
         LEFT JOIN roles r ON u.role_id = r.role_id 
         ORDER BY u.user_id DESC"
    );
    $staffList = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Roles for Form Dropdown
    $rolesStmt = $pdo->query("SELECT * FROM roles ORDER BY role_name ASC");
    $rolesList = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Departments
    $deptStmt = $pdo->query("SELECT * FROM departments ORDER BY department_name ASC");
    $deptList = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch Audit Logs
    $auditStmt = $pdo->query(
        "SELECT a.*, u.username 
         FROM audit_logs a 
         LEFT JOIN users u ON a.user_id = u.user_id 
         ORDER BY a.created_at DESC LIMIT 15"
    );
    $auditLogs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = 'Failed to load dashboard data: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Control Center - Kibiri Hospital HMIS</title>
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
        /* Alerts */
        .alert-success { background: #d1fae5; color: #065f46; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; font-weight: 500; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; font-weight: 500; }
        
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
        /* Cards / Grids */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        @media(max-width: 1024px) {
            .grid-2 { grid-template-columns: 1fr; }
        }
        .card {
            background: var(--card-bg);
            border-radius: 10px;
            border: 1px solid var(--border);
            padding: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        .card h3 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.1rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
        }
        /* Forms */
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: #475569;
        }
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.9rem;
            box-sizing: border-box;
        }
        .btn-primary {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            width: 100%;
            transition: background 0.2s;
        }
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        .btn-danger {
            background-color: #dc2626;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: background 0.2s;
        }
        .btn-danger:hover {
            background-color: #b91c1c;
        }
        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }
        th, td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
        }
        th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
        }
        .badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge.active { background-color: #d1fae5; color: #065f46; }
        .badge.inactive { background-color: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

    <!-- SIDEBAR NAVIGATION -->
    <div class="sidebar">
        <div>
            <h2>Kibiri Hospital HMIS</h2>
            <p>Admin Control Center</p>
            
            <ul class="sidebar-menu">
                <li><a href="admin_dashboard.php" class="active">Overview & Staff</a></li>
                <li><a href="staff_billing.php">Billing Portal</a></li>
                <li><a href="staff_pharmacy.php">Pharmacy Portal</a></li>
                <li><a href="staff_laboratory.php">Laboratory Portal</a></li>
            </ul>
        </div>
        
        <div>
            <div style="margin-bottom: 15px; font-size: 0.9rem;">
                Administrator:<br><strong><?= $adminName ?></strong>
            </div>
            <a href="logout.php" class="logout-btn">Sign Out</a>
        </div>
    </div>

    <!-- MAIN CONTENT AREA -->
    <div class="main-content">
        
        <!-- ADMIN NAVIGATION RETURN BANNER (INJECTED FOR ALL DEPARTMENT VIEWS) -->
        <?php if (basename($_SERVER['PHP_SELF']) !== 'admin_dashboard.php'): ?>
        <div style="background: #065f46; color: white; padding: 12px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <span style="font-size: 0.95rem; font-weight: 500;">🔒 Admin Mode: You are viewing this department dashboard as Administrator.</span>
            <a href="admin_dashboard.php" style="background: white; color: #065f46; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 0.85rem; transition: background 0.2s;">⬅ Back to Admin Dashboard</a>
        </div>
        <?php endif; ?>

        <div class="top-bar">
            <h1>System Administration & Staff Management</h1>
            <div>
                <a href="admin_dashboard.php" style="background-color: #e2e8f0; color: #1e293b; padding: 8px 14px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 600; margin-right: 10px;">🏠 Admin Dashboard</a>
                <span style="color: #64748b; font-size: 0.9rem;">Server Status: <strong style="color: #10b981;">Online</strong></span>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- QUICK STATS -->
        <div class="stats-row">
            <div class="stat-box">
                <h4>Total Staff Accounts</h4>
                <p class="val"><?= count($staffList) ?></p>
            </div>
            <div class="stat-box">
                <h4>System Roles</h4>
                <p class="val"><?= count($rolesList) ?></p>
            </div>
            <div class="stat-box">
                <h4>Hospital Departments</h4>
                <p class="val"><?= count($deptList) ?></p>
            </div>
            <div class="stat-box">
                <h4>Audit Trail Events</h4>
                <p class="val"><?= count($auditLogs) ?></p>
            </div>
        </div>

        <!-- ONBOARDING & STAFF DIRECTORY GRID -->
        <div class="grid-2">
            
            <!-- Add Staff Form -->
            <div class="card">
                <h3>Add New Staff Member</h3>
                <form method="POST" action="admin_dashboard.php">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" required placeholder="e.g. dr_elijah">
                    </div>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" class="form-control" required placeholder="e.g. Dr. Wasswa Elijah">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="elijah@kibirihospital.local">
                    </div>
                    <div class="form-group">
                        <label>Secure Password</label>
                        <input type="password" name="password" class="form-control" required placeholder="••••••••">
                    </div>
                    <div class="form-group">
                        <label>System Role</label>
                        <select name="role_id" class="form-control" required>
                            <option value="">-- Choose Role --</option>
                            <?php foreach ($rolesList as $role): ?>
                                <option value="<?= $role['role_id'] ?>"><?= htmlspecialchars($role['role_name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="add_staff" class="btn-primary">Create Staff Account</button>
                </form>
            </div>

            <!-- Staff Directory Table -->
            <div class="card">
                <h3>Staff Directory & Permissions</h3>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staffList)): ?>
                                <tr><td colspan="6" style="text-align: center; color: #64748b;">No staff accounts registered.</td></tr>
                            <?php else: ?>
                                <?php foreach ($staffList as $staff): ?>
                                    <tr>
                                        <td>#<?= $staff['user_id'] ?></td>
                                        <td><strong><?= htmlspecialchars($staff['full_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                        <td><?= htmlspecialchars($staff['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($staff['role_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?php $status = $staff['status'] ?? 'active'; ?>
                                            <span class="badge <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= ucfirst($status) ?></span>
                                        </td>
                                        <td>
                                            <?php if ((int)$staff['user_id'] !== (int)$_SESSION['user_id']): ?>
                                                <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Are you sure you want to remove <?= htmlspecialchars($staff['full_name'] ?? $staff['username'], ENT_QUOTES, 'UTF-8') ?>?');" style="margin: 0;">
                                                    <input type="hidden" name="delete_staff_id" value="<?= $staff['user_id'] ?>">
                                                    <button type="submit" class="btn-danger">🗑️ Remove</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="font-size: 0.75rem; color: #64748b; font-style: italic;">(Current Admin)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- SYSTEM AUDIT LOGS -->
        <div class="card">
            <h3>System Security & Audit Trail Logs</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Log ID</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>IP Address</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($auditLogs)): ?>
                            <tr><td colspan="6" style="text-align: center; color: #64748b;">No audit log entries recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($auditLogs as $log): ?>
                                <tr>
                                    <td>#<?= $log['audit_id'] ?? '1' ?></td>
                                    <td><strong><?= htmlspecialchars($log['username'] ?? 'System/Guest', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td><span class="badge" style="background: #e2e8f0; color: #334155;"><?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($log['ip_address'] ?? '127.0.0.1', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</body>
</html>