<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ob_start();

try {
    require_once __DIR__ . '/session.php';

    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    requireAuth();

    ok([
        'user' => [
            'name' => (string)($_SESSION['name'] ?? ''),
            'email' => (string)($_SESSION['email'] ?? ''),
            'picture' => $_SESSION['picture'] ?? null
        ],
        'csrfToken' => ensureCsrfToken()
    ]);
} catch (Throwable $e) {
    logAuthDiagnostic(
        $e instanceof PDOException
            ? 'Session status database operation failed.'
            : $e->getMessage()
    );
    fail('Lỗi máy chủ. Vui lòng thử lại sau.', 500);
}
