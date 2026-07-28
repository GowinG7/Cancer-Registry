<?php
/**
 * Patient + diagnosis form shared by add_patient_diagnosis.php and edit_patient.php.
 *
 * Expects: $patient (field values), $diagnosisBlocks (primary/secondary blocks),
 * $icdCodes, $nepalProvinces, $submitLabel, $cancelUrl.
 */

$idTypeOptions = ID_TYPES;
if ($patient['id_type'] !== '' && !in_array($patient['id_type'], $idTypeOptions, true)) {
    $idTypeOptions[] = $patient['id_type']; // keep a legacy value saved earlier
}

// A province typed in before the dropdowns existed stays selectable so editing
// another field never wipes it.
$provinceNames = array_keys($nepalProvinces);
$legacyProvince = $patient['province'] !== '' && !isset($nepalProvinces[$patient['province']])
    ? $patient['province']
    : '';
$legacyDistrict = $legacyProvince !== '' ? $patient['district'] : '';
?>
<form method="post" novalidate class="patient-form">
    <?= csrf_field() ?>

    <div class="card mb-4">
        <div class="card-header">Patient Information</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label for="patient_name" class="form-label">Patient name <span class="req">*</span></label>
                    <input id="patient_name" name="patient_name" type="text" class="form-control" maxlength="150"
                        value="<?= e($patient['patient_name']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label for="age" class="form-label">Age</label>
                    <input id="age" name="age" type="number" class="form-control" min="0" max="130" step="1"
                        value="<?= e($patient['age']) ?>">
                </div>
                <div class="col-md-2">
                    <label for="sex" class="form-label">Sex <span class="req">*</span></label>
                    <select id="sex" name="sex" class="form-select" required>
                        <option value="">-- Select --</option>
                        <?php foreach (SEX_OPTIONS as $option): ?>
                            <option value="<?= e($option) ?>" <?= $patient['sex'] === $option ? 'selected' : '' ?>>
                                <?= e($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="contact_no" class="form-label">Contact no.</label>
                    <input id="contact_no" name="contact_no" type="text" class="form-control" maxlength="20"
                        value="<?= e($patient['contact_no']) ?>" pattern="[0-9+()\-\s]{7,20}"
                        title="7 to 20 digits or phone symbols.">
                </div>

                <div class="col-md-4">
                    <label for="id_type" class="form-label">ID type</label>
                    <select id="id_type" name="id_type" class="form-select">
                        <option value="">-- Select --</option>
                        <?php foreach ($idTypeOptions as $option): ?>
                            <option value="<?= e($option) ?>" <?= $patient['id_type'] === $option ? 'selected' : '' ?>>
                                <?= e($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label for="id_no" class="form-label">ID number</label>
                    <input id="id_no" name="id_no" type="text" class="form-control" maxlength="100"
                        value="<?= e($patient['id_no']) ?>">
                </div>

                <div class="col-md-4">
                    <label for="province" class="form-label">Province</label>
                    <select id="province" name="province" class="form-select"
                        data-selected-district="<?= e($patient['district']) ?>"
                        data-legacy-district="<?= e($legacyDistrict) ?>">
                        <option value="">-- Select Province --</option>
                        <?php if ($legacyProvince !== ''): ?>
                            <option value="<?= e($legacyProvince) ?>" selected>
                                <?= e($legacyProvince) ?> (currently saved)
                            </option>
                        <?php endif; ?>
                        <?php foreach ($provinceNames as $provinceName): ?>
                            <option value="<?= e($provinceName) ?>" <?= $patient['province'] === $provinceName ? 'selected' : '' ?>>
                                <?= e($provinceName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="district" class="form-label">District</label>
                    <select id="district" name="district" class="form-select">
                        <option value="">-- Select Province First --</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="address" class="form-label">Address</label>
                    <input id="address" name="address" type="text" class="form-control" maxlength="255"
                        value="<?= e($patient['address']) ?>">
                </div>
            </div>
        </div>
    </div>

    <?php foreach (['primary' => 'Primary', 'secondary' => 'Secondary'] as $prefix => $label): ?>
        <?php $block = $diagnosisBlocks[$prefix]; ?>
        <div class="card mb-4 diagnosis-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><?= e($label) ?> Diagnosis</span>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input js-diagnosis-toggle" type="checkbox"
                        id="enable_<?= e($prefix) ?>" data-target="<?= e($prefix) ?>Fields"
                        name="<?= e($prefix) ?>_enabled" value="1" <?= $block['enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="enable_<?= e($prefix) ?>">Record this diagnosis</label>
                </div>
            </div>
            <fieldset id="<?= e($prefix) ?>Fields" class="card-body diagnosis-section" <?= $block['enabled'] ? '' : 'disabled' ?>>
                <div class="row g-3">
                    <div class="col-md-5">
                        <label for="<?= e($prefix) ?>_icd" class="form-label">ICD code</label>
                        <select id="<?= e($prefix) ?>_icd" name="<?= e($prefix) ?>_icd_master_id"
                            class="form-select js-icd-select" data-site-target="<?= e($prefix) ?>_site_name">
                            <option value="">-- Select ICD Code --</option>
                            <?php foreach ($icdCodes as $icd): ?>
                                <option value="<?= (int) $icd['id'] ?>" data-site="<?= e($icd['site_name']) ?>"
                                    <?= (string) $block['icd_master_id'] === (string) $icd['id'] ? 'selected' : '' ?>>
                                    <?= e($icd['icd_code'] . ' - ' . $icd['icd_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="<?= e($prefix) ?>_site_name" class="form-label">Site name</label>
                        <input id="<?= e($prefix) ?>_site_name" class="form-control" readonly tabindex="-1">
                    </div>
                    <div class="col-md-4">
                        <label for="<?= e($prefix) ?>_remarks" class="form-label">Remarks</label>
                        <input id="<?= e($prefix) ?>_remarks" name="<?= e($prefix) ?>_remarks" type="text"
                            class="form-control" value="<?= e($block['remarks']) ?>">
                    </div>

                    <div class="col-12">
                        <p class="section-divider">Form filled by</p>
                    </div>
                    <div class="col-md-4">
                        <label for="<?= e($prefix) ?>_prepared_by" class="form-label">Prepared by</label>
                        <input id="<?= e($prefix) ?>_prepared_by" name="<?= e($prefix) ?>_prepared_by" type="text"
                            class="form-control" maxlength="150" value="<?= e($block['prepared_by']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="<?= e($prefix) ?>_prepared_email" class="form-label">Email</label>
                        <input id="<?= e($prefix) ?>_prepared_email" name="<?= e($prefix) ?>_prepared_email"
                            type="email" class="form-control" maxlength="150"
                            value="<?= e($block['prepared_email']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="<?= e($prefix) ?>_prepared_contact" class="form-label">Contact no.</label>
                        <input id="<?= e($prefix) ?>_prepared_contact" name="<?= e($prefix) ?>_prepared_contact"
                            type="text" class="form-control" maxlength="20"
                            value="<?= e($block['prepared_contact']) ?>">
                    </div>
                </div>
            </fieldset>
        </div>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg"><?= e($submitLabel) ?></button>
        <a href="<?= e($cancelUrl) ?>" class="btn btn-outline-secondary btn-lg">Cancel</a>
    </div>
</form>
