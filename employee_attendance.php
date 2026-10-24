<?php
// ============================================================================
// CLOUD ASPIRE REAL-TIME ATTENDANCE TERMINAL & TIMESHEET SYSTEM
// Production Implementation: GPS Geofencing, UI/UX Simplification, Transactional Closing
// ============================================================================

// Set timezone to India Standard Time (IST, UTC+5:30)
date_default_timezone_set('Asia/Kolkata');

// Include database connection & security
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/security.php';
require_once __DIR__ . '/app/AttendanceHelper.php';

Security::init($pdo);
Security::initSecureSession();

// Check if employee is logged in
if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    header("Location: login");
    exit();
}

// Enforce session timeout
if (!Security::setSessionTimeout()) {
    header("Location: login?msg=timeout");
    exit();
}

$employee_id = (int)$_SESSION['employee_id'];
$today = date('Y-m-d');
$currentTime = date('H:i:s');

// Consume PRG Flash Messages
$flashMessage = $_SESSION['att_flash'] ?? '';
$flashType = $_SESSION['att_flash_type'] ?? '';
unset($_SESSION['att_flash'], $_SESSION['att_flash_type']);

// Get Employee Details & Work Mode
$stmtEmp = $pdo->prepare("SELECT id, first_name, last_name, employee_id, work_mode, department, position FROM employees WHERE id = ?");
$stmtEmp->execute([$employee_id]);
$employee = $stmtEmp->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    Security::destroySession();
    header("Location: login");
    exit();
}

$workMode = $employee['work_mode'] ?? 'on_site';
$empFullName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
$empDisplayCode = !empty($employee['employee_id']) ? $employee['employee_id'] : sprintf('EMP-%03d', $employee_id);

// Ensure Portal Closure schema is synced
AttendanceHelper::ensurePortalClosureSchema($pdo);

// Get Assigned Shift Settings for this Employee (with any approved exceptions)
$activeShift = AttendanceHelper::getEmployeeShift($pdo, $employee_id, $today);
$shiftStartTime = $activeShift['checkin_start'] ?? ($activeShift['start_time'] ?? '09:00:00');
$shiftEndTime = $activeShift['checkout_time'] ?? ($activeShift['end_time'] ?? '17:00:00');
$graceMinutes = (int)($activeShift['grace_minutes'] ?? 10);
$shiftWindow = AttendanceHelper::getShiftWindowForWorkday($activeShift, $today);
$isEarly = (strtotime($currentTime) < strtotime($shiftEndTime));

// Evaluate Authoritative Server-Side Portal Closure Status
$portalClosure = AttendanceHelper::evaluatePortalClosureStatus($pdo, $employee_id);

// Real-Time Heartbeat / Status API Endpoint for Active Sessions
if (isset($_GET['action']) && $_GET['action'] === 'check_portal_status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'is_closed'               => $portalClosure['is_closed'],
        'closure_reason'          => $portalClosure['closure_reason'],
        'scheduled_checkout_time' => $portalClosure['scheduled_checkout_time'],
        'scheduled_checkout_ts'   => $portalClosure['scheduled_checkout_ts'],
        'seconds_remaining'       => $portalClosure['seconds_remaining'],
        'time_remaining_text'     => $portalClosure['time_remaining_text'],
        'message'                 => $portalClosure['message'],
        'details'                 => $portalClosure['details'],
        'needs_hr_review'         => $portalClosure['needs_hr_review'],
        'server_time'             => $portalClosure['server_time'],
        'server_date'             => $portalClosure['server_date']
    ]);
    exit();
}

// Get Office Geofencing Settings
$officeSettings = AttendanceHelper::getOfficeLocationSettings($pdo);

// ----------------------------------------------------------------------------
// POST ACTION PROCESSING: CHECK-IN & CHECK-OUT (WITH POST-REDIRECT-GET)
// ----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'attendance_action')) {
        $_SESSION['att_flash'] = "Security token expired or invalid. Please refresh and try again.";
        $_SESSION['att_flash_type'] = "error";
        header("Location: employee_attendance");
        exit();
    }

    $action = $_POST['action'];

    // ────────────────────────────────────────────────────────────────────────
    // BACKEND CLOSURE ENFORCEMENT
    // Revalidates shift schedule and server time on every attendance request.
    // Strictly blocks bypass via direct URLs, API calls, page refreshes, dev tools.
    // ────────────────────────────────────────────────────────────────────────
    if (in_array($action, ['check_in', 'check_out', 'submit_request'], true)) {
        $currentClosure = AttendanceHelper::evaluatePortalClosureStatus($pdo, $employee_id);
        if ($currentClosure['is_closed']) {
            $_SESSION['att_flash'] = "Your scheduled work hours have ended. Attendance access is now closed for today.";
            $_SESSION['att_flash_type'] = "error";
            header("Location: employee_attendance");
            exit();
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // 1. CHECK-IN ACTION
    // ────────────────────────────────────────────────────────────────────────
    if ($action === 'check_in') {
        $submittedLat = isset($_POST['latitude']) ? trim($_POST['latitude']) : '';
        $submittedLng = isset($_POST['longitude']) ? trim($_POST['longitude']) : '';

        // Check if employee has an approved request for today
        $todayApprovedReq = AttendanceHelper::getTodayApprovedRequest($pdo, $employee_id, $today);
        $delayMinutes = 0;
        $status = 'present';
        $oldStatus = 'present';
        $locationNote = '';
        $checkInLat = null;
        $checkInLng = null;
        $checkInDist = null;

        if ($todayApprovedReq) {
            // Scheduled-Time Check-In Enforcement
            $winCheck = AttendanceHelper::evaluateScheduledCheckInWindow(
                $todayApprovedReq['approved_check_in_time'], 
                $officeSettings, 
                $currentTime
            );

            if (!$winCheck['can_check_in']) {
                $_SESSION['att_flash'] = $winCheck['message'];
                $_SESSION['att_flash_type'] = "error";
                header("Location: employee_attendance");
                exit();
            }

            $delayMinutes = (int)($winCheck['delay_minutes'] ?? 0);
            $status = ($winCheck['status'] === 'delayed') ? 'delayed' : 'present';
            $oldStatus = ($status === 'delayed') ? 'late' : 'present';

            $checkInLat = ($submittedLat !== '' && is_numeric($submittedLat)) ? (float)$submittedLat : null;
            $checkInLng = ($submittedLng !== '' && is_numeric($submittedLng)) ? (float)$submittedLng : null;
            $checkInDist = null;

            $locationNote = ($todayApprovedReq['requested_work_mode'] === 'wfh') 
                ? " (Approved Remote • Work From Home)" 
                : " (Approved On-Site Location Exception)";
        } else {
            // Server-Side Geofence Validation
            $locCheck = AttendanceHelper::validateEmployeeLocation($pdo, $workMode, $submittedLat, $submittedLng, 'check_in', $employee_id, $today);

            if (!$locCheck['allowed']) {
                $_SESSION['att_flash'] = $locCheck['error'];
                $_SESSION['att_flash_type'] = "error";
                header("Location: employee_attendance");
                exit();
            }

            $checkInLat = $locCheck['latitude'] ?? null;
            $checkInLng = $locCheck['longitude'] ?? null;
            $checkInDist = $locCheck['distance'] ?? null;

            $locationNote = ($checkInDist !== null)
                ? " (Office Verified: {$checkInDist}m away)"
                : (($workMode === 'wfh') ? " (Remote • Work From Home)" : "");

            $delayMinutes = AttendanceHelper::calculateDelay($currentTime, $activeShift);
            $status = AttendanceHelper::getAttendanceStatus($delayMinutes, 0, $activeShift, false);
            $oldStatus = ($status === 'delayed') ? 'late' : 'present';
        }

        try {
            $pdo->beginTransaction();

            // Check if already checked in today (prevents duplicates)
            $stmtToday = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ? FOR UPDATE");
            $stmtToday->execute([$employee_id, $today]);
            $todaysRecord = $stmtToday->fetch(PDO::FETCH_ASSOC);

            if ($todaysRecord && !empty($todaysRecord['check_in']) && empty($todaysRecord['check_out'])) {
                $pdo->rollBack();
                $_SESSION['att_flash'] = "You already have an active checked-in session for today.";
                $_SESSION['att_flash_type'] = "error";
                header("Location: employee_attendance");
                exit();
            }

            $punchHistory = [];
            if ($todaysRecord) {
                $punchHistory = json_decode($todaysRecord['punch_history'] ?? '[]', true) ?: [];
                if (empty($punchHistory) && !empty($todaysRecord['check_in'])) {
                    $punchHistory[] = ['in' => $todaysRecord['check_in'], 'out' => $todaysRecord['check_out']];
                }
            }

            $punchHistory[] = ['in' => $currentTime, 'out' => null];

            if (!$todaysRecord) {
                $insStmt = $pdo->prepare("INSERT INTO attendance 
                    (employee_id, date, check_in, status, attendance_status, delay_minutes, punch_history, check_in_lat, check_in_lng, check_in_dist) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insStmt->execute([
                    $employee_id,
                    $today,
                    $currentTime,
                    $oldStatus,
                    $status,
                    $delayMinutes,
                    json_encode($punchHistory),
                    $checkInLat,
                    $checkInLng,
                    $checkInDist
                ]);
            } else {
                // Secondary punch on the same day: clear checkout and append punch
                $upStmt = $pdo->prepare("UPDATE attendance SET check_out = NULL, punch_history = ?, updated_at = NOW() WHERE id = ?");
                $upStmt->execute([json_encode($punchHistory), $todaysRecord['id']]);
            }

            // If an approved request was used, mark it as checked_in atomically
            if ($todayApprovedReq) {
                $upReq = $pdo->prepare("UPDATE attendance_requests SET status = 'checked_in', actual_check_in = ?, updated_at = NOW() WHERE id = ?");
                $upReq->execute([$currentTime, $todayApprovedReq['id']]);
            }

            $pdo->commit();

            $_SESSION['att_flash'] = "Checked in successfully at " . date('h:i A') . $locationNote . ".";
            $_SESSION['att_flash_type'] = "success";

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Check-in error: " . $e->getMessage());
            $_SESSION['att_flash'] = "Unable to record check-in due to a database error. Please try again.";
            $_SESSION['att_flash_type'] = "error";
        }

        header("Location: employee_attendance");
        exit();
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. SUBMIT ATTENDANCE REQUEST (WFH OR ON-SITE EXCEPTION)
    // ────────────────────────────────────────────────────────────────────────
    elseif ($action === 'submit_request') {
        $reqMode    = in_array($_POST['requested_work_mode'] ?? '', ['wfh', 'on_site_exception'], true) ? $_POST['requested_work_mode'] : 'wfh';
        $reqReason  = trim($_POST['reason'] ?? '');
        $reqTime    = trim($_POST['requested_check_in_time'] ?? date('H:i'));
        $reqDetails = trim($_POST['supporting_details'] ?? '');
        $reqLat     = isset($_POST['latitude']) ? trim($_POST['latitude']) : null;
        $reqLng     = isset($_POST['longitude']) ? trim($_POST['longitude']) : null;
        $reqDist    = isset($_POST['distance']) ? trim($_POST['distance']) : null;

        $res = AttendanceHelper::createAttendanceRequest($pdo, $employee_id, [
            'attendance_date'         => $today,
            'requested_work_mode'     => $reqMode,
            'reason'                  => $reqReason,
            'supporting_details'      => $reqDetails,
            'requested_check_in_time' => $reqTime,
            'latitude'                => $reqLat,
            'longitude'               => $reqLng,
            'distance'                => $reqDist
        ]);

        if ($res['success']) {
            $modeTitle = ($reqMode === 'wfh') ? 'Work From Home' : 'On-Site Location Exception';
            $_SESSION['att_flash'] = "Your {$modeTitle} request for " . date('h:i A', strtotime($reqTime)) . " has been submitted to HR/CEO for review and approval.";
            $_SESSION['att_flash_type'] = "success";
        } else {
            $_SESSION['att_flash'] = $res['error'];
            $_SESSION['att_flash_type'] = "error";
        }

        header("Location: employee_attendance");
        exit();
    }

    // ────────────────────────────────────────────────────────────────────────
    // 3. CANCEL ATTENDANCE REQUEST (BY EMPLOYEE)
    // ────────────────────────────────────────────────────────────────────────
    elseif ($action === 'cancel_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $res = AttendanceHelper::cancelAttendanceRequest($pdo, $employee_id, $requestId);

        if ($res['success']) {
            $_SESSION['att_flash'] = $res['message'];
            $_SESSION['att_flash_type'] = "success";
        } else {
            $_SESSION['att_flash'] = $res['error'];
            $_SESSION['att_flash_type'] = "error";
        }

        header("Location: employee_attendance");
        exit();
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. CHECK-OUT ACTION (CLOSING SESSION WITH WORK SUMMARY & GPS)
    // ────────────────────────────────────────────────────────────────────────
    elseif ($action === 'check_out') {
        $activeOpenRec = AttendanceHelper::getActiveOpenAttendance($pdo, $employee_id, $today);

        if (!$activeOpenRec) {
            $_SESSION['att_flash'] = "No active check-in session found to close.";
            $_SESSION['att_flash_type'] = "error";
            header("Location: employee_attendance");
            exit();
        }

        $attendanceIdToClose = (int)$activeOpenRec['id'];

        // Geolocation Verification for Check-Out (if required)
        $submittedLat = isset($_POST['latitude']) ? trim($_POST['latitude']) : '';
        $submittedLng = isset($_POST['longitude']) ? trim($_POST['longitude']) : '';
        $locCheck = AttendanceHelper::validateEmployeeLocation($pdo, $workMode, $submittedLat, $submittedLng, 'check_out');

        if (!$locCheck['allowed']) {
            $_SESSION['att_flash'] = $locCheck['error'];
            $_SESSION['att_flash_type'] = "error";
            header("Location: employee_attendance");
            exit();
        }

        $geoData = [
            'latitude'  => $locCheck['latitude'] ?? null,
            'longitude' => $locCheck['longitude'] ?? null,
            'distance'  => $locCheck['distance'] ?? null
        ];

        // Sanitize Summary Inputs
        $summaryData = [
            'work_summary'    => $_POST['work_summary'] ?? '',
            'completed_tasks' => $_POST['completed_tasks'] ?? '',
            'pending_tasks'   => $_POST['pending_tasks'] ?? ''
        ];
        $leaveCode = !empty($_POST['leave_code']) ? trim($_POST['leave_code']) : null;

        // Atomic Transaction Execution
        $closeResult = AttendanceHelper::closeAttendanceTransaction(
            $pdo,
            $employee_id,
            $attendanceIdToClose,
            $summaryData,
            $leaveCode,
            $currentTime,
            $geoData
        );

        if ($closeResult['success']) {
            $_SESSION['att_flash'] = $closeResult['message'];
            $_SESSION['att_flash_type'] = "success";
        } else {
            $_SESSION['att_flash'] = $closeResult['error'];
            $_SESSION['att_flash_type'] = "error";
        }

        header("Location: employee_attendance");
        exit();
    }
}

// ----------------------------------------------------------------------------
// LOAD CURRENT ATTENDANCE STATE FOR TODAY
// ----------------------------------------------------------------------------
$activeOpenRecord = AttendanceHelper::getActiveOpenAttendance($pdo, $employee_id, $today);
$isCheckedIn = ($activeOpenRecord !== null);
$currentPunchIn = null;
$activeAttendanceId = $activeOpenRecord['id'] ?? 0;

if ($activeOpenRecord) {
    $pHistory = json_decode($activeOpenRecord['punch_history'] ?? '[]', true) ?: [];
    foreach ($pHistory as $p) {
        if (empty($p['out'])) {
            $currentPunchIn = $p['in'];
            break;
        }
    }
    if (!$currentPunchIn) {
        $currentPunchIn = $activeOpenRecord['check_in'];
    }
}

// Fetch Today's Overall Record
$stmtToday = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND date = ?");
$stmtToday->execute([$employee_id, $today]);
$todaysRecord = $stmtToday->fetch(PDO::FETCH_ASSOC);

// Determine Today's Status State
// States: 'not_checked_in', 'checked_in', 'checked_out', 'portal_closed'
$todayState = 'not_checked_in';
if ($portalClosure['is_closed']) {
    $todayState = 'portal_closed';
} elseif ($isCheckedIn) {
    $todayState = 'checked_in';
} elseif ($todaysRecord && !empty($todaysRecord['check_out'])) {
    $todayState = 'checked_out';
}

// Fetch Attendance History (Recent 30 Days) with Shift Resolution & Daily Work Summaries
$history = [];
try {
    $sqlHistory = "SELECT 
        a.*,
        COALESCE(dws.work_summary, a.work_summary) AS final_work_summary,
        COALESCE(dws.completed_tasks, a.completed_tasks) AS final_completed_tasks,
        COALESCE(dws.pending_tasks, a.pending_tasks) AS final_pending_tasks,
        ar.requested_work_mode AS approved_request_mode,
        ar.approved_check_in_time,
        ar.approved_check_out_time,
        COALESCE(ss.shift_name, def_ss.shift_name, 'Regular Shift') AS resolved_shift_name,
        COALESCE(ar.approved_check_in_time, ss.checkin_start, def_ss.checkin_start) AS resolved_checkin_start,
        COALESCE(ar.approved_check_out_time, ss.checkout_time, def_ss.checkout_time) AS resolved_checkout_time,
        e.work_mode AS employee_work_mode
    FROM attendance a
    LEFT JOIN employees e ON a.employee_id = e.id
    LEFT JOIN shift_settings ss ON e.shift_id = ss.id
    LEFT JOIN (SELECT * FROM shift_settings WHERE is_active = 1 ORDER BY id ASC LIMIT 1) def_ss ON 1=1
    LEFT JOIN (
        SELECT attendance_id, work_summary, completed_tasks, pending_tasks 
        FROM daily_work_summaries 
        WHERE id IN (SELECT MAX(id) FROM daily_work_summaries GROUP BY attendance_id)
    ) dws ON a.id = dws.attendance_id
    LEFT JOIN (
        SELECT employee_id, attendance_date, requested_work_mode, approved_check_in_time, approved_check_out_time 
        FROM attendance_requests 
        WHERE status = 'approved'
        AND id IN (SELECT MAX(id) FROM attendance_requests WHERE status = 'approved' GROUP BY employee_id, attendance_date)
    ) ar ON a.employee_id = ar.employee_id AND a.date = ar.attendance_date
    WHERE a.employee_id = ?
    ORDER BY a.date DESC, a.id DESC
    LIMIT 30";

    $stmtHistory = $pdo->prepare($sqlHistory);
    $stmtHistory->execute([$employee_id]);
    $history = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching attendance history: " . $e->getMessage());
    $history = [];
}

// Fetch Today's Attendance Request (WFH or On-Site Exception)
$todayRequest = AttendanceHelper::getAttendanceRequestForDate($pdo, $employee_id, $today);
$todayApprovedReq = AttendanceHelper::getTodayApprovedRequest($pdo, $employee_id, $today);
$scheduledWindowCheck = null;
if ($todayApprovedReq) {
    $scheduledWindowCheck = AttendanceHelper::evaluateScheduledCheckInWindow(
        $todayApprovedReq['approved_check_in_time'], 
        $officeSettings, 
        $currentTime
    );
}

// Fetch Employee Request History (Recent 10)
$requestHistory = AttendanceHelper::getEmployeeRequestsHistory($pdo, $employee_id, 10);

$csrfToken = Security::generateCSRFToken('attendance_action');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Attendance &bull; Workforce Terminal</title>
    <meta name="description" content="Clean, responsive employee attendance dashboard with location-aware check-in and checkout.">
    <link rel="icon" type="image/png" href="https://cloudaspire.in/uploads/site_favicon_1783239900.png">

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme.css">

    <!-- Prevent Flash of Unstyled Content (FOUC) -->
    <script>
        (function() {
            try {
                const saved = localStorage.getItem('theme');
                if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>

    <style>
        :root {
            --att-bg:             #f8fafc;
            --att-surface:        #ffffff;
            --att-surface-subtle: #f1f5f9;
            --att-text:           #0f172a;
            --att-text-muted:     #64748b;
            --att-border:         #cbd5e1;
            --att-border-subtle:  #e2e8f0;

            --att-brand:          #4f46e5;
            --att-brand-hover:    #4338ca;
            --att-brand-light:    #eef2ff;
            --att-brand-border:   #c7d2fe;

            --att-success:        #059669;
            --att-success-light:  #ecfdf5;
            --att-success-border: rgba(5, 150, 105, 0.3);

            --att-warning:        #d97706;
            --att-warning-light:  #fffbeb;
            --att-warning-border: rgba(217, 119, 6, 0.3);

            --att-danger:         #e11d48;
            --att-danger-light:   #fff1f2;
            --att-danger-border:  rgba(225, 29, 72, 0.3);

            --att-radius-sm:      8px;
            --att-radius-md:      12px;
            --att-radius-lg:      16px;
            --att-radius-card:    20px;
            --att-radius-pill:    9999px;

            --att-shadow:         0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 8px 24px -4px rgba(15, 23, 42, 0.06);
            --att-shadow-hover:   0 12px 30px -4px rgba(15, 23, 42, 0.12);
            --att-transition:     0.2s cubic-bezier(0.16, 1, 0.3, 1);
            --att-max-w:          1440px;
        }

        html[data-theme="dark"] {
            --att-bg:             #090d16;
            --att-surface:        #131d33;
            --att-surface-subtle: #0f172a;
            --att-text:           #f8fafc;
            --att-text-muted:     #94a3b8;
            --att-border:         #2d3f66;
            --att-border-subtle:  #1e2a44;

            --att-brand-light:    rgba(79, 70, 229, 0.2);
            --att-brand-border:   rgba(99, 102, 241, 0.35);
            --att-success-light:  rgba(5, 150, 105, 0.2);
            --att-warning-light:  rgba(217, 119, 6, 0.2);
            --att-danger-light:   rgba(225, 29, 72, 0.2);

            --att-shadow:         0 4px 24px -2px rgba(0, 0, 0, 0.55);
            --att-shadow-hover:   0 16px 36px -6px rgba(0, 0, 0, 0.7);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: var(--att-bg);
            color: var(--att-text);
            line-height: 1.6;
            min-height: 100vh;
            transition: background-color var(--att-transition), color var(--att-transition);
        }

        .main-container {
            width: 100%;
            max-width: var(--att-max-w);
            margin: 0 auto;
            padding: 28px 24px 60px;
        }

        /* ── Header Ribbon ── */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .header-title-wrap h1 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(1.5rem, 3.2vw, 2rem);
            font-weight: 800;
            color: var(--att-text);
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }
        .header-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 0.88rem;
            color: var(--att-text-muted);
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: var(--att-radius-pill);
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .badge-brand {
            background: var(--att-brand-light);
            color: var(--att-brand);
            border: 1px solid var(--att-brand-border);
        }
        .badge-onsite {
            background: var(--att-success-light);
            color: var(--att-success);
            border: 1px solid var(--att-success-border);
        }
        .badge-wfh {
            background: var(--att-brand-light);
            color: var(--att-brand);
            border: 1px solid var(--att-brand-border);
        }

        /* ── Feedback Alert Toast ── */
        .toast-banner {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--att-radius-md);
            margin-bottom: 24px;
            font-size: 0.92rem;
            font-weight: 600;
            animation: fadeInDown 0.25s ease;
        }
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .toast-success {
            background: var(--att-success-light);
            color: var(--att-success);
            border: 1px solid var(--att-success-border);
        }
        .toast-error {
            background: var(--att-danger-light);
            color: var(--att-danger);
            border: 1px solid var(--att-danger-border);
        }

        /* ── Main Hero Terminal Card ── */
        .hero-terminal-card {
            background: var(--att-surface);
            border: 1px solid var(--att-border);
            border-radius: var(--att-radius-card);
            box-shadow: var(--att-shadow);
            padding: 36px 30px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            transition: all var(--att-transition);
        }
        .hero-terminal-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4f46e5, #06b6d4, #10b981);
        }
        .portal-closed-card::before {
            background: linear-gradient(90deg, #e11d48, #f59e0b) !important;
        }

        /* ── Portal Auto-Closure & Live Cutoff Timer ── */
        .portal-closure-box {
            background: var(--att-danger-light);
            border: 1.5px solid var(--att-danger-border);
            border-radius: var(--att-radius-md);
            padding: 16px 20px;
            margin-top: 18px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            animation: fadeInDown 0.25s ease;
        }
        .portal-warning-box {
            background: var(--att-warning-light);
            border: 1.5px solid var(--att-warning-border);
            border-radius: var(--att-radius-md);
            padding: 12px 18px;
            margin-bottom: 24px;
            display: none;
            align-items: center;
            gap: 12px;
            font-size: 0.92rem;
            color: var(--att-warning);
            font-weight: 600;
            animation: fadeInDown 0.25s ease;
        }
        .btn-punch-closed {
            background: var(--att-surface-subtle) !important;
            color: var(--att-text-muted) !important;
            border: 1.5px solid var(--att-border) !important;
            cursor: not-allowed !important;
            opacity: 0.75;
        }
        .badge-flagged-review {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: var(--att-radius-pill);
            font-size: 0.8rem;
            font-weight: 700;
            background: var(--att-warning-light);
            color: var(--att-warning);
            border: 1px solid var(--att-warning-border);
        }

        .terminal-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 28px;
            align-items: center;
        }
        @media (max-width: 768px) {
            .hero-terminal-card { padding: 24px 20px; }
            .terminal-grid { grid-template-columns: 1fr; gap: 20px; text-align: center; }
        }

        .status-eyebrow {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 800;
            color: var(--att-text-muted);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        @media (max-width: 768px) {
            .status-eyebrow { justify-content: center; }
        }

        .status-title {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(1.4rem, 3.2vw, 1.9rem);
            font-weight: 800;
            color: var(--att-text);
            margin-bottom: 8px;
            line-height: 1.2;
        }

        .status-details-row {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            margin-top: 14px;
        }
        @media (max-width: 768px) {
            .status-details-row { justify-content: center; }
        }
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .detail-label {
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--att-text-muted);
        }
        .detail-val {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--att-text);
        }

        /* ── Pulse Indicator Dot ── */
        .pulse-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        .pulse-active {
            background: var(--att-success);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.25);
            animation: pulseAnim 2s infinite;
        }
        @keyframes pulseAnim {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.25); opacity: 0.7; }
        }
        .pulse-idle {
            background: var(--att-text-muted);
        }

        /* ── Primary Action Buttons ── */
        .primary-action-wrap {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }
        @media (max-width: 768px) {
            .primary-action-wrap { align-items: stretch; width: 100%; }
        }

        .btn-punch {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 16px 36px;
            border-radius: var(--att-radius-pill);
            font-family: inherit;
            font-size: 1.05rem;
            font-weight: 800;
            border: none;
            cursor: pointer;
            transition: all var(--att-transition);
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.12);
            text-decoration: none;
        }
        .btn-punch:hover {
            transform: translateY(-2px);
        }
        .btn-punch:active {
            transform: scale(0.97);
        }
        .btn-punch:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-check-in {
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            color: #ffffff;
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.35);
        }
        .btn-check-in:hover {
            background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
            box-shadow: 0 8px 26px rgba(79, 70, 229, 0.45);
        }

        .btn-check-out {
            background: linear-gradient(135deg, #d97706 0%, #f59e0b 100%);
            color: #ffffff;
            box-shadow: 0 6px 20px rgba(217, 119, 6, 0.35);
        }
        .btn-check-out:hover {
            background: linear-gradient(135deg, #b45309 0%, #d97706 100%);
            box-shadow: 0 8px 26px rgba(217, 119, 6, 0.45);
        }

        .action-subtext {
            font-size: 0.78rem;
            color: var(--att-text-muted);
            text-align: right;
        }
        @media (max-width: 768px) {
            .action-subtext { text-align: center; }
        }

        /* ── Location Banner Notice ── */
        .gps-status-box {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: var(--att-radius-md);
            margin-top: 18px;
            font-size: 0.88rem;
            font-weight: 600;
        }

        /* ── History Table Card ── */
        .history-card {
            background: var(--att-surface);
            border: 1px solid var(--att-border);
            border-radius: var(--att-radius-card);
            box-shadow: var(--att-shadow);
            overflow: hidden;
        }
        .history-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--att-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .history-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--att-text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        .history-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .history-table th {
            padding: 14px 20px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--att-text-muted);
            background: var(--att-bg);
            border-bottom: 1px solid var(--att-border);
            white-space: nowrap;
        }
        .history-table td {
            padding: 16px 20px;
            font-size: 0.9rem;
            border-bottom: 1px solid var(--att-border-subtle);
            color: var(--att-text);
            vertical-align: middle;
        }
        .history-table tbody tr:hover {
            background: var(--att-surface-subtle);
        }
        .history-table tr:last-child td {
            border-bottom: none;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: var(--att-radius-pill);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
        }
        .status-present, .status-completed, .status-on_time {
            background: var(--att-success-light);
            color: var(--att-success);
            border: 1px solid var(--att-success-border);
        }
        .status-delayed, .status-late {
            background: var(--att-warning-light);
            color: var(--att-warning);
            border: 1px solid var(--att-warning-border);
        }
        .status-left_early, .status-half_day {
            background: var(--att-brand-light);
            color: var(--att-brand);
            border: 1px solid var(--att-brand-border);
        }
        .status-absent, .status-on_leave {
            background: var(--att-surface-subtle);
            color: var(--att-text-muted);
            border: 1px solid var(--att-border);
        }
        .status-in_progress {
            background: rgba(59, 130, 246, 0.12);
            color: #2563eb;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        .status-pending_hr_review, .status-flagged {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .badge-overnight {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: var(--att-radius-pill);
            font-size: 0.72rem;
            font-weight: 700;
            background: rgba(99, 102, 241, 0.12);
            color: #4f46e5;
            border: 1px solid rgba(99, 102, 241, 0.25);
            margin-top: 3px;
        }

        .badge-loc {
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .badge-loc.loc-verified {
            color: var(--att-success);
        }
        .badge-loc.loc-remote {
            color: var(--att-brand);
            background: var(--att-brand-light);
            border: 1px solid var(--att-brand-border);
            padding: 2px 8px;
            border-radius: var(--att-radius-pill);
        }
        .badge-loc.loc-exception {
            color: #7c3aed;
            background: rgba(124, 58, 237, 0.1);
            border: 1px solid rgba(124, 58, 237, 0.25);
            padding: 2px 8px;
            border-radius: var(--att-radius-pill);
        }
        .badge-loc.loc-missing {
            color: var(--att-text-muted);
            font-weight: 500;
        }

        .shift-schedule-text {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--att-text);
            white-space: nowrap;
        }
        .shift-schedule-sub {
            font-size: 0.75rem;
            color: var(--att-text-muted);
            font-weight: 500;
            display: block;
            margin-top: 2px;
        }
        .badge-custom-shift {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 1px 6px;
            border-radius: var(--att-radius-pill);
            font-size: 0.68rem;
            font-weight: 700;
            background: rgba(124, 58, 237, 0.1);
            color: #7c3aed;
            border: 1px solid rgba(124, 58, 237, 0.2);
            margin-top: 2px;
        }

        .text-missing-checkout {
            color: var(--att-warning);
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .hours-in-progress {
            color: #2563eb;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .hours-pending {
            color: #dc2626;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .history-sub-note {
            font-size: 0.75rem;
            color: var(--att-text-muted);
            font-weight: 500;
            margin-top: 2px;
        }

        .btn-view-summary {
            padding: 5px 12px;
            border-radius: var(--att-radius-pill);
            background: var(--att-brand-light);
            border: 1px solid var(--att-brand-border);
            color: var(--att-brand);
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: all var(--att-transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-view-summary:hover {
            background: var(--att-brand);
            color: #ffffff;
        }

        /* Mobile Attendance Cards (< 720px) */
        .mobile-history-list {
            display: none;
            padding: 16px;
            flex-direction: column;
            gap: 12px;
        }
        @media (max-width: 720px) {
            .table-responsive { display: none; }
            .mobile-history-list { display: flex; }
        }
        .mobile-card {
            background: var(--att-bg);
            border: 1px solid var(--att-border);
            border-radius: var(--att-radius-md);
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .mobile-card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .mobile-grid-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            padding-top: 8px;
            border-top: 1px solid var(--att-border-subtle);
        }
        .mobile-card-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.84rem;
            padding-top: 8px;
            border-top: 1px solid var(--att-border-subtle);
            gap: 8px;
        }

        /* ── Accessible Modal Dialogs ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(9, 13, 22, 0.72);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 16px;
        }
        .modal-dialog {
            background: var(--att-surface);
            border: 1px solid var(--att-border);
            border-radius: var(--att-radius-card);
            box-shadow: 0 20px 40px -8px rgba(0, 0, 0, 0.4);
            max-width: 520px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 28px;
            text-align: left;
            animation: modalFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--att-text);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-sub {
            font-size: 0.84rem;
            color: var(--att-text-muted);
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 0.84rem;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--att-text);
        }
        .form-label span.req { color: var(--att-danger); }

        .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--att-border);
            border-radius: var(--att-radius-md);
            background: var(--att-bg);
            color: var(--att-text);
            font-family: inherit;
            font-size: 0.9rem;
            line-height: 1.5;
            resize: vertical;
            outline: none;
            margin-bottom: 14px;
            transition: all var(--att-transition);
        }
        .form-textarea:focus {
            border-color: var(--att-brand);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .form-select {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border: 1.5px solid var(--att-border);
            border-radius: var(--att-radius-md);
            background: var(--att-bg);
            color: var(--att-text);
            font-family: inherit;
            font-size: 0.9rem;
            outline: none;
            margin-bottom: 14px;
            transition: all var(--att-transition);
        }
        .form-select:focus {
            border-color: var(--att-brand);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 18px;
        }
        .btn-cancel {
            padding: 9px 18px;
            border-radius: var(--att-radius-pill);
            background: var(--att-surface);
            border: 1px solid var(--att-border);
            color: var(--att-text);
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all var(--att-transition);
        }
        .btn-cancel:hover { background: var(--att-surface-subtle); }

        .btn-submit-modal {
            padding: 9px 22px;
            border-radius: var(--att-radius-pill);
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #ffffff;
            border: none;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.28);
            transition: all var(--att-transition);
        }
        .btn-submit-modal:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.38);
        }
        .btn-submit-modal:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ── Request Status Banners & Mismatch Dialog ── */
        .request-status-banner {
            border-radius: var(--att-radius-md);
            padding: 14px 18px;
            margin-bottom: 20px;
            border-width: 1.5px;
            border-style: solid;
            animation: modalFadeIn 0.25s ease;
        }
        .banner-pending {
            background: rgba(245, 158, 11, 0.08);
            border-color: rgba(245, 158, 11, 0.35);
        }
        .banner-approved {
            background: rgba(16, 185, 129, 0.08);
            border-color: rgba(16, 185, 129, 0.35);
        }
        .banner-rejected {
            background: rgba(239, 68, 68, 0.08);
            border-color: rgba(239, 68, 68, 0.35);
        }

        .btn-mismatch-option {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            border-radius: var(--att-radius-md);
            background: var(--att-surface-subtle);
            border: 1.5px solid var(--att-border);
            cursor: pointer;
            text-align: left;
            transition: all var(--att-transition);
            font-family: inherit;
        }
        .btn-mismatch-option:hover {
            border-color: var(--att-brand);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            background: var(--att-surface);
        }
        .option-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .btn-mismatch-cancel {
            width: 100%;
            padding: 12px;
            border-radius: var(--att-radius-pill);
            background: transparent;
            border: 1px solid var(--att-border);
            color: var(--att-text-muted);
            font-size: 0.86rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
            transition: all var(--att-transition);
        }
        .btn-mismatch-cancel:hover {
            color: var(--att-text);
            background: var(--att-surface-subtle);
        }

        /* ── Request Status Badges ── */
        .badge-req-pending {
            background: rgba(245, 158, 11, 0.12);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .badge-req-approved {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .badge-req-rejected {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .badge-req-cancelled {
            background: rgba(100, 116, 139, 0.12);
            color: #64748b;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }
        .badge-req-checked_in {
            background: rgba(79, 70, 229, 0.12);
            color: #4f46e5;
            border: 1px solid rgba(79, 70, 229, 0.3);
        }

        @media (max-width: 768px) {
            .main-container { padding: 20px 16px 48px; }
        }
        @media (max-width: 480px) {
            .main-container { padding: 16px 12px 48px; }
        }
    </style>
</head>
<body>

    <!-- Shared Header Navigation -->
    <?php include 'employee_header.php'; ?>

    <main class="main-container">

        <!-- Header Ribbon -->
        <header class="page-header">
            <div class="header-title-wrap">
                <h1>My Attendance</h1>
                <div class="header-meta">
                    <span><i class="fa-regular fa-calendar" style="color:var(--att-brand);"></i> <?php echo date('l, F j, Y'); ?></span>
                    <span>&bull;</span>
                    <span>Shift: <strong><?php echo date('h:i A', strtotime($shiftStartTime)); ?> &ndash; <?php echo date('h:i A', strtotime($shiftEndTime)); ?></strong></span>
                </div>
            </div>

            <div style="display: flex; gap: 8px; align-items: center;">
                <?php if ($workMode === 'on_site'): ?>
                    <span class="badge-pill badge-onsite" title="Office Geofence Applied">
                        <i class="fa-solid fa-building"></i>
                        <span>On-Site &bull; Geofence <?php echo (int)($officeSettings['office_radius_meters'] ?? 100); ?>m</span>
                    </span>
                <?php else: ?>
                    <span class="badge-pill badge-wfh" title="Remote Attendance Enabled">
                        <i class="fa-solid fa-house-laptop"></i>
                        <span>Work From Home &bull; Remote</span>
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <!-- Feedback Alert Banner (PRG) -->
        <?php if (!empty($flashMessage)): ?>
            <div class="toast-banner toast-<?php echo ($flashType === 'success') ? 'success' : 'error'; ?>" role="alert">
                <i class="fa-solid <?php echo ($flashType === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?php echo htmlspecialchars($flashMessage); ?></span>
            </div>
        <?php endif; ?>

        <!-- GPS Real-Time Status Notice Box -->
        <div id="gpsNoticeBox" class="gps-status-box" role="alert"></div>

        <!-- Hidden Check-In / Check-Out Submission Form -->
        <form id="attendanceActionForm" method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" id="actionField" value="">
            <input type="hidden" name="latitude" id="latitudeField" value="">
            <input type="hidden" name="longitude" id="longitudeField" value="">
        </form>

        <!-- Today's Attendance Request Status Banner -->
        <?php if ($todayRequest): ?>
            <?php if ($todayRequest['status'] === 'pending'): ?>
                <div class="request-status-banner banner-pending">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px; font-weight:800; font-size:0.95rem; color:#92400e;">
                                <i class="fa-solid fa-hourglass-half fa-spin"></i>
                                <span><?php echo ($todayRequest['requested_work_mode'] === 'wfh') ? 'Work From Home Request Submitted' : 'On-Site Exception Request Submitted'; ?></span>
                            </div>
                            <p style="font-size:0.84rem; color:#b45309; margin-top:4px; line-height:1.4;">
                                Awaiting HR/CEO approval for requested check-in at <strong><?php echo date('h:i A', strtotime($todayRequest['requested_check_in_time'])); ?></strong>.
                                <br><span style="font-size:0.78rem; opacity:0.9;">Reason: &ldquo;<?php echo htmlspecialchars($todayRequest['reason']); ?>&rdquo;</span>
                            </p>
                        </div>
                        <form method="POST" action="" onsubmit="return confirm('Cancel this pending attendance request?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="cancel_request">
                            <input type="hidden" name="request_id" value="<?php echo (int)$todayRequest['id']; ?>">
                            <button type="submit" class="btn-cancel" style="padding:5px 12px; font-size:0.78rem; color:#dc2626; border-color:#fca5a5; display:inline-flex; align-items:center; gap:5px;">
                                <i class="fa-solid fa-xmark"></i> Cancel Request
                            </button>
                        </form>
                    </div>
                </div>
            <?php elseif ($todayRequest['status'] === 'approved'): ?>
                <div class="request-status-banner banner-approved">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px; font-weight:800; font-size:0.95rem; color:#065f46;">
                                <i class="fa-solid fa-circle-check"></i>
                                <span><?php echo ($todayRequest['requested_work_mode'] === 'wfh') ? 'Work From Home Approved' : 'On-Site Exception Approved'; ?></span>
                                <?php if (!empty($todayRequest['approver_username'])): ?>
                                    <span style="font-size:0.75rem; font-weight:600; opacity:0.85;">&bull; Approved by <?php echo htmlspecialchars($todayRequest['approver_username']); ?></span>
                                <?php endif; ?>
                            </div>
                            <p style="font-size:0.84rem; color:#047857; margin-top:4px;">
                                Approved Check-In Time: <strong><?php echo date('h:i A', strtotime($todayRequest['approved_check_in_time'])); ?></strong>
                                <?php if ($scheduledWindowCheck): ?>
                                    &bull; <em><?php echo htmlspecialchars($scheduledWindowCheck['message'] ?? ''); ?></em>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div>
                            <span class="badge-status status-present" style="font-size:0.8rem; padding:5px 12px;">
                                <i class="fa-solid fa-calendar-check"></i> Scheduled for <?php echo date('h:i A', strtotime($todayRequest['approved_check_in_time'])); ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php elseif ($todayRequest['status'] === 'rejected'): ?>
                <div class="request-status-banner banner-rejected">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px; font-weight:800; font-size:0.95rem; color:#991b1b;">
                                <i class="fa-solid fa-circle-xmark"></i>
                                <span>Attendance Request Rejected</span>
                            </div>
                            <p style="font-size:0.84rem; color:#b91c1c; margin-top:4px;">
                                <strong>Rejection Reason:</strong> <?php echo htmlspecialchars($todayRequest['rejection_reason'] ?: 'No specific reason provided.'); ?>
                            </p>
                        </div>
                        <button type="button" class="btn-cancel" style="padding:5px 12px; font-size:0.78rem; font-weight:700; color:var(--att-brand); border-color:var(--att-brand);" onclick="openRequestModal('wfh')">
                            <i class="fa-solid fa-arrow-rotate-right"></i> Submit New Request
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Real-Time Live Cutoff Warning Banner -->
        <div id="portalCutoffWarningBanner" class="portal-warning-box"></div>
        <div id="portalClosureLiveAlert" class="portal-closure-box" style="display:none; margin-bottom:24px;">
            <i class="fa-solid fa-lock" style="color:var(--att-danger); font-size:1.35rem; margin-top:2px;"></i>
            <div>
                <div style="font-weight:800; font-size:1rem; color:var(--att-danger); line-height:1.4;">
                    Your scheduled work hours have ended. Attendance access is now closed for today.
                </div>
                <div style="font-size:0.88rem; color:var(--att-text-muted); margin-top:4px; line-height:1.45;">
                    The scheduled check-out time has arrived. Attendance actions have been automatically closed for today.
                </div>
            </div>
        </div>

        <!-- Primary Hero Attendance Terminal Card -->
        <section class="hero-terminal-card <?php echo $portalClosure['is_closed'] ? 'portal-closed-card' : ''; ?>" aria-label="Today's Attendance Status">
            <div class="terminal-grid">
                
                <!-- Left: Status Display -->
                <div>
                    <div class="status-eyebrow">
                        <?php if ($portalClosure['is_closed']): ?>
                            <span class="pulse-dot pulse-idle" style="background:var(--att-danger);"></span>
                            <span style="color:var(--att-danger); font-weight:700;">Shift Concluded &bull; Portal Closed</span>
                        <?php elseif ($todayState === 'checked_in'): ?>
                            <span class="pulse-dot pulse-active"></span>
                            <span style="color:var(--att-success);">Current Status &bull; Active Shift</span>
                        <?php elseif ($todayState === 'checked_out'): ?>
                            <span class="pulse-dot pulse-idle"></span>
                            <span>Shift Completed Today</span>
                        <?php else: ?>
                            <span class="pulse-dot pulse-idle"></span>
                            <span>Today's Shift Status</span>
                        <?php endif; ?>
                    </div>

                    <div class="status-title">
                        <?php if ($portalClosure['is_closed']): ?>
                            <?php if ($portalClosure['needs_hr_review']): ?>
                                Shift Cutoff Reached &bull; Preserved for HR Review
                            <?php elseif ($todaysRecord && !empty($todaysRecord['check_out'])): ?>
                                Shift Concluded at <?php echo date('h:i A', strtotime($todaysRecord['check_out'])); ?>
                            <?php else: ?>
                                Scheduled Work Hours Have Ended
                            <?php endif; ?>
                        <?php elseif ($todayState === 'checked_in'): ?>
                            On Duty &bull; Punched In at <?php echo date('h:i A', strtotime($currentPunchIn)); ?>
                        <?php elseif ($todayState === 'checked_out'): ?>
                            Shift Concluded at <?php echo date('h:i A', strtotime($todaysRecord['check_out'])); ?>
                        <?php else: ?>
                            Ready to Begin Your Shift
                        <?php endif; ?>
                    </div>

                    <?php if ($portalClosure['is_closed']): ?>
                        <div class="portal-closure-box">
                            <i class="fa-solid fa-lock" style="color:var(--att-danger); font-size:1.35rem; margin-top:2px;"></i>
                            <div>
                                <div style="font-weight:800; font-size:1rem; color:var(--att-danger); line-height:1.4;">
                                    Your scheduled work hours have ended. Attendance access is now closed for today.
                                </div>
                                <div style="font-size:0.88rem; color:var(--att-text-muted); margin-top:4px; line-height:1.45;">
                                    <?php echo htmlspecialchars($portalClosure['details']); ?>
                                </div>
                                <?php if ($portalClosure['needs_hr_review']): ?>
                                    <div style="margin-top:8px;">
                                        <span class="badge-flagged-review"><i class="fa-solid fa-clock-rotate-left"></i> Flagged for HR Review &bull; Check-in preserved</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="status-details-row">
                        <div class="detail-item">
                            <span class="detail-label">Today's Date</span>
                            <span class="detail-val"><?php echo date('M d, Y'); ?></span>
                        </div>

                        <div class="detail-item">
                            <span class="detail-label">Scheduled Cutoff</span>
                            <span class="detail-val" style="color:var(--att-text); font-weight:700;">
                                <i class="fa-solid fa-clock"></i> <?php echo $portalClosure['scheduled_checkout_time']; ?>
                            </span>
                        </div>

                        <?php if (!$portalClosure['is_closed']): ?>
                            <div class="detail-item">
                                <span class="detail-label">Remaining Time</span>
                                <span class="detail-val" id="portalRemainingTimeDisplay" style="color:var(--att-brand); font-weight:700; font-variant-numeric:tabular-nums;">
                                    <?php echo $portalClosure['time_remaining_text']; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($todaysRecord && !empty($todaysRecord['check_in'])): ?>
                            <div class="detail-item">
                                <span class="detail-label">Check-In Time</span>
                                <span class="detail-val"><?php echo date('h:i A', strtotime($todaysRecord['check_in'])); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($todaysRecord && !empty($todaysRecord['check_out'])): ?>
                            <div class="detail-item">
                                <span class="detail-label">Check-Out Time</span>
                                <span class="detail-val"><?php echo date('h:i A', strtotime($todaysRecord['check_out'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Total Work Duration</span>
                                <span class="detail-val" style="color:var(--att-brand);">
                                    <?php echo (float)$todaysRecord['working_hours']; ?> hours
                                </span>
                            </div>
                        <?php elseif ($portalClosure['needs_hr_review']): ?>
                            <div class="detail-item">
                                <span class="detail-label">Check-Out Status</span>
                                <span class="detail-val" style="color:var(--att-warning); font-weight:700;">
                                    Pending HR Review
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="detail-item">
                            <span class="detail-label">Location Verification</span>
                            <span class="detail-val" style="font-size:0.92rem;">
                                <?php if ($todayApprovedReq && $todayApprovedReq['requested_work_mode'] === 'wfh'): ?>
                                    <span style="color:var(--att-brand); font-weight:700;"><i class="fa-solid fa-house-laptop"></i> Approved Remote (WFH)</span>
                                <?php elseif ($todayApprovedReq && $todayApprovedReq['requested_work_mode'] === 'on_site_exception'): ?>
                                    <span style="color:#059669; font-weight:700;"><i class="fa-solid fa-shield-halved"></i> Approved Location Exception</span>
                                <?php elseif ($workMode === 'wfh'): ?>
                                    <span style="color:var(--att-brand); font-weight:700;"><i class="fa-solid fa-house-laptop"></i> Remote (WFH)</span>
                                <?php elseif ($todaysRecord && $todaysRecord['check_in_dist'] !== null): ?>
                                    <span style="color:var(--att-success); font-weight:700;"><i class="fa-solid fa-circle-check"></i> Verified (<?php echo (int)$todaysRecord['check_in_dist']; ?>m)</span>
                                <?php elseif (empty($officeSettings['location_attendance_enabled'])): ?>
                                    <span style="color:var(--att-text-muted); font-weight:700;">Geofence Disabled</span>
                                <?php else: ?>
                                    <span style="color:var(--att-text-muted);">Required upon punch</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Right: Single Primary Action -->
                <div class="primary-action-wrap">
                    <?php if ($portalClosure['is_closed']): ?>
                        <div style="display:flex; flex-direction:column; gap:8px; align-items:flex-end;">
                            <button type="button" class="btn-punch btn-punch-closed" disabled>
                                <i class="fa-solid fa-lock"></i>
                                <span>Portal Access Closed</span>
                            </button>
                            <div class="action-subtext" style="color:var(--att-danger); font-weight:600;">
                                <i class="fa-solid fa-ban"></i> Attendance access is now closed for today
                            </div>
                            <?php if ($todaysRecord && (!empty($todaysRecord['work_summary']) || !empty($todaysRecord['completed_tasks']))): ?>
                                <button type="button" class="btn-view-summary" style="padding: 10px 18px; font-size: 0.88rem; margin-top:4px;" onclick="openViewSummaryModal(<?php echo htmlspecialchars(json_encode([
                                    'date'            => date('D, M j, Y', strtotime($todaysRecord['date'])),
                                    'status'          => ucfirst(str_replace('_', ' ', $todaysRecord['attendance_status'] ?? ($todaysRecord['status'] ?? 'present'))),
                                    'check_in'        => date('h:i A', strtotime($todaysRecord['check_in'])),
                                    'check_out'       => !empty($todaysRecord['check_out']) ? date('h:i A', strtotime($todaysRecord['check_out'])) : 'N/A (Flagged for HR)',
                                    'hours'           => ($todaysRecord['working_hours'] ?: '0.00') . ' hrs',
                                    'work_summary'    => $todaysRecord['work_summary'] ?? '',
                                    'completed_tasks' => $todaysRecord['completed_tasks'] ?? '',
                                    'pending_tasks'   => $todaysRecord['pending_tasks'] ?? '',
                                    'leave_code'      => $todaysRecord['leave_code'] ?? '',
                                    'check_in_dist'   => $todaysRecord['check_in_dist'] ?? null,
                                    'check_out_dist'  => $todaysRecord['check_out_dist'] ?? null
                                ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="fa-solid fa-file-lines"></i> View Today's Summary
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($todayState === 'not_checked_in'): ?>
                        <?php if ($todayRequest && $todayRequest['status'] === 'pending'): ?>
                            <button type="button" class="btn-punch" style="background:var(--att-surface-subtle); color:var(--att-text-muted); border:1.5px solid var(--att-border); cursor:not-allowed;" disabled>
                                <i class="fa-solid fa-hourglass-half"></i>
                                <span>Awaiting Approval</span>
                            </button>
                            <div class="action-subtext">
                                <i class="fa-solid fa-info-circle"></i> WFH request pending review by HR/CEO
                            </div>
                        <?php elseif ($todayApprovedReq && $scheduledWindowCheck): ?>
                            <?php if ($scheduledWindowCheck['status'] === 'too_early'): ?>
                                <button type="button" class="btn-punch" style="background:var(--att-surface-subtle); color:var(--att-text-muted); border:1.5px solid var(--att-border); cursor:not-allowed;" disabled title="Window opens at <?php echo $scheduledWindowCheck['window_opens_at']; ?>">
                                    <i class="fa-solid fa-lock"></i>
                                    <span>Check In at <?php echo $scheduledWindowCheck['approved_time']; ?></span>
                                </button>
                                <div class="action-subtext" style="color:var(--att-warning);">
                                    <i class="fa-solid fa-clock"></i> Window opens at <?php echo $scheduledWindowCheck['window_opens_at']; ?> (<?php echo $scheduledWindowCheck['minutes_remaining']; ?>m left)
                                </div>
                            <?php elseif ($scheduledWindowCheck['status'] === 'expired'): ?>
                                <button type="button" class="btn-punch" style="background:var(--att-surface-subtle); color:var(--att-danger); border:1.5px solid var(--att-danger-border); cursor:not-allowed;" disabled>
                                    <i class="fa-solid fa-ban"></i>
                                    <span>Window Expired</span>
                                </button>
                                <div class="action-subtext">
                                    <a href="javascript:void(0)" onclick="openRequestModal('wfh')" style="color:var(--att-brand); font-weight:700; text-decoration:underline;">Submit new request</a>
                                </div>
                            <?php else: ?>
                                <button type="button" id="btnCheckIn" class="btn-punch btn-check-in" <?php echo ($scheduledWindowCheck['status'] === 'delayed') ? 'style="background:linear-gradient(135deg, #d97706, #f59e0b);"' : ''; ?> onclick="triggerCheckIn()">
                                    <i class="fa-solid fa-fingerprint"></i>
                                    <span>Check In Now (<?php echo date('h:i A', strtotime($todayApprovedReq['approved_check_in_time'])); ?>)</span>
                                </button>
                                <div class="action-subtext">
                                    <?php if ($scheduledWindowCheck['status'] === 'delayed'): ?>
                                        <i class="fa-solid fa-triangle-exclamation" style="color:var(--att-warning);"></i> Late window active (delayed by <?php echo $scheduledWindowCheck['delay_minutes']; ?>m)
                                    <?php else: ?>
                                        <i class="fa-solid fa-circle-check" style="color:var(--att-success);"></i> Approved scheduled time active
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" id="btnCheckIn" class="btn-punch btn-check-in" onclick="triggerCheckIn()">
                                <i class="fa-solid fa-fingerprint"></i>
                                <span>Check In Now</span>
                            </button>
                            <div class="action-subtext">
                                <?php if ($workMode === 'on_site' && !empty($officeSettings['location_attendance_enabled'])): ?>
                                    <i class="fa-solid fa-location-crosshairs"></i> Requires GPS location within <?php echo (int)($officeSettings['office_radius_meters'] ?? 100); ?>m of office
                                <?php else: ?>
                                    <i class="fa-solid fa-house-laptop"></i> Work from home mode &bull; No geofence restriction
                                <?php endif; ?>
                            </div>
                            <?php if ($workMode === 'on_site'): ?>
                                <button type="button" onclick="openRequestModal('wfh')" style="background:transparent; border:none; color:var(--att-brand); font-weight:700; font-size:0.82rem; cursor:pointer; margin-top:8px; display:inline-flex; align-items:center; gap:5px; text-decoration:none;">
                                    <i class="fa-solid fa-house-laptop"></i> Need to work from home? Submit Request
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>

                    <?php elseif ($todayState === 'checked_in'): ?>
                        <button type="button" id="btnOpenCheckoutModal" class="btn-punch btn-check-out" onclick="openCheckoutModal()">
                            <i class="fa-solid fa-right-from-bracket"></i>
                            <span>Check Out &amp; Summary</span>
                        </button>
                        <div class="action-subtext">
                            <i class="fa-solid fa-clipboard-check"></i> Daily work summary is required on checkout
                        </div>

                    <?php else: ?>
                        <div style="display: flex; gap: 8px;">
                            <?php if (!empty($todaysRecord['work_summary']) || !empty($todaysRecord['completed_tasks'])): ?>
                                <button type="button" class="btn-view-summary" style="padding: 10px 18px; font-size: 0.88rem;" onclick="openViewSummaryModal(<?php echo htmlspecialchars(json_encode([
                                    'date'            => date('D, M j, Y', strtotime($todaysRecord['date'])),
                                    'status'          => ucfirst(str_replace('_', ' ', $todaysRecord['attendance_status'] ?? ($todaysRecord['status'] ?? 'present'))),
                                    'check_in'        => date('h:i A', strtotime($todaysRecord['check_in'])),
                                    'check_out'       => date('h:i A', strtotime($todaysRecord['check_out'])),
                                    'hours'           => $todaysRecord['working_hours'] . ' hrs',
                                    'work_summary'    => $todaysRecord['work_summary'] ?? '',
                                    'completed_tasks' => $todaysRecord['completed_tasks'] ?? '',
                                    'pending_tasks'   => $todaysRecord['pending_tasks'] ?? '',
                                    'leave_code'      => $todaysRecord['leave_code'] ?? '',
                                    'check_in_dist'   => $todaysRecord['check_in_dist'] ?? null,
                                    'check_out_dist'  => $todaysRecord['check_out_dist'] ?? null
                                ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="fa-solid fa-file-lines"></i> View Today's Summary
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </section>

        <!-- Location Mismatch Modal (Geofence Outside Trigger) -->
        <div class="modal-overlay" id="locationMismatchModal" role="dialog" aria-modal="true" aria-labelledby="mismatchModalTitle">
            <div class="modal-dialog" style="max-width: 480px; text-align: center;">
                <div style="width: 58px; height: 58px; border-radius: 50%; background: var(--att-warning-light); color: var(--att-warning); display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 16px;">
                    <i class="fa-solid fa-location-crosshairs"></i>
                </div>

                <h2 class="modal-title" id="mismatchModalTitle" style="justify-content: center; font-size: 1.35rem; margin-bottom: 8px;">
                    You're Outside the Office Location
                </h2>

                <p style="font-size: 0.92rem; color: var(--att-text-muted); line-height: 1.5; margin-bottom: 14px;">
                    Your current location does not match the configured office location. Choose how you want to work today.
                </p>

                <div id="mismatchDistanceText" style="display:none; font-size: 0.82rem; font-weight: 700; color: var(--att-danger); background: var(--att-danger-light); padding: 8px 12px; border-radius: var(--att-radius-sm); margin-bottom: 18px; border: 1px solid var(--att-danger-border);"></div>

                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <!-- Option 1: On-Site Exception -->
                    <button type="button" class="btn-mismatch-option" onclick="openRequestModal('on_site_exception')">
                        <div class="option-icon" style="background: rgba(79, 70, 229, 0.1); color: var(--att-brand);">
                            <i class="fa-solid fa-building-circle-exclamation"></i>
                        </div>
                        <div>
                            <div style="font-weight: 800; font-size: 0.95rem; color: var(--att-text);">On-site</div>
                            <div style="font-size: 0.8rem; color: var(--att-text-muted); line-height: 1.4;">Request attendance assistance or report an office-location issue.</div>
                        </div>
                    </button>

                    <!-- Option 2: Work From Home -->
                    <button type="button" class="btn-mismatch-option" onclick="openRequestModal('wfh')">
                        <div class="option-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--att-success);">
                            <i class="fa-solid fa-house-laptop"></i>
                        </div>
                        <div>
                            <div style="font-weight: 800; font-size: 0.95rem; color: var(--att-text);">Work From Home</div>
                            <div style="font-size: 0.8rem; color: var(--att-text-muted); line-height: 1.4;">Submit a WFH attendance request for HR/CEO approval.</div>
                        </div>
                    </button>

                    <!-- Option 3: Cancel -->
                    <button type="button" class="btn-mismatch-cancel" onclick="closeLocationMismatchModal()">
                        Cancel &mdash; Return to the attendance dashboard
                    </button>
                </div>
            </div>
        </div>

        <!-- WFH & On-Site Exception Request Modal -->
        <div class="modal-overlay" id="attendanceRequestModal" role="dialog" aria-modal="true" aria-labelledby="reqModalTitle">
            <div class="modal-dialog">
                <form id="attendanceRequestForm" method="POST" action="" onsubmit="return handleRequestSubmit(event)">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="submit_request">
                    <input type="hidden" name="requested_work_mode" id="reqWorkModeField" value="wfh">
                    <input type="hidden" name="latitude" id="reqLatField" value="">
                    <input type="hidden" name="longitude" id="reqLngField" value="">
                    <input type="hidden" name="distance" id="reqDistField" value="">

                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px;">
                        <h2 class="modal-title" id="reqModalTitle">
                            <i class="fa-solid fa-house-laptop" id="reqModalIcon" style="color:var(--att-brand);"></i>
                            <span id="reqModalHeading">Submit Work From Home Request</span>
                        </h2>
                        <button type="button" class="btn-cancel" style="padding: 2px 8px; font-size: 0.9rem;" onclick="closeRequestModal()">&times;</button>
                    </div>

                    <p class="modal-sub" id="reqModalSub">
                        Submit request for today (<?php echo date('M d, Y'); ?>) to HR &amp; CEO for review and approval.
                    </p>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                        <div>
                            <label class="form-label">Attendance Date</label>
                            <input type="text" class="form-select" value="<?php echo date('Y-m-d'); ?>" readonly style="background:var(--att-surface-subtle); color:var(--att-text-muted); cursor:not-allowed;">
                        </div>
                        <div>
                            <label for="reqCheckInTime" class="form-label">Requested Check-In Time <span class="req">*</span></label>
                            <input type="time" id="reqCheckInTime" name="requested_check_in_time" class="form-select" value="<?php echo date('H:i'); ?>" required>
                        </div>
                    </div>

                    <div>
                        <label for="reqReason" class="form-label"><span id="reqReasonLabel">Reason for Working From Home</span> <span class="req">*</span></label>
                        <textarea id="reqReason" name="reason" class="form-textarea" rows="3" required placeholder="Provide clear business or personal justification for your request..."></textarea>
                    </div>

                    <div>
                        <label for="reqDetails" class="form-label">Supporting Details / Location Notes <span style="color:var(--att-text-muted); font-weight:400;">(Optional)</span></label>
                        <textarea id="reqDetails" name="supporting_details" class="form-textarea" rows="2" placeholder="e.g. Current location, client office visited, or pending tasks planned for today..."></textarea>
                    </div>

                    <div id="reqModalError" style="display:none; padding:10px 14px; border-radius:var(--att-radius-md); background:var(--att-danger-light); color:var(--att-danger); border:1px solid var(--att-danger-border); font-size:0.84rem; font-weight:700; margin-bottom:12px;"></div>

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" onclick="closeRequestModal()">Cancel</button>
                        <button type="submit" id="btnSubmitRequest" class="btn-submit-modal">
                            <i class="fa-solid fa-paper-plane"></i>
                            <span>Submit for Approval</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Check-Out & Daily Work Summary Modal -->
        <div class="modal-overlay" id="checkoutModal" role="dialog" aria-modal="true" aria-labelledby="checkoutModalTitle">
            <div class="modal-dialog">
                <form id="checkoutForm" method="POST" action="" onsubmit="return handleCheckoutSubmit(event)">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="check_out">
                    <input type="hidden" name="latitude" id="checkoutLatField" value="">
                    <input type="hidden" name="longitude" id="checkoutLngField" value="">

                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px;">
                        <h2 class="modal-title" id="checkoutModalTitle">
                            <i class="fa-solid fa-clipboard-check" style="color:var(--att-brand);"></i>
                            <span>Daily Work Summary &amp; Check-Out</span>
                        </h2>
                        <button type="button" class="btn-cancel" style="padding: 2px 8px; font-size: 0.9rem;" onclick="closeCheckoutModal()">&times;</button>
                    </div>

                    <p class="modal-sub">
                        Employee: <strong><?php echo htmlspecialchars($empFullName); ?></strong> &bull; Date: <strong><?php echo date('M d, Y'); ?></strong>
                    </p>

                    <!-- Early Shift Notice (if leaving before shift end) -->
                    <?php if ($isEarly): ?>
                        <div style="background: var(--att-warning-light); border: 1px solid var(--att-warning-border); padding: 12px 14px; border-radius: var(--att-radius-md); margin-bottom: 16px;">
                            <div style="font-size: 0.84rem; font-weight: 700; color: var(--att-warning); margin-bottom: 4px;">
                                <i class="fa-solid fa-person-walking-arrow-right"></i> Early Departure Notice
                            </div>
                            <div style="font-size: 0.8rem; color: var(--att-text-muted); margin-bottom: 8px;">
                                Standard shift concludes at <strong><?php echo date('h:i A', strtotime($shiftEndTime)); ?></strong>. Please select authorization reason:
                            </div>
                            <select name="leave_code" id="checkout_leave_code" class="form-select" style="margin-bottom:0;" required>
                                <option value="">-- Select Early Departure Reason --</option>
                                <option value="Personal Emergency">Personal Emergency</option>
                                <option value="Medical Issue">Medical Issue / Doctor Visit</option>
                                <option value="Family Responsibility">Family Responsibility</option>
                                <option value="Approved Half Day">Approved Half Day</option>
                                <option value="Client Field Visit">Client Field Visit</option>
                                <option value="Other Authorized">Other Authorized Reason</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label for="work_summary" class="form-label">Tasks Worked On Today <span class="req">*</span></label>
                        <textarea id="work_summary" name="work_summary" class="form-textarea" rows="3" required placeholder="Outline key tasks, modules, tickets, or client projects handled today..."></textarea>
                    </div>

                    <div>
                        <label for="completed_tasks" class="form-label">Tasks Completed Today <span class="req">*</span></label>
                        <textarea id="completed_tasks" name="completed_tasks" class="form-textarea" rows="3" required placeholder="List deliverables completed, bug fixes merged, or closed milestones..."></textarea>
                    </div>

                    <div>
                        <label for="pending_tasks" class="form-label">Pending Tasks / Blockers <span style="color:var(--att-text-muted); font-weight:400;">(Optional)</span></label>
                        <textarea id="pending_tasks" name="pending_tasks" class="form-textarea" rows="2" placeholder="List any unfinished tasks, pending reviews, or blockers for tomorrow..."></textarea>
                    </div>

                    <div id="checkoutModalError" style="display:none; padding:10px 14px; border-radius:var(--att-radius-md); background:var(--att-danger-light); color:var(--att-danger); border:1px solid var(--att-danger-border); font-size:0.84rem; font-weight:700; margin-bottom:12px;"></div>

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" onclick="closeCheckoutModal()">Cancel</button>
                        <button type="submit" id="btnConfirmCheckout" class="btn-submit-modal">
                            <i class="fa-solid fa-check-circle"></i>
                            <span>Submit &amp; Check Out</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- View Daily Work Summary Modal -->
        <div class="modal-overlay" id="viewSummaryModal" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">
            <div class="modal-dialog">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                    <div>
                        <h2 class="modal-title" id="viewModalTitle">
                            <i class="fa-solid fa-file-lines" style="color:var(--att-brand);"></i>
                            <span>Daily Work Record</span>
                        </h2>
                        <p class="modal-sub" id="viewSummaryMeta" style="margin-bottom:0;"></p>
                    </div>
                    <button type="button" class="btn-cancel" style="padding:2px 8px; font-size:0.9rem;" onclick="closeViewSummaryModal()">&times;</button>
                </div>

                <div style="margin-bottom: 14px;">
                    <span class="form-label"><i class="fa-solid fa-list-check" style="color:var(--att-brand);"></i> Tasks Worked On</span>
                    <div id="viewWorkSummaryText" style="background:var(--att-bg); border:1px solid var(--att-border); border-radius:var(--att-radius-md); padding:10px 14px; font-size:0.88rem; line-height:1.5; white-space:pre-wrap;"></div>
                </div>

                <div style="margin-bottom: 14px;">
                    <span class="form-label"><i class="fa-solid fa-circle-check" style="color:var(--att-success);"></i> Tasks Completed</span>
                    <div id="viewCompletedTasksText" style="background:var(--att-bg); border:1px solid var(--att-border); border-radius:var(--att-radius-md); padding:10px 14px; font-size:0.88rem; line-height:1.5; white-space:pre-wrap;"></div>
                </div>

                <div id="viewPendingTasksWrap" style="margin-bottom: 14px; display:none;">
                    <span class="form-label"><i class="fa-solid fa-hourglass-half" style="color:var(--att-warning);"></i> Pending Tasks / Blockers</span>
                    <div id="viewPendingTasksText" style="background:var(--att-bg); border:1px solid var(--att-border); border-radius:var(--att-radius-md); padding:10px 14px; font-size:0.88rem; line-height:1.5; white-space:pre-wrap;"></div>
                </div>

                <div id="viewGpsWrap" style="font-size:0.8rem; color:var(--att-text-muted); border-top:1px dashed var(--att-border); padding-top:10px; margin-top:10px; display:none;"></div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeViewSummaryModal()">Close</button>
                </div>
            </div>
        </div>

        <!-- Recent Attendance History Table -->
        <section class="history-card" aria-label="Recent Attendance History">
            <div class="history-header">
                <h2 class="history-title">
                    <i class="fa-solid fa-clock-rotate-left" style="color:var(--att-brand);"></i>
                    <span>Recent Attendance Records (Last 30 Days)</span>
                </h2>
                <span style="font-size:0.84rem; color:var(--att-text-muted); font-weight:600;">
                    <?php echo count($history); ?> entries logged
                </span>
            </div>

            <?php if (empty($history)): ?>
                <div style="padding: 48px 24px; text-align: center; color: var(--att-text-muted);">
                    <i class="fa-solid fa-calendar-xmark" style="font-size: 2rem; margin-bottom: 12px; opacity: 0.5;"></i>
                    <p style="font-weight: 600;">No attendance records found yet.</p>
                </div>
            <?php else: ?>
                <!-- Desktop Table View (>= 720px) -->
                <div class="table-responsive">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Scheduled Shift</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Working Hours</th>
                                <th>Location Info</th>
                                <th>Daily Summary</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): 
                                $disp = AttendanceHelper::formatRecentAttendanceRecord($row, $portalClosure['is_closed'], $today);
                            ?>
                                <tr>
                                    <!-- 1. Date -->
                                    <td style="font-weight: 700; white-space: nowrap;">
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <i class="fa-regular fa-calendar" style="color:var(--att-text-muted); font-size:0.85rem;"></i>
                                            <span><?php echo htmlspecialchars($disp['date_formatted']); ?></span>
                                        </div>
                                        <?php if ($disp['is_overnight']): ?>
                                            <div class="badge-overnight" title="Overnight shift spans into <?php echo htmlspecialchars($disp['overnight_next_long']); ?>">
                                                <i class="fa-solid fa-moon"></i> Overnight &rarr; <?php echo htmlspecialchars($disp['overnight_next_short']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['leave_code'])): ?>
                                            <div class="history-sub-note">Note: <?php echo htmlspecialchars($row['leave_code']); ?></div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 2. Status -->
                                    <td>
                                        <span class="badge-status status-<?php echo htmlspecialchars($disp['status_key']); ?>">
                                            <i class="<?php echo htmlspecialchars($disp['status_icon']); ?>" style="font-size:0.55rem;"></i>
                                            <?php echo htmlspecialchars($disp['status_label']); ?>
                                        </span>
                                        <?php if (!empty($row['flag_reason']) && $disp['status_key'] === 'pending_hr_review'): ?>
                                            <div class="history-sub-note" style="color:#dc2626;" title="<?php echo htmlspecialchars($row['flag_reason']); ?>">
                                                <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars(mb_strimwidth($row['flag_reason'], 0, 22, '...')); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 3. Scheduled Shift -->
                                    <td>
                                        <?php if ($disp['has_schedule']): ?>
                                            <div class="shift-schedule-text"><?php echo htmlspecialchars($disp['shift_schedule_text']); ?></div>
                                            <span class="shift-schedule-sub"><?php echo htmlspecialchars($disp['shift_name']); ?></span>
                                            <?php if (!empty($disp['shift_exception_mode'])): ?>
                                                <span class="badge-custom-shift"><i class="fa-solid fa-user-check"></i> <?php echo htmlspecialchars($disp['shift_exception_mode']); ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:var(--att-text-muted); font-style:italic; font-size:0.85rem;">Schedule unavailable</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 4. Check In -->
                                    <td style="font-weight: 600; white-space: nowrap;">
                                        <?php echo htmlspecialchars($disp['check_in_formatted']); ?>
                                    </td>

                                    <!-- 5. Check Out -->
                                    <td style="font-weight: 600; white-space: nowrap;">
                                        <?php if ($disp['has_check_out']): ?>
                                            <?php echo htmlspecialchars($disp['check_out_formatted']); ?>
                                        <?php elseif ($disp['has_check_in']): ?>
                                            <span class="text-missing-checkout"><i class="fa-regular fa-clock"></i> Not checked out</span>
                                        <?php else: ?>
                                            <span style="color:var(--att-text-muted);">&mdash;</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 6. Working Hours -->
                                    <td style="font-weight: 700; white-space: nowrap;">
                                        <?php if (!$disp['has_check_in']): ?>
                                            <span style="color:var(--att-text-muted);">&mdash;</span>
                                        <?php elseif (!$disp['has_check_out']): ?>
                                            <?php if ($disp['status_key'] === 'in_progress'): ?>
                                                <span class="hours-in-progress"><i class="fa-solid fa-spinner fa-spin"></i> In progress</span>
                                            <?php else: ?>
                                                <span class="hours-pending"><i class="fa-solid fa-hourglass-half"></i> Pending checkout</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:var(--att-text);"><?php echo htmlspecialchars($disp['working_hours_display']); ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 7. Location Info -->
                                    <td style="font-size: 0.82rem; white-space: nowrap;">
                                        <?php if ($disp['loc_type'] === 'verified'): ?>
                                            <div class="badge-loc loc-verified"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($disp['loc_text']); ?></div>
                                            <?php if (!empty($disp['loc_sub'])): ?>
                                                <div class="shift-schedule-sub"><?php echo htmlspecialchars($disp['loc_sub']); ?></div>
                                            <?php endif; ?>
                                        <?php elseif ($disp['loc_type'] === 'remote'): ?>
                                            <span class="badge-loc loc-remote"><i class="fa-solid fa-house-laptop"></i> <?php echo htmlspecialchars($disp['loc_text']); ?></span>
                                        <?php elseif ($disp['loc_type'] === 'exception'): ?>
                                            <span class="badge-loc loc-exception"><i class="fa-solid fa-briefcase"></i> <?php echo htmlspecialchars($disp['loc_text']); ?></span>
                                        <?php else: ?>
                                            <span class="badge-loc loc-missing"><i class="fa-solid fa-location-slash"></i> GPS not recorded</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 8. Daily Summary -->
                                    <td>
                                        <?php if ($disp['has_summary']): ?>
                                            <button type="button" class="btn-view-summary" onclick="openViewSummaryModal(<?php echo htmlspecialchars(json_encode([
                                                'date'            => $disp['date_formatted'],
                                                'shift'           => $disp['shift_schedule_text'] . ($disp['shift_name'] ? ' (' . $disp['shift_name'] . ')' : ''),
                                                'status'          => $disp['status_label'],
                                                'check_in'        => $disp['check_in_formatted'],
                                                'check_out'       => $disp['check_out_formatted'],
                                                'hours'           => $disp['working_hours_display'],
                                                'work_summary'    => $disp['final_work_summary'],
                                                'completed_tasks' => $disp['final_completed_tasks'],
                                                'pending_tasks'   => $disp['final_pending_tasks'],
                                                'leave_code'      => $row['leave_code'] ?? '',
                                                'check_in_dist'   => $row['check_in_dist'] ?? null,
                                                'check_out_dist'  => $row['check_out_dist'] ?? null,
                                                'location_info'   => $disp['loc_text']
                                            ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="fa-solid fa-file-lines"></i> View Summary
                                            </button>
                                        <?php else: ?>
                                            <span style="color:var(--att-text-muted); font-size:0.82rem; font-style:italic;">Not submitted</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card List View (< 720px) -->
                <div class="mobile-history-list">
                    <?php foreach ($history as $row): 
                        $disp = AttendanceHelper::formatRecentAttendanceRecord($row, $portalClosure['is_closed'], $today);
                    ?>
                        <div class="mobile-card">
                            <!-- 1. Date & 2. Status -->
                            <div class="mobile-card-top">
                                <div>
                                    <div style="font-weight: 800; font-size: 0.92rem; display: flex; align-items: center; gap: 6px;">
                                        <i class="fa-regular fa-calendar" style="color:var(--att-text-muted); font-size: 0.85rem;"></i>
                                        <span><?php echo htmlspecialchars($disp['date_formatted']); ?></span>
                                    </div>
                                    <?php if ($disp['is_overnight']): ?>
                                        <div class="badge-overnight">
                                            <i class="fa-solid fa-moon"></i> Overnight &rarr; <?php echo htmlspecialchars($disp['overnight_next_short']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['leave_code'])): ?>
                                        <div class="history-sub-note">Note: <?php echo htmlspecialchars($row['leave_code']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="badge-status status-<?php echo htmlspecialchars($disp['status_key']); ?>">
                                    <i class="<?php echo htmlspecialchars($disp['status_icon']); ?>" style="font-size:0.55rem;"></i>
                                    <?php echo htmlspecialchars($disp['status_label']); ?>
                                </span>
                            </div>

                            <!-- 3. Scheduled Shift -->
                            <div class="mobile-card-row">
                                <span class="detail-label"><i class="fa-regular fa-clock" style="color:var(--att-text-muted);"></i> Scheduled Shift</span>
                                <span class="detail-val" style="text-align: right;">
                                    <?php if ($disp['has_schedule']): ?>
                                        <span style="font-weight:600; font-size:0.86rem;"><?php echo htmlspecialchars($disp['shift_schedule_text']); ?></span>
                                        <span class="shift-schedule-sub"><?php echo htmlspecialchars($disp['shift_name']); ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--att-text-muted); font-style:italic; font-size:0.82rem;">Schedule unavailable</span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <!-- 4. Check In, 5. Check Out, 6. Working Hours -->
                            <div class="mobile-grid-row">
                                <div class="detail-item">
                                    <span class="detail-label">Check In</span>
                                    <span class="detail-val" style="font-size:0.88rem; font-weight:600;"><?php echo htmlspecialchars($disp['check_in_formatted']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Check Out</span>
                                    <span class="detail-val" style="font-size:0.88rem; font-weight:600;">
                                        <?php if ($disp['has_check_out']): ?>
                                            <?php echo htmlspecialchars($disp['check_out_formatted']); ?>
                                        <?php elseif ($disp['has_check_in']): ?>
                                            <span style="color:var(--att-warning); font-size:0.78rem;">Missing</span>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Hours</span>
                                    <span class="detail-val" style="font-size:0.88rem; font-weight:700;">
                                        <?php if (!$disp['has_check_in']): ?>
                                            &mdash;
                                        <?php elseif (!$disp['has_check_out']): ?>
                                            <span style="font-size:0.78rem; color:<?php echo ($disp['status_key'] === 'in_progress' ? '#2563eb' : '#dc2626'); ?>;">
                                                <?php echo htmlspecialchars($disp['working_hours_display']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--att-brand);"><?php echo htmlspecialchars($disp['working_hours_display']); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>

                            <!-- 7. Location Info -->
                            <div class="mobile-card-row">
                                <span class="detail-label"><i class="fa-solid fa-location-dot" style="color:var(--att-text-muted);"></i> Location</span>
                                <span class="detail-val" style="text-align: right;">
                                    <?php if ($disp['loc_type'] === 'verified'): ?>
                                        <span class="badge-loc loc-verified"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($disp['loc_text']); ?></span>
                                    <?php elseif ($disp['loc_type'] === 'remote'): ?>
                                        <span class="badge-loc loc-remote"><i class="fa-solid fa-house-laptop"></i> <?php echo htmlspecialchars($disp['loc_text']); ?></span>
                                    <?php elseif ($disp['loc_type'] === 'exception'): ?>
                                        <span class="badge-loc loc-exception"><i class="fa-solid fa-briefcase"></i> <?php echo htmlspecialchars($disp['loc_text']); ?></span>
                                    <?php else: ?>
                                        <span class="badge-loc loc-missing"><i class="fa-solid fa-location-slash"></i> GPS not recorded</span>
                                    <?php endif; ?>
                                </span>
                            </div>

                            <!-- 8. Daily Summary -->
                            <?php if ($disp['has_summary']): ?>
                                <div style="margin-top: 4px;">
                                    <button type="button" class="btn-view-summary" style="width: 100%; justify-content: center;" onclick="openViewSummaryModal(<?php echo htmlspecialchars(json_encode([
                                        'date'            => $disp['date_formatted'],
                                        'shift'           => $disp['shift_schedule_text'] . ($disp['shift_name'] ? ' (' . $disp['shift_name'] . ')' : ''),
                                        'status'          => $disp['status_label'],
                                        'check_in'        => $disp['check_in_formatted'],
                                        'check_out'       => $disp['check_out_formatted'],
                                        'hours'           => $disp['working_hours_display'],
                                        'work_summary'    => $disp['final_work_summary'],
                                        'completed_tasks' => $disp['final_completed_tasks'],
                                        'pending_tasks'   => $disp['final_pending_tasks'],
                                        'leave_code'      => $row['leave_code'] ?? '',
                                        'check_in_dist'   => $row['check_in_dist'] ?? null,
                                        'check_out_dist'  => $row['check_out_dist'] ?? null,
                                        'location_info'   => $disp['loc_text']
                                    ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <i class="fa-solid fa-file-lines"></i> View Daily Summary
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="mobile-card-row" style="border-bottom: none;">
                                    <span class="detail-label"><i class="fa-solid fa-file-lines" style="color:var(--att-text-muted);"></i> Daily Summary</span>
                                    <span class="detail-val" style="color:var(--att-text-muted); font-style:italic; font-size:0.82rem;">Not submitted</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Attendance Requests History Card (WFH & Location Exceptions) -->
        <section class="history-card" style="margin-top: 24px;" aria-label="Attendance Requests History">
            <div class="history-header">
                <h2 class="history-title">
                    <i class="fa-solid fa-clipboard-list" style="color:var(--att-brand);"></i>
                    <span>My WFH &amp; Attendance Exception Requests</span>
                </h2>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:0.84rem; color:var(--att-text-muted); font-weight:600;">
                        <?php echo count($requestHistory); ?> requests logged
                    </span>
                    <button type="button" class="btn-view-summary" onclick="openRequestModal('wfh')" style="padding:6px 14px;">
                        <i class="fa-solid fa-plus"></i> New Request
                    </button>
                </div>
            </div>

            <?php if (empty($requestHistory)): ?>
                <div style="padding: 36px 24px; text-align: center; color: var(--att-text-muted);">
                    <i class="fa-solid fa-house-laptop" style="font-size: 1.8rem; margin-bottom: 10px; opacity: 0.4;"></i>
                    <p style="font-weight: 600; font-size: 0.9rem;">No WFH or exception requests submitted yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Requested Time</th>
                                <th>Approved Time</th>
                                <th>Status</th>
                                <th>Decision By</th>
                                <th>Reason / Notes</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requestHistory as $req): ?>
                                <tr>
                                    <td style="font-weight: 700;">
                                        <?php echo date('M d, Y', strtotime($req['attendance_date'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($req['requested_work_mode'] === 'wfh'): ?>
                                            <span style="color:var(--att-brand); font-weight:700; font-size:0.82rem; display:inline-flex; align-items:center; gap:5px;">
                                                <i class="fa-solid fa-house-laptop"></i> WFH
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#059669; font-weight:700; font-size:0.82rem; display:inline-flex; align-items:center; gap:5px;">
                                                <i class="fa-solid fa-shield-halved"></i> Exception
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight: 600; font-size:0.88rem;">
                                        <?php echo date('h:i A', strtotime($req['requested_check_in_time'])); ?>
                                    </td>
                                    <td style="font-weight: 700; font-size:0.88rem;">
                                        <?php echo !empty($req['approved_check_in_time']) ? date('h:i A', strtotime($req['approved_check_in_time'])) : '<span style="color:var(--att-text-muted);">&mdash;</span>'; ?>
                                    </td>
                                    <td>
                                        <?php $stClass = 'badge-req-' . strtolower($req['status']); ?>
                                        <span class="badge-status <?php echo $stClass; ?>" style="text-transform: capitalize;">
                                            <?php echo htmlspecialchars(str_replace('_', ' ', $req['status'])); ?>
                                        </span>
                                    </td>
                                    <td style="font-size:0.82rem; color:var(--att-text-muted);">
                                        <?php echo !empty($req['approver_username']) ? htmlspecialchars($req['approver_username']) : '&mdash;'; ?>
                                    </td>
                                    <td style="font-size:0.82rem; max-width:240px;">
                                        <div style="font-weight:600; color:var(--att-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($req['reason']); ?>">
                                            <?php echo htmlspecialchars($req['reason']); ?>
                                        </div>
                                        <?php if (!empty($req['rejection_reason'])): ?>
                                            <div style="color:var(--att-danger); font-size:0.75rem; margin-top:2px;">
                                                Rejection: <?php echo htmlspecialchars($req['rejection_reason']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if ($req['status'] === 'pending'): ?>
                                            <form method="POST" action="" onsubmit="return confirm('Cancel this pending request?');" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="cancel_request">
                                                <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                                <button type="submit" class="btn-cancel" style="padding:4px 10px; font-size:0.74rem; color:var(--att-danger); border-color:var(--att-danger-border);">
                                                    Cancel
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:var(--att-text-muted); font-size:0.78rem;">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <!-- Client-Side Geolocation & Modal Logic -->
    <script>
        const isWorkFromHome = <?php echo ($workMode === 'wfh') ? 'true' : 'false'; ?>;
        const isGeofenceActive = <?php echo (!empty($officeSettings['location_attendance_enabled'])) ? 'true' : 'false'; ?>;
        const officeLat = <?php echo (float)($officeSettings['office_latitude'] ?? 0); ?>;
        const officeLng = <?php echo (float)($officeSettings['office_longitude'] ?? 0); ?>;
        const officeRadius = <?php echo (int)($officeSettings['office_radius_meters'] ?? 100); ?>;
        const hasApprovedRequest = <?php echo ($todayApprovedReq !== null) ? 'true' : 'false'; ?>;
        const canCheckInNow = <?php echo ($scheduledWindowCheck ? ($scheduledWindowCheck['can_check_in'] ? 'true' : 'false') : 'true'); ?>;
        const scheduledCheckInMessage = <?php echo json_encode($scheduledWindowCheck['message'] ?? ''); ?>;

        // Authoritative Server-Synchronized Portal Closure Parameters
        const isPortalClosedInitial = <?php echo $portalClosure['is_closed'] ? 'true' : 'false'; ?>;
        const scheduledCutoffTs = <?php echo (int)$portalClosure['scheduled_checkout_ts']; ?>;
        const serverClockSkewMs = <?php echo (time() * 1000); ?> - Date.now();

        function getAuthoritativeServerTimeSec() {
            return Math.floor((Date.now() + serverClockSkewMs) / 1000);
        }

        function isPortalClosedNow() {
            if (isPortalClosedInitial) return true;
            if (scheduledCutoffTs > 0 && getAuthoritativeServerTimeSec() >= scheduledCutoffTs) {
                return true;
            }
            return false;
        }

        let lastCapturedCoords = { lat: null, lng: null, dist: null };

        function showGpsNotice(msg, type = 'error') {
            const box = document.getElementById('gpsNoticeBox');
            if (!box) return;
            box.className = 'gps-status-box ' + (type === 'error' ? 'toast-error' : 'toast-success');
            box.style.display = 'flex';
            box.innerHTML = `<i class="fa-solid ${type === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check'}"></i> <span>${msg}</span>`;
        }

        function hideGpsNotice() {
            const box = document.getElementById('gpsNoticeBox');
            if (box) box.style.display = 'none';
        }

        function calculateHaversineDistance(lat1, lon1, lat2, lon2) {
            const R = 6371000;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLon / 2) * Math.sin(dLon / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            return Math.round(R * c);
        }

        // ── 1. CHECK-IN HANDLER (GEOFENCE & SCHEDULED-TIME VERIFICATION) ──
        function triggerCheckIn() {
            if (isPortalClosedNow()) {
                triggerPortalClosureLock();
                showGpsNotice("Your scheduled work hours have ended. Attendance access is now closed for today.", "error");
                return;
            }

            const btn = document.getElementById('btnCheckIn');
            hideGpsNotice();

            // Case A: Approved Scheduled Request Check
            if (hasApprovedRequest) {
                if (!canCheckInNow) {
                    showGpsNotice(scheduledCheckInMessage || "Early check-in is restricted before your approved window.", "error");
                    return;
                }
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking In...';
                document.getElementById('actionField').value = 'check_in';
                document.getElementById('attendanceActionForm').submit();
                return;
            }

            // Case B: Work from home or geofencing disabled
            if (isWorkFromHome || !isGeofenceActive) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking In...';
                document.getElementById('actionField').value = 'check_in';
                document.getElementById('attendanceActionForm').submit();
                return;
            }

            // Case C: On-site with active geofencing: Acquire device GPS
            if (!navigator.geolocation) {
                showGpsNotice("Geolocation is not supported by your browser. Please use a modern browser to verify office attendance.", "error");
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying Office Location...';
            showGpsNotice("Verifying device coordinates against configured office location...", "info");

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    const dist = calculateHaversineDistance(lat, lng, officeLat, officeLng);
                    lastCapturedCoords = { lat: lat, lng: lng, dist: dist };

                    // Compare against permitted office radius
                    if (dist <= officeRadius) {
                        // INSIDE Office Geofence: Proceed with normal on-site attendance
                        btn.innerHTML = '<i class="fa-solid fa-check"></i> Location Verified...';
                        document.getElementById('latitudeField').value = lat;
                        document.getElementById('longitudeField').value = lng;
                        document.getElementById('actionField').value = 'check_in';
                        document.getElementById('attendanceActionForm').submit();
                    } else {
                        // OUTSIDE Office Geofence: Display Location Mismatch Popup
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-fingerprint"></i> Check In Now';
                        hideGpsNotice();
                        openLocationMismatchModal(dist);
                    }
                },
                function(error) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-fingerprint"></i> Check In Now';

                    let errorMsg = "Unable to determine your GPS coordinates.";
                    if (error.code === error.PERMISSION_DENIED) {
                        errorMsg = "Location permission denied. On-site attendance requires GPS access. Please allow location permissions in your browser and try again.";
                    } else if (error.code === error.POSITION_UNAVAILABLE) {
                        errorMsg = "GPS location unavailable. Please ensure your device location is enabled.";
                    } else if (error.code === error.TIMEOUT) {
                        errorMsg = "GPS location request timed out. Please try again.";
                    }

                    showGpsNotice(errorMsg, "error");
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }

        // ── 2. LOCATION MISMATCH MODAL HANDLERS ──
        const locationMismatchModal = document.getElementById('locationMismatchModal');
        function openLocationMismatchModal(dist) {
            if (!locationMismatchModal) return;
            const distBox = document.getElementById('mismatchDistanceText');
            if (distBox && dist !== undefined) {
                distBox.style.display = 'block';
                distBox.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> You are approximately <strong>${dist} meters</strong> away from the office (Allowed: ${officeRadius}m).`;
            }
            locationMismatchModal.style.display = 'flex';
        }
        function closeLocationMismatchModal() {
            if (locationMismatchModal) locationMismatchModal.style.display = 'none';
        }

        // ── 3. ATTENDANCE REQUEST MODAL (WFH & ON-SITE EXCEPTION) ──
        const attendanceRequestModal = document.getElementById('attendanceRequestModal');
        function openRequestModal(mode = 'wfh') {
            if (isPortalClosedNow()) {
                triggerPortalClosureLock();
                alert("Your scheduled work hours have ended. Attendance access is now closed for today.");
                return;
            }

            closeLocationMismatchModal();
            if (!attendanceRequestModal) return;

            const modeField = document.getElementById('reqWorkModeField');
            const icon = document.getElementById('reqModalIcon');
            const heading = document.getElementById('reqModalHeading');
            const sub = document.getElementById('reqModalSub');
            const reasonLabel = document.getElementById('reqReasonLabel');
            const reasonInput = document.getElementById('reqReason');
            const errBox = document.getElementById('reqModalError');

            if (errBox) errBox.style.display = 'none';

            // Populate captured coordinates
            document.getElementById('reqLatField').value = lastCapturedCoords.lat || '';
            document.getElementById('reqLngField').value = lastCapturedCoords.lng || '';
            document.getElementById('reqDistField').value = lastCapturedCoords.dist || '';

            if (mode === 'on_site_exception') {
                modeField.value = 'on_site_exception';
                icon.className = 'fa-solid fa-shield-halved';
                icon.style.color = '#059669';
                heading.textContent = 'On-Site Attendance Exception Request';
                sub.textContent = 'Request manager exception approval if you are working on-site or at a client location with location mismatch.';
                reasonLabel.textContent = 'Reason for On-Site Location Exception';
                reasonInput.placeholder = 'Explain location discrepancy (e.g. GPS drift, working from client site, technical issue)...';
            } else {
                modeField.value = 'wfh';
                icon.className = 'fa-solid fa-house-laptop';
                icon.style.color = 'var(--att-brand)';
                heading.textContent = 'Submit Work From Home Request';
                sub.textContent = 'Submit request for HR/CEO approval before checking in remotely today.';
                reasonLabel.textContent = 'Reason for Working From Home';
                reasonInput.placeholder = 'Provide clear business or personal reason for working remotely today...';
            }

            attendanceRequestModal.style.display = 'flex';
            setTimeout(() => reasonInput.focus(), 100);
        }

        function closeRequestModal() {
            if (attendanceRequestModal) attendanceRequestModal.style.display = 'none';
        }

        function handleRequestSubmit(e) {
            const reason = document.getElementById('reqReason')?.value.trim();
            const time = document.getElementById('reqCheckInTime')?.value.trim();
            const errBox = document.getElementById('reqModalError');
            const btn = document.getElementById('btnSubmitRequest');

            if (!reason || reason.length < 5) {
                e.preventDefault();
                if (errBox) {
                    errBox.style.display = 'block';
                    errBox.textContent = 'Please provide a clear justification reason (minimum 5 characters).';
                }
                return false;
            }

            if (!time) {
                e.preventDefault();
                if (errBox) {
                    errBox.style.display = 'block';
                    errBox.textContent = 'Please select your requested check-in time.';
                }
                return false;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
            return true;
        }

        // ── 4. CHECK-OUT MODAL HANDLERS ──
        const checkoutModal = document.getElementById('checkoutModal');
        function openCheckoutModal() {
            if (isPortalClosedNow()) {
                triggerPortalClosureLock();
                alert("Your scheduled work hours have ended. Attendance access is now closed for today.");
                return;
            }
            if (checkoutModal) checkoutModal.style.display = 'flex';
        }
        function closeCheckoutModal() {
            if (checkoutModal) checkoutModal.style.display = 'none';
        }

        // Handle Checkout Form Submission (with GPS for on-site staff)
        function handleCheckoutSubmit(e) {
            e.preventDefault();

            if (isPortalClosedNow()) {
                triggerPortalClosureLock();
                const errBox = document.getElementById('checkoutModalError');
                if (errBox) {
                    errBox.style.display = 'block';
                    errBox.textContent = "Your scheduled work hours have ended. Attendance access is now closed for today.";
                } else {
                    alert("Your scheduled work hours have ended. Attendance access is now closed for today.");
                }
                return false;
            }

            const summary = document.getElementById('work_summary')?.value.trim();
            const completed = document.getElementById('completed_tasks')?.value.trim();
            const leaveSelect = document.getElementById('checkout_leave_code');
            const errBox = document.getElementById('checkoutModalError');
            const btn = document.getElementById('btnConfirmCheckout');

            if (!summary || !completed) {
                if (errBox) {
                    errBox.style.display = 'block';
                    errBox.textContent = 'Both "Tasks Worked On Today" and "Tasks Completed Today" are mandatory.';
                } else {
                    alert('Both "Tasks Worked On Today" and "Tasks Completed Today" are mandatory.');
                }
                return false;
            }

            if (leaveSelect && !leaveSelect.value) {
                if (errBox) {
                    errBox.style.display = 'block';
                    errBox.textContent = 'Please select an authorized reason for early departure.';
                } else {
                    alert('Please select an authorized reason for early departure.');
                }
                leaveSelect.focus();
                return false;
            }

            if (errBox) errBox.style.display = 'none';

            // If WFH or Geofence disabled: submit directly
            if (isWorkFromHome || !isGeofenceActive || hasApprovedRequest) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting Summary...';
                document.getElementById('checkoutForm').submit();
                return true;
            }

            // On-site: capture GPS before closing session
            if (!navigator.geolocation) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
                document.getElementById('checkoutForm').submit();
                return true;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying Office GPS...';

            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    document.getElementById('checkoutLatField').value = pos.coords.latitude;
                    document.getElementById('checkoutLngField').value = pos.coords.longitude;
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> Closing Attendance...';
                    document.getElementById('checkoutForm').submit();
                },
                function(err) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Submit &amp; Check Out';
                    if (errBox) {
                        errBox.style.display = 'block';
                        errBox.textContent = 'Location verification failed (' + err.message + '). Please ensure GPS is active and allowed.';
                    } else {
                        alert('Location verification failed: ' + err.message);
                    }
                },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
            );

            return false;
        }

        // ── 5. VIEW DAILY SUMMARY MODAL HANDLERS ──
        const viewSummaryModal = document.getElementById('viewSummaryModal');
        function openViewSummaryModal(data) {
            if (!viewSummaryModal) return;

            let metaParts = [data.date];
            if (data.shift && data.shift !== 'Schedule unavailable') {
                metaParts.push('Shift: ' + data.shift);
            }
            if (data.status) {
                metaParts.push('Status: ' + data.status);
            }
            if (data.hours && data.hours !== '—') {
                metaParts.push('Hours: ' + data.hours);
            }
            document.getElementById('viewSummaryMeta').textContent = metaParts.join(' • ');

            document.getElementById('viewWorkSummaryText').textContent = data.work_summary || 'No summary provided.';
            document.getElementById('viewCompletedTasksText').textContent = data.completed_tasks || 'No completed tasks recorded.';

            const pendingWrap = document.getElementById('viewPendingTasksWrap');
            if (data.pending_tasks && data.pending_tasks.trim() !== '') {
                pendingWrap.style.display = 'block';
                document.getElementById('viewPendingTasksText').textContent = data.pending_tasks;
            } else {
                pendingWrap.style.display = 'none';
            }

            const gpsWrap = document.getElementById('viewGpsWrap');
            if (data.check_in_dist !== null && data.check_in_dist !== undefined) {
                gpsWrap.style.display = 'block';
                let gpsTxt = `<i class="fa-solid fa-location-crosshairs" style="color:var(--att-brand);"></i> Verified: Check-in was <strong>${data.check_in_dist} meters</strong> from office.`;
                if (data.check_out_dist !== null && data.check_out_dist !== undefined) {
                    gpsTxt += ` &bull; Check-out was <strong>${data.check_out_dist} meters</strong> from office.`;
                }
                gpsWrap.innerHTML = gpsTxt;
            } else if (data.location_info && data.location_info !== 'GPS not recorded') {
                gpsWrap.style.display = 'block';
                gpsWrap.innerHTML = `<i class="fa-solid fa-circle-info" style="color:var(--att-brand);"></i> Location Mode: <strong>${data.location_info}</strong>`;
            } else {
                gpsWrap.style.display = 'none';
            }

            viewSummaryModal.style.display = 'flex';
        }

        function closeViewSummaryModal() {
            if (viewSummaryModal) viewSummaryModal.style.display = 'none';
        }

        // Close Modals with Escape Key or Backdrop Click
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeLocationMismatchModal();
                closeRequestModal();
                closeCheckoutModal();
                closeViewSummaryModal();
            }
        });

        locationMismatchModal?.addEventListener('click', (e) => {
            if (e.target === locationMismatchModal) closeLocationMismatchModal();
        });

        attendanceRequestModal?.addEventListener('click', (e) => {
            if (e.target === attendanceRequestModal) closeRequestModal();
        });

        checkoutModal?.addEventListener('click', (e) => {
            if (e.target === checkoutModal) closeCheckoutModal();
        });

        viewSummaryModal?.addEventListener('click', (e) => {
            if (e.target === viewSummaryModal) closeViewSummaryModal();
        });

        // ── 6. REAL-TIME COUNTDOWN, AUTO-CLOSURE LOCK & SERVER HEARTBEAT ──
        function formatRemainingTime(seconds) {
            if (seconds <= 0) return '00h 00m 00s';
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = seconds % 60;
            return String(h).padStart(2, '0') + 'h ' + String(m).padStart(2, '0') + 'm ' + String(s).padStart(2, '0') + 's';
        }

        let warningTriggered15m = false;
        let warningTriggered5m = false;

        function updateClientPortalCutoffTimer() {
            if (isPortalClosedInitial) return;
            const nowSec = getAuthoritativeServerTimeSec();
            const remainingSec = Math.max(0, scheduledCutoffTs - nowSec);

            const displayEl = document.getElementById('portalRemainingTimeDisplay');
            if (displayEl) {
                displayEl.textContent = formatRemainingTime(remainingSec);
            }

            // Cutoff Warning at 15 minutes remaining
            if (remainingSec > 0 && remainingSec <= 900 && !warningTriggered15m) {
                warningTriggered15m = true;
                showCutoffWarningBanner(15);
            }

            // Cutoff Warning at 5 minutes remaining
            if (remainingSec > 0 && remainingSec <= 300 && !warningTriggered5m) {
                warningTriggered5m = true;
                showCutoffWarningBanner(5);
            }

            // Cutoff reached! Trigger immediate client-side lock
            if (remainingSec <= 0) {
                triggerPortalClosureLock();
            }
        }

        function showCutoffWarningBanner(minutes) {
            const banner = document.getElementById('portalCutoffWarningBanner');
            if (banner) {
                banner.style.display = 'flex';
                banner.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> <span><strong>Shift Cutoff Warning:</strong> Your scheduled work shift ends in less than ${minutes} minutes. Please complete check-out and submit your daily work summary prior to access closure.</span>`;
            }
        }

        function triggerPortalClosureLock() {
            // Dismiss active interactive modals immediately
            closeCheckoutModal();
            closeRequestModal();
            closeLocationMismatchModal();

            // Disable check-in button if present
            const btnIn = document.getElementById('btnCheckIn');
            if (btnIn) {
                btnIn.disabled = true;
                btnIn.className = 'btn-punch btn-punch-closed';
                btnIn.innerHTML = '<i class="fa-solid fa-lock"></i> <span>Portal Access Closed</span>';
            }

            // Disable check-out button if present
            const btnOut = document.getElementById('btnOpenCheckoutModal');
            if (btnOut) {
                btnOut.disabled = true;
                btnOut.className = 'btn-punch btn-punch-closed';
                btnOut.innerHTML = '<i class="fa-solid fa-lock"></i> <span>Portal Access Closed</span>';
            }

            // Hide cutoff warning banner if showing
            const warningBanner = document.getElementById('portalCutoffWarningBanner');
            if (warningBanner) {
                warningBanner.style.display = 'none';
            }

            // Display live closure alert banner
            const liveAlert = document.getElementById('portalClosureLiveAlert');
            if (liveAlert) {
                liveAlert.style.display = 'flex';
            }

            // Update remaining time display
            const displayEl = document.getElementById('portalRemainingTimeDisplay');
            if (displayEl) {
                displayEl.textContent = '00h 00m 00s (Closed)';
                displayEl.style.color = 'var(--att-danger)';
            }
        }

        // Heartbeat API poll every 30 seconds to sync with authoritative server state
        function pollServerClosureStatus() {
            fetch('employee_attendance?action=check_portal_status')
                .then(r => r.json())
                .then(data => {
                    if (data && data.is_closed) {
                        triggerPortalClosureLock();
                    }
                })
                .catch(() => {});
        }

        if (!isPortalClosedInitial && scheduledCutoffTs > 0) {
            setInterval(updateClientPortalCutoffTimer, 1000);
            setInterval(pollServerClosureStatus, 30000);
            updateClientPortalCutoffTimer();
        }
    </script>
</body>
</html>