<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

/**
 * Admin screen for directly correcting geo_tagger_places rows — level,
 * parent, country code, and the three language names. Edits here persist
 * across reruns: nothing in the tagging pipeline ever rewrites these
 * columns once a place row exists (TagManager only ever fills in *missing*
 * term_id_* columns), so a correction made here sticks permanently.
 *
 * Doesn't touch the WP tag itself (post_tag term name/slug) — that's still
 * edited via the standard WordPress term editor, as documented from the
 * start of this plugin. This screen only fixes the geo_tagger_places
 * hierarchy that drives breadcrumbs/related-posts/search-hierarchy.
 *
 * Editing a place doesn't retroactively touch already-cached breadcrumb
 * HTML/JSON-LD (the fingerprint only changes if the leaf place ID itself
 * changes) — use "Clear Breadcrumb Cache" on the main Geo Tagger page
 * afterwards if the edit should show up on already-published content.
 */
class PlaceEditor {

    // A level's parent must be one of these levels — enforced on every save
    // so the hierarchy can't be corrupted into a nonsensical shape.
    // 'continent' isn't listed: its parent is always the single 'world' root
    // and isn't user-editable.
    private const PARENT_LEVELS = [
        'country' => ['continent'],
        'region'  => ['country'],
        'city'    => ['country', 'region'],
    ];

    private PlaceRepository $place_repo;

    public function __construct(PlaceRepository $place_repo) {
        $this->place_repo = $place_repo;
    }

    public function init(): void {
        add_action('admin_menu',            [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_geo_tagger_place_list',   [$this, 'ajax_list']);
        add_action('wp_ajax_geo_tagger_place_update', [$this, 'ajax_update']);
        add_action('wp_ajax_geo_tagger_place_posts',  [$this, 'ajax_posts']);
    }

    public function register_menu(): void {
        add_management_page(
            'Geo Tagger: Place Editor',
            'Geo Tagger Places',
            'manage_options',
            'geo-tagger-places',
            [$this, 'render_page']
        );
    }

    public function enqueue_scripts(string $hook): void {
        if ($hook !== 'tools_page_geo-tagger-places') {
            return;
        }
        wp_enqueue_script(
            'geo-tagger-places',
            plugins_url('js/place-editor.js', __FILE__),
            [],
            GEO_TAGGER_VERSION,
            true
        );
        wp_localize_script('geo-tagger-places', 'geoTaggerPlaces', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('geo_tagger_place_nonce'),
            'editTermUrl' => admin_url('edit-tags.php?action=edit&taxonomy=post_tag&tag_ID='),
        ]);
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Geo Tagger: Place Editor</h1>
            <p>
                Correct a place's level, parent, country code, or language names directly.
                Changes here persist across batch reruns — only the WP tag's own name/slug
                (edited via the standard tag screen) and the breadcrumb cache (use
                "Clear Breadcrumb Cache" on the main Geo Tagger page after a structural edit)
                are separate.
                &nbsp;<a href="<?php echo esc_url(admin_url('tools.php?page=geo-tagger')); ?>">&larr; Back to Geo Tagger</a>
            </p>

            <p>
                <input type="search" id="gtp-search" placeholder="Filter by name…" class="regular-text">
                <select id="gtp-level-filter">
                    <option value="">All levels</option>
                    <option value="continent">Continent</option>
                    <option value="country">Country</option>
                    <option value="region">Region</option>
                    <option value="city">City</option>
                </select>
                <span id="gtp-count" style="margin-left:10px;color:#888"></span>
            </p>

            <div id="gtp-loading" style="color:#888">Loading places&hellip;</div>
            <div id="gtp-table-wrap" style="display:none;overflow-x:auto">
                <table class="widefat striped" id="gtp-table">
                    <thead>
                        <tr>
                            <th style="min-width:220px">Path</th>
                            <th style="min-width:110px">Level</th>
                            <th style="min-width:200px">Parent</th>
                            <th style="width:70px">Country</th>
                            <th style="min-width:140px">Name FR</th>
                            <th style="min-width:140px">Name EN</th>
                            <th style="min-width:140px">Name DE</th>
                            <th style="min-width:90px">Tags</th>
                            <th style="width:130px"></th>
                        </tr>
                    </thead>
                    <tbody id="gtp-tbody"></tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: list all places
    // -------------------------------------------------------------------------

    public function ajax_list(): void {
        check_ajax_referer('geo_tagger_place_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $places = $this->place_repo->get_all_places();

        $by_id = [];
        foreach ($places as $place) {
            $by_id[(int) $place->id] = $place;
        }

        $children_count = [];
        foreach ($places as $place) {
            if ($place->parent_id !== null) {
                $pid = (int) $place->parent_id;
                $children_count[$pid] = ($children_count[$pid] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($places as $place) {
            $rows[] = [
                'id'             => (int) $place->id,
                'parent_id'      => $place->parent_id !== null ? (int) $place->parent_id : null,
                'level'          => $place->level,
                'name_fr'        => $place->name_fr,
                'name_en'        => $place->name_en,
                'name_de'        => $place->name_de,
                'country_code'   => $place->country_code,
                'term_id_fr'     => $place->term_id_fr ? (int) $place->term_id_fr : null,
                'term_id_en'     => $place->term_id_en ? (int) $place->term_id_en : null,
                'term_id_de'     => $place->term_id_de ? (int) $place->term_id_de : null,
                'path'           => $this->build_path($place, $by_id),
                'children_count' => $children_count[(int) $place->id] ?? 0,
            ];
        }

        wp_send_json_success(['places' => $rows]);
    }

    /**
     * Walks the parent chain via the in-memory $by_id map (no extra queries)
     * to build a human-readable "Europe › France › Bretagne" style label —
     * the flat name alone isn't enough to tell apart legitimate same-name
     * places at different points in the tree.
     */
    private function build_path(object $place, array $by_id): string {
        $labels = [$this->display_name($place)];
        $current = $place;
        for ($i = 0; $i < 5 && $current->parent_id !== null; $i++) {
            $parent = $by_id[(int) $current->parent_id] ?? null;
            if (!$parent) {
                break;
            }
            array_unshift($labels, $this->display_name($parent));
            $current = $parent;
        }
        return implode(' › ', $labels);
    }

    private function display_name(object $place): string {
        return $place->name_fr ?: ($place->name_en ?: ($place->name_de ?: "(#{$place->id})"));
    }

    // -------------------------------------------------------------------------
    // AJAX: update one place
    // -------------------------------------------------------------------------

    public function ajax_update(): void {
        check_ajax_referer('geo_tagger_place_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $place_id = absint($_POST['place_id'] ?? 0);
        $level    = sanitize_key($_POST['level'] ?? '');
        $parent_id = absint($_POST['parent_id'] ?? 0);

        if (!$place_id || !in_array($level, ['continent', 'country', 'region', 'city'], true)) {
            wp_send_json_error(['message' => 'Invalid place ID or level.']);
        }
        if ($place_id === $parent_id) {
            wp_send_json_error(['message' => 'A place cannot be its own parent.']);
        }

        $places = $this->place_repo->get_all_places();
        $by_id  = [];
        $children_count = [];
        foreach ($places as $p) {
            $by_id[(int) $p->id] = $p;
            if ($p->parent_id !== null) {
                $pid = (int) $p->parent_id;
                $children_count[$pid] = ($children_count[$pid] ?? 0) + 1;
            }
        }

        $current = $by_id[$place_id] ?? null;
        if (!$current) {
            wp_send_json_error(['message' => 'Place not found.']);
        }

        if ($level !== $current->level && !empty($children_count[$place_id])) {
            wp_send_json_error([
                'message' => "Can't change the level of a place with "
                           . $children_count[$place_id] . ' child place(s) — reparent or edit them first.',
            ]);
        }

        if ($level === 'continent') {
            $parent_id = (int) $this->place_repo->get_world_id();
        } else {
            $parent = $by_id[$parent_id] ?? null;
            $allowed = self::PARENT_LEVELS[$level] ?? [];
            if (!$parent || !in_array($parent->level, $allowed, true)) {
                wp_send_json_error([
                    'message' => "A '{$level}' must be parented under a " . implode(' or ', $allowed) . ' place.',
                ]);
            }
        }

        $data = [
            'level'        => $level,
            'parent_id'    => $parent_id,
            'country_code' => substr(sanitize_key($_POST['country_code'] ?? ''), 0, 2) ?: null,
            'name_fr'      => sanitize_text_field($_POST['name_fr'] ?? '') ?: null,
            'name_en'      => sanitize_text_field($_POST['name_en'] ?? '') ?: null,
            'name_de'      => sanitize_text_field($_POST['name_de'] ?? '') ?: null,
        ];

        $ok = $this->place_repo->update_place($place_id, $data);
        if (!$ok) {
            wp_send_json_error(['message' => 'Database update failed.']);
        }

        wp_send_json_success(['place_id' => $place_id]);
    }

    // -------------------------------------------------------------------------
    // AJAX: posts tagged with a city's term(s)
    // -------------------------------------------------------------------------

    /**
     * Lists the posts tagged with a city place's term_id_fr/en/de — each
     * language is queried separately since Polylang gives every language
     * its own distinct post_tag term. City-only: regions/countries can have
     * far too many posts for this to stay a quick on-demand lookup, and the
     * editor's main use is sanity-checking the leaf level a post landed on.
     */
    public function ajax_posts(): void {
        check_ajax_referer('geo_tagger_place_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $place_id = absint($_POST['place_id'] ?? 0);
        $place    = $place_id ? $this->place_repo->get_place($place_id) : null;

        if (!$place || $place->level !== 'city') {
            wp_send_json_error(['message' => 'Posts are only listed for city-level places.']);
        }

        $by_lang = [];
        foreach (['fr', 'en', 'de'] as $lang) {
            $term_id = (int) ($place->{'term_id_' . $lang} ?? 0);
            if (!$term_id) {
                continue;
            }

            $posts = get_posts([
                'tag_id'         => $term_id,
                'post_type'      => 'post',
                'post_status'    => ['publish', 'draft', 'pending', 'future'],
                'posts_per_page' => 100,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);

            $by_lang[$lang] = array_map(static function ($post) {
                return [
                    'id'        => $post->ID,
                    'title'     => get_the_title($post) ?: '(no title)',
                    'status'    => $post->post_status,
                    'edit_url'  => get_edit_post_link($post->ID, 'raw'),
                    'permalink' => get_permalink($post->ID),
                ];
            }, $posts);
        }

        wp_send_json_success(['by_lang' => $by_lang]);
    }
}
