<?php $filter = $filter ?? 'all'; ?>
<div class="between">
  <div>
    <div class="section-title" style="margin:0">Installer</div>
    <h1 data-testid="page-title">Server Installations</h1>
    <p class="muted">Pick a server variant, mod loader, or modpack source. From vanilla Paper to CurseForge, Forge, Fabric, CS2 Metamod, Rust Oxide — everything is one click.</p>
  </div>
</div>

<div class="row" style="margin:14px 0" data-testid="mods-filters">
  <?php foreach ([['all','All'],['minecraft-java','Minecraft Java'],['minecraft-bedrock','Bedrock'],['cs2','CS2'],['rust','Rust']] as $f): ?>
    <a href="<?= h(apex_base_path()) ?>/mods?game=<?= $f[0] ?>" class="chip <?= $filter === $f[0] ? 'accent' : '' ?>" data-testid="mods-filter-<?= $f[0] ?>" style="padding:8px 14px;text-decoration:none"><?= $f[1] ?></a>
  <?php endforeach; ?>
</div>

<?php foreach ($by_game as $game => $rows): $g = game_meta($game); ?>
  <div class="section-title" style="display:flex;align-items:center;gap:10px">
    <span class="dot" style="color:<?= $g['color'] ?>"></span><?= h($g['label']) ?>
  </div>
  <div class="bento" style="margin-bottom:20px" data-testid="mods-grid-<?= h($game) ?>">
    <?php foreach ($rows as $l): ?>
      <div class="card" style="border-left: 3px solid <?= h($l['accent_color']) ?>" data-testid="mod-card-<?= (int)$l['id'] ?>">
        <div class="between" style="margin-bottom:10px">
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:40px;height:40px;display:grid;place-items:center;border-radius:8px;background:<?= h($l['accent_color']) ?>18;border:1px solid <?= h($l['accent_color']) ?>50;font-family:var(--font-mono);font-size:22px;color:<?= h($l['accent_color']) ?>">
              <?= h($l['logo_char']) ?>
            </div>
            <div>
              <h3 style="margin:0"><?= h($l['name']) ?></h3>
              <span class="chip" style="margin-top:4px;text-transform:capitalize"><?= h(str_replace('_',' ',$l['category'])) ?></span>
            </div>
          </div>
          <?php if ($l['popular']): ?><span class="chip accent">★ POPULAR</span><?php endif; ?>
        </div>
        <p class="muted" style="min-height:36px"><?= h($l['tagline']) ?></p>
        <div class="row" style="margin-top:10px">
          <a href="<?= h(apex_base_path()) ?>/mods/<?= (int)$l['id'] ?>" class="btn btn-sm" data-testid="mod-details-<?= (int)$l['id'] ?>">Details</a>
          <a href="<?= h(apex_base_path()) ?>/mods/<?= (int)$l['id'] ?>#deploy" class="btn btn-sm btn-primary" data-testid="mod-deploy-<?= (int)$l['id'] ?>">▶ Install</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php if (empty($by_game)): ?>
  <div class="card"><h3>No loaders found</h3></div>
<?php endif; ?>
