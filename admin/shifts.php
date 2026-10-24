<?php
// ============================================================================
// Enterprise White-Label SaaS Shift Management Console
// ============================================================================

// Set timezone
date_default_timezone_set('Asia/Kolkata');

// Include database connection & security
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/AttendanceHelper.php';

Security::init($pdo);
Security::initSecureSession();

// Check if admin is logged in
if (!Security::isAuthenticated()) {
    header("Location: ../admin");
    exit();
}

$adminId = $_SESSION['admin_id'] ?? null;
Security::requirePermission('shifts.view', $adminId, '../admin/dashboard');

$canManage = Security::hasPermission('shifts.manage', $adminId);

$pageTitle = 'Shift Policies & Timings';
$currentPage = 'shifts';

// ----------------------------------------------------------------------------
// Safe Schema Sync: Ensure optional description & color columns exist
// ----------------------------------------------------------------------------
try {
    $existingCols = $pdo->query("SHOW COLUMNS FROM shift_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('description', $existingCols, true)) {
        $pdo->exec("ALTER TABLE shift_settings ADD COLUMN description VARCHAR(255) DEFAULT NULL AFTER shift_name");
    }
    if (!in_array('color', $existingCols, true)) {
        $pdo->exec("ALTER TABLE shift_settings ADD COLUMN color VARCHAR(20) DEFAULT '#4f46e5' AFTER early_exit_threshold");
    }
} catch (PDOException $e) {
    error_log("Shift schema sync error: " . $e->getMessage());
}

// ----------------------------------------------------------------------------
// CSV Export Action
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    try {
        $stmt = $pdo->query("SELECT * FROM shift_settings ORDER BY is_active DESC, shift_name ASC");
        $exportShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $filename = 'shifts_policy_export_' . date('Y-m-d_His') . '.csv';
        Security::logAudit($adminId, 'export_shifts_csv', 'shift_settings', null, ['count' => count($exportShifts)]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');

        // UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, [
            'ID',
            'Shift Name',
            'Description',
            'Check-In Start',
            'Check-In End',
            'Check-Out Time',
            'Shift Duration (Hrs)',
            'Grace Period (Mins)',
            'Late Threshold (Mins)',
            'Early Exit Threshold (Mins)',
            'Status',
            'Created At',
            'Last Updated'
        ]);

        foreach ($exportShifts as $s) {
            $inTime = strtotime($s['checkin_start']);
            $outTime = strtotime($s['checkout_time']);
            if ($outTime < $inTime) $outTime += 86400;
            $durationHours = round(($outTime - $inTime) / 3600, 2);

            fputcsv($output, [
                $s['id'],
                $s['shift_name'],
                $s['description'] ?? '',
                date('h:i A', strtotime($s['checkin_start'])),
                date('h:i A', strtotime($s['checkin_end'])),
                date('h:i A', strtotime($s['checkout_time'])),
                $durationHours,
                $s['grace_minutes'],
                $s['late_threshold'],
                $s['early_exit_threshold'],
                $s['is_active'] ? 'Active' : 'Standby',
                $s['created_at'],
                $s['updated_at']
            ]);
        }
        fclose($output);
        exit();
    } catch (PDOException $e) {
        error_log("Shift CSV Export error: " . $e->getMessage());
        $_SESSION['shift_flash'] = "Could not generate CSV export.";
        $_SESSION['shift_flash_type'] = "error";
        header("Location: shifts");
        exit();
    }
}

// ----------------------------------------------------------------------------
// Process POST Actions (Save, Set Active, Clone, Delete) using PRG Pattern
// ----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'shifts')) {
        $_SESSION['shift_flash'] = 'Invalid or expired security token. Please try again.';
        $_SESSION['shift_flash_type'] = 'error';
        header("Location: shifts");
        exit();
    }

    if (!$canManage) {
        $_SESSION['shift_flash'] = 'Access Denied: You do not possess authorization to modify shift policies.';
        $_SESSION['shift_flash_type'] = 'error';
        header("Location: shifts");
        exit();
    }

    $action = $_POST['action'];

    // 1. Save or Update Shift
    if ($action === 'save_shift') {
        $id = !empty($_POST['shift_id']) ? (int)$_POST['shift_id'] : null;
        $name = trim($_POST['shift_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $color = trim($_POST['color'] ?? '#4f46e5');
        if (!preg_match('/^#[a-f0-9]{6}$/i', $color)) {
            $color = '#4f46e5';
        }

        $checkinStart = trim($_POST['checkin_start'] ?? '');
        $checkinEnd = trim($_POST['checkin_end'] ?? '');
        $checkoutTime = trim($_POST['checkout_time'] ?? '');
        $graceMins = max(0, (int)($_POST['grace_minutes'] ?? 0));
        $lateThreshold = max(0, (int)($_POST['late_threshold'] ?? 0));
        $earlyExitThreshold = max(0, (int)($_POST['early_exit_threshold'] ?? 0));
        $makeActive = isset($_POST['is_active']) && $_POST['is_active'] === '1';

        // Normalize time strings to HH:MM:SS
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $checkinStart)) $checkinStart .= ':00';
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $checkinEnd)) $checkinEnd .= ':00';
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $checkoutTime)) $checkoutTime .= ':00';

        if (empty($name)) {
            $_SESSION['shift_flash'] = 'Please enter a valid shift name.';
            $_SESSION['shift_flash_type'] = 'error';
        } elseif (empty($checkinStart) || empty($checkinEnd) || empty($checkoutTime)) {
            $_SESSION['shift_flash'] = 'Please configure all required timing fields.';
            $_SESSION['shift_flash_type'] = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                if ($id) {
                    // Update existing
                    $stmt = $pdo->prepare("UPDATE shift_settings SET shift_name = ?, description = ?, color = ?, checkin_start = ?, checkin_end = ?, checkout_time = ?, grace_minutes = ?, late_threshold = ?, early_exit_threshold = ? WHERE id = ?");
                    $stmt->execute([$name, $description, $color, $checkinStart, $checkinEnd, $checkoutTime, $graceMins, $lateThreshold, $earlyExitThreshold, $id]);
                    $targetId = $id;
                    $flashText = "Shift '{$name}' updated successfully.";
                } else {
                    // Check if this is the first shift being created
                    $shiftCount = (int)$pdo->query("SELECT COUNT(*) FROM shift_settings")->fetchColumn();
                    $initActive = ($shiftCount === 0) ? 1 : ($makeActive ? 1 : 0);

                    $stmt = $pdo->prepare("INSERT INTO shift_settings (shift_name, description, color, checkin_start, checkin_end, checkout_time, grace_minutes, late_threshold, early_exit_threshold, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $description, $color, $checkinStart, $checkinEnd, $checkoutTime, $graceMins, $lateThreshold, $earlyExitThreshold, $initActive]);
                    $targetId = (int)$pdo->lastInsertId();
                    $flashText = "New shift '{$name}' created successfully.";
                }

                // If marked active, deactivate others
                if ($makeActive && $targetId) {
                    $pdo->exec("UPDATE shift_settings SET is_active = 0 WHERE id != " . (int)$targetId);
                    $pdo->exec("UPDATE shift_settings SET is_active = 1 WHERE id = " . (int)$targetId);
                }

                $pdo->commit();

                Security::logAudit($adminId, $id ? 'update_shift' : 'create_shift', 'shift_settings', $targetId, [
                    'shift_name' => $name,
                    'is_active' => $makeActive
                ]);

                $_SESSION['shift_flash'] = $flashText;
                $_SESSION['shift_flash_type'] = 'success';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Shift save error: " . $e->getMessage());
                $_SESSION['shift_flash'] = 'Database error occurred while saving shift.';
                $_SESSION['shift_flash_type'] = 'error';
            }
        }
        header("Location: shifts");
        exit();
    }

    // 2. Set Shift as Active
    elseif ($action === 'set_active' && isset($_POST['shift_id'])) {
        $shiftId = (int)$_POST['shift_id'];
        try {
            $pdo->beginTransaction();
            $pdo->exec("UPDATE shift_settings SET is_active = 0");
            $stmt = $pdo->prepare("UPDATE shift_settings SET is_active = 1 WHERE id = ?");
            $stmt->execute([$shiftId]);
            $pdo->commit();

            Security::logAudit($adminId, 'activate_shift', 'shift_settings', $shiftId, ['status' => 'active']);

            $_SESSION['shift_flash'] = "Shift successfully set as active enterprise policy.";
            $_SESSION['shift_flash_type'] = 'success';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Set active shift error: " . $e->getMessage());
            $_SESSION['shift_flash'] = 'Error updating active shift.';
            $_SESSION['shift_flash_type'] = 'error';
        }
        header("Location: shifts");
        exit();
    }

    // 3. Clone / Duplicate Shift
    elseif ($action === 'clone_shift' && isset($_POST['shift_id'])) {
        $shiftId = (int)$_POST['shift_id'];
        try {
            $stmt = $pdo->prepare("SELECT * FROM shift_settings WHERE id = ?");
            $stmt->execute([$shiftId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($source) {
                $newName = $source['shift_name'] . ' (Copy)';
                $desc = $source['description'] ?? '';
                $color = $source['color'] ?? '#3b82f6';
                $insertStmt = $pdo->prepare("INSERT INTO shift_settings (shift_name, description, color, checkin_start, checkin_end, checkout_time, grace_minutes, late_threshold, early_exit_threshold, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
                $insertStmt->execute([
                    $newName,
                    $desc,
                    $color,
                    $source['checkin_start'],
                    $source['checkin_end'],
                    $source['checkout_time'],
                    $source['grace_minutes'],
                    $source['late_threshold'],
                    $source['early_exit_threshold']
                ]);
                $clonedId = (int)$pdo->lastInsertId();

                Security::logAudit($adminId, 'clone_shift', 'shift_settings', $clonedId, ['source_id' => $shiftId, 'name' => $newName]);

                $_SESSION['shift_flash'] = "Shift policy duplicated as '{$newName}'.";
                $_SESSION['shift_flash_type'] = 'success';
            } else {
                $_SESSION['shift_flash'] = "Source shift not found.";
                $_SESSION['shift_flash_type'] = 'error';
            }
        } catch (PDOException $e) {
            error_log("Shift clone error: " . $e->getMessage());
            $_SESSION['shift_flash'] = 'Error duplicating shift.';
            $_SESSION['shift_flash_type'] = 'error';
        }
        header("Location: shifts");
        exit();
    }

    // 4. Delete Shift (With Active Protection)
    elseif ($action === 'delete_shift' && isset($_POST['shift_id'])) {
        $shiftId = (int)$_POST['shift_id'];
        try {
            // Check if this shift is currently active
            $stmt = $pdo->prepare("SELECT shift_name, is_active FROM shift_settings WHERE id = ?");
            $stmt->execute([$shiftId]);
            $shift = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$shift) {
                $_SESSION['shift_flash'] = "Shift record not found.";
                $_SESSION['shift_flash_type'] = 'error';
            } elseif ((int)$shift['is_active'] === 1) {
                $_SESSION['shift_flash'] = "Active shift cannot be deleted! Please set another shift as active first to prevent attendance calculation disruption.";
                $_SESSION['shift_flash_type'] = 'error';
            } else {
                // Safeguard: Check total shift count
                $totalShifts = (int)$pdo->query("SELECT COUNT(*) FROM shift_settings")->fetchColumn();
                if ($totalShifts <= 1) {
                    $_SESSION['shift_flash'] = "Cannot delete the only configured shift in the system.";
                    $_SESSION['shift_flash_type'] = 'error';
                } else {
                    $delStmt = $pdo->prepare("DELETE FROM shift_settings WHERE id = ?");
                    $delStmt->execute([$shiftId]);

                    Security::logAudit($adminId, 'delete_shift', 'shift_settings', $shiftId, ['shift_name' => $shift['shift_name']]);

                    $_SESSION['shift_flash'] = "Shift '{$shift['shift_name']}' deleted successfully.";
                    $_SESSION['shift_flash_type'] = 'success';
                }
            }
        } catch (PDOException $e) {
            error_log("Shift delete error: " . $e->getMessage());
            $_SESSION['shift_flash'] = 'Cannot delete shift because it is linked to historical employee attendance records.';
            $_SESSION['shift_flash_type'] = 'error';
        }
        header("Location: shifts");
        exit();
    }
}

// ----------------------------------------------------------------------------
// Retrieve Flash Messages
// ----------------------------------------------------------------------------
$flashMessage = $_SESSION['shift_flash'] ?? '';
$flashType = $_SESSION['shift_flash_type'] ?? '';
unset($_SESSION['shift_flash'], $_SESSION['shift_flash_type']);

// ----------------------------------------------------------------------------
// Fetch All Shifts & Compute Analytics
// ----------------------------------------------------------------------------
try {
    $stmt = $pdo->query("SELECT * FROM shift_settings ORDER BY is_active DESC, shift_name ASC");
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $shifts = [];
}

// Calculate KPI Metrics
$totalShiftsCount = count($shifts);
$activeShift = null;
$standbyCount = 0;
$totalGrace = 0;

foreach ($shifts as $s) {
    if ($s['is_active']) {
        $activeShift = $s;
    } else {
        $standbyCount++;
    }
    $totalGrace += (int)$s['grace_minutes'];
}
$avgGrace = $totalShiftsCount > 0 ? round($totalGrace / $totalShiftsCount) : 15;

// Active shift duration
$activeDurationText = 'N/A';
if ($activeShift) {
    $in = strtotime($activeShift['checkin_start']);
    $out = strtotime($activeShift['checkout_time']);
    if ($out < $in) $out += 86400; // Overnight shift
    $diffMins = floor(($out - $in) / 60);
    $hrs = floor($diffMins / 60);
    $mins = $diffMins % 60;
    $activeDurationText = ($mins > 0) ? "{$hrs}h {$mins}m" : "{$hrs} hrs";
}

// Helper to calculate duration for any shift
function getShiftDurationFormatted($start, $end) {
    $in = strtotime($start);
    $out = strtotime($end);
    if ($out < $in) $out += 86400;
    $diffMins = floor(($out - $in) / 60);
    $hrs = floor($diffMins / 60);
    $mins = $diffMins % 60;
    return ($mins > 0) ? "{$hrs}h {$mins}m" : "{$hrs} hrs";
}

// Generate fresh CSRF Token
$csrf_token = Security::generateCSRFToken('shifts');

include 'header.php';
?>

<!-- ─── Custom Page Styling (White-Label SaaS Theme Integration) ────────────── -->
<style>
/* SaaS Color Tokens & Variables */
:root {
    --saas-brand-grad: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    --saas-emerald-grad: linear-gradient(135deg, #059669 0%, #10b981 100%);
    --saas-card-bg: var(--adm-surface);
    --saas-border: var(--adm-border);
    --saas-border-subtle: var(--adm-border-subtle);
    --saas-text-main: var(--adm-text-main);
    --saas-text-muted: var(--adm-muted);
}

/* Page Header */
.shifts-header-wrap {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
}
.shifts-breadcrumbs {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--adm-muted);
    margin-bottom: 6px;
}
.shifts-breadcrumbs a {
    color: var(--adm-muted);
    text-decoration: none;
    transition: color 0.2s;
}
.shifts-breadcrumbs a:hover {
    color: var(--adm-brand);
}
.shifts-breadcrumbs .sep {
    font-size: 0.72rem;
    color: var(--adm-faint);
}
.shifts-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.85rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--saas-text-main);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.shifts-subtitle {
    font-size: 0.92rem;
    color: var(--saas-text-muted);
    margin-top: 4px;
}
.shifts-actions-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

/* Modern SaaS Buttons */
.btn-saas {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 9px 18px;
    font-size: 0.88rem;
    font-weight: 700;
    border-radius: var(--adm-radius-md);
    border: 1px solid transparent;
    cursor: pointer;
    text-decoration: none;
    transition: all var(--adm-transition);
    white-space: nowrap;
    box-shadow: var(--adm-shadow-sm);
}
.btn-saas-primary {
    background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
    color: #ffffff;
    border-color: #4f46e5;
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.28);
}
.btn-saas-primary:hover {
    background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(79, 70, 229, 0.38);
    color: #ffffff;
}
.btn-saas-secondary {
    background: var(--adm-surface);
    color: var(--saas-text-main);
    border-color: var(--saas-border);
}
.btn-saas-secondary:hover {
    background: var(--adm-surface-subtle);
    border-color: var(--adm-brand);
    color: var(--adm-brand);
}
.btn-saas-simulator {
    background: var(--adm-brand-light);
    color: var(--adm-brand);
    border-color: var(--adm-brand-border);
}
.btn-saas-simulator:hover {
    background: var(--adm-brand);
    color: #ffffff;
    transform: translateY(-1px);
}

/* KPI Stat Cards Grid */
.shifts-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.kpi-card {
    background: var(--saas-card-bg);
    border: 1px solid var(--saas-border);
    border-radius: var(--adm-radius-xl);
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: var(--adm-shadow-card);
    transition: transform var(--adm-transition), box-shadow var(--adm-transition);
    position: relative;
    overflow: hidden;
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--adm-shadow-hover);
}
.kpi-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: var(--adm-radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}
.kpi-icon-indigo { background: rgba(79, 70, 229, 0.12); color: #4f46e5; }
.kpi-icon-emerald { background: rgba(5, 150, 105, 0.12); color: #059669; }
.kpi-icon-amber { background: rgba(217, 119, 6, 0.12); color: #d97706; }
.kpi-icon-purple { background: rgba(147, 51, 234, 0.12); color: #9333ea; }
.kpi-info {
    min-width: 0;
    flex: 1;
}
.kpi-label {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--saas-text-muted);
    margin-bottom: 4px;
}
.kpi-value {
    font-family: 'Outfit', sans-serif;
    font-size: 1.45rem;
    font-weight: 800;
    color: var(--saas-text-main);
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.kpi-sub {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--saas-text-muted);
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Control Toolbar & View Switcher */
.shifts-toolbar {
    background: var(--saas-card-bg);
    border: 1px solid var(--saas-border);
    border-radius: var(--adm-radius-xl);
    padding: 14px 18px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    box-shadow: var(--adm-shadow-sm);
}
.shifts-search-box {
    position: relative;
    flex: 1;
    min-width: 260px;
    max-width: 420px;
}
.shifts-search-box i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--adm-faint);
    font-size: 0.9rem;
}
.shifts-search-input {
    width: 100%;
    padding: 9px 14px 9px 38px;
    font-size: 0.88rem;
    border-radius: var(--adm-radius-lg);
    border: 1px solid var(--saas-border);
    background: var(--adm-surface-subtle);
    color: var(--saas-text-main);
    font-family: inherit;
    transition: all var(--adm-transition);
}
.shifts-search-input:focus {
    outline: none;
    border-color: var(--adm-brand);
    box-shadow: 0 0 0 3px var(--adm-brand-light);
    background: var(--adm-surface);
}
.shifts-view-tabs {
    display: inline-flex;
    background: var(--adm-surface-subtle);
    padding: 4px;
    border-radius: var(--adm-radius-lg);
    border: 1px solid var(--saas-border-subtle);
    gap: 4px;
}
.view-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: var(--adm-radius-md);
    border: none;
    background: transparent;
    color: var(--saas-text-muted);
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all var(--adm-transition);
}
.view-btn.active {
    background: var(--adm-surface);
    color: var(--adm-brand);
    box-shadow: var(--adm-shadow-sm);
}

/* ─── Modern Card Grid View ────────────────────────────────────────────────── */
.shifts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
}
.shift-card {
    background: var(--saas-card-bg);
    border: 1px solid var(--saas-border);
    border-radius: var(--adm-radius-xl);
    box-shadow: var(--adm-shadow-card);
    transition: all var(--adm-transition);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
}
.shift-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--adm-shadow-hover);
    border-color: var(--adm-brand-border);
}
.shift-card.is-active-card {
    border-color: #059669;
    box-shadow: 0 4px 20px -2px rgba(5, 150, 105, 0.18), 0 0 0 1px #059669;
}
.shift-card-top-stripe {
    height: 4px;
    width: 100%;
}
.shift-card-body {
    padding: 22px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.shift-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}
.shift-badge-name {
    font-family: 'Outfit', sans-serif;
    font-size: 1.22rem;
    font-weight: 800;
    color: var(--saas-text-main);
    line-height: 1.25;
    margin: 0;
}
.shift-desc-snippet {
    font-size: 0.82rem;
    color: var(--saas-text-muted);
    margin-top: 4px;
    line-height: 1.4;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: var(--adm-radius-pill);
    font-size: 0.74rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    flex-shrink: 0;
}
.status-pill-active {
    background: rgba(5, 150, 105, 0.12);
    color: #059669;
    border: 1px solid rgba(5, 150, 105, 0.28);
}
.status-pill-active .pulse-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #059669;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.25);
    animation: pulse 1.8s infinite;
}
.status-pill-standby {
    background: var(--adm-surface-subtle);
    color: var(--adm-faint);
    border: 1px solid var(--saas-border);
}

/* 24h Visual Timeline Bar */
.shift-visual-timeline {
    background: var(--adm-surface-subtle);
    border: 1px solid var(--saas-border-subtle);
    border-radius: var(--adm-radius-lg);
    padding: 12px;
    margin: 14px 0;
}
.timeline-header {
    display: flex;
    justify-content: space-between;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--saas-text-muted);
    margin-bottom: 8px;
}
.timeline-track {
    height: 10px;
    background: var(--adm-border-subtle);
    border-radius: var(--adm-radius-pill);
    position: relative;
    overflow: hidden;
    display: flex;
}
.timeline-segment-checkin {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #60a5fa);
    border-radius: var(--adm-radius-pill) 0 0 var(--adm-radius-pill);
}
.timeline-segment-work {
    height: 100%;
    background: linear-gradient(90deg, #4f46e5, #818cf8);
    flex: 1;
}
.timeline-segment-grace {
    height: 100%;
    background: #f59e0b;
}
.timeline-segment-out {
    height: 100%;
    background: #10b981;
    border-radius: 0 var(--adm-radius-pill) var(--adm-radius-pill) 0;
}
.timeline-labels {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--saas-text-main);
    margin-top: 6px;
}

/* Policy Metrics 3-box Grid */
.shift-metrics-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-top: auto;
    padding-top: 14px;
}
.policy-box {
    background: var(--adm-surface-subtle);
    border: 1px solid var(--saas-border-subtle);
    border-radius: var(--adm-radius-md);
    padding: 10px 8px;
    text-align: center;
}
.policy-box-label {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--saas-text-muted);
    margin-bottom: 4px;
}
.policy-box-val {
    font-size: 0.88rem;
    font-weight: 800;
    color: var(--saas-text-main);
}
.policy-box-val.accent-grace { color: #d97706; }
.policy-box-val.accent-late { color: #dc2626; }

/* Shift Card Footer Actions */
.shift-card-footer {
    padding: 14px 22px;
    background: var(--adm-surface-subtle);
    border-top: 1px solid var(--saas-border-subtle);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.card-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    font-size: 0.82rem;
    font-weight: 700;
    border-radius: var(--adm-radius-md);
    border: 1px solid var(--saas-border);
    background: var(--adm-surface);
    color: var(--saas-text-main);
    cursor: pointer;
    transition: all var(--adm-transition);
    text-decoration: none;
}
.card-action-btn:hover {
    border-color: var(--adm-brand);
    color: var(--adm-brand);
    background: var(--adm-brand-light);
}
.card-action-btn.btn-del:hover {
    border-color: var(--adm-danger);
    color: var(--adm-danger);
    background: var(--adm-danger-light);
}
.card-action-btn.btn-active-set {
    background: #ecfdf5;
    border-color: rgba(5, 150, 105, 0.3);
    color: #059669;
}
.card-action-btn.btn-active-set:hover {
    background: #059669;
    color: #ffffff;
}

/* ─── Modern SaaS Data Table ───────────────────────────────────────────────── */
.shifts-table-wrap {
    background: var(--saas-card-bg);
    border: 1px solid var(--saas-border);
    border-radius: var(--adm-radius-xl);
    overflow: hidden;
    box-shadow: var(--adm-shadow-card);
    display: none; /* Controlled by toggle */
}
.shifts-table-wrap.active {
    display: block;
}
.saas-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}
.saas-table th {
    background: var(--adm-surface-subtle);
    padding: 14px 18px;
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--saas-text-muted);
    border-bottom: 1px solid var(--saas-border);
}
.saas-table td {
    padding: 16px 18px;
    border-bottom: 1px solid var(--saas-border-subtle);
    font-size: 0.88rem;
    vertical-align: middle;
}
.saas-table tr:hover td {
    background: var(--adm-surface-subtle);
}
.saas-table tr.row-active td {
    background: rgba(5, 150, 105, 0.03);
}

/* ─── Glassmorphism Modal ──────────────────────────────────────────────────── */
.saas-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.saas-modal.active {
    display: flex;
}
.saas-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    animation: fadeIn 0.2s ease-out;
}
.saas-modal-dialog {
    position: relative;
    background: var(--saas-card-bg);
    border: 1px solid var(--saas-border);
    border-radius: var(--adm-radius-xl);
    width: 100%;
    max-width: 640px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--adm-shadow-hover), 0 25px 50px -12px rgba(0, 0, 0, 0.35);
    animation: scaleIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    z-index: 1;
}
.saas-modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--saas-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--adm-surface-subtle);
}
.saas-modal-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.35rem;
    font-weight: 800;
    margin: 0;
    color: var(--saas-text-main);
    display: flex;
    align-items: center;
    gap: 10px;
}
.saas-modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid var(--saas-border);
    background: var(--saas-card-bg);
    color: var(--saas-text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.9rem;
}
.saas-modal-close:hover {
    background: var(--adm-danger-light);
    color: var(--adm-danger);
    border-color: var(--adm-danger-border);
}
.saas-modal-body {
    padding: 24px;
}
.form-section-title {
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--adm-brand);
    margin: 18px 0 10px 0;
    display: flex;
    align-items: center;
    gap: 6px;
}
.form-section-title:first-child {
    margin-top: 0;
}
.form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.form-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.modal-field {
    margin-bottom: 14px;
}
.modal-field label {
    display: block;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--saas-text-main);
    margin-bottom: 6px;
}
.modal-field .input-text,
.modal-field .input-time,
.modal-field .input-num {
    width: 100%;
    padding: 9px 12px;
    border-radius: var(--adm-radius-md);
    border: 1px solid var(--saas-border);
    background: var(--adm-surface-subtle);
    color: var(--saas-text-main);
    font-family: inherit;
    font-size: 0.88rem;
    transition: all var(--adm-transition);
}
.modal-field .input-text:focus,
.modal-field .input-time:focus,
.modal-field .input-num:focus {
    outline: none;
    border-color: var(--adm-brand);
    background: var(--adm-surface);
    box-shadow: 0 0 0 3px var(--adm-brand-light);
}
.color-picker-row {
    display: flex;
    align-items: center;
    gap: 10px;
}
.color-swatch-btn {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 2px solid transparent;
    cursor: pointer;
    transition: transform 0.2s, border-color 0.2s;
}
.color-swatch-btn:hover {
    transform: scale(1.15);
}
.color-swatch-btn.active {
    border-color: var(--saas-text-main);
    transform: scale(1.15);
    box-shadow: 0 0 0 2px var(--adm-surface);
}

/* Live Shift Duration Preview inside Modal */
.modal-duration-preview {
    background: var(--adm-brand-light);
    border: 1px solid var(--adm-brand-border);
    border-radius: var(--adm-radius-lg);
    padding: 12px 16px;
    margin: 16px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-duration-title {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--adm-brand);
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-duration-badge {
    font-family: 'Outfit', sans-serif;
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--adm-brand);
}

.saas-modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--saas-border);
    background: var(--adm-surface-subtle);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
}

/* ─── Interactive Punch Simulator Modal ─────────────────────────────────────── */
.simulator-box {
    background: var(--adm-surface-subtle);
    border: 1px solid var(--saas-border);
    border-radius: var(--adm-radius-xl);
    padding: 20px;
    margin-bottom: 20px;
}
.sim-result-card {
    border-radius: var(--adm-radius-lg);
    padding: 18px;
    margin-top: 16px;
    border: 1px solid var(--saas-border);
    background: var(--adm-surface);
}
.sim-badge-large {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: var(--adm-radius-pill);
    font-weight: 800;
    font-size: 0.95rem;
    text-transform: uppercase;
}

/* Responsive adjustments */
@media (max-width: 1024px) {
    .shifts-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .shifts-kpi-grid { grid-template-columns: 1fr; }
    .shifts-grid { grid-template-columns: 1fr; }
    .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; }
    .shifts-header-wrap { flex-direction: column; align-items: stretch; }
    .shifts-actions-group { width: 100%; }
    .shifts-actions-group .btn-saas { flex: 1; }
}
</style>

<!-- ─── Page Breadcrumbs & Header ────────────────────────────────────────────── -->
<div class="shifts-header-wrap">
    <div>
        <nav class="shifts-breadcrumbs" aria-label="Breadcrumb">
            <a href="/admin/dashboard"><i class="fa-solid fa-house"></i> Admin</a>
            <span class="sep"><i class="fa-solid fa-chevron-right"></i></span>
            <a href="settings">Organization</a>
            <span class="sep"><i class="fa-solid fa-chevron-right"></i></span>
            <span>Shift Policies</span>
        </nav>
        <h1 class="shifts-title">
            <i class="fa-solid fa-business-time" style="color: var(--adm-brand);"></i>
            <span>Workforce Shift Policies</span>
        </h1>
        <p class="shifts-subtitle">
            Configure working hours, tolerance windows, grace periods, and late thresholds for <?php echo htmlspecialchars($adminBrandName); ?>.
        </p>
    </div>

    <div class="shifts-actions-group">
        <!-- Interactive Punch Simulator Button -->
        <button type="button" class="btn-saas btn-saas-simulator" onclick="openSimulatorModal()" title="Simulate employee punches and test shift rules">
            <i class="fa-solid fa-calculator"></i>
            <span>Punch Simulator</span>
        </button>

        <!-- CSV Export Button -->
        <a href="shifts?action=export_csv" class="btn-saas btn-saas-secondary" title="Export shift policies to CSV">
            <i class="fa-solid fa-file-arrow-down"></i>
            <span>Export CSV</span>
        </a>

        <!-- Add Shift Button -->
        <button type="button" class="btn-saas btn-saas-primary" onclick="openCreateShiftModal()">
            <i class="fa-solid fa-plus"></i>
            <span>Create Shift</span>
        </button>
    </div>
</div>

<!-- ─── Flash Feedback Notification ─────────────────────────────────────────── -->
<?php if (!empty($flashMessage)): ?>
    <div class="alert alert-<?php echo ($flashType === 'success') ? 'success' : 'error'; ?>" role="alert">
        <i class="fa-solid <?php echo ($flashType === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
        <span><?php echo htmlspecialchars($flashMessage); ?></span>
    </div>
<?php endif; ?>

<!-- ─── Enterprise KPI Stat Cards ────────────────────────────────────────────── -->
<div class="shifts-kpi-grid">
    <!-- Card 1: Total Configured Shifts -->
    <div class="kpi-card">
        <div class="kpi-icon-wrap kpi-icon-indigo">
            <i class="fa-solid fa-business-time"></i>
        </div>
        <div class="kpi-info">
            <div class="kpi-label">Configured Shifts</div>
            <div class="kpi-value"><?php echo $totalShiftsCount; ?></div>
            <div class="kpi-sub">
                <span style="color: #059669;"><i class="fa-solid fa-circle-check"></i> 1 Active</span>
                <span>&bull;</span>
                <span><?php echo $standbyCount; ?> Standby</span>
            </div>
        </div>
    </div>

    <!-- Card 2: Active Enterprise Shift -->
    <div class="kpi-card">
        <div class="kpi-icon-wrap kpi-icon-emerald">
            <i class="fa-solid fa-clock"></i>
        </div>
        <div class="kpi-info">
            <div class="kpi-label">Active Shift Policy</div>
            <div class="kpi-value" title="<?php echo htmlspecialchars($activeShift['shift_name'] ?? 'None'); ?>">
                <?php echo htmlspecialchars($activeShift['shift_name'] ?? 'None'); ?>
            </div>
            <div class="kpi-sub">
                <?php if ($activeShift): ?>
                    <span><?php echo date('h:i A', strtotime($activeShift['checkin_start'])); ?> &ndash; <?php echo date('h:i A', strtotime($activeShift['checkout_time'])); ?></span>
                <?php else: ?>
                    <span style="color: var(--adm-danger);">No shift active</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Card 3: Standard Shift Duration -->
    <div class="kpi-card">
        <div class="kpi-icon-purple kpi-icon-wrap">
            <i class="fa-solid fa-hourglass-half"></i>
        </div>
        <div class="kpi-info">
            <div class="kpi-label">Shift Duration</div>
            <div class="kpi-value"><?php echo $activeDurationText; ?></div>
            <div class="kpi-sub">
                <?php if ($activeShift): ?>
                    <span>Check-in: <?php echo date('h:i A', strtotime($activeShift['checkin_start'])); ?> to <?php echo date('h:i A', strtotime($activeShift['checkin_end'])); ?></span>
                <?php else: ?>
                    <span>Standard Workday</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Card 4: Grace & Penalty Tolerance -->
    <div class="kpi-card">
        <div class="kpi-icon-wrap kpi-icon-amber">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <div class="kpi-info">
            <div class="kpi-label">Grace &amp; Tolerances</div>
            <div class="kpi-value"><?php echo ($activeShift['grace_minutes'] ?? $avgGrace); ?>m Grace</div>
            <div class="kpi-sub">
                <span>Late mark after <?php echo ($activeShift['late_threshold'] ?? 30); ?>m</span>
            </div>
        </div>
    </div>
</div>

<!-- ─── Control Toolbar & Search Filter ──────────────────────────────────────── -->
<div class="shifts-toolbar">
    <!-- Live Shift Search Input -->
    <div class="shifts-search-box">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="shiftSearchInput" class="shifts-search-input" placeholder="Filter shifts by name, timing, or rules..." onkeyup="filterShifts(this.value)">
    </div>

    <!-- Grid vs Table View Switcher -->
    <div class="shifts-view-tabs" role="tablist">
        <button type="button" class="view-btn active" id="btnViewGrid" onclick="switchView('grid')" title="Switch to Visual Grid View">
            <i class="fa-solid fa-grip"></i>
            <span>Card Grid</span>
        </button>
        <button type="button" class="view-btn" id="btnViewTable" onclick="switchView('table')" title="Switch to Data Table View">
            <i class="fa-solid fa-table-list"></i>
            <span>Data Table</span>
        </button>
    </div>
</div>

<!-- ─── Visual Card Grid View ────────────────────────────────────────────────── -->
<div class="shifts-grid" id="shiftsGridView">
    <?php if (empty($shifts)): ?>
        <div style="grid-column: 1/-1; background: var(--saas-card-bg); border: 1px dashed var(--saas-border); border-radius: var(--adm-radius-xl); padding: 50px 20px; text-align: center;">
            <div style="font-size: 2.5rem; color: var(--adm-faint); margin-bottom: 12px;"><i class="fa-solid fa-business-time"></i></div>
            <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--saas-text-main); margin-bottom: 6px;">No Shifts Configured</h3>
            <p style="font-size: 0.88rem; color: var(--saas-text-muted); max-width: 420px; margin: 0 auto 16px;">
                Define your organization's first work shift with check-in windows, grace periods, and late thresholds.
            </p>
            <button type="button" class="btn-saas btn-saas-primary" onclick="openCreateShiftModal()">
                <i class="fa-solid fa-plus"></i> Create First Shift
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($shifts as $shift): ?>
            <?php
            $isAct = (bool)$shift['is_active'];
            $cardColor = !empty($shift['color']) ? $shift['color'] : '#4f46e5';
            $durationStr = getShiftDurationFormatted($shift['checkin_start'], $shift['checkout_time']);
            $shiftJson = json_encode($shift, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            ?>
            <div class="shift-card <?php echo $isAct ? 'is-active-card' : ''; ?>" 
                 data-shift-id="<?php echo $shift['id']; ?>"
                 data-shift-name="<?php echo htmlspecialchars(strtolower($shift['shift_name'])); ?>">
                
                <!-- Top Color Stripe -->
                <div class="shift-card-top-stripe" style="background: <?php echo $cardColor; ?>;"></div>

                <div class="shift-card-body">
                    <div class="shift-card-header">
                        <div>
                            <h3 class="shift-badge-name"><?php echo htmlspecialchars($shift['shift_name']); ?></h3>
                            <?php if (!empty($shift['description'])): ?>
                                <p class="shift-desc-snippet"><?php echo htmlspecialchars($shift['description']); ?></p>
                            <?php else: ?>
                                <p class="shift-desc-snippet" style="font-style: italic; opacity: 0.7;">General operational schedule</p>
                            <?php endif; ?>
                        </div>

                        <?php if ($isAct): ?>
                            <span class="status-pill status-pill-active" title="Currently enforcing this shift for workforce punches">
                                <span class="pulse-dot"></span>
                                <span>Active</span>
                            </span>
                        <?php else: ?>
                            <span class="status-pill status-pill-standby">Standby</span>
                        <?php endif; ?>
                    </div>

                    <!-- 24h Visual Timeline Preview -->
                    <div class="shift-visual-timeline">
                        <div class="timeline-header">
                            <span><i class="fa-solid fa-hourglass-start"></i> Shift Span: <?php echo $durationStr; ?></span>
                            <span>Grace: <?php echo $shift['grace_minutes']; ?>m</span>
                        </div>
                        <div class="timeline-track">
                            <div class="timeline-segment-checkin" style="width: 20%;" title="Check-in window: <?php echo date('h:i A', strtotime($shift['checkin_start'])); ?> to <?php echo date('h:i A', strtotime($shift['checkin_end'])); ?>"></div>
                            <div class="timeline-segment-work" title="Core Working Hours"></div>
                            <div class="timeline-segment-out" style="width: 15%;" title="Checkout time: <?php echo date('h:i A', strtotime($shift['checkout_time'])); ?>"></div>
                        </div>
                        <div class="timeline-labels">
                            <span>In: <?php echo date('h:i A', strtotime($shift['checkin_start'])); ?></span>
                            <span>Out: <?php echo date('h:i A', strtotime($shift['checkout_time'])); ?></span>
                        </div>
                    </div>

                    <!-- 3 Policy Metric Boxes -->
                    <div class="shift-metrics-row">
                        <div class="policy-box">
                            <div class="policy-box-label">Grace Window</div>
                            <div class="policy-box-val accent-grace"><?php echo $shift['grace_minutes']; ?> mins</div>
                        </div>
                        <div class="policy-box">
                            <div class="policy-box-label">Late Penalty</div>
                            <div class="policy-box-val accent-late">&gt; <?php echo $shift['late_threshold']; ?> mins</div>
                        </div>
                        <div class="policy-box">
                            <div class="policy-box-label">Early Exit</div>
                            <div class="policy-box-val">&lt; <?php echo $shift['early_exit_threshold']; ?> mins</div>
                        </div>
                    </div>
                </div>

                <!-- Shift Card Footer Actions -->
                <div class="shift-card-footer">
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <?php if (!$isAct): ?>
                            <form method="POST" action="" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="set_active">
                                <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                                <button type="submit" class="card-action-btn btn-active-set" title="Activate this shift for all employee punches">
                                    <i class="fa-solid fa-circle-check"></i> Set Active
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="font-size: 0.78rem; font-weight: 700; color: #059669; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="fa-solid fa-shield"></i> Default Rule
                            </span>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; gap: 6px; align-items: center;">
                        <!-- Edit Button (Safe JSON Data-Attribute) -->
                        <button type="button" class="card-action-btn" data-shift='<?php echo htmlspecialchars($shiftJson, ENT_QUOTES, 'UTF-8'); ?>' onclick="handleEditShift(this)" title="Edit this shift policy">
                            <i class="fa-solid fa-pen-to-square"></i> Edit
                        </button>

                        <!-- Clone Shift Button -->
                        <form method="POST" action="" style="margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="clone_shift">
                            <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                            <button type="submit" class="card-action-btn" title="Duplicate this shift settings">
                                <i class="fa-solid fa-copy"></i>
                            </button>
                        </form>

                        <!-- Delete Shift Button -->
                        <?php if (!$isAct): ?>
                            <form method="POST" action="" style="margin: 0;" onsubmit="return confirm('Permanently delete the shift policy \'<?php echo addslashes($shift['shift_name']); ?>\'?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="delete_shift">
                                <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                                <button type="submit" class="card-action-btn btn-del" title="Delete shift policy">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="card-action-btn" disabled style="opacity: 0.4; cursor: not-allowed;" title="Active shift cannot be deleted. Activate another shift first.">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ─── High-Density Data Table View ─────────────────────────────────────────── -->
<div class="shifts-table-wrap" id="shiftsTableView">
    <table class="saas-table">
        <thead>
            <tr>
                <th>Shift Details</th>
                <th>Working Window</th>
                <th>Shift Duration</th>
                <th>Grace &amp; Tolerances</th>
                <th>Status</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($shifts)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px; color: var(--saas-text-muted);">
                        No shifts configured.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($shifts as $shift): ?>
                    <?php
                    $isAct = (bool)$shift['is_active'];
                    $durationStr = getShiftDurationFormatted($shift['checkin_start'], $shift['checkout_time']);
                    $shiftJson = json_encode($shift, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    ?>
                    <tr class="<?php echo $isAct ? 'row-active' : ''; ?>"
                        data-shift-id="<?php echo $shift['id']; ?>"
                        data-shift-name="<?php echo htmlspecialchars(strtolower($shift['shift_name'])); ?>">
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="width: 10px; height: 10px; border-radius: 50%; background: <?php echo !empty($shift['color']) ? $shift['color'] : '#4f46e5'; ?>; flex-shrink: 0;"></span>
                                <div>
                                    <strong style="color: var(--saas-text-main);"><?php echo htmlspecialchars($shift['shift_name']); ?></strong>
                                    <?php if (!empty($shift['description'])): ?>
                                        <div style="font-size: 0.78rem; color: var(--saas-text-muted);"><?php echo htmlspecialchars($shift['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-size: 0.85rem; font-weight: 600;">
                                Check-in: <?php echo date('h:i A', strtotime($shift['checkin_start'])); ?> &ndash; <?php echo date('h:i A', strtotime($shift['checkin_end'])); ?>
                            </div>
                            <div style="font-size: 0.78rem; color: var(--saas-text-muted);">
                                Check-out: <?php echo date('h:i A', strtotime($shift['checkout_time'])); ?>
                            </div>
                        </td>
                        <td>
                            <span class="status-pill status-pill-standby" style="font-size: 0.82rem; font-weight: 700;">
                                <i class="fa-solid fa-hourglass-half"></i> <?php echo $durationStr; ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 0.82rem;">
                                Grace: <strong><?php echo $shift['grace_minutes']; ?>m</strong> |
                                Late: <strong><?php echo $shift['late_threshold']; ?>m</strong> |
                                Early: <strong><?php echo $shift['early_exit_threshold']; ?>m</strong>
                            </div>
                        </td>
                        <td>
                            <?php if ($isAct): ?>
                                <span class="status-pill status-pill-active">
                                    <span class="pulse-dot"></span> Active
                                </span>
                            <?php else: ?>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="set_active">
                                    <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                                    <button type="submit" class="card-action-btn btn-active-set" style="padding: 4px 8px; font-size: 0.75rem;">Set Active</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px; align-items: center;">
                                <button type="button" class="card-action-btn" data-shift='<?php echo htmlspecialchars($shiftJson, ENT_QUOTES, 'UTF-8'); ?>' onclick="handleEditShift(this)">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="clone_shift">
                                    <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                                    <button type="submit" class="card-action-btn" title="Clone Shift">
                                        <i class="fa-solid fa-copy"></i>
                                    </button>
                                </form>
                                <?php if (!$isAct): ?>
                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Delete shift?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="delete_shift">
                                        <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
                                        <button type="submit" class="card-action-btn btn-del" title="Delete">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ─── Create / Edit Shift Modal ────────────────────────────────────────────── -->
<div class="saas-modal" id="shiftModal" role="dialog" aria-modal="true" aria-labelledby="shiftModalTitle">
    <div class="saas-modal-backdrop" onclick="closeModal('shiftModal')"></div>
    <div class="saas-modal-dialog">
        <div class="saas-modal-header">
            <h2 class="saas-modal-title" id="shiftModalTitle">
                <i class="fa-solid fa-business-time" style="color: var(--adm-brand);"></i>
                <span id="shiftModalHeadingText">Create Shift Policy</span>
            </h2>
            <button type="button" class="saas-modal-close" onclick="closeModal('shiftModal')" aria-label="Close dialog">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" id="shiftForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="save_shift">
            <input type="hidden" name="shift_id" id="shift_id" value="">

            <div class="saas-modal-body">
                <!-- Section 1: General Info -->
                <div class="form-section-title">
                    <i class="fa-solid fa-tag"></i> Shift Identity &amp; Accent
                </div>

                <div class="form-grid-2">
                    <div class="modal-field">
                        <label for="shift_name">Shift Policy Name *</label>
                        <input type="text" name="shift_name" id="shift_name" class="input-text" required placeholder="e.g. Regular Morning Shift" maxlength="100">
                    </div>
                    <div class="modal-field">
                        <label for="shift_color">Theme Color</label>
                        <div class="color-picker-row">
                            <input type="color" name="color" id="shift_color" value="#4f46e5" style="width: 42px; height: 38px; padding: 2px; border-radius: var(--adm-radius-md); border: 1px solid var(--saas-border); cursor: pointer;" onchange="syncColorSwatch(this.value)">
                            <div style="display: flex; gap: 6px;" id="swatchList">
                                <button type="button" class="color-swatch-btn" style="background: #4f46e5;" onclick="setColor('#4f46e5')"></button>
                                <button type="button" class="color-swatch-btn" style="background: #059669;" onclick="setColor('#059669')"></button>
                                <button type="button" class="color-swatch-btn" style="background: #d97706;" onclick="setColor('#d97706')"></button>
                                <button type="button" class="color-swatch-btn" style="background: #e11d48;" onclick="setColor('#e11d48')"></button>
                                <button type="button" class="color-swatch-btn" style="background: #0284c7;" onclick="setColor('#0284c7')"></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-field">
                    <label for="shift_desc">Description / Department Scope (Optional)</label>
                    <input type="text" name="description" id="shift_desc" class="input-text" placeholder="e.g. Applicable to Corporate, Tech, and Operations teams" maxlength="255">
                </div>

                <!-- Section 2: Working Hours Schedule -->
                <div class="form-section-title">
                    <i class="fa-solid fa-clock"></i> Timing Schedule
                </div>

                <div class="form-grid-3">
                    <div class="modal-field">
                        <label for="checkin_start">Check-In Start *</label>
                        <input type="time" name="checkin_start" id="checkin_start" class="input-time" required onchange="calculateLiveShiftDuration()">
                    </div>
                    <div class="modal-field">
                        <label for="checkin_end">Check-In End *</label>
                        <input type="time" name="checkin_end" id="checkin_end" class="input-time" required>
                    </div>
                    <div class="modal-field">
                        <label for="checkout_time">Official Check-Out *</label>
                        <input type="time" name="checkout_time" id="checkout_time" class="input-time" required onchange="calculateLiveShiftDuration()">
                    </div>
                </div>

                <!-- Live Shift Working Duration Calculator -->
                <div class="modal-duration-preview">
                    <div class="modal-duration-title">
                        <i class="fa-solid fa-hourglass-half"></i>
                        <span>Computed Net Shift Span:</span>
                    </div>
                    <div class="modal-duration-badge" id="modalLiveDuration">--</div>
                </div>

                <!-- Section 3: Compliance & Tolerances -->
                <div class="form-section-title">
                    <i class="fa-solid fa-shield-halved"></i> Tolerances &amp; Late Penalties
                </div>

                <div class="form-grid-3">
                    <div class="modal-field">
                        <label for="grace_minutes">Grace Period (Mins)</label>
                        <input type="number" name="grace_minutes" id="grace_minutes" class="input-num" min="0" max="180" value="15" required>
                        <small style="display: block; font-size: 0.72rem; color: var(--saas-text-muted); margin-top: 4px;">No penalty if checked in before grace window</small>
                    </div>
                    <div class="modal-field">
                        <label for="late_threshold">Late Mark After (Mins)</label>
                        <input type="number" name="late_threshold" id="late_threshold" class="input-num" min="0" max="240" value="30" required>
                        <small style="display: block; font-size: 0.72rem; color: var(--saas-text-muted); margin-top: 4px;">Marked Delayed if check-in exceeds threshold</small>
                    </div>
                    <div class="modal-field">
                        <label for="early_exit_threshold">Early Exit Before (Mins)</label>
                        <input type="number" name="early_exit_threshold" id="early_exit_threshold" class="input-num" min="0" max="240" value="30" required>
                        <small style="display: block; font-size: 0.72rem; color: var(--saas-text-muted); margin-top: 4px;">Marked Left Early if checked out prematurely</small>
                    </div>
                </div>

                <!-- Make Active Immediately Checkbox -->
                <div style="margin-top: 10px; padding: 12px; background: var(--adm-surface-subtle); border-radius: var(--adm-radius-md); border: 1px solid var(--saas-border-subtle); display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" name="is_active" id="shift_is_active" value="1" style="width: 18px; height: 18px; accent-color: var(--adm-brand); cursor: pointer;">
                    <label for="shift_is_active" style="font-size: 0.84rem; font-weight: 700; color: var(--saas-text-main); cursor: pointer; margin: 0;">
                        Set as Active Enterprise Policy immediately upon saving
                    </label>
                </div>
            </div>

            <div class="saas-modal-footer">
                <button type="button" class="btn-saas btn-saas-secondary" onclick="closeModal('shiftModal')">Cancel</button>
                <button type="submit" class="btn-saas btn-saas-primary" id="shiftModalSubmitBtn">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span id="shiftModalSubmitBtnText">Save Shift Policy</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Interactive Punch Simulator Drawer / Modal ────────────────────────────── -->
<div class="saas-modal" id="simulatorModal" role="dialog" aria-modal="true" aria-labelledby="simModalTitle">
    <div class="saas-modal-backdrop" onclick="closeModal('simulatorModal')"></div>
    <div class="saas-modal-dialog">
        <div class="saas-modal-header">
            <h2 class="saas-modal-title" id="simModalTitle">
                <i class="fa-solid fa-calculator" style="color: var(--adm-brand);"></i>
                <span>Shift Attendance Rule Simulator</span>
            </h2>
            <button type="button" class="saas-modal-close" onclick="closeModal('simulatorModal')" aria-label="Close dialog">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="saas-modal-body">
            <p style="font-size: 0.86rem; color: var(--saas-text-muted); margin-bottom: 16px;">
                Test how attendance statuses (Present, Delayed, Left Early, Half Day) will be automatically computed based on simulated punch-in and punch-out events.
            </p>

            <div class="simulator-box">
                <div class="modal-field">
                    <label for="sim_shift_select">Select Shift to Simulate</label>
                    <select id="sim_shift_select" class="input-text" onchange="runSimulation()">
                        <?php foreach ($shifts as $s): ?>
                            <option value="<?php echo $s['id']; ?>" 
                                    data-in="<?php echo $s['checkin_start']; ?>"
                                    data-out="<?php echo $s['checkout_time']; ?>"
                                    data-grace="<?php echo $s['grace_minutes']; ?>"
                                    data-late="<?php echo $s['late_threshold']; ?>"
                                    data-early="<?php echo $s['early_exit_threshold']; ?>"
                                    <?php echo $s['is_active'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['shift_name']); ?> (In: <?php echo date('h:i A', strtotime($s['checkin_start'])); ?> - Out: <?php echo date('h:i A', strtotime($s['checkout_time'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-grid-2">
                    <div class="modal-field">
                        <label for="sim_check_in">Simulated Check-In Time</label>
                        <input type="time" id="sim_check_in" class="input-time" value="09:55" onchange="runSimulation()">
                    </div>
                    <div class="modal-field">
                        <label for="sim_check_out">Simulated Check-Out Time</label>
                        <input type="time" id="sim_check_out" class="input-time" value="18:00" onchange="runSimulation()">
                    </div>
                </div>
            </div>

            <!-- Simulation Calculation Output Card -->
            <div class="sim-result-card" id="simResultCard">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <span style="font-size: 0.82rem; font-weight: 700; color: var(--saas-text-muted); text-transform: uppercase;">
                        Simulated Attendance Status:
                    </span>
                    <span id="simStatusBadge" class="sim-badge-large" style="background: #ecfdf5; color: #059669;">
                        Completed
                    </span>
                </div>

                <div class="form-grid-3" style="margin-top: 14px;">
                    <div class="policy-box">
                        <div class="policy-box-label">Calculated Delay</div>
                        <div class="policy-box-val" id="simDelayVal">0 mins</div>
                    </div>
                    <div class="policy-box">
                        <div class="policy-box-label">Early Exit</div>
                        <div class="policy-box-val" id="simEarlyVal">0 mins</div>
                    </div>
                    <div class="policy-box">
                        <div class="policy-box-label">Net Working Hours</div>
                        <div class="policy-box-val" id="simWorkHoursVal">8.08 hrs</div>
                    </div>
                </div>

                <div id="simRuleExplanation" style="margin-top: 14px; padding: 12px; background: var(--adm-surface-subtle); border-radius: var(--adm-radius-md); font-size: 0.84rem; color: var(--saas-text-main); line-height: 1.5;">
                    <i class="fa-solid fa-circle-info" style="color: var(--adm-brand); margin-right: 6px;"></i>
                    <span id="simExplanationText">Punch is within grace tolerance and full work duration completed.</span>
                </div>
            </div>
        </div>

        <div class="saas-modal-footer">
            <button type="button" class="btn-saas btn-saas-secondary" onclick="closeModal('simulatorModal')">Close Simulator</button>
        </div>
    </div>
</div>

<!-- ─── Interactive JavaScript Controller ────────────────────────────────────── -->
<script>
// Modal Utilities
function openModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Open Shift Modal in Create Mode
function openCreateShiftModal() {
    document.getElementById('shiftModalHeadingText').innerText = 'Create Shift Policy';
    document.getElementById('shiftModalSubmitBtnText').innerText = 'Save Shift Policy';

    document.getElementById('shift_id').value = '';
    document.getElementById('shift_name').value = '';
    document.getElementById('shift_desc').value = '';
    document.getElementById('shift_color').value = '#4f46e5';
    setColor('#4f46e5');

    // Default timings (HH:MM without seconds for HTML5 inputs)
    document.getElementById('checkin_start').value = '09:00';
    document.getElementById('checkin_end').value = '09:30';
    document.getElementById('checkout_time').value = '18:00';
    document.getElementById('grace_minutes').value = '15';
    document.getElementById('late_threshold').value = '30';
    document.getElementById('early_exit_threshold').value = '30';
    document.getElementById('shift_is_active').checked = false;

    calculateLiveShiftDuration();
    openModal('shiftModal');
}

// Handle Edit Shift via Safe Deserialized JSON
function handleEditShift(btn) {
    try {
        const raw = btn.getAttribute('data-shift');
        const shift = JSON.parse(raw);

        document.getElementById('shiftModalHeadingText').innerText = 'Edit Shift Policy';
        document.getElementById('shiftModalSubmitBtnText').innerText = 'Update Shift Policy';

        document.getElementById('shift_id').value = shift.id || '';
        document.getElementById('shift_name').value = shift.shift_name || '';
        document.getElementById('shift_desc').value = shift.description || '';
        
        const shiftColor = shift.color || '#4f46e5';
        document.getElementById('shift_color').value = shiftColor;
        setColor(shiftColor);

        // Strip seconds (:00) for standard HTML5 time inputs
        document.getElementById('checkin_start').value = (shift.checkin_start || '09:00').substring(0, 5);
        document.getElementById('checkin_end').value = (shift.checkin_end || '09:30').substring(0, 5);
        document.getElementById('checkout_time').value = (shift.checkout_time || '18:00').substring(0, 5);

        document.getElementById('grace_minutes').value = shift.grace_minutes || 0;
        document.getElementById('late_threshold').value = shift.late_threshold || 0;
        document.getElementById('early_exit_threshold').value = shift.early_exit_threshold || 0;
        document.getElementById('shift_is_active').checked = Boolean(Number(shift.is_active));

        calculateLiveShiftDuration();
        openModal('shiftModal');
    } catch (e) {
        console.error("Error deserializing shift edit data:", e);
        alert("Error loading shift details for editing.");
    }
}

// Color Swatch Helpers
function setColor(hex) {
    document.getElementById('shift_color').value = hex;
    const swatches = document.querySelectorAll('.color-swatch-btn');
    swatches.forEach(s => {
        if (s.style.backgroundColor.toLowerCase() === hex.toLowerCase()) {
            s.classList.add('active');
        } else {
            s.classList.remove('active');
        }
    });
}
function syncColorSwatch(hex) {
    setColor(hex);
}

// Live Shift Duration Calculator inside Modal
function calculateLiveShiftDuration() {
    const startVal = document.getElementById('checkin_start').value;
    const endVal = document.getElementById('checkout_time').value;
    const previewEl = document.getElementById('modalLiveDuration');

    if (!startVal || !endVal) {
        previewEl.innerText = '--';
        return;
    }

    const [sH, sM] = startVal.split(':').map(Number);
    const [eH, eM] = endVal.split(':').map(Number);

    let startMins = sH * 60 + sM;
    let endMins = eH * 60 + eM;

    if (endMins < startMins) {
        endMins += 24 * 60; // Overnight shift
    }

    const diff = endMins - startMins;
    const hrs = Math.floor(diff / 60);
    const mins = diff % 60;

    previewEl.innerText = (mins > 0) ? `${hrs} hrs ${mins} mins` : `${hrs} hrs`;
}

// View Switcher (Grid vs Table)
function switchView(mode) {
    const gridView = document.getElementById('shiftsGridView');
    const tableView = document.getElementById('shiftsTableView');
    const btnGrid = document.getElementById('btnViewGrid');
    const btnTable = document.getElementById('btnViewTable');

    if (mode === 'table') {
        gridView.style.display = 'none';
        tableView.classList.add('active');
        btnTable.classList.add('active');
        btnGrid.classList.remove('active');
        localStorage.setItem('shifts_view_mode', 'table');
    } else {
        gridView.style.display = 'grid';
        tableView.classList.remove('active');
        btnGrid.classList.add('active');
        btnTable.classList.remove('active');
        localStorage.setItem('shifts_view_mode', 'grid');
    }
}

// Restore user's preferred view on load
(function() {
    const savedMode = localStorage.getItem('shifts_view_mode');
    if (savedMode === 'table') {
        switchView('table');
    }
})();

// Client-side Search / Filtering
function filterShifts(query) {
    const term = query.toLowerCase().trim();
    const cards = document.querySelectorAll('.shift-card');
    const rows = document.querySelectorAll('.saas-table tbody tr');

    cards.forEach(c => {
        const text = c.textContent.toLowerCase();
        c.style.display = text.includes(term) ? 'flex' : 'none';
    });

    rows.forEach(r => {
        const text = r.textContent.toLowerCase();
        r.style.display = text.includes(term) ? '' : 'none';
    });
}

// ─── Shift Attendance Rule Simulator ─────────────────────────────────────────
function openSimulatorModal() {
    openModal('simulatorModal');
    runSimulation();
}

function runSimulation() {
    const select = document.getElementById('sim_shift_select');
    if (!select || !select.selectedOptions.length) return;

    const opt = select.selectedOptions[0];
    const shiftIn = opt.getAttribute('data-in') || '09:00:00';
    const shiftOut = opt.getAttribute('data-out') || '18:00:00';
    const graceMins = parseInt(opt.getAttribute('data-grace') || 0, 10);
    const lateThreshold = parseInt(opt.getAttribute('data-late') || 0, 10);
    const earlyThreshold = parseInt(opt.getAttribute('data-early') || 0, 10);

    const checkInVal = document.getElementById('sim_check_in').value;
    const checkOutVal = document.getElementById('sim_check_out').value;

    if (!checkInVal || !checkOutVal) return;

    // Convert times to day minutes
    function toMinutes(timeStr) {
        const parts = timeStr.split(':').map(Number);
        return parts[0] * 60 + parts[1];
    }

    const shiftStartMins = toMinutes(shiftIn);
    const shiftEndMins = toMinutes(shiftOut);
    const actualInMins = toMinutes(checkInVal);
    let actualOutMins = toMinutes(checkOutVal);

    if (actualOutMins < actualInMins) actualOutMins += 1440; // overnight punch

    // Delay calculation
    const allowedInMins = shiftStartMins + graceMins;
    let delayMins = 0;
    if (actualInMins > allowedInMins) {
        delayMins = actualInMins - shiftStartMins;
    }

    // Early exit calculation
    let earlyExitMins = 0;
    if (actualOutMins < shiftEndMins) {
        earlyExitMins = shiftEndMins - actualOutMins;
    }

    // Total working hours
    const totalWorkMins = Math.max(0, actualOutMins - actualInMins);
    const workHours = (totalWorkMins / 60).toFixed(2);

    // Status logic matching AttendanceHelper.php
    let status = 'completed';
    let explanation = 'Punched within grace period and completed official shift duration.';
    let badgeBg = '#ecfdf5';
    let badgeColor = '#059669';

    if (delayMins > lateThreshold) {
        status = 'delayed';
        explanation = `Check-in delayed by ${delayMins}m, exceeding the ${lateThreshold}m late threshold.`;
        badgeBg = '#fff1f2';
        badgeColor = '#e11d48';
    }

    if (earlyExitMins > earlyThreshold) {
        if (status === 'delayed') {
            status = 'half_day';
            explanation = `Both late arrival (${delayMins}m) and premature exit (${earlyExitMins}m) registered. Auto-classified as Half Day.`;
            badgeBg = '#fffbeb';
            badgeColor = '#d97706';
        } else {
            status = 'left_early';
            explanation = `Checked out ${earlyExitMins}m ahead of shift end, exceeding the ${earlyThreshold}m early departure threshold.`;
            badgeBg = '#fff7ed';
            badgeColor = '#ea580c';
        }
    }

    // Update UI elements
    document.getElementById('simDelayVal').innerText = `${delayMins} mins`;
    document.getElementById('simEarlyVal').innerText = `${earlyExitMins} mins`;
    document.getElementById('simWorkHoursVal').innerText = `${workHours} hrs`;

    const statusBadge = document.getElementById('simStatusBadge');
    statusBadge.innerText = status.replace('_', ' ').toUpperCase();
    statusBadge.style.backgroundColor = badgeBg;
    statusBadge.style.color = badgeColor;

    document.getElementById('simExplanationText').innerText = explanation;
}
</script>

<?php include 'footer.php'; ?>
