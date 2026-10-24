<?php

declare(strict_types=1);

namespace App\Controllers;

use DB;

/**
 * Public join controller: unauthenticated server status and connect address.
 *
 * Share tokens are unguessable (48 hex chars). Only server name, status,
 * player count, and connect address are exposed.
 */
class PublicJoin
{
    /**
     * Fetch a server by its public share token.
     *
     * @param string $token The share token.
     * @return array|null The server row, or null when not found.
     */
    private function server(string $token): ?array
    {
        return DB::one(
            'SELECT s.name, s.game, s.status, s.port, s.players_online, s.players_max, n.ip, n.hostname
             FROM servers s JOIN nodes n ON n.id=s.node_id WHERE s.share_token=?',
            [$token]
        );
    }

    /**
     * Build a host:port connect address, wrapping IPv6 in brackets.
     *
     * @param array $server The server row.
     * @return string The connect address.
     */
    private function connectAddress(array $server): string
    {
        $host = trim((string) ($server['ip'] ?: $server['hostname']));
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        return $host . ':' . (int) $server['port'];
    }

    /**
     * Render the public join page.
     *
     * @param string $token The share token.
     * @return void
     */
    public function show(string $token): void
    {
        $server = $this->server($token);
        if (!$server) {
            http_response_code(404);
            \view('errors/404');
            return;
        }
        $server['address'] = $this->connectAddress($server);
        $server['token']   = $token;
        $scheme   = \is_https_request() ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^[A-Za-z0-9.:[\]-]+$/', $host)) {
            $host = 'localhost';
        }
        $server['share_url'] = $scheme . '://' . $host . \url('/join/') . $token;
        \view('servers/join', ['title' => 'Join ' . $server['name'], 'server' => $server]);
    }

    /**
     * Return public server status as JSON.
     *
     * @param string $token The share token.
     * @return void
     */
    public function status(string $token): void
    {
        $server = $this->server($token);
        if (!$server) {
            \json_response(['error' => 'not found'], 404);
        }
        \json_response([
            'name'          => $server['name'],
            'game'          => $server['game'],
            'status'        => $server['status'],
            'address'       => $this->connectAddress($server),
            'players_online' => (int) $server['players_online'],
            'players_max'   => (int) $server['players_max'],
        ]);
    }
}
