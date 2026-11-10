<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/db_auth.php';

function risSendResponse($status, $message, $data = null, $code = 200) {
    http_response_code($code);
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

function risGetTableColumns($conn, $table) {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $conn->query("DESCRIBE {$table}");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $columns = [];
    foreach ($rows as $row) {
        if (isset($row['Field'])) {
            $columns[] = $row['Field'];
        }
    }

    $cache[$table] = $columns;
    return $columns;
}

function risPickColumn($columns, $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function risGetIsDeletedColumn($columns) {
    return risPickColumn($columns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
}

function risNormalizeWorkArea($value, $default = 'CHO') {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return $default;
    }
    $value = preg_replace('/[^A-Z0-9 _-]/', '', $value);
    return $value !== '' ? $value : $default;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    risSendResponse('error', 'Invalid request method.', null, 405);
}

if (!$conn) {
    risSendResponse('error', 'Database connection not available.', null, 500);
}

$userData = validateToken();
if (!$userData) {
    risSendResponse('error', 'Invalid or expired token.', null, 401);
}

$role = strtolower(trim((string)($userData['role'] ?? 'user')));
$isSuperAdmin = in_array($role, ['super_admin', 'superadmin'], true);
$isAdmin = ($role === 'admin' || $role === 'administrator');
$userArea = strtolower(trim((string)($userData['work_area'] ?? '')));
$hasArea = $userArea !== '';
$canCheckout = userHasModuleAccess($userData, 'checkout') && ($isSuperAdmin || $isAdmin || in_array($role, ['coa', 'accounting'], true) || (isPharmacistRoleKey($role) && ($userArea === 'pharmacy' || !$hasArea)));

if (!$canCheckout) {
    risSendResponse('error', 'You are not allowed to access RIS documents.', null, 403);
}

$risNumber = trim((string)($_GET['ris'] ?? ''));
if ($risNumber === '') {
    risSendResponse('error', 'RIS number is required.', null, 400);
}

$workArea = risNormalizeWorkArea($_GET['work_area'] ?? ($userData['location'] ?? 'CHO'));

$itemTables = [
    'tbl_item_medicine',
    'tbl_item_medical_supplies',
    'tbl_item_vaccines',
    'tbl_item_lab_reagents'
];

$unionParts = [];
foreach ($itemTables as $table) {
    $cols = risGetTableColumns($conn, $table);
    $deletedCol = risGetIsDeletedColumn($cols);
    $workAreaCol = risPickColumn($cols, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
    $filters = [];
    if ($deletedCol) {
        $filters[] = "{$deletedCol} = 0";
    }
    if ($workAreaCol) {
        $filters[] = "UPPER(COALESCE(NULLIF(TRIM({$workAreaCol}), ''), 'CHO')) = " . $conn->quote($workArea);
    }
    $whereClause = !empty($filters) ? (' WHERE ' . implode(' AND ', $filters)) : '';
    $unionParts[] = "SELECT Barcode_Number, Item, Description, Entity, Unit_Cost FROM {$table}{$whereClause}";
}

$checkedoutColumns = risGetTableColumns($conn, 'tbl_checkedout_items');
$checkedoutDeleted = risGetIsDeletedColumn($checkedoutColumns);
$checkedoutWorkArea = risPickColumn($checkedoutColumns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
$checkedoutFilter = $checkedoutDeleted ? " AND coi.{$checkedoutDeleted} = 0" : '';
if ($checkedoutWorkArea) {
    $checkedoutFilter .= " AND UPPER(COALESCE(NULLIF(TRIM(coi.{$checkedoutWorkArea}), ''), 'CHO')) = :work_area";
}

$sql = "
    SELECT coi.Barcode, coi.Quantity, coi.Checkout_Date, coi.Checkout_By, coi.Barangay, coi.RIS_Number, coi.Category, coi.PIID,
           item.Item, item.Description, item.Entity, item.Unit_Cost
    FROM tbl_checkedout_items coi
    LEFT JOIN (" . implode(' UNION ALL ', $unionParts) . ") item ON item.Barcode_Number = coi.Barcode
    WHERE coi.RIS_Number = :ris{$checkedoutFilter}
    ORDER BY item.Item ASC, coi.Barcode ASC
";

$stmt = $conn->prepare($sql);
$params = [':ris' => $risNumber];
if ($checkedoutWorkArea) {
    $params[':work_area'] = $workArea;
}
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    risSendResponse('error', 'No checkout records found for this RIS.', null, 404);
}

$header = $rows[0];
$person = null;
$personId = $header['PIID'] ?? '';
if ($personId !== '') {
    $personStmt = $conn->prepare('SELECT * FROM tbl_personal_details WHERE PIID = ? LIMIT 1');
    $personStmt->execute([$personId]);
    $person = $personStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$totalQty = 0;
$totalCost = 0;
foreach ($rows as $row) {
    $qty = (int)($row['Quantity'] ?? 0);
    $unitCost = (float)($row['Unit_Cost'] ?? 0);
    $totalQty += $qty;
    $totalCost += ($qty * $unitCost);
}

risSendResponse('success', 'RIS document loaded.', [
    'ris_number' => $risNumber,
    'work_area' => $workArea,
    'header' => $header,
    'rows' => $rows,
    'person' => $person,
    'totals' => [
        'quantity' => $totalQty,
        'cost' => $totalCost
    ]
]);
?>
