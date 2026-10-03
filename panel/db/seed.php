<?php

// Seed demo data: admin user, nodes, sample servers
require_once __DIR__ . '/../app/db.php';

// Admin user
$hash = password_hash('admin123', PASSWORD_BCRYPT);
try {
    DB::q(
        'INSERT IGNORE INTO users (username, email, password_hash, role) VALUES (?,?,?,?)',
        ['admin','admin@apexnode.local',$hash,'admin']
    );
} catch (Throwable $e) {
}

// Operator user
$hash2 = password_hash('operator123', PASSWORD_BCRYPT);
try {
    DB::q(
        'INSERT IGNORE INTO users (username, email, password_hash, role) VALUES (?,?,?,?)',
        ['operator','ops@apexnode.local',$hash2,'operator']
    );
} catch (Throwable $e) {
}

$adminId = (int)DB::one('SELECT id FROM users WHERE username=?', ['admin'])['id'];

// Nodes
if ((int)DB::one('SELECT COUNT(*) c FROM nodes')['c'] === 0) {
    DB::insert('nodes', ['name' => 'daemon-eu-01','hostname' => 'daemon-eu-01','ip' => '10.10.20.11','cpu_cores' => 8,'ram_mb' => 16384,'disk_gb' => 500,'status' => 'online','latency_ms' => 8]);
    DB::insert('nodes', ['name' => 'daemon-us-01','hostname' => 'daemon-us-01','ip' => '10.10.30.12','cpu_cores' => 16,'ram_mb' => 32768,'disk_gb' => 1000,'status' => 'online','latency_ms' => 42]);
    DB::insert('nodes', ['name' => 'daemon-ap-01','hostname' => 'daemon-ap-01','ip' => '10.10.40.14','cpu_cores' => 4,'ram_mb' => 8192,'disk_gb' => 200,'status' => 'degraded','latency_ms' => 110]);
}

// Sample servers
if ((int)DB::one('SELECT COUNT(*) c FROM servers')['c'] === 0) {
    $nodes = array_column(DB::all('SELECT id FROM nodes'), 'id');
    $seed = [
        ['Survival SMP','minecraft-java',$nodes[0], 25565, 4, 4096, 40, 'online',  7, 20],
        ['Bedrock Realm','minecraft-bedrock',$nodes[0], 19132, 2, 2048, 20, 'online', 3, 40],
        ['CS2 5v5 EU','cs2',$nodes[1], 27015, 4, 4096, 15, 'offline', 0, 32],
        ['Rust Vanilla','rust',$nodes[1], 28015, 8, 12288, 80, 'starting', 0, 100],
        ['Creative Build','minecraft-java',$nodes[2], 25566, 2, 2048, 10, 'online', 12, 30],
    ];
    foreach ($seed as $r) {
        DB::insert('servers', [
            'name' => $r[0],'game' => $r[1],'node_id' => $r[2],'owner_id' => $adminId,
            'port' => $r[3],'cpu_limit' => $r[4],'ram_mb' => $r[5],'disk_gb' => $r[6],
            'status' => $r[7],'players_online' => $r[8],'players_max' => $r[9],
            'cpu_usage' => $r[7] === 'online' ? rand(15, 55) : 0,
            'ram_usage_mb' => $r[7] === 'online' ? (int)($r[5] * rand(30, 70) / 100) : 0,
        ]);
    }
    // Seed initial logs
    $servers = DB::all('SELECT id, game FROM servers');
    foreach ($servers as $s) {
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$s['id'], '[installer] Server provisioned and ready.', 'system']);
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$s['id'], '[boot] Loading configuration…', 'info']);
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$s['id'], '[boot] Startup complete.', 'info']);
    }
}

// Default discord prefix
DB::q('INSERT IGNORE INTO settings (k,v) VALUES (?,?)', ['discord_prefix','!']);
DB::q('INSERT IGNORE INTO settings (k,v) VALUES (?,?)', ['discord_status','disconnected']);

echo "Seed complete.\n";
echo "Admin: admin / admin123\n";
echo "Operator: operator / operator123\n";
