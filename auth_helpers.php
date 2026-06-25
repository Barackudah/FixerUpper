<?php

function checkoutPasswordHash($password)
{
    return 'sha256$' . hash('sha256', (string) $password);
}

function checkoutVerifyPassword($password, $storedHash)
{
    $storedHash = (string) $storedHash;

    if (strpos($storedHash, 'sha256$') === 0) {
        return hash_equals(checkoutPasswordHash($password), $storedHash);
    }

    return password_verify((string) $password, $storedHash);
}

function ensureCheckoutUsersTable($conn)
{
    $conn->query(
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
}

function ensureCheckoutAdmin($conn)
{
    $adminHash = checkoutPasswordHash('admin');
    $adminId = 0;
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
    $stmt->execute();
    $stmt->bind_result($adminId);
    $hasAdmin = $stmt->fetch();
    $stmt->close();

    if ($hasAdmin) {
        $stmt = $conn->prepare("UPDATE users SET password_hash = ?, role = 'admin' WHERE id = ?");
        $stmt->bind_param('si', $adminHash, $adminId);
        $stmt->execute();
        $stmt->close();
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
    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role) VALUES ('admin', ?, ?, 'admin')");
    $stmt->bind_param('ss', $adminEmail, $adminHash);
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
