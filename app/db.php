<?php
// PHP 8+ polyfills for PHP 7.x compatibility on live hosting
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle === '' || (substr($haystack, -strlen($needle)) === (string)$needle);
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

// Load environment variables from .env file if present
$envPath = dirname(__DIR__) . '/.env';
if (file_exists($envPath) && is_readable($envPath)) {
    $envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// Staging / Production security: Disable client-facing debug output
$appEnv   = $_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: 'production');
$appDebug = filter_var($_ENV['APP_DEBUG'] ?? (getenv('APP_DEBUG') ?: false), FILTER_VALIDATE_BOOLEAN);

if ($appEnv === 'staging' || $appEnv === 'production' || !$appDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Enforce application timezone
if (date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

// Database configuration from environment or local XAMPP defaults
$host     = $_ENV['DB_HOST'] ?? (getenv('DB_HOST') ?: 'localhost');
$dbname   = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: 'qaschyan_po');
$username = $_ENV['DB_USER'] ?? (getenv('DB_USER') ?: 'root');
$password = $_ENV['DB_PASSWORD'] ?? (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '');
$charset  = 'utf8mb4';

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 30,
];

try {
    // Connect directly to the database
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=$charset",
        $username,
        $password,
        $pdoOptions
    );
    try {
        $pdo->exec("SET time_zone = '+05:30'");
    } catch (PDOException $tzEx) {}
} catch (PDOException $e) {
    // Fail closed in staging or production: NEVER fall back to alternative databases
    if ($appEnv === 'staging' || $appEnv === 'production' || isset($_ENV['DB_NAME'])) {
        error_log("Database connection failed for environment '{$appEnv}' and database '{$dbname}': " . $e->getMessage());
        die("Database connection failed for '{$dbname}'. Fallback connection is disabled in '{$appEnv}' environment.");
    }

    // Intelligent fallback for unconfigured local XAMPP environments only
    $fallbackConfigs = [
        ['host' => 'localhost', 'dbname' => 'qaschyan_po', 'username' => 'root', 'password' => ''],
    ];

    $connected = false;
    foreach ($fallbackConfigs as $cfg) {
        if ($cfg['dbname'] === $dbname && $cfg['username'] === $username && $cfg['password'] === $password) {
            continue;
        }
        try {
            $pdo = new PDO(
                "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=$charset",
                $cfg['username'],
                $cfg['password'],
                $pdoOptions
            );
            $host     = $cfg['host'];
            $dbname   = $cfg['dbname'];
            $username = $cfg['username'];
            $password = $cfg['password'];
            $connected = true;
            break;
        } catch (PDOException $ex) {
            continue;
        }
    }

    if (!$connected) {
        // If database doesn't exist (e.g. local setup), attempt to create it
        if ($e->getCode() == 1049 || stripos($e->getMessage(), 'Unknown database') !== false) {
            try {
                $pdoTemp = new PDO(
                    "mysql:host=$host;charset=$charset",
                    $username,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $pdoTemp->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdoTemp = null;

                $pdo = new PDO(
                    "mysql:host=$host;dbname=$dbname;charset=$charset",
                    $username,
                    $password,
                    $pdoOptions
                );
            } catch (PDOException $createEx) {
                error_log("Database connection & auto-creation failed: " . $createEx->getMessage());
                die('Database connection failed. Please try again later.');
            }
        } else {
            error_log("Database connection failed: " . $e->getMessage());
            die('Database connection failed. Please verify database settings.');
        }
    }
}
?>
