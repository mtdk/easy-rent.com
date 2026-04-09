<?php

declare(strict_types=1);

/**
 * A.xlsx 导入预览脚本（不入库）
 *
 * 用法:
 *   php scripts/preview-rent-xlsx.php [xlsx_path] [output_json_path]
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$xlsxPath = $argv[1] ?? __DIR__ . '/../docs/A.xlsx';
$outputPath = $argv[2] ?? __DIR__ . '/../storage/import-preview/a-xlsx-preview.json';

if (!file_exists($xlsxPath)) {
    fwrite(STDERR, "文件不存在: {$xlsxPath}\n");
    exit(1);
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "缺少 ZipArchive 扩展，无法解析 xlsx。\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($xlsxPath) !== true) {
    fwrite(STDERR, "无法打开 xlsx 文件: {$xlsxPath}\n");
    exit(1);
}

$sharedStrings = parseSharedStrings((string) $zip->getFromName('xl/sharedStrings.xml'));
[$sheetNameToPath, $allSheets] = parseWorkbookSheetMap(
    (string) $zip->getFromName('xl/workbook.xml'),
    (string) $zip->getFromName('xl/_rels/workbook.xml.rels')
);

$roomSheets = [];
$auxSheets = [];
foreach ($allSheets as $sheetName) {
    if (isRoomSheetName($sheetName)) {
        $roomSheets[] = $sheetName;
    } else {
        $auxSheets[] = $sheetName;
    }
}

$records = [];
$sheetSummaries = [];
$invalidRows = [];

foreach ($roomSheets as $sheetName) {
    $path = $sheetNameToPath[$sheetName] ?? null;
    if ($path === null) {
        continue;
    }

    $xml = $zip->getFromName($path);
    if ($xml === false) {
        continue;
    }

    $sheetRows = parseSheetRows($xml, $sharedStrings);
    if (count($sheetRows) === 0) {
        continue;
    }

    $headers = $sheetRows[1] ?? [];
    $subHeaders = $sheetRows[2] ?? [];
    $columnMap = inferColumnMap($headers, $subHeaders);

    $rowCount = 0;
    for ($r = 3; $r <= count($sheetRows); $r++) {
        $row = $sheetRows[$r] ?? [];
        if (isEssentiallyEmpty($row)) {
            continue;
        }

        $record = normalizeRentRow($sheetName, $r, $row, $columnMap);
        if ($record === null) {
            $invalidRows[] = [
                'sheet' => $sheetName,
                'row' => $r,
                'reason' => '缺少日期或租客关键字段',
                'raw' => $row,
            ];
            continue;
        }

        $records[] = $record;
        $rowCount++;
    }

    $sheetSummaries[] = [
        'sheet_name' => $sheetName,
        'worksheet_path' => $path,
        'header_row_1' => $headers,
        'header_row_2' => $subHeaders,
        'normalized_row_count' => $rowCount,
    ];
}

$preview = [
    'source_file' => $xlsxPath,
    'generated_at' => date('c'),
    'sheet_count_total' => count($allSheets),
    'sheet_count_room' => count($roomSheets),
    'sheet_count_auxiliary' => count($auxSheets),
    'room_sheets' => $roomSheets,
    'auxiliary_sheets' => $auxSheets,
    'summary' => [
        'record_count' => count($records),
        'invalid_row_count' => count($invalidRows),
    ],
    'sheet_summaries' => $sheetSummaries,
    'sample_records' => array_slice($records, 0, 40),
    'invalid_rows_sample' => array_slice($invalidRows, 0, 40),
    'normalized_records' => $records,
];

$outputDir = dirname($outputPath);
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "无法创建目录: {$outputDir}\n");
    exit(1);
}

file_put_contents($outputPath, json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$zip->close();

echo "解析完成\n";
echo "- 源文件: {$xlsxPath}\n";
echo "- 房号工作表: " . count($roomSheets) . "\n";
echo "- 归一化记录: " . count($records) . "\n";
echo "- 无效行: " . count($invalidRows) . "\n";
echo "- 预览输出: {$outputPath}\n";

function parseSharedStrings(string $xml): array
{
    if ($xml === '') {
        return [];
    }

    $dom = new DOMDocument();
    if (!$dom->loadXML($xml)) {
        return [];
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $items = $xpath->query('//x:si');
    if ($items === false) {
        return [];
    }

    $result = [];
    foreach ($items as $si) {
        $texts = $xpath->query('.//x:t', $si);
        if ($texts === false || $texts->length === 0) {
            $result[] = '';
            continue;
        }

        $parts = [];
        foreach ($texts as $t) {
            $parts[] = (string) $t->nodeValue;
        }
        $result[] = implode('', $parts);
    }

    return $result;
}

function parseWorkbookSheetMap(string $workbookXml, string $relsXml): array
{
    $sheetNameToRelId = [];
    $sheetOrder = [];

    $wb = simplexml_load_string($workbookXml);
    if ($wb !== false) {
        $wb->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheetNodes = $wb->xpath('//x:sheets/x:sheet');
        if ($sheetNodes !== false) {
            foreach ($sheetNodes as $node) {
                $attrs = $node->attributes();
                $rAttrs = $node->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $name = (string) ($attrs['name'] ?? '');
                $relId = (string) ($rAttrs['id'] ?? '');

                if ($name !== '' && $relId !== '') {
                    $sheetNameToRelId[$name] = $relId;
                    $sheetOrder[] = $name;
                }
            }
        }
    }

    $relIdToPath = [];
    $rels = simplexml_load_string($relsXml);
    if ($rels !== false) {
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relNodes = $rels->xpath('//p:Relationship');
        if ($relNodes !== false) {
            foreach ($relNodes as $node) {
                $attrs = $node->attributes();
                $id = (string) ($attrs['Id'] ?? '');
                $target = (string) ($attrs['Target'] ?? '');
                $type = (string) ($attrs['Type'] ?? '');

                if ($id === '' || $target === '') {
                    continue;
                }

                if (strpos($type, '/worksheet') !== false) {
                    $relIdToPath[$id] = 'xl/' . ltrim($target, '/');
                }
            }
        }
    }

    $sheetNameToPath = [];
    foreach ($sheetNameToRelId as $name => $relId) {
        if (isset($relIdToPath[$relId])) {
            $sheetNameToPath[$name] = $relIdToPath[$relId];
        }
    }

    return [$sheetNameToPath, $sheetOrder];
}

function isRoomSheetName(string $name): bool
{
    if (strpos($name, '#') === false) {
        return false;
    }

    return (bool) preg_match('/^[0-9\-]+#$/', $name);
}

function parseSheetRows(string $sheetXml, array $sharedStrings): array
{
    $sx = simplexml_load_string($sheetXml);
    if ($sx === false) {
        return [];
    }

    $sx->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = $sx->xpath('//x:sheetData/x:row');
    if ($rows === false) {
        return [];
    }

    $result = [];
    foreach ($rows as $rowNode) {
        $rowNode->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rAttr = $rowNode->attributes();
        $rowIndex = (int) ($rAttr['r'] ?? 0);
        if ($rowIndex <= 0) {
            continue;
        }

        $cells = [];
        $cellNodes = $rowNode->xpath('./x:c');
        if ($cellNodes === false) {
            continue;
        }

        foreach ($cellNodes as $cellNode) {
            $cellNode->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $cAttr = $cellNode->attributes();
            $ref = (string) ($cAttr['r'] ?? '');
            if ($ref === '') {
                continue;
            }

            $col = preg_replace('/\d+/', '', $ref);
            $type = (string) ($cAttr['t'] ?? '');

            $vNodes = $cellNode->xpath('./x:v');
            $value = '';
            if ($vNodes !== false && isset($vNodes[0])) {
                $value = (string) $vNodes[0];
            }

            if ($type === 's' && $value !== '' && is_numeric($value)) {
                $idx = (int) $value;
                $value = $sharedStrings[$idx] ?? '';
            }

            $cells[$col] = $value;
        }

        $result[$rowIndex] = $cells;
    }

    return $result;
}

function inferColumnMap(array $headerRow1, array $headerRow2): array
{
    $map = [];

    foreach ($headerRow1 as $col => $h1Raw) {
        $h1 = trim((string) $h1Raw);
        $h2 = trim((string) ($headerRow2[$col] ?? ''));

        if ($h1 === '日期') {
            $map['date'] = $col;
            continue;
        }

        if ($h1 === '房号') {
            $map['room_no'] = $col;
            continue;
        }

        if ($h1 === '用户名') {
            $map['tenant_name'] = $col;
            continue;
        }

        if ($h1 === '水费') {
            $map['water_fee'] = $col;
            continue;
        }

        if ($h1 === '电费') {
            $map['electric_fee'] = $col;
            continue;
        }

        if ($h1 === '租金') {
            $map['rent_amount'] = $col;
            continue;
        }

        if ($h1 === '合计') {
            $map['total_amount'] = $col;
            continue;
        }

        if ($h1 === '押金') {
            $map['deposit'] = $col;
            continue;
        }

        if ($h1 === '备注') {
            $map['remark'] = $col;
            continue;
        }

        if ($h2 === '用水量') {
            $map['water_usage'] = $col;
            continue;
        }

        if ($h2 === '用电量') {
            $map['electric_usage'] = $col;
            continue;
        }

        if ($h2 === '当前度数' && ($h1 === '水表' || mb_strpos($h1, '水表') !== false)) {
            $map['water_current'] = $col;
            continue;
        }

        if ($h2 === '上次度数' && ($h1 === '水表' || mb_strpos($h1, '水表') !== false)) {
            $map['water_previous'] = $col;
            continue;
        }

        if ($h2 === '当前度数' && ($h1 === '电表' || mb_strpos($h1, '电表') !== false)) {
            $map['electric_current'] = $col;
            continue;
        }

        if ($h2 === '上次度数' && ($h1 === '电表' || mb_strpos($h1, '电表') !== false)) {
            $map['electric_previous'] = $col;
            continue;
        }
    }

    return $map;
}

function normalizeRentRow(string $sheetName, int $rowIndex, array $row, array $columnMap): ?array
{
    $excelDate = trim((string) valueByMap($row, $columnMap, 'date', 'A'));
    $tenant = trim((string) valueByMap($row, $columnMap, 'tenant_name', 'C'));

    if ($excelDate === '' && $tenant === '') {
        return null;
    }

    $date = normalizeExcelDate($excelDate);
    $roomNo = trim((string) valueByMap($row, $columnMap, 'room_no', 'B'));
    if ($roomNo === '') {
        $roomNo = $sheetName;
    }

    $waterCurrent = toFloat(valueByMap($row, $columnMap, 'water_current', 'D'));
    $waterPrevious = toFloat(valueByMap($row, $columnMap, 'water_previous', 'E'));
    $electricCurrent = toFloat(valueByMap($row, $columnMap, 'electric_current', 'F'));
    $electricPrevious = toFloat(valueByMap($row, $columnMap, 'electric_previous', 'G'));

    $waterUsage = toFloat(valueByMap($row, $columnMap, 'water_usage', 'H'));
    $electricUsage = toFloat(valueByMap($row, $columnMap, 'electric_usage', 'I'));

    if ($waterUsage === null && $waterCurrent !== null && $waterPrevious !== null) {
        $waterUsage = round($waterCurrent - $waterPrevious, 2);
    }

    if ($electricUsage === null && $electricCurrent !== null && $electricPrevious !== null) {
        $electricUsage = round($electricCurrent - $electricPrevious, 2);
    }

    $waterFee = toFloat(valueByMap($row, $columnMap, 'water_fee', 'J'));
    $electricFee = toFloat(valueByMap($row, $columnMap, 'electric_fee', 'K'));
    $rent = toFloat(valueByMap($row, $columnMap, 'rent_amount', 'L'));
    $total = toFloat(valueByMap($row, $columnMap, 'total_amount', 'M'));
    $deposit = toFloat(valueByMap($row, $columnMap, 'deposit', 'N'));

    $remark = trim((string) valueByMap($row, $columnMap, 'remark', 'O'));
    if ($remark === '') {
        $remark = trim((string) ($row['P'] ?? ''));
    }

    $hasBillingSignal = $waterCurrent !== null
        || $waterPrevious !== null
        || $electricCurrent !== null
        || $electricPrevious !== null
        || $waterUsage !== null
        || $electricUsage !== null
        || $waterFee !== null
        || $electricFee !== null
        || $rent !== null
        || $total !== null
        || $deposit !== null;

    if (!$hasBillingSignal) {
        return null;
    }

    if ($date === null && $tenant === '') {
        return null;
    }

    return [
        'sheet_name' => $sheetName,
        'row_index' => $rowIndex,
        'date' => $date,
        'room_no' => $roomNo,
        'tenant_name' => $tenant !== '' ? $tenant : null,
        'water_current' => $waterCurrent,
        'water_previous' => $waterPrevious,
        'water_usage' => $waterUsage,
        'electric_current' => $electricCurrent,
        'electric_previous' => $electricPrevious,
        'electric_usage' => $electricUsage,
        'water_fee' => $waterFee,
        'electric_fee' => $electricFee,
        'rent_amount' => $rent,
        'total_amount' => $total,
        'deposit' => $deposit,
        'remark' => $remark !== '' ? $remark : null,
        'raw_columns' => $row,
    ];
}

function valueByMap(array $row, array $columnMap, string $key, string $fallbackCol)
{
    if (isset($columnMap[$key]) && array_key_exists($columnMap[$key], $row)) {
        return $row[$columnMap[$key]];
    }

    return $row[$fallbackCol] ?? null;
}

function normalizeExcelDate(string $raw): ?string
{
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $raw;
    }

    if (preg_match('/^\d+$/', $raw)) {
        $serial = (int) $raw;
        if ($serial <= 0) {
            return null;
        }
        $base = new DateTimeImmutable('1899-12-30');
        return $base->add(new DateInterval('P' . $serial . 'D'))->format('Y-m-d');
    }

    // 尝试解析中文日期，如 2026年4月6日
    $normalized = str_replace(['年', '月', '日', '号', '/'], ['-', '-', '', '', '-'], $raw);
    $normalized = preg_replace('/\s+/', '', $normalized ?? '');

    $ts = strtotime((string) $normalized);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d', $ts);
}

function toFloat($value): ?float
{
    if ($value === null) {
        return null;
    }

    $str = trim((string) $value);
    if ($str === '') {
        return null;
    }

    $normalized = str_replace(',', '', $str);
    if (preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
        return round((float) $normalized, 2);
    }

    return null;
}

function isEssentiallyEmpty(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return false;
        }
    }

    return true;
}
