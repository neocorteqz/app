<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * Activity log controller: read-only audit trail of panel actions.
 */
class Activity
{
    /**
     * List recent activity log entries.
     *
     * @return void
     */
    public function index(): void
    {
        \require_login();
        $rows = DB::all('SELECT a.*, u.username FROM activity_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200');
        \view('activity/index', ['title' => 'Activity Log', 'rows' => $rows]);
    }
}
