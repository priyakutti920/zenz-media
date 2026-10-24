<?php
// Include database connection first
require_once '../app/db.php';
require_once '../app/security.php';
require_once '../app/AttendanceHelper.php';
Security::init($pdo);
Security::initSecureSession();

// Check if admin is logged in
if (!Security::isAuthenticated()) {
 header("Location: ../admin");
 exit();
}

$adminId = $_SESSION['admin_id'] ?? null;
Security::requirePermission('attendance.view', $adminId, '../admin/dashboard');

$canManage = Security::hasPermission('attendance.manage', $adminId);

// Set page variables for header
$pageTitle = 'Attendance Report';
$currentPage = 'attendance';

// Initialize variables
$message = '';
$messageType = '';

// Handle Mark Attendance (POST with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
 // Validate CSRF token
 if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '', 'attendance')) {
  $message = 'Invalid security token. Please try again.';
  $messageType = 'error';
 } elseif (!$canManage) {
  $message = 'Access Denied: You do not possess authorization to modify attendance records.';
  $messageType = 'error';
 } else {
  $empId = (int)($_POST['employee_id'] ?? 0);
  $date = $_POST['attendance_date'] ?? '';
  $checkIn = !empty($_POST['check_in']) ? $_POST['check_in'] : null;
  $checkOut = !empty($_POST['check_out']) ? $_POST['check_out'] : null;
  $status = $_POST['attendance_status'] ?? 'present';
  $notes = trim($_POST['notes'] ?? '');

  if (empty($empId) || empty($date)) {
  $message = 'Please select an employee and date.';
  $messageType = 'error';
  } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  $message = 'Invalid date format.';
  $messageType = 'error';
  } else {
  try {
  $activeShift = AttendanceHelper::getActiveShift($pdo);
  
  $delayMins = 0;
  $earlyExitMins = 0;
  $workData = ['minutes' => 0, 'hours_formatted' => 0.00];
  
  if ($checkIn) {
      $delayMins = AttendanceHelper::calculateDelay($checkIn, $activeShift);
  }
  if ($checkOut) {
      $earlyExitMins = AttendanceHelper::calculateEarlyExit($checkOut, $activeShift);
      $workData = AttendanceHelper::calculateWorkingHours($checkIn, $checkOut);
  }
  
  $autoStatus = AttendanceHelper::getAttendanceStatus($delayMins, $earlyExitMins, $activeShift);
  
  // Save admin's selected status as fallback for older frontend logic, but also save real status
  $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, date, check_in, check_out, status, attendance_status, delay_minutes, early_exit_minutes, total_work_minutes, working_hours, notes)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE check_in = VALUES(check_in), check_out = VALUES(check_out), status = VALUES(status), attendance_status = VALUES(attendance_status), delay_minutes = VALUES(delay_minutes), early_exit_minutes = VALUES(early_exit_minutes), total_work_minutes = VALUES(total_work_minutes), working_hours = VALUES(working_hours), notes = VALUES(notes)");
  
  $stmt->execute([$empId, $date, $checkIn, $checkOut, $status, $autoStatus, $delayMins, $earlyExitMins, $workData['minutes'], $workData['hours_formatted'], $notes]);

  Security::logAudit($adminId, 'mark_attendance', 'attendance', $empId, [
      'date' => $date,
      'status' => $status,
      'auto_status' => $autoStatus,
      'check_in' => $checkIn,
      'check_out' => $checkOut
  ]);
  
  $message = 'Attendance saved and recalculated successfully.';
  $messageType = 'success';
  } catch (PDOException $e) {
  error_log('Attendance error: ' . $e->getMessage());
  $message = 'Error saving attendance. Please try again.';
  $messageType = 'error';
  }
  }
 }
}

// Handle Delete Attendance (POST with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_attendance' && isset($_POST['attendance_id'])) {
 // Validate CSRF token
 if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '', 'attendance')) {
  $message = 'Invalid security token. Please try again.';
  $messageType = 'error';
 } elseif (!$canManage) {
  $message = 'Access Denied: You do not possess authorization to delete attendance records.';
  $messageType = 'error';
 } else {
  $id = (int)$_POST['attendance_id'];
  try {
  $stmt = $pdo->prepare("DELETE FROM attendance WHERE id = ?");
  $stmt->execute([$id]);

  Security::logAudit($adminId, 'delete_attendance', 'attendance', $id, ['status' => 'deleted']);

  $message = 'Attendance record deleted successfully.';
  $messageType = 'success';
  } catch (PDOException $e) {
  error_log('Delete attendance error: ' . $e->getMessage());
  $message = 'Error deleting attendance. Please try again.';
  $messageType = 'error';
  }
 }
}

// Handle Resolve Flagged Attendance (POST with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve_flagged_attendance' && isset($_POST['attendance_id'])) {
 // Validate CSRF token
 if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '', 'attendance')) {
  $message = 'Invalid security token. Please try again.';
  $messageType = 'error';
 } elseif (!$canManage) {
  $message = 'Access Denied: You do not possess authorization to resolve flagged attendance records.';
  $messageType = 'error';
 } else {
  $attendanceId = (int)$_POST['attendance_id'];
  $approvedCheckOut = !empty($_POST['approved_check_out']) ? $_POST['approved_check_out'] : null;
  $resolutionStatus = $_POST['resolution_status'] ?? 'present';
  $resolutionNotes = trim($_POST['resolution_notes'] ?? '');

  $res = AttendanceHelper::adminResolveFlaggedAttendance($pdo, $attendanceId, $adminId, [
   'approved_check_out' => $approvedCheckOut,
   'status' => $resolutionStatus,
   'notes' => $resolutionNotes
  ]);

  if ($res['success']) {
   $message = $res['message'];
   $messageType = 'success';
  } else {
   $message = $res['message'];
   $messageType = 'error';
  }
 }
}

// Generate CSRF token for the page forms
$csrf_token = Security::generateCSRFToken('attendance');

// Get filter values
$filterDate = $_GET['filter_date'] ?? date('Y-m-d');
$filterMonth = $_GET['filter_month'] ?? date('Y-m');
$filterEmployee = $_GET['filter_employee'] ?? '';
$filterDepartment = $_GET['filter_department'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';
$viewMode = $_GET['view'] ?? 'daily'; // daily or monthly

// Attendance schema is maintained via migrations

// Fetch all employees for dropdowns
try {
 $stmt = $pdo->query("SELECT id, first_name, last_name, department, status FROM employees ORDER BY first_name ASC");
 $allEmployees = $stmt->fetchAll();
} catch (PDOException $e) {
 $allEmployees = [];
}

// Fetch unique departments
$departments = [];
foreach ($allEmployees as $emp) {
 if (!empty($emp['department']) && !in_array($emp['department'], $departments)) {
  $departments[] = $emp['department'];
 }
}
sort($departments);

// ===== DAILY VIEW DATA =====
$dailyRecords = [];
if ($viewMode === 'daily') {
 $sql = "SELECT a.*, e.first_name, e.last_name, e.department, e.position, e.work_mode
  FROM attendance a
  JOIN employees e ON a.employee_id = e.id
  WHERE a.date = ?";
 $params = [$filterDate];

 if (!empty($filterEmployee)) {
  $sql .= " AND a.employee_id = ?";
  $params[] = (int)$filterEmployee;
 }
 if (!empty($filterDepartment)) {
  $sql .= " AND e.department = ?";
  $params[] = $filterDepartment;
 }
 if (!empty($filterStatus)) {
  $sql .= " AND a.status = ?";
  $params[] = $filterStatus;
 }

 $sql .= " ORDER BY e.first_name ASC";

 try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $dailyRecords = $stmt->fetchAll();
 } catch (PDOException $e) {
  error_log($e->getMessage());
 }
}

// ===== MONTHLY VIEW DATA =====
$monthlyRecords = [];
$monthlySummary = [];
if ($viewMode === 'monthly') {
 $monthStart = $filterMonth . '-01';
 $monthEnd = date('Y-m-t', strtotime($monthStart));

 $sql = "SELECT a.*, e.first_name, e.last_name, e.department, e.position
  FROM attendance a
  JOIN employees e ON a.employee_id = e.id
  WHERE a.date BETWEEN ? AND ?";
 $params = [$monthStart, $monthEnd];

 if (!empty($filterEmployee)) {
  $sql .= " AND a.employee_id = ?";
  $params[] = (int)$filterEmployee;
 }
 if (!empty($filterDepartment)) {
  $sql .= " AND e.department = ?";
  $params[] = $filterDepartment;
 }

 $sql .= " ORDER BY e.first_name ASC, a.date ASC";

 try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $monthlyRecords = $stmt->fetchAll();

  // Build summary per employee
  foreach ($monthlyRecords as $rec) {
  $eid = $rec['employee_id'];
  if (!isset($monthlySummary[$eid])) {
  $monthlySummary[$eid] = [
  'name' => $rec['first_name'] . ' ' . $rec['last_name'],
  'department' => $rec['department'],
  'position' => $rec['position'],
  'present' => 0,
  'absent' => 0,
  'half_day' => 0,
  'late' => 0,
  'on_leave' => 0,
  'total_days' => 0,
  ];
  }
  if (!isset($monthlySummary[$eid][$rec['status']])) {
       $monthlySummary[$eid][$rec['status']] = 0;
   }
   $monthlySummary[$eid][$rec['status']]++;
   $monthlySummary[$eid]['total_days']++;
  }
 } catch (PDOException $e) {
  error_log($e->getMessage());
 }
}

// ===== OVERALL STATS FOR SELECTED DATE =====
$totalPresent = 0;
$totalAbsent = 0;
$totalLate = 0;
$totalOnLeave = 0;
$totalHalfDay = 0;
$totalRecorded = 0;

try {
 $statsDate = ($viewMode === 'daily') ? $filterDate : null;
 if ($viewMode === 'daily') {
  $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE date = ? GROUP BY status");
  $stmt->execute([$filterDate]);
 } else {
  $monthStart = $filterMonth . '-01';
  $monthEnd = date('Y-m-t', strtotime($monthStart));
  $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE date BETWEEN ? AND ? GROUP BY status");
  $stmt->execute([$monthStart, $monthEnd]);
 }
 $stats = $stmt->fetchAll();
 foreach ($stats as $s) {
  $totalRecorded += $s['cnt'];
  switch ($s['status']) {
  case 'present': $totalPresent = $s['cnt']; break;
  case 'absent': $totalAbsent = $s['cnt']; break;
  case 'late': $totalLate = $s['cnt']; break;
  case 'on_leave': $totalOnLeave = $s['cnt']; break;
  case 'half_day': $totalHalfDay = $s['cnt']; break;
  }
 }
} catch (PDOException $e) {
 error_log($e->getMessage());
}

$attendanceRate = $totalRecorded > 0 ? round((($totalPresent + $totalLate + $totalHalfDay) / $totalRecorded) * 100, 1) : 0;
$flaggedCount = AttendanceHelper::getFlaggedAttendanceCount($pdo);
?>

<?php include 'header.php'; ?>

<!-- Page Header -->
<div class="page-header">
 <div>
  <h1 class="page-title">Attendance Report</h1>
  <p class="page-subtitle">Track and manage employee attendance records</p>
 </div>
 <div style="display: flex; gap: 12px;">
  <button class="btn btn-primary" onclick="openMarkAttendanceModal()">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <polyline points="9 11 12 14 22 4"></polyline>
  <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
  </svg>
  Mark Attendance
  </button>
 </div>
</div>

<!-- Alert Messages -->
<?php if ($message): ?>
<div class="alert alert-<?php echo htmlspecialchars($messageType); ?>">
 <?php if ($messageType === 'success'): ?>
 <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
  <polyline points="22 4 12 14.01 9 11.01"></polyline>
 </svg>
 <?php else: ?>
 <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <circle cx="12" cy="12" r="10"></circle>
  <line x1="12" y1="8" x2="12" y2="12"></line>
  <line x1="12" y1="16" x2="12.01" y2="16"></line>
 </svg>
 <?php endif; ?>
 <span><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<!-- Flagged Records Notification Banner -->
<?php if ($flaggedCount > 0): ?>
<div style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.08), rgba(220, 38, 38, 0.04)); border: 1.5px solid rgba(239, 68, 68, 0.25); border-radius: var(--radius-xl); padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
	<div style="display: flex; align-items: center; gap: 14px;">
		<div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(239, 68, 68, 0.15); color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
			<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg>
		</div>
		<div>
			<div style="font-weight: 700; color: #b91c1c; font-size: 15px;">
				Action Required: <?php echo $flaggedCount; ?> Attendance Record<?php echo $flaggedCount > 1 ? 's' : ''; ?> Flagged for HR Review
			</div>
			<div style="font-size: 13px; color: var(--text-secondary); margin-top: 2px;">
				Employees reached shift cutoff without checking out. Actual check-in data has been preserved; HR review and checkout authorization is required.
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Summary Stats Cards -->
<div class="att-stats-grid">
 <div class="att-stat-card att-stat--total">
  <div class="att-stat-icon">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
  <circle cx="9" cy="7" r="4"></circle>
  <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
  <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
  </svg>
  </div>
  <div class="att-stat-info">
  <span class="att-stat-label">Total Recorded</span>
  <span class="att-stat-value"><?php echo $totalRecorded; ?></span>
  </div>
 </div>
 <div class="att-stat-card att-stat--present">
  <div class="att-stat-icon">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
  <polyline points="22 4 12 14.01 9 11.01"></polyline>
  </svg>
  </div>
  <div class="att-stat-info">
  <span class="att-stat-label">Present</span>
  <span class="att-stat-value"><?php echo $totalPresent; ?></span>
  </div>
 </div>
 <div class="att-stat-card att-stat--absent">
  <div class="att-stat-icon">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <circle cx="12" cy="12" r="10"></circle>
  <line x1="15" y1="9" x2="9" y2="15"></line>
  <line x1="9" y1="9" x2="15" y2="15"></line>
  </svg>
  </div>
  <div class="att-stat-info">
  <span class="att-stat-label">Absent</span>
  <span class="att-stat-value"><?php echo $totalAbsent; ?></span>
  </div>
 </div>
 <div class="att-stat-card att-stat--late">
  <div class="att-stat-icon">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <circle cx="12" cy="12" r="10"></circle>
  <polyline points="12 6 12 12 16 14"></polyline>
  </svg>
  </div>
  <div class="att-stat-info">
  <span class="att-stat-label">Late</span>
  <span class="att-stat-value"><?php echo $totalLate; ?></span>
  </div>
 </div>
 <div class="att-stat-card att-stat--leave">
  <div class="att-stat-icon">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
  <line x1="16" y1="2" x2="16" y2="6"></line>
  <line x1="8" y1="2" x2="8" y2="6"></line>
  <line x1="3" y1="10" x2="21" y2="10"></line>
  </svg>
  </div>
  <div class="att-stat-info">
  <span class="att-stat-label">On Leave</span>
  <span class="att-stat-value"><?php echo $totalOnLeave; ?></span>
  </div>
 </div>
 <div class="att-stat-card att-stat--rate">
  <div class="att-stat-icon">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <line x1="18" y1="20" x2="18" y2="10"></line>
  <line x1="12" y1="20" x2="12" y2="4"></line>
  <line x1="6" y1="20" x2="6" y2="14"></line>
  </svg>
  </div>
  <div class="att-stat-info">
  <span class="att-stat-label">Attendance Rate</span>
  <span class="att-stat-value"><?php echo $attendanceRate; ?>%</span>
  </div>
 </div>
</div>

<!-- View Mode Toggle & Filters -->
<div class="att-filters-section">
 <div class="att-view-toggle">
  <a href="?view=daily&filter_date=<?php echo htmlspecialchars($filterDate); ?>" class="att-view-btn <?php echo $viewMode === 'daily' ? 'active' : ''; ?>">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
  <line x1="16" y1="2" x2="16" y2="6"></line>
  <line x1="8" y1="2" x2="8" y2="6"></line>
  <line x1="3" y1="10" x2="21" y2="10"></line>
  </svg>
  Daily View
  </a>
  <a href="?view=monthly&filter_month=<?php echo htmlspecialchars($filterMonth); ?>" class="att-view-btn <?php echo $viewMode === 'monthly' ? 'active' : ''; ?>">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
  <line x1="16" y1="2" x2="16" y2="6"></line>
  <line x1="8" y1="2" x2="8" y2="6"></line>
  <line x1="3" y1="10" x2="21" y2="10"></line>
  <line x1="8" y1="14" x2="8" y2="14.01"></line>
  <line x1="12" y1="14" x2="12" y2="14.01"></line>
  <line x1="16" y1="14" x2="16" y2="14.01"></line>
  <line x1="8" y1="18" x2="8" y2="18.01"></line>
  <line x1="12" y1="18" x2="12" y2="18.01"></line>
  <line x1="16" y1="18" x2="16" y2="18.01"></line>
  </svg>
  Monthly View
  </a>
 </div>

 <form class="att-filters-form" method="GET" action="">
  <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode); ?>">

  <?php if ($viewMode === 'daily'): ?>
  <div class="att-filter-group">
  <label>Date</label>
  <input type="date" name="filter_date" class="input" value="<?php echo htmlspecialchars($filterDate); ?>">
  </div>
  <?php else: ?>
  <div class="att-filter-group">
  <label>Month</label>
  <input type="month" name="filter_month" class="input" value="<?php echo htmlspecialchars($filterMonth); ?>">
  </div>
  <?php endif; ?>

  <div class="att-filter-group">
  <label>Employee</label>
  <select name="filter_employee" class="input">
  <option value="">All Employees</option>
  <?php foreach ($allEmployees as $emp): ?>
  <option value="<?php echo htmlspecialchars($emp['id']); ?>" <?php echo $filterEmployee == $emp['id'] ? 'selected' : ''; ?>>
  <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
  </option>
  <?php endforeach; ?>
  </select>
  </div>

  <div class="att-filter-group">
  <label>Department</label>
  <select name="filter_department" class="input">
  <option value="">All Departments</option>
  <?php foreach ($departments as $dept): ?>
  <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $filterDepartment === $dept ? 'selected' : ''; ?>>
  <?php echo htmlspecialchars($dept); ?>
  </option>
  <?php endforeach; ?>
  </select>
  </div>

  <?php if ($viewMode === 'daily'): ?>
  <div class="att-filter-group">
  <label>Status</label>
  <select name="filter_status" class="input">
  <option value="">All Status</option>
  <option value="present" <?php echo $filterStatus === 'present' ? 'selected' : ''; ?>>Present</option>
  <option value="absent" <?php echo $filterStatus === 'absent' ? 'selected' : ''; ?>>Absent</option>
  <option value="late" <?php echo $filterStatus === 'late' ? 'selected' : ''; ?>>Late</option>
  <option value="half_day" <?php echo $filterStatus === 'half_day' ? 'selected' : ''; ?>>Half Day</option>
  <option value="on_leave" <?php echo $filterStatus === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
  </select>
  </div>
  <?php endif; ?>

  <div class="att-filter-actions">
  <button type="submit" class="btn btn-primary btn-sm">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <circle cx="11" cy="11" r="8"></circle>
  <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
  </svg>
  Filter
  </button>
  <a href="?view=<?php echo htmlspecialchars($viewMode); ?>" class="btn btn-secondary btn-sm">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <polyline points="1 4 1 10 7 10"></polyline>
  <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
  </svg>
  Reset
  </a>
  </div>
 </form>
</div>

<!-- DAILY VIEW TABLE -->
<?php if ($viewMode === 'daily'): ?>
<div class="section">
 <div class="section-header">
  <h2 class="section-title">
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 8px; color: var(--primary-500);">
  <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
  <line x1="16" y1="2" x2="16" y2="6"></line>
  <line x1="8" y1="2" x2="8" y2="6"></line>
  <line x1="3" y1="10" x2="21" y2="10"></line>
  </svg>
  Daily Attendance — <?php echo date('l, F j, Y', strtotime($filterDate)); ?>
  </h2>
  <span class="att-record-count"><?php echo count($dailyRecords); ?> records</span>
 </div>
 <div class="table-container">
  <table>
  <thead>
  <tr>
  <th>Employee</th>
  <th>Department</th>
  <th>Mode</th>
  <th>Check In</th>
  <th>Check Out</th>
  <th>Late / Early</th>
  <th>Working Hours</th>
  <th>Status</th>
  <th>Daily Summary</th>
  <th>Notes</th>
  <th>Actions</th>
  </tr>
  </thead>
  <tbody>
  <?php if (empty($dailyRecords)): ?>
  <tr>
  <td colspan="11" style="text-align: center; color: var(--text-tertiary); padding: 60px 20px;">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px; opacity: 0.5;">
  <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
  <line x1="16" y1="2" x2="16" y2="6"></line>
  <line x1="8" y1="2" x2="8" y2="6"></line>
  <line x1="3" y1="10" x2="21" y2="10"></line>
  </svg>
  <br>No attendance records for this date.
  <br><a href="javascript:void(0)" onclick="openMarkAttendanceModal()" style="color: var(--primary-500); margin-top: 8px; display: inline-block;">Mark attendance →</a>
  </td>
  </tr>
  <?php else: ?>
  <?php foreach ($dailyRecords as $rec): ?>
  <?php
  $workingHours = '—';
  if (isset($rec['working_hours']) && $rec['working_hours'] > 0) {
      $workingHours = $rec['working_hours'] . 'h';
  } elseif ($rec['check_in'] && $rec['check_out']) {
      $in = strtotime($rec['check_in']);
      $out = strtotime($rec['check_out']);
      $diff = $out - $in;
      $hours = floor($diff / 3600);
      $mins = floor(($diff % 3600) / 60);
      $workingHours = $hours . 'h ' . $mins . 'm';
  }
  ?>
  <tr>
  <td>
  <div style="display: flex; align-items: center; gap: 12px;">
  <div class="table-avatar"><?php echo htmlspecialchars(strtoupper(substr($rec['first_name'], 0, 1) . substr($rec['last_name'], 0, 1))); ?></div>
  <div>
  <div style="font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']); ?></div>
  <div style="font-size: 13px; color: var(--text-tertiary);"><?php echo htmlspecialchars($rec['position'] ?: '—'); ?></div>
  </div>
  </div>
  </td>
  <td><?php echo htmlspecialchars($rec['department'] ?: '—'); ?></td>
  <td>
  <?php if (($rec['work_mode'] ?? 'on_site') === 'wfh'): ?>
      <span class="att-status-badge" style="background: rgba(79, 70, 229, 0.1); color: #4f46e5; border: 1px solid rgba(79, 70, 229, 0.25); font-size: 11px;">WFH</span>
  <?php else: ?>
      <span class="att-status-badge" style="background: rgba(5, 150, 105, 0.1); color: #059669; border: 1px solid rgba(5, 150, 105, 0.25); font-size: 11px;">On-Site</span>
  <?php endif; ?>
  </td>
  <td>
  <?php 
  $punches = json_decode($rec['punch_history'] ?? '[]', true);
  if (!empty($punches)): 
      foreach ($punches as $idx => $p): 
  ?>
          <div style="margin-bottom: 4px; font-size: 12px; white-space: nowrap;">
              <strong style="color: var(--text-secondary); margin-right: 4px;">P<?php echo $idx + 1; ?>:</strong>
              <span class="att-time-badge"><?php echo date('h:i A', strtotime($p['in'])); ?></span>
          </div>
  <?php 
      endforeach;
  elseif ($rec['check_in']): 
  ?>
      <span class="att-time-badge"><?php echo date('h:i A', strtotime($rec['check_in'])); ?></span>
  <?php else: ?>
      <span style="color: var(--text-tertiary);">—</span>
  <?php endif; ?>
  </td>
  <td>
  <?php 
  if (!empty($punches)): 
      foreach ($punches as $idx => $p): 
  ?>
          <div style="margin-bottom: 4px; font-size: 12px; white-space: nowrap;">
              <strong style="color: var(--text-secondary); margin-right: 4px;">P<?php echo $idx + 1; ?>:</strong>
              <?php if (!empty($p['out'])): ?>
                  <span class="att-time-badge"><?php echo date('h:i A', strtotime($p['out'])); ?></span>
              <?php else: ?>
                  <span style="color: var(--text-tertiary);">—</span>
              <?php endif; ?>
          </div>
  <?php 
      endforeach;
  elseif ($rec['check_out']): 
  ?>
      <span class="att-time-badge"><?php echo date('h:i A', strtotime($rec['check_out'])); ?></span>
  <?php else: ?>
      <span style="color: var(--text-tertiary);">—</span>
  <?php endif; ?>
  </td>
  <td>
  <div style="font-size: 13px; color: var(--danger-600);"><?php echo isset($rec['delay_minutes']) && $rec['delay_minutes'] > 0 ? "Late: {$rec['delay_minutes']}m" : ''; ?></div>
  <div style="font-size: 13px; color: var(--warning-600);"><?php echo isset($rec['early_exit_minutes']) && $rec['early_exit_minutes'] > 0 ? "Early: {$rec['early_exit_minutes']}m" : ''; ?></div>
  </td>
  <td>
  <span class="att-hours"><?php echo htmlspecialchars($workingHours); ?></span>
  </td>
  <td>
  <span class="att-status-badge att-status--<?php echo htmlspecialchars($rec['status']); ?>">
  <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $rec['status']))); ?>
  </span>
  <?php if (!empty($rec['is_flagged_for_review'])): ?>
      <div style="margin-top: 4px;">
          <span class="att-status-badge" style="background: rgba(239, 68, 68, 0.15); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.3); font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;" title="<?php echo htmlspecialchars($rec['flag_reason'] ?? 'Shift cutoff reached without check-out'); ?>">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg>
              HR Review (Cutoff Unclosed)
          </span>
      </div>
  <?php endif; ?>
  <?php if (!empty($rec['leave_code'])): ?>
      <div style="font-size:11px; margin-top:4px; color:var(--text-secondary);">Reason: <?php echo htmlspecialchars($rec['leave_code']); ?></div>
  <?php endif; ?>
  </td>
  <td>
  <?php if (!empty($rec['work_summary']) || !empty($rec['completed_tasks'])): ?>
      <button type="button" class="btn-action btn-secondary btn-sm" style="display:inline-flex; align-items:center; gap:5px; padding:3px 9px; font-size:11px; font-weight:600;" 
          onclick="viewAdminWorkSummary(<?php echo htmlspecialchars(json_encode([
              'employee' => $rec['first_name'] . ' ' . $rec['last_name'],
              'date' => date('D, M j, Y', strtotime($rec['date'])),
              'work_mode' => $rec['work_mode'] ?? 'on_site',
              'work_summary' => $rec['work_summary'] ?? '',
              'completed_tasks' => $rec['completed_tasks'] ?? '',
              'pending_tasks' => $rec['pending_tasks'] ?? '',
              'check_in_dist' => $rec['check_in_dist'] ?? null,
              'check_out_dist' => $rec['check_out_dist'] ?? null,
              'leave_code' => $rec['leave_code'] ?? null
          ]), ENT_QUOTES, 'UTF-8'); ?>)">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
          <span>Summary</span>
      </button>
  <?php else: ?>
      <span style="color: var(--text-tertiary); font-size: 12px;">—</span>
  <?php endif; ?>
  </td>
  <td>
  <span style="font-size: 13px; color: var(--text-secondary); max-width: 150px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($rec['notes'] ?: ''); ?>">
  <?php echo htmlspecialchars($rec['notes'] ?: '—'); ?>
  </span>
  </td>
  <td>
  <?php if (!empty($rec['is_flagged_for_review']) && $canManage): ?>
  <button type="button" class="btn-action btn-sm" style="background: #fee2e2; color: #b91c1c; border: 1px solid #f87171; font-weight: 600; margin-right: 4px;"
      onclick="openResolveFlagModal(<?php echo (int)$rec['id']; ?>, '<?php echo htmlspecialchars(addslashes($rec['first_name'] . ' ' . $rec['last_name'])); ?>', '<?php echo htmlspecialchars($rec['date']); ?>', '<?php echo htmlspecialchars($rec['check_in'] ? date('h:i A', strtotime($rec['check_in'])) : 'None'); ?>', '<?php echo htmlspecialchars(addslashes($rec['flag_reason'] ?? 'Cutoff unclosed')); ?>')" title="Resolve HR Review">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
      <span>HR Resolve</span>
  </button>
  <?php endif; ?>
  <button type="button" class="btn-action btn-secondary btn-sm"
  onclick="editAttendance('<?php echo htmlspecialchars($rec['employee_id']); ?>', '<?php echo htmlspecialchars($rec['date']); ?>', '<?php echo htmlspecialchars($rec['check_in'] ? date('H:i', strtotime($rec['check_in'])) : ''); ?>', '<?php echo htmlspecialchars($rec['check_out'] ? date('H:i', strtotime($rec['check_out'])) : ''); ?>', '<?php echo htmlspecialchars($rec['status']); ?>', '<?php echo htmlspecialchars(addslashes($rec['notes'] ?? '')); ?>')" style="margin-right: 4px;" title="Edit">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
  <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
  </svg>
  </button>
  <form method="POST" action="" style="display: inline;">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
  <input type="hidden" name="action" value="delete_attendance">
  <input type="hidden" name="attendance_id" value="<?php echo htmlspecialchars($rec['id']); ?>">
  <button type="submit" class="btn-action btn-delete btn-sm"
  onclick="return confirm('Delete this attendance record?')">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <polyline points="3 6 5 6 21 6"></polyline>
  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
  </svg>
  </button>
  </form>
  </td>
  </tr>
  <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
  </table>
 </div>
</div>
<?php endif; ?>

<!-- MONTHLY VIEW TABLE -->
<?php if ($viewMode === 'monthly'): ?>
<div class="section">
 <div class="section-header">
  <h2 class="section-title">
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 8px; color: var(--primary-500);">
  <line x1="18" y1="20" x2="18" y2="10"></line>
  <line x1="12" y1="20" x2="12" y2="4"></line>
  <line x1="6" y1="20" x2="6" y2="14"></line>
  </svg>
  Monthly Summary — <?php echo date('F Y', strtotime($filterMonth . '-01')); ?>
  </h2>
  <span class="att-record-count"><?php echo count($monthlySummary); ?> employees</span>
 </div>
 <div class="table-container">
  <table>
  <thead>
  <tr>
  <th>Employee</th>
  <th>Department</th>
  <th class="text-center">Present</th>
  <th class="text-center">Absent</th>
  <th class="text-center">Late</th>
  <th class="text-center">Half Day</th>
  <th class="text-center">On Leave</th>
  <th class="text-center">Total Days</th>
  <th class="text-center">Attendance %</th>
  </tr>
  </thead>
  <tbody>
  <?php if (empty($monthlySummary)): ?>
  <tr>
  <td colspan="9" style="text-align: center; color: var(--text-tertiary); padding: 60px 20px;">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 12px; opacity: 0.5;">
  <line x1="18" y1="20" x2="18" y2="10"></line>
  <line x1="12" y1="20" x2="12" y2="4"></line>
  <line x1="6" y1="20" x2="6" y2="14"></line>
  </svg>
  <br>No attendance records for this month.
  <br><a href="javascript:void(0)" onclick="openMarkAttendanceModal()" style="color: var(--primary-500); margin-top: 8px; display: inline-block;">Mark attendance →</a>
  </td>
  </tr>
  <?php else: ?>
  <?php foreach ($monthlySummary as $eid => $data): ?>
  <?php
  $empRate = $data['total_days'] > 0 ? round((($data['present'] + $data['late'] + $data['half_day']) / $data['total_days']) * 100, 1) : 0;
  $rateClass = $empRate >= 90 ? 'rate-excellent' : ($empRate >= 75 ? 'rate-good' : ($empRate >= 50 ? 'rate-average' : 'rate-poor'));
  ?>
  <tr>
  <td>
  <div style="display: flex; align-items: center; gap: 12px;">
  <div class="table-avatar"><?php echo htmlspecialchars(strtoupper(substr($data['name'], 0, 1) . substr(strstr($data['name'], ' '), 1, 1))); ?></div>
  <div>
  <div style="font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($data['name']); ?></div>
  <div style="font-size: 13px; color: var(--text-tertiary);"><?php echo htmlspecialchars($data['position'] ?: '—'); ?></div>
  </div>
  </div>
  </td>
  <td><?php echo htmlspecialchars($data['department'] ?: '—'); ?></td>
  <td class="text-center">
  <span class="att-count-badge att-count--present"><?php echo $data['present']; ?></span>
  </td>
  <td class="text-center">
  <span class="att-count-badge att-count--absent"><?php echo $data['absent']; ?></span>
  </td>
  <td class="text-center">
  <span class="att-count-badge att-count--late"><?php echo $data['late']; ?></span>
  </td>
  <td class="text-center">
  <span class="att-count-badge att-count--halfday"><?php echo $data['half_day']; ?></span>
  </td>
  <td class="text-center">
  <span class="att-count-badge att-count--leave"><?php echo $data['on_leave']; ?></span>
  </td>
  <td class="text-center">
  <span style="font-weight: 600; color: var(--text-primary);"><?php echo $data['total_days']; ?></span>
  </td>
  <td class="text-center">
  <div class="att-rate-container">
  <div class="att-rate-bar">
  <div class="att-rate-fill <?php echo htmlspecialchars($rateClass); ?>" style="width: <?php echo htmlspecialchars($empRate); ?>%;"></div>
  </div>
  <span class="att-rate-text <?php echo htmlspecialchars($rateClass); ?>"><?php echo $empRate; ?>%</span>
  </div>
  </td>
  </tr>
  <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
  </table>
 </div>
</div>
<?php endif; ?>

<!-- Mark Attendance Modal -->
<div class="modal" id="markAttendanceModal">
 <div class="modal-backdrop" onclick="closeModal('markAttendanceModal')"></div>
 <div class="modal-content">
  <div class="modal-header">
  <h2 class="modal-title">Mark Attendance</h2>
  <button class="modal-close" onclick="closeModal('markAttendanceModal')">
  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <line x1="18" y1="6" x2="6" y2="18"></line>
  <line x1="6" y1="6" x2="18" y2="18"></line>
  </svg>
  </button>
  </div>
  <form method="POST" action="" class="modal-form">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
  <div class="form-row">
  <div class="form-group">
  <label for="employee_id">Employee *</label>
  <select id="employee_id" name="employee_id" class="input" required>
  <option value="">Select Employee</option>
  <?php foreach ($allEmployees as $emp): ?>
  <?php if ($emp['status'] === 'active' || $emp['status'] === 'on_leave'): ?>
  <option value="<?php echo htmlspecialchars($emp['id']); ?>">
  <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?>
  (<?php echo htmlspecialchars($emp['department'] ?: 'No Dept'); ?>)
  </option>
  <?php endif; ?>
  <?php endforeach; ?>
  </select>
  </div>
  <div class="form-group">
  <label for="attendance_date">Date *</label>
  <input type="date" id="attendance_date" name="attendance_date" class="input" required value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
  </div>
  </div>

  <div class="form-row">
  <div class="form-group">
  <label for="check_in">Check In</label>
  <input type="time" id="check_in" name="check_in" class="input" value="09:00">
  </div>
  <div class="form-group">
  <label for="check_out">Check Out</label>
  <input type="time" id="check_out" name="check_out" class="input" value="18:00">
  </div>
  </div>

  <div class="form-group">
  <label for="attendance_status">Status *</label>
  <select id="attendance_status" name="attendance_status" class="input" required>
  <option value="present">Present</option>
  <option value="absent">Absent</option>
  <option value="late">Late</option>
  <option value="half_day">Half Day</option>
  <option value="on_leave">On Leave</option>
  </select>
  </div>

  <div class="form-group">
  <label for="notes">Notes</label>
  <textarea id="notes" name="notes" class="input" rows="3" placeholder="Optional notes..." style="resize: vertical;"></textarea>
  </div>

  <div class="modal-actions">
  <button type="button" class="btn btn-secondary" onclick="closeModal('markAttendanceModal')">Cancel</button>
  <button type="submit" name="mark_attendance" class="btn btn-primary">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <polyline points="9 11 12 14 22 4"></polyline>
  <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
  </svg>
  Mark Attendance
  </button>
  </div>
  </form>
 </div>
</div>

<!-- View Daily Work Summary Modal -->
<div class="modal" id="adminWorkSummaryModal">
 <div class="modal-backdrop" onclick="closeModal('adminWorkSummaryModal')"></div>
 <div class="modal-content" style="max-width: 600px;">
  <div class="modal-header">
   <div>
    <h2 class="modal-title" id="awsModalTitle">Daily Work Summary</h2>
    <p style="font-size: 13px; color: var(--text-tertiary); margin-top: 4px;" id="awsModalSubtitle"></p>
   </div>
   <button class="modal-close" onclick="closeModal('adminWorkSummaryModal')">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
     <line x1="18" y1="6" x2="6" y2="18"></line>
     <line x1="6" y1="6" x2="18" y2="18"></line>
    </svg>
   </button>
  </div>
  <div style="padding: 24px 28px;">
   <div style="margin-bottom: 20px;">
    <label style="display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--primary-600); margin-bottom: 6px;">Tasks Worked On Today</label>
    <div id="awsWorkSummary" style="background: var(--bg-secondary); padding: 12px 16px; border-radius: var(--radius-lg); font-size: 14px; line-height: 1.5; color: var(--text-primary); border: 1px solid var(--border); white-space: pre-wrap;"></div>
   </div>
   <div style="margin-bottom: 20px;">
    <label style="display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--success-600); margin-bottom: 6px;">Tasks Completed Today</label>
    <div id="awsCompletedTasks" style="background: var(--bg-secondary); padding: 12px 16px; border-radius: var(--radius-lg); font-size: 14px; line-height: 1.5; color: var(--text-primary); border: 1px solid var(--border); white-space: pre-wrap;"></div>
   </div>
   <div style="margin-bottom: 20px;" id="awsPendingTasksGroup">
    <label style="display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--warning-600); margin-bottom: 6px;">Pending Tasks / Blockers</label>
    <div id="awsPendingTasks" style="background: var(--bg-secondary); padding: 12px 16px; border-radius: var(--radius-lg); font-size: 14px; line-height: 1.5; color: var(--text-primary); border: 1px solid var(--border); white-space: pre-wrap;"></div>
   </div>
   <div id="awsExtraInfo" style="font-size: 12px; color: var(--text-tertiary); border-top: 1px solid var(--border); padding-top: 12px;"></div>
  </div>
  <div style="padding: 16px 28px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end;">
   <button type="button" class="btn btn-secondary" onclick="closeModal('adminWorkSummaryModal')">Close</button>
  </div>
 </div>
</div>

<!-- Resolve Flagged Attendance Modal -->
<div class="modal" id="resolveFlagModal">
 <div class="modal-backdrop" onclick="closeModal('resolveFlagModal')"></div>
 <div class="modal-content" style="max-width: 520px;">
  <div class="modal-header">
   <h2 class="modal-title">Resolve Cutoff Attendance Review</h2>
   <button class="modal-close" onclick="closeModal('resolveFlagModal')">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
     <line x1="18" y1="6" x2="6" y2="18"></line>
     <line x1="6" y1="6" x2="18" y2="18"></line>
    </svg>
   </button>
  </div>
  <form method="POST" action="" class="modal-form">
   <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
   <input type="hidden" name="action" value="resolve_flagged_attendance">
   <input type="hidden" name="attendance_id" id="res_attendance_id" value="">

   <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-lg); padding: 14px 16px; margin-bottom: 20px; font-size: 13px; color: #991b1b; line-height: 1.5;">
    <div style="font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
     <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
     Unclosed Workday Flagged by System
    </div>
    <div><strong>Employee:</strong> <span id="res_emp_name">—</span></div>
    <div><strong>Workday Date:</strong> <span id="res_date">—</span></div>
    <div><strong>Preserved Check-In:</strong> <span id="res_check_in">—</span></div>
    <div style="margin-top: 4px; font-size: 12px; color: #b91c1c;"><strong>Flag Reason:</strong> <span id="res_reason">—</span></div>
   </div>

   <div class="form-group">
    <label for="res_approved_check_out">Approved Check-Out Time *</label>
    <input type="time" id="res_approved_check_out" name="approved_check_out" class="input" required value="18:00">
    <small style="color: var(--text-tertiary); font-size: 12px; margin-top: 4px; display: block;">Authorize employee departure time. Working hours and status will be recalculated automatically.</small>
   </div>

   <div class="form-group">
    <label for="res_resolution_status">Final Attendance Status *</label>
    <select id="res_resolution_status" name="resolution_status" class="input" required>
     <option value="present">Present</option>
     <option value="late">Late</option>
     <option value="half_day">Half Day</option>
     <option value="absent">Absent</option>
    </select>
   </div>

   <div class="form-group">
    <label for="res_resolution_notes">HR Resolution Notes / Justification *</label>
    <textarea id="res_resolution_notes" name="resolution_notes" class="input" rows="3" placeholder="Enter reason for checkout time authorization..." required></textarea>
   </div>

   <div class="modal-actions">
    <button type="button" class="btn btn-secondary" onclick="closeModal('resolveFlagModal')">Cancel</button>
    <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #059669, #10b981);">
     <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="20 6 9 17 4 12"></polyline>
     </svg>
     Authorize &amp; Resolve
    </button>
   </div>
  </form>
 </div>
</div>

<style>
/* ==========================================
  Attendance Report Styles
  ========================================== */

/* Stats Grid */
.att-stats-grid {
 display: grid;
 grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
 gap: 16px;
 margin-bottom: 32px;
}

.att-stat-card {
 background: var(--surface);
 border: 1px solid var(--border);
 border-radius: var(--radius-xl);
 padding: 20px 24px;
 display: flex;
 align-items: center;
 gap: 16px;
 transition: all var(--transition);
 position: relative;
 overflow: hidden;
}

.att-stat-card::before {
 content: '';
 position: absolute;
 top: 0;
 left: 0;
 right: 0;
 height: 3px;
 border-radius: var(--radius-xl) var(--radius-xl) 0 0;
 transition: all var(--transition);
}

.att-stat--total::before { background: linear-gradient(90deg, var(--primary-500), var(--accent-500)); }
.att-stat--present::before { background: var(--success-500); }
.att-stat--absent::before { background: var(--danger-500); }
.att-stat--late::before { background: var(--warning-500); }
.att-stat--leave::before { background: var(--accent-500); }
.att-stat--rate::before { background: linear-gradient(90deg, var(--success-500), var(--primary-500)); }

.att-stat-card:hover {
 transform: translateY(-3px);
 box-shadow: var(--shadow-lg);
 border-color: var(--border-hover);
}

.att-stat-icon {
 width: 44px;
 height: 44px;
 border-radius: var(--radius-lg);
 display: flex;
 align-items: center;
 justify-content: center;
 flex-shrink: 0;
 transition: all var(--transition-smooth);
}

.att-stat-icon svg {
 width: 22px;
 height: 22px;
}

.att-stat--total .att-stat-icon { background: var(--primary-50); color: var(--primary-500); }
.att-stat--present .att-stat-icon { background: var(--success-50); color: var(--success-500); }
.att-stat--absent .att-stat-icon { background: var(--danger-50); color: var(--danger-500); }
.att-stat--late .att-stat-icon { background: var(--warning-50); color: var(--warning-500); }
.att-stat--leave .att-stat-icon { background: var(--accent-50); color: var(--accent-500); }
.att-stat--rate .att-stat-icon { background: var(--primary-50); color: var(--primary-500); }

.att-stat-info {
 display: flex;
 flex-direction: column;
 gap: 4px;
}

.att-stat-label {
 font-size: 12px;
 font-weight: 500;
 color: var(--text-secondary);
 text-transform: uppercase;
 letter-spacing: 0.5px;
}

.att-stat-value {
 font-size: 26px;
 font-weight: 700;
 color: var(--text-primary);
 line-height: 1;
}

/* View Toggle */
.att-view-toggle {
 display: flex;
 gap: 4px;
 background: var(--surface);
 padding: 4px;
 border-radius: var(--radius-lg);
 border: 1px solid var(--border);
 margin-bottom: 20px;
 width: fit-content;
}

.att-view-btn {
 padding: 10px 20px;
 border-radius: var(--radius);
 font-size: 14px;
 font-weight: 500;
 color: var(--text-secondary);
 text-decoration: none;
 transition: all var(--transition);
 display: inline-flex;
 align-items: center;
 gap: 8px;
}

.att-view-btn:hover {
 background: var(--surface-hover);
 color: var(--text-primary);
}

.att-view-btn.active {
 background: var(--primary-500);
 color: white;
 box-shadow: var(--shadow-sm);
}

/* Filters Section */
.att-filters-section {
 background: var(--surface);
 border: 1px solid var(--border);
 border-radius: var(--radius-xl);
 padding: 24px;
 margin-bottom: 32px;
 transition: all var(--transition-smooth);
}

.att-filters-form {
 display: flex;
 flex-wrap: wrap;
 gap: 16px;
 align-items: flex-end;
}

.att-filter-group {
 flex: 1;
 min-width: 160px;
}

.att-filter-group label {
 display: block;
 margin-bottom: 6px;
 font-size: 13px;
 font-weight: 500;
 color: var(--text-secondary);
 text-transform: uppercase;
 letter-spacing: 0.3px;
}

.att-filter-group .input {
 padding: 10px 14px;
 font-size: 14px;
}

.att-filter-actions {
 display: flex;
 gap: 8px;
 align-items: flex-end;
 padding-bottom: 2px;
}

.btn-sm {
 padding: 10px 16px !important;
 font-size: 13px !important;
}

/* Section */
.section {
 margin-bottom: 40px;
}

.section-header {
 display: flex;
 justify-content: space-between;
 align-items: center;
 margin-bottom: 20px;
 flex-wrap: wrap;
 gap: 12px;
}

.section-title {
 font-size: 20px;
 font-weight: 600;
 color: var(--text-primary);
 margin: 0;
}

.att-record-count {
 font-size: 14px;
 font-weight: 500;
 color: var(--text-tertiary);
 background: var(--bg-secondary);
 padding: 6px 14px;
 border-radius: var(--radius-full);
 border: 1px solid var(--border);
}

/* Table Overrides */
.table-container {
 background: var(--surface);
 border: 1px solid var(--border);
 border-radius: var(--radius-xl);
 overflow-x: auto;
 transition: all var(--transition-smooth);
}

table {
 width: 100%;
 border-collapse: collapse;
}

th, td {
 padding: 16px 20px;
 text-align: left;
}

th {
 background: var(--bg-secondary);
 font-size: 11px;
 font-weight: 600;
 color: var(--text-secondary);
 text-transform: uppercase;
 letter-spacing: 0.5px;
 border-bottom: 1px solid var(--border);
 transition: background-color var(--transition-smooth);
 white-space: nowrap;
}

td {
 font-size: 14px;
 color: var(--text-primary);
 border-bottom: 1px solid var(--border);
 transition: all var(--transition-smooth);
}

tr:last-child td {
 border-bottom: none;
}

tbody tr:hover td {
 background: var(--surface-hover);
}

.text-center {
 text-align: center;
}

/* Table Avatar */
.table-avatar {
 width: 40px;
 height: 40px;
 border-radius: var(--radius-lg);
 background: linear-gradient(135deg, var(--primary-500), var(--accent-500));
 color: white;
 display: flex;
 align-items: center;
 justify-content: center;
 font-size: 13px;
 font-weight: 700;
 flex-shrink: 0;
}

/* Time Badge */
.att-time-badge {
 font-size: 13px;
 font-weight: 600;
 color: var(--text-primary);
 background: var(--bg-secondary);
 padding: 4px 10px;
 border-radius: var(--radius);
 border: 1px solid var(--border);
}

/* Working Hours */
.att-hours {
 font-size: 14px;
 font-weight: 600;
 color: var(--primary-500);
}

/* Attendance Status Badges */
.att-status-badge {
 display: inline-flex;
 align-items: center;
 padding: 5px 12px;
 border-radius: var(--radius-full);
 font-size: 12px;
 font-weight: 600;
 text-transform: capitalize;
}

.att-status--present {
 background: var(--success-50);
 color: var(--success-500);
}

.att-status--absent {
 background: var(--danger-50);
 color: var(--danger-500);
}

.att-status--late {
 background: var(--warning-50);
 color: var(--warning-500);
}

.att-status--half_day {
 background: rgba(59, 130, 246, 0.1);
 color: var(--primary-500);
}

.att-status--on_leave {
 background: var(--accent-50);
 color: var(--accent-500);
}

/* Monthly Count Badges */
.att-count-badge {
 display: inline-flex;
 align-items: center;
 justify-content: center;
 min-width: 32px;
 height: 28px;
 padding: 0 8px;
 border-radius: var(--radius);
 font-size: 13px;
 font-weight: 700;
}

.att-count--present {
 background: var(--success-50);
 color: var(--success-500);
}

.att-count--absent {
 background: var(--danger-50);
 color: var(--danger-500);
}

.att-count--late {
 background: var(--warning-50);
 color: var(--warning-500);
}

.att-count--halfday {
 background: rgba(59, 130, 246, 0.1);
 color: var(--primary-500);
}

.att-count--leave {
 background: var(--accent-50);
 color: var(--accent-500);
}

/* Attendance Rate Bar */
.att-rate-container {
 display: flex;
 align-items: center;
 gap: 10px;
}

.att-rate-bar {
 flex: 1;
 height: 8px;
 background: var(--bg-tertiary);
 border-radius: var(--radius-full);
 overflow: hidden;
 min-width: 60px;
}

.att-rate-fill {
 height: 100%;
 border-radius: var(--radius-full);
 transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

.att-rate-fill.rate-excellent {
 background: linear-gradient(90deg, #10b981, #059669);
}

.att-rate-fill.rate-good {
 background: linear-gradient(90deg, #3b82f6, #2563eb);
}

.att-rate-fill.rate-average {
 background: linear-gradient(90deg, #f59e0b, #d97706);
}

.att-rate-fill.rate-poor {
 background: linear-gradient(90deg, #ef4444, #dc2626);
}

.att-rate-text {
 font-size: 13px;
 font-weight: 700;
 min-width: 45px;
 text-align: right;
}

.att-rate-text.rate-excellent { color: var(--success-500); }
.att-rate-text.rate-good { color: var(--primary-500); }
.att-rate-text.rate-average { color: var(--warning-500); }
.att-rate-text.rate-poor { color: var(--danger-500); }

/* Button Styles */
.btn {
 display: inline-flex;
 align-items: center;
 justify-content: center;
 gap: 8px;
 padding: 12px 24px;
 font-family: inherit;
 font-size: 14px;
 font-weight: 500;
 border-radius: var(--radius-lg);
 cursor: pointer;
 transition: all var(--transition);
 border: none;
 text-decoration: none;
}

.btn-primary {
 background: linear-gradient(135deg, var(--primary-500), var(--accent-500));
 color: white;
}

.btn-primary:hover {
 transform: translateY(-1px);
 box-shadow: var(--shadow-md);
}

.btn-secondary {
 background: var(--bg-secondary);
 color: var(--text-secondary);
 border: 1px solid var(--border);
}

.btn-secondary:hover {
 background: var(--surface-hover);
 color: var(--text-primary);
}

/* Action Buttons */
.btn-action {
 display: inline-flex;
 align-items: center;
 gap: 4px;
 padding: 6px 10px;
 border-radius: var(--radius);
 font-size: 13px;
 font-weight: 500;
 text-decoration: none;
 transition: all var(--transition);
 border: none;
 cursor: pointer;
}

.btn-delete {
 background: var(--danger-50);
 color: var(--danger-500);
 border: 1px solid transparent;
}

.btn-delete:hover {
 background: var(--danger-500);
 color: white;
}

[data-theme="dark"] .btn-delete {
 background: rgba(239, 68, 68, 0.15);
}

/* Modal */
.modal {
 display: none;
 position: fixed;
 inset: 0;
 z-index: 1000;
}

.modal.active {
 display: block;
}

.modal-backdrop {
 position: absolute;
 inset: 0;
 background: rgba(0, 0, 0, 0.5);
 backdrop-filter: blur(4px);
 animation: fadeIn 0.2s ease-out;
}

.modal-content {
 position: absolute;
 top: 50%;
 left: 50%;
 transform: translate(-50%, -50%);
 background: var(--surface);
 border-radius: var(--radius-2xl);
 width: 90%;
 max-width: 560px;
 max-height: 90vh;
 overflow-y: auto;
 box-shadow: var(--shadow-xl);
 animation: slideUp 0.3s ease-out;
}

.modal-header {
 padding: 24px 28px;
 border-bottom: 1px solid var(--border);
 display: flex;
 justify-content: space-between;
 align-items: center;
}

.modal-title {
 margin: 0;
 font-size: 20px;
 font-weight: 600;
 color: var(--text-primary);
}

.modal-close {
 background: none;
 border: none;
 color: var(--text-tertiary);
 cursor: pointer;
 padding: 4px;
 border-radius: var(--radius);
 transition: all var(--transition);
 display: flex;
 align-items: center;
 justify-content: center;
}

.modal-close:hover {
 background: var(--surface-hover);
 color: var(--text-primary);
}

.modal-form {
 padding: 28px;
}

.form-row {
 display: grid;
 grid-template-columns: 1fr 1fr;
 gap: 16px;
}

.form-group {
 margin-bottom: 20px;
}

.form-group label {
 display: block;
 margin-bottom: 8px;
 font-size: 14px;
 font-weight: 500;
 color: var(--text-secondary);
}

.form-group .input {
 width: 100%;
 padding: 12px 16px;
 background: var(--bg-secondary);
 border: 2px solid var(--border);
 border-radius: var(--radius-lg);
 color: var(--text-primary);
 font-family: inherit;
 font-size: 15px;
 transition: all var(--transition);
}

.form-group .input:focus {
 outline: none;
 border-color: var(--primary-500);
 background: var(--surface);
 box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

.form-group select.input {
 cursor: pointer;
 appearance: none;
 background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
 background-repeat: no-repeat;
 background-position: right 16px center;
 padding-right: 48px;
}

.form-group textarea.input {
 min-height: 80px;
}

.modal-actions {
 display: flex;
 gap: 12px;
 justify-content: flex-end;
 padding-top: 8px;
}

@keyframes fadeIn {
 from { opacity: 0; }
 to { opacity: 1; }
}

@keyframes slideUp {
 from {
  opacity: 0;
  transform: translate(-50%, -45%);
 }
 to {
  opacity: 1;
  transform: translate(-50%, -50%);
 }
}

/* Responsive */
@media (max-width: 768px) {
 .att-stats-grid {
  grid-template-columns: repeat(2, 1fr);
 }

 .att-filters-form {
  flex-direction: column;
 }

 .att-filter-group {
  min-width: 100%;
 }

 .form-row {
  grid-template-columns: 1fr;
 }

 .modal-content {
  width: 95%;
  max-height: 95vh;
 }

 th, td {
  padding: 12px 14px;
 }

 .att-view-toggle {
  width: 100%;
 }

 .att-view-btn {
  flex: 1;
  justify-content: center;
 }
}

@media (max-width: 480px) {
 .att-stats-grid {
  grid-template-columns: 1fr;
 }
}
</style>

<script>
// Modal Functions
function openModal(modalId) {
 document.getElementById(modalId).classList.add('active');
 document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
 document.getElementById(modalId).classList.remove('active');
 document.body.style.overflow = '';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
 if (e.key === 'Escape') {
  closeModal('markAttendanceModal');
  closeModal('adminWorkSummaryModal');
  closeModal('resolveFlagModal');
 }
});

// Auto-clear check-in/out if status is absent or on_leave
document.addEventListener('DOMContentLoaded', function() {
 const statusSelect = document.getElementById('attendance_status');
 if (statusSelect) {
  statusSelect.addEventListener('change', function() {
  const checkIn = document.getElementById('check_in');
  const checkOut = document.getElementById('check_out');
  if (this.value === 'absent' || this.value === 'on_leave') {
  checkIn.value = '';
  checkOut.value = '';
  checkIn.disabled = true;
  checkOut.disabled = true;
  } else {
  checkIn.disabled = false;
  checkOut.disabled = false;
  if (!checkIn.value) checkIn.value = '09:00';
  if (!checkOut.value) checkOut.value = '18:00';
  }
  });
 }
});

function openMarkAttendanceModal() {
 document.getElementById('employee_id').value = '';
 document.getElementById('attendance_date').value = '<?php echo date('Y-m-d'); ?>';
 document.getElementById('check_in').value = '09:00';
 document.getElementById('check_out').value = '18:00';
 document.getElementById('attendance_status').value = 'present';
 document.getElementById('notes').value = '';
 document.getElementById('check_in').disabled = false;
 document.getElementById('check_out').disabled = false;
 
 document.querySelector('#markAttendanceModal .modal-title').innerText = 'Mark Attendance';
 document.querySelector('#markAttendanceModal button[name="mark_attendance"]').innerHTML = `
 <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;">
 <polyline points="9 11 12 14 22 4"></polyline>
 <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
 </svg>
 Mark Attendance`;
 
 openModal('markAttendanceModal');
}

function editAttendance(empId, date, checkIn, checkOut, status, notes) {
 document.getElementById('employee_id').value = empId;
 document.getElementById('attendance_date').value = date;
 document.getElementById('check_in').value = checkIn;
 document.getElementById('check_out').value = checkOut;
 document.getElementById('attendance_status').value = status;
 document.getElementById('notes').value = notes;
 
 document.getElementById('check_in').disabled = (status === 'absent' || status === 'on_leave');
 document.getElementById('check_out').disabled = (status === 'absent' || status === 'on_leave');
 
 document.querySelector('#markAttendanceModal .modal-title').innerText = 'Edit Attendance';
 document.querySelector('#markAttendanceModal button[name="mark_attendance"]').innerHTML = `
 <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;">
 <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
 <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
 </svg>
 Update Attendance`;
 
 openModal('markAttendanceModal');
}

function viewAdminWorkSummary(data) {
 document.getElementById('awsModalTitle').textContent = data.employee + ' — Work Summary';
 let sub = data.date + ' (' + (data.work_mode === 'wfh' ? 'Work From Home' : 'On-Site') + ')';
 document.getElementById('awsModalSubtitle').textContent = sub;
 
 document.getElementById('awsWorkSummary').textContent = data.work_summary || 'None recorded.';
 document.getElementById('awsCompletedTasks').textContent = data.completed_tasks || 'None recorded.';
 
 const pendingGroup = document.getElementById('awsPendingTasksGroup');
 if (data.pending_tasks && data.pending_tasks.trim() !== '') {
  pendingGroup.style.display = 'block';
  document.getElementById('awsPendingTasks').textContent = data.pending_tasks;
 } else {
  pendingGroup.style.display = 'none';
 }
 
 let extra = [];
 if (data.check_in_dist !== null && data.check_in_dist !== undefined) {
  extra.push('Check-in: ' + Math.round(data.check_in_dist) + 'm from office');
 }
 if (data.check_out_dist !== null && data.check_out_dist !== undefined) {
  extra.push('Check-out: ' + Math.round(data.check_out_dist) + 'm from office');
 }
 if (data.leave_code) {
  extra.push('Departure Note: ' + data.leave_code);
 }
 document.getElementById('awsExtraInfo').innerHTML = extra.join(' &bull; ');
 
 openModal('adminWorkSummaryModal');
}

function openResolveFlagModal(id, empName, date, inTime, reason) {
 document.getElementById('res_attendance_id').value = id;
 document.getElementById('res_emp_name').textContent = empName;
 document.getElementById('res_date').textContent = date;
 document.getElementById('res_check_in').textContent = inTime;
 document.getElementById('res_reason').textContent = reason;
 document.getElementById('res_resolution_notes').value = '';
 openModal('resolveFlagModal');
}
</script>

<?php include 'footer.php'; ?>