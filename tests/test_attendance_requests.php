<?php
// ============================================================================
// AUTOMATED TEST SUITE: LOCATION MISMATCH, WFH APPROVAL & SCHEDULED CHECK-IN
// Requirements: Section 10 of Specification
// ============================================================================

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

Security::init($pdo);

$passed = 0;
$failed = 0;
$total = 0;

function assertTest($description, $condition, $details = '') {
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] Test {$total}: {$description}" . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] Test {$total}: {$description}" . ($details ? " ({$details})" : "") . PHP_EOL;
    }
}

echo "======================================================================" . PHP_EOL;
echo "  STARTING WFH APPROVAL, LOCATION MISMATCH & SCHEDULED CHECK-IN TESTS" . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;

// Setup Mock Data
$testEmpEmail = 'test_wfh_emp_' . time() . '@cloudaspire.test';
$pdo->prepare("INSERT INTO employees (first_name, last_name, email, work_mode, status) VALUES ('Jane', 'WFHTester', ?, 'on_site', 'active')")->execute([$testEmpEmail]);
$testEmpId = (int)$pdo->lastInsertId();

$testAdminEmail = 'test_hr_admin_' . time() . '@cloudaspire.test';
$pdo->prepare("INSERT INTO admin (username, email, password) VALUES ('hr_tester', ?, 'hashed_pw')")->execute([$testAdminEmail]);
$testAdminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO admin_roles (admin_id, role_id) VALUES (?, 2)")->execute([$testAdminId]);

$testDate = date('Y-m-d');
$officeCfg = AttendanceHelper::getOfficeLocationSettings($pdo);

// ----------------------------------------------------------------------------
// TEST GROUP 1: LOCATION VERIFICATION & MISMATCH DETECTION
// ----------------------------------------------------------------------------
echo "--- GROUP 1: Location Verification & Mismatch Detection ---" . PHP_EOL;

// 1. Employee Inside Office Radius
$nearLat = (float)$officeCfg['office_latitude'] + 0.0001;
$nearLng = (float)$officeCfg['office_longitude'];
$locInside = AttendanceHelper::validateEmployeeLocation($pdo, 'on_site', $nearLat, $nearLng, 'check_in', $testEmpId, $testDate);
assertTest("Employee inside office radius is permitted normal check-in", $locInside['allowed'] === true && $locInside['location_mismatch'] === false);

// 2. Employee Outside Office Radius (Triggers Mismatch)
$farLat = (float)$officeCfg['office_latitude'] + 0.05; // ~5.5 km away
$farLng = (float)$officeCfg['office_longitude'] + 0.05;
$locOutside = AttendanceHelper::validateEmployeeLocation($pdo, 'on_site', $farLat, $farLng, 'check_in', $testEmpId, $testDate);
assertTest("Employee outside office radius triggers location mismatch flag", $locOutside['allowed'] === false && $locOutside['location_mismatch'] === true);
assertTest("Outside verification does not alter employee permanent work mode", $pdo->query("SELECT work_mode FROM employees WHERE id = {$testEmpId}")->fetchColumn() === 'on_site');

// ----------------------------------------------------------------------------
// TEST GROUP 2: WFH & ON-SITE EXCEPTION REQUEST CREATION
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 2: WFH & Exception Request Creation ---" . PHP_EOL;

// 3. Create WFH Request
$wfhRes = AttendanceHelper::createAttendanceRequest($pdo, $testEmpId, [
    'attendance_date'         => $testDate,
    'requested_work_mode'     => 'wfh',
    'reason'                  => 'Severe weather conditions in residency zone',
    'requested_check_in_time' => '10:00:00',
    'supporting_details'      => 'Will be reachable on Slack and phone',
    'latitude'                => $farLat,
    'longitude'               => $farLng,
    'distance'                => round($locOutside['distance'])
]);
assertTest("WFH request creation succeeds", $wfhRes['success'] === true && !empty($wfhRes['request_id']));
$wfhRequestId = (int)($wfhRes['request_id'] ?? 0);

// Verify request properties in database
$reqStmt = $pdo->prepare("SELECT * FROM attendance_requests WHERE id = ?");
$reqStmt->execute([$wfhRequestId]);
$reqRow = $reqStmt->fetch(PDO::FETCH_ASSOC);
assertTest("Initial request status is strictly 'pending'", $reqRow['status'] === 'pending');
assertTest("Submitting request does NOT create an attendance record (not present)", $pdo->query("SELECT COUNT(*) FROM attendance WHERE employee_id = {$testEmpId} AND date = '{$testDate}'")->fetchColumn() == 0);
assertTest("Submitted GPS coordinates and distance stored accurately", abs((float)$reqRow['submitted_lat'] - (float)$farLat) < 0.0001 && (int)$reqRow['submitted_dist'] > 0);

// 4. Duplicate pending request prevention
$dupRes = AttendanceHelper::createAttendanceRequest($pdo, $testEmpId, [
    'attendance_date'         => $testDate,
    'requested_work_mode'     => 'wfh',
    'reason'                  => 'Second request attempt on same date',
    'requested_check_in_time' => '10:30:00'
]);
assertTest("Duplicate request on the same date is blocked", $dupRes['success'] === false);

// ----------------------------------------------------------------------------
// TEST GROUP 3: HR/CEO APPROVAL & REJECTION WORKFLOW
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 3: HR/CEO Approval & Rejection Rules ---" . PHP_EOL;

// 5. Anti-Self-Approval
// Create an employee linked to same email as an admin
$sameEmail = 'shared_admin_emp_' . time() . '@cloudaspire.test';
$pdo->prepare("INSERT INTO admin (username, email, password) VALUES ('self_tester', ?, 'pw')")->execute([$sameEmail]);
$selfAdminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO admin_roles (admin_id, role_id) VALUES (?, 2)")->execute([$selfAdminId]);
$pdo->prepare("INSERT INTO employees (first_name, last_name, email, work_mode) VALUES ('Self', 'Emp', ?, 'on_site')")->execute([$sameEmail]);
$selfEmpId = (int)$pdo->lastInsertId();
$selfReq = AttendanceHelper::createAttendanceRequest($pdo, $selfEmpId, [
    'attendance_date'         => $testDate,
    'requested_work_mode'     => 'wfh',
    'reason'                  => 'Attempt self approval test',
    'requested_check_in_time' => '09:30:00'
]);
$selfApproveRes = AttendanceHelper::approveAttendanceRequest($pdo, $selfReq['request_id'], $selfAdminId, '09:30:00');
assertTest("Anti-Self-Approval: Admin cannot approve their own attendance request", $selfApproveRes['success'] === false && strpos($selfApproveRes['error'], 'own') !== false);

// 6. Mandatory Rejection Reason
$emptyRejectRes = AttendanceHelper::rejectAttendanceRequest($pdo, $selfReq['request_id'], $testAdminId, '');
assertTest("Rejection requires a mandatory reason", $emptyRejectRes['success'] === false && strpos($emptyRejectRes['error'], 'mandatory') !== false);

$validRejectRes = AttendanceHelper::rejectAttendanceRequest($pdo, $selfReq['request_id'], $testAdminId, 'Client onsite deployment required today');
assertTest("Rejection with valid reason succeeds", $validRejectRes['success'] === true);

$checkReject = $pdo->query("SELECT status, rejection_reason, approver_id, decision_timestamp FROM attendance_requests WHERE id = {$selfReq['request_id']}")->fetch(PDO::FETCH_ASSOC);
assertTest("Rejection records status='rejected', approver_id and decision_timestamp", $checkReject['status'] === 'rejected' && $checkReject['approver_id'] == $testAdminId && !empty($checkReject['decision_timestamp']));

// 7. Concurrent / Duplicate Decisions Protection
$doubleRejectRes = AttendanceHelper::rejectAttendanceRequest($pdo, $selfReq['request_id'], $testAdminId, 'Second rejection attempt');
assertTest("Duplicate decision on processed request is safely rejected", $doubleRejectRes['success'] === false);

// 8. Valid HR Approval with Scheduled Check-In Time (10:00 AM)
$approveRes = AttendanceHelper::approveAttendanceRequest($pdo, $wfhRequestId, $testAdminId, '10:00:00');
assertTest("HR approval with approved check-in time succeeds", $approveRes['success'] === true);

$approvedRow = $pdo->query("SELECT status, approved_check_in_time, approver_id, decision_timestamp FROM attendance_requests WHERE id = {$wfhRequestId}")->fetch(PDO::FETCH_ASSOC);
assertTest("Approval records status='approved' and approved_check_in_time='10:00:00'", $approvedRow['status'] === 'approved' && $approvedRow['approved_check_in_time'] === '10:00:00');
assertTest("Approval alone does NOT create attendance check-in record", $pdo->query("SELECT COUNT(*) FROM attendance WHERE employee_id = {$testEmpId} AND date = '{$testDate}'")->fetchColumn() == 0);

// ----------------------------------------------------------------------------
// TEST GROUP 4: SCHEDULED CHECK-IN TIME ENFORCEMENT
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 4: Scheduled Check-In Time Enforcement ---" . PHP_EOL;

$wfhSettings = [
    'wfh_early_window_minutes'    => 15,
    'wfh_grace_period_minutes'    => 15,
    'wfh_late_threshold_minutes'  => 30,
    'wfh_auto_reschedule_allowed' => 0
];

// 9. Early Check-In Rejection
// Approved time: 10:00 AM. Early window: 15 mins (window opens at 09:45 AM).
// Attempt check-in at 09:30 AM (30 mins early).
$earlyWindow = AttendanceHelper::evaluateScheduledCheckInWindow('10:00:00', $wfhSettings, '09:30:00');
assertTest("Early check-in attempt (09:30 AM vs 10:00 AM) is rejected", $earlyWindow['can_check_in'] === false && $earlyWindow['status'] === 'too_early');
assertTest("Early window indicates 15 minutes remaining until window opens", $earlyWindow['minutes_remaining'] == 15);

// 10. Within Permitted Window (09:50 AM vs 10:00 AM)
$windowOpen = AttendanceHelper::evaluateScheduledCheckInWindow('10:00:00', $wfhSettings, '09:50:00');
assertTest("Check-in within early window (09:50 AM) is permitted", $windowOpen['can_check_in'] === true && $windowOpen['status'] === 'on_time');

// 11. On-Time at Exactly Approved Time (10:00 AM)
$exactTime = AttendanceHelper::evaluateScheduledCheckInWindow('10:00:00', $wfhSettings, '10:00:00');
assertTest("Check-in at exact approved time is permitted on-time", $exactTime['can_check_in'] === true && $exactTime['status'] === 'on_time');

// 12. Within Grace Period (10:10 AM vs 10:00 AM, grace is 15 mins)
$graceTime = AttendanceHelper::evaluateScheduledCheckInWindow('10:00:00', $wfhSettings, '10:10:00');
assertTest("Check-in within 15-minute grace period (10:10 AM) is on-time without delay", $graceTime['can_check_in'] === true && $graceTime['status'] === 'on_time');

// 13. Past Grace Period: Delayed Window (10:25 AM vs 10:00 AM)
$delayedTime = AttendanceHelper::evaluateScheduledCheckInWindow('10:00:00', $wfhSettings, '10:25:00');
assertTest("Check-in past grace period (10:25 AM) is permitted with delayed flag", $delayedTime['can_check_in'] === true && $delayedTime['status'] === 'delayed' && $delayedTime['delay_minutes'] == 25);

// 14. Past Late Threshold without Auto-Reschedule (10:45 AM vs 10:00 AM, threshold 30 mins)
$expiredTime = AttendanceHelper::evaluateScheduledCheckInWindow('10:00:00', $wfhSettings, '10:45:00');
assertTest("Check-in past late threshold (10:45 AM) expires when auto-reschedule disabled", $expiredTime['can_check_in'] === false && $expiredTime['status'] === 'expired');

// 15. Past Late Threshold with Auto-Reschedule Enabled
$wfhSettingsAuto = $wfhSettings;
$wfhSettingsAuto['wfh_auto_reschedule_allowed'] = 1;
$rescheduledTime = AttendanceHelper::evaluateScheduledCheckInWindow('10:00:00', $wfhSettingsAuto, '10:45:00');
assertTest("Check-in past late threshold permits check-in when auto-reschedule allowed", $rescheduledTime['can_check_in'] === true && $rescheduledTime['status'] === 'delayed');

// ----------------------------------------------------------------------------
// TEST GROUP 5: ACTUAL CHECK-IN WITH APPROVED WFH EXCEPTION
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 5: Actual Check-In Execution & State Transition ---" . PHP_EOL;

// 16. Haversine check for employee with approved WFH request
$wfhLocCheck = AttendanceHelper::validateEmployeeLocation($pdo, 'on_site', $farLat, $farLng, 'check_in', $testEmpId, $testDate);
assertTest("Approved WFH request waives on-site geofence restriction for employee", $wfhLocCheck['allowed'] === true && $wfhLocCheck['is_wfh'] === true && $wfhLocCheck['has_approved_request'] === true);

// 17. Execute Check-in with Approved WFH Request
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO attendance 
    (employee_id, date, check_in, status, attendance_status, delay_minutes, punch_history) 
    VALUES (?, ?, '10:02:00', 'present', 'present', 0, ?)")
    ->execute([$testEmpId, $testDate, json_encode([['in' => '10:02:00', 'out' => null]])]);
$attId = (int)$pdo->lastInsertId();

AttendanceHelper::markAttendanceRequestCheckedIn($pdo, $wfhRequestId, '10:02:00');
$pdo->commit();

$checkedInReq = $pdo->query("SELECT status, actual_check_in FROM attendance_requests WHERE id = {$wfhRequestId}")->fetch(PDO::FETCH_ASSOC);
assertTest("Actual check-in transitions request status to 'checked_in'", $checkedInReq['status'] === 'checked_in');
assertTest("Server check-in time recorded accurately (10:02:00)", $checkedInReq['actual_check_in'] === '10:02:00');

// 18. Duplicate Check-in Prevention
$canCheckInAgain = AttendanceHelper::canCheckIn($pdo, $testEmpId, $testDate);
assertTest("Duplicate check-in on the same day is prohibited by canCheckIn", $canCheckInAgain === false);

// 19. Transactional Closing & Daily Work Summary
$closeResult = AttendanceHelper::closeAttendanceTransaction(
    $pdo,
    $testEmpId,
    $attId,
    [
        'work_summary'    => 'Developed WFH approval and scheduled check-in system',
        'completed_tasks' => 'Integrated geofencing, created test suite, verified RBAC',
        'pending_tasks'   => 'None'
    ],
    null,
    '18:05:00'
);
assertTest("Transactional checkout with daily work summary succeeds", $closeResult['success'] === true);

$sumCheck = $pdo->query("SELECT work_summary, completed_tasks FROM daily_work_summaries WHERE attendance_id = {$attId}")->fetch(PDO::FETCH_ASSOC);
assertTest("Daily work summary persisted atomically in daily_work_summaries table", !empty($sumCheck['work_summary']));

// ----------------------------------------------------------------------------
// TEST GROUP 6: ON-SITE LOCATION EXCEPTION WORKFLOW
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 6: On-Site Location Exception Workflow ---" . PHP_EOL;

// 20. Create On-Site Exception Request for next day
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$excRes = AttendanceHelper::createAttendanceRequest($pdo, $testEmpId, [
    'attendance_date'         => $tomorrow,
    'requested_work_mode'     => 'on_site_exception',
    'reason'                  => 'Assigned to Client Office - Puducherry Campus 2',
    'requested_check_in_time' => '09:15:00',
    'supporting_details'      => 'Client project kick-off meeting with stakeholder',
    'latitude'                => 11.960000,
    'longitude'               => 79.825000,
    'distance'                => 2400
]);
assertTest("On-site location exception request creation succeeds", $excRes['success'] === true);
$excReqId = (int)$excRes['request_id'];

// 21. Approve Exception
$apprExc = AttendanceHelper::approveAttendanceRequest($pdo, $excReqId, $testAdminId, '09:15:00');
assertTest("HR approval of on-site location exception succeeds", $apprExc['success'] === true);

// 22. Exception Applies ONLY to that Specific Date
$locTomorrow = AttendanceHelper::validateEmployeeLocation($pdo, 'on_site', 11.960000, 79.825000, 'check_in', $testEmpId, $tomorrow);
assertTest("Approved exception waives location restriction on authorized date ({$tomorrow})", $locTomorrow['allowed'] === true && !empty($locTomorrow['is_on_site_exception']));

$dayAfter = date('Y-m-d', strtotime('+2 days'));
$locDayAfter = AttendanceHelper::validateEmployeeLocation($pdo, 'on_site', 11.960000, 79.825000, 'check_in', $testEmpId, $dayAfter);
assertTest("Exception does NOT permanently disable location check on future dates", $locDayAfter['allowed'] === false && $locDayAfter['location_mismatch'] === true);

// ----------------------------------------------------------------------------
// TEST GROUP 7: DATABASE ROLLBACK INTEGRITY
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 7: Database Rollback & Transaction Integrity ---" . PHP_EOL;

// 23. Test transaction rollback on failure
$rollBackSuccess = false;
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO attendance_requests (employee_id, attendance_date, requested_work_mode, reason, requested_check_in_time) VALUES (?, '2099-01-01', 'wfh', 'Rollback test', '10:00:00')")->execute([$testEmpId]);
    $insertedId = (int)$pdo->lastInsertId();
    // Deliberate constraint violation to trigger catch
    $pdo->prepare("INSERT INTO attendance_requests (employee_id, attendance_date, requested_work_mode, reason, requested_check_in_time) VALUES (999999, '2099-01-01', 'invalid_enum', 'Rollback test', '10:00:00')")->execute();
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Verify $insertedId does NOT exist in DB
    $exists = $pdo->query("SELECT COUNT(*) FROM attendance_requests WHERE id = {$insertedId}")->fetchColumn();
    $rollBackSuccess = ($exists == 0);
}
assertTest("Database transaction rolls back cleanly on exception without partial commits", $rollBackSuccess);

// ----------------------------------------------------------------------------
// CLEANUP TEST DATA
// ----------------------------------------------------------------------------
try {
    $pdo->prepare("DELETE FROM employees WHERE id IN (?, ?)")->execute([$testEmpId, $selfEmpId]);
    $pdo->prepare("DELETE FROM admin_roles WHERE admin_id IN (?, ?)")->execute([$testAdminId, $selfAdminId]);
    $pdo->prepare("DELETE FROM admin WHERE id IN (?, ?)")->execute([$testAdminId, $selfAdminId]);
} catch (PDOException $e) {}

echo PHP_EOL . "======================================================================" . PHP_EOL;
echo "  TEST SUMMARY: {$passed} PASSED / {$failed} FAILED (Total: {$total})" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

if ($failed > 0) {
    echo "  SOME TESTS FAILED!" . PHP_EOL;
    exit(1);
} else {
    echo "  ALL TESTS EXECUTED AND PASSED SUCCESSFULLY!" . PHP_EOL;
    exit(0);
}
