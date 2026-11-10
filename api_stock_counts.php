<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';
require_once 'transaction_logger.php';
require_once __DIR__ . '/stock_workflow_helpers.php';

/**
 * Physical stock counts.
 *
 * GET  ?action=items&work_area=&category_key=   batches with current system quantity (count sheet)
 * GET  ?action=list&work_area=&status=          counts for a location
 * GET  ?action=details&count_id=                one count with its lines
 * POST {action:'save', count_id?, work_area, category_key, remarks, lines:[{barcode, counted_quantity}]}
 *      Saves a DRAFT. System quantities are snapshotted at save time.
 * POST {action:'post', count_id, reason}        Admin/Super Admin: posts variances as ADJUSTMENT ledger rows
 * POST {action:'cancel', count_id}              cancels a DRAFT
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

function normalizeCountWorkArea($value) {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9 _-]/', '', $value);
    return $value !== '' ? $value : 'CHO';
}

function canViewStockCounts($userData, $workArea) {
    if ($workArea === 'ALL' || !userCanAccessWorkArea($userData, $workArea)) {
        return false;
    }
    return apiUserCan($userData, 'inventory_view', $workArea)
        || apiUserCan($userData, 'checkout', $workArea)
        || ($workArea === 'CHO' && apiUserCan($userData, 'clinics'));
}

function canPostStockCounts($userData) {
    $roleKey = strtolower(trim((string)($userData['role'] ?? '')));
    return isSuperAdminRoleKey($roleKey) || isAdministratorRoleKey($roleKey);
}

function loadStockCount($conn, $countId, $forUpdate = false) {
    $stmt = $conn->prepare('SELECT * FROM tbl_stock_counts WHERE count_id = :id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([':id' => $countId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function loadStockCountLines($conn, $countId) {
    $stmt = $conn->prepare('SELECT * FROM tbl_stock_count_lines WHERE count_id = :id ORDER BY item_name ASC, expiry_date ASC');
    $stmt->execute([':id' => $countId]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lines as &$line) {
        $line['system_quantity'] = (int)$line['system_quantity'];
        $line['counted_quantity'] = (int)$line['counted_quantity'];
        $line['variance'] = (int)$line['variance'];
    }
    unset($line);
    return $lines;
}

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}
if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}
if (!ensureStockCountTables($conn)) {
    sendResponse('error', 'Stock count tables are unavailable.', null, 500);
}

$categories = getStockCountCategories();
$username = (string)($userData['username'] ?? 'system');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim((string)($_GET['action'] ?? 'list'));

    if ($action === 'details') {
        $count = loadStockCount($conn, (int)($_GET['count_id'] ?? 0));
        if (!$count) {
            sendResponse('error', 'Stock count not found.', null, 404);
        }
        if (!canViewStockCounts($userData, $count['work_area'])) {
            sendResponse('error', 'You are not allowed to view this stock count.', null, 403);
        }
        $count['category_label'] = $categories[$count['category_key']]['label'] ?? $count['category_key'];
        $count['lines'] = loadStockCountLines($conn, (int)$count['count_id']);
        sendResponse('success', 'Stock count loaded.', $count, 200, [
            'can_post' => canPostStockCounts($userData) && userCanWrite($userData)
        ]);
    }

    $workArea = normalizeCountWorkArea($_GET['work_area'] ?? ($userData['location'] ?? 'CHO'));
    if (!canViewStockCounts($userData, $workArea)) {
        sendResponse('error', 'You are not allowed to view stock counts for this location.', null, 403);
    }

    if ($action === 'items') {
        $categoryKey = trim((string)($_GET['category_key'] ?? ''));
        if (!isset($categories[$categoryKey])) {
            sendResponse('error', 'Select a valid category.', null, 400);
        }
        $rows = array_values(loadStockCountSystemQuantities($conn, $categoryKey, $workArea));
        sendResponse('success', 'Count sheet loaded.', $rows, 200, ['count' => count($rows), 'work_area' => $workArea]);
    }

    if ($action === 'list') {
        $statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));
        $params = [':work_area' => $workArea];
        $statusSql = '';
        if (in_array($statusFilter, ['DRAFT', 'POSTED', 'CANCELLED'], true)) {
            $statusSql = ' AND c.status = :status';
            $params[':status'] = $statusFilter;
        }
        $stmt = $conn->prepare("
            SELECT c.*,
                   COUNT(l.line_id) AS line_count,
                   COALESCE(SUM(CASE WHEN l.variance <> 0 THEN 1 ELSE 0 END), 0) AS variance_lines,
                   COALESCE(SUM(CASE WHEN l.variance < 0 THEN -l.variance ELSE 0 END), 0) AS total_shortage,
                   COALESCE(SUM(CASE WHEN l.variance > 0 THEN l.variance ELSE 0 END), 0) AS total_surplus
            FROM tbl_stock_counts c
            LEFT JOIN tbl_stock_count_lines l ON l.count_id = c.count_id
            WHERE c.work_area = :work_area{$statusSql}
            GROUP BY c.count_id
            ORDER BY c.created_at DESC, c.count_id DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['category_label'] = $categories[$row['category_key']]['label'] ?? $row['category_key'];
        }
        unset($row);
        sendResponse('success', 'Stock counts loaded.', $rows, 200, [
            'count' => count($rows),
            'work_area' => $workArea,
            'can_write' => userCanWrite($userData),
            'can_post' => canPostStockCounts($userData) && userCanWrite($userData)
        ]);
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
$action = trim((string)($payload['action'] ?? ''));

if ($action === 'save') {
    $countId = (int)($payload['count_id'] ?? 0);
    $remarks = trim((string)($payload['remarks'] ?? ''));
    $lineInput = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];

    if ($countId > 0) {
        $existing = loadStockCount($conn, $countId);
        if (!$existing) {
            sendResponse('error', 'Stock count not found.', null, 404);
        }
        if ($existing['status'] !== 'DRAFT') {
            sendResponse('error', 'Only draft counts can be changed.', null, 400);
        }
        $workArea = $existing['work_area'];
        $categoryKey = $existing['category_key'];
    } else {
        $workArea = normalizeCountWorkArea($payload['work_area'] ?? '');
        $categoryKey = trim((string)($payload['category_key'] ?? ''));
    }
    if (!isset($categories[$categoryKey])) {
        sendResponse('error', 'Select a valid category.', null, 400);
    }
    if (!canViewStockCounts($userData, $workArea)) {
        sendResponse('error', 'You are not allowed to count stock for this location.', null, 403);
    }

    $counted = [];
    foreach ($lineInput as $line) {
        $barcode = trim((string)($line['barcode'] ?? ''));
        $quantity = filter_var($line['counted_quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($barcode === '') {
            continue;
        }
        if ($quantity === false || $quantity < 0) {
            sendResponse('error', "Counted quantity for {$barcode} must be 0 or more.", null, 400);
        }
        $counted[$barcode] = $quantity;
    }
    if (empty($counted)) {
        sendResponse('error', 'Enter at least one counted quantity.', null, 400);
    }

    $system = loadStockCountSystemQuantities($conn, $categoryKey, $workArea);
    foreach (array_keys($counted) as $barcode) {
        if (!isset($system[$barcode])) {
            sendResponse('error', "Batch {$barcode} is not in this location's {$categories[$categoryKey]['label']} stock.", null, 400);
        }
    }

    try {
        $conn->beginTransaction();
        if ($countId > 0) {
            $locked = loadStockCount($conn, $countId, true);
            if (!$locked || $locked['status'] !== 'DRAFT') {
                throw new RuntimeException('Only draft counts can be changed.');
            }
            $conn->prepare('UPDATE tbl_stock_counts SET remarks = :remarks, updated_at = NOW() WHERE count_id = :id')
                ->execute([':remarks' => $remarks !== '' ? $remarks : null, ':id' => $countId]);
            $conn->prepare('DELETE FROM tbl_stock_count_lines WHERE count_id = :id')->execute([':id' => $countId]);
        } else {
            $conn->prepare("
                INSERT INTO tbl_stock_counts (work_area, category_key, status, count_date, remarks, counted_by)
                VALUES (:work_area, :category_key, 'DRAFT', CURDATE(), :remarks, :counted_by)
            ")->execute([
                ':work_area' => $workArea,
                ':category_key' => $categoryKey,
                ':remarks' => $remarks !== '' ? $remarks : null,
                ':counted_by' => $username
            ]);
            $countId = (int)$conn->lastInsertId();
        }

        $lineStmt = $conn->prepare("
            INSERT INTO tbl_stock_count_lines
                (count_id, barcode, item_name, description, expiry_date, system_quantity, counted_quantity, variance)
            VALUES
                (:count_id, :barcode, :item_name, :description, :expiry_date, :system_quantity, :counted_quantity, :variance)
        ");
        foreach ($counted as $barcode => $quantity) {
            $snapshot = $system[$barcode];
            $lineStmt->execute([
                ':count_id' => $countId,
                ':barcode' => $barcode,
                ':item_name' => $snapshot['item_name'],
                ':description' => $snapshot['description'],
                ':expiry_date' => $snapshot['expiry_date'],
                ':system_quantity' => $snapshot['system_quantity'],
                ':counted_quantity' => $quantity,
                ':variance' => $quantity - $snapshot['system_quantity']
            ]);
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
        error_log('Stock count save failed: ' . $e->getMessage());
        sendResponse('error', 'Unable to save the stock count. Please try again.', null, 500);
    }

    sendResponse('success', 'Stock count saved as draft.', ['count_id' => $countId]);
}

if ($action === 'cancel') {
    $countId = (int)($payload['count_id'] ?? 0);
    $count = loadStockCount($conn, $countId);
    if (!$count) {
        sendResponse('error', 'Stock count not found.', null, 404);
    }
    if (!canViewStockCounts($userData, $count['work_area'])) {
        sendResponse('error', 'You are not allowed to change this stock count.', null, 403);
    }
    if ($count['counted_by'] !== $username && !canPostStockCounts($userData)) {
        sendResponse('error', 'Only the person who made this count or an Administrator can cancel it.', null, 403);
    }
    $stmt = $conn->prepare("UPDATE tbl_stock_counts SET status = 'CANCELLED', updated_at = NOW() WHERE count_id = :id AND status = 'DRAFT'");
    $stmt->execute([':id' => $countId]);
    if ($stmt->rowCount() === 0) {
        sendResponse('error', 'Only draft counts can be cancelled.', null, 400);
    }
    sendResponse('success', 'Stock count cancelled.', ['count_id' => $countId]);
}

if ($action === 'post') {
    if (!canPostStockCounts($userData)) {
        sendResponse('error', 'Only Administrators can post stock count adjustments.', null, 403);
    }
    $countId = (int)($payload['count_id'] ?? 0);
    $reason = trim((string)($payload['reason'] ?? ''));
    if ($reason === '') {
        sendResponse('error', 'A reason is required to post stock adjustments.', null, 400);
    }

    $count = loadStockCount($conn, $countId);
    if (!$count) {
        sendResponse('error', 'Stock count not found.', null, 404);
    }
    if (!canViewStockCounts($userData, $count['work_area'])) {
        sendResponse('error', 'You are not allowed to post counts for this location.', null, 403);
    }

    if (!acquireStockLock($conn)) {
        sendResponse('error', 'Another stock transaction is in progress. Please try again.', null, 503);
    }

    $adjustmentRef = null;
    $adjustedLines = [];
    try {
        $conn->beginTransaction();
        $locked = loadStockCount($conn, $countId, true);
        if (!$locked || $locked['status'] !== 'DRAFT') {
            throw new RuntimeException('Only draft counts can be posted.');
        }

        // Variance is shelf count minus the system quantity when the count was saved;
        // stock released after the count is already in the ledger and must not be re-adjusted.
        $lines = loadStockCountLines($conn, $countId);
        $varianceLines = array_values(array_filter($lines, function ($line) {
            return (int)$line['variance'] !== 0;
        }));

        if (!empty($varianceLines)) {
            $adjustmentRef = generateAdjustmentNumber($conn);
            $ledgerStmt = $conn->prepare("
                INSERT INTO tbl_checkedout_items
                    (Barcode, PIID, Quantity, Checkout_Date, Checkout_By, Barangay, RIS_Number, Category, isDeleted, Work_Area, Transaction_Type)
                VALUES
                    (:barcode, 0, :quantity, NOW(), :checkout_by, '', :ref, 'ADJUSTMENT', 0, :work_area, 'ADJUSTMENT')
            ");
            foreach ($varianceLines as $line) {
                // Ledger quantity is stock going out: a shortage (negative variance) is positive.
                $ledgerStmt->execute([
                    ':barcode' => $line['barcode'],
                    ':quantity' => -(int)$line['variance'],
                    ':checkout_by' => $username,
                    ':ref' => $adjustmentRef,
                    ':work_area' => $locked['work_area']
                ]);
                $adjustedLines[] = [
                    'barcode' => $line['barcode'],
                    'item' => $line['item_name'],
                    'system_quantity' => (int)$line['system_quantity'],
                    'counted_quantity' => (int)$line['counted_quantity'],
                    'variance' => (int)$line['variance']
                ];
            }
        }

        $conn->prepare("
            UPDATE tbl_stock_counts
            SET status = 'POSTED', posted_by = :posted_by, posted_at = NOW(), post_reason = :reason,
                adjustment_ref = :ref, updated_at = NOW()
            WHERE count_id = :id
        ")->execute([
            ':posted_by' => $username,
            ':reason' => $reason,
            ':ref' => $adjustmentRef,
            ':id' => $countId
        ]);
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
        error_log('Stock count post failed: ' . $e->getMessage());
        sendResponse('error', 'Unable to post the stock count. Please try again.', null, 500);
    }

    if ($adjustmentRef !== null) {
        logTransactionHistory($conn, [
            'module' => 'INVENTORY',
            'action' => 'STOCK_COUNT_ADJUSTMENT',
            'transaction_type' => 'ADJUSTMENT',
            'category' => 'ADJUSTMENT',
            'reference_no' => $adjustmentRef,
            'quantity' => array_sum(array_map(function ($line) {
                return abs($line['variance']);
            }, $adjustedLines)),
            'performed_by' => $username,
            'location' => $userData['location'] ?? '',
            'work_area' => $count['work_area'],
            'details' => [
                'count_id' => $countId,
                'counted_by' => $count['counted_by'],
                'reason' => $reason,
                'items' => $adjustedLines
            ]
        ]);
    }

    sendResponse('success', $adjustmentRef !== null
        ? "Count posted. Adjustment {$adjustmentRef} recorded for " . count($adjustedLines) . ' batch(es).'
        : 'Count posted. No differences found, so no adjustment was needed.', [
        'count_id' => $countId,
        'adjustment_ref' => $adjustmentRef,
        'adjusted_lines' => $adjustedLines
    ]);
}

sendResponse('error', 'Unknown action.', null, 400);
