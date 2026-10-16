<script>
(function () {
    'use strict';

    var dialog = document.getElementById('adminDialog');
    var form = document.getElementById('adminDialogForm');

    function fillValues(values) {
        if (!form || !values) { return; }
        Object.keys(values).forEach(function (name) {
            var el = form.querySelector('[name="' + name + '"]');
            if (!el) { return; }
            if (el.type === 'checkbox') { el.checked = !!values[name]; return; }
            el.value = (values[name] === null || values[name] === undefined) ? '' : values[name];
        });
    }

    function openDialog(config) {
        if (!dialog || !form) { return; }
        var title = document.getElementById('adminDialogTitle');
        var method = document.getElementById('adminDialogMethod');
        var body = document.getElementById('adminDialogBody');

        if (title) { title.textContent = config.title || ''; }
        form.setAttribute('action', config.action || '');
        if (method) { method.value = config.method || 'POST'; }

        var tpl = config.template ? document.querySelector(config.template) : null;
        body.innerHTML = tpl ? tpl.innerHTML : '';
        fillValues(config.values);

        if (typeof dialog.showModal === 'function') { dialog.showModal(); }
    }

    document.addEventListener('click', function (event) {
        var create = event.target.closest('[data-admin-create]');
        if (create) {
            event.preventDefault();
            var createValues = {};
            try { createValues = JSON.parse(create.dataset.adminValues || '{}'); } catch (e) { createValues = {}; }
            openDialog({
                title: create.dataset.adminTitle,
                action: create.dataset.adminAction,
                method: create.dataset.adminMethod || 'POST',
                template: create.dataset.adminTemplate,
                values: createValues
            });
            return;
        }

        var edit = event.target.closest('[data-admin-edit]');
        if (edit) {
            event.preventDefault();
            var values = {};
            try { values = JSON.parse(edit.dataset.adminValues || '{}'); } catch (e) { values = {}; }
            openDialog({
                title: edit.dataset.adminTitle,
                action: edit.dataset.adminAction,
                method: edit.dataset.adminMethod || 'PUT',
                template: edit.dataset.adminTemplate,
                values: values
            });
            return;
        }

        if (event.target.closest('#adminDialogClose') || event.target.closest('#adminDialogCancel')) {
            event.preventDefault();
            if (dialog) { dialog.close(); }
            return;
        }

        if (event.target.closest('#menuBtn')) {
            var sidebar = document.getElementById('sidebar');
            if (sidebar) { sidebar.classList.toggle('open'); }
        }
    });

    document.addEventListener('submit', function (event) {
        var guarded = event.target.closest('form[data-admin-confirm]');
        if (guarded && !window.confirm(guarded.dataset.adminConfirm)) {
            event.preventDefault();
        }
    });
})();
</script>
