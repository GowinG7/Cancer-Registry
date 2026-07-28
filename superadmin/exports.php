<?php
$pageTitle = 'Excel Exports';
require_once __DIR__ . '/includes/header.php';

$hospitals = $conn->query('SELECT id, hospital_name FROM hospital_accounts ORDER BY hospital_name');
?>
<div class="panel">
  <h2>Download everything</h2>
  <p class="hint">One workbook containing Full Registry, Patients, Diagnoses, Hospitals, ICD Master and five analysis sheets.</p>
  <div class="actions">
    <a class="btn" href="export.php?type=all">Export all data (.xlsx)</a>
    <a class="btn btn-light" href="export.php?type=summary">Analysis summary only</a>
  </div>
</div>

<div class="panel">
  <h2>Filtered export</h2>
  <form class="filters" method="get" action="export.php">
    <div class="field">
      <label for="type">Data set</label>
      <select id="type" name="type">
        <option value="full">Full registry (one row per diagnosis)</option>
        <option value="patients">Patient records</option>
        <option value="diagnoses">Diagnoses</option>
        <option value="hospitals">Hospital accounts</option>
        <option value="icd">ICD master</option>
      </select>
    </div>
    <div class="field">
      <label for="hospital_id">Hospital</label>
      <select id="hospital_id" name="hospital_id">
        <option value="">All hospitals</option>
        <?php while ($h = $hospitals->fetch_assoc()): ?>
          <option value="<?= (int) $h['id'] ?>"><?= e($h['hospital_name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="field">
      <label for="sex">Sex</label>
      <select id="sex" name="sex">
        <option value="">Any</option>
        <option value="Male">Male</option>
        <option value="Female">Female</option>
        <option value="Other">Other</option>
      </select>
    </div>
    <div class="field">
      <label for="from">From date</label>
      <input type="date" id="from" name="from">
    </div>
    <div class="field">
      <label for="to">To date</label>
      <input type="date" id="to" name="to">
    </div>
    <div class="field">
      <label for="format">Format</label>
      <select id="format" name="format">
        <option value="xlsx">Excel (.xlsx)</option>
        <option value="csv">CSV</option>
      </select>
    </div>
    <button class="btn" type="submit">Download</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
