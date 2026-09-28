<?php
// Router entry point - handles all requests
require_once __DIR__ . '/../app/helpers.php';

require_https();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// Serve static files directly through built-in server (except dynamic routes)
$dynamic_paths = ['/theme.css', '/service-worker.js', '/manifest.webmanifest', '/install.sh', '/install-daemon.sh'];
if (!in_array($path, $dynamic_paths) && preg_match('#\.(css|js|png|jpg|jpeg|svg|ico|webp|woff2?)$#i', $path)) {
    return false;
}

// Route map
$routes = [
    'GET /'                        => ['App\\Controllers\\Home', 'index'],
    'GET /login'                   => ['App\\Controllers\\Auth', 'showLogin'],
    'POST /login'                  => ['App\\Controllers\\Auth', 'login'],
    'GET /register'                => ['App\\Controllers\\Auth', 'showRegister'],
    'POST /register'               => ['App\\Controllers\\Auth', 'register'],
    'POST /logout'                 => ['App\\Controllers\\Auth', 'logout'],

    'GET /dashboard'               => ['App\\Controllers\\Dashboard', 'index'],
    'GET /servers'                 => ['App\\Controllers\\Servers', 'index'],
    'GET /servers/new'             => ['App\\Controllers\\Servers', 'create'],
    'POST /servers'                => ['App\\Controllers\\Servers', 'store'],
    'POST /servers/action'         => ['App\\Controllers\\Servers', 'action'],
    'POST /servers/delete'         => ['App\\Controllers\\Servers', 'delete'],
    'GET /json/servers'             => ['App\\Controllers\\Servers', 'apiList'],
    'GET /json/servers/logs'        => ['App\\Controllers\\Servers', 'apiLogs'],
    'POST /json/servers/console'    => ['App\\Controllers\\Servers', 'apiConsoleCmd'],

    'GET /nodes'                   => ['App\\Controllers\\Nodes', 'index'],
    'POST /nodes'                  => ['App\\Controllers\\Nodes', 'store'],
    'POST /nodes/delete'           => ['App\\Controllers\\Nodes', 'delete'],

    'GET /users'                   => ['App\\Controllers\\Users', 'index'],
    'POST /users'                  => ['App\\Controllers\\Users', 'store'],
    'POST /users/delete'           => ['App\\Controllers\\Users', 'delete'],
    'GET /database-users'          => ['App\\Controllers\\DatabaseUsers', 'index'],
    'POST /database-users'         => ['App\\Controllers\\DatabaseUsers', 'store'],
    'POST /database-users/delete'  => ['App\\Controllers\\DatabaseUsers', 'delete'],

    'GET /theme'                   => ['App\\Controllers\\Theme', 'index'],
    'POST /theme'                  => ['App\\Controllers\\Theme', 'save'],
    'GET /theme.css'               => ['App\\Controllers\\Theme', 'css'],

    'GET /discord'                 => ['App\\Controllers\\Discord', 'index'],
    'POST /discord'                => ['App\\Controllers\\Discord', 'save'],
    'POST /discord/test'           => ['App\\Controllers\\Discord', 'test'],

    'GET /activity'                => ['App\\Controllers\\Activity', 'index'],
    'GET /install'                 => ['App\\Controllers\\Install', 'index'],
    'GET /install.sh'              => ['App\\Controllers\\Install', 'panelScript'],
    'GET /install-daemon.sh'       => ['App\\Controllers\\Install', 'daemonScript'],
    'GET /manifest.webmanifest'    => ['App\\Controllers\\Pwa', 'manifest'],
    'GET /service-worker.js'       => ['App\\Controllers\\Pwa', 'serviceWorker'],

    'GET /eggs'                    => ['App\\Controllers\\Eggs', 'index'],
    'POST /eggs/import'            => ['App\\Controllers\\Eggs', 'import'],
    'GET /mods'                    => ['App\\Controllers\\Mods', 'index'],
    'GET /jobs'                    => ['App\\Controllers\\Jobs', 'index'],
    'GET /json/loaders'            => ['App\\Controllers\\Mods', 'apiForGame'],
    'GET /json/modpack/preview'    => ['App\\Controllers\\Mods', 'apiPreview'],
];

// Jobs API
if (preg_match('#^/json/jobs/(\d+)$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Jobs.php';
    (new App\Controllers\Jobs())->apiShow((int)$m[1]); return true;
}
if (preg_match('#^/jobs/(\d+)/cancel$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Jobs.php';
    (new App\Controllers\Jobs())->cancel((int)$m[1]); return true;
}
if (preg_match('#^/json/servers/(\d+)/jobs$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Jobs.php';
    (new App\Controllers\Jobs())->apiForServer((int)$m[1]); return true;
}

// Daemon API passthrough
if (($path === '/api/daemon/health' || preg_match('#^/api/daemon/status/(\d+)$#', $path)) && $method === 'GET') {
    $target = 'http://127.0.0.1:8001' . $path;
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        header('Content-Type: application/json');
        echo $resp;
        return true;
    }
    http_response_code($code ?: 502);
    echo $resp ?: '{"error":"daemon_unreachable"}';
    return true;
}
if (preg_match('#^/api/daemon/(start|stop|restart|console)/(\d+)$#', $path, $m) && in_array($method, ['POST','GET'], true)) {
    $target = 'http://127.0.0.1:8001' . $path;
    $body = null;
    if ($method === 'POST' && $m[1] === 'console') {
        $body = file_get_contents('php://input');
    }
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        header('Content-Type: application/json');
        echo $resp;
        return true;
    }
    http_response_code($code ?: 502);
    echo $resp ?: '{"error":"daemon_unreachable"}';
    return true;
}

// Mods dynamic
if (preg_match('#^/mods/(\d+)$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Mods.php';
    (new App\Controllers\Mods())->show((int)$m[1]); return true;
}

// Egg dynamic routes
if (preg_match('#^/eggs/(\d+)$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Eggs.php';
    (new App\Controllers\Eggs())->show((int)$m[1]); return true;
}
if (preg_match('#^/eggs/(\d+)/deploy$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Eggs.php';
    (new App\Controllers\Eggs())->deploy((int)$m[1]); return true;
}

// File Manager
if (preg_match('#^/servers/(\d+)/modpack/install$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Servers.php';
    (new App\Controllers\Servers())->installPack((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/files$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Files.php';
    (new App\Controllers\Files())->index((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/files/edit$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Files.php';
    (new App\Controllers\Files())->edit((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/files/save$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Files.php';
    (new App\Controllers\Files())->save((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/files/mkdir$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Files.php';
    (new App\Controllers\Files())->mkdir((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/files/touch$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Files.php';
    (new App\Controllers\Files())->touch((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/files/delete$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Files.php';
    (new App\Controllers\Files())->delete((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/files/upload$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Files.php';
    (new App\Controllers\Files())->upload((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/files/download$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Files.php';
    (new App\Controllers\Files())->download((int)$m[1]); return true;
}

// Backups
if (preg_match('#^/servers/(\d+)/backups$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Backups.php';
    (new App\Controllers\Backups())->index((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/backups/schedule$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Backups.php';
    (new App\Controllers\Backups())->saveSchedule((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/backups/run$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Backups.php';
    (new App\Controllers\Backups())->runNow((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/backups/restore$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Backups.php';
    (new App\Controllers\Backups())->restore((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/backups/delete$#', $path, $m) && $method === 'POST') {
    require_once __DIR__ . '/../app/Controllers/Backups.php';
    (new App\Controllers\Backups())->delete((int)$m[1]); return true;
}
if (preg_match('#^/servers/(\d+)/backups/download$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Backups.php';
    (new App\Controllers\Backups())->download((int)$m[1]); return true;
}

// Server detail routes (dynamic ID)
if (preg_match('#^/servers/(\d+)$#', $path, $m) && $method === 'GET') {
    require_once __DIR__ . '/../app/Controllers/Servers.php';
    (new App\Controllers\Servers())->show((int)$m[1]);
    return true;
}

$key = "$method $path";
if (isset($routes[$key])) {
    [$class, $action] = $routes[$key];
    $file = __DIR__ . '/../app/Controllers/' . basename(str_replace('App\\Controllers\\', '', $class)) . '.php';
    require_once $file;
    $controller = new $class();
    $controller->$action();
    return true;
}

http_response_code(404);
view('errors/404');
