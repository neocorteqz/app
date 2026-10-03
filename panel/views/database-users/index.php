<div class="between">
  <div>
    <div class="section-title" style="margin:0">Database</div>
    <h1 data-testid="page-title">Database Users</h1>
  </div>
</div>

<?php if (empty($provisioning_configured)): ?><div class="flash error" data-testid="db-provisioning-unconfigured">Database provisioning is unavailable until the installer or administrator configures DB_PROVISIONER_USER and DB_PROVISIONER_PASS.</div><?php endif; ?>

<?php if (!empty($credential_secret)): ?>
<div class="flash success" data-testid="credential-reveal">
  <div><strong>Copy this password now.</strong> It will not be shown again.<br><code><?= h($credential_secret) ?></code></div>
  <button type="button" class="btn btn-sm" data-copy-value="<?= h($credential_secret) ?>" title="Copy password" aria-label="Copy password">▣</button>
</div>
<?php endif; ?>

<div class="grid-2" style="margin-top:16px">
  <div class="card">
    <div class="card-title"><h3>Created users</h3></div>
    <table class="table" data-testid="db-users-table">
      <thead><tr><th>Name</th><th>Username</th><th>Database</th><th>Host</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-testid="db-user-<?= (int)$r['id'] ?>">
          <td><?= h($r['name']) ?></td>
          <td class="mono muted"><?= h($r['username']) ?></td>
          <td class="mono muted"><?= h($r['database_name']) ?></td>
          <td class="mono muted"><?= h($r['host']) ?></td>
          <td class="mono muted"><?= h($r['created_at']) ?></td>
          <td>
            <form method="post" action="<?= h(apex_base_path()) ?>/database-users/rotate" data-confirm="Rotate this database password? The current password will stop working immediately." style="display:inline-block;margin:0 6px 0 0">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm" data-testid="rotate-db-password-<?= (int)$r['id'] ?>" title="Rotate password" aria-label="Rotate password">↻</button>
            </form>
            <form method="post" action="<?= h(apex_base_path()) ?>/database-users/delete" data-confirm="Delete database user?" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-danger">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <div class="card-title"><h3>Create database user</h3></div>
    <p class="muted">Database names are placed in the <code>apexnode_</code> namespace.</p>
    <form method="post" action="<?= h(apex_base_path()) ?>/database-users" data-testid="database-user-form">
      <?= csrf_field() ?>
      <div class="form-group"><label>Friendly name</label><input name="name" required placeholder="Client app"></div>
      <div class="form-group"><label>Database name</label><input name="database_name" required placeholder="app_db"></div>
      <div class="form-group"><label>Host</label><input name="host" value="localhost" required></div>
      <div class="form-group"><label>Password (optional)</label><input type="password" name="password" minlength="8" autocomplete="new-password"><small class="muted">Leave blank to generate a strong password shown once after creation.</small></div>
      <button class="btn btn-primary" data-testid="database-user-submit">＋ Create</button>
    </form>
  </div>
</div>
