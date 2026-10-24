<?php
// Determine the current page for active link styling
$currentPage = basename($_SERVER['PHP_SELF']);

// Automatically ensure employee code is synced to session for active logins
if (!empty($_SESSION['employee_id']) && empty($_SESSION['employee_code']) && isset($pdo) && $pdo instanceof PDO) {
    try {
        $cStmt = $pdo->prepare("SELECT employee_id FROM employees WHERE id = ? LIMIT 1");
        $cStmt->execute([$_SESSION['employee_id']]);
        $cVal = $cStmt->fetchColumn();
        $_SESSION['employee_code'] = !empty($cVal) ? trim($cVal) : ('EMP-' . str_pad($_SESSION['employee_id'], 3, '0', STR_PAD_LEFT));
    } catch (PDOException $e) {}
}

// Load the active branding record
$activeSiteSettings = null;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->query("SELECT site_name, logo, favicon FROM site_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1");
        $activeSiteSettings = $stmt->fetch();
    } catch (PDOException $e) {}
}
$brandLogo = !empty($activeSiteSettings['logo']) ? 'assets/uploads/site/' . rawurlencode($activeSiteSettings['logo']) : null;
$siteName  = !empty($activeSiteSettings['site_name']) ? $activeSiteSettings['site_name'] : 'Cloud Aspire';

$navLinks = [
    'employee_attendance' => ['label' => 'Attendance', 'icon' => 'fa-calendar-check'],
    'employee_project'    => ['label' => 'Projects', 'icon' => 'fa-folder-open'],
    'employee_task'       => ['label' => 'Tasks', 'icon' => 'fa-list-check'],
    'employee_leave'      => ['label' => 'Leaves', 'icon' => 'fa-calendar-xmark'],
    'employee_account'    => ['label' => 'My Account', 'icon' => 'fa-user-gear'],
];
?>

<!-- ===== NAVBAR STYLES ===== -->
<style>
/* ---------- CSS Variables & Theme Support ---------- */
:root {
    --emp-nav-h:        66px;
    --emp-nav-bg:       rgba(255, 255, 255, 0.92);
    --emp-nav-border:   #cbd5e1;
    --emp-nav-shadow:   0 2px 14px rgba(15, 23, 42, 0.05);

    --emp-primary:      #4f46e5;
    --emp-primary-dark: #3730a3;
    --emp-primary-light:#eef2ff;

    --emp-text-main:    #09090b;
    --emp-text-body:    #1e293b;
    --emp-text-muted:   #475569;
    --emp-text-nav:     #334155;

    --emp-surface:      #ffffff;
    --emp-surface-subtle: #f8fafc;
    --emp-border:       #cbd5e1;

    --emp-link-hover:   #f1f5f9;
    --emp-active-color: #4f46e5;
    --emp-active-bg:    #eef2ff;

    --emp-logout-bg:    #fee2e2;
    --emp-logout-color: #b91c1c;
    --emp-logout-hover: #fca5a5;

    --emp-radius-sm:    8px;
    --emp-radius-md:    12px;
    --emp-radius-pill:  9999px;
    --emp-transition:   0.24s cubic-bezier(0.16, 1, 0.3, 1);
}

html[data-theme="dark"] {
    --emp-nav-bg:       rgba(9, 13, 22, 0.92);
    --emp-nav-border:   #2d3f66;
    --emp-nav-shadow:   0 4px 20px rgba(0, 0, 0, 0.4);

    --emp-text-main:    #f8fafc;
    --emp-text-body:    #e2e8f0;
    --emp-text-muted:   #94a3b8;
    --emp-text-nav:     #cbd5e1;

    --emp-surface:      #131d33;
    --emp-surface-subtle: #0f172a;
    --emp-border:       #2d3f66;

    --emp-link-hover:   #18233d;
    --emp-active-color: #818cf8;
    --emp-active-bg:    rgba(79, 70, 229, 0.22);

    --emp-logout-bg:    rgba(225, 29, 72, 0.16);
    --emp-logout-color: #f43f5e;
    --emp-logout-hover: rgba(225, 29, 72, 0.26);
}

/* ---------- Navbar Shell ---------- */
.emp-navbar {
    position: sticky;
    top: 0;
    z-index: 1000;
    height: var(--emp-nav-h);
    background: var(--emp-nav-bg);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--emp-nav-border);
    box-shadow: var(--emp-nav-shadow);
    width: 100%;
    padding: 0 24px;
    transition: background-color 0.3s ease, border-color 0.3s ease;
}

.emp-nav-container {
    max-width: 1440px;
    width: 100%;
    height: 100%;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

/* ---------- Brand ---------- */
.emp-nav-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    flex-shrink: 0;
}
.emp-nav-brand img {
    height: 38px;
    max-width: 150px;
    object-fit: contain;
    transition: transform var(--emp-transition);
}
.emp-nav-brand:hover img { transform: scale(1.04); }
.emp-nav-brand-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    border-radius: var(--emp-radius-sm);
    display: flex; align-items: center; justify-content: center;
    color: #fff;
    font-size: 1.05rem;
    box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
}
.emp-nav-brand-name {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--emp-text-main);
    letter-spacing: -0.02em;
}
.emp-nav-badge {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--emp-primary);
    background: var(--emp-primary-light);
    padding: 2px 7px;
    border-radius: var(--emp-radius-pill);
    border: 1px solid rgba(79, 70, 229, 0.25);
    margin-left: 2px;
}
html[data-theme="dark"] .emp-nav-badge {
    background: rgba(79, 70, 229, 0.2);
    color: #818cf8;
}

/* ---------- Desktop Navigation Links ---------- */
.emp-nav-links {
    display: flex;
    align-items: center;
    gap: 4px;
    flex: 1;
    justify-content: center;
}

.emp-nav-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: var(--emp-radius-sm);
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--emp-text-nav);
    text-decoration: none;
    white-space: nowrap;
    transition: all var(--emp-transition);
}
.emp-nav-link i {
    font-size: 0.95rem;
    color: var(--emp-text-muted);
    transition: color var(--emp-transition);
}
.emp-nav-link:hover {
    background: var(--emp-link-hover);
    color: var(--emp-primary);
}
.emp-nav-link:hover i {
    color: var(--emp-primary);
}
.emp-nav-link.active {
    background: var(--emp-active-bg);
    color: var(--emp-active-color);
    font-weight: 700;
}
.emp-nav-link.active i {
    color: var(--emp-active-color);
}

/* ---------- Right Actions: Theme Toggle, User, Logout ---------- */
.emp-nav-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

/* Animated Theme Toggle Button */
.emp-theme-btn {
    position: relative;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--emp-surface);
    border: 1px solid var(--emp-border);
    color: var(--emp-text-body);
    cursor: pointer;
    overflow: hidden;
    padding: 0;
    outline: none;
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1),
                border-color 0.3s ease,
                box-shadow 0.3s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.emp-theme-btn:hover {
    border-color: var(--emp-primary);
    transform: scale(1.08);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
}
.emp-theme-btn:active {
    transform: scale(0.92) rotate(-10deg);
}
.emp-theme-btn i {
    position: absolute;
    font-size: 1.05rem;
    transition: transform 0.55s cubic-bezier(0.34, 1.56, 0.64, 1),
                opacity 0.35s cubic-bezier(0.16, 1, 0.3, 1),
                filter 0.4s ease,
                color 0.3s ease;
    pointer-events: none;
}
.emp-theme-btn .icon-sun {
    color: #f59e0b;
    transform: scale(1) rotate(0deg);
    opacity: 1;
    filter: drop-shadow(0 0 5px rgba(245, 158, 11, 0.6));
}
.emp-theme-btn .icon-moon {
    color: #818cf8;
    transform: scale(0.2) rotate(-100deg);
    opacity: 0;
}
html[data-theme="dark"] .emp-theme-btn {
    background: #131d33;
    border-color: #334155;
    box-shadow: 0 0 12px rgba(99, 102, 241, 0.25);
}
html[data-theme="dark"] .emp-theme-btn .icon-sun {
    transform: scale(0.2) rotate(100deg);
    opacity: 0;
}
html[data-theme="dark"] .emp-theme-btn .icon-moon {
    transform: scale(1) rotate(0deg);
    opacity: 1;
    filter: drop-shadow(0 0 7px rgba(129, 140, 248, 0.75));
}

.emp-user-profile-link {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 5px 12px;
    border-radius: var(--emp-radius-pill);
    background: var(--emp-surface-subtle);
    border: 1px solid var(--emp-border);
    text-decoration: none;
    color: var(--emp-text-main);
    font-size: 0.88rem;
    font-weight: 600;
    transition: all var(--emp-transition);
}
.emp-user-profile-link:hover {
    border-color: var(--emp-primary);
    color: var(--emp-primary);
}
.emp-nav-code-badge {
    display: inline-flex;
    align-items: center;
    font-size: 0.74rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: var(--emp-radius-pill);
    background: var(--emp-primary-light);
    color: var(--emp-primary);
    border: 1px solid rgba(79, 70, 229, 0.25);
    letter-spacing: 0.02em;
    font-family: 'Outfit', 'Plus Jakarta Sans', monospace, sans-serif;
}
html[data-theme="dark"] .emp-nav-code-badge {
    background: rgba(79, 70, 229, 0.22);
    color: #818cf8;
    border-color: rgba(99, 102, 241, 0.35);
}
.emp-user-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff;
    font-size: 0.76rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
}

.emp-btn-logout {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: var(--emp-radius-sm);
    background: var(--emp-logout-bg);
    color: var(--emp-logout-color);
    font-size: 0.86rem;
    font-weight: 600;
    text-decoration: none;
    transition: all var(--emp-transition);
    white-space: nowrap;
}
.emp-btn-logout:hover {
    background: var(--emp-logout-hover);
    color: #991b1b;
    transform: translateY(-1px);
}

/* ---------- Hamburger Button ---------- */
.emp-nav-hamburger {
    display: none;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 5px;
    width: 38px; height: 38px;
    border: 1px solid var(--emp-border);
    background: var(--emp-surface);
    cursor: pointer;
    border-radius: var(--emp-radius-sm);
    transition: all var(--emp-transition);
    flex-shrink: 0;
    outline: none;
}
.emp-nav-hamburger:hover {
    border-color: var(--emp-primary);
    color: var(--emp-primary);
}
.emp-nav-hamburger span {
    display: block;
    width: 20px; height: 2px;
    background: var(--emp-text-main);
    border-radius: 2px;
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.2s ease;
}
.emp-nav-hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.emp-nav-hamburger.open span:nth-child(2) { opacity: 0; }
.emp-nav-hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

/* ---------- Mobile Drawer Navigation & Backdrop ---------- */
.emp-nav-backdrop {
    display: none;
    position: fixed;
    top: var(--emp-nav-h);
    left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 998;
}
.emp-nav-backdrop.open { display: block; }

.emp-nav-drawer {
    display: none;
    position: fixed;
    top: var(--emp-nav-h);
    left: 0; right: 0;
    background: var(--emp-surface);
    border-bottom: 1px solid var(--emp-border);
    box-shadow: 0 12px 32px rgba(0,0,0,0.18);
    padding: 16px 20px 24px;
    z-index: 999;
    animation: empSlideDown 0.25s ease;
}
@keyframes empSlideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to   { opacity: 1; transform: translateY(0); }
}
.emp-nav-drawer.open { display: block; }

.emp-drawer-links {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-bottom: 16px;
}
.emp-drawer-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--emp-text-body);
    border-radius: var(--emp-radius-md);
    text-decoration: none;
    transition: all var(--emp-transition);
}
.emp-drawer-link i {
    width: 22px;
    text-align: center;
    color: var(--emp-primary);
    font-size: 1.05rem;
}
.emp-drawer-link:hover, .emp-drawer-link.active {
    background: var(--emp-active-bg);
    color: var(--emp-active-color);
}

.emp-drawer-divider {
    border: none;
    border-top: 1px solid var(--emp-border);
    margin: 12px 0 16px;
}

.emp-drawer-user {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.emp-drawer-greeting {
    font-size: 0.88rem;
    color: var(--emp-text-muted);
}
.emp-drawer-greeting strong {
    display: block;
    font-size: 0.96rem;
    color: var(--emp-text-main);
}

/* ---------- Responsive Breakpoints ---------- */
@media (min-width: 1081px) and (max-width: 1260px) {
    .emp-nav-link { padding: 7px 10px; font-size: 0.84rem; gap: 6px; }
    .emp-nav-links { gap: 2px; }
    .emp-user-profile-link { padding: 4px 10px; font-size: 0.82rem; }
    .emp-btn-logout { padding: 6px 10px; font-size: 0.82rem; }
}

@media (max-width: 1080px) {
    .emp-nav-links { display: none; }
    .emp-user-profile-link { display: none; }
    .emp-nav-hamburger { display: flex; }

    /* Remove logout button from mobile navbar header (accessible via mobile menu drawer) */
    .emp-navbar .emp-btn-logout {
        display: none !important;
    }
}

@media (max-width: 540px) {
    .emp-navbar { padding: 0 14px; height: 62px; }
    .emp-nav-brand-name { font-size: 1rem; }
    .emp-nav-badge { display: none; }
}

@media (max-width: 480px) {
    .emp-navbar { padding: 0 12px; }
    .emp-nav-brand { gap: 8px; }
    .emp-nav-actions { gap: 8px; }
}

/* Ensure mobile drawer logout button is ALWAYS prominent and visible */
.emp-drawer-user .emp-btn-logout {
    display: inline-flex !important;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    font-size: 0.88rem;
    font-weight: 700;
    border-radius: var(--emp-radius-sm);
    background: var(--emp-logout-bg);
    color: var(--emp-logout-color);
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    text-decoration: none;
    flex-shrink: 0;
}
.emp-drawer-user .emp-btn-logout:hover {
    background: var(--emp-logout-hover);
    color: #991b1b;
}

/* Dedicated red logout item inside the mobile drawer links */
.emp-drawer-link-logout {
    color: var(--emp-logout-color) !important;
    font-weight: 700;
    border-top: 1px dashed var(--emp-border);
    margin-top: 6px;
    padding-top: 12px;
}
.emp-drawer-link-logout i {
    color: var(--emp-logout-color) !important;
}
.emp-drawer-link-logout:hover {
    background: var(--emp-logout-bg) !important;
    color: #991b1b !important;
}

/* Remove Dashboard from employee portal navigation menus (desktop & mobile) */
.emp-nav-link[href="employee_dashboard"],
.emp-nav-link[href*="employee_dashboard"],
.emp-drawer-link[href="employee_dashboard"],
.emp-drawer-link[href*="employee_dashboard"] {
    display: none !important;
}
</style>

<!-- ===== NAVBAR HTML ===== -->
<nav class="emp-navbar" role="navigation" aria-label="Employee portal navigation">
    <div class="emp-nav-container">
        <!-- Brand Logo -->
        <a href="employee_dashboard" class="emp-nav-brand" aria-label="<?php echo htmlspecialchars($siteName); ?> Dashboard">
            <?php if ($brandLogo): ?>
                <img src="<?php echo htmlspecialchars($brandLogo); ?>" alt="<?php echo htmlspecialchars($siteName); ?> logo">
            <?php else: ?>
                <div class="emp-nav-brand-icon">
                    <i class="fas fa-cloud-bolt"></i>
                </div>
                <span class="emp-nav-brand-name"><?php echo htmlspecialchars($siteName); ?></span>
            <?php endif; ?>
            <span class="emp-nav-badge">Workspace</span>
        </a>

        <!-- Desktop Navigation Links -->
        <div class="emp-nav-links">
            <?php foreach ($navLinks as $href => $item): ?>
                <a href="<?php echo $href; ?>"
                   class="emp-nav-link <?php echo ($currentPage === $href . '.php' || $currentPage === $href) ? 'active' : ''; ?>">
                    <i class="fas <?php echo $item['icon']; ?>"></i>
                    <span><?php echo htmlspecialchars($item['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Right Side Actions: Theme Toggle, User, Logout -->
        <div class="emp-nav-actions">
            <!-- Theme Toggle Button -->
            <button class="emp-theme-btn" id="empThemeToggle" aria-label="Toggle theme mode" title="Toggle dark/light theme">
                <i class="fas fa-sun icon-sun"></i>
                <i class="fas fa-moon icon-moon"></i>
            </button>

            <!-- User Greeting & Profile Shortcut -->
            <a href="employee_account" class="emp-user-profile-link" title="Manage Account & Settings">
                <div class="emp-user-avatar">
                    <?php 
                        $empName = $_SESSION['employee_name'] ?? 'Employee';
                        $words = explode(' ', trim($empName));
                        $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                        echo htmlspecialchars($initials ?: 'EM');
                    ?>
                </div>
                <span><?php echo htmlspecialchars(explode(' ', trim($empName))[0]); ?></span>
                <?php if (!empty($_SESSION['employee_code'])): ?>
                    <span class="emp-nav-code-badge" title="Employee Code"><?php echo htmlspecialchars($_SESSION['employee_code']); ?></span>
                <?php endif; ?>
            </a>

            <!-- Logout Button (Visible on both Desktop & Mobile) -->
            <a href="employee_logout" class="emp-btn-logout" title="Sign out of portal">
                <i class="fas fa-arrow-right-from-bracket"></i>
                <span>Logout</span>
            </a>

            <!-- Mobile Hamburger Toggle -->
            <button class="emp-nav-hamburger" id="empHamburger" aria-label="Toggle navigation drawer" aria-expanded="false" aria-controls="empNavDrawer">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</nav>

<!-- Mobile Navigation Drawer Backdrop Overlay -->
<div class="emp-nav-backdrop" id="empNavBackdrop" aria-hidden="true"></div>

<!-- Mobile Navigation Drawer -->
<div class="emp-nav-drawer" id="empNavDrawer" role="dialog" aria-label="Mobile navigation drawer" aria-hidden="true">
    <div class="emp-drawer-links">
        <?php foreach ($navLinks as $href => $item): ?>
            <?php if ($href === 'employee_dashboard') continue; // Remove Dashboard from mobile view menu ?>
            <a href="<?php echo $href; ?>"
               class="emp-drawer-link <?php echo ($currentPage === $href . '.php' || $currentPage === $href) ? 'active' : ''; ?>">
                <i class="fas <?php echo $item['icon']; ?>"></i>
                <span><?php echo htmlspecialchars($item['label']); ?></span>
            </a>
        <?php endforeach; ?>

        <!-- Direct Logout Option in Navigation Links -->
        <a href="employee_logout" class="emp-drawer-link emp-drawer-link-logout" title="Sign out of portal">
            <i class="fas fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>

    <hr class="emp-drawer-divider">

    <div class="emp-drawer-user">
        <div class="emp-drawer-greeting">
            Signed in as
            <strong style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                <?php echo htmlspecialchars($_SESSION['employee_name'] ?? 'Employee'); ?>
                <?php if (!empty($_SESSION['employee_code'])): ?>
                    <span class="emp-nav-code-badge"><?php echo htmlspecialchars($_SESSION['employee_code']); ?></span>
                <?php endif; ?>
            </strong>
        </div>
        <a href="employee_logout" class="emp-btn-logout" title="Sign out of portal">
            <i class="fas fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<!-- ===== NAVBAR SCRIPT ===== -->
<script>
(function () {
    const hamburger = document.getElementById('empHamburger');
    const drawer    = document.getElementById('empNavDrawer');
    const backdrop  = document.getElementById('empNavBackdrop');
    const themeBtn  = document.getElementById('empThemeToggle');
    const htmlRoot  = document.documentElement;

    function toggleMenu(force) {
        if (!drawer || !hamburger) return;
        const isOpen = force !== undefined ? force : !drawer.classList.contains('open');
        drawer.classList.toggle('open', isOpen);
        hamburger.classList.toggle('open', isOpen);
        if (backdrop) backdrop.classList.toggle('open', isOpen);
        hamburger.setAttribute('aria-expanded', isOpen);
        drawer.setAttribute('aria-hidden', !isOpen);
    }

    hamburger?.addEventListener('click', () => toggleMenu());
    backdrop?.addEventListener('click', () => toggleMenu(false));

    // Close on click outside
    document.addEventListener('click', (e) => {
        if (hamburger && drawer && !hamburger.contains(e.target) && !drawer.contains(e.target)) {
            toggleMenu(false);
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') toggleMenu(false);
    });

    // Auto-close when resizing back to desktop
    window.addEventListener('resize', () => {
        if (window.innerWidth > 1080) toggleMenu(false);
    });

    // Theme Mode Toggle with Circular Reveal Animation
    themeBtn?.addEventListener('click', (e) => {
        const isDark = htmlRoot.getAttribute('data-theme') === 'dark';
        const nextTheme = isDark ? 'light' : 'dark';

        document.body.classList.add('theme-transitioning');
        setTimeout(() => document.body.classList.remove('theme-transitioning'), 600);

        const applyTheme = () => {
            if (nextTheme === 'dark') {
                htmlRoot.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
            } else {
                htmlRoot.removeAttribute('data-theme');
                localStorage.setItem('theme', 'light');
            }
        };

        if (document.startViewTransition && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            const rect = themeBtn.getBoundingClientRect();
            const x = (e && typeof e.clientX === 'number' && e.clientX > 0) ? e.clientX : (rect.left + rect.width / 2);
            const y = (e && typeof e.clientY === 'number' && e.clientY > 0) ? e.clientY : (rect.top + rect.height / 2);
            const endRadius = Math.hypot(
                Math.max(x, window.innerWidth - x),
                Math.max(y, window.innerHeight - y)
            );

            const transition = document.startViewTransition(() => {
                applyTheme();
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
            applyTheme();
        }
    });
})();
</script>