<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';
require_once 'transaction_logger.php';

function sendResponse($status, $message, $data = null, $code = 200, $meta = null) {
    http_response_code($code);
    $response = [
        'status' => $status,
        'message' => $message
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    if ($meta !== null) {
        $response['meta'] = $meta;
    }
    echo json_encode($response);
    exit;
}

function getTableColumns($conn, $table) {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $stmt = $conn->query("DESCRIBE {$table}");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

function getIsDeletedColumn($columns) {
    return pickColumn($columns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
}

function normalizeString($value) {
    return trim((string)$value);
}

function normalizeWorkArea($value, $default = 'CHO') {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return $default;
    }
    $value = preg_replace('/[^A-Z0-9 _-]/', '', $value);
    return $value !== '' ? $value : $default;
}

function normalizeDateOnly($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    try {
        $date = new DateTime($value);
    } catch (Exception $e) {
        return null;
    }
    return $date->format('Y-m-d');
}

function calculateAgeFromBirthdate($birthdate) {
    if (!$birthdate) {
        return null;
    }
    try {
        $birth = new DateTime($birthdate);
    } catch (Exception $e) {
        return null;
    }
    $today = new DateTime('today');
    return (int)$birth->diff($today)->y;
}

define('LOW_STOCK_THRESHOLD', 10);

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}

$categories = [
    'medicine' => [
        'table' => 'tbl_item_medicine',
        'label' => 'Medicine',
        'low_stock_block' => true
    ],
    'medical_supplies' => [
        'table' => 'tbl_item_medical_supplies',
        'label' => 'Medical Supplies',
        'low_stock_block' => false
    ],
    'vaccines' => [
        'table' => 'tbl_item_vaccines',
        'label' => 'Vaccines',
        'low_stock_block' => false
    ],
    'lab_reagents' => [
        'table' => 'tbl_item_lab_reagents',
        'label' => 'Lab Reagents',
        'low_stock_block' => false
    ]
];

$checkedoutColumns = getTableColumns($conn, 'tbl_checkedout_items');
$checkedoutDeletedColumn = getIsDeletedColumn($checkedoutColumns);
$checkedoutWorkAreaColumn = pickColumn($checkedoutColumns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
$checkedoutDateConsultationColumn = pickColumn($checkedoutColumns, ['Date_Consultation', 'date_consultation', 'Consultation_Date', 'consultation_date']);
$checkedoutYakapMemberColumn = pickColumn($checkedoutColumns, ['Yakap_Member', 'yakap_member', 'Is_Yakap_Member', 'is_yakap_member']);
$checkedoutYakapIdColumn = pickColumn($checkedoutColumns, ['Yakap_ID_Number', 'yakap_id_number', 'Yakap_ID', 'yakap_id']);
$userLocationWorkArea = normalizeWorkArea($userData['location'] ?? 'CHO');

function buildOrderClause($columns) {
    $orderParts = [];
    if (in_array('Date_Added', $columns, true)) {
        $orderParts[] = 'Date_Added DESC';
    }
    if (in_array('IID', $columns, true)) {
        $orderParts[] = 'IID DESC';
    }
    if (empty($orderParts)) {
        return '';
    }
    return ' ORDER BY ' . implode(', ', $orderParts);
}

function getAvailableQuantity(
    $conn,
    $itemTable,
    $barcode,
    $itemDeletedColumn,
    $itemWorkAreaColumn,
    $checkedoutDeletedColumn,
    $checkedoutWorkAreaColumn,
    $requestedWorkArea
) {
    $itemFilter = $itemDeletedColumn ? " AND {$itemDeletedColumn} = 0" : '';
    $checkoutFilter = $checkedoutDeletedColumn ? " AND {$checkedoutDeletedColumn} = 0" : '';
    $params = [':barcode' => $barcode];

    if ($itemWorkAreaColumn) {
        $itemFilter .= " AND UPPER(COALESCE(NULLIF(TRIM({$itemWorkAreaColumn}), ''), 'CHO')) = :work_area_item";
        $params[':work_area_item'] = $requestedWorkArea;
    }
    if ($checkedoutWorkAreaColumn) {
        $checkoutFilter .= " AND UPPER(COALESCE(NULLIF(TRIM({$checkedoutWorkAreaColumn}), ''), 'CHO')) = :work_area_checkout";
        $params[':work_area_checkout'] = $requestedWorkArea;
    }

    $stmt = $conn->prepare("
        SELECT COALESCE(stock.total_quantity, 0) - COALESCE(checkout.total_checked_out, 0) AS available
        FROM (
            SELECT SUM(Quantity) AS total_quantity
            FROM {$itemTable}
            WHERE Barcode_Number = :barcode{$itemFilter}
        ) stock
        LEFT JOIN (
            SELECT SUM(Quantity) AS total_checked_out
            FROM tbl_checkedout_items
            WHERE Barcode = :barcode{$checkoutFilter}
        ) checkout ON 1=1
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['available'] ?? 0);
}

function findItemByBarcode($conn, $barcode, $categories, $checkedoutDeletedColumn, $checkedoutWorkAreaColumn, $requestedWorkArea) {
    foreach ($categories as $key => $config) {
        $table = $config['table'];
        $columns = getTableColumns($conn, $table);
        $itemDeletedColumn = getIsDeletedColumn($columns);
        $itemWorkAreaColumn = pickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
        $itemFilter = $itemDeletedColumn ? " AND {$itemDeletedColumn} = 0" : '';
        if ($itemWorkAreaColumn) {
            $itemFilter .= " AND UPPER(COALESCE(NULLIF(TRIM({$itemWorkAreaColumn}), ''), 'CHO')) = :work_area";
        }
        $orderClause = buildOrderClause($columns);

        $stmt = $conn->prepare("
            SELECT Barcode, Barcode_Number, Item, Description, Entity, Unit_Cost, Expiry_Date, Quantity, Date_Added
            FROM {$table}
            WHERE (Barcode_Number = :barcode OR Barcode = :barcode){$itemFilter}
            {$orderClause}
            LIMIT 1
        ");
        $params = [':barcode' => $barcode];
        if ($itemWorkAreaColumn) {
            $params[':work_area'] = $requestedWorkArea;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $barcodeNumber = $row['Barcode_Number'] ?: ($row['Barcode'] ?: $barcode);
            $available = getAvailableQuantity(
                $conn,
                $table,
                $barcodeNumber,
                $itemDeletedColumn,
                $itemWorkAreaColumn,
                $checkedoutDeletedColumn,
                $checkedoutWorkAreaColumn,
                $requestedWorkArea
            );

            return [
                'category_key' => $key,
                'category_label' => $config['label'],
                'low_stock_block' => $config['low_stock_block'],
                'item' => $row,
                'barcode_number' => $barcodeNumber,
                'available' => $available,
                'work_area' => $requestedWorkArea
            ];
        }
    }
    return null;
}

function formatLookupItem($result) {
    $item = $result['item'];
    $available = (int)($result['available'] ?? 0);
    $lowStock = $available > 0 && $available <= LOW_STOCK_THRESHOLD;
    $depleted = $available <= 0;
    $expiredOn = stockBatchExpiredOn($item['Expiry_Date'] ?? '');
    $blockCheckout = $depleted || $expiredOn !== null;
    $blockReason = null;
    $warning = null;

    if ($expiredOn !== null) {
        $blockReason = "This batch expired on {$expiredOn} and cannot be released.";
    } elseif ($depleted) {
        $blockReason = 'Item already depleted!';
    } elseif ($lowStock) {
        $warning = 'Item is in low stock (less than 10 pcs). You can proceed up to available quantity.';
    }

    return [
        'barcode' => $result['barcode_number'],
        'item' => $item['Item'] ?? '',
        'description' => $item['Description'] ?? '',
        'entity' => $item['Entity'] ?? '',
        'unit_cost' => $item['Unit_Cost'] ?? 0,
        'expiry_date' => $item['Expiry_Date'] ?? null,
        'category_key' => $result['category_key'],
        'category_label' => $result['category_label'],
        'available' => $available,
        'low_stock' => $lowStock,
        'depleted' => $depleted,
        'expired' => $expiredOn !== null,
        'block_checkout' => $blockCheckout,
        'block_reason' => $blockReason,
        'warning' => $warning
    ];
}

function readPayload() {
    $rawInput = file_get_contents("php://input");
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

function resolvePersonId($conn, $person) {
    $table = 'tbl_personal_details';
    $columns = getTableColumns($conn, $table);

    $colPIID = pickColumn($columns, ['PIID', 'piid']);
    $colSurname = pickColumn($columns, ['Surname', 'surname']);
    $colFirstName = pickColumn($columns, ['FirstName', 'Firstname', 'firstname', 'first_name']);
    $colMiddleName = pickColumn($columns, ['MiddleName', 'Middlename', 'middlename', 'middle_name']);
    $colNameExt = pickColumn($columns, ['NameExt', 'Name_Ext', 'nameext', 'name_ext']);

    if (!$colPIID || !$colSurname || !$colFirstName) {
        sendResponse('error', 'Personal details table missing required columns.', null, 500);
    }

    $surname = normalizeString($person['surname'] ?? '');
    $firstname = normalizeString($person['firstname'] ?? '');
    $middlename = normalizeString($person['middlename'] ?? '');
    $nameext = normalizeString($person['nameext'] ?? '');

    if ($surname === '' || $firstname === '') {
        sendResponse('error', 'Surname and first name are required.', null, 400);
    }

    $checkSql = "SELECT {$colPIID} FROM {$table} WHERE {$colSurname} = ? AND {$colFirstName} = ?";
    $params = [$surname, $firstname];
    if ($colMiddleName) {
        $checkSql .= " AND {$colMiddleName} = ?";
        $params[] = $middlename;
    }
    if ($colNameExt) {
        $checkSql .= " AND {$colNameExt} = ?";
        $params[] = $nameext;
    }
    $checkSql .= " LIMIT 1";

    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute($params);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && isset($existing[$colPIID])) {
        return (string)$existing[$colPIID];
    }

    $fieldMap = [
        'surname' => $colSurname,
        'firstname' => $colFirstName,
        'middlename' => $colMiddleName,
        'nameext' => $colNameExt,
        'purok' => pickColumn($columns, ['Purok', 'purok']),
        'street' => pickColumn($columns, ['Street', 'street']),
        'city' => pickColumn($columns, ['City', 'city']),
        'barangay' => pickColumn($columns, ['Barangay', 'barangay']),
        'contact_no' => pickColumn($columns, ['Contact_No', 'ContactNo', 'contact_no', 'contactno']),
        'age' => pickColumn($columns, ['Age', 'age']),
        'birthdate' => pickColumn($columns, ['Birthdate', 'birthdate']),
        'sex' => pickColumn($columns, ['Sex', 'sex']),
        'ip' => pickColumn($columns, ['IP', 'ip']),
        'mns' => pickColumn($columns, ['MNS', 'mns']),
        'pwd' => pickColumn($columns, ['PWD', 'pwd']),
        'senior' => pickColumn($columns, ['SENIOR', 'Senior', 'senior'])
    ];

    $insertCols = [];
    $insertVals = [];
    $insertParams = [];

    $addField = function($column, $value) use (&$insertCols, &$insertVals, &$insertParams) {
        if ($column && $value !== null && $value !== '') {
            $insertCols[] = $column;
            $insertVals[] = '?';
            $insertParams[] = $value;
        }
    };

    $addField($fieldMap['surname'], $surname);
    $addField($fieldMap['firstname'], $firstname);
    if ($fieldMap['middlename']) {
        $addField($fieldMap['middlename'], $middlename);
    }
    if ($fieldMap['nameext']) {
        $addField($fieldMap['nameext'], $nameext);
    }

    $addField($fieldMap['purok'], normalizeString($person['purok'] ?? ''));
    $addField($fieldMap['street'], normalizeString($person['street'] ?? ''));
    $addField($fieldMap['city'], normalizeString($person['city'] ?? ''));
    $addField($fieldMap['barangay'], normalizeString($person['barangay'] ?? ''));
    $addField($fieldMap['contact_no'], normalizeString($person['contact_no'] ?? ''));

    $birthdate = normalizeDateOnly($person['birthdate'] ?? '');
    if ($birthdate) {
        $addField($fieldMap['birthdate'], $birthdate);
    }

    // Always compute from birthdate when available so stored age stays current
    $age = $birthdate ? calculateAgeFromBirthdate($birthdate) : ($person['age'] ?? null);
    if ($age !== null && $age !== '') {
        $addField($fieldMap['age'], (int)$age);
    }

    $sex = strtoupper(normalizeString($person['sex'] ?? ''));
    if ($sex === 'MALE' || $sex === 'FEMALE') {
        $addField($fieldMap['sex'], $sex);
    }

    if (!empty($person['ip'])) {
        $addField($fieldMap['ip'], 1);
    }
    if (!empty($person['mns'])) {
        $addField($fieldMap['mns'], 1);
    }
    if (!empty($person['pwd'])) {
        $addField($fieldMap['pwd'], 1);
    }
    if (!empty($person['senior'])) {
        $addField($fieldMap['senior'], 1);
    }

    if (empty($insertCols)) {
        sendResponse('error', 'Unable to create personal record.', null, 400);
    }

    $insertSql = "INSERT INTO {$table} (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->execute($insertParams);

    $personId = $conn->lastInsertId();
    if ($personId) {
        return (string)$personId;
    }

    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute($params);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && isset($existing[$colPIID])) {
        return (string)$existing[$colPIID];
    }

    sendResponse('error', 'Unable to resolve personal record.', null, 500);
}

function generateRisNumber($conn, $checkedoutColumns) {
    $hasCid = in_array('CID', $checkedoutColumns, true);
    if ($hasCid) {
        $stmt = $conn->query("SELECT COALESCE(MAX(CID), 0) AS max_cid FROM tbl_checkedout_items");
        $next = (int)$stmt->fetchColumn() + 1;
        // Zero-padded date (RIS20260105...) so the date/sequence boundary is unambiguous.
        return 'RIS' . date('Ymd') . $next;
    }
    return 'RIS' . date('YmdHis');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requestedWorkArea = normalizeWorkArea($_GET['work_area'] ?? $userLocationWorkArea);
    if (!userCanAccessWorkArea($userData, $requestedWorkArea)) {
        sendResponse('error', 'You are not allowed to access this work area.', null, 403);
    }
    // Stocks Distribution (clinics.php) also reads the barangay list from here.
    requireApiCapability($userData, ['checkout', 'clinics'], $requestedWorkArea);
    $action = $_GET['action'] ?? 'lookup';

    if ($action === 'lookup') {
        $barcode = normalizeString($_GET['barcode'] ?? '');
        if ($barcode === '') {
            sendResponse('error', 'Barcode is required.', null, 400);
        }

        $result = findItemByBarcode(
            $conn,
            $barcode,
            $categories,
            $checkedoutDeletedColumn,
            $checkedoutWorkAreaColumn,
            $requestedWorkArea
        );
        if (!$result) {
            sendResponse('error', 'No record found.', null, 404);
        }

        $payload = formatLookupItem($result);

        $message = 'Item found.';
        if ($payload['block_reason']) {
            $message = $payload['block_reason'];
        } elseif ($payload['low_stock']) {
            $message = 'Item is in low stock (less than 10 pcs). You can proceed up to available quantity.';
        }

        sendResponse('success', $message, $payload);
    }

    if ($action === 'item_search') {
        $search = normalizeString($_GET['search'] ?? '');
        if ($search === '' || strlen($search) < 2) {
            sendResponse('success', 'No search term.', [], 200, ['count' => 0]);
        }

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        if ($limit <= 0) {
            $limit = 10;
        }
        if ($limit > 25) {
            $limit = 25;
        }

        $results = [];
        $seen = [];

        foreach ($categories as $key => $config) {
            $table = $config['table'];
            $columns = getTableColumns($conn, $table);
            $itemDeletedColumn = getIsDeletedColumn($columns);
            $itemWorkAreaColumn = pickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
            $itemFilter = $itemDeletedColumn ? " AND {$itemDeletedColumn} = 0" : '';
            if ($itemWorkAreaColumn) {
                $itemFilter .= " AND UPPER(COALESCE(NULLIF(TRIM({$itemWorkAreaColumn}), ''), 'CHO')) = :work_area";
            }

            $stmt = $conn->prepare("
                SELECT Barcode, Barcode_Number, Item, Description, Entity, Unit_Cost, Expiry_Date
                FROM {$table}
                WHERE (Item LIKE :search OR Description LIKE :search OR Barcode_Number LIKE :search OR Barcode LIKE :search)
                {$itemFilter}
                ORDER BY Item ASC
                LIMIT {$limit}
            ");
            $params = [':search' => '%' . $search . '%'];
            if ($itemWorkAreaColumn) {
                $params[':work_area'] = $requestedWorkArea;
            }
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $barcodeNumber = $row['Barcode_Number'] ?: ($row['Barcode'] ?? '');
                if ($barcodeNumber === '' || isset($seen[$barcodeNumber])) {
                    continue;
                }
                $seen[$barcodeNumber] = true;

                $available = getAvailableQuantity(
                    $conn,
                    $table,
                    $barcodeNumber,
                    $itemDeletedColumn,
                    $itemWorkAreaColumn,
                    $checkedoutDeletedColumn,
                    $checkedoutWorkAreaColumn,
                    $requestedWorkArea
                );
                if ($available <= 0) {
                    continue;
                }
                $payload = formatLookupItem([
                    'category_key' => $key,
                    'category_label' => $config['label'],
                    'low_stock_block' => $config['low_stock_block'],
                    'item' => $row,
                    'barcode_number' => $barcodeNumber,
                    'available' => $available,
                    'work_area' => $requestedWorkArea
                ]);
                $payload['display'] = trim(($row['Item'] ?? '') . ' - ' . ($row['Description'] ?? ''));
                $results[] = $payload;

                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        sendResponse('success', 'Item search results.', $results, 200, ['count' => count($results)]);
    }

    if ($action === 'person_search') {
        $search = normalizeString($_GET['search'] ?? '');
        if ($search === '' || strlen($search) < 2) {
            sendResponse('success', 'No search term.', [], 200, ['count' => 0]);
        }

        $columns = getTableColumns($conn, 'tbl_personal_details');
        $colPIID = pickColumn($columns, ['PIID', 'piid']);
        $colSurname = pickColumn($columns, ['Surname', 'surname']);
        $colFirstName = pickColumn($columns, ['FirstName', 'Firstname', 'firstname', 'first_name']);
        $colMiddleName = pickColumn($columns, ['MiddleName', 'Middlename', 'middlename', 'middle_name']);
        $colNameExt = pickColumn($columns, ['NameExt', 'Name_Ext', 'nameext', 'name_ext']);

        if (!$colPIID || !$colSurname || !$colFirstName) {
            sendResponse('error', 'Personal details table missing required columns.', null, 500);
        }

        $displayName = "CONCAT(
            {$colSurname}, ', ',
            {$colFirstName}, ' ',
            " . ($colMiddleName ? "IFNULL(CONCAT(NULLIF(LEFT({$colMiddleName}, 1), ''), '. '), '')" : "''") . ",
            " . ($colNameExt ? "IFNULL(CONCAT(NULLIF({$colNameExt}, ''), ' '), '')" : "''") . ",
            {$colPIID}
        )";

        $stmt = $conn->prepare("
            SELECT
                {$colPIID} AS PIID,
                {$colSurname} AS Surname,
                {$colFirstName} AS FirstName,
                " . ($colMiddleName ? "{$colMiddleName} AS MiddleName," : "'' AS MiddleName,") . "
                " . ($colNameExt ? "{$colNameExt} AS NameExt," : "'' AS NameExt,") . "
                {$displayName} AS display_name
            FROM tbl_personal_details
            WHERE (
                {$colSurname} LIKE :search
                OR {$colFirstName} LIKE :search
                OR {$colPIID} LIKE :search
                " . ($colMiddleName ? " OR {$colMiddleName} LIKE :search" : "") . "
                " . ($colNameExt ? " OR {$colNameExt} LIKE :search" : "") . "
            )
            ORDER BY {$colSurname} ASC, {$colFirstName} ASC, {$colPIID} DESC
            LIMIT 25
        ");
        $stmt->execute([':search' => '%' . $search . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse('success', 'Person search results.', $rows, 200, ['count' => count($rows)]);
    }

    if ($action === 'person_details') {
        $piid = normalizeString($_GET['piid'] ?? '');
        if ($piid === '') {
            sendResponse('error', 'PIID is required.', null, 400);
        }

        $stmt = $conn->prepare("SELECT * FROM tbl_personal_details WHERE PIID = ? LIMIT 1");
        $stmt->execute([$piid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            sendResponse('error', 'No record found.', null, 404);
        }

        // Always compute age from birthdate so it stays current
        $columns = getTableColumns($conn, 'tbl_personal_details');
        $birthdateCol = pickColumn($columns, ['Birthdate', 'birthdate']);
        $ageCol       = pickColumn($columns, ['Age', 'age']);
        if ($birthdateCol && $ageCol) {
            $bdate = normalizeDateOnly($row[$birthdateCol] ?? '');
            if ($bdate) {
                $row[$ageCol] = calculateAgeFromBirthdate($bdate);
            }
        }

        sendResponse('success', 'Person found.', $row);
    }

    if ($action === 'barangay_list') {
        $columns = getTableColumns($conn, 'tbl_personal_details');
        $barangayCol = pickColumn($columns, ['Barangay', 'barangay']);
        $midBrgyCol = pickColumn($columns, ['Mid_Brgy', 'mid_brgy', 'Mid_BRGY']);

        $queries = [];
        if ($barangayCol) {
            $queries[] = "SELECT DISTINCT {$barangayCol} AS name FROM tbl_personal_details WHERE {$barangayCol} <> ''";
        }
        if ($midBrgyCol) {
            $queries[] = "SELECT DISTINCT {$midBrgyCol} AS name FROM tbl_personal_details WHERE {$midBrgyCol} <> ''";
        }

        if (empty($queries)) {
            sendResponse('success', 'No barangay data.', [], 200, ['count' => 0]);
        }

        $sql = implode(" UNION ", $queries) . " ORDER BY name ASC";
        $stmt = $conn->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse('success', 'Barangay list.', $rows, 200, ['count' => count($rows)]);
    }

    if ($action === 'midwives') {
        $barangay = normalizeString($_GET['barangay'] ?? '');
        if ($barangay === '') {
            sendResponse('success', 'No barangay provided.', [], 200, ['count' => 0]);
        }

        $columns = getTableColumns($conn, 'tbl_personal_details');
        $midBrgyCol = pickColumn($columns, ['Mid_Brgy', 'mid_brgy', 'Mid_BRGY']);
        $midwifeCol = pickColumn($columns, ['Midwife', 'midwife', 'Is_Midwife', 'is_midwife']);
        if (!$midBrgyCol) {
            sendResponse('success', 'Midwife column not found.', [], 200, ['count' => 0]);
        }

        $midwifeFilter = $midwifeCol ? " AND COALESCE({$midwifeCol}, 0) = 1" : '';
        $stmt = $conn->prepare("
            SELECT
                PIID,
                Surname,
                FirstName,
                MiddleName,
                NameExt,
                CONCAT(Surname, ', ', FirstName, ' ', LEFT(MiddleName, 1), '. ', NameExt, ' ', PIID) AS display_name
            FROM tbl_personal_details
            WHERE {$midBrgyCol} = ?
              {$midwifeFilter}
            ORDER BY Surname ASC, FirstName ASC
        ");
        $stmt->execute([$barangay]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse('success', 'Midwives retrieved.', $rows, 200, ['count' => count($rows)]);
    }

    sendResponse('error', 'Unknown action.', null, 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Invalid request method.', null, 405);
}

if (!userCanWrite($userData)) {
    sendResponse('error', 'This role has view-only access.', null, 403);
}

$payload = readPayload();
$action = $payload['action'] ?? 'checkout';
if ($action !== 'checkout') {
    sendResponse('error', 'Unknown action.', null, 400);
}
$requestedWorkArea = normalizeWorkArea($payload['work_area'] ?? $userLocationWorkArea);
if (!userCanAccessWorkArea($userData, $requestedWorkArea)) {
    sendResponse('error', 'You are not allowed to check out from this work area.', null, 403);
}
requireApiCapability($userData, 'checkout', $requestedWorkArea);

$items = $payload['items'] ?? [];
if (is_string($items)) {
    $decoded = json_decode($items, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $items = $decoded;
    }
}

if (!is_array($items) || count($items) === 0) {
    sendResponse('error', 'Checkout requires at least one item.', null, 400);
}

$normalizedItems = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $barcode = normalizeString($item['barcode'] ?? ($item['Barcode'] ?? ''));
    $quantity = $item['quantity'] ?? ($item['Quantity'] ?? null);

    if ($barcode === '') {
        sendResponse('error', 'Invalid barcode in items list.', null, 400);
    }

    $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
    if ($quantity === false || $quantity <= 0) {
        sendResponse('error', 'Quantity must be a valid number greater than 0.', null, 400);
    }

    if (!isset($normalizedItems[$barcode])) {
        $normalizedItems[$barcode] = 0;
    }
    $normalizedItems[$barcode] += $quantity;
}

$checkoutCategory = strtoupper(normalizeString($payload['checkout_category'] ?? ($payload['category'] ?? 'INDIVIDUAL')));
$allowedCategories = ['INDIVIDUAL', 'BARANGAY'];
if (!in_array($checkoutCategory, $allowedCategories, true)) {
    $checkoutCategory = 'INDIVIDUAL';
}

$barangay = normalizeString($payload['barangay'] ?? '');
$dateConsultation = normalizeDateOnly($payload['date_consultation'] ?? ($payload['consultation_date'] ?? ''));
$yakapMember = !empty($payload['yakap_member']) ? 1 : 0;
$yakapIdNumber = normalizeString($payload['yakap_id_number'] ?? ($payload['yakap_id'] ?? ''));

$personId = normalizeString($payload['person_id'] ?? '');
$person = [];
if (isset($payload['person']) && is_array($payload['person'])) {
    $person = $payload['person'];
}

if ($personId === '') {
    $personId = resolvePersonId($conn, $person);
}

// Hold the stock lock across the availability check, insert and RIS number generation.
if (!acquireStockLock($conn)) {
    sendResponse('error', 'Another stock transaction is in progress. Please try again.', null, 503);
}

try {
    $conn->beginTransaction();

    $risNumber = generateRisNumber($conn, $checkedoutColumns);
    $checkoutDate = date('Y-m-d H:i:s');

    $insertCols = ['Barcode', 'PIID', 'Quantity', 'Checkout_Date', 'Checkout_By', 'Barangay', 'RIS_Number', 'Category'];
    $insertVals = ['?', '?', '?', '?', '?', '?', '?', '?'];

    if ($checkedoutDeletedColumn) {
        $insertCols[] = $checkedoutDeletedColumn;
        $insertVals[] = '0';
    }
    if ($checkedoutWorkAreaColumn) {
        $insertCols[] = $checkedoutWorkAreaColumn;
        $insertVals[] = '?';
    }
    if ($checkedoutDateConsultationColumn) {
        $insertCols[] = $checkedoutDateConsultationColumn;
        $insertVals[] = '?';
    }
    if ($checkedoutYakapMemberColumn) {
        $insertCols[] = $checkedoutYakapMemberColumn;
        $insertVals[] = '?';
    }
    if ($checkedoutYakapIdColumn) {
        $insertCols[] = $checkedoutYakapIdColumn;
        $insertVals[] = '?';
    }

    $insertSql = "INSERT INTO tbl_checkedout_items (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
    $insertStmt = $conn->prepare($insertSql);

    $checked = [];
    $warnings = [];

    foreach ($normalizedItems as $barcode => $quantity) {
        $lookup = findItemByBarcode(
            $conn,
            $barcode,
            $categories,
            $checkedoutDeletedColumn,
            $checkedoutWorkAreaColumn,
            $requestedWorkArea
        );
        if (!$lookup) {
            throw new Exception("No record found for barcode: {$barcode}");
        }

        $expiredOn = stockBatchExpiredOn($lookup['item']['Expiry_Date'] ?? '');
        if ($expiredOn !== null) {
            throw new Exception("Item {$barcode} expired on {$expiredOn} and cannot be released.");
        }

        $available = (int)$lookup['available'];
        if ($available <= 0) {
            throw new Exception("Item {$barcode} is already depleted.");
        }
        if ($quantity > $available) {
            throw new Exception("Item {$barcode} only has {$available} available.");
        }

        if ($available <= LOW_STOCK_THRESHOLD) {
            $warnings[] = "Low stock warning for {$barcode} (available {$available}).";
        }

        $insertValues = [
            $lookup['barcode_number'],
            $personId,
            $quantity,
            $checkoutDate,
            $userData['username'],
            $barangay,
            $risNumber,
            $checkoutCategory
        ];
        if ($checkedoutWorkAreaColumn) {
            $insertValues[] = $requestedWorkArea;
        }
        if ($checkedoutDateConsultationColumn) {
            $insertValues[] = $dateConsultation;
        }
        if ($checkedoutYakapMemberColumn) {
            $insertValues[] = $yakapMember;
        }
        if ($checkedoutYakapIdColumn) {
            $insertValues[] = $yakapMember ? $yakapIdNumber : '';
        }
        $insertStmt->execute($insertValues);

        $checked[] = [
            'barcode' => $lookup['barcode_number'],
            'quantity' => $quantity,
            'item' => $lookup['item']['Item'] ?? '',
            'work_area' => $requestedWorkArea
        ];
    }

    $conn->commit();

    $totalQty = 0;
    foreach ($checked as $row) {
        $totalQty += (int)($row['quantity'] ?? 0);
    }
    logTransactionHistory($conn, [
        'module' => 'CHECKOUT',
        'action' => 'CHECKOUT',
        'transaction_type' => $checkoutCategory,
        'category' => $checkoutCategory,
        'reference_no' => $risNumber,
        'quantity' => $totalQty,
        'performed_by' => $userData['username'] ?? 'system',
        'location' => $userData['location'] ?? '',
        'work_area' => $requestedWorkArea,
        'details' => [
            'barangay' => $barangay,
            'person_id' => $personId,
            'date_consultation' => $dateConsultation,
            'yakap_member' => $yakapMember,
            'yakap_id_number' => $yakapMember ? $yakapIdNumber : '',
            'work_area' => $requestedWorkArea,
            'items' => $checked
        ]
    ]);

    sendResponse('success', 'Checkout complete.', [
        'ris_number' => $risNumber,
        'items' => $checked,
        'work_area' => $requestedWorkArea
    ], 200, [
        'warnings' => $warnings
    ]);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    sendResponse('error', 'Checkout failed: ' . $e->getMessage(), null, 400);
}
?>
