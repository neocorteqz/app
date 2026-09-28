<div class="between">
  <div><div class="section-title" style="margin:0">Access Control</div><h1><?= h($server['name']) ?></h1></div>
  <a href="/servers/<?= (int)$server['id'] ?>" class="btn">← Server</a>
</div>

<div class="card" style="margin-top:16px" data-testid="server-access-panel">
  <table class="table">
    <thead><tr><th>Viewer</th><th>Server</th><th>Console</th><th>Control</th><th>Files</th><th>Manage files</th><th>Backups</th><th>Manage backups</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $access): ?>
      <tr data-testid="access-user-<?= (int)$access['id'] ?>">
        <td><?= h($access['username']) ?><div class="muted"><?= h($access['email']) ?></div></td>
        <?php $formId = 'server-access-user-' . (int)$access['id']; ?>
        <?php foreach (['view_server'=>'View server', 'view_console'=>'View console', 'control_server'=>'Control', 'view_files'=>'View files', 'manage_files'=>'Manage files', 'view_backups'=>'View backups', 'manage_backups'=>'Manage backups'] as $key=>$label): ?>
          <td><label title="<?= h($label) ?>"><input form="<?= h($formId) ?>" type="checkbox" name="<?= h($key) ?>" value="1" <?= !empty($access[$key])?'checked':'' ?> aria-label="<?= h($label) ?>"></label></td>
        <?php endforeach; ?>
        <td><form id="<?= h($formId) ?>" method="post" action="/servers/<?= (int)$server['id'] ?>/access" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$access['id'] ?>">
          <button class="btn btn-sm btn-primary">Save</button>
        </form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="9" class="muted">No viewer accounts. Create viewers in Users first.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>