<?php
require_once 'app/db.php';
require_once 'app/security.php';
Security::init($pdo);
Security::initSecureSession();

if (isset($_SESSION['employee_id'])) {
    Security::logAudit(null, 'employee_logout', 'employees', (int)$_SESSION['employee_id'], [
        'email' => $_SESSION['employee_email'] ?? '',
        'name' => $_SESSION['employee_name'] ?? ''
    ]);
}

// Clear session securely
Security::destroySession();

// Redirect to employee login
header("Location: index");
exit();
?>
