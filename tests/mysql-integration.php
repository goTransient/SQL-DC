<?php
declare(strict_types=1);

function mysqlTestFail(string $message, int $status = 1): never
{
    fwrite($status === 0 ? STDOUT : STDERR, $message . "\n");
    exit($status);
}

if (PHP_SAPI !== 'cli') {
    mysqlTestFail('MySQL integration tests are CLI-only.');
}

if (getenv('SQLDC_RUN_MYSQL_INTEGRATION') !== '1') {
    mysqlTestFail('SKIP: set SQLDC_RUN_MYSQL_INTEGRATION=1 to opt in to MySQL integration tests.', 0);
}

if (!extension_loaded('pdo_mysql')) {
    mysqlTestFail('SKIP: the PDO MySQL extension is unavailable.', 0);
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'db.php';

try {
    if (databaseDriver() !== 'mysql') {
        mysqlTestFail('SKIP: set DEV_MODE=false to select a MySQL test database.', 0);
    }
    if (getenv('SQLDC_MYSQL_TEST_DISPOSABLE') !== 'YES') {
        mysqlTestFail('Refusing to run: explicitly confirm the dedicated disposable database with SQLDC_MYSQL_TEST_DISPOSABLE=YES.');
    }

    $config = mysqlDatabaseConfig();
    if (!in_array(strtolower($config['host']), ['localhost', '127.0.0.1', '::1'], true)) {
        mysqlTestFail('Refusing to connect: MySQL integration tests permit loopback hosts only.');
    }
    if (
        preg_match('/^sqldc_test_[a-z0-9_]+$/i', $config['name']) !== 1 ||
        preg_match('/(^|[_-])(prod|production|live|alwaysdata)([_-]|$)/i', $config['name'])
    ) {
        mysqlTestFail('Refusing to run: the database name must be a non-production sqldc_test_* name.');
    }

    $pdo = db();
    $tableCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchColumn();
    if ($tableCount !== 0) {
        mysqlTestFail('Refusing to run: the dedicated MySQL test database must contain no tables or views.');
    }

    $GLOBALS['SQLDC_INTEGRATION_DRIVER'] = 'mysql';
    require __DIR__ . DIRECTORY_SEPARATOR . 'auth-integration.php';
} catch (Throwable $exception) {
    mysqlTestFail('MySQL integration tests could not run: ' . $exception->getMessage());
}
