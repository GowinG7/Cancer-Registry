<?php
require_once __DIR__ . '/config.php';

if (isset($_SESSION['hospital_id'])) {
    redirect('dashboard.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (!verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $activeColumn = has_column($conn, 'hospital_accounts', 'is_active') ? 'is_active' : '1 AS is_active';
        $stmt = $conn->prepare('SELECT id, hospital_name, hospital_code, username, password, ' . $activeColumn . '
                                FROM hospital_accounts
                                WHERE username = ? OR hospital_code = ?
                                LIMIT 1');
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $hospital = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($hospital && password_verify($password, $hospital['password'])) {
            if ((int) $hospital['is_active'] !== 1) {
                $error = 'This hospital account has been deactivated. Contact the registry administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['hospital_id'] = (int) $hospital['id'];
                $_SESSION['hospital_name'] = $hospital['hospital_name'];
                $_SESSION['hospital_code'] = $hospital['hospital_code'];
                redirect('dashboard.php');
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

if (isset($_GET['disabled'])) {
    $error = 'Your hospital account is no longer active. Contact the registry administrator.';
}
if (isset($_GET['logged_out'])) {
    $notice = 'You have been signed out.';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Login | <?= e(APP_NAME) ?></title>
    <link rel="icon" href="assets/images/logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>

<body class="login-page">
    <div class="login-shell">
        <section class="login-intro">
            <img src="<?= e(logo_url()) ?>" alt="" class="login-logo">
            <h1><?= e(APP_NAME) ?></h1>
        </section>

        <section class="login-card">
            <h2>Hospital sign in</h2>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php elseif (!empty($notice)): ?>
                <div class="alert alert-success py-2"><?= e($notice) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="username" class="form-label">Username or hospital code</label>
                    <input id="username" name="username" type="text" class="form-control form-control-lg"
                        value="<?= e($username) ?>" autocomplete="username" autofocus required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="password-field">
                        <input id="password" name="password" type="password" class="form-control form-control-lg"
                            autocomplete="current-password" required>
                        <button type="button" class="password-toggle" data-target="password"
                            aria-label="Show password">Show</button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100">Sign in</button>
            </form>

            <p class="login-foot">
                Trouble signing in or need help? <a href="contact.php">Message the superadmin</a>
                <span class="d-block mt-1"><a href="superadmin/login.php">Superadmin panel</a></span>
            </p>
        </section>
    </div>

    <footer class="login-copyright">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app.js"></script>
</body>

</html>
