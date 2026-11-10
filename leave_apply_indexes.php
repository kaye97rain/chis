<?php
/**
 * leave_apply_indexes.php — One-time index migration for the Leave module.
 *
 * Run once via browser or CLI:
 *   php API/leave_apply_indexes.php
 *   http://localhost/newsystems/HRMIS/API/leave_apply_indexes.php
 *
 * Safe to run multiple times — skips indexes that already exist.
 */

require_once __DIR__ . '/leave_db.php';

header('Content-Type: text/plain; charset=utf-8');

$indexes = [
    // Regular payroll: covering index for "latest PPID per TID" lookup
    // Reduces correlated subquery from N payroll rows to M template rows
    ['tbl_syl_payroll_parent',        'idx_pp_tid_lookup',   '(TID, isDeleted, Quencina, year, End_num, Date_Updated, PPID)'],
    // Casual payroll: same purpose
    ['tbl_syl_payroll_parent_casual', 'idx_ppc_tid_lookup',  '(TID, isDeleted, Quencina, year, End_num, Date_Updated, PPID)'],
    // Template name JOIN: speeds up casual→regular name bridge
    ['tbl_template_payroll',          'idx_tp_deleted_name', '(isDeleted, Name)'],
    ['tbl_template_payroll_casual',   'idx_tpc_deleted_name','(isDeleted, Name)'],
    // Service record: speeds up batch status lookup by PIID
    ['tbl_service_record',            'idx_sr_piid_status',  '(PIID, Status)'],
    // Employee name: prefix scan for LIKE \'surname%\' (may already exist)
    ['tblpersonalinformation',        'idx_pi_name_search',  '(SurName, FirstName)'],
];

$created = 0;
$skipped = 0;
$failed  = 0;

foreach ($indexes as [$table, $name, $cols]) {
    $check = $leave_conn->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $check->execute([$table, $name]);
    if ((int)$check->fetchColumn() > 0) {
        echo "[SKIP]  $table  →  $name  (already exists)\n";
        $skipped++;
        continue;
    }
    try {
        $leave_conn->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols");
        echo "[OK]    $table  →  $name\n";
        $created++;
    } catch (PDOException $e) {
        echo "[FAIL]  $table  →  $name  :  " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\nDone. Created: $created  |  Skipped: $skipped  |  Failed: $failed\n";
