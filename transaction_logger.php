<?php
if (!function_exists('logTransactionHistory')) {
    function transactionHistoryPickColumn($columns, $candidates) {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    function transactionHistoryGetColumns($conn) {
        static $cachedColumns = null;
        if ($cachedColumns !== null) {
            return $cachedColumns;
        }

        try {
            $stmt = $conn->query("DESCRIBE tbl_transaction_history");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $columns = [];
            foreach ($rows as $row) {
                if (isset($row['Field'])) {
                    $columns[] = $row['Field'];
                }
            }
            $cachedColumns = $columns;
            return $columns;
        } catch (Exception $e) {
            $cachedColumns = [];
            return $cachedColumns;
        }
    }

    function logTransactionHistory($conn, $entry = []) {
        if (!$conn || !is_array($entry)) {
            return false;
        }

        $columns = transactionHistoryGetColumns($conn);
        if (empty($columns)) {
            return false;
        }

        $module = trim((string)($entry['module'] ?? ''));
        $action = trim((string)($entry['action'] ?? ''));
        $performedBy = trim((string)($entry['performed_by'] ?? ''));
        if ($module === '' || $action === '') {
            return false;
        }
        if ($performedBy === '') {
            $performedBy = 'system';
        }

        $payload = [
            'module' => $module,
            'action' => $action,
            'transaction_type' => trim((string)($entry['transaction_type'] ?? '')),
            'category' => trim((string)($entry['category'] ?? '')),
            'reference_no' => trim((string)($entry['reference_no'] ?? '')),
            'item_barcode' => trim((string)($entry['item_barcode'] ?? '')),
            'item_name' => trim((string)($entry['item_name'] ?? '')),
            'quantity' => isset($entry['quantity']) && $entry['quantity'] !== '' ? (int)$entry['quantity'] : null,
            'details' => $entry['details'] ?? null,
            'location' => trim((string)($entry['location'] ?? '')),
            'work_area' => trim((string)($entry['work_area'] ?? '')),
            'performed_by' => $performedBy,
            'performed_at' => trim((string)($entry['performed_at'] ?? ''))
        ];

        if ($payload['performed_at'] === '') {
            $payload['performed_at'] = date('Y-m-d H:i:s');
        }

        if (is_array($payload['details']) || is_object($payload['details'])) {
            $payload['details'] = json_encode($payload['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $payload['details'] = trim((string)$payload['details']);
        }

        $columnMap = [
            'module' => ['Module', 'module'],
            'action' => ['Action', 'action'],
            'transaction_type' => ['Transaction_Type', 'transaction_type', 'Type', 'type'],
            'category' => ['Category', 'category'],
            'reference_no' => ['Reference_No', 'reference_no', 'Reference', 'reference'],
            'item_barcode' => ['Item_Barcode', 'item_barcode', 'Barcode', 'barcode'],
            'item_name' => ['Item_Name', 'item_name', 'Item', 'item'],
            'quantity' => ['Quantity', 'quantity'],
            'details' => ['Details', 'details', 'Meta', 'meta'],
            'location' => ['Location', 'location'],
            'work_area' => ['Work_Area', 'work_area', 'WorkArea', 'workarea'],
            'performed_by' => ['Performed_By', 'performed_by', 'User', 'user', 'Username', 'username'],
            'performed_at' => ['Performed_At', 'performed_at', 'Created_At', 'created_at']
        ];

        $insertCols = [];
        $insertVals = [];
        $insertParams = [];

        foreach ($columnMap as $key => $candidates) {
            $column = transactionHistoryPickColumn($columns, $candidates);
            if (!$column) {
                continue;
            }

            $value = $payload[$key];
            if ($key !== 'module' && $key !== 'action' && $key !== 'performed_by' && $key !== 'performed_at') {
                if ($value === null || $value === '') {
                    continue;
                }
            }

            $insertCols[] = '`' . str_replace('`', '``', $column) . '`';
            $insertVals[] = '?';
            $insertParams[] = $value;
        }

        if (empty($insertCols)) {
            return false;
        }

        try {
            $sql = "INSERT INTO tbl_transaction_history (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
            $stmt = $conn->prepare($sql);
            return $stmt->execute($insertParams);
        } catch (Exception $e) {
            error_log('Transaction history log failed: ' . $e->getMessage());
            return false;
        }
    }
}
?>
