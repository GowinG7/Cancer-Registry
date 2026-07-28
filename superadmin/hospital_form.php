<?php
require_once __DIR__ . '/includes/auth.php';
sa_require_login();

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];
$hospital = [
    'hospital_name' => '', 'hospital_code' => '', 'username' => '', 'email' => '',
    'contact_no' => '', 'address' => '', 'logo' => '', 'is_active' => 1,
];

if ($isEdit) {
    $stmt = $conn->prepare('SELECT * FROM hospital_accounts WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$found) {
        sa_flash('Hospital account not found.', 'error');
        header('Location: hospitals.php');
        exit;
    }
    $hospital = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sa_verify_csrf();
    $hospital['hospital_name'] = trim($_POST['hospital_name'] ?? '');
    $hospital['hospital_code'] = trim($_POST['hospital_code'] ?? '');
    $hospital['username'] = trim($_POST['username'] ?? '');
    $hospital['email'] = trim($_POST['email'] ?? '');
    $hospital['contact_no'] = trim($_POST['contact_no'] ?? '');
    $hospital['address'] = trim($_POST['address'] ?? '');
    $hospital['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';

    if ($hospital['hospital_name'] === '') {
        $errors[] = 'Hospital name is required.';
    }
    if ($hospital['hospital_code'] === '') {
        $errors[] = 'Hospital code is required.';
    }
    if ($hospital['username'] === '') {
        $errors[] = 'Username is required.';
    }
    if (!filter_var($hospital['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (!$isEdit && strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($isEdit && $password !== '' && strlen($password) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }

    $dupSql = 'SELECT id FROM hospital_accounts WHERE (hospital_code = ? OR username = ? OR email = ?)';
    if ($isEdit) {
        $dupSql .= ' AND id <> ?';
    }
    $stmt = $conn->prepare($dupSql);
    if ($isEdit) {
        $stmt->bind_param('sssi', $hospital['hospital_code'], $hospital['username'], $hospital['email'], $id);
    } else {
        $stmt->bind_param('sss', $hospital['hospital_code'], $hospital['username'], $hospital['email']);
    }
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = 'Another hospital already uses this code, username or email.';
    }
    $stmt->close();

    // Optional logo upload.
    $logoName = $hospital['logo'];
    if (!empty($_FILES['logo']['name'])) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Logo must be a JPG, PNG, GIF or WEBP image.';
        } elseif ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Logo upload failed.';
        } else {
            if (!is_dir(SA_UPLOAD_DIR)) {
                mkdir(SA_UPLOAD_DIR, 0775, true);
            }
            $logoName = uniqid('logo_', false) . '.' . $ext;
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], SA_UPLOAD_DIR . '/' . $logoName)) {
                $errors[] = 'Could not save the uploaded logo.';
                $logoName = $hospital['logo'];
            }
        }
    }

    if (!$errors) {
        if ($isEdit) {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare(
                    'UPDATE hospital_accounts SET hospital_name=?, hospital_code=?, username=?, email=?,
                     contact_no=?, address=?, logo=?, is_active=?, password=?,
                     deleted_at = IF(? = 0, COALESCE(deleted_at, NOW()), NULL) WHERE id=?'
                );
                $stmt->bind_param('sssssssisii', $hospital['hospital_name'], $hospital['hospital_code'],
                    $hospital['username'], $hospital['email'], $hospital['contact_no'], $hospital['address'],
                    $logoName, $hospital['is_active'], $hash, $hospital['is_active'], $id);
            } else {
                $stmt = $conn->prepare(
                    'UPDATE hospital_accounts SET hospital_name=?, hospital_code=?, username=?, email=?,
                     contact_no=?, address=?, logo=?, is_active=?,
                     deleted_at = IF(? = 0, COALESCE(deleted_at, NOW()), NULL) WHERE id=?'
                );
                $stmt->bind_param('sssssssiii', $hospital['hospital_name'], $hospital['hospital_code'],
                    $hospital['username'], $hospital['email'], $hospital['contact_no'], $hospital['address'],
                    $logoName, $hospital['is_active'], $hospital['is_active'], $id);
            }
            $stmt->execute();
            $stmt->close();

            // patient_records keeps a denormalised hospital_name; keep it in sync.
            $stmt = $conn->prepare('UPDATE patient_records SET hospital_name = ? WHERE hospital_id = ?');
            $stmt->bind_param('si', $hospital['hospital_name'], $id);
            $stmt->execute();
            $stmt->close();

            sa_log($conn, 'update', 'hospital_account', $id, $hospital['hospital_name']);
            sa_flash('Hospital account updated.');
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare(
                'INSERT INTO hospital_accounts
                 (hospital_name, hospital_code, username, password, email, contact_no, address, logo, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('ssssssssi', $hospital['hospital_name'], $hospital['hospital_code'],
                $hospital['username'], $hash, $hospital['email'], $hospital['contact_no'],
                $hospital['address'], $logoName, $hospital['is_active']);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            sa_log($conn, 'create', 'hospital_account', $newId, $hospital['hospital_name']);
            sa_flash('Hospital account created.');
        }

        header('Location: hospitals.php');
        exit;
    }
}

$pageTitle = $isEdit ? 'Edit Hospital Account' : 'New Hospital Account';
require_once __DIR__ . '/includes/header.php';
?>
<div class="panel">
  <?php if ($errors): ?>
    <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= sa_csrf_field() ?>
    <div class="row">
      <div class="field">
        <label for="hospital_name">Hospital name</label>
        <input type="text" id="hospital_name" name="hospital_name" value="<?= e($hospital['hospital_name']) ?>" required>
      </div>
      <div class="field">
        <label for="hospital_code">Hospital code</label>
        <input type="text" id="hospital_code" name="hospital_code" value="<?= e($hospital['hospital_code']) ?>" required>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <label for="username">Login username</label>
        <input type="text" id="username" name="username" value="<?= e($hospital['username']) ?>" required>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>>
        <span class="hint"><?= $isEdit ? 'Leave blank to keep the current password.' : 'Minimum 8 characters.' ?></span>
      </div>
    </div>
    <div class="row">
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($hospital['email']) ?>" required>
      </div>
      <div class="field">
        <label for="contact_no">Contact number</label>
        <input type="text" id="contact_no" name="contact_no" value="<?= e($hospital['contact_no']) ?>">
      </div>
    </div>
    <div class="field">
      <label for="address">Address</label>
      <input type="text" id="address" name="address" value="<?= e($hospital['address']) ?>">
    </div>
    <div class="field">
      <label for="logo">Logo</label>
      <input type="file" id="logo" name="logo" accept="image/*">
      <?php if (!empty($hospital['logo'])): ?>
        <span class="hint">Current: <?= e($hospital['logo']) ?></span>
      <?php endif; ?>
    </div>
    <div class="field">
      <label><input type="checkbox" name="is_active" value="1" <?= $hospital['is_active'] ? 'checked' : '' ?>> Account is active (can log in)</label>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Create account' ?></button>
      <a class="btn btn-light" href="hospitals.php">Cancel</a>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
