<?php
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
Security::requirePermission('settings.view', $adminId, '../admin/dashboard');

$canManageSettings = Security::hasPermission('settings.manage', $adminId);

$pageTitle = 'Office Location & GPS Settings';
$currentPage = 'office_location';

$flashMessage = $_SESSION['office_flash'] ?? '';
$flashType = $_SESSION['office_flash_type'] ?? '';
unset($_SESSION['office_flash'], $_SESSION['office_flash_type']);

// Handle Settings Update POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_office_settings'])) {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'office_location_settings')) {
        $_SESSION['office_flash'] = 'Invalid or expired security token. Please refresh and try again.';
        $_SESSION['office_flash_type'] = 'error';
    } elseif (!$canManageSettings) {
        $_SESSION['office_flash'] = 'Access Denied: You do not possess authorization to update office location settings.';
        $_SESSION['office_flash_type'] = 'error';
    } else {
        $officeName         = trim($_POST['office_name'] ?? '');
        $officeAddress      = trim($_POST['office_address'] ?? '');
        $officeLat          = trim($_POST['office_latitude'] ?? '');
        $officeLng          = trim($_POST['office_longitude'] ?? '');
        $officeRadius       = trim($_POST['office_radius_meters'] ?? '');
        $locationEnabled    = isset($_POST['location_attendance_enabled']) ? 1 : 0;
        $wfhEarlyWindow     = max(0, min(120, (int)($_POST['wfh_early_window_minutes'] ?? 15)));
        $wfhGracePeriod     = max(0, min(120, (int)($_POST['wfh_grace_period_minutes'] ?? 15)));
        $wfhLateThreshold   = max(0, min(360, (int)($_POST['wfh_late_threshold_minutes'] ?? 30)));
        $wfhAutoReschedule  = isset($_POST['wfh_auto_reschedule_allowed']) ? 1 : 0;

        if (empty($officeName)) {
            $_SESSION['office_flash'] = 'Office Name is required.';
            $_SESSION['office_flash_type'] = 'error';
        } elseif ($officeLat === '' || !is_numeric($officeLat) || $officeLng === '' || !is_numeric($officeLng)) {
            $_SESSION['office_flash'] = 'Valid numeric Latitude and Longitude coordinates are required.';
            $_SESSION['office_flash_type'] = 'error';
        } elseif (!Security::validateCoordinates((float)$officeLat, (float)$officeLng)) {
            $_SESSION['office_flash'] = 'Coordinates out of bounds: Latitude must be between -90 and 90, Longitude between -180 and 180.';
            $_SESSION['office_flash_type'] = 'error';
        } elseif (!is_numeric($officeRadius) || (int)$officeRadius < 10 || (int)$officeRadius > 50000) {
            $_SESSION['office_flash'] = 'Allowed attendance radius must be an integer between 10 and 50,000 meters.';
            $_SESSION['office_flash_type'] = 'error';
        } else {
            try {
                $stmtCheck = $pdo->query("SELECT id FROM site_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1");
                $existingSettingId = $stmtCheck->fetchColumn();

                if ($existingSettingId) {
                    $upStmt = $pdo->prepare("UPDATE site_settings SET 
                        office_name = ?, 
                        office_address = ?, 
                        office_latitude = ?, 
                        office_longitude = ?, 
                        office_radius_meters = ?, 
                        location_attendance_enabled = ?,
                        wfh_early_window_minutes = ?,
                        wfh_grace_period_minutes = ?,
                        wfh_late_threshold_minutes = ?,
                        wfh_auto_reschedule_allowed = ?,
                        updated_at = NOW()
                        WHERE id = ?");
                    $upStmt->execute([
                        $officeName,
                        $officeAddress ?: null,
                        (float)$officeLat,
                        (float)$officeLng,
                        (int)$officeRadius,
                        $locationEnabled,
                        $wfhEarlyWindow,
                        $wfhGracePeriod,
                        $wfhLateThreshold,
                        $wfhAutoReschedule,
                        $existingSettingId
                    ]);
                } else {
                    $inStmt = $pdo->prepare("INSERT INTO site_settings 
                        (office_name, office_address, office_latitude, office_longitude, office_radius_meters, location_attendance_enabled, wfh_early_window_minutes, wfh_grace_period_minutes, wfh_late_threshold_minutes, wfh_auto_reschedule_allowed, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $inStmt->execute([
                        $officeName,
                        $officeAddress ?: null,
                        (float)$officeLat,
                        (float)$officeLng,
                        (int)$officeRadius,
                        $locationEnabled,
                        $wfhEarlyWindow,
                        $wfhGracePeriod,
                        $wfhLateThreshold,
                        $wfhAutoReschedule
                    ]);
                }

                Security::logAudit($adminId, 'update_office_location', 'site_settings', $existingSettingId ?: (int)$pdo->lastInsertId(), [
                    'office_name' => $officeName,
                    'latitude' => (float)$officeLat,
                    'longitude' => (float)$officeLng,
                    'radius' => (int)$officeRadius,
                    'location_enabled' => $locationEnabled
                ]);

                $_SESSION['office_flash'] = 'Office location coordinates and geofence parameters saved successfully.';
                $_SESSION['office_flash_type'] = 'success';
            } catch (PDOException $e) {
                error_log("Office location settings update error: " . $e->getMessage());
                $_SESSION['office_flash'] = 'Database error while saving office location settings. Please try again.';
                $_SESSION['office_flash_type'] = 'error';
            }
        }
    }
    header('Location: office_location');
    exit();
}

// Fetch current saved office settings
$officeSettings = AttendanceHelper::getOfficeLocationSettings($pdo);
$csrfToken = Security::generateCSRFToken('office_location_settings');

// Count employee distribution (on-site vs wfh)
$onSiteCount = 0;
$wfhCount = 0;
try {
    $stEmp = $pdo->query("SELECT work_mode, COUNT(*) as c FROM employees GROUP BY work_mode");
    while ($r = $stEmp->fetch(PDO::FETCH_ASSOC)) {
        if ($r['work_mode'] === 'wfh') $wfhCount = (int)$r['c'];
        else $onSiteCount += (int)$r['c'];
    }
} catch (PDOException $e) {}

include 'header.php';
?>

<!-- Leaflet CSS for Map Display -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

<style>
.office-grid {
    display: grid;
    grid-template-columns: 420px 1fr;
    gap: 24px;
    align-items: start;
    margin-top: 20px;
}
@media (max-width: 1024px) {
    .office-grid {
        grid-template-columns: 1fr;
    }
}

.map-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    box-shadow: var(--adm-shadow-card);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.map-toolbar {
    padding: 12px 18px;
    background: var(--adm-surface-subtle);
    border-bottom: 1px solid var(--adm-border-subtle);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
#officeMap {
    height: 520px;
    width: 100%;
    z-index: 1;
}

.form-panel {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    box-shadow: var(--adm-shadow-card);
    padding: 24px;
}

.form-group-saas {
    margin-bottom: 18px;
}
.form-group-saas label {
    display: block;
    font-size: 0.84rem;
    font-weight: 700;
    margin-bottom: 6px;
    color: var(--adm-text-main);
}
.form-control-saas {
    width: 100%;
    height: 42px;
    padding: 0 14px;
    border: 1.5px solid var(--adm-border);
    border-radius: var(--adm-radius-md);
    background: var(--adm-bg);
    color: var(--adm-text-main);
    font-family: inherit;
    font-size: 0.92rem;
    transition: all var(--adm-transition);
}
.form-control-saas:focus {
    border-color: var(--adm-brand);
    outline: none;
    box-shadow: 0 0 0 3px var(--adm-brand-light);
}

.stat-pills {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.stat-pill {
    padding: 8px 14px;
    border-radius: var(--adm-radius-pill);
    background: var(--adm-surface-subtle);
    border: 1px solid var(--adm-border-subtle);
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--adm-text-body);
}

.tester-box {
    margin-top: 20px;
    padding: 16px;
    background: var(--adm-surface-subtle);
    border: 1px solid var(--adm-border-subtle);
    border-radius: var(--adm-radius-md);
}
.tester-title {
    font-size: 0.84rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--adm-muted);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
</style>

<div class="content" style="max-width: 1360px; margin: 0 auto; padding: 24px 20px 60px;">

    <!-- Breadcrumb & Header Ribbon -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 20px;">
        <div>
            <div style="font-size: 0.82rem; color: var(--adm-muted); margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                <a href="dashboard" style="color: inherit; text-decoration: none;">Admin</a>
                <span>&rsaquo;</span>
                <a href="settings" style="color: inherit; text-decoration: none;">Settings</a>
                <span>&rsaquo;</span>
                <span style="color: var(--adm-brand); font-weight: 700;">Office GPS &amp; Geofencing</span>
            </div>
            <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 800; color: var(--adm-text-main); letter-spacing: -0.02em;">
                Office Location &amp; Attendance Geofencing
            </h1>
        </div>

        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="settings" class="btn-saas" style="padding: 8px 16px; border-radius: var(--adm-radius-pill); background: var(--adm-surface); border: 1px solid var(--adm-border); color: var(--adm-text-body); font-size: 0.85rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-user-gear"></i>
                <span>Account</span>
            </a>
            <a href="site_settings" class="btn-saas" style="padding: 8px 16px; border-radius: var(--adm-radius-pill); background: var(--adm-surface); border: 1px solid var(--adm-border); color: var(--adm-text-body); font-size: 0.85rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-sliders"></i>
                <span>Branding &amp; SMTP</span>
            </a>
            <a href="office_location" class="btn-saas" style="padding: 8px 16px; border-radius: var(--adm-radius-pill); background: var(--adm-brand); border: 1px solid var(--adm-brand); color: #ffffff; font-size: 0.85rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-location-dot"></i>
                <span>Office GPS</span>
            </a>
            <a href="attendance" class="btn-saas" style="padding: 8px 16px; border-radius: var(--adm-radius-pill); background: var(--adm-brand-light); border: 1px solid var(--adm-brand-border); color: var(--adm-brand); font-size: 0.85rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-calendar-check"></i>
                <span>Attendance</span>
            </a>
        </div>
    </div>

    <!-- Flash Alert -->
    <?php if (!empty($flashMessage)): ?>
        <div class="alert-saas alert-<?php echo ($flashType === 'success') ? 'success' : 'danger'; ?>" style="margin-bottom: 20px; padding: 14px 18px; border-radius: var(--adm-radius-md); font-weight: 600; display: flex; align-items: center; gap: 10px; background: <?php echo ($flashType === 'success') ? 'var(--adm-success-light)' : 'var(--adm-danger-light)'; ?>; color: <?php echo ($flashType === 'success') ? 'var(--adm-success)' : 'var(--adm-danger)'; ?>; border: 1px solid <?php echo ($flashType === 'success') ? 'var(--adm-success)' : 'var(--adm-danger-border)'; ?>;">
            <i class="fa-solid <?php echo ($flashType === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($flashMessage); ?></span>
        </div>
    <?php endif; ?>

    <!-- Summary Stats Pill Bar -->
    <div class="stat-pills">
        <div class="stat-pill">
            <i class="fa-solid fa-building" style="color: var(--adm-brand);"></i>
            <span>Active Office: <strong><?php echo htmlspecialchars($officeSettings['office_name']); ?></strong></span>
        </div>
        <div class="stat-pill">
            <i class="fa-solid fa-circle-dot" style="color: #059669;"></i>
            <span>Allowed Radius: <strong><?php echo (int)$officeSettings['office_radius_meters']; ?> meters</strong></span>
        </div>
        <div class="stat-pill">
            <i class="fa-solid fa-users" style="color: var(--adm-brand);"></i>
            <span>Policy: <strong><?php echo $onSiteCount; ?> On-Site</strong> (Restricted) &bull; <strong><?php echo $wfhCount; ?> WFH</strong> (Exempt)</span>
        </div>
        <div class="stat-pill">
            <i class="fa-solid <?php echo !empty($officeSettings['location_attendance_enabled']) ? 'fa-shield-halved' : 'fa-shield'; ?>" style="color: <?php echo !empty($officeSettings['location_attendance_enabled']) ? '#059669' : '#d97706'; ?>;"></i>
            <span>Status: <strong><?php echo !empty($officeSettings['location_attendance_enabled']) ? 'Geofencing Active' : 'Geofencing Disabled'; ?></strong></span>
        </div>
    </div>

    <!-- Main Two-Column Layout (Form + Map) -->
    <div class="office-grid">

        <!-- Left: Configuration Form -->
        <div class="form-panel">
            <h2 style="font-size: 1.15rem; font-weight: 800; color: var(--adm-text-main); margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-sliders" style="color: var(--adm-brand);"></i>
                <span>Geofence Parameters</span>
            </h2>
            <p style="font-size: 0.82rem; color: var(--adm-muted); margin-bottom: 20px; line-height: 1.5;">
                Enter coordinates manually, drag the map marker, or use browser GPS detection.
            </p>

            <form method="POST" action="" id="officeSettingsForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <div class="form-group-saas">
                    <label for="office_name">Office Location Name <span style="color:var(--adm-danger);">*</span></label>
                    <input type="text" id="office_name" name="office_name" class="form-control-saas" 
                        value="<?php echo htmlspecialchars($officeSettings['office_name']); ?>" 
                        required maxlength="150" <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                        placeholder="e.g. Cloud Aspire Headquarters - Main Campus">
                </div>

                <div class="form-group-saas">
                    <label for="office_address">Physical Address (Optional)</label>
                    <input type="text" id="office_address" name="office_address" class="form-control-saas" 
                        value="<?php echo htmlspecialchars($officeSettings['office_address']); ?>" 
                        maxlength="255" <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                        placeholder="e.g. 123 Tech Park, Puducherry, India">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;" class="form-group-saas">
                    <div>
                        <label for="office_latitude">Latitude <span style="color:var(--adm-danger);">*</span></label>
                        <input type="number" step="any" min="-90" max="90" id="office_latitude" name="office_latitude" class="form-control-saas" 
                            value="<?php echo htmlspecialchars((string)$officeSettings['office_latitude']); ?>" 
                            required <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                            onchange="updateMapFromInputs()">
                    </div>
                    <div>
                        <label for="office_longitude">Longitude <span style="color:var(--adm-danger);">*</span></label>
                        <input type="number" step="any" min="-180" max="180" id="office_longitude" name="office_longitude" class="form-control-saas" 
                            value="<?php echo htmlspecialchars((string)$officeSettings['office_longitude']); ?>" 
                            required <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                            onchange="updateMapFromInputs()">
                    </div>
                </div>

                <div class="form-group-saas">
                    <label for="office_radius_meters">Allowed Attendance Radius (Meters) <span style="color:var(--adm-danger);">*</span></label>
                    <input type="number" min="10" max="50000" id="office_radius_meters" name="office_radius_meters" class="form-control-saas" 
                        value="<?php echo htmlspecialchars((string)$officeSettings['office_radius_meters']); ?>" 
                        required <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                        oninput="updateRadiusFromInput()">
                    <small style="font-size: 0.75rem; color: var(--adm-muted); margin-top: 4px; display: block;">
                        Recommended: 50m to 150m for a standard building; 200m+ for large multi-acre campuses.
                    </small>
                </div>

                <div class="form-group-saas" style="margin-top: 10px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: <?php echo $canManageSettings ? 'pointer' : 'not-allowed'; ?>; padding: 12px 14px; background: var(--adm-surface-subtle); border: 1.5px solid var(--adm-border); border-radius: var(--adm-radius-md); font-size: 0.88rem; font-weight: 700; color: var(--adm-text-main);">
                        <input type="checkbox" name="location_attendance_enabled" value="1" <?php echo !empty($officeSettings['location_attendance_enabled']) ? 'checked' : ''; ?> <?php echo !$canManageSettings ? 'disabled' : ''; ?> style="width: 18px; height: 18px; accent-color: var(--adm-brand);">
                        <span>Enforce Geofence Restriction for On-Site Staff</span>
                    </label>
                    <small style="font-size: 0.75rem; color: var(--adm-muted); margin-top: 4px; display: block;">
                        When unchecked, employees can punch in/out from anywhere without GPS distance checks.
                    </small>
                </div>

                <!-- WFH & Scheduled Check-In Policies Section -->
                <div style="margin-top: 22px; padding: 16px; background: rgba(79, 70, 229, 0.03); border: 1.5px solid rgba(79, 70, 229, 0.15); border-radius: var(--adm-radius-md);">
                    <h3 style="font-size: 0.95rem; font-weight: 800; color: var(--adm-text-main); margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-business-time" style="color: var(--adm-brand);"></i>
                        <span>WFH &amp; Scheduled Check-In Policy</span>
                    </h3>
                    <p style="font-size: 0.76rem; color: var(--adm-muted); margin-bottom: 14px; line-height: 1.4;">
                        Enforce server-side rules when employees punch in with an approved WFH or on-site exception time.
                    </p>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;" class="form-group-saas">
                        <div>
                            <label for="wfh_early_window_minutes" style="font-size: 0.78rem;">Early Window (Mins)</label>
                            <input type="number" min="0" max="120" id="wfh_early_window_minutes" name="wfh_early_window_minutes" class="form-control-saas" 
                                value="<?php echo htmlspecialchars((string)($officeSettings['wfh_early_window_minutes'] ?? 15)); ?>" 
                                <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                                title="Minutes before approved time employee is allowed to punch in">
                            <small style="font-size: 0.7rem; color: var(--adm-muted);">Rejects punch if too early</small>
                        </div>
                        <div>
                            <label for="wfh_grace_period_minutes" style="font-size: 0.78rem;">Grace Period (Mins)</label>
                            <input type="number" min="0" max="120" id="wfh_grace_period_minutes" name="wfh_grace_period_minutes" class="form-control-saas" 
                                value="<?php echo htmlspecialchars((string)($officeSettings['wfh_grace_period_minutes'] ?? 15)); ?>" 
                                <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                                title="Minutes after approved time marked on-time before marked delayed">
                            <small style="font-size: 0.7rem; color: var(--adm-muted);">Window marked on-time</small>
                        </div>
                    </div>

                    <div class="form-group-saas" style="margin-top: 10px;">
                        <label for="wfh_late_threshold_minutes" style="font-size: 0.78rem;">Late Check-In Window (Mins)</label>
                        <input type="number" min="0" max="360" id="wfh_late_threshold_minutes" name="wfh_late_threshold_minutes" class="form-control-saas" 
                            value="<?php echo htmlspecialchars((string)($officeSettings['wfh_late_threshold_minutes'] ?? 30)); ?>" 
                            <?php echo !$canManageSettings ? 'readonly' : ''; ?>>
                        <small style="font-size: 0.7rem; color: var(--adm-muted); display: block; margin-top: 3px;">
                            Permits check-in with a delayed flag up to this threshold.
                        </small>
                    </div>

                    <div style="margin-top: 10px;">
                        <label style="display: flex; align-items: flex-start; gap: 8px; cursor: <?php echo $canManageSettings ? 'pointer' : 'not-allowed'; ?>; font-size: 0.8rem; font-weight: 600; color: var(--adm-text-body);">
                            <input type="checkbox" name="wfh_auto_reschedule_allowed" value="1" <?php echo !empty($officeSettings['wfh_auto_reschedule_allowed']) ? 'checked' : ''; ?> <?php echo !$canManageSettings ? 'disabled' : ''; ?> style="width: 16px; height: 16px; margin-top: 2px; accent-color: var(--adm-brand);">
                            <span>Allow Late Check-In Authorization Without Requiring Fresh Approval</span>
                        </label>
                    </div>
                </div>

                <?php if ($canManageSettings): ?>
                    <button type="submit" name="save_office_settings" class="btn-saas" style="width: 100%; height: 46px; margin-top: 16px; border: none; border-radius: var(--adm-radius-md); background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); color: #ffffff; font-weight: 800; font-size: 0.95rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>Save Office Geofencing Settings</span>
                    </button>
                <?php else: ?>
                    <div style="margin-top: 16px; padding: 10px 14px; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25); color: #dc2626; border-radius: var(--adm-radius-md); font-size: 0.82rem; font-weight: 600;">
                        <i class="fa-solid fa-lock"></i> Read-only mode: You need the <code>manage_settings</code> permission to save changes.
                    </div>
                <?php endif; ?>
            </form>

            <!-- Distance Testing Sandbox -->
            <div class="tester-box">
                <div class="tester-title">
                    <i class="fa-solid fa-vial"></i>
                    <span>Distance &amp; Geofence Tester</span>
                </div>
                <p style="font-size: 0.78rem; color: var(--adm-muted); margin-bottom: 10px;">
                    Click anywhere on the map or enter coordinates below to test distance calculation against current settings.
                </p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;">
                    <input type="number" step="any" id="test_lat" placeholder="Test Lat" class="form-control-saas" style="height: 36px; font-size: 0.82rem;">
                    <input type="number" step="any" id="test_lng" placeholder="Test Lng" class="form-control-saas" style="height: 36px; font-size: 0.82rem;">
                </div>
                <button type="button" onclick="runDistanceTest()" class="btn-saas" style="width: 100%; height: 34px; padding: 0 12px; border-radius: var(--adm-radius-sm); background: var(--adm-surface); border: 1px solid var(--adm-border); font-size: 0.8rem; font-weight: 700; color: var(--adm-text-body); cursor: pointer;">
                    Calculate Distance &amp; Result
                </button>
                <div id="testResultBox" style="display: none; margin-top: 10px; padding: 8px 12px; border-radius: var(--adm-radius-sm); font-size: 0.8rem; font-weight: 700;"></div>
            </div>

        </div>

        <!-- Right: Interactive Leaflet Map -->
        <div class="map-card">
            <div class="map-toolbar">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 0.88rem; font-weight: 800; color: var(--adm-text-main); display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-map-location-dot" style="color: var(--adm-brand);"></i>
                        <span>Satellite &amp; OpenStreetMap Boundary</span>
                    </span>
                </div>

                <div style="display: flex; gap: 8px;">
                    <?php if ($canManageSettings): ?>
                        <button type="button" class="btn-saas" onclick="detectAdminGPS()" style="height: 32px; padding: 0 12px; border-radius: var(--adm-radius-pill); background: var(--adm-brand-light); border: 1px solid var(--adm-brand-border); color: var(--adm-brand); font-size: 0.78rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-crosshairs"></i>
                            <span>Detect My GPS</span>
                        </button>
                    <?php endif; ?>
                    <a id="btnGmapsExternal" href="https://www.google.com/maps?q=<?php echo urlencode($officeSettings['office_latitude'] . ',' . $officeSettings['office_longitude']); ?>" target="_blank" rel="noopener noreferrer" class="btn-saas" style="height: 32px; padding: 0 12px; border-radius: var(--adm-radius-pill); background: var(--adm-surface); border: 1px solid var(--adm-border); color: var(--adm-text-body); font-size: 0.78rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        <span>Google Maps</span>
                    </a>
                </div>
            </div>

            <!-- Map Container -->
            <div id="officeMap"></div>

            <div style="padding: 12px 18px; background: var(--adm-surface-subtle); border-top: 1px solid var(--adm-border-subtle); font-size: 0.78rem; color: var(--adm-muted); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <span>💡 <strong>Tip:</strong> Drag the blue marker or click anywhere on the map to relocate the office center.</span>
                <span id="mapCoordinatesDisplay" style="font-family: monospace; font-weight: 700; color: var(--adm-text-main);"></span>
            </div>
        </div>

    </div>

</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
    let officeLat = <?php echo (float)$officeSettings['office_latitude']; ?>;
    let officeLng = <?php echo (float)$officeSettings['office_longitude']; ?>;
    let officeRadius = <?php echo (int)$officeSettings['office_radius_meters']; ?>;

    const map = L.map('officeMap').setView([officeLat, officeLng], 16);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Office Pin Marker (Draggable)
    const officeMarker = L.marker([officeLat, officeLng], {
        draggable: <?php echo $canManageSettings ? 'true' : 'false'; ?>
    }).addTo(map);

    officeMarker.bindPopup("<strong><?php echo htmlspecialchars($officeSettings['office_name']); ?></strong><br>Center of allowed attendance zone.").openPopup();

    // Geofence Circle
    const geofenceCircle = L.circle([officeLat, officeLng], {
        color: '#4f46e5',
        fillColor: '#6366f1',
        fillOpacity: 0.18,
        radius: officeRadius,
        weight: 2
    }).addTo(map);

    // Test Marker for Distance Testing Sandbox
    let testMarker = null;

    function updateCoordinatesDisplay(lat, lng) {
        document.getElementById('mapCoordinatesDisplay').textContent = `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`;
        document.getElementById('btnGmapsExternal').href = `https://www.google.com/maps?q=${lat},${lng}`;
    }
    updateCoordinatesDisplay(officeLat, officeLng);

    // Drag marker event
    officeMarker.on('dragend', function(e) {
        const pos = e.target.getLatLng();
        document.getElementById('office_latitude').value = pos.lat.toFixed(8);
        document.getElementById('office_longitude').value = pos.lng.toFixed(8);
        geofenceCircle.setLatLng(pos);
        updateCoordinatesDisplay(pos.lat, pos.lng);
    });

    // Click map to reposition office
    map.on('click', function(e) {
        <?php if ($canManageSettings): ?>
            officeMarker.setLatLng(e.latlng);
            geofenceCircle.setLatLng(e.latlng);
            document.getElementById('office_latitude').value = e.latlng.lat.toFixed(8);
            document.getElementById('office_longitude').value = e.latlng.lng.toFixed(8);
            updateCoordinatesDisplay(e.latlng.lat, e.latlng.lng);
        <?php else: ?>
            // If read only, populate test sandbox
            document.getElementById('test_lat').value = e.latlng.lat.toFixed(8);
            document.getElementById('test_lng').value = e.latlng.lng.toFixed(8);
            runDistanceTest();
        <?php endif; ?>
    });

    // Update from Form Inputs
    function updateMapFromInputs() {
        const latVal = parseFloat(document.getElementById('office_latitude').value);
        const lngVal = parseFloat(document.getElementById('office_longitude').value);
        if (!isNaN(latVal) && !isNaN(lngVal) && latVal >= -90 && latVal <= 90 && lngVal >= -180 && lngVal <= 180) {
            const newPos = [latVal, lngVal];
            officeMarker.setLatLng(newPos);
            geofenceCircle.setLatLng(newPos);
            map.panTo(newPos);
            updateCoordinatesDisplay(latVal, lngVal);
        }
    }

    function updateRadiusFromInput() {
        const radVal = parseInt(document.getElementById('office_radius_meters').value, 10);
        if (!isNaN(radVal) && radVal >= 10 && radVal <= 50000) {
            geofenceCircle.setRadius(radVal);
        }
    }

    // Detect Admin Device GPS
    function detectAdminGPS() {
        if (!navigator.geolocation) {
            alert("Geolocation is not supported by your browser.");
            return;
        }

        const btn = event.currentTarget;
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Detecting...';

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                btn.disabled = false;
                btn.innerHTML = origText;
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                document.getElementById('office_latitude').value = lat.toFixed(8);
                document.getElementById('office_longitude').value = lng.toFixed(8);
                const newPos = [lat, lng];
                officeMarker.setLatLng(newPos);
                geofenceCircle.setLatLng(newPos);
                map.setView(newPos, 17);
                updateCoordinatesDisplay(lat, lng);
            },
            function(err) {
                btn.disabled = false;
                btn.innerHTML = origText;
                alert("GPS detection failed: " + err.message + ". Please ensure browser location permissions are granted.");
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    }

    // Haversine Distance Formula in JS for Testing Widget
    function haversineDistance(lat1, lon1, lat2, lon2) {
        const R = 6371000; // meters
        const phi1 = lat1 * Math.PI / 180;
        const phi2 = lat2 * Math.PI / 180;
        const deltaPhi = (lat2 - lat1) * Math.PI / 180;
        const deltaLambda = (lon2 - lon1) * Math.PI / 180;

        const a = Math.sin(deltaPhi / 2) * Math.sin(deltaPhi / 2) +
                  Math.cos(phi1) * Math.cos(phi2) *
                  Math.sin(deltaLambda / 2) * Math.sin(deltaLambda / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return Math.round(R * c);
    }

    function runDistanceTest() {
        const offLat = parseFloat(document.getElementById('office_latitude').value);
        const offLng = parseFloat(document.getElementById('office_longitude').value);
        const offRad = parseInt(document.getElementById('office_radius_meters').value, 10) || 100;

        const tLat = parseFloat(document.getElementById('test_lat').value);
        const tLng = parseFloat(document.getElementById('test_lng').value);

        const resBox = document.getElementById('testResultBox');

        if (isNaN(tLat) || isNaN(tLng)) {
            alert("Please enter test latitude and longitude coordinates.");
            return;
        }

        const dist = haversineDistance(offLat, offLng, tLat, tLng);
        const isAllowed = dist <= offRad;

        if (testMarker) map.removeLayer(testMarker);
        testMarker = L.circleMarker([tLat, tLng], {
            radius: 8,
            color: isAllowed ? '#059669' : '#dc2626',
            fillColor: isAllowed ? '#10b981' : '#ef4444',
            fillOpacity: 0.8
        }).addTo(map);

        testMarker.bindPopup(`<strong>Test Location</strong><br>Distance: ${dist}m<br>Status: ${isAllowed ? 'ALLOWED' : 'REJECTED'}`).openPopup();

        resBox.style.display = 'block';
        if (isAllowed) {
            resBox.style.background = 'var(--adm-success-light)';
            resBox.style.color = 'var(--adm-success)';
            resBox.style.border = '1px solid var(--adm-success)';
            resBox.innerHTML = `<i class="fa-solid fa-circle-check"></i> <strong>VERIFIED:</strong> Distance is <strong>${dist}m</strong> (Inside ${offRad}m radius). Check-in would be permitted!`;
        } else {
            resBox.style.background = 'var(--adm-danger-light)';
            resBox.style.color = 'var(--adm-danger)';
            resBox.style.border = '1px solid var(--adm-danger-border)';
            resBox.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> <strong>OUTSIDE GEOFENCE:</strong> Distance is <strong>${dist}m</strong> (Exceeds ${offRad}m radius). Check-in would be rejected!`;
        }
    }
</script>

<?php include 'footer.php'; ?>
