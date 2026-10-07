<?php
/**
 * Shared data loader for the GRADE SHEET report.
 *
 * Used by the preview, the print view, export_pdf.php and export_docx.php so that
 * every one of them renders the same (edited, saved) values. Grading itself is
 * untouched: this only reads GradingHelper::calculateStudentGrades() and layers
 * the report_settings overrides on top of the computed cells.
 */

/**
 * Reads the university logo and returns it as a Base64 data URI.
 *
 * Exports must never reference a URL or relative path for the logo, because
 * Word and PDF renderers cannot follow them. Returns an empty string when the
 * file is missing so the caller can fall back to a text placeholder.
 */
function gradesheet_logo_data_uri($path = null) {
    if ($path === null) {
        $path = __DIR__ . '/../assets/images/capsu.jpg';
    }
    if (!is_file($path)) {
        return '';
    }

    $data = @file_get_contents($path);
    if ($data === false || $data === '') {
        return '';
    }

    $info = @getimagesize($path);
    $mime = ($info && !empty($info['mime'])) ? $info['mime'] : 'image/png';

    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

/** Default report metadata, used when the class has no saved overrides yet. */
function gradesheet_defaults($class, $facultyName = '') {
    $semester = ((int)($class['semester'] ?? 1) === 2) ? '2nd' : '1st';
    $code = trim(($class['code'] ?? '') . ' ' . ($class['title'] ?? ''));
    $program = trim(($class['course_program'] ?? '') . ' '
        . ($class['year_level'] ?? '') . '-' . ($class['section'] ?? ''));

    return [
        'university_name'   => 'Capiz State University',
        'iso_line'          => 'ISO 9001:2015',
        'document_type'     => 'FORM',
        'document_title'    => 'GRADE SHEET',
        'document_code'     => 'REG-F12',
        'revision_no'       => '00',
        'effective_date'    => 'June 25, 2018',
        'course_number'     => $class['code'] ?? '',
        'course_title'      => $class['title'] ?? '',
        'semester_ay'       => $semester . ' Semester/Semester AY ' . ($class['academic_year'] ?? ''),
        'course_and_year'   => $program,
        'certification'     => 'I hereby certify that the above grades are true and correct.',
        'facilitator_name'  => $facultyName,
        'program_chair'     => '',
        'dean'              => '',
        'registrar'         => '',
        'date_received'     => '',
        'dean_date'         => '',
        'note'              => 'Note: To be submitted in two (2) copies together with the class list.',
    ];
}

/**
 * Full words for the Remarks column.
 *
 * The computed remark is a code, but the grading scale printed in the footer
 * spells them out, so the sheet has to as well.
 */
function gradesheet_remarks_text($remark) {
    $codes = ['INC' => 'Incomplete', 'DRP' => 'Dropped'];
    $remark = trim((string)$remark);
    return $codes[strtoupper($remark)] ?? ($remark !== '' ? $remark : 'Incomplete');
}

/**
 * Formats a raw score for print: up to two decimals, trailing zeros trimmed.
 *
 * 87.3 stays "87.3", 87.00 becomes "87", 96.50 becomes "96.5". Zero returns an
 * empty string so the caller can substitute INC.
 */
function gradesheet_format_score($score) {
    $score = round(floatval($score), 2);
    if ($score <= 0) {
        return '';
    }
    $text = number_format($score, 2, '.', '');
    return rtrim(rtrim($text, '0'), '.');
}

/** The fixed grading scale printed in the footer of every sheet. */
function gradesheet_grading_scale() {
    return [
        '1.0  = 99-100',
        '1.25 = 96-98',
        '1.5  = 93-95',
        '1.75 = 90-92',
        '2.0  = 87-89',
        '2.25 = 84-86',
        '2.50 = 81-83',
        '2.75 = 78-80',
        '3.0  = 75-77',
        '5.0  = Below 75% (Failed)',
        'INC  = Incomplete',
        'DRP  = Dropped',
    ];
}

/**
 * Builds the full data array the template renders from.
 *
 * @param mysqli $db
 * @param int    $classId
 * @param string $facultyName Name of the logged-in faculty, used as the default facilitator.
 * @return array|null Null when the class does not exist.
 */
function gradesheet_load($db, $classId, $facultyName = '') {
    $classId = intval($classId);
    if ($classId <= 0) {
        return null;
    }

    $stmt = $db->prepare("SELECT cs.*, s.code, s.title
                          FROM class_section cs
                          JOIN subject s ON cs.subject_id = s.id
                          WHERE cs.id = ?");
    $stmt->bind_param('i', $classId);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc();
    if (!$class) {
        return null;
    }

    $students = $db->query("SELECT * FROM student
                            WHERE class_section_id = $classId
                            ORDER BY last_name, first_name")->fetch_all(MYSQLI_ASSOC);

    // Computed grades first; report_settings overrides are layered on after.
    $rows = [];
    foreach ($students as $student) {
        $grades = GradingHelper::calculateStudentGrades($db, $classId, $student['id']);

        $midtermRating = round($grades['midterm']['grade'] ?? 0);

        // The printed score is the unrounded overall, so it reads as 87.3 rather
        // than 87, and the grade point is converted from that exact value. Using
        // the rounded figure instead would move a score such as 86.7 into the
        // 2.00 band instead of the 2.25 band it belongs to.
        $overallRaw = floatval($grades['overall']['grade_raw'] ?? 0);

        // Converted from the grade_scale bands: 0/75/78/81/84/87/90/93/96/99.
        $overallPoint = GradingHelper::getGradePointFromDB($db, $overallRaw);

        // An incomplete record has no final result to report, so the sheet shows
        // INC instead of the 5.00 the conversion returns for a failing score,
        // and leaves the unit credit blank rather than crediting an unfinished
        // subject.
        $isComplete = !empty($grades['overall']['complete']);

        $rows[] = [
            'student_id'      => (int)$student['id'],
            'student_no'      => $student['student_no'] ?? '',
            'name'            => trim(($student['last_name'] ?? '') . ', '
                                  . ($student['first_name'] ?? '') . ' '
                                  . ($student['middle_initial'] ?? '')),
            'midterm_rating'  => $midtermRating ?: 'INC',
            'midterm_remarks' => $grades['midterm']['remarks'] ?? 'INC',
            'numerical_rating'=> gradesheet_format_score($overallRaw) ?: 'INC',
            'final_grade'     => $isComplete ? number_format($overallPoint, 2) : 'INC',
            'unit_credit'     => $isComplete ? '3' : '',
            'remarks'         => gradesheet_remarks_text($grades['overall']['remarks'] ?? 'INC'),
        ];
    }

    $meta = gradesheet_defaults($class, $facultyName);
    $overrides = gradesheet_load_settings($db, $classId);

    // Saved metadata wins over the defaults.
    foreach ($meta as $key => $value) {
        if (array_key_exists($key, $overrides)) {
            $meta[$key] = $overrides[$key];
        }
    }

    // Saved cell edits win over the computed values.
    foreach ($rows as &$row) {
        foreach (['midterm_rating', 'midterm_remarks', 'numerical_rating',
                  'final_grade', 'unit_credit', 'remarks'] as $column) {
            $key = 'cell:' . $row['student_id'] . ':' . $column;
            if (array_key_exists($key, $overrides)) {
                $row[$column] = $overrides[$key];
            }
        }
    }
    unset($row);

    return [
        'class'          => $class,
        'meta'           => $meta,
        'rows'           => $rows,
        'grading_scale'  => gradesheet_grading_scale(),
        'logo_data_uri'  => gradesheet_logo_data_uri(),
        'rows_per_page'  => gradesheet_rows_per_page(),
    ];
}

/** Loads every saved override for a class as a flat key => value map. */
function gradesheet_load_settings($db, $classId) {
    $classId = intval($classId);
    $out = [];
    $stmt = $db->prepare("SELECT field_key, field_value FROM report_settings
                          WHERE class_section_id = ?");
    $stmt->bind_param('i', $classId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $out[$row['field_key']] = $row['field_value'];
    }
    return $out;
}

/** How many students fit on one A4 sheet, overridable per class. */
function gradesheet_rows_per_page() {
    $value = intval(gradesheet_setting('rows_per_page', '20'));
    if ($value < 5) {
        $value = 20;
    }
    return min($value, 60);
}

/** Reads a plain app setting from the filesystem-independent settings helper. */
function gradesheet_setting($key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $file = __DIR__ . '/../config/gradesheet_settings.php';
        if (is_file($file)) {
            $loaded = include $file;
            if (is_array($loaded)) {
                $cache = $loaded;
            }
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}
