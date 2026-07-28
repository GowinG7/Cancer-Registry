<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

function sa_scalar(mysqli $conn, $sql)
{
    $row = $conn->query($sql)->fetch_row();
    return $row ? $row[0] : 0;
}

$totalHospitals = sa_scalar($conn, 'SELECT COUNT(*) FROM hospital_accounts');
$activeHospitals = sa_scalar($conn, 'SELECT COUNT(*) FROM hospital_accounts WHERE is_active = 1');
$totalPatients = sa_scalar($conn, 'SELECT COUNT(*) FROM patient_records');
$totalDiagnoses = sa_scalar($conn, 'SELECT COUNT(*) FROM patient_diagnosis');
$totalIcd = sa_scalar($conn, 'SELECT COUNT(*) FROM icd_master');

$perHospital = $conn->query(
    'SELECT h.id, h.hospital_name, h.hospital_code, h.is_active,
            COUNT(p.id) AS patient_count,
            MAX(p.created_at) AS last_entry
     FROM hospital_accounts h
     LEFT JOIN patient_records p ON p.hospital_id = h.id
     GROUP BY h.id, h.hospital_name, h.hospital_code, h.is_active
     ORDER BY patient_count DESC, h.hospital_name'
);

$topIcd = $conn->query(
    'SELECT i.icd_code, i.icd_name, i.site_name, COUNT(d.id) AS cases
     FROM icd_master i
     JOIN patient_diagnosis d ON d.icd_master_id = i.id
     GROUP BY i.id, i.icd_code, i.icd_name, i.site_name
     ORDER BY cases DESC
     LIMIT 10'
);
?>
<div class="cards">
  <div class="card"><div class="num"><?= (int) $totalHospitals ?></div><div class="label">Hospital accounts</div></div>
  <div class="card"><div class="num"><?= (int) $activeHospitals ?></div><div class="label">Active hospitals</div></div>
  <div class="card"><div class="num"><?= (int) $totalPatients ?></div><div class="label">Patient records</div></div>
  <div class="card"><div class="num"><?= (int) $totalDiagnoses ?></div><div class="label">Diagnoses</div></div>
  <div class="card"><div class="num"><?= (int) $totalIcd ?></div><div class="label">ICD codes</div></div>
  <a class="card" href="messages.php">
    <div class="num"><?= (int) $saNewMessages ?></div>
    <div class="label">New messages</div>
  </a>
</div>

<div class="panel">
  <h2>Records per hospital</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Hospital</th><th>Code</th><th>Status</th><th>Patients</th><th>Last entry</th><th></th></tr></thead>
      <tbody>
      <?php while ($row = $perHospital->fetch_assoc()): ?>
        <tr>
          <td><?= e($row['hospital_name']) ?></td>
          <td><?= e($row['hospital_code']) ?></td>
          <td><?= $row['is_active']
                ? '<span class="badge badge-ok">Active</span>'
                : '<span class="badge badge-off">Deactivated</span>' ?></td>
          <td><?= (int) $row['patient_count'] ?></td>
          <td><?= e($row['last_entry'] ?? '—') ?></td>
          <td><a class="btn btn-light btn-sm" href="patients.php?hospital_id=<?= (int) $row['id'] ?>">View records</a></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <h2>Most reported ICD sites</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ICD code</th><th>Diagnosis</th><th>Site</th><th>Cases</th></tr></thead>
      <tbody>
      <?php if ($topIcd->num_rows === 0): ?>
        <tr><td colspan="4">No diagnoses recorded yet.</td></tr>
      <?php endif; ?>
      <?php while ($row = $topIcd->fetch_assoc()): ?>
        <tr>
          <td><?= e($row['icd_code']) ?></td>
          <td><?= e($row['icd_name']) ?></td>
          <td><?= e($row['site_name']) ?></td>
          <td><?= (int) $row['cases'] ?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <h2>Quick exports</h2>
  <div class="actions">
    <a class="btn" href="export.php?type=full">Full registry</a>
    <a class="btn btn-light" href="export.php?type=patients">Patients</a>
    <a class="btn btn-light" href="export.php?type=hospitals">Hospitals</a>
    <a class="btn btn-light" href="exports.php">More options</a>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
