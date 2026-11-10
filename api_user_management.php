<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';

function sendResponse($status, $message, $data = null, $code = 200) {
    http_response_code($code);
    $response = [
        'status' => $status,
        'message' => $message
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

function toBool($value) {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int)$value) === 1;
    }
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function getRoleIdByKey($roleKey) {
    $roleKey = strtolower(trim((string)$roleKey));
    foreach (getRoleDefinitions() as $role) {
        if (strtolower(trim((string)($role['role_key'] ?? ''))) === $roleKey) {
            return (int)($role['role_id'] ?? 0);
        }
    }
    return 0;
}

function getManagedUserById($userId) {
    $userId = (int)$userId;
    foreach (getManagedUsersForAccess() as $user) {
        if ((int)($user['user_id'] ?? 0) === $userId) {
            return $user;
        }
    }
    return null;
}

function normalizeRequestedSubsystem($subsystem) {
    $subsystem = strtolower(trim((string)$subsystem));
    return in_array($subsystem, ['emedtrack', 'laboratory'], true) ? $subsystem : '';
}

function filterUserMatrixBySubsystem(array $userMatrix, $subsystem) {
    $subsystem = normalizeRequestedSubsystem($subsystem);
    if ($subsystem === '') {
        return $userMatrix;
    }

    $filteredUsers = [];
    $allowedUserIds = [];
    foreach (($userMatrix['users'] ?? []) as $user) {
        $userId = (int)($user['user_id'] ?? 0);
        $roleKey = strtolower(trim((string)($user['role_key'] ?? '')));
        $subsystemAccess = normalizeSubsystemAccess($user['subsystem_access'] ?? 'emedtrack');
        $belongsToSubsystem = $subsystemAccess === 'all' || $subsystemAccess === $subsystem;

        if (isSuperAdminRoleKey($roleKey) || $belongsToSubsystem) {
            $filteredUsers[] = $user;
            $allowedUserIds[$userId] = true;
        }
    }

    foreach (['user_access', 'user_overrides'] as $matrixKey) {
        $filteredMatrix = [];
        foreach (($userMatrix[$matrixKey] ?? []) as $userId => $moduleMap) {
            if (isset($allowedUserIds[(int)$userId])) {
                $filteredMatrix[$userId] = $moduleMap;
            }
        }
        $userMatrix[$matrixKey] = $filteredMatrix;
    }

    $userMatrix['users'] = $filteredUsers;
    return $userMatrix;
}

function normalizeLocationValue($location, $isSuperAdmin) {
    $location = strtolower(trim((string)$location));
    if ($isSuperAdmin) {
        return 'ALL';
    }

    $allowed = [
        'cho' => 'CHO',
        'aglayan' => 'Aglayan',
        'uplc' => 'UPLC'
    ];

    return $allowed[$location] ?? '';
}

function normalizeWorkAreaValue($workArea, $isSuperAdmin) {
    $workArea = strtolower(trim((string)$workArea));
    if ($isSuperAdmin) {
        return 'ALL';
    }

    return in_array($workArea, ['stock_room', 'pharmacy'], true) ? $workArea : '';
}

$authUser = requireAuth();
$roleKey = strtolower(trim((string)($authUser['role'] ?? '')));

if (!userHasModuleAccess($authUser, 'user_management')) {
    sendResponse('error', 'Access denied for User Management module.', null, 403);
}

if (!isSuperAdminRoleKey($roleKey)) {
    sendResponse('error', 'Only Super Admin can access User Management.', null, 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requestedSubsystem = normalizeRequestedSubsystem($_GET['subsystem'] ?? '');
    $roleMatrix = getRoleModuleAccessMatrix();
    $userMatrix = getUserModuleAccessMatrix($roleMatrix['access'] ?? []);
    $userMatrix = filterUserMatrixBySubsystem($userMatrix, $requestedSubsystem);
    $matrix = array_merge($roleMatrix, $userMatrix);
    $matrix['can_edit'] = isSuperAdminRoleKey($roleKey);
    sendResponse('success', 'Access levels loaded.', $matrix);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        sendResponse('error', 'Invalid JSON payload.', null, 400);
    }

    $action = strtolower(trim((string)($payload['action'] ?? 'access')));

    if ($action === 'update_user') {
        global $conn;

        $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
        $targetUser = getManagedUserById($userId);
        if (!$targetUser) {
            sendResponse('error', 'User was not found.', null, 404);
        }
        if (isSuperAdminRoleKey($targetUser['role_key'] ?? '')) {
            sendResponse('error', 'Super Admin accounts cannot be edited here.', null, 403);
        }

        $username = trim((string)($payload['username'] ?? ''));
        $role = strtolower(trim((string)($payload['role'] ?? '')));
        $accountSubsystem = strtolower(trim((string)($payload['account_subsystem'] ?? '')));
        $fName = trim((string)($payload['f_name'] ?? ''));
        $mName = trim((string)($payload['m_name'] ?? ''));
        $lName = trim((string)($payload['l_name'] ?? ''));

        if (strlen($username) < 3) {
            sendResponse('error', 'Username must be at least 3 characters long.', null, 400);
        }

        $roleId = getRoleIdByKey($role);
        if ($roleId <= 0 || isSuperAdminRoleKey($role)) {
            sendResponse('error', 'Please select Administrator, Pharmacist, COA, Accounting, or User role.', null, 400);
        }

        $subsystemAccess = in_array($accountSubsystem, ['emedtrack', 'laboratory', 'all', 'permits'], true)
            ? $accountSubsystem
            : normalizeSubsystemAccess($targetUser['subsystem_access'] ?? 'emedtrack');
        // The "User" role is permits & certificates staff only.
        if (isPermitStaffRoleKey($role)) {
            $subsystemAccess = 'permits';
        }

        // Permits-only accounts have no inventory location/work area (stored as ALL).
        $isAllLocationRole = isSuperAdminRoleKey($role) || in_array($role, ['coa', 'accounting'], true) || $subsystemAccess === 'permits';
        $locationValue = normalizeLocationValue($payload['location'] ?? '', $isAllLocationRole);
        $workAreaValue = normalizeWorkAreaValue($payload['work_area'] ?? '', $isAllLocationRole);
        if ($locationValue === '' || $workAreaValue === '') {
            sendResponse('error', 'Please select a valid location and work area.', null, 400);
        }

        try {
            $dupStmt = $conn->prepare("SELECT user_id FROM users WHERE user_name = ? AND user_id <> ? LIMIT 1");
            $dupStmt->execute([$username, $userId]);
            if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
                sendResponse('error', 'Username already exists. Please choose a different username.', null, 409);
            }

            ensureUsersSubsystemAccessColumn();

            $stmt = $conn->prepare("
                UPDATE users
                SET user_name = ?, role_id = ?, Location = ?, work_area = ?, subsystem_access = ?, f_name = ?, m_name = ?, l_name = ?
                WHERE user_id = ?
            ");
            $saved = $stmt->execute([$username, $roleId, $locationValue, $workAreaValue, $subsystemAccess, $fName, $mName, $lName, $userId]);
            if (!$saved) {
                sendResponse('error', 'Unable to update user account.', null, 500);
            }

            sendResponse('success', 'User account updated.', [
                'user_id' => $userId,
                'users' => getManagedUsersForAccess()
            ]);
        } catch (Throwable $e) {
            error_log('User update error: ' . $e->getMessage());
            sendResponse('error', 'Database error occurred while updating user.', null, 500);
        }
    }

    if ($action === 'reset_password') {
        global $conn;

        $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
        $targetUser = getManagedUserById($userId);
        if (!$targetUser) {
            sendResponse('error', 'User was not found.', null, 404);
        }
        if (isSuperAdminRoleKey($targetUser['role_key'] ?? '')) {
            sendResponse('error', 'Super Admin passwords cannot be reset here.', null, 403);
        }

        $password = (string)($payload['password'] ?? '');
        if (strlen($password) < 6) {
            sendResponse('error', 'Password must be at least 6 characters long.', null, 400);
        }

        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $saved = $stmt->execute([$hash, $userId]);
            if (!$saved) {
                sendResponse('error', 'Unable to reset password.', null, 500);
            }

            sendResponse('success', 'Password reset successfully.', ['user_id' => $userId]);
        } catch (Throwable $e) {
            error_log('Password reset error: ' . $e->getMessage());
            sendResponse('error', 'Database error occurred while resetting password.', null, 500);
        }
    }

    $roleId = isset($payload['role_id']) ? (int)$payload['role_id'] : 0;
    $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
    $moduleKey = normalizeModuleKey($payload['module_key'] ?? '');
    $isEnabled = toBool($payload['is_enabled'] ?? false);

    if (($roleId <= 0 && $userId <= 0) || $moduleKey === '') {
        sendResponse('error', 'role_id/user_id and module_key are required.', null, 400);
    }

    if ($userId > 0) {
        $saved = setUserModuleAccess($userId, $moduleKey, $isEnabled, $authUser['id'] ?? null);
        if (!$saved) {
            sendResponse('error', 'Unable to save user module access.', null, 500);
        }

        $managedUsers = getManagedUsersForAccess();
        $targetUser = null;
        foreach ($managedUsers as $userRow) {
            if ((int)($userRow['user_id'] ?? 0) === $userId) {
                $targetUser = $userRow;
                break;
            }
        }

        $effectiveEnabled = $isEnabled ? 1 : 0;
        if ($targetUser) {
            $effectiveEnabled = userHasModuleAccess([
                'id' => $userId,
                'role' => $targetUser['role_key'] ?? 'user',
                'role_id' => (int)($targetUser['role_id'] ?? 0)
            ], $moduleKey) ? 1 : 0;
        }

        sendResponse('success', 'User module access updated.', [
            'user_id' => $userId,
            'module_key' => $moduleKey,
            'is_enabled' => $effectiveEnabled
        ]);
    }

    $saved = setRoleModuleAccess($roleId, $moduleKey, $isEnabled, $authUser['id'] ?? null);
    if (!$saved) {
        sendResponse('error', 'Unable to save role module access.', null, 500);
    }

    $roleLookup = getRoleLookupById();
    $roleResolvedKey = isset($roleLookup[$roleId]['role_key']) ? $roleLookup[$roleId]['role_key'] : '';
    $effectiveEnabled = getRoleModuleAccess($roleId, $roleResolvedKey, $moduleKey) ? 1 : 0;

    sendResponse('success', 'Role module access updated.', [
        'role_id' => $roleId,
        'module_key' => $moduleKey,
        'is_enabled' => $effectiveEnabled
    ]);
}

sendResponse('error', 'Invalid request method.', null, 405);
?>
