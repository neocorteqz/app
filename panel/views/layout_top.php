<?php $current = substr($_SERVER['REQUEST_URI'] ?? '/', strlen(apex_base_path())); ?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= h($theme['mode']) ?>">
<head>
  <script>const apexBase = <?= json_encode(apex_base_path(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; const apexUrl = path => apexBase + path;</script>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#090A0F">
  <meta name="csrf" content="<?= h(csrf()) ?>">
  <title><?= isset($title) ? h($title) . ' — ' : '' ?>ApexNode</title>
  <link rel="manifest" href="<?= h(apex_base_path()) ?>/manifest.webmanifest">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&family=Bricolage+Grotesque:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(apex_base_path()) ?>/assets/app.css">
  <link rel="stylesheet" href="<?= h(apex_base_path()) ?>/theme.css">
</head>
<body>
<?php if ($user): ?>
<div class="shell">
  <aside class="sidebar" data-testid="sidebar">
    <div class="brand">
      <div class="logo" data-testid="brand-logo">◈</div>
      <div>
        <h1>ApexNode</h1>
        <small>CTRL // TOWER</small>
      </div>
    </div>
    <nav class="nav" data-testid="sidebar-nav">
      <div class="section">Operations</div>
      <a href="<?= h(apex_base_path()) ?>/dashboard" class="<?= str_starts_with($current, '/dashboard') ? 'active':'' ?>" data-testid="nav-dashboard">◱ Dashboard</a>
      <a href="<?= h(apex_base_path()) ?>/servers" class="<?= str_starts_with($current, '/servers') ? 'active':'' ?>" data-testid="nav-servers">▶ Servers</a>
      <a href="<?= h(apex_base_path()) ?>/eggs" class="<?= str_starts_with($current, '/eggs') ? 'active':'' ?>" data-testid="nav-eggs">◈ Egg Marketplace</a>
      <a href="<?= h(apex_base_path()) ?>/mods" class="<?= str_starts_with($current, '/mods') ? 'active':'' ?>" data-testid="nav-mods">⚙ Installations</a>
      <a href="<?= h(apex_base_path()) ?>/jobs" class="<?= str_starts_with($current, '/jobs') ? 'active':'' ?>" data-testid="nav-jobs">◐ Jobs</a>
      <a href="<?= h(apex_base_path()) ?>/nodes" class="<?= str_starts_with($current, '/nodes') ? 'active':'' ?>" data-testid="nav-nodes">◉ Nodes</a>
      <a href="<?= h(apex_base_path()) ?>/activity" class="<?= str_starts_with($current, '/activity') ? 'active':'' ?>" data-testid="nav-activity">≡ Activity</a>
      <div class="section">Configure</div>
      <a href="<?= h(apex_base_path()) ?>/theme" class="<?= str_starts_with($current, '/theme') ? 'active':'' ?>" data-testid="nav-theme">◐ Theme</a>
      <a href="<?= h(apex_base_path()) ?>/discord" class="<?= str_starts_with($current, '/discord') ? 'active':'' ?>" data-testid="nav-discord">◆ Discord Bot</a>
      <?php if (($user['role'] ?? '') === 'admin'): ?>
      <a href="<?= h(apex_base_path()) ?>/users" class="<?= str_starts_with($current, '/users') ? 'active':'' ?>" data-testid="nav-users">☰ Users</a>
      <a href="<?= h(apex_base_path()) ?>/database-users" class="<?= str_starts_with($current, '/database-users') ? 'active':'' ?>" data-testid="nav-db-users">▣ DB Users</a>
      <?php endif; ?>
      <a href="<?= h(apex_base_path()) ?>/install" class="<?= str_starts_with($current, '/install') ? 'active':'' ?>" data-testid="nav-install">↓ Install Script</a>
    </nav>
  </aside>
  <main class="main">
    <div class="topbar">
      <button class="btn btn-ghost menu-toggle" data-toggle-sidebar data-testid="menu-toggle">☰</button>
      <div class="search">
        <input type="text" placeholder="⌘ K   search servers, nodes, commands…" data-testid="global-search">
      </div>
      <div class="row">
        <span class="chip accent" data-testid="user-role"><?= strtoupper($user['role']) ?></span>
        <div class="user-chip" data-testid="user-chip">
          <div class="avatar"><?= strtoupper(substr($user['username'],0,1)) ?></div>
          <span><?= h($user['username']) ?></span>
          <form method="post" action="<?= h(apex_base_path()) ?>/logout" style="margin:0"><?= csrf_field() ?>
            <button class="btn btn-ghost btn-sm" data-testid="logout-btn">Logout</button>
          </form>
        </div>
      </div>
    </div>
    <?php if ($msg = flash('success')): ?><div class="flash success" data-testid="flash-success"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($msg = flash('error')): ?><div class="flash error" data-testid="flash-error"><?= h($msg) ?></div><?php endif; ?>
    <div class="fade-in">
<?php else: ?>
<div class="auth-shell">
<?php endif; ?>
