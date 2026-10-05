-- =====================================================================
-- SCHOOL MODULE (LMS): teachers, classes, subjects, allocations,
-- mark categories, marks, online exams (docx), attendance.
-- Idempotent: safe to run on an existing install.
-- Run: mysql -u <user> -p <db> < database/migration_school.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- Grades / Sections / Subjects
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grades (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL COMMENT 'e.g. Grade 9',
  sort_order INT NOT NULL DEFAULT 0,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grade_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  grade_id INT UNSIGNED NOT NULL,
  name VARCHAR(20) NOT NULL COMMENT 'e.g. A',
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_section_grade (grade_id, name),
  CONSTRAINT fk_section_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(30) NULL,
  name VARCHAR(100) NOT NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subject_code (code),
  UNIQUE KEY uq_subject_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Teachers (optionally linked to a login user with the Teacher role)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_teachers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  teacher_code VARCHAR(30) NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(30) NULL,
  qualification VARCHAR(150) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teacher_code (teacher_code),
  UNIQUE KEY uq_teacher_user (user_id),
  KEY idx_teacher_name (full_name),
  CONSTRAINT fk_teacher_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- School students (separate from clinic patients)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_students (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_code VARCHAR(30) NULL,
  full_name VARCHAR(150) NOT NULL,
  gender ENUM('Male','Female') NULL,
  grade_id INT UNSIGNED NULL,
  section_id INT UNSIGNED NULL,
  guardian_name VARCHAR(150) NULL,
  guardian_phone VARCHAR(30) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_code (student_code),
  KEY idx_student_class (grade_id, section_id),
  CONSTRAINT fk_student_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE SET NULL,
  CONSTRAINT fk_student_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Homeroom: one teacher per grade+section
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_homerooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  teacher_id INT UNSIGNED NOT NULL,
  grade_id INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_homeroom_class (grade_id, section_id),
  CONSTRAINT fk_homeroom_teacher FOREIGN KEY (teacher_id) REFERENCES school_teachers (id) ON DELETE CASCADE,
  CONSTRAINT fk_homeroom_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE CASCADE,
  CONSTRAINT fk_homeroom_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Subject allocation: one teacher per grade+section+subject
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_allocations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  teacher_id INT UNSIGNED NOT NULL,
  grade_id INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_allocation_class_subject (grade_id, section_id, subject_id),
  KEY idx_allocation_teacher (teacher_id),
  CONSTRAINT fk_alloc_teacher FOREIGN KEY (teacher_id) REFERENCES school_teachers (id) ON DELETE CASCADE,
  CONSTRAINT fk_alloc_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE CASCADE,
  CONSTRAINT fk_alloc_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE,
  CONSTRAINT fk_alloc_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Mark categories (school admin defines dynamically; weights per
-- subject must total exactly 100 before marks/exams are accepted)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_mark_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL COMMENT 'e.g. Test 1, Mid Exam, Final',
  max_mark DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Weight out of 100',
  sort_order INT NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_category_subject_name (subject_id, name),
  CONSTRAINT fk_category_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
  CONSTRAINT fk_category_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Marks entered by the allocated subject teacher
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_marks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  entered_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mark_student_category (student_id, category_id),
  KEY idx_mark_subject (subject_id),
  CONSTRAINT fk_mark_student FOREIGN KEY (student_id) REFERENCES school_students (id) ON DELETE CASCADE,
  CONSTRAINT fk_mark_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
  CONSTRAINT fk_mark_category FOREIGN KEY (category_id) REFERENCES school_mark_categories (id) ON DELETE CASCADE,
  CONSTRAINT fk_mark_user FOREIGN KEY (entered_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Online exams uploaded as .docx by the allocated subject teacher
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_exams (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  teacher_id INT UNSIGNED NOT NULL,
  grade_id INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_name VARCHAR(150) NULL,
  file_text MEDIUMTEXT NULL COMMENT 'Extracted text preview (when ZipArchive is available)',
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_exam_class (grade_id, section_id, subject_id),
  CONSTRAINT fk_exam_teacher FOREIGN KEY (teacher_id) REFERENCES school_teachers (id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Daily attendance, taken by the homeroom teacher
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  grade_id INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  att_date DATE NOT NULL,
  status ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
  note VARCHAR(255) NULL,
  taken_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_student_date (student_id, att_date),
  KEY idx_attendance_date (att_date),
  KEY idx_attendance_class (grade_id, section_id),
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES school_students (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_user FOREIGN KEY (taken_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Permissions + Teacher role + grants
-- ---------------------------------------------------------------------
INSERT INTO permissions (permission_name, permission_key, module, description)
SELECT 'View School','school.view','School','View the school module'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'school.view');
INSERT INTO permissions (permission_name, permission_key, module, description)
SELECT 'Manage School','school.manage','School','Manage teachers, students, classes, subjects, homerooms, allocations and mark categories'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'school.manage');
INSERT INTO permissions (permission_name, permission_key, module, description)
SELECT 'Teach','school.teach','School','Enter marks, upload exams and take attendance for allocated classes'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'school.teach');

INSERT INTO roles (role_name, description, is_system, status)
SELECT 'Teacher','School teacher: subject marks, online exams and homeroom attendance',0,1
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Teacher');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.permission_key IN ('school.view','school.manage','school.teach')
WHERE r.role_name = 'Super Admin'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.permission_key = 'school.view'
WHERE r.role_name = 'Branch Admin'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.permission_key IN ('school.view','school.teach')
WHERE r.role_name = 'Teacher'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

-- ---------------------------------------------------------------------
-- Starter seed (skipped when data already exists)
-- ---------------------------------------------------------------------
INSERT INTO grades (name, sort_order)
SELECT CONCAT('Grade ', n.n), n.n FROM (
  SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
  UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12
) n
WHERE NOT EXISTS (SELECT 1 FROM grades LIMIT 1);

INSERT INTO sections (grade_id, name)
SELECT g.id, s.name FROM grades g CROSS JOIN (
  SELECT 'A' name UNION SELECT 'B' UNION SELECT 'C'
) s
WHERE NOT EXISTS (SELECT 1 FROM sections LIMIT 1);

INSERT INTO subjects (code, name)
SELECT t.code, t.name FROM (
  SELECT 'MATH' code, 'Mathematics' name UNION SELECT 'ENG', 'English' UNION SELECT 'AMH', 'Amharic'
  UNION SELECT 'PHY', 'Physics' UNION SELECT 'CHEM', 'Chemistry' UNION SELECT 'BIO', 'Biology'
  UNION SELECT 'HIST', 'History' UNION SELECT 'GEO', 'Geography' UNION SELECT 'CIV', 'Civics'
) t
WHERE NOT EXISTS (SELECT 1 FROM subjects LIMIT 1);

-- ---------------------------------------------------------------------
-- Code generation prefixes (TCH-xxxxxx teachers, STU-xxxxxx students)
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value, setting_type)
SELECT 'teacher_prefix', 'TCH', 'text' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'teacher_prefix');

INSERT INTO settings (setting_key, setting_value, setting_type)
SELECT 'student_prefix', 'STU', 'text' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'student_prefix');
