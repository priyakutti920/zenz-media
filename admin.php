<?php
// Set timezone to India / Local Time
date_default_timezone_set('Asia/Kolkata');

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
if (Security::isAuthenticated()) {
    header("Location: /admin/dashboard");
    exit();
}

// Load active site settings & branding for white-label support
$activeSiteSettings = null;
try {
    $stmt = $pdo->query("SELECT site_name, logo, favicon FROM site_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1");
    $activeSiteSettings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Site settings query error: " . $e->getMessage());
}

$siteName    = !empty($activeSiteSettings['site_name']) ? $activeSiteSettings['site_name'] : 'Cloud Aspire';
$siteLogo    = !empty($activeSiteSettings['logo']) ? '/assets/uploads/site/' . rawurlencode($activeSiteSettings['logo']) : null;
$siteFavicon = !empty($activeSiteSettings['favicon']) ? '/assets/uploads/site/' . rawurlencode($activeSiteSettings['favicon']) : null;

// Initialize rate limiting table
Security::createRateLimitTable();

// Process login form
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'login')) {
        $error = "Invalid or expired security token. Please refresh the page and try again.";
    } else {
        $admin_identity = trim($_POST['username'] ?? '');
        $admin_password = trim($_POST['password'] ?? '');

        // Validate input
        if (empty($admin_identity) || empty($admin_password)) {
            $error = "Please enter both username/email and password.";
        } else {
            // Check rate limiting
            $rateLimit = Security::checkLoginRateLimit();
            if (!$rateLimit['allowed']) {
                $error = $rateLimit['message'];
                Security::recordFailedLogin();
            } else {
                try {
                    // Support login with EITHER username OR email
                    $stmt = $pdo->prepare("SELECT id, username, email, password FROM admin WHERE username = ? OR email = ? LIMIT 1");
                    $stmt->execute([$admin_identity, $admin_identity]);
                    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($admin && password_verify($admin_password, $admin['password'])) {
                        // Clear failed login attempts on success
                        Security::clearFailedLogins();

                        // Regenerate session ID to prevent fixation
                        Security::regenerateSession();

                        // Set session data
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['admin_email'] = $admin['email'] ?? '';
                        $_SESSION['login_time'] = time();

                        Security::logAudit((int)$admin['id'], 'admin_login_success', 'admin', (int)$admin['id'], ['username' => $admin['username'], 'ip' => Security::getClientIp()]);

                        // Optional: Remember identity cookie if checked
                        if (!empty($_POST['remember_me'])) {
                            setcookie('admin_remember', $admin['username'], time() + (30 * 86400), '/', '', false, true);
                        } else {
                            if (isset($_COOKIE['admin_remember'])) {
                                setcookie('admin_remember', '', time() - 3600, '/');
                            }
                        }

                        // Send Login Alert Email safely (fail-fast without blocking user)
                        try {
                            $mailer = new Mailer($pdo);
                            $mailer->sendAdminLoginAlert(
                                $admin['email'] ?? '',
                                $_SESSION['admin_username'],
                                $_SERVER['REMOTE_ADDR'] ?? '',
                                $_SERVER['HTTP_USER_AGENT'] ?? ''
                            );
                        } catch (Throwable $mailEx) {
                            error_log("Admin login alert email failed: " . $mailEx->getMessage());
                        }

                        // Redirect to admin dashboard
                        header("Location: /admin/dashboard");
                        exit();
                    } else {
                        $error = "Invalid credentials. Please verify your username or password.";
                        Security::recordFailedLogin();
                        Security::logAudit(null, 'admin_login_failed', 'admin', null, ['identity' => $admin_identity, 'ip' => Security::getClientIp()]);
                    }
                } catch (PDOException $e) {
                    error_log("Login error: " . $e->getMessage());
                    $error = "A system error occurred. Please try again shortly.";
                }
            }
        }
    }
}

// Generate CSRF token for the form
$csrfToken = Security::generateCSRFToken('login');
$rememberedUser = $_COOKIE['admin_remember'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Console &bull; <?php echo htmlspecialchars($siteName); ?> | White-Label Enterprise SaaS</title>
    <meta name="description" content="Centralized Administrator Command Center for <?php echo htmlspecialchars($siteName); ?> enterprise operations.">
    
    <?php if ($siteFavicon): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($siteFavicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/png" href="https://cloudaspire.in/uploads/site_favicon_1783239900.png">
    <?php endif; ?>

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
            --adm-bg:            #f8fafc;
            --adm-surface:       #ffffff;
            --adm-surface-subtle:#f1f5f9;
            --adm-border:        #cbd5e1;
            --adm-border-subtle: #e2e8f0;

            --adm-text-main:     #09090b;
            --adm-text-body:     #1e293b;
            --adm-muted:         #475569;
            --adm-faint:         #64748b;

            --adm-brand:         #4f46e5;
            --adm-brand-hover:   #4338ca;
            --adm-brand-light:   #eef2ff;
            --adm-brand-border:  #c7d2fe;

            --adm-success:       #059669;
            --adm-success-light: #ecfdf5;
            --adm-danger:        #e11d48;
            --adm-danger-light:  #fff1f2;
            --adm-danger-border: #fecdd3;

            --adm-radius-sm:     8px;
            --adm-radius-md:     14px;
            --adm-radius-lg:     18px;
            --adm-radius-xl:     24px;
            --adm-radius-pill:   9999px;

            --adm-shadow-card:   0 20px 40px -15px rgba(15, 23, 42, 0.08), 0 0 0 1px rgba(15, 23, 42, 0.04);
            --adm-shadow-hover:  0 25px 50px -12px rgba(79, 70, 229, 0.25);
            --adm-transition:    0.24s cubic-bezier(0.16, 1, 0.3, 1);
        }

        html[data-theme="dark"] {
            --adm-bg:            #070a13;
            --adm-surface:       #11192e;
            --adm-surface-subtle:#18233d;
            --adm-border:        #2d3f66;
            --adm-border-subtle: #1e2a44;

            --adm-text-main:     #f8fafc;
            --adm-text-body:     #e2e8f0;
            --adm-muted:         #94a3b8;
            --adm-faint:         #64748b;

            --adm-brand-light:   rgba(79, 70, 229, 0.18);
            --adm-brand-border:  rgba(99, 102, 241, 0.35);

            --adm-success-light: rgba(5, 150, 105, 0.18);
            --adm-danger-light:  rgba(225, 29, 72, 0.18);
            --adm-danger-border: rgba(225, 29, 72, 0.35);

            --adm-shadow-card:   0 25px 50px -15px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 255, 255, 0.06);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--adm-bg);
            color: var(--adm-text-body);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
            transition: background-color 0.3s ease, color 0.3s ease;
            position: relative;
            overflow-x: hidden;
        }

        /* ── Ambient Glow Background ── */
        .ambient-glow-1 {
            position: fixed;
            top: -15%;
            left: -10%;
            width: 50vw;
            height: 50vw;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(79, 70, 229, 0.14) 0%, rgba(79, 70, 229, 0) 70%);
            pointer-events: none;
            z-index: 0;
            filter: blur(80px);
        }
        .ambient-glow-2 {
            position: fixed;
            bottom: -15%;
            right: -10%;
            width: 50vw;
            height: 50vw;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.12) 0%, rgba(124, 58, 237, 0) 70%);
            pointer-events: none;
            z-index: 0;
            filter: blur(80px);
        }

        /* ── Top Header Bar ── */
        .top-bar {
            position: relative;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 36px;
            width: 100%;
            max-width: 1440px;
            margin: 0 auto;
        }

        .top-brand-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--adm-text-main);
        }
        .top-brand-logo {
            height: 38px;
            max-width: 160px;
            object-fit: contain;
        }
        .top-brand-icon {
            width: 38px;
            height: 38px;
            border-radius: var(--adm-radius-md);
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
        }
        .top-brand-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .top-brand-badge {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: var(--adm-brand-light);
            color: var(--adm-brand);
            padding: 3px 8px;
            border-radius: var(--adm-radius-pill);
            border: 1px solid var(--adm-brand-border);
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Theme Toggle Button */
        .theme-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--adm-surface);
            border: 1px solid var(--adm-border);
            border-radius: var(--adm-radius-pill);
            color: var(--adm-text-body);
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            transition: all var(--adm-transition);
        }
        .theme-pill-btn:hover {
            border-color: var(--adm-brand);
            color: var(--adm-brand);
            transform: translateY(-1px);
        }
        .theme-pill-btn i { font-size: 0.95rem; }

        /* ── Main SaaS Split Showcase ── */
        .auth-container {
            position: relative;
            z-index: 1;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 24px 60px;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
        }

        .auth-card-split {
            width: 100%;
            background: var(--adm-surface);
            border: 1px solid var(--adm-border);
            border-radius: var(--adm-radius-xl);
            box-shadow: var(--adm-shadow-card);
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            overflow: hidden;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        /* ── Left Hero Side ── */
        .hero-panel {
            background: linear-gradient(145deg, #1e1b4b 0%, #312e81 40%, #4338ca 100%);
            color: #ffffff;
            padding: 56px 48px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }
        .hero-panel::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: 
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 85% 80%, rgba(99, 102, 241, 0.3) 0%, transparent 50%);
            pointer-events: none;
        }
        .hero-panel-grid-lines {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--adm-radius-pill);
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            margin-bottom: 28px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.3);
            animation: livePulse 2s infinite;
        }
        @keyframes livePulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.7; }
        }

        .hero-title {
            font-family: 'Outfit', sans-serif;
            font-size: 2.35rem;
            font-weight: 800;
            line-height: 1.18;
            letter-spacing: -0.03em;
            margin-bottom: 16px;
            color: #ffffff;
        }
        .hero-title span {
            background: linear-gradient(135deg, #a5b4fc, #e0e7ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-desc {
            font-size: 1.02rem;
            color: #c7d2fe;
            line-height: 1.6;
            margin-bottom: 36px;
            max-width: 480px;
        }

        /* SaaS Value Pillars */
        .feature-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 36px;
        }
        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .feature-icon-chip {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #c7d2fe;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .feature-text strong {
            display: block;
            font-size: 0.95rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 2px;
        }
        .feature-text span {
            font-size: 0.84rem;
            color: #a5b4fc;
            line-height: 1.4;
        }

        /* Hero Footer */
        .hero-footer {
            position: relative;
            z-index: 2;
            padding-top: 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.14);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.82rem;
            color: #a5b4fc;
        }

        /* ── Right Auth Console ── */
        .form-panel {
            padding: 56px 48px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: var(--adm-surface);
        }

        .form-header {
            margin-bottom: 32px;
        }
        .form-header-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 12px;
            background: var(--adm-brand-light);
            border: 1px solid var(--adm-brand-border);
            border-radius: var(--adm-radius-pill);
            color: var(--adm-brand);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 12px;
        }
        .form-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--adm-text-main);
            letter-spacing: -0.02em;
            line-height: 1.25;
            margin-bottom: 6px;
        }
        .form-subtitle {
            font-size: 0.95rem;
            color: var(--adm-muted);
        }

        /* High Visibility Alert */
        .alert-toast {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: var(--adm-radius-md);
            margin-bottom: 24px;
            font-size: 0.9rem;
            font-weight: 500;
            line-height: 1.5;
            animation: slideDownAlert 0.3s ease;
        }
        @keyframes slideDownAlert {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .alert-toast.alert-error {
            background: var(--adm-danger-light);
            border: 1px solid var(--adm-danger-border);
            color: var(--adm-danger);
        }
        .alert-toast.alert-success {
            background: var(--adm-success-light);
            border: 1px solid rgba(5, 150, 105, 0.3);
            color: var(--adm-success);
        }
        .alert-toast i {
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
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--adm-text-main);
            margin-bottom: 8px;
        }
        .input-group-field {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-icon-left {
            position: absolute;
            left: 16px;
            color: var(--adm-faint);
            font-size: 1rem;
            pointer-events: none;
            transition: color var(--adm-transition);
        }
        .adm-input {
            width: 100%;
            padding: 14px 16px 14px 44px;
            background: var(--adm-surface-subtle);
            border: 1px solid var(--adm-border);
            border-radius: var(--adm-radius-md);
            font-family: inherit;
            font-size: 0.96rem;
            color: var(--adm-text-main);
            transition: all var(--adm-transition);
        }
        .adm-input:focus {
            outline: none;
            background: var(--adm-surface);
            border-color: var(--adm-brand);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.16);
        }
        .adm-input:focus + .input-icon-left,
        .input-group-field:focus-within .input-icon-left {
            color: var(--adm-brand);
        }
        .adm-input::placeholder {
            color: var(--adm-faint);
        }

        .btn-toggle-eye {
            position: absolute;
            right: 14px;
            background: transparent;
            border: none;
            color: var(--adm-faint);
            cursor: pointer;
            padding: 4px;
            font-size: 1rem;
            transition: color var(--adm-transition);
        }
        .btn-toggle-eye:hover {
            color: var(--adm-text-main);
        }

        .form-aux-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 26px;
            font-size: 0.88rem;
        }
        .remember-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--adm-muted);
            cursor: pointer;
            user-select: none;
        }
        .remember-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--adm-brand);
            cursor: pointer;
        }
        .forgot-link {
            color: var(--adm-brand);
            text-decoration: none;
            font-weight: 600;
            transition: color var(--adm-transition);
        }
        .forgot-link:hover {
            text-decoration: underline;
            color: var(--adm-brand-hover);
        }

        /* Submit Button */
        .btn-submit-console {
            width: 100%;
            padding: 15px 24px;
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            color: #ffffff;
            border: none;
            border-radius: var(--adm-radius-md);
            font-family: inherit;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all var(--adm-transition);
        }
        .btn-submit-console:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(79, 70, 229, 0.42);
            background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
        }
        .btn-submit-console:active {
            transform: translateY(0);
        }
        .btn-submit-console.loading {
            pointer-events: none;
            opacity: 0.85;
        }
        .spinner-dots {
            display: none;
            align-items: center;
            gap: 6px;
        }
        .spinner-dots span {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #fff;
            animation: bounceDot 0.6s infinite alternate;
        }
        .spinner-dots span:nth-child(2) { animation-delay: 0.2s; }
        .spinner-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes bounceDot {
            from { transform: translateY(0); }
            to   { transform: translateY(-6px); }
        }
        .btn-submit-console.loading .btn-label { display: none; }
        .btn-submit-console.loading .spinner-dots { display: flex; }

        /* Security Assurance Box */
        .security-badge-footer {
            margin-top: 32px;
            padding-top: 20px;
            border-top: 1px solid var(--adm-border-subtle);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.8rem;
            color: var(--adm-faint);
            text-align: center;
        }
        .security-badge-footer i {
            color: var(--adm-success);
        }

        /* ── Modal for Forgot Password ── */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeInModal 0.25s ease;
        }
        @keyframes fadeInModal {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        .modal-card {
            background: var(--adm-surface);
            border: 1px solid var(--adm-border);
            border-radius: var(--adm-radius-xl);
            max-width: 460px;
            width: 100%;
            padding: 32px;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.24);
            position: relative;
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .modal-header h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--adm-text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-header h3 i { color: var(--adm-brand); }
        .modal-close-btn {
            background: transparent;
            border: none;
            color: var(--adm-faint);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 4px;
            transition: color var(--adm-transition);
        }
        .modal-close-btn:hover { color: var(--adm-text-main); }
        .modal-body p {
            color: var(--adm-muted);
            font-size: 0.92rem;
            line-height: 1.55;
            margin-bottom: 20px;
        }
        .modal-contact-card {
            background: var(--adm-surface-subtle);
            border: 1px solid var(--adm-border-subtle);
            border-radius: var(--adm-radius-md);
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            font-size: 0.9rem;
            color: var(--adm-text-main);
            font-weight: 600;
        }
        .modal-contact-card i {
            color: var(--adm-brand);
            font-size: 1.1rem;
        }

        /* ── Responsive ── */
        @media (max-width: 960px) {
            .auth-card-split {
                grid-template-columns: 1fr;
            }
            .hero-panel {
                padding: 40px 32px;
            }
            .hero-title {
                font-size: 1.85rem;
            }
            .form-panel {
                padding: 40px 32px;
            }
        }
        @media (max-width: 480px) {
            .top-bar { padding: 16px 20px; }
            .auth-container { padding: 10px 16px 40px; }
            .hero-panel { display: none; }
            .form-panel { padding: 32px 20px; }
            .form-title { font-size: 1.55rem; }
        }

        /* ── Passkey / Biometric Login UI ── */
        .passkey-divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 20px 0;
            color: var(--adm-muted);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.08em;
        }
        .passkey-divider::before,
        .passkey-divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid var(--adm-border-subtle);
        }
        .passkey-divider span {
            padding: 0 12px;
        }

        .btn-passkey-admin {
            width: 100%;
            height: 48px;
            border-radius: var(--adm-radius-sm);
            background: var(--adm-surface);
            color: var(--adm-text-main);
            border: 1.5px solid var(--adm-border);
            font-family: inherit;
            font-size: 0.92rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s ease;
            box-shadow: var(--adm-shadow-subtle);
        }
        .btn-passkey-admin:hover {
            border-color: var(--adm-brand);
            background: var(--adm-brand-light);
            color: var(--adm-brand);
            transform: translateY(-1px);
        }
        .btn-passkey-admin i {
            font-size: 1.1rem;
            color: var(--adm-brand);
        }
        .btn-passkey-admin.loading {
            pointer-events: none;
            opacity: 0.75;
        }

        .passkey-status-msg {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: var(--adm-radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.4;
        }
        .passkey-status-msg.info {
            background: var(--adm-brand-light);
            border: 1px solid var(--adm-brand-border);
            color: var(--adm-brand);
        }
        .passkey-status-msg.error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        .passkey-status-msg.success {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #10b981;
        }
    </style>
</head>
<body>

    <!-- Ambient Ambient Glows -->
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <!-- Top Header Bar with White-Label Branding & Theme Switcher -->
    <header class="top-bar">
        <a href="/" class="top-brand-link" title="<?php echo htmlspecialchars($siteName); ?> Home">
            <?php if ($siteLogo): ?>
                <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="<?php echo htmlspecialchars($siteName); ?>" class="top-brand-logo">
            <?php else: ?>
                <div class="top-brand-icon">
                    <i class="fa-solid fa-cloud-bolt"></i>
                </div>
                <span class="top-brand-name"><?php echo htmlspecialchars($siteName); ?></span>
            <?php endif; ?>
            <span class="top-brand-badge">Enterprise</span>
        </a>

        <div class="top-actions">
            <!-- Theme Toggle Pill Button -->
            <button type="button" class="theme-pill-btn" id="themeToggleBtn" aria-label="Toggle dark mode">
                <i class="fa-solid fa-sun" id="themeIcon"></i>
                <span id="themeLabel">Light</span>
            </button>
        </div>
    </header>

    <!-- Main SaaS Showcase & Login Console -->
    <main class="auth-container">
        <div class="auth-card-split">
            
            <!-- Left Hero Panel: White-Label Feature Showcase -->
            <section class="hero-panel">
                <div class="hero-panel-grid-lines"></div>

                <div class="hero-content">
                    <div class="hero-status-pill">
                        <span class="live-dot"></span>
                        <span>Enterprise Cloud Console &bull; Online</span>
                    </div>

                    <h2 class="hero-title">
                        Operations &amp; Workforce <span>Command Center</span>
                    </h2>

                    <p class="hero-desc">
                        Unified white-label management for enterprise attendance telemetry, automated shifts, payroll compliance, and project execution.
                    </p>

                    <div class="feature-list">
                        <div class="feature-item">
                            <div class="feature-icon-chip">
                                <i class="fa-solid fa-fingerprint"></i>
                            </div>
                            <div class="feature-text">
                                <strong>Biometric &amp; IP Attendance</strong>
                                <span>Automated shift calculation, delay penalties, and geofence verification.</span>
                            </div>
                        </div>

                        <div class="feature-item">
                            <div class="feature-icon-chip">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                            <div class="feature-text">
                                <strong>Zero-Trust Identity Governance</strong>
                                <span>Aadhar KYC document verification and encrypted administrative auditing.</span>
                            </div>
                        </div>

                        <div class="feature-item">
                            <div class="feature-icon-chip">
                                <i class="fa-solid fa-chart-pie"></i>
                            </div>
                            <div class="feature-text">
                                <strong>Real-Time Analytics &amp; Reports</strong>
                                <span>Instant executive KPI dashboards with monthly CSV &amp; PDF payroll exports.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hero-footer">
                    <span><i class="fa-solid fa-circle-check"></i> SOC-2 Type II Certified</span>
                    <span><i class="fa-solid fa-lock"></i> AES-256 GCM Salted</span>
                    <span>v2.8 Enterprise</span>
                </div>
            </section>

            <!-- Right Form Panel: Administrative Console Login -->
            <section class="form-panel">
                <div class="form-header">
                    <span class="form-header-badge">
                        <i class="fa-solid fa-key"></i> Restricted Access
                    </span>
                    <h1 class="form-title">Administrator Login</h1>
                    <p class="form-subtitle">Enter your corporate credentials to manage <?php echo htmlspecialchars($siteName); ?>.</p>
                </div>

                <!-- Alert Messages -->
                <?php if (!empty($error)): ?>
                    <div class="alert-toast alert-error" role="alert">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert-toast alert-success" role="alert">
                        <i class="fa-solid fa-circle-check"></i>
                        <div><?php echo htmlspecialchars($success); ?></div>
                    </div>
                <?php endif; ?>

                <form action="" method="POST" id="adminLoginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <!-- Username or Email -->
                    <div class="form-group">
                        <label for="username" class="form-label">
                            <span>Username or Corporate Email</span>
                        </label>
                        <div class="input-group-field">
                            <i class="fa-solid fa-user-shield input-icon-left"></i>
                            <input type="text" 
                                   id="username" 
                                   name="username" 
                                   class="adm-input" 
                                   placeholder="admin or admin@cloudaspire.in" 
                                   value="<?php echo htmlspecialchars($rememberedUser); ?>" 
                                   required 
                                   autocomplete="username"
                                   autofocus>
                        </div>
                    </div>

                    <!-- Password with Eye Toggle -->
                    <div class="form-group">
                        <label for="password" class="form-label">
                            <span>Password</span>
                        </label>
                        <div class="input-group-field">
                            <i class="fa-solid fa-lock input-icon-left"></i>
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   class="adm-input" 
                                   placeholder="Enter your administrative password" 
                                   required 
                                   autocomplete="current-password">
                            <button type="button" 
                                    class="btn-toggle-eye" 
                                    id="pwdToggleBtn" 
                                    onclick="toggleAdminPasswordVis();" 
                                    aria-label="Toggle password visibility">
                                <i class="fa-solid fa-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Aux row: Remember Me & Forgot Password -->
                    <div class="form-aux-row">
                        <label class="remember-label">
                            <input type="checkbox" name="remember_me" value="1" <?php echo !empty($rememberedUser) ? 'checked' : ''; ?>>
                            <span>Remember workspace</span>
                        </label>
                        <a href="javascript:void(0);" onclick="openForgotModal();" class="forgot-link">
                            Trouble signing in?
                        </a>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-submit-console" id="submitConsoleBtn">
                        <span class="btn-label">
                            <i class="fa-solid fa-arrow-right-to-bracket"></i>
                            Sign In to Console
                        </span>
                        <div class="spinner-dots">
                            <span></span><span></span><span></span>
                        </div>
                    </button>
                </form>

                <!-- Passkey / Biometric Alternative Login -->
                <div class="passkey-divider">
                    <span>OR</span>
                </div>

                <button type="button" class="btn-passkey-admin" id="adminPasskeyBtn">
                    <i class="fa-solid fa-fingerprint"></i>
                    <span>Sign In with Admin Passkey</span>
                </button>
                <div id="adminPasskeyStatus" class="passkey-status-msg" style="display: none;"></div>

                <!-- Footer Security Badge -->
                <div class="security-badge-footer">
                    <i class="fa-solid fa-shield-check"></i>
                    <span>256-Bit SSL Encrypted &bull; Automated Session Timeout</span>
                </div>
            </section>

        </div>
    </main>

    <!-- Forgot Password / Recovery Modal -->
    <div class="modal-overlay" id="forgotModal" role="dialog" aria-modal="true">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fa-solid fa-life-ring"></i> Admin Recovery Help</h3>
                <button type="button" class="modal-close-btn" onclick="closeForgotModal();" aria-label="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>
                    For enterprise security compliance, administrator password resets require identity verification by the root infrastructure team or direct system console access.
                </p>
                <div class="modal-contact-card">
                    <i class="fa-solid fa-envelope-circle-check"></i>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--adm-muted); text-transform: uppercase;">Primary Administrator Contact</div>
                        <div>cloudaspire2025@gmail.com</div>
                    </div>
                </div>
                <button type="button" class="btn-submit-console" onclick="closeForgotModal();" style="width: 100%;">
                    Understood &bull; Return to Sign In
                </button>
            </div>
        </div>
    </div>

    <!-- Interactive Scripts -->
    <script>
        // Password Visibility Toggle
        function toggleAdminPasswordVis() {
            const pwdInput = document.getElementById('password');
            const eyeIcon  = document.getElementById('eyeIcon');
            if (!pwdInput) return;

            const isPass = pwdInput.type === 'password';
            pwdInput.type = isPass ? 'text' : 'password';
            if (eyeIcon) {
                eyeIcon.className = isPass ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            }
        }

        // Form Submit Loading Indicator
        const adminForm = document.getElementById('adminLoginForm');
        const submitBtn = document.getElementById('submitConsoleBtn');
        if (adminForm && submitBtn) {
            adminForm.addEventListener('submit', function() {
                submitBtn.classList.add('loading');
            });
        }

        // Theme Toggle Handler
        const themeBtn   = document.getElementById('themeToggleBtn');
        const themeIcon  = document.getElementById('themeIcon');
        const themeLabel = document.getElementById('themeLabel');
        const htmlRoot   = document.documentElement;

        function updateThemeUI(isDark) {
            if (themeIcon) {
                themeIcon.className = isDark ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
            }
            if (themeLabel) {
                themeLabel.textContent = isDark ? 'Dark' : 'Light';
            }
        }

        // Initialize button label on load
        const curThemeIsDark = htmlRoot.getAttribute('data-theme') === 'dark';
        updateThemeUI(curThemeIsDark);

        themeBtn?.addEventListener('click', () => {
            const isDark = htmlRoot.getAttribute('data-theme') === 'dark';
            const nextTheme = isDark ? 'light' : 'dark';

            if (nextTheme === 'dark') {
                htmlRoot.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
                updateThemeUI(true);
            } else {
                htmlRoot.removeAttribute('data-theme');
                localStorage.setItem('theme', 'light');
                updateThemeUI(false);
            }
        });

        // Forgot Modal Functions
        function openForgotModal() {
            const modal = document.getElementById('forgotModal');
            if (modal) modal.style.display = 'flex';
        }
        function closeForgotModal() {
            const modal = document.getElementById('forgotModal');
            if (modal) modal.style.display = 'none';
        }
        document.getElementById('forgotModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeForgotModal();
        });
    </script>

    <!-- Passkey / WebAuthn Client Module -->
    <script src="/assets/js/passkey.js"></script>
    <script>
        (function() {
            const passkeyBtn = document.getElementById('adminPasskeyBtn');
            const passkeyStatus = document.getElementById('adminPasskeyStatus');
            const userInput = document.getElementById('username');

            function showStatus(type, msg, icon) {
                if (!passkeyStatus) return;
                passkeyStatus.className = 'passkey-status-msg ' + type;
                passkeyStatus.innerHTML = '<i class="' + (icon || (type === 'error' ? 'fa-solid fa-circle-exclamation' : (type === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-spinner fa-spin'))) + '"></i><span>' + msg + '</span>';
                passkeyStatus.style.display = 'flex';
            }

            function hideStatus() {
                if (!passkeyStatus) return;
                passkeyStatus.style.display = 'none';
            }

            if (!Passkey.isSupported() && passkeyBtn) {
                passkeyBtn.style.opacity = '0.5';
                passkeyBtn.title = 'Passkeys are not supported in this browser or over insecure HTTP.';
            }

            passkeyBtn?.addEventListener('click', async function(e) {
                e.preventDefault();
                hideStatus();

                if (!Passkey.isSupported()) {
                    showStatus('error', 'WebAuthn / Passkeys are not supported by this browser.');
                    return;
                }

                const originalHtml = passkeyBtn.innerHTML;
                passkeyBtn.classList.add('loading');
                passkeyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Verifying biometric key...</span>';
                showStatus('info', 'Touch your fingerprint sensor, use Windows Hello, Face ID, or your security key to sign in.');

                try {
                    const identifier = userInput ? userInput.value.trim() : '';
                    const result = await Passkey.login(identifier || null);

                    showStatus('success', result.message || 'Passkey verified! Redirecting to dashboard...');
                    passkeyBtn.innerHTML = '<i class="fa-solid fa-circle-check"></i><span>Authenticated!</span>';

                    setTimeout(function() {
                        window.location.href = result.redirect || '/admin/dashboard';
                    }, 600);

                } catch (err) {
                    passkeyBtn.classList.remove('loading');
                    passkeyBtn.innerHTML = originalHtml;
                    showStatus('error', err.message || 'Admin passkey login failed.');
                }
            });
        })();
    </script>
</body>
</html>
