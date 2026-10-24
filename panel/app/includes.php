<?php

declare(strict_types=1);

/**
 * Shared web bootstrap: sessions, helpers, authentication, controller loading.
 *
 * Included on every panel request after the application has been installed.
 */

/**
 * Determine whether the current request arrived over HTTPS.
 *
 * Trusts X-Forwarded-Proto / X-Forwarded-SSL only from loopback proxies, so a
 * separate (non-loopback) proxy cannot trick the panel into marking an HTTP
 * request as secure.
 *
 * @return bool True when the request is HTTPS or local.
 */
function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $trustedProxy = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if ($trustedProxy && !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if ($trustedProxy && !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) !== 'off') {
        return true;
    }
    return false;
}

/**
 * Determine whether the current request originated from loopback.
 *
 * @return bool True when REMOTE_ADDR is 127.0.0.1 or ::1.
 */
function is_local_request(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($remote, ['127.0.0.1', '::1'], true);
}

/**
 * Require HTTPS for non-local requests, redirecting to the secure URL.
 *
 * Local requests are exempt so the upload installer and loopback helpers work
 * without a certificate. The Host header is validated before use to prevent
 * header-injection redirects.
 *
 * @return void
 */
function require_https(): void
{
    if (is_https_request() || is_local_request()) {
        return;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^(?:[A-Za-z0-9.-]+|\[[A-Fa-f0-9:]+\])(?::[0-9]+)?$/D', $host)) {
        http_response_code(400);
        exit('Invalid host');
    }
    $host = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

/**
 * Initialise the panel session with secure cookie parameters.
 *
 * Called once per request. Enables strict mode, cookie-only sessions, and sets
 * Secure/HttpOnly/SameSite=Lax attributes over HTTPS.
 *
 * @return void
 */
function init_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $secure = is_https_request();
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 8,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('apexnode_sess');
        session_start();
    }
}

/**
 * Register the App\Controllers autoloader.
 *
 * Only classes beneath the App\Controllers namespace are loaded, and the class
 * name is validated to contain letters only so no path can escape Controllers/.
 *
 * @return void
 */
function register_autoloader(): void
{
    $prefix = 'App\\Controllers\\';
    spl_autoload_register(static function (string $class) use ($prefix): void {
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $name = substr($class, strlen($prefix));
        if (!preg_match('/^[A-Za-z]+$/D', $name)) {
            return;
        }
        $file = __DIR__ . '/Controllers/' . $name . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

/**
 * Return the configured state root directory.
 *
 * @return string The state root without a trailing slash.
 */
function apex_state_root(): string
{
    $config = require __DIR__ . '/../config/config.php';
    return rtrim((string) ($config['state_root'] ?? '/var/lib/apexnode'), '/');
}

/**
 * HTML-escape a string for safe output.
 *
 * @param string|null $s The value to escape.
 * @return string The escaped string.
 */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Alias of h() for templates that prefer a short name.
 *
 * @param string $s The value to escape.
 * @return string The escaped string.
 */
function e(string $s): string
{
    return h($s);
}

/**
 * Return the configured base path (URL prefix) without a trailing slash.
 *
 * @return string The base path.
 */
function apex_base_path(): string
{
    $config = require __DIR__ . '/../config/config.php';
    return rtrim((string) $config['base_path'], '/');
}

/**
 * Build a panel URL by prepending the base path.
 *
 * @param string $path A path, optionally with a leading slash.
 * @return string The full panel-relative URL.
 */
function url(string $path = ''): string
{
    return apex_base_path() . '/' . ltrim($path, '/');
}

/**
 * Send a Location header and stop execution.
 *
 * Only same-origin relative paths are accepted; anything malformed falls back
 * to /dashboard. This prevents open-redirect and CRLF header injection.
 *
 * @param string $to The target path.
 * @return void
 */
function redirect(string $to): void
{
    if (!str_starts_with($to, '/') || str_starts_with($to, '//') || preg_match('/[\x00-\x20\\\\]/', $to)) {
        $to = '/dashboard';
    }
    $base = apex_base_path();
    if ($base !== '' && $to !== $base && !str_starts_with($to, $base . '/')) {
        $to = url($to);
    }
    header('Location: ' . $to);
    exit;
}

/**
 * Return the CSRF token for the current session, generating one if needed.
 *
 * @return string A random CSRF token.
 */
function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

/**
 * Render a CSRF hidden input field.
 *
 * @return string A <input type="hidden"> element.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf() . '">';
}

/**
 * Validate the CSRF token for state-changing requests.
 *
 * Reads from $_POST['_csrf'] or the X-CSRF header and compares it in constant
 * time against the session token. Sends 419 and exits on mismatch.
 *
 * @return void
 */
function check_csrf(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    $expected = $_SESSION['csrf'] ?? '';
    if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '' || !hash_equals($expected, $token)) {
        http_response_code(419);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

/**
 * Return the currently signed-in user, cached for the request.
 *
 * @return array|null The user row, or null when no one is signed in.
 */
function auth_user(): ?array
{
    static $cachedId = null, $cachedUser = null;
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid !== 0 && $cachedId === $uid) {
        return $cachedUser;
    }
    if (!empty($_SESSION['uid'])) {
        $cachedId = $uid;
        return $cachedUser = DB::one('SELECT id, username, email, role FROM users WHERE id=?', [$uid]);
    }
    return null;
}

/**
 * Require a signed-in user, redirecting to /login otherwise.
 *
 * @return array The signed-in user row.
 */
function require_login(): array
{
    $u = auth_user();
    if (!$u) {
        redirect('/login');
    }
    return $u;
}

/**
 * Require a user with at least the given role.
 *
 * Role order: viewer < operator < admin.
 *
 * @param string $role The minimum required role.
 * @return array The signed-in user row.
 */
function require_role(string $role): array
{
    $u = require_login();
    $order = ['viewer' => 1, 'operator' => 2, 'admin' => 3];
    if (($order[$u['role']] ?? 0) < ($order[$role] ?? 99)) {
        http_response_code(403);
        die('Forbidden');
    }
    return $u;
}

/**
 * Require login plus a specific permission on a server.
 *
 * Admins and operators bypass per-server access checks. Viewers are checked
 * against server_access using a whitelisted permission column.
 *
 * @param int    $serverId The server id.
 * @param string $permission One of the valid server permissions.
 * @return array The signed-in user row.
 * @throws InvalidArgumentException When the permission is unknown.
 */
function require_server_permission(int $serverId, string $permission): array
{
    $u = require_login();
    $valid = ['view_server', 'view_console', 'control_server', 'view_files', 'manage_files', 'view_backups', 'manage_backups'];
    if (!in_array($permission, $valid, true)) {
        throw new InvalidArgumentException('Unknown server permission.');
    }
    if (in_array($u['role'], ['admin', 'operator'], true)) {
        return $u;
    }
    $server = DB::one('SELECT owner_id FROM servers WHERE id=?', [$serverId]);
    if (!$server) {
        http_response_code(404);
        view('errors/404');
        exit;
    }
    if ((int) $server['owner_id'] === (int) $u['id']) {
        return $u;
    }
    $access = DB::one("SELECT {$permission} AS allowed FROM server_access WHERE server_id=? AND user_id=?", [$serverId, $u['id']]);
    if (empty($access['allowed'])) {
        http_response_code(403);
        die('Forbidden');
    }
    return $u;
}

/**
 * Build a WHERE clause + args that restricts results to servers a user may see.
 *
 * @param array  $user  The signed-in user row.
 * @param string $alias The table alias to prefix (default 's').
 * @return array{0:string, 1:array} The WHERE fragment and bound arguments.
 */
function accessible_server_filter(array $user, string $alias = 's'): array
{
    if (in_array($user['role'], ['admin', 'operator'], true)) {
        return ['', []];
    }
    return [
        " WHERE ({$alias}.owner_id=? OR EXISTS (SELECT 1 FROM server_access sa WHERE sa.server_id={$alias}.id AND sa.user_id=? AND sa.view_server=1))",
        [(int) $user['id'], (int) $user['id']],
    ];
}

/**
 * Get or consume a flash message stored in the session.
 *
 * @param string $key  The flash key.
 * @param string|null $msg When set, stores the message; otherwise returns and clears it.
 * @return string|null The stored message, or null when none exists.
 */
function flash(string $key, ?string $msg = null): ?string
{
    if ($msg === null) {
        $v = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $v;
    }
    $_SESSION['flash'][$key] = $msg;
    return null;
}

/**
 * Write an activity log entry for the signed-in user.
 *
 * @param string $action The action taken.
 * @param string $target The target of the action.
 * @param string $detail Additional detail.
 * @return void
 */
function log_activity(string $action, string $target = '', string $detail = ''): void
{
    $u = auth_user();
    DB::insert('activity_log', [
        'user_id'  => $u['id'] ?? null,
        'action'   => $action,
        'target'   => $target,
        'detail'   => $detail,
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

/**
 * Return the signed-in user's theme, normalised against allowed values.
 *
 * @param array|null $user The user row, or null when unsigned.
 * @return array<string,string> The normalised theme values.
 */
function user_theme(?array $user = null): array
{
    if ($user === null) {
        $user = auth_user();
    }
    $defaults = ['accent' => '#00F0FF', 'radius' => '12px', 'density' => 'comfortable', 'mode' => 'dark', 'font' => 'Outfit'];
    if (!$user) {
        return $defaults;
    }
    static $themes = [];
    $uid = (int) $user['id'];
    if (!isset($themes[$uid])) {
        $themes[$uid] = DB::one('SELECT accent, radius, density, mode, font FROM user_themes WHERE user_id=?', [$uid]) ?: $defaults;
    }
    return normalize_theme($themes[$uid]);
}

/**
 * Send an email using PHP's mail() function with optional SMTP-like config.
 *
 * Falls back gracefully when mail is unavailable (writes to the error log).
 * Recipient and subject are sanitised to prevent header injection.
 *
 * @param string $to      Recipient address.
 * @param string $subject Email subject.
 * @param string $body    Email body.
 * @param array  $headers Additional headers (optional).
 * @return bool True when mail was accepted for delivery, false otherwise.
 */
function send_mail(string $to, string $subject, string $body, array $headers = []): bool
{
    $config = require __DIR__ . '/../config/config.php';
    $mail   = $config['mail'] ?? [];

    $to = filter_var($to, FILTER_VALIDATE_EMAIL) ?: '';
    if ($to === '') {
        error_log('[send_mail] invalid recipient');
        return false;
    }
    $subject = preg_replace('/[\r\n]+/', '', $subject);

    $allHeaders = [];
    if (!empty($mail['from'])) {
        $fromName = !empty($mail['from_name']) ? $mail['from_name'] : 'ApexNode';
        $fromName = preg_replace('/[\r\n]+/', '', $fromName);
        $allHeaders[] = 'From: "' . $fromName . '" <' . $mail['from'] . '>';
    }
    $allHeaders[] = 'MIME-Version: 1.0';
    $allHeaders[] = 'Content-Type: text/plain; charset=UTF-8';
    $allHeaders[] = 'X-Mailer: ApexNode/1.0';
    foreach ($headers as $key => $value) {
        $key   = preg_replace('/[\r\n]+/', '', $key);
        $value = preg_replace('/[\r\n]+/', '', $value);
        $allHeaders[] = $key . ': ' . $value;
    }

    $result = @mail($to, $subject, $body, implode("\r\n", $allHeaders));
    if (!$result) {
        error_log('[send_mail] mail() rejected recipient: ' . $to);
    }
    return (bool) $result;
}
{
    extract($data);
    $user   = auth_user();
    $theme  = user_theme($user);
    require __DIR__ . '/../views/layout_top.php';
    require __DIR__ . '/../views/' . $name . '.php';
    require __DIR__ . '/../views/layout_bottom.php';
}

/**
 * Send a JSON response and stop execution.
 *
 * @param mixed  $data The payload to encode.
 * @param int    $code The HTTP status code.
 * @return void
 */
function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Return metadata (label, icon, colour, default port) for a supported game.
 *
 * @param string $game The game key.
 * @return array<string,string|int> The game metadata.
 */
function game_meta(string $game): array
{
    $m = [
        'minecraft-java'    => ['label' => 'Minecraft: Java',   'icon' => '⛏',  'color' => '#4CAF50', 'default_port' => 25565],
        'minecraft-bedrock' => ['label' => 'Minecraft: Bedrock','icon' => '⛏',  'color' => '#A855F7', 'default_port' => 19132],
        'cs2'               => ['label' => 'Counter-Strike 2',  'icon' => '⌖',  'color' => '#F59E0B', 'default_port' => 27015],
        'rust'              => ['label' => 'Rust',              'icon' => '⚙',  'color' => '#EF4444', 'default_port' => 28015],
    ];
    return $m[$game] ?? ['label' => $game, 'icon' => '▣', 'color' => '#00F0FF', 'default_port' => 25565];
}

/**
 * Normalise a raw theme array against allowed values, falling back to defaults.
 *
 * @param array $theme Raw theme values from the database or POST.
 * @return array<string,string> The normalised theme values.
 */
function normalize_theme(array $theme): array
{
    $defaults = ['accent' => '#00F0FF', 'radius' => '12px', 'density' => 'comfortable', 'mode' => 'dark', 'font' => 'Outfit'];
    $options = [
        'radius'  => ['0px', '4px', '8px', '12px', '16px'],
        'density' => ['compact', 'comfortable', 'spacious'],
        'mode'    => ['dark', 'light'],
        'font'    => ['Outfit', 'Space Grotesk', 'Bricolage Grotesque', 'Plus Jakarta Sans', 'JetBrains Mono'],
    ];
    $result = $defaults;
    if (is_string($theme['accent'] ?? null) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $theme['accent'])) {
        $result['accent'] = $theme['accent'];
    }
    foreach ($options as $key => $allowed) {
        if (in_array($theme[$key] ?? null, $allowed, true)) {
            $result[$key] = $theme[$key];
        }
    }
    return $result;
}

/* --------------------------------------------------------------------------
 * Bootstrap side effects (run on inclusion)
 * --------------------------------------------------------------------- */

init_session();
require_once __DIR__ . '/db.php';
register_autoloader();
