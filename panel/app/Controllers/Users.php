<?php

namespace App\Controllers;

use DB;

class Users
{
    public function index()
    {
        \require_role('admin');
        $users = DB::all('SELECT id, username, email, role, created_at FROM users ORDER BY id ASC');
        \view('users/index', ['title' => 'Users','users' => $users]);
    }
    public function store()
    {
        \check_csrf();
        \require_role('admin');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'viewer';
        $pass = $_POST['password'] ?? '';
        if (strlen($username) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
            \flash('error', 'Invalid input.');
            \redirect('/users');
        }
        if (!in_array($role, ['admin','operator','viewer'])) {
            $role = 'viewer';
        }
        try {
            DB::insert('users', [
                'username' => $username,'email' => $email,'role' => $role,
                'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
            ]);
            \flash('success', 'User added.');
        } catch (\Throwable $e) {
            \flash('error', 'Username or email taken.');
        }
        \redirect('/users');
    }
    public function delete()
    {
        \check_csrf();
        \require_role('admin');
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['uid']) {
            \flash('error', 'Cannot delete yourself.');
            \redirect('/users');
        }
        DB::q('DELETE FROM users WHERE id=?', [$id]);
        \flash('success', 'User removed.');
        \redirect('/users');
    }
}
