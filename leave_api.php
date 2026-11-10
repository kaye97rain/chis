<?php
require_once __DIR__ . '/db_auth.php';
require_once __DIR__ . '/leave_db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$user = validateToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
    exit;
}

$actor = $user['username'] ?? 'HRMIS';
$role = $user['role'] ?? 'unknown';
$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_GET['action'] ?? '');

function leave_applications_enabled(): bool {
    $raw = getenv('LEAVE_APPLICATIONS_ENABLED');
    $value = strtolower(trim((string)($raw === false ? '1' : $raw)));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

$body = [];
if (in_array($method, ['POST', 'PUT'], true)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $body = json_decode($raw, true) ?? [];
    }
}

function ok($data = []): never {
    echo json_encode(['status' => 'success'] + $data);
    exit;
}

function fail($msg, $code = 400): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

$leaveApplicationActions = [
    'my_leave_data',
    'my_leave_applications',
    'apply_leave',
    'cancel_my_application',
    'applications',
    'application_detail',
    'dept_approve_application',
    'dept_reject_application',
    'approve_application',
    'reject_application',
];
if (!leave_applications_enabled() && in_array($action, $leaveApplicationActions, true)) {
    fail('Leave application module is temporarily unavailable.', 404);
}

function bp(string $key, $default = '') {
    global $body;
    return $body[$key] ?? $default;
}

function bpf(string $key): float {
    return (float)bp($key, 0);
}

function bpi(string $key): int {
    return (int)bp($key, 0);
}

function bp_upper(string $key, string $default = ''): string {
    return strtoupper(trim((string)bp($key, $default)));
}

function leave_email_notifications_enabled(): bool {
    $raw = getenv('LEAVE_EMAIL_NOTIFICATIONS_ENABLED');
    $value = strtolower(trim((string)($raw === false ? '1' : $raw)));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function leave_load_coict_smtp_env(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'COICT' . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($envPath)) {
        return;
    }

    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '' || !preg_match('/^[A-Z0-9_]+$/i', $key)) {
            continue;
        }

        if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
            continue;
        }

        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function leave_env_value(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false && array_key_exists($key, $_ENV)) {
        $value = $_ENV[$key];
    }
    $value = trim((string)($value === false ? $default : $value));
    if (
        strlen($value) >= 2 &&
        (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        )
    ) {
        $value = substr($value, 1, -1);
    }
    return $value;
}

function leave_mail_from(): string {
    $hrmisFrom = leave_env_value('HRMIS_MAIL_FROM');
    if ($hrmisFrom !== '' && strtolower($hrmisFrom) !== 'hrmis@localhost' && filter_var($hrmisFrom, FILTER_VALIDATE_EMAIL)) {
        return $hrmisFrom;
    }

    leave_load_coict_smtp_env();
    $smtpUser = leave_env_value('MAIL_USERNAME');
    if ($smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
        return $smtpUser;
    }

    return filter_var($hrmisFrom, FILTER_VALIDATE_EMAIL) ? $hrmisFrom : 'hrmis@localhost';
}

function leave_mail_from_name(): string {
    $name = leave_env_value('HRMIS_MAIL_FROM_NAME');
    if ($name === '') {
        leave_load_coict_smtp_env();
        $name = leave_env_value('MAIL_FROM_NAME');
    }
    return $name !== '' ? $name : 'Human Resource Information System (HRIS) - Leave Applications';
}

function leave_notification_log(string $message): void {
    error_log('[leave-email] ' . $message);
    @file_put_contents(
        __DIR__ . '/leave_email.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

function leave_employee_email(PDO $authConn, string $piid, string $idNumber = ''): string {
    $stmt = $authConn->prepare(
        "SELECT email
         FROM tbl_employee_accounts
         WHERE piid = :piid
            OR id_number = :id_number
         ORDER BY CASE WHEN email_confirmed_at IS NOT NULL THEN 0 ELSE 1 END,
                  email_updated_at DESC,
                  updated_at DESC
         LIMIT 1"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':id_number' => $idNumber,
    ]);
    $email = strtolower(trim((string)($stmt->fetchColumn() ?: '')));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    $stmt = $authConn->prepare(
        "SELECT Email
         FROM tblpersonalinformation
         WHERE PIID = :piid
            OR ID_NUM = :id_number
         LIMIT 1"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':id_number' => $idNumber,
    ]);
    $email = strtolower(trim((string)($stmt->fetchColumn() ?: '')));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function leave_notification_subject(string $event, array $app): string {
    $detail = match ($event) {
        'submitted' => 'Leave application submitted',
        'dept_approved' => 'Leave application endorsed by department',
        'hr_approved' => 'Leave application approved by HR',
        'cancelled' => 'Leave application cancelled',
        'dept_rejected' => 'Leave application rejected by department',
        'hr_rejected' => 'Leave application rejected by HR',
        default => 'Leave application update',
    };
    $leaveType = leave_clean_leave_type((string)($app['leave_type'] ?? ''));
    if ($leaveType !== '' && strcasecmp($leaveType, 'Leave') !== 0) {
        $detail .= ': ' . $leaveType;
    }
    return 'HRIS - City Government of Malaybalay - ' . $detail;
}

function leave_notification_intro(string $event, array $app): string {
    return match ($event) {
        'submitted' => 'Your leave application has been submitted and is now pending review.',
        'dept_approved' => 'Your leave application has been approved at the department level and is now pending HR review.',
        'hr_approved' => 'Your leave application has been approved by HR and posted to your leave ledger.',
        'cancelled' => 'Your leave application has been cancelled.',
        'dept_rejected' => 'Your leave application has been rejected at the department level.',
        'hr_rejected' => 'Your leave application has been rejected by HR.',
        default => 'There is an update to your leave application.',
    };
}

function leave_clean_leave_type(string $type): string {
    $type = trim($type);
    if ($type === '') {
        return 'Leave';
    }

    $parts = array_map('trim', explode('|', $type));
    $parts = array_values(array_filter($parts, static function ($part): bool {
        return $part !== '' && strcasecmp($part, '-N/A-') !== 0 && strcasecmp($part, 'N/A') !== 0;
    }));

    return !empty($parts) ? implode(' - ', $parts) : 'Leave';
}

function leave_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function leave_format_date_for_email(string $date): string {
    $date = trim($date);
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : $date;
}

function leave_application_period_text(array $app): string {
    $from = leave_format_date_for_email((string)($app['period_from'] ?? ''));
    $to = leave_format_date_for_email((string)($app['period_to'] ?? ''));
    if ($from === '' && $to === '') {
        return '-';
    }
    if ($to === '' || $to === $from) {
        return $from;
    }
    return $from . ' to ' . $to;
}

function leave_status_label(string $event, array $app): string {
    return match ($event) {
        'submitted' => 'Pending review',
        'dept_approved' => 'Endorsed by Department',
        'hr_approved' => 'Approved by HR',
        'cancelled' => 'Cancelled',
        'dept_rejected' => 'Rejected by Department',
        'hr_rejected' => 'Rejected by HR',
        default => trim((string)($app['status'] ?? 'Updated')),
    };
}

function leave_notification_note(array $app, string $event): array {
    $label = match ($event) {
        'cancelled' => 'Cancellation Reason',
        'dept_rejected' => 'Department Remarks',
        'hr_rejected' => 'HR Remarks',
        default => '',
    };
    if ($label === '') {
        return ['', ''];
    }

    $field = match ($event) {
        'cancelled' => 'cancellation_reason',
        'dept_rejected' => 'dept_remarks',
        'hr_rejected' => 'admin_remarks',
        default => '',
    };
    $note = trim((string)($app[$field] ?? ''));
    return $note !== '' ? [$label, $note] : ['', ''];
}

function leave_notification_plain_text(array $app, string $event): string {
    $lines = [
        'City Government of Malaybalay - CHRMO',
        'Human Resource Information System (HRIS) - Leave Applications',
        '',
        'Hello ' . trim((string)($app['employee_name'] ?? '')),
        '',
        leave_notification_intro($event, $app),
        '',
        'Leave Type: ' . leave_clean_leave_type((string)($app['leave_type'] ?? '')),
        'Inclusive Date(s): ' . leave_application_period_text($app),
        'Days Requested: ' . rtrim(rtrim(number_format((float)($app['days_requested'] ?? 0), 3), '0'), '.'),
        'Current Status: ' . leave_status_label($event, $app),
    ];

    [$noteLabel, $note] = leave_notification_note($app, $event);
    if ($note !== '') {
        $lines[] = $noteLabel . ': ' . $note;
    }

    $lines[] = '';
    $lines[] = 'This is an automated email from the City Government of Malaybalay - CHRMO.';

    return implode("\r\n", $lines);
}

function leave_notification_html(array $app, string $event): string {
    $employeeName = trim((string)($app['employee_name'] ?? 'Employee'));
    $leaveType = leave_clean_leave_type((string)($app['leave_type'] ?? ''));
    $period = leave_application_period_text($app);
    $days = rtrim(rtrim(number_format((float)($app['days_requested'] ?? 0), 3), '0'), '.');
    $status = leave_status_label($event, $app);
    $intro = leave_notification_intro($event, $app);
    [$noteLabel, $note] = leave_notification_note($app, $event);
    $noteRow = $note !== '' ? '
                <tr>
                  <td style="padding:16px 18px;background:#f8fbff;border-top:1px solid #dbe6f3;font-size:12px;font-weight:700;color:#5b6f8f;text-transform:uppercase;letter-spacing:.05em;">' . leave_h($noteLabel) . '</td>
                  <td style="padding:16px 18px;border-top:1px solid #dbe6f3;font-size:15px;color:#0d2b55;">' . leave_h($note) . '</td>
                </tr>' : '';

    return '<!doctype html>
<html>
<body style="margin:0;padding:0;background:#eef4fb;font-family:Arial,Helvetica,sans-serif;color:#0f2747;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef4fb;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #d8e4f2;">
          <tr>
            <td style="background:#123967;padding:24px 28px;color:#ffffff;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="width:112px;vertical-align:middle;">
                    <img src="cid:city_logo" width="48" height="48" alt="City Government of Malaybalay" style="display:inline-block;border-radius:50%;margin-right:8px;vertical-align:middle;">
                    <img src="cid:chrmo_logo" width="48" height="48" alt="CHRMO HRMIS" style="display:inline-block;border-radius:50%;vertical-align:middle;">
                  </td>
                  <td style="vertical-align:middle;">
                    <div style="font-size:18px;font-weight:700;line-height:1.3;">City Government of Malaybalay - CHRMO</div>
                    <div style="font-size:13px;opacity:.86;margin-top:3px;">Human Resource Information System (HRIS) - Leave Applications</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:30px 30px 12px;">
              <div style="display:inline-block;background:#e7f0ff;color:#1f5aa6;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">' . leave_h($status) . '</div>
              <h1 style="font-size:24px;line-height:1.25;margin:18px 0 8px;color:#0d2b55;">Leave Application Update</h1>
              <p style="font-size:15px;line-height:1.6;margin:0;color:#405674;">Hello ' . leave_h($employeeName) . ', ' . leave_h($intro) . '</p>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 30px 28px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;border:1px solid #dbe6f3;border-radius:14px;overflow:hidden;">
                <tr>
                  <td style="padding:16px 18px;background:#f8fbff;border-bottom:1px solid #dbe6f3;font-size:12px;font-weight:700;color:#5b6f8f;text-transform:uppercase;letter-spacing:.05em;">Leave Type</td>
                  <td style="padding:16px 18px;border-bottom:1px solid #dbe6f3;font-size:15px;font-weight:700;color:#0d2b55;">' . leave_h($leaveType) . '</td>
                </tr>
                <tr>
                  <td style="padding:16px 18px;background:#f8fbff;border-bottom:1px solid #dbe6f3;font-size:12px;font-weight:700;color:#5b6f8f;text-transform:uppercase;letter-spacing:.05em;">Inclusive Date(s)</td>
                  <td style="padding:16px 18px;border-bottom:1px solid #dbe6f3;font-size:15px;color:#0d2b55;">' . leave_h($period) . '</td>
                </tr>
                <tr>
                  <td style="padding:16px 18px;background:#f8fbff;border-bottom:1px solid #dbe6f3;font-size:12px;font-weight:700;color:#5b6f8f;text-transform:uppercase;letter-spacing:.05em;">No. of Day(s)</td>
                  <td style="padding:16px 18px;border-bottom:1px solid #dbe6f3;font-size:15px;color:#0d2b55;">' . leave_h($days) . '</td>
                </tr>
                <tr>
                  <td style="padding:16px 18px;background:#f8fbff;font-size:12px;font-weight:700;color:#5b6f8f;text-transform:uppercase;letter-spacing:.05em;">Current Status</td>
                  <td style="padding:16px 18px;font-size:15px;font-weight:700;color:#0d2b55;">' . leave_h($status) . '</td>
                </tr>
                ' . $noteRow . '
              </table>
              <p style="font-size:13px;line-height:1.6;margin:20px 0 0;color:#6b7b92;">This is an automated email from the City Government of Malaybalay - CHRMO. Please do not reply to this message.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

function leave_load_phpmailer(): bool {
    if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        return true;
    }

    $localAutoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_readable($localAutoload)) {
        require_once $localAutoload;
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            return true;
        }
    }

    $localSrc = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'phpmailer' . DIRECTORY_SEPARATOR . 'phpmailer' . DIRECTORY_SEPARATOR . 'src';
    $localFiles = [
        $localSrc . DIRECTORY_SEPARATOR . 'Exception.php',
        $localSrc . DIRECTORY_SEPARATOR . 'SMTP.php',
        $localSrc . DIRECTORY_SEPARATOR . 'PHPMailer.php',
    ];
    if (is_readable($localFiles[0]) && is_readable($localFiles[1]) && is_readable($localFiles[2])) {
        require_once $localFiles[0];
        require_once $localFiles[1];
        require_once $localFiles[2];
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            return true;
        }
    }

    $coictAutoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'COICT' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_readable($coictAutoload)) {
        require_once $coictAutoload;
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            return true;
        }
    }

    return false;
}

function leave_logo_paths(): array {
    $imageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'image';
    return [
        'city_logo' => $imageDir . DIRECTORY_SEPARATOR . 'citylogo.png',
        'chrmo_logo' => $imageDir . DIRECTORY_SEPARATOR . 'hr.png',
    ];
}

function leave_send_smtp_email(string $to, string $subject, string $body, string $from, string $fromName, string $htmlBody = ''): bool {
    leave_load_coict_smtp_env();
    if (!leave_load_phpmailer()) {
        leave_notification_log('PHPMailer autoload not found; falling back to PHP mail().');
        return false;
    }

    $host = leave_env_value('MAIL_HOST', 'smtp.gmail.com');
    $username = leave_env_value('MAIL_USERNAME');
    $password = leave_env_value('MAIL_PASSWORD');
    $port = (int)leave_env_value('MAIL_PORT', '587');
    if ($username === '' || $password === '') {
        leave_notification_log('SMTP username/password not configured; falling back to PHP mail().');
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = $port > 0 ? $port : 587;
        $mail->CharSet = 'UTF-8';

        $encryption = strtolower(leave_env_value('MAIL_ENCRYPTION', $mail->Port === 465 ? 'smtps' : 'tls'));
        if (in_array($encryption, ['ssl', 'smtps'], true)) {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($encryption, ['tls', 'starttls'], true)) {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
        }

        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        foreach (leave_logo_paths() as $cid => $path) {
            if (is_readable($path)) {
                $mail->addEmbeddedImage($path, $cid, basename($path));
            }
        }
        $mail->isHTML($htmlBody !== '');
        $mail->Subject = $subject;
        $mail->Body = $htmlBody !== '' ? $htmlBody : $body;
        $mail->AltBody = $body;
        $mail->send();
        return true;
    } catch (Throwable $e) {
        leave_notification_log('SMTP send failed: ' . $e->getMessage());
        return false;
    }
}

function leave_send_email(string $to, string $subject, string $body, string $htmlBody = ''): bool {
    $from = leave_mail_from();
    $fromName = leave_mail_from_name();
    $headers = [
        'From: ' . $fromName . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: ' . ($htmlBody !== '' ? 'text/html' : 'text/plain') . '; charset=UTF-8',
    ];

    $dryRun = strtolower(trim((string)(getenv('HRMIS_MAIL_DRY_RUN') ?: '0')));
    if (in_array($dryRun, ['1', 'true', 'yes', 'on'], true)) {
        leave_notification_log('DRY RUN to=' . $to . ' subject=' . $subject . ' body=' . str_replace(["\r", "\n"], ' ', $body));
        return true;
    }

    if (leave_send_smtp_email($to, $subject, $body, $from, $fromName, $htmlBody)) {
        leave_notification_log('Sent via SMTP to=' . $to . ' subject=' . $subject);
        return true;
    }

    $sent = @mail($to, $subject, $htmlBody !== '' ? $htmlBody : $body, implode("\r\n", $headers));
    if ($sent) {
        leave_notification_log('Sent via PHP mail() to=' . $to . ' subject=' . $subject);
    }
    return $sent;
}

function leave_notify_employee(PDO $authConn, array $app, string $event): void {
    if (!leave_email_notifications_enabled()) {
        return;
    }

    $piid = (string)($app['piid'] ?? '');
    $idNumber = (string)($app['id_number'] ?? '');
    $to = leave_employee_email($authConn, $piid, $idNumber);
    if ($to === '') {
        leave_notification_log('No email found for leave application id=' . (string)($app['id'] ?? '') . ' piid=' . $piid);
        return;
    }

    $subject = leave_notification_subject($event, $app);
    $body = leave_notification_plain_text($app, $event);
    $htmlBody = leave_notification_html($app, $event);
    if (!leave_send_email($to, $subject, $body, $htmlBody)) {
        leave_notification_log('Failed to send to=' . $to . ' app_id=' . (string)($app['id'] ?? '') . ' event=' . $event);
    }
}

function current_actor_fullname(PDO $conn, array $user): string {
    $fallback = trim((string)($user['username'] ?? 'HRMIS'));
    $resolvedPiid = null;

    $sysUserStmt = $conn->prepare(
        "SELECT fkPDS
         FROM tblsysuser
         WHERE UID = :uid OR Username = :username
         LIMIT 1"
    );
    $sysUserStmt->execute([
        ':uid' => (string)($user['id'] ?? ''),
        ':username' => (string)($user['username'] ?? ''),
    ]);
    $sysUserRow = $sysUserStmt->fetch(PDO::FETCH_ASSOC);
    if ($sysUserRow && !empty($sysUserRow['fkPDS'])) {
        $resolvedPiid = (string)$sysUserRow['fkPDS'];
    }

    $lookupStmt = $conn->prepare(
        "SELECT SurName, FirstName, MiddleName
         FROM tblpersonalinformation
         WHERE PIID = :resolved_piid
            OR PIID = :raw_piid
            OR ID_NUM = :id_num
         ORDER BY CASE
            WHEN PIID = :resolved_piid_order THEN 1
            WHEN PIID = :raw_piid_order THEN 2
            WHEN ID_NUM = :id_num_order THEN 3
            ELSE 4
         END
         LIMIT 1"
    );
    $lookupStmt->execute([
        ':resolved_piid' => $resolvedPiid ?? '',
        ':raw_piid' => (string)($user['id'] ?? ''),
        ':id_num' => (string)($user['username'] ?? ''),
        ':resolved_piid_order' => $resolvedPiid ?? '',
        ':raw_piid_order' => (string)($user['id'] ?? ''),
        ':id_num_order' => (string)($user['username'] ?? ''),
    ]);
    $profileRow = $lookupStmt->fetch(PDO::FETCH_ASSOC);
    if (!$profileRow) {
        return $fallback !== '' ? $fallback : 'HRMIS';
    }

    $fullName = trim(
        (string)($profileRow['FirstName'] ?? '') . ' ' .
        (!empty($profileRow['MiddleName']) ? strtoupper(substr((string)$profileRow['MiddleName'], 0, 1)) . '. ' : '') .
        (string)($profileRow['SurName'] ?? '')
    );

    return $fullName !== '' ? $fullName : ($fallback !== '' ? $fallback : 'HRMIS');
}

function require_admin_role(): void {
    global $role;
    if ($role !== 'admin') {
        fail('Administrator access required', 403);
    }
}

function require_rain_user(): void {
    global $user;
    if (strtolower(trim((string)($user['username'] ?? ''))) !== 'rain') {
        fail('rain access required', 403);
    }
}

function require_leave_reviewer_role(): void {
    global $role;
    if (!in_array($role, ['admin', 'dept_approver'], true)) {
        fail('Leave reviewer access required', 403);
    }
}

function current_department_scope(array $user): string {
    $dept = trim((string)($user['department'] ?? ''));
    if ($dept === '') {
        fail('Department approver account is not linked to a department', 403);
    }
    return $dept;
}

function require_employee_role(): void {
    global $role;
    if ($role !== 'employee') {
        fail('Employee access required', 403);
    }
}

function current_employee_piid(array $user): string {
    $piid = trim((string)($user['piid'] ?? ''));
    if ($piid === '') {
        fail('Employee profile is not linked to a leave record', 403);
    }
    return $piid;
}

function employee_is_special_leave_type(string $type): bool {
    $main = strtolower(lv_main_type($type));
    return in_array($main, ['others, (specify)', 'others (specify)', 'special privilege leave'], true);
}

function employee_leave_counters(PDO $conn, string $piid, int $year, array $balance, array $records): array {
    $wellnessUsed = lv_wellness_days_used($conn, $piid, $year);
    $specialStartingBalance = (float)($balance['NoAvailSL'] ?? _LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE);
    if ($specialStartingBalance <= 0 && !lv_special_leave_manual_override($balance)) {
        $specialStartingBalance = _LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE;
    }
    $specialUsed = lv_special_leave_days_used($conn, $piid, $year);
    $specialPending = employee_pending_application_days($conn, $piid, $year, 'employee_is_special_leave_type');

    return [
        'available_mandatory_forced_leave' => (float)lv_normalize_mandatory_forced_leave($balance['NoAvailVL'] ?? null),
        'available_special_leave' => round(max(0, $specialStartingBalance - $specialUsed - $specialPending), 4),
        'remaining_wellness_leave' => round(max(0, 5 - $wellnessUsed), 4),
        'used_special_leave' => $specialUsed,
        'pending_special_leave' => $specialPending,
        'used_wellness_leave' => $wellnessUsed,
    ];
}

function employee_report_snapshot(PDO $conn, string $piid, int $year, int $month): array {
    $employee = lv_get_employee($conn, $piid);
    $dept = trim((string)($employee['Department'] ?? ''));
    $rows = lv_report_summary($conn, $year, 1, $month, $dept, false);

    foreach ($rows as $row) {
        if ((string)($row['PIID'] ?? '') === $piid) {
            return $row;
        }
    }

    return [];
}

function employee_pending_application_days(PDO $conn, string $piid, int $year, callable $matches): float {
    $days = 0.0;
    foreach (lv_get_employee_leave_applications($conn, $piid) as $app) {
        if ((string)($app['status'] ?? '') !== 'Pending') {
            continue;
        }
        $from = trim((string)($app['period_from'] ?? ''));
        if ($from === '' || (int)date('Y', strtotime($from)) !== $year) {
            continue;
        }
        if ($matches((string)($app['leave_type'] ?? ''))) {
            $days += (float)($app['days_requested'] ?? 0);
        }
    }
    return round($days, 4);
}

function employee_record_days(PDO $conn, string $piid, int $year, callable $matches): float {
    $days = 0.0;
    foreach (lv_get_records($conn, $piid, $year) as $row) {
        if ($matches((string)($row['Type_of_Records'] ?? ''))) {
            $days += lv_record_leave_days($row);
        }
    }
    return round($days, 4);
}

function employee_auto_particulars(
    string $type,
    float $days,
    string $vacationLocation = '',
    string $vacationAbroadDetails = '',
    string $sickLeaveDetail = '',
    string $sickIllness = '',
    string $studyLeaveDetail = '',
    string $studyPurpose = ''
): string {
    $parts = explode('|', $type, 2);
    $main = trim((string)($parts[0] ?? $type));
    $other = trim((string)($parts[1] ?? '-N/A-'));
    $daysText = rtrim(rtrim(number_format($days, 3, '.', ''), '0'), '.');
    $dayWord = abs($days - 1.0) < 0.0001 ? 'DAY' : 'DAYS';

    if (strcasecmp($main, 'Others, (Specify)') === 0 && $other !== '' && strcasecmp($other, '-N/A-') !== 0) {
        return strtoupper($daysText . ' ' . $dayWord . ' SPECIAL LEAVE (' . $other . ')');
    }

    if (strcasecmp($main, 'Vacation Leave') === 0 && strcasecmp(trim($vacationLocation), 'Abroad') === 0) {
        $details = trim($vacationAbroadDetails);
        return strtoupper($daysText . ' ' . $dayWord . ' ' . $main . ' - ABROAD' . ($details !== '' ? ' (' . $details . ')' : ''));
    }
    if (strcasecmp($main, 'Sick Leave') === 0) {
        $detail = trim($sickLeaveDetail);
        $illness = trim($sickIllness);
        return strtoupper($daysText . ' ' . $dayWord . ' ' . $main . ($detail !== '' ? ' - ' . $detail : '') . ($illness !== '' ? ' (' . $illness . ')' : ''));
    }
    if (strcasecmp($main, 'Study Leave') === 0) {
        $detail = trim($studyLeaveDetail);
        $purpose = trim($studyPurpose);
        return strtoupper($daysText . ' ' . $dayWord . ' ' . $main . ($detail !== '' ? ' - ' . $detail : '') . ($purpose !== '' ? ' (' . $purpose . ')' : ''));
    }

    return strtoupper($daysText . ' ' . $dayWord . ' ' . $main);
}

function ph_easter_date(int $year): DateTimeImmutable {
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

function ph_last_monday(int $year, int $month): string {
    $date = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $date = $date->modify('last day of this month');
    while ($date->format('N') !== '1') {
        $date = $date->modify('-1 day');
    }
    return $date->format('Y-m-d');
}

function ph_holiday_dates(int $year): array {
    $easter = ph_easter_date($year);
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
        ph_last_monday($year, 8),
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

function ph_is_working_day(DateTimeImmutable $date): bool {
    $weekday = (int)$date->format('N');
    if ($weekday >= 6) {
        return false;
    }
    $holidays = ph_holiday_dates((int)$date->format('Y'));
    return !isset($holidays[$date->format('Y-m-d')]);
}

function ph_working_days_before(string $periodFrom): int {
    $today = new DateTimeImmutable(date('Y-m-d'));
    $start = new DateTimeImmutable($periodFrom);
    $count = 0;
    for ($date = $today->modify('+1 day'); $date < $start; $date = $date->modify('+1 day')) {
        if (ph_is_working_day($date)) {
            $count++;
        }
    }
    return $count;
}

function validate_employee_leave_request(PDO $conn, string $piid, string $type, string $periodFrom, string $periodTo, float $daysRequested, float $monetizationVlDays = 0.0, float $monetizationSlDays = 0.0): void {
    $main = strtolower(lv_main_type($type));
    $year = (int)date('Y', strtotime($periodFrom));
    $blocked = [
        'leave earned',
        'balance forwarded',
        'undertime',
        'undertime (subject to vacation leave)',
        'audit action and findings',
        'special leave vacation leave',
        'unused force vacation leave',
    ];
    if (in_array($main, $blocked, true)) {
        fail('This leave type is for HR use only.');
    }

    if (in_array($main, ['vacation leave', 'vacation leave w/o pay'], true)) {
        try {
            $daysBeforeStart = ph_working_days_before($periodFrom);
        } catch (Throwable $e) {
            fail('Invalid vacation leave date.');
        }
        if ($daysBeforeStart < 5) {
            fail('Vacation Leave must be filed at least 5 working days before the leave start date. Weekends and Philippine non-working holidays are not counted.');
        }
    }

    $snapshot = employee_report_snapshot($conn, $piid, $year, (int)date('n'));
    $balance = lv_get_balance($conn, $piid, $year) ?? [];

    if ($main === 'vacation leave') {
        $available = (float)($snapshot['vac_balance'] ?? 0);
        if ($daysRequested > $available) {
            fail('Vacation Leave exceeds your available balance: ' . number_format($available, 3));
        }
    }
    if ($main === 'sick leave') {
        $available = (float)($snapshot['sick_balance'] ?? 0);
        if ($daysRequested > $available) {
            fail('Sick Leave exceeds your available balance: ' . number_format($available, 3));
        }
    }
    if ($main === 'monetization of leave credits') {
        $availableVl = (float)($snapshot['vac_balance'] ?? 0);
        $availableSl = (float)($snapshot['sick_balance'] ?? 0);
        $splitTotal = round($monetizationVlDays + $monetizationSlDays, 3);
        if ($monetizationVlDays < 0 || $monetizationSlDays < 0) {
            fail('Monetization days cannot be negative.');
        }
        if ($splitTotal <= 0) {
            fail('Please enter the VL or SL days to monetize.');
        }
        if (abs($splitTotal - round($daysRequested, 3)) > 0.001) {
            fail('Monetization VL and SL days must match the total days requested.');
        }
        if ($monetizationVlDays > $availableVl) {
            fail('VL monetization exceeds available Vacation Leave: ' . number_format($availableVl, 3));
        }
        if ($monetizationSlDays > $availableSl) {
            fail('SL monetization exceeds available Sick Leave: ' . number_format($availableSl, 3));
        }
    }

    if ($main === 'mandatory/forced leave') {
        $saved = lv_normalize_mandatory_forced_leave($balance['NoAvailVL'] ?? null);
        $pending = employee_pending_application_days($conn, $piid, $year, fn(string $t): bool => strtolower(lv_main_type($t)) === 'mandatory/forced leave');
        $remaining = max(0, $saved - $pending);
        if ($daysRequested > $remaining) {
            fail('Mandatory/Forced Leave exceeds your available balance: ' . number_format($remaining, 3));
        }
    }

    if ($main === 'wellness leave') {
        $used = lv_wellness_days_used($conn, $piid, $year);
        $pending = employee_pending_application_days($conn, $piid, $year, fn(string $t): bool => strtolower(lv_main_type($t)) === 'wellness leave');
        $remaining = max(0, 5 - $used - $pending);
        if ($daysRequested > $remaining) {
            fail('Wellness Leave exceeds the 5-day annual cap. Remaining balance: ' . number_format($remaining, 3));
        }
    }

    if ($main === 'others, (specify)' || $main === 'others (specify)') {
        $used = employee_record_days($conn, $piid, $year, function (string $t): bool {
            $m = strtolower(lv_main_type($t));
            return $m === 'others, (specify)' || $m === 'others (specify)';
        });
        $pending = employee_pending_application_days($conn, $piid, $year, function (string $t): bool {
            $m = strtolower(lv_main_type($t));
            return $m === 'others, (specify)' || $m === 'others (specify)';
        });
        $remaining = max(0, 3 - $used - $pending);
        if ($daysRequested > $remaining) {
            fail('Special Leave exceeds the 3-day annual cap. Remaining balance: ' . number_format($remaining, 3));
        }
    }

    $annualCaps = [
        'paternity leave' => 7,
        'solo parent leave' => 7,
        '10-day vawc leave' => 10,
        'special emergency (calamity) leave' => 5,
        'adoption leave' => 60,
        'maternity leave' => 105,
    ];
    if (isset($annualCaps[$main])) {
        $cap = (float)$annualCaps[$main];
        $used = employee_record_days($conn, $piid, $year, fn(string $t): bool => strtolower(lv_main_type($t)) === $main);
        $pending = employee_pending_application_days($conn, $piid, $year, fn(string $t): bool => strtolower(lv_main_type($t)) === $main);
        $remaining = max(0, $cap - $used - $pending);
        if ($daysRequested > $remaining) {
            fail(lv_main_type($type) . ' exceeds the annual cap. Remaining balance: ' . number_format($remaining, 3));
        }
    }
}

switch ($action) {
    case 'my_leave_card':
        require_employee_role();
        $piid = current_employee_piid($user);
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = max(1, min(12, (int)($_GET['month_to'] ?? date('n'))));
        lv_recalculate_stale_for_employees($leave_conn, [$piid], $year, 'employee-portal');
        $balance = lv_get_balance($leave_conn, $piid, $year) ?? [
            'piid' => $piid,
            'year' => $year,
            'cBackVaca' => 0,
            'cBalSick' => 0,
            'NoAvailVL' => 5,
            'NoAvailSL' => _LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE,
            'MonetaryVL' => 0,
            'MonetarySL' => 0,
            'sourceYear' => $year - 1,
            'isAutoDerived' => 0,
        ];
        $records = lv_get_records($leave_conn, $piid, $year);
        $fallbackCounters = employee_leave_counters($leave_conn, $piid, $year, $balance, $records);
        $employee = lv_get_employee($leave_conn, $piid);
        $department = trim((string)($employee['Department'] ?? ''));
        $latestRecord = $records ? $records[count($records) - 1] : null;
        ok([
            'employee' => $employee,
            'balance' => $balance,
            'report_balance' => [
                'year' => $year,
                'month_to' => $month,
                'vac_balance' => (float)($latestRecord['VacBal'] ?? ($balance['cBackVaca'] ?? 0)),
                'sick_balance' => (float)($latestRecord['SickBal'] ?? ($balance['cBalSick'] ?? 0)),
            ],
            'leave_counters' => [
                'available_mandatory_forced_leave' => (float)$fallbackCounters['available_mandatory_forced_leave'],
                'available_special_leave' => (float)$fallbackCounters['available_special_leave'],
                'remaining_wellness_leave' => (float)$fallbackCounters['remaining_wellness_leave'],
            ],
            'records' => $records,
            'applications' => lv_get_employee_leave_applications($leave_conn, $piid),
            'department_calendar' => $department !== '' ? lv_search_leave_applications($leave_conn, [
                'department' => $department,
                'period_from' => sprintf('%04d-01-01', $year),
                'period_to' => sprintf('%04d-12-31', $year),
            ]) : [],
        ]);

    case 'my_leave_records':
        require_employee_role();
        $piid = current_employee_piid($user);
        $year = (int)($_GET['year'] ?? date('Y'));
        lv_recalculate_stale_for_employees($leave_conn, [$piid], $year, 'employee-records');
        ok(['data' => lv_get_records($leave_conn, $piid, $year)]);

    case 'my_leave_applications':
        require_employee_role();
        $piid = current_employee_piid($user);
        ok(['data' => lv_get_employee_leave_applications($leave_conn, $piid, trim($_GET['status'] ?? ''))]);

    case 'apply_leave':
        require_employee_role();
        if ($method !== 'POST') fail('POST required');
        $piid = current_employee_piid($user);
        $type = trim((string)bp('leave_type'));
        $dateFiled = trim((string)bp('date_filed', date('Y-m-d')));
        if ($dateFiled === '') {
            $dateFiled = date('Y-m-d');
        }
        $periodFrom = trim((string)bp('period_from'));
        $periodTo = trim((string)bp('period_to'));
        $halfDayMode = trim((string)bp('half_day_mode'));
        if ($halfDayMode !== '' && !in_array(strtolower($halfDayMode), ['full', 'am', 'pm'], true)) {
            fail('half_day_mode must be full, am, or pm');
        }
        $mainType = lv_main_type($type);
        $isMonetization = strcasecmp($mainType, 'Monetization of Leave Credits') === 0;
        $monetizationVlDays = $isMonetization ? bpf('monetization_vl_days') : 0.0;
        $monetizationSlDays = $isMonetization ? bpf('monetization_sl_days') : 0.0;
        $monetizationAmount = $isMonetization ? bpf('monetization_amount') : 0.0;
        if ($isMonetization) {
            $periodFrom = $periodFrom !== '' ? $periodFrom : $dateFiled;
            $periodTo = $periodTo !== '' ? $periodTo : $periodFrom;
        }
        if ($type === '' || (!$isMonetization && ($periodFrom === '' || $periodTo === ''))) {
            fail('leave_type, period_from, and period_to are required');
        }
        if (!$isMonetization) {
            $periodFromTs = strtotime($periodFrom . ' 00:00:00');
            $periodToTs = strtotime($periodTo . ' 00:00:00');
            if ($periodFromTs === false || $periodToTs === false) {
                fail('Please choose valid leave dates.');
            }
            if ($periodToTs < $periodFromTs) {
                fail('To date cannot be earlier than the selected From date.');
            }
        }
        if ($isMonetization) {
            $daysRequested = (float)bp('days_requested');
        } else {
            $daysRequested = lv_calculate_days_requested($type, $periodFrom, $periodTo);
            if (strtolower($halfDayMode) !== 'full' && $halfDayMode !== '') {
                if ($daysRequested <= 1.0 && !lv_is_compensatory_type($type)) {
                    fail('Half-day leave must be part of a leave request longer than 1 day, such as 1.5 or 2.5 days.');
                }
                $daysRequested -= 0.5;
            }
        }
        if ($daysRequested <= 0) {
            fail($isMonetization ? 'Please enter the number of days to monetize.' : 'Unable to determine requested leave days');
        }
        validate_employee_leave_request($leave_conn, $piid, $type, $periodFrom, $periodTo, $daysRequested, $monetizationVlDays, $monetizationSlDays);
        $vacationLocation = trim((string)bp('vacation_location'));
        $vacationAbroadDetails = trim((string)bp('vacation_abroad_details'));
        $sickLeaveDetail = trim((string)bp('sick_leave_detail'));
        $sickIllness = trim((string)bp('sick_illness'));
        $studyLeaveDetail = trim((string)bp('study_leave_detail'));
        $studyPurpose = trim((string)bp('study_purpose'));
        $monetizationPurpose = trim((string)bp('monetization_purpose'));
        if (
            strcasecmp($mainType, 'Vacation Leave') === 0 &&
            strcasecmp($vacationLocation, 'Abroad') === 0 &&
            $vacationAbroadDetails === ''
        ) {
            fail('Please specify the country/place for vacation leave abroad.');
        }
        if (
            strcasecmp($mainType, 'Sick Leave') === 0 &&
            in_array($sickLeaveDetail, ['In Hospital', 'Out Patient'], true) &&
            $sickIllness === ''
        ) {
            fail('Please specify the illness for sick leave.');
        }
        if (
            strcasecmp($mainType, 'Study Leave') === 0 &&
            in_array($studyLeaveDetail, ['BAR/Board Examination Review', 'Other'], true) &&
            $studyPurpose === ''
        ) {
            fail('Please specify the study leave purpose.');
        }
        if (strcasecmp($mainType, 'Monetization of Leave Credits') === 0 && $monetizationPurpose === '') {
            fail('Please specify the purpose for monetization of leave credits.');
        }
        if ($isMonetization && $monetizationAmount <= 0) {
            fail('Please enter the monetization amount provided by HR.');
        }
        try {
            $id = lv_create_leave_application($leave_conn, $piid, [
                'leave_type' => $type,
                'type_other' => trim((string)bp('type_other')),
                'date_filed' => $dateFiled,
                'date_inputted' => trim((string)bp('date_inputted')),
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'half_day_mode' => $halfDayMode,
                'half_day_window' => trim((string)bp('half_day_window')),
                'days_requested' => $daysRequested,
                'vacation_location' => $vacationLocation,
                'vacation_abroad_details' => $vacationAbroadDetails,
                'sick_leave_detail' => $sickLeaveDetail,
                'sick_illness' => $sickIllness,
                'study_leave_detail' => $studyLeaveDetail,
                'study_purpose' => $studyPurpose,
                'monetization_purpose' => $monetizationPurpose,
                'monetization_vl_days' => $monetizationVlDays,
                'monetization_sl_days' => $monetizationSlDays,
                'monetization_amount' => $monetizationAmount,
                'particulars' => employee_auto_particulars(
                    $type,
                    $daysRequested,
                    $vacationLocation,
                    $vacationAbroadDetails,
                    $sickLeaveDetail,
                    $sickIllness,
                    $studyLeaveDetail,
                    $studyPurpose
                ),
                'reason' => trim((string)bp('reason')),
            ]);
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 404);
        }
        $application = lv_get_leave_application($leave_conn, $id);
        if ($application) {
            leave_notify_employee($conn, $application, 'submitted');
        }
        ok(['id' => $id, 'application' => $application]);

    case 'cancel_my_application':
        require_employee_role();
        if ($method !== 'POST') fail('POST required');
        $piid = current_employee_piid($user);
        $id = bpi('id');
        $reason = trim((string)bp('reason'));
        if ($id <= 0) fail('id required');
        if ($reason === '') fail('Cancellation reason is required');
        try {
            lv_cancel_leave_application($leave_conn, $id, $piid, $reason);
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 404);
        }
        $application = lv_get_leave_application($leave_conn, $id);
        if ($application) {
            leave_notify_employee($conn, $application, 'cancelled');
        }
        ok(['message' => 'Leave application cancelled']);

    case 'applications':
        require_leave_reviewer_role();
        $filters = [
            'status' => trim($_GET['status'] ?? ''),
            'department' => trim($_GET['department'] ?? ''),
            'dept_status' => trim($_GET['dept_status'] ?? ''),
            'q' => trim($_GET['q'] ?? ''),
        ];
        if ($role === 'dept_approver') {
            $filters['department'] = current_department_scope($user);
        }
        ok(['data' => lv_search_leave_applications($leave_conn, [
            'status' => $filters['status'],
            'department' => $filters['department'],
            'dept_status' => $filters['dept_status'],
            'q' => $filters['q'],
        ])]);

    case 'application_detail':
        require_leave_reviewer_role();
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) fail('id required');
        $application = lv_get_leave_application($leave_conn, $id);
        if (!$application) fail('Leave application not found', 404);
        if ($role === 'dept_approver' && (string)($application['department'] ?? '') !== current_department_scope($user)) {
            fail('Leave application not found', 404);
        }
        ok(['data' => $application]);

    case 'dept_approve_application':
        require_leave_reviewer_role();
        if ($role !== 'dept_approver') fail('Department approver access required', 403);
        if ($method !== 'POST') fail('POST required');
        $id = bpi('id');
        if ($id <= 0) fail('id required');
        try {
            lv_department_approve_leave_application(
                $leave_conn,
                $id,
                current_department_scope($user),
                current_actor_fullname($leave_conn, $user),
                trim((string)bp('dept_remarks'))
            );
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 400);
        }
        $application = lv_get_leave_application($leave_conn, $id);
        if ($application) {
            leave_notify_employee($conn, $application, 'dept_approved');
        }
        ok(['message' => 'Leave application approved at department level']);

    case 'dept_reject_application':
        require_leave_reviewer_role();
        if ($role !== 'dept_approver') fail('Department approver access required', 403);
        if ($method !== 'POST') fail('POST required');
        $id = bpi('id');
        if ($id <= 0) fail('id required');
        $remarks = trim((string)bp('dept_remarks'));
        if ($remarks === '') fail('Rejection remarks are required');
        try {
            lv_department_reject_leave_application(
                $leave_conn,
                $id,
                current_department_scope($user),
                current_actor_fullname($leave_conn, $user),
                $remarks
            );
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 400);
        }
        $application = lv_get_leave_application($leave_conn, $id);
        if ($application) {
            leave_notify_employee($conn, $application, 'dept_rejected');
        }
        ok(['message' => 'Leave application rejected at department level']);

    case 'approve_application':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $id = bpi('id');
        if ($id <= 0) fail('id required');
        try {
            $result = lv_approve_leave_application($leave_conn, $id, current_actor_fullname($leave_conn, $user), trim((string)bp('admin_remarks')));
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 400);
        }
        if (isset($result['application']) && is_array($result['application'])) {
            leave_notify_employee($conn, $result['application'], 'hr_approved');
        }
        ok($result);

    case 'reject_application':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $id = bpi('id');
        if ($id <= 0) fail('id required');
        try {
            lv_reject_leave_application($leave_conn, $id, $actor, trim((string)bp('admin_remarks')));
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 400);
        }
        $application = lv_get_leave_application($leave_conn, $id);
        if ($application) {
            leave_notify_employee($conn, $application, 'hr_rejected');
        }
        ok(['message' => 'Leave application rejected']);

    case 'employees':
        require_admin_role();
        $q = trim($_GET['q'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = min(200, max(10, (int)($_GET['page_size'] ?? 50)));
        $result = lv_search_employees(
            $leave_conn,
            trim($_GET['surname'] ?? ($q !== '' ? $q : '')),
            trim($_GET['firstname'] ?? ''),
            trim($_GET['dept'] ?? ''),
            trim($_GET['piid'] ?? ''),
            $page,
            $pageSize
        );
        ok([
            'data' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'page_size' => $result['page_size'],
            'total_pages' => $result['total_pages'],
        ]);

    case 'employee':
        require_admin_role();
        $piid = trim($_GET['piid'] ?? '');
        if ($piid === '') fail('piid required');
        $emp = lv_get_employee($leave_conn, $piid);
        if (!$emp) fail('Employee not found', 404);
        ok(['data' => $emp]);

    case 'departments':
        require_admin_role();
        ok(['data' => lv_get_departments($leave_conn)]);

    case 'department_approver_setup':
        require_admin_role();
        require_rain_user();
        hrmis_ensure_department_approvers_table($conn);
        $users = $conn->query(
            "SELECT UID, Username
             FROM tblsysuser
             WHERE COALESCE(Username, '') <> ''
             ORDER BY Username"
        )->fetchAll(PDO::FETCH_ASSOC);
        $assignments = $conn->query(
            "SELECT id, user_id, username, department, approver_role, is_active
             FROM tbl_leave_department_approvers
             ORDER BY department, username"
        )->fetchAll(PDO::FETCH_ASSOC);
        ok([
            'users' => $users,
            'departments' => lv_get_departments($leave_conn),
            'assignments' => $assignments,
        ]);

    case 'department_approver_employee_search':
        require_admin_role();
        require_rain_user();
        try {
            $q = trim((string)($_GET['q'] ?? ''));
            if ($q === '') ok(['data' => []]);
            lv_ensure_employee_roster($leave_conn);
            $stmt = $leave_conn->prepare(
                'SELECT PIID, ID_NUM, Surname, Firstname, MiddleName, Department, Position, Status, IsCasual
                 FROM ' . _LV_EMP_ROSTER_TABLE . '
                 WHERE ID_NUM LIKE :q_id
                    OR Surname LIKE :q_surname
                    OR Firstname LIKE :q_firstname
                    OR CONCAT(Surname, ", ", Firstname) LIKE :q_full_reverse
                    OR CONCAT(Firstname, " ", Surname) LIKE :q_full_forward
                 ORDER BY Department, Surname, Firstname
                 LIMIT 30'
            );
            $like = '%' . $q . '%';
            $stmt->execute([
                ':q_id' => $like,
                ':q_surname' => $like,
                ':q_firstname' => $like,
                ':q_full_reverse' => $like,
                ':q_full_forward' => $like,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['Status'] = lv_normalize_employee_status($row['Status'] ?? null, !empty($row['IsCasual']));
            }
            unset($row);
            ok(['data' => $rows]);
        } catch (Throwable $e) {
            fail('Unable to search employee roster: ' . $e->getMessage(), 500);
        }

    case 'save_department_approver':
        require_admin_role();
        require_rain_user();
        if ($method !== 'POST') fail('POST required');
        hrmis_ensure_department_approvers_table($conn);
        $uid = bpi('user_id');
        $dept = trim((string)bp('department'));
        $approverRole = trim((string)bp('approver_role', 'department_head')) ?: 'department_head';
        $active = bpi('is_active') > 0 ? 1 : 0;
        if ($uid <= 0 || $dept === '') fail('User and department are required');
        $stmt = $conn->prepare("SELECT Username FROM tblsysuser WHERE UID = :uid LIMIT 1");
        $stmt->execute([':uid' => $uid]);
        $username = (string)($stmt->fetchColumn() ?: '');
        if ($username === '') fail('System user not found', 404);
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            "INSERT INTO tbl_leave_department_approvers
                (user_id, username, department, approver_role, is_active, created_at, updated_at)
             VALUES
                (:user_id, :username, :department, :approver_role, :is_active, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                username = VALUES(username),
                approver_role = VALUES(approver_role),
                is_active = VALUES(is_active),
                updated_at = VALUES(updated_at)"
        );
        $stmt->execute([
            ':user_id' => $uid,
            ':username' => $username,
            ':department' => $dept,
            ':approver_role' => $approverRole,
            ':is_active' => $active,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        ok(['message' => 'Department approver saved']);

    case 'register_department_approver_user':
        require_admin_role();
        require_rain_user();
        if ($method !== 'POST') fail('POST required');
        hrmis_ensure_department_approvers_table($conn);
        $piid = trim((string)bp('piid'));
        $username = trim((string)bp('username'));
        $password = trim((string)bp('password'));
        $dept = trim((string)bp('department'));
        $approverRole = trim((string)bp('approver_role', 'department_head')) ?: 'department_head';
        if ($piid === '' || $username === '' || $password === '' || $dept === '') {
            fail('Employee, username, password, and department are required');
        }
        if (strlen($password) < 6) {
            fail('Password must be at least 6 characters');
        }
        $employee = lv_get_employee($leave_conn, $piid);
        if (!$employee) fail('Employee not found in leave roster', 404);
        $existsStmt = $conn->prepare("SELECT UID FROM tblsysuser WHERE Username = :username LIMIT 1");
        $existsStmt->execute([':username' => $username]);
        if ($existsStmt->fetchColumn()) {
            fail('System username already exists');
        }

        $now = date('Y-m-d H:i:s');
        $conn->beginTransaction();
        try {
            $userStmt = $conn->prepare(
                "INSERT INTO tblsysuser
                    (`fkPDS`, `Username`, `Password`, `Userlevel`, `Office`, `TID`, `Updated_By`, `Last_Update`, `PDS`, `ARPS`, `LEAVE`)
                 VALUES
                    (:fkPDS, :username, MD5(:password), '2', :office, '', :updated_by, :last_update, 0, 0, 1)"
            );
            $userStmt->execute([
                ':fkPDS' => $piid,
                ':username' => $username,
                ':password' => $password,
                ':office' => $dept,
                ':updated_by' => substr((string)($user['username'] ?? 'rain'), 0, 20),
                ':last_update' => $now,
            ]);
            $uid = (int)$conn->lastInsertId();

            $approverStmt = $conn->prepare(
                "INSERT INTO tbl_leave_department_approvers
                    (user_id, username, department, approver_role, is_active, created_at, updated_at)
                 VALUES
                    (:user_id, :username, :department, :approver_role, 1, :created_at, :updated_at)"
            );
            $approverStmt->execute([
                ':user_id' => $uid,
                ':username' => $username,
                ':department' => $dept,
                ':approver_role' => $approverRole,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
        ok(['message' => 'Department approver user registered', 'user_id' => $uid]);

    case 'delete_department_approver':
        require_admin_role();
        require_rain_user();
        if ($method !== 'POST') fail('POST required');
        hrmis_ensure_department_approvers_table($conn);
        $id = bpi('id');
        if ($id <= 0) fail('id required');
        $stmt = $conn->prepare(
            "SELECT id, user_id, username
             FROM tbl_leave_department_approvers
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) fail('Department approver not found', 404);

        $uid = (int)($assignment['user_id'] ?? 0);
        $username = trim((string)($assignment['username'] ?? ''));
        if ($uid <= 0 && $username === '') fail('Linked system user not found', 404);

        $conn->beginTransaction();
        try {
            if ($uid > 0) {
                $deleteApprover = $conn->prepare("DELETE FROM tbl_leave_department_approvers WHERE user_id = :uid");
                $deleteApprover->execute([':uid' => $uid]);

                $deleteUser = $conn->prepare("DELETE FROM tblsysuser WHERE UID = :uid");
                $deleteUser->execute([':uid' => $uid]);
            } else {
                $deleteApprover = $conn->prepare("DELETE FROM tbl_leave_department_approvers WHERE username = :username");
                $deleteApprover->execute([':username' => $username]);

                $deleteUser = $conn->prepare("DELETE FROM tblsysuser WHERE Username = :username");
                $deleteUser->execute([':username' => $username]);
            }

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
        ok(['message' => 'Department approver user removed']);

    case 'warm_roster':
        require_admin_role();
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
        $force = isset($_GET['force']) && (string)$_GET['force'] === '1';
        if ($force) {
            lv_force_employee_roster_refresh($leave_conn, $year, $month);
        } else {
            lv_ensure_employee_roster($leave_conn, $year, $month);
        }
        $meta = lv_employee_roster_meta($leave_conn);
        ok([
            'warmed' => true,
            'forced' => $force,
            'year' => $year,
            'month' => $month,
            'row_count' => (int)($meta['row_count'] ?? 0),
            'refreshed_at' => (string)($meta['RefreshedAt'] ?? ''),
        ]);

    case 'warm_recalc':
        require_admin_role();
        $year = (int)($_GET['year'] ?? date('Y'));
        $dept = trim($_GET['dept'] ?? '');
        $limit = max(1, min(300, (int)($_GET['limit'] ?? 50)));
        ok(['data' => lv_recalculate_stale_batch($leave_conn, $year, $dept, $limit)]);

    case 'warm_report_recalc':
        require_admin_role();
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
        $dept = trim($_GET['dept'] ?? '');
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
        ok(['data' => lv_recalculate_report_batch($leave_conn, $year, $month, $dept, $offset, $limit)]);

    case 'records':
        require_admin_role();
        $piid = trim($_GET['piid'] ?? '');
        $year = (int)($_GET['year'] ?? date('Y'));
        if ($piid === '') fail('piid required');
        lv_recalculate($leave_conn, $piid, $year);
        ok(['data' => lv_get_records($leave_conn, $piid, $year)]);

    case 'balance':
        require_admin_role();
        $piid = trim($_GET['piid'] ?? '');
        $year = (int)($_GET['year'] ?? date('Y'));
        if ($piid === '') fail('piid required');
        ok(['data' => lv_get_balance($leave_conn, $piid, $year) ?? [
            'piid' => $piid,
            'year' => $year,
            'cBackVaca' => 0,
            'cBalSick' => 0,
            'NoAvailVL' => 5,
            'NoAvailSL' => _LV_DEFAULT_SPECIAL_PRIVILEGE_LEAVE,
            'MonetaryVL' => 0,
            'MonetarySL' => 0,
            'sourceYear' => $year - 1,
            'isAutoDerived' => 0,
        ]]);

    case 'dtr_suggested_undertime':
        require_admin_role();
        $piid = trim($_GET['piid'] ?? '');
        $from = trim($_GET['from'] ?? '');
        $to = trim($_GET['to'] ?? '');
        if ($piid === '') fail('piid required');
        if ($from === '') fail('from required');
        ok(['data' => lv_get_dtr_suggested_undertime($leave_conn, $piid, $from, $to)]);

    case 'credits_earned':
        require_admin_role();
        ok(['data' => lv_get_credits_earned($leave_conn)]);

    case 'conversion':
        require_admin_role();
        ok(['data' => lv_get_conversion($leave_conn)]);

    case 'dashboard_stats':
        require_admin_role();
        ok(lv_dashboard_stats($leave_conn));

    case 'run_monthly_leave_earned':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        lv_monthly_leave_earned_run_now($leave_conn, 'admin-manual');
        ok(['message' => 'Monthly leave earned backfill completed']);

    case 'backfill_employee_leave_earned':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $piid = trim(bp('PIID'));
        $year = (int)bp('year', date('Y'));
        if ($piid === '') fail('PIID required');
        $inserted = lv_backfill_employee_leave_earned($leave_conn, $piid, $year);
        ok([
            'inserted' => $inserted,
            'balance' => lv_get_balance($leave_conn, $piid, $year),
            'records' => lv_get_records($leave_conn, $piid, $year),
        ]);

    case 'report':
        require_admin_role();
        $year = (int)($_GET['year'] ?? date('Y'));
        $quarter = trim($_GET['quarter'] ?? '');
        $mfrom = isset($_GET['month_from']) && $_GET['month_from'] !== '' ? (int)$_GET['month_from'] : null;
        $mto = isset($_GET['month_to']) && $_GET['month_to'] !== '' ? (int)$_GET['month_to'] : null;
        $dept = trim($_GET['dept'] ?? '');
        if ($quarter !== '') {
            $quarterMap = [
                'Q1' => [1, 3],
                'Q2' => [4, 6],
                'Q3' => [7, 9],
                'Q4' => [10, 12],
            ];
            $quarter = strtoupper($quarter);
            if (!isset($quarterMap[$quarter])) fail('Invalid quarter');
            [$mfrom, $mto] = $quarterMap[$quarter];
        }
        $skip_recalc = isset($_GET['skip_recalc']) && (string)$_GET['skip_recalc'] === '1';
        ok(['data' => lv_report_summary($leave_conn, $year, $mfrom, $mto, $dept, !$skip_recalc)]);

    case 'save_record':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $piid = trim(bp('PIID'));
        if ($piid === '') fail('PIID required');
        if (!lv_get_employee($leave_conn, $piid)) fail('Employee not found', 404);
        $d = [
            'PIID' => $piid,
            'Type_of_Records' => bp_upper('Type_of_Records'),
            'Date_of_Filing' => trim(bp('Date_of_Filing')),
            'Period_From' => trim(bp('Period_From')),
            'Period_To' => trim(bp('Period_To')),
            'VacEarn' => bpf('VacEarn'),
            'VacWP' => bpf('VacWP'),
            'VacBal' => bpf('VacBal'),
            'VacWOP' => bpf('VacWOP'),
            'SickEarn' => bpf('SickEarn'),
            'SickWP' => bpf('SickWP'),
            'SickBal' => bpf('SickBal'),
            'SickWOP' => bpf('SickWOP'),
            'Particulars' => bp_upper('Particulars'),
            'DateAction' => bp_upper('DateAction'),
            'DateProcessed' => trim(bp('DateProcessed')),
            'RecordedBy' => bp_upper('RecordedBy'),
            'no_avail_VL' => bpf('no_avail_VL'),
            'no_avail_SL' => bpf('no_avail_SL'),
            'no_avail_mone_VL' => bpf('no_avail_mone_VL'),
            'no_avail_mone_SL' => bpf('no_avail_mone_SL'),
            'no_avail_SP' => bpf('no_avail_SP'),
            'no_avail_P' => bpf('no_avail_P'),
            'no_avail_mone' => bpf('no_avail_mone'),
            'Remarks' => bp_upper('Remarks'),
        ];
        $dtrHalfDay = bpi('DTRHalfDay') > 0;
        $dtrHalfDayPeriod = strtoupper(trim((string)bp('DTRHalfDayPeriod')));
        if ($d['Period_From'] === '' || $d['Period_To'] === '') fail('Period_From and Period_To are required');
        if ($dtrHalfDay && !in_array($dtrHalfDayPeriod, ['AM', 'PM'], true)) fail('DTRHalfDayPeriod must be AM or PM');
        if (
            $dtrHalfDay
            && lv_calculate_days_requested($d['Type_of_Records'], $d['Period_From'], $d['Period_To']) <= 1.0
            && !lv_is_compensatory_type($d['Type_of_Records'])
        ) {
            fail('Half-day leave must be part of a leave entry longer than 1 day, such as 1.5 or 2.5 days.');
        }
        $year = (int)date('Y', strtotime($d['Period_From']));
        $lid = bpi('LID');
        try {
            $leave_conn->beginTransaction();
            if (lv_is_wellness_type($d['Type_of_Records'])) {
                $requestedWellness = lv_wellness_days_for_record($d);
                if ($requestedWellness > 5.0) fail('Wellness Leave is capped at 5 days per year');
                $usedWellness = lv_wellness_days_used($leave_conn, $piid, $year, $lid);
                $remainingWellness = max(0, 5 - $usedWellness);
                if ($requestedWellness > $remainingWellness) {
                    fail('Wellness Leave exceeds the 5-day annual cap. Remaining balance: ' . number_format($remainingWellness, 3));
                }
            }
            if ($lid > 0) {
                if (!lv_get_record($leave_conn, $lid)) fail('Record not found', 404);
                lv_update_record($leave_conn, $lid, $d, $actor);
            } else {
                $lid = lv_insert_record($leave_conn, $d, $actor);
            }
            lv_upsert_dtr_override(
                $leave_conn,
                $lid,
                $d['Type_of_Records'],
                $d['Period_From'],
                $d['Period_To'],
                $d['Particulars'],
                $piid,
                $actor,
                $dtrHalfDay,
                $dtrHalfDayPeriod
            );
            $leave_conn->commit();
        } catch (Throwable $e) {
            if ($leave_conn->inTransaction()) {
                $leave_conn->rollBack();
            }
            fail('Unable to save DTR override: ' . $e->getMessage(), 500);
        }
        lv_recalculate($leave_conn, $piid, $year);
        ok(['lid' => $lid, 'records' => lv_get_records($leave_conn, $piid, $year)]);

    case 'delete_record':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $lid = bpi('LID');
        if ($lid <= 0) fail('LID required');
        $rec = lv_get_record($leave_conn, $lid);
        if (!$rec) fail('Record not found', 404);
        $piid = (string)$rec['PIID'];
        $recalcYear = (int)date('Y', strtotime((string)$rec['Period_From']));
        $viewYear = bpi('year');
        if ($viewYear <= 0) {
            $viewYear = (int)date('Y', strtotime((string)($rec['Date_of_Filing'] ?: $rec['Period_From'])));
        }
        $companionLid = lv_find_cancel_companion($leave_conn, $rec);
        lv_delete_record($leave_conn, $lid);
        lv_delete_dtr_override($leave_conn, $lid);
        if ($companionLid) {
            lv_delete_record($leave_conn, $companionLid);
            lv_delete_dtr_override($leave_conn, $companionLid);
        }
        lv_recalculate($leave_conn, $piid, $recalcYear);
        ok(['records' => lv_get_records($leave_conn, $piid, $viewYear)]);

    case 'save_balance':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $piid = trim(bp('piid'));
        $year = bpi('year');
        if ($piid === '' || $year <= 0) fail('piid and year required');
        if (!lv_get_employee($leave_conn, $piid)) fail('Employee not found', 404);
        $existing = lv_get_balance($leave_conn, $piid, $year) ?? [
            'NoAvailSLManual' => 0,
            'NoAvailSLManualSetAt' => null,
        ];
        lv_upsert_balance($leave_conn, $piid, $year, [
            'cBackVaca' => bpf('cBackVaca'),
            'cBalSick' => bpf('cBalSick'),
            'NoAvailVL' => bpf('NoAvailVL'),
            'NoAvailSL' => bpf('NoAvailSL'),
            'NoAvailSLManual' => (int)($existing['NoAvailSLManual'] ?? 0),
            'NoAvailSLManualSetAt' => $existing['NoAvailSLManualSetAt'] ?? null,
            'MonetaryVL' => bpf('MonetaryVL'),
            'MonetarySL' => bpf('MonetarySL'),
        ]);
        lv_recalculate($leave_conn, $piid, $year);
        ok(['records' => lv_get_records($leave_conn, $piid, $year)]);

    case 'save_mandatory_forced_leave':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $piid = trim(bp('piid'));
        $year = bpi('year');
        if ($piid === '' || $year <= 0) fail('piid and year required');
        if (!lv_get_employee($leave_conn, $piid)) fail('Employee not found', 404);
        $savedNoAvailVL = (($nvl = bpf('NoAvailVL')) > 0 ? $nvl : 5);
        $existing = lv_get_balance($leave_conn, $piid, $year) ?? [
            'piid' => $piid,
            'year' => $year,
            'cBackVaca' => 0,
            'cBalSick' => 0,
            'NoAvailVL' => 5,
            'NoAvailSL' => 0,
            'NoAvailSLManual' => 0,
            'NoAvailSLManualSetAt' => null,
            'MonetaryVL' => 0,
            'MonetarySL' => 0,
        ];
        lv_upsert_balance($leave_conn, $piid, $year, [
            'cBackVaca' => (float)($existing['cBackVaca'] ?? 0),
            'cBalSick' => (float)($existing['cBalSick'] ?? 0),
            'NoAvailVL' => $savedNoAvailVL,
            'NoAvailSL' => (float)($existing['NoAvailSL'] ?? 0),
            'NoAvailSLManual' => (int)($existing['NoAvailSLManual'] ?? 0),
            'NoAvailSLManualSetAt' => $existing['NoAvailSLManualSetAt'] ?? null,
            'MonetaryVL' => (float)($existing['MonetaryVL'] ?? 0),
            'MonetarySL' => (float)($existing['MonetarySL'] ?? 0),
        ]);
        lv_recalculate($leave_conn, $piid, $year);
        ok([
            'saved_no_avail_vl' => $savedNoAvailVL,
            'balance' => lv_get_balance($leave_conn, $piid, $year),
            'records' => lv_get_records($leave_conn, $piid, $year),
        ]);

    case 'save_special_leave':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $piid = trim(bp('piid'));
        $year = bpi('year');
        if ($piid === '' || $year <= 0) fail('piid and year required');
        if (!lv_get_employee($leave_conn, $piid)) fail('Employee not found', 404);
        $manualEdited = filter_var(bp('manual_edited', false), FILTER_VALIDATE_BOOLEAN);
        $requestedNoAvailSL = (($nsl = bpf('NoAvailSL')) >= 0 ? $nsl : 0);
        $existing = lv_get_balance($leave_conn, $piid, $year) ?? [
            'piid' => $piid,
            'year' => $year,
            'cBackVaca' => 0,
            'cBalSick' => 0,
            'NoAvailVL' => 5,
            'NoAvailSL' => 0,
            'NoAvailSLManual' => 0,
            'NoAvailSLManualSetAt' => null,
            'MonetaryVL' => 0,
            'MonetarySL' => 0,
        ];
        $usedSpecialDays = lv_special_leave_days_used($leave_conn, $piid, $year);
        $autoNoAvailSL = lv_auto_special_leave_forward($leave_conn, $piid, $year);
        $manualOverrideActive = $manualEdited;
        $savedNoAvailSL = $manualEdited
            ? round($requestedNoAvailSL + $usedSpecialDays, 4)
            : $autoNoAvailSL;
        $manualSetAt = $manualEdited
            ? date('Y-m-d H:i:s')
            : null;
        lv_upsert_balance($leave_conn, $piid, $year, [
            'cBackVaca' => (float)($existing['cBackVaca'] ?? 0),
            'cBalSick' => (float)($existing['cBalSick'] ?? 0),
            'NoAvailVL' => (float)($existing['NoAvailVL'] ?? 5),
            'NoAvailSL' => $savedNoAvailSL,
            'NoAvailSLManual' => $manualOverrideActive ? 1 : 0,
            'NoAvailSLManualSetAt' => $manualSetAt,
            'MonetaryVL' => (float)($existing['MonetaryVL'] ?? 0),
            'MonetarySL' => (float)($existing['MonetarySL'] ?? 0),
        ]);
        lv_recalculate($leave_conn, $piid, $year);
        $records = lv_get_records($leave_conn, $piid, $year);
        $latest = !empty($records) ? $records[count($records) - 1] : null;
        $availableNoAvailSL = $latest !== null
            ? (float)($latest['no_avail_SL'] ?? 0)
            : $savedNoAvailSL;
        ok([
            'saved_no_avail_sl' => $availableNoAvailSL,
            'special_leave_manual' => $manualOverrideActive,
            'special_leave_starting_balance' => $savedNoAvailSL,
            'balance' => lv_get_balance($leave_conn, $piid, $year),
            'records' => $records,
        ]);

    case 'save_credits_earned':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $rows = bp('rows', []);
        if (!is_array($rows)) fail('rows must be an array');
        lv_save_credits_earned($leave_conn, $rows);
        ok(['data' => lv_get_credits_earned($leave_conn)]);

    case 'save_conversion':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $rows = bp('rows', []);
        if (!is_array($rows)) fail('rows must be an array');
        lv_save_conversion($leave_conn, $rows);
        ok(['data' => lv_get_conversion($leave_conn)]);

    case 'cancel_record':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $lid = bpi('LID');
        if ($lid <= 0) fail('LID required');
        $rec = lv_get_record($leave_conn, $lid);
        if (!$rec) fail('Record not found', 404);
        try {
            $newLid = lv_cancel_record($leave_conn, $lid, trim(bp('filing_date')), $actor);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        $piid = (string)$rec['PIID'];
        $year = (int)date('Y', strtotime((string)($rec['Period_From'] ?? date('Y-m-d'))));
        ok(['new_lid' => $newLid, 'records' => lv_get_records($leave_conn, $piid, $year)]);

    case 'reschedule_record':
        require_admin_role();
        if ($method !== 'POST') fail('POST required');
        $lid = bpi('LID');
        if ($lid <= 0) fail('LID required');
        $rec = lv_get_record($leave_conn, $lid);
        if (!$rec) fail('Record not found', 404);
        try {
            $newLid = lv_reschedule_record($leave_conn, $lid, trim(bp('new_from')), trim(bp('new_to')), trim(bp('filing_date')), $actor);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        $piid = (string)$rec['PIID'];
        $year = (int)date('Y', strtotime((string)($rec['Period_From'] ?? date('Y-m-d'))));
        ok(['new_lid' => $newLid, 'records' => lv_get_records($leave_conn, $piid, $year)]);

    default:
        fail('Unknown action', 404);
}
