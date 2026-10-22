{{--
    شريط الجلسة الفنية المختصر.

    يُستخدم في صفحات «الإعدادات الفنية للاتصالات» التي لا تُعرض إلا داخل جلسة
    فنية حيّة، فيعرض في سطر واحد: المؤسسة، الحساب الفني، حالة الجلسة، وزر
    إنهائها — بلا مستطيلات تحذير وبلا تكرار لأي معلومة في مكان آخر من الصفحة.

    شرح وظيفة القسم وتسجيل العمليات وفصل الحساب الفني عن حساب مدير المؤسسة
    مجموعة في «مساعدة» قابلة للفتح، فلا تستهلك مساحة ولا تُقرأ كتنبيه دائم.
--}}
<div class="tech-bar" role="status">
    <span class="status active">{{ __('telephony_technical.session_badge') }}</span>

    <span class="tech-fact">
        <small>{{ __('company.gateway_session_org') }}</small>
        <b>{{ $org->name_ar ?? $org->name_en }}</b>
    </span>

    <span class="tech-fact">
        <small>{{ __('company.gateway_session_account') }}</small>
        <b class="code-note">{{ auth('technical')->user()?->username }}</b>
    </span>

    <details class="tech-help">
        <summary>{{ __('company.gateway_help_toggle') }}</summary>
        <p>{{ __('company.gateway_session_note') }}</p>
        <p>{{ __('company.gateway_separation_note') }}</p>
    </details>

    <form method="POST" action="{{ route('company.telephony-technical.logout') }}" class="tech-bar-end">
        @csrf
        <button type="submit" class="btn-quiet">{{ __('telephony_technical.session_end') }}</button>
    </form>
</div>
