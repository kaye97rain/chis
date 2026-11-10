<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

// Include database authentication functions
require_once 'db_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $token = $data['token'] ?? '';

    if (empty($token)) {
        // Try to get token from Authorization header
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                $token = $matches[1];
            }
        }
    }

    if (empty($token)) {
        echo json_encode([
            "status" => "error",
            "message" => "Token is required."
        ]);
        exit;
    }

    try {
        $_POST['auth_token'] = $token;
        $_COOKIE[CHIS_AUTH_COOKIE] = $token;
        $userData = validateToken();

        if ($userData) {
            $moduleAccess = [];
            foreach (getModuleRegistry() as $moduleKey => $_moduleMeta) {
                $moduleAccess[$moduleKey] = userHasModuleAccess($userData, $moduleKey);
            }
            $userData['module_access'] = $moduleAccess;

            echo json_encode([
                "status" => "success",
                "message" => "Token is valid",
                "user" => $userData
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "message" => "Invalid or expired token."
            ]);
        }
    } catch (Exception $e) {
        error_log("Token validation error: " . $e->getMessage());
        echo json_encode([
            "status" => "error",
            "message" => "Token validation failed."
        ]);
    }
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method."
    ]);
}
?>
