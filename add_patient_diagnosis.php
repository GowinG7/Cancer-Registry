<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/nepal_locations.php';

$hospital = require_hospital_login($conn);
$hospitalId = (int) $hospital['id'];

$icdCodes = all_icd_codes($conn);
$icdIds = array_map('intval', array_column($icdCodes, 'id'));

[$patient] = validate_patient_input([], $nepalProvinces);
[$diagnosisBlocks] = validate_diagnosis_input([], $icdIds);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$patient, $patientErrors] = validate_patient_input($_POST, $nepalProvinces);
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
            $stmt = $conn->prepare('INSERT INTO patient_records
                (hospital_id, hospital_name, patient_name, age, sex, id_type, id_no, province, district, address, contact_no)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param(
                'ississsssss',
                $hospitalId,
                $hospital['hospital_name'],
                $patient['patient_name'],
                $age,
                $patient['sex'],
                $idType,
                $idNo,
                $province,
                $district,
                $address,
                $contactNo
            );
            $stmt->execute();
            $patientId = (int) $stmt->insert_id;
            $stmt->close();

            save_diagnosis($conn, $patientId, 'Primary', $diagnosisBlocks['primary'], null);
            save_diagnosis($conn, $patientId, 'Secondary', $diagnosisBlocks['secondary'], null);

            $conn->commit();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Saving patient failed: ' . $exception->getMessage());
            $errors[] = 'The record could not be saved. Please try again.';
        }

        if (!$errors) {
            flash('success', 'Patient "' . $patient['patient_name'] . '" was saved successfully.');
            redirect('dashboard.php');
        }
    }
}

$submitLabel = 'Save Patient & Diagnosis';
$cancelUrl = 'dashboard.php';
$pageTitle = 'Add Patient';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="h3 mb-1">Add Patient &amp; Diagnosis</h1>
    <p class="text-muted mb-0">Recording for <?= e($hospital['hospital_name']) ?>. Fields marked
        <span class="req">*</span> are required.
    </p>
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
