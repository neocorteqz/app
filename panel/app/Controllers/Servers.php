<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * Server lifecycle controller: list, create, start/stop/restart/kill, delete,
 * JSON API endpoints, modpack install, and console commands.
 *
 * Every mutating action validates CSRF and server permissions. Daemon calls are
 * made over loopback only (127.0.0.1:8001) with short timeouts.
 */
class Servers
{
    private const VALID_GAMES = ['minecraft-java', 'minecraft-bedrock', 'cs2', 'rust'];
    private const VALID_ACTIONS = ['start', 'stop', 'restart', 'kill'];

    // Resource sanity limits.
    private const PORT_MIN = 1;
    private const PORT_MAX = 65535;
    private const CPU_MIN = 1;
    private const CPU_MAX = 128;
    private const RAM_MIN_MB = 256;
    private const RAM_MAX_MB = 262144;
    private const DISK_MIN_GB = 1;
    private const DISK_MAX_GB = 10240;

    /**
     * List servers visible to the signed-in user.
     *
     * @return void
     */
    public function index(): void
    {
        $u = \require_login();
        [$where, $args] = \accessible_server_filter($u);
        $servers = DB::all(
            'SELECT s.*, n.name AS node_name FROM servers s JOIN nodes n ON n.id=s.node_id' . $where . ' ORDER BY s.id DESC',
            $args
        );
        \view('servers/index', ['title' => 'Servers', 'servers' => $servers]);
    }

    /**
     * Render the server creation form.
     *
     * @return void
     */
    public function create(): void
    {
        \require_role('operator');
        $nodes          = DB::all('SELECT * FROM nodes ORDER BY name');
        $eggs           = DB::all('SELECT * FROM eggs ORDER BY game, featured DESC');
        $loaders        = DB::all('SELECT * FROM mod_loaders ORDER BY popular DESC, game, name');
        $preselectEgg   = null;
        if (isset($_GET['egg']) && is_numeric($_GET['egg'])) {
            $preselectEgg = DB::one('SELECT * FROM eggs WHERE id=?', [(int) $_GET['egg']]);
        }
        \view('servers/create', [
            'title'         => 'Deploy Server',
            'nodes'         => $nodes,
            'eggs'          => $eggs,
            'loaders'       => $loaders,
            'preselect_egg' => $preselectEgg,
        ]);
    }

    /**
     * Create a new server record after validating all inputs.
     *
     * @return void
     */
    public function store(): void
    {
        \check_csrf();
        $u = \require_role('operator');

        $name          = trim((string) ($_POST['name'] ?? ''));
        $eggId         = (int) ($_POST['egg_id'] ?? 0);
        $loaderId      = (int) ($_POST['loader_id'] ?? 0);
        $modpackRef    = trim((string) ($_POST['modpack_ref'] ?? ''));
        $minecraftVer  = trim((string) ($_POST['minecraft_version'] ?? '1.20.4'));
        $nodeId        = (int) ($_POST['node_id'] ?? 0);
        $port          = (int) ($_POST['port'] ?? 25565);
        $cpu           = (int) ($_POST['cpu_limit'] ?? 2);
        $ram           = (int) ($_POST['ram_mb'] ?? 2048);
        $disk          = (int) ($_POST['disk_gb'] ?? 10);

        if ($name === '' || $nodeId <= 0) {
            \flash('error', 'Missing required fields.');
            \redirect('/servers/new');
        }
        $egg   = $eggId ? DB::one('SELECT * FROM eggs WHERE id=?', [$eggId]) : null;
        $game  = $egg ? $egg['game'] : ($_POST['game'] ?? '');
        if (!in_array($game, self::VALID_GAMES, true)) {
            \flash('error', 'Choose a valid game.');
            \redirect('/servers/new');
        }
        if ($game === 'minecraft-java' && !preg_match('/^\d+\.\d+(?:\.\d+)?$/', $minecraftVer)) {
            \flash('error', 'Choose a valid Minecraft release version.');
            \redirect('/servers/new');
        }
        if ($port < self::PORT_MIN || $port > self::PORT_MAX) {
            \flash('error', 'Port must be between ' . self::PORT_MIN . ' and ' . self::PORT_MAX . '.');
            \redirect('/servers/new');
        }
        if ($cpu < self::CPU_MIN || $cpu > self::CPU_MAX) {
            \flash('error', 'CPU limit must be between ' . self::CPU_MIN . ' and ' . self::CPU_MAX . ' cores.');
            \redirect('/servers/new');
        }
        if ($ram < self::RAM_MIN_MB || $ram > self::RAM_MAX_MB) {
            \flash('error', 'RAM must be between ' . self::RAM_MIN_MB . ' and ' . self::RAM_MAX_MB . ' MB.');
            \redirect('/servers/new');
        }
        if ($disk < self::DISK_MIN_GB || $disk > self::DISK_MAX_GB) {
            \flash('error', 'Disk must be between ' . self::DISK_MIN_GB . ' and ' . self::DISK_MAX_GB . ' GB.');
            \redirect('/servers/new');
        }

        $loader = $loaderId ? DB::one('SELECT * FROM mod_loaders WHERE id=?', [$loaderId]) : null;
        if ($loader && $loader['requires_pack_id'] && $modpackRef === '') {
            \flash('error', 'This installer requires a modpack slug/ID.');
            \redirect('/mods/' . $loaderId . '#deploy');
        }

        $modpackStatus = ($loader && $loader['category'] === 'modpack_source' && $modpackRef !== '') ? 'pending' : 'none';
        $playersMax    = $game === 'cs2' ? 32 : ($game === 'rust' ? 100 : 20);

        $id = DB::insert('servers', [
            'name'            => $name,
            'game'            => $game,
            'egg_id'          => $egg ? $egg['id'] : null,
            'loader_id'       => $loader ? $loader['id'] : null,
            'modpack_ref'     => $modpackRef ?: null,
            'modpack_status'  => $modpackStatus,
            'minecraft_version' => $minecraftVer,
            'node_id'         => $nodeId,
            'owner_id'        => (int) $u['id'],
            'port'            => $port,
            'cpu_limit'       => $cpu,
            'ram_mb'          => $ram,
            'disk_gb'         => $disk,
            'status'          => 'installing',
            'version'         => $egg ? $egg['name'] : ($loader ? $loader['name'] : 'latest'),
            'players_max'     => $playersMax,
        ]);

        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Provisioning ' . $game . ($egg ? ' with egg "' . $egg['name'] . '"' : '') . ' on node ' . $nodeId, 'system']);
        if ($loader) {
            DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Loader: ' . $loader['name'] . ' (' . $loader['category'] . ')', 'system']);
            if ($modpackRef !== '') {
                DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Modpack reference: ' . $modpackRef, 'info']);
            }
            if ($loader['install_cmd'] !== null) {
                $cmd = str_replace('{ref}', $modpackRef ?: 'latest', $loader['install_cmd']);
                DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Would run: ' . $cmd, 'info']);
            }
        }
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Allocated CPU=' . $cpu . 'c RAM=' . $ram . 'MB DISK=' . $disk . 'GB PORT=' . $port, 'info']);
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[installer] Ready to start.', 'system']);
        DB::q('UPDATE servers SET status="offline" WHERE id=?', [$id]);
        if ($egg) {
            DB::q('UPDATE eggs SET downloads = downloads + 1 WHERE id=?', [$eggId]);
        }
        \log_activity('create-server', 'server:' . $name);
        \flash('success', 'Server "' . $name . '" deployed. Ready to start.');
        \redirect('/servers/' . $id);
    }

    /**
     * Show a single server's detail page.
     *
     * @param int $id Server id.
     * @return void
     */
    public function show(int $id): void
    {
        \require_login();
        \require_server_permission($id, 'view_server');
        $u = \auth_user();

        $s = DB::one(
            'SELECT s.*, n.name AS node_name, e.name AS egg_name,
                     ml.name AS loader_name, ml.category AS loader_category,
                     ml.accent_color AS loader_color, ml.logo_char AS loader_icon
             FROM servers s
             JOIN nodes n ON n.id=s.node_id
             LEFT JOIN eggs e ON e.id=s.egg_id
             LEFT JOIN mod_loaders ml ON ml.id=s.loader_id
             WHERE s.id=?',
            [$id]
        );
        if (!$s) {
            http_response_code(404);
            \view('errors/404');
            return;
        }

        $allAccess = in_array($u['role'], ['admin', 'operator'], true) || (int) $s['owner_id'] === (int) $u['id'];
        $access = $allAccess ? [] : (DB::one('SELECT * FROM server_access WHERE server_id=? AND user_id=?', [$id, $u['id']]) ?: []);
        if (empty($s['share_token'])) {
            $token = bin2hex(random_bytes(24));
            DB::q('UPDATE servers SET share_token=? WHERE id=? AND share_token IS NULL', [$token, $id]);
            $saved = DB::one('SELECT share_token FROM servers WHERE id=?', [$id]);
            $s['share_token'] = $saved['share_token'] ?? $token;
        }
        \view('servers/show', [
            'title'             => $s['name'],
            's'                 => $s,
            'can_control'       => $allAccess || !empty($access['control_server']),
            'can_view_console'  => $allAccess || !empty($access['view_console']),
            'can_view_files'    => $allAccess || !empty($access['view_files']),
            'can_view_backups'  => $allAccess || !empty($access['view_backups']),
        ]);
    }

    /**
     * Handle a start/stop/restart/kill action dispatched to the daemon.
     *
     * @return void
     */
    public function action(): void
    {
        \check_csrf();
        $id   = (int) ($_POST['id'] ?? 0);
        \require_server_permission($id, 'control_server');
        $act  = (string) ($_POST['action'] ?? '');
        $s    = DB::one('SELECT * FROM servers WHERE id=?', [$id]);
        if (!$s) {
            \flash('error', 'Server not found.');
            \redirect('/servers');
        }
        if (!in_array($act, self::VALID_ACTIONS, true)) {
            \flash('error', 'Invalid action.');
            \redirect('/servers/' . $id);
        }

        $daemonAct = $act === 'kill' ? 'stop' : $act;
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[control] ' . strtoupper($act) . ' issued by user ' . ((string) ($_SESSION['uid'] ?? '')), 'system']);

        $resp = $this->daemonCall('POST', "/api/daemon/{$daemonAct}/{$id}", null);
        $code = $resp['code'] ?? 0;
        $body = $resp['body'] ?? '';

        if ($code >= 200 && $code < 300) {
            \flash('success', ucfirst($act) . ' dispatched to daemon.');
        } elseif (in_array($act, ['start', 'restart'], true) && str_contains((string) $body, 'loader bootstrap failed')) {
            DB::q('UPDATE servers SET status="crashed" WHERE id=?', [$id]);
            DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[runtime] loader bootstrap failed; server start aborted', 'error']);
            \flash('error', 'Loader bootstrap failed. Check the server logs for details.');
        } else {
            DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[daemon] control failed (HTTP ' . $code . '); server state was not changed', 'warn']);
            \flash('error', 'Daemon control failed. Check the daemon service and server logs.');
        }
        \log_activity($act . '-server', 'server:' . $s['name']);
        \redirect('/servers/' . $id);
    }

    /**
     * Delete a server (admin only).
     *
     * @return void
     */
    public function delete(): void
    {
        \check_csrf();
        \require_role('admin');
        $id = (int) ($_POST['id'] ?? 0);
        $s  = DB::one('SELECT name FROM servers WHERE id=?', [$id]);
        DB::q('DELETE FROM servers WHERE id=?', [$id]);
        \log_activity('delete-server', 'server:' . ($s['name'] ?? ''));
        \flash('success', 'Server removed.');
        \redirect('/servers');
    }

    /**
     * JSON list of servers visible to the signed-in user.
     *
     * @return void
     */
    public function apiList(): void
    {
        $u = \require_login();
        [$where, $args] = \accessible_server_filter($u);
        \json_response(DB::all(
            'SELECT s.id, s.name, s.game, s.status, s.cpu_usage, s.ram_usage_mb, s.ram_mb, s.players_online, s.players_max FROM servers s' . $where,
            $args
        ));
    }

    /**
     * JSON tail of server console logs.
     *
     * @return void
     */
    public function apiLogs(): void
    {
        \require_login();
        $id    = (int) ($_GET['id'] ?? 0);
        \require_server_permission($id, 'view_console');
        $after = (int) ($_GET['after'] ?? 0);
        $s     = DB::one('SELECT * FROM servers WHERE id=?', [$id]);
        if (!$s) {
            \json_response(['lines' => []]);
        }
        $logs = DB::all(
            'SELECT id, line, level, DATE_FORMAT(created_at,"%H:%i:%s") ts FROM server_logs WHERE server_id=? AND id > ? ORDER BY id ASC LIMIT 60',
            [$id, $after]
        );
        \json_response(['lines' => $logs]);
    }

    /**
     * Send a console command to the daemon for a server.
     *
     * @return void
     */
    public function apiConsoleCmd(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        \check_csrf();
        $id   = (int) ($body['id'] ?? 0);
        \require_server_permission($id, 'control_server');
        $cmd  = trim((string) ($body['cmd'] ?? ''));
        if ($id === 0 || $cmd === '') {
            \json_response(['error' => 'invalid']);
        }

        $payload = json_encode(['cmd' => $cmd]);
        $resp    = $this->daemonCall('POST', "/api/daemon/console/{$id}", $payload);
        $code    = $resp['code'] ?? 0;
        $result  = json_decode($resp['body'] ?: '{}', true);
        if ($code >= 200 && $code < 300 && is_array($result) && !empty($result['ok'])) {
            \json_response(['ok' => true]);
        }
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '> ' . $cmd, 'system']);
        DB::q('INSERT INTO server_logs (server_id, line, level) VALUES (?,?,?)', [$id, '[daemon] unreachable — command not delivered', 'warn']);
        \json_response(['ok' => false]);
    }

    /**
     * Enqueue a modpack install on the daemon.
     *
     * @param int $id Server id.
     * @return void
     */
    public function installPack(int $id): void
    {
        \check_csrf();
        \require_role('operator');
        $s = DB::one('SELECT * FROM servers WHERE id=?', [$id]);
        if (!$s) {
            \flash('error', 'Server not found.');
            \redirect('/servers');
        }
        if (empty($s['modpack_ref'])) {
            \flash('error', 'No modpack configured for this server.');
            \redirect('/servers/' . $id);
        }

        $resp = $this->daemonCall('POST', "/api/daemon/modpack/install-async/{$id}", null);
        $code = $resp['code'] ?? 0;
        $json = json_decode($resp['body'] ?: '{}', true) ?: [];
        if (!empty($json['ok'])) {
            if (!empty($json['already_installed'])) {
                \flash('success', 'Pack is already installed.');
            } else {
                \flash('success', 'Installation queued (job #' . ($json['job_id'] ?? '?') . '). Watch progress in the console or on the Jobs page.');
            }
        } else {
            \flash('error', 'Enqueue failed: ' . ($json['error'] ?? "HTTP $code"));
        }
        \redirect('/servers/' . $id);
    }

    /**
     * Call the loopback daemon API and return code + body.
     *
     * @param string $method HTTP method.
     * @param string $path   Daemon API path (no leading host).
     * @param string|null $body Request body (JSON) or null.
     * @return array{code:int, body:string}
     */
    private function daemonCall(string $method, string $path, ?string $body): array
    {
        $ch = curl_init("http://127.0.0.1:8001" . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json'];
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_HTTPHEADER] = ['Accept: application/json'];
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $resp === false ? '' : (string) $resp];
    }
}
