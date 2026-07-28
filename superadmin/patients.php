<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/queries.php';
sa_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sa_verify_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        $pid = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare('SELECT patient_name FROM patient_records WHERE id = ?');
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($patient) {
            // patient_diagnosis and diagnosis_filled_by cascade automatically.
            $stmt = $conn->prepare('DELETE FROM patient_records WHERE id = ?');
            $stmt->bind_param('i', $pid);
            $stmt->execute();
            $stmt->close();
            sa_log($conn, 'delete', 'patient_record', $pid, $patient['patient_name']);
            sa_flash('Patient record deleted along with its diagnoses.');
        } else {
            sa_flash('Patient record not found.', 'error');
        }
    }
    header('Location: patients.php?' . http_build_query($_GET));
    exit;
}

$pageTitle = 'Patient Records';
require_once __DIR__ . '/includes/header.php';

$filters = [
    'hospital_id' => $_GET['hospital_id'] ?? '',
    'q' => trim($_GET['q'] ?? ''),
    'sex' => $_GET['sex'] ?? '',
    'province' => $_GET['province'] ?? '',
    'icd_id' => $_GET['icd_id'] ?? '',
    'from' => $_GET['from'] ?? '',
    'to' => $_GET['to'] ?? '',
];
$built = sa_patient_filters($filters);

$countSql = 'SELECT COUNT(DISTINCT p.id) FROM patient_records p
             JOIN hospital_accounts h ON h.id = p.hospital_id' . $built['sql'];
$total = (int) sa_run($conn, $countSql, $built['types'], $built['params'])->fetch_row()[0];

$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$listSql = sa_patient_select() . $built['sql']
    . ' GROUP BY p.id ORDER BY p.created_at DESC LIMIT ? OFFSET ?';
$listTypes = $built['types'] . 'ii';
$listParams = array_merge($built['params'], [$perPage, $offset]);
$rows = sa_run($conn, $listSql, $listTypes, $listParams);

$hospitals = $conn->query('SELECT id, hospital_name FROM hospital_accounts ORDER BY hospital_name');
$icds = $conn->query('SELECT id, icd_code, icd_name FROM icd_master ORDER BY icd_code');
$provinces = $conn->query('SELECT DISTINCT province FROM patient_records WHERE province <> "" ORDER BY province');
$exportQuery = http_build_query(array_filter($filters, static fn($v) => $v !== '' && $v !== null));
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field">
      <label for="hospital_id">Hospital</label>
      <select id="hospital_id" name="hospital_id">
        <option value="">All hospitals</option>
        <?php while ($h = $hospitals->fetch_assoc()): ?>
          <option value="<?= (int) $h['id'] ?>" <?= (string) $filters['hospital_id'] === (string) $h['id'] ? 'selected' : '' ?>>
            <?= e($h['hospital_name']) ?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="field">
      <label for="q">Search</label>
      <input type="text" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, contact or ID no.">
    </div>
    <div class="field">
      <label for="sex">Sex</label>
      <select id="sex" name="sex">
        <option value="">Any</option>
        <?php foreach (['Male', 'Female', 'Other'] as $sex): ?>
          <option value="<?= $sex ?>" <?= $filters['sex'] === $sex ? 'selected' : '' ?>><?= $sex ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="province">Province</label>
      <select id="province" name="province">
        <option value="">Any</option>
        <?php while ($p = $provinces->fetch_assoc()): ?>
          <option value="<?= e($p['province']) ?>" <?= $filters['province'] === $p['province'] ? 'selected' : '' ?>>
            <?= e(mb_strimwidth($p['province'], 0, 28, '…')) ?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="field">
      <label for="icd_id">ICD code</label>
      <select id="icd_id" name="icd_id">
        <option value="">Any</option>
        <?php while ($i = $icds->fetch_assoc()): ?>
          <option value="<?= (int) $i['id'] ?>" <?= (string) $filters['icd_id'] === (string) $i['id'] ? 'selected' : '' ?>>
            <?= e($i['icd_code'] . ' - ' . mb_strimwidth($i['icd_name'], 0, 30, '…')) ?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="field">
      <label for="from">From</label>
      <input type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
    </div>
    <div class="field">
      <label for="to">To</label>
      <input type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
    </div>
    <button class="btn" type="submit">Apply</button>
    <a class="btn btn-light" href="patients.php">Reset</a>
    <a class="btn btn-light" href="export.php?type=patients&amp;<?= e($exportQuery) ?>">Export these <?= $total ?> record(s)</a>
  </form>

  <p class="hint"><?= $total ?> record(s) found across all hospitals.</p>

  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Hospital</th><th>Patient</th><th>Age</th><th>Sex</th><th>Province</th><th>District</th><th>Contact</th><th>Diagnoses</th><th>Recorded</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($rows->num_rows === 0): ?>
        <tr><td colspan="11">No patient records match these filters.</td></tr>
      <?php endif; ?>
      <?php while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><?= (int) $r['id'] ?></td>
          <td><?= e($r['hospital_name']) ?></td>
          <td><?= e($r['patient_name']) ?></td>
          <td><?= e($r['age']) ?></td>
          <td><?= e($r['sex']) ?></td>
          <td><?= e(mb_strimwidth((string) $r['province'], 0, 24, '…')) ?></td>
          <td><?= e(mb_strimwidth((string) $r['district'], 0, 24, '…')) ?></td>
          <td><?= e($r['contact_no']) ?></td>
          <td><?= e(mb_strimwidth((string) $r['diagnoses'], 0, 46, '…')) ?></td>
          <td><?= e($r['created_at']) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Delete this patient record and all its diagnoses?');">
              <?= sa_csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $pages; $i++):
          $qs = http_build_query(array_merge($filters, ['page' => $i])); ?>
        <?php if ($i === $page): ?>
          <span class="current"><?= $i ?></span>
        <?php else: ?>
          <a href="?<?= e($qs) ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
