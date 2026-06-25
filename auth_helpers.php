<?php

function checkoutPasswordHash($password)
{
    return password_hash((string) $password, PASSWORD_DEFAULT);
}

function checkoutVerifyPassword($password, $storedHash)
{
    $storedHash = (string) $storedHash;

    if (strpos($storedHash, 'sha256$') === 0) {
        return hash_equals('sha256$' . hash('sha256', (string) $password), $storedHash);
    }

    return password_verify((string) $password, $storedHash);
}

function checkoutPasswordNeedsRehash($storedHash)
{
    $storedHash = (string) $storedHash;

    if (strpos($storedHash, 'sha256$') === 0) {
        return true;
    }

    return password_needs_rehash($storedHash, PASSWORD_DEFAULT);
}

function checkoutRehashUserPassword($conn, $userId, $password)
{
    $userId = (int) $userId;

    if ($userId < 1) {
        return;
    }

    $passwordHash = checkoutPasswordHash($password);
    $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->bind_param('si', $passwordHash, $userId);
    $stmt->execute();
    $stmt->close();
}

function checkoutUsernameIsValid($username)
{
    return preg_match('/\A[a-zA-Z0-9._-]{3,50}\z/', (string) $username) === 1;
}

function checkoutEmailIsValid($email)
{
    return filter_var((string) $email, FILTER_VALIDATE_EMAIL) !== false;
}

function checkoutPasswordIsValid($password)
{
    $length = strlen((string) $password);

    return $length >= 5 && $length <= 255;
}

function ensureCheckoutUsersTable($conn)
{
    $stmt = $conn->prepare(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL,
            email VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_username (username),
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $stmt->execute();
    $stmt->close();
}

function ensureCheckoutAdmin($conn)
{
    $adminUsername = 'admin';
    $adminPassword = getenv('FIXERUPPER_ADMIN_PASSWORD') ?: 'admin';
    $adminRole = 'admin';
    $adminId = 0;
    $adminStoredHash = '';
    $adminStoredRole = '';
    $stmt = $conn->prepare('SELECT id, password_hash, role FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $adminUsername);
    $stmt->execute();
    $stmt->bind_result($adminId, $adminStoredHash, $adminStoredRole);
    $hasAdmin = $stmt->fetch();
    $stmt->close();

    if ($hasAdmin) {
        if (
            !checkoutVerifyPassword($adminPassword, $adminStoredHash)
            || checkoutPasswordNeedsRehash($adminStoredHash)
            || $adminStoredRole !== $adminRole
        ) {
            $adminHash = checkoutPasswordHash($adminPassword);
            $stmt = $conn->prepare('UPDATE users SET password_hash = ?, role = ? WHERE id = ?');
            $stmt->bind_param('ssi', $adminHash, $adminRole, $adminId);
            $stmt->execute();
            $stmt->close();
        }

        return;
    }

    $adminEmail = 'admin@fixerupper.local';
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $adminEmail);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $adminEmail = 'admin-' . substr(hash('sha256', __DIR__), 0, 8) . '@fixerupper.local';
    }

    $stmt->close();
    $adminHash = checkoutPasswordHash($adminPassword);
    $stmt = $conn->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $adminUsername, $adminEmail, $adminHash, $adminRole);
    $stmt->execute();
    $stmt->close();
}

function checkoutFindUserByIdentifier($conn, $identifier)
{
    $stmt = $conn->prepare(
        'SELECT id, username, email, password_hash, role
         FROM users
         WHERE username = ? OR email = ?
         LIMIT 1'
    );
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

function checkoutSetUserSession($user)
{
    if (function_exists('fixerupperRegenerateSessionId')) {
        fixerupperRegenerateSessionId();
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['checkout_user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
    ];
}

function setCheckoutAuthFlash($message, $tone, $values = [])
{
    $_SESSION['checkout_auth_flash'] = [
        'message' => (string) $message,
        'tone' => (string) $tone,
        'values' => [
            'login_identifier' => (string) ($values['login_identifier'] ?? ''),
            'register_username' => (string) ($values['register_username'] ?? ''),
            'register_email' => (string) ($values['register_email'] ?? ''),
        ],
    ];
}
