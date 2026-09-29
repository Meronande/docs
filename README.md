# Clinic Management System (PHP 8 + MySQLi + Bootstrap 5)

A complete, multi-branch, permission-driven clinic management system built with **plain PHP 8**, **MySQLi**, **Bootstrap 5**, vanilla **JS/AJAX**, **Font Awesome** and **Chart.js** — no frameworks.

## Features

| Area | Highlights |
|---|---|
| **Auth & Security** | Session hardening, CSRF tokens on every POST, bcrypt passwords, login lockout (5 attempts / 15 min), audit trail |
| **Roles & Permissions** | 87 granular permissions, role matrix UI, permission-driven sidebar & AJAX endpoints |
| **Multi-branch** | Branch-scoped data everywhere; `branches.all` / `see_all_branches` for global views |
| **Front Desk** | Patients (photos, auto codes), appointments (slot conflict checks), live queue board (call / room / skip / recall) |
| **Clinical (EMR)** | Visits, medical records, vitals, diagnoses, prescriptions — shared medical form |
| **Pharmacy** | Medicines, stock levels, low-stock & expiry alerts, Rx dispensing → sales + optional invoicing |
| **Laboratory** | Test catalog, orders, results entry, doctor notifications |
| **Finance** | Invoice builder with live totals + tax, partial payments (row-locked transactions), receipts, insurance claims lifecycle, expenses |
| **Reports** | 7 report types, date/branch filters, Chart.js visuals, CSV export, print |
| **Administration** | Users, branches, roles, master data (13 catalogs), settings, audit log, global search, notifications |

## Quick Start

```bash
# 1. Install PHP + MariaDB and prepare the database (idempotent)
bash setup.sh

# 2. Start the app (MariaDB + PHP built-in server)
bash start.sh          # uses $PORT, defaults to 8000
```

Then open `http://localhost:8000` (or the forwarded port) and sign in:

| Username | Password |
|---|---|
| `admin` | `Admin@123` |

> Change the admin password after first login (Profile → Change Password).

## Configuration

Database credentials default to `clinic_db` / `clinic` / `Clinic@2024` on `127.0.0.1` and can be overridden via environment variables:

```
DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
```

`APP_DEBUG` is `true` in `config/db.php` — set it to `false` in production to hide detailed errors.

## Project Structure

```
index.php          Front controller (clean URLs, /ajax/*, /modules/<module>/<action>)
router.php         Router for the PHP built-in server (serves static files)
config/
  db.php           MySQLi singleton + query helpers + transactions
  functions.php    e(), money(), fmt_date(), generate_code(), upload_image(), pagination...
  auth.php         Session/CSRF/login, permissions, branch scoping, audit, notifications
includes/          header / sidebar / footer / UI kit / shared medical form
ajax/              JSON endpoints (patients, appointments, queue, billing, ...)
modules/           Page controllers grouped by module (billing, reports, ...)
database/
  schema.sql       Full schema + seed data (44 tables, permissions, roles, settings)
assets/            Theme CSS + app.js (CRUD modal engine, toasts, notifications)
errors/            403 / 404 pages
setup.sh           Provisioning script (installs packages, creates DB + user, loads schema)
start.sh           Starts MariaDB + PHP dev server on $PORT
```

## Notes

- All monetary/datetime display respects the **Settings** page (currency, date format, timezone).
- Document codes (invoices, payments, claims, …) are generated from configurable prefixes and are deletion-safe.
- Notifications are permission- and branch-targeted, with per-user read tracking and 30-second polling.
- All destructive actions require confirmation; every mutation is written to the audit log.
