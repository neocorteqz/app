<?php

namespace App\Controllers;

use DB;

class Eggs
{
    public function index()
    {
        \require_login();
        $game = $_GET['game'] ?? 'all';
        $sql = 'SELECT * FROM eggs';
        $args = [];
        if ($game !== 'all') {
            $sql .= ' WHERE game = ?';
            $args[] = $game;
        }
        $sql .= ' ORDER BY featured DESC, downloads DESC, id ASC';
        $eggs = DB::all($sql, $args);
        \view('eggs/index', ['title' => 'Egg Marketplace','eggs' => $eggs,'filter' => $game]);
    }
    public function show(int $id)
    {
        \require_login();
        $egg = DB::one('SELECT * FROM eggs WHERE id=?', [$id]);
        if (!$egg) {
            http_response_code(404);
            \view('errors/404');
            return;
        }
        \view('eggs/show', ['title' => $egg['name'],'egg' => $egg]);
    }
    public function deploy(int $id)
    {
        \require_role('operator');
        $egg = DB::one('SELECT * FROM eggs WHERE id=?', [$id]);
        if (!$egg) {
            \flash('error', 'Egg not found.');
            \redirect('/eggs');
        }
        $nodes = DB::all('SELECT * FROM nodes ORDER BY name');
        \view('eggs/deploy', ['title' => 'Deploy ' . $egg['name'],'egg' => $egg,'nodes' => $nodes]);
    }
    public function import()
    {
        \check_csrf();
        \require_role('operator');
        $raw = trim((string)($_POST['egg_json'] ?? ''));
        if ($raw === '') {
            \flash('error', 'Egg JSON is required.');
            \redirect('/eggs');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            \flash('error', 'Invalid Pterodactyl egg JSON.');
            \redirect('/eggs');
        }
        $confirmedHash = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (empty($_SESSION['egg_import_preview']) || !hash_equals($_SESSION['egg_import_preview'], $confirmedHash)) {
            \flash('error', 'Review this egg before importing it.');
            \redirect('/eggs');
        }
        unset($_SESSION['egg_import_preview']);
        $name = trim((string)($data['name'] ?? 'Custom Egg'));
        $sourceHash = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $tagline = trim((string)($data['description'] ?? 'Imported from Pterodactyl'));
        $description = $tagline !== '' ? $tagline : 'Imported from a Pterodactyl egg definition.';
        $game = $this->detectGame($data, $name);
        $start = trim((string)($data['startup'] ?? $data['start_command'] ?? ''));
        if ($start === '') {
            $start = 'bash /start.sh';
        }
        $dockerImage = '';
        if (!empty($data['docker_images'])) {
            $dockerImage = (string)array_values($data['docker_images'])[0];
        } elseif (!empty($data['docker_image'])) {
            $dockerImage = (string)$data['docker_image'];
        }
        $defaultEnv = [];
        foreach (($data['variables'] ?? []) as $variable) {
            if (is_array($variable) && !empty($variable['env_variable'])) {
                $defaultEnv[(string)$variable['env_variable']] = (string)($variable['default_value'] ?? '');
            }
        }
        $defaultFiles = [];
        if (!empty($data['config']['files'])) {
            foreach ((array)$data['config']['files'] as $path => $content) {
                if (is_string($path) && is_string($content)) {
                    $defaultFiles[$path] = $content;
                }
            }
        }
        $existing = DB::one('SELECT id, source_hash FROM eggs WHERE name=? LIMIT 1', [$name]);
        if ($existing) {
            if (empty($existing['source_hash'])) {
                \flash('error', 'An egg with that name already exists and was not imported from Pterodactyl.');
                \redirect('/eggs');
            }
            DB::q('UPDATE eggs SET game=?, tagline=?, description=?, start_command=?, docker_image=?, default_files=?, default_env=?, source_hash=? WHERE id=?', [
                $game, $tagline !== '' ? $tagline : 'Imported Pterodactyl egg', $description, $start, $dockerImage,
                $defaultFiles ? json_encode($defaultFiles) : null, $defaultEnv ? json_encode($defaultEnv) : null, $sourceHash, $existing['id'],
            ]);
            \log_activity('update-pterodactyl-egg', 'egg:' . $name, substr($sourceHash, 0, 12));
            \flash('success', 'Updated imported egg "' . $name . '".');
            \redirect('/eggs');
        }
        DB::insert('eggs', [
            'game' => $game,
            'name' => $name,
            'tagline' => $tagline !== '' ? $tagline : 'Imported Pterodactyl egg',
            'description' => $description,
            'start_command' => $start,
            'docker_image' => $dockerImage,
            'default_files' => $defaultFiles ? json_encode($defaultFiles) : null,
            'default_env' => $defaultEnv ? json_encode($defaultEnv) : null,
            'author' => 'Pterodactyl Import',
            'downloads' => 0,
            'featured' => 0,
            'source_hash' => $sourceHash,
        ]);
        \log_activity('import-pterodactyl-egg', 'egg:' . $name, substr($sourceHash, 0, 12));
        \flash('success', 'Imported "' . $name . '" from a Pterodactyl egg definition.');
        \redirect('/eggs');
    }
    public function previewImport()
    {
        \check_csrf();
        \require_role('operator');
        $raw = trim((string)($_POST['egg_json'] ?? ''));
        $data = json_decode($raw, true);
        if ($raw === '' || !is_array($data)) {
            \flash('error', 'Provide valid Pterodactyl egg JSON to preview.');
            \redirect('/eggs');
        }
        $name = trim((string)($data['name'] ?? 'Custom Egg'));
        $hash = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $_SESSION['egg_import_preview'] = $hash;
        $existing = DB::one('SELECT id, source_hash, game, start_command, docker_image FROM eggs WHERE name=? LIMIT 1', [$name]);
        if ($existing && empty($existing['source_hash'])) {
            $existing = null;
        }
        $game = $this->detectGame($data, $name);
        $images = $data['docker_images'] ?? [];
        if (is_string($images)) {
            $images = ['default' => $images];
        }
        $variables = array_values(array_filter($data['variables'] ?? [], fn($v) => is_array($v)));
        \view('eggs/preview', [
            'title' => 'Review Egg Import', 'egg_data' => $data, 'egg_json' => $raw, 'egg_hash' => $hash,
            'egg_name' => $name, 'egg_game' => $game, 'egg_start' => trim((string)($data['startup'] ?? $data['start_command'] ?? '')),
            'egg_images' => $images, 'egg_variables' => $variables, 'existing_egg' => $existing,
        ]);
    }
    private function detectGame(array $data, string $name): string
    {
        $haystack = strtolower($name . ' ' . json_encode($data));
        $matches = [
            'minecraft-java' => ['minecraft', 'paper', 'forge', 'fabric', 'spigot', 'vanilla'],
            'minecraft-bedrock' => ['bedrock', 'geyser', 'pe', 'minecraft bedrock'],
            'cs2' => ['cs2', 'counter-strike', 'css', 'source', 'counter strike'],
            'rust' => ['rust', 'oxide'],
        ];
        foreach ($matches as $game => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, (string)$keyword)) {
                    return $game;
                }
            }
        }
        return 'minecraft-java';
    }
}
