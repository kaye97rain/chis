<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/db_auth.php';
require_once __DIR__ . '/leave_db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function auth_ok(array $data = []): never {
    echo json_encode(['status' => 'success'] + $data);
    exit;
}

function auth_fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

function auth_now(): string {
    return date('Y-m-d H:i:s');
}

function auth_body_value(array $body, string $key, $default = '') {
    return $body[$key] ?? $default;
}

function auth_ensure_employee_accounts(PDO $conn): void {
    hrmis_ensure_employee_accounts_table($conn);
}

function auth_find_employee_account(PDO $conn, string $idNumber): ?array {
    auth_ensure_employee_accounts($conn);
    $stmt = $conn->prepare(
        "SELECT id, piid, id_number, password_hash, email, email_source,
                email_confirmed_at, email_updated_at, must_change_password, is_active
         FROM tbl_employee_accounts
         WHERE id_number = ?
         LIMIT 1"
    );
    $stmt->execute([$idNumber]);
    return $stmt->fetch() ?: null;
}

function auth_find_employee_account_by_id(PDO $conn, int $id): ?array {
    auth_ensure_employee_accounts($conn);
    $stmt = $conn->prepare(
        "SELECT id, piid, id_number, password_hash, email, email_source,
                email_confirmed_at, email_updated_at, must_change_password, is_active
         FROM tbl_employee_accounts
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function auth_find_personal_by_id_number(PDO $conn, string $idNumber): ?array {
    $stmt = $conn->prepare(
        "SELECT PIID, ID_NUM, SurName, FirstName, MiddleName, Email
         FROM tblpersonalinformation
         WHERE ID_NUM = ?
         LIMIT 1"
    );
    $stmt->execute([$idNumber]);
    return $stmt->fetch() ?: null;
}

function auth_create_employee_account(PDO $conn, string $piid, string $idNumber, string $passwordHash): int {
    auth_ensure_employee_accounts($conn);
    $stmt = $conn->prepare(
        "INSERT INTO tbl_employee_accounts
            (piid, id_number, password_hash, must_change_password, is_active, created_at, updated_at)
         VALUES
            (?, ?, ?, 1, 1, ?, ?)"
    );
    $now = auth_now();
    $stmt->execute([$piid, $idNumber, $passwordHash, $now, $now]);
    return (int)$conn->lastInsertId();
}

function auth_update_employee_last_login(PDO $conn, int $id): void {
    auth_ensure_employee_accounts($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_employee_accounts
         SET last_login_at = ?, updated_at = ?
         WHERE id = ?"
    );
    $now = auth_now();
    $stmt->execute([$now, $now, $id]);
}

function auth_update_employee_password(PDO $conn, int $id, string $passwordHash): void {
    auth_ensure_employee_accounts($conn);
    $stmt = $conn->prepare(
        "UPDATE tbl_employee_accounts
         SET password_hash = ?, must_change_password = 0, updated_at = ?
         WHERE id = ?"
    );
    $stmt->execute([$passwordHash, auth_now(), $id]);
}

function auth_normalize_email(string $email): string {
    return strtolower(trim($email));
}

function auth_pds_email_for_employee(PDO $conn, string $piid, string $idNumber): string {
    try {
        $person = auth_find_personal_by_id_number($conn, $idNumber);
        $legacyEmail = auth_normalize_email((string)($person['Email'] ?? ''));
        if ($legacyEmail !== '' && filter_var($legacyEmail, FILTER_VALIDATE_EMAIL)) {
            return $legacyEmail;
        }

        $surname = trim((string)($person['SurName'] ?? ''));
        $firstName = trim((string)($person['FirstName'] ?? ''));
        $middleName = trim((string)($person['MiddleName'] ?? ''));

        require_once __DIR__ . '/pds_db.php';
        if (!isset($GLOBALS['pds_conn']) || !$GLOBALS['pds_conn'] instanceof PDO) {
            return '';
        }
        $pds = $GLOBALS['pds_conn'];
        $stmt = $pds->prepare(
            "SELECT email
             FROM pds_personal_info
             WHERE COALESCE(email, '') <> ''
               AND (
                    bio_id = :piid
                    OR employee_no = :id_number_employee
                    OR agency_employee_no = :id_number_agency
                    OR (
                        :surname <> ''
                        AND :first_name <> ''
                        AND UPPER(TRIM(surname)) = UPPER(:surname_match)
                        AND UPPER(TRIM(first_name)) = UPPER(:first_name_match)
                        AND (
                            :middle_name = ''
                            OR UPPER(TRIM(COALESCE(middle_name, ''))) = UPPER(:middle_name_match)
                        )
                    )
               )
             ORDER BY
                CASE
                    WHEN employee_no = :id_number_order_employee THEN 0
                    WHEN agency_employee_no = :id_number_order_agency THEN 1
                    WHEN bio_id = :piid_order THEN 2
                    ELSE 3
                END
             LIMIT 1"
        );
        $stmt->execute([
            ':piid' => $piid,
            ':id_number_employee' => $idNumber,
            ':id_number_agency' => $idNumber,
            ':surname' => $surname,
            ':first_name' => $firstName,
            ':middle_name' => $middleName,
            ':surname_match' => $surname,
            ':first_name_match' => $firstName,
            ':middle_name_match' => $middleName,
            ':id_number_order_employee' => $idNumber,
            ':id_number_order_agency' => $idNumber,
            ':piid_order' => $piid,
        ]);
        return trim((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        error_log('auth_pds_email_for_employee: ' . $e->getMessage());
        return '';
    }
}

function auth_employee_contact_payload(PDO $conn, array $user): array {
    $accountId = (int)($user['employee_account_id'] ?? $user['id'] ?? 0);
    $account = auth_find_employee_account_by_id($conn, $accountId);
    if (!$account) {
        auth_fail('Employee account not found', 404);
    }

    $pdsEmail = auth_normalize_email(auth_pds_email_for_employee(
        $conn,
        (string)($account['piid'] ?? ''),
        (string)($account['id_number'] ?? '')
    ));
    $savedEmail = auth_normalize_email((string)($account['email'] ?? ''));
    $displayEmail = $savedEmail !== '' ? $savedEmail : $pdsEmail;

    return [
        'email' => $displayEmail,
        'pds_email' => $pdsEmail,
        'email_source' => $savedEmail !== '' ? (string)($account['email_source'] ?? 'employee') : ($pdsEmail !== '' ? 'pds' : ''),
        'email_confirmed' => trim((string)($account['email_confirmed_at'] ?? '')) !== '',
        'email_confirmed_at' => $account['email_confirmed_at'] ?? null,
    ];
}

function auth_save_employee_contact(PDO $conn, array $user, string $email, string $source): array {
    $accountId = (int)($user['employee_account_id'] ?? $user['id'] ?? 0);
    $account = auth_find_employee_account_by_id($conn, $accountId);
    if (!$account) {
        auth_fail('Employee account not found', 404);
    }

    $email = auth_normalize_email($email);
    if ($email === '') {
        auth_fail('Email address is required');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        auth_fail('Please enter a valid email address');
    }

    $source = in_array($source, ['pds', 'employee'], true) ? $source : 'employee';
    $now = auth_now();
    $stmt = $conn->prepare(
        "UPDATE tbl_employee_accounts
         SET email = :email,
             email_source = :source,
             email_confirmed_at = :confirmed_at,
             email_updated_at = :updated_at,
             updated_at = :updated_at
         WHERE id = :id"
    );
    $stmt->execute([
        ':email' => $email,
        ':source' => $source,
        ':confirmed_at' => $now,
        ':updated_at' => $now,
        ':id' => $accountId,
    ]);

    return auth_employee_contact_payload($conn, $user);
}

function auth_user_payload(array $profile): array {
    return [
        'id' => (int)($profile['id'] ?? 0),
        'username' => (string)($profile['username'] ?? ''),
        'userlevel' => (int)($profile['userlevel'] ?? 1),
        'role' => (string)($profile['role'] ?? 'unknown'),
        'department' => $profile['department'] ?? null,
        'dept_approver_role' => $profile['dept_approver_role'] ?? null,
        'piid' => $profile['piid'] ?? null,
        'id_number' => $profile['id_number'] ?? null,
        'must_change_password' => (bool)($profile['must_change_password'] ?? false),
        'email' => $profile['email'] ?? null,
        'email_confirmed' => !empty($profile['email_confirmed']),
    ];
}

function auth_warm_employee_leave(PDO $conn, array $profile): void {
    if (($profile['role'] ?? '') !== 'employee') {
        return;
    }

    $piid = trim((string)($profile['piid'] ?? ''));
    if ($piid === '') {
        return;
    }

    try {
        lv_recalculate_stale_for_employees($conn, [$piid], (int)date('Y'), 'employee-login');
    } catch (Throwable $e) {
        error_log('employee login leave warmup failed: ' . $e->getMessage());
    }
}

if (!$conn instanceof PDO) {
    auth_fail('Database connection unavailable', 500);
}

$action = trim($_GET['action'] ?? '');
$raw = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?? []) : [];

switch ($action) {
    case 'validate':
    case 'me':
        $user = validateToken();
        if (!$user) {
            auth_fail('Authentication required', 401);
        }
        auth_ok(['user' => $user]);

    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            auth_fail('POST required');
        }

        $username = trim((string)auth_body_value($body, 'username'));
        $password = (string)auth_body_value($body, 'password');
        if ($username === '' || $password === '') {
            auth_fail('Username and password are required');
        }

        try {
            $sessionIdentity = null;
            $userPayload = null;

            $chk = $conn->prepare("SELECT UID FROM tblsysuser WHERE Username = ?");
            $chk->execute([$username]);
            $sysRow = $chk->fetch();

            if ($sysRow) {
                $stmt = $conn->prepare(
                    "SELECT UID, Userlevel, Username
                     FROM tblsysuser
                     WHERE Username = ? AND Password = MD5(?)"
                );
                $stmt->execute([$username, $password]);
                $row = $stmt->fetch();

                if ($row) {
                    $departmentApprover = hrmis_find_department_approver($conn, (int)$row['UID'], (string)$row['Username']);
                    $sessionIdentity = [
                        'id' => (int)$row['UID'],
                        'username' => (string)$row['Username'],
                    ];
                    $userPayload = [
                        'id' => (int)$row['UID'],
                        'username' => (string)$row['Username'],
                        'userlevel' => (int)($row['Userlevel'] ?? 1),
                        'role' => $departmentApprover ? 'dept_approver' : 'admin',
                        'department' => $departmentApprover ? (string)$departmentApprover['department'] : null,
                        'dept_approver_role' => $departmentApprover ? (string)$departmentApprover['approver_role'] : null,
                        'piid' => null,
                        'id_number' => null,
                        'must_change_password' => false,
                    ];
                }
            }

            if (!$sessionIdentity) {
                $employeeAccount = auth_find_employee_account($conn, $username);
                if ($employeeAccount) {
                    if ((int)($employeeAccount['is_active'] ?? 0) !== 1) {
                        auth_fail('Account is inactive', 403);
                    }
                    if (!password_verify($password, (string)$employeeAccount['password_hash'])) {
                        auth_fail('Invalid username or password', 401);
                    }

                    auth_update_employee_last_login($conn, (int)$employeeAccount['id']);
                    $sessionIdentity = [
                        'id' => (int)$employeeAccount['id'],
                        'username' => (string)$employeeAccount['id_number'],
                    ];
                    $userPayload = [
                        'id' => (int)$employeeAccount['id'],
                        'username' => (string)$employeeAccount['id_number'],
                        'userlevel' => 3,
                        'role' => 'employee',
                        'piid' => (string)$employeeAccount['piid'],
                        'id_number' => (string)$employeeAccount['id_number'],
                        'must_change_password' => (bool)$employeeAccount['must_change_password'],
                        'email' => $employeeAccount['email'] ?? null,
                        'email_confirmed' => trim((string)($employeeAccount['email_confirmed_at'] ?? '')) !== '',
                    ];
                } else {
                    $person = auth_find_personal_by_id_number($conn, $username);
                    if (!$person || trim($password) !== trim((string)$person['ID_NUM'])) {
                        auth_fail('Invalid username or password', 401);
                    }

                    $employeeId = auth_create_employee_account(
                        $conn,
                        (string)$person['PIID'],
                        (string)$person['ID_NUM'],
                        password_hash((string)$person['ID_NUM'], PASSWORD_DEFAULT)
                    );
                    auth_update_employee_last_login($conn, $employeeId);

                    $sessionIdentity = [
                        'id' => $employeeId,
                        'username' => (string)$person['ID_NUM'],
                    ];
                    $userPayload = [
                        'id' => $employeeId,
                        'username' => (string)$person['ID_NUM'],
                        'userlevel' => 3,
                        'role' => 'employee',
                        'piid' => (string)$person['PIID'],
                        'id_number' => (string)$person['ID_NUM'],
                        'must_change_password' => true,
                        'email' => null,
                        'email_confirmed' => false,
                    ];
                }
            }

            if (!$sessionIdentity || !$userPayload) {
                auth_fail('Unable to resolve account', 500);
            }

            $session = storeUserSession($sessionIdentity['id'], $sessionIdentity['username']);
            if (!$session) {
                auth_fail('Could not create session. Please try again.', 500);
            }

            auth_warm_employee_leave($conn, $userPayload);

            auth_ok([
                'token' => $session['access_token'],
                'expiresIn' => $session['expires_in'],
                'user' => auth_user_payload($userPayload),
            ]);
        } catch (Throwable $e) {
            error_log('hrmis_auth login error: ' . $e->getMessage());
            auth_fail('Login failed: ' . $e->getMessage(), 500);
        }

    case 'change_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            auth_fail('POST required');
        }

        $user = validateToken();
        if (!$user) {
            auth_fail('Authentication required', 401);
        }
        if (($user['role'] ?? '') !== 'employee') {
            auth_fail('Only employee accounts can change password here', 403);
        }

        $current = trim((string)auth_body_value($body, 'current_password'));
        $newPass = trim((string)auth_body_value($body, 'new_password'));
        $confirm = trim((string)auth_body_value($body, 'confirm_password'));

        if ($current === '' || $newPass === '' || $confirm === '') {
            auth_fail('All password fields are required');
        }
        if (strlen($newPass) < 6) {
            auth_fail('New password must be at least 6 characters');
        }
        if ($newPass !== $confirm) {
            auth_fail('New passwords do not match');
        }

        $accountId = (int)($user['employee_account_id'] ?? $user['id'] ?? 0);
        $account = auth_find_employee_account_by_id($conn, $accountId);
        if (!$account || !password_verify($current, (string)$account['password_hash'])) {
            auth_fail('Current password is incorrect', 401);
        }

        auth_update_employee_password($conn, $accountId, password_hash($newPass, PASSWORD_DEFAULT));
        auth_ok(['message' => 'Password changed successfully']);

    case 'employee_contact':
        $user = validateToken();
        if (!$user) {
            auth_fail('Authentication required', 401);
        }
        if (($user['role'] ?? '') !== 'employee') {
            auth_fail('Employee access required', 403);
        }
        auth_ok(['data' => auth_employee_contact_payload($conn, $user)]);

    case 'save_employee_contact':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            auth_fail('POST required');
        }

        $user = validateToken();
        if (!$user) {
            auth_fail('Authentication required', 401);
        }
        if (($user['role'] ?? '') !== 'employee') {
            auth_fail('Employee access required', 403);
        }

        $email = trim((string)auth_body_value($body, 'email'));
        $source = trim((string)auth_body_value($body, 'source', 'employee'));
        auth_ok([
            'message' => 'Email address confirmed',
            'data' => auth_save_employee_contact($conn, $user, $email, $source),
        ]);

    case 'logout':
        $token = getBearerTokenFromRequest();
        if (!$token) {
            $token = $_COOKIE['auth_token'] ?? null;
        }

        if ($token) {
            deleteUserSession($token);
        }

        auth_ok(['message' => 'Logged out']);

    default:
        auth_fail('Unknown action', 404);
}
