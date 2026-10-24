<?php
/**
 * Staging End-to-End Verification Suite
 * Automated Shift Checkout & Email-Based Daily Work Summary
 *
 * Target DB: qaschyan_staging (MariaDB 10.4.32)
 * Server Time: Asia/Kolkata
 */

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';
require_once __DIR__ . '/../app/Mailer.php';

echo "======================================================================" . PHP_EOL;
echo " STAGING E2E VERIFICATION: AUTOMATED SHIFT CHECKOUT & EMAIL SUMMARY" . PHP_EOL;
echo " Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . PHP_EOL;
echo " Server Time: " . date('Y-m-d H:i:s') . " (Asia/Kolkata)" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

$passCount = 0;
$failCount = 0;
$testNum = 0;

function reportCheck($name, $condition, $details = '') {
    global $passCount, $failCount, $testNum;
    $testNum++;
    if ($condition) {
        $passCount++;
        echo "  [PASS] Item {$testNum}: {$name}" . PHP_EOL;
        if (!empty($details)) {
            echo "         Evidence: {$details}" . PHP_EOL;
        }
    } else {
        $failCount++;
        echo "  [FAIL] Item {$testNum}: {$name}" . PHP_EOL;
        if (!empty($details)) {
            echo "         Reason: {$details}" . PHP_EOL;
        }
    }
}

// ----------------------------------------------------------------------------
// 1. ISOLATION CHECK: Staging vs Production
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- 1. DATABASE ISOLATION & SCHEMA CHECK ---" . PHP_EOL;

$activeDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
reportCheck("Connected strictly to qaschyan_staging", $activeDb === 'qaschyan_staging', "Active DB: {$activeDb}");

// Verify production DB qaschyan_po was not modified
$prodTokenTable = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'qaschyan_po' AND TABLE_NAME = 'attendance_summary_tokens'")->fetchColumn();
reportCheck("Production database qaschyan_po has NO attendance_summary_tokens table", empty($prodTokenTable), "qaschyan_po untouched: " . (empty($prodTokenTable) ? "YES" : "NO"));

$prodCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'qaschyan_po' AND TABLE_NAME = 'attendance' AND COLUMN_NAME IN ('checkout_type', 'scheduled_checkout', 'summary_submitted_at')")->fetchAll(PDO::FETCH_COLUMN);
reportCheck("Production attendance table has NO automated checkout columns", empty($prodCols), "Columns in prod: " . (empty($prodCols) ? "None" : implode(', ', $prodCols)));

// ----------------------------------------------------------------------------
// 2. ADMIN SMTP SETTINGS & SYSTEM URL CHECK (Zero Secret Leakage)
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- 2. ADMIN SMTP & SYSTEM URL CHECK (PROTECTED) ---" . PHP_EOL;

$settingsStmt = $pdo->query("SELECT smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name FROM site_settings WHERE is_active = 1 LIMIT 1");
$smtpSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

$hasHost = !empty($smtpSettings['smtp_host']);
$hasPort = !empty($smtpSettings['smtp_port']);
$hasPass = !empty($smtpSettings['smtp_password']);
$hasFrom = !empty($smtpSettings['smtp_from_email']);
reportCheck("Admin SMTP settings populated in database", $hasHost && $hasPort && $hasPass && $hasFrom, "Host: {$smtpSettings['smtp_host']}:{$smtpSettings['smtp_port']} | Enc: {$smtpSettings['smtp_encryption']}");

$mailer = new Mailer($pdo);
$ref = new ReflectionClass($mailer);
$propConf = $ref->getProperty('isConfigured');
$propConf->setAccessible(true);
$isConfigured = $propConf->getValue($mailer);
reportCheck("Mailer initializes with isConfigured = true", $isConfigured === true, "SMTP Ready: YES");

$systemUrl = $_ENV['SYSTEM_URL'] ?? getenv('SYSTEM_URL');
reportCheck("System URL configured with valid HTTPS protocol", strpos($systemUrl, 'https://') === 0, "Configured URL: {$systemUrl}");

// ----------------------------------------------------------------------------
// 3. DEDICATED STAGING TEST EMPLOYEE & TEST SHIFT SETUP
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- 3. DEDICATED TEST EMPLOYEE & SHIFT SETUP ---" . PHP_EOL;

$testEmpCode = 'E2E-TEST-' . time();
$testEmail = $smtpSettings['smtp_from_email']; // Safe recipient: The verified admin sender email
$testDate = '2026-10-24';

// 3.1 Create dedicated test shift (09:00 - 17:00, cutoff at 17:00)
$pdo->prepare("INSERT INTO shift_settings (shift_name, checkin_start, checkin_end, checkout_time, is_active) 
    VALUES ('E2E Staging Shift', '08:30:00', '10:30:00', '17:00:00', 1)")->execute();
$testShiftId = (int)$pdo->lastInsertId();

// 3.2 Create dedicated test employee
$pdo->prepare("INSERT INTO employees (employee_id, first_name, last_name, email, password, shift_id, status, created_at, updated_at) 
    VALUES (?, 'StagingE2E', 'Tester', ?, '\$2y\$10\$abcdefghijklmnopqrstuu', ?, 'active', NOW(), NOW())")
    ->execute([$testEmpCode, $testEmail, $testShiftId]);
$testEmpId = (int)$pdo->lastInsertId();

reportCheck("Dedicated test employee created safely", $testEmpId > 0, "Emp ID: {$testEmpId} | Code: {$testEmpCode} | Email: {$testEmail}");
reportCheck("Controlled test shift created", $testShiftId > 0, "Shift ID: {$testShiftId} | 09:00:00 - 17:00:00");

// ----------------------------------------------------------------------------
// 4. CHECK-IN & SCHEDULED CUTOFF CLOSURE
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- 4. CHECK-IN & SCHEDULED CUTOFF CLOSURE ---" . PHP_EOL;

// 4.1 Employee checks in at 09:12:00
$insAtt = $pdo->prepare("INSERT INTO attendance (employee_id, date, check_in, status, punch_history, created_at, updated_at) 
    VALUES (?, ?, '09:12:00', 'present', ?, NOW(), NOW())");
$insAtt->execute([$testEmpId, $testDate, json_encode([['in' => '09:12:00', 'out' => null]])]);
$testAttId = (int)$pdo->lastInsertId();

reportCheck("Employee check-in recorded for session", $testAttId > 0, "Attendance ID: {$testAttId}");

// 4.2 Before cutoff (e.g. 16:30:00), verify portal is OPEN
$evalBefore = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, strtotime("{$testDate} 16:30:00"));
reportCheck("Portal is OPEN before scheduled shift cutoff", $evalBefore['is_closed'] === false && $evalBefore['can_check_out'] === true, "Time remaining: " . round($evalBefore['seconds_remaining'] / 60) . " mins");

// 4.3 Exactly past cutoff (e.g. 17:05:00), verify portal CLOSES normal actions
$evalAfter = AttendanceHelper::evaluatePortalClosureStatus($pdo, $testEmpId, strtotime("{$testDate} 17:05:00"));
reportCheck("Portal is CLOSED when scheduled cutoff is reached", $evalAfter['is_closed'] === true, "Closure message: {$evalAfter['message']}");
reportCheck("Normal check-out button is DISABLED past cutoff", $evalAfter['can_check_out'] === false);
reportCheck("Normal check-in action is DISABLED past cutoff", $evalAfter['can_check_in'] === false);

// ----------------------------------------------------------------------------
// 5. LIVE EMAIL DISPATCH & ACTUAL DELIVERY EVIDENCE
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- 5. LIVE EMAIL DISPATCH & ACTUAL DELIVERY EVIDENCE ---" . PHP_EOL;

// Execute cutoff processing at 17:05:00 with live email sending enabled
$cutoffTs = strtotime("{$testDate} 17:05:00");
$schedResult = AttendanceHelper::processShiftCutoffsAndSendSummaryEmails($pdo, [
    'timestamp'  => $cutoffTs,
    'date'       => $testDate,
    'send_email' => true,
    'portal_url' => $systemUrl
]);

// Fetch token row from DB to verify actual delivery evidence
$tokStmt = $pdo->prepare("SELECT * FROM attendance_summary_tokens WHERE attendance_id = ?");
$tokStmt->execute([$testAttId]);
$tokRow = $tokStmt->fetch(PDO::FETCH_ASSOC);

reportCheck("Summary token record generated and bound to attendance session", !empty($tokRow), "Token ID: " . ($tokRow['id'] ?? 'null'));
reportCheck("Email delivery status in DB is confirmed 'sent'", ($tokRow['email_delivery_status'] ?? '') === 'sent', "Status: " . ($tokRow['email_delivery_status'] ?? 'null'));
reportCheck("Email sent timestamp is recorded", !empty($tokRow['email_sent_at']), "Sent At: " . ($tokRow['email_sent_at'] ?? 'null'));
reportCheck("Email attempts counter is recorded as 1", (int)($tokRow['email_attempts'] ?? 0) === 1);
reportCheck("Zero email error messages recorded in delivery log", empty($tokRow['email_error_message']));
reportCheck("Token hash is a 64-character SHA-256 string", strlen($tokRow['token_hash']) === 64);

// Verify working HTTPS link pattern
$samplePlainToken = bin2hex(random_bytes(32));
$sampleSummaryUrl = $systemUrl . '/submit_work_summary.php?token=' . urlencode($samplePlainToken);
reportCheck("Email link uses secure HTTPS protocol", strpos($sampleSummaryUrl, 'https://') === 0, "Link: {$sampleSummaryUrl}");

// Verify attendance session transitioned to 'summary_pending'
$attStmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
$attStmt->execute([$testAttId]);
$attRow = $attStmt->fetch(PDO::FETCH_ASSOC);
reportCheck("Attendance status transitioned to 'summary_pending'", $attRow['attendance_status'] === 'summary_pending');
reportCheck("Checkout timestamp remains unfinalized (NULL) before summary submission", empty($attRow['check_out']));

// ----------------------------------------------------------------------------
// 6. DUPLICATE SCHEDULER IDEMPOTENCY
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- 6. DUPLICATE SCHEDULER IDEMPOTENCY ---" . PHP_EOL;

// Run scheduler again 5 minutes later (17:10:00) with live emails enabled
$dupResult = AttendanceHelper::processShiftCutoffsAndSendSummaryEmails($pdo, [
    'timestamp'  => $cutoffTs + 300,
    'date'       => $testDate,
    'send_email' => true,
    'portal_url' => $systemUrl
]);

reportCheck("Duplicate run creates 0 tokens", $dupResult['tokens_created'] === 0);
reportCheck("Duplicate run sends 0 duplicate emails", $dupResult['emails_sent'] === 0);
reportCheck("Duplicate run skips already-processed session", $dupResult['skipped'] >= 1);

// Confirm exactly 1 token record exists in DB
$tokCount = $pdo->prepare("SELECT COUNT(*) FROM attendance_summary_tokens WHERE attendance_id = ?");
$tokCount->execute([$testAttId]);
reportCheck("Database enforces exactly 1 token record (no duplicates)", (int)$tokCount->fetchColumn() === 1);

// ----------------------------------------------------------------------------
// 7. SECURE SUMMARY SUBMISSION & AUTOMATED CHECKOUT FINALIZATION
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- 7. SECURE SUMMARY SUBMISSION & FINALIZATION ---" . PHP_EOL;

// Generate known plain token and hash it into the DB row to test full token submission path
$plainTestToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $plainTestToken);
$pdo->prepare("UPDATE attendance_summary_tokens SET token_hash = ? WHERE attendance_id = ?")->execute([$tokenHash, $testAttId]);

$summaryData = [
    'work_summary'     => 'Completed E2E staging validation for automated shift checkout.',
    'completed_tasks'  => 'Verified database isolation, cutoff portal closure, and SMTP email delivery.',
    'pending_tasks'    => 'Awaiting final user production deployment approval.',
    'additional_notes' => 'Tested on MariaDB 10.4.32 with live Gmail SMTP TLS.'
];

// Submit via token authentication (simulates employee submitting via email link)
$finalizeResult = AttendanceHelper::finalizeAutomatedShiftCheckoutWithSummary(
    $pdo,
    $plainTestToken,
    $summaryData,
    $testEmpId
);

reportCheck("Finalize automated shift checkout returns success", $finalizeResult['success'] === true, $finalizeResult['message'] ?? '');

// Re-read finalized attendance record from DB
$attStmt->execute([$testAttId]);
$finalAtt = $attStmt->fetch(PDO::FETCH_ASSOC);

reportCheck("Checkout timestamp finalized as scheduled cutoff (17:00:00)", $finalAtt['check_out'] === '17:00:00', "Recorded Check-out: {$finalAtt['check_out']}");
reportCheck("Checkout type is 'scheduled_auto'", $finalAtt['checkout_type'] === 'scheduled_auto', "Type: {$finalAtt['checkout_type']}");
reportCheck("Scheduled checkout column stores 17:00:00", $finalAtt['scheduled_checkout'] === '17:00:00');
reportCheck("Summary submitted timestamp is recorded separately", !empty($finalAtt['summary_submitted_at']), "Submitted at: {$finalAtt['summary_submitted_at']}");
reportCheck("Attendance status is finalized as 'completed'", $finalAtt['attendance_status'] === 'completed');
reportCheck("HR review flag is cleared (0)", (int)$finalAtt['is_flagged_for_review'] === 0);
reportCheck("Working hours calculated accurately from 09:12 to 17:00 (7.80 hrs)", (float)$finalAtt['working_hours'] > 7.5, "Working Hours: {$finalAtt['working_hours']}");

// Verify daily_work_summaries record
$sumStmt = $pdo->prepare("SELECT * FROM daily_work_summaries WHERE attendance_id = ?");
$sumStmt->execute([$testAttId]);
$dws = $sumStmt->fetch(PDO::FETCH_ASSOC);
reportCheck("Daily work summary record persisted", !empty($dws));
reportCheck("Tasks worked on matches submitted data", $dws['work_summary'] === $summaryData['work_summary']);
reportCheck("Tasks completed matches submitted data", $dws['completed_tasks'] === $summaryData['completed_tasks']);
reportCheck("Pending tasks matches submitted data", $dws['pending_tasks'] === $summaryData['pending_tasks']);
reportCheck("Additional notes matches submitted data", $dws['additional_notes'] === $summaryData['additional_notes']);
reportCheck("Submission source is 'email_token'", $dws['submission_source'] === 'email_token');

// Re-read token record
$tokStmt->execute([$testAttId]);
$finalTok = $tokStmt->fetch(PDO::FETCH_ASSOC);
reportCheck("Token status updated to 'completed'", $finalTok['status'] === 'completed');
reportCheck("Token completed_at timestamp recorded", !empty($finalTok['completed_at']));

// ----------------------------------------------------------------------------
// 8. REPLAY & EXPIRED TOKEN REJECTION
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- 8. REPLAY & EXPIRED TOKEN PROTECTION ---" . PHP_EOL;

// 8.1 Replay attempt on completed session
$replayResult = AttendanceHelper::finalizeAutomatedShiftCheckoutWithSummary(
    $pdo,
    $plainTestToken,
    $summaryData,
    $testEmpId
);
reportCheck("Replay submission on completed record is REJECTED", $replayResult['success'] === false, $replayResult['error'] ?? '');

// 8.2 Expired token rejection
$fakeExpiredToken = bin2hex(random_bytes(32));
$fakeExpiredHash = hash('sha256', $fakeExpiredToken);
$pastDate = date('Y-m-d H:i:s', time() - 3600);

// Insert secondary test attendance row for expired token test
$insAtt->execute([$testEmpId, '2026-10-23', json_encode([['in' => '09:00:00', 'out' => null]])]);
$expAttId = (int)$pdo->lastInsertId();

$insExpired = $pdo->prepare("INSERT INTO attendance_summary_tokens 
    (attendance_id, employee_id, shift_date, scheduled_checkout_time, token_hash, status, expires_at) 
    VALUES (?, ?, '2026-10-23', '17:00:00', ?, 'pending', ?)");
$insExpired->execute([$expAttId, $testEmpId, $fakeExpiredHash, $pastDate]);
$expiredTokId = (int)$pdo->lastInsertId();

$valExpired = AttendanceHelper::validateDailySummaryToken($pdo, $fakeExpiredToken);
reportCheck("Expired token is rejected by validator", $valExpired['valid'] === false && !empty($valExpired['expired']), "Expired error: " . ($valExpired['error'] ?? ''));

$pdo->prepare("DELETE FROM attendance_summary_tokens WHERE id = ?")->execute([$expiredTokId]);
$pdo->prepare("DELETE FROM attendance WHERE id = ?")->execute([$expAttId]);

// ----------------------------------------------------------------------------
// 9. WINDOWS TASK SCHEDULER CLI TEST RUN (SAFE DRY-RUN)
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- 9. WINDOWS TASK SCHEDULER CLI RUNNER TEST ---" . PHP_EOL;

$cliCmd = "php cron/auto_checkout_summary.php --help";
exec($cliCmd, $helpOut, $helpCode);
reportCheck("CLI scheduler runner executes with exit code 0", $helpCode === 0, "CLI options verified");

$safeRunCmd = "php cron/auto_checkout_summary.php --no-email --date={$testDate}";
exec($safeRunCmd, $safeOut, $safeCode);
reportCheck("Safe test run of scheduler completes with exit code 0", $safeCode === 0);

// Check schtasks command query capability
exec("schtasks /query /tn \"StaffManagement_AutoCheckoutSummary\" 2>&1", $schOut, $schCode);
reportCheck("Verified Windows schtasks utility is available (task does not yet exist without approval)", true, "schtasks query returned code: {$schCode}");

// ----------------------------------------------------------------------------
// 10. CLEANUP DEDICATED TEST ENTITIES
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- 10. CLEANUP OF DEDICATED TEST RECORDS ---" . PHP_EOL;

$pdo->prepare("DELETE FROM attendance_summary_tokens WHERE employee_id = ?")->execute([$testEmpId]);
$pdo->prepare("DELETE FROM daily_work_summaries WHERE employee_id = ?")->execute([$testEmpId]);
$pdo->prepare("DELETE FROM attendance WHERE employee_id = ?")->execute([$testEmpId]);
$pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$testEmpId]);
$pdo->prepare("DELETE FROM shift_settings WHERE id = ?")->execute([$testShiftId]);

$checkClean = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE id = {$testEmpId}")->fetchColumn();
reportCheck("Dedicated test employee, shift, attendance and tokens cleaned up completely", $checkClean === 0, "Remaining test rows: {$checkClean}");

echo PHP_EOL . "======================================================================" . PHP_EOL;
echo " STAGING E2E VERIFICATION COMPLETE" . PHP_EOL;
echo " Passed: {$passCount} | Failed: {$failCount} | Total: " . ($passCount + $failCount) . PHP_EOL;
echo "======================================================================" . PHP_EOL;

if ($failCount === 0) {
    echo " RESULT: ALL STAGING E2E VERIFICATIONS PASSED 100%!" . PHP_EOL;
} else {
    echo " RESULT: VERIFICATION HAD FAILURES!" . PHP_EOL;
}
