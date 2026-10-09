<?php
declare(strict_types=1);

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

function normalizeAuthEmail(string $email): string
{
    return strtolower(trim($email));
}

function validGoogleIdTokenClaims(array $claims, string $clientId, string $nonce): bool
{
    $issuer = $claims['iss'] ?? '';
    $audience = $claims['aud'] ?? '';
    $audiences = is_array($audience) ? $audience : [$audience];
    $authorizedParty = $claims['azp'] ?? null;
    $verified = $claims['email_verified'] ?? false;
    $tokenNonce = $claims['nonce'] ?? '';

    return in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
        && in_array($clientId, $audiences, true)
        && (count($audiences) < 2 || $authorizedParty === $clientId)
        && ($authorizedParty === null || $authorizedParty === $clientId)
        && isset($claims['sub']) && is_string($claims['sub']) && $claims['sub'] !== ''
        && isset($claims['email']) && is_string($claims['email'])
        && filter_var($claims['email'], FILTER_VALIDATE_EMAIL) !== false
        && ($verified === true || $verified === 'true')
        && isset($claims['exp']) && is_numeric($claims['exp']) && (int)$claims['exp'] > time()
        && is_string($tokenNonce) && hash_equals($nonce, $tokenNonce);
}

function activeAllowlistedUser(PDO $db, string $email): ?array
{
    $stmt = $db->prepare(
        'SELECT user_id, email, active FROM auth_users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([normalizeAuthEmail($email)]);
    $user = $stmt->fetch();

    return $user && validActiveFlag($user['active'] ?? null) && (int)$user['active'] === 1
        ? $user
        : null;
}

function validActiveFlag(mixed $active): bool
{
    return in_array($active, [0, 1, '0', '1'], true);
}

function requireAuth(): void
{
    if (empty($_SESSION['authenticated']) || empty($_SESSION['auth_user_id'])) {
        fail('Bạn cần đăng nhập để tiếp tục.', 401);
    }
}

function ensureCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requestOriginMatchesApplication(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' || $origin === 'null') {
        return false;
    }

    $config = sessionConfig();
    $expected = parse_url($config['base_url']);
    $actual = parse_url($origin);

    if (
        $expected === false ||
        $actual === false ||
        !empty($actual['user']) ||
        !empty($actual['pass']) ||
        !empty($actual['path']) ||
        !empty($actual['query']) ||
        !empty($actual['fragment'])
    ) {
        return false;
    }

    $scheme = strtolower($expected['scheme']);
    $defaultPort = $scheme === 'https' ? 443 : 80;

    return strtolower($actual['scheme'] ?? '') === $scheme
        && strtolower($actual['host'] ?? '') === strtolower($expected['host'])
        && (int)($actual['port'] ?? $defaultPort) === (int)($expected['port'] ?? $defaultPort);
}

function requireCsrfProtection(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if (
        !requestOriginMatchesApplication() ||
        !is_string($provided) ||
        !is_string($expected) ||
        $provided === '' ||
        $expected === '' ||
        !hash_equals($expected, $provided)
    ) {
        fail('Yêu cầu không hợp lệ hoặc phiên bảo mật đã hết hạn. Hãy tải lại trang.', 403);
    }
}

function enforceSessionFreshness(PDO $db, int $timeoutSeconds): bool
{
    $lastActivity = $_SESSION['last_activity'] ?? null;
    $userId = $_SESSION['auth_user_id'] ?? null;

    if (
        !is_int($lastActivity) ||
        time() - $lastActivity > $timeoutSeconds ||
        !is_numeric($userId)
    ) {
        destroySession();
        return false;
    }

    $stmt = $db->prepare('SELECT email, active FROM auth_users WHERE user_id = ?');
    $stmt->execute([(int)$userId]);
    $user = $stmt->fetch();

    if (
        !$user ||
        !validActiveFlag($user['active'] ?? null) ||
        (int)$user['active'] !== 1 ||
        normalizeAuthEmail((string)$user['email']) !== normalizeAuthEmail((string)($_SESSION['email'] ?? ''))
    ) {
        destroySession();
        return false;
    }

    $_SESSION['last_activity'] = time();
    return true;
}

function destroySession(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function logAuthDiagnostic(string $message): void
{
    error_log('[SQL-DC authentication] ' . $message);
}
