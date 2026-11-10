<?php
/**
 * CHIS API for the Sanitary Permit and Health Certificate subsystems
 * (migrated from the standalone CHOIS app).
 *
 * GET  ?module=<sanitary_permit|health_certificate>&action=<stats|list|get|report|logs>
 * POST {"module": "...", "action": "save|delete", ...}
 *
 * Records live in the CHIS database: tbl_sanitarypermit, tbl_healthcertificate and
 * tbl_chois_activity_logs (DDL: sql_chois_tables.sql; data import from a CHOIS dump:
 * sql_chois_migrate.sql). Access rights: subsystem_sanitary_permit / subsystem_health_certificate.
 */
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/db_auth.php';

define('CHOIS_LOG_TABLE', 'tbl_chois_activity_logs');

function sendResponse($status, $message, $data = null, $code = 200, $meta = null) {
    http_response_code($code);
    $payload = ['status' => $status, 'message' => $message];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    if ($meta !== null) {
        $payload['meta'] = $meta;
    }
    echo json_encode($payload);
    exit;
}

/**
 * Field rules per module. type: text | date | year | int | enum.
 * Column names match the existing CHOIS tables.
 */
function getChoisModules() {
    $classification = ['FOOD', 'NON-FOOD'];

    return [
        'sanitary_permit' => [
            'module_key' => 'subsystem_sanitary_permit',
            'label' => 'Sanitary Permit',
            'table' => 'tbl_sanitarypermit',
            'number_field' => 'Permit_No',
            'number_label' => 'Permit No.',
            'date_field' => 'Date_Issued',
            'search' => ['Permit_No', 'Registered_Name', 'Type_of_Establishment', 'Barangay'],
            'fields' => [
                'Permit_No' => ['type' => 'text', 'max' => 50, 'required' => true, 'label' => 'Permit No.'],
                'Registered_Name' => ['type' => 'text', 'max' => 255, 'required' => true, 'label' => 'Registered name'],
                'Type_of_Establishment' => ['type' => 'text', 'max' => 255, 'required' => true, 'label' => 'Type of establishment'],
                'Classification' => ['type' => 'enum', 'values' => $classification, 'label' => 'Classification'],
                'Date_Issued' => ['type' => 'date', 'label' => 'Date issued'],
                'Year_Expiration' => ['type' => 'year', 'label' => 'Year of expiration'],
                'Purok' => ['type' => 'text', 'max' => 50, 'label' => 'Purok'],
                'Barangay' => ['type' => 'text', 'max' => 100, 'label' => 'Barangay'],
                'City' => ['type' => 'text', 'max' => 100, 'label' => 'City'],
            ],
        ],
        'health_certificate' => [
            'module_key' => 'subsystem_health_certificate',
            'label' => 'Health Certificate',
            'table' => 'tbl_healthcertificate',
            'number_field' => 'Registration_Number',
            'number_label' => 'Registration No.',
            'date_field' => 'Date_of_Issuance',
            'search' => ['Registration_Number', 'Lastname', 'Firstname', 'Occupation', 'Place_of_work', 'CTC_Number'],
            'fields' => [
                'Registration_Number' => ['type' => 'text', 'max' => 50, 'required' => true, 'label' => 'Registration No.'],
                'Lastname' => ['type' => 'text', 'max' => 100, 'required' => true, 'label' => 'Last name'],
                'Firstname' => ['type' => 'text', 'max' => 100, 'required' => true, 'label' => 'First name'],
                'MI' => ['type' => 'text', 'max' => 10, 'label' => 'Middle initial'],
                'Age' => ['type' => 'int', 'min' => 0, 'max' => 130, 'label' => 'Age'],
                'Sex' => ['type' => 'enum', 'values' => ['Male', 'Female'], 'label' => 'Sex'],
                'Nationality' => ['type' => 'text', 'max' => 100, 'label' => 'Nationality'],
                'Occupation' => ['type' => 'text', 'max' => 255, 'required' => true, 'label' => 'Occupation'],
                'Place_of_work' => ['type' => 'text', 'max' => 255, 'label' => 'Place of work'],
                'Classification' => ['type' => 'enum', 'values' => $classification, 'label' => 'Classification'],
                'Date_of_Issuance' => ['type' => 'date', 'label' => 'Date of issuance'],
                'CTC_Number' => ['type' => 'text', 'max' => 100, 'label' => 'CTC No.'],
                'Purok' => ['type' => 'text', 'max' => 50, 'label' => 'Purok'],
                'Barangay' => ['type' => 'text', 'max' => 100, 'label' => 'Barangay'],
                'City' => ['type' => 'text', 'max' => 100, 'label' => 'City'],
            ],
        ],
    ];
}

function choisCanWrite($userData) {
    $roleKey = strtolower(trim((string)($userData['role'] ?? '')));
    return !isReadOnlyViewerRoleKey($roleKey);
}

/**
 * Returns [cleanRecord, errorMessage]. $existing is the stored row on update: a legacy
 * value outside an enum list (migrated CHOIS data) may be kept as-is, but not newly set.
 */
function choisValidateRecord(array $config, array $input, array $existing = []) {
    $clean = [];
    foreach ($config['fields'] as $column => $rule) {
        $raw = $input[$column] ?? '';
        $value = is_scalar($raw) ? trim((string)$raw) : '';
        $label = $rule['label'];

        if ($value === '') {
            if (!empty($rule['required'])) {
                return [null, "{$label} is required."];
            }
            $clean[$column] = null;
            continue;
        }

        switch ($rule['type']) {
            case 'date':
                $date = DateTime::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    return [null, "{$label} must be a valid date."];
                }
                break;
            case 'year':
                if (!preg_match('/^\d{4}$/', $value) || (int)$value < 1990 || (int)$value > 2100) {
                    return [null, "{$label} must be a 4-digit year."];
                }
                break;
            case 'int':
                if (!preg_match('/^\d+$/', $value) || (int)$value < $rule['min'] || (int)$value > $rule['max']) {
                    return [null, "{$label} must be a whole number from {$rule['min']} to {$rule['max']}."];
                }
                $value = (int)$value;
                break;
            case 'enum':
                $match = null;
                foreach ($rule['values'] as $allowed) {
                    if (strcasecmp($allowed, $value) === 0) {
                        $match = $allowed;
                        break;
                    }
                }
                if ($match === null && isset($existing[$column]) && trim((string)$existing[$column]) === $value) {
                    $match = $value;
                }
                if ($match === null) {
                    return [null, "{$label} must be one of: " . implode(', ', $rule['values']) . '.'];
                }
                $value = $match;
                break;
            default:
                if (mb_strlen($value) > $rule['max']) {
                    return [null, "{$label} must be {$rule['max']} characters or fewer."];
                }
        }

        $clean[$column] = $value;
    }

    return [$clean, null];
}

/** Health certificates expire Dec 31; ones issued in Nov/Dec run to the next year. */
function choisHealthCertExpiration($issuanceDate) {
    if (!$issuanceDate) {
        return null;
    }
    $time = strtotime((string)$issuanceDate);
    if ($time === false) {
        return null;
    }
    $year = (int)date('Y', $time) + ((int)date('n', $time) >= 11 ? 1 : 0);
    return $year . '-12-31';
}

function choisDecorateRow($moduleKey, array $row) {
    $address = [];
    if (!empty($row['Purok'])) {
        $address[] = 'Purok ' . $row['Purok'];
    }
    if (!empty($row['Barangay'])) {
        $address[] = $row['Barangay'];
    }
    if (!empty($row['City'])) {
        $address[] = $row['City'];
    }
    $row['Full_Address'] = implode(', ', $address);

    if ($moduleKey === 'health_certificate') {
        $name = trim((string)($row['Lastname'] ?? ''));
        $given = trim(trim((string)($row['Firstname'] ?? '')) . ' ' . trim((string)($row['MI'] ?? '')));
        $row['Full_Name'] = $given !== '' ? ($name !== '' ? "{$name}, {$given}" : $given) : $name;
        $row['Expiration_Date'] = choisHealthCertExpiration($row['Date_of_Issuance'] ?? null);
    }

    return $row;
}

/**
 * Create the tables on first use (same DDL as sql_chois_tables.sql). MySQL 5.5 safe:
 * one auto TIMESTAMP per table, utf8mb4 indexes kept under 767 bytes.
 * Permit/registration numbers are indexed but not UNIQUE, because legacy CHOIS data
 * may hold duplicates. The API refuses new duplicates instead.
 */
function choisEnsureTables(PDO $db) {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS tbl_sanitarypermit (
            ID INT NOT NULL AUTO_INCREMENT,
            Permit_No VARCHAR(50) NOT NULL,
            Registered_Name VARCHAR(255) NOT NULL,
            Type_of_Establishment VARCHAR(255) NOT NULL,
            Classification VARCHAR(100) NULL,
            Date_Issued DATE NULL,
            Year_Expiration VARCHAR(10) NULL,
            Purok VARCHAR(50) NULL,
            Barangay VARCHAR(100) NULL,
            City VARCHAR(100) NULL,
            isDeleted TINYINT(1) NOT NULL DEFAULT 0,
            Date_Updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (ID),
            KEY idx_sp_permit_no (Permit_No),
            KEY idx_sp_deleted_issued (isDeleted, Date_Issued),
            KEY idx_sp_classification (Classification),
            KEY idx_sp_barangay (Barangay)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS tbl_healthcertificate (
            ID INT NOT NULL AUTO_INCREMENT,
            Registration_Number VARCHAR(50) NOT NULL,
            Lastname VARCHAR(100) NOT NULL,
            Firstname VARCHAR(100) NOT NULL,
            MI VARCHAR(10) NULL,
            Age INT NULL,
            Sex VARCHAR(10) NULL,
            Nationality VARCHAR(100) NULL,
            Occupation VARCHAR(255) NOT NULL,
            Place_of_work VARCHAR(255) NULL,
            Classification VARCHAR(100) NULL,
            Date_of_Issuance DATE NULL,
            CTC_Number VARCHAR(100) NULL,
            Purok VARCHAR(50) NULL,
            Barangay VARCHAR(100) NULL,
            City VARCHAR(100) NULL,
            isDeleted TINYINT(1) NOT NULL DEFAULT 0,
            Date_Updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (ID),
            KEY idx_hc_registration (Registration_Number),
            KEY idx_hc_deleted_issued (isDeleted, Date_of_Issuance),
            KEY idx_hc_name (Lastname, Firstname),
            KEY idx_hc_classification (Classification),
            KEY idx_hc_barangay (Barangay)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS " . CHOIS_LOG_TABLE . " (
            ID INT NOT NULL AUTO_INCREMENT,
            Status VARCHAR(50) NOT NULL,
            Module VARCHAR(100) NOT NULL,
            Control_Number VARCHAR(100) NULL,
            Updated_by VARCHAR(100) NOT NULL,
            Date_Updated DATETIME NOT NULL,
            PRIMARY KEY (ID),
            KEY idx_chois_log_module_date (Module, Date_Updated),
            KEY idx_chois_log_control (Control_Number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to ensure CHOIS tables: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/** Module/Status values match what the legacy CHOIS app logged, so migrated history lines up. */
function choisLogActivity(PDO $db, $username, $action, $moduleLabel, $controlNumber) {
    try {
        $db->prepare("INSERT INTO " . CHOIS_LOG_TABLE . " (Status, Module, Control_Number, Updated_by, Date_Updated) VALUES (?, ?, ?, ?, NOW())")
            ->execute([$action, $moduleLabel, $controlNumber, $username]);
    } catch (Throwable $e) {
        error_log('CHOIS log write failed: ' . $e->getMessage());
    }
}

function choisBuildFilters(array $config, array $query) {
    $where = ['isDeleted = 0'];
    $params = [];

    $search = trim((string)($query['search'] ?? ''));
    if ($search !== '') {
        $likes = [];
        foreach ($config['search'] as $column) {
            $likes[] = "{$column} LIKE ?";
            $params[] = '%' . $search . '%';
        }
        $where[] = '(' . implode(' OR ', $likes) . ')';
    }

    $dateField = $config['date_field'];
    foreach (['start_date' => '>=', 'end_date' => '<='] as $key => $operator) {
        $value = trim((string)($query[$key] ?? ''));
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $where[] = "{$dateField} {$operator} ?";
            $params[] = $value;
        }
    }

    $classification = trim((string)($query['classification'] ?? ''));
    if ($classification !== '') {
        $where[] = 'Classification = ?';
        $params[] = $classification;
    }

    $barangay = trim((string)($query['barangay'] ?? ''));
    if ($barangay !== '') {
        $where[] = 'Barangay = ?';
        $params[] = $barangay;
    }

    return ['WHERE ' . implode(' AND ', $where), $params];
}

function choisFindDuplicate(PDO $db, array $config, $number, $excludeId) {
    $stmt = $db->prepare("SELECT ID, isDeleted FROM {$config['table']} WHERE {$config['number_field']} = ? AND ID <> ? LIMIT 1");
    $stmt->execute([$number, (int)$excludeId]);
    return $stmt->fetch() ?: null;
}

// ── Request handling ─────────────────────────────────────────────────────────

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = [];
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        sendResponse('error', 'Invalid request body.', null, 400);
    }
}
$input = $method === 'POST' ? $body : $_GET;

$modules = getChoisModules();
$moduleKey = strtolower(trim((string)($input['module'] ?? '')));
if (!isset($modules[$moduleKey])) {
    sendResponse('error', 'Unknown module.', null, 400);
}
$config = $modules[$moduleKey];

if (!userHasModuleAccess($userData, $config['module_key'])) {
    sendResponse('error', "{$config['label']} access denied.", null, 403);
}

$db = $conn;
if (!($db instanceof PDO)) {
    sendResponse('error', 'Database connection not available.', null, 500);
}
if (!choisEnsureTables($db)) {
    sendResponse('error', "{$config['label']} tables are unavailable. Run API/sql_chois_tables.sql on the CHIS database.", null, 500);
}

$action = strtolower(trim((string)($input['action'] ?? '')));
$table = $config['table'];
$numberField = $config['number_field'];
$dateField = $config['date_field'];
$username = (string)($userData['username'] ?? 'unknown');

try {
    if ($method === 'GET') {
        switch ($action) {
            case 'stats':
                $year = (int)date('Y');
                $stmt = $db->prepare("
                    SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN YEAR({$dateField}) = ? THEN 1 ELSE 0 END) AS this_year,
                        SUM(CASE WHEN {$dateField} >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS this_month,
                        SUM(CASE WHEN {$dateField} = CURDATE() THEN 1 ELSE 0 END) AS today,
                        SUM(CASE WHEN Classification = 'FOOD' THEN 1 ELSE 0 END) AS food,
                        SUM(CASE WHEN Classification = 'NON-FOOD' THEN 1 ELSE 0 END) AS non_food
                    FROM {$table}
                    WHERE isDeleted = 0
                ");
                $stmt->execute([$year]);
                $stats = array_map('intval', $stmt->fetch() ?: []);

                $monthly = array_fill(1, 12, 0);
                $monthStmt = $db->prepare("SELECT MONTH({$dateField}) AS m, COUNT(*) AS c FROM {$table} WHERE isDeleted = 0 AND YEAR({$dateField}) = ? GROUP BY MONTH({$dateField})");
                $monthStmt->execute([$year]);
                foreach ($monthStmt->fetchAll() as $row) {
                    $monthly[(int)$row['m']] = (int)$row['c'];
                }

                $recent = $db->query("SELECT * FROM {$table} WHERE isDeleted = 0 ORDER BY ID DESC LIMIT 6")->fetchAll();

                sendResponse('success', 'OK', [
                    'stats' => $stats,
                    'year' => $year,
                    'monthly' => array_values($monthly),
                    'recent' => array_map(static fn($row) => choisDecorateRow($moduleKey, $row), $recent),
                    'can_write' => choisCanWrite($userData),
                ]);

            case 'list':
                [$where, $params] = choisBuildFilters($config, $input);
                $limit = max(5, min(100, (int)($input['limit'] ?? 15)));
                $page = max(1, (int)($input['page'] ?? 1));

                $countStmt = $db->prepare("SELECT COUNT(*) FROM {$table} {$where}");
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();
                $pages = max(1, (int)ceil($total / $limit));
                $page = min($page, $pages);
                $offset = ($page - 1) * $limit;

                $stmt = $db->prepare("SELECT * FROM {$table} {$where} ORDER BY {$dateField} IS NULL, {$dateField} DESC, ID DESC LIMIT {$limit} OFFSET {$offset}");
                $stmt->execute($params);

                sendResponse('success', 'OK', [
                    'rows' => array_map(static fn($row) => choisDecorateRow($moduleKey, $row), $stmt->fetchAll()),
                    'can_write' => choisCanWrite($userData),
                ], 200, ['total' => $total, 'page' => $page, 'pages' => $pages, 'limit' => $limit]);

            case 'get':
                $id = (int)($input['id'] ?? 0);
                $stmt = $db->prepare("SELECT * FROM {$table} WHERE ID = ? AND isDeleted = 0 LIMIT 1");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) {
                    sendResponse('error', 'Record not found.', null, 404);
                }
                sendResponse('success', 'OK', choisDecorateRow($moduleKey, $row));

            case 'report':
                [$where, $params] = choisBuildFilters($config, $input);
                $stmt = $db->prepare("SELECT * FROM {$table} {$where} ORDER BY {$dateField} DESC, {$numberField} DESC LIMIT 5000");
                $stmt->execute($params);
                $rows = array_map(static fn($row) => choisDecorateRow($moduleKey, $row), $stmt->fetchAll());

                $classifications = $db->query("SELECT DISTINCT Classification FROM {$table} WHERE isDeleted = 0 AND Classification IS NOT NULL AND Classification <> '' ORDER BY Classification")->fetchAll(PDO::FETCH_COLUMN);

                sendResponse('success', 'OK', [
                    'rows' => $rows,
                    'classifications' => $classifications,
                    'truncated' => count($rows) >= 5000,
                ]);

            case 'logs':
                $limit = 25;
                $page = max(1, (int)($input['page'] ?? 1));
                $where = 'WHERE Module = ?';
                $params = [$config['label']];
                $search = trim((string)($input['search'] ?? ''));
                if ($search !== '') {
                    $where .= ' AND (Status LIKE ? OR Control_Number LIKE ? OR Updated_by LIKE ?)';
                    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
                }
                $countStmt = $db->prepare("SELECT COUNT(*) FROM " . CHOIS_LOG_TABLE . " {$where}");
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();
                $pages = max(1, (int)ceil($total / $limit));
                $page = min($page, $pages);
                $offset = ($page - 1) * $limit;

                $stmt = $db->prepare("SELECT ID, Status, Module, Control_Number, Updated_by, Date_Updated FROM " . CHOIS_LOG_TABLE . " {$where} ORDER BY Date_Updated DESC, ID DESC LIMIT {$limit} OFFSET {$offset}");
                $stmt->execute($params);

                sendResponse('success', 'OK', ['rows' => $stmt->fetchAll()], 200, ['total' => $total, 'page' => $page, 'pages' => $pages, 'limit' => $limit]);
        }

        sendResponse('error', 'Unknown action.', null, 400);
    }

    if ($method !== 'POST') {
        sendResponse('error', 'Method not allowed.', null, 405);
    }

    if (!choisCanWrite($userData)) {
        sendResponse('error', 'Your account has view-only access.', null, 403);
    }

    switch ($action) {
        case 'save':
            $id = (int)($input['id'] ?? 0);
            $existing = [];
            if ($id > 0) {
                $existsStmt = $db->prepare("SELECT * FROM {$table} WHERE ID = ? AND isDeleted = 0 LIMIT 1");
                $existsStmt->execute([$id]);
                $existing = $existsStmt->fetch();
                if (!$existing) {
                    sendResponse('error', 'Record not found. It may have been deleted.', null, 404);
                }
            }

            [$record, $error] = choisValidateRecord($config, is_array($input['record'] ?? null) ? $input['record'] : [], $existing);
            if ($error !== null) {
                sendResponse('error', $error, null, 422);
            }

            $duplicate = choisFindDuplicate($db, $config, $record[$numberField], $id);
            if ($duplicate) {
                $note = (int)$duplicate['isDeleted'] === 1 ? ' (on a deleted record)' : '';
                sendResponse('error', "{$config['number_label']} {$record[$numberField]} is already in use{$note}.", null, 409);
            }

            $columns = array_keys($record);
            if ($id > 0) {
                $set = implode(', ', array_map(static fn($c) => "{$c} = ?", $columns));
                $stmt = $db->prepare("UPDATE {$table} SET {$set} WHERE ID = ? AND isDeleted = 0");
                $stmt->execute(array_merge(array_values($record), [$id]));
                choisLogActivity($db, $username, 'Update', $config['label'], $record[$numberField]);
                $message = "{$config['label']} updated.";
            } else {
                $stmt = $db->prepare("INSERT INTO {$table} (" . implode(', ', $columns) . ', isDeleted) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ', 0)');
                $stmt->execute(array_values($record));
                $id = (int)$db->lastInsertId();
                choisLogActivity($db, $username, 'Create', $config['label'], $record[$numberField]);
                $message = "{$config['label']} saved.";
            }

            $rowStmt = $db->prepare("SELECT * FROM {$table} WHERE ID = ? LIMIT 1");
            $rowStmt->execute([$id]);
            sendResponse('success', $message, choisDecorateRow($moduleKey, $rowStmt->fetch() ?: ['ID' => $id]));

        case 'delete':
            $id = (int)($input['id'] ?? 0);
            $stmt = $db->prepare("SELECT ID, {$numberField} FROM {$table} WHERE ID = ? AND isDeleted = 0 LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                sendResponse('error', 'Record not found. It may already be deleted.', null, 404);
            }
            $db->prepare("UPDATE {$table} SET isDeleted = 1 WHERE ID = ?")->execute([$id]);
            choisLogActivity($db, $username, 'Delete', $config['label'], $row[$numberField]);
            sendResponse('success', "{$config['label']} deleted.");
    }

    sendResponse('error', 'Unknown action.', null, 400);
} catch (PDOException $e) {
    error_log("api_chois {$moduleKey}/{$action}: " . $e->getMessage());
    if ($e->getCode() === '23000') {
        sendResponse('error', "That {$config['number_label']} is already in use.", null, 409);
    }
    sendResponse('error', 'A database error occurred. Please try again.', null, 500);
}
