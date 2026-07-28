<?php
require_once __DIR__ . '/auth.php';
sa_require_login();

$saAdmin = sa_current_admin();
$saPage = basename($_SERVER['PHP_SELF']);
$saFlash = sa_flash();

$saNewMessages = has_table($conn, 'support_messages')
    ? (int) $conn->query("SELECT COUNT(*) FROM support_messages WHERE status = 'new'")->fetch_row()[0]
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? e($pageTitle) . ' | ' : '' ?>Superadmin | Cancer Registry</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark">CR</span>
      <div>
        <strong>Cancer Registry</strong>
        <small>Superadmin</small>
      </div>
    </div>
    <nav>
      <a href="index.php" class="<?= $saPage === 'index.php' ? 'active' : '' ?>">Dashboard</a>
      <a href="hospitals.php" class="<?= in_array($saPage, ['hospitals.php', 'hospital_form.php'], true) ? 'active' : '' ?>">Hospital Accounts</a>
      <a href="patients.php" class="<?= $saPage === 'patients.php' ? 'active' : '' ?>">Patient Records</a>
      <a href="icd.php" class="<?= $saPage === 'icd.php' ? 'active' : '' ?>">ICD Master</a>
      <a href="exports.php" class="<?= $saPage === 'exports.php' ? 'active' : '' ?>">Excel Exports</a>
      <a href="messages.php" class="<?= $saPage === 'messages.php' ? 'active' : '' ?>">Messages
        <?php if ($saNewMessages > 0): ?><span class="nav-count"><?= $saNewMessages ?></span><?php endif; ?>
      </a>
      <a href="activity_log.php" class="<?= $saPage === 'activity_log.php' ? 'active' : '' ?>">Activity Log</a>
      <a href="profile.php" class="<?= $saPage === 'profile.php' ? 'active' : '' ?>">My Profile</a>
    </nav>
  </aside>
  <main class="content">
    <header class="topbar">
      <h1><?= isset($pageTitle) ? e($pageTitle) : 'Superadmin' ?></h1>
      <div class="topbar-user">
        <span class="who">Signed in as <strong><?= e($saAdmin['name'] ?: $saAdmin['username']) ?></strong></span>
        <a class="logout" href="logout.php">Log out</a>
      </div>
    </header>
    <?php if ($saFlash): ?>
      <div class="alert alert-<?= e($saFlash['type']) ?>"><?= e($saFlash['message']) ?></div>
    <?php endif; ?>
