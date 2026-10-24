<?php
/**
 * Cloud Aspire - Employee Task Workspace
 * SaaS-Grade Modern Task Management, Progression Pipeline, and Urgency Tracking
 */

// Include database connection and security helpers
require_once 'app/db.php';
require_once 'app/security.php';
require_once 'app/Mailer.php';

if (isset($pdo) && $pdo instanceof PDO) {
    Security::init($pdo);
}
Security::initSecureSession();

// Check if employee is logged in
if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    header("Location: login");
    exit();
}

$employee_id   = (int)($_SESSION['employee_id'] ?? 0);
$employee_name = $_SESSION['employee_name'] ?? 'Team Member';
$message       = '';
$messageType   = '';

// Task Priority Definitions with Visual Tokens
$priorities = [
    'critical' => [
        'label' => 'Critical',
        'icon'  => 'fa-fire',
        'color' => '#ef4444',
        'bg'    => 'rgba(239, 68, 68, 0.12)',
        'border'=> 'rgba(239, 68, 68, 0.3)',
        'order' => 1
    ],
    'high' => [
        'label' => 'High',
        'icon'  => 'fa-angles-up',
        'color' => '#f97316',
        'bg'    => 'rgba(249, 115, 22, 0.12)',
        'border'=> 'rgba(249, 115, 22, 0.3)',
        'order' => 2
    ],
    'medium' => [
        'label' => 'Medium',
        'icon'  => 'fa-bars-staggered',
        'color' => '#3b82f6',
        'bg'    => 'rgba(59, 130, 246, 0.12)',
        'border'=> 'rgba(59, 130, 246, 0.3)',
        'order' => 3
    ],
    'low' => [
        'label' => 'Low',
        'icon'  => 'fa-angle-down',
        'color' => '#10b981',
        'bg'    => 'rgba(16, 185, 129, 0.12)',
        'border'=> 'rgba(16, 185, 129, 0.3)',
        'order' => 4
    ]
];

// Task Status Progression Lifecycle
$statuses = [
    'sent' => [
        'label'       => 'To Do',
        'sublabel'    => 'Assigned / Backlog',
        'icon'        => 'fa-inbox',
        'color'       => '#0284c7',
        'bg'          => 'rgba(2, 132, 199, 0.1)',
        'border'      => 'rgba(2, 132, 199, 0.25)',
        'step'        => 1,
        'next'        => 'in_progress',
        'next_action' => 'Start Working',
        'next_icon'   => 'fa-play'
    ],
    'in_progress' => [
        'label'       => 'In Progress',
        'sublabel'    => 'Currently Active',
        'icon'        => 'fa-bolt',
        'color'       => '#d97706',
        'bg'          => 'rgba(217, 119, 6, 0.1)',
        'border'      => 'rgba(217, 119, 6, 0.25)',
        'step'        => 2,
        'next'        => 'verify',
        'next_action' => 'Submit for Review',
        'next_icon'   => 'fa-paper-plane'
    ],
    'verify' => [
        'label'       => 'Under Verification',
        'sublabel'    => 'Awaiting Lead Approval',
        'icon'        => 'fa-magnifying-glass-chart',
        'color'       => '#7c3aed',
        'bg'          => 'rgba(124, 58, 237, 0.1)',
        'border'      => 'rgba(124, 58, 237, 0.25)',
        'step'        => 3,
        'next'        => 'completed',
        'next_action' => 'Mark Completed',
        'next_icon'   => 'fa-check-double'
    ],
    'completed' => [
        'label'       => 'Completed',
        'sublabel'    => 'Finished & Verified',
        'icon'        => 'fa-circle-check',
        'color'       => '#059669',
        'bg'          => 'rgba(5, 150, 105, 0.1)',
        'border'      => 'rgba(5, 150, 105, 0.25)',
        'step'        => 4,
        'next'        => 'in_progress',
        'next_action' => 'Reopen Task',
        'next_icon'   => 'fa-arrow-rotate-left'
    ]
];

/**
 * Calculates due date urgency status and human-friendly badge
 */
function getDueUrgency($dueDate, $status) {
    if ($status === 'completed') {
        return [
            'type'      => 'done',
            'label'     => 'Completed',
            'sub'       => 'On file',
            'color'     => '#059669',
            'bg'        => 'rgba(5, 150, 105, 0.1)',
            'border'    => 'rgba(5, 150, 105, 0.25)',
            'icon'      => 'fa-check',
            'is_overdue'=> false
        ];
    }
    if (empty($dueDate)) {
        return [
            'type'      => 'none',
            'label'     => 'No Due Date',
            'sub'       => 'Flexible milestone',
            'color'     => '#64748b',
            'bg'        => 'rgba(100, 116, 139, 0.08)',
            'border'    => 'rgba(100, 116, 139, 0.2)',
            'icon'      => 'fa-calendar',
            'is_overdue'=> false
        ];
    }

    $today = new DateTime('today');
    $due   = new DateTime($dueDate);
    $diff  = $today->diff($due);
    $days  = (int)$diff->format('%r%a');

    if ($days < 0) {
        $abs = abs($days);
        return [
            'type'      => 'overdue',
            'label'     => 'Overdue by ' . $abs . ' day' . ($abs > 1 ? 's' : ''),
            'sub'       => 'Was due ' . $due->format('M d, Y'),
            'color'     => '#e11d48',
            'bg'        => 'rgba(225, 29, 72, 0.12)',
            'border'    => 'rgba(225, 29, 72, 0.3)',
            'icon'      => 'fa-triangle-exclamation',
            'is_overdue'=> true,
            'days_diff' => $days
        ];
    } elseif ($days === 0) {
        return [
            'type'      => 'today',
            'label'     => 'Due Today',
            'sub'       => 'Requires immediate finish',
            'color'     => '#d97706',
            'bg'        => 'rgba(217, 119, 6, 0.14)',
            'border'    => 'rgba(217, 119, 6, 0.35)',
            'icon'      => 'fa-clock',
            'is_overdue'=> false,
            'days_diff' => 0
        ];
    } elseif ($days === 1) {
        return [
            'type'      => 'tomorrow',
            'label'     => 'Due Tomorrow',
            'sub'       => $due->format('M d, Y'),
            'color'     => '#4f46e5',
            'bg'        => 'rgba(79, 70, 229, 0.1)',
            'border'    => 'rgba(79, 70, 229, 0.25)',
            'icon'      => 'fa-calendar-day',
            'is_overdue'=> false,
            'days_diff' => 1
        ];
    } else {
        return [
            'type'      => 'upcoming',
            'label'     => 'Due in ' . $days . ' days',
            'sub'       => $due->format('M d, Y'),
            'color'     => '#334155',
            'bg'        => 'rgba(51, 65, 85, 0.08)',
            'border'    => 'rgba(51, 65, 85, 0.18)',
            'icon'      => 'fa-calendar',
            'is_overdue'=> false,
            'days_diff' => $days
        ];
    }
}

// -------------------------------------------------------------------
// Handle POST Actions (Status Update & Quick Progression with CSRF)
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'employee_task')) {
        $message     = 'Invalid or expired security token. Please try again.';
        $messageType = 'error';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_task_status') {
        $taskId    = (int)($_POST['task_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        $note      = trim($_POST['note'] ?? '');

        if ($taskId > 0 && array_key_exists($newStatus, $statuses)) {
            try {
                // Verify task ownership
                $checkStmt = $pdo->prepare("SELECT id, title, status FROM tasks WHERE id = ? AND employee_id = ?");
                $checkStmt->execute([$taskId, $employee_id]);
                $task = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($task) {
                    $stmt = $pdo->prepare("UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ? AND employee_id = ?");
                    $stmt->execute([$newStatus, $taskId, $employee_id]);

                    // Safe Mailer Alert (non-blocking)
                    try {
                        $mailer = new Mailer($pdo);
                        $statusLabel = $statuses[$newStatus]['label'] ?? $newStatus;
                        $mailer->sendTaskUpdateAlert($employee_name, $task['title'], $statusLabel);
                    } catch (Throwable $mailEx) {
                        error_log("Task update mailer error: " . $mailEx->getMessage());
                    }

                    $message = 'Task "' . htmlspecialchars($task['title']) . '" moved to ' . htmlspecialchars($statuses[$newStatus]['label']) . '.';
                    $messageType = 'success';
                } else {
                    $message = 'Task not found or access denied.';
                    $messageType = 'error';
                }
            } catch (PDOException $e) {
                error_log("Employee task status update error: " . $e->getMessage());
                $message = 'Database error updating task. Please try again.';
                $messageType = 'error';
            }
        } else {
            $message = 'Invalid task selection or status.';
            $messageType = 'error';
        }
    }
}

// -------------------------------------------------------------------
// Fetch Assigned Tasks & Calculate Real-Time Metrics
// -------------------------------------------------------------------
$tasks = [];
$stats = [
    'total'       => 0,
    'sent'        => 0,
    'in_progress' => 0,
    'verify'      => 0,
    'completed'   => 0,
    'overdue'     => 0,
];

try {
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE employee_id = ? ORDER BY 
        CASE status 
            WHEN 'sent' THEN 1 
            WHEN 'in_progress' THEN 2 
            WHEN 'verify' THEN 3 
            WHEN 'completed' THEN 4 
            ELSE 5 
        END, 
        CASE 
            WHEN due_date IS NOT NULL AND due_date < CURDATE() AND status != 'completed' THEN 1 
            ELSE 2 
        END, 
        due_date ASC, 
        created_at DESC");
    $stmt->execute([$employee_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['total'] = count($tasks);

    $todayStr = date('Y-m-d');
    foreach ($tasks as $t) {
        $st = $t['status'] ?? 'sent';
        if (isset($stats[$st])) {
            $stats[$st]++;
        }
        if (!empty($t['due_date']) && $t['due_date'] < $todayStr && $st !== 'completed') {
            $stats['overdue']++;
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching employee tasks: " . $e->getMessage());
    $tasks = [];
}

$activeCount = $stats['sent'] + $stats['in_progress'];
$completionRate = ($stats['total'] > 0) ? round(($stats['completed'] / $stats['total']) * 100) : 0;

// Generate CSRF token for the page forms (generated after POST to prevent token collision)
$csrfToken = Security::generateCSRFToken('employee_task');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Tasks &bull; Cloud Aspire Workforce Hub</title>
    <meta name="description" content="Manage assigned tasks, update progression milestones, and track deadline deliverables.">
    <link rel="icon" type="image/png" href="https://cloudaspire.in/uploads/site_favicon_1783239900.png">

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme.css">

    <!-- Immediate Theme Initialization (Eliminates Flash) -->
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
            --tsk-bg:            #f8fafc;
            --tsk-surface:       #ffffff;
            --tsk-surface-subtle:#f1f5f9;
            --tsk-surface-card:  #ffffff;
            --tsk-text:          #09090b;
            --tsk-text-body:     #1e293b;
            --tsk-muted:         #475569;
            --tsk-faint:         #64748b;
            --tsk-border:        #cbd5e1;
            --tsk-border-subtle: #e2e8f0;

            --tsk-brand:         #4f46e5;
            --tsk-brand-hover:   #4338ca;
            --tsk-brand-light:   #eef2ff;
            --tsk-brand-border:  #c7d2fe;

            --tsk-emerald:       #059669;
            --tsk-emerald-light: #ecfdf5;
            --tsk-emerald-border:rgba(5, 150, 105, 0.3);

            --tsk-amber:         #d97706;
            --tsk-amber-light:   #fffbeb;
            --tsk-amber-border:  rgba(217, 119, 6, 0.3);

            --tsk-rose:          #e11d48;
            --tsk-rose-light:    #fff1f2;
            --tsk-rose-border:   rgba(225, 29, 72, 0.3);

            --tsk-sky:           #0284c7;
            --tsk-sky-light:     #f0f9ff;
            --tsk-sky-border:    rgba(2, 132, 199, 0.3);

            --tsk-violet:        #7c3aed;
            --tsk-violet-light:  #f5f3ff;
            --tsk-violet-border: rgba(124, 58, 237, 0.3);

            --tsk-radius-sm:     8px;
            --tsk-radius-md:     14px;
            --tsk-radius-lg:     18px;
            --tsk-radius-card:   22px;
            --tsk-radius-pill:   9999px;

            --tsk-shadow-card:   0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 8px 24px -4px rgba(15, 23, 42, 0.06);
            --tsk-shadow-hover:  0 16px 36px -6px rgba(15, 23, 42, 0.12);
            --tsk-transition:    0.26s cubic-bezier(0.16, 1, 0.3, 1);
            --tsk-max-w:         1440px;
        }

        html[data-theme="dark"] {
            --tsk-bg:            #090d16;
            --tsk-surface:       #131d33;
            --tsk-surface-subtle:#0f172a;
            --tsk-surface-card:  #141f38;
            --tsk-text:          #f8fafc;
            --tsk-text-body:     #e2e8f0;
            --tsk-muted:         #94a3b8;
            --tsk-faint:         #64748b;
            --tsk-border:        #2d3f66;
            --tsk-border-subtle: #1e293b;

            --tsk-brand:         #6366f1;
            --tsk-brand-hover:   #4f46e5;
            --tsk-brand-light:   rgba(99, 102, 241, 0.16);
            --tsk-brand-border:  rgba(99, 102, 241, 0.35);

            --tsk-emerald:       #10b981;
            --tsk-emerald-light: rgba(16, 185, 129, 0.14);
            --tsk-emerald-border:rgba(16, 185, 129, 0.3);

            --tsk-amber:         #f59e0b;
            --tsk-amber-light:   rgba(245, 158, 11, 0.14);
            --tsk-amber-border:  rgba(245, 158, 11, 0.3);

            --tsk-rose:          #f43f5e;
            --tsk-rose-light:    rgba(244, 63, 94, 0.14);
            --tsk-rose-border:   rgba(244, 63, 94, 0.3);

            --tsk-sky:           #38bdf8;
            --tsk-sky-light:     rgba(56, 189, 248, 0.14);
            --tsk-sky-border:    rgba(56, 189, 248, 0.3);

            --tsk-violet:        #a78bfa;
            --tsk-violet-light:  rgba(167, 139, 250, 0.14);
            --tsk-violet-border: rgba(167, 139, 250, 0.3);

            --tsk-shadow-card:   0 4px 20px -2px rgba(0, 0, 0, 0.5);
            --tsk-shadow-hover:  0 20px 40px -4px rgba(0, 0, 0, 0.65);
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--tsk-bg);
            color: var(--tsk-text-body);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            transition: background-color var(--tsk-transition), color var(--tsk-transition);
        }

        .hub-container {
            width: 100%;
            max-width: var(--tsk-max-w);
            margin: 0 auto;
            padding: 32px 24px 64px;
            flex-grow: 1;
        }

        /* ---------------- Alerts ---------------- */
        .tsk-alert {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 20px;
            border-radius: var(--tsk-radius-md);
            margin-bottom: 26px;
            font-size: 0.95rem;
            font-weight: 500;
            animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .tsk-alert-success {
            background: var(--tsk-emerald-light);
            border: 1px solid var(--tsk-emerald-border);
            color: var(--tsk-emerald);
        }
        .tsk-alert-error {
            background: var(--tsk-rose-light);
            border: 1px solid var(--tsk-rose-border);
            color: var(--tsk-rose);
        }

        /* Overdue Notice Alert */
        .overdue-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: var(--tsk-rose-light);
            border: 1px solid var(--tsk-rose-border);
            border-radius: var(--tsk-radius-lg);
            padding: 16px 22px;
            margin-bottom: 26px;
            color: var(--tsk-rose);
            box-shadow: var(--tsk-shadow-card);
        }
        .overdue-strip-content {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 0.94rem;
            font-weight: 600;
        }
        .overdue-strip-btn {
            background: var(--tsk-rose);
            color: #ffffff !important;
            border: none;
            padding: 7px 16px;
            border-radius: var(--tsk-radius-pill);
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all var(--tsk-transition);
        }
        .overdue-strip-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* ---------------- Hero Section ---------------- */
        .hero-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            background: var(--tsk-surface);
            border: 1px solid var(--tsk-border);
            border-radius: var(--tsk-radius-card);
            padding: 30px 36px;
            margin-bottom: 28px;
            box-shadow: var(--tsk-shadow-card);
            position: relative;
            overflow: hidden;
        }
        .hero-banner::before {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(79, 70, 229, 0.14) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .hero-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--tsk-text);
            margin: 0 0 6px;
            letter-spacing: -0.025em;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .hero-title i {
            color: var(--tsk-brand);
            font-size: 1.65rem;
        }
        .hero-subtitle {
            font-size: 0.96rem;
            color: var(--tsk-muted);
            margin: 0;
        }

        /* Hero Progress Pill */
        .hero-progress-box {
            display: flex;
            align-items: center;
            gap: 16px;
            background: var(--tsk-surface-subtle);
            border: 1px solid var(--tsk-border);
            padding: 12px 20px;
            border-radius: var(--tsk-radius-pill);
        }
        .hero-circle-wrap {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.3rem;
            color: var(--tsk-brand);
        }
        .hero-progress-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--tsk-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* ---------------- KPI Metric Cards ---------------- */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        .kpi-card {
            background: var(--tsk-surface);
            border: 1px solid var(--tsk-border);
            border-radius: var(--tsk-radius-lg);
            padding: 22px 24px;
            display: flex;
            align-items: center;
            gap: 18px;
            box-shadow: var(--tsk-shadow-card);
            transition: all var(--tsk-transition);
            position: relative;
            overflow: hidden;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--tsk-shadow-hover);
            border-color: var(--tsk-brand-border);
        }
        .kpi-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: var(--tsk-radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .kpi-indigo .kpi-icon-wrap {
            background: var(--tsk-brand-light);
            color: var(--tsk-brand);
        }
        .kpi-amber .kpi-icon-wrap {
            background: var(--tsk-amber-light);
            color: var(--tsk-amber);
        }
        .kpi-violet .kpi-icon-wrap {
            background: var(--tsk-violet-light);
            color: var(--tsk-violet);
        }
        .kpi-emerald .kpi-icon-wrap {
            background: var(--tsk-emerald-light);
            color: var(--tsk-emerald);
        }

        .kpi-val {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--tsk-text);
            line-height: 1.15;
        }
        .kpi-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--tsk-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 3px;
        }

        /* ---------------- Controls Bar: Filters & Search ---------------- */
        .controls-card {
            background: var(--tsk-surface);
            border: 1px solid var(--tsk-border);
            border-radius: var(--tsk-radius-lg);
            padding: 16px 20px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: var(--tsk-shadow-card);
        }
        .filter-tabs {
            display: flex;
            align-items: center;
            gap: 8px;
            overflow-x: auto;
            max-width: 100%;
            min-width: 0;
            padding-bottom: 4px;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
        }
        .filter-tab {
            background: transparent;
            border: 1px solid transparent;
            color: var(--tsk-muted);
            font-weight: 600;
            font-size: 0.88rem;
            padding: 8px 16px;
            border-radius: var(--tsk-radius-pill);
            cursor: pointer;
            transition: all var(--tsk-transition);
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .filter-tab:hover {
            color: var(--tsk-text);
            background: var(--tsk-surface-subtle);
        }
        .filter-tab.active {
            background: var(--tsk-brand-light);
            color: var(--tsk-brand);
            border-color: var(--tsk-brand-border);
        }
        .filter-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.08);
            color: inherit;
            font-size: 0.76rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: var(--tsk-radius-pill);
        }
        html[data-theme="dark"] .filter-count {
            background: rgba(255, 255, 255, 0.12);
        }

        .search-sort-box {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-grow: 1;
            justify-content: flex-end;
            min-width: 0;
            max-width: 100%;
        }
        .priority-filter-select {
            padding: 8px 14px;
            border: 1px solid var(--tsk-border);
            border-radius: var(--tsk-radius-pill);
            background: var(--tsk-surface-subtle);
            color: var(--tsk-text);
            font-size: 0.86rem;
            font-weight: 600;
            outline: none;
            cursor: pointer;
            transition: all var(--tsk-transition);
        }
        .priority-filter-select:focus {
            border-color: var(--tsk-brand);
            background: var(--tsk-surface);
        }

        .search-wrap {
            position: relative;
            width: 100%;
            max-width: 280px;
            min-width: 0;
        }
        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--tsk-faint);
            font-size: 0.9rem;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            padding: 9px 14px 9px 38px;
            border: 1px solid var(--tsk-border);
            background: var(--tsk-surface-subtle);
            color: var(--tsk-text);
            border-radius: var(--tsk-radius-pill);
            font-size: 0.88rem;
            outline: none;
            transition: all var(--tsk-transition);
            font-family: inherit;
        }
        .search-input:focus {
            border-color: var(--tsk-brand);
            background: var(--tsk-surface);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        /* ---------------- Tasks Grid ---------------- */
        .tasks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
            gap: 24px;
        }

        .task-card {
            background: var(--tsk-surface-card);
            border: 1px solid var(--tsk-border);
            border-radius: var(--tsk-radius-card);
            padding: 24px;
            box-shadow: var(--tsk-shadow-card);
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: relative;
            overflow-wrap: break-word;
            word-break: break-word;
            transition: all var(--tsk-transition);
        }
        .task-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--tsk-shadow-hover);
        }

        /* Top Tag Row: Priority & Due Date */
        .card-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .priority-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            border-radius: var(--tsk-radius-pill);
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border: 1px solid transparent;
        }

        .due-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            border-radius: var(--tsk-radius-pill);
            font-size: 0.76rem;
            font-weight: 700;
            border: 1px solid transparent;
        }

        /* Title & Description */
        .task-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--tsk-text);
            margin: 0;
            line-height: 1.4;
            word-break: break-word;
        }

        .task-desc {
            font-size: 0.91rem;
            color: var(--tsk-text-body);
            background: var(--tsk-surface-subtle);
            border: 1px solid var(--tsk-border-subtle);
            border-radius: var(--tsk-radius-md);
            padding: 14px;
            line-height: 1.6;
            margin: 0;
            word-break: break-word;
            flex-grow: 1;
            max-height: 140px;
            overflow-y: auto;
        }
        .task-desc.empty {
            color: var(--tsk-faint);
            font-style: italic;
        }

        /* Lifecycle Progress Pipeline */
        .stepper-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            background: var(--tsk-surface-subtle);
            border: 1px solid var(--tsk-border-subtle);
            padding: 8px 12px;
            border-radius: var(--tsk-radius-md);
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.76rem;
            font-weight: 700;
            color: var(--tsk-faint);
            transition: all var(--tsk-transition);
        }
        .step-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--tsk-border);
        }
        .step-item.active {
            color: var(--tsk-text);
        }
        .step-item.active .step-dot {
            background: var(--tsk-brand);
            box-shadow: 0 0 0 3px var(--tsk-brand-light);
        }
        .step-item.completed {
            color: var(--tsk-emerald);
        }
        .step-item.completed .step-dot {
            background: var(--tsk-emerald);
        }

        /* Action Buttons Row */
        .card-action-bar {
            border-top: 1px solid var(--tsk-border-subtle);
            padding-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: auto;
        }

        .btn-advance-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: var(--tsk-radius-md);
            font-size: 0.86rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all var(--tsk-transition);
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            white-space: nowrap;
        }
        .btn-advance-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(79, 70, 229, 0.35);
        }
        .btn-advance-action:active {
            transform: translateY(1px);
        }
        .btn-advance-done {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
        }

        .btn-more-options {
            background: var(--tsk-surface);
            border: 1px solid var(--tsk-border);
            color: var(--tsk-muted);
            padding: 9px 14px;
            border-radius: var(--tsk-radius-md);
            font-size: 0.86rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--tsk-transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-more-options:hover {
            color: var(--tsk-brand);
            border-color: var(--tsk-brand-border);
            background: var(--tsk-brand-light);
        }

        /* ---------------- Empty State ---------------- */
        .empty-state-box {
            background: var(--tsk-surface);
            border: 2px dashed var(--tsk-border);
            border-radius: var(--tsk-radius-card);
            padding: 64px 32px;
            text-align: center;
            max-width: 600px;
            margin: 40px auto;
            box-shadow: var(--tsk-shadow-card);
        }
        .empty-icon-wrap {
            width: 80px;
            height: 80px;
            background: var(--tsk-brand-light);
            color: var(--tsk-brand);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            margin: 0 auto 20px;
        }
        .empty-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--tsk-text);
            margin: 0 0 8px;
        }
        .empty-desc {
            font-size: 0.94rem;
            color: var(--tsk-muted);
            margin: 0;
            line-height: 1.5;
        }

        /* ---------------- Modals ---------------- */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
            animation: fadeIn 0.2s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .modal-dialog {
            background: var(--tsk-surface);
            border: 1px solid var(--tsk-border);
            border-radius: var(--tsk-radius-card);
            width: 100%;
            max-width: 520px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            overflow: hidden;
            animation: modalPop 0.26s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalPop {
            from { transform: scale(0.94) translateY(12px); opacity: 0; }
            to { transform: scale(1) translateY(0); opacity: 1; }
        }

        .modal-header {
            padding: 22px 28px;
            border-bottom: 1px solid var(--tsk-border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .modal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.28rem;
            font-weight: 700;
            color: var(--tsk-text);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-title i {
            color: var(--tsk-brand);
        }
        .modal-close-btn {
            background: transparent;
            border: none;
            color: var(--tsk-muted);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 6px;
            border-radius: var(--tsk-radius-sm);
            transition: all var(--tsk-transition);
        }
        .modal-close-btn:hover {
            color: var(--tsk-rose);
            background: var(--tsk-rose-light);
        }

        .modal-body {
            padding: 24px 28px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-label {
            display: block;
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--tsk-text);
            margin-bottom: 7px;
        }
        .form-control {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid var(--tsk-border);
            border-radius: var(--tsk-radius-md);
            background: var(--tsk-surface-subtle);
            color: var(--tsk-text);
            font-size: 0.92rem;
            font-family: inherit;
            outline: none;
            transition: all var(--tsk-transition);
        }
        .form-control:focus {
            border-color: var(--tsk-brand);
            background: var(--tsk-surface);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .modal-footer {
            padding: 18px 28px;
            border-top: 1px solid var(--tsk-border-subtle);
            background: var(--tsk-surface-subtle);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-modal-cancel {
            background: var(--tsk-surface);
            border: 1px solid var(--tsk-border);
            color: var(--tsk-text-body);
            padding: 10px 18px;
            border-radius: var(--tsk-radius-md);
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all var(--tsk-transition);
        }
        .btn-modal-cancel:hover {
            background: var(--tsk-surface-subtle);
            color: var(--tsk-text);
        }
        .btn-modal-submit {
            background: var(--tsk-brand);
            border: 1px solid var(--tsk-brand);
            color: #ffffff;
            padding: 10px 22px;
            border-radius: var(--tsk-radius-md);
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all var(--tsk-transition);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .btn-modal-submit:hover {
            background: var(--tsk-brand-hover);
        }

        /* ---------------- Responsive Styles ---------------- */
        @media (max-width: 1024px) {
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 768px) {
            .hub-container {
                padding: 20px 16px 48px;
            }
            .hero-banner {
                flex-direction: column;
                align-items: flex-start;
                padding: 24px;
            }
            .hero-progress-box {
                width: 100%;
                justify-content: space-between;
            }
            .controls-card {
                flex-direction: column;
                align-items: stretch;
            }
            .search-sort-box {
                justify-content: stretch;
                flex-wrap: wrap;
            }
            .search-wrap {
                max-width: 100%;
            }
            .tasks-grid {
                grid-template-columns: 1fr;
            }
            .kpi-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
        }
        @media (max-width: 480px) {
            .hub-container {
                padding: 16px 12px 48px;
            }
            .controls-card {
                padding: 12px;
            }
            .task-card {
                padding: 16px;
            }
            .card-action-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .btn-advance-action, .btn-more-options {
                justify-content: center;
                width: 100%;
            }
        }
    </style>
</head>
<body>

    <!-- Workforce Hub Header Navigation -->
    <?php include 'employee_header.php'; ?>

    <main class="hub-container">

        <!-- Flash Notice / Feedback Message -->
        <?php if (!empty($message)): ?>
            <div class="tsk-alert tsk-alert-<?php echo $messageType; ?>" role="alert">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> fa-lg"></i>
                <div><?php echo htmlspecialchars($message); ?></div>
            </div>
        <?php endif; ?>

        <!-- Overdue Tasks Banner (If Any) -->
        <?php if ($stats['overdue'] > 0): ?>
            <section class="overdue-strip" role="alert">
                <div class="overdue-strip-content">
                    <i class="fas fa-bell fa-shake fa-lg"></i>
                    <div>
                        <strong>Attention:</strong> You have <strong><?php echo $stats['overdue']; ?></strong> overdue task<?php echo $stats['overdue'] > 1 ? 's' : ''; ?> past deadline.
                    </div>
                </div>
                <button type="button" class="overdue-strip-btn" onclick="applyFilter('overdue')">
                    View Overdue Tasks
                </button>
            </section>
        <?php endif; ?>

        <!-- Hero Header -->
        <section class="hero-banner">
            <div>
                <h1 class="hero-title">
                    <i class="fas fa-list-check"></i>
                    Task Workspace
                </h1>
                <p class="hero-subtitle">
                    Track sprint deliverables, prioritize urgent deadlines, and update milestone progression.
                </p>
            </div>

            <div class="hero-progress-box">
                <div class="hero-circle-wrap">
                    <?php echo $completionRate; ?>%
                </div>
                <div>
                    <div class="hero-progress-label">Task Velocity</div>
                    <div style="font-size: 0.86rem; color: var(--tsk-text); font-weight: 600;">
                        <?php echo $stats['completed']; ?> of <?php echo $stats['total']; ?> Done
                    </div>
                </div>
            </div>
        </section>

        <!-- KPI Metrics Grid -->
        <section class="kpi-grid">
            <div class="kpi-card kpi-indigo">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $stats['total']; ?></div>
                    <div class="kpi-label">Total Assigned</div>
                </div>
            </div>

            <div class="kpi-card kpi-amber">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-bolt"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $activeCount; ?></div>
                    <div class="kpi-label">To Do & Active</div>
                </div>
            </div>

            <div class="kpi-card kpi-violet">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-magnifying-glass-chart"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $stats['verify']; ?></div>
                    <div class="kpi-label">In Verification</div>
                </div>
            </div>

            <div class="kpi-card kpi-emerald">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-circle-check"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $stats['completed']; ?></div>
                    <div class="kpi-label">Completed</div>
                </div>
            </div>
        </section>

        <!-- Filters & Search Toolbar -->
        <section class="controls-card">
            <div class="filter-tabs" role="tablist">
                <button class="filter-tab active" data-filter="all" onclick="applyFilter('all', this)">
                    <span>All</span>
                    <span class="filter-count"><?php echo $stats['total']; ?></span>
                </button>
                <button class="filter-tab" data-filter="active" onclick="applyFilter('active', this)">
                    <span>Active Sprint</span>
                    <span class="filter-count"><?php echo $activeCount; ?></span>
                </button>
                <button class="filter-tab" data-filter="verify" onclick="applyFilter('verify', this)">
                    <span>Under Review</span>
                    <span class="filter-count"><?php echo $stats['verify']; ?></span>
                </button>
                <button class="filter-tab" data-filter="completed" onclick="applyFilter('completed', this)">
                    <span>Completed</span>
                    <span class="filter-count"><?php echo $stats['completed']; ?></span>
                </button>
                <?php if ($stats['overdue'] > 0): ?>
                    <button class="filter-tab" data-filter="overdue" onclick="applyFilter('overdue', this)">
                        <i class="fas fa-circle-exclamation" style="color: var(--tsk-rose); font-size: 0.8rem;"></i>
                        <span>Overdue</span>
                        <span class="filter-count" style="background: var(--tsk-rose-light); color: var(--tsk-rose);">
                            <?php echo $stats['overdue']; ?>
                        </span>
                    </button>
                <?php endif; ?>
            </div>

            <div class="search-sort-box">
                <!-- Priority Dropdown -->
                <select id="priorityFilter" class="priority-filter-select" onchange="filterTaskCards()">
                    <option value="all">All Priorities</option>
                    <option value="critical">Critical</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>

                <!-- Keyword Search -->
                <div class="search-wrap">
                    <i class="fas fa-magnifying-glass search-icon"></i>
                    <input type="text" 
                           id="taskSearchInput" 
                           class="search-input" 
                           placeholder="Search tasks, details..."
                           onkeyup="filterTaskCards()">
                </div>
            </div>
        </section>

        <!-- Tasks Cards Grid / Empty State -->
        <?php if (empty($tasks)): ?>
            <div class="empty-state-box">
                <div class="empty-icon-wrap">
                    <i class="fas fa-circle-check"></i>
                </div>
                <h3 class="empty-title">All Caught Up!</h3>
                <p class="empty-desc">
                    You have zero assigned tasks in your queue right now. New projects and milestones from your manager will appear here.
                </p>
            </div>
        <?php else: ?>
            <div class="tasks-grid" id="tasksContainer">
                <?php foreach ($tasks as $task): 
                    $tId      = (int)$task['id'];
                    $pKey     = strtolower($task['priority'] ?? 'medium');
                    $sKey     = strtolower($task['status'] ?? 'sent');
                    $pCfg     = $priorities[$pKey] ?? $priorities['medium'];
                    $sCfg     = $statuses[$sKey] ?? $statuses['sent'];
                    $dueInfo  = getDueUrgency($task['due_date'], $sKey);

                    // Filter Groups
                    $isSprintActive = in_array($sKey, ['sent', 'in_progress'], true);
                    $filterGroup = 'all';
                    if ($isSprintActive) $filterGroup = 'active';
                    elseif ($sKey === 'verify') $filterGroup = 'verify';
                    elseif ($sKey === 'completed') $filterGroup = 'completed';

                    $assignedDate = !empty($task['created_at']) ? date('M d, Y', strtotime($task['created_at'])) : '';
                ?>
                    <article class="task-card" 
                             data-id="<?php echo $tId; ?>"
                             data-status="<?php echo htmlspecialchars($sKey); ?>"
                             data-priority="<?php echo htmlspecialchars($pKey); ?>"
                             data-filter-group="<?php echo htmlspecialchars($filterGroup); ?>"
                             data-is-overdue="<?php echo $dueInfo['is_overdue'] ? '1' : '0'; ?>"
                             data-title="<?php echo htmlspecialchars(strtolower($task['title'])); ?>"
                             data-desc="<?php echo htmlspecialchars(strtolower($task['description'] ?? '')); ?>">
                        
                        <!-- Top Meta Row: Priority & Due Date -->
                        <div class="card-meta-row">
                            <span class="priority-badge" 
                                  style="color: <?php echo $pCfg['color']; ?>; background: <?php echo $pCfg['bg']; ?>; border-color: <?php echo $pCfg['border']; ?>;">
                                <i class="fas <?php echo $pCfg['icon']; ?>"></i>
                                <?php echo htmlspecialchars($pCfg['label']); ?>
                            </span>

                            <span class="due-badge" 
                                  style="color: <?php echo $dueInfo['color']; ?>; background: <?php echo $dueInfo['bg']; ?>; border-color: <?php echo $dueInfo['border']; ?>;"
                                  title="<?php echo htmlspecialchars($dueInfo['sub']); ?>">
                                <i class="fas <?php echo $dueInfo['icon']; ?>"></i>
                                <?php echo htmlspecialchars($dueInfo['label']); ?>
                            </span>
                        </div>

                        <!-- Task Title -->
                        <h3 class="task-title"><?php echo htmlspecialchars($task['title']); ?></h3>

                        <!-- Task Description -->
                        <div class="task-desc <?php echo empty($task['description']) ? 'empty' : ''; ?>">
                            <?php echo !empty($task['description']) ? nl2br(htmlspecialchars($task['description'])) : 'No additional specification notes provided for this task.'; ?>
                        </div>

                        <!-- Pipeline Progress Stepper -->
                        <div class="stepper-wrap" title="Current Status: <?php echo htmlspecialchars($sCfg['label']); ?>">
                            <div class="step-item <?php echo $sCfg['step'] >= 1 ? ($sCfg['step'] > 1 ? 'completed' : 'active') : ''; ?>">
                                <div class="step-dot"></div>
                                <span>To Do</span>
                            </div>
                            <i class="fas fa-chevron-right" style="font-size: 0.65rem; color: var(--tsk-border);"></i>

                            <div class="step-item <?php echo $sCfg['step'] >= 2 ? ($sCfg['step'] > 2 ? 'completed' : 'active') : ''; ?>">
                                <div class="step-dot"></div>
                                <span>Active</span>
                            </div>
                            <i class="fas fa-chevron-right" style="font-size: 0.65rem; color: var(--tsk-border);"></i>

                            <div class="step-item <?php echo $sCfg['step'] >= 3 ? ($sCfg['step'] > 3 ? 'completed' : 'active') : ''; ?>">
                                <div class="step-dot"></div>
                                <span>Review</span>
                            </div>
                            <i class="fas fa-chevron-right" style="font-size: 0.65rem; color: var(--tsk-border);"></i>

                            <div class="step-item <?php echo $sCfg['step'] >= 4 ? 'completed active' : ''; ?>">
                                <div class="step-dot"></div>
                                <span>Done</span>
                            </div>
                        </div>

                        <!-- Card Actions Bar -->
                        <div class="card-action-bar">
                            <!-- Quick Advance Form -->
                            <form method="POST" action="employee_task" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="update_task_status">
                                <input type="hidden" name="task_id" value="<?php echo $tId; ?>">
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($sCfg['next']); ?>">

                                <?php if ($sKey === 'completed'): ?>
                                    <button type="submit" class="btn-advance-action btn-advance-done" title="Reopen this completed task">
                                        <i class="fas fa-arrow-rotate-left"></i> Reopen Task
                                    </button>
                                <?php else: ?>
                                    <button type="submit" class="btn-advance-action">
                                        <i class="fas <?php echo $sCfg['next_icon']; ?>"></i> 
                                        <?php echo htmlspecialchars($sCfg['next_action']); ?>
                                    </button>
                                <?php endif; ?>
                            </form>

                            <!-- More Details / Direct Status Modal Button -->
                            <button type="button" 
                                    class="btn-more-options" 
                                    onclick="openTaskModal(<?php echo htmlspecialchars(json_encode([
                                        'id'          => $tId,
                                        'title'       => $task['title'],
                                        'description' => $task['description'] ?? '',
                                        'priority'    => $pKey,
                                        'priority_lbl'=> $pCfg['label'],
                                        'status'      => $sKey,
                                        'status_lbl'  => $sCfg['label'],
                                        'due_date'    => $task['due_date'] ?? '',
                                        'assigned'    => $assignedDate,
                                        'due_badge'   => $dueInfo['label']
                                    ])); ?>)">
                                <i class="fas fa-sliders"></i>
                                Status Options
                            </button>
                        </div>

                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Zero Results Search Match -->
            <div id="noResultsState" class="empty-state-box" style="display: none;">
                <div class="empty-icon-wrap" style="background: var(--tsk-surface-subtle); color: var(--tsk-muted);">
                    <i class="fas fa-magnifying-glass"></i>
                </div>
                <h3 class="empty-title">No Matching Tasks Found</h3>
                <p class="empty-desc">Try clearing your search query or selecting another status filter.</p>
                <button type="button" class="btn-modal-cancel" onclick="resetFilters()" style="margin-top: 16px;">
                    Reset All Filters
                </button>
            </div>
        <?php endif; ?>

    </main>

    <!-- ============================================== -->
    <!-- Task Management & Status Update Modal          -->
    <!-- ============================================== -->
    <div class="modal-overlay" id="taskModal" onclick="handleOverlayClick(event, 'taskModal')">
        <div class="modal-dialog" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-list-check"></i>
                    Update Task Status
                </h2>
                <button type="button" class="modal-close-btn" onclick="closeModal('taskModal')">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="employee_task">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="update_task_status">
                <input type="hidden" name="task_id" id="modal_task_id">

                <div class="modal-body">
                    <div style="margin-bottom: 20px;">
                        <span id="modal_priority_badge" class="priority-badge" style="margin-bottom: 8px;"></span>
                        <h3 id="modal_task_title" style="margin: 6px 0 4px; font-size: 1.15rem; font-family: 'Outfit', sans-serif; color: var(--tsk-text);"></h3>
                        <div id="modal_due_info" style="font-size: 0.84rem; color: var(--tsk-muted);"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="modal_status_select">Select New Status</label>
                        <select name="status" id="modal_status_select" class="form-control">
                            <?php foreach ($statuses as $k => $info): ?>
                                <option value="<?php echo $k; ?>">
                                    <?php echo htmlspecialchars($info['label']); ?> &bull; <?php echo htmlspecialchars($info['sublabel']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Full Task Description</label>
                        <div id="modal_task_desc" style="font-size: 0.9rem; background: var(--tsk-surface-subtle); border: 1px solid var(--tsk-border-subtle); border-radius: var(--tsk-radius-md); padding: 14px; max-height: 160px; overflow-y: auto; line-height: 1.55;"></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal('taskModal')">Cancel</button>
                    <button type="submit" class="btn-modal-submit">
                        Save Status Update
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Interactive Filtering & Modal Logic -->
    <script>
        let currentFilter = 'all';

        function openTaskModal(data) {
            document.getElementById('modal_task_id').value = data.id;
            document.getElementById('modal_task_title').textContent = data.title;
            document.getElementById('modal_due_info').textContent = 'Due: ' + data.due_badge + (data.assigned ? ' • Assigned on ' + data.assigned : '');
            document.getElementById('modal_task_desc').textContent = data.description || 'No additional specifications provided.';
            
            // Priority badge
            const pBadge = document.getElementById('modal_priority_badge');
            pBadge.textContent = data.priority_lbl;
            pBadge.className = 'priority-badge';
            if (data.priority === 'critical') {
                pBadge.style.color = '#ef4444';
                pBadge.style.background = 'rgba(239, 68, 68, 0.12)';
            } else if (data.priority === 'high') {
                pBadge.style.color = '#f97316';
                pBadge.style.background = 'rgba(249, 115, 22, 0.12)';
            } else if (data.priority === 'low') {
                pBadge.style.color = '#10b981';
                pBadge.style.background = 'rgba(16, 185, 129, 0.12)';
            } else {
                pBadge.style.color = '#3b82f6';
                pBadge.style.background = 'rgba(59, 130, 246, 0.12)';
            }

            // Select active status
            const select = document.getElementById('modal_status_select');
            if (select) {
                select.value = data.status;
            }

            document.getElementById('taskModal').style.display = 'flex';
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.style.display = 'none';
            }
        }

        function handleOverlayClick(event, id) {
            if (event.target === document.getElementById(id)) {
                closeModal(id);
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('taskModal');
            }
        });

        // Filter tabs logic
        function applyFilter(filter, btn) {
            currentFilter = filter;
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            if (btn) {
                btn.classList.add('active');
            } else {
                const target = document.querySelector(`.filter-tab[data-filter="${filter}"]`);
                if (target) target.classList.add('active');
            }
            filterTaskCards();
        }

        function resetFilters() {
            currentFilter = 'all';
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            const allBtn = document.querySelector('.filter-tab[data-filter="all"]');
            if (allBtn) allBtn.classList.add('active');
            
            const search = document.getElementById('taskSearchInput');
            if (search) search.value = '';

            const pFilter = document.getElementById('priorityFilter');
            if (pFilter) pFilter.value = 'all';

            filterTaskCards();
        }

        function filterTaskCards() {
            const query = (document.getElementById('taskSearchInput')?.value || '').toLowerCase().trim();
            const priorityVal = document.getElementById('priorityFilter')?.value || 'all';
            const cards = document.querySelectorAll('.task-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const cardGroup     = card.getAttribute('data-filter-group');
                const cardPriority  = card.getAttribute('data-priority') || '';
                const cardOverdue   = card.getAttribute('data-is-overdue') === '1';
                const cardTitle     = card.getAttribute('data-title') || '';
                const cardDesc      = card.getAttribute('data-desc') || '';

                // Filter Tab Match
                let matchesTab = true;
                if (currentFilter === 'active') {
                    matchesTab = (cardGroup === 'active');
                } else if (currentFilter === 'verify') {
                    matchesTab = (cardGroup === 'verify');
                } else if (currentFilter === 'completed') {
                    matchesTab = (cardGroup === 'completed');
                } else if (currentFilter === 'overdue') {
                    matchesTab = cardOverdue;
                }

                // Priority Filter Match
                let matchesPriority = (priorityVal === 'all') || (cardPriority === priorityVal);

                // Search Query Match
                let matchesSearch = true;
                if (query.length > 0) {
                    matchesSearch = cardTitle.includes(query) || cardDesc.includes(query);
                }

                if (matchesTab && matchesPriority && matchesSearch) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            const noResults = document.getElementById('noResultsState');
            if (noResults) {
                noResults.style.display = (visibleCount === 0 && cards.length > 0) ? 'block' : 'none';
            }
        }
    </script>
</body>
</html>
