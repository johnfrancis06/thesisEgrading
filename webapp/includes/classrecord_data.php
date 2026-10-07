<?php
/**
 * Shared data loader for the CLASS RECORD (print attendance) report.
 *
 * Used by the print view (classrecord_print.php) and the PDF export
 * (classrecord_pdf.php) so both render exactly the same sheet. The sheet is
 * attendance only; the footer names come from report_settings, never from
 * hard-coded strings, so the Grade Sheet footer and this one always show the
 * same people.
 */

/** Escape helper shared with the template. */
if (!function_exists('classrecord_e')) {
    function classrecord_e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Default header/footer metadata for the class record.
 *
 * The signature names intentionally share their report_settings keys with the
 * Grade Sheet (facilitator_name, program_chair, dean, registrar) so a name typed
 * once appears on both reports. Only the keys that exist purely for this sheet
 * are prefixed cr_.
 */
function classrecord_defaults($class, $facultyName = '') {
    $semesterNo = ((int)($class['semester'] ?? 1) === 2) ? '2nd' : '1st';
    $program = trim(($class['course_program'] ?? '') . ' '
        . ($class['year_level'] ?? '') . '-' . ($class['section'] ?? ''));
    $academicYear = $class['academic_year'] ?? '';

    return [
        // Header
        'course_number'    => $class['code'] ?? '',
        'course_title'     => $class['title'] ?? '',
        'semester_term'    => $semesterNo . ' Semester ' . ($academicYear ? $academicYear : ''),
        'course_and_year'  => $program,
        // Footer
'class_record_note' => 'Note: This is a class record form. The faculty is given freedom to '
                              . 'select the kind of class record whether E-grading or manual computation '
                              . 'to include the Criteria in giving grades.',
        'facilitator_name' => $facultyName,
        'program_chair'    => '',
        'satellite_director' => '',
        // Layout
        'cr_paper'         => 'legal',               // legal | a4
        'cr_term_year'     => 0,                     // 0 = the latest year that has sessions
        'cr_period_split'  => '',                    // first date of the 2nd period
        'cr_from_month'    => '',                    // YYYY-MM, first month printed
        'cr_to_month'      => '',                    // YYYY-MM, last month printed
        'cr_rows_per_page' => 0,                     // unused; kept so old saves still load
        'cr_rotate_dates'  => '1',                   // 1 = stack the date over two lines
    ];
}

/** Loads every saved override for a class as a flat key => value map. */
function classrecord_load_settings($db, $classId) {
    if (!function_exists('gradesheet_load_settings')) {
        $stmt = $db->prepare("SELECT field_key, field_value FROM report_settings
                              WHERE class_section_id = ?");
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $out[$row['field_key']] = $row['field_value'];
        }
        return $out;
    }
    return gradesheet_load_settings($db, $classId);
}

function classrecord_save_setting($db, $classId, $key, $value, $updatedBy = 0) {
    // bind_param takes its arguments by reference, so every value needs its own
    // variable rather than an inline expression.
    $classId = intval($classId);
    $key = (string)$key;
    $value = (string)$value;
    $updatedBy = intval($updatedBy);

    $stmt = $db->prepare("INSERT INTO report_settings
                          (class_section_id, field_key, field_value, updated_by)
                          VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE
                              field_value = VALUES(field_value),
                              updated_by = VALUES(updated_by),
                              updated_at = CURRENT_TIMESTAMP");
    // class_section_id, field_key, field_value, updated_by = int, string, string, int.
    // The order of that type string matters: binding field_value as an int
    // silently stores 0 for every name.
    $stmt->bind_param('issi', $classId, $key, $value, $updatedBy);
    $stmt->execute();
}

/**
 * The year of sessions the record covers.
 *
 * A class keeps every session it has ever had, so a class that has been taught
 * for years would otherwise print all of them in one sheet. A class record is a
 * per-term document, so it is scoped to a single calendar year, by default the
 * latest one that has sessions. Zero means every year.
 */
function classrecord_resolve_term_year($db, $classId, $savedYear) {
    // An empty value means "never chosen", which is different from an explicit 0.
    // Zero means every year, so it must survive instead of being re-detected.
    $raw = trim((string)$savedYear);
    if ($raw !== '' && is_numeric($raw)) {
        return max(0, intval($raw));
    }

    $row = $db->query("SELECT MAX(date) AS last_date FROM attendance_session
                       WHERE class_section_id = " . intval($classId))->fetch_assoc();
    $year = (!empty($row['last_date'])) ? (int)date('Y', strtotime($row['last_date'])) : (int)date('Y');

    classrecord_save_setting($db, $classId, 'cr_term_year', $year);
    return $year;
}

/** SQL fragment limiting sessions to one term year. Year 0 keeps every year. */
function classrecord_year_filter_sql($year) {
    $year = intval($year);
    return $year > 0 ? (' AND YEAR(date) = ' . $year) : '';
}

/**
 * Normalises a period/term month setting to YYYY-MM.
 *
 * Anything that is not a real month comes back as an empty string, which the rest
 * of the loader reads as "not chosen". Values are validated here, before they are
 * ever concatenated into SQL.
 */
function classrecord_normalize_month($value) {
    $raw = trim((string)$value);
    if ($raw === '' || !preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $parts)) {
        return '';
    }
    $year = intval($parts[1]);
    $month = intval($parts[2]);
    if ($year < 1990 || $year > 2100 || $month < 1 || $month > 12) {
        return '';
    }
    return sprintf('%04d-%02d', $year, $month);
}

/**
 * The period and term the sheet is printed for, as a month range.
 *
 * A class keeps every session it has ever had, so the printed sheet is scoped to
 * the months chosen here: only attendance records dated inside the range get a
 * column. Either end may be left blank, which means "no lower bound" or "no
 * upper bound". When nothing has been chosen the range is taken from the term's
 * own first and last session and written back, so the dialog always opens on a
 * concrete range instead of a blank pair of boxes.
 *
 * @return array{0:string,1:string} The normalised from and to months.
 */
function classrecord_resolve_month_range($db, $classId, $savedFrom, $savedTo, $termYear) {
    $from = classrecord_normalize_month($savedFrom);
    $to = classrecord_normalize_month($savedTo);

    if ($from === '' && $to === '') {
        $row = $db->query("SELECT MIN(date) AS first_date, MAX(date) AS last_date
                           FROM attendance_session
                           WHERE class_section_id = " . intval($classId)
                           . classrecord_year_filter_sql($termYear))->fetch_assoc();

        $from = !empty($row['first_date']) ? date('Y-m', strtotime($row['first_date'])) : '';
        $to = !empty($row['last_date']) ? date('Y-m', strtotime($row['last_date'])) : '';

        if ($from !== '' || $to !== '') {
            classrecord_save_setting($db, $classId, 'cr_from_month', $from);
            classrecord_save_setting($db, $classId, 'cr_to_month', $to);
        }
    }

    // A range typed the wrong way round still has to mean something, so the ends
    // are swapped rather than printing an empty sheet.
    if ($from !== '' && $to !== '' && $from > $to) {
        $swap = $from;
        $from = $to;
        $to = $swap;
    }

    return [$from, $to];
}

/**
 * SQL fragment limiting sessions to the chosen months.
 *
 * Both ends are widened to whole months, so "2026-08" keeps every session from
 * 1 to 31 August regardless of which day the class meets. The arguments must
 * already have been through classrecord_normalize_month().
 */
function classrecord_month_filter_sql($fromMonth, $toMonth) {
    $sql = '';
    if ($fromMonth !== '') {
        $sql .= " AND date >= '" . $fromMonth . "-01'";
    }
    if ($toMonth !== '') {
        $sql .= " AND date <= '" . date('Y-m-t', strtotime($toMonth . '-01')) . "'";
    }
    return $sql;
}

/**
 * The weekdays this class meets, as ISO day numbers (1 = Monday .. 7 = Sunday).
 *
 * Same source as the attendance screen: class_section.attendance_weekdays, which
 * the teacher sets when scheduling the class. A class with nothing saved falls back
 * to Monday to Friday, which is the same default the rest of the app assumes.
 */
function classrecord_scheduled_weekdays($db, $classId) {
    $check = $db->query("SHOW COLUMNS FROM class_section LIKE 'attendance_weekdays'");
    if ($check && $check->num_rows > 0) {
        $row = $db->query("SELECT attendance_weekdays FROM class_section
                           WHERE id = " . intval($classId))->fetch_assoc();
        if ($row && trim((string)$row['attendance_weekdays']) !== '') {
            $days = [];
            foreach (explode(',', $row['attendance_weekdays']) as $day) {
                $day = intval(trim($day));
                if ($day >= 1 && $day <= 7) {
                    $days[$day] = $day;
                }
            }
            // A setting that names no real day is treated as unset, not as a class
            // that never meets, which would blank the whole sheet.
            if ($days) {
                return array_values($days);
            }
        }
    }
    return [1, 2, 3, 4, 5];
}

/**
 * SQL fragment keeping only the sessions that fall on a scheduled meeting day.
 *
 * WEEKDAY() counts Monday as 0, so the stored 1-based day numbers are shifted by
 * one here. Dates outside the schedule are not printed at all: if a class meets
 * only on Monday and Friday, Tuesday gets no column.
 */
function classrecord_weekday_filter_sql($weekdays) {
    if (empty($weekdays)) {
        return '';
    }
    $numbers = [];
    foreach ($weekdays as $day) {
        $day = intval($day);
        if ($day >= 1 && $day <= 7) {
            $numbers[] = $day - 1;
        }
    }
    if (!$numbers) {
        return '';
    }
    return ' AND WEEKDAY(date) IN (' . implode(',', array_unique($numbers)) . ')';
}

/** "2026-08" as "Aug 2026", for the printed header. */
function classrecord_month_label($month) {
    return $month === '' ? '' : date('M Y', strtotime($month . '-01'));
}

/** The period and term as one printed phrase, e.g. "Aug 2026 - Dec 2026". */
function classrecord_term_range_label($fromMonth, $toMonth) {
    $from = classrecord_month_label($fromMonth);
    $to = classrecord_month_label($toMonth);
    if ($from === '') {
        return $to;
    }
    if ($to === '' || $from === $to) {
        return $from;
    }
    return $from . ' - ' . $to;
}

/**
 * First date of the 2nd period.
 *
 * A class record always shows two attendance blocks, so every session needs a
 * period. The split is a single date the teacher controls: sessions on or after
 * it are period 2. When nothing is saved yet the midpoint of the term's own
 * dates is used and written back, so the split is stable across prints.
 *
 * @param string $scope Extra SQL restricting which sessions count, built from
 *                      the term year, the chosen months and the meeting days.
 */
function classrecord_resolve_split($db, $classId, $savedSplit, $scope = '') {
    $savedSplit = trim((string)$savedSplit);
    // A stored value that is not a real date cannot divide the term, so it is
    // ignored rather than silently collapsing every column into the 2nd period.
    if ($savedSplit !== '' && strtotime($savedSplit) && date('Y', strtotime($savedSplit)) > 1990) {
        return date('Y-m-d', strtotime($savedSplit));
    }
    $row = $db->query("SELECT MIN(date) AS first_date, MAX(date) AS last_date
                       FROM attendance_session
                       WHERE class_section_id = " . intval($classId) . $scope)->fetch_assoc();
    $first = $row['first_date'] ?? null;
    $last = $row['last_date'] ?? null;

    if (!$first || !$last || $first === $last) {
        $split = $first ?: date('Y-m-d');
    } else {
        // Midpoint of the term, rounded to a real session date so no column is
        // orphaned in a period that has no dates at all.
        $mid = strtotime($first) + intdiv(strtotime($last) - strtotime($first), 2);
        $candidate = date('Y-m-d', $mid);
        $nearest = $db->query("SELECT date FROM attendance_session
                               WHERE class_section_id = " . intval($classId) . $scope
                               . " AND date >= '" . $db->real_escape_string($candidate) . "'
                               ORDER BY date ASC LIMIT 1")->fetch_assoc();
        if (!$nearest) {
            $nearest = $db->query("SELECT date FROM attendance_session
                                   WHERE class_section_id = " . intval($classId) . $scope
                                   . " ORDER BY date DESC LIMIT 1")->fetch_assoc();
        }
        $split = $nearest['date'] ?? $candidate;
    }

    classrecord_save_setting($db, $classId, 'cr_period_split', $split);
    return $split;
}

/**
 * Writes the period flag on the term's sessions from the split date.
 *
 * Only sessions inside the term are touched. A class record prints one term, so
 * relabelling the sessions of previous years would be wrong as well as
 * unnecessary. The scope narrows this further: a date outside the chosen months
 * or outside the class's meeting days is not printed, so it does not need a
 * period either.
 */
function classrecord_assign_periods($db, $classId, $splitDate, $scope = '') {
    $classId = intval($classId);
    $split = $db->real_escape_string($splitDate);
    $db->query("UPDATE attendance_session SET period = IF(date >= '$split', 2, 1)
                WHERE class_section_id = $classId" . $scope);
}

/**
 * Builds everything the class record template renders.
 *
 * @return array|null Null when the class does not exist.
 */
function classrecord_load($db, $classId, $facultyName = '') {
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

    $meta = classrecord_defaults($class, $facultyName);
    $overrides = classrecord_load_settings($db, $classId);
    foreach ($meta as $key => $value) {
        if (array_key_exists($key, $overrides) && $overrides[$key] !== '') {
            $meta[$key] = $overrides[$key];
        }
    }

    // --- Periods -----------------------------------------------------------
    // An absent setting is an empty string, not 0: 0 is the explicit choice to
    // cover every year, and the two must not collapse into each other.
    $termYear = classrecord_resolve_term_year($db, $classId, $overrides['cr_term_year'] ?? '');
    $meta['cr_term_year'] = $termYear;

    // --- Period and term ---------------------------------------------------
    // The chosen month range decides what gets printed at all: only attendance
    // records dated inside it become columns.
    [$fromMonth, $toMonth] = classrecord_resolve_month_range($db, $classId,
        $overrides['cr_from_month'] ?? '', $overrides['cr_to_month'] ?? '', $termYear);
    $meta['cr_from_month'] = $fromMonth;
    $meta['cr_to_month'] = $toMonth;
    $meta['term_range_label'] = classrecord_term_range_label($fromMonth, $toMonth);

    // --- Meeting days ------------------------------------------------------
    // A class meets on the days it was scheduled for. Sessions on any other day
    // are not part of this class's term, so they never reach the sheet: a class
    // set to Monday and Friday prints Monday and Friday columns only.
    $weekdays = classrecord_scheduled_weekdays($db, $classId);
    $meta['cr_weekdays'] = $weekdays;

    // One scope string drives the split, the period flags and the column query, so
    // all three agree on exactly which dates are in play.
    $scope = classrecord_year_filter_sql($termYear)
        . classrecord_month_filter_sql($fromMonth, $toMonth)
        . classrecord_weekday_filter_sql($weekdays);

    $split = classrecord_resolve_split($db, $classId, $overrides['cr_period_split'] ?? '', $scope);
    classrecord_assign_periods($db, $classId, $split, $scope);
    $meta['cr_period_split'] = $split;

    $sessions = $db->query("SELECT * FROM attendance_session
                            WHERE class_section_id = $classId" . $scope
                            . " ORDER BY date ASC, id ASC")->fetch_all(MYSQLI_ASSOC);

    // student_id => session_id => status. Only the term's sessions are needed,
    // and the record is already scoped to one year, so the whole class is fine.
    $recordStmt = $db->prepare("SELECT r.student_id, r.attendance_session_id, r.status
                                FROM attendance_record r
                                JOIN attendance_session a ON a.id = r.attendance_session_id
                                WHERE a.class_section_id = ?");
    $recordStmt->bind_param('i', $classId);
    $recordStmt->execute();
    $records = [];
    $sessionIds = [];
    foreach ($sessions as $session) {
        $sessionIds[(int)$session['id']] = true;
    }
    foreach ($recordStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $record) {
        if (!isset($sessionIds[(int)$record['attendance_session_id']])) {
            continue;
        }
        $records[$record['student_id']][$record['attendance_session_id']] = (string)$record['status'];
    }

    $lateCountsPresent = !empty($class['attendance_late_counts_present']);

    // A status counts as attended: present always, late when the class says so,
    // and excused because the student was there and formally excused.
    $isAttended = function ($status) use ($lateCountsPresent) {
        if ($status === 'present' || $status === 'excused') {
            return true;
        }
        return $status === 'late' && $lateCountsPresent;
    };

    $periods = [];
    foreach ([1, 2] as $periodNo) {
        $periodLabel = ($periodNo === 1 ? '1st' : '2nd') . ' Period';

        $columns = [];
        foreach ($sessions as $session) {
            if ((int)$session['period'] !== $periodNo) {
                continue;
            }
            $type = $session['session_type'] ?? 'regular';
            $label = trim((string)($session['label'] ?? ''));
            $isBreak = ($type !== 'regular');

            $stamp = strtotime($session['date']);
            $columns[] = [
                'session_id' => (int)$session['id'],
                'date'       => date('Y-m-d', $stamp),
                'month'      => date('M', $stamp),          // "Aug"
                'day'        => date('j', $stamp),           // "11"
                'is_break'   => $isBreak,
                // The kind of event on its own ("holiday", "seminar"), so the narrow
                // header column can label itself without needing the full name.
                'break_type'  => $isBreak ? $type : '',
                // A break carries its name instead of 1/0 marks, and is left out
                // of the Total so a holiday never lowers attendance.
                'break_label' => $isBreak
                    ? ($label !== '' ? $label : ucfirst($type))
                    : '',
                'break_caption' => $isBreak && $label !== ''
                    ? ucfirst($type) . ' (' . $label . ')'
                    : '',
            ];
        }

        $periods[] = [
            'number'    => $periodNo,
            'label'     => $periodLabel,
            'columns'   => $columns,
            'countable' => count(array_filter($columns, function ($c) {
                return !$c['is_break'];
            })),
        ];
    }

    // --- Rows --------------------------------------------------------------
    // Total Meet is a property of the teacher's schedule, not of any student: it is
    // the number of scheduled meeting dates on the sheet. Holidays and seminars are
    // not meetings, so their columns are excluded here and can never raise it.
    $totalMeets = 0;
    foreach ($periods as $period) {
        $totalMeets += $period['countable'];
    }

    // One flat list of marks per student, in the same order as the printed date
    // columns, so a mark can never sit under the wrong date. A break column holds a
    // null and prints as the merged event banner instead of a mark.
    $dateColumns = [];
    foreach ($periods as $period) {
        foreach ($period['columns'] as $cIndex => $column) {
            $dateColumns[] = [
                'period'   => $period['number'],
                'column'   => $cIndex,
                'is_break' => (bool)$column['is_break'],
            ];
        }
    }

    $rows = [];
    foreach ($students as $student) {
        $sid = (int)$student['id'];
        $row = [
            'student_id' => $sid,
            'student_no' => $student['student_no'] ?? '',
            'name'       => trim(($student['last_name'] ?? '') . ', '
                              . ($student['first_name'] ?? '')
                              . (($student['middle_initial'] ?? '') !== ''
                                 ? ' ' . $student['middle_initial'] : '')),
            'periods'    => [],
            'marks'      => [],
        ];

        foreach ($periods as $pIndex => $period) {
            $attended = 0;
            $counted = 0;
            $cells = [];
            foreach ($period['columns'] as $column) {
                if ($column['is_break']) {
                    $cells[] = null;          // printed as the merged banner label
                    continue;
                }
                $status = $records[$sid][$column['session_id']] ?? '';
                $cells[] = $status === '' ? '' : ($isAttended($status) ? '1' : '0');
                $counted++;
                if ($isAttended($status)) {
                    $attended++;
                }
            }
            $row['periods'][$pIndex] = [
                'cells'    => $cells,
                'attended' => $attended,
                'total'    => $counted,
                'percent'  => $counted > 0 ? round(($attended / $counted) * 100) : 0,
            ];
        }

        // The same marks again in one row-level list, flattened in date order so the
        // printed cells and the header share a single index space.
        foreach ($dateColumns as $dIndex => $entry) {
            $row['marks'][$dIndex] = $row['periods'][$entry['period'] - 1]['cells'][$entry['column']];
        }

        // The three calculation columns at the far right:
        //   Attendance     - SUM of this student's marks, i.e. the days present
        //   Total Meet     - the teacher's scheduled meetings, the same for everyone
        //   Attendance (%) - ROUND(Attendance / Total Meet * 100)
        // A term with no meetings gives 0, 0 and 0% instead of dividing by zero.
        $overallAttended = 0;
        foreach ($row['marks'] as $mark) {
            if ($mark === '1') {
                $overallAttended++;
            }
        }
        $row['overall'] = [
            'attended' => $overallAttended,
            'meet'     => $totalMeets,
            'percent'  => $totalMeets > 0
                ? round(($overallAttended / $totalMeets) * 100) : 0,
        ];

        $rows[] = $row;
    }

    // Footer names are printed in upper case, as the form requires.
    foreach (['facilitator_name', 'program_chair', 'satellite_director'] as $key) {
        $meta[$key . '_upper'] = strtoupper(trim((string)($meta[$key] ?? '')));
    }

    return [
        'class'         => $class,
        'meta'          => $meta,
        'periods'       => $periods,
        'rows'          => $rows,
        'split_date'    => $split,
        'term_year'     => $termYear,
        'from_month'    => $fromMonth,
        'to_month'      => $toMonth,
        'total_meets'   => $totalMeets,
        'weekdays'      => $weekdays,
        'late_present'  => $lateCountsPresent,
    ];
}