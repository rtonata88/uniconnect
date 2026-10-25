<?php

return [

    /*
     * Identity of this process in the service registry. INSTANCE_SERVICE and
     * INSTANCE_PORT are exported by scripts/run-*.sh before the process starts.
     */
    'instance' => [
        'service' => env('INSTANCE_SERVICE', 'api'),
        'host' => env('APP_ADVERTISE_HOST', '127.0.0.1'),
        'port' => env('INSTANCE_PORT'),
        'hostname' => gethostname() ?: 'unknown',
        'version' => env('APP_VERSION', '1.0.0'),
    ],

    'registry' => [
        'connection' => 'registry',
        'heartbeat_seconds' => 5,
        'ttl_seconds' => 15,
        'sync_seconds' => 2,
        // services that get a Traefik load balancer in Redis KV (health path per service)
        'routed' => [
            'api' => '/api/health',
            'reverb' => '/up',
        ],
        'health_interval' => '5s',
        'health_timeout' => '3s',
    ],

    // Web Push endpoints the server may call (SSRF guard): browser vendors' push services only
    'push' => [
        'allowed_hosts' => array_filter(explode(',', (string) env('PUSH_ALLOWED_HOSTS',
            'fcm.googleapis.com,*.push.services.mozilla.com,updates.push.services.mozilla.com,*.notify.windows.com,web.push.apple.com,*.push.apple.com'))),
    ],

    'uploads' => [
        'max_kb' => 20 * 1024,
        // extension => allowed MIME types (SEC-5)
        'allowed' => [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'text/plain'],
            'zip' => ['application/zip'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'heic' => ['image/heic', 'image/heif'],
        ],
        'signed_url_minutes' => 5,
    ],

];
