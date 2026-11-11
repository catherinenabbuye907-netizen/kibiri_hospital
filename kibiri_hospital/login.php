<?php
declare(strict_types=1);

require_once __DIR__ . '/configdatabase.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error = '';
$portal = $_GET['portal'] ?? 'patient';
$patientError = $_GET['patient_error'] ?? '';
$patientSuccess = $_GET['patient_success'] ?? '';

// Fetch departments for the staff department dropdown
$departments = [];
$departmentLoadError = false;
try {
    $deptStmt = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name ASC");
    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Unable to load staff departments: ' . $e->getMessage());
    $departmentLoadError = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only process if this is a staff login submission (or check hidden input/form field)
    $emailOrUsername = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $selectedDepartmentId = $_POST['department_id'] ?? '';

    if ($emailOrUsername === '' || $password === '' || $selectedDepartmentId === '') {
        $error = 'Please fill in all fields, including your department.';
    } else {
        // Fetch user, role, and verify department validity
        $stmt = $pdo->prepare(
            "SELECT u.user_id, u.username, u.full_name, u.password_hash,
                    r.role_name
             FROM users u
             JOIN roles r ON u.role_id = r.role_id
             WHERE (u.username = ? OR u.email = ?)
               AND u.status = 'active'"
        );
        $stmt->execute([$emailOrUsername, $emailOrUsername]);
        $user = $stmt->fetch();

        // Optional: verify if the department exists in the database
        $deptCheck = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?");
        $deptCheck->execute([$selectedDepartmentId]);
        $department = $deptCheck->fetch();

        if (
            $user
            && $user['role_name'] !== 'Patient'
            && password_verify($password, $user['password_hash'])
            && $department
        ) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role_name'] = $user['role_name'];
            $_SESSION['role'] = $user['role_name'];
            
            // Store selected department details in session
            $_SESSION['department_id'] = $selectedDepartmentId;
            $_SESSION['department_name'] = $department['department_name'];

            $updateLogin = $pdo->prepare(
                'UPDATE users SET last_login = NOW() WHERE user_id = ?'
            );
            $updateLogin->execute([$user['user_id']]);

            $dashboardByRole = [
                'Receptionist' => 'staff_reception.php',
                'Nurse' => 'staff_reception.php',
                'Doctor' => 'doctor_dashboard.php',
                'Laboratory Staff' => 'staff_laboratory.php',
                'Pharmacist' => 'staff_pharmacy.php',
                'Cashier' => 'staff_billing.php',
                'Administrator' => 'admin_dashboard.php'
            ];
            header('Location: ' . ($dashboardByRole[$user['role_name']] ?? 'index.php'));
            exit;
        }

        $error = 'Invalid username, password, or department selection.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kibiri Hospital HMIS - Unified Portal</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

  <div class="portal-wrapper">
    <!-- LEFT BANNER WITH DYNAMIC BACKGROUND -->
    <div id="portalBanner" class="portal-banner patient-bg">
      <h1 id="bannerTitle">Kibiri Hospital Patient Portal</h1>
      <p id="bannerDesc">Access your personal health records, view lab results, view appointments and connect securely with your care team.</p>
    </div>

    <!-- RIGHT CONTAINER FOR VIEWS -->
    <div class="portal-main">

      <!-- VIEW 1: PATIENT SIGN IN -->
      <div id="patientLoginView" class="view-card<?= $portal === 'register' ? '' : ' active' ?>">
        <div class="form-header">
          <h2>Welcome Back</h2>
          <p>Access your healthcare management dashboard</p>
        </div>

        <?php if ($patientError !== '' && $portal !== 'register'): ?>
            <div class="alert alert-danger" style="color: #842029; background: #f8d7da; padding: 10px; border-radius: 6px; margin-bottom: 15px;" role="alert">
                <?= htmlspecialchars($patientError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($patientSuccess !== ''): ?>
            <div class="alert alert-success" style="color: #0f5132; background: #d1e7dd; padding: 10px; border-radius: 6px; margin-bottom: 15px;" role="status">
                Registration successful. Your Hospital ID is <strong><?= htmlspecialchars($patientSuccess, ENT_QUOTES, 'UTF-8') ?></strong>. You can now sign in.
            </div>
        <?php endif; ?>

        <div class="portal-tabs">
          <button type="button" class="tab-btn" onclick="switchPortalTab('staff')">Staff Portal</button>
          <button type="button" class="tab-btn active" onclick="switchPortalTab('patient')">Patient Portal</button>
        </div>

        <form id="patientLoginForm" method="post" action="patient_login_process.php">
          <div class="form-group">
            <label for="patientIdentifier">Patient Email or ID</label>
            <input type="text" id="patientIdentifier" name="identifier" placeholder="e.g., KBH-2026-0042 or email@gmail.com" required />
          </div>

          <div class="form-group">
            <label for="patientPassword">Password</label>
            <input type="password" id="patientPassword" name="password" placeholder="Enter your password" required />
          </div>

          <button type="submit" class="btn-submit">Sign In as Patient &rarr;</button>
        </form>

        <div class="auth-footer">
          New to Kibiri Hospital? <a href="#" onclick="switchView('registerView')">Register / Patient Intake</a>
        </div>
      </div>

      <!-- VIEW 2: STAFF SIGN IN -->
      <div id="staffLoginView" class="view-card">
        <div class="form-header">
          <h2>Staff Access</h2>
          <p>Institutional login for administrators, medical officers, and staff</p>
        </div>

        <div class="portal-tabs">
          <button type="button" class="tab-btn active" onclick="switchPortalTab('staff')">Staff Portal</button>
          <button type="button" class="tab-btn" onclick="switchPortalTab('patient')">Patient Portal</button>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" style="color: #842029; background: #f8d7da; padding: 10px; border-radius: 6px; margin-bottom: 15px;" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($departmentLoadError): ?>
            <div class="alert alert-danger" style="color: #842029; background: #f8d7da; padding: 10px; border-radius: 6px; margin-bottom: 15px;" role="alert">
                The department list could not be loaded. Please contact the system administrator.
            </div>
        <?php elseif ($departments === []): ?>
            <div class="alert alert-danger" style="color: #842029; background: #f8d7da; padding: 10px; border-radius: 6px; margin-bottom: 15px;" role="alert">
                No departments are configured. Please contact the system administrator.
            </div>
        <?php endif; ?>

        <form id="staffLoginForm" method="post" action="login.php">
          <div class="form-group">
            <label for="staffID">Staff ID / Username or Email</label>
            <input type="text" id="staffID" name="username" placeholder="e.g., admin or staff@kibirihospital.local" required />
          </div>

          <div class="form-group">
            <label for="staffPassword">Access Password</label>
            <input type="password" id="staffPassword" name="password" placeholder="Enter staff password" required />
          </div>

          <!-- Department Selection Dropdown -->
          <div class="form-group">
            <label for="staffDepartment">Select Department / Station</label>
            <select id="staffDepartment" name="department_id" required<?= $departmentLoadError || $departments === [] ? ' disabled' : '' ?>>
              <option value=""><?= $departments === [] ? '-- No departments available --' : '-- Choose Working Department --' ?></option>
              <?php foreach ($departments as $dept): ?>
                <option value="<?= htmlspecialchars((string)$dept['department_id'], ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($dept['department_name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="btn-submit" style="background-color: var(--hospital-dark);">Sign In to Staff HMIS &rarr;</button>
        </form>
      </div>

      <!-- VIEW 3: REGISTRATION / INTAKE FORM -->
      <div id="registerView" class="view-card<?= $portal === 'register' ? ' active' : '' ?>" style="max-width: 600px;">
        <div class="form-header">
          <h2>Patient Intake</h2>
          <p>Complete your registration to get your official Patient ID</p>
        </div>

        <?php if ($patientError !== '' && $portal === 'register'): ?>
            <div class="alert alert-danger" style="color: #842029; background: #f8d7da; padding: 10px; border-radius: 6px; margin-bottom: 15px;" role="alert">
                <?= htmlspecialchars($patientError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form id="patientIntakeForm" method="post" action="patient_register_process.php">
          <fieldset class="form-section">
            <legend>1. Personal Information</legend>
            <div class="form-row">
              <div class="form-group" style="flex:2;">
                <label for="fullName">Full Name *</label>
                <input type="text" id="fullName" name="full_name" placeholder="Sarah Namubiru" required />
              </div>
              <div class="form-group" style="flex:1;">
                <label for="dob">Date of Birth *</label>
                <input type="date" id="dob" name="dob" required />
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="gender">Gender *</label>
                <select id="gender" name="gender" required>
                  <option value="">Select</option>
                  <option value="Female">Female</option>
                  <option value="Male">Male</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              <div class="form-group">
                <label for="phoneNumber">Phone Number *</label>
                <input type="tel" id="phoneNumber" name="phone" placeholder="+256 700 000000" required />
              </div>
            </div>

            <div class="form-group">
              <label for="emailAddress">Email Address *</label>
              <input type="email" id="emailAddress" name="email" placeholder="sarah@gmail.com" required />
            </div>
          </fieldset>

          <fieldset class="form-section">
            <legend>2. Portal Password</legend>
            <div class="form-row">
              <div class="form-group">
                <label for="regPassword">Create Password *</label>
                <input type="password" id="regPassword" name="password" placeholder="Minimum 6 characters" minlength="6" required />
              </div>
              <div class="form-group">
                <label for="confirmPassword">Confirm Password *</label>
                <input type="password" id="confirmPassword" name="password_confirm" placeholder="Repeat password" minlength="6" required />
              </div>
            </div>
          </fieldset>

          <button type="submit" class="btn-submit">Complete Registration &rarr;</button>
        </form>

        <div class="auth-footer">
          Already registered? <a href="#" onclick="switchPortalTab('patient')">Sign In</a>
        </div>
      </div>

    </div>
  </div>

  <!-- JAVASCRIPT SWITCHING & AUTH LOGIC -->
  <script>
    function switchView(viewId) {
      document.querySelectorAll('.view-card').forEach(card => {
        card.classList.remove('active');
      });
      document.getElementById(viewId).classList.add('active');
    }

    function switchPortalTab(portalType) {
      const banner = document.getElementById('portalBanner');
      const bannerTitle = document.getElementById('bannerTitle');
      const bannerDesc = document.getElementById('bannerDesc');

      if (portalType === 'staff') {
        banner.className = "portal-banner staff-bg";
        bannerTitle.innerText = "Kibiri Hospital Staff HMIS";
        bannerDesc.innerText = "Internal clinical management console for patient registration, triage vitals, consultation records, and administrative rights.";
        switchView('staffLoginView');
      } else {
        banner.className = "portal-banner patient-bg";
        bannerTitle.innerText = "Kibiri Hospital Patient Portal";
        bannerDesc.innerText = "Access your personal health records, view lab results, view appointments and connect securely with your care team.";
        switchView('patientLoginView');
      }
    }
  </script>

</body>
</html>