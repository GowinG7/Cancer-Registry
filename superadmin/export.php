<?php
/**
 * Excel export endpoint.
 *
 * export.php?type=full        one row per diagnosis, everything joined (best for analysis)
 * export.php?type=patients    patient records (honours the patients.php filters)
 * export.php?type=hospitals   hospital accounts with record counts
 * export.php?type=diagnoses   diagnoses with their "filled by" details
 * export.php?type=icd         ICD master
 * export.php?type=summary     analysis workbook: per hospital, per ICD, per province, by sex/age
 * export.php?type=all         every sheet above in one workbook
 * Add &format=csv to any type (except all/summary) to download CSV instead.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/queries.php';
require_once __DIR__ . '/lib/XlsxWriter.php';
sa_require_login();

$type = $_GET['type'] ?? 'full';
$format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';

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

/**
 * @return array{0: string[], 1: array, 2: int[]} headers, rows, and the indexes of
 *         columns MySQL reports as numeric so Excel can aggregate them while phone
 *         numbers and ID numbers stay text. Every table starts with an S.N. column
 *         numbered from 1.
 */
function sa_table(mysqli $conn, $sql, $types = '', array $params = [])
{
    $numericTypes = [
        MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG,
        MYSQLI_TYPE_INT24, MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE,
        MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL, MYSQLI_TYPE_YEAR,
    ];

    $result = sa_run($conn, $sql, $types, $params);
    $headers = [];
    $numeric = [];
    foreach ($result->fetch_fields() as $index => $field) {
        $headers[] = ucwords(str_replace('_', ' ', $field->name));
        if (in_array($field->type, $numericTypes, true)) {
            $numeric[] = $index;
        }
    }
    $rows = [];
    while ($row = $result->fetch_row()) {
        $rows[] = $row;
    }
    return XlsxWriter::numbered($headers, $rows, $numeric);
}

function sa_sheet_full(mysqli $conn, array $built)
{
    return sa_table($conn, sa_full_registry_select() . $built['sql']
        . ' ORDER BY h.hospital_name, p.id', $built['types'], $built['params']);
}

function sa_sheet_patients(mysqli $conn, array $built)
{
    return sa_table($conn, sa_patient_select() . $built['sql']
        . ' GROUP BY p.id ORDER BY p.created_at DESC', $built['types'], $built['params']);
}

function sa_sheet_hospitals(mysqli $conn)
{
    return sa_table($conn,
        'SELECT h.id, h.hospital_name, h.hospital_code, h.username, h.email, h.contact_no, h.address,
                IF(h.is_active, "Active", "Deactivated") AS status,
                COUNT(DISTINCT p.id) AS patient_records,
                COUNT(d.id) AS diagnoses,
                h.created_at
         FROM hospital_accounts h
         LEFT JOIN patient_records p ON p.hospital_id = h.id
         LEFT JOIN patient_diagnosis d ON d.patient_id = p.id
         GROUP BY h.id ORDER BY h.hospital_name');
}

function sa_sheet_diagnoses(mysqli $conn, array $built)
{
    return sa_table($conn,
        'SELECT d.id, h.hospital_name, p.patient_name, i.icd_code, i.icd_name, i.site_name,
                d.diagnosis_type, d.remarks, f.prepared_by, f.prepared_email, f.prepared_contact, d.created_at
         FROM patient_diagnosis d
         JOIN patient_records p ON p.id = d.patient_id
         JOIN hospital_accounts h ON h.id = p.hospital_id
         JOIN icd_master i ON i.id = d.icd_master_id
         LEFT JOIN diagnosis_filled_by f ON f.diagnosis_id = d.id'
        . $built['sql'] . ' ORDER BY d.created_at DESC', $built['types'], $built['params']);
}

function sa_sheet_icd(mysqli $conn)
{
    return sa_table($conn,
        'SELECT i.id, i.icd_code, i.icd_name, i.site_name, COUNT(d.id) AS times_used, i.created_at
         FROM icd_master i LEFT JOIN patient_diagnosis d ON d.icd_master_id = i.id
         GROUP BY i.id ORDER BY i.icd_code');
}

function sa_add_summary_sheets(mysqli $conn, XlsxWriter $xlsx, array $built)
{
    [$h, $r, $n] = sa_table($conn,
        'SELECT h.hospital_name, COUNT(DISTINCT p.id) AS patients, COUNT(d.id) AS diagnoses,
                ROUND(AVG(p.age), 1) AS average_age, MIN(p.created_at) AS first_record, MAX(p.created_at) AS last_record
         FROM hospital_accounts h
         LEFT JOIN patient_records p ON p.hospital_id = h.id
         LEFT JOIN patient_diagnosis d ON d.patient_id = p.id
         GROUP BY h.id ORDER BY patients DESC');
    $xlsx->addSheet('By Hospital', $h, $r, $n);

    [$h, $r, $n] = sa_table($conn,
        'SELECT i.icd_code, i.icd_name, i.site_name, COUNT(d.id) AS cases,
                SUM(p.sex = "Male") AS male, SUM(p.sex = "Female") AS female, SUM(p.sex = "Other") AS other
         FROM icd_master i
         LEFT JOIN patient_diagnosis d ON d.icd_master_id = i.id
         LEFT JOIN patient_records p ON p.id = d.patient_id
         GROUP BY i.id ORDER BY cases DESC');
    $xlsx->addSheet('By ICD Site', $h, $r, $n);

    [$h, $r, $n] = sa_table($conn,
        'SELECT COALESCE(NULLIF(p.province, ""), "Unspecified") AS province,
                COUNT(*) AS patients, ROUND(AVG(p.age), 1) AS average_age
         FROM patient_records p GROUP BY province ORDER BY patients DESC');
    $xlsx->addSheet('By Province', $h, $r, $n);

    [$h, $r, $n] = sa_table($conn,
        'SELECT CASE
                  WHEN p.age IS NULL THEN "Unknown"
                  WHEN p.age < 15 THEN "0-14"
                  WHEN p.age < 30 THEN "15-29"
                  WHEN p.age < 45 THEN "30-44"
                  WHEN p.age < 60 THEN "45-59"
                  WHEN p.age < 75 THEN "60-74"
                  ELSE "75+"
                END AS age_group,
                COUNT(*) AS patients,
                SUM(p.sex = "Male") AS male, SUM(p.sex = "Female") AS female, SUM(p.sex = "Other") AS other
         FROM patient_records p GROUP BY age_group ORDER BY age_group');
    $xlsx->addSheet('By Age Group', $h, $r, $n);

    [$h, $r, $n] = sa_table($conn,
        'SELECT DATE_FORMAT(p.created_at, "%Y-%m") AS month, COUNT(*) AS patients
         FROM patient_records p GROUP BY month ORDER BY month');
    $xlsx->addSheet('By Month', $h, $r, $n);
}

$stamp = date('Y-m-d_His');
$xlsx = new XlsxWriter();

switch ($type) {
    case 'patients':
        [$headers, $rows, $numeric] = sa_sheet_patients($conn, $built);
        $xlsx->addSheet('Patients', $headers, $rows, $numeric);
        $filename = "patients_$stamp";
        break;
    case 'hospitals':
        [$headers, $rows, $numeric] = sa_sheet_hospitals($conn);
        $xlsx->addSheet('Hospitals', $headers, $rows, $numeric);
        $filename = "hospitals_$stamp";
        break;
    case 'diagnoses':
        [$headers, $rows, $numeric] = sa_sheet_diagnoses($conn, $built);
        $xlsx->addSheet('Diagnoses', $headers, $rows, $numeric);
        $filename = "diagnoses_$stamp";
        break;
    case 'icd':
        [$headers, $rows, $numeric] = sa_sheet_icd($conn);
        $xlsx->addSheet('ICD Master', $headers, $rows, $numeric);
        $filename = "icd_master_$stamp";
        break;
    case 'summary':
        sa_add_summary_sheets($conn, $xlsx, $built);
        $format = 'xlsx';
        $filename = "registry_summary_$stamp";
        break;
    case 'all':
        [$headers, $rows, $numeric] = sa_sheet_full($conn, $built);
        $xlsx->addSheet('Full Registry', $headers, $rows, $numeric);
        [$headers, $rows, $numeric] = sa_sheet_patients($conn, $built);
        $xlsx->addSheet('Patients', $headers, $rows, $numeric);
        [$headers, $rows, $numeric] = sa_sheet_diagnoses($conn, $built);
        $xlsx->addSheet('Diagnoses', $headers, $rows, $numeric);
        [$headers, $rows, $numeric] = sa_sheet_hospitals($conn);
        $xlsx->addSheet('Hospitals', $headers, $rows, $numeric);
        [$headers, $rows, $numeric] = sa_sheet_icd($conn);
        $xlsx->addSheet('ICD Master', $headers, $rows, $numeric);
        sa_add_summary_sheets($conn, $xlsx, $built);
        $format = 'xlsx';
        $filename = "cancer_registry_all_data_$stamp";
        break;
    case 'full':
    default:
        $type = 'full';
        [$headers, $rows, $numeric] = sa_sheet_full($conn, $built);
        $xlsx->addSheet('Full Registry', $headers, $rows, $numeric);
        $filename = "full_registry_$stamp";
        break;
}

sa_log($conn, 'export', 'registry', null, 'Exported ' . $type . ' as ' . $format);

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 correctly
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$xlsx->download($filename . '.xlsx');
