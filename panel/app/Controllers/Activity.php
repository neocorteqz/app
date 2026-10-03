<?php

namespace App\Controllers;

use DB;

class Activity
{
    public function index()
    {
        \require_login();
        $rows = DB::all('SELECT a.*, u.username FROM activity_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200');
        \view('activity/index', ['title' => 'Activity Log','rows' => $rows]);
    }
}
