<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/db_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

$token = trim((string)($data['token'] ?? ''));
$moduleKey = strtolower(trim((string)($data['module_key'] ?? '')));
$moduleKeys = [];
if (isset($data['module_keys']) && is_array($data['module_keys'])) {
    foreach ($data['module_keys'] as $key) {
        $normalizedKey = strtolower(trim((string)$key));
        if ($normalizedKey !== '') {
            $moduleKeys[$normalizedKey] = true;
        }
    }
    $moduleKeys = array_keys($moduleKeys);
}

if ($token === '' || ($moduleKey === '' && empty($moduleKeys))) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Token and module key are required.'
    ]);
    exit;
}

$_POST['auth_token'] = $token;
$userData = validateToken();

if (!$userData) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid or expired token.'
    ]);
    exit;
}

$user = [
    'id' => $userData['id'] ?? null,
    'username' => $userData['username'] ?? null,
    'role' => $userData['role'] ?? null
];

if (!empty($moduleKeys)) {
    $access = [];
    foreach ($moduleKeys as $key) {
        $access[$key] = userHasModuleAccess($userData, $key);
    }

    echo json_encode([
        'status' => 'success',
        'access' => $access,
        'user' => $user
    ]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'allowed' => userHasModuleAccess($userData, $moduleKey),
    'user' => $user
]);
?>
