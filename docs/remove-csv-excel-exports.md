# Removing CSV and Excel Conversion — PDF and DOC Only

**Status:** plan, not yet applied
**Baseline:** commit `2aa8063` (branch `revert`)
**Scope:** strip every CSV / XLS / XLSX export path and leave only **PDF** and **Word (DOC/DOCX)**.

All line numbers below were verified against the baseline commit. Re-check them after
editing earlier parts of a file, since they drift.

---

## 1. Target state

| Report | PDF | Word | CSV | Excel |
|---|---|---|---|---|
| Grading Sheet (preview) | keep | keep | remove | remove |
| Grading Sheet (saved template) | keep | keep | remove | remove |
| Class Record | keep | add | remove | remove |
| E-Grading Submission | keep | add | remove | remove |
| Attendance | add | add | remove | remove |

Two gaps surface immediately: **Class Record, E-Grading and Attendance have no Word
generator at all** — only PDF. Removing CSV/Excel from them without adding DOC leaves
no export on those cards. The choices are in §6.

---

## 2. Naming trap: most "Excel" functions do not produce Excel

Do not trust the names. Verified by reading each function body:

| Function | File:line | Actually emits |
|---|---|---|
| `generateExcelReport` | `api/index.php:2416` | `text/csv` |
| `generateClassRecordExcel` | `api/index.php:2515` | `text/csv` |
| `generateEGradingExcel` | `api/index.php:2814` | `text/csv` |
| `generateGradingSheetExcel` | `api/index.php:2898` | `text/csv` (+ UTF-8 BOM) |
| `generateAttendanceExcel` | `api/index.php:2507` | delegates to `generateAttendanceCSV` |
| `exportGradingSheetXlsx` | `api/index.php:3172` | HTML table served as `.xls` |
| `exportGradingSheetCsv` | `api/index.php:3130` | `text/csv` |

So "remove Excel" also removes CSV in every case, and there is no spreadsheet library
in play — no PhpSpreadsheet, no composer dependency. The only Excel asset in the
project, `webapp/assets/vendor/xlsx.full.min.js`, is used for **import**, not export.

---

## 3. `webapp/api/index.php` (the live API)

### 3a. Format branches in the dispatcher

Delete the spreadsheet branch from each handler, keeping the PDF/DOC path.

| Line | Handler | Current | After |
|---|---|---|---|
| 1771-1772 | `generate_report` | `xlsx\|excel` → `generateExcelReport`, else `generatePdfEGrading` | always `generatePdfEGrading` |
| 1784-1785 | `export_egrading` | same | always `generatePdfEGrading` |
| 1792, 1798-1801 | `export_attendance` | `$format ?? 'csv'`; `xlsx` → `generateAttendanceExcel`, else `generateAttendanceCSV` | **no PDF/DOC path exists** — see §6.1 |
| 1892-1893 | `generate_class_record` | `xlsx\|excel` → Excel, else `generateClassRecordPdf` | always `generateClassRecordPdf` |
| 1911-1912 | `generate_egrading_report` | `xlsx\|excel` → Excel, else `generateEGradingPdf` | always `generateEGradingPdf` |
| 1930-1931 | `generate_grading_sheet_report` | `xlsx\|excel` → Excel, `doc` → `generateGradingSheetDoc`, else PDF | keep `doc` + PDF only |
| 2072-2080 | `export_grading_sheet` | `csv` / `xlsx` / `doc` / else PDF | keep `doc` + PDF only |

`export_grading_sheet` also needs a guard so the removed formats fail loudly instead
of silently falling through to the PDF `else`:

```php
$allowed = ['pdf', 'doc', 'word'];
if (!in_array($format, $allowed, true)) {
    echo ResponseAPI::error('Unsupported export format. Use pdf or doc.');
    exit;
}
```

The same guard belongs in the other four `generate_*` handlers, which today accept any
string and quietly return PDF.

### 3b. Functions to delete

- `generateExcelReport` — 2416
- `generateAttendanceCSV` — 2452
- `generateAttendanceExcel` — 2507
- `generateClassRecordExcel` — 2515
- `generateEGradingExcel` — 2814
- `generateGradingSheetExcel` — 2898
- `gradingSheetExcel` — 3068 (**already dead code** — no caller anywhere in this file)
- `exportGradingSheetCsv` — 3130
- `exportGradingSheetXlsx` — 3172

### 3c. Functions to keep

`generatePdfEGrading` (2446), `generateClassRecordPdf` (2671), `generateEGradingPdf`
(2853), `gradingSheetPageCss` (2948), `generateGradingSheetPdf` (2989),
`generateGradingSheetDoc` (2996), `gradingSheetHtml` (3002),
`exportGradingSheetDoc` (3214), `exportGradingSheetPdf` (3255).

Note `generateGradingSheetPdf` (2989) is *not* a PDF library — it emits the same A4 HTML
as the DOC path and injects `window.print()`, so the browser produces the PDF. That is
why "direct print" on the Reports page and "Export PDF" render identically. Keep both.

---

## 4. `webapp/pages/reports.php` (the UI)

| Line | Element | Action |
|---|---|---|
| 142-144 | "Export CSV" button | delete |
| 145-147 | "Export Excel" button | delete |
| 148-150 | "Export Word" button | keep |
| 139-141 | "Print / Save PDF" button | keep (added earlier) |
| 171-173 | Attendance "Export CSV" | delete |
| 174-176 | Attendance "Export Excel" | delete |
| 332 | `` `${format === 'xlsx' ? 'xls' : format}` `` | simplify to `a.download = \`GradingSheet_${classId}_${format}\`` |
| 304 | `` document.querySelectorAll('[data-export-format]') `` | still valid — two buttons keep the attribute |
| 253-255 | `exportReport(type, classId, format)` | **dead code**, no caller anywhere. Delete. |
| 598-600 | `exportAttendance(classId, format)` | keep, but it must stop defaulting to CSV server-side (see §3a) |

If Attendance keeps only PDF, rename its button to "Print / Save PDF" and call
`printReport()` for consistency with the Grading Sheet card.

---

## 5. Client-side and duplicated dispatchers

### 5a. `webapp/assets/js/grading.js`

`exportVisibleGradingSheet` (1023) contains an Excel branch at 1027-1034 using the
`XLSX` global. **Nothing calls it** — no button on the grading page wires to it, and
`grading.php` does not load `xlsx.full.min.js`, so the branch would only ever hit the
"Excel export is unavailable" alert. Delete the function and its `window` export at
line 1088.

`printVisibleGradingSheet` (1052) is print-only — keep it, and note it does **not**
use `gradingSheetPageCss()`, so it will not match the new A4 layout. Migrate it to the
same approach as `printReport()` in `reports.php`, or drop it if Reports is the only
print entry point.

### 5b. Duplicated dispatchers carrying the same spreadsheet code

These are separate copies, not includes. Each needs the same treatment or should be
deleted outright:

- `webapp/index.php` — 1381, 1394, 1409, 1415, generators at 1429, 1465, 1520
- `webapp/api_gateway.php` — 1364, 1377, 1392, 1398, 1492, 1511, 1530, generators at
  1806, 1842, 1897, 1905, 2204, 2288, 2386
- `webapp/api/index.php.bak` and `webapp/api/index.php.bak2` — dead backups, same
  handlers at 1387/1400 and 1389/1402

Confirm with the project owner whether `index.php` and `api_gateway.php` are still
routed. If not, delete them; if they are, apply the §3a branch changes to each.

---

## 6. Decisions required before implementing

1. **Attendance has neither a PDF nor a Word generator.** `export_attendance` only
   ever produced CSV (`generateAttendanceCSV` / `generateAttendanceExcel`). Removing CSV
   and Excel without adding a replacement leaves the Attendance card with no working
   button at all. Options: add a PDF generator, add both, or remove the card.
2. **Class Record and E-Grading have no Word generator.** Same question. Note their PDF
   renderers (`generateClassRecordPdf` 2671, `generateEGradingPdf` 2853) are built
   around hardcoded columns — Class Participation is fixed at CP1-CP4 with a 24-column
   table. They do **not** respect the dynamic `grade_category_config` categories, so
   they are already out of step with the grading sheet. Fixing that is a separate job
   from this removal.
3. **Is "docs" Word `.doc` or `.docx`?** The current `generateGradingSheetDoc` (2996)
   and `exportGradingSheetDoc` (3214) send HTML with
   `Content-Type: application/msword` and a `.doc` filename — Word HTML, not real
   OOXML. It opens correctly in Word but is not a true `.docx`. If a genuine `.docx` is
   required, that means adding a library (none exists today) and is a bigger change than
   this cleanup.
4. **Excel/CSV *import* — keep or remove?** Out of scope for "conversion" but the same
   assets. `classes.php` (186, 211, 302-312) and `section-enrollment.php` (15, 179, 208,
   215-216, 468-547) both use `assets/vendor/xlsx.full.min.js` for bulk import. Default
   recommendation: **keep** — removing exports does not require removing imports.
   Also note `webapp/assets/template/template.xlsx` is referenced nowhere in the code; it
   is a documentation sample, not a dependency.

---

## 7. Database

`grading_sheet_exports.export_format` is `enum('pdf','xlsx','doc','csv')`.

```sql
ALTER TABLE grading_sheet_exports
  MODIFY export_format ENUM('pdf','doc') NOT NULL;
```

Safe to run directly: the table currently holds **0 rows**, so no data migration or
backfill is needed. Do it *after* §3a so no handler can write a removed format into a
narrowed column.

Schema drift to fix while here: `grading_sheet_exports` is **not defined in
`webapp/sql/schema.sql`** at all, so a fresh install would be missing the table. Add the
CREATE TABLE with the narrowed ENUM.

---

## 8. Suggested order of work

1. §3a guards and branch removal (breaks nothing, disables the feature immediately).
2. §4 UI button removal — the feature is now invisible to users.
3. §3b function deletion.
4. §7 enum narrowing and schema.sql addition.
5. §5 duplicate dispatchers — verify reachability first.
6. §6 decisions, then implement whatever is chosen.

Each step is independently revertable, and steps 1-2 give a working system before any
code is deleted.

---

## 9. Verification checklist

- [ ] `php -l` on every touched file.
- [ ] No remaining `format=csv` / `format=xlsx` / `format=excel` request returns 200.
- [ ] `Select-String -Path webapp\*.php,webapp\**\*.php -Pattern 'csv|xlsx|fputcsv'`
      returns only import-side hits and comments.
- [ ] Reports page: every remaining export button produces PDF or Word.
- [ ] Grading Sheet PDF still renders one A4 sheet per page (see the A4 work: sheets are
      210mm × 297mm at `GRADING_SHEET_STUDENTS_PER_PAGE` = 15 rows).
- [ ] Word export opens in Word and keeps the header and footer images.
- [ ] `grading_sheet_exports` still accepts inserts for `pdf` and `doc`.
- [ ] Class Record, E-Grading and Attendance still export something (see §6).
