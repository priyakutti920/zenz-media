<?php
// Initialize security utilities and secure session
if (!class_exists('Security')) {
    require_once __DIR__ . '/../app/security.php';
}
if (isset($pdo) && $pdo instanceof PDO) {
    Security::init($pdo);
}
Security::initSecureSession();

// Check if admin is logged in and session is active
if (!Security::isAuthenticated()) {
    header("Location: ../admin");
    exit();
}

// Track active session
if (isset($pdo) && $pdo instanceof PDO) {
    $currentAdminId = $_SESSION['admin_id'] ?? 1;
    Security::trackCurrentSession($currentAdminId, 'admin');
}

// Load the active branding record from site_settings
$activeSiteSettings = null;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->query("SELECT site_name, logo, favicon FROM site_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1");
        $activeSiteSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}
$adminBrandName    = !empty($activeSiteSettings['site_name']) ? $activeSiteSettings['site_name'] : 'Cloud Aspire';
$adminBrandLogo    = !empty($activeSiteSettings['logo']) ? '/assets/uploads/site/' . rawurlencode($activeSiteSettings['logo']) : null;
$adminBrandFavicon = !empty($activeSiteSettings['favicon']) ? '/assets/uploads/site/' . rawurlencode($activeSiteSettings['favicon']) : null;

$adminUsername = $_SESSION['admin_username'] ?? 'Administrator';
$adminInitials = strtoupper(substr($adminUsername, 0, 2)) ?: 'AD';

$adminNavLinks = [
    'dashboard'     => ['label' => 'Dashboard',     'icon' => 'fa-gauge-high'],
    'employee'      => ['label' => 'Employees',     'icon' => 'fa-users'],
    'roles'         => ['label' => 'Roles & RBAC',  'icon' => 'fa-user-shield'],
    'attendance'    => ['label' => 'Attendance',    'icon' => 'fa-calendar-check'],
    'attendance_requests' => ['label' => 'WFH Approvals', 'icon' => 'fa-clipboard-check'],
    'task'          => ['label' => 'Tasks',         'icon' => 'fa-list-check'],
    'project'       => ['label' => 'Projects',      'icon' => 'fa-folder-open'],
    'leave'         => ['label' => 'Leaves',        'icon' => 'fa-calendar-xmark'],
    'shifts'        => ['label' => 'Shifts',        'icon' => 'fa-business-time'],
    'reports'       => ['label' => 'Reports',       'icon' => 'fa-chart-pie'],
    'site_settings'   => ['label' => 'Site Settings',   'icon' => 'fa-sliders'],
    'office_location' => ['label' => 'Office GPS',      'icon' => 'fa-location-dot'],
    'settings'        => ['label' => 'Settings',        'icon' => 'fa-gear'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle . ' &bull; ' . $adminBrandName . ' Admin') : htmlspecialchars($adminBrandName . ' Admin Console'); ?></title>
    
    <?php if ($adminBrandFavicon): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($adminBrandFavicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/png" href="https://cloudaspire.in/uploads/site_favicon_1783239900.png">
    <?php endif; ?>

    <!-- Premium Fonts & Icons -->
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
            --adm-nav-bg:        rgba(255, 255, 255, 0.92);
            --adm-subnav-bg:     rgba(255, 255, 255, 0.96);
            --adm-nav-border:    #e2e8f0;
            --adm-nav-shadow:    0 4px 20px -2px rgba(15, 23, 42, 0.05), 0 1px 3px rgba(15, 23, 42, 0.03);

            --adm-bg:            #f8fafc;
            --adm-surface:       #ffffff;
            --adm-surface-subtle:#f1f5f9;
            --adm-surface-card:  #ffffff;
            --adm-border:        #cbd5e1;
            --adm-border-subtle: #e2e8f0;

            --adm-text-main:     #0f172a;
            --adm-text-body:     #334155;
            --adm-muted:         #64748b;
            --adm-faint:         #94a3b8;

            --adm-brand:         #4f46e5;
            --adm-brand-hover:   #4338ca;
            --adm-brand-light:   rgba(79, 70, 229, 0.08);
            --adm-brand-border:  rgba(79, 70, 229, 0.22);
            --adm-brand-gradient: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);

            --adm-tab-hover-bg:  #f1f5f9;

            --adm-success:       #059669;
            --adm-success-light: #ecfdf5;
            --adm-warning:       #d97706;
            --adm-warning-light: #fffbeb;
            --adm-danger:        #e11d48;
            --adm-danger-light:  #fff1f2;
            --adm-danger-border: #fecdd3;

            /* Standard color utilities */
            --danger-600:        #dc2626;
            --success-600:       #059669;
            --warning-600:       #d97706;
            --primary-600:       #2563eb;
            --danger-200:        #fecdd3;
            --success-200:       #a7f3d0;
            --warning-200:       #fde68a;

            --adm-radius-sm:     8px;
            --adm-radius-md:     10px;
            --adm-radius-lg:     14px;
            --adm-radius-xl:     18px;
            --adm-radius-pill:   9999px;

            --adm-shadow-sm:     0 1px 3px rgba(0, 0, 0, 0.05);
            --adm-shadow-card:   0 1px 3px rgba(15, 23, 42, 0.04), 0 6px 18px -2px rgba(15, 23, 42, 0.05);
            --adm-shadow-hover:  0 14px 30px -4px rgba(15, 23, 42, 0.12);

            --adm-transition:    0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        html[data-theme="dark"] {
            --adm-nav-bg:        rgba(15, 23, 42, 0.92);
            --adm-subnav-bg:     rgba(13, 20, 37, 0.96);
            --adm-nav-border:    #1e293b;
            --adm-nav-shadow:    0 8px 32px -4px rgba(0, 0, 0, 0.5);

            --adm-bg:            #090d16;
            --adm-surface:       #0f172a;
            --adm-surface-subtle:#17223b;
            --adm-surface-card:  #0f172a;
            --adm-border:        #29354d;
            --adm-border-subtle: #1e293b;

            --adm-text-main:     #f8fafc;
            --adm-text-body:     #e2e8f0;
            --adm-muted:         #94a3b8;
            --adm-faint:         #64748b;

            --adm-brand:         #6366f1;
            --adm-brand-hover:   #818cf8;
            --adm-brand-light:   rgba(99, 102, 241, 0.16);
            --adm-brand-border:  rgba(99, 102, 241, 0.35);
            --adm-brand-gradient: linear-gradient(135deg, #6366f1 0%, #818cf8 100%);

            --adm-tab-hover-bg:  #1e293b;

            --adm-success-light: rgba(5, 150, 105, 0.18);
            --adm-warning-light: rgba(217, 119, 6, 0.18);
            --adm-danger-light:  rgba(225, 29, 72, 0.18);
            --adm-danger-border: rgba(225, 29, 72, 0.35);

            --adm-shadow-card:   0 4px 20px -2px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05);
            --adm-shadow-hover:  0 16px 36px -6px rgba(0, 0, 0, 0.7);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            background-color: var(--adm-bg);
            font-family: 'Plus Jakarta Sans', 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--adm-text-body);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            display: flex;
            flex-direction: column;
            transition: background-color 0.25s ease, color 0.25s ease;
        }

        /* ── Sticky Unified Header Shell ── */
        .adm-header-wrapper {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: var(--adm-nav-bg);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            box-shadow: var(--adm-nav-shadow);
            border-bottom: 1px solid var(--adm-nav-border);
            width: 100%;
        }

        /* ── Top Ambient Glow Line ── */
        .adm-top-gradient-strip {
            height: 3px;
            width: 100%;
            background: linear-gradient(90deg, #4f46e5 0%, #06b6d4 25%, #10b981 50%, #f59e0b 75%, #ec4899 100%);
        }

        /* ── Upper Navbar (Brand, Live Status, Profile, Logout) ── */
        .adm-navbar {
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            max-width: 1440px;
            margin: 0 auto;
            width: 100%;
            gap: 20px;
        }

        /* Brand Area */
        .adm-brand-area {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--adm-text-main);
            flex-shrink: 0;
        }
        .adm-brand-logo-img {
            height: 38px;
            max-width: 170px;
            object-fit: contain;
            transition: transform var(--adm-transition);
        }
        .adm-brand-logo-img:hover {
            transform: scale(1.02);
        }
        .adm-brand-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--adm-radius-md);
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #ec4899 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
            transition: transform var(--adm-transition);
        }
        .adm-brand-area:hover .adm-brand-icon {
            transform: scale(1.05) rotate(-3deg);
        }
        .adm-brand-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            color: var(--adm-text-main);
            line-height: 1.1;
            white-space: nowrap;
        }
        .adm-badge-saas {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: var(--adm-brand-light);
            color: var(--adm-brand);
            padding: 3px 9px;
            border-radius: var(--adm-radius-pill);
            border: 1px solid var(--adm-brand-border);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .adm-pulse-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: admPulse 2s infinite;
        }

        @keyframes admPulse {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            }
            70% {
                transform: scale(1);
                box-shadow: 0 0 0 5px rgba(16, 185, 129, 0);
            }
            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        /* Center Widget: Live Clock */
        .adm-nav-center {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        .adm-clock-widget {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 12px;
            border-radius: var(--adm-radius-pill);
            background: var(--adm-surface-subtle);
            border: 1px solid var(--adm-border-subtle);
            font-size: 0.80rem;
            font-weight: 600;
            color: var(--adm-muted);
            letter-spacing: -0.01em;
            white-space: nowrap;
        }
        .adm-clock-widget i {
            color: var(--adm-brand);
            font-size: 0.82rem;
        }

        /* Right Side Actions */
        .adm-nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        /* View Public Portal Shortcut Link */
        .adm-link-portal {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: var(--adm-radius-pill);
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--adm-muted);
            text-decoration: none;
            border: 1px solid var(--adm-border-subtle);
            background: var(--adm-surface-subtle);
            transition: all var(--adm-transition);
            white-space: nowrap;
        }
        .adm-link-portal:hover {
            color: var(--adm-brand);
            border-color: var(--adm-brand-border);
            background: var(--adm-brand-light);
            transform: translateY(-1px);
        }

        /* Theme Toggle Button */
        .adm-theme-toggle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid var(--adm-border-subtle);
            background: var(--adm-surface-subtle);
            color: var(--adm-text-body);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.92rem;
            transition: all var(--adm-transition);
            flex-shrink: 0;
        }
        .adm-theme-toggle:hover {
            border-color: var(--adm-brand);
            color: var(--adm-brand);
            transform: scale(1.06);
            background: var(--adm-surface);
        }

        /* Admin User Chip */
        .adm-user-chip {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 3px 12px 3px 3px;
            background: var(--adm-surface-subtle);
            border: 1px solid var(--adm-border-subtle);
            border-radius: var(--adm-radius-pill);
            transition: all var(--adm-transition);
            flex-shrink: 0;
            white-space: nowrap;
        }
        .adm-user-chip:hover {
            border-color: var(--adm-brand-border);
            background: var(--adm-surface);
        }
        .adm-user-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.74rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .adm-user-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
            white-space: nowrap;
        }
        .adm-user-name {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--adm-text-main);
            white-space: nowrap;
        }
        .adm-user-role {
            font-size: 0.68rem;
            font-weight: 600;
            color: var(--adm-brand);
            white-space: nowrap;
        }

        /* Logout Button */
        .adm-btn-logout {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: var(--adm-danger-light);
            border: 1px solid var(--adm-danger-border);
            color: var(--adm-danger);
            border-radius: var(--adm-radius-sm);
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: all var(--adm-transition);
            flex-shrink: 0;
            white-space: nowrap;
        }
        .adm-btn-logout:hover {
            background: var(--adm-danger);
            color: #ffffff;
            border-color: var(--adm-danger);
            transform: translateY(-1px);
        }

        /* ── Integrated Secondary Navigation Menu Bar (Subnav) ── */
        .adm-subnav-bar {
            background: var(--adm-subnav-bg);
            border-top: 1px solid var(--adm-nav-border);
            width: 100%;
            max-width: 100%;
            overflow: hidden;
        }
        .adm-subnav-container {
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
            padding: 4px 32px;
            display: block;
            overflow-x: auto;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .adm-subnav-container::-webkit-scrollbar {
            display: none;
        }

        /* Segmented Nav Menu List */
        .adm-nav-tabs-bar {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 0;
            min-width: max-content;
            flex-wrap: nowrap;
        }

        .adm-tab-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 13px;
            border-radius: var(--adm-radius-sm);
            font-size: 0.83rem;
            font-weight: 600;
            color: var(--adm-muted);
            text-decoration: none;
            white-space: nowrap;
            transition: all var(--adm-transition);
            flex-shrink: 0;
        }
        .adm-tab-link i {
            font-size: 0.88rem;
            color: var(--adm-faint);
            transition: color var(--adm-transition);
        }
        .adm-tab-link:hover {
            color: var(--adm-text-main);
            background: var(--adm-tab-hover-bg);
        }
        .adm-tab-link:hover i {
            color: var(--adm-brand);
        }
        .adm-tab-link.active {
            background: var(--adm-brand-gradient);
            color: #ffffff !important;
            box-shadow: 0 3px 10px rgba(79, 70, 229, 0.32);
            font-weight: 700;
        }
        .adm-tab-link.active i {
            color: #ffffff !important;
        }

        /* ── Workspace Main Wrapper ── */
        .main-content {
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
            padding: 24px 32px 60px;
            flex: 1;
        }

        /* ── Page Header Standard ── */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .page-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--adm-text-main);
            letter-spacing: -0.025em;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-subtitle {
            font-size: 0.95rem;
            color: var(--adm-muted);
            margin: 4px 0 0 0;
        }

        /* ── Alert Messages ── */
        .alert {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 20px;
            border-radius: var(--adm-radius-md);
            margin-bottom: 24px;
            font-size: 0.92rem;
            font-weight: 600;
            box-shadow: var(--adm-shadow-sm);
        }
        .alert-success {
            background: var(--adm-success-light);
            color: var(--adm-success);
            border: 1px solid rgba(5, 150, 105, 0.3);
        }
        .alert-error {
            background: var(--adm-danger-light);
            color: var(--adm-danger);
            border: 1px solid var(--adm-danger-border);
        }
        .alert-warning {
            background: var(--adm-warning-light);
            color: var(--adm-warning);
            border: 1px solid rgba(217, 119, 6, 0.3);
        }
        .alert i {
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .alert svg {
            width: 20px;
            height: 20px;
            min-width: 20px;
            min-height: 20px;
            flex-shrink: 0;
        }

        /* ── Responsive Layout ── */
        @media (max-width: 1100px) {
            .adm-clock-widget { display: none; }
        }
        @media (max-width: 960px) {
            .adm-navbar { padding: 0 16px; height: 60px; }
            .adm-subnav-container { padding: 4px 16px; }
            .main-content { padding: 20px 16px 40px; }
            .adm-link-portal { display: none; }
        }
        @media (max-width: 680px) {
            .adm-brand-name { font-size: 1.1rem; }
            .adm-user-meta { display: none; }
            .adm-user-chip { padding: 3px; }
            .page-title { font-size: 1.5rem; }
            .adm-tab-link { padding: 6px 11px; font-size: 0.80rem; }
        }
        @media (max-width: 480px) {
            .adm-navbar { padding: 0 12px; gap: 8px; }
            .adm-subnav-container { padding: 4px 12px; }
            .main-content { padding: 16px 12px 40px; }
            .adm-brand-area { gap: 8px; }
            .adm-brand-name { font-size: 0.98rem; }
            .adm-nav-actions { gap: 6px; }
            .adm-btn-logout span { display: none; }
            .adm-btn-logout { padding: 6px 8px; min-width: 32px; justify-content: center; }
            .adm-badge-saas { display: none; }
        }
    </style>
</head>
<body>

    <!-- Unified Sticky Header Navigation Shell -->
    <header class="adm-header-wrapper">
        <!-- Top Glow Accent Line -->
        <div class="adm-top-gradient-strip"></div>

        <!-- Upper Navbar -->
        <div class="adm-navbar" role="navigation" aria-label="Admin Navigation">
            
            <!-- Brand Link -->
            <a href="dashboard" class="adm-brand-area" title="<?php echo htmlspecialchars($adminBrandName); ?> Admin Dashboard">
                <?php if ($adminBrandLogo): ?>
                    <img src="<?php echo htmlspecialchars($adminBrandLogo); ?>" alt="<?php echo htmlspecialchars($adminBrandName); ?>" class="adm-brand-logo-img">
                <?php else: ?>
                    <div class="adm-brand-icon">
                        <i class="fa-solid fa-cloud-bolt"></i>
                    </div>
                    <span class="adm-brand-name"><?php echo htmlspecialchars($adminBrandName); ?></span>
                <?php endif; ?>
                <span class="adm-badge-saas">
                    <span class="adm-pulse-dot"></span>
                    <span>Console</span>
                </span>
            </a>

            <!-- Center Widget: Live Clock -->
            <div class="adm-nav-center">
                <div class="adm-clock-widget" title="Live System Time">
                    <i class="fa-regular fa-clock"></i>
                    <span id="adminLiveClock">Syncing...</span>
                </div>
            </div>

            <!-- Right Side Controls -->
            <div class="adm-nav-actions">
                <!-- Shortcut to Public Site -->
                <a href="/" target="_blank" class="adm-link-portal" title="Open public portal">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    <span>View Site</span>
                </a>

                <!-- Theme Toggle Button -->
                <button type="button" class="adm-theme-toggle" id="adminThemeToggle" aria-label="Toggle theme mode" title="Toggle dark / light theme">
                    <i class="fa-solid fa-sun" id="themeNavIcon"></i>
                </button>

                <!-- Admin Profile Indicator -->
                <div class="adm-user-chip" title="Signed in as Administrator">
                    <div class="adm-user-avatar"><?php echo $adminInitials; ?></div>
                    <div class="adm-user-meta">
                        <span class="adm-user-name"><?php echo htmlspecialchars($adminUsername); ?></span>
                        <span class="adm-user-role">Super Admin</span>
                    </div>
                </div>

                <!-- Logout Button -->
                <a href="logout" class="adm-btn-logout" title="Sign out of Admin Console">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>

        <!-- Integrated Secondary Navigation Menu Bar (Subnav) -->
        <nav class="adm-subnav-bar" aria-label="Admin Navigation Menu">
            <div class="adm-subnav-container">
                <div class="adm-nav-tabs-bar">
                    <?php foreach ($adminNavLinks as $pageKey => $navItem): ?>
                        <a href="<?php echo $pageKey; ?>" 
                           class="adm-tab-link <?php echo (($currentPage ?? '') === $pageKey) ? 'active' : ''; ?>">
                            <i class="fa-solid <?php echo $navItem['icon']; ?>"></i>
                            <span><?php echo htmlspecialchars($navItem['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </nav>
    </header>

    <!-- Main Workspace Container -->
    <main class="main-content">

    <script>
        // Navbar Theme Mode Switcher
        (function() {
            const toggleBtn = document.getElementById('adminThemeToggle');
            const iconEl    = document.getElementById('themeNavIcon');
            const htmlRoot  = document.documentElement;

            function syncNavIcon() {
                const isDark = htmlRoot.getAttribute('data-theme') === 'dark';
                if (iconEl) {
                    iconEl.className = isDark ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
                }
            }
            syncNavIcon();

            toggleBtn?.addEventListener('click', () => {
                const isDark = htmlRoot.getAttribute('data-theme') === 'dark';
                const nextTheme = isDark ? 'light' : 'dark';

                if (nextTheme === 'dark') {
                    htmlRoot.setAttribute('data-theme', 'dark');
                    localStorage.setItem('theme', 'dark');
                } else {
                    htmlRoot.removeAttribute('data-theme');
                    localStorage.setItem('theme', 'light');
                }
                syncNavIcon();
            });
        })();

        // Real-time Header Clock
        (function() {
            const clockEl = document.getElementById('adminLiveClock');
            if (!clockEl) return;
            function updateClock() {
                const now = new Date();
                const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                const dateStr = now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
                clockEl.textContent = timeStr + ' \u2022 ' + dateStr;
            }
            updateClock();
            setInterval(updateClock, 1000);
        })();
    </script>
