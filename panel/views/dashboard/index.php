<div class="between">
  <div>
    <div class="section-title" style="margin:0">Overview</div>
    <h1 data-testid="page-title">Control Tower</h1>
    <p class="muted mono">// Node fleet status · <?= date('Y-m-d H:i:s') ?></p>
  </div>
  <div class="row">
    <a href="/servers/new" class="btn btn-primary" data-testid="deploy-server-btn">＋ Deploy Server</a>
    <a href="/theme" class="btn">◐ Theme</a>
  </div>
</div>

<div class="bento" style="margin-top:20px">
  <div class="card stat" data-testid="stat-total"><div><div class="label">Servers</div><div class="value"><?= $stats['total'] ?></div></div><div class="delta">+<?= max(0,$stats['total']) ?></div></div>
  <div class="card stat" data-testid="stat-online"><div><div class="label">Online now</div><div class="value" style="color:var(--ok)"><?= $stats['online'] ?></div></div><span class="dot" style="color:var(--ok)"></span></div>
  <div class="card stat" data-testid="stat-nodes"><div><div class="label">Nodes linked</div><div class="value"><?= $stats['nodes'] ?></div></div><div class="chip accent">DAEMON</div></div>
  <div class="card stat" data-testid="stat-players"><div><div class="label">Players in-game</div><div class="value"><?= $stats['players'] ?></div></div><div class="mono muted">Σ live</div></div>
</div>

<div class="between" style="margin-top:22px">
  <div><div class="section-title" style="margin:0">Operations</div><h2>Operational alerts <span class="chip" data-testid="alert-count"><?= count($alerts) ?></span></h2></div>
</div>
<div class="card" data-testid="operational-alerts">
  <?php if (empty($alerts)): ?>
    <div class="between"><span>No active operational alerts.</span><span class="status status-online">HEALTHY</span></div>
  <?php else: ?>
    <?php foreach ($alerts as $alert): ?>
      <div class="between" style="gap:16px;padding:10px 0;border-bottom:1px solid var(--border-soft)">
        <div><strong><?= h($alert['title']) ?></strong><div class="muted" style="margin-top:4px"><?= h($alert['detail']) ?></div></div>
        <div class="row"><span class="status status-<?= $alert['severity']==='error'?'offline':'starting' ?>"><?= strtoupper($alert['severity']) ?></span><a class="btn btn-sm" href="<?= h($alert['url']) ?>">Review</a></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="section-title">Active Fleet</div>
<div id="server-list-mount" class="bento" data-testid="dashboard-servers">
  <?php foreach ($servers as $s): $g = game_meta($s['game']); ?>
  <div class="server-card span-2" data-server-id="<?= (int)$s['id'] ?>" style="--game-color: <?= $g['color'] ?>">
    <div class="game-bar"></div>
    <div class="between">
      <h3><?= h($s['name']) ?><span class="chip"><?= h($g['label']) ?></span></h3>
      <span class="status status-<?= h($s['status']) ?>" data-status><?= strtoupper($s['status']) ?></span>
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
      <a href="/servers/<?= (int)$s['id'] ?>" class="btn btn-sm" data-testid="open-server-<?= (int)$s['id'] ?>">▶ Console</a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (empty($servers)): ?>
    <div class="card span-4" style="text-align:center;padding:40px" data-testid="empty-servers">
      <h3>No servers yet</h3><p class="muted">Deploy your first Minecraft, CS2, or Rust instance.</p>
      <a href="/servers/new" class="btn btn-primary">＋ Deploy Server</a>
    </div>
  <?php endif; ?>
</div>

<div class="section-title">Recent Activity</div>
<div class="card" data-testid="activity-panel">
  <table class="table">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th></tr></thead>
    <tbody>
    <?php foreach ($activity as $a): ?>
      <tr>
        <td class="mono muted"><?= h($a['created_at']) ?></td>
        <td><?= h($a['username'] ?? 'system') ?></td>
        <td><span class="chip"><?= h($a['action']) ?></span></td>
        <td class="mono"><?= h($a['target']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($activity)): ?><tr><td colspan="4" class="muted" style="text-align:center">No activity yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
