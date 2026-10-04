<?php
/**
 * Shared CLASS RECORD template.
 *
 * One builder used by the print view (classrecord_print.php) and the PDF export
 * (classrecord_pdf.php) so the printed sheet and the exported file are the same
 * document.
 *
 * The design is the Excel "Class record" sheet reproduced as HTML: a dense grid of
 * thin black-bordered cells in a small Times face, a wide NAME OF STUDENTS column
 * over white, then one Attendance Sheet block per period, every block on the
 * same bright yellow - each with one narrow column per class date, the date
 * written diagonally the way Excel rotates a column heading, and Attendance, Total
 * Meet and Attendance (%) at the far right: the days a student attended, the
 * meetings held for that class in the printed range, and the share attended.
 * Nothing here is a card: no rounded corners,
 * no shadows, no panels, and the background outside the table is plain white.
 */

if (!function_exists('classrecord_render')) {

/** Printable width in mm for the chosen paper, landscape. */
    function classrecord_page_width_mm($meta) {
        $isLegal = strtolower(trim((string)($meta['cr_paper'] ?? 'legal'))) === 'legal';
        // Landscape: the long edge is the width.
        return $isLegal ? 355.6 : 297.0;
    }

    /** Printable width in CSS pixels, once the page margins are taken off. */
    function classrecord_available_px($meta, $marginMm = 6.0) {
        $mm = classrecord_page_width_mm($meta) - 2 * $marginMm;
        // A CSS pixel is 1/96 inch, so 25.4mm to the inch.
        return max(200.0, ($mm / 25.4) * 96);
    }

    /**
     * Column widths, in pixels, in Excel proportions.
     *
     * Fixed pixel widths rather than percentages: the whole point of the sheet is
     * that a date column stays as narrow as a spreadsheet column no matter how
     * wide the window is, so the table keeps its shape and is scrolled sideways
     * instead of being stretched to fill the page.
     *
     * $scale shrinks every column by the same factor, which is what keeps the date
     * columns equal to each other on a paper they do not fit.
     */
    function classrecord_column_plan($data, $scale = 1.0) {
        $factor = ($scale > 0) ? floatval($scale) : 1.0;
        $px = function ($value) use ($factor) {
            return max(6, (int)round($value * $factor));
        };

        return [
            'no'          => $px(30),    // spreadsheet row number gutter
            'name'        => $px(200),   // wide enough for "Dela Cruz, bongbong biyako l"
            // Every meeting-date column is exactly this wide. It is declared once,
            // in a colgroup, and never overridden per cell: with table-layout fixed
            // the first row otherwise hands the leftover width to the wide spanning
            // headers, which is what made the early date columns wider than the rest.
            'date'        => $px(30),
            // A holiday or seminar column carries a full word instead of a number,
            // so it needs room of its own.
            'event'       => $px(60),
            'summary'     => $px(60),    // Attendance, the student's present days
            'meet'        => $px(60),    // Total Meet, the teacher's scheduled meetings
            'summary_pct' => $px(70),    // Attendance (%)
        ];
    }

    /**
     * The factor that makes the whole sheet fit the paper it is printed on.
     *
     * The sheet grows with the number of meeting dates, so a long term can need
     * more columns than a legal landscape page holds. Without this the right-hand
     * end - Attendance, Total Meet and Attendance (%) - simply runs off the paper
     * and is lost in the exported PDF. Scaling every column by one factor keeps the
     * date columns equal to each other and keeps the three calculation columns on
     * the page, which is the whole point of the sheet.
     *
     * The renderer also adds a few points of padding to every declared cell, so the
     * sheet is asked to fit inside the paper by rather more than the margin.
     */
    function classrecord_fit_scale($data) {
        $meta = $data['meta'] ?? [];
        $plan = classrecord_column_plan($data, 1.0);

        // The real table width, not the sum of one of each kind of column: a term
        // with thirty meetings is thirty date columns wide.
        $dateCount = 0;
        $eventCount = 0;
        foreach (($data['periods'] ?? []) as $period) {
            foreach ($period['columns'] as $column) {
                if (!empty($column['is_break'])) {
                    $eventCount++;
                } else {
                    $dateCount++;
                }
            }
        }
        $unscaled = $plan['no'] + $plan['name']
            + $dateCount * $plan['date'] + $eventCount * $plan['event']
            + $plan['summary'] + $plan['meet'] + $plan['summary_pct'];

        if ($unscaled <= 0) {
            return 1.0;
        }

        // No., the name, the three calculation columns and the dates themselves.
        $columnCount = $dateCount + $eventCount + 5;
        $target = classrecord_available_px($meta) - (12 + 5 * $columnCount);

        $scale = $target / $unscaled;
        return max(0.35, min(1.0, round($scale, 4)));
    }

    /**
     * The columns of the sheet, in order, as one flat list.
     *
     * Both header rows and every student row are built by walking this same list,
     * so a cell can never drift out of line with its date. The order is exactly:
     * No., NAME OF STUDENTS, one column per scheduled meeting date, then the three
     * calculation columns Attendance, Total Meet and Attendance (%).
     *
     * @return array List of ['key' => ..., 'width' => ..., 'class' => ..., ...].
     */
    function classrecord_columns($data, $plan) {
        $columns = [
            ['key' => 'no',   'width' => $plan['no'],   'class' => 'c-no',   'lines' => ['No.']],
            ['key' => 'name', 'width' => $plan['name'], 'class' => 'c-name',
             'lines' => ['NAME OF STUDENTS', '(Last, First, MI)']],
        ];

        // One column per scheduled meeting. Period blocks are concatenated so the
        // dates read as one continuous run, as on the printed form. A period with no
        // dates left after filtering contributes nothing, which is what stops an
        // empty block from leaving a blank column behind.
        $dateIndex = 0;
        foreach ($data['periods'] as $period) {
            foreach ($period['columns'] as $column) {
                $isBreak = (bool)$column['is_break'];
                $columns[] = [
                    'key'          => 'date',
                    // A holiday or seminar column is wider than a date column because
                    // it carries a vertical word rather than a number.
                    'width'        => $isBreak ? $plan['event'] : $plan['date'],
                    'class'        => $isBreak ? 'c-event' : 'c-date',
                    // The date itself, e.g. "Sep 2". An event column labels itself
                    // with its kind instead, so the short word always fits the header.
                    'label'        => $isBreak ? '' : $column['month'] . ' ' . $column['day'],
                    // The header names the kind of event ("HOLIDAY", "SEMINAR") so the
                    // short word always fits the 85px header row; the merged body cell
                    // below carries the full name, e.g. "Holiday (All Saints' Day)".
                    'lines'        => [$isBreak ? strtoupper($column['break_type'] ?? 'Event') : ''],
                    'date_index'   => $dateIndex++,
                    'is_break'     => $isBreak,
                    'break_caption' => ($column['break_caption'] !== ''
                                        ? $column['break_caption'] : $column['break_label']),
                ];
            }
        }

        // The three calculation columns come after the LAST meeting date. They are
        // not meeting dates and never carry a mark. 'lines' is rendered as real
        // markup with a <br> between words, so a header never prints "<br>" as text.
        $columns[] = ['key' => 'att', 'field' => 'attended', 'is_pct' => false,
                      'width' => $plan['summary'],     'class' => 'c-sum',
                      'lines' => ['Attendance']];
        $columns[] = ['key' => 'meet','field' => 'meet',    'is_pct' => false,
                      'width' => $plan['meet'],        'class' => 'c-sum',
                      'lines' => ['Total', 'Meet']];
        $columns[] = ['key' => 'pct', 'field' => 'percent', 'is_pct' => true,
                      'width' => $plan['summary_pct'], 'class' => 'c-sum',
                      'lines' => ['Attendance', '(%)']];

        return $columns;
    }

    /**
 * One explicit width per column, plus the table's own width.
 *
 * In a table-layout:fixed grid the widths on the first row decide the columns, and
 * a header that spans twenty of them competes with one that spans seventeen.
 * Declaring the whole grid here instead makes every column exactly the width it
 * asks for, so the first date column is no wider than the last.
 *
 * The table width is pinned to the sum of those columns on purpose. Left to stretch
 * to fill its container, a fixed grid hands the surplus width back to the columns
 * and they stop being equal; the sheet is meant to be scrolled sideways at its own
 * size, the way a spreadsheet is.
 */
    function classrecord_colgroup_html($columns) {
        $html = '<colgroup>';
        foreach ($columns as $column) {
            $html .= '<col style="width:' . intval($column['width']) . 'px">';
        }
        return $html . '</colgroup>';
    }

    /** Centred CLASS RECORD title over the three-block identification row. */
    function classrecord_header_html($meta) {
        $e = 'classrecord_e';
        $html  = '<div class="cr-ident">';
        $html .= '<div class="cr-ident-title">CLASS RECORD</div>';
        $html .= '<table class="cr-ident-table"><colgroup>'
               . '<col style="width:32%"><col style="width:34%"><col style="width:34%">'
               . '</colgroup><tr>';
        $html .= '<td>Course Number: ' . $e($meta['course_number']) . '<br>'
               . 'Course Title: ' . $e($meta['course_title']) . '</td>';
        $html .= '<td class="cr-ident-mid">' . $e($meta['semester_term']) . '<br>'
               . 'Course &amp; Year: ' . $e($meta['course_and_year']) . '</td>';
        // The months the sheet was printed for, so the paper says which period and
        // term these columns belong to.
        $html .= '<td class="cr-ident-right">Period &amp; Term: '
               . $e($meta['term_range_label']) . '</td>';
        $html .= '</tr></table>';
        $html .= '</div>';
        return $html;
    }

    /**
     * The two header rows.
     *
     * Row 1: No. and the name are merged down both rows, a single Attendance Sheet
     * spans every meeting-date column, and Attendance, Total Meet and Attendance (%)
     * are merged down as the three calculation columns at the far right.
     * Row 2 carries only the dates, written diagonally.
     */
    function classrecord_table_head($columns, $plan) {
        $e = 'classrecord_e';

        $dateCount = 0;
        $dateWidth = 0;
        $headings = [];
        foreach ($columns as $column) {
            if ($column['key'] === 'date') {
                $dateCount++;
                $dateWidth += $column['width'];
            } elseif ($column['key'] !== 'no' && $column['key'] !== 'name') {
                $headings[] = $column;
            }
        }

        $html  = '<thead>';
        $html .= '<tr class="cr-head-section">';

        // No. and the name span both header rows. Their lines are escaped one by
        // one and joined with real markup, so no header can ever print "<br>" as
        // visible text.
        $html .= '<th class="c-no" rowspan="2" style="width:' . $columns[0]['width'] . 'px">No.</th>';
        $html .= '<th class="c-name" rowspan="2" style="width:' . $columns[1]['width'] . 'px">'
               . $e($columns[1]['lines'][0])
               . '<br><span class="c-name-sub">' . $e($columns[1]['lines'][1]) . '</span></th>';

        // One Attendance Sheet over the meeting dates. With no meeting dates the heading
        // is left out entirely rather than forced over a single column, which would
        // put one more column in the header than the colgroup declares.
        if ($dateCount > 0) {
            $html .= '<th class="c-section cr-sec-yellow" colspan="' . $dateCount . '"'
                   . ' style="width:' . $dateWidth . 'px">Attendance Sheet</th>';
        }

        foreach ($headings as $column) {
            $html .= '<th class="' . $column['class'] . ' cr-sec-yellow" rowspan="2"'
                   . ' style="width:' . $column['width'] . 'px">'
                   . implode('<br>', array_map($e, $column['lines']))
                   . '</th>';
        }
        $html .= '</tr>';

        // Row 2: one cell per meeting-date column. Every label is carried by a
        // <span> turned inside its own cell, so the column itself keeps its exact
        // width and the text simply reads downwards. Event columns label themselves
        // with the kind of event on the same axis.
        $html .= '<tr class="cr-head-dates">';
        foreach ($columns as $column) {
            if ($column['key'] !== 'date') {
                continue;
            }
            $label = $column['is_break'] ? $column['lines'][0] : $column['label'];
            $html .= '<th class="c-date ' . ($column['is_break'] ? 'c-event' : '')
                   . ' cr-sec-yellow" style="width:' . $column['width'] . 'px">'
                   . '<span>' . $e($label) . '</span></th>';
        }
        $html .= '</tr></thead>';
        return $html;
    }

    /**
     * The student rows.
     *
     * Every row walks the same column list as the header, so a mark always sits
     * under its own date. A holiday or event column is one cell merged down the
     * whole column carrying the event name: it shows no mark, is never counted as a
     * meeting, and therefore never raises Total Meet or Attendance (%).
     */
    function classrecord_table_body($data, $columns) {
        $e = 'classrecord_e';
        $rows = $data['rows'] ?? [];
        $html = '<tbody>';

        // Each merged event cell spans the full height of the student list.
        $span = count($rows);

        foreach ($rows as $index => $row) {
            $html .= '<tr>';

            foreach ($columns as $column) {
                $w = ' style="width:' . $column['width'] . 'px"';
                switch ($column['key']) {
                    case 'no':
                        $html .= '<td class="c-no"' . $w . '>' . ($index + 1) . '</td>';
                        break;

                    case 'name':
                        $html .= '<td class="c-name"' . $w . '>' . $e($row['name']) . '</td>';
                        break;

                    case 'att':
                    case 'meet':
                    case 'pct':
                        // One column per figure, read by name so the header, the
                        // cell and the calculation cannot drift apart.
                        $overall = $row['overall'] ?? ['attended' => 0, 'meet' => 0, 'percent' => 0];
                        $value = $overall[$column['field']] ?? 0;
                        $html .= '<td class="c-sum cr-sec-yellow"' . $w . '>'
                               . $e(!empty($column['is_pct']) ? $value . '%' : intval($value))
                               . '</td>';
                        break;

                    default:
                        // A meeting-date column: the mark under that date, or the
                        // merged event banner on the first row.
                        $mark = $row['marks'][$column['date_index']] ?? '';
                        if ($column['is_break']) {
                            if ($index === 0 && $span > 0) {
                                // A normal table cell spanning the whole column. It is
                                // not taken out of the flow, so it cannot cover the
                                // student number, name or any date cell.
                                $html .= '<td class="c-break c-event-body cr-sec-yellow" rowspan="' . $span . '"'
                                       . $w . '><span>' . $e($column['break_caption']) . '</span></td>';
                            }
                            break;
                        }
                        $html .= '<td class="c-mark cr-sec-yellow"' . $w . '>' . $e($mark) . '</td>';
                }
            }

            $html .= '</tr>';
        }

        $html .= '</tbody>';
        return $html;
    }

/**
     * The certification note and the signature blocks.
     *
     * Printed once, directly under the last student row, in the same plain Times
     * face as the grid and never split across a page break.
     *
     * One table for the whole footer, on four equal columns, is what makes it line
     * up: every label sits on the same baseline, every typed name on the next, and
     * every role on the one after that. Three separate tables with three different
     * column splits could not do that - each band would start its own grid and the
     * blocks would sit at unrelated positions.
     *
     * A signature here is plain text and nothing else: the label, then the typed
     * name, then the role. There are no fill-in rules anywhere in the footer. A rule
     * under a name that has already been typed only runs through the text, so the
     * footer carries no lines at all - not an underscore, not a border, and no
     * underline on the name.
     */
    function classrecord_footer_html($meta) {
        $e = 'classrecord_e';

        $html  = '<div class="cr-footer"><table class="cr-foot-grid"><colgroup>'
               . '<col style="width:25%"><col style="width:25%"><col style="width:25%">'
               . '<col style="width:25%"></colgroup>';

        // The note spans the whole grid so the text is not broken into a column.
        $html .= '<tr><td class="cr-foot-note" colspan="4">' . $e($meta['class_record_note']) . '</td></tr>';

        // A blank row, not a border, separates the two bands of signatures.
        $html .= '<tr class="cr-foot-gap"><td colspan="4"></td></tr>';

        $html .= '<tr>';
        $html .= '<td>' . classrecord_signature_html(
                   'Prepared by:', $meta['facilitator_name_upper'], 'Course Facilitator') . '</td>';
        $html .= '<td>' . classrecord_signature_html(
                   'Noted:', $meta['program_chair_upper'], 'Program Chair') . '</td>';
        $html .= '<td>' . classrecord_signature_html(
                   'Approved:', $meta['satellite_director_upper'], 'Satellite College Director') . '</td>';
        $html .= '<td>' . classrecord_signature_html(
                   'Submitted by:',
                   $meta['submitted_by_upper'] ?? strtoupper($meta['submitted_by']),
                   'Professor'
               ) . '</td>';
        $html .= '</tr>';

        $html .= '<tr class="cr-foot-gap"><td colspan="4"></td></tr>';

        $html .= '<tr>';
        $html .= '<td>' . classrecord_signature_html(
                   'Received:', $meta['registrar_upper'], 'Registrar') . '</td>';
        // Prog. Coordinator and Dean share one "Noted" line, so the label carries
        // both names and the role goes underneath. With neither name set the line
        // is just "Noted:" rather than a dangling separator.
        $progDean = trim($meta['prog_coordinator_upper'] ?? '') . ' / ' . trim($meta['dean_upper'] ?? '');
        $html .= '<td>' . classrecord_signature_html(
                   'Noted: ' . $e(trim($progDean, " /")),
                   '', 'Prog. Coordinator / Dean') . '</td>';
        // Date carries no name, so the label is the whole block.
        $html .= '<td>' . classrecord_signature_html('Date:', '', '') . '</td>';
        $html .= '<td>' . classrecord_signature_html('Date:', '', '') . '</td>';
        $html .= '</tr>';

        $html .= '</table></div>';
        return $html;
    }

    /**
     * One signature block: the label, then the typed name, then the role.
     *
     * Nothing is drawn around the name. Each part is its own block so the three
     * print on separate lines, and the name keeps a fixed height so an unset name
     * still holds its line and the roles below stay on one baseline.
     */
    function classrecord_signature_html($label, $name, $role) {
        $e = 'classrecord_e';
        return '<span class="cr-foot-label">' . $e($label) . '</span>'
            . '<span class="cr-foot-sigblock">'
            . '<span class="cr-foot-name">' . $e($name) . '</span>'
            . '<span class="cr-foot-role">' . $e($role) . '</span>'
            . '</span>';
    }

    /**
     * Renders the whole sheet as one continuous table.
     *
     * One table rather than one table per printed page: the thead repeats itself
     * when the sheet is printed, and on screen the grid is simply scrolled
     * sideways and down the way a spreadsheet is.
     */
function classrecord_render($data, $scale = 1.0) {
        $plan = classrecord_column_plan($data, $scale);
        $columns = classrecord_columns($data, $plan);

        $html  = '<div class="cr-sheet">';
        $html .= classrecord_header_html($data['meta']);
        $html .= '<div class="cr-scroll">';
        $html .= '<table class="cr-excel">';
        $html .= classrecord_colgroup_html($columns);
        $html .= classrecord_table_head($columns, $plan);
        $html .= classrecord_table_body($data, $columns);
        $html .= '</table>';
        $html .= '</div>';
        $html .= classrecord_footer_html($data['meta']);
        $html .= '</div>';

        return $html;
    }

    /**
     * Excel-like stylesheet.
     *
     * Deliberately plain: a white page, a dense grid of thin black borders, a
     * small Times face, centred numbers and fixed row heights. There are no rounded
     * corners, no shadows and no cards anywhere in this file.
     */
function classrecord_css($meta = [], $scale = 1.0) {
        $isLegal = strtolower(trim((string)($meta['cr_paper'] ?? 'legal'))) === 'legal';
        $dataPage = ($isLegal ? 'legal' : 'A4') . ' landscape';
        $pageW = $isLegal ? '355.6mm' : '297mm';
        $pageH = $isLegal ? '215.9mm' : '210mm';

        // Type and row height follow the column scale. A date label that is not
        // scaled down with its 30px column would stop fitting inside it.
        $s = ($scale > 0) ? floatval($scale) : 1.0;
        $dateFont = max(7, (int)round(10 * $s));
        $eventFont = max(8, (int)round(14 * $s));
        $eventBodyFont = max(8, (int)round(15 * $s));
        $headHeight = max(45, (int)round(100 * $s));
        $dateSpanWidth = max(12, (int)round(30 * $s));
        $eventSpanWidth = max(16, (int)round(60 * $s));
        $font = max(7, (int)round(11 * $s));

        return '
        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            font-family: "Times New Roman", Times, serif;
        }

        .cr-toolbar {
            display: flex; gap: 6px; align-items: center;
            padding: 6px 8px; margin: 0;
            background: #f0f0f0; border: 0; border-bottom: 1px solid #999;
            font-family: "Times New Roman", Times, serif; font-size: 12px;
        }
        .cr-toolbar button, .cr-toolbar a {
            font-family: "Times New Roman", Times, serif; font-size: 12px;
            padding: 2px 8px; cursor: pointer; text-decoration: none;
            border: 1px solid #666; border-radius: 0; background: #fff; color: #000;
        }

        .cr-sheet {
            background: #fff;
            padding: 8px;
            font-family: "Times New Roman", Times, serif;
            font-size: 11px;
            line-height: 1.05;
        }

        /* --- identification block ------------------------------------- */
        .cr-ident { margin-bottom: 4px; }
        .cr-ident-title {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 0.05em;
            margin-bottom: 3px;
        }
        .cr-ident-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .cr-ident-table td { border: 0; padding: 0 4px 0 0; vertical-align: top; }
        .cr-ident-mid { text-align: center; }
        .cr-ident-right { text-align: right; }

        /* --- the grid -------------------------------------------------- */
        /* max-content, not 100%: the sheet keeps its spreadsheet width and the
           wrapper scrolls, instead of the columns stretching to the window. */
        .cr-scroll {
            overflow: auto;
            max-height: 78vh;
            border: 1px solid #000;
            background: #fff;
        }
        .cr-excel {
            border-collapse: collapse;
            table-layout: fixed;
            /* width is set inline from the colgroup so the grid never stretches. */
            background: #fff;
            font-family: "Times New Roman", Times, serif;
            font-size: 11px;
        }
        .cr-excel th, .cr-excel td {
            border: 1px solid #000;
            padding: 0 2px;
            text-align: center;
            vertical-align: middle;
            font-weight: normal;
            background: #fff;          /* the name/grade block stays white */
            /* Fixed row height keeps the grid as dense as a spreadsheet. */
            height: 17px;
            line-height: 1;
            white-space: nowrap;
            overflow: hidden;
        }
        .cr-excel thead th { background: #fff; font-weight: bold; }

        /* Sticky header rows. Row 1 is the section row and has a fixed height,
           so row 2 can sit exactly under it while the grid scrolls down. */
        .cr-excel thead th { position: sticky; z-index: 3; }
        .cr-excel .cr-head-section th { top: 0; height: 19px; }
        .cr-excel .cr-head-dates th { top: 19px; }

        /* Sticky student-name column. No. sticks at 0 and the name at the width
           of the number gutter, so a wide sheet still shows whose row it is. The
           name column is opaque and sits above the date cells, so the attendance
           grid scrolls underneath it and never over the names. */
        .cr-excel .c-no { position: sticky; left: 0; z-index: 2; background: #fff; }
        .cr-excel .c-name {
            position: sticky;
            left: 30px;               /* must match the No. column width */
            z-index: 3;
            text-align: left;
            padding-left: 4px;
            background: #fff;         /* opaque: date cells pass behind this */
        }
        .cr-excel thead .c-no, .cr-excel thead .c-name { z-index: 5; }

        .cr-excel .c-name-sub { font-weight: normal; font-size: 10px; }
        .cr-excel .c-mark { font-size: 11px; }
        .cr-excel .c-sum { font-size: 11px; }

        /* Large merged section headings. */
        .cr-excel .c-section { font-size: 12px; font-weight: bold; letter-spacing: 0.04em; }

        /* --- vertical headings ------------------------------------------ */
        /* The label is turned inside its own cell, and that is all.
           Two earlier attempts caused the overlap this replaces: absolutely
           positioning the label let it spill past its column, and because the PDF
           renderer ignores transform-origin the turn then happened about the
           wrong point and landed on the neighbouring date. So the label is a plain
           inline-block in the normal flow of the cell, the cell centres it, the
           turn is about the centre of the label, and the cell clips whatever is
           left. The cell keeps display:table-cell throughout - no flexbox on a
           th or td, which is what makes exported table layouts drift. */
.cr-excel .c-date {
            height: ' . $headHeight . 'px;
            padding: 0;
            /* A date may not reach its neighbours, so the cell clips. */
            overflow: hidden;
            vertical-align: middle;
            text-align: center;
        }
        .cr-excel .c-date > span {
            display: inline-block;
            white-space: nowrap;
            /* The span reserves exactly one column. This matters for the PDF: the
               renderer lays a turned element out at its unturned size, so without
               this the "Sep 2" label would make its column as wide as the text
               and the columns would stop lining up. */
            width: ' . $dateSpanWidth . 'px;
            line-height: 1;
            font-size: ' . $dateFont . 'px;
            font-weight: bold;
            text-align: center;
            transform: rotate(-90deg);
            transform-origin: center center;
        }

        /* --- section colour -------------------------------------------- */
        /* Every Attendance Sheet block shares one yellow fill; the date cells use
           a lighter tint of it so the grid stays readable. */
        .cr-excel .cr-sec-yellow { background: #FFFF00; }
        .cr-excel .c-mark.cr-sec-yellow { background: #FFFBE0; }

        /* --- holiday and seminar columns -------------------------------- */
        /* A wider column and a larger word than a meeting date carries, turned the
           opposite way so it reads downwards instead of upwards, and centred in the
           cell on both axes. The yellow belongs to the cell itself, never to a
           separate painted element, and the cell is an ordinary table cell. */
        .cr-excel .c-event,
        .cr-excel .c-event-body {
            padding: 0;
            overflow: hidden;
            vertical-align: middle;
            text-align: center;
            background: #FFFF00;
            border: 1px solid #000;
        }
        .cr-excel .c-event {
            height: ' . $headHeight . 'px;   /* matches the date row exactly */
        }
        .cr-excel .c-event > span,
        .cr-excel .c-event-body > span {
            display: inline-block;
            white-space: nowrap;
            /* One column wide, for the same reason as the date label above: the
               renderer reserves the unturned width, and an event name is a long
               word. */
            width: ' . $eventSpanWidth . 'px;
            line-height: 1;
            font-weight: bold;
            text-align: center;
            /* 90deg, not -90deg: the opposite vertical direction. */
            transform: rotate(90deg);
            transform-origin: center center;
        }
        .cr-excel .c-event > span { font-size: ' . $eventFont . 'px; }
        .cr-excel .c-event-body > span { font-size: ' . $eventBodyFont . 'px; }

        /* --- footer ---------------------------------------------------- */
.cr-footer {
            margin-top: 4px;
            font-family: "Times New Roman", Times, serif;
            font-size: 10px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
.cr-footer table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .cr-footer td { border: 0; padding: 0 10px 0 0; vertical-align: top; }
        /* The note runs the full width of the grid, above the signatures. */
        .cr-foot-note { font-size: 9px; line-height: 1.25; padding-right: 0 !important; }
        /* Blank rows, not borders, between the note and the two signature bands. Kept
           short so the whole footer stays inside the page margin at the bottom. */
        .cr-foot-gap td { height: 6px; padding: 0; }
        /* A signature is three lines of text and nothing is drawn: the label, the
           typed name, the role. No border, no fill-in rule and no underline on the
           name, so nothing runs through the text. The fixed height on the name is
           what holds its line open when no name has been typed, so every role in a
           band still sits on the same baseline. */
        .cr-foot-label {
            display: block;
            white-space: nowrap;
        }
        .cr-foot-sigblock {
            display: block;
            margin-top: 2px;
            line-height: 1.2;
        }
        .cr-foot-name {
            display: block;
            height: 12px;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
        }
        .cr-foot-role { display: block; font-size: 9px; }
        ';
    }

    /** Print rules: landscape, no scroll clipping, header rows repeat. */
    function classrecord_print_css($meta = []) {
        $isLegal = strtolower(trim((string)($meta['cr_paper'] ?? 'legal'))) === 'legal';
        $dataPage = ($isLegal ? 'legal' : 'A4') . ' landscape';

        return '
        @page { size: ' . $dataPage . '; margin: 6mm; }
        @media print {
            /* The on-screen sheet is scrolled in a box; on paper there is no box,
               so the wrapper must stop clipping or the right-hand columns are lost. */
            .cr-scroll {
                overflow: visible;
                max-height: none;
                height: auto;
                border: 1px solid #000;
            }
            .cr-excel { min-width: 0; }
            /* Sticky offsets are meaningless once the page breaks. */
            .cr-excel thead th,
            .cr-excel .c-no,
            .cr-excel .c-name { position: static; }
            thead { display: table-header-group; }
            tr, .cr-footer { page-break-inside: avoid; break-inside: avoid; }
            .no-print { display: none !important; }
        }
        ';
    }

/** Wraps the sheet in a standalone document for print and PDF. */
    function classrecord_document($data, $title = 'Class Record', $toolbar = true, $scale = null) {
        $meta = $data['meta'] ?? [];
        // Scaling is worked out from the paper so a long term cannot push the
        // right-hand columns off the page.
        if ($scale === null) {
            $scale = classrecord_fit_scale($data);
        }
        $classId = intval($data['class']['id'] ?? 0);

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<title>' . classrecord_e($title) . '</title>'
            . '<style>' . classrecord_css($meta, $scale) . classrecord_print_css($meta) . '</style>'
            . '</head><body>';

        if ($toolbar) {
            $html .= '<div class="no-print cr-toolbar">'
                   . '<button type="button" onclick="window.print()">Print</button>'
                   . '<a href="classrecord_xlsx.php?id=' . $classId . '">Download Excel</a>'
                   . '<a href="classrecord_pdf.php?id=' . $classId . '">Export to PDF</a>'
                   . '<a href="index.php?page=attendance&amp;id=' . $classId . '">Back to Attendance</a>'
                   . '</div>';
        }

        $html .= classrecord_render($data, $scale) . '</body></html>';
        return $html;
    }
}