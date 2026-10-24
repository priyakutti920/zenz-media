<?php
// ============================================================================
// UI RENDERING & COMPONENT VERIFICATION TEST
// ============================================================================

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

// Initialize Security session before any output is sent
Security::init($pdo);
Security::initSecureSession();

$passed = 0;
$failed = 0;
$total = 0;

function assertUI($desc, $cond) {
    global $passed, $failed, $total;
    $total++;
    if ($cond) {
        $passed++;
        echo "  [PASS] UI Test {$total}: {$desc}" . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] UI Test {$total}: {$desc}" . PHP_EOL;
    }
}

echo "======================================================================" . PHP_EOL;
echo "  TESTING UI RENDERING & MODAL COMPONENTS" . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;

// 1. Setup Mock Employee Session
$empEmail = 'ui_emp_' . time() . '@cloudaspire.test';
$pdo->prepare("INSERT INTO employees (first_name, last_name, email, work_mode, status) VALUES ('Alex', 'Frontend', ?, 'on_site', 'active')")->execute([$empEmail]);
$empId = (int)$pdo->lastInsertId();

$_SESSION['employee_logged_in'] = true;
$_SESSION['employee_id'] = $empId;
$_SESSION['last_activity'] = time();

// Capture output of employee_attendance.php
ob_start();
try {
    include __DIR__ . '/../employee_attendance.php';
    $html = ob_get_clean();
} catch (Throwable $e) {
    $html = ob_get_clean();
    echo "Rendering error: " . $e->getMessage() . PHP_EOL;
}

// 2. Verify Employee Attendance UI Elements
assertUI("Page renders with HTTP 200 equivalent without fatal errors", !empty($html));
assertUI("Page title 'My Attendance' exists", strpos($html, 'My Attendance') !== false);
assertUI("Location Mismatch Modal exists in DOM", strpos($html, 'id="locationMismatchModal"') !== false);
assertUI("Exact title 'You\'re Outside the Office Location' is present", strpos($html, "You're Outside the Office Location") !== false);
assertUI("Exact message 'Your current location does not match the configured office location' is present", strpos($html, "Your current location does not match the configured office location. Choose how you want to work today.") !== false);
assertUI("Option 1 'On-site — Request attendance assistance or report an office-location issue' is present", strpos($html, "Request attendance assistance or report an office-location issue") !== false);
assertUI("Option 2 'Work From Home — Submit a WFH attendance request for HR/CEO approval' is present", strpos($html, "Submit a WFH attendance request for HR/CEO approval") !== false);
assertUI("Option 3 'Cancel — Return to the attendance dashboard' is present", strpos($html, "Cancel &mdash; Return to the attendance dashboard") !== false);
assertUI("WFH & On-Site Exception Request Modal exists", strpos($html, 'id="attendanceRequestModal"') !== false);
assertUI("Daily Work Summary Checkout Modal exists", strpos($html, 'id="checkoutModal"') !== false);
assertUI("Attendance Requests History card is rendered", strpos($html, 'My WFH &amp; Attendance Exception Requests') !== false);

// 3. Verify Admin Console: Attendance Requests
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'SuperAdmin';
$_SESSION['admin_role'] = 'super_admin';

ob_start();
try {
    include __DIR__ . '/../admin/attendance_requests.php';
    $adminHtml = ob_get_clean();
} catch (Throwable $e) {
    $adminHtml = ob_get_clean();
    echo "Admin rendering error: " . $e->getMessage() . PHP_EOL;
}

assertUI("Admin WFH Approval console renders without errors", !empty($adminHtml));
assertUI("Admin WFH console contains 'WFH & Location Exception Approvals'", strpos($adminHtml, 'WFH &amp; Location Exception Approvals') !== false);
assertUI("Admin WFH console includes KPI status counters", strpos($adminHtml, 'req-stat-card') !== false);
assertUI("Admin WFH console has approve modal with scheduled check-in time", strpos($adminHtml, 'id="approveModal"') !== false && strpos($adminHtml, 'approved_check_in_time') !== false);
assertUI("Admin WFH console has rejection modal with mandatory reason", strpos($adminHtml, 'id="rejectModal"') !== false && strpos($adminHtml, 'rejection_reason') !== false);

// 4. Verify Admin Office Location & Settings
ob_start();
try {
    include __DIR__ . '/../admin/office_location.php';
    $officeHtml = ob_get_clean();
} catch (Throwable $e) {
    $officeHtml = ob_get_clean();
    echo "Office settings rendering error: " . $e->getMessage() . PHP_EOL;
}

assertUI("Office GPS settings console renders successfully", !empty($officeHtml));
assertUI("Office console includes interactive Leaflet map container", strpos($officeHtml, 'id="officeMap"') !== false);
assertUI("Office console includes WFH Early Window Minutes input", strpos($officeHtml, 'name="wfh_early_window_minutes"') !== false);
assertUI("Office console includes WFH Grace Period Minutes input", strpos($officeHtml, 'name="wfh_grace_period_minutes"') !== false);
assertUI("Office console includes Distance & Geofence Tester Sandbox", strpos($officeHtml, 'Distance &amp; Geofence Tester') !== false);

// Cleanup
$pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$empId]);

echo PHP_EOL . "======================================================================" . PHP_EOL;
echo "  UI TEST SUMMARY: {$passed} PASSED / {$failed} FAILED (Total: {$total})" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
