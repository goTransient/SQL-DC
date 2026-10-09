<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';

function usage(): never
{
    fwrite(STDERR, "Usage:\n  php api/provision-user.php add <email>\n  php api/provision-user.php activate <email>\n  php api/provision-user.php deactivate <email>\n");
    exit(2);
}

$command = $argv[1] ?? '';
$email = filter_var(normalizeProvisionEmail($argv[2] ?? ''), FILTER_VALIDATE_EMAIL);

if (!in_array($command, ['add', 'activate', 'deactivate'], true) || $email === false) {
    usage();
}

$pdo = db();

if ($command === 'add') {
    $stmt = $pdo->prepare('INSERT INTO auth_users (email) VALUES (?)');
    $stmt->execute([$email]);
} else {
    $lookup = $pdo->prepare('SELECT 1 FROM auth_users WHERE email = ?');
    $lookup->execute([$email]);

    if (!$lookup->fetchColumn()) {
        fwrite(STDERR, "No allowlisted account matched that email.\n");
        exit(1);
    }

    $active = $command === 'activate' ? 1 : 0;
    if (!in_array($active, [0, 1], true)) {
        throw new RuntimeException('Invalid active status.');
    }

    $stmt = $pdo->prepare('UPDATE auth_users SET active = ? WHERE email = ?');
    $stmt->execute([$active, $email]);
}

fwrite(STDOUT, "Account " . $command . " completed.\n");

function normalizeProvisionEmail(string $email): string
{
    return strtolower(trim($email));
}
