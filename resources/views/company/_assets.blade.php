{{--
    Shared Organization workspace controller (vanilla JS only).

    It wires the project modal partial (partials.modal) to the server-rendered
    forms, handles delete confirmations, the flash toast, the day/target
    repeaters on the Communications page and the "test route" simulation.

    The workspace prototype controller (assets/vodnox/company.js) attaches a
    submit handler to the shared #modalForm that expects a client-side store.
    The node is rebuilt on load so every Laravel-driven modal form posts
    normally.
--}}
@push('scripts')
<script>
(function () {
    'use strict';

    var sharedForm = document.getElementById('modalForm');
    if (sharedForm && sharedForm.parentNode) {
        sharedForm.parentNode.replaceChild(sharedForm.cloneNode(true), sharedForm);
    }

    var modal = document.getElementById('modal');
    var form = document.getElementById('modalForm');
    var body = document.getElementById('modalBody');
    var title = document.getElementById('modalTitle');
    var submit = document.getElementById('modalSubmit');

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function reindex(scope) {
        scope.querySelectorAll('[data-company-rows]').forEach(function (container) {
            container.querySelectorAll('[data-company-row]').forEach(function (row, index) {
                row.querySelectorAll('[name]').forEach(function (node) {
                    var match = node.getAttribute('name').match(/^([a-zA-Z_]+)\[[^\]]*\](.*)$/);
                    if (match) {
                        node.setAttribute('name', match[1] + '[' + index + ']' + match[2]);
                    }
                });
            });
        });
    }

    function syncConditional(scope) {
        var strategy = scope.querySelector('[data-comm-strategy]');
        var sequential = strategy && strategy.value === 'sequential';
        scope.querySelectorAll('.comm-target-ring').forEach(function (node) {
            if (sequential) { node.removeAttribute('hidden'); } else { node.setAttribute('hidden', 'hidden'); }
        });

        var noAnswer = scope.querySelector('[data-comm-noanswer]');
        scope.querySelectorAll('.comm-na-target').forEach(function (node) {
            if (noAnswer && noAnswer.value === 'agent') { node.removeAttribute('hidden'); } else { node.setAttribute('hidden', 'hidden'); }
        });

        var outside = scope.querySelector('[data-comm-outside]');
        scope.querySelectorAll('.comm-oh-target').forEach(function (node) {
            if (outside && outside.value === 'ivr') { node.removeAttribute('hidden'); } else { node.setAttribute('hidden', 'hidden'); }
        });
    }

    function addRow(templateId, containerId) {
        var template = document.getElementById(templateId);
        var container = document.getElementById(containerId);
        if (!template || !container) { return; }
        var holder = document.createElement('div');
        holder.innerHTML = template.innerHTML;
        var row = holder.firstElementChild;
        if (!row) { return; }
        container.appendChild(row);
        reindex(container);
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-company-modal]');
        if (opener && modal && form && body) {
            var template = document.getElementById(opener.getAttribute('data-company-template') || '');
            if (!template) { return; }
            event.preventDefault();
            title.textContent = opener.getAttribute('data-company-title') || '';
            body.innerHTML = template.innerHTML;
            form.setAttribute('action', opener.getAttribute('data-company-action') || '');
            form.setAttribute('method', 'post');
            if (submit && opener.getAttribute('data-company-submit')) {
                submit.textContent = opener.getAttribute('data-company-submit');
            }
            reindex(body);
            syncConditional(body);
            modal.showModal();
            var focus = body.querySelector('input, select, textarea');
            if (focus) { focus.focus(); }
            return;
        }

        if (event.target.closest('#closeModal') || event.target.closest('#cancelModal')) {
            event.preventDefault();
            if (modal) { modal.close(); }
            return;
        }

        var addTarget = event.target.closest('[data-comm-add-target]');
        if (addTarget) {
            event.preventDefault();
            var targetHost = addTarget.getAttribute('data-comm-add-target');
            var targetContainer = document.getElementById(targetHost);
            if (targetContainer && targetContainer.querySelectorAll('[data-company-row]').length >= 2) { return; }
            addRow('tpl-comm-target', targetHost);
            return;
        }

        var addDay = event.target.closest('[data-comm-add-day]');
        if (addDay) {
            event.preventDefault();
            addRow('tpl-comm-day', addDay.getAttribute('data-comm-add-day'));
            return;
        }

        var genericAdd = event.target.closest('[data-company-add]');
        if (genericAdd) {
            event.preventDefault();
            addRow(genericAdd.getAttribute('data-company-template'), genericAdd.getAttribute('data-company-add'));
            return;
        }

        if (event.target.closest('[data-comm-remove-row]')) {
            event.preventDefault();
            var row = event.target.closest('[data-company-row]');
            if (row && row.parentNode) {
                var parent = row.parentNode;
                parent.removeChild(row);
                reindex(parent);
            }
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.closest('[data-comm-strategy], [data-comm-noanswer], [data-comm-outside]')) {
            syncConditional(event.target.closest('#modalBody') || document);
        }
    });

    document.addEventListener('submit', function (event) {
        var confirmable = event.target.closest('form[data-confirm]');
        if (confirmable && !window.confirm(confirmable.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    /* Flash toast ------------------------------------------------------ */
    var toast = document.getElementById('toast');
    function notify(message) {
        if (!toast || !message) { return; }
        toast.textContent = message;
        toast.classList.add('show');
        window.setTimeout(function () { toast.classList.remove('show'); }, 4500);
    }
    var flash = document.querySelector('[data-company-flash]');
    if (flash) { notify(flash.getAttribute('data-company-flash')); }

    /* Mobile sidebar toggle (the prototype aborts before binding it) --- */
    var menuBtn = document.getElementById('menuBtn');
    var sidebar = document.getElementById('sidebar');
    if (menuBtn && sidebar) {
        menuBtn.addEventListener('click', function () { sidebar.classList.toggle('open'); });
    }

    /* Test-route simulation ------------------------------------------- */
    var testPanel = document.getElementById('comm-test');
    if (testPanel) {
        testPanel.addEventListener('submit', function (event) {
            event.preventDefault();
            var selector = testPanel.querySelector('[data-comm-test-schedule]');
            var result = document.getElementById('comm-test-result');
            var option = selector ? selector.options[selector.selectedIndex] : null;
            var url = option ? option.getAttribute('data-url') : '';
            if (!url || !result) { return; }

            fetch(url, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': testPanel.querySelector('input[name="_token"]').value, 'Accept': 'application/json'},
                body: new FormData(testPanel)
            }).then(function (response) {
                return response.json().then(function (payload) { return {ok: response.ok, payload: payload}; });
            }).then(function (result_) {
                var output = '';
                var payload = result_.payload || {};
                if (!result_.ok || payload.ok === false) {
                    output = '<p class="comm-empty">' + esc(payload.message || '') + '</p>';
                } else {
                    if (payload.matched) {
                        output += '<div class="comm-result-head"><strong>' + esc(payload.matched.name_ar || payload.matched.name_en) + '</strong>';
                        output += '<small>' + esc(payload.matched.strategy) + ' · ' + esc(payload.matched.ring_duration) + 's</small></div>';
                    } else {
                        output += '<p class="comm-empty">' + esc((payload.messages || {}).no_match || '') + '</p>';
                    }
                    if (payload.targets && payload.targets.length) {
                        output += '<div class="routing-branches">';
                        payload.targets.forEach(function (target, index) {
                            output += '<div class="routing-branch"><span class="digit">' + (index + 1) + '</span>';
                            output += '<span class="target">' + esc(target.name) + '</span>';
                            output += '<span class="comm-clock">' + esc(target.ring_duration) + 's</span></div>';
                        });
                        output += '</div>';
                    } else if (payload.matched) {
                        output += '<p class="comm-empty">' + esc((payload.messages || {}).none || '') + '</p>';
                    }
                    if (payload.datetime) {
                        output += '<p class="comm-copy">' + esc(payload.datetime) + ' · ' + esc(payload.timezone) + '</p>';
                    }
                }
                result.innerHTML = output;
            }).catch(function () {
                result.innerHTML = '<p class="comm-empty">' + esc(testPanel.getAttribute('data-comm-error') || '') + '</p>';
            });
        });
    }
})();
</script>
@endpush
