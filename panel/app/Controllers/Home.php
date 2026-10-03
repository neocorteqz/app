<?php

namespace App\Controllers;

class Home
{
    public function index()
    {
        if (\auth_user()) {
            \redirect('/dashboard');
        }
        \redirect('/login');
    }
}
