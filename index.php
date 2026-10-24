<?php
/**
 * Cloud Aspire - Employee Portal & Operations Hub
 * White-Label Modern SaaS Theme Synchronized with CloudAspire.in
 */
require_once 'app/db.php';
require_once 'app/security.php';

if (isset($pdo) && $pdo instanceof PDO) {
    Security::init($pdo);
}
Security::initSecureSession();

$isEmployeeLoggedIn = !empty($_SESSION['employee_logged_in']);
$employeeName = $_SESSION['employee_name'] ?? 'Team Member';

// Fetch active site settings for branding
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

// Fallback to logo file if present
if (!$siteLogo && file_exists(__DIR__ . '/assets/uploads/site/logo-96e285f855212224d62cf669.png')) {
    $siteLogo = 'assets/uploads/site/logo-96e285f855212224d62cf669.png';
}
if (!$siteFavicon && file_exists(__DIR__ . '/assets/uploads/site/favicon-b9f474fa06bc608e0ac6586c.png')) {
    $siteFavicon = 'assets/uploads/site/favicon-b9f474fa06bc608e0ac6586c.png';
}

// Fetch live database statistics to empower Cloud Aspire data display
$statsEmployees = 8;
$statsProjects = 69;
$statsTasksCompleted = 30;
$statsSatisfaction = '100%';

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $empCount = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
        if ($empCount !== false && $empCount > 0) $statsEmployees = (int)$empCount;

        $projCount = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
        if ($projCount !== false && $projCount > 0) $statsProjects = (int)$projCount;

        $taskCount = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'completed'")->fetchColumn();
        if ($taskCount !== false && $taskCount > 0) $statsTasksCompleted = (int)$taskCount;
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Cloud Aspire | Enterprise Growth Agency &amp; Workforce Portal</title>
    <meta name="description" content="Empowering Cloud Aspire — Digital Marketing, SEO, PPC, Social Media, Web Development Agency and Unified Employee Workspace Portal in Puducherry.">
    <link rel="canonical" href="https://cloudaspire.in/">
    <?php if ($siteFavicon): ?>
        <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($siteFavicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/png" href="https://cloudaspire.in/uploads/site_favicon_1783239900.png">
    <?php endif; ?>

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
        /* ══════════════════════════════════════════════
           DESIGN SYSTEM — White-Label SaaS Architecture
           ══════════════════════════════════════════════ */
        :root {
            --c-bg:             #ffffff; /* Pristine White SaaS Canvas */
            --c-bg-subtle:      #f8fafc; /* Crisp Slate Tint */
            --c-surface:        #ffffff; /* Elevated Card White */
            --c-surface-hover:  #f8fafc;
            
            --c-text:           #09090b; /* Ultra-Crisp Slate Black */
            --c-text-body:      #1e293b; /* High-Visibility Crisp Body */
            --c-muted:          #475569; /* High-Contrast Metadata Slate */
            --c-faint:          #64748b;
            
            --c-border:         #cbd5e1; /* Sharp high-visibility SaaS border */
            --c-border-subtle:  #e2e8f0;
            --c-border-hover:   #94a3b8;

            --c-brand:          #4f46e5; /* Modern Electric Indigo */
            --c-brand-hover:    #4338ca;
            --c-brand-light:    #eef2ff;
            --c-brand-border:   #c7d2fe;

            --c-dark-btn:       #0f172a; /* Sleek Obsidian SaaS Pill */
            --c-dark-btn-hover: #1e293b;

            --c-emerald:        #059669;
            --c-emerald-light:  #ecfdf5;

            --radius-xs:        6px;
            --radius-sm:        10px;
            --radius-md:        14px;
            --radius-lg:        18px;
            --radius-card:      20px;
            --radius-pill:      9999px;

            --shadow-xs:        0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-sm:        0 1px 3px 0 rgba(0, 0, 0, 0.08), 0 1px 2px -1px rgba(0, 0, 0, 0.08);
            --shadow-card:      0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 8px 20px -2px rgba(15, 23, 42, 0.06);
            --shadow-hover:     0 16px 32px -6px rgba(15, 23, 42, 0.12), 0 8px 16px -4px rgba(15, 23, 42, 0.06);
            --shadow-float:     0 25px 50px -12px rgba(15, 23, 42, 0.15);

            --transition:       0.28s cubic-bezier(0.16, 1, 0.3, 1);
            --max-w:            1260px;
        }

        html[data-theme="dark"] {
            --c-bg:             #090d16;
            --c-bg-subtle:      #0f172a;
            --c-surface:        #131d33;
            --c-surface-hover:  #18233d;
            --c-text:           #f8fafc;
            --c-text-body:      #e2e8f0;
            --c-muted:          #94a3b8;
            --c-faint:          #64748b;
            --c-border:         #2d3f66;
            --c-border-subtle:  #1e2a44;
            --c-border-hover:   #475569;
            --c-dark-btn:       #ffffff;
            --c-dark-btn-hover: #f1f5f9;
            --shadow-card:      0 4px 20px -2px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.06);
            --shadow-hover:     0 16px 36px -6px rgba(0, 0, 0, 0.7);
        }

        /* ── RESET & BASE ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Plus Jakarta Sans', 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--c-bg);
            color: var(--c-text-body);
            line-height: 1.65;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            transition: background-color 0.25s ease, color 0.25s ease;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif;
            font-weight: 700;
            line-height: 1.25;
            color: var(--c-text);
            letter-spacing: -0.025em;
        }

        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; height: auto; display: block; }

        .container {
            width: 100%;
            max-width: var(--max-w);
            margin: 0 auto;
            padding: 0 24px;
        }

        /* ── WHITE SAAS BUTTON SYSTEM ── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 24px;
            border-radius: var(--radius-pill);
            font-weight: 600;
            font-size: 0.92rem;
            cursor: pointer;
            transition: all var(--transition);
            white-space: nowrap;
            border: 1px solid transparent;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        /* High-contrast Obsidian SaaS Button */
        .btn-saas-primary {
            background: var(--c-dark-btn);
            color: #ffffff !important;
            border-color: var(--c-dark-btn);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 4px 12px rgba(15, 23, 42, 0.15);
        }
        html[data-theme="dark"] .btn-saas-primary {
            color: #090d16 !important;
        }
        .btn-saas-primary:hover {
            background: var(--c-dark-btn-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.25);
        }

        /* Electric Indigo Brand Button */
        .btn-brand {
            background: var(--c-brand);
            color: #ffffff !important;
            border-color: var(--c-brand);
            box-shadow: 0 1px 3px rgba(79, 70, 229, 0.2), 0 6px 16px rgba(79, 70, 229, 0.25);
        }
        .btn-brand:hover {
            background: var(--c-brand-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(79, 70, 229, 0.35);
        }

        /* Pure White SaaS Outline Button */
        .btn-white-outline {
            background: var(--c-surface);
            color: var(--c-text);
            border: 1px solid var(--c-border);
            box-shadow: var(--shadow-sm);
        }
        .btn-white-outline:hover {
            border-color: var(--c-border-hover);
            background: var(--c-bg-subtle);
            color: var(--c-brand);
            transform: translateY(-2px);
            box-shadow: var(--shadow-card);
        }

        /* ── NAVBAR (White-Label Frosted Glass) ── */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 76px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--c-border);
            z-index: 1000;
            transition: all var(--transition);
        }
        html[data-theme="dark"] .navbar {
            background: rgba(9, 13, 22, 0.88);
            border-bottom: 1px solid var(--c-border);
        }
        .navbar.scrolled {
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
            height: 70px;
        }

        .navbar .container {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            font-size: 1.35rem;
            color: var(--c-text);
            letter-spacing: -0.02em;
        }
        .brand-logo img {
            max-height: 44px;
            width: auto;
            object-fit: contain;
        }
        .brand-logo-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
        }
        .brand-saas-tag {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--c-brand);
            background: var(--c-brand-light);
            padding: 3px 8px;
            border-radius: var(--radius-pill);
            border: 1px solid var(--c-brand-border);
            margin-left: 4px;
        }

        .nav-menu {
            display: flex;
            align-items: center;
            gap: 28px;
            list-style: none;
        }
        .nav-link {
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--c-text-body);
            transition: color var(--transition);
        }
        .nav-link:hover {
            color: var(--c-brand);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* ── THEME TOGGLE BUTTON & DYNAMIC ANIMATIONS ── */
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
                        background-color 0.3s ease,
                        box-shadow 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        .theme-toggle-btn:hover {
            border-color: var(--c-brand);
            color: var(--c-brand);
            transform: scale(1.08);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.22);
        }
        .theme-toggle-btn:active {
            transform: scale(0.9) rotate(-12deg);
        }
        .theme-toggle-btn:focus-visible {
            outline: 2px solid var(--c-brand);
            outline-offset: 2px;
        }

        /* Animated Sun & Moon Icons */
        .theme-toggle-btn i {
            position: absolute;
            font-size: 1.15rem;
            transition: transform 0.55s cubic-bezier(0.34, 1.56, 0.64, 1),
                        opacity 0.35s cubic-bezier(0.16, 1, 0.3, 1),
                        filter 0.4s ease,
                        color 0.3s ease;
            pointer-events: none;
        }

        /* Light Mode Icon States */
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

        /* Dark Mode Icon States */
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

        /* Button Click Ripple Pulse Ring */
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
            0% {
                transform: scale(0.85);
                opacity: 1;
            }
            100% {
                transform: scale(1.85);
                opacity: 0;
            }
        }

        /* ── SILKY-SMOOTH THEME TRANSITION ARCHITECTURE (Mobile & Desktop) ── */
        ::view-transition-group(root) {
            animation-duration: 0.48s;
            animation-timing-function: cubic-bezier(0.22, 1, 0.36, 1);
        }
        ::view-transition-image-pair(root) {
            isolation: isolate;
        }
        ::view-transition-old(root),
        ::view-transition-new(root) {
            animation: none;
            mix-blend-mode: normal;
            display: block;
            height: 100%;
            overflow: clip;
        }
        ::view-transition-old(root) {
            z-index: 1;
        }
        ::view-transition-new(root) {
            z-index: 9999;
        }

        /* High-Performance Scoped Surface Transition (Fallback for browsers without View Transitions) */
        body.theme-fade-transitioning {
            transition: background-color 0.4s cubic-bezier(0.16, 1, 0.3, 1), color 0.3s ease !important;
        }
        body.theme-fade-transitioning .navbar,
        body.theme-fade-transitioning .section,
        body.theme-fade-transitioning .section-subtle,
        body.theme-fade-transitioning .portal-module-card,
        body.theme-fade-transitioning .service-card,
        body.theme-fade-transitioning .bento-card,
        body.theme-fade-transitioning .review-card,
        body.theme-fade-transitioning .stats-bar,
        body.theme-fade-transitioning .stat-item,
        body.theme-fade-transitioning .workspace-card-wrap,
        body.theme-fade-transitioning .mockup-header,
        body.theme-fade-transitioning .mockup-body,
        body.theme-fade-transitioning .preview-mini-stat,
        body.theme-fade-transitioning .mobile-nav-content,
        body.theme-fade-transitioning .theme-toggle-btn,
        body.theme-fade-transitioning .footer,
        body.theme-fade-transitioning .faq-item {
            transition: background-color 0.4s cubic-bezier(0.16, 1, 0.3, 1),
                        border-color 0.4s cubic-bezier(0.16, 1, 0.3, 1),
                        box-shadow 0.35s ease,
                        color 0.3s ease !important;
        }

        /* ── HERO SECTION (Clean White SaaS Architecture with Ambient Animations) ── */
        .hero {
            position: relative;
            padding: 150px 0 90px;
            background-color: var(--c-bg);
            background-image: 
                radial-gradient(circle at 50% 0%, rgba(79, 70, 229, 0.06) 0%, transparent 65%),
                radial-gradient(var(--c-border) 1.2px, transparent 1.2px);
            background-size: 100% 100%, 28px 28px;
            overflow: hidden;
            border-bottom: 1px solid var(--c-border);
        }

        /* Ambient Background Aurora Orbs */
        .hero-aurora-glow {
            position: absolute;
            border-radius: 50%;
            filter: blur(85px);
            pointer-events: none;
            z-index: 1;
            opacity: 0.55;
            will-change: transform;
        }
        html[data-theme="dark"] .hero-aurora-glow {
            opacity: 0.38;
            filter: blur(100px);
        }

        .hero-aurora-1 {
            width: 500px;
            height: 500px;
            top: 2%;
            right: 4%;
            background: radial-gradient(circle, rgba(79, 70, 229, 0.35) 0%, rgba(124, 58, 237, 0.18) 55%, transparent 75%);
            animation: auroraDrift1 16s ease-in-out infinite alternate;
        }

        .hero-aurora-2 {
            width: 440px;
            height: 440px;
            bottom: -5%;
            left: 5%;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.26) 0%, rgba(59, 130, 246, 0.14) 55%, transparent 75%);
            animation: auroraDrift2 18s ease-in-out infinite alternate;
        }

        .hero-aurora-3 {
            width: 340px;
            height: 340px;
            top: 25%;
            left: 42%;
            background: radial-gradient(circle, rgba(236, 72, 153, 0.16) 0%, transparent 70%);
            animation: auroraDrift1 14s ease-in-out infinite alternate-reverse;
        }

        @keyframes auroraDrift1 {
            0%   { transform: translate(0px, 0px) scale(1); }
            50%  { transform: translate(32px, -24px) scale(1.07); }
            100% { transform: translate(-26px, 18px) scale(0.94); }
        }

        @keyframes auroraDrift2 {
            0%   { transform: translate(0px, 0px) scale(1); }
            50%  { transform: translate(-28px, 26px) scale(1.08); }
            100% { transform: translate(24px, -18px) scale(0.93); }
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 52px;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        /* Hero Left Content Entrance Animation */
        .hero-content-reveal {
            animation: heroFadeInUp 0.85s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes heroFadeInUp {
            from {
                opacity: 0;
                transform: translateY(22px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .saas-pill-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            box-shadow: var(--shadow-sm);
            color: var(--c-text-body);
            padding: 6px 16px;
            border-radius: var(--radius-pill);
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            margin-bottom: 22px;
            position: relative;
            overflow: hidden;
            transition: all var(--transition);
        }
        .saas-pill-badge::after {
            content: '';
            position: absolute;
            top: 0;
            left: -150%;
            width: 60%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(79, 70, 229, 0.16), transparent);
            transform: skewX(-25deg);
            animation: badgeShimmer 4.5s ease-in-out infinite;
        }
        @keyframes badgeShimmer {
            0%, 70% { left: -150%; }
            100%    { left: 200%; }
        }
        .saas-pill-badge:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-card);
            border-color: var(--c-brand-border);
        }

        .live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--c-emerald);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25);
            animation: livePulse 2s infinite ease-in-out;
        }
        @keyframes livePulse {
            0%, 100% { transform: scale(1); opacity: 1; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25); }
            50%      { transform: scale(1.25); opacity: 0.85; box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.12); }
        }

        .hero h1 {
            font-size: clamp(2.3rem, 3.8vw, 3.35rem);
            line-height: 1.16;
            margin-bottom: 20px;
            color: var(--c-text);
            letter-spacing: -0.03em;
        }
        .hero h1 .highlight-brand {
            color: var(--c-brand);
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #06b6d4 100%);
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: gradientShift 8s ease infinite alternate;
        }
        @keyframes gradientShift {
            0%   { background-position: 0% 50%; }
            100% { background-position: 100% 50%; }
        }

        .hero p {
            font-size: 1.05rem;
            color: var(--c-text-body);
            margin-bottom: 32px;
            line-height: 1.75;
            max-width: 580px;
        }

        .hero-btns {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            align-items: center;
        }
        .hero-btns .btn {
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .hero-btns .btn:hover {
            transform: translateY(-3px);
        }

        /* ── HERO WORKSPACE PREVIEW CARD (Floating Stage & Ambient Glow) ── */
        .hero-visual-stage {
            position: relative;
            perspective: 1000px;
        }

        .workspace-card-glow {
            position: absolute;
            inset: -14px;
            background: radial-gradient(circle at 60% 40%, rgba(79, 70, 229, 0.24), rgba(124, 58, 237, 0.14), transparent 70%);
            border-radius: calc(var(--radius-card) + 16px);
            filter: blur(24px);
            z-index: 1;
            opacity: 0.8;
            animation: ambientGlowPulse 5s ease-in-out infinite alternate;
            pointer-events: none;
        }
        @keyframes ambientGlowPulse {
            0%   { transform: scale(0.97); opacity: 0.6; }
            100% { transform: scale(1.03); opacity: 0.95; }
        }

        .workspace-card-wrap {
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-float);
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            overflow: hidden;
            position: relative;
            z-index: 2;
            transition: transform 0.45s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.45s ease, border-color 0.3s ease;
            animation: heroWindowLevitate 6.5s ease-in-out infinite alternate;
            will-change: transform;
        }

        @keyframes heroWindowLevitate {
            0% {
                transform: translateY(0px) rotate(0deg);
            }
            50% {
                transform: translateY(-8px) rotate(0.35deg);
            }
            100% {
                transform: translateY(-2px) rotate(-0.35deg);
            }
        }

        .workspace-card-wrap:hover {
            animation-play-state: paused;
            transform: translateY(-6px) scale(1.008);
            box-shadow: 0 35px 70px -15px rgba(79, 70, 229, 0.2), 0 0 0 1px var(--c-brand-border);
        }

        /* Floating Interactive Micro-Pills on Hero Stage */
        .floating-badge-pill {
            position: absolute;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-pill);
            box-shadow: 0 14px 30px -6px rgba(15, 23, 42, 0.16);
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--c-text);
            z-index: 4;
            pointer-events: none;
            transition: all var(--transition);
        }
        html[data-theme="dark"] .floating-badge-pill {
            background: rgba(19, 29, 51, 0.94);
            box-shadow: 0 14px 30px -6px rgba(0, 0, 0, 0.55);
            border-color: var(--c-border);
        }

        .float-pill-1 {
            top: -14px;
            right: 24px;
            animation: floatPillA 5.2s ease-in-out infinite alternate;
        }
        .float-pill-1 i { color: #f59e0b; }

        .float-pill-2 {
            bottom: -16px;
            left: 18px;
            animation: floatPillB 5.8s ease-in-out infinite alternate;
        }
        .float-pill-2 i { color: var(--c-emerald); }

        @keyframes floatPillA {
            0%   { transform: translateY(0px) rotate(0deg); }
            100% { transform: translateY(-11px) rotate(1.4deg); }
        }
        @keyframes floatPillB {
            0%   { transform: translateY(0px) rotate(0deg); }
            100% { transform: translateY(10px) rotate(-1.4deg); }
        }

        /* Card Spotlight Mouse Tracking Glow */
        .card-spotlight {
            position: relative;
            overflow: hidden;
        }
        .card-spotlight::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: radial-gradient(350px circle at var(--mouse-x, 50%) var(--mouse-y, 50%), rgba(79, 70, 229, 0.08), transparent 45%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
            z-index: 1;
        }
        .card-spotlight:hover::before {
            opacity: 1;
        }

        /* Scroll-Triggered Reveal Animations */
        .reveal-on-scroll {
            opacity: 0;
            transform: translateY(26px);
            transition: opacity 0.75s cubic-bezier(0.16, 1, 0.3, 1), transform 0.75s cubic-bezier(0.16, 1, 0.3, 1);
            will-change: opacity, transform;
        }
        .reveal-on-scroll.revealed {
            opacity: 1;
            transform: translateY(0);
        }
        .reveal-delay-1 { transition-delay: 0.08s; }
        .reveal-delay-2 { transition-delay: 0.16s; }
        .reveal-delay-3 { transition-delay: 0.24s; }
        .reveal-delay-4 { transition-delay: 0.32s; }
        .reveal-delay-5 { transition-delay: 0.40s; }
        .reveal-delay-6 { transition-delay: 0.48s; }

        @media (prefers-reduced-motion: reduce) {
            .reveal-on-scroll {
                opacity: 1 !important;
                transform: none !important;
                transition: none !important;
            }
            .workspace-card-wrap,
            .floating-badge-pill,
            .hero-aurora-glow,
            .live-dot,
            .workspace-card-glow,
            .hero-content-reveal {
                animation: none !important;
            }
        }

        .mockup-header {
            height: 42px;
            background: var(--c-bg-subtle);
            display: flex;
            align-items: center;
            padding: 0 16px;
            gap: 8px;
            border-bottom: 1px solid var(--c-border);
        }
        .mockup-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .mockup-dot.red { background: #ff5f56; }
        .mockup-dot.yellow { background: #ffbd2e; }
        .mockup-dot.green { background: #27c93f; }

        .mockup-url-pill {
            margin-left: 12px;
            font-size: 0.76rem;
            font-family: 'Inter', monospace;
            color: var(--c-muted);
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            padding: 3px 12px;
            border-radius: var(--radius-pill);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .mockup-url-pill i { font-size: 0.65rem; color: var(--c-emerald); }

        .mockup-body {
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .portal-preview-badge {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--c-border);
        }

        .portal-user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .portal-user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);
        }
        .portal-user-details h4 {
            font-size: 0.95rem;
            margin-bottom: 2px;
        }
        .portal-user-details span {
            font-size: 0.78rem;
            color: var(--c-muted);
        }

        .status-pill-active {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--c-emerald);
            background: var(--c-emerald-light);
            border: 1px solid rgba(16, 185, 129, 0.2);
            padding: 4px 12px;
            border-radius: var(--radius-pill);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        html[data-theme="dark"] .status-pill-active {
            background: rgba(16, 185, 129, 0.15);
        }

        .portal-quick-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .preview-mini-stat {
            background: var(--c-bg-subtle);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-md);
            padding: 14px;
            transition: all var(--transition);
        }
        .preview-mini-stat:hover {
            background: var(--c-surface);
            border-color: var(--c-brand-border);
        }
        .preview-mini-stat span {
            font-size: 0.74rem;
            font-weight: 600;
            color: var(--c-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            display: block;
            margin-bottom: 4px;
        }
        .preview-mini-stat strong {
            font-size: 1.25rem;
            color: var(--c-text);
            font-family: 'Outfit', sans-serif;
        }

        .preview-btn-row {
            display: flex;
            gap: 10px;
            margin-top: 4px;
        }
        .preview-btn-row .btn {
            flex: 1;
            padding: 10px;
            font-size: 0.82rem;
            border-radius: var(--radius-sm);
        }

        /* ── STATS BAR (SaaS Ribbon) ── */
        .stats-section {
            padding: 0 24px;
            margin-top: -38px;
            position: relative;
            z-index: 10;
        }

        .stats-bar {
            background: var(--c-surface);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            border: 1px solid var(--c-border);
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            overflow: hidden;
        }

        .stat-item {
            text-align: center;
            padding: 28px 20px;
            border-right: 1px solid var(--c-border);
            transition: background var(--transition);
        }
        .stat-item:last-child { border-right: none; }
        .stat-item:hover { background: var(--c-bg-subtle); }

        .stat-num {
            font-size: clamp(2rem, 3.2vw, 2.6rem);
            font-weight: 800;
            color: var(--c-text);
            line-height: 1;
            margin-bottom: 6px;
            font-family: 'Outfit', sans-serif;
            letter-spacing: -0.03em;
        }
        .stat-num span { color: var(--c-brand); }

        .stat-label {
            color: var(--c-muted);
            font-size: 0.82rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* ── LOGO TICKER (Client & Ecosystem Strip) ── */
        .logo-cloud-section {
            padding: 70px 0 50px;
            background: var(--c-bg);
            border-bottom: 1px solid var(--c-border);
            overflow: hidden;
        }

        .logo-cloud-title {
            text-align: center;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: var(--c-muted);
            text-transform: uppercase;
            margin-bottom: 26px;
        }

        .logo-ticker-wrap {
            overflow: hidden;
            position: relative;
            width: 100%;
            mask-image: linear-gradient(to right, transparent 0%, #000 8%, #000 92%, transparent 100%);
            -webkit-mask-image: linear-gradient(to right, transparent 0%, #000 8%, #000 92%, transparent 100%);
        }

        .logo-ticker-track {
            display: flex;
            align-items: center;
            gap: 48px;
            width: max-content;
            animation: tickerMarquee 42s linear infinite;
        }
        .logo-ticker-wrap:hover .logo-ticker-track {
            animation-play-state: paused;
        }

        .ticker-item {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 0.98rem;
            font-weight: 700;
            color: var(--c-text-body);
            white-space: nowrap;
            opacity: 0.75;
            transition: all 0.25s ease;
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            padding: 8px 18px;
            border-radius: var(--radius-pill);
            box-shadow: var(--shadow-xs);
        }
        .ticker-item i {
            color: var(--c-brand);
            font-size: 1rem;
        }
        .ticker-item:hover {
            opacity: 1;
            border-color: var(--c-brand-border);
            color: var(--c-brand);
            transform: translateY(-2px);
        }

        @keyframes tickerMarquee {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        /* ── SECTION COMMON STYLES ── */
        .section,
        section[id] {
            padding: 96px 0;
            position: relative;
            scroll-margin-top: 85px;
        }
        .hash-anchor {
            position: relative;
            top: -85px;
            display: block;
            visibility: hidden;
            height: 0;
        }
        .section-subtle {
            background-color: var(--c-bg-subtle);
            border-top: 1px solid var(--c-border);
            border-bottom: 1px solid var(--c-border);
        }

        .section-header {
            text-align: center;
            max-width: 720px;
            margin: 0 auto 56px;
        }

        .section-header .sub-badge {
            display: inline-block;
            color: var(--c-brand);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 12px;
            background: var(--c-brand-light);
            border: 1px solid var(--c-brand-border);
            padding: 5px 14px;
            border-radius: var(--radius-pill);
        }
        html[data-theme="dark"] .section-header .sub-badge {
            background: rgba(79, 70, 229, 0.15);
        }

        .section-header h2 {
            font-size: clamp(2rem, 3.2vw, 2.6rem);
            margin-bottom: 14px;
        }

        .section-header p {
            font-size: 1.02rem;
            color: var(--c-muted);
            line-height: 1.7;
        }

        /* ── PORTAL MODULES GRID (6 White SaaS Cards) ── */
        .portal-modules-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 26px;
        }

        .portal-module-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-lg);
            padding: 34px 28px;
            box-shadow: var(--shadow-card);
            transition: all var(--transition);
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .portal-module-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--c-brand-border);
        }

        .module-icon-squircle {
            width: 52px;
            height: 52px;
            border-radius: var(--radius-md);
            background: var(--c-bg-subtle);
            border: 1px solid var(--c-border);
            color: var(--c-brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            margin-bottom: 20px;
            transition: all var(--transition);
        }
        .portal-module-card:hover .module-icon-squircle {
            background: var(--c-brand);
            border-color: var(--c-brand);
            color: #ffffff;
            transform: scale(1.05);
        }

        .portal-module-card h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .portal-module-card p {
            color: var(--c-muted);
            font-size: 0.92rem;
            line-height: 1.68;
            margin-bottom: 22px;
            flex-grow: 1;
        }

        .module-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--c-brand);
            font-weight: 700;
            font-size: 0.88rem;
            transition: gap var(--transition);
        }
        .module-link:hover { gap: 12px; }

        /* ── AGENCY SOLUTIONS GRID ── */
        .services-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 26px;
        }

        .service-card {
            background: var(--c-surface);
            border-radius: var(--radius-lg);
            padding: 36px 30px;
            border: 1px solid var(--c-border);
            box-shadow: var(--shadow-card);
            transition: all var(--transition);
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
            border-color: var(--c-brand-border);
        }

        .svc-icon-squircle {
            width: 52px;
            height: 52px;
            border-radius: var(--radius-md);
            background: var(--c-bg-subtle);
            border: 1px solid var(--c-border);
            color: var(--c-brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            margin-bottom: 18px;
            transition: all var(--transition);
        }
        .service-card:hover .svc-icon-squircle {
            background: var(--c-dark-btn);
            border-color: var(--c-dark-btn);
            color: #ffffff;
            transform: scale(1.05);
        }

        .svc-tag {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--c-brand);
            background: var(--c-brand-light);
            border: 1px solid var(--c-brand-border);
            padding: 3px 10px;
            border-radius: var(--radius-pill);
            margin-bottom: 14px;
            width: fit-content;
        }
        html[data-theme="dark"] .svc-tag {
            background: rgba(79, 70, 229, 0.15);
        }

        .service-card h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .service-card p {
            color: var(--c-muted);
            font-size: 0.92rem;
            line-height: 1.68;
            margin-bottom: 20px;
            flex-grow: 1;
        }

        .service-features {
            list-style: none;
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .service-features li {
            font-size: 0.85rem;
            color: var(--c-text-body);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .service-features li i {
            color: var(--c-emerald);
            font-size: 0.78rem;
        }

        /* ── WHY CLOUD ASPIRE / WORKFLOW BENTO ── */
        .bento-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 26px;
        }

        .bento-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-card);
            padding: 38px 34px;
            box-shadow: var(--shadow-card);
        }

        .bento-item-row {
            display: flex;
            gap: 18px;
            margin-bottom: 24px;
        }
        .bento-item-row:last-child { margin-bottom: 0; }
        .bento-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--c-brand-light);
            color: var(--c-brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
            border: 1px solid var(--c-brand-border);
        }
        .bento-content h4 {
            font-size: 1.05rem;
            margin-bottom: 4px;
        }
        .bento-content p {
            font-size: 0.9rem;
            color: var(--c-muted);
            line-height: 1.6;
        }

        /* ── TESTIMONIALS SECTION ── */
        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 26px;
        }

        .review-card {
            background: var(--c-surface);
            border-radius: var(--radius-card);
            padding: 32px 28px;
            border: 1px solid var(--c-border);
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all var(--transition);
        }
        .review-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
            border-color: var(--c-border-hover);
        }

        .stars {
            color: #f59e0b;
            font-size: 0.95rem;
            letter-spacing: 2px;
            margin-bottom: 14px;
        }

        .review-text {
            font-style: italic;
            color: var(--c-text-body);
            font-size: 0.94rem;
            line-height: 1.7;
            margin-bottom: 22px;
        }

        .reviewer {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .reviewer-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .reviewer-name {
            font-weight: 700;
            font-size: 0.92rem;
            color: var(--c-text);
        }
        .reviewer-role {
            font-size: 0.76rem;
            color: var(--c-muted);
        }

        /* ── FAQ ACCORDION (White SaaS Style) ── */
        .faq-wrap {
            max-width: 820px;
            margin: 0 auto;
        }

        .faq-item {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-md);
            margin-bottom: 12px;
            box-shadow: var(--shadow-xs);
            overflow: hidden;
            transition: all var(--transition);
        }
        .faq-item:hover {
            border-color: var(--c-brand-border);
        }

        .faq-trigger {
            width: 100%;
            background: none;
            border: none;
            cursor: pointer;
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            text-align: left;
            font-family: inherit;
            font-size: 1.02rem;
            font-weight: 700;
            color: var(--c-text);
            transition: color var(--transition);
        }
        .faq-arrow {
            color: var(--c-muted);
            transition: transform var(--transition);
            font-size: 0.88rem;
            flex-shrink: 0;
        }
        .faq-trigger[aria-expanded="true"] {
            color: var(--c-brand);
        }
        .faq-trigger[aria-expanded="true"] .faq-arrow {
            transform: rotate(180deg);
            color: var(--c-brand);
        }

        .faq-body {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s cubic-bezier(0.16, 1, 0.3, 1), padding 0.25s ease;
            padding: 0 24px;
            color: var(--c-muted);
            line-height: 1.75;
            font-size: 0.94rem;
        }
        .faq-body.open {
            max-height: 380px;
            padding: 0 24px 22px;
        }

        /* ── CALL TO ACTION SECTION ── */
        .cta-banner-section {
            padding: 80px 0;
            background-color: var(--c-bg);
        }
        .cta-banner-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            color: #ffffff;
            border-radius: var(--radius-card);
            padding: 60px 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 40px;
            box-shadow: var(--shadow-float);
            position: relative;
            overflow: hidden;
        }
        .cta-banner-card h2 {
            color: #ffffff;
            font-size: clamp(1.8rem, 3vw, 2.4rem);
            margin-bottom: 12px;
        }
        .cta-banner-card p {
            color: #cbd5e1;
            font-size: 1.02rem;
            max-width: 580px;
            line-height: 1.7;
        }
        .cta-banner-actions {
            display: flex;
            gap: 14px;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        /* ── WHITE SAAS FOOTER (Pristine White Architecture) ── */
        .site-footer {
            background: var(--c-surface);
            color: var(--c-text-body);
            padding-top: 80px;
            padding-bottom: 36px;
            position: relative;
            border-top: 1px solid var(--c-border);
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr 1.3fr;
            gap: 48px;
            margin-bottom: 60px;
        }

        .footer-brand h3 {
            color: var(--c-text);
            font-size: 1.35rem;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-brand p {
            font-size: 0.92rem;
            line-height: 1.7;
            margin-bottom: 20px;
            color: var(--c-muted);
        }

        .footer-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 14px;
            background: var(--c-emerald-light);
            border: 1px solid rgba(16, 185, 129, 0.25);
            border-radius: var(--radius-pill);
            font-size: 0.78rem;
            font-weight: 600;
            color: #065f46;
        }
        html[data-theme="dark"] .footer-status-pill {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }

        .footer-heading {
            font-size: 0.98rem;
            font-weight: 700;
            color: var(--c-text);
            margin-bottom: 18px;
        }

        .footer-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .footer-links a {
            font-size: 0.9rem;
            color: var(--c-muted);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all var(--transition);
        }
        .footer-links a:hover {
            color: var(--c-brand);
            transform: translateX(3px);
        }
        .footer-links a i {
            font-size: 0.72rem;
            color: var(--c-faint);
            transition: color var(--transition);
        }
        .footer-links a:hover i {
            color: var(--c-brand);
        }

        .footer-contact-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .footer-contact-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 0.9rem;
        }
        .footer-contact-item i {
            color: var(--c-brand);
            font-size: 1rem;
            margin-top: 3px;
        }
        .footer-contact-item strong {
            display: block;
            color: var(--c-text);
            font-size: 0.82rem;
        }
        .footer-contact-item a, .footer-contact-item span {
            color: var(--c-muted);
        }
        .footer-contact-item a:hover {
            color: var(--c-brand);
        }

        .footer-bottom {
            border-top: 1px solid var(--c-border);
            padding-top: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 0.86rem;
            color: var(--c-muted);
        }
        .footer-bottom a {
            color: var(--c-muted);
            transition: color var(--transition);
        }
        .footer-bottom a:hover {
            color: var(--c-text);
        }

        /* ── FLOATING WHATSAPP BUTTON ── */
        .floating-whatsapp {
            position: fixed;
            bottom: 36px;
            right: 36px;
            width: 56px;
            height: 56px;
            background: #25d366;
            color: #ffffff !important;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            box-shadow: 0 8px 24px rgba(37, 211, 102, 0.4);
            z-index: 9999;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration: none;
        }
        .floating-whatsapp:hover {
            transform: scale(1.1) translateY(-3px);
            box-shadow: 0 14px 30px rgba(37, 211, 102, 0.55);
        }

        /* ── MOBILE HAMBURGER BUTTON & DRAWER SYSTEM ── */
        .mobile-menu-toggle {
            display: none;
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            color: var(--c-text);
            cursor: pointer;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 5px;
            padding: 8px;
            transition: all var(--transition);
            outline: none;
        }
        .mobile-menu-toggle:hover {
            border-color: var(--c-brand);
            color: var(--c-brand);
        }
        .mobile-menu-toggle:focus-visible {
            outline: 2px solid var(--c-brand);
        }
        .hamburger-bar {
            width: 20px;
            height: 2px;
            background-color: currentColor;
            border-radius: 2px;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            transform-origin: center;
        }
        .mobile-menu-toggle.active .hamburger-bar:nth-child(1) {
            transform: translateY(7px) rotate(45deg);
        }
        .mobile-menu-toggle.active .hamburger-bar:nth-child(2) {
            opacity: 0;
            transform: scaleX(0);
        }
        .mobile-menu-toggle.active .hamburger-bar:nth-child(3) {
            transform: translateY(-7px) rotate(-45deg);
        }

        /* Mobile Navigation Drawer */
        .mobile-nav-drawer {
            position: fixed;
            inset: 0;
            z-index: 10000;
            visibility: hidden;
            pointer-events: none;
            transition: visibility 0.35s ease;
        }
        .mobile-nav-drawer.open {
            visibility: visible;
            pointer-events: auto;
        }
        .mobile-nav-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(9, 13, 22, 0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            opacity: 0;
            transition: opacity 0.35s ease;
        }
        .mobile-nav-drawer.open .mobile-nav-backdrop {
            opacity: 1;
        }
        .mobile-nav-content {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: min(85vw, 360px);
            background: var(--c-surface);
            border-left: 1px solid var(--c-border);
            box-shadow: -10px 0 35px rgba(0, 0, 0, 0.25);
            padding: 24px;
            display: flex;
            flex-direction: column;
            transform: translateX(100%);
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            overflow-y: auto;
        }
        .mobile-nav-drawer.open .mobile-nav-content {
            transform: translateX(0);
        }
        .mobile-nav-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--c-border);
            margin-bottom: 20px;
        }
        .mobile-nav-close {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--c-bg-subtle);
            border: 1px solid var(--c-border);
            color: var(--c-text);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.1rem;
            transition: all var(--transition);
        }
        .mobile-nav-close:hover {
            background: var(--c-brand-light);
            color: var(--c-brand);
            border-color: var(--c-brand-border);
        }
        .mobile-nav-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 24px;
        }
        .mobile-nav-link {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 14px;
            border-radius: var(--radius-md);
            color: var(--c-text);
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            transition: all var(--transition);
        }
        .mobile-nav-link i {
            width: 22px;
            color: var(--c-brand);
            font-size: 1rem;
            text-align: center;
        }
        .mobile-nav-link:hover, .mobile-nav-link:active {
            background: var(--c-brand-light);
            color: var(--c-brand);
            transform: translateX(4px);
        }
        .mobile-nav-actions {
            margin-top: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-top: 18px;
            border-top: 1px solid var(--c-border);
        }
        .w-100 { width: 100% !important; text-align: center; }

        /* ── RESPONSIVE BREAKPOINTS (Enhanced Visibility & Layout) ── */
        @media (max-width: 1024px) {
            .hero-grid { grid-template-columns: 1fr; gap: 42px; text-align: center; }
            .hero p, .hero-btns { justify-content: center; margin-inline: auto; }
            .portal-modules-grid { grid-template-columns: repeat(2, 1fr); }
            .services-grid { grid-template-columns: repeat(2, 1fr); }
            .reviews-grid { grid-template-columns: 1fr; }
            .bento-grid { grid-template-columns: 1fr; }
            .cta-banner-card { flex-direction: column; text-align: center; }
            .cta-banner-actions { justify-content: center; }
            .footer-grid { grid-template-columns: 1fr 1fr; gap: 36px; }
        }

        @media (max-width: 890px) {
            .nav-menu { display: none; }
            .mobile-menu-toggle { display: flex; }
            .nav-desktop-login { display: none; }
        }

        @media (max-width: 640px) {
            .container { padding: 0 16px; }
            .navbar { height: 68px; }
            .hero { padding: 110px 0 60px; }
            .hero h1 { font-size: clamp(1.9rem, 6.8vw, 2.6rem); margin-bottom: 16px; }
            .hero p { font-size: 0.98rem; margin-bottom: 24px; }
            .hero-btns { flex-direction: column; width: 100%; gap: 10px; }
            .hero-btns .btn { width: 100%; }
            .stats-bar { grid-template-columns: 1fr 1fr; gap: 12px; padding: 16px 12px; }
            .stat-item { border-right: none !important; border-bottom: none !important; padding: 10px 8px; }
            .stat-number { font-size: 1.7rem; }
            .stat-label { font-size: 0.78rem; }
            .portal-modules-grid { grid-template-columns: 1fr; gap: 16px; }
            .services-grid { grid-template-columns: 1fr; gap: 16px; }
            .footer-grid { grid-template-columns: 1fr; gap: 28px; }
            .footer-bottom { flex-direction: column; text-align: center; gap: 12px; }
            .floating-whatsapp { bottom: 18px; right: 18px; width: 50px; height: 50px; font-size: 24px; }
            .brand-saas-tag { display: none; }
        }

        @media (max-width: 380px) {
            .brand-logo { font-size: 1.15rem; gap: 8px; }
            .brand-logo-icon { width: 34px; height: 34px; font-size: 0.95rem; }
            .nav-actions { gap: 8px; }
            .theme-toggle-btn { width: 36px; height: 36px; }
            .navbar .btn { padding: 7px 12px !important; font-size: 0.8rem !important; }
        }
    </style>
</head>
<body>

    <!-- Navigation Header (White-Label Frosted Glass) -->
    <header class="navbar" id="navbar">
        <div class="container">
            <a href="#" class="brand-logo" aria-label="Cloud Aspire Home">
                <?php if ($siteLogo): ?>
                    <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="<?php echo htmlspecialchars($siteName); ?>">
                <?php else: ?>
                    <div class="brand-logo-icon">
                        <i class="fas fa-cloud-bolt"></i>
                    </div>
                    <span><?php echo htmlspecialchars($siteName); ?></span>
                <?php endif; ?>
                <span class="brand-saas-tag">Enterprise Hub</span>
            </a>

            <!-- Desktop Navigation Menu -->
            <nav aria-label="Main Navigation">
                <ul class="nav-menu">
                    <li><a href="#home" class="nav-link">Home</a></li>
                    <li><a href="#services" class="nav-link">Services</a></li>
                    <li><a href="#portal" class="nav-link">Workforce Hub</a></li>
                    <li><a href="#features" class="nav-link">Enterprise</a></li>
                    <li><a href="#reviews" class="nav-link">Reviews</a></li>
                    <li><a href="#faq" class="nav-link">FAQ</a></li>
                </ul>
            </nav>

            <div class="nav-actions">
                <button class="theme-toggle-btn" id="themeToggle" aria-label="Toggle theme mode" title="Toggle theme">
                    <i class="fas fa-sun icon-sun"></i>
                    <i class="fas fa-moon icon-moon"></i>
                </button>

                <?php if ($isEmployeeLoggedIn): ?>
                    <a href="employee_dashboard" class="btn btn-brand" style="padding: 9px 20px;">
                        <i class="fas fa-gauge-high"></i> Dashboard
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-saas-primary nav-desktop-login" style="padding: 9px 18px; font-size: 0.88rem;">
                        <i class="fas fa-arrow-right-to-bracket"></i> Login
                    </a>
                    <a href="https://wa.me/918098830937?text=Hello%20Cloud%20Aspire,%20I%20would%20like%20to%20consult%20for%20a%20project." 
                       target="_blank" 
                       rel="noopener" 
                       class="btn btn-white-outline" 
                       style="padding: 9px 18px; font-size: 0.88rem;">
                        <i class="fab fa-whatsapp" style="color: var(--c-emerald);"></i> Consult
                    </a>
                <?php endif; ?>

                <!-- Responsive Hamburger Toggle Button -->
                <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="mobileNavDrawer">
                    <span class="hamburger-bar"></span>
                    <span class="hamburger-bar"></span>
                    <span class="hamburger-bar"></span>
                </button>
            </div>
        </div>
    </header>

    <!-- Responsive Mobile Navigation Drawer -->
    <div class="mobile-nav-drawer" id="mobileNavDrawer" aria-hidden="true">
        <div class="mobile-nav-backdrop" id="mobileNavBackdrop"></div>
        <div class="mobile-nav-content">
            <div class="mobile-nav-header">
                <div class="brand-logo" style="font-size: 1.2rem;">
                    <div class="brand-logo-icon" style="width: 32px; height: 32px; font-size: 0.9rem;">
                        <i class="fas fa-cloud-bolt"></i>
                    </div>
                    <span><?php echo htmlspecialchars($siteName); ?></span>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <button class="theme-toggle-btn" id="themeToggleMobile" aria-label="Toggle theme mode" title="Toggle theme">
                        <i class="fas fa-sun icon-sun"></i>
                        <i class="fas fa-moon icon-moon"></i>
                    </button>
                    <button class="mobile-nav-close" id="mobileNavClose" aria-label="Close navigation menu">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>

            <ul class="mobile-nav-links">
                <li><a href="#home" class="mobile-nav-link"><i class="fas fa-house"></i> Home</a></li>
                <li><a href="#services" class="mobile-nav-link"><i class="fas fa-chart-line"></i> Growth &amp; Marketing</a></li>
                <li><a href="#portal" class="mobile-nav-link"><i class="fas fa-laptop-code"></i> Workforce Operations</a></li>
                <li><a href="#features" class="mobile-nav-link"><i class="fas fa-shield-halved"></i> Enterprise Features</a></li>
                <li><a href="#reviews" class="mobile-nav-link"><i class="fas fa-star"></i> Client Success</a></li>
                <li><a href="#faq" class="mobile-nav-link"><i class="fas fa-circle-question"></i> FAQ &amp; Help</a></li>
            </ul>

            <div class="mobile-nav-actions">
                <?php if ($isEmployeeLoggedIn): ?>
                    <a href="employee_dashboard" class="btn btn-brand w-100">
                        <i class="fas fa-gauge-high"></i> Employee Dashboard
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-saas-primary w-100">
                        <i class="fas fa-arrow-right-to-bracket"></i> Employee Portal Login
                    </a>
                    <a href="https://wa.me/918098830937?text=Hello%20Cloud%20Aspire,%20I%20would%20like%20to%20consult%20for%20a%20project." 
                       target="_blank" 
                       rel="noopener" 
                       class="btn btn-white-outline w-100">
                        <i class="fab fa-whatsapp" style="color: var(--c-emerald);"></i> Consult via WhatsApp
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <main>

        <!-- Hero Section (White SaaS Canvas) -->
        <!-- Hero Section (White SaaS Canvas with Modern Ambient Animations) -->
        <section class="hero" id="home">
            <!-- Dynamic Floating Aurora Gradients -->
            <div class="hero-aurora-glow hero-aurora-1" aria-hidden="true"></div>
            <div class="hero-aurora-glow hero-aurora-2" aria-hidden="true"></div>
            <div class="hero-aurora-glow hero-aurora-3" aria-hidden="true"></div>

            <div class="container hero-grid">
                
                <!-- Left: Headline & Actions (Entrance Animated) -->
                <div class="hero-content-reveal">
                    <div class="saas-pill-badge">
                        <span class="live-dot"></span>
                        Cloud Aspire Unified Operations &amp; Growth Suite
                    </div>

                    <h1>
                        Empowering <span class="highlight-brand">Cloud Aspire</span> — Digital Agency &amp; Team Workspace
                    </h1>

                    <p>
                        Scale client campaigns with data-backed SEO, targeted Google &amp; Meta Ads, high-converting web applications, and seamless workforce collaboration — all centralized within the Cloud Aspire ecosystem.
                    </p>

                    <div class="hero-btns">
                        <?php if ($isEmployeeLoggedIn): ?>
                            <a href="employee_dashboard" class="btn btn-brand">
                                <i class="fas fa-gauge-high"></i> Launch My Dashboard
                            </a>
                        <?php else: ?>
                            <a href="login" class="btn btn-saas-primary">
                                <i class="fas fa-user-lock"></i> Employee Portal Login
                            </a>
                        <?php endif; ?>

                        <a href="#services" class="btn btn-white-outline">
                            Explore Services <i class="fas fa-arrow-right" style="font-size: 0.8rem;"></i>
                        </a>
                    </div>
                </div>

                <!-- Right: Workspace Preview Card (Floating 3D Visual Stage) -->
                <div class="hero-visual-stage">
                    <!-- Soft Pulsing Glow -->
                    <div class="workspace-card-glow" aria-hidden="true"></div>

                    <!-- Floating Micro-Interaction Pills -->
                    <div class="floating-badge-pill float-pill-1" aria-hidden="true">
                        <i class="fas fa-bolt"></i>
                        <span><strong>99.4%</strong> Velocity</span>
                    </div>
                    <div class="floating-badge-pill float-pill-2" aria-hidden="true">
                        <i class="fas fa-shield-halved"></i>
                        <span><strong>Passkey 2FA</strong> Secured</span>
                    </div>

                    <div class="workspace-card-wrap">
                        <div class="mockup-header">
                            <span class="mockup-dot red"></span>
                            <span class="mockup-dot yellow"></span>
                            <span class="mockup-dot green"></span>
                            <span class="mockup-url-pill">
                                <i class="fas fa-lock"></i> cloudaspire.in/workspace
                            </span>
                        </div>

                        <div class="mockup-body">
                            <div class="portal-preview-badge">
                                <div class="portal-user-info">
                                    <div class="portal-user-avatar">
                                        <i class="fas fa-user-astronaut"></i>
                                    </div>
                                    <div class="portal-user-details">
                                        <h4><?php echo htmlspecialchars($isEmployeeLoggedIn ? $siteName : 'Cloud Aspire Workspace'); ?></h4>
                                        <span><?php echo $isEmployeeLoggedIn ? 'Active Verified Session' : 'Unified Workforce Platform'; ?></span>
                                    </div>
                                </div>
                                <div class="status-pill-active">
                                    <i class="fas fa-circle-check"></i> Operational
                                </div>
                            </div>

                            <!-- Live Bento Mini-Stats -->
                            <div class="portal-quick-grid">
                                <div class="preview-mini-stat">
                                    <span>Active Sprints</span>
                                    <strong><?php echo $statsProjects; ?> Projects</strong>
                                </div>
                                <div class="preview-mini-stat">
                                    <span>Tasks Completed</span>
                                    <strong><?php echo $statsTasksCompleted; ?>+ Delivered</strong>
                                </div>
                                <div class="preview-mini-stat">
                                    <span>Team Members</span>
                                    <strong><?php echo $statsEmployees; ?> Specialists</strong>
                                </div>
                                <div class="preview-mini-stat">
                                    <span>Client Satisfaction</span>
                                    <strong><?php echo $statsSatisfaction; ?> Rating</strong>
                                </div>
                            </div>

                            <!-- Quick Action Shortcuts -->
                            <div class="preview-btn-row">
                                <a href="employee_attendance" class="btn btn-white-outline">
                                    <i class="fas fa-fingerprint" style="color: var(--c-brand);"></i> Punch In
                                </a>
                                <a href="employee_task" class="btn btn-white-outline">
                                    <i class="fas fa-list-check" style="color: var(--c-brand);"></i> Tasks
                                </a>
                                <a href="employee_project" class="btn btn-white-outline">
                                    <i class="fas fa-layer-group" style="color: var(--c-brand);"></i> Projects
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </section>

        <!-- Live Statistics Bar (SaaS Floating Ribbon with Animated Counter Rollup) -->
        <section class="stats-section">
            <div class="container">
                <div class="stats-bar reveal-on-scroll">
                    <div class="stat-item">
                        <div class="stat-num" data-counter="<?php echo $statsProjects; ?>" data-suffix="+"><?php echo $statsProjects; ?><span>+</span></div>
                        <div class="stat-label">Client Projects Delivered</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-num" data-counter="<?php echo $statsTasksCompleted; ?>" data-suffix="+"><?php echo $statsTasksCompleted; ?><span>+</span></div>
                        <div class="stat-label">Sprint Tasks Resolved</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-num" data-counter="<?php echo $statsEmployees; ?>"><?php echo $statsEmployees; ?></div>
                        <div class="stat-label">In-House Specialists</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-num" data-counter="5.0" data-decimals="1" data-suffix="★">5.0<span>★</span></div>
                        <div class="stat-label">Client Satisfaction Rating</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Partner & Local Brand Ecosystem (from cloudaspire.in) -->
        <section class="logo-cloud-section">
            <div class="container">
                <div class="logo-cloud-title">
                    Trusted By Puducherry's Fastest-Growing Brands &bull; Powered By Cloud Aspire
                </div>
                <div class="logo-ticker-wrap">
                    <div class="logo-ticker-track">
                        <div class="ticker-item"><i class="fas fa-mug-hot"></i> Aura Cafe Pondy</div>
                        <div class="ticker-item"><i class="fas fa-user-doctor"></i> SurgiCare Clinic</div>
                        <div class="ticker-item"><i class="fas fa-cake-candles"></i> Pondy Bakes &amp; Treats</div>
                        <div class="ticker-item"><i class="fas fa-hotel"></i> Ocean View Hotel</div>
                        <div class="ticker-item"><i class="fas fa-pepper-hot"></i> Heritage Spices</div>
                        <div class="ticker-item"><i class="fas fa-tooth"></i> Lotus Dental Care</div>
                        <div class="ticker-item"><i class="fas fa-laptop-code"></i> Aditya TechLabs</div>
                        <div class="ticker-item"><i class="fas fa-building"></i> French Quarter Realty</div>
                        <!-- Duplicate track for seamless infinite marquee -->
                        <div class="ticker-item"><i class="fas fa-mug-hot"></i> Aura Cafe Pondy</div>
                        <div class="ticker-item"><i class="fas fa-user-doctor"></i> SurgiCare Clinic</div>
                        <div class="ticker-item"><i class="fas fa-cake-candles"></i> Pondy Bakes &amp; Treats</div>
                        <div class="ticker-item"><i class="fas fa-hotel"></i> Ocean View Hotel</div>
                        <div class="ticker-item"><i class="fas fa-pepper-hot"></i> Heritage Spices</div>
                        <div class="ticker-item"><i class="fas fa-tooth"></i> Lotus Dental Care</div>
                        <div class="ticker-item"><i class="fas fa-laptop-code"></i> Aditya TechLabs</div>
                        <div class="ticker-item"><i class="fas fa-building"></i> French Quarter Realty</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Employee Portal Workspace Modules (6 White SaaS Cards) -->
        <section class="section" id="portal">
            <span id="portal-hub" class="hash-anchor" aria-hidden="true"></span>
            <div class="container">
                <div class="section-header reveal-on-scroll">
                    <span class="sub-badge">Unified Workforce Suite</span>
                    <h2>Empowering Cloud Aspire Team</h2>
                    <p>
                        High-performance modules designed for daily agility, project tracking, sprint deliverables, and team productivity.
                    </p>
                </div>

                <div class="portal-modules-grid">
                    <!-- Module 1: Attendance Desk -->
                    <div class="portal-module-card card-spotlight reveal-on-scroll reveal-delay-1">
                        <div class="module-icon-squircle"><i class="fas fa-fingerprint"></i></div>
                        <h3>Daily Attendance &amp; Logs</h3>
                        <p>Streamlined punch-in/punch-out recording, automated shift calculation, IP verification, and punctuality logs.</p>
                        <a href="employee_attendance" class="module-link">Punch Attendance <i class="fas fa-arrow-right"></i></a>
                    </div>

                    <!-- Module 2: Project Management -->
                    <div class="portal-module-card card-spotlight reveal-on-scroll reveal-delay-2">
                        <div class="module-icon-squircle"><i class="fas fa-diagram-project"></i></div>
                        <h3>Client Project Hub</h3>
                        <p>Track multi-client digital deliverables across SEO sprints, Google Ads campaigns, web deployments, and creative assets.</p>
                        <a href="employee_project" class="module-link">View Projects <i class="fas fa-arrow-right"></i></a>
                    </div>

                    <!-- Module 3: Task & Sprint Tracker -->
                    <div class="portal-module-card card-spotlight reveal-on-scroll reveal-delay-3">
                        <div class="module-icon-squircle"><i class="fas fa-list-check"></i></div>
                        <h3>Agile Task &amp; Sprint Queue</h3>
                        <p>Prioritized task queues with priority badges, dynamic progress updates, file attachments, and completion logs.</p>
                        <a href="employee_task" class="module-link">Open Sprint Tasks <i class="fas fa-arrow-right"></i></a>
                    </div>

                    <!-- Module 4: Leaves & Time-Off -->
                    <div class="portal-module-card card-spotlight reveal-on-scroll reveal-delay-4">
                        <div class="module-icon-squircle"><i class="fas fa-calendar-check"></i></div>
                        <h3>Leave &amp; Time-Off Desk</h3>
                        <p>Transparent leave balances across Casual, Sick, and Earned leaves with seamless status tracking and quick requests.</p>
                        <a href="employee_leave" class="module-link">Request Time-Off <i class="fas fa-arrow-right"></i></a>
                    </div>

                    <!-- Module 5: Profile & Security -->
                    <div class="portal-module-card card-spotlight reveal-on-scroll reveal-delay-5">
                        <div class="module-icon-squircle"><i class="fas fa-id-card-clip"></i></div>
                        <h3>Employee Profile &amp; Settings</h3>
                        <p>Personal profile records, emergency contacts, designated role specifications, and high-security credential management.</p>
                        <a href="employee_account" class="module-link">Manage Profile <i class="fas fa-arrow-right"></i></a>
                    </div>

                    <!-- Module 6: Knowledge Base & SOPs -->
                    <div class="portal-module-card card-spotlight reveal-on-scroll reveal-delay-6">
                        <div class="module-icon-squircle"><i class="fas fa-book-bookmark"></i></div>
                        <h3>Knowledge Base &amp; SOPs</h3>
                        <p>Centralized playbooks, digital marketing checklists, client campaign frameworks, and creative design assets for seamless team execution.</p>
                        <a href="employee_dashboard" class="module-link">Access Resources <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Agency Solutions Section (from cloudaspire.in) -->
        <section class="section section-subtle" id="services">
            <div class="container">
                <div class="section-header reveal-on-scroll">
                    <span class="sub-badge">Agency Expertise</span>
                    <h2>End-to-End Digital Solutions</h2>
                    <p>
                        Comprehensive digital marketing, paid performance, and custom web development services engineered to drive tangible ROI.
                    </p>
                </div>

                <div class="services-grid">
                    <!-- Service 1: SEO -->
                    <div class="service-card card-spotlight reveal-on-scroll reveal-delay-1">
                        <span class="svc-tag">Organic Visibility</span>
                        <div class="svc-icon-squircle"><i class="fas fa-magnifying-glass-chart"></i></div>
                        <h3>Search Engine Optimization</h3>
                        <p>Rank higher on Google with on-page SEO, technical site speed audits, strategic keyword research, and white-hat backlinking.</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> Local Map Pack Domination</li>
                            <li><i class="fas fa-check"></i> Technical &amp; Core Web Vitals</li>
                            <li><i class="fas fa-check"></i> Competitor Keyword Gap Analysis</li>
                        </ul>
                        <a href="https://cloudaspire.in/services" target="_blank" rel="noopener" class="module-link">Learn More <i class="fas fa-arrow-right"></i></a>
                    </div>

                    <!-- Service 2: Google & Meta Ads -->
                    <div class="service-card card-spotlight reveal-on-scroll reveal-delay-2">
                        <span class="svc-tag">Performance Marketing</span>
                        <div class="svc-icon-squircle"><i class="fas fa-bullseye"></i></div>
                        <h3>Google &amp; Meta Ads (PPC)</h3>
                        <p>High-ROI pay-per-click ad campaigns across Google Search, Display, Meta Instagram &amp; Facebook with conversion tracking.</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> Targeted Audience Segmentation</li>
                            <li><i class="fas fa-check"></i> Conversion Rate Optimization</li>
                            <li><i class="fas fa-check"></i> Real-Time ROAS Analytics</li>
                        </ul>
                        <a href="https://cloudaspire.in/services" target="_blank" rel="noopener" class="module-link">Learn More <i class="fas fa-arrow-right"></i></a>
                    </div>

                    <!-- Service 3: Social Media Growth -->
                    <div class="service-card card-spotlight reveal-on-scroll reveal-delay-3">
                        <span class="svc-tag">Brand Engagement</span>
                        <div class="svc-icon-squircle"><i class="fas fa-share-nodes"></i></div>
                        <h3>Social Media Management</h3>
                        <p>Build engaged communities on Instagram, LinkedIn, and YouTube with scroll-stopping creatives, reels, and data-driven calendars.</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> High-Impact Creative Visuals</li>
                            <li><i class="fas fa-check"></i> Viral Reel &amp; Short-Form Content</li>
                            <li><i class="fas fa-check"></i> Active Follower Growth</li>
                        </ul>
                        <a href="https://cloudaspire.in/services" target="_blank" rel="noopener" class="module-link">Learn More <i class="fas fa-arrow-right"></i></a>
                    </div>

                    <!-- Service 4: Web & App Development -->
                    <div class="service-card card-spotlight reveal-on-scroll reveal-delay-4">
                        <span class="svc-tag">Custom Engineering</span>
                        <div class="svc-icon-squircle"><i class="fas fa-code"></i></div>
                        <h3>Web &amp; Software Development</h3>
                        <p>Lightning-fast custom business websites, e-commerce stores, and tailored web applications built for optimal conversion.</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> 100% Responsive &amp; Mobile First</li>
                            <li><i class="fas fa-check"></i> Fast Page Load Speeds (&lt; 2s)</li>
                            <li><i class="fas fa-check"></i> Secure Cloud Architecture</li>
                        </ul>
                        <a href="https://cloudaspire.in/services" target="_blank" rel="noopener" class="module-link">Learn More <i class="fas fa-arrow-right"></i></a>
                    </div>

                    <!-- Service 5: Branding & Design -->
                    <div class="service-card card-spotlight reveal-on-scroll reveal-delay-5">
                        <span class="svc-tag">Creative Direction</span>
                        <div class="svc-icon-squircle"><i class="fas fa-palette"></i></div>
                        <h3>Branding &amp; Visual Identity</h3>
                        <p>Memorable logos, cohesive design systems, corporate collateral, and packaging that make your business instantly recognizable.</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> Logo &amp; Brand Style Guides</li>
                            <li><i class="fas fa-check"></i> Marketing Banners &amp; Print</li>
                            <li><i class="fas fa-check"></i> Cohesive Social Media Kits</li>
                        </ul>
                        <a href="https://cloudaspire.in/services" target="_blank" rel="noopener" class="module-link">Learn More <i class="fas fa-arrow-right"></i></a>
                    </div>

                    <!-- Service 6: Content & Funnels -->
                    <div class="service-card card-spotlight reveal-on-scroll reveal-delay-6">
                        <span class="svc-tag">Lead Generation</span>
                        <div class="svc-icon-squircle"><i class="fas fa-filter-circle-dollar"></i></div>
                        <h3>Content &amp; Sales Funnels</h3>
                        <p>Persuasive copywriting, high-converting landing pages, email automation sequences, and CRM funnels that nurture prospects into buyers.</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> Lead Magnet Strategy</li>
                            <li><i class="fas fa-check"></i> High-Converting Copywriting</li>
                            <li><i class="fas fa-check"></i> Automated Email Nurturing</li>
                        </ul>
                        <a href="https://cloudaspire.in/services" target="_blank" rel="noopener" class="module-link">Learn More <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Why Choose Cloud Aspire / Operational Excellence Bento -->
        <section class="section" id="features">
            <span id="why-us" class="hash-anchor" aria-hidden="true"></span>
            <div class="container">
                <div class="section-header reveal-on-scroll">
                    <span class="sub-badge">Why Partner With Us</span>
                    <h2>Engineered for High-Performance Delivery</h2>
                    <p>
                        How our centralized portal bridges skilled talent with verifiable client growth.
                    </p>
                </div>

                <div class="bento-grid">
                    <!-- Bento Card 1: Core Pillars -->
                    <div class="bento-card card-spotlight reveal-on-scroll reveal-delay-1">
                        <div class="bento-item-row">
                            <div class="bento-icon"><i class="fas fa-bolt"></i></div>
                            <div class="bento-content">
                                <h4>Real-Time Sprint Transparency</h4>
                                <p>Our synchronized internal portal ensures every deliverable — from SEO audits to full-stack code deployments — is tracked in real-time with accountability.</p>
                            </div>
                        </div>
                        <div class="bento-item-row">
                            <div class="bento-icon"><i class="fas fa-chart-line"></i></div>
                            <div class="bento-content">
                                <h4>Data-Driven Return on Investment</h4>
                                <p>We don't rely on guesswork. Every marketing rupee invested is backed by analytics, search trends, audience metrics, and conversion tracking.</p>
                            </div>
                        </div>
                        <div class="bento-item-row">
                            <div class="bento-icon"><i class="fas fa-users-gear"></i></div>
                            <div class="bento-content">
                                <h4>Dedicated In-House Strategists</h4>
                                <p>Direct communication with certified developers, campaign managers, and content specialists based right in Puducherry.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Bento Card 2: Quick Highlights -->
                    <div class="bento-card card-spotlight reveal-on-scroll reveal-delay-2" style="display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <span class="sub-badge" style="margin-bottom: 16px;">Agency Overview</span>
                            <h3 style="font-size: 1.5rem; margin-bottom: 12px;">Cloud Aspire Growth Guarantee</h3>
                            <p style="color: var(--c-muted); font-size: 0.94rem; line-height: 1.7; margin-bottom: 24px;">
                                Serving local brands, e-commerce stores, and enterprise operations across India. Seamless delivery through our specialized employee ecosystem.
                            </p>
                        </div>
                        <div style="background: var(--c-bg-subtle); border: 1px solid var(--c-border); border-radius: var(--radius-md); padding: 18px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--c-text);">Sprint Completion Velocity</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--c-emerald);">99.4% On-Time</span>
                            </div>
                            <div style="height: 6px; width: 100%; background: var(--c-border); border-radius: 10px; overflow: hidden;">
                                <div style="height: 100%; width: 99.4%; background: var(--c-emerald);"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Client & Team Reviews Section -->
        <section class="section section-subtle" id="reviews">
            <div class="container">
                <div class="section-header reveal-on-scroll">
                    <span class="sub-badge">Client Testimonials</span>
                    <h2>Proven Results, Verified Feedback</h2>
                    <p>
                        What our enterprise partners and clients say about partnering with Cloud Aspire.
                    </p>
                </div>

                <div class="reviews-grid">
                    <div class="review-card card-spotlight reveal-on-scroll reveal-delay-1">
                        <div>
                            <div class="stars">★★★★★</div>
                            <p class="review-text">
                                "Cloud Aspire completely transformed our local visibility in Pondicherry. Our patient appointments from Google Maps grew by 240% within just 3 months. Their sprint tracking and transparency are unmatched."
                            </p>
                        </div>
                        <div class="reviewer">
                            <div class="reviewer-avatar" style="background: linear-gradient(135deg, #4f46e5, #06b6d4);">
                                DR
                            </div>
                            <div>
                                <div class="reviewer-name">Dr. Rajesh K.</div>
                                <div class="reviewer-role">Director, SurgiCare Clinic</div>
                            </div>
                        </div>
                    </div>

                    <div class="review-card card-spotlight reveal-on-scroll reveal-delay-2">
                        <div>
                            <div class="stars">★★★★★</div>
                            <p class="review-text">
                                "The custom web portal and e-commerce application developed by the Cloud Aspire team is remarkably fast. Checkout conversion jumped by 35% on mobile. Exceptional communication throughout."
                            </p>
                        </div>
                        <div class="reviewer">
                            <div class="reviewer-avatar" style="background: linear-gradient(135deg, #ec4899, #f43f5e);">
                                SB
                            </div>
                            <div>
                                <div class="reviewer-name">Senthil B.</div>
                                <div class="reviewer-role">Founder, Pondy Bakes &amp; Treats</div>
                            </div>
                        </div>
                    </div>

                    <div class="review-card card-spotlight reveal-on-scroll reveal-delay-3">
                        <div>
                            <div class="stars">★★★★★</div>
                            <p class="review-text">
                                "Their Google Ads team slashed our cost per qualified lead by half while scaling our monthly enquiries. We love the dedication and responsiveness of their specialists."
                            </p>
                        </div>
                        <div class="reviewer">
                            <div class="reviewer-avatar" style="background: linear-gradient(135deg, #10b981, #059669);">
                                AN
                            </div>
                            <div>
                                <div class="reviewer-name">Ananya N.</div>
                                <div class="reviewer-role">Marketing Head, Heritage Spices</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Interactive FAQ Accordion (from cloudaspire.in) -->
        <section class="section" id="faq">
            <div class="container">
                <div class="section-header">
                    <span class="sub-badge">Frequently Asked Questions</span>
                    <h2>Everything You Need to Know</h2>
                    <p>
                        Common questions about our services, workflow delivery, and employee portal access.
                    </p>
                </div>

                <div class="faq-wrap">
                    <div class="faq-item">
                        <button class="faq-trigger" aria-expanded="false" aria-controls="faq1" onclick="toggleFaq(this)">
                            <span>What digital marketing services does Cloud Aspire provide?</span>
                            <i class="fas fa-chevron-down faq-arrow"></i>
                        </button>
                        <div class="faq-body" id="faq1">
                            Cloud Aspire provides full-funnel digital marketing solutions including Search Engine Optimization (SEO), Google &amp; Meta Ads (PPC), Social Media Marketing (SMM), custom Web Development, Branding, and conversion copywriting.
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-trigger" aria-expanded="false" aria-controls="faq2" onclick="toggleFaq(this)">
                            <span>How does the Cloud Aspire Employee Portal empower delivery?</span>
                            <i class="fas fa-chevron-down faq-arrow"></i>
                        </button>
                        <div class="faq-body" id="faq2">
                            The centralized portal synchronizes our internal workforce across active projects, sprint task backlogs, biometric attendance punch-ins, and leave requests. This ensures clients receive rapid turnaround times with complete sprint accountability.
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-trigger" aria-expanded="false" aria-controls="faq3" onclick="toggleFaq(this)">
                            <span>How soon can we expect results from SEO &amp; Paid Campaigns?</span>
                            <i class="fas fa-chevron-down faq-arrow"></i>
                        </button>
                        <div class="faq-body" id="faq3">
                            Paid performance campaigns (Google Ads &amp; Meta Ads) start generating qualified clicks and leads within 48 to 72 hours of launch. Organic SEO typically shows measurable keyword ranking jumps within 60 to 90 days.
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-trigger" aria-expanded="false" aria-controls="faq4" onclick="toggleFaq(this)">
                            <span>How do team members log into the employee portal?</span>
                            <i class="fas fa-chevron-down faq-arrow"></i>
                        </button>
                        <div class="faq-body" id="faq4">
                            Active staff members can click the "Employee Login" button in the top navigation bar or access the portal at <code>/login</code> with their verified company credentials.
                        </div>
                    </div>

                    <div class="faq-item">
                        <button class="faq-trigger" aria-expanded="false" aria-controls="faq5" onclick="toggleFaq(this)">
                            <span>Where is Cloud Aspire located?</span>
                            <i class="fas fa-chevron-down faq-arrow"></i>
                        </button>
                        <div class="faq-body" id="faq5">
                            Cloud Aspire is headquartered in Puducherry (Pondicherry), India, serving regional businesses, national brands, and remote international enterprise clients.
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Call to Action Banner (White SaaS Accent) -->
        <section class="cta-banner-section">
            <div class="container">
                <div class="cta-banner-card">
                    <div>
                        <h2>Ready to Accelerate Your Digital Growth?</h2>
                        <p>
                            Partner with Cloud Aspire's dedicated team of digital strategists, engineers, and creative storytellers today.
                        </p>
                    </div>
                    <div class="cta-banner-actions">
                        <a href="https://wa.me/918098830937?text=Hello%20Cloud%20Aspire,%20I%20would%20like%20to%20consult%20for%20a%20project." 
                           target="_blank" 
                           rel="noopener" 
                           class="btn btn-brand">
                            <i class="fab fa-whatsapp"></i> Chat on WhatsApp
                        </a>
                        <a href="login" class="btn btn-white-outline">
                            <i class="fas fa-user-lock"></i> Employee Portal
                        </a>
                    </div>
                </div>
            </div>
        </section>

    </main>

    <!-- Enterprise Footer (Clean White Architecture) -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                
                <!-- Col 1: Brand & Status -->
                <div class="footer-brand">
                    <h3>
                        <?php if ($siteLogo): ?>
                            <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="<?php echo htmlspecialchars($siteName); ?>" style="max-height: 38px;">
                        <?php else: ?>
                            <i class="fas fa-cloud-bolt" style="color: var(--c-brand);"></i>
                        <?php endif; ?>
                        <span><?php echo htmlspecialchars($siteName); ?></span>
                    </h3>
                    <p>
                        Puducherry's premier digital growth agency and centralized workforce operations platform. Empowering local brands and scaling national businesses.
                    </p>
                    <div class="footer-status-pill">
                        <span class="live-dot" style="width: 6px; height: 6px;"></span>
                        All Systems Operational &bull; 99.9% Uptime
                    </div>
                </div>

                <!-- Col 2: Solutions -->
                <div>
                    <h4 class="footer-heading">Solutions</h4>
                    <ul class="footer-links">
                        <li><a href="https://cloudaspire.in/services" target="_blank" rel="noopener"><i class="fas fa-chevron-right"></i> SEO Optimization</a></li>
                        <li><a href="https://cloudaspire.in/services" target="_blank" rel="noopener"><i class="fas fa-chevron-right"></i> Google &amp; Meta Ads</a></li>
                        <li><a href="https://cloudaspire.in/services" target="_blank" rel="noopener"><i class="fas fa-chevron-right"></i> Social Media Growth</a></li>
                        <li><a href="https://cloudaspire.in/services" target="_blank" rel="noopener"><i class="fas fa-chevron-right"></i> Custom Web &amp; Apps</a></li>
                        <li><a href="https://cloudaspire.in/services" target="_blank" rel="noopener"><i class="fas fa-chevron-right"></i> Brand Identity Design</a></li>
                    </ul>
                </div>

                <!-- Col 3: Portal Links (EMPLOYEE ONLY) -->
                <div>
                    <h4 class="footer-heading">Workforce Portal</h4>
                    <ul class="footer-links">
                        <li><a href="login"><i class="fas fa-chevron-right"></i> Employee Login</a></li>
                        <li><a href="employee_attendance"><i class="fas fa-chevron-right"></i> Attendance Desk</a></li>
                        <li><a href="employee_project"><i class="fas fa-chevron-right"></i> Project Tracker</a></li>
                        <li><a href="employee_task"><i class="fas fa-chevron-right"></i> Sprint Tasks</a></li>
                        <li><a href="employee_leave"><i class="fas fa-chevron-right"></i> Leave Requests</a></li>
                        <li><a href="employee_account"><i class="fas fa-chevron-right"></i> My Account &amp; Profile</a></li>
                    </ul>
                </div>

                <!-- Col 4: Contact -->
                <div>
                    <h4 class="footer-heading">Contact &amp; Support</h4>
                    <ul class="footer-contact-list">
                        <li class="footer-contact-item">
                            <i class="fas fa-phone-alt"></i>
                            <div>
                                <strong>Direct Call</strong>
                                <a href="tel:8098830937">+91 8098830937</a>
                            </div>
                        </li>
                        <li class="footer-contact-item">
                            <i class="fab fa-whatsapp"></i>
                            <div>
                                <strong>WhatsApp Support</strong>
                                <a href="https://wa.me/918098830937?text=Hello%20Cloud%20Aspire,%20I%20would%20like%20to%20discuss%20a%20project." target="_blank" rel="noopener">Chat with Strategist</a>
                            </div>
                        </li>
                        <li class="footer-contact-item">
                            <i class="fas fa-envelope"></i>
                            <div>
                                <strong>Official Email</strong>
                                <a href="mailto:cloudaspire2025@gmail.com">cloudaspire2025@gmail.com</a>
                            </div>
                        </li>
                        <li class="footer-contact-item">
                            <i class="fas fa-location-dot"></i>
                            <div>
                                <strong>Headquarters</strong>
                                <span>Puducherry, India &bull; 605001</span>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <div>
                    &copy; <?php echo date('Y'); ?> <strong><?php echo htmlspecialchars($siteName); ?></strong>. All rights reserved. Built for modern workforce agility.
                </div>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <a href="https://cloudaspire.in/privacy-policy" target="_blank" rel="noopener">Privacy Policy</a>
                    <a href="https://cloudaspire.in/terms" target="_blank" rel="noopener">Terms of Service</a>
                    <a href="https://cloudaspire.in/refund-policy" target="_blank" rel="noopener">Refund Policy</a>
                    <a href="login">Staff Login</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Floating WhatsApp Action -->
    <a href="https://wa.me/918098830937?text=Hello%20Cloud%20Aspire,%20I%20am%20interested%20in%20scaling%20my%20business%20with%20your%20services." 
       class="floating-whatsapp" 
       target="_blank" 
       rel="noopener" 
       aria-label="Chat on WhatsApp with Cloud Aspire" 
       title="Chat with Cloud Aspire on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Interactivity Scripts -->
    <script>
        // ── Silky-Smooth Dark / Light Theme Controller (Mobile & Desktop) ──
        (function initThemeController() {
            const htmlRoot = document.documentElement;
            const toggleBtns = document.querySelectorAll('#themeToggle, #themeToggleMobile');
            let isThemeAnimating = false;

            function updateThemeUi(isDark) {
                toggleBtns.forEach(btn => {
                    btn.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
                    btn.setAttribute('title', isDark ? 'Switch to light theme' : 'Switch to dark theme');
                });
            }

            // Sync initial state
            updateThemeUi(htmlRoot.getAttribute('data-theme') === 'dark');

            function handleThemeToggle(e, clickedBtn) {
                if (isThemeAnimating) return;
                isThemeAnimating = true;

                const isCurrentlyDark = htmlRoot.getAttribute('data-theme') === 'dark';
                const nextTheme = isCurrentlyDark ? 'light' : 'dark';

                // Trigger button micro-animation on all theme buttons
                toggleBtns.forEach(b => {
                    b.classList.add('animating');
                    setTimeout(() => b.classList.remove('animating'), 600);
                });

                const applyThemeChange = () => {
                    if (nextTheme === 'dark') {
                        htmlRoot.setAttribute('data-theme', 'dark');
                        localStorage.setItem('theme', 'dark');
                    } else {
                        htmlRoot.removeAttribute('data-theme');
                        localStorage.setItem('theme', 'light');
                    }
                    updateThemeUi(nextTheme === 'dark');
                };

                // Check for View Transitions API support & user motion preference
                const supportsViewTransitions = Boolean(document.startViewTransition) && 
                    !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                if (supportsViewTransitions) {
                    // Accurately compute coordinates from touch or click
                    const rect = clickedBtn.getBoundingClientRect();
                    let x = rect.left + rect.width / 2;
                    let y = rect.top + rect.height / 2;

                    // If user tapped/clicked inside window with valid client coordinates, use them
                    if (e && typeof e.clientX === 'number' && e.clientX > 0 && e.clientX <= window.innerWidth) {
                        x = e.clientX;
                        y = e.clientY;
                    }

                    // Calculate distance to furthest screen corner
                    const endRadius = Math.hypot(
                        Math.max(x, window.innerWidth - x),
                        Math.max(y, window.innerHeight - y)
                    );

                    // Execute hardware-accelerated circular reveal
                    const transition = document.startViewTransition(() => {
                        applyThemeChange();
                    });

                    transition.ready.then(() => {
                        const anim = document.documentElement.animate(
                            {
                                clipPath: [
                                    `circle(0px at ${x.toFixed(1)}px ${y.toFixed(1)}px)`,
                                    `circle(${endRadius.toFixed(1)}px at ${x.toFixed(1)}px ${y.toFixed(1)}px)`
                                ]
                            },
                            {
                                duration: 480,
                                easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
                                pseudoElement: '::view-transition-new(root)'
                            }
                        );

                        anim.finished.finally(() => {
                            isThemeAnimating = false;
                        });
                    }).catch(() => {
                        isThemeAnimating = false;
                    });

                } else {
                    // Fallback for browsers without View Transitions:
                    // Use optimized, non-blocking scoped surface transitions (60fps on mobile)
                    document.body.classList.add('theme-fade-transitioning');
                    applyThemeChange();

                    setTimeout(() => {
                        document.body.classList.remove('theme-fade-transitioning');
                        isThemeAnimating = false;
                    }, 450);
                }
            }

            toggleBtns.forEach(btn => {
                btn.addEventListener('click', (e) => handleThemeToggle(e, btn));
            });
        })();

        // Sticky Navbar Effect on Scroll
        window.addEventListener('scroll', () => {
            const nav = document.getElementById('navbar');
            if (window.scrollY > 20) {
                nav?.classList.add('scrolled');
            } else {
                nav?.classList.remove('scrolled');
            }
        });

        // ── Mobile Drawer Navigation Toggle ──
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileNavDrawer = document.getElementById('mobileNavDrawer');
        const mobileNavClose = document.getElementById('mobileNavClose');
        const mobileNavBackdrop = document.getElementById('mobileNavBackdrop');
        const mobileNavLinks = document.querySelectorAll('.mobile-nav-link');

        function openMobileMenu() {
            mobileNavDrawer?.classList.add('open');
            mobileMenuToggle?.classList.add('active');
            mobileMenuToggle?.setAttribute('aria-expanded', 'true');
            mobileNavDrawer?.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileMenu() {
            mobileNavDrawer?.classList.remove('open');
            mobileMenuToggle?.classList.remove('active');
            mobileMenuToggle?.setAttribute('aria-expanded', 'false');
            mobileNavDrawer?.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        mobileMenuToggle?.addEventListener('click', () => {
            if (mobileNavDrawer?.classList.contains('open')) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        });

        mobileNavClose?.addEventListener('click', closeMobileMenu);
        mobileNavBackdrop?.addEventListener('click', closeMobileMenu);
        mobileNavLinks.forEach(link => {
            link.addEventListener('click', closeMobileMenu);
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && mobileNavDrawer?.classList.contains('open')) {
                closeMobileMenu();
            }
        });

        // Interactive FAQ Accordion
        function toggleFaq(btn) {
            const isExpanded = btn.getAttribute('aria-expanded') === 'true';
            const bodyId = btn.getAttribute('aria-controls');
            const targetBody = document.getElementById(bodyId);

            // Collapse all
            document.querySelectorAll('.faq-trigger').forEach(b => {
                b.setAttribute('aria-expanded', 'false');
                const bId = b.getAttribute('aria-controls');
                if (bId) document.getElementById(bId)?.classList.remove('open');
            });

            // Expand selected
            if (!isExpanded && targetBody) {
                btn.setAttribute('aria-expanded', 'true');
                targetBody.classList.add('open');
            }
        }

        // ── SCROLL REVEAL ANIMATIONS (IntersectionObserver) ──
        (function initScrollReveal() {
            const revealElements = document.querySelectorAll('.reveal-on-scroll');
            if (!revealElements.length) return;

            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries, obs) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('revealed');
                            obs.unobserve(entry.target);
                        }
                    });
                }, {
                    root: null,
                    threshold: 0.12,
                    rootMargin: '0px 0px -40px 0px'
                });

                revealElements.forEach(el => observer.observe(el));
            } else {
                revealElements.forEach(el => el.classList.add('revealed'));
            }
        })();

        // ── DYNAMIC NUMBER COUNTER ANIMATION (Stats Ribbon) ──
        (function initStatsCounter() {
            const statsBar = document.querySelector('.stats-bar');
            if (!statsBar) return;

            let animated = false;
            function runCounters() {
                if (animated) return;
                animated = true;

                const counters = document.querySelectorAll('.stat-num[data-counter]');
                counters.forEach(counter => {
                    const targetStr = counter.getAttribute('data-counter');
                    const target = parseFloat(targetStr);
                    const decimals = parseInt(counter.getAttribute('data-decimals') || '0', 10);
                    const suffix = counter.getAttribute('data-suffix') || '';
                    const duration = 1800; // ms
                    const startTime = performance.now();

                    function updateNumber(currentTime) {
                        const elapsed = currentTime - startTime;
                        const progress = Math.min(elapsed / duration, 1);
                        // Ease-out cubic formula
                        const easeProgress = 1 - Math.pow(1 - progress, 3);
                        const currentVal = (target * easeProgress).toFixed(decimals);

                        if (suffix) {
                            counter.innerHTML = `${currentVal}<span>${suffix}</span>`;
                        } else {
                            counter.textContent = currentVal;
                        }

                        if (progress < 1) {
                            requestAnimationFrame(updateNumber);
                        } else {
                            if (suffix) {
                                counter.innerHTML = `${target.toFixed(decimals)}<span>${suffix}</span>`;
                            } else {
                                counter.textContent = target.toFixed(decimals);
                            }
                        }
                    }

                    requestAnimationFrame(updateNumber);
                });
            }

            if ('IntersectionObserver' in window) {
                const statsObserver = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            runCounters();
                            statsObserver.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.2 });

                statsObserver.observe(statsBar);
            } else {
                runCounters();
            }
        })();

        // ── INTERACTIVE MOUSE SPOTLIGHT (Radial Glow on Hover) ──
        (function initCardSpotlight() {
            const spotlightCards = document.querySelectorAll('.card-spotlight');
            spotlightCards.forEach(card => {
                card.addEventListener('mousemove', (e) => {
                    const rect = card.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    card.style.setProperty('--mouse-x', `${x}px`);
                    card.style.setProperty('--mouse-y', `${y}px`);
                });
            });
        })();

        // ── 3D PARALLAX TILT ON HERO WORKSPACE CARD ──
        (function initHeroTilt() {
            const stage = document.querySelector('.hero-visual-stage');
            const card = document.querySelector('.workspace-card-wrap');
            if (!stage || !card || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

            // Only enable interactive 3D tilt on desktop screens
            if (window.innerWidth >= 992) {
                let rafId = null;

                stage.addEventListener('mousemove', (e) => {
                    if (rafId) cancelAnimationFrame(rafId);
                    rafId = requestAnimationFrame(() => {
                        const rect = stage.getBoundingClientRect();
                        const x = e.clientX - rect.left;
                        const y = e.clientY - rect.top;
                        
                        const centerX = rect.width / 2;
                        const centerY = rect.height / 2;

                        // Subtle tilt range (-4deg to +4deg)
                        const rotateX = ((y - centerY) / centerY) * -4.5;
                        const rotateY = ((x - centerX) / centerX) * 4.5;

                        card.style.transform = `perspective(1000px) rotateX(${rotateX.toFixed(2)}deg) rotateY(${rotateY.toFixed(2)}deg) translateY(-8px) scale(1.01)`;
                    });
                });

                stage.addEventListener('mouseleave', () => {
                    if (rafId) cancelAnimationFrame(rafId);
                    card.style.transform = '';
                });
            }
        })();
    </script>
</body>
</html>
