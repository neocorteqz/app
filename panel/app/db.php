<?php

declare(strict_types=1);

/**
 * Database bootstrap using PDO.
 *
 * Provides a shared, lazily-initialised PDO connection plus query helpers.
 * All queries use prepared statements; identifiers are never interpolated from
 * caller input except for hard-coded table/column names.
 *
 * @package App
 */
namespace App;

use PDO;
use PDOStatement;
use RuntimeException;

class DB
{
    private static ?PDO $pdo = null;
    private static ?PDO $provisioner = null;

    /**
     * Inject an existing PDO connection (used by the web installer and tests).
     *
     * @param PDO $pdo An open PDO connection.
     * @return void
     */
    public static function setConnection(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Return the application PDO connection, initialising it on first use.
     *
     * @return PDO The application database connection.
     * @throws RuntimeException If configuration is missing.
     */
    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $c = require __DIR__ . '/../config/config.php';
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $c['db']['host'],
                $c['db']['port'],
                $c['db']['name']
            );
            self::$pdo = new PDO(
                $dsn,
                $c['db']['user'],
                $c['db']['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return self::$pdo;
    }

    /**
     * Return the database provisioner connection (create/drop users and databases).
     *
     * @return PDO The provisioner PDO connection.
     * @throws RuntimeException If provisioning credentials are not configured.
     */
    public static function provisioner(): PDO
    {
        if (self::$provisioner === null) {
            $c = require __DIR__ . '/../config/config.php';
            if (empty($c['db_provisioner']['user']) || empty($c['db_provisioner']['pass'])) {
                throw new RuntimeException('Database provisioning is not configured. Set DB_PROVISIONER_USER and DB_PROVISIONER_PASS.');
            }
            $dsn = sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                $c['db']['host'],
                $c['db']['port']
            );
            self::$provisioner = new PDO(
                $dsn,
                $c['db_provisioner']['user'],
                $c['db_provisioner']['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return self::$provisioner;
    }

    /**
     * Prepare and execute a parameterised query.
     *
     * @param string $sql   SQL statement with ? placeholders.
     * @param array  $args  Bound parameters.
     * @return PDOStatement The executed statement.
     */
    public static function q(string $sql, array $args = []): PDOStatement
    {
        $s = self::conn()->prepare($sql);
        $s->execute($args);
        return $s;
    }

    /**
     * Fetch a single row as an associative array.
     *
     * @param string $sql  SQL statement with ? placeholders.
     * @param array  $args Bound parameters.
     * @return array|null The row, or null when no row matches.
     */
    public static function one(string $sql, array $args = []): ?array
    {
        $r = self::q($sql, $args)->fetch();
        return $r ?: null;
    }

    /**
     * Fetch all rows as an array of associative arrays.
     *
     * @param string $sql  SQL statement with ? placeholders.
     * @param array  $args Bound parameters.
     * @return array<int,array<string,mixed>> The matching rows.
     */
    public static function all(string $sql, array $args = []): array
    {
        return self::q($sql, $args)->fetchAll();
    }

    /**
     * Insert a row and return its new auto-increment id.
     *
     * @param string $table Table name (hard-coded by callers).
     * @param array  $data  Column => value pairs.
     * @return int The new row's last insert id.
     */
    public static function insert(string $table, array $data): int
    {
        $cols = implode(',', array_keys($data));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        self::q(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $cols, $ph), array_values($data));
        return (int) self::conn()->lastInsertId();
    }
}

// Preserve the shared DB facade used by panel helpers and standalone seed scripts.
class_alias(DB::class, 'DB');
