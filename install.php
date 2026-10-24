<?php
/**
 * Cloud Aspire - Web Installer
 * Creates database, tables, and default admin account
 */

// Prevent access if already installed
$lockFile = __DIR__ . '/app/.installed';
$step = (int)($_GET['step'] ?? 1);

if (file_exists($lockFile)) {
    http_response_code(403);
    die('Access Denied: The system is already installed and locked for security. To reinstall, delete app/.installed.');
}

$error = '';
$success = '';

// ── Step 2: Process installation ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $dbHost     = trim($_POST['db_host'] ?? 'localhost');
    $dbName     = trim($_POST['db_name'] ?? 'cloudaspire');
    $dbUser     = trim($_POST['db_user'] ?? 'root');
    $dbPassword = $_POST['db_password'] ?? '';
    $adminUser  = trim($_POST['admin_username'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = $_POST['admin_password'] ?? '';
    $adminPassConfirm = $_POST['admin_password_confirm'] ?? '';
    $siteName   = trim($_POST['site_name'] ?? 'Cloud Aspire');

    // Validation
    if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
        $error = 'Database host, name, and username are required.';
    } elseif (empty($adminUser) || strlen($adminUser) < 3) {
        $error = 'Admin username must be at least 3 characters.';
    } elseif (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid admin email address.';
    } elseif (empty($adminPass) || strlen($adminPass) < 8) {
        $error = 'Admin password must be at least 8 characters.';
    } elseif ($adminPass !== $adminPassConfirm) {
        $error = 'Passwords do not match.';
    } elseif (!preg_match('/[A-Z]/', $adminPass) || !preg_match('/[a-z]/', $adminPass) || !preg_match('/[0-9]/', $adminPass)) {
        $error = 'Password must contain uppercase, lowercase, and a number.';
    } else {
        try {
            // 1. Connect to MySQL (without database)
            $pdoTemp = new PDO(
                "mysql:host=$dbHost;charset=utf8mb4",
                $dbUser,
                $dbPassword,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // 2. Create database if needed
            $pdoTemp->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdoTemp = null;

            // 3. Connect to the database
            $pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPassword,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            // 4. Create all tables
            // ── Admin table ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(100) DEFAULT NULL,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Employees table ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
                id INT AUTO_INCREMENT PRIMARY KEY,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR(255) DEFAULT NULL,
                phone VARCHAR(20),
                department VARCHAR(50),
                position VARCHAR(100),
                salary DECIMAL(10, 2),
                hire_date DATE,
                status ENUM('active', 'inactive', 'on_leave') DEFAULT 'active',
                avatar VARCHAR(255) DEFAULT NULL,
                aadhar_image VARCHAR(255) DEFAULT NULL,
                aadhar_verified TINYINT(1) DEFAULT 0,
                employee_id VARCHAR(10) DEFAULT NULL,
                first_login_completed TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Login attempts (rate limiting) ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                timestamp INT NOT NULL,
                INDEX idx_ip (ip),
                INDEX idx_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Attendance ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                date DATE NOT NULL,
                check_in TIME DEFAULT NULL,
                check_out TIME DEFAULT NULL,
                punch_history TEXT DEFAULT NULL,
                leave_code VARCHAR(50) DEFAULT NULL,
                status ENUM('present', 'absent', 'half_day', 'late', 'on_leave') DEFAULT 'present',
                attendance_status ENUM('present', 'delayed', 'left_early', 'completed', 'half_day', 'absent', 'on_leave') DEFAULT 'present',
                delay_minutes INT DEFAULT 0,
                early_exit_minutes INT DEFAULT 0,
                total_work_minutes INT DEFAULT 0,
                working_hours DECIMAL(5, 2) DEFAULT 0.00,
                notes TEXT DEFAULT NULL,
                remarks TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_emp_date (employee_id, date),
                FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Shift settings ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS shift_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shift_name VARCHAR(100) NOT NULL,
                checkin_start TIME NOT NULL,
                checkin_end TIME NOT NULL,
                checkout_time TIME NOT NULL,
                grace_minutes INT DEFAULT 0,
                late_threshold INT DEFAULT 0,
                early_exit_threshold INT DEFAULT 0,
                is_active TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Tasks ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                status ENUM('sent', 'in_progress', 'verify', 'completed') DEFAULT 'sent',
                due_date DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Projects ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                drive_link VARCHAR(2048) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'submitted',
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Project status updates ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS project_status_updates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                status VARCHAR(40) NOT NULL,
                note TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Leave requests ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS leave_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                leave_type VARCHAR(50) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                days INT NOT NULL DEFAULT 1,
                reason TEXT,
                status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                leave_code VARCHAR(20) DEFAULT NULL,
                approved_by INT DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Leave balances ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS leave_balances (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                leave_type VARCHAR(50) NOT NULL,
                year YEAR NOT NULL,
                total_days INT NOT NULL DEFAULT 0,
                used_days INT NOT NULL DEFAULT 0,
                UNIQUE KEY unique_balance (employee_id, leave_type, year),
                FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Leave code history ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS leave_code_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                leave_id INT NOT NULL,
                leave_code VARCHAR(20) NOT NULL,
                action VARCHAR(20) NOT NULL DEFAULT 'used',
                performed_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (leave_id) REFERENCES leave_requests(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── Site settings ──
            $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_name VARCHAR(120) NOT NULL,
                logo VARCHAR(255) NULL,
                favicon VARCHAR(255) NULL,
                smtp_host VARCHAR(255) NULL,
                smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
                smtp_encryption VARCHAR(10) NOT NULL DEFAULT 'tls',
                smtp_username VARCHAR(255) NULL,
                smtp_password TEXT NULL,
                smtp_from_email VARCHAR(255) NULL,
                smtp_from_name VARCHAR(120) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // 5. Insert default admin account
            $hashedPassword = password_hash($adminPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin WHERE username = ?");
            $stmt->execute([$adminUser]);
            if ((int)$stmt->fetchColumn() === 0) {
                $stmt = $pdo->prepare("INSERT INTO admin (username, email, password) VALUES (?, ?, ?)");
                $stmt->execute([$adminUser, $adminEmail, $hashedPassword]);
            }

            // 6. Insert default site settings
            $stmt = $pdo->query("SELECT COUNT(*) FROM site_settings");
            if ((int)$stmt->fetchColumn() === 0) {
                $stmt = $pdo->prepare("INSERT INTO site_settings (site_name, is_active) VALUES (?, 1)");
                $stmt->execute([$siteName]);
            }

            // 7. Insert default shift
            $stmt = $pdo->query("SELECT COUNT(*) FROM shift_settings");
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec("INSERT INTO shift_settings (shift_name, checkin_start, checkin_end, checkout_time, grace_minutes, late_threshold, early_exit_threshold, is_active)
                    VALUES ('Default Shift', '09:00:00', '09:30:00', '18:00:00', 15, 30, 30, 1)");
            }

            // 8. Create upload directories
            $dirs = [
                __DIR__ . '/assets/uploads',
                __DIR__ . '/assets/uploads/site',
                __DIR__ . '/assets/uploads/avatars',
                __DIR__ . '/assets/uploads/aadhar',
            ];
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }

            // 9. Write lock file
            file_put_contents($lockFile, json_encode([
                'installed_at' => date('Y-m-d H:i:s'),
                'db_name' => $dbName,
                'admin_user' => $adminUser,
                'php_version' => phpversion(),
            ]));

            // 10. Redirect to step 3
            header("Location: install.php?step=3");
            exit();

        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = 'Installation error: ' . $e->getMessage();
        }
    }

    // On error, go back to step 1 form with error
    if ($error) {
        $step = 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - Cloud Aspire HR System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --primary-glow: rgba(59, 130, 246, 0.25);
            --accent: #8b5cf6;
            --success: #10b981;
            --success-bg: #ecfdf5;
            --success-border: rgba(16, 185, 129, 0.2);
            --danger: #ef4444;
            --danger-bg: #fef2f2;
            --danger-border: rgba(239, 68, 68, 0.2);
            --warning: #f59e0b;
            --warning-bg: #fffbeb;
            --bg: #f0f2f5;
            --surface: #ffffff;
            --surface-alt: #f9fafb;
            --text: #111827;
            --text-sec: #4b5563;
            --text-muted: #9ca3af;
            --border: #e5e7eb;
            --border-hover: #d1d5db;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 40px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.04);
            --shadow-xl: 0 20px 60px rgba(0,0,0,0.1);
            --transition: 0.2s ease;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: var(--text);
            line-height: 1.6;
        }

        /* ── Animated Background ── */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(ellipse at 20% 50%, rgba(59,130,246,0.06) 0%, transparent 50%),
                        radial-gradient(ellipse at 80% 20%, rgba(139,92,246,0.06) 0%, transparent 50%),
                        radial-gradient(ellipse at 60% 80%, rgba(16,185,129,0.04) 0%, transparent 50%);
            animation: bgShift 20s ease-in-out infinite alternate;
            z-index: -1;
        }

        @keyframes bgShift {
            0%   { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-5%, -5%) rotate(3deg); }
        }

        /* ── Container ── */
        .installer {
            width: 100%;
            max-width: 640px;
            animation: fadeUp 0.6s ease-out;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Logo / Brand ── */
        .brand {
            text-align: center;
            margin-bottom: 36px;
        }

        .brand-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--radius-lg);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            box-shadow: 0 8px 32px var(--primary-glow);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { box-shadow: 0 8px 32px var(--primary-glow); }
            50% { box-shadow: 0 12px 48px rgba(59, 130, 246, 0.35); }
        }

        .brand-icon svg { width: 36px; height: 36px; fill: white; }

        .brand h1 {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--text), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .brand p {
            color: var(--text-sec);
            font-size: 15px;
            margin-top: 6px;
        }

        /* ── Steps Indicator ── */
        .steps {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 32px;
        }

        .step-dot {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .step-num {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            transition: all 0.4s ease;
        }

        .step-num.active {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            box-shadow: 0 4px 16px var(--primary-glow);
        }

        .step-num.completed {
            background: var(--success);
            color: white;
        }

        .step-num.pending {
            background: var(--border);
            color: var(--text-muted);
        }

        .step-line {
            width: 48px;
            height: 3px;
            border-radius: 4px;
            background: var(--border);
            transition: background 0.4s ease;
        }

        .step-line.done { background: var(--success); }

        .step-label {
            display: none;
        }

        /* ── Card ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .card-header {
            padding: 28px 32px;
            background: var(--surface-alt);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .card-header-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .card-header-icon.blue { background: rgba(59,130,246,0.1); color: var(--primary); }
        .card-header-icon.green { background: rgba(16,185,129,0.1); color: var(--success); }

        .card-header-icon svg { width: 24px; height: 24px; }

        .card-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
        }

        .card-header p {
            font-size: 14px;
            color: var(--text-sec);
            margin-top: 2px;
        }

        .card-body {
            padding: 32px;
        }

        /* ── Alerts ── */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius);
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .alert svg { width: 20px; height: 20px; flex-shrink: 0; margin-top: 1px; }

        .alert-error {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid var(--danger-border);
        }

        .alert-success {
            background: var(--success-bg);
            color: #065f46;
            border: 1px solid var(--success-border);
        }

        .alert-warning {
            background: var(--warning-bg);
            color: #92400e;
            border: 1px solid rgba(245,158,11,0.2);
        }

        /* ── Form ── */
        fieldset {
            border: none;
            padding: 0;
            margin: 0 0 28px;
        }

        legend {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-sec);
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
            width: 100%;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full { grid-column: 1 / -1; }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-sec);
            margin-bottom: 7px;
        }

        .form-group label .required {
            color: var(--danger);
            margin-left: 2px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface-alt);
            color: var(--text);
            font-family: inherit;
            font-size: 15px;
            transition: all var(--transition);
            outline: none;
        }

        .form-input:focus {
            border-color: var(--primary);
            background: var(--surface);
            box-shadow: 0 0 0 4px rgba(59,130,246,0.1);
        }

        .form-input::placeholder { color: var(--text-muted); }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
        }

        /* ── Buttons ── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 28px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            border-radius: var(--radius);
            cursor: pointer;
            border: none;
            transition: all var(--transition);
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            box-shadow: 0 4px 16px var(--primary-glow);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px var(--primary-glow);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            box-shadow: 0 4px 16px rgba(16,185,129,0.25);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(16,185,129,0.3);
        }

        .btn-block { width: 100%; }

        .btn svg { width: 18px; height: 18px; }

        /* ── Requirements Check ── */
        .req-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 28px;
        }

        .req-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: var(--surface-alt);
            transition: all var(--transition);
        }

        .req-item:hover { border-color: var(--border-hover); }

        .req-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .req-icon.pass { background: var(--success-bg); color: var(--success); }
        .req-icon.fail { background: var(--danger-bg); color: var(--danger); }

        .req-icon svg { width: 16px; height: 16px; }

        .req-text { flex: 1; }
        .req-text strong { font-size: 14px; display: block; }
        .req-text span { font-size: 12px; color: var(--text-muted); }

        /* ── Success Page ── */
        .success-page { text-align: center; }

        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--success), #059669);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(16,185,129,0.3);
            animation: scaleIn 0.5s ease-out;
        }

        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.5); }
            to   { opacity: 1; transform: scale(1); }
        }

        .success-icon svg { width: 40px; height: 40px; stroke: white; fill: none; }

        .success-page h2 {
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 10px;
            background: linear-gradient(135deg, var(--text), #059669);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .success-page p {
            color: var(--text-sec);
            font-size: 15px;
            margin-bottom: 8px;
        }

        .credentials-box {
            margin: 28px 0;
            padding: 24px;
            background: var(--surface-alt);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            text-align: left;
        }

        .credentials-box h3 {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-sec);
            margin-bottom: 16px;
        }

        .cred-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        .cred-row:last-child { border-bottom: none; }
        .cred-row .label { color: var(--text-sec); font-weight: 500; }
        .cred-row .value { color: var(--text); font-weight: 600; }

        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 28px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* ── Installed Warning ── */
        .installed-warning {
            text-align: center;
        }

        .installed-warning .warn-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: var(--warning-bg);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }

        .installed-warning .warn-icon svg {
            width: 36px;
            height: 36px;
            color: var(--warning);
        }

        /* ── Footer ── */
        .footer {
            text-align: center;
            margin-top: 28px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        /* ── Responsive ── */
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .card-body { padding: 24px 20px; }
            .card-header { padding: 20px; }
            .brand h1 { font-size: 24px; }
            .btn-group { flex-direction: column; }
            .step-line { width: 32px; }
        }

        /* ── Loading spinner ── */
        .btn.loading { pointer-events: none; opacity: 0.8; }
        .btn .spinner {
            display: none;
            width: 18px; height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        .btn.loading .spinner { display: block; }
        .btn.loading .btn-label { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Password strength ── */
        .password-strength {
            display: flex;
            gap: 4px;
            margin-top: 8px;
        }
        .strength-bar {
            height: 4px;
            flex: 1;
            border-radius: 4px;
            background: var(--border);
            transition: all 0.3s ease;
        }
        .strength-bar.active.weak { background: var(--danger); }
        .strength-bar.active.medium { background: var(--warning); }
        .strength-bar.active.strong { background: var(--success); }
    </style>
</head>
<body>
    <div class="installer">
        <!-- Brand -->
        <div class="brand">
            <div class="brand-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
            </div>
            <h1>Cloud Aspire Installer</h1>
            <p>Set up your HR system in seconds</p>
        </div>

        <!-- Steps -->
        <div class="steps">
            <div class="step-dot">
                <span class="step-num <?php echo $step >= 2 ? 'completed' : ($step === 1 ? 'active' : 'pending'); ?>">
                    <?php echo $step >= 2 ? '✓' : '1'; ?>
                </span>
            </div>
            <span class="step-line <?php echo $step >= 2 ? 'done' : ''; ?>"></span>
            <div class="step-dot">
                <span class="step-num <?php echo $step >= 3 ? 'completed' : ($step === 2 ? 'active' : 'pending'); ?>">
                    <?php echo $step >= 3 ? '✓' : '2'; ?>
                </span>
            </div>
            <span class="step-line <?php echo $step >= 3 ? 'done' : ''; ?>"></span>
            <div class="step-dot">
                <span class="step-num <?php echo $step >= 3 ? 'active' : 'pending'; ?>">3</span>
            </div>
        </div>

<?php if (file_exists($lockFile) && $step !== 3): ?>
        <!-- Already Installed -->
        <div class="card">
            <div class="card-body">
                <div class="installed-warning">
                    <div class="warn-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    </div>
                    <h2>Already Installed</h2>
                    <p>Cloud Aspire has already been installed. To reinstall, delete the file:</p>
                    <p style="margin-top:12px;padding:12px;background:var(--surface-alt);border-radius:var(--radius);font-family:monospace;font-size:13px;color:var(--text-sec);word-break:break-all;">
                        app/.installed
                    </p>
                    <div class="btn-group" style="margin-top:24px">
                        <a href="admin" class="btn btn-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            Go to Admin Panel
                        </a>
                        <a href="login" class="btn btn-success">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            Employee Login
                        </a>
                    </div>
                </div>
            </div>
        </div>

<?php elseif ($step === 1): ?>
        <!-- Step 1: Requirements Check + Configuration Form -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon blue">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="16" x2="12" y2="12"/>
                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                </div>
                <div>
                    <h2>System Requirements & Setup</h2>
                    <p>Configure your database and admin account</p>
                </div>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                <div class="alert alert-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <!-- Requirements -->
                <?php
                $reqs = [
                    ['PHP 8.0+',       version_compare(PHP_VERSION, '8.0.0', '>='), 'Current: PHP ' . PHP_VERSION],
                    ['PDO MySQL',      extension_loaded('pdo_mysql'),               'Database driver'],
                    ['OpenSSL',        extension_loaded('openssl'),                 'For SMTP encryption'],
                    ['mbstring',       extension_loaded('mbstring'),                'String handling'],
                    ['Writable: app/', is_writable(__DIR__ . '/app'),               'For lock file'],
                    ['Writable: assets/', is_writable(__DIR__ . '/assets'),         'For uploads'],
                ];
                $allPass = true;
                foreach ($reqs as $r) { if (!$r[1]) $allPass = false; }
                ?>
                <div class="req-list">
                    <?php foreach ($reqs as $r): ?>
                    <div class="req-item">
                        <div class="req-icon <?php echo $r[1] ? 'pass' : 'fail'; ?>">
                            <?php if ($r[1]): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="req-text">
                            <strong><?php echo $r[0]; ?></strong>
                            <span><?php echo $r[2]; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!$allPass): ?>
                <div class="alert alert-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span>Please fix the failing requirements before proceeding.</span>
                </div>
                <?php else: ?>

                <!-- Installation Form -->
                <form method="POST" action="install.php?step=2" id="installForm">
                    <fieldset>
                        <legend>🗄️ Database Configuration</legend>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Host <span class="required">*</span></label>
                                <input class="form-input" type="text" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>" required placeholder="localhost">
                            </div>
                            <div class="form-group">
                                <label>Database Name <span class="required">*</span></label>
                                <input class="form-input" type="text" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? 'cloudaspire'); ?>" required placeholder="cloudaspire">
                            </div>
                            <div class="form-group">
                                <label>Username <span class="required">*</span></label>
                                <input class="form-input" type="text" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? 'root'); ?>" required placeholder="root">
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input class="form-input" type="password" name="db_password" value="" placeholder="Leave empty for no password">
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>👤 Admin Account</legend>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Username <span class="required">*</span></label>
                                <input class="form-input" type="text" name="admin_username" value="<?php echo htmlspecialchars($_POST['admin_username'] ?? ''); ?>" required minlength="3" placeholder="admin">
                            </div>
                            <div class="form-group">
                                <label>Email <span class="required">*</span></label>
                                <input class="form-input" type="email" name="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>" required placeholder="admin@example.com">
                            </div>
                            <div class="form-group">
                                <label>Password <span class="required">*</span></label>
                                <input class="form-input" type="password" name="admin_password" id="adminPassword" required minlength="8" placeholder="Min 8 chars, uppercase, lowercase, number">
                                <div class="password-strength">
                                    <div class="strength-bar" id="str1"></div>
                                    <div class="strength-bar" id="str2"></div>
                                    <div class="strength-bar" id="str3"></div>
                                    <div class="strength-bar" id="str4"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Confirm Password <span class="required">*</span></label>
                                <input class="form-input" type="password" name="admin_password_confirm" required minlength="8" placeholder="Re-enter password">
                            </div>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>🌐 Site Settings</legend>
                        <div class="form-grid">
                            <div class="form-group full">
                                <label>Site Name</label>
                                <input class="form-input" type="text" name="site_name" value="<?php echo htmlspecialchars($_POST['site_name'] ?? 'Cloud Aspire'); ?>" placeholder="Cloud Aspire">
                                <span class="form-hint">The display name for your HR portal</span>
                            </div>
                        </div>
                    </fieldset>

                    <button type="submit" class="btn btn-primary btn-block" id="installBtn">
                        <span class="btn-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                            Install Cloud Aspire
                        </span>
                        <span class="spinner"></span>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

<?php elseif ($step === 3): ?>
        <!-- Step 3: Success -->
        <div class="card">
            <div class="card-body">
                <div class="success-page">
                    <div class="success-icon">
                        <svg viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </div>

                    <h2>Installation Complete!</h2>
                    <p>Cloud Aspire HR System has been successfully installed and is ready to use.</p>

                    <?php
                    $lockData = [];
                    if (file_exists($lockFile)) {
                        $lockData = json_decode(file_get_contents($lockFile), true) ?: [];
                    }
                    ?>
                    <div class="credentials-box">
                        <h3>Installation Details</h3>
                        <div class="cred-row">
                            <span class="label">Database</span>
                            <span class="value"><?php echo htmlspecialchars($lockData['db_name'] ?? 'cloudaspire'); ?></span>
                        </div>
                        <div class="cred-row">
                            <span class="label">Admin Username</span>
                            <span class="value"><?php echo htmlspecialchars($lockData['admin_user'] ?? 'admin'); ?></span>
                        </div>
                        <div class="cred-row">
                            <span class="label">Installed At</span>
                            <span class="value"><?php echo htmlspecialchars($lockData['installed_at'] ?? date('Y-m-d H:i:s')); ?></span>
                        </div>
                        <div class="cred-row">
                            <span class="label">PHP Version</span>
                            <span class="value"><?php echo htmlspecialchars($lockData['php_version'] ?? phpversion()); ?></span>
                        </div>
                    </div>

                    <div class="alert alert-warning" style="text-align:left">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                        <span><strong>Security Note:</strong> For production use, delete or rename <code>install.php</code> to prevent unauthorized access.</span>
                    </div>

                    <div class="btn-group">
                        <a href="admin" class="btn btn-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            Admin Panel
                        </a>
                        <a href="login" class="btn btn-success">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            Employee Login
                        </a>
                    </div>
                </div>
            </div>
        </div>
<?php endif; ?>

        <!-- Footer -->
        <div class="footer">
            Cloud Aspire &copy; <?php echo date('Y'); ?> &middot; Powered by PHP <?php echo phpversion(); ?>
        </div>
    </div>

    <script>
        // Password strength meter
        const passInput = document.getElementById('adminPassword');
        if (passInput) {
            const bars = [
                document.getElementById('str1'),
                document.getElementById('str2'),
                document.getElementById('str3'),
                document.getElementById('str4')
            ];

            passInput.addEventListener('input', function() {
                const val = this.value;
                let score = 0;
                if (val.length >= 8) score++;
                if (/[A-Z]/.test(val)) score++;
                if (/[a-z]/.test(val)) score++;
                if (/[0-9]/.test(val)) score++;
                if (/[^A-Za-z0-9]/.test(val)) score++;

                const level = score <= 2 ? 'weak' : score <= 3 ? 'medium' : 'strong';

                bars.forEach((bar, i) => {
                    bar.className = 'strength-bar';
                    if (i < score) {
                        bar.classList.add('active', level);
                    }
                });
            });
        }

        // Form loading state
        const form = document.getElementById('installForm');
        const btn = document.getElementById('installBtn');
        if (form && btn) {
            form.addEventListener('submit', function() {
                btn.classList.add('loading');
                btn.disabled = true;
            });
        }
    </script>
</body>
</html>
