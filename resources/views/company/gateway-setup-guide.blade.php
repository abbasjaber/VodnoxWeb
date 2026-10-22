@php
    /*
     * The printable installation guide of one GSM gateway.
     *
     * English only and left to right on purpose: the engineer reads this file
     * standing at the device, so the page names, the field names and the values
     * must match the English interface of the device — never the language the
     * dashboard happened to be in when the export was started. Nothing in this
     * file is translated, including the organization, device and line names:
     * a name stored in another script is replaced by a neutral English
     * identifier instead of being transliterated.
     *
     * The guide is built from the device's real rows only. It never invents a
     * value, never assumes that every model has the same pages as the one that
     * was exercised on real hardware, and only carries a SIP password when the
     * caller explicitly asked for it AND holds the secret-reveal permission.
     */
    $logoPath = public_path('assets/vodnox/vodnox-logo.png');
    $hasLogo = is_file($logoPath);

    /*
     * An English-only label. Anything the device interface could not show —
     * an empty value, or a value written in another script — becomes a plain
     * English identifier such as "Gateway #1" or "Line #2".
     */
    $en = function (?string $value, string $fallback): string {
        $value = trim((string) $value);

        // A value the device interface could not show: empty, or written in a
        // script the English guide cannot carry (Arabic and everything outside
        // Latin-1, Latin Extended and general punctuation). Typographic
        // punctuation such as the em dash is allowed — the PDF font renders it,
        // so an official English label is never thrown away.
        if ($value === '' || preg_match('/[^\x00-\x{024F}\x{2000}-\x{206F}\x{20A0}-\x{20CF}]/u', $value) === 1) {
            return $fallback;
        }

        return $value;
    };

    $organizationName = $en($org->name_en ?? null, 'Organization '.$en($org->code ?? null, '#'.$org->getKey()));
    $deviceName = $en($gateway->label ?? null, 'Gateway #'.$gateway->getKey());
    $modelName = $en($profile['label_en'] ?? null, (string) $gateway->model);
    $firmware = $en($gateway->firmware ?? null, '');
    $profileNote = $en($profile['notes_en'] ?? null, '');
    $verified = (bool) ($profile['verified'] ?? false);

    $ports = $gateway->lines->sortBy('line_index');
    $usedPorts = $ports->where('enabled', true)->values();
    $unusedPorts = $ports->where('enabled', false)->values();

    $serverHost = $en($serverSettings['host'] ?? null, '');
    $serverPort = (string) ($serverSettings['port'] ?? '');
    $serverDomain = $en($serverSettings['domain'] ?? null, '');
    $serverTransport = strtoupper((string) ($serverSettings['transport'] ?? 'udp'));
    $registerInterval = (string) ($serverSettings['register_interval'] ?? '');

    $proxyOf = fn ($line): string => $en($line->sip_host ?? null, $serverHost).':'.($line->sip_port ?: $serverPort);
    $domainOf = fn ($line): string => $en($line->sip_domain ?? null, $serverDomain);
    $transportOf = fn ($line): string => strtoupper((string) ($line->sip_transport ?: $serverTransport));
    $lineNameOf = fn ($line): string => $en($line->label ?? null, 'Line #'.$line->line_index);
@endphp
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<title>VODNOX — GSM Gateway Setup Guide</title>
<style>
    body { font-family: xbriyaz, sans-serif; font-size: 9.8pt; color: #16233b; line-height: 1.5; }
    h1 { font-size: 16.5pt; margin: 2px 0 1px; color: #101d35; }
    h2 { font-size: 11.5pt; margin: 15px 0 5px; padding: 5px 8px; color: #101d35;
         background: #eef2f9; border-left: 4px solid #101d35; page-break-after: avoid; }
    h3 { font-size: 10.2pt; margin: 11px 0 3px; color: #101d35; page-break-after: avoid; }
    p  { margin: 3px 0; }
    ol, ul { margin: 3px 0 6px 16px; padding: 0; }
    li { margin-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; margin: 4px 0 7px; }
    th, td { border: 1px solid #c2cddd; padding: 4px 7px; text-align: left; vertical-align: top; }
    thead th { background: #eef2f9; color: #101d35; }
    tbody tr { page-break-inside: avoid; }
    .brand { text-align: center; border-bottom: 2px solid #101d35; padding-bottom: 7px; margin-bottom: 10px; }
    .brand small { color: #46566f; letter-spacing: 1.5px; font-size: 9pt; }
    .field { width: 38%; font-weight: bold; }
    .mono { white-space: nowrap; }
    .muted { color: #46566f; font-size: 9pt; }
    /* mPDF applies `page-break-after: avoid` only to a heading inside a nested
       block, so the whole guide is wrapped in .doc and every heading sits at
       block level 1. */
    .doc { padding: 0; margin: 0; }
    /* A heading must never be left alone at the foot of a page. mPDF only looks
       a couple of lines ahead for `page-break-after: avoid`, which is not enough
       for a heading followed by a box or a table, so each heading and the block
       that belongs to it are grouped and kept on one page. */
    .keep { page-break-inside: avoid; }
    .step-path { background: #f6f8fb; border: 1px solid #c2cddd; padding: 4px 7px; margin: 3px 0 5px; }
    .warn { border: 1px solid #b8791a; background: #fdf4e3; padding: 6px 8px; margin: 6px 0; page-break-inside: avoid; }
    .warn.secrets { border-color: #a3281d; background: #fdecea; }
    .note { border: 1px solid #c2cddd; background: #f6f8fb; padding: 6px 8px; margin: 6px 0; page-break-inside: avoid; }
    .port { border: 1px solid #c2cddd; padding: 6px 9px 2px; margin: 7px 0; page-break-inside: avoid; }
    .port h3 { margin-top: 0; }
</style>
</head>
<body>
<div class="doc">

<div class="brand">
    @if ($hasLogo)
        <img src="{{ $logoPath }}" alt="VODNOX" style="height:44px">
    @endif
    <h1>VODNOX — GSM Gateway Setup Guide</h1>
    <small>TELEPHONY TECHNICAL SETTINGS</small>
</div>

<div class="keep">
<h2>This export</h2>
<table>
    <tbody>
        <tr><th class="field">Organization</th><td>{{ $organizationName }}</td></tr>
        <tr><th class="field">Device</th><td>{{ $deviceName }}</td></tr>
        <tr><th class="field">Model</th><td>{{ $modelName }}</td></tr>
        @if ($firmware !== '')
            <tr><th class="field">Firmware</th><td>{{ $firmware }}</td></tr>
        @endif
        <tr><th class="field">Ports</th><td>{{ $usedPorts->count() }} of {{ $ports->count() }} in use ({{ $ports->count() }} physical {{ $ports->count() === 1 ? 'port' : 'ports' }})</td></tr>
        <tr><th class="field">Exported at</th><td>{{ $exportedAt->format('Y-m-d H:i') }} ({{ $exportedAt->format('T') }})</td></tr>
    </tbody>
</table>
</div>

@if ($verified)
    <p class="muted">Profile status: verified on real hardware. {{ $profileNote }}</p>
@else
    <div class="warn">
        <b>Profile status: not verified on real hardware.</b>
        {{ $profileNote }}
        The page names and field names in this guide were confirmed on the
        firmware named above for the verified model only. On this model, check
        every page and field name against your own device before you save a
        value; do not assume the screens are identical.
    </div>
@endif

<div class="keep">
<h2>Before you start</h2>
<ul>
    <li>The gateway device, its power supply and one SIM card for every port you intend to use.</li>
    <li>One Ethernet cable from the device to a LAN port of the organization router.</li>
    <li>A computer on the same network, with a browser.</li>
    <li>The device administration password, and the current SIP passwords if this copy includes them.</li>
</ul>
</div>
<p class="muted">
    The device registers outbound to the VODNOX telephony server. No public address, no port forwarding
    and no DMZ rule is required on the site router for this installation.
</p>

<div class="keep">
<h2>Step 1 — Connect the gateway and open its web interface</h2>
<ol>
    <li>Insert one SIM card in every port you are going to use, and write down which SIM went into which port. Step 3 uses that mapping.</li>
    <li>Connect the device LAN port to a LAN port of the organization router with the Ethernet cable. Do not use the device WAN port, and do not attach the device to a guest network.</li>
    <li>Connect power and wait about one minute until the device has finished booting.</li>
    <li>If DHCP is enabled on the device, it takes a local address from the router by itself. Nothing has to be typed on the device for this.</li>
    <li>Find that address on the router: open the router's DHCP client or lease list and match the MAC address printed on the label of the device. Do not guess the address.</li>
    <li>From the computer on the same network, open a browser and enter the address you found, for example <span class="mono">http://192.168.1.50</span>.</li>
    <li>Sign in with the administration account of the device.</li>
    <li>Keep the page open: every remaining step of this guide is done in this interface.</li>
</ol>
</div>
<p>
    This guide does not assume a fixed local address. Use the address the site DHCP server allocates, or
    the address the site policy reserves for this device. The address is a property of the site network,
    not a value that can be copied from another installation.
</p>

<div class="keep">
<h3>Do not confuse these three accounts</h3>
<table>
    <thead>
        <tr><th style="width:26%">Account</th><th style="width:30%">Where it is used</th><th>Where its values come from</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>Device administration account</td>
            <td>Signing in to the GoIP web interface in Step 1.</td>
            <td>The device itself, or the records of the site. It is never printed in this guide, and it is not the SIP password.</td>
        </tr>
        <tr>
            <td>SIP account (one per port)</td>
            <td><span class="mono">Configurations &gt; Basic VoIP</span> in Step 2.</td>
            <td>The Authentication ID and Password listed for each port in this guide.</td>
        </tr>
        <tr>
            <td>VODNOX technical account</td>
            <td>Opening the Telephony technical settings section in VODNOX.</td>
            <td>Issued to the engineer by VODNOX. It is never printed in this guide and is never typed into the device.</td>
        </tr>
    </tbody>
</table>
</div>
<p class="muted">
    If the administration password of the device is not known, use the device's own reset procedure before
    the maintenance window. Do not try passwords that were not verified for this device.
</p>

<div class="keep">
<h2>Step 2 — Configure the SIP account</h2>
<div class="step-path">Path on the device: <b>Configurations &gt; Basic VoIP</b></div>
</div>

<div class="keep">
<h3>2.1 Fields that are the same for every port</h3>
<table>
    <thead>
        <tr><th class="field">Field in the device</th><th>Value to enter</th></tr>
    </thead>
    <tbody>
        <tr><td class="field">Endpoint Type</td><td>SIP Phone</td></tr>
        <tr><td class="field">Config Mode</td><td>Single Server Mode</td></tr>
        <tr><td class="field">SIP Proxy</td><td class="mono">{{ $serverHost }}:{{ $serverPort }}</td></tr>
        <tr><td class="field">SIP Registrar</td><td class="mono">{{ $serverDomain }}</td></tr>
        <tr><td class="field">Re-register Period</td><td class="mono">{{ $registerInterval }}</td></tr>
        <tr><td class="field">Home Domain</td><td class="mono">{{ $serverDomain }}</td></tr>
        <tr><td class="field">Outbound Proxy</td><td>Leave empty</td></tr>
    </tbody>
</table>
</div>
<p class="muted">
    Write the proxy as <span class="mono">host:port</span> when the field takes one text value. When the
    form has a separate port box, put the host in the host field and <span class="mono">{{ $serverPort }}</span>
    in the port box — the meaning is the same. The transport to use is <span class="mono">{{ $serverTransport }}</span>;
    keep the same transport on the device in Step 4.
</p>

<div class="keep">
<h3>2.2 Fields that are different for every port</h3>
<p>
    Repeat the block below once for each port that carries a SIM, using the values of that port only.
    Never copy the account of one port into another port: every port registers with its own identity, and
    a duplicated identity makes the incoming call arrive on the wrong port.
</p>
</div>
@foreach ($usedPorts as $line)
    <div class="port">
        <h3>Port #{{ $line->line_index }} — {{ $lineNameOf($line) }}@if ($line->sim_number) (SIM {{ $line->sim_number }})@endif</h3>
        <div class="step-path">Open <b>Configurations &gt; Basic VoIP</b> and make sure you are editing this port before you type anything.</div>
        <table>
            <thead>
                <tr><th class="field">Field in the device</th><th>Value to enter</th></tr>
            </thead>
            <tbody>
                <tr><td class="field">Authentication ID</td><td class="mono">{{ $line->sip_username ?: '—' }}</td></tr>
                <tr>
                    <td class="field">Password</td>
                    <td>
                        @if ($includePasswords)
                            <span class="mono">{{ $line->sip_password ?: '—' }}</span>
                        @else
                            Ask the authorised engineer for the current password of this port
                            (see Passwords and confidentiality). It is never printed in a copy
                            that was exported without secrets.
                        @endif
                    </td>
                </tr>
                <tr><td class="field">Phone Number</td><td class="mono">{{ $line->sip_username ?: '—' }}</td></tr>
            </tbody>
        </table>
        <p class="muted">
            Transport for this port: <span class="mono">{{ $transportOf($line) }}</span> ·
            Proxy for this port: <span class="mono">{{ $proxyOf($line) }}</span> ·
            Home Domain: <span class="mono">{{ $domainOf($line) }}</span>
        </p>
    </div>
@endforeach

<p><b>Click Save Changes.</b> The device keeps the values and starts registering.</p>

<div class="keep">
<h2>Step 3 — Configure incoming GSM calls</h2>
<div class="step-path">Path on the device: <b>Configurations &gt; Call In</b></div>
<table>
    <thead>
        <tr><th class="field">Field in the device</th><th>Value to enter</th></tr>
    </thead>
    <tbody>
        <tr><td class="field">Call IN via GSM</td><td>Enable</td></tr>
        <tr><td class="field">CID Forward Mode</td><td>Use RemotePartyID</td></tr>
    </tbody>
</table>
</div>

<div class="keep">
<h3>3.1 Forwarding to VoIP Number — one value per port</h3>
<p>
    Enter the number below for the port you are configuring. It is an internal number that the VODNOX
    server allocated to that port. It is <b>not</b> the mobile number of the SIM, and it is <b>not</b> the
    extension of the employee who will answer: the employee's extension is chosen in VODNOX, in the
    Numbers &amp; routing section, and can be changed there without touching the device.
</p>
</div>
<table>
    <thead>
        <tr><th style="width:22%">Port</th><th style="width:30%">Forwarding to VoIP Number</th><th>Employee extension configured in VODNOX</th></tr>
    </thead>
    <tbody>
        @foreach ($usedPorts as $line)
            <tr>
                <td>#{{ $line->line_index }} — {{ $lineNameOf($line) }}</td>
                <td class="mono">{{ $line->inbound_number ?: '—' }}</td>
                <td class="mono">{{ $line->route?->defaultDestination?->extension ?: 'not set yet' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<p><b>Click Save Changes.</b></p>
<p class="muted">
    In one number, in short: the SIM number is the mobile line inside the port, the Forwarding to VoIP
    Number is the internal number of the port, and the employee extension is the phone that rings.
</p>

<div class="keep">
<h2>Step 4 — Configure media</h2>
<div class="step-path">Path on the device: <b>Configurations &gt; Media</b></div>
<table>
    <thead>
        <tr><th class="field">Field in the device</th><th>Value to enter</th></tr>
    </thead>
    <tbody>
        <tr><td class="field">Media Encryption</td><td>None</td></tr>
        <tr><td class="field">Media NAT Traversal</td><td>None</td></tr>
        <tr><td class="field">Signaling NAT Traversal</td><td>Leave the device default unchanged</td></tr>
        <tr><td class="field">Audio Codec Preference</td><td>Enable G.711A and G.711U only</td></tr>
        <tr><td class="field">RTP Port Range</td><td>Leave the device default unchanged</td></tr>
    </tbody>
</table>
<p><b>Click Save Changes.</b></p>
</div>

<div class="keep">
<h3>Where to set the codec</h3>
<p>
    The codec is listed under <b>Audio Codec Preference</b> on the same Media page: move
    <span class="mono">G.711A</span> and <span class="mono">G.711U</span> to the enabled side and switch
    the remaining codecs off. Both directions then negotiate the same codec.
</p>
</div>

<div class="keep">
<h3>Media NAT Traversal is not Signaling NAT Traversal</h3>
<ul>
    <li><b>Media NAT Traversal</b> affects the audio stream (RTP). Leave it as <b>None</b>.</li>
    <li><b>Signaling NAT Traversal</b> affects the SIP messages only. Leave it as the device sets it; it does not need to be enabled for this installation.</li>
    <li>The RTP port range does not have to match any range on the server. It is not fixed to a specific pair of ports, so there is no rule to add on the router for it.</li>
</ul>
</div>
<div class="note">
    Do not open a DMZ host for the device, do not add a port forwarding rule, and do not change settings on
    the router as part of this guide. The device opens the connection outbound. The one network
    precondition that is not part of this page — the telephony server accepting registrations from the site —
    is prepared by VODNOX once per site, not per device.
</div>
<p class="muted">
    If a firewall such as a FortiGate sits between the device and the internet and audio fails while
    registration succeeds, treat the SIP handling of that firewall (session helper or SIP ALG) as a task for
    the network operator of the site, not as a change to be made here blind.
</p>

<div class="keep">
<h2>Step 5 — Verify registration and test a call</h2>
<ol>
    <li>
        <b>On the device:</b> open <span class="mono">Status &gt; VoIP Status</span> — the status page that
        lists the VoIP state of each port — and check that every port you configured reads
        <span class="mono">VoIP = Y</span>.
    </li>
    <li>
        <b>In VODNOX:</b> open <b>Telephony technical settings</b> inside a technical session, use
        <b>Refresh status</b> on this device, and check that each port reaches <b>Registered on device</b>.
        A port that keeps showing Unknown means no reading arrived yet, not that the port is disconnected.
    </li>
    <li><b>Confirm the destination:</b> make sure the employee you expect to answer is signed in and their phone is registered.</li>
    <li><b>Place the call:</b> from a phone outside the site, dial the mobile number of the SIM of the port you are testing.</li>
    <li>
        <b>Confirm the whole path:</b> the chosen employee's phone rings, the call can be answered, both
        people hear each other, and the call ends cleanly from either side.
    </li>
</ol>
<p class="muted">
    A successful SIP registration proves the account and the address only. It does not prove that the
    audio path works, so the guide is not finished until a real call was placed and heard in both
    directions.
</p>
</div>

<div class="keep">
<h3>Troubleshooting</h3>
<table>
    <thead>
        <tr><th style="width:24%">What you see</th><th>What to check next</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>The port never registers (no <span class="mono">VoIP = Y</span>, and VODNOX stays Unknown)</td>
            <td>Compare the Authentication ID and Password character by character with this guide, without adding spaces. Check that SIP Proxy is <span class="mono">host:port</span> exactly as printed and that the transport is <span class="mono">{{ $serverTransport }}</span> as in Step 2. Then refresh the status in VODNOX again.</td>
        </tr>
        <tr>
            <td>The port registers but the employee's phone does not ring</td>
            <td>Check that the SIM is inserted in the port you are testing and the port is enabled in VODNOX; that <b>Forwarding to VoIP Number</b> equals the port's number from Step 3.1 and not the employee extension or the SIM number; and that the employee's own phone is registered.</td>
        </tr>
        <tr>
            <td>The call is answered but nobody hears the other side</td>
            <td>Check that <b>Media Encryption</b> is None and <b>Media NAT Traversal</b> is None, and that only G.711A and G.711U are enabled. If the call was never answered, there is no audio to hear yet — confirm the ringing step first.</td>
        </tr>
        <tr>
            <td>The call connects to the wrong person</td>
            <td>The port is fine; the destination is not. Open the Numbers &amp; routing section in VODNOX and correct the default destination of this port. Nothing has to change on the device.</td>
        </tr>
        <tr>
            <td>A second port stops working after you added another one</td>
            <td>Two ports were given the same Authentication ID. Set each port back to its own value from Step 2.2; every port has its own account and they must never be duplicated.</td>
        </tr>
    </tbody>
</table>
</div>

@if ($unusedPorts->isNotEmpty())
    <div class="keep">
    <h2>Ports not in use</h2>
    <p>
        {{ $unusedPorts->count() === 1 ? 'One port is' : 'Some ports are' }} not in use on this device:
        <span class="mono">{{ $unusedPorts->map(fn ($line) => '#'.$line->line_index.' ('.$lineNameOf($line).')')->implode(', ') }}</span>.
        They are neither provisioned nor monitored, they need no SIP account and no settings on the device,
        and they are deliberately not listed with credentials above.
    </p>
    </div>
@endif

<div class="keep">
<h2>Passwords and confidentiality</h2>
@if ($includePasswords)
    <div class="warn secrets">
        <b>This copy contains the current SIP passwords.</b>
        It is handed to the authorised engineer only. The passwords are the ones already in use at the moment
        of the export: this file neither creates nor rotates any account. Rotating a SIP credential later
        makes this copy stale, and the guide must then be downloaded again.
    </div>
@else
    <p>
        <b>This copy does not contain any SIP password.</b> Ask the authorised engineer for the current
        password of each port before you finish Step 2.2. The password is shown inside the live technical
        session only, on request, and it is never cached.
    </p>
@endif
</div>
<ul>
    <li>This file never contains the technical sign-in password, the organization account password, or any server service key.</li>
    <li>The guide is downloaded from inside a live technical session and is served as a private, non-storable response. It is never published on a public address.</li>
    <li>Handle this file as a secret when it carries passwords: store it where the site keeps its technical records and delete it when it is no longer needed.</li>
</ul>

<div class="keep">
<h2>Appendix A — Terms used</h2>
<table>
    <thead>
        <tr><th style="width:30%">Term</th><th>Meaning</th></tr>
    </thead>
    <tbody>
        <tr><td>Gateway device</td><td>The GSM gateway that converts calls between the mobile network and the SIP telephony server.</td></tr>
        <tr><td>Port (line)</td><td>One physical SIM slot of the device. Each port carries its own SIM, its own SIP account and its own routing.</td></tr>
        <tr><td>SIM number</td><td>The mobile number of the SIM inside the port. It is recorded for reference and is not typed into any SIP field.</td></tr>
        <tr><td>Authentication ID</td><td>The SIP identity the port registers with. It is unique across the whole platform.</td></tr>
        <tr><td>Forwarding to VoIP Number</td><td>The internal number the server allocated to this port; an incoming call on the port is sent to the server under this number.</td></tr>
        <tr><td>Employee extension</td><td>The extension of the person who answers. It is chosen in VODNOX, not on the device.</td></tr>
        <tr><td>Registration</td><td>The port proving it is reachable with its SIP account. It does not prove that audio works.</td></tr>
        <tr><td>Media / RTP</td><td>The audio stream of the call, separate from the SIP messages that set the call up.</td></tr>
        <tr><td>NAT traversal</td><td>The device's handling of addresses that are rewritten by a router. Media NAT affects audio; signaling NAT affects the SIP messages.</td></tr>
    </tbody>
</table>
</div>

</div>

</body>
</html>
