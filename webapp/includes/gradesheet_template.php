<?php
/**
 * Shared GRADE SHEET template.
 *
 * One builder used by the preview, the browser print view, export_pdf.php and as
 * the layout reference for export_docx.php. The header and footer are real HTML
 * (tables and text) rather than images, so nothing can break in Word or PDF and
 * every value can be edited in place.
 *
 * @param array $data Output of gradesheet_load().
 * @return string HTML for one or more .sheet blocks.
 */

if (!function_exists('gradesheet_render')) {

    function gradesheet_e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /** Data URI logo, or a bordered text placeholder when the file is absent. */
    function gradesheet_logo_html($dataUri) {
        if ($dataUri !== '') {
            return '<img class="gs-logo" src="' . gradesheet_e($dataUri) . '" alt="University logo">';
        }
        return '<div class="gs-logo-placeholder">LOGO<br><small>assets/img/csu-logo.png</small></div>';
    }

    /**
     * Emits a value that may be edited in place.
     *
     * The header and footer repeat on every sheet, so the same data-field key
     * appears more than once. Every copy is editable, and pages/reports.php
     * resolves the duplicates by preferring whichever copy the user actually
     * typed into, so an edit on one page is never lost to an untouched copy on
     * another page.
     */
    function gradesheet_value($value, $field) {
        return '<b data-field="' . $field . '">' . gradesheet_e($value) . '</b>';
    }

    /**
     * The document-control header table.
     *
     * @param string $pageLabel e.g. "Page 1 of 2"
     */
    function gradesheet_header_html($meta, $logoDataUri, $pageLabel) {
        $e = 'gradesheet_e';
        $html  = '<table class="gs-header">';
        $html .= '<colgroup><col style="width:20%"><col style="width:45%"><col style="width:35%"></colgroup>';

        // Row 1: logo spans all three rows.
        $html .= '<tr>';
        $html .= '<td rowspan="3" class="gs-header-logo">' . gradesheet_logo_html($logoDataUri) . '</td>';
        $html .= '<td class="gs-kv">Document Type: ' . gradesheet_value($meta['document_type'], 'document_type') . '</td>';
        $html .= '<td class="gs-kv">Document Code: ' . gradesheet_value($meta['document_code'], 'document_code') . '</td>';
        $html .= '</tr>';

        // Row 2: ISO line, revision number.
        $html .= '<tr>';
        $html .= '<td class="gs-kv">' . $e($meta['iso_line']) . '</td>';
        $html .= '<td class="gs-kv">Revision No.: ' . gradesheet_value($meta['revision_no'], 'revision_no') . '</td>';
        $html .= '</tr>';

        // Row 3: document title, effective date and the page counter. The page
        // number is computed, never editable, so it cannot be broken by hand.
        $html .= '<tr>';
        $html .= '<td class="gs-kv">Document Title: ' . gradesheet_value($meta['document_title'], 'document_title') . '</td>';
        $html .= '<td class="gs-kv">Effective Date: ' . gradesheet_value($meta['effective_date'], 'effective_date')
               . '<br><span class="gs-page">Page ' . $e($pageLabel) . '</span></td>';
        $html .= '</tr>';

        $html .= '</table>';
        return $html;
    }

    /** The course identification strip under the header. */
    function gradesheet_course_info_html($meta) {
        $e = 'gradesheet_e';
        $html  = '<table class="gs-course-info">';
        $html .= '<tr>';
        $html .= '<td class="gs-kv">Course Number: ' . gradesheet_value($meta['course_number'], 'course_number') . '</td>';
        $html .= '<td class="gs-kv">Course Title: ' . gradesheet_value($meta['course_title'], 'course_title') . '</td>';
        $html .= '</tr>';
        $html .= '<tr>';
        $html .= '<td class="gs-kv">' . gradesheet_value($meta['semester_ay'], 'semester_ay') . '</td>';
        $html .= '<td class="gs-kv">Course and Year: ' . gradesheet_value($meta['course_and_year'], 'course_and_year') . '</td>';
        $html .= '</tr>';
        $html .= '</table>';
        return $html;
    }

    /** The student score table. */
    function gradesheet_table_html($rows, $startIndex) {
        $e = 'gradesheet_e';
        $html  = '<table class="gs-table">';
        $html .= '<thead><tr>'
               . '<th class="c-no">No.</th>'
               . '<th class="c-name">Name of Students<br>(Last, First, MI)</th>'
               . '<th class="c-rate">Midterm<br>Rating</th>'
               . '<th class="c-rem">Midterm<br>Remarks</th>'
               . '<th class="c-rate">Numerical<br>Rating</th>'
               . '<th class="c-rate">Final<br>Grade</th>'
               . '<th class="c-rate">Unit<br>Credit</th>'
               . '<th class="c-rem">Remarks</th>'
               . '</tr></thead><tbody>';

        $columns = ['midterm_rating', 'midterm_remarks', 'numerical_rating',
                    'final_grade', 'unit_credit', 'remarks'];

        foreach ($rows as $offset => $row) {
            $number = $startIndex + $offset + 1;
            $html .= '<tr>';
            $html .= '<td class="c-no">' . $number . '</td>';
            $html .= '<td class="c-name gs-edit" data-field="cell:' . (int)$row['student_id'] . ':name">'
                   . $e($row['name']) . '</td>';
            foreach ($columns as $column) {
                $html .= '<td class="gs-edit" data-field="cell:' . (int)$row['student_id'] . ':' . $column . '">'
                       . $e($row[$column]) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * The certification / signature / grading-scale footer, as HTML text.
     *
     * The footer repeats on every sheet and every copy is editable. The keys
     * repeat with it, which is fine because pages/reports.php prefers the copy
     * the user actually typed into.
     */
    function gradesheet_footer_html($meta, $scale) {
        $e = 'gradesheet_e';

        $html  = '<div class="footer-block gs-footer">';

        $html .= '<p class="gs-certify" data-field="certification">' . $e($meta['certification']) . '</p>';

        $html .= '<table class="gs-signatures"><tr>';

        // [label, [ [field, role, extra class], ... ]]
        $columns = [
            ['Submitted by:', [['facilitator_name', 'Course Facilitator', '']]],
            ['Noted:',       [['program_chair', 'Program Chair', ''], ['dean', 'Dean', 'gs-sig-gap']]],
            ['Received:',    [['registrar', 'Registrar', ''], ['date_received', 'Date', 'gs-sig-gap']]],
        ];

        foreach ($columns as $column) {
            $html .= '<td class="gs-sig-col">';
            $html .= '<div class="gs-sig-label">' . $column[0] . '</div>';
            foreach ($column[1] as $pair) {
                $html .= '<div class="gs-sig-line ' . $pair[2] . '" data-field="' . $pair[0] . '">'
                       . $e($meta[$pair[0]]) . '</div>';
                $html .= '<div class="gs-sig-role">' . $pair[1] . '</div>';
            }
            $html .= '</td>';
        }

        $html .= '</tr></table>';

        $html .= '<table class="gs-scale"><tr><td colspan="2" class="gs-scale-title">Grading System</td></tr>';
        foreach (array_chunk($scale, 2) as $pair) {
            $html .= '<tr>';
            $html .= '<td>' . $e($pair[0]) . '</td>';
            $html .= '<td>' . $e($pair[1] ?? '') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        $html .= '<p class="gs-note" data-field="note">' . $e($meta['note']) . '</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Renders every A4 sheet. Header repeats on each page, footer only on the last.
     *
     * @return string Concatenated .sheet blocks.
     */
    function gradesheet_render($data) {
        $rows = $data['rows'] ?? [];
        $perPage = max(1, intval($data['rows_per_page'] ?? 20));

        $chunks = array_chunk($rows, $perPage);
        if (empty($chunks)) {
            $chunks = [[]];
        }
        $totalPages = count($chunks);

        $html = '';
        foreach ($chunks as $pageIndex => $pageRows) {
            $pageNumber = $pageIndex + 1;
            $isLast = ($pageNumber === $totalPages);

            $html .= '<section class="sheet" data-page="' . $pageNumber . '">';
            $html .= gradesheet_header_html($data['meta'], $data['logo_data_uri'] ?? '',
                                            $pageNumber . ' of ' . $totalPages);
            $html .= gradesheet_course_info_html($data['meta']);
            $html .= gradesheet_table_html($pageRows, $pageIndex * $perPage);
            $html .= gradesheet_footer_html($data['meta'], $data['grading_scale'] ?? []);
            $html .= '</section>';
        }

        return $html;
    }

    /**
     * Stylesheet for the sheet. Split into print and screen halves so the same
     * rules serve the on-screen preview, browser print, Dompdf and mPDF.
     */
    function gradesheet_css() {
        return '
        .sheet {
            width: 210mm;
            min-height: 297mm;
            padding: 10mm;
            box-sizing: border-box;
            background: #fff;
            margin: 0 auto 8mm;
            color: #000;
            font-family: "Times New Roman", Times, serif;
            font-size: 10pt;
        }
        /* Percentage column widths must cover padding and border. Left at the
           default content-box, the width applies to the text area only, the
           padding is added on top, the total overshoots 100% and the browser
           rescales every column - so the columns no longer match the values
           written here or the .docx column widths. */
        .sheet table, .sheet th, .sheet td { box-sizing: border-box; }
        .gs-header { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
        .gs-header td { border: 1px solid #000; padding: 1mm 1.5mm; vertical-align: middle; }
        .gs-header-logo { text-align: center; width: 20%; }
        .gs-logo { max-width: 100%; max-height: 26mm; object-fit: contain; }
        .gs-logo-placeholder {
            border: 1px dashed #999; color: #777; font-size: 7pt; line-height: 1.2;
            padding: 4mm 1mm; font-family: Arial, sans-serif;
        }
        .gs-kv { font-size: 9pt; }
        .gs-page { font-size: 8pt; }

        .gs-course-info { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
        .gs-course-info td { border: 1px solid #000; padding: 1mm 1.5mm; font-size: 9pt; }

        .gs-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .gs-table th, .gs-table td {
            border: 1px solid #000; padding: 0.6mm 1mm;
            text-align: center; vertical-align: middle; font-size: 9pt;
        }
        .gs-table thead th { background: #d9d9d9; font-weight: bold; }
        /* These must add up to 100%. With table-layout:fixed the browser rescales
           any leftover, so a partial total silently distorts every column and
           stops matching the .docx column widths. */
        .gs-table .c-no { width: 5%; }
        .gs-table .c-name { width: 34%; text-align: left; }
        .gs-table .c-rate, .gs-table .c-rem { width: 10.1667%; }

        .footer-block { page-break-inside: avoid; break-inside: avoid; }
        .gs-footer { margin-top: 4mm; font-size: 9pt; }
        .gs-certify { margin: 0 0 2mm; font-size: 9.5pt; }
        .gs-signatures { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
        .gs-signatures td { border: 0; padding: 0 3mm 0 0; vertical-align: top; width: 33.333%; }
        .gs-sig-label { font-size: 8.5pt; margin-bottom: 0.5mm; }
        .gs-sig-line {
            border-bottom: 1px solid #000; min-height: 5mm;
            font-size: 9.5pt; font-weight: bold; padding: 0 0.5mm;
        }
        .gs-sig-gap { margin-top: 6mm; }
        .gs-sig-role { font-size: 8pt; margin-top: 0.5mm; }
        .gs-scale { width: 60%; border-collapse: collapse; font-size: 8pt; }
        .gs-scale td { border: 0; padding: 0.3mm 0; }
        .gs-scale-title { font-weight: bold; text-decoration: underline; margin-bottom: 0.5mm; }
        .gs-note { margin: 1.5mm 0 0; font-size: 8pt; font-style: italic; }

        /* Edit-mode affordances: dashed outline only while editing, never on paper. */
        .gs-edit { cursor: text; }
        body.gs-editing .gs-edit,
        body.gs-editing [data-field] {
            outline: 1px dashed #4a90d9;
            outline-offset: 1px;
            background: #f7fbff;
        }
        @media print {
            /* Printing or exporting while Edit Mode is on must not show the
               editing affordances. */
            body.gs-editing .gs-edit,
            body.gs-editing [data-field] {
                outline: none !important;
                outline-offset: 0 !important;
                background: transparent !important;
            }
            .gs-edit { cursor: auto; }
        }
        ';
    }

    /** Print rules. The sheet itself supplies the 10mm inset, so @page must not
     *  add a second one and overflow the printable area. */
    function gradesheet_print_css() {
        return '
        @page { size: A4 portrait; margin: 10mm; }
        @media print {
            body * { visibility: hidden; }
            .sheet, .sheet * { visibility: visible; }

            /* Sheets MUST stay in normal flow. Using position:absolute here (as a
               common print hack suggests) takes every sheet out of the flow, so
               they all stack at left:0/top:0 and the whole report prints as a
               single overlapping page. */
            .sheet {
                position: static;
                width: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
                box-shadow: none;
                background: #fff;
                page-break-after: always;
                break-after: page;
            }
            .sheet:last-child { page-break-after: auto; break-after: auto; }

            /* Drop the app chrome padding so nothing offsets the sheets. */
            .main-content, .content-area-modern, .card, .card-body, .gs-preview-host {
                padding: 0 !important; margin: 0 !important;
                border: 0 !important; background: #fff !important;
                overflow: visible !important;
            }

            .no-print, .edit-toolbar { display: none !important; }
            thead { display: table-header-group; }
            tr, .footer-block { page-break-inside: avoid; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        ';
    }

    /** Wraps sheet HTML in a standalone document. Used by the print view and PDF export. */
    function gradesheet_document($data, $title = 'Grade Sheet', $bodyClass = '') {
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<title>' . gradesheet_e($title) . '</title>'
            . '<style>' . gradesheet_css() . gradesheet_print_css() . '</style>'
            . '</head><body' . ($bodyClass !== '' ? ' class="' . gradesheet_e($bodyClass) . '"' : '') . '>'
            . gradesheet_render($data)
            . '</body></html>';
    }
}
