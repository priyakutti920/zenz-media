<?php
// Include database connection first
require_once 'app/db.php';

// Initialize security utilities
require_once 'app/security.php';
require_once 'app/Mailer.php';
Security::init($pdo);
Security::initSecureSession();

// Initialize variables
$error = '';
$success = '';

// Check if already logged in
if (isset($_SESSION['employee_logged_in']) && $_SESSION['employee_logged_in'] === true) {
    header("Location: employee_dashboard");
    exit();
}

// Initialize rate limiting table
Security::createRateLimitTable();

// Process login form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'login')) {
        $error = "Invalid security token. Please refresh the page and try again.";
    } else {
        $employee_email = trim($_POST['email']);
        $employee_password = trim($_POST['password']);

        // Validate input
        if (empty($employee_email) || empty($employee_password)) {
            $error = "Please enter both email and password.";
        } else {
            // Check rate limiting
            $rateLimit = Security::checkLoginRateLimit();
            if (!$rateLimit['allowed']) {
                $error = $rateLimit['message'];
                Security::recordFailedLogin();
            } else {
                try {
                    // Prepare and execute query
                    $stmt = $pdo->prepare("SELECT id, employee_id, first_name, last_name, email, password, status FROM employees WHERE email = ?");
                    $stmt->execute([$employee_email]);
                    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($employee && password_verify($employee_password, $employee['password'])) {
                        if ($employee['status'] !== 'active') {
                            $error = "Your account is not active. Please contact the administrator.";
                        } else {
                            // Clear failed login attempts on success
                            Security::clearFailedLogins();

                            // Regenerate session ID to prevent fixation
                            Security::regenerateSession();

                            // Set session data
                            $_SESSION['employee_logged_in'] = true;
                            $_SESSION['employee_id'] = $employee['id'];
                            $_SESSION['employee_code'] = !empty($employee['employee_id']) 
                                ? trim($employee['employee_id']) 
                                : ('EMP-' . str_pad($employee['id'], 3, '0', STR_PAD_LEFT));
                            $_SESSION['employee_name'] = $employee['first_name'] . ' ' . $employee['last_name'];
                            $_SESSION['employee_email'] = $employee['email'];
                            $_SESSION['login_time'] = time();

                            Security::logAudit(null, 'employee_login_success', 'employees', (int)$employee['id'], ['email' => $employee['email'], 'ip' => Security::getClientIp()]);

                            // Send Login Alert Email safely (fail-fast without blocking user login)
                            try {
                                $mailer = new Mailer($pdo);
                                $mailer->sendEmployeeLoginAlert(
                                    $employee['email'],
                                    $_SESSION['employee_name'],
                                    $_SERVER['REMOTE_ADDR'] ?? '',
                                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                                );
                            } catch (Throwable $mailEx) {
                                error_log("Employee login alert email failed: " . $mailEx->getMessage());
                            }

                            // Redirect to employee dashboard
                            header("Location: employee_dashboard");
                            exit();
                        }
                    } else {
                        $error = "Invalid email or password.";
                        Security::recordFailedLogin();
                        Security::logAudit(null, 'employee_login_failed', 'employees', null, ['email' => $employee_email, 'ip' => Security::getClientIp()]);
                    }
                } catch (PDOException $e) {
                    error_log("Login error: " . $e->getMessage());
                    $error = "An error occurred. Please try again later.";
                }
            }
        }
    }
}

// Generate CSRF token for the form
$csrfToken = Security::generateCSRFToken('login');

// Fetch site branding
$siteName = 'Cloud Aspire'; 
$siteLogo = null;
$siteFavicon = null;

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->query("SELECT site_name, logo, favicon FROM site_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($settings) {
            $siteName = !empty($settings['site_name']) ? $settings['site_name'] : $siteName;
            $siteLogo = !empty($settings['logo']) ? 'assets/uploads/site/' . $settings['logo'] : null;
            $siteFavicon = !empty($settings['favicon']) ? 'assets/uploads/site/' . $settings['favicon'] : null;
        }
    } catch (PDOException $e) {}
}

if (!$siteLogo && file_exists(__DIR__ . '/assets/uploads/site/logo-96e285f855212224d62cf669.png')) {
    $siteLogo = 'assets/uploads/site/logo-96e285f855212224d62cf669.png';
}
if (!$siteFavicon && file_exists(__DIR__ . '/assets/uploads/site/favicon-b9f474fa06bc608e0ac6586c.png')) {
    $siteFavicon = 'assets/uploads/site/favicon-b9f474fa06bc608e0ac6586c.png';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Employee Login &bull; <?php echo htmlspecialchars($siteName); ?> Portal</title>
    <meta name="description" content="Secure Employee Portal Login for Cloud Aspire team members.">
    <?php if ($siteFavicon): ?>
        <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($siteFavicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/png" href="https://cloudaspire.in/uploads/site_favicon_1783239900.png">
    <?php endif; ?>

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

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
            --c-bg:             #ffffff;
            --c-bg-subtle:      #f8fafc;
            --c-surface:        #ffffff;
            --c-surface-glass:  rgba(255, 255, 255, 0.94);
            
            --c-text:           #09090b;
            --c-text-body:      #1e293b;
            --c-muted:          #475569;
            --c-faint:          #64748b;
            
            --c-border:         #cbd5e1;
            --c-border-subtle:  #e2e8f0;
            --c-border-hover:   #94a3b8;

            --c-brand:          #4f46e5;
            --c-brand-hover:    #4338ca;
            --c-brand-light:    #eef2ff;
            --c-brand-border:   #c7d2fe;

            --c-emerald:        #059669;
            --c-emerald-light:  #ecfdf5;

            --c-danger:         #e11d48;
            --c-danger-light:   #fff1f2;
            --c-danger-border:  #fecdd3;

            --radius-sm:        10px;
            --radius-md:        14px;
            --radius-lg:        18px;
            --radius-card:      24px;
            --radius-pill:      9999px;

            --shadow-card:      0 20px 45px -10px rgba(15, 23, 42, 0.12), 0 0 0 1px rgba(15, 23, 42, 0.05);
            --transition:       0.28s cubic-bezier(0.16, 1, 0.3, 1);
        }

        html[data-theme="dark"] {
            --c-bg:             #090d16;
            --c-bg-subtle:      #0f172a;
            --c-surface:        #131d33;
            --c-surface-glass:  rgba(19, 29, 51, 0.92);
            --c-text:           #f8fafc;
            --c-text-body:      #e2e8f0;
            --c-muted:          #94a3b8;
            --c-faint:          #64748b;
            --c-border:         #2d3f66;
            --c-border-subtle:  #1e2a44;
            --c-border-hover:   #475569;
            --c-brand-light:    rgba(79, 70, 229, 0.18);
            --c-brand-border:   rgba(99, 102, 241, 0.35);
            --c-danger-light:   rgba(225, 29, 72, 0.15);
            --c-danger-border:  rgba(225, 29, 72, 0.35);
            --shadow-card:      0 25px 60px -10px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.08);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
            background-color: var(--c-bg);
            background-image: 
                radial-gradient(circle at 50% 10%, rgba(79, 70, 229, 0.08) 0%, transparent 60%),
                radial-gradient(var(--c-border-subtle) 1.2px, transparent 1.2px);
            background-size: 100% 100%, 28px 28px;
            color: var(--c-text-body);
            padding: 20px 16px;
            position: relative;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        /* Top Navigation Utility Bar */
        .top-utility-bar {
            width: 100%;
            max-width: 500px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 10;
        }

        .back-home-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-pill);
            color: var(--c-text-body);
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            transition: all var(--transition);
        }
        .back-home-btn:hover {
            color: var(--c-brand);
            border-color: var(--c-brand);
            transform: translateX(-2px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
        }

        /* Animated Theme Toggle Button */
        .theme-toggle-btn {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            color: var(--c-text-body);
            cursor: pointer;
            overflow: hidden;
            padding: 0;
            outline: none;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1),
                        border-color 0.3s ease,
                        box-shadow 0.3s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }
        .theme-toggle-btn:hover {
            border-color: var(--c-brand);
            transform: scale(1.08);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.22);
        }
        .theme-toggle-btn:active {
            transform: scale(0.9) rotate(-12deg);
        }
        .theme-toggle-btn i {
            position: absolute;
            font-size: 1.15rem;
            transition: transform 0.55s cubic-bezier(0.34, 1.56, 0.64, 1),
                        opacity 0.35s cubic-bezier(0.16, 1, 0.3, 1),
                        filter 0.4s ease,
                        color 0.3s ease;
            pointer-events: none;
        }
        .theme-toggle-btn .icon-sun {
            color: #f59e0b;
            transform: scale(1) rotate(0deg);
            opacity: 1;
            filter: drop-shadow(0 0 6px rgba(245, 158, 11, 0.6));
        }
        .theme-toggle-btn .icon-moon {
            color: #818cf8;
            transform: scale(0.2) rotate(-100deg);
            opacity: 0;
            filter: drop-shadow(0 0 0px transparent);
        }
        html[data-theme="dark"] .theme-toggle-btn {
            background: #131d33;
            border-color: #334155;
            box-shadow: 0 0 14px rgba(99, 102, 241, 0.25);
        }
        html[data-theme="dark"] .theme-toggle-btn:hover {
            border-color: #818cf8;
            box-shadow: 0 0 20px rgba(129, 140, 248, 0.45);
        }
        html[data-theme="dark"] .theme-toggle-btn .icon-sun {
            transform: scale(0.2) rotate(100deg);
            opacity: 0;
            filter: drop-shadow(0 0 0px transparent);
        }
        html[data-theme="dark"] .theme-toggle-btn .icon-moon {
            transform: scale(1) rotate(0deg);
            opacity: 1;
            filter: drop-shadow(0 0 8px rgba(129, 140, 248, 0.75));
        }

        /* Pulse wave animation on click */
        .theme-toggle-btn.animating::after {
            content: '';
            position: absolute;
            inset: -3px;
            border-radius: 50%;
            border: 2px solid var(--c-brand);
            animation: themePulseWave 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            pointer-events: none;
        }
        @keyframes themePulseWave {
            0% { transform: scale(0.85); opacity: 1; }
            100% { transform: scale(1.85); opacity: 0; }
        }

        /* View Transition API styles */
        ::view-transition-old(root),
        ::view-transition-new(root) {
            animation: none;
            mix-blend-mode: normal;
        }
        ::view-transition-old(root) { z-index: 1; }
        ::view-transition-new(root) { z-index: 9999; }

        body.theme-transitioning,
        body.theme-transitioning *,
        body.theme-transitioning *::before,
        body.theme-transitioning *::after {
            transition: background-color 0.45s cubic-bezier(0.22, 1, 0.36, 1),
                        border-color 0.45s cubic-bezier(0.22, 1, 0.36, 1),
                        color 0.35s ease,
                        box-shadow 0.45s ease !important;
        }

        /* Main Login Card Container */
        .login-wrapper {
            width: 100%;
            max-width: 480px;
            margin: auto;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: var(--c-surface-glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-card);
            padding: 40px 36px;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
            transition: border-color var(--transition), box-shadow var(--transition);
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4f46e5, #7c3aed, #06b6d4);
        }

        /* Brand Header */
        .brand-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .brand-logo-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
        }

        .brand-logo-img {
            max-height: 48px;
            width: auto;
            object-fit: contain;
        }

        .brand-logo-fallback {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.6rem;
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.35);
        }

        .brand-title {
            font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif;
            font-size: 1.65rem;
            font-weight: 800;
            color: var(--c-text);
            letter-spacing: -0.025em;
            margin-bottom: 6px;
        }

        .portal-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 14px;
            background: var(--c-brand-light);
            border: 1px solid var(--c-brand-border);
            border-radius: var(--radius-pill);
            color: var(--c-brand);
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--c-emerald);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25);
            animation: pulseLive 2s infinite;
        }
        @keyframes pulseLive {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.25); opacity: 0.7; }
        }

        /* High Visibility Alerts */
        .alert-banner {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            font-size: 0.9rem;
            font-weight: 500;
            line-height: 1.5;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-error {
            background: var(--c-danger-light);
            border: 1px solid var(--c-danger-border);
            color: var(--c-danger);
        }
        .alert-error i {
            font-size: 1.15rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .alert-success {
            background: var(--c-emerald-light);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--c-emerald);
        }
        .alert-success i {
            font-size: 1.15rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* Form Controls */
        .form-group {
            margin-bottom: 22px;
        }

        .form-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--c-text);
            margin-bottom: 8px;
        }

        .input-control-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon-left {
            position: absolute;
            left: 16px;
            color: var(--c-faint);
            font-size: 1.05rem;
            pointer-events: none;
            transition: color var(--transition);
        }

        .form-input {
            width: 100%;
            height: 52px;
            padding: 0 44px 0 46px;
            background: var(--c-bg-subtle);
            border: 1.5px solid var(--c-border);
            border-radius: var(--radius-md);
            color: var(--c-text);
            font-family: inherit;
            font-size: 0.96rem;
            font-weight: 500;
            outline: none;
            transition: all var(--transition);
        }

        .form-input::placeholder {
            color: var(--c-faint);
            font-weight: 400;
        }

        .form-input:hover {
            border-color: var(--c-border-hover);
        }

        .form-input:focus {
            border-color: var(--c-brand);
            background: var(--c-surface);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.16);
        }

        .form-input:focus + .input-icon-left,
        .input-control-wrap:focus-within .input-icon-left {
            color: var(--c-brand);
        }

        /* Show/Hide Password Toggle */
        .toggle-password-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--c-faint);
            cursor: pointer;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            border-radius: 8px;
            transition: color var(--transition), background-color var(--transition);
        }
        .toggle-password-btn:hover {
            color: var(--c-brand);
            background-color: var(--c-brand-light);
        }
        .toggle-password-btn:focus-visible {
            outline: 2px solid var(--c-brand);
        }

        /* Options Row: Remember & Assistance */
        .form-options-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 26px;
            font-size: 0.88rem;
            flex-wrap: wrap;
            gap: 10px;
        }

        .remember-checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: var(--c-muted);
            font-weight: 600;
            user-select: none;
        }
        .remember-checkbox-label input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: var(--c-brand);
            cursor: pointer;
            border-radius: 4px;
        }
        .remember-checkbox-label:hover {
            color: var(--c-text);
        }

        .help-link {
            color: var(--c-brand);
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: color var(--transition);
        }
        .help-link:hover {
            color: var(--c-brand-hover);
            text-decoration: underline;
        }

        /* High-Visibility Submit Button */
        .btn-submit-login {
            width: 100%;
            height: 52px;
            border-radius: var(--radius-pill);
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            color: #ffffff !important;
            border: none;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.32), 0 1px 2px rgba(0, 0, 0, 0.1);
            transition: all var(--transition);
        }

        .btn-submit-login:hover {
            background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(79, 70, 229, 0.45);
        }

        .btn-submit-login:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
        }

        .btn-submit-login.loading {
            pointer-events: none;
            opacity: 0.85;
        }

        .btn-submit-login .btn-text { display: inline-flex; align-items: center; gap: 8px; }
        .btn-submit-login .btn-spinner { display: none; }
        .btn-submit-login.loading .btn-text { display: none; }
        .btn-submit-login.loading .btn-spinner { display: flex; }

        /* Card Footer Notes */
        .card-security-badge {
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid var(--c-border);
            text-align: center;
            font-size: 0.82rem;
            color: var(--c-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .card-security-badge i {
            color: var(--c-emerald);
            font-size: 0.9rem;
        }

        /* Global Footer */
        .login-footer {
            width: 100%;
            text-align: center;
            font-size: 0.84rem;
            color: var(--c-muted);
            margin-top: 20px;
            z-index: 10;
        }
        .login-footer a {
            color: var(--c-text);
            font-weight: 600;
            text-decoration: none;
            transition: color var(--transition);
        }
        .login-footer a:hover {
            color: var(--c-brand);
        }

        /* Passkey / WebAuthn Biometric Button & Divider */
        .passkey-divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 20px 0;
            color: var(--c-faint);
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.08em;
        }
        .passkey-divider::before,
        .passkey-divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid var(--c-border);
        }
        .passkey-divider span {
            padding: 0 12px;
        }

        .btn-passkey-login {
            width: 100%;
            height: 50px;
            border-radius: var(--radius-pill);
            background: var(--c-surface);
            color: var(--c-text);
            border: 1.5px solid var(--c-border);
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all var(--transition);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        }
        .btn-passkey-login:hover {
            border-color: var(--c-brand);
            background: var(--c-brand-light);
            color: var(--c-brand);
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.18);
        }
        .btn-passkey-login:active {
            transform: translateY(0);
        }
        .btn-passkey-login i {
            font-size: 1.15rem;
            color: var(--c-brand);
        }
        .btn-passkey-login.loading {
            pointer-events: none;
            opacity: 0.8;
        }

        .passkey-status-msg {
            margin-top: 14px;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            font-size: 0.88rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.4;
        }
        .passkey-status-msg.info {
            background: var(--c-brand-light);
            border: 1px solid var(--c-brand-border);
            color: var(--c-brand);
        }
        .passkey-status-msg.error {
            background: var(--c-danger-light);
            border: 1px solid var(--c-danger-border);
            color: var(--c-danger);
        }
        .passkey-status-msg.success {
            background: var(--c-emerald-light);
            border: 1px solid rgba(5, 150, 105, 0.3);
            color: var(--c-emerald);
        }

        /* Mobile Responsiveness (< 480px) */
        @media (max-width: 480px) {
            body {
                padding: 12px 10px;
            }
            .login-card {
                padding: 28px 20px;
                border-radius: var(--radius-lg);
            }
            .brand-title {
                font-size: 1.45rem;
            }
            .form-input {
                height: 48px;
                font-size: 0.92rem;
                padding-left: 42px;
            }
            .input-icon-left {
                left: 14px;
                font-size: 0.95rem;
            }
            .btn-submit-login {
                height: 48px;
                font-size: 0.95rem;
            }
            .form-options-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .help-link {
                margin-left: 2px;
            }
        }
    </style>
</head>
<body>

    <!-- Top Utility Bar: Back to Home & Theme Mode Toggle -->
    <header class="top-utility-bar">
        <a href="index.php" class="back-home-btn" title="Return to Main Portal">
            <i class="fas fa-arrow-left"></i>
            <span>Back to Home</span>
        </a>

        <button class="theme-toggle-btn" id="themeToggle" aria-label="Toggle theme mode" title="Toggle theme">
            <i class="fas fa-sun icon-sun"></i>
            <i class="fas fa-moon icon-moon"></i>
        </button>
    </header>

    <!-- Main Centered Login Card -->
    <main class="login-wrapper">
        <div class="login-card">
            
            <!-- Brand Header -->
            <div class="brand-header">
                <div class="brand-logo-wrap">
                    <?php if ($siteLogo): ?>
                        <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="<?php echo htmlspecialchars($siteName); ?>" class="brand-logo-img">
                    <?php else: ?>
                        <div class="brand-logo-fallback">
                            <i class="fas fa-cloud-bolt"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <h1 class="brand-title"><?php echo htmlspecialchars($siteName); ?></h1>
                
                <div class="portal-badge">
                    <span class="pulse-dot"></span>
                    <span>Employee Operations Hub</span>
                </div>
            </div>

            <!-- Error Notification Banner -->
            <?php if (!empty($error)): ?>
                <div class="alert-banner alert-error" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <div>
                        <strong>Authentication Error</strong>
                        <div><?php echo Security::sanitize($error); ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Success Notification Banner -->
            <?php if (!empty($success)): ?>
                <div class="alert-banner alert-success" role="alert">
                    <i class="fas fa-circle-check"></i>
                    <div>
                        <strong>Notice</strong>
                        <div><?php echo Security::sanitize($success); ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Authentication Form -->
            <form method="POST" action="" id="loginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <!-- Email Input -->
                <div class="form-group">
                    <label for="email" class="form-label">
                        <span>Work Email</span>
                    </label>
                    <div class="input-control-wrap">
                        <i class="fas fa-envelope input-icon-left"></i>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               class="form-input" 
                               placeholder="you@cloudaspire.in" 
                               required 
                               autofocus 
                               autocomplete="email"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                </div>

                <!-- Password Input -->
                <div class="form-group">
                    <label for="password" class="form-label">
                        <span>Password</span>
                    </label>
                    <div class="input-control-wrap">
                        <i class="fas fa-lock input-icon-left"></i>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-input" 
                               placeholder="Enter your security credentials" 
                               required 
                               autocomplete="current-password">
                        <button type="button" 
                                class="toggle-password-btn" 
                                id="togglePassword" 
                                aria-label="Show password" 
                                title="Show / Hide password">
                            <i class="fas fa-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember Me & Assistance Options -->
                <div class="form-options-row">
                    <label class="remember-checkbox-label">
                        <input type="checkbox" name="remember_me" id="rememberMe">
                        <span>Remember credentials</span>
                    </label>

                    <a href="https://wa.me/918098830937?text=Hello%20Cloud%20Aspire%20Admin,%20I%20need%20assistance%20logging%20into%20my%20employee%20account." 
                       target="_blank" 
                       rel="noopener" 
                       class="help-link">
                        <i class="fas fa-circle-question"></i> Help / Support
                    </a>
                </div>

                <!-- High Visibility Action Button -->
                <button type="submit" class="btn-submit-login" id="loginBtn">
                    <span class="btn-text">
                        <i class="fas fa-arrow-right-to-bracket"></i> Sign In to Portal
                    </span>
                    <span class="btn-spinner">
                        <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                            <circle cx="4" cy="12" r="3"><animate id="a" begin="0;b.end-0.25s" attributeName="r" dur="0.75s" values="3;.2;3"/></circle>
                            <circle cx="12" cy="12" r="3"><animate begin="a.begin+0.25s" attributeName="r" dur="0.75s" values="3;.2;3"/></circle>
                            <circle cx="20" cy="12" r="3"><animate id="b" begin="a.begin+0.5s" attributeName="r" dur="0.75s" values="3;.2;3"/></circle>
                        </svg>
                    </span>
                </button>
            </form>

            <!-- Passkey / Biometric Alternative Login -->
            <div class="passkey-divider">
                <span>OR</span>
            </div>

            <button type="button" class="btn-passkey-login" id="passkeyLoginBtn">
                <i class="fas fa-fingerprint"></i>
                <span id="passkeyBtnText">Sign In with Passkey / Biometrics</span>
            </button>
            <div id="passkeyStatus" class="passkey-status-msg" style="display: none;"></div>

            <!-- Security Badge -->
            <div class="card-security-badge">
                <i class="fas fa-shield-halved"></i>
                <span>Enterprise TLS Encryption &bull; Role Protected</span>
            </div>
        </div>
    </main>

    <!-- Global Footer -->
    <footer class="login-footer">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?> Platform &bull; Puducherry, India
    </footer>

    <!-- Client-Side Interactivity -->
    <script>
        // ── Password Visibility Toggle ──
        const togglePasswordBtn = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const togglePasswordIcon = document.getElementById('togglePasswordIcon');

        togglePasswordBtn?.addEventListener('click', () => {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            togglePasswordIcon.classList.toggle('fa-eye', !isPassword);
            togglePasswordIcon.classList.toggle('fa-eye-slash', isPassword);
            togglePasswordBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });

        // ── Form Submission Loading State ──
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');

        loginForm?.addEventListener('submit', () => {
            loginBtn?.classList.add('loading');
        });

        // ── Theme Mode Toggle with Circular Reveal Animation ──
        const themeToggle = document.getElementById('themeToggle');
        const htmlRoot = document.documentElement;

        function updateThemeAria(isDark) {
            if (!themeToggle) return;
            themeToggle.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
            themeToggle.setAttribute('title', isDark ? 'Switch to light theme' : 'Switch to dark theme');
        }
        updateThemeAria(htmlRoot.getAttribute('data-theme') === 'dark');

        themeToggle?.addEventListener('click', (e) => {
            const isCurrentlyDark = htmlRoot.getAttribute('data-theme') === 'dark';
            const nextTheme = isCurrentlyDark ? 'light' : 'dark';

            themeToggle.classList.add('animating');
            setTimeout(() => themeToggle.classList.remove('animating'), 650);

            document.body.classList.add('theme-transitioning');
            setTimeout(() => document.body.classList.remove('theme-transitioning'), 650);

            const applyThemeChange = () => {
                if (nextTheme === 'dark') {
                    htmlRoot.setAttribute('data-theme', 'dark');
                    localStorage.setItem('theme', 'dark');
                } else {
                    htmlRoot.removeAttribute('data-theme');
                    localStorage.setItem('theme', 'light');
                }
                updateThemeAria(nextTheme === 'dark');
            };

            if (document.startViewTransition && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                const rect = themeToggle.getBoundingClientRect();
                const x = (e && typeof e.clientX === 'number' && e.clientX > 0) ? e.clientX : (rect.left + rect.width / 2);
                const y = (e && typeof e.clientY === 'number' && e.clientY > 0) ? e.clientY : (rect.top + rect.height / 2);

                const endRadius = Math.hypot(
                    Math.max(x, window.innerWidth - x),
                    Math.max(y, window.innerHeight - y)
                );

                const transition = document.startViewTransition(() => {
                    applyThemeChange();
                });

                transition.ready.then(() => {
                    document.documentElement.animate(
                        {
                            clipPath: [
                                `circle(0px at ${x}px ${y}px)`,
                                `circle(${endRadius}px at ${x}px ${y}px)`
                            ]
                        },
                        {
                            duration: 550,
                            easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
                            pseudoElement: '::view-transition-new(root)'
                        }
                    );
                });
            } else {
                applyThemeChange();
            }
        });
    </script>

    <!-- Passkey / WebAuthn Client Module -->
    <script src="assets/js/passkey.js"></script>
    <script>
        (function() {
            const passkeyBtn = document.getElementById('passkeyLoginBtn');
            const passkeyText = document.getElementById('passkeyBtnText');
            const passkeyStatus = document.getElementById('passkeyStatus');
            const emailInput = document.getElementById('email');

            function showPasskeyStatus(type, message, iconClass) {
                if (!passkeyStatus) return;
                passkeyStatus.className = 'passkey-status-msg ' + type;
                passkeyStatus.innerHTML = '<i class="' + (iconClass || (type === 'error' ? 'fas fa-circle-exclamation' : (type === 'success' ? 'fas fa-circle-check' : 'fas fa-spinner fa-spin'))) + '"></i><span>' + message + '</span>';
                passkeyStatus.style.display = 'flex';
            }

            function hidePasskeyStatus() {
                if (!passkeyStatus) return;
                passkeyStatus.style.display = 'none';
                passkeyStatus.innerHTML = '';
            }

            if (!Passkey.isSupported()) {
                // If WebAuthn is not supported in this browser, hide or soften button
                if (passkeyBtn) {
                    passkeyBtn.style.opacity = '0.5';
                    passkeyBtn.title = 'Passkeys are not supported in this browser or over insecure HTTP.';
                }
            }

            passkeyBtn?.addEventListener('click', async function(e) {
                e.preventDefault();
                hidePasskeyStatus();

                if (!Passkey.isSupported()) {
                    showPasskeyStatus('error', 'Passkeys are not supported by this browser. Passkeys require HTTPS or localhost.');
                    return;
                }

                const originalHtml = passkeyBtn.innerHTML;
                passkeyBtn.classList.add('loading');
                passkeyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Waiting for biometric verification...</span>';
                showPasskeyStatus('info', 'Touch your fingerprint sensor, use Face ID, Windows Hello, or your device PIN to sign in.');

                try {
                    // Pass email if user typed it, or empty for 1-click discoverable passkey
                    const enteredEmail = emailInput ? emailInput.value.trim() : '';
                    const result = await Passkey.login(enteredEmail || null);

                    showPasskeyStatus('success', result.message || 'Passkey verified! Redirecting to dashboard...');
                    passkeyBtn.innerHTML = '<i class="fas fa-circle-check"></i><span>Authenticated! Redirecting...</span>';

                    setTimeout(function() {
                        window.location.href = result.redirect || 'employee_dashboard';
                    }, 600);

                } catch (err) {
                    passkeyBtn.classList.remove('loading');
                    passkeyBtn.innerHTML = originalHtml;
                    showPasskeyStatus('error', err.message || 'Passkey login could not be completed.');
                }
            });
        })();
    </script>
</body>
</html>
