/* global geoTagger */
(function () {
    'use strict';

    const { ajaxUrl, nonce } = geoTagger;

    async function post(action, data) {
        const body = new URLSearchParams({ action, nonce, ...data });
        const res  = await fetch(ajaxUrl, { method: 'POST', body });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        if (!json.success) throw new Error(json.data?.message || 'Request failed');
        return json.data;
    }

    function setProgress(done, total) {
        const pct  = total > 0 ? Math.round((done / total) * 100) : 0;
        document.getElementById('gt-progress-bar').style.width = pct + '%';
        document.getElementById('gt-progress-text').textContent =
            done + ' / ' + total + ' posts processed (' + pct + '%)';
    }

    function appendLog(entries) {
        const log = document.getElementById('gt-log');
        entries.forEach(function (entry) {
            const added   = entry.added.length   ? '  + ' + entry.added.join(', ')   : '';
            const skipped = entry.skipped.length  ? '  ~ ' + entry.skipped.join(', ') : '';
            const errors  = entry.errors.length   ? '  ! ' + entry.errors.join(', ')  : '';
            const title   = '[' + entry.post_id + '] ' + entry.title;
            log.textContent += title + '\n' + added + (added ? '\n' : '') +
                               skipped + (skipped ? '\n' : '') +
                               errors  + (errors  ? '\n' : '') + '\n';
            log.scrollTop = log.scrollHeight;
        });
    }

    function showDone() {
        const text = document.getElementById('gt-progress-text');
        text.textContent += ' — Done!';
        document.getElementById('gt-run-batch').disabled = false;
    }

    async function runBatch() {
        const btn     = document.getElementById('gt-run-batch');
        const progress = document.getElementById('gt-progress');
        const log      = document.getElementById('gt-log');

        btn.disabled        = true;
        progress.style.display = 'block';
        log.style.display      = 'block';
        log.textContent        = '';

        let offset = 0;
        let total  = 0;

        try {
            const count = await post('geo_tagger_batch_count', {});
            total = count.total;
            setProgress(0, total);

            while (offset < total) {
                const result = await post('geo_tagger_batch_run', { offset: offset });
                offset += result.processed;
                setProgress(offset, total);
                appendLog(result.log);
                if (result.done) break;
            }

            showDone();
        } catch (err) {
            log.textContent += '\nError: ' + err.message + '\n';
            btn.disabled = false;
        }
    }

    async function clearCache() {
        const btn = document.getElementById('gt-clear-cache');
        btn.disabled = true;
        try {
            const result = await post('geo_tagger_clear_cache', {});
            alert('Cache cleared. Deleted ' + result.deleted + ' transient(s).');
        } catch (err) {
            alert('Error: ' + err.message);
        } finally {
            btn.disabled = false;
        }
    }

    async function testNominatim() {
        const btn    = document.getElementById('gt-test-nominatim');
        const result = document.getElementById('gt-nominatim-result');
        btn.disabled = true;
        result.textContent = 'Testing…';
        try {
            const data = await post('geo_tagger_test_nominatim', {});
            result.textContent = '✓ ' + data.message;
            result.style.color = 'green';
        } catch (err) {
            result.textContent = '✗ ' + err.message;
            result.style.color = 'red';
        } finally {
            btn.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('gt-run-batch')      ?.addEventListener('click', runBatch);
        document.getElementById('gt-clear-cache')    ?.addEventListener('click', clearCache);
        document.getElementById('gt-test-nominatim') ?.addEventListener('click', testNominatim);
    });
}());
