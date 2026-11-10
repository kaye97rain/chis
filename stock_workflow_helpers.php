<?php
/**
 * Transfer receipts (clinic acknowledgement) and physical stock counts.
 *
 * Transfers still move stock immediately; a receipt only records what the destination
 * actually got. Physical counts post their variances as ADJUSTMENT rows in
 * tbl_checkedout_items (positive quantity = shortage, negative = surplus), so every
 * availability calculation, stock card and transaction history picks them up and an
 * adjustment can be voided like any other transaction.
 *
 * Call the ensure* functions BEFORE beginTransaction(): CREATE TABLE commits implicitly.
 */

if (!defined('STOCK_WORKFLOW_HELPERS_LOADED')) {
    define('STOCK_WORKFLOW_HELPERS_LOADED', true);

    // Destinations with their own staff who can confirm receipt.
    define('TRANSFER_RECEIPT_WORK_AREAS', ['AGLAYAN', 'UPLC', 'LABORATORY']);

    function ensureTransferReceiptsTable($conn) {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS tbl_transfer_receipts (
                receipt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                transfer_number VARCHAR(50) NOT NULL,
                barcode VARCHAR(50) NOT NULL,
                item_name VARCHAR(255) NULL,
                destination VARCHAR(150) NULL,
                destination_work_area VARCHAR(50) NOT NULL,
                sent_quantity INT NOT NULL,
                received_quantity INT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
                sent_by VARCHAR(150) NULL,
                sent_at DATETIME NOT NULL,
                received_by VARCHAR(150) NULL,
                received_at DATETIME NULL,
                remarks TEXT NULL,
                PRIMARY KEY (receipt_id),
                UNIQUE KEY uq_transfer_receipt_item (transfer_number, barcode),
                KEY idx_transfer_receipt_area_status (destination_work_area, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ready = true;
        } catch (Throwable $e) {
            error_log('Unable to ensure tbl_transfer_receipts: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    /**
     * Record one pending receipt line per transferred item. Runs inside the transfer transaction.
     */
    function createTransferReceipts($conn, $transferNumber, $destination, $destinationWorkArea, array $transferred, $sentBy, $sentAt) {
        $destinationWorkArea = strtoupper(trim((string)$destinationWorkArea));
        if (!in_array($destinationWorkArea, TRANSFER_RECEIPT_WORK_AREAS, true)) {
            return 0;
        }

        $stmt = $conn->prepare("
            INSERT INTO tbl_transfer_receipts (
                transfer_number, barcode, item_name, destination, destination_work_area,
                sent_quantity, status, sent_by, sent_at
            ) VALUES (
                :transfer_number, :barcode, :item_name, :destination, :work_area,
                :sent_quantity, 'PENDING', :sent_by, :sent_at
            )
            ON DUPLICATE KEY UPDATE sent_quantity = sent_quantity + VALUES(sent_quantity)
        ");
        $count = 0;
        foreach ($transferred as $line) {
            $stmt->execute([
                ':transfer_number' => $transferNumber,
                ':barcode' => (string)($line['barcode'] ?? ''),
                ':item_name' => (string)($line['item'] ?? ''),
                ':destination' => (string)$destination,
                ':work_area' => $destinationWorkArea,
                ':sent_quantity' => (int)($line['quantity'] ?? 0),
                ':sent_by' => (string)$sentBy,
                ':sent_at' => $sentAt
            ]);
            $count++;
        }
        return $count;
    }

    function ensureStockCountTables($conn) {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS tbl_stock_counts (
                count_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                work_area VARCHAR(50) NOT NULL,
                category_key VARCHAR(50) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
                count_date DATE NOT NULL,
                remarks TEXT NULL,
                counted_by VARCHAR(150) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                posted_by VARCHAR(150) NULL,
                posted_at DATETIME NULL,
                post_reason TEXT NULL,
                adjustment_ref VARCHAR(50) NULL,
                PRIMARY KEY (count_id),
                KEY idx_stock_count_area_status (work_area, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $conn->exec("CREATE TABLE IF NOT EXISTS tbl_stock_count_lines (
                line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                count_id BIGINT UNSIGNED NOT NULL,
                barcode VARCHAR(50) NOT NULL,
                item_name VARCHAR(255) NULL,
                description VARCHAR(255) NULL,
                expiry_date VARCHAR(30) NULL,
                system_quantity INT NOT NULL,
                counted_quantity INT NOT NULL,
                variance INT NOT NULL,
                PRIMARY KEY (line_id),
                UNIQUE KEY uq_stock_count_line (count_id, barcode),
                KEY idx_stock_count_line_count (count_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ready = true;
        } catch (Throwable $e) {
            error_log('Unable to ensure stock count tables: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }

    function getStockCountCategories() {
        return [
            'medicine' => ['table' => 'tbl_item_medicine', 'masterlist' => 'tbl_masterlist_medicine', 'label' => 'Medicine'],
            'medical_supplies' => ['table' => 'tbl_item_medical_supplies', 'masterlist' => 'tbl_masterlist_medical_supplies', 'label' => 'Medical Supplies'],
            'vaccines' => ['table' => 'tbl_item_vaccines', 'masterlist' => 'tbl_masterlist_vaccines', 'label' => 'Vaccines'],
            'lab_reagents' => ['table' => 'tbl_item_lab_reagents', 'masterlist' => 'tbl_masterlist_lab_reagents', 'label' => 'Lab Reagents']
        ];
    }

    /**
     * Current system quantity of every batch (barcode) in one category and work area:
     * stock rows minus non-voided ledger rows, the same rule checkout uses.
     * Returns [barcode => ['barcode', 'item_name', 'description', 'expiry_date', 'system_quantity']].
     */
    function loadStockCountSystemQuantities($conn, $categoryKey, $workArea) {
        $categories = getStockCountCategories();
        if (!isset($categories[$categoryKey])) {
            return [];
        }
        $table = $categories[$categoryKey]['table'];

        $stmt = $conn->prepare("
            SELECT
                stock.barcode_number,
                stock.item_name,
                stock.description,
                stock.expiry_date,
                stock.total_quantity - COALESCE(ledger.total_out, 0) AS system_quantity
            FROM (
                SELECT
                    Barcode_Number AS barcode_number,
                    MAX(Item) AS item_name,
                    MAX(Description) AS description,
                    MIN(Expiry_Date) AS expiry_date,
                    SUM(Quantity) AS total_quantity
                FROM {$table}
                WHERE COALESCE(isDeleted, 0) = 0
                  AND UPPER(COALESCE(NULLIF(TRIM(Work_Area), ''), 'CHO')) = :item_work_area
                  AND COALESCE(Barcode_Number, '') <> ''
                GROUP BY Barcode_Number
            ) stock
            LEFT JOIN (
                SELECT Barcode, SUM(Quantity) AS total_out
                FROM tbl_checkedout_items
                WHERE COALESCE(isDeleted, 0) = 0
                  AND UPPER(COALESCE(NULLIF(TRIM(Work_Area), ''), 'CHO')) = :ledger_work_area
                GROUP BY Barcode
            ) ledger ON ledger.Barcode = stock.barcode_number
            ORDER BY stock.item_name ASC, stock.expiry_date ASC
        ");
        $stmt->execute([':item_work_area' => $workArea, ':ledger_work_area' => $workArea]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $barcode = (string)$row['barcode_number'];
            $rows[$barcode] = [
                'barcode' => $barcode,
                'item_name' => (string)($row['item_name'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'expiry_date' => (string)($row['expiry_date'] ?? ''),
                'system_quantity' => (int)$row['system_quantity']
            ];
        }
        return $rows;
    }

    function generateAdjustmentNumber($conn) {
        $prefix = 'ADJ-' . date('Ymd') . '-';
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT RIS_Number) FROM tbl_checkedout_items WHERE RIS_Number LIKE :prefix");
        $stmt->execute([':prefix' => $prefix . '%']);
        $next = (int)$stmt->fetchColumn() + 1;
        return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    }

    // Clinics that may record the stock they already had on hand before EMedTrack (opening stock).
    define('OPENING_STOCK_WORK_AREAS', ['AGLAYAN', 'UPLC']);
    // Stock_Source value of a batch encoded by hand at the clinic, not distributed by CHO.
    // NULL means a normal batch (received at CHO, or transferred from CHO).
    define('STOCK_SOURCE_OPENING', 'OPENING');

    /**
     * Add the Stock_Source column to the four item tables if a deployment has not run the
     * migration yet. Call BEFORE beginTransaction(): ALTER TABLE commits implicitly.
     */
    function ensureStockSourceColumns($conn) {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $ready = true;
        foreach (getStockCountCategories() as $category) {
            $table = $category['table'];
            try {
                $stmt = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'Stock_Source'");
                if ($stmt && $stmt->fetch()) {
                    continue;
                }
                $conn->exec("ALTER TABLE {$table} ADD COLUMN Stock_Source VARCHAR(20) NULL DEFAULT NULL");
            } catch (Throwable $e) {
                error_log("Unable to ensure {$table}.Stock_Source: " . $e->getMessage());
                $ready = false;
            }
        }
        return $ready;
    }

    /**
     * Unique batch number for an opening-stock entry, e.g. OB-AGLAYAN-261110-3FA9C2.
     * Its own prefix keeps it apart from CHO batch numbers, so a later CHO transfer of a
     * real batch never merges into a hand-encoded one.
     */
    function generateOpeningStockBarcode($conn, $table, $workArea) {
        $area = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$workArea));
        $prefix = 'OB-' . ($area !== '' ? $area : 'CLINIC') . '-' . date('ymd') . '-';
        $check = $conn->prepare("SELECT 1 FROM {$table} WHERE Barcode_Number = :barcode LIMIT 1");
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = $prefix . strtoupper(bin2hex(random_bytes(3)));
            $check->execute([':barcode' => $candidate]);
            if (!$check->fetchColumn()) {
                return $candidate;
            }
        }
        throw new Exception('Unable to generate a unique opening stock batch number. Please try again.');
    }
}
