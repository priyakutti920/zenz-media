<?php
/**
 * Passkey Rename Endpoint
 * Updates the user-friendly device name of an existing passkey.
 */
require_once __DIR__ . '/config.php';

$currentUser = getAuthenticatedPasskeyUser();
if (!$currentUser) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Unauthorized: Please log in to rename passkeys.'
    ], 401);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid JSON.'], 400);
}

$passkeyId  = (int)($input['id'] ?? 0);
$deviceName = trim($input['device_name'] ?? '');

if ($passkeyId <= 0 || empty($deviceName)) {
    sendJsonResponse(['success' => false, 'message' => 'Passkey ID and new device name are required.'], 400);
}

$deviceName = mb_substr(strip_tags($deviceName), 0, 100);

try {
    // Verify passkey belongs to the logged-in user
    $checkStmt = $pdo->prepare("SELECT id FROM passkey_credentials WHERE id = ? AND user_type = ? AND user_id = ?");
    $checkStmt->execute([$passkeyId, $currentUser['type'], $currentUser['id']]);
    if (!$checkStmt->fetch()) {
        sendJsonResponse(['success' => false, 'message' => 'Passkey not found or unauthorized.'], 404);
    }

    $updateStmt = $pdo->prepare("UPDATE passkey_credentials SET device_name = ? WHERE id = ?");
    $updateStmt->execute([$deviceName, $passkeyId]);

    sendJsonResponse([
        'success'     => true,
        'message'     => 'Passkey device name updated successfully.',
        'device_name' => $deviceName
    ]);

} catch (Exception $e) {
    error_log("Passkey Rename Error: " . $e->getMessage());
    sendJsonResponse(['success' => false, 'message' => 'Failed to update passkey name.'], 500);
}
