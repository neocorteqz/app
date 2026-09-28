<?php
namespace App\Controllers;
use DB;

class DatabaseUsers {
    public function index() {
        \require_role('admin');
        $rows = DB::all('SELECT * FROM database_users ORDER BY id ASC');
        \view('database-users/index', ['title' => 'Database Users', 'rows' => $rows]);
    }

    public function store() {
        \check_csrf();
        \require_role('admin');

        $name = trim((string)($_POST['name'] ?? ''));
        $dbName = trim((string)($_POST['database_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
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

        $host = preg_replace('/[^A-Za-z0-9_.:%-]/', '', $host) ?: 'localhost';
        $username = strtolower($safeUser . '_' . substr(md5($dbNameSafe . microtime(true)), 0, 6));
        $quotedUser = "'" . str_replace("'", "''", $username) . "'";
        $quotedHost = "'" . str_replace("'", "''", $host) . "'";
        $quotedPass = "'" . str_replace("'", "''", $password) . "'";
        $quotedDb = "`" . str_replace("`", "``", $dbNameSafe) . "`";

        try {
            $dsn = \DB::conn();
            $dsn->exec("CREATE DATABASE IF NOT EXISTS {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $dsn->exec("CREATE USER IF NOT EXISTS {$quotedUser}@{$quotedHost} IDENTIFIED BY {$quotedPass}");
            $dsn->exec("GRANT ALL PRIVILEGES ON {$quotedDb}.* TO {$quotedUser}@{$quotedHost}");
            $dsn->exec('FLUSH PRIVILEGES');

            DB::insert('database_users', [
                'name' => $name,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'database_name' => $dbNameSafe,
                'host' => $host,
            ]);

            \flash('success', 'Database user created for ' . $dbNameSafe . '.');
        } catch (\Throwable $e) {
            \flash('error', 'Unable to create the database user: ' . $e->getMessage());
        }

        \redirect('/database-users');
    }

    public function delete() {
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
            $dsn = \DB::conn();
            $dsn->exec("DROP USER IF EXISTS {$quotedUser}@{$quotedHost}");
            $dsn->exec("DROP DATABASE IF EXISTS {$quotedDb}");
            $dsn->exec('FLUSH PRIVILEGES');
            DB::q('DELETE FROM database_users WHERE id=?', [$id]);
            \flash('success', 'Database user removed.');
        } catch (\Throwable $e) {
            \flash('error', 'Unable to remove database user: ' . $e->getMessage());
        }

        \redirect('/database-users');
    }
}
