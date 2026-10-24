<?php
// Set timezone
date_default_timezone_set('Asia/Kolkata');

// Include database connection & security
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
Security::init($pdo);
Security::initSecureSession();

// Check if admin is logged in
if (!Security::isAuthenticated()) {
    header("Location: ../admin");
    exit();
}

$adminId = (int)($_SESSION['admin_id'] ?? 1);
Security::requirePermission('employees.view', $adminId);

$canCreate = Security::hasPermission('employees.create', $adminId);
$canEdit   = Security::hasPermission('employees.edit', $adminId);
$canDelete = Security::hasPermission('employees.delete', $adminId);

// -------------------------------------------------------------
// Secure Aadhaar Document Viewer Gateway (RBAC Protected)
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'view_aadhar') {
    $empId = (int)($_GET['id'] ?? 0);
    if ($empId <= 0) {
        http_response_code(400);
        die("Invalid employee identifier.");
    }
    try {
        $st = $pdo->prepare("SELECT aadhar_image, first_name, last_name FROM employees WHERE id = ?");
        $st->execute([$empId]);
        $empDoc = $st->fetch(PDO::FETCH_ASSOC);

        if (!$empDoc || empty($empDoc['aadhar_image'])) {
            http_response_code(404);
            die("No Aadhaar document found for this employee.");
        }

        $baseDir = realpath(__DIR__ . '/../assets/uploads/aadhar');
        $filePath = realpath(__DIR__ . '/../assets/uploads/aadhar/' . basename($empDoc['aadhar_image']));

        if (!$filePath || !$baseDir || !str_starts_with($filePath, $baseDir) || !file_exists($filePath)) {
            http_response_code(404);
            die("Document not found on storage.");
        }

        Security::logAudit('view_employee_aadhar', 'employee', $empId, [
            'employee_name' => trim($empDoc['first_name'] . ' ' . $empDoc['last_name'])
        ], $adminId);

        $mime = mime_content_type($filePath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="aadhaar_emp_' . $empId . '.' . pathinfo($filePath, PATHINFO_EXTENSION) . '"');
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

// Database schema is maintained via migrations (database/migration_staff_tool.sql)

// Page variables
$pageTitle = 'Employee Directory';
$currentPage = 'employee';

// Initialize variables
$message = '';
$messageType = '';
$errors = [];

// Ensure avatar directory exists
$avatarDir = __DIR__ . '/../assets/uploads/avatars';
if (!is_dir($avatarDir)) {
    @mkdir($avatarDir, 0755, true);
}

// -------------------------------------------------------------
// CSV Export Action
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    Security::requirePermission('employees.view', $adminId);
    Security::logAudit('export_employees_csv', 'employee', null, ['format' => 'csv'], $adminId);
    try {
        $stmt = $pdo->query("SELECT employee_id, first_name, last_name, email, phone, department, position, salary, hire_date, status, aadhar_verified, created_at FROM employees ORDER BY id ASC");
        $exportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=employees_export_' . date('Y-m-d_His') . '.csv');
        $output = fopen('php://output', 'w');

        // UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, ['Employee ID', 'First Name', 'Last Name', 'Full Name', 'Email', 'Phone', 'Department', 'Position', 'Salary (INR ₹)', 'Hire Date', 'Status', 'Aadhaar / KYC', 'Created At']);

        foreach ($exportRows as $r) {
            $kycText = 'Unverified';
            if ((int)$r['aadhar_verified'] === 2) $kycText = 'Verified';
            elseif ((int)$r['aadhar_verified'] === 1) $kycText = 'Under Review';

            fputcsv($output, [
                $r['employee_id'] ?: ('EMP-' . $r['id']),
                $r['first_name'],
                $r['last_name'],
                trim($r['first_name'] . ' ' . $r['last_name']),
                $r['email'],
                $r['phone'] ?: 'N/A',
                $r['department'] ?: 'General',
                $r['position'] ?: 'Staff',
                number_format((float)$r['salary'], 2, '.', ''),
                $r['hire_date'] ?: 'N/A',
                ucfirst($r['status']),
                $kycText,
                $r['created_at']
            ]);
        }
        fclose($output);
        exit();
    } catch (PDOException $e) {
        error_log("Export CSV error: " . $e->getMessage());
        $message = "Failed to generate CSV export.";
        $messageType = "error";
    }
}

// -------------------------------------------------------------
// Handle Quick Aadhaar Status Update (Approve / Reject)
// -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_aadhar_status') {
    if (!$canEdit) {
        $message = "Access Denied: You do not have permission to update KYC status.";
        $messageType = 'error';
    } elseif (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'employee_actions')) {
        $message = "Security token mismatch. Please try again.";
        $messageType = 'error';
    } else {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $newStatus = (int)($_POST['aadhar_status'] ?? 0);
        if ($empId > 0 && in_array($newStatus, [0, 1, 2], true)) {
            try {
                $stmt = $pdo->prepare("UPDATE employees SET aadhar_verified = ? WHERE id = ?");
                $stmt->execute([$newStatus, $empId]);
                $statusLabels = [0 => 'Unverified / Rejected', 1 => 'Pending Review', 2 => 'Verified & Approved'];
                $message = "KYC status updated to: " . $statusLabels[$newStatus];
                $messageType = 'success';

                Security::logAudit('update_kyc_status', 'employee', $empId, [
                    'new_status' => $newStatus,
                    'status_label' => $statusLabels[$newStatus]
                ], $adminId);
            } catch (PDOException $e) {
                $message = "Failed to update KYC status.";
                $messageType = 'error';
            }
        }
    }
}

// -------------------------------------------------------------
// Handle DELETE Request
// -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_employee'])) {
    if (!$canDelete) {
        $message = "Access Denied: You do not have permission to delete employee records.";
        $messageType = 'error';
    } elseif (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'employee_delete')) {
        $message = "Invalid security token. Please try again.";
        $messageType = 'error';
    } else {
        $id = (int)($_POST['employee_id'] ?? 0);
        if ($id > 0) {
            try {
                // Fetch avatar and aadhar image to remove files cleanly
                $st = $pdo->prepare("SELECT first_name, last_name, avatar, aadhar_image FROM employees WHERE id = ?");
                $st->execute([$id]);
                $empData = $st->fetch();

                if ($empData) {
                    if (!empty($empData['avatar'])) {
                        $avPath = $avatarDir . '/' . $empData['avatar'];
                        if (file_exists($avPath)) @unlink($avPath);
                    }
                    if (!empty($empData['aadhar_image'])) {
                        $aadhPath = __DIR__ . '/../assets/uploads/aadhar/' . $empData['aadhar_image'];
                        if (file_exists($aadhPath)) @unlink($aadhPath);
                    }
                }

                $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
                $stmt->execute([$id]);
                $message = 'Employee and associated records deleted successfully.';
                $messageType = 'success';

                Security::logAudit('delete_employee', 'employee', $id, [
                    'employee_name' => $empData ? trim($empData['first_name'] . ' ' . $empData['last_name']) : "Employee #{$id}"
                ], $adminId);
            } catch (PDOException $e) {
                $message = 'Error deleting employee. Please try again.';
                $messageType = 'error';
                error_log("Delete employee error: " . $e->getMessage());
            }
        } else {
            $message = 'Invalid employee ID.';
            $messageType = 'error';
        }
    }
}

// -------------------------------------------------------------
// Handle CREATE / UPDATE Employee Request
// -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['submit_employee'])) {
    if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'], 'employee_form')) {
        $message = "Invalid security token. Please refresh and try again.";
        $messageType = 'error';
    } else {
        $id          = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;
        if ($id && !$canEdit) {
            $message = "Access Denied: You do not have permission to edit employee records.";
            $messageType = 'error';
        } elseif (!$id && !$canCreate) {
            $message = "Access Denied: You do not have permission to create employee records.";
            $messageType = 'error';
        } else {
        $employeeCode= trim($_POST['employee_code'] ?? '');
        $firstName   = trim($_POST['first_name'] ?? '');
        $lastName    = trim($_POST['last_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $department  = trim($_POST['department'] ?? '');
        $position    = trim($_POST['position'] ?? '');
        $salary      = isset($_POST['salary']) && $_POST['salary'] !== '' ? (float)$_POST['salary'] : 0.00;
        $hireDate    = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $status      = in_array($_POST['status'] ?? '', ['active', 'inactive', 'on_leave'], true) ? $_POST['status'] : 'active';
        $workMode    = (isset($_POST['work_mode']) && $_POST['work_mode'] === 'wfh') ? 'wfh' : 'on_site';
        $password    = $_POST['password'] ?? '';
        $removeAvatar= !empty($_POST['remove_avatar']) && $_POST['remove_avatar'] === '1';

        // Validation
        if (empty($firstName) || empty($lastName) || empty($email)) {
            $errors[] = 'First Name, Last Name, and Email are required.';
        }
        if (!$id && empty($password)) {
            $errors[] = 'Password is required for new employees.';
        }
        if (!empty($email) && !Security::validateEmail($email)) {
            $errors[] = 'Please provide a valid email address.';
        }
        if ($salary < 0) {
            $errors[] = 'Salary cannot be negative.';
        }

        // Check duplicate email
        if (empty($errors)) {
            try {
                if ($id) {
                    $st = $pdo->prepare("SELECT id FROM employees WHERE email = ? AND id != ?");
                    $st->execute([$email, $id]);
                } else {
                    $st = $pdo->prepare("SELECT id FROM employees WHERE email = ?");
                    $st->execute([$email]);
                }
                if ($st->fetch()) {
                    $errors[] = 'An employee with this email address already exists.';
                }
            } catch (PDOException $e) {
                $errors[] = 'Database validation error.';
            }
        }

        // Handle Avatar File Upload if provided
        $uploadedAvatarName = null;
        if (empty($errors) && isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmp  = $_FILES['avatar_file']['tmp_name'];
            $fileSize = $_FILES['avatar_file']['size'];

            if ($fileSize > 3 * 1024 * 1024) {
                $errors[] = 'Avatar image must not exceed 3MB in size.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($fileTmp);
                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/jpg'  => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif'
                ];

                if (!array_key_exists($mimeType, $allowedMimes)) {
                    $errors[] = 'Invalid avatar format. Allowed: JPG, PNG, WEBP, GIF.';
                } else {
                    $ext = $allowedMimes[$mimeType];
                    $cleanCode = preg_replace('/[^a-zA-Z0-9_-]/', '', $employeeCode ?: 'emp');
                    $uploadedAvatarName = 'avatar_' . strtolower($cleanCode) . '_' . time() . '.' . $ext;
                    $targetPath = $avatarDir . '/' . $uploadedAvatarName;

                    if (!move_uploaded_file($fileTmp, $targetPath)) {
                        $errors[] = 'Failed to upload avatar image file.';
                    }
                }
            }
        }

        if (empty($errors)) {
            try {
                if ($id) {
                    // Update existing employee
                    $curStmt = $pdo->prepare("SELECT avatar, employee_id FROM employees WHERE id = ?");
                    $curStmt->execute([$id]);
                    $currentEmp = $curStmt->fetch();
                    $oldAvatar = $currentEmp['avatar'] ?? null;

                    // If a new avatar was uploaded, delete old avatar file
                    $finalAvatar = $oldAvatar;
                    if ($uploadedAvatarName) {
                        if (!empty($oldAvatar) && file_exists($avatarDir . '/' . $oldAvatar)) {
                            @unlink($avatarDir . '/' . $oldAvatar);
                        }
                        $finalAvatar = $uploadedAvatarName;
                    } elseif ($removeAvatar) {
                        if (!empty($oldAvatar) && file_exists($avatarDir . '/' . $oldAvatar)) {
                            @unlink($avatarDir . '/' . $oldAvatar);
                        }
                        $finalAvatar = null;
                    }

                    // Fallback employee code if empty
                    if (empty($employeeCode)) {
                        $employeeCode = $currentEmp['employee_id'] ?: sprintf('EMP-%03d', $id);
                    }

                    if (!empty($password)) {
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE employees SET
                            employee_id = ?, first_name = ?, last_name = ?, email = ?, password = ?,
                            phone = ?, department = ?, position = ?, salary = ?, hire_date = ?,
                            status = ?, avatar = ?, work_mode = ?
                            WHERE id = ?");
                        $stmt->execute([
                            $employeeCode, $firstName, $lastName, $email, $hashed,
                            $phone ?: null, $department ?: null, $position ?: null, $salary, $hireDate,
                            $status, $finalAvatar, $workMode, $id
                        ]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE employees SET
                            employee_id = ?, first_name = ?, last_name = ?, email = ?,
                            phone = ?, department = ?, position = ?, salary = ?, hire_date = ?,
                            status = ?, avatar = ?, work_mode = ?
                            WHERE id = ?");
                        $stmt->execute([
                            $employeeCode, $firstName, $lastName, $email,
                            $phone ?: null, $department ?: null, $position ?: null, $salary, $hireDate,
                            $status, $finalAvatar, $workMode, $id
                        ]);
                    }

                    $message = "Employee {$firstName} {$lastName} updated successfully.";
                    $messageType = 'success';

                    Security::logAudit('update_employee', 'employee', $id, [
                        'code' => $employeeCode,
                        'name' => "$firstName $lastName",
                        'email' => $email,
                        'status' => $status
                    ], $adminId);
                } else {
                    // Auto-generate employee code if left empty
                    if (empty($employeeCode)) {
                        $highestStmt = $pdo->query("SELECT employee_id FROM employees WHERE employee_id LIKE 'EMP-%' ORDER BY CAST(SUBSTRING(employee_id, 5) AS UNSIGNED) DESC LIMIT 1");
                        $topCode = $highestStmt->fetchColumn();
                        $nextNum = 1;
                        if ($topCode && preg_match('/EMP-(\d+)/i', $topCode, $m)) {
                            $nextNum = (int)$m[1] + 1;
                        }
                        $employeeCode = sprintf('EMP-%03d', $nextNum);
                    }

                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO employees
                        (employee_id, first_name, last_name, email, password, phone, department, position, salary, hire_date, status, avatar, work_mode)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $employeeCode, $firstName, $lastName, $email, $hashed,
                        $phone ?: null, $department ?: null, $position ?: null, $salary, $hireDate,
                        $status, $uploadedAvatarName, $workMode
                    ]);

                    $newEmpId = (int)$pdo->lastInsertId();
                    $message = "New employee {$firstName} {$lastName} added successfully ({$employeeCode}).";
                    $messageType = 'success';

                    Security::logAudit('create_employee', 'employee', $newEmpId, [
                        'code' => $employeeCode,
                        'name' => "$firstName $lastName",
                        'email' => $email,
                        'status' => $status
                    ], $adminId);
                }
            } catch (PDOException $e) {
                error_log("Save employee error: " . $e->getMessage());
                $message = "Database error while saving employee. Please verify details and try again.";
                $messageType = 'error';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
        }
    }
}

// Generate fresh CSRF tokens
$csrfTokenForm    = Security::generateCSRFToken('employee_form');
$csrfTokenDelete  = Security::generateCSRFToken('employee_delete');
$csrfTokenActions = Security::generateCSRFToken('employee_actions');

// -------------------------------------------------------------
// Fetch Analytics & Employee Data
// -------------------------------------------------------------
$totalEmployees  = 0;
$activeEmployees = 0;
$onLeaveEmployees= 0;
$inactiveEmployees=0;
$totalSalary     = 0.00;
$pendingAadhaar  = 0;
$employees       = [];
$departmentsList = [];

try {
    // 1. All employees list
    $stmt = $pdo->query("SELECT * FROM employees ORDER BY id DESC LIMIT 1500");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalEmployees = count($employees);

    // 2. Compute metrics
    foreach ($employees as $emp) {
        $st = $emp['status'] ?? 'active';
        if ($st === 'active') {
            $activeEmployees++;
            $totalSalary += (float)($emp['salary'] ?? 0);
        } elseif ($st === 'on_leave') {
            $onLeaveEmployees++;
        } elseif ($st === 'inactive') {
            $inactiveEmployees++;
        }

        if ((int)($emp['aadhar_verified'] ?? 0) === 1) {
            $pendingAadhaar++;
        }

        if (!empty($emp['department']) && !in_array($emp['department'], $departmentsList, true)) {
            $departmentsList[] = $emp['department'];
        }
    }
    sort($departmentsList);
} catch (PDOException $e) {
    error_log("Fetch employees error: " . $e->getMessage());
    $message = "Error loading employee roster.";
    $messageType = "error";
}

// Standard fallback departments to add to picker
$standardDepts = ['Engineering', 'Design', 'Marketing', 'Sales', 'HR', 'Finance', 'Operations', 'Support'];
$allAvailableDepts = array_values(array_unique(array_merge($standardDepts, $departmentsList)));
sort($allAvailableDepts);

// Determine next suggested employee code for the Add Modal
$suggestedNextCode = 'EMP-001';
try {
    $stmtNext = $pdo->query("SELECT employee_id FROM employees WHERE employee_id LIKE 'EMP-%' ORDER BY CAST(SUBSTRING(employee_id, 5) AS UNSIGNED) DESC LIMIT 1");
    $lastFound = $stmtNext->fetchColumn();
    if ($lastFound && preg_match('/EMP-(\d+)/i', $lastFound, $matches)) {
        $suggestedNextCode = sprintf('EMP-%03d', ((int)$matches[1]) + 1);
    } elseif ($totalEmployees > 0) {
        $suggestedNextCode = sprintf('EMP-%03d', $totalEmployees + 1);
    }
} catch (Exception $e) {}

// Include admin header
include __DIR__ . '/header.php';
?>

<!-- ── Custom SaaS Employee Hub Styling & Animations ── -->
<style>
    /* ── Animations ── */
    @keyframes empFadeInUp {
        from {
            opacity: 0;
            transform: translateY(18px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes pulseGlow {
        0%, 100% {
            box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.4);
        }
        50% {
            box-shadow: 0 0 0 8px rgba(79, 70, 229, 0);
        }
    }

    @keyframes modalPopIn {
        from {
            opacity: 0;
            transform: scale(0.96);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    /* ── Main Container ── */
    .emp-container {
        display: flex;
        flex-direction: column;
        gap: 24px;
        animation: empFadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    /* ── Hero Top Header ── */
    .emp-hero-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
        padding-bottom: 4px;
    }

    .emp-hero-title-wrap h1 {
        font-family: 'Outfit', sans-serif;
        font-size: 1.85rem;
        font-weight: 800;
        color: var(--adm-text-main);
        letter-spacing: -0.025em;
        line-height: 1.2;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .emp-hero-badge {
        font-size: 0.72rem;
        font-weight: 700;
        font-family: 'Plus Jakarta Sans', sans-serif;
        padding: 4px 10px;
        border-radius: var(--adm-radius-pill);
        background: var(--adm-brand-light);
        color: var(--adm-brand);
        border: 1px solid var(--adm-brand-border);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .emp-hero-subtitle {
        font-size: 0.95rem;
        color: var(--adm-muted);
        margin: 0;
    }

    .emp-hero-actions {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .btn-export-csv {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--adm-surface);
        color: var(--adm-text-body);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-md);
        padding: 10px 18px;
        font-size: 0.88rem;
        font-weight: 600;
        text-decoration: none;
        box-shadow: var(--adm-shadow-sm);
        transition: all var(--adm-transition);
        cursor: pointer;
    }

    .btn-export-csv:hover {
        background: var(--adm-surface-subtle);
        border-color: var(--adm-brand);
        color: var(--adm-brand);
        transform: translateY(-1px);
    }

    .btn-add-employee {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        color: #ffffff !important;
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: var(--adm-radius-md);
        padding: 10px 20px;
        font-size: 0.9rem;
        font-weight: 700;
        box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
        transition: all var(--adm-transition);
        cursor: pointer;
    }

    .btn-add-employee:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(79, 70, 229, 0.45);
        background: linear-gradient(135deg, #4338ca 0%, #6d28d9 100%);
    }

    /* ── Metrics KPI Cards ── */
    .emp-kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 16px;
    }

    .emp-kpi-card {
        background: var(--adm-surface);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-lg);
        padding: 20px;
        box-shadow: var(--adm-shadow-card);
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
        transition: all var(--adm-transition);
    }

    .emp-kpi-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--adm-shadow-hover);
        border-color: var(--adm-brand-border);
    }

    .emp-kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: transparent;
        transition: background var(--adm-transition);
    }

    .emp-kpi-card.kpi-total::before { background: linear-gradient(90deg, #4f46e5, #7c3aed); }
    .emp-kpi-card.kpi-active::before { background: linear-gradient(90deg, #059669, #10b981); }
    .emp-kpi-card.kpi-leave::before { background: linear-gradient(90deg, #d97706, #f59e0b); }
    .emp-kpi-card.kpi-payroll::before { background: linear-gradient(90deg, #2563eb, #06b6d4); }
    .emp-kpi-card.kpi-kyc::before { background: linear-gradient(90deg, #e11d48, #f43f5e); }

    .emp-kpi-info {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .emp-kpi-label {
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--adm-muted);
    }

    .emp-kpi-value {
        font-family: 'Outfit', sans-serif;
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--adm-text-main);
        line-height: 1.1;
    }

    .emp-kpi-sub {
        font-size: 0.78rem;
        color: var(--adm-faint);
        display: flex;
        align-items: center;
        gap: 5px;
        margin-top: 2px;
    }

    .emp-kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: var(--adm-radius-md);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    .emp-kpi-card.kpi-total .emp-kpi-icon {
        background: var(--adm-brand-light);
        color: var(--adm-brand);
    }
    .emp-kpi-card.kpi-active .emp-kpi-icon {
        background: var(--adm-success-light);
        color: var(--adm-success);
    }
    .emp-kpi-card.kpi-leave .emp-kpi-icon {
        background: var(--adm-warning-light);
        color: var(--adm-warning);
    }
    .emp-kpi-card.kpi-payroll .emp-kpi-icon {
        background: rgba(37, 99, 235, 0.12);
        color: #2563eb;
    }
    .emp-kpi-card.kpi-kyc .emp-kpi-icon {
        background: var(--adm-danger-light);
        color: var(--adm-danger);
    }

    /* ── Controls & Filter Toolbar ── */
    .emp-toolbar {
        background: var(--adm-surface);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-lg);
        padding: 16px 20px;
        box-shadow: var(--adm-shadow-card);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }

    .emp-toolbar-left {
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 1;
        min-width: 280px;
        flex-wrap: wrap;
    }

    .emp-search-box {
        position: relative;
        flex: 1;
        min-width: 220px;
        max-width: 380px;
    }

    .emp-search-box i.fa-magnifying-glass {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--adm-faint);
        pointer-events: none;
        font-size: 0.9rem;
    }

    .emp-search-input {
        width: 100%;
        padding: 10px 36px 10px 38px;
        border-radius: var(--adm-radius-md);
        border: 1px solid var(--adm-border);
        background: var(--adm-bg);
        color: var(--adm-text-main);
        font-size: 0.88rem;
        font-family: inherit;
        transition: all var(--adm-transition);
    }

    .emp-search-input:focus {
        outline: none;
        border-color: var(--adm-brand);
        background: var(--adm-surface);
        box-shadow: 0 0 0 3px var(--adm-brand-light);
    }

    .emp-search-clear {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--adm-faint);
        cursor: pointer;
        display: none;
        font-size: 0.85rem;
    }
    .emp-search-clear:hover { color: var(--adm-danger); }

    .emp-filter-select {
        padding: 10px 14px;
        border-radius: var(--adm-radius-md);
        border: 1px solid var(--adm-border);
        background: var(--adm-bg);
        color: var(--adm-text-main);
        font-size: 0.88rem;
        font-family: inherit;
        cursor: pointer;
        transition: all var(--adm-transition);
    }

    .emp-filter-select:focus {
        outline: none;
        border-color: var(--adm-brand);
        box-shadow: 0 0 0 3px var(--adm-brand-light);
    }

    .emp-toolbar-right {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .emp-count-pill {
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--adm-muted);
        background: var(--adm-surface-subtle);
        padding: 6px 12px;
        border-radius: var(--adm-radius-pill);
        border: 1px solid var(--adm-border-subtle);
        white-space: nowrap;
    }

    /* View Mode Switcher */
    .emp-view-switch {
        display: inline-flex;
        background: var(--adm-bg);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-md);
        padding: 3px;
        gap: 3px;
    }

    .emp-view-btn {
        background: transparent;
        border: none;
        padding: 7px 12px;
        border-radius: 6px;
        color: var(--adm-muted);
        font-size: 0.9rem;
        cursor: pointer;
        transition: all var(--adm-transition);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .emp-view-btn.active {
        background: var(--adm-surface);
        color: var(--adm-brand);
        box-shadow: var(--adm-shadow-sm);
        font-weight: 700;
    }

    /* ── Employee Card Grid ── */
    .emp-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
        gap: 20px;
    }

    .emp-card {
        background: var(--adm-surface);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-xl);
        overflow: hidden;
        box-shadow: var(--adm-shadow-card);
        display: flex;
        flex-direction: column;
        transition: all var(--adm-transition);
        position: relative;
    }

    .emp-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--adm-shadow-hover);
        border-color: var(--adm-brand-border);
    }

    /* Top Strip / Header */
    .emp-card-header {
        padding: 20px 20px 16px;
        display: flex;
        align-items: center;
        gap: 16px;
        border-bottom: 1px solid var(--adm-border-subtle);
        background: linear-gradient(180deg, var(--adm-surface-subtle) 0%, var(--adm-surface) 100%);
    }

    .emp-avatar-wrap {
        position: relative;
        width: 56px;
        height: 56px;
        flex-shrink: 0;
    }

    .emp-avatar-img {
        width: 100%;
        height: 100%;
        border-radius: var(--adm-radius-lg);
        object-fit: cover;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        border: 2px solid var(--adm-surface);
    }

    .emp-avatar-fallback {
        width: 100%;
        height: 100%;
        border-radius: var(--adm-radius-lg);
        background: linear-gradient(135deg, #4f46e5, #06b6d4);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Outfit', sans-serif;
        font-size: 1.15rem;
        font-weight: 800;
        letter-spacing: 0.02em;
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
    }

    .emp-status-indicator {
        position: absolute;
        bottom: -3px;
        right: -3px;
        width: 14px;
        height: 14px;
        border-radius: 50%;
        border: 2px solid var(--adm-surface);
    }

    .emp-status-indicator.status-active {
        background: #10b981;
        box-shadow: 0 0 6px #10b981;
    }
    .emp-status-indicator.status-on_leave {
        background: #f59e0b;
        box-shadow: 0 0 6px #f59e0b;
    }
    .emp-status-indicator.status-inactive {
        background: #94a3b8;
    }

    .emp-card-titles {
        flex: 1;
        min-width: 0;
    }

    .emp-card-name {
        font-family: 'Outfit', sans-serif;
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--adm-text-main);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-bottom: 2px;
    }

    .emp-card-role {
        font-size: 0.84rem;
        color: var(--adm-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-bottom: 6px;
    }

    .emp-card-chips {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .emp-code-pill {
        font-size: 0.72rem;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 6px;
        background: var(--adm-surface-subtle);
        color: var(--adm-muted);
        border: 1px solid var(--adm-border);
        font-family: monospace;
    }

    .emp-dept-pill {
        font-size: 0.72rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 6px;
        background: var(--adm-brand-light);
        color: var(--adm-brand);
    }

    /* Card Details Body */
    .emp-card-body {
        padding: 18px 20px;
        display: flex;
        flex-direction: column;
        gap: 11px;
        flex: 1;
    }

    .emp-detail-row {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.85rem;
        color: var(--adm-text-body);
        min-width: 0;
    }

    .emp-detail-row i {
        width: 16px;
        color: var(--adm-faint);
        font-size: 0.9rem;
        text-align: center;
        flex-shrink: 0;
    }

    .emp-detail-row a {
        color: var(--adm-text-body);
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: color var(--adm-transition);
    }

    .emp-detail-row a:hover {
        color: var(--adm-brand);
        text-decoration: underline;
    }

    .emp-card-tags {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 4px;
        padding-top: 10px;
        border-top: 1px dashed var(--adm-border-subtle);
        flex-wrap: wrap;
    }

    .emp-tag-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.76rem;
        font-weight: 600;
        padding: 4px 9px;
        border-radius: var(--adm-radius-pill);
    }

    .emp-tag-salary {
        background: rgba(37, 99, 235, 0.08);
        color: #2563eb;
    }
    .emp-tag-hire {
        background: var(--adm-surface-subtle);
        color: var(--adm-muted);
    }
    .emp-tag-kyc-ok {
        background: var(--adm-success-light);
        color: var(--adm-success);
    }
    .emp-tag-kyc-pending {
        background: var(--adm-warning-light);
        color: var(--adm-warning);
    }

    /* Card Actions Footer */
    .emp-card-actions {
        padding: 12px 18px;
        background: var(--adm-surface-subtle);
        border-top: 1px solid var(--adm-border);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .btn-card-action {
        flex: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 12px;
        border-radius: var(--adm-radius-md);
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid var(--adm-border);
        background: var(--adm-surface);
        color: var(--adm-text-body);
        transition: all var(--adm-transition);
        cursor: pointer;
    }

    .btn-card-action:hover {
        background: var(--adm-brand-light);
        color: var(--adm-brand);
        border-color: var(--adm-brand-border);
    }

    .btn-card-action.btn-del:hover {
        background: var(--adm-danger-light);
        color: var(--adm-danger);
        border-color: var(--adm-danger-border);
    }

    /* ── Enterprise Data Table View ── */
    .emp-table-wrap {
        background: var(--adm-surface);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-xl);
        box-shadow: var(--adm-shadow-card);
        overflow-x: auto;
        display: none; /* toggled via JS */
    }

    .emp-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.88rem;
        text-align: left;
    }

    .emp-table th {
        background: var(--adm-surface-subtle);
        color: var(--adm-muted);
        font-weight: 700;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 14px 20px;
        border-bottom: 1px solid var(--adm-border);
        white-space: nowrap;
    }

    .emp-table td {
        padding: 16px 20px;
        border-bottom: 1px solid var(--adm-border-subtle);
        color: var(--adm-text-body);
        vertical-align: middle;
    }

    .emp-table tr:hover td {
        background: var(--adm-surface-subtle);
    }

    .emp-table tr:last-child td {
        border-bottom: none;
    }

    .emp-tbl-profile {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .emp-tbl-avatar {
        width: 40px;
        height: 40px;
        border-radius: var(--adm-radius-md);
        object-fit: cover;
        box-shadow: var(--adm-shadow-sm);
        border: 1px solid var(--adm-border);
        flex-shrink: 0;
    }

    .emp-tbl-fallback {
        width: 40px;
        height: 40px;
        border-radius: var(--adm-radius-md);
        background: linear-gradient(135deg, #4f46e5, #06b6d4);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
        flex-shrink: 0;
    }

    .emp-tbl-name {
        font-weight: 700;
        color: var(--adm-text-main);
        line-height: 1.2;
    }

    .emp-tbl-code {
        font-size: 0.75rem;
        font-family: monospace;
        color: var(--adm-muted);
    }

    .emp-tbl-actions {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .emp-tbl-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: 1px solid var(--adm-border);
        background: var(--adm-surface);
        color: var(--adm-muted);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all var(--adm-transition);
        text-decoration: none;
        font-size: 0.82rem;
    }

    .emp-tbl-btn:hover {
        background: var(--adm-brand-light);
        color: var(--adm-brand);
        border-color: var(--adm-brand-border);
    }

    .emp-tbl-btn.tbl-del:hover {
        background: var(--adm-danger-light);
        color: var(--adm-danger);
        border-color: var(--adm-danger-border);
    }

    /* ── Empty State ── */
    .emp-empty-box {
        background: var(--adm-surface);
        border: 2px dashed var(--adm-border);
        border-radius: var(--adm-radius-xl);
        padding: 60px 24px;
        text-align: center;
        display: none;
    }

    .emp-empty-icon {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: var(--adm-surface-subtle);
        color: var(--adm-muted);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.75rem;
        margin: 0 auto 16px;
    }

    .emp-empty-box h3 {
        font-family: 'Outfit', sans-serif;
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--adm-text-main);
        margin-bottom: 6px;
    }

    .emp-empty-box p {
        color: var(--adm-muted);
        font-size: 0.9rem;
        margin-bottom: 18px;
    }

    /* ── Modern Modal Component ── */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.65);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 1050;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal-dialog {
        background: var(--adm-surface);
        border: 1px solid var(--adm-border);
        border-radius: var(--adm-radius-2xl);
        width: 100%;
        max-width: 620px;
        max-height: 92vh;
        overflow-y: auto;
        box-shadow: var(--adm-shadow-hover), 0 25px 50px -12px rgba(0, 0, 0, 0.3);
        position: relative;
        animation: modalPopIn 0.28s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .modal-dialog.modal-sm {
        max-width: 440px;
    }

    .modal-dialog.modal-lg {
        max-width: 720px;
    }

    .modal-top {
        padding: 20px 24px;
        border-bottom: 1px solid var(--adm-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        background: var(--adm-surface);
        z-index: 2;
    }

    .modal-heading {
        font-family: 'Outfit', sans-serif;
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--adm-text-main);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .modal-close-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        background: var(--adm-surface-subtle);
        color: var(--adm-muted);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all var(--adm-transition);
        font-size: 0.95rem;
    }

    .modal-close-btn:hover {
        background: var(--adm-danger-light);
        color: var(--adm-danger);
    }

    .modal-body {
        padding: 24px;
    }

    /* Modal Form Controls */
    .form-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }

    .form-group-modern {
        margin-bottom: 18px;
    }

    .form-label-modern {
        display: block;
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--adm-text-main);
        margin-bottom: 6px;
    }

    .form-label-modern .req {
        color: var(--adm-danger);
    }

    .form-input-modern {
        width: 100%;
        padding: 10px 14px;
        border-radius: var(--adm-radius-md);
        border: 1px solid var(--adm-border);
        background: var(--adm-bg);
        color: var(--adm-text-main);
        font-size: 0.9rem;
        font-family: inherit;
        transition: all var(--adm-transition);
    }

    .form-input-modern:focus {
        outline: none;
        border-color: var(--adm-brand);
        background: var(--adm-surface);
        box-shadow: 0 0 0 3px var(--adm-brand-light);
    }

    /* Avatar Upload Preview Area */
    .avatar-picker-wrap {
        display: flex;
        align-items: center;
        gap: 18px;
        padding: 14px 18px;
        border-radius: var(--adm-radius-lg);
        background: var(--adm-surface-subtle);
        border: 1px dashed var(--adm-border);
        margin-bottom: 20px;
    }

    .avatar-preview-thumb {
        width: 64px;
        height: 64px;
        border-radius: var(--adm-radius-lg);
        object-fit: cover;
        box-shadow: var(--adm-shadow-sm);
        border: 2px solid var(--adm-surface);
    }

    .avatar-picker-controls {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .avatar-upload-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: var(--adm-radius-md);
        background: var(--adm-surface);
        border: 1px solid var(--adm-border);
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--adm-text-main);
        cursor: pointer;
        width: fit-content;
        transition: all var(--adm-transition);
    }

    .avatar-upload-btn:hover {
        background: var(--adm-brand-light);
        color: var(--adm-brand);
        border-color: var(--adm-brand-border);
    }

    .avatar-remove-btn {
        font-size: 0.78rem;
        color: var(--adm-danger);
        background: none;
        border: none;
        cursor: pointer;
        text-align: left;
        padding: 0;
        display: none;
    }

    /* Password Generator Input Wrap */
    .input-addon-wrap {
        position: relative;
        display: flex;
        align-items: center;
    }

    .input-addon-wrap .form-input-modern {
        padding-right: 74px;
    }

    .input-addon-actions {
        position: absolute;
        right: 8px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .addon-icon-btn {
        background: transparent;
        border: none;
        color: var(--adm-muted);
        padding: 6px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.85rem;
    }

    .addon-icon-btn:hover {
        color: var(--adm-brand);
        background: var(--adm-brand-light);
    }

    /* Modal Footer Actions */
    .modal-bottom {
        padding: 16px 24px;
        border-top: 1px solid var(--adm-border);
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 12px;
        background: var(--adm-surface-subtle);
        border-bottom-left-radius: var(--adm-radius-2xl);
        border-bottom-right-radius: var(--adm-radius-2xl);
    }

    .btn-secondary-modern {
        padding: 10px 20px;
        border-radius: var(--adm-radius-md);
        border: 1px solid var(--adm-border);
        background: var(--adm-surface);
        color: var(--adm-text-body);
        font-size: 0.88rem;
        font-weight: 600;
        cursor: pointer;
        transition: all var(--adm-transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-secondary-modern:hover {
        background: var(--adm-surface-hover);
        color: var(--adm-text-main);
    }

    .btn-primary-modern {
        padding: 10px 22px;
        border-radius: var(--adm-radius-md);
        border: none;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: #ffffff;
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
        transition: all var(--adm-transition);
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-primary-modern:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(79, 70, 229, 0.45);
    }

    .btn-danger-modern {
        padding: 10px 22px;
        border-radius: var(--adm-radius-md);
        border: none;
        background: #e11d48;
        color: #ffffff;
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3);
        transition: all var(--adm-transition);
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-danger-modern:hover {
        background: #be123c;
    }

    /* ── Quick Profile View Modal Styling ── */
    .profile-hero-card {
        text-align: center;
        padding-bottom: 20px;
        border-bottom: 1px solid var(--adm-border-subtle);
        margin-bottom: 20px;
    }

    .profile-hero-avatar {
        width: 88px;
        height: 88px;
        border-radius: var(--adm-radius-xl);
        object-fit: cover;
        box-shadow: var(--adm-shadow-hover);
        border: 3px solid var(--adm-surface);
        margin: 0 auto 12px;
    }

    .profile-hero-fallback {
        width: 88px;
        height: 88px;
        border-radius: var(--adm-radius-xl);
        background: linear-gradient(135deg, #4f46e5, #06b6d4);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Outfit', sans-serif;
        font-size: 2rem;
        font-weight: 800;
        margin: 0 auto 12px;
        box-shadow: var(--adm-shadow-hover);
    }

    .profile-hero-name {
        font-family: 'Outfit', sans-serif;
        font-size: 1.4rem;
        font-weight: 800;
        color: var(--adm-text-main);
        margin-bottom: 4px;
    }

    .profile-hero-sub {
        font-size: 0.9rem;
        color: var(--adm-muted);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .profile-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .profile-info-item {
        background: var(--adm-surface-subtle);
        border: 1px solid var(--adm-border-subtle);
        border-radius: var(--adm-radius-md);
        padding: 12px 14px;
    }

    .profile-info-label {
        font-size: 0.74rem;
        font-weight: 700;
        color: var(--adm-faint);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 4px;
    }

    .profile-info-val {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--adm-text-main);
        word-break: break-word;
    }

    /* Aadhaar Review Box in View Modal */
    .aadhaar-review-box {
        margin-top: 18px;
        padding: 14px 18px;
        border-radius: var(--adm-radius-lg);
        background: var(--adm-surface-subtle);
        border: 1px solid var(--adm-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .form-grid-2 {
            grid-template-columns: 1fr;
        }
        .emp-hero-bar {
            flex-direction: column;
            align-items: flex-start;
        }
        .emp-hero-actions {
            width: 100%;
            justify-content: space-between;
        }
        .profile-info-grid {
            grid-template-columns: 1fr;
        }
        .emp-toolbar {
            flex-direction: column;
            align-items: stretch;
        }
        .emp-toolbar-left, .emp-search-box {
            max-width: 100%;
            width: 100%;
        }
    }
    @media (max-width: 480px) {
        .emp-container { padding-bottom: 48px; }
        .emp-card-header, .emp-card-body, .emp-card-actions { padding: 12px; }
        .emp-hero-actions { flex-direction: column; gap: 8px; }
        .emp-hero-actions .btn-primary-modern, .emp-hero-actions .btn-export-csv { width: 100%; justify-content: center; }
        .emp-stats-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="emp-container">

    <!-- ── Hero Title & Actions ── -->
    <div class="emp-hero-bar">
        <div class="emp-hero-title-wrap">
            <h1>
                <span>Employee Directory</span>
                <span class="emp-hero-badge">SaaS Suite</span>
            </h1>
            <p class="emp-hero-subtitle">Manage organization staff, view profiles, credentials &amp; KYC verification records</p>
        </div>

        <div class="emp-hero-actions">
            <!-- CSV Export -->
            <a href="?action=export_csv" class="btn-export-csv" title="Download roster as formatted CSV">
                <i class="fa-solid fa-file-csv"></i>
                <span>Export CSV</span>
            </a>

            <!-- Add Employee Trigger -->
            <button type="button" class="btn-add-employee" onclick="openAddModal()">
                <i class="fa-solid fa-user-plus"></i>
                <span>Add Employee</span>
            </button>
        </div>
    </div>

    <!-- ── Notifications / Feedback Banner ── -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo ($messageType === 'success') ? 'success' : 'danger'; ?>" role="alert">
            <i class="fa-solid <?php echo ($messageType === 'success') ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <div><?php echo $message; ?></div>
        </div>
    <?php endif; ?>

    <!-- ── KPI Metrics Cards ── -->
    <div class="emp-kpi-grid">
        <!-- Card 1: Total Employees -->
        <div class="emp-kpi-card kpi-total">
            <div class="emp-kpi-info">
                <span class="emp-kpi-label">Total Workforce</span>
                <span class="emp-kpi-value" id="kpiTotalCount"><?php echo number_format($totalEmployees); ?></span>
                <span class="emp-kpi-sub">
                    <i class="fa-solid fa-users"></i>
                    <span>All registered profiles</span>
                </span>
            </div>
            <div class="emp-kpi-icon">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>

        <!-- Card 2: Active Staff -->
        <div class="emp-kpi-card kpi-active">
            <div class="emp-kpi-info">
                <span class="emp-kpi-label">Active Staff</span>
                <span class="emp-kpi-value"><?php echo number_format($activeEmployees); ?></span>
                <span class="emp-kpi-sub">
                    <i class="fa-solid fa-circle-check" style="color:#10b981;"></i>
                    <span><?php echo $totalEmployees > 0 ? round(($activeEmployees / $totalEmployees) * 100) : 0; ?>% active rate</span>
                </span>
            </div>
            <div class="emp-kpi-icon">
                <i class="fa-solid fa-user-check"></i>
            </div>
        </div>

        <!-- Card 3: On Leave -->
        <div class="emp-kpi-card kpi-leave">
            <div class="emp-kpi-info">
                <span class="emp-kpi-label">On Leave / Away</span>
                <span class="emp-kpi-value"><?php echo number_format($onLeaveEmployees); ?></span>
                <span class="emp-kpi-sub">
                    <i class="fa-solid fa-calendar-minus"></i>
                    <span>Scheduled absence</span>
                </span>
            </div>
            <div class="emp-kpi-icon">
                <i class="fa-solid fa-calendar-day"></i>
            </div>
        </div>

        <!-- Card 4: Estimated Payroll -->
        <div class="emp-kpi-card kpi-payroll">
            <div class="emp-kpi-info">
                <span class="emp-kpi-label">Active Payroll</span>
                <span class="emp-kpi-value">₹<?php echo number_format($totalSalary, 0); ?></span>
                <span class="emp-kpi-sub">
                    <i class="fa-solid fa-sack-dollar"></i>
                    <span>Monthly estimate</span>
                </span>
            </div>
            <div class="emp-kpi-icon">
                <i class="fa-solid fa-wallet"></i>
            </div>
        </div>

        <!-- Card 5: Aadhaar / KYC Review -->
        <div class="emp-kpi-card kpi-kyc">
            <div class="emp-kpi-info">
                <span class="emp-kpi-label">KYC Verification</span>
                <span class="emp-kpi-value"><?php echo number_format($pendingAadhaar); ?></span>
                <span class="emp-kpi-sub">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span><?php echo $pendingAadhaar > 0 ? 'Action required' : 'All clear'; ?></span>
                </span>
            </div>
            <div class="emp-kpi-icon">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
        </div>
    </div>

    <!-- ── Interactive Controls & Filter Toolbar ── -->
    <div class="emp-toolbar">
        <div class="emp-toolbar-left">
            <!-- Real-time Live Search Input -->
            <div class="emp-search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" 
                       id="empSearchInput" 
                       class="emp-search-input" 
                       placeholder="Search by name, email, ID, role..." 
                       autocomplete="off">
                <button type="button" id="empSearchClear" class="emp-search-clear" title="Clear search">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Department Filter -->
            <select id="empDeptFilter" class="emp-filter-select">
                <option value="">All Departments</option>
                <?php foreach ($allAvailableDepts as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Status Filter -->
            <select id="empStatusFilter" class="emp-filter-select">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="on_leave">On Leave</option>
                <option value="inactive">Inactive</option>
            </select>

            <!-- Sort Option -->
            <select id="empSortFilter" class="emp-filter-select">
                <option value="name_asc">Name (A-Z)</option>
                <option value="name_desc">Name (Z-A)</option>
                <option value="newest">Newest First</option>
                <option value="salary_high">Salary (High to Low)</option>
                <option value="salary_low">Salary (Low to High)</option>
            </select>
        </div>

        <div class="emp-toolbar-right">
            <!-- Dynamic Count Pill -->
            <div class="emp-count-pill" id="empShowingCount">
                Showing <?php echo $totalEmployees; ?> employees
            </div>

            <!-- View Mode Switcher -->
            <div class="emp-view-switch" role="group" aria-label="View switcher">
                <button type="button" class="emp-view-btn active" id="btnViewGrid" title="Card Grid View">
                    <i class="fa-solid fa-grip"></i>
                </button>
                <button type="button" class="emp-view-btn" id="btnViewTable" title="Data Table View">
                    <i class="fa-solid fa-table-list"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Card Grid View ── -->
    <div class="emp-grid" id="empCardGrid">
        <?php foreach ($employees as $index => $emp): 
            $fullName = htmlspecialchars(trim($emp['first_name'] . ' ' . $emp['last_name']));
            $initials = strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1)) ?: 'EM';
            $code = htmlspecialchars($emp['employee_id'] ?: ('EMP-' . $emp['id']));
            $position = htmlspecialchars($emp['position'] ?: 'Staff Member');
            $dept = htmlspecialchars($emp['department'] ?: 'General');
            $status = $emp['status'] ?: 'active';
            $salary = (float)($emp['salary'] ?? 0);
            $hireDateFmt = !empty($emp['hire_date']) ? date('M d, Y', strtotime($emp['hire_date'])) : 'Not recorded';
            $aadharStatus = (int)($emp['aadhar_verified'] ?? 0);
            
            // Check avatar file existence
            $hasAvatar = !empty($emp['avatar']) && file_exists($avatarDir . '/' . $emp['avatar']);
            $avatarUrl = $hasAvatar ? '/assets/uploads/avatars/' . rawurlencode($emp['avatar']) : null;
        ?>
            <div class="emp-card" 
                 data-id="<?php echo $emp['id']; ?>"
                 data-name="<?php echo strtolower($fullName); ?>"
                 data-email="<?php echo strtolower($emp['email']); ?>"
                 data-phone="<?php echo strtolower($emp['phone'] ?? ''); ?>"
                 data-code="<?php echo strtolower($code); ?>"
                 data-role="<?php echo strtolower($position); ?>"
                 data-dept="<?php echo htmlspecialchars($dept); ?>"
                 data-status="<?php echo $status; ?>"
                 data-salary="<?php echo $salary; ?>"
                 data-hire="<?php echo $emp['hire_date'] ?? ''; ?>"
                 data-created="<?php echo $emp['created_at'] ?? ''; ?>"
                 style="animation-delay: <?php echo min(0.4, $index * 0.04); ?>s;">
                
                <div class="emp-card-header">
                    <div class="emp-avatar-wrap">
                        <?php if ($avatarUrl): ?>
                            <img src="<?php echo $avatarUrl; ?>" alt="<?php echo $fullName; ?>" class="emp-avatar-img">
                        <?php else: ?>
                            <div class="emp-avatar-fallback"><?php echo $initials; ?></div>
                        <?php endif; ?>
                        <div class="emp-status-indicator status-<?php echo $status; ?>" title="Status: <?php echo ucfirst(str_replace('_', ' ', $status)); ?>"></div>
                    </div>

                    <div class="emp-card-titles">
                        <div class="emp-card-name" title="<?php echo $fullName; ?>"><?php echo $fullName; ?></div>
                        <div class="emp-card-role" title="<?php echo $position; ?>"><?php echo $position; ?></div>
                        <div class="emp-card-chips">
                            <span class="emp-code-pill"><?php echo $code; ?></span>
                            <span class="emp-dept-pill"><?php echo $dept; ?></span>
                        </div>
                    </div>
                </div>

                <div class="emp-card-body">
                    <!-- Email -->
                    <div class="emp-detail-row">
                        <i class="fa-regular fa-envelope"></i>
                        <a href="mailto:<?php echo htmlspecialchars($emp['email']); ?>" title="Send email">
                            <?php echo htmlspecialchars($emp['email']); ?>
                        </a>
                    </div>

                    <!-- Phone -->
                    <div class="emp-detail-row">
                        <i class="fa-solid fa-phone"></i>
                        <?php if (!empty($emp['phone'])): ?>
                            <a href="tel:<?php echo htmlspecialchars($emp['phone']); ?>">
                                <?php echo htmlspecialchars($emp['phone']); ?>
                            </a>
                        <?php else: ?>
                            <span style="color:var(--adm-faint); font-style:italic;">No phone provided</span>
                        <?php endif; ?>
                    </div>

                    <!-- Tags: Salary, Hire, KYC -->
                    <div class="emp-card-tags">
                        <div class="emp-tag-badge emp-tag-salary" title="Annual / Monthly compensation">
                            <i class="fa-solid fa-coins"></i>
                            <span>₹<?php echo number_format($salary, 2); ?></span>
                        </div>
                        <div class="emp-tag-badge emp-tag-hire" title="Joined date">
                            <i class="fa-regular fa-calendar-check"></i>
                            <span><?php echo $hireDateFmt; ?></span>
                        </div>
                        <?php if ($aadharStatus === 2): ?>
                            <div class="emp-tag-badge emp-tag-kyc-ok" title="Identity Verified">
                                <i class="fa-solid fa-shield-check"></i>
                                <span>Verified</span>
                            </div>
                        <?php elseif ($aadharStatus === 1): ?>
                            <div class="emp-tag-badge emp-tag-kyc-pending" title="Aadhaar uploaded, pending review">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                                <span>KYC Review</span>
                            </div>
                        <?php endif; ?>

                        <?php if (($emp['work_mode'] ?? 'on_site') === 'wfh'): ?>
                            <div class="emp-tag-badge" style="background:var(--adm-brand-light); color:var(--adm-brand); border:1px solid var(--adm-brand-border);" title="Work From Home / Remote">
                                <i class="fa-solid fa-house-laptop"></i>
                                <span>WFH</span>
                            </div>
                        <?php else: ?>
                            <div class="emp-tag-badge" style="background:rgba(5,150,105,0.12); color:#059669; border:1px solid rgba(5,150,105,0.25);" title="On-Site Geofence Restricted">
                                <i class="fa-solid fa-building"></i>
                                <span>On-Site</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="emp-card-actions">
                    <button type="button" class="btn-card-action" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($emp)); ?>)">
                        <i class="fa-regular fa-eye"></i>
                        <span>Details</span>
                    </button>
                    <button type="button" class="btn-card-action" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($emp)); ?>)">
                        <i class="fa-regular fa-pen-to-square"></i>
                        <span>Edit</span>
                    </button>
                    <button type="button" class="btn-card-action btn-del" onclick="confirmDelete(<?php echo htmlspecialchars(json_encode($emp)); ?>)">
                        <i class="fa-regular fa-trash-can"></i>
                        <span>Delete</span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Enterprise Data Table View ── -->
    <div class="emp-table-wrap" id="empTableWrap">
        <table class="emp-table" id="empMainTable">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Role &amp; Department</th>
                    <th>Contact Info</th>
                    <th>Compensation</th>
                    <th>Hire Date</th>
                    <th>Status</th>
                    <th>Work Mode</th>
                    <th>KYC</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): 
                    $fullName = htmlspecialchars(trim($emp['first_name'] . ' ' . $emp['last_name']));
                    $initials = strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1)) ?: 'EM';
                    $code = htmlspecialchars($emp['employee_id'] ?: ('EMP-' . $emp['id']));
                    $position = htmlspecialchars($emp['position'] ?: 'Staff');
                    $dept = htmlspecialchars($emp['department'] ?: 'General');
                    $status = $emp['status'] ?: 'active';
                    $salary = (float)($emp['salary'] ?? 0);
                    $hireDateFmt = !empty($emp['hire_date']) ? date('M d, Y', strtotime($emp['hire_date'])) : 'N/A';
                    $aadharStatus = (int)($emp['aadhar_verified'] ?? 0);
                    
                    $hasAvatar = !empty($emp['avatar']) && file_exists($avatarDir . '/' . $emp['avatar']);
                    $avatarUrl = $hasAvatar ? '/assets/uploads/avatars/' . rawurlencode($emp['avatar']) : null;
                ?>
                    <tr class="emp-table-row"
                        data-id="<?php echo $emp['id']; ?>"
                        data-name="<?php echo strtolower($fullName); ?>"
                        data-email="<?php echo strtolower($emp['email']); ?>"
                        data-phone="<?php echo strtolower($emp['phone'] ?? ''); ?>"
                        data-code="<?php echo strtolower($code); ?>"
                        data-role="<?php echo strtolower($position); ?>"
                        data-dept="<?php echo htmlspecialchars($dept); ?>"
                        data-status="<?php echo $status; ?>"
                        data-salary="<?php echo $salary; ?>"
                        data-hire="<?php echo $emp['hire_date'] ?? ''; ?>"
                        data-created="<?php echo $emp['created_at'] ?? ''; ?>">
                        
                        <!-- Employee Profile -->
                        <td>
                            <div class="emp-tbl-profile">
                                <?php if ($avatarUrl): ?>
                                    <img src="<?php echo $avatarUrl; ?>" alt="<?php echo $fullName; ?>" class="emp-tbl-avatar">
                                <?php else: ?>
                                    <div class="emp-tbl-fallback"><?php echo $initials; ?></div>
                                <?php endif; ?>
                                <div>
                                    <div class="emp-tbl-name"><?php echo $fullName; ?></div>
                                    <div class="emp-tbl-code"><?php echo $code; ?></div>
                                </div>
                            </div>
                        </td>

                        <!-- Role & Dept -->
                        <td>
                            <div style="font-weight:600; color:var(--adm-text-main);"><?php echo $position; ?></div>
                            <span class="emp-dept-pill" style="display:inline-block; margin-top:3px;"><?php echo $dept; ?></span>
                        </td>

                        <!-- Contact -->
                        <td>
                            <div><a href="mailto:<?php echo htmlspecialchars($emp['email']); ?>" style="color:var(--adm-brand); text-decoration:none; font-weight:500;"><?php echo htmlspecialchars($emp['email']); ?></a></div>
                            <div style="font-size:0.8rem; color:var(--adm-faint); margin-top:2px;"><?php echo htmlspecialchars($emp['phone'] ?: 'No phone'); ?></div>
                        </td>

                        <!-- Compensation -->
                        <td>
                            <strong style="color:var(--adm-text-main);">₹<?php echo number_format($salary, 2); ?></strong>
                        </td>

                        <!-- Hire Date -->
                        <td style="color:var(--adm-muted);">
                            <?php echo $hireDateFmt; ?>
                        </td>

                        <!-- Status -->
                        <td>
                            <span class="emp-tag-badge" style="background: <?php echo $status === 'active' ? 'var(--adm-success-light)' : ($status === 'on_leave' ? 'var(--adm-warning-light)' : 'var(--adm-surface-subtle)'); ?>; color: <?php echo $status === 'active' ? 'var(--adm-success)' : ($status === 'on_leave' ? 'var(--adm-warning)' : 'var(--adm-muted)'); ?>;">
                                <i class="fa-solid fa-circle" style="font-size:0.45rem;"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                            </span>
                        </td>

                        <!-- Work Mode -->
                        <td>
                            <?php if (($emp['work_mode'] ?? 'on_site') === 'wfh'): ?>
                                <span class="emp-tag-badge" style="background:var(--adm-brand-light); color:var(--adm-brand); border:1px solid var(--adm-brand-border);" title="Remote / Work From Home">
                                    <i class="fa-solid fa-house-laptop"></i> Remote
                                </span>
                            <?php else: ?>
                                <span class="emp-tag-badge" style="background:rgba(5,150,105,0.12); color:#059669; border:1px solid rgba(5,150,105,0.25);" title="Office Geofence Restricted">
                                    <i class="fa-solid fa-building"></i> On-Site
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- KYC -->
                        <td>
                            <?php if ($aadharStatus === 2): ?>
                                <span class="emp-tag-badge emp-tag-kyc-ok">Verified</span>
                            <?php elseif ($aadharStatus === 1): ?>
                                <span class="emp-tag-badge emp-tag-kyc-pending">In Review</span>
                            <?php else: ?>
                                <span class="emp-tag-badge emp-tag-hire">Unverified</span>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td style="text-align: right;">
                            <div class="emp-tbl-actions" style="justify-content: flex-end;">
                                <button type="button" class="emp-tbl-btn" title="View details" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($emp)); ?>)">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                                <button type="button" class="emp-tbl-btn" title="Edit employee" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($emp)); ?>)">
                                    <i class="fa-regular fa-pen-to-square"></i>
                                </button>
                                <button type="button" class="emp-tbl-btn tbl-del" title="Delete employee" onclick="confirmDelete(<?php echo htmlspecialchars(json_encode($emp)); ?>)">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Empty State ── -->
    <div class="emp-empty-box" id="empEmptyBox">
        <div class="emp-empty-icon">
            <i class="fa-solid fa-user-xmark"></i>
        </div>
        <h3>No matching employees found</h3>
        <p>Try adjusting your search criteria, filters, or add a new team member.</p>
        <button type="button" class="btn-export-csv" onclick="resetFilters()">
            <i class="fa-solid fa-arrow-rotate-left"></i>
            <span>Reset Filters</span>
        </button>
    </div>

</div>

<!-- ========================================================================= -->
<!-- MODAL 1: ADD EMPLOYEE MODAL                                                -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="addEmployeeModal" onclick="closeModalOnBackdrop(event, 'addEmployeeModal')">
    <div class="modal-dialog modal-lg">
        <div class="modal-top">
            <div class="modal-heading">
                <i class="fa-solid fa-user-plus" style="color:var(--adm-brand);"></i>
                <span>Add New Employee</span>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeModal('addEmployeeModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfTokenForm; ?>">

                <!-- Avatar Picker Preview Area -->
                <div class="avatar-picker-wrap">
                    <img src="/assets/uploads/avatars/default.png" id="addAvatarPreview" class="avatar-preview-thumb" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'64\' height=\'64\' fill=\'%23cbd5e1\' viewBox=\'0 0 24 24\'%3E%3Cpath d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/%3E%3C/svg%3E';">
                    <div class="avatar-picker-controls">
                        <label for="addAvatarFile" class="avatar-upload-btn">
                            <i class="fa-solid fa-camera"></i>
                            <span>Choose Photo</span>
                        </label>
                        <input type="file" id="addAvatarFile" name="avatar_file" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" onchange="handleAvatarPreview(this, 'addAvatarPreview')">
                        <span style="font-size:0.75rem; color:var(--adm-faint);">JPG, PNG, WEBP or GIF (Max 3MB)</span>
                    </div>
                </div>

                <!-- Row 1: Code & Department -->
                <div class="form-grid-2">
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="add_employee_code">Employee ID / Code</label>
                        <input type="text" id="add_employee_code" name="employee_code" class="form-input-modern" value="<?php echo htmlspecialchars($suggestedNextCode); ?>" placeholder="e.g. EMP-009" maxlength="20">
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern" for="add_department">Department</label>
                        <input type="text" list="addDeptList" id="add_department" name="department" class="form-input-modern" placeholder="Select or type department" maxlength="50">
                        <datalist id="addDeptList">
                            <?php foreach ($allAvailableDepts as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <!-- Row 2: First Name & Last Name -->
                <div class="form-grid-2">
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="add_first_name">First Name <span class="req">*</span></label>
                        <input type="text" id="add_first_name" name="first_name" class="form-input-modern" required placeholder="John" maxlength="50">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="add_last_name">Last Name <span class="req">*</span></label>
                        <input type="text" id="add_last_name" name="last_name" class="form-input-modern" required placeholder="Doe" maxlength="50">
                    </div>
                </div>

                <!-- Row 3: Email & Password -->
                <div class="form-grid-2">
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="add_email">Work Email <span class="req">*</span></label>
                        <input type="email" id="add_email" name="email" class="form-input-modern" required placeholder="john.doe@company.com" maxlength="100" autocomplete="off">
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern" for="add_password">Password <span class="req">*</span></label>
                        <div class="input-addon-wrap">
                            <input type="password" id="add_password" name="password" class="form-input-modern" required placeholder="Enter password" autocomplete="new-password">
                            <div class="input-addon-actions">
                                <button type="button" class="addon-icon-btn" title="Toggle visibility" onclick="togglePasswordVisibility('add_password', this)">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                                <button type="button" class="addon-icon-btn" title="Generate secure password" onclick="generatePassword('add_password')">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 4: Phone & Position -->
                <div class="form-grid-2">
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="add_phone">Phone Number</label>
                        <input type="tel" id="add_phone" name="phone" class="form-input-modern" placeholder="+91 98765 43210" maxlength="20">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="add_position">Designation / Role</label>
                        <input type="text" id="add_position" name="position" class="form-input-modern" placeholder="e.g. Senior Software Engineer" maxlength="100">
                    </div>
                </div>

                <!-- Row 5: Salary, Hire Date, Status -->
                <div class="form-grid-2">
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="add_salary">Salary (Monthly / Annual INR ₹)</label>
                        <input type="number" id="add_salary" name="salary" class="form-input-modern" step="0.01" min="0" placeholder="50000.00">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="add_hire_date">Hire Date</label>
                        <input type="date" id="add_hire_date" name="hire_date" class="form-input-modern" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <div class="form-grid-2" style="margin-bottom:0;">
                    <div class="form-group-modern" style="margin-bottom:0;">
                        <label class="form-label-modern" for="add_status">Account Status</label>
                        <select id="add_status" name="status" class="form-input-modern">
                            <option value="active" selected>Active &bull; Regular employee</option>
                            <option value="on_leave">On Leave &bull; Temporarily absent</option>
                            <option value="inactive">Inactive &bull; Deactivated / Suspended</option>
                        </select>
                    </div>

                    <div class="form-group-modern" style="margin-bottom:0;">
                        <label class="form-label-modern" for="add_work_mode">Work Mode &bull; Attendance Location</label>
                        <select id="add_work_mode" name="work_mode" class="form-input-modern">
                            <option value="on_site" selected>🏢 On-Site (Geofence Restricted)</option>
                            <option value="wfh">🏠 Work From Home (Remote Mode)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-bottom">
                <button type="button" class="btn-secondary-modern" onclick="closeModal('addEmployeeModal')">Cancel</button>
                <button type="submit" name="submit_employee" class="btn-primary-modern">
                    <i class="fa-solid fa-check"></i>
                    <span>Add Employee</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: EDIT EMPLOYEE MODAL                                               -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="editEmployeeModal" onclick="closeModalOnBackdrop(event, 'editEmployeeModal')">
    <div class="modal-dialog modal-lg">
        <div class="modal-top">
            <div class="modal-heading">
                <i class="fa-regular fa-pen-to-square" style="color:var(--adm-brand);"></i>
                <span id="editModalHeading">Edit Employee</span>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeModal('editEmployeeModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfTokenForm; ?>">
                <input type="hidden" id="edit_employee_id" name="employee_id" value="">
                <input type="hidden" id="edit_remove_avatar" name="remove_avatar" value="0">

                <!-- Avatar Area with Preview & Remove -->
                <div class="avatar-picker-wrap">
                    <img src="" id="editAvatarPreview" class="avatar-preview-thumb" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'64\' height=\'64\' fill=\'%23cbd5e1\' viewBox=\'0 0 24 24\'%3E%3Cpath d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/%3E%3C/svg%3E';">
                    <div class="avatar-picker-controls">
                        <label for="editAvatarFile" class="avatar-upload-btn">
                            <i class="fa-solid fa-camera"></i>
                            <span>Change Photo</span>
                        </label>
                        <input type="file" id="editAvatarFile" name="avatar_file" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" onchange="handleAvatarPreview(this, 'editAvatarPreview')">
                        <button type="button" id="editAvatarRemoveBtn" class="avatar-remove-btn" onclick="triggerRemoveAvatar()">
                            <i class="fa-solid fa-trash-can"></i> Remove current photo
                        </button>
                    </div>
                </div>

                <!-- Row 1: Code & Department -->
                <div class="form-grid-2">
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="edit_employee_code">Employee ID / Code</label>
                        <input type="text" id="edit_employee_code" name="employee_code" class="form-input-modern" maxlength="20">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="edit_department">Department</label>
                        <input type="text" list="editDeptList" id="edit_department" name="department" class="form-input-modern" maxlength="50">
                        <datalist id="editDeptList">
                            <?php foreach ($allAvailableDepts as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <!-- Row 2: First Name & Last Name -->
                <div class="form-grid-2">
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="edit_first_name">First Name <span class="req">*</span></label>
                        <input type="text" id="edit_first_name" name="first_name" class="form-input-modern" required maxlength="50">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="edit_last_name">Last Name <span class="req">*</span></label>
                        <input type="text" id="edit_last_name" name="last_name" class="form-input-modern" required maxlength="50">
                    </div>
                </div>

                <!-- Row 3: Email & Password -->
                <div class="form-grid-2">
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="edit_email">Work Email <span class="req">*</span></label>
                        <input type="email" id="edit_email" name="email" class="form-input-modern" required maxlength="100">
                    </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern" for="edit_password">Password <span style="font-weight:normal; color:var(--adm-faint);">(Leave blank to retain)</span></label>
                        <div class="input-addon-wrap">
                            <input type="password" id="edit_password" name="password" class="form-input-modern" placeholder="Leave blank to keep current" autocomplete="new-password">
                            <div class="input-addon-actions">
                                <button type="button" class="addon-icon-btn" title="Toggle visibility" onclick="togglePasswordVisibility('edit_password', this)">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                                <button type="button" class="addon-icon-btn" title="Generate password" onclick="generatePassword('edit_password')">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 4: Phone & Position -->
                <div class="form-grid-2">
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="edit_phone">Phone Number</label>
                        <input type="tel" id="edit_phone" name="phone" class="form-input-modern" maxlength="20">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="edit_position">Designation / Role</label>
                        <input type="text" id="edit_position" name="position" class="form-input-modern" maxlength="100">
                    </div>
                </div>

                <!-- Row 5: Salary & Hire Date -->
                <div class="form-grid-2">
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="edit_salary">Salary (INR ₹)</label>
                        <input type="number" id="edit_salary" name="salary" class="form-input-modern" step="0.01" min="0">
                    </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern" for="edit_hire_date">Hire Date</label>
                        <input type="date" id="edit_hire_date" name="hire_date" class="form-input-modern">
                    </div>
                </div>

                <div class="form-grid-2" style="margin-bottom:0;">
                    <div class="form-group-modern" style="margin-bottom:0;">
                        <label class="form-label-modern" for="edit_status">Account Status</label>
                        <select id="edit_status" name="status" class="form-input-modern">
                            <option value="active">Active &bull; Regular employee</option>
                            <option value="on_leave">On Leave &bull; Temporarily absent</option>
                            <option value="inactive">Inactive &bull; Deactivated / Suspended</option>
                        </select>
                    </div>

                    <div class="form-group-modern" style="margin-bottom:0;">
                        <label class="form-label-modern" for="edit_work_mode">Work Mode &bull; Attendance Location</label>
                        <select id="edit_work_mode" name="work_mode" class="form-input-modern">
                            <option value="on_site">🏢 On-Site (Geofence Restricted)</option>
                            <option value="wfh">🏠 Work From Home (Remote Mode)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-bottom">
                <button type="button" class="btn-secondary-modern" onclick="closeModal('editEmployeeModal')">Cancel</button>
                <button type="submit" name="submit_employee" class="btn-primary-modern">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Save Changes</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: QUICK PROFILE VIEW MODAL                                          -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="viewEmployeeModal" onclick="closeModalOnBackdrop(event, 'viewEmployeeModal')">
    <div class="modal-dialog modal-lg">
        <div class="modal-top">
            <div class="modal-heading">
                <i class="fa-regular fa-address-card" style="color:var(--adm-brand);"></i>
                <span>Employee Profile</span>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeModal('viewEmployeeModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body">
            <!-- Hero Top Header -->
            <div class="profile-hero-card">
                <div id="viewAvatarWrap"></div>
                <div class="profile-hero-name" id="viewFullName">Full Name</div>
                <div class="profile-hero-sub">
                    <span id="viewRole">Role</span> &bull; 
                    <span id="viewDeptBadge" class="emp-dept-pill">Department</span> &bull; 
                    <span id="viewCodeBadge" class="emp-code-pill">EMP-000</span>
                </div>
            </div>

            <!-- Profile Info Grid -->
            <div class="profile-info-grid">
                <div class="profile-info-item">
                    <div class="profile-info-label">Email Address</div>
                    <div class="profile-info-val" id="viewEmail">N/A</div>
                </div>
                <div class="profile-info-item">
                    <div class="profile-info-label">Phone Number</div>
                    <div class="profile-info-val" id="viewPhone">N/A</div>
                </div>
                <div class="profile-info-item">
                    <div class="profile-info-label">Compensation / Salary</div>
                    <div class="profile-info-val" id="viewSalary">N/A</div>
                </div>
                <div class="profile-info-item">
                    <div class="profile-info-label">Hire Date &amp; Tenure</div>
                    <div class="profile-info-val" id="viewHireDate">N/A</div>
                </div>
                <div class="profile-info-item">
                    <div class="profile-info-label">Account Status</div>
                    <div class="profile-info-val" id="viewStatus">N/A</div>
                </div>
                <div class="profile-info-item">
                    <div class="profile-info-label">Aadhaar / KYC Verification</div>
                    <div class="profile-info-val" id="viewKycBadge">N/A</div>
                </div>
            </div>

            <!-- Aadhaar Review Box if image exists -->
            <div id="viewAadhaarSection" class="aadhaar-review-box" style="display:none;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fa-solid fa-file-invoice" style="font-size:1.5rem; color:var(--adm-brand);"></i>
                    <div>
                        <div style="font-weight:700; color:var(--adm-text-main);">Aadhaar Identity Document</div>
                        <div style="font-size:0.8rem; color:var(--adm-muted);">Uploaded by employee for KYC compliance</div>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <a href="" id="viewAadhaarDocLink" target="_blank" class="btn-export-csv" style="padding:6px 14px; font-size:0.8rem;">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        <span>View Document</span>
                    </a>
                    <!-- Quick KYC Verify form -->
                    <form method="POST" action="" style="display:inline;" id="kycActionForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfTokenActions; ?>">
                        <input type="hidden" name="action" value="update_aadhar_status">
                        <input type="hidden" name="employee_id" id="kycActionEmpId" value="">
                        <input type="hidden" name="aadhar_status" id="kycActionStatus" value="2">
                        <button type="submit" class="btn-add-employee" id="kycApproveBtn" style="padding:6px 14px; font-size:0.8rem;">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Approve KYC</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal-bottom">
            <a href="#" id="viewAttendanceLink" class="btn-secondary-modern">
                <i class="fa-solid fa-calendar-check"></i>
                <span>View Attendance</span>
            </a>
            <a href="#" id="viewTasksLink" class="btn-secondary-modern">
                <i class="fa-solid fa-list-check"></i>
                <span>View Tasks</span>
            </a>
            <button type="button" class="btn-primary-modern" id="viewEditBtn">
                <i class="fa-regular fa-pen-to-square"></i>
                <span>Edit Profile</span>
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: DELETE CONFIRMATION MODAL                                         -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="deleteEmployeeModal" onclick="closeModalOnBackdrop(event, 'deleteEmployeeModal')">
    <div class="modal-dialog modal-sm">
        <div class="modal-top">
            <div class="modal-heading" style="color:var(--adm-danger);">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>Delete Employee</span>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeModal('deleteEmployeeModal')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="">
            <div class="modal-body" style="text-align:center; padding:24px 20px;">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfTokenDelete; ?>">
                <input type="hidden" name="delete_employee" value="1">
                <input type="hidden" id="delete_employee_id" name="employee_id" value="">

                <div style="width:56px; height:56px; border-radius:50%; background:var(--adm-danger-light); color:var(--adm-danger); display:flex; align-items:center; justify-content:center; font-size:1.5rem; margin:0 auto 16px;">
                    <i class="fa-solid fa-user-minus"></i>
                </div>

                <h3 style="font-family:'Outfit', sans-serif; font-size:1.15rem; color:var(--adm-text-main); margin-bottom:8px;">
                    Delete <span id="delEmployeeName" style="color:var(--adm-danger);">Employee</span>?
                </h3>
                <p style="font-size:0.88rem; color:var(--adm-muted); line-height:1.5; margin:0;">
                    This will permanently remove this employee profile (<span id="delEmployeeCode" style="font-family:monospace; font-weight:700;"></span>) along with their attendance logs, leave records, and assigned tasks.
                </p>
            </div>

            <div class="modal-bottom" style="justify-content:center;">
                <button type="button" class="btn-secondary-modern" onclick="closeModal('deleteEmployeeModal')">Cancel</button>
                <button type="submit" class="btn-danger-modern">
                    <i class="fa-regular fa-trash-can"></i>
                    <span>Delete Permanently</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Client Scripts for Real-time Filter, Sorting, View Modes & Modals ── -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const searchInput  = document.getElementById('empSearchInput');
    const searchClear  = document.getElementById('empSearchClear');
    const deptFilter   = document.getElementById('empDeptFilter');
    const statusFilter = document.getElementById('empStatusFilter');
    const sortFilter   = document.getElementById('empSortFilter');
    const countPill    = document.getElementById('empShowingCount');
    const emptyBox     = document.getElementById('empEmptyBox');
    const cardGrid     = document.getElementById('empCardGrid');
    const tableWrap    = document.getElementById('empTableWrap');

    const btnViewGrid  = document.getElementById('btnViewGrid');
    const btnViewTable = document.getElementById('btnViewTable');

    // ── 1. View Mode Persistence ──
    const savedView = localStorage.getItem('emp_admin_view_mode') || 'grid';
    setViewMode(savedView);

    btnViewGrid.addEventListener('click', () => {
        setViewMode('grid');
        localStorage.setItem('emp_admin_view_mode', 'grid');
    });

    btnViewTable.addEventListener('click', () => {
        setViewMode('table');
        localStorage.setItem('emp_admin_view_mode', 'table');
    });

    function setViewMode(mode) {
        if (mode === 'table') {
            btnViewTable.classList.add('active');
            btnViewGrid.classList.remove('active');
            tableWrap.style.display = 'block';
            cardGrid.style.display = 'none';
        } else {
            btnViewGrid.classList.add('active');
            btnViewTable.classList.remove('active');
            cardGrid.style.display = 'grid';
            tableWrap.style.display = 'none';
        }
    }

    // ── 2. Real-time Search & Filter Engine ──
    function filterEmployees() {
        const query = (searchInput.value || '').trim().toLowerCase();
        const selectedDept = (deptFilter.value || '').trim();
        const selectedStatus = (statusFilter.value || '').trim();

        // Show/hide search clear button
        searchClear.style.display = query ? 'block' : 'none';

        const cards = Array.from(cardGrid.querySelectorAll('.emp-card'));
        const rows  = Array.from(tableWrap.querySelectorAll('.emp-table-row'));

        let visibleCount = 0;

        cards.forEach((card, idx) => {
            const row = rows[idx];
            const name   = card.getAttribute('data-name') || '';
            const email  = card.getAttribute('data-email') || '';
            const phone  = card.getAttribute('data-phone') || '';
            const code   = card.getAttribute('data-code') || '';
            const role   = card.getAttribute('data-role') || '';
            const dept   = card.getAttribute('data-dept') || '';
            const status = card.getAttribute('data-status') || '';

            // Match conditions
            const matchesQuery = !query || 
                name.includes(query) || 
                email.includes(query) || 
                phone.includes(query) || 
                code.includes(query) || 
                role.includes(query);

            const matchesDept = !selectedDept || dept === selectedDept;
            const matchesStatus = !selectedStatus || status === selectedStatus;

            if (matchesQuery && matchesDept && matchesStatus) {
                card.style.display = 'flex';
                if (row) row.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
                if (row) row.style.display = 'none';
            }
        });

        // Sort items
        sortElements();

        // Update count pill & empty state
        countPill.textContent = `Showing ${visibleCount} employees`;
        if (visibleCount === 0) {
            emptyBox.style.display = 'block';
            cardGrid.style.display = 'none';
            tableWrap.style.display = 'none';
        } else {
            emptyBox.style.display = 'none';
            const currentMode = localStorage.getItem('emp_admin_view_mode') || 'grid';
            setViewMode(currentMode);
        }
    }

    // ── 3. Sorting Function ──
    function sortElements() {
        const sortBy = sortFilter.value;
        const cards = Array.from(cardGrid.querySelectorAll('.emp-card'));
        const tbody = tableWrap.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('.emp-table-row'));

        const comparator = (a, b) => {
            if (sortBy === 'name_asc') {
                return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '');
            } else if (sortBy === 'name_desc') {
                return (b.getAttribute('data-name') || '').localeCompare(a.getAttribute('data-name') || '');
            } else if (sortBy === 'newest') {
                return (b.getAttribute('data-created') || '').localeCompare(a.getAttribute('data-created') || '');
            } else if (sortBy === 'salary_high') {
                return parseFloat(b.getAttribute('data-salary') || 0) - parseFloat(a.getAttribute('data-salary') || 0);
            } else if (sortBy === 'salary_low') {
                return parseFloat(a.getAttribute('data-salary') || 0) - parseFloat(b.getAttribute('data-salary') || 0);
            }
            return 0;
        };

        cards.sort(comparator).forEach(card => cardGrid.appendChild(card));
        rows.sort(comparator).forEach(row => tbody.appendChild(row));
    }

    searchInput.addEventListener('input', filterEmployees);
    deptFilter.addEventListener('change', filterEmployees);
    statusFilter.addEventListener('change', filterEmployees);
    sortFilter.addEventListener('change', filterEmployees);

    searchClear.addEventListener('click', () => {
        searchInput.value = '';
        searchInput.focus();
        filterEmployees();
    });

    window.resetFilters = () => {
        searchInput.value = '';
        deptFilter.value = '';
        statusFilter.value = '';
        sortFilter.value = 'name_asc';
        filterEmployees();
    };

    // Keyboard shortcut '/' to focus search
    document.addEventListener('keydown', (e) => {
        if (e.key === '/' && document.activeElement !== searchInput && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
            e.preventDefault();
            searchInput.focus();
            searchInput.select();
        }
    });
});

// ── Modal Handling Functions ──
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function closeModalOnBackdrop(e, modalId) {
    if (e.target.id === modalId) {
        closeModal(modalId);
    }
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        ['addEmployeeModal', 'editEmployeeModal', 'viewEmployeeModal', 'deleteEmployeeModal'].forEach(id => closeModal(id));
    }
});

// ── Add Modal Trigger ──
function openAddModal() {
    document.getElementById('add_first_name').value = '';
    document.getElementById('add_last_name').value = '';
    document.getElementById('add_email').value = '';
    document.getElementById('add_password').value = '';
    document.getElementById('add_phone').value = '';
    document.getElementById('add_position').value = '';
    document.getElementById('add_salary').value = '';
    document.getElementById('add_status').value = 'active';
    document.getElementById('addAvatarFile').value = '';
    document.getElementById('addAvatarPreview').src = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'64\' height=\'64\' fill=\'%23cbd5e1\' viewBox=\'0 0 24 24\'%3E%3Cpath d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/%3E%3C/svg%3E';
    openModal('addEmployeeModal');
}

// ── Edit Modal Population ──
function openEditModal(emp) {
    document.getElementById('edit_employee_id').value = emp.id;
    document.getElementById('editModalHeading').textContent = `Edit: ${emp.first_name} ${emp.last_name}`;
    document.getElementById('edit_employee_code').value = emp.employee_id || `EMP-${emp.id}`;
    document.getElementById('edit_first_name').value = emp.first_name || '';
    document.getElementById('edit_last_name').value = emp.last_name || '';
    document.getElementById('edit_email').value = emp.email || '';
    document.getElementById('edit_password').value = '';
    document.getElementById('edit_phone').value = emp.phone || '';
    document.getElementById('edit_department').value = emp.department || '';
    document.getElementById('edit_position').value = emp.position || '';
    document.getElementById('edit_salary').value = emp.salary || '';
    document.getElementById('edit_hire_date').value = emp.hire_date || '';
    document.getElementById('edit_status').value = emp.status || 'active';
    document.getElementById('edit_work_mode').value = emp.work_mode || 'on_site';
    document.getElementById('edit_remove_avatar').value = '0';

    const prevImg = document.getElementById('editAvatarPreview');
    const removeBtn = document.getElementById('editAvatarRemoveBtn');

    if (emp.avatar) {
        prevImg.src = `/assets/uploads/avatars/${encodeURIComponent(emp.avatar)}`;
        removeBtn.style.display = 'block';
    } else {
        prevImg.src = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'64\' height=\'64\' fill=\'%23cbd5e1\' viewBox=\'0 0 24 24\'%3E%3Cpath d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/%3E%3C/svg%3E';
        removeBtn.style.display = 'none';
    }

    openModal('editEmployeeModal');
}

// ── Live Avatar Preview ──
function handleAvatarPreview(input, imgId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = (e) => {
            document.getElementById(imgId).src = e.target.result;
            if (imgId === 'editAvatarPreview') {
                document.getElementById('editAvatarRemoveBtn').style.display = 'block';
                document.getElementById('edit_remove_avatar').value = '0';
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function triggerRemoveAvatar() {
    document.getElementById('edit_remove_avatar').value = '1';
    document.getElementById('editAvatarFile').value = '';
    document.getElementById('editAvatarPreview').src = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'64\' height=\'64\' fill=\'%23cbd5e1\' viewBox=\'0 0 24 24\'%3E%3Cpath d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/%3E%3C/svg%3E';
    document.getElementById('editAvatarRemoveBtn').style.display = 'none';
}

// ── Password Generator & Visibility ──
function generatePassword(targetId) {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
    let pwd = '';
    for (let i = 0; i < 12; i++) {
        pwd += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById(targetId);
    input.value = pwd;
    input.type = 'text';

    // Copy to clipboard
    navigator.clipboard?.writeText(pwd);
    alert('Generated secure password: ' + pwd + '\n(Copied to clipboard)');
}

function togglePasswordVisibility(targetId, btn) {
    const input = document.getElementById(targetId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}

// ── View Details Modal Population ──
function openViewModal(emp) {
    const fullName = `${emp.first_name} ${emp.last_name}`;
    const code = emp.employee_id || `EMP-${emp.id}`;

    document.getElementById('viewFullName').textContent = fullName;
    document.getElementById('viewRole').textContent = emp.position || 'Staff Member';
    document.getElementById('viewDeptBadge').textContent = emp.department || 'General';
    document.getElementById('viewCodeBadge').textContent = code;

    // Avatar
    const avatarWrap = document.getElementById('viewAvatarWrap');
    if (emp.avatar) {
        avatarWrap.innerHTML = `<img src="/assets/uploads/avatars/${encodeURIComponent(emp.avatar)}" class="profile-hero-avatar" alt="${fullName}">`;
    } else {
        const initials = ((emp.first_name ? emp.first_name[0] : '') + (emp.last_name ? emp.last_name[0] : '')).toUpperCase() || 'EM';
        avatarWrap.innerHTML = `<div class="profile-hero-fallback">${initials}</div>`;
    }

    document.getElementById('viewEmail').innerHTML = `<a href="mailto:${encodeURIComponent(emp.email)}" style="color:var(--adm-brand); text-decoration:none;">${emp.email}</a>`;
    document.getElementById('viewPhone').textContent = emp.phone || 'Not specified';
    document.getElementById('viewSalary').textContent = emp.salary ? `₹${parseFloat(emp.salary).toLocaleString('en-IN', {minimumFractionDigits:2})}` : 'Not specified';
    
    // Hire date
    if (emp.hire_date) {
        const dt = new Date(emp.hire_date);
        document.getElementById('viewHireDate').textContent = dt.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
    } else {
        document.getElementById('viewHireDate').textContent = 'Not specified';
    }

    // Status
    const statusLabels = {
        'active': '<span style="color:#10b981; font-weight:700;"><i class="fa-solid fa-circle" style="font-size:0.5rem;"></i> Active</span>',
        'on_leave': '<span style="color:#f59e0b; font-weight:700;"><i class="fa-solid fa-circle" style="font-size:0.5rem;"></i> On Leave</span>',
        'inactive': '<span style="color:#94a3b8; font-weight:700;"><i class="fa-solid fa-circle" style="font-size:0.5rem;"></i> Inactive</span>'
    };
    document.getElementById('viewStatus').innerHTML = statusLabels[emp.status] || emp.status;

    // KYC
    const kycVal = parseInt(emp.aadhar_verified || 0, 10);
    let kycHtml = '<span class="emp-tag-badge emp-tag-hire">Unverified</span>';
    if (kycVal === 2) {
        kycHtml = '<span class="emp-tag-badge emp-tag-kyc-ok"><i class="fa-solid fa-circle-check"></i> Verified &amp; Approved</span>';
    } else if (kycVal === 1) {
        kycHtml = '<span class="emp-tag-badge emp-tag-kyc-pending"><i class="fa-solid fa-clock-rotate-left"></i> Under Review</span>';
    }
    document.getElementById('viewKycBadge').innerHTML = kycHtml;

    // Aadhaar document section
    const aadhaarSection = document.getElementById('viewAadhaarSection');
    if (emp.aadhar_image) {
        aadhaarSection.style.display = 'flex';
        document.getElementById('viewAadhaarDocLink').href = `employee?action=view_aadhar&id=${encodeURIComponent(emp.id)}`;
        document.getElementById('kycActionEmpId').value = emp.id;
        document.getElementById('kycApproveBtn').style.display = (kycVal === 2) ? 'none' : 'inline-flex';
    } else {
        aadhaarSection.style.display = 'none';
    }

    // Shortcuts
    document.getElementById('viewAttendanceLink').href = `attendance?filter_employee=${emp.id}`;
    document.getElementById('viewTasksLink').href = `task?employee_id=${emp.id}`;

    document.getElementById('viewEditBtn').onclick = () => {
        closeModal('viewEmployeeModal');
        openEditModal(emp);
    };

    openModal('viewEmployeeModal');
}

// ── Delete Confirmation Trigger ──
function confirmDelete(emp) {
    document.getElementById('delete_employee_id').value = emp.id;
    document.getElementById('delEmployeeName').textContent = `${emp.first_name} ${emp.last_name}`;
    document.getElementById('delEmployeeCode').textContent = emp.employee_id || `EMP-${emp.id}`;
    openModal('deleteEmployeeModal');
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
