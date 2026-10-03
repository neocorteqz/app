<?php $g = game_meta($l['game']); ?>
<div class="between">
  <div>
    <div class="section-title" style="margin:0"><?= h($g['label']) ?> · <?= ucfirst(str_replace('_',' ',$l['category'])) ?></div>
    <h1 data-testid="page-title" style="display:flex;align-items:center;gap:12px">
      <span style="width:44px;height:44px;display:inline-grid;place-items:center;border-radius:10px;background:<?= h($l['accent_color']) ?>25;border:1px solid <?= h($l['accent_color']) ?>60;color:<?= h($l['accent_color']) ?>;font-family:var(--font-mono);font-size:24px"><?= h($l['logo_char']) ?></span>
      <?= h($l['name']) ?>
    </h1>
    <p class="muted"><?= h($l['tagline']) ?></p>
  </div>
  <a href="<?= h(apex_base_path()) ?>/mods?game=<?= h($l['game']) ?>" class="btn">← Back</a>
</div>

<div class="grid-2" style="margin-top:16px">
  <div class="card">
    <div class="section-title" style="margin-top:0">About</div>
    <p><?= h($l['description']) ?></p>
    <?php if ($l['install_cmd']): ?>
      <div class="section-title">Install command</div>
      <pre class="mono" style="background:#05070c;border:1px solid var(--border);border-radius:8px;padding:12px;overflow:auto"><?= h($l['install_cmd']) ?></pre>
    <?php endif; ?>
    <?php if ($l['requires_pack_id']): ?>
      <div class="flash success" style="margin-top:14px">
        This installer requires a modpack reference — you'll enter the CurseForge / Modrinth / FTB slug or numeric ID during install.
      </div>
    <?php endif; ?>
  </div>

  <form id="deploy" method="post" action="<?= h(apex_base_path()) ?>/servers" class="card" data-testid="mod-deploy-form">
    <?= csrf_field() ?>
    <input type="hidden" name="game" value="<?= h($l['game']) ?>">
    <input type="hidden" name="loader_id" value="<?= (int)$l['id'] ?>">
    <div class="section-title" style="margin-top:0">Install onto a new server</div>
    <div class="form-group"><label>Server Name</label><input name="name" required placeholder="My <?= h($l['name']) ?>" data-testid="input-name"></div>
    <?php if ($l['requires_pack_id']): ?>
      <div class="form-group">
        <label>Modpack slug or ID</label>
        <input name="modpack_ref" required placeholder="e.g. all-the-mods-9 or 520914" data-testid="input-modpack-ref">
        <p class="mono muted" style="margin-top:6px">The panel records this reference and the daemon runs <code><?= h($l['install_cmd']) ?></code> on first start.</p>
      </div>
    <?php endif; ?>
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
      <div class="form-group"><label>CPU cores</label><input type="number" name="cpu_limit" value="2"></div>
      <div class="form-group"><label>RAM (MB)</label><input type="number" name="ram_mb" value="4096" step="256"></div>
    </div>
    <div class="between">
      <p class="mono muted">// files seed on first daemon start</p>
      <button class="btn btn-primary" data-testid="submit-mod-deploy">▶ Create Server</button>
    </div>
  </form>
</div>
