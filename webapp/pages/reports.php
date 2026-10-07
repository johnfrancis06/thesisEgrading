<?php
require_once __DIR__ . '/../includes/gradesheet_data.php';
require_once __DIR__ . '/../includes/gradesheet_template.php';

$db = Database::getInstance()->getConnection();
$classId = intval($_GET['id'] ?? 0);
$facultyName = $_SESSION['faculty_name'] ?? '';
$facultyId = $auth->getFacultyId();

$sheetData = $classId > 0 ? gradesheet_load($db, $classId, $facultyName) : null;

// Without a class selected the page is a picker, so it needs the list to offer.
$classes = [];
if ($classId <= 0) {
    $stmt = $db->prepare(
        'SELECT cs.*, s.code, s.title FROM class_section cs
         JOIN subject s ON cs.subject_id = s.id
         WHERE cs.faculty_id = ?
         ORDER BY cs.academic_year DESC, cs.semester DESC'
    );
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Preview markup is rendered server-side so the page, print and exports all agree.
$sheetHtml = $sheetData ? gradesheet_render($sheetData) : '';
$sheetCss  = gradesheet_css();
$sheetPrintCss = gradesheet_print_css();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $sheetData ? htmlspecialchars($sheetData['meta']['course_number']) . ' - Grade Sheet' : 'Reports' ?> - <?= APP_NAME ?></title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=18">
    <style>
        <?= $sheetCss ?>
        .gs-preview-host { background: #e9ecef; padding: 16mm 12px; overflow-x: auto; }
        .gs-preview-host .sheet { box-shadow: 0 1px 6px rgba(0,0,0,.25); }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-content">
        <header class="topbar-modern">
            <div class="topbar-left">
                <button class="mobile-toggle" id="mobileToggle" aria-label="Toggle navigation">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title-block">
                    <h1 class="page-title"><?= $sheetData ? 'Grade Sheet' : 'Reports' ?></h1>
                    <span class="page-subtitle">
                        <?= $sheetData
                            ? htmlspecialchars($sheetData['meta']['course_number'])
                              . ' - ' . htmlspecialchars($sheetData['meta']['course_and_year'])
                            : 'Select a class to view its grade sheet' ?>
                    </span>
                </div>
            </div>
            <div class="topbar-right">
                <nav class="topbar-nav">
                    <a href="index.php?page=dashboard" class="nav-link <?= ($_GET['page'] ?? '') === 'dashboard' ? 'active' : '' ?>">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>
                    <a href="index.php?page=classes" class="nav-link <?= ($_GET['page'] ?? '') === 'classes' ? 'active' : '' ?>">
                        <i class="bi bi-people me-1"></i> Classes
                    </a>
                </nav>
                <div class="user-menu">
                    <div class="user-avatar"><?= strtoupper(substr($facultyName !== '' ? $facultyName : 'U', 0, 2)) ?></div>
                    <div class="user-info d-none d-md-block">
                        <span class="user-name"><?= htmlspecialchars($facultyName) ?></span>
                        <span class="user-role">Faculty</span>
                    </div>
                </div>
            </div>
        </header>

        <main class="content-area-modern">
            <?php if (!$sheetData): ?>
                <div class="page-header fade-in">
                    <div class="page-header-left">
                        <div class="page-header-icon">
                            <i class="bi bi-folder2-open"></i>
                        </div>
                        <div>
                            <h1 class="mb-0">Select a Class</h1>
                            <p class="text-muted mb-0">Choose a class to view its grade sheet</p>
                        </div>
                    </div>
                </div>

                <?php if (empty($classes)): ?>
                    <div class="card fade-in">
                        <div class="card-body text-center py-5">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <p>No classes yet. Create one from the
                                    <a href="index.php?page=classes">Classes</a> page first.</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($classes as $cs): ?>
                            <div class="col-md-6 col-lg-4">
                                <a href="index.php?page=reports&amp;id=<?= (int) $cs['id'] ?>"
                                   class="card card-hover text-decoration-none h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <span class="badge badge-primary"><?= htmlspecialchars($cs['code']) ?></span>
                                            <small class="text-muted"><?= htmlspecialchars($cs['academic_year']) ?></small>
                                        </div>
                                        <h5 class="mb-1"><?= htmlspecialchars($cs['course_program']) ?>
                                            Yr<?= htmlspecialchars($cs['year_level']) ?>
                                            - <?= htmlspecialchars($cs['section']) ?></h5>
                                        <p class="text-muted mb-0 small"><?= htmlspecialchars($cs['title']) ?></p>
                                        <div class="mt-3 pt-3 border-top">
                                            <span class="badge badge-info"><?= $cs['semester'] == 1 ? '1st Semester' : '2nd Semester' ?></span>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="card fade-in mb-3 no-print">
                    <div class="card-body d-flex flex-wrap gap-2 align-items-center edit-toolbar">
                        <button class="btn btn-outline-primary" id="btnToggleEdit" onclick="toggleEditMode()">
                            <i class="bi bi-pencil me-1"></i> Edit Mode
                        </button>
                        <button class="btn btn-outline-success" id="btnSaveChanges" onclick="saveReportChanges()" disabled>
                            <i class="bi bi-save me-1"></i> Save Changes
                        </button>
                        <button class="btn btn-danger" onclick="printSheet()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                        <a class="btn btn-primary" href="export_pdf.php?id=<?= $classId ?>" target="_blank" rel="noopener">
                            <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
                        </a>
                        <a class="btn btn-success" href="export_docx.php?id=<?= $classId ?>">
                            <i class="bi bi-file-earmark-word me-1"></i> Download Word
                        </a>
                        <span class="ms-auto text-muted small" id="saveStatus"></span>
                    </div>
                </div>

                <div class="card fade-in">
                    <div class="card-body gs-preview-host" id="sheetPreview">
                        <?= $sheetHtml ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function () {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });

        const REPORT_CLASS_ID = <?= $classId ?>;
        const SHEET_CSS = <?= json_encode($sheetCss) ?>;
        const PRINT_CSS = <?= json_encode($sheetPrintCss) ?>;
        let editMode = false;

        // The header and footer repeat on every sheet, so their data-field keys
        // appear more than once and every copy is editable. Track the elements the
        // user actually typed into - not just their keys - so collectFields() can
        // prefer the edited copy. Tracking keys alone would mark every copy of an
        // edited key as edited, and the last untouched one would win again.
        const editedFields = new Set();

        document.getElementById('sheetPreview')?.addEventListener('input', function (e) {
            const el = e.target;
            if (el?.getAttribute?.('data-field')) {
                editedFields.add(el);
                // Mirror the change to all other elements with the same data-field
                const key = el.getAttribute('data-field');
                const value = el.innerText;
                sheetElements().forEach(other => {
                    if (other !== el && other.getAttribute('data-field') === key) {
                        other.innerText = value;
                    }
                });
            }
        });

        document.getElementById('sheetPreview')?.addEventListener('blur', function (e) {
            const el = e.target;
            if (el?.getAttribute?.('data-field')) editedFields.add(el);
        }, true);

        function sheetElements() {
            return document.querySelectorAll('#sheetPreview [data-field]');
        }

        // Edit mode toggles contenteditable and the dashed highlight together.
        // The highlight lives on a body class, so it never reaches print or export.
        function toggleEditMode() {
            editMode = !editMode;
            const btn = document.getElementById('btnToggleEdit');
            const save = document.getElementById('btnSaveChanges');

            sheetElements().forEach(el => {
                el.contentEditable = editMode ? 'true' : 'false';
            });
            document.body.classList.toggle('gs-editing', editMode);

            btn.innerHTML = editMode
                ? '<i class="bi bi-check2 me-1"></i> Finish Editing'
                : '<i class="bi bi-pencil me-1"></i> Edit Mode';
            save.disabled = !editMode;
            setStatus('');
        }

        function setStatus(message, isError = false) {
            const el = document.getElementById('saveStatus');
            if (!el) return;
            el.textContent = message;
            el.classList.toggle('text-danger', isError);
            if (message && !isError) {
                setTimeout(() => { if (el.textContent === message) el.textContent = ''; }, 4000);
            }
        }

        // Collects every [data-field] value. innerText keeps the line breaks a user
        // may have typed into a signature cell.
        //
        // A key that repeats across sheets (header and footer metadata) resolves to
        // the copy the user edited. Unedited keys fall back to the first occurrence,
        // which is the stored value, since all untouched copies are identical.
        function collectFields() {
            const fields = {};

            // Baseline: the first copy of each key, which carries the stored value.
            sheetElements().forEach(el => {
                const key = el.getAttribute('data-field');
                if (key && !(key in fields)) fields[key] = (el.innerText || '').trim();
            });

            // Then overwrite with whatever the user actually typed into.
            editedFields.forEach(el => {
                const key = el.getAttribute('data-field');
                if (key) fields[key] = (el.innerText || '').trim();
            });

            return fields;
        }

        async function saveReportChanges() {
            const btn = document.getElementById('btnSaveChanges');
            const fields = collectFields();

            btn.disabled = true;
            setStatus('Saving...');

            try {
                const resp = await fetch('api/index.php?action=save_report_settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ class_id: REPORT_CLASS_ID, fields: fields })
                });
                const data = await resp.json();

                if (data.success) {
                    setStatus('Saved ' + (data.data?.saved ?? 0) + ' change(s).');
                } else {
                    setStatus(data.message || 'Save failed', true);
                }
            } catch (e) {
                setStatus('Save failed: ' + e.message, true);
            } finally {
                btn.disabled = !editMode;
            }
        }

        // Prints the sheets in a clean window carrying the same print rules.
        // A4 page size and margins come from the @page rule in the shared CSS.
        function printSheet() {
            const host = document.getElementById('sheetPreview');
            if (!host) return;

            const printWindow = window.open('', '_blank', 'width=794,height=1123');
            if (!printWindow) {
                alert('Please allow pop-ups to print the grade sheet.');
                return;
            }

            printWindow.document.write('<!DOCTYPE html><html><head><meta charset="utf-8">'
                + '<title>Grade Sheet</title><style>' + SHEET_CSS + PRINT_CSS + '</style></head>'
                + '<body>' + host.innerHTML + '</body></html>');
            printWindow.document.close();

            // Let the embedded logo decode, otherwise the print dialog opens blank.
            const images = [...printWindow.document.images];
            const ready = images.length
                ? Promise.race([
                    Promise.all(images.map(img => img.complete
                        ? Promise.resolve()
                        : new Promise(resolve => {
                            img.addEventListener('load', resolve, { once: true });
                            img.addEventListener('error', resolve, { once: true });
                        }))),
                    new Promise(resolve => setTimeout(resolve, 4000))
                ])
                : Promise.resolve();

            ready.then(() => {
                printWindow.focus();
                printWindow.print();
            });
        }
    </script>
</body>
</html>
