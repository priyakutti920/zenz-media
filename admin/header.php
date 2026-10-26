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
    'employee'      => ['label' => 'Employees',     'icon' => 'fa-users'],
    'roles'         => ['label' => 'Roles & RBAC',  'icon' => 'fa-user-shield'],
    'attendance'    => ['label' => 'Attendance',    'icon' => 'fa-calendar-check'],
    'working_days'  => ['label' => 'Working Days',  'icon' => 'fa-calendar-days'],
    'task'          => ['label' => 'Tasks',         'icon' => 'fa-list-check'],
    'project'       => ['label' => 'Projects',      'icon' => 'fa-folder-open'],
    'leave'         => ['label' => 'Leaves',        'icon' => 'fa-calendar-xmark'],
    'shifts'        => ['label' => 'Shifts',        'icon' => 'fa-business-time'],
    'reports'       => ['label' => 'Reports',       'icon' => 'fa-chart-pie'],
    'settings'      => ['label' => 'Settings',      'icon' => 'fa-gear'],
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

        html {
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
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
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
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
            max-width: 100%;
            box-sizing: border-box;
        }

        /* ── Top Ambient Glow Line (Removed) ── */
        .adm-top-gradient-strip {
            display: none !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* ── Sidebar Menu Toggle Button (Mobile & Tablet) ── */
        .adm-menu-toggle {
            width: 38px;
            height: 38px;
            border-radius: var(--adm-radius-sm);
            border: 1px solid var(--adm-border-subtle);
            background: var(--adm-surface-subtle);
            color: var(--adm-text-main);
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.05rem;
            transition: all var(--adm-transition);
            flex-shrink: 0;
            margin-right: 2px;
        }
        .adm-menu-toggle:hover {
            border-color: var(--adm-brand);
            color: var(--adm-brand);
            background: var(--adm-brand-light);
        }

        /* ── Sidebar Backdrop Overlay ── */
        .adm-sidebar-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100vw;
            height: 100vh;
            height: 100dvh;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 1998;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .adm-sidebar-backdrop.open {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        /* ── Slide-out Sidebar Drawer ── */
        .adm-sidebar-drawer {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 280px;
            max-width: 85vw;
            height: 100vh;
            height: 100dvh;
            background: var(--adm-surface);
            border-right: 1px solid var(--adm-border);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25);
            z-index: 1999;
            display: flex;
            flex-direction: column;
            transform: translateX(-100%);
            visibility: hidden;
            pointer-events: none;
            transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.28s ease;
            overflow: hidden;
        }
        .adm-sidebar-drawer.open {
            transform: translateX(0);
            visibility: visible;
            pointer-events: auto;
        }
        body.adm-sidebar-open {
            overflow: hidden !important;
        }

        .adm-sidebar-header {
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px;
            border-bottom: 1px solid var(--adm-border-subtle);
            flex-shrink: 0;
        }
        .adm-sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--adm-text-main);
        }
        .adm-sidebar-logo-img {
            height: 32px;
            max-width: 140px;
            object-fit: contain;
        }
        .adm-sidebar-close {
            width: 32px;
            height: 32px;
            border-radius: var(--adm-radius-sm);
            border: 1px solid var(--adm-border-subtle);
            background: var(--adm-surface-subtle);
            color: var(--adm-text-body);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.92rem;
            transition: all var(--adm-transition);
        }
        .adm-sidebar-close:hover {
            border-color: var(--adm-danger);
            color: var(--adm-danger);
            background: var(--adm-danger-light);
        }

        .adm-sidebar-section-title {
            font-size: 0.70rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--adm-faint);
            padding: 4px 14px 4px;
        }

        .adm-sidebar-links {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 12px 10px;
            display: flex;
            flex-direction: column;
            gap: 3px;
            scrollbar-width: thin;
            scrollbar-color: var(--adm-border) transparent;
            -webkit-overflow-scrolling: touch;
        }
        .adm-sidebar-links::-webkit-scrollbar {
            width: 5px;
        }
        .adm-sidebar-links::-webkit-scrollbar-track {
            background: transparent;
        }
        .adm-sidebar-links::-webkit-scrollbar-thumb {
            background: var(--adm-border);
            border-radius: 4px;
        }

        .adm-sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: var(--adm-radius-sm);
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--adm-text-body);
            text-decoration: none;
            transition: all var(--adm-transition);
        }
        .adm-sidebar-link i {
            width: 22px;
            text-align: center;
            color: var(--adm-muted);
            font-size: 0.95rem;
            transition: color var(--adm-transition);
        }
        .adm-sidebar-link:hover {
            color: var(--adm-brand);
            background: var(--adm-brand-light);
        }
        .adm-sidebar-link:hover i {
            color: var(--adm-brand);
        }
        .adm-sidebar-link.active {
            background: var(--adm-brand-gradient);
            color: #ffffff !important;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.32);
        }
        .adm-sidebar-link.active i {
            color: #ffffff !important;
        }

        /* ── Upper Navbar (Brand, Live Status, Profile Dropdown) ── */
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

        /* ── Admin Profile Menu & Dropdown ── */
        .adm-user-menu-wrapper {
            position: relative;
            display: inline-block;
        }

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
            cursor: pointer;
            font-family: inherit;
            color: inherit;
            text-align: left;
        }
        .adm-user-chip:hover,
        .adm-user-menu-wrapper.open .adm-user-chip {
            border-color: var(--adm-brand-border);
            background: var(--adm-surface);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.12);
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
        .adm-user-caret {
            font-size: 0.68rem;
            color: var(--adm-muted);
            transition: transform var(--adm-transition);
            margin-left: 2px;
        }
        .adm-user-menu-wrapper.open .adm-user-caret {
            transform: rotate(180deg);
            color: var(--adm-brand);
        }

        /* Profile Dropdown Menu */
        .adm-user-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            min-width: 210px;
            max-width: calc(100vw - 24px);
            background: var(--adm-surface);
            border: 1px solid var(--adm-border);
            border-radius: var(--adm-radius-md);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            padding: 6px;
            z-index: 2100;
            display: none;
            opacity: 0;
            transform: translateY(-6px);
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .adm-user-menu-wrapper.open .adm-user-dropdown {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .adm-user-dropdown-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: var(--adm-radius-sm);
            background: var(--adm-surface-subtle);
            margin-bottom: 4px;
        }
        .adm-user-dropdown-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.80rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .adm-user-dropdown-info {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .adm-user-dropdown-name {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--adm-text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .adm-user-dropdown-role {
            font-size: 0.70rem;
            font-weight: 600;
            color: var(--adm-brand);
            white-space: nowrap;
        }

        .adm-user-dropdown-divider {
            height: 1px;
            background: var(--adm-border-subtle);
            margin: 4px 0;
        }

        .adm-user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--adm-radius-sm);
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--adm-text-body);
            text-decoration: none;
            transition: all var(--adm-transition);
            cursor: pointer;
        }
        .adm-user-dropdown-item i {
            width: 18px;
            text-align: center;
            font-size: 0.90rem;
            color: var(--adm-muted);
            transition: color var(--adm-transition);
        }
        .adm-user-dropdown-item:hover {
            background: var(--adm-surface-subtle);
            color: var(--adm-text-main);
        }
        .adm-user-dropdown-item:hover i {
            color: var(--adm-brand);
        }

        .adm-user-dropdown-item--danger {
            color: var(--adm-danger);
        }
        .adm-user-dropdown-item--danger i {
            color: var(--adm-danger);
        }
        .adm-user-dropdown-item--danger:hover {
            background: var(--adm-danger-light);
            color: var(--adm-danger);
        }
        .adm-user-dropdown-item--danger:hover i {
            color: var(--adm-danger);
        }


        /* ── Integrated Secondary Navigation Menu Bar (Desktop Horizontal Subnav) ── */
        .adm-subnav-bar {
            background: var(--adm-subnav-bg);
            border-top: 1px solid var(--adm-nav-border);
            width: 100%;
            max-width: 100%;
            display: block;
            box-sizing: border-box;
        }
        .adm-subnav-container {
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
            padding: 4px 32px;
            display: flex;
            align-items: center;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
            box-sizing: border-box;
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

        /* ── Responsive Layout & Breakpoints ── */
        /* Desktop: screens > 1024px */
        @media (min-width: 1025px) {
            .adm-menu-toggle { display: none !important; }
            .adm-sidebar-drawer { display: none !important; }
            .adm-sidebar-backdrop { display: none !important; }
            .adm-subnav-bar { display: block !important; }
        }
        @media (min-width: 1025px) and (max-width: 1400px) {
            .adm-navbar { padding: 0 20px; }
            .adm-subnav-container { padding: 4px 20px; }
            .adm-tab-link { padding: 6px 10px; font-size: 0.81rem; gap: 5px; }
            .main-content { padding: 24px 20px 60px; }
        }

        /* Tablet & Mobile: screens <= 1024px */
        @media (max-width: 1024px) {
            .adm-subnav-bar { display: none !important; }
            .adm-menu-toggle { display: inline-flex !important; }
            .adm-clock-widget { display: none; }
        }

        /* Tablet specific: 681px to 1024px */
        @media (min-width: 681px) and (max-width: 1024px) {
            .adm-navbar { padding: 0 20px; height: 64px; }
            .main-content { padding: 24px 20px 48px; }
            .adm-sidebar-drawer { width: 280px; max-width: 85vw; }
        }

        /* Mobile: <= 680px */
        @media (max-width: 680px) {
            .adm-navbar { padding: 0 16px; height: 60px; }
            .main-content { padding: 20px 16px 40px; }
            .adm-link-portal { display: none; }
            .adm-brand-name { font-size: 1.1rem; }
            .adm-user-meta { display: none; }
            .adm-user-chip { padding: 3px; }
            .page-title { font-size: 1.5rem; }
            .adm-sidebar-drawer { width: 260px; max-width: 82vw; }
            .adm-sidebar-link { padding: 9px 12px; font-size: 0.85rem; }
        }

        /* Small Mobile: <= 480px */
        @media (max-width: 480px) {
            .adm-navbar { padding: 0 12px; gap: 8px; }
            .main-content { padding: 16px 12px 40px; }
            .adm-brand-area { gap: 8px; }
            .adm-brand-name { font-size: 0.98rem; }
            .adm-nav-actions { gap: 6px; }
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
            
            <!-- Sidebar / Navigation Menu Toggle Button -->
            <button type="button" class="adm-menu-toggle" id="adminMenuToggle" aria-label="Toggle navigation menu" aria-expanded="false" title="Toggle navigation menu">
                <i class="fa-solid fa-bars" id="adminMenuToggleIcon"></i>
            </button>

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

                <!-- Admin Profile Menu with Dropdown -->
                <div class="adm-user-menu-wrapper" id="adminUserMenuWrapper">
                    <button type="button" class="adm-user-chip" id="adminUserMenuBtn" aria-expanded="false" aria-haspopup="true" title="Account: <?php echo htmlspecialchars($adminUsername); ?>">
                        <div class="adm-user-avatar"><?php echo $adminInitials; ?></div>
                        <div class="adm-user-meta">
                            <span class="adm-user-name"><?php echo htmlspecialchars($adminUsername); ?></span>
                            <span class="adm-user-role">Super Admin</span>
                        </div>
                        <i class="fa-solid fa-chevron-down adm-user-caret"></i>
                    </button>
                    <div class="adm-user-dropdown" id="adminUserDropdown" role="menu" aria-label="User Account Menu">
                        <div class="adm-user-dropdown-header">
                            <div class="adm-user-dropdown-avatar"><?php echo $adminInitials; ?></div>
                            <div class="adm-user-dropdown-info">
                                <span class="adm-user-dropdown-name"><?php echo htmlspecialchars($adminUsername); ?></span>
                                <span class="adm-user-dropdown-role">Super Admin</span>
                            </div>
                        </div>
                        <div class="adm-user-dropdown-divider"></div>
                        <a href="logout" class="adm-user-dropdown-item adm-user-dropdown-item--danger" id="adminHeaderLogoutBtn" role="menuitem" title="Sign out of Admin Console">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Integrated Secondary Navigation Menu Bar (Desktop Horizontal Subnav) -->
        <nav class="adm-subnav-bar" aria-label="Admin Navigation Menu">
            <div class="adm-subnav-container">
                <div class="adm-nav-tabs-bar">
                    <?php foreach ($adminNavLinks as $pageKey => $navItem): ?>
                        <?php 
                            $isActive = (($currentPage ?? '') === $pageKey);
                            if ($pageKey === 'settings' && in_array(($currentPage ?? ''), ['settings', 'site_settings', 'office_location'], true)) $isActive = true;
                            if ($pageKey === 'attendance' && in_array(($currentPage ?? ''), ['attendance', 'attendance_requests'], true)) $isActive = true;
                        ?>
                        <a href="<?php echo $pageKey; ?>" 
                           class="adm-tab-link <?php echo $isActive ? 'active' : ''; ?>">
                            <i class="fa-solid <?php echo $navItem['icon']; ?>"></i>
                            <span><?php echo htmlspecialchars($navItem['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </nav>

        <!-- Sidebar Backdrop Overlay -->
        <div class="adm-sidebar-backdrop" id="adminSidebarBackdrop" aria-hidden="true"></div>

        <!-- Slide-out Sidebar Drawer Navigation -->
        <aside class="adm-sidebar-drawer" id="adminSidebarDrawer" role="dialog" aria-label="Admin Navigation Menu" aria-hidden="true">
            <div class="adm-sidebar-header">
                <a href="dashboard" class="adm-sidebar-brand" title="<?php echo htmlspecialchars($adminBrandName); ?> Admin Dashboard">
                    <?php if ($adminBrandLogo): ?>
                        <img src="<?php echo htmlspecialchars($adminBrandLogo); ?>" alt="<?php echo htmlspecialchars($adminBrandName); ?>" class="adm-sidebar-logo-img">
                    <?php else: ?>
                        <div class="adm-brand-icon" style="width: 32px; height: 32px; font-size: 0.95rem;">
                            <i class="fa-solid fa-cloud-bolt"></i>
                        </div>
                    <?php endif; ?>
                    <span class="adm-brand-name" style="font-size: 1.05rem;"><?php echo htmlspecialchars($adminBrandName); ?></span>
                </a>
                <button type="button" class="adm-sidebar-close" id="adminSidebarClose" aria-label="Close menu" title="Close navigation menu">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="adm-sidebar-links" role="navigation" aria-label="Sidebar Navigation">
                <div class="adm-sidebar-section-title">Navigation</div>
                <?php foreach ($adminNavLinks as $pageKey => $navItem): ?>
                    <?php 
                        $isActive = (($currentPage ?? '') === $pageKey);
                        if ($pageKey === 'settings' && in_array(($currentPage ?? ''), ['settings', 'site_settings', 'office_location'], true)) $isActive = true;
                        if ($pageKey === 'attendance' && in_array(($currentPage ?? ''), ['attendance', 'attendance_requests'], true)) $isActive = true;
                    ?>
                    <a href="<?php echo $pageKey; ?>" 
                       class="adm-sidebar-link <?php echo $isActive ? 'active' : ''; ?>">
                        <i class="fa-solid <?php echo $navItem['icon']; ?>"></i>
                        <span><?php echo htmlspecialchars($navItem['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>
    </header>

    <!-- Main Workspace Container -->
    <main class="main-content">

    <script>
        // Sidebar Menu Open/Close Controller (Universal for Desktop, Tablet, & Mobile)
        (function() {
            const toggleBtn  = document.getElementById('adminMenuToggle');
            const toggleIcon = document.getElementById('adminMenuToggleIcon');
            const drawer     = document.getElementById('adminSidebarDrawer');
            const backdrop   = document.getElementById('adminSidebarBackdrop');
            const closeBtn   = document.getElementById('adminSidebarClose');

            function toggleSidebar(forceState) {
                if (!drawer) return;
                const willOpen = forceState !== undefined ? forceState : !drawer.classList.contains('open');

                if (willOpen) {
                    // Automatically close profile dropdown when sidebar opens
                    if (typeof window.adminCloseUserMenu === 'function') {
                        window.adminCloseUserMenu();
                    } else {
                        const userWrapper = document.getElementById('adminUserMenuWrapper');
                        const userBtn = document.getElementById('adminUserMenuBtn');
                        if (userWrapper?.classList.contains('open')) {
                            userWrapper.classList.remove('open');
                            userBtn?.setAttribute('aria-expanded', 'false');
                        }
                    }
                    document.body.classList.add('adm-sidebar-open');
                } else {
                    document.body.classList.remove('adm-sidebar-open');
                }

                drawer.classList.toggle('open', willOpen);
                drawer.setAttribute('aria-hidden', willOpen ? 'false' : 'true');
                if (backdrop) {
                    backdrop.classList.toggle('open', willOpen);
                    backdrop.setAttribute('aria-hidden', willOpen ? 'false' : 'true');
                }
                if (toggleBtn) {
                    toggleBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                    toggleBtn.classList.toggle('is-active', willOpen);
                }
                if (toggleIcon) {
                    toggleIcon.className = willOpen ? 'fa-solid fa-xmark' : 'fa-solid fa-bars';
                }
            }

            // Expose closer for cross-menu coordination
            window.adminCloseSidebar = function() {
                toggleSidebar(false);
            };

            toggleBtn?.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });

            closeBtn?.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar(false);
            });

            backdrop?.addEventListener('click', function() {
                toggleSidebar(false);
            });

            // Close on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && drawer?.classList.contains('open')) {
                    toggleSidebar(false);
                }
            });

            // Close when drawer link is clicked
            drawer?.querySelectorAll('.adm-sidebar-link').forEach(function(link) {
                link.addEventListener('click', function() {
                    toggleSidebar(false);
                });
            });

            // Auto-close drawer if resized back to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth > 1024 && drawer?.classList.contains('open')) {
                    toggleSidebar(false);
                }
            });
        })();

        // Admin User Profile Dropdown Controller
        (function() {
            const wrapper   = document.getElementById('adminUserMenuWrapper');
            const menuBtn   = document.getElementById('adminUserMenuBtn');

            if (!wrapper || !menuBtn) return;

            function toggleUserMenu(forceState) {
                const isOpen = forceState !== undefined ? forceState : !wrapper.classList.contains('open');

                if (isOpen) {
                    // Automatically close sidebar when profile dropdown opens
                    if (typeof window.adminCloseSidebar === 'function') {
                        window.adminCloseSidebar();
                    } else {
                        const sideDrawer = document.getElementById('adminSidebarDrawer');
                        if (sideDrawer?.classList.contains('open')) {
                            sideDrawer.classList.remove('open');
                            document.getElementById('adminSidebarBackdrop')?.classList.remove('open');
                            document.body.classList.remove('adm-sidebar-open');
                        }
                    }
                }

                wrapper.classList.toggle('open', isOpen);
                menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }

            // Expose closer for cross-menu coordination
            window.adminCloseUserMenu = function() {
                toggleUserMenu(false);
            };

            menuBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleUserMenu();
            });

            // Close on click outside
            document.addEventListener('click', function(e) {
                if (!wrapper.contains(e.target)) {
                    toggleUserMenu(false);
                }
            });

            // Close on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && wrapper.classList.contains('open')) {
                    toggleUserMenu(false);
                }
            });
        })();

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
