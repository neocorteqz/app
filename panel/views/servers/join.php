<?php $game = game_meta($server['game']); ?>
<main class="card" style="width:min(100%,640px);margin:36px auto;padding:28px" data-testid="public-join-page">
  <div class="between" style="align-items:flex-start">
    <div>
      <div class="section-title" style="margin-top:0">ApexNode · <?= h($game['label']) ?></div>
      <h1 data-testid="join-server-name"><?= h($server['name']) ?></h1>
    </div>
    <span id="join-status" class="status status-<?= h($server['status']) ?>" data-testid="join-server-status"><?= strtoupper(h($server['status'])) ?></span>
  </div>

  <div class="grid-2" style="align-items:center;margin-top:20px">
    <div>
      <div class="section-title">Connect address</div>
      <div class="between" style="gap:8px">
        <code id="join-address" class="mono" data-testid="join-connect-address"><?= h($server['address']) ?></code>
        <button type="button" class="btn btn-sm" data-copy-value="<?= h($server['address']) ?>" aria-label="Copy connect address" title="Copy connect address">▣</button>
      </div>
      <p class="muted" style="margin-top:12px">Players <span id="join-players" data-testid="join-player-count"><?= (int)$server['players_online'] ?>/<?= (int)$server['players_max'] ?></span></p>
      <p class="mono muted" data-testid="join-game"><?= h($game['label']) ?></p>
    </div>
    <div style="text-align:center">
      <img
        src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&amp;data=<?= rawurlencode($server['share_url']) ?>"
        width="240" height="240" alt="QR code linking to this server join page"
        style="max-width:100%;height:auto;background:white;padding:8px;border-radius:8px"
        data-testid="join-qr-code">
      <div class="mono muted" style="font-size:11px;margin-top:6px">Scan to open this join page</div>
    </div>
  </div>
</main>
<script>
(function () {
  const status = document.getElementById('join-status');
  const players = document.getElementById('join-players');
  const address = document.getElementById('join-address');
  async function refresh() {
    try {
      const response = await fetch('/api/public/join/<?= h($server['token']) ?>', { cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      status.textContent = data.status.toUpperCase();
      status.className = 'status status-' + data.status;
      players.textContent = data.players_online + '/' + data.players_max;
      address.textContent = data.address;
    } catch (_) {}
  }
  setInterval(refresh, 5000);
})();
</script>