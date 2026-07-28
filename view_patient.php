<?php
require_once __DIR__ . '/config.php';

$hospital = require_hospital_login($conn);
$hospitalId = (int) $hospital['id'];

$patientId = (int) ($_GET['id'] ?? 0);
$record = find_patient($conn, $patientId, $hospitalId);
if (!$record) {
    flash('error', 'That patient record was not found in your hospital.');
    redirect('dashboard.php');
}

$diagnoses = patient_diagnoses($conn, $patientId);

$pageTitle = 'Patient Details';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
        <h1 class="h3 mb-1"><?= e($record['patient_name']) ?></h1>
        <p class="text-muted mb-0">Record #<?= (int) $record['id'] ?> &middot; registered
            <?= e(date('d M Y, H:i', strtotime($record['created_at']))) ?>
            <?php if (!empty($record['updated_at'])): ?>
                &middot; last edited <?= e(date('d M Y, H:i', strtotime($record['updated_at']))) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="dashboard.php" class="btn btn-outline-secondary">Back</a>
        <a href="edit_patient.php?id=<?= (int) $record['id'] ?>" class="btn btn-primary">Edit record</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Patient Information</div>
    <div class="card-body">
        <dl class="detail-grid">
            <div><dt>Patient name</dt><dd><?= e($record['patient_name']) ?></dd></div>
            <div><dt>Age</dt><dd><?= $record['age'] === null ? '-' : (int) $record['age'] ?></dd></div>
            <div><dt>Sex</dt><dd><?= e($record['sex'] ?: '-') ?></dd></div>
            <div><dt>ID type</dt><dd><?= e($record['id_type'] ?: '-') ?></dd></div>
            <div><dt>ID number</dt><dd><?= e($record['id_no'] ?: '-') ?></dd></div>
            <div><dt>Contact no.</dt><dd><?= e($record['contact_no'] ?: '-') ?></dd></div>
            <div><dt>Province</dt><dd><?= e($record['province'] ?: '-') ?></dd></div>
            <div><dt>District</dt><dd><?= e($record['district'] ?: '-') ?></dd></div>
            <div><dt>Address</dt><dd><?= e($record['address'] ?: '-') ?></dd></div>
        </dl>
    </div>
</div>

<?php foreach (['Primary', 'Secondary'] as $type): ?>
    <?php $diagnosis = $diagnoses[$type] ?? null; ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><?= e($type) ?> Diagnosis</span>
            <?php if ($diagnosis): ?>
                <span class="badge <?= $type === 'Primary' ? 'text-bg-success' : 'text-bg-warning' ?>">
                    <?= e($diagnosis['icd_code']) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!$diagnosis): ?>
                <p class="text-muted mb-0">Not recorded for this patient.</p>
            <?php else: ?>
                <dl class="detail-grid">
                    <div><dt>ICD code</dt><dd><?= e($diagnosis['icd_code']) ?></dd></div>
                    <div><dt>Diagnosis</dt><dd><?= e($diagnosis['icd_name']) ?></dd></div>
                    <div><dt>Site</dt><dd><?= e($diagnosis['site_name']) ?></dd></div>
                    <div><dt>Remarks</dt><dd><?= e($diagnosis['remarks'] ?: '-') ?></dd></div>
                    <div><dt>Prepared by</dt><dd><?= e($diagnosis['prepared_by'] ?: '-') ?></dd></div>
                    <div><dt>Email</dt><dd><?= e($diagnosis['prepared_email'] ?: '-') ?></dd></div>
                    <div><dt>Contact</dt><dd><?= e($diagnosis['prepared_contact'] ?: '-') ?></dd></div>
                    <div><dt>Recorded</dt>
                        <dd><?= e(date('d M Y, H:i', strtotime($diagnosis['created_at']))) ?></dd>
                    </div>
                </dl>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
