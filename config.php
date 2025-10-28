<?php
// Core configuration and helper functions for API

// Database configuration (adjust via environment variables on server)
$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_PORT = getenv('DB_PORT') ?: '3306';
$DB_NAME = getenv('DB_NAME') ?: 'tokio';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';

// Singleton mysqli connection
function getDBConnection() {
    static $conn = null;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    mysqli_report(MYSQLI_REPORT_OFF);

    $host = $GLOBALS['DB_HOST'] . ':' . $GLOBALS['DB_PORT'];
    $user = $GLOBALS['DB_USER'];
    $pass = $GLOBALS['DB_PASS'];
    $name = $GLOBALS['DB_NAME'];

    $conn = @new mysqli($host, $user, $pass, $name);

    if ($conn->connect_errno) {
        error_log('DB connection failed: ' . $conn->connect_error);
        // Return stub object that throws informative errors on usage
        throw new Exception('Не удалось подключиться к базе данных');
    }

    // Ensure utf8mb4
    @$conn->set_charset('utf8mb4');

    return $conn;
}

// Simple email validation
function validateEmail($email) {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Login attempts tracking using filesystem to avoid DB dependency pre-auth
function getLoginAttemptsDir() {
    $dir = __DIR__ . '/runtime/login_attempts';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function getAttemptsFilePath($username) {
    $safe = preg_replace('~[^a-z0-9_.-]+~i', '_', (string)$username);
    return rtrim(getLoginAttemptsDir(), '/') . '/' . strtolower($safe) . '.json';
}

function readAttempts($username) {
    $file = getAttemptsFilePath($username);
    if (!is_file($file)) {
        return ['count' => 0, 'blocked_until' => 0];
    }
    $json = @file_get_contents($file);
    $data = @json_decode($json, true);
    if (!is_array($data)) {
        return ['count' => 0, 'blocked_until' => 0];
    }
    return [
        'count' => isset($data['count']) ? (int)$data['count'] : 0,
        'blocked_until' => isset($data['blocked_until']) ? (int)$data['blocked_until'] : 0,
    ];
}

function writeAttempts($username, $data) {
    $file = getAttemptsFilePath($username);
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function checkLoginAttempts($username) {
    $info = readAttempts($username);
    if ($info['blocked_until'] > time()) {
        return false;
    }
    return true;
}

function registerFailedLogin($username) {
    $info = readAttempts($username);
    $info['count'] = $info['count'] + 1;

    // Exponential backoff: 3 fails => 1 min, 5 => 5 min, 7 => 30 min
    $blockSeconds = 0;
    if ($info['count'] >= 7) {
        $blockSeconds = 30 * 60;
    } elseif ($info['count'] >= 5) {
        $blockSeconds = 5 * 60;
    } elseif ($info['count'] >= 3) {
        $blockSeconds = 60;
    }

    if ($blockSeconds > 0) {
        $info['blocked_until'] = time() + $blockSeconds;
    }

    writeAttempts($username, $info);
}

function resetLoginAttempts($username) {
    writeAttempts($username, ['count' => 0, 'blocked_until' => 0]);
}

// Security/audit logging (file-based)
function getSecurityLogPath() {
    $dir = __DIR__ . '/runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/security.log';
}

function logSecurityEvent($userId, $event, $details = '') {
    $line = sprintf(
        "%s\tuser=%s\tevent=%s\tdetails=%s\tip=%s\n",
        date('c'),
        $userId === null ? '-' : (string)$userId,
        (string)$event,
        str_replace(["\r", "\n"], ' ', (string)$details),
        isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-'
    );
    @file_put_contents(getSecurityLogPath(), $line, FILE_APPEND);
}

