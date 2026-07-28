<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sa_is_logged_in()
{
    return !empty($_SESSION['superadmin_id']);
}

/** Redirect to the login page unless a superadmin session is active. */
function sa_require_login()
{
    if (!sa_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function sa_current_admin()
{
    return [
        'id' => $_SESSION['superadmin_id'] ?? null,
        'name' => $_SESSION['superadmin_name'] ?? '',
        'username' => $_SESSION['superadmin_username'] ?? '',
    ];
}

/** CSRF token for every state-changing form in the panel. */
function sa_csrf_token()
{
    if (empty($_SESSION['sa_csrf'])) {
        $_SESSION['sa_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['sa_csrf'];
}

function sa_csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(sa_csrf_token(), ENT_QUOTES) . '">';
}

function sa_verify_csrf()
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['sa_csrf']) || !hash_equals($_SESSION['sa_csrf'], $token)) {
        http_response_code(419);
        exit('Invalid or expired form token. Please go back and try again.');
    }
}

/** Write an entry to admin_activity_log. */
function sa_log(mysqli $conn, $action, $entity, $entityId = null, $details = null)
{
    $stmt = $conn->prepare(
        'INSERT INTO admin_activity_log (super_admin_id, action, entity, entity_id, details)
         VALUES (?, ?, ?, ?, ?)'
    );
    $adminId = $_SESSION['superadmin_id'] ?? null;
    $stmt->bind_param('issis', $adminId, $action, $entity, $entityId, $details);
    $stmt->execute();
    $stmt->close();
}

function sa_flash($message = null, $type = 'success')
{
    if ($message !== null) {
        $_SESSION['sa_flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['sa_flash'] ?? null;
    unset($_SESSION['sa_flash']);
    return $flash;
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
