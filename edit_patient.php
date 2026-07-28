<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/nepal_locations.php';

$hospital = require_hospital_login($conn);
$hospitalId = (int) $hospital['id'];

$patientId = (int) ($_GET['id'] ?? $_POST['patient_id'] ?? 0);
$record = find_patient($conn, $patientId, $hospitalId);
if (!$record) {
    flash('error', 'That patient record was not found in your hospital.');
    redirect('dashboard.php');
}

$icdCodes = all_icd_codes($conn);
$icdIds = array_map('intval', array_column($icdCodes, 'id'));
$existing = patient_diagnoses($conn, $patientId);

// Pre-fill the form with the stored record.
$patient = [
    'patient_name' => (string) $record['patient_name'],
    'age' => $record['age'] === null ? '' : (string) $record['age'],
    'sex' => (string) $record['sex'],
    'id_type' => (string) $record['id_type'],
    'id_no' => (string) $record['id_no'],
    'province' => (string) $record['province'],
    'district' => (string) $record['district'],
    'address' => (string) $record['address'],
    'contact_no' => (string) $record['contact_no'],
];
$diagnosisBlocks = [];
foreach (['primary' => 'Primary', 'secondary' => 'Secondary'] as $prefix => $type) {
    $row = $existing[$type] ?? null;
    $diagnosisBlocks[$prefix] = [
        'enabled' => $row !== null,
        'icd_master_id' => $row ? (string) $row['icd_master_id'] : '',
        'remarks' => (string) ($row['remarks'] ?? ''),
        'prepared_by' => (string) ($row['prepared_by'] ?? ''),
        'prepared_email' => (string) ($row['prepared_email'] ?? ''),
        'prepared_contact' => (string) ($row['prepared_contact'] ?? ''),
    ];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$patient, $patientErrors] = validate_patient_input($_POST, $nepalProvinces, [
        'province' => (string) $record['province'],
        'district' => (string) $record['district'],
    ]);
    [$diagnosisBlocks, $diagnosisErrors] = validate_diagnosis_input($_POST, $icdIds);
    $errors = array_merge($patientErrors, $diagnosisErrors);

    if (!verify_csrf()) {
        $errors = ['Your session expired. Please submit the form again.'];
    }

    if (!$errors) {
        $age = $patient['age'] === '' ? null : (int) $patient['age'];
        $blank = static fn(string $value) => $value === '' ? null : $value;
        $idType = $blank($patient['id_type']);
        $idNo = $blank($patient['id_no']);
        $province = $blank($patient['province']);
        $district = $blank($patient['district']);
        $address = $blank($patient['address']);
        $contactNo = $blank($patient['contact_no']);

        $conn->begin_transaction();
        try {
            $touch = has_column($conn, 'patient_records', 'updated_at') ? ', updated_at = NOW()' : '';
            $stmt = $conn->prepare('UPDATE patient_records
                SET patient_name = ?, age = ?, sex = ?, id_type = ?, id_no = ?,
                    province = ?, district = ?, address = ?, contact_no = ?' . $touch . '
                WHERE id = ? AND hospital_id = ?');
            $stmt->bind_param(
                'sisssssssii',
                $patient['patient_name'],
                $age,
                $patient['sex'],
                $idType,
                $idNo,
                $province,
                $district,
                $address,
                $contactNo,
                $patientId,
                $hospitalId
            );
            $stmt->execute();
            $stmt->close();

            save_diagnosis($conn, $patientId, 'Primary', $diagnosisBlocks['primary'], $existing['Primary'] ?? null);
            save_diagnosis($conn, $patientId, 'Secondary', $diagnosisBlocks['secondary'], $existing['Secondary'] ?? null);

            $conn->commit();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Updating patient failed: ' . $exception->getMessage());
            $errors[] = 'The changes could not be saved. Please try again.';
        }

        if (!$errors) {
            flash('success', 'Patient "' . $patient['patient_name'] . '" was updated.');
            redirect('view_patient.php?id=' . $patientId);
        }
    }
}

$submitLabel = 'Save changes';
$cancelUrl = 'view_patient.php?id=' . $patientId;
$pageTitle = 'Edit Patient';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
    <div>
        <h1 class="h3 mb-1">Edit Patient Record</h1>
        <p class="text-muted mb-0">Record #<?= (int) $patientId ?> &middot; created
            <?= e(date('d M Y, H:i', strtotime($record['created_at']))) ?>
        </p>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary">Back to records</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger alert-persistent">
        <p class="fw-semibold mb-1">Please correct the following:</p>
        <ul class="mb-0">
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php
echo '<script>const nepalProvinces = ' . json_encode($nepalProvinces, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script>';
include __DIR__ . '/includes/patient_form.php';
include __DIR__ . '/includes/footer.php';
