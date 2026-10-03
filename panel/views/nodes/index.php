<div class="between">
  <div><div class="section-title" style="margin:0">Infrastructure</div><h1 data-testid="page-title">Nodes</h1><p class="muted">Linked Linux daemon hosts.</p></div>
</div>

<div class="grid-2" style="margin-top:16px">
  <div class="card">
    <div class="card-title"><h3>Registered nodes</h3><span class="chip"><?= count($nodes) ?> total</span></div>
    <table class="table" data-testid="nodes-table">
      <thead><tr><th>Name</th><th>Host</th><th>Resources</th><th>Servers</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($nodes as $n): ?>
        <tr>
          <td><?= h($n['name']) ?></td>
          <td class="mono"><?= h($n['ip']) ?>:22</td>
          <td class="mono muted"><?= (int)$n['cpu_cores'] ?>c · <?= round($n['ram_mb']/1024,1) ?>G · <?= (int)$n['disk_gb'] ?>GB</td>
          <td><?= (int)$n['srv_count'] ?></td>
          <td><span class="status status-<?= $n['status'] === 'online' ? 'online':'offline' ?>"><?= strtoupper($n['status']) ?></span></td>
          <td>
            <?php if (($user['role'] ?? '')==='admin' && (int)$n['srv_count']===0): ?>
              <form method="post" action="<?= h(apex_base_path()) ?>/nodes/delete" data-confirm="Remove node?" style="margin:0"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                <button class="btn btn-sm btn-danger">✕</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (($user['role'] ?? '') === 'admin'): ?>
  <div class="card">
    <div class="card-title"><h3>Register new node</h3></div>
    <form method="post" action="<?= h(apex_base_path()) ?>/nodes" data-testid="node-form">
      <?= csrf_field() ?>
      <div class="grid-2">
        <div class="form-group"><label>Name</label><input name="name" required></div>
        <div class="form-group"><label>Hostname</label><input name="hostname" required placeholder="daemon-01"></div>
        <div class="form-group"><label>IP</label><input name="ip" required placeholder="10.0.0.10"></div>
        <div class="form-group"><label>CPU cores</label><input type="number" name="cpu_cores" value="4"></div>
        <div class="form-group"><label>RAM (MB)</label><input type="number" name="ram_mb" value="8192"></div>
        <div class="form-group"><label>Disk (GB)</label><input type="number" name="disk_gb" value="100"></div>
      </div>
      <button class="btn btn-primary" data-testid="node-submit">＋ Register</button>
    </form>
    <div class="section-title">Provisioning</div>
    <p class="muted">SSH the target host and run:</p>
    <pre class="mono" style="background:#05070c;border:1px solid var(--border);border-radius:8px;padding:12px;overflow:auto">curl -fsSL <?= h(($_SERVER['REQUEST_SCHEME'] ?? 'https').'://'.($_SERVER['HTTP_HOST'] ?? '').'/install-daemon.sh') ?> | sudo bash</pre>
  </div>
  <?php endif; ?>
</div>
