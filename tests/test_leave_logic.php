<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/LeaveHelper.php';

echo "=== TESTING LEAVE HELPER & WORKFLOWS ===\n";

// 1. Get an existing employee id
$empId = (int)$pdo->query("SELECT id FROM employees ORDER BY id ASC LIMIT 1")->fetchColumn();
if (!$empId) {
    die("No employee found to test.\n");
}
echo "Testing with employee ID: $empId\n";

// 2. Initialize and get balances
$bals = LeaveHelper::getEmployeeBalances($pdo, $empId, (int)date('Y'));
echo "Employee balances initialized:\n";
foreach ($bals as $t => $info) {
    echo "  $t: Total={$info['total_days']}, Used={$info['used_days']}, Rem={$info['remaining_days']}\n";
}

// 3. Generate a test leave for future dates (e.g. 2026-11-10 to 2026-11-12)
$testStart = date('Y') . '-11-10';
$testEnd   = date('Y') . '-11-12';

// Clean up any previous test records
$pdo->prepare("DELETE FROM leave_requests WHERE employee_id = ? AND start_date = ?")->execute([$empId, $testStart]);
$pdo->prepare("DELETE FROM attendance WHERE employee_id = ? AND date BETWEEN ? AND ?")->execute([$empId, $testStart, $testEnd]);

// Test Generate Leave
$genResult = LeaveHelper::generateLeave($pdo, 1, $empId, 'casual', $testStart, $testEnd, 'Test casual trip');
echo "Generate Leave result:\n";
print_r($genResult);

// Check if attendance records were created
$attCount = (int)$pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND date BETWEEN ? AND ? AND status = 'on_leave'")->execute([$empId, $testStart, $testEnd]) ? $pdo->query("SELECT COUNT(*) FROM attendance WHERE employee_id = $empId AND date BETWEEN '$testStart' AND '$testEnd' AND status = 'on_leave'")->fetchColumn() : 0;
echo "Attendance 'on_leave' records created: $attCount (Expected: 3)\n";

// Check balance was incremented
$balsAfter = LeaveHelper::getEmployeeBalances($pdo, $empId, (int)date('Y'));
echo "Casual used days after approval: {$balsAfter['casual']['used_days']} (Expected: 3)\n";

// Test Overlap detection
$overlap = LeaveHelper::checkOverlap($pdo, $empId, $testStart, $testEnd);
echo "Overlap check with same dates: " . ($overlap ? "FOUND (Correct)" : "NOT FOUND (Bug)") . "\n";

// Test Reject previously approved leave
$testLeaveId = (int)$pdo->prepare("SELECT id FROM leave_requests WHERE employee_id = ? AND start_date = ? LIMIT 1")->execute([$empId, $testStart]) ? $pdo->query("SELECT id FROM leave_requests WHERE employee_id = $empId AND start_date = '$testStart' LIMIT 1")->fetchColumn() : 0;
echo "Rejecting approved leave ID: $testLeaveId\n";
$rejResult = LeaveHelper::rejectLeave($pdo, $testLeaveId, 1, 'Client emergency cancelled trip');
echo "Reject result:\n";
print_r($rejResult);

// Verify balance was refunded
$balsRefund = LeaveHelper::getEmployeeBalances($pdo, $empId, (int)date('Y'));
echo "Casual used days after rejection refund: {$balsRefund['casual']['used_days']} (Expected: 0)\n";

// Verify attendance records were deleted/cleared
$attAfterRej = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE employee_id = $empId AND date BETWEEN '$testStart' AND '$testEnd' AND status = 'on_leave'")->fetchColumn();
echo "Attendance records after rejection: $attAfterRej (Expected: 0)\n";

// Clean up test leave record
$delResult = LeaveHelper::deleteLeave($pdo, $testLeaveId, 1);
echo "Delete test leave result:\n";
print_r($delResult);

echo "=== LEAVE MANAGEMENT TESTS COMPLETE ===\n";
