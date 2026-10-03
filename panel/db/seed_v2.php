<?php
// Seed marketplace eggs (game templates)
require_once __DIR__ . '/../app/db.php';

$default_mc = json_encode([
    'server.properties' => "server-port={port}\nmax-players={players}\nmotd={name} — powered by ApexNode\nspawn-protection=0\nview-distance=10\n",
    'eula.txt' => "eula=true\n",
    'plugins/README.md' => "# Plugins\nDrop .jar plugins here.\n"
]);
$default_cs2 = json_encode([
    'cfg/server.cfg' => "hostname \"{name}\"\nsv_password \"\"\nmp_maxrounds 24\nmp_freezetime 15\n",
    'cfg/gamemode.cfg' => "game_type 0\ngame_mode 1\n"
]);
$default_rust = json_encode([
    'server.cfg' => "server.hostname \"{name}\"\nserver.maxplayers {players}\nserver.description \"Powered by ApexNode\"\nserver.worldsize 3500\n",
    'oxide/config/README.md' => "# Oxide/uMod\nInstall plugins by dropping .cs / .dll files here.\n"
]);

$eggs = [
  ['minecraft-java', 'Vanilla Java 1.20', 'Pure Mojang — no mods, no plugins', 'The classic Minecraft experience straight from Mojang. Best for survival groups that want stability with zero surprises.', 'python3 -u /app/panel/daemon/fake_game.py minecraft-java', 'itzg/minecraft-server:latest', $default_mc, 1],
  ['minecraft-java', 'PaperMC 1.20', 'High-performance Spigot fork with plugin support', 'Paper is the go-to Java server: faster than vanilla, drop-in Spigot plugin compatibility, sane defaults for 5-50 players.', 'python3 -u /app/panel/daemon/fake_game.py minecraft-java', 'itzg/minecraft-server:latest', $default_mc, 1],
  ['minecraft-java', 'Forge Modded 1.20', 'Big-modpack ready runtime with 8G heap', 'Runs modpacks like ATM9, Better MC, RLCraft. Auto-installs Forge and gives you 8GB heap headroom.', 'python3 -u /app/panel/daemon/fake_game.py minecraft-java', 'itzg/minecraft-server:latest', $default_mc, 0],
  ['minecraft-bedrock', 'Bedrock Vanilla', 'Cross-play with Xbox, mobile, and consoles', 'Official Bedrock server. Pairs perfectly with Realms-style buddy servers, Xbox invites, and mobile play.', 'python3 -u /app/panel/daemon/fake_game.py minecraft-bedrock', 'itzg/minecraft-bedrock-server:latest', null, 1],
  ['cs2', 'CS2 Competitive 5v5', 'Standard MR12 + overtime ruleset', 'Ideal for scrims and pug matches. Round MR12, overtime enabled, warmup with knife round.', 'python3 -u /app/panel/daemon/fake_game.py cs2', 'joedwards32/cs2', $default_cs2, 1],
  ['cs2', 'CS2 Deathmatch 24/7', 'Fast respawn, aim-warmup mode', 'A pure aim server: instant respawn, random spawns, warmup weapons everyone loves.', 'python3 -u /app/panel/daemon/fake_game.py cs2', 'joedwards32/cs2', $default_cs2, 0],
  ['cs2', 'CS2 Retakes', 'Bomb-planted retake practice server', 'Practice retakes with tuned bot economies and pre-planted C4. Great for solo grinders.', 'python3 -u /app/panel/daemon/fake_game.py cs2', 'joedwards32/cs2', $default_cs2, 0],
  ['rust', 'Rust Vanilla Monthly', 'Full-wipe monthly, no mods', 'Straight from Facepunch. Monthly wipes, blueprint reset on force wipe. Classic PvP loop.', 'python3 -u /app/panel/daemon/fake_game.py rust', 'didstopia/rust-server', $default_rust, 1],
  ['rust', 'Rust PvE Modded', 'Oxide + Zombies + Quests', 'Curated Oxide plugin bundle for PvE fun: zombies, quests, teleport, skinbox.', 'python3 -u /app/panel/daemon/fake_game.py rust', 'didstopia/rust-server', $default_rust, 0],
];

$existing = (int)DB::one('SELECT COUNT(*) c FROM eggs')['c'];
if ($existing === 0) {
    foreach ($eggs as $e) {
        DB::insert('eggs', [
            'game' => $e[0], 'name' => $e[1], 'tagline' => $e[2],
            'description' => $e[3], 'start_command' => $e[4],
            'docker_image' => $e[5], 'default_files' => $e[6],
            'featured' => $e[7], 'downloads' => rand(100, 8000),
        ]);
    }
    echo "Seeded " . count($eggs) . " eggs.\n";
}

// Backfill existing servers with default egg per game
$rows = DB::all("SELECT s.id, s.game FROM servers s WHERE s.egg_id IS NULL");
foreach ($rows as $r) {
    $egg = DB::one("SELECT id FROM eggs WHERE game=? ORDER BY featured DESC, id ASC LIMIT 1", [$r['game']]);
    if ($egg) DB::q("UPDATE servers SET egg_id=? WHERE id=?", [$egg['id'], $r['id']]);
}
echo "Eggs backfilled.\n";
