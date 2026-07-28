<?php
$pageTitle = 'Activity Log';
require_once __DIR__ . '/includes/header.php';

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) $conn->query('SELECT COUNT(*) FROM admin_activity_log')->fetch_row()[0];
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare(
    'SELECT l.*, s.username
     FROM admin_activity_log l
     LEFT JOIN super_admins s ON s.id = l.super_admin_id
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT ? OFFSET ?'
);
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$rows = $stmt->get_result();
?>
<div class="panel">
  <div class="table-wrap">
    <table>
      <thead><tr><th>When</th><th>Superadmin</th><th>Action</th><th>Entity</th><th>ID</th><th>Details</th></tr></thead>
      <tbody>
      <?php if ($rows->num_rows === 0): ?>
        <tr><td colspan="6">Nothing logged yet.</td></tr>
      <?php endif; ?>
      <?php while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><?= e($r['created_at']) ?></td>
          <td><?= e($r['username'] ?? 'deleted admin') ?></td>
          <td><?= e($r['action']) ?></td>
          <td><?= e($r['entity']) ?></td>
          <td><?= $r['entity_id'] !== null ? (int) $r['entity_id'] : '—' ?></td>
          <td><?= e($r['details']) ?></td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <?php if ($i === $page): ?>
          <span class="current"><?= $i ?></span>
        <?php else: ?>
          <a href="?page=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
