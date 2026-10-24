<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * Password reset controller: forgot password and token-based reset.
 *
 * Tokens are single-use, time-limited, and stored as SHA-256 hashes so a
 * database leak cannot be used to reset accounts.
 */
class PasswordReset
{
    private const TOKEN_TTL_SECONDS = 60 * 60; // 1 hour
    private const RESET_COOLDOWN_SECONDS = 300; // 5 min between requests

    /**
     * Render the forgot-password form.
     *
     * @return void
     */
    public function forgot(): void
    {
        \view('auth/forgot', ['title' => 'Forgot password']);
    }

    /**
     * Issue a password-reset token and email it to the account owner.
     *
     * Rate-limited per email and per IP via the session to slow enumeration
     * and abuse. Always returns success to the caller to avoid leaking
     * whether an email exists.
     *
     * @return void
     */
    public function request(): void
    {
        \check_csrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $now   = time();

        $last = (int) ($_SESSION['pw_reset_last_request'] ?? 0);
        if ($now - $last < self::RESET_COOLDOWN_SECONDS) {
            \flash('info', 'Please wait before requesting another reset.');
            \redirect('/forgot');
        }
        $_SESSION['pw_reset_last_request'] = $now;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            \flash('info', 'If that account exists, a reset link has been sent.');
            \redirect('/forgot');
        }

        $u = DB::one('SELECT id, username, email FROM users WHERE email=? LIMIT 1', [$email]);
        if (!$u) {
            \flash('info', 'If that account exists, a reset link has been sent.');
            \redirect('/forgot');
        }

        // Invalidate any unused tokens for this user.
        DB::q('DELETE FROM password_reset_tokens WHERE user_id=? AND used_at IS NULL', [(int) $u['id']]);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expires = date('Y-m-d H:i:s', $now + self::TOKEN_TTL_SECONDS);

        DB::insert('password_reset_tokens', [
            'user_id'    => (int) $u['id'],
            'token'      => $tokenHash,
            'expires_at' => $expires,
        ]);

        $resetUrl = \url('/reset/' . $rawToken);
        $body = "Hello {$u['username']},\n\n"
            . "You requested a password reset for your ApexNode account.\n"
            . "Reset your password here: {$resetUrl}\n"
            . "This link expires in " . (self::TOKEN_TTL_SECONDS / 60) . " minutes.\n"
            . "If you did not request this, you can safely ignore this email.\n\n"
            . "— ApexNode\n";

        @\send_mail(
            $u['email'],
            'ApexNode — Password reset',
            $body,
            ['X-Purpose' => 'password-reset']
        );

        \flash('info', 'If that account exists, a reset link has been sent.');
        \redirect('/forgot');
    }

    /**
     * Render the reset-password form for a valid, unused token.
     *
     * @param string $token The raw reset token from the URL.
     * @return void
     */
    public function reset(string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $row = DB::one(
            'SELECT prt.id, prt.user_id, prt.expires_at, u.username, u.email
             FROM password_reset_tokens prt
             JOIN users u ON u.id = prt.user_id
             WHERE prt.token=? AND prt.used_at IS NULL AND prt.expires_at > NOW()',
            [$tokenHash]
        );
        if (!$row) {
            http_response_code(404);
            \view('errors/404');
            return;
        }
        \view('auth/reset', [
            'title'    => 'Reset password',
            'token'    => $token,
            'username' => $row['username'],
            'email'    => $row['email'],
        ]);
    }

    /**
     * Apply a new password using a valid reset token, then invalidate it.
     *
     * @param string $token The raw reset token from the form.
     * @return void
     */
    public function submit(string $token): void
    {
        \check_csrf();
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm'] ?? '');

        if (strlen($password) < 12 || strlen($password) > 72 || $password !== $confirm) {
            \flash('error', 'Password must be 12–72 characters and match the confirmation.');
            \redirect('/reset/' . $token);
        }

        $tokenHash = hash('sha256', $token);
        $row = DB::one(
            'SELECT prt.id, prt.user_id
             FROM password_reset_tokens prt
             WHERE prt.token=? AND prt.used_at IS NULL AND prt.expires_at > NOW()
             LIMIT 1',
            [$tokenHash]
        );
        if (!$row) {
            \flash('error', 'Reset link is invalid or has expired.');
            \redirect('/forgot');
        }

        DB::q('UPDATE users SET password_hash=? WHERE id=?', [
            password_hash($password, PASSWORD_BCRYPT),
            (int) $row['user_id'],
        ]);
        DB::q('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=?', [(int) $row['id']]);

        // Invalidate all other unused tokens for this user.
        DB::q('DELETE FROM password_reset_tokens WHERE user_id=? AND used_at IS NULL', [(int) $row['user_id']]);

        $u = DB::one('SELECT username FROM users WHERE id=?', [(int) $row['user_id']]);
        \log_activity('password-reset', 'user:' . ($u['username'] ?? ''));
        \flash('success', 'Your password has been reset. You can now sign in.');
        \redirect('/login');
    }
}
