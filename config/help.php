<?php
/**
 * Help content per role.
 * Used by modules/help/index.php (on-screen guide) and the PDF download.
 * Keep role names identical to database/schema.sql role seeds.
 */

declare(strict_types=1);

if (!defined('APP_BOOT') && !defined('HELP_CONTENT_ONLY')) {
    require_once dirname(__DIR__) . '/config/auth.php';
}

/**
 * Structure: role_name => [
 *   'icon'      => FontAwesome icon,
 *   'tagline'   => one-line purpose of the role,
 *   'intro'     => short paragraph,
 *   'start'     => [step, step, ...]  getting-started checklist,
 *   'modules'   => [ [module, icon, [how-to steps...]], ... ],
 *   'tips'      => [tip, tip, ...],
 * ]
 */
function help_roles(): array
{
    return [
        'Super Admin' => [
            'icon' => 'fa-user-shield',
            'tagline' => 'Full system access — every module, every branch.',
            'intro' => 'As Super Admin you own the whole system: users, roles, branches, settings and all operational modules across every branch. Start by configuring the clinic, then create accounts for your team.',
            'start' => [
                'Open System Settings and set the clinic name, phone, email, address, currency and date format.',
                'Go to Branches and add every physical branch of the clinic.',
                'Open Departments, Specializations and Master Data to prepare shared lists (services, tax, prefixes).',
                'Create Users for each staff member and assign the matching Role (Doctor, Receptionist, Pharmacist, ...).',
                'Review Roles & Permissions if you need custom permission sets beyond the built-in roles.',
                'Register Doctors under People → Doctors so they appear in appointment and queue dropdowns.',
            ],
            'modules' => [
                ['System Settings', 'fa-gear', [
                    'Settings → System Settings: edit clinic identity, currency, invoice/patient prefixes and session timeout.',
                    'Low-stock alert days and receipt footer are also configured here.',
                ]],
                ['Users & Roles', 'fa-users-gear', [
                    'Administration → Users: Add User, fill name/username/email, choose role and branch, then Save.',
                    'Administration → Roles & Permissions: view built-in roles or create custom ones and tick the permissions they need.',
                    'Deactivate instead of delete: a deactivated user can no longer sign in but history is kept.',
                ]],
                ['Branches', 'fa-building', [
                    'Administration → Branches: add each branch with code, phone and address.',
                    'Assign staff to a branch when creating their user; use "Access All Branches" only for auditors/head office.',
                ]],
                ['Reports & Audit', 'fa-chart-line', [
                    'Administration → Reports: finance, patients, pharmacy and lab reports with branch and date filters; export to CSV where available.',
                    'Administration → Audit Log: track every login, create, edit and delete action with the responsible user.',
                ]],
                ['Everything else', 'fa-layer-group', [
                    'You automatically have access to all Front Desk, Clinical, Pharmacy, Laboratory and Finance modules — see the other role guides (download "All Roles" PDF) for their day-to-day workflows.',
                ]],
            ],
            'tips' => [
                'Change the default admin password immediately (Profile → Security).',
                'Use the Audit Log regularly to review sensitive changes.',
            ],
        ],

        'Branch Admin' => [
            'icon' => 'fa-building-shield',
            'tagline' => 'Manages one branch: staff, stock, billing and daily operations.',
            'intro' => 'The Branch Admin runs a single branch. You can do almost everything a Super Admin can — inside your branch — except manage users globally, roles or system-wide settings.',
            'start' => [
                'Check Dashboard for today\'s appointments, queue and revenue at your branch.',
                'Confirm your Doctors and Staff lists are complete under People.',
                'Review Pharmacy stock levels and Laboratory setup if your branch runs them.',
                'Use the branch filter (top bar) only if you were granted all-branch visibility.',
            ],
            'modules' => [
                ['Users (your branch)', 'fa-users', [
                    'Create and edit users assigned to your branch and pick their role.',
                    'You cannot create/delete roles, create/delete branches or change global system settings.',
                ]],
                ['Front Desk', 'fa-hospital-user', [
                    'Register patients, book appointments and manage the live queue — same workflow as Receptionist.',
                ]],
                ['Finance', 'fa-file-invoice-dollar', [
                    'Invoices, payments, insurance claims and expenses for your branch.',
                    'Reports → branch-filtered finance reports; export for your monthly summary.',
                ]],
                ['People & Master Data', 'fa-user-doctor', [
                    'Keep doctors, staff, departments, specializations and services up to date for your branch.',
                ]],
            ],
            'tips' => [
                'Records you create are automatically tagged with your branch.',
                'If a patient from another branch visits, ask a Super Admin — branch data is isolated on purpose.',
            ],
        ],

        'Doctor' => [
            'icon' => 'fa-user-doctor',
            'tagline' => 'Consultations, medical records, prescriptions and lab orders.',
            'intro' => 'Doctors see today\'s schedule and queue, write medical records during consultations, issue prescriptions, and send lab orders — all linked to the patient\'s file.',
            'start' => [
                'Sign in and open Dashboard to see your appointments and today\'s numbers.',
                'Check the Live Queue to see which patient is called / in your room.',
                'Open a patient from Patients list or from the queue to start a consultation.',
            ],
            'modules' => [
                ['Dashboard', 'fa-gauge-high', [
                    'Cards show today\'s appointments, patients seen and pending items; the chart switches between week/month range.',
                ]],
                ['Appointments', 'fa-calendar-check', [
                    'Front Desk → Appointments: view the schedule, use New Appointment to book a patient with a doctor, date, time and reason.',
                    'You can edit appointments (reschedule) but deletions belong to the front desk role.',
                ]],
                ['Live Queue', 'fa-list-ol', [
                    'Front Desk → Live Queue shows waiting patients; reception calls them in and you treat them in order.',
                    'Use the queue board instead of paper numbers — it refreshes automatically.',
                ]],
                ['Medical Records', 'fa-file-medical', [
                    'Clinical → Medical Records → open patient → Add Record: fill visit date, symptoms, diagnosis and notes; Save.',
                    'Records are permanent history — previous visits appear under the patient\'s file for reference.',
                    'Clinical → Diagnoses is the shared diagnosis catalog (if you have permission to maintain it).',
                ]],
                ['Prescriptions', 'fa-prescription', [
                    'From the patient file or Clinical → Prescriptions: Add Prescription, add medicines with dose, frequency and duration.',
                    'Save to send it straight to Pharmacy — the pharmacist sees it under "Prescriptions to Dispense".',
                ]],
                ['Laboratory Orders', 'fa-flask-vial', [
                    'Clinical → Laboratory: order a test for a patient; the lab technician processes it and enters results.',
                    'Results appear back on the lab order — review them before the next consultation.',
                ]],
                ['Global Search', 'fa-magnifying-glass', [
                    'Top search bar finds patients, invoices, medicines and more instantly.',
                ]],
            ],
            'tips' => [
                'Never create a duplicate patient — search first, then register only if truly new.',
                'Prescriptions go to pharmacy in real time; no printing needed unless the patient pays elsewhere.',
            ],
        ],

        'Receptionist' => [
            'icon' => 'fa-hospital-user',
            'tagline' => 'Front desk: register patients, appointments, queue, billing and payments.',
            'intro' => 'You are the first and last touchpoint: register walk-ins, book and manage appointments, run the live queue, take payments and issue simple invoices.',
            'start' => [
                'Open Dashboard for today\'s overview.',
                'Register new patients under Front Desk → Register Patient (or Patients → Add).',
                'Book appointments under Front Desk → Appointments.',
                'Check people in to the Live Queue when they arrive.',
            ],
            'modules' => [
                ['Patients', 'fa-hospital-user', [
                    'Front Desk → Patients: search by name or patient code; open a patient to see their file.',
                    'Add Patient: full name, sex, date of birth, phone, address, blood group — then Save. A patient code (e.g. PAT-000123) is generated automatically.',
                    'Use Edit to correct details; add a photo if available.',
                ]],
                ['Appointments', 'fa-calendar-check', [
                    'Front Desk → New Appointment: choose patient, doctor, date, time and reason; Save.',
                    'The schedule lists upcoming visits; you can edit or delete appointments as needed.',
                ]],
                ['Live Queue', 'fa-list-ol', [
                    'Front Desk → Live Queue: press Check In (Walk-in) and pick the patient to hand them a queue number.',
                    'Use Call, In Room, Skip, Recall and Complete buttons to move patients through the clinic.',
                    'The "Now Serving" card always shows who is with the doctor.',
                ]],
                ['Billing', 'fa-file-invoice-dollar', [
                    'Finance → New Invoice: pick the patient, add services with quantities, Save — the invoice is numbered automatically.',
                    'Finance → Invoices: track unpaid/partial invoices; open one to record payment details.',
                ]],
                ['Payments', 'fa-money-bill-wave', [
                    'Finance → Payments: record what the patient paid against an invoice; receipts use the configured receipt footer.',
                ]],
                ['Global Search', 'fa-magnifying-glass', [
                    'Use the top search bar to find a returning patient in seconds.',
                ]],
            ],
            'tips' => [
                'Check every arriving patient in to the queue — the doctor\'s board depends on it.',
                'Verify phone numbers at registration; appointment and lab notifications rely on them.',
            ],
        ],

        'Pharmacist' => [
            'icon' => 'fa-pills',
            'tagline' => 'Medicines, stock levels, dispensing and pharmacy sales.',
            'intro' => 'You keep the pharmacy stocked and dispense what doctors prescribe. Doctors\' prescriptions arrive in your queue in real time; over-the-counter sales are recorded as direct sales.',
            'start' => [
                'Open Pharmacy → Medicines & Stock to review quantities and expiry.',
                'Open Pharmacy → Prescriptions to Dispense for prescriptions waiting on you.',
                'Use Dispensing & Sales for walk-in (OTC) sales.',
            ],
            'modules' => [
                ['Medicines & Stock', 'fa-pills', [
                    'Pharmacy → Medicines & Stock: Add Medicine with name, category, unit, purchase/selling price, stock quantity, expiry and reorder level.',
                    'Edit a medicine to adjust stock (deliveries) — the system warns when stock falls below the reorder level.',
                ]],
                ['Prescriptions to Dispense', 'fa-clipboard-check', [
                    'Pharmacy → Prescriptions to Dispense lists prescriptions sent by doctors with patient, prescriber and items.',
                    'Open one, confirm availability, dispense; stock is deducted automatically. Mark it dispensed to close it.',
                ]],
                ['Dispensing & Sales', 'fa-bag-shopping', [
                    'Pharmacy → Dispensing & Sales: New Sale, pick items and quantities for walk-in customers, complete the sale and take payment.',
                    'Sales receipts show the sale code (e.g. SAL-000045) and the configured receipt footer.',
                ]],
                ['Master Data', 'fa-database', [
                    'Maintain medicine categories, units and suppliers via Master Data so stock entries stay consistent.',
                ]],
            ],
            'tips' => [
                'Check expiry dates when receiving new stock; remove expired items from sale immediately.',
                'If a prescribed medicine is out of stock, call the doctor to substitute it before dispensing.',
            ],
        ],

        'Laboratory Technician' => [
            'icon' => 'fa-flask-vial',
            'tagline' => 'Lab orders, sample processing and entering results.',
            'intro' => 'Doctors send lab orders to you. You process samples, enter results and mark orders complete so results reach the patient file and the doctor.',
            'start' => [
                'Open Laboratory → Laboratory (Clinical → Laboratory) for the list of orders: pending, in-progress, completed.',
                'Keep the test catalog current under Master Data (test name, price, sample type).',
            ],
            'modules' => [
                ['Lab Orders', 'fa-flask-vial', [
                    'Each order shows patient, ordering doctor, test and status.',
                    'Start an order when the sample is taken (status → in progress).',
                    'Enter Result: fill values and notes, then complete it — the doctor sees the result immediately.',
                ]],
                ['Patients', 'fa-hospital-user', [
                    'View-only access to patient details so you can match samples to the right person.',
                ]],
                ['Master Data', 'fa-database', [
                    'Maintain the lab test catalog and prices; new tests become available to doctors when ordering.',
                ]],
            ],
            'tips' => [
                'Enter results the same day — doctors and patients are waiting on them.',
                'Always verify patient code (PAT-...) against the sample label before entering results.',
            ],
        ],

        'Accountant' => [
            'icon' => 'fa-calculator',
            'tagline' => 'Billing, payments, insurance claims, expenses and finance reports.',
            'intro' => 'You own the money side: invoicing, payments and refunds, insurance claims, expenses, and the finance reports management uses for decisions.',
            'start' => [
                'Review Finance → Invoices for unpaid and partially paid invoices.',
                'Record incoming money under Finance → Payments.',
                'Enter monthly costs under Finance → Expenses.',
                'Finish with Reports for the finance summary.',
            ],
            'modules' => [
                ['Invoices', 'fa-file-invoice-dollar', [
                    'Finance → Invoices: list with status filters; New Invoice lets you bill services to a patient with automatic numbering (INV-...).',
                    'Edit invoices to correct items before payment; view shows printable details.',
                ]],
                ['Payments', 'fa-money-bill-wave', [
                    'Finance → Payments: record full or part payments against invoices.',
                    'Refunds (if permitted) reverse a payment and are kept in the audit trail.',
                ]],
                ['Insurance', 'fa-shield-halved', [
                    'Finance → Insurance: register insurers and policies, create claims for covered invoices and track their status (submitted, approved, rejected, paid).',
                ]],
                ['Expenses', 'fa-receipt', [
                    'Finance → Expenses: log expense date, category, amount and notes; keep receipts attached where possible.',
                ]],
                ['Reports', 'fa-chart-line', [
                    'Reports → finance reports: income vs expenses, revenue by service, outstanding invoices — filter by date range and branch, export where available.',
                ]],
                ['Master Data', 'fa-database', [
                    'Maintain service catalog and prices (Master Data) so invoices use correct amounts.',
                ]],
            ],
            'tips' => [
                'Reconcile payments daily; unpaid invoices should always have a reason (pending insurance, instalment plan).',
                'Use Reports → export for the monthly management meeting.',
            ],
        ],
    ];
}

/** Role list guaranteed to exist in the app; unknown roles fall back to Super Admin content. */
function help_fallback_role(): string
{
    return 'Super Admin';
}
