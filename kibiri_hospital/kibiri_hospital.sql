
CREATE DATABASE IF NOT EXISTS kibiri_hospital
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE kibiri_hospital;

-- Hospital departments associated with staff roles
CREATE TABLE departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO departments (department_name) VALUES
('Administration'),
('Reception'),
('Clinical'),
('Nursing'),
('Laboratory'),
('Pharmacy'),
('Billing'),
('Management'),
('Patient Services');

-- 1. User roles
CREATE TABLE roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    department_id INT NULL,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
) ENGINE=InnoDB;

-- 2. Permissions
CREATE TABLE permissions (
    permission_id INT AUTO_INCREMENT PRIMARY KEY,
    permission_name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255)
) ENGINE=InnoDB;

-- 3. Role permissions
CREATE TABLE role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(role_id),
    FOREIGN KEY (permission_id)
        REFERENCES permissions(permission_id)
) ENGINE=InnoDB;

-- 4. Users
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive')
        NOT NULL DEFAULT 'active',
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id),
    INDEX idx_user_role (role_id),
    INDEX idx_user_status (status)
) ENGINE=InnoDB;

-- 5. Audit logs
CREATE TABLE audit_logs (
    log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_date (created_at)
) ENGINE=InnoDB;

-- Initial hospital roles
INSERT INTO roles (role_name, description) VALUES
('Administrator', 'Manages the hospital system'),
('Receptionist', 'Patient registration and appointments'),
('Doctor', 'Clinical consultations and prescriptions'),
('Nurse', 'Vital signs and patient care'),
('Laboratory Staff', 'Laboratory operations'),
('Pharmacist', 'Medicine and pharmacy management'),
('Cashier', 'Billing and payments'),
('Hospital Manager', 'Management reports'),
('Patient', 'Patient portal access');

UPDATE roles r
JOIN departments d ON d.department_name = CASE r.role_name
    WHEN 'Administrator' THEN 'Administration'
    WHEN 'Receptionist' THEN 'Reception'
    WHEN 'Doctor' THEN 'Clinical'
    WHEN 'Nurse' THEN 'Nursing'
    WHEN 'Laboratory Staff' THEN 'Laboratory'
    WHEN 'Pharmacist' THEN 'Pharmacy'
    WHEN 'Cashier' THEN 'Billing'
    WHEN 'Hospital Manager' THEN 'Management'
    WHEN 'Patient' THEN 'Patient Services'
END
SET r.department_id = d.department_id;

-- Initial permissions
INSERT INTO permissions (permission_name, description) VALUES
('manage_users', 'Manage user accounts'),
('manage_patients', 'Manage patient records'),
('manage_appointments', 'Manage appointments'),
('manage_clinical', 'Manage clinical records'),
('manage_laboratory', 'Manage laboratory services'),
('manage_pharmacy', 'Manage medicines'),
('manage_billing', 'Manage invoices and payments'),
('view_reports', 'View hospital reports'),
('manage_health_tips', 'Manage health education'),
('view_own_records', 'View own patient records');

-- Initial administrator permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, permission_id FROM permissions;

-- Add this to your kibiri_hospital.sql
CREATE TABLE IF NOT EXISTS patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_number VARCHAR(50) NOT NULL UNIQUE, -- e.g., KBH-2026-0042
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    date_of_birth DATE NULL,
    gender ENUM('Male', 'Female', 'Other') NULL,
    address TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_patient_number (hospital_number),
    INDEX idx_patient_email (email)
) ENGINE=InnoDB;

-- Reception triage observations
CREATE TABLE IF NOT EXISTS triage_records (
    triage_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    receptionist_id INT NULL,
    temperature DECIMAL(4,1) NOT NULL,
    blood_pressure VARCHAR(20) NOT NULL,
    pulse_rate SMALLINT UNSIGNED NOT NULL,
    weight DECIMAL(5,2) NULL,
    height DECIMAL(5,2) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (receptionist_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_triage_patient (patient_id),
    INDEX idx_triage_date (created_at)
) ENGINE=InnoDB;

-- Consultations / Medical History
CREATE TABLE IF NOT EXISTS consultations (
    consultation_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NULL,
    diagnosis TEXT NOT NULL,
    prescription TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Laboratory Results
CREATE TABLE IF NOT EXISTS lab_results (
    lab_id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NULL,
    patient_id INT NOT NULL,
    test_name VARCHAR(150) NOT NULL,
    results TEXT NOT NULL,
    status ENUM('Pending', 'Completed') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointments (
    appointment_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    department VARCHAR(100) NOT NULL,
    preferred_date DATE NOT NULL,
    preferred_time TIME NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('Pending', 'Confirmed', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    INDEX idx_appointment_patient (patient_id),
    INDEX idx_appointment_status (status)
) ENGINE=InnoDB;

-- 1. Medicine Inventory Table
CREATE TABLE IF NOT EXISTS medicines (
    medicine_id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_name VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    expiry_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Prescriptions Table
CREATE TABLE IF NOT EXISTS prescriptions (
    prescription_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NULL,
    medicine_id INT NOT NULL,
    dosage VARCHAR(100) NOT NULL,
    quantity INT NOT NULL,
    status ENUM('Pending', 'Dispensed') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (medicine_id) REFERENCES medicines(medicine_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 1. Invoices Table
CREATE TABLE IF NOT EXISTS invoices (
    invoice_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('Unpaid', 'Partially Paid', 'Paid') NOT NULL DEFAULT 'Unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Invoice Items Table
CREATE TABLE IF NOT EXISTS invoice_items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    service_description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Doctor Lab Test Requests
CREATE TABLE IF NOT EXISTS lab_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    test_type VARCHAR(150) NOT NULL,
    clinical_notes TEXT NULL,
    status ENUM('Pending', 'In Progress', 'Completed') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE lab_results
    ADD CONSTRAINT fk_lab_results_request
    FOREIGN KEY (request_id) REFERENCES lab_requests(request_id)
    ON DELETE SET NULL;

-- 1. Table for Medicine Inventory & Stock Levels
CREATE TABLE IF NOT EXISTS `medicines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL UNIQUE,
  `category` VARCHAR(100) NOT NULL DEFAULT 'General',
  `quantity` INT NOT NULL DEFAULT 0,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `reorder_level` INT NOT NULL DEFAULT 10,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table for Pharmacy Prescriptions Issued by Doctors
CREATE TABLE IF NOT EXISTS `pharmacy_prescriptions` (
  `prescription_id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_name` VARCHAR(150) NOT NULL,
  `medication` VARCHAR(255) NOT NULL,
  `dosage` VARCHAR(100) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Table for Cashier Billing Queue & Payments
CREATE TABLE IF NOT EXISTS `billing_queue` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `prescription_id` INT DEFAULT NULL,
  `patient_name` VARCHAR(150) NOT NULL,
  `item_description` TEXT NOT NULL,
  `amount_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` DECIMAL(12,2) DEFAULT 0.00,
  `status` ENUM('Unpaid', 'Paid', 'Cancelled') NOT NULL DEFAULT 'Unpaid',
  `cashier_name` VARCHAR(150) DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_billing_prescription` FOREIGN KEY (`prescription_id`) REFERENCES `pharmacy_prescriptions` (`prescription_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Seed Data to Test the Tables Immediately
INSERT INTO `medicines` (`name`, `category`, `quantity`, `unit_price`, `reorder_level`) VALUES
('Amoxicillin 500mg', 'Antibiotics', 150, 500.00, 20),
('Paracetamol 500mg', 'Analgesics', 300, 100.00, 50),
('Artemether-Lumefantrine (AL)', 'Antimalarials', 80, 2000.00, 15),
('Ibuprofen 400mg', 'Analgesics', 120, 300.00, 20)
ON DUPLICATE KEY UPDATE `quantity` = `quantity`;
