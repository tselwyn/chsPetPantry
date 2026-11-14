/*
 * Two safety nets shown on every page (loaded by header.php).
 *
 * 1. Lost connection (SRS NFR-REL-003). Distribution sites have spotty
 *    internet. When the browser goes offline a banner says so, a form can't be
 *    submitted (which would throw away what was typed), and leaving a page
 *    with a half-filled form asks for confirmation. When the connection comes
 *    back the banner says it's safe to save.
 *
 * 2. Inactivity sign-out (SRS NFR-SEC-005). The server signs a user out after
 *    30 minutes without a request (include/sessionTimeout.php). Two minutes
 *    before that, a warning offers "Stay signed in". Someone who has been
 *    typing or clicking recently is kept signed in automatically, so a long
 *    form isn't lost; an unattended tablet still signs out.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var signedIn = script && script.getAttribute('data-signed-in') === '1';
    var timeoutSeconds = parseInt(script && script.getAttribute('data-timeout-seconds'), 10) || 1800;
    var WARNING_SECONDS = 120;          // warn this long before sign-out
    var RECENT_ACTIVITY_SECONDS = 300;  // typing/clicking this recently counts as active

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function makeBanner(id) {
        var banner = document.createElement('div');
        banner.id = id;
        banner.className = 'pfpms-banner';
        banner.hidden = true;
        document.body.appendChild(banner);
        return banner;
    }

    ready(function () {
        /* ---------- 1. Lost connection ---------- */
        var offlineBanner = makeBanner('pfpms-offline-banner');
        offlineBanner.setAttribute('role', 'alert');
        var dirtyForms = new Set();
        var hideTimer = null;

        document.addEventListener('input', function (event) {
            if (event.target && event.target.form) dirtyForms.add(event.target.form);
        });
        document.addEventListener('change', function (event) {
            if (event.target && event.target.form) dirtyForms.add(event.target.form);
        });

        function showOffline() {
            clearTimeout(hideTimer);
            offlineBanner.className = 'pfpms-banner pfpms-banner-offline';
            offlineBanner.innerHTML = '<strong>&#9888; No internet connection.</strong> ' +
                'What you typed is still on this page. Don\'t refresh or leave it. ' +
                'Save once the connection is back.';
            offlineBanner.hidden = false;
        }

        function showOnline() {
            if (offlineBanner.hidden) return;
            offlineBanner.className = 'pfpms-banner pfpms-banner-online';
            offlineBanner.innerHTML = '<strong>&#10003; Connection restored.</strong> You can save now.';
            hideTimer = setTimeout(function () { offlineBanner.hidden = true; }, 6000);
        }

        window.addEventListener('offline', showOffline);
        window.addEventListener('online', showOnline);
        if (navigator.onLine === false) showOffline();

        // Submitting while offline would replace the page with a browser error
        // and lose the entries, so keep the user on the page instead
        document.addEventListener('submit', function (event) {
            if (navigator.onLine === false) {
                event.preventDefault();
                event.stopImmediatePropagation();
                showOffline();
                offlineBanner.setAttribute('tabindex', '-1');
                offlineBanner.focus();
                return;
            }
            dirtyForms.delete(event.target);
        }, true);

        window.addEventListener('beforeunload', function (event) {
            if (navigator.onLine === false && dirtyForms.size > 0) {
                event.preventDefault();
                event.returnValue = '';
                return '';
            }
        });

        /* ---------- 2. Inactivity sign-out ---------- */
        if (!signedIn) return;

        var timeoutBanner = makeBanner('pfpms-timeout-banner');
        timeoutBanner.setAttribute('role', 'alertdialog');
        timeoutBanner.setAttribute('aria-live', 'assertive');
        timeoutBanner.setAttribute('aria-label', 'Inactivity warning');
        timeoutBanner.className = 'pfpms-banner pfpms-banner-timeout';

        var lastInteraction = Date.now();
        var expiresAt = Date.now() + timeoutSeconds * 1000;
        var countdown = null;
        var keepAliveInFlight = false;

        ['keydown', 'pointerdown', 'touchstart', 'input'].forEach(function (type) {
            document.addEventListener(type, function () { lastInteraction = Date.now(); }, { passive: true, capture: true });
        });

        function signOut() {
            window.location.href = 'login.php?timeout=1';
        }

        function keepAlive() {
            if (navigator.onLine === false || keepAliveInFlight) return;
            keepAliveInFlight = true;
            fetch('sessionKeepAlive.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (response.status === 401) { signOut(); return null; }
                return response.json();
            }).then(function (data) {
                if (!data) return;
                expiresAt = Date.now() + (data.secondsLeft || timeoutSeconds) * 1000;
                timeoutBanner.hidden = true;
                clearInterval(countdown);
                countdown = null;
            }).catch(function () {
                // Network trouble: leave the warning up; the offline banner explains
            }).then(function () {
                keepAliveInFlight = false;
            });
        }

        function formatLeft(ms) {
            var total = Math.max(0, Math.ceil(ms / 1000));
            var m = Math.floor(total / 60);
            var s = total % 60;
            return m + ':' + (s < 10 ? '0' : '') + s;
        }

        function showWarning() {
            timeoutBanner.innerHTML = '<strong>&#9201; Still there?</strong> ' +
                'For privacy you\'ll be signed out in <span id="pfpms-timeout-left">' +
                formatLeft(expiresAt - Date.now()) + '</span>. ' +
                '<button type="button" id="pfpms-stay-signed-in">Stay signed in</button>';
            timeoutBanner.hidden = false;
            document.getElementById('pfpms-stay-signed-in').addEventListener('click', keepAlive);
            countdown = setInterval(function () {
                var left = document.getElementById('pfpms-timeout-left');
                if (left) left.textContent = formatLeft(expiresAt - Date.now());
            }, 1000);
        }

        setInterval(function () {
            var now = Date.now();
            var msLeft = expiresAt - now;
            // A few seconds' grace so the server has expired the session too
            if (msLeft <= -5000) { signOut(); return; }
            if (msLeft <= WARNING_SECONDS * 1000 && timeoutBanner.hidden) {
                if (now - lastInteraction < RECENT_ACTIVITY_SECONDS * 1000) {
                    keepAlive();       // they're working on this page; don't interrupt
                } else {
                    showWarning();
                }
            }
        }, 5000);
    });
})();
