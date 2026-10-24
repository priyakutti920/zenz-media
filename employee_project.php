<?php
/**
 * Cloud Aspire - Employee Project Hub
 * Modernized, Responsive, High-Visibility Project Submission & Tracking Terminal
 */

// Include database and dependencies
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

// Status definitions with descriptive labels, icons, and themes
$statusConfig = [
    'submitted' => [
        'label' => 'Submitted',
        'icon'  => 'fa-paper-plane',
        'color' => '#0284c7',
        'bg'    => 'rgba(2, 132, 199, 0.1)',
        'border'=> 'rgba(2, 132, 199, 0.25)',
    ],
    'under_review' => [
        'label' => 'Under Review',
        'icon'  => 'fa-magnifying-glass',
        'color' => '#d97706',
        'bg'    => 'rgba(217, 119, 6, 0.1)',
        'border'=> 'rgba(217, 119, 6, 0.25)',
    ],
    'changes_requested' => [
        'label' => 'Changes Requested',
        'icon'  => 'fa-triangle-exclamation',
        'color' => '#ea580c',
        'bg'    => 'rgba(234, 88, 12, 0.12)',
        'border'=> 'rgba(234, 88, 12, 0.3)',
    ],
    'approved' => [
        'label' => 'Approved',
        'icon'  => 'fa-circle-check',
        'color' => '#059669',
        'bg'    => 'rgba(5, 150, 105, 0.1)',
        'border'=> 'rgba(5, 150, 105, 0.25)',
    ],
    'completed' => [
        'label' => 'Completed',
        'icon'  => 'fa-award',
        'color' => '#6366f1',
        'bg'    => 'rgba(99, 102, 241, 0.1)',
        'border'=> 'rgba(99, 102, 241, 0.25)',
    ],
    'on_hold' => [
        'label' => 'On Hold',
        'icon'  => 'fa-circle-pause',
        'color' => '#64748b',
        'bg'    => 'rgba(100, 116, 139, 0.1)',
        'border'=> 'rgba(100, 116, 139, 0.25)',
    ],
    'rejected' => [
        'label' => 'Rejected',
        'icon'  => 'fa-circle-xmark',
        'color' => '#e11d48',
        'bg'    => 'rgba(225, 29, 72, 0.1)',
        'border'=> 'rgba(225, 29, 72, 0.25)',
    ]
];

/**
 * Normalizes input URL by adding https:// if protocol was omitted
 */
function normalizeProjectUrl($url) {
    $url = trim($url);
    if (empty($url)) return '';
    if (!preg_match('~^(?:f|ht)tps?://~i', $url)) {
        $url = 'https://' . $url;
    }
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
}

/**
 * Human-readable relative timestamp
 */
function getRelativeTime($timestamp) {
    if (empty($timestamp)) return 'Recently';
    $time = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp);
    if (!$time) return 'Recently';
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hrs ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M d, Y', $time);
}

// ----------------------------------------------------
// Form Handling (POST with CSRF Protection)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'employee_project')) {
        $message = 'Invalid or expired security token. Please try again.';
        $messageType = 'error';
    } 
    // Action 1: Submit New Project
    elseif (isset($_POST['action']) && $_POST['action'] === 'submit_project') {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $rawLink     = trim($_POST['drive_link'] ?? '');
        $driveLink   = normalizeProjectUrl($rawLink);

        if (empty($title) || empty($rawLink)) {
            $message = 'Project title and shared link are required.';
            $messageType = 'error';
        } elseif (!$driveLink) {
            $message = 'Please provide a valid URL for the resource/drive link (e.g., https://drive.google.com/...).';
            $messageType = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('INSERT INTO projects (employee_id, title, description, drive_link, status) VALUES (?, ?, ?, ?, "submitted")');
                $stmt->execute([$employee_id, $title, $description ?: null, $driveLink]);
                $projectId = (int)$pdo->lastInsertId();

                $stmtUpdate = $pdo->prepare("INSERT INTO project_status_updates (project_id, status, note) VALUES (?, 'submitted', 'Project submitted by employee')");
                $stmtUpdate->execute([$projectId]);

                $pdo->commit();

                // Send email alert non-blocking / safe try-catch
                try {
                    $mailer = new Mailer($pdo);
                    $mailer->sendProjectSubmissionAlert($employee_name, $title, $driveLink);
                } catch (Throwable $mailEx) {
                    error_log('Project submission mailer error: ' . $mailEx->getMessage());
                }

                $message = 'Project "' . htmlspecialchars($title) . '" submitted successfully! The team has been notified.';
                $messageType = 'success';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Employee submit project error: ' . $e->getMessage());
                $message = 'Database error saving project. Please try again.';
                $messageType = 'error';
            }
        }
    }
    // Action 2: Edit / Resubmit Project (Fixes inability to revise upon changes requested)
    elseif (isset($_POST['action']) && $_POST['action'] === 'edit_project') {
        $projectId    = (int)($_POST['project_id'] ?? 0);
        $title        = trim($_POST['title'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $rawLink      = trim($_POST['drive_link'] ?? '');
        $revisionNote = trim($_POST['revision_note'] ?? '');
        $driveLink    = normalizeProjectUrl($rawLink);

        if (empty($projectId) || empty($title) || empty($rawLink)) {
            $message = 'Project ID, title, and shared link are required.';
            $messageType = 'error';
        } elseif (!$driveLink) {
            $message = 'Please provide a valid URL for the resource/drive link.';
            $messageType = 'error';
        } else {
            try {
                // Verify project ownership
                $checkStmt = $pdo->prepare("SELECT id, status, title FROM projects WHERE id = ? AND employee_id = ?");
                $checkStmt->execute([$projectId, $employee_id]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    $message = 'Project not found or access denied.';
                    $messageType = 'error';
                } elseif (in_array($existing['status'], ['approved', 'completed'], true)) {
                    $message = 'Approved or completed projects cannot be modified.';
                    $messageType = 'error';
                } else {
                    $pdo->beginTransaction();

                    // If status was changes_requested or rejected, reset to 'submitted' for fresh admin review
                    $newStatus = in_array($existing['status'], ['changes_requested', 'rejected', 'under_review']) ? 'submitted' : $existing['status'];

                    $updateStmt = $pdo->prepare("UPDATE projects SET title = ?, description = ?, drive_link = ?, status = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$title, $description ?: null, $driveLink, $newStatus, $projectId]);

                    // Log audit note
                    $logNote = !empty($revisionNote) 
                        ? 'Employee revised project: ' . $revisionNote 
                        : 'Project details and link revised by employee';
                    
                    $auditStmt = $pdo->prepare("INSERT INTO project_status_updates (project_id, status, note) VALUES (?, ?, ?)");
                    $auditStmt->execute([$projectId, $newStatus, $logNote]);

                    $pdo->commit();

                    // Trigger notification alert if resubmitted
                    try {
                        $mailer = new Mailer($pdo);
                        $mailer->sendProjectSubmissionAlert($employee_name, $title . ' (Revised)', $driveLink);
                    } catch (Throwable $mailEx) {}

                    $message = 'Project updated and resubmitted successfully.';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Employee edit project error: ' . $e->getMessage());
                $message = 'Database error updating project.';
                $messageType = 'error';
            }
        }
    }
    // Action 3: Delete Project (Only allowed when in 'submitted' status)
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_project') {
        $projectId = (int)($_POST['project_id'] ?? 0);

        if (empty($projectId)) {
            $message = 'Invalid project request.';
            $messageType = 'error';
        } else {
            try {
                // Verify project ownership and ensure it is still only 'submitted'
                $checkStmt = $pdo->prepare("SELECT id, status, title FROM projects WHERE id = ? AND employee_id = ?");
                $checkStmt->execute([$projectId, $employee_id]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    $message = 'Project not found or access denied.';
                    $messageType = 'error';
                } elseif ($existing['status'] !== 'submitted') {
                    $message = 'Only newly submitted projects that have not yet been evaluated can be deleted.';
                    $messageType = 'error';
                } else {
                    $pdo->beginTransaction();
                    $delUpdates = $pdo->prepare("DELETE FROM project_status_updates WHERE project_id = ?");
                    $delUpdates->execute([$projectId]);

                    $delProj = $pdo->prepare("DELETE FROM projects WHERE id = ?");
                    $delProj->execute([$projectId]);
                    $pdo->commit();

                    $message = 'Project "' . htmlspecialchars($existing['title']) . '" deleted successfully.';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Delete project error: ' . $e->getMessage());
                $message = 'Could not delete project.';
                $messageType = 'error';
            }
        }
    }
}

// ----------------------------------------------------
// Query Employee Projects & Full Status Timeline Updates
// ----------------------------------------------------
$projects = [];
$projectUpdates = [];
$stats = [
    'total'             => 0,
    'submitted'         => 0,
    'under_review'      => 0,
    'changes_requested' => 0,
    'approved'          => 0,
    'completed'         => 0,
    'rejected'          => 0,
    'on_hold'           => 0,
];

try {
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE employee_id = ? ORDER BY submitted_at DESC, id DESC");
    $stmt->execute([$employee_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['total'] = count($projects);

    if (!empty($projects)) {
        $projectIds = array_column($projects, 'id');
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        
        $stmtUpdates = $pdo->prepare("SELECT * FROM project_status_updates WHERE project_id IN ($placeholders) ORDER BY created_at DESC, id DESC");
        $stmtUpdates->execute($projectIds);
        while ($row = $stmtUpdates->fetch(PDO::FETCH_ASSOC)) {
            $projectUpdates[$row['project_id']][] = $row;
        }

        // Tally statistics
        foreach ($projects as $p) {
            $st = $p['status'] ?? 'submitted';
            if (isset($stats[$st])) {
                $stats[$st]++;
            }
        }
    }
} catch (PDOException $e) {
    error_log('Fetch employee projects error: ' . $e->getMessage());
}

$pendingCount = $stats['submitted'] + $stats['under_review'];
$successCount = $stats['approved'] + $stats['completed'];
$actionCount  = $stats['changes_requested'] + $stats['rejected'];

// Generate CSRF token for the page forms
$csrfToken = Security::generateCSRFToken('employee_project');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Projects &bull; Cloud Aspire Workforce Hub</title>
    <meta name="description" content="Manage and submit client deliverables, repositories, and project milestones on Cloud Aspire.">
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
            --prj-bg:            #f8fafc;
            --prj-surface:       #ffffff;
            --prj-surface-subtle:#f1f5f9;
            --prj-surface-card:  #ffffff;
            --prj-text:          #09090b;
            --prj-text-body:     #1e293b;
            --prj-muted:         #475569;
            --prj-faint:         #64748b;
            --prj-border:        #cbd5e1;
            --prj-border-subtle: #e2e8f0;

            --prj-brand:         #4f46e5;
            --prj-brand-hover:   #4338ca;
            --prj-brand-light:   #eef2ff;
            --prj-brand-border:  #c7d2fe;

            --prj-emerald:       #059669;
            --prj-emerald-light: #ecfdf5;
            --prj-emerald-border:rgba(5, 150, 105, 0.3);

            --prj-amber:         #d97706;
            --prj-amber-light:   #fffbeb;
            --prj-amber-border:  rgba(217, 119, 6, 0.3);

            --prj-rose:          #e11d48;
            --prj-rose-light:    #fff1f2;
            --prj-rose-border:   rgba(225, 29, 72, 0.3);

            --prj-sky:           #0284c7;
            --prj-sky-light:     #f0f9ff;

            --prj-radius-sm:     8px;
            --prj-radius-md:     14px;
            --prj-radius-lg:     18px;
            --prj-radius-card:   22px;
            --prj-radius-pill:   9999px;

            --prj-shadow-card:   0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 8px 24px -4px rgba(15, 23, 42, 0.06);
            --prj-shadow-hover:  0 16px 36px -6px rgba(15, 23, 42, 0.12);
            --prj-transition:    0.26s cubic-bezier(0.16, 1, 0.3, 1);
            --prj-max-w:         1440px;
        }

        html[data-theme="dark"] {
            --prj-bg:            #090d16;
            --prj-surface:       #131d33;
            --prj-surface-subtle:#0f172a;
            --prj-surface-card:  #141f38;
            --prj-text:          #f8fafc;
            --prj-text-body:     #e2e8f0;
            --prj-muted:         #94a3b8;
            --prj-faint:         #64748b;
            --prj-border:        #2d3f66;
            --prj-border-subtle: #1e293b;

            --prj-brand:         #6366f1;
            --prj-brand-hover:   #4f46e5;
            --prj-brand-light:   rgba(99, 102, 241, 0.16);
            --prj-brand-border:  rgba(99, 102, 241, 0.35);

            --prj-emerald:       #10b981;
            --prj-emerald-light: rgba(16, 185, 129, 0.14);
            --prj-emerald-border:rgba(16, 185, 129, 0.3);

            --prj-amber:         #f59e0b;
            --prj-amber-light:   rgba(245, 158, 11, 0.14);
            --prj-amber-border:  rgba(245, 158, 11, 0.3);

            --prj-rose:          #f43f5e;
            --prj-rose-light:    rgba(244, 63, 94, 0.14);
            --prj-rose-border:   rgba(244, 63, 94, 0.3);

            --prj-sky:           #38bdf8;
            --prj-sky-light:     rgba(56, 189, 248, 0.14);

            --prj-shadow-card:   0 4px 20px -2px rgba(0, 0, 0, 0.5);
            --prj-shadow-hover:  0 20px 40px -4px rgba(0, 0, 0, 0.65);
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--prj-bg);
            color: var(--prj-text-body);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            transition: background-color var(--prj-transition), color var(--prj-transition);
        }

        /* ---------------- Container ---------------- */
        .hub-container {
            width: 100%;
            max-width: var(--prj-max-w);
            margin: 0 auto;
            padding: 32px 24px 64px;
            flex-grow: 1;
        }

        /* ---------------- Notifications ---------------- */
        .prj-alert {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 20px;
            border-radius: var(--prj-radius-md);
            margin-bottom: 28px;
            font-size: 0.95rem;
            font-weight: 500;
            animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .prj-alert-success {
            background: var(--prj-emerald-light);
            border: 1px solid var(--prj-emerald-border);
            color: var(--prj-emerald);
        }
        .prj-alert-error {
            background: var(--prj-rose-light);
            border: 1px solid var(--prj-rose-border);
            color: var(--prj-rose);
        }

        /* ---------------- Hero Section ---------------- */
        .hero-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            background: var(--prj-surface);
            border: 1px solid var(--prj-border);
            border-radius: var(--prj-radius-card);
            padding: 32px 36px;
            margin-bottom: 28px;
            box-shadow: var(--prj-shadow-card);
            position: relative;
            overflow: hidden;
        }
        .hero-banner::before {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 240px;
            height: 240px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .hero-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--prj-text);
            margin: 0 0 6px;
            letter-spacing: -0.025em;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .hero-title i {
            color: var(--prj-brand);
            font-size: 1.65rem;
        }
        .hero-subtitle {
            font-size: 0.96rem;
            color: var(--prj-muted);
            margin: 0;
        }

        /* Primary Call-to-Action Button */
        .btn-submit-hero {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: #ffffff !important;
            border: none;
            padding: 13px 26px;
            border-radius: var(--prj-radius-pill);
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            box-shadow: 0 6px 18px rgba(79, 70, 229, 0.35);
            transition: all var(--prj-transition);
            white-space: nowrap;
            text-decoration: none;
        }
        .btn-submit-hero:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(79, 70, 229, 0.45);
        }
        .btn-submit-hero:active {
            transform: translateY(1px);
        }

        /* ---------------- KPI Stat Cards Grid ---------------- */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        .kpi-card {
            background: var(--prj-surface);
            border: 1px solid var(--prj-border);
            border-radius: var(--prj-radius-lg);
            padding: 22px 24px;
            display: flex;
            align-items: center;
            gap: 18px;
            box-shadow: var(--prj-shadow-card);
            transition: all var(--prj-transition);
            position: relative;
            overflow: hidden;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--prj-shadow-hover);
            border-color: var(--prj-brand-border);
        }
        .kpi-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: var(--prj-radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .kpi-indigo .kpi-icon-wrap {
            background: var(--prj-brand-light);
            color: var(--prj-brand);
        }
        .kpi-amber .kpi-icon-wrap {
            background: var(--prj-amber-light);
            color: var(--prj-amber);
        }
        .kpi-emerald .kpi-icon-wrap {
            background: var(--prj-emerald-light);
            color: var(--prj-emerald);
        }
        .kpi-rose .kpi-icon-wrap {
            background: var(--prj-rose-light);
            color: var(--prj-rose);
        }

        .kpi-val {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--prj-text);
            line-height: 1.15;
        }
        .kpi-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--prj-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 3px;
        }

        /* ---------------- Filter & Search Bar ---------------- */
        .controls-card {
            background: var(--prj-surface);
            border: 1px solid var(--prj-border);
            border-radius: var(--prj-radius-lg);
            padding: 16px 20px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: var(--prj-shadow-card);
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
            color: var(--prj-muted);
            font-weight: 600;
            font-size: 0.88rem;
            padding: 8px 16px;
            border-radius: var(--prj-radius-pill);
            cursor: pointer;
            transition: all var(--prj-transition);
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .filter-tab:hover {
            color: var(--prj-text);
            background: var(--prj-surface-subtle);
        }
        .filter-tab.active {
            background: var(--prj-brand-light);
            color: var(--prj-brand);
            border-color: var(--prj-brand-border);
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
            border-radius: var(--prj-radius-pill);
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
        .search-wrap {
            position: relative;
            width: 100%;
            max-width: 320px;
            min-width: 0;
        }
        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--prj-faint);
            font-size: 0.9rem;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            padding: 9px 14px 9px 38px;
            border: 1px solid var(--prj-border);
            background: var(--prj-surface-subtle);
            color: var(--prj-text);
            border-radius: var(--prj-radius-pill);
            font-size: 0.88rem;
            outline: none;
            transition: all var(--prj-transition);
            font-family: inherit;
        }
        .search-input:focus {
            border-color: var(--prj-brand);
            background: var(--prj-surface);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        /* ---------------- Project Cards Grid ---------------- */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
            gap: 24px;
        }

        .project-card {
            background: var(--prj-surface-card);
            border: 1px solid var(--prj-border);
            border-radius: var(--prj-radius-card);
            padding: 26px;
            box-shadow: var(--prj-shadow-card);
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: relative;
            overflow-wrap: break-word;
            word-break: break-word;
            transition: all var(--prj-transition);
        }
        .project-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--prj-shadow-hover);
            border-color: var(--prj-border);
        }

        .card-top-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }
        .card-title-wrap {
            flex-grow: 1;
        }
        .project-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.22rem;
            font-weight: 700;
            color: var(--prj-text);
            margin: 0 0 4px;
            line-height: 1.35;
            word-break: break-word;
        }
        .project-date-meta {
            font-size: 0.82rem;
            color: var(--prj-muted);
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .project-date-meta i {
            font-size: 0.75rem;
        }

        /* Status Badges */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: var(--prj-radius-pill);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: capitalize;
            letter-spacing: 0.02em;
            flex-shrink: 0;
            border: 1px solid transparent;
        }

        /* Description Box */
        .project-description {
            font-size: 0.92rem;
            color: var(--prj-text-body);
            line-height: 1.6;
            margin: 0;
            word-break: break-word;
            flex-grow: 1;
        }
        .project-description.empty {
            color: var(--prj-faint);
            font-style: italic;
        }

        /* Resource Link Box */
        .resource-box {
            background: var(--prj-surface-subtle);
            border: 1px solid var(--prj-border-subtle);
            border-radius: var(--prj-radius-md);
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .resource-info {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .resource-icon {
            color: var(--prj-brand);
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .resource-link-text {
            font-size: 0.85rem;
            color: var(--prj-text);
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-decoration: none;
            display: block;
        }
        .resource-link-text:hover {
            color: var(--prj-brand);
            text-decoration: underline;
        }
        .resource-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .btn-icon-action {
            background: var(--prj-surface);
            border: 1px solid var(--prj-border);
            color: var(--prj-muted);
            width: 32px;
            height: 32px;
            border-radius: var(--prj-radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all var(--prj-transition);
            text-decoration: none;
        }
        .btn-icon-action:hover {
            color: var(--prj-brand);
            border-color: var(--prj-brand-border);
            background: var(--prj-brand-light);
        }

        /* Review Notes & Feedback Alert */
        .feedback-banner {
            border-radius: var(--prj-radius-md);
            padding: 12px 14px;
            font-size: 0.86rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.45;
        }
        .feedback-changes {
            background: var(--prj-amber-light);
            border: 1px solid var(--prj-amber-border);
            color: var(--prj-amber);
        }
        .feedback-rejected {
            background: var(--prj-rose-light);
            border: 1px solid var(--prj-rose-border);
            color: var(--prj-rose);
        }
        .feedback-approved {
            background: var(--prj-emerald-light);
            border: 1px solid var(--prj-emerald-border);
            color: var(--prj-emerald);
        }

        /* Timeline Accordion */
        .timeline-toggle-btn {
            background: transparent;
            border: none;
            color: var(--prj-brand);
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            padding: 4px 0;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color var(--prj-transition);
        }
        .timeline-toggle-btn:hover {
            text-decoration: underline;
        }
        .timeline-container {
            display: none;
            background: var(--prj-surface-subtle);
            border: 1px dashed var(--prj-border);
            border-radius: var(--prj-radius-md);
            padding: 14px;
            margin-top: 4px;
        }
        .timeline-item {
            position: relative;
            padding-left: 20px;
            padding-bottom: 12px;
            border-left: 2px solid var(--prj-border);
        }
        .timeline-item:last-child {
            padding-bottom: 0;
            border-left: 2px solid transparent;
        }
        .timeline-dot {
            position: absolute;
            left: -6px;
            top: 2px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--prj-brand);
        }
        .timeline-time {
            font-size: 0.74rem;
            color: var(--prj-faint);
            margin-bottom: 2px;
        }
        .timeline-text {
            font-size: 0.82rem;
            color: var(--prj-text-body);
            font-weight: 500;
        }

        /* Card Action Footer */
        .card-footer {
            border-top: 1px solid var(--prj-border-subtle);
            padding-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: auto;
        }
        .btn-card-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: var(--prj-radius-md);
            font-size: 0.84rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--prj-transition);
            border: 1px solid var(--prj-border);
            background: var(--prj-surface);
            color: var(--prj-text-body);
            text-decoration: none;
        }
        .btn-card-action:hover {
            border-color: var(--prj-brand);
            color: var(--prj-brand);
            background: var(--prj-brand-light);
        }
        .btn-card-danger:hover {
            border-color: var(--prj-rose-border);
            color: var(--prj-rose);
            background: var(--prj-rose-light);
        }
        .btn-card-primary {
            background: var(--prj-brand);
            color: #ffffff !important;
            border-color: var(--prj-brand);
        }
        .btn-card-primary:hover {
            background: var(--prj-brand-hover);
        }

        /* ---------------- Empty State ---------------- */
        .empty-state-box {
            background: var(--prj-surface);
            border: 2px dashed var(--prj-border);
            border-radius: var(--prj-radius-card);
            padding: 64px 32px;
            text-align: center;
            max-width: 600px;
            margin: 40px auto;
            box-shadow: var(--prj-shadow-card);
        }
        .empty-icon-wrap {
            width: 80px;
            height: 80px;
            background: var(--prj-brand-light);
            color: var(--prj-brand);
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
            color: var(--prj-text);
            margin: 0 0 8px;
        }
        .empty-desc {
            font-size: 0.94rem;
            color: var(--prj-muted);
            margin: 0 0 24px;
            line-height: 1.5;
        }

        /* ---------------- Modal Dialogs ---------------- */
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
            background: var(--prj-surface);
            border: 1px solid var(--prj-border);
            border-radius: var(--prj-radius-card);
            width: 100%;
            max-width: 540px;
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
            border-bottom: 1px solid var(--prj-border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .modal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.28rem;
            font-weight: 700;
            color: var(--prj-text);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-title i {
            color: var(--prj-brand);
        }
        .modal-close-btn {
            background: transparent;
            border: none;
            color: var(--prj-muted);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 6px;
            border-radius: var(--prj-radius-sm);
            transition: all var(--prj-transition);
        }
        .modal-close-btn:hover {
            color: var(--prj-rose);
            background: var(--prj-rose-light);
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
            color: var(--prj-text);
            margin-bottom: 7px;
        }
        .form-label span.req {
            color: var(--prj-rose);
        }
        .form-control {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid var(--prj-border);
            border-radius: var(--prj-radius-md);
            background: var(--prj-surface-subtle);
            color: var(--prj-text);
            font-size: 0.92rem;
            font-family: inherit;
            outline: none;
            transition: all var(--prj-transition);
        }
        .form-control:focus {
            border-color: var(--prj-brand);
            background: var(--prj-surface);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        .form-helper {
            font-size: 0.78rem;
            color: var(--prj-muted);
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .modal-footer {
            padding: 18px 28px;
            border-top: 1px solid var(--prj-border-subtle);
            background: var(--prj-surface-subtle);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-modal-cancel {
            background: var(--prj-surface);
            border: 1px solid var(--prj-border);
            color: var(--prj-text-body);
            padding: 10px 18px;
            border-radius: var(--prj-radius-md);
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all var(--prj-transition);
        }
        .btn-modal-cancel:hover {
            background: var(--prj-surface-subtle);
            color: var(--prj-text);
        }
        .btn-modal-submit {
            background: var(--prj-brand);
            border: 1px solid var(--prj-brand);
            color: #ffffff;
            padding: 10px 22px;
            border-radius: var(--prj-radius-md);
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all var(--prj-transition);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .btn-modal-submit:hover {
            background: var(--prj-brand-hover);
        }
        .btn-modal-danger {
            background: var(--prj-rose);
            border-color: var(--prj-rose);
            box-shadow: 0 4px 12px rgba(225, 29, 72, 0.25);
        }
        .btn-modal-danger:hover {
            background: #be123c;
        }

        /* ---------------- Toast Notification ---------------- */
        .prj-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #09090b;
            color: #ffffff;
            padding: 12px 20px;
            border-radius: var(--prj-radius-pill);
            font-size: 0.88rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            z-index: 10000;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            pointer-events: none;
        }
        .prj-toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        /* ---------------- Responsive Rules ---------------- */
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
            .btn-submit-hero {
                width: 100%;
                justify-content: center;
            }
            .controls-card {
                flex-direction: column;
                align-items: stretch;
            }
            .search-sort-box {
                justify-content: stretch;
            }
            .search-wrap {
                max-width: 100%;
            }
            .projects-grid {
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
            .project-card {
                padding: 16px;
            }
            .modal-dialog {
                border-radius: var(--prj-radius-lg);
            }
            .modal-header, .modal-body, .modal-footer {
                padding-left: 16px;
                padding-right: 16px;
            }
        }
    </style>
</head>
<body>

    <!-- Unified Workforce Navigation Header -->
    <?php include 'employee_header.php'; ?>

    <main class="hub-container">
        
        <!-- Flash Alert Message -->
        <?php if (!empty($message)): ?>
            <div class="prj-alert prj-alert-<?php echo $messageType; ?>" role="alert">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> fa-lg"></i>
                <div><?php echo htmlspecialchars($message); ?></div>
            </div>
        <?php endif; ?>

        <!-- Hero Header -->
        <section class="hero-banner">
            <div>
                <h1 class="hero-title">
                    <i class="fas fa-folder-open"></i>
                    My Projects
                </h1>
                <p class="hero-subtitle">
                    Submit client deliverables, Google Drive repositories, and track supervisor review progress in real-time.
                </p>
            </div>
            <button class="btn-submit-hero" onclick="openSubmitModal()">
                <i class="fas fa-plus"></i>
                Submit New Project
            </button>
        </section>

        <!-- KPI Metrics Grid -->
        <section class="kpi-grid">
            <div class="kpi-card kpi-indigo">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $stats['total']; ?></div>
                    <div class="kpi-label">Total Submissions</div>
                </div>
            </div>

            <div class="kpi-card kpi-amber">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $pendingCount; ?></div>
                    <div class="kpi-label">Under Evaluation</div>
                </div>
            </div>

            <div class="kpi-card kpi-emerald">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-circle-check"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $successCount; ?></div>
                    <div class="kpi-label">Approved / Completed</div>
                </div>
            </div>

            <div class="kpi-card kpi-rose">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $actionCount; ?></div>
                    <div class="kpi-label">Revisions Requested</div>
                </div>
            </div>
        </section>

        <!-- Search & Filter Controls -->
        <section class="controls-card">
            <div class="filter-tabs" role="tablist">
                <button class="filter-tab active" data-filter="all" onclick="applyStatusFilter('all', this)">
                    <span>All</span>
                    <span class="filter-count"><?php echo $stats['total']; ?></span>
                </button>
                <button class="filter-tab" data-filter="pending" onclick="applyStatusFilter('pending', this)">
                    <span>In Review</span>
                    <span class="filter-count"><?php echo $pendingCount; ?></span>
                </button>
                <button class="filter-tab" data-filter="approved" onclick="applyStatusFilter('approved', this)">
                    <span>Approved & Done</span>
                    <span class="filter-count"><?php echo $successCount; ?></span>
                </button>
                <?php if ($actionCount > 0): ?>
                    <button class="filter-tab" data-filter="action" onclick="applyStatusFilter('action', this)">
                        <i class="fas fa-circle-exclamation" style="color: var(--prj-rose); font-size: 0.8rem;"></i>
                        <span>Needs Action</span>
                        <span class="filter-count"><?php echo $actionCount; ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <div class="search-sort-box">
                <div class="search-wrap">
                    <i class="fas fa-magnifying-glass search-icon"></i>
                    <input type="text" 
                           id="projectSearchInput" 
                           class="search-input" 
                           placeholder="Search title, description or URL..."
                           onkeyup="filterProjectCards()">
                </div>
            </div>
        </section>

        <!-- Projects Grid or Empty State -->
        <?php if (empty($projects)): ?>
            <div class="empty-state-box">
                <div class="empty-icon-wrap">
                    <i class="fas fa-folder-plus"></i>
                </div>
                <h3 class="empty-title">No Projects Submitted Yet</h3>
                <p class="empty-desc">
                    You haven't submitted any deliverables. Share your Google Drive, Figma, or repository links to submit your work for team review.
                </p>
                <button class="btn-submit-hero" onclick="openSubmitModal()">
                    <i class="fas fa-plus"></i>
                    Submit Your First Project
                </button>
            </div>
        <?php else: ?>
            <div class="projects-grid" id="projectsContainer">
                <?php foreach ($projects as $project): 
                    $pId        = (int)$project['id'];
                    $statusKey  = $project['status'] ?? 'submitted';
                    $cfg        = $statusConfig[$statusKey] ?? $statusConfig['submitted'];
                    $updates    = $projectUpdates[$pId] ?? [];
                    $dateFormatted = date('M d, Y', strtotime($project['submitted_at']));
                    $relTime    = getRelativeTime($project['submitted_at']);

                    // Filter classification
                    $filterGroup = 'pending';
                    if (in_array($statusKey, ['approved', 'completed'], true)) {
                        $filterGroup = 'approved';
                    } elseif (in_array($statusKey, ['changes_requested', 'rejected'], true)) {
                        $filterGroup = 'action';
                    } elseif ($statusKey === 'on_hold') {
                        $filterGroup = 'on_hold';
                    }

                    // Check if there is an admin review note
                    $latestReviewNote = '';
                    foreach ($updates as $u) {
                        if (!empty($u['note']) && stripos($u['note'], 'Project submitted by employee') === false) {
                            $latestReviewNote = $u['note'];
                            break;
                        }
                    }
                ?>
                    <article class="project-card" 
                             data-id="<?php echo $pId; ?>"
                             data-status="<?php echo htmlspecialchars($statusKey); ?>"
                             data-filter-group="<?php echo $filterGroup; ?>"
                             data-title="<?php echo htmlspecialchars(strtolower($project['title'])); ?>"
                             data-desc="<?php echo htmlspecialchars(strtolower($project['description'] ?? '')); ?>"
                             data-link="<?php echo htmlspecialchars(strtolower($project['drive_link'])); ?>">
                        
                        <!-- Header Row: Title & Status Badge -->
                        <div class="card-top-row">
                            <div class="card-title-wrap">
                                <h3 class="project-title"><?php echo htmlspecialchars($project['title']); ?></h3>
                                <div class="project-date-meta">
                                    <span><i class="far fa-calendar"></i> <?php echo $dateFormatted; ?></span>
                                    <span>&bull;</span>
                                    <span><i class="far fa-clock"></i> <?php echo $relTime; ?></span>
                                </div>
                            </div>
                            <span class="status-pill" 
                                  style="color: <?php echo $cfg['color']; ?>; background: <?php echo $cfg['bg']; ?>; border-color: <?php echo $cfg['border']; ?>;">
                                <i class="fas <?php echo $cfg['icon']; ?>"></i>
                                <?php echo htmlspecialchars($cfg['label']); ?>
                            </span>
                        </div>

                        <!-- Description -->
                        <p class="project-description <?php echo empty($project['description']) ? 'empty' : ''; ?>">
                            <?php echo !empty($project['description']) ? nl2br(htmlspecialchars($project['description'])) : 'No additional notes or description provided for this deliverable.'; ?>
                        </p>

                        <!-- Resource Drive Link Box -->
                        <div class="resource-box">
                            <div class="resource-info">
                                <i class="fas fa-link resource-icon"></i>
                                <div style="min-width: 0;">
                                    <a href="<?php echo htmlspecialchars($project['drive_link']); ?>" 
                                       target="_blank" 
                                       rel="noopener noreferrer" 
                                       class="resource-link-text"
                                       title="<?php echo htmlspecialchars($project['drive_link']); ?>">
                                        <?php echo htmlspecialchars($project['drive_link']); ?>
                                    </a>
                                </div>
                            </div>
                            <div class="resource-actions">
                                <button type="button" 
                                        class="btn-icon-action" 
                                        onclick="copyLinkToClipboard('<?php echo htmlspecialchars(addslashes($project['drive_link'])); ?>', this)"
                                        title="Copy Link to Clipboard">
                                    <i class="far fa-copy"></i>
                                </button>
                                <a href="<?php echo htmlspecialchars($project['drive_link']); ?>" 
                                   target="_blank" 
                                   rel="noopener noreferrer" 
                                   class="btn-icon-action" 
                                   title="Open Link in New Tab">
                                    <i class="fas fa-arrow-up-right-from-square"></i>
                                </a>
                            </div>
                        </div>

                        <!-- Admin Review Feedback Alert (If changes requested or rejected or feedback provided) -->
                        <?php if ($statusKey === 'changes_requested'): ?>
                            <div class="feedback-banner feedback-changes">
                                <i class="fas fa-triangle-exclamation" style="margin-top: 2px;"></i>
                                <div>
                                    <strong>Revisions Requested:</strong>
                                    <div><?php echo htmlspecialchars($latestReviewNote ?: 'The reviewer has requested revisions on this deliverable. Please update your file and click Resubmit.'); ?></div>
                                </div>
                            </div>
                        <?php elseif ($statusKey === 'rejected'): ?>
                            <div class="feedback-banner feedback-rejected">
                                <i class="fas fa-circle-xmark" style="margin-top: 2px;"></i>
                                <div>
                                    <strong>Submission Rejected:</strong>
                                    <div><?php echo htmlspecialchars($latestReviewNote ?: 'This project submission was not accepted. Please review supervisor feedback and submit updated work.'); ?></div>
                                </div>
                            </div>
                        <?php elseif ($statusKey === 'approved' && !empty($latestReviewNote)): ?>
                            <div class="feedback-banner feedback-approved">
                                <i class="fas fa-circle-check" style="margin-top: 2px;"></i>
                                <div>
                                    <strong>Reviewer Note:</strong>
                                    <div><?php echo htmlspecialchars($latestReviewNote); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Expandable Timeline & Review Updates -->
                        <?php if (!empty($updates)): ?>
                            <div>
                                <button type="button" 
                                        class="timeline-toggle-btn" 
                                        onclick="toggleTimeline(<?php echo $pId; ?>, this)">
                                    <i class="fas fa-list-check"></i>
                                    <span>View Audit Activity (<?php echo count($updates); ?>)</span>
                                    <i class="fas fa-chevron-down" style="font-size: 0.75rem;"></i>
                                </button>
                                
                                <div class="timeline-container" id="timeline_<?php echo $pId; ?>">
                                    <?php foreach ($updates as $up): 
                                        $upStatus = $up['status'] ?? 'submitted';
                                        $upCfg = $statusConfig[$upStatus] ?? $statusConfig['submitted'];
                                        $upTime = date('M d, Y h:i A', strtotime($up['created_at']));
                                    ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot" style="background: <?php echo $upCfg['color']; ?>;"></div>
                                            <div class="timeline-time"><?php echo $upTime; ?> &bull; <strong><?php echo htmlspecialchars($upCfg['label']); ?></strong></div>
                                            <div class="timeline-text"><?php echo htmlspecialchars($up['note'] ?: 'Status set to ' . $upCfg['label']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Actions Footer -->
                        <div class="card-footer">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <?php if (in_array($statusKey, ['submitted', 'changes_requested', 'rejected', 'under_review'], true)): ?>
                                    <button type="button" 
                                            class="btn-card-action <?php echo in_array($statusKey, ['changes_requested', 'rejected']) ? 'btn-card-primary' : ''; ?>"
                                            onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                                                'id'          => $pId,
                                                'title'       => $project['title'],
                                                'description' => $project['description'] ?? '',
                                                'drive_link'  => $project['drive_link'],
                                                'status'      => $statusKey
                                            ])); ?>)">
                                        <i class="fas <?php echo in_array($statusKey, ['changes_requested', 'rejected']) ? 'fa-rotate' : 'fa-pen-to-square'; ?>"></i>
                                        <?php echo in_array($statusKey, ['changes_requested', 'rejected']) ? 'Resubmit Deliverable' : 'Edit Submission'; ?>
                                    </button>
                                <?php else: ?>
                                    <span style="font-size: 0.84rem; color: var(--prj-emerald); font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                        <i class="fas fa-shield-check"></i> Verified & Closed
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($statusKey === 'submitted'): ?>
                                <button type="button" 
                                        class="btn-card-action btn-card-danger"
                                        onclick="openDeleteModal(<?php echo $pId; ?>, '<?php echo htmlspecialchars(addslashes($project['title'])); ?>')">
                                    <i class="far fa-trash-can"></i>
                                    Delete
                                </button>
                            <?php endif; ?>
                        </div>

                    </article>
                <?php endforeach; ?>
            </div>
            
            <!-- No Search Results Dynamic State -->
            <div id="noResultsState" class="empty-state-box" style="display: none;">
                <div class="empty-icon-wrap" style="background: var(--prj-surface-subtle); color: var(--prj-muted);">
                    <i class="fas fa-magnifying-glass"></i>
                </div>
                <h3 class="empty-title">No Matching Projects Found</h3>
                <p class="empty-desc">Try adjusting your search terms or selecting a different status filter.</p>
                <button type="button" class="btn-modal-cancel" onclick="resetFilters()">Reset All Filters</button>
            </div>
        <?php endif; ?>

    </main>

    <!-- ============================================== -->
    <!-- Submit New Project Modal                       -->
    <!-- ============================================== -->
    <div class="modal-overlay" id="submitModal" onclick="handleOverlayClick(event, 'submitModal')">
        <div class="modal-dialog" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-cloud-arrow-up"></i>
                    Submit New Project Deliverable
                </h2>
                <button type="button" class="modal-close-btn" onclick="closeModal('submitModal')">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="employee_project" onsubmit="return validateSubmissionForm(this)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="submit_project">

                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="submit_title">
                            Project Title <span class="req">*</span>
                        </label>
                        <input type="text" 
                               name="title" 
                               id="submit_title" 
                               class="form-control" 
                               required 
                               placeholder="e.g. Q3 SEO Strategy Report or Redesign Prototype"
                               maxlength="250">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="submit_link">
                            Google Drive / Resource Link <span class="req">*</span>
                        </label>
                        <input type="url" 
                               name="drive_link" 
                               id="submit_link" 
                               class="form-control" 
                               required 
                               placeholder="https://drive.google.com/..."
                               maxlength="2000">
                        <div class="form-helper">
                            <i class="fas fa-circle-info"></i>
                            Important: Set Google Drive permissions to <strong>"Anyone with the link can view"</strong>.
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="submit_desc">
                            Deliverable Summary / Remarks (Optional)
                        </label>
                        <textarea name="description" 
                                  id="submit_desc" 
                                  class="form-control" 
                                  rows="3" 
                                  placeholder="Provide brief notes, key milestones completed, or instructions for the reviewer..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal('submitModal')">Cancel</button>
                    <button type="submit" class="btn-modal-submit">
                        <i class="fas fa-paper-plane" style="margin-right: 6px;"></i> Submit for Review
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================== -->
    <!-- Edit / Resubmit Project Modal                  -->
    <!-- ============================================== -->
    <div class="modal-overlay" id="editModal" onclick="handleOverlayClick(event, 'editModal')">
        <div class="modal-dialog" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h2 class="modal-title" id="editModalTitle">
                    <i class="fas fa-pen-to-square"></i>
                    Edit Project Deliverable
                </h2>
                <button type="button" class="modal-close-btn" onclick="closeModal('editModal')">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="employee_project" onsubmit="return validateSubmissionForm(this)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="edit_project">
                <input type="hidden" name="project_id" id="edit_project_id">

                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="edit_title">
                            Project Title <span class="req">*</span>
                        </label>
                        <input type="text" 
                               name="title" 
                               id="edit_title" 
                               class="form-control" 
                               required 
                               maxlength="250">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit_link">
                            Google Drive / Resource Link <span class="req">*</span>
                        </label>
                        <input type="url" 
                               name="drive_link" 
                               id="edit_link" 
                               class="form-control" 
                               required 
                               maxlength="2000">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit_desc">
                            Deliverable Summary / Description
                        </label>
                        <textarea name="description" 
                                  id="edit_desc" 
                                  class="form-control" 
                                  rows="3"></textarea>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="edit_revision_note">
                            Revision Notes for Supervisor (Optional)
                        </label>
                        <input type="text" 
                               name="revision_note" 
                               id="edit_revision_note" 
                               class="form-control" 
                               placeholder="e.g. Updated slide 5 and granted public view permissions.">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn-modal-submit" id="editSubmitBtn">
                        Save Changes & Resubmit
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================== -->
    <!-- Delete Project Confirmation Modal              -->
    <!-- ============================================== -->
    <div class="modal-overlay" id="deleteModal" onclick="handleOverlayClick(event, 'deleteModal')">
        <div class="modal-dialog" style="max-width: 440px;" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h2 class="modal-title" style="color: var(--prj-rose);">
                    <i class="fas fa-trash-can"></i>
                    Confirm Deletion
                </h2>
                <button type="button" class="modal-close-btn" onclick="closeModal('deleteModal')">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="employee_project">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="delete_project">
                <input type="hidden" name="project_id" id="delete_project_id">

                <div class="modal-body">
                    <p style="margin: 0; font-size: 0.94rem; line-height: 1.5;">
                        Are you sure you want to delete <strong id="delete_project_title">this project</strong>? This will remove the submission and its entire audit history.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" class="btn-modal-submit btn-modal-danger">
                        Yes, Delete Project
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="prjToast" class="prj-toast">
        <i class="fas fa-circle-check" style="color: #10b981;"></i>
        <span id="prjToastMsg">Link copied to clipboard!</span>
    </div>

    <!-- Interactive Client-side Script -->
    <script>
        let currentFilter = 'all';

        function openSubmitModal() {
            document.getElementById('submitModal').style.display = 'flex';
            setTimeout(() => document.getElementById('submit_title').focus(), 50);
        }

        function openEditModal(project) {
            document.getElementById('edit_project_id').value = project.id;
            document.getElementById('edit_title').value = project.title;
            document.getElementById('edit_link').value = project.drive_link;
            document.getElementById('edit_desc').value = project.description || '';
            document.getElementById('edit_revision_note').value = '';

            const isActionReq = (project.status === 'changes_requested' || project.status === 'rejected');
            document.getElementById('editModalTitle').innerHTML = isActionReq 
                ? '<i class="fas fa-rotate" style="color: var(--prj-amber);"></i> Resubmit Project Deliverable'
                : '<i class="fas fa-pen-to-square"></i> Edit Project Submission';
            
            document.getElementById('editSubmitBtn').textContent = isActionReq 
                ? 'Save Changes & Resubmit'
                : 'Save Changes';

            document.getElementById('editModal').style.display = 'flex';
            setTimeout(() => document.getElementById('edit_title').focus(), 50);
        }

        function openDeleteModal(projectId, projectTitle) {
            document.getElementById('delete_project_id').value = projectId;
            document.getElementById('delete_project_title').textContent = '"' + projectTitle + '"';
            document.getElementById('deleteModal').style.display = 'flex';
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

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('submitModal');
                closeModal('editModal');
                closeModal('deleteModal');
            }
        });

        // Form URL Validation Helper
        function validateSubmissionForm(form) {
            const linkInput = form.querySelector('input[type="url"]');
            if (linkInput) {
                let val = linkInput.value.trim();
                if (val && !/^https?:\/\//i.test(val)) {
                    linkInput.value = 'https://' + val;
                }
            }
            return true;
        }

        // Timeline Accordion Toggle
        function toggleTimeline(id, btn) {
            const timeline = document.getElementById('timeline_' + id);
            if (!timeline) return;
            const isShown = timeline.style.display === 'block';
            timeline.style.display = isShown ? 'none' : 'block';
            
            const icon = btn.querySelector('.fa-chevron-down, .fa-chevron-up');
            if (icon) {
                icon.className = isShown ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
            }
        }

        // Copy Link with Toast Feedback
        function copyLinkToClipboard(url, btn) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(showToast).catch(() => fallbackCopy(url));
            } else {
                fallbackCopy(url);
            }
        }

        function fallbackCopy(url) {
            const temp = document.createElement('input');
            temp.value = url;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
            showToast();
        }

        function showToast() {
            const toast = document.getElementById('prjToast');
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2400);
        }

        // Filtering & Search
        function applyStatusFilter(filter, tabBtn) {
            currentFilter = filter;
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            if (tabBtn) tabBtn.classList.add('active');
            filterProjectCards();
        }

        function resetFilters() {
            currentFilter = 'all';
            const allTab = document.querySelector('.filter-tab[data-filter="all"]');
            if (allTab) {
                document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                allTab.classList.add('active');
            }
            const searchInput = document.getElementById('projectSearchInput');
            if (searchInput) searchInput.value = '';
            filterProjectCards();
        }

        function filterProjectCards() {
            const query = (document.getElementById('projectSearchInput')?.value || '').toLowerCase().trim();
            const cards = document.querySelectorAll('.project-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const cardGroup = card.getAttribute('data-filter-group');
                const cardTitle = card.getAttribute('data-title') || '';
                const cardDesc  = card.getAttribute('data-desc') || '';
                const cardLink  = card.getAttribute('data-link') || '';

                // Check filter tab
                let matchesTab = (currentFilter === 'all') || (currentFilter === cardGroup);
                
                // Check search query
                let matchesSearch = true;
                if (query.length > 0) {
                    matchesSearch = cardTitle.includes(query) || cardDesc.includes(query) || cardLink.includes(query);
                }

                if (matchesTab && matchesSearch) {
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
