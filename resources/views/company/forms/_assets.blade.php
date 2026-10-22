{{--
    Shared VODNOX FORMS assets for the organization workspace.

    The module re-uses the design stylesheets (crm.css for the shared shell and
    forms.css for the forms-specific components) and ships its own small vanilla
    controller. It intentionally avoids the prototype's forms.js, which expects a
    client-side store and DOM nodes that do not exist in the Laravel layout.
--}}
@push('styles')
    <link rel="stylesheet" href="{{ $assetv('assets/vodnox/crm.css') }}">
    <link rel="stylesheet" href="{{ $assetv('assets/vodnox/forms.css') }}">
@endpush

<script>document.body.setAttribute('data-forms-role', 'company');</script>

@push('scripts')
<script>
(function () {
    'use strict';

    var toast = document.getElementById('toast');
    function notify(message) {
        if (!toast || !message) { return; }
        toast.textContent = message;
        toast.classList.add('show');
        window.setTimeout(function () { toast.classList.remove('show'); }, 4500);
    }

    var flash = document.querySelector('[data-forms-flash]');
    if (flash) { notify(flash.getAttribute('data-forms-flash')); }

    /* Project modal (partials.modal) ------------------------------------- */
    var modal = document.getElementById('modal');
    var modalForm = document.getElementById('modalForm');
    var modalBody = document.getElementById('modalBody');
    var modalTitle = document.getElementById('modalTitle');

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-forms-modal]');
        if (opener && modal && modalForm) {
            var template = document.getElementById(opener.getAttribute('data-forms-template') || '');
            if (!template) { return; }
            event.preventDefault();
            modalTitle.textContent = opener.getAttribute('data-forms-title') || '';
            modalBody.innerHTML = template.innerHTML;
            modalForm.setAttribute('action', opener.getAttribute('data-forms-action') || '');
            modalForm.setAttribute('method', 'post');
            modal.showModal();
            return;
        }

        if (event.target.closest('#closeModal') || event.target.closest('#cancelModal')) {
            event.preventDefault();
            if (modal) { modal.close(); }
            return;
        }

        var tab = event.target.closest('[data-forms-tab]');
        if (tab) {
            event.preventDefault();
            var group = tab.closest('[data-forms-tabs]') || document;
            group.querySelectorAll('[data-forms-tab]').forEach(function (button) {
                button.setAttribute('aria-pressed', String(button === tab));
            });
            group.querySelectorAll('[data-forms-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-forms-panel') !== tab.getAttribute('data-forms-tab');
            });
            return;
        }

        var copy = event.target.closest('[data-forms-copy]');
        if (copy) {
            event.preventDefault();
            var link = copy.getAttribute('data-forms-copy') || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(
                    function () { notify(copy.getAttribute('data-forms-copied') || ''); },
                    function () { window.prompt('', link); }
                );
            } else {
                window.prompt('', link);
            }
        }
    });

    document.addEventListener('submit', function (event) {
        var confirmForm = event.target.closest('form[data-confirm]');
        if (confirmForm && !window.confirm(confirmForm.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    /* Builder: field-definition form ------------------------------------- */
    var typeSelect = document.getElementById('forms-field-type');

    function syncFieldGroups() {
        if (!typeSelect) { return; }
        var type = typeSelect.value;
        document.querySelectorAll('[data-types]').forEach(function (group) {
            var types = group.getAttribute('data-types').split(' ');
            var show = types.indexOf('*') !== -1 ? true : types.indexOf(type) !== -1;
            if (types.indexOf('!section') !== -1 && type !== 'section') { show = true; }
            group.hidden = !show;
        });
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', syncFieldGroups);
        document.querySelectorAll('[data-forms-pick]').forEach(function (button) {
            button.addEventListener('click', function () {
                typeSelect.value = button.getAttribute('data-forms-pick');
                document.querySelectorAll('[data-forms-pick]').forEach(function (b) {
                    b.classList.toggle('selected', b === button);
                });
                syncFieldGroups();
                typeSelect.focus();
            });
        });
        syncFieldGroups();
    }

    var fieldForm = document.querySelector('form[data-forms-field-form]');
    if (fieldForm) {
        fieldForm.addEventListener('submit', function () {
            // Turn the one-per-line options textarea into options[] inputs.
            var optionsArea = fieldForm.querySelector('[data-forms-options]');
            var isChoice = typeSelect && ['select', 'radio', 'checkbox'].indexOf(typeSelect.value) !== -1;
            if (optionsArea && isChoice) {
                fieldForm.querySelectorAll('input[data-forms-option-input]').forEach(function (node) {
                    node.parentNode.removeChild(node);
                });
                optionsArea.value.split('\n').map(function (line) {
                    return line.trim();
                }).filter(function (line) {
                    return line !== '';
                }).forEach(function (line) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'options[]';
                    hidden.value = line;
                    hidden.setAttribute('data-forms-option-input', '1');
                    fieldForm.appendChild(hidden);
                });
            }

            // Only submit a condition when a dependency field is chosen.
            var depends = fieldForm.querySelector('[data-forms-condition-depends]');
            if (depends) {
                var active = depends.value !== '';
                fieldForm.querySelectorAll('[data-forms-condition] input, [data-forms-condition] select, [data-forms-condition] textarea').forEach(function (node) {
                    if (!active) {
                        node.removeAttribute('name');
                    }
                });
            }

            fieldForm.querySelectorAll('[hidden] input, [hidden] select, [hidden] textarea').forEach(function (node) {
                node.removeAttribute('name');
            });
        });
    }
})();
</script>
@endpush
