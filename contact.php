<?php
/**
 * Support request form. Hospitals use it to reach the superadmin, whether or
 * not they can sign in, so it is reachable from the login page too. Messages
 * land in the superadmin panel under Messages.
 */
require_once __DIR__ . '/config.php';

$signedIn = isset($_SESSION['hospital_id']);
$backLink = $signedIn ? 'dashboard.php' : 'index.php';
$errors = [];
$sent = false;

$form = [
    'hospital_name' => $_SESSION['hospital_name'] ?? '',
    'contact_person' => '',
    'email' => '',
    'contact_no' => '',
    'subject' => '',
    'message' => '',
];

// Prefill from the signed-in hospital's own profile.
if ($signedIn) {
    $stmt = $conn->prepare('SELECT hospital_name, email, contact_no FROM hospital_accounts WHERE id = ?');
    $stmt->bind_param('i', $_SESSION['hospital_id']);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $form['hospital_name'] = $row['hospital_name'];
        $form['email'] = $row['email'];
        $form['contact_no'] = $row['contact_no'];
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $field => $_) {
        $form[$field] = trim($_POST[$field] ?? '');
    }

    if (!verify_csrf()) {
        $errors[] = 'Your session expired. Please send the message again.';
    }
    if ($form['hospital_name'] === '') {
        $errors[] = 'Hospital name is required.';
    }
    if ($form['contact_person'] === '') {
        $errors[] = 'Your name is required.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required so the superadmin can reply.';
    }
    if ($form['subject'] === '') {
        $errors[] = 'Subject is required.';
    }
    if (strlen($form['message']) < 10) {
        $errors[] = 'Please describe the issue in at least 10 characters.';
    }
    if (!has_table($conn, 'support_messages')) {
        $errors[] = 'Messaging is not available yet: the database upgrade has not been run.';
    }

    if (!$errors) {
        $hospitalId = $signedIn ? (int) $_SESSION['hospital_id'] : null;
        $stmt = $conn->prepare(
            'INSERT INTO support_messages
             (hospital_id, hospital_name, contact_person, email, contact_no, subject, message)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'issssss',
            $hospitalId,
            $form['hospital_name'],
            $form['contact_person'],
            $form['email'],
            $form['contact_no'],
            $form['subject'],
            $form['message']
        );
        $stmt->execute();
        $stmt->close();

        $sent = true;
        $form['subject'] = '';
        $form['message'] = '';
        $form['contact_person'] = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact the Superadmin | <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(logo_url()) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>

<body class="login-page">

    <div class="login-shell login-shell-narrow">
        <section class="login-card">
            <img src="<?= e(logo_url()) ?>" alt="" class="login-card-logo">
            <h2>Contact the superadmin</h2>
            <p class="text-muted small mb-4">Report a problem or ask for help with your hospital account. The
                superadmin sees your message in the registry panel and replies to the email you give below.</p>

            <?php if ($sent): ?>
                <div class="alert alert-success py-2">Your message has been sent. The superadmin will get back to you.
                </div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger alert-persistent py-2">
                    <?php foreach ($errors as $message): ?>
                        <div><?= e($message) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="hospital_name" class="form-label">Hospital name</label>
                        <input type="text" id="hospital_name" name="hospital_name" class="form-control"
                            value="<?= e($form['hospital_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="contact_person" class="form-label">Your name</label>
                        <input type="text" id="contact_person" name="contact_person" class="form-control"
                            value="<?= e($form['contact_person']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email for the reply</label>
                        <input type="email" id="email" name="email" class="form-control"
                            value="<?= e($form['email']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="contact_no" class="form-label">Contact number <span
                                class="text-muted">(optional)</span></label>
                        <input type="text" id="contact_no" name="contact_no" class="form-control"
                            value="<?= e($form['contact_no']) ?>">
                    </div>
                    <div class="col-12">
                        <label for="subject" class="form-label">Subject</label>
                        <input type="text" id="subject" name="subject" class="form-control" maxlength="200"
                            value="<?= e($form['subject']) ?>" placeholder="e.g. Cannot sign in / wrong record"
                            required>
                    </div>
                    <div class="col-12">
                        <label for="message" class="form-label">How can we help?</label>
                        <textarea id="message" name="message" class="form-control" rows="5"
                            required><?= e($form['message']) ?></textarea>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100 mt-4">Send message</button>
            </form>

            <p class="login-foot"><a href="<?= e($backLink) ?>">Back to
                    <?= $signedIn ? 'the dashboard' : 'sign in' ?></a></p>
        </section>
    </div>

    <footer class="login-copyright">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></footer>

    <script src="assets/js/app.js"></script>
</body>

</html>