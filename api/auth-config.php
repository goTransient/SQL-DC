<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function loadLocalAuthEnv(): void
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read local authentication configuration.');
    }

    $allowedNames = [
        'SQLDC_BASE_URL',
        'SQLDC_GOOGLE_REDIRECT_URI',
        'SQLDC_GOOGLE_CLIENT_ID',
        'SQLDC_GOOGLE_CLIENT_SECRET',
        'DEV_MODE',
        'SQLDC_DB_HOST',
        'SQLDC_DB_PORT',
        'SQLDC_DB_NAME',
        'SQLDC_DB_USER',
        'SQLDC_DB_PASSWORD'
    ];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $matches)) {
            continue;
        }

        $name = $matches[1];
        if (in_array($name, $allowedNames, true) && getenv($name) === false) {
            putenv($name . '=' . trim($matches[2]));
        }
    }
}

loadLocalAuthEnv();

function requiredAuthEnv(string $name): string
{
    $value = getenv($name);

    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Missing required environment variable: $name");
    }

    return trim($value);
}

function sessionConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $baseUrl = rtrim(requiredAuthEnv('SQLDC_BASE_URL'), '/');
    $base = parse_url($baseUrl);

    if (
        $base === false ||
        empty($base['scheme']) ||
        empty($base['host']) ||
        isset($base['user']) ||
        isset($base['pass']) ||
        isset($base['query']) ||
        isset($base['fragment'])
    ) {
        throw new RuntimeException('SQLDC_BASE_URL must be an absolute application URL without credentials, query, or fragment.');
    }

    $scheme = strtolower($base['scheme']);
    $host = strtolower($base['host']);
    $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($host, '.localhost');

    if ($scheme !== 'https' && !($scheme === 'http' && $isLocal)) {
        throw new RuntimeException('SQLDC_BASE_URL must use HTTPS except for localhost development.');
    }

    $basePath = $base['path'] ?? '';
    if (
        $basePath !== '' &&
        (!preg_match('#^/(?:[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*)?$#', $basePath)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $basePath))
    ) {
        throw new RuntimeException('SQLDC_BASE_URL contains an invalid application path.');
    }

    $config = [
        'base_url' => $baseUrl,
        'base_path' => rtrim($basePath, '/'),
        'cookie_path' => $basePath === '' ? '/' : rtrim($basePath, '/') . '/',
        'secure_cookie' => $scheme === 'https'
    ];

    return $config;
}

function authConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $sessionConfig = sessionConfig();
    $baseUrl = $sessionConfig['base_url'];
    $base = parse_url($baseUrl);
    $scheme = strtolower($base['scheme']);
    $host = strtolower($base['host']);
    $basePath = $base['path'] ?? '';
    $expectedCallbackPath = rtrim($basePath, '/') . '/api/google-callback.php';
    $redirectUri = requiredAuthEnv('SQLDC_GOOGLE_REDIRECT_URI');
    $redirect = parse_url($redirectUri);

    if (
        $redirect === false ||
        strtolower($redirect['scheme'] ?? '') !== $scheme ||
        strtolower($redirect['host'] ?? '') !== $host ||
        (int)($redirect['port'] ?? ($scheme === 'https' ? 443 : 80))
            !== (int)($base['port'] ?? ($scheme === 'https' ? 443 : 80)) ||
        ($redirect['path'] ?? '') !== $expectedCallbackPath ||
        isset($redirect['user']) ||
        isset($redirect['pass']) ||
        isset($redirect['query']) ||
        isset($redirect['fragment'])
    ) {
        throw new RuntimeException('SQLDC_GOOGLE_REDIRECT_URI must point to this application’s /api/google-callback.php endpoint.');
    }

    $clientId = requiredAuthEnv('SQLDC_GOOGLE_CLIENT_ID');
    $clientSecret = requiredAuthEnv('SQLDC_GOOGLE_CLIENT_SECRET');

    if (preg_match('/\s/', $clientId) || preg_match('/\s/', $clientSecret)) {
        throw new RuntimeException('Google OAuth configuration contains invalid whitespace.');
    }

    $config = [
        ...$sessionConfig,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri
    ];

    return $config;
}
