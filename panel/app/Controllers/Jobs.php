<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * Jobs controller: background job list, cancellation, and JSON status.
 */
class Jobs
{
    /**
     * List background jobs visible to the signed-in user.
     *
     * @return void
     */
    public function index(): void
    {
        $u = \require_login();
        $scope = '';
        $args = [];
        if (!in_array($u['role'], ['admin', 'operator'], true)) {
            $scope = ' WHERE (s.owner_id=? OR EXISTS (SELECT 1 FROM server_access sa WHERE sa.server_id=s.id AND sa.user_id=? AND sa.view_console=1))';
            $args = [(int) $u['id'], (int) $u['id']];
        }
        $jobs = DB::all(
            'SELECT j.*, s.name AS server_name
             FROM jobs j
             LEFT JOIN servers s ON s.id = j.target_id AND j.target_kind="server"
             ' . $scope . ' ORDER BY j.id DESC LIMIT 100',
            $args
        );
        \view('jobs/index', ['title' => 'Background Jobs', 'jobs' => $jobs]);
    }

    /**
     * Request cancellation of a job via the daemon.
     *
     * @param int $id Job id.
     * @return void
     */
    public function cancel(int $id): void
    {
        \check_csrf();
        \require_role('operator');
        $ch = curl_init("http://127.0.0.1:8001/api/daemon/jobs/$id/cancel");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $j = json_decode($resp ?: '{}', true) ?: [];
        $j['ok'] = !empty($j['ok']);
        if (!empty($j['already_finished'])) {
            $message = 'Job already finished.';
        } elseif ($j['ok']) {
            $message = 'Cancel requested — worker will stop between files.';
        } else {
            $message = 'Cancel failed.';
        }
        \flash($j['ok'] ? 'success' : 'error', $message);
        \redirect('/jobs');
    }

    /**
     * Return a single job's status as JSON with access checks.
     *
     * @param int $id Job id.
     * @return void
     */
    public function apiShow(int $id): void
    {
        $u = \require_login();
        $j = DB::one('SELECT * FROM jobs WHERE id=?', [$id]);
        if (!$j) {
            \json_response(['ok' => false, 'error' => 'not found'], 404);
        }
        if ($j['target_kind'] !== 'server' && !in_array($u['role'], ['admin', 'operator'], true)) {
            \json_response(['error' => 'Forbidden'], 403);
        }
        if ($j['target_kind'] === 'server' && !in_array($u['role'], ['admin', 'operator'], true)) {
            \require_server_permission((int) $j['target_id'], 'view_console');
        }
        $j['progress'] = (int) $j['progress'];
        $j['total']    = (int) $j['total'];
        $j['pct']      = $j['total'] > 0 ? min(100, (int) round(100 * $j['progress'] / $j['total'])) : 0;
        \json_response($j);
    }

    /**
     * Return recent jobs for a server as JSON.
     *
     * @param int $sid Server id.
     * @return void
     */
    public function apiForServer(int $sid): void
    {
        \require_login();
        \require_server_permission($sid, 'view_console');
        $rows = DB::all(
            'SELECT id, kind, status, cancel_requested, progress, total, message, created_at, completed_at
             FROM jobs WHERE target_kind="server" AND target_id=? ORDER BY id DESC LIMIT 10',
            [$sid]
        );
        foreach ($rows as &$r) {
            $r['pct'] = $r['total'] > 0 ? min(100, (int) round(100 * $r['progress'] / $r['total'])) : 0;
        }
        \json_response($rows);
    }
}
