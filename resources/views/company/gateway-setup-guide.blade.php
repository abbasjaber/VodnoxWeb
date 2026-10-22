@php
    /*
     * The printable wiring guide of one GSM device.
     *
     * Rendered to HTML and converted to PDF by mPDF (A4, RTL, embedded Arabic
     * font). It is built from the device's real rows only; nothing here is
     * invented, and the SIP password is only present when the caller explicitly
     * asked for it AND holds the secret-reveal permission.
     *
     * The guide repeats table headers on every page (mPDF repeats <thead>), and
     * every long table is written so a row never has to be cut in half.
     */
    $logoPath = public_path('assets/vodnox/vodnox-logo.png');
    $hasLogo = is_file($logoPath);

    $portCount = $gateway->lines->count();
    $modelLabel = __(
        ($profile['label_'.app()->getLocale()] ?? null)
        ?: ($profile['label_en'] ?? $gateway->model)
    );

    $t = fn (string $ar, string $en) => app()->getLocale() === 'ar' ? $ar : $en;

    $sharedRows = [
        [$t('SIP Host', 'SIP Host'), (string) $serverSettings['host']],
        [$t('SIP Port', 'SIP Port'), (string) $serverSettings['port']],
        [$t('Transport', 'Transport'), strtoupper((string) $serverSettings['transport'])],
        [$t('Home Domain', 'Home Domain'), (string) $serverSettings['domain']],
        [$t('Re-Register Interval', 'Re-Register Interval'), $serverSettings['register_interval'].' s'],
        [$t('SIP Proxy', 'SIP Proxy'), $serverSettings['host'].':'.$serverSettings['port']],
        [$t('SIP Registrar', 'SIP Registrar'), $serverSettings['domain']],
        [$t('Outbound Proxy', 'Outbound Proxy'), $t('اتركه فارغاً', 'leave empty')],
    ];

    $audioRows = [
        [$t('Call In (وارد إلى VoIP)', 'Call In (to VoIP)'), $t('تشغيل', 'Enable')],
        [$t('Forwarding to VoIP Number', 'Forwarding to VoIP Number'), $t('مُفعَّل — الرقم = رقم مسار الوارد للمنفذ', 'Enabled — the number is the port inbound path number')],
        [$t('CID Forward Mode', 'CID Forward Mode'), $t('Use P-Asserted Identity Header', 'Use P-Asserted Identity Header')],
        [$t('Media Encryption', 'Media Encryption'), $t('None', 'None')],
        [$t('NAT Traversal', 'NAT Traversal'), $t('None', 'None')],
        [$t('Preferred Codec', 'Preferred Codec'), 'G.711A / G.711U (PCMA/PCMU)'],
    ];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
<meta charset="utf-8">
<title>VODNOX — {{ __('telephony_technical.guide_button') }}</title>
<style>
    body { font-family: xbriyaz, sans-serif; font-size: 10.5pt; color: #16233b; }
    h1 { font-size: 16pt; margin: 0 0 2px; color: #101d35; }
    h2 { font-size: 12.5pt; margin: 14px 0 6px; color: #101d35; border-bottom: 1px solid #d6ddea; padding-bottom: 3px; }
    p  { margin: 3px 0; line-height: 1.6; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    th, td { border: 1px solid #c8d1e0; padding: 4px 6px; text-align: right; vertical-align: top; }
    thead th { background: #eef2f9; color: #101d35; }
    .brand { text-align: center; border-bottom: 2px solid #101d35; padding-bottom: 6px; margin-bottom: 8px; }
    .brand small { color: #6b7a92; letter-spacing: 2px; }
    .muted { color: #5b6b84; font-size: 9.5pt; }
    .warn { border: 1px solid #b8791a; background: #fdf4e3; padding: 6px 8px; margin: 6px 0; }
    .legend td { font-size: 9.5pt; }
</style>
</head>
<body>

<div class="brand">
    @if ($hasLogo)
        <img src="{{ $logoPath }}" alt="VODNOX" style="height:46px">
    @endif
    <h1>VODNOX</h1>
    <small>{{ $t('الإعدادات الفنية للاتصالات', 'TELEPHONY TECHNICAL SETTINGS') }}</small>
</div>

<h2>{{ $t('معلومات الجهاز', 'Device information') }}</h2>
<table>
    <tbody>
        <tr><th style="width:32%">{{ $t('المؤسسة', 'Organization') }}</th><td>{{ $org->name_ar ?? $org->name_en }} ({{ $org->code }})</td></tr>
        <tr><th>{{ $t('اسم الجهاز', 'Device name') }}</th><td>{{ $gateway->label }}</td></tr>
        <tr><th>{{ $t('الموديل', 'Model') }}</th><td>{{ $modelLabel }}</td></tr>
        <tr><th>{{ $t('عدد المنافذ', 'Port count') }}</th><td>{{ $portCount }}</td></tr>
        <tr><th>{{ $t('تاريخ التصدير', 'Exported at') }}</th><td>{{ $exportedAt->format('Y-m-d H:i') }}</td></tr>
    </tbody>
</table>

<h2>{{ $t('الإعدادات المشتركة (SIP)', 'Shared SIP settings') }}</h2>
<table>
    <thead>
        <tr>
            <th style="width:40%">{{ $t('الحقل كما يظهر في الجهاز', 'Field as shown on the device') }}</th>
            <th>{{ $t('القيمة', 'Value') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($sharedRows as [$label, $value])
            <tr><td>{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </tbody>
</table>
<p class="muted">{{ $t('هذه هي إعدادات SIP/UDP التي تُدخل في الجهاز، وليست عنوان الويب ولا منفذ AMI ولا عنوان WSS.', 'These are the SIP/UDP settings typed into the device — not the web address, the AMI port or the WSS address.') }}</p>

<h2>{{ $t('إعدادات الصوت والاتصال', 'Audio & call settings') }}</h2>
<table>
    <thead>
        <tr>
            <th style="width:40%">{{ $t('الحقل', 'Field') }}</th>
            <th>{{ $t('القيمة', 'Value') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($audioRows as [$label, $value])
            <tr><td>{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </tbody>
</table>

<h2>{{ $t('بيانات كل منفذ', 'Per-port settings') }}</h2>
<table>
    <thead>
        <tr>
            <th>{{ $t('المنفذ', 'Port') }}</th>
            <th>{{ $t('اسم الخط', 'Line name') }}</th>
            <th>{{ $t('رقم السيم', 'SIM number') }}</th>
            <th>{{ $t('Authentication ID', 'Authentication ID') }}</th>
            <th>{{ $t('Phone Number', 'Phone Number') }}</th>
            @if ($includePasswords)
                <th>{{ $t('كلمة مرور SIP', 'SIP password') }}</th>
            @endif
            <th>{{ $t('رقم مسار الوارد', 'Inbound path number') }}</th>
            <th>{{ $t('رقم الموظف (الوجهة)', 'Employee number (destination)') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($gateway->lines->sortBy('line_index') as $line)
            <tr>
                <td>#{{ $line->line_index }}</td>
                <td>{{ $line->label ?: '—' }}</td>
                <td>{{ $line->sim_number ?: '—' }}</td>
                <td>{{ $line->sip_username ?: '—' }}</td>
                <td>{{ $line->sip_username ?: '—' }}</td>
                @if ($includePasswords)
                    <td>{{ $line->sip_password ?: '—' }}</td>
                @endif
                <td>{{ $line->inbound_number ?: '—' }}</td>
                <td>{{ $line->route?->defaultDestination?->extension ?: '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="legend">
    <thead>
        <tr><th colspan="2">{{ $t('تمييز الأرقام', 'Distinguishing the numbers') }}</th></tr>
    </thead>
    <tbody>
        <tr>
            <th style="width:32%">{{ $t('رقم السيم', 'SIM number') }}</th>
            <td>{{ $t('رقم الشريحة الخلوية داخل المنفذ، يُكتب يدوياً للتوثيق فقط ولا يدخل في إعدادات SIP.', 'The mobile SIM number inside the port; typed by hand for reference only and not part of the SIP settings.') }}</td>
        </tr>
        <tr>
            <th>{{ $t('رقم الموظف', 'Employee number') }}</th>
            <td>{{ $t('التحويلة الداخلية التي يرنّ عليها الموظف عند وصول المكالمة.', 'The internal extension that rings when the call arrives.') }}</td>
        </tr>
        <tr>
            <th>{{ $t('رقم مسار الوارد', 'Inbound path number') }}</th>
            <td>{{ $t('رقم داخلي يولّده الخادم لكل منفذ ويُدخل في «Forwarding to VoIP Number»، وهو مختلف عن رقم السيم ورقم الموظف.', 'An internal number the server allocates per port, entered in "Forwarding to VoIP Number"; it differs from the SIM number and the employee number.') }}</td>
        </tr>
    </tbody>
</table>

@if ($includePasswords)
    <div class="warn">
        {{ $t(
            'يحتوي هذا الملف على كلمات مرور SIP الفعلية، ويُسلَّم للفني المخوّل فقط. تدوير بيانات SIP يجعل هذه النسخة قديمة.',
            'This file contains real SIP passwords and is handed to the authorised engineer only. Rotating the SIP credentials makes this copy stale.'
        ) }}
    </div>
@else
    <p class="muted">{{ $t('لم تُضمَّن كلمات مرور SIP في هذه النسخة.', 'SIP passwords are not included in this copy.') }}</p>
@endif

<h2>{{ $t('خطوات الحفظ والتحقق', 'Save & verify') }}</h2>
<ol>
    <li>{{ $t('أدخل الإعدادات المشتركة مرة واحدة في الجهاز (SIP Proxy / SIP Registrar).', 'Enter the shared settings once on the device (SIP Proxy / SIP Registrar).') }}</li>
    <li>{{ $t('أدخل بيانات كل منفذ في تبويبه الخاص؛ لا تنسخ بيانات منفذ إلى آخر.', 'Enter each port\'s values on its own tab; never copy one port\'s values to another.') }}</li>
    <li>{{ $t('احفظ الإعدادات ثم أعد تشغيل خدمة SIP في الجهاز إن لزم.', 'Save, then restart the device SIP service if required.') }}</li>
    <li>{{ $t('تحقّق من حالة التسجيل لكل منفذ في صفحة «الإعدادات الفنية للاتصالات».', 'Check each port\'s registration state on the "Telephony Technical Settings" page.') }}</li>
    <li>{{ $t('أجرِ مكالمة اختبار واردة وتأكد من سماع الصوت بالاتجاهين.', 'Place an inbound test call and confirm two-way audio.') }}</li>
</ol>
<p class="muted">{{ $t('التسجيل الناجح وحده لا يثبت نجاح الصوت: يجب إجراء مكالمة فعلية والتحقق من الصوت بالاتجاهين.', 'A successful registration alone does not prove audio works: an actual call must be placed and two-way audio verified.') }}</p>

<h2>{{ $t('ملاحظات', 'Notes') }}</h2>
<ul>
    <li>{{ $t('لا يتضمن هذا الملف كلمة مرور الدخول الفني، ولا حساب المؤسسة، ولا مفاتيح خدمات السيرفر.', 'This file never contains the technical sign-in password, the organization account or any server service key.') }}</li>
    <li>{{ $t('ملفات تعريف الأجهزة غير المُختبرة على عتاد حقيقي تُعلَّم صراحةً على هذا النحو.', 'Profiles not exercised on real hardware are explicitly labelled as such.') }}</li>
</ul>

</body>
</html>
