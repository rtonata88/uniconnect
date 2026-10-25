<?php

/*
| SEC-10: the API is used by the web app on the same origin (through the gateway), so
| cross-origin access is limited to that origin (plus CORS_ALLOWED_ORIGINS, e.g. a LAN URL).
*/
$origins = array_values(array_filter(array_map('trim', array_merge(
    [rtrim((string) env('APP_URL', 'https://localhost'), '/')],
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
))));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Request-Id', 'X-Socket-ID', 'X-Requested-With'],
    'exposed_headers' => ['X-Served-By', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => false,
];
