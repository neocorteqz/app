<?php
// ApexNode Configuration
return [
    'app_name' => 'ApexNode',
    'app_tagline' => 'Tactical Game Server Control Tower',
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'apexnode',
        'user' => getenv('DB_USER') ?: 'apexnode',
        'pass' => getenv('DB_PASS') ?: 'apex_local_dev',
    ],
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('REDIS_PORT') ?: 6379),
    ],
    'discord' => [
        'token' => getenv('DISCORD_BOT_TOKEN') ?: '',
        'guild' => getenv('DISCORD_GUILD_ID') ?: '',
        'channel' => getenv('DISCORD_CHANNEL_ID') ?: '',
    ],
    'session_lifetime' => 60 * 60 * 8,
];
