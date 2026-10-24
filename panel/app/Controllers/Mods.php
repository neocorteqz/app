<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * Mods controller: server variants, loaders, and modpack sources.
 *
 * Exposes the modpack preview endpoint backed by the daemon API.
 */
class Mods
{
    private const VALID_GAMES = ['minecraft-java', 'minecraft-bedrock', 'cs2', 'rust'];

    /**
     * List mod loaders, optionally filtered by game.
     *
     * @return void
     */
    public function index(): void
    {
        \require_login();
        $game = (string) ($_GET['game'] ?? 'all');
        $sql  = 'SELECT * FROM mod_loaders';
        $args = [];
        if ($game !== 'all') {
            $sql .= ' WHERE game=?';
            $args[] = $game;
        }
        $sql .= ' ORDER BY popular DESC, category, name';
        $loaders = DB::all($sql, $args);
        $byGame = [];
        foreach ($loaders as $l) {
            $byGame[$l['game']][] = $l;
        }
        \view('mods/index', ['title' => 'Server Installations', 'loaders' => $loaders, 'by_game' => $byGame, 'filter' => $game]);
    }

    /**
     * Show a single loader's details and deploy form.
     *
     * @param int $id Loader id.
     * @return void
     */
    public function show(int $id): void
    {
        \require_login();
        $l = DB::one('SELECT * FROM mod_loaders WHERE id=?', [$id]);
        if (!$l) {
            http_response_code(404);
            \view('errors/404');
            return;
        }
        $nodes = DB::all('SELECT * FROM nodes ORDER BY name');
        \view('mods/show', ['title' => $l['name'], 'l' => $l, 'nodes' => $nodes]);
    }

    /**
     * JSON list of loaders for a game.
     *
     * @return void
     */
    public function apiForGame(): void
    {
        \require_login();
        $game = (string) ($_GET['game'] ?? '');
        if (!in_array($game, self::VALID_GAMES, true)) {
            \json_response([]);
        }
        $rows = DB::all(
            'SELECT id, slug, name, category, tagline, logo_char, accent_color, requires_pack_id, popular FROM mod_loaders WHERE game=? ORDER BY popular DESC, category, name',
            [$game]
        );
        \json_response($rows);
    }

    /**
     * Proxy a modpack preview request to the daemon API.
     *
     * @return void
     */
    public function apiPreview(): void
    {
        \require_login();
        $source = (string) ($_GET['source'] ?? '');
        $ref    = trim((string) ($_GET['ref'] ?? ''));
        if ($source === 'ftb') {
            $source = 'curseforge';
        }
        if (!$ref || !in_array($source, ['modrinth', 'curseforge'], true)) {
            \json_response(['ok' => false, 'error' => 'invalid source or ref']);
        }
        $url = "http://127.0.0.1:8001/api/daemon/modpack/preview?source=" . urlencode($source) . "&ref=" . urlencode($ref);
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            \json_response(['ok' => false, 'error' => 'daemon unreachable']);
        }
        header('Content-Type: application/json');
        echo $resp;
    }
}
