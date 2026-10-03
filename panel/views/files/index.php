<?php
function fmt_bytes($b) {
    if ($b < 1024) return $b . ' B';
    if ($b < 1024*1024) return round($b/1024,1) . ' KB';
    if ($b < 1024*1024*1024) return round($b/1024/1024,1) . ' MB';
    return round($b/1024/1024/1024,2) . ' GB';
}
$crumbs = ['' => $s['name']];
if ($path) {
    $acc = '';
    foreach (explode('/', $path) as $p) {
        if (!$p) continue;
        $acc = $acc ? "$acc/$p" : $p;
        $crumbs[$acc] = $p;
    }
}
?>
<div class="between">
  <div>
    <div class="section-title" style="margin:0">Files · <?= h($s['name']) ?></div>
    <h1 data-testid="page-title">File Manager</h1>
    <p class="mono muted" data-testid="crumbs">
      <?php $first = true; foreach ($crumbs as $p => $label): ?>
        <?php if (!$first): ?><span> / </span><?php endif; $first = false; ?>
        <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files?path=<?= urlencode($p) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </p>
  </div>
  <div class="row">
    <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>" class="btn">← Console</a>
    <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/backups" class="btn">◱ Backups</a>
  </div>
</div>

<div class="row" style="margin-top:14px;flex-wrap:wrap;gap:10px" data-testid="file-actions">
  <form method="post" action="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files/touch" class="row" style="gap:6px;margin:0">
    <?= csrf_field() ?><input type="hidden" name="path" value="<?= h($path) ?>">
    <input name="name" placeholder="new-file.txt" required data-testid="new-file-name" style="width:180px">
    <button class="btn btn-sm" data-testid="new-file-btn">＋ File</button>
  </form>
  <form method="post" action="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files/mkdir" class="row" style="gap:6px;margin:0">
    <?= csrf_field() ?><input type="hidden" name="path" value="<?= h($path) ?>">
    <input name="name" placeholder="new-folder" required data-testid="new-folder-name" style="width:180px">
    <button class="btn btn-sm" data-testid="new-folder-btn">＋ Folder</button>
  </form>
  <form method="post" action="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files/upload" enctype="multipart/form-data" class="row" style="gap:6px;margin:0">
    <?= csrf_field() ?><input type="hidden" name="path" value="<?= h($path) ?>">
    <input type="file" name="file" required data-testid="upload-input" style="padding:6px">
    <button class="btn btn-sm" data-testid="upload-btn">⬆ Upload</button>
  </form>
</div>

<div class="card" style="margin-top:14px">
  <table class="table" data-testid="file-list">
    <thead><tr><th></th><th>Name</th><th>Size</th><th>Modified</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
    <?php if ($path): ?>
      <tr><td>↰</td><td><a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files?path=<?= urlencode(dirname($path)==='.'?'':dirname($path)) ?>">.. (up)</a></td><td></td><td></td><td></td></tr>
    <?php endif; ?>
    <?php foreach ($items as $it): ?>
      <tr data-testid="file-row-<?= h($it['name']) ?>">
        <td><?= $it['is_dir'] ? '▣' : '≡' ?></td>
        <td>
          <?php if ($it['is_dir']): ?>
            <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files?path=<?= urlencode($it['rel']) ?>"><b><?= h($it['name']) ?></b></a>
          <?php else: ?>
            <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files/edit?path=<?= urlencode($it['rel']) ?>"><?= h($it['name']) ?></a>
          <?php endif; ?>
        </td>
        <td class="mono muted"><?= $it['is_dir'] ? '—' : fmt_bytes($it['size']) ?></td>
        <td class="mono muted"><?= date('Y-m-d H:i', $it['mtime']) ?></td>
        <td style="text-align:right">
          <?php if (!$it['is_dir']): ?>
            <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files/download?path=<?= urlencode($it['rel']) ?>" class="btn btn-sm">⬇</a>
          <?php endif; ?>
          <form method="post" action="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files/delete" data-confirm="Delete <?= h($it['name']) ?>?" style="display:inline-block;margin:0">
            <?= csrf_field() ?><input type="hidden" name="path" value="<?= h($it['rel']) ?>">
            <button class="btn btn-sm btn-danger" data-testid="delete-<?= h($it['name']) ?>">✕</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($items) && !$path): ?>
      <tr><td colspan="5" class="muted" style="text-align:center;padding:30px" data-testid="empty-files">This folder is empty. Upload a file, create one, or the daemon will seed defaults from your egg on first start.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
