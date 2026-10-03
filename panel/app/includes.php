<?php
// Session, helpers, auth
function is_https_request(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) !== 'off') {
        return true;
    }
    return false;
}

function is_local_request(): bool {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
        || in_array($remote, ['127.0.0.1', '::1'], true);
}

function require_https(): void {
    if (is_https_request() || is_local_request()) {
        return;
    }
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    $secure = is_https_request();
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 8,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('apexnode_sess');
    session_start();
}

require_once __DIR__ . '/db.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\Controllers\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z]+$/D', $name)) return;
    $file = __DIR__ . '/Controllers/' . $name . '.php';
    if (is_file($file)) require_once $file;
});

function apex_state_root(): string {
    $config = require __DIR__ . '/../config/config.php';
    return rtrim((string)($config['state_root'] ?? '/var/lib/apexnode'), '/');
}

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function e(string $s): string { return h($s); }

function apex_base_path(): string {
    $config = require __DIR__ . '/../config/config.php';
    return rtrim((string)$config['base_path'], '/');
}

function url(string $path = ''): string {
    return apex_base_path() . '/' . ltrim($path, '/');
}

function redirect(string $to): void {
    if (str_starts_with($to, '/') && !str_starts_with($to, '//')) {
        $base = apex_base_path();
        if ($base !== '' && $to !== $base && !str_starts_with($to, $base . '/')) $to = url($to);
    }
    header('Location: ' . $to);
    exit;
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf() . '">';
}

function check_csrf(): void {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

function auth_user(): ?array {
    static $cachedId = null, $cachedUser = null;
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid !== 0 && $cachedId === $uid) return $cachedUser;
    if (!empty($_SESSION['uid'])) {
        $cachedId = $uid;
        return $cachedUser = DB::one('SELECT id, username, email, role FROM users WHERE id=?', [$uid]);
    }
    return null;
}

function require_login(): array {
    $u = auth_user();
    if (!$u) redirect('/login');
    return $u;
}

function require_role(string $role): array {
    $u = require_login();
    $order = ['viewer' => 1, 'operator' => 2, 'admin' => 3];
    if (($order[$u['role']] ?? 0) < ($order[$role] ?? 99)) {
        http_response_code(403);
        die('Forbidden');
    }
    return $u;
}

function require_server_permission(int $serverId, string $permission): array {
    $u = require_login();
    $valid = ['view_server', 'view_console', 'control_server', 'view_files', 'manage_files', 'view_backups', 'manage_backups'];
    if (!in_array($permission, $valid, true)) throw new InvalidArgumentException('Unknown server permission.');
    if (in_array($u['role'], ['admin', 'operator'], true)) return $u;
    $server = DB::one('SELECT owner_id FROM servers WHERE id=?', [$serverId]);
    if (!$server) { http_response_code(404); view('errors/404'); exit; }
    if ((int)$server['owner_id'] === (int)$u['id']) return $u;
    $access = DB::one("SELECT {$permission} AS allowed FROM server_access WHERE server_id=? AND user_id=?", [$serverId, $u['id']]);
    if (empty($access['allowed'])) { http_response_code(403); die('Forbidden'); }
    return $u;
}

function accessible_server_filter(array $user, string $alias = 's'): array {
    if (in_array($user['role'], ['admin', 'operator'], true)) return ['', []];
    return [" WHERE ({$alias}.owner_id=? OR EXISTS (SELECT 1 FROM server_access sa WHERE sa.server_id={$alias}.id AND sa.user_id=? AND sa.view_server=1))", [(int)$user['id'], (int)$user['id']]];
}

function flash(string $key, ?string $msg = null) {
    if ($msg === null) {
        $v = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $v;
    }
    $_SESSION['flash'][$key] = $msg;
}

function log_activity(string $action, string $target = '', string $detail = ''): void {
    $u = auth_user();
    DB::insert('activity_log', [
        'user_id' => $u['id'] ?? null,
        'action' => $action,
        'target' => $target,
        'detail' => $detail,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

function user_theme(): array {
    $u = auth_user();
    $defaults = ['accent'=>'#00F0FF','radius'=>'12px','density'=>'comfortable','mode'=>'dark','font'=>'Outfit'];
    if (!$u) return $defaults;
    static $themes = [];
    if (!isset($themes[$u['id']])) {
        $themes[$u['id']] = DB::one('SELECT accent, radius, density, mode, font FROM user_themes WHERE user_id=?', [$u['id']]) ?: $defaults;
    }
    return $themes[$u['id']];
}

function view(string $name, array $data = []): void {
    extract($data);
    $user = auth_user();
    $theme = user_theme();
    require __DIR__ . '/../views/layout_top.php';
    require __DIR__ . '/../views/' . $name . '.php';
    require __DIR__ . '/../views/layout_bottom.php';
}

function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function game_meta(string $game): array {
    $m = [
        'minecraft-java'    => ['label'=>'Minecraft: Java',   'icon'=>'⛏',  'color'=>'#4CAF50', 'default_port'=>25565],
        'minecraft-bedrock' => ['label'=>'Minecraft: Bedrock','icon'=>'⛏',  'color'=>'#A855F7', 'default_port'=>19132],
        'cs2'               => ['label'=>'Counter-Strike 2',  'icon'=>'⌖',  'color'=>'#F59E0B', 'default_port'=>27015],
        'rust'              => ['label'=>'Rust',              'icon'=>'⚙',  'color'=>'#EF4444', 'default_port'=>28015],
    ];
    return $m[$game] ?? ['label'=>$game,'icon'=>'▣','color'=>'#00F0FF','default_port'=>25565];
}
