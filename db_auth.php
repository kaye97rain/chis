<?php
// Secure Database-Based Token Authentication System with Hashed Tokens
// Implements atomic token rotation with proper database tables

// Apply CORS headers when called from an API endpoint (not from a system page).
$_dba_caller = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
if (strncmp($_dba_caller, 'api_', 4) === 0) {
    require_once __DIR__ . '/cors.php';
}
unset($_dba_caller);

// Load environment variables from API/.env (function already defined in config.php)
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) {
            throw new Exception("Environment file not found: $path");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

loadEnv(__DIR__ . '/.env');

// Use Philippine time for PHP date() and MySQL NOW() so both agree and daily
// records/reports fall on the correct local date. Override with APP_TIMEZONE in .env.
$_dba_timezone = getenv('APP_TIMEZONE') ?: 'Asia/Manila';
if (!@date_default_timezone_set($_dba_timezone)) {
    date_default_timezone_set('Asia/Manila');
}
define('DB_TIME_ZONE_OFFSET', (new DateTime('now'))->format('P'));
unset($_dba_timezone);

// Database connection using API/.env (no fallbacks - must be in .env)
$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASS');

if (!$host || !$dbname || !$username) {
    die("Database configuration missing from .env file");
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Match the PHP timezone so PHP-generated dates and NOW() are comparable.
    $conn->exec("SET time_zone = " . $conn->quote(DB_TIME_ZONE_OFFSET));

    // Keep auth requests responsive under lock contention.
    try {
        $conn->exec("SET innodb_lock_wait_timeout = 5");
    } catch (Throwable $timeoutConfigError) {
        error_log("Unable to set innodb_lock_wait_timeout: " . $timeoutConfigError->getMessage());
    }

} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $conn = null; // Set to null instead of dying
}

// CHIS login cookie name; separate from HRMIS's auth_token on the same host (see system/config.php).
if (!defined('CHIS_AUTH_COOKIE')) {
    define('CHIS_AUTH_COOKIE', 'chis_auth_token');
}

// Token configuration (same session model as HRIS)
//
// Fixed session duration from sign-in. Activity does not extend it, so the browser cookie
// and the server agree on one deadline. Override with SESSION_DURATION_SECONDS in API/.env.
define('ACCESS_TOKEN_EXPIRY', max(300, (int)(getenv('SESSION_DURATION_SECONDS') ?: 86400))); // 24 hours
// Refresh tokens are still stored but cannot be redeemed (api_auth.php refuses
// action=refresh), so nothing can stretch a session past ACCESS_TOKEN_EXPIRY.
define('REFRESH_TOKEN_EXPIRY', 2592000); // 30 days
define('TOKEN_LENGTH', 64);
define('HASH_ALGO', PASSWORD_DEFAULT); // Secure hashing algorithm (bcrypt)

/**
 * Normalize role to app-friendly key.
 */
function normalizeRoleKey($roleId, $roleName = '') {
    $roleId = (int)$roleId;
    $roleName = strtolower(trim((string)$roleName));

    if ($roleId === 1 || ($roleName !== '' && strpos($roleName, 'super') !== false)) {
        return 'super_admin';
    }

    if ($roleId === 2 || ($roleName !== '' && (strpos($roleName, 'administrator') !== false || strpos($roleName, 'admin') !== false))) {
        return 'administrator';
    }

    if ($roleId === 7 || ($roleName !== '' && strpos($roleName, 'pharmacist') !== false)) {
        return 'pharmacist';
    }

    if ($roleId === 8 || ($roleName !== '' && strpos($roleName, 'coa') !== false)) {
        return 'coa';
    }

    if ($roleId === 9 || ($roleName !== '' && strpos($roleName, 'accounting') !== false)) {
        return 'accounting';
    }

    // "User": Sanitary Permit / Health Certificate staff (key 'staff'; 'user' already means pharmacist).
    if ($roleId === 10) {
        return 'staff';
    }

    return 'user';
}

function normalizeSubsystemAccess($value) {
    $value = strtolower(trim((string)$value));
    // 'permits' = Sanitary Permit / Health Certificate staff: no EMedTrack or Laboratory access.
    if (in_array($value, ['emedtrack', 'laboratory', 'all', 'permits'], true)) {
        return $value;
    }
    return 'emedtrack';
}

/**
 * Permits-only accounts must not reach EMedTrack or Laboratory modules even when their
 * role would allow the page (e.g. Pharmacist role defaults include dashboard/checkout).
 */
/**
 * Whether the account's subsystem access rules out this module, whatever the role allows.
 * Permits-only accounts get no EMedTrack/Laboratory modules; EMedTrack-only and
 * Laboratory-only accounts get no Sanitary Permit / Health Certificate.
 */
function isModuleBlockedForPermitsAccount($userData, $moduleKey) {
    $access = getUserSubsystemAccess($userData);
    if ($access === 'all') {
        return false;
    }
    $registry = getModuleRegistry();
    $owner = $registry[$moduleKey]['subsystem'] ?? '';
    if ($access === 'permits') {
        return in_array($owner, ['emedtrack', 'laboratory'], true);
    }
    return in_array($owner, ['sanitary_permit', 'health_certificate'], true);
}

function ensureUsersSubsystemAccessColumn() {
    global $conn;
    static $initialized = false;
    static $isAvailable = false;

    if ($initialized) {
        return $isAvailable;
    }
    $initialized = true;

    if (!$conn) {
        return false;
    }

    try {
        $stmt = $conn->query("SHOW COLUMNS FROM users LIKE 'subsystem_access'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE users ADD COLUMN subsystem_access VARCHAR(32) NOT NULL DEFAULT 'emedtrack' AFTER work_area");
        }
        $isAvailable = true;
    } catch (Throwable $e) {
        error_log("Unable to ensure users.subsystem_access column: " . $e->getMessage());
        $isAvailable = false;
    }

    return $isAvailable;
}

function getUserSubsystemAccess($userData) {
    global $conn;

    if (isset($userData['subsystem_access'])) {
        return normalizeSubsystemAccess($userData['subsystem_access']);
    }

    $userId = isset($userData['id']) ? (int)$userData['id'] : 0;
    if ($userId <= 0 || !$conn || !ensureUsersSubsystemAccessColumn()) {
        return 'emedtrack';
    }

    try {
        $stmt = $conn->prepare("SELECT subsystem_access FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return normalizeSubsystemAccess($value);
    } catch (Throwable $e) {
        error_log("Unable to load user subsystem access: " . $e->getMessage());
        return 'emedtrack';
    }
}

function userHasSubsystemAccess($userData, $subsystemKey) {
    $roleKey = strtolower(trim((string)($userData['role'] ?? ($userData['role_key'] ?? ''))));
    // Other roles, Administrator included, follow the account's subsystem access so a
    // permits-only account never reaches EMedTrack or Laboratory.
    if (isSuperAdminRoleKey($roleKey)) {
        return true;
    }

    $subsystemKey = normalizeSubsystemAccess($subsystemKey);
    $access = getUserSubsystemAccess($userData);
    return $access === 'all' || $access === $subsystemKey;
}

/**
 * Fetch user profile details from users table.
 * Returns null if not found or on error.
 */
function getUserProfile($userId) {
    global $conn;

    if (!$conn) {
        return null;
    }

    try {
        ensureUsersSubsystemAccessColumn();
        $stmt = $conn->prepare("
            SELECT
                u.user_id,
                u.user_name,
                u.role_id,
                r.role AS role_name,
                u.Location AS location,
                u.work_area,
                u.subsystem_access,
                u.f_name,
                u.m_name,
                u.l_name
            FROM users u
            LEFT JOIN table_role r ON r.role_id = u.role_id
            WHERE u.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    } catch (Exception $e) {
        error_log("Error fetching user profile: " . $e->getMessage());
        return null;
    }
}

/**
 * Generate a cryptographically secure random token
 */
function generateSecureToken($length = TOKEN_LENGTH) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Hash a token for secure storage.
 * Tokens are 256-bit random values, so a fast SHA-256 digest is safe here and lets
 * validation find the row with one indexed lookup instead of a bcrypt check per session.
 */
function hashToken($token) {
    return hash('sha256', (string)$token);
}

function isLegacyTokenHash($hash) {
    return strncmp((string)$hash, '$2', 2) === 0;
}

/**
 * Verify a token against its hash (SHA-256, or bcrypt for tokens issued before the switch)
 */
function verifyTokenHash($token, $hash) {
    if (isLegacyTokenHash($hash)) {
        return password_verify($token, $hash);
    }
    return hash_equals((string)$hash, hashToken($token));
}

/**
 * Find the active, unexpired access token row for a raw token, or null.
 */
function findActiveAccessToken($token) {
    global $conn;

    $columns = "id, user_id, username, token, created_at, expires_at, last_accessed";
    $stmt = $conn->prepare("
        SELECT {$columns}
        FROM access_tokens
        WHERE token = ? AND expires_at > NOW() AND is_active = TRUE
        LIMIT 1
    ");
    $stmt->execute([hashToken($token)]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    // Bcrypt-hashed tokens from before the switch; these expire on their own.
    $stmt = $conn->prepare("
        SELECT {$columns}
        FROM access_tokens
        WHERE token LIKE '$2%' AND expires_at > NOW() AND is_active = TRUE
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        if (password_verify($token, $row['token'])) {
            return $row;
        }
    }
    return null;
}

/**
 * Find the usable refresh token row for a raw refresh token, or null.
 */
function findUsableRefreshToken($refreshToken) {
    global $conn;

    $columns = "id, user_id, username, refresh_token, access_token_id";
    $where = "expires_at > NOW() AND is_used = FALSE AND is_revoked = FALSE";
    $stmt = $conn->prepare("SELECT {$columns} FROM refresh_tokens WHERE refresh_token = ? AND {$where} LIMIT 1");
    $stmt->execute([hashToken($refreshToken)]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $stmt = $conn->prepare("SELECT {$columns} FROM refresh_tokens WHERE refresh_token LIKE '$2%' AND {$where}");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        if (password_verify($refreshToken, $row['refresh_token'])) {
            return $row;
        }
    }
    return null;
}

/**
 * Generate session token (access token)
 */
function generateSessionToken() {
    return generateSecureToken();
}

/**
 * Generate refresh token
 */
function generateRefreshToken() {
    return generateSecureToken();
}

/**
 * Start a new session for a user (same model as HRIS).
 * Other browsers and devices stay signed in; each session has its own fixed
 * deadline, and logout revokes only the token presented.
 */
function storeUserSession($userId, $username) {
    global $conn;

    if (!$conn) {
        error_log("storeUserSession: Database connection is not available.");
        return false;
    }

    $accessToken = generateSessionToken();
    $refreshToken = generateRefreshToken();
    $accessExpiresAt = date('Y-m-d H:i:s', time() + ACCESS_TOKEN_EXPIRY);
    $refreshExpiresAt = date('Y-m-d H:i:s', time() + REFRESH_TOKEN_EXPIRY);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    try {
        $conn->beginTransaction();

        $accessStmt = $conn->prepare("
            INSERT INTO access_tokens
            (token, user_id, username, expires_at, ip_address, user_agent, is_active)
            VALUES (?, ?, ?, ?, ?, ?, TRUE)
        ");
        $accessStmt->execute([
            hashToken($accessToken),
            $userId,
            $username,
            $accessExpiresAt,
            $ipAddress,
            $userAgent
        ]);
        $accessTokenId = $conn->lastInsertId();

        $refreshStmt = $conn->prepare("
            INSERT INTO refresh_tokens
            (refresh_token, user_id, username, access_token_id, expires_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $refreshStmt->execute([
            hashToken($refreshToken),
            $userId,
            $username,
            $accessTokenId,
            $refreshExpiresAt,
            $ipAddress,
            $userAgent
        ]);

        logTokenAction($accessTokenId, 'access', $userId, 'created', $ipAddress, $userAgent);
        $conn->commit();

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => ACCESS_TOKEN_EXPIRY,
            'token_type' => 'Bearer'
        ];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("storeUserSession failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Validate the request's access token against the database.
 * Read-only per request (no sliding expiry, no per-request log row), like HRIS.
 * system/config.php calls this directly when the API lives beside the pages.
 */
function chisValidateAccessToken() {
    global $conn;

    if (!$conn) {
        error_log("validateToken: Database connection is not available");
        return false;
    }

    // Get token from multiple sources
    $token = null;

    // First priority: Authorization header
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $headerName => $headerValue) {
        if (strcasecmp((string)$headerName, 'Authorization') === 0
            && preg_match('/Bearer\s+(.*)$/i', (string)$headerValue, $matches)) {
            $token = trim($matches[1]);
        }
    }

    // Second priority: Cookie
    if (!$token) {
        $token = $_COOKIE[CHIS_AUTH_COOKIE] ?? null;
    }

    // Third priority: POST data (for AJAX requests)
    // Tokens are never read from the URL: query strings end up in server logs and browser history.
    if (!$token && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['auth_token'] ?? null;
    }

    if (!$token) {
        return false;
    }

    try {
        $tokenData = findActiveAccessToken($token);
        if ($tokenData) {
            $profile = getUserProfile($tokenData['user_id']);
            if (!$profile) {
                $profile = [];
            }

            return [
                'id' => $tokenData['user_id'],
                'username' => $tokenData['username'],
                'role' => normalizeRoleKey($profile['role_id'] ?? null, $profile['role_name'] ?? ''),
                'role_id' => isset($profile['role_id']) ? (int)$profile['role_id'] : null,
                'role_name' => $profile['role_name'] ?? null,
                'location' => $profile['location'] ?? null,
                'work_area' => $profile['work_area'] ?? null,
                'token' => $token,
                'expires_at' => $tokenData['expires_at']
            ];
        }

        error_log("validateToken: Token verification failed");
        return false;

    } catch (Exception $e) {
        error_log("Error validating token: " . $e->getMessage());
        return false;
    }
}

if (!function_exists('validateToken')) {
    function validateToken() {
        return chisValidateAccessToken();
    }
}

/**
 * Access audit log (same table and columns as HRIS): sign-ins, failed sign-ins
 * with the reason, and sign-outs. Never throws; a logging problem must not block login.
 */
function chisLogAccessEvent($eventType, $username, $userId = null, $role = null, $reason = null) {
    global $conn;
    if (!$conn) {
        return;
    }
    try {
        static $tableReady = false;
        if (!$tableReady) {
            $conn->exec("CREATE TABLE IF NOT EXISTS tbl_access_audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NULL,
                username VARCHAR(255) NOT NULL,
                role VARCHAR(50) NULL,
                piid VARCHAR(64) NULL,
                event_type VARCHAR(30) NOT NULL,
                reason VARCHAR(100) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_access_audit_created (created_at),
                KEY idx_access_audit_user (username, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $tableReady = true;
        }
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $conn->prepare("
            INSERT INTO tbl_access_audit_log
                (user_id, username, role, event_type, reason, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $userId,
            mb_substr((string)$username, 0, 255),
            $role,
            $eventType,
            $reason,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null
        ]);
    } catch (Throwable $e) {
        error_log('chisLogAccessEvent: ' . $e->getMessage());
    }
}

/**
 * Refresh access token using refresh token (atomic operation)
 */
function refreshAccessToken($refreshToken) {
    global $conn;

    if (!$conn) {
        return false;
    }

    try {
        $rt = findUsableRefreshToken($refreshToken);
        if (!$rt) {
            error_log("Invalid or expired refresh token");
            return false;
        }

        // Claim the refresh token first; the is_used check makes a replayed token fail here.
        $conn->beginTransaction();
        $updateRefreshStmt = $conn->prepare("
            UPDATE refresh_tokens
            SET is_used = TRUE
            WHERE id = ? AND is_used = FALSE AND is_revoked = FALSE
        ");
        $updateRefreshStmt->execute([$rt['id']]);
        if ($updateRefreshStmt->rowCount() !== 1) {
            $conn->rollBack();
            return false;
        }
        $conn->commit();

        // storeUserSession() runs its own transaction and revokes the user's old tokens.
        $newTokens = storeUserSession($rt['user_id'], $rt['username']);
        if (!$newTokens) {
            return false;
        }

        logTokenAction($rt['access_token_id'], 'refresh', $rt['user_id'], 'refreshed');
        return $newTokens;

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error refreshing token: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete user session (logout) - revoke all tokens atomically
 */
function deleteUserSession($token) {
    global $conn;

    if (!$conn) {
        return false;
    }

    try {
        // Find the token to revoke
        $tokenData = findActiveAccessToken($token);
        if (!$tokenData) {
            error_log("Token not found for revocation");
            return false;
        }

        $conn->beginTransaction();

        // Revoke access token
        $revokeAccessStmt = $conn->prepare("
            UPDATE access_tokens
            SET is_active = FALSE
            WHERE id = ?
        ");
        $revokeAccessStmt->execute([$tokenData['id']]);

        // Revoke associated refresh tokens
        $revokeRefreshStmt = $conn->prepare("
            UPDATE refresh_tokens
            SET is_revoked = TRUE
            WHERE access_token_id = ?
        ");
        $revokeRefreshStmt->execute([$tokenData['id']]);

        // Log revocation
        logTokenAction($tokenData['id'], 'access', $tokenData['user_id'], 'revoked');

        $conn->commit();
        chisLogAccessEvent('logout', (string)$tokenData['username'], (int)$tokenData['user_id']);
        return true;

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error deleting user session: " . $e->getMessage());
        return false;
    }
}

/**
 * Log token actions for auditing
 */
function logTokenAction($tokenId, $tokenType, $userId, $action, $ipAddress = null, $userAgent = null) {
    global $conn;

    try {
        if (!$ipAddress) $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$userAgent) $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO token_logs
            (token_id, token_type, user_id, action, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tokenId, $tokenType, $userId, $action, $ipAddress, $userAgent]);
    } catch (Exception $e) {
        error_log("Error logging token action: " . $e->getMessage());
    }
}

/**
 * Canonical module registry for page-level access checks.
 */
function getModuleRegistry() {
    return [
        'subsystem_emedtrack' => [
            'label' => 'CHIS EMedTrack',
            'description' => 'Access to the EMedTrack subsystem',
            'page' => 'subsystem_entry.php?subsystem=emedtrack',
            'subsystem' => 'emedtrack'
        ],
        'subsystem_laboratory' => [
            'label' => 'CHIS Laboratory',
            'description' => 'Access to the Laboratory subsystem',
            'page' => 'subsystem_entry.php?subsystem=laboratory',
            'subsystem' => 'laboratory'
        ],
        'subsystem_sanitary_permit' => [
            'label' => 'CHIS Sanitary Permit',
            'description' => 'Access to the Sanitary Permit subsystem (permits to operate)',
            'page' => 'subsystem_entry.php?subsystem=sanitary_permit',
            'subsystem' => 'sanitary_permit'
        ],
        'subsystem_health_certificate' => [
            'label' => 'CHIS Health Certificate',
            'description' => 'Access to the Health Certificate subsystem',
            'page' => 'subsystem_entry.php?subsystem=health_certificate',
            'subsystem' => 'health_certificate'
        ],
        'dashboard' => [
            'label' => 'Dashboard',
            'description' => 'Overview page and summary widgets',
            'page' => 'inventory_dashboard.php',
            'subsystem' => 'emedtrack'
        ],
        'inventory' => [
            'label' => 'Inventory',
            'description' => 'Inventory list, stock updates, and adjustments',
            'page' => 'inventory.php',
            'subsystem' => 'emedtrack'
        ],
        'checkout' => [
            'label' => 'Checkout',
            'description' => 'Item checkout and RIS generation',
            'page' => 'checkout.php',
            'subsystem' => 'emedtrack'
        ],
        'patient_history' => [
            'label' => 'Patient Medication History',
            'description' => 'Recipient profiles and released medicine history',
            'page' => 'patient_history.php',
            'subsystem' => 'emedtrack'
        ],
        'barangay_midwives' => [
            'label' => 'Barangay Midwives',
            'description' => 'Directory of midwives assigned per barangay',
            'page' => 'barangay_midwives.php',
            'subsystem' => 'emedtrack'
        ],
        'clinics' => [
            'label' => 'Stocks Distribution',
            'description' => 'Clinic transfer and stocks distribution',
            'page' => 'clinics.php',
            'subsystem' => 'emedtrack'
        ],
        'transaction_history' => [
            'label' => 'Transaction History',
            'description' => 'Audit trail and transaction logs',
            'page' => 'transaction_history.php',
            'subsystem' => 'emedtrack'
        ],
        'reports' => [
            'label' => 'Inventory Reports',
            'description' => 'Inventory and stock movement reports',
            'page' => 'reports.php',
            'subsystem' => 'emedtrack'
        ],
        'user_manual' => [
            'label' => 'User Manual',
            'description' => 'System documentation and usage guides',
            'page' => 'user_manual.php',
            'subsystem' => 'emedtrack'
        ],
        'user_management' => [
            'label' => 'User Management',
            'description' => 'User administration and role module access levels',
            'page' => 'user_management.php',
            'subsystem' => 'chis'
        ]
    ];
}

function normalizeModuleKey($moduleKey) {
    return strtolower(trim((string)$moduleKey));
}

if (!function_exists('isSuperAdminRoleKey')) {
    function isSuperAdminRoleKey($roleKey) {
        $roleKey = strtolower(trim((string)$roleKey));
        return $roleKey === 'super_admin' || $roleKey === 'superadmin';
    }
}

if (!function_exists('isAdministratorRoleKey')) {
    function isAdministratorRoleKey($roleKey) {
        $roleKey = strtolower(trim((string)$roleKey));
        return $roleKey === 'administrator' || $roleKey === 'admin';
    }
}

if (!function_exists('isPharmacistRoleKey')) {
    function isPharmacistRoleKey($roleKey) {
        $roleKey = strtolower(trim((string)$roleKey));
        return $roleKey === 'pharmacist' || $roleKey === 'user';
    }
}

if (!function_exists('isReadOnlyViewerRoleKey')) {
    function isReadOnlyViewerRoleKey($roleKey) {
        $roleKey = strtolower(trim((string)$roleKey));
        return in_array($roleKey, ['coa', 'accounting'], true);
    }
}

if (!function_exists('isPermitStaffRoleKey')) {
    function isPermitStaffRoleKey($roleKey) {
        return strtolower(trim((string)$roleKey)) === 'staff';
    }
}

/**
 * Modules only a Super Admin may ever use. A role or per-user toggle cannot grant them to anyone else.
 */
if (!function_exists('isSuperAdminOnlyModule')) {
    function isSuperAdminOnlyModule($moduleKey) {
        return strtolower(trim((string)$moduleKey)) === 'user_management';
    }
}

if (!function_exists('userCanWrite')) {
    function userCanWrite($userData) {
        $roleKey = strtolower(trim((string)($userData['role'] ?? '')));
        return isSuperAdminRoleKey($roleKey) || isAdministratorRoleKey($roleKey) || isPharmacistRoleKey($roleKey);
    }
}

/**
 * Work areas the user may read or change.
 * Returns null when unrestricted (Super Admin, Administrator, or an ALL-location account
 * with EMedTrack access, which picks its location on location_select.php); otherwise
 * the user's own location work area plus LABORATORY when they have lab access.
 */
function getUserAllowedWorkAreas($userData) {
    $roleKey = strtolower(trim((string)($userData['role'] ?? '')));
    if (isSuperAdminRoleKey($roleKey) || isAdministratorRoleKey($roleKey)) {
        return null;
    }

    $location = strtoupper(trim((string)($userData['location'] ?? '')));
    $location = preg_replace('/[^A-Z0-9 _-]/', '', $location);
    if ($location === '') {
        $location = 'CHO';
    }

    $canEmedtrack = userHasModuleAccess($userData, 'subsystem_emedtrack');
    if ($location === 'ALL' && $canEmedtrack) {
        return null;
    }

    $allowed = [];
    if ($location !== 'ALL' && $canEmedtrack) {
        $allowed[] = $location;
    }
    if (userHasModuleAccess($userData, 'subsystem_laboratory')) {
        $allowed[] = 'LABORATORY';
    }
    return $allowed;
}

/**
 * Whether the user may act on the given work area ('ALL' means every area).
 */
function userCanAccessWorkArea($userData, $workArea) {
    $allowed = getUserAllowedWorkAreas($userData);
    if ($allowed === null) {
        return true;
    }
    $workArea = strtoupper(trim((string)$workArea));
    return $workArea !== 'ALL' && in_array($workArea, $allowed, true);
}

/**
 * Page-equivalent permission check for API endpoints.
 * Mirrors the $can* rules at the top of the system pages, so switching a module off in
 * User Management (or a role lacking it) also blocks direct API calls, not just the menu.
 * $workArea LABORATORY applies the Laboratory subsystem rules used by laboratory/*.php.
 */
function apiUserCan($userData, $capability, $workArea = '') {
    $roleKey = strtolower(trim((string)($userData['role'] ?? '')));
    $isSuperAdmin = isSuperAdminRoleKey($roleKey);
    $isAdmin = isAdministratorRoleKey($roleKey);
    $isViewer = isReadOnlyViewerRoleKey($roleKey);
    $isManager = $isSuperAdmin || $isAdmin;
    $isLab = strtoupper(trim((string)$workArea)) === 'LABORATORY';
    $userArea = strtolower(trim((string)($userData['work_area'] ?? '')));
    $isDispensingPharmacist = isPharmacistRoleKey($roleKey) && ($userArea === 'pharmacy' || $userArea === '');

    switch ($capability) {
        case 'dashboard':
            return $isLab
                ? userHasModuleAccess($userData, 'subsystem_laboratory')
                : userHasModuleAccess($userData, 'dashboard');
        case 'inventory_view':
            return userHasModuleAccess($userData, $isLab ? 'subsystem_laboratory' : 'inventory')
                && ($isManager || $isViewer);
        case 'inventory_write':
            return userHasModuleAccess($userData, $isLab ? 'subsystem_laboratory' : 'inventory') && $isManager;
        case 'checkout':
            if ($isLab) {
                return userHasModuleAccess($userData, 'subsystem_laboratory');
            }
            return userHasModuleAccess($userData, 'checkout')
                && ($isManager || $isViewer || $isDispensingPharmacist);
        case 'clinics':
            return !$isLab && userHasModuleAccess($userData, 'clinics') && apiUserCan($userData, 'inventory_view');
        case 'transaction_history':
            return userHasModuleAccess($userData, 'transaction_history') && ($isManager || $isViewer);
        case 'transaction_admin':
            return userHasModuleAccess($userData, 'transaction_history') && $isManager;
        case 'reports':
            return userHasModuleAccess($userData, 'reports')
                && (apiUserCan($userData, 'inventory_view') || apiUserCan($userData, 'checkout'));
        case 'patient_history':
            return userHasModuleAccess($userData, 'patient_history')
                && ($isManager || $isViewer || apiUserCan($userData, 'checkout'));
        case 'barangay_midwives':
            return userHasModuleAccess($userData, 'barangay_midwives') && ($isManager || $isViewer);
    }

    return false;
}

/**
 * Stop the request with 403 unless the user has at least one of the capabilities.
 */
function requireApiCapability($userData, $capabilities, $workArea = '') {
    foreach ((array)$capabilities as $capability) {
        if (apiUserCan($userData, $capability, $workArea)) {
            return;
        }
    }

    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'status' => 'error',
        'message' => 'You do not have access to this module. Ask an administrator to enable it in User Management.'
    ]);
    exit;
}

/**
 * Expiry date (Y-m-d) of a stock batch that is already past expiry, or null when the batch
 * is still usable or has no readable expiry. A batch is usable through its expiry date.
 */
function stockBatchExpiredOn($expiryDate) {
    $raw = trim((string)$expiryDate);
    if ($raw === '' || strpos($raw, '0000-00-00') === 0) {
        return null;
    }
    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return null;
    }
    $expiry = date('Y-m-d', $timestamp);
    return $expiry < date('Y-m-d') ? $expiry : null;
}

define('STOCK_LOCK_NAME', 'chis_stock_movement');

/**
 * Serialize stock-changing requests (checkout, transfer, void/undo, RIS edit) so two
 * users cannot both pass an availability check for the same units.
 * Call BEFORE beginTransaction() so the transaction reads data committed by the
 * previous lock holder. The lock is released automatically when the request ends.
 */
function acquireStockLock($conn, $timeoutSeconds = 10) {
    static $held = false;
    if ($held) {
        return true;
    }

    try {
        $stmt = $conn->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([STOCK_LOCK_NAME, (int)$timeoutSeconds]);
        if ((int)$stmt->fetchColumn() !== 1) {
            return false;
        }
    } catch (Throwable $e) {
        error_log('acquireStockLock failed: ' . $e->getMessage());
        return false;
    }

    $held = true;
    register_shutdown_function(function () use ($conn) {
        try {
            $conn->query("SELECT RELEASE_LOCK(" . $conn->quote(STOCK_LOCK_NAME) . ")");
        } catch (Throwable $e) {
            // Connection already closed; MySQL releases the lock with the session.
        }
    });
    return true;
}

function getDefaultModuleAccessByRoleKey($roleKey) {
    $roleKey = strtolower(trim((string)$roleKey));
    $defaults = [
        'subsystem_emedtrack' => true,
        'subsystem_laboratory' => false,
        // Permit/certificate subsystems are granted per role or per user in User Management.
        'subsystem_sanitary_permit' => false,
        'subsystem_health_certificate' => false,
        'dashboard' => true,
        'inventory' => false,
        'checkout' => false,
        'patient_history' => false,
        'barangay_midwives' => false,
        'clinics' => false,
        'transaction_history' => false,
        'reports' => false,
        'user_manual' => true,
        'user_management' => false
    ];

    if (isSuperAdminRoleKey($roleKey)) {
        foreach ($defaults as $moduleKey => $value) {
            $defaults[$moduleKey] = true;
        }
        return $defaults;
    }

    if (isAdministratorRoleKey($roleKey)) {
        // Administrators see every module; User Management is reserved for Super Admin.
        foreach ($defaults as $moduleKey => $value) {
            $defaults[$moduleKey] = !isSuperAdminOnlyModule($moduleKey);
        }
        return $defaults;
    }

    if (isPharmacistRoleKey($roleKey)) {
        $defaults['checkout'] = true;
        $defaults['patient_history'] = true;
        return $defaults;
    }

    if (isPermitStaffRoleKey($roleKey)) {
        $defaults['subsystem_sanitary_permit'] = true;
        $defaults['subsystem_health_certificate'] = true;
        return $defaults;
    }

    if (isReadOnlyViewerRoleKey($roleKey)) {
        foreach ($defaults as $moduleKey => $value) {
            $defaults[$moduleKey] = true;
        }
        $defaults['user_management'] = false;
        // COA/Accounting audit inventory; permits and certificates stay off unless granted.
        $defaults['subsystem_sanitary_permit'] = false;
        $defaults['subsystem_health_certificate'] = false;
        return $defaults;
    }

    return $defaults;
}

function getCanonicalRoleIds() {
    return [1, 2, 7, 8, 9, 10];
}

/**
 * Load role metadata from table_role with safe fallbacks.
 */
function getRoleDefinitions() {
    global $conn;

    $fallback = [
        ['role_id' => 1, 'role_name' => 'Super Admin', 'role_key' => 'super_admin'],
        ['role_id' => 2, 'role_name' => 'Administrator', 'role_key' => 'administrator'],
        ['role_id' => 7, 'role_name' => 'Pharmacist', 'role_key' => 'pharmacist'],
        ['role_id' => 8, 'role_name' => 'COA', 'role_key' => 'coa'],
        ['role_id' => 9, 'role_name' => 'Accounting', 'role_key' => 'accounting'],
        ['role_id' => 10, 'role_name' => 'User', 'role_key' => 'staff']
    ];
    $canonicalRoleIds = getCanonicalRoleIds();

    if (!$conn) {
        return $fallback;
    }

    // Roles do not change during a request; every page asks for them at least once.
    static $resolvedRoles = null;
    if ($resolvedRoles !== null) {
        return $resolvedRoles;
    }

    $loadRows = function () use ($conn) {
        $stmt = $conn->query("SELECT role_id, role, description FROM table_role ORDER BY role_id ASC");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    };

    try {
        $rows = $loadRows();
    } catch (Throwable $e) {
        error_log("Error loading role definitions: " . $e->getMessage());
        return $fallback;
    }

    // Seed the canonical roles only when one is missing or differs, instead of writing on every request.
    $existingById = [];
    foreach ($rows as $row) {
        $existingById[(int)($row['role_id'] ?? 0)] = $row;
    }
    $rolesToSeed = [];
    foreach ($fallback as $role) {
        $roleId = (int)$role['role_id'];
        if ($roleId === 1) {
            continue;
        }
        $description = $role['role_key'] === 'coa' || $role['role_key'] === 'accounting'
            ? 'View-only access'
            : ($role['role_key'] === 'staff' ? 'Permits and certificates staff' : 'System role');
        $existing = $existingById[$roleId] ?? null;
        if ($existing === null
            || (string)($existing['role'] ?? '') !== $role['role_name']
            || (string)($existing['description'] ?? '') !== $description) {
            $rolesToSeed[] = [$roleId, $role['role_name'], $description];
        }
    }

    if (!empty($rolesToSeed)) {
        try {
            $seedStmt = $conn->prepare("
                INSERT INTO table_role (role_id, role, description)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    role = VALUES(role),
                    description = VALUES(description)
            ");
            foreach ($rolesToSeed as $seedRow) {
                $seedStmt->execute($seedRow);
            }
            $rows = $loadRows();
        } catch (Throwable $e) {
            error_log("Unable to seed role definitions: " . $e->getMessage());
        }
    }

    if (empty($rows)) {
        return $fallback;
    }

    $rolesById = [];
    foreach ($rows as $row) {
        $roleId = isset($row['role_id']) ? (int)$row['role_id'] : 0;
        if ($roleId <= 0) {
            continue;
        }

        // Keep only roles that are used by the current system implementation.
        if (!in_array($roleId, $canonicalRoleIds, true)) {
            continue;
        }

        $roleName = trim((string)($row['role'] ?? 'Role ' . $roleId));
        if ($roleName === '') {
            $roleName = 'Role ' . $roleId;
        }

        $rolesById[$roleId] = [
            'role_id' => $roleId,
            'role_name' => $roleName,
            'role_key' => normalizeRoleKey($roleId, $roleName)
        ];
    }

    $roles = [];
    foreach ($canonicalRoleIds as $roleId) {
        if (isset($rolesById[$roleId])) {
            $roles[] = $rolesById[$roleId];
            continue;
        }
        foreach ($fallback as $fallbackRole) {
            if ((int)$fallbackRole['role_id'] === $roleId) {
                $roles[] = $fallbackRole;
                break;
            }
        }
    }

    $resolvedRoles = !empty($roles) ? $roles : $fallback;
    return $resolvedRoles;
}

function getRoleLookupById() {
    $roles = getRoleDefinitions();
    $lookup = [];
    foreach ($roles as $role) {
        $lookup[(int)$role['role_id']] = $role;
    }
    return $lookup;
}

/**
 * Create and seed role_module_access table if available.
 */
function ensureRoleModuleAccessTable() {
    global $conn;
    static $initialized = false;
    static $isAvailable = false;

    if ($initialized) {
        return $isAvailable;
    }
    $initialized = true;

    if (!$conn) {
        return false;
    }

    // Normal case: the table exists, so one cheap read replaces CREATE TABLE on every request.
    try {
        $existingRows = (int)$conn->query("SELECT COUNT(*) FROM role_module_access")->fetchColumn();
        $isAvailable = true;
    } catch (Throwable $e) {
        $existingRows = 0;
        try {
            // updated_at has no DEFAULT CURRENT_TIMESTAMP: MySQL 5.5 rejects that on DATETIME,
            // so every INSERT below sets it explicitly.
            $conn->exec("
                CREATE TABLE IF NOT EXISTS role_module_access (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    role_id INT NOT NULL,
                    module_key VARCHAR(64) NOT NULL,
                    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                    updated_by INT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_role_module (role_id, module_key),
                    INDEX idx_module_key (module_key),
                    INDEX idx_role_id (role_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $isAvailable = true;
        } catch (Throwable $createError) {
            error_log("role_module_access unavailable: " . $createError->getMessage());
            $isAvailable = false;
            return false;
        }
    }

    try {
        $roles = getRoleDefinitions();
        $modules = getModuleRegistry();

        // Seed defaults only when some role/module pair is missing (first run, or a new module or role).
        if ($existingRows >= count($roles) * count($modules)) {
            return true;
        }

        $stmt = $conn->prepare("
            INSERT INTO role_module_access (role_id, module_key, is_enabled, updated_by, updated_at)
            VALUES (?, ?, ?, NULL, NOW())
            ON DUPLICATE KEY UPDATE module_key = module_key
        ");

        // One commit for the whole seed instead of one per row.
        $ownsTransaction = !$conn->inTransaction() && $conn->beginTransaction();
        foreach ($roles as $role) {
            $roleId = (int)$role['role_id'];
            $roleKey = $role['role_key'] ?? '';
            $defaults = getDefaultModuleAccessByRoleKey($roleKey);

            foreach ($modules as $moduleKey => $_moduleMeta) {
                $defaultEnabled = !empty($defaults[$moduleKey]) ? 1 : 0;
                $stmt->execute([$roleId, $moduleKey, $defaultEnabled]);
            }
        }
        if ($ownsTransaction) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if (!empty($ownsTransaction) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Unable to seed role_module_access defaults: " . $e->getMessage());
    }

    return true;
}

function getRoleIdFromUserData($userData) {
    global $conn;

    $roleId = isset($userData['role_id']) ? (int)$userData['role_id'] : 0;
    if ($roleId > 0) {
        return $roleId;
    }

    $userId = isset($userData['id']) ? (int)$userData['id'] : 0;
    if ($userId > 0 && $conn) {
        try {
            $stmt = $conn->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['role_id'])) {
                $resolvedRoleId = (int)$row['role_id'];
                if ($resolvedRoleId > 0) {
                    return $resolvedRoleId;
                }
            }
        } catch (Throwable $e) {
            error_log("Error resolving user role_id: " . $e->getMessage());
        }
    }

    $roleKey = strtolower(trim((string)($userData['role'] ?? '')));
    if (isSuperAdminRoleKey($roleKey)) {
        return 1;
    }
    if (isAdministratorRoleKey($roleKey)) {
        return 2;
    }
    if (isPharmacistRoleKey($roleKey)) {
        return 7;
    }
    if ($roleKey === 'coa') {
        return 8;
    }
    if ($roleKey === 'accounting') {
        return 9;
    }
    if (isPermitStaffRoleKey($roleKey)) {
        return 10;
    }

    return 0;
}

/**
 * Resolve module access for a specific role and module.
 */
function getRoleModuleAccess($roleId, $roleKey, $moduleKey) {
    global $conn;
    static $cache = [];

    $moduleKey = normalizeModuleKey($moduleKey);
    $modules = getModuleRegistry();
    if (!array_key_exists($moduleKey, $modules)) {
        return false;
    }

    if (isSuperAdminRoleKey($roleKey)) {
        return true;
    }

    if (isSuperAdminOnlyModule($moduleKey)) {
        return false;
    }

    $defaults = getDefaultModuleAccessByRoleKey($roleKey);
    $fallback = !empty($defaults[$moduleKey]);

    $roleId = (int)$roleId;
    if ($roleId <= 0 || !$conn || !ensureRoleModuleAccessTable()) {
        return $fallback;
    }

    $cacheKey = $roleId . '|' . $moduleKey;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $conn->prepare("
            SELECT is_enabled
            FROM role_module_access
            WHERE role_id = ? AND module_key = ?
            LIMIT 1
        ");
        $stmt->execute([$roleId, $moduleKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && array_key_exists('is_enabled', $row)) {
            $cache[$cacheKey] = ((int)$row['is_enabled']) === 1;
            return $cache[$cacheKey];
        }
    } catch (Throwable $e) {
        error_log("Error reading role module access: " . $e->getMessage());
    }

    $cache[$cacheKey] = $fallback;
    return $cache[$cacheKey];
}

function chisDbUserHasModuleAccess($userData, $moduleKey) {
    $moduleKey = normalizeModuleKey($moduleKey);
    $userId = isset($userData['id']) ? (int)$userData['id'] : 0;
    $roleKey = strtolower(trim((string)($userData['role'] ?? 'user')));
    $roleId = getRoleIdFromUserData($userData);

    if (isSuperAdminRoleKey($roleKey)) {
        return true;
    }

    if (isSuperAdminOnlyModule($moduleKey)) {
        return false;
    }

    if ($moduleKey === 'subsystem_emedtrack') {
        return userHasSubsystemAccess($userData, 'emedtrack');
    }

    if ($moduleKey === 'subsystem_laboratory') {
        return userHasSubsystemAccess($userData, 'laboratory');
    }

    if (isModuleBlockedForPermitsAccount($userData, $moduleKey)) {
        return false;
    }

    if ($userId > 0) {
        $override = getUserModuleOverrideAccess($userId, $moduleKey);
        if ($override !== null) {
            return $override;
        }
    }

    return getRoleModuleAccess($roleId, $roleKey, $moduleKey);
}

if (!function_exists('userHasModuleAccess')) {
    function userHasModuleAccess($userData, $moduleKey) {
        return chisDbUserHasModuleAccess($userData, $moduleKey);
    }
}

function getRoleModuleAccessMatrix() {
    global $conn;

    $roles = getRoleDefinitions();
    $modules = getModuleRegistry();
    $matrix = [];

    foreach ($roles as $role) {
        $roleId = (int)$role['role_id'];
        $roleKey = $role['role_key'] ?? '';
        $defaults = getDefaultModuleAccessByRoleKey($roleKey);

        $matrix[$roleId] = [];
        foreach ($modules as $moduleKey => $_moduleMeta) {
            $matrix[$roleId][$moduleKey] = !empty($defaults[$moduleKey]);
        }
    }

    if ($conn && ensureRoleModuleAccessTable()) {
        try {
            $stmt = $conn->query("
                SELECT role_id, module_key, is_enabled
                FROM role_module_access
            ");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                $roleId = isset($row['role_id']) ? (int)$row['role_id'] : 0;
                $moduleKey = normalizeModuleKey($row['module_key'] ?? '');
                if ($roleId <= 0 || !array_key_exists($moduleKey, $modules)) {
                    continue;
                }
                if (!isset($matrix[$roleId])) {
                    $matrix[$roleId] = [];
                }
                $matrix[$roleId][$moduleKey] = ((int)($row['is_enabled'] ?? 0) === 1);
            }
        } catch (Throwable $e) {
            error_log("Error loading role module access matrix: " . $e->getMessage());
        }
    }

    $moduleList = [];
    foreach ($modules as $moduleKey => $meta) {
        $moduleList[] = [
            'module_key' => $moduleKey,
            'label' => $meta['label'],
            'description' => $meta['description'],
            'page' => $meta['page'],
            'subsystem' => $meta['subsystem'] ?? ''
        ];
    }

    $roleKeysById = [];
    foreach ($roles as $index => $role) {
        $roleKey = $role['role_key'] ?? '';
        $roleKeysById[(int)$role['role_id']] = $roleKey;
        $roles[$index]['read_only'] = isReadOnlyViewerRoleKey($roleKey);
        $roles[$index]['access_note'] = getRoleAccessDescription($roleKey);
    }

    // User Management can only ever be on for Super Admin, whatever is stored.
    foreach ($matrix as $roleId => $_moduleMap) {
        foreach (array_keys($modules) as $moduleKey) {
            if (isSuperAdminOnlyModule($moduleKey)) {
                $matrix[$roleId][$moduleKey] = isSuperAdminRoleKey($roleKeysById[(int)$roleId] ?? '');
            }
        }
    }

    return [
        'roles' => $roles,
        'modules' => $moduleList,
        'access' => $matrix
    ];
}

/**
 * Plain-language access level shown next to each role in User Management.
 */
function getRoleAccessDescription($roleKey) {
    if (isSuperAdminRoleKey($roleKey)) {
        return 'Full access, including User Management.';
    }
    if (isAdministratorRoleKey($roleKey)) {
        return 'Full access to every subsystem and location, except User Management.';
    }
    if (isReadOnlyViewerRoleKey($roleKey)) {
        return 'Read-only: can view records but cannot add, edit, release, or delete.';
    }
    if (isPharmacistRoleKey($roleKey)) {
        return 'Dispensing and patient medication history.';
    }
    if (isPermitStaffRoleKey($roleKey)) {
        return 'Encodes and updates Sanitary Permits and Health Certificates only. No EMedTrack, Laboratory, or User Management.';
    }
    return '';
}

function setRoleModuleAccess($roleId, $moduleKey, $isEnabled, $updatedBy = null) {
    global $conn;

    if (!$conn) {
        return false;
    }

    $roleId = (int)$roleId;
    if ($roleId <= 0) {
        return false;
    }

    $moduleKey = normalizeModuleKey($moduleKey);
    $modules = getModuleRegistry();
    if (!array_key_exists($moduleKey, $modules)) {
        return false;
    }

    if (!ensureRoleModuleAccessTable()) {
        return false;
    }

    $roleLookup = getRoleLookupById();
    if (!isset($roleLookup[$roleId])) {
        // Only allow updates for canonical system roles (1, 2, 7).
        return false;
    }
    $roleKey = isset($roleLookup[$roleId]['role_key']) ? $roleLookup[$roleId]['role_key'] : '';

    // Keep super admin full access to avoid locking out administrative recovery.
    $isEnabled = isSuperAdminRoleKey($roleKey) ? 1 : ($isEnabled ? 1 : 0);
    if (!isSuperAdminRoleKey($roleKey) && isSuperAdminOnlyModule($moduleKey)) {
        $isEnabled = 0;
    }
    $updatedBy = $updatedBy !== null ? (int)$updatedBy : null;

    try {
        $stmt = $conn->prepare("
            INSERT INTO role_module_access (role_id, module_key, is_enabled, updated_by, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        return $stmt->execute([$roleId, $moduleKey, $isEnabled, $updatedBy]);
    } catch (Throwable $e) {
        error_log("Error saving role module access: " . $e->getMessage());
        return false;
    }
}

function ensureUserModuleAccessTable() {
    global $conn;
    static $initialized = false;
    static $isAvailable = false;

    if ($initialized) {
        return $isAvailable;
    }
    $initialized = true;

    if (!$conn) {
        return false;
    }

    // Normal case: the table exists, so one cheap read replaces CREATE TABLE on every request.
    try {
        $conn->query("SELECT 1 FROM user_module_access LIMIT 1");
        $isAvailable = true;
    } catch (Throwable $e) {
        try {
            // updated_at has no DEFAULT CURRENT_TIMESTAMP (MySQL 5.5); setUserModuleAccess sets it.
            $conn->exec("
                CREATE TABLE IF NOT EXISTS user_module_access (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    module_key VARCHAR(64) NOT NULL,
                    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                    updated_by INT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_user_module (user_id, module_key),
                    INDEX idx_user_module_key (module_key),
                    INDEX idx_user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $isAvailable = true;
        } catch (Throwable $createError) {
            error_log("user_module_access unavailable: " . $createError->getMessage());
            $isAvailable = false;
            return false;
        }
    }

    return true;
}

function getManagedUsersForAccess() {
    global $conn;

    if (!$conn) {
        return [];
    }

    $canonicalRoleIds = getCanonicalRoleIds();
    if (empty($canonicalRoleIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($canonicalRoleIds), '?'));

    try {
        ensureUsersSubsystemAccessColumn();
        $stmt = $conn->prepare("
            SELECT
                u.user_id,
                u.user_name,
                u.role_id,
                r.role AS role_name,
                u.Location AS location,
                u.work_area,
                u.subsystem_access,
                u.f_name,
                u.m_name,
                u.l_name
            FROM users u
            LEFT JOIN table_role r ON r.role_id = u.role_id
            WHERE u.role_id IN ({$placeholders})
            ORDER BY u.user_name ASC
        ");
        $stmt->execute($canonicalRoleIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Error loading users for module access: " . $e->getMessage());
        return [];
    }

    $users = [];
    foreach ($rows as $row) {
        $roleId = isset($row['role_id']) ? (int)$row['role_id'] : 0;
        if ($roleId <= 0) {
            continue;
        }

        $roleName = trim((string)($row['role_name'] ?? ''));
        $users[] = [
            'user_id' => (int)($row['user_id'] ?? 0),
            'user_name' => (string)($row['user_name'] ?? ''),
            'role_id' => $roleId,
            'role_name' => $roleName !== '' ? $roleName : ('Role ' . $roleId),
            'role_key' => normalizeRoleKey($roleId, $roleName),
            'location' => (string)($row['location'] ?? ''),
            'work_area' => (string)($row['work_area'] ?? ''),
            'subsystem_access' => normalizeSubsystemAccess($row['subsystem_access'] ?? 'emedtrack'),
            'f_name' => (string)($row['f_name'] ?? ''),
            'm_name' => (string)($row['m_name'] ?? ''),
            'l_name' => (string)($row['l_name'] ?? '')
        ];
    }

    return $users;
}

function &getUserModuleOverrideCache() {
    static $cache = [];
    return $cache;
}

function getUserModuleOverrideMap($userId) {
    global $conn;
    $cache = &getUserModuleOverrideCache();

    $userId = (int)$userId;
    if ($userId <= 0) {
        return [];
    }

    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $cache[$userId] = [];
    if (!$conn || !ensureUserModuleAccessTable()) {
        return $cache[$userId];
    }

    try {
        $stmt = $conn->prepare("
            SELECT module_key, is_enabled
            FROM user_module_access
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $modules = getModuleRegistry();

        foreach ($rows as $row) {
            $moduleKey = normalizeModuleKey($row['module_key'] ?? '');
            if (!array_key_exists($moduleKey, $modules)) {
                continue;
            }
            $cache[$userId][$moduleKey] = ((int)($row['is_enabled'] ?? 0) === 1);
        }
    } catch (Throwable $e) {
        error_log("Error loading user module override map: " . $e->getMessage());
    }

    return $cache[$userId];
}

function getUserModuleOverrideAccess($userId, $moduleKey) {
    $moduleKey = normalizeModuleKey($moduleKey);
    if ($moduleKey === '') {
        return null;
    }

    $map = getUserModuleOverrideMap($userId);
    if (array_key_exists($moduleKey, $map)) {
        return (bool)$map[$moduleKey];
    }
    return null;
}

function setUserModuleAccess($userId, $moduleKey, $isEnabled, $updatedBy = null) {
    global $conn;
    $cache = &getUserModuleOverrideCache();

    if (!$conn) {
        return false;
    }

    $userId = (int)$userId;
    if ($userId <= 0) {
        return false;
    }

    $moduleKey = normalizeModuleKey($moduleKey);
    $modules = getModuleRegistry();
    if (!array_key_exists($moduleKey, $modules)) {
        return false;
    }

    if (!ensureUserModuleAccessTable()) {
        return false;
    }

    if ($moduleKey === 'subsystem_emedtrack' || $moduleKey === 'subsystem_laboratory') {
        if (!ensureUsersSubsystemAccessColumn()) {
            return false;
        }

        try {
            $stmt = $conn->prepare("SELECT subsystem_access FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $currentAccess = normalizeSubsystemAccess($stmt->fetchColumn());
            $targetAccess = $moduleKey === 'subsystem_emedtrack' ? 'emedtrack' : 'laboratory';

            if ($isEnabled) {
                $newAccess = ($currentAccess === 'all' || $currentAccess === $targetAccess)
                    ? $currentAccess
                    : (($currentAccess === 'emedtrack' || $currentAccess === 'laboratory') ? 'all' : $targetAccess);
            } else {
                if ($currentAccess === 'all') {
                    $newAccess = $targetAccess === 'emedtrack' ? 'laboratory' : 'emedtrack';
                } elseif ($currentAccess === $targetAccess) {
                    $newAccess = 'emedtrack';
                } else {
                    $newAccess = $currentAccess;
                }
            }

            $updateStmt = $conn->prepare("UPDATE users SET subsystem_access = ? WHERE user_id = ?");
            $saved = $updateStmt->execute([$newAccess, $userId]);
            if ($saved && array_key_exists($userId, $cache)) {
                unset($cache[$userId]);
            }
            return $saved;
        } catch (Throwable $e) {
            error_log("Error saving user subsystem access: " . $e->getMessage());
            return false;
        }
    }

    try {
        $userStmt = $conn->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Error loading target user for module access update: " . $e->getMessage());
        return false;
    }

    if (!$userRow || !isset($userRow['role_id'])) {
        return false;
    }

    $roleId = (int)$userRow['role_id'];
    if (!in_array($roleId, getCanonicalRoleIds(), true)) {
        return false;
    }

    $roleKey = normalizeRoleKey($roleId, '');
    // Keep super admin full access to avoid lockout.
    $isEnabled = isSuperAdminRoleKey($roleKey) ? 1 : ($isEnabled ? 1 : 0);
    if (!isSuperAdminRoleKey($roleKey) && isSuperAdminOnlyModule($moduleKey)) {
        $isEnabled = 0;
    }
    $updatedBy = $updatedBy !== null ? (int)$updatedBy : null;

    try {
        $stmt = $conn->prepare("
            INSERT INTO user_module_access (user_id, module_key, is_enabled, updated_by, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        $saved = $stmt->execute([$userId, $moduleKey, $isEnabled, $updatedBy]);
        if ($saved && array_key_exists($userId, $cache)) {
            unset($cache[$userId]);
        }
        return $saved;
    } catch (Throwable $e) {
        error_log("Error saving user module access: " . $e->getMessage());
        return false;
    }
}

function getUserModuleAccessMatrix($roleMatrix = null) {
    $modules = getModuleRegistry();
    $users = getManagedUsersForAccess();
    $effective = [];
    $overrides = [];

    if (!is_array($roleMatrix)) {
        $roleMatrixData = getRoleModuleAccessMatrix();
        $roleMatrix = $roleMatrixData['access'] ?? [];
    }

    foreach ($users as $user) {
        $userId = (int)($user['user_id'] ?? 0);
        $roleId = (int)($user['role_id'] ?? 0);
        $roleKey = strtolower(trim((string)($user['role_key'] ?? '')));
        $overrideMap = getUserModuleOverrideMap($userId);

        $effective[$userId] = [];
        $overrides[$userId] = [];

        foreach ($modules as $moduleKey => $_moduleMeta) {
            if (isSuperAdminOnlyModule($moduleKey)) {
                $effective[$userId][$moduleKey] = isSuperAdminRoleKey($roleKey);
                $overrides[$userId][$moduleKey] = $effective[$userId][$moduleKey];
                continue;
            }

            if ($moduleKey === 'subsystem_emedtrack') {
                $effective[$userId][$moduleKey] = userHasSubsystemAccess($user, 'emedtrack');
                $overrides[$userId][$moduleKey] = $effective[$userId][$moduleKey];
                continue;
            }

            if ($moduleKey === 'subsystem_laboratory') {
                $effective[$userId][$moduleKey] = userHasSubsystemAccess($user, 'laboratory');
                $overrides[$userId][$moduleKey] = $effective[$userId][$moduleKey];
                continue;
            }

            if (isModuleBlockedForPermitsAccount($user, $moduleKey)) {
                $effective[$userId][$moduleKey] = false;
                $overrides[$userId][$moduleKey] = false;
                continue;
            }

            if (array_key_exists($moduleKey, $overrideMap)) {
                $effective[$userId][$moduleKey] = (bool)$overrideMap[$moduleKey];
                $overrides[$userId][$moduleKey] = (bool)$overrideMap[$moduleKey];
                continue;
            }

            if (isset($roleMatrix[$roleId]) && array_key_exists($moduleKey, $roleMatrix[$roleId])) {
                $effective[$userId][$moduleKey] = (bool)$roleMatrix[$roleId][$moduleKey];
            } else {
                $effective[$userId][$moduleKey] = getRoleModuleAccess($roleId, $roleKey, $moduleKey);
            }
        }
    }

    return [
        'users' => $users,
        'user_access' => $effective,
        'user_overrides' => $overrides
    ];
}

/**
 * Require authentication - returns user data or exits with error
 */
if (!function_exists('requireAuth')) {
    function requireAuth() {
        $user = validateToken();
    
        if (!$user) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Authentication required'
            ]);
            exit;
        }
    
        return $user;
    }
}

/**
 * Clean up expired tokens (should be called by cron job)
 */
function cleanupExpiredTokens() {
    global $conn;

    try {
        $conn->beginTransaction();

        // Mark expired access tokens as inactive
        $conn->exec("
            UPDATE access_tokens
            SET is_active = FALSE
            WHERE expires_at < NOW() AND is_active = TRUE
        ");

        // Mark expired refresh tokens as revoked
        $conn->exec("
            UPDATE refresh_tokens
            SET is_revoked = TRUE
            WHERE expires_at < NOW() AND is_revoked = FALSE
        ");

        // Move expired tokens to revoked table for audit trail
        $conn->exec("
            INSERT INTO revoked_tokens (token, token_type, user_id, reason)
            SELECT token, 'access', user_id, 'expired'
            FROM access_tokens
            WHERE expires_at < NOW() AND is_active = FALSE
        ");

        $conn->exec("
            INSERT INTO revoked_tokens (token, token_type, user_id, reason)
            SELECT refresh_token, 'refresh', user_id, 'expired'
            FROM refresh_tokens
            WHERE expires_at < NOW() AND is_revoked = FALSE
        ");

        // Delete old revoked tokens (keep last 30 days)
        $conn->exec("
            DELETE FROM revoked_tokens
            WHERE revoked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");

        $conn->commit();
        error_log("Cleaned up expired tokens");
        return true;

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error cleaning up expired tokens: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user tokens for management (admin function)
 */
function getUserTokens($userId) {
    global $conn;

    try {
        $stmt = $conn->prepare("
            SELECT
                'access' as type,
                id,
                username,
                created_at,
                expires_at,
                last_accessed,
                is_active,
                ip_address,
                user_agent
            FROM access_tokens
            WHERE user_id = ?

            UNION ALL

            SELECT
                'refresh' as type,
                id,
                username,
                created_at,
                expires_at,
                NULL as last_accessed,
                CASE WHEN is_revoked = FALSE AND is_used = FALSE THEN 1 ELSE 0 END as is_active,
                ip_address,
                user_agent
            FROM refresh_tokens
            WHERE user_id = ?

            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll();

    } catch (Exception $e) {
        error_log("Error getting user tokens: " . $e->getMessage());
        return [];
    }
}
?>
