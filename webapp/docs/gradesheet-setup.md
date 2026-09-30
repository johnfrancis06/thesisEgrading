# GRADE SHEET rebuild - setup for XAMPP

The header and footer are now real HTML instead of images, so nothing breaks in
Word or PDF, everything is editable in the preview, and each sheet fits one A4 page.

## 1. Database

Already applied, but to install it on another machine run:

```
C:\xampp\mysql\bin\mysql.exe -u root egrading < webapp\sql\report_settings.sql
```

This creates `report_settings`, a key/value table scoped to a class. It stores the
edited report metadata and any edited student cells.

## 2. Enable the GD extension (required)

The Word export embeds the logo, and PhpWord needs GD to do it.

1. Open `C:\xampp\php\php.ini`
2. Uncomment (remove the leading `;`): `extension=gd`
3. Restart Apache from the XAMPP Control Panel

Check it worked:

```
C:\xampp\php\php.exe -r "echo extension_loaded('gd') ? 'gd ok' : 'gd MISSING';"
```

## 3. Install the export libraries

Install Composer from https://getcomposer.org/Composer-Setup.php, then in
`C:\xampp\htdocs\thesisEgrading`:

```
composer install
```

That pulls `dompdf/dompdf` and `phpoffice/phpword` from `composer.json` into
`vendor/`. To install them one at a time instead:

```
composer require dompdf/dompdf
composer require phpoffice/phpword
```

Until this is done, **Download PDF** and **Download Word** return a plain message
telling you to run the command. Print, Edit Mode and Save Changes all work without it.

## 4. The logo

The logo must be a real file at:

```
webapp/assets/img/csu-logo.png
```

It is embedded into exports as a Base64 data URI, so any PNG/JPG works and nothing
is ever linked to a URL. A 170x164 crop of the old `header.png` has been placed
there as a starting point - **check it looks like the real logo** and replace the
file with your own if not.

No logo file? The sheet still renders, with a dashed "LOGO" placeholder in its place.
To re-crop from the original letterhead (needs GD enabled):

```
C:\xampp\php\php.exe webapp\tools\extract_logo.php webapp\assets\images\header.png 0 0 170 164
```

## 5. Use it

Open `index.php?page=reports&id=18`.

| Button | What it does |
|---|---|
| Edit Mode | Makes every value editable and shows dashed outlines. Off by default. |
| Save Changes | POSTs the edited values to `save_report_settings`. They persist on reload. |
| Print | Opens a clean A4 window and calls `window.print()`. |
| Download PDF | Server-side render via Dompdf (falls back to mPDF). |
| Download Word | Real `.docx` via PhpWord with the logo embedded. |

## 6. Optional: students per page

`webapp/config/gradesheet_settings.php`:

```php
return array('rows_per_page' => 20);
```

Every sheet carries its own certification, signature and grading-scale footer, so this
value is bounded by the header, the student table and that footer together. At 9pt the
footer ends around 242 mm on a full sheet, inside the 287 mm printable limit, so there is
room to raise it if the signature block is ever trimmed. The footer is not shared
between sheets: each page carries its own copy, and because every copy is editable
you can change the name on one page and it applies to the whole document.

## Notes on the sheet CSS

The print rules hide the surrounding app chrome and keep only `.sheet`. One deliberate
deviation from the original spec: the sheets are left in **normal flow** rather than
`position: absolute`. Absolute positioning removes every sheet from the flow, so they
all stack at `left:0/top:0` and the whole report prints as a single overlapping page.
Verified with headless Chrome: the absolute version produced 1 page for 29 students,
the flow version produces the correct 2 (and 3 when the page size is lowered).

Two layout rules are load-bearing, so keep them if the CSS is ever edited:

- **`.sheet table, .sheet th, .sheet td { box-sizing: border-box; }`** Percentage
  column widths must cover padding and border. At the default `content-box` the width
  applies to the text area only, the padding is added on top, the row totals more than
  100%, and the browser silently rescales every column - so the rendered columns stop
  matching both the values in the CSS and the `.docx` column widths.
- **Column percentages must total 100%.** With `table-layout: fixed` any leftover is
  redistributed across the columns. The student table is 5% + 34% + 6 x 10.1667%, and
  `export_docx.php` mirrors those same proportions in twips.
- **The header and the footer both repeat on every sheet, and every copy is editable.**
  That means a `data-field` key can appear several times in one document, so
  `collectFields()` in `pages/reports.php` resolves duplicates in two passes: the first
  copy of a key supplies the stored value, then any element the user actually typed into
  overwrites it. It tracks the edited **elements**, not their keys - tracking keys alone
  marks every copy of an edited key as edited and the last untouched copy wins, which
  silently discards the user's change.

## Layout of the new files

| File | Purpose |
|---|---|
| `includes/gradesheet_data.php` | Loads class, students, grades and saved overrides. |
| `includes/gradesheet_template.php` | The single HTML template + print CSS. |
| `api/index.php` | `render_gradesheet` and `save_report_settings` actions. |
| `pages/reports.php` | Preview page, edit mode, save, print. |
| `export_pdf.php` | Dompdf / mPDF export. |
| `export_docx.php` | PhpWord export. |
| `sql/report_settings.sql` | The new table. |
| `tools/extract_logo.php` | Optional logo crop helper. |
