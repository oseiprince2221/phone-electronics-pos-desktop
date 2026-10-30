<?php

/**
 * Exports the POS SQLite database into an Excel .xlsx workbook.
 * The SQLite database remains the source of truth.
 */

function posExcelEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_XML1 | ENT_QUOTES,
        'UTF-8'
    );
}

function posExcelColumnName(int $number): string
{
    $name = '';

    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $name = chr(65 + $remainder) . $name;
        $number = intdiv($number - 1, 26);
    }

    return $name;
}

function posExcelCell(string $cellReference, $value): string
{
    if ($value === null) {
        return '<c r="' . $cellReference . '"/>';
    }

    if (is_numeric($value) && !is_string($value)) {
        return '<c r="' . $cellReference . '" t="n"><v>' .
            posExcelEscape($value) . '</v></c>';
    }

    return '<c r="' . $cellReference . '" t="inlineStr"><is><t xml:space="preserve">' .
        posExcelEscape($value) .
        '</t></is></c>';
}

function posExcelWorksheet(array $headers, array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetData>';

    $xml .= '<row r="1">';

    foreach ($headers as $index => $header) {
        $cell = posExcelColumnName($index + 1) . '1';
        $xml .= '<c r="' . $cell . '" t="inlineStr" s="1"><is><t>' .
            posExcelEscape($header) .
            '</t></is></c>';
    }

    $xml .= '</row>';

    $rowNumber = 2;

    foreach ($rows as $row) {
        $xml .= '<row r="' . $rowNumber . '">';

        $columnNumber = 1;

        foreach ($row as $value) {
            $cell = posExcelColumnName($columnNumber) . $rowNumber;
            $xml .= posExcelCell($cell, $value);
            $columnNumber++;
        }

        $xml .= '</row>';
        $rowNumber++;
    }

    $xml .= '</sheetData></worksheet>';

    return $xml;
}

function exportPosWorkbook(PDO $pdo): void
{
    if (!class_exists('ZipArchive')) {
        error_log('POS Excel export failed: PHP ZipArchive extension is unavailable.');
        return;
    }

    $directory = getenv('LOCALAPPDATA') .
        DIRECTORY_SEPARATOR . 'PhoneElectronicsPOS';

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        error_log('POS Excel export failed: cannot create output directory.');
        return;
    }

    $filePath = $directory . DIRECTORY_SEPARATOR . 'POS_Business_Records.xlsx';
    $temporaryPath = $filePath . '.tmp';

    try {
        $tableQuery = $pdo->query(
            "SELECT name FROM sqlite_master
             WHERE type = 'table'
             AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
        );

        $tables = $tableQuery->fetchAll(PDO::FETCH_COLUMN);

        $sheets = [];
        $usedNames = [];

        foreach ($tables as $tableName) {
            $sheetName = substr(
                preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '_', $tableName),
                0,
                31
            );

            if ($sheetName === '') {
                $sheetName = 'Sheet';
            }

            $baseName = $sheetName;
            $suffix = 1;

            while (isset($usedNames[strtolower($sheetName)])) {
                $ending = '_' . $suffix;
                $sheetName = substr($baseName, 0, 31 - strlen($ending)) . $ending;
                $suffix++;
            }

            $usedNames[strtolower($sheetName)] = true;

            $columnsQuery = $pdo->query(
                'PRAGMA table_info("' . str_replace('"', '""', $tableName) . '")'
            );

            $columns = $columnsQuery->fetchAll(PDO::FETCH_ASSOC);
            $headers = array_column($columns, 'name');

            $dataQuery = $pdo->query(
                'SELECT * FROM "' . str_replace('"', '""', $tableName) . '"'
            );

            $rows = $dataQuery->fetchAll(PDO::FETCH_NUM);

            $sheets[] = [
                'name' => $sheetName,
                'headers' => $headers,
                'rows' => $rows
            ];
        }

        if (!$sheets) {
            error_log('POS Excel export skipped: no database tables were found.');
            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            error_log('POS Excel export failed: could not create workbook.');
            return;
        }

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $contentTypes .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $contentTypes .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $contentTypes .= '<Default Extension="xml" ContentType="application/xml"/>';
        $contentTypes .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $contentTypes .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        foreach ($sheets as $index => $sheet) {
            $contentTypes .= '<Override PartName="/xl/worksheets/sheet' .
                ($index + 1) .
                '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        $contentTypes .= '</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypes);

        $zip->addFromString(
            '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>'
        );

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $workbook .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $workbook .= '<sheets>';

        $relationships = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $relationships .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach ($sheets as $index => $sheet) {
            $sheetNumber = $index + 1;

            $workbook .= '<sheet name="' . posExcelEscape($sheet['name']) .
                '" sheetId="' . $sheetNumber .
                '" r:id="rId' . $sheetNumber . '"/>';

            $relationships .= '<Relationship Id="rId' . $sheetNumber .
                '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' .
                $sheetNumber . '.xml"/>';
        }

        $stylesId = count($sheets) + 1;

        $workbook .= '</sheets></workbook>';

        $relationships .= '<Relationship Id="rId' . $stylesId .
            '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $relationships .= '</Relationships>';

        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $relationships);

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $styles .= '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>';
        $styles .= '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>';
        $styles .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';
        $styles .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $styles .= '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs>';
        $styles .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        $styles .= '</styleSheet>';

        $zip->addFromString('xl/styles.xml', $styles);

        foreach ($sheets as $index => $sheet) {
            $zip->addFromString(
                'xl/worksheets/sheet' . ($index + 1) . '.xml',
                posExcelWorksheet($sheet['headers'], $sheet['rows'])
            );
        }

        $zip->close();

        if (!is_file($temporaryPath) || filesize($temporaryPath) === 0) {
            throw new RuntimeException('The Excel workbook file was not created correctly.');
        }

        if (!rename($temporaryPath, $filePath)) {
            if (is_file($filePath)) {
                unlink($filePath);
            }

            if (!rename($temporaryPath, $filePath)) {
                throw new RuntimeException('Could not save the Excel workbook.');
            }
        }
    } catch (Throwable $e) {
        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }

        error_log('POS Excel export error: ' . $e->getMessage());
    }
}
