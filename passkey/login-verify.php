<?php
/**
 * Passkey Login Verification Endpoint
 * Validates WebAuthn cryptographic assertion and establishes authenticated PHP session.
 */
require_once __DIR__ . '/config.php';

use lbuchs\WebAuthn\Binary\ByteBuffer;

// Ensure challenge is present in session
if (empty($_SESSION['webauthn_auth_challenge'])) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Authentication challenge expired or not found. Please try again.'
    ], 400);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Invalid JSON payload received.'
    ], 400);
}

$rawId1              = trim($input['id'] ?? '');
$rawId2              = trim($input['rawId'] ?? '');
$credentialIdB64Url  = $rawId1 ?: $rawId2;
$clientDataJSON      = $input['clientDataJSON'] ?? '';
$authenticatorData   = $input['authenticatorData'] ?? '';
$signature           = $input['signature'] ?? '';
$userHandleB64Url    = trim($input['userHandle'] ?? '');

if (empty($credentialIdB64Url) || empty($clientDataJSON) || empty($authenticatorData) || empty($signature)) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Missing cryptographic assertion parameters from authenticator.'
    ], 400);
}

try {
    $webAuthn = getWebAuthnInstance();
    $savedChallenge = $_SESSION['webauthn_auth_challenge'];

    // 1. Look up the credential in MySQL with all Base64URL/Base64/padding variants
    $candidates = [];
    foreach ([$rawId1, $rawId2] as $val) {
        if (empty($val)) continue;
        $candidates[] = $val;
        $unpadded = rtrim($val, '=');
        $candidates[] = $unpadded;
        $candidates[] = $unpadded . '=';
        $candidates[] = $unpadded . '==';
        // Base64URL (-_) vs Standard Base64 (+/)
        $candidates[] = strtr($unpadded, '-_', '+/');
        $candidates[] = strtr($unpadded, '-_', '+/') . '=';
        $candidates[] = strtr($unpadded, '-_', '+/') . '==';
        $candidates[] = strtr($unpadded, '+/', '-_');
        $candidates[] = strtr($unpadded, '+/', '-_') . '=';
        $candidates[] = strtr($unpadded, '+/', '-_') . '==';
    }
    $candidates = array_values(array_unique(array_filter($candidates)));

    $credentialRecord = null;
    if (!empty($candidates)) {
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $pdo->prepare("SELECT id, user_type, user_id, public_key, sign_count, device_name, credential_id FROM passkey_credentials WHERE credential_id IN ($placeholders) LIMIT 1");
        $stmt->execute($candidates);
        $credentialRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Fallback: If not found by candidate IDs, attempt match by discoverable user_handle if present
    if (!$credentialRecord && !empty($userHandleB64Url)) {
        $decodedHandle = base64url_decode($userHandleB64Url) ?: $userHandleB64Url;
        $handleStmt = $pdo->prepare("SELECT id, user_type, user_id, public_key, sign_count, device_name, credential_id FROM passkey_credentials WHERE user_handle = ? OR user_handle = ? ORDER BY last_used_at DESC LIMIT 1");
        $handleStmt->execute([$userHandleB64Url, $decodedHandle]);
        $fallbackRecord = $handleStmt->fetch(PDO::FETCH_ASSOC);

        if ($fallbackRecord) {
            $credentialRecord = $fallbackRecord;
            // Update credential_id to the authenticator's active ID representation
            $pdo->prepare("UPDATE passkey_credentials SET credential_id = ? WHERE id = ?")->execute([$credentialIdB64Url, $fallbackRecord['id']]);
        }
    }

    if (!$credentialRecord) {
        $existing = $pdo->query("SELECT id, user_type, user_id, credential_id, device_name FROM passkey_credentials")->fetchAll(PDO::FETCH_ASSOC);
        error_log("Passkey login: credential not recognized. Sent id: '$rawId1', rawId: '$rawId2', userHandle: '$userHandleB64Url'. DB credentials: " . json_encode($existing));

        sendJsonResponse([
            'success' => false,
            'message' => 'Passkey is not recognized on this server. If you have an older passkey saved on your device from a previous test attempt, please delete it from your device/password manager, or sign in with your password and register a fresh passkey in Account Settings.'
        ], 404);
    }

    $publicKeyPem   = $credentialRecord['public_key'];
    $prevSignCount  = (int)$credentialRecord['sign_count'];
    $userType       = $credentialRecord['user_type'];
    $userId         = (int)$credentialRecord['user_id'];

    // 2. Decode assertion payloads from Base64URL
    $clientDataBinary      = ByteBuffer::fromBase64Url($clientDataJSON)->getBinaryString();
    $authenticatorDataBin  = ByteBuffer::fromBase64Url($authenticatorData)->getBinaryString();
    $signatureBinary       = ByteBuffer::fromBase64Url($signature)->getBinaryString();

    // 3. Cryptographic assertion verification
    // Note: Pass null for $prevSignatureCnt to support multi-device/sync passkeys (Google/Apple/Windows)
    // which intentionally report signCount = 0 per W3C WebAuthn Level 3 specifications.
    $verified = $webAuthn->processGet(
        $clientDataBinary,
        $authenticatorDataBin,
        $signatureBinary,
        $publicKeyPem,
        $savedChallenge,
        null,  // Do not fail sync passkeys on monotonic counter validation
        false, // requireUserVerification: false (authenticator verified presence)
        true   // requireUserPresent: true
    );

    if (!$verified) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Cryptographic signature verification failed. Please try again.'
        ], 401);
    }

    // 4. Update signature counter and last_used_at in database
    $newCounter = $webAuthn->getSignatureCounter();
    $updatedCount = ($newCounter !== null && $newCounter > $prevSignCount) ? $newCounter : $prevSignCount;

    $updateStmt = $pdo->prepare("UPDATE passkey_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?");
    $updateStmt->execute([$updatedCount, $credentialRecord['id']]);

    // 5. Establish authenticated PHP Session
    $redirectUrl = 'employee_dashboard';

    if ($userType === 'employee') {
        $empStmt = $pdo->prepare("SELECT id, employee_id, first_name, last_name, email, status FROM employees WHERE id = ? LIMIT 1");
        $empStmt->execute([$userId]);
        $employee = $empStmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) {
            sendJsonResponse(['success' => false, 'message' => 'Associated employee record not found.'], 404);
        }

        if ($employee['status'] !== 'active') {
            sendJsonResponse(['success' => false, 'message' => 'Your account is inactive. Please contact the administrator.'], 403);
        }

        // Clear failed logins & regenerate session ID to prevent fixation
        Security::clearFailedLogins();
        Security::regenerateSession();

        $_SESSION['employee_logged_in'] = true;
        $_SESSION['employee_id']        = $employee['id'];
        $_SESSION['employee_code']      = !empty($employee['employee_id']) 
            ? trim($employee['employee_id']) 
            : ('EMP-' . str_pad($employee['id'], 3, '0', STR_PAD_LEFT));
        $_SESSION['employee_name']      = trim($employee['first_name'] . ' ' . $employee['last_name']);
        $_SESSION['employee_email']     = $employee['email'];
        $_SESSION['login_time']         = time();
        $_SESSION['last_activity']      = time();

        // Send login alert safely
        try {
            require_once __DIR__ . '/../app/Mailer.php';
            $mailer = new Mailer($pdo);
            $mailer->sendEmployeeLoginAlert(
                $employee['email'],
                $_SESSION['employee_name'],
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
        } catch (Throwable $mailEx) {
            error_log("Passkey employee login email alert failed: " . $mailEx->getMessage());
        }

        $redirectUrl = 'employee_dashboard';

    } elseif ($userType === 'admin') {
        $admStmt = $pdo->prepare("SELECT id, username, email FROM admin WHERE id = ? LIMIT 1");
        $admStmt->execute([$userId]);
        $admin = $admStmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            sendJsonResponse(['success' => false, 'message' => 'Associated administrator record not found.'], 404);
        }

        // Clear failed logins & regenerate session ID
        Security::clearFailedLogins();
        Security::regenerateSession();

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id']        = $admin['id'];
        $_SESSION['admin_username']  = $admin['username'];
        $_SESSION['admin_email']     = $admin['email'] ?? '';
        $_SESSION['login_time']      = time();
        $_SESSION['last_activity']   = time();

        // Send admin login alert safely
        try {
            require_once __DIR__ . '/../app/Mailer.php';
            $mailer = new Mailer($pdo);
            $mailer->sendAdminLoginAlert(
                $admin['email'] ?? '',
                $_SESSION['admin_username'],
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
        } catch (Throwable $mailEx) {
            error_log("Passkey admin login email alert failed: " . $mailEx->getMessage());
        }

        $redirectUrl = '/admin/dashboard';
    }

    // Clear challenge from session
    unset($_SESSION['webauthn_auth_challenge']);
    unset($_SESSION['webauthn_target_user']);

    sendJsonResponse([
        'success'      => true,
        'message'      => 'Biometric authentication verified! Logging you in...',
        'redirect'     => $redirectUrl,
        'user_type'    => $userType,
        'device_name'  => $credentialRecord['device_name']
    ]);

} catch (Throwable $e) {
    error_log("Passkey Login Verify Error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'message' => 'Passkey verification failed: ' . $e->getMessage()
    ], 401);
}
