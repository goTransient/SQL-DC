<?php
declare(strict_types=1);

/*
 * DOMIVA - database + shared helpers.
 * The database file and its tables are created automatically on first use.
 */

const DATA_DIR = __DIR__ . '/../data';
const DB_FILE  = DATA_DIR . '/dancu.db';

const STATUSES  = ['Đang ở', 'Tạm vắng', 'Đã chuyển đi', 'Đã mất'];
const RELATIONS = ['', 'Chủ hộ', 'Vợ / Chồng', 'Con', 'Cha / Mẹ', 'Ông / Bà', 'Cháu', 'Khác'];

function schema(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS households (
            household_id INTEGER PRIMARY KEY AUTOINCREMENT,
            code         TEXT NOT NULL UNIQUE,
            address      TEXT NOT NULL DEFAULT '',
            group_number INTEGER,
            notes        TEXT NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS residents (
            resident_id  INTEGER PRIMARY KEY AUTOINCREMENT,
            code         TEXT NOT NULL UNIQUE,
            household_id INTEGER NOT NULL REFERENCES households(household_id),
            full_name    TEXT NOT NULL,
            gender       TEXT NOT NULL DEFAULT '',
            dob          TEXT NOT NULL DEFAULT '',
            birth_year   INTEGER,
            ethnicity    TEXT NOT NULL DEFAULT '',
            relation     TEXT NOT NULL DEFAULT '',
            cccd         TEXT NOT NULL DEFAULT '',
            phone        TEXT NOT NULL DEFAULT '',
            email        TEXT NOT NULL DEFAULT '',
            job          TEXT NOT NULL DEFAULT '',
            is_voter     INTEGER NOT NULL DEFAULT 0,
            status       TEXT NOT NULL DEFAULT 'Đang ở',
            notes        TEXT NOT NULL DEFAULT '',
            source       TEXT NOT NULL DEFAULT '',
            check_note   TEXT NOT NULL DEFAULT ''
        )",
        "CREATE INDEX IF NOT EXISTS idx_residents_household ON residents(household_id)",
        "CREATE TABLE IF NOT EXISTS associations (
            association_id INTEGER PRIMARY KEY AUTOINCREMENT,
            name           TEXT NOT NULL,
            note           TEXT NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS resident_associations (
            resident_id    INTEGER NOT NULL REFERENCES residents(resident_id) ON DELETE CASCADE,
            association_id INTEGER NOT NULL REFERENCES associations(association_id) ON DELETE CASCADE,
            PRIMARY KEY (resident_id, association_id)
        )",
        "CREATE TABLE IF NOT EXISTS activities (
            activity_id      INTEGER PRIMARY KEY AUTOINCREMENT,
            name             TEXT NOT NULL,
            activity_date    TEXT NOT NULL DEFAULT '',
            amount_suggested INTEGER NOT NULL DEFAULT 0,
            notes            TEXT NOT NULL DEFAULT ''
        )",
        "CREATE TABLE IF NOT EXISTS household_activities (
            household_id INTEGER NOT NULL REFERENCES households(household_id) ON DELETE CASCADE,
            activity_id  INTEGER NOT NULL REFERENCES activities(activity_id) ON DELETE CASCADE,
            paid         INTEGER NOT NULL DEFAULT 0,
            amount       INTEGER NOT NULL DEFAULT 0,
            notes        TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (household_id, activity_id)
        )"
    ];
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true)) {
        throw new RuntimeException('Không tạo được thư mục data/. Kiểm tra quyền ghi của thư mục ứng dụng.');
    }

    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    foreach (schema() as $sql) {
        $pdo->exec($sql);
    }

    return $pdo;
}

/* ---------- JSON responses ---------- */

function respond(array $data, int $status): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function ok(array $data = []): never
{
    respond(['ok' => true] + $data, 200);
}

function fail(string $message, int $status = 400): never
{
    respond(['ok' => false, 'error' => $message], $status);
}

/* ---------- Input helpers ---------- */

function body(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '[]', true);

    return is_array($data) ? $data : [];
}

function str(mixed $value): string
{
    return trim(preg_replace('/\s+/u', ' ', (string)($value ?? '')) ?? '');
}

/* Multi-line text (notes): keep line breaks, trim the ends. */
function text(mixed $value): string
{
    return trim(str_replace("\r\n", "\n", (string)($value ?? '')));
}

/* Returns 'YYYY-MM-DD' or '' */
function parseDate(mixed $value): string
{
    $value = str($value);

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $value, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } else {
        return '';
    }

    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
}

/*
 * Cleans one resident record, from the form or from an Excel row.
 * The birth year always follows the date of birth when both are given.
 */
function cleanResident(array $d): array
{
    $dob = parseDate($d['dob'] ?? '');
    $year = $dob !== '' ? (int)substr($dob, 0, 4) : (int)str($d['birth_year'] ?? '');

    if ($year < 1900 || $year > (int)date('Y')) {
        $year = null;
    }

    $gender = ['nam' => 'Nam', 'nữ' => 'Nữ', 'nu' => 'Nữ'][strtolower(str($d['gender'] ?? ''))] ?? '';

    $status = str($d['status'] ?? '');
    if (!in_array($status, STATUSES, true)) {
        $status = 'Đang ở';
    }

    $relation = str($d['relation'] ?? '');
    if (!in_array($relation, RELATIONS, true)) {
        $relation = 'Khác';
    }

    $voter = $d['is_voter'] ?? false;
    $voter = $voter === true || in_array(strtolower(str($voter)), ['1', 'true', 'có', 'co', 'x', 'on'], true);

    return [
        'household_id' => (int)($d['household_id'] ?? 0),
        'full_name'    => str($d['full_name'] ?? ''),
        'gender'       => $gender,
        'dob'          => $dob,
        'birth_year'   => $year,
        'ethnicity'    => str($d['ethnicity'] ?? ''),
        'relation'     => $relation,
        'cccd'         => str($d['cccd'] ?? ''),
        'phone'        => str($d['phone'] ?? ''),
        'email'        => str($d['email'] ?? ''),
        'job'          => str($d['job'] ?? ''),
        'is_voter'     => $voter ? 1 : 0,
        'status'       => $status,
        'notes'        => text($d['notes'] ?? ''),
        'source'       => str($d['source'] ?? ''),
        'check_note'   => text($d['check_note'] ?? '')
    ];
}

/* Next code such as H161 or CD0678 */
function nextCode(PDO $db, string $table, string $prefix, int $pad): string
{
    $start = strlen($prefix) + 1;

    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTR(code, $start) AS INTEGER)), 0)
         FROM $table WHERE code LIKE ?"
    );
    $stmt->execute([$prefix . '%']);

    return $prefix . str_pad((string)((int)$stmt->fetchColumn() + 1), $pad, '0', STR_PAD_LEFT);
}

/* Throws a user-facing error if the row does not exist. */
function mustExist(PDO $db, string $table, string $idColumn, int $id, string $message): void
{
    $stmt = $db->prepare("SELECT 1 FROM $table WHERE $idColumn = ?");
    $stmt->execute([$id]);

    if (!$stmt->fetchColumn()) {
        fail($message, 404);
    }
}
