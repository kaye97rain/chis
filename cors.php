<?php
/**
 * Centralised CORS handler for all API endpoints.
 * Include this at the top of db_auth.php so every api_*.php gets it for free.
 *
 * Rules:
 *  - Same-origin requests (Origin host equals this server's host) are always allowed.
 *  - Other origins must be listed in CORS_ALLOWED_ORIGINS in API/.env (comma separated,
 *    e.g. CORS_ALLOWED_ORIGINS=http://192.168.10.232,https://sakatamalaybalay.com).
 *  - Unlisted origins get no CORS headers, so browsers refuse to share the response,
 *    and their preflight requests are rejected with 403.
 */
if (headers_sent()) {
    return;
}

if (!function_exists('chisCorsAllowedOrigins')) {
    function chisCorsAllowedOrigins() {
        $raw = getenv('CORS_ALLOWED_ORIGINS');
        if ($raw === false || $raw === '') {
            $raw = '';
            $envPath = __DIR__ . '/.env';
            if (is_readable($envPath)) {
                foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if (strpos($line, 'CORS_ALLOWED_ORIGINS=') === 0) {
                        $raw = substr($line, strlen('CORS_ALLOWED_ORIGINS='));
                    }
                }
            }
        }

        $origins = [];
        foreach (explode(',', $raw) as $origin) {
            $origin = strtolower(rtrim(trim($origin), '/'));
            if ($origin !== '') {
                $origins[] = $origin;
            }
        }
        return $origins;
    }

    function chisCorsOriginIsAllowed($origin) {
        $origin = strtolower(rtrim(trim((string)$origin), '/'));
        if ($origin === '') {
            return false;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($originHost !== null && $requestHost !== '') {
            $originHostPort = strtolower($originHost) . ($originPort ? ':' . $originPort : '');
            if ($originHostPort === $requestHost) {
                return true;
            }
        }

        return in_array($origin, chisCorsAllowedOrigins(), true);
    }
}

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isPreflight = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS';

if ($requestOrigin !== '' && chisCorsOriginIsAllowed($requestOrigin)) {
    // Reflect the exact allowed origin — required for credentialed requests.
    header("Access-Control-Allow-Origin: $requestOrigin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
} elseif ($requestOrigin !== '' && $isPreflight) {
    http_response_code(403);
    exit;
}

header("Vary: Origin");

// Answer preflight immediately — no need to touch the database.
if ($isPreflight) {
    http_response_code(200);
    exit;
}
