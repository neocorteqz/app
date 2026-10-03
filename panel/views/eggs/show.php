<?php $g = game_meta($egg['game']); ?>
<div class="between">
  <div>
    <div class="section-title" style="margin:0"><?= h($g['label']) ?> Template</div>
    <h1 data-testid="page-title"><?= h($egg['name']) ?></h1>
  </div>
  <a href="<?= h(apex_base_path()) ?>/eggs/<?= (int)$egg['id'] ?>/deploy" class="btn btn-primary" data-testid="egg-deploy-cta">▶ Deploy this egg</a>
</div>

<div class="grid-2" style="margin-top:16px">
  <div class="card">
    <div class="section-title" style="margin-top:0">About</div>
    <p><?= h($egg['description']) ?></p>
    <div class="section-title">Start command</div>
    <pre class="mono" style="background:#05070c;border:1px solid var(--border);border-radius:8px;padding:12px;overflow:auto"><?= h($egg['start_command']) ?></pre>
    <?php if ($egg['docker_image']): ?>
      <div class="section-title">Container image</div>
      <p class="mono"><?= h($egg['docker_image']) ?></p>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="section-title" style="margin-top:0">Metadata</div>
    <table class="table">
      <tr><td class="muted">Game</td><td><span class="chip" style="color:<?= $g['color'] ?>;border-color:<?= $g['color'] ?>"><?= h($g['label']) ?></span></td></tr>
      <tr><td class="muted">Author</td><td><?= h($egg['author']) ?></td></tr>
      <tr><td class="muted">Deploys</td><td class="mono"><?= number_format($egg['downloads']) ?></td></tr>
      <tr><td class="muted">Featured</td><td><?= $egg['featured'] ? '★ Yes' : 'No' ?></td></tr>
      <tr><td class="muted">Added</td><td class="mono muted"><?= h($egg['created_at']) ?></td></tr>
    </table>
    <?php if ($egg['default_files']):
      $files = json_decode($egg['default_files'], true) ?: [];
      if ($files): ?>
      <div class="section-title">Bundled files</div>
      <ul class="mono muted" style="padding-left:18px">
        <?php foreach (array_keys($files) as $f): ?><li><?= h($f) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; endif; ?>
  </div>
</div>
