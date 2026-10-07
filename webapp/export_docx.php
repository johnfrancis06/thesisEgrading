<?php
/**
 * Grade Sheet Word (.docx) export.
 *
 * Builds a real OOXML document with PhpWord. The header logo is added from the
 * local file with $cell->addImage(), which EMBEDS the bytes inside the .docx -
 * it is never a linked or relative image, so the file opens correctly anywhere.
 * The header and footer are real tables and paragraphs, not images.
 *
 * Requires ext-gd (enable extension=gd in php.ini) for image embedding.
 *
 * Usage: export_docx.php?id=CLASS_ID
 */

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Drawing;
use PhpOffice\PhpWord\Style\Cell as CellStyle;
use PhpOffice\PhpWord\Style\Section as SectionStyle;
use PhpOffice\PhpWord\Style\Table as TableStyle;

// APP_NAME and the other app constants come from config/db.php. Defining them
// here too would emit a "already defined" warning ahead of the binary stream,
// and any bytes printed before the .docx corrupt the download.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gradesheet_data.php';
require_once __DIR__ . '/includes/gradesheet_template.php';

// This endpoint streams a binary file. Any warning or notice printed first would
// be prepended to the .docx and make the download unreadable.
ini_set('display_errors', '0');
error_reporting(E_ALL);

$auth = new Auth();
$auth->requireLogin();

$classId = intval($_GET['id'] ?? 0);
$db = Database::getInstance()->getConnection();
$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    exit('Word export is not installed yet. Run <code>composer install</code> '
        . 'in the project folder, then reload this page.');
}
require_once $autoload;

if (!class_exists(PhpWord::class)) {
    http_response_code(500);
    exit('PhpWord is not available. Run <code>composer install</code>.');
}
if (!extension_loaded('gd')) {
    http_response_code(500);
    exit('The GD extension is required to embed the logo. Enable '
        . '<code>extension=gd</code> in php.ini and restart Apache.');
}

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
    exit('You are not authorised to export this class report.');
}

$data = gradesheet_load($db, $classId, $_SESSION['faculty_name'] ?? '');
if (!$data) {
    http_response_code(404);
    exit('Class not found.');
}

$meta = $data['meta'];
$rows = $data['rows'];
$perPage = max(1, intval($data['rows_per_page']));
$totalPages = max(1, (int)ceil(count($rows) / $perPage));
$chunks = array_chunk($rows, $perPage) ?: [[]];

/**
 * Usable text width: A4 is 210 mm wide and the margins below are 10 mm a side,
 * leaving 190 mm. The Word writer measures every width in twips (1 mm = 56.69),
 * so column widths are computed from this total and must sum back to it.
 */
define('USABLE_WIDTH', 10772);

/** Shared table styling. */
$borderedTable = [
    'borderSize'        => 6,
    'borderColor'       => '000000',
    'borderInsideHSize' => 4,
    'borderInsideVSize' => 4,
    'cellMargin'        => 40,
    'width'             => USABLE_WIDTH,
    'unit'              => 'dxa',
    'layout'            => TableStyle::LAYOUT_FIXED,
];
$plainTable = [
    'borderSize'        => 0,
    'borderInsideHSize' => 0,
    'borderInsideVSize' => 0,
    'cellMargin'        => 0,
    'width'             => USABLE_WIDTH,
    'unit'              => 'dxa',
    'layout'            => TableStyle::LAYOUT_FIXED,
];

/**
 * Creates a table whose column grid is built from the per-cell widths.
 *
 * The Word writer derives w:tblGrid from the cell widths, so every table must
 * add its cells with an explicit width; there is no separate column-widths
 * call. All widths are twips and must sum to USABLE_WIDTH.
 */
function gradesheet_docx_table($section, array $style) {
    return $section->addTable($style);
}

/** Appends a row of cells, each holding a single text run. */
function gradesheet_docx_row($table, array $widths, array $texts, array $style = [], array $cellStyle = []) {
    $table->addRow();
    foreach ($widths as $i => $width) {
        $table->addCell($width, $cellStyle)->addText((string) ($texts[$i] ?? ''), $style);
    }
    return $table;
}

/** The logo, sized to a target width in millimetres, never upscaled. */
function gradesheet_docx_logo($cell, $logoPath, $targetMm, $center = false) {
    $info = @getimagesize($logoPath);
    if (!$info) {
        $cell->addText('LOGO', ['size' => 8], $center ? ['jc' => 'center'] : null);
        return;
    }
    // This writer renders inline images as VML shapes sized in points (72 pt
    // = 25.4 mm). Cap to the source's natural width in points so it is never
    // upscaled.
    $widthPt  = min($targetMm * 72 / 25.4, $info[0] / Drawing::DPI_96 * 72);
    $heightPt = $widthPt * ($info[1] / $info[0]);
    $style = ['width' => $widthPt, 'height' => $heightPt];
    if ($center) {
        // Inline images are centred by their paragraph: FrameStyle::alignment
        // maps to <w:jc w:val="center"/> written by the image style writer.
        $style['alignment'] = 'center';
    }
    $cell->addImage($logoPath, $style);
}

/** Writes a "Label: Value" pair on one line, with only the value bolded. */
function gradesheet_docx_label_value($cell, $label, $value, $plainStyle, $boldStyle) {
    $run = $cell->addTextRun(['jc' => 'left']);
    $run->addText((string) $label, $plainStyle);
    $run->addText((string) $value, $boldStyle);
}

$phpWord = new PhpWord();
$phpWord->setDefaultFontName('Times New Roman');

// A new PhpWord has no sections yet, so the first one is created here.
$section = $phpWord->addSection();

// A4 portrait with 1.0 cm margins. In this PhpWord version the page setup
// lives on the section style, and getStyle() returns null until one is set.
$sectionStyle = new SectionStyle();
$sectionStyle->setPaperSize('A4');
$sectionStyle->setOrientation(SectionStyle::ORIENTATION_PORTRAIT);
$sectionStyle->setMarginTop(567);      // 1 cm, in twips
$sectionStyle->setMarginBottom(567);
$sectionStyle->setMarginLeft(567);
$sectionStyle->setMarginRight(567);

$section->setStyle($sectionStyle);

$logoPath = __DIR__ . '/assets/images/capsu.jpg';
$haveLogo = is_file($logoPath);

/** Converts a percentage of the usable width into twips. */
function gradesheet_twips($percent) {
    return (int) round(USABLE_WIDTH * $percent / 100);
}

$headerWidths = array_map('gradesheet_twips', [20, 45, 35]);
$infoWidths   = array_map('gradesheet_twips', [50, 50]);
// Four cells matching the HTML footer's 24 / 33 / 22 / 21 colgroup.
// The footer is one five-row table: the grading scale holds the first
// column across all rows (vMerge) and the signature blocks flow in the
// other three, with gridSpan pairs wherever the HTML uses colspan="2".
$footerWidths = array_map('gradesheet_twips', [24, 33, 22, 21]);
// Row heights in twips, matching .gs-r1..gs-r4 in gradesheet_template.php.
$footerRowHeights = [794, 624, 567, 737];
// Must sum to 100% and match gradesheet_template.php so the Word table lines up
// with the browser and PDF versions.
$colWidths    = array_map('gradesheet_twips', [5, 39, 14, 14, 14, 14]);

$bodyStyle = ['size' => 9];
$headStyle = ['bold' => true, 'size' => 9];
// Every grade column is centred except the name, which is left-aligned. This
// mirrors the .gs-table rules in gradesheet_template.php; without it the Word
// file renders left-aligned numbers against a centred browser and PDF sheet.
$bodyCenter = ['jc' => 'center'];
$headCenter = array_merge($headStyle, $bodyCenter);
$nameStyle = ['size' => 9];
$headCell  = ['bgColor' => 'D9D9D9'];
// tblHeader repeats the two heading rows if a table breaks across pages.
$headRowStyle = ['tblHeader' => true];

foreach ($chunks as $pageIndex => $pageRows) {
    $pageNumber = $pageIndex + 1;
    $isLast = ($pageNumber === $totalPages);

    // ---- Document-control header table (REG-F12) ---------------------------
    // Four rows, three columns. PhpWord spells rowspan as vMerge: the leading
    // cell of each span uses vMerge='restart' and every row it continues
    // through carries an empty vMerge='continue' cell, so each row still has
    // three cells in the column grid:
    //   col1 logo           -> rows 1-4 (restart row 1, continue 2-4)
    //   col2 Document Type -> rows 1-2 (restart row 1, continue row 2)
    //   col2 Document Title -> rows 3-4 (restart row 3, continue row 4)
    //   col3 Document Code / Revision No. / Effective Date / Page
    // Only the values are bolded, mirroring gradesheet_value() in the HTML
    // template so the Word header matches the browser and PDF output.
    $header = gradesheet_docx_table($section, $borderedTable);
    $boldValue = $headStyle;            // ['bold' => true, 'size' => 9]
    $isoStyle  = ['size' => 8, 'italic' => true];
    $vmLogo    = ['vMerge' => 'restart', 'vAlign' => CellStyle::VALIGN_CENTER];
    $vmLeft    = ['vMerge' => 'restart', 'vAlign' => CellStyle::VALIGN_TOP];
    $vmCont    = ['vMerge' => 'continue'];

    // Row 1: logo + Document Type box + Document Code.
    $header->addRow();
    $logoCell = $header->addCell($headerWidths[0], $vmLogo);
    if ($haveLogo) {
        gradesheet_docx_logo($logoCell, $logoPath, 20, true);
    } else {
        $logoCell->addText('LOGO', ['size' => 8], ['jc' => 'center']);
    }
    $docTypeCell = $header->addCell($headerWidths[1], $vmLeft);
    gradesheet_docx_label_value($docTypeCell, 'Document Type: ', $meta['document_type'], $bodyStyle, $boldValue);
    $docTypeCell->addText($meta['iso_line'], $isoStyle);
    $codeCell = $header->addCell($headerWidths[2]);
    gradesheet_docx_label_value($codeCell, 'Document Code: ', $meta['document_code'], $bodyStyle, $boldValue);

    // Row 2: Revision No. (Document Type keeps its rowspan=2 hold).
    $header->addRow();
    $header->addCell($headerWidths[0], $vmCont);
    $header->addCell($headerWidths[1], $vmCont);
    $revCell = $header->addCell($headerWidths[2]);
    gradesheet_docx_label_value($revCell, 'Revision No.: ', $meta['revision_no'], $bodyStyle, $boldValue);

    // Row 3: Document Title box + Effective Date.
    $header->addRow();
    $header->addCell($headerWidths[0], $vmCont);
    $docTitleCell = $header->addCell($headerWidths[1], $vmLeft);
    gradesheet_docx_label_value($docTitleCell, 'Document Title: ', $meta['document_title'], $bodyStyle, $boldValue);
    $dateCell = $header->addCell($headerWidths[2]);
    gradesheet_docx_label_value($dateCell, 'Effective Date: ', $meta['effective_date'], $bodyStyle, $boldValue);

    // Row 4: page counter (computed, never editable).
    $header->addRow();
    $header->addCell($headerWidths[0], $vmCont);
    $header->addCell($headerWidths[1], $vmCont);
    $pageCell = $header->addCell($headerWidths[2]);
    $pageCell->addText('Page: ' . $pageNumber . ' of ' . $totalPages, ['size' => 8], ['jc' => 'left']);

    $section->addText('');

    // ---- Course identification strip -------------------------------------
    $info = gradesheet_docx_table($section, $borderedTable);
    gradesheet_docx_row($info, $infoWidths, [
        'Course Number: ' . $meta['course_number'],
        'Course Title: ' . $meta['course_title'],
    ], $bodyStyle);
    gradesheet_docx_row($info, $infoWidths, [
        $meta['semester_ay'],
        'Course and Year: ' . $meta['course_and_year'],
    ], $bodyStyle);

    $section->addText('');

    // ---- Student table ---------------------------------------------------
    $table = gradesheet_docx_table($section, $borderedTable);

    $table->addRow(240, $headRowStyle);
    foreach (['No.', 'Name of Students', 'Numerical', 'Final', 'Unit', 'Remarks'] as $i => $label) {
        $table->addCell($colWidths[$i], $headCell)->addText($label, $headCenter);
    }
    $table->addRow(240, $headRowStyle);
    foreach (['', '(Last, First, MI)', 'Rating', 'Grade', 'Credit', ''] as $i => $label) {
        $style = ($i === 1) ? $headStyle : $headCenter;
        $table->addCell($colWidths[$i], $headCell)->addText($label, $style);
    }

    foreach ($pageRows as $offset => $row) {
        $table->addRow();
        $values = array_merge(
            [(string) ($pageIndex * $perPage + $offset + 1), (string) $row['name']],
            array_map('strval', [
                $row['numerical_rating'], $row['final_grade'],
                $row['unit_credit'],     $row['remarks'],
            ])
        );
        foreach ($values as $i => $value) {
            $style = ($i === 1) ? $bodyStyle : $bodyCenter;
            $table->addCell($colWidths[$i])->addText($value, $style);
        }
    }

    // ---- Certification, signatures and grading scale ---------------------
    // Every sheet carries the footer, matching the browser and PDF output:
    // certification top-left, then one five-row table with the grading
    // scale down the left and the signature blocks to its right.
    $section->addText('');
    $section->addText($meta['certification'], ['size' => 10]);
    $section->addText('');

    // Signature names are bold and underlined (.gs-ul in the HTML
    // footer); roles sit under each block at 10pt.
    $nameStyle       = ['size' => 10, 'bold' => true, 'underline' => 'single'];
    $roleStyle       = ['size' => 10];
    $labelStyle      = ['size' => 10];
    $notedLabelStyle = ['size' => 8.5];
    // Signature blocks are left-aligned, matching the HTML
    // footer's .gs-center rule, with the same 50px indent
    // (50px = 750 twips at 96dpi).
    $left     = ['jc' => 'left'];
    $leftCell = ['jc' => 'left', 'marginLeft' => 750];
    $right  = ['jc' => 'right'];
    // 9mm of right padding for the right-aligned labels, matching the
    // .gs-submitted-label / .gs-received-label padding.
    $labelCell = ['marginRight' => 510];

    $main = gradesheet_docx_table($section, $plainTable);

    // Row 1: grading scale (spans all five rows) | Submitted by | facilitator.
    $main->addRow($footerRowHeights[0]);
    $cell = $main->addCell($footerWidths[0], ['vMerge' => 'restart']);
    // Two blank lines push the scale down, matching the 9mm padding-top
    // of .gs-cell-scale.
    $cell->addText('', ['size' => 10]);
    $cell->addText('', ['size' => 10]);
    $cell->addText('Grading System:', ['bold' => true, 'size' => 10]);
    foreach ($data['grading_scale'] as $line) {
        // Show "1.0 - 99-100" like the printed form (the data may use "=").
        $line = preg_replace('/\s*=\s*/', ' - ', (string) $line, 1);
        $cell->addText($line, ['size' => 9.5]);
    }
    $cell = $main->addCell($footerWidths[1], $labelCell);
    $cell->addText('Submitted by:', $labelStyle + $right);
    $cell = $main->addCell($footerWidths[2] + $footerWidths[3], $leftCell + ['gridSpan' => 2]);
    $cell->addText($meta['facilitator_name'], $nameStyle);
    $cell->addText('Course Facilitator', $roleStyle + $left);

    // Row 2: Noted (program chair) | Dean.
    $main->addRow($footerRowHeights[1]);
    $main->addCell($footerWidths[0], ['vMerge' => 'continue']);
    $cell = $main->addCell($footerWidths[1] + $footerWidths[2], ['gridSpan' => 2]);
    $run = $cell->addTextRun();
    $run->addText('Noted: ', $notedLabelStyle);
    $run->addText($meta['program_chair'], $nameStyle);
    $cell->addText('Program Chair', $roleStyle + $left);
    $cell = $main->addCell($footerWidths[3], $leftCell);
    $cell->addText($meta['dean'], $nameStyle);
    $cell->addText('Dean', $roleStyle + $left);

    // Row 3: the Dean's date. Four plain cells so the table grid keeps
    // all four columns (the writer builds w:tblGrid from the widest row).
    $main->addRow($footerRowHeights[2]);
    $main->addCell($footerWidths[0], ['vMerge' => 'continue']);
    $main->addCell($footerWidths[1]);
    $cell = $main->addCell($footerWidths[2]);
    $run = $cell->addTextRun();
    $run->addText('Date: ', $labelStyle);
    $run->addText((string) $meta['dean_date'], $nameStyle);
    $main->addCell($footerWidths[3]);

    // Row 4: Received | registrar.
    $main->addRow($footerRowHeights[3]);
    $main->addCell($footerWidths[0], ['vMerge' => 'continue']);
    $cell = $main->addCell($footerWidths[1], $labelCell);
    $cell->addText('Received:', $labelStyle + $right);
    $cell = $main->addCell($footerWidths[2] + $footerWidths[3], $leftCell + ['gridSpan' => 2]);
    $cell->addText($meta['registrar'], $nameStyle);
    $cell->addText('Registrar', $roleStyle + $left);

    // Row 5: the registrar's date, same four-cell layout as row 3.
    $main->addRow();
    $main->addCell($footerWidths[0], ['vMerge' => 'continue']);
    $main->addCell($footerWidths[1]);
    $cell = $main->addCell($footerWidths[2]);
    $run = $cell->addTextRun();
    $run->addText('Date: ', $labelStyle);
    $run->addText((string) $meta['date_received'], $nameStyle);
    $main->addCell($footerWidths[3]);

    $section->addText('');
    $section->addText($meta['note'], ['size' => 9]);

    if (!$isLast) {
        $section->addPageBreak();
    }
}

$fileName = 'GradeSheet_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $meta['course_number']) . '.docx';

IOFactory::createWriter($phpWord, 'Word2007')->save($fileName);

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($fileName));
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

readfile($fileName);
unlink($fileName);
exit;
