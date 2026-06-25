<?php
// Store PHP session files inside the project so the cart works consistently in XAMPP.
$sessionPath = __DIR__ . '/storage/sessions';
$sessionLifetime = 1800;
$sessionRegenerateInterval = 300;

function fixerupperIsHttpsRequest()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function fixerupperIsLocalHost()
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    $host = trim($host, '[]');

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($host, '.local');
}

function fixerupperIpPrefix($ip)
{
    $ip = (string) $ip;

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return preg_replace('/\.\d+$/', '.0', $ip);
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return implode(':', array_slice(explode(':', $ip), 0, 4));
    }

    return '';
}

function fixerupperSessionFingerprint()
{
    return hash('sha256', implode('|', [
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
        fixerupperIpPrefix($_SERVER['REMOTE_ADDR'] ?? ''),
    ]));
}

function fixerupperRegenerateSessionId()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    session_regenerate_id(true);
    $_SESSION['last_regenerated'] = time();
    $_SESSION['session_fingerprint'] = fixerupperSessionFingerprint();
}

function fixerupperResetSession()
{
    $_SESSION = [];
    fixerupperRegenerateSessionId();
}

function fixerupperGuardSession($sessionLifetime, $sessionRegenerateInterval)
{
    $now = time();
    $fingerprint = fixerupperSessionFingerprint();
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    $lastRegenerated = (int) ($_SESSION['last_regenerated'] ?? 0);
    $storedFingerprint = (string) ($_SESSION['session_fingerprint'] ?? '');

    if ($lastActivity > 0 && $now - $lastActivity > $sessionLifetime) {
        fixerupperResetSession();
        $lastRegenerated = (int) ($_SESSION['last_regenerated'] ?? 0);
        $storedFingerprint = '';
    }

    if ($storedFingerprint !== '' && !hash_equals($storedFingerprint, $fingerprint)) {
        fixerupperResetSession();
        $lastRegenerated = (int) ($_SESSION['last_regenerated'] ?? 0);
    }

    $_SESSION['session_fingerprint'] = $fingerprint;

    if ($lastRegenerated < 1) {
        $_SESSION['last_regenerated'] = $now;
    } elseif ($now - $lastRegenerated > $sessionRegenerateInterval) {
        fixerupperRegenerateSessionId();
    }

    $_SESSION['last_activity'] = $now;
}

// Create the session directory on first run instead of requiring manual setup.
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}

// Point PHP at the project session directory before session_start() creates a file.
if (is_dir($sessionPath)) {
    session_save_path($sessionPath);
}

$isHttps = fixerupperIsHttpsRequest();

if (!$isHttps && !fixerupperIsLocalHost() && !headers_sent() && !empty($_SERVER['HTTP_HOST'])) {
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $requestUri, true, 301);
    exit;
}

if ($isHttps && !headers_sent()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', (string) $sessionLifetime);

session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Start or resume the visitor session that stores cart quantities.
session_start();
fixerupperGuardSession($sessionLifetime, $sessionRegenerateInterval);
