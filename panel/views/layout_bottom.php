<?php if ($user): ?>
    </div>
  </main>
</div>
<div class="install-banner" id="install-banner" data-testid="install-banner">
  <span>Install ApexNode as an app</span>
  <button class="btn btn-primary btn-sm" id="install-pwa-btn" data-testid="install-pwa-btn">Install</button>
  <button class="btn btn-ghost btn-sm" id="install-dismiss-btn" data-testid="install-dismiss-btn">✕</button>
</div>
<?php else: ?>
</div>
<?php endif; ?>
<script src="<?= h(apex_base_path()) ?>/assets/app.js"></script>
</body>
</html>
