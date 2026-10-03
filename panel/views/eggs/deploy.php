<?php $g = game_meta($egg['game']); ?>
<div class="between">
  <div>
    <div class="section-title" style="margin:0">Deploy · <?= h($g['label']) ?></div>
    <h1 data-testid="page-title">Deploy: <?= h($egg['name']) ?></h1>
    <p class="muted"><?= h($egg['tagline']) ?></p>
  </div>
  <a href="<?= h(apex_base_path()) ?>/eggs/<?= (int)$egg['id'] ?>" class="btn">← Back</a>
</div>

<form method="post" action="<?= h(apex_base_path()) ?>/servers" class="card" style="max-width:820px;margin-top:16px" data-testid="egg-deploy-form">
  <?= csrf_field() ?>
  <input type="hidden" name="egg_id" value="<?= (int)$egg['id'] ?>">
  <div class="form-group"><label>Server Name</label><input type="text" name="name" required placeholder="My <?= h($egg['name']) ?>" data-testid="input-name"></div>
  <div class="grid-2">
    <div class="form-group">
      <label>Node</label>
      <select name="node_id" required data-testid="input-node">
        <?php foreach ($nodes as $n): ?>
          <option value="<?= (int)$n['id'] ?>"><?= h($n['name']) ?> — <?= h($n['ip']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Port</label><input type="number" name="port" value="<?= (int)$g['default_port'] ?>" data-testid="input-port"></div>
    <div class="form-group"><label>CPU cores</label><input type="number" name="cpu_limit" value="2" data-testid="input-cpu"></div>
    <div class="form-group"><label>RAM (MB)</label><input type="number" name="ram_mb" value="2048" step="256" data-testid="input-ram"></div>
    <div class="form-group"><label>Disk (GB)</label><input type="number" name="disk_gb" value="10" data-testid="input-disk"></div>
  </div>
  <div class="between" style="margin-top:8px">
    <p class="mono muted">// This egg bundles default files and start command.</p>
    <button class="btn btn-primary" data-testid="submit-deploy">▶ Launch Server</button>
  </div>
</form>
