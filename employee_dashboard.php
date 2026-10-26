<?php
// Set timezone to India / Local Time
date_default_timezone_set('Asia/Kolkata');

// Include database connection
require_once 'app/db.php';

// Initialize security utilities and helpers
require_once 'app/security.php';
require_once 'app/AttendanceHelper.php';
Security::init($pdo);
Security::initSecureSession();

// Check if employee is logged in
if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    header("Location: login");
    exit();
}

// Check session timeout
if (!Security::setSessionTimeout()) {
    header("Location: login?msg=timeout");
    exit();
}

$employee_id = $_SESSION['employee_id'];
$todayDate = date('Y-m-d');

// Fetch site favicon
$siteFavicon = null;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->query("SELECT favicon FROM site_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['favicon'])) {
            $siteFavicon = 'assets/uploads/site/' . $row['favicon'];
        }
    } catch (PDOException $e) {}
}
if (!$siteFavicon && file_exists(__DIR__ . '/assets/uploads/site/favicon-b9f474fa06bc608e0ac6586c.png')) {
    $siteFavicon = 'assets/uploads/site/favicon-b9f474fa06bc608e0ac6586c.png';
}

// Handle first-login motivation popup dismissal
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['dismiss_motivation'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (Security::validateCSRFToken($token, 'motivation_dismiss')) {
        try {
            $pdo->prepare("UPDATE employees SET first_login_completed = 1 WHERE id = ?")->execute([$employee_id]);
        } catch (PDOException $e) {
            error_log("Failed to update first_login_completed: " . $e->getMessage());
        }
    }
    // Redirect to prevent re-submission
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit();
}

// Check if this is the employee's first login
$isFirstLogin = false;
$motivationQuote = '';
$motivationAuthor = '';
$firstName = $_SESSION['employee_name'] ?? 'Team Member';
// Extract just the first name for friendlier greeting
$firstName = trim(explode(' ', $firstName)[0] ?? 'Team Member');
try {
    $stmt = $pdo->prepare("SELECT first_login_completed, first_name FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    $empMeta = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($empMeta && empty($empMeta['first_login_completed'])) {
        $isFirstLogin = true;
        if (!empty($empMeta['first_name'])) {
            $firstName = $empMeta['first_name'];
        }
    }
} catch (PDOException $e) {
    $isFirstLogin = false;
}

// Motivation quotes pool (curated for first-time portal login)
if ($isFirstLogin) {
    $motivations = [
        ['quote' => "Welcome to the team, {$firstName}! This is your space to shine. Every task you complete brings us closer to something great.", 'author' => 'Cloud Aspire'],
        ['quote' => "Great things start with a single login. You are now part of a team that builds, grows, and wins together. Let's make it happen!", 'author' => 'Cloud Aspire'],
        ['quote' => "The best time to start is now. Your dashboard is your command center — track tasks, manage projects, and keep winning. Welcome aboard!", 'author' => 'Cloud Aspire'],
        ['quote' => "Every champion was once a newcomer. Your journey with Cloud Aspire starts right here. Stay focused, stay consistent, stay unstoppable.", 'author' => 'Cloud Aspire'],
        ['quote' => "Productivity is not about being busy. It is about being effective. This portal is your shortcut to clarity and impact. Welcome!", 'author' => 'Cloud Aspire'],
        ['quote' => "You have the tools. You have the talent. Now go deliver what only you can. Welcome to your workspace, {$firstName}!", 'author' => 'Cloud Aspire'],
    ];
    $selectedMotivation = $motivations[array_rand($motivations)];
    $motivationQuote = $selectedMotivation['quote'];
    $motivationAuthor = $selectedMotivation['author'];
}

// Fetch employee profile details
try {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        header("Location: employee_logout");
        exit();
    }

    // Determine proper Employee Code (e.g. EMP-001)
    $empCode = !empty($employee['employee_id']) 
        ? trim($employee['employee_id']) 
        : ('EMP-' . str_pad($employee['id'], 3, '0', STR_PAD_LEFT));

    // Synchronize to session for cross-portal access
    $_SESSION['employee_code'] = $empCode;
} catch (PDOException $e) {
    error_log("Error fetching employee: " . $e->getMessage());
    $employee = null;
    $empCode = 'EMP-' . str_pad($employee_id, 3, '0', STR_PAD_LEFT);
}

// Fetch Tasks Statistics
$totalTasks = 0;
$pendingTasks = 0;
$completedTasks = 0;
$recentTasks = [];
$tasksExist = false;

try {
    $tasksExist = $pdo->query("SHOW TABLES LIKE 'tasks'")->rowCount() > 0;
    
    if ($tasksExist) {
        // Total Tasks
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        $totalTasks = (int)$stmt->fetchColumn();

        // Pending / Active Tasks
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE employee_id = ? AND status IN ('sent', 'in_progress', 'verify')");
        $stmt->execute([$employee_id]);
        $pendingTasks = (int)$stmt->fetchColumn();

        // Completed Tasks
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE employee_id = ? AND status = 'completed'");
        $stmt->execute([$employee_id]);
        $completedTasks = (int)$stmt->fetchColumn();

        // Recent Tasks (up to 5)
        $stmt = $pdo->prepare("SELECT id, title, priority, status, due_date FROM tasks WHERE employee_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$employee_id]);
        $recentTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching tasks: " . $e->getMessage());
}

// Fetch Projects Statistics
$totalProjects = 0;
try {
    $projectsExist = $pdo->query("SHOW TABLES LIKE 'projects'")->rowCount() > 0;
    if ($projectsExist) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        $totalProjects = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("Error fetching projects: " . $e->getMessage());
}

// Fetch Today's Attendance Record
$todayAttendance = null;
try {
    $attendanceExist = $pdo->query("SHOW TABLES LIKE 'attendance'")->rowCount() > 0;
    if ($attendanceExist) {
        $stmt = $pdo->prepare("SELECT check_in, check_out, status, attendance_status FROM attendance WHERE employee_id = ? AND date = ?");
        $stmt->execute([$employee_id, $todayDate]);
        $todayAttendance = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching today's attendance: " . $e->getMessage());
}

// Evaluate automatic Attendance Portal closure status
$portalClosure = null;
try {
    $portalClosure = AttendanceHelper::evaluatePortalClosureStatus($pdo, $employee_id);
} catch (Exception $e) {
    error_log("Portal closure check failed: " . $e->getMessage());
}

// Calculate Task Completion Rate
$completionRate = ($totalTasks > 0) ? round(($completedTasks / $totalTasks) * 100) : 0;

// Dynamic Greeting based on time of day
$currentHour = (int)date('H');
if ($currentHour < 12) {
    $timeGreeting = "Good morning";
} elseif ($currentHour < 17) {
    $timeGreeting = "Good afternoon";
} else {
    $timeGreeting = "Good evening";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Employee Dashboard &bull; <?php echo htmlspecialchars($employee['first_name']); ?> | Cloud Aspire</title>
    <meta name="description" content="Cloud Aspire Centralized Employee Dashboard and Workspace Hub.">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($siteFavicon); ?>">

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme.css">

    <!-- Immediate Theme Initialization (Eliminates FOUC) -->
    <script>
        (function() {
            try {
                const savedTheme = localStorage.getItem('theme');
                if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>

    <style>
        :root {
            --db-bg:            #f8fafc;
            --db-surface:       #ffffff;
            --db-surface-hover: #f1f5f9;
            --db-text:          #09090b;
            --db-text-body:     #1e293b;
            --db-muted:         #475569;
            --db-faint:         #64748b;
            --db-border:        #cbd5e1;
            --db-border-subtle: #e2e8f0;

            --db-brand:         #4f46e5;
            --db-brand-light:   #eef2ff;
            --db-brand-border:  #c7d2fe;
            --db-emerald:       #059669;
            --db-emerald-light: #ecfdf5;
            --db-amber:         #d97706;
            --db-amber-light:   #fffbeb;
            --db-rose:          #e11d48;
            --db-rose-light:    #fff1f2;

            --db-radius-sm:     8px;
            --db-radius-md:     14px;
            --db-radius-lg:     18px;
            --db-radius-card:   20px;
            --db-radius-pill:   9999px;

            --db-shadow-sm:     0 1px 3px 0 rgba(0, 0, 0, 0.05);
            --db-shadow-card:   0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 6px 16px -2px rgba(15, 23, 42, 0.05);
            --db-shadow-hover:  0 12px 28px -6px rgba(15, 23, 42, 0.12);

            --db-transition:    0.25s cubic-bezier(0.16, 1, 0.3, 1);
            --db-max-w:         1440px;
        }

        html[data-theme="dark"] {
            --db-bg:            #090d16;
            --db-surface:       #131d33;
            --db-surface-hover: #18233d;
            --db-text:          #f8fafc;
            --db-text-body:     #e2e8f0;
            --db-muted:         #94a3b8;
            --db-faint:         #64748b;
            --db-border:        #2d3f66;
            --db-border-subtle: #1e2a44;

            --db-brand-light:   rgba(79, 70, 229, 0.2);
            --db-brand-border:  rgba(99, 102, 241, 0.35);
            --db-emerald-light: rgba(5, 150, 105, 0.2);
            --db-amber-light:   rgba(217, 119, 6, 0.2);
            --db-rose-light:    rgba(225, 29, 72, 0.2);

            --db-shadow-card:   0 4px 20px -2px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05);
            --db-shadow-hover:  0 16px 36px -6px rgba(0, 0, 0, 0.65);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--db-bg);
            color: var(--db-text-body);
            line-height: 1.6;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

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

        @keyframes iconWiggle {
            0%, 100% { transform: rotate(0deg); }
            25% { transform: rotate(-8deg); }
            75% { transform: rotate(8deg); }
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

        .icon-anim-wiggle {
            animation: iconWiggle 2.2s ease-in-out infinite;
            display: inline-block;
        }

        .icon-anim-sparkle {
            animation: iconSparkleBeat 2.2s ease-in-out infinite;
            display: inline-block;
        }

        .metric-card:hover .metric-icon-box i,
        .action-btn:hover i,
        .btn-manage-account:hover i {
            animation: iconBounceSoft 0.7s ease-in-out 1;
        }

        /* ── Container Layout ── */
        .dash-container {
            width: 100%;
            max-width: var(--db-max-w);
            margin: 0 auto;
            padding: 28px 24px 60px;
        }

        /* ── Welcome Banner ── */
        .welcome-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--db-border);
        }

        .welcome-title-wrap h1 {
            font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif;
            font-size: clamp(1.6rem, 3.2vw, 2.15rem);
            font-weight: 800;
            color: var(--db-text);
            letter-spacing: -0.025em;
            margin-bottom: 6px;
        }

        .welcome-subtitle {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.94rem;
            color: var(--db-muted);
            flex-wrap: wrap;
        }

        .live-date-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 12px;
            border-radius: var(--db-radius-pill);
            background: var(--db-surface);
            border: 1px solid var(--db-border);
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--db-text-body);
        }
        .live-date-pill i {
            color: var(--db-brand);
        }

        /* Quick Action Buttons */
        .welcome-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: var(--db-radius-pill);
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all var(--db-transition);
            white-space: nowrap;
        }
        .action-btn-primary {
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .action-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.38);
        }
        .action-btn-outline {
            background: var(--db-surface);
            border-color: var(--db-border);
            color: var(--db-text);
        }
        .action-btn-outline:hover {
            border-color: var(--db-brand);
            color: var(--db-brand);
            background: var(--db-brand-light);
            transform: translateY(-2px);
        }

        /* ── Main Dashboard Layout Grid ── */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 310px 1fr;
            gap: 28px;
            align-items: start;
        }

        /* ── Left Sidebar: Profile Widget ── */
        .profile-widget-card {
            background: var(--db-surface);
            border: 1px solid var(--db-border);
            border-radius: var(--db-radius-card);
            box-shadow: var(--db-shadow-card);
            padding: 28px 24px;
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: transform var(--db-transition), box-shadow var(--db-transition);
        }
        .profile-widget-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
        }

        .avatar-wrap {
            position: relative;
            display: inline-block;
            margin-bottom: 16px;
        }
        .profile-avatar-lg {
            width: 86px;
            height: 86px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #ffffff;
            font-size: 2rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 22px rgba(79, 70, 229, 0.3);
            border: 4px solid var(--db-surface);
        }
        .status-dot-badge {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--db-emerald);
            border: 3px solid var(--db-surface);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        }

        .profile-emp-name {
            font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif;
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--db-text);
            margin-bottom: 4px;
        }
        .profile-emp-role {
            font-size: 0.92rem;
            color: var(--db-muted);
            font-weight: 600;
            margin-bottom: 14px;
        }
        .department-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: var(--db-brand-light);
            border: 1px solid var(--db-brand-border);
            border-radius: var(--db-radius-pill);
            color: var(--db-brand);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 24px;
        }

        /* ── Modern Employee Code Badge & Pill ── */
        .emp-code-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1), rgba(99, 102, 241, 0.05));
            border: 1px solid var(--db-brand-border);
            border-radius: var(--db-radius-pill);
            color: var(--db-brand);
            font-size: 0.8rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            font-family: 'Outfit', 'Plus Jakarta Sans', monospace, sans-serif;
            box-shadow: 0 1px 3px rgba(79, 70, 229, 0.1);
            transition: all var(--db-transition);
        }
        .emp-code-pill:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(79, 70, 229, 0.2);
        }
        .emp-code-pill i {
            color: var(--db-brand);
            font-size: 0.82rem;
        }
        html[data-theme="dark"] .emp-code-pill {
            background: rgba(79, 70, 229, 0.2);
            border-color: rgba(99, 102, 241, 0.35);
            color: #a5b4fc;
        }
        html[data-theme="dark"] .emp-code-pill i {
            color: #a5b4fc;
        }

        .emp-code-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            background: var(--db-brand-light);
            border: 1px solid var(--db-brand-border);
            border-radius: var(--db-radius-sm);
            color: var(--db-brand);
            font-size: 0.86rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            font-family: 'Outfit', 'Plus Jakarta Sans', monospace, sans-serif;
            box-shadow: var(--db-shadow-sm);
        }
        html[data-theme="dark"] .emp-code-badge {
            background: rgba(79, 70, 229, 0.22);
            border-color: rgba(99, 102, 241, 0.35);
            color: #a5b4fc;
        }

        /* Profile Details Table */
        .profile-detail-list {
            text-align: left;
            border-top: 1px solid var(--db-border);
            padding-top: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .detail-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.88rem;
            gap: 12px;
        }
        .detail-key {
            color: var(--db-muted);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .detail-key i {
            color: var(--db-brand);
            width: 16px;
            text-align: center;
        }
        .detail-val {
            color: var(--db-text);
            font-weight: 700;
            text-align: right;
            word-break: break-all;
        }

        .btn-manage-account {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 10px;
            margin-top: 24px;
            border-radius: var(--db-radius-md);
            background: var(--db-surface-hover);
            border: 1px solid var(--db-border);
            color: var(--db-text);
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
            transition: all var(--db-transition);
        }
        .btn-manage-account:hover {
            border-color: var(--db-brand);
            color: var(--db-brand);
            background: var(--db-brand-light);
        }

        /* ── Right Content Area ── */
        .content-area {
            display: flex;
            flex-direction: column;
            gap: 28px;
        }

        /* Metrics Row (4 Cards) */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
        }

        .metric-card {
            background: var(--db-surface);
            border: 1px solid var(--db-border);
            border-radius: var(--db-radius-lg);
            box-shadow: var(--db-shadow-card);
            padding: 20px 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            transition: all var(--db-transition);
        }
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--db-shadow-hover);
            border-color: var(--db-brand);
        }

        .metric-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .metric-label {
            font-size: 0.86rem;
            font-weight: 700;
            color: var(--db-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .metric-icon-box {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }
        .icon-box-indigo { background: var(--db-brand-light); color: var(--db-brand); }
        .icon-box-amber { background: var(--db-amber-light); color: var(--db-amber); }
        .icon-box-emerald { background: var(--db-emerald-light); color: var(--db-emerald); }
        .icon-box-rose { background: var(--db-rose-light); color: var(--db-rose); }

        .metric-value {
            font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif;
            font-size: 2.1rem;
            font-weight: 800;
            color: var(--db-text);
            line-height: 1.1;
            margin-bottom: 6px;
        }
        .metric-caption {
            font-size: 0.8rem;
            color: var(--db-muted);
            font-weight: 600;
        }

        /* ── Recent Tasks Section Card ── */
        .section-box {
            background: var(--db-surface);
            border: 1px solid var(--db-border);
            border-radius: var(--db-radius-card);
            box-shadow: var(--db-shadow-card);
            overflow: hidden;
        }

        .section-box-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--db-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .section-box-title {
            font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif;
            font-size: 1.22rem;
            font-weight: 800;
            color: var(--db-text);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-box-title i {
            color: var(--db-brand);
            font-size: 1.1rem;
        }

        .section-header-link {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--db-brand);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color var(--db-transition);
        }
        .section-header-link:hover {
            text-decoration: underline;
        }

        /* Desktop Table View */
        .tasks-table-container {
            width: 100%;
            overflow-x: auto;
        }

        .tasks-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .tasks-table th {
            padding: 14px 24px;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--db-muted);
            background: var(--db-bg);
            border-bottom: 1px solid var(--db-border);
            white-space: nowrap;
        }

        .tasks-table td {
            padding: 16px 24px;
            font-size: 0.92rem;
            border-bottom: 1px solid var(--db-border-subtle);
            color: var(--db-text);
            vertical-align: middle;
        }

        .tasks-table tr:last-child td {
            border-bottom: none;
        }

        .tasks-table tbody tr {
            transition: background-color var(--db-transition);
        }
        .tasks-table tbody tr:hover {
            background-color: var(--db-surface-hover);
        }

        .task-title-cell {
            font-weight: 700;
            color: var(--db-text);
            max-width: 320px;
        }

        /* Badges & Pills */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: var(--db-radius-pill);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .badge-priority-high {
            background: var(--db-rose-light);
            color: var(--db-rose);
            border: 1px solid rgba(225, 29, 72, 0.3);
        }
        .badge-priority-medium {
            background: var(--db-amber-light);
            color: var(--db-amber);
            border: 1px solid rgba(217, 119, 6, 0.3);
        }
        .badge-priority-low {
            background: var(--db-emerald-light);
            color: var(--db-emerald);
            border: 1px solid rgba(5, 150, 105, 0.3);
        }

        .badge-status-in_progress, .badge-status-pending {
            background: var(--db-brand-light);
            color: var(--db-brand);
            border: 1px solid var(--db-brand-border);
        }
        .badge-status-completed {
            background: var(--db-emerald-light);
            color: var(--db-emerald);
            border: 1px solid rgba(5, 150, 105, 0.3);
        }
        .badge-status-verify {
            background: var(--db-amber-light);
            color: var(--db-amber);
            border: 1px solid rgba(217, 119, 6, 0.3);
        }

        /* Mobile Task Card View (< 768px) */
        .mobile-tasks-cards {
            display: none;
            padding: 16px;
            flex-direction: column;
            gap: 12px;
        }

        .mobile-task-card {
            background: var(--db-bg);
            border: 1px solid var(--db-border);
            border-radius: var(--db-radius-md);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .mobile-task-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .mobile-task-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--db-text);
            line-height: 1.4;
        }
        .mobile-task-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 10px;
            border-top: 1px solid var(--db-border-subtle);
            font-size: 0.82rem;
            color: var(--db-muted);
        }

        /* Empty State */
        .empty-tasks-state {
            padding: 48px 24px;
            text-align: center;
            color: var(--db-muted);
        }
        .empty-tasks-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--db-brand-light);
            color: var(--db-brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin: 0 auto 16px;
        }
        .empty-tasks-state h3 {
            font-size: 1.1rem;
            color: var(--db-text);
            margin-bottom: 6px;
            font-weight: 700;
        }

        /* ── Attendance Quick Banner ── */
        .attendance-banner {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.08), rgba(99, 102, 241, 0.04));
            border: 1.5px dashed var(--db-brand-border);
            border-radius: var(--db-radius-card);
            padding: 22px 26px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .attendance-info-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .attendance-icon-badge {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: var(--db-brand);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }
        .attendance-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--db-text);
            margin-bottom: 2px;
        }
        .attendance-sub {
            font-size: 0.88rem;
            color: var(--db-muted);
        }

        /* ── RESPONSIVE BREAKPOINTS ── */
        @media (max-width: 1080px) {
            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 900px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            .profile-widget-card {
                text-align: left;
                display: flex;
                flex-direction: column;
            }
            .avatar-wrap {
                margin: 0 0 16px 0;
            }
            .profile-detail-list {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
        }

        @media (max-width: 680px) {
            .dash-container {
                padding: 16px 12px 48px;
            }
            .welcome-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
                margin-bottom: 22px;
            }
            .welcome-actions {
                width: 100%;
            }
            .action-btn {
                flex: 1;
                justify-content: center;
            }
            .metrics-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .metric-card {
                padding: 16px;
            }
            .metric-value {
                font-size: 1.7rem;
            }
            .profile-detail-list {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .tasks-table-container {
                display: none;
            }
            .mobile-tasks-cards {
                display: flex;
            }
            .attendance-banner {
                flex-direction: column;
                align-items: flex-start;
            }
            .attendance-banner .action-btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .dash-container {
                padding: 16px 12px 48px;
            }
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            .profile-widget-card {
                padding: 20px 16px;
            }
        }

        /* ── First Login Motivation Popup ── */
        .motivation-overlay {
            position: fixed;
            inset: 0;
            z-index: 10000;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            animation: motFadeIn 0.4s ease;
        }
        @keyframes motFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .motivation-popup {
            background: var(--db-surface);
            border: 1px solid var(--db-border);
            border-radius: var(--db-radius-card);
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            max-width: 520px;
            width: 100%;
            padding: 48px 36px 32px;
            text-align: center;
            position: relative;
            animation: motSlideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }
        @keyframes motSlideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .motivation-popup::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #4f46e5, #7c3aed, #06b6d4);
        }
        .motivation-emoji {
            font-size: 3.5rem;
            margin-bottom: 16px;
            animation: motBounce 1s ease-in-out;
        }
        @keyframes motBounce {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            70% { transform: scale(0.9); }
            100% { transform: scale(1); }
        }
        .motivation-popup h2 {
            font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--db-text);
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }
        .motivation-popup .motivation-subtitle {
            font-size: 0.9rem;
            color: var(--db-muted);
            font-weight: 600;
            margin-bottom: 20px;
        }
        .motivation-popup blockquote {
            font-size: 1.05rem;
            color: var(--db-text-body);
            line-height: 1.7;
            font-style: italic;
            margin: 0 0 8px;
            padding: 20px 24px;
            background: var(--db-bg);
            border-radius: var(--db-radius-lg);
            border: 1px solid var(--db-border);
            position: relative;
            text-indent: 2rem;
        }
        .motivation-popup blockquote::before {
            content: '\201C';
            font-size: 3rem;
            color: var(--db-brand);
            opacity: 0.25;
            position: absolute;
            top: -4px;
            left: 12px;
            font-style: normal;
            line-height: 1;
        }
        .motivation-popup .motivation-author {
            font-size: 0.85rem;
            color: var(--db-muted);
            font-weight: 600;
            margin-bottom: 24px;
        }
        .motivation-popup .btn {
            width: 100%;
            height: 48px;
            border-radius: var(--db-radius-pill);
            font-size: 0.95rem;
        }
        .motivation-popup .dismiss-form {
            margin-top: 0;
        }
        html[data-theme="dark"] .motivation-overlay {
            background: rgba(0, 0, 0, 0.7);
        }
    </style>
</head>
<body>

    <?php if ($isFirstLogin):
        $motivationToken = Security::generateCSRFToken('motivation_dismiss');
    ?>
    <!-- First Login Motivation Popup -->
    <div class="motivation-overlay" id="motivationOverlay" role="dialog" aria-labelledby="motivationTitle" aria-modal="true">
        <div class="motivation-popup">
            <div class="motivation-emoji" aria-hidden="true">&#127881;</div>
            <h2 id="motivationTitle">Welcome aboard, <?php echo htmlspecialchars($firstName ?? 'Team Member'); ?>!</h2>
            <p class="motivation-subtitle">Your Cloud Aspire workspace is ready.</p>
            <blockquote>
                <?php echo htmlspecialchars($motivationQuote); ?>
            </blockquote>
            <p class="motivation-author">&mdash; <?php echo htmlspecialchars($motivationAuthor); ?></p>
            <form method="POST" class="dismiss-form" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($motivationToken); ?>">
                <input type="hidden" name="dismiss_motivation" value="1">
                <button type="submit" class="btn action-btn-primary" id="motivationDismissBtn">
                    <i class="fas fa-rocket"></i> Let's Get Started
                </button>
            </form>
        </div>
    </div>
    <script>
        (function() {
            const overlay = document.getElementById('motivationOverlay');
            const btn = document.getElementById('motivationDismissBtn');
            if (overlay && btn) {
                document.body.style.overflow = 'hidden';
                btn.addEventListener('click', function() {
                    setTimeout(function() {
                        document.body.style.overflow = '';
                    }, 500);
                });
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        // Click-outside should not dismiss; user must click the button to commit first-login state
                    }
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') e.preventDefault();
                });
            }
        })();
    </script>
    <?php endif; ?>

    <!-- Shared Responsive Navbar Header -->
    <?php include 'employee_header.php'; ?>

    <main class="dash-container">
        
        <!-- Welcome & Status Header -->
        <section class="welcome-header">
            <div class="welcome-title-wrap">
                <h1><?php echo htmlspecialchars($timeGreeting); ?>, <?php echo htmlspecialchars($employee['first_name']); ?>!</h1>
                <div class="welcome-subtitle">
                    <span>Here is your workforce activity &amp; project overview.</span>
                    <span class="emp-code-pill" title="Official Employee Code">
                        <i class="fas fa-id-badge"></i>
                        <span><?php echo htmlspecialchars($empCode); ?></span>
                    </span>
                    <span class="live-date-pill">
                        <i class="fas fa-calendar-day"></i>
                        <span><?php echo date('l, F j, Y'); ?></span>
                    </span>
                </div>
            </div>

            <!-- Quick Action Buttons -->
            <div class="welcome-actions">
                <a href="employee_attendance" class="action-btn action-btn-primary" title="Record Attendance">
                    <i class="fas fa-clock icon-anim-spin"></i> Attendance
                </a>
                <a href="employee_task" class="action-btn action-btn-outline" title="View Assigned Tasks">
                    <i class="fas fa-list-check icon-anim-bounce"></i> Tasks (<?php echo $pendingTasks; ?>)
                </a>
                <a href="employee_project" class="action-btn action-btn-outline" title="Submit Project">
                    <i class="fas fa-plus icon-anim-sparkle"></i> New Project
                </a>
                <a href="employee_leave" class="action-btn action-btn-outline" title="Request Leave">
                    <i class="fas fa-plane-departure icon-anim-float"></i> Request Leave
                </a>
            </div>
        </section>

        <!-- Main Dashboard Grid Layout -->
        <div class="dashboard-grid">
            
            <!-- Left Column: Employee Identity Card -->
            <aside class="profile-widget-card" aria-label="Employee profile details">
                <div class="avatar-wrap">
                    <div class="profile-avatar-lg">
                        <?php if (!empty($employee['avatar']) && file_exists(__DIR__ . '/assets/uploads/avatars/' . $employee['avatar'])): ?>
                            <img src="assets/uploads/avatars/<?php echo htmlspecialchars($employee['avatar']); ?>" alt="Profile" style="width:100%; height:100%; border-radius:50%; object-fit:cover;">
                        <?php else: ?>
                            <?php 
                                $fn = substr($employee['first_name'] ?? 'E', 0, 1);
                                $ln = substr($employee['last_name'] ?? '', 0, 1);
                                echo htmlspecialchars(strtoupper($fn . $ln));
                            ?>
                        <?php endif; ?>
                    </div>
                    <span class="status-dot-badge" title="Active Account"></span>
                </div>

                <h2 class="profile-emp-name"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h2>
                <p class="profile-emp-role"><?php echo htmlspecialchars($employee['position'] ?: 'Team Member'); ?></p>
                
                <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-bottom: 22px;">
                    <span class="emp-code-pill" title="Employee Code">
                        <i class="fas fa-id-badge"></i>
                        <span><?php echo htmlspecialchars($empCode); ?></span>
                    </span>
                    <span class="department-pill" style="margin-bottom: 0;">
                        <i class="fas fa-briefcase icon-anim-float"></i>
                        <span><?php echo htmlspecialchars($employee['department'] ?: 'Operations'); ?></span>
                    </span>
                </div>

                <!-- Profile Metadata List -->
                <div class="profile-detail-list">
                    <div class="detail-row">
                        <span class="detail-key"><i class="fas fa-id-card"></i> Employee Code</span>
                        <span class="detail-val">
                            <span class="emp-code-badge" title="Official Employee Code"><?php echo htmlspecialchars($empCode); ?></span>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-key"><i class="fas fa-envelope"></i> Email</span>
                        <span class="detail-val"><?php echo htmlspecialchars($employee['email']); ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-key"><i class="fas fa-phone"></i> Phone</span>
                        <span class="detail-val"><?php echo htmlspecialchars($employee['phone'] ?: 'Not Provided'); ?></span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-key"><i class="fas fa-calendar"></i> Joining Date</span>
                        <span class="detail-val">
                            <?php echo $employee['hire_date'] ? date('M j, Y', strtotime($employee['hire_date'])) : 'Active'; ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-key"><i class="fas fa-circle-check"></i> Status</span>
                        <span class="detail-val" style="color: var(--db-emerald); text-transform: capitalize;">
                            <?php echo htmlspecialchars($employee['status'] ?: 'Active'); ?>
                        </span>
                    </div>
                </div>

                <a href="employee_account" class="btn-manage-account">
                    <i class="fas fa-gear icon-anim-spin"></i> Manage Account &amp; Security
                </a>
            </aside>

            <!-- Right Column: Operational Stats & Activities -->
            <div class="content-area">
                
                <!-- 4 Operational Metrics Cards -->
                <section class="metrics-grid" aria-label="Key Performance Indicators">
                    
                    <!-- Card 1: Active Sprints -->
                    <div class="metric-card">
                        <div class="metric-top">
                            <span class="metric-label">In Progress</span>
                            <div class="metric-icon-box icon-box-amber">
                                <i class="fas fa-hourglass-half icon-anim-pulse"></i>
                            </div>
                        </div>
                        <div class="metric-value"><?php echo $pendingTasks; ?></div>
                        <div class="metric-caption">Pending or Active Tasks</div>
                    </div>

                    <!-- Card 2: Completed Tasks -->
                    <div class="metric-card">
                        <div class="metric-top">
                            <span class="metric-label">Completed</span>
                            <div class="metric-icon-box icon-box-emerald">
                                <i class="fas fa-circle-check icon-anim-bounce"></i>
                            </div>
                        </div>
                        <div class="metric-value"><?php echo $completedTasks; ?></div>
                        <div class="metric-caption"><?php echo $completionRate; ?>% task completion rate</div>
                    </div>

                    <!-- Card 3: Total Projects -->
                    <div class="metric-card">
                        <div class="metric-top">
                            <span class="metric-label">Projects</span>
                            <div class="metric-icon-box icon-box-indigo">
                                <i class="fas fa-folder-tree icon-anim-float"></i>
                            </div>
                        </div>
                        <div class="metric-value"><?php echo $totalProjects; ?></div>
                        <div class="metric-caption">Client Deliverables</div>
                    </div>

                    <!-- Card 4: Total Assigned -->
                    <div class="metric-card">
                        <div class="metric-top">
                            <span class="metric-label">Total Assigned</span>
                            <div class="metric-icon-box icon-box-rose">
                                <i class="fas fa-list-check icon-anim-sparkle"></i>
                            </div>
                        </div>
                        <div class="metric-value"><?php echo $totalTasks; ?></div>
                        <div class="metric-caption">Lifetime Tasks Assigned</div>
                    </div>
                </section>

                <!-- Today's Attendance Quick Widget -->
                <section class="attendance-banner" style="<?php echo ($portalClosure && $portalClosure['is_closed']) ? 'background: linear-gradient(135deg, rgba(239, 68, 68, 0.08), rgba(220, 38, 38, 0.03)); border-color: rgba(239, 68, 68, 0.3);' : ''; ?>">
                    <div class="attendance-info-left">
                        <div class="attendance-icon-badge" style="<?php echo ($portalClosure && $portalClosure['is_closed']) ? 'background: #dc2626; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);' : ''; ?>">
                            <?php if ($portalClosure && $portalClosure['is_closed']): ?>
                                <i class="fas fa-lock"></i>
                            <?php else: ?>
                                <i class="fas fa-fingerprint icon-anim-pulse"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($portalClosure && $portalClosure['is_closed']): ?>
                                <h3 class="attendance-title" style="color: #b91c1c;">Attendance Portal Closed</h3>
                                <div class="attendance-sub">
                                    <span style="color: #dc2626; font-weight: 600;">
                                        <?php echo htmlspecialchars($portalClosure['message']); ?>
                                    </span>
                                    <?php if (!empty($todayAttendance['check_in'])): ?>
                                        <div style="font-size: 0.8rem; color: var(--db-muted); margin-top: 3px;">
                                            Checked in: <?php echo date('h:i A', strtotime($todayAttendance['check_in'])); ?>
                                            <?php if (!empty($todayAttendance['check_out'])): ?>
                                                &bull; Checked out: <?php echo date('h:i A', strtotime($todayAttendance['check_out'])); ?>
                                            <?php elseif (!empty($portalClosure['needs_hr_review'])): ?>
                                                &bull; <strong style="color: #b91c1c;">Unclosed workday flagged for HR review</strong>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <h3 class="attendance-title">Today's Attendance Status</h3>
                                <div class="attendance-sub">
                                    <?php if ($todayAttendance && !empty($todayAttendance['check_in'])): ?>
                                        <span style="color: var(--db-emerald); font-weight: 700;">
                                            <i class="fas fa-circle-check"></i> Checked In at <?php echo date('h:i A', strtotime($todayAttendance['check_in'])); ?>
                                        </span>
                                        <?php if (!empty($todayAttendance['check_out'])): ?>
                                            &bull; Checked Out at <?php echo date('h:i A', strtotime($todayAttendance['check_out'])); ?>
                                        <?php else: ?>
                                            &bull; Cutoff at <?php echo !empty($portalClosure['scheduled_checkout_time']) ? date('h:i A', strtotime($portalClosure['scheduled_checkout_time'])) : 'End of shift'; ?> (<?php echo $portalClosure['time_remaining_text'] ?? 'Active'; ?> remaining)
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: var(--db-amber); font-weight: 600;">
                                            <i class="fas fa-clock"></i> No check-in recorded yet &bull; Portal closes at <?php echo !empty($portalClosure['scheduled_checkout_time']) ? date('h:i A', strtotime($portalClosure['scheduled_checkout_time'])) : 'scheduled end time'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <a href="employee_attendance" class="action-btn <?php echo ($portalClosure && $portalClosure['is_closed']) ? 'action-btn-outline' : 'action-btn-primary'; ?>">
                        <?php if ($portalClosure && $portalClosure['is_closed']): ?>
                            <i class="fas fa-eye"></i> View Attendance Summary
                        <?php else: ?>
                            <i class="fas fa-location-arrow"></i> Open Attendance Desk
                        <?php endif; ?>
                    </a>
                </section>

                <!-- Recent Tasks & Activity -->
                <section class="section-box" aria-label="Recent Sprint Tasks">
                    <div class="section-box-header">
                        <h2 class="section-box-title">
                            <i class="fas fa-list-check"></i>
                            <span>Recent Assigned Tasks</span>
                        </h2>
                        <a href="employee_task" class="section-header-link">
                            <span>View All Tasks</span>
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                    <?php if (empty($recentTasks)): ?>
                        <div class="empty-tasks-state">
                            <div class="empty-tasks-icon">
                                <i class="fas fa-circle-check"></i>
                            </div>
                            <h3>You are all caught up!</h3>
                            <p>No active tasks are currently assigned to your account.</p>
                        </div>
                    <?php else: ?>
                        <!-- Desktop Table View -->
                        <div class="tasks-table-container">
                            <table class="tasks-table">
                                <thead>
                                    <tr>
                                        <th>Task Title</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Target Due Date</th>
                                        <th style="text-align: right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentTasks as $task): ?>
                                    <tr>
                                        <td class="task-title-cell">
                                            <?php echo htmlspecialchars($task['title']); ?>
                                        </td>
                                        <td>
                                            <?php $p = strtolower($task['priority'] ?: 'medium'); ?>
                                            <span class="badge badge-priority-<?php echo htmlspecialchars($p); ?>">
                                                <i class="fas fa-flag"></i> <?php echo ucfirst($p); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php $st = strtolower($task['status'] ?: 'pending'); ?>
                                            <span class="badge badge-status-<?php echo htmlspecialchars($st); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $st)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="display: inline-flex; align-items: center; gap: 6px; font-weight: 500;">
                                                <i class="fas fa-calendar" style="color: var(--db-muted); font-size: 0.85rem;"></i>
                                                <?php echo $task['due_date'] ? date('M j, Y', strtotime($task['due_date'])) : 'Open'; ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <a href="employee_task" class="section-header-link" style="font-size: 0.84rem;">
                                                Details <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Card View (< 680px) -->
                        <div class="mobile-tasks-cards">
                            <?php foreach ($recentTasks as $task): ?>
                            <div class="mobile-task-card">
                                <div class="mobile-task-top">
                                    <div class="mobile-task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                    <?php $p = strtolower($task['priority'] ?: 'medium'); ?>
                                    <span class="badge badge-priority-<?php echo htmlspecialchars($p); ?>">
                                        <?php echo ucfirst($p); ?>
                                    </span>
                                </div>
                                <div class="mobile-task-meta">
                                    <?php $st = strtolower($task['status'] ?: 'pending'); ?>
                                    <span class="badge badge-status-<?php echo htmlspecialchars($st); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $st)); ?>
                                    </span>
                                    <span>
                                        <i class="fas fa-calendar-day"></i> <?php echo $task['due_date'] ? date('M j, Y', strtotime($task['due_date'])) : 'No date'; ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            </div>
        </div>
    </main>

</body>
</html>
