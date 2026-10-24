<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * User management controller: list, create, and remove panel users.
 *
 * Admin-only. The signed-in user cannot delete their own account.
 */
class Users
{
    private const VALID_ROLES = ['admin', 'operator', 'viewer'];

    /**
     * List all panel users.
     *
     * @return void
     */
    public function index(): void
    {
        \require_role('admin');
        $users = DB::all('SELECT id, username, email, role, created_at FROM users ORDER BY id ASC');
        \view('users/index', ['title' => 'Users', 'users' => $users]);
    }

    /**
     * Create a new panel user.
     *
     * @return void
     */
    public function store(): void
    {
        \check_csrf();
        \require_role('admin');
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $role     = (string) ($_POST['role'] ?? 'viewer');
        $pass     = (string) ($_POST['password'] ?? '');
        if (strlen($username) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
            \flash('error', 'Invalid input.');
            \redirect('/users');
        }
        if (!in_array($role, self::VALID_ROLES, true)) {
            $role = 'viewer';
        }
        try {
            DB::insert('users', [
                'username'      => $username,
                'email'         => $email,
                'role'          => $role,
                'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
            ]);
            \flash('success', 'User added.');
        } catch (\Throwable $e) {
            \flash('error', 'Username or email taken.');
        }
        \redirect('/users');
    }

    /**
     * Delete a panel user (not oneself).
     *
     * @return void
     */
    public function delete(): void
    {
        \check_csrf();
        \require_role('admin');
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $_SESSION['uid']) {
            \flash('error', 'Cannot delete yourself.');
            \redirect('/users');
        }
        DB::q('DELETE FROM users WHERE id=?', [$id]);
        \flash('success', 'User removed.');
        \redirect('/users');
    }
}
