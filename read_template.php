<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\vendor\autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$spreadsheet = IOFactory::load('C:\xampp\htdocs\thesisEgrading\webapp\assets\template\template.xlsx');
$sheet = $spreadsheet->getActiveSheet();

// Read rows 9 to 34
for ($row = 9; $row <= 34; $row++) {
    $rowData = [];
    for ($col = 'A'; $col <= 'Z'; $col++) {
        $cell = $sheet->getCell($col . $row);
        $value = $cell->getValue();
        if ($value !== null && $value !== '') {
            $rowData[$col] = $value;
        }
    }
    if (!empty($rowData)) {
        echo "Row $row: " . json_encode($rowData) . PHP_EOL;
    }
}