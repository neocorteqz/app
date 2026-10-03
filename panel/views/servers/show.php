<?php $g = game_meta($s['game']); ?>
<div class="between">
  <div>
    <div class="section-title" style="margin:0"><?= h($g['label']) ?><?php if (!empty($s['egg_name'])): ?> · <?= h($s['egg_name']) ?><?php endif; ?></div>
    <h1 data-testid="server-name"><?= h($s['name']) ?> <span class="status status-<?= h($s['status']) ?>" data-testid="server-status"><?= strtoupper($s['status']) ?></span></h1>
    <p class="mono muted">◉ <?= h($s['node_name']) ?> · :<?= (int)$s['port'] ?> · <?= (int)$s['players_online'] ?>/<?= (int)$s['players_max'] ?> players</p>
  </div>
  <div class="row">
    <?php if (in_array($user['role'] ?? '', ['admin','operator'], true)): ?><a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/access" class="btn btn-sm" data-testid="server-access-link">♙ Access</a><?php endif; ?>
    <?php if ($can_view_files): ?><a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files" class="btn" data-testid="tab-files">≡ Files</a><?php endif; ?>
    <?php if ($can_view_backups): ?><a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/backups" class="btn" data-testid="tab-backups">◱ Backups</a><?php endif; ?>
    <?php if ($can_control): ?>
    <form method="post" action="<?= h(apex_base_path()) ?>/servers/action" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="start"><button class="btn btn-primary btn-sm" data-testid="btn-start">▶ Start</button></form>
    <form method="post" action="<?= h(apex_base_path()) ?>/servers/action" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="stop"><button class="btn btn-danger btn-sm" data-testid="btn-stop">■ Stop</button></form>
    <form method="post" action="<?= h(apex_base_path()) ?>/servers/action" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="restart"><button class="btn btn-sm" data-testid="btn-restart">↻ Restart</button></form>
    <form method="post" action="<?= h(apex_base_path()) ?>/servers/action" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="kill"><button class="btn btn-sm" data-testid="btn-kill">☠ Kill</button></form>
    <?php if (($user['role'] ?? '') === 'admin'): ?><form method="post" action="<?= h(apex_base_path()) ?>/servers/delete" data-confirm="Delete this server?" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-danger" data-testid="btn-delete">✕ Delete</button></form><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php
$scheme = is_https_request() ? 'https' : 'http';
$requestHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (!preg_match('/^[A-Za-z0-9.:[\]-]+$/', $requestHost)) $requestHost = 'localhost';
$publicJoinUrl = $scheme . '://' . $requestHost . url('/join/') . h($s['share_token']);
?>
<div class="card" style="margin-top:14px" data-testid="server-share-panel">
  <div class="between">
    <div><div class="section-title" style="margin:0">Share</div><h2>Public join page</h2></div>
    <a class="btn btn-primary" href="<?= h($publicJoinUrl) ?>" target="_blank" rel="noopener" data-testid="server-share-link">↗ Open join page</a>
  </div>
  <div class="between" style="margin-top:10px;gap:8px">
    <code class="mono" data-testid="server-share-url"><?= h($publicJoinUrl) ?></code>
    <button type="button" class="btn btn-sm" data-copy-value="<?= h($publicJoinUrl) ?>" aria-label="Copy public join link" title="Copy public join link">▣</button>
  </div>
</div>

<div class="bento" style="margin-top:16px">
  <div class="card span-3" id="jobs-panel" data-server-id="<?= (int)$s['id'] ?>" data-testid="server-jobs-panel" style="display:none">
    <div class="card-title"><h3>◐ Active Jobs</h3><a href="<?= h(apex_base_path()) ?>/jobs" class="btn btn-sm">All Jobs →</a></div>
    <div id="jobs-list"></div>
  </div>
  <?php if ($can_view_console): ?><div class="card span-3" id="console-mount" data-server-id="<?= (int)$s['id'] ?>" data-testid="console-panel">
    <div class="card-title"><h3>▶ Live Console</h3><span class="chip accent blink">STREAM</span></div>
    <div class="console">
      <div class="log" data-testid="console-log"></div>
      <?php if ($can_control): ?><div class="cmd">
        <span class="prompt">$</span>
        <input type="text" placeholder="type command (say hello, list, help) and press Enter" data-testid="console-input" autocomplete="off">
      </div><?php endif; ?>
    </div>
  </div><?php else: ?><div class="card span-3 muted">Console access is not granted for this server.</div><?php endif; ?>
  <div class="card">
    <div class="card-title"><h3>Resources</h3></div>
    <div class="form-group">
      <div class="mono muted between"><span>CPU</span><span data-cpu-txt><?= round((float)$s['cpu_usage']) ?>%</span></div>
      <div class="meter"><span data-cpu-bar style="width: <?= min(100,(float)$s['cpu_usage']) ?>%"></span></div>
    </div>
    <div class="form-group">
      <div class="mono muted between"><span>RAM</span><span><?= (int)$s['ram_usage_mb'] ?> / <?= (int)$s['ram_mb'] ?> MB</span></div>
      <div class="meter"><span data-ram-bar style="width: <?= min(100,($s['ram_usage_mb']/max(1,$s['ram_mb']))*100) ?>%"></span></div>
    </div>
    <div class="form-group">
      <div class="mono muted between"><span>DISK</span><span><?= (int)$s['disk_gb'] ?> GB</span></div>
      <div class="meter warn"><span style="width:24%"></span></div>
    </div>
    <div class="section-title">Configuration</div>
    <table class="table">
      <tr><td class="muted">Version</td><td class="mono"><?= h($s['version']) ?></td></tr>
      <?php if ($s['game'] === 'minecraft-java'): ?><tr><td class="muted">Minecraft</td><td class="mono"><?= h($s['minecraft_version']) ?></td></tr><?php endif; ?>
      <?php if (!empty($s['loader_name'])): ?>
        <tr><td class="muted">Loader</td>
            <td><span class="chip" style="color:<?= h($s['loader_color']) ?>;border-color:<?= h($s['loader_color']) ?>"><?= h($s['loader_icon']) ?> <?= h($s['loader_name']) ?></span></td></tr>
      <?php endif; ?>
      <?php if (!empty($s['modpack_ref'])): ?>
        <tr><td class="muted">Modpack</td><td class="mono"><?= h($s['modpack_ref']) ?></td></tr>
        <tr><td class="muted">Pack status</td>
            <td>
              <span class="status status-<?= ($s['modpack_status']==='installed'?'online':($s['modpack_status']==='failed'?'offline':'starting')) ?>" data-testid="modpack-status"><?= strtoupper($s['modpack_status'] ?: 'PENDING') ?></span>
              <?php if (in_array($s['modpack_status'], ['pending','failed','none',''], true)): ?>
                <form method="post" action="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/modpack/install" style="display:inline-block;margin-left:8px">
                  <?= csrf_field() ?>
                  <button class="btn btn-sm btn-primary" data-testid="btn-install-pack">⬇ Install pack now</button>
                </form>
              <?php endif; ?>
            </td></tr>
      <?php endif; ?>
      <tr><td class="muted">Port</td><td class="mono"><?= (int)$s['port'] ?></td></tr>
      <tr><td class="muted">Node</td><td class="mono"><?= h($s['node_name']) ?></td></tr>
      <tr><td class="muted">CPU limit</td><td class="mono"><?= (int)$s['cpu_limit'] ?> cores</td></tr>
    </table>
  </div>
</div>

<script>
// Live server-job progress polling
(function () {
  const sid = <?= (int)$s['id'] ?>;
  const panel = document.getElementById('jobs-panel');
  const list = document.getElementById('jobs-list');
  function escapeHtml(s){return String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
  let lastCompletedSeen = new Set();
  async function tick() {
    try {
      const r = await fetch(apexUrl(`/json/servers/${sid}/jobs`));
      if (!r.ok) return;
      const jobs = await r.json();
      // If any modpack_install job just transitioned to completed and we haven't reloaded yet, refresh once
      const justCompleted = jobs.find(j => j.status === 'completed' && j.kind === 'modpack_install' && !lastCompletedSeen.has(j.id));
      jobs.filter(j => j.status === 'completed').forEach(j => lastCompletedSeen.add(j.id));
      if (justCompleted && sessionStorage.getItem('apex-reloaded-job-' + justCompleted.id) !== '1') {
        sessionStorage.setItem('apex-reloaded-job-' + justCompleted.id, '1');
        location.reload();
        return;
      }
      const active = jobs.filter(j => j.status === 'queued' || j.status === 'running');
      if (active.length === 0 && !panel.dataset.wasVisible) {
        panel.style.display = 'none';
        return;
      }
      panel.dataset.wasVisible = '1';
      panel.style.display = 'block';
      list.innerHTML = jobs.slice(0, 3).map(j => {
        const cls = j.status === 'completed' ? 'online' : j.status === 'failed' || j.status === 'cancelled' ? 'offline' : j.status === 'running' ? 'starting' : 'installing';
        const cancelBtn = (j.status === 'queued' || j.status === 'running')
          ? `<form method="post" action="<?= h(apex_base_path()) ?>/jobs/${j.id}/cancel" data-confirm="Cancel this job?" style="margin:0">
               <input type="hidden" name="_csrf" value="${document.querySelector('meta[name=csrf]').content}">
               <button class="btn btn-sm btn-danger" data-testid="cancel-server-job-${j.id}">✕ Cancel</button>
             </form>` : '';
        return `
          <div style="padding:10px 0;border-bottom:1px solid var(--border-soft)" data-testid="job-card-${j.id}">
            <div class="between">
              <div><span class="chip">${j.kind}</span> <span class="mono muted" style="font-size:11px">#${j.id}</span></div>
              <div class="row" style="gap:6px;align-items:center">
                <span class="status status-${cls}" data-testid="server-job-status-${j.id}">${j.status.toUpperCase()}${j.cancel_requested==1 && j.status==='running' ? ' (cancelling…)' : ''}</span>
                ${cancelBtn}
              </div>
            </div>
            <div class="meter" style="margin-top:8px"><span style="width:${j.pct}%"></span></div>
            <div class="mono muted" style="font-size:11px;margin-top:4px" data-testid="server-job-message-${j.id}">${j.pct}% — ${escapeHtml(j.message || '')}</div>
          </div>`;
      }).join('');
    } catch (_) {}
  }
  setInterval(tick, 2000);
  tick();
})();
</script>
