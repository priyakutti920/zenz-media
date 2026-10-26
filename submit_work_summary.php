<?php
/**
 * Daily Work Summary Submission & Automated Shift Checkout Portal
 *
 * Secure single-use expiring token flow:
 * - Validates cryptographic token hash bound to employee, attendance record, and shift date.
 * - Enforces CSRF protection, anti-replay, and server-side validation.
 * - IDOR protection against unauthorized submissions.
 * - Automatically finalizes check-out with the scheduled shift cutoff timestamp.
 * - Records actual summary submission timestamp separately.
 * - Finalizes attendance record consistently without requiring HR review.
 */

require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/security.php';
require_once __DIR__ . '/app/AttendanceHelper.php';

date_default_timezone_set('Asia/Kolkata');
Security::init($pdo);
Security::initSecureSession();

$plainToken = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$errorMessage = null;
$successData = null;
$tokenValidation = null;

// Determine if an employee is currently logged in via session
$loggedInEmployeeId = $_SESSION['employee_id'] ?? ($_SESSION['user_id'] ?? null);

// Validate Token
if (!empty($plainToken)) {
    $tokenValidation = AttendanceHelper::validateDailySummaryToken($pdo, $plainToken);
}

// Check session employee ID against token employee ID (IDOR prevention for logged-in users)
if ($tokenValidation && $tokenValidation['valid'] && !empty($loggedInEmployeeId)) {
    if ((int)$loggedInEmployeeId !== (int)$tokenValidation['employee']['id']) {
        $tokenValidation = [
            'valid' => false,
            'error' => 'Access Denied: You are currently logged in as a different user (' . htmlspecialchars($_SESSION['user_email'] ?? 'another account') . '). Please sign in with the account assigned to this work summary.'
        ];
    }
}

// Process POST Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_daily_summary') {
    $submittedToken = trim($_POST['token'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!Security::validateCSRFToken($csrfToken, 'submit_daily_summary')) {
        $errorMessage = "Security session expired. Please refresh the page and try again.";
    } elseif (empty($submittedToken)) {
        $errorMessage = "Authentication token is missing. Please open the link sent to your email.";
    } else {
        $summaryData = [
            'tasks_worked'     => $_POST['tasks_worked'] ?? '',
            'tasks_completed'  => $_POST['tasks_completed'] ?? '',
            'pending_tasks'    => $_POST['pending_tasks'] ?? '',
            'additional_notes' => $_POST['additional_notes'] ?? ''
        ];

        // Finalize automated shift checkout
        $finalizeResult = AttendanceHelper::finalizeAutomatedShiftCheckoutWithSummary(
            $pdo,
            $submittedToken,
            $summaryData,
            $loggedInEmployeeId
        );

        if ($finalizeResult['success']) {
            $successData = $finalizeResult;
            $successData['summary_inputs'] = $summaryData;
        } else {
            $errorMessage = $finalizeResult['error'];
        }
    }
}

// Re-check token state if not already finalized
if (!$successData && !empty($plainToken)) {
    $tokenValidation = AttendanceHelper::validateDailySummaryToken($pdo, $plainToken);
}

// Generate CSRF token for the form
$csrfToken = Security::generateCSRFToken('submit_daily_summary');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Work Summary & Automated Shift Checkout | Staff Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --att-bg:             #0f172a;
            --att-card-bg:        #1e293b;
            --att-card-subtle:    #182234;
            --att-border:         #334155;
            --att-text:           #f8fafc;
            --att-text-muted:     #94a3b8;
            --att-brand:          #6366f1;
            --att-brand-hover:    #4f46e5;
            --att-brand-light:    rgba(99, 102, 241, 0.15);
            --att-success:        #10b981;
            --att-success-light:  rgba(16, 185, 129, 0.15);
            --att-warning:        #f59e0b;
            --att-warning-light:  rgba(245, 158, 11, 0.15);
            --att-danger:         #ef4444;
            --att-danger-light:   rgba(239, 68, 68, 0.15);
            --att-radius:         16px;
            --att-shadow:         0 10px 25px -5px rgba(0, 0, 0, 0.4), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: var(--att-bg);
            color: var(--att-text);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 32px 16px;
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.10) 0px, transparent 50%);
        }

        .portal-wrapper {
            width: 100%;
            max-width: 780px;
            margin: 0 auto;
        }

        .brand-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: var(--att-brand-light);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 9999px;
            color: #a5b4fc;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .brand-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(1.6rem, 3.5vw, 2.2rem);
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--att-text);
        }
        .brand-header p {
            color: var(--att-text-muted);
            font-size: 0.95rem;
            margin-top: 4px;
        }

        .card {
            background: var(--att-card-bg);
            border: 1px solid var(--att-border);
            border-radius: var(--att-radius);
            box-shadow: var(--att-shadow);
            padding: 28px;
            margin-bottom: 24px;
            backdrop-filter: blur(12px);
        }

        /* ── Info Box ── */
        .info-ribbon {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            padding: 16px;
            background: var(--att-card-subtle);
            border: 1px solid var(--att-border);
            border-radius: 12px;
            margin-bottom: 24px;
        }
        .ribbon-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .ribbon-label {
            font-size: 0.75rem;
            color: var(--att-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
        }
        .ribbon-val {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--att-text);
        }

        .alert-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 10px;
            font-size: 0.92rem;
            margin-bottom: 20px;
        }
        .alert-box.info {
            background: var(--att-brand-light);
            border: 1px solid rgba(99, 102, 241, 0.4);
            color: #c7d2fe;
        }
        .alert-box.error {
            background: var(--att-danger-light);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
        }
        .alert-box.success {
            background: var(--att-success-light);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #6ee7b7;
        }

        /* ── Form Controls ── */
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--att-text);
            margin-bottom: 8px;
        }
        .form-label .badge-req {
            font-size: 0.72rem;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-req.required {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        .badge-req.optional {
            background: rgba(148, 163, 184, 0.2);
            color: var(--att-text-muted);
        }

        .form-control {
            width: 100%;
            background: var(--att-card-subtle);
            border: 1.5px solid var(--att-border);
            border-radius: 10px;
            color: var(--att-text);
            padding: 12px 14px;
            font-family: inherit;
            font-size: 0.95rem;
            line-height: 1.5;
            resize: vertical;
            min-height: 95px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--att-brand);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.25);
        }
        .form-hint {
            font-size: 0.8rem;
            color: var(--att-text-muted);
            margin-top: 6px;
        }

        .btn-submit {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 24px;
            background: var(--att-brand);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 14px 0 rgba(99, 102, 241, 0.39);
        }
        .btn-submit:hover {
            background: var(--att-brand-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
        }
        .btn-submit:active {
            transform: translateY(0);
        }

        /* ── Confirmation View ── */
        .confirmation-wrap {
            text-align: center;
            padding: 20px 0;
        }
        .conf-icon-circle {
            width: 72px;
            height: 72px;
            background: var(--att-success-light);
            border: 2px solid var(--att-success);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--att-success);
            font-size: 2rem;
            margin-bottom: 16px;
            animation: confPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes confPop {
            0% { transform: scale(0.4); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .conf-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--att-text);
            margin-bottom: 8px;
        }
        .conf-desc {
            color: var(--att-text-muted);
            font-size: 0.95rem;
            max-width: 520px;
            margin: 0 auto 24px;
        }

        .summary-card {
            background: var(--att-card-subtle);
            border: 1px solid var(--att-border);
            border-radius: 12px;
            padding: 20px;
            text-align: left;
            margin-bottom: 24px;
        }
        .summary-card-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.92rem;
        }
        .summary-card-row:last-child { border-bottom: none; }
        .summary-card-row .sc-label { color: var(--att-text-muted); }
        .summary-card-row .sc-val { font-weight: 700; color: var(--att-text); }

        .btn-portal {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: var(--att-brand);
            color: #ffffff;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            transition: background 0.2s;
        }
        .btn-portal:hover { background: var(--att-brand-hover); }

        .footer-note {
            text-align: center;
            font-size: 0.8rem;
            color: var(--att-text-muted);
            margin-top: 16px;
        }
    </style>
</head>
<body>

<div class="portal-wrapper">
    <div class="brand-header">
        <div class="brand-badge">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Secure Staff Portal</span>
        </div>
        <h1>Daily Work Summary</h1>
        <p>Complete your tasks to finalize your daily shift attendance.</p>
    </div>

    <?php if ($successData): ?>
        <!-- ============================================================== -->
        <!-- SUCCESS CONFIRMATION VIEW                                      -->
        <!-- ============================================================== -->
        <div class="card">
            <div class="confirmation-wrap">
                <div class="conf-icon-circle">
                    <i class="fa-solid fa-check"></i>
                </div>
                <h2 class="conf-title">Daily Attendance Completed</h2>
                <p class="conf-desc">
                    Your daily work summary has been submitted successfully. Your attendance for this shift has been automatically finalized using your scheduled shift checkout time.
                </p>

                <div class="summary-card">
                    <div class="summary-card-row">
                        <span class="sc-label"><i class="fa-regular fa-calendar"></i> Shift Date</span>
                        <span class="sc-val"><?php echo htmlspecialchars(date('D, M j, Y', strtotime($successData['date']))); ?></span>
                    </div>
                    <div class="summary-card-row">
                        <span class="sc-label"><i class="fa-solid fa-right-to-bracket"></i> Recorded Check-in</span>
                        <span class="sc-val"><?php echo htmlspecialchars(date('h:i A', strtotime($successData['check_in']))); ?></span>
                    </div>
                    <div class="summary-card-row">
                        <span class="sc-label"><i class="fa-solid fa-right-from-bracket"></i> Finalized Check-out</span>
                        <span class="sc-val" style="color:var(--att-success);">
                            <?php echo htmlspecialchars(date('h:i A', strtotime($successData['check_out']))); ?>
                            <span style="font-size:0.8rem; background:rgba(16,185,129,0.2); padding:2px 8px; border-radius:6px; margin-left:6px;">
                                <?php echo ($successData['checkout_type'] === 'scheduled_auto') ? 'Scheduled Auto' : 'Verified'; ?>
                            </span>
                        </span>
                    </div>
                    <div class="summary-card-row">
                        <span class="sc-label"><i class="fa-solid fa-clock"></i> Working Hours</span>
                        <span class="sc-val"><?php echo htmlspecialchars($successData['working_hours']); ?> hrs</span>
                    </div>
                    <div class="summary-card-row">
                        <span class="sc-label"><i class="fa-solid fa-calendar-check"></i> Attendance Status</span>
                        <span class="sc-val" style="color:var(--att-success); text-transform:capitalize;">
                            <i class="fa-solid fa-circle-check"></i> Completed
                        </span>
                    </div>
                    <div class="summary-card-row">
                        <span class="sc-label"><i class="fa-solid fa-envelope-circle-check"></i> Summary Submitted At</span>
                        <span class="sc-val"><?php echo htmlspecialchars(date('M j, Y - h:i:s A', strtotime($successData['summary_submitted_at']))); ?></span>
                    </div>
                </div>

                <a href="employee_attendance" class="btn-portal">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Return to Attendance Portal</span>
                </a>
            </div>
        </div>

    <?php elseif (!$tokenValidation || !$tokenValidation['valid']): ?>
        <!-- ============================================================== -->
        <!-- INVALID / EXPIRED / ALREADY COMPLETED TOKEN VIEW                -->
        <!-- ============================================================== -->
        <div class="card" style="text-align: center; padding: 48px 24px;">
            <?php if (!empty($tokenValidation['already_completed'])): ?>
                <div style="font-size: 3rem; color: var(--att-success); margin-bottom: 16px;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h2 style="font-family:'Outfit',sans-serif; font-size:1.6rem; font-weight:800; margin-bottom:8px;">
                    Summary Already Completed
                </h2>
                <p style="color: var(--att-text-muted); max-width: 480px; margin: 0 auto 24px;">
                    The Daily Work Summary for this shift has already been submitted and your attendance has been finalized using the scheduled checkout time. No further action is required.
                </p>
            <?php elseif (!empty($tokenValidation['expired'])): ?>
                <div style="font-size: 3rem; color: var(--att-warning); margin-bottom: 16px;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <h2 style="font-family:'Outfit',sans-serif; font-size:1.6rem; font-weight:800; margin-bottom:8px;">
                    Summary Link Expired
                </h2>
                <p style="color: var(--att-text-muted); max-width: 480px; margin: 0 auto 24px;">
                    This secure summary access link has expired. Please contact your HR administrator to request a new summary link.
                </p>
            <?php else: ?>
                <div style="font-size: 3rem; color: var(--att-danger); margin-bottom: 16px;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <h2 style="font-family:'Outfit',sans-serif; font-size:1.6rem; font-weight:800; margin-bottom:8px;">
                    Access Link Invalid
                </h2>
                <p style="color: var(--att-text-muted); max-width: 480px; margin: 0 auto 24px;">
                    <?php echo htmlspecialchars($tokenValidation['error'] ?? 'A valid authentication token is required to access this page. Please use the exact link sent to your registered email address.'); ?>
                </p>
            <?php endif; ?>

            <a href="employee_attendance" class="btn-portal">
                <i class="fa-solid fa-house"></i>
                <span>Go to Staff Portal</span>
            </a>
        </div>

    <?php else: ?>
        <!-- ============================================================== -->
        <!-- ACTIVE SUMMARY SUBMISSION FORM VIEW                             -->
        <!-- ============================================================== -->
        <?php 
            $emp = $tokenValidation['employee'];
            $att = $tokenValidation['attendance'];
            $tok = $tokenValidation['token_record'];
            $schedFormatted = date('h:i A', strtotime($tok['scheduled_checkout_time']));
        ?>

        <div class="card">
            <!-- Employee & Shift Ribbon -->
            <div class="info-ribbon">
                <div class="ribbon-item">
                    <span class="ribbon-label">Employee</span>
                    <span class="ribbon-val"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></span>
                </div>
                <div class="ribbon-item">
                    <span class="ribbon-label">Shift Date</span>
                    <span class="ribbon-val"><?php echo htmlspecialchars(date('D, M j, Y', strtotime($att['date']))); ?></span>
                </div>
                <div class="ribbon-item">
                    <span class="ribbon-label">Check In Recorded</span>
                    <span class="ribbon-val"><?php echo htmlspecialchars(date('h:i A', strtotime($att['check_in']))); ?></span>
                </div>
                <div class="ribbon-item">
                    <span class="ribbon-label">Scheduled Cutoff</span>
                    <span class="ribbon-val" style="color: #a5b4fc;"><?php echo htmlspecialchars($schedFormatted); ?></span>
                </div>
            </div>

            <!-- Helpful Notice -->
            <div class="alert-box info">
                <i class="fa-solid fa-circle-info" style="font-size:1.2rem; margin-top:2px;"></i>
                <div>
                    <strong>Scheduled Checkout Rule:</strong> Once you submit this summary, your attendance for this shift will be finalized automatically using your scheduled shift cutoff time (<strong><?php echo htmlspecialchars($schedFormatted); ?></strong>). No HR review is required.
                </div>
            </div>

            <?php if (!empty($errorMessage)): ?>
                <div class="alert-box error">
                    <i class="fa-solid fa-circle-exclamation" style="font-size:1.2rem; margin-top:2px;"></i>
                    <div><?php echo htmlspecialchars($errorMessage); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="submit_work_summary" id="dailySummaryForm">
                <input type="hidden" name="action" value="submit_daily_summary">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($plainToken); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <!-- 1. Tasks Worked On (Required) -->
                <div class="form-group">
                    <label class="form-label" for="tasks_worked">
                        <span><i class="fa-solid fa-briefcase" style="color:var(--att-brand);"></i> Tasks Worked On Today</span>
                        <span class="badge-req required">Required</span>
                    </label>
                    <textarea 
                        name="tasks_worked" 
                        id="tasks_worked" 
                        class="form-control" 
                        required 
                        placeholder="Please describe the projects, tickets, client requests, or tasks you worked on during today's shift..."><?php echo htmlspecialchars($_POST['tasks_worked'] ?? ''); ?></textarea>
                    <div class="form-hint">Provide an overview of all major work items you focused on today.</div>
                </div>

                <!-- 2. Tasks Completed Today (Required) -->
                <div class="form-group">
                    <label class="form-label" for="tasks_completed">
                        <span><i class="fa-solid fa-circle-check" style="color:var(--att-success);"></i> Tasks Completed Today</span>
                        <span class="badge-req required">Required</span>
                    </label>
                    <textarea 
                        name="tasks_completed" 
                        id="tasks_completed" 
                        class="form-control" 
                        required 
                        placeholder="List specific milestones, pull requests, closed tickets, or deliverables finished today..."><?php echo htmlspecialchars($_POST['tasks_completed'] ?? ''); ?></textarea>
                    <div class="form-hint">Detail finished outcomes and completed work.</div>
                </div>

                <!-- 3. Pending Tasks (Optional) -->
                <div class="form-group">
                    <label class="form-label" for="pending_tasks">
                        <span><i class="fa-solid fa-clock-rotate-left" style="color:var(--att-warning);"></i> Pending Tasks</span>
                        <span class="badge-req optional">Optional</span>
                    </label>
                    <textarea 
                        name="pending_tasks" 
                        id="pending_tasks" 
                        class="form-control" 
                        placeholder="Any ongoing tasks, blockers, or items carrying over to your next workday..."><?php echo htmlspecialchars($_POST['pending_tasks'] ?? ''); ?></textarea>
                </div>

                <!-- 4. Additional Notes (Optional) -->
                <div class="form-group">
                    <label class="form-label" for="additional_notes">
                        <span><i class="fa-regular fa-note-sticky" style="color:#a5b4fc;"></i> Additional Notes</span>
                        <span class="badge-req optional">Optional</span>
                    </label>
                    <textarea 
                        name="additional_notes" 
                        id="additional_notes" 
                        class="form-control" 
                        placeholder="Any feedback, schedule notes, or handover comments for your team lead or manager..."><?php echo htmlspecialchars($_POST['additional_notes'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Submit Daily Summary & Finalize Checkout</span>
                </button>
            </form>
        </div>

        <p class="footer-note">
            <i class="fa-solid fa-lock"></i> Single-use cryptographic token &bull; Bound to <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?> &bull; Server Time: Asia/Kolkata
        </p>
    <?php endif; ?>
</div>

<script>
    const form = document.getElementById('dailySummaryForm');
    const submitBtn = document.getElementById('submitBtn');
    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Finalizing Checkout...';
        });
    }
</script>

</body>
</html>
