<?php
/** Inbox for the support requests hospitals send from contact.php. */
require_once __DIR__ . '/includes/auth.php';
sa_require_login();

if (!has_table($conn, 'support_messages')) {
    sa_flash('Run the database upgrade to enable hospital messages.', 'error');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sa_verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'status') {
        $status = $_POST['status'] ?? 'new';
        if (!in_array($status, ['new', 'in_progress', 'resolved'], true)) {
            $status = 'new';
        }
        $notes = trim($_POST['admin_notes'] ?? '');
        $stmt = $conn->prepare(
            'UPDATE support_messages
             SET status = ?, admin_notes = ?,
                 resolved_at = IF(? = \'resolved\', COALESCE(resolved_at, NOW()), NULL)
             WHERE id = ?'
        );
        $stmt->bind_param('sssi', $status, $notes, $status, $id);
        $stmt->execute();
        $stmt->close();
        sa_log($conn, 'update', 'support_message', $id, 'Marked ' . $status);
        sa_flash('Message updated.');
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare('DELETE FROM support_messages WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        sa_log($conn, 'delete', 'support_message', $id, 'Message deleted');
        sa_flash('Message deleted.');
    }

    header('Location: messages.php' . (isset($_POST['return_status']) && $_POST['return_status'] !== ''
        ? '?status=' . urlencode($_POST['return_status']) : ''));
    exit;
}

$filter = $_GET['status'] ?? '';
if (!in_array($filter, ['new', 'in_progress', 'resolved'], true)) {
    $filter = '';
}

$pageTitle = 'Messages';
require_once __DIR__ . '/includes/header.php';

$counts = ['new' => 0, 'in_progress' => 0, 'resolved' => 0];
$countRows = $conn->query('SELECT status, COUNT(*) FROM support_messages GROUP BY status');
while ($row = $countRows->fetch_row()) {
    $counts[$row[0]] = (int) $row[1];
}

$sql = 'SELECT * FROM support_messages';
if ($filter !== '') {
    $sql .= ' WHERE status = ?';
}
$sql .= " ORDER BY FIELD(status, 'new', 'in_progress', 'resolved'), created_at DESC";

$stmt = $conn->prepare($sql);
if ($filter !== '') {
    $stmt->bind_param('s', $filter);
}
$stmt->execute();
$messages = $stmt->get_result();

$statusLabels = ['new' => 'New', 'in_progress' => 'In progress', 'resolved' => 'Resolved'];
?>
<div class="panel">
  <div class="filters">
    <a class="btn <?= $filter === '' ? '' : 'btn-light' ?>" href="messages.php">All</a>
    <?php foreach ($statusLabels as $key => $label): ?>
      <a class="btn <?= $filter === $key ? '' : 'btn-light' ?>" href="messages.php?status=<?= $key ?>">
        <?= $label ?> (<?= $counts[$key] ?>)
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($messages->num_rows === 0): ?>
  <div class="panel">No messages<?= $filter !== '' ? ' with this status' : '' ?> yet.</div>
<?php endif; ?>

<?php while ($m = $messages->fetch_assoc()): ?>
  <div class="panel message-card">
    <div class="message-head">
      <div>
        <h2><?= e($m['subject']) ?></h2>
        <p class="hint">
          <strong><?= e($m['hospital_name']) ?></strong> &middot; <?= e($m['contact_person']) ?> &middot;
          <a href="mailto:<?= e($m['email']) ?>?subject=Re: <?= e(rawurlencode($m['subject'])) ?>"><?= e($m['email']) ?></a>
          <?= $m['contact_no'] ? ' &middot; ' . e($m['contact_no']) : '' ?>
          &middot; <?= e($m['created_at']) ?>
        </p>
      </div>
      <span class="badge badge-<?= $m['status'] === 'resolved' ? 'ok' : ($m['status'] === 'new' ? 'warn' : 'off') ?>">
        <?= e($statusLabels[$m['status']]) ?>
      </span>
    </div>

    <p class="message-body"><?= nl2br(e($m['message'])) ?></p>

    <form method="post" class="message-actions">
      <?= sa_csrf_field() ?>
      <input type="hidden" name="action" value="status">
      <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
      <input type="hidden" name="return_status" value="<?= e($filter) ?>">
      <div class="field">
        <label for="notes-<?= (int) $m['id'] ?>">Internal note</label>
        <input type="text" id="notes-<?= (int) $m['id'] ?>" name="admin_notes"
               value="<?= e($m['admin_notes']) ?>" placeholder="What was done about this?">
      </div>
      <div class="field">
        <label for="status-<?= (int) $m['id'] ?>">Status</label>
        <select id="status-<?= (int) $m['id'] ?>" name="status">
          <?php foreach ($statusLabels as $key => $label): ?>
            <option value="<?= $key ?>" <?= $m['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn" type="submit">Save</button>
    </form>

    <form method="post" onsubmit="return confirm('Delete this message?');">
      <?= sa_csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
      <input type="hidden" name="return_status" value="<?= e($filter) ?>">
      <button class="btn btn-danger btn-sm" type="submit">Delete</button>
    </form>
  </div>
<?php endwhile; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
