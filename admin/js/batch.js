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

    async function clearBreadcrumbCache() {
        const btn = document.getElementById('gt-clear-breadcrumb-cache');
        if (!confirm(
            'This deletes the cached breadcrumb HTML and JSON-LD for ALL posts and tag archives, '
            + 'including any manual link edits made directly in postmeta/termmeta.\n\n'
            + 'Posts regenerate the next time they are saved or batch-processed; tag archives '
            + 'regenerate the next time their page is viewed.\n\n'
            + 'Continue?'
        )) {
            return;
        }
        btn.disabled = true;
        try {
            const result = await post('geo_tagger_clear_breadcrumb_cache', {});
            alert('Breadcrumb cache cleared. Deleted ' + result.deleted + ' meta row(s).');
        } catch (err) {
            alert('Error: ' + err.message);
        } finally {
            btn.disabled = false;
        }
    }

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async function processSinglePost(force) {
        const input     = document.getElementById('gt-single-post-id');
        const btn       = document.getElementById('gt-single-post-btn');
        const forceBtn  = document.getElementById('gt-single-force-btn');
        const result    = document.getElementById('gt-single-result');
        const postId    = input.value.trim();

        if (!postId) { input.focus(); return; }

        btn.disabled         = true;
        forceBtn.disabled    = true;
        result.style.display = 'block';
        result.innerHTML     = force ? 'Clearing cache and reprocessing…' : 'Processing…';

        try {
            const payload = { post_id: postId };
            if (force) payload.force = 1;
            const d = await post('geo_tagger_process_single', payload);

            let html = '<strong>[' + d.post_id + '] ' + esc(d.title) + '</strong>';
            if (d.lang) {
                html += ' &nbsp;<em style="color:#666">lang: ' + esc(d.lang) + '</em>';
            }
            if (d.location) {
                html += '<br>Location: ' + esc(d.location.lat) + ', ' + esc(d.location.lng);
                if (d.location.city) html += ' &mdash; ' + esc(d.location.city);
            }
            if (d.note) {
                html += '<br><span style="color:#b45309">⚠ ' + esc(d.note) + '</span>';
            }
            if (d.added && d.added.length) {
                html += '<br><span style="color:#166534">＋ Added: ' + esc(d.added.join(', ')) + '</span>';
            }
            if (d.skipped && d.skipped.length) {
                html += '<br><span style="color:#555">～ Already tagged: ' + esc(d.skipped.join(', ')) + '</span>';
            }
            if (d.errors && d.errors.length) {
                html += '<br><span style="color:#991b1b">✗ Errors: ' + esc(d.errors.join(', ')) + '</span>';
            }
            if (!d.note && !d.added?.length && !d.skipped?.length && !d.errors?.length) {
                html += '<br><span style="color:#b45309">No tags were processed.</span>';
            }

            result.innerHTML = html;
        } catch (err) {
            result.innerHTML = '<span style="color:#991b1b">✗ ' + esc(err.message) + '</span>';
        } finally {
            btn.disabled      = false;
            forceBtn.disabled = false;
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
        document.getElementById('gt-run-batch')       ?.addEventListener('click', runBatch);
        document.getElementById('gt-clear-cache')     ?.addEventListener('click', clearCache);
        document.getElementById('gt-clear-breadcrumb-cache') ?.addEventListener('click', clearBreadcrumbCache);
        document.getElementById('gt-test-nominatim')  ?.addEventListener('click', testNominatim);
        document.getElementById('gt-single-post-btn')  ?.addEventListener('click', () => processSinglePost(false));
        document.getElementById('gt-single-force-btn') ?.addEventListener('click', () => processSinglePost(true));
        document.getElementById('gt-single-post-id')   ?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') processSinglePost(false);
        });
    });
}());
