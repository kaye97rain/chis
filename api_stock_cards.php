<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';
require_once 'transaction_logger.php';
require_once 'stock_card_helpers.php';

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

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}
$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

if (!apiUserCan($userData, 'inventory_view')) {
    sendResponse('error', 'You are not allowed to access stock cards.', null, 403);
}

$payload = readPayload();
$action = stockCardNormalizeString($_GET['action'] ?? ($payload['action'] ?? 'list'));
$requestedWorkArea = stockCardNormalizeWorkArea($_GET['work_area'] ?? ($payload['work_area'] ?? ($userData['location'] ?? 'CHO')));
if (!userCanAccessWorkArea($userData, $requestedWorkArea)) {
    sendResponse('error', 'You are not allowed to access this work area.', null, 403);
}
$performedBy = stockCardNormalizeString($userData['username'] ?? 'system');

function stockCardPrepareModule($conn, $requestedWorkArea, $performedBy, $shouldSync = false) {
    stockCardEnsureSchema($conn);
    if ($shouldSync) {
        stockCardSyncAll($conn, $requestedWorkArea, $performedBy);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if ($action === 'location_suggestions') {
            stockCardPrepareModule($conn, $requestedWorkArea, $performedBy, false);
            $field = stockCardNormalizeString($_GET['field'] ?? '');
            $allowedFields = [
                'room' => 'Location_Room',
                'cabinet' => 'Location_Cabinet',
                'shelf' => 'Location_Shelf',
                'bin' => 'Location_Bin',
                'note' => 'Location_Note'
            ];
            if (!isset($allowedFields[$field])) {
                sendResponse('error', 'Invalid suggestion field.', null, 400);
            }

            $column = $allowedFields[$field];
            $where = ["Work_Area = :work_area", "{$column} IS NOT NULL", "TRIM({$column}) <> ''"];
            $params = [':work_area' => $requestedWorkArea];

            $room = stockCardNormalizeString($_GET['room'] ?? '');
            $cabinet = stockCardNormalizeString($_GET['cabinet'] ?? '');
            $shelf = stockCardNormalizeString($_GET['shelf'] ?? '');
            $bin = stockCardNormalizeString($_GET['bin'] ?? '');

            if ($room !== '') {
                $where[] = "Location_Room = :room";
                $params[':room'] = $room;
            }
            if ($cabinet !== '' && in_array($field, ['cabinet', 'shelf', 'bin', 'note'], true)) {
                $where[] = "Location_Cabinet = :cabinet";
                $params[':cabinet'] = $cabinet;
            }
            if ($shelf !== '' && in_array($field, ['shelf', 'bin', 'note'], true)) {
                $where[] = "Location_Shelf = :shelf";
                $params[':shelf'] = $shelf;
            }
            if ($bin !== '' && in_array($field, ['bin', 'note'], true)) {
                $where[] = "Location_Bin = :bin";
                $params[':bin'] = $bin;
            }

            $query = stockCardNormalizeString($_GET['query'] ?? '');
            if ($query !== '') {
                $where[] = "{$column} LIKE :query";
                $params[':query'] = '%' . $query . '%';
            }

            $stmt = $conn->prepare("
                SELECT DISTINCT {$column} AS value
                FROM tbl_stock_cards
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$column} ASC
                LIMIT 25
            ");
            $stmt->execute($params);
            $values = array_values(array_filter(array_map(static function ($row) {
                return stockCardNormalizeString($row['value'] ?? '');
            }, $stmt->fetchAll(PDO::FETCH_ASSOC))));

            sendResponse('success', 'Location suggestions loaded.', $values);
        }

        if ($action === 'details') {
            stockCardPrepareModule($conn, $requestedWorkArea, $performedBy, false);
            $cardId = (int)($_GET['card_id'] ?? 0);
            if ($cardId <= 0) {
                sendResponse('error', 'Stock card is required.', null, 400);
            }

            $card = stockCardGetCardById($conn, $cardId, $requestedWorkArea);
            if (!$card) {
                sendResponse('error', 'Stock card not found.', null, 404);
            }

            sendResponse('success', 'Stock card details loaded.', stockCardBuildDetail($conn, $card));
        }

        if ($action === 'item_details') {
            stockCardPrepareModule($conn, $requestedWorkArea, $performedBy, false);
            $categoryKey = stockCardNormalizeString($_GET['category'] ?? '');
            $itemId = (int)($_GET['item_id'] ?? 0);
            if ($categoryKey === '' || $itemId <= 0) {
                sendResponse('error', 'Category and item are required.', null, 400);
            }

            $card = stockCardGetCardByItem($conn, $categoryKey, $itemId, $requestedWorkArea);
            if (!$card) {
                stockCardSyncCategory($conn, $categoryKey, $requestedWorkArea, $performedBy);
                $card = stockCardGetCardByItem($conn, $categoryKey, $itemId, $requestedWorkArea);
            }
            if (!$card) {
                sendResponse('error', 'Stock card not found.', null, 404);
            }

            sendResponse('success', 'Stock card item details loaded.', stockCardBuildDetail($conn, $card));
        }

        if ($action === 'item_search') {
            stockCardPrepareModule($conn, $requestedWorkArea, $performedBy, false);
            $search = stockCardNormalizeString($_GET['search'] ?? '');
            if ($search === '' || strlen($search) < 2) {
                sendResponse('success', 'No search term.', [], 200, ['count' => 0]);
            }

            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
            if ($limit <= 0) {
                $limit = 12;
            }
            if ($limit > 25) {
                $limit = 25;
            }

            $stmt = $conn->prepare("
                SELECT SCID, Category_Key, Barcode_Number, Barcode_Value, Item_Name, Description, Current_Balance, Status
                FROM tbl_stock_cards
                WHERE Work_Area = :work_area
                  AND (
                    Item_Name LIKE :search
                    OR Description LIKE :search
                    OR Barcode_Number LIKE :search
                    OR Barcode_Value LIKE :search
                  )
                ORDER BY Status ASC, Item_Name ASC, Date_Added DESC, SCID DESC
                LIMIT {$limit}
            ");
            $stmt->execute([
                ':work_area' => $requestedWorkArea,
                ':search' => '%' . $search . '%'
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results = array_map(static function ($row) {
                return [
                    'card_id' => (int)($row['SCID'] ?? 0),
                    'category_key' => $row['Category_Key'] ?? '',
                    'barcode' => stockCardNormalizeString($row['Barcode_Number'] ?? '') !== '' ? $row['Barcode_Number'] : ($row['Barcode_Value'] ?? ''),
                    'item' => $row['Item_Name'] ?? '',
                    'description' => $row['Description'] ?? '',
                    'available' => (int)($row['Current_Balance'] ?? 0),
                    'status' => $row['Status'] ?? ''
                ];
            }, $rows);

            sendResponse('success', 'Stock card search results.', $results, 200, ['count' => count($results)]);
        }

        stockCardPrepareModule($conn, $requestedWorkArea, $performedBy, true);
        $search = stockCardNormalizeString($_GET['search'] ?? '');
        $categoryKey = stockCardNormalizeString($_GET['category'] ?? 'all');
        $status = strtoupper(stockCardNormalizeString($_GET['status'] ?? 'ACTIVE'));
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

        $where = ['Work_Area = :work_area'];
        $params = [':work_area' => $requestedWorkArea];
        if ($categoryKey !== '' && $categoryKey !== 'all') {
            $where[] = 'Category_Key = :category_key';
            $params[':category_key'] = $categoryKey;
        }
        if ($status !== '' && $status !== 'ALL') {
            $where[] = 'Status = :status';
            $params[':status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(Item_Name LIKE :search OR Barcode_Number LIKE :search OR Barcode_Value LIKE :search OR Description LIKE :search OR Location_Room LIKE :search OR Location_Cabinet LIKE :search OR Location_Shelf LIKE :search OR Location_Bin LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }
        foreach ([
            'room' => 'Location_Room',
            'cabinet' => 'Location_Cabinet',
            'shelf' => 'Location_Shelf',
            'bin' => 'Location_Bin',
            'note' => 'Location_Note'
        ] as $paramKey => $column) {
            $value = stockCardNormalizeString($_GET[$paramKey] ?? '');
            if ($value !== '') {
                $where[] = "{$column} = :{$paramKey}";
                $params[":{$paramKey}"] = $value;
            }
        }

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM tbl_stock_cards WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT *
            FROM tbl_stock_cards
            WHERE " . implode(' AND ', $where) . "
            ORDER BY Status ASC, Item_Name ASC, Date_Added DESC, SCID DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['location_display'] = stockCardFormatLocationParts(
                $row['Location_Room'] ?? '',
                $row['Location_Cabinet'] ?? '',
                $row['Location_Shelf'] ?? '',
                $row['Location_Bin'] ?? '',
                $row['Location_Note'] ?? ''
            );
        }
        unset($row);

        sendResponse('success', 'Stock cards loaded.', $rows, 200, [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'work_area' => $requestedWorkArea
        ]);
    } catch (Exception $e) {
        sendResponse('error', 'Unable to load stock cards: ' . $e->getMessage(), null, 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Invalid request method.', null, 405);
}

if (!userCanWrite($userData)) {
    sendResponse('error', 'This role has view-only access.', null, 403);
}

try {
    if ($action === 'sync_cards') {
        stockCardPrepareModule($conn, $requestedWorkArea, $performedBy, false);
        sendResponse('success', 'Stock cards synced.', ['synced' => stockCardSyncAll($conn, $requestedWorkArea, $performedBy)]);
    }

    stockCardPrepareModule($conn, $requestedWorkArea, $performedBy, false);

    if ($action === 'bulk_move_cards') {
        stockCardSyncAll($conn, $requestedWorkArea, $performedBy);

        $room = stockCardNormalizeString($payload['location_room'] ?? '');
        $cabinet = stockCardNormalizeString($payload['location_cabinet'] ?? '');
        $shelf = stockCardNormalizeString($payload['location_shelf'] ?? '');
        $bin = stockCardNormalizeString($payload['location_bin'] ?? '');
        $note = stockCardNormalizeString($payload['location_note'] ?? '');
        if ($room === '' && $cabinet === '' && $shelf === '' && $bin === '' && $note === '') {
            sendResponse('error', 'Provide at least one location field.', null, 400);
        }

        $cardIds = [];
        if (isset($payload['card_ids']) && is_array($payload['card_ids'])) {
            foreach ($payload['card_ids'] as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $cardIds[$id] = true;
                }
            }
        }

        $barcodes = [];
        if (isset($payload['barcodes']) && is_array($payload['barcodes'])) {
            foreach ($payload['barcodes'] as $barcode) {
                $barcode = stockCardNormalizeString($barcode);
                if ($barcode !== '') {
                    $barcodes[$barcode] = true;
                }
            }
        }

        if (empty($cardIds) && empty($barcodes)) {
            sendResponse('error', 'Select stock cards or enter barcode numbers.', null, 400);
        }

        $whereParts = ['Work_Area = :work_area'];
        $params = [':work_area' => $requestedWorkArea];
        $matchParts = [];

        if (!empty($cardIds)) {
            $idParams = [];
            foreach (array_keys($cardIds) as $index => $id) {
                $param = ':card_id_' . $index;
                $idParams[] = $param;
                $params[$param] = $id;
            }
            $matchParts[] = 'SCID IN (' . implode(', ', $idParams) . ')';
        }

        if (!empty($barcodes)) {
            $barcodeNumberParams = [];
            $barcodeValueParams = [];
            foreach (array_keys($barcodes) as $index => $barcode) {
                $numberParam = ':barcode_number_' . $index;
                $valueParam = ':barcode_value_' . $index;
                $barcodeNumberParams[] = $numberParam;
                $barcodeValueParams[] = $valueParam;
                $params[$numberParam] = $barcode;
                $params[$valueParam] = $barcode;
            }
            $matchParts[] = '(Barcode_Number IN (' . implode(', ', $barcodeNumberParams) . ') OR Barcode_Value IN (' . implode(', ', $barcodeValueParams) . '))';
        }

        $whereParts[] = '(' . implode(' OR ', $matchParts) . ')';
        $stmt = $conn->prepare("SELECT * FROM tbl_stock_cards WHERE " . implode(' AND ', $whereParts));
        $stmt->execute($params);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cards)) {
            sendResponse('error', 'No matching stock cards found.', null, 404);
        }

        $toLocation = stockCardFormatLocationParts($room, $cabinet, $shelf, $bin, $note);
        $updateStmt = $conn->prepare("
            UPDATE tbl_stock_cards
            SET Location_Room = :room,
                Location_Cabinet = :cabinet,
                Location_Shelf = :shelf,
                Location_Bin = :bin,
                Location_Note = :note,
                Updated_At = NOW()
            WHERE SCID = :card_id
        ");

        $updated = [];
        foreach ($cards as $card) {
            $cardId = (int)($card['SCID'] ?? 0);
            if ($cardId <= 0) {
                continue;
            }

            $fromLocation = stockCardFormatLocationParts(
                $card['Location_Room'] ?? '',
                $card['Location_Cabinet'] ?? '',
                $card['Location_Shelf'] ?? '',
                $card['Location_Bin'] ?? '',
                $card['Location_Note'] ?? ''
            );

            $updateStmt->execute([
                ':room' => $room !== '' ? $room : null,
                ':cabinet' => $cabinet !== '' ? $cabinet : null,
                ':shelf' => $shelf !== '' ? $shelf : null,
                ':bin' => $bin !== '' ? $bin : null,
                ':note' => $note !== '' ? $note : null,
                ':card_id' => $cardId
            ]);

            stockCardInsertHistory($conn, $cardId, 'MOVE', $performedBy, [
                'from_location' => $fromLocation,
                'to_location' => $toLocation,
                'balance_after' => (int)($card['Current_Balance'] ?? 0),
                'remarks' => stockCardNormalizeString($payload['remarks'] ?? '')
            ]);

            logTransactionHistory($conn, [
                'module' => 'STOCK_CARD',
                'action' => 'BULK_MOVE',
                'transaction_type' => 'MOVE',
                'category' => $card['Category_Key'] ?? '',
                'reference_no' => 'SC-' . $cardId,
                'item_barcode' => $card['Barcode_Number'] ?? '',
                'item_name' => $card['Item_Name'] ?? '',
                'quantity' => (int)($card['Current_Balance'] ?? 0),
                'details' => ['from_location' => $fromLocation, 'to_location' => $toLocation, 'remarks' => stockCardNormalizeString($payload['remarks'] ?? '')],
                'location' => $toLocation,
                'work_area' => $requestedWorkArea,
                'performed_by' => $performedBy
            ]);

            $updated[] = [
                'card_id' => $cardId,
                'barcode' => $card['Barcode_Number'] ?? '',
                'item' => $card['Item_Name'] ?? ''
            ];
        }

        sendResponse('success', 'Stock card locations updated.', ['updated' => $updated, 'count' => count($updated)]);
    }

    $cardId = (int)($payload['card_id'] ?? 0);
    if ($cardId <= 0) {
        sendResponse('error', 'Stock card is required.', null, 400);
    }

    $card = stockCardGetCardById($conn, $cardId, $requestedWorkArea);
    if (!$card) {
        sendResponse('error', 'Stock card not found.', null, 404);
    }

    if ($action === 'move_card') {
        $room = stockCardNormalizeString($payload['location_room'] ?? '');
        $cabinet = stockCardNormalizeString($payload['location_cabinet'] ?? '');
        $shelf = stockCardNormalizeString($payload['location_shelf'] ?? '');
        $bin = stockCardNormalizeString($payload['location_bin'] ?? '');
        $note = stockCardNormalizeString($payload['location_note'] ?? '');
        if ($room === '' && $cabinet === '' && $shelf === '' && $bin === '' && $note === '') {
            sendResponse('error', 'Provide at least one location field.', null, 400);
        }

        $fromLocation = stockCardFormatLocationParts(
            $card['Location_Room'] ?? '',
            $card['Location_Cabinet'] ?? '',
            $card['Location_Shelf'] ?? '',
            $card['Location_Bin'] ?? '',
            $card['Location_Note'] ?? ''
        );
        $toLocation = stockCardFormatLocationParts($room, $cabinet, $shelf, $bin, $note);

        $stmt = $conn->prepare("
            UPDATE tbl_stock_cards
            SET Location_Room = :room,
                Location_Cabinet = :cabinet,
                Location_Shelf = :shelf,
                Location_Bin = :bin,
                Location_Note = :note,
                Updated_At = NOW()
            WHERE SCID = :card_id
        ");
        $stmt->execute([
            ':room' => $room !== '' ? $room : null,
            ':cabinet' => $cabinet !== '' ? $cabinet : null,
            ':shelf' => $shelf !== '' ? $shelf : null,
            ':bin' => $bin !== '' ? $bin : null,
            ':note' => $note !== '' ? $note : null,
            ':card_id' => $cardId
        ]);

        stockCardInsertHistory($conn, $cardId, 'MOVE', $performedBy, [
            'from_location' => $fromLocation,
            'to_location' => $toLocation,
            'balance_after' => (int)($card['Current_Balance'] ?? 0),
            'remarks' => stockCardNormalizeString($payload['remarks'] ?? '')
        ]);

        logTransactionHistory($conn, [
            'module' => 'STOCK_CARD',
            'action' => 'MOVE',
            'transaction_type' => 'MOVE',
            'category' => $card['Category_Key'] ?? '',
            'reference_no' => 'SC-' . $cardId,
            'item_barcode' => $card['Barcode_Number'] ?? '',
            'item_name' => $card['Item_Name'] ?? '',
            'quantity' => (int)($card['Current_Balance'] ?? 0),
            'details' => ['from_location' => $fromLocation, 'to_location' => $toLocation, 'remarks' => stockCardNormalizeString($payload['remarks'] ?? '')],
            'location' => $toLocation,
            'work_area' => $requestedWorkArea,
            'performed_by' => $performedBy
        ]);

        sendResponse('success', 'Stock card location updated.');
    }

    if ($action === 'close_card') {
        $status = strtoupper(stockCardNormalizeString($payload['status'] ?? 'CONSUMED'));
        if (!in_array($status, ['CONSUMED', 'CLOSED', 'REPLACED'], true)) {
            $status = 'CONSUMED';
        }

        $stmt = $conn->prepare("
            UPDATE tbl_stock_cards
            SET Status = :status,
                Closed_At = NOW(),
                Closed_By = :closed_by,
                Updated_At = NOW()
            WHERE SCID = :card_id
        ");
        $stmt->execute([
            ':status' => $status,
            ':closed_by' => $performedBy,
            ':card_id' => $cardId
        ]);

        stockCardInsertHistory($conn, $cardId, $status, $performedBy, [
            'balance_after' => (int)($card['Current_Balance'] ?? 0),
            'remarks' => stockCardNormalizeString($payload['remarks'] ?? '')
        ]);
        sendResponse('success', 'Stock card closed.');
    }

    if ($action === 'reopen_card') {
        $stmt = $conn->prepare("
            UPDATE tbl_stock_cards
            SET Status = 'ACTIVE',
                Closed_At = NULL,
                Closed_By = NULL,
                Updated_At = NOW()
            WHERE SCID = :card_id
        ");
        $stmt->execute([':card_id' => $cardId]);

        stockCardInsertHistory($conn, $cardId, 'REOPEN', $performedBy, [
            'balance_after' => (int)($card['Current_Balance'] ?? 0),
            'remarks' => stockCardNormalizeString($payload['remarks'] ?? '')
        ]);
        sendResponse('success', 'Stock card reopened.');
    }

    sendResponse('error', 'Unknown action.', null, 400);
} catch (Exception $e) {
    sendResponse('error', 'Unable to update stock card: ' . $e->getMessage(), null, 500);
}
