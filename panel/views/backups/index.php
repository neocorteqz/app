<?php
function fmt_bytes2($b) {
    if ($b < 1024) return $b . ' B';
    if ($b < 1024*1024) return round($b/1024,1) . ' KB';
    if ($b < 1024*1024*1024) return round($b/1024/1024,1) . ' MB';
    return round($b/1024/1024/1024,2) . ' GB';
}
?>
<div class="between">
  <div>
    <div class="section-title" style="margin:0">Backups · <?= h($s['name']) ?></div>
    <h1 data-testid="page-title">Backups & Restore</h1>
    <p class="muted">Snapshot the server working dir. Local snapshots are stored in <?= h(apex_state_root()) ?>/backups. Optional S3-compatible remote storage.</p>
  </div>
  <div class="row">
    <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>" class="btn">← Console</a>
    <form method="post" action="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/backups/run" style="margin:0">
      <?= csrf_field() ?>
      <button class="btn btn-primary" data-testid="run-backup-btn">▶ Backup Now</button>
    </form>
  </div>
</div>

<div class="grid-2" style="margin-top:14px">
  <form method="post" action="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/backups/schedule" class="card" data-testid="schedule-form">
    <?= csrf_field() ?>
    <div class="section-title" style="margin-top:0">Schedule</div>
    <label style="display:flex;gap:8px;align-items:center;font-family:var(--font-body);text-transform:none;letter-spacing:0;color:var(--text)">
      <input type="checkbox" name="enabled" <?= $sched['enabled'] ? 'checked' : '' ?> data-testid="schedule-enabled"> Enable automatic backups
    </label>
    <div class="grid-2" style="margin-top:14px">
      <div class="form-group">
        <label>Every (minutes)</label>
        <input type="number" name="interval_minutes" min="5" value="<?= (int)$sched['interval_minutes'] ?>" data-testid="schedule-interval">
      </div>
      <div class="form-group">
        <label>Retention (keep last)</label>
        <input type="number" name="retention" min="1" value="<?= (int)$sched['retention'] ?>" data-testid="schedule-retention">
      </div>
    </div>
    <div class="form-group">
      <label>Storage</label>
      <select name="storage" data-testid="schedule-storage">
        <option value="local" <?= ($sched['storage'] ?? 'local')==='local'?'selected':''?>>Local disk (<?= h(apex_state_root()) ?>/backups)</option>
        <option value="s3" <?= ($sched['storage'] ?? '')==='s3'?'selected':''?>>S3-compatible remote (Backblaze B2, AWS S3, Wasabi)</option>
      </select>
    </div>
    <div class="section-title">S3 credentials (optional)</div>
    <div class="grid-2">
      <div class="form-group"><label>Bucket</label><input name="s3_bucket" value="<?= h($sched['s3_bucket'] ?? '') ?>" data-testid="s3-bucket"></div>
      <div class="form-group"><label>Endpoint (for B2/Wasabi)</label><input name="s3_endpoint" value="<?= h($sched['s3_endpoint'] ?? '') ?>" placeholder="https://s3.us-west-002.backblazeb2.com" data-testid="s3-endpoint"></div>
      <div class="form-group"><label>Access Key</label><input name="s3_access_key" value="<?= h($sched['s3_access_key'] ?? '') ?>" data-testid="s3-key"></div>
      <div class="form-group"><label>Secret Key</label><input type="password" name="s3_secret_key" value="" placeholder="<?= isset($sched['id']) ? 'Stored; leave blank to keep' : 'Enter secret key' ?>" autocomplete="new-password" data-testid="s3-secret"></div>
    </div>
    <div class="between">
      <p class="mono muted">Last run: <?= h($sched['last_run'] ?: 'never') ?></p>
      <button class="btn btn-primary" data-testid="save-schedule-btn">💾 Save Schedule</button>
    </div>
  </form>

  <div class="card">
    <div class="section-title" style="margin-top:0">Snapshots (<?= count($backups) ?>)</div>
    <table class="table" data-testid="backups-table">
      <thead><tr><th>Name</th><th>Size</th><th>Storage</th><th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td class="mono" style="font-size:11px"><?= h($b['name']) ?></td>
          <td class="mono muted"><?= fmt_bytes2((int)$b['size_bytes']) ?></td>
          <td><span class="chip"><?= strtoupper($b['storage']) ?></span></td>
          <td><span class="status status-<?= $b['status']==='completed'?'online':($b['status']==='failed'?'offline':'starting') ?>"><?= strtoupper($b['status']) ?></span></td>
          <td class="mono muted"><?= h($b['created_at']) ?></td>
          <td style="text-align:right">
            <?php if ($b['status'] === 'completed'): ?>
              <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/backups/download?backup_id=<?= (int)$b['id'] ?>" class="btn btn-sm">⬇</a>
              <form method="post" action="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/backups/restore" data-confirm="Overwrite server files with this backup? The server must be stopped." style="display:inline-block;margin:0">
                <?= csrf_field() ?><input type="hidden" name="backup_id" value="<?= (int)$b['id'] ?>">
                <input name="confirm_restore" required pattern="RESTORE" placeholder="Type RESTORE" aria-label="Type RESTORE to confirm" style="width:120px">
                <button class="btn btn-sm btn-primary" data-testid="restore-<?= (int)$b['id'] ?>">↩ Restore</button>
              </form>
            <?php endif; ?>
            <form method="post" action="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/backups/delete" data-confirm="Delete backup?" style="display:inline-block;margin:0">
              <?= csrf_field() ?><input type="hidden" name="backup_id" value="<?= (int)$b['id'] ?>">
              <button class="btn btn-sm btn-danger" data-testid="delete-backup-<?= (int)$b['id'] ?>">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($backups)): ?>
        <tr><td colspan="6" class="muted" style="text-align:center;padding:30px" data-testid="empty-backups">No snapshots yet. Hit "Backup Now" to create your first.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
