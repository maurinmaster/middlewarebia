<?php

if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                if (!array_key_exists($key, $_ENV)) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
}

$rootPath = dirname(__DIR__);
loadEnv($rootPath . '/.env');

date_default_timezone_set(getenv('TIMEZONE') ?: 'America/Sao_Paulo');

$rawAppUrl = getenv('APP_URL') ?: 'http://localhost:8080';
if (!preg_match('#^https?://#i', $rawAppUrl)) {
    $rawAppUrl = 'https://' . $rawAppUrl;
}

return [
    'root_path' => $rootPath,
    'data_path' => $rootPath . '/data',
    'logs_path' => $rootPath . '/data/logs',
    'app_url'   => rtrim($rawAppUrl, '/'),
    'sync_only_with_stock' => (getenv('SYNC_ONLY_WITH_STOCK') === false) ? true : filter_var(getenv('SYNC_ONLY_WITH_STOCK'), FILTER_VALIDATE_BOOLEAN),
    
    // Nuvemshop
    'nuvemshop' => [
        'store_id'     => getenv('NUVEMSHOP_STORE_ID') ?: '8045641',
        'access_token' => getenv('NUVEMSHOP_ACCESS_TOKEN') ?: '',
        'user_agent'   => getenv('NUVEMSHOP_USER_AGENT') ?: 'lojaBia (procombatesanda@gmail.com)',
        'location_id'  => getenv('NUVEMSHOP_LOCATION_ID') ?: '01KZ4G2W4BPEJX3MQ4ARW1B692',
        'api_url'      => 'https://api.nuvemshop.com.br/v1',
    ],
    
    // eGestor
    'egestor' => [
        'security_token' => getenv('EGESTOR_SECURITY_TOKEN') ?: '4d2bf5aa7a3d610b8ce40bd504037f',
        'personal_token' => getenv('EGESTOR_PERSONAL_TOKEN') ?: '',
        'auth_url'       => 'https://api.egestor.com.br/api/oauth/access_token',
        'api_url'        => 'https://api.egestor.com.br/api/v1',
    ]
];
