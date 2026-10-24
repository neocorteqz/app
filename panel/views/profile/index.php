<div class="between">
  <div>
    <div class="section-title" style="margin:0">Profile</div>
    <h1 data-testid="page-title">Account settings</h1>
    <p class="muted">Manage your password and email address.</p>
  </div>
</div>

<?php if ($msg = flash('success')): ?><div class="flash success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="flash error" data-testid="flash-error"><?= h($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('info')): ?><div class="flash info"><?= h($msg) ?></div><?php endif; ?>

<div class="grid-2" style="margin-top:16px">
  <div class="card">
    <div class="card-title"><h3>Change password</h3></div>
    <form method="post" action="<?= h(apex_base_path()) ?>/profile/password" data-testid="change-password-form">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>Current password</label>
        <input type="password" name="current_password" required autocomplete="current-password" data-testid="input-current-password">
      </div>
      <div class="form-group">
        <label>New password (12–72 chars)</label>
        <input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password" data-testid="input-password">
      </div>
      <div class="form-group">
        <label>Confirm new password</label>
        <input type="password" name="confirm" required minlength="12" maxlength="72" autocomplete="new-password" data-testid="input-confirm">
      </div>
      <div class="between">
        <p class="mono muted">Your password is stored using bcrypt and is never revealed back to you.</p>
        <button class="btn btn-primary" data-testid="change-password-submit">🔑 Change password</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-title"><h3>Change email</h3></div>
    <p class="muted">Current email: <code class="mono"><?= h($user['email']) ?></code></p>
    <form method="post" action="<?= h(apex_base_path()) ?>/profile/email" data-testid="change-email-form">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>New email address</label>
        <input type="email" name="new_email" required value="<?= h($_POST['new_email'] ?? '') ?>" data-testid="input-new-email">
      </div>
      <p class="mono muted" style="margin-top:8px">A confirmation link will be sent to the new address. Your email changes only after you click it.</p>
      <div class="between">
        <button class="btn btn-primary" data-testid="change-email-submit">✉ Request email change</button>
      </div>
    </form>
  </div>
</div>
