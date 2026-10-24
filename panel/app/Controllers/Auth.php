<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * Authentication controller: login, registration, logout.
 *
 * Passwords are verified with password_verify() and hashed with PASSWORD_BCRYPT.
 * CSRF is validated on every state-changing request. Failed logins are
 * throttled per session to slow brute-force attempts.
 */
class Auth
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 300;

    /**
     * Render the login form.
     *
     * @return void
     */
    public function showLogin(): void
    {
        \view('auth/login', ['title' => 'Sign in']);
    }

    /**
     * Process a login submission.
     *
     * @return void
     */
    public function login(): void
    {
        \check_csrf();
        $this->enforceLoginThrottle();

        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($email === '' || $password === '') {
            $this->recordLoginFailure();
            \flash('error', 'Invalid credentials.');
            \redirect('/login');
        }

        $u = DB::one('SELECT * FROM users WHERE email=? OR username=?', [$email, $email]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            $this->recordLoginFailure();
            \flash('error', 'Invalid credentials.');
            \redirect('/login');
        }

        $this->clearLoginThrottle();
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        $_SESSION['uid']  = (int) $u['id'];
        \log_activity('login', 'user:' . $u['username']);
        \redirect('/dashboard');
    }

    /**
     * Render the registration form (first-run only, or admin-invited).
     *
     * @return void
     */
    public function showRegister(): void
    {
        $count = (int) (DB::one('SELECT COUNT(*) c FROM users')['c'] ?? 0);
        if ($count > 0 && (!\auth_user() || \auth_user()['role'] !== 'admin')) {
            \flash('error', 'Registration disabled. Ask an admin to create your account.');
            \redirect('/login');
        }
        \view('auth/register', ['title' => 'Register']);
    }

    /**
     * Process a registration submission.
     *
     * The first registered user becomes administrator; later users are viewers
     * unless created by an administrator.
     *
     * @return void
     */
    public function register(): void
    {
        \check_csrf();
        $count = (int) (DB::one('SELECT COUNT(*) c FROM users')['c'] ?? 0);
        $isFirst = $count === 0;
        if (!$isFirst && (!\auth_user() || \auth_user()['role'] !== 'admin')) {
            \redirect('/login');
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $pass     = (string) ($_POST['password'] ?? '');
        if (strlen($username) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) {
            \flash('error', 'Please fill valid username, email, and password (at least 6 chars).');
            \redirect('/register');
        }

        try {
            $id = DB::insert('users', [
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
                'role'          => $isFirst ? 'admin' : 'viewer',
            ]);
        } catch (\Throwable $e) {
            \flash('error', 'That username or email is taken.');
            \redirect('/register');
        }

        if ($isFirst) {
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            $_SESSION['uid']  = (int) $id;
            \log_activity('bootstrap-admin', 'user:' . $username);
            \flash('success', 'Welcome — you are now the first admin.');
            \redirect('/dashboard');
        }
        \flash('success', 'User created.');
        \redirect('/users');
    }

    /**
     * Process a logout submission and destroy the session.
     *
     * @return void
     */
    public function logout(): void
    {
        \check_csrf();
        \log_activity('logout');
        session_destroy();
        \redirect('/login');
    }

    /**
     * Refuse logins while the session is locked out after repeated failures.
     *
     * @return void
     */
    private function enforceLoginThrottle(): void
    {
        $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
        if ($lockedUntil > 0 && time() < $lockedUntil) {
            \flash('error', 'Too many failed login attempts. Try again later.');
            \redirect('/login');
        }
    }

    /**
     * Record a failed login and lock the session after the threshold.
     *
     * @return void
     */
    private function recordLoginFailure(): void
    {
        $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $_SESSION['login_locked_until'] = time() + self::LOCKOUT_SECONDS;
        }
    }

    /**
     * Clear the login failure tracker on successful authentication.
     *
     * @return void
     */
    private function clearLoginThrottle(): void
    {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_locked_until'] = 0;
    }
}
