<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ob_start();

try {
    require_once __DIR__ . '/session.php';
    $AUTH_CONFIG = authConfig();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        exit('Phương thức không được hỗ trợ.');
    }

    $state = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(32));
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_nonce'] = $nonce;
    $_SESSION['oauth_verifier'] = $verifier;
    $_SESSION['oauth_started_at'] = time();

    $email = filter_var($_GET['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $params = [
        'client_id' => $AUTH_CONFIG['client_id'],
        'redirect_uri' => $AUTH_CONFIG['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256'
    ];

    if ($email !== false) {
        $params['login_hint'] = $email;
    }

    header('Cache-Control: no-store');
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    exit;
} catch (Throwable $e) {
    logAuthDiagnostic($e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Không thể bắt đầu đăng nhập. Vui lòng liên hệ quản trị viên.');
}
