<?php

namespace App\Controllers;

use DB;

class DatabaseUsers
{
    public function index()
    {
        \require_role('admin');
        $rows = DB::all('SELECT id, name, username, database_name, host, created_at FROM database_users ORDER BY id ASC');
        $config = require __DIR__ . '/../../config/config.php';
        \view('database-users/index', ['title' => 'Database Users', 'rows' => $rows, 'credential_secret' => \flash('credential_secret'), 'provisioning_configured' => !empty($config['db_provisioner']['user']) && !empty($config['db_provisioner']['pass'])]);
    }

    public function store()
    {
        \check_csrf();
        \require_role('admin');

        $name = trim((string)($_POST['name'] ?? ''));
        $dbName = trim((string)($_POST['database_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($password === '') {
            $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        }
        $host = trim((string)($_POST['host'] ?? 'localhost'));

        if ($name === '' || $dbName === '' || strlen($password) < 8) {
            \flash('error', 'Name, database name, and a password of at least 8 characters are required.');
            \redirect('/database-users');
        }

        $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        if ($safeUser === '' || strlen($safeUser) < 3) {
            \flash('error', 'Database username must contain at least 3 alphanumeric characters.');
            \redirect('/database-users');
        }

        $dbNameSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
        if ($dbNameSafe === '') {
            \flash('error', 'Database name contains invalid characters.');
            \redirect('/database-users');
        }
        $dbNameSafe = 'apexnode_' . preg_replace('/^apexnode_/', '', $dbNameSafe);
        if ($dbNameSafe === 'apexnode_') {
            \flash('error', 'Database name must include a name after the required apexnode_ prefix.');
            \redirect('/database-users');
        }

        $host = preg_replace('/[^A-Za-z0-9_.:%-]/', '', $host) ?: 'localhost';
        $username = strtolower($safeUser . '_' . substr(md5($dbNameSafe . microtime(true)), 0, 6));
        $quotedUser = "'" . str_replace("'", "''", $username) . "'";
        $quotedHost = "'" . str_replace("'", "''", $host) . "'";
        $quotedPass = "'" . str_replace("'", "''", $password) . "'";
        $quotedDb = "`" . str_replace("`", "``", $dbNameSafe) . "`";

        try {
            $dsn = \DB::provisioner();
            $dsn->exec("CREATE DATABASE IF NOT EXISTS {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $dsn->exec("CREATE USER IF NOT EXISTS {$quotedUser}@{$quotedHost} IDENTIFIED BY {$quotedPass}");
            $dsn->exec("GRANT ALL PRIVILEGES ON {$quotedDb}.* TO {$quotedUser}@{$quotedHost}");

            DB::insert('database_users', [
                'name' => $name,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'database_name' => $dbNameSafe,
                'host' => $host,
            ]);

            \log_activity('create-database-user', 'database-user:' . $username, $dbNameSafe);
            \flash('credential_secret', $password);
            \flash('success', 'Database user created for ' . $dbNameSafe . '.');
        } catch (\Throwable $e) {
            \flash('error', 'Unable to create the database user: ' . $e->getMessage());
        }

        \redirect('/database-users');
    }

    public function rotate()
    {
        \check_csrf();
        \require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        $row = DB::one('SELECT id, username, database_name, host FROM database_users WHERE id=?', [$id]);
        if (!$row) {
            \flash('error', 'Database user not found.');
            \redirect('/database-users');
        }
        $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $quotedUser = "'" . str_replace("'", "''", $row['username']) . "'";
        $quotedHost = "'" . str_replace("'", "''", $row['host']) . "'";
        $quotedPass = "'" . str_replace("'", "''", $password) . "'";
        try {
            \DB::provisioner()->exec("ALTER USER {$quotedUser}@{$quotedHost} IDENTIFIED BY {$quotedPass}");
            DB::q('UPDATE database_users SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_BCRYPT), $id]);
            \log_activity('rotate-database-password', 'database-user:' . $row['username'], $row['database_name']);
            \flash('credential_secret', $password);
            \flash('success', 'Database password rotated. Copy the new password now; it will not be shown again.');
        } catch (\Throwable $e) {
            \flash('error', 'Unable to rotate the database password: ' . $e->getMessage());
        }
        \redirect('/database-users');
    }

    public function delete()
    {
        \check_csrf();
        \require_role('admin');

        $id = (int)($_POST['id'] ?? 0);
        $row = DB::one('SELECT * FROM database_users WHERE id=?', [$id]);
        if (!$row) {
            \flash('error', 'Database user not found.');
            \redirect('/database-users');
        }

        try {
            $quotedUser = "'" . str_replace("'", "''", $row['username']) . "'";
            $quotedHost = "'" . str_replace("'", "''", $row['host']) . "'";
            $quotedDb = "`" . str_replace("`", "``", $row['database_name']) . "`";
            if (!str_starts_with($row['database_name'], 'apexnode_')) {
                throw new \RuntimeException('Refusing to drop a database outside the ApexNode namespace.');
            }
            $dsn = \DB::provisioner();
            $dsn->exec("DROP USER IF EXISTS {$quotedUser}@{$quotedHost}");
            $dsn->exec("DROP DATABASE IF EXISTS {$quotedDb}");
            DB::q('DELETE FROM database_users WHERE id=?', [$id]);
            \log_activity('delete-database-user', 'database-user:' . $row['username'], $row['database_name']);
            \flash('success', 'Database user removed.');
        } catch (\Throwable $e) {
            \flash('error', 'Unable to remove database user: ' . $e->getMessage());
        }

        \redirect('/database-users');
    }
}
