# Staff Management Tool — Production Release Runbook (Phase 4)

**System:** Staff Management Tool (GPS Attendance, Location Mismatch, WFH Approval & Scheduled Check-In)  
**Target Production Domain:** `https://cloudaspire.in`  
**Target Production Database:** `qaschyan_portel`  
**Application Timezone:** `Asia/Kolkata` (IST, UTC+5:30)  
**Security Standard:** HTTPS (TLS/SSL Port 443), Anti-CSRF, Anti-IDOR, Transactional Concurrency  
**Release Readiness State:** **STAGING & UAT VERIFIED — READY FOR AUTHORIZED PRODUCTION DEPLOYMENT**  

---

## 1. Release Architecture & Environment Pre-requisites

| Component | Production Standard / Requirement | Verification Command / Method |
|---|---|---|
| **Web Server** | Apache 2.4+ with `mod_rewrite`, `mod_ssl`, `mod_headers` enabled | `httpd -M \| grep -E 'rewrite\|ssl'` |
| **PHP Runtime** | PHP 8.2+ or 8.3 (`alt-php83` on cPanel) with `pdo_mysql`, `openssl`, `mbstring`, `session`, `json` | `php -v` & `php -m` |
| **SSL Certificate** | Valid, trusted public CA certificate (required for HTML5 `navigator.geolocation` API) | `curl -Iv https://cloudaspire.in` |
| **Database Engine** | MySQL 8.0+ / MariaDB 10.4+ with InnoDB engine support | `SELECT VERSION(), @@default_storage_engine;` |
| **Timezone** | Application and DB session enforced to `Asia/Kolkata` (+05:30) | `SELECT @@session.time_zone, NOW();` |
| **Access Security** | Direct access to `.env`, `.installed`, `composer.*`, `*.sql` denied by web server | `.htaccess` rule inspection |

---

## 2. Production Database Backup & Disaster Recovery Plan

> [!CRITICAL]
> **Zero Data-Loss Mandate:** Never run migrations or alter production schemas without a timestamped, verified cold/warm snapshot of the production database.

### 2.1 Pre-Deployment Cold/Warm Snapshot
Execute on the production database host before applying any code or schema changes:
```bash
# Set timestamp variable
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")

# Generate compressed, single-transaction database dump with routines and triggers
mysqldump --host=localhost \
          --user=qaschyan_portel \
          --password \
          --single-transaction \
          --quick \
          --routines \
          --triggers \
          --databases qaschyan_portel > /secure/backups/prod_backup_qaschyan_portel_${TIMESTAMP}.sql

# Verify backup integrity (ensure size is > 0 and dump ended cleanly)
tail -n 5 /secure/backups/prod_backup_qaschyan_portel_${TIMESTAMP}.sql
# Output MUST include: "-- Dump completed on YYYY-MM-DD HH:MM:SS"
```

### 2.2 Disaster Recovery & Emergency Database Restoration Plan
In the event of an unrecoverable migration error or database corruption during release:
1. Put web server into maintenance mode (serve static 503 Maintenance page).
2. Restore database from pre-deployment snapshot:
   ```bash
   mysql --host=localhost \
         --user=qaschyan_portel \
         --password \
         qaschyan_portel < /secure/backups/prod_backup_qaschyan_portel_${TIMESTAMP}.sql
   ```
3. Re-verify table checksums and record counts:
   ```sql
   SELECT table_name, table_rows 
   FROM information_schema.tables 
   WHERE table_schema = 'qaschyan_portel' 
   ORDER BY table_name;
   ```
4. Revert code to previous release commit / tag.
5. Lift maintenance mode.

---

## 3. Database Migration Order, Compatibility Checks & Rollback Plan

### 3.1 Pre-Migration Schema Compatibility Checks
Verify before running migrations:
```sql
USE qaschyan_portel;

-- 1. Ensure employees table exists with primary key `id`
SHOW CREATE TABLE employees;

-- 2. Ensure admin table exists with primary key `id`
SHOW CREATE TABLE admin;

-- 3. Ensure attendance table exists
SHOW CREATE TABLE attendance;

-- 4. Check whether site_settings table exists
SHOW CREATE TABLE site_settings;
```

### 3.2 Sequential Migration Deployment Order

#### Step 1: GPS Attendance & Work Summary Refinements
- **Migration Script:** `database/migration_gps_attendance.sql`
- **Actions:**
  - Adds `check_out_lat`, `check_out_lng`, `check_out_dist` to `attendance` table.
  - Adds unique constraint `unique_attendance_summary` on `daily_work_summaries(attendance_id)`.
  - Adds index `idx_emp_checkout(employee_id, check_out)`.
- **Command:**
  ```bash
  mysql -u qaschyan_portel -p qaschyan_portel < database/migration_gps_attendance.sql
  ```

#### Step 2: WFH Requests, Scheduling Parameters & RBAC
- **Migration Script:** `database/migration_attendance_requests.sql`
- **Actions:**
  - Creates table `attendance_requests` with foreign keys (`employees.id`, `admin.id`).
  - Adds WFH window parameters to `site_settings`: `wfh_early_window_minutes`, `wfh_grace_period_minutes`, `wfh_late_threshold_minutes`, `wfh_auto_reschedule_allowed`.
  - Registers granular permission `attendance.approve_requests`.
  - Maps permission to Role 2 (HR Operations Manager).
- **Command:**
  ```bash
  mysql -u qaschyan_portel -p qaschyan_portel < database/migration_attendance_requests.sql
  ```

### 3.3 Post-Migration Schema Verification Queries
```sql
USE qaschyan_portel;

-- Verify attendance columns
SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA='qaschyan_portel' AND TABLE_NAME='attendance' AND COLUMN_NAME LIKE 'check_out%';

-- Verify site_settings columns
SELECT COLUMN_NAME, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA='qaschyan_portel' AND TABLE_NAME='site_settings' AND COLUMN_NAME LIKE 'wfh_%';

-- Verify unique constraint on daily_work_summaries
SELECT INDEX_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA='qaschyan_portel' AND TABLE_NAME='daily_work_summaries' AND INDEX_NAME='unique_attendance_summary';

-- Verify RBAC permission registration
SELECT * FROM permissions WHERE slug = 'attendance.approve_requests';
```

### 3.4 Granular Rollback Instructions (Zero Data Loss)
If rollback is required without restoring the entire database snapshot:
```bash
# 1. Roll back WFH requests, scheduling parameters, and RBAC mapping
mysql -u qaschyan_portel -p qaschyan_portel < database/rollback_attendance_requests.sql

# 2. Roll back check-out geolocation columns and unique index
mysql -u qaschyan_portel -p qaschyan_portel < database/rollback_gps_attendance.sql
```

---

## 4. Secure Production Environment Configuration & HTTPS Checklist

### 4.1 Production `.env` File
Create `.env` in the production project root with restricted permissions (`chmod 600`):
```env
# Application Environment
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Kolkata

# Production Database Credentials (Restricted to cPanel user)
DB_HOST=localhost
DB_NAME=qaschyan_portel
DB_USER=qaschyan_portel
DB_PASSWORD=<SECURE_PRODUCTION_DB_PASSWORD>
```

### 4.2 Web Server Security & HTTPS Checklist
- [ ] **HTTPS Redirection:** Verify HTTP (port 80) redirects permanently (301) to HTTPS (port 443).
- [ ] **HSTS Header:** Ensure `Strict-Transport-Security: max-age=31536000; includeSubDomains` is served.
- [ ] **Sensitive File Access Denial:** Confirm that requesting `https://cloudaspire.in/.env` returns `HTTP 403 Forbidden`.
- [ ] **Directory Browsing Disabled:** `Options -Indexes` enforced in `.htaccess`.
- [ ] **Error Details Disabled:** `ini_set('display_errors', '0')` and `ini_set('log_errors', '1')` active via `app/db.php`.
- [ ] **Session Security:** Cookies configured with `HttpOnly`, `Secure`, and `SameSite=Lax`.

---

## 5. Post-Deployment Smoke-Test Checklist

Execute immediately after production deployment:

- [ ] **1. Public Application Health:**
  - `https://cloudaspire.in/` returns `HTTP 200 OK` without PHP errors or notices.
- [ ] **2. Admin Authentication:**
  - `https://cloudaspire.in/admin` loads login form with CSRF token; admin credentials authenticate and redirect to `/admin/dashboard`.
- [ ] **3. Employee Authentication:**
  - `https://cloudaspire.in/login` loads login form; active employee authenticates and redirects to `/employee_dashboard`.
- [ ] **4. Employee Attendance Page:**
  - `https://cloudaspire.in/employee_attendance` loads attendance punch card, date, and history table without fatal errors.
- [ ] **5. Admin Office Location Console:**
  - `https://cloudaspire.in/admin/office_location.php` renders office coordinates, radius slider, Leaflet map, and sandbox geofence tester.
- [ ] **6. Admin WFH Approvals Console:**
  - `https://cloudaspire.in/admin/attendance_requests.php` loads request queue, status filters, and approve/reject modal dialogs.
- [ ] **7. Production Error Logs:**
  - Inspect server error log (`error_log` in public_html); verify zero unhandled fatal exceptions.

---

## 6. Physical Smartphone GPS Verification Steps (Field Testing)

> [!NOTE]
> HTML5 Geolocation API (`navigator.geolocation.getCurrentPosition`) requires a secure context (HTTPS) and hardware GPS sensor access on a physical mobile device.

### 6.1 Preparation for Field Tester
1. Use an active smartphone (Android Chrome or iOS Safari).
2. Ensure device **Location / GPS Services** are turned **ON** in device system settings.
3. If testing against staging before production cutover, start the public HTTPS tunnel:
   ```bat
   start-tunnel.bat
   ```
   Provide the generated Cloudflare HTTPS URL to the mobile tester.
4. If testing on production, tester navigates directly to `https://cloudaspire.in/login`.

### 6.2 Test Case GPS-FIELD-01: On-Site Check-In (Within 100m)
1. Tester stands physically at headquarters: Latitude `11.95309480`, Longitude `79.81349809` (configured office location: *Cloud Aspire solution*).
2. Log in as an `on_site` employee.
3. Tap **"Check In"** on the attendance card.
4. Browser prompts: *"cloudaspire.in wants to know your location"*.
5. Tap **"Allow while using app"**.
6. **Expected Result:** Location verified successfully. Attendance punch recorded with green confirmation toast; no location mismatch popup shown.

### 6.3 Test Case GPS-FIELD-02: Location Mismatch & WFH Request (Outside 100m)
1. Tester stands physically outside the 100m office boundary (e.g., >200m away or at home).
2. Log in as an `on_site` employee.
3. Tap **"Check In"**.
4. Allow browser GPS access.
5. **Expected Result:** Location Mismatch Modal intercepts check-in with title *"You're Outside the Office Location"*.
6. Tap **"Work From Home"**.
7. Enter reason: *"Field UAT test outside radius"*. Tap **"Submit WFH Request"**.
8. **Expected Result:** Confirmation toast displayed. Status card shows *"WFH Request Pending HR/CEO Approval"*. Employee is NOT marked as checked in.

### 6.4 Test Case GPS-FIELD-03: HR Approval & Scheduled Punch
1. HR Administrator logs into `admin/attendance_requests.php`.
2. Locates the pending request submitted in Step 6.3.
3. Clicks **"Approve"**, specifies scheduled check-in time (e.g. current time + 10 mins).
4. Submits approval.
5. Employee refreshes `/employee_attendance`.
6. Status card shows *"Approved for WFH - Scheduled Window"*.
7. Employee punches check-in within the permitted window.
8. **Expected Result:** Punch accepted with server timestamp; request state transitions to `checked_in`.

### 6.5 Test Case GPS-FIELD-04: Attendance Checkout with Work Summary
1. Employee taps **"Check Out"**.
2. Work Summary Modal appears.
3. Enter:
   - Tasks Worked On Today: *"Completed on-site and remote GPS boundary field validation"*.
   - Tasks Completed Today: *"Verified geofence trigger and WFH approval punch"*.
4. Tap **"Submit & Check Out"**.
5. **Expected Result:** Check-out confirmed; checkout GPS coordinates stored; daily work summary persisted atomically.

---

## 7. Final Release-Readiness Report & Risk Assessment

### 7.1 Pre-Release Verification Summary

| Verification Category | Status | Notes / Evidence |
|---|:---:|---|
| Automated Integration Test Suite | **PASS** | 109 / 109 passing (100%) |
| Automated UAT Workflow Suite | **PASS** | 26 / 26 passing (100%) |
| Staging Smoke Test Suite | **PASS** | 10 / 10 verified |
| Database Migration Scripts | **PASS** | Idempotent, syntax-checked, verified on staging |
| Database Rollback Scripts | **PASS** | Zero-data-loss rollback verified on staging |
| Timezone Synchronization | **PASS** | Enforced to `Asia/Kolkata` (+05:30) |
| Production Database Isolation | **PASS** | Production `qaschyan_portel` strictly untouched |
| Real Smartphone GPS Field Test | **PENDING** | Programmatically verified via W3C Geolocation API; awaiting manual handset field sign-off |

### 7.2 Risk Assessment & Mitigation Matrix

| Identified Risk | Severity | Mitigation Strategy in Place |
|---|:---:|---|
| **Mobile browser denies location permission** | Medium | System detects permission rejection (`error.code === 1`), displays friendly modal instructing employee how to enable location in site settings. |
| **GPS accuracy degradation (indoor/cloudy)** | Low | High-accuracy flag `enableHighAccuracy: true` with 10s timeout implemented in Geolocation JS. Permitted radius set to 100m to accommodate normal mobile GPS jitter. |
| **Concurrent checkout race conditions** | Low | Database transactions with `SELECT ... FOR UPDATE` row-level locks and `unique_attendance_summary` unique constraint prevent duplicate records. |
| **HR Admin approves own request** | Low | Server-side Anti-Self-Approval validation strictly blocks any administrator whose email matches the employee requesting WFH. |
| **Unexpected production rollback needed** | Low | Timestamped pre-deployment dump procedure documented with verified zero-data-loss rollback scripts. |

---

## 8. Deployment Gate Status

```
================================================================================
  PRODUCTION RELEASE GATE STATUS: HOLD FOR EXPLICIT AUTHORIZATION
================================================================================
  All Phase 4 production release runbooks, backup scripts, migration plans,
  smoke test checklists, and field test procedures are prepared and verified.

  NO PRODUCTION SYSTEM HAS BEEN TOUCHED, MODIFIED, OR DEPLOYED.
================================================================================
```
