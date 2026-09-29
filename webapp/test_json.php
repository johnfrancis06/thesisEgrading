<?php
header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'test works', 'time' => date('H:i:s')]);