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
    | Telephony gateway (provider-agnostic contract).
    |
    | Two secrets are involved and must not be the same value:
    |  - signing_secret authenticates VODNOX -> provisioning agent requests
    |    (HMAC over timestamp.nonce.body).
    |  - webhook_secret authenticates provisioning agent / Asterisk -> VODNOX
    |    call events (HMAC-SHA256 over the raw body).
    |
    | Values here are read from the .env file and never from a request.
    */
    'telephony' => [
        'enabled' => (bool) env('TELEPHONY_ENABLED', false),

        // Base URL of the provisioning agent (see deploy/telephony).
        'provisioning_url' => env('TELEPHONY_PROVISIONING_URL'),
        'token' => env('TELEPHONY_PROVISIONING_TOKEN'),
        'signing_secret' => env('TELEPHONY_PROVISIONING_SIGNING_SECRET'),

        'webhook_secret' => env('TELEPHONY_WEBHOOK_SECRET'),

        /*
        | Values handed to the browser so SIP.js can register. None of these
        | are secrets: the SIP password is delivered separately, per user, by an
        | authenticated no-store endpoint.
        */
        'sip_domain' => env('TELEPHONY_SIP_DOMAIN', 'pbx.vodnox.com'),
        'sip_wss_url' => env('TELEPHONY_SIP_WSS_URL', 'wss://pbx.vodnox.com:8089/ws'),
        'sip_ice_url' => env('TELEPHONY_SIP_ICE_URL'),

        // Optional TURN relay (used when both peers are behind symmetric NAT).
        'turn_url' => env('TELEPHONY_TURN_URL'),
        'turn_username' => env('TELEPHONY_TURN_USERNAME'),
        'turn_credential' => env('TELEPHONY_TURN_CREDENTIAL'),

        // A short-lived cache TTL for the per-user credential endpoint.
        'credential_ttl' => (int) env('TELEPHONY_CREDENTIAL_TTL', 300),
    ],

    /*
    | Logging channel used by all integrations.
    */
    'log_channel' => env('INTEGRATION_LOG_CHANNEL', 'integrations'),

];
