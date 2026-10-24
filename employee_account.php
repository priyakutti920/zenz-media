<?php
// Set timezone to India / Local Time
date_default_timezone_set('Asia/Kolkata');

// Include database connection and security modules
require_once 'app/db.php';
require_once 'app/security.php';

Security::init($pdo);
Security::initSecureSession();

// Check if employee is logged in
if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    header("Location: login");
    exit();
}

// Check session timeout (30 mins inactivity)
if (!Security::setSessionTimeout()) {
    header("Location: login?msg=timeout");
    exit();
}

$employee_id = $_SESSION['employee_id'];
$message = '';
$messageType = '';
$activeTab = 'profile';

// ── Secure Aadhar Document Viewer Gateway (Employee Self-Access Only) ──
if (isset($_GET['action']) && $_GET['action'] === 'view_aadhar') {
    try {
        $st = $pdo->prepare("SELECT aadhar_image FROM employees WHERE id = ?");
        $st->execute([$employee_id]);
        $aadharDoc = $st->fetchColumn();

        if (empty($aadharDoc)) {
            http_response_code(404);
            die("No Aadhaar document found for this account.");
        }

        $baseDir = realpath(__DIR__ . '/assets/uploads/aadhar');
        $filePath = realpath(__DIR__ . '/assets/uploads/aadhar/' . basename($aadharDoc));

        // Path traversal defense
        if (!$filePath || !$baseDir || !str_starts_with($filePath, $baseDir) || !file_exists($filePath)) {
            http_response_code(404);
            die("Document not found on storage.");
        }

        $mime = mime_content_type($filePath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="aadhaar_document_' . $employee_id . '.' . pathinfo($filePath, PATHINFO_EXTENSION) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        readfile($filePath);
        exit();
    } catch (PDOException $e) {
        http_response_code(500);
        die("Error loading document.");
    }
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCSRFToken($submittedToken, 'employee_account')) {
        $message = 'Invalid or expired security token. Please try again.';
        $messageType = 'error';
    } else {
        
        // ── 1. Update Profile (Name, Email, Phone, Avatar) ──
        if (isset($_POST['update_profile'])) {
            $activeTab = 'profile';
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName  = trim($_POST['last_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $phone     = trim($_POST['phone'] ?? '');
            
            if (empty($firstName) || empty($lastName) || empty($email)) {
                $message = "First name, last name, and email are required fields.";
                $messageType = 'error';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid email address.";
                $messageType = 'error';
            } else {
                try {
                    // Check if email belongs to another employee
                    $dupStmt = $pdo->prepare("SELECT id FROM employees WHERE email = ? AND id != ?");
                    $dupStmt->execute([$email, $employee_id]);
                    if ($dupStmt->fetch()) {
                        $message = "An employee with this email address already exists.";
                        $messageType = 'error';
                    } else {
                        // Update basic info in DB
                        $stmt = $pdo->prepare("UPDATE employees SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?");
                        $stmt->execute([$firstName, $lastName, $email, $phone, $employee_id]);
                        
                        // Update Session variables consistently
                        $_SESSION['employee_name']  = trim($firstName . ' ' . $lastName);
                        $_SESSION['employee_email'] = $email;
                        
                        $message = "Profile updated successfully.";
                        $messageType = 'success';
                        
                        // Handle Avatar Upload if provided
                        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                            $maxSize = 2 * 1024 * 1024; // 2MB
                            if ($_FILES['avatar']['size'] > $maxSize) {
                                $message .= " However, avatar exceeds 2MB limit and was not updated.";
                                $messageType = 'warning';
                            } else {
                                $finfo = new finfo(FILEINFO_MIME_TYPE);
                                $mimeType = $finfo->file($_FILES['avatar']['tmp_name']);
                                $allowedMimeTypes = [
                                    'image/jpeg' => 'jpg',
                                    'image/png'  => 'png',
                                    'image/gif'  => 'gif',
                                    'image/webp' => 'webp'
                                ];
                                
                                if (array_key_exists($mimeType, $allowedMimeTypes)) {
                                    $ext = $allowedMimeTypes[$mimeType];
                                    $avatarDir = 'assets/uploads/avatars';
                                    if (!is_dir($avatarDir)) {
                                        mkdir($avatarDir, 0755, true);
                                    }
                                    
                                    // Fetch old avatar to remove it cleanly
                                    $curStmt = $pdo->prepare("SELECT avatar FROM employees WHERE id = ?");
                                    $curStmt->execute([$employee_id]);
                                    $oldAvatar = $curStmt->fetchColumn();
                                    
                                    $fileName = 'avatar_' . $employee_id . '_' . time() . '.' . $ext;
                                    $uploadPath = $avatarDir . '/' . $fileName;
                                    
                                    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
                                        $stmt = $pdo->prepare("UPDATE employees SET avatar = ? WHERE id = ?");
                                        $stmt->execute([$fileName, $employee_id]);
                                        $_SESSION['employee_avatar'] = $fileName;
                                        
                                        // Delete old avatar file if exists
                                        if (!empty($oldAvatar) && file_exists($avatarDir . '/' . $oldAvatar)) {
                                            @unlink($avatarDir . '/' . $oldAvatar);
                                        }
                                        $message .= " Avatar updated successfully.";
                                    } else {
                                        $message .= " Could not save avatar file. Please check folder permissions.";
                                        $messageType = 'warning';
                                    }
                                } else {
                                    $message .= " Invalid avatar format. Only JPG, PNG, GIF, or WEBP allowed.";
                                    $messageType = 'warning';
                                }
                            }
                        }
                    }
                } catch (PDOException $e) {
                    error_log("Profile update error: " . $e->getMessage());
                    $message = "An error occurred while updating your profile.";
                    $messageType = 'error';
                }
            }
        }
        
        // ── 2. Upload Aadhar Card ──
        if (isset($_POST['upload_aadhar'])) {
            $activeTab = 'verification';
            
            // Check current verified status: if already verified, do not allow overwrite
            $checkStmt = $pdo->prepare("SELECT aadhar_verified FROM employees WHERE id = ?");
            $checkStmt->execute([$employee_id]);
            $curVerified = (int)$checkStmt->fetchColumn();
            
            if ($curVerified === 2) {
                $message = "Your account is already verified. No further upload is required.";
                $messageType = 'info';
            } else {
                if (isset($_FILES['aadhar_image']) && $_FILES['aadhar_image']['error'] === UPLOAD_ERR_OK) {
                    $maxAadharSize = 5 * 1024 * 1024; // 5MB
                    if ($_FILES['aadhar_image']['size'] > $maxAadharSize) {
                        $message = "File exceeds the 5MB size limit. Please upload a smaller file.";
                        $messageType = 'error';
                    } else {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->file($_FILES['aadhar_image']['tmp_name']);
                        $allowedMimeTypes = [
                            'image/jpeg'      => 'jpg',
                            'image/png'       => 'png',
                            'image/webp'      => 'webp',
                            'application/pdf' => 'pdf'
                        ];
                        
                        if (array_key_exists($mimeType, $allowedMimeTypes)) {
                            $ext = $allowedMimeTypes[$mimeType];
                            $aadharDir = 'assets/uploads/aadhar';
                            if (!is_dir($aadharDir)) {
                                mkdir($aadharDir, 0755, true);
                            }
                            
                            $randomSuffix = bin2hex(random_bytes(16));
                            $fileName = 'aadhar_' . $employee_id . '_' . $randomSuffix . '.' . $ext;
                            $uploadPath = $aadharDir . '/' . $fileName;
                            
                            if (move_uploaded_file($_FILES['aadhar_image']['tmp_name'], $uploadPath)) {
                                $stmt = $pdo->prepare("UPDATE employees SET aadhar_image = ?, aadhar_verified = 1 WHERE id = ?");
                                $stmt->execute([$fileName, $employee_id]);
                                $message = "Aadhar card uploaded successfully! Your verification is now pending review by HR.";
                                $messageType = 'success';
                            } else {
                                $message = "Failed to upload file. Please try again.";
                                $messageType = 'error';
                            }
                        } else {
                            $message = "Invalid file type. Only JPG, PNG, WEBP, or PDF are accepted.";
                            $messageType = 'error';
                        }
                    }
                } else {
                    $message = "Please select a valid Aadhar file to upload.";
                    $messageType = 'error';
                }
            }
        }
        
        // ── 3. Change Password ──
        if (isset($_POST['update_password'])) {
            $activeTab = 'security';
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword     = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $message = "Please fill in all password fields.";
                $messageType = 'error';
            } elseif ($newPassword !== $confirmPassword) {
                $message = "New password and confirmation do not match.";
                $messageType = 'error';
            } else {
                $pwdErrors = Security::validatePasswordStrength($newPassword);
                if (!empty($pwdErrors)) {
                    $message = implode('. ', $pwdErrors) . '.';
                    $messageType = 'error';
                } else {
                    try {
                        $stmt = $pdo->prepare("SELECT password FROM employees WHERE id = ?");
                        $stmt->execute([$employee_id]);
                        $empRecord = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($empRecord && password_verify($currentPassword, $empRecord['password'])) {
                            if (password_verify($newPassword, $empRecord['password'])) {
                                $message = "New password cannot be the same as your current password.";
                                $messageType = 'error';
                            } else {
                                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("UPDATE employees SET password = ? WHERE id = ?");
                                $stmt->execute([$hashedPassword, $employee_id]);
                                $message = "Password updated successfully. Please keep your credentials secure.";
                                $messageType = 'success';
                            }
                        } else {
                            $message = "Current password is incorrect.";
                            $messageType = 'error';
                        }
                    } catch (PDOException $e) {
                        error_log("Password update error: " . $e->getMessage());
                        $message = "Error updating password. Please try again.";
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

// Fetch fresh employee profile
try {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        header("Location: employee_logout");
        exit();
    }
} catch (PDOException $e) {
    die("Error loading account data.");
}

$csrfToken = Security::generateCSRFToken('employee_account');

// Determine verification status
// 0: Unverified (no image or rejected)
// 1: Pending review (image uploaded, awaiting admin)
// 2: Verified (approved)
$aadharStatus = (int)($employee['aadhar_verified'] ?? 0);
$hasAadharImage = !empty($employee['aadhar_image']);
$isVerified = ($aadharStatus === 2);
$isPending  = ($aadharStatus === 1 || ($hasAadharImage && $aadharStatus === 0));
$isUnverified = (!$isVerified && !$isPending);

// Avatar initials fallback
$firstName = htmlspecialchars($employee['first_name'] ?? '');
$lastName  = htmlspecialchars($employee['last_name'] ?? '');
$fullName  = trim($firstName . ' ' . $lastName) ?: 'Employee';
$initials  = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1)) ?: 'EM';
$hireDate  = !empty($employee['hire_date']) ? date('M d, Y', strtotime($employee['hire_date'])) : 'Not specified';
$joinedDate = !empty($employee['created_at']) ? date('M d, Y', strtotime($employee['created_at'])) : $hireDate;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Account &amp; Settings &bull; <?php echo $fullName; ?> | Cloud Aspire</title>
    <meta name="description" content="Manage personal information, security credentials, and identity verification on Cloud Aspire Employee Portal.">
    <link rel="icon" type="image/png" href="https://cloudaspire.in/uploads/site_favicon_1783239900.png">

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
            --acc-bg:            #f8fafc;
            --acc-surface:       #ffffff;
            --acc-surface-subtle:#f1f5f9;
            --acc-surface-card:  #ffffff;
            --acc-text-main:     #09090b;
            --acc-text-body:     #1e293b;
            --acc-muted:         #475569;
            --acc-faint:         #64748b;
            --acc-border:        #cbd5e1;
            --acc-border-subtle: #e2e8f0;

            --acc-brand:         #4f46e5;
            --acc-brand-hover:   #4338ca;
            --acc-brand-light:   #eef2ff;
            --acc-brand-border:  #c7d2fe;

            --acc-success:       #059669;
            --acc-success-light: #ecfdf5;
            --acc-success-border:#a7f3d0;

            --acc-warning:       #d97706;
            --acc-warning-light: #fffbeb;
            --acc-warning-border:#fde68a;

            --acc-danger:        #e11d48;
            --acc-danger-light:  #fff1f2;
            --acc-danger-border: #fecdd3;

            --acc-radius-sm:     8px;
            --acc-radius-md:     14px;
            --acc-radius-lg:     18px;
            --acc-radius-xl:     24px;
            --acc-radius-pill:   9999px;

            --acc-shadow-sm:     0 1px 3px rgba(0, 0, 0, 0.05);
            --acc-shadow-card:   0 1px 3px rgba(15, 23, 42, 0.04), 0 6px 18px -2px rgba(15, 23, 42, 0.05);
            --acc-shadow-hover:  0 12px 28px -6px rgba(15, 23, 42, 0.12);

            --acc-transition:    0.24s cubic-bezier(0.16, 1, 0.3, 1);
            --acc-max-w:         1440px;
        }

        html[data-theme="dark"] {
            --acc-bg:            #090d16;
            --acc-surface:       #131d33;
            --acc-surface-subtle:#18233d;
            --acc-surface-card:  #131d33;
            --acc-text-main:     #f8fafc;
            --acc-text-body:     #e2e8f0;
            --acc-muted:         #94a3b8;
            --acc-faint:         #64748b;
            --acc-border:        #2d3f66;
            --acc-border-subtle: #1e2a44;

            --acc-brand-light:   rgba(79, 70, 229, 0.18);
            --acc-brand-border:  rgba(99, 102, 241, 0.35);

            --acc-success-light: rgba(5, 150, 105, 0.18);
            --acc-success-border:rgba(5, 150, 105, 0.35);

            --acc-warning-light: rgba(217, 119, 6, 0.18);
            --acc-warning-border:rgba(217, 119, 6, 0.35);

            --acc-danger-light:  rgba(225, 29, 72, 0.18);
            --acc-danger-border: rgba(225, 29, 72, 0.35);

            --acc-shadow-card:   0 4px 20px -2px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05);
            --acc-shadow-hover:  0 16px 36px -6px rgba(0, 0, 0, 0.65);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--acc-bg);
            color: var(--acc-text-body);
            line-height: 1.6;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        /* ── Container ── */
        .account-wrapper {
            max-width: var(--acc-max-w);
            margin: 0 auto;
            padding: 28px 24px 60px;
        }

        /* ── Alert Toasts ── */
        .alert-box {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 20px;
            border-radius: var(--acc-radius-md);
            margin-bottom: 24px;
            font-size: 0.95rem;
            font-weight: 500;
            line-height: 1.5;
            position: relative;
            animation: accFadeDown 0.3s ease;
            box-shadow: var(--acc-shadow-sm);
        }
        @keyframes accFadeDown {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .alert-box i.alert-icon {
            font-size: 1.25rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .alert-box.alert-success {
            background: var(--acc-success-light);
            color: var(--acc-success);
            border: 1px solid var(--acc-success-border);
        }
        .alert-box.alert-error {
            background: var(--acc-danger-light);
            color: var(--acc-danger);
            border: 1px solid var(--acc-danger-border);
        }
        .alert-box.alert-warning {
            background: var(--acc-warning-light);
            color: var(--acc-warning);
            border: 1px solid var(--acc-warning-border);
        }
        .alert-box.alert-info {
            background: var(--acc-brand-light);
            color: var(--acc-brand);
            border: 1px solid var(--acc-brand-border);
        }
        .alert-close {
            margin-left: auto;
            background: transparent;
            border: none;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
            font-size: 1.1rem;
            line-height: 1;
            padding: 2px;
            transition: opacity var(--acc-transition);
        }
        .alert-close:hover { opacity: 1; }

        /* ── Profile Hero Header ── */
        .profile-hero {
            background: var(--acc-surface);
            border: 1px solid var(--acc-border);
            border-radius: var(--acc-radius-xl);
            box-shadow: var(--acc-shadow-card);
            overflow: hidden;
            margin-bottom: 28px;
            position: relative;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .hero-banner {
            height: 140px;
            background: linear-gradient(135deg, #3730a3 0%, #4f46e5 50%, #7c3aed 100%);
            position: relative;
            overflow: hidden;
        }
        .hero-banner::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: radial-gradient(circle at 20% 30%, rgba(255,255,255,0.18) 0%, transparent 60%),
                              radial-gradient(circle at 80% 80%, rgba(255,255,255,0.12) 0%, transparent 50%);
            pointer-events: none;
        }
        .hero-banner-pattern {
            position: absolute;
            right: 20px;
            bottom: -20px;
            opacity: 0.15;
            color: #fff;
            font-size: 8rem;
            pointer-events: none;
        }

        .hero-body {
            padding: 0 32px 28px;
            position: relative;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }

        .hero-user-section {
            display: flex;
            align-items: flex-end;
            gap: 24px;
            margin-top: -60px;
        }

        /* Avatar Container with Upload Badge */
        .avatar-wrap {
            position: relative;
            width: 116px;
            height: 116px;
            border-radius: 50%;
            background: var(--acc-surface);
            padding: 4px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
            flex-shrink: 0;
        }
        .avatar-img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            background: var(--acc-surface-subtle);
            display: block;
        }
        .avatar-initials {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            font-weight: 800;
            color: #fff;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            font-family: 'Outfit', sans-serif;
            letter-spacing: -0.02em;
        }
        .avatar-edit-btn {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--acc-brand);
            color: #fff;
            border: 2px solid var(--acc-surface);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.88rem;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.4);
            transition: transform var(--acc-transition), background var(--acc-transition);
        }
        .avatar-edit-btn:hover {
            transform: scale(1.1);
            background: var(--acc-brand-hover);
        }

        .hero-info {
            margin-bottom: 6px;
        }
        .hero-name-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }
        .hero-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--acc-text-main);
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        /* Status & Role Badges */
        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: var(--acc-radius-pill);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .badge-verified {
            background: var(--acc-success-light);
            color: var(--acc-success);
            border: 1px solid var(--acc-success-border);
        }
        .badge-pending {
            background: var(--acc-warning-light);
            color: var(--acc-warning);
            border: 1px solid var(--acc-warning-border);
        }
        .badge-unverified {
            background: var(--acc-danger-light);
            color: var(--acc-danger);
            border: 1px solid var(--acc-danger-border);
        }

        .hero-meta-row {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            font-size: 0.9rem;
            color: var(--acc-muted);
        }
        .hero-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .hero-meta-item i {
            color: var(--acc-brand);
            font-size: 0.92rem;
        }

        .hero-quick-stats {
            display: flex;
            gap: 12px;
            margin-bottom: 6px;
        }
        .quick-stat-chip {
            background: var(--acc-surface-subtle);
            border: 1px solid var(--acc-border-subtle);
            border-radius: var(--acc-radius-md);
            padding: 8px 16px;
            text-align: right;
        }
        .quick-stat-chip .stat-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--acc-faint);
            font-weight: 700;
            display: block;
        }
        .quick-stat-chip .stat-val {
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--acc-text-main);
        }

        /* ── Tabs Navigation ── */
        .account-tabs {
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1px solid var(--acc-border);
            margin-bottom: 28px;
            padding-bottom: 4px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .account-tabs::-webkit-scrollbar { display: none; }

        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            border: none;
            background: transparent;
            color: var(--acc-muted);
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: var(--acc-radius-md);
            cursor: pointer;
            transition: all var(--acc-transition);
            white-space: nowrap;
            position: relative;
        }
        .tab-btn i {
            font-size: 1.05rem;
            color: var(--acc-faint);
            transition: color var(--acc-transition);
        }
        .tab-btn:hover {
            color: var(--acc-text-main);
            background: var(--acc-surface-subtle);
        }
        .tab-btn:hover i { color: var(--acc-brand); }
        .tab-btn.active {
            color: var(--acc-brand);
            background: var(--acc-brand-light);
        }
        .tab-btn.active i { color: var(--acc-brand); }
        .tab-btn .tab-badge-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--acc-warning);
            margin-left: 2px;
        }

        /* ── Tab Panes ── */
        .tab-pane {
            display: none;
            animation: accFadeIn 0.25s ease;
        }
        .tab-pane.active { display: block; }
        @keyframes accFadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Modern Card ── */
        .card-panel {
            background: var(--acc-surface-card);
            border: 1px solid var(--acc-border);
            border-radius: var(--acc-radius-xl);
            box-shadow: var(--acc-shadow-card);
            padding: 32px;
            margin-bottom: 28px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .card-header-clean {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 28px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--acc-border-subtle);
        }
        .card-header-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--acc-text-main);
            letter-spacing: -0.01em;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-header-title i {
            color: var(--acc-brand);
            font-size: 1.25rem;
        }
        .card-header-desc {
            font-size: 0.9rem;
            color: var(--acc-muted);
            margin: 0;
        }

        /* ── Forms & Input Grid ── */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 22px;
            margin-bottom: 24px;
        }
        .form-grid.grid-1col { grid-template-columns: 1fr; }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        .form-label {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--acc-text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .form-label .req {
            color: var(--acc-danger);
            font-weight: bold;
        }
        .input-group-custom {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-icon-left {
            position: absolute;
            left: 16px;
            color: var(--acc-faint);
            font-size: 1rem;
            pointer-events: none;
            transition: color var(--acc-transition);
        }
        .form-input-custom {
            width: 100%;
            padding: 13px 16px 13px 44px;
            background: var(--acc-surface-subtle);
            border: 1px solid var(--acc-border);
            border-radius: var(--acc-radius-md);
            font-family: inherit;
            font-size: 0.95rem;
            color: var(--acc-text-main);
            transition: all var(--acc-transition);
        }
        .form-input-custom:focus {
            outline: none;
            background: var(--acc-surface);
            border-color: var(--acc-brand);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.16);
        }
        .form-input-custom:focus + .input-icon-left,
        .input-group-custom:focus-within .input-icon-left {
            color: var(--acc-brand);
        }
        .form-input-custom:disabled,
        .form-input-custom[readonly] {
            opacity: 0.72;
            cursor: not-allowed;
            background: var(--acc-surface-subtle);
            border-color: var(--acc-border-subtle);
        }
        .input-btn-toggle {
            position: absolute;
            right: 14px;
            background: transparent;
            border: none;
            color: var(--acc-faint);
            cursor: pointer;
            padding: 4px;
            font-size: 1rem;
            transition: color var(--acc-transition);
        }
        .input-btn-toggle:hover { color: var(--acc-text-main); }

        .field-hint {
            font-size: 0.8rem;
            color: var(--acc-muted);
            margin-top: 2px;
        }

        /* ── Action Buttons ── */
        .form-actions-clean {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 14px;
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid var(--acc-border-subtle);
        }
        .btn-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 28px;
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            color: #ffffff;
            border: none;
            border-radius: var(--acc-radius-md);
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.28);
            transition: all var(--acc-transition);
            text-decoration: none;
        }
        .btn-brand:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.38);
            background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
        }
        .btn-brand:active { transform: translateY(0); }

        .btn-secondary-clean {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 22px;
            background: var(--acc-surface-subtle);
            color: var(--acc-text-body);
            border: 1px solid var(--acc-border);
            border-radius: var(--acc-radius-md);
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--acc-transition);
            text-decoration: none;
        }
        .btn-secondary-clean:hover {
            background: var(--acc-surface);
            color: var(--acc-text-main);
            border-color: var(--acc-border-hover);
        }

        /* ── Password Strength UI ── */
        .password-strength-box {
            background: var(--acc-surface-subtle);
            border: 1px solid var(--acc-border-subtle);
            border-radius: var(--acc-radius-md);
            padding: 16px 20px;
            margin-top: 10px;
        }
        .pwd-strength-bar {
            height: 6px;
            background: var(--acc-border);
            border-radius: var(--acc-radius-pill);
            overflow: hidden;
            margin-bottom: 12px;
            position: relative;
        }
        .pwd-strength-fill {
            height: 100%;
            width: 0%;
            border-radius: var(--acc-radius-pill);
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        .pwd-rules-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px 16px;
        }
        .pwd-rule-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
            color: var(--acc-muted);
            transition: color var(--acc-transition);
        }
        .pwd-rule-item i {
            font-size: 0.85rem;
            color: var(--acc-faint);
            width: 14px;
        }
        .pwd-rule-item.valid {
            color: var(--acc-success);
            font-weight: 600;
        }
        .pwd-rule-item.valid i {
            color: var(--acc-success);
        }

        /* ── Documents & Verification Aesthetics ── */
        .verified-hero-box {
            background: linear-gradient(135deg, rgba(5, 150, 105, 0.08) 0%, rgba(16, 185, 129, 0.03) 100%);
            border: 1px solid var(--acc-success-border);
            border-radius: var(--acc-radius-xl);
            padding: 28px;
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 24px;
        }
        .verified-shield-icon {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: var(--acc-success-light);
            color: var(--acc-success);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            flex-shrink: 0;
            border: 2px solid var(--acc-success-border);
        }
        .verified-info h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--acc-text-main);
            margin-bottom: 6px;
        }
        .verified-info p {
            color: var(--acc-muted);
            font-size: 0.94rem;
            line-height: 1.5;
            margin-bottom: 12px;
        }

        .upload-dropzone {
            border: 2px dashed var(--acc-brand-border);
            background: var(--acc-brand-light);
            border-radius: var(--acc-radius-xl);
            padding: 36px 24px;
            text-align: center;
            cursor: pointer;
            transition: all var(--acc-transition);
            position: relative;
        }
        .upload-dropzone:hover, .upload-dropzone.dragover {
            border-color: var(--acc-brand);
            background: rgba(79, 70, 229, 0.08);
            transform: scale(1.005);
        }
        .upload-dropzone-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--acc-surface);
            color: var(--acc-brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin: 0 auto 16px;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
        }
        .upload-dropzone h4 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--acc-text-main);
            margin-bottom: 6px;
        }
        .upload-dropzone p {
            font-size: 0.88rem;
            color: var(--acc-muted);
            max-width: 480px;
            margin: 0 auto 18px;
        }
        .file-chosen-info {
            display: none;
            margin-top: 14px;
            padding: 10px 16px;
            border-radius: var(--acc-radius-md);
            background: var(--acc-surface);
            border: 1px solid var(--acc-border);
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--acc-text-main);
        }

        /* ── Info Cards & Guidelines ── */
        .info-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
            margin-top: 24px;
        }
        .info-card-item {
            background: var(--acc-surface-subtle);
            border: 1px solid var(--acc-border-subtle);
            border-radius: var(--acc-radius-lg);
            padding: 20px;
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }
        .info-card-item i {
            font-size: 1.4rem;
            color: var(--acc-brand);
            flex-shrink: 0;
            margin-top: 2px;
        }
        .info-card-item h5 {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--acc-text-main);
            margin-bottom: 4px;
        }
        .info-card-item p {
            font-size: 0.84rem;
            color: var(--acc-muted);
            line-height: 1.45;
            margin: 0;
        }

        /* ── Responsive Media Queries ── */
        @media (max-width: 768px) {
            .hero-body {
                padding: 0 20px 24px;
                flex-direction: column;
                align-items: flex-start;
            }
            .hero-user-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
                margin-top: -50px;
            }
            .avatar-wrap {
                width: 96px;
                height: 96px;
            }
            .hero-name { font-size: 1.45rem; }
            .form-grid { grid-template-columns: 1fr; }
            .pwd-rules-grid { grid-template-columns: 1fr; }
            .card-panel { padding: 22px 18px; }
            .verified-hero-box {
                flex-direction: column;
                text-align: center;
            }
            .hero-quick-stats {
                width: 100%;
                justify-content: space-between;
            }
        }
        @media (max-width: 480px) {
            .account-wrapper { padding: 16px 12px 48px; }
            .card-panel { padding: 18px 14px; }
        }

        /* ── Passkeys Management Styling ── */
        .passkeys-list-wrap {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 10px;
        }
        .passkey-item-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            background: var(--acc-surface-subtle);
            border: 1px solid var(--acc-border-subtle);
            border-radius: 14px;
            transition: border-color 0.2s ease, transform 0.2s ease;
            gap: 16px;
            flex-wrap: wrap;
        }
        .passkey-item-card:hover {
            border-color: var(--acc-brand-border);
            transform: translateY(-1px);
        }
        .passkey-item-info {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 220px;
        }
        .passkey-icon-bubble {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--acc-brand-light);
            color: var(--acc-brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .passkey-meta-title {
            font-weight: 700;
            color: var(--acc-text-main);
            font-size: 0.95rem;
            margin-bottom: 4px;
        }
        .passkey-meta-sub {
            font-size: 0.8rem;
            color: var(--acc-muted);
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }
        .passkey-meta-sub span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .passkey-item-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-pk-action {
            padding: 7px 13px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            border: 1px solid var(--acc-border);
            background: var(--acc-surface);
            color: var(--acc-text-body);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .btn-pk-action:hover {
            border-color: var(--acc-brand);
            color: var(--acc-brand);
        }
        .btn-pk-action.btn-delete:hover {
            border-color: #e11d48;
            color: #e11d48;
            background: #fff1f2;
        }

        /* Passkey Modal Styles */
        .pk-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .pk-modal-card {
            background: var(--acc-surface);
            border: 1px solid var(--acc-border);
            border-radius: 20px;
            max-width: 480px;
            width: 100%;
            padding: 26px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: pkModalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes pkModalPop {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .pk-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .pk-modal-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--acc-text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .pk-modal-close {
            background: transparent;
            border: none;
            font-size: 1.2rem;
            color: var(--acc-muted);
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .pk-modal-close:hover {
            color: var(--acc-text-main);
            background: var(--acc-surface-subtle);
        }
        .pk-modal-body {
            margin-bottom: 22px;
            color: var(--acc-text-body);
            font-size: 0.92rem;
            line-height: 1.5;
        }
        .pk-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
    </style>
</head>
<body>

    <!-- Main Navigation Header -->
    <?php include 'employee_header.php'; ?>

    <main class="account-wrapper">

        <!-- Flash Messages & Alerts -->
        <?php if (!empty($message)): ?>
            <div class="alert-box alert-<?php echo htmlspecialchars($messageType); ?>" role="alert">
                <?php if ($messageType === 'success'): ?>
                    <i class="fa-solid fa-circle-check alert-icon"></i>
                <?php elseif ($messageType === 'error'): ?>
                    <i class="fa-solid fa-circle-exclamation alert-icon"></i>
                <?php elseif ($messageType === 'warning'): ?>
                    <i class="fa-solid fa-triangle-exclamation alert-icon"></i>
                <?php else: ?>
                    <i class="fa-solid fa-circle-info alert-icon"></i>
                <?php endif; ?>
                <div style="flex: 1;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <button type="button" class="alert-close" onclick="this.parentElement.remove();" aria-label="Close notification">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- ========================================
             PROFILE HERO BANNER
             ======================================== -->
        <div class="profile-hero">
            <div class="hero-banner">
                <i class="fa-solid fa-fingerprint hero-banner-pattern"></i>
            </div>
            
            <div class="hero-body">
                <div class="hero-user-section">
                    <div class="avatar-wrap">
                        <?php if (!empty($employee['avatar'])): ?>
                            <img src="/assets/uploads/avatars/<?php echo htmlspecialchars($employee['avatar']); ?>" 
                                 alt="<?php echo $fullName; ?>" 
                                 class="avatar-img" 
                                 id="profileHeroAvatar">
                        <?php else: ?>
                            <div class="avatar-initials" id="profileHeroAvatarFallback">
                                <?php echo $initials; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Quick Trigger Button for Avatar File Picker -->
                        <label for="avatarInputFile" class="avatar-edit-btn" title="Change profile photo">
                            <i class="fa-solid fa-camera"></i>
                        </label>
                    </div>

                    <div class="hero-info">
                        <div class="hero-name-row">
                            <h1 class="hero-name"><?php echo $fullName; ?></h1>
                            
                            <!-- Identity Status Pill -->
                            <?php if ($isVerified): ?>
                                <span class="badge-pill badge-verified" title="Identity verified and approved by HR">
                                    <i class="fa-solid fa-circle-check"></i> Identity Verified
                                </span>
                            <?php elseif ($isPending): ?>
                                <span class="badge-pill badge-pending" title="Aadhar card is currently under review">
                                    <i class="fa-solid fa-clock"></i> Verification In Review
                                </span>
                            <?php else: ?>
                                <span class="badge-pill badge-unverified" title="Identity verification is required">
                                    <i class="fa-solid fa-shield-halved"></i> Verification Required
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="hero-meta-row">
                            <span class="hero-meta-item" title="Employee ID">
                                <i class="fa-solid fa-id-badge"></i> 
                                <?php echo htmlspecialchars($employee['employee_id'] ?: 'EMP-' . str_pad($employee['id'], 3, '0', STR_PAD_LEFT)); ?>
                            </span>
                            <span class="hero-meta-item" title="Department">
                                <i class="fa-solid fa-building"></i> 
                                <?php echo htmlspecialchars($employee['department'] ?: 'Operations'); ?>
                            </span>
                            <span class="hero-meta-item" title="Designation">
                                <i class="fa-solid fa-briefcase"></i> 
                                <?php echo htmlspecialchars($employee['position'] ?: 'Team Member'); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Right Quick Stats -->
                <div class="hero-quick-stats">
                    <div class="quick-stat-chip">
                        <span class="stat-label">Member Since</span>
                        <span class="stat-val"><?php echo $joinedDate; ?></span>
                    </div>
                    <div class="quick-stat-chip">
                        <span class="stat-label">Account Status</span>
                        <span class="stat-val" style="color: var(--acc-success); text-transform: capitalize;">
                            <i class="fa-solid fa-circle" style="font-size: 0.55rem; vertical-align: middle; margin-right: 4px;"></i>
                            <?php echo htmlspecialchars($employee['status'] ?: 'Active'); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================
             NAVIGATION TABS
             ======================================== -->
        <nav class="account-tabs" aria-label="Account Settings Sections">
            <button type="button" class="tab-btn <?php echo ($activeTab === 'profile') ? 'active' : ''; ?>" data-target="tab-profile">
                <i class="fa-solid fa-user-pen"></i>
                <span>Profile Information</span>
            </button>

            <button type="button" class="tab-btn <?php echo ($activeTab === 'security') ? 'active' : ''; ?>" data-target="tab-security">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Security &amp; Password</span>
            </button>

            <button type="button" class="tab-btn <?php echo ($activeTab === 'verification') ? 'active' : ''; ?>" data-target="tab-verification">
                <i class="fa-solid fa-id-card"></i>
                <span>Identity &amp; Compliance</span>
                <?php if (!$isVerified): ?>
                    <span class="tab-badge-dot" title="Action required"></span>
                <?php endif; ?>
            </button>

            <button type="button" class="tab-btn <?php echo ($activeTab === 'activity') ? 'active' : ''; ?>" data-target="tab-activity">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Account Activity</span>
            </button>
        </nav>

        <!-- ========================================
             TAB 1: PROFILE INFORMATION
             ======================================== -->
        <div class="tab-pane <?php echo ($activeTab === 'profile') ? 'active' : ''; ?>" id="tab-profile">
            <div class="card-panel">
                <div class="card-header-clean">
                    <div>
                        <h2 class="card-header-title">
                            <i class="fa-solid fa-address-card"></i>
                            Personal &amp; Contact Details
                        </h2>
                        <p class="card-header-desc">Update your personal contact information and workspace profile.</p>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="profileForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <!-- Hidden File Input triggered by Avatar Button or File Picker -->
                    <input type="file" 
                           name="avatar" 
                           id="avatarInputFile" 
                           accept="image/jpeg, image/png, image/gif, image/webp" 
                           style="display: none;" 
                           onchange="previewProfileAvatar(this);">

                    <div class="form-grid">
                        <!-- First Name -->
                        <div class="form-group">
                            <label class="form-label" for="first_name">
                                First Name <span class="req">*</span>
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-user input-icon-left"></i>
                                <input type="text" 
                                       id="first_name" 
                                       name="first_name" 
                                       class="form-input-custom" 
                                       value="<?php echo htmlspecialchars($employee['first_name'] ?? ''); ?>" 
                                       required>
                            </div>
                        </div>

                        <!-- Last Name -->
                        <div class="form-group">
                            <label class="form-label" for="last_name">
                                Last Name <span class="req">*</span>
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-user input-icon-left"></i>
                                <input type="text" 
                                       id="last_name" 
                                       name="last_name" 
                                       class="form-input-custom" 
                                       value="<?php echo htmlspecialchars($employee['last_name'] ?? ''); ?>" 
                                       required>
                            </div>
                        </div>

                        <!-- Email Address -->
                        <div class="form-group">
                            <label class="form-label" for="email">
                                Work Email Address <span class="req">*</span>
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-envelope input-icon-left"></i>
                                <input type="email" 
                                       id="email" 
                                       name="email" 
                                       class="form-input-custom" 
                                       value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>" 
                                       required>
                            </div>
                            <span class="field-hint">Used for portal notifications, project task assignments, and security alerts.</span>
                        </div>

                        <!-- Phone Number -->
                        <div class="form-group">
                            <label class="form-label" for="phone">
                                Phone Number
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-phone input-icon-left"></i>
                                <input type="tel" 
                                       id="phone" 
                                       name="phone" 
                                       class="form-input-custom" 
                                       placeholder="+91 98765 43210" 
                                       value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>">
                            </div>
                            <span class="field-hint">Your active contact number for urgent communications.</span>
                        </div>

                        <!-- Employee ID (Read-only) -->
                        <div class="form-group">
                            <label class="form-label">
                                Employee Code <i class="fa-solid fa-lock" style="font-size: 0.75rem; color: var(--acc-faint);"></i>
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-id-card-clip input-icon-left"></i>
                                <input type="text" 
                                       class="form-input-custom" 
                                       value="<?php echo htmlspecialchars($employee['employee_id'] ?: 'EMP-' . str_pad($employee['id'], 3, '0', STR_PAD_LEFT)); ?>" 
                                       disabled>
                            </div>
                            <span class="field-hint">Official corporate identifier assigned by HR.</span>
                        </div>

                        <!-- Department (Read-only) -->
                        <div class="form-group">
                            <label class="form-label">
                                Department <i class="fa-solid fa-lock" style="font-size: 0.75rem; color: var(--acc-faint);"></i>
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-building-user input-icon-left"></i>
                                <input type="text" 
                                       class="form-input-custom" 
                                       value="<?php echo htmlspecialchars($employee['department'] ?: 'Operations'); ?>" 
                                       disabled>
                            </div>
                            <span class="field-hint">Department assignment is managed by your administrator.</span>
                        </div>

                        <!-- Position (Read-only) -->
                        <div class="form-group">
                            <label class="form-label">
                                Role / Designation <i class="fa-solid fa-lock" style="font-size: 0.75rem; color: var(--acc-faint);"></i>
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-user-tie input-icon-left"></i>
                                <input type="text" 
                                       class="form-input-custom" 
                                       value="<?php echo htmlspecialchars($employee['position'] ?: 'Team Member'); ?>" 
                                       disabled>
                            </div>
                        </div>

                        <!-- Hire Date (Read-only) -->
                        <div class="form-group">
                            <label class="form-label">
                                Joining Date <i class="fa-solid fa-lock" style="font-size: 0.75rem; color: var(--acc-faint);"></i>
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-calendar-check input-icon-left"></i>
                                <input type="text" 
                                       class="form-input-custom" 
                                       value="<?php echo $hireDate; ?>" 
                                       disabled>
                            </div>
                        </div>
                    </div>

                    <!-- Avatar Preview Banner if new file selected -->
                    <div id="avatarSelectedAlert" style="display: none; margin-bottom: 20px;" class="alert-box alert-info">
                        <i class="fa-solid fa-image alert-icon"></i>
                        <div style="flex: 1;">
                            <strong>New photo selected:</strong> <span id="avatarFileName"></span>. Click "Save Changes" below to update your profile picture.
                        </div>
                    </div>

                    <div class="form-actions-clean">
                        <label for="avatarInputFile" class="btn-secondary-clean" style="cursor: pointer;">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            Choose Avatar Photo
                        </label>
                        <button type="submit" class="btn-brand">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ========================================
             TAB 2: SECURITY & PASSWORD
             ======================================== -->
        <div class="tab-pane <?php echo ($activeTab === 'security') ? 'active' : ''; ?>" id="tab-security">
            <div class="card-panel">
                <div class="card-header-clean">
                    <div>
                        <h2 class="card-header-title">
                            <i class="fa-solid fa-key"></i>
                            Password &amp; Credentials
                        </h2>
                        <p class="card-header-desc">Ensure your account is protected with a strong, distinct password.</p>
                    </div>
                </div>

                <form method="POST" id="passwordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="update_password" value="1">

                    <div class="form-grid grid-1col" style="max-width: 680px;">
                        <!-- Current Password -->
                        <div class="form-group">
                            <label class="form-label" for="current_password">
                                Current Password <span class="req">*</span>
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-lock input-icon-left"></i>
                                <input type="password" 
                                       id="current_password" 
                                       name="current_password" 
                                       class="form-input-custom" 
                                       placeholder="Enter your current password" 
                                       required>
                                <button type="button" class="input-btn-toggle" onclick="togglePasswordVis('current_password', this);" aria-label="Toggle password visibility">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- New Password -->
                        <div class="form-group">
                            <label class="form-label" for="new_password">
                                New Password <span class="req">*</span>
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-shield-keyhole input-icon-left"></i>
                                <input type="password" 
                                       id="new_password" 
                                       name="new_password" 
                                       class="form-input-custom" 
                                       placeholder="Create a strong new password" 
                                       oninput="checkPasswordStrength(this.value);" 
                                       required>
                                <button type="button" class="input-btn-toggle" onclick="togglePasswordVis('new_password', this);" aria-label="Toggle password visibility">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>

                            <!-- Interactive Strength Indicator & Requirements -->
                            <div class="password-strength-box">
                                <div class="pwd-strength-bar">
                                    <div class="pwd-strength-fill" id="pwdStrengthFill"></div>
                                </div>
                                <div class="pwd-rules-grid">
                                    <div class="pwd-rule-item" id="ruleLength">
                                        <i class="fa-solid fa-circle-dot"></i>
                                        <span>At least 8 characters</span>
                                    </div>
                                    <div class="pwd-rule-item" id="ruleUpper">
                                        <i class="fa-solid fa-circle-dot"></i>
                                        <span>One uppercase letter (A-Z)</span>
                                    </div>
                                    <div class="pwd-rule-item" id="ruleLower">
                                        <i class="fa-solid fa-circle-dot"></i>
                                        <span>One lowercase letter (a-z)</span>
                                    </div>
                                    <div class="pwd-rule-item" id="ruleNumber">
                                        <i class="fa-solid fa-circle-dot"></i>
                                        <span>One number (0-9)</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Confirm Password -->
                        <div class="form-group">
                            <label class="form-label" for="confirm_password">
                                Confirm New Password <span class="req">*</span>
                            </label>
                            <div class="input-group-custom">
                                <i class="fa-solid fa-check-double input-icon-left"></i>
                                <input type="password" 
                                       id="confirm_password" 
                                       name="confirm_password" 
                                       class="form-input-custom" 
                                       placeholder="Re-enter your new password" 
                                       oninput="checkPasswordMatch();" 
                                       required>
                                <button type="button" class="input-btn-toggle" onclick="togglePasswordVis('confirm_password', this);" aria-label="Toggle password visibility">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <span id="pwdMatchStatus" class="field-hint"></span>
                        </div>
                    </div>

                    <div class="form-actions-clean">
                        <button type="submit" class="btn-brand">
                            <i class="fa-solid fa-shield-check"></i>
                            Update Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- ========================================
                 PASSKEYS & BIOMETRIC AUTHENTICATION CARD
                 ======================================== -->
            <div class="card-panel" style="margin-top: 24px;">
                <div class="card-header-clean" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h2 class="card-header-title">
                            <i class="fa-solid fa-fingerprint" style="color: var(--acc-brand);"></i>
                            Passkeys &amp; Biometric Sign-in
                        </h2>
                        <p class="card-header-desc">
                            Use your fingerprint, Face ID, Windows Hello, or device PIN to sign in securely without entering your password.
                        </p>
                    </div>
                    <button type="button" class="btn-brand" id="openAddPasskeyBtn" style="padding: 10px 18px; font-size: 0.88rem;">
                        <i class="fa-solid fa-plus"></i> Add Passkey
                    </button>
                </div>

                <div id="passkeyNotification" style="display: none; margin-bottom: 16px;"></div>

                <!-- Passkey List Container -->
                <div id="passkeysListContainer" class="passkeys-list-wrap">
                    <div style="text-align: center; padding: 32px 16px; color: var(--acc-muted);">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size: 1.5rem; color: var(--acc-brand); margin-bottom: 10px;"></i>
                        <div>Loading registered passkeys...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================
             TAB 3: IDENTITY & COMPLIANCE (AADHAR)
             ======================================== -->
        <div class="tab-pane <?php echo ($activeTab === 'verification') ? 'active' : ''; ?>" id="tab-verification">
            <div class="card-panel">
                <div class="card-header-clean">
                    <div>
                        <h2 class="card-header-title">
                            <i class="fa-solid fa-shield-halved"></i>
                            Identity Verification &amp; KYC
                        </h2>
                        <p class="card-header-desc">Official identity proof compliance required by corporate governance policies.</p>
                    </div>
                </div>

                <?php if ($isVerified): ?>
                    <!-- VERIFIED STATE: NO BANNER OR POP-UP! ELEGANT, UNINTRUSIVE CONFIRMATION -->
                    <div class="verified-hero-box">
                        <div class="verified-shield-icon">
                            <i class="fa-solid fa-shield-check"></i>
                        </div>
                        <div class="verified-info" style="flex: 1;">
                            <h3>Your Account is Fully Verified</h3>
                            <p>
                                Your Aadhar identity verification has been reviewed, approved, and registered by our HR Compliance team. 
                                No further action is required from you.
                            </p>
                            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                <span class="badge-pill badge-verified">
                                    <i class="fa-solid fa-circle-check"></i> Verified &amp; Compliant
                                </span>
                                <?php if (!empty($employee['aadhar_image'])): ?>
                                    <a href="employee_account?action=view_aadhar" 
                                       target="_blank" 
                                       class="btn-secondary-clean" 
                                       style="padding: 6px 14px; font-size: 0.85rem;">
                                        <i class="fa-solid fa-file-lines"></i> View Uploaded Document
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                <?php elseif ($isPending): ?>
                    <!-- PENDING STATE: Reassuring informational status -->
                    <div class="alert-box alert-warning">
                        <i class="fa-solid fa-hourglass-half alert-icon"></i>
                        <div style="flex: 1;">
                            <strong style="display: block; font-size: 1rem; margin-bottom: 4px;">Verification Under Review</strong>
                            Your Aadhar card has been successfully uploaded and is currently pending verification by an administrator. 
                            You will receive confirmation once the document has been approved.
                        </div>
                        <?php if (!empty($employee['aadhar_image'])): ?>
                            <a href="employee_account?action=view_aadhar" 
                               target="_blank" 
                               class="btn-secondary-clean" 
                               style="padding: 6px 12px; font-size: 0.82rem; white-space: nowrap;">
                                <i class="fa-solid fa-eye"></i> View Card
                            </a>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- UNVERIFIED STATE: Clean upload interface -->
                    <div class="alert-box alert-warning" style="margin-bottom: 24px;">
                        <i class="fa-solid fa-triangle-exclamation alert-icon"></i>
                        <div>
                            <strong>Identity Verification Required:</strong> 
                            To comply with company policy and maintain full access to workspace services, please upload your official Aadhar card.
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="aadharUploadForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="upload_aadhar" value="1">

                        <div class="upload-dropzone" id="aadharDropzone" onclick="document.getElementById('aadharFileInput').click();">
                            <div class="upload-dropzone-icon">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                            </div>
                            <h4>Upload Clear Photo or PDF of Aadhar Card</h4>
                            <p>Drag and drop your file here, or click to browse from your device. Supported file types: JPEG, PNG, WEBP, or PDF (Max size: 5MB).</p>
                            
                            <input type="file" 
                                   name="aadhar_image" 
                                   id="aadharFileInput" 
                                   accept="image/jpeg, image/png, image/webp, application/pdf" 
                                   style="display: none;" 
                                   required 
                                   onchange="handleAadharFileChosen(this);">

                            <span class="btn-brand" style="pointer-events: none; padding: 10px 22px; font-size: 0.9rem;">
                                <i class="fa-solid fa-folder-open"></i> Select Aadhar File
                            </span>

                            <div class="file-chosen-info" id="aadharChosenInfo">
                                <i class="fa-solid fa-file-circle-check" style="color: var(--acc-brand); margin-right: 6px;"></i>
                                <span id="aadharChosenName"></span>
                            </div>
                        </div>

                        <div class="form-actions-clean">
                            <button type="submit" class="btn-brand" id="submitAadharBtn">
                                <i class="fa-solid fa-arrow-up-from-bracket"></i>
                                Submit for Verification
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <!-- Informational Compliance Guidelines -->
                <div class="info-cards-grid">
                    <div class="info-card-item">
                        <i class="fa-solid fa-lock"></i>
                        <div>
                            <h5>Encrypted &amp; Secure</h5>
                            <p>Your identity documents are stored in restricted directories with encrypted access controls.</p>
                        </div>
                    </div>
                    <div class="info-card-item">
                        <i class="fa-solid fa-user-shield"></i>
                        <div>
                            <h5>HR Compliance</h5>
                            <p>Used strictly for employee payroll records, taxation compliance, and government identity regulations.</p>
                        </div>
                    </div>
                    <div class="info-card-item">
                        <i class="fa-solid fa-file-circle-check"></i>
                        <div>
                            <h5>Clear Legibility</h5>
                            <p>Please ensure all 12 digits of your Aadhar number and your legal name are crisp and legible.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================
             TAB 4: ACCOUNT ACTIVITY
             ======================================== -->
        <div class="tab-pane <?php echo ($activeTab === 'activity') ? 'active' : ''; ?>" id="tab-activity">
            <div class="card-panel">
                <div class="card-header-clean">
                    <div>
                        <h2 class="card-header-title">
                            <i class="fa-solid fa-shield-heart"></i>
                            Security &amp; Session Status
                        </h2>
                        <p class="card-header-desc">Overview of your active workspace session and security parameters.</p>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="info-card-item">
                        <i class="fa-solid fa-desktop"></i>
                        <div>
                            <h5>Active Session</h5>
                            <p>Authenticated as <strong><?php echo $fullName; ?></strong> via Secure PHP Session.</p>
                        </div>
                    </div>
                    <div class="info-card-item">
                        <i class="fa-solid fa-stopwatch"></i>
                        <div>
                            <h5>Inactivity Timeout</h5>
                            <p>Automatic logout enabled after 30 minutes of idle inactivity.</p>
                        </div>
                    </div>
                    <div class="info-card-item">
                        <i class="fa-solid fa-shield-virus"></i>
                        <div>
                            <h5>CSRF Protection</h5>
                            <p>Tokenized one-time challenge validation on all form submissions.</p>
                        </div>
                    </div>
                    <div class="info-card-item">
                        <i class="fa-solid fa-key"></i>
                        <div>
                            <h5>Password Encryption</h5>
                            <p>One-way BCRYPT salted hashing algorithm protects your password.</p>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--acc-border-subtle); display: flex; justify-content: space-between; align-items: center; flex-wrap: gap; gap: 16px;">
                    <span style="font-size: 0.88rem; color: var(--acc-muted);">
                        Need assistance updating confidential information? Contact <a href="mailto:admin@cloudaspire.in" style="color: var(--acc-brand); text-decoration: none; font-weight: 600;">HR Administration</a>.
                    </span>
                    <a href="employee_logout" class="btn-secondary-clean" style="color: var(--acc-danger); border-color: var(--acc-danger-border);">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
                    </a>
                </div>
            </div>
        </div>

    </main>

    <!-- ========================================
         JAVASCRIPT LOGIC
         ======================================== -->
    <script>
        // Tab Switching Logic
        document.querySelectorAll('.tab-btn').forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                
                // Set active tab button
                document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');

                // Show target pane
                document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
                const targetPane = document.getElementById(targetId);
                if (targetPane) {
                    targetPane.classList.add('active');
                }
            });
        });

        // Toggle Password Field Visibility
        function togglePasswordVis(fieldId, btnElement) {
            const input = document.getElementById(fieldId);
            if (!input) return;
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            const icon = btnElement.querySelector('i');
            if (icon) {
                icon.className = isPassword ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            }
        }

        // Live Password Strength & Requirements Checker
        function checkPasswordStrength(pwd) {
            const ruleLen   = document.getElementById('ruleLength');
            const ruleUpper = document.getElementById('ruleUpper');
            const ruleLower = document.getElementById('ruleLower');
            const ruleNum   = document.getElementById('ruleNumber');
            const fill      = document.getElementById('pwdStrengthFill');

            let score = 0;
            const hasLen   = pwd.length >= 8;
            const hasUpper = /[A-Z]/.test(pwd);
            const hasLower = /[a-z]/.test(pwd);
            const hasNum   = /[0-9]/.test(pwd);

            if (hasLen)   { score++; ruleLen?.classList.add('valid'); }   else { ruleLen?.classList.remove('valid'); }
            if (hasUpper) { score++; ruleUpper?.classList.add('valid'); } else { ruleUpper?.classList.remove('valid'); }
            if (hasLower) { score++; ruleLower?.classList.add('valid'); } else { ruleLower?.classList.remove('valid'); }
            if (hasNum)   { score++; ruleNum?.classList.add('valid'); }   else { ruleNum?.classList.remove('valid'); }

            if (!fill) return;
            if (pwd.length === 0) {
                fill.style.width = '0%';
                fill.style.backgroundColor = 'transparent';
            } else if (score <= 1) {
                fill.style.width = '25%';
                fill.style.backgroundColor = 'var(--acc-danger)';
            } else if (score <= 3) {
                fill.style.width = '65%';
                fill.style.backgroundColor = 'var(--acc-warning)';
            } else {
                fill.style.width = '100%';
                fill.style.backgroundColor = 'var(--acc-success)';
            }

            checkPasswordMatch();
        }

        // Check Password Confirmation Match
        function checkPasswordMatch() {
            const newPwd = document.getElementById('new_password')?.value || '';
            const confirmPwd = document.getElementById('confirm_password')?.value || '';
            const statusEl = document.getElementById('pwdMatchStatus');

            if (!statusEl) return;
            if (confirmPwd.length === 0) {
                statusEl.textContent = '';
                return;
            }

            if (newPwd === confirmPwd) {
                statusEl.innerHTML = '<span style="color: var(--acc-success); font-weight: 600;"><i class="fa-solid fa-check"></i> Passwords match</span>';
            } else {
                statusEl.innerHTML = '<span style="color: var(--acc-danger); font-weight: 600;"><i class="fa-solid fa-xmark"></i> Passwords do not match</span>';
            }
        }

        // Live Avatar File Preview
        function previewProfileAvatar(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                reader.onload = function(e) {
                    const heroAvatar = document.getElementById('profileHeroAvatar');
                    const heroFallback = document.getElementById('profileHeroAvatarFallback');
                    
                    if (heroAvatar) {
                        heroAvatar.src = e.target.result;
                    } else if (heroFallback) {
                        heroFallback.outerHTML = `<img src="${e.target.result}" class="avatar-img" id="profileHeroAvatar" alt="Preview">`;
                    }

                    const alertBox = document.getElementById('avatarSelectedAlert');
                    const fileNameEl = document.getElementById('avatarFileName');
                    if (alertBox && fileNameEl) {
                        fileNameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                        alertBox.style.display = 'flex';
                    }
                };
                reader.readAsDataURL(file);
            }
        }

        // Aadhar File Dropzone & Picker Handling
        function handleAadharFileChosen(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const infoBox = document.getElementById('aadharChosenInfo');
                const nameBox = document.getElementById('aadharChosenName');
                if (infoBox && nameBox) {
                    nameBox.textContent = file.name + ' (' + (file.size / (1024*1024)).toFixed(2) + ' MB)';
                    infoBox.style.display = 'inline-block';
                }
            }
        }

        // Drag & Drop for Aadhar Dropzone
        const dropzone = document.getElementById('aadharDropzone');
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(eventName => {
                dropzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('dragover');
                });
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('dragover');
                });
            });

            dropzone.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                const files = dt.files;
                const fileInput = document.getElementById('aadharFileInput');
                if (fileInput && files.length > 0) {
                    fileInput.files = files;
                    handleAadharFileChosen(fileInput);
                }
            });
        }
    </script>

    <!-- Passkey / WebAuthn Modals -->
    <!-- 1. Add Passkey Modal -->
    <div class="pk-modal-overlay" id="addPasskeyModal" role="dialog" aria-modal="true">
        <div class="pk-modal-card">
            <div class="pk-modal-header">
                <div class="pk-modal-title">
                    <i class="fa-solid fa-fingerprint" style="color: var(--acc-brand);"></i>
                    Register New Passkey
                </div>
                <button type="button" class="pk-modal-close" onclick="closePkModal('addPasskeyModal');">&times;</button>
            </div>
            <div class="pk-modal-body">
                <p style="margin-bottom: 14px;">
                    Use your device's biometric sensor (Fingerprint, Touch ID, Face ID, Windows Hello) or security key.
                </p>
                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" for="pkDeviceName">Name this device / passkey</label>
                    <input type="text" id="pkDeviceName" class="form-input-custom" placeholder="e.g. My iPhone, Office Laptop">
                </div>
                <div id="addPkStatusMsg" style="display: none; padding: 10px 14px; border-radius: 8px; font-size: 0.85rem; margin-top: 10px;"></div>
            </div>
            <div class="pk-modal-footer">
                <button type="button" class="btn-pk-action" onclick="closePkModal('addPasskeyModal');">Cancel</button>
                <button type="button" class="btn-brand" id="startPkRegisterBtn" style="padding: 8px 18px;">
                    <i class="fa-solid fa-key"></i> Start Biometric Prompt
                </button>
            </div>
        </div>
    </div>

    <!-- 2. Rename Passkey Modal -->
    <div class="pk-modal-overlay" id="renamePasskeyModal" role="dialog" aria-modal="true">
        <div class="pk-modal-card">
            <div class="pk-modal-header">
                <div class="pk-modal-title">
                    <i class="fa-solid fa-pen-to-square" style="color: var(--acc-brand);"></i>
                    Rename Passkey
                </div>
                <button type="button" class="pk-modal-close" onclick="closePkModal('renamePasskeyModal');">&times;</button>
            </div>
            <div class="pk-modal-body">
                <input type="hidden" id="renamePkId">
                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" for="renamePkName">Device Name</label>
                    <input type="text" id="renamePkName" class="form-input-custom" required>
                </div>
                <div id="renamePkStatusMsg" style="display: none; padding: 10px 14px; border-radius: 8px; font-size: 0.85rem;"></div>
            </div>
            <div class="pk-modal-footer">
                <button type="button" class="btn-pk-action" onclick="closePkModal('renamePasskeyModal');">Cancel</button>
                <button type="button" class="btn-brand" id="savePkRenameBtn" style="padding: 8px 18px;">
                    Save Name
                </button>
            </div>
        </div>
    </div>

    <!-- 3. Remove Passkey Modal -->
    <div class="pk-modal-overlay" id="removePasskeyModal" role="dialog" aria-modal="true">
        <div class="pk-modal-card">
            <div class="pk-modal-header">
                <div class="pk-modal-title" style="color: #e11d48;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    Remove Passkey
                </div>
                <button type="button" class="pk-modal-close" onclick="closePkModal('removePasskeyModal');">&times;</button>
            </div>
            <div class="pk-modal-body">
                <input type="hidden" id="removePkId">
                <p id="removePkWarningText" style="margin-bottom: 14px;">
                    Are you sure you want to remove <strong id="removePkNameDisplay"></strong> from your account?
                </p>
                <div id="lastPkWarningBox" style="display: none; background: #fff1f2; border: 1px solid #fecdd3; color: #be123c; padding: 12px; border-radius: 10px; margin-bottom: 14px; font-size: 0.86rem;">
                    <i class="fa-solid fa-shield-exclamation"></i> <strong>This is your last registered passkey.</strong> You will still be able to log in using your password.
                </div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="form-label" for="removePkPassword">
                        Re-enter account password to confirm <span class="req">*</span>
                    </label>
                    <input type="password" id="removePkPassword" class="form-input-custom" placeholder="Current password" required>
                </div>
                <div id="removePkStatusMsg" style="display: none; padding: 10px 14px; border-radius: 8px; font-size: 0.85rem;"></div>
            </div>
            <div class="pk-modal-footer">
                <button type="button" class="btn-pk-action" onclick="closePkModal('removePasskeyModal');">Cancel</button>
                <button type="button" class="btn-pk-action btn-delete" id="confirmPkDeleteBtn" style="padding: 8px 18px; border-color: #e11d48; color: #fff; background: #e11d48;">
                    Confirm &amp; Remove
                </button>
            </div>
        </div>
    </div>

    <!-- Passkey JS Library & Account Management Controller -->
    <script src="/assets/js/passkey.js"></script>
    <script>
        let cachedPasskeys = [];

        function closePkModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.style.display = 'none';
        }

        function openPkModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.style.display = 'flex';
        }

        async function renderPasskeys() {
            const container = document.getElementById('passkeysListContainer');
            if (!container) return;

            try {
                const passkeys = await Passkey.listPasskeys();
                cachedPasskeys = passkeys;

                if (!passkeys || passkeys.length === 0) {
                    container.innerHTML = `
                        <div style="text-align: center; padding: 36px 20px; background: var(--acc-surface-subtle); border: 1.5px dashed var(--acc-border); border-radius: 16px;">
                            <i class="fa-solid fa-fingerprint" style="font-size: 2.2rem; color: var(--acc-faint); margin-bottom: 12px; display: block;"></i>
                            <h3 style="font-size: 1rem; font-weight: 700; color: var(--acc-text-main); margin-bottom: 6px;">No Passkeys Registered Yet</h3>
                            <p style="font-size: 0.86rem; color: var(--acc-muted); max-width: 420px; margin: 0 auto 16px;">
                                Add your fingerprint, Face ID, Windows Hello, or hardware key to log into your account instantly with 1-touch verification.
                            </p>
                            <button type="button" class="btn-brand" onclick="document.getElementById('openAddPasskeyBtn').click();" style="padding: 9px 20px; font-size: 0.88rem;">
                                <i class="fa-solid fa-plus"></i> Add Your First Passkey
                            </button>
                        </div>
                    `;
                    return;
                }

                let html = '';
                passkeys.forEach(pk => {
                    html += `
                        <div class="passkey-item-card">
                            <div class="passkey-item-info">
                                <div class="passkey-icon-bubble">
                                    <i class="${pk.icon || 'fa-solid fa-key'}"></i>
                                </div>
                                <div>
                                    <div class="passkey-meta-title">${escapeHtml(pk.device_name)}</div>
                                    <div class="passkey-meta-sub">
                                        <span><i class="fa-regular fa-calendar"></i> Added: ${pk.created_at}</span>
                                        <span><i class="fa-solid fa-clock-rotate-left"></i> Last used: ${pk.last_used_at}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="passkey-item-actions">
                                <button type="button" class="btn-pk-action" onclick="openRenameModal(${pk.id}, '${escapeJs(pk.device_name)}');" title="Rename device">
                                    <i class="fa-solid fa-pen-to-square"></i> Rename
                                </button>
                                <button type="button" class="btn-pk-action btn-delete" onclick="openDeleteModal(${pk.id}, '${escapeJs(pk.device_name)}');" title="Remove passkey">
                                    <i class="fa-solid fa-trash-can"></i> Remove
                                </button>
                            </div>
                        </div>
                    `;
                });

                container.innerHTML = html;

            } catch (e) {
                container.innerHTML = `
                    <div style="padding: 16px; background: #fff1f2; color: #e11d48; border-radius: 12px; font-size: 0.88rem;">
                        <i class="fa-solid fa-circle-exclamation"></i> Error loading passkeys: ${escapeHtml(e.message)}
                    </div>
                `;
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function escapeJs(str) {
            if (!str) return '';
            return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
        }

        // Open Add Passkey Modal
        document.getElementById('openAddPasskeyBtn')?.addEventListener('click', () => {
            const input = document.getElementById('pkDeviceName');
            if (input) input.value = Passkey.detectDeviceName();
            const statusMsg = document.getElementById('addPkStatusMsg');
            if (statusMsg) statusMsg.style.display = 'none';
            openPkModal('addPasskeyModal');
        });

        // Trigger Biometric Registration
        document.getElementById('startPkRegisterBtn')?.addEventListener('click', async function() {
            const btn = this;
            const nameInput = document.getElementById('pkDeviceName');
            const statusMsg = document.getElementById('addPkStatusMsg');
            const deviceName = nameInput ? nameInput.value.trim() : '';

            statusMsg.style.display = 'block';
            statusMsg.style.background = 'var(--acc-brand-light)';
            statusMsg.style.color = 'var(--acc-brand)';
            statusMsg.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Follow the biometric prompt on your device...';
            btn.disabled = true;

            try {
                const res = await Passkey.register(deviceName);
                statusMsg.style.background = '#ecfdf5';
                statusMsg.style.color = '#059669';
                statusMsg.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + (res.message || 'Passkey registered successfully!');

                setTimeout(() => {
                    closePkModal('addPasskeyModal');
                    renderPasskeys();
                    btn.disabled = false;
                }, 900);

            } catch (err) {
                statusMsg.style.background = '#fff1f2';
                statusMsg.style.color = '#e11d48';
                statusMsg.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(err.message);
                btn.disabled = false;
            }
        });

        // Open Rename Modal
        window.openRenameModal = function(id, curName) {
            document.getElementById('renamePkId').value = id;
            const input = document.getElementById('renamePkName');
            input.value = curName;
            document.getElementById('renamePkStatusMsg').style.display = 'none';
            openPkModal('renamePasskeyModal');
        };

        // Save Rename
        document.getElementById('savePkRenameBtn')?.addEventListener('click', async function() {
            const id = document.getElementById('renamePkId').value;
            const newName = document.getElementById('renamePkName').value.trim();
            const statusMsg = document.getElementById('renamePkStatusMsg');
            if (!newName) return;

            this.disabled = true;
            try {
                await Passkey.renamePasskey(id, newName);
                closePkModal('renamePasskeyModal');
                renderPasskeys();
            } catch (e) {
                statusMsg.style.display = 'block';
                statusMsg.style.background = '#fff1f2';
                statusMsg.style.color = '#e11d48';
                statusMsg.innerHTML = escapeHtml(e.message);
            }
            this.disabled = false;
        });

        // Open Delete Modal
        window.openDeleteModal = function(id, name) {
            document.getElementById('removePkId').value = id;
            document.getElementById('removePkNameDisplay').textContent = name;
            document.getElementById('removePkPassword').value = '';
            document.getElementById('removePkStatusMsg').style.display = 'none';

            const lastWarn = document.getElementById('lastPkWarningBox');
            if (lastWarn) {
                lastWarn.style.display = (cachedPasskeys.length <= 1) ? 'block' : 'none';
            }

            openPkModal('removePasskeyModal');
        };

        // Confirm Delete
        document.getElementById('confirmPkDeleteBtn')?.addEventListener('click', async function() {
            const id = document.getElementById('removePkId').value;
            const pwd = document.getElementById('removePkPassword').value;
            const statusMsg = document.getElementById('removePkStatusMsg');

            if (!pwd) {
                statusMsg.style.display = 'block';
                statusMsg.style.background = '#fff1f2';
                statusMsg.style.color = '#e11d48';
                statusMsg.innerHTML = 'Password is required to confirm deletion.';
                return;
            }

            this.disabled = true;
            try {
                await Passkey.removePasskey(id, pwd);
                closePkModal('removePasskeyModal');
                renderPasskeys();
            } catch (e) {
                statusMsg.style.display = 'block';
                statusMsg.style.background = '#fff1f2';
                statusMsg.style.color = '#e11d48';
                statusMsg.innerHTML = escapeHtml(e.message);
            }
            this.disabled = false;
        });

        // Load passkeys when Security tab is activated or on initial load if active
        document.addEventListener('DOMContentLoaded', () => {
            renderPasskeys();
        });
    </script>
</body>
</html>
