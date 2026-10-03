<div class="auth-card fade-in">
  <div class="brand">
    <div class="logo">◈</div>
    <div><h1>ApexNode</h1><small>CTRL // TOWER</small></div>
  </div>
  <h2 style="margin-top:8px">Sign in</h2>
  <p>Access your tactical control tower.</p>
  <?php if ($msg = flash('success')): ?><div class="flash success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash('error')): ?><div class="flash error" data-testid="flash-error"><?= h($msg) ?></div><?php endif; ?>
  <form method="post" action="<?= h(apex_base_path()) ?>/login">
    <?= csrf_field() ?>
    <div class="form-group">
      <label for="email">Email or Username</label>
      <input type="text" id="email" name="email" autofocus required data-testid="login-email">
    </div>
    <div class="form-group">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required data-testid="login-password">
    </div>
    <button class="btn btn-primary" style="width:100%" data-testid="login-submit">Enter Command Tower →</button>
  </form>
  <p style="text-align:center;margin-top:20px" class="muted">
    First install? <a href="<?= h(apex_base_path()) ?>/register" data-testid="link-register">Create the first admin →</a>
  </p>
</div>
