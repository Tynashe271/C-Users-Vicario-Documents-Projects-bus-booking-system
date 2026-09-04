<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third-party integrations
    |--------------------------------------------------------------------------
    |
    | Every entry follows the same shape already used for payment providers
    | (config/payments.php) and notification channels (config/notification_channels.php):
    | a configured `url` (+ optional `token`) the corresponding service calls out to, and a safe
    | no-op when it's blank — these only ever activate once an operator supplies real credentials.
    */

    'error_monitoring' => [
        'url' => env('ERROR_MONITORING_URL'),
        'token' => env('ERROR_MONITORING_TOKEN'),
    ],

    'accounting' => [
        'url' => env('ACCOUNTING_WEBHOOK_URL'),
        'token' => env('ACCOUNTING_WEBHOOK_TOKEN'),
    ],

    // Distance/route calculation between two coordinates. Falls back to a haversine
    // great-circle calculation (see MappingService) when unconfigured — always works, no API key
    // required — real mapping providers are usually more accurate for actual road distance.
    'mapping' => [
        'url' => env('MAPPING_API_URL'),
        'token' => env('MAPPING_API_TOKEN'),
    ],
];
