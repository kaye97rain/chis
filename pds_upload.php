<?php
/**
 * PDS File Upload Handler
 * Stores file binary directly in pds_attachments.file_data (MEDIUMBLOB)
 * No filesystem storage — everything in cgmhris database.
 *
 * POST params:
 *   pds_id       int      required
 *   file_type    string   photo|signature|eligibility_cert|training_cert|civil_service_cert|valid_id|other
 *   reference_id int      optional – links to a specific pds_civil_service.id or pds_learning_development.id
 *   file         FILE     the uploaded file
 */

require_once __DIR__ . '/db_auth.php';
require_once __DIR__ . '/pds_db.php';

header('Content-Type: application/json');

$mainUser = validateToken();
$pdsUser = null;
if (!$mainUser) {
    $pdsUser = pds_validate_token();
}
if (!$mainUser && !$pdsUser) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
    exit;
}

$user = $mainUser ?: $pdsUser;

function enforceUploadOwnership(PDO $conn, int $pds_id, ?array $pdsUser): void {
    if (!$pdsUser || !$pds_id) {
        return;
    }

    $linkedId = (int)($pdsUser['pds_id'] ?? 0);
    if ($linkedId) {
        if ($linkedId !== $pds_id) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Not authorized for this PDS record']);
            exit;
        }
        return;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM pds_personal_info
        WHERE id = ?
          AND (
            (employee_no = ? AND ? <> '')
            OR (bio_id = ? AND ? <> '')
          )
        LIMIT 1
    ");
    $stmt->execute([
        $pds_id,
        $pdsUser['employee_no'] ?? '',
        $pdsUser['employee_no'] ?? '',
        $pdsUser['bio_id'] ?? '',
        $pdsUser['bio_id'] ?? '',
    ]);

    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not authorized for this PDS record']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$pds_id    = (int)($_POST['pds_id'] ?? 0);
$file_type = $_POST['file_type'] ?? 'other';
$ref_id    = isset($_POST['reference_id']) ? (int)$_POST['reference_id'] : null;

if (!$pds_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'pds_id is required']);
    exit;
}

enforceUploadOwnership($conn, $pds_id, $pdsUser);

$allowed_types = ['photo','signature','eligibility_cert','training_cert','civil_service_cert','valid_id','other'];
if (!in_array($file_type, $allowed_types, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid file_type']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['file']['error'] ?? 'no file received';
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "Upload error code: $code"]);
    exit;
}

$tmp       = $_FILES['file']['tmp_name'];
$orig_name = basename($_FILES['file']['name']);
$size      = $_FILES['file']['size'];
$mime      = mime_content_type($tmp);

// Allowed MIME types
$allowed_mimes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'application/pdf',
];
if (!in_array($mime, $allowed_mimes, true)) {
    http_response_code(415);
    echo json_encode(['status' => 'error', 'message' => "File type not allowed: $mime"]);
    exit;
}

// 5 MB hard limit (MEDIUMBLOB supports up to 16 MB)
if ($size > 5 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['status' => 'error', 'message' => 'File exceeds 5 MB limit']);
    exit;
}

// Read raw binary
$binary = file_get_contents($tmp);
if ($binary === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not read uploaded file']);
    exit;
}

$file_hash = hash('sha256', $binary);

try {
    $conn->beginTransaction();

    // For photo and signature: demote current primary
    if (in_array($file_type, ['photo', 'signature'], true)) {
        $stmt = $conn->prepare("
            UPDATE pds_attachments
               SET is_primary = 0
             WHERE pds_id = ? AND file_type = ? AND is_deleted = 0
        ");
        $stmt->execute([$pds_id, $file_type]);
    }

    // Insert new record with binary data
    $stmt = $conn->prepare("
        INSERT INTO pds_attachments
            (pds_id, bio_id, file_type, reference_id, file_data, file_hash,
             original_filename, file_size_bytes, mime_type, is_primary, uploaded_by)
        VALUES
            (:pds_id,
             (SELECT bio_id FROM pds_personal_info WHERE id = :pds_id2),
             :file_type, :ref_id, :file_data, :file_hash,
             :orig_name, :size, :mime, 1, :user_id)
    ");
    $stmt->bindValue(':pds_id',     $pds_id,    PDO::PARAM_INT);
    $stmt->bindValue(':pds_id2',    $pds_id,    PDO::PARAM_INT);
    $stmt->bindValue(':file_type',  $file_type, PDO::PARAM_STR);
    $stmt->bindValue(':ref_id',     $ref_id,    PDO::PARAM_INT);
    $stmt->bindValue(':file_data',  $binary,    PDO::PARAM_LOB); // binary-safe
    $stmt->bindValue(':file_hash',  $file_hash, PDO::PARAM_STR);
    $stmt->bindValue(':orig_name',  $orig_name, PDO::PARAM_STR);
    $stmt->bindValue(':size',       $size,      PDO::PARAM_INT);
    $stmt->bindValue(':mime',       $mime,      PDO::PARAM_STR);
    $stmt->bindValue(':user_id',    $user['id'],PDO::PARAM_INT);
    $stmt->execute();

    $attach_id = $conn->lastInsertId();
    $conn->commit();

    echo json_encode([
        'status'     => 'success',
        'attach_id'  => (int)$attach_id,
        'file_type'  => $file_type,
        'mime_type'  => $mime,
        'size_bytes' => $size,
        'file_hash'  => $file_hash,
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    error_log('pds_upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error during upload']);
}
