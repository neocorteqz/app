<div class="between">
  <div><div class="section-title" style="margin:0">Integration</div><h1 data-testid="page-title">Discord Bot</h1><p class="muted">Connect the ApexNode Discord bot for status + start/stop/restart slash commands.</p></div>
  <span class="status status-<?= $status === 'connected' ? 'online' : ($status==='failed'?'offline':'offline') ?>" data-testid="discord-status"><?= strtoupper($status) ?></span>
</div>

<div class="grid-2" style="margin-top:16px">
  <form method="post" action="<?= h(apex_base_path()) ?>/discord" class="card" data-testid="discord-form">
    <?= csrf_field() ?>
    <div class="section-title" style="margin-top:0">Credentials</div>
    <div class="form-group">
      <label>Bot Token</label>
      <input type="password" name="token" value="" autocomplete="new-password" placeholder="MTA1…" data-testid="input-token">
      <p class="mono muted" style="margin-top:6px">Create a bot at <a href="https://discord.com/developers/applications" target="_blank" rel="noopener noreferrer">discord.com/developers</a>, copy the token, and invite it with the applications.commands scope. Set the allowed guild ID; lifecycle commands require Manage Server permission there. Leave the token field blank to keep the saved token.</p>
    </div>
    <div class="grid-2">
      <div class="form-group"><label>Guild ID</label><input name="guild" value="<?= h($guild) ?>" data-testid="input-guild"></div>
      <div class="form-group"><label>Status Channel ID</label><input name="channel" value="<?= h($channel) ?>" data-testid="input-channel"></div>
    </div>
    <div class="form-group"><label>Command Prefix</label><input name="prefix" value="<?= h($prefix) ?>" data-testid="input-prefix"></div>
    <div class="row">
      <button class="btn btn-primary" data-testid="discord-save">💾 Save</button>
    </div>
  </form>

  <div class="card">
    <div class="section-title" style="margin-top:0">Test connection</div>
    <p class="muted">Ping Discord to verify the bot identity is valid.</p>
    <form method="post" action="<?= h(apex_base_path()) ?>/discord/test"><?= csrf_field() ?>
      <button class="btn" data-testid="discord-test">◈ Test Bot Token</button>
    </form>

    <div class="section-title">Supported commands</div>
    <table class="table">
      <tr><td class="mono">/status &lt;server&gt;</td><td class="muted">Show live server status + player count.</td></tr>
      <tr><td class="mono">/start &lt;server&gt;</td><td class="muted">Boot the game server (operator role).</td></tr>
      <tr><td class="mono">/stop &lt;server&gt;</td><td class="muted">Gracefully shutdown.</td></tr>
      <tr><td class="mono">/restart &lt;server&gt;</td><td class="muted">Restart the game process.</td></tr>
    </table>

    <div class="section-title">Run the bot daemon</div>
    <pre class="mono" style="background:#05070c;border:1px solid var(--border);border-radius:8px;padding:12px;overflow:auto">cd &lt;your-panel-directory&gt;/discord-bot
pip install -r requirements.txt
DISCORD_BOT_TOKEN=… DISCORD_GUILD_ID=… python bot.py</pre>
  </div>
</div>
