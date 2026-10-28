/*
 * SHARED STYLED DIALOGS (replaces the browser's confirm(), alert() and prompt()).
 *
 *   SbcDialog.confirm({title, message, confirmText, cancelText, variant, details})
 *       -> Promise<boolean>        (true only when Confirm is clicked)
 *   SbcDialog.confirmRemark({..., remark: {label, placeholder, required, minLength}})
 *       -> Promise<string|null>    (the remark, or null when cancelled)
 *   SbcDialog.alert(message, {title, variant}) -> Promise<void>
 *
 * variant: 'primary' (default), 'danger' (reject, delete, cancel, return) or
 * 'warning'. details: optional table {columns: [...], rows: [[...], ...]}.
 * All text is inserted as text, never as HTML.
 *
 * Declarative use (no page script needed):
 *   <form data-confirm="Cancel this visit?" data-confirm-title="Cancel visit"
 *         data-confirm-variant="danger" data-confirm-ok="Yes, cancel">
 *   or the same data-confirm-* attributes on a submit button.
 * The form is submitted only after Confirm. Esc, Cancel or a click outside
 * does nothing. A confirmed form can be submitted only once (its submit
 * buttons are disabled), which keeps the double-submit protection.
 */
(function () {
    'use strict';
    if (window.SbcDialog) return;

    var ICONS = { primary: 'bi-question-circle', danger: 'bi-exclamation-octagon', warning: 'bi-exclamation-triangle', info: 'bi-info-circle', success: 'bi-check-circle' };
    var css = '' +
        '.sbc-dialog{z-index:1070}' +
        '.sbc-dialog .modal-dialog{max-width:520px;width:calc(100% - 30px)}' +
        '.sbc-dialog.sbc-wide .modal-dialog{max-width:680px}' +
        '.sbc-dialog .modal-content{overflow:hidden;border:0;border-radius:20px;background:#fff;box-shadow:0 20px 55px rgba(31,45,110,.2)}' +
        '.sbc-dialog .modal-header{display:block;padding:26px 24px 6px;border:0;background:#fff;text-align:center}' +
        '.sbc-dialog .sbc-dialog-icon{width:64px;height:64px;display:inline-flex;align-items:center;justify-content:center;margin:0 auto 14px;color:#fff;border-radius:50%;font-size:27px;background:linear-gradient(135deg,#2B3A8C,#4a5bb8)}' +
        '.sbc-dialog.sbc-danger .sbc-dialog-icon{background:linear-gradient(135deg,#ef3340,#f05b68)}' +
        '.sbc-dialog.sbc-warning .sbc-dialog-icon{background:linear-gradient(135deg,#f0a500,#f7c548)}' +
        '.sbc-dialog.sbc-success .sbc-dialog-icon{background:linear-gradient(135deg,#1f9d55,#38c172)}' +
        '.sbc-dialog .modal-title{margin:0;color:#283a7a;font-size:22px;font-weight:700;line-height:1.3}' +
        '.sbc-dialog .modal-body{padding:6px 28px 18px;text-align:center;color:#5f6b85;font-size:14.5px}' +
        '.sbc-dialog .sbc-dialog-message{margin:0;white-space:pre-line}' +
        '.sbc-dialog .sbc-dialog-details{margin-top:14px;text-align:left;max-height:240px;overflow:auto;border:1px solid #e6eaf2;border-radius:10px}' +
        '.sbc-dialog .sbc-dialog-details table{width:100%;margin:0;font-size:12.5px;border-collapse:collapse}' +
        '.sbc-dialog .sbc-dialog-details th{position:sticky;top:0;background:#2B3A8C;color:#fff;font-weight:600;padding:6px 9px}' +
        '.sbc-dialog .sbc-dialog-details td{padding:6px 9px;border-top:1px solid #eef1f6;color:#344054}' +
        '.sbc-dialog .sbc-dialog-remark{margin-top:14px;text-align:left}' +
        '.sbc-dialog .sbc-dialog-remark label{display:block;margin-bottom:5px;color:#344054;font-size:13px;font-weight:600}' +
        '.sbc-dialog .sbc-dialog-remark textarea{width:100%;min-height:76px;border-radius:10px}' +
        '.sbc-dialog .modal-footer{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:0 24px 24px;border:0}' +
        '.sbc-dialog.sbc-single .modal-footer{grid-template-columns:1fr}' +
        '.sbc-dialog .modal-footer .btn{min-height:44px;margin:0;border-radius:10px;font-weight:600}' +
        '.sbc-dialog .sbc-ok-primary{color:#fff;background:#2B3A8C;border-color:#2B3A8C}.sbc-dialog .sbc-ok-primary:hover{color:#fff;background:#202d70;border-color:#202d70}' +
        '.sbc-dialog-fallback{display:block;background:rgba(15,23,42,.45)}';

    var el = null, modal = null, state = null;

    function build() {
        if (el) return;
        var style = document.createElement('style');
        style.textContent = css;
        document.head.appendChild(style);
        el = document.createElement('div');
        el.className = 'modal fade sbc-dialog';
        el.id = 'sbcDialog';
        el.tabIndex = -1;
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-labelledby', 'sbcDialogTitle');
        el.setAttribute('aria-describedby', 'sbcDialogMessage');
        el.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
            '<div class="modal-header"><div class="sbc-dialog-icon"><i class="bi"></i></div><h2 class="modal-title" id="sbcDialogTitle"></h2></div>' +
            '<div class="modal-body"><p class="sbc-dialog-message" id="sbcDialogMessage"></p><div class="sbc-dialog-details d-none"></div>' +
            '<div class="sbc-dialog-remark d-none"><label for="sbcDialogRemark"></label><textarea class="form-control" id="sbcDialogRemark" maxlength="500"></textarea>' +
            '<div class="invalid-feedback"></div></div></div>' +
            '<div class="modal-footer"><button type="button" class="btn btn-light border sbc-cancel"></button><button type="button" class="btn sbc-ok"></button></div>' +
            '</div></div>';
        document.body.appendChild(el);
        el.querySelector('.sbc-ok').addEventListener('click', onOk);
        el.querySelector('.sbc-cancel').addEventListener('click', function () { hide(); });
        if (window.bootstrap && window.bootstrap.Modal) {
            modal = new window.bootstrap.Modal(el, { backdrop: true, keyboard: true });
            el.addEventListener('hidden.bs.modal', settle);
            el.addEventListener('shown.bs.modal', function () {
                // Esc pressed during the fade-in (Bootstrap ignores it then): close now.
                if (state && state.cancelRequested) { modal.hide(); return; }
                focusFirst();
            });
        } else {
            el.addEventListener('click', function (e) { if (e.target === el) hide(); });
        }
        // Esc always cancels, even before the dialog has finished opening.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !state || !el.classList.contains('show')) return;
            e.preventDefault();
            state.result = state.single ? undefined : (state.remark ? null : false);
            state.cancelRequested = true;
            hide();
        }, true);
    }

    function focusFirst() {
        if (!state) return;
        var target = !state.remark ? null : el.querySelector('#sbcDialogRemark');
        if (!target) target = el.querySelector(state.variant === 'danger' && !state.single ? '.sbc-cancel' : '.sbc-ok');
        if (target) target.focus();
    }

    function show() {
        if (modal) { modal.show(); return; }
        el.classList.add('show', 'sbc-dialog-fallback');
        el.style.display = 'block';
        focusFirst();
    }

    function hide() {
        if (modal) { modal.hide(); return; }
        el.classList.remove('show', 'sbc-dialog-fallback');
        el.style.display = 'none';
        settle();
    }

    function settle() {
        if (!state) return;
        var s = state;
        state = null;
        s.resolve(s.result);
        if (s.returnFocus && typeof s.returnFocus.focus === 'function') {
            try { s.returnFocus.focus(); } catch (e) { /* ignore */ }
        }
    }

    function onOk() {
        if (!state) return;
        if (state.remark) {
            var box = el.querySelector('#sbcDialogRemark');
            var value = box.value.trim();
            var min = state.remark.required ? Math.max(1, state.remark.minLength || 1) : 0;
            if (value.length < min) {
                box.classList.add('is-invalid');
                el.querySelector('.sbc-dialog-remark .invalid-feedback').textContent =
                    state.remark.requiredMessage || ('Enter a remark' + (min > 1 ? ' of at least ' + min + ' characters' : '') + '.');
                box.focus();
                return;
            }
            state.result = value;
        } else {
            state.result = state.single ? undefined : true;
        }
        hide();
    }

    function open(opts, resolve) {
        build();
        if (state) { var old = state; state = null; old.resolve(old.single ? undefined : (old.remark ? null : false)); }
        var variant = ['danger', 'warning', 'success', 'info'].indexOf(opts.variant) >= 0 ? opts.variant : 'primary';
        state = { resolve: resolve, variant: variant, remark: opts.remark || null, single: !!opts.single,
            result: opts.single ? undefined : (opts.remark ? null : false), returnFocus: document.activeElement };
        el.className = 'modal fade sbc-dialog sbc-' + variant + (opts.single ? ' sbc-single' : '') + (opts.details ? ' sbc-wide' : '');
        el.querySelector('.sbc-dialog-icon i').className = 'bi ' + (opts.icon || ICONS[variant]);
        el.querySelector('.modal-title').textContent = opts.title || (opts.single ? 'Notice' : 'Please confirm');
        el.querySelector('.sbc-dialog-message').textContent = opts.message || '';
        var details = el.querySelector('.sbc-dialog-details');
        details.textContent = '';
        details.classList.toggle('d-none', !opts.details);
        if (opts.details) {
            var table = document.createElement('table');
            var head = table.createTHead().insertRow();
            (opts.details.columns || []).forEach(function (c) { var th = document.createElement('th'); th.textContent = c; head.appendChild(th); });
            var body = table.createTBody();
            (opts.details.rows || []).forEach(function (r) { var tr = body.insertRow(); r.forEach(function (c) { tr.insertCell().textContent = c == null ? '' : String(c); }); });
            details.appendChild(table);
        }
        var remark = el.querySelector('.sbc-dialog-remark');
        remark.classList.toggle('d-none', !opts.remark);
        if (opts.remark) {
            remark.querySelector('label').textContent = opts.remark.label || (opts.remark.required ? 'Remark (required)' : 'Remark (optional)');
            var box = remark.querySelector('textarea');
            box.value = opts.remark.value || '';
            box.placeholder = opts.remark.placeholder || '';
            box.classList.remove('is-invalid');
        }
        var ok = el.querySelector('.sbc-ok');
        ok.textContent = opts.confirmText || (opts.single ? 'OK' : 'Confirm');
        ok.className = 'btn sbc-ok ' + (variant === 'danger' ? 'btn-danger' : variant === 'warning' ? 'btn-warning' : variant === 'success' ? 'btn-success' : 'sbc-ok-primary');
        var cancel = el.querySelector('.sbc-cancel');
        cancel.textContent = opts.cancelText || 'Cancel';
        cancel.classList.toggle('d-none', !!opts.single);
        show();
    }

    function asOptions(opts) {
        return typeof opts === 'string' ? { message: opts } : (opts || {});
    }

    window.SbcDialog = {
        confirm: function (opts) {
            opts = asOptions(opts);
            delete opts.remark;
            return new Promise(function (resolve) { open(opts, resolve); });
        },
        confirmRemark: function (opts) {
            opts = asOptions(opts);
            opts.remark = opts.remark || { required: true };
            return new Promise(function (resolve) { open(opts, resolve); });
        },
        alert: function (message, opts) {
            opts = Object.assign({ variant: 'warning', title: 'Please check' }, asOptions(opts), { message: message, single: true });
            delete opts.remark;
            return new Promise(function (resolve) { open(opts, resolve); });
        }
    };

    // Declarative data-confirm on forms and submit buttons. Runs after the
    // page's own submit checks (bubble phase on document): a submit they
    // already stopped is left alone.
    function lockForm(form) {
        form.dataset.sbcBusy = '1';
        setTimeout(function () {
            form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])').forEach(function (b) { b.disabled = true; });
        }, 0);
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        var submitter = event.submitter || null;
        var source = submitter && submitter.hasAttribute('data-confirm') ? submitter : (form.hasAttribute('data-confirm') ? form : null);
        if (!source) return;
        if (event.defaultPrevented) return;
        if (form.dataset.sbcBusy === '1') { event.preventDefault(); return; }
        if (form.dataset.sbcConfirmed === '1') {
            delete form.dataset.sbcConfirmed;
            lockForm(form);
            return;
        }
        event.preventDefault();
        SbcDialog.confirm({
            title: source.getAttribute('data-confirm-title') || 'Please confirm',
            message: source.getAttribute('data-confirm'),
            confirmText: source.getAttribute('data-confirm-ok') || 'Confirm',
            cancelText: source.getAttribute('data-confirm-cancel') || 'Cancel',
            variant: source.getAttribute('data-confirm-variant') || 'primary'
        }).then(function (ok) {
            if (!ok) return;
            form.dataset.sbcConfirmed = '1';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            } else {
                if (submitter && submitter.name) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden'; hidden.name = submitter.name; hidden.value = submitter.value;
                    form.appendChild(hidden);
                }
                delete form.dataset.sbcConfirmed;
                lockForm(form);
                form.submit();
            }
        });
    });

    // Back/forward cache: re-enable forms locked before the page was left.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-sbc-busy="1"]').forEach(function (form) {
            delete form.dataset.sbcBusy;
            form.querySelectorAll('button, input[type="submit"]').forEach(function (b) { b.disabled = false; });
        });
    });
})();
