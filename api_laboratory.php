<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';

function sendResponse($status, $message, $data = null, $code = 200, $meta = null) {
    http_response_code($code);

    $payload = [
        'status' => $status,
        'message' => $message
    ];

    if ($data !== null) {
        $payload['data'] = $data;
    }

    if ($meta !== null) {
        $payload['meta'] = $meta;
    }

    echo json_encode($payload);
    exit;
}

function normalizeString($value) {
    return trim((string)$value);
}

function normalizeStatus($status) {
    $normalized = strtoupper(normalizeString($status));
    $allowed = ['PENDING', 'PROCESSING', 'READY_FOR_RELEASE', 'RELEASED', 'CANCELLED'];
    if (!in_array($normalized, $allowed, true)) {
        return 'PROCESSING';
    }
    return $normalized;
}

function normalizeTestType($testType) {
    $normalized = strtoupper(normalizeString($testType));
    $normalized = preg_replace('/[^A-Z0-9_]/', '_', $normalized);
    $normalized = preg_replace('/_+/', '_', $normalized);
    $normalized = trim($normalized, '_');
    $allowed = [
        'BLOOD_CHEM',
        'HEMATOLOGY',
        'URINALYSIS',
        'FECALYSIS',
        'SEROLOGY',
        'MICROSCOPY',
        'SPUTUM',
        'OTHER'
    ];
    if (!in_array($normalized, $allowed, true)) {
        return 'OTHER';
    }
    return $normalized;
}

function getBloodChemChargeItems() {
    return [
        ['key' => 'package', 'test_name' => 'Blood Chemistry Package', 'label' => 'Blood Chemistry Package', 'amount' => 1055.00, 'is_package' => 1],
        ['key' => 'uric_acid', 'test_name' => 'Blood Uric Acid (BUA)', 'label' => 'Blood Uric Acid (BUA)', 'amount' => 85.00, 'is_package' => 0],
        ['key' => 'bun', 'test_name' => 'Blood Urea Nitrogen (BUN)', 'label' => 'Blood Urea Nitrogen (BUN)', 'amount' => 85.00, 'is_package' => 0],
        ['key' => 'creatinine', 'test_name' => 'Creatinine', 'label' => 'Creatinine', 'amount' => 75.00, 'is_package' => 0],
        ['key' => 'glucose', 'test_name' => 'Glucose (FBS/RBS)', 'label' => 'Glucose (FBS/RBS)', 'amount' => 80.00, 'is_package' => 0],
        ['key' => 'hdl', 'test_name' => 'High-Density Lipoprotein (HDL)', 'label' => 'High-Density Lipoprotein (HDL)', 'amount' => 150.00, 'is_package' => 0],
        ['key' => 'ldl', 'test_name' => 'Low-Density Lipoprotein (LDL)', 'label' => 'Low-Density Lipoprotein (LDL)', 'amount' => 150.00, 'is_package' => 0],
        ['key' => 'lipid_profile', 'test_name' => 'Lipid Profile', 'label' => 'Lipid Profile', 'amount' => 450.00, 'is_package' => 0],
        ['key' => 'cholesterol', 'test_name' => 'Total Cholesterol', 'label' => 'Total Cholesterol', 'amount' => 85.00, 'is_package' => 0],
        ['key' => 'triglycerides', 'test_name' => 'Triglycerides (TAG)', 'label' => 'Triglycerides (TAG)', 'amount' => 220.00, 'is_package' => 0],
        ['key' => 'sgpt', 'test_name' => 'SGPT/ALT', 'label' => 'SGPT/ALT', 'amount' => 150.00, 'is_package' => 0],
        ['key' => 'sgot', 'test_name' => 'SGOT/AST', 'label' => 'SGOT/AST', 'amount' => 150.00, 'is_package' => 0],
        ['key' => 'hba1c', 'test_name' => 'Hba1c Test', 'label' => 'Hba1c Test', 'amount' => 650.00, 'is_package' => 0],
        ['key' => 'dgt75', 'test_name' => 'DGT 75%', 'label' => 'DGT 75%', 'amount' => null, 'is_package' => 0],
        ['key' => 'electrolytes', 'test_name' => 'ELECTROLYTES (Na+, K+, nCa, CL-)', 'label' => 'ELECTROLYTES (Na+, K+, nCa, CL-)', 'amount' => null, 'is_package' => 0],
    ];
}

function getBloodChemChargeMap() {
    $map = [];
    foreach (getBloodChemChargeItems() as $item) {
        $map[$item['key']] = $item;
    }
    return $map;
}

function getYakapExcludedBloodChemKeys() {
    return ['sgot', 'sgpt', 'uric_acid', 'bun'];
}

function normalizeFlag($value) {
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_numeric($value)) {
        return ((int)$value) > 0 ? 1 : 0;
    }

    $v = strtolower(normalizeString($value));
    return in_array($v, ['1', 'true', 'yes', 'y', 'employee'], true) ? 1 : 0;
}

function normalizePatientType($value) {
    $normalized = strtolower(normalizeString($value));
    return in_array($normalized, ['employee', 'emp', '1', 'true', 'yes'], true) ? 'employee' : 'citizen';
}

function normalizeDateOnly($value) {
    $value = normalizeString($value);
    if ($value === '') {
        return null;
    }

    try {
        $date = new DateTime($value);
    } catch (Exception $e) {
        return false;
    }

    return $date->format('Y-m-d');
}

function normalizeDateTimeValue($value) {
    $value = normalizeString($value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return null;
    }

    try {
        $date = new DateTime($value);
    } catch (Exception $e) {
        return null;
    }

    return $date->format('Y-m-d H:i:s');
}

function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '', (string)$identifier) . '`';
}

function getTableColumns($conn, $table) {
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $conn->query('DESCRIBE ' . quoteIdentifier($table));
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $rows = [];
    }

    $columns = [];
    foreach ($rows as $row) {
        if (isset($row['Field'])) {
            $columns[] = $row['Field'];
        }
    }

    $cache[$table] = $columns;
    return $columns;
}

function getTableColumnDefinition($conn, $table, $column) {
    try {
        $stmt = $conn->query('DESCRIBE ' . quoteIdentifier($table));
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return null;
    }

    foreach ($rows as $row) {
        if ((string)($row['Field'] ?? '') === (string)$column) {
            return $row;
        }
    }

    return null;
}

function pickColumn($columns, $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ensureLaboratoryResultsTable($conn) {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    if (!$conn) {
        $ready = false;
        return false;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_laboratory_results (
            result_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            piid VARCHAR(64) NOT NULL,
            test_name VARCHAR(150) NOT NULL,
            test_type VARCHAR(50) NOT NULL DEFAULT 'BLOOD_CHEM',
            is_employee TINYINT(1) NOT NULL DEFAULT 0,
            request_date DATE NULL,
            result_summary VARCHAR(255) NOT NULL,
            result_value TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'PROCESSING',
            result_date DATE NULL,
            remarks TEXT NULL,
            created_by VARCHAR(80) NULL,
            updated_by VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (result_id),
            KEY idx_lab_result_piid_date (piid, result_date),
            KEY idx_lab_result_status_date (status, result_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $existingCols = getTableColumns($conn, 'tbl_laboratory_results');
        if (!in_array('test_type', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN test_type VARCHAR(50) NOT NULL DEFAULT 'BLOOD_CHEM' AFTER test_name");
        }
        if (!in_array('is_employee', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN is_employee TINYINT(1) NOT NULL DEFAULT 0 AFTER test_type");
        }
        if (!in_array('request_date', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN request_date DATE NULL AFTER is_employee");
        }

        $resultDateDef = getTableColumnDefinition($conn, 'tbl_laboratory_results', 'result_date');
        if ($resultDateDef && strtoupper((string)($resultDateDef['Null'] ?? 'NO')) !== 'YES') {
            $conn->exec("ALTER TABLE tbl_laboratory_results MODIFY COLUMN result_date DATE NULL");
        }

        if (!in_array('or_number', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN or_number VARCHAR(50) NULL AFTER remarks");
        }
        if (!in_array('payment_required', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN payment_required TINYINT(1) NOT NULL DEFAULT 0 AFTER or_number");
        }
        if (!in_array('is_deleted', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_required");
        }
        if (!in_array('lab_patient_id', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN lab_patient_id BIGINT UNSIGNED NULL AFTER piid");
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD KEY idx_lab_result_lab_patient_id (lab_patient_id)");
        }
        // Sign-off trail: who last saved the result as Ready for Release, and who released it.
        if (!in_array('verified_by', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN verified_by VARCHAR(80) NULL AFTER updated_by, ADD COLUMN verified_at DATETIME NULL AFTER verified_by");
        }
        if (!in_array('released_by', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN released_by VARCHAR(80) NULL AFTER verified_at, ADD COLUMN released_at DATETIME NULL AFTER released_by");
        }
        // Amendments: a correction to a released result is a new record linked both ways.
        if (!in_array('amends_result_id', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN amends_result_id BIGINT UNSIGNED NULL AFTER released_at, ADD COLUMN amended_by_result_id BIGINT UNSIGNED NULL AFTER amends_result_id, ADD KEY idx_lab_result_amends (amends_result_id)");
        }

        $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to ensure laboratory results table: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

function readPayload() {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        sendResponse('error', 'Invalid JSON format.', null, 400);
    }

    return $decoded;
}

function getPersonColumns($conn) {
    $columns = getTableColumns($conn, 'tbl_personal_details');
    if (empty($columns)) {
        return null;
    }

    $meta = [
        'piid' => pickColumn($columns, ['PIID', 'piid']),
        'surname' => pickColumn($columns, ['Surname', 'surname']),
        'firstname' => pickColumn($columns, ['FirstName', 'Firstname', 'firstname', 'first_name']),
        'middlename' => pickColumn($columns, ['MiddleName', 'Middlename', 'middlename', 'middle_name']),
        'nameext' => pickColumn($columns, ['NameExt', 'Name_Ext', 'nameext', 'name_ext']),
        'sex' => pickColumn($columns, ['Sex', 'sex']),
        'birthdate' => pickColumn($columns, ['Birthdate', 'birthdate']),
        'barangay' => pickColumn($columns, ['Barangay', 'barangay']),
        'city' => pickColumn($columns, ['City', 'city'])
    ];

    if (!$meta['piid'] || !$meta['surname'] || !$meta['firstname']) {
        return null;
    }

    return $meta;
}

function buildPersonDisplayExpr($meta) {
    $surname = quoteIdentifier($meta['surname']);
    $firstname = quoteIdentifier($meta['firstname']);
    $middlename = $meta['middlename'] ? quoteIdentifier($meta['middlename']) : null;
    $nameext = $meta['nameext'] ? quoteIdentifier($meta['nameext']) : null;
    $piid = quoteIdentifier($meta['piid']);

    $middleExpr = $middlename
        ? "IFNULL(CONCAT(NULLIF(LEFT({$middlename}, 1), ''), '. '), '')"
        : "''";

    $nameExtExpr = $nameext
        ? "IFNULL(CONCAT(NULLIF({$nameext}, ''), ' '), '')"
        : "''";

    return "CONCAT({$surname}, ', ', {$firstname}, ' ', {$middleExpr}, {$nameExtExpr}, {$piid})";
}

function getPersonByPiid($conn, $piid, $meta) {
    $stmt = $conn->prepare(
        'SELECT * FROM tbl_personal_details WHERE ' . quoteIdentifier($meta['piid']) . ' = ? LIMIT 1'
    );
    $stmt->execute([$piid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function formatPersonName($row, $meta) {
    $parts = [];

    $surname = trim((string)($row[$meta['surname']] ?? ''));
    $firstname = trim((string)($row[$meta['firstname']] ?? ''));
    $middlename = $meta['middlename'] ? trim((string)($row[$meta['middlename']] ?? '')) : '';
    $nameext = $meta['nameext'] ? trim((string)($row[$meta['nameext']] ?? '')) : '';
    $piid = trim((string)($row[$meta['piid']] ?? ''));

    if ($surname !== '') {
        $parts[] = $surname . ',';
    }
    if ($firstname !== '') {
        $parts[] = $firstname;
    }
    if ($middlename !== '') {
        $parts[] = strtoupper(substr($middlename, 0, 1)) . '.';
    }
    if ($nameext !== '') {
        $parts[] = $nameext;
    }
    if ($piid !== '') {
        $parts[] = $piid;
    }

    return trim(implode(' ', $parts));
}

// ---------------------------------------------------------------------------
// tbl_lab_personal_info helpers
// ---------------------------------------------------------------------------

function ensureLabPersonalInfoTable($conn) {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!$conn) {
        $ready = false;
        return false;
    }
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_lab_personal_info (
            lab_patient_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            piid           VARCHAR(64)  NULL,
            is_employee    TINYINT(1)   NOT NULL DEFAULT 0,
            surname        VARCHAR(100) NOT NULL,
            first_name     VARCHAR(100) NOT NULL,
            middle_name    VARCHAR(100) NULL,
            name_ext       VARCHAR(20)  NULL,
            sex            VARCHAR(10)  NOT NULL DEFAULT '',
            birthdate      DATE         NULL,
            city           VARCHAR(100) NULL,
            barangay       VARCHAR(100) NULL,
            yakap_member   TINYINT(1)   NOT NULL DEFAULT 0,
            yakap_id_number VARCHAR(80) NULL,
            senior         TINYINT(1)   NOT NULL DEFAULT 0,
            senior_id_number VARCHAR(80) NULL,
            pwd            TINYINT(1)   NOT NULL DEFAULT 0,
            pwd_id_number  VARCHAR(80)  NULL,
            is_archived    TINYINT(1)   NOT NULL DEFAULT 0,
            source_system   VARCHAR(40)  NULL,
            source_snapshot_at DATETIME NULL,
            employee_roster_synced_at DATETIME NULL,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NULL,
            PRIMARY KEY (lab_patient_id),
            UNIQUE KEY uq_lab_piid (piid),
            KEY idx_lab_patient_name (surname, first_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = getTableColumns($conn, 'tbl_lab_personal_info');
        if (!in_array('is_employee', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN is_employee TINYINT(1) NOT NULL DEFAULT 0 AFTER piid");
        }
        if (!in_array('yakap_member', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN yakap_member TINYINT(1) NOT NULL DEFAULT 0 AFTER barangay");
        }
        if (!in_array('yakap_id_number', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN yakap_id_number VARCHAR(80) NULL AFTER yakap_member");
        }
        if (!in_array('senior', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN senior TINYINT(1) NOT NULL DEFAULT 0 AFTER yakap_id_number");
        }
        if (!in_array('senior_id_number', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN senior_id_number VARCHAR(80) NULL AFTER senior");
        }
        if (!in_array('pwd', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN pwd TINYINT(1) NOT NULL DEFAULT 0 AFTER senior_id_number");
        }
        if (!in_array('pwd_id_number', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN pwd_id_number VARCHAR(80) NULL AFTER pwd");
        }
        if (!in_array('is_archived', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER pwd_id_number");
        }
        if (!in_array('source_system', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN source_system VARCHAR(40) NULL AFTER is_archived");
        }
        if (!in_array('source_snapshot_at', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN source_snapshot_at DATETIME NULL AFTER source_system");
        }
        if (!in_array('employee_roster_synced_at', $cols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_personal_info ADD COLUMN employee_roster_synced_at DATETIME NULL AFTER source_snapshot_at");
        }
        $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to ensure lab personal info table: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

function formatLabPatientDisplayName($row) {
    $parts    = [];
    $surname  = trim((string)($row['surname']     ?? ''));
    $first    = trim((string)($row['first_name']  ?? ''));
    $middle   = trim((string)($row['middle_name'] ?? ''));
    $ext      = trim((string)($row['name_ext']    ?? ''));
    $piid     = trim((string)($row['piid']        ?? ''));

    if ($surname !== '') $parts[] = $surname . ',';
    if ($first   !== '') $parts[] = $first;
    if ($middle  !== '') $parts[] = strtoupper(substr($middle, 0, 1)) . '.';
    if ($ext     !== '') $parts[] = $ext;
    if ($piid    !== '') $parts[] = $piid;

    return trim(implode(' ', $parts));
}

function getLabPatientByPiid($conn, $piid) {
    if ($piid === '') {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT * FROM tbl_lab_personal_info WHERE piid = :piid LIMIT 1'
    );
    $stmt->execute([':piid' => $piid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getLabPatientById($conn, $labPatientId) {
    $labPatientId = (int)$labPatientId;
    if ($labPatientId <= 0) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM tbl_lab_personal_info WHERE lab_patient_id = :id LIMIT 1');
    $stmt->execute([':id' => $labPatientId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function generateLabPatientPiid($labPatientId, $isEmployee) {
    return ($isEmployee ? 'LAB-E-' : 'LAB-C-') . str_pad((string)((int)$labPatientId), 6, '0', STR_PAD_LEFT);
}

function findLabPatientDuplicateCandidates($conn, $data, $limit = 8) {
    $surname = normalizeString($data['surname'] ?? '');
    $firstName = normalizeString($data['first_name'] ?? '');
    $birthdate = normalizeString($data['birthdate'] ?? '');
    $barangay = normalizeString($data['barangay'] ?? '');
    $isEmployee = (int)($data['is_employee'] ?? 0);

    if ($surname === '' || $firstName === '') {
        return [];
    }

    $where = [
        'is_employee = :is_employee',
        "(
            (LOWER(surname) = LOWER(:surname_exact) AND LOWER(first_name) = LOWER(:first_exact))
            OR SOUNDEX(surname) = SOUNDEX(:surname_soundex)
            OR SOUNDEX(first_name) = SOUNDEX(:first_soundex)
            OR surname LIKE :surname_like
            OR first_name LIKE :first_like
        )"
    ];
    $params = [
        ':is_employee' => $isEmployee,
        ':surname_exact' => $surname,
        ':first_exact' => $firstName,
        ':surname_soundex' => $surname,
        ':first_soundex' => $firstName,
        ':surname_like' => '%' . $surname . '%',
        ':first_like' => '%' . $firstName . '%',
    ];

    if ($birthdate !== '') {
        $where[] = '(birthdate = :birthdate OR birthdate IS NULL)';
        $params[':birthdate'] = $birthdate;
    }
    if ($barangay !== '') {
        $where[] = '(LOWER(IFNULL(barangay, "")) = LOWER(:barangay) OR barangay IS NULL OR barangay = "")';
        $params[':barangay'] = $barangay;
    }

    $stmt = $conn->prepare(
        "SELECT
            lab_patient_id, piid, is_employee, surname, first_name, middle_name, name_ext,
            sex, birthdate, barangay, city, yakap_member, yakap_id_number, senior, senior_id_number, pwd, pwd_id_number
         FROM tbl_lab_personal_info
         WHERE " . implode(' AND ', $where) . "
         ORDER BY
            CASE WHEN LOWER(surname) = LOWER(:surname_order) AND LOWER(first_name) = LOWER(:first_order) THEN 0 ELSE 1 END,
            birthdate DESC,
            surname ASC,
            first_name ASC
         LIMIT " . (int)$limit
    );
    $params[':surname_order'] = $surname;
    $params[':first_order'] = $firstName;
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $score = 0;
        if (strcasecmp((string)($row['surname'] ?? ''), $surname) === 0) $score += 40;
        if (strcasecmp((string)($row['first_name'] ?? ''), $firstName) === 0) $score += 35;
        if ($birthdate !== '' && (string)($row['birthdate'] ?? '') === $birthdate) $score += 20;
        if ($barangay !== '' && strcasecmp((string)($row['barangay'] ?? ''), $barangay) === 0) $score += 5;
        $row['match_score'] = $score;
        $row['display_name'] = formatLabPatientDisplayName($row);
    }
    unset($row);

    return array_values(array_filter($rows, fn($row) => (int)($row['match_score'] ?? 0) >= 40));
}

/**
 * Upsert a patient into tbl_lab_personal_info using data from tbl_personal_details.
 * Returns the lab_patient_id on success, or 0 on failure.
 */
function upsertLabPatient($conn, $piid, $personRow, $personMeta) {
    $surname    = normalizeString($personRow[$personMeta['surname']]    ?? '');
    $firstName  = normalizeString($personRow[$personMeta['firstname']]  ?? '');
    $middleName = $personMeta['middlename'] ? normalizeString($personRow[$personMeta['middlename']] ?? '') : '';
    $nameExt    = $personMeta['nameext']    ? normalizeString($personRow[$personMeta['nameext']]    ?? '') : '';
    $sex        = $personMeta['sex']        ? normalizeString($personRow[$personMeta['sex']]        ?? '') : '';
    $birthdateRaw = $personMeta['birthdate'] ? normalizeString($personRow[$personMeta['birthdate']] ?? '') : '';
    $birthdate  = ($birthdateRaw !== '' && $birthdateRaw !== '0000-00-00') ? $birthdateRaw : null;
    $city       = $personMeta['city']       ? normalizeString($personRow[$personMeta['city']]       ?? '') : '';
    $barangay   = $personMeta['barangay']   ? normalizeString($personRow[$personMeta['barangay']]   ?? '') : '';

    try {
        $stmt = $conn->prepare(
            "INSERT INTO tbl_lab_personal_info
                (piid, surname, first_name, middle_name, name_ext, sex, birthdate, city, barangay, source_system, source_snapshot_at)
             VALUES
                (:piid, :surname, :first_name, :middle_name, :name_ext, :sex, :birthdate, :city, :barangay, 'emedtrack_personal_details', NOW())
             ON DUPLICATE KEY UPDATE
                surname     = VALUES(surname),
                first_name  = VALUES(first_name),
                middle_name = VALUES(middle_name),
                name_ext    = VALUES(name_ext),
                sex         = VALUES(sex),
                birthdate   = VALUES(birthdate),
                city        = VALUES(city),
                barangay    = VALUES(barangay),
                source_system = VALUES(source_system),
                source_snapshot_at = VALUES(source_snapshot_at)"
        );
        $stmt->execute([
            ':piid'        => $piid ?: null,
            ':surname'     => $surname,
            ':first_name'  => $firstName,
            ':middle_name' => $middleName ?: null,
            ':name_ext'    => $nameExt    ?: null,
            ':sex'         => $sex,
            ':birthdate'   => $birthdate,
            ':city'        => $city     ?: null,
            ':barangay'    => $barangay ?: null,
        ]);

        // Retrieve the lab_patient_id (works for both INSERT and UPDATE)
        $find = $conn->prepare(
            'SELECT lab_patient_id FROM tbl_lab_personal_info WHERE piid = :piid LIMIT 1'
        );
        $find->execute([':piid' => $piid]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['lab_patient_id'] : 0;
    } catch (Throwable $e) {
        error_log('upsertLabPatient error: ' . $e->getMessage());
        return 0;
    }
}

// ---------------------------------------------------------------------------
// tbl_lab_fees helpers
// ---------------------------------------------------------------------------

function ensureLabFeesTable($conn) {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!$conn) {
        $ready = false;
        return false;
    }
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_lab_fees (
            fee_id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            test_type      VARCHAR(50)   NOT NULL,
            test_name      VARCHAR(150)  NULL,
            amount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            effective_from DATE          NOT NULL DEFAULT '2000-01-01',
            description    VARCHAR(255)  NULL,
            is_active      TINYINT(1)    NOT NULL DEFAULT 1,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NULL,
            PRIMARY KEY (fee_id),
            UNIQUE KEY uq_lab_fee (test_type, test_name, effective_from),
            KEY idx_lab_fee_type (test_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Migration: add effective_from column and update unique key for existing tables
        $feeCols = array_column($conn->query("SHOW COLUMNS FROM tbl_lab_fees")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('effective_from', $feeCols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_fees ADD COLUMN effective_from DATE NOT NULL DEFAULT '2000-01-01' AFTER amount");
            try { $conn->exec("ALTER TABLE tbl_lab_fees DROP INDEX uq_lab_fee"); } catch (Throwable $e) {}
            try { $conn->exec("ALTER TABLE tbl_lab_fees ADD UNIQUE KEY uq_lab_fee (test_type, test_name, effective_from)"); } catch (Throwable $e) {}
        }

        $seedStmt = $conn->prepare(
            "INSERT INTO tbl_lab_fees (test_type, test_name, amount, effective_from, description, is_active)
             SELECT :test_type, :test_name, :amount, '2000-01-01', :description, 1
             FROM DUAL
             WHERE NOT EXISTS (
                SELECT 1 FROM tbl_lab_fees
                WHERE test_type = :test_type_check
                  AND test_name = :test_name_check
                  AND effective_from = '2000-01-01'
             )"
        );
        foreach (getBloodChemChargeItems() as $item) {
            if ($item['amount'] === null) {
                continue;
            }
            $seedStmt->execute([
                ':test_type' => 'BLOOD_CHEM',
                ':test_name' => $item['test_name'],
                ':amount' => $item['amount'],
                ':description' => 'Default blood chemistry charge slip item',
                ':test_type_check' => 'BLOOD_CHEM',
                ':test_name_check' => $item['test_name'],
            ]);
        }
        $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to ensure lab fees table: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * Load all active fees into a two-level array keyed by [UPPER(test_type)][lower(test_name)].
 * A NULL test_name row is stored under the '_default' key for its test_type.
 */
function loadLabFees($conn) {
    try {
        $stmt = $conn->query(
            "SELECT test_type, test_name, amount, effective_from
             FROM tbl_lab_fees
             WHERE is_active = 1
             ORDER BY test_type, test_name, effective_from DESC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $fees = [];
    foreach ($rows as $row) {
        $typeKey = strtoupper(trim((string)($row['test_type'] ?? '')));
        $nameRaw = $row['test_name'];
        $amount  = (float)($row['amount'] ?? 0);
        $eff     = (string)($row['effective_from'] ?? '2000-01-01');
        $nameKey = ($nameRaw === null || trim($nameRaw) === '') ? '_default' : strtolower(trim($nameRaw));
        $fees[$typeKey][$nameKey][] = ['eff' => $eff, 'amt' => $amount];
    }
    return $fees;
}

/**
 * Look up a fee from a pre-loaded fees array.
 * Entries per key are sorted by effective_from DESC; we return the first entry
 * whose effective_from is <= $date.  If $date is null, the latest entry is used.
 */
function lookupFee($fees, $testType, $testName, $date = null) {
    $typeKey = strtoupper(trim((string)$testType));
    $nameKey = strtolower(trim((string)$testName));
    $keys = ($nameKey !== '' && $nameKey !== '_default') ? [$nameKey, '_default'] : ['_default'];
    foreach ($keys as $key) {
        if (!isset($fees[$typeKey][$key])) continue;
        foreach ($fees[$typeKey][$key] as $entry) {
            if ($date === null || $entry['eff'] <= $date) {
                return $entry['amt'];
            }
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// tbl_lab_org_chart helpers
// ---------------------------------------------------------------------------

function ensureLabOrgChartTable($conn) {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!$conn) {
        $ready = false;
        return false;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_lab_org_chart (
            staff_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NULL,
            full_name VARCHAR(150) NOT NULL,
            designation VARCHAR(150) NOT NULL DEFAULT '',
            license_no VARCHAR(80) NULL,
            section_name VARCHAR(120) NULL,
            specialization VARCHAR(255) NULL,
            photo_url VARCHAR(255) NULL,
            is_checkout_signatory TINYINT(1) NOT NULL DEFAULT 0,
            is_result_signatory TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(80) NULL,
            updated_by VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (staff_id),
            KEY idx_lab_org_parent (parent_id),
            KEY idx_lab_org_active_sort (is_active, sort_order, full_name),
            KEY idx_lab_org_signatory (is_checkout_signatory, is_result_signatory)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cols = getTableColumns($conn, 'tbl_lab_org_chart');
        $addColumn = function ($column, $definition) use ($conn, $cols) {
            if (!in_array($column, $cols, true)) {
                $conn->exec("ALTER TABLE tbl_lab_org_chart ADD COLUMN {$definition}");
            }
        };
        $addColumn('photo_url', 'photo_url VARCHAR(255) NULL AFTER specialization');
        $addColumn('is_checkout_signatory', 'is_checkout_signatory TINYINT(1) NOT NULL DEFAULT 0 AFTER photo_url');
        $addColumn('is_result_signatory', 'is_result_signatory TINYINT(1) NOT NULL DEFAULT 0 AFTER is_checkout_signatory');
        $addColumn('sort_order', 'sort_order INT NOT NULL DEFAULT 0 AFTER is_result_signatory');
        $addColumn('is_active', 'is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order');
        $addColumn('updated_at', 'updated_at DATETIME NULL AFTER created_at');

        seedLabOrgChartFromImage($conn);

        $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to ensure lab org chart table: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

function seedLabOrgChartFromImage($conn) {
    try {
        $count = (int)$conn->query("SELECT COUNT(*) FROM tbl_lab_org_chart")->fetchColumn();
        if ($count > 0) {
            return;
        }

        $insert = $conn->prepare(
            "INSERT INTO tbl_lab_org_chart (
                parent_id, full_name, designation, license_no, section_name, specialization,
                is_checkout_signatory, is_result_signatory, sort_order, is_active, created_by
             ) VALUES (
                :parent_id, :full_name, :designation, NULL, :section_name, :specialization,
                :is_checkout_signatory, :is_result_signatory, :sort_order, 1, 'image_seed'
             )"
        );

        $ids = [];
        $add = function ($key, $parentKey, $name, $designation, $section, $notes, $sort, $checkout = 0, $results = 0) use ($conn, $insert, &$ids) {
            $parentId = $parentKey !== null && isset($ids[$parentKey]) ? $ids[$parentKey] : null;
            $insert->execute([
                ':parent_id' => $parentId,
                ':full_name' => $name,
                ':designation' => $designation,
                ':section_name' => $section !== '' ? $section : null,
                ':specialization' => $notes !== '' ? $notes : null,
                ':is_checkout_signatory' => $checkout ? 1 : 0,
                ':is_result_signatory' => $results ? 1 : 0,
                ':sort_order' => $sort,
            ]);
            $ids[$key] = (int)$conn->lastInsertId();
        };

        $add('amabelle', null, 'AMABELLE TCHULA, RMT', 'Chief Medical Technologist', 'Laboratory Administration', 'TB Lab Microscopist; Biosafety Officer; Biosafety Manager', 10, 1, 1);
        $add('joy', 'amabelle', 'JOY RUTH ARKALDEZ, RMT', 'Medical Technologist I', 'Laboratory Operations', 'Sterilization; specimen processing; lab file', 20, 1, 0);
        $add('eden', 'amabelle', 'EDEN PANCHO, RMT', 'Medical Technologist II', 'TB Laboratory', 'TB Lab Microscopy; Integrated Biosafety Officer', 30, 1, 1);

        $add('nova', 'joy', 'NOVA MAE B. MAGBANNOY, RMT', 'Medical Technologist I', 'Clinical Laboratory', 'TB DOTS; PhilHealth accredited clinical laboratory certifying officer', 40, 0, 0);
        $add('menargie', 'joy', 'MENARGIE D.S. MATOCINO, RMT', 'Medical Technologist I', 'Clinical Laboratory', 'Water lab analyst; integrated biosafety officer; microscopy', 50, 0, 1);
        $add('jeffry', 'joy', 'JEFFRY M. ALCOLAR, RMT', 'Medical Technologist I', 'Clinical Laboratory', 'HIV screening; newborn screening', 60, 0, 0);

        $add('amaryllis', 'eden', 'AMARYLLIS A. MURILLO, RMT', 'Medical Technologist I', 'Clinical Chemistry', 'HIV proficiency; clinical chemistry', 70, 0, 0);
        $add('louis', 'eden', 'LOUIS MIGUEL B. CABRERA, RMT', 'Medical Technologist I', 'Clinical Laboratory', '', 80, 0, 0);
        $add('jane', 'eden', 'JANE A. LADESMA, RMT', 'Medical Technologist I', 'Clinical Laboratory', '', 90, 0, 0);

        $add('harry', 'nova', 'HARRY EZRA C. LONIO, RMT', 'Medical Technologist I', 'Clinical Laboratory', 'Name read from attached organizational chart image.', 100, 0, 0);
        $add('ana', 'nova', 'ANA M. BERSABAL, RMT', 'Medical Technologist I', 'Clinical Laboratory', 'Name partly unclear in attached image; please verify spelling.', 110, 0, 0);
        $add('mary', 'menargie', 'MARY CHELO G. QUIMPOIANO, RMT', 'Medical Technologist I', 'Clinical Laboratory', '', 120, 0, 0);
        $add('julianne', 'amaryllis', 'JULIANNE GRACE H. CHOSENON, RMT', 'Medical Technologist I', 'Clinical Laboratory', 'Name partly unclear in attached image; please verify spelling.', 130, 0, 0);
        $add('japheth', 'louis', 'JAPHETH J. G. BARBERRA, RMT', 'Medical Technologist I', 'Clinical Laboratory', 'Name partly unclear in attached image; please verify spelling.', 140, 0, 0);
    } catch (Throwable $e) {
        error_log('Unable to seed lab org chart from image: ' . $e->getMessage());
    }
}

function normalizeOrgStaffRow($row) {
    return [
        'staff_id' => (int)($row['staff_id'] ?? 0),
        'parent_id' => $row['parent_id'] === null ? null : (int)$row['parent_id'],
        'full_name' => (string)($row['full_name'] ?? ''),
        'designation' => (string)($row['designation'] ?? ''),
        'license_no' => (string)($row['license_no'] ?? ''),
        'section_name' => (string)($row['section_name'] ?? ''),
        'specialization' => (string)($row['specialization'] ?? ''),
        'photo_url' => (string)($row['photo_url'] ?? ''),
        'is_checkout_signatory' => (int)($row['is_checkout_signatory'] ?? 0),
        'is_result_signatory' => (int)($row['is_result_signatory'] ?? 0),
        'sort_order' => (int)($row['sort_order'] ?? 0),
        'is_active' => (int)($row['is_active'] ?? 0),
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}

function normalizeBloodChemSelection($value) {
    $catalog = getBloodChemChargeMap();
    $raw = [];

    if (is_array($value)) {
        $raw = $value;
    } elseif (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }

    $keys = [];
    foreach ($raw as $entry) {
        $key = is_array($entry) ? (string)($entry['key'] ?? '') : (string)$entry;
        $key = strtolower(trim($key));
        if ($key !== '' && isset($catalog[$key])) {
            $keys[$key] = true;
        }
    }

    if (empty($keys)) {
        return ['package'];
    }

    return array_values(array_keys($keys));
}

function buildBloodChemSelectionDetails($selectedKeys, $fees, $requestDate = null) {
    $catalog = getBloodChemChargeMap();
    $items = [];
    $total = 0.0;

    foreach ($selectedKeys as $key) {
        if (!isset($catalog[$key])) {
            continue;
        }
        $item = $catalog[$key];
        $amount = lookupFee($fees, 'BLOOD_CHEM', $item['test_name'], $requestDate);
        if ($amount === null && $item['amount'] !== null) {
            $amount = (float)$item['amount'];
        }
        if ($amount !== null) {
            $total += (float)$amount;
        }
        $items[] = [
            'key' => $item['key'],
            'label' => $item['label'],
            'test_name' => $item['test_name'],
            'amount' => $amount !== null ? number_format((float)$amount, 2, '.', '') : null,
            'is_package' => (int)$item['is_package'],
        ];
    }

    return [
        'selected_tests' => $items,
        'total_amount' => number_format($total, 2, '.', ''),
    ];
}

function getLabDiscountInfo($patient) {
    $reasons = [];
    if ((int)($patient['senior'] ?? 0) === 1) {
        $reasons[] = 'Senior Citizen';
    }
    if ((int)($patient['pwd'] ?? 0) === 1) {
        $reasons[] = 'PWD';
    }
    if ((int)($patient['yakap_member'] ?? 0) === 1) {
        $reasons[] = 'Yakap Member';
    }

    return [
        'rate' => count($reasons) > 0 ? 0.20 : 0.0,
        'reasons' => $reasons,
    ];
}

function applyLabDiscount($amount, $patient, $selectedTests = null) {
    if ($amount === null || $amount === '') {
        return [
            'gross_amount' => null,
            'discount_rate' => '0.00',
            'discount_amount' => null,
            'net_amount' => null,
            'discount_reasons' => [],
        ];
    }

    $gross = max(0.0, (float)$amount);

    if ((int)($patient['yakap_member'] ?? 0) === 1 && is_array($selectedTests)) {
        $excluded = array_flip(getYakapExcludedBloodChemKeys());
        $hasPackage = false;
        $coveredTotal = 0.0;
        $excludedTotal = 0.0;

        foreach ($selectedTests as $item) {
            $key = strtolower(trim((string)($item['key'] ?? '')));
            $itemAmount = (float)($item['amount'] ?? 0);
            if ($key === 'package') {
                $hasPackage = true;
                continue;
            }
            if (isset($excluded[$key])) {
                $excludedTotal += $itemAmount;
                continue;
            }
            $coveredTotal += $itemAmount;
        }

        $coveredDue = $hasPackage ? 470.0 : min($coveredTotal, 470.0);
        $net = max(0.0, $coveredDue + $excludedTotal);
        $discountAmount = max(0.0, round($gross - $net, 2));

        return [
            'gross_amount' => number_format($gross, 2, '.', ''),
            'discount_rate' => '0.00',
            'discount_amount' => number_format($discountAmount, 2, '.', ''),
            'net_amount' => number_format($net, 2, '.', ''),
            'discount_reasons' => ['Yakap Member - PHP 470 blood chemistry package; excludes SGOT, SGPT, Blood Uric Acid, and BUN'],
        ];
    }

    $discount = getLabDiscountInfo($patient);
    $discountAmount = round($gross * (float)$discount['rate'], 2);
    $net = max(0.0, $gross - $discountAmount);

    return [
        'gross_amount' => number_format($gross, 2, '.', ''),
        'discount_rate' => number_format((float)$discount['rate'], 2, '.', ''),
        'discount_amount' => number_format($discountAmount, 2, '.', ''),
        'net_amount' => number_format($net, 2, '.', ''),
        'discount_reasons' => $discount['reasons'],
    ];
}

function parseResultValueJson($value) {
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function getSelectedBloodChemKeysFromResult($row) {
    $parsed = parseResultValueJson($row['result_value'] ?? '');
    return normalizeBloodChemSelection($parsed['selected_tests'] ?? []);
}

function removeYakapExcludedBloodChemPanelValues($resultValue, $selectedKeys, $patient) {
    if ((int)($patient['yakap_member'] ?? 0) !== 1 || !in_array('package', $selectedKeys, true)) {
        return $resultValue;
    }

    $parsed = parseResultValueJson($resultValue);
    if (!isset($parsed['panel']) || !is_array($parsed['panel'])) {
        return $resultValue;
    }

    $selected = array_flip($selectedKeys);
    foreach (getYakapExcludedBloodChemKeys() as $key) {
        if (!isset($selected[$key])) {
            unset($parsed['panel'][$key]);
        }
    }

    return json_encode($parsed);
}

function readEnvFileAssoc($path) {
    if (!is_file($path)) {
        return [];
    }

    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }

    return $env;
}

function getHrmisConnection() {
    static $hrmisConn = false;
    if ($hrmisConn !== false) {
        return $hrmisConn;
    }

    $env = readEnvFileAssoc(__DIR__ . '/../../HRMIS/API/.env');
    $host = $env['DB_HOST'] ?? '';
    $dbname = $env['DB_NAME'] ?? '';
    $username = $env['DB_USER'] ?? '';
    $password = $env['DB_PASS'] ?? '';

    if ($host === '' || $dbname === '' || $username === '') {
        $hrmisConn = null;
        return null;
    }

    try {
        $hrmisConn = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8", $username, $password);
        $hrmisConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $hrmisConn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $hrmisConn->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        return $hrmisConn;
    } catch (Throwable $e) {
        error_log('Unable to connect to HRMIS database for laboratory employee roster: ' . $e->getMessage());
        $hrmisConn = null;
        return null;
    }
}

function hrmisPayrollRosterSql($whereSql = '', $limitSql = '', $piidFilter = false) {
    $currentYear = (int)date('Y');
    $regularPiidSql = $piidFilter ? ' AND pp.PIID = :piid' : '';
    $casualPiidSql = $piidFilter ? ' AND pc.PIID = :piid' : '';
    $joPiidSql = $piidFilter ? ' AND jo.PIID = :piid' : '';
    $latestRegularPiidSql = $piidFilter ? ' AND PIID = :piid' : '';
    $latestCasualPiidSql = $piidFilter ? ' AND PIID = :piid' : '';
    $latestJoPiidSql = $piidFilter ? ' AND PIID = :piid' : '';
    $source = "
        SELECT pp.PIID, pp.ID_Num AS ID_NUM, tp.Name AS Department, pp.Position,
               pp.End_Date, pp.End_Num, pp.Date_Updated, pp.Year AS PayYear,
               0 AS IsCasual, 0 AS IsJo
        FROM tbl_syl_payroll_parent pp
        INNER JOIN (
            SELECT TID, MAX(Year) AS LatestYear
            FROM tbl_syl_payroll_parent
            WHERE isDeleted = 0 AND Quencina = 'whole' AND Year <= {$currentYear}{$latestRegularPiidSql}
            GROUP BY TID
        ) latest_pp ON latest_pp.TID = pp.TID AND latest_pp.LatestYear = pp.Year
        INNER JOIN tbl_template_payroll tp ON tp.TID = pp.TID AND tp.isDeleted = 0
        WHERE pp.isDeleted = 0 AND pp.Quencina = 'whole'{$regularPiidSql}

        UNION ALL

        SELECT pc.PIID, pc.ID_Num AS ID_NUM, tpc.Name AS Department, pc.Position,
               pc.End_Date, pc.End_Num, pc.Date_Updated, pc.Year AS PayYear,
               1 AS IsCasual, 0 AS IsJo
        FROM tbl_syl_payroll_parent_casual pc
        INNER JOIN (
            SELECT TID, MAX(Year) AS LatestYear
            FROM tbl_syl_payroll_parent_casual
            WHERE isDeleted = 0 AND Quencina = 'whole' AND Year <= {$currentYear}{$latestCasualPiidSql}
            GROUP BY TID
        ) latest_pc ON latest_pc.TID = pc.TID AND latest_pc.LatestYear = pc.Year
        INNER JOIN tbl_template_payroll_casual tpc ON tpc.TID = pc.TID AND tpc.isDeleted = 0
        WHERE pc.isDeleted = 0 AND pc.Quencina = 'whole'{$casualPiidSql}

        UNION ALL

        SELECT jo.PIID, NULL AS ID_NUM, COALESCE(NULLIF(jo.Office, ''), tjo.Name) AS Department,
               jo.Position, jo.Period_Date AS End_Date, 0 AS End_Num, jo.Date_Updated,
               YEAR(jo.Period_Date) AS PayYear, 0 AS IsCasual, 1 AS IsJo
        FROM tbl_syl_payroll_for_print_jo jo
        INNER JOIN (
            SELECT PIID, MAX(Period_Date) AS LatestPeriod
            FROM tbl_syl_payroll_for_print_jo
            WHERE isDeleted = 0{$latestJoPiidSql}
            GROUP BY PIID
        ) latest_jo ON latest_jo.PIID = jo.PIID AND latest_jo.LatestPeriod = jo.Period_Date
        LEFT JOIN tbl_templatepayroll_jo tjo ON tjo.TID = jo.TID AND COALESCE(tjo.isDeleted, 0) = 0
        WHERE jo.isDeleted = 0{$joPiidSql}
    ";

    return "
        SELECT *
        FROM (
            SELECT
                CAST(pi.PIID AS CHAR) AS piid,
                COALESCE(NULLIF(MIN(pi.ID_NUM), ''), NULLIF(MIN(emp_src.ID_NUM), '')) AS id_num,
                MIN(pi.SurName) AS surname,
                MIN(pi.FirstName) AS first_name,
                MIN(pi.MiddleName) AS middle_name,
                MIN(pi.NameExt) AS name_ext,
                MIN(pi.Sex) AS sex,
                MIN(pi.BirthDate) AS birthdate,
                SUBSTRING_INDEX(GROUP_CONCAT(emp_src.Department ORDER BY emp_src.IsJo DESC, emp_src.PayYear DESC, emp_src.End_Date DESC, emp_src.End_Num DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1) AS department,
                SUBSTRING_INDEX(GROUP_CONCAT(emp_src.Position ORDER BY emp_src.IsJo DESC, emp_src.PayYear DESC, emp_src.End_Date DESC, emp_src.End_Num DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1) AS position,
                MAX(emp_src.IsCasual) AS is_casual,
                MAX(emp_src.IsJo) AS is_jo,
                SUBSTRING_INDEX(GROUP_CONCAT(emp_src.Date_Updated ORDER BY emp_src.IsJo DESC, emp_src.PayYear DESC, emp_src.End_Date DESC, emp_src.End_Num DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1) AS source_snapshot_at,
                CASE
                    WHEN MAX(emp_src.IsJo) = 1 THEN 'Job Order'
                    WHEN MAX(emp_src.IsCasual) = 1 THEN 'Casual'
                    ELSE 'Permanent'
                END AS employee_status
            FROM ({$source}) emp_src
            INNER JOIN tblpersonalinformation pi ON pi.PIID = emp_src.PIID
            WHERE emp_src.PIID IS NOT NULL AND emp_src.PIID <> ''
            GROUP BY pi.PIID
        ) roster
        {$whereSql}
        ORDER BY surname ASC, first_name ASC, piid ASC
        {$limitSql}
    ";
}

function hrmisPayrollQuickSearchSql($limitSql = '') {
    $currentYear = (int)date('Y');
    $source = "
        SELECT
            pp.PIID,
            pp.ID_Num AS ID_NUM,
            tp.Name AS Department,
            pp.Position,
            pp.End_Date,
            pp.End_Num,
            pp.Date_Updated,
            pp.Year AS PayYear,
            0 AS IsCasual,
            0 AS IsJo,
            pi.SurName,
            pi.FirstName,
            pi.MiddleName,
            pi.NameExt,
            pi.Sex,
            pi.BirthDate,
            pi.ID_NUM AS PersonalIdNum
        FROM tbl_syl_payroll_parent pp
        INNER JOIN tbl_template_payroll tp ON tp.TID = pp.TID AND tp.isDeleted = 0
        INNER JOIN tblpersonalinformation pi ON pi.PIID = pp.PIID
        WHERE pp.isDeleted = 0
          AND pp.Quencina = 'whole'
          AND pp.Year <= {$currentYear}
          AND (
                pi.SurName LIKE :search OR pi.FirstName LIKE :search OR pi.MiddleName LIKE :search
                OR pi.PIID LIKE :search OR pi.ID_NUM LIKE :search OR pp.ID_Num LIKE :search
                OR tp.Name LIKE :search OR pp.Position LIKE :search
          )

        UNION ALL

        SELECT
            pc.PIID,
            pc.ID_Num AS ID_NUM,
            tpc.Name AS Department,
            pc.Position,
            pc.End_Date,
            pc.End_Num,
            pc.Date_Updated,
            pc.Year AS PayYear,
            1 AS IsCasual,
            0 AS IsJo,
            pi.SurName,
            pi.FirstName,
            pi.MiddleName,
            pi.NameExt,
            pi.Sex,
            pi.BirthDate,
            pi.ID_NUM AS PersonalIdNum
        FROM tbl_syl_payroll_parent_casual pc
        INNER JOIN tbl_template_payroll_casual tpc ON tpc.TID = pc.TID AND tpc.isDeleted = 0
        INNER JOIN tblpersonalinformation pi ON pi.PIID = pc.PIID
        WHERE pc.isDeleted = 0
          AND pc.Quencina = 'whole'
          AND pc.Year <= {$currentYear}
          AND (
                pi.SurName LIKE :search OR pi.FirstName LIKE :search OR pi.MiddleName LIKE :search
                OR pi.PIID LIKE :search OR pi.ID_NUM LIKE :search OR pc.ID_Num LIKE :search
                OR tpc.Name LIKE :search OR pc.Position LIKE :search
          )

        UNION ALL

        SELECT
            jo.PIID,
            NULL AS ID_NUM,
            COALESCE(NULLIF(jo.Office, ''), tjo.Name) AS Department,
            jo.Position,
            jo.Period_Date AS End_Date,
            0 AS End_Num,
            jo.Date_Updated,
            YEAR(jo.Period_Date) AS PayYear,
            0 AS IsCasual,
            1 AS IsJo,
            pi.SurName,
            pi.FirstName,
            pi.MiddleName,
            pi.NameExt,
            pi.Sex,
            pi.BirthDate,
            pi.ID_NUM AS PersonalIdNum
        FROM tbl_syl_payroll_for_print_jo jo
        LEFT JOIN tbl_templatepayroll_jo tjo ON tjo.TID = jo.TID AND COALESCE(tjo.isDeleted, 0) = 0
        INNER JOIN tblpersonalinformation pi ON pi.PIID = jo.PIID
        WHERE jo.isDeleted = 0
          AND (
                pi.SurName LIKE :search OR pi.FirstName LIKE :search OR pi.MiddleName LIKE :search
                OR pi.PIID LIKE :search OR pi.ID_NUM LIKE :search
                OR jo.Office LIKE :search OR tjo.Name LIKE :search OR jo.Position LIKE :search
          )
    ";

    return "
        SELECT
            CAST(PIID AS CHAR) AS piid,
            COALESCE(NULLIF(MIN(PersonalIdNum), ''), NULLIF(MIN(ID_NUM), '')) AS id_num,
            MIN(SurName) AS surname,
            MIN(FirstName) AS first_name,
            MIN(MiddleName) AS middle_name,
            MIN(NameExt) AS name_ext,
            MIN(Sex) AS sex,
            MIN(BirthDate) AS birthdate,
            SUBSTRING_INDEX(GROUP_CONCAT(Department ORDER BY IsJo DESC, PayYear DESC, End_Date DESC, End_Num DESC, Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1) AS department,
            SUBSTRING_INDEX(GROUP_CONCAT(Position ORDER BY IsJo DESC, PayYear DESC, End_Date DESC, End_Num DESC, Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1) AS position,
            MAX(IsCasual) AS is_casual,
            MAX(IsJo) AS is_jo,
            SUBSTRING_INDEX(GROUP_CONCAT(Date_Updated ORDER BY IsJo DESC, PayYear DESC, End_Date DESC, End_Num DESC, Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1) AS source_snapshot_at,
            CASE
                WHEN MAX(IsJo) = 1 THEN 'Job Order'
                WHEN MAX(IsCasual) = 1 THEN 'Casual'
                ELSE 'Permanent'
            END AS employee_status
        FROM ({$source}) matched
        WHERE PIID IS NOT NULL AND PIID <> ''
        GROUP BY PIID
        ORDER BY surname ASC, first_name ASC, piid ASC
        {$limitSql}
    ";
}

function formatHrmisEmployeeDisplayName($row) {
    $surname = normalizeString($row['surname'] ?? '');
    $first = normalizeString($row['first_name'] ?? '');
    $middle = normalizeString($row['middle_name'] ?? '');
    $ext = normalizeString($row['name_ext'] ?? '');
    $parts = [];
    if ($first !== '') $parts[] = $first;
    if ($middle !== '') $parts[] = strtoupper(substr($middle, 0, 1)) . '.';
    if ($ext !== '') $parts[] = $ext;
    return trim($surname . ($parts ? ', ' . implode(' ', $parts) : ''));
}

function normalizeHrmisEmployeeRow($row) {
    return [
        'piid' => (string)($row['piid'] ?? ''),
        'id_num' => (string)($row['id_num'] ?? ''),
        'surname' => (string)($row['surname'] ?? ''),
        'first_name' => (string)($row['first_name'] ?? ''),
        'middle_name' => (string)($row['middle_name'] ?? ''),
        'name_ext' => (string)($row['name_ext'] ?? ''),
        'sex' => (string)($row['sex'] ?? ''),
        'birthdate' => (string)($row['birthdate'] ?? ''),
        'department' => (string)($row['department'] ?? ''),
        'position' => (string)($row['position'] ?? ''),
        'employee_status' => (string)($row['employee_status'] ?? ''),
        'is_jo' => (int)($row['is_jo'] ?? 0),
        'is_casual' => (int)($row['is_casual'] ?? 0),
        'source_system' => 'hrmis_payroll',
        'source_snapshot_at' => normalizeDateTimeValue($row['source_snapshot_at'] ?? ''),
        'display_name' => formatHrmisEmployeeDisplayName($row),
    ];
}

function searchHrmisPayrollEmployees($search = '', $limit = 25, $offset = 0, $includeTotal = false, $maxLimit = 500) {
    $hrmis = getHrmisConnection();
    if (!$hrmis) {
        return ['rows' => [], 'total' => 0, 'available' => false];
    }

    $where = '';
    $params = [];
    if (normalizeString($search) !== '') {
        $where = "WHERE (
            surname LIKE :search OR first_name LIKE :search OR middle_name LIKE :search
            OR piid LIKE :search OR id_num LIKE :search OR department LIKE :search
            OR position LIKE :search OR employee_status LIKE :search
        )";
        $params[':search'] = '%' . normalizeString($search) . '%';
    }

    $maxLimit = max(1, min(10000, (int)$maxLimit));
    $limit = max(1, min($maxLimit, (int)$limit));
    $offset = max(0, (int)$offset);
    $searchTerm = normalizeString($search);
    $sql = $searchTerm !== ''
        ? hrmisPayrollQuickSearchSql("LIMIT {$limit} OFFSET {$offset}")
        : hrmisPayrollRosterSql($where, "LIMIT {$limit} OFFSET {$offset}");
    $stmt = $hrmis->prepare($sql);
    $stmt->execute($params);
    $rows = array_map('normalizeHrmisEmployeeRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
    $total = null;

    if ($includeTotal) {
        $countSql = "SELECT COUNT(*) FROM (" . hrmisPayrollRosterSql($where, '') . ") counted";
        $countStmt = $hrmis->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
    }

    return ['rows' => $rows, 'total' => $total, 'available' => true];
}

function getHrmisPayrollEmployeeByPiid($piid) {
    $piid = normalizeString($piid);
    if ($piid === '') {
        return null;
    }

    $hrmis = getHrmisConnection();
    if (!$hrmis) {
        return null;
    }

    $stmt = $hrmis->prepare(hrmisPayrollRosterSql('WHERE piid = :piid', 'LIMIT 1', true));
    $stmt->execute([':piid' => $piid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? normalizeHrmisEmployeeRow($row) : null;
}

function getLabEmployeeByPiid($conn, $piid) {
    $employee = getCachedLabEmployeeByPiid($conn, $piid);
    if ($employee) {
        return $employee;
    }
    return getHrmisPayrollEmployeeByPiid($piid);
}

function upsertLabPatientFromHrmisEmployee($conn, $employee) {
    $piid = normalizeString($employee['piid'] ?? '');
    $surname = normalizeString($employee['surname'] ?? '');
    $firstName = normalizeString($employee['first_name'] ?? '');
    if ($piid === '' || $surname === '' || $firstName === '') {
        return 0;
    }

    $sourceSnapshotAt = normalizeDateTimeValue($employee['source_snapshot_at'] ?? '');

    $stmt = $conn->prepare(
        "INSERT INTO tbl_lab_personal_info
            (piid, is_employee, surname, first_name, middle_name, name_ext, sex, birthdate, city, barangay, is_archived, source_system, source_snapshot_at, employee_roster_synced_at, updated_at)
         VALUES
            (:piid, 1, :surname, :first_name, :middle_name, :name_ext, :sex, :birthdate, '', '', 0, 'hrmis_payroll', :source_snapshot_at, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            is_employee = 1,
            surname = VALUES(surname),
            first_name = VALUES(first_name),
            middle_name = VALUES(middle_name),
            name_ext = VALUES(name_ext),
            sex = VALUES(sex),
            birthdate = VALUES(birthdate),
            is_archived = 0,
            source_system = VALUES(source_system),
            source_snapshot_at = VALUES(source_snapshot_at),
            employee_roster_synced_at = VALUES(employee_roster_synced_at),
            updated_at = NOW()"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':surname' => $surname,
        ':first_name' => $firstName,
        ':middle_name' => normalizeString($employee['middle_name'] ?? '') ?: null,
        ':name_ext' => normalizeString($employee['name_ext'] ?? '') ?: null,
        ':sex' => normalizeString($employee['sex'] ?? ''),
        ':birthdate' => normalizeDateOnly($employee['birthdate'] ?? '') ?: null,
        ':source_snapshot_at' => $sourceSnapshotAt,
    ]);

    $find = $conn->prepare('SELECT lab_patient_id FROM tbl_lab_personal_info WHERE piid = :piid LIMIT 1');
    $find->execute([':piid' => $piid]);
    return (int)($find->fetchColumn() ?: 0);
}

function getLabPatientsByPiids($conn, $piids) {
    $piids = array_values(array_unique(array_filter(array_map('normalizeString', $piids))));
    if (empty($piids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare("SELECT * FROM tbl_lab_personal_info WHERE piid IN ({$placeholders})");
    $stmt->execute($piids);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string)$row['piid']] = $row;
    }
    return $map;
}

function ensureLabEmployeeRosterCacheTable($conn) {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!$conn) {
        $ready = false;
        return false;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_lab_employee_roster_cache (
            piid VARCHAR(64) NOT NULL,
            id_num VARCHAR(80) NULL,
            surname VARCHAR(100) NOT NULL DEFAULT '',
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            middle_name VARCHAR(100) NULL,
            name_ext VARCHAR(20) NULL,
            sex VARCHAR(10) NOT NULL DEFAULT '',
            birthdate DATE NULL,
            department VARCHAR(150) NULL,
            position VARCHAR(150) NULL,
            employee_status VARCHAR(40) NULL,
            is_jo TINYINT(1) NOT NULL DEFAULT 0,
            is_casual TINYINT(1) NOT NULL DEFAULT 0,
            source_system VARCHAR(40) NOT NULL DEFAULT 'hrmis_payroll',
            source_snapshot_at DATETIME NULL,
            cached_at DATETIME NOT NULL,
            PRIMARY KEY (piid),
            KEY idx_lab_emp_name (surname, first_name),
            KEY idx_lab_emp_id_num (id_num),
            KEY idx_lab_emp_cached_at (cached_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to ensure lab employee roster cache table: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

function normalizeCachedEmployeeRow($row) {
    $row = [
        'piid' => (string)($row['piid'] ?? ''),
        'id_num' => (string)($row['id_num'] ?? ''),
        'surname' => (string)($row['surname'] ?? ''),
        'first_name' => (string)($row['first_name'] ?? ''),
        'middle_name' => (string)($row['middle_name'] ?? ''),
        'name_ext' => (string)($row['name_ext'] ?? ''),
        'sex' => (string)($row['sex'] ?? ''),
        'birthdate' => (string)($row['birthdate'] ?? ''),
        'department' => (string)($row['department'] ?? ''),
        'position' => (string)($row['position'] ?? ''),
        'employee_status' => (string)($row['employee_status'] ?? ''),
        'is_jo' => (int)($row['is_jo'] ?? 0),
        'is_casual' => (int)($row['is_casual'] ?? 0),
        'source_system' => (string)($row['source_system'] ?? 'hrmis_payroll'),
        'source_snapshot_at' => (string)($row['source_snapshot_at'] ?? ''),
        'cached_at' => (string)($row['cached_at'] ?? ''),
    ];
    $row['display_name'] = formatHrmisEmployeeDisplayName($row);
    return $row;
}

function searchCachedLabEmployees($conn, $search = '', $limit = 25, $offset = 0) {
    if (!ensureLabEmployeeRosterCacheTable($conn)) {
        return ['rows' => [], 'total' => null, 'available' => false, 'cache_count' => 0];
    }

    $search = normalizeString($search);
    $limit = max(1, min(500, (int)$limit));
    $offset = max(0, (int)$offset);
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = "WHERE surname LIKE :search OR first_name LIKE :search OR middle_name LIKE :search
                  OR piid LIKE :search OR id_num LIKE :search OR department LIKE :search
                  OR position LIKE :search OR employee_status LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }

    $stmt = $conn->prepare(
        "SELECT * FROM tbl_lab_employee_roster_cache
         {$where}
         ORDER BY surname ASC, first_name ASC, piid ASC
         LIMIT {$limit} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $rows = array_map('normalizeCachedEmployeeRow', $stmt->fetchAll(PDO::FETCH_ASSOC));

    return ['rows' => $rows, 'total' => null, 'available' => true];
}

function getCachedLabEmployeeByPiid($conn, $piid) {
    $piid = normalizeString($piid);
    if ($piid === '' || !ensureLabEmployeeRosterCacheTable($conn)) {
        return null;
    }

    $stmt = $conn->prepare('SELECT * FROM tbl_lab_employee_roster_cache WHERE piid = :piid LIMIT 1');
    $stmt->execute([':piid' => $piid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? normalizeCachedEmployeeRow($row) : null;
}

function refreshLabEmployeeRosterCache($conn, $limit = 0, $batchSize = 1000) {
    if (!ensureLabEmployeeRosterCacheTable($conn)) {
        return ['refreshed' => 0, 'available' => false, 'batches' => 0, 'pruned' => 0];
    }

    $limit = max(0, min(50000, (int)$limit));
    $batchSize = max(100, min(2000, (int)$batchSize));
    $maxRecords = $limit > 0 ? $limit : 50000;
    $syncMarker = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "INSERT INTO tbl_lab_employee_roster_cache (
            piid, id_num, surname, first_name, middle_name, name_ext, sex, birthdate,
            department, position, employee_status, is_jo, is_casual, source_system,
            source_snapshot_at, cached_at
        ) VALUES (
            :piid, :id_num, :surname, :first_name, :middle_name, :name_ext, :sex, :birthdate,
            :department, :position, :employee_status, :is_jo, :is_casual, :source_system,
            :source_snapshot_at, :cached_at
        )
        ON DUPLICATE KEY UPDATE
            id_num = VALUES(id_num),
            surname = VALUES(surname),
            first_name = VALUES(first_name),
            middle_name = VALUES(middle_name),
            name_ext = VALUES(name_ext),
            sex = VALUES(sex),
            birthdate = VALUES(birthdate),
            department = VALUES(department),
            position = VALUES(position),
            employee_status = VALUES(employee_status),
            is_jo = VALUES(is_jo),
            is_casual = VALUES(is_casual),
            source_system = VALUES(source_system),
            source_snapshot_at = VALUES(source_snapshot_at),
            cached_at = VALUES(cached_at)"
    );

    $refreshed = 0;
    $batches = 0;
    for ($offset = 0; $offset < $maxRecords; $offset += $batchSize) {
        $remaining = $maxRecords - $offset;
        $pageSize = min($batchSize, $remaining);
        $payroll = searchHrmisPayrollEmployees('', $pageSize, $offset, false, $pageSize);
        if (!$payroll['available']) {
            return ['refreshed' => $refreshed, 'available' => false, 'batches' => $batches, 'pruned' => 0];
        }
        if (empty($payroll['rows'])) {
            break;
        }

        foreach ($payroll['rows'] as $employee) {
            $stmt->execute([
                ':piid' => $employee['piid'],
                ':id_num' => $employee['id_num'] ?: null,
                ':surname' => $employee['surname'],
                ':first_name' => $employee['first_name'],
                ':middle_name' => $employee['middle_name'] ?: null,
                ':name_ext' => $employee['name_ext'] ?: null,
                ':sex' => $employee['sex'],
                ':birthdate' => normalizeDateOnly($employee['birthdate'] ?? '') ?: null,
                ':department' => $employee['department'] ?: null,
                ':position' => $employee['position'] ?: null,
                ':employee_status' => $employee['employee_status'] ?: null,
                ':is_jo' => (int)$employee['is_jo'],
                ':is_casual' => (int)$employee['is_casual'],
                ':source_system' => $employee['source_system'] ?: 'hrmis_payroll',
                ':source_snapshot_at' => normalizeDateTimeValue($employee['source_snapshot_at'] ?? ''),
                ':cached_at' => $syncMarker,
            ]);
            $refreshed++;
        }
        $batches++;

        if (count($payroll['rows']) < $pageSize || $refreshed >= $maxRecords) {
            break;
        }
    }

    $pruned = 0;
    if ($limit === 0 && $refreshed > 0) {
        $pruneStmt = $conn->prepare('DELETE FROM tbl_lab_employee_roster_cache WHERE cached_at <> :sync_marker');
        $pruneStmt->execute([':sync_marker' => $syncMarker]);
        $pruned = $pruneStmt->rowCount();
    }

    return ['refreshed' => $refreshed, 'available' => true, 'batches' => $batches, 'pruned' => $pruned, 'complete' => $limit === 0];
}

function requireLabAccess($userData) {
    if (!userHasModuleAccess($userData, 'subsystem_laboratory')) {
        sendResponse('error', 'Laboratory access denied.', null, 403);
    }
}

function requireLabAdmin($userData) {
    requireLabAccess($userData);
    $roleKey = strtolower(trim((string)($userData['role'] ?? 'user')));
    $isAdmin = ($roleKey === 'super_admin' || $roleKey === 'superadmin' || $roleKey === 'admin' || $roleKey === 'administrator');
    if (!$isAdmin) {
        sendResponse('error', 'Administrator access required for this action.', null, 403);
    }
}

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

requireLabAccess($userData);

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}

$personMeta = getPersonColumns($conn);

if (!ensureLaboratoryResultsTable($conn)) {
    sendResponse('error', 'Laboratory table is unavailable.', null, 500);
}

if (!ensureLabPersonalInfoTable($conn)) {
    sendResponse('error', 'Laboratory patient info table is unavailable.', null, 500);
}

if (!ensureLabEmployeeRosterCacheTable($conn)) {
    sendResponse('error', 'Laboratory employee roster cache is unavailable.', null, 500);
}

if (!ensureLabFeesTable($conn)) {
    sendResponse('error', 'Laboratory fees table is unavailable.', null, 500);
}

if (!ensureLabOrgChartTable($conn)) {
    sendResponse('error', 'Laboratory organizational chart table is unavailable.', null, 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = normalizeString($_GET['action'] ?? 'person_search');

    if ($action === 'blood_chem_charge_items') {
        $fees = loadLabFees($conn);
        $details = buildBloodChemSelectionDetails(array_column(getBloodChemChargeItems(), 'key'), $fees, normalizeDateOnly($_GET['request_date'] ?? '') ?: null);
        sendResponse('success', 'Blood chemistry charge items loaded.', $details['selected_tests']);
    }

    if ($action === 'person_search') {
        $search = normalizeString($_GET['search'] ?? '');
        $patientType = normalizePatientType($_GET['patient_type'] ?? 'citizen');
        $isEmployeeFilter = $patientType === 'employee' ? 1 : 0;
        if ($search === '' || strlen($search) < 2) {
            sendResponse('success', 'No search term.', [], 200, ['count' => 0]);
        }

        if ($patientType === 'employee') {
            $payroll = searchCachedLabEmployees($conn, $search, 25, 0);
            if ($payroll['available']) {
                $labByPiid = getLabPatientsByPiids($conn, array_column($payroll['rows'], 'piid'));
                $results = [];
                $seenPiids = [];
                foreach ($payroll['rows'] as $employee) {
                    $labRow = $labByPiid[$employee['piid']] ?? null;
                    $seenPiids[(string)$employee['piid']] = true;
                    $results[] = [
                        'lab_patient_id' => $labRow ? (int)($labRow['lab_patient_id'] ?? 0) : 0,
                        'PIID' => $employee['piid'],
                        'is_employee' => 1,
                        'Surname' => $employee['surname'],
                        'FirstName' => $employee['first_name'],
                        'MiddleName' => $employee['middle_name'],
                        'NameExt' => $employee['name_ext'],
                        'display_name' => $employee['display_name'],
                        'department' => $employee['department'],
                        'position' => $employee['position'],
                        'employee_status' => $employee['employee_status'],
                        'source' => 'HRMIS Payroll',
                        'source_system' => $employee['source_system'],
                        'source_snapshot_at' => $employee['source_snapshot_at'],
                        'employee_roster_synced_at' => (string)($labRow['employee_roster_synced_at'] ?? ''),
                    ];
                }

                $likeSearch = '%' . $search . '%';
                $manualStmt = $conn->prepare(
                    "SELECT lab_patient_id, piid, is_employee, surname, first_name, middle_name, name_ext
                     FROM tbl_lab_personal_info
                     WHERE is_employee = 1
                       AND COALESCE(is_archived, 0) = 0
                       AND COALESCE(source_system, '') = 'laboratory_manual'
                       AND (
                            surname    LIKE :search
                         OR first_name LIKE :search
                         OR middle_name LIKE :search
                         OR piid       LIKE :search
                       )
                     ORDER BY surname ASC, first_name ASC
                     LIMIT 25"
                );
                $manualStmt->execute([':search' => $likeSearch]);
                foreach ($manualStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $manualPiid = (string)($row['piid'] ?? '');
                    if ($manualPiid !== '' && isset($seenPiids[$manualPiid])) {
                        continue;
                    }
                    $results[] = [
                        'lab_patient_id' => (int)($row['lab_patient_id'] ?? 0),
                        'PIID' => $manualPiid,
                        'is_employee' => 1,
                        'Surname' => (string)($row['surname'] ?? ''),
                        'FirstName' => (string)($row['first_name'] ?? ''),
                        'MiddleName' => (string)($row['middle_name'] ?? ''),
                        'NameExt' => (string)($row['name_ext'] ?? ''),
                        'display_name' => formatLabPatientDisplayName($row),
                        'department' => '',
                        'position' => '',
                        'employee_status' => 'Manual temporary record',
                        'source' => 'Laboratory Manual',
                        'source_system' => 'laboratory_manual',
                        'source_snapshot_at' => '',
                        'employee_roster_synced_at' => '',
                    ];
                }

                sendResponse('success', 'Payroll employee search results.', $results, 200, [
                    'count' => count($results),
                    'total' => $payroll['total'],
                    'patient_type' => $patientType,
                    'source' => empty($payroll['rows']) ? 'employee_roster_cache' : (string)($payroll['rows'][0]['source_system'] ?? 'employee_roster_cache')
                ]);
            }
        }

        $likeSearch = '%' . $search . '%';

        $labStmt = $conn->prepare(
            "SELECT lab_patient_id, piid, is_employee, surname, first_name, middle_name, name_ext
             FROM tbl_lab_personal_info
             WHERE is_employee = :is_employee
               AND COALESCE(is_archived, 0) = 0
               AND (
                    surname    LIKE :search
                 OR first_name LIKE :search
                 OR middle_name LIKE :search
                 OR piid       LIKE :search
               )
             ORDER BY surname ASC, first_name ASC
             LIMIT 25"
        );
        $labStmt->execute([':is_employee' => $isEmployeeFilter, ':search' => $likeSearch]);
        $results = [];
        foreach ($labStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'lab_patient_id' => (int)($row['lab_patient_id'] ?? 0),
                'PIID'         => (string)($row['piid'] ?? ''),
                'is_employee'  => (int)($row['is_employee'] ?? 0),
                'Surname'      => (string)($row['surname'] ?? ''),
                'FirstName'    => (string)($row['first_name'] ?? ''),
                'MiddleName'   => (string)($row['middle_name'] ?? ''),
                'NameExt'      => (string)($row['name_ext'] ?? ''),
                'display_name' => formatLabPatientDisplayName($row),
            ];
        }

        sendResponse('success', 'Laboratory patient search results.', $results, 200, [
            'count' => count($results),
            'patient_type' => $patientType
        ]);
    }

    if ($action === 'patient_results') {
        $piid = normalizeString($_GET['piid'] ?? '');
        $labPatientIdParam = (int)($_GET['lab_patient_id'] ?? 0);
        $patientType = normalizePatientType($_GET['patient_type'] ?? 'citizen');
        $isEmployeeFilter = $patientType === 'employee' ? 1 : 0;
        if ($piid === '' && $labPatientIdParam <= 0) {
            sendResponse('error', 'PIID or laboratory patient ID is required.', null, 400);
        }

        $labPatient = $labPatientIdParam > 0 ? getLabPatientById($conn, $labPatientIdParam) : getLabPatientByPiid($conn, $piid);
        if ($patientType === 'employee' && (!$labPatient || (int)($labPatient['is_archived'] ?? 0) === 1)) {
            $employee = getLabEmployeeByPiid($conn, $piid);
            if ($employee) {
                $syncedId = upsertLabPatientFromHrmisEmployee($conn, $employee);
                if ($syncedId > 0) {
                    $labPatient = getLabPatientById($conn, $syncedId);
                }
            }
        }
        if (!$labPatient || (int)($labPatient['is_employee'] ?? 0) !== $isEmployeeFilter || (int)($labPatient['is_archived'] ?? 0) === 1) {
            sendResponse('error', ucfirst($patientType) . ' record not found in laboratory database.', null, 404);
        }
        $piid = (string)($labPatient['piid'] ?? '');
        if ($piid === '') {
            $piid = generateLabPatientPiid((int)($labPatient['lab_patient_id'] ?? 0), $isEmployeeFilter);
            $upd = $conn->prepare('UPDATE tbl_lab_personal_info SET piid = :piid WHERE lab_patient_id = :id AND (piid IS NULL OR piid = "")');
            $upd->execute([':piid' => $piid, ':id' => (int)$labPatient['lab_patient_id']]);
            $labPatient['piid'] = $piid;
        }
        $patient = [
            'lab_patient_id' => (int)($labPatient['lab_patient_id'] ?? 0),
            'piid'         => $piid,
            'is_employee'  => (int)($labPatient['is_employee'] ?? 0),
            'surname'      => (string)($labPatient['surname'] ?? ''),
            'first_name'   => (string)($labPatient['first_name'] ?? ''),
            'middle_name'  => (string)($labPatient['middle_name'] ?? ''),
            'name_ext'     => (string)($labPatient['name_ext'] ?? ''),
            'display_name' => formatLabPatientDisplayName($labPatient),
            'sex'          => (string)($labPatient['sex']      ?? ''),
            'birthdate'    => (string)($labPatient['birthdate'] ?? ''),
            'barangay'     => (string)($labPatient['barangay'] ?? ''),
            'city'         => (string)($labPatient['city']     ?? ''),
            'yakap_member' => (int)($labPatient['yakap_member'] ?? 0),
            'yakap_id_number' => (string)($labPatient['yakap_id_number'] ?? ''),
            'senior' => (int)($labPatient['senior'] ?? 0),
            'senior_id_number' => (string)($labPatient['senior_id_number'] ?? ''),
            'pwd' => (int)($labPatient['pwd'] ?? 0),
            'pwd_id_number' => (string)($labPatient['pwd_id_number'] ?? ''),
            'source_system' => (string)($labPatient['source_system'] ?? ''),
            'source_snapshot_at' => (string)($labPatient['source_snapshot_at'] ?? ''),
            'employee_roster_synced_at' => (string)($labPatient['employee_roster_synced_at'] ?? ''),
        ];

        $stmt = $conn->prepare(
            "SELECT
                result_id,
                piid,
                lab_patient_id,
                test_name,
                test_type,
                is_employee,
                request_date,
                result_summary,
                result_value,
                status,
                result_date,
                remarks,
                or_number,
                payment_required,
                created_by,
                updated_by,
                created_at,
                updated_at,
                verified_by,
                verified_at,
                released_by,
                released_at,
                amends_result_id,
                amended_by_result_id
             FROM tbl_laboratory_results
             WHERE is_deleted = 0
               AND (
                    lab_patient_id = :lab_patient_id
                    OR (lab_patient_id IS NULL AND piid = :piid AND is_employee = :is_employee)
               )
             ORDER BY COALESCE(result_date, request_date) DESC, result_id DESC"
        );
        $stmt->execute([
            ':lab_patient_id' => (int)($labPatient['lab_patient_id'] ?? 0),
            ':piid' => $piid,
            ':is_employee' => $isEmployeeFilter,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fees = loadLabFees($conn);
        foreach ($rows as &$row) {
            $row['status']     = normalizeStatus($row['status'] ?? 'PROCESSING');
            if (strtoupper((string)($row['test_type'] ?? '')) === 'BLOOD_CHEM') {
                $selection = buildBloodChemSelectionDetails(getSelectedBloodChemKeysFromResult($row), $fees, $row['request_date'] ?? null);
                $row['selected_tests'] = $selection['selected_tests'];
                $discounted = applyLabDiscount($selection['total_amount'], $labPatient, $selection['selected_tests']);
                $row['gross_amount'] = $discounted['gross_amount'];
                $row['discount_rate'] = $discounted['discount_rate'];
                $row['discount_amount'] = $discounted['discount_amount'];
                $row['discount_reasons'] = $discounted['discount_reasons'];
                $row['fee_amount'] = $discounted['net_amount'];
            } else {
                $feeAmt = lookupFee($fees, $row['test_type'] ?? '', $row['test_name'] ?? '', $row['request_date'] ?? null);
                $discounted = applyLabDiscount($feeAmt !== null ? $feeAmt : null, $labPatient);
                $row['gross_amount'] = $discounted['gross_amount'];
                $row['discount_rate'] = $discounted['discount_rate'];
                $row['discount_amount'] = $discounted['discount_amount'];
                $row['discount_reasons'] = $discounted['discount_reasons'];
                $row['fee_amount'] = $discounted['net_amount'];
            }
        }
        unset($row);

        sendResponse('success', 'Patient laboratory records loaded.', [
            'patient' => $patient,
            'results' => $rows
        ], 200, [
            'count' => count($rows)
        ]);
    }

    if ($action === 'result_print') {
        $resultId = (int)($_GET['result_id'] ?? 0);
        if ($resultId <= 0) {
            sendResponse('error', 'Result ID is required.', null, 400);
        }

        $stmt = $conn->prepare(
            "SELECT
                result_id,
                piid,
                test_name,
                test_type,
                is_employee,
                request_date,
                result_summary,
                result_value,
                status,
                result_date,
                remarks,
                or_number,
                payment_required,
                created_by,
                updated_by,
                created_at,
                updated_at,
                verified_by,
                verified_at,
                released_by,
                released_at,
                amends_result_id,
                amended_by_result_id
             FROM tbl_laboratory_results
             WHERE result_id = :result_id
               AND is_deleted = 0
             LIMIT 1"
        );
        $stmt->execute([':result_id' => $resultId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            sendResponse('error', 'Result not found.', null, 404);
        }

        $resultPiid = (string)($row['piid'] ?? '');
        $row['status'] = normalizeStatus($row['status'] ?? 'PROCESSING');
        $selection = null;
        if (strtoupper((string)($row['test_type'] ?? '')) === 'BLOOD_CHEM') {
            $selection = buildBloodChemSelectionDetails(getSelectedBloodChemKeysFromResult($row), loadLabFees($conn), $row['request_date'] ?? null);
            $row['selected_tests'] = $selection['selected_tests'];
            $row['fee_amount'] = $selection['total_amount'];
        }

        // Prefer lab-owned personal info; fall back to tbl_personal_details
        $labPatient = getLabPatientByPiid($conn, $resultPiid);
        if ($labPatient) {
            $patientInfo = [
                'piid'         => $resultPiid,
                'display_name' => formatLabPatientDisplayName($labPatient),
                'sex'          => (string)($labPatient['sex']      ?? ''),
                'birthdate'    => (string)($labPatient['birthdate'] ?? ''),
                'barangay'     => (string)($labPatient['barangay'] ?? ''),
                'city'         => (string)($labPatient['city']     ?? ''),
            ];
        } else {
            if (!$personMeta) {
                sendResponse('error', 'Patient details for this legacy result are unavailable.', null, 404);
            }
            $person = getPersonByPiid($conn, $resultPiid, $personMeta);
            if (!$person) {
                sendResponse('error', 'Citizen record not found for this result.', null, 404);
            }
            $patientInfo = [
                'piid'         => $resultPiid,
                'display_name' => formatPersonName($person, $personMeta),
                'sex'          => $personMeta['sex']      ? (string)($person[$personMeta['sex']]      ?? '') : '',
                'birthdate'    => $personMeta['birthdate'] ? (string)($person[$personMeta['birthdate']] ?? '') : '',
                'barangay'     => $personMeta['barangay'] ? (string)($person[$personMeta['barangay']] ?? '') : '',
                'city'         => $personMeta['city']     ? (string)($person[$personMeta['city']]     ?? '') : '',
            ];
        }
        if ($selection !== null) {
            $discounted = applyLabDiscount($selection['total_amount'], $labPatient ?: [], $selection['selected_tests']);
            $row['gross_amount'] = $discounted['gross_amount'];
            $row['discount_rate'] = $discounted['discount_rate'];
            $row['discount_amount'] = $discounted['discount_amount'];
            $row['discount_reasons'] = $discounted['discount_reasons'];
            $row['fee_amount'] = $discounted['net_amount'];
        }

        sendResponse('success', 'Printable result loaded.', [
            'result'  => $row,
            'patient' => $patientInfo,
        ]);
    }

    if ($action === 'dashboard') {
        if (!$personMeta) {
            sendResponse('error', 'Personal details table is unavailable for dashboard legacy joins.', null, 500);
        }
        $totalsStmt = $conn->query(
            "SELECT
                SUM(CASE WHEN UPPER(status) != 'CANCELLED' THEN 1 ELSE 0 END) AS total_results,
                COUNT(DISTINCT CASE WHEN UPPER(status) != 'CANCELLED' THEN piid END) AS total_patients,
                SUM(CASE WHEN UPPER(status) = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN UPPER(status) = 'PROCESSING' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN UPPER(status) = 'READY_FOR_RELEASE' THEN 1 ELSE 0 END) AS ready_for_release_count,
                SUM(CASE WHEN UPPER(status) = 'RELEASED' THEN 1 ELSE 0 END) AS released_count,
                SUM(CASE WHEN UPPER(status) = 'CANCELLED' THEN 1 ELSE 0 END) AS cancelled_count,
                SUM(CASE WHEN UPPER(status) != 'CANCELLED'
                         AND DATE_FORMAT(COALESCE(result_date, request_date), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
                         THEN 1 ELSE 0 END) AS this_month_count
             FROM tbl_laboratory_results
             WHERE is_deleted = 0"
        );
        $totals = $totalsStmt ? ($totalsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $piidJoinCol     = 'p.' . quoteIdentifier($personMeta['piid']);
        $pSexCol         = $personMeta['sex']      ? 'p.' . quoteIdentifier($personMeta['sex'])      : 'NULL';
        $pBirthdateCol   = $personMeta['birthdate'] ? 'p.' . quoteIdentifier($personMeta['birthdate']) : 'NULL';

        // COALESCE: lab-owned data takes priority over tbl_personal_details
        $sexSelect      = "COALESCE(lpi.sex,      {$pSexCol})      AS sex_value";
        $birthdateSelect = "COALESCE(lpi.birthdate, {$pBirthdateCol}) AS birthdate_value";

        $demoStmt = $conn->query(
            "SELECT
                SUM(CASE WHEN UPPER(TRIM(sex_value)) IN ('F', 'FEMALE') THEN 1 ELSE 0 END) AS female_count,
                SUM(CASE WHEN UPPER(TRIM(sex_value)) IN ('M', 'MALE') THEN 1 ELSE 0 END) AS male_count,
                SUM(CASE WHEN sex_value IS NULL OR TRIM(sex_value) = '' OR UPPER(TRIM(sex_value)) NOT IN ('F', 'FEMALE', 'M', 'MALE') THEN 1 ELSE 0 END) AS other_sex_count,
                SUM(CASE WHEN birthdate_value IS NULL OR birthdate_value = '' OR birthdate_value = '0000-00-00' THEN 1 ELSE 0 END) AS age_unknown_count,
                SUM(CASE WHEN birthdate_value IS NOT NULL AND birthdate_value <> '' AND birthdate_value <> '0000-00-00' AND TIMESTAMPDIFF(YEAR, birthdate_value, CURDATE()) BETWEEN 0 AND 17 THEN 1 ELSE 0 END) AS age_child_count,
                SUM(CASE WHEN birthdate_value IS NOT NULL AND birthdate_value <> '' AND birthdate_value <> '0000-00-00' AND TIMESTAMPDIFF(YEAR, birthdate_value, CURDATE()) BETWEEN 18 AND 59 THEN 1 ELSE 0 END) AS age_adult_count,
                SUM(CASE WHEN birthdate_value IS NOT NULL AND birthdate_value <> '' AND birthdate_value <> '0000-00-00' AND TIMESTAMPDIFF(YEAR, birthdate_value, CURDATE()) >= 60 THEN 1 ELSE 0 END) AS age_senior_count
             FROM (
                SELECT DISTINCT
                    r.piid,
                    {$sexSelect},
                    {$birthdateSelect}
                FROM tbl_laboratory_results r
                LEFT JOIN tbl_lab_personal_info lpi ON lpi.lab_patient_id = r.lab_patient_id
                LEFT JOIN tbl_personal_details  p   ON {$piidJoinCol}     = r.piid
                WHERE r.is_deleted = 0 AND UPPER(r.status) != 'CANCELLED'
             ) d"
        );
        $demo = $demoStmt ? ($demoStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $topTestsStmt = $conn->query(
            "SELECT
                test_name,
                COUNT(*) AS record_count
             FROM tbl_laboratory_results
             WHERE is_deleted = 0 AND UPPER(status) != 'CANCELLED'
             GROUP BY test_name
             ORDER BY record_count DESC, test_name ASC
             LIMIT 6"
        );
        $topTests = $topTestsStmt ? $topTestsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $readyListStmt = $conn->query(
            "SELECT
                r.result_id,
                r.piid,
                CONCAT(
                    COALESCE(lpi.surname, p." . quoteIdentifier($personMeta['surname']) . "),
                    ', ',
                    COALESCE(lpi.first_name, p." . quoteIdentifier($personMeta['firstname']) . "),
                    ' ',
                    IFNULL(CONCAT(NULLIF(LEFT(COALESCE(lpi.middle_name" . ($personMeta['middlename'] ? ", p." . quoteIdentifier($personMeta['middlename']) : "") . ", ''), 1), ''), '. '), '')
                ) AS display_name,
                r.test_name,
                r.request_date,
                r.result_date
             FROM tbl_laboratory_results r
             LEFT JOIN tbl_lab_personal_info lpi ON lpi.lab_patient_id = r.lab_patient_id
             LEFT JOIN tbl_personal_details p ON p." . quoteIdentifier($personMeta['piid']) . " = r.piid
             WHERE r.is_deleted = 0
               AND UPPER(r.status) = 'READY_FOR_RELEASE'
             ORDER BY COALESCE(r.result_date, r.request_date) ASC, r.result_id ASC
             LIMIT 12"
        );
        $readyList = $readyListStmt ? $readyListStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $trendStmt = $conn->query(
            "SELECT
                DATE_FORMAT(COALESCE(result_date, request_date), '%Y-%m') AS ym,
                COUNT(*) AS record_count
             FROM tbl_laboratory_results
             WHERE is_deleted = 0
               AND UPPER(status) != 'CANCELLED'
               AND COALESCE(result_date, request_date) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
             GROUP BY DATE_FORMAT(COALESCE(result_date, request_date), '%Y-%m')
             ORDER BY ym ASC"
        );
        $trendRows = $trendStmt ? $trendStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $trendMap = [];
        foreach ($trendRows as $row) {
            $key = (string)($row['ym'] ?? '');
            if ($key !== '') {
                $trendMap[$key] = (int)($row['record_count'] ?? 0);
            }
        }

        $monthly = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts = strtotime("-{$i} month");
            $ym = date('Y-m', $ts);
            $monthly[] = [
                'month' => $ym,
                'label' => date('M Y', $ts),
                'record_count' => (int)($trendMap[$ym] ?? 0)
            ];
        }

        sendResponse('success', 'Dashboard data loaded.', [
            'totals' => [
                'total_results' => (int)($totals['total_results'] ?? 0),
                'total_patients' => (int)($totals['total_patients'] ?? 0),
                'pending_count' => (int)($totals['pending_count'] ?? 0),
                'processing_count' => (int)($totals['processing_count'] ?? 0),
                'ready_for_release_count' => (int)($totals['ready_for_release_count'] ?? 0),
                'released_count' => (int)($totals['released_count'] ?? 0),
                'cancelled_count' => (int)($totals['cancelled_count'] ?? 0),
                'this_month_count' => (int)($totals['this_month_count'] ?? 0)
            ],
            'demographics' => [
                'female_count' => (int)($demo['female_count'] ?? 0),
                'male_count' => (int)($demo['male_count'] ?? 0),
                'other_sex_count' => (int)($demo['other_sex_count'] ?? 0),
                'age_child_count' => (int)($demo['age_child_count'] ?? 0),
                'age_adult_count' => (int)($demo['age_adult_count'] ?? 0),
                'age_senior_count' => (int)($demo['age_senior_count'] ?? 0),
                'age_unknown_count' => (int)($demo['age_unknown_count'] ?? 0)
            ],
            'ready_for_release' => $readyList,
            'top_tests' => $topTests,
            'monthly' => $monthly
        ], 200);
    }

    if ($action === 'processing_queue') {
        if (!$personMeta) {
            sendResponse('error', 'Personal details table is unavailable for processing queue joins.', null, 500);
        }
        $fromDate  = normalizeDateOnly($_GET['from'] ?? '');
        $toDate    = normalizeDateOnly($_GET['to'] ?? '');
        $search    = normalizeString($_GET['search'] ?? '');
        $limitRaw  = (int)($_GET['limit'] ?? 500);
        $offsetRaw = (int)($_GET['offset'] ?? 0);
        $limit     = max(1, min(1000, $limitRaw));
        $offset    = max(0, $offsetRaw);

        if ($fromDate === false || $toDate === false) {
            sendResponse('error', 'Invalid date format. Use YYYY-MM-DD.', null, 400);
        }

        if ($fromDate && $toDate && strtotime($fromDate) > strtotime($toDate)) {
            sendResponse('error', 'Invalid date range: from-date is later than to-date.', null, 400);
        }

        $piidCol     = quoteIdentifier($personMeta['piid']);
        $surnameCol  = quoteIdentifier($personMeta['surname']);
        $firstCol    = quoteIdentifier($personMeta['firstname']);
        $midCol      = $personMeta['middlename'] ? quoteIdentifier($personMeta['middlename']) : null;
        $sexCol      = $personMeta['sex']       ? quoteIdentifier($personMeta['sex'])       : null;
        $birthCol    = $personMeta['birthdate'] ? quoteIdentifier($personMeta['birthdate']) : null;
        $brgyCol     = $personMeta['barangay']  ? quoteIdentifier($personMeta['barangay'])  : null;
        $cityCol     = $personMeta['city']      ? quoteIdentifier($personMeta['city'])      : null;

        $nameExpr = "CONCAT(COALESCE(lpi.surname, p.{$surnameCol}), ', ', COALESCE(lpi.first_name, p.{$firstCol})" .
                    ", ' ', IFNULL(CONCAT(NULLIF(LEFT(COALESCE(lpi.middle_name" . ($midCol ? ", p.{$midCol}" : "") . ",''),1),''),'. '),'') " .
                    ")";

        $where  = ["r.is_deleted = 0", "UPPER(r.status) = 'PROCESSING'"];
        $params = [];

        if ($fromDate) {
            $where[] = "r.request_date >= :from_date";
            $params[':from_date'] = $fromDate;
        }
        if ($toDate) {
            $where[] = "r.request_date <= :to_date";
            $params[':to_date'] = $toDate;
        }
        if ($search !== '') {
            $where[] = "({$nameExpr} LIKE :search OR r.piid LIKE :search2 OR r.lab_patient_id LIKE :search3 OR r.test_name LIKE :search4)";
            $params[':search']  = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
            $params[':search3'] = '%' . $search . '%';
            $params[':search4'] = '%' . $search . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $totStmt = $conn->prepare(
            "SELECT COUNT(*) AS total_records
             FROM tbl_laboratory_results r
             LEFT JOIN tbl_lab_personal_info lpi ON lpi.lab_patient_id = r.lab_patient_id
             LEFT JOIN tbl_personal_details p ON p.{$piidCol} = r.piid
             {$whereSql}"
        );
        $totStmt->execute($params);
        $totals = $totStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $sexExpr   = "COALESCE(lpi.sex,      " . ($sexCol   ? "p.{$sexCol}"   : "NULL") . ")";
        $birthExpr = "COALESCE(lpi.birthdate," . ($birthCol ? "p.{$birthCol}" : "NULL") . ")";
        $brgyExpr  = "COALESCE(lpi.barangay, " . ($brgyCol  ? "p.{$brgyCol}"  : "NULL") . ")";
        $cityExpr  = "COALESCE(lpi.city,     " . ($cityCol  ? "p.{$cityCol}"  : "NULL") . ")";

        $rowStmt = $conn->prepare(
            "SELECT
                r.result_id,
                r.piid,
                r.lab_patient_id,
                {$nameExpr} AS display_name,
                {$sexExpr} AS sex,
                {$birthExpr} AS birthdate,
                {$brgyExpr} AS barangay,
                {$cityExpr} AS city,
                r.test_name,
                r.request_date,
                r.result_date,
                UPPER(r.status) AS status,
                r.result_summary,
                r.result_value,
                r.or_number,
                r.payment_required,
                r.is_employee
             FROM tbl_laboratory_results r
             LEFT JOIN tbl_lab_personal_info lpi ON lpi.lab_patient_id = r.lab_patient_id
             LEFT JOIN tbl_personal_details p ON p.{$piidCol} = r.piid
             {$whereSql}
             ORDER BY r.request_date DESC, r.result_id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $rowStmt->execute($params);
        $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);
        $fallbackLabPatientStmt = $conn->prepare(
            'SELECT lab_patient_id, piid, surname, first_name, middle_name, name_ext, sex, birthdate, barangay, city
             FROM tbl_lab_personal_info
             WHERE piid = :piid AND is_employee = :is_employee AND COALESCE(is_archived, 0) = 0
             LIMIT 1'
        );
        foreach ($rows as &$queueRow) {
            if ((int)($queueRow['lab_patient_id'] ?? 0) > 0) {
                continue;
            }
            $queuePiid = normalizeString($queueRow['piid'] ?? '');
            if ($queuePiid === '') {
                continue;
            }
            $fallbackLabPatientStmt->execute([
                ':piid' => $queuePiid,
                ':is_employee' => (int)($queueRow['is_employee'] ?? 0),
            ]);
            $fallbackPatient = $fallbackLabPatientStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fallbackPatient) {
                continue;
            }
            $queueRow['lab_patient_id'] = (int)($fallbackPatient['lab_patient_id'] ?? 0);
            $queueRow['display_name'] = formatLabPatientDisplayName($fallbackPatient);
            $queueRow['sex'] = (string)($fallbackPatient['sex'] ?? '');
            $queueRow['birthdate'] = (string)($fallbackPatient['birthdate'] ?? '');
            $queueRow['barangay'] = (string)($fallbackPatient['barangay'] ?? '');
            $queueRow['city'] = (string)($fallbackPatient['city'] ?? '');
        }
        unset($queueRow);
        $totalRecords = (int)($totals['total_records'] ?? 0);

        sendResponse('success', 'Processing queue loaded.', [
            'totals' => [
                'total_records' => $totalRecords,
                'processing_count' => $totalRecords,
            ],
            'results' => $rows,
        ], 200, [
            'count' => count($rows),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    if ($action === 'all_results') {
        requireLabAdmin($userData);
        if (!$personMeta) {
            sendResponse('error', 'Personal details table is unavailable for report legacy joins.', null, 500);
        }
        $fromDate  = normalizeDateOnly($_GET['from']   ?? '');
        $toDate    = normalizeDateOnly($_GET['to']     ?? '');
        $status    = strtoupper(normalizeString($_GET['status'] ?? 'ALL'));
        $search    = normalizeString($_GET['search']   ?? '');
        $limitRaw  = (int)($_GET['limit']  ?? 500);
        $offsetRaw = (int)($_GET['offset'] ?? 0);
        $limit     = max(1, min(1000, $limitRaw));
        $offset    = max(0, $offsetRaw);

        if ($fromDate === false || $toDate === false) {
            sendResponse('error', 'Invalid date format. Use YYYY-MM-DD.', null, 400);
        }

        if ($fromDate && $toDate && strtotime($fromDate) > strtotime($toDate)) {
            sendResponse('error', 'Invalid date range: from-date is later than to-date.', null, 400);
        }

        $allowedFilter = ['ALL', 'PENDING', 'PROCESSING', 'READY_FOR_RELEASE', 'RELEASED', 'CANCELLED'];
        if (!in_array($status, $allowedFilter, true)) {
            $status = 'ALL';
        }

        $piidCol     = quoteIdentifier($personMeta['piid']);
        $surnameCol  = quoteIdentifier($personMeta['surname']);
        $firstCol    = quoteIdentifier($personMeta['firstname']);
        $midCol      = $personMeta['middlename'] ? quoteIdentifier($personMeta['middlename']) : null;
        $sexCol      = $personMeta['sex']       ? quoteIdentifier($personMeta['sex'])       : null;
        $birthCol    = $personMeta['birthdate'] ? quoteIdentifier($personMeta['birthdate']) : null;
        $brgyCol     = $personMeta['barangay']  ? quoteIdentifier($personMeta['barangay'])  : null;
        $cityCol     = $personMeta['city']      ? quoteIdentifier($personMeta['city'])      : null;

        // Name expression: prefer lab table columns, fall back to tbl_personal_details
        $nameExpr = "CONCAT(COALESCE(lpi.surname, p.{$surnameCol}), ', ', COALESCE(lpi.first_name, p.{$firstCol})" .
                    ", ' ', IFNULL(CONCAT(NULLIF(LEFT(COALESCE(lpi.middle_name" . ($midCol ? ", p.{$midCol}" : "") . ",''),1),''),'. '),'') " .
                    ")";

        $where  = ["r.is_deleted = 0"];
        $params = [];

        if ($fromDate) {
            $where[] = "r.request_date >= :from_date";
            $params[':from_date'] = $fromDate;
        }
        if ($toDate) {
            $where[] = "r.request_date <= :to_date";
            $params[':to_date'] = $toDate;
        }
        if ($status !== 'ALL') {
            $where[] = "UPPER(r.status) = :status";
            $params[':status'] = $status;
        }
        if ($search !== '') {
            $where[] = "({$nameExpr} LIKE :search OR r.piid LIKE :search2)";
            $params[':search']  = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // Totals — join both tables; lab table takes priority
        $totStmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total_records,
                SUM(CASE WHEN UPPER(r.status)='PENDING' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN UPPER(r.status)='PROCESSING' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN UPPER(r.status)='READY_FOR_RELEASE' THEN 1 ELSE 0 END) AS ready_for_release_count,
                SUM(CASE WHEN UPPER(r.status)='RELEASED' THEN 1 ELSE 0 END) AS released_count,
                SUM(CASE WHEN UPPER(r.status)='CANCELLED' THEN 1 ELSE 0 END) AS cancelled_count
             FROM tbl_laboratory_results r
             LEFT JOIN tbl_lab_personal_info lpi ON lpi.lab_patient_id = r.lab_patient_id
             LEFT JOIN tbl_personal_details  p   ON p.{$piidCol}       = r.piid
             {$whereSql}"
        );
        $totStmt->execute($params);
        $totals = $totStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Rows — COALESCE lab table over tbl_personal_details for personal fields
        $sexExpr   = "COALESCE(lpi.sex,      " . ($sexCol   ? "p.{$sexCol}"   : "NULL") . ")";
        $birthExpr = "COALESCE(lpi.birthdate," . ($birthCol ? "p.{$birthCol}" : "NULL") . ")";
        $brgyExpr  = "COALESCE(lpi.barangay, " . ($brgyCol  ? "p.{$brgyCol}"  : "NULL") . ")";
        $cityExpr  = "COALESCE(lpi.city,     " . ($cityCol  ? "p.{$cityCol}"  : "NULL") . ")";

        $rowStmt = $conn->prepare(
            "SELECT
                r.result_id,
                r.piid,
                r.lab_patient_id,
                {$nameExpr} AS display_name,
                {$sexExpr}   AS sex,
                {$birthExpr} AS birthdate,
                {$brgyExpr}  AS barangay,
                {$cityExpr}  AS city,
                r.test_name,
                r.request_date,
                r.result_date,
                UPPER(r.status) AS status,
                r.result_summary,
                r.result_value,
                r.or_number,
                r.payment_required,
                r.is_employee
             FROM tbl_laboratory_results r
             LEFT JOIN tbl_lab_personal_info lpi ON lpi.lab_patient_id = r.lab_patient_id
             LEFT JOIN tbl_personal_details  p   ON p.{$piidCol}       = r.piid
             {$whereSql}
             ORDER BY r.request_date DESC, r.result_id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $rowStmt->execute($params);
        $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse('success', 'Results loaded.', [
            'totals' => [
                'total_records'          => (int)($totals['total_records'] ?? 0),
                'pending_count'          => (int)($totals['pending_count'] ?? 0),
                'processing_count'       => (int)($totals['processing_count'] ?? 0),
                'ready_for_release_count'=> (int)($totals['ready_for_release_count'] ?? 0),
                'released_count'         => (int)($totals['released_count'] ?? 0),
                'cancelled_count'        => (int)($totals['cancelled_count'] ?? 0),
            ],
            'results' => $rows,
        ], 200, [
            'count'  => count($rows),
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    if ($action === 'summary') {
        requireLabAdmin($userData);
        $fromDate = normalizeDateOnly($_GET['from'] ?? '');
        $toDate = normalizeDateOnly($_GET['to'] ?? '');
        $status = strtoupper(normalizeString($_GET['status'] ?? 'ALL'));

        if ($fromDate === false || $toDate === false) {
            sendResponse('error', 'Invalid date format. Use YYYY-MM-DD.', null, 400);
        }

        if ($fromDate && $toDate && strtotime($fromDate) > strtotime($toDate)) {
            sendResponse('error', 'Invalid date range: from-date is later than to-date.', null, 400);
        }

        $allowedFilter = ['ALL', 'PENDING', 'PROCESSING', 'READY_FOR_RELEASE', 'RELEASED', 'CANCELLED'];
        if (!in_array($status, $allowedFilter, true)) {
            $status = 'ALL';
        }

        $where = [];
        $params = [];

        if ($fromDate) {
            $where[] = 'DATE(COALESCE(result_date, request_date)) >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate) {
            $where[] = 'DATE(COALESCE(result_date, request_date)) <= :to_date';
            $params[':to_date'] = $toDate;
        }

        if ($status !== 'ALL') {
            $where[] = 'UPPER(status) = :status';
            $params[':status'] = $status;
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = 'WHERE ' . implode(' AND ', $where);
        }

        $summaryStmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total_records,
                SUM(CASE WHEN UPPER(status) = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN UPPER(status) = 'PROCESSING' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN UPPER(status) = 'READY_FOR_RELEASE' THEN 1 ELSE 0 END) AS ready_for_release_count,
                SUM(CASE WHEN UPPER(status) = 'RELEASED' THEN 1 ELSE 0 END) AS released_count,
                SUM(CASE WHEN UPPER(status) = 'CANCELLED' THEN 1 ELSE 0 END) AS cancelled_count
             FROM tbl_laboratory_results
             {$whereSql}"
        );
        $summaryStmt->execute($params);
        $totals = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $breakdownStmt = $conn->prepare(
            "SELECT
                test_name,
                UPPER(status) AS status,
                COUNT(*) AS record_count,
                MIN(COALESCE(result_date, request_date)) AS first_result_date,
                MAX(COALESCE(result_date, request_date)) AS last_result_date
             FROM tbl_laboratory_results
             {$whereSql}
             GROUP BY test_name, UPPER(status)
             ORDER BY test_name ASC, status ASC"
        );
        $breakdownStmt->execute($params);
        $rows = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse('success', 'Summary report generated.', [
            'totals' => [
                'total_records' => (int)($totals['total_records'] ?? 0),
                'pending_count' => (int)($totals['pending_count'] ?? 0),
                'processing_count' => (int)($totals['processing_count'] ?? 0),
                'ready_for_release_count' => (int)($totals['ready_for_release_count'] ?? 0),
                'released_count' => (int)($totals['released_count'] ?? 0),
                'cancelled_count' => (int)($totals['cancelled_count'] ?? 0)
            ],
            'breakdown' => $rows
        ], 200, [
            'count' => count($rows),
            'status_filter' => $status,
            'from' => $fromDate,
            'to' => $toDate
        ]);
    }

    if ($action === 'get_test_types') {
        // Known types defined in the recording form — always included even if no records exist yet
        $known = [
            'BLOOD_CHEM' => 'Blood Chem',
            'HEMATOLOGY' => 'Hematology',
            'URINALYSIS' => 'Urinalysis',
            'FECALYSIS' => 'Fecalysis',
            'SEROLOGY' => 'Serology',
            'MICROSCOPY' => 'Microscopy',
            'SPUTUM' => 'Sputum',
            'OTHER' => 'Other'
        ];

        // Merge with any distinct values already recorded in the DB
        try {
            $dbTypes = $conn->query(
                "SELECT DISTINCT UPPER(TRIM(test_type)) AS test_type FROM tbl_laboratory_results
                 WHERE test_type IS NOT NULL AND test_type <> ''
                 UNION
                 SELECT DISTINCT UPPER(TRIM(test_type)) FROM tbl_lab_fees
                 WHERE test_type IS NOT NULL AND test_type <> ''"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $dbTypes = [];
        }

        foreach ($dbTypes as $t) {
            $t = strtoupper(trim($t));
            if ($t !== '' && !isset($known[$t])) {
                $known[$t] = $t; // no friendly label — use raw value
            }
        }

        $result = [];
        foreach ($known as $value => $label) {
            $result[] = ['value' => $value, 'label' => $label];
        }
        usort($result, fn($a, $b) => strcmp($a['value'], $b['value']));

        sendResponse('success', 'Test types loaded.', $result, 200, ['count' => count($result)]);
    }

    if ($action === 'get_fees') {
        $stmt = $conn->query(
            "SELECT fee_id, test_type, test_name, amount, effective_from, description, is_active, updated_at
             FROM tbl_lab_fees
             ORDER BY test_type, test_name, effective_from DESC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['fee_id']   = (int)$row['fee_id'];
            $row['amount']   = number_format((float)$row['amount'], 2, '.', '');
            $row['is_active'] = (int)$row['is_active'];
        }
        unset($row);
        sendResponse('success', 'Fee schedule loaded.', $rows, 200, ['count' => count($rows)]);
    }

    if ($action === 'get_org_chart') {
        $includeInactive = normalizeFlag($_GET['include_inactive'] ?? 0);
        $signatoryFor = strtolower(normalizeString($_GET['signatory_for'] ?? ''));

        $where = [];
        if (!$includeInactive) {
            $where[] = 'is_active = 1';
        }
        if ($signatoryFor === 'checkout') {
            $where[] = 'is_checkout_signatory = 1';
        } elseif ($signatoryFor === 'results' || $signatoryFor === 'result') {
            $where[] = 'is_result_signatory = 1';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $conn->query(
            "SELECT staff_id, parent_id, full_name, designation, license_no, section_name,
                    specialization, photo_url, is_checkout_signatory, is_result_signatory,
                    sort_order, is_active, created_at, updated_at
             FROM tbl_lab_org_chart
             {$whereSql}
             ORDER BY sort_order ASC, full_name ASC, staff_id ASC"
        );
        $rows = array_map('normalizeOrgStaffRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
        sendResponse('success', 'Laboratory organizational chart loaded.', $rows, 200, ['count' => count($rows)]);
    }

    if ($action === 'lab_patients') {
        $patientType = normalizePatientType($_GET['patient_type'] ?? 'citizen');
        $isEmployeeFilter = $patientType === 'employee' ? 1 : 0;
        $search = normalizeString($_GET['search'] ?? '');
        $includeArchived = normalizeFlag($_GET['include_archived'] ?? 0);
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        if ($patientType === 'employee') {
            $payroll = searchCachedLabEmployees($conn, $search, $limit, $offset);
            if ($payroll['available']) {
                $labByPiid = getLabPatientsByPiids($conn, array_column($payroll['rows'], 'piid'));
                $rows = [];
                $seenPiids = [];
                foreach ($payroll['rows'] as $employee) {
                    $labRow = $labByPiid[$employee['piid']] ?? [];
                    if (!$includeArchived && $labRow && (int)($labRow['is_archived'] ?? 0) === 1) {
                        continue;
                    }
                    $seenPiids[(string)$employee['piid']] = true;
                    $row = [
                        'lab_patient_id' => (int)($labRow['lab_patient_id'] ?? 0),
                        'piid' => $employee['piid'],
                        'is_employee' => 1,
                        'surname' => $employee['surname'],
                        'first_name' => $employee['first_name'],
                        'middle_name' => $employee['middle_name'],
                        'name_ext' => $employee['name_ext'],
                        'sex' => $employee['sex'],
                        'birthdate' => $employee['birthdate'],
                        'barangay' => (string)($labRow['barangay'] ?? ''),
                        'city' => (string)($labRow['city'] ?? ''),
                        'yakap_member' => (int)($labRow['yakap_member'] ?? 0),
                        'yakap_id_number' => (string)($labRow['yakap_id_number'] ?? ''),
                        'senior' => (int)($labRow['senior'] ?? 0),
                        'senior_id_number' => (string)($labRow['senior_id_number'] ?? ''),
                        'pwd' => (int)($labRow['pwd'] ?? 0),
                        'pwd_id_number' => (string)($labRow['pwd_id_number'] ?? ''),
                        'is_archived' => (int)($labRow['is_archived'] ?? 0),
                        'created_at' => (string)($labRow['created_at'] ?? ''),
                        'updated_at' => (string)($labRow['updated_at'] ?? ''),
                        'department' => $employee['department'],
                        'position' => $employee['position'],
                        'employee_status' => $employee['employee_status'],
                        'is_jo' => $employee['is_jo'],
                        'is_casual' => $employee['is_casual'],
                        'display_name' => $employee['display_name'],
                        'source' => 'HRMIS Payroll',
                        'source_system' => $employee['source_system'],
                        'source_snapshot_at' => $employee['source_snapshot_at'],
                        'employee_roster_synced_at' => (string)($labRow['employee_roster_synced_at'] ?? ''),
                    ];
                    $rows[] = $row;
                }

                $manualWhere = [
                    'is_employee = 1',
                    "COALESCE(source_system, '') = 'laboratory_manual'",
                ];
                $manualParams = [];
                if (!$includeArchived) {
                    $manualWhere[] = 'COALESCE(is_archived, 0) = 0';
                }
                if ($search !== '') {
                    $manualWhere[] = '(surname LIKE :manual_search OR first_name LIKE :manual_search OR middle_name LIKE :manual_search OR piid LIKE :manual_search OR barangay LIKE :manual_search)';
                    $manualParams[':manual_search'] = '%' . $search . '%';
                }
                $manualSql = 'WHERE ' . implode(' AND ', $manualWhere);
                $manualStmt = $conn->prepare(
                    "SELECT
                        lab_patient_id, piid, is_employee, surname, first_name, middle_name, name_ext,
                        sex, birthdate, barangay, city, yakap_member, yakap_id_number,
                        senior, senior_id_number, pwd, pwd_id_number, is_archived, created_at, updated_at,
                        source_system, source_snapshot_at, employee_roster_synced_at
                     FROM tbl_lab_personal_info
                     {$manualSql}
                     ORDER BY surname ASC, first_name ASC, lab_patient_id DESC
                     LIMIT {$limit}"
                );
                $manualStmt->execute($manualParams);
                foreach ($manualStmt->fetchAll(PDO::FETCH_ASSOC) as $manualRow) {
                    $manualPiid = (string)($manualRow['piid'] ?? '');
                    if ($manualPiid !== '' && isset($seenPiids[$manualPiid])) {
                        continue;
                    }
                    $manualRow['is_employee'] = 1;
                    $manualRow['yakap_member'] = (int)($manualRow['yakap_member'] ?? 0);
                    $manualRow['senior'] = (int)($manualRow['senior'] ?? 0);
                    $manualRow['pwd'] = (int)($manualRow['pwd'] ?? 0);
                    $manualRow['is_archived'] = (int)($manualRow['is_archived'] ?? 0);
                    $manualRow['department'] = '';
                    $manualRow['position'] = '';
                    $manualRow['employee_status'] = 'Manual temporary record';
                    $manualRow['is_jo'] = 0;
                    $manualRow['is_casual'] = 0;
                    $manualRow['display_name'] = formatLabPatientDisplayName($manualRow);
                    $manualRow['source'] = 'Laboratory Manual';
                    $rows[] = $manualRow;
                }

                sendResponse('success', 'Payroll employees loaded.', [
                    'patients' => $rows,
                    'total' => $payroll['total']
                ], 200, [
                    'count' => count($rows),
                    'limit' => $limit,
                    'offset' => $offset,
                    'patient_type' => $patientType,
                    'source' => empty($payroll['rows']) ? 'employee_roster_cache' : (string)($payroll['rows'][0]['source_system'] ?? 'employee_roster_cache')
                ]);
            }
        }

        $where = ['is_employee = :is_employee'];
        $params = [':is_employee' => $isEmployeeFilter];
        if (!$includeArchived) {
            $where[] = 'COALESCE(is_archived, 0) = 0';
        }
        if ($search !== '') {
            $where[] = '(surname LIKE :search OR first_name LIKE :search OR middle_name LIKE :search OR piid LIKE :search OR barangay LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM tbl_lab_personal_info {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $conn->prepare(
            "SELECT
                lab_patient_id, piid, is_employee, surname, first_name, middle_name, name_ext,
                sex, birthdate, barangay, city, yakap_member, yakap_id_number,
                senior, senior_id_number, pwd, pwd_id_number, is_archived, created_at, updated_at
             FROM tbl_lab_personal_info
             {$whereSql}
             ORDER BY surname ASC, first_name ASC, lab_patient_id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (normalizeString($row['piid'] ?? '') === '') {
                $row['piid'] = generateLabPatientPiid((int)$row['lab_patient_id'], (int)$row['is_employee']);
            }
            $row['display_name'] = formatLabPatientDisplayName($row);
            $row['is_employee'] = (int)($row['is_employee'] ?? 0);
            $row['yakap_member'] = (int)($row['yakap_member'] ?? 0);
            $row['senior'] = (int)($row['senior'] ?? 0);
            $row['pwd'] = (int)($row['pwd'] ?? 0);
            $row['is_archived'] = (int)($row['is_archived'] ?? 0);
        }
        unset($row);

        sendResponse('success', 'Laboratory patients loaded.', [
            'patients' => $rows,
            'total' => $total
        ], 200, [
            'count' => count($rows),
            'limit' => $limit,
            'offset' => $offset,
            'patient_type' => $patientType
        ]);
    }

    sendResponse('error', 'Unknown action.', null, 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = readPayload();
    $action = normalizeString($payload['action'] ?? 'save_result');

    if ($action === 'refresh_employee_roster_cache') {
        requireLabAccess($userData);
        @set_time_limit(300);
        $limit = isset($payload['limit']) ? max(0, min(50000, (int)$payload['limit'])) : 0;
        $batchSize = isset($payload['batch_size']) ? max(100, min(2000, (int)$payload['batch_size'])) : 1000;
        $result = refreshLabEmployeeRosterCache($conn, $limit, $batchSize);
        if (!$result['available']) {
            sendResponse('error', 'HRMIS payroll roster is unavailable.', $result, 503);
        }
        sendResponse('success', 'Employee roster cache refreshed.', $result, 200);
    }

    if ($action === 'create_lab_patient') {
        $patientType = normalizePatientType($payload['patient_type'] ?? 'citizen');
        $isEmployee = $patientType === 'employee' ? 1 : 0;
        $surname = normalizeString($payload['surname'] ?? '');
        $firstName = normalizeString($payload['first_name'] ?? '');
        $middleName = normalizeString($payload['middle_name'] ?? '');
        $nameExt = normalizeString($payload['name_ext'] ?? '');
        $sex = normalizeString($payload['sex'] ?? '');
        $birthdate = normalizeDateOnly($payload['birthdate'] ?? '');
        $city = normalizeString($payload['city'] ?? '');
        $barangay = normalizeString($payload['barangay'] ?? '');
        $yakapMember = normalizeFlag($payload['yakap_member'] ?? 0);
        $yakapIdNumber = normalizeString($payload['yakap_id_number'] ?? '');
        $senior = normalizeFlag($payload['senior'] ?? 0);
        $seniorIdNumber = normalizeString($payload['senior_id_number'] ?? '');
        $pwd = normalizeFlag($payload['pwd'] ?? 0);
        $pwdIdNumber = normalizeString($payload['pwd_id_number'] ?? '');
        $confirmDuplicate = normalizeFlag($payload['confirm_duplicate'] ?? 0);

        if ($surname === '' || $firstName === '') {
            sendResponse('error', 'Surname and first name are required.', null, 400);
        }
        if ($birthdate === false) {
            sendResponse('error', 'Invalid birthdate format. Use YYYY-MM-DD.', null, 400);
        }

        if (!$confirmDuplicate) {
            $duplicates = findLabPatientDuplicateCandidates($conn, [
                'is_employee' => $isEmployee,
                'surname' => $surname,
                'first_name' => $firstName,
                'birthdate' => $birthdate ?: '',
                'barangay' => $barangay,
            ]);
            if (!empty($duplicates)) {
                sendResponse('duplicate', 'Possible matching patient already exists. Please confirm before adding a new record.', [
                    'candidates' => $duplicates
                ], 409, ['count' => count($duplicates)]);
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO tbl_lab_personal_info
                (piid, is_employee, surname, first_name, middle_name, name_ext, sex, birthdate, city, barangay,
                 yakap_member, yakap_id_number, senior, senior_id_number, pwd, pwd_id_number, source_system, source_snapshot_at)
             VALUES
                (NULL, :is_employee, :surname, :first_name, :middle_name, :name_ext, :sex, :birthdate, :city, :barangay,
                 :yakap_member, :yakap_id_number, :senior, :senior_id_number, :pwd, :pwd_id_number, 'laboratory_manual', NOW())"
        );
        $stmt->execute([
            ':is_employee' => $isEmployee,
            ':surname' => $surname,
            ':first_name' => $firstName,
            ':middle_name' => $middleName !== '' ? $middleName : null,
            ':name_ext' => $nameExt !== '' ? $nameExt : null,
            ':sex' => $sex,
            ':birthdate' => $birthdate,
            ':city' => $city !== '' ? $city : null,
            ':barangay' => $barangay !== '' ? $barangay : null,
            ':yakap_member' => $yakapMember,
            ':yakap_id_number' => $yakapMember && $yakapIdNumber !== '' ? $yakapIdNumber : null,
            ':senior' => $senior,
            ':senior_id_number' => $senior && $seniorIdNumber !== '' ? $seniorIdNumber : null,
            ':pwd' => $pwd,
            ':pwd_id_number' => $pwd && $pwdIdNumber !== '' ? $pwdIdNumber : null,
        ]);

        $labPatientId = (int)$conn->lastInsertId();
        $piid = generateLabPatientPiid($labPatientId, $isEmployee);
        $upd = $conn->prepare('UPDATE tbl_lab_personal_info SET piid = :piid WHERE lab_patient_id = :id');
        $upd->execute([':piid' => $piid, ':id' => $labPatientId]);

        $patient = getLabPatientById($conn, $labPatientId);
        sendResponse('success', ucfirst($patientType) . ' details added.', [
            'patient' => [
                'lab_patient_id' => $labPatientId,
                'piid' => $piid,
                'is_employee' => $isEmployee,
                'display_name' => formatLabPatientDisplayName($patient),
                'sex' => (string)($patient['sex'] ?? ''),
                'birthdate' => (string)($patient['birthdate'] ?? ''),
                'barangay' => (string)($patient['barangay'] ?? ''),
                'city' => (string)($patient['city'] ?? ''),
                'yakap_member' => (int)($patient['yakap_member'] ?? 0),
                'yakap_id_number' => (string)($patient['yakap_id_number'] ?? ''),
                'senior' => (int)($patient['senior'] ?? 0),
                'senior_id_number' => (string)($patient['senior_id_number'] ?? ''),
                'pwd' => (int)($patient['pwd'] ?? 0),
                'pwd_id_number' => (string)($patient['pwd_id_number'] ?? ''),
                'source_system' => (string)($patient['source_system'] ?? ''),
                'source_snapshot_at' => (string)($patient['source_snapshot_at'] ?? ''),
                'employee_roster_synced_at' => (string)($patient['employee_roster_synced_at'] ?? ''),
            ]
        ], 201);
    }

    if ($action === 'release_result') {
        $resultId = isset($payload['result_id']) ? (int)$payload['result_id'] : 0;
        if ($resultId <= 0) {
            sendResponse('error', 'Result ID is required.', null, 400);
        }

        $findStmt = $conn->prepare("SELECT result_id, status, request_date, result_date, payment_required, or_number FROM tbl_laboratory_results WHERE result_id = :result_id AND is_deleted = 0 LIMIT 1");
        $findStmt->execute([':result_id' => $resultId]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            sendResponse('error', 'Result not found.', null, 404);
        }

        if (strtoupper((string)($existing['status'] ?? '')) === 'CANCELLED') {
            sendResponse('error', 'Cancelled results cannot be released.', null, 400);
        }

        if (strtoupper((string)($existing['status'] ?? '')) === 'RELEASED') {
            sendResponse('success', 'Result is already released.', [
                'result_id' => $resultId,
                'status' => 'RELEASED'
            ]);
        }

        if (strtoupper((string)($existing['status'] ?? '')) !== 'READY_FOR_RELEASE') {
            sendResponse('error', 'Only results tagged as Ready for Release can be released.', null, 400);
        }

        // Result date must already be set — do not silently fill it with today's date.
        $existingResultDate = trim((string)($existing['result_date'] ?? ''));
        if ($existingResultDate === '' || $existingResultDate === '0000-00-00') {
            sendResponse('error', 'Result date is required before releasing. Edit the record and set the result date first.', null, 400);
        }

        if ((int)($existing['payment_required'] ?? 0) === 1 && trim((string)($existing['or_number'] ?? '')) === '') {
            sendResponse('error', 'This repeat test is unpaid. Save the OR number before releasing.', null, 400);
        }

        $username = normalizeString($userData['username'] ?? 'system');
        $releaseStmt = $conn->prepare(
            "UPDATE tbl_laboratory_results
             SET
                status = 'RELEASED',
                released_by = :updated_by,
                released_at = NOW(),
                updated_by = :updated_by,
                updated_at = NOW()
             WHERE result_id = :result_id"
        );
        $releaseStmt->execute([
            ':updated_by' => $username,
            ':result_id' => $resultId
        ]);

        sendResponse('success', 'Result marked as released.', [
            'result_id' => $resultId,
            'status' => 'RELEASED'
        ]);
    }

    if ($action === 'save_or_number') {
        $resultId = isset($payload['result_id']) ? (int)$payload['result_id'] : 0;
        $orNumber = normalizeString($payload['or_number'] ?? '');
        if (strlen($orNumber) > 50) {
            $orNumber = substr($orNumber, 0, 50);
        }

        if ($resultId <= 0) {
            sendResponse('error', 'Result ID is required.', null, 400);
        }
        if ($orNumber === '') {
            sendResponse('error', 'OR number is required.', null, 400);
        }

        $findStmt = $conn->prepare("SELECT result_id, status FROM tbl_laboratory_results WHERE result_id = :result_id AND is_deleted = 0 LIMIT 1");
        $findStmt->execute([':result_id' => $resultId]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            sendResponse('error', 'Record not found.', null, 404);
        }

        // Advance status from PENDING to PROCESSING once OR number is confirmed
        $currentStatus = strtoupper((string)($existing['status'] ?? 'PROCESSING'));
        $newStatus = $currentStatus === 'PENDING' ? 'PROCESSING' : $currentStatus;

        $username = normalizeString($userData['username'] ?? 'system');
        $orStmt = $conn->prepare(
            "UPDATE tbl_laboratory_results
             SET or_number = :or_number, status = :status, updated_by = :updated_by, updated_at = NOW()
             WHERE result_id = :result_id"
        );
        $orStmt->execute([
            ':or_number' => $orNumber,
            ':status' => $newStatus,
            ':updated_by' => $username,
            ':result_id' => $resultId
        ]);

        sendResponse('success', 'OR number saved.', [
            'result_id' => $resultId,
            'status' => $newStatus
        ]);
    }

    if ($action === 'delete_result') {
        requireLabAdmin($userData);
        $resultId = isset($payload['result_id']) ? (int)$payload['result_id'] : 0;
        if ($resultId <= 0) {
            sendResponse('error', 'Result ID is required.', null, 400);
        }

        $findStmt = $conn->prepare("SELECT result_id FROM tbl_laboratory_results WHERE result_id = :result_id AND is_deleted = 0 LIMIT 1");
        $findStmt->execute([':result_id' => $resultId]);
        if (!$findStmt->fetch()) {
            sendResponse('error', 'Result not found.', null, 404);
        }

        $username = normalizeString($userData['username'] ?? 'system');
        $delStmt = $conn->prepare(
            "UPDATE tbl_laboratory_results
             SET is_deleted = 1, updated_by = :updated_by
             WHERE result_id = :result_id"
        );
        $delStmt->execute([':updated_by' => $username, ':result_id' => $resultId]);

        sendResponse('success', 'Record deleted.', ['result_id' => $resultId]);
    }

    if ($action === 'update_lab_patient') {
        $labPatientId = (int)($payload['lab_patient_id'] ?? 0);
        $patientType = normalizePatientType($payload['patient_type'] ?? 'citizen');
        if ($labPatientId <= 0 && $patientType === 'employee') {
            $employeePiid = normalizeString($payload['piid'] ?? '');
            $employee = getLabEmployeeByPiid($conn, $employeePiid);
            if (!$employee) {
                sendResponse('error', 'Employee record was not found in the HRMIS payroll cache.', null, 404);
            }
            $labPatientId = upsertLabPatientFromHrmisEmployee($conn, $employee);
            if ($labPatientId <= 0) {
                sendResponse('error', 'Unable to create the Laboratory employee registry record.', null, 500);
            }
        }
        if ($labPatientId <= 0) {
            sendResponse('error', 'Laboratory patient ID is required.', null, 400);
        }
        $existing = getLabPatientById($conn, $labPatientId);
        if (!$existing) {
            sendResponse('error', 'Patient record not found.', null, 404);
        }

        $surname = normalizeString($payload['surname'] ?? '');
        $firstName = normalizeString($payload['first_name'] ?? '');
        $middleName = normalizeString($payload['middle_name'] ?? '');
        $nameExt = normalizeString($payload['name_ext'] ?? '');
        $sex = normalizeString($payload['sex'] ?? '');
        $birthdate = normalizeDateOnly($payload['birthdate'] ?? '');
        $city = normalizeString($payload['city'] ?? '');
        $barangay = normalizeString($payload['barangay'] ?? '');
        $yakapMember = normalizeFlag($payload['yakap_member'] ?? 0);
        $yakapIdNumber = normalizeString($payload['yakap_id_number'] ?? '');
        $senior = normalizeFlag($payload['senior'] ?? 0);
        $seniorIdNumber = normalizeString($payload['senior_id_number'] ?? '');
        $pwd = normalizeFlag($payload['pwd'] ?? 0);
        $pwdIdNumber = normalizeString($payload['pwd_id_number'] ?? '');

        if ($surname === '' || $firstName === '') {
            sendResponse('error', 'Surname and first name are required.', null, 400);
        }
        if ($birthdate === false) {
            sendResponse('error', 'Invalid birthdate format. Use YYYY-MM-DD.', null, 400);
        }

        $stmt = $conn->prepare(
            "UPDATE tbl_lab_personal_info
             SET surname = :surname,
                 first_name = :first_name,
                 middle_name = :middle_name,
                 name_ext = :name_ext,
                 sex = :sex,
                 birthdate = :birthdate,
                 city = :city,
                 barangay = :barangay,
                 yakap_member = :yakap_member,
                 yakap_id_number = :yakap_id_number,
                 senior = :senior,
                 senior_id_number = :senior_id_number,
                 pwd = :pwd,
                 pwd_id_number = :pwd_id_number,
                 updated_at = NOW()
             WHERE lab_patient_id = :lab_patient_id"
        );
        $stmt->execute([
            ':surname' => $surname,
            ':first_name' => $firstName,
            ':middle_name' => $middleName !== '' ? $middleName : null,
            ':name_ext' => $nameExt !== '' ? $nameExt : null,
            ':sex' => $sex,
            ':birthdate' => $birthdate,
            ':city' => $city !== '' ? $city : null,
            ':barangay' => $barangay !== '' ? $barangay : null,
            ':yakap_member' => $yakapMember,
            ':yakap_id_number' => $yakapMember && $yakapIdNumber !== '' ? $yakapIdNumber : null,
            ':senior' => $senior,
            ':senior_id_number' => $senior && $seniorIdNumber !== '' ? $seniorIdNumber : null,
            ':pwd' => $pwd,
            ':pwd_id_number' => $pwd && $pwdIdNumber !== '' ? $pwdIdNumber : null,
            ':lab_patient_id' => $labPatientId,
        ]);

        sendResponse('success', 'Patient details updated.', ['lab_patient_id' => $labPatientId]);
    }

    if ($action === 'archive_lab_patient') {
        requireLabAdmin($userData);
        $labPatientId = (int)($payload['lab_patient_id'] ?? 0);
        $archive = normalizeFlag($payload['archive'] ?? 1);
        if ($labPatientId <= 0) {
            sendResponse('error', 'Laboratory patient ID is required.', null, 400);
        }
        $stmt = $conn->prepare('UPDATE tbl_lab_personal_info SET is_archived = :archive, updated_at = NOW() WHERE lab_patient_id = :id');
        $stmt->execute([':archive' => $archive, ':id' => $labPatientId]);
        sendResponse('success', $archive ? 'Patient archived.' : 'Patient restored.', ['lab_patient_id' => $labPatientId, 'is_archived' => $archive]);
    }

    if ($action === 'save_fee') {
        requireLabAdmin($userData);
        $feeId      = isset($payload['fee_id'])   ? (int)$payload['fee_id']   : 0;
        $testType   = strtoupper(normalizeString($payload['test_type']   ?? ''));
        $testName   = normalizeString($payload['test_name']   ?? '');
        $testName   = $testName !== '' ? $testName : null;
        $amountRaw  = $payload['amount'] ?? '0';
        $amount     = round((float)str_replace(',', '', (string)$amountRaw), 2);
        $effectiveFrom = normalizeDateOnly($payload['effective_from'] ?? '');
        if ($effectiveFrom === '') { $effectiveFrom = date('Y-01-01'); }
        $description = normalizeString($payload['description'] ?? '');
        $isActive   = isset($payload['is_active']) ? (int)(bool)$payload['is_active'] : 1;

        if ($testType === '') {
            sendResponse('error', 'Test type is required.', null, 400);
        }
        if (strlen($testType) > 50 || ($testName !== null && strlen($testName) > 150)) {
            sendResponse('error', 'Test type or name is too long.', null, 400);
        }
        if ($amount < 0) {
            sendResponse('error', 'Amount cannot be negative.', null, 400);
        }

        if ($feeId > 0) {
            // Update
            $chkStmt = $conn->prepare("SELECT fee_id FROM tbl_lab_fees WHERE fee_id = :id LIMIT 1");
            $chkStmt->execute([':id' => $feeId]);
            if (!$chkStmt->fetch()) {
                sendResponse('error', 'Fee record not found.', null, 404);
            }
            $updStmt = $conn->prepare(
                "UPDATE tbl_lab_fees
                 SET test_type = :test_type, test_name = :test_name, amount = :amount,
                     effective_from = :effective_from, description = :description, is_active = :is_active
                 WHERE fee_id = :fee_id"
            );
            $updStmt->execute([
                ':test_type'     => $testType,
                ':test_name'     => $testName,
                ':amount'        => $amount,
                ':effective_from' => $effectiveFrom,
                ':description'   => $description ?: null,
                ':is_active'     => $isActive,
                ':fee_id'        => $feeId,
            ]);
            sendResponse('success', 'Fee updated.', ['fee_id' => $feeId]);
        } else {
            // For null test_name, MySQL unique index allows duplicate NULLs — check manually
            if ($testName === null) {
                $dupChk = $conn->prepare("SELECT COUNT(*) FROM tbl_lab_fees WHERE test_type = :tt AND test_name IS NULL AND effective_from = :ef");
                $dupChk->execute([':tt' => $testType, ':ef' => $effectiveFrom]);
                if ((int)$dupChk->fetchColumn() > 0) {
                    sendResponse('error', 'A fee for this test type / effective date already exists.', null, 409);
                }
            }
            // Insert
            try {
                $insStmt = $conn->prepare(
                    "INSERT INTO tbl_lab_fees (test_type, test_name, amount, effective_from, description, is_active)
                     VALUES (:test_type, :test_name, :amount, :effective_from, :description, :is_active)"
                );
                $insStmt->execute([
                    ':test_type'     => $testType,
                    ':test_name'     => $testName,
                    ':amount'        => $amount,
                    ':effective_from' => $effectiveFrom,
                    ':description'   => $description ?: null,
                    ':is_active'     => $isActive,
                ]);
                sendResponse('success', 'Fee created.', ['fee_id' => (int)$conn->lastInsertId()], 201);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    sendResponse('error', 'A fee for this test type / name / effective date already exists.', null, 409);
                }
                throw $e;
            }
        }
    }

    if ($action === 'delete_fee') {
        requireLabAdmin($userData);
        $feeId = isset($payload['fee_id']) ? (int)$payload['fee_id'] : 0;
        if ($feeId <= 0) {
            sendResponse('error', 'Fee ID is required.', null, 400);
        }

        // Fetch the fee record first so we know what test_type/test_name to check against
        $feeChk = $conn->prepare("SELECT test_type, test_name FROM tbl_lab_fees WHERE fee_id = :id LIMIT 1");
        $feeChk->execute([':id' => $feeId]);
        $feeRow = $feeChk->fetch(PDO::FETCH_ASSOC);
        if (!$feeRow) {
            sendResponse('error', 'Fee record not found.', null, 404);
        }

        // Orphan check: block delete if any PENDING or PROCESSING result references this fee
        $orphanStmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt
             FROM tbl_laboratory_results
             WHERE test_type  = :test_type
               AND (
                     (:test_name_null = 1 AND (test_name IS NULL OR test_name = ''))
                     OR
                     (:test_name_val  IS NOT NULL AND test_name = :test_name_val2)
                   )
               AND status     IN ('PENDING', 'PROCESSING')
               AND is_deleted = 0"
        );
        $testNameIsNull = ($feeRow['test_name'] === null || $feeRow['test_name'] === '') ? 1 : 0;
        $orphanStmt->execute([
            ':test_type'       => $feeRow['test_type'],
            ':test_name_null'  => $testNameIsNull,
            ':test_name_val'   => $testNameIsNull ? null : $feeRow['test_name'],
            ':test_name_val2'  => $testNameIsNull ? null : $feeRow['test_name'],
        ]);
        $orphanCount = (int)($orphanStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        if ($orphanCount > 0) {
            $label = $feeRow['test_name'] ? $feeRow['test_name'] : $feeRow['test_type'];
            sendResponse(
                'error',
                "Cannot delete this fee — {$orphanCount} pending/processing result(s) still reference \"{$label}\". "
                . "Resolve or cancel those results first.",
                ['pending_count' => $orphanCount],
                409
            );
        }

        $delStmt = $conn->prepare("DELETE FROM tbl_lab_fees WHERE fee_id = :id");
        $delStmt->execute([':id' => $feeId]);
        if ($delStmt->rowCount() === 0) {
            sendResponse('error', 'Fee record not found.', null, 404);
        }
        sendResponse('success', 'Fee deleted.');
    }

    if ($action === 'save_org_chart_member') {
        requireLabAdmin($userData);

        $staffId = isset($payload['staff_id']) ? (int)$payload['staff_id'] : 0;
        $parentId = isset($payload['parent_id']) && (int)$payload['parent_id'] > 0 ? (int)$payload['parent_id'] : null;
        $fullName = normalizeString($payload['full_name'] ?? '');
        $designation = normalizeString($payload['designation'] ?? '');
        $licenseNo = normalizeString($payload['license_no'] ?? '');
        $sectionName = normalizeString($payload['section_name'] ?? '');
        $specialization = normalizeString($payload['specialization'] ?? '');
        $photoUrl = normalizeString($payload['photo_url'] ?? '');
        $isCheckoutSignatory = normalizeFlag($payload['is_checkout_signatory'] ?? 0);
        $isResultSignatory = normalizeFlag($payload['is_result_signatory'] ?? 0);
        $sortOrder = isset($payload['sort_order']) ? (int)$payload['sort_order'] : 0;
        $isActive = isset($payload['is_active']) ? normalizeFlag($payload['is_active']) : 1;

        if ($fullName === '') {
            sendResponse('error', 'Full name is required.', null, 400);
        }
        if ($designation === '') {
            sendResponse('error', 'Designation is required.', null, 400);
        }
        if (strlen($fullName) > 150 || strlen($designation) > 150 || strlen($licenseNo) > 80 || strlen($sectionName) > 120 || strlen($photoUrl) > 255) {
            sendResponse('error', 'One or more fields are too long.', null, 400);
        }
        if ($staffId > 0 && $parentId === $staffId) {
            sendResponse('error', 'A staff member cannot report to themselves.', null, 400);
        }
        if ($parentId !== null) {
            $parentStmt = $conn->prepare('SELECT staff_id FROM tbl_lab_org_chart WHERE staff_id = :id LIMIT 1');
            $parentStmt->execute([':id' => $parentId]);
            if (!$parentStmt->fetch()) {
                sendResponse('error', 'Selected reports-to person was not found.', null, 404);
            }

            if ($staffId > 0) {
                $seenParents = [];
                $walkId = $parentId;
                $walkStmt = $conn->prepare('SELECT parent_id FROM tbl_lab_org_chart WHERE staff_id = :id LIMIT 1');
                while ($walkId !== null && $walkId > 0) {
                    if ($walkId === $staffId) {
                        sendResponse('error', 'Reporting line would create a cycle.', null, 400);
                    }
                    if (isset($seenParents[$walkId])) {
                        break;
                    }
                    $seenParents[$walkId] = true;
                    $walkStmt->execute([':id' => $walkId]);
                    $walkParent = $walkStmt->fetchColumn();
                    $walkId = $walkParent !== false && $walkParent !== null ? (int)$walkParent : null;
                }
            }
        }

        if ($staffId > 0) {
            $chkStmt = $conn->prepare('SELECT staff_id FROM tbl_lab_org_chart WHERE staff_id = :id LIMIT 1');
            $chkStmt->execute([':id' => $staffId]);
            if (!$chkStmt->fetch()) {
                sendResponse('error', 'Staff member not found.', null, 404);
            }

            $stmt = $conn->prepare(
                "UPDATE tbl_lab_org_chart
                 SET parent_id = :parent_id, full_name = :full_name, designation = :designation,
                     license_no = :license_no, section_name = :section_name, specialization = :specialization,
                     photo_url = :photo_url, is_checkout_signatory = :is_checkout_signatory,
                     is_result_signatory = :is_result_signatory, sort_order = :sort_order,
                     is_active = :is_active, updated_by = :updated_by, updated_at = NOW()
                 WHERE staff_id = :staff_id"
            );
            $stmt->execute([
                ':parent_id' => $parentId,
                ':full_name' => $fullName,
                ':designation' => $designation,
                ':license_no' => $licenseNo ?: null,
                ':section_name' => $sectionName ?: null,
                ':specialization' => $specialization ?: null,
                ':photo_url' => $photoUrl ?: null,
                ':is_checkout_signatory' => $isCheckoutSignatory,
                ':is_result_signatory' => $isResultSignatory,
                ':sort_order' => $sortOrder,
                ':is_active' => $isActive,
                ':updated_by' => (string)($userData['username'] ?? ''),
                ':staff_id' => $staffId,
            ]);
            sendResponse('success', 'Organizational chart member updated.', ['staff_id' => $staffId]);
        }

        $stmt = $conn->prepare(
            "INSERT INTO tbl_lab_org_chart (
                parent_id, full_name, designation, license_no, section_name, specialization, photo_url,
                is_checkout_signatory, is_result_signatory, sort_order, is_active, created_by
             ) VALUES (
                :parent_id, :full_name, :designation, :license_no, :section_name, :specialization, :photo_url,
                :is_checkout_signatory, :is_result_signatory, :sort_order, :is_active, :created_by
             )"
        );
        $stmt->execute([
            ':parent_id' => $parentId,
            ':full_name' => $fullName,
            ':designation' => $designation,
            ':license_no' => $licenseNo ?: null,
            ':section_name' => $sectionName ?: null,
            ':specialization' => $specialization ?: null,
            ':photo_url' => $photoUrl ?: null,
            ':is_checkout_signatory' => $isCheckoutSignatory,
            ':is_result_signatory' => $isResultSignatory,
            ':sort_order' => $sortOrder,
            ':is_active' => $isActive,
            ':created_by' => (string)($userData['username'] ?? ''),
        ]);
        sendResponse('success', 'Organizational chart member created.', ['staff_id' => (int)$conn->lastInsertId()], 201);
    }

    if ($action === 'delete_org_chart_member') {
        requireLabAdmin($userData);
        $staffId = isset($payload['staff_id']) ? (int)$payload['staff_id'] : 0;
        if ($staffId <= 0) {
            sendResponse('error', 'Staff member ID is required.', null, 400);
        }

        $stmt = $conn->prepare(
            "UPDATE tbl_lab_org_chart
             SET is_active = 0, updated_by = :updated_by, updated_at = NOW()
             WHERE staff_id = :staff_id"
        );
        $stmt->execute([
            ':updated_by' => (string)($userData['username'] ?? ''),
            ':staff_id' => $staffId,
        ]);
        if ($stmt->rowCount() === 0) {
            sendResponse('error', 'Staff member not found.', null, 404);
        }
        sendResponse('success', 'Organizational chart member archived.');
    }

    if ($action === 'amend_result') {
        // A released result is never edited in place. The correction becomes a new record
        // (back in PROCESSING) linked to the original, and the original points to it.
        $resultId = isset($payload['result_id']) ? (int)$payload['result_id'] : 0;
        $reason = normalizeString($payload['reason'] ?? '');
        if ($resultId <= 0) {
            sendResponse('error', 'Result ID is required.', null, 400);
        }
        if ($reason === '') {
            sendResponse('error', 'A reason is required to amend a released result.', null, 400);
        }
        if (strlen($reason) > 500) {
            $reason = substr($reason, 0, 500);
        }

        $username = normalizeString($userData['username'] ?? 'system');
        try {
            $conn->beginTransaction();

            $origStmt = $conn->prepare("SELECT * FROM tbl_laboratory_results WHERE result_id = :id AND is_deleted = 0 LIMIT 1 FOR UPDATE");
            $origStmt->execute([':id' => $resultId]);
            $original = $origStmt->fetch(PDO::FETCH_ASSOC);
            if (!$original) {
                $conn->rollBack();
                sendResponse('error', 'Result not found.', null, 404);
            }
            if (strtoupper((string)($original['status'] ?? '')) !== 'RELEASED') {
                $conn->rollBack();
                sendResponse('error', 'Only released results can be amended. Edit the record instead.', null, 400);
            }
            if (!empty($original['amended_by_result_id'])) {
                $conn->rollBack();
                sendResponse('error', 'This result was already amended by record #' . (int)$original['amended_by_result_id'] . '. Amend that record instead.', null, 409);
            }

            $insertStmt = $conn->prepare(
                "INSERT INTO tbl_laboratory_results (
                    piid, lab_patient_id, test_name, test_type, is_employee, request_date,
                    result_summary, result_value, status, result_date, remarks,
                    or_number, payment_required, created_by, updated_by, amends_result_id
                 ) VALUES (
                    :piid, :lab_patient_id, :test_name, :test_type, :is_employee, :request_date,
                    :result_summary, :result_value, 'PROCESSING', :result_date, :remarks,
                    :or_number, :payment_required, :created_by, :updated_by, :amends_result_id
                 )"
            );
            $insertStmt->execute([
                ':piid' => $original['piid'],
                ':lab_patient_id' => $original['lab_patient_id'],
                ':test_name' => $original['test_name'],
                ':test_type' => $original['test_type'],
                ':is_employee' => (int)$original['is_employee'],
                ':request_date' => $original['request_date'],
                ':result_summary' => $original['result_summary'],
                ':result_value' => $original['result_value'],
                ':result_date' => $original['result_date'],
                ':remarks' => 'Amendment of result #' . $resultId . ': ' . $reason,
                ':or_number' => $original['or_number'],
                ':payment_required' => (int)$original['payment_required'],
                ':created_by' => $username,
                ':updated_by' => $username,
                ':amends_result_id' => $resultId
            ]);
            $newId = (int)$conn->lastInsertId();

            $linkStmt = $conn->prepare(
                "UPDATE tbl_laboratory_results
                 SET amended_by_result_id = :new_id, updated_by = :updated_by, updated_at = NOW()
                 WHERE result_id = :id"
            );
            $linkStmt->execute([':new_id' => $newId, ':updated_by' => $username, ':id' => $resultId]);

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('amend_result failed: ' . $e->getMessage());
            sendResponse('error', 'Database error while amending result. Please try again.', null, 500);
        }

        sendResponse('success', 'Amendment created. Update the values, then mark it Ready for Release and release it.', [
            'result_id' => $newId,
            'amends_result_id' => $resultId,
            'status' => 'PROCESSING'
        ], 201);
    }

    if ($action !== 'save_result') {
        sendResponse('error', 'Unknown action.', null, 400);
    }

    $piid = normalizeString($payload['piid'] ?? '');
    $testName = normalizeString($payload['test_name'] ?? '');
    $testType = normalizeTestType($payload['test_type'] ?? 'BLOOD_CHEM');
    $isEmployee = normalizeFlag($payload['is_employee'] ?? 0);
    $requestDate = normalizeDateOnly($payload['request_date'] ?? '');
    $resultSummary = normalizeString($payload['result_summary'] ?? '');
    $resultValue = normalizeString($payload['result_value'] ?? '');
    $selectedBloodChemKeys = normalizeBloodChemSelection($payload['selected_tests'] ?? []);
    $status = normalizeStatus($payload['status'] ?? 'PROCESSING');
    $resultDate = normalizeDateOnly($payload['result_date'] ?? '');
    $remarks = normalizeString($payload['remarks'] ?? '');
    $orNumber = normalizeString($payload['or_number'] ?? '');
    $waiveRepeatPayment = normalizeFlag($payload['waive_repeat_payment'] ?? 0);
    // Only lab administrators may skip the repeat blood chem payment check. For everyone else the
    // check always runs; a first test of the year still finds no repeat and needs no payment.
    $saveRoleKey = strtolower(trim((string)($userData['role'] ?? 'user')));
    if (!isSuperAdminRoleKey($saveRoleKey) && !isAdministratorRoleKey($saveRoleKey)) {
        $waiveRepeatPayment = 0;
    }
    if (strlen($orNumber) > 50) {
        $orNumber = substr($orNumber, 0, 50);
    }
    $resultId = isset($payload['result_id']) ? (int)$payload['result_id'] : 0;

    // RELEASED is only reachable through the release_result action, which records the release.
    if ($resultId <= 0 && $status === 'RELEASED') {
        sendResponse('error', 'A new result cannot be saved as Released. Save it as Ready for Release, then release it.', null, 400);
    }

    if ($piid === '') {
        sendResponse('error', 'PIID is required.', null, 400);
    }

    if ($testName === '') {
        sendResponse('error', 'Test name is required.', null, 400);
    }

    if ($testType !== 'BLOOD_CHEM') {
        $parsedResult = parseResultValueJson($resultValue);
        if (empty($parsedResult) || !is_array($parsedResult)) {
            $parsedResult = [
                'schema' => 'generic_lab_v1',
                'test_type' => $testType,
                'details' => $resultValue,
                'notes' => ''
            ];
        }
        $parsedResult['schema'] = 'generic_lab_v1';
        $parsedResult['test_type'] = $testType;
        $resultValue = json_encode($parsedResult);
    }

    if ($testType === 'BLOOD_CHEM') {
        $parsedResult = parseResultValueJson($resultValue);
        if (empty($parsedResult)) {
            $parsedResult = ['schema' => 'blood_chem_v1', 'test_type' => 'BLOOD_CHEM', 'panel' => [], 'notes' => $resultValue];
        }
        $parsedResult['schema'] = 'blood_chem_v1';
        $parsedResult['test_type'] = 'BLOOD_CHEM';
        if ($resultId > 0) {
            $existingSelectionStmt = $conn->prepare('SELECT result_value FROM tbl_laboratory_results WHERE result_id = :id AND is_deleted = 0 LIMIT 1');
            $existingSelectionStmt->execute([':id' => $resultId]);
            $existingSelectionRow = $existingSelectionStmt->fetch(PDO::FETCH_ASSOC);
            $existingSelectedKeys = $existingSelectionRow ? getSelectedBloodChemKeysFromResult($existingSelectionRow) : [];
            if (!empty($existingSelectedKeys)) {
                $selectedBloodChemKeys = $existingSelectedKeys;
            }
        }
        $parsedResult['selected_tests'] = $selectedBloodChemKeys;
        $resultValue = json_encode($parsedResult);
        $testName = in_array('package', $selectedBloodChemKeys, true) ? 'Blood Chemistry Package' : 'Blood Chemistry - Selected Tests';
    }


    if ($requestDate === false || $requestDate === null) {
        sendResponse('error', 'Request date is required in YYYY-MM-DD format.', null, 400);
    }

    if ($resultDate === false) {
        sendResponse('error', 'Invalid result date format. Use YYYY-MM-DD.', null, 400);
    }

    if (in_array($status, ['READY_FOR_RELEASE', 'RELEASED'], true) && $resultDate === null) {
        sendResponse('error', 'Result date is required when status is Ready for Release or Released.', null, 400);
    }

    // Resolve patient from the lab-owned registry only.
    $labPatientId = 0;
    $labPatient = getLabPatientByPiid($conn, $piid);
    if (!$labPatient && $isEmployee === 1) {
        $employee = getLabEmployeeByPiid($conn, $piid);
        if ($employee) {
            $syncedId = upsertLabPatientFromHrmisEmployee($conn, $employee);
            if ($syncedId > 0) {
                $labPatient = getLabPatientById($conn, $syncedId);
            }
        }
    }
    if (!$labPatient) {
        sendResponse('error', 'Patient record not found in laboratory database. Add details first.', null, 404);
    }
    if ((int)($labPatient['is_employee'] ?? 0) !== $isEmployee) {
        sendResponse('error', 'Selected patient type does not match the laboratory registry record.', null, 400);
    }
    if ($labPatient) {
        // Already in lab registry — use existing record
        $labPatientId = (int)$labPatient['lab_patient_id'];
    } else {
        // First time this patient uses the lab — copy from tbl_personal_details
        $person = getPersonByPiid($conn, $piid, $personMeta);
        if (!$person) {
            sendResponse('error', 'Citizen record not found.', null, 404);
        }
        $labPatientId = upsertLabPatient($conn, $piid, $person, $personMeta);
        if ($labPatientId === 0) {
            sendResponse('error', 'Failed to register patient in laboratory records.', null, 500);
        }
    }

    if ($testType === 'BLOOD_CHEM') {
        $resultValue = removeYakapExcludedBloodChemPanelValues($resultValue, $selectedBloodChemKeys, $labPatient);
    }

    $username = normalizeString($userData['username'] ?? 'system');

    if ($resultId > 0) {
        // Fetch current status to enforce transition rules before updating
        $curStmt = $conn->prepare("SELECT status, payment_required, or_number FROM tbl_laboratory_results WHERE result_id = :id AND is_deleted = 0 LIMIT 1");
        $curStmt->execute([':id' => $resultId]);
        $currentRow = $curStmt->fetch(PDO::FETCH_ASSOC);
        if (!$currentRow) {
            sendResponse('error', 'Result record not found.', null, 404);
        }
        $currentStatus = strtoupper(trim((string)($currentRow['status'] ?? '')));

        // RELEASED is a terminal state — it can only be set by the release_result action, never by a direct edit
        if ($currentStatus === 'RELEASED') {
            sendResponse('error', 'Released results cannot be edited. Use Amend to issue a corrected result.', null, 400);
        }
        if ($status === 'RELEASED') {
            sendResponse('error', 'Status cannot be set to Released directly. Use the Release button instead.', null, 400);
        }

        // An unpaid repeat test stays Pending (or Cancelled) until its OR number is recorded.
        $effectiveOrNumber = $orNumber !== '' ? $orNumber : trim((string)($currentRow['or_number'] ?? ''));
        if ((int)($currentRow['payment_required'] ?? 0) === 1
            && $effectiveOrNumber === ''
            && !in_array($status, ['PENDING', 'CANCELLED'], true)) {
            sendResponse('error', 'This repeat test is unpaid. Enter the OR number before moving it past Pending.', null, 400);
        }

        // Saving as Ready for Release is the verification step; record who did it.
        $verifiedSql = $status === 'READY_FOR_RELEASE'
            ? 'verified_by = :verified_by, verified_at = NOW(),'
            : 'verified_by = NULL, verified_at = NULL,';

        $stmt = $conn->prepare(
            "UPDATE tbl_laboratory_results
             SET
                piid = :piid,
                lab_patient_id = :lab_patient_id,
                test_name = :test_name,
                test_type = :test_type,
                is_employee = :is_employee,
                request_date = :request_date,
                result_summary = :result_summary,
                result_value = :result_value,
                status = :status,
                result_date = :result_date,
                remarks = :remarks,
                or_number = CASE WHEN :or_number <> '' THEN :or_number ELSE or_number END,
                {$verifiedSql}
                updated_by = :updated_by,
                updated_at = NOW()
             WHERE result_id = :result_id"
        );

        $updateParams = [
            ':piid' => $piid,
            ':lab_patient_id' => $labPatientId,
            ':test_name' => $testName,
            ':test_type' => $testType,
            ':is_employee' => $isEmployee,
            ':request_date' => $requestDate,
            ':result_summary' => $resultSummary,
            ':result_value' => $resultValue,
            ':status' => $status,
            ':result_date' => $resultDate,
            ':remarks' => $remarks,
            ':or_number' => $orNumber,
            ':updated_by' => $username,
            ':result_id' => $resultId
        ];
        if ($status === 'READY_FOR_RELEASE') {
            $updateParams[':verified_by'] = $username;
        }
        $stmt->execute($updateParams);

        $fetchStmt = $conn->prepare("SELECT test_type, test_name, payment_required, or_number FROM tbl_laboratory_results WHERE result_id = :id");
        $fetchStmt->execute([':id' => $resultId]);
        $updatedRow = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        $fees = loadLabFees($conn);
        $selection = null;
        if (strtoupper((string)($updatedRow['test_type'] ?? '')) === 'BLOOD_CHEM') {
            $selection = buildBloodChemSelectionDetails($selectedBloodChemKeys, $fees, $requestDate ?: null);
            $feeAmt = (float)$selection['total_amount'];
        } else {
            $feeAmt = lookupFee($fees, $updatedRow['test_type'] ?? '', $updatedRow['test_name'] ?? '', $requestDate ?: null);
        }
        $discounted = applyLabDiscount($feeAmt !== null ? $feeAmt : null, $labPatient, $selection['selected_tests'] ?? null);
        sendResponse('success', 'Laboratory result updated.', [
            'result_id'        => $resultId,
            'payment_required' => (int)($updatedRow['payment_required'] ?? 0),
            'or_number'        => $updatedRow['or_number'] ?? '',
            'gross_amount'     => $discounted['gross_amount'],
            'discount_rate'    => $discounted['discount_rate'],
            'discount_amount'  => $discounted['discount_amount'],
            'discount_reasons' => $discounted['discount_reasons'],
            'fee_amount'       => $discounted['net_amount'],
        ]);
    }

    // Acquire an advisory lock keyed on patient + year to prevent a race condition
    // where two simultaneous saves both pass the duplicate check before either inserts.
    $lockYear = date('Y', strtotime($requestDate));
    $lockKey  = 'lab_bc_' . md5($labPatientId . '_' . $isEmployee . '_' . $lockYear);
    $lockRow  = $conn->query("SELECT GET_LOCK(" . $conn->quote($lockKey) . ", 5) AS acquired")->fetch(PDO::FETCH_ASSOC);
    if (!($lockRow['acquired'] ?? 0)) {
        sendResponse('error', 'Server is busy processing another request for this patient. Please try again.', null, 503);
    }

    // Check for duplicate blood chem this year — if found, mark as payment-required.
    // This check runs inside the advisory lock so no two requests can race past it.
    $paymentRequired = 0;
    try {
        $conn->beginTransaction();

        if ($testType === 'BLOOD_CHEM' && $waiveRepeatPayment !== 1) {
            $dupStmt = $conn->prepare(
                "SELECT result_id FROM tbl_laboratory_results
                 WHERE (
                        lab_patient_id = :lab_patient_id
                        OR (lab_patient_id IS NULL AND piid = :piid AND is_employee = :is_employee)
                   )
                   AND test_type = 'BLOOD_CHEM'
                   AND YEAR(request_date) = YEAR(:request_date)
                   AND status != 'CANCELLED'
                   AND is_deleted = 0
                 LIMIT 1"
            );
            $dupStmt->execute([
                ':lab_patient_id' => $labPatientId,
                ':piid' => $piid,
                ':is_employee' => $isEmployee,
                ':request_date' => $requestDate
            ]);
            if ($dupStmt->fetch()) {
                $paymentRequired = 1;
                // Hold at PENDING until the OR number is provided.
                if ($orNumber === '') {
                    $status = 'PENDING';
                }
            }
        }
        $isVerified = $status === 'READY_FOR_RELEASE';

        $stmt = $conn->prepare(
            "INSERT INTO tbl_laboratory_results (
                piid,
                lab_patient_id,
                test_name,
                test_type,
                is_employee,
                request_date,
                result_summary,
                result_value,
                status,
                result_date,
                remarks,
                or_number,
                payment_required,
                created_by,
                updated_by,
                verified_by,
                verified_at
             ) VALUES (
                :piid,
                :lab_patient_id,
                :test_name,
                :test_type,
                :is_employee,
                :request_date,
                :result_summary,
                :result_value,
                :status,
                :result_date,
                :remarks,
                :or_number,
                :payment_required,
                :created_by,
                :updated_by,
                :verified_by,
                " . ($isVerified ? 'NOW()' : 'NULL') . "
             )"
        );

        $stmt->execute([
            ':piid'             => $piid,
            ':lab_patient_id'   => $labPatientId,
            ':test_name'        => $testName,
            ':test_type'        => $testType,
            ':is_employee'      => $isEmployee,
            ':request_date'     => $requestDate,
            ':result_summary'   => $resultSummary,
            ':result_value'     => $resultValue,
            ':status'           => $status,
            ':result_date'      => $resultDate,
            ':remarks'          => $remarks,
            ':or_number'        => $orNumber,
            ':payment_required' => $paymentRequired,
            ':created_by'       => $username,
            ':updated_by'       => $username,
            ':verified_by'      => $isVerified ? $username : null,
        ]);

        $newId = (int)$conn->lastInsertId();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $conn->query("SELECT RELEASE_LOCK(" . $conn->quote($lockKey) . ")");
        sendResponse('error', 'Database error while saving result. Please try again.', null, 500);
    }

    // Release lock before responding — sendResponse calls exit() so finally won't run
    $conn->query("SELECT RELEASE_LOCK(" . $conn->quote($lockKey) . ")");

    $fees   = loadLabFees($conn);
    $selection = null;
    if ($testType === 'BLOOD_CHEM') {
        $selection = buildBloodChemSelectionDetails($selectedBloodChemKeys, $fees, $requestDate ?: null);
        $feeAmt = (float)$selection['total_amount'];
    } else {
        $feeAmt = lookupFee($fees, $testType, $testName, $requestDate ?: null);
    }
    $discounted = applyLabDiscount($feeAmt !== null ? $feeAmt : null, $labPatient, $selection['selected_tests'] ?? null);
    sendResponse('success', 'Laboratory result recorded.', [
        'result_id'        => $newId,
        'payment_required' => $paymentRequired,
        'gross_amount'     => $discounted['gross_amount'],
        'discount_rate'    => $discounted['discount_rate'],
        'discount_amount'  => $discounted['discount_amount'],
        'discount_reasons' => $discounted['discount_reasons'],
        'fee_amount'       => $discounted['net_amount'],
    ], 201);
}

sendResponse('error', 'Invalid request method.', null, 405);
?>
