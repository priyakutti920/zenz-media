<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
Security::init($pdo);
Security::initSecureSession();
if (isset($_SESSION['admin_id'])) {
    Security::logAudit((int)$_SESSION['admin_id'], 'logout', 'admin', (int)$_SESSION['admin_id'], ['username' => $_SESSION['admin_username'] ?? '']);
}
Security::destroySession();

// Redirect to admin login page
header("Location: ../admin");
exit();