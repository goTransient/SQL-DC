<?php
declare(strict_types=1);

$integrationDriver = $GLOBALS['SQLDC_INTEGRATION_DRIVER'] ?? 'sqlite';
if ($integrationDriver === 'sqlite') {
    putenv('DEV_MODE=true');
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function copySourceTree(string $source, string $destination): void
{
    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        throw new RuntimeException('Unable to create disposable application directory.');
    }

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, ['.git', '.env', 'tests'], true)) {
            continue;
        }

        $from = $source . DIRECTORY_SEPARATOR . $entry;
        $to = $destination . DIRECTORY_SEPARATOR . $entry;

        if ($entry === 'data') {
            if (is_dir($from)) {
                mkdir($to, 0777, true);
                if (is_file($from . DIRECTORY_SEPARATOR . '.htaccess')) {
                    copy($from . DIRECTORY_SEPARATOR . '.htaccess', $to . DIRECTORY_SEPARATOR . '.htaccess');
                }
            }
            continue;
        }

        if (is_dir($from)) {
            copySourceTree($from, $to);
        } elseif (is_file($from)) {
            copy($from, $to);
        }
    }
}

function removeTestTree(string $directory): void
{
    $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sqldc-auth-test-';
    if (!str_starts_with($directory, $prefix) || !is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

function httpRequest(
    string $url,
    string $method = 'GET',
    ?array $json = null,
    array $headers = [],
    ?string $cookie = null,
    ?array $form = null
): array {
    $handle = curl_init($url);
    $responseHeaders = [];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        }
    ]);

    if ($cookie !== null) {
        $headers[] = 'Cookie: ' . $cookie;
    }

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        if ($form !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $form);
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($json ?? []));
        }
    }

    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($handle);
    if ($body === false) {
        $error = curl_error($handle);
        throw new RuntimeException('Local HTTP test request failed: ' . $error);
    }

    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
}

function responseJson(array $response): array
{
    $decoded = json_decode($response['body'], true);
    check(is_array($decoded), 'Endpoint response was not JSON.');
    return $decoded;
}

function writeTestSession(string $savePath, string $id, array $data): void
{
    session_save_path($savePath);
    session_id($id);
    if (!session_start()) {
        throw new RuntimeException('Unable to start test session.');
    }
    $_SESSION = $data;
    session_write_close();
}

function columnName(int $zeroBased): string
{
    $name = '';
    for ($number = $zeroBased + 1; $number > 0; $number = intdiv($number - 1, 26)) {
        $name = chr(65 + (($number - 1) % 26)) . $name;
    }
    return $name;
}

function worksheetXml(array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $rowIndex => $values) {
        $excelRow = $rowIndex + 1;
        $xml .= '<row r="' . $excelRow . '">';
        foreach ($values as $column => $value) {
            $cell = columnName($column) . $excelRow;
            $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t>'
                . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</t></is></c>';
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

function createWorkbook(string $file, string $householdCode = 'TST1', string $residentCode = 'TSTCD1'): void
{
    $zip = new ZipArchive();
    check($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Unable to create test workbook.');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
        . '<sheet name="Hộ gia đình" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Cư dân" sheetId="2" r:id="rId2"/>'
        . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', worksheetXml([
        ['Mã hộ', 'Địa chỉ', 'Tổ', 'Ghi chú'],
        [$householdCode, 'Địa chỉ kiểm thử', '1', '']
    ]));
    $zip->addFromString('xl/worksheets/sheet2.xml', worksheetXml([
        ['Mã CD', 'Mã hộ', 'Họ và tên'],
        [$residentCode, $householdCode, 'Cư dân kiểm thử']
    ]));
    $zip->close();
}

function withEnvironment(array $values, callable $callback): mixed
{
    $previous = [];
    foreach ($values as $name => $value) {
        $previous[$name] = getenv($name);
        if ($value === null) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }
}

function runProvisionCommand(string $appRoot, string $command, string $email): array
{
    $process = proc_open(
        [PHP_BINARY, $appRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'provision-user.php', $command, $email],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $appRoot
    );
    check(is_resource($process), 'Unable to start the provisioning CLI test.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sqldc-auth-test-' . bin2hex(random_bytes(8));
$siteRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'www';
$appRoot = $siteRoot . DIRECTORY_SEPARATOR . 'sql-dc';
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'sessions';
$serverLog = $temporaryRoot . DIRECTORY_SEPARATOR . 'server.log';
$routerPath = $siteRoot . DIRECTORY_SEPARATOR . 'router.php';
$server = null;
$serverLogHandle = null;
$failures = [];

require_once dirname(__DIR__) . '/api/db.php';
require_once dirname(__DIR__) . '/api/auth-config.php';
require_once dirname(__DIR__) . '/api/auth-helpers.php';

try {
    $invalidDriverRejected = withEnvironment(
        ['DEV_MODE' => 'sometimes'],
        static function (): bool {
            try {
                databaseDriver();
            } catch (RuntimeException) {
                return true;
            }
            return false;
        }
    );
    check($invalidDriverRejected, 'An invalid database driver was not rejected.');

    $mysqlConfigRejected = withEnvironment([
        'DEV_MODE' => 'false',
        'SQLDC_DB_HOST' => null,
        'SQLDC_DB_PORT' => null,
        'SQLDC_DB_NAME' => null,
        'SQLDC_DB_USER' => null,
        'SQLDC_DB_PASSWORD' => null
    ], static function (): bool {
        check(databaseDriver() === 'mysql', 'Production mode did not select MySQL.');
        try {
            mysqlDatabaseConfig();
        } catch (RuntimeException) {
            return true;
        }
        return false;
    });
    check($mysqlConfigRejected, 'Incomplete MySQL configuration was not rejected.');

    mkdir($temporaryRoot, 0777, true);
    mkdir($sessionPath, 0777, true);
    copySourceTree(dirname(__DIR__), $appRoot);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    check($socket !== false, 'Unable to allocate a local test port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr(strrchr($address, ':'), 1);
    $baseUrl = "http://127.0.0.1:$port/sql-dc";
    $origin = "http://127.0.0.1:$port";
    $url = $origin . '/sql-dc';

    putenv('SQLDC_BASE_URL=' . $baseUrl);
    putenv('SQLDC_GOOGLE_REDIRECT_URI=' . $baseUrl . '/api/google-callback.php');
    putenv('SQLDC_GOOGLE_CLIENT_ID=local-test-client.apps.googleusercontent.com');
    putenv('SQLDC_GOOGLE_CLIENT_SECRET=' . bin2hex(random_bytes(32)));

    $productionBaseUrl = 'https://sql.example.test/sql-dc';
    putenv('SQLDC_BASE_URL=' . $productionBaseUrl);
    putenv('SQLDC_GOOGLE_REDIRECT_URI=' . $productionBaseUrl . '/api/google-callback.php');
    $productionConfig = authConfig();
    check(
        $productionConfig['secure_cookie'] === true &&
        $productionConfig['cookie_path'] === '/sql-dc/' &&
        $productionConfig['redirect_uri'] === $productionBaseUrl . '/api/google-callback.php',
        'Configured HTTPS subdirectory deployment settings were not generated correctly.'
    );
    putenv('SQLDC_BASE_URL=' . $baseUrl);
    putenv('SQLDC_GOOGLE_REDIRECT_URI=' . $baseUrl . '/api/google-callback.php');

    file_put_contents($routerPath, '<?php' . PHP_EOL
        . 'session_save_path(' . var_export($sessionPath, true) . ');' . PHP_EOL
        . '$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";' . PHP_EOL
        . '$prefix = "/sql-dc";' . PHP_EOL
        . 'if ($path !== $prefix && !str_starts_with($path, $prefix . "/")) { http_response_code(404); exit; }' . PHP_EOL
        . '$relative = substr($path, strlen($prefix));' . PHP_EOL
        . 'if (($_GET["test_missing_oauth_config"] ?? "") === "1") {' . PHP_EOL
        . '    foreach (["SQLDC_GOOGLE_REDIRECT_URI", "SQLDC_GOOGLE_CLIENT_ID", "SQLDC_GOOGLE_CLIENT_SECRET"] as $name) { putenv($name); }' . PHP_EOL
        . '}' . PHP_EOL
        . 'if ($relative === "" || str_ends_with($relative, "/")) { $relative .= "index.php"; }' . PHP_EOL
        . 'if (str_contains($relative, "..")) { http_response_code(404); exit; }' . PHP_EOL
        . '$file = realpath(__DIR__ . $prefix . $relative);' . PHP_EOL
        . '$root = realpath(__DIR__ . $prefix);' . PHP_EOL
        . 'if (!$file || !$root || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) { http_response_code(404); exit; }' . PHP_EOL
        . 'if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== "php") { return false; }' . PHP_EOL
        . '$_SERVER["SCRIPT_FILENAME"] = $file;' . PHP_EOL
        . '$_SERVER["SCRIPT_NAME"] = $prefix . $relative;' . PHP_EOL
        . '$_SERVER["PHP_SELF"] = $prefix . $relative;' . PHP_EOL
        . 'chdir(dirname($file));' . PHP_EOL
        . 'require $file;' . PHP_EOL);

    if ($integrationDriver === 'sqlite') {
        $databaseFile = $appRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'dancu.db';
        $pdo = new PDO('sqlite:' . $databaseFile);
        foreach (schema() as $statement) {
            $pdo->exec($statement);
        }
    } else {
        $pdo = db();
        $schemaFile = $appRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'mysql-schema.sql';
        $schemaSql = file_get_contents($schemaFile);
        check($schemaSql !== false, 'Unable to read the MySQL test schema.');
        foreach (explode(';', $schemaSql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }
    $pdo->prepare('INSERT INTO auth_users (email) VALUES (?)')->execute(['approved@example.test']);
    foreach ([
        ['add', 'provisioned@example.test', 1],
        ['deactivate', 'provisioned@example.test', 0],
        ['activate', 'provisioned@example.test', 1]
    ] as [$commandName, $provisionedEmail, $expectedActive]) {
        $provisioned = runProvisionCommand($appRoot, $commandName, $provisionedEmail);
        check(
            $provisioned['status'] === 0 &&
            trim($provisioned['stdout']) === 'Account ' . $commandName . ' completed.',
            'CLI provisioning failed for an authorized test operation (exit '
                . $provisioned['status'] . '): ' . trim($provisioned['stderr'] . ' ' . $provisioned['stdout'])
        );
        $activeValue = $pdo->prepare('SELECT active FROM auth_users WHERE email = ?');
        $activeValue->execute([$provisionedEmail]);
        $active = $activeValue->fetchColumn();
        $activeValue->closeCursor();
        check(
            validActiveFlag($active) && (int)$active === $expectedActive,
            'Provisioning did not persist a valid active status.'
        );
    }
    if ($integrationDriver === 'mysql') {
        $pdo->prepare('UPDATE auth_users SET active = 2 WHERE email = ?')
            ->execute(['provisioned@example.test']);
        check(
            activeAllowlistedUser($pdo, 'provisioned@example.test') === null,
            'An invalid MySQL active flag was accepted.'
        );
        $pdo->prepare('UPDATE auth_users SET active = 1 WHERE email = ?')
            ->execute(['provisioned@example.test']);
    }

    $invalidClaims = [
        'iss' => 'https://accounts.google.com',
        'aud' => 'local-test-client.apps.googleusercontent.com',
        'sub' => 'synthetic-subject',
        'email' => 'approved@example.test',
        'email_verified' => false,
        'exp' => time() + 60,
        'nonce' => 'expected-nonce'
    ];
    check(!validGoogleIdTokenClaims($invalidClaims, 'local-test-client.apps.googleusercontent.com', 'expected-nonce'), 'Unverified Google email was accepted.');
    $invalidClaims['email_verified'] = true;
    $invalidClaims['aud'] = 'another-client';
    check(!validGoogleIdTokenClaims($invalidClaims, 'local-test-client.apps.googleusercontent.com', 'expected-nonce'), 'Wrong OAuth audience was accepted.');
    $invalidClaims['aud'] = 'local-test-client.apps.googleusercontent.com';
    $invalidClaims['nonce'] = 'wrong-nonce';
    check(!validGoogleIdTokenClaims($invalidClaims, 'local-test-client.apps.googleusercontent.com', 'expected-nonce'), 'Wrong OIDC nonce was accepted.');
    $invalidClaims['nonce'] = 'expected-nonce';
    $invalidClaims['exp'] = time() - 1;
    check(!validGoogleIdTokenClaims($invalidClaims, 'local-test-client.apps.googleusercontent.com', 'expected-nonce'), 'Expired Google ID token was accepted.');
    check(activeAllowlistedUser($pdo, ' APPROVED@example.test ') !== null, 'Normalized allowlist lookup failed.');
    $pdo->exec('UPDATE auth_users SET active = 0 WHERE user_id = 1');
    check(activeAllowlistedUser($pdo, 'approved@example.test') === null, 'Inactive account passed the allowlist check.');
    $pdo->exec('UPDATE auth_users SET active = 1 WHERE user_id = 1');
    $duplicateRejected = false;
    try {
        $pdo->prepare('INSERT INTO auth_users (email) VALUES (?)')->execute(['APPROVED@EXAMPLE.TEST']);
    } catch (PDOException) {
        $duplicateRejected = true;
    }
    check($duplicateRejected, 'Email uniqueness did not ignore case.');

    $serverLogHandle = fopen($serverLog, 'ab');
    $command = [
        PHP_BINARY,
        '-S',
        '127.0.0.1:' . $port,
        '-t',
        $siteRoot,
        'router.php'
    ];
    $server = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => $serverLogHandle,
        2 => $serverLogHandle
    ], $pipes, $siteRoot);
    check(is_resource($server), 'Unable to start the local PHP test server.');
    fclose($pipes[0]);

    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($probe !== false) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'Local PHP test server did not start.');

    $status = httpRequest($url . '/api/current-user.php');
    check($status['status'] === 401 && responseJson($status)['ok'] === false, 'Unauthenticated session status was not a consistent 401.');

    $page = httpRequest($url . '/');
    check(
        $page['status'] === 200 &&
        str_contains($page['body'], 'id="main"') &&
        str_contains($page['body'], 'class="app auth-only"') &&
        str_contains($page['body'], 'href="api/google-login.php"'),
        'Initial login screen or application shell failed at a subdirectory base path.'
    );

    $protectedActions = [
        ['data', 'GET'],
        ['household.save', 'POST'],
        ['household.delete', 'POST'],
        ['resident.save', 'POST'],
        ['resident.delete', 'POST'],
        ['association.save', 'POST'],
        ['association.delete', 'POST'],
        ['activity.save', 'POST'],
        ['activity.delete', 'POST'],
        ['contribution.save', 'POST'],
        ['import', 'POST'],
        ['not-a-real-action', 'POST']
    ];
    foreach ($protectedActions as [$action, $method]) {
        $response = httpRequest(
            $url . '/api/api.php?action=' . rawurlencode($action),
            $method
        );
        check($response['status'] === 401, 'Unauthenticated action was not blocked: ' . $action);
        check(responseJson($response)['ok'] === false, 'Unauthenticated action did not use the common JSON error shape: ' . $action);
    }

    $login = httpRequest($url . '/api/google-login.php');
    check($login['status'] === 302, 'Google login did not redirect.');
    $loginLocation = $login['headers']['location'] ?? '';
    check(str_contains($loginLocation, rawurlencode($baseUrl . '/api/google-callback.php')), 'OAuth callback URL omitted the configured subdirectory.');
    parse_str((string)parse_url($loginLocation, PHP_URL_QUERY), $loginQuery);
    check(preg_match('/^[a-f0-9]{64}$/', $loginQuery['state'] ?? '') === 1, 'OAuth state was not a 256-bit random token.');
    check(preg_match('/^[a-f0-9]{64}$/', $loginQuery['nonce'] ?? '') === 1, 'OIDC nonce was not a 256-bit random token.');
    check(($loginQuery['code_challenge_method'] ?? '') === 'S256' && preg_match('/^[A-Za-z0-9_-]{43}$/', $loginQuery['code_challenge'] ?? '') === 1, 'PKCE S256 challenge was not configured.');
    $cookieHeader = strtolower($login['headers']['set-cookie'] ?? '');
    check(str_contains($cookieHeader, 'path=/sql-dc/'), 'Session cookie did not use the configured application path.');
    check(str_contains($cookieHeader, 'httponly'), 'Session cookie is not HttpOnly.');
    check(str_contains($cookieHeader, 'samesite=lax'), 'Session cookie does not allow the OAuth redirect.');

    $callbackCookie = explode(';', $login['headers']['set-cookie'] ?? '', 2)[0];
    $badState = httpRequest(
        $url . '/api/google-callback.php?state=invalid&code=unused',
        'GET',
        null,
        [],
        $callbackCookie
    );
    check($badState['status'] === 400, 'Callback did not reject invalid OAuth state.');
    $deniedLogin = httpRequest($url . '/api/google-login.php');
    $deniedCookie = explode(';', $deniedLogin['headers']['set-cookie'] ?? '', 2)[0];
    parse_str((string)parse_url($deniedLogin['headers']['location'] ?? '', PHP_URL_QUERY), $oauthQuery);
    $providerDenied = httpRequest(
        $url . '/api/google-callback.php?state=' . rawurlencode((string)($oauthQuery['state'] ?? ''))
            . '&error=access_denied',
        'GET',
        null,
        [],
        $deniedCookie
    );
    check($providerDenied['status'] === 400, 'OAuth provider denial was not handled safely.');

    $appSessionId = bin2hex(random_bytes(16));
    $csrf = bin2hex(random_bytes(32));
    $sessionData = [
        'authenticated' => true,
        'auth_user_id' => 1,
        'email' => 'approved@example.test',
        'name' => 'Test Operator',
        'picture' => null,
        'last_activity' => time(),
        'csrf_token' => $csrf
    ];
    writeTestSession($sessionPath, $appSessionId, $sessionData);
    $sessionCookie = session_name() . '=' . $appSessionId;
    $csrfHeaders = ['Origin: ' . $origin, 'X-CSRF-Token: ' . $csrf];
    $apiUrl = $url . '/api/api.php?action=';

    $authenticatedStatus = httpRequest($url . '/api/current-user.php', 'GET', null, [], $sessionCookie);
    $statusData = responseJson($authenticatedStatus);
    check(
        $authenticatedStatus['status'] === 200 &&
        ($statusData['csrfToken'] ?? '') === $csrf &&
        ($statusData['user']['email'] ?? '') === 'approved@example.test' &&
        !isset($statusData['role']),
        'Authenticated session status did not return only the expected identity and CSRF data.'
    );
    $json = httpRequest($apiUrl . 'data', 'GET', null, [], $sessionCookie);
    check($json['status'] === 200 && responseJson($json)['ok'] === true, 'Active allowlisted session could not read application data.');

    $badJsonCsrf = httpRequest($apiUrl . 'household.save', 'POST', ['address' => 'blocked'], ['Origin: ' . $origin], $sessionCookie);
    check($badJsonCsrf['status'] === 403, 'JSON mutation without CSRF token was not rejected.');
    $badOrigin = httpRequest($apiUrl . 'household.save', 'POST', ['address' => 'blocked'], ['Origin: https://attacker.example', 'X-CSRF-Token: ' . $csrf], $sessionCookie);
    check($badOrigin['status'] === 403, 'Mutation with a foreign Origin was not rejected.');
    $badMultipart = httpRequest($apiUrl . 'import', 'POST', null, ['Origin: ' . $origin], $sessionCookie, ['file' => 'not-a-workbook']);
    check($badMultipart['status'] === 403, 'Multipart mutation without CSRF token was not rejected.');

    $household = httpRequest($apiUrl . 'household.save', 'POST', ['address' => 'Test street'], $csrfHeaders, $sessionCookie);
    check($household['status'] === 200, 'Household save failed with valid auth and CSRF.');
    $householdId = (int)responseJson($household)['household_id'];
    $householdUpdate = httpRequest($apiUrl . 'household.save', 'POST', [
        'household_id' => $householdId,
        'address' => 'Updated test street'
    ], $csrfHeaders, $sessionCookie);
    check($householdUpdate['status'] === 200, 'Household update failed.');

    $association = httpRequest($apiUrl . 'association.save', 'POST', ['name' => 'Test association'], $csrfHeaders, $sessionCookie);
    check($association['status'] === 200, 'Association save failed.');
    $associationId = (int)responseJson($association)['association_id'];
    $associationUpdate = httpRequest($apiUrl . 'association.save', 'POST', [
        'association_id' => $associationId,
        'name' => 'Updated test association',
        'note' => 'Updated note'
    ], $csrfHeaders, $sessionCookie);
    check($associationUpdate['status'] === 200, 'Association update failed.');

    $activity = httpRequest($apiUrl . 'activity.save', 'POST', [
        'name' => 'Test activity',
        'activity_date' => '2026-01-01',
        'end_date' => '2026-01-02',
        'amount_suggested' => 10
    ], $csrfHeaders, $sessionCookie);
    check($activity['status'] === 200, 'Activity save failed.');
    $activityId = (int)responseJson($activity)['activity_id'];
    $activityUpdate = httpRequest($apiUrl . 'activity.save', 'POST', [
        'activity_id' => $activityId,
        'name' => 'Updated test activity',
        'activity_date' => '2026-01-01',
        'end_date' => '2026-01-03',
        'amount_suggested' => 15
    ], $csrfHeaders, $sessionCookie);
    check($activityUpdate['status'] === 200, 'Activity update failed.');

    $resident = httpRequest($apiUrl . 'resident.save', 'POST', [
        'household_id' => $householdId,
        'full_name' => 'Test Resident',
        'associationIds' => [$associationId, $associationId]
    ], $csrfHeaders, $sessionCookie);
    check($resident['status'] === 200, 'Resident save failed.');
    $residentId = (int)responseJson($resident)['resident_id'];
    $residentUpdate = httpRequest($apiUrl . 'resident.save', 'POST', [
        'resident_id' => $residentId,
        'household_id' => $householdId,
        'full_name' => 'Updated Test Resident',
        'associationIds' => [$associationId]
    ], $csrfHeaders, $sessionCookie);
    check($residentUpdate['status'] === 200, 'Resident update failed.');

    $contribution = httpRequest($apiUrl . 'contribution.save', 'POST', [
        'household_id' => $householdId,
        'activity_id' => $activityId,
        'paid' => 1,
        'amount' => 10,
        'notes' => ''
    ], $csrfHeaders, $sessionCookie);
    check($contribution['status'] === 200, 'Contribution save failed.');
    $contributionUpdate = httpRequest($apiUrl . 'contribution.save', 'POST', [
        'household_id' => $householdId,
        'activity_id' => $activityId,
        'paid' => 0,
        'amount' => 12,
        'notes' => 'Updated contribution'
    ], $csrfHeaders, $sessionCookie);
    check($contributionUpdate['status'] === 200, 'Contribution upsert failed.');

    $beforeImport = responseJson(httpRequest($apiUrl . 'data', 'GET', null, [], $sessionCookie));
    check(
        $beforeImport['households'][0]['address'] === 'Updated test street' &&
        $beforeImport['residents'][0]['full_name'] === 'Updated Test Resident' &&
        $beforeImport['associations'][0]['name'] === 'Updated test association' &&
        $beforeImport['activities'][0]['name'] === 'Updated test activity' &&
        count($beforeImport['residentAssociations']) === 1 &&
        count($beforeImport['householdActivities']) === 1 &&
        (int)$beforeImport['householdActivities'][0]['amount'] === 12 &&
        (int)$beforeImport['householdActivities'][0]['paid'] === 0,
        'CRUD updates, duplicate association handling, or contribution upsert did not persist correctly.'
    );

    $workbookPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'synthetic.xlsx';
    createWorkbook($workbookPath);
    $import = httpRequest($apiUrl . 'import', 'POST', null, $csrfHeaders, $sessionCookie, [
        'file' => new CURLFile($workbookPath, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'synthetic.xlsx')
    ]);
    check($import['status'] === 200, 'Synthetic workbook import failed: ' . $import['body']);
    $afterImport = responseJson(httpRequest($apiUrl . 'data', 'GET', null, [], $sessionCookie));
    check(count($afterImport['households']) === 1 && $afterImport['households'][0]['code'] === 'TST1', 'Import did not replace only the disposable household data.');
    check(count($afterImport['residents']) === 1 && $afterImport['residents'][0]['full_name'] === 'Cư dân kiểm thử', 'Import did not load the synthetic resident.');
    check(count($afterImport['associations']) === 1 && count($afterImport['activities']) === 1, 'Import did not preserve association/activity definitions.');
    check(count($afterImport['residentAssociations']) === 0 && count($afterImport['householdActivities']) === 0, 'Import did not clear links belonging to replaced records.');

    foreach ([
        ['households', str_repeat('H', MAX_CODE_LENGTH + 1), 'TSTCD1'],
        ['residents', 'TST1', str_repeat('R', MAX_CODE_LENGTH + 1)]
    ] as [$oversizedType, $householdCode, $residentCode]) {
        $invalidWorkbook = $temporaryRoot . DIRECTORY_SEPARATOR . 'oversized-' . $oversizedType . '.xlsx';
        createWorkbook($invalidWorkbook, $householdCode, $residentCode);
        $invalidImport = httpRequest($apiUrl . 'import', 'POST', null, $csrfHeaders, $sessionCookie, [
            'file' => new CURLFile($invalidWorkbook, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'oversized.xlsx')
        ]);
        check($invalidImport['status'] === 400, 'Import accepted an oversized ' . $oversizedType . ' code.');
        $preservedData = responseJson(httpRequest($apiUrl . 'data', 'GET', null, [], $sessionCookie));
        check(
            count($preservedData['households']) === 1 &&
            $preservedData['households'][0]['code'] === 'TST1' &&
            count($preservedData['residents']) === 1,
            'Rejected import did not roll back the prior business records.'
        );
    }

    foreach ([
        ['resident.delete', ['resident_id' => $residentId]],
        ['household.delete', ['household_id' => $householdId]],
        ['association.delete', ['association_id' => $associationId]],
        ['activity.delete', ['activity_id' => $activityId]]
    ] as [$action, $payload]) {
        $deleted = httpRequest($apiUrl . $action, 'POST', $payload, $csrfHeaders, $sessionCookie);
        check($deleted['status'] === 200, 'Delete action failed with valid auth and CSRF: ' . $action);
    }

    $pdo->exec('UPDATE auth_users SET active = 0 WHERE user_id = 1');
    $deactivated = httpRequest($apiUrl . 'data', 'GET', null, [], $sessionCookie);
    check($deactivated['status'] === 401, 'Deactivated account retained access through an existing session.');

    $pdo->exec('UPDATE auth_users SET active = 1 WHERE user_id = 1');
    $sessionData['last_activity'] = time() - (3 * 60 * 60 + 1);
    writeTestSession($sessionPath, $appSessionId, $sessionData);
    $expired = httpRequest($apiUrl . 'data', 'GET', null, [], $sessionCookie);
    check($expired['status'] === 401, 'Idle session was not expired.');

    $sessionData['last_activity'] = time();
    writeTestSession($sessionPath, $appSessionId, $sessionData);
    $missingLogoutCsrf = httpRequest($url . '/api/google-logout.php', 'POST', [], ['Origin: ' . $origin], $sessionCookie);
    check($missingLogoutCsrf['status'] === 403, 'Logout without CSRF token was not rejected.');
    $logout = httpRequest($url . '/api/google-logout.php', 'POST', [], $csrfHeaders, $sessionCookie);
    check($logout['status'] === 200 && responseJson($logout)['ok'] === true, 'Valid CSRF-protected logout failed.');
    $afterLogout = httpRequest($apiUrl . 'data', 'GET', null, [], $sessionCookie);
    check($afterLogout['status'] === 401, 'Logged-out session retained business API access.');

    $missingOAuthStatus = httpRequest($url . '/api/current-user.php?test_missing_oauth_config=1');
    check(
        $missingOAuthStatus['status'] === 401 && responseJson($missingOAuthStatus)['ok'] === false,
        'Unauthenticated session status depended on Google OAuth-only configuration.'
    );
    $incompleteOAuthLogin = httpRequest($url . '/api/google-login.php?test_missing_oauth_config=1');
    check(
        $incompleteOAuthLogin['status'] === 500,
        'Google login did not reject incomplete OAuth configuration.'
    );

    fwrite(STDOUT, "PASS: $integrationDriver auth/API/CSRF/session/CRUD/contribution/import/provisioning integration tests.\n");
    fwrite(STDOUT, "NOTE: OAuth provider token exchange and successful Google identity validation require real Google credentials and were not simulated.\n");
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    if (is_file($serverLog)) {
        fwrite(STDERR, "Local PHP server log:\n" . file_get_contents($serverLog));
    }
} finally {
    if (isset($activeValue) && $activeValue instanceof PDOStatement) {
        $activeValue->closeCursor();
        $activeValue = null;
    }
    if (isset($pdo)) {
        $pdo = null;
    }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_resource($serverLogHandle)) {
        fclose($serverLogHandle);
    }
    removeTestTree($temporaryRoot);
}

exit($failures ? 1 : 0);
