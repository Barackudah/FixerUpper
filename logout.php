<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/helpers.php';

unset($_SESSION['checkout_user']);
clearGuestCart();

if (function_exists('fixerupperRegenerateSessionId')) {
    fixerupperRegenerateSessionId();
} elseif (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

header('Location: index.php');
exit;
