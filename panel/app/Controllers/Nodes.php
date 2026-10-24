<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * Node controller: register and remove Linux daemon hosts.
 *
 * Admin-only. Stores node metadata used for server placement and status.
 */
class Nodes
{
    /**
     * List all registered nodes with server counts.
     *
     * @return void
     */
    public function index(): void
    {
        \require_login();
        $nodes = DB::all('SELECT n.*, (SELECT COUNT(*) FROM servers s WHERE s.node_id=n.id) srv_count FROM nodes n ORDER BY n.id ASC');
        \view('nodes/index', ['title' => 'Nodes', 'nodes' => $nodes]);
    }

    /**
     * Register a new node.
     *
     * @return void
     */
    public function store(): void
    {
        \check_csrf();
        \require_role('admin');
        $name  = trim((string) ($_POST['name'] ?? ''));
        $host  = trim((string) ($_POST['hostname'] ?? ''));
        $ip    = trim((string) ($_POST['ip'] ?? ''));
        if ($name === '' || $host === '' || $ip === '') {
            \flash('error', 'All fields required.');
            \redirect('/nodes');
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            \flash('error', 'Enter a valid IP address.');
            \redirect('/nodes');
        }
        DB::insert('nodes', [
            'name'      => $name,
            'hostname'  => $host,
            'ip'        => $ip,
            'cpu_cores' => (int) ($_POST['cpu_cores'] ?? 4),
            'ram_mb'    => (int) ($_POST['ram_mb'] ?? 8192),
            'disk_gb'   => (int) ($_POST['disk_gb'] ?? 100),
        ]);
        \log_activity('create-node', 'node:' . $name);
        \flash('success', 'Node "' . $name . '" registered.');
        \redirect('/nodes');
    }

    /**
     * Remove a node.
     *
     * @return void
     */
    public function delete(): void
    {
        \check_csrf();
        \require_role('admin');
        $id = (int) ($_POST['id'] ?? 0);
        DB::q('DELETE FROM nodes WHERE id=?', [$id]);
        \flash('success', 'Node removed.');
        \redirect('/nodes');
    }
}
