<?php
require_once __DIR__ . '/includes/auth.php';

if (sa_is_logged_in()) {
    sa_log($conn, 'logout', 'super_admin', (int) $_SESSION['superadmin_id'], 'Superadmin signed out');
}

$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
