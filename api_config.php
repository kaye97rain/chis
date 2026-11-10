<?php
// Load environment variables
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
        return true;
    }
}

loadEnv(__DIR__ . '/.env');

// Set API directory path for PHP includes
$_ENV['API_DIR_PATH'] = __DIR__;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/javascript");

// Use .env value only if its host matches the current request host.
$_env_api_url   = $_ENV['API_BASE_URL'] ?? '';
$_current_host  = $_SERVER['HTTP_HOST'] ?? '';
$_env_host      = $_env_api_url !== '' ? (parse_url($_env_api_url, PHP_URL_HOST) ?? '') : '';
if ($_env_api_url !== '' && $_env_host === $_current_host) {
    $computed_api_base = rtrim($_env_api_url, '/');
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_current_host ?: 'localhost';
    $project_root = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $computed_api_base = $scheme . '://' . $host . rtrim($project_root, '/') . '/API';
}
?>
const API_BASE_URL = "<?php echo $computed_api_base; ?>";
