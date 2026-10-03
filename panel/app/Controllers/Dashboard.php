<?php

namespace App\Controllers;

use DB;

class Dashboard
{
    public function index()
    {
        $u = \require_login();
        [$where, $args] = \accessible_server_filter($u);
        $servers = DB::all('SELECT s.*, n.name AS node_name FROM servers s JOIN nodes n ON n.id=s.node_id' . $where . ' ORDER BY s.id DESC', $args);
        $stats = [
            'total'   => count($servers),
            'online'  => count(array_filter($servers, fn($s)=>$s['status'] === 'online')),
            'nodes'   => (int)DB::one('SELECT COUNT(*) c FROM nodes')['c'],
            'players' => array_sum(array_map(fn($s)=>(int)$s['players_online'], $servers)),
        ];
        $alerts = [];
        $alertScope = in_array($u['role'], ['admin','operator'], true) ? '' : ' AND (s.owner_id=? OR EXISTS (SELECT 1 FROM server_access sa WHERE sa.server_id=s.id AND sa.user_id=? AND sa.view_server=1))';
        $alertArgs = in_array($u['role'], ['admin','operator'], true) ? [] : [(int)$u['id'], (int)$u['id']];
        foreach (DB::all("SELECT s.id, s.name FROM servers s WHERE s.status='crashed'{$alertScope} ORDER BY s.id DESC LIMIT 10", $alertArgs) as $server) {
            $alerts[] = ['severity' => 'error', 'title' => 'Server crashed: ' . $server['name'], 'url' => '/servers/' . $server['id'], 'detail' => 'Open the server console and inspect recent logs.'];
        }
        foreach (DB::all("SELECT j.id, j.target_id, j.message FROM jobs j JOIN servers s ON s.id=j.target_id AND j.target_kind='server' WHERE j.status='failed' AND j.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY){$alertScope} ORDER BY j.id DESC LIMIT 10", $alertArgs) as $job) {
            $alerts[] = ['severity' => 'error', 'title' => 'Job #' . $job['id'] . ' failed', 'url' => '/jobs', 'detail' => $job['message'] ?: 'Inspect the job details for the failure reason.'];
        }
        foreach (DB::all("SELECT b.server_id, b.name, s.name AS server_name FROM backups b JOIN servers s ON s.id=b.server_id WHERE b.status='failed' AND b.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY){$alertScope} ORDER BY b.id DESC LIMIT 10", $alertArgs) as $backup) {
            $alerts[] = ['severity' => 'error', 'title' => 'Backup failed: ' . $backup['server_name'], 'url' => '/servers/' . $backup['server_id'] . '/backups', 'detail' => $backup['name']];
        }
        foreach (DB::all("SELECT s.id, s.name, bs.last_run FROM backup_schedules bs JOIN servers s ON s.id=bs.server_id WHERE bs.enabled=1 AND (bs.last_run IS NULL OR bs.last_run < DATE_SUB(NOW(), INTERVAL bs.interval_minutes MINUTE)){$alertScope} ORDER BY bs.last_run ASC LIMIT 10", $alertArgs) as $schedule) {
            $alerts[] = ['severity' => 'warning', 'title' => 'Backup overdue: ' . $schedule['name'], 'url' => '/servers/' . $schedule['id'] . '/backups', 'detail' => $schedule['last_run'] ? 'Last completed run: ' . $schedule['last_run'] : 'No scheduled backup has completed yet.'];
        }
        $activity = DB::all('SELECT a.*, u.username FROM activity_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 12');
        \view('dashboard/index', ['title' => 'Dashboard','servers' => $servers,'stats' => $stats,'activity' => $activity,'alerts' => $alerts]);
    }
}
