<?php
// Store PHP session files inside the project so the cart works consistently in XAMPP.
$sessionPath = __DIR__ . '/storage/sessions';

// Create the session directory on first run instead of requiring manual setup.
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}

// Point PHP at the project session directory before session_start() creates a file.
if (is_dir($sessionPath)) {
    session_save_path($sessionPath);
}

// Start or resume the visitor session that stores cart quantities.
session_start();
