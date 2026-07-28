<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$host = "127.0.0.1";
$user = "root";
$pass = "";
$db = "cancer_registry";

mysqli_report(MYSQLI_REPORT_OFF); // report the connection problem below instead of throwing

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn->set_charset("utf8mb4");
date_default_timezone_set("Asia/Kathmandu");

// Shared helpers used by every page (escaping, CSRF, login guard, queries).
require_once __DIR__ . "/includes/functions.php";
