# Staff Management Tool — Staging Deployment Checklist
**Module:** GPS Attendance, Location Mismatch Handling, WFH Approval & Scheduled Check-In  
**Target Environment:** Staging  
**Application Timezone:** `Asia/Kolkata` (IST, UTC+5:30)  
**Security Standard:** HTTPS (Required for HTML5 Geolocation API), CSRF Protection, Anti-IDOR, Transactional Concurrency  

---

## 1. Pre-Deployment Environment Verification

- [x] **PHP Runtime:** PHP 8.2+ with `pdo_mysql`, `openssl`, `mbstring`, `session`, `json` enabled.
- [x] **Web Server & SSL:** Apache 2.4+ listening on port 443 (HTTPS) with active SSL certificate (required by browser `navigator.geolocation` API).
- [x] **Database Engine:** MySQL 8.0+ / MariaDB 10.4+ with InnoDB engine support.
- [x] **Timezone Synchronization:**
  - PHP application timezone: `date_default_timezone_set('Asia/Kolkata')` (enforced in `app/db.php`).
  - Database session timezone: `SET time_zone = '+05:30'` (enforced in `app/db.php`).
- [x] **PHP Syntax Check:** All application files linted (`php -l`) with 0 syntax errors.

---

## 2. Database Backup & Rollback Safeguards

- [x] **Pre-Migration Database Snapshot:**
  ```bash
  mysqldump.exe -u root --databases qaschyan_po --result-file="database/staging_backup_qaschyan_po_20261023.sql"
  ```
  *Status:* Backup generated and verified (Size: ~37.4 KB).
- [x] **Rollback Scripts Verified:**
  1. `database/rollback_attendance_requests.sql`: Reverts `attendance_requests` table, site settings columns, and RBAC permissions.
  2. `database/rollback_gps_attendance.sql`: Reverts `attendance.check_out_*` columns and `daily_work_summaries` unique constraint.

---

## 3. Database Migration Deployment Order

1. **Step 1: Apply GPS Attendance & Work Summary Migration**
   - File: `database/migration_gps_attendance.sql`
   - Actions:
     - `ALTER TABLE attendance ADD COLUMN check_out_lat, check_out_lng, check_out_dist`
     - `ALTER TABLE daily_work_summaries ADD UNIQUE KEY unique_attendance_summary (attendance_id)`
     - `ALTER TABLE attendance ADD KEY idx_emp_checkout (employee_id, check_out)`

2. **Step 2: Apply WFH Approval & Scheduled Window Migration**
   - File: `database/migration_attendance_requests.sql`
   - Actions:
     - `CREATE TABLE attendance_requests` (foreign keys to `employees.id` and `admin.id`)
     - `ALTER TABLE site_settings ADD COLUMN wfh_early_window_minutes, wfh_grace_period_minutes, wfh_late_threshold_minutes, wfh_auto_reschedule_allowed`
     - `INSERT INTO permissions (name, slug, module) VALUES ('Attendance: Approve / Reject WFH & Exceptions', 'attendance.approve_requests', 'Attendance')`
     - `INSERT INTO role_permissions` (maps permission to Role 2 — HR Operations Manager)

---

## 4. Configuration & Office Settings Initialization

- [x] **Office Coordinates & Geofencing:**
  - Configured in `admin/office_location.php`:
    - Office Name: `Cloud Aspire Headquarters`
    - Office Coordinates: Latitude `11.94160000`, Longitude `79.80830000`
    - Permitted Radius: `100` meters
    - Location Attendance Enforcement: `Enabled` (1)
- [x] **WFH Scheduled Window Policy:**
  - Early Window: `15` minutes before approved time
  - Grace Period: `15` minutes after approved time
  - Late Threshold: `30` minutes past approved time
  - Auto-reschedule Disallowed: `0` (enforces explicit HR re-approval if late threshold is exceeded)

---

## 5. End-to-End Acceptance Testing Matrix

| Flow | Scenario | Expected Behavior | Staging Result |
|---|---|---|---|
| **GPS Geofence** | Employee inside 100m radius | Normal on-site check-in permitted | **PASS** |
| **Location Mismatch** | Employee outside 100m radius | Intercepted with 3-option modal (`on_site_exception`, `wfh`, `cancel`) | **PASS** |
| **WFH Request** | Employee submits WFH request | Status set to `pending`; no attendance record created; coordinates recorded | **PASS** |
| **Anti-Self-Approval** | HR Admin approves own request | Denied with self-approval violation error | **PASS** |
| **HR/CEO Approval** | HR Admin approves WFH request | Status set to `approved`; scheduled check-in time recorded; no attendance created | **PASS** |
| **Scheduled Window** | Early punch (< approved - 15m) | Rejected with remaining minutes countdown | **PASS** |
| **Scheduled Window** | On-time punch (approved ± grace) | Permitted with server time; delay = 0; transitions request to `checked_in` | **PASS** |
| **Scheduled Window** | Delayed punch (past grace period) | Permitted; recorded with delay minutes | **PASS** |
| **Scheduled Window** | Expired punch (> late threshold) | Blocked when auto-reschedule is disabled | **PASS** |
| **Attendance Close** | Employee checks out with tasks | Tasks validated; checkout GPS saved; row in `daily_work_summaries` persisted | **PASS** |

---

## 6. Staging Automated Test Suite Verification

Execute all test suites from project root:
```bash
php tests/test_attendance_requests.php    # 35 Tests (Workflow & Business Logic)
php tests/test_gps_attendance.php         # 34 Tests (Geofence & Transactional Closing)
php tests/test_ui_rendering.php           # 21 Tests (Modals & Admin UI Consoles)
php tests/test_staging_verification.php    # 19 Tests (End-to-End Staging Lifecycle)
```
**Total Automated Tests:** 109 Tests  
**Passing Rate:** 100% (109 / 109 Passed, 0 Failures)

---

## 7. Go / No-Go Decision Criteria

- [x] Zero failing automated tests
- [x] Zero PHP syntax or runtime errors
- [x] Verified database backup created
- [x] Zero database table locking conflicts
- [x] CSRF tokens validated on all POST forms
- [x] Employee ID strictly resolved from authenticated session (anti-IDOR)
- [x] HTTPS listening on port 443 with valid SSL support
- [x] Production deployment isolated (no production data modified)

**Staging Status:** **APPROVED FOR STAGING RELEASE**
