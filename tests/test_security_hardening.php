<?php
/**
 * Cloud Aspire - Automated Security Hardening & Remediation Verification Suite
 * Tests all 20 phases of security hardening.
 */

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';

date_default_timezone_set('Asia/Kolkata');
Security::init($pdo);

echo "======================================================================\n";
echo "  CLOUD ASPIRE — SECURITY AUDIT & HARDENING VERIFICATION SUITE\n";
echo "  Target Database: qaschyan_staging\n";
echo "  Execution Time: " . date('Y-m-d H:i:s T') . "\n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($name, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$name}\n";
        if ($details) echo "         Evidence: {$details}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$name}\n";
        if ($details) echo "         Failure: {$details}\n";
    }
}

// ----------------------------------------------------------------------
// GROUP 1: Sensitive File & Directory Protection (.htaccess)
// ----------------------------------------------------------------------
echo "--- GROUP 1: Sensitive File & Directory Protection ---\n";

function checkHttpStatus($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

$baseUrl = 'http://localhost';
$codeEnv = checkHttpStatus($baseUrl . '/.env');
assertTest("Direct access to .env is forbidden", in_array($codeEnv, [403, 404], true), "HTTP {$codeEnv}");

$codeGit = checkHttpStatus($baseUrl . '/.git/config');
assertTest("Direct access to .git is forbidden", in_array($codeGit, [403, 404], true), "HTTP {$codeGit}");

$codeApp = checkHttpStatus($baseUrl . '/app/db.php');
assertTest("Direct access to app/ directory is forbidden", in_array($codeApp, [403, 404], true), "HTTP {$codeApp}");

$codeDb = checkHttpStatus($baseUrl . '/database/');
assertTest("Direct access to database/ directory is forbidden", in_array($codeDb, [403, 404], true), "HTTP {$codeDb}");

$codeTests = checkHttpStatus($baseUrl . '/tests/');
assertTest("Direct access to tests/ directory is forbidden", in_array($codeTests, [403, 404], true), "HTTP {$codeTests}");

$codeComposer = checkHttpStatus($baseUrl . '/composer.json');
assertTest("Direct access to composer.json is forbidden", in_array($codeComposer, [403, 404], true), "HTTP {$codeComposer}");

$codeAadhar = checkHttpStatus($baseUrl . '/assets/uploads/aadhar/test.jpg');
assertTest("Direct access to assets/uploads/aadhar/ is strictly forbidden", in_array($codeAadhar, [403, 404], true), "HTTP {$codeAadhar}");

// ----------------------------------------------------------------------
// GROUP 2: Security Response Headers & Transport Security
// ----------------------------------------------------------------------
echo "\n--- GROUP 2: Security Headers & Transport Security ---\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/login');
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
$headersOutput = curl_exec($ch);
curl_close($ch);

$hasNosniff = stripos($headersOutput, 'X-Content-Type-Options: nosniff') !== false;
assertTest("X-Content-Type-Options: nosniff header present", $hasNosniff, "Found in HTTP response headers");

$hasFrameOptions = stripos($headersOutput, 'X-Frame-Options: SAMEORIGIN') !== false;
assertTest("X-Frame-Options: SAMEORIGIN header present", $hasFrameOptions, "Found in HTTP response headers");

$hasReferrer = stripos($headersOutput, 'Referrer-Policy: strict-origin-when-cross-origin') !== false;
assertTest("Referrer-Policy: strict-origin-when-cross-origin present", $hasReferrer, "Found in HTTP response headers");

$hasPermissions = stripos($headersOutput, 'Permissions-Policy') !== false;
assertTest("Permissions-Policy header present", $hasPermissions, "Found in HTTP response headers");

$hasCSP = stripos($headersOutput, 'Content-Security-Policy') !== false;
assertTest("Content-Security-Policy header present", $hasCSP, "Found in HTTP response headers");

// ----------------------------------------------------------------------
// GROUP 3: Reverse-Proxy IP Spoofing Defense
// ----------------------------------------------------------------------
echo "\n--- GROUP 3: Reverse-Proxy Spoofing Defense ---\n";

// Case A: Untrusted remote IP sending fake CF-Connecting-IP
$_SERVER['REMOTE_ADDR'] = '203.0.113.195'; // External untrusted client
$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.1.1.1'; // Spoofed IP header
$clientIpUntrusted = Security::getClientIp();
assertTest("Spoofed proxy headers ignored from untrusted remote client", $clientIpUntrusted === '203.0.113.195', "Client IP correctly resolved to REMOTE_ADDR: {$clientIpUntrusted}");

// Case B: Trusted loopback reverse proxy forwarding real client
$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // Trusted loopback proxy
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.42';
$clientIpTrusted = Security::getClientIp();
assertTest("Validated proxy headers accepted from trusted loopback proxy", $clientIpTrusted === '198.51.100.42', "Client IP resolved to forwarder: {$clientIpTrusted}");

// Clean up test server variables
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
unset($_SERVER['HTTP_CF_CONNECTING_IP']);

// ----------------------------------------------------------------------
// GROUP 4: CORS Configuration & Validation
// ----------------------------------------------------------------------
echo "\n--- GROUP 4: CORS Configuration & Lockdown ---\n";

// Test unapproved origin
$_SERVER['HTTP_ORIGIN'] = 'https://malicious-attacker.com';
ob_start();
$corsUnapproved = Security::handleCORS();
ob_end_clean();
assertTest("Unapproved external origins rejected by CORS handler", $corsUnapproved === false, "Origin rejected: https://malicious-attacker.com");

// Test approved origin
$_SERVER['HTTP_ORIGIN'] = 'https://cloudaspire.in';
ob_start();
$corsApproved = Security::handleCORS();
ob_end_clean();
assertTest("Approved production origin accepted by CORS handler", $corsApproved === true, "Origin accepted: https://cloudaspire.in");
unset($_SERVER['HTTP_ORIGIN']);

// ----------------------------------------------------------------------
// GROUP 5: Database-Backed Rate Limiting & Concurrency Safety
// ----------------------------------------------------------------------
echo "\n--- GROUP 5: Rate Limiting & Abuse Protection ---\n";

$testAction = 'test_security_audit';
$testIdentifier = 'audit_tester_' . bin2hex(random_bytes(4));

// Attempts 1 and 2 should be allowed (limit 2 per 60s)
$attempt1 = Security::checkRateLimit($testAction, $testIdentifier, 2, 60);
$attempt2 = Security::checkRateLimit($testAction, $testIdentifier, 2, 60);
$attempt3 = Security::checkRateLimit($testAction, $testIdentifier, 2, 60);

assertTest("Rate limiting allows requests below threshold", $attempt1['allowed'] && $attempt2['allowed'], "Attempt 1 and 2 permitted");
assertTest("Rate limiting blocks requests exceeding threshold with retry window", !$attempt3['allowed'] && $attempt3['retry_after'] > 0, "Attempt 3 blocked, retry_after: {$attempt3['retry_after']}s");

// ----------------------------------------------------------------------
// GROUP 6: Role-Based Access Control (RBAC) & Data Isolation
// ----------------------------------------------------------------------
echo "\n--- GROUP 6: Role-Based Access Control (RBAC) ---\n";

// Super Admin ID 1 possesses all permissions
$superAdminHasTasks = Security::hasPermission('tasks.manage', 1);
$superAdminHasRoles = Security::hasPermission('roles.manage', 1);
assertTest("Super Admin role automatically bypasses all permission gates", $superAdminHasTasks && $superAdminHasRoles, "tasks.manage: true, roles.manage: true");

// Test non-super admin role resolution
$nonAdminPerm = Security::hasPermission('roles.manage', 999999);
assertTest("Non-existent admin ID correctly denied permissions", $nonAdminPerm === false, "Access denied as expected");

// ----------------------------------------------------------------------
// GROUP 7: Audit Logging & Sensitive Data Redaction
// ----------------------------------------------------------------------
echo "\n--- GROUP 7: Audit Logging & Secret Redaction ---\n";

$secretTestPayload = [
    'username' => 'test_officer',
    'password' => 'SuperSecretP@ssw0rd123!',
    'csrf_token' => 'a1b2c3d4e5f6',
    'status' => 'active'
];

Security::logAudit(1, 'test_security_redaction', 'security_test', 999, $secretTestPayload);

// Query back the log entry
$logStmt = $pdo->prepare("SELECT details FROM admin_audit_logs WHERE action = 'test_security_redaction' AND target_id = 999 ORDER BY id DESC LIMIT 1");
$logStmt->execute();
$loggedDetailsJson = $logStmt->fetchColumn();
$loggedDetails = json_decode($loggedDetailsJson, true);

$passwordRedacted = !isset($loggedDetails['password']) || $loggedDetails['password'] === '[REDACTED]';
$csrfRedacted = !isset($loggedDetails['csrf_token']) || $loggedDetails['csrf_token'] === '[REDACTED]';
$usernamePreserved = isset($loggedDetails['username']) && $loggedDetails['username'] === 'test_officer';

assertTest("Audit logging records privileged actions", !empty($loggedDetailsJson), "Record persisted in admin_audit_logs");
assertTest("Sensitive fields (passwords, tokens) automatically redacted from audit logs", $passwordRedacted && $csrfRedacted && $usernamePreserved, "Logged details: {$loggedDetailsJson}");

// Clean up test audit log row
$pdo->exec("DELETE FROM admin_audit_logs WHERE action = 'test_security_redaction'");

// ----------------------------------------------------------------------
// GROUP 8: Server-Side CAPTCHA & Honeypot Validation
// ----------------------------------------------------------------------
echo "\n--- GROUP 8: CAPTCHA & Form Abuse Protection ---\n";

// Case A: Honeypot field filled -> Bot detected
$_POST['website_hp'] = 'http://spam-bot.net';
$captchaBotResult = Security::verifyCaptcha('');
assertTest("Honeypot detection blocks automated form bots", $captchaBotResult === false, "Bot rejected as expected");
unset($_POST['website_hp']);

// Case B: Clean submission without configured secret passes gracefully
$captchaCleanResult = Security::verifyCaptcha('');
assertTest("Clean submission handled safely without blocking legitimate users", $captchaCleanResult === true, "Graceful pass without error");

// ----------------------------------------------------------------------
// GROUP 9: Aadhaar Document Isolation & Ownership Gateway
// ----------------------------------------------------------------------
echo "\n--- GROUP 9: Aadhaar Storage Isolation & Gateway ---\n";

$aadharDir = dirname(__DIR__) . '/assets/uploads/aadhar';
$aadharHtaccess = $aadharDir . '/.htaccess';
$htaccessExists = file_exists($aadharHtaccess);
$htaccessContent = $htaccessExists ? file_get_contents($aadharHtaccess) : '';
$hasRequireDenied = strpos($htaccessContent, 'Require all denied') !== false || strpos($htaccessContent, 'Deny from all') !== false;

assertTest("Aadhaar storage folder protected with access-blocking .htaccess", $htaccessExists && $hasRequireDenied, "Direct downloads blocked at web server layer");

echo "\n======================================================================\n";
echo "  SECURITY VERIFICATION SUMMARY: {$passCount} PASSED / {$failCount} FAILED (Total: " . ($passCount + $failCount) . ")\n";
echo "======================================================================\n";

if ($failCount === 0) {
    echo "  ALL SECURITY HARDENING CHECKS VERIFIED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "  SOME SECURITY HARDENING CHECKS FAILED!\n";
    exit(1);
}
