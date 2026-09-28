<div class="between">
  <div>
    <div class="section-title" style="margin:0">Database</div>
    <h1 data-testid="page-title">Database Users</h1>
  </div>
</div>

<div class="grid-2" style="margin-top:16px">
  <div class="card">
    <div class="card-title"><h3>Created users</h3></div>
    <table class="table" data-testid="db-users-table">
      <thead><tr><th>Name</th><th>Username</th><th>Database</th><th>Host</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['name']) ?></td>
          <td class="mono muted"><?= h($r['username']) ?></td>
          <td class="mono muted"><?= h($r['database_name']) ?></td>
          <td class="mono muted"><?= h($r['host']) ?></td>
          <td class="mono muted"><?= h($r['created_at']) ?></td>
          <td>
            <form method="post" action="/database-users/delete" data-confirm="Delete database user?" style="margin:0">
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
    <form method="post" action="/database-users" data-testid="database-user-form">
      <?= csrf_field() ?>
      <div class="form-group"><label>Friendly name</label><input name="name" required placeholder="Client app"></div>
      <div class="form-group"><label>Database name</label><input name="database_name" required placeholder="app_db"></div>
      <div class="form-group"><label>Host</label><input name="host" value="localhost" required></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" required minlength="8"></div>
      <button class="btn btn-primary" data-testid="database-user-submit">＋ Create</button>
    </form>
  </div>
</div>
