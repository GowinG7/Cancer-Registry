<?php
/**
 * Shared helpers: escaping, CSRF, flash messages, session guard and the
 * patient queries used by the dashboard, the export and the patient forms.
 */

const APP_NAME = 'Nepal Cancer Registry Programme';

const ID_TYPES = ['Citizenship', 'Birth Certificate', 'National ID', 'Passport', 'Other'];
/** Values saved by older versions of the form; still accepted when editing. */
const LEGACY_ID_TYPES = ['Aadhar'];
const SEX_OPTIONS = ['Male', 'Female', 'Other'];

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * URL of the registry logo with a cache-busting version so a logo the
 * superadmin uploads shows up immediately. $base prefixes the path for pages
 * in a subfolder (e.g. '../' for the superadmin panel).
 */
function logo_url(string $base = ''): string
{
    $version = @filemtime(__DIR__ . '/../assets/images/logo.png') ?: 1;
    return $base . 'assets/images/logo.png?v=' . $version;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);
}

/** Store a one-time message shown on the next page load. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][$type][] = $message;
}

/** @return array<string, string[]> */
function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Whether a column exists, so the app still runs on a database where
 * superadmin/sql/upgrade.sql has not been imported yet.
 */
function has_column(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (!isset($cache[$key])) {
        $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $cache[$key] = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
    }

    return $cache[$key];
}

function has_table(mysqli $conn, string $table): bool
{
    static $cache = [];

    if (!isset($cache[$table])) {
        $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLES
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $cache[$table] = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
    }

    return $cache[$table];
}

/** True while superadmin/sql/upgrade.sql still has to be imported. */
function upgrade_pending(mysqli $conn): bool
{
    return !has_column($conn, 'patient_records', 'updated_at')
        || !has_column($conn, 'hospital_accounts', 'is_active')
        || !has_table($conn, 'support_messages');
}

/** Redirect to the login page unless a hospital session is active. */
function require_hospital_login(mysqli $conn): array
{
    $hospitalId = $_SESSION['hospital_id'] ?? null;
    if ($hospitalId === null) {
        redirect('index.php');
    }

    $activeColumn = has_column($conn, 'hospital_accounts', 'is_active') ? 'is_active' : '1 AS is_active';
    $stmt = $conn->prepare('SELECT id, hospital_name, hospital_code, username, email, contact_no, address, logo, '
        . $activeColumn . '
                            FROM hospital_accounts WHERE id = ?');
    $stmt->bind_param('i', $hospitalId);
    $stmt->execute();
    $hospital = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$hospital || (int) $hospital['is_active'] !== 1) {
        session_unset();
        session_destroy();
        redirect('index.php?disabled=1');
    }

    return $hospital;
}

/** Patient record belonging to the logged-in hospital, or null. */
function find_patient(mysqli $conn, int $patientId, int $hospitalId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM patient_records WHERE id = ? AND hospital_id = ?');
    $stmt->bind_param('ii', $patientId, $hospitalId);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $patient ?: null;
}

/**
 * Diagnoses of a patient keyed by type ("Primary"/"Secondary"), each including
 * the ICD details and the person who prepared the form.
 *
 * @return array<string, array>
 */
function patient_diagnoses(mysqli $conn, int $patientId): array
{
    $stmt = $conn->prepare('SELECT d.id, d.icd_master_id, d.diagnosis_type, d.remarks, d.created_at,
                                   i.icd_code, i.icd_name, i.site_name,
                                   f.id AS filled_id, f.prepared_by, f.prepared_email, f.prepared_contact
                            FROM patient_diagnosis d
                            JOIN icd_master i ON i.id = d.icd_master_id
                            LEFT JOIN diagnosis_filled_by f ON f.diagnosis_id = d.id
                            WHERE d.patient_id = ?
                            ORDER BY d.diagnosis_type, d.id');
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $result = $stmt->get_result();

    $diagnoses = [];
    while ($row = $result->fetch_assoc()) {
        $diagnoses[$row['diagnosis_type']] = $row;
    }
    $stmt->close();

    return $diagnoses;
}

/** @return array<int, array> All ICD codes for the diagnosis dropdowns. */
function all_icd_codes(mysqli $conn): array
{
    return $conn->query('SELECT id, icd_code, icd_name, site_name FROM icd_master ORDER BY icd_code')
        ->fetch_all(MYSQLI_ASSOC);
}

/**
 * Validate and normalise the patient half of the add/edit form.
 *
 * @param array $provinces Province => districts map.
 * @param array $current   Stored record, so a province/district saved before the
 *                         dropdowns existed can be kept instead of being lost.
 * @return array{0: array, 1: string[]} normalised values and validation errors
 */
function validate_patient_input(array $input, array $provinces, array $current = []): array
{
    $errors = [];
    $patient = [
        'patient_name' => trim($input['patient_name'] ?? ''),
        'age' => trim($input['age'] ?? ''),
        'sex' => $input['sex'] ?? '',
        'id_type' => $input['id_type'] ?? '',
        'id_no' => trim($input['id_no'] ?? ''),
        'province' => trim($input['province'] ?? ''),
        'district' => trim($input['district'] ?? ''),
        'address' => trim($input['address'] ?? ''),
        'contact_no' => trim($input['contact_no'] ?? ''),
    ];

    if ($patient['patient_name'] === '' || mb_strlen($patient['patient_name']) > 150) {
        $errors[] = 'Enter a patient name of up to 150 characters.';
    }
    if ($patient['age'] !== '' && (!ctype_digit($patient['age']) || (int) $patient['age'] > 130)) {
        $errors[] = 'Age must be a whole number between 0 and 130.';
    }
    if (!in_array($patient['sex'], SEX_OPTIONS, true)) {
        $errors[] = 'Select a valid sex.';
    }
    if ($patient['id_type'] !== '' && !in_array($patient['id_type'], array_merge(ID_TYPES, LEGACY_ID_TYPES), true)) {
        $errors[] = 'Select a valid ID type.';
    }
    if ($patient['id_type'] !== '' && $patient['id_no'] === '') {
        $errors[] = 'Enter an ID number when an ID type is selected.';
    }
    if ($patient['id_no'] !== '' && $patient['id_type'] === '') {
        $errors[] = 'Select an ID type for the ID number you entered.';
    }
    $unchangedLocation = isset($current['province'], $current['district'])
        && $patient['province'] === (string) $current['province']
        && $patient['district'] === (string) $current['district'];

    if (!$unchangedLocation) {
        if ($patient['province'] !== '' && !isset($provinces[$patient['province']])) {
            $errors[] = 'Select a valid province.';
        } elseif ($patient['district'] !== ''
            && ($patient['province'] === '' || !in_array($patient['district'], $provinces[$patient['province']], true))) {
            $errors[] = 'Select a district that belongs to the selected province.';
        }
    }
    if (mb_strlen($patient['address']) > 255) {
        $errors[] = 'Address must be 255 characters or fewer.';
    }
    if ($patient['contact_no'] !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $patient['contact_no'])) {
        $errors[] = 'Enter a valid contact number (7-20 digits or phone symbols).';
    }

    return [$patient, $errors];
}

/**
 * Validate the primary/secondary diagnosis half of the form.
 *
 * @return array{0: array, 1: string[]} normalised diagnosis blocks and errors
 */
function validate_diagnosis_input(array $input, array $icdIds): array
{
    $errors = [];
    $diagnoses = [];

    foreach (['primary' => 'Primary', 'secondary' => 'Secondary'] as $prefix => $label) {
        $enabled = !empty($input[$prefix . '_enabled']);
        $block = [
            'enabled' => $enabled,
            'icd_master_id' => trim($input[$prefix . '_icd_master_id'] ?? ''),
            'remarks' => trim($input[$prefix . '_remarks'] ?? ''),
            'prepared_by' => trim($input[$prefix . '_prepared_by'] ?? ''),
            'prepared_email' => trim($input[$prefix . '_prepared_email'] ?? ''),
            'prepared_contact' => trim($input[$prefix . '_prepared_contact'] ?? ''),
        ];

        if ($enabled) {
            if ($block['icd_master_id'] === '' || !in_array((int) $block['icd_master_id'], $icdIds, true)) {
                $errors[] = 'Select a valid ICD code for the ' . strtolower($label) . ' diagnosis.';
            }
            if ($block['prepared_email'] !== '' && !filter_var($block['prepared_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address in the ' . strtolower($label) . ' "form filled by" section.';
            }
            if ($block['prepared_contact'] !== ''
                && !preg_match('/^[0-9+()\-\s]{7,20}$/', $block['prepared_contact'])) {
                $errors[] = 'Enter a valid contact number in the ' . strtolower($label) . ' "form filled by" section.';
            }
        }

        $diagnoses[$prefix] = $block;
    }

    if (!$diagnoses['primary']['enabled'] && $diagnoses['secondary']['enabled']) {
        $errors[] = 'Record the primary diagnosis before adding a secondary diagnosis.';
    }

    return [$diagnoses, $errors];
}

/** Insert or update one diagnosis (and its "filled by" row) for a patient. */
function save_diagnosis(mysqli $conn, int $patientId, string $type, array $block, ?array $existing): void
{
    if (!$block['enabled']) {
        if ($existing) {
            $stmt = $conn->prepare('DELETE FROM patient_diagnosis WHERE id = ?');
            $stmt->bind_param('i', $existing['id']);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    $icdId = (int) $block['icd_master_id'];
    $remarks = $block['remarks'] !== '' ? $block['remarks'] : null;

    if ($existing) {
        $diagnosisId = (int) $existing['id'];
        $stmt = $conn->prepare('UPDATE patient_diagnosis SET icd_master_id = ?, remarks = ? WHERE id = ?');
        $stmt->bind_param('isi', $icdId, $remarks, $diagnosisId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO patient_diagnosis (patient_id, icd_master_id, diagnosis_type, remarks)
                                VALUES (?, ?, ?, ?)');
        $stmt->bind_param('iiss', $patientId, $icdId, $type, $remarks);
        $stmt->execute();
        $diagnosisId = (int) $stmt->insert_id;
        $stmt->close();
    }

    $preparedBy = $block['prepared_by'] !== '' ? $block['prepared_by'] : null;
    $preparedEmail = $block['prepared_email'] !== '' ? $block['prepared_email'] : null;
    $preparedContact = $block['prepared_contact'] !== '' ? $block['prepared_contact'] : null;
    $hasFilledBy = $preparedBy !== null || $preparedEmail !== null || $preparedContact !== null;

    if ($existing && !empty($existing['filled_id'])) {
        if ($hasFilledBy) {
            $stmt = $conn->prepare('UPDATE diagnosis_filled_by
                                    SET prepared_by = ?, prepared_email = ?, prepared_contact = ?
                                    WHERE diagnosis_id = ?');
            $stmt->bind_param('sssi', $preparedBy, $preparedEmail, $preparedContact, $diagnosisId);
        } else {
            $stmt = $conn->prepare('DELETE FROM diagnosis_filled_by WHERE diagnosis_id = ?');
            $stmt->bind_param('i', $diagnosisId);
        }
        $stmt->execute();
        $stmt->close();
        return;
    }

    if ($hasFilledBy) {
        $stmt = $conn->prepare('INSERT INTO diagnosis_filled_by (diagnosis_id, prepared_by, prepared_email, prepared_contact)
                                VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isss', $diagnosisId, $preparedBy, $preparedEmail, $preparedContact);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * WHERE clause + bound parameters shared by the dashboard list and the export.
 *
 * @return array{sql: string, types: string, params: array}
 */
function patient_filters(int $hospitalId, array $filters): array
{
    $sql = ' WHERE p.hospital_id = ?';
    $types = 'i';
    $params = [$hospitalId];

    if (($filters['search'] ?? '') !== '') {
        $sql .= ' AND (p.patient_name LIKE ? OR p.contact_no LIKE ? OR p.id_no LIKE ? OR p.address LIKE ?)';
        $term = '%' . $filters['search'] . '%';
        $types .= 'ssss';
        array_push($params, $term, $term, $term, $term);
    }
    if (($filters['sex'] ?? '') !== '' && in_array($filters['sex'], SEX_OPTIONS, true)) {
        $sql .= ' AND p.sex = ?';
        $types .= 's';
        $params[] = $filters['sex'];
    }
    if (!empty($filters['icd_id'])) {
        $sql .= ' AND EXISTS (SELECT 1 FROM patient_diagnosis pd WHERE pd.patient_id = p.id AND pd.icd_master_id = ?)';
        $types .= 'i';
        $params[] = (int) $filters['icd_id'];
    }
    if (($filters['from'] ?? '') !== '') {
        $sql .= ' AND p.created_at >= ?';
        $types .= 's';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if (($filters['to'] ?? '') !== '') {
        $sql .= ' AND p.created_at <= ?';
        $types .= 's';
        $params[] = $filters['to'] . ' 23:59:59';
    }

    return ['sql' => $sql, 'types' => $types, 'params' => $params];
}

/** Run a prepared statement and return its result set. */
function run_query(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_result
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    return $result;
}
