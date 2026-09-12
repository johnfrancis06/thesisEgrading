<?php
// Local Development Configuration

// Session configuration
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST']
]);

// Development error handling - show errors locally
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Prevent common attacks
define('INCLUDE_GUARD', true);
?>
