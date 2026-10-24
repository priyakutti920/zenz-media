<?php
/**
 * Cloud Aspire - Employee Leave Management Terminal
 * Modern, Enterprise SaaS Leave Requests, LV-Code Audit, and Entitlement Tracking
 */

// Include database connection and security modules
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

// Supported Leave Types matching database enum('annual','sick','casual','early_departure')
$leaveTypes = [
    'casual' => [
        'label' => 'Casual Leave',
        'desc'  => 'Short personal time off or family commitments',
        'icon'  => 'fa-mug-hot',
        'color' => '#0284c7',
        'bg'    => 'rgba(2, 132, 199, 0.1)',
        'border'=> 'rgba(2, 132, 199, 0.25)'
    ],
    'sick' => [
        'label' => 'Sick Leave',
        'desc'  => 'Medical recovery, health consultations or illness',
        'icon'  => 'fa-heart-pulse',
        'color' => '#e11d48',
        'bg'    => 'rgba(225, 29, 72, 0.1)',
        'border'=> 'rgba(225, 29, 72, 0.25)'
    ],
    'annual' => [
        'label' => 'Annual Leave',
        'desc'  => 'Earned planned vacations and prolonged rest',
        'icon'  => 'fa-umbrella-beach',
        'color' => '#059669',
        'bg'    => 'rgba(5, 150, 105, 0.1)',
        'border'=> 'rgba(5, 150, 105, 0.25)'
    ],
    'early_departure' => [
        'label' => 'Early Departure / Half Day',
        'desc'  => 'Leaving shift early or arriving late for urgent appointments',
        'icon'  => 'fa-person-walking-arrow-right',
        'color' => '#d97706',
        'bg'    => 'rgba(217, 119, 6, 0.1)',
        'border'=> 'rgba(217, 119, 6, 0.25)'
    ]
];

// Status Lifecycle Configurations
$statuses = [
    'pending' => [
        'label' => 'Pending Review',
        'icon'  => 'fa-hourglass-half',
        'color' => '#d97706',
        'bg'    => 'rgba(217, 119, 6, 0.12)',
        'border'=> 'rgba(217, 119, 6, 0.3)'
    ],
    'approved' => [
        'label' => 'Approved',
        'icon'  => 'fa-circle-check',
        'color' => '#059669',
        'bg'    => 'rgba(5, 150, 105, 0.12)',
        'border'=> 'rgba(5, 150, 105, 0.3)'
    ],
    'rejected' => [
        'label' => 'Rejected',
        'icon'  => 'fa-circle-xmark',
        'color' => '#e11d48',
        'bg'    => 'rgba(225, 29, 72, 0.12)',
        'border'=> 'rgba(225, 29, 72, 0.3)'
    ]
];

// -------------------------------------------------------------------
// Form Handling (POST with CSRF Protection)
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'employee_leave')) {
        $message     = 'Invalid or expired security token. Please try again.';
        $messageType = 'error';
    } 
    // Action 1: Submit New Leave Request
    elseif (isset($_POST['action']) && $_POST['action'] === 'submit_leave') {
        $leaveType = trim($_POST['leave_type'] ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date'] ?? '');
        $depTime   = trim($_POST['early_departure_time'] ?? '');
        $reason    = trim($_POST['reason'] ?? '');

        if (!array_key_exists($leaveType, $leaveTypes)) {
            $message     = 'Please select a valid leave classification.';
            $messageType = 'error';
        } elseif (empty($startDate) || empty($endDate) || empty($reason)) {
            $message     = 'Please fill in all required date and reason fields.';
            $messageType = 'error';
        } else {
            try {
                $start = new DateTime($startDate);
                $end   = new DateTime($endDate);

                if ($end < $start) {
                    $message     = 'End date cannot be prior to start date.';
                    $messageType = 'error';
                } else {
                    $days = (int)$end->diff($start)->days + 1;
                    $departureVal = ($leaveType === 'early_departure' && !empty($depTime)) ? $depTime : null;

                    $stmt = $pdo->prepare("INSERT INTO leave_requests 
                        (employee_id, leave_type, start_date, end_date, days, reason, early_departure_time, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
                    $stmt->execute([$employee_id, $leaveType, $startDate, $endDate, $days, $reason, $departureVal]);

                    // Safe Email Alert (Non-blocking)
                    try {
                        $mailer = new Mailer($pdo);
                        $typeLabel = $leaveTypes[$leaveType]['label'] ?? $leaveType;
                        $mailer->sendLeaveRequestAlert($employee_name, $typeLabel, $startDate, $endDate, $reason);
                    } catch (Throwable $mailEx) {
                        error_log("Leave request mailer error: " . $mailEx->getMessage());
                    }

                    $message     = 'Leave request for ' . $days . ' day' . ($days > 1 ? 's' : '') . ' submitted successfully. You will receive an approved LV Code upon review.';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                error_log("Leave submission error: " . $e->getMessage());
                $message     = 'Could not process leave dates. Please try again.';
                $messageType = 'error';
            }
        }
    }
    // Action 2: Cancel / Withdraw Pending Request
    elseif (isset($_POST['action']) && $_POST['action'] === 'cancel_leave') {
        $leaveId = (int)($_POST['leave_id'] ?? 0);

        if ($leaveId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM leave_requests WHERE id = ? AND employee_id = ? AND status = 'pending'");
                $stmt->execute([$leaveId, $employee_id]);

                if ($stmt->rowCount() > 0) {
                    $message     = 'Pending leave request cancelled and removed.';
                    $messageType = 'success';
                } else {
                    $message     = 'Cannot cancel this leave request. Only pending requests can be withdrawn.';
                    $messageType = 'error';
                }
            } catch (PDOException $e) {
                error_log("Cancel leave error: " . $e->getMessage());
                $message     = 'Error cancelling leave request.';
                $messageType = 'error';
            }
        }
    }
}

// -------------------------------------------------------------------
// Query Employee Leave History & Real-Time KPI Stats
// -------------------------------------------------------------------
$leaves = [];
$stats = [
    'total'         => 0,
    'approved'      => 0,
    'pending'       => 0,
    'rejected'      => 0,
    'approved_days' => 0,
];

try {
    $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->execute([$employee_id]);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['total'] = count($leaves);

    $currentYear = date('Y');
    foreach ($leaves as $lv) {
        $st = $lv['status'] ?? 'pending';
        if (isset($stats[$st])) {
            $stats[$st]++;
        }
        if ($st === 'approved' && date('Y', strtotime($lv['start_date'])) === $currentYear) {
            $stats['approved_days'] += (int)($lv['days'] ?? 0);
        }
    }
} catch (PDOException $e) {
    error_log("Fetch employee leaves error: " . $e->getMessage());
    $leaves = [];
}

// Generate CSRF token for the page forms (generated after POST)
$csrfToken = Security::generateCSRFToken('employee_leave');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Leaves &bull; Cloud Aspire Workforce Hub</title>
    <meta name="description" content="Submit time-off requests, view active leave approvals, and retrieve official LV Codes.">
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
            --lve-bg:            #f8fafc;
            --lve-surface:       #ffffff;
            --lve-surface-subtle:#f1f5f9;
            --lve-surface-card:  #ffffff;
            --lve-text:          #09090b;
            --lve-text-body:     #1e293b;
            --lve-muted:         #475569;
            --lve-faint:         #64748b;
            --lve-border:        #cbd5e1;
            --lve-border-subtle: #e2e8f0;

            --lve-brand:         #4f46e5;
            --lve-brand-hover:   #4338ca;
            --lve-brand-light:   #eef2ff;
            --lve-brand-border:  #c7d2fe;

            --lve-emerald:       #059669;
            --lve-emerald-light: #ecfdf5;
            --lve-emerald-border:rgba(5, 150, 105, 0.3);

            --lve-amber:         #d97706;
            --lve-amber-light:   #fffbeb;
            --lve-amber-border:  rgba(217, 119, 6, 0.3);

            --lve-rose:          #e11d48;
            --lve-rose-light:    #fff1f2;
            --lve-rose-border:   rgba(225, 29, 72, 0.3);

            --lve-sky:           #0284c7;
            --lve-sky-light:     #f0f9ff;
            --lve-sky-border:    rgba(2, 132, 199, 0.3);

            --lve-radius-sm:     8px;
            --lve-radius-md:     14px;
            --lve-radius-lg:     18px;
            --lve-radius-card:   22px;
            --lve-radius-pill:   9999px;

            --lve-shadow-card:   0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 8px 24px -4px rgba(15, 23, 42, 0.06);
            --lve-shadow-hover:  0 16px 36px -6px rgba(15, 23, 42, 0.12);
            --lve-transition:    0.26s cubic-bezier(0.16, 1, 0.3, 1);
            --lve-max-w:         1440px;
        }

        html[data-theme="dark"] {
            --lve-bg:            #090d16;
            --lve-surface:       #131d33;
            --lve-surface-subtle:#0f172a;
            --lve-surface-card:  #141f38;
            --lve-text:          #f8fafc;
            --lve-text-body:     #e2e8f0;
            --lve-muted:         #94a3b8;
            --lve-faint:         #64748b;
            --lve-border:        #2d3f66;
            --lve-border-subtle: #1e293b;

            --lve-brand:         #6366f1;
            --lve-brand-hover:   #4f46e5;
            --lve-brand-light:   rgba(99, 102, 241, 0.16);
            --lve-brand-border:  rgba(99, 102, 241, 0.35);

            --lve-emerald:       #10b981;
            --lve-emerald-light: rgba(16, 185, 129, 0.14);
            --lve-emerald-border:rgba(16, 185, 129, 0.3);

            --lve-amber:         #f59e0b;
            --lve-amber-light:   rgba(245, 158, 11, 0.14);
            --lve-amber-border:  rgba(245, 158, 11, 0.3);

            --lve-rose:          #f43f5e;
            --lve-rose-light:    rgba(244, 63, 94, 0.14);
            --lve-rose-border:   rgba(244, 63, 94, 0.3);

            --lve-sky:           #38bdf8;
            --lve-sky-light:     rgba(56, 189, 248, 0.14);
            --lve-sky-border:    rgba(56, 189, 248, 0.3);

            --lve-shadow-card:   0 4px 20px -2px rgba(0, 0, 0, 0.5);
            --lve-shadow-hover:  0 20px 40px -4px rgba(0, 0, 0, 0.65);
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--lve-bg);
            color: var(--lve-text-body);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            transition: background-color var(--lve-transition), color var(--lve-transition);
        }

        .hub-container {
            width: 100%;
            max-width: var(--lve-max-w);
            margin: 0 auto;
            padding: 32px 24px 64px;
            flex-grow: 1;
        }

        /* ---------------- Notifications ---------------- */
        .lve-alert {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 20px;
            border-radius: var(--lve-radius-md);
            margin-bottom: 26px;
            font-size: 0.95rem;
            font-weight: 500;
            animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .lve-alert-success {
            background: var(--lve-emerald-light);
            border: 1px solid var(--lve-emerald-border);
            color: var(--lve-emerald);
        }
        .lve-alert-error {
            background: var(--lve-rose-light);
            border: 1px solid var(--lve-rose-border);
            color: var(--lve-rose);
        }

        /* ---------------- Hero Section ---------------- */
        .hero-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            background: var(--lve-surface);
            border: 1px solid var(--lve-border);
            border-radius: var(--lve-radius-card);
            padding: 30px 36px;
            margin-bottom: 28px;
            box-shadow: var(--lve-shadow-card);
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
            color: var(--lve-text);
            margin: 0 0 6px;
            letter-spacing: -0.025em;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .hero-title i {
            color: var(--lve-brand);
            font-size: 1.65rem;
        }
        .hero-subtitle {
            font-size: 0.96rem;
            color: var(--lve-muted);
            margin: 0;
        }

        /* Hero Primary Action Button */
        .btn-submit-hero {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: #ffffff !important;
            border: none;
            padding: 13px 26px;
            border-radius: var(--lve-radius-pill);
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            box-shadow: 0 6px 18px rgba(79, 70, 229, 0.35);
            transition: all var(--lve-transition);
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

        /* ---------------- KPI Metric Cards ---------------- */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        .kpi-card {
            background: var(--lve-surface);
            border: 1px solid var(--lve-border);
            border-radius: var(--lve-radius-lg);
            padding: 22px 24px;
            display: flex;
            align-items: center;
            gap: 18px;
            box-shadow: var(--lve-shadow-card);
            transition: all var(--lve-transition);
            position: relative;
            overflow: hidden;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--lve-shadow-hover);
            border-color: var(--lve-brand-border);
        }
        .kpi-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: var(--lve-radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .kpi-indigo .kpi-icon-wrap {
            background: var(--lve-brand-light);
            color: var(--lve-brand);
        }
        .kpi-amber .kpi-icon-wrap {
            background: var(--lve-amber-light);
            color: var(--lve-amber);
        }
        .kpi-emerald .kpi-icon-wrap {
            background: var(--lve-emerald-light);
            color: var(--lve-emerald);
        }
        .kpi-rose .kpi-icon-wrap {
            background: var(--lve-rose-light);
            color: var(--lve-rose);
        }

        .kpi-val {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--lve-text);
            line-height: 1.15;
        }
        .kpi-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--lve-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 3px;
        }

        /* ---------------- Controls Bar ---------------- */
        .controls-card {
            background: var(--lve-surface);
            border: 1px solid var(--lve-border);
            border-radius: var(--lve-radius-lg);
            padding: 16px 20px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: var(--lve-shadow-card);
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
            color: var(--lve-muted);
            font-weight: 600;
            font-size: 0.88rem;
            padding: 8px 16px;
            border-radius: var(--lve-radius-pill);
            cursor: pointer;
            transition: all var(--lve-transition);
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .filter-tab:hover {
            color: var(--lve-text);
            background: var(--lve-surface-subtle);
        }
        .filter-tab.active {
            background: var(--lve-brand-light);
            color: var(--lve-brand);
            border-color: var(--lve-brand-border);
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
            border-radius: var(--lve-radius-pill);
        }
        html[data-theme="dark"] .filter-count {
            background: rgba(255, 255, 255, 0.12);
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
            color: var(--lve-faint);
            font-size: 0.9rem;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            padding: 9px 14px 9px 38px;
            border: 1px solid var(--lve-border);
            background: var(--lve-surface-subtle);
            color: var(--lve-text);
            border-radius: var(--lve-radius-pill);
            font-size: 0.88rem;
            outline: none;
            transition: all var(--lve-transition);
            font-family: inherit;
        }
        .search-input:focus {
            border-color: var(--lve-brand);
            background: var(--lve-surface);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        /* ---------------- Leaves Grid ---------------- */
        .leaves-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
            gap: 24px;
        }

        .leave-card {
            background: var(--lve-surface-card);
            border: 1px solid var(--lve-border);
            border-radius: var(--lve-radius-card);
            padding: 26px;
            box-shadow: var(--lve-shadow-card);
            display: flex;
            flex-direction: column;
            overflow-wrap: break-word;
            word-break: break-word;
            gap: 16px;
            position: relative;
            transition: all var(--lve-transition);
        }
        .leave-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--lve-shadow-hover);
        }

        .card-top-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .leave-type-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--lve-text);
            margin: 0 0 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .leave-dates-meta {
            font-size: 0.84rem;
            color: var(--lve-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: var(--lve-radius-pill);
            font-size: 0.78rem;
            font-weight: 700;
            border: 1px solid transparent;
            text-transform: capitalize;
            flex-shrink: 0;
        }

        .leave-reason-box {
            font-size: 0.91rem;
            color: var(--lve-text-body);
            background: var(--lve-surface-subtle);
            border: 1px solid var(--lve-border-subtle);
            border-radius: var(--lve-radius-md);
            padding: 14px;
            line-height: 1.6;
            margin: 0;
            flex-grow: 1;
            word-break: break-word;
        }

        /* Approved LV Code Box */
        .lv-code-card {
            background: var(--lve-brand-light);
            border: 1px dashed var(--lve-brand-border);
            border-radius: var(--lve-radius-md);
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .lv-code-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--lve-brand);
            letter-spacing: 0.05em;
            display: block;
        }
        .lv-code-number {
            font-family: 'Outfit', monospace;
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--lve-brand);
            letter-spacing: 0.08em;
            line-height: 1.2;
        }
        .btn-copy-code {
            background: var(--lve-surface);
            border: 1px solid var(--lve-border);
            color: var(--lve-brand);
            padding: 8px 14px;
            border-radius: var(--lve-radius-md);
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            transition: all var(--lve-transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .btn-copy-code:hover {
            border-color: var(--lve-brand);
            background: var(--lve-brand-light);
        }

        /* Rejection Reason Box */
        .rejection-banner {
            background: var(--lve-rose-light);
            border: 1px solid var(--lve-rose-border);
            border-radius: var(--lve-radius-md);
            padding: 12px 14px;
            font-size: 0.86rem;
            color: var(--lve-rose);
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.45;
        }

        /* Card Footer */
        .card-footer {
            border-top: 1px solid var(--lve-border-subtle);
            padding-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: auto;
        }
        .submission-time {
            font-size: 0.78rem;
            color: var(--lve-faint);
        }
        .btn-cancel-request {
            background: var(--lve-surface);
            border: 1px solid var(--lve-border);
            color: var(--lve-rose);
            padding: 7px 14px;
            border-radius: var(--lve-radius-md);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--lve-transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-cancel-request:hover {
            background: var(--lve-rose-light);
            border-color: var(--lve-rose-border);
        }

        /* ---------------- Modals ---------------- */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
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
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-dialog {
            background: var(--lve-surface);
            border: 1px solid var(--lve-border);
            border-radius: var(--lve-radius-card);
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
            border-bottom: 1px solid var(--lve-border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .modal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.28rem;
            font-weight: 700;
            color: var(--lve-text);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-close-btn {
            background: transparent;
            border: none;
            color: var(--lve-muted);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 6px;
            border-radius: var(--lve-radius-sm);
            transition: all var(--lve-transition);
        }
        .modal-close-btn:hover {
            color: var(--lve-rose);
            background: var(--lve-rose-light);
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
            color: var(--lve-text);
            margin-bottom: 7px;
        }
        .form-label span.req { color: var(--lve-rose); }
        .form-control {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid var(--lve-border);
            border-radius: var(--lve-radius-md);
            background: var(--lve-surface-subtle);
            color: var(--lve-text);
            font-size: 0.92rem;
            font-family: inherit;
            outline: none;
            transition: all var(--lve-transition);
        }
        .form-control:focus {
            border-color: var(--lve-brand);
            background: var(--lve-surface);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .days-estimate-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--lve-brand-light);
            color: var(--lve-brand);
            padding: 4px 10px;
            border-radius: var(--lve-radius-pill);
            font-size: 0.8rem;
            font-weight: 700;
            margin-top: 6px;
        }

        .modal-footer {
            padding: 18px 28px;
            border-top: 1px solid var(--lve-border-subtle);
            background: var(--lve-surface-subtle);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-modal-cancel {
            background: var(--lve-surface);
            border: 1px solid var(--lve-border);
            color: var(--lve-text-body);
            padding: 10px 18px;
            border-radius: var(--lve-radius-md);
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all var(--lve-transition);
        }
        .btn-modal-submit {
            background: var(--lve-brand);
            border: 1px solid var(--lve-brand);
            color: #ffffff;
            padding: 10px 22px;
            border-radius: var(--lve-radius-md);
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all var(--lve-transition);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .btn-modal-submit:hover {
            background: var(--lve-brand-hover);
        }

        /* ---------------- Toast Notification ---------------- */
        .lve-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #09090b;
            color: #ffffff;
            padding: 12px 20px;
            border-radius: var(--lve-radius-pill);
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
        .lve-toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        /* Empty State */
        .empty-state-box {
            background: var(--lve-surface);
            border: 2px dashed var(--lve-border);
            border-radius: var(--lve-radius-card);
            padding: 64px 32px;
            text-align: center;
            max-width: 600px;
            margin: 40px auto;
            box-shadow: var(--lve-shadow-card);
        }

        /* Responsive Breakpoints */
        @media (max-width: 1024px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .hub-container { padding: 20px 16px 48px; }
            .hero-banner { flex-direction: column; align-items: flex-start; padding: 24px; }
            .btn-submit-hero { width: 100%; justify-content: center; }
            .controls-card { flex-direction: column; align-items: stretch; }
            .search-wrap { max-width: 100%; }
            .leaves-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: 1fr; gap: 14px; }
        }
        @media (max-width: 480px) {
            .hub-container { padding: 16px 12px 48px; }
            .controls-card { padding: 12px; }
            .leave-card { padding: 16px; }
            .modal-dialog { border-radius: var(--lve-radius-lg); }
            .modal-header, .modal-body, .modal-footer { padding-left: 16px; padding-right: 16px; }
        }
    </style>
</head>
<body>

    <!-- Unified Workforce Navigation Header -->
    <?php include 'employee_header.php'; ?>

    <main class="hub-container">

        <!-- Flash Notice / Feedback Message -->
        <?php if (!empty($message)): ?>
            <div class="lve-alert lve-alert-<?php echo $messageType; ?>" role="alert">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> fa-lg"></i>
                <div><?php echo htmlspecialchars($message); ?></div>
            </div>
        <?php endif; ?>

        <!-- Hero Header -->
        <section class="hero-banner">
            <div>
                <h1 class="hero-title">
                    <i class="fas fa-calendar-xmark"></i>
                    Leave Management
                </h1>
                <p class="hero-subtitle">
                    Submit time-off requests, track supervisor approvals, and obtain official Leave Codes (LV Codes).
                </p>
            </div>
            <button class="btn-submit-hero" onclick="openLeaveModal()">
                <i class="fas fa-plus"></i>
                Request Time Off
            </button>
        </section>

        <!-- KPI Metrics Grid -->
        <section class="kpi-grid">
            <div class="kpi-card kpi-indigo">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $stats['total']; ?></div>
                    <div class="kpi-label">Total Requests</div>
                </div>
            </div>

            <div class="kpi-card kpi-emerald">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $stats['approved']; ?></div>
                    <div class="kpi-label">Approved Requests</div>
                </div>
            </div>

            <div class="kpi-card kpi-amber">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $stats['pending']; ?></div>
                    <div class="kpi-label">Pending Approval</div>
                </div>
            </div>

            <div class="kpi-card kpi-rose">
                <div class="kpi-icon-wrap">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div>
                    <div class="kpi-val"><?php echo $stats['approved_days']; ?></div>
                    <div class="kpi-label"><?php echo date('Y'); ?> Days Taken</div>
                </div>
            </div>
        </section>

        <!-- Search & Filter Controls -->
        <section class="controls-card">
            <div class="filter-tabs" role="tablist">
                <button class="filter-tab active" data-filter="all" onclick="applyFilter('all', this)">
                    <span>All</span>
                    <span class="filter-count"><?php echo $stats['total']; ?></span>
                </button>
                <button class="filter-tab" data-filter="approved" onclick="applyFilter('approved', this)">
                    <span>Approved</span>
                    <span class="filter-count"><?php echo $stats['approved']; ?></span>
                </button>
                <button class="filter-tab" data-filter="pending" onclick="applyFilter('pending', this)">
                    <span>Pending</span>
                    <span class="filter-count"><?php echo $stats['pending']; ?></span>
                </button>
                <?php if ($stats['rejected'] > 0): ?>
                    <button class="filter-tab" data-filter="rejected" onclick="applyFilter('rejected', this)">
                        <span>Rejected</span>
                        <span class="filter-count"><?php echo $stats['rejected']; ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <div class="search-wrap">
                <i class="fas fa-magnifying-glass search-icon"></i>
                <input type="text" 
                       id="leaveSearchInput" 
                       class="search-input" 
                       placeholder="Search reason, code, dates..."
                       onkeyup="filterLeaveCards()">
            </div>
        </section>

        <!-- Leaves Grid / Empty State -->
        <?php if (empty($leaves)): ?>
            <div class="empty-state-box">
                <div class="empty-icon-wrap" style="width: 80px; height: 80px; background: var(--lve-brand-light); color: var(--lve-brand); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; margin: 0 auto 20px;">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.4rem; font-weight: 700; color: var(--lve-text); margin: 0 0 8px;">No Leave Requests Filed</h3>
                <p style="font-size: 0.94rem; color: var(--lve-muted); margin: 0 0 24px; line-height: 1.5;">
                    You haven't requested any time off yet. Submit a request to obtain your supervisor's approval and official LV Code.
                </p>
                <button class="btn-submit-hero" onclick="openLeaveModal()">
                    <i class="fas fa-plus"></i>
                    Request Time Off
                </button>
            </div>
        <?php else: ?>
            <div class="leaves-grid" id="leavesContainer">
                <?php foreach ($leaves as $leave): 
                    $lId      = (int)$leave['id'];
                    $typeKey  = $leave['leave_type'] ?? 'casual';
                    $tCfg     = $leaveTypes[$typeKey] ?? $leaveTypes['casual'];
                    $stKey    = $leave['status'] ?? 'pending';
                    $sCfg     = $statuses[$stKey] ?? $statuses['pending'];

                    $startDateFmt = date('M d, Y', strtotime($leave['start_date']));
                    $endDateFmt   = date('M d, Y', strtotime($leave['end_date']));
                    $isSameDay    = ($leave['start_date'] === $leave['end_date']);
                    $dateRangeStr = $isSameDay ? $startDateFmt : ($startDateFmt . ' — ' . $endDateFmt);
                    $daysCount    = (int)($leave['days'] ?? 1);
                ?>
                    <article class="leave-card" 
                             data-id="<?php echo $lId; ?>"
                             data-status="<?php echo htmlspecialchars($stKey); ?>"
                             data-type="<?php echo htmlspecialchars($typeKey); ?>"
                             data-search="<?php echo htmlspecialchars(strtolower($tCfg['label'] . ' ' . $leave['reason'] . ' ' . ($leave['leave_code'] ?? '') . ' ' . $dateRangeStr)); ?>">
                        
                        <!-- Top Header Row: Leave Title & Status Pill -->
                        <div class="card-top-row">
                            <div>
                                <h3 class="leave-type-title" style="color: <?php echo $tCfg['color']; ?>;">
                                    <i class="fas <?php echo $tCfg['icon']; ?>"></i>
                                    <?php echo htmlspecialchars($tCfg['label']); ?>
                                </h3>
                                <div class="leave-dates-meta">
                                    <i class="far fa-calendar"></i>
                                    <span><?php echo $dateRangeStr; ?></span>
                                    <span>&bull;</span>
                                    <strong><?php echo $daysCount; ?> Day<?php echo $daysCount > 1 ? 's' : ''; ?></strong>
                                    <?php if (!empty($leave['early_departure_time'])): ?>
                                        <span>&bull;</span>
                                        <span><i class="far fa-clock"></i> Leave at <?php echo date('h:i A', strtotime($leave['early_departure_time'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <span class="status-pill" 
                                  style="color: <?php echo $sCfg['color']; ?>; background: <?php echo $sCfg['bg']; ?>; border-color: <?php echo $sCfg['border']; ?>;">
                                <i class="fas <?php echo $sCfg['icon']; ?>"></i>
                                <?php echo htmlspecialchars($sCfg['label']); ?>
                            </span>
                        </div>

                        <!-- Reason -->
                        <div class="leave-reason-box">
                            <strong>Reason:</strong> <?php echo nl2br(htmlspecialchars($leave['reason'])); ?>
                        </div>

                        <!-- Approved Leave Code (If Approved) -->
                        <?php if ($stKey === 'approved' && !empty($leave['leave_code'])): ?>
                            <div class="lv-code-card">
                                <div>
                                    <span class="lv-code-label">Approved Leave Code</span>
                                    <div class="lv-code-number"><?php echo htmlspecialchars($leave['leave_code']); ?></div>
                                </div>
                                <button type="button" 
                                        class="btn-copy-code" 
                                        onclick="copyCodeToClipboard('<?php echo htmlspecialchars($leave['leave_code']); ?>', this)">
                                    <i class="far fa-copy"></i> Copy Code
                                </button>
                            </div>
                        <?php endif; ?>

                        <!-- Rejection Reason (If Rejected) -->
                        <?php if ($stKey === 'rejected' && !empty($leave['rejection_reason'])): ?>
                            <div class="rejection-banner">
                                <i class="fas fa-circle-exclamation" style="margin-top: 2px;"></i>
                                <div>
                                    <strong>Supervisor Remarks:</strong>
                                    <div><?php echo htmlspecialchars($leave['rejection_reason']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Footer -->
                        <div class="card-footer">
                            <span class="submission-time">
                                Requested on <?php echo date('M d, Y h:i A', strtotime($leave['created_at'])); ?>
                            </span>

                            <?php if ($stKey === 'pending'): ?>
                                <form method="POST" action="employee_leave" style="margin:0;" onsubmit="return confirm('Withdraw this pending leave request?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="cancel_leave">
                                    <input type="hidden" name="leave_id" value="<?php echo $lId; ?>">
                                    <button type="submit" class="btn-cancel-request">
                                        <i class="far fa-trash-can"></i> Cancel
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Zero Results Search Match -->
            <div id="noResultsState" class="empty-state-box" style="display: none;">
                <div class="empty-icon-wrap" style="width: 70px; height: 70px; background: var(--lve-surface-subtle); color: var(--lve-muted); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 16px;">
                    <i class="fas fa-magnifying-glass"></i>
                </div>
                <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.3rem; font-weight: 700; color: var(--lve-text); margin: 0 0 8px;">No Matching Leave Requests</h3>
                <p style="font-size: 0.92rem; color: var(--lve-muted); margin: 0 0 16px;">Try clearing your search query or selecting a different status filter.</p>
                <button type="button" class="btn-modal-cancel" onclick="resetFilters()">Reset All Filters</button>
            </div>
        <?php endif; ?>

    </main>

    <!-- ============================================== -->
    <!-- Submit Leave Request Modal                     -->
    <!-- ============================================== -->
    <div class="modal-overlay" id="leaveModal" onclick="handleOverlayClick(event, 'leaveModal')">
        <div class="modal-dialog" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-calendar-plus"></i>
                    Request Time Off
                </h2>
                <button type="button" class="modal-close-btn" onclick="closeModal('leaveModal')">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="employee_leave" onsubmit="return validateLeaveForm()">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="submit_leave">

                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="lve_type">Leave Classification <span class="req">*</span></label>
                        <select name="leave_type" id="lve_type" class="form-control" required onchange="handleTypeChange(this.value)">
                            <?php foreach ($leaveTypes as $k => $info): ?>
                                <option value="<?php echo $k; ?>">
                                    <?php echo htmlspecialchars($info['label']); ?> &bull; <?php echo htmlspecialchars($info['desc']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="lve_start">From Date <span class="req">*</span></label>
                            <input type="date" 
                                   name="start_date" 
                                   id="lve_start" 
                                   class="form-control" 
                                   required 
                                   value="<?php echo date('Y-m-d'); ?>"
                                   onchange="recalcDays()">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="lve_end">To Date <span class="req">*</span></label>
                            <input type="date" 
                                   name="end_date" 
                                   id="lve_end" 
                                   class="form-control" 
                                   required 
                                   value="<?php echo date('Y-m-d'); ?>"
                                   onchange="recalcDays()">
                        </div>
                    </div>

                    <!-- Calculated Days Preview -->
                    <div style="margin-bottom: 16px;">
                        <span id="daysCounterPill" class="days-estimate-pill">
                            <i class="far fa-calendar-check"></i> Total Duration: 1 Day
                        </span>
                    </div>

                    <!-- Early Departure Time Field (Conditional) -->
                    <div class="form-group" id="departureTimeWrap" style="display: none;">
                        <label class="form-label" for="lve_dep_time">Early Departure Time <span class="req">*</span></label>
                        <input type="time" name="early_departure_time" id="lve_dep_time" class="form-control" value="15:00">
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="lve_reason">Reason for Leave <span class="req">*</span></label>
                        <textarea name="reason" 
                                  id="lve_reason" 
                                  class="form-control" 
                                  rows="3" 
                                  required 
                                  placeholder="Provide clear details regarding your absence..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal('leaveModal')">Cancel</button>
                    <button type="submit" class="btn-modal-submit">
                        <i class="fas fa-paper-plane" style="margin-right: 6px;"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="lveToast" class="lve-toast">
        <i class="fas fa-circle-check" style="color: #10b981;"></i>
        <span id="lveToastMsg">Code copied to clipboard!</span>
    </div>

    <!-- Client-Side Interactivity -->
    <script>
        let currentFilter = 'all';

        function openLeaveModal() {
            document.getElementById('leaveModal').style.display = 'flex';
            setTimeout(() => document.getElementById('lve_type').focus(), 50);
            recalcDays();
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.style.display = 'none';
        }

        function handleOverlayClick(event, id) {
            if (event.target === document.getElementById(id)) {
                closeModal(id);
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal('leaveModal');
        });

        function handleTypeChange(val) {
            const depWrap = document.getElementById('departureTimeWrap');
            if (val === 'early_departure') {
                depWrap.style.display = 'block';
            } else {
                depWrap.style.display = 'none';
            }
            recalcDays();
        }

        function recalcDays() {
            const startVal = document.getElementById('lve_start')?.value;
            const endVal = document.getElementById('lve_end')?.value;
            const counter = document.getElementById('daysCounterPill');
            const type = document.getElementById('lve_type')?.value;

            if (!startVal || !endVal) return;

            const start = new Date(startVal);
            const end = new Date(endVal);

            if (end < start) {
                counter.style.background = 'var(--lve-rose-light)';
                counter.style.color = 'var(--lve-rose)';
                counter.innerHTML = '<i class="fas fa-triangle-exclamation"></i> End date cannot precede start date';
                return;
            }

            const diffTime = Math.abs(end - start);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

            counter.style.background = 'var(--lve-brand-light)';
            counter.style.color = 'var(--lve-brand)';
            counter.innerHTML = `<i class="far fa-calendar-check"></i> Total Duration: ${diffDays} Day${diffDays > 1 ? 's' : ''}`;
        }

        function validateLeaveForm() {
            const startVal = document.getElementById('lve_start')?.value;
            const endVal = document.getElementById('lve_end')?.value;
            if (startVal && endVal && new Date(endVal) < new Date(startVal)) {
                alert('End date cannot precede start date.');
                return false;
            }
            return true;
        }

        function copyCodeToClipboard(code, btn) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(showToast).catch(() => fallbackCopy(code));
            } else {
                fallbackCopy(code);
            }
        }

        function fallbackCopy(text) {
            const temp = document.createElement('input');
            temp.value = text;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
            showToast();
        }

        function showToast() {
            const toast = document.getElementById('lveToast');
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2400);
        }

        function applyFilter(filter, btn) {
            currentFilter = filter;
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            if (btn) btn.classList.add('active');
            else {
                const target = document.querySelector(`.filter-tab[data-filter="${filter}"]`);
                if (target) target.classList.add('active');
            }
            filterLeaveCards();
        }

        function resetFilters() {
            currentFilter = 'all';
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            const allBtn = document.querySelector('.filter-tab[data-filter="all"]');
            if (allBtn) allBtn.classList.add('active');
            const search = document.getElementById('leaveSearchInput');
            if (search) search.value = '';
            filterLeaveCards();
        }

        function filterLeaveCards() {
            const query = (document.getElementById('leaveSearchInput')?.value || '').toLowerCase().trim();
            const cards = document.querySelectorAll('.leave-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const status = card.getAttribute('data-status') || '';
                const searchTxt = card.getAttribute('data-search') || '';

                let matchesFilter = (currentFilter === 'all') || (status === currentFilter);
                let matchesSearch = (query.length === 0) || searchTxt.includes(query);

                if (matchesFilter && matchesSearch) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            const noRes = document.getElementById('noResultsState');
            if (noRes) noRes.style.display = (visibleCount === 0 && cards.length > 0) ? 'block' : 'none';
        }
    </script>
</body>
</html>
