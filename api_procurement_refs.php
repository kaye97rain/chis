<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';

function sendResponse($status, $message, $data = null, $code = 200, $meta = null) {
    http_response_code($code);
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    if ($meta !== null)  $response['meta'] = $meta;
    echo json_encode($response);
    exit;
}

function quoteId($id) {
    return '`' . str_replace('`', '``', (string)$id) . '`';
}

function getColumns($conn, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $conn->query("DESCRIBE " . quoteId($table));
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        $rows = [];
    }
    $cols = [];
    foreach ($rows as $r) { if (isset($r['Field'])) $cols[] = $r['Field']; }
    return $cache[$table] = $cols;
}

function pickCol($columns, $candidates) {
    foreach ($candidates as $c) {
        if (in_array($c, $columns, true)) return $c;
    }
    return null;
}

function getRefCol($conn, $table, $refType) {
    $cols = getColumns($conn, $table);
    $map = [
        'po'       => ['PO_Number',       'po_number'],
        'contract' => ['Contract_Number', 'contract_number', 'ContractNo', 'Contract_No'],
        'batch'    => ['Batch_Number',    'batch_number',    'BatchNo',    'Batch_No'],
        'lot'      => ['Lot_Number',      'lot_number',      'LotNo',      'Lot_No'],
    ];
    return pickCol($cols, $map[$refType] ?? []);
}

if (!$conn) sendResponse('error', 'Database connection not available.', null, 500);

$userData = validateToken();
if (!$userData) sendResponse('error', 'Invalid or expired token.', null, 401);

if (!apiUserCan($userData, 'inventory_view')) {
    sendResponse('error', 'Access denied.', null, 403);
}

$itemTables = [
    'medicine'         => 'Medicine',
    'medical_supplies' => 'Medical Supplies',
    'vaccines'         => 'Vaccines',
    'lab_reagents'     => 'Lab Reagents',
];
$tableNames = [
    'medicine'         => 'tbl_item_medicine',
    'medical_supplies' => 'tbl_item_medical_supplies',
    'vaccines'         => 'tbl_item_vaccines',
    'lab_reagents'     => 'tbl_item_lab_reagents',
];
$refTypes = ['po', 'contract', 'batch', 'lot'];

$action = strtolower(trim($_GET['action'] ?? 'list'));

// ── SUGGEST ────────────────────────────────────────────────────────────────
if ($action === 'suggest') {
    $refType = strtolower(trim($_GET['ref_type'] ?? 'po'));
    $q       = trim($_GET['q'] ?? '');

    if (!in_array($refType, $refTypes, true)) sendResponse('error', 'Invalid ref_type.', null, 400);
    if (strlen($q) < 1) sendResponse('success', 'OK', [], 200, ['count' => 0]);

    $unions = [];
    $params = [];
    $i = 0;
    foreach ($tableNames as $catKey => $tableName) {
        $col = getRefCol($conn, $tableName, $refType);
        if (!$col) { $i++; continue; }
        $pk = ":q{$i}";
        $unions[] = "SELECT DISTINCT TRIM(" . quoteId($col) . ") AS val"
                  . " FROM " . quoteId($tableName)
                  . " WHERE isDeleted = 0"
                  . "   AND TRIM(" . quoteId($col) . ") <> ''"
                  . "   AND " . quoteId($col) . " LIKE {$pk}";
        $params[$pk] = '%' . $q . '%';
        $i++;
    }

    if (empty($unions)) sendResponse('success', 'OK', [], 200, ['count' => 0]);

    $sql  = "SELECT DISTINCT val FROM (" . implode(' UNION ALL ', $unions) . ") AS u ORDER BY val LIMIT 15";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    sendResponse('success', 'Suggestions retrieved.', $rows, 200, ['count' => count($rows)]);
}

// ── LIST ───────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $result = [];
    $totals = [];

    foreach ($refTypes as $refType) {
        $unions = [];
        foreach ($tableNames as $catKey => $tableName) {
            $col      = getRefCol($conn, $tableName, $refType);
            if (!$col) continue;
            $catLabel = $itemTables[$catKey];
            $unions[] = "SELECT TRIM(" . quoteId($col) . ") AS ref_value,"
                      . " COUNT(*) AS item_count,"
                      . " COALESCE(SUM(Quantity), 0) AS total_qty,"
                      . " '{$catLabel}' AS category,"
                      . " MIN(Date_Added) AS first_date,"
                      . " MAX(Date_Added) AS last_date"
                      . " FROM " . quoteId($tableName)
                      . " WHERE isDeleted = 0"
                      . "   AND TRIM(" . quoteId($col) . ") <> ''"
                      . "   AND " . quoteId($col) . " IS NOT NULL"
                      . " GROUP BY TRIM(" . quoteId($col) . ")";
        }

        if (empty($unions)) {
            $result[$refType] = [];
            $totals[$refType] = 0;
            continue;
        }

        $sql = "SELECT ref_value,"
             . " SUM(item_count) AS item_count,"
             . " SUM(total_qty) AS total_qty,"
             . " GROUP_CONCAT(DISTINCT category ORDER BY category SEPARATOR ', ') AS categories,"
             . " MIN(first_date) AS first_date,"
             . " MAX(last_date) AS last_date"
             . " FROM (" . implode(' UNION ALL ', $unions) . ") AS combined"
             . " GROUP BY ref_value"
             . " ORDER BY last_date DESC, ref_value ASC"
             . " LIMIT 1000";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['item_count'] = (int)$row['item_count'];
            $row['total_qty']  = (int)$row['total_qty'];
        }
        unset($row);

        $result[$refType] = $rows;
        $totals[$refType] = count($rows);
    }

    sendResponse('success', 'Reference list retrieved.', $result, 200, $totals);
}

// ── ITEMS ──────────────────────────────────────────────────────────────────
if ($action === 'items') {
    $refType  = strtolower(trim($_GET['ref_type']  ?? 'po'));
    $refValue = trim($_GET['ref_value'] ?? '');

    if (!in_array($refType, $refTypes, true)) sendResponse('error', 'Invalid ref_type.', null, 400);
    if ($refValue === '') sendResponse('error', 'ref_value is required.', null, 400);

    $allItems = [];
    foreach ($tableNames as $catKey => $tableName) {
        $col = getRefCol($conn, $tableName, $refType);
        if (!$col) continue;

        $cols        = getColumns($conn, $tableName);
        $batchCol    = pickCol($cols, ['Batch_Number',    'batch_number',    'BatchNo']);
        $lotCol      = pickCol($cols, ['Lot_Number',      'lot_number',      'LotNo']);
        $contractCol = pickCol($cols, ['Contract_Number', 'contract_number', 'ContractNo']);
        $workAreaCol = pickCol($cols, ['Work_Area',       'work_area',       'WorkArea']);

        $extra = '';
        if ($batchCol)    $extra .= ", " . quoteId($batchCol)    . " AS Batch_Number";
        if ($lotCol)      $extra .= ", " . quoteId($lotCol)      . " AS Lot_Number";
        if ($contractCol) $extra .= ", " . quoteId($contractCol) . " AS Contract_Number";
        if ($workAreaCol) $extra .= ", " . quoteId($workAreaCol) . " AS Work_Area";

        $sql  = "SELECT IID, Item, Description, Barcode_Number, Entity, Unit_Cost,"
              . " Quantity, Expiry_Date, Date_Added, Added_By, PO_Number, Remarks{$extra}"
              . " FROM " . quoteId($tableName)
              . " WHERE isDeleted = 0"
              . "   AND TRIM(" . quoteId($col) . ") = :ref_value"
              . " ORDER BY Date_Added DESC"
              . " LIMIT 500";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':ref_value' => $refValue]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['category']     = $itemTables[$catKey];
            $row['category_key'] = $catKey;
        }
        unset($row);

        $allItems = array_merge($allItems, $rows);
    }

    usort($allItems, fn($a, $b) => strcmp((string)($b['Date_Added'] ?? ''), (string)($a['Date_Added'] ?? '')));

    sendResponse('success', 'Items retrieved.', $allItems, 200, [
        'count'     => count($allItems),
        'ref_type'  => $refType,
        'ref_value' => $refValue,
    ]);
}

sendResponse('error', 'Unknown action.', null, 400);
