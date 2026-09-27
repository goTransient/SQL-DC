<?php
declare(strict_types=1);

/*
 * DOMIVA - the only API endpoint.
 *
 *   GET  api.php?action=data                everything the page needs, in one call
 *   POST api.php?action=household.save      {household_id?, address, group_number, notes}
 *   POST api.php?action=household.delete    {household_id}
 *   POST api.php?action=resident.save       {resident_id?, ...fields, associationIds: []}
 *   POST api.php?action=resident.delete     {resident_id}
 *   POST api.php?action=association.save    {association_id?, name, note}
 *   POST api.php?action=association.delete  {association_id}
 *   POST api.php?action=activity.save       {activity_id?, name, activity_date, end_date, amount_suggested, notes}
 *   POST api.php?action=activity.delete     {activity_id}
 *   POST api.php?action=contribution.save   {household_id, activity_id, paid, amount, notes}
 *   POST api.php?action=import              multipart "file" = standard .xlsx
 *
 * A missing or 0 id in a *.save call creates a new record.
 * Every response is JSON: {ok: true, ...} or {ok: false, error: "..."}
 */

ini_set('display_errors', '0');
ob_start();

require __DIR__ . '/db.php';

/* Excel import: header text -> field. Defined here, before the dispatch below,
 * because a top-level const only exists once PHP has executed its line. */
const HOUSEHOLD_COLUMNS = [
    'Mã hộ'   => 'code',
    'Địa chỉ' => 'address',
    'Tổ'      => 'group_number',
    'Ghi chú' => 'notes'
];

const RESIDENT_COLUMNS = [
    'Mã CD'              => 'code',
    'Mã hộ'              => 'household_code',
    'Họ và tên'          => 'full_name',
    'Giới tính'          => 'gender',
    'Ngày sinh'          => 'dob',
    'Năm sinh'           => 'birth_year',
    'Dân tộc'            => 'ethnicity',
    'Quan hệ với chủ hộ' => 'relation',
    'CCCD'               => 'cccd',
    'Điện thoại'         => 'phone',
    'Email'              => 'email',
    'Nghề nghiệp'        => 'job',
    'Cử tri'             => 'is_voter',
    'Tình trạng'         => 'status',
    'Ghi chú'            => 'notes',
    'Nguồn thông tin'    => 'source',
    'Cần kiểm tra'       => 'check_note'
];

$action = $_GET['action'] ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

try {
    if ($action !== 'data' && !$isPost) {
        fail('Hãy gửi yêu cầu này bằng POST.', 405);
    }

    upgradeSchema(db());

    match ($action) {
        'data'               => getData(),
        'household.save'     => saveHousehold(body()),
        'household.delete'   => deleteHousehold(body()),
        'resident.save'      => saveResident(body()),
        'resident.delete'    => deleteRow('residents', 'resident_id', body(), 'Cư dân không tồn tại.'),
        'association.save'   => saveAssociation(body()),
        'association.delete' => deleteRow('associations', 'association_id', body(), 'Hội không tồn tại.'),
        'activity.save'      => saveActivity(body()),
        'activity.delete'    => deleteRow('activities', 'activity_id', body(), 'Hoạt động không tồn tại.'),
        'contribution.save'  => saveContribution(body()),
        'import'             => importExcel(),
        default              => fail('Hành động không hợp lệ: ' . $action, 404)
    };
} catch (Throwable $e) {
    fail($e->getMessage(), 500);
}

/* ============================================================ */

/*
 * Adds columns introduced after the database was first created.
 * Safe to run on every request: it only changes anything once.
 */
function upgradeSchema(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(activities)')->fetchAll(), 'name');

    if (!in_array('end_date', $columns, true)) {
        $db->exec('ALTER TABLE activities ADD COLUMN end_date TEXT');
    }
}

function getData(): never
{
    $db = db();

    ok([
        'households' => $db->query(
            'SELECT household_id, code, address, group_number, notes FROM households ORDER BY code'
        )->fetchAll(),
        'residents' => $db->query('SELECT * FROM residents ORDER BY code')->fetchAll(),
        'associations' => $db->query(
            'SELECT association_id, name, note FROM associations ORDER BY name COLLATE NOCASE'
        )->fetchAll(),
        'residentAssociations' => $db->query(
            'SELECT resident_id, association_id FROM resident_associations'
        )->fetchAll(),
        'activities' => $db->query(
            'SELECT activity_id, name, activity_date, end_date, amount_suggested, notes
             FROM activities ORDER BY activity_date DESC, activity_id DESC'
        )->fetchAll(),
        'householdActivities' => $db->query(
            'SELECT household_id, activity_id, paid, amount, notes FROM household_activities'
        )->fetchAll()
    ]);
}

/* Shared delete: removes one row by id, 404 if it was not there. */
function deleteRow(string $table, string $idColumn, array $data, string $notFound): never
{
    $stmt = db()->prepare("DELETE FROM $table WHERE $idColumn = ?");
    $stmt->execute([(int)($data[$idColumn] ?? 0)]);

    if ($stmt->rowCount() === 0) {
        fail($notFound, 404);
    }

    ok();
}

/* ---------- Households ---------- */

function saveHousehold(array $data): never
{
    $db = db();
    $id = (int)($data['household_id'] ?? 0);
    $address = str($data['address'] ?? '');
    $group = str($data['group_number'] ?? '');
    $group = $group === '' ? null : (int)$group;
    $notes = text($data['notes'] ?? '');

    if ($address === '') {
        fail('Địa chỉ không được để trống.');
    }

    if ($id > 0) {
        mustExist($db, 'households', 'household_id', $id, 'Hộ gia đình không tồn tại.');

        $db->prepare('UPDATE households SET address = ?, group_number = ?, notes = ? WHERE household_id = ?')
            ->execute([$address, $group, $notes, $id]);
    } else {
        $db->prepare('INSERT INTO households (code, address, group_number, notes) VALUES (?, ?, ?, ?)')
            ->execute([nextCode($db, 'households', 'H', 3), $address, $group, $notes]);
        $id = (int)$db->lastInsertId();
    }

    ok(['household_id' => $id]);
}

function deleteHousehold(array $data): never
{
    $db = db();
    $id = (int)($data['household_id'] ?? 0);

    $stmt = $db->prepare('SELECT COUNT(*) FROM residents WHERE household_id = ?');
    $stmt->execute([$id]);
    $count = (int)$stmt->fetchColumn();

    if ($count > 0) {
        fail("Hộ này còn $count người. Chuyển họ sang hộ khác (hoặc xóa) trước khi xóa hộ.");
    }

    deleteRow('households', 'household_id', $data, 'Hộ gia đình không tồn tại.');
}

/* ---------- Residents ---------- */

function saveResident(array $data): never
{
    $db = db();
    $id = (int)($data['resident_id'] ?? 0);
    $r = cleanResident($data);

    if ($r['full_name'] === '') {
        fail('Họ và tên không được để trống.');
    }

    mustExist($db, 'households', 'household_id', $r['household_id'], 'Hãy chọn hộ gia đình.');

    $db->beginTransaction();

    try {
        if ($id > 0) {
            mustExist($db, 'residents', 'resident_id', $id, 'Cư dân không tồn tại.');

            $set = implode(', ', array_map(fn($f) => "$f = :$f", array_keys($r)));
            $db->prepare("UPDATE residents SET $set WHERE resident_id = :id")->execute($r + ['id' => $id]);
        } else {
            $r['code'] = nextCode($db, 'residents', 'CD', 4);
            $cols = implode(', ', array_keys($r));
            $vals = ':' . implode(', :', array_keys($r));
            $db->prepare("INSERT INTO residents ($cols) VALUES ($vals)")->execute($r);
            $id = (int)$db->lastInsertId();
        }

        // Associations: replace the whole set when the form sends it.
        if (isset($data['associationIds']) && is_array($data['associationIds'])) {
            $db->prepare('DELETE FROM resident_associations WHERE resident_id = ?')->execute([$id]);
            $insert = $db->prepare(
                'INSERT OR IGNORE INTO resident_associations (resident_id, association_id)
                 SELECT ?, association_id FROM associations WHERE association_id = ?'
            );

            foreach ($data['associationIds'] as $associationId) {
                $insert->execute([$id, (int)$associationId]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    ok(['resident_id' => $id]);
}

/* ---------- Associations ---------- */

function saveAssociation(array $data): never
{
    $db = db();
    $id = (int)($data['association_id'] ?? 0);
    $name = str($data['name'] ?? '');
    $note = text($data['note'] ?? '');

    if ($name === '') {
        fail('Tên hội không được để trống.');
    }

    if ($id > 0) {
        mustExist($db, 'associations', 'association_id', $id, 'Hội không tồn tại.');
        $db->prepare('UPDATE associations SET name = ?, note = ? WHERE association_id = ?')
            ->execute([$name, $note, $id]);
    } else {
        $db->prepare('INSERT INTO associations (name, note) VALUES (?, ?)')->execute([$name, $note]);
        $id = (int)$db->lastInsertId();
    }

    ok(['association_id' => $id]);
}

/* ---------- Activities & contributions ---------- */

function saveActivity(array $data): never
{
    $db = db();
    $id = (int)($data['activity_id'] ?? 0);
    $name = str($data['name'] ?? '');
    $from = parseDate($data['activity_date'] ?? '');
    $to = parseDate($data['end_date'] ?? '');
    $amount = max(0, (int)($data['amount_suggested'] ?? 0));
    $notes = text($data['notes'] ?? '');

    if ($name === '') {
        fail('Tên hoạt động không được để trống.');
    }

    if ($from && $to && $to < $from) {
        fail('"Đến ngày" phải sau hoặc bằng "Từ ngày".');
    }

    if ($id > 0) {
        mustExist($db, 'activities', 'activity_id', $id, 'Hoạt động không tồn tại.');
        $db->prepare(
            'UPDATE activities SET name = ?, activity_date = ?, end_date = ?, amount_suggested = ?, notes = ?
             WHERE activity_id = ?'
        )->execute([$name, $from, $to, $amount, $notes, $id]);
    } else {
        $db->prepare(
            'INSERT INTO activities (name, activity_date, end_date, amount_suggested, notes) VALUES (?, ?, ?, ?, ?)'
        )->execute([$name, $from, $to, $amount, $notes]);
        $id = (int)$db->lastInsertId();
    }

    ok(['activity_id' => $id]);
}

function saveContribution(array $data): never
{
    $db = db();
    $householdId = (int)($data['household_id'] ?? 0);
    $activityId = (int)($data['activity_id'] ?? 0);

    mustExist($db, 'households', 'household_id', $householdId, 'Hộ gia đình không tồn tại.');
    mustExist($db, 'activities', 'activity_id', $activityId, 'Hoạt động không tồn tại.');

    $db->prepare(
        'INSERT INTO household_activities (household_id, activity_id, paid, amount, notes)
         VALUES (?, ?, ?, ?, ?)
         ON CONFLICT (household_id, activity_id) DO UPDATE SET
            paid = excluded.paid, amount = excluded.amount, notes = excluded.notes'
    )->execute([
        $householdId,
        $activityId,
        empty($data['paid']) ? 0 : 1,
        max(0, (int)($data['amount'] ?? 0)),
        text($data['notes'] ?? '')
    ]);

    ok();
}

/* ============================================================
 * IMPORT
 * Reads the standard workbook (sheets "Hộ gia đình" and "Cư dân")
 * and REPLACES all households and residents. Columns are found by
 * their header text, so their order does not matter.
 * Associations and activities are kept; memberships and
 * contributions are cleared because they belong to the old records.
 * ============================================================ */

function findSheet(array $sheets, string $name): array
{
    foreach ($sheets as $sheet) {
        if (str($sheet['name']) === $name) {
            return $sheet['rows'];
        }
    }

    throw new RuntimeException("Không tìm thấy sheet \"$name\". Hãy dùng đúng file mẫu.");
}

/* header text -> [field => column index] */
function mapColumns(array $headerRow, array $map, array $required, string $sheet): array
{
    $cols = [];

    foreach ($headerRow as $index => $label) {
        $label = str($label);

        if (isset($map[$label]) && !isset($cols[$map[$label]])) {
            $cols[$map[$label]] = $index;
        }
    }

    foreach ($required as $field) {
        if (!isset($cols[$field])) {
            throw new RuntimeException(
                "Sheet \"$sheet\" thiếu cột \"" . array_search($field, $map, true) . '".'
            );
        }
    }

    return $cols;
}

function rowValues(array $row, array $cols): array
{
    $values = [];

    foreach ($cols as $field => $index) {
        $values[$field] = str($row[$index] ?? '');
    }

    return $values;
}

function importExcel(): never
{
    require __DIR__ . '/xlsx-reader.php';

    $file = $_FILES['file'] ?? null;

    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail('Chưa nhận được file. Nếu file lớn, kiểm tra upload_max_filesize trong php.ini.');
    }

    if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        fail('Chỉ nhận file Excel .xlsx.');
    }

    $db = db();

    try {
        $sheets = xlsxReadWorkbook((string)$file['tmp_name']);

        $hhRows = findSheet($sheets, 'Hộ gia đình');
        $rRows = findSheet($sheets, 'Cư dân');

        $hhCols = mapColumns($hhRows[0] ?? [], HOUSEHOLD_COLUMNS, ['code', 'address'], 'Hộ gia đình');
        $rCols = mapColumns($rRows[0] ?? [], RESIDENT_COLUMNS, ['household_code', 'full_name'], 'Cư dân');

        $db->beginTransaction();

        $db->exec('DELETE FROM resident_associations');
        $db->exec('DELETE FROM household_activities');
        $db->exec('DELETE FROM residents');
        $db->exec('DELETE FROM households');
        $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('residents', 'households')");

        /* Households */
        $householdIds = [];
        $insertHousehold = $db->prepare(
            'INSERT INTO households (code, address, group_number, notes) VALUES (?, ?, ?, ?)'
        );

        for ($i = 1, $n = count($hhRows); $i < $n; $i++) {
            $v = rowValues($hhRows[$i], $hhCols);
            $code = $v['code'] ?? '';

            if ($code === '') {
                continue;
            }

            if (isset($householdIds[$code])) {
                throw new RuntimeException('Sheet "Hộ gia đình", dòng ' . ($i + 1) . ": mã hộ $code bị trùng.");
            }

            $group = $v['group_number'] ?? '';
            $insertHousehold->execute([$code, $v['address'] ?? '', $group === '' ? null : (int)$group, $v['notes'] ?? '']);
            $householdIds[$code] = (int)$db->lastInsertId();
        }

        /* Residents */
        $fields = array_keys(cleanResident([]));
        $insertResident = $db->prepare(
            'INSERT INTO residents (code, ' . implode(', ', $fields) . ')
             VALUES (:code, :' . implode(', :', $fields) . ')'
        );

        $usedCodes = [];
        $withoutCode = [];

        for ($i = 1, $n = count($rRows); $i < $n; $i++) {
            $v = rowValues($rRows[$i], $rCols);

            if (($v['full_name'] ?? '') === '') {
                continue;
            }

            $hhCode = $v['household_code'] ?? '';

            if (!isset($householdIds[$hhCode])) {
                throw new RuntimeException(
                    'Sheet "Cư dân", dòng ' . ($i + 1) . " ({$v['full_name']}): mã hộ \"$hhCode\" không có trong sheet \"Hộ gia đình\"."
                );
            }

            $v['household_id'] = $householdIds[$hhCode];
            $resident = cleanResident($v);
            $code = $v['code'] ?? '';

            if ($code === '' || isset($usedCodes[$code])) {
                $withoutCode[] = $resident;
                continue;
            }

            $usedCodes[$code] = true;
            $insertResident->execute(['code' => $code] + $resident);
        }

        // Rows without a code (or with a duplicate one) get the next free codes.
        foreach ($withoutCode as $resident) {
            $insertResident->execute(['code' => nextCode($db, 'residents', 'CD', 4)] + $resident);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        fail('Chưa nhập được, dữ liệu cũ được giữ nguyên. ' . $e->getMessage());
    }

    ok([
        'households' => count($householdIds),
        'residents'  => count($usedCodes) + count($withoutCode),
        'new_codes'  => count($withoutCode)
    ]);
}