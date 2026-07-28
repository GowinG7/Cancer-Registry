<?php
require_once __DIR__ . '/config.php';

$hospital = require_hospital_login($conn);
$hospitalId = (int) $hospital['id'];

$details = [
    'email' => (string) $hospital['email'],
    'contact_no' => (string) $hospital['contact_no'],
    'address' => (string) $hospital['address'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'details') {
        $details = [
            'email' => trim($_POST['email'] ?? ''),
            'contact_no' => trim($_POST['contact_no'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
        ];

        if ($details['email'] === '' || !filter_var($details['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($details['contact_no'] !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $details['contact_no'])) {
            $errors[] = 'Enter a valid contact number.';
        }
        if (mb_strlen($details['address']) > 255) {
            $errors[] = 'Address must be 255 characters or fewer.';
        }

        if (!$errors) {
            $stmt = $conn->prepare('SELECT id FROM hospital_accounts WHERE email = ? AND id <> ?');
            $stmt->bind_param('si', $details['email'], $hospitalId);
            $stmt->execute();
            $taken = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($taken) {
                $errors[] = 'Another hospital account already uses that email address.';
            } else {
                $stmt = $conn->prepare('UPDATE hospital_accounts SET email = ?, contact_no = ?, address = ? WHERE id = ?');
                $stmt->bind_param('sssi', $details['email'], $details['contact_no'], $details['address'], $hospitalId);
                $stmt->execute();
                $stmt->close();
                flash('success', 'Hospital details updated.');
                redirect('profile.php');
            }
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $stmt = $conn->prepare('SELECT password FROM hospital_accounts WHERE id = ?');
        $stmt->bind_param('i', $hospitalId);
        $stmt->execute();
        $stored = $stmt->get_result()->fetch_assoc()['password'] ?? '';
        $stmt->close();

        if (!password_verify($current, $stored)) {
            $errors[] = 'Your current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'The new password must be at least 8 characters long.';
        } elseif ($new !== $confirm) {
            $errors[] = 'The new password and its confirmation do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE hospital_accounts SET password = ? WHERE id = ?');
            $stmt->bind_param('si', $hash, $hospitalId);
            $stmt->execute();
            $stmt->close();
            flash('success', 'Password changed successfully.');
            redirect('profile.php');
        }
    }
}

$counts = run_query($conn,
    'SELECT COUNT(*) AS patients,
            (SELECT COUNT(*) FROM patient_diagnosis d JOIN patient_records p2 ON p2.id = d.patient_id
             WHERE p2.hospital_id = ?) AS diagnoses,
            MAX(created_at) AS last_entry
     FROM patient_records WHERE hospital_id = ?',
    'ii', [$hospitalId, $hospitalId])->fetch_assoc();

$pageTitle = 'Hospital Profile';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="h3 mb-1">Hospital Profile</h1>
    <p class="text-muted mb-0"><?= e($hospital['hospital_name']) ?> &middot; Code <?= e($hospital['hospital_code']) ?></p>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger alert-persistent">
        <ul class="mb-0">
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header">Account details</div>
            <div class="card-body">
                <dl class="detail-grid mb-4">
                    <div><dt>Hospital</dt><dd><?= e($hospital['hospital_name']) ?></dd></div>
                    <div><dt>Hospital code</dt><dd><?= e($hospital['hospital_code']) ?></dd></div>
                    <div><dt>Username</dt><dd><?= e($hospital['username']) ?></dd></div>
                </dl>
                <p class="text-muted small">The hospital name, code and username can only be changed by the
                    registry administrator in the superadmin panel.</p>

                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="details">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email <span class="req">*</span></label>
                            <input id="email" name="email" type="email" class="form-control"
                                value="<?= e($details['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="contact_no" class="form-label">Contact no.</label>
                            <input id="contact_no" name="contact_no" type="text" class="form-control"
                                value="<?= e($details['contact_no']) ?>" maxlength="20">
                        </div>
                        <div class="col-12">
                            <label for="address" class="form-label">Address</label>
                            <input id="address" name="address" type="text" class="form-control"
                                value="<?= e($details['address']) ?>" maxlength="255">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">Save details</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Change password</div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="current_password" class="form-label">Current password</label>
                            <input id="current_password" name="current_password" type="password" class="form-control"
                                autocomplete="current-password" required>
                        </div>
                        <div class="col-md-4">
                            <label for="new_password" class="form-label">New password</label>
                            <input id="new_password" name="new_password" type="password" class="form-control"
                                autocomplete="new-password" minlength="8" required>
                        </div>
                        <div class="col-md-4">
                            <label for="confirm_password" class="form-label">Confirm new password</label>
                            <input id="confirm_password" name="confirm_password" type="password" class="form-control"
                                autocomplete="new-password" minlength="8" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">Change password</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Registry activity</div>
            <div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Patients registered</dt><dd><?= (int) $counts['patients'] ?></dd></div>
                    <div><dt>Diagnoses recorded</dt><dd><?= (int) $counts['diagnoses'] ?></dd></div>
                    <div>
                        <dt>Last entry</dt>
                        <dd><?= $counts['last_entry'] ? e(date('d M Y, H:i', strtotime($counts['last_entry']))) : '-' ?></dd>
                    </div>
                </dl>
                <a href="export.php" class="btn btn-outline-primary w-100 mt-2">Export my hospital data to Excel</a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
