/* global geoTaggerDup */
(function () {
    'use strict';

    const { ajaxUrl, nonce } = geoTaggerDup;

    async function post(action, data) {
        const body = new URLSearchParams({ action, nonce, ...data });
        const res  = await fetch(ajaxUrl, { method: 'POST', body });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        if (!json.success) throw new Error(json.data?.message || 'Request failed');
        return json.data;
    }

    function esc(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // -----------------------------------------------------------------------
    // Section 1 — duplicate list
    // -----------------------------------------------------------------------

    async function loadList() {
        const loadingEl   = document.getElementById('gtd-loading');
        const listSection = document.getElementById('gtd-list-section');
        const listEl      = document.getElementById('gtd-list');

        loadingEl.style.display = 'block';
        listSection.style.display = 'none';

        try {
            const data   = await post('geo_tagger_dup_list', {});
            const groups = data.groups;

            loadingEl.style.display = 'none';

            if (!groups.length) {
                listEl.innerHTML = '<p style="color:#166534">&#10003; No duplicate tags found.</p>';
                listSection.style.display = 'block';
                return;
            }

            const byLang = {};
            groups.forEach(g => {
                if (!byLang[g.lang]) byLang[g.lang] = [];
                byLang[g.lang].push(g);
            });

            let html = '';
            for (const lang of ['fr', 'en', 'de']) {
                const items = byLang[lang];
                if (!items || !items.length) continue;
                html += '<h3 style="margin-bottom:8px">' + esc(lang.toUpperCase()) + '</h3>';
                html += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px">';
                items.forEach(g => {
                    html += '<button class="button gtd-group-btn"'
                          + ' data-term-ids="' + esc(g.term_ids.join(',')) + '"'
                          + ' data-name="'     + esc(g.name) + '"'
                          + ' data-lang="'     + esc(g.lang) + '">'
                          + esc(g.name) + ' <sup style="font-size:10px">&times;' + g.count + '</sup>'
                          + '</button>';
                });
                html += '</div>';
            }

            listEl.innerHTML = html;
            listSection.style.display = 'block';

            listEl.querySelectorAll('.gtd-group-btn').forEach(btn => {
                btn.addEventListener('click', () => loadDetails(btn));
            });

        } catch (err) {
            loadingEl.textContent  = 'Error loading duplicates: ' + err.message;
            loadingEl.style.color  = 'red';
        }
    }

    // -----------------------------------------------------------------------
    // Section 2 — term details
    // -----------------------------------------------------------------------

    async function loadDetails(btn) {
        const termIds    = btn.dataset.termIds;
        const name       = btn.dataset.name;
        const lang       = btn.dataset.lang;
        const detailSec  = document.getElementById('gtd-detail-section');
        const detailTitle = document.getElementById('gtd-detail-title');
        const detailEl   = document.getElementById('gtd-detail');
        const mergeSec   = document.getElementById('gtd-merge-section');
        const mergeForm  = document.getElementById('gtd-merge-form');

        document.querySelectorAll('.gtd-group-btn').forEach(b => b.classList.remove('button-primary'));
        btn.classList.add('button-primary');

        detailTitle.textContent = '“' + name + '” (' + lang + ')';
        detailEl.innerHTML      = 'Loading…';
        mergeSec.style.display  = 'none';
        detailSec.style.display = 'block';
        detailSec.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        try {
            const data  = await post('geo_tagger_dup_details', { term_ids: termIds });
            const terms = data.terms;

            let html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px">';
            terms.forEach(t => { html += renderTermCard(t); });
            html += '</div>';
            detailEl.innerHTML = html;

            renderMergeForm(mergeForm, terms);
            mergeSec.style.display = 'block';

        } catch (err) {
            detailEl.innerHTML = '<span style="color:red">Error: ' + esc(err.message) + '</span>';
        }
    }

    function renderTermCard(t) {
        const box = 'background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px;font-size:13px';
        let html  = '<div style="' + box + '">';
        html += '<strong style="font-size:14px">term_id: ' + esc(t.term_id) + '</strong>';
        html += '<table style="width:100%;border-collapse:collapse;margin-top:8px">';

        function row(k, v) {
            return '<tr>'
                 + '<td style="color:#888;white-space:nowrap;padding:2px 6px 2px 0;vertical-align:top">' + esc(k) + '</td>'
                 + '<td style="padding:2px 0;word-break:break-all"><code>' + esc(String(v)) + '</code></td>'
                 + '</tr>';
        }

        html += row('name',             t.name);
        html += row('slug',             t.slug);
        html += row('term_group',       t.term_group);
        html += row('term_taxonomy_id', t.term_taxonomy_id);
        html += row('post count',       t.count);
        html += row('pll_lang',         t.pll_lang || '—');

        if (t.meta && t.meta.length) {
            html += '<tr><td colspan="2" style="padding-top:10px;color:#888;font-size:11px;text-transform:uppercase;letter-spacing:.04em">term meta</td></tr>';
            t.meta.forEach(m => { html += row(m.meta_key, m.meta_value); });
        }

        if (t.translations && Object.keys(t.translations).length) {
            html += '<tr><td colspan="2" style="padding-top:10px;color:#888;font-size:11px;text-transform:uppercase;letter-spacing:.04em">pll translations</td></tr>';
            Object.entries(t.translations).forEach(([lang, tid]) => { html += row(lang, tid); });
        }

        if (t.places && t.places.length) {
            html += '<tr><td colspan="2" style="padding-top:10px;color:#888;font-size:11px;text-transform:uppercase;letter-spacing:.04em">geo_tagger_places</td></tr>';
            t.places.forEach(p => {
                html += row(
                    'place id=' + p.id,
                    'level=' + p.level
                    + ' fr=' + (p.term_id_fr || '—')
                    + ' en=' + (p.term_id_en || '—')
                    + ' de=' + (p.term_id_de || '—')
                    + (p.country_code ? ' cc=' + p.country_code : '')
                );
            });
        }

        html += '</table></div>';
        return html;
    }

    // -----------------------------------------------------------------------
    // Section 3 — merge form
    // -----------------------------------------------------------------------

    function renderMergeForm(container, terms) {
        const mergeResult = document.getElementById('gtd-merge-result');
        mergeResult.innerHTML = '';

        let html = '';
        terms.forEach((t, i) => {
            html += '<label style="display:block;margin:6px 0;cursor:pointer">'
                  + '<input type="radio" name="gtd_keep_id" value="' + esc(t.term_id) + '"'
                  + (i === 0 ? ' checked' : '') + '> '
                  + 'Keep <strong>' + esc(t.slug) + '</strong>'
                  + ' &nbsp;<span style="color:#888">(id&nbsp;' + esc(t.term_id) + ', ' + esc(t.count) + ' post(s))</span>'
                  + '</label>';
        });
        html += '<button id="gtd-do-merge" class="button button-primary" style="margin-top:14px">'
              + 'Merge &mdash; discard the other term'
              + '</button>';

        container.innerHTML = html;

        document.getElementById('gtd-do-merge').addEventListener('click', async () => {
            const checked = container.querySelector('input[name="gtd_keep_id"]:checked');
            if (!checked) return;

            const keepId   = parseInt(checked.value, 10);
            const dropTerm = terms.find(t => t.term_id !== keepId);
            if (!dropTerm) return;
            const dropId   = dropTerm.term_id;

            const keepSlug = terms.find(t => t.term_id === keepId)?.slug || keepId;
            const dropSlug = dropTerm.slug || dropId;

            if (!confirm(
                'Keep “' + keepSlug + '” (id ' + keepId + ')\n'
                + 'Delete “' + dropSlug + '” (id ' + dropId + ')\n\n'
                + 'All posts carrying the deleted tag will be reassigned.\n'
                + 'This cannot be undone.'
            )) {
                return;
            }

            const mergeBtn    = document.getElementById('gtd-do-merge');
            mergeBtn.disabled = true;
            mergeResult.innerHTML = 'Merging…';

            try {
                const result = await post('geo_tagger_dup_merge', { keep_id: keepId, drop_id: dropId });

                let logHtml = '<ol style="margin:8px 0 0;padding-left:22px;line-height:1.8">';
                result.log.forEach(line => { logHtml += '<li>' + esc(line) + '</li>'; });
                logHtml += '</ol>';
                mergeResult.innerHTML =
                    '<p style="color:#166534"><strong>&#10003; Merge complete.</strong></p>' + logHtml;

                // Silently reload the duplicate list
                await loadList();
                // Hide the detail and merge sections
                document.getElementById('gtd-detail-section').style.display = 'none';
                document.getElementById('gtd-merge-section').style.display  = 'none';

            } catch (err) {
                mergeResult.innerHTML = '<span style="color:#991b1b">&#10007; Error: ' + esc(err.message) + '</span>';
                mergeBtn.disabled = false;
            }
        });
    }

    // -----------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', loadList);
}());
