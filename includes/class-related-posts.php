<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

/**
 * "Other articles from the same place" tiles — a CTA to help readers
 * prepare their trip, using the same post → place resolution as
 * GeoBreadcrumb (PlaceRepository::get_chain_for_post()).
 *
 * Two shortcodes:
 *   [geo_related]       — single section. Auto-cascades city → region →
 *                          country (first level with at least
 *                          MIN_OTHERS other posts), or force a specific
 *                          level with level="city|region|country".
 *   [geo_related_full]  — stacked sections for every level that clears
 *                          MIN_OTHERS, most specific first.
 *
 * Both accept style="plain|cta|compact":
 *   plain   — "More about {place}" heading, image tiles.
 *   cta     — "Plan your trip to {place}" framing, same image tiles.
 *   compact — text-link list, no images, for narrow contexts.
 *
 * Also exposed as global functions (geo_tagger_related_posts() /
 * geo_tagger_related_posts_full()) for systematic theme use — e.g.
 * called directly from content-single.php so every geo-tagged post
 * gets this without manually placing a shortcode in the post content.
 *
 * Deliberately self-contained CSS (own inline stylesheet, like
 * GeoBreadcrumb), not the theme's .mv-card classes — this can appear on
 * any single post, and the theme's mv-home.css is only enqueued on the
 * homepage/Start Here/search pages, not on regular posts.
 */
class RelatedPosts {

    private const ALLOWED_LANGS = ['fr', 'en', 'de'];

    // How many *other* posts (current post excluded) a level needs before
    // it's worth showing as a tiles section — agreed as "3 others, 4 total"
    // rather than the breadcrumb's lower bar (a single extra link is a much
    // smaller commitment than a whole tiles section).
    private const MIN_OTHERS = 3;

    // Most specific first — auto-cascade and the "full" stack both walk this.
    private const LEVELS_DESC = ['city', 'region', 'country'];

    private const STRINGS = [
        'fr' => [
            'heading'     => 'Plus d’articles sur %s',
            'cta_heading' => 'Préparez votre voyage : %s',
            'see_all'     => 'Voir tous les articles sur %s',
        ],
        'en' => [
            'heading'     => 'More about %s',
            'cta_heading' => 'Plan your trip: %s',
            'see_all'     => 'See all articles about %s',
        ],
        'de' => [
            'heading'     => 'Mehr über %s',
            'cta_heading' => 'Plant eure Reise: %s',
            'see_all'     => 'Alle Artikel über %s ansehen',
        ],
    ];

    private PlaceRepository $place_repo;

    public function __construct(PlaceRepository $place_repo) {
        $this->place_repo = $place_repo;
    }

    public function init(): void {
        add_shortcode('geo_related', [$this, 'shortcode']);
        add_shortcode('geo_related_full', [$this, 'shortcode_full']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    public function enqueue_styles(): void {
        wp_register_style('geo-tagger-related', false, [], GEO_TAGGER_VERSION);
        wp_enqueue_style('geo-tagger-related');
        wp_add_inline_style('geo-tagger-related', $this->inline_css());
    }

    // -------------------------------------------------------------------------
    // Shortcodes
    // -------------------------------------------------------------------------

    public function shortcode(array $atts): string {
        $atts = shortcode_atts(
            [
                'post_id' => 0,
                'level'   => '',
                'style'   => 'plain',
                'limit'   => 6,
            ],
            $atts,
            'geo_related'
        );

        return $this->render(
            (int) $atts['post_id'],
            $atts['level'] ?: null,
            (string) $atts['style'],
            (int) $atts['limit']
        );
    }

    public function shortcode_full(array $atts): string {
        $atts = shortcode_atts(
            [
                'post_id' => 0,
                'style'   => 'plain',
                'limit'   => 6,
            ],
            $atts,
            'geo_related_full'
        );

        return $this->render_full((int) $atts['post_id'], (string) $atts['style'], (int) $atts['limit']);
    }

    // -------------------------------------------------------------------------
    // Public rendering API — also backs the global theme helper functions.
    // -------------------------------------------------------------------------

    /**
     * Single section: forces $level if given, otherwise auto-cascades
     * city → region → country, stopping at the first level with at
     * least MIN_OTHERS other posts. A forced level always renders
     * (even with 0-2 other posts) — no fallback — since the caller
     * asked for that level specifically.
     */
    public function render(int $post_id = 0, ?string $level = null, string $style = 'plain', int $limit = 6): string {
        $post_id = $post_id ?: (int) get_the_ID();
        if (!$post_id) {
            return '';
        }

        $lang = $this->get_lang($post_id);
        if (!$lang) {
            return '';
        }

        $chain = $this->place_repo->get_chain_for_post($post_id, $lang);
        if (empty($chain)) {
            return '';
        }

        $by_level      = $this->index_chain_by_level($chain);
        $levels_to_try = $level ? [$level] : self::LEVELS_DESC;

        foreach ($levels_to_try as $try_level) {
            $place = $by_level[$try_level] ?? null;
            if (!$place) {
                continue;
            }

            $term_id = $this->term_id_for_place($place, $lang);
            if (!$term_id) {
                continue;
            }

            $posts = $this->query_related($term_id, $post_id, $limit);

            if ($level || count($posts) >= self::MIN_OTHERS) {
                return $this->render_section($try_level, (string) $place->{'name_' . $lang}, $term_id, $posts, $lang, $style);
            }
        }

        return '';
    }

    /**
     * Stacked sections for every level that clears MIN_OTHERS, most
     * specific first. Unlike render(), there's no forcing a single
     * level here — that's what [geo_related level="..."] is for.
     */
    public function render_full(int $post_id = 0, string $style = 'plain', int $limit = 6): string {
        $post_id = $post_id ?: (int) get_the_ID();
        if (!$post_id) {
            return '';
        }

        $lang = $this->get_lang($post_id);
        if (!$lang) {
            return '';
        }

        $chain = $this->place_repo->get_chain_for_post($post_id, $lang);
        if (empty($chain)) {
            return '';
        }

        $by_level = $this->index_chain_by_level($chain);
        $sections = '';

        foreach (self::LEVELS_DESC as $try_level) {
            $place = $by_level[$try_level] ?? null;
            if (!$place) {
                continue;
            }

            $term_id = $this->term_id_for_place($place, $lang);
            if (!$term_id) {
                continue;
            }

            $posts = $this->query_related($term_id, $post_id, $limit);
            if (count($posts) < self::MIN_OTHERS) {
                continue;
            }

            $sections .= $this->render_section($try_level, (string) $place->{'name_' . $lang}, $term_id, $posts, $lang, $style);
        }

        return $sections;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function get_lang(int $post_id): ?string {
        $lang = function_exists('pll_get_post_language')
            ? (string) pll_get_post_language($post_id)
            : '';
        return in_array($lang, self::ALLOWED_LANGS, true) ? $lang : null;
    }

    private function index_chain_by_level(array $chain): array {
        $by_level = [];
        foreach ($chain as $place) {
            $by_level[$place->level] = $place;
        }
        return $by_level;
    }

    private function term_id_for_place(object $place, string $lang): int {
        return (int) ($place->{'term_id_' . $lang} ?? 0);
    }

    /**
     * @return \WP_Post[]
     */
    private function query_related(int $term_id, int $exclude_post_id, int $limit): array {
        $query = new \WP_Query([
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'tag__in'             => [$term_id],
            'post__not_in'        => [$exclude_post_id],
            'posts_per_page'      => $limit,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ]);
        return $query->posts;
    }

    private function render_section(string $level, string $place_name, int $term_id, array $posts, string $lang, string $style): string {
        if (empty($posts)) {
            return '';
        }

        $strings    = self::STRINGS[$lang] ?? self::STRINGS['fr'];
        $is_cta     = 'cta' === $style;
        $is_compact = 'compact' === $style;

        $heading = sprintf($is_cta ? $strings['cta_heading'] : $strings['heading'], $place_name);

        $items = '';
        foreach ($posts as $post) {
            $items .= $is_compact ? $this->render_list_item($post) : $this->render_tile($post);
        }

        $see_all_url = get_term_link($term_id, 'post_tag');
        $see_all     = '';
        if (!is_wp_error($see_all_url)) {
            $see_all = sprintf(
                '<p class="geo-related__more"><a href="%s">%s</a></p>',
                esc_url($see_all_url),
                esc_html(sprintf($strings['see_all'], $place_name))
            );
        }

        $list_tag   = $is_compact ? 'ul' : 'div';
        $list_class = $is_compact ? 'geo-related__list' : 'geo-related__grid';

        return sprintf(
            '<div class="geo-related geo-related--%s geo-related--%s"><h2 class="geo-related__heading">%s</h2><%s class="%s">%s</%s>%s</div>',
            esc_attr($level),
            esc_attr($style),
            esc_html($heading),
            $list_tag,
            $list_class,
            $items,
            $list_tag,
            $see_all
        );
    }

    private function render_tile(\WP_Post $post): string {
        $image = get_the_post_thumbnail($post, 'medium_large', ['class' => 'geo-related__image', 'alt' => '']);
        return sprintf(
            '<a class="geo-related__tile" href="%s">%s<span class="geo-related__title">%s</span></a>',
            esc_url(get_permalink($post)),
            $image ?: '',
            esc_html(get_the_title($post))
        );
    }

    private function render_list_item(\WP_Post $post): string {
        return sprintf(
            '<li class="geo-related__list-item"><a href="%s">%s</a></li>',
            esc_url(get_permalink($post)),
            esc_html(get_the_title($post))
        );
    }

    private function inline_css(): string {
        return '.geo-related{margin:2em 0}'
             . '.geo-related__heading{font-size:1.25em;line-height:1.3;margin:0 0 .75em}'
             . '.geo-related--cta .geo-related__heading{font-weight:700}'
             . '.geo-related__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1em;margin:0}'
             . '.geo-related__tile{display:block;text-decoration:none;color:inherit;border-radius:8px;overflow:hidden;background:#f7f7f7}'
             . '.geo-related__image{display:block;width:100%;height:120px;object-fit:cover}'
             . '.geo-related__title{display:block;padding:.6em .75em;font-size:.9em;line-height:1.3}'
             . '.geo-related__list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5em}'
             . '.geo-related__list-item a{text-decoration:none}'
             . '.geo-related__list-item a:hover{text-decoration:underline}'
             . '.geo-related__more{margin:1em 0 0;font-size:.9em}';
    }
}
