-- =====================================================================
-- Migration: Payroll system + Payroll expense category
-- Idempotent: safe to re-run on an existing clinic_db.
-- Fresh installs get the same objects via database/schema.sql.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Payroll periods (one per month per branch)
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- 2) Payroll line items (one row per employee per period)
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- 3) Payroll expense category (auto-referenced by P&L report)
-- ---------------------------------------------------------------------
INSERT INTO expense_categories (category_name)
SELECT 'Payroll' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE category_name = 'Payroll');

-- ---------------------------------------------------------------------
-- 4) Payroll permissions
-- ---------------------------------------------------------------------
INSERT INTO permissions (permission_name, permission_key, module, description)
SELECT * FROM (
  SELECT 'View Payroll' AS permission_name, 'payroll.view' AS permission_key, 'Payroll' AS module, 'View payroll periods & payslips' AS description
  UNION ALL SELECT 'Manage Payroll', 'payroll.manage', 'Payroll', 'Create/approve/pay payroll periods'
  UNION ALL SELECT 'Delete Payroll', 'payroll.delete', 'Payroll', 'Delete draft payroll periods'
) new_perms
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key IN ('payroll.view','payroll.manage','payroll.delete'));

-- Grant all three to Super Admin
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.permission_key IN ('payroll.view','payroll.manage','payroll.delete')
WHERE r.role_name = 'Super Admin'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- ---------------------------------------------------------------------
-- 5) Payroll day setting
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value, setting_type)
SELECT 'payroll_day', '30', 'number' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'payroll_day');
