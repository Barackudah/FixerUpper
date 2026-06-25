<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/auth_helpers.php';

ensureInventoryTable($conn);
ensureCheckoutUsersTable($conn);
ensureCheckoutAdmin($conn);

function redirectToCheckout()
{
    header('Location: cart.php?checkout=1');
    exit;
}

function redirectToCart()
{
    header('Location: cart.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectToCheckout();
}

$checkoutAction = (string) ($_POST['checkout_auth_action'] ?? '');

if ($checkoutAction === 'login') {
    $identifier = trim((string) ($_POST['login_identifier'] ?? ''));
    $password = (string) ($_POST['login_password'] ?? '');

    if ($identifier === '' || $password === '') {
        setCheckoutAuthFlash('enter your login details.', 'error', [
            'login_identifier' => $identifier,
        ]);
        redirectToCheckout();
    }

    $user = checkoutFindUserByIdentifier($conn, $identifier);

    if (!$user || !checkoutVerifyPassword($password, $user['password_hash'])) {
        setCheckoutAuthFlash('login details are incorrect.', 'error', [
            'login_identifier' => $identifier,
        ]);
        redirectToCheckout();
    }

    checkoutSetUserSession($user);
    mergeGuestCartIntoUserCart($conn, (int) $user['id']);
    unset($_SESSION['checkout_auth_flash']);
    redirectToCart();
}

if ($checkoutAction === 'register') {
    $username = trim((string) ($_POST['register_username'] ?? ''));
    $email = trim((string) ($_POST['register_email'] ?? ''));
    $password = (string) ($_POST['register_password'] ?? '');
    $passwordConfirm = (string) ($_POST['register_password_confirm'] ?? '');
    $flashValues = [
        'register_username' => $username,
        'register_email' => $email,
    ];

    if ($username === '' || $email === '' || $password === '' || $passwordConfirm === '') {
        setCheckoutAuthFlash('complete all create account fields.', 'error', $flashValues);
        redirectToCheckout();
    }

    if (strlen($username) > 50 || strlen($email) > 100) {
        setCheckoutAuthFlash('username or email is too long.', 'error', $flashValues);
        redirectToCheckout();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setCheckoutAuthFlash('enter a valid email.', 'error', $flashValues);
        redirectToCheckout();
    }

    if ($password !== $passwordConfirm) {
        setCheckoutAuthFlash('passwords do not match.', 'error', $flashValues);
        redirectToCheckout();
    }

    if (checkoutFindUserByIdentifier($conn, $username)) {
        setCheckoutAuthFlash('username is already taken.', 'error', $flashValues);
        redirectToCheckout();
    }

    if (checkoutFindUserByIdentifier($conn, $email)) {
        setCheckoutAuthFlash('email is already registered.', 'error', $flashValues);
        redirectToCheckout();
    }

    $passwordHash = checkoutPasswordHash($password);
    $role = 'user';
    $stmt = $conn->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $username, $email, $passwordHash, $role);
    $stmt->execute();
    $newUserId = $stmt->insert_id;
    $stmt->close();

    checkoutSetUserSession([
        'id' => $newUserId,
        'username' => $username,
        'email' => $email,
        'role' => $role,
    ]);

    mergeGuestCartIntoUserCart($conn, $newUserId);
    unset($_SESSION['checkout_auth_flash']);
    redirectToCart();
}

setCheckoutAuthFlash('choose sign in or create account.', 'error');
redirectToCheckout();
