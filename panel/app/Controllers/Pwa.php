<?php
namespace App\Controllers;

class Pwa {
    public function manifest() {
        header('Content-Type: application/manifest+json');
        echo json_encode([
            'name' => 'ApexNode Panel',
            'short_name' => 'ApexNode',
            'start_url' => \url('/dashboard'),
            'display' => 'standalone',
            'background_color' => '#090A0F',
            'theme_color' => '#00F0FF',
            'description' => 'Tactical game server control tower.',
            'icons' => [
                ['src' => \url('/assets/icon-192.svg'), 'sizes' => '192x192', 'type' => 'image/svg+xml'],
                ['src' => \url('/assets/icon-512.svg'), 'sizes' => '512x512', 'type' => 'image/svg+xml'],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
    public function serviceWorker() {
        header('Content-Type: application/javascript');
        $assets = json_encode(array_map('url', ['/assets/app.css', '/assets/app.js', '/manifest.webmanifest']));
        $version = substr(hash('sha256', \apex_base_path() . hash_file('sha256', __DIR__ . '/../../public/assets/app.css') . hash_file('sha256', __DIR__ . '/../../public/assets/app.js')), 0, 16);
        echo <<<JS
        const CACHE = 'apex-$version';
        const ASSETS = $assets;
        self.addEventListener('install', (e) => {
          e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)));
          self.skipWaiting();
        });
        self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
        self.addEventListener('fetch', (e) => {
          const url = new URL(e.request.url);
          if (ASSETS.some((a) => url.pathname === a)) {
            e.respondWith(caches.match(e.request).then((r) => r || fetch(e.request)));
          }
        });
        JS;
    }
}
