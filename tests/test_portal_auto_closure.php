<?php
// ============================================================================
// AUTOMATED TEST SUITE: ATTENDANCE PORTAL AUTOMATIC CLOSURE & HR REVIEW
// ============================================================================

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

date_default_timezone_set('Asia/Kolkata');
Security::init($pdo);

$passed = 0;
$failed = 0;
$total = 0;

function assertTest($description, $condition) {
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] Test {$total}: {$description}" . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] Test {$total}: {$description}" . PHP_EOL;
    }
}

echo "======================================================================" . PHP_EOL;
echo "  STARTING ATTENDANCE PORTAL AUTOMATIC CLOSURE TEST SUITE" . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;

// ----------------------------------------------------------------------------
// TEST GROUP 1: SCHEMA VERIFICATION & TIMEZONE
// ----------------------------------------------------------------------------
echo "--- GROUP 1: Database Schema & Timezone Configuration ---" . PHP_EOL;

assertTest("Timezone is set to Asia/Kolkata", date_default_timezone_get() === 'Asia/Kolkata');

// Verify migration columns exist
AttendanceHelper::ensurePortalClosureSchema($pdo);

$attCols = $pdo->query("SHOW COLUMNS FROM attendance")->fetchAll(PDO::FETCH_COLUMN);
assertTest("attendance has 'is_flagged_for_review' column", in_array('is_flagged_for_review', $attCols));
assertTest("attendance has 'flag_reason' column", in_array('flag_reason', $attCols));
assertTest("attendance has 'closed_by_system' column", in_array('closed_by_system', $attCols));

$empCols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
assertTest("employees has 'shift_id' column", in_array('shift_id', $empCols));

$reqCols = $pdo->query("SHOW COLUMNS FROM attendance_requests")->fetchAll(PDO::FETCH_COLUMN);
assertTest("attendance_requests has 'approved_check_out_time' column", in_array('approved_check_out_time', $reqCols));

// ----------------------------------------------------------------------------
// TEST GROUP 2: SHIFT WINDOW & OVERNIGHT SHIFT DETERMINATION
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 2: Shift Window & Overnight Shift Logic ---" . PHP_EOL;

$dayShift = [
    'shift_name' => 'General Day Shift',
    'checkin_start' => '09:00:00',
    'checkin_end' => '10:00:00',
    'checkout_time' => '18:00:00',
];

$nightShift = [
    'shift_name' => 'Night Shift',
    'checkin_start' => '20:00:00',
    'checkin_end' => '21:00:00',
    'checkout_time' => '04:00:00',
];

assertTest("Day shift (09:00 - 18:00) is NOT detected as overnight", AttendanceHelper::isOvernightShift($dayShift) === false);
assertTest("Night shift (20:00 - 04:00) IS detected as overnight", AttendanceHelper::isOvernightShift($nightShift) === true);

$workday = '2026-10-24';
$dayWindow = AttendanceHelper::getShiftWindowForWorkday($dayShift, $workday);
assertTest("Day shift starts on workday date", date('Y-m-d H:i:s', $dayWindow['start_ts']) === '2026-10-24 09:00:00');
assertTest("Day shift cutoff ends on same workday date", date('Y-m-d H:i:s', $dayWindow['end_ts']) === '2026-10-24 18:00:00');
assertTest("Day shift end date equals workday", $dayWindow['end_date'] === '2026-10-24');

$nightWindow = AttendanceHelper::getShiftWindowForWorkday($nightShift, $workday);
assertTest("Night shift starts on workday date 20:00", date('Y-m-d H:i:s', $nightWindow['start_ts']) === '2026-10-24 20:00:00');
assertTest("Night shift cutoff ends NEXT day at 04:00", date('Y-m-d H:i:s', $nightWindow['end_ts']) === '2026-10-25 04:00:00');
assertTest("Night shift end date is 2026-10-25", $nightWindow['end_date'] === '2026-10-25');

// ----------------------------------------------------------------------------
// TEST GROUP 3: DAYTIME SHIFT AUTOMATIC PORTAL CLOSURE
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 3: Daytime Shift Cutoff Evaluation ---" . PHP_EOL;

// Setup test shifts
$pdo->prepare("DELETE FROM shift_settings WHERE shift_name IN ('Test Day Shift', 'Overnight Test Shift')")->execute();

$stmt = $pdo->prepare("INSERT INTO shift_settings (shift_name, checkin_start, checkin_end, checkout_time, is_active) VALUES (?, ?, ?, ?, ?)");
$stmt->execute(['Test Day Shift', '09:00:00', '10:00:00', '18:00:00', 1]);
$testDayShiftId = (int)$pdo->lastInsertId();

$stmt = $pdo->prepare("INSERT INTO shift_settings (shift_name, checkin_start, checkin_end, checkout_time, is_active) VALUES (?, ?, ?, ?, ?)");
$stmt->execute(['Overnight Test Shift', '20:00:00', '21:00:00', '04:00:00', 1]);
$testNightShiftId = (int)$pdo->lastInsertId();

// Setup test employee assigned to Test Day Shift
$testEmpEmail = 'test_closure_emp@test.com';
$pdo->prepare("DELETE FROM employees WHERE email = ?")->execute([$testEmpEmail]);
$stmt = $pdo->prepare("INSERT INTO employees (first_name, last_name, email, password, status, position, work_mode, shift_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute(['TestClosure', 'Employee', $testEmpEmail, password_hash('Secret123', PASSWORD_BCRYPT), 'active', 'Developer', 'on_site', $testDayShiftId]);
$testEmpId = (int)$pdo->lastInsertId();

// Clean any attendance for test employee
$pdo->prepare("DELETE FROM attendance WHERE employee_id = ?")->execute([$testEmpId]);

// Case 3A: Before Cutoff (e.g. 17:30 for 18:00 cutoff)
$tsBeforeCutoff = strtotime("2026-10-24 17:30:00");
$evalBefore = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, $tsBeforeCutoff);
assertTest("Portal is OPEN before cutoff", $evalBefore['is_closed'] === false);
assertTest("Seconds remaining is 1800 (30m)", $evalBefore['seconds_remaining'] === 1800);
assertTest("can_check_in is true when open", $evalBefore['can_check_in'] === true);

// Case 3B: Exactly at Cutoff (18:00:00)
$tsAtCutoff = strtotime("2026-10-24 18:00:00");
$evalAt = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, $tsAtCutoff);
assertTest("Portal is CLOSED exactly at cutoff (18:00:00)", $evalAt['is_closed'] === true);
assertTest("Closure reason indicates scheduled end reached", strpos($evalAt['closure_reason'], 'scheduled_end_reached') !== false);
assertTest("Exact message matches requirement", $evalAt['message'] === 'Your scheduled work hours have ended. Attendance access is now closed for today.');
assertTest("Further check-in is disabled after closure", $evalAt['can_check_in'] === false);
assertTest("Further check-out is disabled after closure", $evalAt['can_check_out'] === false);

// Case 3C: After Cutoff (18:45:00)
$tsAfterCutoff = strtotime("2026-10-24 18:45:00");
$evalAfter = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, $tsAfterCutoff);
assertTest("Portal is CLOSED after cutoff (18:45:00)", $evalAfter['is_closed'] === true);
assertTest("Seconds remaining is 0", $evalAfter['seconds_remaining'] === 0);
assertTest("Exact message displayed after cutoff", $evalAfter['message'] === 'Your scheduled work hours have ended. Attendance access is now closed for today.');

// ----------------------------------------------------------------------------
// TEST GROUP 4: EARLY CHECKOUT CLOSURE POLICY
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 4: Early Checkout Policy ---" . PHP_EOL;

// Create attendance record with early checkout at 15:00
$today = '2026-10-24';
$pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND date = ?")->execute([$testEmpId, $today]);
$stmt = $pdo->prepare("INSERT INTO attendance (employee_id, date, check_in, check_out, status, total_work_minutes, working_hours) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$testEmpId, $today, '09:15:00', '15:00:00', 'present', 345, 5.75]);

// Evaluate at 15:30 (before 18:00 cutoff)
$tsEarlyAfter = strtotime("$today 15:30:00");
$evalEarly = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, $tsEarlyAfter);
assertTest("Portal closes immediately upon early checkout without waiting for scheduled end time", $evalEarly['is_closed'] === true);
assertTest("Closure reason is 'already_checked_out'", $evalEarly['closure_reason'] === 'already_checked_out');
assertTest("Details clarify checkout completed and closed", strpos($evalEarly['details'], 'Attendance access is now closed for today') !== false);
assertTest("can_check_in is false after early checkout", $evalEarly['can_check_in'] === false);
assertTest("can_check_out is false after early checkout", $evalEarly['can_check_out'] === false);

// ----------------------------------------------------------------------------
// TEST GROUP 5: MISSING CHECKOUT PRESERVATION & HR REVIEW FLAGGING
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 5: Missing Checkout (Unclosed Record) & HR Flagging ---" . PHP_EOL;

// Setup record where employee checked in at 09:05, but never checked out
$pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND date = ?")->execute([$testEmpId, $today]);
$stmt = $pdo->prepare("INSERT INTO attendance (employee_id, date, check_in, check_out, status, notes) VALUES (?, ?, ?, NULL, ?, ?)");
$stmt->execute([$testEmpId, $today, '09:05:00', 'present', 'Client meeting scheduled.']);
$unclosedAttId = (int)$pdo->lastInsertId();

// Simulate time passing cutoff: e.g. 18:15:00
$tsPastCutoff = strtotime("$today 18:15:00");
$evalUnclosed = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, $tsPastCutoff);

assertTest("Portal is closed past cutoff for unclosed record", $evalUnclosed['is_closed'] === true);
assertTest("Closure reason indicates scheduled end reached missing checkout", $evalUnclosed['closure_reason'] === 'scheduled_end_reached_missing_checkout');
assertTest("needs_hr_review flag is true", $evalUnclosed['needs_hr_review'] === true);
assertTest("Exact closure message is returned", $evalUnclosed['message'] === 'Your scheduled work hours have ended. Attendance access is now closed for today.');

// Inspect the database record
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
$stmt->execute([$unclosedAttId]);
$dbRec = $stmt->fetch(PDO::FETCH_ASSOC);

assertTest("Actual check-in time is preserved intact ('09:05:00')", $dbRec['check_in'] === '09:05:00');
assertTest("Check-out timestamp is NOT fabricated (remains NULL)", $dbRec['check_out'] === null);
assertTest("is_flagged_for_review is set to 1", (int)$dbRec['is_flagged_for_review'] === 1);
assertTest("closed_by_system is set to 1", (int)$dbRec['closed_by_system'] === 1);
assertTest("flag_reason notes cutoff reached without checkout", strpos($dbRec['flag_reason'], 'Missing check-out before scheduled shift cutoff') !== false);
assertTest("Employee notes are preserved with appended audit notice", strpos($dbRec['notes'], 'Client meeting scheduled.') !== false);

// ----------------------------------------------------------------------------
// TEST GROUP 6: OVERNIGHT SHIFT CROSS-MIDNIGHT BOUNDARY
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 6: Overnight Shift Boundary ---" . PHP_EOL;

// Assign employee to night shift (20:00 - 04:00)
$pdo->prepare("UPDATE employees SET shift_id = ? WHERE id = ?")->execute([$testNightShiftId, $testEmpId]);

// Create attendance record on workday 2026-10-24 with checkin 20:05:00
$workdayOvernight = '2026-10-24';
$pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND date = ?")->execute([$testEmpId, $workdayOvernight]);
$stmt = $pdo->prepare("INSERT INTO attendance (employee_id, date, check_in, check_out, status) VALUES (?, ?, ?, NULL, ?)");
$stmt->execute([$testEmpId, $workdayOvernight, '20:05:00', 'present']);

// At 2026-10-25 03:30:00 (before 04:00 cutoff): portal should be OPEN
$tsNightOpen = strtotime("2026-10-25 03:30:00");
$evalNightOpen = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, $tsNightOpen);
assertTest("Overnight shift is OPEN at 03:30 AM before 04:00 AM cutoff", $evalNightOpen['is_closed'] === false);
assertTest("Overnight shift remaining seconds is 1800 (30m)", $evalNightOpen['seconds_remaining'] === 1800);
assertTest("can_check_out is true for active overnight employee", $evalNightOpen['can_check_out'] === true);

// At 2026-10-25 04:00:00 (cutoff): portal should be CLOSED
$tsNightCutoff = strtotime("2026-10-25 04:00:00");
$evalNightCutoff = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, $tsNightCutoff);
assertTest("Overnight shift is CLOSED at 04:00 AM cutoff", $evalNightCutoff['is_closed'] === true);
assertTest("Overnight shift closure message matches exact requirement", $evalNightCutoff['message'] === 'Your scheduled work hours have ended. Attendance access is now closed for today.');

// Reset employee shift back to test day shift
$pdo->prepare("UPDATE employees SET shift_id = ? WHERE id = ?")->execute([$testDayShiftId, $testEmpId]);

// ----------------------------------------------------------------------------
// TEST GROUP 7: APPROVED SCHEDULE CHANGE / CUSTOM EXCEPTION
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 7: Approved Schedule Exception / Overrides ---" . PHP_EOL;

// Create approved exception in attendance_requests for today: approved_check_out_time = '20:00:00'
$pdo->prepare("DELETE FROM attendance_requests WHERE employee_id = ? AND attendance_date = ?")->execute([$testEmpId, $today]);
$stmt = $pdo->prepare("INSERT INTO attendance_requests (employee_id, attendance_date, requested_work_mode, approved_check_in_time, approved_check_out_time, status, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$testEmpId, $today, 'on_site_exception', '10:00:00', '20:00:00', 'approved', 'Authorized overtime for project release.']);

// Clean attendance for test
$pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND date = ?")->execute([$testEmpId, $today]);

// At 18:30 (standard 18:00 cutoff passed, but exception cutoff is 20:00)
$tsExceptionBefore = strtotime("$today 18:30:00");
$evalExceptionBefore = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, $tsExceptionBefore);
assertTest("Portal remains OPEN at 18:30 due to approved exception cutoff (20:00)", $evalExceptionBefore['is_closed'] === false);
assertTest("Scheduled checkout time reflects approved exception (08:00 PM)", $evalExceptionBefore['scheduled_checkout_time'] === '08:00 PM');
assertTest("Seconds remaining until 20:00 is 5400 (1h 30m)", $evalExceptionBefore['seconds_remaining'] === 5400);

// At 20:05 (past exception cutoff)
$tsExceptionAfter = strtotime("$today 20:05:00");
$evalExceptionAfter = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, $tsExceptionAfter);
assertTest("Portal CLOSES past approved exception cutoff (20:05)", $evalExceptionAfter['is_closed'] === true);
assertTest("Exact message shown upon exception cutoff", $evalExceptionAfter['message'] === 'Your scheduled work hours have ended. Attendance access is now closed for today.');

// ----------------------------------------------------------------------------
// TEST GROUP 8: HR / ADMIN RESOLUTION CONSOLE & AUDIT LOGGING
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 8: HR/Admin Override & Audit Trail ---" . PHP_EOL;

// Setup a flagged attendance record
$pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND date = ?")->execute([$testEmpId, '2026-10-23']);
$stmt = $pdo->prepare("INSERT INTO attendance (employee_id, date, check_in, check_out, status, is_flagged_for_review, flag_reason, closed_by_system) VALUES (?, ?, ?, NULL, ?, 1, ?, 1)");
$stmt->execute([$testEmpId, '2026-10-23', '09:00:00', 'present', 'Scheduled shift cutoff reached without check-out. Preserved check-in data; HR review required.']);
$flaggedRecordId = (int)$pdo->lastInsertId();

$flaggedCountBefore = AttendanceHelper::getFlaggedAttendanceCount($pdo);
assertTest("Flagged attendance count is at least 1", $flaggedCountBefore >= 1);

// Perform HR resolution
$adminId = 1;
$resolutionResult = AttendanceHelper::adminResolveFlaggedAttendance($pdo, $flaggedRecordId, $adminId, [
    'approved_check_out' => '18:15:00',
    'status' => 'present',
    'notes' => 'Employee worked until 18:15 on deployment; manager confirmed.'
]);

assertTest("adminResolveFlaggedAttendance returns success", $resolutionResult['success'] === true);

// Verify record in database
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
$stmt->execute([$flaggedRecordId]);
$resolvedRec = $stmt->fetch(PDO::FETCH_ASSOC);

assertTest("is_flagged_for_review is cleared to 0", (int)$resolvedRec['is_flagged_for_review'] === 0);
assertTest("Check-out time is set to authorized '18:15:00'", $resolvedRec['check_out'] === '18:15:00');
assertTest("Working hours recalculated (>9 hours)", (float)$resolvedRec['working_hours'] >= 9.0);
assertTest("Remarks contain resolution audit string", strpos($resolvedRec['remarks'], 'HR Override by Admin') !== false);

// Verify audit log entry was written
$stmt = $pdo->prepare("SELECT * FROM admin_audit_logs WHERE action = 'resolve_flagged_attendance' AND target_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$flaggedRecordId]);
$auditLog = $stmt->fetch(PDO::FETCH_ASSOC);

assertTest("Audit log recorded with action 'resolve_flagged_attendance'", !empty($auditLog));
assertTest("Audit log records admin ID", (int)$auditLog['admin_id'] === $adminId);

$flaggedCountAfter = AttendanceHelper::getFlaggedAttendanceCount($pdo);
assertTest("Flagged count decreased after resolution", $flaggedCountAfter === ($flaggedCountBefore - 1));

// ----------------------------------------------------------------------------
// TEST GROUP 9: BACKEND ENFORCEMENT & HEARTBEAT API
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 9: Backend Enforcement & Heartbeat API ---" . PHP_EOL;

// 1. Backend closure validation gate blocks check-in after cutoff
$pdo->prepare("DELETE FROM attendance_requests WHERE employee_id = ?")->execute([$testEmpId]);
$gateCheck = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, strtotime("2026-10-24 18:30:00"));
assertTest("Backend closure gate rejects action past cutoff", $gateCheck['is_closed'] === true);
assertTest("Backend error message matches exact requirement", $gateCheck['message'] === 'Your scheduled work hours have ended. Attendance access is now closed for today.');

// 2. Heartbeat JSON payload structure verification
assertTest("Heartbeat payload contains boolean 'is_closed'", is_bool($gateCheck['is_closed']));
assertTest("Heartbeat payload contains 'scheduled_checkout_time'", !empty($gateCheck['scheduled_checkout_time']));
assertTest("Heartbeat payload contains 'time_remaining_text'", isset($gateCheck['time_remaining_text']));
assertTest("Heartbeat payload contains 'needs_hr_review'", is_bool($gateCheck['needs_hr_review']));

// 3. Geofencing & GPS location verification remains intact
$locCheck = AttendanceHelper::validateEmployeeLocation($pdo, 'on_site', 11.941600, 79.808300, 'check_in', $testEmpId);
assertTest("GPS geofence validation remains intact alongside closure", is_array($locCheck) && isset($locCheck['allowed']));

// ----------------------------------------------------------------------------
// TEST GROUP 10: CLEANUP TEST DATA
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 10: Cleanup & Teardown ---" . PHP_EOL;

$pdo->prepare("DELETE FROM attendance WHERE employee_id = ?")->execute([$testEmpId]);
$pdo->prepare("DELETE FROM attendance_requests WHERE employee_id = ?")->execute([$testEmpId]);
$pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$testEmpId]);
$pdo->prepare("DELETE FROM shift_settings WHERE id IN (?, ?)")->execute([$testDayShiftId, $testNightShiftId]);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE id = ?");
$stmt->execute([$testEmpId]);
assertTest("Test employee records successfully cleaned up", (int)$stmt->fetchColumn() === 0);

// ============================================================================
// TEST SUITE SUMMARY
// ============================================================================
echo PHP_EOL . "======================================================================" . PHP_EOL;
echo "  TEST SUMMARY" . PHP_EOL;
echo "  Total:  {$total}" . PHP_EOL;
echo "  Passed: {$passed}" . PHP_EOL;
echo "  Failed: {$failed}" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

if ($failed === 0) {
    echo "  ALL TESTS PASSED SUCCESSFULLY!" . PHP_EOL;
    exit(0);
} else {
    echo "  WARNING: {$failed} TEST(S) FAILED." . PHP_EOL;
    exit(1);
}
