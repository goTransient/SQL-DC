<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ob_start();

try {
    require_once __DIR__ . '/session.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        fail('Hãy gửi yêu cầu này bằng POST.', 405);
    }

    requireAuth();
    requireCsrfProtection();
    destroySession();
    ok();
} catch (Throwable $e) {
    logAuthDiagnostic($e->getMessage());
    fail('Lỗi máy chủ. Vui lòng thử lại sau.', 500);
}
