<?php
/**
 * leave_db.php — PDO connection + all DB query functions for the Leave Credits module.
 *
 * Uses the same cgmhris database as pds_db.php (PDS_DB_* env vars).
 * Exposes $leave_conn (PDO) and a set of functions used by leave_api.php.
 *
 * Tables used:
 *   tbl_syl_leave_form               — individual leave ledger entries
 *   tbl_syl_leave_available_balance  — per-employee per-year forwarded balances
 *   tbl_syl_leave_credits_earned     — lookup: days present + LWOP → credits earned
 *   tbl_syl_leave_conversion_of_working_day — lookup: time value + type → day fraction
 *   tbl_syl_employee_masterlist      — employee list (PIID, name, dept, etc.)
 */

if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $name  = trim($name);
            $value = trim($value);
            if ($name && !array_key_exists($name, $_ENV)) {
                putenv("$name=$value");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }
}
loadEnv(__DIR__ . '/.env');

$_lv_host = getenv('DB_HOST') ?: 'localhost';
$_lv_db   = getenv('DB_NAME') ?: 'hrmis';
$_lv_user = getenv('DB_USER') ?: 'root';
$_lv_pass = getenv('DB_PASS') ?: '';

try {
    $leave_conn = new PDO(
        "mysql:host=$_lv_host;dbname=$_lv_db;charset=utf8",
        $_lv_user,
        $_lv_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $leave_conn->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    error_log('leave_db.php: ' . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Leave database unavailable']);
    exit;
}

// ── Payroll-based employee source (regular + casual consolidated) ─────────────
// Regular: tbl_syl_payroll_parent → tbl_template_payroll
// Casual:  tbl_syl_payroll_parent_casual → tbl_template_payroll_casual → bridged
//          to tbl_template_payroll by matching Name, so both show under the same
//          consolidated department label.
//
// OPTIMIZED: the "latest PPID per TID" lookup is pre-computed once per unique
// template (≈33 rows) via a derived table, instead of running a correlated
// subquery for every row in tbl_syl_payroll_parent (potentially thousands).

define('_LV_EMP_BASE_WHERE', '1');
define('_LV_EMP_DEPT_EXPR',
    "SUBSTRING_INDEX(GROUP_CONCAT(emp_src.Department ORDER BY emp_src.PayYear DESC, emp_src.End_Date DESC, emp_src.End_num DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1)"
);
define('_LV_EMP_POS_EXPR',
    "SUBSTRING_INDEX(GROUP_CONCAT(emp_src.Position ORDER BY emp_src.PayYear DESC, emp_src.End_Date DESC, emp_src.End_num DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1)"
);
define('_LV_EMP_END_DATE_EXPR',
    "SUBSTRING_INDEX(GROUP_CONCAT(emp_src.End_Date ORDER BY emp_src.PayYear DESC, emp_src.End_Date DESC, emp_src.End_num DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1)"
);
define('_LV_EMP_PAYYEAR_EXPR',
    "SUBSTRING_INDEX(GROUP_CONCAT(emp_src.PayYear ORDER BY emp_src.PayYear DESC, emp_src.End_Date DESC, emp_src.End_num DESC, emp_src.Date_Updated DESC SEPARATOR '\x1F'), '\x1F', 1)"
);
define('_LV_EMP_STATUS_EXPR',
    "(SELECT sr.Status
      FROM tbl_service_record sr
      WHERE sr.PIID = pi.PIID
        AND sr.Status IS NOT NULL
        AND sr.Status <> ''
      ORDER BY COALESCE(sr.Inc_Date_To, sr.Inc_Date_from, sr.Date) DESC, sr.Inc_Date_from DESC
      LIMIT 1)"
);
define('_LV_EMP_ROSTER_TABLE', 'tbl_syl_leave_employee_roster');
define('_LV_EMP_ROSTER_LOCK', 'lv_emp_roster_refresh');
define('_LV_EMP_ROSTER_TTL_SECONDS', 21600);
define('_LV_EMP_ROSTER_SOURCE_VERSION', '6');
define('_LV_MONTHLY_LEAVE_EARNED_LOCK', 'lv_monthly_leave_earned');
define('_LV_MONTHLY_LEAVE_EARNED_EVENT', 'ev_monthly_leave_earned');
define('_LV_MONTHLY_LEAVE_EARNED_PROC', 'sp_insert_monthly_leave_earned');
define('_LV_MONTHLY_LEAVE_EARNED_TRACKER', 'tbl_syl_leave_monthly_jobs');
  define('_LV_RECALC_STATUS_TABLE', 'tbl_syl_leave_recalc_status');
  define('_LV_DEFAULT_MANDATORY_FORCED_LEAVE', 5.0);
  define('_LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE', 3.0);

function lv_ensure_available_balance_schema(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $stmt = $conn->query("SHOW COLUMNS FROM tbl_syl_leave_available_balance LIKE 'NoAvailSLManual'");
    if (!$stmt->fetch()) {
        $conn->exec(
            "ALTER TABLE tbl_syl_leave_available_balance
             ADD COLUMN NoAvailSLManual TINYINT(1) NOT NULL DEFAULT 0 AFTER NoAvailSL"
        );
    }

    $stmt = $conn->query("SHOW COLUMNS FROM tbl_syl_leave_available_balance LIKE 'NoAvailSLManualSetAt'");
    if (!$stmt->fetch()) {
        $conn->exec(
            "ALTER TABLE tbl_syl_leave_available_balance
             ADD COLUMN NoAvailSLManualSetAt DATETIME NULL AFTER NoAvailSLManual"
        );
    }

    $ensured = true;
}

function lv_special_leave_manual_override(array $balance): bool {
    return (int)($balance['NoAvailSLManual'] ?? 0) === 1
        && trim((string)($balance['NoAvailSLManualSetAt'] ?? '')) !== '';
}

function lv_employee_ref_secret(): string {
    $secret = (string)(getenv('APP_KEY') ?: getenv('JWT_SECRET') ?: getenv('DB_PASS') ?: '');
    return $secret !== '' ? $secret : 'hrmis-leave-employee-ref';
}

function lv_employee_ref(string $piid): string {
    return rtrim(strtr(base64_encode(hash_hmac('sha256', $piid, lv_employee_ref_secret(), true)), '+/', '-_'), '=');
}

function lv_resolve_employee_ref(PDO $conn, string $ref): ?string {
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }

    lv_ensure_employee_roster($conn);
    $stmt = $conn->query('SELECT PIID FROM ' . _LV_EMP_ROSTER_TABLE);
    while (($piid = $stmt->fetchColumn()) !== false) {
        $piid = (string)$piid;
        if (hash_equals(lv_employee_ref($piid), $ref)) {
            return $piid;
        }
    }

    return null;
}

function lv_normalize_mandatory_forced_leave($value): float {
    $num = (float)$value;
    return $num > 0 ? $num : _LV_DEFAULT_MANDATORY_FORCED_LEAVE;
}

function lv_normalize_employee_status(?string $service_status, bool $is_casual): string {
    $status = strtoupper(trim((string)$service_status));

    if ($status === 'REGULAR' || $status === 'PERMANENT') {
        return 'Permanent';
    }
    if ($status === 'CASUAL') {
        return 'Casual';
    }

    return $is_casual ? 'Casual' : 'Permanent';
}

function lv_department_match_key_sql(string $expr): string {
    $normalized = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE($expr, ''))), '&', 'AND'), '.', ''), ',', ''), '-', ''), '/', ''), '(', ''), ')', ''), ' ', ''), '''', '')";
    return "REPLACE($normalized, 'CITYACCOUNTANTSOFFICE', 'CITYACCOUNTING')";
}

function lv_department_canonical_name_sql(string $expr): string {
    $deptKey = lv_department_match_key_sql($expr);
    return "CASE
        WHEN $deptKey IN ('CITYACCOUNTING', 'CITYACCOUNTINGOFFICE') THEN 'CITY ACCOUNTANT''S OFFICE'
        ELSE UPPER(TRIM(COALESCE($expr, '')))
    END";
}

function lv_regular_department_match_join_sql(string $source_expr, string $alias = 'tp_match'): string {
    $regular_key = lv_department_match_key_sql('tp_norm.Name');
    $source_key = lv_department_match_key_sql($source_expr);

    return "LEFT JOIN (
                SELECT DeptKey, MIN(Name) AS Name
                FROM (
                    SELECT tp_norm.Name, $regular_key AS DeptKey
                    FROM tbl_template_payroll tp_norm
                    WHERE tp_norm.isDeleted = 0
                ) regular_map
                WHERE DeptKey <> ''
                GROUP BY DeptKey
            ) $alias ON $alias.DeptKey = $source_key";
}

function lv_employee_roster_create_sql(string $table): string {
    return "CREATE TABLE IF NOT EXISTS `$table` (
        `PIID` varchar(20) NOT NULL,
        `ID_NUM` varchar(20) DEFAULT NULL,
        `Surname` varchar(80) DEFAULT NULL,
        `Firstname` varchar(80) DEFAULT NULL,
        `MiddleName` varchar(80) DEFAULT NULL,
        `Department` varchar(255) DEFAULT NULL,
        `Position` varchar(255) DEFAULT NULL,
        `IsCasual` tinyint(1) NOT NULL DEFAULT 0,
        `End_Date` varchar(255) DEFAULT NULL,
        `PayYear` varchar(20) DEFAULT NULL,
        `Status` varchar(255) DEFAULT NULL,
        `FirstGovServiceDate` date DEFAULT NULL,
        `SourceFingerprint` varchar(64) DEFAULT NULL,
        `SnapshotYear` int(11) NOT NULL,
        `SnapshotMonth` int(11) NOT NULL,
        `RefreshedAt` datetime NOT NULL,
        PRIMARY KEY (`PIID`),
        KEY `idx_lv_roster_name` (`Surname`,`Firstname`),
        KEY `idx_lv_roster_firstname` (`Firstname`,`Surname`),
        KEY `idx_lv_roster_department` (`Department`),
        KEY `idx_lv_roster_snapshot` (`SnapshotYear`,`SnapshotMonth`,`RefreshedAt`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
}

function lv_employee_roster_ensure_schema(PDO $conn, ?string $table = null): void {
    $table = $table ?: _LV_EMP_ROSTER_TABLE;
    $conn->exec(lv_employee_roster_create_sql($table));
    $col_stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
    );
    $has_column = function (string $column) use ($col_stmt, $table): bool {
        $col_stmt->execute([$table, $column]);
        return (int)$col_stmt->fetchColumn() > 0;
    };
    $add_column = function (string $column, string $definition) use ($conn, $table, $has_column): void {
        if (!$has_column($column)) {
            $conn->exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $definition);
        }
    };

    $add_column('ID_NUM', '`ID_NUM` varchar(20) DEFAULT NULL AFTER `PIID`');
    $add_column('Surname', '`Surname` varchar(80) DEFAULT NULL AFTER `ID_NUM`');
    $add_column('Firstname', '`Firstname` varchar(80) DEFAULT NULL AFTER `Surname`');
    $add_column('MiddleName', '`MiddleName` varchar(80) DEFAULT NULL AFTER `Firstname`');
    $add_column('Department', '`Department` varchar(255) DEFAULT NULL AFTER `MiddleName`');
    $add_column('Position', '`Position` varchar(255) DEFAULT NULL AFTER `Department`');
    $add_column('IsCasual', '`IsCasual` tinyint(1) NOT NULL DEFAULT 0 AFTER `Position`');
    $add_column('End_Date', '`End_Date` varchar(255) DEFAULT NULL AFTER `IsCasual`');
    $add_column('PayYear', '`PayYear` varchar(20) DEFAULT NULL AFTER `End_Date`');
    $add_column('Status', '`Status` varchar(255) DEFAULT NULL AFTER `PayYear`');
    $add_column('FirstGovServiceDate', '`FirstGovServiceDate` date DEFAULT NULL AFTER `Status`');
    $add_column('SourceFingerprint', '`SourceFingerprint` varchar(64) DEFAULT NULL AFTER `FirstGovServiceDate`');
    $add_column('SnapshotYear', '`SnapshotYear` int(11) NOT NULL DEFAULT 0 AFTER `SourceFingerprint`');
    $add_column('SnapshotMonth', '`SnapshotMonth` int(11) NOT NULL DEFAULT 0 AFTER `SnapshotYear`');
    $add_column('RefreshedAt', '`RefreshedAt` datetime NULL DEFAULT NULL AFTER `SnapshotMonth`');
}

function lv_monthly_leave_earned_tracker_ensure_schema(PDO $conn): void {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `" . _LV_MONTHLY_LEAVE_EARNED_TRACKER . "` (
            `JobKey` varchar(80) NOT NULL,
            `JobPeriod` char(7) NOT NULL,
            `RanAt` datetime NOT NULL,
            `RunSource` varchar(40) NOT NULL,
            PRIMARY KEY (`JobKey`, `JobPeriod`),
            KEY `idx_lv_monthly_jobs_ran_at` (`RanAt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
}

function lv_monthly_leave_earned_procedure_sql(): string {
    return <<<SQL
CREATE PROCEDURE sp_insert_monthly_leave_earned()
BEGIN
    DECLARE v_done INT DEFAULT FALSE;
    DECLARE v_piid VARCHAR(50);
    DECLARE v_vac_bal DOUBLE DEFAULT 0;
    DECLARE v_sick_bal DOUBLE DEFAULT 0;
    DECLARE v_earn_rate DOUBLE DEFAULT 1.25;
    DECLARE v_last_day INT;
    DECLARE v_month_pad VARCHAR(2);
    DECLARE v_from_date DATE;
    DECLARE v_to_date DATE;
    DECLARE v_date_action VARCHAR(50);
    DECLARE v_cur_month INT;
    DECLARE v_max_month INT;
    DECLARE v_cur_year INT;
    DECLARE v_exists INT;
    DECLARE v_sync_vac DOUBLE;
    DECLARE v_sync_sick DOUBLE;

    DECLARE emp_cur CURSOR FOR
        SELECT DISTINCT eligible.PIID
        FROM (
            SELECT p.PIID
            FROM tbl_syl_payroll_parent pp
            INNER JOIN tbl_template_payroll tp
                    ON pp.TID = tp.TID
                   AND tp.isDeleted = 0
            INNER JOIN tblpersonalinformation p ON pp.PIID = p.PIID
            WHERE pp.isDeleted = 0
              AND pp.Quencina = 'whole'
              AND pp.year <= YEAR(NOW())
              AND tp.NAME NOT LIKE '%JOB ORDER%'
              AND tp.NAME NOT LIKE '%JO%'
              AND tp.NAME NOT LIKE '%J.O%'

            UNION

            SELECT p.PIID
            FROM tbl_syl_payroll_parent_casual pc
            INNER JOIN tbl_template_payroll_casual tpc
                    ON pc.TID = tpc.TID
                   AND tpc.isDeleted = 0
            INNER JOIN tblpersonalinformation p ON pc.PIID = p.PIID
            WHERE pc.isDeleted = 0
              AND pc.Quencina = 'whole'
              AND pc.year <= YEAR(NOW())
              AND tpc.NAME NOT LIKE '%JOB ORDER%'
              AND tpc.NAME NOT LIKE '%JO%'
              AND tpc.NAME NOT LIKE '%J.O%'
        ) eligible
        WHERE eligible.PIID IS NOT NULL;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = TRUE;

    SELECT COALESCE(MAX(Leave_Credits_Earned), 1.25)
    INTO v_earn_rate
    FROM tbl_syl_leave_credits_earned;

    SET v_max_month = MONTH(NOW());
    SET v_cur_year = YEAR(NOW());

    OPEN emp_cur;

    emp_loop: LOOP
        FETCH emp_cur INTO v_piid;
        IF v_done THEN
            LEAVE emp_loop;
        END IF;

        SELECT COALESCE(MAX(cBackVaca), 0)
        INTO v_vac_bal
        FROM tbl_syl_leave_available_balance
        WHERE piid = v_piid AND year = v_cur_year;

        SELECT COALESCE(MAX(cBalSick), 0)
        INTO v_sick_bal
        FROM tbl_syl_leave_available_balance
        WHERE piid = v_piid AND year = v_cur_year;

        SET v_cur_month = 1;

        month_loop: WHILE v_cur_month <= v_max_month DO
            SET v_month_pad = LPAD(v_cur_month, 2, '0');
            SET v_from_date = STR_TO_DATE(CONCAT(v_cur_year, '-', v_month_pad, '-01'), '%Y-%m-%d');
            SET v_to_date = LAST_DAY(v_from_date);
            SET v_last_day = DAY(v_to_date);
            SET v_date_action = CONCAT(
                ELT(v_cur_month, 'JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'),
                '.01-', v_last_day, ', ', v_cur_year
            );

            SELECT COUNT(1) INTO v_exists
            FROM tbl_syl_leave_form
            WHERE Type_of_Records LIKE '%LEAVE EARNED%'
              AND Particulars LIKE 'LEAVE EARNED%'
              AND isDeleted = 0
              AND PIID = v_piid
              AND Date_of_Filing BETWEEN v_from_date AND v_to_date;

            IF v_exists > 0 THEN
                SELECT VacBal, SickBal
                INTO v_sync_vac, v_sync_sick
                FROM tbl_syl_leave_form
                WHERE Type_of_Records LIKE '%LEAVE EARNED%'
                  AND Particulars LIKE 'LEAVE EARNED%'
                  AND isDeleted = 0
                  AND PIID = v_piid
                  AND Date_of_Filing BETWEEN v_from_date AND v_to_date
                ORDER BY Date_of_Filing DESC, DateProcessed DESC
                LIMIT 1;

                SET v_vac_bal = v_sync_vac;
                SET v_sick_bal = v_sync_sick;
            ELSE
                SET v_vac_bal = v_vac_bal + v_earn_rate;
                SET v_sick_bal = v_sick_bal + v_earn_rate;

                INSERT INTO tbl_syl_leave_form (
                    PIID, Type_of_Records,
                    Period_From, Period_To, Date_of_Filing,
                    VacEarn, VacBal,
                    SickEarn, SickBal,
                    Particulars, DateAction,
                    no_avail_VL, no_avail_SL, no_avail_SP, no_avail_P,
                    DateProcessed, RecordedBy
                ) VALUES (
                    v_piid, 'Leave Earned|-N/A-',
                    v_from_date, v_to_date, v_from_date,
                    v_earn_rate, v_vac_bal,
                    v_earn_rate, v_sick_bal,
                    CONCAT('LEAVE EARNED ', v_cur_year), v_date_action,
                    0, 0, 0, 0,
                    v_from_date, 'Added by System'
                );
            END IF;

            SET v_cur_month = v_cur_month + 1;
        END WHILE;
    END LOOP;

    CLOSE emp_cur;
END
SQL;
}

function lv_monthly_leave_earned_event_starts(): string {
    $starts = new DateTimeImmutable('first day of next month 00:01:00');
    return $starts->format('Y-m-d H:i:s');
}

function lv_monthly_leave_earned_ensure_procedure(PDO $conn): void {
    $conn->exec('DROP PROCEDURE IF EXISTS ' . _LV_MONTHLY_LEAVE_EARNED_PROC);
    $conn->exec(lv_monthly_leave_earned_procedure_sql());
}

function lv_monthly_leave_earned_ensure_event(PDO $conn): void {
    $conn->exec('DROP EVENT IF EXISTS ' . _LV_MONTHLY_LEAVE_EARNED_EVENT);
    $conn->exec(
        "CREATE EVENT " . _LV_MONTHLY_LEAVE_EARNED_EVENT . "
            ON SCHEDULE EVERY 1 MONTH
            STARTS '" . lv_monthly_leave_earned_event_starts() . "'
            ON COMPLETION PRESERVE
            ENABLE
            COMMENT 'Auto-inserts Leave Earned for all regular employees; backfills missing months'
            DO CALL " . _LV_MONTHLY_LEAVE_EARNED_PROC . "()"
    );
}

function lv_monthly_leave_earned_has_run(PDO $conn, string $job_period): bool {
    lv_monthly_leave_earned_tracker_ensure_schema($conn);
    $stmt = $conn->prepare(
        'SELECT 1
         FROM ' . _LV_MONTHLY_LEAVE_EARNED_TRACKER . '
         WHERE JobKey = ?
           AND JobPeriod = ?
         LIMIT 1'
    );
    $stmt->execute([_LV_MONTHLY_LEAVE_EARNED_PROC, $job_period]);
    return (bool)$stmt->fetchColumn();
}

function lv_monthly_leave_earned_mark_run(PDO $conn, string $job_period, string $source): void {
    $stmt = $conn->prepare(
        'INSERT INTO ' . _LV_MONTHLY_LEAVE_EARNED_TRACKER . '
            (JobKey, JobPeriod, RanAt, RunSource)
         VALUES
            (:job_key, :job_period, NOW(), :run_source)
         ON DUPLICATE KEY UPDATE
            RanAt = VALUES(RanAt),
            RunSource = VALUES(RunSource)'
    );
    $stmt->execute([
        ':job_key' => _LV_MONTHLY_LEAVE_EARNED_PROC,
        ':job_period' => $job_period,
        ':run_source' => $source,
    ]);
}

function lv_monthly_leave_earned_run_if_due(PDO $conn): void {
    $job_period = date('Y-m');
    if (lv_monthly_leave_earned_has_run($conn, $job_period)) {
        return;
    }

    $lock_stmt = $conn->prepare('SELECT GET_LOCK(?, 5)');
    $lock_stmt->execute([_LV_MONTHLY_LEAVE_EARNED_LOCK]);
    $locked = (int)$lock_stmt->fetchColumn() === 1;
    if (!$locked) {
        return;
    }

    try {
        if (lv_monthly_leave_earned_has_run($conn, $job_period)) {
            return;
        }
        $conn->exec('CALL ' . _LV_MONTHLY_LEAVE_EARNED_PROC . '()');
        lv_monthly_leave_earned_mark_run($conn, $job_period, 'php-fallback');
    } finally {
        $unlock_stmt = $conn->prepare('DO RELEASE_LOCK(?)');
        $unlock_stmt->execute([_LV_MONTHLY_LEAVE_EARNED_LOCK]);
    }
}

function lv_monthly_leave_earned_run_now(PDO $conn, string $source = 'manual'): void {
    lv_monthly_leave_earned_tracker_ensure_schema($conn);
    lv_monthly_leave_earned_ensure_procedure($conn);

    $lock_stmt = $conn->prepare('SELECT GET_LOCK(?, 30)');
    $lock_stmt->execute([_LV_MONTHLY_LEAVE_EARNED_LOCK]);
    $locked = (int)$lock_stmt->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Monthly leave earned job is already running');
    }

    try {
        $conn->exec('CALL ' . _LV_MONTHLY_LEAVE_EARNED_PROC . '()');
        lv_monthly_leave_earned_mark_run($conn, date('Y-m'), $source);
    } finally {
        $unlock_stmt = $conn->prepare('DO RELEASE_LOCK(?)');
        $unlock_stmt->execute([_LV_MONTHLY_LEAVE_EARNED_LOCK]);
    }
}

function lv_backfill_employee_leave_earned(PDO $conn, string $piid, int $year): int {
    $piid = trim($piid);
    if ($piid === '') {
        throw new InvalidArgumentException('PIID required');
    }

    $earn_rate = (float)$conn->query(
        'SELECT COALESCE(MAX(Leave_Credits_Earned), 1.25)
         FROM tbl_syl_leave_credits_earned'
    )->fetchColumn();
    if ($earn_rate <= 0) {
        $earn_rate = 1.25;
    }

    $max_month = $year === (int)date('Y') ? (int)date('n') : 12;
    $exists_stmt = $conn->prepare(
        "SELECT COUNT(1)
           FROM tbl_syl_leave_form
          WHERE Type_of_Records LIKE '%LEAVE EARNED%'
            AND Particulars LIKE 'LEAVE EARNED%'
            AND isDeleted = 0
            AND PIID = :piid
            AND Date_of_Filing BETWEEN :from_date AND :to_date"
    );
    $insert_stmt = $conn->prepare(
        "INSERT INTO tbl_syl_leave_form (
            PIID, Type_of_Records,
            Period_From, Period_To, Date_of_Filing,
            VacEarn, VacBal,
            SickEarn, SickBal,
            Particulars, DateAction,
            no_avail_VL, no_avail_SL, no_avail_SP, no_avail_P,
            DateProcessed, RecordedBy
        ) VALUES (
            :piid, 'Leave Earned|-N/A-',
            :period_from, :period_to, :date_filing,
            :vac_earn, 0,
            :sick_earn, 0,
            :particulars, :date_action,
            0, 0, 0, 0,
            :date_processed, 'Added by System'
        )"
    );

    $inserted = 0;
    for ($month = 1; $month <= $max_month; $month++) {
        $from_date = sprintf('%04d-%02d-01', $year, $month);
        $to_date = date('Y-m-t', strtotime($from_date));
        $exists_stmt->execute([
            ':piid' => $piid,
            ':from_date' => $from_date,
            ':to_date' => $to_date,
        ]);
        if ((int)$exists_stmt->fetchColumn() > 0) {
            continue;
        }

        $insert_stmt->execute([
            ':piid' => $piid,
            ':period_from' => $from_date,
            ':period_to' => $to_date,
            ':date_filing' => $from_date,
            ':vac_earn' => $earn_rate,
            ':sick_earn' => $earn_rate,
            ':particulars' => 'LEAVE EARNED ' . $year,
            ':date_action' => strtoupper(date('M', strtotime($from_date))) . '.01-' . date('j', strtotime($to_date)) . ', ' . $year,
            ':date_processed' => $from_date,
        ]);
        $inserted++;
    }

    if ($inserted > 0) {
        lv_recalculate($conn, $piid, $year, 'leave-earned-backfill');
    }

    return $inserted;
}

function lv_ensure_monthly_leave_earned_automation(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    try {
        lv_monthly_leave_earned_tracker_ensure_schema($conn);
        lv_monthly_leave_earned_ensure_procedure($conn);
        try {
            lv_monthly_leave_earned_ensure_event($conn);
        } catch (Throwable $event_error) {
            error_log('lv_monthly_leave_earned_ensure_event: ' . $event_error->getMessage());
        }
        lv_monthly_leave_earned_run_if_due($conn);
    } catch (Throwable $e) {
        error_log('lv_ensure_monthly_leave_earned_automation: ' . $e->getMessage());
    }
}

function lv_employee_roster_source_fingerprint(PDO $conn): string {
    $row = $conn->query(
        "SELECT MD5(CONCAT_WS('|',
            '" . _LV_EMP_ROSTER_SOURCE_VERSION . "',
            (SELECT COUNT(*) FROM tblpersonalinformation),
            (SELECT COALESCE(MAX(PIID), 0) FROM tblpersonalinformation),
            (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', PIID, ID_NUM, SurName, FirstName, MiddleName))), 0) FROM tblpersonalinformation),
            (SELECT COUNT(*) FROM tbl_syl_payroll_parent WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COALESCE(MAX(CONCAT_WS('|', Date_Updated, PPID, Year, End_Num, TID)), '') FROM tbl_syl_payroll_parent WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COUNT(*) FROM tbl_syl_payroll_parent_casual WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COALESCE(MAX(CONCAT_WS('|', Date_Updated, PPID, Year, End_Num, TID)), '') FROM tbl_syl_payroll_parent_casual WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COUNT(*) FROM tbl_template_payroll),
            (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', TID, Name, isDeleted))), 0) FROM tbl_template_payroll),
            (SELECT COUNT(*) FROM tbl_template_payroll_casual),
            (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', TID, Name, isDeleted))), 0) FROM tbl_template_payroll_casual),
            (SELECT COUNT(*) FROM tbl_service_record),
            (SELECT COALESCE(MAX(CONCAT_WS('|', COALESCE(Last_Updated, ''), COALESCE(Inc_Date_To, ''), COALESCE(Inc_Date_from, ''), COALESCE(Date, ''), COALESCE(PIID, ''), COALESCE(Status, ''))), '') FROM tbl_service_record)
        )) AS fingerprint"
    )->fetch() ?: [];

    return (string)($row['fingerprint'] ?? '');
}

function lv_employee_roster_meta(PDO $conn): array {
    lv_employee_roster_ensure_schema($conn);
    $row = $conn->query(
        'SELECT COUNT(*) AS row_count,
                MAX(SnapshotYear) AS SnapshotYear,
                MAX(SnapshotMonth) AS SnapshotMonth,
                MAX(RefreshedAt) AS RefreshedAt,
                MAX(SourceFingerprint) AS SourceFingerprint
         FROM ' . _LV_EMP_ROSTER_TABLE
    )->fetch() ?: [];

    return [
        'row_count' => (int)($row['row_count'] ?? 0),
        'SnapshotYear' => (int)($row['SnapshotYear'] ?? 0),
        'SnapshotMonth' => (int)($row['SnapshotMonth'] ?? 0),
        'RefreshedAt' => (string)($row['RefreshedAt'] ?? ''),
        'SourceFingerprint' => (string)($row['SourceFingerprint'] ?? ''),
    ];
}

function lv_employee_roster_is_fresh(array $meta, int $target_year, int $target_month, string $source_fingerprint = ''): bool {
    if (($meta['row_count'] ?? 0) <= 0) {
        return false;
    }
    if ((int)($meta['SnapshotYear'] ?? 0) !== $target_year) {
        return false;
    }
    if ((int)($meta['SnapshotMonth'] ?? 0) !== $target_month) {
        return false;
    }

    $refreshed_at = trim((string)($meta['RefreshedAt'] ?? ''));
    if ($refreshed_at === '') {
        return false;
    }

    $ts = strtotime($refreshed_at);
    if ($ts === false) {
        return false;
    }

    if ($source_fingerprint !== '' && ($meta['SourceFingerprint'] ?? '') !== '') {
        if (!hash_equals((string)$meta['SourceFingerprint'], $source_fingerprint)) {
            return false;
        }
    }

    return (time() - $ts) <= _LV_EMP_ROSTER_TTL_SECONDS;
}

function lv_employee_roster_refresh(PDO $conn, int $target_year, int $target_month, ?string $source_fingerprint = null): void {
    lv_employee_roster_ensure_schema($conn);

    $shadow = _LV_EMP_ROSTER_TABLE . '_shadow';
    $swap   = _LV_EMP_ROSTER_TABLE . '_old';
    $source_fingerprint = $source_fingerprint ?? lv_employee_roster_source_fingerprint($conn);

    $conn->exec('DROP TABLE IF EXISTS `' . $shadow . '`');
    $conn->exec('DROP TABLE IF EXISTS `' . $swap . '`');
    $conn->exec('CREATE TABLE `' . $shadow . '` LIKE `' . _LV_EMP_ROSTER_TABLE . '`');

    $join = lv_emp_join_sql($target_year, $target_month);
    $sql = 'INSERT INTO `' . $shadow . '`
                (PIID, ID_NUM, Surname, Firstname, MiddleName, Department, Position,
                 IsCasual, End_Date, PayYear, Status, FirstGovServiceDate, SourceFingerprint,
                 SnapshotYear, SnapshotMonth, RefreshedAt)
            SELECT CAST(pi.PIID AS CHAR(20)) AS PIID,
                   MIN(pi.ID_NUM) AS ID_NUM,
                   MIN(pi.SurName) AS Surname,
                   MIN(pi.FirstName) AS Firstname,
                   MIN(pi.MiddleName) AS MiddleName,
                   ' . _LV_EMP_DEPT_EXPR . ' AS Department,
                   ' . _LV_EMP_POS_EXPR . ' AS Position,
                   MAX(emp_src.IsCasual) AS IsCasual,
                   ' . _LV_EMP_END_DATE_EXPR . ' AS End_Date,
                   ' . _LV_EMP_PAYYEAR_EXPR . ' AS PayYear,
                   ' . _LV_EMP_STATUS_EXPR . ' AS Status,
                   (SELECT MIN(COALESCE(sr.Inc_Date_from, sr.Date))
                    FROM tbl_service_record sr
                    WHERE sr.PIID = pi.PIID
                      AND COALESCE(sr.Inc_Date_from, sr.Date) IS NOT NULL) AS FirstGovServiceDate,
                   :source_fingerprint AS SourceFingerprint,
                   :snapshot_year AS SnapshotYear,
                   :snapshot_month AS SnapshotMonth,
                   NOW() AS RefreshedAt
            FROM ' . $join['sql'] . '
            WHERE ' . _LV_EMP_BASE_WHERE . '
            GROUP BY pi.PIID';
    $stmt = $conn->prepare($sql);
    $stmt->execute($join['params'] + [
        ':source_fingerprint' => $source_fingerprint,
        ':snapshot_year' => $target_year,
        ':snapshot_month' => $target_month,
    ]);

    $conn->exec(
        'RENAME TABLE `' . _LV_EMP_ROSTER_TABLE . '` TO `' . $swap . '`, `' .
        $shadow . '` TO `' . _LV_EMP_ROSTER_TABLE . '`'
    );
    $conn->exec('DROP TABLE IF EXISTS `' . $swap . '`');
}

function lv_ensure_employee_roster(PDO $conn, ?int $target_year = null, ?int $target_month = null): void {
    $target_year = $target_year ?: (int)date('Y');
    $target_month = $target_month ?: (int)date('n');

    $meta = lv_employee_roster_meta($conn);
    $source_fingerprint = lv_employee_roster_source_fingerprint($conn);
    if (lv_employee_roster_is_fresh($meta, $target_year, $target_month, $source_fingerprint)) {
        return;
    }

    $lock_stmt = $conn->prepare('SELECT GET_LOCK(:lock_name, 30)');
    $lock_stmt->execute([':lock_name' => _LV_EMP_ROSTER_LOCK]);
    $locked = (int)$lock_stmt->fetchColumn() === 1;
    if (!$locked) {
        return;
    }

    try {
        $meta = lv_employee_roster_meta($conn);
        if (!lv_employee_roster_is_fresh($meta, $target_year, $target_month, $source_fingerprint)) {
            lv_employee_roster_refresh($conn, $target_year, $target_month, $source_fingerprint);
        }
    } finally {
        $unlock_stmt = $conn->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $unlock_stmt->execute([':lock_name' => _LV_EMP_ROSTER_LOCK]);
    }
}

function lv_force_employee_roster_refresh(PDO $conn, ?int $target_year = null, ?int $target_month = null): void {
    $target_year = $target_year ?: (int)date('Y');
    $target_month = $target_month ?: (int)date('n');
    $source_fingerprint = lv_employee_roster_source_fingerprint($conn);

    $lock_stmt = $conn->prepare('SELECT GET_LOCK(:lock_name, 30)');
    $lock_stmt->execute([':lock_name' => _LV_EMP_ROSTER_LOCK]);
    $locked = (int)$lock_stmt->fetchColumn() === 1;
    if (!$locked) {
        return;
    }

    try {
        lv_employee_roster_refresh($conn, $target_year, $target_month, $source_fingerprint);
    } finally {
        $unlock_stmt = $conn->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $unlock_stmt->execute([':lock_name' => _LV_EMP_ROSTER_LOCK]);
    }
}

function lv_payroll_source_sql(int $target_year, int $cutoff_end_num): array {
    $dept_match_join = lv_regular_department_match_join_sql('tpc.Name', 'tp_match');
    $regular_department = lv_department_canonical_name_sql('tp.Name');
    $casual_department = lv_department_canonical_name_sql('COALESCE(tp_exact.Name, tp_match.Name, tpc.Name)');
    $sql = "(SELECT p.PIID, $regular_department AS Department, p.Position, p.End_Date, p.End_num, p.Date_Updated, p.year AS PayYear, p.TID,
                    0 AS IsCasual
             FROM tbl_syl_payroll_parent p
             INNER JOIN (
                 SELECT TID, MAX(year) AS LatestYear
                 FROM tbl_syl_payroll_parent
                 WHERE isDeleted = 0
                   AND Quencina = 'whole'
                   AND year <= :target_year_regular_latest
                 GROUP BY TID
             ) pr_latest ON pr_latest.TID = p.TID AND pr_latest.LatestYear = p.year
             INNER JOIN tbl_template_payroll tp ON p.TID = tp.TID AND tp.isDeleted = 0
             WHERE p.isDeleted = 0
               AND p.Quencina = 'whole'

             UNION

             SELECT pc.PIID, $casual_department AS Department, pc.Position, pc.End_Date, pc.End_num, pc.Date_Updated, pc.year AS PayYear, pc.TID,
                    1 AS IsCasual
             FROM tbl_syl_payroll_parent_casual pc
             INNER JOIN (
                 SELECT TID, MAX(year) AS LatestYear
                 FROM tbl_syl_payroll_parent_casual
                 WHERE isDeleted = 0
                   AND Quencina = 'whole'
                   AND year <= :target_year_casual_latest
                 GROUP BY TID
             ) pc_latest ON pc_latest.TID = pc.TID AND pc_latest.LatestYear = pc.year
             INNER JOIN tbl_template_payroll_casual tpc ON pc.TID = tpc.TID AND tpc.isDeleted = 0
             LEFT JOIN tbl_template_payroll tp_exact ON tp_exact.Name = tpc.Name AND tp_exact.isDeleted = 0
             $dept_match_join
             WHERE pc.isDeleted = 0
               AND pc.Quencina = 'whole'
            ) emp_src";

    return [
        'sql' => $sql,
        'params' => [
            ':target_year_regular_latest' => $target_year,
            ':target_year_casual_latest' => $target_year,
        ],
    ];
}

function lv_emp_join_sql(int $target_year, int $cutoff_end_num): array {
    $src = lv_payroll_source_sql($target_year, $cutoff_end_num);
    return [
        'sql' => $src['sql'] . ' INNER JOIN tblpersonalinformation pi ON pi.PIID = emp_src.PIID',
        'params' => $src['params'],
    ];
}

// ── Employee queries ──────────────────────────────────────────────────────────

/**
 * Batch-fetch the latest Status for a list of PIIDs from tbl_service_record.
 * Returns [PIID => Status] map. Single query replaces N correlated subqueries.
 */
function lv_batch_statuses(PDO $conn, array $piids): array {
    if (empty($piids)) return [];
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT PIID, Status
         FROM tbl_service_record
         WHERE PIID IN ($placeholders)
           AND Status IS NOT NULL AND Status <> ''
         ORDER BY PIID,
                  COALESCE(Inc_Date_To, Inc_Date_from, Date) DESC,
                  Inc_Date_from DESC"
    );
    $stmt->execute(array_values($piids));
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!array_key_exists($row['PIID'], $result)) {
            $result[$row['PIID']] = $row['Status'];
        }
    }
    return $result;
}

/**
 * Search employees eligible for leave credits from the active DTR template roster.
 *
 * Returns ['rows'=>[], 'total'=>int, 'page'=>int, 'page_size'=>int, 'total_pages'=>int].
 *
 * - dept uses exact match (value always comes from dropdown).
 * - surname/firstname/piid use prefix match (faster index scan than %like%).
 * - Status is fetched in one batch query after the main result set (no N+1).
 */
function lv_search_employees(
    PDO    $conn,
    string $surname   = '',
    string $firstname = '',
    string $dept      = '',
    string $piid      = '',
    int    $page      = 1,
    int    $page_size = 50
): array {
    lv_ensure_employee_roster($conn);
    $where  = ['1'];
    $params = [];

    if ($surname !== '') {
        $where[] = 'Surname LIKE :surname';
        $params[':surname'] = "$surname%";
    }
    if ($firstname !== '') {
        $where[] = 'Firstname LIKE :firstname';
        $params[':firstname'] = "$firstname%";
    }
    if ($dept !== '') {
        $where[] = 'Department = :dept';
        $params[':dept'] = $dept;
    }
    if ($piid !== '') {
        $where[] = 'PIID LIKE :piid';
        $params[':piid'] = "$piid%";
    }

    $where_sql = implode(' AND ', $where);

    $count_stmt = $conn->prepare(
        'SELECT COUNT(*) FROM ' . _LV_EMP_ROSTER_TABLE . ' WHERE ' . $where_sql
    );
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $page      = max(1, $page);
    $page_size = max(1, $page_size);
    $total_pages = $total > 0 ? (int)ceil($total / $page_size) : 1;
    $offset    = ($page - 1) * $page_size;

    // Main data query — Status excluded; fetched separately below
    $sql = 'SELECT PIID, ID_NUM, Surname, Firstname, MiddleName,
                   Department, Position, IsCasual, End_Date, PayYear, Status
            FROM ' . _LV_EMP_ROSTER_TABLE . '
            WHERE ' . $where_sql . '
            ORDER BY Department, Surname, Firstname
            LIMIT ' . $page_size . ' OFFSET ' . $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Single batch query for all statuses — replaces one correlated subquery per row
    foreach ($rows as &$row) {
        $row['employee_ref'] = lv_employee_ref((string)($row['PIID'] ?? ''));
        $row['Status'] = lv_normalize_employee_status(
            $row['Status'] ?? null,
            !empty($row['IsCasual'])
        );
    }
    unset($row);

    return [
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'page_size'   => $page_size,
        'total_pages' => $total_pages,
    ];
}

/**
 * Get a single employee row by PIID.
 */
function lv_get_employee(PDO $conn, string $piid): ?array {
    lv_ensure_employee_roster($conn);
    $stmt = $conn->prepare(
        'SELECT PIID, ID_NUM, Surname, Firstname, MiddleName, Department, Position,
                IsCasual, End_Date, PayYear, FirstGovServiceDate, Status
         FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE PIID = :piid
         LIMIT 1'
    );
    $stmt->execute([':piid' => $piid]);
    $row = $stmt->fetch() ?: null;
    if ($row) {
        $row['Status'] = lv_normalize_employee_status($row['Status'] ?? null, !empty($row['IsCasual']));
    }
    return $row;
}

/**
 * Get all distinct departments from payroll template tables.
 *
 * Regular template names are the canonical display values. Casual template
 * names are consolidated into matching regular template names, so the
 * dropdown only shows the regular department labels.
 */
function lv_get_departments(PDO $conn): array {
    $dept_match_join = lv_regular_department_match_join_sql('tpc.Name', 'tp_match');
    $regular_department = lv_department_canonical_name_sql('tp.Name');
    $casual_department = lv_department_canonical_name_sql('COALESCE(tp_exact.Name, tp_match.Name, tpc.Name)');
    $stmt = $conn->query(
        "SELECT DISTINCT Department
         FROM (
             SELECT $regular_department AS Department
             FROM tbl_template_payroll tp
             WHERE tp.isDeleted = 0

             UNION

             SELECT $casual_department AS Department
             FROM tbl_template_payroll_casual tpc
             LEFT JOIN tbl_template_payroll tp_exact ON tp_exact.Name = tpc.Name AND tp_exact.isDeleted = 0
             $dept_match_join
             WHERE tpc.isDeleted = 0
         ) dept_src
         WHERE Department IS NOT NULL AND Department <> ''
         ORDER BY Department"
    );
    return array_column($stmt->fetchAll(), 'Department');
}

// ── Leave ledger queries ──────────────────────────────────────────────────────

/**
 * Get all leave records for an employee in a given year, ordered chronologically.
 */
function lv_get_records(PDO $conn, string $piid, int $year): array {
    $stmt = $conn->prepare(
        'SELECT LID, PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
                VacEarn, VacWP, VacBal, VacWOP,
                SickEarn, SickWP, SickBal, SickWOP,
                Particulars, DateAction, DateProcessed, RecordedBy, Remarks,
                no_avail_VL, no_avail_SL, no_avail_mone_VL, no_avail_mone_SL,
                no_avail_SP, no_avail_P, no_avail_mone
         FROM tbl_syl_leave_form
         WHERE PIID = :piid
           AND Date_of_Filing BETWEEN :date_from AND :date_to
           AND isDeleted = 0
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC'
    );
    $stmt->execute([
        ':piid' => $piid,
        ':date_from' => sprintf('%04d-01-01', $year),
        ':date_to' => sprintf('%04d-12-31', $year),
    ]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return [];
    }

    $overrideMeta = lv_get_dtr_override_meta_for_lids(
        $conn,
        array_map(static fn(array $row): int => (int)($row['LID'] ?? 0), $rows)
    );
    foreach ($rows as &$row) {
        $lid = (int)($row['LID'] ?? 0);
        $meta = $overrideMeta[$lid] ?? ['DTRHalfDay' => 0, 'DTRHalfDayPeriod' => ''];
        $row['DTRHalfDay'] = (int)($meta['DTRHalfDay'] ?? 0);
        $row['DTRHalfDayPeriod'] = (string)($meta['DTRHalfDayPeriod'] ?? '');
    }
    unset($row);

    return $rows;
}

function lv_get_records_for_recalculate(PDO $conn, string $piid, int $year): array {
    $stmt = $conn->prepare(
        'SELECT LID,
                VacEarn, VacWP, VacWOP,
                SickEarn, SickWP, SickWOP,
                no_avail_mone_VL, no_avail_mone_SL
         FROM tbl_syl_leave_form
         WHERE PIID = :piid
           AND Date_of_Filing BETWEEN :date_from AND :date_to
           AND isDeleted = 0
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC'
    );
    $stmt->execute([
        ':piid' => $piid,
        ':date_from' => sprintf('%04d-01-01', $year),
        ':date_to' => sprintf('%04d-12-31', $year),
    ]);
    return $stmt->fetchAll();
}

function lv_update_latest_special_leave_balance(PDO $conn, string $piid, int $year, float $value): void {
    $records = lv_get_records_for_recalculate($conn, $piid, $year);
    if (empty($records)) {
        return;
    }

    $last = $records[count($records) - 1];
    $lid = (int)($last['LID'] ?? 0);
    if ($lid <= 0) {
        return;
    }

    $stmt = $conn->prepare(
        'UPDATE tbl_syl_leave_form
         SET no_avail_SL = :value
         WHERE LID = :lid
           AND isDeleted = 0'
    );
    $stmt->execute([
        ':value' => $value,
        ':lid' => $lid,
    ]);
}

function lv_get_dtr_suggested_undertime(PDO $conn, string $piid, string $from, string $to = ''): array {
    $piid = trim($piid);
    $from = trim($from);
    $to = trim($to) !== '' ? trim($to) : $from;
    if ($piid === '' || $from === '') {
        return ['minutes' => 0, 'hours' => 0, 'display_minutes' => 0, 'from' => $from, 'to' => $to];
    }

    $start = strtotime($from);
    $end = strtotime($to);
    if ($start === false || $end === false) {
        return ['minutes' => 0, 'hours' => 0, 'display_minutes' => 0, 'from' => $from, 'to' => $to];
    }
    if ($end < $start) {
        [$from, $to] = [$to, $from];
    }

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(TIME_TO_SEC(Undertime)), 0) AS undertime_seconds
         FROM tbldtr
         WHERE PIID = :piid
           AND DTR_Date BETWEEN :date_from AND :date_to
           AND Undertime IS NOT NULL
           AND Undertime <> '00:00:00'"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':date_from' => $from,
        ':date_to' => $to,
    ]);

    $seconds = (int)($stmt->fetchColumn() ?: 0);
    $totalMinutes = (int)floor($seconds / 60);

    return [
        'minutes' => $totalMinutes,
        'hours' => (int)floor($totalMinutes / 60),
        'display_minutes' => $totalMinutes % 60,
        'from' => $from,
        'to' => $to,
    ];
}

/**
 * Get a single leave record by LID.
 */
function lv_get_record(PDO $conn, int $lid): ?array {
    $stmt = $conn->prepare(
        'SELECT * FROM tbl_syl_leave_form WHERE LID = :lid AND isDeleted = 0 LIMIT 1'
    );
    $stmt->execute([':lid' => $lid]);
    return $stmt->fetch() ?: null;
}

function lv_get_dtr_override_meta_for_lids(PDO $conn, array $lids): array {
    $lids = array_values(array_unique(array_filter(array_map('intval', $lids), static fn(int $lid): bool => $lid > 0)));
    if (!$lids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($lids), '?'));
    $stmt = $conn->prepare(
        "SELECT o.LID, MIN(d.time_start) AS min_start, MAX(d.time_end) AS max_end
           FROM tbldtr_override o
           JOIN tbldtr_override_details d ON d.OID = o.OID
          WHERE o.LID IN ($placeholders)
          GROUP BY o.LID"
    );
    $stmt->execute($lids);

    $meta = [];
    foreach ($stmt->fetchAll() as $row) {
        $period = '';
        $minStart = trim((string)($row['min_start'] ?? ''));
        $maxEnd = trim((string)($row['max_end'] ?? ''));
        if ($minStart === '08:00' && $maxEnd === '12:00') {
            $period = 'AM';
        } elseif ($minStart === '12:45' && $maxEnd === '17:00') {
            $period = 'PM';
        }

        $lid = (int)($row['LID'] ?? 0);
        if ($lid > 0) {
            $meta[$lid] = [
                'DTRHalfDay' => $period !== '' ? 1 : 0,
                'DTRHalfDayPeriod' => $period,
            ];
        }
    }

    return $meta;
}

function lv_main_type(string $type): string {
    $parts = explode('|', $type, 2);
    return trim((string)($parts[0] ?? $type));
}

function lv_is_wellness_type(string $type): bool {
    return strtolower(lv_main_type($type)) === 'wellness leave';
}

function lv_is_compensatory_type(string $type): bool {
    return strtolower(lv_main_type($type)) === 'compensatory time-off (cto)';
}

function lv_type_includes_weekends(string $type): bool {
    $normalized = strtolower(trim(lv_main_type($type)));
    return in_array($normalized, ['maternity leave', 'study leave'], true);
}

function lv_ph_easter_date(int $year): DateTimeImmutable {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

function lv_ph_last_monday(int $year, int $month): string {
    $date = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $date = $date->modify('last day of this month');
    while ($date->format('N') !== '1') {
        $date = $date->modify('-1 day');
    }
    return $date->format('Y-m-d');
}

function lv_ph_holiday_dates(int $year): array {
    $easter = lv_ph_easter_date($year);
    $dates = [
        sprintf('%04d-01-01', $year),
        sprintf('%04d-04-09', $year),
        sprintf('%04d-05-01', $year),
        sprintf('%04d-06-12', $year),
        sprintf('%04d-08-21', $year),
        sprintf('%04d-11-01', $year),
        sprintf('%04d-11-30', $year),
        sprintf('%04d-12-08', $year),
        sprintf('%04d-12-25', $year),
        sprintf('%04d-12-30', $year),
        $easter->modify('-3 days')->format('Y-m-d'),
        $easter->modify('-2 days')->format('Y-m-d'),
        lv_ph_last_monday($year, 8),
    ];
    if ($year === 2026) {
        $dates = array_merge($dates, [
            '2026-02-17',
            '2026-03-20',
            '2026-04-04',
            '2026-10-31',
            '2026-12-24',
            '2026-12-31',
        ]);
    }
    return array_fill_keys($dates, true);
}

function lv_ph_is_working_day_timestamp(int $ts): bool {
    $day = (int)date('w', $ts);
    if ($day === 0 || $day === 6) return false;
    $holidays = lv_ph_holiday_dates((int)date('Y', $ts));
    return !isset($holidays[date('Y-m-d', $ts)]);
}

function lv_count_leave_days_between(string $from, string $to, bool $include_weekends = false): float {
    if ($from === '' || $to === '') return 0.0;

    $start = strtotime($from . ' 00:00:00');
    $end = strtotime($to . ' 00:00:00');
    if ($start === false || $end === false || $end < $start) return 0.0;

    $count = 0.0;
    for ($ts = $start; $ts <= $end; $ts = strtotime('+1 day', $ts)) {
        if ($include_weekends || lv_ph_is_working_day_timestamp($ts)) {
            $count += 1.0;
        }
    }

    return $count;
}

function lv_record_leave_days(array $record): float {
    $type = lv_main_type((string)($record['Type_of_Records'] ?? ''));
    return lv_count_leave_days_between(
        (string)($record['Period_From'] ?? ''),
        (string)($record['Period_To'] ?? ''),
        lv_type_includes_weekends($type)
    );
}

function lv_wellness_days_for_record(array $record): float {
    if (!lv_is_wellness_type((string)($record['Type_of_Records'] ?? ''))) {
        return 0.0;
    }

    return lv_record_leave_days($record);
}

function lv_wellness_days_used(PDO $conn, string $piid, int $year, int $exclude_lid = 0): float {
    $used = 0.0;
    foreach (lv_get_records($conn, $piid, $year) as $row) {
        if ($exclude_lid > 0 && (int)($row['LID'] ?? 0) === $exclude_lid) {
            continue;
        }
        $used += lv_wellness_days_for_record($row);
    }
    return round($used, 4);
}

/**
 * Insert a new leave record. Returns the new LID.
 */
function lv_insert_record(PDO $conn, array $d, string $recorded_by): int {
    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_form
            (PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
             VacEarn, VacWP, VacBal, VacWOP,
            SickEarn, SickWP, SickBal, SickWOP,
            Particulars, DateAction, DateProcessed, RecordedBy,
            no_avail_VL, no_avail_SL, no_avail_mone_VL, no_avail_mone_SL,
            no_avail_SP, no_avail_P, no_avail_mone, Remarks, isDeleted)
         VALUES
            (:piid, :type, :filing, :pfrom, :pto,
             :ve, :vwp, :vbal, :vwop,
             :se, :swp, :sbal, :swop,
             :particulars, :date_action, :date_processed, :recorded_by,
             :nvl, :nsl, :nmvl, :nmsl,
             :nsp, :np, :nmone, :remarks, 0)'
    );
    $stmt->execute([
        ':piid'       => $d['PIID'],
        ':type'       => $d['Type_of_Records'],
        ':filing'     => $d['Date_of_Filing'],
        ':pfrom'      => $d['Period_From'],
        ':pto'        => $d['Period_To'],
        ':ve'         => $d['VacEarn']  ?? 0,
        ':vwp'        => $d['VacWP']   ?? 0,
        ':vbal'       => $d['VacBal']  ?? 0,
        ':vwop'       => $d['VacWOP']  ?? 0,
        ':se'         => $d['SickEarn'] ?? 0,
        ':swp'        => $d['SickWP']  ?? 0,
        ':sbal'       => $d['SickBal'] ?? 0,
        ':swop'       => $d['SickWOP'] ?? 0,
        ':particulars'=> $d['Particulars'] ?? '',
        ':date_action'=> $d['DateAction'] ?? '',
        ':date_processed'=> !empty($d['DateProcessed']) ? $d['DateProcessed'] : date('Y-m-d H:i:s'),
        ':recorded_by'=> !empty($d['RecordedBy']) ? $d['RecordedBy'] : $recorded_by,
        ':nvl'        => $d['no_avail_VL']      ?? 0,
        ':nsl'        => $d['no_avail_SL']      ?? 0,
        ':nmvl'       => $d['no_avail_mone_VL'] ?? 0,
        ':nmsl'       => $d['no_avail_mone_SL'] ?? 0,
        ':nsp'        => $d['no_avail_SP']      ?? 0,
        ':np'         => $d['no_avail_P']       ?? 0,
        ':nmone'      => $d['no_avail_mone']    ?? 0,
        ':remarks'    => $d['Remarks']          ?? '',
    ]);
    return (int)$conn->lastInsertId();
}

/**
 * Update an existing leave record by LID.
 */
function lv_update_record(PDO $conn, int $lid, array $d, string $recorded_by): void {
    $stmt = $conn->prepare(
        'UPDATE tbl_syl_leave_form SET
            Type_of_Records = :type,
            Date_of_Filing  = :filing,
            Period_From     = :pfrom,
            Period_To       = :pto,
            VacEarn  = :ve,  VacWP  = :vwp,  VacBal  = :vbal,  VacWOP  = :vwop,
            SickEarn = :se,  SickWP = :swp,  SickBal = :sbal,  SickWOP = :swop,
            Particulars     = :particulars,
            DateAction      = :date_action,
            DateProcessed   = :date_processed,
            RecordedBy      = :recorded_by,
            no_avail_VL     = :nvl,
            no_avail_SL     = :nsl,
            no_avail_mone_VL= :nmvl,
            no_avail_mone_SL= :nmsl,
            no_avail_SP     = :nsp,
            no_avail_P      = :np,
            no_avail_mone   = :nmone,
            Remarks         = :remarks
         WHERE LID = :lid AND isDeleted = 0'
    );
    $stmt->execute([
        ':lid'        => $lid,
        ':type'       => $d['Type_of_Records'],
        ':filing'     => $d['Date_of_Filing'],
        ':pfrom'      => $d['Period_From'],
        ':pto'        => $d['Period_To'],
        ':ve'         => $d['VacEarn']  ?? 0,
        ':vwp'        => $d['VacWP']   ?? 0,
        ':vbal'       => $d['VacBal']  ?? 0,
        ':vwop'       => $d['VacWOP']  ?? 0,
        ':se'         => $d['SickEarn'] ?? 0,
        ':swp'        => $d['SickWP']  ?? 0,
        ':sbal'       => $d['SickBal'] ?? 0,
        ':swop'       => $d['SickWOP'] ?? 0,
        ':particulars'=> $d['Particulars'] ?? '',
        ':date_action'=> $d['DateAction'] ?? '',
        ':date_processed'=> !empty($d['DateProcessed']) ? $d['DateProcessed'] : date('Y-m-d H:i:s'),
        ':recorded_by'=> !empty($d['RecordedBy']) ? $d['RecordedBy'] : $recorded_by,
        ':nvl'        => $d['no_avail_VL']      ?? 0,
        ':nsl'        => $d['no_avail_SL']      ?? 0,
        ':nmvl'       => $d['no_avail_mone_VL'] ?? 0,
        ':nmsl'       => $d['no_avail_mone_SL'] ?? 0,
        ':nsp'        => $d['no_avail_SP']      ?? 0,
        ':np'         => $d['no_avail_P']       ?? 0,
        ':nmone'      => $d['no_avail_mone']    ?? 0,
        ':remarks'    => $d['Remarks']          ?? '',
    ]);
}

/**
 * Soft-delete a leave record.
 */
function lv_delete_record(PDO $conn, int $lid): void {
    $stmt = $conn->prepare('UPDATE tbl_syl_leave_form SET isDeleted = 1 WHERE LID = :lid');
    $stmt->execute([':lid' => $lid]);
}

// ── DTR override helpers ──────────────────────────────────────────────────────

/**
 * Returns true if this leave type should generate DTR override rows.
 * Mirrors VB frmChoiceFormLeave.add_to_override() eligibility condition.
 */
function lv_dtr_eligible(string $type): bool {
    $main = strtolower(trim(lv_main_type($type)));
    return in_array($main, [
        'vacation leave', 'sick leave', 'paternity leave', 'maternity leave',
        'terminal leave', 'solo parent leave', 'compensatory time-off (cto)',
        'rehabilitation leave', 'rehabilitation privilege',
        'vacation leave w/o pay', 'sick leave w/o pay',
        'mandatory/forced leave', 'others, (specify)', 'others (specify)',
        'special leave benefits for women', 'wellness leave',
    ], true);
}

function lv_dtr_audit_user(string $value): string {
    $value = trim($value);
    return $value !== '' ? substr($value, 0, 20) : 'HRMIS';
}

/**
 * Maps a leave type (may include "|subtype" suffix) to the Name stored in tbldtr_override.
 * Mirrors VB frmChoiceFormLeave.add_to_override() Name-mapping logic.
 */
function lv_dtr_name_for_type(string $type): string {
    $parts = explode('|', $type, 2);
    $main  = trim($parts[0] ?? $type);
    $sub   = strtolower(trim($parts[1] ?? ''));
    $normalizedMain = strtolower($main);
    $canonical = [
        'vacation leave' => 'Vacation Leave',
        'sick leave' => 'Sick Leave',
        'paternity leave' => 'Paternity Leave',
        'maternity leave' => 'Maternity Leave',
        'terminal leave' => 'Terminal Leave',
        'solo parent leave' => 'Solo Parent Leave',
        'compensatory time-off (cto)' => 'Compensatory Time-Off (CTO)',
        'rehabilitation leave' => 'Rehabilitation Leave',
        'rehabilitation privilege' => 'Rehabilitation Privilege',
        'vacation leave w/o pay' => 'Vacation Leave w/o pay',
        'sick leave w/o pay' => 'Sick Leave w/o pay',
        'mandatory/forced leave' => 'Mandatory/Forced Leave',
        'special leave benefits for women' => 'Special Leave Benefits for Women',
        'wellness leave' => 'Wellness Leave',
    ];
    if (isset($canonical[$normalizedMain])) {
        return $canonical[$normalizedMain];
    }
    if ($sub !== '' && $sub !== '-n/a-' && $normalizedMain === 'others, (specify)') {
        $map = [
            'personal milestone'    => 'SPECIAL LEAVE/PM',
            'filial obligation'     => 'SPECIAL LEAVE/FL',
            'domestic emergency'    => 'SPECIAL LEAVE/DE',
            'personal transaction'  => 'SPECIAL LEAVE/PT',
            'calamity, accident hospitalization leave' => 'Calamity,Accident',
            'mourning leave'        => 'SPECIAL LEAVE/ML',
            'magna carta for women' => 'SPECIAL LEAVE/MC',
        ];
        return $map[$sub] ?? ('SPECIAL LEAVE/' . strtoupper(trim($parts[1] ?? '')));
    }
    return $main;
}

/**
 * Given a cancelled or restoration record, return the LID of its pair or null.
 * Matches the pair using stable fields (PIID + period range) instead of parsing
 * human-readable Particulars text.
 */
function lv_find_cancel_companion(PDO $conn, array $rec): ?int {
    $pars  = strtoupper(trim((string)($rec['Particulars'] ?? '')));
    $piid  = (string)($rec['PIID'] ?? '');
    $lid   = (int)($rec['LID'] ?? 0);
    $pfrom = trim((string)($rec['Period_From'] ?? ''));
    $pto   = trim((string)($rec['Period_To'] ?? ''));

    if ($piid === '' || $lid <= 0 || $pfrom === '') {
        return null;
    }

    $pto = $pto !== '' ? $pto : $pfrom;

    if (str_starts_with($pars, 'CANCELLED-')) {
        $stmt = $conn->prepare(
            "SELECT LID FROM tbl_syl_leave_form
             WHERE PIID = :piid
               AND isDeleted = 0
               AND LID <> :lid
               AND DATE(Period_From) = :pfrom
               AND DATE(COALESCE(NULLIF(Period_To, ''), Period_From)) = :pto
               AND UPPER(Particulars) LIKE 'RESTORATION%'
             ORDER BY LID DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':piid'  => $piid,
            ':lid'   => $lid,
            ':pfrom' => $pfrom,
            ':pto'   => $pto,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['LID'] : null;
    }

    if (str_starts_with($pars, 'RESTORATION')) {
        $stmt = $conn->prepare(
            "SELECT LID FROM tbl_syl_leave_form
             WHERE PIID = :piid
               AND isDeleted = 0
               AND LID <> :lid
               AND DATE(Period_From) = :pfrom
               AND DATE(COALESCE(NULLIF(Period_To, ''), Period_From)) = :pto
               AND UPPER(Particulars) LIKE 'CANCELLED-%'
             ORDER BY LID DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':piid'  => $piid,
            ':lid'   => $lid,
            ':pfrom' => $pfrom,
            ':pto'   => $pto,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['LID'] : null;
    }

    return null;
}

/**
 * Delete DTR override rows by OID.
 * Removes details and person rows first (no FK cascade assumed), then the header.
 */
function lv_delete_dtr_override_oids(PDO $conn, array $oids): void {
    $oids = array_values(array_unique(array_filter(array_map('intval', $oids), static fn($oid) => $oid > 0)));
    if ($oids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($oids), '?'));
    $conn->prepare("DELETE FROM tbldtr_override_details WHERE OID IN ($placeholders)")->execute($oids);
    $conn->prepare("DELETE FROM tbldtr_override_person  WHERE OID IN ($placeholders)")->execute($oids);
    $conn->prepare("DELETE FROM tbldtr_override         WHERE OID IN ($placeholders)")->execute($oids);
}

/**
 * Delete all DTR override rows tied to a leave record LID.
 */
function lv_delete_dtr_override(PDO $conn, int $lid): void {
    $stmt = $conn->prepare('SELECT OID FROM tbldtr_override WHERE LID = :lid');
    $stmt->execute([':lid' => $lid]);
    $oids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    lv_delete_dtr_override_oids($conn, $oids);
}

/**
 * Find existing override rows for the same employee and overlapping date span.
 * The legacy client reads overrides by PIID + DTR_Date, so any overlapping row
 * can still surface even if the header/LID differs.
 */
function lv_find_dtr_override_oids_for_period(
    PDO $conn,
    string $piid,
    string $period_from,
    string $period_to,
    int $exclude_lid = 0,
    int $exclude_oid = 0
): array {
    if ($piid === '' || $period_from === '' || $period_to === '') {
        return [];
    }

    $sql = "
        SELECT DISTINCT o.OID
          FROM tbldtr_override o
          JOIN tbldtr_override_person p
            ON p.OID = o.OID
         JOIN tbldtr_override_details d
            ON d.OID = o.OID
         WHERE p.PIID = :piid
           AND DATE(d.dtr_date) BETWEEN :period_from AND :period_to
           AND (:exclude_lid_flag <= 0 OR COALESCE(o.LID, 0) <> :exclude_lid_value)
           AND (:exclude_oid_flag <= 0 OR o.OID <> :exclude_oid_value)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':piid' => $piid,
        ':exclude_lid_flag' => $exclude_lid,
        ':exclude_lid_value' => $exclude_lid,
        ':exclude_oid_flag' => $exclude_oid,
        ':exclude_oid_value' => $exclude_oid,
        ':period_from' => $period_from,
        ':period_to' => $period_to,
    ]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Insert/replace DTR override rows for a leave record.
 *
 * Mirrors VB frmChoiceFormLeave.add_to_override():
 *   - One tbldtr_override header row  (Override_Type = "Memo")
 *   - One tbldtr_override_details row per calendar day (weekends included, 08:00–17:00)
 *   - One tbldtr_override_person row
 *
 * No-ops if the leave type is not DTR-eligible or dates are missing.
 */
function lv_upsert_dtr_override(
    PDO $conn, int $lid, string $type,
    string $period_from, string $period_to,
    string $particulars, string $piid, string $created_by,
    bool $is_half_day = false, string $half_day_period = ''
): void {
    $mainType = lv_main_type($type);
    $eligible = lv_dtr_eligible($type);
    if (!$eligible || $period_from === '' || $period_to === '') {
        lv_delete_dtr_override($conn, $lid);
        return;
    }

    lv_delete_dtr_override_oids(
        $conn,
        lv_find_dtr_override_oids_for_period($conn, $piid, $period_from, $period_to, $lid)
    );
    lv_delete_dtr_override($conn, $lid);

    $now = date('Y-m-d H:i:s');
    $overrideName = lv_dtr_name_for_type($type);
    $auditUser = lv_dtr_audit_user($created_by);
    $conn->prepare(
        'INSERT INTO tbldtr_override
            (Name, ODate, Override_Type, Remarks, LID, Created_By, Created_Date, Updated_By, Updated_Date)
         VALUES (:name, :odate, :otype, :remarks, :lid, :cby, :cdate, :uby, :udate)'
    )->execute([
        ':name'    => $overrideName,
        ':odate'   => date('Y-m-d'),
        ':otype'   => 'Memo',
        ':remarks' => $particulars,
        ':lid'     => $lid,
        ':cby'     => $auditUser,
        ':cdate'   => $now,
        ':uby'     => $auditUser,
        ':udate'   => $now,
    ]);
    $oid = (int)$conn->lastInsertId();

    $halfDayStart = '08:00';
    $halfDayEnd = '17:00';
    if ($is_half_day) {
        $period = strtoupper(trim($half_day_period));
        if ($period === 'AM') {
            $halfDayStart = '08:00';
            $halfDayEnd = '12:00';
        } elseif ($period === 'PM') {
            $halfDayStart = '12:45';
            $halfDayEnd = '17:00';
        }
    }

    $det   = $conn->prepare(
        'INSERT INTO tbldtr_override_details (OID, dtr_date, time_start, time_end)
         VALUES (:oid, :dt, :time_start, :time_end)'
    );
    $start = strtotime($period_from);
    $end   = strtotime($period_to);
    if ($start !== false && $end !== false && $end >= $start) {
        for ($ts = $start; $ts <= $end; $ts = strtotime('+1 day', $ts)) {
            $isFinalHalfDay = $is_half_day && date('Y-m-d', $ts) === date('Y-m-d', $end);
            $det->execute([
                ':oid' => $oid,
                ':dt' => date('Y-m-d', $ts),
                ':time_start' => $isFinalHalfDay ? $halfDayStart : '08:00',
                ':time_end' => $isFinalHalfDay ? $halfDayEnd : '17:00',
            ]);
        }
    }

    $conn->prepare('DELETE FROM tbldtr_override_person WHERE OID = :oid')->execute([':oid' => $oid]);
    $conn->prepare('INSERT INTO tbldtr_override_person (OID, piid) VALUES (:oid, :piid)')
         ->execute([':oid' => $oid, ':piid' => $piid]);

    // Final sweep: keep only the freshly saved override for this employee/date span.
    $duplicateOids = lv_find_dtr_override_oids_for_period($conn, $piid, $period_from, $period_to, 0, $oid);
    lv_delete_dtr_override_oids($conn, $duplicateOids);
}

/**
 * Cancel a leave record (transaction-wrapped).
 *
 * - Prefixes Particulars with "CANCELLED-" on the original row (VacWP/SickWP kept intact)
 * - Inserts a restoration row: VacEarn = original.VacWP, SickEarn = original.SickWP
 * - Deletes the DTR override for the original LID
 * - Calls lv_recalculate() for the affected year
 *
 * Returns the new restoration row's LID.
 * Throws RuntimeException on invalid state (already cancelled, not found).
 */
function lv_cancel_record(PDO $conn, int $lid, string $filing_date, string $actor): int {
    $rec = lv_get_record($conn, $lid);
    if (!$rec) throw new RuntimeException('Record not found');
    if (str_starts_with((string)($rec['Particulars'] ?? ''), 'CANCELLED-')) {
        throw new RuntimeException('Record is already cancelled');
    }

    if (!$filing_date) $filing_date = date('Y-m-d');
    $pfrom = (string)($rec['Period_From'] ?? '');
    $pto   = (string)($rec['Period_To']   ?? '');
    $type  = (string)($rec['Type_of_Records'] ?? '');
    $pars  = (string)($rec['Particulars'] ?? '');

    $restPars = 'RESTORATION of ' . lv_main_type($type) . ' DATED '
              . date('M j, Y', strtotime($pfrom ?: 'now'));
    if ($pto && $pto !== $pfrom) {
        $restPars .= ' - ' . date('M j, Y', strtotime($pto));
    }

    $conn->beginTransaction();
    try {
        $conn->prepare('UPDATE tbl_syl_leave_form SET Particulars = :p WHERE LID = :lid')
             ->execute([':p' => 'CANCELLED-' . $pars, ':lid' => $lid]);

        $restore = [
            'PIID'             => $rec['PIID'],
            'Type_of_Records'  => $type,
            'Date_of_Filing'   => $filing_date,
            'Period_From'      => $pfrom,
            'Period_To'        => $pto,
            'VacEarn'          => (float)($rec['VacWP']  ?? 0),
            'VacWP'            => 0,
            'VacBal'           => 0,
            'VacWOP'           => 0,
            'SickEarn'         => (float)($rec['SickWP'] ?? 0),
            'SickWP'           => 0,
            'SickBal'          => 0,
            'SickWOP'          => 0,
            'Particulars'      => $restPars,
            'DateAction'       => (string)($rec['DateAction'] ?? ''),
            'DateProcessed'    => $filing_date,
            'RecordedBy'       => $actor,
            'no_avail_VL'      => (float)($rec['no_avail_VL']      ?? 0),
            'no_avail_SL'      => (float)($rec['no_avail_SL']      ?? 0),
            'no_avail_mone_VL' => (float)($rec['no_avail_mone_VL'] ?? 0),
            'no_avail_mone_SL' => (float)($rec['no_avail_mone_SL'] ?? 0),
            'no_avail_SP'      => (float)($rec['no_avail_SP']      ?? 0),
            'no_avail_P'       => (float)($rec['no_avail_P']       ?? 0),
            'no_avail_mone'    => 0,
            'Remarks'          => (string)($rec['Remarks'] ?? ''),
        ];
        $new_lid = lv_insert_record($conn, $restore, $actor);
        lv_delete_dtr_override($conn, $lid);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    lv_recalculate($conn, (string)$rec['PIID'], (int)date('Y', strtotime($pfrom ?: date('Y-m-d'))));
    return $new_lid;
}

/**
 * Reschedule a leave record (transaction-wrapped).
 *
 * - Clones the row with new Period_From / Period_To (keeping all deduction amounts)
 * - Appends "-RESCHEDULED TO [mm/dd/yyyy - mm/dd/yyyy]" to original Particulars
 * - Zeros original VacWP and SickWP
 * - Deletes the original DTR override; creates a new DTR override for the cloned row
 * - Calls lv_recalculate() for both affected years
 *
 * Returns the new cloned row's LID.
 * Throws RuntimeException on invalid state.
 */
function lv_reschedule_record(
    PDO $conn, int $lid, string $new_from, string $new_to, string $filing_date, string $actor
): int {
    $rec = lv_get_record($conn, $lid);
    if (!$rec) throw new RuntimeException('Record not found');
    if (str_contains((string)($rec['Particulars'] ?? ''), '-RESCHEDULED TO ')) {
        throw new RuntimeException('Record has already been rescheduled');
    }
    if (!$new_from || !$new_to) throw new RuntimeException('New Period_From and Period_To are required');

    $from_ts = strtotime($new_from);
    $to_ts   = strtotime($new_to);
    if ($from_ts === false || $to_ts === false || $to_ts < $from_ts) {
        throw new RuntimeException('Invalid new date range');
    }

    if (!$filing_date) $filing_date = date('Y-m-d');
    $pars   = (string)($rec['Particulars'] ?? '');
    $conDay = date('m/d/Y', $from_ts) . ' - ' . date('m/d/Y', $to_ts);

    $conn->beginTransaction();
    try {
        $clone = [
            'PIID'             => $rec['PIID'],
            'Type_of_Records'  => $rec['Type_of_Records'],
            'Date_of_Filing'   => $filing_date,
            'Period_From'      => $new_from,
            'Period_To'        => $new_to,
            'VacEarn'          => (float)($rec['VacEarn']  ?? 0),
            'VacWP'            => (float)($rec['VacWP']    ?? 0),
            'VacBal'           => 0,
            'VacWOP'           => (float)($rec['VacWOP']   ?? 0),
            'SickEarn'         => (float)($rec['SickEarn'] ?? 0),
            'SickWP'           => (float)($rec['SickWP']   ?? 0),
            'SickBal'          => 0,
            'SickWOP'          => (float)($rec['SickWOP']  ?? 0),
            'Particulars'      => $pars,
            'DateAction'       => (string)($rec['DateAction'] ?? ''),
            'DateProcessed'    => $filing_date,
            'RecordedBy'       => $actor,
            'no_avail_VL'      => (float)($rec['no_avail_VL']      ?? 0),
            'no_avail_SL'      => (float)($rec['no_avail_SL']      ?? 0),
            'no_avail_mone_VL' => (float)($rec['no_avail_mone_VL'] ?? 0),
            'no_avail_mone_SL' => (float)($rec['no_avail_mone_SL'] ?? 0),
            'no_avail_SP'      => (float)($rec['no_avail_SP']      ?? 0),
            'no_avail_P'       => (float)($rec['no_avail_P']       ?? 0),
            'no_avail_mone'    => (float)($rec['no_avail_mone']    ?? 0),
            'Remarks'          => (string)($rec['Remarks'] ?? ''),
        ];
        $new_lid = lv_insert_record($conn, $clone, $actor);

        $conn->prepare(
            'UPDATE tbl_syl_leave_form SET Particulars = :p, VacWP = 0, SickWP = 0 WHERE LID = :lid'
        )->execute([':p' => $pars . '-RESCHEDULED TO ' . $conDay, ':lid' => $lid]);

        lv_delete_dtr_override($conn, $lid);
        lv_upsert_dtr_override(
            $conn, $new_lid, (string)$rec['Type_of_Records'],
            $new_from, $new_to, $pars, (string)$rec['PIID'], $actor
        );

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    $piid      = (string)$rec['PIID'];
    $orig_year = (int)date('Y', strtotime((string)($rec['Period_From'] ?? date('Y-m-d'))));
    $new_year  = (int)date('Y', $from_ts);
    lv_recalculate($conn, $piid, $orig_year);
    if ($new_year !== $orig_year) lv_recalculate($conn, $piid, $new_year);
    return $new_lid;
}

// ── Balance forwarded ─────────────────────────────────────────────────────────

/**
 * Get the forwarded balance row for an employee for a given year.
 * Returns null if not found.
 */
function lv_get_saved_balance(PDO $conn, string $piid, int $year): ?array {
    lv_ensure_available_balance_schema($conn);
    $stmt = $conn->prepare(
        'SELECT piid, cBackVaca, cBalSick, NoAvailVL, NoAvailSL,
                NoAvailSLManual, NoAvailSLManualSetAt, MonetaryVL, MonetarySL, year
         FROM tbl_syl_leave_available_balance
         WHERE piid = :piid AND year = :year
         LIMIT 1'
    );
    $stmt->execute([':piid' => $piid, ':year' => $year]);
    $row = $stmt->fetch() ?: null;
    if ($row) {
        $row['NoAvailVL'] = lv_normalize_mandatory_forced_leave($row['NoAvailVL'] ?? null);
    }
    return $row;
}

function lv_auto_special_leave_forward(PDO $conn, string $piid, int $year): float {
    return _LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE;
}

/**
 * Build an automatic forwarded balance from the previous year's closing row.
 * Annual leave counters such as Special Leave reset to their yearly defaults.
 * Saved balances still take precedence over this derived fallback.
 */
function lv_get_auto_forwarded_balance(PDO $conn, string $piid, int $year): ?array {
    if ($year <= 0) return null;
    $source_year = $year - 1;
    if ($source_year <= 0) return null;

    $prev_records = lv_get_records($conn, $piid, $source_year);
    if (!empty($prev_records)) {
        $last = $prev_records[count($prev_records) - 1];
        return [
            'piid'        => $piid,
            'year'        => $year,
            'cBackVaca'   => (float)($last['VacBal'] ?? 0),
            'cBalSick'    => (float)($last['SickBal'] ?? 0),
            'NoAvailVL'   => _LV_DEFAULT_MANDATORY_FORCED_LEAVE,
            'NoAvailSL'   => lv_auto_special_leave_forward($conn, $piid, $year),
            'NoAvailSLManual' => 0,
            'MonetaryVL'  => (float)($last['no_avail_mone_VL'] ?? 0),
            'MonetarySL'  => (float)($last['no_avail_mone_SL'] ?? 0),
            'sourceYear'  => $source_year,
            'isAutoDerived' => 1,
        ];
    }

    $prev_saved = lv_get_saved_balance($conn, $piid, $source_year);
    if ($prev_saved) {
        return [
            'piid'        => $piid,
            'year'        => $year,
            'cBackVaca'   => (float)($prev_saved['cBackVaca'] ?? 0),
            'cBalSick'    => (float)($prev_saved['cBalSick'] ?? 0),
            'NoAvailVL'   => _LV_DEFAULT_MANDATORY_FORCED_LEAVE,
            'NoAvailSL'   => lv_auto_special_leave_forward($conn, $piid, $year),
            'NoAvailSLManual' => 0,
            'MonetaryVL'  => (float)($prev_saved['MonetaryVL'] ?? 0),
            'MonetarySL'  => (float)($prev_saved['MonetarySL'] ?? 0),
            'sourceYear'  => $source_year,
            'isAutoDerived' => 1,
        ];
    }

    return null;
}

function lv_get_balance(PDO $conn, string $piid, int $year): ?array {
    $saved = lv_get_saved_balance($conn, $piid, $year);
    if ($saved) {
        $saved['sourceYear'] = $year - 1;
        $saved['isAutoDerived'] = 0;
        if (!lv_special_leave_manual_override($saved)) {
            $saved['NoAvailSL'] = lv_auto_special_leave_forward($conn, $piid, $year);
        }
        return $saved;
    }

    return lv_get_auto_forwarded_balance($conn, $piid, $year);
}

/**
 * Upsert the forwarded balance row for an employee/year.
 */
function lv_upsert_balance(PDO $conn, string $piid, int $year, array $d): void {
    lv_ensure_available_balance_schema($conn);
    $params = [
        ':piid' => $piid,
        ':year' => $year,
        ':vac'  => $d['cBackVaca']  ?? 0,
        ':sick' => $d['cBalSick']   ?? 0,
        ':nvl'  => $d['NoAvailVL']  ?? 0,
        ':nsl'  => $d['NoAvailSL']  ?? 0,
        ':nsl_manual' => !empty($d['NoAvailSLManual']) ? 1 : 0,
        ':nsl_manual_at' => !empty($d['NoAvailSLManual']) ? ($d['NoAvailSLManualSetAt'] ?? date('Y-m-d H:i:s')) : null,
        ':mvl'  => $d['MonetaryVL'] ?? 0,
        ':msl'  => $d['MonetarySL'] ?? 0,
    ];

    $stmt = $conn->prepare(
        'UPDATE tbl_syl_leave_available_balance
         SET cBackVaca = :vac,
             cBalSick = :sick,
             NoAvailVL = :nvl,
             NoAvailSL = :nsl,
             NoAvailSLManual = :nsl_manual,
             NoAvailSLManualSetAt = :nsl_manual_at,
             MonetaryVL = :mvl,
             MonetarySL = :msl
         WHERE piid = :piid AND year = :year'
    );
    $stmt->execute($params);
    $exists = $conn->prepare(
        'SELECT 1
         FROM tbl_syl_leave_available_balance
         WHERE piid = :piid AND year = :year
         LIMIT 1'
    );
    $exists->execute([':piid' => $piid, ':year' => $year]);
    if ($exists->fetchColumn()) {
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_available_balance
            (piid, year, cBackVaca, cBalSick, NoAvailVL, NoAvailSL, NoAvailSLManual, NoAvailSLManualSetAt, MonetaryVL, MonetarySL)
         VALUES (:piid, :year, :vac, :sick, :nvl, :nsl, :nsl_manual, :nsl_manual_at, :mvl, :msl)'
    );
    $stmt->execute($params);
}

// ── Recalculation ─────────────────────────────────────────────────────────────

function lv_recalc_status_ensure_schema(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `" . _LV_RECALC_STATUS_TABLE . "` (
            `PIID` varchar(20) NOT NULL,
            `LeaveYear` int(11) NOT NULL,
            `LedgerFingerprint` varchar(80) NOT NULL,
            `LastRecalculatedAt` datetime NOT NULL,
            `Source` varchar(40) NOT NULL,
            PRIMARY KEY (`PIID`, `LeaveYear`),
            KEY `idx_lv_recalc_year_time` (`LeaveYear`, `LastRecalculatedAt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    $ensured = true;
}

function lv_recalc_fingerprint_expr(): string {
    return "CONCAT(
        COUNT(*), ':',
        COALESCE(MAX(LID), 0), ':',
        COALESCE(SUM(CRC32(CONCAT_WS('|',
            LID,
            COALESCE(Type_of_Records, ''),
            COALESCE(Date_of_Filing, ''),
            COALESCE(Period_From, ''),
            COALESCE(Period_To, ''),
            COALESCE(VacEarn, 0),
            COALESCE(VacWP, 0),
            COALESCE(VacWOP, 0),
            COALESCE(SickEarn, 0),
            COALESCE(SickWP, 0),
            COALESCE(SickWOP, 0),
            COALESCE(no_avail_mone_VL, 0),
            COALESCE(no_avail_mone_SL, 0),
            COALESCE(no_avail_SL, 0),
            COALESCE(no_avail_VL, 0),
            COALESCE(no_avail_SP, 0),
            COALESCE(no_avail_P, 0)
        ))), 0)
    )";
}

function lv_recalc_fingerprints(PDO $conn, array $piids, int $year): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), static fn(string $piid): bool => trim($piid) !== '')));
    if (!$piids) return [];

    $fingerprints = array_fill_keys($piids, '0:0:0');
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT PIID, " . lv_recalc_fingerprint_expr() . " AS LedgerFingerprint
         FROM tbl_syl_leave_form
         WHERE isDeleted = 0
           AND PIID IN ($placeholders)
           AND Date_of_Filing BETWEEN ? AND ?
         GROUP BY PIID"
    );
    $params = $piids;
    $params[] = sprintf('%04d-01-01', $year);
    $params[] = sprintf('%04d-12-31', $year);
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $fingerprints[(string)$row['PIID']] = (string)($row['LedgerFingerprint'] ?? '0:0:0');
    }
    return $fingerprints;
}

function lv_recalc_statuses(PDO $conn, array $piids, int $year): array {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), static fn(string $piid): bool => trim($piid) !== '')));
    if (!$piids) return [];

    lv_recalc_status_ensure_schema($conn);
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT PIID, LedgerFingerprint, LastRecalculatedAt
         FROM " . _LV_RECALC_STATUS_TABLE . "
         WHERE LeaveYear = ?
           AND PIID IN ($placeholders)"
    );
    $params = [$year];
    array_push($params, ...$piids);
    $stmt->execute($params);

    $statuses = [];
    foreach ($stmt->fetchAll() as $row) {
        $statuses[(string)$row['PIID']] = $row;
    }
    return $statuses;
}

function lv_recalc_mark_clean(PDO $conn, string $piid, int $year, string $fingerprint = '', string $source = 'manual'): void {
    lv_recalc_status_ensure_schema($conn);
    if ($fingerprint === '') {
        $fingerprints = lv_recalc_fingerprints($conn, [$piid], $year);
        $fingerprint = $fingerprints[$piid] ?? '0:0:0';
    }

    $stmt = $conn->prepare(
        "INSERT INTO " . _LV_RECALC_STATUS_TABLE . "
            (PIID, LeaveYear, LedgerFingerprint, LastRecalculatedAt, Source)
         VALUES
            (:piid, :year, :fingerprint, NOW(), :source)
         ON DUPLICATE KEY UPDATE
            LedgerFingerprint = VALUES(LedgerFingerprint),
            LastRecalculatedAt = VALUES(LastRecalculatedAt),
            Source = VALUES(Source)"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':year' => $year,
        ':fingerprint' => $fingerprint,
        ':source' => $source,
    ]);
}

function lv_recalculate_stale_for_employees(PDO $conn, array $piids, int $year, string $source = 'report'): int {
    $fingerprints = lv_recalc_fingerprints($conn, $piids, $year);
    $statuses = lv_recalc_statuses($conn, $piids, $year);
    $count = 0;

    foreach ($piids as $piid) {
        $piid = (string)$piid;
        $fingerprint = $fingerprints[$piid] ?? '0:0:0';
        $status = $statuses[$piid] ?? null;
        if ($status && (string)($status['LedgerFingerprint'] ?? '') === $fingerprint) {
            continue;
        }
        lv_recalculate($conn, $piid, $year, $source, $fingerprint);
        $count++;
    }

    return $count;
}

function lv_recalculate_for_employees(PDO $conn, array $piids, int $year, string $source = 'report'): int {
    $piids = array_values(array_unique(array_filter(array_map('strval', $piids), static fn($piid) => trim($piid) !== '')));
    if ($piids === []) {
        return 0;
    }

    $fingerprints = lv_recalc_fingerprints($conn, $piids, $year);
    $count = 0;
    foreach ($piids as $piid) {
        lv_recalculate($conn, $piid, $year, $source, $fingerprints[$piid] ?? '0:0:0');
        $count++;
    }

    return $count;
}

function lv_recalculate_stale_batch(PDO $conn, int $year, string $dept = '', int $limit = 50): array {
    $limit = max(1, min(300, $limit));
    $employees = lv_report_employee_base($conn, $year, (int)date('n'), $dept);
    if (!$employees) {
        return ['checked' => 0, 'recalculated' => 0, 'remaining_stale' => 0];
    }

    $piids = array_map(static fn(array $row): string => (string)$row['PIID'], $employees);
    $fingerprints = lv_recalc_fingerprints($conn, $piids, $year);
    $statuses = lv_recalc_statuses($conn, $piids, $year);
    $stale = [];

    foreach ($piids as $piid) {
        $fingerprint = $fingerprints[$piid] ?? '0:0:0';
        $status = $statuses[$piid] ?? null;
        if (!$status || (string)($status['LedgerFingerprint'] ?? '') !== $fingerprint) {
            $stale[] = $piid;
        }
    }

    $batch = array_slice($stale, 0, $limit);
    foreach ($batch as $piid) {
        lv_recalculate($conn, $piid, $year, 'warmup', $fingerprints[$piid] ?? '0:0:0');
    }

    return [
        'checked' => count($piids),
        'recalculated' => count($batch),
        'remaining_stale' => max(0, count($stale) - count($batch)),
    ];
}

function lv_recalculate_report_batch(PDO $conn, int $year, int $cutoff_month, string $dept = '', int $offset = 0, int $limit = 25): array {
    $cutoff_month = max(1, min(12, $cutoff_month));
    $offset = max(0, $offset);
    $limit = max(1, min(100, $limit));

    $employees = lv_report_employee_base($conn, $year, $cutoff_month, $dept);
    if (!$employees) {
        return ['total' => 0, 'offset' => $offset, 'processed' => 0, 'remaining' => 0, 'done' => true];
    }

    $total = count($employees);
    $batch = array_slice($employees, $offset, $limit);
    $piids = array_map(static fn(array $row): string => (string)$row['PIID'], $batch);
    $processed = lv_recalculate_for_employees($conn, $piids, $year, 'report-batch');
    $next_offset = $offset + count($batch);

    return [
        'total' => $total,
        'offset' => $offset,
        'next_offset' => $next_offset,
        'processed' => $processed,
        'remaining' => max(0, $total - $next_offset),
        'done' => $next_offset >= $total,
    ];
}

/**
 * Recalculate running VL and SL balances for all records in a year.
 *
 * Mirrors VB frmRevisedLeaveMngmnt.recalculateRecord():
 *  - Starts from forwarded balance (cBackVaca / cBalSick)
 *  - Each record: balance = prev_balance + earned - with_pay - without_pay - monetized
 *  - Updates VacBal / SickBal in-place.
 */
function lv_recalculate(PDO $conn, string $piid, int $year, string $source = 'manual', string $known_fingerprint = ''): void {
    $balance = lv_get_balance($conn, $piid, $year);
    $vBal  = (float)($balance['cBackVaca'] ?? 0);
    $sBal  = (float)($balance['cBalSick']  ?? 0);

    $records = lv_get_records($conn, $piid, $year);
    if (empty($records)) {
        lv_recalc_mark_clean($conn, $piid, $year, $known_fingerprint !== '' ? $known_fingerprint : '0:0:0', $source);
        return;
    }

    $savedSpecialBal = (float)($balance['NoAvailSL'] ?? 0);
    $specialBal = $savedSpecialBal > 0 ? $savedSpecialBal : _LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE;
    $soloParentBal = 7.0;
    $paternityBal = 7.0;

    $upd = $conn->prepare(
        'UPDATE tbl_syl_leave_form
         SET VacBal = :vbal,
             SickBal = :sbal,
             no_avail_SL = :special_bal,
             no_avail_SP = :solo_parent_bal,
             no_avail_P = :paternity_bal
         WHERE LID = :lid'
    );

    lv_recalc_status_ensure_schema($conn);
    $startedTx = !$conn->inTransaction();
    if ($startedTx) {
        $conn->beginTransaction();
    }

    try {
        foreach ($records as $row) {
            $vBal = $vBal
                + (float)$row['VacEarn']
                - (float)$row['VacWP']
                - (float)$row['VacWOP']
                - (float)$row['no_avail_mone_VL'];

            $sBal = $sBal
                + (float)$row['SickEarn']
                - (float)$row['SickWP']
                - (float)$row['SickWOP']
                - (float)$row['no_avail_mone_SL'];

            $mainType = strtolower(lv_main_type((string)($row['Type_of_Records'] ?? '')));
            $days = lv_record_leave_days($row);
            if (in_array($mainType, ['others, (specify)', 'others (specify)', 'special privilege leave'], true)) {
                $specialBal -= $days;
            } elseif ($mainType === 'solo parent leave') {
                $soloParentBal -= $days;
            } elseif ($mainType === 'paternity leave') {
                $paternityBal -= $days;
            }

            $upd->execute([
                ':vbal' => round($vBal, 4),
                ':sbal' => round($sBal, 4),
                ':special_bal' => round(max(0, $specialBal), 4),
                ':solo_parent_bal' => round(max(0, $soloParentBal), 4),
                ':paternity_bal' => round(max(0, $paternityBal), 4),
                ':lid' => $row['LID'],
            ]);
        }

        lv_recalc_mark_clean($conn, $piid, $year, $known_fingerprint, $source);

        if ($startedTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($startedTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

// ── Credits earned lookup table ───────────────────────────────────────────────

/**
 * Get all rows from the credits-earned lookup table.
 */
function lv_get_credits_earned(PDO $conn): array {
    $stmt = $conn->query(
        'SELECT LCEID, No_Of_Days_Present, On_Leave_Without_Pay, Leave_Credits_Earned
         FROM tbl_syl_leave_credits_earned
         ORDER BY No_Of_Days_Present ASC, On_Leave_Without_Pay ASC'
    );
    return $stmt->fetchAll();
}

/**
 * Replace the entire credits-earned lookup table with new rows.
 * Accepts array of ['days_present', 'lwop', 'credits'] maps.
 */
function lv_save_credits_earned(PDO $conn, array $rows): void {
    $conn->exec('TRUNCATE TABLE tbl_syl_leave_credits_earned');
    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_credits_earned
            (No_Of_Days_Present, On_Leave_Without_Pay, Leave_Credits_Earned)
         VALUES (:dp, :lwop, :ce)'
    );
    foreach ($rows as $r) {
        $stmt->execute([
            ':dp'   => $r['days_present'],
            ':lwop' => $r['lwop'],
            ':ce'   => $r['credits'],
        ]);
    }
}

// ── Working-day conversion table ─────────────────────────────────────────────

/**
 * Get all rows from the working-day conversion table.
 */
function lv_get_conversion(PDO $conn): array {
    $stmt = $conn->query(
        "SELECT CWHMID, Time, Type, Equivalent_Day
         FROM tbl_syl_leave_conversion_of_working_day
         ORDER BY Type ASC, Time ASC"
    );
    return $stmt->fetchAll();
}

/**
 * Replace the entire conversion table with new rows.
 * Accepts array of ['time', 'type', 'equivalent_day'] maps.
 */
function lv_save_conversion(PDO $conn, array $rows): void {
    $conn->exec('TRUNCATE TABLE tbl_syl_leave_conversion_of_working_day');
    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_conversion_of_working_day (Time, Type, Equivalent_Day)
         VALUES (:time, :type, :equiv)'
    );
    foreach ($rows as $r) {
        $stmt->execute([
            ':time'  => $r['time'],
            ':type'  => $r['type'],   // 'Hr' or 'Min'
            ':equiv' => $r['equivalent_day'],
        ]);
    }
}

// ── Dashboard stats ───────────────────────────────────────────────────────────

/**
 * Return aggregate counts for the dashboard stats strip.
 */
function lv_dashboard_stats(PDO $conn): array {
    $year = (int)date('Y');
    $month = (int)date('n');
    lv_ensure_employee_roster($conn, $year, $month);

    $empStmt = $conn->prepare(
        'SELECT COUNT(*) FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE SnapshotYear = :snapshot_year
           AND SnapshotMonth = :snapshot_month'
    );
    $empStmt->execute([
        ':snapshot_year' => $year,
        ':snapshot_month' => $month,
    ]);
    $employees = (int)$empStmt->fetchColumn();

    $pds = 0; // pds_personal_info is in the cgmhris DB, not queried here

    $leaveStmt = $conn->prepare(
        "SELECT COUNT(*) FROM tbl_syl_leave_form WHERE YEAR(Period_From) = :yr AND isDeleted = 0"
    );
    $leaveStmt->execute([':yr' => $year]);
    $leave = (int)$leaveStmt->fetchColumn();

    $deptStmt = $conn->prepare(
        'SELECT COUNT(DISTINCT Department) FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE SnapshotYear = :snapshot_year
           AND SnapshotMonth = :snapshot_month'
    );
    $deptStmt->execute([
        ':snapshot_year' => $year,
        ':snapshot_month' => $month,
    ]);
    $depts = (int)$deptStmt->fetchColumn();

    lv_ensure_leave_applications_table($conn);
    $pendingStmt = $conn->prepare(
        "SELECT COUNT(*) FROM tbl_leave_applications WHERE status = 'Pending'"
    );
    $pendingStmt->execute();
    $pendingApplications = (int)$pendingStmt->fetchColumn();

    return [
        'employees'   => $employees,
        'pds'         => $pds,
        'leave'       => $leave,
        'departments' => $depts,
        'pending_applications' => $pendingApplications,
    ];
}

// ── Monthly / quarterly report helpers ───────────────────────────────────────

function lv_report_employee_base(PDO $conn, int $year, int $cutoff_month, string $dept = ''): array {
    lv_ensure_employee_roster($conn, $year, $cutoff_month);
    $params = [];
    $where = ['1'];

    if ($dept !== '') {
        $where[] = 'Department = :dept';
        $params[':dept'] = $dept;
    }

    $stmt = $conn->prepare(
        'SELECT PIID,
                Surname,
                Firstname,
                MiddleName,
                Department,
                Position
         FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE SnapshotYear = :snapshot_year
           AND SnapshotMonth = :snapshot_month
           AND ' . implode(' AND ', $where) . '
         ORDER BY Department, Surname, Firstname'
    );
    $params[':snapshot_year'] = $year;
    $params[':snapshot_month'] = $cutoff_month;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lv_report_rows_up_to_cutoff(PDO $conn, array $piids, int $year, string $cutoff_date): array {
    if (empty($piids)) return [];
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT LID, PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
                VacBal, SickBal, no_avail_VL, no_avail_SL,
                no_avail_mone_VL, no_avail_mone_SL,
                no_avail_SP, no_avail_P
         FROM tbl_syl_leave_form
         WHERE isDeleted = 0
           AND PIID IN ($placeholders)
           AND Date_of_Filing BETWEEN ? AND ?
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC"
    );
    $params = array_values($piids);
    $params[] = sprintf('%04d-01-01', $year);
    $params[] = $cutoff_date;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lv_report_rows_in_period(PDO $conn, array $piids, string $period_from, string $period_to): array {
    if (empty($piids)) return [];
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT LID, PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
                VacWP, VacWOP, SickWP, SickWOP,
                no_avail_mone_VL, no_avail_mone_SL, no_avail_SP, no_avail_P
         FROM tbl_syl_leave_form
         WHERE isDeleted = 0
           AND PIID IN ($placeholders)
           AND Period_From BETWEEN ? AND ?
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC"
    );
    $params = array_values($piids);
    $params[] = $period_from;
    $params[] = $period_to;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lv_report_forwarded_balances(PDO $conn, array $piids, int $year): array {
    if (empty($piids)) return [];
    lv_ensure_available_balance_schema($conn);
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT piid, cBackVaca, cBalSick, NoAvailVL, NoAvailSL, NoAvailSLManual, NoAvailSLManualSetAt
         FROM tbl_syl_leave_available_balance
         WHERE year = ?
           AND piid IN ($placeholders)"
    );
    $params = [$year];
    foreach ($piids as $piid) {
        $params[] = $piid;
    }
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['NoAvailVL'] = lv_normalize_mandatory_forced_leave($row['NoAvailVL'] ?? null);
        if (!lv_special_leave_manual_override($row)) {
            $row['NoAvailSL'] = lv_auto_special_leave_forward($conn, (string)$row['piid'], $year);
        }
        $rows[$row['piid']] = $row;
    }
    return $rows;
}

function lv_special_leave_days_used(PDO $conn, string $piid, int $year): float {
    $days = 0.0;
    foreach (lv_get_records($conn, $piid, $year) as $row) {
        $mainType = strtolower(lv_main_type((string)($row['Type_of_Records'] ?? '')));
        if (in_array($mainType, ['others, (specify)', 'others (specify)', 'special privilege leave'], true)) {
            $days += lv_record_leave_days($row);
        }
    }
    return round($days, 4);
}

function lv_report_type_matches(string $type, array $needles): bool {
    $type = strtolower(trim($type));
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($type, strtolower($needle))) {
            return true;
        }
    }
    return false;
}

function lv_report_main_type(string $type): string {
    $parts = explode('|', $type, 2);
    return trim($parts[0] ?? $type);
}

function lv_report_period_display(array $row): string {
    $from = trim((string)($row['Period_From'] ?? ''));
    $to = trim((string)($row['Period_To'] ?? ''));
    $source = $from !== '' ? $from : trim((string)($row['Date_of_Filing'] ?? ''));
    if ($source === '') return '';

    $dtFrom = strtotime($source);
    if ($dtFrom === false) return $source;

    $toSource = $to !== '' ? $to : $source;
    $dtTo = strtotime($toSource);
    if ($dtTo === false) {
        return date('M j, Y', $dtFrom);
    }

    if (date('Y-m-d', $dtFrom) === date('Y-m-d', $dtTo)) {
        return date('M j, Y', $dtFrom);
    }

    if (date('Y-m', $dtFrom) === date('Y-m', $dtTo)) {
        return date('M j', $dtFrom) . '-' . date('j, Y', $dtTo);
    }

    if (date('Y', $dtFrom) === date('Y', $dtTo)) {
        return date('M j', $dtFrom) . '-' . date('M j, Y', $dtTo);
    }

    return date('M j, Y', $dtFrom) . ' - ' . date('M j, Y', $dtTo);
}

function lv_report_has_vacation_application(array $row, string $type): bool {
    $mainType = strtolower(lv_report_main_type($type));
    return in_array($mainType, [
        'vacation leave',
        'vacation leave w/o pay',
    ], true);
}

function lv_report_has_sick_application(array $row, string $type): bool {
    $mainType = strtolower(lv_report_main_type($type));
    return in_array($mainType, [
        'sick leave',
        'sick leave w/o pay',
    ], true);
}

function lv_report_is_monetization(string $type): bool {
    return strtolower(lv_report_main_type($type)) === 'monetization of leave credits';
}

/**
 * Get leave balance report per employee for a given year and period.
 * Balances are derived from the latest ledger row on or before the selected
 * period cutoff date. Activity fields use the selected month/quarter window.
 */
function lv_report_summary(PDO $conn, int $year, ?int $month_from = null, ?int $month_to = null, string $dept = '', bool $recalculate = true): array {
    $month_from = $month_from !== null ? max(1, min(12, $month_from)) : 1;
    $month_to   = $month_to   !== null ? max(1, min(12, $month_to))   : 12;
    if ($month_from > $month_to) {
        [$month_from, $month_to] = [$month_to, $month_from];
    }

    $period_from = sprintf('%04d-%02d-01', $year, $month_from);
    $cutoff_date = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month_to)));

    $employees = lv_report_employee_base($conn, $year, $month_to, $dept);
    if (empty($employees)) return [];

    $piids = array_column($employees, 'PIID');

    if ($recalculate) {
        // The report is an official balance surface, so rebuild running balances
        // before reading rows instead of trusting cached recalc status.
        lv_recalculate_for_employees($conn, $piids, $year, 'report');
    }

    $up_to_cutoff = lv_report_rows_up_to_cutoff($conn, $piids, $year, $cutoff_date);
    $in_period = lv_report_rows_in_period($conn, $piids, $period_from, $cutoff_date);
    $forwarded = lv_report_forwarded_balances($conn, $piids, $year);

    $latest_by_piid = [];
    foreach ($up_to_cutoff as $row) {
        $latest_by_piid[$row['PIID']] = $row;
    }

    $wellness_used = [];
    foreach ($up_to_cutoff as $row) {
        $piid = $row['PIID'];
        if (!isset($wellness_used[$piid])) {
            $wellness_used[$piid] = 0.0;
        }
        $wellness_used[$piid] += lv_wellness_days_for_record($row);
    }

    $activity = [];
    foreach ($in_period as $row) {
        $piid = $row['PIID'];
        if (!isset($activity[$piid])) {
            $activity[$piid] = [
                'vac_applied_display' => '',
                'sick_applied_display' => '',
                'vac_applied_dates' => [],
                'sick_applied_dates' => [],
                'monetized_vl' => 0.0,
                'monetized_sl' => 0.0,
            ];
        }

        $type = (string)($row['Type_of_Records'] ?? '');
        $display = lv_report_period_display($row);

        if ($display !== '' && lv_report_has_vacation_application($row, $type)) {
            $activity[$piid]['vac_applied_dates'][$display] = true;
        }

        if ($display !== '' && lv_report_has_sick_application($row, $type)) {
            $activity[$piid]['sick_applied_dates'][$display] = true;
        }

        if (lv_report_is_monetization($type)) {
            $vlMonetized = (float)($row['no_avail_mone_VL'] ?? 0);
            $slMonetized = (float)($row['no_avail_mone_SL'] ?? 0);

            // Existing ledger rows often store monetization in the same
            // visible vacation/sick deduction fields shown on leave_form.
            if ($vlMonetized == 0.0) {
                $vlMonetized = (float)($row['VacWP'] ?? 0);
            }
            if ($slMonetized == 0.0) {
                $slMonetized = (float)($row['SickWP'] ?? 0);
            }

            $activity[$piid]['monetized_vl'] += $vlMonetized;
            $activity[$piid]['monetized_sl'] += $slMonetized;
        } else {
            $activity[$piid]['monetized_vl'] += (float)($row['no_avail_mone_VL'] ?? 0);
            $activity[$piid]['monetized_sl'] += (float)($row['no_avail_mone_SL'] ?? 0);
        }
    }

    $report = [];
    foreach ($employees as $emp) {
        $piid = $emp['PIID'];
        $latest = $latest_by_piid[$piid] ?? null;
        $fwd = $forwarded[$piid] ?? null;
        if ($fwd === null) {
            $fwd = lv_get_balance($conn, (string)$piid, $year) ?? [
                'cBackVaca' => 0,
                'cBalSick' => 0,
                'NoAvailVL' => _LV_DEFAULT_MANDATORY_FORCED_LEAVE,
                'NoAvailSL' => _LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE,
            ];
        }
        $act = $activity[$piid] ?? [
            'vac_applied_display' => '',
            'sick_applied_display' => '',
            'vac_applied_dates' => [],
            'sick_applied_dates' => [],
            'monetized_vl' => 0.0,
            'monetized_sl' => 0.0,
        ];

        $emp['vac_balance'] = $latest !== null
            ? (float)($latest['VacBal'] ?? 0)
            : (float)($fwd['cBackVaca'] ?? 0);
        $emp['sick_balance'] = $latest !== null
            ? (float)($latest['SickBal'] ?? 0)
            : (float)($fwd['cBalSick'] ?? 0);
        $emp['vac_applied_display'] = !empty($act['vac_applied_dates'])
            ? implode('; ', array_keys($act['vac_applied_dates']))
            : '';
        $emp['sick_applied_display'] = !empty($act['sick_applied_dates'])
            ? implode('; ', array_keys($act['sick_applied_dates']))
            : '';
        $emp['monetized_vl'] = round($act['monetized_vl'], 4);
        $emp['monetized_sl'] = round($act['monetized_sl'], 4);
        $emp['available_mandatory_forced_leave'] = (float)($fwd['NoAvailVL'] ?? _LV_DEFAULT_MANDATORY_FORCED_LEAVE);
        $emp['available_special_leave'] = $latest !== null
            ? (float)($latest['no_avail_SL'] ?? 0)
            : (float)($fwd['NoAvailSL'] ?? 0);
        $emp['remaining_wellness_leave'] = round(max(0, 5 - (float)($wellness_used[$piid] ?? 0)), 4);
        $emp['cutoff_date'] = $cutoff_date;
        $emp['employee_ref'] = lv_employee_ref((string)$piid);

        if (
            $emp['vac_balance'] == 0.0 &&
            $emp['sick_balance'] == 0.0 &&
            $emp['vac_applied_display'] === '' &&
            $emp['sick_applied_display'] === '' &&
            $emp['monetized_vl'] == 0.0 &&
            $emp['monetized_sl'] == 0.0 &&
            $emp['available_mandatory_forced_leave'] == 0.0 &&
            $emp['available_special_leave'] == 0.0
        ) {
            continue;
        }

        $report[] = $emp;
    }

    return $report;
}

function lv_ensure_leave_applications_table(PDO $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS tbl_leave_applications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            piid VARCHAR(20) NOT NULL,
            id_number VARCHAR(20) DEFAULT NULL,
            employee_name VARCHAR(150) DEFAULT NULL,
            department VARCHAR(150) DEFAULT NULL,
            leave_type VARCHAR(100) NOT NULL,
            type_other VARCHAR(100) DEFAULT NULL,
            date_filed DATE NOT NULL,
            date_inputted DATE DEFAULT NULL,
            period_from DATE NOT NULL,
            period_to DATE NOT NULL,
            half_day_mode VARCHAR(10) DEFAULT NULL,
            half_day_window VARCHAR(40) DEFAULT NULL,
            days_requested DECIMAL(8,3) NOT NULL DEFAULT 0.000,
            vacation_location VARCHAR(30) DEFAULT NULL,
            vacation_abroad_details VARCHAR(100) DEFAULT NULL,
            sick_leave_detail VARCHAR(30) DEFAULT NULL,
            sick_illness VARCHAR(150) DEFAULT NULL,
            study_leave_detail VARCHAR(40) DEFAULT NULL,
            study_purpose VARCHAR(150) DEFAULT NULL,
            monetization_purpose VARCHAR(150) DEFAULT NULL,
            monetization_vl_days DECIMAL(8,3) NOT NULL DEFAULT 0.000,
            monetization_sl_days DECIMAL(8,3) NOT NULL DEFAULT 0.000,
            monetization_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            particulars VARCHAR(255) DEFAULT NULL,
            reason TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            dept_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            dept_remarks TEXT,
            dept_approved_by VARCHAR(100) DEFAULT NULL,
            dept_approved_at DATETIME DEFAULT NULL,
            dept_rejected_by VARCHAR(100) DEFAULT NULL,
            dept_rejected_at DATETIME DEFAULT NULL,
            admin_remarks TEXT,
            cancellation_reason TEXT,
            approved_by VARCHAR(100) DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            rejected_by VARCHAR(100) DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            cancelled_by_employee_at DATETIME DEFAULT NULL,
            posted_lid INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_leave_app_piid (piid),
            KEY idx_leave_app_status (status),
            KEY idx_leave_app_date_filed (date_filed),
            KEY idx_leave_app_status_department (status, department),
            KEY idx_leave_app_dept_status (dept_status, department)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    $columns = [];
    $stmt = $conn->query("SHOW COLUMNS FROM tbl_leave_applications");
    foreach ($stmt->fetchAll() as $row) {
        $columns[strtolower((string)$row['Field'])] = true;
    }
    $alter = [];
    if (!isset($columns['type_other'])) {
        $alter[] = "ADD COLUMN type_other VARCHAR(100) DEFAULT NULL AFTER leave_type";
    }
    if (!isset($columns['date_inputted'])) {
        $alter[] = "ADD COLUMN date_inputted DATE DEFAULT NULL AFTER date_filed";
    }
    if (!isset($columns['half_day_mode'])) {
        $alter[] = "ADD COLUMN half_day_mode VARCHAR(10) DEFAULT NULL AFTER period_to";
    }
    if (!isset($columns['half_day_window'])) {
        $alter[] = "ADD COLUMN half_day_window VARCHAR(40) DEFAULT NULL AFTER half_day_mode";
    }
    if (!isset($columns['vacation_location'])) {
        $alter[] = "ADD COLUMN vacation_location VARCHAR(30) DEFAULT NULL AFTER days_requested";
    }
    if (!isset($columns['vacation_abroad_details'])) {
        $alter[] = "ADD COLUMN vacation_abroad_details VARCHAR(100) DEFAULT NULL AFTER vacation_location";
    }
    if (!isset($columns['sick_leave_detail'])) {
        $alter[] = "ADD COLUMN sick_leave_detail VARCHAR(30) DEFAULT NULL AFTER vacation_abroad_details";
    }
    if (!isset($columns['sick_illness'])) {
        $alter[] = "ADD COLUMN sick_illness VARCHAR(150) DEFAULT NULL AFTER sick_leave_detail";
    }
    if (!isset($columns['study_leave_detail'])) {
        $alter[] = "ADD COLUMN study_leave_detail VARCHAR(40) DEFAULT NULL AFTER sick_illness";
    }
    if (!isset($columns['study_purpose'])) {
        $alter[] = "ADD COLUMN study_purpose VARCHAR(150) DEFAULT NULL AFTER study_leave_detail";
    }
    if (!isset($columns['monetization_purpose'])) {
        $alter[] = "ADD COLUMN monetization_purpose VARCHAR(150) DEFAULT NULL AFTER study_purpose";
    }
    if (!isset($columns['monetization_vl_days'])) {
        $alter[] = "ADD COLUMN monetization_vl_days DECIMAL(8,3) NOT NULL DEFAULT 0.000 AFTER monetization_purpose";
    }
    if (!isset($columns['monetization_sl_days'])) {
        $alter[] = "ADD COLUMN monetization_sl_days DECIMAL(8,3) NOT NULL DEFAULT 0.000 AFTER monetization_vl_days";
    }
    if (!isset($columns['monetization_amount'])) {
        $alter[] = "ADD COLUMN monetization_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER monetization_sl_days";
    }
    if (!isset($columns['dept_status'])) {
        $alter[] = "ADD COLUMN dept_status VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER status";
    }
    if (!isset($columns['dept_remarks'])) {
        $alter[] = "ADD COLUMN dept_remarks TEXT AFTER dept_status";
    }
    if (!isset($columns['dept_approved_by'])) {
        $alter[] = "ADD COLUMN dept_approved_by VARCHAR(100) DEFAULT NULL AFTER dept_remarks";
    }
    if (!isset($columns['dept_approved_at'])) {
        $alter[] = "ADD COLUMN dept_approved_at DATETIME DEFAULT NULL AFTER dept_approved_by";
    }
    if (!isset($columns['dept_rejected_by'])) {
        $alter[] = "ADD COLUMN dept_rejected_by VARCHAR(100) DEFAULT NULL AFTER dept_approved_at";
    }
    if (!isset($columns['dept_rejected_at'])) {
        $alter[] = "ADD COLUMN dept_rejected_at DATETIME DEFAULT NULL AFTER dept_rejected_by";
    }
    if (!isset($columns['cancellation_reason'])) {
        $alter[] = "ADD COLUMN cancellation_reason TEXT AFTER admin_remarks";
    }
    foreach ($alter as $statement) {
        $conn->exec("ALTER TABLE tbl_leave_applications " . $statement);
    }

    $ensured = true;
}

function lv_application_now(): string {
    return date('Y-m-d H:i:s');
}

function lv_application_employee_name(array $employee): string {
    $parts = [
        trim((string)($employee['Firstname'] ?? '')),
        trim((string)($employee['MiddleName'] ?? '')) !== ''
            ? strtoupper(substr(trim((string)$employee['MiddleName']), 0, 1)) . '.'
            : '',
        trim((string)($employee['Surname'] ?? '')),
    ];
    return trim(implode(' ', array_filter($parts, static fn($v) => $v !== '')));
}

function lv_application_period_label(string $periodFrom, string $periodTo): string {
    if ($periodFrom === '') {
        return '';
    }

    try {
        $from = new DateTime($periodFrom);
        $to = $periodTo !== '' ? new DateTime($periodTo) : $from;
    } catch (Exception $e) {
        return strtoupper($periodFrom . ($periodTo !== '' && $periodTo !== $periodFrom ? '-' . $periodTo : ''));
    }

    $months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
    $fromMonth = $months[(int)$from->format('n') - 1];
    $toMonth = $months[(int)$to->format('n') - 1];
    $fromDay = (int)$from->format('j');
    $toDay = (int)$to->format('j');
    $fromYear = $from->format('Y');

    if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
        $days = (string)$fromDay;
    } elseif ($from->format('n') !== $to->format('n')) {
        $days = $fromDay . '-' . $toMonth . ' ' . $toDay;
    } else {
        $days = $fromDay . '-' . $toDay;
    }

    return $fromMonth . ' ' . $days . ' ,' . $fromYear;
}

function lv_calculate_days_requested(string $type, string $periodFrom, string $periodTo): float {
    return lv_count_leave_days_between($periodFrom, $periodTo, lv_type_includes_weekends($type));
}

function lv_application_half_day_mode(array $row): string {
    $mode = strtolower(trim((string)($row['half_day_mode'] ?? '')));
    return in_array($mode, ['am', 'pm'], true) ? $mode : 'full';
}

function lv_normalized_application_days(array $row): float {
    $stored = (float)($row['days_requested'] ?? 0);
    $mode = lv_application_half_day_mode($row);
    if ($mode === 'full') {
        return $stored;
    }

    $type = (string)($row['leave_type'] ?? '');
    $from = (string)($row['period_from'] ?? '');
    $to = (string)($row['period_to'] ?? '');
    if ($type === '' || $from === '' || $to === '') {
        return $stored;
    }

    $fullDays = lv_calculate_days_requested($type, $from, $to);
    if ($fullDays <= 1.0 && !lv_is_compensatory_type($type)) {
        return $stored;
    }

    return round($fullDays - 0.5, 3);
}

function lv_decorate_leave_application_row(array $row): array {
    $stored = (float)($row['days_requested'] ?? 0);
    $normalized = lv_normalized_application_days($row);
    $row['stored_days_requested'] = $stored;
    $row['days_requested'] = $normalized;
    $row['normalized_days_requested'] = $normalized;
    return $row;
}

function lv_get_employee_leave_applications(PDO $conn, string $piid, string $status = ''): array {
    lv_ensure_leave_applications_table($conn);
    $sql = "SELECT *
            FROM tbl_leave_applications
            WHERE piid = :piid";
    $params = [':piid' => $piid];
    if ($status !== '') {
        $sql .= " AND status = :status";
        $params[':status'] = $status;
    }
    $sql .= " ORDER BY created_at DESC, id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return array_map('lv_decorate_leave_application_row', $stmt->fetchAll());
}

function lv_get_leave_application(PDO $conn, int $id): ?array {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare("SELECT * FROM tbl_leave_applications WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch() ?: null;
    return $row ? lv_decorate_leave_application_row($row) : null;
}

function lv_create_leave_application(PDO $conn, string $piid, array $payload): int {
    lv_ensure_leave_applications_table($conn);
    $employee = lv_get_employee($conn, $piid);
    if (!$employee) {
        throw new RuntimeException('Employee not found');
    }

    $type = trim((string)($payload['leave_type'] ?? ''));
    $typeOther = trim((string)($payload['type_other'] ?? ''));
    $dateFiled = trim((string)($payload['date_filed'] ?? date('Y-m-d')));
    $dateInputted = trim((string)($payload['date_inputted'] ?? ''));
    $periodFrom = trim((string)($payload['period_from'] ?? ''));
    $periodTo = trim((string)($payload['period_to'] ?? ''));
    $halfDayMode = trim((string)($payload['half_day_mode'] ?? ''));
    $halfDayWindow = trim((string)($payload['half_day_window'] ?? ''));
    $vacationLocation = trim((string)($payload['vacation_location'] ?? ''));
    $vacationAbroadDetails = trim((string)($payload['vacation_abroad_details'] ?? ''));
    $sickLeaveDetail = trim((string)($payload['sick_leave_detail'] ?? ''));
    $sickIllness = trim((string)($payload['sick_illness'] ?? ''));
    $studyLeaveDetail = trim((string)($payload['study_leave_detail'] ?? ''));
    $studyPurpose = trim((string)($payload['study_purpose'] ?? ''));
    $monetizationPurpose = trim((string)($payload['monetization_purpose'] ?? ''));
    $monetizationVlDays = (float)($payload['monetization_vl_days'] ?? 0);
    $monetizationSlDays = (float)($payload['monetization_sl_days'] ?? 0);
    $monetizationAmount = (float)($payload['monetization_amount'] ?? 0);
    $daysRequested = (float)($payload['days_requested'] ?? 0);
    if ($daysRequested <= 0 && $periodFrom !== '' && $periodTo !== '') {
        $daysRequested = lv_calculate_days_requested($type, $periodFrom, $periodTo);
    }

    $stmt = $conn->prepare(
        "INSERT INTO tbl_leave_applications
            (piid, id_number, employee_name, department, leave_type, type_other, date_filed, date_inputted,
             period_from, period_to, half_day_mode, half_day_window, days_requested,
             vacation_location, vacation_abroad_details, sick_leave_detail, sick_illness,
             study_leave_detail, study_purpose, monetization_purpose, monetization_vl_days, monetization_sl_days, monetization_amount, particulars, reason,
             status, created_at, updated_at)
         VALUES
            (:piid, :id_number, :employee_name, :department, :leave_type, :type_other, :date_filed, :date_inputted,
             :period_from, :period_to, :half_day_mode, :half_day_window, :days_requested,
             :vacation_location, :vacation_abroad_details, :sick_leave_detail, :sick_illness,
             :study_leave_detail, :study_purpose, :monetization_purpose, :monetization_vl_days, :monetization_sl_days, :monetization_amount, :particulars, :reason,
             'Pending', :created_at, :updated_at)"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':piid' => $piid,
        ':id_number' => (string)($employee['ID_NUM'] ?? ''),
        ':employee_name' => lv_application_employee_name($employee),
        ':department' => (string)($employee['Department'] ?? ''),
        ':leave_type' => $type,
        ':type_other' => $typeOther !== '' ? $typeOther : null,
        ':date_filed' => $dateFiled,
        ':date_inputted' => $dateInputted !== '' ? $dateInputted : null,
        ':period_from' => $periodFrom,
        ':period_to' => $periodTo,
        ':half_day_mode' => $halfDayMode !== '' ? $halfDayMode : null,
        ':half_day_window' => $halfDayWindow !== '' ? $halfDayWindow : null,
        ':days_requested' => $daysRequested,
        ':vacation_location' => $vacationLocation !== '' ? $vacationLocation : null,
        ':vacation_abroad_details' => $vacationAbroadDetails !== '' ? $vacationAbroadDetails : null,
        ':sick_leave_detail' => $sickLeaveDetail !== '' ? $sickLeaveDetail : null,
        ':sick_illness' => $sickIllness !== '' ? $sickIllness : null,
        ':study_leave_detail' => $studyLeaveDetail !== '' ? $studyLeaveDetail : null,
        ':study_purpose' => $studyPurpose !== '' ? $studyPurpose : null,
        ':monetization_purpose' => $monetizationPurpose !== '' ? $monetizationPurpose : null,
        ':monetization_vl_days' => $monetizationVlDays,
        ':monetization_sl_days' => $monetizationSlDays,
        ':monetization_amount' => $monetizationAmount,
        ':particulars' => trim((string)($payload['particulars'] ?? '')),
        ':reason' => trim((string)($payload['reason'] ?? '')),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$conn->lastInsertId();
}

function lv_cancel_leave_application(PDO $conn, int $id, string $piid, string $reason): void {
    lv_ensure_leave_applications_table($conn);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Cancellation reason is required');
    }
    $stmt = $conn->prepare(
        "UPDATE tbl_leave_applications
         SET status = 'Cancelled',
             cancellation_reason = :cancellation_reason,
             cancelled_by_employee_at = :cancelled_at,
             updated_at = :updated_at
         WHERE id = :id
           AND piid = :piid
           AND status = 'Pending'"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':cancellation_reason' => $reason,
        ':cancelled_at' => $now,
        ':updated_at' => $now,
        ':id' => $id,
        ':piid' => $piid,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Pending leave application not found');
    }
}

function lv_search_leave_applications(PDO $conn, array $filters = []): array {
    lv_ensure_leave_applications_table($conn);
    $where = [];
    $params = [];

    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }

    $deptStatus = trim((string)($filters['dept_status'] ?? ''));
    if ($deptStatus !== '') {
        $where[] = 'dept_status = :dept_status';
        $params[':dept_status'] = $deptStatus;
    }

    $department = trim((string)($filters['department'] ?? ''));
    if ($department !== '') {
        $where[] = 'department = :department';
        $params[':department'] = $department;
    }

    $periodFrom = trim((string)($filters['period_from'] ?? ''));
    $periodTo = trim((string)($filters['period_to'] ?? ''));
    if ($periodFrom !== '' && $periodTo !== '') {
        $where[] = '(period_from <= :period_to AND period_to >= :period_from)';
        $params[':period_from'] = $periodFrom;
        $params[':period_to'] = $periodTo;
    }

    $query = trim((string)($filters['q'] ?? ''));
    if ($query !== '') {
        $where[] = '(employee_name LIKE :q OR id_number LIKE :q OR leave_type LIKE :q)';
        $params[':q'] = '%' . $query . '%';
    }

    $sql = "SELECT *
            FROM tbl_leave_applications";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC, id DESC';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return array_map('lv_decorate_leave_application_row', $stmt->fetchAll());
}

function lv_department_approve_leave_application(PDO $conn, int $id, string $department, string $actor, string $remarks = ''): void {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_leave_applications
         SET dept_status = 'Approved',
             dept_remarks = :remarks,
             dept_approved_by = :approved_by,
             dept_approved_at = :approved_at,
             dept_rejected_by = NULL,
             dept_rejected_at = NULL,
             updated_at = :updated_at
         WHERE id = :id
           AND department = :department
           AND status = 'Pending'
           AND dept_status = 'Pending'"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':remarks' => $remarks,
        ':approved_by' => $actor,
        ':approved_at' => $now,
        ':updated_at' => $now,
        ':id' => $id,
        ':department' => $department,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Pending department leave application not found');
    }
}

function lv_department_reject_leave_application(PDO $conn, int $id, string $department, string $actor, string $remarks = ''): void {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_leave_applications
         SET status = 'Rejected',
             dept_status = 'Rejected',
             dept_remarks = :remarks,
             dept_rejected_by = :rejected_by,
             dept_rejected_at = :rejected_at,
             admin_remarks = :remarks_admin,
             rejected_by = :rejected_by_admin,
             rejected_at = :rejected_at_admin,
             updated_at = :updated_at
         WHERE id = :id
           AND department = :department
           AND status = 'Pending'
           AND dept_status = 'Pending'"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':remarks' => $remarks,
        ':rejected_by' => $actor,
        ':rejected_at' => $now,
        ':remarks_admin' => $remarks,
        ':rejected_by_admin' => $actor,
        ':rejected_at_admin' => $now,
        ':updated_at' => $now,
        ':id' => $id,
        ':department' => $department,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Pending department leave application not found');
    }
}

function lv_application_to_record(array $app, string $actor): array {
    $type = strtoupper(trim((string)($app['leave_type'] ?? '')));
    $days = (float)($app['days_requested'] ?? 0);
    $mainType = strtoupper(lv_main_type($type));

    $record = [
        'PIID' => (string)$app['piid'],
        'Type_of_Records' => $type,
        'Date_of_Filing' => (string)$app['date_filed'],
        'Period_From' => (string)$app['period_from'],
        'Period_To' => (string)$app['period_to'],
        'VacEarn' => 0,
        'VacWP' => 0,
        'VacBal' => 0,
        'VacWOP' => 0,
        'SickEarn' => 0,
        'SickWP' => 0,
        'SickBal' => 0,
        'SickWOP' => 0,
        'Particulars' => strtoupper(trim((string)($app['particulars'] ?? ''))),
        'DateAction' => lv_application_period_label((string)$app['period_from'], (string)$app['period_to']),
        'DateProcessed' => trim((string)($app['date_inputted'] ?? '')) !== ''
            ? (string)$app['date_inputted']
            : date('Y-m-d'),
        'RecordedBy' => strtoupper($actor),
        'no_avail_VL' => 0,
        'no_avail_SL' => 0,
        'no_avail_mone_VL' => 0,
        'no_avail_mone_SL' => 0,
        'no_avail_SP' => 0,
        'no_avail_P' => 0,
        'no_avail_mone' => 0,
        'Remarks' => strtoupper(trim((string)($app['reason'] ?? ''))),
    ];

    if (in_array($mainType, ['VACATION LEAVE', 'UNDERTIME (SUBJECT TO VACATION LEAVE)', 'UNDERTIME', 'MANDATORY/FORCED LEAVE', 'SPECIAL LEAVE VACATION LEAVE', 'UNUSED FORCE VACATION LEAVE'], true)) {
        $record['VacWP'] = $days;
    } elseif ($mainType === 'VACATION LEAVE W/O PAY') {
        $record['VacWOP'] = $days;
    } elseif ($mainType === 'SICK LEAVE') {
        $record['SickWP'] = $days;
    } elseif ($mainType === 'SICK LEAVE W/O PAY') {
        $record['SickWOP'] = $days;
    } elseif ($mainType === 'MONETIZATION OF LEAVE CREDITS') {
        $monetizationVlDays = (float)($app['monetization_vl_days'] ?? 0);
        $monetizationSlDays = (float)($app['monetization_sl_days'] ?? 0);
        if ($monetizationVlDays <= 0 && $monetizationSlDays <= 0) {
            $monetizationVlDays = $days;
        }
        $record['no_avail_mone'] = $days;
        $record['no_avail_mone_VL'] = $monetizationVlDays;
        $record['no_avail_mone_SL'] = $monetizationSlDays;
    } elseif ($mainType === 'SOLO PARENT LEAVE') {
        $record['no_avail_SP'] = $days;
    } elseif ($mainType === 'PATERNITY LEAVE') {
        $record['no_avail_P'] = $days;
    }

    return $record;
}

function lv_approve_leave_application(PDO $conn, int $id, string $actor, string $remarks = ''): array {
    lv_ensure_leave_applications_table($conn);
    $app = lv_get_leave_application($conn, $id);
    if (!$app) {
        throw new RuntimeException('Leave application not found');
    }
    if ((string)$app['status'] !== 'Pending') {
        throw new RuntimeException('Leave application has already been processed');
    }
    if ((string)($app['dept_status'] ?? 'Pending') !== 'Approved') {
        throw new RuntimeException('Department approval is required before HR approval');
    }

    $record = lv_application_to_record($app, $actor);
    lv_recalc_status_ensure_schema($conn);
    $startedTx = !$conn->inTransaction();
    if ($startedTx) {
        $conn->beginTransaction();
    }

    try {
        if (lv_is_wellness_type($record['Type_of_Records'])) {
            $year = (int)date('Y', strtotime($record['Period_From']));
            $requested = lv_wellness_days_for_record($record);
            $used = lv_wellness_days_used($conn, (string)$app['piid'], $year);
            if ($requested > max(0, 5 - $used)) {
                throw new RuntimeException('Wellness Leave exceeds the remaining annual balance');
            }
        }
        if (
            lv_application_half_day_mode($app) !== 'full'
            && lv_calculate_days_requested($record['Type_of_Records'], $record['Period_From'], $record['Period_To']) <= 1.0
            && !lv_is_compensatory_type($record['Type_of_Records'])
        ) {
            throw new RuntimeException('Half-day leave must be part of a leave request longer than 1 day, such as 1.5 or 2.5 days.');
        }

        $lid = lv_insert_record($conn, $record, $actor);
        lv_upsert_dtr_override(
            $conn,
            $lid,
            $record['Type_of_Records'],
            $record['Period_From'],
            $record['Period_To'],
            $record['Particulars'],
            $record['PIID'],
            $actor,
            strtolower((string)($app['half_day_mode'] ?? '')) !== 'full' && trim((string)($app['half_day_mode'] ?? '')) !== '',
            strtoupper((string)($app['half_day_mode'] ?? ''))
        );
        lv_recalculate($conn, (string)$app['piid'], (int)date('Y', strtotime((string)$app['period_from'])));

        $stmt = $conn->prepare(
            "UPDATE tbl_leave_applications
             SET status = 'Approved',
                 admin_remarks = :remarks,
                 approved_by = :approved_by,
                 approved_at = :approved_at,
                 posted_lid = :posted_lid,
                 updated_at = :updated_at
             WHERE id = :id"
        );
        $now = lv_application_now();
        $stmt->execute([
            ':remarks' => $remarks,
            ':approved_by' => $actor,
            ':approved_at' => $now,
            ':posted_lid' => $lid,
            ':updated_at' => $now,
            ':id' => $id,
        ]);

        if ($startedTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($startedTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    return ['lid' => $lid, 'application' => lv_get_leave_application($conn, $id)];
}

function lv_reject_leave_application(PDO $conn, int $id, string $actor, string $remarks = ''): void {
    lv_ensure_leave_applications_table($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_leave_applications
         SET status = 'Rejected',
             admin_remarks = :remarks,
             rejected_by = :rejected_by,
             rejected_at = :rejected_at,
             updated_at = :updated_at
         WHERE id = :id
           AND status = 'Pending'"
    );
    $now = lv_application_now();
    $stmt->execute([
        ':remarks' => $remarks,
        ':rejected_by' => $actor,
        ':rejected_at' => $now,
        ':updated_at' => $now,
        ':id' => $id,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Pending leave application not found');
    }
}

lv_ensure_monthly_leave_earned_automation($leave_conn);
