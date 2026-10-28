/*
 * PRINT THE REAL PDF (not the web page).
 *
 *   SbcPrint.print(url, button)  or  <button type="button" data-print-pdf="URL">Print</button>
 *
 * The same URL as "Export PDF" is loaded with &inline=1 (the server sends the
 * PDF inline, with the same login, role and branch checks as the export) into
 * a hidden iframe, and the browser's print dialog is opened for that PDF, so
 * the printout is the report layout with all rows, without the browser URL or
 * page controls. While it loads the button shows "Preparing...".
 * When the browser cannot print from an iframe (phones and tablets, some PDF
 * viewers) or the response is not a PDF, the PDF opens in a new tab instead,
 * where the user presses Print.
 */
(function () {
    'use strict';
    if (window.SbcPrint) return;

    var MOBILE = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent || '')
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var frame = null;
    var timer = null;

    function inlineUrl(url) {
        var u = new URL(url, window.location.href);
        u.searchParams.set('inline', '1');
        u.searchParams.delete('download');
        return u.toString();
    }

    function busy(button, on) {
        if (!button) return;
        if (on) {
            if (button.dataset.sbcPrintLabel === undefined) button.dataset.sbcPrintLabel = button.innerHTML;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Preparing…';
        } else if (button.dataset.sbcPrintLabel !== undefined) {
            button.innerHTML = button.dataset.sbcPrintLabel;
            delete button.dataset.sbcPrintLabel;
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    }

    function openTab(src) {
        var w = window.open(src, '_blank');
        if (w) { try { w.opener = null; } catch (e) { /* ignore */ } return; }
        window.location.href = src; // pop-ups blocked: open it here
    }

    function cleanup() {
        if (timer) { clearTimeout(timer); timer = null; }
        if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
        frame = null;
    }

    function print(url, button) {
        var src = inlineUrl(url);
        if (MOBILE) { openTab(src); return; }
        if (button && button.getAttribute('aria-busy') === 'true') return;
        cleanup();
        busy(button, true);
        var done = false;
        var finish = function (fallback) {
            if (done) return;
            done = true;
            busy(button, false);
            if (fallback) { cleanup(); openTab(src); }
            // Keep the iframe until the print dialog has used it; remove it later.
            else setTimeout(function () { if (frame === f) cleanup(); }, 60000);
        };
        var f = document.createElement('iframe');
        frame = f;
        f.setAttribute('aria-hidden', 'true');
        f.tabIndex = -1;
        f.title = 'Print preview';
        f.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none;';
        f.onload = function () {
            setTimeout(function () {
                try {
                    var doc = f.contentDocument;
                    var type = doc && doc.contentType ? String(doc.contentType) : '';
                    // An error page or the login page instead of a PDF: show it in a tab.
                    if (type && type !== 'application/pdf' && type.indexOf('image/') !== 0) { finish(true); return; }
                    f.contentWindow.focus();
                    f.contentWindow.print();
                    finish(false);
                } catch (e) {
                    finish(true); // printing from the iframe is blocked
                }
            }, 400);
        };
        timer = setTimeout(function () { finish(true); }, 30000);
        f.src = src;
        document.body.appendChild(f);
    }

    window.SbcPrint = { print: print, inlineUrl: inlineUrl };

    document.addEventListener('click', function (event) {
        var el = event.target.closest ? event.target.closest('[data-print-pdf]') : null;
        if (!el) return;
        event.preventDefault();
        print(el.getAttribute('data-print-pdf'), el);
    });
})();
