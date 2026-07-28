<?php
/**
 * Shared page shell: opens the document and renders the navigation bar.
 *
 * Pages set $pageTitle and must close the document with includes/footer.php.
 * $hospital is provided by require_hospital_login().
 */

$hospital = $hospital ?? null;
$currentPage = basename($_SERVER['PHP_SELF']);
$navLinks = [
    'dashboard.php' => 'Patient Records',
    'add_patient_diagnosis.php' => 'Add Patient',
    'profile.php' => 'Hospital Profile',
    'contact.php' => 'Contact Superadmin',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(($pageTitle ?? 'Dashboard') . ' | ' . APP_NAME) ?></title>
    <link rel="icon" href="assets/images/logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg app-navbar sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
                <?php $logo = !empty($hospital['logo']) && is_file(__DIR__ . '/../uploads/logos/' . $hospital['logo'])
                    ? 'uploads/logos/' . $hospital['logo']
                    : logo_url(); ?>
                <img src="<?= e($logo) ?>" alt="" class="hospital-logo">
                <span>
                    <span class="brand-title"><?= e($hospital['hospital_name'] ?? APP_NAME) ?></span>
                    <?php if ($hospital): ?>
                        <span class="brand-subtitle d-block">Cancer Registry · Code
                            <?= e($hospital['hospital_code']) ?></span>
                    <?php endif; ?>
                </span>
            </a>

            <?php if ($hospital): ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                        <?php foreach ($navLinks as $file => $label): ?>
                            <li class="nav-item">
                                <a class="nav-link<?= $currentPage === $file ? ' active' : '' ?>"
                                    href="<?= e($file) ?>"><?= e($label) ?></a>
                            </li>
                        <?php endforeach; ?>
                        <li class="nav-item ms-lg-2">
                            <span class="navbar-user"><?= e($hospital['username']) ?></span>
                        </li>
                        <li class="nav-item">
                            <a href="logout.php" class="btn btn-sm btn-outline-light ms-lg-2">Log out</a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container py-4">
        <?php if (isset($conn) && upgrade_pending($conn)): ?>
            <div class="alert alert-warning alert-persistent" role="alert">
                <strong>Database upgrade pending.</strong> Open <a href="upgrade.php">upgrade.php</a> (or import
                <code>superadmin/sql/upgrade.sql</code>) to enable
                edit timestamps, account deactivation and hospital deletion.
            </div>
        <?php endif; ?>
        <?php foreach (take_flashes() as $type => $messages): ?>
            <?php foreach ($messages as $message): ?>
                <div class="alert alert-<?= e($type === 'error' ? 'danger' : $type) ?> alert-dismissible fade show"
                    role="alert">
                    <?= e($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
