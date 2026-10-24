<?php
/**
 * Passkey Login Options Generator
 * Generates PublicKeyCredentialRequestOptions for navigator.credentials.get()
 * Supports both Discoverable Credentials (Usernameless) and Username/Email-targeted login.
 */
require_once __DIR__ . '/config.php';

use lbuchs\WebAuthn\Binary\ByteBuffer;

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: [];

// Optional identifier (email or username) for targeted authentication
$identifier = trim($input['identifier'] ?? ($_GET['identifier'] ?? ''));

try {
    $webAuthn = getWebAuthnInstance();
    $allowedCredentialBuffers = [];
    $targetUser = null;

    if (!empty($identifier)) {
        // 1. Try finding employee by email
        $empStmt = $pdo->prepare("SELECT id, email, first_name, last_name, status FROM employees WHERE email = ? LIMIT 1");
        $empStmt->execute([$identifier]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

        if ($emp) {
            if ($emp['status'] !== 'active') {
                sendJsonResponse([
                    'success' => false,
                    'message' => 'Your employee account is not active. Please contact the administrator.'
                ], 403);
            }
            $targetUser = ['type' => 'employee', 'id' => (int)$emp['id'], 'name' => $emp['first_name'] . ' ' . $emp['last_name']];
        } else {
            // 2. Try finding admin by username or email
            $adminStmt = $pdo->prepare("SELECT id, username, email FROM admin WHERE username = ? OR email = ? LIMIT 1");
            $adminStmt->execute([$identifier, $identifier]);
            $adm = $adminStmt->fetch(PDO::FETCH_ASSOC);

            if ($adm) {
                $targetUser = ['type' => 'admin', 'id' => (int)$adm['id'], 'name' => $adm['username']];
            }
        }

        if ($targetUser) {
            // Fetch credentials registered for this specific user
            $credStmt = $pdo->prepare("SELECT credential_id FROM passkey_credentials WHERE user_type = ? AND user_id = ?");
            $credStmt->execute([$targetUser['type'], $targetUser['id']]);
            $creds = $credStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($creds)) {
                sendJsonResponse([
                    'success' => false,
                    'message' => 'No passkeys found for this account. Please sign in with your password and register a passkey in Account Settings.'
                ], 404);
            }

            foreach ($creds as $cId) {
                if (!empty($cId)) {
                    $allowedCredentialBuffers[] = ByteBuffer::fromBase64Url($cId);
                }
            }
        } else {
            sendJsonResponse([
                'success' => false,
                'message' => 'Account not found. Please verify your email or username.'
            ], 404);
        }
    }

    // Call getGetArgs:
    // When $allowedCredentialBuffers is empty, navigator.credentials.get() utilizes Discoverable Credentials (Usernameless 1-click login)
    // When populated, it requests a credential belonging to the specified user
    $getArgs = $webAuthn->getGetArgs(
        $allowedCredentialBuffers,
        60,     // timeout in seconds
        true,   // usb
        true,   // nfc
        true,   // ble
        true,   // hybrid (cross-device e.g. Phone QR to PC)
        true,   // internal (Windows Hello, Face ID, Touch ID)
        'preferred' // requireUserVerification
    );

    // Store challenge in session
    $_SESSION['webauthn_auth_challenge'] = $webAuthn->getChallenge();
    $_SESSION['webauthn_target_user'] = $targetUser;

    sendJsonResponse([
        'success' => true,
        'options' => $getArgs
    ]);

} catch (Exception $e) {
    error_log("Passkey Login Options Error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'message' => 'Unable to initiate passkey authentication: ' . $e->getMessage()
    ], 500);
}
