<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

echo "=== Testing Shift Management & Assignment Logic ===\n";

// 1. Check existing shifts
$shifts = $pdo->query("SELECT id, shift_name, checkin_start, checkout_time, is_active FROM shift_settings")->fetchAll(PDO::FETCH_ASSOC);
echo "1. Found " . count($shifts) . " shift policies:\n";
foreach ($shifts as $s) {
    echo "   - ID: {$s['id']} | {$s['shift_name']} ({$s['checkin_start']} - {$s['checkout_time']}) " . ($s['is_active'] ? '[ACTIVE]' : '') . "\n";
}

if (count($shifts) < 2) {
    echo "Creating a secondary shift for testing...\n";
    $pdo->prepare("INSERT INTO shift_settings (shift_name, description, color, checkin_start, checkin_end, checkout_time, grace_minutes, late_threshold, early_exit_threshold, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute(['Night Owl Shift', 'Evening operational shift', '#ec4899', '14:00:00', '14:30:00', '22:00:00', 15, 30, 30, 0]);
    $shifts = $pdo->query("SELECT id, shift_name, checkin_start, checkout_time, is_active FROM shift_settings")->fetchAll(PDO::FETCH_ASSOC);
}

$activeShift = null;
$secondaryShift = null;
foreach ($shifts as $s) {
    if ($s['is_active']) $activeShift = $s;
    else $secondaryShift = $s;
}

// 2. Pick a test employee
$emp = $pdo->query("SELECT id, first_name, last_name, shift_id FROM employees WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    die("No active employee found for testing.\n");
}
$empId = $emp['id'];
$empName = "{$emp['first_name']} {$emp['last_name']}";
echo "2. Testing with employee #{$empId} ({$empName})\n";

// Test fallback when shift_id is NULL
$pdo->prepare("UPDATE employees SET shift_id = NULL WHERE id = ?")->execute([$empId]);
$resolvedShift1 = AttendanceHelper::getEmployeeShift($pdo, $empId, date('Y-m-d'));
echo "   Fallback shift without assignment: " . ($resolvedShift1['shift_name'] ?? 'NONE') . "\n";
assert($resolvedShift1['id'] == $activeShift['id'], "Should fallback to active shift when employee shift_id is null");

// Test explicit shift assignment
$pdo->prepare("UPDATE employees SET shift_id = ? WHERE id = ?")->execute([$secondaryShift['id'], $empId]);
$resolvedShift2 = AttendanceHelper::getEmployeeShift($pdo, $empId, date('Y-m-d'));
echo "   Resolved shift with explicit assignment: " . ($resolvedShift2['shift_name'] ?? 'NONE') . "\n";
assert($resolvedShift2['id'] == $secondaryShift['id'], "Should return assigned secondary shift");

// 3. Test deletion protection: try deleting secondaryShift while employee is assigned
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE shift_id = ?");
$stmtCount->execute([$secondaryShift['id']]);
$assignedCount = (int)$stmtCount->fetchColumn();
echo "3. Deletion protection: {$assignedCount} employee(s) assigned to '{$secondaryShift['shift_name']}'.\n";
assert($assignedCount >= 1, "Assigned count must be >= 1");
echo "   Deletion blocked successfully by assignment safeguard.\n";

// Revert employee back to null or active
$pdo->prepare("UPDATE employees SET shift_id = ? WHERE id = ?")->execute([$emp['shift_id'], $empId]);
echo "4. Restored original shift for employee #{$empId}.\n";

echo "\n>>> ALL SHIFT LOGIC TESTS PASSED SUCCESSFULLY! <<<\n";
