<?php
/**
 * PDS Auth API
 * Actions: register | login | logout | me | change_password
 *
 * Uses pds_users + pds_sessions in cgmhris (via pds_db.php).
 * Login username = id_number (Employee ID Number).
 */

require_once __DIR__ . '/pds_db.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// JSON body support
$body = [];
if (in_array($method, ['POST', 'PUT'])) {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
}

function pb(string $key, $default = ''): mixed {
    global $body;
    return $body[$key] ?? $_POST[$key] ?? $default;
}

function pds_ok(array $data = []): never {
    echo json_encode(['status' => 'success'] + $data);
    exit;
}

function pds_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

// ── CORS (allow same-origin AJAX) ────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// ── Route ─────────────────────────────────────────────────────────────────
switch ($action) {

// ── REGISTER ─────────────────────────────────────────────────────────────
case 'register':
    if ($method !== 'POST') pds_err('Method not allowed', 405);

    $id_number  = trim((string)pb('id_number'));
    $bio_id     = trim((string)pb('bio_id'));
    $full_name  = trim((string)pb('full_name'));
    $email      = trim((string)pb('email'));
    $password   = (string)pb('password');
    $password2  = (string)pb('password_confirm');

    if (!$id_number)          pds_err('ID Number is required.');
    if (strlen($id_number) < 3) pds_err('ID Number too short.');
    if (!$full_name)          pds_err('Full name is required.');
    if (strlen($password) < 6) pds_err('Password must be at least 6 characters.');
    if ($password !== $password2) pds_err('Passwords do not match.');

    // Duplicate check
    $chk = $pds_conn->prepare("SELECT id FROM pds_users WHERE id_number = ?");
    $chk->execute([$id_number]);
    if ($chk->fetch()) pds_err('This ID Number is already registered. Please log in.');

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $ins = $pds_conn->prepare("
        INSERT INTO pds_users (id_number, bio_id, full_name, email, password_hash, must_change_password)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    $ins->execute([
        $id_number,
        $bio_id ?: null,
        $full_name,
        $email ?: null,
        $hash,
    ]);

    $user_id = (int)$pds_conn->lastInsertId();

    // Auto-link pds_personal_info if employee_no or bio_id match
    $link = $pds_conn->prepare("
        SELECT id FROM pds_personal_info
        WHERE (employee_no = ? AND ? <> '')
           OR (bio_id = ? AND ? <> '')
        LIMIT 1
    ");
    $link->execute([$id_number, $id_number, $bio_id, $bio_id]);
    if ($pds_row = $link->fetch()) {
        $pds_conn->prepare("UPDATE pds_users SET pds_id=?, employee_no=? WHERE id=?")
                 ->execute([$pds_row['id'], $id_number, $user_id]);
    } else {
        $pds_conn->prepare("UPDATE pds_users SET employee_no=? WHERE id=?")
                 ->execute([$id_number, $user_id]);
    }

    pds_ok(['message' => 'Registration successful. You can now log in.']);

// ── LOGIN ─────────────────────────────────────────────────────────────────
case 'login':
    if ($method !== 'POST') pds_err('Method not allowed', 405);

    $id_number = trim((string)pb('id_number'));
    $password  = (string)pb('password');

    if (!$id_number || !$password) pds_err('ID Number and password are required.');

    $stmt = $pds_conn->prepare("SELECT * FROM pds_users WHERE id_number = ? AND is_active = 1");
    $stmt->execute([$id_number]);
    $user = $stmt->fetch();

    $passwordValid = false;
    if ($user) {
        $hash = $user['password_hash'];
        $passwordValid = password_verify($password, $hash);
        if (!$passwordValid && md5($password) === $hash) {
            $passwordValid = true;
        }
        if (!$passwordValid && $hash === $password) {
            $passwordValid = true;
        }
    }
    if (!$passwordValid) {
        pds_err('Invalid ID Number or password.', 401);
    }

    // Update last login
    $pds_conn->prepare("UPDATE pds_users SET last_login_at = NOW() WHERE id = ?")
             ->execute([$user['id']]);

    // Try to auto-link pds_personal_info if not already linked
    if (!$user['pds_id']) {
        $link = $pds_conn->prepare("
            SELECT id FROM pds_personal_info
            WHERE employee_no = ? OR (bio_id = ? AND bio_id IS NOT NULL AND bio_id <> '')
            LIMIT 1
        ");
        $link->execute([$user['id_number'], $user['bio_id'] ?? '']);
        if ($pds_row = $link->fetch()) {
            $pds_conn->prepare("UPDATE pds_users SET pds_id=? WHERE id=?")
                     ->execute([$pds_row['id'], $user['id']]);
            $user['pds_id'] = $pds_row['id'];
        }
    }

    // Create session token
    $raw_token  = pds_token();
    $token_hash = password_hash($raw_token, PASSWORD_DEFAULT);
    $expires_at = date('Y-m-d H:i:s', time() + PDS_SESSION_TTL);
    $ip         = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua         = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Purge old sessions for this user
    $pds_conn->prepare("DELETE FROM pds_sessions WHERE user_id = ?")->execute([$user['id']]);

    $pds_conn->prepare("
        INSERT INTO pds_sessions (user_id, token_hash, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$user['id'], $token_hash, $expires_at, $ip, $ua]);

    pds_ok([
        'token'               => $raw_token,
        'expires_at'          => $expires_at,
        'must_change_password'=> (bool)$user['must_change_password'],
        'user' => [
            'id'          => (int)$user['id'],
            'id_number'   => $user['id_number'],
            'bio_id'      => $user['bio_id'],
            'employee_no' => $user['employee_no'] ?? $user['id_number'],
            'full_name'   => $user['full_name'],
            'role'        => $user['role'],
            'pds_id'      => $user['pds_id'] ? (int)$user['pds_id'] : null,
        ],
    ]);

// ── LOGOUT ───────────────────────────────────────────────────────────────
case 'logout':
    $u = pds_validate_token();
    if ($u) {
        // Delete sessions for this user — simple approach
        $pds_conn->prepare("DELETE FROM pds_sessions WHERE user_id = ?")->execute([$u['id']]);
    }
    pds_ok(['message' => 'Logged out.']);

// ── ME (current user info) ────────────────────────────────────────────────
case 'me':
    $u = pds_require_auth();
    pds_ok(['user' => $u]);

// ── CHANGE PASSWORD ───────────────────────────────────────────────────────
case 'change_password':
    if ($method !== 'POST') pds_err('Method not allowed', 405);
    $u = pds_require_auth();

    $current  = (string)pb('current_password');
    $new_pass = (string)pb('new_password');
    $confirm  = (string)pb('confirm_password');

    if (!$current || !$new_pass) pds_err('All password fields are required.');
    if (strlen($new_pass) < 6)   pds_err('New password must be at least 6 characters.');
    if ($new_pass !== $confirm)  pds_err('New passwords do not match.');

    $row = $pds_conn->prepare("SELECT password_hash FROM pds_users WHERE id = ?");
    $row->execute([$u['id']]);
    $user = $row->fetch();

    if (!$user || !password_verify($current, $user['password_hash'])) {
        pds_err('Current password is incorrect.', 401);
    }

    $pds_conn->prepare("
        UPDATE pds_users SET password_hash=?, must_change_password=0 WHERE id=?
    ")->execute([password_hash($new_pass, PASSWORD_DEFAULT), $u['id']]);

    pds_ok(['message' => 'Password changed successfully.']);

default:
    pds_err("Unknown action: $action", 404);
}
