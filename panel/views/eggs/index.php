<?php $filter = $filter ?? 'all'; ?>
<div class="between">
  <div>
    <div class="section-title" style="margin:0">Marketplace</div>
    <h1 data-testid="page-title">Egg Marketplace</h1>
    <p class="muted">Community-crafted server templates. One-tap deploy — no startup commands to memorize.</p>
  </div>
</div>

<?php if (($user['role'] ?? '') !== 'viewer'): ?>
<div class="card" style="margin:16px 0">
  <div class="card-title"><h3>Import Pterodactyl egg JSON</h3></div>
  <form method="post" action="<?= h(apex_base_path()) ?>/eggs/preview" data-testid="pterodactyl-import-form">
    <?= csrf_field() ?>
    <div class="form-group">
      <label>Egg JSON</label>
      <textarea name="egg_json" rows="8" placeholder='{"name":"Paper","startup":"java -jar server.jar","docker_images":{"java":"ghcr.io/pterodactyl/yolks:java_21"},"variables":[]}' required></textarea>
    </div>
    <button class="btn btn-primary" data-testid="pterodactyl-import-submit">Review Import</button>
  </form>
</div>
<?php endif; ?>

<div class="row" style="margin:14px 0" data-testid="egg-filters">
  <?php foreach ([['all','All'],['minecraft-java','Minecraft Java'],['minecraft-bedrock','Bedrock'],['cs2','CS2'],['rust','Rust']] as $f): ?>
    <a href="<?= h(apex_base_path()) ?>/eggs?game=<?= $f[0] ?>" class="chip <?= $filter === $f[0] ? 'accent' : '' ?>" data-testid="egg-filter-<?= $f[0] ?>" style="padding:8px 14px;text-decoration:none"><?= $f[1] ?></a>
  <?php endforeach; ?>
</div>

<div class="bento" data-testid="egg-grid">
  <?php foreach ($eggs as $egg): $g = game_meta($egg['game']); ?>
    <div class="card" style="border-left: 3px solid <?= $g['color'] ?>" data-testid="egg-card-<?= (int)$egg['id'] ?>">
      <div class="between" style="margin-bottom:8px">
        <span class="chip" style="color:<?= $g['color'] ?>;border-color:<?= $g['color'] ?>"><?= h($g['label']) ?></span>
        <?php if ($egg['featured']): ?><span class="chip accent">★ FEATURED</span><?php endif; ?>
      </div>
      <h3><?= h($egg['name']) ?></h3>
      <p class="muted" style="min-height:44px"><?= h($egg['tagline']) ?></p>
      <div class="mono muted" style="font-size:11px;margin-bottom:12px">
        ⬇ <?= number_format($egg['downloads']) ?> deploys · by <?= h($egg['author']) ?>
      </div>
      <div class="row">
        <a href="<?= h(apex_base_path()) ?>/eggs/<?= (int)$egg['id'] ?>" class="btn btn-sm" data-testid="egg-details-<?= (int)$egg['id'] ?>">Details</a>
        <a href="<?= h(apex_base_path()) ?>/eggs/<?= (int)$egg['id'] ?>/deploy" class="btn btn-sm btn-primary" data-testid="egg-deploy-<?= (int)$egg['id'] ?>">▶ Deploy</a>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (empty($eggs)): ?>
    <div class="card span-4" style="text-align:center;padding:40px"><h3>No eggs yet</h3></div>
  <?php endif; ?>
</div>
