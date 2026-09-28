<div class="between">
  <div><div class="section-title" style="margin:0">Pterodactyl</div><h1 data-testid="egg-preview-title">Review Egg Import</h1></div>
  <a href="/eggs" class="btn">Cancel</a>
</div>

<div class="card" style="margin-top:16px" data-testid="egg-import-preview">
  <div class="between">
    <div><h2><?= h($egg_name) ?></h2><p class="muted"><?= h($egg_data['description'] ?? 'No description provided.') ?></p></div>
    <span class="chip accent"><?= h(game_meta($egg_game)['label']) ?></span>
  </div>
  <?php if ($existing_egg): ?>
    <div class="flash <?= hash_equals($existing_egg['source_hash'], $egg_hash) ? 'success' : 'error' ?>" data-testid="egg-update-state">
      <?= hash_equals($existing_egg['source_hash'], $egg_hash) ? 'This imported egg matches the current source.' : 'Update available: this import differs from the stored source.' ?>
    </div>
    <?php if (!hash_equals($existing_egg['source_hash'], $egg_hash)): ?>
      <table class="table"><thead><tr><th>Field</th><th>Current</th><th>Incoming</th></tr></thead><tbody>
        <tr><td>Game</td><td><?= h($existing_egg['game']) ?></td><td><?= h($egg_game) ?></td></tr>
        <tr><td>Startup</td><td><code><?= h($existing_egg['start_command']) ?></code></td><td><code><?= h($egg_start ?: 'bash /start.sh') ?></code></td></tr>
        <tr><td>Docker image</td><td><code><?= h($existing_egg['docker_image'] ?? '') ?></code></td><td><code><?= h(is_array($egg_images) ? (string)(reset($egg_images) ?: '') : '') ?></code></td></tr>
      </tbody></table>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted">This will create a new egg. Game detection is inferred from the egg name and JSON.</p>
  <?php endif; ?>
  <div class="form-group"><label>Startup command</label><code><?= h($egg_start ?: 'bash /start.sh') ?></code></div>
  <div class="form-group"><label>Docker images</label><pre><?= h(json_encode($egg_images, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></div>
  <div class="form-group"><label>Environment variables (<?= count($egg_variables) ?>)</label>
    <?php foreach ($egg_variables as $variable): ?><div class="mono muted"><?= h($variable['env_variable'] ?? '') ?> = <?= h($variable['default_value'] ?? '') ?></div><?php endforeach; ?>
  </div>
  <form method="post" action="/eggs/import" data-testid="egg-import-confirm-form">
    <?= csrf_field() ?><textarea name="egg_json" hidden><?= h($egg_json) ?></textarea>
    <button class="btn btn-primary" data-testid="confirm-egg-import"><?= $existing_egg && !hash_equals($existing_egg['source_hash'], $egg_hash) ? 'Apply Egg Update' : ($existing_egg ? 'Import Unchanged Egg' : 'Confirm Egg Import') ?></button>
  </form>
</div>