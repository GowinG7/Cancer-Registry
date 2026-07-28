<?php
require_once __DIR__ . '/config.php';

$hospital = require_hospital_login($conn);
$hospitalId = (int) $hospital['id'];

// Deleting a record is a POST action so it cannot be triggered by a link.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verify_csrf()) {
        flash('error', 'Your session expired. Please try again.');
    } else {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $patient = find_patient($conn, $patientId, $hospitalId);
        if (!$patient) {
            flash('error', 'That patient record was not found.');
        } else {
            $stmt = $conn->prepare('DELETE FROM patient_records WHERE id = ? AND hospital_id = ?');
            $stmt->bind_param('ii', $patientId, $hospitalId);
            $stmt->execute();
            $stmt->close();
            flash('success', 'Patient record "' . $patient['patient_name'] . '" was deleted.');
        }
    }
    redirect('dashboard.php?' . http_build_query(array_diff_key($_GET, ['page' => ''])));
}

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'sex' => $_GET['sex'] ?? '',
    'icd_id' => $_GET['icd_id'] ?? '',
    'from' => $_GET['from'] ?? '',
    'to' => $_GET['to'] ?? '',
];
$hasFilters = implode('', $filters) !== '';
$built = patient_filters($hospitalId, $filters);

$total = (int) run_query(
    $conn,
    'SELECT COUNT(*) AS total FROM patient_records p' . $built['sql'],
    $built['types'],
    $built['params']
)->fetch_assoc()['total'];

$perPage = 10;
$totalPages = max(1, (int) ceil($total / $perPage));
$page = max(1, min($totalPages, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1));
$offset = ($page - 1) * $perPage;

// One row per patient; the diagnoses are aggregated so the serial numbers and
// the pagination always match the number of patients.
$updatedAt = has_column($conn, 'patient_records', 'updated_at') ? 'p.updated_at' : 'NULL AS updated_at';
$records = run_query(
    $conn,
    'SELECT p.id, p.patient_name, p.age, p.sex, p.id_type, p.id_no, p.province, p.district,
            p.address, p.contact_no, p.created_at, ' . $updatedAt . ',
            GROUP_CONCAT(CONCAT(d.diagnosis_type, ": ", i.icd_code, " - ", i.icd_name)
                         ORDER BY d.diagnosis_type SEPARATOR "|") AS diagnoses
     FROM patient_records p
     LEFT JOIN patient_diagnosis d ON d.patient_id = p.id
     LEFT JOIN icd_master i ON i.id = d.icd_master_id'
    . $built['sql'] .
    ' GROUP BY p.id
      ORDER BY p.created_at DESC, p.id DESC
      LIMIT ? OFFSET ?',
    $built['types'] . 'ii',
    array_merge($built['params'], [$perPage, $offset])
)->fetch_all(MYSQLI_ASSOC);

$stats = run_query(
    $conn,
    'SELECT COUNT(*) AS patients,
            SUM(p.sex = "Male") AS male,
            SUM(p.sex = "Female") AS female,
            (SELECT COUNT(*) FROM patient_diagnosis d
             JOIN patient_records pr ON pr.id = d.patient_id WHERE pr.hospital_id = ?) AS diagnoses,
            SUM(DATE(p.created_at) = CURDATE()) AS today
     FROM patient_records p WHERE p.hospital_id = ?',
    'ii',
    [$hospitalId, $hospitalId]
)->fetch_assoc();

$icdCodes = all_icd_codes($conn);
$exportQuery = http_build_query(array_filter($filters, static fn($value) => $value !== ''));

$pageTitle = 'Patient Records';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
        <h1 class="h3 mb-1">Patient Records</h1>
        <p class="text-muted mb-0"><?= e($hospital['hospital_name']) ?> cancer registry</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="export.php<?= $exportQuery ? '?' . e($exportQuery) : '' ?>" class="btn btn-outline-primary">
            Export to Excel
        </a>
        <a href="add_patient_diagnosis.php" class="btn btn-primary">+ Add Patient</a>
    </div>
</div>

<div class="row g-3 stat-row">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="stat-value"><?= (int) $stats['patients'] ?></span>
            <span class="stat-label">Patients registered</span>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="stat-value"><?= (int) $stats['diagnoses'] ?></span>
            <span class="stat-label">Diagnoses recorded</span>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="stat-value"><?= (int) $stats['male'] ?> / <?= (int) $stats['female'] ?></span>
            <span class="stat-label">Male / Female</span>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <span class="stat-value"><?= (int) $stats['today'] ?></span>
            <span class="stat-label">Added today</span>
        </div>
    </div>
</div>

<div class="card filter-card">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-12 col-lg-4">
            <label for="search" class="form-label">Search</label>
            <input id="search" name="search" type="search" class="form-control" value="<?= e($filters['search']) ?>"
                placeholder="Patient name, contact, ID or address" autocomplete="off">
        </div>
        <div class="col-6 col-lg-2">
            <label for="sex" class="form-label">Sex</label>
            <select id="sex" name="sex" class="form-select">
                <option value="">Any</option>
                <?php foreach (SEX_OPTIONS as $option): ?>
                    <option value="<?= e($option) ?>" <?= $filters['sex'] === $option ? 'selected' : '' ?>>
                        <?= e($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-lg-3">
            <label for="icd_id" class="form-label">ICD code</label>
            <select id="icd_id" name="icd_id" class="form-select">
                <option value="">Any</option>
                <?php foreach ($icdCodes as $icd): ?>
                    <option value="<?= (int) $icd['id'] ?>" <?= (string) $filters['icd_id'] === (string) $icd['id'] ? 'selected' : '' ?>>
                        <?= e($icd['icd_code'] . ' - ' . $icd['icd_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-lg-3 col-xl-2">
            <label for="from" class="form-label">From</label>
            <input id="from" name="from" type="date" class="form-control" value="<?= e($filters['from']) ?>">
        </div>
        <div class="col-6 col-xl-2">
            <label for="to" class="form-label">To</label>
            <input id="to" name="to" type="date" class="form-control" value="<?= e($filters['to']) ?>">
        </div>
        <div class="col-12 col-lg-auto d-flex gap-2">
            <button type="submit" class="btn btn-primary">Apply</button>
            <?php if ($hasFilters): ?>
                <a href="dashboard.php" class="btn btn-outline-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <p class="text-muted mb-0">
        <?= $total ?> patient record<?= $total === 1 ? '' : 's' ?><?= $hasFilters ? ' match your filters' : '' ?>
    </p>
    <small class="text-muted">Page <?= $page ?> of <?= $totalPages ?></small>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:56px">S.N.</th>
                    <th>Patient</th>
                    <th>Age / Sex</th>
                    <th>Address</th>
                    <th>Contact</th>
                    <th>Diagnoses</th>
                    <th>Recorded</th>
                    <th class="text-end" style="width:190px">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$records): ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            <p class="mb-2">No patient records found<?= $hasFilters ? ' for these filters' : '' ?>.</p>
                            <a href="add_patient_diagnosis.php" class="btn btn-sm btn-primary">Add the first patient</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $serial = $offset + 1; ?>
                    <?php foreach ($records as $row): ?>
                        <tr>
                            <td class="text-muted"><?= $serial++ ?></td>
                            <td>
                                <span class="fw-semibold"><?= e($row['patient_name']) ?></span>
                                <?php if ($row['id_no']): ?>
                                    <span class="d-block text-muted small"><?= e($row['id_type']) ?>:
                                        <?= e($row['id_no']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $row['age'] === null ? '<span class="text-muted">-</span>' : (int) $row['age'] ?>
                                <span class="text-muted">/</span> <?= e($row['sex']) ?>
                            </td>
                            <td>
                                <?= e($row['district'] ?: '-') ?>
                                <span class="d-block text-muted small"><?= e($row['province'] ?: '') ?></span>
                            </td>
                            <td><?= e($row['contact_no'] ?: '-') ?></td>
                            <td>
                                <?php if (!$row['diagnoses']): ?>
                                    <span class="badge text-bg-light">No diagnosis</span>
                                <?php else: ?>
                                    <?php foreach (explode('|', $row['diagnoses']) as $diagnosis): ?>
                                        <?php [$type, $text] = array_pad(explode(': ', $diagnosis, 2), 2, ''); ?>
                                        <span class="d-block small">
                                            <span class="badge <?= $type === 'Primary' ? 'text-bg-success' : 'text-bg-warning' ?>">
                                                <?= e($type) ?>
                                            </span>
                                            <?= e($text) ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= e(date('d M Y, H:i', strtotime($row['created_at']))) ?>
                                <?php if (!empty($row['updated_at'])): ?>
                                    <span class="d-block">edited
                                        <?= e(date('d M Y, H:i', strtotime($row['updated_at']))) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="view_patient.php?id=<?= (int) $row['id'] ?>"
                                    class="btn btn-sm btn-outline-secondary">View</a>
                                <a href="edit_patient.php?id=<?= (int) $row['id'] ?>"
                                    class="btn btn-sm btn-outline-primary">Edit</a>
                                <form method="post" class="d-inline js-confirm-delete"
                                    data-name="<?= e($row['patient_name']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="patient_id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <?php $pageLink = static function (int $number) use ($filters): string {
        return '?' . http_build_query(array_merge($filters, ['page' => $number]));
    }; ?>
    <nav aria-label="Patient record pages" class="mt-3">
        <ul class="pagination justify-content-center mb-0">
            <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= e($pageLink(max(1, $page - 1))) ?>">Previous</a>
            </li>
            <?php $first = max(1, $page - 2);
            $last = min($totalPages, $page + 2); ?>
            <?php if ($first > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= e($pageLink(1)) ?>">1</a></li>
                <?php if ($first > 2): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <?php endif; ?>
            <?php endif; ?>
            <?php for ($number = $first; $number <= $last; $number++): ?>
                <li class="page-item <?= $number === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e($pageLink($number)) ?>"><?= $number ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($last < $totalPages): ?>
                <?php if ($last < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?= e($pageLink($totalPages)) ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $page === $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= e($pageLink(min($totalPages, $page + 1))) ?>">Next</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>