<?php
namespace App\Controllers;

use DB;

class Servers {
    public function index() {
        $u = \require_login();
        [$where, $args] = \accessible_server_filter($u);
        $servers = DB::all('SELECT s.*, n.name AS node_name FROM servers s JOIN nodes n ON n.id=s.node_id'.$where.' ORDER BY s.id DESC', $args);
        \view('servers/index', ['title'=>'Servers','servers'=>$servers]);
    }
    public function create() {
        \require_role('operator');
        $nodes = DB::all('SELECT * FROM nodes ORDER BY name');
        $eggs = DB::all('SELECT * FROM eggs ORDER BY game, featured DESC');
        $loaders = DB::all('SELECT * FROM mod_loaders ORDER BY popular DESC, game, name');
        $preselect_egg = isset($_GET['egg']) ? DB::one('SELECT * FROM eggs WHERE id=?', [(int)$_GET['egg']]) : null;
        \view('servers/create', ['title'=>'Deploy Server','nodes'=>$nodes,'eggs'=>$eggs,'loaders'=>$loaders,'preselect_egg'=>$preselect_egg]);
    }
    public function store() {
        \check_csrf();
        $u = \require_role('operator');
        $name = trim($_POST['name'] ?? '');
        $egg_id = (int)($_POST['egg_id'] ?? 0);
        $egg = $egg_id ? DB::one('SELECT * FROM eggs WHERE id=?', [$egg_id]) : null;
        $loader_id = (int)($_POST['loader_id'] ?? 0);
        $loader = $loader_id ? DB::one('SELECT * FROM mod_loaders WHERE id=?', [$loader_id]) : null;
        $modpack_ref = trim($_POST['modpack_ref'] ?? '');
        $game = $egg ? $egg['game'] : ($loader ? $loader['game'] : ($_POST['game'] ?? ''));
        $node_id = (int)($_POST['node_id'] ?? 0);
        $port = (int)($_POST['port'] ?? 25565);
        $cpu = (int)($_POST['cpu_limit'] ?? 2);
        $ram = (int)($_POST['ram_mb'] ?? 2048);
        $disk = (int)($_POST['disk_gb'] ?? 10);
        if (!$name || !in_array($game, ['minecraft-java','minecraft-bedrock','cs2','rust']) || !$node_id) {
            \flash('error','Missing required fields.'); \redirect('/servers/new');
        }
        if ($loader && $loader['requires_pack_id'] && !$modpack_ref) {
            \flash('error','This installer requires a modpack slug/ID.');
            \redirect('/mods/'.$loader_id.'#deploy');
        }
        $id = DB::insert('servers', [
            'name'=>$name,'game'=>$game,'egg_id'=>$egg ? $egg['id'] : null,
            'loader_id' => $loader ? $loader['id'] : null,
            'modpack_ref' => $modpack_ref ?: null,
            'modpack_status' => ($loader && $loader['category']==='modpack_source' && $modpack_ref) ? 'pending' : 'none',
            'node_id'=>$node_id,'owner_id'=>$u['id'],
            'port'=>$port,'cpu_limit'=>$cpu,'ram_mb'=>$ram,'disk_gb'=>$disk,
            'status'=>'installing','version'=>$egg ? $egg['name'] : ($loader ? $loader['name'] : 'latest'),
            'players_max'=>$game==='cs2'?32:($game==='rust'?100:20),
        ]);
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Provisioning '.$game.($egg?' with egg "'.$egg['name'].'"':'').' on node '.$node_id, 'system']);
        if ($loader) {
            DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Loader: '.$loader['name'].' ('.$loader['category'].')', 'system']);
            if ($modpack_ref) {
                DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Modpack reference: '.$modpack_ref, 'info']);
            }
            if ($loader['install_cmd']) {
                $cmd = str_replace('{ref}', $modpack_ref ?: 'latest', $loader['install_cmd']);
                DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Would run: '.$cmd, 'info']);
            }
        }
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Allocated CPU='.$cpu.'c RAM='.$ram.'MB DISK='.$disk.'GB PORT='.$port, 'info']);
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Ready to start.', 'system']);
        DB::q('UPDATE servers SET status="offline" WHERE id=?', [$id]);
        if ($egg) DB::q('UPDATE eggs SET downloads = downloads + 1 WHERE id=?', [$egg_id]);
        \log_activity('create-server','server:'.$name);
        \flash('success','Server "'.$name.'" deployed. Ready to start.');
        \redirect('/servers/'.$id);
    }
    public function show(int $id) {
        \require_login();
        \require_server_permission($id, 'view_server');
        $u = \auth_user();
        $s = DB::one('SELECT s.*, n.name AS node_name, e.name AS egg_name,
                             ml.name AS loader_name, ml.category AS loader_category,
                             ml.accent_color AS loader_color, ml.logo_char AS loader_icon
                      FROM servers s
                      JOIN nodes n ON n.id=s.node_id
                      LEFT JOIN eggs e ON e.id=s.egg_id
                      LEFT JOIN mod_loaders ml ON ml.id=s.loader_id
                      WHERE s.id=?', [$id]);
        if (!$s) { http_response_code(404); \view('errors/404'); return; }
        $allAccess = in_array($u['role'], ['admin','operator'], true) || (int)$s['owner_id'] === (int)$u['id'];
        $access = $allAccess ? [] : (DB::one('SELECT * FROM server_access WHERE server_id=? AND user_id=?', [$id,$u['id']]) ?: []);
        \view('servers/show', ['title'=>$s['name'],'s'=>$s,'can_control'=>$allAccess || !empty($access['control_server']),'can_view_console'=>$allAccess || !empty($access['view_console']),'can_view_files'=>$allAccess || !empty($access['view_files']),'can_view_backups'=>$allAccess || !empty($access['view_backups'])]);
    }
    public function action() {
        \check_csrf();
        $id = (int)($_POST['id'] ?? 0);
        \require_server_permission($id, 'control_server');
        $act = $_POST['action'] ?? '';
        $s = DB::one('SELECT * FROM servers WHERE id=?', [$id]);
        if (!$s) { \flash('error','Server not found.'); \redirect('/servers'); }
        if (!in_array($act, ['start','stop','restart','kill'])) {
            \flash('error','Invalid action.'); \redirect('/servers/'.$id);
        }
        // Route to real daemon
        $daemon_act = $act === 'kill' ? 'stop' : $act;
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[control] '.strtoupper($act).' issued by user '.($_SESSION['uid']??''), 'system']);
        $ch = curl_init("http://127.0.0.1:8001/api/daemon/$daemon_act/$id");
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            \flash('success', ucfirst($act).' dispatched to daemon.');
        } else {
            // Fallback if daemon unreachable — update DB directly
            $map = ['start'=>'online','stop'=>'offline','restart'=>'online','kill'=>'offline'];
            DB::q('UPDATE servers SET status=? WHERE id=?', [$map[$act], $id]);
            DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[daemon] unreachable (HTTP '.$code.'), fallback state applied', 'warn']);
            \flash('error','Daemon unreachable; fallback state applied.');
        }
        \log_activity($act.'-server','server:'.$s['name']);
        \redirect('/servers/'.$id);
    }
    public function delete() {
        \check_csrf();
        \require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        $s = DB::one('SELECT name FROM servers WHERE id=?', [$id]);
        DB::q('DELETE FROM servers WHERE id=?', [$id]);
        \log_activity('delete-server','server:'.($s['name']??''));
        \flash('success','Server removed.');
        \redirect('/servers');
    }
    public function apiList() {
        $u = \require_login();
        // Live drift only for simulated (non-daemon-managed) online servers
        [$where, $args] = \accessible_server_filter($u);
        $rows = DB::all('SELECT s.* FROM servers s'.$where, $args);
        foreach ($rows as $r) {
            if ($r['status'] === 'online') {
                $cpu = max(2, min(95, (float)$r['cpu_usage'] + rand(-8,8)));
                $ram = max(64, min((int)$r['ram_mb'], (int)$r['ram_usage_mb'] + rand(-64,64)));
                DB::q('UPDATE servers SET cpu_usage=?, ram_usage_mb=? WHERE id=?', [$cpu, $ram, $r['id']]);
            }
        }
        \json_response(DB::all('SELECT s.id, s.name, s.game, s.status, s.cpu_usage, s.ram_usage_mb, s.ram_mb, s.players_online, s.players_max FROM servers s'.$where, $args));
    }
    public function apiLogs() {
        \require_login();
        $id = (int)($_GET['id'] ?? 0);
        \require_server_permission($id, 'view_console');
        $after = (int)($_GET['after'] ?? 0);
        $s = DB::one('SELECT * FROM servers WHERE id=?', [$id]);
        if (!$s) \json_response(['lines'=>[]]);
        // The real daemon streams logs to the DB. Legacy simulated drift is disabled.
        $logs = DB::all('SELECT id, line, level, DATE_FORMAT(created_at,"%H:%i:%s") ts FROM server_logs WHERE server_id=? AND id > ? ORDER BY id ASC LIMIT 60', [$id, $after]);
        \json_response(['lines'=>$logs]);
    }
    public function apiConsoleCmd() {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? '')) \json_response(['error'=>'csrf'],419);
        $id = (int)($body['id'] ?? 0);
        \require_server_permission($id, 'control_server');
        $cmd = trim($body['cmd'] ?? '');
        if (!$id || !$cmd) \json_response(['error'=>'invalid']);
        // Send through daemon (which writes to process stdin)
        $ch = curl_init("http://127.0.0.1:8001/api/daemon/console/$id");
        curl_setopt_array($ch, [
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode(['cmd'=>$cmd]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            \json_response(['ok'=>true]);
        }
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '> '.$cmd, 'system']);
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[daemon] unreachable — command not delivered', 'warn']);
        \json_response(['ok'=>false]);
    }

    public function installPack(int $id) {
        \check_csrf(); \require_role('operator');
        $s = DB::one('SELECT * FROM servers WHERE id=?', [$id]);
        if (!$s) { \flash('error','Server not found.'); \redirect('/servers'); }
        if (empty($s['modpack_ref'])) { \flash('error','No modpack configured for this server.'); \redirect('/servers/'.$id); }
        // Enqueue on the daemon (returns instantly with job_id)
        $ch = curl_init("http://127.0.0.1:8001/api/daemon/modpack/install-async/$id");
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($resp ?: '{}', true) ?: [];
        if (!empty($json['ok'])) {
            if (!empty($json['already_installed'])) {
                \flash('success','Pack is already installed.');
            } else {
                \flash('success','Installation queued (job #'.($json['job_id']??'?').'). Watch progress in the console or on the Jobs page.');
            }
        } else {
            \flash('error','Enqueue failed: '.($json['error'] ?? "HTTP $code"));
        }
        \redirect('/servers/'.$id);
    }
}
