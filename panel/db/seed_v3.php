<?php

require_once __DIR__ . '/../app/db.php';

$loaders = [
    // Minecraft Java loaders / server variants
    ['minecraft-java','vanilla','Vanilla',            'vanilla',        'Official Mojang jar — unmodified',                    'The purest Java experience. Fastest boot, zero surprises, works with any client.',                              null, '◇', '#94A3B8', 0, 1],
    ['minecraft-java','paper',  'PaperMC',            'loader',         'High-performance drop-in for Spigot plugins',         'Paper patches vanilla for 3-5× more TPS at scale. Best default for community survival servers.',                'paper --version=1.20.4', '⚡', '#FF6B35', 0, 1],
    ['minecraft-java','purpur', 'Purpur',             'loader',         'Paper fork with 200+ config knobs',                   'Everything Paper does, plus toggles for phantoms, dolphin milk, ender-pearl cooldowns, and more.',                'purpur --version=1.20.4', '☯', '#A855F7', 0, 0],
    ['minecraft-java','forge',  'Forge',              'loader',         'The classic mod loader — thousands of mods',          'The originator. Compatible with every Forge modpack ever shipped. Great for tech and adventure mods.',            'forge --version=47.2.0',  '⚙', '#1D4E89', 0, 1],
    ['minecraft-java','neoforge','NeoForge',          'loader',         'Modern Forge fork — faster releases',                 'Post-1.20 community Forge fork. Faster patch cadence, cleaner API for authors.',                                  'neoforge --version=20.4', '☰', '#F59E0B', 0, 0],
    ['minecraft-java','fabric', 'Fabric',             'loader',         'Lightweight, snapshot-friendly loader',               'Fabric ships on day 1 of every Minecraft release. Small mods, fast startup, huge community.',                     'fabric --version=0.15',   '▲', '#DDA15E', 0, 1],
    ['minecraft-java','quilt',  'Quilt',              'loader',         'Fabric-compatible community fork',                    'Runs Fabric mods plus Quilt-only mods. Great governance, aims for stability + choice.',                          'quilt --version=0.20',    '◈', '#7928CA', 0, 0],
    ['minecraft-java','curseforge','CurseForge Modpack','modpack_source','Install any CurseForge modpack by slug/ID',           'Type a modpack slug (e.g. `all-the-mods-9`) or numeric ID. Panel fetches the manifest and installs.',              'apex-curse install {ref}', '☰', '#F16436', 1, 1],
    ['minecraft-java','modrinth','Modrinth Modpack',  'modpack_source', 'Install any Modrinth modpack',                        'Free, open ecosystem. Paste a Modrinth project slug — panel resolves versions and mods automatically.',            'apex-modrinth install {ref}', '◉', '#1BD96A', 1, 0],
    ['minecraft-java','ftb',    'FTB / Feed the Beast','modpack_source','Curated FTB modpacks',                                 'Feed the Beast\'s hand-tuned modpacks like FTB Skies, FTB Revelation. Enter the pack code.',                       'apex-ftb install {ref}',  '★', '#0067B4', 1, 0],

    // Minecraft Bedrock
    ['minecraft-bedrock','vanilla','Bedrock Vanilla', 'vanilla',        'Official Bedrock server binary',                       'Cross-play with Xbox, PS, Switch, mobile. Straight from Mojang, updates auto-pulled monthly.',                     null, '◇', '#94A3B8', 0, 1],
    ['minecraft-bedrock','pocketmine','PocketMine-MP','loader',         'PHP-powered Bedrock server with plugins',              'Community PHP server for Bedrock. Slower than vanilla but supports plugins, minigames, and custom worlds.',        'pmmp --stable',           '▶', '#F97316', 0, 0],

    // CS2
    ['cs2','vanilla','Vanilla CS2',                   'vanilla',        'Base Counter-Strike 2 dedicated server',               'Straight-shipped SRCDS build from Valve. Ideal for pug 5v5, wingman, and casual queues.',                          null, '⌖', '#F59E0B', 0, 1],
    ['cs2','metamod','Metamod:Source',                'mod_framework',  'Plugin loader for source engine games',                'The foundational plugin framework. Required for CS# / CSSharp and most custom plugins.',                          'metamod --version=2.0',   '≡', '#0EA5E9', 0, 1],
    ['cs2','cssharp','CounterStrikeSharp',            'mod_framework',  '.NET plugin runtime for CS2',                          'Write CS2 plugins in C#. Big library on GitHub — Retakes, MatchZy, PUG-Setup all available.',                       'cssharp --latest',        '#', '#512BD4', 0, 1],
    ['cs2','matchzy','MatchZy Match Config',          'loader',         'Pug/scrim/tournament match framework',                 'Turns your server into a full MR12 competitive match host with knife rounds, pause, tac-pause, veto.',              'apex-plugin install matchzy', '★', '#EF4444', 0, 0],
    ['cs2','workshop','Workshop Map Bundle',          'modpack_source', 'Auto-download a Steam Workshop map collection',        'Give the panel a Workshop collection ID — every map in the collection is fetched and put in mapcycle.',             'apex-workshop pull {ref}', '↧', '#F59E0B', 1, 0],

    // Rust
    ['rust','vanilla','Vanilla Rust',                 'vanilla',        'Facepunch-shipped stock server',                        'Standard monthly-wipe Rust server. Zero mods, blueprint wipe on force wipe.',                                       null, '◇', '#94A3B8', 0, 1],
    ['rust','oxide',  'Oxide / uMod',                 'mod_framework',  'The Rust modding platform',                             'Runs thousands of Oxide plugins: kits, teleport, zombies, quests, skinbox, event bots. Panel auto-updates.',        'oxide --stable',          '◆', '#B45309', 0, 1],
    ['rust','carbon', 'Carbon',                       'mod_framework',  'Modern Oxide-compatible runtime',                       'Drop-in Oxide replacement with faster startup + hot-reload. Runs unmodified Oxide plugins.',                        'carbon --latest',         '●', '#F43F5E', 0, 0],
];

$existing = (int)DB::one('SELECT COUNT(*) c FROM mod_loaders')['c'];
if ($existing === 0) {
    foreach ($loaders as $l) {
        DB::insert('mod_loaders', [
            'game' => $l[0],'slug' => $l[1],'name' => $l[2],'category' => $l[3],
            'tagline' => $l[4],'description' => $l[5],'install_cmd' => $l[6],
            'logo_char' => $l[7],'accent_color' => $l[8],
            'requires_pack_id' => $l[9],'popular' => $l[10],
        ]);
    }
    echo "Seeded " . count($loaders) . " mod loaders.\n";
} else {
    echo "mod_loaders already populated ($existing rows).\n";
}
