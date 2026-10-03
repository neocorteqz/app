<?php

namespace App\Controllers;

use DB;

class Discord
{
    public function index()
    {
        \require_role('admin');
        $token   = DB::one('SELECT v FROM settings WHERE k=?', ['discord_token'])['v'] ?? '';
        $channel = DB::one('SELECT v FROM settings WHERE k=?', ['discord_channel'])['v'] ?? '';
        $guild   = DB::one('SELECT v FROM settings WHERE k=?', ['discord_guild'])['v'] ?? '';
        $prefix  = DB::one('SELECT v FROM settings WHERE k=?', ['discord_prefix'])['v'] ?? '!';
        $status  = DB::one('SELECT v FROM settings WHERE k=?', ['discord_status'])['v'] ?? 'disconnected';
        \view('discord/index', ['title' => 'Discord Bot','token' => $token !== '' ? 'configured' : '','channel' => $channel,'guild' => $guild,'prefix' => $prefix,'status' => $status]);
    }
    public function save()
    {
        \check_csrf();
        \require_role('admin');
        foreach (['discord_token','discord_channel','discord_guild','discord_prefix'] as $k) {
            $key = str_replace('discord_', '', $k);
            $val = trim($_POST[$key] ?? '');
            if ($k === 'discord_token' && $val === '') {
                continue; // An empty password input preserves the saved secret.
            }
            DB::q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $val]);
        }
        \log_activity('update-discord-config');
        \flash('success', 'Discord bot configuration saved.');
        \redirect('/discord');
    }
    public function test()
    {
        \check_csrf();
        \require_role('admin');
        $token = DB::one('SELECT v FROM settings WHERE k=?', ['discord_token'])['v'] ?? '';
        if (!$token) {
            \flash('error', 'Set a bot token first.');
            \redirect('/discord');
        }
        // Ping Discord API (read-only)
        $ch = curl_init('https://discord.com/api/v10/users/@me');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bot ' . $token],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        ]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            DB::q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', ['discord_status','connected']);
            \flash('success', 'Bot connected. Identity verified.');
        } else {
            DB::q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', ['discord_status','failed']);
            \flash('error', 'Bot connection failed (HTTP ' . $code . '). Check token.');
        }
        \redirect('/discord');
    }
}
