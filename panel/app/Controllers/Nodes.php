<?php

namespace App\Controllers;

use DB;

class Nodes
{
    public function index()
    {
        \require_login();
        $nodes = DB::all('SELECT n.*, (SELECT COUNT(*) FROM servers s WHERE s.node_id=n.id) srv_count FROM nodes n ORDER BY n.id ASC');
        \view('nodes/index', ['title' => 'Nodes','nodes' => $nodes]);
    }
    public function store()
    {
        \check_csrf();
        \require_role('admin');
        $name = trim($_POST['name'] ?? '');
        $host = trim($_POST['hostname'] ?? '');
        $ip = trim($_POST['ip'] ?? '');
        if (!$name || !$host || !$ip) {
            \flash('error', 'All fields required.');
            \redirect('/nodes');
        }
        DB::insert('nodes', [
            'name' => $name,'hostname' => $host,'ip' => $ip,
            'cpu_cores' => (int)($_POST['cpu_cores'] ?? 4),
            'ram_mb' => (int)($_POST['ram_mb'] ?? 8192),
            'disk_gb' => (int)($_POST['disk_gb'] ?? 100),
        ]);
        \log_activity('create-node', 'node:' . $name);
        \flash('success', 'Node "' . $name . '" registered.');
        \redirect('/nodes');
    }
    public function delete()
    {
        \check_csrf();
        \require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        DB::q('DELETE FROM nodes WHERE id=?', [$id]);
        \flash('success', 'Node removed.');
        \redirect('/nodes');
    }
}
