<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/helpers.php';

unset($_SESSION['checkout_user']);
clearGuestCart();

header('Location: index.php');
exit;
