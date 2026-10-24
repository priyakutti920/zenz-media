<?php
// ============================================================================
// Enterprise White-Label SaaS Administrator Account & Security Console
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

$pageTitle = 'Account & Security Settings';
$currentPage = 'settings';

// Safe resolution of admin ID
$adminId = $_SESSION['admin_id'] ?? null;
if (!$adminId && isset($_SESSION['admin_username'])) {
    try {
        $st = $pdo->prepare("SELECT id FROM admin WHERE username = ? LIMIT 1");
        $st->execute([$_SESSION['admin_username']]);
        $adminId = $st->fetchColumn() ?: 1;
        $_SESSION['admin_id'] = $adminId;
    } catch (PDOException $e) {
        $adminId = 1;
    }
}

// ----------------------------------------------------------------------------
// Process POST Actions (Profile Update & Password Change) with PRG
// ----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    // 1. Handle Profile Update Action
    if (isset($_POST['update_profile'])) {
        if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'update_profile')) {
            $_SESSION['settings_flash'] = 'Invalid or expired security token. Please refresh and try again.';
            $_SESSION['settings_flash_type'] = 'error';
        } else {
            $newUsername = trim($_POST['username'] ?? '');
            $newEmail = trim($_POST['email'] ?? '');

            if (empty($newUsername)) {
                $_SESSION['settings_flash'] = 'Administrator username cannot be blank.';
                $_SESSION['settings_flash_type'] = 'error';
            } elseif (strlen($newUsername) < 3 || strlen($newUsername) > 50) {
                $_SESSION['settings_flash'] = 'Username must be between 3 and 50 characters.';
                $_SESSION['settings_flash_type'] = 'error';
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $newUsername)) {
                $_SESSION['settings_flash'] = 'Username may only contain letters, numbers, hyphens, periods, and underscores.';
                $_SESSION['settings_flash_type'] = 'error';
            } elseif (!empty($newEmail) && !Security::validateEmail($newEmail)) {
                $_SESSION['settings_flash'] = 'Please provide a valid corporate email address.';
                $_SESSION['settings_flash_type'] = 'error';
            } else {
                try {
                    // Check duplicate username or email against other administrators
                    $dupStmt = $pdo->prepare("SELECT id FROM admin WHERE (username = ? OR (email = ? AND email IS NOT NULL AND email != '')) AND id != ?");
                    $dupStmt->execute([$newUsername, $newEmail ?: '', $adminId]);
                    if ($dupStmt->fetch()) {
                        $_SESSION['settings_flash'] = 'This username or email is already associated with another administrator account.';
                        $_SESSION['settings_flash_type'] = 'error';
                    } else {
                        $stmt = $pdo->prepare("UPDATE admin SET username = ?, email = ? WHERE id = ?");
                        $stmt->execute([$newUsername, $newEmail ?: null, $adminId]);

                        $_SESSION['admin_username'] = $newUsername;
                        $_SESSION['admin_email'] = $newEmail;

                        Security::logAudit($adminId, 'update_profile', 'admin', $adminId, ['username' => $newUsername, 'email' => $newEmail]);

                        $_SESSION['settings_flash'] = 'Administrator profile details updated successfully.';
                        $_SESSION['settings_flash_type'] = 'success';
                    }
                } catch (PDOException $e) {
                    error_log("Profile update error: " . $e->getMessage());
                    $_SESSION['settings_flash'] = 'Database error occurred while updating profile.';
                    $_SESSION['settings_flash_type'] = 'error';
                }
            }
        }
        header('Location: settings');
        exit();
    }

    // 2. Handle Password Change Action
    elseif (isset($_POST['change_password'])) {
        if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'change_password')) {
            $_SESSION['settings_flash'] = 'Invalid or expired security token. Please refresh and try again.';
            $_SESSION['settings_flash_type'] = 'error';
        } else {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $_SESSION['settings_flash'] = 'All password fields are required.';
                $_SESSION['settings_flash_type'] = 'error';
            } elseif ($newPassword !== $confirmPassword) {
                $_SESSION['settings_flash'] = 'New password and confirmation do not match.';
                $_SESSION['settings_flash_type'] = 'error';
            } else {
                // Strict password policy check (min 8 chars, uppercase, lowercase, number)
                $passwordErrors = Security::validatePasswordStrength($newPassword);
                if (!empty($passwordErrors)) {
                    $_SESSION['settings_flash'] = implode(' ', $passwordErrors);
                    $_SESSION['settings_flash_type'] = 'error';
                } else {
                    try {
                        $stmt = $pdo->prepare("SELECT password FROM admin WHERE id = ?");
                        $stmt->execute([$adminId]);
                        $admin = $stmt->fetch();

                        if ($admin && password_verify($currentPassword, $admin['password'])) {
                            if (password_verify($newPassword, $admin['password'])) {
                                $_SESSION['settings_flash'] = 'New password cannot be identical to your current password.';
                                $_SESSION['settings_flash_type'] = 'error';
                            } else {
                                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                                $upStmt = $pdo->prepare("UPDATE admin SET password = ? WHERE id = ?");
                                $upStmt->execute([$hashedPassword, $adminId]);

                                Security::logAudit($adminId, 'change_password', 'admin', $adminId, ['status' => 'password_updated']);

                                $_SESSION['settings_flash'] = 'Security credentials updated successfully. Your new password is now active.';
                                $_SESSION['settings_flash_type'] = 'success';
                            }
                        } else {
                            $_SESSION['settings_flash'] = 'Current password verification failed. Please try again.';
                            $_SESSION['settings_flash_type'] = 'error';
                        }
                    } catch (PDOException $e) {
                        error_log("Password update error: " . $e->getMessage());
                        $_SESSION['settings_flash'] = 'Database error occurred while updating password.';
                        $_SESSION['settings_flash_type'] = 'error';
                    }
                }
            }
        }
        header('Location: settings');
        exit();
    }

    // 3. Handle Single Session Termination Action
    elseif (isset($_POST['terminate_session'])) {
        if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'terminate_session')) {
            $_SESSION['settings_flash'] = 'Invalid or expired security token. Please refresh and try again.';
            $_SESSION['settings_flash_type'] = 'error';
        } else {
            $targetSid = trim($_POST['session_id'] ?? '');
            $currentSid = session_id();

            if ($targetSid === $currentSid) {
                Security::terminateSession($targetSid, $adminId, 'admin');
                Security::destroySession();
                header("Location: ../admin");
                exit();
            } else {
                Security::terminateSession($targetSid, $adminId, 'admin');
                $_SESSION['settings_flash'] = 'Active session was terminated successfully. The device has been revoked.';
                $_SESSION['settings_flash_type'] = 'success';
            }
        }
        header('Location: settings');
        exit();
    }

    // 4. Handle Terminate All Other Sessions Action
    elseif (isset($_POST['terminate_all_others'])) {
        if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'terminate_all_others')) {
            $_SESSION['settings_flash'] = 'Invalid or expired security token. Please refresh and try again.';
            $_SESSION['settings_flash_type'] = 'error';
        } else {
            $currentSid = session_id();
            Security::terminateOtherSessions($currentSid, $adminId, 'admin');
            $_SESSION['settings_flash'] = 'All other active sessions have been terminated. Only this current device remains authorized.';
            $_SESSION['settings_flash_type'] = 'success';
        }
        header('Location: settings');
        exit();
    }

    // 5. Handle Mock Session Simulation (for Testing Multi-Device Revocation)
    elseif (isset($_POST['simulate_session'])) {
        if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'simulate_session')) {
            $_SESSION['settings_flash'] = 'Invalid or expired security token.';
            $_SESSION['settings_flash_type'] = 'error';
        } else {
            $devices = [
                ['ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'ip' => '49.37.152.88', 'role' => 'admin'],
                ['ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 'ip' => '103.212.144.12', 'role' => 'admin'],
                ['ua' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.122 Mobile Safari/537.36', 'ip' => '157.48.21.65', 'role' => 'admin']
            ];
            $choice = $devices[array_rand($devices)];
            $simSid = 'sim_' . bin2hex(random_bytes(16));
            $parsed = Security::parseUserAgent($choice['ua']);

            $stmt = $pdo->prepare("INSERT INTO active_sessions (session_id, user_id, role, ip_address, user_agent, device_type, browser, platform, last_activity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 20 + 2) MINUTE))");
            $stmt->execute([
                $simSid,
                $adminId,
                'admin',
                $choice['ip'],
                $choice['ua'],
                $parsed['device_type'],
                $parsed['browser'],
                $parsed['platform']
            ]);

            $_SESSION['settings_flash'] = "Simulated active session created for {$parsed['device_type']} ({$parsed['browser']} on {$parsed['platform']}). You can now test terminating it!";
            $_SESSION['settings_flash_type'] = 'success';
        }
        header('Location: settings');
        exit();
    }

    // 6. Handle Office Geolocation & Attendance Boundaries Update
    elseif (isset($_POST['update_office_location'])) {
        if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'update_office_location')) {
            $_SESSION['settings_flash'] = 'Invalid or expired security token. Please refresh and try again.';
            $_SESSION['settings_flash_type'] = 'error';
        } elseif (!Security::hasPermission('manage_settings', $adminId)) {
            $_SESSION['settings_flash'] = 'Access Denied: You do not possess authorization to update office location settings.';
            $_SESSION['settings_flash_type'] = 'error';
        } else {
            $officeName = trim($_POST['office_name'] ?? '');
            $officeAddress = trim($_POST['office_address'] ?? '');
            $officeLat = trim($_POST['office_latitude'] ?? '');
            $officeLng = trim($_POST['office_longitude'] ?? '');
            $officeRadius = trim($_POST['office_radius_meters'] ?? '');
            $locationEnabled = isset($_POST['location_attendance_enabled']) ? 1 : 0;

            if (empty($officeName)) {
                $_SESSION['settings_flash'] = 'Office Name is required.';
                $_SESSION['settings_flash_type'] = 'error';
            } elseif ($officeLat === '' || !is_numeric($officeLat) || $officeLng === '' || !is_numeric($officeLng)) {
                $_SESSION['settings_flash'] = 'Valid numeric Latitude and Longitude coordinates are required.';
                $_SESSION['settings_flash_type'] = 'error';
            } elseif (!Security::validateCoordinates((float)$officeLat, (float)$officeLng)) {
                $_SESSION['settings_flash'] = 'Coordinates out of bounds: Latitude must be between -90 and 90, Longitude between -180 and 180.';
                $_SESSION['settings_flash_type'] = 'error';
            } elseif (!is_numeric($officeRadius) || (int)$officeRadius <= 0 || (int)$officeRadius > 50000) {
                $_SESSION['settings_flash'] = 'Allowed attendance radius must be a positive integer between 10 and 50,000 meters.';
                $_SESSION['settings_flash_type'] = 'error';
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
                            updated_at = NOW()
                            WHERE id = ?");
                        $upStmt->execute([
                            $officeName,
                            $officeAddress ?: null,
                            (float)$officeLat,
                            (float)$officeLng,
                            (int)$officeRadius,
                            $locationEnabled,
                            $existingSettingId
                        ]);
                    } else {
                        $inStmt = $pdo->prepare("INSERT INTO site_settings 
                            (office_name, office_address, office_latitude, office_longitude, office_radius_meters, location_attendance_enabled, is_active) 
                            VALUES (?, ?, ?, ?, ?, ?, 1)");
                        $inStmt->execute([
                            $officeName,
                            $officeAddress ?: null,
                            (float)$officeLat,
                            (float)$officeLng,
                            (int)$officeRadius,
                            $locationEnabled
                        ]);
                    }

                    $_SESSION['settings_flash'] = 'Office location and geofencing parameters saved successfully.';
                    $_SESSION['settings_flash_type'] = 'success';
                } catch (PDOException $e) {
                    error_log("Office location settings update error: " . $e->getMessage());
                    $_SESSION['settings_flash'] = 'Database error while saving office location settings.';
                    $_SESSION['settings_flash_type'] = 'error';
                }
            }
        }
        header('Location: settings');
        exit();
    }
}

// ----------------------------------------------------------------------------
// Retrieve Flash Messages & Admin Record
// ----------------------------------------------------------------------------
$flashMessage = $_SESSION['settings_flash'] ?? '';
$flashType = $_SESSION['settings_flash_type'] ?? '';
unset($_SESSION['settings_flash'], $_SESSION['settings_flash_type']);

// Track current session and retrieve active sessions
Security::trackCurrentSession($adminId, 'admin');
$activeSessions = Security::getActiveSessions($adminId, 'admin');
$activeSessionsCount = count($activeSessions);

try {
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE id = ?");
    $stmt->execute([$adminId]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$adminData) {
        $adminData = [
            'id' => $adminId,
            'username' => $_SESSION['admin_username'] ?? 'admin',
            'email' => $_SESSION['admin_email'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
} catch (PDOException $e) {
    error_log("Fetch admin data error: " . $e->getMessage());
    $adminData = [
        'id' => $adminId,
        'username' => $_SESSION['admin_username'] ?? 'admin',
        'email' => $_SESSION['admin_email'] ?? '',
        'created_at' => date('Y-m-d H:i:s')
    ];
}

// Fetch active Office Location Settings
$officeLocationSettings = AttendanceHelper::getOfficeLocationSettings($pdo);

// Environment Telemetry Details
$phpVersion = PHP_VERSION;
$dbVersion = 'Unknown';
try {
    $dbVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (Throwable $e) {}
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache/XAMPP';
$memoryLimit = ini_get('memory_limit') ?: '128M';
$uploadMax = ini_get('upload_max_filesize') ?: '2M';
$currentTimezone = date_default_timezone_get();
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$memberSince = !empty($adminData['created_at']) ? date('M j, Y', strtotime($adminData['created_at'])) : 'System Creation';

$csrfTokenProfile = Security::generateCSRFToken('update_profile');
$csrfTokenPassword = Security::generateCSRFToken('change_password');
$csrfTokenTerminate = Security::generateCSRFToken('terminate_session');
$csrfTokenTerminateAll = Security::generateCSRFToken('terminate_all_others');
$csrfTokenSimulate = Security::generateCSRFToken('simulate_session');
$csrfTokenOfficeLocation = Security::generateCSRFToken('update_office_location');

include 'header.php';
?>

<!-- ─── White-Label SaaS Settings Styling ───────────────────────────────────── -->
<style>
.settings-page-wrapper {
    display: flex;
    flex-direction: column;
    gap: 22px;
}

/* Hero Header & Breadcrumb */
.settings-hero-header {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    padding: 24px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    box-shadow: var(--adm-shadow-card);
    position: relative;
    overflow: hidden;
}
.settings-hero-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 6px;
    height: 100%;
    background: linear-gradient(180deg, var(--adm-brand), #7c3aed);
}
.settings-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--adm-muted);
    margin-bottom: 6px;
}
.settings-breadcrumb a {
    color: var(--adm-muted);
    text-decoration: none;
    transition: color 0.2s;
}
.settings-breadcrumb a:hover {
    color: var(--adm-brand);
}
.settings-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.65rem;
    font-weight: 800;
    color: var(--adm-text-main);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.settings-subtitle {
    font-size: 0.88rem;
    color: var(--adm-muted);
    margin: 4px 0 0 0;
}
.settings-nav-tabs {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* Button SaaS Styling */
.btn-saas {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    font-size: 0.86rem;
    font-weight: 700;
    border-radius: var(--adm-radius-md);
    cursor: pointer;
    border: 1px solid transparent;
    text-decoration: none;
    transition: all var(--adm-transition);
    font-family: inherit;
    white-space: nowrap;
}
.btn-saas-primary {
    background: linear-gradient(135deg, var(--adm-brand), #4338ca);
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);
}
.btn-saas-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
    color: #ffffff;
}
.btn-saas-secondary {
    background: var(--adm-surface-subtle);
    border-color: var(--adm-border);
    color: var(--adm-text-main);
}
.btn-saas-secondary:hover {
    background: var(--adm-surface);
    border-color: var(--adm-brand);
    color: var(--adm-brand);
}
.btn-saas-danger {
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
    border-color: rgba(239, 68, 68, 0.3);
}
.btn-saas-danger:hover {
    background: #dc2626;
    color: #ffffff;
}

/* 4 Enterprise KPI Metric Cards */
.settings-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
}
.kpi-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: var(--adm-shadow-card);
    transition: all var(--adm-transition);
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--adm-shadow-hover);
    border-color: var(--adm-border-hover);
}
.kpi-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: var(--adm-radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.kpi-icon-indigo { background: rgba(79, 70, 229, 0.12); color: #4f46e5; }
.kpi-icon-emerald { background: rgba(16, 185, 129, 0.12); color: #10b981; }
.kpi-icon-purple { background: rgba(139, 92, 246, 0.12); color: #8b5cf6; }
.kpi-icon-amber  { background: rgba(245, 158, 11, 0.12); color: #f59e0b; }
.kpi-info { min-width: 0; flex: 1; }
.kpi-label {
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--adm-muted);
    margin-bottom: 4px;
}
.kpi-value {
    font-family: 'Outfit', sans-serif;
    font-size: 1.28rem;
    font-weight: 800;
    color: var(--adm-text-main);
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.kpi-sub {
    font-size: 0.76rem;
    font-weight: 600;
    color: var(--adm-muted);
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* 2-Column Responsive Workspace Grid */
.settings-workspace-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(min(100%, 320px), 0.95fr);
    gap: 24px;
    align-items: start;
    min-width: 0;
    max-width: 100%;
}
.settings-workspace-layout > section,
.settings-workspace-layout > aside {
    min-width: 0;
    max-width: 100%;
}

/* Cards & Panel Layout */
.settings-card-panel {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    overflow: hidden;
    box-shadow: var(--adm-shadow-card);
    margin-bottom: 24px;
}
.settings-card-panel:last-child {
    margin-bottom: 0;
}
.panel-header-banner {
    padding: 18px 24px;
    background: var(--adm-surface-subtle);
    border-bottom: 1px solid var(--adm-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.panel-header-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--adm-text-main);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.panel-header-subtitle {
    font-size: 0.8rem;
    color: var(--adm-muted);
    margin: 3px 0 0 0;
}
.panel-body-content {
    padding: 24px;
}

/* Modern Form Controls with Prefix Icons */
.form-group-item {
    margin-bottom: 18px;
}
.form-group-item label {
    display: block;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--adm-text-main);
    margin-bottom: 6px;
}
.input-with-icon-wrap {
    position: relative;
    display: flex;
    align-items: center;
}
.input-prefix-icon {
    position: absolute;
    left: 14px;
    color: var(--adm-muted);
    font-size: 0.88rem;
    pointer-events: none;
}
.form-control-saas {
    width: 100%;
    padding: 11px 14px 11px 40px;
    font-size: 0.88rem;
    border-radius: var(--adm-radius-md);
    border: 1px solid var(--adm-border);
    background: var(--adm-surface-subtle);
    color: var(--adm-text-main);
    font-family: inherit;
    transition: all var(--adm-transition);
}
.form-control-saas:focus {
    outline: none;
    border-color: var(--adm-brand);
    background: var(--adm-surface);
    box-shadow: 0 0 0 3px var(--adm-brand-light);
}
.form-control-saas[readonly] {
    opacity: 0.85;
    background: var(--adm-border-subtle);
    cursor: default;
}
.password-toggle-btn {
    position: absolute;
    right: 12px;
    background: transparent;
    border: none;
    color: var(--adm-muted);
    cursor: pointer;
    font-size: 0.88rem;
    padding: 4px;
    transition: color 0.2s;
}
.password-toggle-btn:hover {
    color: var(--adm-brand);
}

/* Password Strength Meter & Interactive Checklist */
.pwd-meter-box {
    margin: 10px 0 16px 0;
    padding: 12px 14px;
    background: var(--adm-surface-subtle);
    border: 1px solid var(--adm-border-subtle);
    border-radius: var(--adm-radius-md);
}
.pwd-meter-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
    font-size: 0.76rem;
    font-weight: 700;
}
.pwd-meter-track {
    height: 6px;
    background: var(--adm-border);
    border-radius: 999px;
    overflow: hidden;
    margin-bottom: 10px;
}
.pwd-meter-bar {
    height: 100%;
    width: 0%;
    border-radius: 999px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    background: #ef4444;
}
.pwd-rule-list {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    font-size: 0.74rem;
    margin: 0;
    padding: 0;
    list-style: none;
}
.pwd-rule-item {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--adm-muted);
    font-weight: 600;
    transition: color 0.2s;
}
.pwd-rule-item.rule-valid {
    color: #059669;
}
.pwd-rule-item.rule-valid i {
    color: #059669;
}

/* Action Footer */
.panel-footer-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-top: 10px;
    border-top: 1px solid var(--adm-border-subtle);
    margin-top: 18px;
}

/* Mini Buttons */
.mini-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    font-size: 0.76rem;
    font-weight: 700;
    border-radius: var(--adm-radius-sm);
    border: 1px solid var(--adm-border);
    background: var(--adm-surface);
    color: var(--adm-text-main);
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    font-family: inherit;
    white-space: nowrap;
}
.mini-btn:hover {
    border-color: var(--adm-brand);
    color: var(--adm-brand);
    background: var(--adm-brand-light);
}
.mini-btn-del {
    color: #dc2626;
    border-color: rgba(239, 68, 68, 0.25);
    background: rgba(239, 68, 68, 0.05);
}
.mini-btn-del:hover {
    border-color: #dc2626;
    color: #ffffff;
    background: #dc2626;
}

/* Active Sessions List Styling */
.session-list-wrap {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.session-item-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 14px 16px;
    background: var(--adm-surface-subtle);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    transition: all var(--adm-transition);
}
.session-item-row:hover {
    border-color: var(--adm-brand);
    box-shadow: var(--adm-shadow-sm);
}
.session-item-row.session-item-current {
    border-color: rgba(16, 185, 129, 0.4);
    background: rgba(16, 185, 129, 0.03);
}
.session-device-icon-box {
    width: 42px;
    height: 42px;
    border-radius: var(--adm-radius-md);
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    color: var(--adm-brand);
    flex-shrink: 0;
}
.session-meta-info {
    flex: 1;
    min-width: 0;
}
.session-meta-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: var(--adm-text-main);
    margin-bottom: 3px;
}
.session-meta-sub {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.76rem;
    color: var(--adm-muted);
    flex-wrap: wrap;
}
.session-action-wrap {
    flex-shrink: 0;
}

/* Right Column Telemetry Lists */
.telemetry-dl {
    margin: 0;
    display: flex;
    flex-direction: column;
}
.telemetry-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--adm-border-subtle);
    font-size: 0.82rem;
}
.telemetry-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
.telemetry-row:first-child {
    padding-top: 0;
}
.telemetry-label {
    color: var(--adm-muted);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.telemetry-val {
    font-weight: 700;
    color: var(--adm-text-main);
    text-align: right;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 0.8rem;
}

/* Badges */
.badge-saas {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 9px;
    border-radius: var(--adm-radius-sm);
    font-size: 0.74rem;
    font-weight: 700;
}
.badge-clean { background: rgba(16, 185, 129, 0.12); color: #059669; }
.badge-info  { background: rgba(59, 130, 246, 0.12); color: #2563eb; }
.badge-purple{ background: rgba(139, 92, 246, 0.12); color: #7c3aed; }
.badge-danger{ background: rgba(239, 68, 68, 0.12); color: #dc2626; }

/* Alert Feedback */
.alert-feedback {
    padding: 14px 18px;
    border-radius: var(--adm-radius-lg);
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.88rem;
    font-weight: 600;
    box-shadow: var(--adm-shadow-sm);
}
.alert-feedback-success {
    background: rgba(16, 185, 129, 0.12);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: #059669;
}
.alert-feedback-error {
    background: rgba(239, 68, 68, 0.12);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #dc2626;
}

@media (max-width: 1050px) {
    .settings-workspace-layout { grid-template-columns: 1fr; }
    .settings-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .settings-kpi-grid { grid-template-columns: 1fr; }
    .settings-hero-header { flex-direction: column; align-items: stretch; padding: 16px 18px; }
    .settings-nav-tabs { width: 100%; }
    .settings-nav-tabs .btn-saas { flex: 1; }
    .pwd-rule-list { grid-template-columns: 1fr; }
    .panel-header-banner { padding: 14px 16px; flex-direction: column; align-items: flex-start; gap: 10px; }
    .panel-body-content { padding: 16px; }
}
@media (max-width: 480px) {
    .settings-page-wrapper { gap: 16px; }
    .kpi-card { padding: 14px 16px; }
    .kpi-value { font-size: 1.15rem; }
    .panel-body-content { padding: 14px 12px; }
    .btn-saas { width: 100%; justify-content: center; }
}

.settings-form-row-2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.settings-form-row-2col > * {
    min-width: 0;
}
@media (max-width: 580px) {
    .settings-form-row-2col {
        grid-template-columns: 1fr;
        gap: 12px;
    }
}
</style>

<div class="settings-page-wrapper">

    <!-- ─── Hero Header & Contextual Tab Navigation ──────────────────────────── -->
    <div class="settings-hero-header">
        <div>
            <nav class="settings-breadcrumb" aria-label="Breadcrumb">
                <a href="/admin/dashboard"><i class="fa-solid fa-house"></i> Admin</a>
                <span><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem;"></i></span>
                <span>Console Preferences</span>
                <span><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem;"></i></span>
                <span>Account &amp; Security</span>
            </nav>
            <h1 class="settings-title">
                <i class="fa-solid fa-user-shield" style="color: var(--adm-brand);"></i>
                <span>Account Credentials &amp; Security</span>
            </h1>
            <p class="settings-subtitle">
                Manage your administrative credentials, strong password rotation, and server runtime environment.
            </p>
        </div>

        <div class="settings-nav-tabs">
            <a href="settings" class="btn-saas btn-saas-primary" title="Current: Account and Security Settings">
                <i class="fa-solid fa-user-gear"></i>
                <span>Account &amp; Security</span>
            </a>
            <a href="site_settings" class="btn-saas btn-saas-secondary" title="Navigate to White-Label Platform Branding &amp; SMTP Infrastructure">
                <i class="fa-solid fa-sliders"></i>
                <span>Branding &amp; SMTP</span>
            </a>
        </div>
    </div>

    <!-- ─── Flash Feedback Notification ─────────────────────────────────────── -->
    <?php if (!empty($flashMessage)): ?>
        <div class="alert-feedback alert-feedback-<?php echo ($flashType === 'success') ? 'success' : 'error'; ?>" role="alert">
            <i class="fa-solid <?php echo ($flashType === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>" style="font-size: 1.1rem;"></i>
            <span><?php echo htmlspecialchars($flashMessage); ?></span>
        </div>
    <?php endif; ?>

    <!-- ─── 4 Enterprise KPI Stat Metric Cards ────────────────────────────────── -->
    <div class="settings-kpi-grid">
        <!-- Card 1: Active Identity -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-indigo">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">Account Identity</div>
                <div class="kpi-value" title="<?php echo htmlspecialchars($adminData['username']); ?>"><?php echo htmlspecialchars($adminData['username']); ?></div>
                <div class="kpi-sub">
                    <span class="badge-saas badge-clean" style="font-size: 0.7rem;"><i class="fa-solid fa-crown"></i> Super Administrator</span>
                </div>
            </div>
        </div>

        <!-- Card 2: Security Posture -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-emerald">
                <i class="fa-solid fa-lock"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">Security Posture</div>
                <div class="kpi-value">Protected</div>
                <div class="kpi-sub">
                    <span><i class="fa-solid fa-check" style="color: #059669;"></i> Bcrypt Policy Enforced</span>
                </div>
            </div>
        </div>

        <!-- Card 3: Active Session -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-purple">
                <i class="fa-solid fa-network-wired"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">Session Telemetry</div>
                <div class="kpi-value" title="<?php echo htmlspecialchars($clientIp); ?>"><?php echo htmlspecialchars($clientIp); ?></div>
                <div class="kpi-sub">
                    <span>30m Idle Timeout &bull; SameSite</span>
                </div>
            </div>
        </div>

        <!-- Card 4: Platform Engine -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-amber">
                <i class="fa-solid fa-server"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">Platform Engine</div>
                <div class="kpi-value">PHP <?php echo htmlspecialchars(explode('-', $phpVersion)[0]); ?></div>
                <div class="kpi-sub">
                    <span title="<?php echo htmlspecialchars($dbVersion); ?>"><?php echo htmlspecialchars(explode('-', $dbVersion)[0]); ?> DB</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── 2-Column Responsive Workspace Grid ───────────────────────────────── -->
    <div class="settings-workspace-layout">

        <!-- Column 1: Profile & Password Management Forms -->
        <section>

            <!-- Card 1: Administrator Profile -->
            <div class="settings-card-panel">
                <div class="panel-header-banner">
                    <div>
                        <h2 class="panel-header-title">
                            <i class="fa-solid fa-id-badge" style="color: var(--adm-brand);"></i>
                            <span>Administrator Profile Information</span>
                        </h2>
                        <p class="panel-header-subtitle">Update your console display name and primary administrative contact coordinates.</p>
                    </div>
                    <span class="badge-saas badge-info">
                        <i class="fa-solid fa-user-check"></i> Primary Admin
                    </span>
                </div>

                <form method="POST" action="" class="panel-body-content" id="profileForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfTokenProfile); ?>">

                    <div class="form-group-item">
                        <label for="profile_username">Administrator Username *</label>
                        <div class="input-with-icon-wrap">
                            <i class="fa-solid fa-user input-prefix-icon"></i>
                            <input type="text" id="profile_username" name="username" class="form-control-saas" value="<?php echo htmlspecialchars($adminData['username']); ?>" required minlength="3" maxlength="50" autocomplete="username">
                        </div>
                    </div>

                    <div class="form-group-item">
                        <label for="profile_email">Administrative Notification Email</label>
                        <div class="input-with-icon-wrap">
                            <i class="fa-solid fa-envelope input-prefix-icon"></i>
                            <input type="email" id="profile_email" name="email" class="form-control-saas" value="<?php echo htmlspecialchars($adminData['email'] ?? ''); ?>" placeholder="admin@cloudaspire.in" maxlength="100" autocomplete="email">
                        </div>
                        <small style="font-size: 0.75rem; color: var(--adm-muted); margin-top: 5px; display: block;">
                            Receives system notifications, login dispatch alerts, and workforce anomaly reports.
                        </small>
                    </div>

                    <div class="form-group-item">
                        <label>Assigned Authorization Role</label>
                        <div class="input-with-icon-wrap">
                            <i class="fa-solid fa-shield-halved input-prefix-icon"></i>
                            <input type="text" class="form-control-saas" value="Super Administrator (Full System Root Access)" readonly tabindex="-1">
                        </div>
                    </div>

                    <div class="panel-footer-actions">
                        <button type="submit" name="update_profile" class="btn-saas btn-saas-primary">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <span>Save Profile Changes</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card 2: Security & Password Management -->
            <div class="settings-card-panel">
                <div class="panel-header-banner">
                    <div>
                        <h2 class="panel-header-title">
                            <i class="fa-solid fa-key" style="color: #7c3aed;"></i>
                            <span>Security Credentials &amp; Password Rotation</span>
                        </h2>
                        <p class="panel-header-subtitle">Enforce strong authentication standards to safeguard administrative privileges.</p>
                    </div>
                    <span class="badge-saas badge-purple">
                        <i class="fa-solid fa-fingerprint"></i> High Security
                    </span>
                </div>

                <form method="POST" action="" class="panel-body-content" id="passwordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfTokenPassword); ?>">

                    <!-- Current Password -->
                    <div class="form-group-item">
                        <label for="current_password">Current Password *</label>
                        <div class="input-with-icon-wrap">
                            <i class="fa-solid fa-lock-open input-prefix-icon"></i>
                            <input type="password" id="current_password" name="current_password" class="form-control-saas" placeholder="Enter existing password" required autocomplete="current-password">
                            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('current_password', 'eye_curr')" title="Toggle visibility" aria-label="Toggle current password visibility">
                                <i class="fa-solid fa-eye" id="eye_curr"></i>
                            </button>
                        </div>
                    </div>

                    <!-- New Password -->
                    <div class="form-group-item">
                        <label for="new_password">New Password *</label>
                        <div class="input-with-icon-wrap">
                            <i class="fa-solid fa-lock input-prefix-icon"></i>
                            <input type="password" id="new_password" name="new_password" class="form-control-saas" placeholder="Enter new password (min. 8 characters)" required minlength="8" autocomplete="new-password" oninput="evaluatePasswordStrength(this.value)">
                            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('new_password', 'eye_new')" title="Toggle visibility" aria-label="Toggle new password visibility">
                                <i class="fa-solid fa-eye" id="eye_new"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Live Password Strength Meter & Interactive Rules -->
                    <div class="pwd-meter-box">
                        <div class="pwd-meter-header">
                            <span style="color: var(--adm-muted);">Password Strength:</span>
                            <span id="strengthLabel" style="color: #ef4444;">Too Short</span>
                        </div>
                        <div class="pwd-meter-track">
                            <div class="pwd-meter-bar" id="strengthBar"></div>
                        </div>

                        <ul class="pwd-rule-list">
                            <li class="pwd-rule-item" id="ruleLen">
                                <i class="fa-solid fa-circle-xmark"></i> 8+ characters
                            </li>
                            <li class="pwd-rule-item" id="ruleUpper">
                                <i class="fa-solid fa-circle-xmark"></i> Uppercase letter (A-Z)
                            </li>
                            <li class="pwd-rule-item" id="ruleLower">
                                <i class="fa-solid fa-circle-xmark"></i> Lowercase letter (a-z)
                            </li>
                            <li class="pwd-rule-item" id="ruleNum">
                                <i class="fa-solid fa-circle-xmark"></i> Number / digit (0-9)
                            </li>
                        </ul>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="form-group-item">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label for="confirm_password" style="margin: 0;">Confirm New Password *</label>
                            <span id="matchFeedback" style="font-size: 0.74rem; font-weight: 700; color: var(--adm-muted);"></span>
                        </div>
                        <div class="input-with-icon-wrap">
                            <i class="fa-solid fa-shield-check input-prefix-icon"></i>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control-saas" placeholder="Confirm your new password" required minlength="8" autocomplete="new-password" oninput="verifyPasswordMatch()">
                            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('confirm_password', 'eye_conf')" title="Toggle visibility" aria-label="Toggle confirm password visibility">
                                <i class="fa-solid fa-eye" id="eye_conf"></i>
                            </button>
                        </div>
                    </div>

                    <div class="panel-footer-actions">
                        <button type="submit" name="change_password" class="btn-saas btn-saas-primary">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span>Update Security Password</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card: Passkeys & Biometric Authentication (WebAuthn / FIDO2) -->
            <div class="settings-card-panel" style="margin-bottom: 24px;">
                <div class="panel-header-banner" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                    <div>
                        <h2 class="panel-header-title">
                            <i class="fa-solid fa-fingerprint" style="color: var(--adm-brand);"></i>
                            <span>Admin Passkeys &amp; Biometric Security</span>
                        </h2>
                        <p class="panel-header-subtitle">Use Windows Hello, Touch ID, Face ID, or hardware security keys to access the admin console.</p>
                    </div>
                    <button type="button" class="btn-saas btn-saas-primary" id="openAdminAddPkBtn" style="padding: 7px 14px; font-size: 0.8rem;">
                        <i class="fa-solid fa-plus"></i>
                        <span>Add Admin Passkey</span>
                    </button>
                </div>

                <div class="panel-body-content" style="padding: 18px 24px;">
                    <div id="adminPasskeysTableContainer">
                        <div style="text-align: center; padding: 24px; color: var(--adm-muted);">
                            <i class="fa-solid fa-spinner fa-spin" style="font-size: 1.3rem; color: var(--adm-brand); margin-bottom: 8px;"></i>
                            <div>Loading administrative passkeys...</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card: Office Geolocation & Attendance Boundaries -->
            <div class="settings-card-panel" style="margin-bottom: 24px;">
                <div class="panel-header-banner" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                    <div>
                        <h2 class="panel-header-title">
                            <i class="fa-solid fa-location-dot" style="color: #059669;"></i>
                            <span>Office Location &amp; Attendance Geofencing</span>
                        </h2>
                        <p class="panel-header-subtitle">Define physical office boundaries and configure GPS geofence restrictions for on-site employee attendance.</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <a href="office_location" class="mini-btn" style="color: var(--adm-brand); border-color: var(--adm-brand); font-weight: 700; text-decoration: none;">
                            <i class="fa-solid fa-map-location-dot"></i> Interactive Map
                        </a>
                        <?php if (!empty($officeLocationSettings['location_attendance_enabled'])): ?>
                            <span class="badge-saas badge-clean" style="color: #059669; border-color: rgba(5,150,105,0.3); background: rgba(5,150,105,0.1);">
                                <i class="fa-solid fa-circle-check"></i> Geofencing Active
                            </span>
                        <?php else: ?>
                            <span class="badge-saas badge-clean" style="color: #d97706; border-color: rgba(217,119,6,0.3); background: rgba(217,119,6,0.1);">
                                <i class="fa-solid fa-circle-pause"></i> Geofencing Disabled
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST" action="" class="panel-body-content" id="officeLocationForm" style="padding: 24px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfTokenOfficeLocation); ?>">

                    <?php $canManageSettings = Security::hasPermission('manage_settings', $adminId); ?>
                    <?php if (!$canManageSettings): ?>
                        <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #dc2626; padding: 12px 16px; border-radius: var(--adm-radius-md); font-size: 0.86rem; font-weight: 600; margin-bottom: 18px;">
                            <i class="fa-solid fa-lock"></i> You have read-only access. Only authorized administrators with the <code>manage_settings</code> permission can update office geofencing parameters.
                        </div>
                    <?php endif; ?>

                    <div class="form-group-item">
                        <label for="office_name">Office Location Name *</label>
                        <div class="input-with-icon-wrap">
                            <i class="fa-solid fa-building input-prefix-icon"></i>
                            <input type="text" id="office_name" name="office_name" class="form-control-saas" 
                                value="<?php echo htmlspecialchars($officeLocationSettings['office_name'] ?? 'Cloud Aspire Headquarters'); ?>" 
                                required maxlength="150" <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                                placeholder="e.g. Cloud Aspire Headquarters - Main Campus">
                        </div>
                    </div>

                    <div class="form-group-item">
                        <label for="office_address">Physical Address (Optional)</label>
                        <div class="input-with-icon-wrap">
                            <i class="fa-solid fa-map-pin input-prefix-icon"></i>
                            <input type="text" id="office_address" name="office_address" class="form-control-saas" 
                                value="<?php echo htmlspecialchars($officeLocationSettings['office_address'] ?? ''); ?>" 
                                maxlength="255" <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                                placeholder="e.g. 123 Tech Boulevard, Puducherry, India">
                        </div>
                    </div>

                    <div class="settings-form-row-2col">
                        <div class="form-group-item" style="margin-bottom: 0;">
                            <label for="office_latitude">Office Latitude *</label>
                            <div class="input-with-icon-wrap">
                                <i class="fa-solid fa-compass input-prefix-icon"></i>
                                <input type="number" step="any" min="-90" max="90" id="office_latitude" name="office_latitude" class="form-control-saas" 
                                    value="<?php echo htmlspecialchars((string)($officeLocationSettings['office_latitude'] ?? '11.94160000')); ?>" 
                                    required <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                                    placeholder="e.g. 11.94160000">
                            </div>
                        </div>

                        <div class="form-group-item" style="margin-bottom: 0;">
                            <label for="office_longitude">Office Longitude *</label>
                            <div class="input-with-icon-wrap">
                                <i class="fa-solid fa-compass input-prefix-icon"></i>
                                <input type="number" step="any" min="-180" max="180" id="office_longitude" name="office_longitude" class="form-control-saas" 
                                    value="<?php echo htmlspecialchars((string)($officeLocationSettings['office_longitude'] ?? '79.80830000')); ?>" 
                                    required <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                                    placeholder="e.g. 79.80830000">
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; padding: 12px 14px; background: var(--adm-surface-subtle); border: 1px solid var(--adm-border-subtle); border-radius: var(--adm-radius-md);">
                        <div style="font-size: 0.82rem; color: var(--adm-muted);">
                            <i class="fa-solid fa-satellite-dish" style="color: var(--adm-brand); margin-right: 4px;"></i>
                            Auto-capture coordinates or verify location on satellite map:
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <?php if ($canManageSettings): ?>
                                <button type="button" class="mini-btn" id="btnDetectGps" onclick="detectAdminCoordinates()" title="Use browser GPS to fill coordinates with your current position">
                                    <i class="fa-solid fa-crosshairs"></i> Detect My GPS
                                </button>
                            <?php endif; ?>
                            <a id="btnMapPreview" href="https://www.google.com/maps?q=<?php echo urlencode($officeLocationSettings['office_latitude'] . ',' . $officeLocationSettings['office_longitude']); ?>" target="_blank" rel="noopener noreferrer" class="mini-btn" title="Open configured coordinates in Google Maps">
                                <i class="fa-solid fa-map-location-dot"></i> View on Maps
                            </a>
                        </div>
                    </div>

                    <div class="settings-form-row-2col" style="margin-bottom: 20px;">
                        <div class="form-group-item" style="margin-bottom: 0;">
                            <label for="office_radius_meters">Allowed Attendance Radius (Meters) *</label>
                            <div class="input-with-icon-wrap">
                                <i class="fa-solid fa-circle-dot input-prefix-icon"></i>
                                <input type="number" min="10" max="50000" id="office_radius_meters" name="office_radius_meters" class="form-control-saas" 
                                    value="<?php echo htmlspecialchars((string)($officeLocationSettings['office_radius_meters'] ?? '100')); ?>" 
                                    required <?php echo !$canManageSettings ? 'readonly' : ''; ?>
                                    placeholder="e.g. 100">
                            </div>
                            <small style="font-size: 0.75rem; color: var(--adm-muted); margin-top: 4px; display: block;">
                                Configurable default: 100 meters (maximum distance allowed from office center).
                            </small>
                        </div>

                        <div class="form-group-item" style="margin-bottom: 0;">
                            <label style="margin-bottom: 8px; display: block;">Enforce Location Restrictions</label>
                            <label style="display: flex; align-items: center; gap: 10px; cursor: <?php echo $canManageSettings ? 'pointer' : 'not-allowed'; ?>; padding: 10px 14px; background: var(--adm-surface-subtle); border: 1.5px solid var(--adm-border-subtle); border-radius: var(--adm-radius-md); font-size: 0.88rem; font-weight: 600; color: var(--adm-text-main);">
                                <input type="checkbox" name="location_attendance_enabled" value="1" <?php echo !empty($officeLocationSettings['location_attendance_enabled']) ? 'checked' : ''; ?> <?php echo !$canManageSettings ? 'disabled' : ''; ?> style="width: 18px; height: 18px; accent-color: var(--adm-brand);">
                                <span>Enable Location-Based Attendance</span>
                            </label>
                            <small style="font-size: 0.75rem; color: var(--adm-muted); margin-top: 4px; display: block;">
                                When enabled, on-site employees must check in within the allowed radius. Remote (WFH) employees bypass geofencing.
                            </small>
                        </div>
                    </div>

                    <?php if ($canManageSettings): ?>
                        <div class="panel-footer-actions">
                            <button type="submit" name="update_office_location" class="btn-saas btn-saas-primary">
                                <i class="fa-solid fa-floppy-disk"></i>
                                <span>Save Office Geofencing Settings</span>
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Card 3: Active Authorized Sessions & Device Access -->
            <div class="settings-card-panel">
                <div class="panel-header-banner">
                    <div>
                        <h2 class="panel-header-title">
                            <i class="fa-solid fa-laptop-code" style="color: var(--adm-brand);"></i>
                            <span>Active Authorized Sessions</span>
                        </h2>
                        <p class="panel-header-subtitle">Review active devices and immediately revoke unauthorized or stale logins.</p>
                    </div>

                    <div style="display: flex; align-items: center; gap: 8px;">
                        <?php if ($activeSessionsCount > 1): ?>
                            <form method="POST" action="" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfTokenTerminateAll); ?>">
                                <button type="submit" name="terminate_all_others" class="mini-btn mini-btn-del" onclick="return confirm('Terminate all other active sessions? Other devices will be logged out immediately.');" title="Revoke all devices except this one">
                                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Terminate Other Sessions
                                </button>
                            </form>
                        <?php endif; ?>

                        <span class="badge-saas badge-clean" style="font-size: 0.74rem;">
                            <i class="fa-solid fa-signal"></i> <?php echo $activeSessionsCount; ?> Active Device<?php echo ($activeSessionsCount === 1) ? '' : 's'; ?>
                        </span>
                    </div>
                </div>

                <div class="panel-body-content">
                    <?php if (empty($activeSessions)): ?>
                        <div style="text-align: center; padding: 24px; color: var(--adm-muted);">
                            <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 1.4rem; margin-bottom: 8px; display: block;"></i>
                            Synchronizing active session device...
                        </div>
                    <?php else: ?>
                        <div class="session-list-wrap">
                            <?php 
                            $currentSid = session_id();
                            foreach ($activeSessions as $sess): 
                                $isCurrent = ($sess['session_id'] === $currentSid);
                                $devType = strtolower($sess['device_type'] ?? 'desktop');
                                $devIcon = 'fa-desktop';
                                if ($devType === 'mobile') $devIcon = 'fa-mobile-screen';
                                elseif ($devType === 'tablet') $devIcon = 'fa-tablet-screen-button';
                                
                                $timeAgo = 'Active Now';
                                $actTime = strtotime($sess['last_activity']);
                                $diff = time() - $actTime;
                                if ($diff > 60) {
                                    $mins = floor($diff / 60);
                                    if ($mins < 60) {
                                        $timeAgo = $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
                                    } else {
                                        $hrs = floor($mins / 60);
                                        $timeAgo = $hrs . ' hr' . ($hrs > 1 ? 's' : '') . ' ago';
                                    }
                                }
                            ?>
                                <div class="session-item-row <?php echo $isCurrent ? 'session-item-current' : ''; ?>">
                                    <div class="session-device-icon-box">
                                        <i class="fa-solid <?php echo $devIcon; ?>"></i>
                                    </div>

                                    <div class="session-meta-info">
                                        <div class="session-meta-title">
                                            <strong><?php echo htmlspecialchars($sess['browser'] ?: 'Browser'); ?> on <?php echo htmlspecialchars($sess['platform'] ?: 'OS'); ?></strong>
                                            <?php if ($isCurrent): ?>
                                                <span class="badge-saas badge-clean" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-circle-dot"></i> This Device (Current)
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="session-meta-sub">
                                            <span><i class="fa-solid fa-network-wired" style="font-size: 0.72rem;"></i> <?php echo htmlspecialchars($sess['ip_address']); ?></span>
                                            <span>&bull;</span>
                                            <span><i class="fa-solid fa-clock" style="font-size: 0.72rem;"></i> <?php echo $isCurrent ? '<span style="color: #059669; font-weight: 700;">Active Now</span>' : htmlspecialchars($timeAgo); ?></span>
                                            <span>&bull;</span>
                                            <span style="font-size: 0.72rem; color: var(--adm-muted);">Logged in <?php echo date('M j, g:i A', strtotime($sess['created_at'])); ?></span>
                                        </div>
                                    </div>

                                    <div class="session-action-wrap">
                                        <?php if (!$isCurrent): ?>
                                            <form method="POST" action="" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfTokenTerminate); ?>">
                                                <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($sess['session_id']); ?>">
                                                <button type="submit" name="terminate_session" class="mini-btn mini-btn-del" onclick="return confirm('Terminate this session? The device will be signed out immediately.');" title="Revoke this device session immediately">
                                                    <i class="fa-solid fa-ban"></i> Terminate
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <a href="logout" class="mini-btn" style="color: var(--adm-muted);" onclick="return confirm('Sign out of your current session on this device?');" title="Sign out of this device">
                                                <i class="fa-solid fa-right-from-bracket"></i> Sign Out
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Simulated session test helper for verification -->
                    <div style="margin-top: 16px; padding-top: 14px; border-top: 1px dashed var(--adm-border-subtle); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <span style="font-size: 0.76rem; color: var(--adm-muted);">
                            <i class="fa-solid fa-shield-halved" style="color: var(--adm-brand); margin-right: 4px;"></i>
                            Sessions expire after 30 minutes of inactivity or upon remote termination.
                        </span>
                        <form method="POST" action="" style="margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfTokenSimulate); ?>">
                            <button type="submit" name="simulate_session" class="mini-btn" style="font-size: 0.74rem;" title="Simulate an authorized mobile device session for testing termination">
                                <i class="fa-solid fa-plus"></i> Simulate Mobile Session (Test)
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </section>

        <!-- Column 2: System Environment Telemetry & Security Shield -->
        <aside>

            <!-- Card 3: Platform Telemetry & Environment Specs -->
            <div class="settings-card-panel">
                <div class="panel-header-banner">
                    <div>
                        <h3 class="panel-header-title">
                            <i class="fa-solid fa-microchip" style="color: var(--adm-brand);"></i>
                            <span>Platform Environment Telemetry</span>
                        </h3>
                        <p class="panel-header-subtitle">Server infrastructure and runtime execution profile.</p>
                    </div>
                </div>

                <div class="panel-body-content">
                    <dl class="telemetry-dl">
                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-brands fa-php" style="color: #8892bf; font-size: 1rem;"></i> PHP Engine</dt>
                            <dd class="telemetry-val"><?php echo htmlspecialchars($phpVersion); ?></dd>
                        </div>

                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-solid fa-database" style="color: #f59e0b;"></i> Database Server</dt>
                            <dd class="telemetry-val" title="<?php echo htmlspecialchars($dbVersion); ?>"><?php echo htmlspecialchars(explode('-', $dbVersion)[0]); ?> MariaDB</dd>
                        </div>

                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-solid fa-server" style="color: var(--adm-brand);"></i> Web Server</dt>
                            <dd class="telemetry-val"><?php echo htmlspecialchars(explode(' ', $serverSoftware)[0]); ?></dd>
                        </div>

                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-solid fa-memory" style="color: #10b981;"></i> Memory Allocation</dt>
                            <dd class="telemetry-val"><?php echo htmlspecialchars($memoryLimit); ?></dd>
                        </div>

                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-solid fa-file-arrow-up" style="color: #06b6d4;"></i> Upload Max Filesize</dt>
                            <dd class="telemetry-val"><?php echo htmlspecialchars($uploadMax); ?></dd>
                        </div>

                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-solid fa-clock" style="color: #8b5cf6;"></i> System Timezone</dt>
                            <dd class="telemetry-val"><?php echo htmlspecialchars($currentTimezone); ?></dd>
                        </div>

                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-solid fa-calendar-check" style="color: #64748b;"></i> Admin Registration</dt>
                            <dd class="telemetry-val"><?php echo htmlspecialchars($memberSince); ?></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Card 4: Security Shield & Policy Audit -->
            <div class="settings-card-panel">
                <div class="panel-header-banner">
                    <div>
                        <h3 class="panel-header-title">
                            <i class="fa-solid fa-shield-heart" style="color: #059669;"></i>
                            <span>Security Defense &amp; Audit Status</span>
                        </h3>
                        <p class="panel-header-subtitle">Active protections safeguarding console operations.</p>
                    </div>
                </div>

                <div class="panel-body-content">
                    <dl class="telemetry-dl">
                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-solid fa-shield-virus" style="color: #059669;"></i> CSRF Mitigation</dt>
                            <dd><span class="badge-saas badge-clean"><i class="fa-solid fa-check"></i> Per-Form Tokens</span></dd>
                        </div>

                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-solid fa-cookie-bite" style="color: #059669;"></i> Cookie Hardening</dt>
                            <dd><span class="badge-saas badge-clean"><i class="fa-solid fa-check"></i> HttpOnly / Lax</span></dd>
                        </div>

                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-solid fa-user-clock" style="color: #059669;"></i> Session Inactivity</dt>
                            <dd><span class="badge-saas badge-clean">30 Min Idle Auto-Exp</span></dd>
                        </div>

                        <div class="telemetry-row">
                            <dt class="telemetry-label"><i class="fa-solid fa-sliders" style="color: var(--adm-brand);"></i> Platform Branding</dt>
                            <dd><a href="site_settings" style="color: var(--adm-brand); font-weight: 700; text-decoration: none; font-size: 0.8rem;"><i class="fa-solid fa-arrow-up-right-from-square"></i> Configure Brand</a></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Card 5: Danger Zone / Session Termination -->
            <div class="settings-card-panel" style="border-color: rgba(239, 68, 68, 0.3);">
                <div class="panel-header-banner" style="background: rgba(239, 68, 68, 0.04);">
                    <div>
                        <h3 class="panel-header-title" style="color: #dc2626;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>Session Termination</span>
                        </h3>
                        <p class="panel-header-subtitle">End active authorization and destroy the current session token.</p>
                    </div>
                </div>

                <div class="panel-body-content">
                    <p style="font-size: 0.82rem; color: var(--adm-muted); margin-bottom: 16px;">
                        Logging out terminates your authenticated session cookie and clears all administrative authorization privileges on this device.
                    </p>

                    <a href="logout" class="btn-saas btn-saas-danger" style="width: 100%;" onclick="return confirm('Are you sure you wish to terminate your administrative session and log out?');">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span>Terminate Session &amp; Logout</span>
                    </a>
                </div>
            </div>

        </aside>
    </div>
</div>

<!-- ─── Interactive Client-Side JavaScript ───────────────────────────────────── -->
<script>
// 1. Password Visibility Toggle
function togglePasswordVisibility(fieldId, iconId) {
    const input = document.getElementById(fieldId);
    const icon = document.getElementById(iconId);
    if (!input || !icon) return;

    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// 2. Real-Time Password Strength Evaluator
function evaluatePasswordStrength(pwd) {
    const bar = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    const rLen = document.getElementById('ruleLen');
    const rUpper = document.getElementById('ruleUpper');
    const rLower = document.getElementById('ruleLower');
    const rNum = document.getElementById('ruleNum');

    if (!bar || !label) return;

    // Rule tests
    const hasLen = pwd.length >= 8;
    const hasUpper = /[A-Z]/.test(pwd);
    const hasLower = /[a-z]/.test(pwd);
    const hasNum = /[0-9]/.test(pwd);
    const hasSpecial = /[^A-Za-z0-9]/.test(pwd);

    updateRuleItem(rLen, hasLen);
    updateRuleItem(rUpper, hasUpper);
    updateRuleItem(rLower, hasLower);
    updateRuleItem(rNum, hasNum);

    let score = 0;
    if (hasLen) score += 25;
    if (hasUpper) score += 25;
    if (hasLower) score += 25;
    if (hasNum) score += 25;
    if (hasSpecial && score === 100) score = 100;

    bar.style.width = score + '%';

    if (score < 50) {
        bar.style.background = '#ef4444';
        label.style.color = '#ef4444';
        label.innerText = 'Weak (Needs Requirements)';
    } else if (score < 75) {
        bar.style.background = '#f59e0b';
        label.style.color = '#f59e0b';
        label.innerText = 'Moderate (Add More Variety)';
    } else if (score < 100) {
        bar.style.background = '#3b82f6';
        label.style.color = '#3b82f6';
        label.innerText = 'Good (Almost Secure)';
    } else {
        bar.style.background = '#10b981';
        label.style.color = '#10b981';
        label.innerText = 'Enterprise Secure';
    }

    verifyPasswordMatch();
}

function updateRuleItem(el, isValid) {
    if (!el) return;
    const icon = el.querySelector('i');
    if (isValid) {
        el.classList.add('rule-valid');
        if (icon) {
            icon.classList.remove('fa-circle-xmark');
            icon.classList.add('fa-circle-check');
        }
    } else {
        el.classList.remove('rule-valid');
        if (icon) {
            icon.classList.remove('fa-circle-check');
            icon.classList.add('fa-circle-xmark');
        }
    }
}

// 3. Confirm Password Match Checker
function verifyPasswordMatch() {
    const np = document.getElementById('new_password');
    const cp = document.getElementById('confirm_password');
    const matchEl = document.getElementById('matchFeedback');
    if (!np || !cp || !matchEl) return;

    if (!cp.value) {
        matchEl.innerText = '';
        return;
    }

    if (np.value === cp.value) {
        matchEl.style.color = '#059669';
        matchEl.innerHTML = '<i class="fa-solid fa-check"></i> Passwords Match';
    } else {
        matchEl.style.color = '#dc2626';
        matchEl.innerHTML = '<i class="fa-solid fa-xmark"></i> Passwords Do Not Match';
    }
}
</script>

<!-- Admin Passkey Management Modals -->
<!-- 1. Add Passkey Modal -->
<div class="modal-overlay" id="adminAddPkModal" role="dialog" aria-modal="true" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center;">
    <div class="modal-card" style="background: var(--adm-surface); border: 1px solid var(--adm-border); border-radius: var(--adm-radius-lg); max-width: 480px; width: 90%; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 1.15rem; color: var(--adm-text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-fingerprint" style="color: var(--adm-brand);"></i> Add Admin Passkey
            </h3>
            <button type="button" class="mini-btn" onclick="closeAdminPkModal('adminAddPkModal');" style="border: none; background: transparent; font-size: 1.1rem; color: var(--adm-muted); cursor: pointer;">&times;</button>
        </div>
        <div style="margin-bottom: 20px; font-size: 0.88rem; color: var(--adm-text-main);">
            <p style="margin-bottom: 12px; color: var(--adm-muted);">Register your biometric sensor (Windows Hello, Touch ID, Face ID) or hardware key to securely sign into the admin portal.</p>
            <div class="form-group-item">
                <label for="adminPkDeviceName">Device / Passkey Label</label>
                <input type="text" id="adminPkDeviceName" class="form-control-saas" placeholder="e.g. Work MacBook (Touch ID)">
            </div>
            <div id="adminAddPkStatus" style="display: none; padding: 10px 14px; border-radius: var(--adm-radius-md); font-size: 0.84rem; margin-top: 10px;"></div>
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="mini-btn" onclick="closeAdminPkModal('adminAddPkModal');">Cancel</button>
            <button type="button" class="btn-saas btn-saas-primary" id="startAdminPkRegisterBtn" style="padding: 8px 16px;">
                <i class="fa-solid fa-key"></i> Start Biometric Prompt
            </button>
        </div>
    </div>
</div>

<!-- 2. Rename Passkey Modal -->
<div class="modal-overlay" id="adminRenamePkModal" role="dialog" aria-modal="true" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center;">
    <div class="modal-card" style="background: var(--adm-surface); border: 1px solid var(--adm-border); border-radius: var(--adm-radius-lg); max-width: 440px; width: 90%; padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 1.1rem; color: var(--adm-text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-pen-to-square" style="color: var(--adm-brand);"></i> Rename Passkey
            </h3>
            <button type="button" class="mini-btn" onclick="closeAdminPkModal('adminRenamePkModal');" style="border: none; background: transparent; font-size: 1.1rem; color: var(--adm-muted); cursor: pointer;">&times;</button>
        </div>
        <div style="margin-bottom: 20px;">
            <input type="hidden" id="adminRenamePkId">
            <div class="form-group-item">
                <label for="adminRenamePkName">Device Name</label>
                <input type="text" id="adminRenamePkName" class="form-control-saas" required>
            </div>
            <div id="adminRenamePkStatus" style="display: none; padding: 10px 14px; border-radius: var(--adm-radius-md); font-size: 0.84rem;"></div>
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="mini-btn" onclick="closeAdminPkModal('adminRenamePkModal');">Cancel</button>
            <button type="button" class="btn-saas btn-saas-primary" id="saveAdminPkRenameBtn" style="padding: 8px 16px;">Save Name</button>
        </div>
    </div>
</div>

<!-- 3. Remove Passkey Modal -->
<div class="modal-overlay" id="adminRemovePkModal" role="dialog" aria-modal="true" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center;">
    <div class="modal-card" style="background: var(--adm-surface); border: 1px solid var(--adm-border); border-radius: var(--adm-radius-lg); max-width: 460px; width: 90%; padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 1.1rem; color: #dc2626; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-triangle-exclamation"></i> Remove Passkey
            </h3>
            <button type="button" class="mini-btn" onclick="closeAdminPkModal('adminRemovePkModal');" style="border: none; background: transparent; font-size: 1.1rem; color: var(--adm-muted); cursor: pointer;">&times;</button>
        </div>
        <div style="margin-bottom: 20px; font-size: 0.88rem; color: var(--adm-text-main);">
            <input type="hidden" id="adminRemovePkId">
            <p style="margin-bottom: 12px;">Are you sure you want to remove <strong id="adminRemovePkNameDisplay"></strong> from your administrator account?</p>
            <div id="adminLastPkWarn" style="display: none; background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #dc2626; padding: 10px 12px; border-radius: var(--adm-radius-md); margin-bottom: 12px; font-size: 0.82rem;">
                <i class="fa-solid fa-shield-exclamation"></i> <strong>This is your last registered passkey.</strong> You will still be able to log in using your password.
            </div>
            <div class="form-group-item">
                <label for="adminRemovePkPassword">Re-enter Admin Password to Confirm *</label>
                <input type="password" id="adminRemovePkPassword" class="form-control-saas" placeholder="Current administrator password" required>
            </div>
            <div id="adminRemovePkStatus" style="display: none; padding: 10px 14px; border-radius: var(--adm-radius-md); font-size: 0.84rem;"></div>
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 10px;">
            <button type="button" class="mini-btn" onclick="closeAdminPkModal('adminRemovePkModal');">Cancel</button>
            <button type="button" class="mini-btn mini-btn-del" id="confirmAdminPkDeleteBtn" style="padding: 8px 16px;">Confirm &amp; Remove</button>
        </div>
    </div>
</div>

<!-- Passkey JS Library & Admin Controller -->
<script src="/assets/js/passkey.js"></script>
<script>
    let cachedAdminPasskeys = [];

    function closeAdminPkModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }
    function openAdminPkModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'flex';
    }

    async function loadAdminPasskeys() {
        const container = document.getElementById('adminPasskeysTableContainer');
        if (!container) return;

        try {
            const passkeys = await Passkey.listPasskeys();
            cachedAdminPasskeys = passkeys;

            if (!passkeys || passkeys.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 30px 16px; background: var(--adm-surface-subtle); border: 1.5px dashed var(--adm-border); border-radius: var(--adm-radius-md);">
                        <i class="fa-solid fa-fingerprint" style="font-size: 2rem; color: var(--adm-muted); margin-bottom: 10px; display: block;"></i>
                        <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--adm-text-main); margin-bottom: 4px;">No Admin Passkeys Registered</h4>
                        <p style="font-size: 0.82rem; color: var(--adm-muted); max-width: 380px; margin: 0 auto 14px;">
                            Enable 1-touch cryptographic authentication for your administrator workspace.
                        </p>
                        <button type="button" class="btn-saas btn-saas-primary" onclick="document.getElementById('openAdminAddPkBtn').click();" style="padding: 7px 16px; font-size: 0.82rem;">
                            <i class="fa-solid fa-plus"></i> Register First Passkey
                        </button>
                    </div>
                `;
                return;
            }

            let html = `
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.84rem; text-align: left;">
                        <thead>
                            <tr style="border-bottom: 1.5px solid var(--adm-border); color: var(--adm-muted); font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.05em;">
                                <th style="padding: 10px 12px;">Passkey</th>
                                <th style="padding: 10px 12px;">Device</th>
                                <th style="padding: 10px 12px;">Created</th>
                                <th style="padding: 10px 12px;">Last Used</th>
                                <th style="padding: 10px 12px;">Status</th>
                                <th style="padding: 10px 12px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            passkeys.forEach(pk => {
                html += `
                    <tr style="border-bottom: 1px solid var(--adm-border-subtle); transition: background 0.15s;">
                        <td style="padding: 12px; font-weight: 700; color: var(--adm-text-main);">
                            <i class="${pk.icon || 'fa-solid fa-key'}" style="margin-right: 8px; color: var(--adm-brand);"></i>
                            ${escapeAdminHtml(pk.device_name)}
                        </td>
                        <td style="padding: 12px; color: var(--adm-muted);">${escapeAdminHtml(pk.device_name)}</td>
                        <td style="padding: 12px; color: var(--adm-muted);">${pk.created_at}</td>
                        <td style="padding: 12px; color: var(--adm-muted);">${pk.last_used_at}</td>
                        <td style="padding: 12px;">
                            <span class="badge-saas badge-clean" style="font-size: 0.72rem;">
                                <i class="fa-solid fa-circle-check"></i> Active
                            </span>
                        </td>
                        <td style="padding: 12px; text-align: right;">
                            <button type="button" class="mini-btn" onclick="openAdminRename(${pk.id}, '${escapeAdminJs(pk.device_name)}');" title="Rename" style="margin-right: 4px;">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <button type="button" class="mini-btn mini-btn-del" onclick="openAdminDelete(${pk.id}, '${escapeAdminJs(pk.device_name)}');" title="Remove">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            container.innerHTML = html;

        } catch (e) {
            container.innerHTML = `<div style="padding: 14px; background: rgba(239,68,68,0.1); color: #dc2626; border-radius: var(--adm-radius-md); font-size: 0.84rem;">Failed to load passkeys: ${escapeAdminHtml(e.message)}</div>`;
        }
    }

    function escapeAdminHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escapeAdminJs(str) {
        if (!str) return '';
        return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
    }

    // Open Add Modal
    document.getElementById('openAdminAddPkBtn')?.addEventListener('click', () => {
        const inp = document.getElementById('adminPkDeviceName');
        if (inp) inp.value = Passkey.detectDeviceName();
        const stat = document.getElementById('adminAddPkStatus');
        if (stat) stat.style.display = 'none';
        openAdminPkModal('adminAddPkModal');
    });

    // Start Register
    document.getElementById('startAdminPkRegisterBtn')?.addEventListener('click', async function() {
        const btn = this;
        const nameInp = document.getElementById('adminPkDeviceName');
        const stat = document.getElementById('adminAddPkStatus');
        const devName = nameInp ? nameInp.value.trim() : '';

        stat.style.display = 'block';
        stat.style.background = 'var(--adm-brand-light)';
        stat.style.color = 'var(--adm-brand)';
        stat.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Triggering biometric handshake...';
        btn.disabled = true;

        try {
            const res = await Passkey.register(devName);
            stat.style.background = 'rgba(16,185,129,0.15)';
            stat.style.color = '#059669';
            stat.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + (res.message || 'Passkey registered!');

            setTimeout(() => {
                closeAdminPkModal('adminAddPkModal');
                loadAdminPasskeys();
                btn.disabled = false;
            }, 900);

        } catch (err) {
            stat.style.background = 'rgba(239,68,68,0.15)';
            stat.style.color = '#dc2626';
            stat.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeAdminHtml(err.message);
            btn.disabled = false;
        }
    });

    // Rename
    window.openAdminRename = function(id, name) {
        document.getElementById('adminRenamePkId').value = id;
        document.getElementById('adminRenamePkName').value = name;
        document.getElementById('adminRenamePkStatus').style.display = 'none';
        openAdminPkModal('adminRenamePkModal');
    };

    document.getElementById('saveAdminPkRenameBtn')?.addEventListener('click', async function() {
        const id = document.getElementById('adminRenamePkId').value;
        const newName = document.getElementById('adminRenamePkName').value.trim();
        const stat = document.getElementById('adminRenamePkStatus');
        if (!newName) return;

        this.disabled = true;
        try {
            await Passkey.renamePasskey(id, newName);
            closeAdminPkModal('adminRenamePkModal');
            loadAdminPasskeys();
        } catch (e) {
            stat.style.display = 'block';
            stat.style.background = 'rgba(239,68,68,0.15)';
            stat.style.color = '#dc2626';
            stat.innerHTML = escapeAdminHtml(e.message);
        }
        this.disabled = false;
    });

    // Delete
    window.openAdminDelete = function(id, name) {
        document.getElementById('adminRemovePkId').value = id;
        document.getElementById('adminRemovePkNameDisplay').textContent = name;
        document.getElementById('adminRemovePkPassword').value = '';
        document.getElementById('adminRemovePkStatus').style.display = 'none';

        const lastWarn = document.getElementById('adminLastPkWarn');
        if (lastWarn) {
            lastWarn.style.display = (cachedAdminPasskeys.length <= 1) ? 'block' : 'none';
        }

        openAdminPkModal('adminRemovePkModal');
    };

    document.getElementById('confirmAdminPkDeleteBtn')?.addEventListener('click', async function() {
        const id = document.getElementById('adminRemovePkId').value;
        const pwd = document.getElementById('adminRemovePkPassword').value;
        const stat = document.getElementById('adminRemovePkStatus');

        if (!pwd) {
            stat.style.display = 'block';
            stat.style.background = 'rgba(239,68,68,0.15)';
            stat.style.color = '#dc2626';
            stat.innerHTML = 'Password required to confirm.';
            return;
        }

        this.disabled = true;
        try {
            await Passkey.removePasskey(id, pwd);
            closeAdminPkModal('adminRemovePkModal');
            loadAdminPasskeys();
        } catch (e) {
            stat.style.display = 'block';
            stat.style.background = 'rgba(239,68,68,0.15)';
            stat.style.color = '#dc2626';
            stat.innerHTML = escapeAdminHtml(e.message);
        }
        this.disabled = false;
    });

    document.addEventListener('DOMContentLoaded', () => {
        loadAdminPasskeys();
    });

    // Detect browser coordinates for Office Geofencing setup
    function detectAdminCoordinates() {
        const btn = document.getElementById('btnDetectGps');
        if (!navigator.geolocation) {
            alert("Geolocation is not supported by your browser.");
            return;
        }
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Detecting...';
        }

        navigator.geolocation.getCurrentPosition(
            function(position) {
                const lat = position.coords.latitude.toFixed(8);
                const lng = position.coords.longitude.toFixed(8);
                document.getElementById('office_latitude').value = lat;
                document.getElementById('office_longitude').value = lng;
                const mapLink = document.getElementById('btnMapPreview');
                if (mapLink) {
                    mapLink.href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
                }
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> GPS Filled!';
                    setTimeout(() => {
                        btn.innerHTML = '<i class="fa-solid fa-crosshairs"></i> Detect My GPS';
                    }, 3000);
                }
            },
            function(err) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-crosshairs"></i> Detect My GPS';
                }
                alert("Unable to detect coordinates: " + err.message);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    }
</script>

<?php include 'footer.php'; ?>