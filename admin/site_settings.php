<?php
// ============================================================================
// Enterprise White-Label SaaS Site Branding & SMTP Diagnostic Console
// ============================================================================

// Set timezone
date_default_timezone_set('Asia/Kolkata');

// Include database connection & security
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/Mailer.php';

Security::init($pdo);
Security::initSecureSession();

// Check if admin is logged in
if (!Security::isAuthenticated()) {
    header('Location: ../admin');
    exit();
}

$adminId = $_SESSION['admin_id'] ?? null;
Security::requirePermission('settings.view', $adminId, '../admin/dashboard');

$canManage = Security::hasPermission('settings.manage', $adminId);

$pageTitle = 'Site Branding & Infrastructure';
$currentPage = 'site_settings';

$uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'site';
$uploadUrl = '/assets/uploads/site/';
$allowedEncryption = ['none', 'tls', 'ssl'];

// Site settings schema is maintained via migrations

// Helper Functions
function uploadSiteImage($field, $type, $uploadDir) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(ucfirst($type) . ' upload failed.');
    }
    if ($_FILES[$field]['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException(ucfirst($type) . ' must be smaller than 2 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $types = ($type === 'favicon')
        ? ['image/png' => 'png', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico']
        : ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    if (!isset($types[$mime])) {
        throw new RuntimeException(
            ($type === 'favicon')
                ? 'Favicon must be a PNG or ICO image.'
                : 'Logo must be a PNG, JPG, or WebP image.'
        );
    }
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Could not create site uploads directory.');
    }

    $filename = $type . '-' . bin2hex(random_bytes(12)) . '.' . $types[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException(ucfirst($type) . ' could not be saved to disk.');
    }
    return $filename;
}

function removeSiteImage($filename, $uploadDir) {
    if (!$filename) return;
    $safeName = basename($filename);
    $path = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
    if (is_file($path)) {
        @unlink($path);
    }
}

function protectSmtpPassword($plainText) {
    if ($plainText === '') return null;
    if (!function_exists('openssl_encrypt')) {
        return 'plain:' . base64_encode($plainText);
    }
    $keyMaterial = $_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: dirname(__DIR__);
    $key = hash('sha256', $keyMaterial, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('SMTP password encryption failed.');
    return 'gcm:' . base64_encode($iv . $tag . $cipher);
}

function unprotectSmtpPassword($stored) {
    if (empty($stored)) return '';
    if (strncmp($stored, 'plain:', 6) === 0) {
        return base64_decode(substr($stored, 6));
    }
    if (strncmp($stored, 'gcm:', 4) === 0) {
        $raw = base64_decode(substr($stored, 4));
        if (strlen($raw) < 28) return '';
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $keyMaterial = $_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: dirname(__DIR__);
        $key = hash('sha256', $keyMaterial, true);
        return openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag) ?: '';
    }
    return $stored;
}

// ----------------------------------------------------------------------------
// Process POST Actions (Save, Delete, Set Active, Clone, Test SMTP) with PRG
// ----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'site_settings')) {
        $_SESSION['site_settings_flash'] = 'Invalid or expired security token. Please refresh and try again.';
        $_SESSION['site_settings_flash_type'] = 'error';
        header('Location: site_settings');
        exit();
    }

    if (!$canManage) {
        $_SESSION['site_settings_flash'] = 'Access Denied: You do not possess authorization to modify system settings.';
        $_SESSION['site_settings_flash_type'] = 'error';
        header('Location: site_settings');
        exit();
    }

    // 1. Delete Configuration (With Active Profile Safeguard)
    if (isset($_POST['delete_settings'])) {
        $id = (int)($_POST['settings_id'] ?? 0);
        try {
            $stmt = $pdo->prepare('SELECT id, site_name, logo, favicon, is_active FROM site_settings WHERE id = ?');
            $stmt->execute([$id]);
            $record = $stmt->fetch();

            if (!$record) {
                $_SESSION['site_settings_flash'] = 'Site settings profile not found.';
                $_SESSION['site_settings_flash_type'] = 'error';
            } elseif ((int)$record['is_active'] === 1) {
                $_SESSION['site_settings_flash'] = 'The active brand configuration cannot be deleted! Please activate an alternate configuration first.';
                $_SESSION['site_settings_flash_type'] = 'error';
            } else {
                $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM site_settings")->fetchColumn();
                if ($totalCount <= 1) {
                    $_SESSION['site_settings_flash'] = 'Cannot delete the sole remaining site configuration.';
                    $_SESSION['site_settings_flash_type'] = 'error';
                } else {
                    $delStmt = $pdo->prepare('DELETE FROM site_settings WHERE id = ?');
                    $delStmt->execute([$id]);
                    removeSiteImage($record['logo'], $uploadDir);
                    removeSiteImage($record['favicon'], $uploadDir);

                    Security::logAudit($adminId, 'delete_settings', 'site_settings', $id, ['site_name' => $record['site_name']]);

                    $_SESSION['site_settings_flash'] = "Configuration profile '{$record['site_name']}' deleted successfully.";
                    $_SESSION['site_settings_flash_type'] = 'success';
                }
            }
        } catch (PDOException $e) {
            error_log('Delete site settings: ' . $e->getMessage());
            $_SESSION['site_settings_flash'] = 'Database error while deleting configuration.';
            $_SESSION['site_settings_flash_type'] = 'error';
        }
        header('Location: site_settings');
        exit();
    }

    // 2. Set Profile as Active
    elseif (isset($_POST['set_active'])) {
        $id = (int)($_POST['settings_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $pdo->exec('UPDATE site_settings SET is_active = 0');
            $stmt = $pdo->prepare('UPDATE site_settings SET is_active = 1 WHERE id = ?');
            $stmt->execute([$id]);
            $pdo->commit();

            Security::logAudit($adminId, 'activate_settings', 'site_settings', $id, ['is_active' => 1]);

            $_SESSION['site_settings_flash'] = 'Active brand configuration updated successfully.';
            $_SESSION['site_settings_flash_type'] = 'success';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Set active site settings: ' . $e->getMessage());
            $_SESSION['site_settings_flash'] = 'Error activating site configuration.';
            $_SESSION['site_settings_flash_type'] = 'error';
        }
        header('Location: site_settings?edit=' . $id);
        exit();
    }

    // 3. Clone Profile Action
    elseif (isset($_POST['clone_settings'])) {
        $id = (int)($_POST['settings_id'] ?? 0);
        try {
            $stmt = $pdo->prepare('SELECT * FROM site_settings WHERE id = ?');
            $stmt->execute([$id]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($source) {
                $newName = $source['site_name'] . ' (Copy)';
                $ins = $pdo->prepare('INSERT INTO site_settings (site_name, tagline, brand_color, support_email, support_phone, logo, favicon, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)');
                $ins->execute([
                    $newName,
                    $source['tagline'] ?? null,
                    $source['brand_color'] ?? '#4f46e5',
                    $source['support_email'] ?? null,
                    $source['support_phone'] ?? null,
                    $source['logo'] ?? null,
                    $source['favicon'] ?? null,
                    $source['smtp_host'] ?? null,
                    $source['smtp_port'] ?? 587,
                    $source['smtp_encryption'] ?? 'tls',
                    $source['smtp_username'] ?? null,
                    $source['smtp_password'] ?? null,
                    $source['smtp_from_email'] ?? null,
                    $source['smtp_from_name'] ?? null
                ]);
                $clonedId = (int)$pdo->lastInsertId();

                Security::logAudit($adminId, 'clone_settings', 'site_settings', $clonedId, ['source_id' => $id, 'name' => $newName]);

                $_SESSION['site_settings_flash'] = "Configuration duplicated as '{$newName}'.";
                $_SESSION['site_settings_flash_type'] = 'success';
                header('Location: site_settings?edit=' . $clonedId);
                exit();
            }
        } catch (PDOException $e) {
            error_log('Clone settings error: ' . $e->getMessage());
            $_SESSION['site_settings_flash'] = 'Error duplicating configuration.';
            $_SESSION['site_settings_flash_type'] = 'error';
        }
        header('Location: site_settings');
        exit();
    }

    // 4. Test SMTP Connection
    elseif (isset($_POST['test_smtp'])) {
        $testEmail = trim($_POST['test_email'] ?? '');
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        $smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';
        $smtpUsername = trim($_POST['smtp_username'] ?? '');
        $smtpPassword = (string)($_POST['smtp_password'] ?? '');
        $fromEmail = trim($_POST['smtp_from_email'] ?? '');
        $fromName = trim($_POST['smtp_from_name'] ?? '');
        $currentId = (int)($_POST['settings_id'] ?? 0);

        // If password is blank and editing, pull existing decrypted password
        if ($smtpPassword === '' && $currentId > 0) {
            $stmt = $pdo->prepare('SELECT smtp_password FROM site_settings WHERE id = ?');
            $stmt->execute([$currentId]);
            $stored = $stmt->fetchColumn();
            $smtpPassword = unprotectSmtpPassword($stored);
        }

        $sec = Mailer::normalizeSecurity($smtpPort, $smtpEncryption);
        $smtpEncryption = $sec['encryption'];

        $mailer = new Mailer($pdo);
        $result = $mailer->testSmtpConnection($testEmail, [
            'host'       => $smtpHost,
            'port'       => $smtpPort,
            'encryption' => $smtpEncryption,
            'username'   => $smtpUsername,
            'password'   => $smtpPassword,
            'from_email' => $fromEmail,
            'from_name'  => $fromName
        ]);

        $_SESSION['site_settings_flash'] = $result['message'];
        $_SESSION['site_settings_flash_type'] = $result['success'] ? 'success' : 'error';
        if (!empty($result['debug'])) {
            $_SESSION['smtp_debug_log'] = $result['debug'];
        }

        Security::logAudit($adminId, 'test_smtp', 'site_settings', $currentId, ['recipient' => $testEmail, 'success' => $result['success']]);

        $redirectUrl = 'site_settings' . ($currentId > 0 ? '?edit=' . $currentId : '');
        header('Location: ' . $redirectUrl);
        exit();
    }

    // 5. Save or Update Configuration
    elseif (isset($_POST['save_settings'])) {
        $id = (int)($_POST['settings_id'] ?? 0);
        $siteName = trim($_POST['site_name'] ?? '');
        $tagline = trim($_POST['tagline'] ?? '');
        $brandColor = trim($_POST['brand_color'] ?? '#4f46e5');
        if (!preg_match('/^#[a-f0-9]{6}$/i', $brandColor)) $brandColor = '#4f46e5';

        $supportEmail = trim($_POST['support_email'] ?? '');
        $supportPhone = trim($_POST['support_phone'] ?? '');

        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        $rawEncryption = $_POST['smtp_encryption'] ?? 'tls';
        $sec = Mailer::normalizeSecurity($smtpPort, $rawEncryption);
        $smtpEncryption = $sec['encryption'];
        $smtpUsername = trim($_POST['smtp_username'] ?? '');
        $smtpPassword = (string)($_POST['smtp_password'] ?? '');
        $fromEmail = trim($_POST['smtp_from_email'] ?? '');
        $fromName = trim($_POST['smtp_from_name'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $newLogo = $newFavicon = null;

        if (strlen($siteName) < 2 || strlen($siteName) > 120) {
            $_SESSION['site_settings_flash'] = 'Site name must be between 2 and 120 characters.';
            $_SESSION['site_settings_flash_type'] = 'error';
        } elseif ($smtpPort < 1 || $smtpPort > 65535) {
            $_SESSION['site_settings_flash'] = 'SMTP port must be between 1 and 65535.';
            $_SESSION['site_settings_flash_type'] = 'error';
        } elseif (!in_array($smtpEncryption, $allowedEncryption, true)) {
            $_SESSION['site_settings_flash'] = 'Select a valid SMTP encryption mode.';
            $_SESSION['site_settings_flash_type'] = 'error';
        } elseif ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['site_settings_flash'] = 'Enter a valid SMTP sender email.';
            $_SESSION['site_settings_flash_type'] = 'error';
        } else {
            try {
                $old = ['logo' => null, 'favicon' => null, 'smtp_password' => null];
                if ($id) {
                    $stmt = $pdo->prepare('SELECT logo, favicon, smtp_password FROM site_settings WHERE id = ?');
                    $stmt->execute([$id]);
                    $old = $stmt->fetch();
                    if (!$old) throw new RuntimeException('Settings record not found.');
                }

                $newLogo = uploadSiteImage('logo', 'logo', $uploadDir);
                $newFavicon = uploadSiteImage('favicon', 'favicon', $uploadDir);
                $logo = $newLogo ?: ($old['logo'] ?? null);
                $favicon = $newFavicon ?: ($old['favicon'] ?? null);
                $storedPassword = ($smtpPassword !== '') ? protectSmtpPassword($smtpPassword) : ($old['smtp_password'] ?? null);

                $pdo->beginTransaction();
                if ($isActive) {
                    $pdo->exec('UPDATE site_settings SET is_active = 0');
                }

                if ($id) {
                    $stmt = $pdo->prepare('UPDATE site_settings SET
                        site_name=?, tagline=?, brand_color=?, support_email=?, support_phone=?,
                        logo=?, favicon=?, smtp_host=?, smtp_port=?, smtp_encryption=?,
                        smtp_username=?, smtp_password=?, smtp_from_email=?, smtp_from_name=?, is_active=?
                        WHERE id=?');
                    $stmt->execute([
                        $siteName, $tagline ?: null, $brandColor, $supportEmail ?: null, $supportPhone ?: null,
                        $logo, $favicon, $smtpHost ?: null, $smtpPort, $smtpEncryption,
                        $smtpUsername ?: null, $storedPassword, $fromEmail ?: null, $fromName ?: null, $isActive, $id
                    ]);
                    $savedId = $id;
                    $flashMsg = "Site settings for '{$siteName}' updated successfully.";
                } else {
                    $stmt = $pdo->prepare('INSERT INTO site_settings
                        (site_name, tagline, brand_color, support_email, support_phone,
                         logo, favicon, smtp_host, smtp_port, smtp_encryption,
                         smtp_username, smtp_password, smtp_from_email, smtp_from_name, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([
                        $siteName, $tagline ?: null, $brandColor, $supportEmail ?: null, $supportPhone ?: null,
                        $logo, $favicon, $smtpHost ?: null, $smtpPort, $smtpEncryption,
                        $smtpUsername ?: null, $storedPassword, $fromEmail ?: null, $fromName ?: null, $isActive
                    ]);
                    $savedId = (int)$pdo->lastInsertId();
                    $flashMsg = "New site profile '{$siteName}' created successfully.";
                }

                $pdo->commit();

                Security::logAudit($adminId, $id ? 'update_settings' : 'create_settings', 'site_settings', $savedId, ['site_name' => $siteName, 'is_active' => $isActive]);

                // Clean up replaced images
                if ($newLogo && !empty($old['logo'])) removeSiteImage($old['logo'], $uploadDir);
                if ($newFavicon && !empty($old['favicon'])) removeSiteImage($old['favicon'], $uploadDir);

                $_SESSION['site_settings_flash'] = $flashMsg;
                $_SESSION['site_settings_flash_type'] = 'success';
                header('Location: site_settings?edit=' . $savedId);
                exit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($newLogo) removeSiteImage($newLogo, $uploadDir);
                if ($newFavicon) removeSiteImage($newFavicon, $uploadDir);
                error_log('Save site settings: ' . $e->getMessage());
                $_SESSION['site_settings_flash'] = ($e instanceof RuntimeException) ? $e->getMessage() : 'Error saving site settings.';
                $_SESSION['site_settings_flash_type'] = 'error';
            }
        }
        header('Location: site_settings' . ($id ? '?edit=' . $id : ''));
        exit();
    }
}

// ----------------------------------------------------------------------------
// Retrieve Flash Messages & Debug Logs
// ----------------------------------------------------------------------------
$flashMessage = $_SESSION['site_settings_flash'] ?? '';
$flashType = $_SESSION['site_settings_flash_type'] ?? '';
$smtpDebugLog = $_SESSION['smtp_debug_log'] ?? '';
unset($_SESSION['site_settings_flash'], $_SESSION['site_settings_flash_type'], $_SESSION['smtp_debug_log']);

// ----------------------------------------------------------------------------
// Load Record for Editing & All Configurations List
// ----------------------------------------------------------------------------
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM site_settings WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
} elseif (!isset($_GET['new'])) {
    // Open active record by default
    $stmt = $pdo->query('SELECT * FROM site_settings ORDER BY is_active DESC, updated_at DESC LIMIT 1');
    $editing = $stmt->fetch() ?: null;
}

try {
    $settingsList = $pdo->query('SELECT * FROM site_settings ORDER BY is_active DESC, updated_at DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $settingsList = [];
}

// Top Metric Analytics
$totalProfilesCount = count($settingsList);
$activeConfig = null;
foreach ($settingsList as $s) {
    if ($s['is_active']) {
        $activeConfig = $s;
        break;
    }
}
$activeName = $activeConfig ? $activeConfig['site_name'] : 'Cloud Aspire';
$activeLogo = $activeConfig && !empty($activeConfig['logo']) ? $uploadUrl . rawurlencode($activeConfig['logo']) : null;
$activeFavicon = $activeConfig && !empty($activeConfig['favicon']) ? $uploadUrl . rawurlencode($activeConfig['favicon']) : null;
$activeSmtpHost = $activeConfig && !empty($activeConfig['smtp_host']) ? $activeConfig['smtp_host'] : 'Not Configured';

$csrfToken = Security::generateCSRFToken('site_settings');
include 'header.php';
?>

<!-- ─── White-Label SaaS Site Settings Styling ─────────────────────────────── -->
<style>
/* Scoped Enterprise Layout */
.site-settings-container {
    display: flex;
    flex-direction: column;
    gap: 22px;
}

/* Page Header */
.settings-hero-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
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
    font-size: 1.85rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--adm-text-main);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.settings-subtitle {
    font-size: 0.92rem;
    color: var(--adm-muted);
    margin-top: 4px;
}
.settings-actions-group {
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
    color: #ffffff;
}
.btn-saas-secondary {
    background: var(--adm-surface);
    color: var(--adm-text-main);
    border-color: var(--adm-border);
}
.btn-saas-secondary:hover {
    background: var(--adm-surface-subtle);
    border-color: var(--adm-brand);
    color: var(--adm-brand);
}
.btn-saas-test {
    background: var(--adm-brand-light);
    color: var(--adm-brand);
    border-color: var(--adm-brand-border);
}
.btn-saas-test:hover {
    background: var(--adm-brand);
    color: #ffffff;
    transform: translateY(-1px);
}

/* 4 Enterprise KPI Cards */
.settings-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
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
    transition: transform var(--adm-transition), box-shadow var(--adm-transition);
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
.kpi-icon-purple { background: rgba(147, 51, 234, 0.12); color: #9333ea; }
.kpi-icon-amber { background: rgba(217, 119, 6, 0.12); color: #d97706; }

.kpi-info { min-width: 0; flex: 1; }
.kpi-label {
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--adm-muted);
    margin-bottom: 4px;
}
.kpi-value {
    font-family: 'Outfit', sans-serif;
    font-size: 1.35rem;
    font-weight: 800;
    color: var(--adm-text-main);
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.kpi-sub {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--adm-muted);
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Main 2-Column Responsive Workspace Grid */
.settings-workspace-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.65fr) minmax(340px, 0.95fr);
    gap: 24px;
    align-items: start;
}

/* Editor Panel & Cards */
.settings-main-card, .records-side-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    overflow: hidden;
    box-shadow: var(--adm-shadow-card);
}
.card-header-banner {
    padding: 20px 24px;
    background: var(--adm-surface-subtle);
    border-bottom: 1px solid var(--adm-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.card-header-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.2rem;
    font-weight: 800;
    color: var(--adm-text-main);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.card-header-subtitle {
    font-size: 0.82rem;
    color: var(--adm-muted);
    margin-top: 3px;
}

/* Form Sections */
.settings-form-body {
    padding: 26px;
}
.form-section-divider {
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--adm-brand);
    margin: 22px 0 14px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--adm-border-subtle);
}
.form-section-divider:first-child {
    margin-top: 0;
}
.form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.form-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
.form-group-item {
    margin-bottom: 16px;
}
.form-group-item.full-width {
    grid-column: 1 / -1;
}
.form-group-item label {
    display: block;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--adm-text-main);
    margin-bottom: 6px;
}
.form-input-control {
    width: 100%;
    padding: 10px 14px;
    font-size: 0.88rem;
    border-radius: var(--adm-radius-md);
    border: 1px solid var(--adm-border);
    background: var(--adm-surface-subtle);
    color: var(--adm-text-main);
    font-family: inherit;
    transition: all var(--adm-transition);
}
.form-input-control:focus {
    outline: none;
    border-color: var(--adm-brand);
    background: var(--adm-surface);
    box-shadow: 0 0 0 3px var(--adm-brand-light);
}

/* File Upload Dropzone Boxes */
.upload-dropzone-box {
    border: 1px dashed var(--adm-border);
    border-radius: var(--adm-radius-lg);
    padding: 14px;
    background: var(--adm-surface-subtle);
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: border-color 0.2s;
}
.upload-dropzone-box:hover {
    border-color: var(--adm-brand);
}
.dropzone-file-input {
    font-size: 0.82rem;
    color: var(--adm-muted);
}
.dropzone-hint {
    font-size: 0.72rem;
    color: var(--adm-muted);
}

/* Image Previews */
.preview-cluster-box {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 14px 18px;
    background: var(--adm-surface-subtle);
    border-radius: var(--adm-radius-lg);
    border: 1px solid var(--adm-border);
    margin-top: 10px;
}
.preview-item-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.preview-item-label {
    font-size: 0.74rem;
    font-weight: 700;
    color: var(--adm-muted);
    text-transform: uppercase;
}
.logo-display-img {
    height: 44px;
    max-width: 170px;
    object-fit: contain;
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-md);
    padding: 4px 8px;
}
.favicon-display-img {
    width: 38px;
    height: 38px;
    object-fit: contain;
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-md);
    padding: 4px;
}

/* Active Profile Toggle Card */
.active-profile-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px;
    background: var(--adm-surface-subtle);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    cursor: pointer;
    margin: 18px 0;
}
.active-profile-card input[type="checkbox"] {
    width: 20px;
    height: 20px;
    accent-color: var(--adm-brand);
    cursor: pointer;
}
.active-profile-info strong {
    display: block;
    font-size: 0.88rem;
    color: var(--adm-text-main);
}
.active-profile-info small {
    display: block;
    font-size: 0.78rem;
    color: var(--adm-muted);
    margin-top: 2px;
}

/* Form Action Buttons */
.form-footer-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 24px;
    padding-top: 18px;
    border-top: 1px solid var(--adm-border-subtle);
    flex-wrap: wrap;
    gap: 12px;
}

/* ─── Right Column: Interactive Brand Identity Mockup Preview ─────────────── */
.brand-mockup-card {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    padding: 20px;
    margin-bottom: 22px;
    box-shadow: var(--adm-shadow-sm);
}
.mockup-header-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--adm-text-main);
    margin: 0 0 14px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.mockup-navbar-preview {
    height: 52px;
    background: var(--adm-nav-bg);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    padding: 0 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: var(--adm-shadow-sm);
}
.mockup-brand-item {
    display: flex;
    align-items: center;
    gap: 10px;
}
.mockup-avatar-mock {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #4f46e5;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    font-weight: 800;
}

/* ─── Right Column: Saved Profiles Cards ───────────────────────────────────── */
.saved-profile-item {
    padding: 16px;
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-lg);
    margin-bottom: 12px;
    background: var(--adm-surface);
    transition: all var(--adm-transition);
}
.saved-profile-item:hover {
    border-color: var(--adm-brand);
    box-shadow: var(--adm-shadow-sm);
}
.saved-profile-item.is-active-profile {
    border-color: #059669;
    box-shadow: 0 2px 10px rgba(5, 150, 105, 0.15);
    background: rgba(5, 150, 105, 0.02);
}
.profile-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.profile-brand-chip {
    display: flex;
    align-items: center;
    gap: 10px;
}
.profile-thumb-img {
    width: 36px;
    height: 36px;
    border-radius: var(--adm-radius-md);
    object-fit: contain;
    background: var(--adm-surface-subtle);
    border: 1px solid var(--adm-border);
    padding: 2px;
}
.profile-thumb-fallback {
    width: 36px;
    height: 36px;
    border-radius: var(--adm-radius-md);
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.95rem;
}
.profile-meta-dl {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    font-size: 0.78rem;
    margin: 10px 0;
    padding: 8px 0;
    border-top: 1px solid var(--adm-border-subtle);
    border-bottom: 1px solid var(--adm-border-subtle);
}
.profile-meta-dl dt { color: var(--adm-muted); }
.profile-meta-dl dd { margin: 0; font-weight: 700; color: var(--adm-text-main); text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.profile-card-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
}
.mini-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 10px;
    font-size: 0.76rem;
    font-weight: 700;
    border-radius: var(--adm-radius-sm);
    border: 1px solid var(--adm-border);
    background: var(--adm-surface);
    color: var(--adm-text-main);
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.mini-btn:hover {
    border-color: var(--adm-brand);
    color: var(--adm-brand);
    background: var(--adm-brand-light);
}
.mini-btn-del:hover {
    border-color: var(--adm-danger);
    color: var(--adm-danger);
    background: var(--adm-danger-light);
}
.mini-btn-active {
    background: #ecfdf5;
    color: #059669;
    border-color: rgba(5, 150, 105, 0.3);
}
.mini-btn-active:hover {
    background: #059669;
    color: #ffffff;
}

/* Modal for SMTP Diagnostics */
.modal-diag-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(8px);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-diag-overlay.active {
    display: flex;
}
.modal-diag-box {
    background: var(--adm-surface);
    border: 1px solid var(--adm-border);
    border-radius: var(--adm-radius-xl);
    width: 100%;
    max-width: 580px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--adm-shadow-hover);
}
.modal-diag-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--adm-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--adm-surface-subtle);
}

@media (max-width: 1050px) {
    .settings-workspace-layout { grid-template-columns: 1fr; }
    .settings-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 640px) {
    .settings-kpi-grid { grid-template-columns: 1fr; }
    .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; }
    .settings-hero-header { flex-direction: column; align-items: stretch; }
    .settings-actions-group { width: 100%; }
    .settings-actions-group .btn-saas { flex: 1; }
}
</style>

<div class="site-settings-container">

    <!-- ─── Page Hero Header & Actions ───────────────────────────────────────── -->
    <div class="settings-hero-header">
        <div>
            <nav class="settings-breadcrumb" aria-label="Breadcrumb">
                <a href="/admin/dashboard"><i class="fa-solid fa-house"></i> Admin</a>
                <span><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem;"></i></span>
                <span>Platform Settings</span>
                <span><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem;"></i></span>
                <span>Branding &amp; Infrastructure</span>
            </nav>
            <h1 class="settings-title">
                <i class="fa-solid fa-sliders" style="color: var(--adm-brand);"></i>
                <span>Organization Branding &amp; Infrastructure</span>
            </h1>
            <p class="settings-subtitle">
                Configure corporate identity, custom logo, favicon, and outgoing SMTP email delivery for <?php echo htmlspecialchars($activeName); ?>.
            </p>
        </div>

        <div class="settings-actions-group">
            <!-- Test SMTP Connection Button -->
            <button type="button" class="btn-saas btn-saas-test" onclick="openSmtpDiagModal()" title="Verify outgoing SMTP delivery and test handshake">
                <i class="fa-solid fa-paper-plane"></i>
                <span>Test SMTP</span>
            </button>

            <!-- Create New Profile Button -->
            <?php if ($editing): ?>
                <a href="site_settings?new=1" class="btn-saas btn-saas-secondary" title="Create a new branding and SMTP configuration profile">
                    <i class="fa-solid fa-plus"></i>
                    <span>New Profile</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ─── Flash Feedback Notification ─────────────────────────────────────── -->
    <?php if (!empty($flashMessage)): ?>
        <div class="alert alert-<?php echo ($flashType === 'success') ? 'success' : 'error'; ?>" role="alert">
            <i class="fa-solid <?php echo ($flashType === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <div>
                <strong><?php echo htmlspecialchars($flashMessage); ?></strong>
                <?php if (!empty($smtpDebugLog)): ?>
                    <pre style="margin-top: 8px; font-size: 0.75rem; background: rgba(0,0,0,0.05); padding: 8px; border-radius: 6px; overflow-x: auto; max-height: 150px;"><?php echo htmlspecialchars($smtpDebugLog); ?></pre>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ─── 4 Enterprise KPI Stat Metric Cards ────────────────────────────────── -->
    <div class="settings-kpi-grid">
        <!-- Card 1: Active Organization Name -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-indigo">
                <i class="fa-solid fa-building"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">Active Organization</div>
                <div class="kpi-value" title="<?php echo htmlspecialchars($activeName); ?>"><?php echo htmlspecialchars($activeName); ?></div>
                <div class="kpi-sub">
                    <span style="color: #059669;"><i class="fa-solid fa-circle-check"></i> Enforced Brand</span>
                </div>
            </div>
        </div>

        <!-- Card 2: Visual Branding Status -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-emerald">
                <i class="fa-solid fa-images"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">Branding Assets</div>
                <div class="kpi-value"><?php echo $activeLogo ? 'Custom Logo' : 'Default Icon'; ?></div>
                <div class="kpi-sub">
                    <span>Favicon: <?php echo $activeFavicon ? 'Custom ICO/PNG' : 'Default'; ?></span>
                </div>
            </div>
        </div>

        <!-- Card 3: Outgoing SMTP Delivery -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-purple">
                <i class="fa-solid fa-envelope-circle-check"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">SMTP Delivery</div>
                <div class="kpi-value" title="<?php echo htmlspecialchars($activeSmtpHost); ?>"><?php echo htmlspecialchars($activeSmtpHost); ?></div>
                <div class="kpi-sub">
                    <span>Port: <?php echo $activeConfig['smtp_port'] ?? 587; ?> &bull; <?php echo strtoupper($activeConfig['smtp_encryption'] ?? 'TLS'); ?></span>
                </div>
            </div>
        </div>

        <!-- Card 4: Saved Configuration Profiles -->
        <div class="kpi-card">
            <div class="kpi-icon-wrap kpi-icon-amber">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <div class="kpi-info">
                <div class="kpi-label">Brand Profiles</div>
                <div class="kpi-value"><?php echo $totalProfilesCount; ?> Profile<?php echo $totalProfilesCount === 1 ? '' : 's'; ?></div>
                <div class="kpi-sub">
                    <span>1 Active &bull; <?php echo max(0, $totalProfilesCount - 1); ?> Standby</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── 2-Column Responsive Workspace Grid ───────────────────────────────── -->
    <div class="settings-workspace-layout">

        <!-- Column 1: Main Settings Form -->
        <section class="settings-main-card">
            <div class="card-header-banner">
                <div>
                    <h2 class="card-header-title">
                        <i class="fa-solid fa-pen-to-square" style="color: var(--adm-brand);"></i>
                        <span><?php echo $editing ? 'Edit Organization Configuration' : 'Create Organization Profile'; ?></span>
                    </h2>
                    <p class="card-header-subtitle">Custom brand identity, security credentials, and email delivery parameters.</p>
                </div>
                <?php if ($editing && $editing['is_active']): ?>
                    <span class="badge-saas badge-clean" style="font-size: 0.76rem;">
                        <i class="fa-solid fa-circle-check"></i> Active Policy
                    </span>
                <?php endif; ?>
            </div>

            <form method="POST" action="" enctype="multipart/form-data" class="settings-form-body" id="siteSettingsForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="settings_id" id="settings_id" value="<?php echo (int)($editing['id'] ?? 0); ?>">

                <!-- Section 1: Corporate Identity & Assets -->
                <div class="form-section-divider">
                    <i class="fa-solid fa-palette"></i> Corporate Identity &amp; Visual Assets
                </div>

                <div class="form-grid-2">
                    <div class="form-group-item">
                        <label for="site_name">Organization / Portal Name *</label>
                        <input type="text" name="site_name" id="site_name" class="form-input-control" required minlength="2" maxlength="120" value="<?php echo htmlspecialchars($editing['site_name'] ?? ''); ?>" placeholder="e.g. Cloud Aspire Technologies" oninput="updateLiveMockup()">
                    </div>

                    <div class="form-group-item">
                        <label for="tagline">Tagline / Mission (Optional)</label>
                        <input type="text" name="tagline" id="tagline" class="form-input-control" maxlength="255" value="<?php echo htmlspecialchars($editing['tagline'] ?? ''); ?>" placeholder="e.g. Enterprise Workforce &amp; Payroll Solutions">
                    </div>
                </div>

                <div class="form-grid-2">
                    <!-- Logo Upload Dropzone -->
                    <div class="form-group-item">
                        <label for="logo_file">Corporate Logo</label>
                        <div class="upload-dropzone-box">
                            <input type="file" name="logo" id="logo_file" class="dropzone-file-input" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" onchange="previewLogoFile(this)">
                            <div class="dropzone-hint">PNG, JPG, or WebP. Transparent background recommended. Max 2 MB.</div>
                        </div>
                    </div>

                    <!-- Favicon Upload Dropzone -->
                    <div class="form-group-item">
                        <label for="favicon_file">Browser Favicon</label>
                        <div class="upload-dropzone-box">
                            <input type="file" name="favicon" id="favicon_file" class="dropzone-file-input" accept=".png,.ico,image/png,image/x-icon" onchange="previewFaviconFile(this)">
                            <div class="dropzone-hint">PNG or ICO icon. Square 32x32 / 64x64 recommended. Max 2 MB.</div>
                        </div>
                    </div>
                </div>

                <!-- Live Asset Previews -->
                <div class="preview-cluster-box" id="previewCluster">
                    <div class="preview-item-group">
                        <span class="preview-item-label">Logo Preview</span>
                        <?php if (!empty($editing['logo'])): ?>
                            <img src="<?php echo $uploadUrl . rawurlencode($editing['logo']); ?>" alt="Corporate Logo" class="logo-display-img" id="mockupLogoImg">
                        <?php else: ?>
                            <img src="" alt="Corporate Logo" class="logo-display-img" id="mockupLogoImg" style="display: none;">
                            <div class="profile-thumb-fallback" id="mockupLogoFallback"><i class="fa-solid fa-cloud-bolt"></i></div>
                        <?php endif; ?>
                    </div>

                    <div class="preview-item-group">
                        <span class="preview-item-label">Favicon Preview</span>
                        <?php if (!empty($editing['favicon'])): ?>
                            <img src="<?php echo $uploadUrl . rawurlencode($editing['favicon']); ?>" alt="Favicon" class="favicon-display-img" id="mockupFaviconImg">
                        <?php else: ?>
                            <img src="" alt="Favicon" class="favicon-display-img" id="mockupFaviconImg" style="display: none;">
                            <div class="profile-thumb-fallback" id="mockupFaviconFallback" style="width: 38px; height: 38px;"><i class="fa-solid fa-globe"></i></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Section 2: Outgoing SMTP Delivery Configuration -->
                <div class="form-section-divider">
                    <i class="fa-solid fa-paper-plane"></i> Outgoing SMTP Email Infrastructure
                </div>

                <!-- Quick Provider Presets Toolbar -->
                <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; align-items: center;">
                    <span style="font-size: 0.76rem; font-weight: 700; color: var(--adm-muted); text-transform: uppercase; letter-spacing: 0.05em;">Presets:</span>
                    <button type="button" class="mini-btn" onclick="applySmtpPreset('gmail_tls')" title="Configure Gmail on Port 587 with TLS (STARTTLS)">
                        <i class="fa-brands fa-google" style="color: #ea4335;"></i> Gmail (587 TLS)
                    </button>
                    <button type="button" class="mini-btn" onclick="applySmtpPreset('gmail_ssl')" title="Configure Gmail on Port 465 with SSL">
                        <i class="fa-brands fa-google" style="color: #ea4335;"></i> Gmail (465 SSL)
                    </button>
                    <button type="button" class="mini-btn" onclick="applySmtpPreset('office365')" title="Configure Office 365 on Port 587 with TLS">
                        <i class="fa-brands fa-microsoft" style="color: #00a4ef;"></i> Office 365 (587 TLS)
                    </button>
                </div>

                <div class="form-grid-3">
                    <div class="form-group-item" style="grid-column: span 2;">
                        <label for="smtp_host">SMTP Host Address</label>
                        <input type="text" name="smtp_host" id="smtp_host" class="form-input-control" maxlength="255" value="<?php echo htmlspecialchars($editing['smtp_host'] ?? ''); ?>" placeholder="smtp.gmail.com / smtp.office365.com">
                    </div>

                    <div class="form-group-item">
                        <label for="smtp_port">Port</label>
                        <input type="number" name="smtp_port" id="smtp_port" class="form-input-control" min="1" max="65535" value="<?php echo (int)($editing['smtp_port'] ?? 587); ?>">
                    </div>
                </div>

                <div class="form-grid-3">
                    <div class="form-group-item">
                        <label for="smtp_encryption">Security Encryption</label>
                        <select name="smtp_encryption" id="smtp_encryption" class="form-input-control">
                            <?php foreach (['tls' => 'TLS (Recommended)', 'ssl' => 'SSL', 'none' => 'None / Plain'] as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>" <?php echo ($editing['smtp_encryption'] ?? 'tls') === $val ? 'selected' : ''; ?>>
                                    <?php echo $lbl; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group-item">
                        <label for="smtp_username">SMTP Username</label>
                        <input type="text" name="smtp_username" id="smtp_username" class="form-input-control" autocomplete="off" value="<?php echo htmlspecialchars($editing['smtp_username'] ?? ''); ?>" placeholder="account@company.com">
                    </div>

                    <div class="form-group-item">
                        <label for="smtp_password">SMTP Password</label>
                        <input type="password" name="smtp_password" id="smtp_password" class="form-input-control" autocomplete="new-password" placeholder="<?php echo !empty($editing['smtp_password']) ? '•••••••••••• (Leave blank to keep)' : 'App Password'; ?>">
                    </div>
                </div>

                <!-- Google / Mail Provider Guidance Banner -->
                <div style="background: rgba(79, 70, 229, 0.05); border: 1px solid rgba(79, 70, 229, 0.2); border-radius: var(--adm-radius-md); padding: 12px 14px; margin-bottom: 18px; font-size: 0.8rem; color: var(--adm-text-main); display: flex; align-items: flex-start; gap: 10px;">
                    <i class="fa-solid fa-key" style="color: var(--adm-brand); margin-top: 2px; font-size: 0.95rem;"></i>
                    <div>
                        <strong>Important for Gmail / Google Workspace:</strong>
                        Google requires a 16-character <strong>Google App Password</strong> (not your normal account password). Standard passwords trigger a <em>535 Bad Credentials</em> error.
                        <div style="margin-top: 4px;">
                            <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener" style="color: var(--adm-brand); font-weight: 700; text-decoration: underline;">
                                Generate Google App Password <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.7rem;"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group-item">
                        <label for="smtp_from_email">Sender (From) Email</label>
                        <input type="email" name="smtp_from_email" id="smtp_from_email" class="form-input-control" maxlength="255" value="<?php echo htmlspecialchars($editing['smtp_from_email'] ?? ''); ?>" placeholder="noreply@company.com">
                    </div>

                    <div class="form-group-item">
                        <label for="smtp_from_name">Sender (From) Display Name</label>
                        <input type="text" name="smtp_from_name" id="smtp_from_name" class="form-input-control" maxlength="120" value="<?php echo htmlspecialchars($editing['smtp_from_name'] ?? ''); ?>" placeholder="e.g. Cloud Aspire Notifications">
                    </div>
                </div>

                <!-- Section 3: Support Contact Information -->
                <div class="form-section-divider">
                    <i class="fa-solid fa-headset"></i> Support &amp; Help Desk Coordinates
                </div>

                <div class="form-grid-2">
                    <div class="form-group-item">
                        <label for="support_email">Help Desk Email</label>
                        <input type="email" name="support_email" id="support_email" class="form-input-control" value="<?php echo htmlspecialchars($editing['support_email'] ?? ''); ?>" placeholder="support@company.com">
                    </div>

                    <div class="form-group-item">
                        <label for="support_phone">Help Desk Phone</label>
                        <input type="text" name="support_phone" id="support_phone" class="form-input-control" value="<?php echo htmlspecialchars($editing['support_phone'] ?? ''); ?>" placeholder="+91 98765 43210">
                    </div>
                </div>

                <!-- Active Policy Enforce Card -->
                <label class="active-profile-card">
                    <input type="checkbox" name="is_active" value="1" <?php echo !isset($editing['is_active']) || $editing['is_active'] ? 'checked' : ''; ?>>
                    <div class="active-profile-info">
                        <strong>Set as Active Enterprise Brand Configuration</strong>
                        <small>Activating this configuration applies the logo, brand name, and SMTP routing across the entire portal.</small>
                    </div>
                </label>

                <!-- Action Toolbar -->
                <div class="form-footer-actions">
                    <div style="display: flex; gap: 8px;">
                        <?php if ($editing): ?>
                            <a href="site_settings" class="btn-saas btn-saas-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn-saas btn-saas-test" onclick="openSmtpDiagModal()">
                            <i class="fa-solid fa-vial"></i> Test Email Handshake
                        </button>
                        <button type="submit" name="save_settings" class="btn-saas btn-saas-primary">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <span><?php echo $editing ? 'Update Configuration' : 'Create Configuration'; ?></span>
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <!-- Column 2: Live Brand Mockup & Saved Profiles -->
        <aside>
            <!-- 1. Interactive Brand Identity Mockup Preview -->
            <div class="brand-mockup-card">
                <h3 class="mockup-header-title">
                    <i class="fa-solid fa-eye" style="color: var(--adm-brand);"></i>
                    <span>Live Brand Mockup</span>
                </h3>
                <p style="font-size: 0.78rem; color: var(--adm-muted); margin-bottom: 12px;">
                    Real-time visual preview of how your brand appears on the portal's sticky navbar.
                </p>

                <div class="mockup-navbar-preview">
                    <div class="mockup-brand-item">
                        <img src="<?php echo $activeLogo ?: ''; ?>" alt="Logo" id="liveMockNavLogo" class="logo-display-img" style="height: 32px; <?php echo $activeLogo ? '' : 'display:none;'; ?>">
                        <div id="liveMockNavIcon" class="mockup-avatar-mock" style="<?php echo $activeLogo ? 'display:none;' : ''; ?>">
                            <i class="fa-solid fa-cloud-bolt"></i>
                        </div>
                        <span id="liveMockNavTitle" style="font-family: 'Outfit', sans-serif; font-size: 1.05rem; font-weight: 800; color: var(--adm-text-main);">
                            <?php echo htmlspecialchars($editing['site_name'] ?? $activeName); ?>
                        </span>
                        <span class="badge-saas badge-info" style="font-size: 0.65rem;">Console</span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div class="mockup-avatar-mock">AD</div>
                    </div>
                </div>
            </div>

            <!-- 2. Saved Configurations List -->
            <div class="records-side-card">
                <div class="card-header-banner">
                    <div>
                        <h3 class="card-header-title">
                            <i class="fa-solid fa-folder-open" style="color: var(--adm-brand);"></i>
                            <span>Saved Configurations</span>
                        </h3>
                        <span style="font-size: 0.78rem; color: var(--adm-muted);">
                            <?php echo count($settingsList); ?> Profiles Registered
                        </span>
                    </div>
                </div>

                <div style="padding: 16px;">
                    <?php if (empty($settingsList)): ?>
                        <div style="text-align: center; padding: 30px; color: var(--adm-muted);">No profiles saved yet.</div>
                    <?php else: ?>
                        <?php foreach ($settingsList as $item): ?>
                            <?php $isAct = (bool)$item['is_active']; ?>
                            <div class="saved-profile-item <?php echo $isAct ? 'is-active-profile' : ''; ?>">
                                <div class="profile-card-top">
                                    <div class="profile-brand-chip">
                                        <?php if (!empty($item['logo'])): ?>
                                            <img src="<?php echo $uploadUrl . rawurlencode($item['logo']); ?>" alt="" class="profile-thumb-img">
                                        <?php else: ?>
                                            <div class="profile-thumb-fallback"><?php echo strtoupper(substr($item['site_name'], 0, 1)); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <strong style="font-size: 0.92rem; color: var(--adm-text-main); display: block;">
                                                <?php echo htmlspecialchars($item['site_name']); ?>
                                            </strong>
                                            <small style="color: var(--adm-muted); font-size: 0.72rem;">
                                                Updated <?php echo date('M j, Y', strtotime($item['updated_at'])); ?>
                                            </small>
                                        </div>
                                    </div>

                                    <?php if ($isAct): ?>
                                        <span class="badge-saas badge-clean" style="font-size: 0.72rem;"><i class="fa-solid fa-check"></i> Active</span>
                                    <?php endif; ?>
                                </div>

                                <dl class="profile-meta-dl">
                                    <dt>SMTP Host</dt>
                                    <dd><?php echo htmlspecialchars($item['smtp_host'] ?: 'Unset'); ?></dd>
                                    <dt>Port / Security</dt>
                                    <dd><?php echo (int)$item['smtp_port']; ?> / <?php echo strtoupper($item['smtp_encryption'] ?? 'TLS'); ?></dd>
                                    <dt>From Email</dt>
                                    <dd><?php echo htmlspecialchars($item['smtp_from_email'] ?: 'Unset'); ?></dd>
                                    <dt>Favicon</dt>
                                    <dd><?php echo $item['favicon'] ? 'Uploaded' : 'None'; ?></dd>
                                </dl>

                                <div class="profile-card-actions">
                                    <?php if (!$isAct): ?>
                                        <form method="POST" action="" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="settings_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" name="set_active" class="mini-btn mini-btn-active" title="Enforce this configuration">
                                                <i class="fa-solid fa-circle-check"></i> Set Active
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <a href="site_settings?edit=<?php echo $item['id']; ?>" class="mini-btn" title="Edit this profile">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </a>

                                    <form method="POST" action="" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="settings_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" name="clone_settings" class="mini-btn" title="Duplicate profile">
                                            <i class="fa-solid fa-copy"></i>
                                        </button>
                                    </form>

                                    <?php if (!$isAct): ?>
                                        <form method="POST" action="" style="margin: 0;" onsubmit="return confirm('Delete this site configuration?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="settings_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" name="delete_settings" class="mini-btn mini-btn-del" title="Delete">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="mini-btn" disabled style="opacity: 0.4; cursor: not-allowed;" title="Active configuration cannot be deleted.">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- ─── Interactive SMTP Diagnostics & Test Email Modal ──────────────────────── -->
<div class="modal-diag-overlay" id="smtpDiagModal" role="dialog" aria-modal="true" aria-labelledby="diagModalTitle">
    <div class="modal-diag-box">
        <div class="modal-diag-header">
            <h3 style="margin: 0; font-family: 'Outfit', sans-serif; font-size: 1.25rem; font-weight: 800; color: var(--adm-text-main); display: flex; align-items: center; gap: 8px;" id="diagModalTitle">
                <i class="fa-solid fa-paper-plane" style="color: var(--adm-brand);"></i>
                <span>SMTP Handshake &amp; Deliverability Test</span>
            </h3>
            <button type="button" class="mini-btn" onclick="closeSmtpDiagModal()" aria-label="Close modal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" style="padding: 22px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="test_smtp" value="1">
            <input type="hidden" name="settings_id" value="<?php echo (int)($editing['id'] ?? 0); ?>">

            <!-- Pass in-flight form values into test -->
            <input type="hidden" name="smtp_host" id="diag_smtp_host">
            <input type="hidden" name="smtp_port" id="diag_smtp_port">
            <input type="hidden" name="smtp_encryption" id="diag_smtp_encryption">
            <input type="hidden" name="smtp_username" id="diag_smtp_username">
            <input type="hidden" name="smtp_password" id="diag_smtp_password">
            <input type="hidden" name="smtp_from_email" id="diag_smtp_from_email">
            <input type="hidden" name="smtp_from_name" id="diag_smtp_from_name">

            <p style="font-size: 0.86rem; color: var(--adm-muted); margin-bottom: 16px;">
                Dispatch an immediate test email to verify your SMTP server connectivity, SSL/TLS handshakes, and credentials.
            </p>

            <div class="form-group-item">
                <label for="test_email_input">Recipient Test Email Address *</label>
                <input type="email" name="test_email" id="test_email_input" class="form-input-control" required value="<?php echo htmlspecialchars($_SESSION['admin_email'] ?? 'cloudaspire2025@gmail.com'); ?>" placeholder="admin@company.com">
            </div>

            <div style="background: var(--adm-surface-subtle); border-radius: var(--adm-radius-md); padding: 12px; font-size: 0.78rem; color: var(--adm-muted); margin-bottom: 16px;">
                <i class="fa-solid fa-circle-info" style="color: var(--adm-brand); margin-right: 4px;"></i>
                The test will use the parameters currently typed in the form above.
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-saas btn-saas-secondary" onclick="closeSmtpDiagModal()">Cancel</button>
                <button type="submit" class="btn-saas btn-saas-primary" onclick="syncDiagParams()">
                    <i class="fa-solid fa-paper-plane"></i> Send Test Verification
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Interactive Client-Side JavaScript ───────────────────────────────────── -->
<script>
function openSmtpDiagModal() {
    document.getElementById('smtpDiagModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeSmtpDiagModal() {
    document.getElementById('smtpDiagModal').classList.remove('active');
    document.body.style.overflow = '';
}

function syncDiagParams() {
    document.getElementById('diag_smtp_host').value = document.getElementById('smtp_host').value;
    document.getElementById('diag_smtp_port').value = document.getElementById('smtp_port').value;
    document.getElementById('diag_smtp_encryption').value = document.getElementById('smtp_encryption').value;
    document.getElementById('diag_smtp_username').value = document.getElementById('smtp_username').value;
    document.getElementById('diag_smtp_password').value = document.getElementById('smtp_password').value;
    document.getElementById('diag_smtp_from_email').value = document.getElementById('smtp_from_email').value;
    document.getElementById('diag_smtp_from_name').value = document.getElementById('smtp_from_name').value;
}

function updateLiveMockup() {
    const val = document.getElementById('site_name').value.trim();
    const titleEl = document.getElementById('liveMockNavTitle');
    if (titleEl) {
        titleEl.innerText = val ? val : 'Organization Brand';
    }
}

// Client-Side FileReader Logo Preview
function previewLogoFile(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const logoImg = document.getElementById('mockupLogoImg');
            const fallback = document.getElementById('mockupLogoFallback');
            const liveNavLogo = document.getElementById('liveMockNavLogo');
            const liveNavIcon = document.getElementById('liveMockNavIcon');

            if (logoImg) {
                logoImg.src = e.target.result;
                logoImg.style.display = 'block';
            }
            if (fallback) fallback.style.display = 'none';

            if (liveNavLogo) {
                liveNavLogo.src = e.target.result;
                liveNavLogo.style.display = 'block';
            }
            if (liveNavIcon) liveNavIcon.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Client-Side FileReader Favicon Preview
function previewFaviconFile(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const favImg = document.getElementById('mockupFaviconImg');
            const fallback = document.getElementById('mockupFaviconFallback');

            if (favImg) {
                favImg.src = e.target.result;
                favImg.style.display = 'block';
            }
            if (fallback) fallback.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Quick SMTP Provider Presets
function applySmtpPreset(type) {
    const hostEl = document.getElementById('smtp_host');
    const portEl = document.getElementById('smtp_port');
    const encEl = document.getElementById('smtp_encryption');

    if (type === 'gmail_tls') {
        if (hostEl) hostEl.value = 'smtp.gmail.com';
        if (portEl) portEl.value = 587;
        if (encEl) encEl.value = 'tls';
    } else if (type === 'gmail_ssl') {
        if (hostEl) hostEl.value = 'smtp.gmail.com';
        if (portEl) portEl.value = 465;
        if (encEl) encEl.value = 'ssl';
    } else if (type === 'office365') {
        if (hostEl) hostEl.value = 'smtp.office365.com';
        if (portEl) portEl.value = 587;
        if (encEl) encEl.value = 'tls';
    }
}

// Auto-align Port & Encryption pairs to prevent invalid handshakes
document.addEventListener('DOMContentLoaded', function() {
    const portEl = document.getElementById('smtp_port');
    const encEl = document.getElementById('smtp_encryption');

    if (portEl && encEl) {
        portEl.addEventListener('change', function() {
            const p = parseInt(this.value, 10);
            if (p === 587 && encEl.value === 'ssl') {
                encEl.value = 'tls';
            } else if (p === 465 && encEl.value === 'tls') {
                encEl.value = 'ssl';
            }
        });

        encEl.addEventListener('change', function() {
            const p = parseInt(portEl.value, 10);
            if (this.value === 'ssl' && p === 587) {
                portEl.value = 465;
            } else if (this.value === 'tls' && p === 465) {
                portEl.value = 587;
            }
        });
    }
});
</script>

<?php include 'footer.php'; ?>
