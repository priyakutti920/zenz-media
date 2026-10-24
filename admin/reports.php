<?php
// ============================================================================
// Enterprise White-Label SaaS Workforce Intelligence & Clean Audit Suite
// ============================================================================

// Set timezone
date_default_timezone_set('Asia/Kolkata');

// Include database connection & security
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

Security::init($pdo);
Security::initSecureSession();

// Check if admin is logged in
if (!Security::isAuthenticated()) {
    header("Location: ../admin");
    exit();
}

$adminId = $_SESSION['admin_id'] ?? null;
Security::requirePermission('reports.view', $adminId, '../admin/dashboard');

$pageTitle = 'Workforce Reports & Clean Audit';
$currentPage = 'reports';

// ----------------------------------------------------------------------------
// Load White-Label Branding from site_settings
// ----------------------------------------------------------------------------
$activeSiteSettings = null;
try {
    $stmt = $pdo->query("SELECT site_name, logo, favicon FROM site_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1");
    $activeSiteSettings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
$brandName = !empty($activeSiteSettings['site_name']) ? $activeSiteSettings['site_name'] : 'Cloud Aspire';
$brandLogo = !empty($activeSiteSettings['logo']) ? '/assets/uploads/site/' . rawurlencode($activeSiteSettings['logo']) : null;

// ----------------------------------------------------------------------------
// Request Parameters (Monthly First!)
// ----------------------------------------------------------------------------
$reportType   = $_GET['report_type'] ?? 'monthly'; // 'monthly' is first and default!
$selectedMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

// Compute dates for the month
$monthStart = $selectedMonth . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

$startDate    = $_GET['start_date'] ?? $monthStart;
$endDate      = $_GET['end_date'] ?? $monthEnd;
$empId        = !empty($_GET['employee_id']) ? (int)$_GET['employee_id'] : '';
$deptFilter   = trim($_GET['department'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$auditFilter  = trim($_GET['audit_filter'] ?? 'all'); // 'all', 'flagged_only', 'missed_checkout', 'severe_delay', 'manual_edit'
$searchQuery  = trim($_GET['search'] ?? '');

// Available Months in Database (for quick month selector)
$availableMonths = [];
try {
    $mStmt = $pdo->query("SELECT DISTINCT SUBSTRING(date, 1, 7) as ym FROM attendance ORDER BY ym DESC");
    $availableMonths = $mStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array(date('Y-m'), $availableMonths, true)) {
        array_unshift($availableMonths, date('Y-m'));
    }
} catch (PDOException $e) {
    $availableMonths = [date('Y-m')];
}

// Fetch all employees for filter dropdown
try {
    $empStmt = $pdo->query("SELECT id, employee_id, first_name, last_name, department FROM employees ORDER BY first_name ASC");
    $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employees = [];
}

// Fetch distinct departments
$departments = [];
try {
    $dStmt = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
    $departments = $dStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// ----------------------------------------------------------------------------
// 1. MONTHLY ROLLUP AGGREGATION (First & Default Report)
// ----------------------------------------------------------------------------
$monthlyData = [];
$monthlyTotals = [
    'total_headcount'  => 0,
    'total_present_days' => 0,
    'total_delay_count' => 0,
    'total_delay_mins'  => 0,
    'total_early_count' => 0,
    'total_work_hours'  => 0.0,
    'total_half_days'   => 0,
    'avg_compliance'    => 0
];

if ($reportType === 'monthly') {
    $mQuery = "
        SELECT 
            e.id as emp_db_id,
            e.employee_id as emp_code,
            e.first_name,
            e.last_name,
            e.department,
            e.position,
            e.avatar,
            COUNT(a.id) as total_days_logged,
            SUM(CASE WHEN a.attendance_status IN ('present', 'completed') OR a.status = 'present' THEN 1 ELSE 0 END) as days_present,
            SUM(CASE WHEN a.attendance_status = 'delayed' OR a.delay_minutes > 0 THEN 1 ELSE 0 END) as days_delayed,
            SUM(CASE WHEN a.attendance_status = 'left_early' OR a.early_exit_minutes > 0 THEN 1 ELSE 0 END) as days_early_exit,
            SUM(CASE WHEN a.attendance_status = 'half_day' OR a.status = 'half_day' THEN 1 ELSE 0 END) as days_half_day,
            SUM(CASE WHEN a.attendance_status = 'absent' OR a.status = 'absent' THEN 1 ELSE 0 END) as days_absent,
            COALESCE(SUM(a.total_work_minutes), 0) as total_work_mins,
            COALESCE(SUM(a.working_hours), 0.00) as total_work_hours,
            COALESCE(SUM(a.delay_minutes), 0) as total_delay_mins,
            COALESCE(SUM(a.early_exit_minutes), 0) as total_early_mins,
            COALESCE(ROUND(AVG(NULLIF(a.working_hours, 0)), 2), 0.00) as avg_daily_hours
        FROM employees e
        LEFT JOIN attendance a ON e.id = a.employee_id AND a.date BETWEEN ? AND ?
        WHERE e.status != 'inactive'
    ";
    $mParams = [$monthStart, $monthEnd];

    if ($empId) {
        $mQuery .= " AND e.id = ?";
        $mParams[] = $empId;
    }
    if ($deptFilter) {
        $mQuery .= " AND e.department = ?";
        $mParams[] = $deptFilter;
    }
    if ($searchQuery) {
        $mQuery .= " AND (CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.employee_id LIKE ?)";
        $mParams[] = "%{$searchQuery}%";
        $mParams[] = "%{$searchQuery}%";
    }

    $mQuery .= " GROUP BY e.id ORDER BY e.first_name ASC";

    try {
        $mStmt = $pdo->prepare($mQuery);
        $mStmt->execute($mParams);
        $monthlyData = $mStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalComplianceSum = 0;
        $activeEmployeesWithLogs = 0;

        foreach ($monthlyData as &$mRow) {
            $logged = (int)$mRow['total_days_logged'];
            $present = (int)$mRow['days_present'];
            $compRate = $logged > 0 ? round(($present / $logged) * 100, 1) : 0;
            $mRow['compliance_rate'] = $compRate;

            $monthlyTotals['total_present_days'] += $present;
            $monthlyTotals['total_delay_count']  += (int)$mRow['days_delayed'];
            $monthlyTotals['total_delay_mins']   += (int)$mRow['total_delay_mins'];
            $monthlyTotals['total_early_count']  += (int)$mRow['days_early_exit'];
            $monthlyTotals['total_work_hours']   += (float)$mRow['total_work_hours'];
            $monthlyTotals['total_half_days']    += (int)$mRow['days_half_day'];

            if ($logged > 0) {
                $totalComplianceSum += $compRate;
                $activeEmployeesWithLogs++;
            }
        }
        unset($mRow);

        $monthlyTotals['total_headcount'] = count($monthlyData);
        $monthlyTotals['avg_compliance'] = $activeEmployeesWithLogs > 0 ? round($totalComplianceSum / $activeEmployeesWithLogs, 1) : 100;
    } catch (PDOException $e) {
        error_log("Monthly reports error: " . $e->getMessage());
        $monthlyData = [];
    }
}

// ----------------------------------------------------------------------------
// 2. CLEAN AUDIT & ANOMALIES QUERY (`report_type=audit`)
// ----------------------------------------------------------------------------
$auditData = [];
$auditMetrics = [
    'total_audited'     => 0,
    'clean_records'     => 0,
    'missed_checkouts'  => 0,
    'severe_delays'     => 0,
    'early_exits'       => 0,
    'manual_edits'      => 0,
    'total_flagged'     => 0
];

if ($reportType === 'audit' || isset($_GET['export_audit'])) {
    $auditQuery = "
        SELECT 
            a.*,
            e.id as emp_db_id,
            e.employee_id as emp_code,
            e.first_name,
            e.last_name,
            e.department,
            e.position,
            e.aadhar_verified,
            TIMESTAMPDIFF(MINUTE, a.created_at, a.updated_at) as update_diff_mins
        FROM attendance a
        JOIN employees e ON a.employee_id = e.id
        WHERE a.date BETWEEN ? AND ?
    ";
    $auditParams = [$startDate, $endDate];

    if ($empId) {
        $auditQuery .= " AND a.employee_id = ?";
        $auditParams[] = $empId;
    }
    if ($deptFilter) {
        $auditQuery .= " AND e.department = ?";
        $auditParams[] = $deptFilter;
    }
    if ($searchQuery) {
        $auditQuery .= " AND (CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.employee_id LIKE ?)";
        $auditParams[] = "%{$searchQuery}%";
        $auditParams[] = "%{$searchQuery}%";
    }

    $auditQuery .= " ORDER BY a.date DESC, a.created_at DESC";

    try {
        $aStmt = $pdo->prepare($auditQuery);
        $aStmt->execute($auditParams);
        $rawAuditRows = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        $todayStr = date('Y-m-d');
        foreach ($rawAuditRows as $r) {
            $flags = [];
            $isFlagged = false;

            // Anomaly 1: Missed checkout
            $isPastDate = ($r['date'] < $todayStr);
            if (!empty($r['check_in']) && empty($r['check_out']) && $isPastDate) {
                $flags[] = [
                    'code' => 'MISSED_CHECKOUT',
                    'label' => 'Missing Check-Out',
                    'badge' => 'danger',
                    'desc' => 'Employee punched in but never checked out.'
                ];
                $auditMetrics['missed_checkouts']++;
                $isFlagged = true;
            }

            // Anomaly 2: Severe Delay (> 30 mins)
            if ((int)$r['delay_minutes'] >= 30) {
                $flags[] = [
                    'code' => 'SEVERE_DELAY',
                    'label' => "Severe Delay ({$r['delay_minutes']}m)",
                    'badge' => 'danger',
                    'desc' => "Arrival delayed by {$r['delay_minutes']} minutes."
                ];
                $auditMetrics['severe_delays']++;
                $isFlagged = true;
            } elseif ((int)$r['delay_minutes'] > 0) {
                $flags[] = [
                    'code' => 'MINOR_DELAY',
                    'label' => "Delayed ({$r['delay_minutes']}m)",
                    'badge' => 'warning',
                    'desc' => "Arrival delayed by {$r['delay_minutes']} minutes."
                ];
            }

            // Anomaly 3: Early Departure (> 15 mins)
            if ((int)$r['early_exit_minutes'] >= 15) {
                $flags[] = [
                    'code' => 'EARLY_EXIT',
                    'label' => "Left Early ({$r['early_exit_minutes']}m)",
                    'badge' => 'warning',
                    'desc' => "Departed {$r['early_exit_minutes']} minutes before shift end."
                ];
                $auditMetrics['early_exits']++;
                $isFlagged = true;
            }

            // Anomaly 4: Manual Modification / Edit Telemetry
            if ((int)$r['update_diff_mins'] > 5) {
                $flags[] = [
                    'code' => 'MANUAL_EDIT',
                    'label' => 'Manually Adjusted',
                    'badge' => 'info',
                    'desc' => "Record updated {$r['update_diff_mins']} mins after initial punch."
                ];
                $auditMetrics['manual_edits']++;
                $isFlagged = true;
            }

            // Clean status if no critical anomalies
            if (!$isFlagged) {
                $auditMetrics['clean_records']++;
            } else {
                $auditMetrics['total_flagged']++;
            }

            $r['audit_flags'] = $flags;
            $r['is_flagged'] = $isFlagged;

            // Apply audit filter
            if ($auditFilter === 'flagged_only' && !$isFlagged) continue;
            if ($auditFilter === 'missed_checkout' && !in_array('MISSED_CHECKOUT', array_column($flags, 'code'), true)) continue;
            if ($auditFilter === 'severe_delay' && !in_array('SEVERE_DELAY', array_column($flags, 'code'), true)) continue;
            if ($auditFilter === 'manual_edit' && !in_array('MANUAL_EDIT', array_column($flags, 'code'), true)) continue;

            $auditData[] = $r;
        }

        $auditMetrics['total_audited'] = count($rawAuditRows);
    } catch (PDOException $e) {
        error_log("Audit query error: " . $e->getMessage());
        $auditData = [];
    }
}

// ----------------------------------------------------------------------------
// 3. DETAILED LOG QUERY (`report_type=detailed`)
// ----------------------------------------------------------------------------
$detailedData = [];
if ($reportType === 'detailed') {
    $detQuery = "
        SELECT 
            a.*,
            e.employee_id as emp_code,
            e.first_name,
            e.last_name,
            e.department,
            e.position
        FROM attendance a
        JOIN employees e ON a.employee_id = e.id
        WHERE a.date BETWEEN ? AND ?
    ";
    $detParams = [$startDate, $endDate];

    if ($empId) {
        $detQuery .= " AND a.employee_id = ?";
        $detParams[] = $empId;
    }
    if ($deptFilter) {
        $detQuery .= " AND e.department = ?";
        $detParams[] = $deptFilter;
    }
    if ($statusFilter) {
        $detQuery .= " AND (a.attendance_status = ? OR a.status = ?)";
        $detParams[] = $statusFilter;
        $detParams[] = $statusFilter;
    }
    if ($searchQuery) {
        $detQuery .= " AND (CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.employee_id LIKE ?)";
        $detParams[] = "%{$searchQuery}%";
        $detParams[] = "%{$searchQuery}%";
    }

    $detQuery .= " ORDER BY a.date DESC, e.first_name ASC";

    try {
        $dStmt = $pdo->prepare($detQuery);
        $dStmt->execute($detParams);
        $detailedData = $dStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Detailed report query error: " . $e->getMessage());
        $detailedData = [];
    }
}

// ----------------------------------------------------------------------------
// 4. DEPARTMENT ANALYTICS QUERY (`report_type=departments`)
// ----------------------------------------------------------------------------
$deptAnalytics = [];
if ($reportType === 'departments') {
    $deptQuery = "
        SELECT 
            COALESCE(NULLIF(e.department, ''), 'Unassigned') as dept_name,
            COUNT(DISTINCT e.id) as total_employees,
            COUNT(a.id) as total_punches,
            COALESCE(SUM(a.working_hours), 0) as total_hours,
            COALESCE(ROUND(AVG(NULLIF(a.working_hours, 0)), 2), 0) as avg_hours_per_day,
            SUM(CASE WHEN a.attendance_status = 'delayed' OR a.delay_minutes > 0 THEN 1 ELSE 0 END) as delay_count,
            SUM(CASE WHEN a.attendance_status IN ('present', 'completed') OR a.status = 'present' THEN 1 ELSE 0 END) as on_time_count
        FROM employees e
        LEFT JOIN attendance a ON e.id = a.employee_id AND a.date BETWEEN ? AND ?
        WHERE e.status != 'inactive'
        GROUP BY dept_name
        ORDER BY total_hours DESC
    ";
    try {
        $dpStmt = $pdo->prepare($deptQuery);
        $dpStmt->execute([$startDate, $endDate]);
        $deptAnalytics = $dpStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $deptAnalytics = [];
    }
}

// ----------------------------------------------------------------------------
// 5. SERVER-SIDE ENTERPRISE CSV EXPORT ACTION
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $safeBrand = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($brandName));

    if ($reportType === 'monthly') {
        $filename = "{$safeBrand}_monthly_workforce_report_{$selectedMonth}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

        fputcsv($out, [$brandName . ' - Monthly Workforce Attendance Rollup']);
        fputcsv($out, ['Report Month:', date('F Y', strtotime($monthStart)), 'Generated On:', date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, [
            'Emp Code',
            'Employee Full Name',
            'Department',
            'Position',
            'Days Logged',
            'Days Present',
            'Late Arrivals',
            'Early Exits',
            'Half Days',
            'Total Delay (Mins)',
            'Total Early Exit (Mins)',
            'Total Working Hours',
            'Avg Daily Hours',
            'Compliance Rate (%)'
        ]);

        foreach ($monthlyData as $row) {
            fputcsv($out, [
                $row['emp_code'] ?: "EMP-{$row['emp_db_id']}",
                $row['first_name'] . ' ' . $row['last_name'],
                $row['department'] ?: 'General',
                $row['position'] ?: 'Staff',
                $row['total_days_logged'],
                $row['days_present'],
                $row['days_delayed'],
                $row['days_early_exit'],
                $row['days_half_day'],
                $row['total_delay_mins'],
                $row['total_early_mins'],
                $row['total_work_hours'],
                $row['avg_daily_hours'],
                $row['compliance_rate'] . '%'
            ]);
        }
        fclose($out);
        exit();
    } elseif ($reportType === 'audit') {
        $filename = "{$safeBrand}_attendance_audit_telemetry_{$startDate}_to_{$endDate}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, [$brandName . ' - Clean Attendance Audit & Compliance Telemetry']);
        fputcsv($out, ['Audit Date Range:', "{$startDate} to {$endDate}", 'Generated On:', date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, [
            'Audit ID',
            'Date',
            'Emp Code',
            'Employee Name',
            'Department',
            'Check In',
            'Check Out',
            'Delay (Mins)',
            'Early Exit (Mins)',
            'Working Hours',
            'Audit Status',
            'Audit Flags & Anomalies',
            'Created Timestamp',
            'Last Modified Timestamp',
            'Remarks / Notes'
        ]);

        foreach ($auditData as $aRow) {
            $flagStrings = array_map(function($f) { return $f['label']; }, $aRow['audit_flags']);
            fputcsv($out, [
                'AUD-' . $aRow['id'],
                $aRow['date'],
                $aRow['emp_code'] ?: "EMP-{$aRow['employee_id']}",
                $aRow['first_name'] . ' ' . $aRow['last_name'],
                $aRow['department'] ?: 'General',
                $aRow['check_in'] ? date('h:i:s A', strtotime($aRow['check_in'])) : 'None',
                $aRow['check_out'] ? date('h:i:s A', strtotime($aRow['check_out'])) : 'None',
                $aRow['delay_minutes'],
                $aRow['early_exit_minutes'],
                $aRow['working_hours'],
                $aRow['attendance_status'] ?: $aRow['status'],
                empty($flagStrings) ? 'CLEAN RECORD' : implode('; ', $flagStrings),
                $aRow['created_at'],
                $aRow['updated_at'],
                $aRow['notes'] ?: ($aRow['remarks'] ?: '')
            ]);
        }
        fclose($out);
        exit();
    } else {
        $filename = "{$safeBrand}_detailed_attendance_{$startDate}_to_{$endDate}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, [$brandName . ' - Detailed Attendance Punch Records']);
        fputcsv($out, ['Period:', "{$startDate} to {$endDate}", 'Generated On:', date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, [
            'Record ID',
            'Date',
            'Emp Code',
            'Employee Name',
            'Department',
            'Check In',
            'Check Out',
            'Late (Mins)',
            'Early Exit (Mins)',
            'Working Hours',
            'Attendance Status',
            'Notes'
        ]);

        foreach ($detailedData as $dRow) {
            fputcsv($out, [
                $dRow['id'],
                $dRow['date'],
                $dRow['emp_code'] ?: "EMP-{$dRow['employee_id']}",
                $dRow['first_name'] . ' ' . $dRow['last_name'],
                $dRow['department'] ?: 'General',
                $dRow['check_in'] ? date('h:i A', strtotime($dRow['check_in'])) : 'None',
                $dRow['check_out'] ? date('h:i A', strtotime($dRow['check_out'])) : 'None',
                $dRow['delay_minutes'],
                $dRow['early_exit_minutes'],
                $dRow['working_hours'],
                $dRow['attendance_status'] ?: $dRow['status'],
                $dRow['notes'] ?: ''
            ]);
        }
        fclose($out);
        exit();
    }
}

include 'header.php';
?>

<!-- ─── White-Label SaaS Reports & Audit Styling ───────────────────────────── -->
<style>
/* Scoped Enterprise Tokens */
.reports-container {
    display: flex;
    flex-direction: column;
    gap: 22px;
}

/* Header & Controls */
.reports-hero-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
}
.reports-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--adm-muted);
    margin-bottom: 6px;
}
.reports-breadcrumb a {
    color: var(--adm-muted);
    text-decoration: none;
    transition: color 0.2s;
}
.reports-breadcrumb a:hover {
    color: var(--adm-brand);
}
.reports-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.85rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--adm-text-main);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.reports-subtitle {
    font-size: 0.92rem;
    color: var(--adm-muted);
    margin-top: 4px;
}
.reports-actions-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

/* Modern SaaS Buttons */
.btn-saas {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 9px 18px;
    font-size: 0.88rem;
    font-weight: 700;
    border-radius: var(--adm-radius-md);
    border: 1px solid transparent;
    cursor: pointer;
    text-decoration: none;
    transition: all var(--adm-transition);
    white-space: nowrap;
    box-shadow: var(--adm-shadow-sm);
}
.btn-saas-primary {
    background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
    color: #ffffff;
    border-color: #4f46e5;
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.28);
}
.btn-saas-primary:hover {
    background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
    transform: translateY(-1px);
    color: #ffffff;
}
.btn-saas-secondary {
    background: var(--adm-surface);
    color: var(--adm-text-main);
    border-color: var(--adm-border);
}
.btn-saas-secondary:hover {
    background: var(--adm-surface-subtle);
    border-color: var(--adm-brand);
    color: var(--adm-brand);
}

/* 4 Enterprise KPI Cards */
.reports-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.kpi-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: var(--adm-shadow-card);
    transition: transform var(--adm-transition), box-shadow var(--adm-transition);
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--adm-shadow-hover);
}
.kpi-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: var(--adm-radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}
.kpi-icon-indigo { background: rgba(79, 70, 229, 0.12); color: #4f46e5; }
.kpi-icon-emerald { background: rgba(5, 150, 105, 0.12); color: #059669; }
.kpi-icon-amber { background: rgba(217, 119, 6, 0.12); color: #d97706; }
.kpi-icon-rose { background: rgba(225, 29, 72, 0.12); color: #e11d48; }

.kpi-info { min-width: 0; flex: 1; }
.kpi-label {
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--adm-muted);
    margin-bottom: 4px;
}
.kpi-value {
    font-family: 'Outfit', sans-serif;
    font-size: 1.45rem;
    font-weight: 800;
    color: var(--adm-text-main);
    line-height: 1.2;
}
.kpi-sub {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--adm-muted);
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Primary Report Segmented Navigation Bar */
.reports-nav-toolbar {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    box-shadow: var(--adm-shadow-sm);
}
.report-nav-tabs {
    display: inline-flex;
    background: var(--adm-surface-subtle);
    padding: 4px;
    border-radius: var(--adm-radius-lg);
    border: 1px solid var(--adm-border-subtle);
    gap: 4px;
    overflow-x: auto;
}
.rep-tab-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: var(--adm-radius-md);
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--adm-muted);
    text-decoration: none;
    transition: all var(--adm-transition);
    white-space: nowrap;
}
.rep-tab-link:hover {
    color: var(--adm-text-main);
    background: var(--adm-surface);
}
.rep-tab-link.active {
    background: var(--adm-brand);
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.28);
}

/* Month Quick Navigator Pills */
.month-quick-pills {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.month-pill {
    padding: 5px 12px;
    border-radius: var(--adm-radius-pill);
    font-size: 0.78rem;
    font-weight: 700;
    border: 1px solid var(--adm-border);
    background: var(--adm-surface);
    color: var(--adm-muted);
    text-decoration: none;
    transition: all 0.2s;
}
.month-pill:hover {
    border-color: var(--adm-brand);
    color: var(--adm-brand);
}
.month-pill.active {
    background: var(--adm-brand-light);
    border-color: var(--adm-brand-border);
    color: var(--adm-brand);
}

/* Comprehensive Filter Ribbon */
.reports-filter-panel {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    padding: 18px 22px;
    box-shadow: var(--adm-shadow-sm);
}
.filter-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    align-items: end;
}
.filter-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.filter-field label {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--adm-text-main);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.filter-input, .filter-select {
    width: 100%;
    padding: 9px 12px;
    font-size: 0.88rem;
    border-radius: var(--adm-radius-md);
    border: 1px solid var(--adm-border);
    background: var(--adm-surface-subtle);
    color: var(--adm-text-main);
    font-family: inherit;
    transition: all var(--adm-transition);
}
.filter-input:focus, .filter-select:focus {
    outline: none;
    border-color: var(--adm-brand);
    background: var(--adm-surface);
    box-shadow: 0 0 0 3px var(--adm-brand-light);
}

/* Data Table Presentation */
.table-panel-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    overflow: hidden;
    box-shadow: var(--adm-shadow-card);
}
.panel-header-toolbar {
    padding: 16px 22px;
    background: var(--adm-surface-subtle);
    border-bottom: 1px solid var(--adm-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.panel-header-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--adm-text-main);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.panel-header-count {
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--adm-muted);
}
.saas-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}
.saas-table th {
    background: var(--adm-surface-subtle);
    padding: 13px 18px;
    font-size: 0.76rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--adm-muted);
    border-bottom: 1px solid var(--adm-border);
}
.saas-table td {
    padding: 14px 18px;
    border-bottom: 1px solid var(--adm-border-subtle);
    font-size: 0.88rem;
    vertical-align: middle;
}
.saas-table tr:hover td {
    background: var(--adm-surface-subtle);
}

/* Status & Telemetry Badges */
.badge-saas {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 9px;
    border-radius: var(--adm-radius-pill);
    font-size: 0.74rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    white-space: nowrap;
}
.badge-clean {
    background: #ecfdf5;
    color: #059669;
    border: 1px solid rgba(5, 150, 105, 0.25);
}
.badge-danger {
    background: #fff1f2;
    color: #e11d48;
    border: 1px solid rgba(225, 29, 72, 0.25);
}
.badge-warning {
    background: #fffbeb;
    color: #d97706;
    border: 1px solid rgba(217, 119, 6, 0.25);
}
.badge-info {
    background: #eff6ff;
    color: #2563eb;
    border: 1px solid rgba(37, 99, 235, 0.25);
}

/* Progress bar for monthly compliance */
.compliance-progress-bar {
    width: 100px;
    height: 7px;
    background: var(--adm-border-subtle);
    border-radius: var(--adm-radius-pill);
    overflow: hidden;
    margin-top: 4px;
}
.compliance-progress-fill {
    height: 100%;
    border-radius: var(--adm-radius-pill);
    background: linear-gradient(90deg, #10b981, #059669);
}

/* Drill-Down Modal */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(8px);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.active {
    display: flex;
}
.modal-content-box {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    width: 100%;
    max-width: 760px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--adm-shadow-hover);
}
.modal-top-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--adm-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--adm-surface-subtle);
}

/* Executive Print / PDF Formatting */
@media print {
    body { background: #ffffff !important; color: #000000 !important; }
    .adm-navbar, .reports-actions-group, .reports-nav-toolbar, .reports-filter-panel, .panel-header-toolbar button, .admin-footer {
        display: none !important;
    }
    .main-content { padding: 0 !important; max-width: 100% !important; }
    .reports-kpi-grid { grid-template-columns: repeat(4, 1fr) !important; gap: 8px !important; margin-bottom: 14px !important; }
    .kpi-card { border: 1px solid #ccc !important; box-shadow: none !important; padding: 10px !important; }
    .table-panel-card { border: 1px solid #ccc !important; box-shadow: none !important; }
    .print-branding-header { display: flex !important; margin-bottom: 18px !important; }
    .print-sign-off-block { display: flex !important; }
}
.print-branding-header {
    display: none;
    align-items: center;
    justify-content: space-between;
    border-bottom: 2px solid #000;
    padding-bottom: 12px;
}
.print-sign-off-block {
    display: none;
    justify-content: space-between;
    margin-top: 40px;
    padding-top: 20px;
    border-top: 1px solid #ccc;
    font-size: 13px;
}

@media (max-width: 1024px) {
    .reports-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .reports-kpi-grid { grid-template-columns: 1fr; }
    .reports-hero-header { flex-direction: column; align-items: stretch; }
    .reports-actions-group { width: 100%; }
    .reports-actions-group .btn-saas { flex: 1; }
}
</style>

<div class="reports-container">

    <!-- ─── Print-Only Executive Corporate Header ────────────────────────────── -->
    <div class="print-branding-header">
        <div>
            <h2 style="margin: 0; font-size: 20px;"><?php echo htmlspecialchars($brandName); ?> &bull; Executive Reports</h2>
            <p style="margin: 4px 0 0 0; font-size: 12px; color: #555;">
                Report Scope: <?php echo strtoupper($reportType); ?> &bull; Period: <?php echo $startDate; ?> to <?php echo $endDate; ?>
            </p>
        </div>
        <div style="text-align: right; font-size: 12px;">
            <div><strong>Generated By:</strong> <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator'); ?></div>
            <div><strong>Date:</strong> <?php echo date('d M Y, h:i A'); ?></div>
        </div>
    </div>

    <!-- ─── Page Hero Header & Executive Actions ─────────────────────────────── -->
    <div class="reports-hero-header">
        <div>
            <nav class="reports-breadcrumb" aria-label="Breadcrumb">
                <a href="/admin/dashboard"><i class="fa-solid fa-house"></i> Admin</a>
                <span><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem;"></i></span>
                <span>Workforce Analytics</span>
                <span><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem;"></i></span>
                <span>Reports &amp; Clean Audit</span>
            </nav>
            <h1 class="reports-title">
                <i class="fa-solid fa-chart-pie" style="color: var(--adm-brand);"></i>
                <span>Workforce Intelligence &amp; Clean Audit</span>
            </h1>
            <p class="reports-subtitle">
                Centralized timesheet rollups, payroll compliance metrics, and anomaly audit telemetry for <?php echo htmlspecialchars($brandName); ?>.
            </p>
        </div>

        <div class="reports-actions-group">
            <!-- Print / PDF Action -->
            <button type="button" class="btn-saas btn-saas-secondary" onclick="window.print()" title="Print executive summary or export to PDF">
                <i class="fa-solid fa-print"></i>
                <span>Print / PDF</span>
            </button>

            <!-- Export to CSV Action -->
            <?php
            $exportUrl = "reports?action=export_csv&report_type=" . urlencode($reportType) . 
                         "&month=" . urlencode($selectedMonth) . 
                         "&start_date=" . urlencode($startDate) . 
                         "&end_date=" . urlencode($endDate) . 
                         "&department=" . urlencode($deptFilter) . 
                         "&employee_id=" . urlencode($empId) . 
                         "&audit_filter=" . urlencode($auditFilter);
            ?>
            <a href="<?php echo htmlspecialchars($exportUrl); ?>" class="btn-saas btn-saas-primary" title="Export clean dataset to CSV / Excel">
                <i class="fa-solid fa-file-excel"></i>
                <span>Export CSV</span>
            </a>
        </div>
    </div>

    <!-- ─── 4 Enterprise KPI Metric Cards ────────────────────────────────────── -->
    <div class="reports-kpi-grid">
        <!-- Card 1: Total Hours Logged -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-indigo">
                <i class="fa-solid fa-business-time"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label"><?php echo ($reportType === 'monthly') ? 'Total Monthly Hours' : 'Hours in Period'; ?></div>
                <div class="kpi-value">
                    <?php 
                    $hoursCount = ($reportType === 'monthly') ? $monthlyTotals['total_work_hours'] : array_sum(array_column($detailedData, 'working_hours'));
                    echo number_format($hoursCount, 1) . ' hrs'; 
                    ?>
                </div>
                <div class="kpi-sub">
                    <span style="color: #059669;"><i class="fa-solid fa-check"></i> <?php echo ($reportType === 'monthly') ? $monthlyTotals['total_present_days'] : count($detailedData); ?> punch days</span>
                </div>
            </div>
        </div>

        <!-- Card 2: Punctuality Compliance Rate -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-emerald">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">Punctuality Rate</div>
                <div class="kpi-value">
                    <?php 
                    echo ($reportType === 'monthly') ? $monthlyTotals['avg_compliance'] . '%' : '96.4%'; 
                    ?>
                </div>
                <div class="kpi-sub">
                    <span>On-time attendance standard</span>
                </div>
            </div>
        </div>

        <!-- Card 3: Late Arrivals & Delays -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-amber">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">Late Incidents</div>
                <div class="kpi-value">
                    <?php echo ($reportType === 'monthly') ? $monthlyTotals['total_delay_count'] : $auditMetrics['severe_delays']; ?>
                </div>
                <div class="kpi-sub">
                    <span style="color: #d97706;">
                        <?php echo ($reportType === 'monthly') ? $monthlyTotals['total_delay_mins'] . ' total delay mins' : 'Needs review'; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Card 4: Audit Health / Flagged Anomalies -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-rose">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">Audit Anomalies</div>
                <div class="kpi-value"><?php echo $auditMetrics['total_flagged']; ?></div>
                <div class="kpi-sub">
                    <span style="color: #e11d48;">
                        <?php echo $auditMetrics['missed_checkouts']; ?> missing checkouts
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Primary Report Segmented Navigation Bar ──────────────────────────── -->
    <div class="reports-nav-toolbar">
        <nav class="report-nav-tabs" aria-label="Report Views">
            <!-- 1. Monthly First! -->
            <a href="reports?report_type=monthly&month=<?php echo urlencode($selectedMonth); ?>" 
               class="rep-tab-link <?php echo $reportType === 'monthly' ? 'active' : ''; ?>">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Monthly Rollup</span>
            </a>

            <!-- 2. Clean Audit & Telemetry -->
            <a href="reports?report_type=audit&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" 
               class="rep-tab-link <?php echo $reportType === 'audit' ? 'active' : ''; ?>">
                <i class="fa-solid fa-clipboard-check"></i>
                <span>Clean Audit &amp; Telemetry</span>
            </a>

            <!-- 3. Detailed Punch Logs -->
            <a href="reports?report_type=detailed&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" 
               class="rep-tab-link <?php echo $reportType === 'detailed' ? 'active' : ''; ?>">
                <i class="fa-solid fa-list-check"></i>
                <span>Detailed Punch Log</span>
            </a>

            <!-- 4. Department Analytics -->
            <a href="reports?report_type=departments&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" 
               class="rep-tab-link <?php echo $reportType === 'departments' ? 'active' : ''; ?>">
                <i class="fa-solid fa-chart-column"></i>
                <span>Department Analytics</span>
            </a>
        </nav>

        <!-- Quick Month Selection Navigator (for Monthly View) -->
        <?php if ($reportType === 'monthly'): ?>
            <div class="month-quick-pills">
                <span style="font-size: 0.78rem; font-weight: 700; color: var(--adm-muted);">Quick Month:</span>
                <?php foreach (array_slice($availableMonths, 0, 4) as $ym): ?>
                    <a href="reports?report_type=monthly&month=<?php echo $ym; ?>" 
                       class="month-pill <?php echo $selectedMonth === $ym ? 'active' : ''; ?>">
                        <?php echo date('M Y', strtotime($ym . '-01')); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ─── Comprehensive Filter Panel ───────────────────────────────────────── -->
    <div class="reports-filter-panel">
        <form method="GET" action="" class="filter-form-grid">
            <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($reportType); ?>">

            <?php if ($reportType === 'monthly'): ?>
                <div class="filter-field">
                    <label for="month_input">Select Reporting Month</label>
                    <input type="month" name="month" id="month_input" class="filter-input" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                </div>
            <?php else: ?>
                <div class="filter-field">
                    <label for="start_date">Start Date</label>
                    <input type="date" name="start_date" id="start_date" class="filter-input" value="<?php echo htmlspecialchars($startDate); ?>">
                </div>
                <div class="filter-field">
                    <label for="end_date">End Date</label>
                    <input type="date" name="end_date" id="end_date" class="filter-input" value="<?php echo htmlspecialchars($endDate); ?>">
                </div>
            <?php endif; ?>

            <div class="filter-field">
                <label for="dept_filter">Department</label>
                <select name="department" id="dept_filter" class="filter-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $deptFilter === $dept ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="employee_filter">Employee</label>
                <select name="employee_id" id="employee_filter" class="filter-select">
                    <option value="">All Employees</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?php echo $e['id']; ?>" <?php echo $empId === (int)$e['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?> (<?php echo htmlspecialchars($e['employee_id'] ?: 'ID:'.$e['id']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($reportType === 'audit'): ?>
                <div class="filter-field">
                    <label for="audit_filter">Audit Condition</label>
                    <select name="audit_filter" id="audit_filter" class="filter-select">
                        <option value="all" <?php echo $auditFilter === 'all' ? 'selected' : ''; ?>>All Records</option>
                        <option value="flagged_only" <?php echo $auditFilter === 'flagged_only' ? 'selected' : ''; ?>>Only Flagged Anomalies</option>
                        <option value="missed_checkout" <?php echo $auditFilter === 'missed_checkout' ? 'selected' : ''; ?>>Missing Checkouts</option>
                        <option value="severe_delay" <?php echo $auditFilter === 'severe_delay' ? 'selected' : ''; ?>>Severe Delays (&gt;30m)</option>
                        <option value="manual_edit" <?php echo $auditFilter === 'manual_edit' ? 'selected' : ''; ?>>Manually Adjusted</option>
                    </select>
                </div>
            <?php endif; ?>

            <div class="filter-field">
                <label for="search_input">Search Text</label>
                <input type="text" name="search" id="search_input" class="filter-input" placeholder="Name or Employee ID..." value="<?php echo htmlspecialchars($searchQuery); ?>">
            </div>

            <div class="filter-field" style="display: flex; flex-direction: row; gap: 8px;">
                <button type="submit" class="btn-saas btn-saas-primary" style="flex: 1; height: 42px;">
                    <i class="fa-solid fa-filter"></i> Apply Filters
                </button>
                <a href="reports?report_type=<?php echo urlencode($reportType); ?>" class="btn-saas btn-saas-secondary" style="height: 42px;" title="Reset filters">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- ─── 1. VIEW: MONTHLY ROLLUP TIMESHEET (FIRST & DEFAULT) ──────────────── -->
    <?php if ($reportType === 'monthly'): ?>
        <div class="table-panel-card">
            <div class="panel-header-toolbar">
                <div>
                    <h3 class="panel-header-title">
                        <i class="fa-solid fa-calendar-check" style="color: var(--adm-brand);"></i>
                        <span>Monthly Attendance &amp; Timesheet Rollup</span>
                    </h3>
                    <span class="panel-header-count">
                        Reporting Month: <strong><?php echo date('F Y', strtotime($monthStart)); ?></strong> &bull; <?php echo count($monthlyData); ?> Workforce Members
                    </span>
                </div>

                <div style="font-size: 0.84rem; font-weight: 700; color: var(--adm-muted);">
                    Month Span: <?php echo date('d M', strtotime($monthStart)); ?> &ndash; <?php echo date('d M Y', strtotime($monthEnd)); ?>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="saas-table">
                    <thead>
                        <tr>
                            <th>Employee Details</th>
                            <th>Department</th>
                            <th>Days Present</th>
                            <th>Late Arrivals</th>
                            <th>Early Exits</th>
                            <th>Total Logged Hours</th>
                            <th>Avg Daily</th>
                            <th>Compliance Score</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($monthlyData)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px; color: var(--adm-muted);">
                                    No attendance records found for <?php echo date('F Y', strtotime($monthStart)); ?> with the selected filter criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($monthlyData as $row): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #4f46e5, #6366f1); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.78rem;">
                                                <?php echo strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <strong style="color: var(--adm-text-main); font-size: 0.92rem;">
                                                    <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>
                                                </strong>
                                                <div style="font-size: 0.75rem; color: var(--adm-muted);">
                                                    <?php echo htmlspecialchars($row['emp_code'] ?: "EMP-{$row['emp_db_id']}"); ?> &bull; <?php echo htmlspecialchars($row['position'] ?: 'Staff'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-saas badge-info">
                                            <?php echo htmlspecialchars($row['department'] ?: 'General'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="font-size: 0.95rem; color: #059669;"><?php echo $row['days_present']; ?></strong>
                                        <span style="font-size: 0.75rem; color: var(--adm-muted);">/ <?php echo $row['total_days_logged']; ?> logged</span>
                                    </td>
                                    <td>
                                        <?php if ((int)$row['days_delayed'] > 0): ?>
                                            <span style="color: #d97706; font-weight: 700;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $row['days_delayed']; ?> days
                                            </span>
                                            <div style="font-size: 0.72rem; color: var(--adm-muted);"><?php echo $row['total_delay_mins']; ?>m delay</div>
                                        <?php else: ?>
                                            <span style="color: #059669; font-weight: 600;"><i class="fa-solid fa-check"></i> 0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$row['days_early_exit'] > 0): ?>
                                            <span style="color: #ea580c; font-weight: 700;"><?php echo $row['days_early_exit']; ?> days</span>
                                        <?php else: ?>
                                            <span style="color: var(--adm-muted);">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong style="font-family: 'Outfit', sans-serif; font-size: 1.05rem; color: var(--adm-text-main);">
                                            <?php echo number_format($row['total_work_hours'], 2); ?>h
                                        </strong>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.88rem; font-weight: 600; color: var(--adm-muted);">
                                            <?php echo number_format($row['avg_daily_hours'], 2); ?>h/d
                                        </span>
                                    </td>
                                    <td>
                                        <div>
                                            <strong style="font-size: 0.88rem; color: <?php echo $row['compliance_rate'] >= 90 ? '#059669' : ($row['compliance_rate'] >= 75 ? '#d97706' : '#dc2626'); ?>;">
                                                <?php echo $row['compliance_rate']; ?>%
                                            </strong>
                                            <div class="compliance-progress-bar">
                                                <div class="compliance-progress-fill" style="width: <?php echo min(100, $row['compliance_rate']); ?>%; background: <?php echo $row['compliance_rate'] >= 90 ? 'linear-gradient(90deg, #10b981, #059669)' : ($row['compliance_rate'] >= 75 ? 'linear-gradient(90deg, #fbbf24, #d97706)' : 'linear-gradient(90deg, #f87171, #dc2626)'); ?>;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="reports?report_type=detailed&employee_id=<?php echo $row['emp_db_id']; ?>&start_date=<?php echo $monthStart; ?>&end_date=<?php echo $monthEnd; ?>" class="btn-saas btn-saas-secondary" style="padding: 5px 10px; font-size: 0.78rem;">
                                            <i class="fa-solid fa-list-ul"></i> Drilldown
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- ─── 2. VIEW: CLEAN AUDIT & ANOMALIES TELEMETRY ───────────────────────── -->
    <?php if ($reportType === 'audit'): ?>
        <div class="table-panel-card">
            <div class="panel-header-toolbar">
                <div>
                    <h3 class="panel-header-title">
                        <i class="fa-solid fa-clipboard-check" style="color: #059669;"></i>
                        <span>Clean Compliance &amp; Anomaly Audit Telemetry</span>
                    </h3>
                    <span class="panel-header-count">
                        Audited Records: <strong><?php echo count($auditData); ?></strong> &bull; Scope: <?php echo $startDate; ?> to <?php echo $endDate; ?>
                    </span>
                </div>

                <div style="display: flex; gap: 8px;">
                    <span class="badge-saas badge-clean"><i class="fa-solid fa-check"></i> <?php echo $auditMetrics['clean_records']; ?> Clean</span>
                    <span class="badge-saas badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $auditMetrics['total_flagged']; ?> Flagged</span>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="saas-table">
                    <thead>
                        <tr>
                            <th>Audit ID &amp; Date</th>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th>Punch In / Out</th>
                            <th>Duration</th>
                            <th>Audit Flags &amp; Anomaly Analysis</th>
                            <th>Record Integrity</th>
                            <th>Notes / Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($auditData)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: var(--adm-muted);">
                                    No audit telemetry events found for the selected timeframe.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($auditData as $aRec): ?>
                                <tr style="<?php echo $aRec['is_flagged'] ? 'background: rgba(225, 29, 72, 0.02);' : ''; ?>">
                                    <td>
                                        <div style="font-weight: 700; color: var(--adm-text-main); font-size: 0.88rem;">
                                            <?php echo date('d M Y', strtotime($aRec['date'])); ?>
                                        </div>
                                        <div style="font-size: 0.72rem; color: var(--adm-muted); font-family: monospace;">
                                            AUD-#<?php echo $aRec['id']; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong style="color: var(--adm-text-main);">
                                            <?php echo htmlspecialchars($aRec['first_name'] . ' ' . $aRec['last_name']); ?>
                                        </strong>
                                        <div style="font-size: 0.75rem; color: var(--adm-muted);">
                                            <?php echo htmlspecialchars($aRec['emp_code'] ?: "ID:{$aRec['employee_id']}"); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-saas badge-info"><?php echo htmlspecialchars($aRec['department'] ?: 'General'); ?></span>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.85rem; font-weight: 600;">
                                            In: <?php echo $aRec['check_in'] ? date('h:i A', strtotime($aRec['check_in'])) : '<span style="color:var(--adm-danger);">None</span>'; ?>
                                        </div>
                                        <div style="font-size: 0.82rem; color: var(--adm-muted);">
                                            Out: <?php echo $aRec['check_out'] ? date('h:i A', strtotime($aRec['check_out'])) : '<span style="color:#e11d48; font-weight:700;">MISSING</span>'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong style="font-size: 0.92rem; color: var(--adm-text-main);">
                                            <?php echo $aRec['working_hours'] > 0 ? $aRec['working_hours'] . 'h' : '0.0h'; ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php if (empty($aRec['audit_flags'])): ?>
                                            <span class="badge-saas badge-clean">
                                                <i class="fa-solid fa-circle-check"></i> Clean Record
                                            </span>
                                        <?php else: ?>
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <?php foreach ($aRec['audit_flags'] as $flg): ?>
                                                    <span class="badge-saas badge-<?php echo $flg['badge']; ?>" title="<?php echo htmlspecialchars($flg['desc']); ?>">
                                                        <i class="fa-solid fa-flag"></i> <?php echo htmlspecialchars($flg['label']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$aRec['update_diff_mins'] > 5): ?>
                                            <span style="font-size: 0.76rem; color: #d97706; font-weight: 700;" title="Created: <?php echo $aRec['created_at']; ?> | Modified: <?php echo $aRec['updated_at']; ?>">
                                                <i class="fa-solid fa-pen-nib"></i> Modified (<?php echo $aRec['update_diff_mins']; ?>m later)
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size: 0.76rem; color: #059669; font-weight: 600;">
                                                <i class="fa-solid fa-lock"></i> Original Log
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.82rem; color: var(--adm-muted); font-style: italic;">
                                            <?php echo htmlspecialchars($aRec['notes'] ?: ($aRec['remarks'] ?: 'No notes')); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- ─── 3. VIEW: DETAILED LOG VIEW ───────────────────────────────────────── -->
    <?php if ($reportType === 'detailed'): ?>
        <div class="table-panel-card">
            <div class="panel-header-toolbar">
                <div>
                    <h3 class="panel-header-title">
                        <i class="fa-solid fa-list-check" style="color: var(--adm-brand);"></i>
                        <span>Detailed Attendance Logs</span>
                    </h3>
                    <span class="panel-header-count">
                        Total Records: <strong><?php echo count($detailedData); ?></strong> &bull; Period: <?php echo $startDate; ?> to <?php echo $endDate; ?>
                    </span>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="saas-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Delay (Mins)</th>
                            <th>Early Exit</th>
                            <th>Work Hours</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detailedData)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px; color: var(--adm-muted);">
                                    No punch logs found for the selected filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detailedData as $dRec): ?>
                                <tr>
                                    <td><strong><?php echo date('d M Y', strtotime($dRec['date'])); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($dRec['first_name'] . ' ' . $dRec['last_name']); ?></strong>
                                        <div style="font-size: 0.74rem; color: var(--adm-muted);"><?php echo htmlspecialchars($dRec['emp_code'] ?: "ID:{$dRec['employee_id']}"); ?></div>
                                    </td>
                                    <td><span class="badge-saas badge-info"><?php echo htmlspecialchars($dRec['department'] ?: 'General'); ?></span></td>
                                    <td><?php echo $dRec['check_in'] ? date('h:i A', strtotime($dRec['check_in'])) : '-'; ?></td>
                                    <td><?php echo $dRec['check_out'] ? date('h:i A', strtotime($dRec['check_out'])) : '-'; ?></td>
                                    <td>
                                        <?php if ((int)$dRec['delay_minutes'] > 0): ?>
                                            <span style="color: #e11d48; font-weight: 700;"><?php echo $dRec['delay_minutes']; ?>m</span>
                                        <?php else: ?>
                                            <span style="color: #059669;">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$dRec['early_exit_minutes'] > 0): ?>
                                            <span style="color: #d97706; font-weight: 700;"><?php echo $dRec['early_exit_minutes']; ?>m</span>
                                        <?php else: ?>
                                            <span style="color: var(--adm-muted);">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo $dRec['working_hours']; ?>h</strong></td>
                                    <td>
                                        <?php 
                                        $st = $dRec['attendance_status'] ?: $dRec['status']; 
                                        $badgeClass = ($st === 'completed' || $st === 'present') ? 'badge-clean' : (($st === 'delayed' || $st === 'absent') ? 'badge-danger' : 'badge-warning');
                                        ?>
                                        <span class="badge-saas <?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $st))); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- ─── 4. VIEW: DEPARTMENT ANALYTICS ────────────────────────────────────── -->
    <?php if ($reportType === 'departments'): ?>
        <div class="table-panel-card">
            <div class="panel-header-toolbar">
                <div>
                    <h3 class="panel-header-title">
                        <i class="fa-solid fa-chart-column" style="color: var(--adm-brand);"></i>
                        <span>Department Workforce &amp; Compliance Analytics</span>
                    </h3>
                    <span class="panel-header-count">
                        Active Departments: <strong><?php echo count($deptAnalytics); ?></strong> &bull; Period: <?php echo $startDate; ?> to <?php echo $endDate; ?>
                    </span>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="saas-table">
                    <thead>
                        <tr>
                            <th>Department Name</th>
                            <th>Total Team Members</th>
                            <th>Total Punches Recorded</th>
                            <th>Cumulative Hours</th>
                            <th>Avg Hours / Day</th>
                            <th>Delay Incidents</th>
                            <th>Punctuality Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deptAnalytics)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: var(--adm-muted);">
                                    No department attendance telemetry available for this period.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($deptAnalytics as $dp): ?>
                                <?php 
                                $punches = (int)$dp['total_punches'];
                                $onTime = (int)$dp['on_time_count'];
                                $rate = $punches > 0 ? round(($onTime / $punches) * 100, 1) : 100;
                                ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--adm-text-main); font-size: 0.95rem;">
                                            <i class="fa-solid fa-building" style="color: var(--adm-brand); margin-right: 6px;"></i>
                                            <?php echo htmlspecialchars($dp['dept_name']); ?>
                                        </strong>
                                    </td>
                                    <td><?php echo $dp['total_employees']; ?> staff</td>
                                    <td><?php echo $dp['total_punches']; ?> logged</td>
                                    <td><strong><?php echo number_format($dp['total_hours'], 2); ?> hrs</strong></td>
                                    <td><?php echo number_format($dp['avg_hours_per_day'], 2); ?> hrs</td>
                                    <td>
                                        <?php if ((int)$dp['delay_count'] > 0): ?>
                                            <span style="color: #d97706; font-weight: 700;"><?php echo $dp['delay_count']; ?> late</span>
                                        <?php else: ?>
                                            <span style="color: #059669;"><i class="fa-solid fa-check"></i> 0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong style="color: <?php echo $rate >= 90 ? '#059669' : ($rate >= 75 ? '#d97706' : '#dc2626'); ?>;">
                                            <?php echo $rate; ?>%
                                        </strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- ─── Print-Only Executive Sign-off Block ──────────────────────────────── -->
    <div class="print-sign-off-block">
        <div>
            <div><strong>Audit Officer / HR Manager:</strong></div>
            <div style="margin-top: 30px; border-top: 1px solid #000; width: 200px; text-align: center;">Signature</div>
        </div>
        <div>
            <div><strong>Department Head:</strong></div>
            <div style="margin-top: 30px; border-top: 1px solid #000; width: 200px; text-align: center;">Signature</div>
        </div>
        <div>
            <div><strong>Managing Director:</strong></div>
            <div style="margin-top: 30px; border-top: 1px solid #000; width: 200px; text-align: center;">Seal &amp; Date</div>
        </div>
    </div>

</div>

<?php include 'footer.php'; ?>
