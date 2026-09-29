<?php
$zip = new ZipArchive;
if ($zip->open('C:\xampp\htdocs\thesisEgrading\webapp\assets\template\template.xlsx') === true) {
    $content = $zip->getFromName('xl/worksheets/sheet1.xml');
    echo $content;
    $zip->close();
} else {
    echo 'Failed to open';
}