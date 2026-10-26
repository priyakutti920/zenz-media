<?php
// ============================================================================
// RECENT ATTENDANCE RECORDS SECTION VERIFICATION TEST SUITE
// ============================================================================

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

// Initialize session for tests
Security::init($pdo);
Security::initSecureSession();

$passed = 0;
$failed = 0;
$total = 0;

function assertRecord($desc, $cond, $details = '') {
    global $passed, $failed, $total;
    $total++;
    if ($cond) {
        $passed++;
        echo "  [PASS] Test {$total}: {$desc}" . PHP_EOL;
    } else {
        $failed++;
        echo "  [FAIL] Test {$total}: {$desc}" . ($details ? " ($details)" : '') . PHP_EOL;
    }
}

echo "======================================================================" . PHP_EOL;
echo "  TESTING RECENT ATTENDANCE RECORDS (8 COLUMNS & DATA ACCURACY)" . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;

// ----------------------------------------------------------------------------
// GROUP 1: Unit Tests on AttendanceHelper::formatRecentAttendanceRecord
// ----------------------------------------------------------------------------
echo "--- GROUP 1: Column Data & Logic Verification ---" . PHP_EOL;

// Scenario 1: Completed Attendance with verified GPS and full summary
$rec1 = [
    'date' => '2026-10-20',
    'check_in' => '09:05:00',
    'check_out' => '17:35:00',
    'resolved_checkin_start' => '09:00:00',
    'resolved_checkout_time' => '17:30:00',
    'resolved_shift_name' => 'General Shift',
    'attendance_status' => 'completed',
    'check_in_dist' => 18,
    'check_out_dist' => 22,
    'final_work_summary' => 'Refactored backend attendance module.',
    'final_completed_tasks' => 'Added unit tests.',
    'final_pending_tasks' => 'Awaiting peer review',
];
$res1 = AttendanceHelper::formatRecentAttendanceRecord($rec1, true, '2026-10-24');

assertRecord("Col 1 (Date): Formatted readable date", $res1['date_formatted'] === 'Tue, Oct 20, 2026');
assertRecord("Col 1 (Date): Not overnight for daytime shift", $res1['is_overnight'] === false);
assertRecord("Col 2 (Status): Completed status badge", $res1['status_key'] === 'completed' && $res1['status_label'] === 'Completed');
assertRecord("Col 3 (Scheduled Shift): Correct shift time range", $res1['shift_schedule_text'] === '09:00 AM – 05:30 PM');
assertRecord("Col 3 (Scheduled Shift): Resolved shift name", $res1['shift_name'] === 'General Shift');
assertRecord("Col 4 (Check In): Actual recorded check-in timestamp", $res1['check_in_formatted'] === '09:05 AM');
assertRecord("Col 5 (Check Out): Actual recorded check-out timestamp", $res1['check_out_formatted'] === '05:35 PM');
assertRecord("Col 6 (Working Hours): Accurate checkin-to-checkout duration (8h 30m)", $res1['working_hours_display'] === '8h 30m');
assertRecord("Col 7 (Location Info): Verified office distance", $res1['loc_type'] === 'verified' && strpos($res1['loc_text'], '18m') !== false);
assertRecord("Col 8 (Daily Summary): Flagged as submitted", $res1['has_summary'] === true);
assertRecord("Col 8 (Daily Summary): Tasks worked on preserved", $res1['final_work_summary'] === 'Refactored backend attendance module.');
assertRecord("Col 8 (Daily Summary): Completed tasks preserved", $res1['final_completed_tasks'] === 'Added unit tests.');
assertRecord("Col 8 (Daily Summary): Pending tasks preserved", $res1['final_pending_tasks'] === 'Awaiting peer review');

// Scenario 2: Missing Checkout (Auto-closed past cutoff / flagged for HR review)
$rec2 = [
    'date' => '2026-10-22',
    'check_in' => '09:12:00',
    'check_out' => null,
    'resolved_checkin_start' => '09:00:00',
    'resolved_checkout_time' => '17:00:00',
    'resolved_shift_name' => 'Regular Shift',
    'attendance_status' => 'present',
    'is_flagged_for_review' => 1,
    'flag_reason' => 'Attendance closed automatically without checkout.',
    'check_in_dist' => 15,
    'check_out_dist' => null,
    'final_work_summary' => '',
    'final_completed_tasks' => '',
    'final_pending_tasks' => '',
];
$res2 = AttendanceHelper::formatRecentAttendanceRecord($rec2, true, '2026-10-24');

assertRecord("Missing Checkout: Status is Pending HR Review", $res2['status_key'] === 'pending_hr_review' && $res2['status_label'] === 'Pending HR Review');
assertRecord("Missing Checkout: Check Out displays 'Not checked out'", $res2['check_out_formatted'] === 'Not checked out');
assertRecord("Missing Checkout: Working Hours displays 'Pending checkout'", $res2['working_hours_display'] === 'Pending checkout');
assertRecord("Missing Checkout: No fake hours or fake timestamps generated", empty($rec2['check_out']) && $res2['check_out_formatted'] !== '05:00 PM');
assertRecord("Missing Checkout: Summary correctly detected as not submitted", $res2['has_summary'] === false);

// Scenario 3: In Progress (Today, Portal Open)
$rec3 = [
    'date' => '2026-10-24',
    'check_in' => '09:30:00',
    'check_out' => null,
    'resolved_checkin_start' => '09:00:00',
    'resolved_checkout_time' => '18:00:00',
    'resolved_shift_name' => 'Day Shift',
    'attendance_status' => 'present',
    'is_flagged_for_review' => 0,
    'check_in_dist' => 12,
    'check_out_dist' => null,
    'final_work_summary' => '',
    'final_completed_tasks' => '',
    'final_pending_tasks' => '',
];
$res3 = AttendanceHelper::formatRecentAttendanceRecord($rec3, false, '2026-10-24');

assertRecord("In Progress: Status is 'In Progress'", $res3['status_key'] === 'in_progress' && $res3['status_label'] === 'In Progress');
assertRecord("In Progress: Working Hours is 'In progress'", $res3['working_hours_display'] === 'In progress');

// Scenario 4: Missing GPS Data (Not WFH)
$rec4 = [
    'date' => '2026-10-19',
    'check_in' => '09:00:00',
    'check_out' => '17:00:00',
    'resolved_checkin_start' => '09:00:00',
    'resolved_checkout_time' => '17:00:00',
    'attendance_status' => 'completed',
    'check_in_dist' => null,
    'check_out_dist' => null,
    'employee_work_mode' => 'office',
    'final_work_summary' => '',
    'final_completed_tasks' => '',
    'final_pending_tasks' => '',
];
$res4 = AttendanceHelper::formatRecentAttendanceRecord($rec4, true, '2026-10-24');

assertRecord("Missing GPS: Location type is 'missing'", $res4['loc_type'] === 'missing');
assertRecord("Missing GPS: Distinct text 'GPS not recorded'", $res4['loc_text'] === 'GPS not recorded');

// Scenario 5: Remote Work (WFH Approved)
$rec5 = [
    'date' => '2026-10-18',
    'check_in' => '09:15:00',
    'check_out' => '17:45:00',
    'resolved_checkin_start' => '09:00:00',
    'resolved_checkout_time' => '17:00:00',
    'approved_request_mode' => 'wfh',
    'attendance_status' => 'completed',
    'check_in_dist' => null,
    'check_out_dist' => null,
    'employee_work_mode' => 'office',
    'final_work_summary' => 'Remote sprint tasks',
    'final_completed_tasks' => 'Completed code review',
    'final_pending_tasks' => '',
];
$res5 = AttendanceHelper::formatRecentAttendanceRecord($rec5, true, '2026-10-24');

assertRecord("WFH Remote: Location type is 'remote'", $res5['loc_type'] === 'remote');
assertRecord("WFH Remote: Text is 'WFH (Remote)'", $res5['loc_text'] === 'WFH (Remote)');
assertRecord("WFH Remote: Exception mode is 'Approved WFH'", $res5['shift_exception_mode'] === 'Approved WFH');

// Scenario 6: Unavailable Shift Schedule
$rec6 = [
    'date' => '2026-10-16',
    'check_in' => '10:00:00',
    'check_out' => '16:00:00',
    'resolved_checkin_start' => null,
    'resolved_checkout_time' => null,
    'attendance_status' => 'completed',
    'check_in_dist' => 20,
    'check_out_dist' => 20,
    'final_work_summary' => 'Special assignment',
    'final_completed_tasks' => '',
    'final_pending_tasks' => '',
];
$res6 = AttendanceHelper::formatRecentAttendanceRecord($rec6, true, '2026-10-24');

assertRecord("Unavailable Schedule: has_schedule is false", $res6['has_schedule'] === false);
assertRecord("Unavailable Schedule: Text is 'Schedule unavailable'", $res6['shift_schedule_text'] === 'Schedule unavailable');

// Scenario 7: Overnight Shift
$rec7 = [
    'date' => '2026-10-15',
    'check_in' => '22:00:00',
    'check_out' => '06:30:00',
    'resolved_checkin_start' => '21:30:00',
    'resolved_checkout_time' => '06:00:00',
    'resolved_shift_name' => 'Night Shift',
    'attendance_status' => 'completed',
    'check_in_dist' => 14,
    'check_out_dist' => 14,
    'final_work_summary' => 'Overnight monitoring',
    'final_completed_tasks' => '',
    'final_pending_tasks' => '',
];
$res7 = AttendanceHelper::formatRecentAttendanceRecord($rec7, true, '2026-10-24');

assertRecord("Overnight Shift: is_overnight is true", $res7['is_overnight'] === true);
assertRecord("Overnight Shift: Indicates next day (Oct 16)", $res7['overnight_next_short'] === 'Oct 16');
assertRecord("Overnight Shift: Duration accounts for midnight rollover (8h 30m)", $res7['working_hours_display'] === '8h 30m');

// Scenario 8: Delayed Attendance
$rec8 = [
    'date' => '2026-10-14',
    'check_in' => '10:45:00',
    'check_out' => '17:30:00',
    'resolved_checkin_start' => '09:00:00',
    'resolved_checkout_time' => '17:00:00',
    'attendance_status' => 'delayed',
    'status' => 'late',
    'check_in_dist' => 16,
    'check_out_dist' => 16,
    'final_work_summary' => 'Late start due to transit',
    'final_completed_tasks' => '',
    'final_pending_tasks' => '',
];
$res8 = AttendanceHelper::formatRecentAttendanceRecord($rec8, true, '2026-10-24');

assertRecord("Delayed Attendance: Status is 'Delayed'", $res8['status_key'] === 'delayed' && $res8['status_label'] === 'Delayed');
assertRecord("Delayed Attendance: Working hours accurately calculated (6h 45m)", $res8['working_hours_display'] === '6h 45m');

echo PHP_EOL . "--- GROUP 2: Database Query & Join Deduplication ---" . PHP_EOL;

// Setup a test employee with multiple daily work summaries and requests to ensure no duplication
$testEmpEmail = 'dedup_test_' . time() . '@cloudaspire.test';
$pdo->prepare("INSERT INTO employees (first_name, last_name, email, work_mode, status) VALUES ('Dedup', 'Tester', ?, 'on_site', 'active')")->execute([$testEmpEmail]);
$testEmpId = (int)$pdo->lastInsertId();

// Create 1 attendance record
$pdo->prepare("INSERT INTO attendance (employee_id, date, check_in, check_out, working_hours, attendance_status, check_in_dist) VALUES (?, '2026-10-23', '09:00:00', '17:00:00', 8.00, 'completed', 15)")->execute([$testEmpId]);
$attId = (int)$pdo->lastInsertId();

// Insert daily work summary for that attendance record
$pdo->prepare("INSERT INTO daily_work_summaries (attendance_id, employee_id, date, work_summary, completed_tasks) VALUES (?, ?, '2026-10-23', 'Summary V2 (Latest)', 'Tasks V2 (Latest)')")->execute([$attId, $testEmpId]);

// Insert MULTIPLE attendance requests for that employee and date (hazard for 1-to-many join duplication)
$pdo->prepare("INSERT INTO attendance_requests (employee_id, attendance_date, requested_work_mode, reason, status) VALUES (?, '2026-10-23', 'on_site_exception', 'Old request', 'approved')")->execute([$testEmpId]);
$pdo->prepare("INSERT INTO attendance_requests (employee_id, attendance_date, requested_work_mode, reason, status) VALUES (?, '2026-10-23', 'wfh', 'Latest request', 'approved')")->execute([$testEmpId]);

// Execute the history query from employee_attendance.php
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

$stmt = $pdo->prepare($sqlHistory);
$stmt->execute([$testEmpId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

assertRecord("Deduplication: Only 1 attendance row returned despite multiple 1-to-many joins", count($rows) === 1, "Count was " . count($rows));
assertRecord("Deduplication: Picks latest work summary ('Summary V2 (Latest)')", ($rows[0]['final_work_summary'] ?? '') === 'Summary V2 (Latest)');
assertRecord("Deduplication: Picks latest approved request mode ('wfh')", ($rows[0]['approved_request_mode'] ?? '') === 'wfh');

echo PHP_EOL . "--- GROUP 3: HTML Rendering & 8 Required Columns in Exact Order ---" . PHP_EOL;

// Mock session for this test employee
$_SESSION['employee_logged_in'] = true;
$_SESSION['employee_id'] = $testEmpId;
$_SESSION['last_activity'] = time();

ob_start();
try {
    include __DIR__ . '/../employee_attendance.php';
    $renderedHtml = ob_get_clean();
} catch (Throwable $e) {
    $renderedHtml = ob_get_clean();
    echo "Rendering error: " . $e->getMessage() . PHP_EOL;
}

// Check exact 8 columns in order in the desktop <thead>
$expectedHeaderPattern = '/<thead>\s*<tr>\s*<th>Date<\/th>\s*<th>Status<\/th>\s*<th>Scheduled Shift<\/th>\s*<th>Check In<\/th>\s*<th>Check Out<\/th>\s*<th>Working Hours<\/th>\s*<th>Location Info<\/th>\s*<th>Daily Summary<\/th>\s*<\/tr>\s*<\/thead>/is';

assertRecord("Desktop Table <thead> contains exactly the 8 columns in required order", preg_match($expectedHeaderPattern, $renderedHtml) === 1);

// Verify columns individually
assertRecord("Col 1 'Date' header exists", strpos($renderedHtml, '<th>Date</th>') !== false);
assertRecord("Col 2 'Status' header exists", strpos($renderedHtml, '<th>Status</th>') !== false);
assertRecord("Col 3 'Scheduled Shift' header exists", strpos($renderedHtml, '<th>Scheduled Shift</th>') !== false);
assertRecord("Col 4 'Check In' header exists", strpos($renderedHtml, '<th>Check In</th>') !== false);
assertRecord("Col 5 'Check Out' header exists", strpos($renderedHtml, '<th>Check Out</th>') !== false);
assertRecord("Col 6 'Working Hours' header exists", strpos($renderedHtml, '<th>Working Hours</th>') !== false);
assertRecord("Col 7 'Location Info' header exists", strpos($renderedHtml, '<th>Location Info</th>') !== false);
assertRecord("Col 8 'Daily Summary' header exists", strpos($renderedHtml, '<th>Daily Summary</th>') !== false);

// Check that mobile cards render the fields without losing any
assertRecord("Mobile card list container exists", strpos($renderedHtml, 'class="mobile-history-list"') !== false);
assertRecord("Mobile card item renders", strpos($renderedHtml, 'class="mobile-card"') !== false);
assertRecord("Mobile card includes Scheduled Shift", strpos($renderedHtml, 'Scheduled Shift') !== false);
assertRecord("Mobile card includes Location", strpos($renderedHtml, 'Location</span>') !== false);
assertRecord("Mobile card includes Daily Summary button or text", strpos($renderedHtml, 'Daily Summary') !== false);

// Check modal integration
assertRecord("Daily summary modal has viewSummaryModal ID", strpos($renderedHtml, 'id="viewSummaryModal"') !== false);
assertRecord("Modal includes Tasks Worked On section", strpos($renderedHtml, 'Tasks Worked On') !== false);
assertRecord("Modal includes Tasks Completed section", strpos($renderedHtml, 'Tasks Completed') !== false);

// Cleanup test employee records
$pdo->prepare("DELETE FROM daily_work_summaries WHERE employee_id = ?")->execute([$testEmpId]);
$pdo->prepare("DELETE FROM attendance_requests WHERE employee_id = ?")->execute([$testEmpId]);
$pdo->prepare("DELETE FROM attendance WHERE employee_id = ?")->execute([$testEmpId]);
$pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$testEmpId]);

echo PHP_EOL . "======================================================================" . PHP_EOL;
echo "  TEST SUMMARY" . PHP_EOL;
echo "  Total:  {$total}" . PHP_EOL;
echo "  Passed: {$passed}" . PHP_EOL;
echo "  Failed: {$failed}" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

if ($failed === 0) {
    echo "  ALL RECENT ATTENDANCE RECORDS TESTS PASSED!" . PHP_EOL;
} else {
    echo "  SOME TESTS FAILED." . PHP_EOL;
}
