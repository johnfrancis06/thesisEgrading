<?php
/**
 * Minimal .xlsx writer helpers.
 *
 * PhpWord 0.18 no longer ships an Excel writer and PhpSpreadsheet is not a
 * dependency here, so a workbook is assembled by hand: a .xlsx is a ZIP of a
 * handful of XML parts, and a report only ever needs one sheet, a style table and
 * inline strings.
 *
 * These are the same helpers the monthly attendance export uses, moved into an
 * include so the class record workbook and that export cannot drift apart. Every
 * one is guarded, so the copies that live in api/index.php stay valid.
 */

if (!function_exists('excelColumnName')) {
    // Excel column letters for a zero-based column index: 0 = A, 26 = AA.
    function excelColumnName(int $index) {
        $name = '';
        $index++;
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $name = chr(65 + $remainder) . $name;
            $index = intdiv($index - $remainder, 26);
        }
        return $name;
    }
}

if (!function_exists('excelCell')) {
    // One worksheet cell as inline XML, so no shared-string table is needed.
    function excelCell(string $reference, $value, int $style = 0) {
        $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
        if ($value === null || $value === '') {
            return '<c r="' . $reference . '"' . $styleAttr . '/>';
        }
        $text = htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<c r="' . $reference . '"' . $styleAttr . ' t="inlineStr"><is><t xml:space="preserve">'
            . $text . '</t></is></c>';
    }
}

if (!function_exists('excelNumberCell')) {
    /**
     * One numeric worksheet cell, so Excel sorts and adds it instead of treating
     * the figure as text.
     *
     * @param float|int $value  A blank or null value writes an empty cell.
     */
    function excelNumberCell(string $reference, $value, int $style = 0) {
        $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
        if ($value === null || $value === '') {
            return '<c r="' . $reference . '"' . $styleAttr . '/>';
        }
        return '<c r="' . $reference . '"' . $styleAttr . '><v>'
            . (is_float($value) ? rtrim(rtrim(sprintf('%.4F', $value), '0'), '.') : intval($value))
            . '</v></c>';
    }
}

if (!function_exists('excelStylesXml')) {
    // Style table for the attendance workbooks. Index order matters: each cellXf
    // below is referred to by number from the sheet builders.
    //  0 plain  1 title  2 subtitle  3 header center  4 header left  5 name cell
    //  6 present  7 absent  8 late  9 excused  10 blank mark  11 holiday banner
    //  12 seminar banner  13 totals header  14 attendance cell  15 legend
    //  16 yellow date header, written up the column  17 yellow event banner
    //  18 light yellow mark cell  19 yellow cell  20 white number cell
    //  21 yellow percent cell, a number that reads with the sign the sheet shows
    //
    // The last six are only used by the class record workbook, which is the one
    // place that has to keep the printed sheet's Excel yellow. api/index.php keeps
    // its own copy of this table for the monthly export and never loads this
    // include, so adding entries here cannot change that file's output.
    function excelStylesXml() {
        $fonts = [
            '<font><sz val="11"/><name val="Calibri"/></font>',
            '<font><b/><sz val="11"/><name val="Calibri"/></font>',
            '<font><b/><sz val="14"/><name val="Calibri"/></font>',
            '<font><sz val="10"/><name val="Calibri"/></font>',
            '<font><b/><sz val="8"/><name val="Calibri"/></font>',
            '<font><sz val="8"/><name val="Calibri"/></font>',
            '<font><b/><sz val="9"/><name val="Calibri"/></font>',
            '<font><b/><color rgb="FF881337"/><sz val="9"/><name val="Calibri"/></font>',
            '<font><b/><color rgb="FF4C1D95"/><sz val="9"/><name val="Calibri"/></font>',
        ];
        $fills = ['none', 'gray125', 'D9D9D9', 'FECDD3', 'DDD6FE', 'DCFCE7', 'FEE2E2', 'FEF3C7', 'DBEAFE', 'F1F5F9',
                  'FFFF00', 'FFFBE0'];
        $fillXml = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach (array_slice($fills, 2) as $color) {
            $fillXml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $color
                . '"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $thin = '<left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right>'
            . '<top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom>';

        // fill, font, border, alignment flags and number format per cellXf
        $xfs = [
            [0, 0, 0, ''],                                   // 0 plain
            [0, 2, 0, 'horizontal="center"'],               // 1 title
            [0, 3, 0, 'horizontal="center"'],               // 2 subtitle
            [2, 4, 1, 'horizontal="center" vertical="center" wrapText="1"'],   // 3 header center
            [2, 4, 1, 'horizontal="left" vertical="center" wrapText="1"'],     // 4 header left
            [0, 5, 1, 'horizontal="left"'],                 // 5 name cell
            [5, 4, 1, 'horizontal="center"'],               // 6 present
            [6, 4, 1, 'horizontal="center"'],               // 7 absent
            [7, 4, 1, 'horizontal="center"'],               // 8 late
            [8, 4, 1, 'horizontal="center"'],               // 9 excused
            [0, 4, 1, 'horizontal="center"'],               // 10 blank mark
            [3, 7, 1, 'horizontal="center" vertical="center" wrapText="1"'],    // 11 holiday banner
            [4, 8, 1, 'horizontal="center" vertical="center" wrapText="1"'],    // 12 seminar banner
            [2, 4, 1, 'horizontal="center" vertical="center" wrapText="1"'],    // 13 totals header
            [9, 4, 1, 'horizontal="center" vertical="center" wrapText="1"'],    // 14 attendance cell
            [0, 5, 0, 'horizontal="left"'],                 // 15 legend
            [10, 4, 1, 'horizontal="center" vertical="bottom" textRotation="90"'],  // 16 yellow date header
            [10, 4, 1, 'horizontal="center" vertical="center" textRotation="90"'],  // 17 yellow event banner
            [11, 5, 1, 'horizontal="center"'],               // 18 light yellow mark cell
            [10, 4, 1, 'horizontal="center" vertical="center" wrapText="1"'],    // 19 yellow cell
            [0, 5, 1, 'horizontal="center"'],               // 20 white number cell
            [10, 4, 1, 'horizontal="center" vertical="center"', '0&quot;%&quot;'],  // 21 yellow percent cell
        ];
        $xfXml = '';
        foreach ($xfs as $xf) {
            list($fill, $font, $border, $align) = $xf;
            // A fifth entry overrides the number format, so a figure stays a number
            // and still reads the way the printed sheet prints it.
            $numFmt = isset($xf[4]) ? $xf[4] : '';
            $xfXml .= '<xf numFmtId="' . ($numFmt !== '' ? '164' : '0') . '" fontId="' . $font
                . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
                . ' applyFont="1"' . ($fill > 0 ? ' applyFill="1"' : '')
                . ($border > 0 ? ' applyBorder="1"' : '')
                . ($numFmt !== '' ? ' applyNumberFormat="1"' : '')
                . ($align !== '' ? ' applyAlignment="1"><alignment ' . $align . '/></xf>' : '/>');
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // Custom formats start at 164 and must be declared before the fonts.
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="' . $xfs[count($xfs) - 1][4]
            . '"/></numFmts>'
            . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
            . '<fills count="' . (count($fills)) . '">' . $fillXml . '</fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>' . $thin . '<diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . $xfXml . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}

if (!function_exists('excelWriteWorkbook')) {
    /**
     * Zips the four parts a one-sheet workbook needs and returns the file path.
     *
     * @param array $parts  Logical name => XML string, as passed to addFromString.
     * @return string|null  The temp file path, or null when it could not be written.
     */
    function excelWriteWorkbook(array $parts) {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'classrecord_export_' . uniqid('', true) . '.xlsx';
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();

        return is_file($file) ? $file : null;
    }
}