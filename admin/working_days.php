<?php
// ============================================================================
// Enterprise White-Label SaaS Monthly Working Days & Company Calendar
// ============================================================================

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

Security::init($pdo);
Security::initSecureSession();

if (!Security::isAuthenticated()) {
    header("Location: ../admin");
    exit();
}

$adminId = (int)($_SESSION['admin_id'] ?? 1);
$canView = Security::hasPermission('shifts.view', $adminId) || Security::hasPermission('attendance.view', $adminId) || Security::hasPermission('settings.view', $adminId);
if (!$canView) {
    Security::requirePermission('shifts.view', $adminId);
}
$canManage = Security::hasPermission('shifts.manage', $adminId) || Security::hasPermission('attendance.manage', $adminId) || Security::hasPermission('settings.manage', $adminId);

$pageTitle = 'Monthly Working Days & Calendar';
$currentPage = 'working_days';

// ----------------------------------------------------------------------------
// Request Parameters: Year and Month
// ----------------------------------------------------------------------------
$selectedYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

if ($selectedYear < 2020 || $selectedYear > 2040) {
    $selectedYear = (int)date('Y');
}
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int)date('n');
}

// Compute calendar details
$firstDayTimestamp = mktime(0, 0, 0, $selectedMonth, 1, $selectedYear);
$daysInMonth       = (int)date('t', $firstDayTimestamp);
$firstDayOfWeek    = (int)date('w', $firstDayTimestamp); // 0 (Sun) to 6 (Sat)
$monthName         = date('F', $firstDayTimestamp);
$yearMonthStr      = sprintf('%04d-%02d', $selectedYear, $selectedMonth);

// Previous and Next month links
$prevMonth = $selectedMonth - 1;
$prevYear  = $selectedYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $selectedMonth + 1;
$nextYear  = $selectedYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// ----------------------------------------------------------------------------
// Process POST Actions with CSRF Protection
// ----------------------------------------------------------------------------
$flashMessage = $_SESSION['working_days_flash'] ?? '';
$flashType    = $_SESSION['working_days_flash_type'] ?? '';
unset($_SESSION['working_days_flash'], $_SESSION['working_days_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'working_days')) {
        $_SESSION['working_days_flash'] = 'Invalid or expired security token. Please refresh and try again.';
        $_SESSION['working_days_flash_type'] = 'error';
        header("Location: working_days?year={$selectedYear}&month={$selectedMonth}");
        exit();
    }

    if (!$canManage) {
        $_SESSION['working_days_flash'] = 'Access Denied: You do not possess authorization to update calendar settings.';
        $_SESSION['working_days_flash_type'] = 'error';
        header("Location: working_days?year={$selectedYear}&month={$selectedMonth}");
        exit();
    }

    $action = $_POST['action'];

    // Action 1: Save All Days for the Month
    if ($action === 'save_month') {
        $daysData = $_POST['days'] ?? [];
        if (!is_array($daysData) || empty($daysData)) {
            $_SESSION['working_days_flash'] = 'No calendar data provided.';
            $_SESSION['working_days_flash_type'] = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $stmtUpsert = $pdo->prepare("
                    INSERT INTO monthly_working_days (`date`, `year`, `month`, `day_type`, `title`, `notes`)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        `day_type` = VALUES(`day_type`),
                        `title`    = VALUES(`title`),
                        `notes`    = VALUES(`notes`),
                        `updated_at` = CURRENT_TIMESTAMP
                ");

                $savedCount = 0;
                $allowedTypes = ['working_day', 'weekly_off', 'holiday'];

                foreach ($daysData as $dayNum => $d) {
                    $dayNum = (int)$dayNum;
                    if ($dayNum < 1 || $dayNum > $daysInMonth) continue;

                    $dateStr = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, $dayNum);
                    $type    = in_array($d['type'] ?? '', $allowedTypes, true) ? $d['type'] : 'working_day';
                    $title   = trim($d['title'] ?? '');
                    $notes   = trim($d['notes'] ?? '');

                    $stmtUpsert->execute([
                        $dateStr,
                        $selectedYear,
                        $selectedMonth,
                        $type,
                        $title !== '' ? $title : null,
                        $notes !== '' ? $notes : null
                    ]);
                    $savedCount++;
                }

                $pdo->commit();

                Security::logAudit($adminId, 'update_monthly_working_days', 'monthly_working_days', null, [
                    'year' => $selectedYear,
                    'month' => $selectedMonth,
                    'days_updated' => $savedCount
                ]);

                $_SESSION['working_days_flash'] = "Successfully updated {$savedCount} calendar days for {$monthName} {$selectedYear}.";
                $_SESSION['working_days_flash_type'] = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Error saving monthly working days: " . $e->getMessage());
                $_SESSION['working_days_flash'] = 'Database error occurred while saving calendar settings.';
                $_SESSION['working_days_flash_type'] = 'error';
            }
        }
        header("Location: working_days?year={$selectedYear}&month={$selectedMonth}");
        exit();
    }

    // Action 2: Auto-populate Standard Month (Sundays Off, Weekdays Working)
    elseif ($action === 'auto_populate') {
        $mode = $_POST['auto_mode'] ?? 'sundays_off'; // 'sundays_off' or 'sundays_saturdays_off'
        try {
            $pdo->beginTransaction();

            $stmtUpsert = $pdo->prepare("
                INSERT INTO monthly_working_days (`date`, `year`, `month`, `day_type`, `title`, `notes`)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    `day_type` = VALUES(`day_type`),
                    `title`    = VALUES(`title`),
                    `notes`    = VALUES(`notes`),
                    `updated_at` = CURRENT_TIMESTAMP
            ");

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateStr = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, $day);
                $w = (int)date('w', strtotime($dateStr)); // 0 = Sun, 6 = Sat

                if ($w === 0) {
                    $type = 'weekly_off';
                    $title = 'Sunday Off';
                } elseif ($w === 6 && $mode === 'sundays_saturdays_off') {
                    $type = 'weekly_off';
                    $title = 'Saturday Off';
                } else {
                    $type = 'working_day';
                    $title = null;
                }

                $stmtUpsert->execute([
                    $dateStr,
                    $selectedYear,
                    $selectedMonth,
                    $type,
                    $title,
                    null
                ]);
            }

            $pdo->commit();

            $_SESSION['working_days_flash'] = "Standard working schedule generated for {$monthName} {$selectedYear}.";
            $_SESSION['working_days_flash_type'] = 'success';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Error auto-populating working days: " . $e->getMessage());
            $_SESSION['working_days_flash'] = 'Error generating calendar schedule.';
            $_SESSION['working_days_flash_type'] = 'error';
        }
        header("Location: working_days?year={$selectedYear}&month={$selectedMonth}");
        exit();
    }
}

// ----------------------------------------------------------------------------
// Load Saved Days for the Selected Month
// ----------------------------------------------------------------------------
$savedDays = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM monthly_working_days WHERE `year` = ? AND `month` = ?");
    $stmt->execute([$selectedYear, $selectedMonth]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dayNum = (int)date('j', strtotime($row['date']));
        $savedDays[$dayNum] = $row;
    }
} catch (PDOException $e) {
    error_log("Load monthly working days error: " . $e->getMessage());
}

// ----------------------------------------------------------------------------
// Calculate Totals for the Selected Month
// ----------------------------------------------------------------------------
$totalDays = $daysInMonth;
$workingDaysCount = 0;
$weeklyOffCount   = 0;
$holidaysCount    = 0;

for ($day = 1; $day <= $daysInMonth; $day++) {
    $dateStr = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, $day);
    if (isset($savedDays[$day])) {
        $type = $savedDays[$day]['day_type'];
    } else {
        // Fallback default: Sundays are weekly_off, rest are working_day
        $w = (int)date('w', strtotime($dateStr));
        $type = ($w === 0) ? 'weekly_off' : 'working_day';
    }

    if ($type === 'working_day') {
        $workingDaysCount++;
    } elseif ($type === 'weekly_off') {
        $weeklyOffCount++;
    } elseif ($type === 'holiday') {
        $holidaysCount++;
    }
}

$csrfToken = Security::generateCSRFToken('working_days');
include __DIR__ . '/header.php';
?>

<style>
/* ── Working Days & Calendar Styles ── */
.wd-container {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.wd-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    padding: 24px;
    box-shadow: var(--adm-shadow-card);
}

.wd-hero-title h1 {
    font-family: 'Outfit', sans-serif;
    font-size: 1.65rem;
    font-weight: 700;
    color: var(--adm-text-title);
    margin: 0 0 6px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.wd-hero-title p {
    margin: 0;
    color: var(--adm-text-muted);
    font-size: 0.92rem;
}

.wd-month-picker-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.wd-month-picker-bar .btn-nav {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: var(--adm-radius-md);
    background: var(--adm-surface-subtle);
    border: 1px solid var(--adm-border);
    color: var(--adm-text-title);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.wd-month-picker-bar .btn-nav:hover {
    background: var(--adm-brand-light);
    border-color: var(--adm-brand);
    color: var(--adm-brand);
}

.wd-select-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
}

.wd-select {
    padding: 8px 14px;
    border-radius: var(--adm-radius-md);
    border: 1px solid var(--adm-border);
    background: var(--adm-surface);
    color: var(--adm-text-title);
    font-family: inherit;
    font-size: 0.92rem;
    font-weight: 600;
    cursor: pointer;
    outline: none;
    transition: border-color 0.2s;
}

.wd-select:focus {
    border-color: var(--adm-brand);
}

/* ── KPI Stat Cards ── */
.wd-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
}

.wd-stat-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    padding: 20px;
    box-shadow: var(--adm-shadow-card);
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: transform 0.2s, box-shadow 0.2s;
}

.wd-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
}

.wd-stat-info .wd-stat-label {
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--adm-text-muted);
    display: block;
    margin-bottom: 4px;
}

.wd-stat-info .wd-stat-val {
    font-family: 'Outfit', sans-serif;
    font-size: 1.85rem;
    font-weight: 800;
    color: var(--adm-text-title);
    line-height: 1.1;
}

.wd-stat-info .wd-stat-sub {
    font-size: 0.8rem;
    color: var(--adm-text-muted);
    margin-top: 4px;
    display: block;
}

.wd-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--adm-radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}

.wd-stat-total .wd-stat-icon { background: rgba(79, 70, 229, 0.1); color: #4f46e5; }
.wd-stat-working .wd-stat-icon { background: rgba(16, 185, 129, 0.1); color: #10b981; }
.wd-stat-offs .wd-stat-icon { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
.wd-stat-holidays .wd-stat-icon { background: rgba(236, 72, 153, 0.1); color: #ec4899; }

/* ── Action Toolbar ── */
.wd-toolbar {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    padding: 14px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    box-shadow: var(--adm-shadow-card);
}

.wd-toolbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.btn-auto {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: var(--adm-radius-md);
    background: var(--adm-surface-subtle);
    border: 1px solid var(--adm-border);
    color: var(--adm-text-title);
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-auto:hover {
    background: var(--adm-brand-light);
    border-color: var(--adm-brand);
    color: var(--adm-brand);
}

.btn-save-cal {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 22px;
    border-radius: var(--adm-radius-md);
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    border: none;
    color: #fff;
    font-size: 0.9rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
    transition: all 0.2s;
}

.btn-save-cal:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(79, 70, 229, 0.45);
}

/* ── Calendar Grid ── */
.wd-calendar-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    padding: 24px;
    box-shadow: var(--adm-shadow-card);
}

.wd-weekdays-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 12px;
    margin-bottom: 12px;
    text-align: center;
}

.wd-weekday-label {
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--adm-text-muted);
    padding: 8px 0;
}

.wd-weekday-label.weekend {
    color: #f59e0b;
}

.wd-days-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 12px;
}

.wd-cell-empty {
    min-height: 110px;
    background: var(--adm-surface-subtle);
    border: 1px dashed var(--adm-border);
    border-radius: var(--adm-radius-lg);
    opacity: 0.45;
}

.wd-day-cell {
    min-height: 120px;
    background: var(--adm-surface);
    border: 1.5px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    padding: 12px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.2s;
    position: relative;
}

.wd-day-cell:hover {
    border-color: var(--adm-brand);
    box-shadow: var(--adm-shadow-md);
}

.wd-day-cell.type-working_day {
    border-left: 4px solid #10b981;
    background: rgba(16, 185, 129, 0.02);
}

.wd-day-cell.type-weekly_off {
    border-left: 4px solid #f59e0b;
    background: rgba(245, 158, 11, 0.03);
}

.wd-day-cell.type-holiday {
    border-left: 4px solid #ec4899;
    background: rgba(236, 72, 153, 0.04);
}

.wd-day-cell.is-today {
    box-shadow: 0 0 0 2px var(--adm-brand);
}

.wd-day-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.wd-day-num {
    font-family: 'Outfit', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--adm-text-title);
    line-height: 1;
}

.wd-day-weekday {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--adm-text-muted);
}

.wd-day-type-select {
    width: 100%;
    padding: 6px 8px;
    font-size: 0.8rem;
    font-weight: 600;
    border-radius: var(--adm-radius-sm);
    border: 1px solid var(--adm-border);
    background: var(--adm-surface);
    color: var(--adm-text-title);
    margin-bottom: 6px;
    cursor: pointer;
    font-family: inherit;
}

.wd-day-title-input {
    width: 100%;
    padding: 5px 8px;
    font-size: 0.78rem;
    border-radius: var(--adm-radius-sm);
    border: 1px solid var(--adm-border);
    background: var(--adm-surface-subtle);
    color: var(--adm-text-title);
    box-sizing: border-box;
    font-family: inherit;
}

.wd-day-title-input:focus {
    border-color: var(--adm-brand);
    background: var(--adm-surface);
    outline: none;
}

/* ── Responsive Rules ── */
@media (max-width: 1100px) {
    .wd-stats-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    .wd-weekdays-header { display: none; }
    .wd-days-grid { grid-template-columns: repeat(2, 1fr); }
    .wd-cell-empty { display: none; }
}

@media (max-width: 520px) {
    .wd-stats-grid { grid-template-columns: 1fr; }
    .wd-days-grid { grid-template-columns: 1fr; }
    .wd-hero { padding: 16px; }
}
</style>

<div class="wd-container">

    <!-- ── Hero Banner ── -->
    <div class="wd-hero">
        <div class="wd-hero-title">
            <h1>
                <i class="fa-solid fa-calendar-days" style="color: #4f46e5;"></i>
                <span>Monthly Working Days</span>
            </h1>
            <p>Configure operational business days, weekly offs, and holidays for workforce attendance and payroll</p>
        </div>

        <!-- Month & Year Navigation -->
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <div class="wd-month-picker-bar">
                <a href="?year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth; ?>" class="btn-nav" title="Previous Month">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>

                <form method="get" class="wd-select-wrap">
                    <select name="month" class="wd-select" onchange="this.form.submit()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === $selectedMonth ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1, 2000)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>

                    <select name="year" class="wd-select" onchange="this.form.submit()">
                        <?php for ($y = date('Y') - 2; $y <= date('Y') + 3; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $selectedYear ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </form>

                <a href="?year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>" class="btn-nav" title="Next Month">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
            </div>

            <a href="attendance" class="btn btn-secondary" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px; border-radius: var(--adm-radius-lg); height: 42px; padding: 0 16px;">
                <i class="fa-solid fa-calendar-check"></i>
                <span>Attendance Log</span>
            </a>
        </div>
    </div>

    <!-- ── Flash Notifications ── -->
    <?php if (!empty($flashMessage)): ?>
        <div class="alert alert-<?php echo ($flashType === 'success') ? 'success' : 'danger'; ?>" role="alert">
            <i class="fa-solid <?php echo ($flashType === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <div><?php echo htmlspecialchars($flashMessage); ?></div>
        </div>
    <?php endif; ?>

    <!-- ── KPI Stat Cards ── -->
    <div class="wd-stats-grid">
        <div class="wd-stat-card wd-stat-total">
            <div class="wd-stat-info">
                <span class="wd-stat-label">Total Days</span>
                <span class="wd-stat-val"><?php echo $totalDays; ?></span>
                <span class="wd-stat-sub"><?php echo $monthName . ' ' . $selectedYear; ?></span>
            </div>
            <div class="wd-stat-icon">
                <i class="fa-solid fa-calendar"></i>
            </div>
        </div>

        <div class="wd-stat-card wd-stat-working">
            <div class="wd-stat-info">
                <span class="wd-stat-label">Working Days</span>
                <span class="wd-stat-val"><?php echo $workingDaysCount; ?></span>
                <span class="wd-stat-sub"><?php echo round(($workingDaysCount / $totalDays) * 100); ?>% of month</span>
            </div>
            <div class="wd-stat-icon">
                <i class="fa-solid fa-briefcase"></i>
            </div>
        </div>

        <div class="wd-stat-card wd-stat-offs">
            <div class="wd-stat-info">
                <span class="wd-stat-label">Weekly Offs</span>
                <span class="wd-stat-val"><?php echo $weeklyOffCount; ?></span>
                <span class="wd-stat-sub">Sundays / Weekends</span>
            </div>
            <div class="wd-stat-icon">
                <i class="fa-solid fa-mug-hot"></i>
            </div>
        </div>

        <div class="wd-stat-card wd-stat-holidays">
            <div class="wd-stat-info">
                <span class="wd-stat-label">Official Holidays</span>
                <span class="wd-stat-val"><?php echo $holidaysCount; ?></span>
                <span class="wd-stat-sub">Paid holidays</span>
            </div>
            <div class="wd-stat-icon">
                <i class="fa-solid fa-umbrella-beach"></i>
            </div>
        </div>
    </div>

    <!-- ── Interactive Form Container ── -->
    <form method="post" id="workingDaysForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" value="save_month">

        <!-- ── Toolbar ── -->
        <div class="wd-toolbar">
            <div class="wd-toolbar-left">
                <button type="button" class="btn-auto" onclick="autoPopulateSchedule('sundays_off')" <?php echo !$canManage ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span>Sundays Off (6-Day Week)</span>
                </button>
                <button type="button" class="btn-auto" onclick="autoPopulateSchedule('sundays_saturdays_off')" <?php echo !$canManage ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span>Sat &amp; Sun Off (5-Day Week)</span>
                </button>
            </div>

            <button type="submit" class="btn-save-cal" <?php echo !$canManage ? 'disabled' : ''; ?>>
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Calendar Settings</span>
            </button>
        </div>

        <!-- ── Calendar Grid Card ── -->
        <div class="wd-calendar-card" style="margin-top: 18px;">
            <!-- Weekdays Header (Sun-Sat) -->
            <div class="wd-weekdays-header">
                <div class="wd-weekday-label weekend">Sunday</div>
                <div class="wd-weekday-label">Monday</div>
                <div class="wd-weekday-label">Tuesday</div>
                <div class="wd-weekday-label">Wednesday</div>
                <div class="wd-weekday-label">Thursday</div>
                <div class="wd-weekday-label">Friday</div>
                <div class="wd-weekday-label weekend">Saturday</div>
            </div>

            <!-- Days Grid -->
            <div class="wd-days-grid">
                <!-- Empty Cells for Days Before 1st of Month -->
                <?php for ($empty = 0; $empty < $firstDayOfWeek; $empty++): ?>
                    <div class="wd-cell-empty"></div>
                <?php endfor; ?>

                <!-- Day Cells -->
                <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                    <?php
                    $dateStr   = sprintf('%04d-%02d-%02d', $selectedYear, $selectedMonth, $day);
                    $weekdayNum= (int)date('w', strtotime($dateStr));
                    $weekdayName = date('D', strtotime($dateStr));
                    $isToday   = ($dateStr === date('Y-m-d'));

                    if (isset($savedDays[$day])) {
                        $currentType  = $savedDays[$day]['day_type'];
                        $currentTitle = $savedDays[$day]['title'] ?? '';
                        $currentNotes = $savedDays[$day]['notes'] ?? '';
                    } else {
                        $currentType  = ($weekdayNum === 0) ? 'weekly_off' : 'working_day';
                        $currentTitle = ($weekdayNum === 0) ? 'Sunday Off' : '';
                        $currentNotes = '';
                    }
                    ?>
                    <div class="wd-day-cell type-<?php echo htmlspecialchars($currentType); ?> <?php echo $isToday ? 'is-today' : ''; ?>" id="cell_<?php echo $day; ?>">
                        <div class="wd-day-top">
                            <span class="wd-day-num"><?php echo $day; ?></span>
                            <span class="wd-day-weekday"><?php echo $weekdayName; ?></span>
                        </div>

                        <div>
                            <select 
                                name="days[<?php echo $day; ?>][type]" 
                                class="wd-day-type-select" 
                                onchange="onTypeChange(<?php echo $day; ?>, this.value)"
                                <?php echo !$canManage ? 'disabled' : ''; ?>>
                                <option value="working_day" <?php echo $currentType === 'working_day' ? 'selected' : ''; ?>>💼 Working Day</option>
                                <option value="weekly_off" <?php echo $currentType === 'weekly_off' ? 'selected' : ''; ?>>🏖️ Weekly Off</option>
                                <option value="holiday" <?php echo $currentType === 'holiday' ? 'selected' : ''; ?>>🎉 Holiday</option>
                            </select>

                            <input 
                                type="text" 
                                name="days[<?php echo $day; ?>][title]" 
                                class="wd-day-title-input" 
                                placeholder="Title / Holiday name..." 
                                value="<?php echo htmlspecialchars($currentTitle); ?>"
                                maxlength="100"
                                <?php echo !$canManage ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </form>
</div>

<script>
function onTypeChange(dayNum, newType) {
    const cell = document.getElementById('cell_' + dayNum);
    if (!cell) return;
    cell.classList.remove('type-working_day', 'type-weekly_off', 'type-holiday');
    cell.classList.add('type-' + newType);

    const titleInput = cell.querySelector('.wd-day-title-input');
    if (titleInput && !titleInput.value.trim()) {
        if (newType === 'weekly_off') {
            titleInput.value = 'Weekly Off';
        } else if (newType === 'holiday') {
            titleInput.value = 'Public Holiday';
        }
    }
}

function autoPopulateSchedule(mode) {
    if (!confirm('This will update the days in the form for ' + (mode === 'sundays_off' ? 'Sundays Off (6-day week)' : 'Saturday & Sunday Off (5-day week)') + '. Click "Save Calendar Settings" afterwards to commit changes.')) {
        return;
    }

    const daysInMonth = <?php echo $daysInMonth; ?>;
    const year = <?php echo $selectedYear; ?>;
    const month = <?php echo $selectedMonth; ?>;

    for (let day = 1; day <= daysInMonth; day++) {
        const d = new Date(year, month - 1, day);
        const dayOfWeek = d.getDay(); // 0 = Sun, 6 = Sat
        const cell = document.getElementById('cell_' + day);
        if (!cell) continue;

        const select = cell.querySelector('.wd-day-type-select');
        const titleInput = cell.querySelector('.wd-day-title-input');
        if (!select) continue;

        if (dayOfWeek === 0) {
            select.value = 'weekly_off';
            if (titleInput) titleInput.value = 'Sunday Off';
            onTypeChange(day, 'weekly_off');
        } else if (dayOfWeek === 6 && mode === 'sundays_saturdays_off') {
            select.value = 'weekly_off';
            if (titleInput) titleInput.value = 'Saturday Off';
            onTypeChange(day, 'weekly_off');
        } else {
            select.value = 'working_day';
            if (titleInput) titleInput.value = '';
            onTypeChange(day, 'working_day');
        }
    }
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
