<?php
// API endpoint for inventory dashboard data

require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

// Include database authentication functions
require_once 'db_auth.php';

function ensureIndex($conn, $table, $indexName, array $columns) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
          AND index_name = :index_name
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':index_name' => $indexName
    ]);

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $quotedColumns = array_map(static function ($column) {
        return '`' . str_replace('`', '``', (string)$column) . '`';
    }, $columns);

    $sql = sprintf(
        'ALTER TABLE `%s` ADD INDEX `%s` (%s)',
        str_replace('`', '``', $table),
        str_replace('`', '``', $indexName),
        implode(', ', $quotedColumns)
    );
    $conn->exec($sql);
}

function ensureDashboardIndexes($conn) {
    static $done = false;
    if ($done || !$conn) {
        return;
    }

    foreach ([
        'tbl_item_medicine',
        'tbl_item_medical_supplies',
        'tbl_item_vaccines',
        'tbl_item_lab_reagents'
    ] as $table) {
        $columns = getTableColumns($conn, $table);
        $available = array_flip($columns);

        if (isset($available['Barcode_Number'])) {
            ensureIndex($conn, $table, 'idx_dashboard_barcode_number', ['Barcode_Number']);
        }
        if (isset($available['ID'])) {
            ensureIndex($conn, $table, 'idx_dashboard_id', ['ID']);
        }
        if (isset($available['Expiry_Date'])) {
            ensureIndex($conn, $table, 'idx_dashboard_expiry', ['Expiry_Date']);
        }
        if (isset($available['Date_Added'])) {
            ensureIndex($conn, $table, 'idx_dashboard_date_added', ['Date_Added']);
        }
        if (isset($available['Work_Area']) && isset($available['isDeleted'])) {
            ensureIndex($conn, $table, 'idx_dashboard_work_area_deleted', ['Work_Area', 'isDeleted']);
        }
    }

    $checkedoutColumns = getTableColumns($conn, 'tbl_checkedout_items');
    $checkedoutAvailable = array_flip($checkedoutColumns);
    if (isset($checkedoutAvailable['Barcode'])) {
        ensureIndex($conn, 'tbl_checkedout_items', 'idx_dashboard_barcode', ['Barcode']);
    }
    if (isset($checkedoutAvailable['Work_Area']) && isset($checkedoutAvailable['isDeleted'])) {
        ensureIndex($conn, 'tbl_checkedout_items', 'idx_dashboard_work_area_deleted', ['Work_Area', 'isDeleted']);
    }

    $done = true;
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

function normalizeWorkArea($value, $default = 'CHO') {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return $default;
    }
    $value = preg_replace('/[^A-Z0-9 _-]/', '', $value);
    return $value !== '' ? $value : $default;
}

function sqlQuote($value) {
    return "'" . str_replace("'", "''", (string)$value) . "'";
}

function sqlId($identifier) {
    return '`' . str_replace('`', '``', (string)$identifier) . '`';
}

function buildWorkAreaCondition($alias, $column, $workArea) {
    if (!$column) {
        return '';
    }

    $qualified = $alias !== '' ? "{$alias}.{$column}" : $column;
    return " AND UPPER(COALESCE(NULLIF(TRIM({$qualified}), ''), 'CHO')) = " . sqlQuote($workArea);
}

function buildEmptyMonthlySpend($months) {
    $result = [];
    $cursor = new DateTime('first day of this month');
    $cursor->modify('-' . max(0, $months - 1) . ' months');
    for ($i = 0; $i < $months; $i++) {
        $result[] = [
            'month' => $cursor->format('Y-m'),
            'label' => $cursor->format('M Y'),
            'purchased' => 0,
            'donated' => 0
        ];
        $cursor->modify('+1 month');
    }
    return $result;
}

// Mimics the SQL barcode joins' utf8_general_ci match: case- and trailing-space-insensitive.
function dashboardBarcodeKey($barcode) {
    return strtolower(rtrim((string)$barcode));
}

/**
 * Checkout totals per barcode for the work area, from one scan of tbl_checkedout_items.
 * Low stock, most checked out and utilization used to join item tables to checkout
 * subqueries in SQL. Item barcodes are latin1 and checkout barcodes utf8, and MySQL 5.5
 * cannot index a derived table, so each join compared every item row with every barcode
 * group and took seconds. They now look totals up here by dashboardBarcodeKey().
 */
function getCheckoutTotalsByBarcode($conn, $workArea, $context) {
    static $cache = [];
    if (isset($cache[$workArea])) {
        return $cache[$workArea];
    }

    $quantity = 'co.' . sqlId($context['checkedout_quantity'] ?? 'Quantity');
    $where = '1=1';
    if (!empty($context['checkedout_deleted'])) {
        $where .= ' AND co.' . sqlId($context['checkedout_deleted']) . ' = 0';
    }
    $where .= buildWorkAreaCondition('co', $context['checkedout_work_area'] ?? null, $workArea);

    // Most checked out leaves out stock-count adjustments; dispensed value also leaves out transfers.
    $notAdjustment = '1=1';
    $released = '1=1';
    if (!empty($context['checkedout_transaction_type'])) {
        $type = 'co.' . sqlId($context['checkedout_transaction_type']);
        $notAdjustment = "UPPER(COALESCE({$type}, '')) <> 'ADJUSTMENT'";
        $released = "UPPER(COALESCE(NULLIF(TRIM({$type}), ''), 'CHECKOUT')) NOT IN ('TRANSFER', 'ADJUSTMENT')";
    } elseif (!empty($context['checkedout_ris'])) {
        $released = 'co.' . sqlId($context['checkedout_ris']) . " NOT LIKE 'TRF%'";
    }

    $stmt = $conn->prepare("
        SELECT co.Barcode AS barcode,
               COALESCE(SUM({$quantity}), 0) AS quantity_all,
               SUM(CASE WHEN {$notAdjustment} THEN 1 ELSE 0 END) AS count_not_adjustment,
               COALESCE(SUM(CASE WHEN {$notAdjustment} THEN {$quantity} ELSE 0 END), 0) AS quantity_not_adjustment,
               SUM(CASE WHEN {$released} THEN COALESCE({$quantity}, 0) ELSE 0 END) AS quantity_released
        FROM tbl_checkedout_items co
        WHERE {$where}
        GROUP BY co.Barcode
        ORDER BY NULL
    ");
    $stmt->execute();

    $totals = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = dashboardBarcodeKey($row['barcode']);
        $current = $totals[$key] ?? [
            'quantity_all' => 0,
            'count_not_adjustment' => 0,
            'quantity_not_adjustment' => 0,
            'quantity_released' => 0
        ];
        foreach ($current as $field => $value) {
            $current[$field] = $value + $row[$field];
        }
        $totals[$key] = $current;
    }

    $cache[$workArea] = $totals;
    return $totals;
}

// Validate token first
$userData = validateToken();
if (!$userData) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid or expired token."
    ]);
    exit;
}

// Connect to cho_inventory database for inventory data
$inventory_conn = null;
try {
    $inv_host = getenv('DB_HOST');
    $inv_dbname = getenv('DB_NAME');
    $inv_username = getenv('DB_USER');
    $inv_password = getenv('DB_PASS');

    if (!$inv_host || !$inv_dbname || !$inv_username) {
        throw new Exception("Database configuration missing from .env file");
    }

    $inventory_conn = new PDO("mysql:host=$inv_host;dbname=$inv_dbname;charset=utf8", $inv_username, $inv_password);
    $inventory_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $inventory_conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    ensureDashboardIndexes($inventory_conn);
} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed: " . $e->getMessage()
    ]);
    exit;
}

$itemWorkAreaColumns = [
    'tbl_item_medicine' => pickColumn(getTableColumns($inventory_conn, 'tbl_item_medicine'), ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
    'tbl_item_medical_supplies' => pickColumn(getTableColumns($inventory_conn, 'tbl_item_medical_supplies'), ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
    'tbl_item_vaccines' => pickColumn(getTableColumns($inventory_conn, 'tbl_item_vaccines'), ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
    'tbl_item_lab_reagents' => pickColumn(getTableColumns($inventory_conn, 'tbl_item_lab_reagents'), ['Work_Area', 'work_area', 'WorkArea', 'workarea'])
];
$checkedoutColumns = getTableColumns($inventory_conn, 'tbl_checkedout_items');
$checkedoutDeletedColumn = pickColumn($checkedoutColumns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
$checkedoutWorkAreaColumn = pickColumn($checkedoutColumns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
$checkedoutTransactionTypeColumn = pickColumn($checkedoutColumns, ['Transaction_Type', 'transaction_type']);
$checkedoutRisColumn = pickColumn($checkedoutColumns, ['RIS_Number', 'ris_number', 'Reference_No', 'reference_no']);
$checkedoutPiidColumn = pickColumn($checkedoutColumns, ['PIID', 'piid']);
$checkedoutBarangayColumn = pickColumn($checkedoutColumns, ['Barangay', 'barangay']);
$checkedoutCategoryColumn = pickColumn($checkedoutColumns, ['Category', 'category']);
$checkedoutQuantityColumn = pickColumn($checkedoutColumns, ['Quantity', 'quantity']);
$checkedoutCheckoutDateColumn = pickColumn($checkedoutColumns, ['Checkout_Date', 'checkout_date']);
$checkedoutConsultationDateColumn = pickColumn($checkedoutColumns, ['Date_Consultation', 'date_consultation', 'Consultation_Date', 'consultation_date']);
$checkedoutYakapMemberColumn = pickColumn($checkedoutColumns, ['Yakap_Member', 'yakap_member', 'Is_Yakap_Member', 'is_yakap_member']);

$queryContext = [
    'item_work_area' => $itemWorkAreaColumns,
    'checkedout_deleted' => $checkedoutDeletedColumn,
    'checkedout_work_area' => $checkedoutWorkAreaColumn,
    'checkedout_transaction_type' => $checkedoutTransactionTypeColumn,
    'checkedout_ris' => $checkedoutRisColumn,
    'checkedout_piid' => $checkedoutPiidColumn,
    'checkedout_barangay' => $checkedoutBarangayColumn,
    'checkedout_category' => $checkedoutCategoryColumn,
    'checkedout_quantity' => $checkedoutQuantityColumn,
    'checkedout_checkout_date' => $checkedoutCheckoutDateColumn,
    'checkedout_consultation_date' => $checkedoutConsultationDateColumn,
    'checkedout_yakap_member' => $checkedoutYakapMemberColumn
];

// Fetch data based on type parameter
$type = $_GET['type'] ?? 'all';
$months = isset($_GET['months']) ? (int)$_GET['months'] : 12;
if ($months <= 0) {
    $months = 12;
}
if ($months > 36) {
    $months = 36;
}

$userLocationWorkArea = normalizeWorkArea($userData['location'] ?? 'CHO');
$requestedWorkArea = normalizeWorkArea($_GET['work_area'] ?? $userLocationWorkArea);
if (!userCanAccessWorkArea($userData, $requestedWorkArea)) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "You are not allowed to access this work area."
    ]);
    exit;
}
requireApiCapability($userData, 'dashboard', $requestedWorkArea);

$response = [
    "status" => "success",
    "user" => $userData['username'],
    "data" => [
        'work_area' => $requestedWorkArea
    ]
];

try {
    switch ($type) {
        case 'low_stock':
            $response['data']['lowStockItems'] = getLowStockItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['lowStockCount'] = count($response['data']['lowStockItems']);
            break;

        case 'most_checked_out':
            $response['data']['mostCheckedOut'] = getMostCheckedOutItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['mostCheckedOutCount'] = count($response['data']['mostCheckedOut']);
            break;

        case 'near_expiry':
            $response['data']['expiryRed'] = getExpiryRedItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryGreen'] = getExpiryGreenItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryYellow'] = getExpiryYellowItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryOrange'] = getExpiryOrangeItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryRedCount'] = count($response['data']['expiryRed']);
            $response['data']['expiryGreenCount'] = count($response['data']['expiryGreen']);
            $response['data']['expiryYellowCount'] = count($response['data']['expiryYellow']);
            $response['data']['expiryOrangeCount'] = count($response['data']['expiryOrange']);
            break;

        case 'all':
        default:
            $response['data']['lowStockItems'] = getLowStockItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['mostCheckedOut'] = getMostCheckedOutItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryRed'] = getExpiryRedItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryGreen'] = getExpiryGreenItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryYellow'] = getExpiryYellowItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryOrange'] = getExpiryOrangeItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['monthlySpend'] = getMonthlySpend($inventory_conn, $months, $requestedWorkArea, $queryContext);
            $response['data']['utilizationSummary'] = getUtilizationSummary($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['demographicsSummary'] = getDemographicsSummary($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['lowStockCount'] = count($response['data']['lowStockItems']);
            $response['data']['mostCheckedOutCount'] = count($response['data']['mostCheckedOut']);
            $response['data']['expiryRedCount'] = count($response['data']['expiryRed']);
            $response['data']['expiryGreenCount'] = count($response['data']['expiryGreen']);
            $response['data']['expiryYellowCount'] = count($response['data']['expiryYellow']);
            $response['data']['expiryOrangeCount'] = count($response['data']['expiryOrange']);
            $response['data']['actionRequired'] = $response['data']['lowStockCount']
                + $response['data']['expiryRedCount']
                + $response['data']['expiryGreenCount']
                + $response['data']['expiryYellowCount']
                + $response['data']['expiryOrangeCount'];
            break;
    }

    echo json_encode($response);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Error fetching data: " . $e->getMessage()
    ]);
}

/**
 * Get Low Stock Items (less than 100 available)
 */
function getLowStockItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $checkoutTotals = getCheckoutTotalsByBarcode($conn, $workArea, $context);
    $categories = [
        'Medicine' => ['tbl_masterlist_medicine', 'tbl_item_medicine'],
        'Medical Supplies' => ['tbl_masterlist_medical_supplies', 'tbl_item_medical_supplies'],
        'Vaccines' => ['tbl_masterlist_vaccines', 'tbl_item_vaccines'],
        'Lab Reagents' => ['tbl_masterlist_lab_reagents', 'tbl_item_lab_reagents']
    ];

    $items = [];
    foreach ($categories as $category => [$masterTable, $itemTable]) {
        // Available = stock received minus every checkout of the same barcodes, per masterlist item.
        $availableById = [];
        $itemArea = buildWorkAreaCondition('', $itemWorkAreas[$itemTable] ?? null, $workArea);
        $stmt = $conn->prepare("SELECT ID, Barcode_Number, Quantity FROM {$itemTable} WHERE isDeleted = 0{$itemArea}");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $checkedOut = $row['Barcode_Number'] !== null
                ? ($checkoutTotals[dashboardBarcodeKey($row['Barcode_Number'])]['quantity_all'] ?? 0)
                : 0;
            $id = (string)$row['ID'];
            $availableById[$id] = ($availableById[$id] ?? 0) + (int)$row['Quantity'] - $checkedOut;
        }

        $stmt = $conn->prepare("SELECT ID, Item, Description FROM {$masterTable} WHERE isDeleted = 0");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $available = $availableById[(string)$row['ID']] ?? 0;
            if ($available < 100) {
                $items[] = [
                    'item' => $row['Item'],
                    'description' => $row['Description'],
                    'total_quantity' => $available,
                    'category' => $category
                ];
            }
        }
    }

    usort($items, static function ($a, $b) {
        return [$a['total_quantity'], strtolower((string)$a['item'])] <=> [$b['total_quantity'], strtolower((string)$b['item'])];
    });
    return $items;
}

/**
 * Get Most Checked Out Items (top 10 medicines by checkout count, adjustments excluded)
 */
function getMostCheckedOutItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $checkoutTotals = getCheckoutTotalsByBarcode($conn, $workArea, $context);
    $medicineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);

    $stmt = $conn->prepare("
        SELECT ml.id AS master_id, ml.item, ml.description, im.Barcode_Number AS barcode
        FROM tbl_item_medicine im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        WHERE im.isDeleted = 0{$medicineArea}
    ");
    $stmt->execute();

    $byMedicine = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (string)$row['master_id'];
        if (!isset($byMedicine[$id])) {
            // total_checked_out stays null when nothing was released, as SUM() over no rows was.
            $byMedicine[$id] = [
                'item' => $row['item'],
                'description' => $row['description'],
                'checkout_count' => 0,
                'total_checked_out' => null
            ];
        }
        $totals = $row['barcode'] !== null ? ($checkoutTotals[dashboardBarcodeKey($row['barcode'])] ?? null) : null;
        if ($totals && $totals['count_not_adjustment'] > 0) {
            $byMedicine[$id]['checkout_count'] += $totals['count_not_adjustment'];
            $byMedicine[$id]['total_checked_out'] = ($byMedicine[$id]['total_checked_out'] ?? 0) + $totals['quantity_not_adjustment'];
        }
    }

    $rows = array_values($byMedicine);
    usort($rows, static function ($a, $b) {
        return [$b['checkout_count'], $b['total_checked_out'] ?? -1] <=> [$a['checkout_count'], $a['total_checked_out'] ?? -1];
    });
    return array_slice($rows, 0, 10);
}

/**
 * Get Near Expiry RED Items (0-3 months)
 * Items that should be used soon - expiring within 3 months
 */
function formatStockCardLocation($row) {
    $parts = array_filter([
        $row['Location_Room'] ?? '',
        $row['Location_Cabinet'] ?? '',
        $row['Location_Shelf'] ?? '',
        $row['Location_Bin'] ?? '',
    ], fn($p) => trim($p) !== '');
    $location = implode(' / ', $parts);
    $note = trim($row['Location_Note'] ?? '');
    if ($note !== '') {
        $location = $location !== '' ? ($location . ' (' . $note . ')') : $note;
    }
    return $location;
}

function getExpiryRedItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $medicineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $labArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);
    $vaccineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);

    $query = "
        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_medicine im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= CURDATE()
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.quantity > 0{$medicineArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_lab_reagents im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= CURDATE()
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.quantity > 0{$labArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_vaccines im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= CURDATE()
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.quantity > 0{$vaccineArea}

        ORDER BY expiry_date ASC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['stock_card_location'] = formatStockCardLocation($row);
    }
    return $rows;
}

/**
 * Get Near Expiry GREEN Items (9-12 months)
 * Items expiring later this year - 9 to 12 months
 */
function getExpiryGreenItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $medicineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $labArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);
    $vaccineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);

    $query = "
        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_medicine im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
        AND im.quantity > 0{$medicineArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_lab_reagents im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
        AND im.quantity > 0{$labArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_vaccines im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
        AND im.quantity > 0{$vaccineArea}

        ORDER BY expiry_date ASC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['stock_card_location'] = formatStockCardLocation($row);
    }
    return $rows;
}

/**
 * Get Near Expiry YELLOW Items (3-6 months)
 * Items expiring in medium term - 3 to 6 months
 */
function getExpiryYellowItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $medicineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $labArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);
    $vaccineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);

    $query = "
        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_medicine im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.quantity > 0{$medicineArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_lab_reagents im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.quantity > 0{$labArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_vaccines im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.quantity > 0{$vaccineArea}

        ORDER BY expiry_date ASC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['stock_card_location'] = formatStockCardLocation($row);
    }
    return $rows;
}

/**
 * Get Near Expiry ORANGE Items (6-9 months)
 * Items expiring in medium term - 6 to 9 months
 */
function getExpiryOrangeItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $medicineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $labArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);
    $vaccineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);

    $query = "
        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_medicine im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.quantity > 0{$medicineArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_lab_reagents im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.quantity > 0{$labArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_vaccines im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.quantity > 0{$vaccineArea}

        ORDER BY expiry_date ASC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['stock_card_location'] = formatStockCardLocation($row);
    }
    return $rows;
}

/**
 * Get Monthly Spend vs Donated Value (last N months)
 */
function getMonthlySpend($conn, $months = 12, $workArea = 'CHO', $context = []) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $medicineArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $suppliesArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_medical_supplies'] ?? null, $workArea);
    $vaccineArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);
    $labArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);

    $stmt = $conn->prepare("
        SELECT ym,
               SUM(purchased_amount) AS purchased_amount,
               SUM(donated_amount) AS donated_amount
        FROM (
            SELECT DATE_FORMAT(Date_Added, '%Y-%m') AS ym,
                   SUM(CASE WHEN Donated = 0 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS purchased_amount,
                   SUM(CASE WHEN Donated = 1 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS donated_amount
            FROM tbl_item_medicine
            WHERE isDeleted = 0{$medicineArea} AND Date_Added IS NOT NULL
            GROUP BY ym

            UNION ALL

            SELECT DATE_FORMAT(Date_Added, '%Y-%m') AS ym,
                   SUM(CASE WHEN Donated = 0 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS purchased_amount,
                   SUM(CASE WHEN Donated = 1 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS donated_amount
            FROM tbl_item_medical_supplies
            WHERE isDeleted = 0{$suppliesArea} AND Date_Added IS NOT NULL
            GROUP BY ym

            UNION ALL

            SELECT DATE_FORMAT(Date_Added, '%Y-%m') AS ym,
                   SUM(CASE WHEN Donated = 0 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS purchased_amount,
                   SUM(CASE WHEN Donated = 1 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS donated_amount
            FROM tbl_item_vaccines
            WHERE isDeleted = 0{$vaccineArea} AND Date_Added IS NOT NULL
            GROUP BY ym

            UNION ALL

            SELECT DATE_FORMAT(Date_Added, '%Y-%m') AS ym,
                   SUM(CASE WHEN Donated = 0 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS purchased_amount,
                   SUM(CASE WHEN Donated = 1 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS donated_amount
            FROM tbl_item_lab_reagents
            WHERE isDeleted = 0{$labArea} AND Date_Added IS NOT NULL
            GROUP BY ym
        ) combined
        GROUP BY ym
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $byMonth = [];
    foreach ($rows as $row) {
        $monthKey = $row['ym'];
        $byMonth[$monthKey] = [
            'purchased' => (float)($row['purchased_amount'] ?? 0),
            'donated' => (float)($row['donated_amount'] ?? 0)
        ];
    }

    $result = [];
    $cursor = new DateTime('first day of this month');
    $cursor->modify('-' . max(0, $months - 1) . ' months');
    for ($i = 0; $i < $months; $i++) {
        $key = $cursor->format('Y-m');
        $label = $cursor->format('M Y');
        $values = $byMonth[$key] ?? ['purchased' => 0, 'donated' => 0];
        $result[] = [
            'month' => $key,
            'label' => $label,
            'purchased' => $values['purchased'],
            'donated' => $values['donated']
        ];
        $cursor->modify('+1 month');
    }

    return $result;
}

/**
 * Get procurement utilization value summary for the selected work area.
 */
function getUtilizationSummary($conn, $workArea = 'CHO', $context = []) {
    $itemTables = [
        'tbl_item_medicine',
        'tbl_item_medical_supplies',
        'tbl_item_vaccines',
        'tbl_item_lab_reagents'
    ];
    $itemWorkAreas = $context['item_work_area'] ?? [];

    $stockParts = [];
    $itemParts = [];
    foreach ($itemTables as $table) {
        $columns = getTableColumns($conn, $table);
        $barcodeCol = pickColumn($columns, ['Barcode_Number', 'barcode_number', 'Barcode', 'barcode']);
        $unitCostCol = pickColumn($columns, ['Unit_Cost', 'unit_cost', 'UnitCost']);
        $quantityCol = pickColumn($columns, ['Quantity', 'quantity']);
        $donatedCol = pickColumn($columns, ['Donated', 'donated']);
        $deletedCol = pickColumn($columns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);

        if (!$barcodeCol || !$quantityCol) {
            continue;
        }

        $workAreaCondition = buildWorkAreaCondition('', $itemWorkAreas[$table] ?? null, $workArea);
        $deletedCondition = $deletedCol ? " AND " . sqlId($deletedCol) . " = 0" : '';
        $unitCostExpr = $unitCostCol ? "COALESCE(" . sqlId($unitCostCol) . ", 0)" : '0';
        $donatedExpr = $donatedCol ? "COALESCE(" . sqlId($donatedCol) . ", 0)" : '0';

        $stockParts[] = "
            SELECT
                SUM(CASE WHEN {$donatedExpr} = 1 THEN 0 ELSE {$unitCostExpr} * COALESCE(" . sqlId($quantityCol) . ", 0) END) AS purchased_amount,
                SUM(CASE WHEN {$donatedExpr} = 1 THEN {$unitCostExpr} * COALESCE(" . sqlId($quantityCol) . ", 0) ELSE 0 END) AS donated_amount
            FROM " . sqlId($table) . "
            WHERE 1=1{$deletedCondition}{$workAreaCondition}
        ";

        $itemParts[] = "
            SELECT
                " . sqlId($barcodeCol) . " AS barcode,
                MAX({$unitCostExpr}) AS unit_cost
            FROM " . sqlId($table) . "
            WHERE 1=1{$deletedCondition}{$workAreaCondition}
            GROUP BY " . sqlId($barcodeCol) . "
        ";
    }

    if (empty($stockParts)) {
        return [
            'purchased_amount' => 0,
            'donated_amount' => 0,
            'dispensed_amount' => 0,
            'utilization_rate' => 0
        ];
    }

    $stockSql = "
        SELECT
            COALESCE(SUM(purchased_amount), 0) AS purchased_amount,
            COALESCE(SUM(donated_amount), 0) AS donated_amount
        FROM (
            " . implode("\nUNION ALL\n", $stockParts) . "
        ) stock_values
    ";
    $stockStmt = $conn->prepare($stockSql);
    $stockStmt->execute();
    $stockTotals = $stockStmt->fetch() ?: [];

    // Dispensed value = sum over barcodes of (quantity released x unit cost), joined in
    // PHP: MySQL 5.5 cannot index a derived table, so joining every checkout row to the
    // unit-cost subquery scanned the whole subquery per row and took over a minute.
    $unitCosts = [];
    $itemStmt = $conn->prepare(implode("\nUNION ALL\n", $itemParts));
    $itemStmt->execute();
    foreach ($itemStmt->fetchAll() as $row) {
        $key = dashboardBarcodeKey($row['barcode']);
        $unitCosts[$key] = max($unitCosts[$key] ?? 0.0, (float)$row['unit_cost']);
    }

    $dispensedAmount = 0.0;
    foreach (getCheckoutTotalsByBarcode($conn, $workArea, $context) as $key => $totals) {
        $dispensedAmount += (float)$totals['quantity_released'] * ($unitCosts[$key] ?? 0.0);
    }

    $purchasedAmount = (float)($stockTotals['purchased_amount'] ?? 0);
    $donatedAmount = (float)($stockTotals['donated_amount'] ?? 0);
    return [
        'purchased_amount' => round($purchasedAmount, 2),
        'donated_amount' => round($donatedAmount, 2),
        'dispensed_amount' => round($dispensedAmount, 2),
        'utilization_rate' => $purchasedAmount > 0 ? round(($dispensedAmount / $purchasedAmount) * 100, 2) : 0
    ];
}

/**
 * Get patient/recipient demographics from checkout records.
 */
function getDemographicsSummary($conn, $workArea = 'CHO', $context = []) {
    $piidCol = $context['checkedout_piid'] ?? null;
    $qtyCol = $context['checkedout_quantity'] ?? null;
    if (!$piidCol || !$qtyCol) {
        return [
            'total_recipients' => 0,
            'total_dispensed_quantity' => 0,
            'age_groups' => [],
            'sex' => [],
            'barangays' => [],
            'yakap' => ['members' => 0, 'non_members' => 0],
            'monthly_trend' => []
        ];
    }

    $personalColumns = getTableColumns($conn, 'tbl_personal_details');
    $personalPiid = pickColumn($personalColumns, ['PIID', 'piid']);
    $birthdateCol = pickColumn($personalColumns, ['Birthdate', 'birthdate', 'BirthDate', 'birth_date']);
    $sexCol = pickColumn($personalColumns, ['Sex', 'sex', 'Gender', 'gender']);
    $personalBarangayCol = pickColumn($personalColumns, ['Barangay', 'barangay']);

    $checkoutWhere = '1=1';
    if (!empty($context['checkedout_deleted'])) {
        $checkoutWhere .= ' AND co.' . sqlId($context['checkedout_deleted']) . ' = 0';
    }
    $checkoutWhere .= buildWorkAreaCondition('co', $context['checkedout_work_area'] ?? null, $workArea);
    if (!empty($context['checkedout_transaction_type'])) {
        $checkoutWhere .= " AND UPPER(COALESCE(NULLIF(TRIM(co." . sqlId($context['checkedout_transaction_type']) . "), ''), 'CHECKOUT')) NOT IN ('TRANSFER', 'ADJUSTMENT')";
    } elseif (!empty($context['checkedout_ris'])) {
        $checkoutWhere .= " AND co." . sqlId($context['checkedout_ris']) . " NOT LIKE 'TRF%'";
    }
    if (!empty($context['checkedout_category'])) {
        $checkoutWhere .= " AND UPPER(COALESCE(NULLIF(TRIM(co." . sqlId($context['checkedout_category']) . "), ''), 'INDIVIDUAL')) = 'INDIVIDUAL'";
    }

    // Checkout barangay first, then the patient's registered barangay.
    $checkoutBarangayExpr = !empty($context['checkedout_barangay'])
        ? "NULLIF(TRIM(co." . sqlId($context['checkedout_barangay']) . "), '')"
        : "NULL";
    $personalBarangayExpr = $personalBarangayCol ? "NULLIF(TRIM(pd." . sqlId($personalBarangayCol) . "), '')" : "''";
    $birthdateExpr = $birthdateCol ? "pd." . sqlId($birthdateCol) : "NULL";
    $sexExpr = $sexCol ? "COALESCE(NULLIF(TRIM(pd." . sqlId($sexCol) . "), ''), 'Unspecified')" : "'Unspecified'";
    $yakapExpr = !empty($context['checkedout_yakap_member'])
        ? "MAX(COALESCE(co." . sqlId($context['checkedout_yakap_member']) . ", 0))"
        : "0";
    $dateExpr = !empty($context['checkedout_consultation_date'])
        ? "COALESCE(co." . sqlId($context['checkedout_consultation_date']) . ", " . (!empty($context['checkedout_checkout_date']) ? "co." . sqlId($context['checkedout_checkout_date']) : "NULL") . ")"
        : (!empty($context['checkedout_checkout_date']) ? "co." . sqlId($context['checkedout_checkout_date']) : "NULL");
    $join = $personalPiid
        ? "LEFT JOIN tbl_personal_details pd ON pd." . sqlId($personalPiid) . " = agg.piid"
        : "";

    // Totals per patient and checkout barangay first, then one patient lookup per group.
    // Grouping the joined checkout rows by patient fields in one query built a large
    // on-disk temporary table on MySQL 5.5 and took seconds.
    $sql = "
        SELECT
            agg.piid,
            {$birthdateExpr} AS birthdate,
            {$sexExpr} AS sex,
            COALESCE(agg.checkout_barangay, {$personalBarangayExpr}) AS barangay,
            agg.yakap_member,
            agg.dispensed_quantity
        FROM (
            SELECT
                co." . sqlId($piidCol) . " AS piid,
                {$checkoutBarangayExpr} AS checkout_barangay,
                {$yakapExpr} AS yakap_member,
                SUM(COALESCE(co." . sqlId($qtyCol) . ", 0)) AS dispensed_quantity
            FROM tbl_checkedout_items co
            WHERE {$checkoutWhere}
              AND COALESCE(co." . sqlId($piidCol) . ", '') <> ''
            GROUP BY co." . sqlId($piidCol) . ", {$checkoutBarangayExpr}
            ORDER BY NULL
        ) agg
        {$join}
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();

    // A patient's checkouts with no barangay fall back to the registered one, so merge
    // them with checkouts that named that same barangay (one recipient, as before).
    $recipientRows = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = $row['piid'] . "\0" . mb_strtolower((string)$row['barangay']);
        if (!isset($recipientRows[$key])) {
            $recipientRows[$key] = $row;
            continue;
        }
        $recipientRows[$key]['yakap_member'] = max((int)$recipientRows[$key]['yakap_member'], (int)$row['yakap_member']);
        $recipientRows[$key]['dispensed_quantity'] += $row['dispensed_quantity'];
    }
    // Patient order decides the order of the sex chart's slices.
    $recipientRows = array_values($recipientRows);
    usort($recipientRows, static function ($a, $b) {
        return $a['piid'] <=> $b['piid'];
    });

    $ageGroups = [
        '0-5' => 0,
        '6-17' => 0,
        '18-59' => 0,
        '60+' => 0,
        'Unknown' => 0
    ];
    $sexCounts = [];
    $barangays = [];
    $yakapMembers = 0;
    $yakapNonMembers = 0;
    $totalDispensed = 0;

    foreach ($recipientRows as $row) {
        $totalDispensed += (int)($row['dispensed_quantity'] ?? 0);
        $sex = trim((string)($row['sex'] ?? ''));
        $sex = $sex !== '' ? ucwords(strtolower($sex)) : 'Unspecified';
        $sexCounts[$sex] = ($sexCounts[$sex] ?? 0) + 1;

        $barangay = trim((string)($row['barangay'] ?? ''));
        $barangay = $barangay !== '' ? $barangay : 'Unspecified';
        if (!isset($barangays[$barangay])) {
            $barangays[$barangay] = ['barangay' => $barangay, 'recipients' => 0, 'dispensed_quantity' => 0];
        }
        $barangays[$barangay]['recipients']++;
        $barangays[$barangay]['dispensed_quantity'] += (int)($row['dispensed_quantity'] ?? 0);

        if ((int)($row['yakap_member'] ?? 0) === 1) {
            $yakapMembers++;
        } else {
            $yakapNonMembers++;
        }

        $birthdate = trim((string)($row['birthdate'] ?? ''));
        $ageGroup = 'Unknown';
        if ($birthdate !== '') {
            $ts = strtotime($birthdate);
            if ($ts !== false) {
                $age = (int)floor((time() - $ts) / 31557600);
                if ($age <= 5) {
                    $ageGroup = '0-5';
                } elseif ($age <= 17) {
                    $ageGroup = '6-17';
                } elseif ($age <= 59) {
                    $ageGroup = '18-59';
                } else {
                    $ageGroup = '60+';
                }
            }
        }
        $ageGroups[$ageGroup]++;
    }

    $barangayRows = array_values($barangays);
    usort($barangayRows, function ($a, $b) {
        if ($a['recipients'] === $b['recipients']) {
            return strcasecmp($a['barangay'], $b['barangay']);
        }
        return $b['recipients'] <=> $a['recipients'];
    });
    $barangayRows = array_slice($barangayRows, 0, 8);

    $trend = [];
    if (!empty($context['checkedout_checkout_date']) || !empty($context['checkedout_consultation_date'])) {
        // DISTINCT in a subquery: COUNT(DISTINCT) grouped by a date expression is
        // very slow on MySQL 5.5.
        $trendSql = "
            SELECT ym, COUNT(*) AS recipients
            FROM (
                SELECT DISTINCT DATE_FORMAT({$dateExpr}, '%Y-%m') AS ym, co." . sqlId($piidCol) . " AS piid
                FROM tbl_checkedout_items co
                WHERE {$checkoutWhere}
                  AND co." . sqlId($piidCol) . " IS NOT NULL
                  AND {$dateExpr} IS NOT NULL
                  AND {$dateExpr} >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            ) monthly_recipients
            GROUP BY ym
            ORDER BY ym ASC
        ";
        $trendStmt = $conn->prepare($trendSql);
        $trendStmt->execute();
        foreach ($trendStmt->fetchAll() as $row) {
            $trend[] = [
                'month' => (string)($row['ym'] ?? ''),
                'recipients' => (int)($row['recipients'] ?? 0)
            ];
        }
    }

    return [
        'total_recipients' => count($recipientRows),
        'total_dispensed_quantity' => $totalDispensed,
        'age_groups' => array_map(fn($label, $count) => ['label' => $label, 'count' => $count], array_keys($ageGroups), $ageGroups),
        'sex' => array_map(fn($label, $count) => ['label' => $label, 'count' => $count], array_keys($sexCounts), $sexCounts),
        'barangays' => $barangayRows,
        'yakap' => [
            'members' => $yakapMembers,
            'non_members' => $yakapNonMembers
        ],
        'monthly_trend' => $trend
    ];
}
?>
