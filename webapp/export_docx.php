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

/** The logo, sized to fit a given width in millimetres without upscaling. */
function gradesheet_docx_logo($cell, $logoPath, $targetMm) {
    $info = @getimagesize($logoPath);
    if (!$info) {
        $cell->addText('LOGO', ['size' => 8]);
        return;
    }
    // PhpWord image dimensions are in pixels at 96 dpi.
    $widthPx = min($targetMm / 25.4 * Drawing::DPI_96, $info[0]);
    $heightPx = $widthPx * ($info[1] / $info[0]);
    $cell->addImage($logoPath, ['width' => $widthPx, 'height' => $heightPx]);
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
// Three cells matching the HTML footer's 22 / 26 / 52 split. The four 22 /
    // 23 / 33 / 22 arrays no longer apply: Submitted by moved out of the band
    // and sits between the certification and these cells.
$scaleWidths = array_map('gradesheet_twips', [22]);
$notedWidths = array_map('gradesheet_twips', [26]);
$deanWidths  = array_map('gradesheet_twips', [52]);
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

    // ---- Document-control header table -----------------------------------
    $header = gradesheet_docx_table($section, $borderedTable);

    $header->addRow();
    $logoCell = $header->addCell($headerWidths[0], ['vAlign' => CellStyle::VALIGN_CENTER]);
    if ($haveLogo) {
        gradesheet_docx_logo($logoCell, $logoPath, 24);
    } else {
        $logoCell->addText('LOGO', ['size' => 8]);
    }
    $header->addCell($headerWidths[1])->addText('Document Type: ' . $meta['document_type'], $bodyStyle);
    $header->addCell($headerWidths[2])->addText('Document Code: ' . $meta['document_code'], $bodyStyle);

    gradesheet_docx_row($header, [$headerWidths[1], $headerWidths[2]], [
        $meta['iso_line'],
        'Revision No.: ' . $meta['revision_no'],
    ], $bodyStyle);

    // Document title on the left; effective date over the page counter on the right.
    $header->addRow();
    $header->addCell($headerWidths[1])->addText('Document Title: ' . $meta['document_title'], $bodyStyle);
    $dateCell = $header->addCell($headerWidths[2]);
    $dateCell->addText('Effective Date: ' . $meta['effective_date'], $bodyStyle);
    $dateCell->addText('Page ' . $pageNumber . ' of ' . $totalPages, ['size' => 8]);

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
    // certification top-left, grading scale on the left, signatures right.
    $section->addText('');
    $section->addText($meta['certification'], ['size' => 9]);
    $section->addText('');

    // Names are plain bold text - no underline rule, no border.
    $nameStyle = ['size' => 10.5, 'bold' => true];
    $roleStyle = ['size' => 8.5];
    $labelStyle = ['size' => 9];
    // Signature blocks are centred, matching the HTML footer's centred columns.
    $center = ['jc' => 'center'];

// Submitted by sits between the certification text and the band, matching
// gradesheet_template.php. Word needs a real table for a centred block: a
// paragraph cannot be centred reliably across three stacked lines of differing
// font size, so a one-cell borderless row carries it.
$submitted = gradesheet_docx_table($section, $plainTable);
$submitted->addRow();
$cell = $submitted->addCell(USABLE_WIDTH, $center);
$cell->addText('Submitted by:', ['jc' => 'center'] + $labelStyle);
$cell->addText($meta['facilitator_name'], ['jc' => 'center'] + $nameStyle);
$cell->addText('Course Facilitator', ['jc' => 'center'] + $roleStyle);

$section->addText('');

// Main band: scale on the left, signatures right. Three cells, not four: the
// widths must sum to USABLE_WIDTH with the same 22 / 26 / 52 split the HTML
// footer uses, or the printed sheet and the browser preview stop matching.
$main = gradesheet_docx_table($section, $plainTable);
$main->addRow();

$cell = $main->addCell($scaleWidths[0]);
    $cell->addText('Grading System', ['bold' => true, 'size' => 9]);
    foreach ($data['grading_scale'] as $line) {
        $cell->addText($line, ['size' => 8]);
    }

    // Noted/Received in the middle column.
    $cell = $main->addCell($notedWidths[0], $center);
    $cell->addText('Noted:', array_merge($labelStyle, $center));
    $cell->addText($meta['program_chair'], array_merge($nameStyle, $center));
    $cell->addText('Program Chair', array_merge($roleStyle, $center));
    $cell->addText('', ['size' => 9, 'before' => 200]);
    $cell->addText('Received:', array_merge($labelStyle, $center));
    $cell->addText($meta['registrar'], array_merge($nameStyle, $center));
    $cell->addText('Registrar', array_merge($roleStyle, $center));

    // Dean and the date received fill the remaining width.
    $cell = $main->addCell($deanWidths[0], $center);
    $cell->addText($meta['dean'], array_merge($nameStyle, $center));
    $cell->addText('Dean', array_merge($roleStyle, $center));
    $cell->addText('', ['size' => 9, 'before' => 200]);
    $cell->addText('Date:', array_merge($labelStyle, $center));
    $cell->addText($meta['date_received'], array_merge($nameStyle, $center));

    $section->addText('');
    $section->addText($meta['note'], ['size' => 8, 'italics' => true]);

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
