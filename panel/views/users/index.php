<div class="between">
  <div><div class="section-title" style="margin:0">Access</div><h1 data-testid="page-title">Users</h1></div>
</div>
<div class="grid-2" style="margin-top:16px">
  <div class="card">
    <div class="card-title"><h3>Team</h3></div>
    <table class="table" data-testid="users-table">
      <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Joined</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $uu): ?>
        <tr>
          <td><?= h($uu['username']) ?></td>
          <td class="mono muted"><?= h($uu['email']) ?></td>
          <td><span class="chip <?= $uu['role']==='admin'?'accent':'' ?>"><?= h($uu['role']) ?></span></td>
          <td class="mono muted"><?= h($uu['created_at']) ?></td>
          <td>
            <?php if ((int)$uu['id'] !== (int)$_SESSION['uid']): ?>
              <form method="post" action="<?= h(apex_base_path()) ?>/users/delete" data-confirm="Remove user?" style="margin:0"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$uu['id'] ?>">
                <button class="btn btn-sm btn-danger">✕</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card">
    <div class="card-title"><h3>Invite user</h3></div>
    <form method="post" action="<?= h(apex_base_path()) ?>/users" data-testid="user-form">
      <?= csrf_field() ?>
      <div class="form-group"><label>Username</label><input name="username" required></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
      <div class="form-group">
        <label>Role</label>
        <select name="role">
          <option value="viewer">Viewer — read only</option>
          <option value="operator">Operator — manage servers</option>
          <option value="admin">Admin — full access</option>
        </select>
      </div>
      <button class="btn btn-primary" data-testid="user-submit">＋ Add User</button>
    </form>
  </div>
</div>
