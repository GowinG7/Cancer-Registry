<?php
/**
 * The panel uses the project's single database connection ($conn) from the
 * root config.php, so the credentials live in one file only.
 */
require_once __DIR__ . '/../../config.php';

// Folder where hospital logos are stored.
define('SA_UPLOAD_DIR', __DIR__ . '/../../uploads/logos');
define('SA_UPLOAD_URL', '../uploads/logos');

// The panel lives in tables created by superadmin/sql/upgrade.sql.
if (!$conn->query('SELECT 1 FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "super_admins"')->fetch_row()) {
    header('Location: ../upgrade.php');
    exit;
}
