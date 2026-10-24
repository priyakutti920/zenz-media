<?php
// ============================================================================
// AUTOMATED TEST SUITE: GPS ATTENDANCE, GEOFENCING & TRANSACTIONAL CLOSING
// ============================================================================

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

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
echo "  STARTING GPS ATTENDANCE & CLOSING SYSTEM TEST SUITE" . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;

// ----------------------------------------------------------------------------
// TEST GROUP 1: HAVERSINE DISTANCE & COORDINATE VALIDATION
// ----------------------------------------------------------------------------
echo "--- GROUP 1: Geolocation & Distance Calculation ---" . PHP_EOL;

// 1. Office Center (11.941600, 79.808300) to identical point should be 0 meters
$dist0 = Security::calculateHaversineDistance(11.941600, 79.808300, 11.941600, 79.808300);
assertTest("Distance from office to itself is 0 meters", (int)$dist0 === 0);

// 2. Point ~30 meters away
$dist30 = Security::calculateHaversineDistance(11.941600, 79.808300, 11.941850, 79.808300);
assertTest("Close point calculates ~28m (under 100m radius)", $dist30 > 20 && $dist30 < 40);

// 3. Point ~500 meters away (outside standard 100m geofence)
$dist500 = Security::calculateHaversineDistance(11.941600, 79.808300, 11.946000, 79.808300);
assertTest("Distant point calculates ~489m (well outside 100m radius)", $dist500 > 450 && $dist500 < 550);

// 4. Coordinate range validation
assertTest("Valid coordinates pass range check", Security::validateCoordinates(11.9416, 79.8083) === true);
assertTest("Out-of-bounds latitude (>90) is rejected", Security::validateCoordinates(95.0, 79.8083) === false);
assertTest("Out-of-bounds longitude (>180) is rejected", Security::validateCoordinates(11.9416, 190.0) === false);
assertTest("Non-numeric coordinates are rejected", Security::validateCoordinates("invalid", "coords") === false);

// ----------------------------------------------------------------------------
// TEST GROUP 2: SERVER-SIDE GEOFENCE ENFORCEMENT (ON-SITE VS WFH)
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 2: Server-Side Geofence Enforcement ---" . PHP_EOL;

// 5. On-site employee within office radius
$officeCfg = AttendanceHelper::getOfficeLocationSettings($pdo);
$nearLat = (float)$officeCfg['office_latitude'] + 0.0001;
$nearLng = (float)$officeCfg['office_longitude'];
$resInside = AttendanceHelper::validateEmployeeLocation($pdo, 'on_site', $nearLat, $nearLng, 'check_in');
assertTest("On-site employee within radius is approved", $resInside['allowed'] === true && $resInside['distance'] < 100);

// 6. On-site employee outside office radius
$farLat = (float)$officeCfg['office_latitude'] + 0.1;
$farLng = (float)$officeCfg['office_longitude'] + 0.1;
$resOutside = AttendanceHelper::validateEmployeeLocation($pdo, 'on_site', $farLat, $farLng, 'check_in');
assertTest("On-site employee outside radius is rejected", $resOutside['allowed'] === false && strpos($resOutside['error'], 'rejected') !== false);

// 7. On-site employee with missing coordinates
$resMissing = AttendanceHelper::validateEmployeeLocation($pdo, 'on_site', '', '', 'check_in');
assertTest("On-site employee with empty GPS is rejected", $resMissing['allowed'] === false && strpos($resMissing['error'], 'permission is required') !== false);

// 8. WFH employee without coordinates
$resWfh = AttendanceHelper::validateEmployeeLocation($pdo, 'wfh', '', '', 'check_in');
assertTest("WFH employee without GPS is allowed (geofence waived)", $resWfh['allowed'] === true && $resWfh['is_wfh'] === true);

// 9. WFH employee with coordinates
$resWfhCoords = AttendanceHelper::validateEmployeeLocation($pdo, 'wfh', 12.9716, 77.5946, 'check_in');
assertTest("WFH employee with remote coordinates is allowed without error", $resWfhCoords['allowed'] === true && $resWfhCoords['is_wfh'] === true);

// ----------------------------------------------------------------------------
// TEST GROUP 3: OFFICE LOCATION SETTINGS RETRIEVAL & INTEGRITY
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 3: Office Settings Architecture ---" . PHP_EOL;

$officeSettings = AttendanceHelper::getOfficeLocationSettings($pdo);
assertTest("Office settings return office_name", !empty($officeSettings['office_name']));
assertTest("Office settings return valid numeric latitude", is_numeric($officeSettings['office_latitude']) && $officeSettings['office_latitude'] >= -90 && $officeSettings['office_latitude'] <= 90);
assertTest("Office settings return valid numeric longitude", is_numeric($officeSettings['office_longitude']) && $officeSettings['office_longitude'] >= -180 && $officeSettings['office_longitude'] <= 180);
assertTest("Office settings return positive radius", (int)$officeSettings['office_radius_meters'] > 0);

// ----------------------------------------------------------------------------
// TEST GROUP 4: ATTENDANCE SESSION CREATION, CLOSING & SUMMARY TRANSACTION
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 4: Transactional Attendance Closing & Work Summary ---" . PHP_EOL;

// Create a mock test employee
$testEmail = 'test_employee_gps_' . time() . '@cloudaspire.test';
$pdo->prepare("INSERT INTO employees (first_name, last_name, email, work_mode, status) VALUES ('Test', 'GPSUnit', ?, 'on_site', 'active')")->execute([$testEmail]);
$testEmpId = (int)$pdo->lastInsertId();

// Verify test employee created
assertTest("Test employee created successfully (ID: {$testEmpId})", $testEmpId > 0);

// A. Create test attendance check-in record for today
$testDate = '2029-01-15'; // Future date to guarantee no collision with real data
$insStmt = $pdo->prepare("INSERT INTO attendance 
    (employee_id, date, check_in, status, attendance_status, punch_history, check_in_lat, check_in_lng, check_in_dist) 
    VALUES (?, ?, '09:05:00', 'present', 'present', ?, 11.941620, 79.808310, 15)");
$insStmt->execute([$testEmpId, $testDate, json_encode([['in' => '09:05:00', 'out' => null]])]);
$testAttId = (int)$pdo->lastInsertId();

assertTest("Attendance check-in record created (ID: {$testAttId})", $testAttId > 0);

// B. Test Active Open Attendance Detection
$openRec = AttendanceHelper::getActiveOpenAttendance($pdo, $testEmpId, $testDate);
assertTest("getActiveOpenAttendance finds the open session for test date", $openRec !== null && (int)$openRec['id'] === $testAttId);

// C. Test Closing Attendance with Missing Mandatory Summary (Must Fail)
$failSummary = [
    'work_summary'    => '',
    'completed_tasks' => '',
    'pending_tasks'   => ''
];
$failRes = AttendanceHelper::closeAttendanceTransaction($pdo, $testEmpId, $testAttId, $failSummary, null, '17:30:00');
assertTest("Closing without mandatory tasks is rejected", $failRes['success'] === false && strpos($failRes['error'], 'mandatory') !== false);

// Verify record was not closed
$checkUnclosed = $pdo->query("SELECT check_out FROM attendance WHERE id = {$testAttId}")->fetchColumn();
assertTest("Record remains unclosed after validation failure", empty($checkUnclosed));

// D. Test Closing Attendance with IDOR / Wrong Employee ID (Must Fail)
$validSummary = [
    'work_summary'    => 'Developed and verified GPS geofencing module.',
    'completed_tasks' => 'Integrated Haversine validation and closed attendance bug.',
    'pending_tasks'   => 'None.'
];
$wrongEmpRes = AttendanceHelper::closeAttendanceTransaction($pdo, 999999, $testAttId, $validSummary, null, '17:30:00');
assertTest("IDOR protection: Closing another employee's session is denied", $wrongEmpRes['success'] === false && strpos($wrongEmpRes['error'], 'access denied') !== false);

// E. Test Successful Closing Attendance Transaction
$geoClose = ['latitude' => 11.941650, 'longitude' => 79.808310, 'distance' => 18];
$closeRes = AttendanceHelper::closeAttendanceTransaction($pdo, $testEmpId, $testAttId, $validSummary, null, '17:30:00', $geoClose);
assertTest("Valid attendance close succeeds with transaction committed", $closeRes['success'] === true);

// F. Verify Attendance Table Updates
$updatedAtt = $pdo->query("SELECT * FROM attendance WHERE id = {$testAttId}")->fetch(PDO::FETCH_ASSOC);
assertTest("Attendance check_out recorded correctly (17:30:00)", $updatedAtt['check_out'] === '17:30:00');
assertTest("Attendance working_hours calculated correctly (> 8 hours)", (float)$updatedAtt['working_hours'] > 8.0);
assertTest("Attendance status updated to 'completed'", $updatedAtt['attendance_status'] === 'completed');
assertTest("Attendance check_out_lat saved correctly", $updatedAtt['check_out_lat'] !== null);
assertTest("Attendance check_out_dist saved correctly", (int)$updatedAtt['check_out_dist'] === 18);

// G. Verify Daily Work Summaries Table Updates
$summaryRow = $pdo->query("SELECT * FROM daily_work_summaries WHERE attendance_id = {$testAttId}")->fetch(PDO::FETCH_ASSOC);
assertTest("Daily work summary row exists in daily_work_summaries table", !empty($summaryRow));
assertTest("Daily work summary content matches submitted data", $summaryRow['work_summary'] === $validSummary['work_summary']);
assertTest("Completed tasks match submitted data", $summaryRow['completed_tasks'] === $validSummary['completed_tasks']);

// H. Test Idempotency: Attempting to close already closed session (Must Fail gracefully)
$duplicateRes = AttendanceHelper::closeAttendanceTransaction($pdo, $testEmpId, $testAttId, $validSummary, null, '17:35:00');
assertTest("Duplicate close request is safely rejected without creating duplicates", $duplicateRes['success'] === false && strpos($duplicateRes['error'], 'already been closed') !== false);

// I. Verify No Duplicate Summary Rows Created
$summaryCount = (int)$pdo->query("SELECT COUNT(*) FROM daily_work_summaries WHERE attendance_id = {$testAttId}")->fetchColumn();
assertTest("Unique constraint ensures exactly 1 summary row per attendance session", $summaryCount === 1);

// ----------------------------------------------------------------------------
// TEST GROUP 5: STALE SESSION ISOLATION
// ----------------------------------------------------------------------------
echo PHP_EOL . "--- GROUP 5: Stale / Historical Session Isolation ---" . PHP_EOL;

// Create an old unclosed record from 30 days ago
$oldDate = '2025-01-01';
$pdo->prepare("INSERT INTO attendance 
    (employee_id, date, check_in, status, attendance_status, punch_history) 
    VALUES (?, ?, '09:00:00', 'present', 'present', ?)")->execute([$testEmpId, $oldDate, json_encode([['in' => '09:00:00', 'out' => null]])]);
$oldAttId = (int)$pdo->lastInsertId();

// Verify that getActiveOpenAttendance for TODAY does NOT return the 30-day-old unclosed record
$todayOpen = AttendanceHelper::getActiveOpenAttendance($pdo, $testEmpId, date('Y-m-d'));
assertTest("Old unclosed session from past months does NOT block today or return as active", $todayOpen === null);

// ----------------------------------------------------------------------------
// CLEANUP TEST DATA
// ----------------------------------------------------------------------------
$pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$testEmpId]);
// Foreign key cascade deletes test rows from attendance and daily_work_summaries

echo PHP_EOL . "======================================================================" . PHP_EOL;
echo "  TEST SUMMARY: {$passed} PASSED / " . ($failed > 0 ? "{$failed} FAILED" : "0 FAILED") . " (Total: {$total})" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

if ($failed === 0) {
    echo "  ALL SUITE TESTS EXECUTED AND CONFIRMED PASSING!" . PHP_EOL;
    exit(0);
} else {
    echo "  SOME TESTS FAILED!" . PHP_EOL;
    exit(1);
}
