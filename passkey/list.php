<?php
/**
 * Passkey Listing Endpoint
 * Returns all active passkeys registered by the currently authenticated user.
 */
require_once __DIR__ . '/config.php';

$currentUser = getAuthenticatedPasskeyUser();
if (!$currentUser) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Unauthorized: Please log in to view your passkeys.'
    ], 401);
}

try {
    $stmt = $pdo->prepare("SELECT id, credential_id, device_name, created_at, last_used_at, sign_count 
                           FROM passkey_credentials 
                           WHERE user_type = ? AND user_id = ? 
                           ORDER BY created_at DESC");
    $stmt->execute([$currentUser['type'], $currentUser['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $passkeys = [];
    foreach ($rows as $row) {
        $devName = $row['device_name'] ?: 'Biometric Key';
        
        // Infer device icon for frontend display
        $icon = 'fa-key';
        if (stripos($devName, 'Apple') !== false || stripos($devName, 'iPhone') !== false || stripos($devName, 'iPad') !== false || stripos($devName, 'Mac') !== false) {
            $icon = 'fa-brands fa-apple';
        } elseif (stripos($devName, 'Android') !== false) {
            $icon = 'fa-brands fa-android';
        } elseif (stripos($devName, 'Windows') !== false) {
            $icon = 'fa-brands fa-windows';
        } elseif (stripos($devName, 'Linux') !== false) {
            $icon = 'fa-brands fa-linux';
        } elseif (stripos($devName, 'USB') !== false || stripos($devName, 'YubiKey') !== false) {
            $icon = 'fa-solid fa-shield-halved';
        }

        $passkeys[] = [
            'id'            => (int)$row['id'],
            'credential_id' => $row['credential_id'],
            'device_name'   => $devName,
            'icon'          => $icon,
            'created_at'    => date('M d, Y', strtotime($row['created_at'])),
            'last_used_at'  => !empty($row['last_used_at']) ? date('M d, Y H:i', strtotime($row['last_used_at'])) : 'Never',
            'sign_count'    => (int)$row['sign_count']
        ];
    }

    sendJsonResponse([
        'success'  => true,
        'passkeys' => $passkeys,
        'count'    => count($passkeys)
    ]);

} catch (Exception $e) {
    error_log("Passkey List Error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'message' => 'Unable to load passkeys: ' . $e->getMessage()
    ], 500);
}
