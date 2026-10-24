<div class="auth-card fade-in">
  <div class="brand">
    <div class="logo">◈</div>
    <div><h1>ApexNode</h1><small>CTRL // TOWER</small></div>
  </div>
  <h2 style="margin-top:8px">Reset password</h2>
  <p>Set a new password for <?= h($username) ?> (<?= h($email) ?>).</p>
  <?php if ($msg = flash('success')): ?><div class="flash success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash('error')): ?><div class="flash error" data-testid="flash-error"><?= h($msg) ?></div><?php endif; ?>
  <form method="post" action="<?= h(apex_base_path()) ?>/reset/<?= h($token) ?>">
    <?= csrf_field() ?>
    <div class="form-group">
      <label for="password">New password</label>
      <input type="password" id="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password" data-testid="reset-password">
    </div>
    <div class="form-group">
      <label for="confirm">Confirm new password</label>
      <input type="password" id="confirm" name="confirm" required minlength="12" maxlength="72" autocomplete="new-password" data-testid="reset-confirm">
    </div>
    <button class="btn btn-primary" style="width:100%" data-testid="reset-submit">Reset password →</button>
  </form>
  <p style="text-align:center;margin-top:20px" class="muted">
    <a href="<?= h(apex_base_path()) ?>/login" data-testid="link-login">Back to sign in →</a>
  </p>
</div>
