<div class="between"><div><div class="section-title" style="margin:0">Deployment</div><h1 data-testid="page-title">Install on Linux Server</h1><p class="muted">Install on a bare server or behind cPanel, DirectAdmin, Plesk, or an existing Nginx host.</p></div></div>

<div class="grid-2" style="margin-top:16px">
  <div class="card">
    <div class="section-title" style="margin-top:0">1. Choose your web server</div>
    <div class="row" id="host-picker" data-testid="host-picker" style="flex-wrap:wrap">
      <?php $modes = [
        ['standalone',   'Standalone',  'New bare server; installer manages Nginx'],
        ['apache',       'Apache',      'Existing Apache / httpd server'],
        ['nginx',        'Nginx',       'Existing Nginx reverse proxy'],
        ['caddy',        'Caddy',       'Existing Caddy server; automatic HTTPS'],
        ['cpanel',       'cPanel',      'Installs cPanel Apache vhost includes'],
        ['directadmin',  'DirectAdmin', 'Installs a per-domain CustomBuild include'],
        ['plesk',        'Plesk',       'Installs a per-domain Nginx proxy include'],
        ['other',        'Other',       'Keep server config untouched; show manual proxy steps'],
      ]; foreach ($modes as $m): ?>
        <label class="chip" data-mode="<?= $m[0] ?>" style="padding:10px 14px;cursor:pointer;flex:1;min-width:150px;text-align:center" data-testid="host-mode-<?= $m[0] ?>">
          <input type="radio" name="mode" value="<?= $m[0] ?>" <?= $m[0]==='standalone'?'checked':''?> style="margin-right:6px">
          <b><?= h($m[1]) ?></b><br>
          <span class="mono muted" style="font-size:10px"><?= h($m[2]) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="section-title">2. Pick a port</div>
    <div class="form-group">
      <label>Panel port</label>
      <input type="number" id="panel-port" value="80" min="1" max="65535" data-testid="input-panel-port">
      <p class="mono muted" style="margin-top:6px">Standalone binds publicly. Existing-panel modes bind to 127.0.0.1 only; the selected integration helper configures the reverse proxy.</p>
    </div>
    <div class="grid-2">
      <div class="form-group"><label>Panel domain (optional)</label><input id="panel-domain" placeholder="panel.example.com" data-testid="input-panel-domain"></div>
      <div class="form-group"><label>cPanel / DirectAdmin account</label><input id="panel-user" placeholder="account name" data-testid="input-panel-user"><p class="mono muted">Required with a domain for cPanel or DirectAdmin integration.</p></div>
    </div>
    <div class="section-title">3. Installation locations</div>
    <div class="form-group"><label>Application directory</label><input id="install-dir" value="/opt/apexnode" data-testid="input-install-dir"></div>
    <div class="form-group"><label>Server data and backups directory</label><input id="data-dir" value="/var/lib/apexnode" data-testid="input-data-dir"></div>
    <div class="form-group"><label>Panel integration scripts directory</label><input id="plugin-dir" value="/opt/apexnode-integrations" data-testid="input-plugin-dir"></div>

    <div class="section-title">4. One-liner</div>
    <pre class="mono" style="background:#05070c;border:1px solid var(--border);border-radius:8px;padding:12px;overflow:auto" data-testid="install-cmd">curl -fsSL https://<?= h($host) ?>/install.sh | sudo bash -s -- --port 8443 --coexist standalone</pre>
    <button class="btn btn-primary btn-sm" data-testid="copy-install-btn" onclick="navigator.clipboard.writeText(document.querySelector('[data-testid=install-cmd]').textContent)">⧉ Copy</button>

    <div class="section-title">5. Node daemon (game hosts)</div>
    <pre class="mono" style="background:#05070c;border:1px solid var(--border);border-radius:8px;padding:12px;overflow:auto">curl -fsSL https://<?= h($host) ?>/install-daemon.sh | sudo bash</pre>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0">Reverse-proxy snippet</div>
    <p class="muted" data-testid="snippet-intro">The installer detects an active web server when run directly. Choose the matching option here; its helper validates or updates only its own ApexNode configuration. Supply a domain above to configure it automatically:</p>
    <pre class="mono" id="snippet" data-testid="reverse-proxy-snippet" style="background:#05070c;border:1px solid var(--border);border-radius:8px;padding:12px;overflow:auto;font-size:11px;white-space:pre-wrap"></pre>

    <div class="section-title">What gets installed</div>
    <ul class="mono muted" style="padding-left:18px;font-size:12px">
      <li>Nginx + PHP-FPM only in standalone mode; Apache, Nginx, Caddy, and hosted-panel modes preserve the existing web server</li>
      <li>MariaDB (auto-provisioned DB user & schema)</li>
      <li>Redis (session + cache)</li>
      <li>OpenJDK 17 JRE (for Paper / Purpur / Forge runtimes)</li>
      <li>Systemd units: <b>apex-daemon</b>, <b>apex-backup</b>, <b>apexnode-bot</b></li>
    </ul>

    <div class="section-title">System requirements</div>
    <table class="table">
      <tr><td class="muted">Minimum</td><td>1 vCPU · 1 GB RAM · 10 GB SSD</td></tr>
      <tr><td class="muted">Recommended</td><td>4 vCPU · 8 GB RAM · SSD</td></tr>
      <tr><td class="muted">OS</td><td>Ubuntu 22.04+, Debian 12+</td></tr>
    </table>
  </div>
</div>

<script>
(function () {
  const port = document.getElementById('panel-port');
  const cmd = document.querySelector('[data-testid=install-cmd]');
  const snippet = document.getElementById('snippet');
  const host = '<?= h($host) ?>';
  const domain = document.getElementById('panel-domain');
  const panelUser = document.getElementById('panel-user');
  const installDir = document.getElementById('install-dir');
  const dataDir = document.getElementById('data-dir');
  const pluginDir = document.getElementById('plugin-dir');
  let portWasEdited = false;

  const templates = {
    standalone: () => 'Standalone mode — the installer configures Nginx as the public webserver on the port you chose. No control-panel integration needed.',
    apache: () => `Apache helper: ${pluginDir.value}/apache/install.sh
  Adds a dedicated Apache proxy virtual host and validates configuration before reload. TLS remains managed by your certificate setup.`,
    cpanel: () => `cPanel helper: ${pluginDir.value}/cpanel/install.sh
  Requires the cPanel account username. It writes standard + SSL vhost includes and rebuilds Apache configuration.`,
    plesk: () => `Plesk helper: ${pluginDir.value}/plesk/install.sh
  Writes the domain's Nginx proxy include and asks Plesk to reconfigure that domain.`,
    directadmin: () => `DirectAdmin helper: ${pluginDir.value}/directadmin/install.sh
  Requires the DirectAdmin account username. It writes the domain custom HTTPD include and runs CustomBuild.`,
    nginx: () => `Nginx helper: ${pluginDir.value}/nginx/install.sh
  Writes a per-domain reverse-proxy vhost and validates/reloads Nginx.`,
    caddy: () => `Caddy helper: ${pluginDir.value}/caddy/install.sh
  Adds an imported site fragment, validates Caddyfile, and preserves Caddy-managed HTTPS.`,
    other: () => `Manual proxy instructions: ${pluginDir.value}/other/install.sh
  The installer leaves the detected web server untouched and prints the loopback upstream and required headers.`,
  };

  function refresh() {
    const mode = document.querySelector('input[name=mode]:checked').value;
    if (!portWasEdited) port.value = mode === 'standalone' ? '80' : '8443';
    const p = parseInt(port.value, 10) || 80;
    const quote = (value) => `'${value.replace(/'/g, "'\\''")}'`;
    const args = [`--port ${p}`, `--web-server ${mode}`, `--coexist ${mode}`, `--install-dir ${quote(installDir.value)}`, `--data-dir ${quote(dataDir.value)}`, `--plugin-dir ${quote(pluginDir.value)}`];
    if (domain.value.trim()) args.push(`--domain ${quote(domain.value.trim())}`);
    if (panelUser.value.trim() && ['cpanel', 'directadmin'].includes(mode)) args.push(`--panel-user ${quote(panelUser.value.trim())}`);
    cmd.textContent = `curl -fsSL https://${host}/install.sh | sudo bash -s -- ${args.join(' ')}`;
    snippet.textContent = templates[mode](p);
  }
  document.querySelectorAll('input[name=mode]').forEach(r => r.addEventListener('change', refresh));
  port.addEventListener('input', () => { portWasEdited = true; refresh(); });
  [domain, panelUser, installDir, dataDir, pluginDir].forEach(input => input.addEventListener('input', refresh));
  refresh();
})();
</script>
