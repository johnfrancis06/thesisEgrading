<?php
require_once '../includes/auth.php';
$auth->logout();
header("Location: ../index.php?page=login");
?>
