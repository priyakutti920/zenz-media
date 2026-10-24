<?php
/**
 * Passkey Registration Verification Endpoint
 * Validates WebAuthn attestation and stores public key credential in MySQL.
 */
require_once __DIR__ . '/config.php';

use lbuchs\WebAuthn\Binary\ByteBuffer;

$currentUser = getAuthenticatedPasskeyUser();
if (!$currentUser) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Unauthorized: Please log in to complete passkey setup.'
    ], 401);
}

// Ensure challenge is present in session
if (empty($_SESSION['webauthn_reg_challenge'])) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Registration session expired or invalid. Please try again.'
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

$clientDataJSON    = $input['clientDataJSON'] ?? '';
$attestationObject = $input['attestationObject'] ?? '';
$deviceName        = trim($input['deviceName'] ?? '');
$transports        = $input['transports'] ?? [];

if (empty($clientDataJSON) || empty($attestationObject)) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Missing cryptographic attestation data from authenticator.'
    ], 400);
}

try {
    $webAuthn = getWebAuthnInstance();
    $savedChallenge = $_SESSION['webauthn_reg_challenge'];

    // Decode binary payloads from Base64URL
    $clientDataBinary = ByteBuffer::fromBase64Url($clientDataJSON)->getBinaryString();
    $attestationBinary = ByteBuffer::fromBase64Url($attestationObject)->getBinaryString();

    // Verify attestation
    // processCreate($clientDataJSON, $attestationObject, $challenge, $requireUserVerification=false, $requireUserPresent=true, $failIfRootMismatch=false)
    $data = $webAuthn->processCreate(
        $clientDataBinary,
        $attestationBinary,
        $savedChallenge,
        false, // requireUserVerification
        true,  // requireUserPresent
        false  // failIfRootMismatch: false allows standard client self/platform attestations
    );

    // Convert credential ID binary to standard Base64URL string for storage & lookup
    $credentialIdBinary = $data->credentialId;
    $credentialIdB64Url = !empty($input['id']) ? trim($input['id']) : base64url_encode($credentialIdBinary);
    $publicKeyPem = $data->credentialPublicKey;
    $signCount = (int)($data->signatureCounter ?? 0);
    $userHandle = "{$currentUser['type']}_{$currentUser['id']}";

    // Sanitize device name or generate default
    if (empty($deviceName)) {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $detectedPlatform = 'Passkey';
        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            $detectedPlatform = 'Apple Device (Face ID / Touch ID)';
        } elseif (stripos($ua, 'Macintosh') !== false) {
            $detectedPlatform = 'Mac (Touch ID)';
        } elseif (stripos($ua, 'Android') !== false) {
            $detectedPlatform = 'Android Biometrics';
        } elseif (stripos($ua, 'Windows') !== false) {
            $detectedPlatform = 'Windows Hello';
        } elseif (stripos($ua, 'Linux') !== false) {
            $detectedPlatform = 'Linux FIDO Device';
        }
        $deviceName = $detectedPlatform . ' (' . date('M d, Y') . ')';
    } else {
        $deviceName = mb_substr(strip_tags($deviceName), 0, 100);
    }

    $transportsJson = is_array($transports) ? json_encode(array_values($transports)) : null;

    // Check if this credential ID is already registered
    $checkStmt = $pdo->prepare("SELECT id FROM passkey_credentials WHERE credential_id = ? LIMIT 1");
    $checkStmt->execute([$credentialIdB64Url]);
    if ($checkStmt->fetch()) {
        sendJsonResponse([
            'success' => false,
            'message' => 'This passkey has already been registered in the system.'
        ], 409);
    }

    // Insert new credential into database
    $insertStmt = $pdo->prepare("INSERT INTO passkey_credentials 
        (user_type, user_id, credential_id, public_key, user_handle, sign_count, transports, device_name, created_at, last_used_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

    $insertStmt->execute([
        $currentUser['type'],
        $currentUser['id'],
        $credentialIdB64Url,
        $publicKeyPem,
        $userHandle,
        $signCount,
        $transportsJson,
        $deviceName
    ]);

    // Clear challenge from session
    unset($_SESSION['webauthn_reg_challenge']);
    unset($_SESSION['webauthn_reg_user']);

    sendJsonResponse([
        'success'     => true,
        'message'     => 'Passkey registered successfully! You can now use your biometric sensor or security key to sign in.',
        'device_name' => $deviceName,
        'credential_id' => $credentialIdB64Url
    ]);

} catch (Throwable $e) {
    error_log("Passkey Registration Verify Error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'message' => 'Failed to verify and save passkey: ' . $e->getMessage()
    ], 400);
}
