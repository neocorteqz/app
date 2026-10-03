<?php
namespace App\Controllers;

use DB;

class Auth {
    public function showLogin() {
        \view('auth/login', ['title' => 'Sign in']);
    }
    public function login() {
        \check_csrf();
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $u = DB::one('SELECT * FROM users WHERE email=? OR username=?', [$email, $email]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            \flash('error', 'Invalid credentials.');
            \redirect('/login');
        }
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        $_SESSION['uid'] = $u['id'];
        \log_activity('login', 'user:'.$u['username']);
        \redirect('/dashboard');
    }
    public function showRegister() {
        // Only allow if no users exist (first-run) or if admin invites
        $count = (int)DB::one('SELECT COUNT(*) c FROM users')['c'];
        if ($count > 0 && (!\auth_user() || \auth_user()['role'] !== 'admin')) {
            \flash('error', 'Registration disabled. Ask an admin to create your account.');
            \redirect('/login');
        }
        \view('auth/register', ['title' => 'Register']);
    }
    public function register() {
        \check_csrf();
        $count = (int)DB::one('SELECT COUNT(*) c FROM users')['c'];
        $isFirst = $count === 0;
        if (!$isFirst && (!\auth_user() || \auth_user()['role'] !== 'admin')) {
            \redirect('/login');
        }
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $pass     = $_POST['password'] ?? '';
        if (strlen($username) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
            \flash('error', 'Please fill valid username, email, and password ≥ 6 chars.');
            \redirect('/register');
        }
        try {
            $id = DB::insert('users', [
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
                'role' => $isFirst ? 'admin' : 'viewer',
            ]);
        } catch (\Throwable $e) {
            \flash('error', 'That username or email is taken.');
            \redirect('/register');
        }
        if ($isFirst) {
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            $_SESSION['uid'] = $id;
            \log_activity('bootstrap-admin', 'user:'.$username);
            \flash('success', 'Welcome — you are now the first admin.');
            \redirect('/dashboard');
        }
        \flash('success', 'User created.');
        \redirect('/users');
    }
    public function logout() {
        \check_csrf();
        \log_activity('logout');
        session_destroy();
        \redirect('/login');
    }
}
