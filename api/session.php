<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-config.php';
require_once __DIR__ . '/auth-helpers.php';

$SESSION_CONFIG = sessionConfig();
$SESSION_TIMEOUT = 60 * 60 * 3;

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $SESSION_CONFIG['cookie_path'],
        'secure' => $SESSION_CONFIG['secure_cookie'],
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

if (!empty($_SESSION['authenticated'])) {
    enforceSessionFreshness(db(), $SESSION_TIMEOUT);
}
