<?php
/**
 * Grade Sheet PDF export.
 *
 * Renders the shared template (includes/gradesheet_template.php) through Dompdf,
 * or mPDF when Dompdf is not installed. The logo is already a Base64 data URI, so
 * no external file reference is involved and the logo always appears.
 *
 * Usage: export_pdf.php?id=CLASS_ID
 */

// APP_NAME and the other app constants come from config/db.php. Defining them
// here too would emit a "already defined" warning ahead of the binary stream,
// and any bytes printed before a PDF corrupt the download.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gradesheet_data.php';
require_once __DIR__ . '/includes/gradesheet_template.php';

// This endpoint streams a binary file. Any warning or notice printed first would
// be prepended to the PDF and make the download unreadable.
ini_set('display_errors', '0');
error_reporting(E_ALL);

$auth = new Auth();
$auth->requireLogin();

$classId = intval($_GET['id'] ?? 0);
$db = Database::getInstance()->getConnection();

/** Shared preamble: autoloader lookup, login and class ownership. */
function gradesheet_export_prelude($db, $classId) {
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!is_file($autoload)) {
        http_response_code(500);
        exit('PDF export is not installed yet. Run these commands in the '
            . 'project folder:<br><code>composer require dompdf/dompdf</code> '
            . 'then reload this page.');
    }
    require_once $autoload;

    $owner = $db->prepare("SELECT faculty_id FROM class_section WHERE id = ?");
    $owner->bind_param('i', $classId);
    $owner->execute();
    $ownerRow = $owner->get_result()->fetch_assoc();

    if (!$ownerRow) {
        http_response_code(404);
        exit('Class not found.');
    }
    if (intval($ownerRow['faculty_id']) !== intval($GLOBALS['auth']->getFacultyId())) {
        http_response_code(403);
        exit('You are not authorised to export this class report.');
    }

    return $classId;
}

$classId = gradesheet_export_prelude($db, $classId);

$data = gradesheet_load($db, $classId, $_SESSION['faculty_name'] ?? '');
if (!$data) {
    http_response_code(404);
    exit('Class not found.');
}

$html = gradesheet_document($data, 'Grade Sheet - ' . $data['meta']['course_number']);
$fileName = 'GradeSheet_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $data['meta']['course_number']) . '.pdf';

// Dompdf first, mPDF as a fallback.
if (class_exists('Dompdf\Dompdf')) {
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);   // everything needed is already inlined
    $options->set('isHtml5ParserEnabled', true);
    // Without this Dompdf uses the screen styles, where each .sheet keeps its
    // own 10mm padding on top of the page margins, and the report spills onto
    // an extra page. The print rules are what the browser applies too.
    $options->setDefaultMediaType('print');

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html);
    $dompdf->render();

    $dompdf->stream($fileName, ['Attachment' => true]);
    exit;
}

if (class_exists('Mpdf\Mpdf')) {
    $mpdf = new Mpdf\Mpdf(['format' => 'A4-P', 'orientation' => 'P']);
    $mpdf->WriteHTML($html);
    $mpdf->Output($fileName, 'D');
    exit;
}

http_response_code(500);
exit('Neither Dompdf nor mPDF is available. Run <code>composer require dompdf/dompdf</code>.');
