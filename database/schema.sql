-- =====================================================================
-- CLINIC MANAGEMENT SYSTEM — MySQL/MariaDB Schema
-- Engine: InnoDB | Charset: utf8mb4 | Fully database-driven
-- =====================================================================

CREATE DATABASE IF NOT EXISTS clinic_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE clinic_db;

-- ---------------------------------------------------------------------
-- CORE MASTER DATA
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS branches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_code VARCHAR(20) NOT NULL,
  branch_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(100) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=active 0=inactive',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branch_code (branch_code),
  KEY idx_branch_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=built-in role, cannot delete',
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  permission_name VARCHAR(150) NOT NULL,
  permission_key VARCHAR(100) NOT NULL,
  module VARCHAR(60) NOT NULL,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permission_key (permission_key),
  KEY idx_permission_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_permission (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(100) NOT NULL,
  setting_value TEXT NULL,
  setting_type VARCHAR(20) NOT NULL DEFAULT 'text' COMMENT 'text|number|textarea|image|password',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- USERS & HR
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id INT UNSIGNED NULL COMMENT 'NULL allowed for super admins (all-branch access)',
  role_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(30) NULL,
  gender ENUM('Male','Female','Other') NULL,
  address VARCHAR(255) NULL,
  password VARCHAR(255) NOT NULL,
  profile_image VARCHAR(255) NULL,
  see_all_branches TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Overrides branch scoping',
  status TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username),
  KEY idx_users_branch (branch_id),
  KEY idx_users_role (role_id),
  KEY idx_users_status (status),
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(60) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempt_user_ip (username, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  department_name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  branch_id INT UNSIGNED NULL COMMENT 'NULL = shared across branches',
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dept_branch (branch_id),
  CONSTRAINT fk_dept_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS specializations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_specialization_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_code VARCHAR(30) NULL,
  user_id INT UNSIGNED NULL COMMENT 'Linked login account, optional',
  full_name VARCHAR(150) NOT NULL,
  gender ENUM('Male','Female','Other') NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  position VARCHAR(120) NULL,
  department_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  joining_date DATE NULL,
  salary DECIMAL(12,2) NULL,
  address VARCHAR(255) NULL,
  photo VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_code (staff_code),
  KEY idx_staff_branch (branch_id),
  KEY idx_staff_department (department_id),
  KEY idx_staff_user (user_id),
  CONSTRAINT fk_staff_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_staff_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doctors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL COMMENT 'Login account of the doctor',
  doctor_code VARCHAR(30) NULL,
  full_name VARCHAR(150) NOT NULL,
  specialization_id INT UNSIGNED NULL,
  license_number VARCHAR(80) NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  consultation_fee DECIMAL(12,2) NULL DEFAULT 0.00,
  branch_id INT UNSIGNED NULL,
  photo VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doctor_code (doctor_code),
  KEY idx_doctor_branch (branch_id),
  KEY idx_doctor_specialization (specialization_id),
  KEY idx_doctor_user (user_id),
  CONSTRAINT fk_doc_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_specialization FOREIGN KEY (specialization_id) REFERENCES specializations (id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doctor_departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  doctor_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doctor_department (doctor_id, department_id),
  CONSTRAINT fk_dd_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id) ON DELETE CASCADE,
  CONSTRAINT fk_dd_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PATIENTS
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS insurance_companies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_code VARCHAR(30) NULL,
  full_name VARCHAR(150) NOT NULL,
  gender ENUM('Male','Female','Other') NULL,
  date_of_birth DATE NULL,
  age INT UNSIGNED NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  emergency_contact VARCHAR(150) NULL,
  emergency_phone VARCHAR(30) NULL,
  blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NULL,
  marital_status VARCHAR(30) NULL,
  occupation VARCHAR(120) NULL,
  nationality VARCHAR(80) NULL,
  photo VARCHAR(255) NULL,
  branch_id INT UNSIGNED NULL,
  insurance_company_id INT UNSIGNED NULL,
  insurance_number VARCHAR(80) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_patient_code (patient_code),
  KEY idx_patient_branch (branch_id),
  KEY idx_patient_phone (phone),
  KEY idx_patient_name (full_name),
  KEY idx_patient_insurance (insurance_company_id),
  CONSTRAINT fk_patient_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_patient_insurance FOREIGN KEY (insurance_company_id) REFERENCES insurance_companies (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- APPOINTMENTS & QUEUE
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS appointment_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_statuses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  status_name VARCHAR(50) NOT NULL,
  badge_class VARCHAR(30) NOT NULL DEFAULT 'secondary' COMMENT 'Bootstrap color for badge',
  is_final TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=terminal status (completed/cancelled)',
  sort_order INT NOT NULL DEFAULT 0,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_appt_status_name (status_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_code VARCHAR(30) NULL,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  appointment_date DATE NOT NULL,
  appointment_time TIME NOT NULL,
  appointment_type_id INT UNSIGNED NULL,
  reason VARCHAR(255) NULL,
  status_id INT UNSIGNED NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_appointment_code (appointment_code),
  KEY idx_appt_patient (patient_id),
  KEY idx_appt_doctor (doctor_id),
  KEY idx_appt_branch (branch_id),
  KEY idx_appt_date (appointment_date),
  KEY idx_appt_status (status_id),
  CONSTRAINT fk_appt_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id),
  CONSTRAINT fk_appt_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL,
  CONSTRAINT fk_appt_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_appt_type FOREIGN KEY (appointment_type_id) REFERENCES appointment_types (id) ON DELETE SET NULL,
  CONSTRAINT fk_appt_status FOREIGN KEY (status_id) REFERENCES appointment_statuses (id) ON DELETE SET NULL,
  CONSTRAINT fk_appt_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_code VARCHAR(20) NULL,
  appointment_id INT UNSIGNED NULL,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  queue_date DATE NOT NULL,
  status ENUM('waiting','called','in_room','skipped','completed') NOT NULL DEFAULT 'waiting',
  called_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_queue_code (queue_code),
  KEY idx_queue_date (queue_date),
  KEY idx_queue_patient (patient_id),
  KEY idx_queue_doctor (doctor_id),
  CONSTRAINT fk_queue_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL,
  CONSTRAINT fk_queue_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
  CONSTRAINT fk_queue_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id) ON DELETE SET NULL,
  CONSTRAINT fk_queue_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_queue_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- MEDICAL RECORDS
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS diagnoses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  diagnosis_code VARCHAR(30) NULL,
  diagnosis_name VARCHAR(180) NOT NULL,
  description VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_diagnosis_code (diagnosis_code),
  KEY idx_diagnosis_name (diagnosis_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  visit_code VARCHAR(30) NULL,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  appointment_id INT UNSIGNED NULL,
  visit_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_visit_code (visit_code),
  KEY idx_visit_patient (patient_id),
  KEY idx_visit_doctor (doctor_id),
  CONSTRAINT fk_visit_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
  CONSTRAINT fk_visit_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id) ON DELETE SET NULL,
  CONSTRAINT fk_visit_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_visit_appointment FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL,
  CONSTRAINT fk_visit_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS medical_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  visit_id INT UNSIGNED NULL,
  chief_complaint TEXT NULL,
  history TEXT NULL,
  examination TEXT NULL,
  diagnosis_id INT UNSIGNED NULL,
  treatment TEXT NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mr_patient (patient_id),
  KEY idx_mr_doctor (doctor_id),
  KEY idx_mr_visit (visit_id),
  CONSTRAINT fk_mr_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
  CONSTRAINT fk_mr_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id) ON DELETE SET NULL,
  CONSTRAINT fk_mr_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_mr_visit FOREIGN KEY (visit_id) REFERENCES visits (id) ON DELETE SET NULL,
  CONSTRAINT fk_mr_diagnosis FOREIGN KEY (diagnosis_id) REFERENCES diagnoses (id) ON DELETE SET NULL,
  CONSTRAINT fk_mr_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_vitals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id INT UNSIGNED NOT NULL,
  visit_id INT UNSIGNED NULL,
  temperature DECIMAL(4,1) NULL,
  weight DECIMAL(5,2) NULL,
  height DECIMAL(5,2) NULL,
  blood_pressure VARCHAR(20) NULL,
  pulse VARCHAR(20) NULL,
  recorded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vitals_patient (patient_id),
  CONSTRAINT fk_vitals_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
  CONSTRAINT fk_vitals_visit FOREIGN KEY (visit_id) REFERENCES visits (id) ON DELETE SET NULL,
  CONSTRAINT fk_vitals_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PHARMACY
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS medicine_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS medicines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  medicine_code VARCHAR(30) NULL,
  medicine_name VARCHAR(150) NOT NULL,
  generic_name VARCHAR(150) NULL,
  category_id INT UNSIGNED NULL,
  unit VARCHAR(40) NULL,
  purchase_price DECIMAL(12,2) NULL DEFAULT 0.00,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  stock_quantity INT NOT NULL DEFAULT 0,
  minimum_stock INT NOT NULL DEFAULT 10,
  expiry_date DATE NULL,
  supplier_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_medicine_code (medicine_code),
  KEY idx_medicine_branch (branch_id),
  KEY idx_medicine_category (category_id),
  KEY idx_medicine_expiry (expiry_date),
  KEY idx_medicine_name (medicine_name),
  CONSTRAINT fk_med_category FOREIGN KEY (category_id) REFERENCES medicine_categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_med_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
  CONSTRAINT fk_med_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prescriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  prescription_code VARCHAR(30) NULL,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  visit_id INT UNSIGNED NULL,
  notes TEXT NULL,
  status ENUM('pending','dispensed','cancelled') NOT NULL DEFAULT 'pending',
  dispensed_by INT UNSIGNED NULL,
  dispensed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prescription_code (prescription_code),
  KEY idx_rx_patient (patient_id),
  KEY idx_rx_doctor (doctor_id),
  KEY idx_rx_visit (visit_id),
  KEY idx_rx_status (status),
  CONSTRAINT fk_rx_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
  CONSTRAINT fk_rx_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id) ON DELETE SET NULL,
  CONSTRAINT fk_rx_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_rx_visit FOREIGN KEY (visit_id) REFERENCES visits (id) ON DELETE SET NULL,
  CONSTRAINT fk_rx_dispensed_by FOREIGN KEY (dispensed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prescription_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  prescription_id INT UNSIGNED NOT NULL,
  medicine_id INT UNSIGNED NULL,
  medicine_name_free VARCHAR(150) NULL COMMENT 'For non-stock items',
  dosage VARCHAR(80) NULL,
  frequency VARCHAR(80) NULL,
  duration VARCHAR(80) NULL,
  quantity INT NOT NULL DEFAULT 1,
  instructions VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_rx_item_prescription (prescription_id),
  KEY idx_rx_item_medicine (medicine_id),
  CONSTRAINT fk_rx_item_rx FOREIGN KEY (prescription_id) REFERENCES prescriptions (id) ON DELETE CASCADE,
  CONSTRAINT fk_rx_item_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (pharmacy_sales & pharmacy_sale_items are defined after invoices below due to FK dependency)

-- ---------------------------------------------------------------------
-- LABORATORY
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS lab_test_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS laboratory_tests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  test_code VARCHAR(30) NULL,
  test_name VARCHAR(150) NOT NULL,
  category_id INT UNSIGNED NULL,
  normal_range VARCHAR(120) NULL,
  unit VARCHAR(60) NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_test_code (test_code),
  KEY idx_test_category (category_id),
  CONSTRAINT fk_test_category FOREIGN KEY (category_id) REFERENCES lab_test_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_code VARCHAR(30) NULL,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  visit_id INT UNSIGNED NULL,
  status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lab_order_code (order_code),
  KEY idx_lab_patient (patient_id),
  KEY idx_lab_doctor (doctor_id),
  KEY idx_lab_branch (branch_id),
  KEY idx_lab_status (status),
  CONSTRAINT fk_lab_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
  CONSTRAINT fk_lab_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id) ON DELETE SET NULL,
  CONSTRAINT fk_lab_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_lab_visit FOREIGN KEY (visit_id) REFERENCES visits (id) ON DELETE SET NULL,
  CONSTRAINT fk_lab_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_order_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lab_order_id INT UNSIGNED NOT NULL,
  test_id INT UNSIGNED NOT NULL,
  result VARCHAR(255) NULL,
  normal_range VARCHAR(120) NULL,
  unit VARCHAR(60) NULL,
  remarks VARCHAR(255) NULL,
  status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
  entered_by INT UNSIGNED NULL,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_lab_item_order (lab_order_id),
  KEY idx_lab_item_test (test_id),
  CONSTRAINT fk_lab_item_order FOREIGN KEY (lab_order_id) REFERENCES lab_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_lab_item_test FOREIGN KEY (test_id) REFERENCES laboratory_tests (id),
  CONSTRAINT fk_lab_item_user FOREIGN KEY (entered_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- BILLING & FINANCE
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS services (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_code VARCHAR(30) NULL,
  service_name VARCHAR(150) NOT NULL,
  department_id INT UNSIGNED NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  description VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_code (service_code),
  KEY idx_service_department (department_id),
  CONSTRAINT fk_service_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_methods (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  method_name VARCHAR(100) NOT NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_method_name (method_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_number VARCHAR(30) NULL,
  patient_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  visit_id INT UNSIGNED NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_number (invoice_number),
  KEY idx_invoice_patient (patient_id),
  KEY idx_invoice_branch (branch_id),
  KEY idx_invoice_status (status),
  KEY idx_invoice_created (created_at),
  CONSTRAINT fk_invoice_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_invoice_visit FOREIGN KEY (visit_id) REFERENCES visits (id) ON DELETE SET NULL,
  CONSTRAINT fk_invoice_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NULL,
  description VARCHAR(255) NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_invoice_item_invoice (invoice_id),
  KEY idx_invoice_item_service (service_id),
  CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
  CONSTRAINT fk_item_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pharmacy_sales (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_code VARCHAR(30) NULL,
  prescription_id INT UNSIGNED NULL,
  patient_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  invoice_id INT UNSIGNED NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  sold_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sale_code (sale_code),
  KEY idx_sale_branch (branch_id),
  KEY idx_sale_created (created_at),
  CONSTRAINT fk_sale_rx FOREIGN KEY (prescription_id) REFERENCES prescriptions (id) ON DELETE SET NULL,
  CONSTRAINT fk_sale_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE SET NULL,
  CONSTRAINT fk_sale_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_sale_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE SET NULL,
  CONSTRAINT fk_sale_user FOREIGN KEY (sold_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pharmacy_sale_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id INT UNSIGNED NOT NULL,
  medicine_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_sale_item_sale (sale_id),
  KEY idx_sale_item_medicine (medicine_id),
  CONSTRAINT fk_sale_item_sale FOREIGN KEY (sale_id) REFERENCES pharmacy_sales (id) ON DELETE CASCADE,
  CONSTRAINT fk_sale_item_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_code VARCHAR(30) NULL,
  invoice_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_method_id INT UNSIGNED NULL,
  reference_number VARCHAR(80) NULL,
  paid_by VARCHAR(150) NULL,
  payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_code (payment_code),
  KEY idx_payment_invoice (invoice_id),
  KEY idx_payment_branch (branch_id),
  KEY idx_payment_date (payment_date),
  CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE SET NULL,
  CONSTRAINT fk_payment_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_payment_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE SET NULL,
  CONSTRAINT fk_payment_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS insurance_claims (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_code VARCHAR(30) NULL,
  invoice_id INT UNSIGNED NOT NULL,
  insurance_company_id INT UNSIGNED NOT NULL,
  claimed_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  approved_amount DECIMAL(12,2) NULL,
  status ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_claim_code (claim_code),
  KEY idx_claim_invoice (invoice_id),
  KEY idx_claim_company (insurance_company_id),
  CONSTRAINT fk_claim_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_company FOREIGN KEY (insurance_company_id) REFERENCES insurance_companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  expense_date DATE NOT NULL,
  description VARCHAR(255) NULL,
  payment_method_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_expense_branch (branch_id),
  KEY idx_expense_category (category_id),
  KEY idx_expense_date (expense_date),
  CONSTRAINT fk_expense_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_expense_category FOREIGN KEY (category_id) REFERENCES expense_categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_expense_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE SET NULL,
  CONSTRAINT fk_expense_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- NOTIFICATIONS, AUDIT & SUPPORT
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL COMMENT 'NULL = all users with matching branch/permission',
  branch_id INT UNSIGNED NULL COMMENT 'NULL = all branches',
  role_id INT UNSIGNED NULL COMMENT 'NULL = all roles',
  permission_key VARCHAR(100) NULL COMMENT 'Only notify users holding this permission',
  title VARCHAR(150) NOT NULL,
  message TEXT NULL,
  notification_type VARCHAR(20) NOT NULL DEFAULT 'info' COMMENT 'success|info|warning|danger|appointment|laboratory|pharmacy|finance|system',
  reference_type VARCHAR(40) NULL,
  reference_id INT UNSIGNED NULL,
  url VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, is_read),
  KEY idx_notif_branch (branch_id),
  KEY idx_notif_role (role_id),
  KEY idx_notif_created (created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_reads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  notification_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notif_read (notification_id, user_id),
  CONSTRAINT fk_nread_notif FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE,
  CONSTRAINT fk_nread_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL COMMENT 'login|logout|create|update|delete|payment|...',
  module VARCHAR(60) NULL,
  record_id INT UNSIGNED NULL,
  description VARCHAR(500) NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_branch (branch_id),
  KEY idx_audit_module (module),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SEED DATA
-- =====================================================================

-- Permissions (module, name, key) -------------------------------------
INSERT INTO permissions (permission_name, permission_key, module, description) VALUES
('View Dashboard','dashboard.view','Dashboard','Access the dashboard'),
('View Patients','patients.view','Patients','View patient list & profiles'),
('Create Patients','patients.create','Patients','Register new patients'),
('Edit Patients','patients.edit','Patients','Edit patient records'),
('Delete Patients','patients.delete','Patients','Delete patient records'),
('View Doctors','doctors.view','Doctors','View doctor list'),
('Create Doctors','doctors.create','Doctors','Add doctors'),
('Edit Doctors','doctors.edit','Doctors','Edit doctors'),
('Delete Doctors','doctors.delete','Doctors','Delete doctors'),
('View Appointments','appointments.view','Appointments','View appointments'),
('Create Appointments','appointments.create','Appointments','Book appointments'),
('Edit Appointments','appointments.edit','Appointments','Edit appointments'),
('Delete Appointments','appointments.delete','Appointments','Cancel/delete appointments'),
('View Queue','queue.view','Queue','View patient queue board'),
('Manage Queue','queue.manage','Queue','Call next, skip, recall, complete'),
('View Medical Records','medical.view','Medical','View EMR'),
('Create Medical Records','medical.create','Medical','Write consultations & records'),
('Edit Medical Records','medical.edit','Medical','Edit records'),
('Delete Medical Records','medical.delete','Medical','Delete records'),
('View Prescriptions','prescriptions.view','Prescriptions','View prescriptions'),
('Create Prescriptions','prescriptions.create','Prescriptions','Write prescriptions'),
('Edit Prescriptions','prescriptions.edit','Prescriptions','Edit prescriptions'),
('Delete Prescriptions','prescriptions.delete','Prescriptions','Delete prescriptions'),
('View Pharmacy','pharmacy.view','Pharmacy','View medicines & stock'),
('Create Pharmacy','pharmacy.create','Pharmacy','Add medicines/categories/suppliers'),
('Edit Pharmacy','pharmacy.edit','Pharmacy','Edit medicines & stock'),
('Delete Pharmacy','pharmacy.delete','Pharmacy','Delete medicines'),
('Dispense Pharmacy','pharmacy.sell','Pharmacy','Dispense prescriptions & sell'),
('View Laboratory','laboratory.view','Laboratory','View lab tests & orders'),
('Create Laboratory','laboratory.create','Laboratory','Order tests & manage test catalog'),
('Edit Laboratory','laboratory.edit','Laboratory','Edit tests & orders'),
('Delete Laboratory','laboratory.delete','Laboratory','Delete tests/orders'),
('Enter Lab Results','laboratory.result','Laboratory','Enter lab results'),
('View Billing','billing.view','Billing','View invoices'),
('Create Billing','billing.create','Billing','Create invoices'),
('Edit Billing','billing.edit','Billing','Edit invoices'),
('Delete Billing','billing.delete','Billing','Cancel invoices'),
('Refund Billing','billing.refund','Billing','Issue refunds'),
('View Payments','payments.view','Payments','View payments'),
('Create Payments','payments.create','Payments','Record payments'),
('Refund Payments','payments.refund','Payments','Refund payments'),
('View Insurance','insurance.view','Insurance','View insurance companies & claims'),
('Create Insurance','insurance.create','Insurance','Manage insurance companies & claims'),
('Edit Insurance','insurance.edit','Insurance','Edit insurance records'),
('Delete Insurance','insurance.delete','Insurance','Delete insurance records'),
('View Expenses','expenses.view','Expenses','View expenses'),
('Create Expenses','expenses.create','Expenses','Record expenses'),
('Edit Expenses','expenses.edit','Expenses','Edit expenses'),
('Delete Expenses','expenses.delete','Expenses','Delete expenses'),
('View Staff','staff.view','HR','View staff list'),
('Create Staff','staff.create','HR','Add staff'),
('Edit Staff','staff.edit','HR','Edit staff'),
('Delete Staff','staff.delete','HR','Delete staff'),
('View Reports','reports.view','Reports','View reports'),
('Export Reports','reports.export','Reports','Export to CSV/Excel/PDF'),
('View Users','users.view','Users','View users'),
('Create Users','users.create','Users','Add users'),
('Edit Users','users.edit','Users','Edit users'),
('Delete Users','users.delete','Users','Delete users'),
('View Branches','branches.view','Branches','View branches'),
('Create Branches','branches.create','Branches','Add branches'),
('Edit Branches','branches.edit','Branches','Edit branches'),
('Delete Branches','branches.delete','Branches','Delete branches'),
('View Roles','roles.view','Roles','View roles'),
('Create Roles','roles.create','Roles','Add roles'),
('Edit Roles','roles.edit','Roles','Edit roles'),
('Assign Permissions','roles.permissions','Roles','Assign permissions to roles'),
('Delete Roles','roles.delete','Roles','Delete roles'),
('View Departments','departments.view','Departments','View departments'),
('Create Departments','departments.create','Departments','Add departments'),
('Edit Departments','departments.edit','Departments','Edit departments'),
('Delete Departments','departments.delete','Departments','Delete departments'),
('View Master Data','masterdata.view','Master Data','View services, types, methods, categories'),
('Manage Master Data','masterdata.manage','Master Data','Add/edit/delete master data'),
('View Specializations','specializations.view','Specializations','View doctor specializations'),
('Create Specializations','specializations.create','Specializations','Add specializations'),
('Edit Specializations','specializations.edit','Specializations','Edit specializations'),
('Delete Specializations','specializations.delete','Specializations','Delete specializations'),
('View Diagnoses','diagnoses.view','Diagnoses','View diagnosis catalog'),
('Create Diagnoses','diagnoses.create','Diagnoses','Add diagnoses'),
('Edit Diagnoses','diagnoses.edit','Diagnoses','Edit diagnoses'),
('Delete Diagnoses','diagnoses.delete','Diagnoses','Delete diagnoses'),
('View Audit Logs','audit.view','Audit','View audit trail'),
('View Settings','settings.view','Settings','View system settings'),
('Manage Settings','settings.manage','Settings','Change system settings'),
('Global Search','search.global','Search','Use global search'),
('Access All Branches','branches.all','Branches','View data across all branches');

-- Roles ----------------------------------------------------------------
INSERT INTO roles (role_name, description, is_system) VALUES
('Super Admin','Full system access, all branches',1),
('Branch Admin','Manages a single branch',1),
('Doctor','Consultations, records, prescriptions, lab orders',1),
('Receptionist','Front desk: patients, appointments, queue, billing',1),
('Pharmacist','Pharmacy stock and dispensing',1),
('Laboratory Technician','Lab orders and results',1),
('Accountant','Billing, payments, expenses, finance reports',1);

-- Super Admin: all permissions ----------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.role_name = 'Super Admin';

-- Branch Admin: everything except user/role/branch admin ----------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.permission_key NOT IN ('users.create','users.delete','roles.create','roles.delete','roles.permissions','branches.create','branches.delete','settings.manage')
WHERE r.role_name = 'Branch Admin';

-- Doctor ---------------------------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.permission_key IN ('dashboard.view','patients.view','doctors.view','appointments.view','appointments.create','appointments.edit','queue.view','queue.manage','medical.view','medical.create','medical.edit','prescriptions.view','prescriptions.create','prescriptions.edit','laboratory.view','laboratory.create','search.global')
WHERE r.role_name = 'Doctor';

-- Receptionist ----------------------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.permission_key IN ('dashboard.view','patients.view','patients.create','patients.edit','doctors.view','appointments.view','appointments.create','appointments.edit','appointments.delete','queue.view','queue.manage','billing.view','billing.create','payments.view','payments.create','search.global')
WHERE r.role_name = 'Receptionist';

-- Pharmacist ------------------------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.permission_key IN ('dashboard.view','patients.view','prescriptions.view','pharmacy.view','pharmacy.create','pharmacy.edit','pharmacy.sell','masterdata.view','masterdata.manage','search.global')
WHERE r.role_name = 'Pharmacist';

-- Laboratory Technician --------------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.permission_key IN ('dashboard.view','patients.view','medical.view','laboratory.view','laboratory.create','laboratory.edit','laboratory.result','masterdata.view','masterdata.manage','search.global')
WHERE r.role_name = 'Laboratory Technician';

-- Accountant --------------------------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.permission_key IN ('dashboard.view','patients.view','billing.view','billing.create','billing.edit','payments.view','payments.create','payments.refund','expenses.view','expenses.create','expenses.edit','expenses.delete','insurance.view','insurance.create','insurance.edit','reports.view','reports.export','masterdata.view','masterdata.manage','search.global')
WHERE r.role_name = 'Accountant';

-- Settings ----------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value, setting_type) VALUES
('clinic_name','MediCare Clinic','text'),
('clinic_logo','','image'),
('clinic_phone','+251 900 000 000','text'),
('clinic_email','info@medicare.example','text'),
('clinic_address','Bole Road, Addis Ababa','textarea'),
('currency','ETB','text'),
('currency_symbol','Br','text'),
('timezone','Africa/Addis_Ababa','text'),
('date_format','d/m/Y','text'),
('invoice_prefix','INV','text'),
('patient_prefix','PAT','text'),
('appointment_prefix','APT','text'),
('prescription_prefix','RX','text'),
('lab_prefix','LAB','text'),
('payment_prefix','PAY','text'),
('queue_prefix','Q','text'),
('doctor_prefix','DR','text'),
('staff_prefix','STF','text'),
('medicine_prefix','MED','text'),
('sale_prefix','SAL','text'),
('claim_prefix','CLM','text'),
('visit_prefix','VIS','text'),
('branch_prefix','BR','text'),
('service_prefix','SRV','text'),
('diagnosis_prefix','D','text'),
('tax_percent','0','number'),
('receipt_footer','Thank you for choosing us. Get well soon!','textarea'),
('session_timeout_minutes','60','number'),
('low_stock_alert_days','30','number');

-- Branches ----------------------------------------------------------------
INSERT INTO branches (branch_code, branch_name, phone, email, address, city) VALUES
('BR-001','Main Branch','+251 911 111 111','main@medicare.example','Bole Road','Addis Ababa');

-- Appointment statuses -----------------------------------------------------
INSERT INTO appointment_statuses (status_name, badge_class, is_final, sort_order) VALUES
('Pending','warning',0,1),
('Confirmed','info',0,2),
('Waiting','primary',0,3),
('In Consultation','primary',0,4),
('Completed','success',1,5),
('Cancelled','danger',1,6),
('No Show','dark',1,7);

-- Appointment types ---------------------------------------------------------
INSERT INTO appointment_types (type_name, description) VALUES
('General Consultation','Regular OPD consultation'),
('Follow-up','Follow-up visit'),
('Emergency','Emergency case'),
('Procedure','Minor procedure'),
('Vaccination','Immunization');

-- Departments ----------------------------------------------------------------
INSERT INTO departments (department_name, description, branch_id) VALUES
('OPD','Outpatient Department',NULL),
('Emergency','Emergency & Casualty',NULL),
('Laboratory','Diagnostic Laboratory',NULL),
('Pharmacy','Pharmacy Unit',NULL),
('Radiology','Imaging & Radiology',NULL),
('Dental','Dental Clinic',NULL);

-- Specializations -------------------------------------------------------------
INSERT INTO specializations (name, description) VALUES
('General Practice','General medicine'),
('Cardiology','Heart & cardiovascular'),
('Pediatrics','Child health'),
('Surgery','General surgery'),
('Internal Medicine','Internal medicine'),
('Obstetrics & Gynecology','Maternal health'),
('Dermatology','Skin conditions');

-- Payment methods --------------------------------------------------------------
INSERT INTO payment_methods (method_name) VALUES
('Cash'),('Bank Transfer'),('Telebirr'),('Card'),('Insurance');

-- Medicine categories -----------------------------------------------------------
INSERT INTO medicine_categories (category_name, description) VALUES
('Analgesic','Pain relievers'),
('Antibiotic','Antibacterial drugs'),
('Antimalarial','Malaria treatment'),
('Antihypertensive','Blood pressure'),
('Supplement','Vitamins & supplements');

-- Lab categories & tests ----------------------------------------------------------
INSERT INTO lab_test_categories (category_name, description) VALUES
('Hematology','Blood tests'),
('Clinical Chemistry','Chemistry panels'),
('Microbiology','Cultures & sensitivities'),
('Serology','Antibody tests'),
('Urinalysis','Urine tests');

INSERT INTO laboratory_tests (test_code, test_name, category_id, normal_range, unit, price) VALUES
('CBC','Complete Blood Count',1,'See report','-',250.00),
('HGB','Hemoglobin',1,'13-17 (M) / 12-16 (F)','g/dL',90.00),
('FBS','Fasting Blood Sugar',2,'70-100','mg/dL',120.00),
('RBS','Random Blood Sugar',2,'70-140','mg/dL',100.00),
('CREA','Creatinine',2,'0.6-1.2','mg/dL',110.00),
('URIC','Uric Acid',2,'3.5-7.2','mg/dL',110.00),
('WIDAL','Widal Test',4,'Negative','-',180.00),
('HIV','HIV Screening',4,'Non-reactive','-',150.00),
('URINE','Urine Analysis',5,'Normal','-',130.00),
('MPS','Malaria (RDT)',4,'Negative','-',120.00);

-- Services ------------------------------------------------------------------------
INSERT INTO services (service_code, service_name, department_id, price, description) VALUES
('SRV-001','General Consultation',1,300.00,'OPD consultation fee'),
('SRV-002','Specialist Consultation',1,600.00,'Specialist consultation'),
('SRV-003','Wound Dressing',2,200.00,'Dressing & minor care'),
('SRV-004','Dental Cleaning',6,450.00,'Scaling & polishing'),
('SRV-005','Nebulization',2,150.00,'Nebulizer session'),
('SRV-006','X-Ray Chest',5,350.00,'Chest radiograph');

-- Diagnoses --------------------------------------------------------------------------
INSERT INTO diagnoses (diagnosis_code, diagnosis_name, description) VALUES
('D-001','Acute Pharyngitis','Throat infection'),
('D-002','Malaria','Plasmodium infection'),
('D-003','Typhoid Fever','Salmonella typhi infection'),
('D-004','Peptic Ulcer Disease','Gastric ulceration'),
('D-005','Upper Respiratory Tract Infection','Common cold / URI'),
('D-006','Type 2 Diabetes Mellitus','Metabolic disorder'),
('D-007','Essential Hypertension','High blood pressure'),
('D-008','Gastroenteritis','Stomach & intestine inflammation'),
('D-009','Urinary Tract Infection','Bacterial UTI'),
('D-010','Iron Deficiency Anemia','Low hemoglobin due to iron deficiency');

-- Sample medicine -----------------------------------------------------------------------
INSERT INTO medicines (medicine_code, medicine_name, generic_name, category_id, unit, purchase_price, selling_price, stock_quantity, minimum_stock, expiry_date, branch_id) VALUES
('MED-000001','Paracetamol 500mg','Acetaminophen',1,'Tablet',0.50,1.00,5000,500,DATE_ADD(CURDATE(), INTERVAL 18 MONTH),1),
('MED-000002','Amoxicillin 250mg','Amoxicillin',2,'Capsule',1.20,2.50,800,100,DATE_ADD(CURDATE(), INTERVAL 12 MONTH),1),
('MED-000003','Coartem 20/120','Artemether/Lumefantrine',3,'Tablet',3.00,6.00,150,60,DATE_ADD(CURDATE(), INTERVAL 20 MONTH),1),
('MED-000004','Amlodipine 5mg','Amlodipine',4,'Tablet',0.80,1.80,40,100,DATE_ADD(CURDATE(), INTERVAL 15 MONTH),1),
('MED-000005','Vitamin C 100mg','Ascorbic Acid',5,'Tablet',0.30,0.80,900,100,DATE_ADD(CURDATE(), INTERVAL 25 DAY),1);

-- Default Super Admin user (password: Admin@123) ---------------------------------------
INSERT INTO users (branch_id, role_id, full_name, username, email, phone, gender, password, see_all_branches)
SELECT b.id, r.id, 'System Administrator','admin','admin@clinic.local','+251 900 000 001','Male',
       '$2y$10$2W1FRa6chmvvF6syseF6VeZePhq/HSRiIGUikeXIpZUyMYXVcIMNq', 1
FROM branches b JOIN roles r
WHERE b.branch_code='BR-001' AND r.role_name='Super Admin';

-- =====================================================================
-- PAYROLL SYSTEM (Super Admin)
-- =====================================================================

CREATE TABLE IF NOT EXISTS payroll_periods (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  period_month CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  branch_id INT UNSIGNED NULL,
  status ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
  total_gross DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total_net DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payroll_period_branch (period_month, branch_id),
  KEY idx_payroll_period_month (period_month),
  KEY idx_payroll_period_branch (branch_id),
  CONSTRAINT fk_payroll_period_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_period_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_period_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  period_id INT UNSIGNED NOT NULL,
  staff_id INT UNSIGNED NOT NULL,
  basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  overtime DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  advance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  other_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  net_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payroll_item (period_id, staff_id),
  KEY idx_payroll_item_staff (staff_id),
  CONSTRAINT fk_payroll_item_period FOREIGN KEY (period_id) REFERENCES payroll_periods (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_item_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payroll permissions ----------------------------------------------------------------
INSERT INTO permissions (permission_name, permission_key, module, description)
SELECT * FROM (
  SELECT 'View Payroll' AS permission_name, 'payroll.view' AS permission_key, 'Payroll' AS module, 'View payroll periods & payslips' AS description
  UNION ALL SELECT 'Manage Payroll', 'payroll.manage', 'Payroll', 'Create/approve/pay payroll periods'
  UNION ALL SELECT 'Delete Payroll', 'payroll.delete', 'Payroll', 'Delete draft payroll periods'
) new_perms
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key IN ('payroll.view','payroll.manage','payroll.delete'));

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.permission_key IN ('payroll.view','payroll.manage','payroll.delete')
WHERE r.role_name = 'Super Admin'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO settings (setting_key, setting_value, setting_type)
SELECT 'payroll_day', '30', 'number' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'payroll_day');

INSERT INTO expense_categories (category_name)
SELECT 'Payroll' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE category_name = 'Payroll');

-- =====================================================================
-- PACK-BASED STOCK + OPD INTERNAL REFERRALS
-- =====================================================================

ALTER TABLE medicines
  ADD COLUMN IF NOT EXISTS pack_size INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Units per pack',
  ADD COLUMN IF NOT EXISTS pack_purchase_price DECIMAL(12,2) NULL COMMENT 'Price per pack (purchase)';

ALTER TABLE pharmacy_sale_items
  ADD COLUMN IF NOT EXISTS unit_price_at_sale DECIMAL(12,2) NULL COMMENT 'Selling price per unit when sold';

CREATE TABLE IF NOT EXISTS opd_sends (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id INT UNSIGNED NOT NULL,
  visit_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  destination ENUM('laboratory','pharmacy','od','doctor') NOT NULL DEFAULT 'od',
  vitals_blood_pressure VARCHAR(20) NULL,
  vitals_temperature DECIMAL(4,1) NULL,
  vitals_weight DECIMAL(5,2) NULL,
  vitals_pulse VARCHAR(20) NULL,
  complaint VARCHAR(255) NULL,
  note VARCHAR(255) NULL,
  status ENUM('pending','received','completed','transferred','cancelled') NOT NULL DEFAULT 'pending',
  lab_order_id INT UNSIGNED NULL,
  prescription_id INT UNSIGNED NULL,
  sent_by INT UNSIGNED NULL,
  received_by INT UNSIGNED NULL,
  transferred_from ENUM('laboratory','pharmacy','od','doctor') NULL,
  transferred_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_opd_send_patient (patient_id),
  KEY idx_opd_send_branch (branch_id),
  KEY idx_opd_send_dest_status (destination, status),
  KEY idx_opd_send_visit (visit_id),
  CONSTRAINT fk_opd_send_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
  CONSTRAINT fk_opd_send_visit FOREIGN KEY (visit_id) REFERENCES visits (id) ON DELETE SET NULL,
  CONSTRAINT fk_opd_send_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
  CONSTRAINT fk_opd_send_lab FOREIGN KEY (lab_order_id) REFERENCES lab_orders (id) ON DELETE SET NULL,
  CONSTRAINT fk_opd_send_rx FOREIGN KEY (prescription_id) REFERENCES prescriptions (id) ON DELETE SET NULL,
  CONSTRAINT fk_opd_send_sender FOREIGN KEY (sent_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_opd_send_receiver FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_name, permission_key, module, description)
SELECT 'Send OPD Referrals', 'opd.send', 'OPD', 'Send patients to OPD, lab or pharmacy'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'opd.send');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.permission_key = 'opd.send'
WHERE r.role_name IN ('Super Admin', 'Branch Admin', 'Receptionist', 'Doctor', 'Laboratory Technician')
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO settings (setting_key, setting_value, setting_type)
SELECT 'default_pack_size', '1', 'number' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'default_pack_size');
