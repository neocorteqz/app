<div class="auth-card fade-in">
  <div class="brand">
    <div class="logo">◈</div>
    <div><h1>ApexNode</h1><small>CTRL // TOWER</small></div>
  </div>
  <h2>Create Admin Account</h2>
  <p>First user becomes the panel administrator.</p>
  <?php if ($msg = flash('error')): ?><div class="flash error"><?= h($msg) ?></div><?php endif; ?>
  <form method="post" action="<?= h(apex_base_path()) ?>/register">
    <?= csrf_field() ?>
    <div class="form-group"><label>Username</label><input type="text" name="username" required data-testid="register-username"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email" required data-testid="register-email"></div>
    <div class="form-group"><label>Password (min 6)</label><input type="password" name="password" required data-testid="register-password"></div>
    <button class="btn btn-primary" style="width:100%" data-testid="register-submit">Create account →</button>
  </form>
  <p style="text-align:center;margin-top:20px" class="muted">
    Already registered? <a href="<?= h(apex_base_path()) ?>/login">Sign in →</a>
  </p>
</div>
