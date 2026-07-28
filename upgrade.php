<?php
/**
 * Applies superadmin/sql/upgrade.sql from the browser, so the database can be upgraded
 * without phpMyAdmin. Available while the upgrade is pending and to a logged-in
 * superadmin afterwards.
 */
require_once __DIR__ . '/config.php';

$pending = upgrade_pending($conn);
$isSuperadmin = !empty($_SESSION['superadmin_id']);

if (!$pending && !$isSuperadmin) {
    http_response_code(403);
}

$notes = [];
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($pending || $isSuperadmin)) {
    if (!verify_csrf()) {
        $errors[] = 'Your session expired. Reload the page and try again.';
    } else {
        $sql = file_get_contents(__DIR__ . '/superadmin/sql/upgrade.sql');
        if ($sql === false) {
            $errors[] = 'superadmin/sql/upgrade.sql could not be read.';
        } else {
            try {
                if ($conn->multi_query($sql)) {
                    do {
                        $result = $conn->store_result();
                        if ($result) {
                            while ($row = $result->fetch_assoc()) {
                                $notes[] = (string) reset($row);
                            }
                            $result->free();
                        }
                    } while ($conn->more_results() && $conn->next_result());
                }
                $done = true;
            } catch (mysqli_sql_exception $e) {
                error_log('Cancer registry upgrade failed: ' . $e->getMessage());
                $errors[] = 'The upgrade stopped: ' . $e->getMessage();
            }
        }
        $pending = upgrade_pending($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database upgrade | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>

<body class="bg-light">
    <main class="container py-5" style="max-width: 720px;">
        <h1 class="h4 mb-3">Database upgrade</h1>
        <p class="text-muted">Adds the edit timestamp, the account activation columns and the superadmin tables, and
            fixes the hospital delete error (#1451). It is safe to run more than once.</p>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-danger alert-persistent"><?= e($message) ?></div>
        <?php endforeach; ?>

        <?php if ($done && !$errors): ?>
            <div class="alert alert-success">Upgrade applied.</div>
        <?php endif; ?>

        <?php if ($notes): ?>
            <ul class="list-group mb-3">
                <?php foreach ($notes as $note): ?>
                    <li class="list-group-item py-2 small"><?= e($note) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($pending || $isSuperadmin): ?>
            <form method="post" class="d-flex gap-2">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit">Run the upgrade</button>
                <a class="btn btn-outline-secondary" href="index.php">Back to login</a>
            </form>
        <?php else: ?>
            <div class="alert alert-secondary alert-persistent">The database is already up to date. Sign in as a
                superadmin to run this page again.</div>
            <a class="btn btn-outline-secondary" href="index.php">Back to login</a>
        <?php endif; ?>
    </main>

    <script src="assets/js/app.js"></script>
</body>

</html>
