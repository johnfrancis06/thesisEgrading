<?php
/**
 * CLASS RECORD Excel export.
 *
 * Builds the same worksheet the print and PDF views render, as a real .xlsx, so
 * the class record can be opened, edited and handed over in Excel. The workbook
 * is written straight to OOXML by includes/excel_writer.php: PhpSpreadsheet is not
 * a dependency here and PHPWord no longer ships an Excel writer.
 *
 * The columns, the marks and the three calculated figures come from the same
 * includes/classrecord_data.php load the printed sheet uses, so the file cannot
 * disagree with the paper.
 *
 * Usage: classrecord_xlsx.php?id=CLASS_ID
 */

// This endpoint streams a binary file, exactly like classrecord_pdf.php: any
// warning, notice or stray byte printed first would be prepended to the workbook
// and make the download unreadable, so errors are silenced before the includes
// run and any output they produced is discarded before the stream starts.
// APP_NAME and the other app constants come from config/db.php. Defining them
// here too would emit an "already defined" warning ahead of the binary stream.
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gradesheet_data.php';
require_once __DIR__ . '/includes/classrecord_data.php';
require_once __DIR__ . '/includes/classrecord_template.php';
require_once __DIR__ . '/includes/excel_writer.php';

$auth = new Auth();
$auth->requireLogin();

$classId = intval($_GET['id'] ?? 0);
$db = Database::getInstance()->getConnection();

$owner = $db->prepare("SELECT faculty_id FROM class_section WHERE id = ?");
$owner->bind_param('i', $classId);
$owner->execute();
$ownerRow = $owner->get_result()->fetch_assoc();
if (!$ownerRow) {
    http_response_code(404);
    exit('Class not found.');
}
if (intval($ownerRow['faculty_id']) !== intval($auth->getFacultyId())) {
    http_response_code(403);
    exit('You are not authorised to export this class record.');
}

$data = classrecord_load($db, $classId, $_SESSION['faculty_name'] ?? '');
if (!$data) {
    http_response_code(404);
    exit('Class not found.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Excel export is unavailable because the PHP zip extension is not enabled.');
}

// ---------------------------------------------------------------------------
// The worksheet
// ---------------------------------------------------------------------------

$meta = $data['meta'] ?? [];
$rows = $data['rows'] ?? [];

// The column list is the shared builder from the template, so a mark can never
// sit under the wrong date. The print widths are pixels; these are Excel
// character widths over the same proportions.
$plan = classrecord_column_plan($data, 1.0);
$columns = classrecord_columns($data, $plan);

$colWidth = [
    'no'   => 4.5,
    'name' => 34,
    'date' => 4.5,
    'att'  => 11,
    'meet' => 11,
    'pct'  => 13,
];

$lastColumnIndex = count($columns) - 1;
$lastCol = excelColumnName($lastColumnIndex);

// Styles used below, named so the layout reads without counting cellXfs indexes.
$STYLE_TITLE = 1;
$STYLE_SUBTITLE = 2;
$STYLE_NO_HEADER = 3;
$STYLE_NAME_HEADER = 4;
$STYLE_NO_CELL = 20;
$STYLE_NAME_CELL = 5;
$STYLE_MARK = 18;          // light yellow, the printed sheet's #FFFBE0
$STYLE_SECTION = 19;       // solid yellow
$STYLE_DATE_HEADER = 16;   // solid yellow, label written up the column
$STYLE_CALC = 19;          // solid yellow
$STYLE_CALC_PCT = 21;      // solid yellow, number formatted with the sign
$STYLE_FOOT = 15;

$merges = [];
$sheetRows = [];
$rowNum = 0;

/**
 * One worksheet row from a list of [value, style] cells keyed by column index.
 *
 * Cells are written in column order and any gap is still emitted, because Excel
 * reads a sparse row only if the empty cells are present - otherwise the sheet
 * loads as corrupt.
 *
 * A third entry in a cell marks it as a number rather than a label, which is what
 * keeps Attendance, Total Meet and Attendance (%) sortable and summable.
 */
$addRow = function (array $cells, $height = null) use (&$sheetRows, &$rowNum, $lastColumnIndex) {
    $rowNum++;
    $body = '';
    for ($i = 0; $i <= $lastColumnIndex; $i++) {
        $cell = $cells[$i] ?? ['', 0];
        $ref = excelColumnName($i) . $rowNum;
        $body .= !empty($cell[2])
            ? excelNumberCell($ref, $cell[0], $cell[1])
            : excelCell($ref, $cell[0], $cell[1]);
    }
    $attrs = $height ? ' ht="' . $height . '" customHeight="1"' : '';
    $sheetRows[] = '<row r="' . $rowNum . '"' . $attrs . '>' . $body . '</row>';
};

// --- Title block -----------------------------------------------------------
$addRow([['CLASS RECORD', $STYLE_TITLE]], 24);
$merges[] = 'A1:' . $lastCol . '1';

$addRow([[
    'Course Number: ' . ($meta['course_number'] ?? '')
    . '    |    Course Title: ' . ($meta['course_title'] ?? '')
    . '    |    ' . ($meta['semester_term'] ?? '')
    . '    |    Course & Year: ' . ($meta['course_and_year'] ?? '')
    . '    |    Period & Term: ' . ($meta['term_range_label'] ?? ''),
    $STYLE_SUBTITLE,
]], 16);
$merges[] = 'A2:' . $lastCol . '2';

// --- Where each column sits -------------------------------------------------
// $dateCols keeps the meeting-date and event columns separately from the three
// calculation columns, so the "Attendance Sheet" heading spans exactly the block
// the printed sheet spans.
$dateCols = [];
$calcCols = [];
foreach ($columns as $index => $column) {
    if ($column['key'] === 'date') {
        $dateCols[$index] = $column;
    } elseif ($column['key'] !== 'no' && $column['key'] !== 'name') {
        $calcCols[$index] = $column;
    }
}
$firstDateCol = $dateCols ? array_key_first($dateCols) : null;
$lastDateCol = $dateCols ? array_key_last($dateCols) : null;

// --- Header row 1: the section headings ------------------------------------
$sectionRow = $rowNum + 1;
$header = [];
$header[0] = ['No.', $STYLE_NO_HEADER];
$header[1] = ['NAME OF STUDENTS', $STYLE_NAME_HEADER];
$merges[] = 'A' . $sectionRow . ':A' . ($sectionRow + 1);
$merges[] = 'B' . $sectionRow . ':B' . ($sectionRow + 1);

// One Attendance Sheet over every meeting and event column. With no meeting
// dates the heading is left out, exactly as the printed sheet leaves it out,
// rather than forced over a column that does not exist.
if ($firstDateCol !== null) {
    $header[$firstDateCol] = ['Attendance Sheet', $STYLE_SECTION];
    $merges[] = excelColumnName($firstDateCol) . $sectionRow
        . ':' . excelColumnName($lastDateCol) . $sectionRow;
}

foreach ($calcCols as $index => $column) {
    $header[$index] = [implode(' ', $column['lines']), $STYLE_CALC];
    $merges[] = excelColumnName($index) . $sectionRow . ':' . excelColumnName($index) . ($sectionRow + 1);
}
$addRow($header, 18);

// --- Header row 2: the dates, written up the column ------------------------
$dates = [];
foreach ($dateCols as $index => $column) {
    $dates[$index] = [$column['is_break'] ? $column['lines'][0] : $column['label'], $STYLE_DATE_HEADER];
}
$addRow($dates, 62);

// Both header rows are frozen below, so the split sits under the date row.
$headerRows = $rowNum;

// --- The students -----------------------------------------------------------
// An event column is one cell merged down the whole student list carrying the
// event name, the same way the printed sheet does it: it shows no mark, is never
// counted as a meeting, and so never raises Total Meet or Attendance (%).
$firstStudentRow = $rowNum + 1;
foreach ($rows as $index => $row) {
    $cells = [];
    $cells[0] = [$index + 1, $STYLE_NO_CELL, true];
    $cells[1] = [(string)($row['name'] ?? ''), $STYLE_NAME_CELL];

    $overall = $row['overall'] ?? ['attended' => 0, 'meet' => 0, 'percent' => 0];
    foreach ($columns as $colIndex => $column) {
        switch ($column['key']) {
            case 'no':
            case 'name':
                break;

            case 'att':
            case 'meet':
            case 'pct':
                // Written as the number it is, not as the text the sheet prints, so
                // Excel can sort or total the column. Attendance (%) carries the
                // percent sign in its number format instead.
                $cells[$colIndex] = [$overall[$column['field']] ?? 0,
                    !empty($column['is_pct']) ? $STYLE_CALC_PCT : $STYLE_CALC, true];
                break;

            default:
                if ($column['is_break']) {
                    if ($index === 0) {
                        $cells[$colIndex] = [(string)$column['break_caption'], $STYLE_SECTION];
                    }
                    break;
                }
                // A mark is 1 or 0; an unmarked day stays an empty cell.
                $mark = $row['marks'][$column['date_index']] ?? '';
                $cells[$colIndex] = [$mark === '' ? '' : intval($mark), $STYLE_MARK, true];
        }
    }
    $addRow($cells);
}
$lastStudentRow = $rowNum;

if ($lastStudentRow >= $firstStudentRow) {
    foreach ($dateCols as $index => $column) {
        if (!$column['is_break']) {
            continue;
        }
        $ref = excelColumnName($index);
        $merges[] = $ref . $firstStudentRow . ':' . $ref . $lastStudentRow;
    }
}

// --- Footer -----------------------------------------------------------------
// The same note and signature blocks the printed sheet carries, in the same
// reading order: the note, then the first band of signatures, then the second.
// A signature is plain text on three rows - the label, the typed name, the role -
// with a blank row between the bands. No underscore fill-in rule is written and the
// name is not underlined.
$addRow([[(string)($meta['class_record_note'] ?? ''), $STYLE_FOOT]], 14);
$addRow([['', $STYLE_FOOT]], 8);

$signature = function ($label, $name, $role) use (&$addRow, $STYLE_FOOT) {
    $addRow([[$label, $STYLE_FOOT]], 13);
    $addRow([[strtoupper((string)$name), $STYLE_FOOT]], 13);
    $addRow([[$role, $STYLE_FOOT]], 13);
};

$signature('Prepared by:', $meta['facilitator_name_upper'] ?? '', 'Course Facilitator');
$signature('Noted:', $meta['program_chair_upper'] ?? '', 'Program Chair');
$signature('Approved:', $meta['satellite_director_upper'] ?? '', 'Satellite College Director');

$addRow([['', $STYLE_FOOT]], 8);

$signature('Date:', '', '');
$signature('Date:', '', '');
$signature('Date:', '', '');

// --- Sheet XML --------------------------------------------------------------
// Frozen panes at C5 give the worksheet the same two sticky header rows and
// sticky No./name columns the print view has.
$isLegal = strtolower(trim((string)($meta['cr_paper'] ?? 'legal'))) === 'legal';

$cols = '<cols><col min="1" max="1" width="' . $colWidth['no'] . '" customWidth="1"/>'
    . '<col min="2" max="2" width="' . $colWidth['name'] . '" customWidth="1"/>';
foreach ($columns as $index => $column) {
    if ($index < 2) {
        continue;
    }
    $width = !empty($column['is_break']) ? 9.0 : $colWidth[$column['key']];
    $cols .= '<col min="' . ($index + 1) . '" max="' . ($index + 1)
        . '" width="' . $width . '" customWidth="1"/>';
}
$cols .= '</cols>';

$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
    . '<sheetViews><sheetView showGridLines="0" workbookViewId="0">'
    . '<pane xSplit="2" ySplit="' . $headerRows . '" topLeftCell="C' . ($headerRows + 1)
    . '" activePane="bottomRight" state="frozen"/>'
    . '<selection pane="bottomRight" activeCell="C' . ($headerRows + 1)
    . '" sqref="C' . ($headerRows + 1) . '"/></sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="14"/>'
    . $cols
    . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
    . '<mergeCells count="' . count($merges) . '">';
foreach ($merges as $merge) {
    $sheet .= '<mergeCell ref="' . $merge . '"/>';
}
$sheet .= '</mergeCells>'
    . '<pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.2" footer="0.2"/>'
    // 5 = Legal, 9 = A4. fitToWidth keeps the right-hand calculation columns on
    // the page when the term is long, the same job the PDF fit scaling does.
    . '<pageSetup paperSize="' . ($isLegal ? 5 : 9) . '" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
    . '</worksheet>';

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Class Record" sheetId="1" r:id="rId1"/></sheets></workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
    . ' Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
    . ' Target="styles.xml"/></Relationships>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
    . ' Target="xl/workbook.xml"/></Relationships>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml"'
    . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml"'
    . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml"'
    . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>';

$file = excelWriteWorkbook([
    '[Content_Types].xml'        => $contentTypes,
    '_rels/.rels'                => $rootRels,
    'xl/workbook.xml'            => $workbook,
    'xl/_rels/workbook.xml.rels' => $workbookRels,
    'xl/styles.xml'              => excelStylesXml(),
    'xl/worksheets/sheet1.xml'   => $sheet,
]);
if (!$file) {
    http_response_code(500);
    exit('Excel export failed: the workbook could not be written.');
}

// Whatever an include may have printed (a notice from a doubled session_start,
// a PHP warning) has to be dropped now: the workbook stream starts at byte zero.
while (ob_get_level() > 0) {
    ob_end_clean();
}

$fileName = 'ClassRecord_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $data['class']['code'] ?? 'class') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=0');
readfile($file);
unlink($file);
exit;