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

function getTableColumns($conn, $table) {
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

function pickColumn($columns, $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function sqlId($identifier) {
    return '`' . str_replace('`', '``', (string)$identifier) . '`';
}

function normalizeWorkArea($value, $default = 'CHO') {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return $default;
    }
    $value = preg_replace('/[^A-Z0-9 _-]/', '', $value);
    return $value !== '' ? $value : $default;
}

function buildWorkAreaExpr($alias, $column) {
    if (!$column) {
        return "'CHO'";
    }
    return "UPPER(COALESCE(NULLIF(TRIM({$alias}." . sqlId($column) . "), ''), 'CHO'))";
}

function buildDeletedFilter($alias, $column) {
    if (!$column) {
        return '1=1';
    }
    return "{$alias}." . sqlId($column) . " = 0";
}

function loadNegativeInventoryRows($conn, $requestedWorkArea, $categoryFilter, $search) {
    $categories = [
        'medicine' => ['label' => 'Medicine', 'table' => 'tbl_item_medicine'],
        'medical_supplies' => ['label' => 'Medical Supplies', 'table' => 'tbl_item_medical_supplies'],
        'vaccines' => ['label' => 'Vaccines', 'table' => 'tbl_item_vaccines'],
        'lab_reagents' => ['label' => 'Lab Reagents', 'table' => 'tbl_item_lab_reagents']
    ];

    if ($categoryFilter !== '' && $categoryFilter !== 'all') {
        if (!isset($categories[$categoryFilter])) {
            sendResponse('error', 'Invalid category.', null, 400);
        }
        $categories = [$categoryFilter => $categories[$categoryFilter]];
    }

    $checkedoutColumns = getTableColumns($conn, 'tbl_checkedout_items');
    $checkedoutDeleted = pickColumn($checkedoutColumns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
    $checkedoutWorkArea = pickColumn($checkedoutColumns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
    $checkedoutCheckoutDate = pickColumn($checkedoutColumns, ['Checkout_Date', 'checkout_date']);
    $checkedoutBarcode = pickColumn($checkedoutColumns, ['Barcode', 'barcode']);
    $checkedoutQuantity = pickColumn($checkedoutColumns, ['Quantity', 'quantity']);

    if (!$checkedoutBarcode || !$checkedoutQuantity) {
        sendResponse('error', 'Checkout table is missing barcode or quantity columns.', null, 500);
    }

    $rows = [];
    foreach ($categories as $key => $category) {
        $table = $category['table'];
        $columns = getTableColumns($conn, $table);
        $barcodeCol = pickColumn($columns, ['Barcode_Number', 'barcode_number', 'Barcode', 'barcode']);
        $quantityCol = pickColumn($columns, ['Quantity', 'quantity']);
        if (!$barcodeCol || !$quantityCol) {
            continue;
        }

        $itemCol = pickColumn($columns, ['Item', 'item']);
        $descCol = pickColumn($columns, ['Description', 'description']);
        $entityCol = pickColumn($columns, ['Entity', 'entity']);
        $expiryCol = pickColumn($columns, ['Expiry_Date', 'expiry_date']);
        $dateAddedCol = pickColumn($columns, ['Date_Added', 'date_added']);
        $deletedCol = pickColumn($columns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
        $workAreaCol = pickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
        $itemWorkAreaExpr = buildWorkAreaExpr('im', $workAreaCol);
        $checkoutWorkAreaExpr = buildWorkAreaExpr('co', $checkedoutWorkArea);
        $itemFilter = buildDeletedFilter('im', $deletedCol);
        $checkoutFilter = buildDeletedFilter('co', $checkedoutDeleted);

        $itemSelect = $itemCol ? "MAX(im." . sqlId($itemCol) . ")" : "''";
        $descSelect = $descCol ? "MAX(im." . sqlId($descCol) . ")" : "''";
        $entitySelect = $entityCol ? "MAX(im." . sqlId($entityCol) . ")" : "''";
        $expirySelect = $expiryCol ? "MIN(im." . sqlId($expiryCol) . ")" : "NULL";
        $dateAddedSelect = $dateAddedCol ? "MAX(im." . sqlId($dateAddedCol) . ")" : "NULL";
        $latestCheckoutSelect = $checkedoutCheckoutDate ? "MAX(co." . sqlId($checkedoutCheckoutDate) . ")" : "NULL";

        $sql = "
            SELECT *
            FROM (
                SELECT
                    :category_key AS category_key,
                    :category_label AS category_label,
                    stock.barcode AS barcode,
                    stock.work_area AS work_area,
                    stock.item_name AS item_name,
                    stock.description AS description,
                    stock.entity AS entity,
                    stock.expiry_date AS earliest_expiry,
                    stock.latest_stock_date AS latest_stock_date,
                    COALESCE(stock.stock_quantity, 0) AS stock_quantity,
                    COALESCE(outgoing.checkedout_quantity, 0) AS outgoing_quantity,
                    COALESCE(stock.stock_quantity, 0) - COALESCE(outgoing.checkedout_quantity, 0) AS available_quantity,
                    outgoing.latest_checkout_date AS latest_checkout_date
                FROM (
                    SELECT
                        im." . sqlId($barcodeCol) . " AS barcode,
                        {$itemWorkAreaExpr} AS work_area,
                        {$itemSelect} AS item_name,
                        {$descSelect} AS description,
                        {$entitySelect} AS entity,
                        {$expirySelect} AS expiry_date,
                        {$dateAddedSelect} AS latest_stock_date,
                        SUM(COALESCE(im." . sqlId($quantityCol) . ", 0)) AS stock_quantity
                    FROM {$table} im
                    WHERE {$itemFilter}
                      AND COALESCE(im." . sqlId($barcodeCol) . ", '') <> ''
                      AND {$itemWorkAreaExpr} = :item_work_area
                    GROUP BY im." . sqlId($barcodeCol) . ", {$itemWorkAreaExpr}
                ) stock
                LEFT JOIN (
                    SELECT
                        co." . sqlId($checkedoutBarcode) . " AS barcode,
                        {$checkoutWorkAreaExpr} AS work_area,
                        SUM(COALESCE(co." . sqlId($checkedoutQuantity) . ", 0)) AS checkedout_quantity,
                        {$latestCheckoutSelect} AS latest_checkout_date
                    FROM tbl_checkedout_items co
                    WHERE {$checkoutFilter}
                      AND COALESCE(co." . sqlId($checkedoutBarcode) . ", '') <> ''
                      AND {$checkoutWorkAreaExpr} = :checkout_work_area
                    GROUP BY co." . sqlId($checkedoutBarcode) . ", {$checkoutWorkAreaExpr}
                ) outgoing ON outgoing.barcode = stock.barcode AND outgoing.work_area = stock.work_area
            ) audit
            WHERE audit.available_quantity < 0
        ";

        $params = [
            ':category_key' => $key,
            ':category_label' => $category['label'],
            ':item_work_area' => $requestedWorkArea,
            ':checkout_work_area' => $requestedWorkArea
        ];
        if ($search !== '') {
            $sql .= " AND (audit.barcode LIKE :search OR audit.item_name LIKE :search OR audit.description LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY audit.available_quantity ASC, audit.item_name ASC, audit.barcode ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['stock_quantity'] = (int)($row['stock_quantity'] ?? 0);
            $row['outgoing_quantity'] = (int)($row['outgoing_quantity'] ?? 0);
            $row['available_quantity'] = (int)($row['available_quantity'] ?? 0);
            $row['overdrawn_by'] = abs((int)$row['available_quantity']);
            $rows[] = $row;
        }
    }

    usort($rows, static function ($a, $b) {
        $qty = ((int)($a['available_quantity'] ?? 0)) <=> ((int)($b['available_quantity'] ?? 0));
        if ($qty !== 0) {
            return $qty;
        }
        return strcasecmp((string)($a['item_name'] ?? ''), (string)($b['item_name'] ?? ''));
    });

    return $rows;
}

function loadReleaseRows($conn, $requestedWorkArea, $barcode, $limit = 300, $offset = 0) {
    $barcode = trim((string)$barcode);
    if ($barcode === '') {
        sendResponse('error', 'Barcode is required.', null, 400);
    }

    $columns = getTableColumns($conn, 'tbl_checkedout_items');
    $barcodeCol = pickColumn($columns, ['Barcode', 'barcode']);
    $quantityCol = pickColumn($columns, ['Quantity', 'quantity']);
    $risCol = pickColumn($columns, ['RIS_Number', 'ris_number', 'Reference_No', 'reference_no']);
    $categoryCol = pickColumn($columns, ['Category', 'category']);
    $transactionTypeCol = pickColumn($columns, ['Transaction_Type', 'transaction_type']);
    $checkoutDateCol = pickColumn($columns, ['Checkout_Date', 'checkout_date']);
    $transferDateCol = pickColumn($columns, ['Transfer_Date', 'transfer_date', 'Date_Transfer', 'date_transfer']);
    $checkoutByCol = pickColumn($columns, ['Checkout_By', 'checkout_by']);
    $transferByCol = pickColumn($columns, ['Transfer_By', 'transfer_by', 'Transferred_By', 'transferred_by']);
    $barangayCol = pickColumn($columns, ['Barangay', 'barangay']);
    $destinationCol = pickColumn($columns, ['Destination', 'destination']);
    $piidCol = pickColumn($columns, ['PIID', 'piid']);
    $deletedCol = pickColumn($columns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
    $workAreaCol = pickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);

    if (!$barcodeCol || !$quantityCol) {
        sendResponse('error', 'Checkout table is missing barcode or quantity columns.', null, 500);
    }
    if ($limit <= 0) {
        $limit = 300;
    }
    if ($limit > 1000) {
        $limit = 1000;
    }
    if ($offset < 0) {
        $offset = 0;
    }

    $workAreaExpr = buildWorkAreaExpr('co', $workAreaCol);
    $dateExpr = $transferDateCol && $checkoutDateCol
        ? "COALESCE(co." . sqlId($transferDateCol) . ", co." . sqlId($checkoutDateCol) . ")"
        : ($transferDateCol ? "co." . sqlId($transferDateCol) : ($checkoutDateCol ? "co." . sqlId($checkoutDateCol) : "NULL"));
    $performedByExpr = $transferByCol && $checkoutByCol
        ? "COALESCE(NULLIF(TRIM(co." . sqlId($transferByCol) . "), ''), co." . sqlId($checkoutByCol) . ")"
        : ($transferByCol ? "co." . sqlId($transferByCol) : ($checkoutByCol ? "co." . sqlId($checkoutByCol) : "''"));

    $selects = [
        "co." . sqlId($barcodeCol) . " AS barcode",
        ($risCol ? "co." . sqlId($risCol) : "''") . " AS reference_no",
        "co." . sqlId($quantityCol) . " AS quantity",
        "{$dateExpr} AS transaction_date",
        "{$performedByExpr} AS performed_by",
        ($categoryCol ? "co." . sqlId($categoryCol) : "''") . " AS category",
        ($transactionTypeCol ? "co." . sqlId($transactionTypeCol) : "''") . " AS transaction_type",
        ($destinationCol ? "co." . sqlId($destinationCol) : "''") . " AS destination",
        ($barangayCol ? "co." . sqlId($barangayCol) : "''") . " AS barangay",
        ($piidCol ? "co." . sqlId($piidCol) : "''") . " AS piid",
        ($deletedCol ? "co." . sqlId($deletedCol) : "0") . " AS is_deleted"
    ];

    $where = [
        "co." . sqlId($barcodeCol) . " = :barcode",
        "{$workAreaExpr} = :work_area"
    ];
    $params = [':barcode' => $barcode, ':work_area' => $requestedWorkArea];
    $baseWhereSql = implode(' AND ', $where);
    $activeQuantityExpr = $deletedCol
        ? "SUM(CASE WHEN co." . sqlId($deletedCol) . " = 0 THEN COALESCE(co." . sqlId($quantityCol) . ", 0) ELSE 0 END)"
        : "SUM(COALESCE(co." . sqlId($quantityCol) . ", 0))";
    $activeRowsExpr = $deletedCol
        ? "SUM(CASE WHEN co." . sqlId($deletedCol) . " = 0 THEN 1 ELSE 0 END)"
        : "COUNT(*)";

    $totalStmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_rows,
            {$activeRowsExpr} AS active_rows,
            COALESCE({$activeQuantityExpr}, 0) AS active_quantity
        FROM tbl_checkedout_items co
        WHERE {$baseWhereSql}
    ");
    $totalStmt->execute($params);
    $totals = $totalStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $conn->prepare("
        SELECT " . implode(",\n               ", $selects) . "
        FROM tbl_checkedout_items co
        WHERE {$baseWhereSql}
        ORDER BY {$dateExpr} DESC, " . ($risCol ? "co." . sqlId($risCol) : "co." . sqlId($barcodeCol)) . " DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $reference = strtoupper(trim((string)($row['reference_no'] ?? '')));
        $destination = trim((string)($row['destination'] ?? ''));
        $category = strtoupper(trim((string)($row['category'] ?? '')));
        $transactionType = strtoupper(trim((string)($row['transaction_type'] ?? '')));
        $isTransfer = $transactionType === 'TRANSFER' || substr($reference, 0, 3) === 'TRF' || $destination !== '';
        $row['kind'] = $isTransfer ? 'TRANSFER' : 'CHECKOUT';
        $row['category'] = $category;
        $row['transaction_type'] = $transactionType;
        $row['quantity'] = (int)($row['quantity'] ?? 0);
        $row['is_deleted'] = (int)($row['is_deleted'] ?? 0);
    }
    unset($row);

    return [
        'rows' => $rows,
        'total_rows' => (int)($totals['total_rows'] ?? 0),
        'active_rows' => (int)($totals['active_rows'] ?? 0),
        'active_quantity' => (int)($totals['active_quantity'] ?? 0),
        'limit' => $limit,
        'offset' => $offset
    ];
}

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

if (!apiUserCan($userData, 'inventory_view')) {
    sendResponse('error', 'You are not allowed to access inventory audit.', null, 403);
}

try {
    $requestedWorkArea = normalizeWorkArea($_GET['work_area'] ?? ($userData['location'] ?? 'CHO'));
    $action = strtolower(trim((string)($_GET['action'] ?? 'negative_inventory')));
    if ($action === 'item_releases') {
        $barcode = trim((string)($_GET['barcode'] ?? ''));
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 300;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $releaseData = loadReleaseRows($conn, $requestedWorkArea, $barcode, $limit, $offset);
        $rows = $releaseData['rows'];
        sendResponse('success', 'Item release records loaded.', $rows, 200, [
            'count' => count($rows),
            'total_rows' => $releaseData['total_rows'],
            'active_rows' => $releaseData['active_rows'],
            'active_quantity' => $releaseData['active_quantity'],
            'limit' => $releaseData['limit'],
            'offset' => $releaseData['offset'],
            'barcode' => $barcode,
            'work_area' => $requestedWorkArea
        ]);
    }

    $category = strtolower(trim((string)($_GET['category'] ?? 'all')));
    $search = trim((string)($_GET['search'] ?? ''));
    $rows = loadNegativeInventoryRows($conn, $requestedWorkArea, $category, $search);

    $totalOverdrawn = 0;
    foreach ($rows as $row) {
        $totalOverdrawn += (int)($row['overdrawn_by'] ?? 0);
    }

    sendResponse('success', 'Inventory audit loaded.', $rows, 200, [
        'count' => count($rows),
        'total_overdrawn' => $totalOverdrawn,
        'work_area' => $requestedWorkArea,
        'category' => $category,
        'search' => $search
    ]);
} catch (Exception $e) {
    sendResponse('error', 'Inventory audit failed: ' . $e->getMessage(), null, 500);
}
?>
