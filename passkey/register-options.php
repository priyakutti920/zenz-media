<?php
/**
 * Passkey Registration Options Generator
 * Generates PublicKeyCredentialCreationOptions for navigator.credentials.create()
 */
require_once __DIR__ . '/config.php';

use lbuchs\WebAuthn\Binary\ByteBuffer;

$currentUser = getAuthenticatedPasskeyUser();
if (!$currentUser) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Unauthorized: Please log in to add a passkey.'
    ], 401);
}

try {
    $webAuthn = getWebAuthnInstance();

    // Fetch already registered credentials for this user to prevent duplicate registrations
    $stmt = $pdo->prepare("SELECT credential_id FROM passkey_credentials WHERE user_type = ? AND user_id = ?");
    $stmt->execute([$currentUser['type'], $currentUser['id']]);
    $existingCreds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $excludeCredentialIds = [];
    foreach ($existingCreds as $b64Id) {
        if (!empty($b64Id)) {
            $excludeCredentialIds[] = ByteBuffer::fromBase64Url($b64Id);
        }
    }

    // Unique user ID string for WebAuthn user handle
    $userIdHandle = "{$currentUser['type']}_{$currentUser['id']}";
    $userName = !empty($currentUser['email']) ? $currentUser['email'] : "user_{$currentUser['id']}";
    $userDisplayName = !empty($currentUser['name']) ? $currentUser['name'] : $userName;

    // Timeout: 60s, Resident Key: 'preferred' (enables 1-click discoverable login), User Verification: 'preferred'
    $createArgs = $webAuthn->getCreateArgs(
        $userIdHandle,
        $userName,
        $userDisplayName,
        60,                    // timeout in seconds
        'preferred',           // requireResidentKey
        'preferred',           // requireUserVerification
        null,                  // crossPlatformAttachment (null allows both platform like FaceID/Hello & external keys)
        $excludeCredentialIds  // exclude already registered credentials
    );

    // Save challenge to user session for validation in register-verify.php
    $_SESSION['webauthn_reg_challenge'] = $webAuthn->getChallenge();
    $_SESSION['webauthn_reg_user'] = $currentUser;

    sendJsonResponse([
        'success' => true,
        'options' => $createArgs
    ]);

} catch (Exception $e) {
    error_log("Passkey Registration Options Error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'message' => 'Unable to generate passkey registration challenge: ' . $e->getMessage()
    ], 500);
}
