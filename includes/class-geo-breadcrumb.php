<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class GeoBreadcrumb {

    private const WORLD_URL     = 'https://www.mamanvoyage.com/ou-partir-trouvez-votre-prochain-voyage/';
    private const LEVEL_ORDER   = ['continent' => 1, 'country' => 2, 'region' => 3, 'city' => 4];
    private const ALLOWED_LANGS = ['fr', 'en', 'de'];
    private const HOME_LABELS   = ['fr' => 'Accueil', 'en' => 'Home', 'de' => 'Startseite'];

    public const META_HTML        = '_geo_breadcrumb_html';
    public const META_JSON        = '_geo_breadcrumb_json';
    public const META_FINGERPRINT = '_geo_breadcrumb_fingerprint';

    // Cached in META_FINGERPRINT (termmeta only) to remember that a tag was already
    // checked and isn't a geo place — skips the places-table lookup on later views.
    private const NOT_GEO_SENTINEL = '-';

    private PlaceRepository $place_repo;
    private array           $chain_cache      = [];
    private array           $region_countries = [];

    public function __construct(PlaceRepository $place_repo) {
        $this->place_repo      = $place_repo;
        $saved                 = get_option('geo_tagger_settings', []);
        $this->region_countries = $saved['region_countries'] ?? [];
    }

    public function init(): void {
        add_shortcode('geo_breadcrumb',  [$this, 'shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);

        if (defined('WPSEO_VERSION')) {
            // Yoast SEO already emits a BreadcrumbList inside its own @graph.
            // Replace its itemListElement with ours instead of outputting a
            // second, competing BreadcrumbList block.
            add_filter('wpseo_schema_breadcrumb', [$this, 'filter_yoast_breadcrumb']);
        } else {
            add_action('wp_head', [$this, 'output_json_ld']);
        }
    }

    public function enqueue_styles(): void {
        // Register a virtual (no-src) handle so we can attach inline CSS cleanly.
        wp_register_style('geo-tagger-breadcrumb', false, [], GEO_TAGGER_VERSION);
        wp_enqueue_style('geo-tagger-breadcrumb');
        wp_add_inline_style('geo-tagger-breadcrumb', $this->inline_css());
    }

    private function inline_css(): string {
        // Font-size/colour: WCAG doesn't set a numeric size floor, but ~13px is the
        // practical readability floor most accessibility guidance settles on; #767676
        // on a white/near-white background is the conventional "as light as possible
        // while still passing WCAG AA" grey (contrast ratio ~4.54:1, just over the 4.5:1
        // minimum for normal-sized text). Adjust if your theme's background isn't white.
        return '.geo-breadcrumb{'
             .     'display:flex;align-items:center;flex-wrap:wrap;'
             .     'gap:.15em;line-height:2;'
             .     'font-size:.8125em;color:#767676;'
             . '}'
             . '.geo-breadcrumb a{color:inherit;text-decoration:none}'
             . '.geo-breadcrumb a:hover,'
             . '.geo-breadcrumb a:focus{text-decoration:underline}'
             . '.geo-breadcrumb__full{'
             .     'display:inline-flex;align-items:center;flex-wrap:wrap;gap:.15em;'
             . '}'
             . '.geo-breadcrumb__up{'
             .     'display:none;align-items:center;gap:.3em;'
             . '}'
             . '.geo-breadcrumb__sep,'
             . '.geo-breadcrumb__end{'
             .     'display:inline-flex;align-items:center;'
             .     'opacity:.35;flex-shrink:0;margin:0 .15em;'
             . '}'
             . '.geo-breadcrumb__world,'
             . '.geo-breadcrumb__item{'
             .     'display:inline-flex;align-items:center;'
             . '}'
             . '@media (max-width:480px){'
             .     '.geo-breadcrumb__full{display:none}'
             .     '.geo-breadcrumb__up{display:inline-flex}'
             . '}';
    }

    public function shortcode(array $atts): string {
        $post_id = absint($atts['post_id'] ?? get_the_ID());
        return $this->render($post_id);
    }

    /**
     * Returns the cached breadcrumb <nav> HTML for a post.
     * Pass 0 (default) to use the current post in the loop.
     * Reads postmeta as-is — see sync_post_cache() for how/when it's (re)computed.
     */
    public function render(int $post_id = 0): string {
        if (!$post_id) {
            $post_id = (int) get_the_ID();
        }
        if (!$post_id) {
            return '';
        }
        $html = get_post_meta($post_id, self::META_HTML, true);
        return is_string($html) ? $html : '';
    }

    /**
     * Outputs the cached BreadcrumbList JSON-LD block into <head> for singular posts
     * and for post_tag archive pages that correspond to a geo place.
     */
    public function output_json_ld(): void {
        $json = $this->get_json_ld_for_current_request();
        if (empty($json)) {
            return;
        }
        // Defensive: a manual meta edit could reintroduce a script-closing sequence.
        echo '<script type="application/ld+json">'
           . str_replace('</script', '<\/script', $json)
           . '</script>' . "\n";
    }

    /**
     * Replaces Yoast SEO's BreadcrumbList itemListElement with our cached one,
     * for singular posts and tag archives that have a geo breadcrumb. Yoast's own
     * '@id' (and the rest of its @graph) is left untouched, so anything referencing
     * the breadcrumb piece by @id keeps working.
     */
    public function filter_yoast_breadcrumb(array $data): array {
        $json = $this->get_json_ld_for_current_request();
        if (empty($json)) {
            return $data;
        }

        $decoded = json_decode($json, true);
        if (empty($decoded['itemListElement']) || !is_array($decoded['itemListElement'])) {
            return $data;
        }

        $data['itemListElement'] = $decoded['itemListElement'];
        return $data;
    }

    /**
     * Resolves the cached JSON-LD string for whichever context the current request
     * is in (singular post, or a post_tag archive backed by a geo place). Triggers
     * lazy cache population for tag archives so the very first view is covered too.
     */
    private function get_json_ld_for_current_request(): string {
        if (is_singular()) {
            $post_id = (int) get_queried_object_id();
            if (!$post_id) {
                return '';
            }
            $json = get_post_meta($post_id, self::META_JSON, true);
        } elseif (is_tag()) {
            $term_id = (int) get_queried_object_id();
            if (!$term_id) {
                return '';
            }
            $this->sync_term_cache($term_id);
            $json = get_term_meta($term_id, self::META_JSON, true);
        } else {
            return '';
        }

        return is_string($json) ? $json : '';
    }

    /**
     * Recomputes and caches the breadcrumb HTML + JSON-LD for a post, but only
     * when its resolved geographic leaf (or language) actually changed since the
     * last cache write. This preserves any manual link edits made directly in
     * postmeta when a batch rerun resolves the post to the same location.
     */
    public function sync_post_cache(int $post_id): void {
        $lang = function_exists('pll_get_post_language')
            ? (string) pll_get_post_language($post_id)
            : '';
        if (!in_array($lang, self::ALLOWED_LANGS, true)) {
            return;
        }

        $chain = $this->get_cached_chain($post_id, $lang);
        if (empty($chain)) {
            return;
        }

        $leaf        = end($chain);
        $fingerprint = $leaf->id . '_' . $lang;

        if (get_post_meta($post_id, self::META_FINGERPRINT, true) === $fingerprint) {
            return;
        }

        $items = $this->resolve_items($chain, $lang);
        if (empty($items)) {
            return;
        }

        $json_ld = $this->build_json_ld($items);

        update_post_meta($post_id, self::META_HTML, $this->build_html($items));
        update_post_meta($post_id, self::META_JSON, empty($json_ld)
            ? ''
            : wp_json_encode($json_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        update_post_meta($post_id, self::META_FINGERPRINT, $fingerprint);
    }

    /**
     * Returns the cached breadcrumb <nav> HTML for a post_tag archive, when that
     * tag corresponds to a node in the geo_tagger_places hierarchy. Pass 0 (default)
     * to use the currently queried term. Lazily computes + caches on first call
     * (see sync_term_cache()) — after that it's a plain termmeta read.
     */
    public function render_term(int $term_id = 0): string {
        if (!$term_id) {
            $term_id = (int) get_queried_object_id();
        }
        if (!$term_id) {
            return '';
        }

        $this->sync_term_cache($term_id);

        $html = get_term_meta($term_id, self::META_HTML, true);
        return is_string($html) ? $html : '';
    }

    /**
     * Resolves and caches the breadcrumb HTML + JSON-LD for a tag, in termmeta,
     * mirroring sync_post_cache(). Unlike posts there's no "save" event tied to a
     * term's position in the hierarchy, so this is called lazily from render_term()
     * and from the JSON-LD hooks — a cheap termmeta read short-circuits every call
     * after the first, including for ordinary (non-geo) tags via NOT_GEO_SENTINEL.
     *
     * The tag being displayed is always shown (un-linked, as the "current" crumb),
     * regardless of the region whitelist or city post-count rules — those only
     * prune intermediate ancestors, never the page you're actually on.
     */
    public function sync_term_cache(int $term_id): void {
        $cached_fingerprint = get_term_meta($term_id, self::META_FINGERPRINT, true);
        if ($cached_fingerprint === self::NOT_GEO_SENTINEL) {
            return;
        }

        $place = $this->place_repo->get_place_by_term_id($term_id);
        if (!$place) {
            update_term_meta($term_id, self::META_FINGERPRINT, self::NOT_GEO_SENTINEL);
            return;
        }

        $lang = null;
        foreach (self::ALLOWED_LANGS as $l) {
            if ((int) ($place->{'term_id_' . $l} ?? 0) === $term_id) {
                $lang = $l;
                break;
            }
        }
        if (!$lang) {
            return;
        }

        $fingerprint = $place->id . '_' . $lang;
        if ($cached_fingerprint === $fingerprint) {
            return;
        }

        $chain = $this->place_repo->get_place_chain((int) $place->id);
        if (empty($chain)) {
            return;
        }

        $items = $this->resolve_items($chain, $lang, (int) $place->id);
        if (empty($items)) {
            return;
        }

        $json_ld = $this->build_json_ld($items);

        // No trailing arrow: on a term's own archive page the last crumb already
        // is "here", there's no separate title below it for the arrow to point to.
        update_term_meta($term_id, self::META_HTML, $this->build_html($items, false));
        update_term_meta($term_id, self::META_JSON, empty($json_ld)
            ? ''
            : wp_json_encode($json_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        update_term_meta($term_id, self::META_FINGERPRINT, $fingerprint);
    }

    // -------------------------------------------------------------------------
    // Shared resolution pipeline
    // -------------------------------------------------------------------------

    /**
     * Converts a place chain into an ordered list of breadcrumb items.
     * Applies the city-count rule (city only if count > 1) and resolves URLs.
     * The world root is always item 0.
     *
     * $current_place_id, when set (term-archive breadcrumbs), marks that place as
     * the page being displayed: it's always included and never linked, regardless
     * of the region-whitelist or city-count rules below, which only ever prune
     * intermediate ancestors.
     *
     * Each item: ['level' => string, 'name' => string, 'url' => string|null, 'current' => bool]
     */
    private function resolve_items(array $chain, string $lang, ?int $current_place_id = null): array {
        $col_name = 'name_' . $lang;
        $col_term = 'term_id_' . $lang;

        $items = [
            [
                'level' => 'home',
                'name'  => self::HOME_LABELS[$lang] ?? 'Home',
                'url'   => home_url('/'),
            ],
            [
                'level' => 'world',
                'name'  => 'Voyages',
                'url'   => self::WORLD_URL,
            ],
        ];

        foreach ($chain as $place) {
            if ($place->level === 'world') {
                continue;
            }

            $name    = $place->$col_name ?? '';
            $term_id = empty($place->$col_term) ? 0 : (int) $place->$col_term;

            if (!$name || !$term_id) {
                continue;
            }

            $is_current = $current_place_id !== null && (int) $place->id === $current_place_id;

            // Region appears only for countries in the admin whitelist.
            if (!$is_current
                && $place->level === 'region'
                && !empty($this->region_countries)
                && !in_array($place->country_code, $this->region_countries, true)
            ) {
                continue;
            }

            // City appears only when other posts also carry this tag.
            // count = 1 means only the current post → tag archive would be trivial.
            if (!$is_current && $place->level === 'city') {
                $term = get_term($term_id, 'post_tag');
                if (!$term || is_wp_error($term) || $term->count <= 1) {
                    continue;
                }
            }

            $url = $is_current ? null : get_term_link($term_id, 'post_tag');

            $items[] = [
                'level'   => $place->level,
                'name'    => $name,
                'url'     => is_wp_error($url) ? null : $url,
                'current' => $is_current,
            ];
        }

        // Return empty if only the root items (home, world) made it in — nothing geographic to show.
        return count($items) > 2 ? $items : [];
    }

    // -------------------------------------------------------------------------
    // Renderers
    // -------------------------------------------------------------------------

    /**
     * $with_trailing_arrow is false for term-archive breadcrumbs: there, the last
     * crumb already is "where you are" (it gets aria-current instead), so there's
     * no separate title below it for a trailing arrow to point to.
     */
    private function build_html(array $items, bool $with_trailing_arrow = true): string {
        $parts = [];

        foreach ($items as $item) {
            if ($item['level'] === 'world') {
                $parts[] = sprintf(
                    '<a href="%s" class="geo-breadcrumb__world" title="%s">%s</a>',
                    esc_url($item['url']),
                    esc_attr($item['name']),
                    $this->world_icon()
                );
                continue;
            }

            if ($item['url']) {
                $parts[] = sprintf(
                    '<a href="%s" class="geo-breadcrumb__item geo-breadcrumb__%s">%s</a>',
                    esc_url($item['url']),
                    esc_attr($item['level']),
                    esc_html($item['name'])
                );
            } else {
                $parts[] = sprintf(
                    '<span class="geo-breadcrumb__item geo-breadcrumb__%s"%s>%s</span>',
                    esc_attr($item['level']),
                    !empty($item['current']) ? ' aria-current="page"' : '',
                    esc_html($item['name'])
                );
            }
        }

        $sep = '<span class="geo-breadcrumb__sep" aria-hidden="true">'
             . $this->chevron_icon()
             . '</span>';

        // Two views are baked into the same markup: the full chain (desktop) and a
        // single "one level up" link (mobile) — a media query in inline_css() picks
        // whichever fits, with no extra postmeta or per-request computation needed.
        $full = '<span class="geo-breadcrumb__full">' . implode($sep, $parts) . '</span>';
        $up   = $this->build_up_link($items);

        // On post breadcrumbs, a trailing non-clickable chevron closes the line,
        // hinting that the post title follows directly below.
        $end = $with_trailing_arrow
             ? '<span class="geo-breadcrumb__end" aria-hidden="true">' . $this->chevron_icon() . '</span>'
             : '';

        return '<nav class="geo-breadcrumb" aria-label="Geographic location">'
             . $full . $up . $end
             . '</nav>';
    }

    /**
     * Builds the compact "‹ Parent" link used on narrow screens — points to the
     * item directly above the deepest geographic item in the chain.
     */
    private function build_up_link(array $items): string {
        $count = count($items);
        if ($count < 2) {
            return '';
        }

        $parent = $items[$count - 2];
        if (empty($parent['url'])) {
            return '';
        }

        return sprintf(
            '<a href="%s" class="geo-breadcrumb__up">%s<span>%s</span></a>',
            esc_url($parent['url']),
            $this->chevron_icon(true),
            esc_html($parent['name'])
        );
    }

    private function build_json_ld(array $items): array {
        $list_items = [];
        $position   = 1;

        foreach ($items as $item) {
            // Skip items with no URL — structured data requires an @id/item URL
            if (!$item['url']) {
                continue;
            }
            $list_items[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $item['name'],
                'item'     => $item['url'],
            ];
        }

        if (count($list_items) <= 1) {
            return [];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $list_items,
        ];
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    private function get_cached_chain(int $post_id, string $lang): array {
        $key = $post_id . '_' . $lang;
        if (!array_key_exists($key, $this->chain_cache)) {
            $this->chain_cache[$key] = $this->get_place_chain_for_post($post_id, $lang);
        }
        return $this->chain_cache[$key];
    }

    /**
     * Finds the post's geo tags in geo_tagger_places, picks the deepest level
     * (city > region > country > continent) as the leaf, and returns the full
     * chain from continent down to that leaf via PlaceRepository::get_place_chain().
     */
    private function get_place_chain_for_post(int $post_id, string $lang): array {
        global $wpdb;

        $term_ids = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'ids']);
        if (empty($term_ids) || is_wp_error($term_ids)) {
            return [];
        }

        // $lang is whitelisted to fr/en/de above, so the column name is safe.
        $col          = 'term_id_' . $lang;
        $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));

        $places = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}geo_tagger_places WHERE {$col} IN ($placeholders)",
                ...$term_ids
            )
        );

        if (empty($places)) {
            return [];
        }

        $leaf      = null;
        $max_depth = 0;
        foreach ($places as $place) {
            $depth = self::LEVEL_ORDER[$place->level] ?? 0;
            if ($depth > $max_depth) {
                $max_depth = $depth;
                $leaf      = $place;
            }
        }

        return $leaf ? $this->place_repo->get_place_chain((int) $leaf->id) : [];
    }

    private function chevron_icon(bool $flip = false): string {
        // A clean chevron that inherits colour and scales with text.
        // $flip mirrors it to point left, used by the "one level up" link.
        $style = $flip ? ' style="transform:scaleX(-1)"' : '';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 6 10"'
             . ' width=".45em" height=".75em" fill="none" stroke="currentColor"'
             . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
             . $style
             . ' aria-hidden="true" focusable="false">'
             . '<polyline points="1,1 5,5 1,9"/>'
             . '</svg>';
    }

    private function world_icon(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"'
             . ' width="1.1em" height="1.1em" fill="none" stroke="currentColor"'
             . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
             . ' style="vertical-align:middle" aria-hidden="true" focusable="false">'
             . '<circle cx="12" cy="12" r="10"/>'
             . '<line x1="2" y1="12" x2="22" y2="12"/>'
             . '<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10'
             . ' 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'
             . '</svg>';
    }
}
