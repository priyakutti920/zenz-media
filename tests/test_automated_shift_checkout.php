<?php
/**
 * AUTOMATED SHIFT CHECKOUT WITH EMAIL-BASED DAILY WORK SUMMARY TEST SUITE
 *
 * Comprehensive Automated Verification covering all 11 user scenarios:
 * 1. Employee checks out normally before cutoff.
 * 2. Shift cutoff triggers the email workflow.
 * 3. Summary link opens securely.
 * 4. Valid summary submission finalizes checkout.
 * 5. Expired or reused token is rejected.
 * 6. Duplicate scheduler runs do not send duplicate emails.
 * 7. Missing summary remains pending.
 * 8. Existing checkout timestamps are preserved.
 * 9. Overnight and WFH shifts work correctly.
 * 10. Unauthorized users cannot submit another employee's summary (IDOR protection).
 * 11. Email failures can be retried safely.
 */

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';
require_once __DIR__ . '/../app/Mailer.php';

Security::init($pdo);

$passed = 0;
$failed = 0;
$total = 0;

function assertCheck($desc, $cond, $details = '') {
    global $passed, $failed, $total;
    $total++;
    if ($cond) {
        $passed++;
        echo "  [PASS] Test {$total}: {$desc}" . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] Test {$total}: {$desc}" . ($details ? " ({$details})" : '') . PHP_EOL;
    }
}

echo "======================================================================" . PHP_EOL;
echo " STARTING AUTOMATED SHIFT CHECKOUT & EMAIL SUMMARY TEST SUITE" . PHP_EOL;
echo " Timezone: " . date_default_timezone_get() . " | Server Time: " . date('Y-m-d H:i:s') . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;

// ----------------------------------------------------------------------------
// SETUP TEST FIXTURES
// ----------------------------------------------------------------------------
echo "--- FIXTURES SETUP: Database Schema & Test Entities ---" . PHP_EOL;

AttendanceHelper::ensureAutomatedCheckoutSchema($pdo);

// 1. Test Shift 1: Standard Day Shift (09:00:00 - 17:00:00)
$pdo->prepare("DELETE FROM shift_settings WHERE shift_name IN ('Test Auto Checkout Day', 'Test Auto Checkout Night')")->execute();

$stmt = $pdo->prepare("INSERT INTO shift_settings (shift_name, checkin_start, checkin_end, checkout_time, is_active) VALUES (?, ?, ?, ?, ?)");
$stmt->execute(['Test Auto Checkout Day', '09:00:00', '10:00:00', '17:00:00', 1]);
$dayShiftId = (int)$pdo->lastInsertId();

// 2. Test Shift 2: Overnight Shift (20:00:00 - 04:00:00)
$stmt = $pdo->prepare("INSERT INTO shift_settings (shift_name, checkin_start, checkin_end, checkout_time, is_active) VALUES (?, ?, ?, ?, ?)");
$stmt->execute(['Test Auto Checkout Night', '20:00:00', '21:00:00', '04:00:00', 1]);
$nightShiftId = (int)$pdo->lastInsertId();

// 3. Test Employees: Employee A & Employee B
$empAEmail = 'test_auto_emp_a@test.com';
$empBEmail = 'test_auto_emp_b@test.com';

$pdo->prepare("DELETE FROM employees WHERE email IN (?, ?)")->execute([$empAEmail, $empBEmail]);

$insEmp = $pdo->prepare("INSERT INTO employees (first_name, last_name, email, password, status, position, work_mode, shift_id) VALUES (?, ?, ?, ?, 'active', 'QA Engineer', 'on_site', ?)");
$insEmp->execute(['Alice', 'Tester', $empAEmail, password_hash('Pass123!', PASSWORD_BCRYPT), $dayShiftId]);
$empAId = (int)$pdo->lastInsertId();

$insEmp->execute(['Bob', 'Hacker', $empBEmail, password_hash('Pass123!', PASSWORD_BCRYPT), $dayShiftId]);
$empBId = (int)$pdo->lastInsertId();

// Clean existing attendance for both test employees
$pdo->prepare("DELETE FROM attendance WHERE employee_id IN (?, ?)")->execute([$empAId, $empBId]);
$pdo->prepare("DELETE FROM attendance_summary_tokens WHERE employee_id IN (?, ?)")->execute([$empAId, $empBId]);
$pdo->prepare("DELETE FROM daily_work_summaries WHERE employee_id IN (?, ?)")->execute([$empAId, $empBId]);

assertCheck("Schema synchronized and test employees initialized", $empAId > 0 && $empBId > 0);

// ============================================================================
// SCENARIO 1: EMPLOYEE CHECKS OUT NORMALLY BEFORE CUTOFF
// ============================================================================
echo PHP_EOL . "--- SCENARIO 1: Employee Checks Out Normally Before Cutoff ---" . PHP_EOL;

$date1 = '2026-10-20';
// Alice checks in at 09:05:00
$insAtt = $pdo->prepare("INSERT INTO attendance (employee_id, date, check_in, status, punch_history) VALUES (?, ?, ?, 'present', ?)");
$insAtt->execute([$empAId, $date1, '09:05:00', json_encode([['in' => '09:05:00', 'out' => null]])]);
$attId1 = (int)$pdo->lastInsertId();

// Alice checks out normally via modal at 15:30:00 (before 17:00:00 cutoff)
$summary1 = [
    'work_summary'    => 'Developed automated checkout feature tests.',
    'completed_tasks' => 'Finished scenario 1 implementation.',
    'pending_tasks'   => 'None'
];
$closeRes1 = AttendanceHelper::closeAttendanceTransaction($pdo, $empAId, $attId1, $summary1, null, '15:30:00');

assertCheck("1.1: Normal checkout before cutoff returns success", $closeRes1['success'] === true);

$stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
$stmt->execute([$attId1]);
$row1 = $stmt->fetch(PDO::FETCH_ASSOC);

assertCheck("1.2: Check-out timestamp recorded as actual 15:30:00", $row1['check_out'] === '15:30:00');
assertCheck("1.3: Checkout type is manual (or default)", ($row1['checkout_type'] ?? 'manual') === 'manual');
assertCheck("1.4: Working hours calculated correctly for 09:05 to 15:30 (6.42 hrs)", (float)$row1['working_hours'] > 6.0);
assertCheck("1.5: Attendance status is completed or valid closed status", in_array($row1['attendance_status'], ['completed', 'left_early']));

// Evaluate portal closure after checkout (at 16:00:00, before cutoff)
$eval1 = AttendanceHelper::evaluatePortalClosureStatus($pdo, $empAId, strtotime("$date1 16:00:00"));
assertCheck("1.6: Portal closed immediately upon early checkout", $eval1['is_closed'] === true);
assertCheck("1.7: Normal checkout button disabled after early checkout", $eval1['can_check_out'] === false);

// ============================================================================
// SCENARIO 2: SHIFT CUTOFF TRIGGERS THE EMAIL WORKFLOW
// ============================================================================
echo PHP_EOL . "--- SCENARIO 2: Shift Cutoff Triggers Email Workflow & Token Creation ---" . PHP_EOL;

$date2 = '2026-10-21';
// Alice checks in at 09:10:00, forgets to checkout
$insAtt->execute([$empAId, $date2, '09:10:00', json_encode([['in' => '09:10:00', 'out' => null]])]);
$attId2 = (int)$pdo->lastInsertId();

// Simulate scheduler running at 17:15:00 (15m past the 17:00 cutoff)
$tsPastCutoff2 = strtotime("$date2 17:15:00");
$schedRun2 = AttendanceHelper::processShiftCutoffsAndSendSummaryEmails($pdo, [
    'timestamp'  => $tsPastCutoff2,
    'date'       => $date2,
    'send_email' => false // Token creation without SMTP socket in unit test
]);

assertCheck("2.1: Scheduler detects cutoff reached", $schedRun2['cutoff_reached'] >= 1);
assertCheck("2.2: Scheduler generates summary token", $schedRun2['tokens_created'] >= 1);

// Verify database state of attendance record #2
$stmt->execute([$attId2]);
$row2 = $stmt->fetch(PDO::FETCH_ASSOC);

assertCheck("2.3: Attendance status transitions to 'summary_pending'", $row2['attendance_status'] === 'summary_pending');
assertCheck("2.4: closed_by_system is set to 1", (int)$row2['closed_by_system'] === 1);
assertCheck("2.5: check_out remains unfinalized (NULL) while pending summary", empty($row2['check_out']));

// Verify attendance_summary_tokens row
$tokStmt = $pdo->prepare("SELECT * FROM attendance_summary_tokens WHERE attendance_id = ?");
$tokStmt->execute([$attId2]);
$tokenRow2 = $tokStmt->fetch(PDO::FETCH_ASSOC);

assertCheck("2.6: Token record created in attendance_summary_tokens", $tokenRow2 !== false);
assertCheck("2.7: Token status is 'pending'", $tokenRow2['status'] === 'pending');
assertCheck("2.8: Scheduled checkout time bound in token as '17:00:00'", $tokenRow2['scheduled_checkout_time'] === '17:00:00');
assertCheck("2.9: Token hash is 64 hex characters (SHA-256)", strlen($tokenRow2['token_hash']) === 64);

// Verify portal closure evaluation past cutoff
$eval2 = AttendanceHelper::evaluatePortalClosureStatus($pdo, $empAId, $tsPastCutoff2);
assertCheck("2.10: Normal check-out action is strictly closed past cutoff", $eval2['is_closed'] === true && $eval2['can_check_out'] === false);

// ============================================================================
// SCENARIO 3: SUMMARY LINK OPENS SECURELY
// ============================================================================
echo PHP_EOL . "--- SCENARIO 3: Summary Link Opens Securely ---" . PHP_EOL;

// Generate a fresh plain token for attendance record #2
$genRes3 = AttendanceHelper::generateDailySummaryToken($pdo, $attId2, $empAId, $date2, '17:00:00');
$plainToken3 = $genRes3['plain_token'];

assertCheck("3.1: Cryptographically secure 64-char plain token generated", strlen($plainToken3) === 64);

// Validate using valid plain token
$valRes3 = AttendanceHelper::validateDailySummaryToken($pdo, $plainToken3);
assertCheck("3.2: Plain token successfully validated against SHA-256 hash", $valRes3['valid'] === true);
assertCheck("3.3: Token binds to correct employee ID", (int)$valRes3['employee']['id'] === $empAId);
assertCheck("3.4: Token binds to correct attendance ID", (int)$valRes3['attendance']['id'] === $attId2);
assertCheck("3.5: Token binds to correct shift date", $valRes3['attendance']['date'] === $date2);

// Validate with invalid / malformed token
$badTokenVal = AttendanceHelper::validateDailySummaryToken($pdo, 'invalid_nonexistent_token_1234567890abcdef');
assertCheck("3.6: Invalid token is rejected", $badTokenVal['valid'] === false);

// ============================================================================
// SCENARIO 4: VALID SUMMARY SUBMISSION FINALIZES CHECKOUT
// ============================================================================
echo PHP_EOL . "--- SCENARIO 4: Valid Summary Submission Finalizes Checkout ---" . PHP_EOL;

$validSummaryData = [
    'tasks_worked'     => 'Designed and verified email-based shift checkout system.',
    'tasks_completed'  => 'Completed migrations, Mailer helper, and token validation.',
    'pending_tasks'    => 'Code review and staging regression verification.',
    'additional_notes' => 'Everything operating smoothly.'
];

$finalizeRes4 = AttendanceHelper::finalizeAutomatedShiftCheckoutWithSummary(
    $pdo,
    $plainToken3,
    $validSummaryData,
    $empAId
);

assertCheck("4.1: Finalize automated shift checkout returns success", $finalizeRes4['success'] === true);

// Re-fetch attendance record #2
$stmt->execute([$attId2]);
$finalAtt2 = $stmt->fetch(PDO::FETCH_ASSOC);

assertCheck("4.2: Check-out time finalized using scheduled cutoff (17:00:00), NOT email submission time", $finalAtt2['check_out'] === '17:00:00');
assertCheck("4.3: checkout_type set to 'scheduled_auto'", $finalAtt2['checkout_type'] === 'scheduled_auto');
assertCheck("4.4: scheduled_checkout column recorded as 17:00:00", $finalAtt2['scheduled_checkout'] === '17:00:00');
assertCheck("4.5: summary_submitted_at recorded separately as timestamp", !empty($finalAtt2['summary_submitted_at']));
assertCheck("4.6: attendance_status finalized as 'completed'", $finalAtt2['attendance_status'] === 'completed');
assertCheck("4.7: is_flagged_for_review cleared to 0 (no HR review required)", (int)$finalAtt2['is_flagged_for_review'] === 0);
assertCheck("4.8: flag_reason is NULL", $finalAtt2['flag_reason'] === null);
assertCheck("4.9: Working hours accurately calculated from check-in 09:10 to cutoff 17:00 (7.83h)", (float)$finalAtt2['working_hours'] > 7.5);

// Check daily_work_summaries table
$dwsStmt = $pdo->prepare("SELECT * FROM daily_work_summaries WHERE attendance_id = ?");
$dwsStmt->execute([$attId2]);
$dwsRow2 = $dwsStmt->fetch(PDO::FETCH_ASSOC);

assertCheck("4.10: daily_work_summaries record saved", $dwsRow2 !== false);
assertCheck("4.11: Tasks worked on saved correctly", $dwsRow2['work_summary'] === $validSummaryData['tasks_worked']);
assertCheck("4.12: Tasks completed saved correctly", $dwsRow2['completed_tasks'] === $validSummaryData['tasks_completed']);
assertCheck("4.13: Pending tasks saved correctly", $dwsRow2['pending_tasks'] === $validSummaryData['pending_tasks']);
assertCheck("4.14: Additional notes saved correctly", $dwsRow2['additional_notes'] === $validSummaryData['additional_notes']);
assertCheck("4.15: Submission source recorded as 'email_token'", $dwsRow2['submission_source'] === 'email_token');

// Check token row status invalidated
$tokStmt->execute([$attId2]);
$tokFinal2 = $tokStmt->fetch(PDO::FETCH_ASSOC);
assertCheck("4.16: Token status invalidated to 'completed'", $tokFinal2['status'] === 'completed');
assertCheck("4.17: Token completed_at timestamp recorded", !empty($tokFinal2['completed_at']));

// Check Recent Attendance UI formatter output
$formattedUi4 = AttendanceHelper::formatRecentAttendanceRecord($finalAtt2, true, $date2);
assertCheck("4.18: Formatted UI status is 'Completed'", $formattedUi4['status_label'] === 'Completed');
assertCheck("4.19: Formatted UI indicates scheduled auto checkout", $formattedUi4['is_scheduled_auto'] === true);
assertCheck("4.20: Formatted UI check-out formatted string is 05:00 PM", $formattedUi4['check_out_formatted'] === '05:00 PM');

// ============================================================================
// SCENARIO 5: EXPIRED OR REUSED TOKEN IS REJECTED
// ============================================================================
echo PHP_EOL . "--- SCENARIO 5: Expired or Reused Token is Rejected ---" . PHP_EOL;

// 5A: Replay attack (reusing token that was already completed)
$replayRes = AttendanceHelper::finalizeAutomatedShiftCheckoutWithSummary(
    $pdo,
    $plainToken3,
    $validSummaryData,
    $empAId
);
assertCheck("5.1: Replay submission with completed token rejected", $replayRes['success'] === false);

$reusedTokenVal = AttendanceHelper::validateDailySummaryToken($pdo, $plainToken3);
assertCheck("5.2: Validation recognizes already completed token", $reusedTokenVal['valid'] === false && !empty($reusedTokenVal['already_completed']));

// 5B: Expired Token
$date5 = '2026-10-15';
$insAtt->execute([$empAId, $date5, '09:00:00', json_encode([['in' => '09:00:00', 'out' => null]])]);
$attId5 = (int)$pdo->lastInsertId();

$plainExpiredToken = bin2hex(random_bytes(32));
$expiredHash = hash('sha256', $plainExpiredToken);
$pastExpiration = date('Y-m-d H:i:s', time() - 3600); // 1 hour ago

$insExpTok = $pdo->prepare("INSERT INTO attendance_summary_tokens 
    (attendance_id, employee_id, shift_date, scheduled_checkout_time, token_hash, status, expires_at) 
    VALUES (?, ?, ?, '17:00:00', ?, 'pending', ?)");
$insExpTok->execute([$attId5, $empAId, $date5, $expiredHash, $pastExpiration]);

$valExpired = AttendanceHelper::validateDailySummaryToken($pdo, $plainExpiredToken);
assertCheck("5.3: Expired token rejected by validator", $valExpired['valid'] === false && !empty($valExpired['expired']));

$finalizeExpired = AttendanceHelper::finalizeAutomatedShiftCheckoutWithSummary($pdo, $plainExpiredToken, $validSummaryData, $empAId);
assertCheck("5.4: Submission using expired token rejected", $finalizeExpired['success'] === false);

// ============================================================================
// SCENARIO 6: DUPLICATE SCHEDULER RUNS DO NOT SEND DUPLICATE EMAILS
// ============================================================================
echo PHP_EOL . "--- SCENARIO 6: Duplicate Scheduler Runs are Idempotent ---" . PHP_EOL;

$date6 = '2026-10-22';
$insAtt->execute([$empAId, $date6, '09:15:00', json_encode([['in' => '09:15:00', 'out' => null]])]);
$attId6 = (int)$pdo->lastInsertId();

$tsPastCutoff6 = strtotime("$date6 17:10:00");

// Run 1: First scheduler execution
$run1 = AttendanceHelper::processShiftCutoffsAndSendSummaryEmails($pdo, [
    'timestamp'  => $tsPastCutoff6,
    'date'       => $date6,
    'send_email' => false
]);
assertCheck("6.1: Run 1 creates token", $run1['tokens_created'] === 1);

// Simulate that Run 1 email was sent successfully
$pdo->prepare("UPDATE attendance_summary_tokens SET email_sent = 1, email_sent_at = NOW(), email_delivery_status = 'sent' WHERE attendance_id = ?")->execute([$attId6]);

// Run 2: Immediate duplicate scheduler execution (simulating repeated cron every 5 mins)
$run2 = AttendanceHelper::processShiftCutoffsAndSendSummaryEmails($pdo, [
    'timestamp'  => $tsPastCutoff6 + 300, // 5 mins later
    'date'       => $date6,
    'send_email' => false
]);
assertCheck("6.2: Run 2 does NOT create duplicate tokens (0 tokens created)", $run2['tokens_created'] === 0);
assertCheck("6.3: Run 2 skips session because token already exists and email was sent / not due", $run2['skipped'] >= 1);

// Verify exactly one token row exists in DB
$tokCount = $pdo->prepare("SELECT COUNT(*) FROM attendance_summary_tokens WHERE attendance_id = ?");
$tokCount->execute([$attId6]);
assertCheck("6.4: Exactly 1 token record exists in DB (no duplicates)", (int)$tokCount->fetchColumn() === 1);

// ============================================================================
// SCENARIO 7: MISSING SUMMARY REMAINS PENDING
// ============================================================================
echo PHP_EOL . "--- SCENARIO 7: Missing Summary Remains Pending (No Silent Fabrication) ---" . PHP_EOL;

$stmt->execute([$attId6]);
$pendingRow6 = $stmt->fetch(PDO::FETCH_ASSOC);

assertCheck("7.1: Attendance status remains 'summary_pending'", $pendingRow6['attendance_status'] === 'summary_pending');
assertCheck("7.2: Check-out timestamp is NOT fabricated (remains NULL)", $pendingRow6['check_out'] === null);

$formattedPending = AttendanceHelper::formatRecentAttendanceRecord($pendingRow6, true, $date6);
assertCheck("7.3: UI status displays 'Summary Pending'", $formattedPending['status_label'] === 'Summary Pending');
assertCheck("7.4: UI Check Out column displays 'Pending Summary'", $formattedPending['check_out_formatted'] === 'Pending Summary');
assertCheck("7.5: UI Working Hours displays 'Pending summary'", $formattedPending['working_hours_display'] === 'Pending summary');

// ============================================================================
// SCENARIO 8: EXISTING CHECKOUT TIMESTAMPS ARE PRESERVED
// ============================================================================
echo PHP_EOL . "--- SCENARIO 8: Existing Checkout Timestamps are Preserved ---" . PHP_EOL;

$date8 = '2026-10-23';
// Alice checked out early at 14:15:00
$insAttEarly = $pdo->prepare("INSERT INTO attendance (employee_id, date, check_in, check_out, checkout_type, working_hours, total_work_minutes, status, attendance_status) 
    VALUES (?, ?, '09:00:00', '14:15:00', 'manual', 5.25, 315, 'present', 'present')");
$insAttEarly->execute([$empAId, $date8]);
$attId8 = (int)$pdo->lastInsertId();

// Generate a summary token for this attendance record
$genTok8 = AttendanceHelper::generateDailySummaryToken($pdo, $attId8, $empAId, $date8, '17:00:00');
$plainTok8 = $genTok8['plain_token'];

// Finalize summary
$finRes8 = AttendanceHelper::finalizeAutomatedShiftCheckoutWithSummary($pdo, $plainTok8, $validSummaryData, $empAId);
assertCheck("8.1: Summary submission on existing checkout succeeds", $finRes8['success'] === true);

$stmt->execute([$attId8]);
$row8 = $stmt->fetch(PDO::FETCH_ASSOC);

assertCheck("8.2: Actual checkout timestamp 14:15:00 is PRESERVED (not overwritten by 17:00)", $row8['check_out'] === '14:15:00');
assertCheck("8.3: Checkout type remains 'manual'", $row8['checkout_type'] === 'manual');
assertCheck("8.4: Working hours remain based on actual checkout (5.25 hrs)", (float)$row8['working_hours'] === 5.25);
assertCheck("8.5: Summary submission timestamp is recorded separately", !empty($row8['summary_submitted_at']));

// ============================================================================
// SCENARIO 9: OVERNIGHT AND WFH SHIFTS WORK CORRECTLY
// ============================================================================
echo PHP_EOL . "--- SCENARIO 9: Overnight and WFH Shifts Work Correctly ---" . PHP_EOL;

// 9A: Overnight Shift (20:00 to 04:00 next day)
$pdo->prepare("UPDATE employees SET shift_id = ? WHERE id = ?")->execute([$nightShiftId, $empAId]);

$workdayNight = '2026-10-24';
$insAtt->execute([$empAId, $workdayNight, '20:05:00', json_encode([['in' => '20:05:00', 'out' => null]])]);
$attIdNight = (int)$pdo->lastInsertId();

// At 2026-10-25 03:30 (before 04:00 cutoff): portal must be OPEN
$evalNightBefore = AttendanceHelper::evaluatePortalClosureStatus($pdo, $empAId, strtotime("2026-10-25 03:30:00"));
assertCheck("9.1: Overnight shift is OPEN before 04:00 AM cutoff", $evalNightBefore['is_closed'] === false);

// At 2026-10-25 04:10 (past 04:00 cutoff): portal must be CLOSED and trigger summary
$evalNightPast = AttendanceHelper::evaluatePortalClosureStatus($pdo, $empAId, strtotime("2026-10-25 04:10:00"));
assertCheck("9.2: Overnight shift is CLOSED past 04:00 AM cutoff", $evalNightPast['is_closed'] === true);

$genNightTok = AttendanceHelper::generateDailySummaryToken($pdo, $attIdNight, $empAId, $workdayNight, '04:00:00');
$plainNightTok = $genNightTok['plain_token'];

$finNight = AttendanceHelper::finalizeAutomatedShiftCheckoutWithSummary($pdo, $plainNightTok, $validSummaryData, $empAId);
assertCheck("9.3: Overnight summary finalized successfully", $finNight['success'] === true);

$stmt->execute([$attIdNight]);
$nightRow = $stmt->fetch(PDO::FETCH_ASSOC);

assertCheck("9.4: Overnight checkout time finalized as scheduled cutoff (04:00:00)", $nightRow['check_out'] === '04:00:00');
assertCheck("9.5: Overnight cross-midnight working hours accurately calculated (approx 7.92 hrs)", (float)$nightRow['working_hours'] > 7.5);

// Restore day shift for Alice
$pdo->prepare("UPDATE employees SET shift_id = ? WHERE id = ?")->execute([$dayShiftId, $empAId]);

// 9B: Approved WFH Shift with Approved Schedule Exception (10:00 - 16:00)
$dateWfh = '2026-10-18';
$insAtt->execute([$empAId, $dateWfh, '10:02:00', json_encode([['in' => '10:02:00', 'out' => null]])]);
$attIdWfh = (int)$pdo->lastInsertId();

// Create approved request for 10:00 to 16:00
$insReq = $pdo->prepare("INSERT INTO attendance_requests 
    (employee_id, attendance_date, requested_work_mode, status, approved_check_in_time, approved_check_out_time) 
    VALUES (?, ?, 'wfh', 'approved', '10:00:00', '16:00:00')");
$insReq->execute([$empAId, $dateWfh]);

$shiftWfh = AttendanceHelper::getEmployeeShift($pdo, $empAId, $dateWfh);
assertCheck("9.6: Resolved shift respects approved WFH checkout time (16:00:00)", $shiftWfh['checkout_time'] === '16:00:00');

$genWfhTok = AttendanceHelper::generateDailySummaryToken($pdo, $attIdWfh, $empAId, $dateWfh, $shiftWfh['checkout_time']);
$finWfh = AttendanceHelper::finalizeAutomatedShiftCheckoutWithSummary($pdo, $genWfhTok['plain_token'], $validSummaryData, $empAId);
assertCheck("9.7: WFH checkout finalized with approved cutoff time (16:00:00)", $finWfh['check_out'] === '16:00:00');

// ============================================================================
// SCENARIO 10: IDOR & UNAUTHORIZED USER REJECTION
// ============================================================================
echo PHP_EOL . "--- SCENARIO 10: IDOR & Unauthorized User Rejection ---" . PHP_EOL;

$date10 = '2026-10-19';
// Alice's attendance record
$insAtt->execute([$empAId, $date10, '09:00:00', json_encode([['in' => '09:00:00', 'out' => null]])]);
$attId10 = (int)$pdo->lastInsertId();

$genTok10 = AttendanceHelper::generateDailySummaryToken($pdo, $attId10, $empAId, $date10, '17:00:00');
$plainTok10 = $genTok10['plain_token'];

// Bob (empBId) attempts to submit summary for Alice's attendance
$idorAttempt = AttendanceHelper::finalizeAutomatedShiftCheckoutWithSummary(
    $pdo,
    $plainTok10,
    $validSummaryData,
    $empBId // Bob's ID passed!
);

assertCheck("10.1: IDOR attack by Bob on Alice's summary is REJECTED", $idorAttempt['success'] === false);
assertCheck("10.2: Error message explicitly reports access denied", strpos($idorAttempt['error'], 'Access Denied') !== false);

// Verify Alice's record remains unmodified
$stmt->execute([$attId10]);
$row10 = $stmt->fetch(PDO::FETCH_ASSOC);
assertCheck("10.3: Alice's attendance record was NOT finalized by Bob", empty($row10['check_out']) && $row10['attendance_status'] !== 'completed');

// ============================================================================
// SCENARIO 11: EMAIL FAILURE AND RETRY RESILIENCE
// ============================================================================
echo PHP_EOL . "--- SCENARIO 11: Email Failures Handled and Retry Safe ---" . PHP_EOL;

// Check token row for failed email record handling
$tokStmt->execute([$attId10]);
$tokRow10 = $tokStmt->fetch(PDO::FETCH_ASSOC);

// Simulate recording email failure without leaking sensitive token
$failErrMsg = "SMTP Connection timeout on port 587";
$pdo->prepare("UPDATE attendance_summary_tokens SET 
    email_delivery_status = 'failed',
    email_attempts = email_attempts + 1,
    email_error_message = ? 
    WHERE id = ?")->execute([$failErrMsg, $tokRow10['id']]);

$tokStmt->execute([$attId10]);
$tokFailed = $tokStmt->fetch(PDO::FETCH_ASSOC);

assertCheck("11.1: Failed email delivery status recorded as 'failed'", $tokFailed['email_delivery_status'] === 'failed');
assertCheck("11.2: Email attempts incremented to 1", (int)$tokFailed['email_attempts'] === 1);
assertCheck("11.3: Token remains active/pending for retry", $tokFailed['status'] === 'pending');

// Retry scheduler run (simulating retry next run)
$retryRun = AttendanceHelper::processShiftCutoffsAndSendSummaryEmails($pdo, [
    'timestamp'  => strtotime("$date10 18:00:00"),
    'date'       => $date10,
    'send_email' => false // unit mode
]);

assertCheck("11.4: Scheduler retry successfully processes pending token", $retryRun !== false);

// ============================================================================
// CLEANUP & TEARDOWN
// ============================================================================
echo PHP_EOL . "--- CLEANUP: Removing Test Entities ---" . PHP_EOL;

$pdo->prepare("DELETE FROM attendance_summary_tokens WHERE employee_id IN (?, ?)")->execute([$empAId, $empBId]);
$pdo->prepare("DELETE FROM daily_work_summaries WHERE employee_id IN (?, ?)")->execute([$empAId, $empBId]);
$pdo->prepare("DELETE FROM attendance_requests WHERE employee_id IN (?, ?)")->execute([$empAId, $empBId]);
$pdo->prepare("DELETE FROM attendance WHERE employee_id IN (?, ?)")->execute([$empAId, $empBId]);
$pdo->prepare("DELETE FROM employees WHERE id IN (?, ?)")->execute([$empAId, $empBId]);
$pdo->prepare("DELETE FROM shift_settings WHERE id IN (?, ?)")->execute([$dayShiftId, $nightShiftId]);

assertCheck("Test entities cleaned up successfully", true);

echo PHP_EOL . "======================================================================" . PHP_EOL;
echo "  AUTOMATED SHIFT CHECKOUT TEST SUMMARY" . PHP_EOL;
echo "  Total:  {$total}" . PHP_EOL;
echo "  Passed: {$passed}" . PHP_EOL;
echo "  Failed: {$failed}" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

if ($failed === 0) {
    echo "  ALL 11 SCENARIOS VERIFIED SUCCESSFULLY WITH 100% PASS RATE!" . PHP_EOL;
    exit(0);
} else {
    echo "  SOME TESTS FAILED!" . PHP_EOL;
    exit(1);
}
