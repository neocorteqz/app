<?php
// Browser-only bootstrap; deliberately independent of app sessions and the database.
function apex_web_installer(): void {
    $root = dirname(__DIR__);
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    if (is_file($root . '/config/installed.php') || is_file($root . '/config/.env') || getenv('DB_NAME')) {
        http_response_code(403);
        echo 'Installation is locked. Use the panel login.';
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    // Reverse proxies must configure HTTPS in the web server; do not trust client headers here.
    if (!$https && !$local) {
        http_response_code(400);
        echo 'Open this installer using HTTPS. Ask your hosting provider to enable SSL for this directory.';
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('apexnode_setup');
        session_set_cookie_params(['secure'=>$https, 'httponly'=>true, 'samesite'=>'Strict']);
        session_start();
    }
    $_SESSION['setup_csrf'] ??= bin2hex(random_bytes(32));
    $checks = [
        'PHP 8.0 or newer' => PHP_VERSION_ID >= 80000,
        'PDO MySQL extension' => extension_loaded('pdo_mysql'),
        'cURL extension' => extension_loaded('curl'),
        'Writable config directory' => is_writable($root . '/config'),
        'Writable panel directory (local storage)' => is_writable($root),
    ];
    $error = '';
    $success = false;
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php')), '/.');
    if (!preg_match('#^(?:/[A-Za-z0-9_~.%-]+)*$#D', $base)) $base = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $lock = null;
        try {
            if (!is_string($_POST['_csrf'] ?? null) || !hash_equals($_SESSION['setup_csrf'], $_POST['_csrf'])) throw new RuntimeException('Session expired. Reload the installer and try again.');
            if (in_array(false, $checks, true)) throw new RuntimeException('Resolve the failed requirements before installing.');
            $lock = fopen($root . '/config/install.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another installation is running. Please wait.');
            if (is_file($root . '/config/installed.php') || is_file($root . '/config/.env')) throw new RuntimeException('Installation is already complete.');
            $host = trim((string)($_POST['db_host'] ?? ''));
            $port = filter_var($_POST['db_port'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>65535]]);
            $name = trim((string)($_POST['db_name'] ?? ''));
            $user = trim((string)($_POST['db_user'] ?? ''));
            $pass = (string)($_POST['db_pass'] ?? '');
            $admin = trim((string)($_POST['admin'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9.:-]+$/D', $host) || !$port || !preg_match('/^[a-zA-Z0-9_]+$/D', $name) || $user === '') throw new RuntimeException('Enter valid database connection details.');
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D', $admin) || strlen($email) > 128 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 72 || $password !== ($_POST['confirm'] ?? '')) throw new RuntimeException('Use a valid username/email and matching passwords of 12–72 bytes.');
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
            $fingerprint = hash('sha256', json_encode([$host, $port, $name, $user]));
            $journalPath = $root . '/config/install-progress.php';
            $journal = is_file($journalPath) ? require $journalPath : null;
            if ($journal !== null && $journal !== $fingerprint) throw new RuntimeException('A partial installation belongs to another database. Restore that connection to resume.');
            if ($journal === null) {
                if ($pdo->query('SHOW TABLES')->fetch()) throw new RuntimeException('Choose an empty database. Existing installations are not overwritten.');
                apex_setup_write($journalPath, $fingerprint);
            }
            $state = $root . '/storage';
            foreach (['', '/servers', '/backups', '/sessions'] as $dir) {
                if (!is_dir($state . $dir) && !mkdir($state . $dir, 0700, true)) throw new RuntimeException('Cannot create storage. Check directory permissions in your hosting file manager.');
            }
            // Files are bundled; no downloads, shell commands or database-root privileges.
            foreach (['schema.sql', 'schema_v2.sql', 'schema_v3.sql', 'schema_v4.sql', 'schema_v5.sql', 'schema_v6.sql', 'schema_v7.sql'] as $file) {
                $sql = preg_replace('/^--.*$/m', '', file_get_contents($root . '/db/' . $file));
                foreach (explode(';', $sql) as $statement) {
                    $statement = trim($statement);
                    if ($statement === '' || preg_match('/^(CREATE DATABASE|USE )/i', $statement)) continue;
                    // MariaDB-only IF NOT EXISTS syntax is handled explicitly for MySQL too.
                    if (preg_match('/^ALTER TABLE (\w+) ADD COLUMN IF NOT EXISTS (\w+) (.+)$/is', $statement, $m)) {
                        $q = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
                        $q->execute([$name, $m[1], $m[2]]);
                        if ($q->fetch()) continue;
                        $statement = "ALTER TABLE `$m[1]` ADD COLUMN `$m[2]` $m[3]";
                    }
                    if (preg_match('/^CREATE UNIQUE INDEX IF NOT EXISTS (\w+) ON (\w+) (.+)$/is', $statement, $m)) {
                        $q = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
                        $q->execute([$name, $m[2], $m[1]]);
                        if ($q->fetch()) continue;
                        $statement = "CREATE UNIQUE INDEX `$m[1]` ON `$m[2]` $m[3]";
                    }
                    if (preg_match('/^CREATE TABLE/i', $statement)) {
                        $statement .= ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
                    }
                    $pdo->exec($statement);
                }
            }
            require_once $root . '/app/db.php';
            DB::setConnection($pdo);
            ob_start();
            try {
                require $root . '/db/seed_v2.php';
                require $root . '/db/seed_v3.php';
            } finally { ob_end_clean(); }
            $existing = $pdo->query('SELECT username, email, password_hash FROM users LIMIT 1')->fetch();
            if ($existing && ($existing['username'] !== $admin || $existing['email'] !== $email || !password_verify($password, $existing['password_hash']))) throw new RuntimeException('Resume using the administrator details from the original attempt.');
            $pdo->beginTransaction();
            if (!$existing) {
                $q = $pdo->prepare('INSERT INTO users (username,email,password_hash,role) VALUES (?,?,?,?)');
                $q->execute([$admin, $email, password_hash($password, PASSWORD_BCRYPT), 'admin']);
            }
            $q = $pdo->prepare('REPLACE INTO settings (k,v) VALUES (?,?)');
            $q->execute(['panel_url_path', $base ?: '/']);
            $q->execute(['coexist_mode', 'web-upload']);
            $pdo->commit();
            apex_setup_write($root . '/config/installed.php', ['DB_HOST'=>$host, 'DB_PORT'=>(string)$port, 'DB_NAME'=>$name, 'DB_USER'=>$user, 'DB_PASS'=>$pass, 'APEX_STATE'=>$state, 'APEX_BASE_PATH'=>$base]);
            @unlink($journalPath);
            unset($_SESSION['setup_csrf']);
            $success = true;
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $error = 'Database setup failed. Check the database exists, your connection details and CREATE/ALTER/INDEX privileges in your hosting dashboard. You can retry with the same database. Error code: ' . $e->getCode();
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Installation failed. Check hosting permissions and retry.';
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install ApexNode</title>
<style>body{font:16px system-ui;background:#0b1220;color:#e5edf9;margin:0;padding:24px}main{max-width:640px;margin:auto}form,section{background:#172236;padding:24px;border-radius:12px}label{display:block;margin:16px 0 5px}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #566580;border-radius:6px;background:#0b1220;color:white}button{padding:14px;margin-top:24px;background:#43def0;border:0;border-radius:6px;font-weight:bold}a{color:#43def0}.error{color:#ffb8b8}li{margin:8px 0}</style></head><body><main><h1>Install ApexNode</h1>
<?php if ($success): ?><section><h2>Your panel is ready</h2><p>Your administrator account has been created and installation is locked.</p><p><a href="<?= $escape($base . '/login') ?>">Sign in to your panel</a></p><p>Remove install.php using your hosting file manager. Game-server operations require the separately installed daemon and game runtimes.</p></section>
<?php else: ?><p>Upload the panel files, create an empty MySQL/MariaDB database in your hosting dashboard, and complete this form. No terminal commands are used.</p><ul><?php foreach ($checks as $label=>$ok): ?><li><?= $ok ? '✓' : '✗' ?> <?= $escape($label) ?></li><?php endforeach; ?></ul>
<?php if ($error): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?= $escape($_SESSION['setup_csrf']) ?>"><h2>Database</h2>
<?php foreach (['db_host'=>['Host','localhost'], 'db_port'=>['Port','3306'], 'db_name'=>['Database name',''], 'db_user'=>['Database user','']] as $key=>$field): ?><label for="<?= $key ?>"><?= $field[0] ?></label><input id="<?= $key ?>" name="<?= $key ?>" required value="<?= $escape($_POST[$key] ?? $field[1]) ?>"><?php endforeach; ?>
<label for="db_pass">Database password</label><input id="db_pass" name="db_pass" type="password" autocomplete="new-password">
<h2>Administrator</h2><label for="admin">Username</label><input id="admin" name="admin" required maxlength="64" value="<?= $escape($_POST['admin'] ?? '') ?>"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="128" value="<?= $escape($_POST['email'] ?? '') ?>"><label for="password">Password (at least 12 characters)</label><input id="password" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password"><label for="confirm">Confirm password</label><input id="confirm" name="confirm" type="password" required autocomplete="new-password"><p>The panel will use this directory and detect its URL automatically. Game-server services are configured separately.</p><button <?= in_array(false, $checks, true) ? 'disabled' : '' ?>>Install web panel</button></form><?php endif; ?></main></body></html>
<?php
}
function apex_setup_write(string $path, $value): void {
    $temp = tempnam(dirname($path), 'setup-');
    if ($temp === false) throw new RuntimeException('Cannot write configuration. Check config directory permissions.');
    try {
        if (!chmod($temp, 0600) || file_put_contents($temp, "<?php\nreturn " . var_export($value, true) . ";\n") === false || !rename($temp, $path)) throw new RuntimeException('Cannot save configuration. Check config directory permissions.');
    } finally { if (is_file($temp)) unlink($temp); }
}
