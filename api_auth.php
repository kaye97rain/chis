<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

// Include database authentication functions
require_once 'db_auth.php';

function normalizeRequestedSubsystem($subsystem) {
    return strtolower(trim((string)$subsystem));
}

function getSubsystemAccessConfig() {
    return [
        'emedtrack' => [
            'label' => 'EMedTrack',
            'module_key' => 'subsystem_emedtrack'
        ],
        'laboratory' => [
            'label' => 'Laboratory',
            'module_key' => 'subsystem_laboratory'
        ],
        'sanitary_permit' => [
            'label' => 'Sanitary Permit',
            'module_key' => 'subsystem_sanitary_permit'
        ],
        'health_certificate' => [
            'label' => 'Health Certificate',
            'module_key' => 'subsystem_health_certificate'
        ]
    ];
}

// Failed-login throttling: per username+IP, plus a wider per-IP limit.
define('LOGIN_THROTTLE_WINDOW_MINUTES', 15);
define('LOGIN_MAX_FAILURES_PER_USER_IP', 5);
define('LOGIN_MAX_FAILURES_PER_IP', 30);

function ensureLoginAttemptsTable($conn) {
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_login_attempts (
            attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL,
            PRIMARY KEY (attempt_id),
            KEY idx_login_attempts_user_ip (username, ip_address, attempted_at),
            KEY idx_login_attempts_ip (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Throwable $e) {
        error_log('Unable to ensure tbl_login_attempts: ' . $e->getMessage());
        return false;
    }
}

/**
 * Minutes until this username/IP may try again, or 0 when not locked.
 */
function getLoginLockoutMinutes($conn, $username, $ipAddress) {
    $window = (int)LOGIN_THROTTLE_WINDOW_MINUTES;
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN username = :username THEN 1 ELSE 0 END) AS user_failures,
            COUNT(*) AS ip_failures,
            MIN(CASE WHEN username = :username2 THEN attempted_at END) AS user_oldest,
            MIN(attempted_at) AS ip_oldest
        FROM tbl_login_attempts
        WHERE ip_address = :ip AND attempted_at > NOW() - INTERVAL {$window} MINUTE
    ");
    $stmt->execute([':username' => $username, ':username2' => $username, ':ip' => $ipAddress]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $oldest = null;
    if ((int)($row['user_failures'] ?? 0) >= LOGIN_MAX_FAILURES_PER_USER_IP) {
        $oldest = $row['user_oldest'];
    } elseif ((int)($row['ip_failures'] ?? 0) >= LOGIN_MAX_FAILURES_PER_IP) {
        $oldest = $row['ip_oldest'];
    }
    if ($oldest === null) {
        return 0;
    }
    $unlockAt = strtotime((string)$oldest) + $window * 60;
    return max(1, (int)ceil(($unlockAt - time()) / 60));
}

function recordLoginFailure($conn, $username, $ipAddress) {
    $conn->prepare("INSERT INTO tbl_login_attempts (username, ip_address, attempted_at) VALUES (?, ?, NOW())")
        ->execute([$username, $ipAddress]);
    // Keep the table small.
    $conn->exec("DELETE FROM tbl_login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
}

function clearLoginFailures($conn, $username, $ipAddress) {
    $conn->prepare("DELETE FROM tbl_login_attempts WHERE username = ? AND ip_address = ?")
        ->execute([$username, $ipAddress]);
}

if (!isset($conn) || !$conn instanceof PDO) {
    error_log("API AUTH: database connection is not available");
}


// Login; POST ?action=refresh is handled by the refresh branch below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') !== 'refresh') {
    $rawInput = file_get_contents("php://input");

    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("API AUTH: JSON decode error: " . json_last_error_msg());
        echo json_encode([
            "status" => "error",
            "message" => "Invalid JSON format."
        ]);         
        exit;
    }

    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $requestedSubsystem = normalizeRequestedSubsystem($data['subsystem'] ?? '');
    $subsystemConfig = getSubsystemAccessConfig();

    if ($requestedSubsystem !== '' && !isset($subsystemConfig[$requestedSubsystem])) {
        echo json_encode([
            "status" => "error",
            "message" => "Invalid subsystem selected."
        ]);
        exit;
    }

    // Validate input
    if (empty($username) || empty($password)) {
        echo json_encode([
            "status" => "error",
            "message" => "Username and password are required."
        ]);
        exit;
    }

    // Database authentication required
    if (!$conn) {
        echo json_encode([
            "status" => "error",
            "message" => "Database connection not available."
        ]);
        exit;
    }

    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $throttleAvailable = ensureLoginAttemptsTable($conn);
    if ($throttleAvailable) {
        $lockMinutes = getLoginLockoutMinutes($conn, $username, $clientIp);
        if ($lockMinutes > 0) {
            chisLogAccessEvent('login_failed', $username, null, null, 'locked_out');
            http_response_code(429);
            echo json_encode([
                "status" => "error",
                "message" => "Too many failed sign-in attempts. Try again in {$lockMinutes} minute(s)."
            ]);
            exit;
        }
    }

    try {
        // Get user from database
        $hasSubsystemAccessColumn = ensureUsersSubsystemAccessColumn();
        $subsystemAccessSelect = $hasSubsystemAccessColumn
            ? "u.subsystem_access"
            : "'emedtrack'";

        $stmt = $conn->prepare("
            SELECT
                u.user_id as ID,
                u.user_name,
                u.password,
                u.role_id,
                r.role as role_name,
                u.Location as location,
                u.work_area,
                {$subsystemAccessSelect} as subsystem_access
            FROM users u
            LEFT JOIN table_role r ON r.role_id = u.role_id
            WHERE u.user_name = :username
        ");
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        // Older data has a few usernames shared by two accounts, so check every match.
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $user = $candidates[0] ?? false;

        // Try the password as typed, then without surrounding spaces (the old login page trimmed it).
        $passwordForms = [$password];
        if (trim($password) !== $password && trim($password) !== '') {
            $passwordForms[] = trim($password);
        }

        $passwordValid = false;
        $legacyMatch = null;
        foreach ($candidates as $candidate) {
            $stored = (string)$candidate['password'];
            foreach ($passwordForms as $form) {
                if ($stored !== '' && password_verify($form, $stored)) {
                    $passwordValid = true;
                } elseif ($stored !== '' && (hash_equals($stored, md5($form)) || hash_equals($stored, $form))) {
                    // Legacy MD5 or plain text password.
                    $passwordValid = true;
                    $legacyMatch = $form;
                }
                if ($passwordValid) {
                    $user = $candidate;
                    break 2;
                }
            }
        }

        if ($user) {
            // Legacy MD5 or plain text passwords: accept once, then upgrade to bcrypt
            // so the old format disappears as users log in.
            if ($passwordValid && $legacyMatch !== null) {
                try {
                    $newHash = password_hash($legacyMatch, PASSWORD_DEFAULT);
                    // Only upgrade when the column can hold the full hash; a truncated hash would lock the user out.
                    $lenStmt = $conn->query("
                        SELECT CHARACTER_MAXIMUM_LENGTH
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password'
                    ");
                    $maxLength = (int)$lenStmt->fetchColumn();
                    if ($maxLength >= strlen($newHash)) {
                        $upgradeStmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                        $upgradeStmt->execute([$newHash, $user['ID']]);
                    }
                } catch (Exception $e) {
                    error_log("Unable to upgrade legacy password hash for user ID " . $user['ID'] . ": " . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        error_log("Database login error: " . $e->getMessage());
        echo json_encode([
            "status" => "error",
            "message" => "Database error occurred."
        ]);
        exit;
    }

    if ($throttleAvailable) {
        try {
            if ($passwordValid && $user) {
                clearLoginFailures($conn, $username, $clientIp);
            } else {
                recordLoginFailure($conn, $username, $clientIp);
            }
        } catch (Throwable $e) {
            error_log('Login throttle bookkeeping failed: ' . $e->getMessage());
        }
    }

    if ($passwordValid && $user) {
        $roleKey = normalizeRoleKey($user['role_id'] ?? null, $user['role_name'] ?? '');

        if ($requestedSubsystem !== '') {
            $accessMeta = $subsystemConfig[$requestedSubsystem];
            $accessCheckUser = [
                'id' => (int)$user['ID'],
                'role' => $roleKey,
                'role_id' => (int)($user['role_id'] ?? 0),
                'subsystem_access' => $user['subsystem_access'] ?? 'emedtrack'
            ];

            if (!userHasModuleAccess($accessCheckUser, $accessMeta['module_key'])) {
                chisLogAccessEvent('login_failed', $user['user_name'], (int)$user['ID'], $roleKey, 'subsystem_denied:' . $requestedSubsystem);
                echo json_encode([
                    "status" => "error",
                    "message" => "Your account is not allowed to access " . $accessMeta['label'] . "."
                ]);
                exit;
            }
        }

        // Use the new secure token rotation system
        $tokens = storeUserSession($user['ID'], $user['user_name']);

        if (!$tokens) {
            error_log("Error storing tokens in secure database");
            echo json_encode([
                "status" => "error",
                "message" => "Error creating secure authentication session."
            ]);
            exit;
        }

        // Prepare user data for response
        $userData = [
            'id' => $user['ID'],
            'username' => $user['user_name'],
            'role' => $roleKey,
            'location' => $user['location'] ?? null,
            'work_area' => $user['work_area'] ?? null,
            'subsystem_access' => $user['subsystem_access'] ?? 'emedtrack',
            'login_time' => time()
        ];

        chisLogAccessEvent('login', $user['user_name'], (int)$user['ID'], $roleKey);

        echo json_encode([
            "status" => "success",
            "message" => "Login successful.",
            "token" => $tokens['access_token'],
            "refresh_token" => $tokens['refresh_token'],
            "expires_in" => $tokens['expires_in'],
            "subsystem" => $requestedSubsystem,
            "user" => $userData
        ]);
    } else {
        chisLogAccessEvent('login_failed', $username, $user ? (int)$user['ID'] : null, null, $user ? 'invalid_password' : 'unknown_username');
        echo json_encode([
            "status" => "error",
            "message" => "Invalid username or password."
        ]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if user is authenticated via token
    $user = validateToken();

    if ($user) {
        echo json_encode([
            "status" => "success",
            "message" => "User authenticated.",
            "user" => $user
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Not authenticated."
        ]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'refresh') {
    // Same as HRIS: a session lasts a fixed 24 hours from sign-in and cannot be extended.
    http_response_code(410);
    echo json_encode([
        "status" => "error",
        "message" => "Sessions cannot be refreshed. Please sign in again."
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method."
    ]);
}
?>
