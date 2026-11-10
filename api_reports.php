<?php
require_once __DIR__ . '/cors.php';
header("Access-Control-Expose-Headers: Content-Disposition");
header("Content-Type: application/json");

require_once 'db_auth.php';

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
        return false;
    }
    return $date->format('Y-m-d');
}

function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '', (string)$identifier) . '`';
}

function quoteLiteral($value) {
    return "'" . str_replace("'", "''", (string)$value) . "'";
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

function isAllWorkArea($workArea) {
    return strtoupper(trim((string)$workArea)) === 'ALL';
}

function addWorkAreaCondition($expression, $workArea, &$conditions, &$params, $paramName) {
    if (isAllWorkArea($workArea)) {
        return;
    }
    $conditions[] = "UPPER(COALESCE(NULLIF(TRIM({$expression}), ''), 'CHO')) = {$paramName}";
    $params[$paramName] = $workArea;
}

function addDateRangeConditions($expression, $fromDate, $toDate, &$conditions, &$params, $prefix) {
    if ($fromDate) {
        $param = ':' . $prefix . '_from';
        $conditions[] = "DATE({$expression}) >= {$param}";
        $params[$param] = $fromDate;
    }
    if ($toDate) {
        $param = ':' . $prefix . '_to';
        $conditions[] = "DATE({$expression}) <= {$param}";
        $params[$param] = $toDate;
    }
}

function formatDateTimeValue($value) {
    $value = normalizeString($value);
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('Y-m-d H:i:s', $ts);
}

function formatDateValue($value) {
    $value = normalizeString($value);
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('Y-m-d', $ts);
}

function buildPersonJoinMeta($conn, $checkedoutPiidExpr) {
    $columns = getTableColumns($conn, 'tbl_personal_details');
    if (empty($columns)) {
        return ['join' => '', 'name_expr' => "''"];
    }

    $piid = pickColumn($columns, ['PIID', 'piid']);
    if (!$piid) {
        return ['join' => '', 'name_expr' => "''"];
    }

    $surname = pickColumn($columns, ['Surname', 'surname']);
    $firstName = pickColumn($columns, ['FirstName', 'Firstname', 'firstname', 'first_name']);
    $middleName = pickColumn($columns, ['MiddleName', 'Middlename', 'middlename', 'middle_name']);
    $nameExt = pickColumn($columns, ['NameExt', 'Name_Ext', 'nameext', 'name_ext']);

    $parts = [];
    if ($surname) {
        $parts[] = "NULLIF(TRIM(pd." . quoteIdentifier($surname) . "), '')";
    }
    if ($firstName) {
        $parts[] = "NULLIF(TRIM(pd." . quoteIdentifier($firstName) . "), '')";
    }
    if ($middleName) {
        $parts[] = "NULLIF(TRIM(pd." . quoteIdentifier($middleName) . "), '')";
    }
    if ($nameExt) {
        $parts[] = "NULLIF(TRIM(pd." . quoteIdentifier($nameExt) . "), '')";
    }

    $nameExpr = "''";
    if (!empty($parts)) {
        $nameExpr = "TRIM(CONCAT_WS(' ', " . implode(', ', $parts) . "))";
    }

    return [
        'join' => "LEFT JOIN tbl_personal_details pd ON pd." . quoteIdentifier($piid) . " = {$checkedoutPiidExpr}",
        'name_expr' => $nameExpr
    ];
}

function getItemTableMeta($conn, $table, $label) {
    $columns = getTableColumns($conn, $table);
    if (empty($columns)) {
        return null;
    }

    $barcode = pickColumn($columns, ['Barcode_Number', 'barcode_number', 'Barcode', 'barcode']);
    $item = pickColumn($columns, ['Item', 'item']);
    $description = pickColumn($columns, ['Description', 'description']);
    $entity = pickColumn($columns, ['Entity', 'entity']);
    $unitCost = pickColumn($columns, ['Unit_Cost', 'unit_cost', 'UnitCost']);
    $quantity = pickColumn($columns, ['Quantity', 'quantity']);
    $expiryDate = pickColumn($columns, ['Expiry_Date', 'expiry_date', 'Expiration_Date', 'expiration_date']);
    $dateAdded = pickColumn($columns, ['Date_Added', 'date_added']);
    $donated = pickColumn($columns, ['Donated', 'donated']);
    $poNumber = pickColumn($columns, ['PO_Number', 'po_number', 'PONumber', 'po']);
    $contractNumber = pickColumn($columns, ['Contract_Number', 'contract_number', 'ContractNo', 'Contract_No', 'contract_no']);
    $batchNumber = pickColumn($columns, ['Batch_Number', 'batch_number', 'BatchNo', 'Batch_No', 'batch_no']);
    $lotNumber = pickColumn($columns, ['Lot_Number', 'lot_number', 'LotNo', 'Lot_No', 'lot_no']);
    $workArea = pickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
    $stockSource = pickColumn($columns, ['Stock_Source', 'stock_source']);
    $isDeleted = getIsDeletedColumn($columns);

    if (!$barcode || !$item || !$quantity) {
        return null;
    }

    return [
        'table' => $table,
        'label' => $label,
        'barcode' => $barcode,
        'item' => $item,
        'description' => $description,
        'entity' => $entity,
        'unit_cost' => $unitCost,
        'quantity' => $quantity,
        'expiry_date' => $expiryDate,
        'date_added' => $dateAdded,
        'donated' => $donated,
        'po_number' => $poNumber,
        'contract_number' => $contractNumber,
        'batch_number' => $batchNumber,
        'lot_number' => $lotNumber,
        'work_area' => $workArea,
        'stock_source' => $stockSource,
        'is_deleted' => $isDeleted
    ];
}

/**
 * Opening stock = batches Aglayan/UPLC entered by hand (Stock_Source = 'OPENING', see
 * api_opening_stock.php). They were not distributed by CHO and have no PO, so reports can
 * show them on their own, leave them out, and never count them as purchased.
 * Filter values: 'all' (default), 'opening' (only opening stock), 'cho' (everything else).
 */
function normalizeStockSourceFilter($value) {
    $value = strtolower(normalizeString($value));
    return in_array($value, ['opening', 'cho'], true) ? $value : 'all';
}

function getStockSourceFilterLabel($filter) {
    $labels = [
        'all' => 'All stock',
        'opening' => 'Opening stock only (entered by hand)',
        'cho' => 'Through CHO only (opening stock excluded)'
    ];
    return $labels[$filter] ?? $labels['all'];
}

function stockSourceOpeningExpr($meta, $alias = 't') {
    if (empty($meta['stock_source'])) {
        return '0';
    }
    return "CASE WHEN UPPER(COALESCE({$alias}." . quoteIdentifier($meta['stock_source']) . ", '')) = 'OPENING' THEN 1 ELSE 0 END";
}

function addStockSourceFilterCondition($meta, $filter, &$conditions, $alias = 't') {
    if ($filter === 'opening' || $filter === 'cho') {
        $conditions[] = '(' . stockSourceOpeningExpr($meta, $alias) . ') = ' . ($filter === 'opening' ? '1' : '0');
    }
}

function getStockSourceRowLabel($isOpening) {
    return (int)$isOpening === 1 ? 'Opening stock (manual)' : 'Through CHO';
}

function buildItemDetailsSubquery($itemMetas, $workArea, &$params) {
    $parts = [];
    $idx = 0;
    $applyWorkAreaFilter = !isAllWorkArea($workArea);

    foreach ($itemMetas as $meta) {
        $conditions = [];
        if ($meta['is_deleted']) {
            $conditions[] = 't.' . quoteIdentifier($meta['is_deleted']) . ' = 0';
        }
        if ($meta['work_area'] && $applyWorkAreaFilter) {
            $param = ':item_detail_work_area_' . $idx;
            $conditions[] = "UPPER(COALESCE(NULLIF(TRIM(t." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = {$param}";
            $params[$param] = $workArea;
        }

        $where = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));
        $parts[] = "
            SELECT
                t." . quoteIdentifier($meta['barcode']) . " AS barcode_number,
                t." . quoteIdentifier($meta['item']) . " AS item_name,
                " . ($meta['description'] ? ('t.' . quoteIdentifier($meta['description'])) : "''") . " AS description,
                " . ($meta['entity'] ? ('t.' . quoteIdentifier($meta['entity'])) : "''") . " AS entity,
                " . ($meta['unit_cost'] ? ('t.' . quoteIdentifier($meta['unit_cost'])) : "0") . " AS unit_cost,
                " . ($meta['expiry_date'] ? ('t.' . quoteIdentifier($meta['expiry_date'])) : "NULL") . " AS expiry_date
            FROM " . quoteIdentifier($meta['table']) . " t
            {$where}
        ";
        $idx++;
    }

    if (empty($parts)) {
        return "
            SELECT
                '' AS barcode_number,
                '' AS item_name,
                '' AS description,
                '' AS entity,
                0 AS unit_cost,
                NULL AS expiry_date
            WHERE 1 = 0
        ";
    }

    return "
        SELECT
            u.barcode_number,
            MAX(u.item_name) AS item_name,
            MAX(u.description) AS description,
            MAX(u.entity) AS entity,
            MAX(COALESCE(u.unit_cost, 0)) AS unit_cost,
            MIN(u.expiry_date) AS expiry_date
        FROM (
            " . implode("\nUNION ALL\n", $parts) . "
        ) u
        GROUP BY u.barcode_number
    ";
}

function buildCheckoutAggregationSubquery($checkoutMeta, $workArea, &$params) {
    $conditions = ['1=1'];
    if ($checkoutMeta['is_deleted']) {
        $conditions[] = 'co.' . quoteIdentifier($checkoutMeta['is_deleted']) . ' = 0';
    }
    if ($checkoutMeta['work_area']) {
        addWorkAreaCondition(
            'co.' . quoteIdentifier($checkoutMeta['work_area']),
            $workArea,
            $conditions,
            $params,
            ':checkout_agg_work_area'
        );
    }

    $clinicExpr = !empty($checkoutMeta['work_area'])
        ? "UPPER(COALESCE(NULLIF(TRIM(co." . quoteIdentifier($checkoutMeta['work_area']) . "), ''), 'CHO'))"
        : quoteLiteral('CHO');

    return "
        SELECT
            co." . quoteIdentifier($checkoutMeta['barcode']) . " AS barcode_number,
            {$clinicExpr} AS clinic_key,
            SUM(COALESCE(co." . quoteIdentifier($checkoutMeta['quantity']) . ", 0)) AS total_checked_out
        FROM tbl_checkedout_items co
        WHERE " . implode(' AND ', $conditions) . "
        GROUP BY co." . quoteIdentifier($checkoutMeta['barcode']) . ", {$clinicExpr}
    ";
}

function buildStockSourceSubquery($itemMetas, $workArea, $fromDate, $toDate, $applyDateFilter, &$params, $stockSourceFilter = 'all') {
    $parts = [];
    $idx = 0;
    $applyWorkAreaFilter = !isAllWorkArea($workArea);

    foreach ($itemMetas as $meta) {
        $conditions = [];
        if ($meta['is_deleted']) {
            $conditions[] = 't.' . quoteIdentifier($meta['is_deleted']) . ' = 0';
        }
        if ($meta['work_area'] && $applyWorkAreaFilter) {
            $param = ':stock_work_area_' . $idx;
            $conditions[] = "UPPER(COALESCE(NULLIF(TRIM(t." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = {$param}";
            $params[$param] = $workArea;
        }
        if ($applyDateFilter && $meta['date_added']) {
            addDateRangeConditions(
                't.' . quoteIdentifier($meta['date_added']),
                $fromDate,
                $toDate,
                $conditions,
                $params,
                'stock_date_' . $idx
            );
        }
        addStockSourceFilterCondition($meta, $stockSourceFilter, $conditions);

        $where = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));
        $stockAreaExpr = $meta['work_area']
            ? "UPPER(COALESCE(NULLIF(TRIM(t." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO'))"
            : quoteLiteral('CHO');
        $parts[] = "
            SELECT
                " . quoteLiteral($meta['label']) . " AS stock_category,
                {$stockAreaExpr} AS stock_work_area,
                t." . quoteIdentifier($meta['barcode']) . " AS barcode_number,
                t." . quoteIdentifier($meta['item']) . " AS item_name,
                " . ($meta['description'] ? ('t.' . quoteIdentifier($meta['description'])) : "''") . " AS description,
                " . ($meta['entity'] ? ('t.' . quoteIdentifier($meta['entity'])) : "''") . " AS entity,
                " . ($meta['unit_cost'] ? ('t.' . quoteIdentifier($meta['unit_cost'])) : "0") . " AS unit_cost,
                COALESCE(t." . quoteIdentifier($meta['quantity']) . ", 0) AS stock_quantity,
                " . ($meta['expiry_date'] ? ('t.' . quoteIdentifier($meta['expiry_date'])) : "NULL") . " AS expiry_date,
                " . ($meta['date_added'] ? ('t.' . quoteIdentifier($meta['date_added'])) : "NULL") . " AS date_added,
                " . ($meta['donated'] ? ('COALESCE(t.' . quoteIdentifier($meta['donated']) . ", 0)") : "0") . " AS donated_flag,
                " . stockSourceOpeningExpr($meta) . " AS is_opening
            FROM " . quoteIdentifier($meta['table']) . " t
            {$where}
        ";
        $idx++;
    }

    if (empty($parts)) {
        return "
            SELECT
                '' AS stock_category,
                'CHO' AS stock_work_area,
                '' AS barcode_number,
                '' AS item_name,
                '' AS description,
                '' AS entity,
                0 AS unit_cost,
                0 AS stock_quantity,
                NULL AS expiry_date,
                NULL AS date_added,
                0 AS donated_flag,
                0 AS is_opening
            WHERE 1 = 0
        ";
    }

    return implode("\nUNION ALL\n", $parts);
}

function buildStockRows($conn, $itemMetas, $checkoutMeta, $workArea, $fromDate, $toDate, $applyDateFilter, $stockSourceFilter = 'all') {
    $params = [];
    $checkoutSubquery = buildCheckoutAggregationSubquery($checkoutMeta, $workArea, $params);
    $stockSubquery = buildStockSourceSubquery($itemMetas, $workArea, $fromDate, $toDate, $applyDateFilter, $params, $stockSourceFilter);

    $sql = "
        SELECT
            s.stock_category,
            s.stock_work_area,
            s.barcode_number,
            MAX(s.item_name) AS item_name,
            MAX(s.description) AS description,
            MAX(s.entity) AS entity,
            MAX(COALESCE(s.unit_cost, 0)) AS unit_cost,
            SUM(COALESCE(s.stock_quantity, 0)) AS total_stock_quantity,
            COALESCE(c.total_checked_out, 0) AS total_checked_out_quantity,
            (SUM(COALESCE(s.stock_quantity, 0)) - COALESCE(c.total_checked_out, 0)) AS available_quantity,
            MIN(s.expiry_date) AS nearest_expiry_date,
            MAX(s.date_added) AS latest_date_added,
            MAX(COALESCE(s.is_opening, 0)) AS is_opening,
            SUM(CASE WHEN COALESCE(s.is_opening, 0) = 1 THEN COALESCE(s.stock_quantity, 0) ELSE 0 END) AS opening_quantity,
            SUM(CASE WHEN COALESCE(s.donated_flag, 0) = 1 THEN COALESCE(s.stock_quantity, 0) ELSE 0 END) AS donated_quantity,
            SUM(CASE WHEN COALESCE(s.donated_flag, 0) = 1 OR COALESCE(s.is_opening, 0) = 1 THEN 0 ELSE COALESCE(s.stock_quantity, 0) END) AS purchased_quantity
        FROM (
            {$stockSubquery}
        ) s
        LEFT JOIN (
            {$checkoutSubquery}
        ) c ON c.barcode_number = s.barcode_number
            AND c.clinic_key = s.stock_work_area
        GROUP BY s.stock_category, s.stock_work_area, s.barcode_number, c.total_checked_out
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildProcurementUtilizationRows($conn, $itemMetas, $checkoutMeta, $workArea, $fromDate, $toDate, $stockSourceFilter = 'all') {
    $params = [];
    $stockSubquery = buildStockSourceSubquery($itemMetas, $workArea, $fromDate, $toDate, true, $params, $stockSourceFilter);

    $checkoutConditions = ['1=1'];
    if ($checkoutMeta['is_deleted']) {
        $checkoutConditions[] = 'co.' . quoteIdentifier($checkoutMeta['is_deleted']) . ' = 0';
    }
    if ($checkoutMeta['work_area']) {
        addWorkAreaCondition(
            'co.' . quoteIdentifier($checkoutMeta['work_area']),
            $workArea,
            $checkoutConditions,
            $params,
            ':proc_checkout_work_area'
        );
    }
    if ($checkoutMeta['transaction_type']) {
        $checkoutConditions[] = "UPPER(COALESCE(NULLIF(TRIM(co." . quoteIdentifier($checkoutMeta['transaction_type']) . "), ''), 'CHECKOUT')) NOT IN ('TRANSFER', 'ADJUSTMENT')";
    } elseif ($checkoutMeta['ris_number']) {
        $checkoutConditions[] = "co." . quoteIdentifier($checkoutMeta['ris_number']) . " NOT LIKE 'TRF%'";
    }
    if ($checkoutMeta['checkout_date']) {
        addDateRangeConditions(
            'co.' . quoteIdentifier($checkoutMeta['checkout_date']),
            $fromDate,
            $toDate,
            $checkoutConditions,
            $params,
            'proc_checkout_date'
        );
    }

    $checkoutAreaExpr = $checkoutMeta['work_area']
        ? "UPPER(COALESCE(NULLIF(TRIM(co." . quoteIdentifier($checkoutMeta['work_area']) . "), ''), 'CHO'))"
        : quoteLiteral('CHO');

    $checkoutSubquery = "
        SELECT
            co." . quoteIdentifier($checkoutMeta['barcode']) . " AS barcode_number,
            {$checkoutAreaExpr} AS stock_work_area,
            SUM(COALESCE(co." . quoteIdentifier($checkoutMeta['quantity']) . ", 0)) AS dispensed_quantity
        FROM tbl_checkedout_items co
        WHERE " . implode(' AND ', $checkoutConditions) . "
        GROUP BY co." . quoteIdentifier($checkoutMeta['barcode']) . ", {$checkoutAreaExpr}
    ";

    $sql = "
        SELECT
            s.stock_category,
            s.stock_work_area,
            s.barcode_number,
            MAX(s.item_name) AS item_name,
            MAX(s.description) AS description,
            MAX(s.entity) AS entity,
            MAX(COALESCE(s.unit_cost, 0)) AS unit_cost,
            SUM(COALESCE(s.stock_quantity, 0)) AS received_quantity,
            MAX(COALESCE(s.is_opening, 0)) AS is_opening,
            SUM(CASE WHEN COALESCE(s.is_opening, 0) = 1 THEN COALESCE(s.stock_quantity, 0) ELSE 0 END) AS opening_quantity,
            SUM(CASE WHEN COALESCE(s.is_opening, 0) = 1 THEN COALESCE(s.stock_quantity, 0) * COALESCE(s.unit_cost, 0) ELSE 0 END) AS opening_amount,
            SUM(CASE WHEN COALESCE(s.donated_flag, 0) = 1 OR COALESCE(s.is_opening, 0) = 1 THEN 0 ELSE COALESCE(s.stock_quantity, 0) END) AS purchased_quantity,
            SUM(CASE WHEN COALESCE(s.donated_flag, 0) = 1 THEN COALESCE(s.stock_quantity, 0) ELSE 0 END) AS donated_quantity,
            SUM(CASE WHEN COALESCE(s.donated_flag, 0) = 1 OR COALESCE(s.is_opening, 0) = 1 THEN 0 ELSE COALESCE(s.stock_quantity, 0) * COALESCE(s.unit_cost, 0) END) AS purchased_amount,
            SUM(CASE WHEN COALESCE(s.donated_flag, 0) = 1 THEN COALESCE(s.stock_quantity, 0) * COALESCE(s.unit_cost, 0) ELSE 0 END) AS donated_amount,
            COALESCE(c.dispensed_quantity, 0) AS dispensed_quantity,
            COALESCE(c.dispensed_quantity, 0) * MAX(COALESCE(s.unit_cost, 0)) AS dispensed_amount,
            LEAST(
                COALESCE(c.dispensed_quantity, 0),
                SUM(CASE WHEN COALESCE(s.donated_flag, 0) = 1 THEN COALESCE(s.stock_quantity, 0) ELSE 0 END)
            ) AS donated_dispensed_quantity,
            LEAST(
                COALESCE(c.dispensed_quantity, 0),
                SUM(CASE WHEN COALESCE(s.donated_flag, 0) = 1 THEN COALESCE(s.stock_quantity, 0) ELSE 0 END)
            ) * MAX(COALESCE(s.unit_cost, 0)) AS donated_dispensed_amount,
            GREATEST(SUM(COALESCE(s.stock_quantity, 0)) - COALESCE(c.dispensed_quantity, 0), 0) AS remaining_quantity,
            GREATEST(SUM(COALESCE(s.stock_quantity, 0)) - COALESCE(c.dispensed_quantity, 0), 0) * MAX(COALESCE(s.unit_cost, 0)) AS remaining_value,
            CASE
                WHEN SUM(CASE WHEN COALESCE(s.donated_flag, 0) = 1 OR COALESCE(s.is_opening, 0) = 1 THEN 0 ELSE COALESCE(s.stock_quantity, 0) * COALESCE(s.unit_cost, 0) END) > 0
                THEN (COALESCE(c.dispensed_quantity, 0) * MAX(COALESCE(s.unit_cost, 0)))
                    / SUM(CASE WHEN COALESCE(s.donated_flag, 0) = 1 OR COALESCE(s.is_opening, 0) = 1 THEN 0 ELSE COALESCE(s.stock_quantity, 0) * COALESCE(s.unit_cost, 0) END) * 100
                ELSE 0
            END AS utilization_rate
        FROM (
            {$stockSubquery}
        ) s
        LEFT JOIN (
            {$checkoutSubquery}
        ) c ON c.barcode_number = s.barcode_number
            AND c.stock_work_area = s.stock_work_area
        GROUP BY s.stock_category, s.stock_work_area, s.barcode_number, c.dispensed_quantity
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildProcurementReferenceRows($conn, $itemMetas, $workArea, $fromDate, $toDate, $stockSourceFilter = 'all') {
    $parts = [];
    $params = [];
    $idx = 0;
    $applyWorkAreaFilter = !isAllWorkArea($workArea);

    foreach ($itemMetas as $meta) {
        $conditions = [];
        if ($meta['is_deleted']) {
            $conditions[] = 't.' . quoteIdentifier($meta['is_deleted']) . ' = 0';
        }
        if ($meta['work_area'] && $applyWorkAreaFilter) {
            $param = ':proc_ref_work_area_' . $idx;
            $conditions[] = "UPPER(COALESCE(NULLIF(TRIM(t." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = {$param}";
            $params[$param] = $workArea;
        }
        if ($meta['date_added']) {
            addDateRangeConditions(
                't.' . quoteIdentifier($meta['date_added']),
                $fromDate,
                $toDate,
                $conditions,
                $params,
                'proc_ref_date_' . $idx
            );
        }
        addStockSourceFilterCondition($meta, $stockSourceFilter, $conditions);

        $where = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));
        $stockAreaExpr = $meta['work_area']
            ? "UPPER(COALESCE(NULLIF(TRIM(t." . quoteIdentifier($meta['work_area']) . "), ''), 'CHO'))"
            : quoteLiteral('CHO');
        $parts[] = "
            SELECT
                " . quoteLiteral($meta['label']) . " AS stock_category,
                {$stockAreaExpr} AS clinic,
                t." . quoteIdentifier($meta['barcode']) . " AS barcode_number,
                t." . quoteIdentifier($meta['item']) . " AS item_name,
                " . ($meta['description'] ? ('t.' . quoteIdentifier($meta['description'])) : "''") . " AS description,
                " . ($meta['entity'] ? ('t.' . quoteIdentifier($meta['entity'])) : "''") . " AS entity,
                COALESCE(t." . quoteIdentifier($meta['quantity']) . ", 0) AS quantity,
                " . ($meta['unit_cost'] ? ('COALESCE(t.' . quoteIdentifier($meta['unit_cost']) . ', 0)') : "0") . " AS unit_cost,
                COALESCE(t." . quoteIdentifier($meta['quantity']) . ", 0) * " . ($meta['unit_cost'] ? ('COALESCE(t.' . quoteIdentifier($meta['unit_cost']) . ', 0)') : "0") . " AS total_amount,
                " . ($meta['date_added'] ? ('t.' . quoteIdentifier($meta['date_added'])) : "NULL") . " AS date_added,
                " . ($meta['po_number'] ? ('COALESCE(t.' . quoteIdentifier($meta['po_number']) . ", '')") : "''") . " AS po_number,
                " . ($meta['contract_number'] ? ('COALESCE(t.' . quoteIdentifier($meta['contract_number']) . ", '')") : "''") . " AS contract_number,
                " . ($meta['batch_number'] ? ('COALESCE(t.' . quoteIdentifier($meta['batch_number']) . ", '')") : "''") . " AS batch_number,
                " . ($meta['lot_number'] ? ('COALESCE(t.' . quoteIdentifier($meta['lot_number']) . ", '')") : "''") . " AS lot_number,
                " . ($meta['donated'] ? ('COALESCE(t.' . quoteIdentifier($meta['donated']) . ", 0)") : "0") . " AS donated,
                " . stockSourceOpeningExpr($meta) . " AS is_opening
            FROM " . quoteIdentifier($meta['table']) . " t
            {$where}
        ";
        $idx++;
    }

    if (empty($parts)) {
        return [];
    }

    $sql = implode("\nUNION ALL\n", $parts) . "\nORDER BY date_added DESC, stock_category ASC, item_name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDistributionDestinationExpression($checkoutMeta, $alias = 'co') {
    if (!empty($checkoutMeta['destination'])) {
        return "{$alias}." . quoteIdentifier($checkoutMeta['destination']);
    }
    if (!empty($checkoutMeta['barangay'])) {
        return "{$alias}." . quoteIdentifier($checkoutMeta['barangay']);
    }
    return null;
}

function fetchDistributionClinicOptions($conn, $checkoutMeta, $workArea) {
    $destinationExpr = getDistributionDestinationExpression($checkoutMeta, 'co');
    if (!$destinationExpr) {
        return [];
    }

    $params = [];
    $where = ["NULLIF(TRIM({$destinationExpr}), '') IS NOT NULL"];

    if (!empty($checkoutMeta['is_deleted'])) {
        $where[] = 'co.' . quoteIdentifier($checkoutMeta['is_deleted']) . ' = 0';
    }
    if (!empty($checkoutMeta['work_area'])) {
        addWorkAreaCondition(
            'co.' . quoteIdentifier($checkoutMeta['work_area']),
            $workArea,
            $where,
            $params,
            ':clinic_list_work_area'
        );
    }
    if (!empty($checkoutMeta['transaction_type'])) {
        $where[] = "UPPER(COALESCE(NULLIF(TRIM(co." . quoteIdentifier($checkoutMeta['transaction_type']) . "), ''), 'TRANSFER')) = 'TRANSFER'";
    } elseif (!empty($checkoutMeta['ris_number'])) {
        $where[] = "co." . quoteIdentifier($checkoutMeta['ris_number']) . " LIKE 'TRF%'";
    }
    if (!empty($checkoutMeta['category'])) {
        $where[] = "UPPER(COALESCE(NULLIF(TRIM(co." . quoteIdentifier($checkoutMeta['category']) . "), ''), '')) = 'CLINIC'";
    }

    $sql = "
        SELECT DISTINCT TRIM({$destinationExpr}) AS clinic_name
        FROM tbl_checkedout_items co
        WHERE " . implode(' AND ', $where) . "
        ORDER BY clinic_name ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clinics = [];
    foreach ($rows as $row) {
        $name = normalizeString($row['clinic_name'] ?? '');
        if ($name !== '') {
            $clinics[] = $name;
        }
    }
    return $clinics;
}

function sanitizeFilename($value, $fallback = 'inventory_report') {
    $value = strtolower(normalizeString($value));
    if ($value === '') {
        return $fallback;
    }
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : $fallback;
}

function removePathRecursively($path) {
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $items = scandir($path);
    if ($items !== false) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            removePathRecursively($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

function xmlSanitize($value) {
    $value = (string)$value;
    $value = preg_replace('/[^\P{C}\t\n\r]/u', '', $value);
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsxColumnName($index) {
    $index = (int)$index;
    if ($index < 0) {
        return 'A';
    }
    $name = '';
    while ($index >= 0) {
        $name = chr(($index % 26) + 65) . $name;
        $index = intdiv($index, 26) - 1;
    }
    return $name;
}

function xlsxBuildWorksheetXml($columns, $rows) {
    $header = [];
    foreach ($columns as $column) {
        $header[] = (string)($column['label'] ?? $column['key'] ?? '');
    }

    $allRows = [$header];
    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as $column) {
            $key = (string)($column['key'] ?? '');
            $value = ($key !== '' && is_array($row) && array_key_exists($key, $row)) ? $row[$key] : '';
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
            $line[] = $value;
        }
        $allRows[] = $line;
    }

    $rowXmlParts = [];
    $maxColumnCount = max(1, count($header));
    $maxRowCount = max(1, count($allRows));

    foreach ($allRows as $rowIndex => $rowValues) {
        $excelRow = $rowIndex + 1;
        $cells = [];

        foreach ($rowValues as $colIndex => $cellValue) {
            $cellRef = xlsxColumnName($colIndex) . $excelRow;
            $isHeader = ($excelRow === 1);

            if ($isHeader) {
                $text = (string)$cellValue;
                $preserve = preg_match('/^\s|\s$|[\t\r\n]/', $text) ? ' xml:space="preserve"' : '';
                $cells[] = '<c r="' . $cellRef . '" s="1" t="inlineStr"><is><t' . $preserve . '>' . xmlSanitize($text) . '</t></is></c>';
                continue;
            }

            if ($cellValue === null || $cellValue === '') {
                continue;
            }

            if (is_int($cellValue) || is_float($cellValue)) {
                $cells[] = '<c r="' . $cellRef . '"><v>' . (0 + $cellValue) . '</v></c>';
                continue;
            }

            if (is_bool($cellValue)) {
                $cells[] = '<c r="' . $cellRef . '"><v>' . ($cellValue ? '1' : '0') . '</v></c>';
                continue;
            }

            $text = (string)$cellValue;
            $preserve = preg_match('/^\s|\s$|[\t\r\n]/', $text) ? ' xml:space="preserve"' : '';
            $cells[] = '<c r="' . $cellRef . '" t="inlineStr"><is><t' . $preserve . '>' . xmlSanitize($text) . '</t></is></c>';
        }

        if (!empty($cells)) {
            $rowXmlParts[] = '<row r="' . $excelRow . '">' . implode('', $cells) . '</row>';
        }
    }

    $dimension = 'A1:' . xlsxColumnName($maxColumnCount - 1) . $maxRowCount;
    $autofilterRef = 'A1:' . xlsxColumnName($maxColumnCount - 1) . '1';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<sheetData>' . implode('', $rowXmlParts) . '</sheetData>'
        . '<autoFilter ref="' . $autofilterRef . '"/>'
        . '</worksheet>';
}

function xlsxStylesXml() {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2">'
        . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function writeTextFile($path, $contents) {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new Exception('Unable to create export directory: ' . $dir);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new Exception('Unable to write export file: ' . $path);
    }
}

function outputReportXlsx($title, $columns, $rows, $meta) {
    if (!class_exists('PharData')) {
        throw new Exception('PharData is required for XLSX export.');
    }

    $createdAt = gmdate('Y-m-d\TH:i:s\Z');
    $reportName = normalizeString($title) !== '' ? normalizeString($title) : 'Inventory Report';
    $filename = sanitizeFilename($reportName) . '_' . date('Ymd_His') . '.xlsx';

    $basePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'emedtrack_xlsx_' . bin2hex(random_bytes(8));
    $buildPath = $basePath . DIRECTORY_SEPARATOR . 'build';
    $tarPath = $basePath . DIRECTORY_SEPARATOR . 'report.tar';
    $zipPath = $basePath . DIRECTORY_SEPARATOR . 'report.zip';

    try {
        if (!is_dir($buildPath) && !mkdir($buildPath, 0777, true) && !is_dir($buildPath)) {
            throw new Exception('Unable to create temporary export directory.');
        }

        $worksheetXml = xlsxBuildWorksheetXml($columns, $rows);
        $stylesXml = xlsxStylesXml();
        $safeTitle = xmlSanitize($reportName);
        $generator = xmlSanitize('E-MEDTRACK');

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $safeTitle . '</dc:title>'
            . '<dc:creator>' . $generator . '</dc:creator>'
            . '<cp:lastModifiedBy>' . $generator . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $createdAt . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>E-MEDTRACK</Application>'
            . '<DocSecurity>0</DocSecurity>'
            . '<ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Report</vt:lpstr></vt:vector></TitlesOfParts>'
            . '<Company></Company>'
            . '<LinksUpToDate>false</LinksUpToDate>'
            . '<SharedDoc>false</SharedDoc>'
            . '<HyperlinksChanged>false</HyperlinksChanged>'
            . '<AppVersion>1.0</AppVersion>'
            . '</Properties>';

        writeTextFile($buildPath . DIRECTORY_SEPARATOR . '[Content_Types].xml', $contentTypes);
        writeTextFile($buildPath . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . '.rels', $rootRels);
        writeTextFile($buildPath . DIRECTORY_SEPARATOR . 'docProps' . DIRECTORY_SEPARATOR . 'core.xml', $coreXml);
        writeTextFile($buildPath . DIRECTORY_SEPARATOR . 'docProps' . DIRECTORY_SEPARATOR . 'app.xml', $appXml);
        writeTextFile($buildPath . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'workbook.xml', $workbookXml);
        writeTextFile($buildPath . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'styles.xml', $stylesXml);
        writeTextFile($buildPath . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . 'workbook.xml.rels', $workbookRels);
        writeTextFile($buildPath . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'worksheets' . DIRECTORY_SEPARATOR . 'sheet1.xml', $worksheetXml);

        $tar = new PharData($tarPath);
        $tar->buildFromDirectory($buildPath);
        $tar->convertToData(Phar::ZIP, Phar::NONE, 'zip');

        if (!file_exists($zipPath)) {
            throw new Exception('Failed to build XLSX archive.');
        }

        $binary = file_get_contents($zipPath);
        if ($binary === false) {
            throw new Exception('Failed to read XLSX archive.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(200);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $binary;
        exit;
    } finally {
        removePathRecursively($basePath);
    }
}

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}

$report = strtolower(normalizeString($_GET['report'] ?? 'ris'));
$export = strtolower(normalizeString($_GET['export'] ?? 'json'));
$wantsXlsx = ($export === 'xlsx');
$clinicFilter = normalizeString($_GET['clinic'] ?? '');
$clinicOptionsOnly = (normalizeString($_GET['clinic_options'] ?? '') === '1');
$fromDate = normalizeDateOnly($_GET['from'] ?? '');
$toDate = normalizeDateOnly($_GET['to'] ?? '');
if ($fromDate === false || $toDate === false) {
    sendResponse('error', 'Invalid date format. Use YYYY-MM-DD.', null, 400);
}
if ($fromDate && $toDate && $fromDate > $toDate) {
    sendResponse('error', 'Invalid date range: "from" date cannot be later than "to" date.', null, 400);
}

$lowStockThreshold = isset($_GET['threshold']) ? (int)$_GET['threshold'] : 10;
$stockSourceFilter = normalizeStockSourceFilter($_GET['stock_source'] ?? 'all');
$stockSourceReports = ['stocks_available', 'low_stocks', 'near_expiries', 'procurement_utilization', 'procurement_references'];
if ($lowStockThreshold <= 0) {
    $lowStockThreshold = 10;
}

$userLocationWorkArea = normalizeWorkArea($userData['location'] ?? 'CHO');
$requestedWorkArea = normalizeWorkArea($_GET['work_area'] ?? $userLocationWorkArea);
// No work-area restriction here: CHO staff report across all clinics ("All Clinics").
requireApiCapability($userData, 'reports');
if ($report === 'stocks_distribution') {
    $requestedWorkArea = 'CHO';
}

$checkedoutColumns = getTableColumns($conn, 'tbl_checkedout_items');
if (empty($checkedoutColumns)) {
    sendResponse('error', 'Checkout table metadata is unavailable.', null, 500);
}

$checkoutMeta = [
    'barcode' => pickColumn($checkedoutColumns, ['Barcode', 'barcode']),
    'quantity' => pickColumn($checkedoutColumns, ['Quantity', 'quantity']),
    'checkout_date' => pickColumn($checkedoutColumns, ['Checkout_Date', 'checkout_date']),
    'checkout_by' => pickColumn($checkedoutColumns, ['Checkout_By', 'checkout_by']),
    'barangay' => pickColumn($checkedoutColumns, ['Barangay', 'barangay']),
    'ris_number' => pickColumn($checkedoutColumns, ['RIS_Number', 'ris_number', 'Reference_No', 'reference_no']),
    'category' => pickColumn($checkedoutColumns, ['Category', 'category']),
    'piid' => pickColumn($checkedoutColumns, ['PIID', 'piid']),
    'work_area' => pickColumn($checkedoutColumns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
    'destination' => pickColumn($checkedoutColumns, ['Destination', 'destination', 'Target_Area', 'target_area']),
    'transaction_type' => pickColumn($checkedoutColumns, ['Transaction_Type', 'transaction_type']),
    'transfer_date' => pickColumn($checkedoutColumns, ['Transfer_Date', 'transfer_date', 'Date_Transfer', 'date_transfer']),
    'transfer_by' => pickColumn($checkedoutColumns, ['Transfer_By', 'transfer_by', 'Transferred_By', 'transferred_by']),
    'is_deleted' => getIsDeletedColumn($checkedoutColumns)
];

if (!$checkoutMeta['barcode'] || !$checkoutMeta['quantity']) {
    sendResponse('error', 'Checkout table is missing required columns.', null, 500);
}

if ($clinicOptionsOnly) {
    if ($report !== 'stocks_distribution') {
        sendResponse('error', 'Clinic options are available only for stocks distribution report.', null, 400);
    }

    $clinicOptions = fetchDistributionClinicOptions($conn, $checkoutMeta, $requestedWorkArea);
    sendResponse('success', 'Clinic list loaded.', $clinicOptions, 200, [
        'count' => count($clinicOptions),
        'work_area' => $requestedWorkArea
    ]);
}

$itemMetas = [];
$itemTables = [
    ['table' => 'tbl_item_medicine', 'label' => 'Medicine'],
    ['table' => 'tbl_item_medical_supplies', 'label' => 'Medical Supplies'],
    ['table' => 'tbl_item_vaccines', 'label' => 'Vaccines'],
    ['table' => 'tbl_item_lab_reagents', 'label' => 'Lab Reagents']
];
foreach ($itemTables as $itemDef) {
    $meta = getItemTableMeta($conn, $itemDef['table'], $itemDef['label']);
    if ($meta) {
        $itemMetas[] = $meta;
    }
}
if (empty($itemMetas)) {
    sendResponse('error', 'No valid inventory item tables were found.', null, 500);
}

try {
    $rows = [];
    $columns = [];
    $title = '';
    $meta = [];

    if ($report === 'ris' || $report === 'checked_out' || $report === 'stocks_distribution') {
        $params = [];
        $where = ['1=1'];

        if ($checkoutMeta['is_deleted']) {
            $where[] = 'co.' . quoteIdentifier($checkoutMeta['is_deleted']) . ' = 0';
        }
        if ($checkoutMeta['work_area']) {
            addWorkAreaCondition(
                'co.' . quoteIdentifier($checkoutMeta['work_area']),
                $requestedWorkArea,
                $where,
                $params,
                ':checkout_work_area'
            );
        }

        if (($report === 'ris' || $report === 'checked_out') && $checkoutMeta['transaction_type']) {
            $where[] = "UPPER(COALESCE(NULLIF(TRIM(co." . quoteIdentifier($checkoutMeta['transaction_type']) . "), ''), 'CHECKOUT')) NOT IN ('TRANSFER', 'ADJUSTMENT')";
        } elseif (($report === 'ris' || $report === 'checked_out') && $checkoutMeta['ris_number']) {
            $where[] = "co." . quoteIdentifier($checkoutMeta['ris_number']) . " NOT LIKE 'TRF%'";
        }

        if ($report === 'stocks_distribution') {
            if ($checkoutMeta['transaction_type']) {
                $where[] = "UPPER(COALESCE(NULLIF(TRIM(co." . quoteIdentifier($checkoutMeta['transaction_type']) . "), ''), 'TRANSFER')) = 'TRANSFER'";
            } elseif ($checkoutMeta['ris_number']) {
                $where[] = "co." . quoteIdentifier($checkoutMeta['ris_number']) . " LIKE 'TRF%'";
            }

            if ($checkoutMeta['category']) {
                $where[] = "UPPER(COALESCE(NULLIF(TRIM(co." . quoteIdentifier($checkoutMeta['category']) . "), ''), '')) = 'CLINIC'";
            }

            $destinationExpr = getDistributionDestinationExpression($checkoutMeta, 'co');
            if ($clinicFilter !== '') {
                if (!$destinationExpr) {
                    sendResponse('error', 'Destination column is not available for clinic filter.', null, 500);
                }
                $where[] = "UPPER(TRIM({$destinationExpr})) = :selected_clinic";
                $params[':selected_clinic'] = strtoupper($clinicFilter);
            }
        }

        $dateColumn = $checkoutMeta['checkout_date'];
        if ($report === 'stocks_distribution' && $checkoutMeta['transfer_date']) {
            $dateColumn = $checkoutMeta['transfer_date'];
        }
        if ($dateColumn) {
            addDateRangeConditions(
                'co.' . quoteIdentifier($dateColumn),
                $fromDate,
                $toDate,
                $where,
                $params,
                'report_date'
            );
        }

        if ($report === 'checked_out') {
            $category = strtoupper(normalizeString($_GET['checkout_category'] ?? 'INDIVIDUAL'));
            if (!in_array($category, ['INDIVIDUAL', 'BARANGAY', 'LABORATORY'], true)) {
                $category = 'INDIVIDUAL';
            }
            if ($checkoutMeta['category']) {
                $where[] = "UPPER(COALESCE(NULLIF(TRIM(co." . quoteIdentifier($checkoutMeta['category']) . "), ''), 'INDIVIDUAL')) = :checkout_category";
                $params[':checkout_category'] = $category;
            }
            $meta['checkout_category'] = $category;
            $titleMap = [
                'INDIVIDUAL' => 'Checked Out Items - Citizens',
                'BARANGAY' => 'Checked Out Items - Barangays',
                'LABORATORY' => 'Checked Out Items - Laboratory'
            ];
            $title = $titleMap[$category];
        } elseif ($report === 'stocks_distribution') {
            $title = 'Stocks Distribution Report';
        } else {
            $title = 'RIS List Report';
        }

        $itemParams = [];
        $itemSubquery = buildItemDetailsSubquery($itemMetas, $requestedWorkArea, $itemParams);
        $params = array_merge($params, $itemParams);

        $piidExpr = $checkoutMeta['piid'] ? ('co.' . quoteIdentifier($checkoutMeta['piid'])) : "''";
        $personMeta = buildPersonJoinMeta($conn, $piidExpr);

        $destinationSelectExpr = "''";
        if ($report === 'stocks_distribution') {
            $destinationExpr = getDistributionDestinationExpression($checkoutMeta, 'co');
            if ($destinationExpr) {
                $destinationSelectExpr = "COALESCE({$destinationExpr}, '')";
            }
        } elseif ($checkoutMeta['destination']) {
            $destinationSelectExpr = 'COALESCE(co.' . quoteIdentifier($checkoutMeta['destination']) . ", '')";
        }

        $sourceClinicExpr = !empty($checkoutMeta['work_area'])
            ? "UPPER(COALESCE(NULLIF(TRIM(co." . quoteIdentifier($checkoutMeta['work_area']) . "), ''), 'CHO'))"
            : quoteLiteral($requestedWorkArea);

        $sql = "
            SELECT
                " . ($checkoutMeta['ris_number'] ? ('co.' . quoteIdentifier($checkoutMeta['ris_number'])) : "''") . " AS reference_number,
                " . ($dateColumn ? ('co.' . quoteIdentifier($dateColumn)) : "NULL") . " AS report_date,
                " . ($checkoutMeta['category'] ? ('UPPER(COALESCE(co.' . quoteIdentifier($checkoutMeta['category']) . ", ''))") : "''") . " AS category,
                co." . quoteIdentifier($checkoutMeta['barcode']) . " AS barcode,
                COALESCE(itm.item_name, '') AS item_name,
                COALESCE(itm.description, '') AS description,
                COALESCE(itm.entity, '') AS entity,
                COALESCE(itm.unit_cost, 0) AS unit_cost,
                COALESCE(co." . quoteIdentifier($checkoutMeta['quantity']) . ", 0) AS quantity,
                (COALESCE(co." . quoteIdentifier($checkoutMeta['quantity']) . ", 0) * COALESCE(itm.unit_cost, 0)) AS total_amount,
                " . ($checkoutMeta['checkout_by'] ? ('COALESCE(co.' . quoteIdentifier($checkoutMeta['checkout_by']) . ", '')") : "''") . " AS checkout_by,
                " . ($checkoutMeta['barangay'] ? ('COALESCE(co.' . quoteIdentifier($checkoutMeta['barangay']) . ", '')") : "''") . " AS barangay,
                {$destinationSelectExpr} AS destination,
                " . ($checkoutMeta['transfer_by'] ? ('COALESCE(co.' . quoteIdentifier($checkoutMeta['transfer_by']) . ", '')") : "''") . " AS transfer_by,
                {$sourceClinicExpr} AS source_clinic,
                {$personMeta['name_expr']} AS person_name
            FROM tbl_checkedout_items co
            LEFT JOIN (
                {$itemSubquery}
            ) itm ON itm.barcode_number = co." . quoteIdentifier($checkoutMeta['barcode']) . "
            {$personMeta['join']}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY report_date DESC, reference_number DESC, item_name ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['report_date'] = formatDateTimeValue($row['report_date'] ?? '');
            $row['quantity'] = (int)($row['quantity'] ?? 0);
            $row['unit_cost'] = (float)($row['unit_cost'] ?? 0);
            $row['total_amount'] = (float)($row['total_amount'] ?? 0);
            if ($report === 'stocks_distribution') {
                $clinicName = normalizeString($row['destination'] ?? '');
                $row['clinic'] = $clinicName !== '' ? $clinicName : $requestedWorkArea;
            } else {
                $clinicName = normalizeString($row['source_clinic'] ?? '');
                $row['clinic'] = $clinicName !== '' ? $clinicName : $requestedWorkArea;
            }
        }

        if ($report === 'stocks_distribution') {
            $columns = [
                ['key' => 'reference_number', 'label' => 'Transfer No.'],
                ['key' => 'report_date', 'label' => 'Transfer Date'],
                ['key' => 'clinic', 'label' => 'Clinic'],
                ['key' => 'barcode', 'label' => 'Barcode Number'],
                ['key' => 'item_name', 'label' => 'Item'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'entity', 'label' => 'Entity'],
                ['key' => 'quantity', 'label' => 'Quantity'],
                ['key' => 'transfer_by', 'label' => 'Transferred By']
            ];
        } else {
            $columns = [
                ['key' => 'reference_number', 'label' => 'RIS Number'],
                ['key' => 'report_date', 'label' => 'Checkout Date'],
                ['key' => 'clinic', 'label' => 'Clinic'],
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'barcode', 'label' => 'Barcode Number'],
                ['key' => 'item_name', 'label' => 'Item'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'entity', 'label' => 'Entity'],
                ['key' => 'quantity', 'label' => 'Quantity'],
                ['key' => 'unit_cost', 'label' => 'Unit Cost'],
                ['key' => 'total_amount', 'label' => 'Total Amount'],
                ['key' => 'checkout_by', 'label' => 'Issued By'],
                ['key' => 'person_name', 'label' => 'Recipient'],
                ['key' => 'barangay', 'label' => 'Barangay']
            ];
        }

        $totalQty = 0;
        $totalAmount = 0;
        $references = [];
        foreach ($rows as $row) {
            $totalQty += (int)($row['quantity'] ?? 0);
            $totalAmount += (float)($row['total_amount'] ?? 0);
            $ref = normalizeString($row['reference_number'] ?? '');
            if ($ref !== '') {
                $references[$ref] = true;
            }
        }

        $meta['total_quantity'] = $totalQty;
        $meta['total_amount'] = round($totalAmount, 2);
        $meta['unique_references'] = count($references);
        if ($report === 'stocks_distribution') {
            $meta['selected_clinic'] = $clinicFilter;
        }
    } elseif ($report === 'procurement_utilization') {
        $title = 'Procurement and Utilization Value Summary';
        $rows = buildProcurementUtilizationRows(
            $conn,
            $itemMetas,
            $checkoutMeta,
            $requestedWorkArea,
            $fromDate,
            $toDate,
            $stockSourceFilter
        );

        foreach ($rows as &$row) {
            $row['unit_cost'] = (float)($row['unit_cost'] ?? 0);
            $row['received_quantity'] = (int)round((float)($row['received_quantity'] ?? 0));
            $row['opening_quantity'] = (int)round((float)($row['opening_quantity'] ?? 0));
            $row['opening_amount'] = round((float)($row['opening_amount'] ?? 0), 2);
            $row['stock_source'] = getStockSourceRowLabel($row['is_opening'] ?? 0);
            $row['purchased_quantity'] = (int)round((float)($row['purchased_quantity'] ?? 0));
            $row['donated_quantity'] = (int)round((float)($row['donated_quantity'] ?? 0));
            $row['dispensed_quantity'] = (int)round((float)($row['dispensed_quantity'] ?? 0));
            $row['donated_dispensed_quantity'] = (int)round((float)($row['donated_dispensed_quantity'] ?? 0));
            $row['remaining_quantity'] = (int)round((float)($row['remaining_quantity'] ?? 0));
            $row['purchased_amount'] = round((float)($row['purchased_amount'] ?? 0), 2);
            $row['donated_amount'] = round((float)($row['donated_amount'] ?? 0), 2);
            $row['dispensed_amount'] = round((float)($row['dispensed_amount'] ?? 0), 2);
            $row['donated_dispensed_amount'] = round((float)($row['donated_dispensed_amount'] ?? 0), 2);
            $row['remaining_value'] = round((float)($row['remaining_value'] ?? 0), 2);
            $row['utilization_rate'] = round((float)($row['utilization_rate'] ?? 0), 2);
            $clinicName = normalizeString($row['stock_work_area'] ?? '');
            $row['clinic'] = $clinicName !== '' ? $clinicName : $requestedWorkArea;
        }
        unset($row);

        usort($rows, function ($a, $b) {
            $catCompare = strcasecmp((string)($a['stock_category'] ?? ''), (string)($b['stock_category'] ?? ''));
            if ($catCompare !== 0) {
                return $catCompare;
            }
            $amountCompare = ((float)($b['dispensed_amount'] ?? 0)) <=> ((float)($a['dispensed_amount'] ?? 0));
            if ($amountCompare !== 0) {
                return $amountCompare;
            }
            return strcasecmp((string)($a['item_name'] ?? ''), (string)($b['item_name'] ?? ''));
        });

        $columns = [
            ['key' => 'stock_category', 'label' => 'Category'],
            ['key' => 'clinic', 'label' => 'Clinic'],
            ['key' => 'stock_source', 'label' => 'Source'],
            ['key' => 'barcode_number', 'label' => 'Barcode Number'],
            ['key' => 'item_name', 'label' => 'Item'],
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'entity', 'label' => 'Entity'],
            ['key' => 'unit_cost', 'label' => 'Unit Cost'],
            ['key' => 'purchased_quantity', 'label' => 'Purchased Qty'],
            ['key' => 'purchased_amount', 'label' => 'Purchased Amount'],
            ['key' => 'opening_quantity', 'label' => 'Opening Stock Qty'],
            ['key' => 'opening_amount', 'label' => 'Opening Stock Value'],
            ['key' => 'dispensed_quantity', 'label' => 'Dispensed Qty'],
            ['key' => 'dispensed_amount', 'label' => 'Dispensed Amount'],
            ['key' => 'donated_dispensed_quantity', 'label' => 'Donated Dispensed Qty'],
            ['key' => 'donated_dispensed_amount', 'label' => 'Donated Dispensed Amount'],
            ['key' => 'remaining_quantity', 'label' => 'Remaining Qty'],
            ['key' => 'remaining_value', 'label' => 'Remaining Value'],
            ['key' => 'donated_amount', 'label' => 'Donated Value'],
            ['key' => 'utilization_rate', 'label' => 'Utilization Rate']
        ];

        $meta['total_purchased_quantity'] = 0;
        $meta['total_dispensed_quantity'] = 0;
        $meta['total_donated_dispensed_quantity'] = 0;
        $meta['total_remaining_quantity'] = 0;
        $meta['total_purchased_amount'] = 0;
        $meta['total_dispensed_amount'] = 0;
        $meta['total_donated_dispensed_amount'] = 0;
        $meta['total_remaining_value'] = 0;
        $meta['total_donated_amount'] = 0;
        $meta['total_opening_quantity'] = 0;
        $meta['total_opening_amount'] = 0;
        foreach ($rows as $row) {
            $meta['total_opening_quantity'] += (int)($row['opening_quantity'] ?? 0);
            $meta['total_opening_amount'] += (float)($row['opening_amount'] ?? 0);
            $meta['total_purchased_quantity'] += (int)($row['purchased_quantity'] ?? 0);
            $meta['total_dispensed_quantity'] += (int)($row['dispensed_quantity'] ?? 0);
            $meta['total_donated_dispensed_quantity'] += (int)($row['donated_dispensed_quantity'] ?? 0);
            $meta['total_remaining_quantity'] += (int)($row['remaining_quantity'] ?? 0);
            $meta['total_purchased_amount'] += (float)($row['purchased_amount'] ?? 0);
            $meta['total_dispensed_amount'] += (float)($row['dispensed_amount'] ?? 0);
            $meta['total_donated_dispensed_amount'] += (float)($row['donated_dispensed_amount'] ?? 0);
            $meta['total_remaining_value'] += (float)($row['remaining_value'] ?? 0);
            $meta['total_donated_amount'] += (float)($row['donated_amount'] ?? 0);
        }
        $meta['total_purchased_amount'] = round($meta['total_purchased_amount'], 2);
        $meta['total_dispensed_amount'] = round($meta['total_dispensed_amount'], 2);
        $meta['total_donated_dispensed_amount'] = round($meta['total_donated_dispensed_amount'], 2);
        $meta['total_remaining_value'] = round($meta['total_remaining_value'], 2);
        $meta['total_donated_amount'] = round($meta['total_donated_amount'], 2);
        $meta['total_opening_amount'] = round($meta['total_opening_amount'], 2);
        $meta['total_quantity'] = $meta['total_dispensed_quantity'];
        $meta['total_amount'] = $meta['total_dispensed_amount'];
        $meta['total_available_quantity'] = $meta['total_remaining_quantity'];
        $meta['utilization_rate'] = $meta['total_purchased_amount'] > 0
            ? round(($meta['total_dispensed_amount'] / $meta['total_purchased_amount']) * 100, 2)
            : 0;
    } elseif ($report === 'procurement_references') {
        $title = 'PO / Contract / Batch / Lot Report';
        $rows = buildProcurementReferenceRows($conn, $itemMetas, $requestedWorkArea, $fromDate, $toDate, $stockSourceFilter);

        foreach ($rows as &$row) {
            $row['quantity'] = (int)round((float)($row['quantity'] ?? 0));
            $row['unit_cost'] = round((float)($row['unit_cost'] ?? 0), 2);
            $row['total_amount'] = round((float)($row['total_amount'] ?? 0), 2);
            $row['date_added'] = formatDateTimeValue($row['date_added'] ?? '');
            $row['donated'] = (int)($row['donated'] ?? 0) === 1 ? 'Yes' : 'No';

            $po = normalizeString($row['po_number'] ?? '');
            $contract = normalizeString($row['contract_number'] ?? '');
            $row['stock_source'] = getStockSourceRowLabel($row['is_opening'] ?? 0);
            if ((int)($row['is_opening'] ?? 0) === 1 && $po === '' && $contract === '') {
                // Hand-entered clinic stock never has a PO; it is not a missing reference.
                $row['reference_type'] = 'Opening stock (manual)';
                $row['reference_number'] = '';
            } elseif ($po !== '') {
                $row['reference_type'] = 'PO';
                $row['reference_number'] = $po;
            } elseif ($contract !== '') {
                $row['reference_type'] = 'Contract';
                $row['reference_number'] = $contract;
            } else {
                $row['reference_type'] = 'No PO/Contract';
                $row['reference_number'] = '';
            }
        }
        unset($row);

        $columns = [
            ['key' => 'stock_category', 'label' => 'Category'],
            ['key' => 'clinic', 'label' => 'Clinic'],
            ['key' => 'stock_source', 'label' => 'Source'],
            ['key' => 'date_added', 'label' => 'Date Added'],
            ['key' => 'reference_type', 'label' => 'Reference Type'],
            ['key' => 'reference_number', 'label' => 'Reference Number'],
            ['key' => 'po_number', 'label' => 'PO Number'],
            ['key' => 'contract_number', 'label' => 'Contract Number'],
            ['key' => 'batch_number', 'label' => 'Batch Number'],
            ['key' => 'lot_number', 'label' => 'Lot Number'],
            ['key' => 'barcode_number', 'label' => 'Barcode Number'],
            ['key' => 'item_name', 'label' => 'Item'],
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'entity', 'label' => 'Entity'],
            ['key' => 'quantity', 'label' => 'Quantity'],
            ['key' => 'unit_cost', 'label' => 'Unit Cost'],
            ['key' => 'total_amount', 'label' => 'Total Amount'],
            ['key' => 'donated', 'label' => 'Donated']
        ];

        $meta['total_quantity'] = 0;
        $meta['total_amount'] = 0;
        $meta['total_purchased_amount'] = 0;
        $meta['total_donated_amount'] = 0;
        $meta['with_po_count'] = 0;
        $meta['with_contract_count'] = 0;
        $meta['missing_reference_count'] = 0;
        $meta['opening_stock_count'] = 0;
        foreach ($rows as $row) {
            $meta['total_quantity'] += (int)($row['quantity'] ?? 0);
            $rowAmount = (float)($row['total_amount'] ?? 0);
            $meta['total_amount'] += $rowAmount;
            if (strtoupper(normalizeString($row['donated'] ?? '')) === 'YES') {
                $meta['total_donated_amount'] += $rowAmount;
            } else {
                $meta['total_purchased_amount'] += $rowAmount;
            }
            if ($row['reference_type'] === 'Opening stock (manual)') {
                $meta['opening_stock_count']++;
            } elseif (normalizeString($row['po_number'] ?? '') !== '') {
                $meta['with_po_count']++;
            } elseif (normalizeString($row['contract_number'] ?? '') !== '') {
                $meta['with_contract_count']++;
            } else {
                $meta['missing_reference_count']++;
            }
        }
        $meta['total_amount'] = round($meta['total_amount'], 2);
        $meta['total_purchased_amount'] = round($meta['total_purchased_amount'], 2);
        $meta['total_donated_amount'] = round($meta['total_donated_amount'], 2);
    } elseif ($report === 'stocks_available' || $report === 'low_stocks' || $report === 'near_expiries') {
        $titleMap = [
            'stocks_available' => 'Stocks Available Report',
            'low_stocks' => 'Low Stocks Report',
            'near_expiries' => 'Near Expiries Report'
        ];
        $title = $titleMap[$report];

        $stockRows = buildStockRows(
            $conn,
            $itemMetas,
            $checkoutMeta,
            $requestedWorkArea,
            $fromDate,
            $toDate,
            true,
            $stockSourceFilter
        );

        $filteredRows = [];

        if ($report === 'stocks_available') {
            foreach ($stockRows as $row) {
                $row['available_quantity'] = (int)round((float)($row['available_quantity'] ?? 0));
                if ($row['available_quantity'] > 0) {
                    $filteredRows[] = $row;
                }
            }
            usort($filteredRows, function ($a, $b) {
                $catCompare = strcasecmp((string)($a['stock_category'] ?? ''), (string)($b['stock_category'] ?? ''));
                if ($catCompare !== 0) {
                    return $catCompare;
                }
                return strcasecmp((string)($a['item_name'] ?? ''), (string)($b['item_name'] ?? ''));
            });
        } elseif ($report === 'low_stocks') {
            foreach ($stockRows as $row) {
                $row['available_quantity'] = (int)round((float)($row['available_quantity'] ?? 0));
                if ($row['available_quantity'] <= $lowStockThreshold) {
                    $filteredRows[] = $row;
                }
            }

            usort($filteredRows, function ($a, $b) {
                $aq = (int)($a['available_quantity'] ?? 0);
                $bq = (int)($b['available_quantity'] ?? 0);
                if ($aq === $bq) {
                    return strcasecmp((string)($a['item_name'] ?? ''), (string)($b['item_name'] ?? ''));
                }
                return $aq < $bq ? -1 : 1;
            });

            $meta['threshold'] = $lowStockThreshold;
        } else {
            $expiryFrom = $fromDate ?: date('Y-m-d');
            $expiryTo = $toDate ?: date('Y-m-d', strtotime('+90 days'));

            foreach ($stockRows as $row) {
                $available = (int)round((float)($row['available_quantity'] ?? 0));
                if ($available <= 0) {
                    continue;
                }

                $expiry = normalizeDateOnly($row['nearest_expiry_date'] ?? '');
                if (!$expiry || $expiry === false) {
                    continue;
                }
                if ($expiry < $expiryFrom || $expiry > $expiryTo) {
                    continue;
                }

                $row['available_quantity'] = $available;
                $row['nearest_expiry_date'] = $expiry;
                $row['days_to_expiry'] = (int)floor(
                    (strtotime($expiry . ' 00:00:00') - strtotime(date('Y-m-d 00:00:00'))) / 86400
                );
                $filteredRows[] = $row;
            }

            usort($filteredRows, function ($a, $b) {
                $da = (int)($a['days_to_expiry'] ?? 0);
                $db = (int)($b['days_to_expiry'] ?? 0);
                if ($da === $db) {
                    return strcasecmp((string)($a['item_name'] ?? ''), (string)($b['item_name'] ?? ''));
                }
                return $da < $db ? -1 : 1;
            });

            $meta['expiry_from'] = $expiryFrom;
            $meta['expiry_to'] = $expiryTo;
        }

        foreach ($filteredRows as &$row) {
            $row['unit_cost'] = (float)($row['unit_cost'] ?? 0);
            $row['total_stock_quantity'] = (int)round((float)($row['total_stock_quantity'] ?? 0));
            $row['total_checked_out_quantity'] = (int)round((float)($row['total_checked_out_quantity'] ?? 0));
            $row['available_quantity'] = (int)round((float)($row['available_quantity'] ?? 0));
            $row['nearest_expiry_date'] = formatDateValue($row['nearest_expiry_date'] ?? '');
            $row['latest_date_added'] = formatDateTimeValue($row['latest_date_added'] ?? '');
            $row['donated_quantity'] = (int)round((float)($row['donated_quantity'] ?? 0));
            $row['purchased_quantity'] = (int)round((float)($row['purchased_quantity'] ?? 0));
            $row['opening_quantity'] = (int)round((float)($row['opening_quantity'] ?? 0));
            $row['stock_source'] = getStockSourceRowLabel($row['is_opening'] ?? 0);
            $clinicName = normalizeString($row['stock_work_area'] ?? '');
            $row['clinic'] = $clinicName !== '' ? $clinicName : $requestedWorkArea;
        }
        unset($row);

        $rows = $filteredRows;
        if ($report === 'near_expiries') {
            $columns = [
                ['key' => 'stock_category', 'label' => 'Category'],
                ['key' => 'clinic', 'label' => 'Clinic'],
                ['key' => 'stock_source', 'label' => 'Source'],
                ['key' => 'barcode_number', 'label' => 'Barcode Number'],
                ['key' => 'item_name', 'label' => 'Item'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'entity', 'label' => 'Entity'],
                ['key' => 'available_quantity', 'label' => 'Available Qty'],
                ['key' => 'nearest_expiry_date', 'label' => 'Expiry Date'],
                ['key' => 'days_to_expiry', 'label' => 'Days To Expiry'],
                ['key' => 'latest_date_added', 'label' => 'Last Date Added']
            ];
        } else {
            $columns = [
                ['key' => 'stock_category', 'label' => 'Category'],
                ['key' => 'clinic', 'label' => 'Clinic'],
                ['key' => 'stock_source', 'label' => 'Source'],
                ['key' => 'barcode_number', 'label' => 'Barcode Number'],
                ['key' => 'item_name', 'label' => 'Item'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'entity', 'label' => 'Entity'],
                ['key' => 'total_stock_quantity', 'label' => 'Total Stock Qty'],
                ['key' => 'total_checked_out_quantity', 'label' => 'Checked Out Qty'],
                ['key' => 'available_quantity', 'label' => 'Available Qty'],
                ['key' => 'nearest_expiry_date', 'label' => 'Nearest Expiry'],
                ['key' => 'latest_date_added', 'label' => 'Last Date Added']
            ];
        }

        $meta['total_available_quantity'] = 0;
        foreach ($rows as $row) {
            $meta['total_available_quantity'] += (int)($row['available_quantity'] ?? 0);
        }
    } else {
        sendResponse('error', 'Invalid report type.', null, 400);
    }

    $data = [
        'report' => $report,
        'title' => $title,
        'columns' => $columns,
        'rows' => $rows
    ];

    $meta['row_count'] = count($rows);
    $meta['report'] = $report;
    if (in_array($report, $stockSourceReports, true)) {
        $meta['stock_source'] = $stockSourceFilter;
        $meta['stock_source_label'] = getStockSourceFilterLabel($stockSourceFilter);
    }
    $meta['work_area'] = $requestedWorkArea;
    $meta['from'] = $fromDate;
    $meta['to'] = $toDate;
    $meta['generated_at'] = date('Y-m-d H:i:s');

    if ($wantsXlsx) {
        outputReportXlsx($title, $columns, $rows, $meta);
    }

    sendResponse('success', 'Report generated successfully.', $data, 200, $meta);
} catch (Exception $e) {
    sendResponse('error', 'Failed to generate report: ' . $e->getMessage(), null, 500);
}
