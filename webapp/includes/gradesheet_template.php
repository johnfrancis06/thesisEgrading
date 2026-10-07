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
        return '<div class="gs-logo-placeholder">LOGO<br><small>assets/images/capsu.jpg</small></div>';
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
        $v = 'gradesheet_value';

        // One flat table: logo | center | control label | control value.
        // The right-hand document-control cells are direct cells of this
        // table, so the grid lines run continuously and no cell holds a
        // second bordered table inside it.
        $html  = '<table class="gs-header">';
        $html .= '<colgroup><col style="width:15%"><col style="width:58%"><col style="width:15.5%"><col style="width:11.5%"></colgroup>';

        // Row 1: Logo spans all 4 rows; Center top spans 2; Document Code
        $html .= '<tr>';
        $html .= '<td rowspan="4" class="gs-header-logo">' . gradesheet_logo_html($logoDataUri) . '</td>';
        $html .= '<td rowspan="2" class="gs-center-top">';
        $html .= '<div class="gs-doc-type-label">Document Type:</div>';
        $html .= '<div class="gs-doc-type-value">' . $v($meta['document_type'], 'document_type') . '</div>';
        $html .= '<div class="gs-iso">' . $e($meta['iso_line']) . '</div>';
        $html .= '</td>';
        $html .= '<td class="gs-control-label">Document Code</td>';
        $html .= '<td class="gs-control-value">' . $v($meta['document_code'], 'document_code') . '</td>';
        $html .= '</tr>';

        // Row 2: Revision No. (Center top continues via rowspan)
        $html .= '<tr>';
        $html .= '<td class="gs-control-label">Revision No.</td>';
        $html .= '<td class="gs-control-value">' . $v($meta['revision_no'], 'revision_no') . '</td>';
        $html .= '</tr>';

        // Row 3: Center bottom starts rowspan=2; Effective Date
        $html .= '<tr>';
        $html .= '<td rowspan="2" class="gs-center-bottom">';
        $html .= '<div class="gs-doc-title-label">Document Title:</div>';
        $html .= '<div class="gs-doc-title-value">' . $v($meta['document_title'], 'document_title') . '</div>';
        $html .= '</td>';
        $html .= '<td class="gs-control-label">Effective Date</td>';
        $html .= '<td class="gs-control-value">' . $v($meta['effective_date'], 'effective_date') . '</td>';
        $html .= '</tr>';

        // Row 4: Page (Center bottom continues via rowspan)
        $html .= '<tr>';
        $html .= '<td class="gs-control-label">Page</td>';
        $html .= '<td class="gs-control-value">' . $e($pageLabel) . '</td>';
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

    /**
     * The student score table.
     *
     * Midterm Rating and Midterm Remarks are deliberately not printed. The midterm
     * period is still graded, weighted and stored - only these two columns are left
     * out of the sheet, so the report shows the final result and its remarks.
     */
    function gradesheet_table_html($rows, $startIndex) {
        $e = 'gradesheet_e';
        $html  = '<table class="gs-table">';
        $html .= '<thead><tr>'
               . '<th class="c-no">No.</th>'
               . '<th class="c-name">Name of Students<br>(Last, First, MI)</th>'
               . '<th class="c-rate">Numerical<br>Rating</th>'
               . '<th class="c-rate">Final<br>Grade</th>'
               . '<th class="c-rate">Unit<br>Credit</th>'
               . '<th class="c-rem">Remarks</th>'
               . '</tr></thead><tbody>';

        $columns = ['numerical_rating', 'final_grade', 'unit_credit', 'remarks'];

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

        // Certification sits alone at the top-left of the footer.
        $html .= '<p class="gs-certify" data-field="certification">' . $e($meta['certification']) . '</p>';

        // Submitted by sits on its own line between the certification and the
        // band, centred on the page. It was the third cell of the four-cell band,
        // which left it visually attached to the grading scale rather than to the
        // declaration above it - the faculty member signs their own submission,
        // not the scale.
        $html .= '<div class="gs-submitted-row">';
        $html .= '<div class="gs-sig-label">Submitted by:</div>';
        $html .= '<div class="gs-sig-name" data-field="facilitator_name">' . $e($meta['facilitator_name']) . '</div>';
        $html .= '<div class="gs-sig-role">Course Facilitator</div>';
        $html .= '</div>';

// Main band: grading scale on the left, signatures to its right.
        // A table rather than display:grid because Dompdf and mPDF do not
        // implement grid, so a grid footer would print differently from the
        // browser preview.
        //
        // Three cells, not four. Noted/Received keep their own column so they
        // sit further left than the Dean block without dragging it along.
        $html .= '<table class="gs-footer-main"><tr>';

        $html .= '<td class="gs-cell gs-cell-scale">';
        $html .= '<div class="gs-scale-title">Grading System</div>';
        $html .= '<div class="gs-scale-list">';
        foreach ($scale as $line) {
            $html .= '<div class="gs-scale-row">' . $e($line) . '</div>';
        }
        $html .= '</div></td>';

        // Centre-left: Noted (program chair) above Received (registrar).
        $html .= '<td class="gs-cell gs-cell-noted">';
        $html .= '<div class="gs-sig-label">Noted:</div>';
        $html .= '<div class="gs-sig-name" data-field="program_chair">' . $e($meta['program_chair']) . '</div>';
        $html .= '<div class="gs-sig-role">Program Chair</div>';
        $html .= '<div class="gs-sig-gap"></div>';
        $html .= '<div class="gs-sig-label">Received:</div>';
        $html .= '<div class="gs-sig-name" data-field="registrar">' . $e($meta['registrar']) . '</div>';
        $html .= '<div class="gs-sig-role">Registrar</div>';
        $html .= '</td>';

        // Right: Dean and the date received.
        $html .= '<td class="gs-cell gs-cell-right">';
        $html .= '<div class="gs-sig-name" data-field="dean">' . $e($meta['dean']) . '</div>';
        $html .= '<div class="gs-sig-role">Dean</div>';
        $html .= '<div class="gs-sig-gap"></div>';
        $html .= '<div class="gs-sig-label">Date:</div>';
        $html .= '<div class="gs-sig-name" data-field="date_received">' . $e($meta['date_received']) . '</div>';
        $html .= '</td>';

        $html .= '</tr></table>';

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
        
        /* Header: official Capiz State University Grade Sheet form */
        .gs-header { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 2mm; 
            table-layout: fixed; 
            border: 1px solid #000; 
        }
        .gs-header td { 
            border: 1px solid #000; 
            padding: 0; 
            vertical-align: middle; 
        }
        .gs-header-logo { 
            text-align: center; 
            width: 15%; 
            padding: 2mm 1mm; 
        }
        .gs-header-logo img { 
            max-width: 18mm; 
            max-height: 24mm; 
            object-fit: contain; 
            border: none; 
            outline: none; 
            box-shadow: none; 
        }
        .gs-logo-placeholder {
            border: 1px dashed #999; color: #777; font-size: 7pt; line-height: 1.2;
            padding: 3mm 1mm; font-family: Arial, sans-serif;
        }
        
        /* Force 4 equal-height rows on outer table */
        .gs-header tr { height: 25%; }
        
        /* Center top cell (rowspan=2) */
        .gs-center-top { 
            width: 58%; 
            padding: 2mm 3mm; 
            vertical-align: middle;
        }
        .gs-doc-type-label {
            font-size: 8.5pt;
            font-weight: normal;
            margin-bottom: 1mm;
            text-align: left;
        }
        .gs-doc-type-value {
            font-size: 16pt;
            font-weight: bold;
            text-align: center;
            margin: 1mm 0;
            line-height: 1.2;
        }
        .gs-iso {
            font-size: 9pt;
            font-style: italic;
            text-align: center;
            margin-top: 1mm;
        }
        
        /* Center bottom cell (rowspan=2) */
        .gs-center-bottom { 
            width: 58%; 
            padding: 2mm 3mm; 
            vertical-align: middle;
        }
        .gs-doc-title-label {
            font-size: 8.5pt;
            font-weight: normal;
            margin-bottom: 1mm;
            text-align: left;
        }
        .gs-doc-title-value {
            font-size: 16pt;
            font-weight: bold;
            text-align: center;
            line-height: 1.2;
        }
        
        /* Right section document-control cells - direct cells of
           the header table, so the vertical and horizontal grid
           lines run continuously with no nested box. */
        .gs-control-label {
            font-weight: normal;
            text-align: left;
            font-size: 8.5pt;
            padding: 0.8mm 1.5mm;
        }
        .gs-control-value {
            font-weight: bold;
            text-align: center;
            font-size: 8.5pt;
            padding: 0.8mm 1.5mm;
        }

        .gs-course-info { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
        /* The course strip keeps the field layout in place
           but carries no box around it. */
        .gs-course-info td { border: 0; padding: 1mm 1.5mm; font-size: 9pt; }
        /* The values are fill-in fields on the printed form, so they
           carry a rule underneath the bold text, like the Excel form. */
        .gs-course-info td b { text-decoration: underline; }

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
        .gs-table .c-name { width: 39%; text-align: left; }
        .gs-table .c-rate, .gs-table .c-rem { width: 14%; }

        .footer-block { page-break-inside: avoid; break-inside: avoid; }

        /* Footer. Sized in pt to match the rest of the sheet (the px figures in
           the design brief are the same values at 96dpi: 12px = 9pt). */
        .gs-footer {
            margin-top: 5mm;
            width: 100%;
            font-family: "Times New Roman", Times, serif;
            font-size: 9pt;
            line-height: 1.25;
        }
        .gs-certify { margin: 0 0 4mm; font-size: 9pt; }

        .gs-footer-main { width: 100%; border-collapse: collapse; }
        .gs-footer-main td { border: 0; padding: 0; vertical-align: top; }
        /* 22 / 26 / 52. The band is three cells since Submitted by moved above
           it. Sizes are measured, not guessed: at 10.5pt bold the facilitator
           name needed 53.4mm, which is why that block had 33% to itself; the
           remaining 67% splits here so Noted/Received keep their own column and
           the Dean block still has room for a long name. */
        .gs-cell-scale     { width: 22%; padding-right: 4mm; text-align: left; }
        .gs-cell-noted     { width: 26%; padding-right: 4mm; text-align: center; }
        .gs-cell-right     { width: 52%; text-align: center; }

        /* Own line between the certification and the band, centred on the page.
           A block rather than a table cell: Dompdf and mPDF do not implement
           grid, and a table cell here would force the band to start below an
           empty first column. */
        .gs-submitted-row {
            text-align: center; margin: 0 0 4mm; padding: 0;
        }

        .gs-sig-label { font-size: 9pt; margin-bottom: 0.5mm; }
        /* Names are plain bold text - no underline, no rule, no border. */
        .gs-sig-name {
            font-size: 10.5pt; font-weight: bold; line-height: 1.2;
            min-height: 5mm; padding: 0 0 0.5mm 0;
        }
        .gs-sig-gap { height: 7mm; }
        .gs-sig-role { font-size: 8.5pt; margin-top: 0.5mm; }

        .gs-scale-title { font-size: 9pt; font-weight: bold; margin-bottom: 1mm; }
        .gs-scale-list { font-size: 8pt; line-height: 1.3; }
        .gs-scale-row { white-space: nowrap; }

        .gs-note { margin: 3mm 0 0; font-size: 8pt; font-style: italic; }

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
            tr, .footer-block, .gs-header { page-break-inside: avoid; break-inside: avoid; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            
            /* Ensure header borders print cleanly */
            .gs-header, .gs-header td {
                border: 1px solid #000 !important;
            }
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
