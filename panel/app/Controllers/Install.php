<?php

namespace App\Controllers;

class Install
{
    public function index()
    {
        \require_login();
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        \view('install/index', ['title' => 'Install Script','host' => $host]);
    }
    public function panelScript()
    {
        header('Content-Type: text/x-shellscript');
        readfile(__DIR__ . '/../../install/install.sh');
    }
    public function daemonScript()
    {
        header('Content-Type: text/x-shellscript');
        readfile(__DIR__ . '/../../install/install-daemon.sh');
    }
}
