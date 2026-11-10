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

function isAllWorkArea($value) {
    return strtoupper(trim((string)$value)) === 'ALL';
}

function normalizeDateOnly($value) {
    $value = trim((string)$value);
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

function hasHistoryAccess($userData) {
    return apiUserCan($userData, 'transaction_history');
}

function resolveRequestedWorkArea($userData, $rawInput) {
    $explicit = normalizeString($rawInput);
    if ($explicit !== '') {
        $workArea = normalizeWorkArea($explicit, 'CHO');
    } else {
        $workArea = normalizeWorkArea($userData['location'] ?? 'CHO', 'CHO');
    }

    if (!userCanAccessWorkArea($userData, $workArea)) {
        sendResponse('error', 'You are not allowed to access this work area.', null, 403);
    }
    return $workArea;
}

/**
 * Units still available for a barcode in a work area: received stock minus active checkouts/transfers.
 * Same formula as api_checkout.php getAvailableQuantity().
 */
function getAvailableStock($conn, $inventoryMetas, $meta, $barcode, $workArea) {
    $received = 0;
    foreach ($inventoryMetas as $invMeta) {
        $where = [quoteIdentifier($invMeta['barcode_match']) . ' = ?'];
        $params = [$barcode];
        if ($invMeta['is_deleted']) {
            $where[] = quoteIdentifier($invMeta['is_deleted']) . ' = 0';
        }
        $where[] = "UPPER(COALESCE(NULLIF(TRIM(" . quoteIdentifier($invMeta['work_area']) . "), ''), 'CHO')) = ?";
        $params[] = $workArea;

        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(" . quoteIdentifier($invMeta['quantity']) . "), 0)"
            . " FROM " . quoteIdentifier($invMeta['table'])
            . " WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $received += (int)$stmt->fetchColumn();
    }

    $where = [quoteIdentifier($meta['barcode']) . ' = ?'];
    $params = [$barcode];
    if ($meta['is_deleted']) {
        $where[] = quoteIdentifier($meta['is_deleted']) . ' = 0';
    }
    if ($meta['work_area']) {
        $where[] = "UPPER(COALESCE(NULLIF(TRIM(" . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = ?";
        $params[] = $workArea;
    }
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(" . quoteIdentifier($meta['quantity']) . "), 0)"
        . " FROM tbl_checkedout_items WHERE " . implode(' AND ', $where)
    );
    $stmt->execute($params);
    $checkedOut = (int)$stmt->fetchColumn();

    return $received - $checkedOut;
}

function formatDateTimeValue($value) {
    $value = normalizeString($value);
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }
    return date('Y-m-d H:i:s', $timestamp);
}

function resolveDestinationWorkArea($transferType, $destination, $explicit = '') {
    $mapped = normalizeWorkArea($explicit, '');
    if ($mapped !== '' && $mapped !== 'ALL') {
        return $mapped;
    }

    // Must match resolveDestinationWorkArea() in api_clinics.php, which added the destination stock.
    $transferType = strtoupper(normalizeString($transferType));
    if ($transferType === 'LABORATORY' || $transferType === 'BARANGAY') {
        return $transferType;
    }
    if ($transferType !== 'CLINIC') {
        return '';
    }

    $destinationLower = strtolower((string)$destination);
    if (strpos($destinationLower, 'aglayan') !== false) {
        return 'AGLAYAN';
    }
    if (strpos($destinationLower, 'uplc') !== false) {
        return 'UPLC';
    }

    return '';
}

function detectTransactionKind($referenceNo, $transactionType, $destination) {
    $transactionType = strtoupper(normalizeString($transactionType));
    if ($transactionType === 'TRANSFER') {
        return 'TRANSFER';
    }

    $ref = strtoupper(normalizeString($referenceNo));
    if ($transactionType === 'ADJUSTMENT' || strpos($ref, 'ADJ-') === 0) {
        return 'ADJUSTMENT';
    }
    if (strpos($ref, 'TRF') === 0) {
        return 'TRANSFER';
    }

    if (normalizeString($destination) !== '') {
        return 'TRANSFER';
    }

    return 'CHECKOUT';
}

function buildCheckedoutMeta($conn) {
    $columns = getTableColumns($conn, 'tbl_checkedout_items');
    if (empty($columns)) {
        sendResponse('error', 'Checkout table metadata not found.', null, 500);
    }

    $meta = [
        'columns' => $columns,
        'id' => pickColumn($columns, ['CID', 'cid', 'ID', 'id']),
        'reference_no' => pickColumn($columns, ['RIS_Number', 'ris_number', 'RIS_No', 'ris_no', 'Reference_No', 'reference_no']),
        'barcode' => pickColumn($columns, ['Barcode', 'barcode', 'Barcode_Number', 'barcode_number']),
        'quantity' => pickColumn($columns, ['Quantity', 'quantity']),
        'checkout_date' => pickColumn($columns, ['Checkout_Date', 'checkout_date']),
        'checkout_by' => pickColumn($columns, ['Checkout_By', 'checkout_by']),
        'category' => pickColumn($columns, ['Category', 'category']),
        'barangay' => pickColumn($columns, ['Barangay', 'barangay']),
        'destination' => pickColumn($columns, ['Destination', 'destination', 'Target_Area', 'target_area']),
        'transaction_type' => pickColumn($columns, ['Transaction_Type', 'transaction_type', 'Type', 'type']),
        'transfer_date' => pickColumn($columns, ['Transfer_Date', 'transfer_date', 'Date_Transfer', 'date_transfer']),
        'transfer_by' => pickColumn($columns, ['Transfer_By', 'transfer_by', 'Transferred_By', 'transferred_by']),
        'remarks' => pickColumn($columns, ['Remarks', 'remarks', 'Notes', 'notes']),
        'work_area' => pickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
        'is_deleted' => getIsDeletedColumn($columns)
    ];

    if (!$meta['reference_no'] || !$meta['barcode'] || !$meta['quantity']) {
        sendResponse('error', 'Checkout table is missing required columns.', null, 500);
    }

    return $meta;
}

function buildInventoryMetas($conn) {
    $tables = [
        'tbl_item_medicine',
        'tbl_item_medical_supplies',
        'tbl_item_vaccines',
        'tbl_item_lab_reagents'
    ];

    $metas = [];
    foreach ($tables as $table) {
        $columns = getTableColumns($conn, $table);
        if (empty($columns)) {
            continue;
        }

        $quantity = pickColumn($columns, ['Quantity', 'quantity']);
        $barcodeNumber = pickColumn($columns, ['Barcode_Number', 'barcode_number']);
        $barcode = pickColumn($columns, ['Barcode', 'barcode']);
        $workArea = pickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
        $isDeleted = getIsDeletedColumn($columns);

        if (!$quantity || (!$barcodeNumber && !$barcode) || !$workArea) {
            continue;
        }

        $metas[] = [
            'table' => $table,
            'columns' => $columns,
            'iid' => pickColumn($columns, ['IID', 'iid']),
            'id' => pickColumn($columns, ['ID', 'id']),
            'quantity' => $quantity,
            'barcode_number' => $barcodeNumber,
            'barcode' => $barcode,
            'barcode_match' => $barcodeNumber ?: $barcode,
            'item' => pickColumn($columns, ['Item', 'item']),
            'description' => pickColumn($columns, ['Description', 'description']),
            'entity' => pickColumn($columns, ['Entity', 'entity']),
            'unit_cost' => pickColumn($columns, ['Unit_Cost', 'unit_cost', 'UnitCost']),
            'expiry_date' => pickColumn($columns, ['Expiry_Date', 'expiry_date']),
            'date_added' => pickColumn($columns, ['Date_Added', 'date_added']),
            'date_updated' => pickColumn($columns, ['Date_Updated', 'date_updated']),
            'added_by' => pickColumn($columns, ['Added_By', 'added_by', 'Created_By', 'created_by']),
            'remarks' => pickColumn($columns, ['Remarks', 'remarks', 'Notes', 'notes']),
            'donated' => pickColumn($columns, ['Donated', 'donated']),
            'po_number' => pickColumn($columns, ['PO_Number', 'po_number']),
            'work_area' => $workArea,
            'is_deleted' => $isDeleted
        ];
    }

    return $metas;
}

function findDestinationInventoryRow($conn, $meta, $barcode, $destinationWorkArea) {
    $where = [];
    $params = [
        ':barcode' => $barcode,
        ':destination_work_area' => $destinationWorkArea
    ];

    $where[] = 't.' . quoteIdentifier($meta['barcode_match']) . ' = :barcode';
    $where[] = "UPPER(COALESCE(NULLIF(TRIM(t." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = :destination_work_area";
    if ($meta['is_deleted']) {
        $where[] = 't.' . quoteIdentifier($meta['is_deleted']) . ' = 0';
    }

    $selectCols = ['t.' . quoteIdentifier($meta['quantity']) . ' AS qty'];
    if ($meta['iid']) {
        $selectCols[] = 't.' . quoteIdentifier($meta['iid']) . ' AS row_key';
    } elseif ($meta['id']) {
        $selectCols[] = 't.' . quoteIdentifier($meta['id']) . ' AS row_key';
    } else {
        $selectCols[] = 'NULL AS row_key';
    }

    $orderParts = [];
    if ($meta['iid']) {
        $orderParts[] = 't.' . quoteIdentifier($meta['iid']) . ' DESC';
    } elseif ($meta['id']) {
        $orderParts[] = 't.' . quoteIdentifier($meta['id']) . ' DESC';
    }
    if ($meta['date_added']) {
        $orderParts[] = 't.' . quoteIdentifier($meta['date_added']) . ' DESC';
    }
    $orderSql = empty($orderParts) ? '' : (' ORDER BY ' . implode(', ', $orderParts));

    $sql = "
        SELECT " . implode(', ', $selectCols) . "
        FROM " . quoteIdentifier($meta['table']) . " t
        WHERE " . implode(' AND ', $where) . "
        {$orderSql}
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function findSourceTemplateRow($conn, $meta, $barcode) {
    $where = ['t.' . quoteIdentifier($meta['barcode_match']) . ' = :barcode'];
    $params = [':barcode' => $barcode];

    if ($meta['is_deleted']) {
        $where[] = 't.' . quoteIdentifier($meta['is_deleted']) . ' = 0';
    }

    $orderParts = [];
    if ($meta['work_area']) {
        $orderParts[] = "CASE WHEN UPPER(COALESCE(NULLIF(TRIM(t." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = 'CHO' THEN 0 ELSE 1 END";
    }
    if ($meta['iid']) {
        $orderParts[] = 't.' . quoteIdentifier($meta['iid']) . ' DESC';
    } elseif ($meta['id']) {
        $orderParts[] = 't.' . quoteIdentifier($meta['id']) . ' DESC';
    }
    if ($meta['date_added']) {
        $orderParts[] = 't.' . quoteIdentifier($meta['date_added']) . ' DESC';
    }
    $orderSql = empty($orderParts) ? '' : (' ORDER BY ' . implode(', ', $orderParts));

    $sql = "
        SELECT t.*
        FROM " . quoteIdentifier($meta['table']) . " t
        WHERE " . implode(' AND ', $where) . "
        {$orderSql}
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function updateDestinationInventoryRow($conn, $meta, $barcode, $destinationWorkArea, $rowKey, $newQuantity, $setDeleted = null) {
    $parts = [quoteIdentifier($meta['quantity']) . ' = :new_qty'];
    $params = [':new_qty' => (int)$newQuantity];

    if ($meta['date_updated']) {
        $parts[] = quoteIdentifier($meta['date_updated']) . ' = NOW()';
    }
    if ($setDeleted !== null && $meta['is_deleted']) {
        $parts[] = quoteIdentifier($meta['is_deleted']) . ' = :set_deleted';
        $params[':set_deleted'] = (int)$setDeleted;
    }

    $where = [];
    if ($rowKey !== null && $rowKey !== '') {
        if ($meta['iid']) {
            $where[] = quoteIdentifier($meta['iid']) . ' = :row_key';
            $params[':row_key'] = (int)$rowKey;
        } elseif ($meta['id']) {
            $where[] = quoteIdentifier($meta['id']) . ' = :row_key';
            $params[':row_key'] = (int)$rowKey;
        }
    }
    if (empty($where)) {
        $where[] = quoteIdentifier($meta['barcode_match']) . ' = :barcode';
        $where[] = "UPPER(COALESCE(NULLIF(TRIM(" . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = :destination_work_area";
        $params[':barcode'] = $barcode;
        $params[':destination_work_area'] = $destinationWorkArea;
        if ($meta['is_deleted']) {
            $where[] = quoteIdentifier($meta['is_deleted']) . ' = 0';
        }
    }

    $sql = "
        UPDATE " . quoteIdentifier($meta['table']) . "
        SET " . implode(', ', $parts) . "
        WHERE " . implode(' AND ', $where);
    if (empty($rowKey) && $meta['iid']) {
        $sql .= " ORDER BY " . quoteIdentifier($meta['iid']) . " DESC LIMIT 1";
    } elseif (empty($rowKey) && $meta['id']) {
        $sql .= " ORDER BY " . quoteIdentifier($meta['id']) . " DESC LIMIT 1";
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
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

function insertDestinationInventoryRow($conn, $meta, $templateRow, $barcode, $destinationWorkArea, $quantity, $performedBy, $remarks = '') {
    $insertCols = [];
    $insertVals = [];
    $insertParams = [];

    $addColumn = function ($column, $value) use (&$insertCols, &$insertVals, &$insertParams, $meta) {
        if (!$column || !in_array($column, $meta['columns'], true)) {
            return;
        }
        $insertCols[] = quoteIdentifier($column);
        $insertVals[] = '?';
        $insertParams[] = $value;
    };

    if ($meta['id'] && isset($templateRow[$meta['id']]) && $templateRow[$meta['id']] !== null && $templateRow[$meta['id']] !== '') {
        $addColumn($meta['id'], $templateRow[$meta['id']]);
    }

    if ($meta['barcode']) {
        $addColumn($meta['barcode'], buildTransferBarcode($barcode, $destinationWorkArea));
    }
    if ($meta['barcode_number']) {
        $addColumn($meta['barcode_number'], $barcode);
    } elseif ($meta['barcode']) {
        $addColumn($meta['barcode'], $barcode);
    }

    $addColumn($meta['item'], $templateRow[$meta['item']] ?? '');
    $addColumn($meta['description'], $templateRow[$meta['description']] ?? '');
    $addColumn($meta['entity'], $templateRow[$meta['entity']] ?? '');
    $addColumn($meta['unit_cost'], isset($templateRow[$meta['unit_cost']]) ? (float)$templateRow[$meta['unit_cost']] : 0);
    $addColumn($meta['quantity'], (int)$quantity);
    $addColumn($meta['expiry_date'], $templateRow[$meta['expiry_date']] ?? null);
    $addColumn($meta['date_added'], date('Y-m-d H:i:s'));
    $addColumn($meta['added_by'], $performedBy ?: 'system');

    $rowRemarks = normalizeString($remarks);
    if ($rowRemarks === '' && $meta['remarks']) {
        $rowRemarks = normalizeString($templateRow[$meta['remarks']] ?? '');
    }
    $addColumn($meta['remarks'], $rowRemarks);

    if ($meta['donated']) {
        $donatedValue = isset($templateRow[$meta['donated']]) ? (int)$templateRow[$meta['donated']] : 0;
        $addColumn($meta['donated'], $donatedValue);
    }
    $addColumn($meta['po_number'], $templateRow[$meta['po_number']] ?? '');
    $addColumn($meta['work_area'], $destinationWorkArea);

    if ($meta['is_deleted'] && in_array($meta['is_deleted'], $meta['columns'], true)) {
        $insertCols[] = quoteIdentifier($meta['is_deleted']);
        $insertVals[] = '0';
    }

    if (empty($insertCols)) {
        return false;
    }

    $sql = "
        INSERT INTO " . quoteIdentifier($meta['table']) . " (" . implode(', ', $insertCols) . ")
        VALUES (" . implode(', ', $insertVals) . ")
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($insertParams);
    return true;
}

function adjustDestinationStockForTransfer($conn, $inventoryMetas, $barcode, $quantity, $destinationWorkArea, $mode, $performedBy, $remarks, &$warnings) {
    $quantity = (int)$quantity;
    if ($barcode === '' || $quantity <= 0 || $destinationWorkArea === '') {
        return;
    }

    foreach ($inventoryMetas as $meta) {
        $destinationRow = findDestinationInventoryRow($conn, $meta, $barcode, $destinationWorkArea);
        if ($destinationRow) {
            $currentQty = (int)($destinationRow['qty'] ?? 0);
            if ($mode === 'decrease') {
                // Take the quantity back from the newest destination rows first, across as many
                // rows as needed, archiving each row that reaches zero.
                $remaining = $quantity;
                for ($guard = 0; $destinationRow && $remaining > 0 && $guard < 1000; $guard++) {
                    $currentQty = (int)($destinationRow['qty'] ?? 0);
                    $take = min($remaining, max(0, $currentQty));
                    $nextQty = $currentQty - $take;
                    $setDeleted = ($nextQty <= 0 && $meta['is_deleted']) ? 1 : null;
                    updateDestinationInventoryRow(
                        $conn,
                        $meta,
                        $barcode,
                        $destinationWorkArea,
                        $destinationRow['row_key'] ?? null,
                        $nextQty,
                        $setDeleted
                    );
                    $remaining -= $take;
                    if ($setDeleted === null) {
                        break;
                    }
                    $destinationRow = findDestinationInventoryRow($conn, $meta, $barcode, $destinationWorkArea);
                }
                if ($remaining > 0) {
                    $warnings[] = "Only " . ($quantity - $remaining) . " of {$quantity} unit(s) of {$barcode} could be removed from {$destinationWorkArea} stock.";
                }
            } else {
                $nextQty = $currentQty + $quantity;
                updateDestinationInventoryRow(
                    $conn,
                    $meta,
                    $barcode,
                    $destinationWorkArea,
                    $destinationRow['row_key'] ?? null,
                    $nextQty,
                    ($meta['is_deleted'] ? 0 : null)
                );
            }
            return;
        }

        if ($mode === 'increase') {
            $template = findSourceTemplateRow($conn, $meta, $barcode);
            if ($template) {
                $inserted = insertDestinationInventoryRow(
                    $conn,
                    $meta,
                    $template,
                    $barcode,
                    $destinationWorkArea,
                    $quantity,
                    $performedBy,
                    $remarks
                );
                if ($inserted) {
                    return;
                }
            }
        }
    }

    if ($mode === 'decrease') {
        $warnings[] = "No destination stock row found for barcode {$barcode} ({$destinationWorkArea}).";
    } else {
        $warnings[] = "Unable to restore destination stock for barcode {$barcode} ({$destinationWorkArea}).";
    }
}

function fetchItemNameLookup($conn, $barcodes, $requestedWorkArea) {
    $lookup = [];
    $barcodes = array_values(array_unique(array_filter(array_map('normalizeString', (array)$barcodes))));
    if (empty($barcodes)) {
        return $lookup;
    }

    $inventoryMetas = buildInventoryMetas($conn);
    if (empty($inventoryMetas)) {
        return $lookup;
    }

    $placeholderList = implode(', ', array_fill(0, count($barcodes), '?'));
    foreach ($inventoryMetas as $meta) {
        if (!$meta['item']) {
            continue;
        }

        $params = $barcodes;
        $where = ['t.' . quoteIdentifier($meta['barcode_match']) . " IN ({$placeholderList})"];
        if ($meta['is_deleted']) {
            $where[] = 't.' . quoteIdentifier($meta['is_deleted']) . ' = 0';
        }
        if (!isAllWorkArea($requestedWorkArea) && $meta['work_area']) {
            $where[] = "UPPER(COALESCE(NULLIF(TRIM(t." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = ?";
            $params[] = $requestedWorkArea;
        }

        $orderParts = [];
        if ($meta['iid']) {
            $orderParts[] = 't.' . quoteIdentifier($meta['iid']) . ' DESC';
        } elseif ($meta['id']) {
            $orderParts[] = 't.' . quoteIdentifier($meta['id']) . ' DESC';
        }
        if ($meta['date_added']) {
            $orderParts[] = 't.' . quoteIdentifier($meta['date_added']) . ' DESC';
        }
        $orderSql = empty($orderParts) ? '' : (' ORDER BY ' . implode(', ', $orderParts));

        $sql = "
            SELECT
                t." . quoteIdentifier($meta['barcode_match']) . " AS barcode,
                t." . quoteIdentifier($meta['item']) . " AS item_name,
                " . ($meta['description'] ? ('t.' . quoteIdentifier($meta['description'])) : "''") . " AS description
            FROM " . quoteIdentifier($meta['table']) . " t
            WHERE " . implode(' AND ', $where) . "
            {$orderSql}
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $barcode = normalizeString($row['barcode'] ?? '');
            if ($barcode === '' || isset($lookup[$barcode])) {
                continue;
            }
            $lookup[$barcode] = [
                'item_name' => normalizeString($row['item_name'] ?? ''),
                'description' => normalizeString($row['description'] ?? '')
            ];
        }
    }

    return $lookup;
}

function buildReferenceLogRows($conn, $referenceNo) {
    $columns = getTableColumns($conn, 'tbl_transaction_history');
    if (empty($columns)) {
        return [];
    }

    $referenceCol = pickColumn($columns, ['Reference_No', 'reference_no', 'Reference', 'reference']);
    if (!$referenceCol) {
        return [];
    }

    $moduleCol = pickColumn($columns, ['Module', 'module']);
    $actionCol = pickColumn($columns, ['Action', 'action']);
    $typeCol = pickColumn($columns, ['Transaction_Type', 'transaction_type', 'Type', 'type']);
    $quantityCol = pickColumn($columns, ['Quantity', 'quantity']);
    $detailsCol = pickColumn($columns, ['Details', 'details', 'Meta', 'meta']);
    $performedByCol = pickColumn($columns, ['Performed_By', 'performed_by', 'User', 'user', 'Username', 'username']);
    $performedAtCol = pickColumn($columns, ['Performed_At', 'performed_at', 'Created_At', 'created_at']);

    $selectCols = [
        ($moduleCol ? quoteIdentifier($moduleCol) : "''") . " AS module_name",
        ($actionCol ? quoteIdentifier($actionCol) : "''") . " AS action_name",
        ($typeCol ? quoteIdentifier($typeCol) : "''") . " AS transaction_type",
        ($quantityCol ? quoteIdentifier($quantityCol) : "0") . " AS quantity",
        ($detailsCol ? quoteIdentifier($detailsCol) : "''") . " AS details",
        ($performedByCol ? quoteIdentifier($performedByCol) : "''") . " AS performed_by",
        ($performedAtCol ? quoteIdentifier($performedAtCol) : "NULL") . " AS performed_at"
    ];

    $orderSql = $performedAtCol ? (" ORDER BY " . quoteIdentifier($performedAtCol) . " DESC") : '';
    $sql = "
        SELECT " . implode(', ', $selectCols) . "
        FROM tbl_transaction_history
        WHERE " . quoteIdentifier($referenceCol) . " = ?
        {$orderSql}
        LIMIT 100
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$referenceNo]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['module_name'] = normalizeString($row['module_name'] ?? '');
        $row['action_name'] = normalizeString($row['action_name'] ?? '');
        $row['transaction_type'] = normalizeString($row['transaction_type'] ?? '');
        $row['quantity'] = (int)($row['quantity'] ?? 0);
        $row['performed_by'] = normalizeString($row['performed_by'] ?? '');
        $row['performed_at'] = formatDateTimeValue($row['performed_at'] ?? '');

        $detailsRaw = $row['details'] ?? '';
        $decoded = null;
        if (is_string($detailsRaw) && $detailsRaw !== '') {
            $parsed = json_decode($detailsRaw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $parsed;
            }
        }
        if ($decoded !== null) {
            $row['details'] = $decoded;
        } else {
            $row['details'] = normalizeString($detailsRaw);
        }
    }
    unset($row);

    return $rows;
}

function handleListAction($conn, $meta, $requestedWorkArea) {
    $typeFilter = strtoupper(normalizeString($_GET['type'] ?? 'ALL'));
    if (!in_array($typeFilter, ['ALL', 'CHECKOUT', 'TRANSFER', 'ADJUSTMENT'], true)) {
        $typeFilter = 'ALL';
    }

    $statusFilter = strtoupper(normalizeString($_GET['status'] ?? 'ALL'));
    if (!in_array($statusFilter, ['ALL', 'ACTIVE', 'VOIDED'], true)) {
        $statusFilter = 'ALL';
    }

    $search = normalizeString($_GET['search'] ?? '');

    $fromDate = normalizeDateOnly($_GET['from'] ?? '');
    if ($fromDate === false) {
        sendResponse('error', 'Invalid from date.', null, 400);
    }

    $toDate = normalizeDateOnly($_GET['to'] ?? '');
    if ($toDate === false) {
        sendResponse('error', 'Invalid to date.', null, 400);
    }

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page <= 0) {
        $page = 1;
    }

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    if ($limit <= 0) {
        $limit = 20;
    }
    if ($limit > 100) {
        $limit = 100;
    }

    $offset = ($page - 1) * $limit;

    $refExpr = 'tc.' . quoteIdentifier($meta['reference_no']);
    $quantityExpr = 'tc.' . quoteIdentifier($meta['quantity']);

    $checkoutDateExpr = $meta['checkout_date'] ? ('tc.' . quoteIdentifier($meta['checkout_date'])) : 'NULL';
    $transferDateExpr = $meta['transfer_date'] ? ('tc.' . quoteIdentifier($meta['transfer_date'])) : 'NULL';
    $dateExpr = ($meta['checkout_date'] || $meta['transfer_date'])
        ? "COALESCE({$transferDateExpr}, {$checkoutDateExpr})"
        : 'NOW()';

    $checkoutByExpr = $meta['checkout_by'] ? ('tc.' . quoteIdentifier($meta['checkout_by'])) : "''";
    $transferByExpr = $meta['transfer_by'] ? ('tc.' . quoteIdentifier($meta['transfer_by'])) : "''";
    $performedByExpr = "COALESCE(NULLIF(TRIM({$transferByExpr}), ''), {$checkoutByExpr})";

    $categoryExpr = $meta['category'] ? ('tc.' . quoteIdentifier($meta['category'])) : "''";
    $destinationExpr = $meta['destination'] ? ('tc.' . quoteIdentifier($meta['destination'])) : "''";
    $barangayExpr = $meta['barangay'] ? ('tc.' . quoteIdentifier($meta['barangay'])) : "''";
    $transactionTypeExpr = $meta['transaction_type']
        ? ("UPPER(COALESCE(NULLIF(TRIM(tc." . quoteIdentifier($meta['transaction_type']) . "), ''), ''))")
        : "''";

    $transferMatchParts = [];
    if ($meta['transaction_type']) {
        $transferMatchParts[] = "{$transactionTypeExpr} = 'TRANSFER'";
    }
    $transferMatchParts[] = "UPPER(LEFT({$refExpr}, 3)) = 'TRF'";
    if ($meta['destination']) {
        $transferMatchParts[] = "COALESCE(NULLIF(TRIM({$destinationExpr}), ''), '') <> ''";
    }
    $transferMatchExpr = '(' . implode(' OR ', $transferMatchParts) . ')';

    $activeRowsExpr = $meta['is_deleted']
        ? ("SUM(CASE WHEN tc." . quoteIdentifier($meta['is_deleted']) . " = 0 THEN 1 ELSE 0 END)")
        : 'COUNT(*)';
    $activeQuantityExpr = $meta['is_deleted']
        ? ("SUM(CASE WHEN tc." . quoteIdentifier($meta['is_deleted']) . " = 0 THEN {$quantityExpr} ELSE 0 END)")
        : "SUM({$quantityExpr})";

    $where = [
        "{$refExpr} IS NOT NULL",
        "TRIM({$refExpr}) <> ''"
    ];
    $params = [];

    if ($meta['work_area'] && !isAllWorkArea($requestedWorkArea)) {
        $where[] = "UPPER(COALESCE(NULLIF(TRIM(tc." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = :work_area";
        $params[':work_area'] = $requestedWorkArea;
    }

    if ($fromDate) {
        $where[] = "DATE({$dateExpr}) >= :date_from";
        $params[':date_from'] = $fromDate;
    }
    if ($toDate) {
        $where[] = "DATE({$dateExpr}) <= :date_to";
        $params[':date_to'] = $toDate;
    }

    if ($search !== '') {
        $searchParts = ["{$refExpr} LIKE :search"];
        if ($meta['checkout_by']) {
            $searchParts[] = $checkoutByExpr . ' LIKE :search';
        }
        if ($meta['transfer_by']) {
            $searchParts[] = $transferByExpr . ' LIKE :search';
        }
        if ($meta['destination']) {
            $searchParts[] = $destinationExpr . ' LIKE :search';
        }
        if ($meta['barangay']) {
            $searchParts[] = $barangayExpr . ' LIKE :search';
        }
        if ($meta['category']) {
            $searchParts[] = $categoryExpr . ' LIKE :search';
        }
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
        $params[':search'] = '%' . $search . '%';
    }

    $having = [];
    if ($typeFilter === 'TRANSFER') {
        $having[] = 'transfer_hits > 0';
    } elseif ($typeFilter === 'CHECKOUT') {
        $having[] = "transfer_hits = 0 AND reference_no NOT LIKE 'ADJ-%'";
    } elseif ($typeFilter === 'ADJUSTMENT') {
        $having[] = "reference_no LIKE 'ADJ-%'";
    }

    if ($statusFilter === 'ACTIVE') {
        $having[] = 'active_rows > 0';
    } elseif ($statusFilter === 'VOIDED') {
        if ($meta['is_deleted']) {
            $having[] = 'active_rows = 0';
        } else {
            $having[] = '1 = 0';
        }
    }

    $baseWhereSql = 'WHERE ' . implode(' AND ', $where);
    $havingSql = empty($having) ? '' : (' HAVING ' . implode(' AND ', $having));

    $groupSql = "
        SELECT
            {$refExpr} AS reference_no,
            {$activeRowsExpr} AS active_rows,
            {$activeQuantityExpr} AS active_quantity,
            SUM(CASE WHEN {$transferMatchExpr} THEN 1 ELSE 0 END) AS transfer_hits
        FROM tbl_checkedout_items tc
        {$baseWhereSql}
        GROUP BY {$refExpr}
        {$havingSql}
    ";

    $countSql = "SELECT COUNT(*) AS total_count FROM ({$groupSql}) grouped_refs";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalRows = (int)($countStmt->fetchColumn() ?: 0);

    $dataSql = "
        SELECT
            {$refExpr} AS reference_no,
            MIN({$dateExpr}) AS transaction_date,
            MAX({$performedByExpr}) AS performed_by,
            MAX({$categoryExpr}) AS category,
            MAX({$destinationExpr}) AS destination,
            MAX({$barangayExpr}) AS barangay,
            SUM({$quantityExpr}) AS total_quantity,
            {$activeQuantityExpr} AS active_quantity,
            {$activeRowsExpr} AS active_rows,
            COUNT(*) AS total_rows,
            SUM(CASE WHEN {$transferMatchExpr} THEN 1 ELSE 0 END) AS transfer_hits
        FROM tbl_checkedout_items tc
        {$baseWhereSql}
        GROUP BY {$refExpr}
        {$havingSql}
        ORDER BY MIN({$dateExpr}) DESC, {$refExpr} DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt = $conn->prepare($dataSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalQuantity = 0;
    $totalActiveQuantity = 0;
    $transferCount = 0;
    $checkoutCount = 0;
    $voidedCount = 0;

    foreach ($rows as &$row) {
        $referenceNo = normalizeString($row['reference_no'] ?? '');
        $category = strtoupper(normalizeString($row['category'] ?? ''));
        $destination = normalizeString($row['destination'] ?? '');
        $totalQty = (int)($row['total_quantity'] ?? 0);
        $activeQty = (int)($row['active_quantity'] ?? 0);
        $activeRows = (int)($row['active_rows'] ?? 0);
        $transferHits = (int)($row['transfer_hits'] ?? 0);

        $kind = ($transferHits > 0) ? 'TRANSFER' : detectTransactionKind($referenceNo, '', $destination);
        $status = ($meta['is_deleted'] && $activeRows === 0) ? 'VOIDED' : 'ACTIVE';

        $row['reference_no'] = $referenceNo;
        $row['transaction_date'] = formatDateTimeValue($row['transaction_date'] ?? '');
        $row['performed_by'] = normalizeString($row['performed_by'] ?? '');
        $row['category'] = $category;
        $row['destination'] = $destination;
        $row['barangay'] = normalizeString($row['barangay'] ?? '');
        $row['total_quantity'] = $totalQty;
        $row['active_quantity'] = $activeQty;
        $row['active_rows'] = $activeRows;
        $row['total_rows'] = (int)($row['total_rows'] ?? 0);
        $row['transaction_kind'] = $kind;
        $row['status'] = $status;
        $row['can_void'] = ($meta['is_deleted'] && $status === 'ACTIVE');
        $row['can_undo'] = ($meta['is_deleted'] && $status === 'VOIDED');

        $totalQuantity += $totalQty;
        $totalActiveQuantity += $activeQty;
        if ($kind === 'TRANSFER') {
            $transferCount++;
        } else {
            $checkoutCount++;
        }
        if ($status === 'VOIDED') {
            $voidedCount++;
        }
    }
    unset($row);

    sendResponse('success', 'Transaction history retrieved.', $rows, 200, [
        'page' => $page,
        'limit' => $limit,
        'total_rows' => $totalRows,
        'total_pages' => ($limit > 0 ? (int)ceil($totalRows / $limit) : 1),
        'work_area' => $requestedWorkArea,
        'filters' => [
            'type' => $typeFilter,
            'status' => $statusFilter,
            'search' => $search,
            'from' => $fromDate,
            'to' => $toDate
        ],
        'totals' => [
            'quantity' => $totalQuantity,
            'active_quantity' => $totalActiveQuantity,
            'checkout_count' => $checkoutCount,
            'transfer_count' => $transferCount,
            'voided_count' => $voidedCount
        ]
    ]);
}

function handleDetailsAction($conn, $meta, $requestedWorkArea) {
    $referenceNo = normalizeString($_GET['reference_no'] ?? '');
    if ($referenceNo === '') {
        sendResponse('error', 'Reference number is required.', null, 400);
    }

    $where = ['tc.' . quoteIdentifier($meta['reference_no']) . ' = :reference_no'];
    $params = [':reference_no' => $referenceNo];

    if ($meta['work_area'] && !isAllWorkArea($requestedWorkArea)) {
        $where[] = "UPPER(COALESCE(NULLIF(TRIM(tc." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = :work_area";
        $params[':work_area'] = $requestedWorkArea;
    }

    $selectCols = [
        'tc.' . quoteIdentifier($meta['reference_no']) . ' AS reference_no',
        'tc.' . quoteIdentifier($meta['barcode']) . ' AS barcode',
        'tc.' . quoteIdentifier($meta['quantity']) . ' AS quantity'
    ];
    $selectCols[] = $meta['checkout_date'] ? ('tc.' . quoteIdentifier($meta['checkout_date']) . ' AS checkout_date') : "NULL AS checkout_date";
    $selectCols[] = $meta['checkout_by'] ? ('tc.' . quoteIdentifier($meta['checkout_by']) . ' AS checkout_by') : "'' AS checkout_by";
    $selectCols[] = $meta['category'] ? ('tc.' . quoteIdentifier($meta['category']) . ' AS category') : "'' AS category";
    $selectCols[] = $meta['barangay'] ? ('tc.' . quoteIdentifier($meta['barangay']) . ' AS barangay') : "'' AS barangay";
    $selectCols[] = $meta['destination'] ? ('tc.' . quoteIdentifier($meta['destination']) . ' AS destination') : "'' AS destination";
    $selectCols[] = $meta['transaction_type'] ? ('tc.' . quoteIdentifier($meta['transaction_type']) . ' AS transaction_type') : "'' AS transaction_type";
    $selectCols[] = $meta['transfer_date'] ? ('tc.' . quoteIdentifier($meta['transfer_date']) . ' AS transfer_date') : "NULL AS transfer_date";
    $selectCols[] = $meta['transfer_by'] ? ('tc.' . quoteIdentifier($meta['transfer_by']) . ' AS transfer_by') : "'' AS transfer_by";
    $selectCols[] = $meta['work_area'] ? ('tc.' . quoteIdentifier($meta['work_area']) . ' AS work_area') : "'' AS work_area";
    $selectCols[] = $meta['is_deleted'] ? ('tc.' . quoteIdentifier($meta['is_deleted']) . ' AS is_deleted') : "0 AS is_deleted";
    $selectCols[] = $meta['remarks'] ? ('tc.' . quoteIdentifier($meta['remarks']) . ' AS remarks') : "'' AS remarks";
    $selectCols[] = $meta['id'] ? ('tc.' . quoteIdentifier($meta['id']) . ' AS row_id') : "NULL AS row_id";

    $orderParts = [];
    if ($meta['id']) {
        $orderParts[] = 'tc.' . quoteIdentifier($meta['id']) . ' ASC';
    }
    if ($meta['checkout_date']) {
        $orderParts[] = 'tc.' . quoteIdentifier($meta['checkout_date']) . ' ASC';
    }
    $orderSql = empty($orderParts) ? '' : (' ORDER BY ' . implode(', ', $orderParts));

    $sql = "
        SELECT " . implode(', ', $selectCols) . "
        FROM tbl_checkedout_items tc
        WHERE " . implode(' AND ', $where) . "
        {$orderSql}
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        sendResponse('error', 'Reference number not found.', null, 404);
    }

    $barcodes = [];
    foreach ($rows as $row) {
        $barcode = normalizeString($row['barcode'] ?? '');
        if ($barcode !== '') {
            $barcodes[] = $barcode;
        }
    }
    $itemLookup = fetchItemNameLookup($conn, $barcodes, $requestedWorkArea);

    $items = [];
    $totalQuantity = 0;
    $activeQuantity = 0;
    $activeRows = 0;
    $voidedRows = 0;
    $kind = 'CHECKOUT';
    $status = 'ACTIVE';

    $summaryCategory = '';
    $summaryDestination = '';
    $summaryBarangay = '';
    $summaryBy = '';
    $summaryDate = '';

    foreach ($rows as $row) {
        $barcode = normalizeString($row['barcode'] ?? '');
        $quantity = (int)($row['quantity'] ?? 0);
        $isDeleted = (int)($row['is_deleted'] ?? 0);
        $category = strtoupper(normalizeString($row['category'] ?? ''));
        $destination = normalizeString($row['destination'] ?? '');
        $transactionType = normalizeString($row['transaction_type'] ?? '');

        $transactionDate = normalizeString($row['transfer_date'] ?? '');
        if ($transactionDate === '') {
            $transactionDate = normalizeString($row['checkout_date'] ?? '');
        }
        $performedBy = normalizeString($row['transfer_by'] ?? '');
        if ($performedBy === '') {
            $performedBy = normalizeString($row['checkout_by'] ?? '');
        }

        $itemName = '';
        $description = '';
        if ($barcode !== '' && isset($itemLookup[$barcode])) {
            $itemName = normalizeString($itemLookup[$barcode]['item_name'] ?? '');
            $description = normalizeString($itemLookup[$barcode]['description'] ?? '');
        }

        if ($itemName === '') {
            $itemName = $barcode;
        }

        $lineKind = detectTransactionKind($referenceNo, $transactionType, $destination);
        if ($lineKind === 'TRANSFER' || $lineKind === 'ADJUSTMENT') {
            $kind = $lineKind;
        }

        if ($summaryCategory === '' && $category !== '') {
            $summaryCategory = $category;
        }
        if ($summaryDestination === '' && $destination !== '') {
            $summaryDestination = $destination;
        }
        if ($summaryBarangay === '' && normalizeString($row['barangay'] ?? '') !== '') {
            $summaryBarangay = normalizeString($row['barangay'] ?? '');
        }
        if ($summaryBy === '' && $performedBy !== '') {
            $summaryBy = $performedBy;
        }
        if ($summaryDate === '' && $transactionDate !== '') {
            $summaryDate = formatDateTimeValue($transactionDate);
        }

        $totalQuantity += $quantity;
        if ($isDeleted === 0) {
            $activeRows++;
            $activeQuantity += $quantity;
        } else {
            $voidedRows++;
        }

        $items[] = [
            'row_id' => (int)($row['row_id'] ?? 0),
            'barcode' => $barcode,
            'item_name' => $itemName,
            'description' => $description,
            'quantity' => $quantity,
            'category' => $category,
            'destination' => $destination,
            'barangay' => normalizeString($row['barangay'] ?? ''),
            'transaction_date' => formatDateTimeValue($transactionDate),
            'performed_by' => $performedBy,
            'status' => ($isDeleted === 0 ? 'ACTIVE' : 'VOIDED'),
            'remarks' => normalizeString($row['remarks'] ?? '')
        ];
    }

    if ($meta['is_deleted'] && $activeRows === 0) {
        $status = 'VOIDED';
    }

    $data = [
        'reference_no' => $referenceNo,
        'transaction_kind' => $kind,
        'status' => $status,
        'category' => $summaryCategory,
        'destination' => $summaryDestination,
        'barangay' => $summaryBarangay,
        'performed_by' => $summaryBy,
        'transaction_date' => $summaryDate,
        'work_area' => $requestedWorkArea,
        'total_rows' => count($items),
        'active_rows' => $activeRows,
        'voided_rows' => $voidedRows,
        'total_quantity' => $totalQuantity,
        'active_quantity' => $activeQuantity,
        'items' => $items,
        'audit_logs' => buildReferenceLogRows($conn, $referenceNo)
    ];

    sendResponse('success', 'Transaction details retrieved.', $data);
}

function handleToggleVoidAction($conn, $userData, $meta, $requestedWorkArea, $payload, $targetDeleted) {
    if (!$meta['is_deleted']) {
        sendResponse('error', 'Void/undo requires isDeleted column in checkout table.', null, 500);
    }

    $referenceNo = normalizeString($payload['reference_no'] ?? '');
    if ($referenceNo === '') {
        sendResponse('error', 'Reference number is required.', null, 400);
    }

    $reason = normalizeString($payload['reason'] ?? '');
    if ($reason === '') {
        sendResponse('error', 'A reason is required to ' . ($targetDeleted === 1 ? 'void' : 'undo') . ' a transaction.', null, 400);
    }
    $currentDeleted = $targetDeleted === 1 ? 0 : 1;

    $where = ['tc.' . quoteIdentifier($meta['reference_no']) . ' = :reference_no'];
    $params = [':reference_no' => $referenceNo];

    if ($meta['work_area'] && !isAllWorkArea($requestedWorkArea)) {
        $where[] = "UPPER(COALESCE(NULLIF(TRIM(tc." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = :work_area";
        $params[':work_area'] = $requestedWorkArea;
    }

    $selectCols = [
        'tc.' . quoteIdentifier($meta['reference_no']) . ' AS reference_no',
        'tc.' . quoteIdentifier($meta['barcode']) . ' AS barcode',
        'tc.' . quoteIdentifier($meta['quantity']) . ' AS quantity',
        'tc.' . quoteIdentifier($meta['is_deleted']) . ' AS is_deleted'
    ];
    $selectCols[] = $meta['category'] ? ('tc.' . quoteIdentifier($meta['category']) . ' AS category') : "'' AS category";
    $selectCols[] = $meta['destination'] ? ('tc.' . quoteIdentifier($meta['destination']) . ' AS destination') : "'' AS destination";
    $selectCols[] = $meta['transaction_type'] ? ('tc.' . quoteIdentifier($meta['transaction_type']) . ' AS transaction_type') : "'' AS transaction_type";
    $selectCols[] = $meta['remarks'] ? ('tc.' . quoteIdentifier($meta['remarks']) . ' AS remarks') : "'' AS remarks";
    $selectCols[] = $meta['work_area'] ? ("UPPER(COALESCE(NULLIF(TRIM(tc." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) AS work_area") : "'CHO' AS work_area";

    $sql = "
        SELECT " . implode(', ', $selectCols) . "
        FROM tbl_checkedout_items tc
        WHERE " . implode(' AND ', $where) . "
        FOR UPDATE
    ";

    $inventoryMetas = buildInventoryMetas($conn);

    if (!acquireStockLock($conn)) {
        sendResponse('error', 'Another stock transaction is in progress. Please try again.', null, 503);
    }

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            throw new Exception('Reference number not found.');
        }

        $rowsToChange = [];
        foreach ($rows as $row) {
            if ((int)($row['is_deleted'] ?? 0) === $currentDeleted) {
                $rowsToChange[] = $row;
            }
        }

        if (empty($rowsToChange)) {
            if ($targetDeleted === 1) {
                throw new Exception('Transaction is already voided.');
            }
            throw new Exception('Transaction is already active.');
        }

        // Restoring a voided transaction takes the units out of stock again, so they must still be there.
        if ($targetDeleted === 0) {
            $restoreNeeds = [];
            foreach ($rowsToChange as $row) {
                $barcode = normalizeString($row['barcode'] ?? '');
                if ($barcode === '') {
                    continue;
                }
                $key = $barcode . '|' . $row['work_area'];
                if (!isset($restoreNeeds[$key])) {
                    $restoreNeeds[$key] = ['barcode' => $barcode, 'work_area' => $row['work_area'], 'quantity' => 0];
                }
                $restoreNeeds[$key]['quantity'] += (int)($row['quantity'] ?? 0);
            }
            foreach ($restoreNeeds as $need) {
                $available = getAvailableStock($conn, $inventoryMetas, $meta, $need['barcode'], $need['work_area']);
                if ($need['quantity'] > $available) {
                    throw new Exception(
                        "Cannot restore: item {$need['barcode']} needs {$need['quantity']} but only "
                        . max(0, $available) . " is available in {$need['work_area']}."
                    );
                }
            }
        }

        // Voiding a stock count surplus (negative ledger quantity) takes those units back out.
        if ($targetDeleted === 1) {
            foreach ($rowsToChange as $row) {
                $quantity = (int)($row['quantity'] ?? 0);
                $barcode = normalizeString($row['barcode'] ?? '');
                if ($quantity >= 0 || $barcode === '') {
                    continue;
                }
                $available = getAvailableStock($conn, $inventoryMetas, $meta, $barcode, $row['work_area']);
                if (-$quantity > $available) {
                    throw new Exception(
                        "Cannot void: the surplus of {$barcode} has already been released; only "
                        . max(0, $available) . " remain in {$row['work_area']}."
                    );
                }
            }
        }

        $warnings = [];
        $adjustments = [];
        $kind = 'CHECKOUT';
        $category = '';
        $totalQuantity = 0;

        foreach ($rowsToChange as $row) {
            $barcode = normalizeString($row['barcode'] ?? '');
            $quantity = (int)($row['quantity'] ?? 0);
            $destination = normalizeString($row['destination'] ?? '');
            $transactionType = normalizeString($row['transaction_type'] ?? '');
            $rowCategory = strtoupper(normalizeString($row['category'] ?? ''));

            $lineKind = detectTransactionKind($referenceNo, $transactionType, $destination);
            if ($lineKind === 'TRANSFER') {
                $kind = 'TRANSFER';
            }
            if ($category === '' && $rowCategory !== '') {
                $category = $rowCategory;
            }
            $totalQuantity += $quantity;

            if ($lineKind !== 'TRANSFER') {
                continue;
            }

            $destinationWorkArea = resolveDestinationWorkArea($rowCategory ?: $transactionType, $destination, '');
            if ($destinationWorkArea === '' || $barcode === '' || $quantity <= 0) {
                continue;
            }

            $key = $barcode . '|' . $destinationWorkArea;
            if (!isset($adjustments[$key])) {
                $adjustments[$key] = [
                    'barcode' => $barcode,
                    'quantity' => 0,
                    'destination_work_area' => $destinationWorkArea,
                    'remarks' => normalizeString($row['remarks'] ?? '')
                ];
            }
            $adjustments[$key]['quantity'] += $quantity;
        }

        // Voiding a transfer takes the units back out of the destination, so the destination
        // must not have dispensed them already.
        if ($targetDeleted === 1) {
            foreach ($adjustments as $adjustment) {
                $destinationAvailable = getAvailableStock(
                    $conn,
                    $inventoryMetas,
                    $meta,
                    $adjustment['barcode'],
                    $adjustment['destination_work_area']
                );
                if ($adjustment['quantity'] > $destinationAvailable) {
                    throw new Exception(
                        "Cannot void: {$adjustment['destination_work_area']} has only " . max(0, $destinationAvailable)
                        . " of item {$adjustment['barcode']} left, but this transfer sent {$adjustment['quantity']}. "
                        . "Void the destination's checkouts first."
                    );
                }
            }
        }

        if (!empty($adjustments)) {
            foreach ($adjustments as $adjustment) {
                adjustDestinationStockForTransfer(
                    $conn,
                    $inventoryMetas,
                    $adjustment['barcode'],
                    $adjustment['quantity'],
                    $adjustment['destination_work_area'],
                    ($targetDeleted === 1 ? 'decrease' : 'increase'),
                    normalizeString($userData['username'] ?? 'system'),
                    $reason !== '' ? $reason : $adjustment['remarks'],
                    $warnings
                );
            }
        }

        $updateSql = "
            UPDATE tbl_checkedout_items tc
            SET tc." . quoteIdentifier($meta['is_deleted']) . " = :target_deleted
            WHERE tc." . quoteIdentifier($meta['reference_no']) . " = :reference_no
        ";
        $updateParams = [
            ':target_deleted' => $targetDeleted,
            ':reference_no' => $referenceNo,
            ':current_deleted' => $currentDeleted
        ];

        if ($meta['work_area'] && !isAllWorkArea($requestedWorkArea)) {
            $updateSql .= " AND UPPER(COALESCE(NULLIF(TRIM(tc." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = :work_area";
            $updateParams[':work_area'] = $requestedWorkArea;
        }
        $updateSql .= " AND tc." . quoteIdentifier($meta['is_deleted']) . " = :current_deleted";

        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute($updateParams);
        $affectedRows = (int)$updateStmt->rowCount();

        if ($affectedRows <= 0) {
            throw new Exception('No rows were updated. Please retry.');
        }

        $actionName = ($targetDeleted === 1) ? 'VOID' : 'UNDO';
        logTransactionHistory($conn, [
            'module' => $kind,
            'action' => $actionName,
            'transaction_type' => $category,
            'category' => $category,
            'reference_no' => $referenceNo,
            'quantity' => $totalQuantity,
            'performed_by' => $userData['username'] ?? 'system',
            'location' => $userData['location'] ?? '',
            'work_area' => $requestedWorkArea,
            'details' => [
                'reason' => $reason,
                'changed_rows' => count($rowsToChange),
                'affected_rows' => $affectedRows,
                'transaction_kind' => $kind,
                'status_after' => ($targetDeleted === 1 ? 'VOIDED' : 'ACTIVE'),
                'warnings' => $warnings
            ]
        ]);

        $conn->commit();

        sendResponse('success', ($targetDeleted === 1 ? 'Transaction voided.' : 'Transaction restored.'), [
            'reference_no' => $referenceNo,
            'changed_rows' => count($rowsToChange),
            'affected_rows' => $affectedRows,
            'changed_quantity' => $totalQuantity,
            'transaction_kind' => $kind,
            'status' => ($targetDeleted === 1 ? 'VOIDED' : 'ACTIVE')
        ], 200, [
            'warnings' => $warnings
        ]);
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        sendResponse('error', $e->getMessage(), null, 400);
    }
}

function handleEditRisAction($conn, $userData, $meta, $requestedWorkArea, $payload) {
    $referenceNo = normalizeString($payload['reference_no'] ?? '');
    if ($referenceNo === '') {
        sendResponse('error', 'Reference number is required.', null, 400);
    }

    $itemsInput = $payload['items'] ?? null;
    if (!is_array($itemsInput) || empty($itemsInput)) {
        sendResponse('error', 'items array is required.', null, 400);
    }

    if (!$meta['id']) {
        sendResponse('error', 'Checkout table is missing row ID column.', null, 500);
    }

    foreach ($itemsInput as $item) {
        if ((int)($item['row_id'] ?? 0) <= 0) {
            sendResponse('error', 'Each item must have a valid row_id.', null, 400);
        }
        if ((int)($item['quantity'] ?? 0) < 1) {
            sendResponse('error', 'Quantity must be at least 1. Use void to remove an item.', null, 400);
        }
    }

    $remarks = normalizeString($payload['remarks'] ?? '');
    if ($remarks === '') {
        sendResponse('error', 'Remarks are required when editing RIS quantities.', null, 400);
    }

    $inventoryMetas = buildInventoryMetas($conn);
    $rowIds = array_map(fn($i) => (int)$i['row_id'], $itemsInput);
    $placeholders = implode(',', array_fill(0, count($rowIds), '?'));

    $fetchSelect = [
        'tc.' . quoteIdentifier($meta['id'])           . ' AS row_id',
        'tc.' . quoteIdentifier($meta['barcode'])      . ' AS barcode',
        'tc.' . quoteIdentifier($meta['quantity'])     . ' AS old_quantity',
        $meta['work_area']
            ? "UPPER(COALESCE(NULLIF(TRIM(tc." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) AS work_area"
            : "'CHO' AS work_area",
    ];
    $fetchWhere  = [
        'tc.' . quoteIdentifier($meta['id'])           . " IN ({$placeholders})",
        'tc.' . quoteIdentifier($meta['reference_no']) . ' = ?'
    ];
    $fetchParams = array_merge($rowIds, [$referenceNo]);

    if ($meta['is_deleted']) {
        $fetchWhere[] = 'tc.' . quoteIdentifier($meta['is_deleted']) . ' = 0';
    }
    if ($meta['work_area'] && !isAllWorkArea($requestedWorkArea)) {
        $fetchWhere[] = "UPPER(COALESCE(NULLIF(TRIM(tc." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = ?";
        $fetchParams[] = $requestedWorkArea;
    }
    if ($meta['transaction_type']) {
        $fetchWhere[] = "UPPER(COALESCE(NULLIF(TRIM(tc." . quoteIdentifier($meta['transaction_type']) . "), ''), '')) NOT IN ('TRANSFER', 'ADJUSTMENT')";
    }

    if (!acquireStockLock($conn)) {
        sendResponse('error', 'Another stock transaction is in progress. Please try again.', null, 503);
    }

    try {
        $conn->beginTransaction();

        $fetchSql = "SELECT " . implode(', ', $fetchSelect)
                  . " FROM tbl_checkedout_items tc"
                  . " WHERE " . implode(' AND ', $fetchWhere)
                  . " FOR UPDATE";
        $stmt = $conn->prepare($fetchSql);
        $stmt->execute($fetchParams);
        $currentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($currentRows)) {
            throw new Exception('No matching active checkout items found for this RIS.');
        }

        $currentByRowId = [];
        foreach ($currentRows as $cr) {
            $currentByRowId[(int)$cr['row_id']] = $cr;
        }

        $itemLookup = fetchItemNameLookup(
            $conn,
            array_map(fn($row) => normalizeString($row['barcode'] ?? ''), $currentRows),
            $requestedWorkArea
        );
        $warnings    = [];
        $totalChanged = 0;
        $updatedCount = 0;
        $itemChanges = [];

        foreach ($itemsInput as $item) {
            $rowId  = (int)$item['row_id'];
            $newQty = (int)$item['quantity'];

            if (!isset($currentByRowId[$rowId])) continue;

            $current = $currentByRowId[$rowId];
            $oldQty  = (int)$current['old_quantity'];
            $barcode = normalizeString($current['barcode'] ?? '');
            $delta   = $newQty - $oldQty;

            if ($delta === 0) continue;

            // Available stock is received quantity minus checked-out quantity, so changing the
            // checkout row is the whole adjustment. The inventory row keeps the received quantity.
            if ($delta > 0 && $barcode !== '' && !empty($inventoryMetas)) {
                $available = getAvailableStock($conn, $inventoryMetas, $meta, $barcode, $current['work_area']);
                if ($delta > $available) {
                    throw new Exception(
                        "Cannot increase item {$barcode} by {$delta}: only " . max(0, $available)
                        . " is available in {$current['work_area']}."
                    );
                }
            }

            $updStmt = $conn->prepare(
                "UPDATE tbl_checkedout_items"
                . " SET " . quoteIdentifier($meta['quantity']) . " = ?"
                . " WHERE " . quoteIdentifier($meta['id']) . " = ?"
                . "   AND " . quoteIdentifier($meta['reference_no']) . " = ?"
            );
            $updStmt->execute([$newQty, $rowId, $referenceNo]);

            $totalChanged += abs($delta);
            $updatedCount++;
            $itemChanges[] = [
                'row_id' => $rowId,
                'barcode' => $barcode,
                'item_name' => normalizeString($itemLookup[$barcode]['item_name'] ?? ''),
                'description' => normalizeString($itemLookup[$barcode]['description'] ?? ''),
                'old_quantity' => $oldQty,
                'new_quantity' => $newQty,
                'delta' => $delta
            ];
        }

        if ($updatedCount === 0) {
            throw new Exception('No quantity changes detected.');
        }

        logTransactionHistory($conn, [
            'module'           => 'CHECKOUT',
            'action'           => 'EDIT_RIS_QTY',
            'transaction_type' => 'CHECKOUT',
            'category'         => '',
            'reference_no'     => $referenceNo,
            'quantity'         => $totalChanged,
            'performed_by'     => $userData['username'] ?? 'system',
            'location'         => $userData['location'] ?? '',
            'work_area'        => $requestedWorkArea,
            'details'          => [
                'items_updated' => $updatedCount,
                'remarks'       => $remarks,
                'items'         => $itemChanges,
                'warnings'      => $warnings
            ]
        ]);

        $conn->commit();

        sendResponse('success', 'RIS quantities updated.', [
            'reference_no'  => $referenceNo,
            'items_updated' => $updatedCount
        ], 200, ['warnings' => $warnings]);

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        sendResponse('error', $e->getMessage(), null, 400);
    }
}

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}

if (!hasHistoryAccess($userData)) {
    sendResponse('error', 'You are not allowed to access transaction history.', null, 403);
}

$meta = buildCheckedoutMeta($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = normalizeString($_GET['action'] ?? 'list');
    $requestedWorkArea = resolveRequestedWorkArea($userData, $_GET['work_area'] ?? '');

    if ($action === 'list') {
        handleListAction($conn, $meta, $requestedWorkArea);
    }
    if ($action === 'details') {
        handleDetailsAction($conn, $meta, $requestedWorkArea);
    }

    sendResponse('error', 'Unknown action.', null, 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Invalid request method.', null, 405);
}

if (!apiUserCan($userData, 'transaction_admin')) {
    sendResponse('error', 'Only Administrators can void, undo or edit transactions.', null, 403);
}

$payload = readPayload();
$action = normalizeString($payload['action'] ?? '');
$requestedWorkArea = resolveRequestedWorkArea($userData, $payload['work_area'] ?? '');

if ($action === 'void_transaction') {
    handleToggleVoidAction($conn, $userData, $meta, $requestedWorkArea, $payload, 1);
}
if ($action === 'undo_transaction') {
    handleToggleVoidAction($conn, $userData, $meta, $requestedWorkArea, $payload, 0);
}
if ($action === 'edit_ris') {
    handleEditRisAction($conn, $userData, $meta, $requestedWorkArea, $payload);
}

sendResponse('error', 'Unknown action.', null, 400);
?>
