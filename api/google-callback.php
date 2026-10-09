<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ob_start();

function googleRequest(string $url, string $method, array $data = [], array $headers = []): ?array
{
    $handle = curl_init($url);
    if ($handle === false) {
        logAuthDiagnostic('Unable to initialize the Google HTTPS request.');
        return null;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($data);
    }

    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlFailed = $body === false;

    if ($curlFailed || $status < 200 || $status >= 300 || !is_string($body)) {
        logAuthDiagnostic('Google HTTPS request failed (HTTP ' . $status . ').');
        return null;
    }

    $response = json_decode($body, true);
    return is_array($response) ? $response : null;
}

function callbackFailure(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="vi"><meta charset="utf-8"><title>Đăng nhập</title>'
        . '<body><main><h1>Không thể đăng nhập</h1><p>'
        . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</p><p><a href="../">Quay lại Sổ Quản Lý Dân Cư</a></p></main></body></html>';
    exit;
}

try {
    require_once __DIR__ . '/session.php';
    $AUTH_CONFIG = authConfig();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        exit('Phương thức không được hỗ trợ.');
    }

    $state = $_GET['state'] ?? '';
    $expectedState = $_SESSION['oauth_state'] ?? '';
    $nonce = $_SESSION['oauth_nonce'] ?? '';
    $verifier = $_SESSION['oauth_verifier'] ?? '';
    $startedAt = $_SESSION['oauth_started_at'] ?? 0;

    unset(
        $_SESSION['oauth_state'],
        $_SESSION['oauth_nonce'],
        $_SESSION['oauth_verifier'],
        $_SESSION['oauth_started_at']
    );

    if (
        !is_string($state) ||
        !is_string($expectedState) ||
        $state === '' ||
        $expectedState === '' ||
        !hash_equals($expectedState, $state) ||
        !is_int($startedAt) ||
        time() - $startedAt > 600 ||
        !is_string($nonce) ||
        !is_string($verifier)
    ) {
        callbackFailure(400, 'Yêu cầu đăng nhập đã hết hạn hoặc không hợp lệ. Hãy thử lại.');
    }

    if (isset($_GET['error']) || !isset($_GET['code']) || !is_string($_GET['code'])) {
        callbackFailure(400, 'Đăng nhập Google đã bị hủy hoặc không được hoàn tất.');
    }

    $token = googleRequest(
        'https://oauth2.googleapis.com/token',
        'POST',
        [
            'code' => $_GET['code'],
            'client_id' => $AUTH_CONFIG['client_id'],
            'client_secret' => $AUTH_CONFIG['client_secret'],
            'redirect_uri' => $AUTH_CONFIG['redirect_uri'],
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier
        ],
        ['Content-Type: application/x-www-form-urlencoded']
    );

    if (!$token || empty($token['id_token']) || !is_string($token['id_token'])) {
        callbackFailure(502, 'Google không xác nhận được tài khoản. Vui lòng thử lại sau.');
    }

    $claims = googleRequest(
        'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($token['id_token']),
        'GET'
    );

    if (
        !$claims ||
        !validGoogleIdTokenClaims($claims, $AUTH_CONFIG['client_id'], $nonce)
    ) {
        logAuthDiagnostic('Google identity token validation failed.');
        callbackFailure(401, 'Không xác minh được danh tính Google hoặc địa chỉ email.');
    }

    $user = activeAllowlistedUser(db(), $claims['email']);
    if (!$user) {
        logAuthDiagnostic('Login rejected for an unapproved or inactive account.');
        callbackFailure(403, 'Tài khoản này chưa được cấp quyền truy cập SQL-DC.');
    }

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to regenerate the authenticated session ID.');
    }

    $_SESSION = [
        'authenticated' => true,
        'auth_user_id' => (int)$user['user_id'],
        'email' => normalizeAuthEmail((string)$user['email']),
        'name' => is_string($claims['name'] ?? null) ? $claims['name'] : normalizeAuthEmail((string)$user['email']),
        'picture' => is_string($claims['picture'] ?? null) ? $claims['picture'] : null,
        'last_activity' => time(),
        'csrf_token' => bin2hex(random_bytes(32))
    ];

    $update = db()->prepare('UPDATE auth_users SET last_login = CURRENT_TIMESTAMP WHERE user_id = ?');
    $update->execute([(int)$user['user_id']]);

    header('Cache-Control: no-store');
    header('Location: ' . $AUTH_CONFIG['base_url'] . '/');
    exit;
} catch (Throwable $e) {
    logAuthDiagnostic(
        $e instanceof PDOException
            ? 'OAuth database operation failed.'
            : $e->getMessage()
    );

    if (!headers_sent()) {
        callbackFailure(500, 'Đã xảy ra lỗi máy chủ khi đăng nhập. Vui lòng thử lại sau.');
    }
}
