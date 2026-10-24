<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * Database user provisioning controller.
 *
 * Creates, rotates, and drops MySQL/MariaDB users and databases inside the
 * apexnode_ namespace using the provisioner connection. Requires
 * DB_PROVISIONER_USER / DB_PROVISIONER_PASS.
 */
class DatabaseUsers
{
    private const DB_NAMESPACE = 'apexnode_';

    /**
     * List provisioned database users.
     *
     * @return void
     */
    public function index(): void
    {
        \require_role('admin');
        $rows = DB::all('SELECT id, name, username, database_name, host, created_at FROM database_users ORDER BY id ASC');
        $config = require __DIR__ . '/../../config/config.php';
        $provisioningConfigured = !empty($config['db_provisioner']['user']) && !empty($config['db_provisioner']['pass']);
        \view('database-users/index', [
            'title'                 => 'Database Users',
            'rows'                  => $rows,
            'credential_secret'     => \flash('credential_secret'),
            'provisioning_configured' => $provisioningConfigured,
        ]);
    }

    /**
     * Create a database user and database in the apexnode_ namespace.
     *
     * @return void
     */
    public function store(): void
    {
        \check_csrf();
        \require_role('admin');

        $name   = trim((string) ($_POST['name'] ?? ''));
        $dbName = trim((string) ($_POST['database_name'] ?? ''));
        $pass   = (string) ($_POST['password'] ?? '');
        if ($pass === '') {
            $pass = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        }
        $host = trim((string) ($_POST['host'] ?? 'localhost'));

        if ($name === '' || $dbName === '' || strlen($pass) < 8) {
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
        $dbNameSafe = self::DB_NAMESPACE . preg_replace('/^' . preg_quote(self::DB_NAMESPACE, '/') . '/', '', $dbNameSafe);
        if ($dbNameSafe === self::DB_NAMESPACE) {
            \flash('error', 'Database name must include a name after the required apexnode_ prefix.');
            \redirect('/database-users');
        }

        $host = preg_replace('/[^A-Za-z0-9_.:%-]/', '', $host) ?: 'localhost';
        $username = strtolower($safeUser . '_' . substr(md5($dbNameSafe . microtime(true)), 0, 6));

        $quotedUser = "'" . str_replace("'", "''", $username) . "'";
        $quotedHost = "'" . str_replace("'", "''", $host) . "'";
        $quotedPass = "'" . str_replace("'", "''", $pass) . "'";
        $quotedDb   = "`" . str_replace("`", "``", $dbNameSafe) . "`";

        try {
            $dsn = \DB::provisioner();
            $dsn->exec("CREATE DATABASE IF NOT EXISTS {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $dsn->exec("CREATE USER IF NOT EXISTS {$quotedUser}@{$quotedHost} IDENTIFIED BY {$quotedPass}");
            $dsn->exec("GRANT ALL PRIVILEGES ON {$quotedDb}.* TO {$quotedUser}@{$quotedHost}");

            DB::insert('database_users', [
                'name'           => $name,
                'username'       => $username,
                'password_hash'  => password_hash($pass, PASSWORD_BCRYPT),
                'database_name'  => $dbNameSafe,
                'host'           => $host,
            ]);

            \log_activity('create-database-user', 'database-user:' . $username, $dbNameSafe);
            \flash('credential_secret', $pass);
            \flash('success', 'Database user created for ' . $dbNameSafe . '.');
        } catch (\Throwable $e) {
            \flash('error', 'Unable to create the database user.');
        }
        \redirect('/database-users');
    }

    /**
     * Rotate a database user's password on the server and in the panel record.
     *
     * @return void
     */
    public function rotate(): void
    {
        \check_csrf();
        \require_role('admin');
        $id = (int) ($_POST['id'] ?? 0);
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
            \flash('error', 'Unable to rotate the database password.');
        }
        \redirect('/database-users');
    }

    /**
     * Delete a database user and drop its database (apexnode_ namespace only).
     *
     * @return void
     */
    public function delete(): void
    {
        \check_csrf();
        \require_role('admin');

        $id = (int) ($_POST['id'] ?? 0);
        $row = DB::one('SELECT * FROM database_users WHERE id=?', [$id]);
        if (!$row) {
            \flash('error', 'Database user not found.');
            \redirect('/database-users');
        }

        try {
            if (!str_starts_with($row['database_name'], self::DB_NAMESPACE)) {
                throw new \RuntimeException('Refusing to drop a database outside the ApexNode namespace.');
            }
            $quotedUser = "'" . str_replace("'", "''", $row['username']) . "'";
            $quotedHost = "'" . str_replace("'", "''", $row['host']) . "'";
            $quotedDb   = "`" . str_replace("`", "``", $row['database_name']) . "`";
            $dsn = \DB::provisioner();
            $dsn->exec("DROP USER IF EXISTS {$quotedUser}@{$quotedHost}");
            $dsn->exec("DROP DATABASE IF EXISTS {$quotedDb}");
            DB::q('DELETE FROM database_users WHERE id=?', [$id]);
            \log_activity('delete-database-user', 'database-user:' . $row['username'], $row['database_name']);
            \flash('success', 'Database user removed.');
        } catch (\Throwable $e) {
            \flash('error', 'Unable to remove database user.');
        }
        \redirect('/database-users');
    }
}
