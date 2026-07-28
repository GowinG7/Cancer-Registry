<?php
require_once __DIR__ . '/includes/auth.php';
sa_require_login();

$adminId = (int) $_SESSION['superadmin_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sa_verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_no'] ?? '');

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }

        if (!$errors) {
            $stmt = $conn->prepare('UPDATE super_admins SET full_name = ?, email = ?, contact_no = ? WHERE id = ?');
            $stmt->bind_param('sssi', $fullName, $email, $contact, $adminId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['superadmin_name'] = $fullName;
            sa_log($conn, 'update', 'super_admin', $adminId, 'Profile updated');
            sa_flash('Profile updated.');
            header('Location: profile.php');
            exit;
        }
    } elseif ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $conn->prepare('SELECT password FROM super_admins WHERE id = ?');
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $hash = $stmt->get_result()->fetch_assoc()['password'];
        $stmt->close();

        if (!password_verify($current, $hash)) {
            $errors[] = 'Current password is incorrect.';
        }
        if (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($new !== $confirm) {
            $errors[] = 'New password and confirmation do not match.';
        }

        if (!$errors) {
            $newHash = password_hash($new, PASSWORD_BCRYPT);
            $stmt = $conn->prepare('UPDATE super_admins SET password = ? WHERE id = ?');
            $stmt->bind_param('si', $newHash, $adminId);
            $stmt->execute();
            $stmt->close();
            sa_log($conn, 'password_change', 'super_admin', $adminId, 'Password changed');
            sa_flash('Password changed.');
            header('Location: profile.php');
            exit;
        }
    } elseif ($action === 'logo') {
        // The registry logo shown on the login pages and page headers lives at
        // assets/images/logo.png. Overwriting it keeps every reference working.
        $logoPath = __DIR__ . '/../assets/images/logo.png';

        if (empty($_FILES['logo']['name'])) {
            $errors[] = 'Choose an image to upload.';
        } elseif ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Logo upload failed.';
        } else {
            $info = getimagesize($_FILES['logo']['tmp_name']);
            $allowed = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
            if ($info === false || !in_array($info[2], $allowed, true)) {
                $errors[] = 'Please upload a valid PNG, JPG, GIF or WEBP image.';
            } elseif (!move_uploaded_file($_FILES['logo']['tmp_name'], $logoPath)) {
                $errors[] = 'Could not save the logo. Check the assets/images folder is writable.';
            } else {
                sa_log($conn, 'update', 'settings', null, 'Registry logo updated');
                sa_flash('Registry logo updated.');
                header('Location: profile.php');
                exit;
            }
        }
    } elseif ($action === 'add_admin') {
        $fullName = trim($_POST['new_full_name'] ?? '');
        $username = trim($_POST['new_username'] ?? '');
        $email = trim($_POST['new_email'] ?? '');
        $password = $_POST['new_admin_password'] ?? '';

        if ($fullName === '' || $username === '') {
            $errors[] = 'Name and username are required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required for the new superadmin.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if (!$errors) {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare(
                    'INSERT INTO super_admins (full_name, username, password, email) VALUES (?, ?, ?, ?)'
                );
                $stmt->bind_param('ssss', $fullName, $username, $hash, $email);
                $stmt->execute();
                sa_log($conn, 'create', 'super_admin', $stmt->insert_id, $username);
                $stmt->close();
                sa_flash('Superadmin account created.');
                header('Location: profile.php');
                exit;
            } catch (mysqli_sql_exception $ex) {
                $errors[] = 'That username or email is already taken.';
            }
        }
    }
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/header.php';

$stmt = $conn->prepare('SELECT * FROM super_admins WHERE id = ?');
$stmt->bind_param('i', $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

$admins = $conn->query('SELECT id, full_name, username, email, is_active, last_login_at FROM super_admins ORDER BY username');
?>
<?php if ($errors): ?>
  <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<div class="panel">
  <h2>Account details</h2>
  <form method="post">
    <?= sa_csrf_field() ?>
    <input type="hidden" name="action" value="profile">
    <div class="row">
      <div class="field">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" value="<?= e($me['full_name']) ?>" required>
      </div>
      <div class="field">
        <label>Username</label>
        <input type="text" value="<?= e($me['username']) ?>" disabled>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($me['email']) ?>" required>
      </div>
      <div class="field">
        <label for="contact_no">Contact number</label>
        <input type="text" id="contact_no" name="contact_no" value="<?= e($me['contact_no']) ?>">
      </div>
    </div>
    <button class="btn" type="submit">Save profile</button>
  </form>
</div>

<div class="panel">
  <h2>Change password</h2>
  <form method="post">
    <?= sa_csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <div class="row">
      <div class="field">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required>
      </div>
      <div class="field">
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" required>
      </div>
      <div class="field">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required>
      </div>
    </div>
    <button class="btn" type="submit">Change password</button>
  </form>
</div>

<div class="panel">
  <h2>Registry logo</h2>
  <p class="hint">Shown on the login pages and in the page headers. PNG, JPG, GIF or WEBP; a square image works best.</p>
  <div class="logo-upload">
    <img src="<?= e(logo_url('../')) ?>" alt="Current registry logo" class="logo-preview">
    <form method="post" enctype="multipart/form-data">
      <?= sa_csrf_field() ?>
      <input type="hidden" name="action" value="logo">
      <div class="field">
        <label for="logo">Upload a new logo</label>
        <input type="file" id="logo" name="logo" accept="image/*" required>
      </div>
      <button class="btn" type="submit">Update logo</button>
    </form>
  </div>
</div>

<div class="panel">
  <h2>Superadmin accounts</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Status</th><th>Last login</th></tr></thead>
      <tbody>
      <?php while ($a = $admins->fetch_assoc()): ?>
        <tr>
          <td><?= e($a['full_name']) ?></td>
          <td><?= e($a['username']) ?></td>
          <td><?= e($a['email']) ?></td>
          <td><?= $a['is_active'] ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-off">Disabled</span>' ?></td>
          <td><?= e($a['last_login_at'] ?? 'never') ?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <h2 style="margin-top:18px">Add another superadmin</h2>
  <form method="post">
    <?= sa_csrf_field() ?>
    <input type="hidden" name="action" value="add_admin">
    <div class="row">
      <div class="field">
        <label for="new_full_name">Full name</label>
        <input type="text" id="new_full_name" name="new_full_name" required>
      </div>
      <div class="field">
        <label for="new_username">Username</label>
        <input type="text" id="new_username" name="new_username" required>
      </div>
      <div class="field">
        <label for="new_email">Email</label>
        <input type="email" id="new_email" name="new_email" required>
      </div>
      <div class="field">
        <label for="new_admin_password">Password</label>
        <input type="password" id="new_admin_password" name="new_admin_password" required>
      </div>
    </div>
    <button class="btn" type="submit">Create superadmin</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
