<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';
require_once 'transaction_logger.php';
require_once __DIR__ . '/stock_workflow_helpers.php';

/**
 * Transfer receipts: the destination clinic confirms what arrived from a CHO transfer.
 *
 * GET  ?action=list&work_area=AGLAYAN|UPLC|LABORATORY|ALL&status=PENDING|RECEIVED|ALL
 *      ALL is the CHO view across destinations (Stocks Distribution access).
 * POST {action: 'receive', transfer_number, items: [{barcode, received_quantity}], remarks}
 *      Records received quantities. Stock does not move here; shortages are corrected
 *      through a physical count.
 */

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

function normalizeReceiptWorkArea($value) {
    $value = strtoupper(trim((string)$value));
    return preg_replace('/[^A-Z0-9 _-]/', '', $value);
}

/**
 * Whether the user may see (and, with $forWrite, confirm) receipts for a destination.
 */
function canUseReceiptWorkArea($userData, $workArea, $forWrite) {
    if ($workArea === 'ALL') {
        return !$forWrite && apiUserCan($userData, 'clinics');
    }
    if (!in_array($workArea, TRANSFER_RECEIPT_WORK_AREAS, true) || !userCanAccessWorkArea($userData, $workArea)) {
        return false;
    }
    if ($forWrite && !userCanWrite($userData)) {
        return false;
    }
    return apiUserCan($userData, 'checkout', $workArea)
        || apiUserCan($userData, 'inventory_view', $workArea)
        || apiUserCan($userData, 'clinics');
}

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}
if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}
if (!ensureTransferReceiptsTable($conn)) {
    sendResponse('error', 'Transfer receipts table is unavailable.', null, 500);
}

// A transfer counts as voided once none of its ledger rows are active.
$activeTransferSql = "EXISTS (
    SELECT 1 FROM tbl_checkedout_items tc
    WHERE tc.RIS_Number = r.transfer_number AND COALESCE(tc.isDeleted, 0) = 0
)";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim((string)($_GET['action'] ?? 'list'));
    if ($action !== 'list') {
        sendResponse('error', 'Unknown action.', null, 400);
    }

    $workArea = normalizeReceiptWorkArea($_GET['work_area'] ?? ($userData['location'] ?? ''));
    if (!canUseReceiptWorkArea($userData, $workArea, false)) {
        sendResponse('error', 'You are not allowed to view transfer receipts for this location.', null, 403);
    }

    $statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'PENDING')));
    if (!in_array($statusFilter, ['PENDING', 'RECEIVED', 'ALL'], true)) {
        $statusFilter = 'PENDING';
    }

    $where = [];
    $params = [];
    if ($workArea !== 'ALL') {
        $where[] = 'r.destination_work_area = :work_area';
        $params[':work_area'] = $workArea;
    }
    if ($statusFilter !== 'ALL') {
        $where[] = 'r.status = :status';
        $params[':status'] = $statusFilter;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $conn->prepare("
        SELECT r.*, {$activeTransferSql} AS transfer_active
        FROM tbl_transfer_receipts r
        {$whereSql}
        ORDER BY r.sent_at DESC, r.transfer_number DESC, r.item_name ASC
        LIMIT 1000
    ");
    $stmt->execute($params);

    $transfers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $number = (string)$row['transfer_number'];
        if (!isset($transfers[$number])) {
            $transfers[$number] = [
                'transfer_number' => $number,
                'destination' => (string)($row['destination'] ?? ''),
                'destination_work_area' => (string)$row['destination_work_area'],
                'sent_by' => (string)($row['sent_by'] ?? ''),
                'sent_at' => (string)$row['sent_at'],
                'received_by' => '',
                'received_at' => '',
                'remarks' => '',
                'voided' => (int)$row['transfer_active'] === 0,
                'status' => 'RECEIVED',
                'total_sent' => 0,
                'total_received' => 0,
                'total_shortage' => 0,
                'items' => []
            ];
        }
        $sent = (int)$row['sent_quantity'];
        $received = $row['received_quantity'] === null ? null : (int)$row['received_quantity'];
        $shortage = $received === null ? null : max(0, $sent - $received);

        $transfer = &$transfers[$number];
        if ($row['status'] === 'PENDING') {
            $transfer['status'] = 'PENDING';
        }
        if ((string)($row['received_by'] ?? '') !== '') {
            $transfer['received_by'] = (string)$row['received_by'];
            $transfer['received_at'] = (string)$row['received_at'];
            $transfer['remarks'] = (string)($row['remarks'] ?? '');
        }
        $transfer['total_sent'] += $sent;
        $transfer['total_received'] += (int)$received;
        $transfer['total_shortage'] += (int)$shortage;
        $transfer['items'][] = [
            'barcode' => (string)$row['barcode'],
            'item_name' => (string)($row['item_name'] ?? ''),
            'sent_quantity' => $sent,
            'received_quantity' => $received,
            'shortage' => $shortage,
            'status' => (string)$row['status']
        ];
        unset($transfer);
    }

    sendResponse('success', 'Transfer receipts loaded.', array_values($transfers), 200, [
        'work_area' => $workArea,
        'status' => $statusFilter,
        'count' => count($transfers)
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Invalid request method.', null, 405);
}

$payload = readPayload();
$action = trim((string)($payload['action'] ?? ''));
if ($action !== 'receive') {
    sendResponse('error', 'Unknown action.', null, 400);
}

$transferNumber = trim((string)($payload['transfer_number'] ?? ''));
if ($transferNumber === '') {
    sendResponse('error', 'Transfer number is required.', null, 400);
}
$remarks = trim((string)($payload['remarks'] ?? ''));
$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
$receivedByBarcode = [];
foreach ($items as $item) {
    $barcode = trim((string)($item['barcode'] ?? ''));
    $quantity = filter_var($item['received_quantity'] ?? null, FILTER_VALIDATE_INT);
    if ($barcode === '' || $quantity === false || $quantity < 0) {
        sendResponse('error', 'Each item needs a received quantity of 0 or more.', null, 400);
    }
    $receivedByBarcode[$barcode] = $quantity;
}

// Check permission before the transaction: the module-access lookup may run
// CREATE TABLE IF NOT EXISTS, which would implicitly commit an open transaction.
$areaStmt = $conn->prepare('SELECT destination_work_area FROM tbl_transfer_receipts WHERE transfer_number = :transfer_number LIMIT 1');
$areaStmt->execute([':transfer_number' => $transferNumber]);
$workArea = (string)($areaStmt->fetchColumn() ?: '');
if ($workArea === '') {
    sendResponse('error', 'Transfer receipt not found.', null, 404);
}
if (!canUseReceiptWorkArea($userData, $workArea, true)) {
    sendResponse('error', 'You are not allowed to confirm receipts for ' . $workArea . '.', null, 403);
}

try {
    $conn->beginTransaction();

    $linesStmt = $conn->prepare("
        SELECT r.*, {$activeTransferSql} AS transfer_active
        FROM tbl_transfer_receipts r
        WHERE r.transfer_number = :transfer_number
        FOR UPDATE
    ");
    $linesStmt->execute([':transfer_number' => $transferNumber]);
    $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) {
        throw new RuntimeException('Transfer receipt not found.');
    }

    if ((int)$lines[0]['transfer_active'] === 0) {
        throw new RuntimeException('This transfer was voided and cannot be received.');
    }

    $updateStmt = $conn->prepare("
        UPDATE tbl_transfer_receipts
        SET received_quantity = :received, status = 'RECEIVED', received_by = :received_by,
            received_at = NOW(), remarks = :remarks
        WHERE receipt_id = :receipt_id
    ");
    $username = (string)($userData['username'] ?? 'system');
    $totalSent = 0;
    $totalReceived = 0;
    $shortages = [];
    $pendingLines = 0;
    foreach ($lines as $line) {
        if ($line['status'] !== 'PENDING') {
            continue;
        }
        $pendingLines++;
        $barcode = (string)$line['barcode'];
        $sent = (int)$line['sent_quantity'];
        if (!array_key_exists($barcode, $receivedByBarcode)) {
            throw new RuntimeException("Enter the received quantity for {$barcode}.");
        }
        $received = (int)$receivedByBarcode[$barcode];
        if ($received > $sent) {
            throw new RuntimeException("Received quantity for {$barcode} cannot exceed the {$sent} sent. Record extra stock through a physical count.");
        }
        if ($received < $sent && $remarks === '') {
            throw new RuntimeException('Add a remark explaining the shortage.');
        }
        $updateStmt->execute([
            ':received' => $received,
            ':received_by' => $username,
            ':remarks' => $remarks !== '' ? $remarks : null,
            ':receipt_id' => $line['receipt_id']
        ]);
        $totalSent += $sent;
        $totalReceived += $received;
        if ($received < $sent) {
            $shortages[] = [
                'barcode' => $barcode,
                'item_name' => (string)($line['item_name'] ?? ''),
                'sent' => $sent,
                'received' => $received,
                'shortage' => $sent - $received
            ];
        }
    }
    if ($pendingLines === 0) {
        throw new RuntimeException('This transfer was already received.');
    }

    $conn->commit();
} catch (RuntimeException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    sendResponse('error', $e->getMessage(), null, 400);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Transfer receipt failed: ' . $e->getMessage());
    sendResponse('error', 'Unable to save the receipt. Please try again.', null, 500);
}

logTransactionHistory($conn, [
    'module' => 'TRANSFER',
    'action' => 'RECEIVE',
    'transaction_type' => 'RECEIPT',
    'category' => 'CLINIC',
    'reference_no' => $transferNumber,
    'quantity' => $totalReceived,
    'performed_by' => $username,
    'location' => $userData['location'] ?? '',
    'work_area' => $workArea,
    'details' => [
        'total_sent' => $totalSent,
        'total_received' => $totalReceived,
        'shortages' => $shortages,
        'remarks' => $remarks
    ]
]);

$message = empty($shortages)
    ? 'Receipt confirmed. All items arrived.'
    : 'Receipt confirmed with ' . count($shortages) . ' short item(s). Correct the stock through a physical count.';
sendResponse('success', $message, [
    'transfer_number' => $transferNumber,
    'total_sent' => $totalSent,
    'total_received' => $totalReceived,
    'shortages' => $shortages
]);
