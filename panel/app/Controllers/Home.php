<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Home controller: redirects to dashboard or login based on session.
 */
class Home
{
    /**
     * Redirect authenticated users to the dashboard, others to login.
     *
     * @return void
     */
    public function index(): void
    {
        if (\auth_user()) {
            \redirect('/dashboard');
        }
        \redirect('/login');
    }
}
