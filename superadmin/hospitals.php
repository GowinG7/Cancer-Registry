<?php
require_once __DIR__ . '/includes/auth.php';
sa_require_login();

/**
 * Deleting a hospital used to fail with MySQL error #1451 because
 * patient_records.hospital_id references hospital_accounts without a delete
 * rule. The migration recreates that constraint with ON DELETE CASCADE, so a
 * delete here removes the hospital's patients, their diagnoses and the
 * diagnosis_filled_by rows in one transaction. Because that is destructive,
 * the default action is "deactivate" and a hard delete must be confirmed by
 * typing the hospital code.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sa_verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    $stmt = $conn->prepare('SELECT hospital_name, hospital_code, is_active FROM hospital_accounts WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $hospital = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$hospital) {
        sa_flash('Hospital account not found.', 'error');
        header('Location: hospitals.php');
        exit;
    }

    if ($action === 'toggle') {
        $newState = $hospital['is_active'] ? 0 : 1;
        $stmt = $conn->prepare(
            'UPDATE hospital_accounts SET is_active = ?, deleted_at = IF(? = 0, NOW(), NULL) WHERE id = ?'
        );
        $stmt->bind_param('iii', $newState, $newState, $id);
        $stmt->execute();
        $stmt->close();

        sa_log($conn, $newState ? 'activate' : 'deactivate', 'hospital_account', $id, $hospital['hospital_name']);
        sa_flash($hospital['hospital_name'] . ($newState
            ? ' is active again and can log in.'
            : ' has been deactivated. Its records are kept but it can no longer log in.'));
    } elseif ($action === 'delete') {
        if (trim($_POST['confirm_code'] ?? '') !== $hospital['hospital_code']) {
            sa_flash('Deletion cancelled: the hospital code you typed did not match.', 'error');
            header('Location: hospitals.php');
            exit;
        }

        $stmt = $conn->prepare('SELECT COUNT(*) FROM patient_records WHERE hospital_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $patientCount = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('DELETE FROM hospital_accounts WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            sa_log($conn, 'delete', 'hospital_account', $id,
                sprintf('%s (%s) deleted with %d patient record(s)',
                    $hospital['hospital_name'], $hospital['hospital_code'], $patientCount));
            $conn->commit();

            sa_flash(sprintf('%s and its %d patient record(s) were permanently deleted.',
                $hospital['hospital_name'], $patientCount));
        } catch (mysqli_sql_exception $ex) {
            $conn->rollback();
            sa_flash('Could not delete this hospital: ' . $ex->getMessage(), 'error');
        }
    }

    header('Location: hospitals.php');
    exit;
}

$pageTitle = 'Hospital Accounts';
require_once __DIR__ . '/includes/header.php';

$search = trim($_GET['q'] ?? '');
$sql = 'SELECT h.*, COUNT(p.id) AS patient_count
        FROM hospital_accounts h
        LEFT JOIN patient_records p ON p.hospital_id = h.id';
if ($search !== '') {
    $sql .= ' WHERE h.hospital_name LIKE ? OR h.hospital_code LIKE ? OR h.email LIKE ?';
}
$sql .= ' GROUP BY h.id ORDER BY h.hospital_name';

$stmt = $conn->prepare($sql);
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt->bind_param('sss', $like, $like, $like);
}
$stmt->execute();
$hospitals = $stmt->get_result();
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field">
      <label for="q">Search</label>
      <input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="Name, code or email">
    </div>
    <button class="btn btn-light" type="submit">Filter</button>
    <a class="btn btn-light" href="hospitals.php">Reset</a>
    <span style="flex:1"></span>
    <a class="btn" href="hospital_form.php">+ New hospital account</a>
    <a class="btn btn-light" href="export.php?type=hospitals">Export to Excel</a>
  </form>

  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Hospital</th><th>Code</th><th>Username</th><th>Email</th><th>Contact</th><th>Patients</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php if ($hospitals->num_rows === 0): ?>
        <tr><td colspan="8">No hospital accounts found.</td></tr>
      <?php endif; ?>
      <?php while ($h = $hospitals->fetch_assoc()): ?>
        <tr>
          <td><?= e($h['hospital_name']) ?></td>
          <td><?= e($h['hospital_code']) ?></td>
          <td><?= e($h['username']) ?></td>
          <td><?= e($h['email']) ?></td>
          <td><?= e($h['contact_no']) ?></td>
          <td><a href="patients.php?hospital_id=<?= (int) $h['id'] ?>"><?= (int) $h['patient_count'] ?></a></td>
          <td><?= $h['is_active']
                ? '<span class="badge badge-ok">Active</span>'
                : '<span class="badge badge-off">Deactivated</span>' ?></td>
          <td>
            <div class="actions">
              <a class="btn btn-light btn-sm" href="hospital_form.php?id=<?= (int) $h['id'] ?>">Edit</a>
              <form method="post" onsubmit="return confirm('<?= $h['is_active'] ? 'Deactivate' : 'Activate' ?> <?= e(addslashes($h['hospital_name'])) ?>?');">
                <?= sa_csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                <button class="btn btn-warn btn-sm" type="submit"><?= $h['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
              <button class="btn btn-danger btn-sm" type="button"
                      onclick="document.getElementById('del-<?= (int) $h['id'] ?>').showModal()">Delete</button>
            </div>

            <dialog class="modal" id="del-<?= (int) $h['id'] ?>">
              <form method="post">
                <?= sa_csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                <h3>Delete <?= e($h['hospital_name']) ?>?</h3>
                <p>This permanently removes:</p>
                <ul>
                  <li><strong><?= (int) $h['patient_count'] ?></strong> patient record(s)</li>
                  <li>all diagnoses and "filled by" entries attached to them</li>
                  <li>the hospital login</li>
                </ul>
                <p class="hint">Prefer <strong>Deactivate</strong> if you only want to block the login and keep the data for analysis.</p>
                <div class="field">
                  <label>Type the hospital code <code><?= e($h['hospital_code']) ?></code> to confirm</label>
                  <input type="text" name="confirm_code" required autocomplete="off">
                </div>
                <div class="actions">
                  <button class="btn btn-danger" type="submit">Delete permanently</button>
                  <button class="btn btn-light" type="button"
                          onclick="document.getElementById('del-<?= (int) $h['id'] ?>').close()">Cancel</button>
                </div>
              </form>
            </dialog>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
