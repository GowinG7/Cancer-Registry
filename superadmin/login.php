<?php
require_once __DIR__ . '/includes/auth.php';

if (sa_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sa_verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare(
        'SELECT id, full_name, username, password, is_active FROM super_admins WHERE username = ? OR email = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$admin || !password_verify($password, $admin['password'])) {
        $error = 'Invalid username or password.';
    } elseif (!$admin['is_active']) {
        $error = 'This superadmin account is deactivated.';
    } else {
        session_regenerate_id(true);
        $_SESSION['superadmin_id'] = (int) $admin['id'];
        $_SESSION['superadmin_name'] = $admin['full_name'];
        $_SESSION['superadmin_username'] = $admin['username'];

        $stmt = $conn->prepare('UPDATE super_admins SET last_login_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $admin['id']);
        $stmt->execute();
        $stmt->close();

        sa_log($conn, 'login', 'super_admin', (int) $admin['id'], 'Superadmin signed in');
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Superadmin Login | Nepal Cancer Registry Programme</title>
    <link rel="icon" href="../assets/images/logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body class="login-page">
    <div class="login-shell login-shell-narrow">
        <section class="login-card">
            <img src="<?= e(logo_url('../')) ?>" alt="" class="login-card-logo">
            <h2>Superadmin sign in</h2>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= sa_csrf_field() ?>
                <div class="mb-3">
                    <label for="username" class="form-label">Username or email</label>
                    <input type="text" id="username" name="username" class="form-control form-control-lg"
                        autocomplete="username" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="password-field">
                        <input type="password" id="password" name="password" class="form-control form-control-lg"
                            autocomplete="current-password" required>
                        <button type="button" class="password-toggle" data-target="password"
                            aria-label="Show password">Show</button>
                    </div>
                </div>
                <button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>
            </form>

            <p class="login-foot"><a href="../index.php">Back to hospital sign in</a></p>
        </section>
    </div>

    <footer class="login-copyright">&copy; <?= date('Y') ?> Nepal Cancer Registry Programme</footer>

    <script src="../assets/js/app.js"></script>
</body>

</html>
