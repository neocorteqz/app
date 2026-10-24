<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Install controller: download links for the host installer and daemon script.
 *
 * Requires login; serves the bundled shell scripts with the correct content type.
 */
class Install
{
    /**
     * Render the install script landing page.
     *
     * @return void
     */
    public function index(): void
    {
        \require_login();
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        \view('install/index', ['title' => 'Install Script', 'host' => $host]);
    }

    /**
     * Serve the panel host installer script.
     *
     * @return void
     */
    public function panelScript(): void
    {
        header('Content-Type: text/x-shellscript');
        readfile(__DIR__ . '/../../install/install.sh');
    }

    /**
     * Serve the node daemon installer script.
     *
     * @return void
     */
    public function daemonScript(): void
    {
        header('Content-Type: text/x-shellscript');
        readfile(__DIR__ . '/../../install/install-daemon.sh');
    }
}
