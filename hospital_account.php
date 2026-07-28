<?php
/**
 * Creating hospital accounts is a registry-administrator task, so this page
 * only redirects to the superadmin panel where the full account management
 * (create, edit, deactivate, delete) lives.
 */

require_once __DIR__ . '/config.php';

if (!empty($_SESSION['superadmin_id'])) {
    redirect('superadmin/hospital_form.php');
}

http_response_code(403);
$pageTitle = 'Administrator only';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle . ' | ' . APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>

<body class="login-page">
    <div class="login-shell login-shell-narrow">
        <section class="login-card">
            <h2>Administrator only</h2>
            <p class="login-sub">Hospital accounts are created and managed by the registry administrator, not from
                the hospital login.</p>
            <a href="superadmin/login.php" class="btn btn-primary w-100">Open the superadmin panel</a>
            <p class="login-foot"><a href="index.php">Back to hospital login</a></p>
        </section>
    </div>
</body>

</html>
