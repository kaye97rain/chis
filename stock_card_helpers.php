<?php
if (!function_exists('stockCardNormalizeString')) {
    function stockCardNormalizeString($value) {
        return trim((string)$value);
    }

    function stockCardNormalizeWorkArea($value, $default = 'CHO') {
        $value = strtoupper(trim((string)$value));
        if ($value === '') {
            return $default;
        }
        $value = preg_replace('/[^A-Z0-9 _-]/', '', $value);
        return $value !== '' ? $value : $default;
    }

    function stockCardQuoteIdentifier($identifier) {
        return '`' . str_replace('`', '``', (string)$identifier) . '`';
    }

    function stockCardGetTableColumns($conn, $table) {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        try {
            $stmt = $conn->query("DESCRIBE " . stockCardQuoteIdentifier($table));
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

    function stockCardPickColumn($columns, $candidates) {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    function stockCardGetIsDeletedColumn($columns) {
        return stockCardPickColumn($columns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
    }

    function stockCardFormatDateTime($value, $fallback = '') {
        $value = stockCardNormalizeString($value);
        if ($value === '') {
            return $fallback;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    function stockCardDetectMovementKind($referenceNo, $transactionType, $destination) {
        $transactionType = strtoupper(stockCardNormalizeString($transactionType));
        if ($transactionType === 'TRANSFER') {
            return 'TRANSFER';
        }

        $referenceNo = strtoupper(stockCardNormalizeString($referenceNo));
        if ($transactionType === 'ADJUSTMENT' || strpos($referenceNo, 'ADJ-') === 0) {
            return 'ADJUSTMENT';
        }
        if (strpos($referenceNo, 'TRF') === 0) {
            return 'TRANSFER';
        }

        if (stockCardNormalizeString($destination) !== '') {
            return 'TRANSFER';
        }

        return 'CHECKOUT';
    }

    function stockCardFormatLocationParts($room, $cabinet, $shelf, $bin, $note = '') {
        $parts = [];
        foreach ([$room, $cabinet, $shelf, $bin] as $part) {
            $part = stockCardNormalizeString($part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        $location = implode(' / ', $parts);
        $note = stockCardNormalizeString($note);
        if ($note !== '') {
            $location = $location !== '' ? ($location . ' (' . $note . ')') : $note;
        }
        return $location;
    }

    function stockCardGetCategories() {
        return [
            'medicine' => ['label' => 'Medicine', 'item_table' => 'tbl_item_medicine'],
            'medical_supplies' => ['label' => 'Medical Supplies', 'item_table' => 'tbl_item_medical_supplies'],
            'vaccines' => ['label' => 'Vaccines', 'item_table' => 'tbl_item_vaccines'],
            'lab_reagents' => ['label' => 'Lab Reagents', 'item_table' => 'tbl_item_lab_reagents']
        ];
    }

    function stockCardEnsureSchema($conn) {
        static $done = false;
        if ($done || !$conn) {
            return;
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS tbl_stock_cards (
                SCID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                Category_Key VARCHAR(60) NOT NULL,
                Work_Area VARCHAR(80) NOT NULL DEFAULT 'CHO',
                Item_IID BIGINT UNSIGNED NOT NULL,
                Master_ID BIGINT UNSIGNED NULL,
                Barcode_Number VARCHAR(150) NULL,
                Barcode_Value VARCHAR(150) NULL,
                Item_Name VARCHAR(255) NOT NULL,
                Description TEXT NULL,
                Entity VARCHAR(150) NULL,
                Unit_Cost DECIMAL(12,2) NULL,
                Starting_Quantity INT NOT NULL DEFAULT 0,
                Current_Balance INT NOT NULL DEFAULT 0,
                Expiry_Date DATETIME NULL,
                Date_Added DATETIME NULL,
                PO_Number VARCHAR(150) NULL,
                Donated TINYINT(1) NOT NULL DEFAULT 0,
                Location_Room VARCHAR(120) NULL,
                Location_Cabinet VARCHAR(120) NULL,
                Location_Shelf VARCHAR(120) NULL,
                Location_Bin VARCHAR(120) NULL,
                Location_Note VARCHAR(255) NULL,
                Status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
                Created_At TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                Updated_At DATETIME NULL,
                Last_Synced_At DATETIME NULL,
                Closed_At DATETIME NULL,
                Closed_By VARCHAR(150) NULL,
                PRIMARY KEY (SCID),
                UNIQUE KEY uq_stock_card_item (Category_Key, Work_Area, Item_IID),
                KEY idx_stock_card_barcode (Barcode_Number),
                KEY idx_stock_card_status (Status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS tbl_stock_card_history (
                SCHID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                Stock_Card_ID BIGINT UNSIGNED NOT NULL,
                Transaction_Type VARCHAR(60) NOT NULL,
                Reference_No VARCHAR(150) NULL,
                Qty_In INT NOT NULL DEFAULT 0,
                Qty_Out INT NOT NULL DEFAULT 0,
                Balance_After INT NULL,
                From_Location VARCHAR(255) NULL,
                To_Location VARCHAR(255) NULL,
                Remarks TEXT NULL,
                Performed_By VARCHAR(150) NOT NULL,
                Transaction_Date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                Meta_JSON LONGTEXT NULL,
                PRIMARY KEY (SCHID),
                KEY idx_stock_card_history_card (Stock_Card_ID),
                KEY idx_stock_card_history_date (Transaction_Date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $done = true;
    }

    function stockCardGetCheckoutMeta($conn) {
        $columns = stockCardGetTableColumns($conn, 'tbl_checkedout_items');
        return [
            'id' => stockCardPickColumn($columns, ['CID', 'cid', 'ID', 'id']),
            'barcode' => stockCardPickColumn($columns, ['Barcode', 'barcode', 'Barcode_Number', 'barcode_number']),
            'quantity' => stockCardPickColumn($columns, ['Quantity', 'quantity']),
            'reference_no' => stockCardPickColumn($columns, ['RIS_Number', 'ris_number', 'RIS_No', 'ris_no', 'Reference_No', 'reference_no']),
            'checkout_date' => stockCardPickColumn($columns, ['Checkout_Date', 'checkout_date']),
            'checkout_by' => stockCardPickColumn($columns, ['Checkout_By', 'checkout_by']),
            'category' => stockCardPickColumn($columns, ['Category', 'category']),
            'barangay' => stockCardPickColumn($columns, ['Barangay', 'barangay']),
            'destination' => stockCardPickColumn($columns, ['Destination', 'destination', 'Target_Area', 'target_area']),
            'transaction_type' => stockCardPickColumn($columns, ['Transaction_Type', 'transaction_type', 'Type', 'type']),
            'transfer_date' => stockCardPickColumn($columns, ['Transfer_Date', 'transfer_date', 'Date_Transfer', 'date_transfer']),
            'transfer_by' => stockCardPickColumn($columns, ['Transfer_By', 'transfer_by', 'Transferred_By', 'transferred_by']),
            'remarks' => stockCardPickColumn($columns, ['Remarks', 'remarks', 'Notes', 'notes']),
            'work_area' => stockCardPickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
            'is_deleted' => stockCardGetIsDeletedColumn($columns)
        ];
    }

    function stockCardGetItemMeta($conn, $categoryKey) {
        $categories = stockCardGetCategories();
        if (!isset($categories[$categoryKey])) {
            return null;
        }

        $table = $categories[$categoryKey]['item_table'];
        $columns = stockCardGetTableColumns($conn, $table);
        return [
            'category' => $categories[$categoryKey],
            'table' => $table,
            'iid' => stockCardPickColumn($columns, ['IID', 'iid']),
            'id' => stockCardPickColumn($columns, ['ID', 'id']),
            'barcode' => stockCardPickColumn($columns, ['Barcode', 'barcode']),
            'barcode_number' => stockCardPickColumn($columns, ['Barcode_Number', 'barcode_number']),
            'item' => stockCardPickColumn($columns, ['Item', 'item']),
            'description' => stockCardPickColumn($columns, ['Description', 'description']),
            'entity' => stockCardPickColumn($columns, ['Entity', 'entity']),
            'unit_cost' => stockCardPickColumn($columns, ['Unit_Cost', 'unit_cost', 'UnitCost']),
            'quantity' => stockCardPickColumn($columns, ['Quantity', 'quantity']),
            'expiry_date' => stockCardPickColumn($columns, ['Expiry_Date', 'expiry_date']),
            'date_added' => stockCardPickColumn($columns, ['Date_Added', 'date_added']),
            'po_number' => stockCardPickColumn($columns, ['PO_Number', 'po_number']),
            'donated' => stockCardPickColumn($columns, ['Donated', 'donated']),
            'work_area' => stockCardPickColumn($columns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
            'stock_source' => stockCardPickColumn($columns, ['Stock_Source', 'stock_source']),
            'is_deleted' => stockCardGetIsDeletedColumn($columns)
        ];
    }

    function stockCardComputeAvailableQuantity($conn, $barcodeNumber, $startingQuantity, $workArea) {
        $barcodeNumber = stockCardNormalizeString($barcodeNumber);
        if ($barcodeNumber === '') {
            return (int)$startingQuantity;
        }

        $meta = stockCardGetCheckoutMeta($conn);
        if (!$meta['barcode'] || !$meta['quantity']) {
            return (int)$startingQuantity;
        }

        $where = [stockCardQuoteIdentifier($meta['barcode']) . ' = :barcode'];
        $params = [':barcode' => $barcodeNumber];
        if ($meta['is_deleted']) {
            $where[] = stockCardQuoteIdentifier($meta['is_deleted']) . ' = 0';
        }
        if ($meta['work_area']) {
            $where[] = "UPPER(COALESCE(NULLIF(TRIM(" . stockCardQuoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = :work_area";
            $params[':work_area'] = $workArea;
        }

        $sql = "SELECT COALESCE(SUM(" . stockCardQuoteIdentifier($meta['quantity']) . "), 0) FROM tbl_checkedout_items WHERE " . implode(' AND ', $where);
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $checkedOut = (int)$stmt->fetchColumn();
        return (int)$startingQuantity - $checkedOut;
    }

    function stockCardSyncCategory($conn, $categoryKey, $workArea, $performedBy = 'system') {
        $meta = stockCardGetItemMeta($conn, $categoryKey);
        if (!$meta || !$meta['iid'] || !$meta['quantity']) {
            return 0;
        }

        stockCardEnsureSchema($conn);

        $where = [];
        if ($meta['is_deleted']) {
            $where[] = stockCardQuoteIdentifier($meta['is_deleted']) . ' = 0';
        }
        if ($meta['work_area']) {
            $where[] = "UPPER(COALESCE(NULLIF(TRIM(" . stockCardQuoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = :work_area";
        }

        $sql = "SELECT * FROM " . stockCardQuoteIdentifier($meta['table']);
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $stmt = $conn->prepare($sql);
        $params = [];
        if ($meta['work_area']) {
            $params[':work_area'] = $workArea;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $upsertStmt = $conn->prepare("
            INSERT INTO tbl_stock_cards (
                Category_Key, Work_Area, Item_IID, Master_ID, Barcode_Number, Barcode_Value,
                Item_Name, Description, Entity, Unit_Cost, Starting_Quantity, Current_Balance,
                Expiry_Date, Date_Added, PO_Number, Donated, Last_Synced_At
            ) VALUES (
                :category_key, :work_area, :item_iid, :master_id, :barcode_number, :barcode_value,
                :item_name, :description, :entity, :unit_cost, :starting_quantity, :current_balance,
                :expiry_date, :date_added, :po_number, :donated, NOW()
            )
            ON DUPLICATE KEY UPDATE
                Master_ID = VALUES(Master_ID),
                Barcode_Number = VALUES(Barcode_Number),
                Barcode_Value = VALUES(Barcode_Value),
                Item_Name = VALUES(Item_Name),
                Description = VALUES(Description),
                Entity = VALUES(Entity),
                Unit_Cost = VALUES(Unit_Cost),
                Starting_Quantity = VALUES(Starting_Quantity),
                Current_Balance = VALUES(Current_Balance),
                Expiry_Date = VALUES(Expiry_Date),
                Date_Added = VALUES(Date_Added),
                PO_Number = VALUES(PO_Number),
                Donated = VALUES(Donated),
                Last_Synced_At = NOW(),
                Updated_At = NOW()
        ");

        $count = 0;
        foreach ($rows as $row) {
            $itemIid = (int)($row[$meta['iid']] ?? 0);
            if ($itemIid <= 0) {
                continue;
            }

            $startingQuantity = (int)($row[$meta['quantity']] ?? 0);
            $barcodeNumber = $meta['barcode_number'] ? stockCardNormalizeString($row[$meta['barcode_number']] ?? '') : '';
            $barcodeValue = $meta['barcode'] ? stockCardNormalizeString($row[$meta['barcode']] ?? '') : '';
            $lookupBarcode = $barcodeNumber !== '' ? $barcodeNumber : $barcodeValue;
            $currentBalance = stockCardComputeAvailableQuantity($conn, $lookupBarcode, $startingQuantity, $workArea);

            $upsertStmt->execute([
                ':category_key' => $categoryKey,
                ':work_area' => $workArea,
                ':item_iid' => $itemIid,
                ':master_id' => $meta['id'] ? (($row[$meta['id']] ?? null) !== null ? (int)$row[$meta['id']] : null) : null,
                ':barcode_number' => $barcodeNumber !== '' ? $barcodeNumber : null,
                ':barcode_value' => $barcodeValue !== '' ? $barcodeValue : null,
                ':item_name' => $meta['item'] ? stockCardNormalizeString($row[$meta['item']] ?? '') : '',
                ':description' => $meta['description'] ? stockCardNormalizeString($row[$meta['description']] ?? '') : null,
                ':entity' => $meta['entity'] ? stockCardNormalizeString($row[$meta['entity']] ?? '') : null,
                ':unit_cost' => $meta['unit_cost'] ? (($row[$meta['unit_cost']] ?? '') !== '' ? $row[$meta['unit_cost']] : null) : null,
                ':starting_quantity' => $startingQuantity,
                ':current_balance' => $currentBalance,
                ':expiry_date' => $meta['expiry_date'] ? (stockCardNormalizeString($row[$meta['expiry_date']] ?? '') !== '' ? $row[$meta['expiry_date']] : null) : null,
                ':date_added' => $meta['date_added'] ? (stockCardNormalizeString($row[$meta['date_added']] ?? '') !== '' ? $row[$meta['date_added']] : null) : null,
                ':po_number' => $meta['po_number'] ? (stockCardNormalizeString($row[$meta['po_number']] ?? '') !== '' ? $row[$meta['po_number']] : null) : null,
                ':donated' => $meta['donated'] ? (int)($row[$meta['donated']] ?? 0) : 0
            ]);
            $count++;
        }

        return $count;
    }

    function stockCardSyncAll($conn, $workArea, $performedBy = 'system') {
        $total = 0;
        foreach (array_keys(stockCardGetCategories()) as $categoryKey) {
            $total += stockCardSyncCategory($conn, $categoryKey, $workArea, $performedBy);
        }
        return $total;
    }

    function stockCardGetCardById($conn, $cardId, $workArea = '') {
        stockCardEnsureSchema($conn);
        $where = ['SCID = :card_id'];
        $params = [':card_id' => $cardId];
        if ($workArea !== '') {
            $where[] = 'Work_Area = :work_area';
            $params[':work_area'] = $workArea;
        }
        $stmt = $conn->prepare("SELECT * FROM tbl_stock_cards WHERE " . implode(' AND ', $where) . " LIMIT 1");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function stockCardGetCardByItem($conn, $categoryKey, $itemIid, $workArea) {
        stockCardEnsureSchema($conn);
        $stmt = $conn->prepare("
            SELECT *
            FROM tbl_stock_cards
            WHERE Category_Key = :category_key
              AND Item_IID = :item_iid
              AND Work_Area = :work_area
            LIMIT 1
        ");
        $stmt->execute([
            ':category_key' => $categoryKey,
            ':item_iid' => $itemIid,
            ':work_area' => $workArea
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function stockCardInsertHistory($conn, $cardId, $transactionType, $performedBy, $data = []) {
        stockCardEnsureSchema($conn);
        $metaJson = $data['meta_json'] ?? null;
        if (is_array($metaJson) || is_object($metaJson)) {
            $metaJson = json_encode($metaJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $stmt = $conn->prepare("
            INSERT INTO tbl_stock_card_history (
                Stock_Card_ID, Transaction_Type, Reference_No, Qty_In, Qty_Out, Balance_After,
                From_Location, To_Location, Remarks, Performed_By, Transaction_Date, Meta_JSON
            ) VALUES (
                :card_id, :transaction_type, :reference_no, :qty_in, :qty_out, :balance_after,
                :from_location, :to_location, :remarks, :performed_by, :transaction_date, :meta_json
            )
        ");
        return $stmt->execute([
            ':card_id' => $cardId,
            ':transaction_type' => $transactionType,
            ':reference_no' => stockCardNormalizeString($data['reference_no'] ?? '') !== '' ? stockCardNormalizeString($data['reference_no']) : null,
            ':qty_in' => (int)($data['qty_in'] ?? 0),
            ':qty_out' => (int)($data['qty_out'] ?? 0),
            ':balance_after' => isset($data['balance_after']) ? (int)$data['balance_after'] : null,
            ':from_location' => stockCardNormalizeString($data['from_location'] ?? '') !== '' ? stockCardNormalizeString($data['from_location']) : null,
            ':to_location' => stockCardNormalizeString($data['to_location'] ?? '') !== '' ? stockCardNormalizeString($data['to_location']) : null,
            ':remarks' => stockCardNormalizeString($data['remarks'] ?? '') !== '' ? stockCardNormalizeString($data['remarks']) : null,
            ':performed_by' => $performedBy !== '' ? $performedBy : 'system',
            ':transaction_date' => stockCardFormatDateTime($data['transaction_date'] ?? '', date('Y-m-d H:i:s')),
            ':meta_json' => $metaJson
        ]);
    }

    function stockCardLoadManualHistory($conn, $cardId) {
        stockCardEnsureSchema($conn);
        $stmt = $conn->prepare("
            SELECT SCHID, Transaction_Type, Reference_No, Qty_In, Qty_Out, Balance_After,
                   From_Location, To_Location, Remarks, Performed_By, Transaction_Date
            FROM tbl_stock_card_history
            WHERE Stock_Card_ID = :card_id
            ORDER BY Transaction_Date ASC, SCHID ASC
        ");
        $stmt->execute([':card_id' => $cardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    function stockCardLoadCheckoutRows($conn, $barcodeNumber, $workArea) {
        $barcodeNumber = stockCardNormalizeString($barcodeNumber);
        if ($barcodeNumber === '') {
            return [];
        }

        $meta = stockCardGetCheckoutMeta($conn);
        if (!$meta['barcode'] || !$meta['quantity']) {
            return [];
        }

        $selectCols = [
            stockCardQuoteIdentifier($meta['barcode']) . ' AS barcode',
            stockCardQuoteIdentifier($meta['quantity']) . ' AS quantity'
        ];
        $selectCols[] = $meta['reference_no'] ? (stockCardQuoteIdentifier($meta['reference_no']) . ' AS reference_no') : "'' AS reference_no";
        $selectCols[] = $meta['checkout_date'] ? (stockCardQuoteIdentifier($meta['checkout_date']) . ' AS checkout_date') : "NULL AS checkout_date";
        $selectCols[] = $meta['checkout_by'] ? (stockCardQuoteIdentifier($meta['checkout_by']) . ' AS checkout_by') : "'' AS checkout_by";
        $selectCols[] = $meta['category'] ? (stockCardQuoteIdentifier($meta['category']) . ' AS category') : "'' AS category";
        $selectCols[] = $meta['barangay'] ? (stockCardQuoteIdentifier($meta['barangay']) . ' AS barangay') : "'' AS barangay";
        $selectCols[] = $meta['destination'] ? (stockCardQuoteIdentifier($meta['destination']) . ' AS destination') : "'' AS destination";
        $selectCols[] = $meta['transaction_type'] ? (stockCardQuoteIdentifier($meta['transaction_type']) . ' AS transaction_type') : "'' AS transaction_type";
        $selectCols[] = $meta['transfer_date'] ? (stockCardQuoteIdentifier($meta['transfer_date']) . ' AS transfer_date') : "NULL AS transfer_date";
        $selectCols[] = $meta['transfer_by'] ? (stockCardQuoteIdentifier($meta['transfer_by']) . ' AS transfer_by') : "'' AS transfer_by";
        $selectCols[] = $meta['remarks'] ? (stockCardQuoteIdentifier($meta['remarks']) . ' AS remarks') : "'' AS remarks";
        $selectCols[] = $meta['is_deleted'] ? (stockCardQuoteIdentifier($meta['is_deleted']) . ' AS is_deleted') : "0 AS is_deleted";

        $where = [stockCardQuoteIdentifier($meta['barcode']) . ' = :barcode'];
        $params = [':barcode' => $barcodeNumber];
        if ($meta['work_area']) {
            $where[] = "UPPER(COALESCE(NULLIF(TRIM(" . stockCardQuoteIdentifier($meta['work_area']) . "), ''), 'CHO')) = :work_area";
            $params[':work_area'] = $workArea;
        }

        $order = [];
        if ($meta['transfer_date']) {
            $order[] = stockCardQuoteIdentifier($meta['transfer_date']) . ' ASC';
        }
        if ($meta['checkout_date']) {
            $order[] = stockCardQuoteIdentifier($meta['checkout_date']) . ' ASC';
        }
        if ($meta['id']) {
            $order[] = stockCardQuoteIdentifier($meta['id']) . ' ASC';
        }

        $sql = "
            SELECT " . implode(', ', $selectCols) . "
            FROM tbl_checkedout_items
            WHERE " . implode(' AND ', $where) . "
            " . (!empty($order) ? (' ORDER BY ' . implode(', ', $order)) : '');
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Whether the card's batch was entered by hand at a clinic as opening stock
     * (Stock_Source = 'OPENING', see api_opening_stock.php) rather than received or transferred.
     */
    function stockCardIsOpeningStock($conn, $card) {
        $meta = stockCardGetItemMeta($conn, (string)($card['Category_Key'] ?? ''));
        if (!$meta || !$meta['stock_source'] || !$meta['iid']) {
            return false;
        }
        try {
            $stmt = $conn->prepare("SELECT " . stockCardQuoteIdentifier($meta['stock_source']) . " FROM " . stockCardQuoteIdentifier($meta['table'])
                . " WHERE " . stockCardQuoteIdentifier($meta['iid']) . " = ? LIMIT 1");
            $stmt->execute([(int)($card['Item_IID'] ?? 0)]);
            return strtoupper(stockCardNormalizeString($stmt->fetchColumn())) === 'OPENING';
        } catch (Throwable $e) {
            return false;
        }
    }

    function stockCardBuildDetail($conn, $card) {
        $workArea = stockCardNormalizeWorkArea($card['Work_Area'] ?? 'CHO');
        $startingQuantity = (int)($card['Starting_Quantity'] ?? 0);
        $runningBalance = $startingQuantity;
        $lines = [];

        $initialLocation = stockCardFormatLocationParts(
            $card['Location_Room'] ?? '',
            $card['Location_Cabinet'] ?? '',
            $card['Location_Shelf'] ?? '',
            $card['Location_Bin'] ?? '',
            $card['Location_Note'] ?? ''
        );
        $isOpeningStock = stockCardIsOpeningStock($conn, $card);
        $receivedRemarks = [];
        if ($isOpeningStock) {
            $receivedRemarks[] = 'Opening stock entered by hand at the clinic; not distributed by CHO';
        }
        if (stockCardNormalizeString($card['PO_Number'] ?? '') !== '') {
            $receivedRemarks[] = 'PO: ' . stockCardNormalizeString($card['PO_Number']);
        }
        if ((int)($card['Donated'] ?? 0) === 1) {
            $receivedRemarks[] = 'Donated stock';
        }
        if ($initialLocation !== '') {
            $receivedRemarks[] = 'Location: ' . $initialLocation;
        }

        $lines[] = [
            'transaction_date' => stockCardFormatDateTime($card['Date_Added'] ?? '', ''),
            'movement' => $isOpeningStock ? 'OPENING STOCK' : 'RECEIVED',
            'reference_no' => $isOpeningStock ? 'OPENING-BALANCE' : 'STOCK-IN',
            'qty_in' => $startingQuantity,
            'qty_out' => 0,
            'balance_after' => $runningBalance,
            'performed_by' => $isOpeningStock ? 'manual entry' : 'system',
            'remarks' => implode(' | ', $receivedRemarks),
            'source' => 'system'
        ];

        foreach (stockCardLoadManualHistory($conn, (int)$card['SCID']) as $history) {
            $lines[] = [
                'transaction_date' => stockCardFormatDateTime($history['Transaction_Date'] ?? '', ''),
                'movement' => strtoupper(stockCardNormalizeString($history['Transaction_Type'] ?? 'UPDATE')),
                'reference_no' => stockCardNormalizeString($history['Reference_No'] ?? ''),
                'qty_in' => (int)($history['Qty_In'] ?? 0),
                'qty_out' => (int)($history['Qty_Out'] ?? 0),
                'balance_after' => isset($history['Balance_After']) && $history['Balance_After'] !== null ? (int)$history['Balance_After'] : $runningBalance,
                'performed_by' => stockCardNormalizeString($history['Performed_By'] ?? ''),
                'remarks' => implode(' | ', array_filter([
                    stockCardNormalizeString($history['From_Location'] ?? '') !== '' ? ('From: ' . stockCardNormalizeString($history['From_Location'])) : '',
                    stockCardNormalizeString($history['To_Location'] ?? '') !== '' ? ('To: ' . stockCardNormalizeString($history['To_Location'])) : '',
                    stockCardNormalizeString($history['Remarks'] ?? '')
                ])),
                'source' => 'history'
            ];
        }

        $voidedRows = 0;
        foreach (stockCardLoadCheckoutRows($conn, $card['Barcode_Number'] ?? '', $workArea) as $row) {
            if ((int)($row['is_deleted'] ?? 0) === 1) {
                $voidedRows++;
                continue;
            }

            $qtyOut = (int)($row['quantity'] ?? 0);
            $runningBalance -= $qtyOut;
            $destination = stockCardNormalizeString($row['destination'] ?? '');
            $barangay = stockCardNormalizeString($row['barangay'] ?? '');
            $remarks = stockCardNormalizeString($row['remarks'] ?? '');
            $detailParts = [];
            if ($destination !== '') {
                $detailParts[] = 'Destination: ' . $destination;
            }
            if ($barangay !== '') {
                $detailParts[] = 'Barangay: ' . $barangay;
            }
            if ($remarks !== '') {
                $detailParts[] = $remarks;
            }

            $transactionDate = stockCardFormatDateTime($row['transfer_date'] ?? '', '');
            if ($transactionDate === '') {
                $transactionDate = stockCardFormatDateTime($row['checkout_date'] ?? '', '');
            }
            $performedBy = stockCardNormalizeString($row['transfer_by'] ?? '');
            if ($performedBy === '') {
                $performedBy = stockCardNormalizeString($row['checkout_by'] ?? '');
            }

            // A negative ledger quantity is a stock count surplus: show it as stock in.
            $lines[] = [
                'transaction_date' => $transactionDate,
                'movement' => stockCardDetectMovementKind($row['reference_no'] ?? '', $row['transaction_type'] ?? '', $destination),
                'reference_no' => stockCardNormalizeString($row['reference_no'] ?? ''),
                'qty_in' => max(0, -$qtyOut),
                'qty_out' => max(0, $qtyOut),
                'balance_after' => $runningBalance,
                'performed_by' => $performedBy,
                'remarks' => implode(' | ', $detailParts),
                'source' => 'checkout'
            ];
        }

        usort($lines, function ($a, $b) {
            $aDate = stockCardNormalizeString($a['transaction_date'] ?? '');
            $bDate = stockCardNormalizeString($b['transaction_date'] ?? '');
            if ($aDate === $bDate) {
                $priority = ['system' => 0, 'history' => 1, 'checkout' => 2];
                return ($priority[$a['source']] ?? 9) <=> ($priority[$b['source']] ?? 9);
            }
            return strcmp($aDate, $bDate);
        });

        return [
            'card' => $card,
            'current_location' => $initialLocation,
            'timeline' => $lines,
            'voided_rows' => $voidedRows
        ];
    }
}
