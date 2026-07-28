<?php
require_once __DIR__ . '/includes/auth.php';
sa_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sa_verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $code = trim($_POST['icd_code'] ?? '');
    $name = trim($_POST['icd_name'] ?? '');
    $site = trim($_POST['site_name'] ?? '');

    try {
        if ($action === 'create' && $code !== '' && $name !== '') {
            $stmt = $conn->prepare('INSERT INTO icd_master (icd_code, icd_name, site_name) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $code, $name, $site);
            $stmt->execute();
            sa_log($conn, 'create', 'icd_master', $stmt->insert_id, $code . ' ' . $name);
            $stmt->close();
            sa_flash('ICD code added.');
        } elseif ($action === 'update' && $id > 0) {
            $stmt = $conn->prepare('UPDATE icd_master SET icd_code = ?, icd_name = ?, site_name = ? WHERE id = ?');
            $stmt->bind_param('sssi', $code, $name, $site, $id);
            $stmt->execute();
            $stmt->close();
            sa_log($conn, 'update', 'icd_master', $id, $code . ' ' . $name);
            sa_flash('ICD code updated.');
        } elseif ($action === 'delete' && $id > 0) {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM patient_diagnosis WHERE icd_master_id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $used = (int) $stmt->get_result()->fetch_row()[0];
            $stmt->close();

            if ($used > 0) {
                sa_flash("This ICD code is used by $used diagnosis record(s), so it cannot be deleted.", 'error');
            } else {
                $stmt = $conn->prepare('DELETE FROM icd_master WHERE id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                sa_log($conn, 'delete', 'icd_master', $id, 'ICD code removed');
                sa_flash('ICD code deleted.');
            }
        }
    } catch (mysqli_sql_exception $ex) {
        sa_flash('Could not save the ICD code: ' . $ex->getMessage(), 'error');
    }

    header('Location: icd.php');
    exit;
}

$pageTitle = 'ICD Master';
require_once __DIR__ . '/includes/header.php';

$rows = $conn->query(
    'SELECT i.*, COUNT(d.id) AS usage_count
     FROM icd_master i
     LEFT JOIN patient_diagnosis d ON d.icd_master_id = i.id
     GROUP BY i.id ORDER BY i.icd_code'
)->fetch_all(MYSQLI_ASSOC);
?>
<div class="panel">
  <h2>Add ICD code</h2>
  <form method="post">
    <?= sa_csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="row">
      <div class="field">
        <label for="icd_code">ICD code</label>
        <input type="text" id="icd_code" name="icd_code" required placeholder="C50">
      </div>
      <div class="field">
        <label for="icd_name">Diagnosis name</label>
        <input type="text" id="icd_name" name="icd_name" required placeholder="Malignant neoplasm of breast">
      </div>
      <div class="field">
        <label for="site_name">Site</label>
        <input type="text" id="site_name" name="site_name" placeholder="Breast">
      </div>
    </div>
    <button class="btn" type="submit">Add code</button>
  </form>
</div>

<?php // One form per row, declared outside the table so the markup stays valid.
foreach ($rows as $r): ?>
  <form id="icd-<?= (int) $r['id'] ?>" method="post">
    <?= sa_csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
  </form>
<?php endforeach; ?>

<div class="panel">
  <div class="filters">
    <h2 style="margin:0">All ICD codes</h2>
    <span style="flex:1"></span>
    <a class="btn btn-light" href="export.php?type=icd">Export to Excel</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Code</th><th>Name</th><th>Site</th><th>Used by</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $fid = 'icd-' . (int) $r['id']; ?>
        <tr>
          <td><input type="text" form="<?= $fid ?>" name="icd_code" value="<?= e($r['icd_code']) ?>" required></td>
          <td><input type="text" form="<?= $fid ?>" name="icd_name" value="<?= e($r['icd_name']) ?>" required></td>
          <td><input type="text" form="<?= $fid ?>" name="site_name" value="<?= e($r['site_name']) ?>"></td>
          <td><?= (int) $r['usage_count'] ?> record(s)</td>
          <td>
            <div class="actions">
              <button class="btn btn-sm" type="submit" form="<?= $fid ?>" name="action" value="update">Save</button>
              <button class="btn btn-danger btn-sm" type="submit" form="<?= $fid ?>" name="action" value="delete"
                      onclick="return confirm('Delete ICD code <?= e(addslashes($r['icd_code'])) ?>?');"
                      <?= $r['usage_count'] > 0 ? 'disabled title="In use by diagnoses"' : '' ?>>Delete</button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">A code that is already used by a diagnosis cannot be deleted; edit it instead.</p>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
