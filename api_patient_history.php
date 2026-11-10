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

function normalizeWorkArea($value, $default = 'CHO') {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return $default;
    }
    if ($value === 'ALL') {
        return 'ALL';
    }
    $value = preg_replace('/[^A-Z0-9 _-]/', '', $value);
    return $value !== '' ? $value : $default;
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

function getIsDeletedColumn($columns) {
    return pickColumn($columns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
}

function formatDateTimeValue($value) {
    $value = normalizeString($value);
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('Y-m-d H:i:s', $timestamp);
}

function calculateAge($birthdate, $fallback = null) {
    $birthdate = normalizeString($birthdate);
    if ($birthdate === '') {
        return $fallback;
    }
    try {
        return (new DateTime($birthdate))->diff(new DateTime('today'))->y;
    } catch (Exception $e) {
        return $fallback;
    }
}

function buildCheckoutMeta($conn) {
    $columns = getTableColumns($conn, 'tbl_checkedout_items');
    if (empty($columns)) {
        sendResponse('error', 'Checkout table metadata not found.', null, 500);
    }

    return [
        'columns' => $columns,
        'id' => pickColumn($columns, ['CID', 'cid', 'ID', 'id']),
        'piid' => pickColumn($columns, ['PIID', 'piid']),
        'barcode' => pickColumn($columns, ['Barcode', 'barcode', 'Barcode_Number', 'barcode_number']),
        'quantity' => pickColumn($columns, ['Quantity', 'quantity']),
        'checkout_date' => pickColumn($columns, ['Checkout_Date', 'checkout_date']),
        'checkout_by' => pickColumn($columns, ['Checkout_By', 'checkout_by']),
        'barangay' => pickColumn($columns, ['Barangay', 'barangay']),
        'ris' => pickColumn($columns, ['RIS_Number', 'ris_number', 'RIS_No', 'ris_no', 'Reference_No', 'reference_no']),
        'category' => pickColumn($columns, ['Category', 'category']),
        'work_area' => pickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
        'date_consultation' => pickColumn($columns, ['Date_Consultation', 'date_consultation', 'Consultation_Date', 'consultation_date']),
        'yakap_member' => pickColumn($columns, ['Yakap_Member', 'yakap_member', 'Is_Yakap_Member', 'is_yakap_member']),
        'yakap_id' => pickColumn($columns, ['Yakap_ID_Number', 'yakap_id_number', 'Yakap_ID', 'yakap_id']),
        'is_deleted' => getIsDeletedColumn($columns)
    ];
}

function buildInventoryLookupSql($conn) {
    $tables = [
        'medicine' => 'tbl_item_medicine',
        'medical_supplies' => 'tbl_item_medical_supplies',
        'vaccines' => 'tbl_item_vaccines',
        'lab_reagents' => 'tbl_item_lab_reagents'
    ];
    $parts = [];

    foreach ($tables as $category => $table) {
        $columns = getTableColumns($conn, $table);
        if (empty($columns)) {
            continue;
        }
        $barcode = pickColumn($columns, ['Barcode_Number', 'barcode_number', 'Barcode', 'barcode']);
        $item = pickColumn($columns, ['Item', 'item']);
        if (!$barcode || !$item) {
            continue;
        }
        $description = pickColumn($columns, ['Description', 'description']);
        $entity = pickColumn($columns, ['Entity', 'entity']);
        $isDeleted = getIsDeletedColumn($columns);
        $where = $isDeleted ? (' WHERE ' . quoteIdentifier($isDeleted) . ' = 0') : '';
        $parts[] = "
            SELECT
                " . quoteIdentifier($barcode) . " AS lookup_barcode,
                " . quoteIdentifier($item) . " AS item_name,
                " . ($description ? quoteIdentifier($description) : "''") . " AS description,
                " . ($entity ? quoteIdentifier($entity) : "''") . " AS entity,
                '{$category}' AS item_category
            FROM " . quoteIdentifier($table) . "
            {$where}
        ";
    }

    if (empty($parts)) {
        return "SELECT '' AS lookup_barcode, '' AS item_name, '' AS description, '' AS entity, '' AS item_category WHERE 1=0";
    }

    return implode("\nUNION ALL\n", $parts);
}

function buildPatientSelect() {
    return "
        PIID,
        Surname,
        FirstName,
        COALESCE(MiddleName, '') AS MiddleName,
        COALESCE(NameExt, '') AS NameExt,
        Birthdate,
        Age,
        COALESCE(Sex, '') AS Sex,
        COALESCE(Purok, '') AS Purok,
        COALESCE(Street, '') AS Street,
        COALESCE(Barangay, '') AS Barangay,
        COALESCE(City, '') AS City,
        COALESCE(Contact_No, '') AS Contact_No
    ";
}

function normalizePatient($row) {
    $middle = normalizeString($row['MiddleName'] ?? '');
    $middleInitial = $middle !== '' ? strtoupper(substr($middle, 0, 1)) . '. ' : '';
    $nameExt = normalizeString($row['NameExt'] ?? '');
    $displayName = trim(
        normalizeString($row['Surname'] ?? '') . ', ' .
        normalizeString($row['FirstName'] ?? '') . ' ' .
        $middleInitial .
        ($nameExt !== '' ? $nameExt : '')
    );

    return [
        'piid' => (int)($row['PIID'] ?? 0),
        'display_name' => $displayName,
        'surname' => $row['Surname'] ?? '',
        'first_name' => $row['FirstName'] ?? '',
        'middle_name' => $row['MiddleName'] ?? '',
        'name_ext' => $row['NameExt'] ?? '',
        'birthdate' => $row['Birthdate'] ?? '',
        'age' => calculateAge($row['Birthdate'] ?? '', isset($row['Age']) ? (int)$row['Age'] : null),
        'sex' => $row['Sex'] ?? '',
        'barangay' => $row['Barangay'] ?? '',
        'address' => trim(implode(', ', array_filter([
            normalizeString($row['Purok'] ?? ''),
            normalizeString($row['Street'] ?? ''),
            normalizeString($row['Barangay'] ?? ''),
            normalizeString($row['City'] ?? '')
        ]))),
        'contact_no' => $row['Contact_No'] ?? ''
    ];
}

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

if (!apiUserCan($userData, 'patient_history')) {
    sendResponse('error', 'You are not allowed to access patient medication history.', null, 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse('error', 'Invalid request method.', null, 405);
}

$action = normalizeString($_GET['action'] ?? 'search');
$requestedWorkArea = normalizeWorkArea($_GET['work_area'] ?? ($userData['location'] ?? 'CHO'));
$checkoutMeta = buildCheckoutMeta($conn);
if (!$checkoutMeta['piid'] || !$checkoutMeta['barcode'] || !$checkoutMeta['quantity']) {
    sendResponse('error', 'Checkout table is missing required patient history columns.', null, 500);
}

try {
    if ($action === 'search') {
        $search = normalizeString($_GET['search'] ?? '');
        if ($search === '' || strlen($search) < 2) {
            sendResponse('success', 'Enter at least 2 characters.', [], 200, ['count' => 0]);
        }

        $where = ["COALESCE(isDeleted, 0) = 0", "(Surname LIKE :search OR FirstName LIKE :search OR PIID LIKE :search OR Barangay LIKE :search)"];
        $params = [':search' => '%' . $search . '%'];

        $stmt = $conn->prepare("
            SELECT " . buildPatientSelect() . "
            FROM tbl_personal_details
            WHERE " . implode(' AND ', $where) . "
            ORDER BY Surname ASC, FirstName ASC, PIID DESC
            LIMIT 20
        ");
        $stmt->execute($params);
        $patients = array_map('normalizePatient', $stmt->fetchAll(PDO::FETCH_ASSOC));

        sendResponse('success', 'Patient search results.', $patients, 200, ['count' => count($patients)]);
    }

    if ($action !== 'history') {
        sendResponse('error', 'Unknown action.', null, 400);
    }

    $piid = (int)($_GET['piid'] ?? 0);
    if ($piid <= 0) {
        sendResponse('error', 'PIID is required.', null, 400);
    }

    $patientStmt = $conn->prepare("
        SELECT " . buildPatientSelect() . "
        FROM tbl_personal_details
        WHERE PIID = ? AND COALESCE(isDeleted, 0) = 0
        LIMIT 1
    ");
    $patientStmt->execute([$piid]);
    $patientRow = $patientStmt->fetch(PDO::FETCH_ASSOC);
    if (!$patientRow) {
        sendResponse('error', 'Patient not found.', null, 404);
    }

    $where = ['c.' . quoteIdentifier($checkoutMeta['piid']) . ' = :piid'];
    $params = [':piid' => $piid];
    if ($checkoutMeta['is_deleted']) {
        $where[] = 'c.' . quoteIdentifier($checkoutMeta['is_deleted']) . ' = 0';
    }
    if ($checkoutMeta['work_area'] && $requestedWorkArea !== 'ALL') {
        $where[] = "UPPER(COALESCE(NULLIF(TRIM(c." . quoteIdentifier($checkoutMeta['work_area']) . "), ''), 'CHO')) = :work_area";
        $params[':work_area'] = $requestedWorkArea;
    }

    $dateCol = $checkoutMeta['checkout_date'] ? 'c.' . quoteIdentifier($checkoutMeta['checkout_date']) : 'NULL';
    $byCol = $checkoutMeta['checkout_by'] ? 'c.' . quoteIdentifier($checkoutMeta['checkout_by']) : "''";
    $barangayCol = $checkoutMeta['barangay'] ? 'c.' . quoteIdentifier($checkoutMeta['barangay']) : "''";
    $risCol = $checkoutMeta['ris'] ? 'c.' . quoteIdentifier($checkoutMeta['ris']) : "''";
    $categoryCol = $checkoutMeta['category'] ? 'c.' . quoteIdentifier($checkoutMeta['category']) : "''";
    $workAreaCol = $checkoutMeta['work_area'] ? 'c.' . quoteIdentifier($checkoutMeta['work_area']) : "'CHO'";
    $consultCol = $checkoutMeta['date_consultation'] ? 'c.' . quoteIdentifier($checkoutMeta['date_consultation']) : 'NULL';
    $yakapMemberCol = $checkoutMeta['yakap_member'] ? 'c.' . quoteIdentifier($checkoutMeta['yakap_member']) : '0';
    $yakapIdCol = $checkoutMeta['yakap_id'] ? 'c.' . quoteIdentifier($checkoutMeta['yakap_id']) : "''";

    $inventorySql = buildInventoryLookupSql($conn);
    $sql = "
        SELECT
            " . ($checkoutMeta['id'] ? 'c.' . quoteIdentifier($checkoutMeta['id']) : '0') . " AS row_id,
            c." . quoteIdentifier($checkoutMeta['barcode']) . " AS barcode,
            c." . quoteIdentifier($checkoutMeta['quantity']) . " AS quantity,
            {$dateCol} AS checkout_date,
            {$consultCol} AS date_consultation,
            {$byCol} AS checkout_by,
            {$barangayCol} AS barangay,
            {$risCol} AS ris_number,
            {$categoryCol} AS checkout_category,
            {$workAreaCol} AS work_area,
            {$yakapMemberCol} AS yakap_member,
            {$yakapIdCol} AS yakap_id_number,
            COALESCE(i.item_name, c." . quoteIdentifier($checkoutMeta['barcode']) . ") AS item_name,
            COALESCE(i.description, '') AS description,
            COALESCE(i.entity, '') AS entity,
            COALESCE(i.item_category, '') AS item_category
        FROM tbl_checkedout_items c
        LEFT JOIN (
            SELECT lookup_barcode, MAX(item_name) AS item_name, MAX(description) AS description, MAX(entity) AS entity, MAX(item_category) AS item_category
            FROM ({$inventorySql}) inventory_union
            GROUP BY lookup_barcode
        ) i ON i.lookup_barcode = c." . quoteIdentifier($checkoutMeta['barcode']) . "
        WHERE " . implode(' AND ', $where) . "
        ORDER BY {$dateCol} DESC, row_id DESC
        LIMIT 500
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history = [];
    $risSet = [];
    $totalQty = 0;
    foreach ($rows as $row) {
        $qty = (int)($row['quantity'] ?? 0);
        $totalQty += $qty;
        $ris = normalizeString($row['ris_number'] ?? '');
        if ($ris !== '') {
            $risSet[$ris] = true;
        }
        $history[] = [
            'row_id' => (int)($row['row_id'] ?? 0),
            'date' => formatDateTimeValue($row['checkout_date'] ?? ''),
            'date_consultation' => $row['date_consultation'] ?? '',
            'ris_number' => $ris,
            'barcode' => $row['barcode'] ?? '',
            'item_name' => $row['item_name'] ?? '',
            'description' => $row['description'] ?? '',
            'entity' => $row['entity'] ?? '',
            'item_category' => $row['item_category'] ?? '',
            'quantity' => $qty,
            'barangay' => $row['barangay'] ?? '',
            'checkout_category' => $row['checkout_category'] ?? '',
            'work_area' => $row['work_area'] ?? '',
            'checkout_by' => $row['checkout_by'] ?? '',
            'yakap_member' => (int)($row['yakap_member'] ?? 0),
            'yakap_id_number' => $row['yakap_id_number'] ?? ''
        ];
    }

    sendResponse('success', 'Patient medication history loaded.', [
        'patient' => normalizePatient($patientRow),
        'history' => $history,
        'summary' => [
            'transaction_count' => count($history),
            'ris_count' => count($risSet),
            'total_quantity' => $totalQty,
            'work_area' => $requestedWorkArea
        ]
    ]);
} catch (Exception $e) {
    sendResponse('error', 'Unable to load patient medication history: ' . $e->getMessage(), null, 500);
}
