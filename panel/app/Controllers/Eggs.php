<?php
namespace App\Controllers;
use DB;

class Eggs {
    public function index() {
        \require_login();
        $game = $_GET['game'] ?? 'all';
        $sql = 'SELECT * FROM eggs';
        $args = [];
        if ($game !== 'all') { $sql .= ' WHERE game = ?'; $args[] = $game; }
        $sql .= ' ORDER BY featured DESC, downloads DESC, id ASC';
        $eggs = DB::all($sql, $args);
        \view('eggs/index', ['title'=>'Egg Marketplace','eggs'=>$eggs,'filter'=>$game]);
    }
    public function show(int $id) {
        \require_login();
        $egg = DB::one('SELECT * FROM eggs WHERE id=?', [$id]);
        if (!$egg) { http_response_code(404); \view('errors/404'); return; }
        \view('eggs/show', ['title'=>$egg['name'],'egg'=>$egg]);
    }
    public function deploy(int $id) {
        \require_role('operator');
        $egg = DB::one('SELECT * FROM eggs WHERE id=?', [$id]);
        if (!$egg) { \flash('error','Egg not found.'); \redirect('/eggs'); }
        $nodes = DB::all('SELECT * FROM nodes ORDER BY name');
        \view('eggs/deploy', ['title'=>'Deploy '.$egg['name'],'egg'=>$egg,'nodes'=>$nodes]);
    }
    public function import() {
        \check_csrf();
        \require_role('operator');
        $raw = trim((string)($_POST['egg_json'] ?? ''));
        if ($raw === '') {
            \flash('error','Egg JSON is required.');
            \redirect('/eggs');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            \flash('error','Invalid Pterodactyl egg JSON.');
            \redirect('/eggs');
        }
        $name = trim((string)($data['name'] ?? 'Custom Egg'));
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
        $existing = DB::one('SELECT id FROM eggs WHERE name=? LIMIT 1', [$name]);
        if ($existing) {
            \flash('error','An egg with that name already exists.');
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
        ]);
        \flash('success','Imported "'.$name.'" from a Pterodactyl egg definition.');
        \redirect('/eggs');
    }
    private function detectGame(array $data, string $name): string {
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
