/* global geoTaggerPlaces */
(function () {
    'use strict';

    const { ajaxUrl, nonce, editTermUrl } = geoTaggerPlaces;

    // Mirrors PlaceEditor::PARENT_LEVELS in class-place-editor.php — keep in sync.
    const PARENT_LEVELS = {
        country: ['continent'],
        region:  ['country'],
        city:    ['country', 'region'],
    };

    let places = [];   // last loaded list, used to rebuild parent dropdowns client-side
    let byId   = {};
    const postsCache = {}; // place_id -> { by_lang } from ajax_posts, kept for the page's lifetime

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

    async function loadList() {
        const loadingEl = document.getElementById('gtp-loading');
        const wrapEl     = document.getElementById('gtp-table-wrap');

        loadingEl.style.display = 'block';
        wrapEl.style.display    = 'none';

        try {
            const data = await post('geo_tagger_place_list', {});
            places = data.places;
            byId   = {};
            places.forEach(p => { byId[p.id] = p; });

            renderTable();
            loadingEl.style.display = 'none';
            wrapEl.style.display    = 'block';
            applyFilter();
        } catch (err) {
            loadingEl.textContent = 'Error loading places: ' + err.message;
            loadingEl.style.color = 'red';
        }
    }

    function levelOptions(selected) {
        return ['continent', 'country', 'region', 'city'].map(lvl =>
            '<option value="' + lvl + '"' + (lvl === selected ? ' selected' : '') + '>'
            + lvl.charAt(0).toUpperCase() + lvl.slice(1) + '</option>'
        ).join('');
    }

    function parentOptionsHtml(level, selectedParentId, ownId) {
        if (level === 'continent') {
            return '<em style="color:#888">World (fixed)</em>';
        }
        const allowedLevels = PARENT_LEVELS[level] || [];
        const candidates = places
            .filter(p => allowedLevels.includes(p.level) && p.id !== ownId)
            .sort((a, b) => a.path.localeCompare(b.path));

        let html = '<select class="gtp-parent" style="width:100%">';
        html += '<option value="">— choose —</option>';
        candidates.forEach(p => {
            html += '<option value="' + p.id + '"' + (p.id === selectedParentId ? ' selected' : '') + '>'
                  + esc(p.path) + '</option>';
        });
        html += '</select>';
        return html;
    }

    function tagsHtml(p) {
        const langs = [['fr', p.term_id_fr], ['en', p.term_id_en], ['de', p.term_id_de]];
        return langs.map(([lang, id]) => {
            if (!id) return '<span style="color:#bbb">' + lang + '—</span>';
            return '<a href="' + esc(editTermUrl) + id + '" target="_blank" rel="noopener">' + lang + ':' + id + '</a>';
        }).join(' &nbsp;');
    }

    function renderRow(p) {
        const tr = document.createElement('tr');
        tr.dataset.id = p.id;
        tr.dataset.path = p.path.toLowerCase();
        tr.dataset.level = p.level;

        const postsToggleHtml = p.level === 'city'
            ? '<button class="button button-small gtp-toggle-posts" style="margin-left:4px">Posts</button>'
            : '';

        tr.innerHTML =
            '<td class="gtp-path">' + esc(p.path) + '</td>'
            + '<td><select class="gtp-level">' + levelOptions(p.level) + '</select></td>'
            + '<td class="gtp-parent-cell">' + parentOptionsHtml(p.level, p.parent_id, p.id) + '</td>'
            + '<td><input class="gtp-cc" type="text" maxlength="2" value="' + esc(p.country_code || '') + '" style="width:100%"></td>'
            + '<td><input class="gtp-name-fr" type="text" value="' + esc(p.name_fr || '') + '" style="width:100%"></td>'
            + '<td><input class="gtp-name-en" type="text" value="' + esc(p.name_en || '') + '" style="width:100%"></td>'
            + '<td><input class="gtp-name-de" type="text" value="' + esc(p.name_de || '') + '" style="width:100%"></td>'
            + '<td style="font-size:11px;white-space:nowrap">' + tagsHtml(p) + '</td>'
            + '<td><button class="button button-primary button-small gtp-save">Save</button>'
            + postsToggleHtml
            + '<div class="gtp-status" style="font-size:11px;margin-top:4px"></div></td>';

        tr.querySelector('.gtp-level').addEventListener('change', (e) => {
            const cell = tr.querySelector('.gtp-parent-cell');
            cell.innerHTML = parentOptionsHtml(e.target.value, null, p.id);
        });

        tr.querySelector('.gtp-save').addEventListener('click', () => saveRow(tr, p.id));

        const toggleBtn = tr.querySelector('.gtp-toggle-posts');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => togglePostsRow(tr, p));
        }

        return tr;
    }

    function renderTable() {
        const tbody = document.getElementById('gtp-tbody');
        tbody.innerHTML = '';
        places.forEach(p => tbody.appendChild(renderRow(p)));
        document.getElementById('gtp-count').textContent = places.length + ' place(s)';
    }

    // -------------------------------------------------------------------------
    // City-level: list of posts tagged with this place
    // -------------------------------------------------------------------------

    const LANG_LABELS = { fr: 'FR', en: 'EN', de: 'DE' };

    function postsListHtml(byLang) {
        const langs = Object.keys(byLang);
        if (langs.length === 0) {
            return '<p style="margin:0;color:#888">No language tag is linked to this place yet.</p>';
        }

        let html = '';
        let total = 0;
        langs.forEach(lang => {
            const posts = byLang[lang];
            total += posts.length;
            html += '<strong>' + (LANG_LABELS[lang] || lang) + '</strong> (' + posts.length + ')';
            if (posts.length === 0) {
                html += '<p style="margin:2px 0 10px;color:#888">No posts.</p>';
                return;
            }
            html += '<ul style="margin:4px 0 10px;padding-left:18px">';
            posts.forEach(post => {
                const statusTag = post.status !== 'publish' ? ' <em style="color:#b32d2e">(' + esc(post.status) + ')</em>' : '';
                html += '<li>'
                    + '<a href="' + esc(post.edit_url) + '" target="_blank" rel="noopener">' + esc(post.title) + '</a>'
                    + statusTag
                    + ' &nbsp;<a href="' + esc(post.permalink) + '" target="_blank" rel="noopener" style="color:#888">view ↗</a>'
                    + '</li>';
            });
            html += '</ul>';
        });

        if (total === 0) {
            html = '<p style="margin:0;color:#888">No posts are tagged with this place yet.</p>' + html;
        }
        return html;
    }

    async function togglePostsRow(tr, p) {
        const existing = tr.nextElementSibling;
        if (existing && existing.classList.contains('gtp-posts-row')) {
            existing.remove();
            return;
        }

        // Close any other open posts row — keeps the table from growing unbounded.
        document.querySelectorAll('.gtp-posts-row').forEach(row => row.remove());

        const postsRow = document.createElement('tr');
        postsRow.className = 'gtp-posts-row';
        const cell = document.createElement('td');
        cell.colSpan = 9;
        cell.style.background = '#f6f7f7';
        cell.style.padding = '10px 16px';
        cell.textContent = 'Loading posts…';
        cell.style.color = '#888';
        postsRow.appendChild(cell);
        tr.after(postsRow);

        try {
            if (!postsCache[p.id]) {
                postsCache[p.id] = await post('geo_tagger_place_posts', { place_id: p.id });
            }
            cell.style.color = '';
            cell.innerHTML = postsListHtml(postsCache[p.id].by_lang);
        } catch (err) {
            cell.style.color = '#991b1b';
            cell.textContent = 'Error loading posts: ' + err.message;
        }
    }

    async function saveRow(tr, placeId) {
        const statusEl = tr.querySelector('.gtp-status');
        const saveBtn  = tr.querySelector('.gtp-save');
        const parentSelect = tr.querySelector('.gtp-parent');

        const payload = {
            place_id:     placeId,
            level:        tr.querySelector('.gtp-level').value,
            parent_id:    parentSelect ? (parentSelect.value || 0) : 0,
            country_code: tr.querySelector('.gtp-cc').value,
            name_fr:      tr.querySelector('.gtp-name-fr').value,
            name_en:      tr.querySelector('.gtp-name-en').value,
            name_de:      tr.querySelector('.gtp-name-de').value,
        };

        saveBtn.disabled = true;
        statusEl.style.color = '#888';
        statusEl.textContent = 'Saving…';

        try {
            await post('geo_tagger_place_update', payload);
            statusEl.style.color = '#166534';
            statusEl.textContent = 'Saved ✓';
            // Reload the whole list — names/parent may have changed, which shifts
            // this row's and others' "Path" column and parent-dropdown candidates.
            await loadList();
        } catch (err) {
            statusEl.style.color = '#991b1b';
            statusEl.textContent = 'Error: ' + err.message;
            saveBtn.disabled = false;
        }
    }

    function applyFilter() {
        const term  = document.getElementById('gtp-search').value.trim().toLowerCase();
        const level = document.getElementById('gtp-level-filter').value;
        let visible = 0;

        document.querySelectorAll('#gtp-tbody tr').forEach(tr => {
            const matchesTerm  = !term || tr.dataset.path.includes(term);
            const matchesLevel = !level || tr.dataset.level === level;
            const show = matchesTerm && matchesLevel;
            tr.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        document.getElementById('gtp-count').textContent = visible + ' / ' + places.length + ' place(s)';
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadList();
        document.getElementById('gtp-search').addEventListener('input', applyFilter);
        document.getElementById('gtp-level-filter').addEventListener('change', applyFilter);
    });
}());
