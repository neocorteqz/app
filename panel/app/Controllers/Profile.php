<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * Profile controller: change password and change email with verification.
 *
 * Email changes require confirmation via a one-time token sent to the new
 * address; the old email remains in effect until the user clicks the link.
 */
class Profile
{
    private const EMAIL_TOKEN_TTL_SECONDS = 60 * 60 * 24; // 24 hours

    /**
     * Render the profile editor (password + email).
     *
     * @return void
     */
    public function index(): void
    {
        $u = \require_login();
        \view('profile/index', [
            'title' => 'Profile',
            'user'  => $u,
        ]);
    }

    /**
     * Change the signed-in user's password after verifying the current one.
     *
     * @return void
     */
    public function changePassword(): void
    {
        \check_csrf();
        $u = \require_login();

        $current = (string) ($_POST['current_password'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        $row = DB::one('SELECT password_hash FROM users WHERE id=?', [(int) $u['id']]);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            \flash('error', 'Current password is incorrect.');
            \redirect('/profile');
        }

        if (strlen($password) < 12 || strlen($password) > 72 || $password !== $confirm) {
            \flash('error', 'New password must be 12–72 characters and match the confirmation.');
            \redirect('/profile');
        }
        if (password_verify($password, $row['password_hash'])) {
            \flash('error', 'New password must differ from the current password.');
            \redirect('/profile');
        }

        DB::q('UPDATE users SET password_hash=? WHERE id=?', [
            password_hash($password, PASSWORD_BCRYPT),
            (int) $u['id'],
        ]);
        \log_activity('change-password', 'user:' . $u['username']);
        \flash('success', 'Password changed.');
        \redirect('/profile');
    }

    /**
     * Request an email change: validate, generate token, email the new address.
     *
     * @return void
     */
    public function changeEmailRequest(): void
    {
        \check_csrf();
        $u = \require_login();

        $newEmail = trim((string) ($_POST['new_email'] ?? ''));
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 128) {
            \flash('error', 'Enter a valid email address.');
            \redirect('/profile');
        }
        if (strtolower($newEmail) === strtolower($u['email'])) {
            \flash('error', 'That is already your email address.');
            \redirect('/profile');
        }

        $existing = DB::one('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1', [$newEmail, (int) $u['id']]);
        if ($existing) {
            \flash('error', 'That email address is already in use.');
            \redirect('/profile');
        }

        // Invalidate any unused email-change tokens for this user.
        DB::q('DELETE FROM email_change_tokens WHERE user_id=? AND used_at IS NULL', [(int) $u['id']]);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expires = date('Y-m-d H:i:s', time() + self::EMAIL_TOKEN_TTL_SECONDS);

        DB::insert('email_change_tokens', [
            'user_id'    => (int) $u['id'],
            'new_email'  => $newEmail,
            'token'      => $tokenHash,
            'expires_at' => $expires,
        ]);

        $confirmUrl = \url('/profile/email/confirm/' . $rawToken);
        $body = "Hello {$u['username']},\n\n"
            . "You requested to change your ApexNode account email to: {$newEmail}\n"
            . "Confirm the change here: {$confirmUrl}\n"
            . "This link expires in " . (self::EMAIL_TOKEN_TTL_SECONDS / 3600) . " hours.\n"
            . "If you did not request this, you can safely ignore this email.\n\n"
            . "— ApexNode\n";

        @\send_mail($newEmail, 'ApexNode — Confirm email change', $body, ['X-Purpose' => 'email-change']);

        \flash('info', 'A confirmation link has been sent to ' . \h($newEmail) . '. Your email will change once you confirm.');
        \redirect('/profile');
    }

    /**
     * Confirm an email change using a valid token.
     *
     * @param string $token The raw confirmation token.
     * @return void
     */
    public function confirmEmailChange(string $token): void
    {
        $u = \require_login();
        $tokenHash = hash('sha256', $token);
        $row = DB::one(
            'SELECT id, new_email FROM email_change_tokens
             WHERE user_id=? AND token=? AND used_at IS NULL AND expires_at > NOW()',
            [(int) $u['id'], $tokenHash]
        );
        if (!$row) {
            \flash('error', 'Confirmation link is invalid or has expired.');
            \redirect('/profile');
        }

        // Make sure the new email is still unused.
        $existing = DB::one('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1', [$row['new_email'], (int) $u['id']]);
        if ($existing) {
            DB::q('UPDATE email_change_tokens SET used_at=NOW() WHERE id=?', [(int) $row['id']]);
            \flash('error', 'That email address is now in use by another account.');
            \redirect('/profile');
        }

        DB::q('UPDATE users SET email=?, email_verified=0 WHERE id=?', [$row['new_email'], (int) $u['id']]);
        DB::q('UPDATE email_change_tokens SET used_at=NOW() WHERE id=?', [(int) $row['id']]);
        DB::q('DELETE FROM email_change_tokens WHERE user_id=? AND used_at IS NULL', [(int) $u['id']]);

        \log_activity('change-email', 'user:' . $u['username'], $row['new_email']);
        \flash('success', 'Your email address has been changed to ' . \h($row['new_email']) . '.');
        \redirect('/profile');
    }
}
