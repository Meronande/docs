-- =====================================================================
-- Migration: Pack-based stock + OPD internal referrals (sends)
-- Idempotent. Safe to re-run on an existing clinic_db.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Pack-based drug stock: register/add by pack, sell by unit.
--    stock_quantity remains the SOURCE OF TRUTH in individual units;
--    pack_size records how many units come in one pack.
-- ---------------------------------------------------------------------
ALTER TABLE medicines
  ADD COLUMN IF NOT EXISTS pack_size INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Units per pack',
  ADD COLUMN IF NOT EXISTS pack_purchase_price DECIMAL(12,2) NULL COMMENT 'Price per pack (purchase)';

-- ---------------------------------------------------------------------
-- 2) Sale line snapshot: selling price at the time of the sale
-- ---------------------------------------------------------------------
ALTER TABLE pharmacy_sale_items
  ADD COLUMN IF NOT EXISTS unit_price_at_sale DECIMAL(12,2) NULL COMMENT 'Selling price per unit when sold';

-- ---------------------------------------------------------------------
-- 3) OPD internal referrals: patient + vitals + destination + status.
--    visit_id links to the visits table when created from a queue check-in;
--    destination 'od' = Observation Department.
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- 4) OPD permission (view + receive handled by existing module perms;
--    this one guards sending patients around).
-- ---------------------------------------------------------------------
INSERT INTO permissions (permission_name, permission_key, module, description)
SELECT 'Send OPD Referrals', 'opd.send', 'OPD', 'Send patients to OPD, lab or pharmacy'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'opd.send');

-- Everyone who already manages the queue can send referrals.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.permission_key = 'opd.send'
WHERE r.role_name IN ('Super Admin', 'Branch Admin', 'Receptionist', 'Doctor', 'Laboratory Technician')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- ---------------------------------------------------------------------
-- 5) Default pack size setting (new medicines default to 1 unit/pack)
-- ---------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value, setting_type)
SELECT 'default_pack_size', '1', 'number' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = 'default_pack_size');
