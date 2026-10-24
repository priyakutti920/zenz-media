<?php
/**
 * Passkey / WebAuthn Configuration and Service Helper
 * Supports FIDO2 / WebAuthn Level 3 biometrics & security keys.
 */

// Ensure clean output buffering & suppress direct HTML error leaking for API requests
if (ob_get_level() === 0) {
    ob_start();
}
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Polyfills for mbstring extension functions (if disabled on live web host)
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null) {
        if ($length === null) {
            return substr((string)$string, (int)$start);
        }
        return substr((string)$string, (int)$start, (int)$length);
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null) {
        return strlen((string)$string);
    }
}

if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = null) {
        return strpos((string)$haystack, (string)$needle, (int)$offset);
    }
}

if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth($string, $start, $width, $trimmarker = '', $encoding = null) {
        $str = (string)$string;
        if (strlen($str) <= $width) {
            return $str;
        }
        $markerLen = strlen($trimmarker);
        return substr($str, $start, max(0, $width - $markerLen)) . $trimmarker;
    }
}

if (!function_exists('mb_convert_encoding')) {
    function mb_convert_encoding($string, $to_encoding, $from_encoding = null) {
        return (string)$string;
    }
}

if (!function_exists('mb_check_encoding')) {
    function mb_check_encoding($value = null, $encoding = null) {
        return true;
    }
}

// Base64URL encoding/decoding helper utilities
if (!function_exists('base64url_encode')) {
    function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('base64url_decode')) {
    function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
}

// Global shutdown function to prevent fatal PHP errors from corrupting JSON responses
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) {
            ob_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Internal server error: ' . $error['message']
        ], JSON_UNESCAPED_SLASHES);
    }
});

// Include core dependencies
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../vendor/autoload.php';

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;

// Ensure secure session is initialized
Security::init($pdo);
Security::initSecureSession();

/**
 * Determine the Relying Party (RP) ID, Origin, and Name dynamically.
 */
function getWebAuthnConfig() {
    global $pdo;

    // 1. RP Name (default from site_settings or fallback)
    $rpName = 'Cloud Aspire Portal';
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->query("SELECT site_name FROM site_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1");
            $name = $stmt->fetchColumn();
            if (!empty($name)) {
                $rpName = $name;
            }
        }
    } catch (Exception $e) {}

    // Allow environment override
    $rpName = $_ENV['WEBAUTHN_RP_NAME'] ?? getenv('WEBAUTHN_RP_NAME') ?: $rpName;

    // 2. Host and RP ID
    $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Remove port if present for RP ID
    $hostOnly = preg_replace('/:\d+$/', '', $httpHost);
    $hostOnly = trim(strtolower($hostOnly));

    // Allow environment override
    $rpId = $_ENV['WEBAUTHN_RP_ID'] ?? getenv('WEBAUTHN_RP_ID') ?: $hostOnly;

    // 3. Origin Detection
    $isHttps = (
        (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) === 'on') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    );

    $scheme = ($isHttps || ($rpId !== 'localhost' && $rpId !== '127.0.0.1')) ? 'https' : 'http';
    $origin = "{$scheme}://{$httpHost}";

    // Allow environment override
    $origin = $_ENV['WEBAUTHN_ORIGIN'] ?? getenv('WEBAUTHN_ORIGIN') ?: $origin;

    return [
        'rp_name' => $rpName,
        'rp_id'   => $rpId,
        'origin'  => $origin
    ];
}

/**
 * Instantiate and configure WebAuthn server instance.
 */
function getWebAuthnInstance() {
    $cfg = getWebAuthnConfig();

    // Formats supported for attestation: android-key, android-safetynet, apple, fido-u2f, none, packed, tpm
    $allowedFormats = ['none', 'packed', 'apple', 'fido-u2f', 'android-key', 'tpm'];

    // useBase64UrlEncoding = true: ensures binary fields are serialized as Base64URL in JSON
    $webAuthn = new WebAuthn($cfg['rp_name'], $cfg['rp_id'], $allowedFormats, true);

    return $webAuthn;
}

/**
 * Returns current authenticated user context (Employee or Admin)
 */
function getAuthenticatedPasskeyUser() {
    if (isset($_SESSION['employee_logged_in']) && $_SESSION['employee_logged_in'] === true) {
        return [
            'type'  => 'employee',
            'id'    => (int)$_SESSION['employee_id'],
            'email' => $_SESSION['employee_email'] ?? '',
            'name'  => $_SESSION['employee_name'] ?? 'Employee'
        ];
    }

    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return [
            'type'  => 'admin',
            'id'    => (int)($_SESSION['admin_id'] ?? 1),
            'email' => $_SESSION['admin_email'] ?? ($_SESSION['admin_username'] ?? 'admin'),
            'name'  => $_SESSION['admin_username'] ?? 'Administrator'
        ];
    }

    return null;
}

/**
 * JSON Response helper
 */
function sendJsonResponse($data, $statusCode = 200) {
    if (ob_get_length()) {
        ob_clean();
    }
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Ensure passkey_credentials table exists in database
 */
function ensurePasskeyTableExists($pdo) {
    static $checked = false;
    if ($checked || !$pdo) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `passkey_credentials` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_type` ENUM('employee', 'admin') NOT NULL DEFAULT 'employee',
            `user_id` INT NOT NULL,
            `credential_id` VARCHAR(255) NOT NULL,
            `public_key` TEXT NOT NULL,
            `user_handle` VARCHAR(64) NULL,
            `sign_count` BIGINT UNSIGNED DEFAULT 0,
            `transports` VARCHAR(255) NULL,
            `device_name` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` TIMESTAMP NULL,
            UNIQUE KEY `unique_credential_id` (`credential_id`),
            INDEX `idx_user_type_id` (`user_type`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $checked = true;
    } catch (Exception $e) {
        error_log("Passkey table check error: " . $e->getMessage());
    }
}

// Auto-verify table
ensurePasskeyTableExists($pdo);
