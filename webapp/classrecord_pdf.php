<?php
/**
 * CLASS RECORD PDF export.
 *
 * Renders the shared class record template (includes/classrecord_template.php)
 * through Dompdf, or mPDF as a fallback. Landscape legal or A4 depending on the
 * class setting, so the file matches what the print view produces.
 *
 * Usage: classrecord_pdf.php?id=CLASS_ID
 */

// This endpoint streams a binary file. Any warning, notice or stray byte printed
// first would be prepended to the PDF and make the download unreadable, so errors
// are silenced before the includes run and any output they produced is discarded
// before the stream starts.
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

$auth = new Auth();
$auth->requireLogin();

$classId = intval($_GET['id'] ?? 0);
$db = Database::getInstance()->getConnection();

if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    http_response_code(500);
    exit('PDF export is not installed yet. Run <code>composer require dompdf/dompdf</code> '
        . 'in the project folder, then reload this page.');
}
require_once __DIR__ . '/../vendor/autoload.php';

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

// No toolbar in the PDF: the buttons would print as page content.
$html = classrecord_document($data, 'Class Record - ' . ($data['class']['code'] ?? ''), false);
$fileName = 'ClassRecord_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $data['class']['code'] ?? 'class') . '.pdf';
$isLegal = strtolower(trim((string)($data['meta']['cr_paper'] ?? 'legal'))) === 'legal';

// Whatever an include may have printed (a notice from a doubled session_start,
// a PHP warning) has to be dropped now: the PDF stream starts at byte zero.
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (class_exists('Dompdf\Dompdf')) {
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);   // everything needed is already inlined
    $options->set('isHtml5ParserEnabled', true);
    // The print rules are what the browser applies too, so the two agree.
    $options->setDefaultMediaType('print');

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->setPaper($isLegal ? 'legal' : 'A4', 'landscape');
    $dompdf->loadHtml($html);
    $dompdf->render();

    $dompdf->stream($fileName, ['Attachment' => true]);
    exit;
}

if (class_exists('Mpdf\Mpdf')) {
    $mpdf = new Mpdf\Mpdf(['format' => ($isLegal ? 'Legal' : 'A4') . '-L', 'orientation' => 'L']);
    $mpdf->WriteHTML($html);
    $mpdf->Output($fileName, 'D');
    exit;
}

http_response_code(500);
exit('Neither Dompdf nor mPDF is available. Run <code>composer require dompdf/dompdf</code>.');