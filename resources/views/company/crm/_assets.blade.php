{{--
    Shared CRM assets: the design stylesheet and a small vanilla-JS controller
    that wires the project modal partial (partials.modal) to the CRM templates,
    drives the customer profile tabs, the delete confirmations and the flash toast.
--}}
@push('styles')
    <link rel="stylesheet" href="{{ $assetv('assets/vodnox/crm.css') }}">
@endpush

@push('scripts')
<script>
(function () {
    'use strict';

    var modal = document.getElementById('modal');
    if (!modal) {
        return;
    }

    var form = document.getElementById('modalForm');
    var body = document.getElementById('modalBody');
    var title = document.getElementById('modalTitle');

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-crm-modal]');
        if (opener) {
            var template = document.getElementById(opener.getAttribute('data-crm-template') || '');
            if (!template) {
                return;
            }
            event.preventDefault();
            title.textContent = opener.getAttribute('data-crm-title') || '';
            body.innerHTML = template.innerHTML;
            form.setAttribute('action', opener.getAttribute('data-crm-action') || '');
            form.setAttribute('method', 'post');
            modal.showModal();
            return;
        }

        var tab = event.target.closest('[data-crm-tab]');
        if (tab) {
            event.preventDefault();
            var group = tab.closest('[data-crm-tabgroup]') || document;
            group.querySelectorAll('[data-crm-tab]').forEach(function (button) {
                button.setAttribute('aria-pressed', String(button === tab));
            });
            group.querySelectorAll('[data-crm-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-crm-panel') !== tab.getAttribute('data-crm-tab');
            });
        }
    });

    var cancel = document.getElementById('cancelModal');
    if (cancel) {
        cancel.addEventListener('click', function (event) {
            event.preventDefault();
            modal.close();
        });
    }

    var close = document.getElementById('closeModal');
    if (close) {
        close.addEventListener('click', function (event) {
            event.preventDefault();
            modal.close();
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-confirm]');
        if (form && !window.confirm(form.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    var flash = document.querySelector('[data-crm-flash]');
    var toast = document.getElementById('toast');
    if (flash && toast) {
        toast.textContent = flash.getAttribute('data-crm-flash');
        toast.classList.add('show');
        window.setTimeout(function () {
            toast.classList.remove('show');
        }, 4500);
    }
})();
</script>
@endpush
