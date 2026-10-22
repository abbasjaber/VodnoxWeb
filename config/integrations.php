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

        /*
        | GSM gateway (GoIP and similar) — a *separate*, classic SIP/UDP
        | profile from the browser phone above. These are the values an
        | installer types into the device, so they are the SIP host/port the
        | gateway registers to — never the HTTPS provisioning URL, the AMI
        | address or the WSS URL.
        */
        'gateway_sip_host' => env('TELEPHONY_GATEWAY_SIP_HOST', env('TELEPHONY_SIP_DOMAIN', 'pbx.vodnox.com')),
        'gateway_sip_port' => (int) env('TELEPHONY_GATEWAY_SIP_PORT', 5060),
        'gateway_sip_transport' => env('TELEPHONY_GATEWAY_SIP_TRANSPORT', 'udp'),
        'gateway_realm' => env('TELEPHONY_GATEWAY_REALM', env('TELEPHONY_SIP_DOMAIN', 'pbx.vodnox.com')),
        'gateway_registration_interval' => (int) env('TELEPHONY_GATEWAY_REGISTER_INTERVAL', 60),

        // Generated inbound path numbers start here (per organization) so they
        // never collide with employee extensions and are never hard-coded.
        'gateway_inbound_start' => (int) env('TELEPHONY_GATEWAY_INBOUND_START', 5000),

        // A registration reading older than this is reported as unknown, not
        // as "disconnected".
        'gateway_registration_stale' => (int) env('TELEPHONY_GATEWAY_STALE_SECONDS', 180),

        /*
        | Lifetime of the "Telephony Technical Settings" support session.
        |
        | A technical session ends after `idle_minutes` without activity AND in
        | any case after `absolute_minutes`, whichever comes first. Both are
        | configurable so the operator can tighten or relax them per deployment
        | without touching code. They are enforced server-side by
        | App\Http\Middleware\EnsureTechnicalSession, never by the browser.
        */
        'technical_session' => [
            'idle_minutes' => (int) env('TELEPHONY_TECHNICAL_IDLE_MINUTES', 15),
            'absolute_minutes' => (int) env('TELEPHONY_TECHNICAL_ABSOLUTE_MINUTES', 120),
        ],

        /*
        | Device profiles.
        |
        | A profile fixes three things that differ between models and that no
        | administrator may improvise:
        |  - `lines`            how many SIM ports the model actually has;
        |  - `credential_mode`  how the device authenticates:
        |                         per_line → every port registers with its OWN
        |                                    SIP account (each port gets its own
        |                                    Authentication ID / Phone Number);
        |                         shared   → the whole device uses one account.
        |                       Every profile currently shipping uses per_line,
        |                       which is what the agent implements, so a model
        |                       needing one account for all ports is deliberately
        |                       NOT offered instead of being faked.
        |  - `verified`         whether the profile was exercised on real hardware.
        |
        | `verified: false` profiles are offered but labelled as unverified in
        | the UI, and the operator is told a real multi-port device has to be
        | tested before the model can be considered supported.
        */
        'gateway_profiles' => [
            'goip-1' => [
                'label_ar' => 'GoIP — منفذ واحد (GHSFVT-1.1-68-9)',
                'label_en' => 'GoIP — 1 port (GHSFVT-1.1-68-9)',
                'lines' => 1,
                'credential_mode' => 'per_line',
                'verified' => true,
                'notes_ar' => 'مختبر فعلياً على جهاز حقيقي: تسجيل SIP وصوت باتجاهين.',
                'notes_en' => 'Exercised on real hardware: SIP registration and two-way audio.',
            ],
            'goip-4' => [
                'label_ar' => 'GoIP — 4 منافذ (GSM)',
                'label_en' => 'GoIP — 4 ports (GSM)',
                'lines' => 4,
                'credential_mode' => 'per_line',
                'verified' => false,
                'notes_ar' => 'لم يُختبر بعد على جهاز حقيقي متعدد المنافذ. كل منفذ يأخذ حساب SIP مستقلاً ورقم مسار وارد مستقلاً.',
                'notes_en' => 'Not yet exercised on real multi-port hardware. Each port gets its own SIP account and its own inbound path number.',
            ],
            'goip-8' => [
                'label_ar' => 'GoIP — 8 منافذ (GSM)',
                'label_en' => 'GoIP — 8 ports (GSM)',
                'lines' => 8,
                'credential_mode' => 'per_line',
                'verified' => false,
                'notes_ar' => 'لم يُختبر بعد على جهاز حقيقي. عدد المنافذ من ملف الموديل فقط؛ يلزم اختبار جهاز 8 منافذ قبل اعتماده بالكامل.',
                'notes_en' => 'Not yet exercised on real hardware. The port count comes from the model profile only; an 8-port device must be tested before it is fully supported.',
            ],
        ],
    ],

    /*
    | Logging channel used by all integrations.
    */
    'log_channel' => env('INTEGRATION_LOG_CHANNEL', 'integrations'),

];
