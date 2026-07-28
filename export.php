<?php
/**
 * Excel/CSV export of the logged-in hospital's own records. Accepts the same
 * filters as the dashboard so "Export to Excel" downloads exactly what is
 * shown on screen.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/superadmin/lib/XlsxWriter.php';

$hospital = require_hospital_login($conn);
$hospitalId = (int) $hospital['id'];

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'sex' => $_GET['sex'] ?? '',
    'icd_id' => $_GET['icd_id'] ?? '',
    'from' => $_GET['from'] ?? '',
    'to' => $_GET['to'] ?? '',
];
$built = patient_filters($hospitalId, $filters);
$format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';

$lastEdited = has_column($conn, 'patient_records', 'updated_at')
    ? 'p.updated_at AS last_edited'
    : 'NULL AS last_edited';
$result = run_query($conn,
    'SELECT p.id AS record_no, p.patient_name, p.age, p.sex, p.id_type, p.id_no,
            p.province, p.district, p.address, p.contact_no,
            d.diagnosis_type, i.icd_code, i.icd_name, i.site_name, d.remarks,
            f.prepared_by, f.prepared_email, f.prepared_contact,
            p.created_at AS registered_at, ' . $lastEdited . '
     FROM patient_records p
     LEFT JOIN patient_diagnosis d ON d.patient_id = p.id
     LEFT JOIN icd_master i ON i.id = d.icd_master_id
     LEFT JOIN diagnosis_filled_by f ON f.diagnosis_id = d.id'
    . $built['sql'] .
    ' ORDER BY p.created_at DESC, p.id DESC, d.diagnosis_type',
    $built['types'], $built['params']);

$numericTypes = [
    MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG,
    MYSQLI_TYPE_INT24, MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE,
    MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL, MYSQLI_TYPE_YEAR,
];
$headers = [];
$numericColumns = [];
foreach ($result->fetch_fields() as $index => $field) {
    $headers[] = ucwords(str_replace('_', ' ', $field->name));
    if (in_array($field->type, $numericTypes, true)) {
        $numericColumns[] = $index;
    }
}
$rows = $result->fetch_all(MYSQLI_NUM);
[$headers, $rows, $numericColumns] = XlsxWriter::numbered($headers, $rows, $numericColumns);

$slug = preg_replace('/[^a-z0-9]+/i', '_', $hospital['hospital_code'] ?: $hospital['hospital_name']);
$filename = strtolower(trim($slug, '_')) . '_patient_records_' . date('Y-m-d_His');

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

$summary = run_query($conn,
    'SELECT i.icd_code, i.icd_name, i.site_name, COUNT(*) AS cases,
            SUM(p.sex = "Male") AS male, SUM(p.sex = "Female") AS female,
            ROUND(AVG(p.age), 1) AS average_age
     FROM patient_diagnosis d
     JOIN patient_records p ON p.id = d.patient_id
     JOIN icd_master i ON i.id = d.icd_master_id
     WHERE p.hospital_id = ?
     GROUP BY i.id ORDER BY cases DESC',
    'i', [$hospitalId])->fetch_all(MYSQLI_NUM);

[$summaryHeaders, $summaryRows, $summaryNumeric] = XlsxWriter::numbered(
    ['ICD Code', 'Diagnosis', 'Site', 'Cases', 'Male', 'Female', 'Average Age'],
    $summary,
    [3, 4, 5, 6]
);

$xlsx = new XlsxWriter();
$xlsx->addSheet('Patient Records', $headers, $rows, $numericColumns);
$xlsx->addSheet('Summary by ICD', $summaryHeaders, $summaryRows, $summaryNumeric);
$xlsx->download($filename . '.xlsx');
