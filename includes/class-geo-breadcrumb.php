<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class GeoBreadcrumb {

    private const WORLD_URL     = 'https://www.mamanvoyage.com/ou-partir-trouvez-votre-prochain-voyage/';
    private const LEVEL_ORDER   = ['continent' => 1, 'country' => 2, 'region' => 3, 'city' => 4];
    private const ALLOWED_LANGS = ['fr', 'en', 'de'];

    private PlaceRepository $place_repo;
    private array           $chain_cache = [];

    public function __construct(PlaceRepository $place_repo) {
        $this->place_repo = $place_repo;
    }

    public function init(): void {
        add_shortcode('geo_breadcrumb',  [$this, 'shortcode']);
        add_action('wp_head',            [$this, 'output_json_ld']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    public function enqueue_styles(): void {
        // Register a virtual (no-src) handle so we can attach inline CSS cleanly.
        wp_register_style('geo-tagger-breadcrumb', false, [], GEO_TAGGER_VERSION);
        wp_enqueue_style('geo-tagger-breadcrumb');
        wp_add_inline_style('geo-tagger-breadcrumb', $this->inline_css());
    }

    private function inline_css(): string {
        return '.geo-breadcrumb{'
             .     'display:flex;align-items:center;flex-wrap:wrap;'
             .     'gap:.15em;line-height:2;'
             . '}'
             . '.geo-breadcrumb__sep{'
             .     'display:inline-flex;align-items:center;'
             .     'opacity:.35;flex-shrink:0;margin:0 .15em;'
             . '}'
             . '.geo-breadcrumb__world,'
             . '.geo-breadcrumb__item{'
             .     'display:inline-flex;align-items:center;'
             . '}';
    }

    public function shortcode(array $atts): string {
        $post_id = absint($atts['post_id'] ?? get_the_ID());
        return $this->render($post_id);
    }

    /**
     * Returns the breadcrumb <nav> HTML for a post.
     * Pass 0 (default) to use the current post in the loop.
     */
    public function render(int $post_id = 0): string {
        [$post_id, $lang, $items] = $this->load($post_id);
        if (empty($items)) {
            return '';
        }
        return $this->build_html($items);
    }

    /**
     * Outputs a BreadcrumbList JSON-LD block into <head> for singular posts.
     */
    public function output_json_ld(): void {
        if (!is_singular()) {
            return;
        }
        [$post_id, $lang, $items] = $this->load(0);
        if (empty($items)) {
            return;
        }
        $payload = $this->build_json_ld($items);
        if (empty($payload)) {
            return;
        }
        echo '<script type="application/ld+json">'
           . wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
           . '</script>' . "\n";
    }

    // -------------------------------------------------------------------------
    // Shared resolution pipeline
    // -------------------------------------------------------------------------

    /**
     * Resolves post_id + lang, fetches (and caches) the place chain, then
     * converts it to a flat list of breadcrumb items.
     * Returns [$post_id, $lang, $items] where $items is empty on failure.
     */
    private function load(int $post_id): array {
        if (!$post_id) {
            $post_id = (int) get_the_ID();
        }
        if (!$post_id) {
            return [0, '', []];
        }

        $lang = function_exists('pll_get_post_language')
            ? (string) pll_get_post_language($post_id)
            : '';

        if (!in_array($lang, self::ALLOWED_LANGS, true)) {
            return [$post_id, '', []];
        }

        $chain = $this->get_cached_chain($post_id, $lang);
        if (empty($chain)) {
            return [$post_id, $lang, []];
        }

        return [$post_id, $lang, $this->resolve_items($chain, $lang)];
    }

    /**
     * Converts a place chain into an ordered list of breadcrumb items.
     * Applies the city-count rule (city only if count > 1) and resolves URLs.
     * The world root is always item 0.
     *
     * Each item: ['level' => string, 'name' => string, 'url' => string|null]
     */
    private function resolve_items(array $chain, string $lang): array {
        $col_name = 'name_' . $lang;
        $col_term = 'term_id_' . $lang;

        $items = [[
            'level' => 'world',
            'name'  => 'Voyages',
            'url'   => self::WORLD_URL,
        ]];

        foreach ($chain as $place) {
            if ($place->level === 'world') {
                continue;
            }

            $name    = $place->$col_name ?? '';
            $term_id = empty($place->$col_term) ? 0 : (int) $place->$col_term;

            if (!$name || !$term_id) {
                continue;
            }

            // City appears only when other posts also carry this tag.
            // count = 1 means only the current post → tag archive would be trivial.
            if ($place->level === 'city') {
                $term = get_term($term_id, 'post_tag');
                if (!$term || is_wp_error($term) || $term->count <= 1) {
                    continue;
                }
            }

            $url = get_term_link($term_id, 'post_tag');

            $items[] = [
                'level' => $place->level,
                'name'  => $name,
                'url'   => is_wp_error($url) ? null : $url,
            ];
        }

        // Return empty if only the world root made it in (nothing geographic to show)
        return count($items) > 1 ? $items : [];
    }

    // -------------------------------------------------------------------------
    // Renderers
    // -------------------------------------------------------------------------

    private function build_html(array $items): string {
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
                    '<span class="geo-breadcrumb__item geo-breadcrumb__%s">%s</span>',
                    esc_attr($item['level']),
                    esc_html($item['name'])
                );
            }
        }

        $sep = '<span class="geo-breadcrumb__sep" aria-hidden="true">'
             . $this->chevron_icon()
             . '</span>';

        return '<nav class="geo-breadcrumb" aria-label="Geographic location">'
             . implode($sep, $parts)
             . '</nav>';
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

    private function chevron_icon(): string {
        // A clean right-pointing chevron that inherits colour and scales with text.
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 6 10"'
             . ' width=".45em" height=".75em" fill="none" stroke="currentColor"'
             . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
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
