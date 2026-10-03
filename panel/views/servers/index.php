<div class="between">
  <div><div class="section-title" style="margin:0">Fleet</div><h1 data-testid="page-title">Servers</h1></div>
  <a href="<?= h(apex_base_path()) ?>/servers/new" class="btn btn-primary" data-testid="deploy-server-btn">＋ Deploy Server</a>
</div>

<div id="server-list-mount" class="bento" style="margin-top:16px" data-testid="server-list">
<?php foreach ($servers as $s): $g = game_meta($s['game']); ?>
  <div class="server-card span-2" data-server-id="<?= (int)$s['id'] ?>" style="--game-color: <?= $g['color'] ?>">
    <div class="game-bar"></div>
    <div class="between">
      <h3><?= h($s['name']) ?><span class="chip"><?= h($g['label']) ?></span></h3>
      <span class="status status-<?= h($s['status']) ?>" data-status data-testid="server-status-<?= (int)$s['id'] ?>"><?= strtoupper($s['status']) ?></span>
    </div>
    <div class="meta">
      <span>◉ <?= h($s['node_name']) ?></span>
      <span>: <?= (int)$s['port'] ?></span>
      <span data-players><?= (int)$s['players_online'] ?>/<?= (int)$s['players_max'] ?> players</span>
    </div>
    <div>
      <div class="mono muted" style="display:flex;justify-content:space-between"><span>CPU</span><span><?= round((float)$s['cpu_usage']) ?>%</span></div>
      <div class="meter"><span data-cpu-bar style="width: <?= min(100,(float)$s['cpu_usage']) ?>%"></span></div>
    </div>
    <div>
      <div class="mono muted" style="display:flex;justify-content:space-between"><span>RAM</span><span><?= (int)$s['ram_usage_mb'] ?> / <?= (int)$s['ram_mb'] ?> MB</span></div>
      <div class="meter"><span data-ram-bar style="width: <?= min(100,($s['ram_usage_mb']/max(1,$s['ram_mb']))*100) ?>%"></span></div>
    </div>
    <div class="actions">
      <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>" class="btn btn-sm" data-testid="open-server-<?= (int)$s['id'] ?>">Console →</a>
      <?php if ($s['status'] !== 'online'): ?>
        <form method="post" action="<?= h(apex_base_path()) ?>/servers/action" style="margin:0"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="action" value="start">
          <button class="btn btn-sm btn-primary" data-testid="start-server-<?= (int)$s['id'] ?>">▶ Start</button>
        </form>
      <?php else: ?>
        <form method="post" action="<?= h(apex_base_path()) ?>/servers/action" style="margin:0"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="action" value="stop">
          <button class="btn btn-sm btn-danger" data-testid="stop-server-<?= (int)$s['id'] ?>">■ Stop</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($servers)): ?>
  <div class="card span-4" style="text-align:center;padding:40px" data-testid="empty-servers">
    <h3>No servers deployed</h3><p class="muted">Provision your first game server to see it here.</p>
    <a href="<?= h(apex_base_path()) ?>/servers/new" class="btn btn-primary">＋ Deploy Server</a>
  </div>
<?php endif; ?>
</div>
