<?php
// Set timezone to India / Local Time
date_default_timezone_set('Asia/Kolkata');

// Include database connection first
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
Security::init($pdo);
Security::initSecureSession();

// Check if admin is logged in
if (!Security::isAuthenticated()) {
    header("Location: ../admin");
    exit();
}

// Check session timeout
if (!Security::setSessionTimeout()) {
    header("Location: ../admin?msg=timeout");
    exit();
}

// Set page variables for header
$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

// Fetch real stats from database
$totalEmployees = 0;
$presentToday = 0;
$absentToday = 0;
$lateToday = 0;
$earlyLeaveToday = 0;
$monthlyAttendanceRate = 0;
$pendingLeaves = 0;
$pendingAadhar = 0;
$activeTasks = 0;
$recentAttendance = [];

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

// Determine dynamic greeting
$hour = (int)date('G');
if ($hour < 12) {
    $greeting = "Good morning";
} elseif ($hour < 17) {
    $greeting = "Good afternoon";
} else {
    $greeting = "Good evening";
}
$adminName = htmlspecialchars($_SESSION['admin_username'] ?? 'Administrator');

try {
    // 1. Total employees
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees");
    $totalEmployees = (int)$stmt->fetchColumn();

    // 2. Present Today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE date = ? AND (attendance_status IN ('present', 'completed', 'half_day') OR status = 'present')");
    $stmt->execute([$today]);
    $presentToday = (int)$stmt->fetchColumn();

    // 3. On Leave Today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE date = ? AND (attendance_status = 'on_leave' OR status = 'on_leave')");
    $stmt->execute([$today]);
    $onLeaveToday = (int)$stmt->fetchColumn();
    
    $absentToday = max(0, $totalEmployees - $presentToday - $onLeaveToday);

    // 4. Late Employees Today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE date = ? AND (attendance_status = 'delayed' OR status = 'late' OR delay_minutes > 0)");
    $stmt->execute([$today]);
    $lateToday = (int)$stmt->fetchColumn();

    // 5. Early Leave Today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE date = ? AND (attendance_status = 'left_early' OR early_exit_minutes > 0)");
    $stmt->execute([$today]);
    $earlyLeaveToday = (int)$stmt->fetchColumn();

    // 6. Monthly Attendance Rate
    $stmt = $pdo->prepare("SELECT attendance_status, status, COUNT(*) as cnt FROM attendance WHERE date BETWEEN ? AND ? GROUP BY attendance_status, status");
    $stmt->execute([$monthStart, $monthEnd]);
    $attStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalAtt = 0;
    $presentAtt = 0;
    foreach ($attStats as $s) {
        $totalAtt += $s['cnt'];
        $st = $s['attendance_status'] ?: $s['status'];
        if (in_array($st, ['present', 'completed', 'half_day', 'delayed', 'late'])) {
            $presentAtt += $s['cnt'];
        }
    }
    $monthlyAttendanceRate = $totalAtt > 0 ? round(($presentAtt / $totalAtt) * 100, 1) : 0;

    // 7. Action Items (Pending Leaves, KYC, Tasks)
    $stmtLeaves = $pdo->query("SHOW TABLES LIKE 'leave_requests'");
    if ($stmtLeaves->rowCount() > 0) {
        $pendingLeaves = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
    }
    
    $pendingAadhar = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE aadhar_verified = 1")->fetchColumn();
    
    $stmtTasks = $pdo->query("SHOW TABLES LIKE 'tasks'");
    if ($stmtTasks->rowCount() > 0) {
        $activeTasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status IN ('sent', 'in_progress', 'verify')")->fetchColumn();
    }

    // 8. Recent Attendance Activity Feed
    $stmt = $pdo->query("
        SELECT a.*, e.first_name, e.last_name, e.avatar, e.department, e.position, e.employee_id as emp_code
        FROM attendance a
        LEFT JOIN employees e ON a.employee_id = e.id
        ORDER BY a.date DESC, a.check_in DESC
        LIMIT 6
    ");
    $recentAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 9. Chart Data: Last 7 Days Attendance
    $chartDates = [];
    $chartPresent = [];
    $chartLate = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $chartDates[] = date('M d', strtotime($d));
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE date = ? AND (attendance_status IN ('present', 'completed', 'half_day', 'delayed') OR status IN ('present', 'late'))");
        $stmt->execute([$d]);
        $chartPresent[] = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE date = ? AND (attendance_status = 'delayed' OR delay_minutes > 0)");
        $stmt->execute([$d]);
        $chartLate[] = (int)$stmt->fetchColumn();
    }

} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
}
?>

<?php include __DIR__ . '/header.php'; ?>

<!-- Custom Dashboard Styling & Animations -->
<style>
    /* ── Entrance Animation ── */
    @keyframes dashFadeUp {
        from {
            opacity: 0;
            transform: translateY(14px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .animate-up {
        animation: dashFadeUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    /* Staggered Delays */
    .stagger-1 { animation-delay: 0.04s; }
    .stagger-2 { animation-delay: 0.08s; }
    .stagger-3 { animation-delay: 0.12s; }
    .stagger-4 { animation-delay: 0.16s; }
    .stagger-5 { animation-delay: 0.20s; }
    .stagger-6 { animation-delay: 0.24s; }

    /* ── Dynamic Animated Icon Suite ── */
    @keyframes iconFloat {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-4px); }
    }

    @keyframes iconPulseGlow {
        0%, 100% { transform: scale(1); filter: drop-shadow(0 0 2px rgba(79, 70, 229, 0.35)); }
        50% { transform: scale(1.14); filter: drop-shadow(0 0 8px rgba(79, 70, 229, 0.65)); }
    }

    @keyframes iconBounceSoft {
        0%, 100% { transform: translateY(0); }
        30% { transform: translateY(-5px); }
        50% { transform: translateY(0); }
        70% { transform: translateY(-2px); }
    }

    @keyframes iconSlowSpin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    @keyframes iconSparkleBeat {
        0%, 100% { transform: scale(1) rotate(0deg); opacity: 1; }
        50% { transform: scale(1.18) rotate(12deg); opacity: 0.85; }
    }

    .icon-anim-float {
        animation: iconFloat 3.2s ease-in-out infinite;
        display: inline-block;
    }

    .icon-anim-pulse {
        animation: iconPulseGlow 2.5s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        display: inline-block;
    }

    .icon-anim-bounce {
        animation: iconBounceSoft 2.4s ease-in-out infinite;
        display: inline-block;
    }

    .icon-anim-spin {
        animation: iconSlowSpin 14s linear infinite;
        display: inline-block;
    }

    .icon-anim-sparkle {
        animation: iconSparkleBeat 2.2s ease-in-out infinite;
        display: inline-block;
    }

    .dash-card:hover .stat-icon-wrap i,
    .btn-dash-action:hover i,
    .insight-card:hover .insight-icon-wrap i {
        animation: iconBounceSoft 0.7s ease-in-out 1;
    }

    /* ── Dashboard Hero Header ── */
    .dash-hero-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 18px;
        margin-bottom: 28px;
    }

    .dash-greeting-title {
        font-family: 'Outfit', sans-serif;
        font-size: 1.9rem;
        font-weight: 800;
        color: var(--adm-text-main);
        letter-spacing: -0.02em;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .dash-greeting-subtitle {
        font-size: 0.95rem;
        color: var(--adm-muted);
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .live-clock-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 5px 14px;
        background: var(--adm-surface);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-pill);
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--adm-text-main);
        box-shadow: var(--adm-shadow-sm);
    }
    .pulse-green {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--adm-success);
        box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.25);
        animation: pulseLive 2s infinite;
    }
    @keyframes pulseLive {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.25); opacity: 0.7; }
    }

    .dash-action-buttons {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .btn-dash-action {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        border-radius: var(--adm-radius-md);
        font-family: inherit;
        font-size: 0.88rem;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        transition: all var(--adm-transition);
        border: 1px solid var(--adm-border);
        background: var(--adm-surface);
        color: var(--adm-text-body);
        box-shadow: var(--adm-shadow-sm);
    }
    .btn-dash-action:hover {
        transform: translateY(-2px);
        box-shadow: var(--adm-shadow-hover);
        color: var(--adm-brand);
        border-color: var(--adm-brand-border);
    }
    .btn-dash-action.primary {
        background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
        color: #ffffff;
        border: none;
        box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
    }
    .btn-dash-action.primary:hover {
        background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
        box-shadow: 0 8px 20px rgba(79, 70, 229, 0.4);
    }

    /* ── Action Required Insights Banners ── */
    .insights-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
        gap: 16px;
        margin-bottom: 28px;
        min-width: 0;
        max-width: 100%;
    }

    .insight-card {
        padding: 16px 20px;
        border-radius: var(--adm-radius-lg);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        transition: transform var(--adm-transition), box-shadow var(--adm-transition);
        box-shadow: var(--adm-shadow-sm);
    }
    .insight-card:hover {
        transform: translateY(-2px);
    }

    .insight-card.warning {
        background: var(--adm-warning-light);
        border: 1px solid rgba(217, 119, 6, 0.3);
        color: var(--adm-warning);
    }
    .insight-card.info {
        background: var(--adm-brand-light);
        border: 1px solid var(--adm-brand-border);
        color: var(--adm-brand);
    }

    .insight-left {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .insight-icon-wrap {
        width: 44px;
        height: 44px;
        border-radius: var(--adm-radius-md);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        background: var(--adm-surface);
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        flex-shrink: 0;
    }
    .insight-card.warning .insight-icon-wrap { color: var(--adm-warning); }
    .insight-card.info .insight-icon-wrap { color: var(--adm-brand); }

    .insight-text strong {
        display: block;
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--adm-text-main);
        margin-bottom: 2px;
    }
    .insight-text span {
        font-size: 0.82rem;
        opacity: 0.85;
    }

    .insight-btn {
        padding: 7px 14px;
        border-radius: var(--adm-radius-md);
        font-size: 0.82rem;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--adm-surface);
        color: var(--adm-text-main);
        border: 1px solid var(--adm-border);
        transition: all var(--adm-transition);
    }
    .insight-btn:hover {
        background: var(--adm-brand);
        color: #fff;
        border-color: var(--adm-brand);
    }

    /* ── Metric Cards Grid ── */
    .cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }

    .dash-card {
        background: var(--adm-surface-card);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-xl);
        padding: 24px;
        box-shadow: var(--adm-shadow-card);
        transition: transform var(--adm-transition), box-shadow var(--adm-transition), border-color var(--adm-transition);
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .dash-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--adm-shadow-hover);
        border-color: var(--adm-brand-border);
    }

    .dash-card {
        background: var(--adm-surface-card);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-lg);
        padding: 18px 20px;
        box-shadow: var(--adm-shadow-card);
        transition: transform var(--adm-transition), box-shadow var(--adm-transition), border-color var(--adm-transition);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .dash-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--adm-shadow-hover);
        border-color: var(--adm-brand-border);
    }

    .dash-card-top {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 10px;
    }

    .dash-card-icon {
        width: 44px;
        height: 44px;
        border-radius: var(--adm-radius-md);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        transition: transform var(--adm-transition);
        flex-shrink: 0;
    }
    .dash-card:hover .dash-card-icon {
        transform: scale(1.06);
    }

    .dash-card-icon--blue   { background: var(--adm-brand-light); color: var(--adm-brand); }
    .dash-card-icon--green  { background: var(--adm-success-light); color: var(--adm-success); }
    .dash-card-icon--orange { background: var(--adm-warning-light); color: var(--adm-warning); }
    .dash-card-icon--purple { background: rgba(139, 92, 246, 0.16); color: #8b5cf6; }

    .dash-card-title {
        font-size: 0.84rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--adm-muted);
        margin: 0;
    }

    .dash-card-value {
        font-family: 'Outfit', sans-serif;
        font-size: 2.1rem;
        font-weight: 800;
        color: var(--adm-text-main);
        line-height: 1;
        letter-spacing: -0.02em;
        margin: 0;
    }

    /* ── Analytics & Visual Charts Grid (Compact Layout) ── */
    .charts-grid-layout {
        display: grid;
        grid-template-columns: 1.85fr 1fr;
        gap: 16px;
        margin-bottom: 20px;
        min-width: 0;
        max-width: 100%;
    }
    .charts-grid-layout > * {
        min-width: 0;
        max-width: 100%;
    }

    .chart-panel-card {
        background: var(--adm-surface-card);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-lg);
        padding: 16px 20px;
        box-shadow: var(--adm-shadow-card);
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
        position: relative;
        min-width: 0;
        max-width: 100%;
        box-sizing: border-box;
    }
    .chart-canvas-container canvas {
        max-width: 100% !important;
    }

    .chart-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--adm-border-subtle);
    }

    .chart-panel-title {
        font-family: 'Outfit', sans-serif;
        font-size: 1.08rem;
        font-weight: 700;
        color: var(--adm-text-main);
        letter-spacing: -0.01em;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .chart-panel-title i {
        color: var(--adm-brand);
        font-size: 1.05rem;
    }

    .chart-panel-desc {
        font-size: 0.80rem;
        color: var(--adm-muted);
        margin-top: 2px;
    }

    .chart-canvas-container {
        position: relative;
        width: 100%;
        height: 200px;
    }

    /* ── Live Attendance Activity Table ── */
    .feed-panel-card {
        background: var(--adm-surface-card);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-xl);
        padding: 28px;
        box-shadow: var(--adm-shadow-card);
        margin-bottom: 32px;
    }

    .table-clean {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }
    .table-clean th {
        text-align: left;
        padding: 12px 16px;
        font-size: 0.76rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--adm-faint);
        border-bottom: 1px solid var(--adm-border);
        background: var(--adm-surface-subtle);
    }
    .table-clean th:first-child { border-top-left-radius: var(--adm-radius-md); }
    .table-clean th:last-child { border-top-right-radius: var(--adm-radius-md); text-align: right; }

    .table-clean td {
        padding: 14px 16px;
        border-bottom: 1px solid var(--adm-border-subtle);
        color: var(--adm-text-body);
        vertical-align: middle;
    }
    .table-clean tr:last-child td {
        border-bottom: none;
    }
    .table-clean tr:hover td {
        background: var(--adm-surface-subtle);
    }

    .emp-row-user {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .emp-row-avatar {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        object-fit: cover;
        background: var(--adm-surface-subtle);
        flex-shrink: 0;
    }
    .emp-row-initials {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        font-weight: 800;
        color: #fff;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        flex-shrink: 0;
    }
    .emp-row-name {
        font-weight: 700;
        color: var(--adm-text-main);
        display: block;
    }
    .emp-row-code {
        font-size: 0.75rem;
        color: var(--adm-faint);
    }

    /* Badges */
    .badge-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: var(--adm-radius-pill);
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: capitalize;
    }
    .badge-status.present   { background: var(--adm-success-light); color: var(--adm-success); }
    .badge-status.delayed   { background: var(--adm-warning-light); color: var(--adm-warning); }
    .badge-status.completed { background: rgba(59, 130, 246, 0.14); color: #2563eb; }
    .badge-status.absent    { background: var(--adm-danger-light); color: var(--adm-danger); }

    /* Empty state */
    .empty-state-box {
        text-align: center;
        padding: 40px 20px;
        color: var(--adm-faint);
    }
    .empty-state-box i {
        font-size: 2.5rem;
        color: var(--adm-faint);
        margin-bottom: 12px;
        opacity: 0.5;
    }

    /* ── Responsive ── */
    @media (max-width: 1024px) {
        .charts-grid-layout { grid-template-columns: 1fr; }
    }
    @media (max-width: 768px) {
        .dash-hero-header { flex-direction: column; align-items: flex-start; }
        .dash-action-buttons { width: 100%; }
        .btn-dash-action { flex: 1; justify-content: center; }
        .cards-grid { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
        .chart-panel-card { padding: 20px 16px; }
        .feed-panel-card { padding: 20px 16px; overflow-x: auto; }
        .insights-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 480px) {
        .dash-hero-header { padding: 16px 14px; }
        .insights-grid { grid-template-columns: 1fr; margin-bottom: 20px; }
        .insight-card { padding: 12px; flex-direction: column; align-items: flex-start; gap: 10px; }
        .insight-btn { width: 100%; justify-content: center; }
        .cards-grid { grid-template-columns: 1fr; gap: 12px; margin-bottom: 24px; }
        .chart-panel-card { padding: 16px 12px; }
        .chart-panel-header { flex-direction: column; align-items: flex-start; gap: 8px; margin-bottom: 16px; }
        .feed-panel-card { padding: 16px 12px; }
        .chart-canvas-container { max-width: 100%; overflow: hidden; width: 100%; min-width: 0; }
    }
</style>

<!-- ========================================
     HERO GREETING & TELEMETRY BAR
     ======================================== -->
<div class="dash-hero-header animate-up">
    <div>
        <h1 class="dash-greeting-title">
            <span><?php echo $greeting; ?>, <?php echo $adminName; ?></span>
            <span style="font-size: 1.4rem;">👋</span>
        </h1>
        <div class="dash-greeting-subtitle">
            <span>Enterprise workforce &amp; operations telemetry overview</span>
            <span style="color: var(--adm-faint);">&bull;</span>
            <div class="live-clock-pill">
                <span class="pulse-green"></span>
                <span id="liveClockDisplay"><?php echo date('l, M d, Y &bull; h:i:s A'); ?></span>
            </div>
        </div>
    </div>

    <!-- Quick Action Shortcut Buttons -->
    <div class="dash-action-buttons">
        <a href="employee" class="btn-dash-action primary" title="Add and manage employees">
            <i class="fa-solid fa-user-plus icon-anim-sparkle"></i>
            <span>Manage Workforce</span>
        </a>
        <a href="attendance" class="btn-dash-action" title="View live attendance log">
            <i class="fa-solid fa-calendar-check icon-anim-pulse"></i>
            <span>Attendance Log</span>
        </a>
        <a href="reports" class="btn-dash-action" title="Generate compliance report">
            <i class="fa-solid fa-file-export icon-anim-float"></i>
            <span>Export Reports</span>
        </a>
    </div>
</div>

<!-- ========================================
     SMART INSIGHTS / ACTION BANNERS
     ======================================== -->
<?php if ($pendingLeaves > 0 || $pendingAadhar > 0): ?>
    <div class="insights-grid animate-up stagger-1">
        <?php if ($pendingLeaves > 0): ?>
            <div class="insight-card warning">
                <div class="insight-left">
                    <div class="insight-icon-wrap">
                        <i class="fa-solid fa-calendar-xmark icon-anim-pulse"></i>
                    </div>
                    <div class="insight-text">
                        <strong><?php echo $pendingLeaves; ?> Leave Request<?php echo $pendingLeaves > 1 ? 's' : ''; ?> Pending</strong>
                        <span>Employees awaiting administrative approval.</span>
                    </div>
                </div>
                <a href="leave" class="insight-btn">
                    <span>Review Leaves</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($pendingAadhar > 0): ?>
            <div class="insight-card info">
                <div class="insight-left">
                    <div class="insight-icon-wrap">
                        <i class="fa-solid fa-id-card icon-anim-pulse"></i>
                    </div>
                    <div class="insight-text">
                        <strong><?php echo $pendingAadhar; ?> Aadhar Verification<?php echo $pendingAadhar > 1 ? 's' : ''; ?> Pending</strong>
                        <span>Identity KYC document verification required.</span>
                    </div>
                </div>
                <a href="employee" class="insight-btn">
                    <span>Verify KYC</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ========================================
     PRIMARY KPI STAT CARDS (Animated)
     ======================================== -->
<div class="cards-grid">
    
    <!-- 1. Total Workforce -->
    <div class="dash-card animate-up stagger-1" onclick="window.location.href='employee'" style="cursor: pointer;" title="View all employees">
        <div class="dash-card-top">
            <div class="dash-card-icon dash-card-icon--blue">
                <i class="fa-solid fa-users"></i>
            </div>
            <p class="dash-card-title">Total Workforce</p>
        </div>
        <p class="dash-card-value"><?php echo number_format($totalEmployees); ?></p>
    </div>

    <!-- 2. Present Today -->
    <div class="dash-card animate-up stagger-2" onclick="window.location.href='attendance'" style="cursor: pointer;" title="View present employees today">
        <div class="dash-card-top">
            <div class="dash-card-icon dash-card-icon--green">
                <i class="fa-solid fa-user-check"></i>
            </div>
            <p class="dash-card-title">Present Today</p>
        </div>
        <p class="dash-card-value"><?php echo number_format($presentToday); ?></p>
    </div>

    <!-- 3. Absent Today -->
    <div class="dash-card animate-up stagger-3" onclick="window.location.href='attendance'" style="cursor: pointer;" title="View absent employees">
        <div class="dash-card-top">
            <div class="dash-card-icon" style="background: var(--adm-danger-light); color: var(--adm-danger);">
                <i class="fa-solid fa-user-xmark"></i>
            </div>
            <p class="dash-card-title">Absent Today</p>
        </div>
        <p class="dash-card-value"><?php echo number_format($absentToday); ?></p>
    </div>

    <!-- 4. Late Today -->
    <div class="dash-card animate-up stagger-4" onclick="window.location.href='attendance'" style="cursor: pointer;" title="View delayed arrivals">
        <div class="dash-card-top">
            <div class="dash-card-icon dash-card-icon--orange">
                <i class="fa-solid fa-clock"></i>
            </div>
            <p class="dash-card-title">Late Today</p>
        </div>
        <p class="dash-card-value"><?php echo number_format($lateToday); ?></p>
    </div>

    <!-- 5. Early Leave Today -->
    <div class="dash-card animate-up stagger-5" onclick="window.location.href='attendance'" style="cursor: pointer;" title="View early departures">
        <div class="dash-card-top">
            <div class="dash-card-icon dash-card-icon--purple">
                <i class="fa-solid fa-person-walking-arrow-right"></i>
            </div>
            <p class="dash-card-title">Early Departures</p>
        </div>
        <p class="dash-card-value"><?php echo number_format($earlyLeaveToday); ?></p>
    </div>

    <!-- 6. Monthly Attendance Efficiency -->
    <div class="dash-card animate-up stagger-6" onclick="window.location.href='reports'" style="cursor: pointer;" title="View monthly attendance rate">
        <div class="dash-card-top">
            <div class="dash-card-icon" style="background: var(--adm-success-light); color: var(--adm-success);">
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <p class="dash-card-title">Monthly Efficiency</p>
        </div>
        <p class="dash-card-value"><?php echo $monthlyAttendanceRate; ?>%</p>
    </div>

</div>

<!-- ========================================
     ANALYTICS & VISUAL CHARTS (Dark/Light Adaptive)
     ======================================== -->
<div class="charts-grid-layout animate-up stagger-3">
    
    <!-- 7-Day Attendance Trend Chart -->
    <div class="chart-panel-card">
        <div class="chart-panel-header">
            <div>
                <h2 class="chart-panel-title">
                    <i class="fa-solid fa-chart-column"></i>
                    Attendance Telemetry (Last 7 Days)
                </h2>
                <p class="chart-panel-desc">Daily comparison of on-time attendance versus delayed arrivals.</p>
            </div>
            <span class="dash-card-chip" style="font-size: 0.75rem;">Real-time</span>
        </div>

        <div class="chart-canvas-container">
            <canvas id="attendanceChart"></canvas>
        </div>
    </div>

    <!-- Today's Attendance Composition Doughnut Chart -->
    <div class="chart-panel-card">
        <div class="chart-panel-header">
            <div>
                <h2 class="chart-panel-title">
                    <i class="fa-solid fa-chart-pie"></i>
                    Today's Composition
                </h2>
                <p class="chart-panel-desc">Present, absent, and delayed distribution.</p>
            </div>
        </div>

        <div class="chart-canvas-container">
            <canvas id="todayChart"></canvas>
        </div>
    </div>

</div>

<!-- ========================================
     RECENT LIVE ATTENDANCE ACTIVITY FEED
     ======================================== -->
<div class="feed-panel-card animate-up stagger-4">
    <div class="chart-panel-header">
        <div>
            <h2 class="chart-panel-title">
                <i class="fa-solid fa-clock-rotate-left"></i>
                Live Attendance &amp; Punch Activity
            </h2>
            <p class="chart-panel-desc">Recent punch telemetry across shifts and employee departments.</p>
        </div>
        <a href="attendance" class="btn-dash-action" style="padding: 6px 14px; font-size: 0.82rem;">
            <span>View All Attendance</span>
            <i class="fa-solid fa-arrow-right"></i>
        </a>
    </div>

    <?php if (!empty($recentAttendance)): ?>
        <div style="overflow-x: auto;">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Date</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentAttendance as $row): 
                        $empName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Employee';
                        $empInitials = strtoupper(substr($row['first_name'] ?? 'E', 0, 1) . substr($row['last_name'] ?? 'M', 0, 1));
                        $checkInDisplay = !empty($row['check_in']) ? date('h:i A', strtotime($row['check_in'])) : '—';
                        $checkOutDisplay = !empty($row['check_out']) ? date('h:i A', strtotime($row['check_out'])) : 'Active';
                        $attStatus = strtolower($row['attendance_status'] ?: ($row['status'] ?: 'present'));
                    ?>
                        <tr>
                            <td>
                                <div class="emp-row-user">
                                    <?php if (!empty($row['avatar'])): ?>
                                        <img src="/assets/uploads/avatars/<?php echo htmlspecialchars($row['avatar']); ?>" alt="" class="emp-row-avatar">
                                    <?php else: ?>
                                        <div class="emp-row-initials"><?php echo $empInitials; ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <span class="emp-row-name"><?php echo htmlspecialchars($empName); ?></span>
                                        <span class="emp-row-code"><?php echo htmlspecialchars($row['emp_code'] ?? 'EMP-' . $row['employee_id']); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['department'] ?: 'Operations'); ?></td>
                            <td><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                            <td>
                                <span style="font-weight: 600; color: var(--adm-text-main);">
                                    <i class="fa-solid fa-right-to-bracket" style="color: var(--adm-success); font-size: 0.8rem; margin-right: 4px;"></i>
                                    <?php echo $checkInDisplay; ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 600;">
                                    <?php if ($checkOutDisplay === 'Active'): ?>
                                        <span class="badge-status present" style="font-size: 0.75rem; padding: 2px 8px;">
                                            <span class="pulse-green" style="width: 6px; height: 6px;"></span> On Duty
                                        </span>
                                    <?php else: ?>
                                        <i class="fa-solid fa-right-from-bracket" style="color: var(--adm-faint); font-size: 0.8rem; margin-right: 4px;"></i>
                                        <?php echo $checkOutDisplay; ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <span class="badge-status <?php echo in_array($attStatus, ['present', 'completed', 'half_day', 'delayed']) ? $attStatus : 'present'; ?>">
                                    <i class="fa-solid <?php echo ($attStatus === 'delayed') ? 'fa-clock' : 'fa-circle-check'; ?>" style="font-size: 0.75rem;"></i>
                                    <?php echo ucfirst($attStatus); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state-box">
            <i class="fa-solid fa-calendar-xmark"></i>
            <h4 style="font-size: 1.1rem; color: var(--adm-text-main); margin-bottom: 4px;">No Attendance Records Recorded Yet</h4>
            <p style="font-size: 0.88rem; color: var(--adm-muted);">Employee punch-in records will stream here in real time as shifts begin.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Dynamic Theme-Adaptive Chart & Live Clock Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── Live Clock Ticker ──
    const clockEl = document.getElementById('liveClockDisplay');
    function updateLiveClock() {
        if (!clockEl) return;
        const now = new Date();
        const options = { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric', 
            hour: '2-digit', 
            minute: '2-digit', 
            second: '2-digit',
            hour12: true 
        };
        clockEl.innerHTML = now.toLocaleDateString('en-US', options);
    }
    setInterval(updateLiveClock, 1000);

    // ── Chart.js Theme Adaptive Helper ──
    const htmlRoot = document.documentElement;

    function getChartThemeColors() {
        const isDark = htmlRoot.getAttribute('data-theme') === 'dark';
        return {
            textColor: isDark ? '#94a3b8' : '#64748b',
            gridColor: isDark ? 'rgba(255, 255, 255, 0.07)' : 'rgba(0, 0, 0, 0.05)',
            barPresent: '#10b981',
            barLate: '#f59e0b',
            donutColors: ['#10b981', '#ef4444', '#f59e0b', '#8b5cf6']
        };
    }

    let colors = getChartThemeColors();

    // ── 1. Attendance Bar Chart (7 Days) ──
    const ctxAtt = document.getElementById('attendanceChart')?.getContext('2d');
    let attendanceChart = null;
    if (ctxAtt) {
        attendanceChart = new Chart(ctxAtt, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartDates); ?>,
                datasets: [
                    {
                        label: 'Present (On-time)',
                        data: <?php echo json_encode($chartPresent); ?>,
                        backgroundColor: colors.barPresent,
                        borderRadius: 6,
                        barPercentage: 0.6,
                        categoryPercentage: 0.7
                    },
                    {
                        label: 'Late / Delayed',
                        data: <?php echo json_encode($chartLate); ?>,
                        backgroundColor: colors.barLate,
                        borderRadius: 6,
                        barPercentage: 0.6,
                        categoryPercentage: 0.7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1000,
                    easing: 'easeOutQuart'
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: colors.textColor,
                            font: { family: "'Plus Jakarta Sans', sans-serif", weight: '600', size: 12 },
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 18
                        }
                    },
                    tooltip: {
                        padding: 12,
                        cornerRadius: 8,
                        titleFont: { family: "'Plus Jakarta Sans', sans-serif", weight: '700' },
                        bodyFont: { family: "'Plus Jakarta Sans', sans-serif" }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: colors.textColor, font: { weight: '600', size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: colors.gridColor },
                        ticks: { 
                            stepSize: 1, 
                            color: colors.textColor,
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    }

    // ── 2. Today's Doughnut Chart ──
    const ctxToday = document.getElementById('todayChart')?.getContext('2d');
    let todayChart = null;
    if (ctxToday) {
        const rawTodayData = [
            <?php echo $presentToday; ?>, 
            <?php echo $absentToday; ?>, 
            <?php echo $lateToday; ?>, 
            <?php echo $earlyLeaveToday; ?>
        ];
        const sumToday = rawTodayData.reduce((a, b) => a + b, 0);
        const hasData = sumToday > 0;

        todayChart = new Chart(ctxToday, {
            type: 'doughnut',
            data: {
                labels: ['Present', 'Absent', 'Late', 'Early Exit'],
                datasets: [{
                    data: hasData ? rawTodayData : [1],
                    backgroundColor: hasData ? colors.donutColors : [colors.gridColor || 'rgba(148, 163, 184, 0.25)'],
                    borderWidth: 0,
                    hoverOffset: hasData ? 5 : 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                animation: {
                    animateScale: true,
                    animateRotate: true,
                    duration: 1000
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: colors.textColor,
                            font: { family: "'Plus Jakarta Sans', sans-serif", weight: '600', size: 11 },
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 10,
                            boxWidth: 8
                        }
                    },
                    tooltip: {
                        enabled: hasData,
                        padding: 10,
                        cornerRadius: 8,
                        titleFont: { family: "'Plus Jakarta Sans', sans-serif", weight: '700' }
                    }
                }
            }
        });
    }

    // ── Theme Switch Observer for Real-Time Chart Color Sync ──
    const themeBtn = document.getElementById('adminThemeToggle');
    themeBtn?.addEventListener('click', () => {
        setTimeout(() => {
            const newColors = getChartThemeColors();
            if (attendanceChart) {
                attendanceChart.options.scales.x.ticks.color = newColors.textColor;
                attendanceChart.options.scales.y.ticks.color = newColors.textColor;
                attendanceChart.options.scales.y.grid.color  = newColors.gridColor;
                attendanceChart.options.plugins.legend.labels.color = newColors.textColor;
                attendanceChart.update();
            }
            if (todayChart) {
                todayChart.options.plugins.legend.labels.color = newColors.textColor;
                const curSum = [
                    <?php echo $presentToday; ?>, 
                    <?php echo $absentToday; ?>, 
                    <?php echo $lateToday; ?>, 
                    <?php echo $earlyLeaveToday; ?>
                ].reduce((a, b) => a + b, 0);
                if (curSum > 0) {
                    todayChart.data.datasets[0].backgroundColor = newColors.donutColors;
                } else {
                    todayChart.data.datasets[0].backgroundColor = [newColors.gridColor || 'rgba(148, 163, 184, 0.25)'];
                }
                todayChart.update();
            }
        }, 50);
    });
});
</script>

<?php include 'footer.php'; ?>
