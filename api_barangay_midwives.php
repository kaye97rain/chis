<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';

function sendResponse($status, $message, $data = null, $code = 200, $meta = null) {
    http_response_code($code);
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    if ($meta !== null) {
        $response['meta'] = $meta;
    }
    echo json_encode($response);
    exit;
}

function normalizeString($value) {
    return trim((string)$value);
}

function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', (string)$identifier) . '`';
}

function getTableColumns($conn, $table) {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = $conn->query("DESCRIBE " . quoteIdentifier($table));
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
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

function pickColumn($columns, $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function readPayload() {
    $rawInput = file_get_contents('php://input');
    if ($rawInput !== '') {
        $json = json_decode($rawInput, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
        if (!empty($_POST)) {
            return $_POST;
        }
        sendResponse('error', 'Invalid JSON format.', null, 400);
    }
    return $_POST;
}

function requireColumn($meta, $key, $label) {
    if (empty($meta[$key])) {
        sendResponse('error', "{$label} column is missing in personal details table.", null, 500);
    }
}

function buildMeta($conn) {
    $columns = getTableColumns($conn, 'tbl_personal_details');
    if (empty($columns)) {
        sendResponse('error', 'Personal details table not found.', null, 500);
    }
    return [
        'columns' => $columns,
        'piid' => pickColumn($columns, ['PIID', 'piid']),
        'surname' => pickColumn($columns, ['Surname', 'surname']),
        'firstname' => pickColumn($columns, ['FirstName', 'Firstname', 'firstname', 'first_name']),
        'middlename' => pickColumn($columns, ['MiddleName', 'Middlename', 'middlename', 'middle_name']),
        'nameext' => pickColumn($columns, ['NameExt', 'Name_Ext', 'nameext', 'name_ext']),
        'sex' => pickColumn($columns, ['Sex', 'sex']),
        'birthdate' => pickColumn($columns, ['Birthdate', 'birthdate']),
        'age' => pickColumn($columns, ['Age', 'age']),
        'barangay' => pickColumn($columns, ['Barangay', 'barangay']),
        'contact' => pickColumn($columns, ['Contact_No', 'ContactNo', 'contact_no', 'contactno']),
        'midwife' => pickColumn($columns, ['Midwife', 'midwife', 'Is_Midwife', 'is_midwife']),
        'mid_brgy' => pickColumn($columns, ['Mid_Brgy', 'Mid_BRGY', 'mid_brgy', 'midbrgy']),
        'added_by' => pickColumn($columns, ['Added_By', 'added_by']),
        'is_deleted' => pickColumn($columns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted'])
    ];
}

function displayNameSql($meta) {
    $surname = quoteIdentifier($meta['surname']);
    $first = quoteIdentifier($meta['firstname']);
    $middle = $meta['middlename'] ? quoteIdentifier($meta['middlename']) : "''";
    $ext = $meta['nameext'] ? quoteIdentifier($meta['nameext']) : "''";
    $piid = quoteIdentifier($meta['piid']);
    return "TRIM(CONCAT({$surname}, ', ', {$first}, ' ', IFNULL(CONCAT(NULLIF(LEFT({$middle}, 1), ''), '. '), ''), IFNULL(NULLIF({$ext}, ''), ''), ' ', {$piid}))";
}

function selectSql($meta) {
    $cols = [
        quoteIdentifier($meta['piid']) . ' AS PIID',
        quoteIdentifier($meta['surname']) . ' AS Surname',
        quoteIdentifier($meta['firstname']) . ' AS FirstName',
        ($meta['middlename'] ? quoteIdentifier($meta['middlename']) : "''") . ' AS MiddleName',
        ($meta['nameext'] ? quoteIdentifier($meta['nameext']) : "''") . ' AS NameExt',
        ($meta['sex'] ? quoteIdentifier($meta['sex']) : "''") . ' AS Sex',
        ($meta['birthdate'] ? quoteIdentifier($meta['birthdate']) : 'NULL') . ' AS Birthdate',
        ($meta['barangay'] ? quoteIdentifier($meta['barangay']) : "''") . ' AS Barangay',
        ($meta['contact'] ? quoteIdentifier($meta['contact']) : "''") . ' AS Contact_No',
        ($meta['midwife'] ? quoteIdentifier($meta['midwife']) : '0') . ' AS Midwife',
        ($meta['mid_brgy'] ? quoteIdentifier($meta['mid_brgy']) : "''") . ' AS Mid_Brgy',
        displayNameSql($meta) . ' AS display_name'
    ];
    return implode(",\n                ", $cols);
}

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

if (!apiUserCan($userData, 'barangay_midwives')) {
    sendResponse('error', 'You are not allowed to manage barangay midwives.', null, 403);
}

$meta = buildMeta($conn);
foreach (['piid', 'surname', 'firstname', 'midwife', 'mid_brgy'] as $requiredKey) {
    requireColumn($meta, $requiredKey, $requiredKey);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $action = normalizeString($_GET['action'] ?? 'list');
        $search = normalizeString($_GET['search'] ?? '');
        $barangay = normalizeString($_GET['barangay'] ?? '');

        if ($action === 'person_search') {
            if ($search === '' || strlen($search) < 2) {
                sendResponse('success', 'Enter at least 2 characters.', [], 200, ['count' => 0]);
            }
            $where = [
                '(' . quoteIdentifier($meta['surname']) . ' LIKE :search OR ' . quoteIdentifier($meta['firstname']) . ' LIKE :search OR ' . quoteIdentifier($meta['piid']) . ' LIKE :search)'
            ];
            $params = [':search' => '%' . $search . '%'];
            if ($meta['is_deleted']) {
                $where[] = quoteIdentifier($meta['is_deleted']) . ' = 0';
            }
            $stmt = $conn->prepare("
                SELECT
                    " . selectSql($meta) . "
                FROM tbl_personal_details
                WHERE " . implode(' AND ', $where) . "
                ORDER BY " . quoteIdentifier($meta['surname']) . " ASC, " . quoteIdentifier($meta['firstname']) . " ASC
                LIMIT 20
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            sendResponse('success', 'Personal records found.', $rows, 200, ['count' => count($rows)]);
        }

        if ($action === 'midwife_history') {
            $piid = (int)($_GET['piid'] ?? 0);
            $midBrgy = normalizeString($_GET['mid_brgy'] ?? '');

            if ($piid > 0 && $midBrgy === '') {
                $stmt = $conn->prepare("
                    SELECT " . quoteIdentifier($meta['mid_brgy']) . "
                    FROM tbl_personal_details
                    WHERE " . quoteIdentifier($meta['piid']) . " = ?
                    LIMIT 1
                ");
                $stmt->execute([$piid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $midBrgy = normalizeString($row[$meta['mid_brgy']] ?? '');
            }

            if ($midBrgy === '') {
                sendResponse('success', 'No barangay assigned.', [], 200, ['count' => 0, 'barangay' => '']);
            }

            try {
                $cStmt = $conn->query("DESCRIBE tbl_checkedout_items");
                $cCols = $cStmt ? array_column($cStmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
            } catch (Exception $e) {
                $cCols = [];
            }

            if (empty($cCols)) {
                sendResponse('success', 'Transaction data unavailable.', [], 200, ['count' => 0, 'barangay' => $midBrgy]);
            }

            $cRef      = pickColumn($cCols, ['RIS_Number', 'ris_number', 'Reference_No', 'reference_no']);
            $cBarcode  = pickColumn($cCols, ['Barcode', 'barcode']);
            $cQty      = pickColumn($cCols, ['Quantity', 'quantity']);
            $cDate     = pickColumn($cCols, ['Checkout_Date', 'checkout_date']);
            $cBy       = pickColumn($cCols, ['Checkout_By', 'checkout_by']);
            $cTrfDate  = pickColumn($cCols, ['Transfer_Date', 'transfer_date']);
            $cTrfBy    = pickColumn($cCols, ['Transfer_By', 'transfer_by']);
            $cCat      = pickColumn($cCols, ['Category', 'category']);
            $cBrgy     = pickColumn($cCols, ['Barangay', 'barangay']);
            $cDest     = pickColumn($cCols, ['Destination', 'destination']);
            $cType     = pickColumn($cCols, ['Transaction_Type', 'transaction_type']);
            $cDel      = pickColumn($cCols, ['isDeleted', 'IsDeleted', 'isdeleted']);

            if (!$cRef || !$cQty || !$cBrgy) {
                sendResponse('success', 'Transaction data unavailable.', [], 200, ['count' => 0, 'barangay' => $midBrgy]);
            }

            $refExpr  = quoteIdentifier($cRef);
            $qtyExpr  = quoteIdentifier($cQty);
            $dateExpr = $cDate ? quoteIdentifier($cDate) : 'NULL';
            $byExpr   = $cBy ? quoteIdentifier($cBy) : "''";
            $trfDateExpr = $cTrfDate ? quoteIdentifier($cTrfDate) : 'NULL';
            $trfByExpr   = $cTrfBy ? quoteIdentifier($cTrfBy) : "''";
            $catExpr  = $cCat ? quoteIdentifier($cCat) : "''";
            $destExpr = $cDest ? quoteIdentifier($cDest) : "''";
            $typeExpr = $cType ? ("UPPER(COALESCE(NULLIF(TRIM(" . quoteIdentifier($cType) . "), ''), ''))") : "''";

            $effectiveDateExpr = $cTrfDate ? "COALESCE({$trfDateExpr}, {$dateExpr})" : $dateExpr;
            $performedByExpr   = "COALESCE(NULLIF(TRIM({$trfByExpr}), ''), {$byExpr})";

            $activeQtyExpr  = $cDel ? "SUM(CASE WHEN {$cDel} = 0 THEN {$qtyExpr} ELSE 0 END)" : "SUM({$qtyExpr})";
            $activeRowsExpr = $cDel ? "SUM(CASE WHEN {$cDel} = 0 THEN 1 ELSE 0 END)" : 'COUNT(*)';

            $histStmt = $conn->prepare("
                SELECT
                    {$refExpr}                          AS reference_no,
                    MIN({$effectiveDateExpr})            AS transaction_date,
                    MAX({$performedByExpr})              AS performed_by,
                    MAX({$catExpr})                      AS category,
                    MAX({$destExpr})                     AS destination,
                    MAX({$typeExpr})                     AS transaction_type,
                    SUM({$qtyExpr})                      AS total_quantity,
                    {$activeQtyExpr}                     AS active_quantity,
                    {$activeRowsExpr}                    AS active_rows,
                    COUNT(*)                             AS total_rows
                FROM tbl_checkedout_items
                WHERE " . quoteIdentifier($cBrgy) . " = :mid_brgy
                  AND {$refExpr} IS NOT NULL
                  AND TRIM({$refExpr}) <> ''
                GROUP BY {$refExpr}
                ORDER BY MIN({$effectiveDateExpr}) DESC
                LIMIT 200
            ");
            $histStmt->execute([':mid_brgy' => $midBrgy]);
            $histRows = $histStmt->fetchAll(PDO::FETCH_ASSOC);

            $totalItems = 0;
            $totalActive = 0;
            foreach ($histRows as &$hr) {
                $activeRows = (int)($hr['active_rows'] ?? 0);
                $totalQty   = (int)($hr['total_quantity'] ?? 0);
                $activeQty  = (int)($hr['active_quantity'] ?? 0);
                $dest       = trim((string)($hr['destination'] ?? ''));
                $type       = strtoupper(trim((string)($hr['transaction_type'] ?? '')));
                $ref        = strtoupper(trim((string)($hr['reference_no'] ?? '')));

                $isTransfer = ($type === 'TRANSFER') || (strpos($ref, 'TRF') === 0) || ($dest !== '');
                $hr['transaction_kind'] = $isTransfer ? 'TRANSFER' : 'CHECKOUT';
                $hr['status']           = ($cDel && $activeRows === 0) ? 'VOIDED' : 'ACTIVE';
                $hr['total_quantity']   = $totalQty;
                $hr['active_quantity']  = $activeQty;
                $hr['total_rows']       = (int)($hr['total_rows'] ?? 0);

                $totalItems  += $totalQty;
                $totalActive += $activeQty;
            }
            unset($hr);

            sendResponse('success', 'Midwife transaction history retrieved.', $histRows, 200, [
                'count'         => count($histRows),
                'barangay'      => $midBrgy,
                'total_items'   => $totalItems,
                'active_items'  => $totalActive
            ]);
        }

        if ($action !== 'list') {
            sendResponse('error', 'Unknown action.', null, 400);
        }

        $where = [quoteIdentifier($meta['midwife']) . ' = 1'];
        $params = [];
        if ($meta['is_deleted']) {
            $where[] = quoteIdentifier($meta['is_deleted']) . ' = 0';
        }
        if ($barangay !== '') {
            $where[] = quoteIdentifier($meta['mid_brgy']) . ' = :barangay';
            $params[':barangay'] = $barangay;
        }
        if ($search !== '') {
            $where[] = '(' . quoteIdentifier($meta['surname']) . ' LIKE :search OR ' . quoteIdentifier($meta['firstname']) . ' LIKE :search OR ' . quoteIdentifier($meta['mid_brgy']) . ' LIKE :search OR ' . quoteIdentifier($meta['piid']) . ' LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $stmt = $conn->prepare("
            SELECT
                " . selectSql($meta) . "
            FROM tbl_personal_details
            WHERE " . implode(' AND ', $where) . "
            ORDER BY " . quoteIdentifier($meta['mid_brgy']) . " ASC, " . quoteIdentifier($meta['surname']) . " ASC, " . quoteIdentifier($meta['firstname']) . " ASC
            LIMIT 500
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse('success', 'Barangay midwives retrieved.', $rows, 200, ['count' => count($rows)]);
    } catch (Exception $e) {
        sendResponse('error', 'Unable to load barangay midwives: ' . $e->getMessage(), null, 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Invalid request method.', null, 405);
}

// COA and Accounting can view the directory but not change it.
if (!userCanWrite($userData)) {
    sendResponse('error', 'This role has view-only access.', null, 403);
}

$payload = readPayload();
$action = normalizeString($payload['action'] ?? 'save');

try {
    if ($action === 'archive') {
        $piid = (int)($payload['piid'] ?? 0);
        if ($piid <= 0) {
            sendResponse('error', 'Midwife record is required.', null, 400);
        }
        $stmt = $conn->prepare("
            UPDATE tbl_personal_details
            SET " . quoteIdentifier($meta['midwife']) . " = 0,
                " . quoteIdentifier($meta['mid_brgy']) . " = ''
            WHERE " . quoteIdentifier($meta['piid']) . " = ?
            LIMIT 1
        ");
        $stmt->execute([$piid]);
        sendResponse('success', 'Midwife removed from barangay list.');
    }

    if (!in_array($action, ['save', 'assign'], true)) {
        sendResponse('error', 'Unknown action.', null, 400);
    }

    $piid = (int)($payload['piid'] ?? 0);
    $surname = normalizeString($payload['surname'] ?? '');
    $firstName = normalizeString($payload['first_name'] ?? ($payload['firstname'] ?? ''));
    $middleName = normalizeString($payload['middle_name'] ?? ($payload['middlename'] ?? ''));
    $nameExt = normalizeString($payload['name_ext'] ?? ($payload['nameext'] ?? ''));
    $sex = normalizeString($payload['sex'] ?? '');
    $birthdate = normalizeString($payload['birthdate'] ?? '');
    $barangay = normalizeString($payload['barangay'] ?? '');
    $midBrgy = normalizeString($payload['mid_brgy'] ?? '');
    $contact = normalizeString($payload['contact_no'] ?? '');

    if ($midBrgy === '') {
        sendResponse('error', 'Assigned barangay is required.', null, 400);
    }

    if ($piid > 0) {
        $parts = [
            quoteIdentifier($meta['midwife']) . ' = 1',
            quoteIdentifier($meta['mid_brgy']) . ' = :mid_brgy'
        ];
        $params = [':mid_brgy' => $midBrgy, ':piid' => $piid];
        foreach ([
            'surname' => $surname,
            'firstname' => $firstName,
            'middlename' => $middleName,
            'nameext' => $nameExt,
            'sex' => $sex,
            'birthdate' => $birthdate,
            'barangay' => $barangay,
            'contact' => $contact
        ] as $key => $value) {
            if ($value !== '' && !empty($meta[$key])) {
                $param = ':' . $key;
                $parts[] = quoteIdentifier($meta[$key]) . " = {$param}";
                $params[$param] = $value;
            }
        }
        $stmt = $conn->prepare("
            UPDATE tbl_personal_details
            SET " . implode(', ', $parts) . "
            WHERE " . quoteIdentifier($meta['piid']) . " = :piid
            LIMIT 1
        ");
        $stmt->execute($params);
        sendResponse('success', 'Midwife assignment saved.');
    }

    if ($surname === '' || $firstName === '') {
        sendResponse('error', 'Surname and first name are required for a new midwife.', null, 400);
    }

    $cols = [
        quoteIdentifier($meta['surname']),
        quoteIdentifier($meta['firstname']),
        quoteIdentifier($meta['midwife']),
        quoteIdentifier($meta['mid_brgy'])
    ];
    $vals = ['?', '?', '1', '?'];
    $params = [$surname, $firstName, $midBrgy];
    foreach ([
        'middlename' => $middleName,
        'nameext' => $nameExt,
        'sex' => $sex,
        'birthdate' => $birthdate,
        'barangay' => $barangay,
        'contact' => $contact,
        'added_by' => normalizeString($userData['username'] ?? 'system')
    ] as $key => $value) {
        if ($value !== '' && !empty($meta[$key])) {
            $cols[] = quoteIdentifier($meta[$key]);
            $vals[] = '?';
            $params[] = $value;
        }
    }
    if ($meta['is_deleted']) {
        $cols[] = quoteIdentifier($meta['is_deleted']);
        $vals[] = '0';
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_personal_details (" . implode(', ', $cols) . ")
        VALUES (" . implode(', ', $vals) . ")
    ");
    $stmt->execute($params);
    sendResponse('success', 'Midwife added to barangay list.', ['piid' => (int)$conn->lastInsertId()]);
} catch (Exception $e) {
    sendResponse('error', 'Unable to save barangay midwife: ' . $e->getMessage(), null, 500);
}
