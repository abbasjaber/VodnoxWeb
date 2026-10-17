{{--
    Shared agent-workspace bootstrap.

    The server-rendered markup is authoritative; the browser phone lives in
    user/_phone_card.blade.php and owns every telephony interaction from real
    SIP events (registration, ringing, answered, ended).

    This partial provides the flash/validation feedback and the bindings the
    hand-written pages rely on: sidebar toggle, destructive-action confirmation
    and the explicit microphone / notification permission buttons (never
    requested automatically).
--}}
@if (session('success'))
    <span data-agent-flash="{{ session('success') }}" hidden></span>
@endif

@if ($errors->any())
    <div class="card" role="alert">
        <div class="card-head">
            <div><h2>{{ __('portal.agent.validation_failed') }}</h2></div>
        </div>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@push('scripts')
<script>
(function () {
    'use strict';

    var menuBtn = document.getElementById('menuBtn');
    var sidebar = document.getElementById('sidebar');
    if (menuBtn && sidebar) {
        menuBtn.addEventListener('click', function () { sidebar.classList.toggle('open'); });
    }

    var toastEl = document.getElementById('toast');
    function notify(message) {
        if (!toastEl || !message) { return; }
        toastEl.textContent = message;
        toastEl.classList.add('show');
        window.setTimeout(function () { toastEl.classList.remove('show'); }, 4500);
    }

    var flash = document.querySelector('[data-agent-flash]');
    if (flash) { notify(flash.getAttribute('data-agent-flash')); }

    /* Destructive-action confirmation (delete forms carry data-confirm). */
    document.addEventListener('submit', function (event) {
        var node = event.target.closest('form[data-confirm]');
        if (node && !window.confirm(node.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    /* Microphone permission: only on an explicit click. */
    var mic = document.querySelector('[data-request-mic]');
    if (mic) {
        mic.addEventListener('click', function () {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                notify(mic.getAttribute('data-unsupported'));
                return;
            }
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                stream.getTracks().forEach(function (track) { track.stop(); });
                notify(mic.getAttribute('data-granted'));
            }).catch(function () {
                notify(mic.getAttribute('data-denied'));
            });
        });
    }

    /* Browser notifications: only on an explicit click. */
    var notifyBtn = document.querySelector('[data-request-notifications]');
    if (notifyBtn) {
        notifyBtn.addEventListener('click', function () {
            if (!('Notification' in window)) {
                notify(notifyBtn.getAttribute('data-denied'));
                return;
            }
            window.Notification.requestPermission().then(function (permission) {
                notify(permission === 'granted' ? notifyBtn.getAttribute('data-granted') : notifyBtn.getAttribute('data-denied'));
            });
        });
    }
})();
</script>
@endpush
