<?php
/**
 * pds_db.php — Dedicated PDO connection for the local cgmhris database.
 *
 * Reads PDS_DB_* vars from .env; falls back to sensible localhost defaults
 * so the PDS module works in dev without extra config.
 *
 * Exposes: $pds_conn (PDO), pds_ok(), pds_err()
 */

if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $name  = trim($name);
            $value = trim($value);
            if ($name && !array_key_exists($name, $_ENV)) {
                putenv("$name=$value");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }
}

loadEnv(__DIR__ . '/.env');

$_pds_host   = getenv('PDS_DB_HOST') ?: 'localhost';
$_pds_db     = getenv('PDS_DB_NAME') ?: 'cgmhris';
$_pds_user   = getenv('PDS_DB_USER') ?: 'root';
$_pds_pass   = getenv('PDS_DB_PASS') ?: '';

try {
    $pds_conn = new PDO(
        "mysql:host=$_pds_host;dbname=$_pds_db;charset=utf8mb4",
        $_pds_user,
        $_pds_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pds_conn->exec("SET time_zone = '+08:00'");  // Philippine time
} catch (PDOException $e) {
    error_log('pds_db.php: ' . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'PDS database unavailable']);
    exit;
}

// ── Token helpers ──────────────────────────────────────────────────────────

function pds_ensure_workflow_columns(): void {
    global $pds_conn;
    static $checked = false;

    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pds_conn->query("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'pds_personal_info'
        ");
        $existing = array_flip(array_column($stmt->fetchAll(), 'COLUMN_NAME'));
        $indexStmt = $pds_conn->query("
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'pds_personal_info'
        ");
        $indexes = array_flip(array_column($indexStmt->fetchAll(), 'INDEX_NAME'));

        if (!$existing) {
            return;
        }

        $alterParts = [];

        if (!isset($existing['workflow_status'])) {
            $alterParts[] = "ADD COLUMN `workflow_status` ENUM('draft','submitted','verified') NOT NULL DEFAULT 'draft' AFTER `photo_path`";
        }
        if (!isset($existing['submitted_at'])) {
            $alterParts[] = "ADD COLUMN `submitted_at` DATETIME NULL AFTER `workflow_status`";
        }
        if (!isset($existing['submitted_by_name'])) {
            $alterParts[] = "ADD COLUMN `submitted_by_name` VARCHAR(120) NULL AFTER `submitted_at`";
        }
        if (!isset($existing['submitted_by_role'])) {
            $alterParts[] = "ADD COLUMN `submitted_by_role` ENUM('employee','admin') NULL AFTER `submitted_by_name`";
        }
        if (!isset($existing['verified_at'])) {
            $alterParts[] = "ADD COLUMN `verified_at` DATETIME NULL AFTER `submitted_by_role`";
        }
        if (!isset($existing['verified_by_name'])) {
            $alterParts[] = "ADD COLUMN `verified_by_name` VARCHAR(120) NULL AFTER `verified_at`";
        }
        if (!isset($existing['verified_by_role'])) {
            $alterParts[] = "ADD COLUMN `verified_by_role` ENUM('employee','admin') NULL AFTER `verified_by_name`";
        }
        if (!isset($indexes['idx_workflow_status'])) {
            $alterParts[] = "ADD INDEX `idx_workflow_status` (`workflow_status`)";
        }

        if ($alterParts) {
            $pds_conn->exec("ALTER TABLE `pds_personal_info` " . implode(', ', $alterParts));
        }
    } catch (Throwable $e) {
        error_log('pds_ensure_workflow_columns: ' . $e->getMessage());
    }
}

pds_ensure_workflow_columns();

function pds_ensure_office_table(): void {
    global $pds_conn;
    try {
        $pds_conn->exec("
            CREATE TABLE IF NOT EXISTS `pds_office` (
                `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `pds_id`      INT UNSIGNED NOT NULL,
                `office_name` VARCHAR(255) NOT NULL DEFAULT '',
                `division`    VARCHAR(255) NOT NULL DEFAULT '',
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_pds_office_pds_id` (`pds_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        error_log('pds_ensure_office_table: ' . $e->getMessage());
    }
}

pds_ensure_office_table();

define('PDS_SESSION_TTL', 3600 * 8);   // 8 hours
define('PDS_TOKEN_LEN',   64);

function pds_token(): string {
    return bin2hex(random_bytes(PDS_TOKEN_LEN / 2));
}

function pds_request_bearer_token(): ?string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $k => $v) {
        if (strcasecmp((string)$k, 'Authorization') === 0
            && preg_match('/Bearer\s+(.+)/i', (string)$v, $m)) {
            return trim((string)$m[1]);
        }
    }

    foreach ([
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['Authorization'] ?? null,
    ] as $candidate) {
        if (is_string($candidate) && preg_match('/Bearer\s+(.+)/i', $candidate, $m)) {
            return trim((string)$m[1]);
        }
    }

    return null;
}

/**
 * Validate a raw PDS bearer token.
 * Returns assoc array [id, id_number, bio_id, employee_no, full_name, role, pds_id]
 * or false.
 */
function pds_validate_token(): array|false {
    global $pds_conn;

    $candidates = [];

    // Authorization header
    $headerToken = pds_request_bearer_token();
    if ($headerToken !== null && $headerToken !== '') {
        $candidates[] = $headerToken;
    }

    // Cookie fallback
    if (!empty($_COOKIE['pds_token'])) {
        $candidates[] = $_COOKIE['pds_token'];
    }

    // GET fallback (for direct links)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !empty($_GET['pds_token'])) {
        $candidates[] = $_GET['pds_token'];
    }

    $candidates = array_values(array_unique(array_filter($candidates, fn($value) => is_string($value) && $value !== '')));
    if (!$candidates) return false;

    try {
        // Fetch all unexpired sessions and verify hash
        $stmt = $pds_conn->prepare("
            SELECT s.id AS session_id, s.token_hash, s.expires_at,
                   u.id, u.id_number, u.bio_id, u.employee_no, u.full_name, u.role, u.pds_id,
                   u.must_change_password, u.is_active
              FROM pds_sessions s
              JOIN pds_users u ON u.id = s.user_id
             WHERE s.expires_at > NOW()
               AND u.is_active = 1
        ");
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            foreach ($candidates as $raw) {
                if (password_verify($raw, $row['token_hash'])) {
                    return [
                        'id'                  => (int)$row['id'],
                        'id_number'           => $row['id_number'],
                        'bio_id'              => $row['bio_id'],
                        'employee_no'         => $row['employee_no'],
                        'full_name'           => $row['full_name'],
                        'role'                => $row['role'],
                        'pds_id'              => $row['pds_id'] ? (int)$row['pds_id'] : null,
                        'must_change_password'=> (bool)$row['must_change_password'],
                    ];
                }
            }
        }
        return false;
    } catch (Exception $e) {
        error_log('pds_validate_token: ' . $e->getMessage());
        return false;
    }
}

/**
 * Require PDS auth — exits with JSON 401 if not authenticated.
 */
function pds_require_auth(): array {
    $u = pds_validate_token();
    if (!$u) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'PDS authentication required']);
        exit;
    }
    return $u;
}
