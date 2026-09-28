<?php
// ApexNode Configuration
$dotenv = is_file(__DIR__ . '/.env') ? (parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW) ?: []) : [];
$setting = static fn(string $key, string $default = ''): string => (string)(getenv($key) ?: ($dotenv[$key] ?? $default));
return [
    'app_name' => 'ApexNode',
    'app_tagline' => 'Tactical Game Server Control Tower',
    'db' => [
        'host' => $setting('DB_HOST', '127.0.0.1'),
        'port' => (int)$setting('DB_PORT', '3306'),
        'name' => $setting('DB_NAME', 'apexnode'),
        'user' => $setting('DB_USER', 'apexnode'),
        'pass' => $setting('DB_PASS', 'apex_local_dev'),
    ],
    'db_provisioner' => [
        'user' => $setting('DB_PROVISIONER_USER'),
        'pass' => $setting('DB_PROVISIONER_PASS'),
    ],
    'state_root' => rtrim($setting('APEX_STATE', '/var/lib/apexnode'), '/'),
    'redis' => [
        'host' => $setting('REDIS_HOST', '127.0.0.1'),
        'port' => (int)$setting('REDIS_PORT', '6379'),
    ],
    'discord' => [
        'token' => $setting('DISCORD_BOT_TOKEN'),
        'guild' => $setting('DISCORD_GUILD_ID'),
        'channel' => $setting('DISCORD_CHANNEL_ID'),
    ],
    'session_lifetime' => 60 * 60 * 8,
];
