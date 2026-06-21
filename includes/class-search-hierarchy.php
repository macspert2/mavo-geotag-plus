<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

/**
 * "Geographic hierarchy" section for the search results page. If the
 * search term matches a known place name (PlaceRepository::find_place_by_name(),
 * a LIKE match against name_{lang}), show links to articles in that
 * place's city, region, and country — useful when the literal search
 * text wouldn't otherwise match any post's title/content via
 * WordPress's own search (e.g. searching "Londres" surfaces the city
 * tag archive even for posts that never spell out the city name).
 *
 * Purpose-built for the search page only — no shortcode, just a global
 * helper function (geo_tagger_search_hierarchy(), see the main plugin
 * file), called directly from the theme's search-page template code
 * with get_search_query() and the current language.
 */
class SearchHierarchy {

    private const ALLOWED_LANGS  = ['fr', 'en', 'de'];
    private const MIN_TERM_LENGTH = 3;

    // A level only gets a link if its tag has more than this many
    // posts — mirrors GeoBreadcrumb's "city only shows if count > 1" rule.
    private const MIN_POST_COUNT = 1;

    private const LINKABLE_LEVELS = ['city', 'region', 'country'];

    private const STRINGS = [
        'fr' => [
            'heading' => 'Voyager à %s',
            'city'    => 'Articles sur %s',
            'region'  => 'Articles dans la même région : %s',
            'country' => 'Articles dans le même pays : %s',
        ],
        'en' => [
            'heading' => 'Travelling to %s',
            'city'    => 'Articles about %s',
            'region'  => 'Articles in the same region: %s',
            'country' => 'Articles in the same country: %s',
        ],
        'de' => [
            'heading' => 'Reisen nach %s',
            'city'    => 'Artikel über %s',
            'region'  => 'Artikel in der gleichen Region: %s',
            'country' => 'Artikel im gleichen Land: %s',
        ],
    ];

    private PlaceRepository $place_repo;

    public function __construct(PlaceRepository $place_repo) {
        $this->place_repo = $place_repo;
    }

    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    public function enqueue_styles(): void {
        wp_register_style('geo-tagger-search-hierarchy', false, [], GEO_TAGGER_VERSION);
        wp_enqueue_style('geo-tagger-search-hierarchy');
        wp_add_inline_style('geo-tagger-search-hierarchy', $this->inline_css());
    }

    /**
     * @param string $term Free-text search term (e.g. get_search_query()).
     * @param string $lang 'fr' | 'en' | 'de'.
     * @return string HTML, or '' if no place matches or none of its
     *                 levels have enough posts to link to.
     */
    public function render(string $term, string $lang): string {
        if (!in_array($lang, self::ALLOWED_LANGS, true)) {
            return '';
        }
        if (mb_strlen(trim($term)) < self::MIN_TERM_LENGTH) {
            return '';
        }

        $place = $this->place_repo->find_place_by_name($term, $lang);
        if (!$place) {
            return '';
        }

        $chain = $this->place_repo->get_place_chain((int) $place->id);
        if (empty($chain)) {
            return '';
        }

        $strings  = self::STRINGS[$lang] ?? self::STRINGS['fr'];
        $col_name = 'name_' . $lang;
        $col_term = 'term_id_' . $lang;

        $links = [];
        // Leaf (most specific) first: city, then region, then country.
        foreach (array_reverse($chain) as $node) {
            if (!in_array($node->level, self::LINKABLE_LEVELS, true)) {
                continue;
            }

            $name    = $node->$col_name ?? '';
            $term_id = (int) ($node->$col_term ?? 0);
            if (!$name || !$term_id) {
                continue;
            }

            $term_obj = get_term($term_id, 'post_tag');
            if (!$term_obj || is_wp_error($term_obj) || $term_obj->count <= self::MIN_POST_COUNT) {
                continue;
            }

            $url = get_term_link($term_id, 'post_tag');
            if (is_wp_error($url)) {
                continue;
            }

            $links[] = [
                'level' => $node->level,
                'url'   => $url,
                'label' => sprintf($strings[$node->level] ?? '%s', $name),
            ];
        }

        if (empty($links)) {
            return '';
        }

        $leaf_name = end($chain)->$col_name ?? '';
        $heading   = sprintf($strings['heading'], $leaf_name);

        $items = '';
        foreach ($links as $link) {
            $items .= sprintf(
                '<li class="geo-search-hierarchy__item geo-search-hierarchy__%s"><a href="%s">%s</a></li>',
                esc_attr($link['level']),
                esc_url($link['url']),
                esc_html($link['label'])
            );
        }

        return sprintf(
            '<div class="geo-search-hierarchy"><h2 class="geo-search-hierarchy__heading">%s</h2><ul class="geo-search-hierarchy__list">%s</ul></div>',
            esc_html($heading),
            $items
        );
    }

    private function inline_css(): string {
        return '.geo-search-hierarchy{margin:1.5em 0;padding:1em 1.25em;border-radius:8px;background:#f7f7f7}'
             . '.geo-search-hierarchy__heading{margin:0 0 .5em;font-size:1.1em}'
             . '.geo-search-hierarchy__list{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:.5em 1.25em}'
             . '.geo-search-hierarchy__item a{text-decoration:none}'
             . '.geo-search-hierarchy__item a:hover{text-decoration:underline}';
    }
}
