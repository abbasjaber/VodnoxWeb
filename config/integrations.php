<?php

/*
|--------------------------------------------------------------------------
| External Integrations Configuration
|--------------------------------------------------------------------------
|
| All credentials, endpoints, and options for third-party integrations
| (Asterisk/AMI, payment gateways, SMS providers, etc.) live here and are
| read from the .env file. Controllers and services MUST read from this
| config — never call env() directly.
|
| Each integration entry should define at minimum: base_url, timeout,
| connect_timeout, and any auth credentials. The example below shows the
| expected shape; real integrations are added in later phases.
|
*/

return [

    /*
    | Default HTTP client options shared by all integrations.
    */
    'defaults' => [
        'timeout' => (int) env('INTEGRATION_HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('INTEGRATION_CONNECT_TIMEOUT', 5),
        'retry' => [
            'times' => (int) env('INTEGRATION_RETRY_TIMES', 0),
            'sleep' => (int) env('INTEGRATION_RETRY_SLEEP', 100),
        ],
    ],

    /*
    | Asterisk / AMI integration (deferred).
    | Reserved for the future telephony layer. No connection is made in
    | this foundational phase.
    */
    'asterisk' => [
        'enabled' => (bool) env('ASTERISK_ENABLED', false),
        'host' => env('ASTERISK_HOST', '127.0.0.1'),
        'port' => (int) env('ASTERISK_PORT', 5038),
        'username' => env('ASTERISK_USERNAME'),
        'secret' => env('ASTERISK_SECRET'),
    ],

    /*
    | Logging channel used by all integrations.
    */
    'log_channel' => env('INTEGRATION_LOG_CHANNEL', 'integrations'),

];
