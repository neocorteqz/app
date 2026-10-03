<?php

namespace App\Controllers;

use DB;

class Mods
{
    public function index()
    {
        \require_login();
        $game = $_GET['game'] ?? 'all';
        $sql = 'SELECT * FROM mod_loaders';
        $args = [];
        if ($game !== 'all') {
            $sql .= ' WHERE game=?';
            $args[] = $game;
        }
        $sql .= ' ORDER BY popular DESC, category, name';
        $loaders = DB::all($sql, $args);
        $by_game = [];
        foreach ($loaders as $l) {
            $by_game[$l['game']][] = $l;
        }
        \view('mods/index', ['title' => 'Server Installations','loaders' => $loaders,'by_game' => $by_game,'filter' => $game]);
    }
    public function show(int $id)
    {
        \require_login();
        $l = DB::one('SELECT * FROM mod_loaders WHERE id=?', [$id]);
        if (!$l) {
            http_response_code(404);
            \view('errors/404');
            return;
        }
        $nodes = DB::all('SELECT * FROM nodes ORDER BY name');
        \view('mods/show', ['title' => $l['name'],'l' => $l,'nodes' => $nodes]);
    }
    public function apiForGame()
    {
        \require_login();
        $game = $_GET['game'] ?? '';
        if (!in_array($game, ['minecraft-java','minecraft-bedrock','cs2','rust'])) {
            \json_response([]);
        }
        $rows = DB::all('SELECT id, slug, name, category, tagline, logo_char, accent_color, requires_pack_id, popular FROM mod_loaders WHERE game=? ORDER BY popular DESC, category, name', [$game]);
        \json_response($rows);
    }
    public function apiPreview()
    {
        \require_login();
        $source = $_GET['source'] ?? '';
        $ref = trim($_GET['ref'] ?? '');
        // Normalize FTB → curseforge (FTB packs are hosted on CurseForge)
        if ($source === 'ftb') {
            $source = 'curseforge';
        }
        if (!$ref || !in_array($source, ['modrinth','curseforge'])) {
            \json_response(['ok' => false, 'error' => 'invalid source or ref']);
        }
        $url = "http://127.0.0.1:8001/api/daemon/modpack/preview?source=" . urlencode($source) . "&ref=" . urlencode($ref);
        $ctx = stream_context_create(['http' => ['timeout' => 20,'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            \json_response(['ok' => false,'error' => 'daemon unreachable']);
        }
        header('Content-Type: application/json');
        echo $resp;
    }
}
