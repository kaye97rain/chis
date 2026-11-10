<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';
require_once 'transaction_logger.php';
require_once __DIR__ . '/stock_workflow_helpers.php';

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

function normalizeDateTime($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (strlen($value) === 10) {
        $value .= ' 00:00:00';
    } elseif (strlen($value) === 16) {
        $value .= ':00';
    }
    try {
        $date = new DateTime($value);
    } catch (Exception $e) {
        return null;
    }
    return $date->format('Y-m-d H:i:s');
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

function normalizeInventoryWorkArea($value) {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[^A-Z0-9 _-]/', '', $value);
    return $value !== '' ? $value : '';
}

function getAvailableQuantity($conn, $itemTable, $barcode, $itemDeletedColumn, $checkedoutDeletedColumn, $itemWorkAreaColumn = null, $sourceWorkArea = '', $checkedoutWorkAreaColumn = null) {
    $itemFilter = $itemDeletedColumn ? " AND {$itemDeletedColumn} = 0" : '';
    $checkoutFilter = $checkedoutDeletedColumn ? " AND {$checkedoutDeletedColumn} = 0" : '';
    $params = [':barcode' => $barcode];

    $sourceWorkArea = normalizeInventoryWorkArea($sourceWorkArea);
    if ($sourceWorkArea !== '' && $itemWorkAreaColumn) {
        $itemFilter .= " AND UPPER(COALESCE(NULLIF(TRIM({$itemWorkAreaColumn}), ''), 'CHO')) = :source_item_work_area";
        $params[':source_item_work_area'] = $sourceWorkArea;
    }
    if ($sourceWorkArea !== '' && $checkedoutWorkAreaColumn) {
        $checkoutFilter .= " AND UPPER(COALESCE(NULLIF(TRIM({$checkedoutWorkAreaColumn}), ''), 'CHO')) = :source_checkout_work_area";
        $params[':source_checkout_work_area'] = $sourceWorkArea;
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

function findItemByBarcode($conn, $barcode, $categories, $checkedoutDeletedColumn, $checkedoutWorkAreaColumn, $sourceWorkArea = 'CHO') {
    $sourceWorkArea = normalizeInventoryWorkArea($sourceWorkArea);
    foreach ($categories as $key => $config) {
        $table = $config['table'];
        $columns = getTableColumns($conn, $table);
        $itemDeletedColumn = getIsDeletedColumn($columns);
        $itemWorkAreaColumn = pickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
        $itemFilter = $itemDeletedColumn ? " AND {$itemDeletedColumn} = 0" : '';
        if ($sourceWorkArea !== '' && $itemWorkAreaColumn) {
            $itemFilter .= " AND UPPER(COALESCE(NULLIF(TRIM({$itemWorkAreaColumn}), ''), 'CHO')) = :source_item_work_area";
        }
        $orderClause = buildOrderClause($columns);

        $stmt = $conn->prepare("
            SELECT *
            FROM {$table}
            WHERE (Barcode_Number = :barcode OR Barcode = :barcode){$itemFilter}
            {$orderClause}
            LIMIT 1
        ");
        $params = [':barcode' => $barcode];
        if ($sourceWorkArea !== '' && $itemWorkAreaColumn) {
            $params[':source_item_work_area'] = $sourceWorkArea;
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
                $checkedoutDeletedColumn,
                $itemWorkAreaColumn,
                $sourceWorkArea,
                $checkedoutWorkAreaColumn
            );

            return [
                'category_key' => $key,
                'category_label' => $config['label'],
                'low_stock_block' => $config['low_stock_block'],
                'table' => $table,
                'columns' => $columns,
                'item_deleted_column' => $itemDeletedColumn,
                'work_area_column' => $itemWorkAreaColumn,
                'item' => $row,
                'barcode_number' => $barcodeNumber,
                'available' => $available
            ];
        }
    }
    return null;
}

function buildTransferBarcode($barcodeNumber, $destinationWorkArea) {
    $barcodeSeed = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$barcodeNumber));
    if ($barcodeSeed === '') {
        $barcodeSeed = 'ITEM';
    }
    $areaSeed = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$destinationWorkArea));
    if ($areaSeed === '') {
        $areaSeed = 'DST';
    }
    return substr($barcodeSeed, 0, 12) . '-TRF-' . substr($areaSeed, 0, 6) . '-' . date('ymdHis');
}

function resolveDestinationWorkArea($transferType, $destination, $explicit = '') {
    $mapped = normalizeInventoryWorkArea($explicit);
    if ($mapped !== '') {
        return $mapped;
    }

    $transferType = strtoupper($transferType);
    if ($transferType === 'LABORATORY') {
        return 'LABORATORY';
    }
    if ($transferType === 'BARANGAY') {
        return 'BARANGAY';
    }

    if ($transferType === 'CLINIC') {
        $destinationLower = strtolower((string)$destination);
        if (strpos($destinationLower, 'aglayan') !== false) {
            return 'AGLAYAN';
        }
        if (strpos($destinationLower, 'uplc') !== false) {
            return 'UPLC';
        }
    }

    return '';
}

function hasTransferBackfillAccess($userData) {
    $role = strtolower(trim((string)($userData['role'] ?? '')));
    return in_array($role, ['super_admin', 'superadmin', 'admin', 'administrator'], true);
}

function upsertTransferredStock($conn, $lookup, $quantity, $destinationWorkArea, $checkoutDate, $username, $remarks = '') {
    $table = $lookup['table'] ?? '';
    $columns = $lookup['columns'] ?? [];
    $item = $lookup['item'] ?? [];
    $barcodeNumber = (string)($lookup['barcode_number'] ?? '');
    $workAreaColumn = $lookup['work_area_column'] ?? null;
    $itemDeletedColumn = $lookup['item_deleted_column'] ?? null;

    if ($table === '' || !is_array($columns) || empty($columns)) {
        throw new Exception('Destination stock update failed: item table metadata is incomplete.');
    }
    if (!$workAreaColumn) {
        throw new Exception('Destination stock update failed: Work_Area column is missing.');
    }
    if ($barcodeNumber === '') {
        throw new Exception('Destination stock update failed: barcode is missing.');
    }

    $where = [];
    if ($itemDeletedColumn) {
        $where[] = "{$itemDeletedColumn} = 0";
    }
    $where[] = "Barcode_Number = :barcode_number";
    $where[] = "UPPER(COALESCE(NULLIF(TRIM({$workAreaColumn}), ''), 'CHO')) = :destination_work_area";
    $hasIid = in_array('IID', $columns, true);
    $selectColumns = $hasIid ? 'IID, Quantity' : 'Quantity';
    $orderBy = in_array('IID', $columns, true) ? " ORDER BY IID DESC" : '';
    $existingSql = "SELECT {$selectColumns} FROM {$table} WHERE " . implode(' AND ', $where) . $orderBy . " LIMIT 1";
    $existingStmt = $conn->prepare($existingSql);
    $existingStmt->execute([
        ':barcode_number' => $barcodeNumber,
        ':destination_work_area' => $destinationWorkArea
    ]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $updateParts = ['Quantity = Quantity + :qty'];
        if (in_array('Date_Updated', $columns, true)) {
            $updateParts[] = 'Date_Updated = NOW()';
        }
        if ($hasIid && isset($existing['IID'])) {
            $updateSql = "UPDATE {$table} SET " . implode(', ', $updateParts) . " WHERE IID = :iid";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([
                ':qty' => (int)$quantity,
                ':iid' => (int)$existing['IID']
            ]);
        } else {
            $updateSql = "UPDATE {$table} SET " . implode(', ', $updateParts) . " WHERE Barcode_Number = :barcode_number AND UPPER(COALESCE(NULLIF(TRIM({$workAreaColumn}), ''), 'CHO')) = :destination_work_area";
            if ($itemDeletedColumn) {
                $updateSql .= " AND {$itemDeletedColumn} = 0";
            }
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([
                ':qty' => (int)$quantity,
                ':barcode_number' => $barcodeNumber,
                ':destination_work_area' => $destinationWorkArea
            ]);
        }
        return;
    }

    $insertCols = [];
    $insertVals = [];
    $insertParams = [];

    $addColumn = function ($column, $value) use (&$insertCols, &$insertVals, &$insertParams, $columns) {
        if (!in_array($column, $columns, true)) {
            return;
        }
        $insertCols[] = $column;
        $insertVals[] = '?';
        $insertParams[] = $value;
    };

    $addColumn('ID', $item['ID'] ?? null);
    $addColumn('Barcode', buildTransferBarcode($barcodeNumber, $destinationWorkArea));
    $addColumn('Barcode_Number', $barcodeNumber);
    $addColumn('Item', $item['Item'] ?? '');
    $addColumn('Description', $item['Description'] ?? '');
    $addColumn('Entity', $item['Entity'] ?? '');
    $addColumn('Unit_Cost', isset($item['Unit_Cost']) ? (float)$item['Unit_Cost'] : 0);
    $addColumn('Quantity', (int)$quantity);
    $addColumn('Expiry_Date', $item['Expiry_Date'] ?? null);
    $addColumn('Date_Added', $checkoutDate);
    $addColumn('Added_By', $username ?: 'system');
    $addColumn('Remarks', $remarks !== '' ? $remarks : ($item['Remarks'] ?? ''));
    $addColumn('Donated', isset($item['Donated']) ? (int)$item['Donated'] : 0);
    $addColumn('PO_Number', $item['PO_Number'] ?? '');
    $addColumn($workAreaColumn, $destinationWorkArea);

    if ($itemDeletedColumn && in_array($itemDeletedColumn, $columns, true)) {
        $insertCols[] = $itemDeletedColumn;
        $insertVals[] = '0';
    }

    if (empty($insertCols)) {
        throw new Exception('Destination stock update failed: no insertable columns found.');
    }

    $insertSql = "INSERT INTO {$table} (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->execute($insertParams);
}

function formatLookupItem($result) {
    $item = $result['item'];
    $available = (int)($result['available'] ?? 0);
    $lowStock = $available > 0 && $available <= LOW_STOCK_THRESHOLD;
    $depleted = $available <= 0;
    $expiredOn = stockBatchExpiredOn($item['Expiry_Date'] ?? '');
    $blockTransfer = $depleted || $expiredOn !== null;
    $blockReason = null;
    $warning = null;

    if ($expiredOn !== null) {
        $blockReason = "This batch expired on {$expiredOn} and cannot be transferred.";
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
        'block_checkout' => $blockTransfer,
        'block_reason' => $blockReason,
        'warning' => $warning
    ];
}

function normalizeWorkArea($value) {
    $key = strtoupper(normalizeString($value));
    if ($key === 'CLINIC' || $key === 'BARANGAY' || $key === 'LABORATORY') {
        return $key;
    }
    return '';
}

function generateTransferNumber($conn, $checkedoutColumns) {
    $hasCid = in_array('CID', $checkedoutColumns, true);
    if ($hasCid) {
        $stmt = $conn->query("SELECT COALESCE(MAX(CID), 0) AS max_cid FROM tbl_checkedout_items");
        $next = (int)$stmt->fetchColumn() + 1;
        // Zero-padded date (TRF20260105...) so the date/sequence boundary is unambiguous.
        return 'TRF' . date('Ymd') . $next;
    }
    return 'TRF' . date('YmdHis');
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
$checkedoutDestinationColumn = pickColumn($checkedoutColumns, ['Destination', 'destination', 'Target_Area', 'target_area']);
$checkedoutRemarksColumn = pickColumn($checkedoutColumns, ['Remarks', 'remarks', 'Notes', 'notes', 'Note', 'note']);
$checkedoutTypeColumn = pickColumn($checkedoutColumns, ['Transaction_Type', 'transaction_type']);
$checkedoutTransferDateColumn = pickColumn($checkedoutColumns, ['Transfer_Date', 'transfer_date', 'Date_Transfer', 'date_transfer']);
$checkedoutTransferByColumn = pickColumn($checkedoutColumns, ['Transfer_By', 'transfer_by', 'Transferred_By', 'transferred_by']);
$sourceWorkArea = 'CHO';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireApiCapability($userData, 'clinics');
    $action = $_GET['action'] ?? 'lookup';

    if ($action === 'work_areas') {
        sendResponse('success', 'Work area list.', [
            ['key' => 'CLINIC', 'label' => 'Clinic'],
            ['key' => 'BARANGAY', 'label' => 'Barangay'],
            ['key' => 'LABORATORY', 'label' => 'Laboratory']
        ], 200, ['count' => 3]);
    }

    if ($action === 'lookup') {
        $barcode = normalizeString($_GET['barcode'] ?? '');
        if ($barcode === '') {
            sendResponse('error', 'Barcode is required.', null, 400);
        }

        $result = findItemByBarcode($conn, $barcode, $categories, $checkedoutDeletedColumn, $checkedoutWorkAreaColumn, $sourceWorkArea);
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
            $params = [':search' => '%' . $search . '%'];

            if ($sourceWorkArea !== '' && $itemWorkAreaColumn) {
                $itemFilter .= " AND UPPER(COALESCE(NULLIF(TRIM({$itemWorkAreaColumn}), ''), 'CHO')) = :source_item_work_area";
                $params[':source_item_work_area'] = $sourceWorkArea;
            }

            $stmt = $conn->prepare("
                SELECT Barcode, Barcode_Number, Item, Description, Entity, Unit_Cost, Expiry_Date
                FROM {$table}
                WHERE (Item LIKE :search OR Description LIKE :search OR Barcode_Number LIKE :search OR Barcode LIKE :search)
                {$itemFilter}
                ORDER BY Item ASC
                LIMIT {$limit}
            ");
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
                    $checkedoutDeletedColumn,
                    $itemWorkAreaColumn,
                    $sourceWorkArea,
                    $checkedoutWorkAreaColumn
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
                    'available' => $available
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

    sendResponse('error', 'Unknown action.', null, 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Invalid request method.', null, 405);
}

if (!userCanWrite($userData)) {
    sendResponse('error', 'This role has view-only access.', null, 403);
}

$payload = readPayload();
$action = $payload['action'] ?? 'transfer';
if ($action !== 'transfer' && $action !== 'backfill_transfer_work_area') {
    sendResponse('error', 'Unknown action.', null, 400);
}
if ($action === 'transfer') {
    requireApiCapability($userData, 'clinics');
}

if ($action === 'backfill_transfer_work_area') {
    if (!hasTransferBackfillAccess($userData)) {
        sendResponse('error', 'You are not allowed to run transfer backfill.', null, 403);
    }
    if (!$checkedoutWorkAreaColumn) {
        sendResponse('error', 'Cannot backfill: checked-out work area column not found.', null, 500);
    }

    $checkedoutIdColumn = pickColumn($checkedoutColumns, ['CID', 'cid', 'ID', 'id']);
    if (!$checkedoutIdColumn) {
        sendResponse('error', 'Cannot backfill: checked-out ID column not found.', null, 500);
    }

    $checkedoutCategoryColumn = pickColumn($checkedoutColumns, ['Category', 'category']);
    $limit = isset($payload['limit']) ? (int)$payload['limit'] : 5000;
    if ($limit <= 0) {
        $limit = 5000;
    }
    if ($limit > 20000) {
        $limit = 20000;
    }

    $whereParts = [
        "UPPER(COALESCE(NULLIF(TRIM(tc.{$checkedoutWorkAreaColumn}), ''), 'CHO')) IN ('CLINIC', 'BARANGAY', 'LABORATORY')"
    ];
    if ($checkedoutCategoryColumn) {
        $whereParts[] = "UPPER(COALESCE(NULLIF(TRIM(tc.{$checkedoutCategoryColumn}), ''), '')) IN ('CLINIC', 'BARANGAY', 'LABORATORY')";
    }
    $where = '(' . implode(' OR ', $whereParts) . ')';
    if ($checkedoutTypeColumn) {
        $where .= " AND UPPER(COALESCE(NULLIF(TRIM(tc.{$checkedoutTypeColumn}), ''), 'TRANSFER')) = 'TRANSFER'";
    }

    $selectCols = [
        "tc.{$checkedoutIdColumn} AS Row_ID",
        "tc.Barcode",
        "tc.Quantity",
        "tc.Checkout_Date"
    ];
    if ($checkedoutDestinationColumn) {
        $selectCols[] = "tc.{$checkedoutDestinationColumn} AS Destination_Name";
    } else {
        $selectCols[] = "tc.Barangay AS Destination_Name";
    }
    if ($checkedoutCategoryColumn) {
        $selectCols[] = "tc.{$checkedoutCategoryColumn} AS Transfer_Type";
    } else {
        $selectCols[] = "tc.{$checkedoutWorkAreaColumn} AS Transfer_Type";
    }
    if ($checkedoutRemarksColumn) {
        $selectCols[] = "tc.{$checkedoutRemarksColumn} AS Transfer_Remarks";
    } else {
        $selectCols[] = "'' AS Transfer_Remarks";
    }
    $selectCols[] = "tc.{$checkedoutWorkAreaColumn} AS Current_Work_Area";

    if (!acquireStockLock($conn)) {
        sendResponse('error', 'Another stock transaction is in progress. Please try again.', null, 503);
    }

    try {
        $conn->beginTransaction();

        $selectSql = "
            SELECT " . implode(', ', $selectCols) . "
            FROM tbl_checkedout_items tc
            WHERE {$where}
            ORDER BY tc.{$checkedoutIdColumn} ASC
            LIMIT {$limit}
        ";
        $rows = $conn->query($selectSql)->fetchAll(PDO::FETCH_ASSOC);

        $updateSql = "UPDATE tbl_checkedout_items SET {$checkedoutWorkAreaColumn} = :source_work_area WHERE {$checkedoutIdColumn} = :row_id";
        $updateStmt = $conn->prepare($updateSql);

        $processed = 0;
        $updated = 0;
        $destinationBackfilled = 0;
        $destinationQtyBackfilled = 0;
        $warnings = [];
        $destinationHadStock = [];

        foreach ($rows as $row) {
            $processed++;

            $rowId = (int)($row['Row_ID'] ?? 0);
            $barcode = normalizeString($row['Barcode'] ?? '');
            $quantity = (int)($row['Quantity'] ?? 0);
            $destination = normalizeString($row['Destination_Name'] ?? '');
            $transferType = normalizeWorkArea($row['Transfer_Type'] ?? ($row['Current_Work_Area'] ?? ''));
            if ($transferType === '') {
                $transferType = 'CLINIC';
            }
            $destinationWorkArea = resolveDestinationWorkArea($transferType, $destination, '');

            if ($rowId > 0) {
                $updateStmt->execute([
                    ':source_work_area' => $sourceWorkArea,
                    ':row_id' => $rowId
                ]);
                $updated += (int)$updateStmt->rowCount();
            }

            if ($barcode === '' || $quantity <= 0 || $destinationWorkArea === '') {
                continue;
            }

            $lookup = findItemByBarcode(
                $conn,
                $barcode,
                $categories,
                $checkedoutDeletedColumn,
                $checkedoutWorkAreaColumn,
                $sourceWorkArea
            );
            if (!$lookup) {
                if (count($warnings) < 50) {
                    $warnings[] = "Skipped {$barcode}: source item not found for destination backfill.";
                }
                continue;
            }

            if (empty($lookup['work_area_column'])) {
                if (count($warnings) < 50) {
                    $warnings[] = "Skipped {$barcode}: Work_Area column is missing in source table.";
                }
                continue;
            }

            $destinationKey = $lookup['table'] . '|' . $lookup['barcode_number'] . '|' . $destinationWorkArea;
            if (!array_key_exists($destinationKey, $destinationHadStock)) {
                $existingStmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM {$lookup['table']}
                    WHERE Barcode_Number = :barcode
                      AND UPPER(COALESCE(NULLIF(TRIM({$lookup['work_area_column']}), ''), 'CHO')) = :work_area
                      " . (!empty($lookup['item_deleted_column']) ? "AND {$lookup['item_deleted_column']} = 0" : '') . "
                ");
                $existingStmt->execute([
                    ':barcode' => $lookup['barcode_number'],
                    ':work_area' => $destinationWorkArea
                ]);
                $destinationHadStock[$destinationKey] = ((int)$existingStmt->fetchColumn()) > 0;
            }
            if ($destinationHadStock[$destinationKey]) {
                continue;
            }

            upsertTransferredStock(
                $conn,
                $lookup,
                $quantity,
                $destinationWorkArea,
                normalizeDateTime($row['Checkout_Date'] ?? '') ?: date('Y-m-d H:i:s'),
                $userData['username'] ?? 'system',
                normalizeString($row['Transfer_Remarks'] ?? '')
            );
            $destinationBackfilled++;
            $destinationQtyBackfilled += $quantity;
        }

        $conn->commit();

        sendResponse('success', 'Transfer backfill complete.', [
            'processed_rows' => $processed,
            'updated_checkedout_work_area_rows' => $updated,
            'destination_rows_backfilled' => $destinationBackfilled,
            'destination_quantity_backfilled' => $destinationQtyBackfilled
        ], 200, [
            'source_work_area' => $sourceWorkArea,
            'limit' => $limit,
            'warnings' => $warnings
        ]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        sendResponse('error', 'Transfer backfill failed: ' . $e->getMessage(), null, 400);
    }
}

// Transfers always draw from CHO stock.
if (!userCanAccessWorkArea($userData, $sourceWorkArea)) {
    sendResponse('error', 'Only CHO accounts can transfer stock.', null, 403);
}

$transferTypeInput = $payload['transfer_type'] ?? ($payload['category'] ?? ($payload['work_area'] ?? ''));
$transferType = normalizeWorkArea($transferTypeInput);
if ($transferType === '') {
    sendResponse('error', 'Please select a valid destination work area.', null, 400);
}

$destination = normalizeString($payload['destination'] ?? ($payload['target_name'] ?? ''));
if ($destination === '') {
    sendResponse('error', 'Destination name is required.', null, 400);
}

$destinationWorkArea = resolveDestinationWorkArea($transferType, $destination, $payload['destination_work_area'] ?? '');
if ($transferType === 'CLINIC' && $destinationWorkArea === '') {
    sendResponse('error', 'Unable to resolve destination clinic work area.', null, 400);
}

$remarks = normalizeString($payload['remarks'] ?? '');
$transferDateInput = normalizeString($payload['transfer_date'] ?? '');
$transferDate = null;
if ($transferDateInput !== '') {
    $transferDate = normalizeDateTime($transferDateInput);
    if ($transferDate === null) {
        sendResponse('error', 'Invalid transfer date format.', null, 400);
    }
}

$items = $payload['items'] ?? [];
if (is_string($items)) {
    $decoded = json_decode($items, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $items = $decoded;
    }
}

if (!is_array($items) || count($items) === 0) {
    sendResponse('error', 'Transfer requires at least one item.', null, 400);
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

$personId = normalizeString($payload['person_id'] ?? '');
if ($personId === '') {
    $personId = '0';
}

if (!acquireStockLock($conn)) {
    sendResponse('error', 'Another stock transaction is in progress. Please try again.', null, 503);
}

// Before the transaction: CREATE TABLE would commit it.
$receiptsAvailable = ensureTransferReceiptsTable($conn);

try {
    $conn->beginTransaction();

    $transferNumber = generateTransferNumber($conn, $checkedoutColumns);
    $checkoutDate = $transferDate ?: date('Y-m-d H:i:s');

    $insertCols = ['Barcode', 'PIID', 'Quantity', 'Checkout_Date', 'Checkout_By', 'Barangay', 'RIS_Number', 'Category'];
    $insertVals = ['?', '?', '?', '?', '?', '?', '?', '?'];
    $extraValues = [];

    if ($checkedoutDeletedColumn) {
        $insertCols[] = $checkedoutDeletedColumn;
        $insertVals[] = '0';
    }

    if ($checkedoutWorkAreaColumn) {
        $insertCols[] = $checkedoutWorkAreaColumn;
        $insertVals[] = '?';
        $extraValues[] = $sourceWorkArea;
    }

    if ($checkedoutDestinationColumn) {
        $insertCols[] = $checkedoutDestinationColumn;
        $insertVals[] = '?';
        $extraValues[] = $destination;
    }

    if ($checkedoutRemarksColumn) {
        $insertCols[] = $checkedoutRemarksColumn;
        $insertVals[] = '?';
        $extraValues[] = $remarks;
    }

    if ($checkedoutTypeColumn) {
        $insertCols[] = $checkedoutTypeColumn;
        $insertVals[] = '?';
        $extraValues[] = 'TRANSFER';
    }

    if ($checkedoutTransferDateColumn) {
        $insertCols[] = $checkedoutTransferDateColumn;
        $insertVals[] = '?';
        $extraValues[] = $checkoutDate;
    }

    if ($checkedoutTransferByColumn) {
        $insertCols[] = $checkedoutTransferByColumn;
        $insertVals[] = '?';
        $extraValues[] = $userData['username'] ?? 'system';
    }

    $insertSql = "INSERT INTO tbl_checkedout_items (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
    $insertStmt = $conn->prepare($insertSql);

    $transferred = [];
    $warnings = [];

    foreach ($normalizedItems as $barcode => $quantity) {
        $lookup = findItemByBarcode($conn, $barcode, $categories, $checkedoutDeletedColumn, $checkedoutWorkAreaColumn, $sourceWorkArea);
        if (!$lookup) {
            throw new Exception("No record found for barcode: {$barcode}");
        }

        $expiredOn = stockBatchExpiredOn($lookup['item']['Expiry_Date'] ?? '');
        if ($expiredOn !== null) {
            throw new Exception("Item {$barcode} expired on {$expiredOn} and cannot be transferred.");
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

        $baseValues = [
            $lookup['barcode_number'],
            $personId,
            $quantity,
            $checkoutDate,
            $userData['username'],
            $destination,
            $transferNumber,
            $transferType
        ];

        $insertStmt->execute(array_merge($baseValues, $extraValues));

        if ($destinationWorkArea !== '') {
            if (!empty($lookup['work_area_column'])) {
                upsertTransferredStock(
                    $conn,
                    $lookup,
                    $quantity,
                    $destinationWorkArea,
                    $checkoutDate,
                    $userData['username'] ?? 'system',
                    $remarks
                );
            } else {
                $warnings[] = "Skipped destination stock update for {$barcode}: Work_Area column is missing in source table.";
            }
        }

        $transferred[] = [
            'barcode' => $lookup['barcode_number'],
            'quantity' => $quantity,
            'item' => $lookup['item']['Item'] ?? '',
            'work_area' => $destinationWorkArea !== '' ? $destinationWorkArea : $sourceWorkArea,
            'transfer_type' => $transferType,
            'destination' => $destination
        ];
    }

    // The destination clinic confirms what actually arrived on Transfer Receipts.
    if ($receiptsAvailable) {
        createTransferReceipts(
            $conn,
            $transferNumber,
            $destination,
            $destinationWorkArea,
            $transferred,
            $userData['username'] ?? 'system',
            $checkoutDate
        );
    } else {
        $warnings[] = 'Transfer saved, but the receipt record could not be created.';
    }

    $conn->commit();

    $totalQty = 0;
    foreach ($transferred as $row) {
        $totalQty += (int)($row['quantity'] ?? 0);
    }
    logTransactionHistory($conn, [
        'module' => 'TRANSFER',
        'action' => 'TRANSFER',
        'transaction_type' => $transferType,
        'category' => $transferType,
        'reference_no' => $transferNumber,
        'quantity' => $totalQty,
        'performed_by' => $userData['username'] ?? 'system',
        'location' => $userData['location'] ?? '',
        'work_area' => $destinationWorkArea !== '' ? $destinationWorkArea : $sourceWorkArea,
        'details' => [
            'destination' => $destination,
            'source_work_area' => $sourceWorkArea,
            'destination_work_area' => $destinationWorkArea,
            'transfer_date' => $checkoutDate,
            'transfer_by' => $userData['username'] ?? '',
            'items' => $transferred
        ]
    ]);

    sendResponse('success', 'Clinic transfer complete.', [
        'transfer_number' => $transferNumber,
        'work_area' => $destinationWorkArea !== '' ? $destinationWorkArea : $sourceWorkArea,
        'source_work_area' => $sourceWorkArea,
        'destination_work_area' => $destinationWorkArea,
        'transfer_type' => $transferType,
        'destination' => $destination,
        'transfer_date' => $checkoutDate,
        'transfer_by' => $userData['username'] ?? '',
        'items' => $transferred
    ], 200, [
        'warnings' => $warnings
    ]);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    sendResponse('error', 'Transfer failed: ' . $e->getMessage(), null, 400);
}
?>
