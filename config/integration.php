<?php

return [
    'sdg_oauth' => [
        'client_id' => env('SDG_OAUTH_CLIENT_ID'),
        'client_secret' => env('SDG_OAUTH_CLIENT_SECRET'),
    ],

    'property_changes' => [
        'retention_days' => (int) env('PROPERTY_CHANGES_RETENTION_DAYS', 90),
        'cursor_ttl_seconds' => (int) env('PROPERTY_CHANGES_CURSOR_TTL', 86400),
        'publisher_lock_timeout_seconds' => (int) env('PROPERTY_CHANGES_PUBLISHER_LOCK_TIMEOUT', 300),
    ],
];
