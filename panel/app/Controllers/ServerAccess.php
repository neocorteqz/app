<?php
namespace App\Controllers;
use DB;

class ServerAccess {
    private const PERMISSIONS = ['view_server', 'view_console', 'control_server', 'view_files', 'manage_files', 'view_backups', 'manage_backups'];

    public function index(int $id) {
        \require_role('operator');
        $server = DB::one('SELECT id, name FROM servers WHERE id=?', [$id]);
        if (!$server) { http_response_code(404); \view('errors/404'); return; }
        $users = DB::all("SELECT u.id, u.username, u.email, sa.view_server, sa.view_console, sa.control_server, sa.view_files, sa.manage_files, sa.view_backups, sa.manage_backups FROM users u LEFT JOIN server_access sa ON sa.user_id=u.id AND sa.server_id=? WHERE u.role='viewer' ORDER BY u.username", [$id]);
        \view('servers/access', ['title'=>'Access — '.$server['name'], 'server'=>$server, 'users'=>$users]);
    }

    public function save(int $id) {
        \check_csrf();
        \require_role('operator');
        $server = DB::one('SELECT id, name FROM servers WHERE id=?', [$id]);
        if (!$server) { http_response_code(404); \view('errors/404'); return; }
        $userId = (int)($_POST['user_id'] ?? 0);
        $target = DB::one("SELECT id FROM users WHERE id=? AND role='viewer'", [$userId]);
        if (!$target) { \flash('error', 'Choose an existing viewer account.'); \redirect("/servers/$id/access"); }
        $values = [];
        foreach (self::PERMISSIONS as $permission) $values[$permission] = isset($_POST[$permission]) ? 1 : 0;
        if (array_sum(array_diff_key($values, ['view_server'=>true]))) $values['view_server'] = 1;
        if ($values['control_server']) $values['view_console'] = 1;
        if ($values['manage_files']) $values['view_files'] = 1;
        if ($values['manage_backups']) $values['view_backups'] = 1;
        if (!array_sum($values)) {
            DB::q('DELETE FROM server_access WHERE server_id=? AND user_id=?', [$id, $userId]);
        } else {
            DB::q('INSERT INTO server_access (server_id, user_id, view_server, view_console, control_server, view_files, manage_files, view_backups, manage_backups) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE view_server=VALUES(view_server), view_console=VALUES(view_console), control_server=VALUES(control_server), view_files=VALUES(view_files), manage_files=VALUES(manage_files), view_backups=VALUES(view_backups), manage_backups=VALUES(manage_backups)', [$id, $userId, ...array_values($values)]);
        }
        \log_activity('update-server-access', 'server:'.$server['name'], 'user:'.$userId);
        \flash('success', 'Server access updated.');
        \redirect("/servers/$id/access");
    }
}