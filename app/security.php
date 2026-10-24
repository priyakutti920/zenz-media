<?php
// Standard polyfills for mbstring extension functions (if disabled on live web host)
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

class Security {
 private static $pdo = null;

 /**
 * Initialize the Security class with PDO connection
 */
 public static function init($pdo) {
 self::$pdo = $pdo;
 }

 /**
 * Set secure session cookie parameters
 */
 	public static function initSecureSession() {
		if (session_status() === PHP_SESSION_NONE) {
			// Set secure, HttpOnly cookies
			ini_set('session.cookie_httponly', 1);
			ini_set('session.use_only_cookies', 1);
			ini_set('session.cookie_samesite', 'Lax');

			// Use secure cookies in production (HTTPS)
			if ((isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) || 
			    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
				ini_set('session.cookie_secure', 1);
			} else {
				ini_set('session.cookie_secure', 0);
			}

			session_start();

			// Regenerate session ID periodically to prevent fixation
			if (!isset($_SESSION['last_regeneration'])) {
				$_SESSION['last_regeneration'] = time();
			}

			// Regenerate periodically without destroying existing active session file
			if (time() - $_SESSION['last_regeneration'] > 1800) {
				session_regenerate_id(false);
				$_SESSION['last_regeneration'] = time();
			}
		}

		// Dispatch security headers automatically on all requests
		self::sendSecurityHeaders();
	}

	/**
	 * Regenerate session ID after login (session fixation prevention)
	 */
	public static function regenerateSession() {
		session_regenerate_id(true);
		$_SESSION['last_regeneration'] = time();
	}

	/**
	 * Set session timeout (idle timeout in seconds)
	 */
	public static function setSessionTimeout($timeout = 3600) { // 1 hour default
		if (!isset($_SESSION['last_activity'])) {
			$_SESSION['last_activity'] = time();
		}

		$elapsed = time() - $_SESSION['last_activity'];

		if ($elapsed > $timeout) {
			self::destroySession();
			return false;
		}

		$_SESSION['last_activity'] = time();
		return true;
	}

	/**
	 * Destroy session securely
	 */
	public static function destroySession() {
		$_SESSION = [];

		if (ini_get('session.use_cookies')) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000,
				$params['path'], $params['domain'],
				$params['secure'], $params['httponly']
			);
		}

		session_destroy();
	}

	/**
	 * Generate CSRF token (Reuses existing valid token to prevent breaking multi-tab or back-button)
	 */
	public static function generateCSRFToken($formName = 'default') {
		if (session_status() === PHP_SESSION_NONE) {
			self::initSecureSession();
		}

		if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
			$_SESSION['csrf_tokens'] = [];
		}
		if (!isset($_SESSION['csrf_token_history']) || !is_array($_SESSION['csrf_token_history'])) {
			$_SESSION['csrf_token_history'] = [];
		}

		$now = time();

		// If a valid unexpired token exists for this form (valid for 2 hours), reuse it
		if (
			isset($_SESSION['csrf_tokens'][$formName]) &&
			is_array($_SESSION['csrf_tokens'][$formName]) &&
			!empty($_SESSION['csrf_tokens'][$formName]['token']) &&
			($now - ($_SESSION['csrf_tokens'][$formName]['time'] ?? 0) < 7200)
		) {
			$token = $_SESSION['csrf_tokens'][$formName]['token'];
			$_SESSION['csrf_token_history'][$token] = $now;
			$_SESSION['csrf_token'] = $token;
			return $token;
		}

		$token = bin2hex(random_bytes(32));

		$_SESSION['csrf_tokens'][$formName] = [
			'token' => $token,
			'time' => $now
		];
		$_SESSION['csrf_token_history'][$token] = $now;
		$_SESSION['csrf_token'] = $token;

		// Housekeeping: clean expired history tokens older than 2 hours
		foreach ($_SESSION['csrf_token_history'] as $t => $timestamp) {
			if ($now - $timestamp > 7200) {
				unset($_SESSION['csrf_token_history'][$t]);
			}
		}
		if (count($_SESSION['csrf_token_history']) > 30) {
			$_SESSION['csrf_token_history'] = array_slice($_SESSION['csrf_token_history'], -30, null, true);
		}

		return $token;
	}

	/**
	 * Validate CSRF token (Supports direct form match, session token history, and global token)
	 */
	public static function validateCSRFToken($token, $formName = 'default') {
		if (empty($token) || !is_string($token)) {
			return false;
		}

		if (session_status() === PHP_SESSION_NONE) {
			self::initSecureSession();
		}

		$now = time();

		// 1. Direct form match in csrf_tokens
		if (
			isset($_SESSION['csrf_tokens'][$formName]) &&
			is_array($_SESSION['csrf_tokens'][$formName]) &&
			!empty($_SESSION['csrf_tokens'][$formName]['token'])
		) {
			$stored = $_SESSION['csrf_tokens'][$formName];
			if (($now - ($stored['time'] ?? 0) <= 7200) && hash_equals($stored['token'], $token)) {
				return true;
			}
		}

		// 2. Token history match (ensures multi-tab, back-button, or rapid forms remain valid within 2 hours)
		if (isset($_SESSION['csrf_token_history']) && is_array($_SESSION['csrf_token_history'])) {
			if (isset($_SESSION['csrf_token_history'][$token])) {
				$issuedAt = $_SESSION['csrf_token_history'][$token];
				if ($now - $issuedAt <= 7200) {
					return true;
				} else {
					unset($_SESSION['csrf_token_history'][$token]);
				}
			}
		}

		// 3. Fallback check across ANY form in csrf_tokens
		if (isset($_SESSION['csrf_tokens']) && is_array($_SESSION['csrf_tokens'])) {
			foreach ($_SESSION['csrf_tokens'] as $name => $data) {
				if (is_array($data) && !empty($data['token'])) {
					if (($now - ($data['time'] ?? 0) <= 7200) && hash_equals($data['token'], $token)) {
						return true;
					}
				} elseif (is_string($data) && hash_equals($data, $token)) {
					return true;
				}
			}
		}

		// 4. Global token fallback
		if (isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])) {
			if (hash_equals($_SESSION['csrf_token'], $token)) {
				return true;
			}
		}

		return false;
	}

 /**
 * Check rate limiting for login attempts
 */
 public static function checkLoginRateLimit($identifier = null) {
 if (self::$pdo === null) {
 return ['allowed' => true, 'remaining' => 5, 'wait_seconds' => 0];
  }

 $ip = $identifier ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
 $now = time();
 $window = 900; // 15 minutes
 $maxAttempts = 5;

 try {
 // Clean old entries
 $cleanup = self::$pdo->prepare("DELETE FROM login_attempts WHERE timestamp < ?");
 $cleanup->execute([$now - $window]);

 // Count recent attempts
 $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND timestamp > ?");
 $stmt->execute([$ip, $now - $window]);
 $attempts = (int)$stmt->fetchColumn();

 if ($attempts >= $maxAttempts) {
 // Get oldest attempt timestamp
 $stmt = self::$pdo->prepare("SELECT MIN(timestamp) FROM login_attempts WHERE ip = ?");
 $stmt->execute([$ip]);
 $oldest = $stmt->fetchColumn();
 $waitSeconds = max(0, ($oldest + $window) - $now);

 return [
 'allowed' => false,
 'remaining' => 0,
 'wait_seconds' => $waitSeconds,
 'message' => "Too many login attempts. Please try again in " . ceil($waitSeconds / 60) . " minute(s)."
 ];
 }

 return [
 'allowed' => true,
 'remaining' => $maxAttempts - $attempts,
 'wait_seconds' => 0
 ];

 } catch (PDOException $e) {
 error_log("Rate limit check failed: " . $e->getMessage());
 return ['allowed' => true, 'remaining' => 5, 'wait_seconds' => 0];
 }
 }

 /**
 * Record a failed login attempt
 */
 public static function recordFailedLogin($identifier = null) {
 if (self::$pdo === null) return;

 $ip = $identifier ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

 try {
 $stmt = self::$pdo->prepare("INSERT INTO login_attempts (ip, timestamp) VALUES (?, ?)");
 $stmt->execute([$ip, time()]);
 } catch (PDOException $e) {
 error_log("Failed to record login attempt: " . $e->getMessage());
 }
 }

 /**
 * Clear failed login attempts on successful login
 */
 public static function clearFailedLogins($identifier = null) {
 if (self::$pdo === null) return;

 $ip = $identifier ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

 try {
 $stmt = self::$pdo->prepare("DELETE FROM login_attempts WHERE ip = ?");
 $stmt->execute([$ip]);
 } catch (PDOException $e) {
 error_log("Failed to clear login attempts: " . $e->getMessage());
 }
 }

 /**
 * Create rate limiting table if not exists
 */
 public static function createRateLimitTable() {
 if (self::$pdo === null) return;

 try {
 self::$pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
 id INT AUTO_INCREMENT PRIMARY KEY,
 ip VARCHAR(45) NOT NULL,
 timestamp INT NOT NULL,
 INDEX idx_ip (ip),
 INDEX idx_timestamp (timestamp)
  )");
 } catch (PDOException $e) {
 error_log("Failed to create rate limit table: " . $e->getMessage());
 }
 }

 /**
 * Validate email format
 */
 public static function validateEmail($email) {
 return filter_var(trim($email), FILTER_VALIDATE_EMAIL);
 }

 /**
 * Validate password strength
 */
 public static function validatePasswordStrength($password) {
 $errors = [];

 if (strlen($password) < 8) {
 $errors[] = "Password must be at least 8 characters long";
 }

 if (!preg_match('/[A-Z]/', $password)) {
 $errors[] = "Password must contain at least one uppercase letter";
 }

 if (!preg_match('/[a-z]/', $password)) {
 $errors[] = "Password must contain at least one lowercase letter";
 }

 if (!preg_match('/[0-9]/', $password)) {
 $errors[] = "Password must contain at least one number";
 }

 return $errors;
 }

 /**
 * Sanitize output for XSS prevention
 */
 public static function sanitize($string) {
 return htmlspecialchars(trim($string), ENT_QUOTES, 'UTF-8');
 }

 /**
 * Validate required fields
 */
 public static function validateRequired($data, $requiredFields) {
 $errors = [];

 foreach ($requiredFields as $field) {
 if (!isset($data[$field]) || trim($data[$field]) === '') {
 $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
 }
 }

 return $errors;
 }

 /**
 * Check if user is logged in and session is valid
 */
 public static function isAuthenticated() {
 if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
 return false;
 }

 // Check session timeout
 return self::setSessionTimeout(); // 30 minutes
 }

 /**
 * Require authentication or redirect
 */
 public static function requireAuth($redirectUrl = '../admin.php') {
 if (!self::isAuthenticated()) {
 self::destroySession();
 header("Location: $redirectUrl");
 exit();
 }
 }

	/**
	 * Parse User Agent for Platform, Browser, and Device Type
	 */
	public static function parseUserAgent($ua = null) {
		$ua = $ua ?: ($_SERVER['HTTP_USER_AGENT'] ?? '');
		$platform = 'Unknown OS';
		$browser = 'Web Browser';
		$deviceType = 'Desktop';

		if (preg_match('/windows|win32|win64/i', $ua)) {
			$platform = 'Windows';
		} elseif (preg_match('/macintosh|mac os x/i', $ua)) {
			$platform = 'macOS';
		} elseif (preg_match('/android/i', $ua)) {
			$platform = 'Android';
			$deviceType = 'Mobile';
		} elseif (preg_match('/iphone|ipod/i', $ua)) {
			$platform = 'iOS (iPhone)';
			$deviceType = 'Mobile';
		} elseif (preg_match('/ipad/i', $ua)) {
			$platform = 'iOS (iPad)';
			$deviceType = 'Tablet';
		} elseif (preg_match('/linux/i', $ua)) {
			$platform = 'Linux';
		}

		if (preg_match('/edg\/([0-9.]+)/i', $ua, $m)) {
			$browser = 'Microsoft Edge ' . explode('.', $m[1])[0];
		} elseif (preg_match('/chrome\/([0-9.]+)/i', $ua, $m)) {
			$browser = 'Google Chrome ' . explode('.', $m[1])[0];
		} elseif (preg_match('/firefox\/([0-9.]+)/i', $ua, $m)) {
			$browser = 'Mozilla Firefox ' . explode('.', $m[1])[0];
		} elseif (preg_match('/safari\/([0-9.]+)/i', $ua, $m) && !preg_match('/chrome/i', $ua)) {
			$browser = 'Apple Safari ' . explode('.', $m[1])[0];
		} elseif (preg_match('/opera|opr\/([0-9.]+)/i', $ua, $m)) {
			$browser = 'Opera';
		}

		return [
			'platform' => $platform,
			'browser' => $browser,
			'device_type' => $deviceType
		];
	}

	/**
	 * Ensure the active_sessions table exists in the database
	 */
	public static function ensureSessionTable() {
		if (!self::$pdo) return;
		try {
			self::$pdo->exec("CREATE TABLE IF NOT EXISTS active_sessions (
				id INT AUTO_INCREMENT PRIMARY KEY,
				session_id VARCHAR(128) NOT NULL UNIQUE,
				user_id INT NOT NULL,
				role VARCHAR(20) NOT NULL DEFAULT 'admin',
				ip_address VARCHAR(45) NOT NULL,
				user_agent TEXT NULL,
				device_type VARCHAR(50) NULL,
				browser VARCHAR(50) NULL,
				platform VARCHAR(50) NULL,
				last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_user_role (user_id, role),
				INDEX idx_last_activity (last_activity)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
		} catch (PDOException $e) {
			error_log("Session table creation error: " . $e->getMessage());
		}
	}

	/**
	 * Track the current active session in the database
	 */
	public static function trackCurrentSession($userId, $role = 'admin') {
		if (!self::$pdo || session_status() !== PHP_SESSION_ACTIVE) return;
		$sessionId = session_id();
		if (empty($sessionId) || empty($userId)) return;

		self::ensureSessionTable();

		$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$parsed = self::parseUserAgent($ua);

		try {
			$stmt = self::$pdo->prepare("INSERT INTO active_sessions 
				(session_id, user_id, role, ip_address, user_agent, device_type, browser, platform, last_activity)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
				ON DUPLICATE KEY UPDATE 
				ip_address = VALUES(ip_address),
				user_agent = VALUES(user_agent),
				device_type = VALUES(device_type),
				browser = VALUES(browser),
				platform = VALUES(platform),
				last_activity = NOW()");
			$stmt->execute([
				$sessionId,
				$userId,
				$role,
				$ip,
				$ua,
				$parsed['device_type'],
				$parsed['browser'],
				$parsed['platform']
			]);
		} catch (PDOException $e) {
			error_log("Session tracking error: " . $e->getMessage());
		}
	}

	/**
	 * Retrieve all active sessions for a user
	 */
	public static function getActiveSessions($userId, $role = 'admin') {
		if (!self::$pdo) return [];
		self::ensureSessionTable();

		try {
			// Clean up sessions inactive for more than 30 days
			self::$pdo->exec("DELETE FROM active_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)");

			$stmt = self::$pdo->prepare("SELECT * FROM active_sessions WHERE user_id = ? AND role = ? ORDER BY last_activity DESC");
			$stmt->execute([$userId, $role]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			error_log("Fetch active sessions error: " . $e->getMessage());
			return [];
		}
	}

	/**
	 * Terminate a single active session by its session_id
	 */
	public static function terminateSession($sessionId, $userId, $role = 'admin') {
		if (!self::$pdo) return false;
		self::ensureSessionTable();

		try {
			$stmt = self::$pdo->prepare("DELETE FROM active_sessions WHERE session_id = ? AND user_id = ? AND role = ?");
			return $stmt->execute([$sessionId, $userId, $role]);
		} catch (PDOException $e) {
			error_log("Terminate session error: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Terminate all sessions except the current one
	 */
	public static function terminateOtherSessions($currentSessionId, $userId, $role = 'admin') {
		if (!self::$pdo) return false;
		self::ensureSessionTable();

		try {
			$stmt = self::$pdo->prepare("DELETE FROM active_sessions WHERE session_id != ? AND user_id = ? AND role = ?");
			return $stmt->execute([$currentSessionId, $userId, $role]);
		} catch (PDOException $e) {
			error_log("Terminate other sessions error: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Calculate great-circle distance between two GPS coordinates using the Haversine formula (returns meters)
	 */
	public static function calculateHaversineDistance($lat1, $lon1, $lat2, $lon2) {
		$earthRadius = 6371000; // in meters

		$lat1Rad = deg2rad((float)$lat1);
		$lon1Rad = deg2rad((float)$lon1);
		$lat2Rad = deg2rad((float)$lat2);
		$lon2Rad = deg2rad((float)$lon2);

		$deltaLat = $lat2Rad - $lat1Rad;
		$deltaLon = $lon2Rad - $lon1Rad;

		$a = sin($deltaLat / 2) * sin($deltaLat / 2) +
		     cos($lat1Rad) * cos($lat2Rad) *
		     sin($deltaLon / 2) * sin($deltaLon / 2);

		$c = 2 * atan2(sqrt($a), sqrt(1 - $a));

		return round($earthRadius * $c);
	}

	/**
	 * Validate latitude and longitude numerical range
	 */
	public static function validateCoordinates($lat, $lon) {
		if (!is_numeric($lat) || !is_numeric($lon)) {
			return false;
		}
		$lat = (float)$lat;
		$lon = (float)$lon;
		if ($lat < -90 || $lat > 90) {
			return false;
		}
		if ($lon < -180 || $lon > 180) {
			return false;
		}
		return true;
	}

	/**
	 * Check if an administrator possesses a specific permission slug
	 */
	public static function hasPermission($permissionSlug, $adminId = null) {
		if (!self::$pdo) return false;
		if ($adminId === null) {
			$adminId = $_SESSION['admin_id'] ?? null;
		}
		if (!$adminId) return false;

		try {
			// Super admin has unrestricted root access
			$st = self::$pdo->prepare("SELECT COUNT(*) FROM admin_roles ar 
				JOIN roles r ON ar.role_id = r.id 
				WHERE ar.admin_id = ? AND r.slug = 'super_admin'");
			$st->execute([$adminId]);
			if ((int)$st->fetchColumn() > 0) {
				return true;
			}

			// Check assigned granular permission
			$stmt = self::$pdo->prepare("SELECT COUNT(*) FROM admin_roles ar
				JOIN role_permissions rp ON ar.role_id = rp.role_id
				JOIN permissions p ON rp.permission_id = p.id
				WHERE ar.admin_id = ? AND p.slug = ?");
			$stmt->execute([$adminId, $permissionSlug]);
			return (int)$stmt->fetchColumn() > 0;
		} catch (PDOException $e) {
			error_log("Permission check error: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Get all permission slugs assigned to an administrator
	 */
	public static function getAdminPermissions($adminId = null) {
		if (!self::$pdo) return [];
		if ($adminId === null) {
			$adminId = $_SESSION['admin_id'] ?? null;
		}
		if (!$adminId) return [];

		try {
			// If super admin, return all system permissions
			$st = self::$pdo->prepare("SELECT COUNT(*) FROM admin_roles ar 
				JOIN roles r ON ar.role_id = r.id 
				WHERE ar.admin_id = ? AND r.slug = 'super_admin'");
			$st->execute([$adminId]);
			if ((int)$st->fetchColumn() > 0) {
				return self::$pdo->query("SELECT slug FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
			}

			$stmt = self::$pdo->prepare("SELECT DISTINCT p.slug FROM admin_roles ar
				JOIN role_permissions rp ON ar.role_id = rp.role_id
				JOIN permissions p ON rp.permission_id = p.id
				WHERE ar.admin_id = ?");
			$stmt->execute([$adminId]);
			return $stmt->fetchAll(PDO::FETCH_COLUMN);
		} catch (PDOException $e) {
			error_log("Get admin permissions error: " . $e->getMessage());
			return [];
		}
	}

	/**
	 * Get roles assigned to an administrator
	 */
	public static function getAdminRoles($adminId = null) {
		if (!self::$pdo) return [];
		if ($adminId === null) {
			$adminId = $_SESSION['admin_id'] ?? null;
		}
		if (!$adminId) return [];

		try {
			$stmt = self::$pdo->prepare("SELECT r.id, r.name, r.slug, r.is_system FROM admin_roles ar
				JOIN roles r ON ar.role_id = r.id
				WHERE ar.admin_id = ?");
			$stmt->execute([$adminId]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			error_log("Get admin roles error: " . $e->getMessage());
			return [];
		}
	}

	/**
	 * Safely determine the real client IP address, guarding against header spoofing
	 */
	public static function getClientIp() {
		$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

		// Only trust reverse proxy headers if REMOTE_ADDR is loopback or trusted private range
		$isLoopback = in_array($remoteAddr, ['127.0.0.1', '::1'], true);
		$isPrivateProxy = filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
		
		if ($isLoopback || $isPrivateProxy) {
			if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
				$cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
				if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
					return $cfIp;
				}
			}
			if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
				$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
				$clientIp = trim($ips[0]);
				if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
					return $clientIp;
				}
			}
		}

		return $remoteAddr;
	}

	/**
	 * Send standard HTTP security headers
	 */
	public static function sendSecurityHeaders() {
		if (headers_sent()) {
			return;
		}

		// Remove PHP fingerprint
		@header_remove('X-Powered-By');

		// Prevent MIME-sniffing
		header('X-Content-Type-Options: nosniff');

		// Prevent Clickjacking
		header('X-Frame-Options: SAMEORIGIN');

		// Strict Referrer Policy
		header('Referrer-Policy: strict-origin-when-cross-origin');

		// Restrict hardware access while explicitly permitting Geolocation for attendance on self
		header("Permissions-Policy: geolocation=(self), camera=(), microphone=()");

		// Content Security Policy compatible with Google Fonts, FontAwesome, Leaflet/OpenStreetMap
		$csp = "default-src 'self'; " .
		       "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://unpkg.com; " .
		       "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com; " .
		       "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; " .
		       "img-src 'self' data: blob: https://*.tile.openstreetmap.org https://cloudaspire.in; " .
		       "connect-src 'self'; " .
		       "frame-ancestors 'self'; " .
		       "base-uri 'self'; " .
		       "form-action 'self'";
		header("Content-Security-Policy: $csp");

		// HSTS: Only when served over active HTTPS
		$isHttps = (
			(isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) ||
			(isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
			(isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
		);
		if ($isHttps) {
			header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
		}
	}

	/**
	 * Handle CORS validation with a strict origin allowlist
	 */
	public static function handleCORS($customAllowedOrigins = []) {
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if (empty($origin)) {
			return true; // Same-origin request
		}

		$allowedOrigins = array_merge([
			'https://cloudaspire.in',
			'http://localhost',
			'http://127.0.0.1',
			'https://localhost',
			'https://127.0.0.1'
		], $customAllowedOrigins);

		$parsedOrigin = parse_url($origin);
		$originHost = $parsedOrigin['host'] ?? '';

		$isAllowed = false;
		if (in_array($origin, $allowedOrigins, true)) {
			$isAllowed = true;
		} elseif (str_ends_with($originHost, '.trycloudflare.com')) {
			$isAllowed = true;
		}

		if (!headers_sent()) {
			if ($isAllowed) {
				header("Access-Control-Allow-Origin: $origin");
				header("Access-Control-Allow-Credentials: true");
				header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
				header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");
				header("Vary: Origin");
			} else {
				header("Access-Control-Allow-Origin: null");
			}
		}

		if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
			if (!headers_sent()) {
				http_response_code(204);
			}
			exit();
		}

		return $isAllowed;
	}

	/**
	 * General rate-limiting helper for sensitive actions
	 */
	public static function checkRateLimit($actionKey, $identifier = null, $maxAttempts = 30, $windowSeconds = 60) {
		if (self::$pdo === null) {
			return ['allowed' => true, 'remaining' => $maxAttempts, 'wait_seconds' => 0];
		}

		self::createRateLimitTable();
		$ip = $identifier ?? self::getClientIp();
		$key = substr($actionKey . ':' . $ip, 0, 45);
		$now = time();

		try {
			// Clean old entries
			$cleanup = self::$pdo->prepare("DELETE FROM login_attempts WHERE timestamp < ?");
			$cleanup->execute([$now - $windowSeconds]);

			// Count attempts for this key
			$stmt = self::$pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND timestamp > ?");
			$stmt->execute([$key, $now - $windowSeconds]);
			$attempts = (int)$stmt->fetchColumn();

			if ($attempts >= $maxAttempts) {
				$stmt = self::$pdo->prepare("SELECT MIN(timestamp) FROM login_attempts WHERE ip = ?");
				$stmt->execute([$key]);
				$oldest = $stmt->fetchColumn();
				$waitSeconds = max(1, ($oldest + $windowSeconds) - $now);

				return [
					'allowed' => false,
					'remaining' => 0,
					'wait_seconds' => $waitSeconds,
					'retry_after' => $waitSeconds,
					'message' => "Too many requests. Please wait {$waitSeconds} second(s) before retrying."
				];
			}

			// Record attempt
			$recordStmt = self::$pdo->prepare("INSERT INTO login_attempts (ip, timestamp) VALUES (?, ?)");
			$recordStmt->execute([$key, $now]);

			return [
				'allowed' => true,
				'remaining' => $maxAttempts - ($attempts + 1),
				'wait_seconds' => 0,
				'retry_after' => 0
			];
		} catch (PDOException $e) {
			error_log("General rate limit error: " . $e->getMessage());
			return ['allowed' => true, 'remaining' => $maxAttempts, 'wait_seconds' => 0, 'retry_after' => 0];
		}
	}

	/**
	 * Enforce permission requirement, returning 403 or redirecting if unauthorized
	 */
	public static function requirePermission($permissionSlug, $adminId = null, $redirectUrl = null) {
		if ($adminId === null) {
			$adminId = $_SESSION['admin_id'] ?? null;
		}

		if (!self::hasPermission($permissionSlug, $adminId)) {
			if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || 
			    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
			    (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
				http_response_code(403);
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode([
					'success' => false,
					'message' => "Access Denied: You do not have the required permission '{$permissionSlug}'."
				]);
				exit();
			}

			if ($redirectUrl) {
				header("Location: $redirectUrl");
				exit();
			}

			http_response_code(403);
			?>
			<!DOCTYPE html>
			<html lang="en">
			<head>
				<meta charset="UTF-8">
				<title>403 Access Denied - Cloud Aspire</title>
				<style>
					body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
					.card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 40px; max-width: 500px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
					.icon { font-size: 3rem; color: #ef4444; margin-bottom: 16px; }
					h1 { margin: 0 0 12px; font-size: 1.5rem; }
					p { color: #94a3b8; font-size: 0.95rem; line-height: 1.5; margin-bottom: 24px; }
					.btn { display: inline-block; background: #4f46e5; color: #fff; padding: 10px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; }
				</style>
			</head>
			<body>
				<div class="card">
					<div class="icon">&#9888;</div>
					<h1>Access Denied (403)</h1>
					<p>You do not have the required permission (<code><?php echo htmlspecialchars($permissionSlug); ?></code>) to access or perform actions in this administrative module.</p>
					<a href="/admin/dashboard" class="btn">Return to Dashboard</a>
				</div>
			</body>
			</html>
			<?php
			exit();
		}

		return true;
	}

	/**
	 * Ensure the audit log table exists in the database
	 */
	public static function ensureAuditLogTable() {
		if (!self::$pdo) return;
		try {
			self::$pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (
				id INT AUTO_INCREMENT PRIMARY KEY,
				admin_id INT NULL,
				admin_username VARCHAR(100) NULL,
				action VARCHAR(100) NOT NULL,
				target_type VARCHAR(50) NULL,
				target_id INT NULL,
				details TEXT NULL,
				ip_address VARCHAR(45) NOT NULL,
				user_agent TEXT NULL,
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_admin_id (admin_id),
				INDEX idx_action (action),
				INDEX idx_created_at (created_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
		} catch (PDOException $e) {
			error_log("Audit log table creation error: " . $e->getMessage());
		}
	}

	/**
	 * Log a privileged administrative action for compliance auditing
	 */
	public static function logAudit($param1, $param2 = null, $param3 = null, $param4 = null, $param5 = null) {
		if (!self::$pdo) return;
		self::ensureAuditLogTable();

		// Handle either signature:
		// 1. logAudit($adminId, $action, $targetType, $targetId, $details)
		// 2. logAudit($action, $targetType, $targetId, $details, $adminId)
		if (is_numeric($param1) || ($param1 === null && is_string($param2))) {
			$adminId    = $param1 ? (int)$param1 : null;
			$action     = (string)$param2;
			$targetType = is_string($param3) ? $param3 : null;
			$targetId   = is_numeric($param4) ? (int)$param4 : null;
			$details    = $param5;
		} else {
			$action     = (string)$param1;
			$targetType = is_string($param2) ? $param2 : null;
			$targetId   = is_numeric($param3) ? (int)$param3 : null;
			$details    = $param4;
			$adminId    = is_numeric($param5) ? (int)$param5 : ($_SESSION['admin_id'] ?? null);
		}

		$username = $_SESSION['admin_username'] ?? ($adminId ? "Admin #{$adminId}" : 'System');
		$ip = self::getClientIp();
		$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

		if (is_array($details) || is_object($details)) {
			// Strip sensitive fields before logging
			$filtered = (array)$details;
			foreach (['password', 'csrf_token', 'token', 'secret', 'new_password', 'current_password', 'confirm_password'] as $k) {
				if (isset($filtered[$k])) {
					$filtered[$k] = '[REDACTED]';
				}
			}
			$detailsStr = json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} else {
			$detailsStr = (string)$details;
		}

		try {
			$stmt = self::$pdo->prepare("INSERT INTO admin_audit_logs 
				(admin_id, admin_username, action, target_type, target_id, details, ip_address, user_agent, created_at)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
			$stmt->execute([
				$adminId,
				$username,
				$action,
				$targetType,
				$targetId,
				$detailsStr,
				$ip,
				$ua
			]);
		} catch (PDOException $e) {
			error_log("Failed to write audit log: " . $e->getMessage());
		}
	}

	/**
	 * Validate CAPTCHA token server-side if configured in environment
	 */
	public static function verifyCaptcha($token = null) {
		$secretKey = $_ENV['CAPTCHA_SECRET_KEY'] ?? getenv('CAPTCHA_SECRET_KEY') ?: '';
		if (empty($secretKey)) {
			if (isset($_POST['website_hp']) && !empty($_POST['website_hp'])) {
				return false; // Honeypot triggered
			}
			return true;
		}

		if (empty($token)) {
			$token = $_POST['cf-turnstile-response'] ?? $_POST['g-recaptcha-response'] ?? '';
		}

		if (empty($token)) {
			return false;
		}

		$verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
		$postData = http_build_query([
			'secret' => $secretKey,
			'response' => $token,
			'remoteip' => self::getClientIp()
		]);

		$opts = [
			'http' => [
				'method' => 'POST',
				'header' => "Content-type: application/x-www-form-urlencoded\r\n",
				'content' => $postData,
				'timeout' => 5,
				'ignore_errors' => true
			]
		];

		try {
			$context = stream_context_create($opts);
			$result = @file_get_contents($verifyUrl, false, $context);
			if ($result === false) {
				error_log("CAPTCHA verification endpoint connection failure.");
				return false;
			}
			$resData = json_decode($result, true);
			return !empty($resData['success']);
		} catch (Exception $e) {
			error_log("CAPTCHA verification error: " . $e->getMessage());
			return false;
		}
	}
}
?>