<?php
/**
 * Shared query builders so the on-screen tables and the Excel exports always
 * return exactly the same rows for the same filters.
 */

/**
 * Build the WHERE clause + bind parameters for the patient record filters.
 *
 * @param array $f Filter values (hospital_id, q, sex, province, district, icd_id, from, to).
 * @return array{sql: string, types: string, params: array}
 */
function sa_patient_filters(array $f)
{
    $where = [];
    $types = '';
    $params = [];

    if (!empty($f['hospital_id'])) {
        $where[] = 'p.hospital_id = ?';
        $types .= 'i';
        $params[] = (int) $f['hospital_id'];
    }
    if (!empty($f['q'])) {
        $where[] = '(p.patient_name LIKE ? OR p.contact_no LIKE ? OR p.id_no LIKE ?)';
        $like = '%' . $f['q'] . '%';
        $types .= 'sss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($f['sex'])) {
        $where[] = 'p.sex = ?';
        $types .= 's';
        $params[] = $f['sex'];
    }
    if (!empty($f['province'])) {
        $where[] = 'p.province = ?';
        $types .= 's';
        $params[] = $f['province'];
    }
    if (!empty($f['icd_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM patient_diagnosis pd WHERE pd.patient_id = p.id AND pd.icd_master_id = ?)';
        $types .= 'i';
        $params[] = (int) $f['icd_id'];
    }
    if (!empty($f['from'])) {
        $where[] = 'DATE(p.created_at) >= ?';
        $types .= 's';
        $params[] = $f['from'];
    }
    if (!empty($f['to'])) {
        $where[] = 'DATE(p.created_at) <= ?';
        $types .= 's';
        $params[] = $f['to'];
    }

    return [
        'sql' => $where ? ' WHERE ' . implode(' AND ', $where) : '',
        'types' => $types,
        'params' => $params,
    ];
}

/** Run a prepared statement built from sa_patient_filters() and return the result set. */
function sa_run(mysqli $conn, $sql, $types = '', array $params = [])
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/** One row per patient, with hospital name and their diagnoses folded into one cell. */
function sa_patient_select()
{
    return 'SELECT p.id, h.hospital_name, p.patient_name, p.age, p.sex, p.id_type, p.id_no,
                   p.province, p.district, p.address, p.contact_no, p.created_at,
                   GROUP_CONCAT(DISTINCT CONCAT(i.icd_code, " - ", i.icd_name) ORDER BY i.icd_code SEPARATOR "; ") AS diagnoses
            FROM patient_records p
            JOIN hospital_accounts h ON h.id = p.hospital_id
            LEFT JOIN patient_diagnosis d ON d.patient_id = p.id
            LEFT JOIN icd_master i ON i.id = d.icd_master_id';
}

/** One row per diagnosis: the flat sheet used for analysis in Excel. */
function sa_full_registry_select()
{
    return 'SELECT p.id AS patient_id, h.hospital_name, h.hospital_code, p.patient_name, p.age, p.sex,
                   p.id_type, p.id_no, p.province, p.district, p.address, p.contact_no,
                   i.icd_code, i.icd_name, i.site_name, d.diagnosis_type, d.remarks,
                   f.prepared_by, f.prepared_email, f.prepared_contact,
                   p.created_at AS record_created_at
            FROM patient_records p
            JOIN hospital_accounts h ON h.id = p.hospital_id
            LEFT JOIN patient_diagnosis d ON d.patient_id = p.id
            LEFT JOIN icd_master i ON i.id = d.icd_master_id
            LEFT JOIN diagnosis_filled_by f ON f.diagnosis_id = d.id';
}
