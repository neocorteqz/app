<div class="between">
  <div><div class="section-title" style="margin:0">Personalize</div><h1 data-testid="page-title">Theme Customizer</h1><p class="muted">Adjust the command tower to your taste. Live preview → save to persist.</p></div>
</div>

<form method="post" action="<?= h(apex_base_path()) ?>/theme" data-testid="theme-form">
  <?= csrf_field() ?>
  <div class="grid-2" style="margin-top:16px">
    <div class="card">
      <div class="section-title" style="margin-top:0">Accent Color</div>
      <div class="row" data-testid="accent-swatches">
        <?php foreach ([['#00F0FF','Electric Cyan'],['#10B981','Neon Emerald'],['#F59E0B','Laser Amber'],['#A855F7','Cyber Violet'],['#FF3B30','Crimson Blaze']] as $c): ?>
          <label class="chip" style="cursor:pointer;border-color:<?= $c[0] ?>;color:<?= $c[0] ?>;padding:8px 12px">
            <input type="radio" name="accent" value="<?= $c[0] ?>" onchange="applyTheme({accent:'<?= $c[0] ?>'})" <?= $theme['accent']===$c[0]?'checked':'' ?> style="margin-right:6px">
            <?= $c[1] ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="form-group" style="margin-top:14px">
        <label>Custom Accent Hex</label>
        <input type="text" name="accent" value="<?= h($theme['accent']) ?>" pattern="^#[0-9A-Fa-f]{6}$" oninput="applyTheme({accent:this.value})" data-testid="input-accent">
      </div>

      <div class="section-title">Corner Radius</div>
      <div class="row">
        <?php foreach (['0px','4px','8px','12px','16px'] as $r): ?>
          <label class="chip" style="cursor:pointer;padding:8px 12px">
            <input type="radio" name="radius" value="<?= $r ?>" onchange="applyTheme({radius:'<?= $r ?>'})" <?= $theme['radius']===$r?'checked':'' ?>>
            <?= $r ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="section-title" style="margin-top:0">Density</div>
      <div class="row">
        <?php foreach (['compact','comfortable','spacious'] as $d): ?>
          <label class="chip" style="cursor:pointer;padding:8px 12px;text-transform:capitalize">
            <input type="radio" name="density" value="<?= $d ?>" onchange="applyTheme({density:'<?= $d ?>'})" <?= $theme['density']===$d?'checked':'' ?>>
            <?= $d ?>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="section-title">Font</div>
      <div class="row">
        <?php foreach (['Outfit','Space Grotesk','Bricolage Grotesque','Plus Jakarta Sans','JetBrains Mono'] as $f): ?>
          <label class="chip" style="cursor:pointer;padding:8px 12px;font-family:'<?= $f ?>'">
            <input type="radio" name="font" value="<?= $f ?>" onchange="applyTheme({font:'<?= $f ?>'})" <?= $theme['font']===$f?'checked':'' ?>>
            <?= $f ?>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="section-title">Mode</div>
      <div class="row">
        <?php foreach (['dark','light'] as $m): ?>
          <label class="chip" style="cursor:pointer;padding:8px 12px;text-transform:capitalize">
            <input type="radio" name="mode" value="<?= $m ?>" <?= $theme['mode']===$m?'checked':'' ?>>
            <?= $m ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card" style="margin-top:14px">
    <div class="section-title" style="margin-top:0">Live Preview</div>
    <div class="row">
      <button type="button" class="btn">Secondary</button>
      <button type="button" class="btn btn-primary">Primary CTA</button>
      <span class="status status-online"><span class="dot" style="color:var(--ok)"></span>ONLINE</span>
      <span class="status status-offline"><span class="dot" style="color:var(--err)"></span>OFFLINE</span>
      <span class="chip accent">CHIP</span>
    </div>
    <div class="meter" style="margin-top:14px"><span style="width:64%"></span></div>
  </div>

  <div class="between" style="margin-top:14px">
    <p class="mono muted">Preferences save per-user in the database.</p>
    <button class="btn btn-primary" data-testid="theme-save">💾 Save Theme</button>
  </div>
</form>
