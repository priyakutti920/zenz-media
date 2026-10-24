<?php
/**
 * Passkey Removal Endpoint
 * Requires password re-authentication before deleting a biometric credential.
 */
require_once __DIR__ . '/config.php';

$currentUser = getAuthenticatedPasskeyUser();
if (!$currentUser) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Unauthorized: Please log in to manage your passkeys.'
    ], 401);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid JSON.'], 400);
}

$passkeyId = (int)($input['id'] ?? 0);
$password  = (string)($input['password'] ?? '');

if ($passkeyId <= 0) {
    sendJsonResponse(['success' => false, 'message' => 'Valid passkey ID is required.'], 400);
}

if (empty($password)) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Password re-authentication is required to remove a registered passkey.'
    ], 400);
}

try {
    // 1. Re-authenticate user with their password
    $passwordHash = null;
    if ($currentUser['type'] === 'employee') {
        $st = $pdo->prepare("SELECT password FROM employees WHERE id = ? LIMIT 1");
        $st->execute([$currentUser['id']]);
        $passwordHash = $st->fetchColumn();
    } elseif ($currentUser['type'] === 'admin') {
        $st = $pdo->prepare("SELECT password FROM admin WHERE id = ? LIMIT 1");
        $st->execute([$currentUser['id']]);
        $passwordHash = $st->fetchColumn();
    }

    if (!$passwordHash || !password_verify($password, $passwordHash)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Incorrect password. Verification failed.'
        ], 403);
    }

    // 2. Verify passkey belongs to current user
    $checkStmt = $pdo->prepare("SELECT id, device_name FROM passkey_credentials WHERE id = ? AND user_type = ? AND user_id = ?");
    $checkStmt->execute([$passkeyId, $currentUser['type'], $currentUser['id']]);
    $keyToDelete = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$keyToDelete) {
        sendJsonResponse(['success' => false, 'message' => 'Passkey not found or already deleted.'], 404);
    }

    // 3. Delete passkey
    $delStmt = $pdo->prepare("DELETE FROM passkey_credentials WHERE id = ?");
    $delStmt->execute([$passkeyId]);

    // 4. Check remaining passkeys count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM passkey_credentials WHERE user_type = ? AND user_id = ?");
    $countStmt->execute([$currentUser['type'], $currentUser['id']]);
    $remaining = (int)$countStmt->fetchColumn();

    sendJsonResponse([
        'success'   => true,
        'message'   => 'Passkey "' . htmlspecialchars($keyToDelete['device_name']) . '" removed successfully.',
        'remaining' => $remaining
    ]);

} catch (Exception $e) {
    error_log("Passkey Removal Error: " . $e->getMessage());
    sendJsonResponse(['success' => false, 'message' => 'Database error while removing passkey.'], 500);
}
