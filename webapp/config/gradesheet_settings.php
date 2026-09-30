<?php
// Grade sheet report options.
//
// rows_per_page is how many students fit on one A4 sheet. Each sheet carries
// its own certification, signature and grading-scale footer, so this is bounded
// by the header, the table and that footer together. 20 fits with room to spare
// and the footer can be raised further if the signature block is ever trimmed.
return array('rows_per_page' => 20);
