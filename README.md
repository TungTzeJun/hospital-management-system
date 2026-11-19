# 🏥 Healthcore — Hospital Management Portal

A PHP + MySQL patient/staff portal with real-time appointment booking, per-department queue tracking, and role-based dashboards. No framework, no Composer, no build step — runs on plain Apache/PHP/MySQL (XAMPP-friendly).(HTML INCLUDED IN PHP FILE)

---

## 🚀 Quick Start (5 minutes)

| Step | What to do |
|---|---|
| 1 | Install **XAMPP** (or WAMP/MAMP) — bundles Apache, PHP and MySQL. |
| 2 | Copy this whole folder into your web root: `C:\xampp\htdocs\healthcore-php\` (Windows) or `/Applications/XAMPP/htdocs/healthcore-php/` (Mac). |
| 3 | Start **Apache** and **MySQL** in the XAMPP control panel. |
| 4 | Open `http://localhost/phpmyadmin` → **Import** → select `schema.sql` → **Go**. This creates the `healthcore` database and imports the demo accounts (see table below) along with sample appointments, records, and queue data. |
| 5 | Open `db.php` — defaults (`root` / no password / `localhost`) match a fresh XAMPP install. Only edit if yours differs. |
| 6 | Log in at `http://localhost/healthcore-php/login.php`. |

### Updating an existing database

Back up the database first, then run this in your database's SQL console. It adds the called state, renumbers existing appointments from #1 within each department/day, and updates the serving counter to match any ticket already called today:

```sql
ALTER TABLE appointments
	MODIFY status ENUM('Pending','Confirmed','Arrived','Called','Completed','Cancelled') DEFAULT 'Confirmed';

CREATE TEMPORARY TABLE queue_ticket_numbers AS
SELECT a.id,
			 ROW_NUMBER() OVER (PARTITION BY d.department, a.appt_date ORDER BY a.ticket_number, a.appt_time, a.id) AS ticket_number
FROM appointments a
JOIN doctors d ON d.id = a.doctor_id;

UPDATE appointments a
JOIN queue_ticket_numbers q ON q.id = a.id
SET a.ticket_number = q.ticket_number;

DROP TEMPORARY TABLE queue_ticket_numbers;

ALTER TABLE queue_state
	MODIFY currently_serving INT NOT NULL DEFAULT -1;

UPDATE queue_state qs
LEFT JOIN (
	SELECT d.department, MAX(a.ticket_number) AS ticket_number
	FROM appointments a
	JOIN doctors d ON d.id = a.doctor_id
	WHERE a.appt_date = CURDATE() AND a.status = 'Called'
	GROUP BY d.department
) called ON called.department = qs.department
SET qs.currently_serving = COALESCE(called.ticket_number, -1);
```

After this migration, tickets are numbered from #1 for each department and day. Each doctor's/admin's **Call Next** action marks only the next waiting appointment as `Called`; that patient sees the call on their dashboard and is removed from the waiting count. The live serving number is read from today's `Called` appointments, so an old counter cannot carry over after midnight. New ticket allocation is serialized per department to avoid duplicate numbers when bookings happen at the same time.

### Adding the pharmacy feature to an existing database

Back up the database, then run this once in its SQL console. Fresh installs already get these tables from `schema.sql`.

```sql
CREATE TABLE prescriptions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    medical_record_id INT NOT NULL,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    medicine_name VARCHAR(150) NOT NULL,
    dosage VARCHAR(120) NOT NULL,
    instructions TEXT NOT NULL,
    quantity INT NOT NULL,
    refills_allowed INT NOT NULL DEFAULT 0,
    valid_until DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY medical_record_id (medical_record_id),
    KEY patient_id (patient_id),
    KEY doctor_id (doctor_id),
    CONSTRAINT prescriptions_ibfk_1 FOREIGN KEY (medical_record_id) REFERENCES medical_records (id) ON DELETE CASCADE,
    CONSTRAINT prescriptions_ibfk_2 FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT prescriptions_ibfk_3 FOREIGN KEY (doctor_id) REFERENCES doctors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE pharmacy_orders (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    patient_id INT NOT NULL,
    quantity INT NOT NULL,
    status ENUM('Requested','Ready','Collected','Cancelled') NOT NULL DEFAULT 'Requested',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY prescription_id (prescription_id),
    KEY patient_id (patient_id),
    KEY status (status),
    CONSTRAINT pharmacy_orders_ibfk_1 FOREIGN KEY (prescription_id) REFERENCES prescriptions (id) ON DELETE CASCADE,
    CONSTRAINT pharmacy_orders_ibfk_2 FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### Demo accounts (password: `password123` for all demo accounts)

| Email | Role | Lands on |
|---|---|---|
| `admin@healthcore.com` | Admin | `admin_directory.php` — read-only patient and doctor directory |
| `sarah.lee@healthcore.com` | Doctor | `admin.php` — scoped to her department |
| `john.doe@example.com` | Patient | `index.php` — patient dashboard |
| `pharmacy@healthcore.com` | Pharmacist | `pharmacist.php` — medication pickup requests |

New patients can self-register at `register.php`. Doctor registration requires `DOCTOR_INVITE_CODE`; pharmacist registration requires the `PHARMACIST_INVITE_CODE` environment variable to be configured and the matching invite code.

### 🎬 Suggested walkthrough (~2 min)

1. Log in as **patient** → book an appointment → watch the live "waiting" queue widget.
2. Log out, log in as **doctor** → add a checkup record and its structured prescription.
3. Log back in as **patient** → request fulfillment from the Pharmacy section and track its status.
4. As **pharmacist**, mark the request ready for pickup and then collected. The patient's request status updates on refresh.

Pharmacy requests use only clinician-entered prescriptions linked to a checkup. They are internal pickup requests; the portal does not process payment, delivery, or external pharmacy transactions.



Something not working? Jump to **[Troubleshooting](#-troubleshooting)**.

---

## 📁 Project structure

| File | Purpose |
|---|---|
| `schema.sql` | Creates the `healthcore` database, all tables, and the demo accounts/sample data |
| `db.php` | MySQL connection + shared config (edit credentials here) |
| `auth.php` | Session/login helpers + CSRF protection (`csrf_field()` / `verify_csrf()`) |
| `login.php` / `register.php` / `logout.php` | Authentication |
| `index.php` | Patient dashboard — appointments, queue status, medical records, prescription fulfillment requests |
| `export_records.php` | Authenticated patient download of all medical records as a PDF |
| `profile.php` | Patient profile |
| `settings.php` | Notification preferences and password change |
| `admin.php` | Doctor dashboard — department appointments, queue control, and clinical records/prescriptions |
| `admin_directory.php` | Read-only admin directory — hospital-wide patient history and doctor contact information |
| `pharmacist.php` | Pharmacy staff dashboard — medication pickup request status only |
| `get_slots.php` | AJAX: which appointment slots are free for a doctor/date |
| `queue_status.php` | AJAX: live per-department queue numbers (polled every 5s) |
| `queue_helpers.php` | Shared queue read/write logic used by every page |
| `style.css` / `script.js` | Front end |

> **Note:** Each `.php` page (e.g. `login.php`, `index.php`, `admin.php`, `settings.php`) already contains its own HTML markup inline — the PHP logic and the page's HTML output live in the same file, so there's no separate `.html` template to copy or edit.

> **Security note:** the old static `admin.html` / `index.html` / `profile.html` / `settings.html` files have been removed. They had no login check — anyone could view the staff admin UI directly, and "Logout" didn't end a session. The `.php` pages are now the only versions, and every one is gated by `auth.php`'s `require_role()`.

---

## 🔐 How the login/role system works

- One `users` table with a `role` column (`patient`, `doctor`, `admin`, `pharmacist`); `patients` and `doctors` tables hold role-specific fields linked via `user_id`.
- `auth.php`'s `require_role([...])` guards every protected page — wrong role bounces you to your own dashboard, logged-out bounces you to `login.php`.
- Passwords use PHP's `password_hash()` / `password_verify()` — never stored in plain text.
- Admin accounts are redirected to `admin_directory.php`, a read-only view of patient profiles and history plus doctor contact/department information. Admins cannot access the staff dashboard or queue endpoint, or submit queue, appointment, clinical-record, or pharmacy actions; these restrictions are enforced server-side.
- Doctor accounts use `admin.php` for department-scoped clinical and queue tasks and do not see the hospital-wide directory. Pharmacists use `pharmacist.php` to update medication pickup statuses and have no queue or clinical-record controls.
- Every data-changing form (login, register, book/cancel, pharmacy requests, profile, settings, and staff actions) is protected by a CSRF token (`csrf_field()` + `verify_csrf()`), so another site can't silently submit forms on a logged-in user's behalf.
- The session cookie is `HttpOnly` + `SameSite=Lax`. Once served over HTTPS, uncomment `'secure' => true` in `auth.php`'s `session_set_cookie_params()`.
- Ticket numbers start at #1 and reset per department per calendar day. A call marks one appointment as `Called`; it does not advance all patients sharing the queue.
- Queue tickets display a department prefix and a number padded to at least two digits (for example, `GM01` for General Medicine and `N01` for Neurology); the database retains numeric ticket values for ordering.
- Patient wait estimates recalculate on each live queue poll using unserved tickets ahead and the current called ticket's remaining service time. Called tickets that have already been passed are no longer counted; queue-status responses are not cached.

### Adding pharmacist access to an existing database

Back up the database, then run this single statement in its SQL console. It modifies the allowed user roles and preserves existing rows:

```sql
ALTER TABLE users
MODIFY role ENUM('patient','doctor','admin','pharmacist') NOT NULL DEFAULT 'patient';
```

Set a `PHARMACIST_INVITE_CODE` environment variable in the PHP hosting service before inviting pharmacy staff to register. Without it, pharmacist self-registration is disabled.

---


## 🆘 Troubleshooting

| Symptom | Fix |
|---|---|
| **"Database connection failed"** | Check the database host and credentials, and import `schema.sql`. On Railway, add a MySQL service and reference its `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, and `MYSQLPASSWORD` variables in the PHP service's Variables tab; `db.php` reads these automatically. On Railway's PHP/Nixpacks build, `composer.json` declares the required `pdo_mysql` extension. For a custom Dockerfile or another PHP host, configure the database variables and enable PDO MySQL in that runtime. |
| **Blank white page** | Check XAMPP's PHP error log, or temporarily add `ini_set('display_errors', 1); error_reporting(E_ALL);` to the top of the failing file. |
| **"No patient profile found"** | You're logged in as a `patient`-role user with no row in the `patients` table — shouldn't happen via `register.php`. |

---

© 2026 Healthcore Hospital Management System
