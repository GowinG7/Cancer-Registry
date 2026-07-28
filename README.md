# BPKMCH Hospital Cancer Registry

A PHP web application for hospital-based cancer registration: hospitals record patients with
ICD-coded primary and secondary diagnoses, correct their records at any time, and export their
data to Excel. A separate superadmin panel gives the registry administrator full control over
every hospital account and every record.

## Installation (XAMPP)

1. Copy the `cancer-registry` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. In phpMyAdmin create the `cancer_registry` database and import your existing dump.
4. Apply the database upgrade — either open <http://localhost/cancer-registry/upgrade.php> and
   press **Run the upgrade**, or import **`superadmin/sql/upgrade.sql`** in phpMyAdmin with
   `cancer_registry` selected. It is safe to run more than once and it:
   - fixes the hospital deletion error #1451 (`ON DELETE CASCADE` on `fk_patient_hospital`),
   - adds `is_active` / `deleted_at` to `hospital_accounts` (deactivate instead of delete),
   - adds `patient_records.updated_at` plus dashboard indexes,
   - creates `super_admins` and `admin_activity_log`.
5. Open <http://localhost/cancer-registry/> and sign in as a hospital, or
   <http://localhost/cancer-registry/superadmin/login.php> as the administrator
   (`superadmin` / `ChangeMe@123` — **change this password immediately** under *My Profile*).

If your MySQL user is not `root` with an empty password, edit the four variables at the top of
`config.php`. That one file holds the connection used by both the hospital pages and the
superadmin panel.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `Unknown column 'p.updated_at'` or a *Database upgrade pending* banner | The upgrade has not been applied — open `upgrade.php` or import `superadmin/sql/upgrade.sql`. |
| Deleting a hospital fails with error **#1451** | Same: the upgrade recreates `fk_patient_hospital` with `ON DELETE CASCADE`. |
| The upgrade prints *skipped: patient_records rows point at hospitals that no longer exist* | Orphan records block the foreign key. Reassign or remove the rows whose `hospital_id` has no hospital, then run the upgrade again. |
| The upgrade prints *skipped: duplicate hospital usernames* | Two hospitals share a username. Rename one in the superadmin panel and run the upgrade again. |

## Hospital application

| Page | What it does |
| --- | --- |
| `index.php` | Hospital login: username **or** hospital code + password, CSRF-protected, rejects deactivated accounts, regenerates the session ID. |
| `dashboard.php` | One row per patient with statistics, search, sex/ICD/date filters, pagination and **View / Edit / Delete** actions. |
| `add_patient_diagnosis.php` | Register a patient with optional primary and secondary diagnoses; the whole save runs in one transaction. |
| `edit_patient.php` | Correct any patient record: all patient fields plus adding, changing or removing each diagnosis and its preparer. |
| `view_patient.php` | Full read-only record with both diagnoses. |
| `profile.php` | Hospital's own email/contact/address and password change, plus its record counts. |
| `export.php` | Excel (`.xlsx`) or CSV export of the hospital's own records, honouring the dashboard filters. |
| `contact.php` | Support form for reaching the superadmin. Linked from the login page too, so a hospital that cannot sign in can still ask for help. |

A hospital can only ever see, edit, delete or export records whose `hospital_id` matches its
session — every query is scoped by the logged-in hospital.

## Superadmin panel (`superadmin/`)

Sign in at `superadmin/login.php` with `superadmin` / `ChangeMe@123` and change the password
under *My Profile*.

| Page | What it does |
| --- | --- |
| `index.php` | Totals, records per hospital and the most frequent ICD sites. |
| `hospitals.php` | All hospital accounts: activate / deactivate, or permanently delete after typing the hospital code (the confirmation shows how many patient records will be destroyed). |
| `hospital_form.php` | Create or edit a hospital: name, code, username, password, contact details and logo upload. |
| `patients.php` | Every patient of every hospital, filtered by hospital, search, sex, province, ICD code and date range. |
| `icd.php` | ICD master maintenance. |
| `exports.php` / `export.php` | Excel or CSV exports: full registry, patients, diagnoses, hospitals, ICD master and the analysis sheets (by hospital, ICD site, province, age group, month). |
| `messages.php` | Support requests sent from `contact.php`: contact details, status (new / in progress / resolved), internal notes and delete. New messages are counted in the sidebar and on the dashboard. |
| `activity_log.php` | Audit trail of every administrator action. |
| `profile.php` | Change the administrator password, add more administrators and upload the registry logo shown on the login pages and headers. |

Hospital deletion is destructive, so deactivating is the default: the account keeps all of its
records but can no longer sign in.

## Technology

PHP 8 with MySQLi prepared statements, MySQL/MariaDB, Bootstrap 5, and a dependency-free XLSX
writer (`superadmin/lib/XlsxWriter.php`) built on `ZipArchive` — no Composer required.

## Project structure

```text
cancer-registry/
├── config.php                   Session + database connection (edit the credentials here)
├── index.php                    Hospital login
├── dashboard.php                Patient records, filters, View / Edit / Delete
├── add_patient_diagnosis.php    New patient + diagnoses
├── edit_patient.php             Correct an existing record
├── view_patient.php             Full record details
├── profile.php                  Hospital profile and password
├── export.php                   Excel / CSV export for the hospital
├── contact.php                  Support form sent to the superadmin
├── hospital_account.php         Sends account creation to the superadmin panel
├── upgrade.php                  Runs the database upgrade from the browser
├── logout.php
├── assets/
│   ├── css/app.css              Application theme
│   ├── js/app.js                Form behaviour and confirmations
│   └── images/                  Logos used by the login and the navbar
├── includes/
│   ├── functions.php            Escaping, CSRF, flash, login guard, validation, queries
│   ├── patient_form.php         Form shared by the add and edit pages
│   ├── header.php / footer.php  Shared page shell and navigation
│   └── nepal_locations.php      Provinces and districts
├── superadmin/
│   ├── login.php / logout.php / index.php
│   ├── hospitals.php / hospital_form.php
│   ├── patients.php / icd.php
│   ├── exports.php / export.php
│   ├── messages.php             Support requests from the hospitals
│   ├── activity_log.php / profile.php
│   ├── assets/style.css         Panel theme
│   ├── includes/                config.php, auth.php, queries.php, header.php, footer.php
│   ├── lib/XlsxWriter.php       XLSX writer used by both panels
│   └── sql/upgrade.sql          Idempotent database upgrade
└── uploads/logos/               Hospital logos
```

## Data model

- `hospital_accounts` — one login per hospital (`is_active` controls access).
- `support_messages` — help requests sent by the hospitals, with their status and internal notes.
- `patient_records` — patient details, owned by a hospital; deleting a hospital now cascades.
- `patient_diagnosis` — primary/secondary diagnosis per patient, referencing `icd_master`.
- `diagnosis_filled_by` — who prepared each diagnosis.
- `super_admins`, `admin_activity_log` — administrator accounts and audit trail.

## Security

- All statements are prepared; every rendered value is escaped with `htmlspecialchars`.
- Every state-changing form carries a CSRF token; deletes are POST-only with confirmation.
- Passwords are bcrypt (`password_hash`); session cookies are `HttpOnly` and `SameSite=Lax`;
  the session ID is regenerated on login.
- Deactivated hospitals cannot sign in and active sessions are dropped.
- Hospital accounts can only be created and managed from the superadmin panel.
- Database errors are logged, never shown to users.
- Multi-table saves run inside transactions and roll back on failure.

Before storing real patient data on a public server, also add HTTPS, off-site backups, a
session timeout, and database credentials managed outside the web root.
