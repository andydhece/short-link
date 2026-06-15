<?php
// loadEnv parses .env if it exists
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                // Remove surrounding quotes if any
                if (preg_match('/^"?(.*?)"?$/', $value, $matches)) {
                    $value = $matches[1];
                }
                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv(sprintf('%s=%s', $name, $value));
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}

loadEnv(__DIR__ . '/.env');

return [
    'db_host' => getenv('DB_HOST') ?: 'db',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_password' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
    'db_name' => getenv('DB_NAME') ?: 'dhece086_your551',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'base_url' => rtrim(getenv('BASE_URL') ?: 'http://localhost:8080', '/'),
    'admin_username' => getenv('ADMIN_USERNAME') ?: 'andy.dhece',
    'admin_password' => getenv('ADMIN_PASSWORD') ?: 'admin123',
    'session_secret' => getenv('SESSION_SECRET') ?: 'fallback-secret-key-12998',
];
