/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth Error Monitor — storefront JS error collector.
 *
 * Plain (non-AMD) script loaded with `defer` and configured from an inert
 * JSON <script> block, so it runs under a strict Content-Security-Policy.
 *
 * Client-side safeguards (the server enforces its own, independently):
 *   - sampling: only a configured % of page loads report at all;
 *   - per-page cap on the number of reports;
 *   - per-page dedupe so a looping error is sent once;
 *   - payload field caps so we never post huge blobs;
 *   - fire-and-forget via navigator.sendBeacon (falls back to fetch keepalive).
 */
(function () {
    'use strict';

    var configEl = document.getElementById('panth-em-config');
    if (!configEl) {
        return;
    }

    var config;
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (e) {
        return;
    }
    if (!config.url) {
        return;
    }

    var sampleRate = typeof config.sampleRate === 'number' ? config.sampleRate : 100;
    if (sampleRate <= 0 || Math.random() * 100 >= sampleRate) {
        return; // not sampled this page load
    }

    var maxPerPage = typeof config.maxPerPage === 'number' ? config.maxPerPage : 10;
    var sent = 0;
    var seen = Object.create(null);

    function cap(value, max) {
        if (typeof value !== 'string') {
            return '';
        }
        return value.length > max ? value.slice(0, max) : value;
    }

    function send(payload) {
        if (sent >= maxPerPage) {
            return;
        }
        var signature = (payload.name || '') + '|' + (payload.message || '') + '|' +
            (payload.source || '') + '|' + (payload.lineno || '');
        if (seen[signature]) {
            return;
        }
        seen[signature] = true;
        sent++;

        var body = JSON.stringify(payload);

        try {
            // text/plain keeps sendBeacon CORS-safelisted (no preflight); the
            // server parses JSON from the raw body regardless of content type.
            if (navigator.sendBeacon) {
                navigator.sendBeacon(config.url, body);
                return;
            }
        } catch (e) { /* fall through to fetch */ }

        try {
            fetch(config.url, {
                method: 'POST',
                body: body,
                keepalive: true,
                credentials: 'omit',
                headers: { 'Content-Type': 'text/plain' }
            }).catch(function () {});
        } catch (e) { /* give up silently */ }
    }

    window.addEventListener('error', function (event) {
        // Ignore resource-load errors (img/script 404s) — they have no message.
        if (!event || (!event.message && !event.error)) {
            return;
        }
        var err = event.error || {};
        send({
            kind: 'error',
            name: cap(err.name || 'Error', 191),
            message: cap(event.message || (err && err.message) || 'Unknown error', 2000),
            source: cap(event.filename || '', 1024),
            lineno: event.lineno || 0,
            colno: event.colno || 0,
            stack: cap((err && err.stack) || '', 8000),
            pageUrl: cap(location.href, 2048)
        });
    }, true);

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event ? event.reason : null;
        var message = 'Unhandled promise rejection';
        var stack = '';
        var name = 'UnhandledRejection';
        if (reason && typeof reason === 'object') {
            message = reason.message || message;
            stack = reason.stack || '';
            name = reason.name || name;
        } else if (typeof reason === 'string') {
            message = reason;
        }
        send({
            kind: 'unhandledrejection',
            name: cap(name, 191),
            message: cap(message, 2000),
            source: cap(location.pathname || '', 1024),
            lineno: 0,
            colno: 0,
            stack: cap(stack, 8000),
            pageUrl: cap(location.href, 2048)
        });
    });
})();
