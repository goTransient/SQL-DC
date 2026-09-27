<?php
declare(strict_types=1);

/*
 * DOMIVA - XLSX reader
 * No mbstring required.
 *
 * Returns a list of sheets:
 *   [ ['name' => string, 'path' => string, 'rows' => array<int, array<int, string>>], ... ]
 *
 * Notes:
 * - $rows[$i] is Excel row number ($i + 1). Blank rows are kept as empty arrays,
 *   so row numbers reported to the user match Excel.
 * - Every cell value is returned as a string.
 * - Cells formatted as dates in Excel are converted to "YYYY-MM-DD".
 */

function xlsxOpen(string $file): ZipArchive
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive chưa được bật.');
    }

    $zip = new ZipArchive();
    $result = $zip->open($file);

    if ($result !== true) {
        throw new RuntimeException('Không thể mở file Excel (.xlsx).');
    }

    return $zip;
}

function xlsxXml(ZipArchive $zip, string $path): SimpleXMLElement
{
    $data = $zip->getFromName($path);

    if ($data === false) {
        throw new RuntimeException("Không tìm thấy thành phần XLSX: {$path}");
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($data);

    if ($xml === false) {
        throw new RuntimeException("Không thể đọc XML: {$path}");
    }

    return $xml;
}

function xlsxColumnNumber(string $cellRef): int
{
    if (!preg_match('/^([A-Z]+)/i', $cellRef, $m)) {
        return -1;
    }

    $letters = strtoupper($m[1]);
    $number = 0;

    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $number = $number * 26 + (ord($letters[$i]) - 64);
    }

    return $number - 1;
}

function xlsxSharedStrings(ZipArchive $zip): array
{
    $path = 'xl/sharedStrings.xml';

    if ($zip->locateName($path) === false) {
        return [];
    }

    $xml = xlsxXml($zip, $path);
    $strings = [];

    foreach ($xml->si as $si) {
        $text = '';

        // Plain string: <si><t>..</t></si>
        foreach ($si->t as $t) {
            $text .= (string)$t;
        }

        // Rich text: <si><r><t>..</t></r>...</si>
        foreach ($si->r as $run) {
            $text .= (string)$run->t;
        }

        $strings[] = $text;
    }

    return $strings;
}

function xlsxRelationships(ZipArchive $zip): array
{
    $xml = xlsxXml($zip, 'xl/_rels/workbook.xml.rels');
    $result = [];

    foreach ($xml->Relationship as $rel) {
        $attrs = $rel->attributes();

        $id = (string)($attrs['Id'] ?? '');
        $target = (string)($attrs['Target'] ?? '');

        if ($id !== '' && $target !== '') {
            $result[$id] = $target;
        }
    }

    return $result;
}

function xlsxResolveWorksheetPath(string $target): string
{
    $target = str_replace('\\', '/', $target);

    if (str_starts_with($target, '/')) {
        return ltrim($target, '/');
    }

    if (str_starts_with($target, 'xl/')) {
        return $target;
    }

    return 'xl/' . ltrim($target, '/');
}

/*
 * Which cell styles (cellXfs index) are date formats.
 * Without this, a date cell arrives as a serial number such as 20089.
 */
function xlsxDateStyles(ZipArchive $zip): array
{
    if ($zip->locateName('xl/styles.xml') === false) {
        return [];
    }

    $xml = xlsxXml($zip, 'xl/styles.xml');

    $custom = [];

    if (isset($xml->numFmts->numFmt)) {
        foreach ($xml->numFmts->numFmt as $fmt) {
            $code = (string)$fmt['formatCode'];

            // Remove quoted text, [colour]/[locale] blocks and escaped chars.
            $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code) ?? $code;
            $code = strtolower($code);

            $custom[(int)$fmt['numFmtId']] = preg_match('/[dy]/', $code) === 1;
        }
    }

    // Built-in date / date-time formats (time-only formats 18-21 and 45-47 excluded).
    $builtin = [14, 15, 16, 17, 22, 27, 28, 29, 30, 31, 36, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    $styles = [];
    $index = 0;

    if (isset($xml->cellXfs->xf)) {
        foreach ($xml->cellXfs->xf as $xf) {
            $id = (int)$xf['numFmtId'];
            $styles[$index++] = in_array($id, $builtin, true) || !empty($custom[$id]);
        }
    }

    return $styles;
}

function xlsxSerialToDate(float $serial, bool $date1904): string
{
    $days = (int)floor($serial);

    if ($days < 1) {
        return '';
    }

    $base = $date1904 ? '1904-01-01' : '1899-12-30';

    return (new DateTime($base))
        ->modify('+' . $days . ' days')
        ->format('Y-m-d');
}

function xlsxCellValue(
    SimpleXMLElement $cell,
    array $sharedStrings,
    array $dateStyles = [],
    bool $date1904 = false
): string {
    $type = (string)($cell['t'] ?? '');
    $value = isset($cell->v) ? (string)$cell->v : '';

    if ($type === 'inlineStr') {
        $text = '';

        if (isset($cell->is->t)) {
            $text .= (string)$cell->is->t;
        }

        if (isset($cell->is->r)) {
            foreach ($cell->is->r as $run) {
                $text .= (string)$run->t;
            }
        }

        return $text;
    }

    if ($type === 's') {
        return $sharedStrings[(int)$value] ?? '';
    }

    if ($type === 'b') {
        return $value === '1' ? 'TRUE' : 'FALSE';
    }

    if ($type === 'str' || $type === 'e') {
        return $type === 'e' ? '' : $value;
    }

    if ($value === '') {
        return '';
    }

    if (($type === '' || $type === 'n') && is_numeric($value)) {
        $styleIndex = (int)($cell['s'] ?? 0);

        if (!empty($dateStyles[$styleIndex])) {
            return xlsxSerialToDate((float)$value, $date1904);
        }

        // Integral numbers: "1954.0" / "1.954E3" -> "1954".
        // Non-integral numbers are left untouched.
        $float = (float)$value;

        if (floor($float) === $float && abs($float) < 1e15) {
            return (string)(int)$float;
        }

        return $value;
    }

    return $value;
}

function xlsxReadSheet(
    ZipArchive $zip,
    string $path,
    array $sharedStrings,
    array $dateStyles,
    bool $date1904
): array {
    $xml = xlsxXml($zip, $path);
    $rows = [];
    $maxIndex = -1;

    if (!isset($xml->sheetData->row)) {
        return [];
    }

    $autoRow = 0;

    foreach ($xml->sheetData->row as $rowNode) {
        $autoRow++;
        $rowNumber = (int)($rowNode['r'] ?? $autoRow);

        if ($rowNumber < 1) {
            $rowNumber = $autoRow;
        }

        $cells = [];
        $hasValue = false;
        $autoCol = -1;

        foreach ($rowNode->c as $cell) {
            $ref = (string)($cell['r'] ?? '');
            $column = $ref !== '' ? xlsxColumnNumber($ref) : ($autoCol + 1);

            if ($column < 0) {
                continue;
            }

            $autoCol = $column;

            $value = xlsxCellValue($cell, $sharedStrings, $dateStyles, $date1904);

            if (trim($value) !== '') {
                $hasValue = true;
            }

            $cells[$column] = $value;
        }

        if (!$hasValue) {
            continue;
        }

        $maxColumn = max(array_keys($cells));
        $normalized = array_fill(0, $maxColumn + 1, '');

        foreach ($cells as $column => $value) {
            $normalized[$column] = $value;
        }

        $rows[$rowNumber - 1] = $normalized;
        $maxIndex = max($maxIndex, $rowNumber - 1);
    }

    if ($maxIndex < 0) {
        return [];
    }

    // Keep Excel row numbers: index i == Excel row i + 1.
    $result = [];

    for ($i = 0; $i <= $maxIndex; $i++) {
        $result[$i] = $rows[$i] ?? [];
    }

    return $result;
}

function xlsxReadWorkbook(string $file): array
{
    $zip = xlsxOpen($file);

    try {
        $sharedStrings = xlsxSharedStrings($zip);
        $dateStyles = xlsxDateStyles($zip);

        $workbook = xlsxXml($zip, 'xl/workbook.xml');
        $relationships = xlsxRelationships($zip);

        $date1904 = false;

        if (isset($workbook->workbookPr)) {
            $flag = strtolower((string)($workbook->workbookPr['date1904'] ?? ''));
            $date1904 = $flag === '1' || $flag === 'true';
        }

        $sheets = [];

        foreach ($workbook->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
            );

            $relationshipId = (string)($attrs['id'] ?? '');
            $name = (string)($sheet['name'] ?? '');

            if ($relationshipId === '' || $name === '') {
                continue;
            }

            if (!isset($relationships[$relationshipId])) {
                continue;
            }

            $path = xlsxResolveWorksheetPath($relationships[$relationshipId]);

            // Chart sheets / missing parts are skipped instead of failing the import.
            if ($zip->locateName($path) === false) {
                continue;
            }

            $sheets[] = [
                'name' => $name,
                'path' => $path,
                'rows' => xlsxReadSheet(
                    $zip,
                    $path,
                    $sharedStrings,
                    $dateStyles,
                    $date1904
                )
            ];
        }

        return $sheets;
    } finally {
        $zip->close();
    }
}