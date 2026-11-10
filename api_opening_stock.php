<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';
require_once 'transaction_logger.php';
require_once __DIR__ . '/stock_workflow_helpers.php';

/**
 * Opening stock: Aglayan / UPLC encode the stock they already had on hand, which was NOT
 * distributed by CHO through EMedTrack. Each entry is a normal batch row in the item table
 * (so dispensing, counts and stock cards just work) tagged Stock_Source = 'OPENING', with
 * its own OB- batch number and an automatic remark saying it was entered by hand.
 *
 * GET  ?action=list&work_area=AGLAYAN|UPLC|ALL[&category=medicine|...]
 *      ALL is the CHO view across both clinics (Stocks Distribution access), read-only.
 * GET  ?action=catalog&category=...&search=...
 *      Masterlist items to choose from.
 * POST {action: 'create', work_area, as_of_date, lines: [{category, masterlist_id, quantity,
 *       expiry_date, batch_number, lot_number, unit_cost, remarks}]}
 *      Records every line in one transaction (all or nothing).
 * POST {action: 'void', work_area, category, item_id, reason}
 *      Removes a mistaken entry. Only allowed while none of it has been dispensed,
 *      transferred or adjusted; after that, correct it with a physical count.
 */

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

function readPayload() {
    $rawInput = file_get_contents("php://input");
    if ($rawInput !== '') {
        $json = json_decode($rawInput, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
        sendResponse('error', 'Invalid JSON format.', null, 400);
    }
    return $_POST;
}

function normalizeOpeningWorkArea($value) {
    $value = strtoupper(trim((string)$value));
    return preg_replace('/[^A-Z0-9 _-]/', '', $value);
}

function normalizeOpeningDate($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', substr($value, 0, 10));
    return $date ? $date->format('Y-m-d') : false;
}

/**
 * View: CHO monitors both clinics (ALL); a clinic sees its own.
 * Write: users assigned to that clinic (or an administrator working in it) who can change
 * stock. COA / Accounting stay read-only; permits staff never reach EMedTrack areas.
 */
function canUseOpeningStockWorkArea($userData, $workArea, $forWrite) {
    if ($workArea === 'ALL') {
        return !$forWrite && apiUserCan($userData, 'clinics');
    }
    if (!in_array($workArea, OPENING_STOCK_WORK_AREAS, true) || !userCanAccessWorkArea($userData, $workArea)) {
        return false;
    }
    $roleKey = strtolower(trim((string)($userData['role'] ?? '')));
    $handlesStock = apiUserCan($userData, 'checkout', $workArea)
        || apiUserCan($userData, 'inventory_view', $workArea)
        || apiUserCan($userData, 'clinics')
        || (isPharmacistRoleKey($roleKey) && userHasModuleAccess($userData, 'subsystem_emedtrack'));
    if ($forWrite) {
        return $handlesStock && userCanWrite($userData);
    }
    return $handlesStock;
}

function openingCategoryMasterDeletedColumn($categoryKey) {
    return $categoryKey === 'lab_reagents' ? 'isDeleted' : 'isdeleted';
}

/**
 * Optional item-table columns differ between older deployments; report which exist.
 */
function openingItemHasColumn($conn, $table, $column) {
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = [];
        foreach ($conn->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $cache[$table][strtolower($col['Field'])] = true;
        }
    }
    return isset($cache[$table][strtolower($column)]);
}

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}
if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}
if (!ensureStockSourceColumns($conn)) {
    sendResponse('error', 'Opening stock is unavailable: the Stock_Source column could not be added. Run API/cloud_update_latest.sql.', null, 500);
}

$categories = getStockCountCategories();
$username = (string)($userData['username'] ?? 'system');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim((string)($_GET['action'] ?? 'list'));
    $workArea = normalizeOpeningWorkArea($_GET['work_area'] ?? ($userData['location'] ?? ''));
    if (!canUseOpeningStockWorkArea($userData, $workArea, false)) {
        sendResponse('error', 'You are not allowed to view opening stock for this location.', null, 403);
    }

    if ($action === 'catalog') {
        $categoryKey = (string)($_GET['category'] ?? '');
        if (!isset($categories[$categoryKey])) {
            sendResponse('error', 'Invalid category.', null, 400);
        }
        $search = trim((string)($_GET['search'] ?? ''));
        $master = $categories[$categoryKey]['masterlist'];
        $deletedCol = openingCategoryMasterDeletedColumn($categoryKey);
        $sql = "SELECT ID, Item, Description, Entity FROM {$master} WHERE COALESCE({$deletedCol}, 0) = 0";
        $params = [];
        if ($search !== '') {
            $sql .= " AND (Item LIKE :search OR Description LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY Item ASC, Description ASC LIMIT 50";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        sendResponse('success', 'Catalog retrieved.', $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($action !== 'list') {
        sendResponse('error', 'Unknown action.', null, 400);
    }

    $categoryFilter = (string)($_GET['category'] ?? '');
    $areas = $workArea === 'ALL' ? OPENING_STOCK_WORK_AREAS : [$workArea];
    $areaPlaceholders = implode(', ', array_fill(0, count($areas), '?'));
    $canWrite = $workArea !== 'ALL' && canUseOpeningStockWorkArea($userData, $workArea, true);

    $rows = [];
    foreach ($categories as $categoryKey => $category) {
        if ($categoryFilter !== '' && $categoryFilter !== $categoryKey) {
            continue;
        }
        $table = $category['table'];
        $batchSelect = openingItemHasColumn($conn, $table, 'Batch_Number') ? 'im.Batch_Number' : "''";
        $lotSelect = openingItemHasColumn($conn, $table, 'Lot_Number') ? 'im.Lot_Number' : "''";
        // Ledger usage is matched per work area, the same rule checkout and counts use.
        $stmt = $conn->prepare("
            SELECT
                im.IID, im.Barcode_Number, im.Item, im.Description, im.Quantity, im.Unit_Cost,
                im.Expiry_Date, im.Date_Added, im.Added_By, im.Remarks,
                {$batchSelect} AS Batch_Number, {$lotSelect} AS Lot_Number,
                UPPER(COALESCE(NULLIF(TRIM(im.Work_Area), ''), 'CHO')) AS Work_Area,
                (
                    SELECT COALESCE(SUM(tc.Quantity), 0)
                    FROM tbl_checkedout_items tc
                    WHERE tc.Barcode = im.Barcode_Number
                      AND COALESCE(tc.isDeleted, 0) = 0
                      AND UPPER(COALESCE(NULLIF(TRIM(tc.Work_Area), ''), 'CHO')) = UPPER(COALESCE(NULLIF(TRIM(im.Work_Area), ''), 'CHO'))
                ) AS Used_Quantity,
                (
                    SELECT COUNT(*)
                    FROM tbl_checkedout_items tc
                    WHERE tc.Barcode = im.Barcode_Number
                      AND COALESCE(tc.isDeleted, 0) = 0
                      AND UPPER(COALESCE(NULLIF(TRIM(tc.Work_Area), ''), 'CHO')) = UPPER(COALESCE(NULLIF(TRIM(im.Work_Area), ''), 'CHO'))
                ) AS Ledger_Rows
            FROM {$table} im
            WHERE COALESCE(im.isDeleted, 0) = 0
              AND im.Stock_Source = ?
              AND UPPER(COALESCE(NULLIF(TRIM(im.Work_Area), ''), 'CHO')) IN ({$areaPlaceholders})
            ORDER BY im.Date_Added DESC, im.IID DESC
            LIMIT 2000
        ");
        $stmt->execute(array_merge([STOCK_SOURCE_OPENING], $areas));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $quantity = (int)$row['Quantity'];
            $used = (int)$row['Used_Quantity'];
            $rows[] = [
                'item_id' => (int)$row['IID'],
                'category' => $categoryKey,
                'category_label' => $category['label'],
                'work_area' => $row['Work_Area'],
                'barcode' => (string)$row['Barcode_Number'],
                'item_name' => (string)$row['Item'],
                'description' => (string)$row['Description'],
                'batch_number' => (string)($row['Batch_Number'] ?? ''),
                'lot_number' => (string)($row['Lot_Number'] ?? ''),
                'quantity' => $quantity,
                'used_quantity' => $used,
                'available_quantity' => $quantity - $used,
                'unit_cost' => $row['Unit_Cost'] !== null ? (float)$row['Unit_Cost'] : null,
                'expiry_date' => (string)($row['Expiry_Date'] ?? ''),
                'as_of_date' => (string)($row['Date_Added'] ?? ''),
                'added_by' => (string)($row['Added_By'] ?? ''),
                'remarks' => (string)($row['Remarks'] ?? ''),
                'can_void' => $canWrite && (int)$row['Ledger_Rows'] === 0
            ];
        }
    }

    usort($rows, function ($a, $b) {
        return [$b['as_of_date'], $b['item_id']] <=> [$a['as_of_date'], $a['item_id']];
    });
    sendResponse('success', 'Opening stock retrieved.', $rows, 200, ['can_write' => $canWrite]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Method not allowed.', null, 405);
}

$payload = readPayload();
$action = trim((string)($payload['action'] ?? ''));
$workArea = normalizeOpeningWorkArea($payload['work_area'] ?? '');
if (!canUseOpeningStockWorkArea($userData, $workArea, true)) {
    sendResponse('error', 'Only staff assigned to Aglayan or UPLC can record opening stock for their facility.', null, 403);
}
$facilityLabel = $workArea === 'AGLAYAN' ? 'Aglayan' : $workArea;

if ($action === 'create') {
    $lines = $payload['lines'] ?? [];
    if (!is_array($lines) || empty($lines)) {
        sendResponse('error', 'Add at least one item.', null, 400);
    }
    if (count($lines) > 200) {
        sendResponse('error', 'Save at most 200 items at a time.', null, 400);
    }

    $today = date('Y-m-d');
    $asOfDate = normalizeOpeningDate($payload['as_of_date'] ?? '');
    if ($asOfDate === false) {
        sendResponse('error', 'The count date is not a valid date.', null, 400);
    }
    $asOfDate = $asOfDate ?? $today;
    if ($asOfDate > $today) {
        sendResponse('error', 'The count date cannot be in the future.', null, 400);
    }
    $dateAdded = $asOfDate === $today ? date('Y-m-d H:i:s') : ($asOfDate . ' 00:00:00');

    // Validate every line before touching the database.
    $prepared = [];
    $masterStmts = [];
    foreach (array_values($lines) as $index => $line) {
        $lineNo = $index + 1;
        $categoryKey = (string)($line['category'] ?? '');
        if (!isset($categories[$categoryKey])) {
            sendResponse('error', "Line {$lineNo}: choose a category.", null, 400);
        }
        $masterId = (int)($line['masterlist_id'] ?? 0);
        $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($masterId <= 0) {
            sendResponse('error', "Line {$lineNo}: choose an item from the catalog.", null, 400);
        }
        if ($quantity === false || $quantity <= 0) {
            sendResponse('error', "Line {$lineNo}: quantity must be a whole number greater than 0.", null, 400);
        }
        $expiry = normalizeOpeningDate($line['expiry_date'] ?? '');
        if ($expiry === false) {
            sendResponse('error', "Line {$lineNo}: the expiry date is not valid.", null, 400);
        }
        if ($expiry === null && $categoryKey !== 'medical_supplies') {
            sendResponse('error', "Line {$lineNo}: expiry date is required for {$categories[$categoryKey]['label']}.", null, 400);
        }
        $unitCostRaw = trim((string)($line['unit_cost'] ?? ''));
        if ($unitCostRaw !== '' && (!is_numeric($unitCostRaw) || (float)$unitCostRaw < 0)) {
            sendResponse('error', "Line {$lineNo}: unit cost must be a number of 0 or more.", null, 400);
        }

        if (!isset($masterStmts[$categoryKey])) {
            $master = $categories[$categoryKey]['masterlist'];
            $deletedCol = openingCategoryMasterDeletedColumn($categoryKey);
            $masterStmts[$categoryKey] = $conn->prepare("SELECT ID, Item, Description, Entity FROM {$master} WHERE ID = ? AND COALESCE({$deletedCol}, 0) = 0");
        }
        $masterStmts[$categoryKey]->execute([$masterId]);
        $masterRow = $masterStmts[$categoryKey]->fetch(PDO::FETCH_ASSOC);
        if (!$masterRow) {
            sendResponse('error', "Line {$lineNo}: the selected item is no longer in the catalog.", null, 404);
        }

        $note = trim((string)($line['remarks'] ?? ''));
        $remarks = "OPENING STOCK - entered by hand at {$facilityLabel}; not distributed by CHO.";
        if ($note !== '') {
            $remarks .= ' ' . $note;
        }

        $prepared[] = [
            'category' => $categoryKey,
            'master' => $masterRow,
            'quantity' => (int)$quantity,
            'expiry' => $expiry !== null ? ($expiry . ' 00:00:00') : null,
            'unit_cost' => $unitCostRaw === '' ? 0 : round((float)$unitCostRaw, 3),
            'batch_number' => mb_substr(trim((string)($line['batch_number'] ?? '')), 0, 100),
            'lot_number' => mb_substr(trim((string)($line['lot_number'] ?? '')), 0, 100),
            'remarks' => mb_substr($remarks, 0, 255)
        ];
    }

    if (!acquireStockLock($conn)) {
        sendResponse('error', 'Another stock transaction is in progress. Please try again.', null, 503);
    }

    $saved = [];
    try {
        $conn->beginTransaction();
        foreach ($prepared as $line) {
            $table = $categories[$line['category']]['table'];
            $barcodeNumber = generateOpeningStockBarcode($conn, $table, $workArea);
            $values = [
                'ID' => $line['master']['ID'],
                'Barcode' => $barcodeNumber,
                'Barcode_Number' => $barcodeNumber,
                'Item' => $line['master']['Item'],
                'Description' => $line['master']['Description'],
                'Entity' => $line['master']['Entity'],
                'Unit_Cost' => $line['unit_cost'],
                'Quantity' => $line['quantity'],
                'Expiry_Date' => $line['expiry'],
                'Date_Added' => $dateAdded,
                'Added_By' => $username,
                'Remarks' => $line['remarks'],
                'Donated' => 0,
                'PO_Number' => '',
                'isDeleted' => 0,
                'Work_Area' => $workArea,
                'Stock_Source' => STOCK_SOURCE_OPENING
            ];
            foreach (['Batch_Number' => $line['batch_number'], 'Lot_Number' => $line['lot_number'], 'Contract_Number' => ''] as $column => $value) {
                if (openingItemHasColumn($conn, $table, $column)) {
                    $values[$column] = $value;
                }
            }
            $stmt = $conn->prepare(
                "INSERT INTO {$table} (" . implode(', ', array_keys($values)) . ")"
                . " VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ")"
            );
            $stmt->execute(array_values($values));
            $itemId = (int)$conn->lastInsertId();

            logTransactionHistory($conn, [
                'module' => 'INVENTORY',
                'action' => 'CREATE',
                'transaction_type' => 'OPENING_STOCK',
                'category' => $line['category'],
                'reference_no' => $barcodeNumber,
                'item_barcode' => $barcodeNumber,
                'item_name' => $line['master']['Item'] ?? '',
                'quantity' => $line['quantity'],
                'performed_by' => $username,
                'location' => $userData['location'] ?? '',
                'work_area' => $workArea,
                'details' => [
                    'stock_source' => STOCK_SOURCE_OPENING,
                    'item_id' => $itemId,
                    'masterlist_id' => $line['master']['ID'] ?? null,
                    'as_of_date' => $asOfDate,
                    'expiry_date' => $line['expiry'],
                    'unit_cost' => $line['unit_cost'],
                    'batch_number' => $line['batch_number'],
                    'lot_number' => $line['lot_number'],
                    'remarks' => $line['remarks']
                ]
            ]);

            $saved[] = ['item_id' => $itemId, 'category' => $line['category'], 'barcode' => $barcodeNumber, 'item_name' => $line['master']['Item']];
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Opening stock create failed: ' . $e->getMessage());
        sendResponse('error', 'Unable to save opening stock: ' . $e->getMessage(), null, 500);
    }

    $count = count($saved);
    sendResponse('success', $count === 1 ? 'Opening stock saved for 1 item.' : "Opening stock saved for {$count} items.", $saved);
}

if ($action === 'void') {
    $categoryKey = (string)($payload['category'] ?? '');
    $itemId = (int)($payload['item_id'] ?? 0);
    $reason = trim((string)($payload['reason'] ?? ''));
    if (!isset($categories[$categoryKey]) || $itemId <= 0) {
        sendResponse('error', 'Invalid opening stock entry.', null, 400);
    }
    if ($reason === '') {
        sendResponse('error', 'Give a reason for removing this entry.', null, 400);
    }
    $table = $categories[$categoryKey]['table'];

    if (!acquireStockLock($conn)) {
        sendResponse('error', 'Another stock transaction is in progress. Please try again.', null, 503);
    }

    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("
            SELECT IID, Barcode_Number, Item, Quantity, Remarks
            FROM {$table}
            WHERE IID = ? AND COALESCE(isDeleted, 0) = 0 AND Stock_Source = ?
              AND UPPER(COALESCE(NULLIF(TRIM(Work_Area), ''), 'CHO')) = ?
            FOR UPDATE
        ");
        $stmt->execute([$itemId, STOCK_SOURCE_OPENING, $workArea]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $conn->rollBack();
            sendResponse('error', 'Opening stock entry not found for this facility.', null, 404);
        }

        $ledger = $conn->prepare("
            SELECT COUNT(*) FROM tbl_checkedout_items
            WHERE Barcode = ? AND COALESCE(isDeleted, 0) = 0
              AND UPPER(COALESCE(NULLIF(TRIM(Work_Area), ''), 'CHO')) = ?
        ");
        $ledger->execute([$row['Barcode_Number'], $workArea]);
        if ((int)$ledger->fetchColumn() > 0) {
            $conn->rollBack();
            sendResponse('error', 'Part of this batch has already been dispensed, transferred or counted. Correct it with a physical stock count instead.', null, 409);
        }

        $remarks = mb_substr(trim((string)$row['Remarks']) . ' | REMOVED by ' . $username . ': ' . $reason, 0, 255);
        $conn->prepare("UPDATE {$table} SET isDeleted = 1, Remarks = ? WHERE IID = ?")->execute([$remarks, $itemId]);

        logTransactionHistory($conn, [
            'module' => 'INVENTORY',
            'action' => 'DELETE',
            'transaction_type' => 'OPENING_STOCK_VOID',
            'category' => $categoryKey,
            'reference_no' => (string)$row['Barcode_Number'],
            'item_barcode' => (string)$row['Barcode_Number'],
            'item_name' => (string)$row['Item'],
            'quantity' => (int)$row['Quantity'],
            'performed_by' => $username,
            'location' => $userData['location'] ?? '',
            'work_area' => $workArea,
            'details' => ['stock_source' => STOCK_SOURCE_OPENING, 'item_id' => $itemId, 'reason' => $reason]
        ]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Opening stock void failed: ' . $e->getMessage());
        sendResponse('error', 'Unable to remove the entry: ' . $e->getMessage(), null, 500);
    }

    sendResponse('success', 'Opening stock entry removed.');
}

sendResponse('error', 'Unknown action.', null, 400);
