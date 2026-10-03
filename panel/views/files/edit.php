<div class="between">
  <div>
    <div class="section-title" style="margin:0">Edit File · <?= h($s['name']) ?></div>
    <h1 data-testid="page-title"><?= h($rel) ?></h1>
  </div>
  <div class="row">
    <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files?path=<?= urlencode(dirname($rel)==='.'?'':dirname($rel)) ?>" class="btn">← Back</a>
    <a href="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files/download?path=<?= urlencode($rel) ?>" class="btn btn-sm">⬇ Download</a>
  </div>
</div>

<form method="post" action="<?= h(apex_base_path()) ?>/servers/<?= (int)$s['id'] ?>/files/save" class="card" style="margin-top:14px" data-testid="file-edit-form">
  <?= csrf_field() ?>
  <input type="hidden" name="path" value="<?= h($rel) ?>">
  <textarea name="content" rows="24" spellcheck="false" data-testid="file-content" style="width:100%;background:#05070c;border:1px solid var(--border);border-radius:8px;padding:12px;color:#d1e6ff;font-family:var(--font-mono);font-size:13px;line-height:1.6"><?= h($content) ?></textarea>
  <div class="between" style="margin-top:12px">
    <p class="mono muted">Ctrl+S to save · <?= strlen($content) ?> chars</p>
    <button class="btn btn-primary" data-testid="save-file-btn">💾 Save</button>
  </div>
</form>
<script>
document.querySelector('textarea').addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); e.target.form.submit(); }
});
</script>
