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
    $stmt = $conn->query("DESCRIBE {$table}");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $columns = [];
    foreach ($rows as $row) {
        if (isset($row['Field'])) {
            $columns[] = $row['Field'];
        }
    }
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

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}

$categories = [
    'medicine' => [
        'master_table' => 'tbl_masterlist_medicine',
        'item_table' => 'tbl_item_medicine',
        'master_added_by' => 'Added_by',
        'master_is_deleted' => 'isdeleted'
    ],
    'medical_supplies' => [
        'master_table' => 'tbl_masterlist_medical_supplies',
        'item_table' => 'tbl_item_medical_supplies',
        'master_added_by' => 'Added_By',
        'master_is_deleted' => 'isdeleted'
    ],
    'vaccines' => [
        'master_table' => 'tbl_masterlist_vaccines',
        'item_table' => 'tbl_item_vaccines',
        'master_added_by' => 'Added_by',
        'master_is_deleted' => 'isdeleted'
    ],
    'lab_reagents' => [
        'master_table' => 'tbl_masterlist_lab_reagents',
        'item_table' => 'tbl_item_lab_reagents',
        'master_added_by' => 'Added_by',
        'master_is_deleted' => 'isDeleted'
    ]
];

$payload = [];
$rawInput = file_get_contents("php://input");
if ($rawInput !== '') {
    $json = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $payload = $json;
    } elseif (!empty($_POST)) {
        $payload = $_POST;
    } else {
        sendResponse('error', 'Invalid JSON format.', null, 400);
    }
} else {
    $payload = $_POST;
}

$categoryKey = $_GET['category'] ?? ($payload['category'] ?? '');
if (!isset($categories[$categoryKey])) {
    sendResponse('error', 'Invalid category.', null, 400);
}

$category = $categories[$categoryKey];
$masterTable = $category['master_table'];
$itemTable = $category['item_table'];
$masterAddedBy = $category['master_added_by'];
$masterIsDeleted = $category['master_is_deleted'];
$itemColumns = getTableColumns($conn, $itemTable);
$itemWorkAreaColumn = pickColumn($itemColumns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
$checkedoutColumns = getTableColumns($conn, 'tbl_checkedout_items');
$checkedoutDeletedColumn = pickColumn($checkedoutColumns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
$checkedoutWorkAreaColumn = pickColumn($checkedoutColumns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
$userLocationWorkArea = normalizeWorkArea($userData['location'] ?? 'CHO');
$requestedWorkArea = normalizeWorkArea($_GET['work_area'] ?? ($payload['work_area'] ?? $userLocationWorkArea));
if (!userCanAccessWorkArea($userData, $requestedWorkArea)) {
    sendResponse('error', 'You are not allowed to access this work area.', null, 403);
}
requireApiCapability(
    $userData,
    $_SERVER['REQUEST_METHOD'] === 'GET' ? 'inventory_view' : 'inventory_write',
    $requestedWorkArea
);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $search = trim($_GET['search'] ?? '');
        $searchField = trim($_GET['search_field'] ?? '');
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        if ($limit <= 0) {
            $limit = 100;
        }
        if ($limit > 500) {
            $limit = 500;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        $allowedFields = [
            'Item' => 'Item',
            'Description' => 'Description',
            'Entity' => 'Entity',
            'Added_By' => $masterAddedBy
        ];

        $where = "ml.{$masterIsDeleted} = 0";
        $params = [];
        if ($search !== '') {
            if ($searchField !== '' && $searchField !== 'all' && isset($allowedFields[$searchField])) {
                $column = $allowedFields[$searchField];
                $where .= " AND {$column} LIKE :search";
            } else {
                $where .= " AND (Item LIKE :search OR Description LIKE :search OR Entity LIKE :search OR {$masterAddedBy} LIKE :search)";
            }
            $params[':search'] = '%' . $search . '%';
        }

        $masterScopeFilter = '';
        if ($requestedWorkArea !== 'CHO' && $itemWorkAreaColumn) {
            $masterScopeFilter = "
                AND EXISTS (
                    SELECT 1
                    FROM {$itemTable} scope_item
                    WHERE scope_item.ID = ml.ID
                      AND scope_item.isDeleted = 0
                      AND UPPER(COALESCE(NULLIF(TRIM(scope_item.{$itemWorkAreaColumn}), ''), 'CHO')) = :work_area_master_scope
                )
            ";
            $params[':work_area_master_scope'] = $requestedWorkArea;
        }

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM {$masterTable} ml WHERE {$where} {$masterScopeFilter}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stockWhere = "isDeleted = 0";
        if ($itemWorkAreaColumn) {
            $stockWhere .= " AND UPPER(COALESCE(NULLIF(TRIM({$itemWorkAreaColumn}), ''), 'CHO')) = :work_area_stock";
            $params[':work_area_stock'] = $requestedWorkArea;
        }

        $checkoutWhere = "im.isDeleted = 0";
        if ($itemWorkAreaColumn) {
            $checkoutWhere .= " AND UPPER(COALESCE(NULLIF(TRIM(im.{$itemWorkAreaColumn}), ''), 'CHO')) = :work_area_checkout_item";
            $params[':work_area_checkout_item'] = $requestedWorkArea;
        }
        if ($checkedoutWorkAreaColumn) {
            $checkoutWhere .= " AND UPPER(COALESCE(NULLIF(TRIM(tc.{$checkedoutWorkAreaColumn}), ''), 'CHO')) = :work_area_checkout_tx";
            $params[':work_area_checkout_tx'] = $requestedWorkArea;
        }
        $checkoutDeletedFilter = $checkedoutDeletedColumn ? "AND tc.{$checkedoutDeletedColumn} = 0" : '';

        $stmt = $conn->prepare("
            SELECT
                ml.ID,
                ml.Item,
                ml.Description,
                ml.Entity,
                ml.{$masterAddedBy} AS Added_By,
                ml.Date_Updated,
                COALESCE(stock.total_quantity, 0) AS Total_Quantity,
                COALESCE(stock.total_quantity, 0) - COALESCE(checkout.total_checked_out, 0) AS Available_Quantity
            FROM {$masterTable} ml
            LEFT JOIN (
                SELECT ID, SUM(Quantity) AS total_quantity
                FROM {$itemTable}
                WHERE {$stockWhere}
                GROUP BY ID
            ) stock ON stock.ID = ml.ID
            LEFT JOIN (
                SELECT im.ID, SUM(tc.Quantity) AS total_checked_out
                FROM {$itemTable} im
                INNER JOIN tbl_checkedout_items tc
                    ON im.Barcode_Number = tc.Barcode
                    {$checkoutDeletedFilter}
                WHERE {$checkoutWhere}
                GROUP BY im.ID
            ) checkout ON checkout.ID = ml.ID
            WHERE {$where}
            {$masterScopeFilter}
            ORDER BY ml.Item ASC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse('success', 'Masterlist retrieved.', $rows, 200, [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'search' => $search,
            'search_field' => $searchField,
            'work_area' => $requestedWorkArea
        ]);
    } catch (Exception $e) {
        sendResponse('error', 'Error fetching masterlist: ' . $e->getMessage(), null, 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Invalid request method.', null, 405);
}

if (!userCanWrite($userData)) {
    sendResponse('error', 'This role has view-only access.', null, 403);
}

$action = $payload['action'] ?? '';

if ($requestedWorkArea !== 'CHO') {
    sendResponse('error', 'Inventory module is available only for CHO location.', null, 403);
}

try {
    switch ($action) {
        case 'create_masterlist':
            $itemName = trim($payload['item_name'] ?? '');
            $description = trim($payload['description'] ?? '');
            $entity = trim($payload['entity'] ?? '');

            if ($itemName === '') {
                sendResponse('error', 'Item name is required.', null, 400);
            }

            $stmt = $conn->prepare("
                INSERT INTO {$masterTable} (Item, Description, Entity, {$masterAddedBy})
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$itemName, $description, $entity, $userData['username']]);
            $createdId = $conn->lastInsertId();
            logTransactionHistory($conn, [
                'module' => 'MASTERLIST',
                'action' => 'CREATE',
                'transaction_type' => 'ADDED',
                'category' => $categoryKey,
                'reference_no' => $createdId !== '' ? ('MASTERLIST-' . $createdId) : '',
                'item_name' => $itemName,
                'performed_by' => $userData['username'] ?? 'system',
                'location' => $userData['location'] ?? '',
                'work_area' => $payload['work_area'] ?? 'CHO',
                'details' => [
                    'description' => $description,
                    'entity' => $entity
                ]
            ]);
            sendResponse('success', 'Masterlist item added successfully.');

        case 'update_masterlist':
            $masterId = (int)($payload['master_id'] ?? 0);
            $itemName = trim($payload['item_name'] ?? '');
            $description = trim($payload['description'] ?? '');
            $entity = trim($payload['entity'] ?? '');

            if ($masterId <= 0 || $itemName === '') {
                sendResponse('error', 'Invalid masterlist update request.', null, 400);
            }

            $stmt = $conn->prepare("
                UPDATE {$masterTable}
                SET Item = ?, Description = ?, Entity = ?, Date_Updated = NOW()
                WHERE ID = ?
            ");
            $stmt->execute([$itemName, $description, $entity, $masterId]);
            logTransactionHistory($conn, [
                'module' => 'MASTERLIST',
                'action' => 'UPDATE',
                'transaction_type' => 'UPDATED',
                'category' => $categoryKey,
                'reference_no' => 'MASTERLIST-' . $masterId,
                'item_name' => $itemName,
                'performed_by' => $userData['username'] ?? 'system',
                'location' => $userData['location'] ?? '',
                'work_area' => $payload['work_area'] ?? 'CHO',
                'details' => [
                    'description' => $description,
                    'entity' => $entity
                ]
            ]);
            sendResponse('success', 'Masterlist item updated.');

        case 'delete_masterlist':
            $masterId = (int)($payload['master_id'] ?? 0);
            if ($masterId <= 0) {
                sendResponse('error', 'Invalid masterlist deletion request.', null, 400);
            }

            $stmt = $conn->prepare("
                UPDATE {$masterTable}
                SET {$masterIsDeleted} = 1, Date_Updated = NOW()
                WHERE ID = ?
            ");
            $stmt->execute([$masterId]);
            logTransactionHistory($conn, [
                'module' => 'MASTERLIST',
                'action' => 'DELETE',
                'transaction_type' => 'ARCHIVED',
                'category' => $categoryKey,
                'reference_no' => 'MASTERLIST-' . $masterId,
                'performed_by' => $userData['username'] ?? 'system',
                'location' => $userData['location'] ?? '',
                'work_area' => $payload['work_area'] ?? 'CHO'
            ]);
            sendResponse('success', 'Masterlist item archived.');

        default:
            sendResponse('error', 'Unknown action.', null, 400);
    }
} catch (Exception $e) {
    sendResponse('error', 'Error processing request: ' . $e->getMessage(), null, 500);
}
