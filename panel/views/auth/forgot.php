<div class="auth-card fade-in">
  <div class="brand">
    <div class="logo">◈</div>
    <div><h1>ApexNode</h1><small>CTRL // TOWER</small></div>
  </div>
  <h2 style="margin-top:8px">Forgot password</h2>
  <p>Enter your email address and we will send a reset link.</p>
  <?php if ($msg = flash('success')): ?><div class="flash success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash('error')): ?><div class="flash error" data-testid="flash-error"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash('info')): ?><div class="flash info"><?= h($msg) ?></div><?php endif; ?>
  <form method="post" action="<?= h(apex_base_path()) ?>/forgot">
    <?= csrf_field() ?>
    <div class="form-group">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" autofocus required data-testid="forgot-email">
    </div>
    <button class="btn btn-primary" style="width:100%" data-testid="forgot-submit">Send reset link →</button>
  </form>
  <p style="text-align:center;margin-top:20px" class="muted">
    Remembered your password? <a href="<?= h(apex_base_path()) ?>/login" data-testid="link-login">Sign in →</a>
  </p>
</div>
