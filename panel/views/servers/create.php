<div class="between">
  <div><div class="section-title" style="margin:0">Deploy</div><h1>New Server</h1></div>
  <a href="<?= h(apex_base_path()) ?>/servers" class="btn">← Back</a>
</div>

<form method="post" action="<?= h(apex_base_path()) ?>/servers" class="card" style="max-width:820px;margin-top:16px" data-testid="deploy-form">
  <?= csrf_field() ?>
  <div class="section-title" style="margin-top:0">1. Identity</div>
  <div class="form-group"><label>Server Name</label><input type="text" name="name" required placeholder="Survival SMP" data-testid="input-name"></div>

  <div class="section-title">2. Egg (optional template)</div>
  <div class="form-group">
    <label>Pick an egg — or leave blank for a bare server</label>
    <select name="egg_id" data-testid="input-egg" onchange="var opt=this.options[this.selectedIndex];if(opt.dataset.game){document.querySelector('[name=game]').value=opt.dataset.game;}">
      <option value="">— None (choose game below) —</option>
      <?php foreach ($eggs as $e): ?>
        <option value="<?= (int)$e['id'] ?>" data-game="<?= h($e['game']) ?>"><?= h($e['name']) ?> · <?= h($e['game']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="mono muted" style="margin-top:6px">Browse the full <a href="<?= h(apex_base_path()) ?>/eggs">Egg Marketplace →</a></p>
  </div>

  <div class="section-title">3. Game & Node</div>
  <div class="grid-2">
    <div class="form-group">
      <label>Game</label>
      <select name="game" id="game-select" required data-testid="input-game">
        <option value="minecraft-java">Minecraft: Java Edition</option>
        <option value="minecraft-bedrock">Minecraft: Bedrock Edition</option>
        <option value="cs2">Counter-Strike 2</option>
        <option value="rust">Rust</option>
      </select>
    </div>
    <div class="form-group">
      <label>Node</label>
      <select name="node_id" required data-testid="input-node">
        <?php foreach ($nodes as $n): ?>
          <option value="<?= (int)$n['id'] ?>"><?= h($n['name']) ?> — <?= h($n['ip']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="form-group" id="minecraft-version-wrap" data-testid="minecraft-version-wrap">
    <label for="minecraft-version">Minecraft Java version</label>
    <select name="minecraft_version" id="minecraft-version" data-testid="minecraft-version-picker">
      <option value="1.21.8">1.21.8</option>
      <option value="1.21.7">1.21.7</option>
      <option value="1.21.6">1.21.6</option>
      <option value="1.21.5">1.21.5</option>
      <option value="1.21.4">1.21.4</option>
      <option value="1.20.6">1.20.6</option>
      <option value="1.20.4">1.20.4</option>
    </select>
    <span class="mono muted" id="minecraft-version-source" aria-live="polite">Available releases are refreshed from Mojang when online.</span>
  </div>

  <div class="section-title">4. Server Installation (loader / modpack)</div>
  <p class="mono muted" style="margin-top:-6px">Choose vanilla, a loader (Paper, Forge, Fabric…), or a modpack source (CurseForge, Modrinth). Leave "None" to install the raw egg.</p>
  <div class="row" id="loader-picker" data-testid="loader-picker" style="gap:8px;margin-bottom:8px"></div>
  <input type="hidden" name="loader_id" id="loader-input" value="">
  <div class="form-group" id="modpack-ref-wrap" style="display:none">
    <label>Modpack slug or ID</label>
    <input name="modpack_ref" id="modpack-ref" placeholder="e.g. all-the-mods-9" data-testid="input-modpack-ref">
    <div id="modpack-preview" data-testid="modpack-preview" style="margin-top:10px"></div>
  </div>

  <div class="section-title">5. Resources</div>
  <div class="grid-3">
    <div class="form-group"><label>Port</label><input type="number" name="port" value="25565" data-testid="input-port"></div>
    <div class="form-group"><label>CPU cores</label><input type="number" name="cpu_limit" value="2" min="1" max="32" data-testid="input-cpu"></div>
    <div class="form-group"><label>RAM (MB)</label><input type="number" name="ram_mb" value="2048" step="256" data-testid="input-ram"></div>
    <div class="form-group"><label>Disk (GB)</label><input type="number" name="disk_gb" value="10" min="1" data-testid="input-disk"></div>
  </div>

  <div class="between" style="margin-top:8px">
    <p class="mono muted">// resources will be reserved on the selected node.</p>
    <button class="btn btn-primary" data-testid="submit-deploy">▶ Deploy Server</button>
  </div>
</form>

<script>
(function () {
  const gameSel = document.getElementById('game-select');
  const versionWrap = document.getElementById('minecraft-version-wrap');
  const versionSelect = document.getElementById('minecraft-version');
  const picker = document.getElementById('loader-picker');
  const loaderInput = document.getElementById('loader-input');
  const packWrap = document.getElementById('modpack-ref-wrap');
  const packInput = document.getElementById('modpack-ref');
  const preview = document.getElementById('modpack-preview');
  let currentSource = null;

  function syncVersionVisibility() {
    versionWrap.hidden = gameSel.value !== 'minecraft-java';
    versionSelect.required = gameSel.value === 'minecraft-java';
  }

  async function loadMinecraftVersions() {
    try {
      const response = await fetch('https://launchermeta.mojang.com/mc/game/version_manifest_v2.json', { cache: 'no-cache' });
      if (!response.ok) return;
      const manifest = await response.json();
      const releases = manifest.versions.filter(version => version.type === 'release').slice(0, 24);
      if (!releases.length) return;
      const selected = versionSelect.value;
      versionSelect.replaceChildren(...releases.map(version => {
        const option = document.createElement('option');
        option.value = version.id;
        option.textContent = version.id;
        return option;
      }));
      versionSelect.value = releases.some(version => version.id === selected) ? selected : manifest.latest.release;
      document.getElementById('minecraft-version-source').textContent = 'Release list provided by Mojang.';
    } catch (_) {}
  }

  async function refresh() {
    const game = gameSel.value;
    picker.innerHTML = '<span class="chip">Loading…</span>';
    const r = await fetch(apexUrl('/json/loaders?game=') + encodeURIComponent(game));
    const data = await r.json();
    picker.innerHTML = '';
    picker.appendChild(makeChip({id: '', slug: 'none', name: 'None (raw)', category: 'vanilla', logo_char: '∅', accent_color: '#64748B', requires_pack_id: 0}, true));
    data.forEach((l) => picker.appendChild(makeChip(l, false)));
    loaderInput.value = '';
    packWrap.style.display = 'none';
    packInput.required = false;
    preview.innerHTML = '';
    currentSource = null;
  }
  function makeChip(l, selected) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'chip' + (selected ? ' accent' : '');
    btn.dataset.testid = 'loader-chip-' + (l.slug || 'none');
    btn.style.padding = '8px 12px';
    btn.style.cursor = 'pointer';
    btn.style.borderColor = l.accent_color;
    btn.style.color = l.accent_color;
    btn.innerHTML = `<span style="margin-right:6px">${l.logo_char}</span>${l.name}` + (l.popular ? ' ★' : '');
    btn.addEventListener('click', () => {
      loaderInput.value = l.id || '';
      picker.querySelectorAll('button').forEach((b) => b.classList.remove('accent'));
      btn.classList.add('accent');
      if (l.requires_pack_id) {
        packWrap.style.display = 'block';
        packInput.required = true;
        currentSource = l.slug === 'modrinth' ? 'modrinth' : (l.slug === 'ftb' ? 'ftb' : (l.slug === 'workshop' ? 'workshop' : 'curseforge'));
      } else {
        packWrap.style.display = 'none';
        packInput.required = false;
        currentSource = null;
      }
      preview.innerHTML = '';
    });
    return btn;
  }

  // Live preview
  let debounce = null;
  packInput.addEventListener('input', () => {
    if (!currentSource || currentSource === 'workshop') return;
    const ref = packInput.value.trim();
    clearTimeout(debounce);
    if (!ref) { preview.innerHTML = ''; return; }
    debounce = setTimeout(() => resolvePreview(ref), 500);
  });
  async function resolvePreview(ref) {
    preview.innerHTML = '<div class="chip">Resolving…</div>';
    try {
      const r = await fetch(apexUrl(`/json/modpack/preview?source=${encodeURIComponent(currentSource)}&ref=${encodeURIComponent(ref)}`));
      const j = await r.json();
      if (!j.ok) {
        preview.innerHTML = `<div class="flash error" data-testid="modpack-error">${j.error || 'Not found'}</div>`;
        return;
      }
      const d = j.data;
      const chipsMc = (d.latest_mc || []).slice(0, 4).map(v => `<span class="chip">${v}</span>`).join(' ');
      const chipsLoaders = (d.latest_loaders || []).slice(0, 3).map(v => `<span class="chip accent">${v}</span>`).join(' ');
      const authors = d.authors ? d.authors.join(', ') : (d.team || '');
      preview.innerHTML = `
        <div class="card" style="border-color:var(--accent);padding:14px" data-testid="modpack-preview-card">
          <div class="between" style="margin-bottom:6px">
            <h3 style="margin:0">${escapeHtml(d.title)}</h3>
            <span class="chip accent">${d.source.toUpperCase()}</span>
          </div>
          <p class="muted" style="margin:4px 0">${escapeHtml(d.description || '')}</p>
          <div class="mono muted" style="font-size:11px;margin:8px 0">
            ${d.downloads ? '⬇ ' + Number(d.downloads).toLocaleString() + ' downloads' : ''}
            ${d.latest_version ? ' · v' + escapeHtml(d.latest_version) : ''}
            ${d.latest_file_name ? ' · ' + escapeHtml(d.latest_file_name) : ''}
            ${authors ? ' · by ' + escapeHtml(authors) : ''}
            ${d.files_count ? ' · ' + d.files_count + ' files' : ''}
          </div>
          <div class="row">${chipsLoaders}${chipsMc}</div>
        </div>`;
    } catch (e) {
      preview.innerHTML = `<div class="flash error">${e.message}</div>`;
    }
  }
  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  gameSel.addEventListener('change', refresh);
  gameSel.addEventListener('change', syncVersionVisibility);
  syncVersionVisibility();
  loadMinecraftVersions();
  refresh();
})();
</script>
