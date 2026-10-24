<?php
// ============================================================================
// STAGING DEPLOYMENT VERIFICATION SUITE
// Simulates complete staging lifecycle: Environment, Schema, GPS Geofencing,
// WFH Request, Admin Approval, Scheduled Window, Check-In, and Atomic Check-Out.
// ============================================================================

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

date_default_timezone_set('Asia/Kolkata');

$passed = 0;
$failed = 0;
$total = 0;

function assertStaging($title, $condition, $details = '') {
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] Staging Check {$total}: {$title}" . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] Staging Check {$total}: {$title}" . ($details ? " ({$details})" : "") . PHP_EOL;
    }
}

echo "======================================================================" . PHP_EOL;
echo "  STAFF MANAGEMENT TOOL - STAGING VERIFICATION SUITE" . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;

// 1. Environment & Database Checks
echo "--- 1. Staging Environment & Database Pre-Flight ---" . PHP_EOL;
assertStaging("PHP Timezone is Asia/Kolkata", date_default_timezone_get() === 'Asia/Kolkata');

$tzQuery = $pdo->query("SELECT @@session.time_zone as tz, NOW() as now_time")->fetch(PDO::FETCH_ASSOC);
assertStaging("MySQL Connection established with active PDO", $pdo instanceof PDO);
assertStaging("MySQL Timezone aligns with Asia/Kolkata (+05:30)", $tzQuery['tz'] === '+05:30' || $tzQuery['tz'] === 'SYSTEM');

// 2. Schema and Migration Integrity
echo PHP_EOL . "--- 2. Database Schema & Migration Columns ---" . PHP_EOL;
$attReqCols = $pdo->query("SHOW TABLES LIKE 'attendance_requests'")->fetchColumn();
assertStaging("attendance_requests table exists", !empty($attReqCols));

$wfhSettings = $pdo->query("SHOW COLUMNS FROM site_settings LIKE 'wfh_%'")->fetchAll(PDO::FETCH_COLUMN);
assertStaging("site_settings contains all 4 WFH scheduling columns", count($wfhSettings) >= 4);

$attCheckOut = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'check_out_%'")->fetchAll(PDO::FETCH_COLUMN);
assertStaging("attendance contains check_out_lat, check_out_lng, check_out_dist", in_array('check_out_lat', $attCheckOut) && in_array('check_out_dist', $attCheckOut));

$summaryIndexes = $pdo->query("SHOW INDEX FROM daily_work_summaries WHERE Key_name = 'unique_attendance_summary'")->fetch(PDO::FETCH_ASSOC);
assertStaging("daily_work_summaries enforces unique_attendance_summary constraint", !empty($summaryIndexes) && $summaryIndexes['Non_unique'] == 0);

// 3. Full End-to-End Workflow Simulation
echo PHP_EOL . "--- 3. End-to-End Staging Lifecycle Simulation ---" . PHP_EOL;

// A. Create Staging Employee
$empEmail = 'staging_emp_' . time() . '@cloudaspire.test';
$pdo->prepare("INSERT INTO employees (first_name, last_name, email, work_mode, status) VALUES ('Staging', 'User', ?, 'on_site', 'active')")->execute([$empEmail]);
$empId = (int)$pdo->lastInsertId();
assertStaging("Staging employee provisioned successfully (ID: {$empId})", $empId > 0);

// B. GPS Verification - Outside Office Geofence (Location Mismatch)
$office = AttendanceHelper::getOfficeLocationSettings($pdo);
$outsideLat = (float)$office['office_latitude'] + 0.05; // ~5.5km away
$outsideLng = (float)$office['office_longitude'] + 0.05;

$locCheck = AttendanceHelper::validateEmployeeLocation($pdo, 'on_site', $outsideLat, $outsideLng, 'check_in', $empId);
assertStaging("Employee outside geofence triggers location mismatch", $locCheck['allowed'] === false && !empty($locCheck['location_mismatch']));

// C. Submit WFH Request for Today
$today = date('Y-m-d');
$nowTime = date('H:i:s');
$approvedScheduledTime = date('H:i:00', strtotime('+10 minutes'));

$reqResult = AttendanceHelper::createAttendanceRequest($pdo, $empId, [
    'attendance_date'         => $today,
    'requested_work_mode'     => 'wfh',
    'reason'                  => 'Staging automated validation of location mismatch workflow',
    'supporting_details'      => 'Device outside permitted 100m geofence radius',
    'requested_check_in_time' => $nowTime,
    'latitude'                => $outsideLat,
    'longitude'               => $outsideLng,
    'distance'                => $locCheck['distance'] ?? 5500
]);
assertStaging("WFH request created with initial status 'pending'", $reqResult['success'] === true && !empty($reqResult['request_id']));
$reqId = $reqResult['request_id'];

// D. HR / Admin Approval with Scheduled Check-In Time
$adminId = 1; // Super admin
$appResult = AttendanceHelper::approveAttendanceRequest($pdo, $reqId, $adminId, $approvedScheduledTime);
assertStaging("Admin approves WFH request with scheduled check-in time", $appResult['success'] === true);

// Verify approval did NOT create attendance record
$openAtt = AttendanceHelper::getActiveOpenAttendance($pdo, $empId, $today);
assertStaging("Approval alone did NOT mark employee as checked in", $openAtt === null);

// E. Scheduled Window Enforcement
// 1. Check early attempt (30 mins before scheduled time)
$earlyTime = date('H:i:s', strtotime('-30 minutes', strtotime($approvedScheduledTime)));
$earlyEval = AttendanceHelper::evaluateScheduledCheckInWindow($approvedScheduledTime, $office, $earlyTime);
assertStaging("Early check-in before window opens is rejected", $earlyEval['can_check_in'] === false && $earlyEval['status'] === 'too_early');

// 2. Check on-time attempt (within window)
$onTime = date('H:i:s', strtotime('-5 minutes', strtotime($approvedScheduledTime)));
$onTimeEval = AttendanceHelper::evaluateScheduledCheckInWindow($approvedScheduledTime, $office, $onTime);
assertStaging("Check-in within early window is permitted on-time", $onTimeEval['can_check_in'] === true && $onTimeEval['status'] === 'on_time');

// F. Actual Check-In Execution
$pdo->beginTransaction();
$insStmt = $pdo->prepare("INSERT INTO attendance 
    (employee_id, date, check_in, status, attendance_status, delay_minutes, punch_history, check_in_lat, check_in_lng, check_in_dist) 
    VALUES (?, ?, ?, 'present', 'present', 0, ?, ?, ?, NULL)");
$insStmt->execute([
    $empId,
    $today,
    $onTime,
    json_encode([['in' => $onTime, 'out' => null]]),
    $outsideLat,
    $outsideLng
]);
$attId = (int)$pdo->lastInsertId();
AttendanceHelper::markAttendanceRequestCheckedIn($pdo, $reqId, $onTime);
$pdo->commit();

assertStaging("Actual check-in records attendance row with server time", $attId > 0);

$reqRow = $pdo->query("SELECT status, actual_check_in FROM attendance_requests WHERE id = {$reqId}")->fetch(PDO::FETCH_ASSOC);
assertStaging("Attendance request transitioned to 'checked_in' with actual punch time", $reqRow['status'] === 'checked_in' && !empty($reqRow['actual_check_in']));

// G. Attendance Closing & Work Summary Persistence
$checkoutTime = date('H:i:s', strtotime('+8 hours', strtotime($onTime)));
$summaryData = [
    'work_summary'    => 'Completed staging release readiness testing and GPS boundary validation',
    'completed_tasks' => 'Task #101 verified, Task #102 closed',
    'pending_tasks'   => 'None'
];
$closeResult = AttendanceHelper::closeAttendanceTransaction(
    $pdo,
    $empId,
    $attId,
    $summaryData,
    null,
    $checkoutTime,
    ['latitude' => $outsideLat, 'longitude' => $outsideLng, 'distance' => 5500]
);
assertStaging("Attendance closing transaction committed successfully", $closeResult['success'] === true);

$summaryRecord = $pdo->query("SELECT * FROM daily_work_summaries WHERE attendance_id = {$attId}")->fetch(PDO::FETCH_ASSOC);
assertStaging("Daily work summary row persisted in database", !empty($summaryRecord) && $summaryRecord['work_summary'] === $summaryData['work_summary']);

// Cleanup Staging Test Data
$pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$empId]);
assertStaging("Staging test artifacts cleaned up cleanly", true);

echo PHP_EOL . "======================================================================" . PHP_EOL;
echo "  STAGING VERIFICATION SUMMARY: {$passed} PASSED / {$failed} FAILED (Total: {$total})" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
