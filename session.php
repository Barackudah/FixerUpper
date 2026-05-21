<?php
$sessionPath = __DIR__ . '/storage/sessions';

if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}

if (is_dir($sessionPath)) {
    session_save_path($sessionPath);
}

session_start();
