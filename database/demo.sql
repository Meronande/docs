-- =====================================================================
-- Demo data for a lively preview.
-- Idempotent: inserts are skipped if doctors already exist (marker).
-- Run with: mysql clinic_db < database/demo.sql
-- =====================================================================
USE clinic_db;

-- Doctor login accounts (password: Admin@123)
INSERT INTO users (branch_id, role_id, full_name, username, email, phone, gender, password)
SELECT NULL AS branch_id, 3 AS role_id, 'Sarah Bekele' AS full_name, 'dr.sarah' AS username,
    'sarah@medicare.example' AS email, '+251 911 100 001' AS phone, 'Female' AS gender,
    '$2y$10$2W1FRa6chmvvF6syseF6VeZePhq/HSRiIGUikeXIpZUyMYXVcIMNq' AS password FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'dr.sarah');
INSERT INTO users (branch_id, role_id, full_name, username, email, phone, gender, password)
SELECT NULL, 3, 'Daniel Girma', 'dr.daniel',
    'daniel@medicare.example', '+251 911 100 002', 'Male',
    '$2y$10$2W1FRa6chmvvF6syseF6VeZePhq/HSRiIGUikeXIpZUyMYXVcIMNq' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'dr.daniel');

-- Stop here if demo doctors already exist (re-run guard)
INSERT INTO doctors (user_id, full_name, specialization_id, license_number, consultation_fee, branch_id)
SELECT u.id, u.full_name, 1, 'LIC-1001', 500.00, 1 FROM users u
WHERE u.username = 'dr.sarah' AND NOT EXISTS (SELECT 1 FROM doctors WHERE user_id = u.id);
INSERT INTO doctors (user_id, full_name, specialization_id, license_number, consultation_fee, branch_id)
SELECT u.id, u.full_name, 2, 'LIC-1002', 800.00, 1 FROM users u
WHERE u.username = 'dr.daniel' AND NOT EXISTS (SELECT 1 FROM doctors WHERE user_id = u.id);
INSERT INTO doctor_departments (doctor_id, department_id)
SELECT d.id, 1 FROM doctors d WHERE d.user_id = (SELECT id FROM users WHERE username='dr.sarah')
AND NOT EXISTS (SELECT 1 FROM doctor_departments dd WHERE dd.doctor_id = d.id);
INSERT INTO doctor_departments (doctor_id, department_id)
SELECT d.id, 3 FROM doctors d WHERE d.user_id = (SELECT id FROM users WHERE username='dr.daniel')
AND NOT EXISTS (SELECT 1 FROM doctor_departments dd WHERE dd.doctor_id = d.id);

-- Insurance companies
INSERT INTO insurance_companies (company_name, phone, email, address)
SELECT 'Nyala Insurance','+251 115 550 001','corporate@nyala.example','Bole, Addis Ababa' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM insurance_companies WHERE company_name='Nyala Insurance');
INSERT INTO insurance_companies (company_name, phone, email, address)
SELECT 'Awash Insurance','+251 115 550 002','groups@awash.example','Kazanchis, Addis Ababa' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM insurance_companies WHERE company_name='Awash Insurance');

-- Patients
INSERT INTO patients (patient_code, full_name, gender, date_of_birth, age, phone, email, address, blood_group, branch_id, insurance_company_id, insurance_number, created_at)
SELECT 'PAT-000001' a,'Abebe Kebede' b,'Male' c,'1990-05-12' d,36 e,'+251 921 000 001' f,'abebe@mail.com' g,'Bole, Addis' h,'O+' i,1 j,NULL k,NULL l, NOW() - INTERVAL 40 DAY m FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE patient_code='PAT-000001');
INSERT INTO patients (patient_code, full_name, gender, date_of_birth, age, phone, email, address, blood_group, branch_id, insurance_company_id, insurance_number, created_at)
SELECT 'PAT-000002','Marta Alemu','Female','1985-11-03',40,'+251 921 000 002','marta@mail.com','Kality, Addis','A+',1,NULL,NULL, NOW() - INTERVAL 35 DAY FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE patient_code='PAT-000002');
INSERT INTO patients (patient_code, full_name, gender, date_of_birth, age, phone, email, address, blood_group, branch_id, insurance_company_id, insurance_number, created_at)
SELECT 'PAT-000003','Yonas Tesfaye','Male','2001-02-18',25,'+251 921 000 003','yonas@mail.com','Megenagna, Addis','B+',2,NULL,NULL, NOW() - INTERVAL 30 DAY FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE patient_code='PAT-000003');
INSERT INTO patients (patient_code, full_name, gender, date_of_birth, age, phone, email, address, blood_group, branch_id, insurance_company_id, insurance_number, created_at)
SELECT 'PAT-000004','Hanna Solomon','Female','2015-07-25',11,'+251 921 000 004',NULL,'Gerji, Addis','AB+',2,1,'INS-778899', NOW() - INTERVAL 20 DAY FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE patient_code='PAT-000004');
INSERT INTO patients (patient_code, full_name, gender, date_of_birth, age, phone, email, address, blood_group, branch_id, insurance_company_id, insurance_number, created_at)
SELECT 'PAT-000005','Kalkidan Fikru','Female','1997-09-30',29,'+251 921 000 005','kalki@mail.com','Sarbet, Addis','O-',3,NULL,NULL, NOW() - INTERVAL 12 DAY FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE patient_code='PAT-000005');
INSERT INTO patients (patient_code, full_name, gender, date_of_birth, age, phone, email, address, blood_group, branch_id, insurance_company_id, insurance_number, created_at)
SELECT 'PAT-000006','Getachew Mulu','Male','1968-01-09',58,'+251 921 000 006',NULL,'Adama','A-',3,1,'INS-112233', NOW() - INTERVAL 5 DAY FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE patient_code='PAT-000006');

-- Expense categories
INSERT INTO expense_categories (category_name, description)
SELECT 'Utilities','Electricity, water, internet' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE category_name='Utilities');
INSERT INTO expense_categories (category_name, description)
SELECT 'Medical Supplies','Consumables and disposables' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE category_name='Medical Supplies');
INSERT INTO expense_categories (category_name, description)
SELECT 'Rent','Facility rent' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE category_name='Rent');
INSERT INTO expense_categories (category_name, description)
SELECT 'Maintenance','Repairs and upkeep' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE category_name='Maintenance');
INSERT INTO expense_categories (category_name, description)
SELECT 'Marketing','Advertising and outreach' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE category_name='Marketing');

-- Staff sample
INSERT INTO staff (staff_code, full_name, gender, phone, position, department_id, branch_id, joining_date, salary)
SELECT 'STF-000001','Selam Tadesse','Female','+251 922 010 101','Receptionist',1,1, CURDATE() - INTERVAL 300 DAY, 8000.00 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM staff WHERE staff_code='STF-000001');
INSERT INTO staff (staff_code, full_name, gender, phone, position, department_id, branch_id, joining_date, salary)
SELECT 'STF-000002','Robel Assefa','Male','+251 922 010 102','Lab Technician',3,1, CURDATE() - INTERVAL 200 DAY, 9500.00 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM staff WHERE staff_code='STF-000002');

-- Resolve ids into variables
SET @sarah = (SELECT id FROM users WHERE username='dr.sarah');
SET @daniel = (SELECT id FROM users WHERE username='dr.daniel');
SET @sarah_doc = (SELECT id FROM doctors WHERE user_id=@sarah);
SET @daniel_doc = (SELECT id FROM doctors WHERE user_id=@daniel);
SET @p1=(SELECT id FROM patients WHERE patient_code='PAT-000001');
SET @p2=(SELECT id FROM patients WHERE patient_code='PAT-000002');
SET @p3=(SELECT id FROM patients WHERE patient_code='PAT-000003');
SET @p4=(SELECT id FROM patients WHERE patient_code='PAT-000004');
SET @p5=(SELECT id FROM patients WHERE patient_code='PAT-000005');
SET @p6=(SELECT id FROM patients WHERE patient_code='PAT-000006');
SET @cbc=(SELECT id FROM laboratory_tests WHERE test_name='Complete Blood Count (CBC)' LIMIT 1);
SET @malaria=(SELECT id FROM laboratory_tests WHERE test_name='Malaria RDT' LIMIT 1);
SET @smear=(SELECT id FROM laboratory_tests WHERE test_name='Blood Smear' LIMIT 1);
SET @nyala=(SELECT id FROM insurance_companies WHERE company_name='Nyala Insurance');
SET @cash=1; SET @card=4;
SET @st_completed=(SELECT id FROM appointment_statuses WHERE status_name='Completed');
SET @st_cancelled=(SELECT id FROM appointment_statuses WHERE status_name='Cancelled');
SET @st_confirmed=(SELECT id FROM appointment_statuses WHERE status_name='Confirmed');
SET @st_pending=(SELECT id FROM appointment_statuses WHERE status_name='Pending');
SET @svc_consult=(SELECT id FROM services WHERE service_name='General Consultation' LIMIT 1);
SET @svc_specialist=(SELECT id FROM services WHERE service_name='Specialist Consultation' LIMIT 1);
SET @svc_cbc=(SELECT id FROM services WHERE service_name LIKE '%Blood Count%' LIMIT 1);

-- Appointments
INSERT INTO appointments (appointment_code, patient_id, doctor_id, branch_id, appointment_date, appointment_time, appointment_type_id, reason, status_id, created_by)
SELECT 'APT-000001' a,@p1 b,@sarah_doc c,1 d, CURDATE() - INTERVAL 6 DAY e,'09:30:00' f,1 g,'Fever and headache' h,@st_completed i,1 j FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM appointments WHERE appointment_code='APT-000001');
INSERT INTO appointments (appointment_code, patient_id, doctor_id, branch_id, appointment_date, appointment_time, appointment_type_id, reason, status_id, created_by)
SELECT 'APT-000002',@p2,@daniel_doc,1, CURDATE() - INTERVAL 4 DAY,'11:00:00',1,'Chest pain on exertion',@st_completed,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM appointments WHERE appointment_code='APT-000002');
INSERT INTO appointments (appointment_code, patient_id, doctor_id, branch_id, appointment_date, appointment_time, appointment_type_id, reason, status_id, created_by)
SELECT 'APT-000003',@p3,@sarah_doc,1, CURDATE() - INTERVAL 2 DAY,'10:00:00',1,'Follow-up',@st_cancelled,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM appointments WHERE appointment_code='APT-000003');
INSERT INTO appointments (appointment_code, patient_id, doctor_id, branch_id, appointment_date, appointment_time, appointment_type_id, reason, status_id, created_by)
SELECT 'APT-000004',@p4,@sarah_doc,2, CURDATE() - INTERVAL 1 DAY,'09:00:00',1,'Child fever',@st_completed,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM appointments WHERE appointment_code='APT-000004');
INSERT INTO appointments (appointment_code, patient_id, doctor_id, branch_id, appointment_date, appointment_time, appointment_type_id, reason, status_id, created_by)
SELECT 'APT-000005',@p5,@daniel_doc,3, CURDATE(),'14:00:00',1,'Persistent cough',@st_confirmed,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM appointments WHERE appointment_code='APT-000005');
INSERT INTO appointments (appointment_code, patient_id, doctor_id, branch_id, appointment_date, appointment_time, appointment_type_id, reason, status_id, created_by)
SELECT 'APT-000006',@p6,@daniel_doc,1, CURDATE(),'16:00:00',1,'Hypertension review',@st_confirmed,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM appointments WHERE appointment_code='APT-000006');
INSERT INTO appointments (appointment_code, patient_id, doctor_id, branch_id, appointment_date, appointment_time, appointment_type_id, reason, status_id, created_by)
SELECT 'APT-000007',@p1,@sarah_doc,1, CURDATE() + INTERVAL 1 DAY,'09:30:00',1,'Lab results review',@st_pending,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM appointments WHERE appointment_code='APT-000007');

-- Visits
INSERT INTO visits (visit_code, patient_id, doctor_id, branch_id, appointment_id, visit_date, status, created_by)
SELECT 'VIS-000001',@p1,@sarah_doc,1,1, NOW() - INTERVAL 6 DAY,'closed',1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM visits WHERE visit_code='VIS-000001');
INSERT INTO visits (visit_code, patient_id, doctor_id, branch_id, appointment_id, visit_date, status, created_by)
SELECT 'VIS-000002',@p2,@daniel_doc,1,2, NOW() - INTERVAL 4 DAY,'closed',1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM visits WHERE visit_code='VIS-000002');
INSERT INTO visits (visit_code, patient_id, doctor_id, branch_id, appointment_id, visit_date, status, created_by)
SELECT 'VIS-000003',@p4,@sarah_doc,2,4, NOW() - INTERVAL 1 DAY,'closed',1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM visits WHERE visit_code='VIS-000003');

-- Medical records
INSERT INTO medical_records (patient_id, doctor_id, branch_id, visit_id, chief_complaint, history, examination, treatment, notes, created_by)
SELECT @p1,@sarah_doc,1,1,'Fever, headache for 3 days','No chronic illness','Temp 38.2C, throat congested','Rest, hydration, paracetamol','Review if fever persists beyond 3 days',@sarah FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM medical_records WHERE visit_id=1);
INSERT INTO medical_records (patient_id, doctor_id, branch_id, visit_id, chief_complaint, history, examination, treatment, notes, created_by)
SELECT @p2,@daniel_doc,1,2,'Chest tightness on exertion','Hypertension x5 years','BP 145/90, clear chest','ECG ordered, lifestyle advice','Follow-up in 2 weeks',@daniel FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM medical_records WHERE visit_id=2);
INSERT INTO medical_records (patient_id, doctor_id, branch_id, visit_id, chief_complaint, history, examination, treatment, notes, created_by)
SELECT @p4,@sarah_doc,2,3,'High fever, no cough','Healthy child','Temp 38.9C','ORS, paracetamol syrup','Return if fever > 48h',@sarah FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM medical_records WHERE visit_id=3);

-- Vitals
INSERT INTO patient_vitals (patient_id, visit_id, temperature, weight, height, blood_pressure, pulse, recorded_by)
SELECT @p1,1,38.2,72.0,175,'120/80','88',1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patient_vitals WHERE visit_id=1);
INSERT INTO patient_vitals (patient_id, visit_id, temperature, weight, height, blood_pressure, pulse, recorded_by)
SELECT @p2,2,37.0,80.5,168,'145/90','92',1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patient_vitals WHERE visit_id=2);
INSERT INTO patient_vitals (patient_id, visit_id, temperature, weight, height, blood_pressure, pulse, recorded_by)
SELECT @p4,3,38.9,24.0,120,'95/60','110',1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patient_vitals WHERE visit_id=3);

-- Lab orders + items
INSERT INTO lab_orders (order_code, patient_id, doctor_id, branch_id, visit_id, status, created_by)
SELECT 'LAB-000001',@p2,@daniel_doc,1,2,'completed',@daniel FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM lab_orders WHERE order_code='LAB-000001');
INSERT INTO lab_orders (order_code, patient_id, doctor_id, branch_id, visit_id, status, created_by)
SELECT 'LAB-000002',@p1,@sarah_doc,1,1,'completed',@sarah FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM lab_orders WHERE order_code='LAB-000002');
INSERT INTO lab_orders (order_code, patient_id, doctor_id, branch_id, visit_id, status, created_by)
SELECT 'LAB-000003',@p4,@sarah_doc,2,3,'pending',@sarah FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM lab_orders WHERE order_code='LAB-000003');
INSERT INTO lab_order_items (lab_order_id, test_id, result, normal_range, unit, remarks, status, entered_by, completed_at)
SELECT 1, @cbc, 'Hemoglobin 13.5 g/dL, WBC 7.2 — within range','4.5-11.0','10^3/uL','Normal profile','completed',@daniel, NOW() - INTERVAL 4 DAY
WHERE NOT EXISTS (SELECT 1 FROM lab_order_items WHERE lab_order_id=1);
INSERT INTO lab_order_items (lab_order_id, test_id, result, normal_range, unit, remarks, status, entered_by, completed_at)
SELECT 2, @malaria, 'Negative','','—','No malaria parasites seen','completed',@sarah, NOW() - INTERVAL 6 DAY
WHERE NOT EXISTS (SELECT 1 FROM lab_order_items WHERE lab_order_id=2);
INSERT INTO lab_order_items (lab_order_id, test_id, result, normal_range, unit, remarks, status, entered_by, completed_at)
SELECT 3, @smear, NULL,'Negative','—',NULL,'pending',NULL,NULL
WHERE NOT EXISTS (SELECT 1 FROM lab_order_items WHERE lab_order_id=3);

-- Prescriptions + items (pending, ready for pharmacy dispensing)
INSERT INTO prescriptions (prescription_code, patient_id, doctor_id, branch_id, visit_id, notes, status)
SELECT 'RX-000001',@p1,@sarah_doc,1,1,'Take after meals','pending' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM prescriptions WHERE prescription_code='RX-000001');
INSERT INTO prescriptions (prescription_code, patient_id, doctor_id, branch_id, visit_id, notes, status)
SELECT 'RX-000002',@p4,@sarah_doc,2,3,'Syrup — shake well','pending' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM prescriptions WHERE prescription_code='RX-000002');
INSERT INTO prescription_items (prescription_id, medicine_id, dosage, frequency, duration, quantity, instructions)
SELECT 1, 1, '1 tablet','3x daily','5 days',15,'After meals'
WHERE NOT EXISTS (SELECT 1 FROM prescription_items WHERE prescription_id=1 AND medicine_id=1);
INSERT INTO prescription_items (prescription_id, medicine_id, dosage, frequency, duration, quantity, instructions)
SELECT 1, 5, '2 puffs','as needed','7 days',1,'For wheezing'
WHERE NOT EXISTS (SELECT 1 FROM prescription_items WHERE prescription_id=1 AND medicine_id=5);
INSERT INTO prescription_items (prescription_id, medicine_id, dosage, frequency, duration, quantity, instructions)
SELECT 2, 1, '5 ml','3x daily','5 days',1,'Pediatric dose'
WHERE NOT EXISTS (SELECT 1 FROM prescription_items WHERE prescription_id=2 AND medicine_id=1);

-- Invoices + items + payments
INSERT INTO invoices (invoice_number, patient_id, branch_id, visit_id, subtotal, discount, tax, total, paid_amount, balance, status, created_by, created_at)
SELECT 'INV-000001',@p1,1,1,500.00,0,0,500.00,500.00,0.00,'paid',1, NOW() - INTERVAL 6 DAY FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM invoices WHERE invoice_number='INV-000001');
INSERT INTO invoices (invoice_number, patient_id, branch_id, visit_id, subtotal, discount, tax, total, paid_amount, balance, status, created_by, created_at)
SELECT 'INV-000002',@p2,1,2,1150.00,0,0,1150.00,1150.00,0.00,'paid',1, NOW() - INTERVAL 4 DAY FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM invoices WHERE invoice_number='INV-000002');
INSERT INTO invoices (invoice_number, patient_id, branch_id, visit_id, subtotal, discount, tax, total, paid_amount, balance, status, created_by, created_at)
SELECT 'INV-000003',@p4,2,3,600.00,0,0,600.00,300.00,300.00,'partial',1, NOW() - INTERVAL 1 DAY FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM invoices WHERE invoice_number='INV-000003');
INSERT INTO invoices (invoice_number, patient_id, branch_id, visit_id, subtotal, discount, tax, total, paid_amount, balance, status, created_by, created_at)
SELECT 'INV-000004',@p6,1,NULL,1900.00,100.00,0,1800.00,0.00,1800.00,'unpaid',1, NOW() - INTERVAL 2 DAY FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM invoices WHERE invoice_number='INV-000004');
INSERT INTO invoices (invoice_number, patient_id, branch_id, visit_id, subtotal, discount, tax, total, paid_amount, balance, status, created_by, created_at)
SELECT 'INV-000005',@p5,3,NULL,500.00,0,0,500.00,0.00,500.00,'unpaid',1, NOW() - INTERVAL 3 DAY FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM invoices WHERE invoice_number='INV-000005');

INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, discount, total)
SELECT i.id, @svc_consult, 'General Consultation',1,500.00,0,500.00 FROM invoices i
WHERE i.invoice_number='INV-000001' AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=i.id);
INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, discount, total)
SELECT i.id, @svc_specialist, 'Specialist Consultation',1,800.00,0,800.00 FROM invoices i
WHERE i.invoice_number='INV-000002' AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=i.id);
INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, discount, total)
SELECT i.id, @svc_cbc, 'Complete Blood Count (CBC)',1,350.00,0,350.00 FROM invoices i
WHERE i.invoice_number='INV-000002' AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=i.id);
INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, discount, total)
SELECT i.id, 6, 'Pediatric Checkup',1,600.00,0,600.00 FROM invoices i
WHERE i.invoice_number='INV-000003' AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=i.id);
INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, discount, total)
SELECT i.id, @svc_specialist, 'Specialist Consultation',1,800.00,0,800.00 FROM invoices i
WHERE i.invoice_number='INV-000004' AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=i.id);
INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, discount, total)
SELECT i.id, 1, 'Blood Pressure Check',2,100.00,0,200.00 FROM invoices i
WHERE i.invoice_number='INV-000004' AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=i.id);
INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, discount, total)
SELECT i.id, 3, 'Wound Dressing',3,300.00,0,900.00 FROM invoices i
WHERE i.invoice_number='INV-000004' AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=i.id);
INSERT INTO invoice_items (invoice_id, service_id, description, quantity, unit_price, discount, total)
SELECT i.id, @svc_consult, 'General Consultation',1,500.00,0,500.00 FROM invoices i
WHERE i.invoice_number='INV-000005' AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE invoice_id=i.id);

INSERT INTO payments (payment_code, invoice_id, patient_id, branch_id, amount, payment_method_id, reference_number, paid_by, payment_date, created_by)
SELECT 'PAY-000001',(SELECT id FROM invoices WHERE invoice_number='INV-000001'),@p1,1,500.00,@cash,'CASH-1001','Abebe Kebede', NOW() - INTERVAL 6 DAY,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM payments WHERE payment_code='PAY-000001');
INSERT INTO payments (payment_code, invoice_id, patient_id, branch_id, amount, payment_method_id, reference_number, paid_by, payment_date, created_by)
SELECT 'PAY-000002',(SELECT id FROM invoices WHERE invoice_number='INV-000002'),@p2,1,1150.00,@card,'POS-8842','Marta Alemu', NOW() - INTERVAL 4 DAY,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM payments WHERE payment_code='PAY-000002');
INSERT INTO payments (payment_code, invoice_id, patient_id, branch_id, amount, payment_method_id, reference_number, paid_by, payment_date, created_by)
SELECT 'PAY-000003',(SELECT id FROM invoices WHERE invoice_number='INV-000003'),@p4,2,300.00,@cash,'CASH-1002','Hanna Solomon', NOW() - INTERVAL 1 DAY,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM payments WHERE payment_code='PAY-000003');

-- Pharmacy sale (direct sale)
INSERT INTO pharmacy_sales (sale_code, patient_id, branch_id, total_amount, sold_by)
SELECT 'SAL-000001',@p1,1,500.00,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM pharmacy_sales WHERE sale_code='SAL-000001');
INSERT INTO pharmacy_sale_items (sale_id, medicine_id, quantity, unit_price, total)
SELECT s.id, 1, 10, 25.00, 250.00 FROM pharmacy_sales s WHERE s.sale_code='SAL-000001'
AND NOT EXISTS (SELECT 1 FROM pharmacy_sale_items WHERE sale_id=s.id AND medicine_id=1);
INSERT INTO pharmacy_sale_items (sale_id, medicine_id, quantity, unit_price, total)
SELECT s.id, 2, 4, 65.00, 260.00 FROM pharmacy_sales s WHERE s.sale_code='SAL-000001'
AND NOT EXISTS (SELECT 1 FROM pharmacy_sale_items WHERE sale_id=s.id AND medicine_id=2);

-- Expenses
INSERT INTO expenses (branch_id, category_id, amount, expense_date, description, payment_method_id, created_by)
SELECT 1,(SELECT id FROM expense_categories WHERE category_name='Utilities'),1450.00, CURDATE() - INTERVAL 20 DAY,'Electricity bill',2,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expenses WHERE description='Electricity bill');
INSERT INTO expenses (branch_id, category_id, amount, expense_date, description, payment_method_id, created_by)
SELECT 1,(SELECT id FROM expense_categories WHERE category_name='Medical Supplies'),3200.00, CURDATE() - INTERVAL 10 DAY,'Gloves, syringes, cotton',1,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expenses WHERE description='Gloves, syringes, cotton');
INSERT INTO expenses (branch_id, category_id, amount, expense_date, description, payment_method_id, created_by)
SELECT 2,(SELECT id FROM expense_categories WHERE category_name='Rent'),25000.00, CURDATE() - INTERVAL 25 DAY,'Monthly rent',2,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expenses WHERE description='Monthly rent');
INSERT INTO expenses (branch_id, category_id, amount, expense_date, description, payment_method_id, created_by)
SELECT 1,(SELECT id FROM expense_categories WHERE category_name='Utilities'),1500.00, CURDATE() - INTERVAL 2 DAY,'Internet & water',2,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expenses WHERE description='Internet & water');
INSERT INTO expenses (branch_id, category_id, amount, expense_date, description, payment_method_id, created_by)
SELECT 3,(SELECT id FROM expense_categories WHERE category_name='Maintenance'),800.00, CURDATE() - INTERVAL 4 DAY,'AC repair',1,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expenses WHERE description='AC repair');
INSERT INTO expenses (branch_id, category_id, amount, expense_date, description, payment_method_id, created_by)
SELECT 1,(SELECT id FROM expense_categories WHERE category_name='Marketing'),2000.00, CURDATE() - INTERVAL 1 DAY,'Radio advertisement',1,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM expenses WHERE description='Radio advertisement');

-- Insurance claim
INSERT INTO insurance_claims (claim_code, invoice_id, insurance_company_id, claimed_amount, approved_amount, status, notes)
SELECT 'CLM-000001',(SELECT id FROM invoices WHERE invoice_number='INV-000003'),@nyala,600.00,NULL,'pending','Pediatric checkup for insured child' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM insurance_claims WHERE claim_code='CLM-000001');

-- Queue entries for today
INSERT INTO queue (queue_code, appointment_id, patient_id, doctor_id, branch_id, queue_date, status, called_at, created_by)
SELECT 'Q-001',5,@p5,@daniel_doc,3,CURDATE(),'called', NOW(),1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM queue WHERE queue_code='Q-001');
INSERT INTO queue (queue_code, appointment_id, patient_id, doctor_id, branch_id, queue_date, status, called_at, created_by)
SELECT 'Q-002',6,@p6,@daniel_doc,1,CURDATE(),'waiting',NULL,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM queue WHERE queue_code='Q-002');

-- Audit + notification samples
INSERT INTO audit_logs (user_id, branch_id, action, module, record_id, description, ip_address)
SELECT 1,NULL,'login','Auth',NULL,'User admin signed in','127.0.0.1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM audit_logs WHERE action='login' AND description='User admin signed in');
INSERT INTO audit_logs (user_id, branch_id, action, module, record_id, description, ip_address)
SELECT 1,1,'create','Invoice',1,'Created invoice INV-000001 for Abebe Kebede','127.0.0.1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM audit_logs WHERE description='Created invoice INV-000001 for Abebe Kebede');
INSERT INTO notifications (user_id, branch_id, permission_key, title, message, notification_type, reference_type, reference_id, url, is_read)
SELECT NULL,1,'payments.view','Payment received','Br 500.00 received for INV-000001 (Abebe Kebede). Invoice fully settled.','finance','payment',1,'/billing/view/1',0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE title='Payment received' AND message LIKE '%INV-000001%');
INSERT INTO notifications (user_id, branch_id, permission_key, title, message, notification_type, reference_type, reference_id, url, is_read)
SELECT NULL,NULL,'insurance.view','New insurance claim','CLM-000001 — Br 600.00 submitted for invoice INV-000003.','finance','insurance_claim',1,'/insurance/claims',0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE message LIKE '%CLM-000001%');
