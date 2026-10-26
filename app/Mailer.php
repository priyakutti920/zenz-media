<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Require Composer's autoloader
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';

class Mailer {
    private $pdo = null;
    private $host = '';
    private $username = '';
    private $password = '';
    private $port = 587;
    private $encryption = 'tls';
    private $fromEmail = '';
    private $fromName = 'Cloud Aspire';
    private $adminEmail = '';
    private $isConfigured = false;

    public function __construct($pdo = null) {
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
        } else {
            global $pdo;
            $this->pdo = $pdo;
        }
        $this->loadSettings();
    }

    /**
     * Load SMTP and branding settings from the database
     */
    private function loadSettings() {
        if (!$this->pdo) return;

        try {
            // Load site / SMTP settings
            $stmt = $this->pdo->query("SELECT * FROM site_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($settings) {
                $this->host = $settings['smtp_host'] ?? '';
                $this->port = (int)($settings['smtp_port'] ?? 587);
                $this->encryption = strtolower($settings['smtp_encryption'] ?? 'tls');
                $this->username = $settings['smtp_username'] ?? '';
                $this->password = $this->decryptSmtpPassword($settings['smtp_password'] ?? '');
                $this->fromEmail = $settings['smtp_from_email'] ?? '';
                $this->fromName = !empty($settings['smtp_from_name']) ? $settings['smtp_from_name'] : (!empty($settings['site_name']) ? $settings['site_name'] : 'Cloud Aspire');

                if (!empty($this->host) && !empty($this->fromEmail)) {
                    $this->isConfigured = true;
                }
            }

            // Load primary Admin Email from admin table
            $adminStmt = $this->pdo->query("SELECT email FROM admin WHERE email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1");
            $adminEmail = $adminStmt->fetchColumn();
            if (!empty($adminEmail)) {
                $this->adminEmail = $adminEmail;
            } elseif (!empty($this->fromEmail)) {
                $this->adminEmail = $this->fromEmail;
            }
        } catch (PDOException $e) {
            error_log("Mailer Settings Load Error: " . $e->getMessage());
        }
    }

    /**
     * Decrypt SMTP password stored in site_settings
     */
    private function decryptSmtpPassword($stored) {
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

    /**
     * Resolves security encryption and handles port/encryption auto-correction
     */
    public static function normalizeSecurity($port, $encryption) {
        $port = (int)$port;
        $enc = strtolower(trim((string)$encryption));

        // RFC standard correction: Port 587 is STARTTLS submission. Implicit SSL fails immediately.
        if ($port === 587 && $enc === 'ssl') {
            $enc = 'tls';
        }
        // Port 465 is SMTPS (Implicit SSL wrapper). STARTTLS fails immediately on 465.
        if ($port === 465 && $enc === 'tls') {
            $enc = 'ssl';
        }

        if ($enc === 'ssl') {
            return [
                'secure' => PHPMailer::ENCRYPTION_SMTPS,
                'autoTls' => false,
                'encryption' => 'ssl'
            ];
        } elseif ($enc === 'tls') {
            return [
                'secure' => PHPMailer::ENCRYPTION_STARTTLS,
                'autoTls' => true,
                'encryption' => 'tls'
            ];
        } else {
            return [
                'secure' => '',
                'autoTls' => false,
                'encryption' => 'none'
            ];
        }
    }

    /**
     * Initializes PHPMailer with dynamic SMTP config
     */
    private function getMailer() {
        if (!$this->isConfigured) {
            // SMTP is not yet configured in site_settings
            return null;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Timeout = 6;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ];
            $mail->Host     = $this->host;
            $mail->SMTPAuth = !empty($this->username);
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            
            $sec = self::normalizeSecurity($this->port, $this->encryption);
            $mail->SMTPSecure  = $sec['secure'];
            $mail->SMTPAutoTLS = $sec['autoTls'];

            $mail->Port = $this->port;
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->isHTML(true);
            return $mail;
        } catch (Exception $e) {
            error_log("PHPMailer Init Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Build an ultra-modern, responsive SaaS HTML email layout
     */
    private function formatEmailTemplate($heading, $preheader, $bodyContentHtml, $ctaText = null, $ctaUrl = null, $badge = null, $badgeColor = '#4f46e5') {
        $brandName = htmlspecialchars($this->fromName ?: 'Cloud Aspire');
        $year = date('Y');
        $preheaderEscaped = htmlspecialchars($preheader);
        
        $badgeHtml = '';
        if ($badge) {
            $badgeText = htmlspecialchars($badge);
            $badgeHtml = "<span style=\"display: inline-block; padding: 4px 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #ffffff; background-color: {$badgeColor}; border-radius: 9999px; margin-bottom: 12px;\">{$badgeText}</span>";
        }

        $ctaHtml = '';
        if ($ctaText && $ctaUrl) {
            $ctaTextEsc = htmlspecialchars($ctaText);
            $ctaUrlEsc = htmlspecialchars($ctaUrl);
            $ctaHtml = "
            <div style=\"margin: 28px 0 10px 0; text-align: center;\">
                <a href=\"{$ctaUrlEsc}\" target=\"_blank\" rel=\"noopener noreferrer\" style=\"display: inline-block; padding: 13px 28px; background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%); color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35); letter-spacing: 0.02em;\">
                    {$ctaTextEsc} &rarr;
                </a>
            </div>";
        }

        return "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>{$heading}</title>
</head>
<body style=\"margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;\">
    <div style=\"display: none; max-height: 0px; overflow: hidden;\">{$preheaderEscaped}</div>

    <table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"background-color: #f1f5f9; padding: 32px 16px;\">
        <tr>
            <td align=\"center\">
                <table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"max-width: 580px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04); border: 1px solid #e2e8f0;\">
                    <!-- Brand Header -->
                    <tr>
                        <td style=\"background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%); padding: 24px 32px; text-align: left; border-bottom: 2px solid #4f46e5;\">
                            <div style=\"font-size: 20px; font-weight: 800; color: #ffffff; letter-spacing: -0.02em;\">
                                {$brandName}
                            </div>
                            <div style=\"font-size: 11px; color: #94a3b8; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600;\">
                                Enterprise Workforce & Management
                            </div>
                        </td>
                    </tr>

                    <!-- Main Email Body -->
                    <tr>
                        <td style=\"padding: 32px; color: #334155; font-size: 15px; line-height: 1.6;\">
                            {$badgeHtml}
                            <h1 style=\"margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #0f172a; letter-spacing: -0.01em;\">
                                {$heading}
                            </h1>

                            <div style=\"color: #475569; font-size: 14px; line-height: 1.65;\">
                                {$bodyContentHtml}
                            </div>

                            {$ctaHtml}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style=\"background-color: #f8fafc; padding: 20px 32px; border-top: 1px solid #e2e8f0; text-align: center; color: #94a3b8; font-size: 12px; line-height: 1.5;\">
                            <p style=\"margin: 0 0 4px 0;\">This is an automated system notification from <strong>{$brandName}</strong>.</p>
                            <p style=\"margin: 0;\">&copy; {$year} {$brandName}. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
    }

    /**
     * Send email to an employee when they login
     */
    public function sendEmployeeLoginAlert($employeeEmail, $employeeName, $ipAddress = '', $userAgent = '') {
        $mail = $this->getMailer();
        if (!$mail) return false;

        $ip = $ipAddress ?: ($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
        $ua = $userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown browser');
        $timeStr = date('d M Y, h:i A') . ' IST';

        $bodyHtml = "
            <p>Hello <strong>" . htmlspecialchars($employeeName) . "</strong>,</p>
            <p>A new login was just detected on your employee portal account.</p>
            <table style=\"width: 100%; border-collapse: collapse; margin: 16px 0; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;\">
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px; width: 120px; border-bottom: 1px solid #e2e8f0;\">Timestamp</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px; border-bottom: 1px solid #e2e8f0;\">{$timeStr}</td></tr>
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px; width: 120px; border-bottom: 1px solid #e2e8f0;\">IP Address</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px; border-bottom: 1px solid #e2e8f0;\">" . htmlspecialchars($ip) . "</td></tr>
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px;\">Device / Client</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px;\">" . htmlspecialchars(substr($ua, 0, 100)) . "</td></tr>
            </table>
            <p style=\"color: #64748b; font-size: 13px;\">If this was you, you can safely disregard this email. If you did not recognize this activity, please contact your workspace administrator immediately.</p>
        ";

        $portalUrl = (isset($_SERVER['HTTP_HOST']) ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '') . '/employee_dashboard';

        try {
            $mail->addAddress($employeeEmail, $employeeName);
            $mail->Subject = 'Security Alert: New Employee Login Detected';
            $mail->Body = $this->formatEmailTemplate('New Login Detected', 'Security Alert: New login on your account', $bodyHtml, 'Go to Employee Portal', $portalUrl, 'Security Alert', '#0284c7');
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Employee Login): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to Admin when admin logs in
     */
    public function sendAdminLoginAlert($adminEmail, $adminName, $ipAddress = '', $userAgent = '') {
        $recipient = !empty($adminEmail) ? $adminEmail : $this->adminEmail;
        if (empty($recipient)) return false;

        $mail = $this->getMailer();
        if (!$mail) return false;

        $ip = $ipAddress ?: ($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
        $ua = $userAgent ?: ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown browser');
        $timeStr = date('d M Y, h:i A') . ' IST';

        $bodyHtml = "
            <p>Hello <strong>" . htmlspecialchars($adminName) . "</strong>,</p>
            <p>An administrator login has been registered on the management console.</p>
            <table style=\"width: 100%; border-collapse: collapse; margin: 16px 0; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;\">
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px; width: 120px; border-bottom: 1px solid #e2e8f0;\">Timestamp</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px; border-bottom: 1px solid #e2e8f0;\">{$timeStr}</td></tr>
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px; width: 120px; border-bottom: 1px solid #e2e8f0;\">IP Address</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px; border-bottom: 1px solid #e2e8f0;\">" . htmlspecialchars($ip) . "</td></tr>
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px;\">Device / Client</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px;\">" . htmlspecialchars(substr($ua, 0, 100)) . "</td></tr>
            </table>
            <p style=\"color: #64748b; font-size: 13px;\">If this was not authorized by you, please check server logs and change your administrative credentials immediately.</p>
        ";

        $adminUrl = (isset($_SERVER['HTTP_HOST']) ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '') . '/admin/dashboard';

        try {
            $mail->addAddress($recipient, $adminName);
            $mail->Subject = 'Security Notice: Administrator Console Login';
            $mail->Body = $this->formatEmailTemplate('Admin Login Notice', 'Administrator session authenticated', $bodyHtml, 'Open Admin Console', $adminUrl, 'Security Notice', '#4f46e5');
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Admin Login): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to an employee when a new task is assigned to them
     */
    public function sendTaskAssignedAlert($employeeEmail, $employeeName, $taskTitle, $priority, $dueDate, $description) {
        $mail = $this->getMailer();
        if (!$mail) return false;

        $pColors = [
            'critical' => '#ef4444',
            'high'     => '#f97316',
            'medium'   => '#3b82f6',
            'low'      => '#10b981'
        ];
        $priorityColor = $pColors[strtolower($priority)] ?? '#3b82f6';
        $priorityLabel = ucfirst($priority ?: 'Medium');
        $dueLabel = $dueDate ? date('d M Y', strtotime($dueDate)) : 'No Due Date Set';
        $descSnippet = !empty($description) ? nl2br(htmlspecialchars($description)) : '<em>No task description provided.</em>';

        $bodyHtml = "
            <p>Hello <strong>" . htmlspecialchars($employeeName) . "</strong>,</p>
            <p>You have been assigned a new task by your administrator.</p>

            <div style=\"background: #f8fafc; border-left: 4px solid {$priorityColor}; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 18px 0; border-top: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;\">
                <div style=\"font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 10px;\">
                    " . htmlspecialchars($taskTitle) . "
                </div>
                <div style=\"margin-bottom: 8px;\">
                    <span style=\"display: inline-block; padding: 2px 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #ffffff; background-color: {$priorityColor}; border-radius: 4px;\">
                        {$priorityLabel} Priority
                    </span>
                    <span style=\"margin-left: 12px; font-size: 13px; color: #64748b;\">
                        <strong>Due Date:</strong> {$dueLabel}
                    </span>
                </div>
                <div style=\"font-size: 13px; color: #334155; line-height: 1.6; margin-top: 12px; border-top: 1px dashed #cbd5e1; padding-top: 10px;\">
                    <strong>Instructions / Overview:</strong><br>
                    {$descSnippet}
                </div>
            </div>

            <p style=\"font-size: 13px; color: #64748b;\">Please review this task on your workspace board and update its status as you make progress.</p>
        ";

        $taskUrl = (isset($_SERVER['HTTP_HOST']) ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '') . '/employee_task';

        try {
            $mail->addAddress($employeeEmail, $employeeName);
            $mail->Subject = 'New Task Assigned: ' . $taskTitle;
            $mail->Body = $this->formatEmailTemplate('New Task Assigned', 'You have been assigned: ' . $taskTitle, $bodyHtml, 'View Task & Start Work', $taskUrl, 'Task Assignment', $priorityColor);
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Task Assigned): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to an employee when their assigned task is updated
     */
    public function sendTaskUpdatedAlert($employeeEmail, $employeeName, $taskTitle, $priority, $dueDate, $status, $description) {
        $mail = $this->getMailer();
        if (!$mail) return false;

        $pColors = [
            'critical' => '#ef4444',
            'high'     => '#f97316',
            'medium'   => '#3b82f6',
            'low'      => '#10b981'
        ];
        $priorityColor = $pColors[strtolower($priority)] ?? '#3b82f6';
        $priorityLabel = ucfirst($priority ?: 'Medium');
        $dueLabel = $dueDate ? date('d M Y', strtotime($dueDate)) : 'No Due Date Set';
        $statusLabel = ucwords(str_replace('_', ' ', $status));

        $bodyHtml = "
            <p>Hello <strong>" . htmlspecialchars($employeeName) . "</strong>,</p>
            <p>Details for your assigned task <strong>" . htmlspecialchars($taskTitle) . "</strong> have been updated by your administrator.</p>

            <table style=\"width: 100%; border-collapse: collapse; margin: 16px 0; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;\">
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px; width: 120px; border-bottom: 1px solid #e2e8f0;\">Task</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px; font-weight: 600; border-bottom: 1px solid #e2e8f0;\">" . htmlspecialchars($taskTitle) . "</td></tr>
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px; border-bottom: 1px solid #e2e8f0;\">Priority</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px; border-bottom: 1px solid #e2e8f0;\">{$priorityLabel}</td></tr>
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px; border-bottom: 1px solid #e2e8f0;\">Current Status</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px; border-bottom: 1px solid #e2e8f0;\">{$statusLabel}</td></tr>
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px;\">Due Date</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px;\">{$dueLabel}</td></tr>
            </table>

            <p style=\"font-size: 13px; color: #64748b;\">Check your task dashboard to see the latest requirements and timeline.</p>
        ";

        $taskUrl = (isset($_SERVER['HTTP_HOST']) ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '') . '/employee_task';

        try {
            $mail->addAddress($employeeEmail, $employeeName);
            $mail->Subject = 'Task Updated: ' . $taskTitle;
            $mail->Body = $this->formatEmailTemplate('Task Details Updated', 'Your task details have changed: ' . $taskTitle, $bodyHtml, 'View Updated Task', $taskUrl, 'Task Update', '#6366f1');
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Task Updated): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to an employee when an admin assigns / receives a project for them
     */
    public function sendProjectAssignedAlert($employeeEmail, $employeeName, $projectTitle, $driveLink, $description) {
        $mail = $this->getMailer();
        if (!$mail) return false;

        $descSnippet = !empty($description) ? nl2br(htmlspecialchars($description)) : '<em>No additional project description provided.</em>';
        $driveEsc = htmlspecialchars($driveLink);

        $bodyHtml = "
            <p>Hello <strong>" . htmlspecialchars($employeeName) . "</strong>,</p>
            <p>A new project has been registered / assigned to your profile in the administrative workspace.</p>

            <div style=\"background: #f8fafc; border-left: 4px solid #4f46e5; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 18px 0; border-top: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;\">
                <div style=\"font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 10px;\">
                    " . htmlspecialchars($projectTitle) . "
                </div>
                <div style=\"font-size: 13px; color: #334155; line-height: 1.6;\">
                    <strong>Description / Scope:</strong><br>
                    {$descSnippet}
                </div>
                <div style=\"margin-top: 14px; padding-top: 10px; border-top: 1px dashed #cbd5e1; font-size: 13px;\">
                    <strong>Google Drive Repository:</strong><br>
                    <a href=\"{$driveEsc}\" target=\"_blank\" rel=\"noopener noreferrer\" style=\"color: #2563eb; text-decoration: underline; word-break: break-all;\">{$driveEsc}</a>
                </div>
            </div>

            <p style=\"font-size: 13px; color: #64748b;\">You can track, review, and submit revisions directly from your employee project dashboard.</p>
        ";

        $projectUrl = (isset($_SERVER['HTTP_HOST']) ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '') . '/employee_project';

        try {
            $mail->addAddress($employeeEmail, $employeeName);
            $mail->Subject = 'New Project Assigned: ' . $projectTitle;
            $mail->Body = $this->formatEmailTemplate('New Project Assignment', 'New project registered: ' . $projectTitle, $bodyHtml, 'Open Employee Projects', $projectUrl, 'Project Portal', '#4f46e5');
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Project Assigned): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to an employee when an admin updates the status or feedback on their project
     */
    public function sendProjectStatusAlert($employeeEmail, $employeeName, $projectTitle, $status, $note) {
        $mail = $this->getMailer();
        if (!$mail) return false;

        $statusColors = [
            'approved'          => '#059669',
            'submitted'         => '#0284c7',
            'under_review'      => '#7c3aed',
            'changes_requested' => '#ea580c',
            'on_hold'           => '#d97706',
            'completed'         => '#16a34a',
            'rejected'          => '#dc2626'
        ];
        $statusLabels = [
            'submitted'         => 'Submitted',
            'under_review'      => 'Under Review',
            'changes_requested' => 'Changes Requested',
            'approved'          => 'Approved',
            'on_hold'           => 'On Hold',
            'completed'         => 'Completed',
            'rejected'          => 'Rejected'
        ];

        $statusKey = strtolower($status);
        $badgeColor = $statusColors[$statusKey] ?? '#4f46e5';
        $statusLabel = $statusLabels[$statusKey] ?? ucwords(str_replace('_', ' ', $status));
        $noteHtml = !empty($note) ? nl2br(htmlspecialchars($note)) : '<em>No additional review notes provided.</em>';

        $bodyHtml = "
            <p>Hello <strong>" . htmlspecialchars($employeeName) . "</strong>,</p>
            <p>The status of your project <strong>" . htmlspecialchars($projectTitle) . "</strong> has been updated by the administrator.</p>

            <table style=\"width: 100%; border-collapse: collapse; margin: 16px 0; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;\">
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px; width: 120px; border-bottom: 1px solid #e2e8f0;\">Project</td><td style=\"padding: 10px 14px; color: #0f172a; font-size: 13px; font-weight: 600; border-bottom: 1px solid #e2e8f0;\">" . htmlspecialchars($projectTitle) . "</td></tr>
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px; border-bottom: 1px solid #e2e8f0;\">New Status</td><td style=\"padding: 10px 14px; font-size: 13px; border-bottom: 1px solid #e2e8f0;\"><span style=\"display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 700; color: #ffffff; background-color: {$badgeColor}; border-radius: 4px;\">{$statusLabel}</span></td></tr>
                <tr><td style=\"padding: 10px 14px; font-weight: 600; color: #475569; font-size: 13px;\">Review Note</td><td style=\"padding: 10px 14px; color: #334155; font-size: 13px; line-height: 1.5;\">{$noteHtml}</td></tr>
            </table>

            <p style=\"font-size: 13px; color: #64748b;\">Please visit the project console to take any requested actions or review approval details.</p>
        ";

        $projectUrl = (isset($_SERVER['HTTP_HOST']) ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '') . '/employee_project';

        try {
            $mail->addAddress($employeeEmail, $employeeName);
            $mail->Subject = "Project Status Update: {$projectTitle} [{$statusLabel}]";
            $mail->Body = $this->formatEmailTemplate("Project {$statusLabel}", "Status updated for {$projectTitle}: {$statusLabel}", $bodyHtml, 'View Project Details', $projectUrl, "Status: {$statusLabel}", $badgeColor);
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Project Status): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to Admin when an employee requests leave
     */
    public function sendLeaveRequestAlert($employeeName, $leaveType, $startDate, $endDate, $reason) {
        if (empty($this->adminEmail)) return false;

        $mail = $this->getMailer();
        if (!$mail) return false;
        
        try {
            $mail->addAddress($this->adminEmail);
            $mail->Subject = "New Leave Request from {$employeeName}";
            $mail->Body    = "<h3>New Leave Request Submitted</h3>
                              <p><strong>Employee:</strong> " . htmlspecialchars($employeeName) . "</p>
                              <p><strong>Type:</strong> " . htmlspecialchars($leaveType) . "</p>
                              <p><strong>Dates:</strong> " . htmlspecialchars($startDate) . " to " . htmlspecialchars($endDate) . "</p>
                              <p><strong>Reason:</strong> " . nl2br(htmlspecialchars($reason)) . "</p>
                              <p>Please log in to the admin panel to approve or reject this request.</p>";
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Leave Alert): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to Employee when their leave request is approved
     */
    public function sendLeaveApprovalAlert($toEmail, $employeeName, $leaveType, $leaveCode, $startDate, $endDate, $days) {
        if (empty($toEmail)) return false;

        $mail = $this->getMailer();
        if (!$mail) return false;

        try {
            $mail->addAddress($toEmail);
            $mail->Subject = "Leave Request Approved &bull; {$leaveCode}";
            $mail->Body    = "<h3>Your Leave Request Has Been Approved</h3>
                              <p>Dear " . htmlspecialchars($employeeName) . ",</p>
                              <p>Your request for <strong>" . htmlspecialchars($leaveType) . "</strong> has been approved by the administrator.</p>
                              <div style='background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:16px;margin:16px 0;'>
                                  <p style='margin:0 0 8px;'><strong>Official Leave Code:</strong> <span style='font-family:monospace;font-size:1.2em;color:#166534;font-weight:bold;'>" . htmlspecialchars($leaveCode) . "</span></p>
                                  <p style='margin:0 0 8px;'><strong>Duration:</strong> " . (int)$days . " Day(s)</p>
                                  <p style='margin:0;'><strong>Period:</strong> " . htmlspecialchars($startDate) . " to " . htmlspecialchars($endDate) . "</p>
                              </div>
                              <p>Your attendance roster has been updated accordingly.</p>";
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Leave Approval Alert): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to Employee when their leave request is rejected
     */
    public function sendLeaveRejectionAlert($toEmail, $employeeName, $leaveType, $startDate, $endDate, $rejectionReason = '') {
        if (empty($toEmail)) return false;

        $mail = $this->getMailer();
        if (!$mail) return false;

        try {
            $mail->addAddress($toEmail);
            $mail->Subject = "Leave Request Update: Declined";
            $mail->Body    = "<h3>Leave Request Update</h3>
                              <p>Dear " . htmlspecialchars($employeeName) . ",</p>
                              <p>Your request for <strong>" . htmlspecialchars($leaveType) . "</strong> from " . htmlspecialchars($startDate) . " to " . htmlspecialchars($endDate) . " could not be approved at this time.</p>
                              " . (!empty($rejectionReason) ? "<p><strong>Reason provided:</strong> " . nl2br(htmlspecialchars($rejectionReason)) . "</p>" : "") . "
                              <p>Please contact human resources or your administrator if you have any questions.</p>";
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Leave Rejection Alert): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to Admin when a project is submitted
     */
    public function sendProjectSubmissionAlert($employeeName, $projectTitle, $driveLink) {
        if (empty($this->adminEmail)) return false;

        $mail = $this->getMailer();
        if (!$mail) return false;
        
        try {
            $mail->addAddress($this->adminEmail);
            $mail->Subject = "New Project Submitted: {$projectTitle}";
            $mail->Body    = "<h3>New Project Submission</h3>
                              <p><strong>Employee:</strong> " . htmlspecialchars($employeeName) . "</p>
                              <p><strong>Project:</strong> " . htmlspecialchars($projectTitle) . "</p>
                              <p><strong>Drive Link:</strong> <a href='" . htmlspecialchars($driveLink) . "'>" . htmlspecialchars($driveLink) . "</a></p>
                              <p>Please log in to review the submission.</p>";
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Project Alert): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to Admin when a task status is updated
     */
    public function sendTaskUpdateAlert($employeeName, $taskTitle, $newStatus) {
        if (empty($this->adminEmail)) return false;

        $mail = $this->getMailer();
        if (!$mail) return false;
        
        try {
            $mail->addAddress($this->adminEmail);
            $mail->Subject = "Task Update: {$taskTitle}";
            $mail->Body    = "<h3>Task Status Updated</h3>
                              <p><strong>Employee:</strong> " . htmlspecialchars($employeeName) . "</p>
                              <p><strong>Task:</strong> " . htmlspecialchars($taskTitle) . "</p>
                              <p><strong>New Status:</strong> " . htmlspecialchars($newStatus) . "</p>";
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error (Task Alert): " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send email to an employee asking them to complete their Daily Work Summary
     *
     * @param string $employeeEmail
     * @param string $employeeName
     * @param string $summaryUrl Secure expiring link with single-use token
     * @param string $shiftDate Formatted date string (e.g. Sat, Oct 24, 2026)
     * @param string $scheduledCheckoutTime Formatted time string (e.g. 04:20 PM)
     * @param string $shiftName Shift name (e.g. Regular Shift)
     * @return bool
     */
    public function sendDailySummaryRequestEmail($employeeEmail, $employeeName, $summaryUrl, $shiftDate, $scheduledCheckoutTime, $shiftName = 'Regular Shift') {
        $instance = isset($this) ? $this : new self();
        $mail = $instance->getMailer();
        if (!$mail) {
            return ['success' => false, 'error' => 'SMTP mailer is not configured or failed to initialize.'];
        }

        $bodyHtml = "
            <p>Hi <strong>" . htmlspecialchars($employeeName) . "</strong>,</p>
            <p>Your scheduled shift (<strong>" . htmlspecialchars($shiftName) . "</strong>) has ended. Please complete your daily work summary using the secure link below.</p>
            
            <div style=\"background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; margin: 18px 0;\">
                <div style=\"font-size: 13px; font-weight: 600; color: #0f172a; margin-bottom: 6px;\">Summary Requirements:</div>
                <ul style=\"margin: 0; padding-left: 20px; font-size: 13px; color: #475569; line-height: 1.6;\">
                    <li><strong>Tasks worked on:</strong> Please describe your work.</li>
                    <li><strong>Tasks completed:</strong> Please list completed tasks.</li>
                    <li><strong>Pending tasks:</strong> Optional.</li>
                </ul>
            </div>

            <p style=\"font-size: 13px; color: #334155;\">Once you submit your summary, your attendance for this shift (<strong>" . htmlspecialchars($shiftDate) . "</strong>) will be finalized automatically using the scheduled shift checkout time (<strong>" . htmlspecialchars($scheduledCheckoutTime) . "</strong>).</p>

            <p style=\"font-size: 12px; color: #64748b; margin-top: 14px;\"><em>If you have already completed your summary, no further action is required.</em></p>
        ";

        try {
            $mail->addAddress($employeeEmail, $employeeName);
            $mail->Subject = 'Action Required: Complete Your Daily Work Summary';
            $mail->Body = $instance->formatEmailTemplate(
                'Complete Your Daily Work Summary',
                'Action Required: Complete your daily work summary for ' . $shiftDate,
                $bodyHtml,
                'Complete Daily Work Summary',
                $summaryUrl,
                'Action Required',
                '#d97706'
            );
            $sent = $mail->send();
            return [
                'success' => (bool)$sent,
                'error'   => $sent ? null : ($mail->ErrorInfo ?: 'Unknown mail delivery error')
            ];
        } catch (Exception $e) {
            error_log("Mailer Error (Daily Work Summary): " . $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * Test SMTP Connection and send diagnostic test email with clean diagnostics
     */
    public function testSmtpConnection($targetEmail, $customConfig = null) {
        if (empty($targetEmail) || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid destination test email address.'];
        }

        $host = trim((string)($customConfig['host'] ?? $this->host));
        $port = (int)($customConfig['port'] ?? $this->port);
        $rawEncryption = strtolower(trim((string)($customConfig['encryption'] ?? $this->encryption)));
        $username = trim((string)($customConfig['username'] ?? $this->username));
        $password = isset($customConfig['password']) ? (string)$customConfig['password'] : $this->password;
        $fromEmail = trim((string)($customConfig['from_email'] ?? $this->fromEmail));
        $fromName = trim((string)($customConfig['from_name'] ?? $this->fromName));

        if (empty($host) || empty($fromEmail)) {
            return ['success' => false, 'message' => 'SMTP Host and From Email are required to test connection.'];
        }

        // Auto-correct incompatible port and encryption
        $sec = self::normalizeSecurity($port, $rawEncryption);
        $effectiveEncryption = $sec['encryption'];
        $wasAutoCorrected = ($rawEncryption !== $effectiveEncryption);

        // Pre-flight TCP socket connectivity check
        $socketHost = ($effectiveEncryption === 'ssl') ? 'ssl://' . $host : $host;
        $errno = 0;
        $errstr = '';
        $socketTimeout = 5;
        $fp = @fsockopen($socketHost, $port, $errno, $errstr, $socketTimeout);
        if (!$fp) {
            $friendlySocketError = "Cannot connect to SMTP server at {$host}:{$port} ({$errstr} [Code {$errno}]). ";
            if ($port === 587 && $rawEncryption === 'ssl') {
                $friendlySocketError .= "Port 587 does not accept implicit SSL. Please use TLS (STARTTLS).";
            } elseif ($port === 465 && $rawEncryption === 'tls') {
                $friendlySocketError .= "Port 465 is an SSL-wrapped port. Please choose SSL encryption.";
            } else {
                $friendlySocketError .= "Please verify that the host name and port are correct, and that outbound traffic is allowed by your network firewall.";
            }
            return [
                'success' => false,
                'message' => 'Network Connection Error: ' . $friendlySocketError,
                'debug' => "fsockopen({$socketHost}, {$port}) failed: {$errstr} ({$errno})"
            ];
        }
        fclose($fp);

        $debugOutput = [];
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function($str, $level) use (&$debugOutput) {
                $debugOutput[] = trim($str);
            };

            $mail->Timeout = 10;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ];

            $mail->Host       = $host;
            $mail->SMTPAuth   = !empty($username);
            $mail->Username   = $username;
            $mail->Password   = $password;
            $mail->SMTPSecure  = $sec['secure'];
            $mail->SMTPAutoTLS = $sec['autoTls'];
            $mail->Port       = $port;

            $mail->setFrom($fromEmail, $fromName ?: 'Enterprise Portal');
            $mail->addAddress($targetEmail);
            $mail->isHTML(true);
            $mail->Subject = 'SMTP Test Verification - ' . ($fromName ?: 'Enterprise Portal');
            $mail->Body    = "<div style='font-family: Arial, sans-serif; padding: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;'>
                                <h2 style='color: #4f46e5; margin-top: 0;'>SMTP Handshake & Delivery Verified!</h2>
                                <p>Congratulations! Outgoing SMTP email dispatch is operational.</p>
                                <ul>
                                    <li><strong>SMTP Host:</strong> " . htmlspecialchars($host) . "</li>
                                    <li><strong>Port / Security:</strong> " . htmlspecialchars($port) . " / " . strtoupper(htmlspecialchars($effectiveEncryption)) . "</li>
                                    <li><strong>Sender Identity:</strong> " . htmlspecialchars($fromName) . " &lt;" . htmlspecialchars($fromEmail) . "&gt;</li>
                                    <li><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</li>
                                </ul>
                                <p style='color: #64748b; font-size: 12px; margin-bottom: 0;'>Automated test dispatch from Enterprise Console.</p>
                              </div>";

            $mail->send();
            $successMsg = "SMTP connection verified! Diagnostic test email successfully delivered to {$targetEmail}.";
            if ($wasAutoCorrected) {
                $successMsg .= " (Note: Encryption was auto-aligned to " . strtoupper($effectiveEncryption) . " for Port {$port}).";
            }
            return [
                'success' => true,
                'message' => $successMsg,
                'debug' => implode("\n", array_slice($debugOutput, -15))
            ];
        } catch (Exception $e) {
            $rawError = $mail->ErrorInfo;
            $debugCombined = implode("\n", $debugOutput);
            $userFriendlyMessage = $rawError;

            // Intelligent error interpretation
            if (stripos($debugCombined, 'BadCredentials') !== false || stripos($debugCombined, '535') !== false || stripos($rawError, 'Could not authenticate') !== false) {
                $userFriendlyMessage = "Authentication Failed (Invalid Credentials): The SMTP server rejected the username or password. If using Gmail / Google Workspace, Google requires a 16-character 'App Password' (not your standard account password). Visit https://myaccount.google.com/apppasswords to create one.";
            } elseif (stripos($debugCombined, '534') !== false || stripos($debugCombined, 'Application-specific password required') !== false) {
                $userFriendlyMessage = "Application-Specific Password Required: Google detected a third-party login. Generate a 16-character App Password at https://myaccount.google.com/apppasswords and paste it as the SMTP Password.";
            } elseif (stripos($rawError, 'Could not connect to SMTP host') !== false || stripos($debugCombined, 'Failed to connect to server: (0)') !== false) {
                $userFriendlyMessage = "Could not connect to SMTP host {$host} on port {$port}. Verify the host name, check firewall rules, and ensure the port matches the encryption mode (Port 587 = TLS, Port 465 = SSL).";
            } elseif (stripos($debugCombined, 'Relay access denied') !== false || stripos($debugCombined, '550') !== false) {
                $userFriendlyMessage = "Relay Access Denied: The sender address '{$fromEmail}' is not permitted to send through '{$host}'. Check your domain and authorized sender settings.";
            }

            return [
                'success' => false,
                'message' => 'SMTP Diagnostic Error: ' . $userFriendlyMessage,
                'debug' => implode("\n", array_slice($debugOutput, -20))
            ];
        }
    }
}
