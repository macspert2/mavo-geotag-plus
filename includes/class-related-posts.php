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

    // render_full() only: skip the country section once the country has
    // more posts than this — double the default tile limit (6) felt
    // like a reasonable line between "these tiles are a representative
    // sample" and "these tiles are an arbitrary handful out of hundreds".
    private const MAX_COUNTRY_POSTS = 12;

    // Most specific first — auto-cascade and the "full" stack both walk this.
    private const LEVELS_DESC = ['city', 'region', 'country'];

    // cta_heading is per-level — "plan your trip" framing only makes
    // sense at the city level; region/country use a plainer "elsewhere
    // in the same place" framing instead.
    private const STRINGS = [
        'fr' => [
            'heading'     => 'Plus d’articles sur %s',
            'cta_heading' => [
                'city'    => 'Préparez votre voyage : %s',
                'region'  => 'Dans la même région : %s',
                'country' => 'Dans le même pays : %s',
            ],
            'see_all'     => 'Voir tous les articles sur %s',
        ],
        'en' => [
            'heading'     => 'More about %s',
            'cta_heading' => [
                'city'    => 'Plan your trip: %s',
                'region'  => 'In the same region: %s',
                'country' => 'In the same country: %s',
            ],
            'see_all'     => 'See all articles about %s',
        ],
        'de' => [
            'heading'     => 'Mehr über %s',
            'cta_heading' => [
                'city'    => 'Plant eure Reise: %s',
                'region'  => 'In der gleichen Region: %s',
                'country' => 'Im gleichen Land: %s',
            ],
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
                $place_name_fr = (string) ($place->name_fr ?? '');
                $place_label   = function_exists('mv_normalize_geo_label')
                    ? mv_normalize_geo_label($place_name_fr)
                    : $place_name_fr;
                $current_geo   = ['type' => $try_level, 'slug' => sanitize_title($place_label)];
                return $this->render_section($try_level, (string) $place->{'name_' . $lang}, $term_id, $posts, $lang, $style, $current_geo);
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

            // Country only: skip entirely once it has too many posts to
            // meaningfully represent in a handful of tiles (e.g. France,
            // with hundreds of posts, next to a focused city/region
            // section feels arbitrary rather than a real "see more"). Not
            // applied to city/region — those rarely get large enough for
            // this to matter, and not applied to render()'s single-section
            // auto-cascade, where country might be the only level a post
            // has at all.
            if ('country' === $try_level && $this->count_posts_for_term($term_id) > self::MAX_COUNTRY_POSTS) {
                continue;
            }

            $posts = $this->query_related($term_id, $post_id, $limit);
            if (count($posts) < self::MIN_OTHERS) {
                continue;
            }

            $place_name_fr = (string) ($place->name_fr ?? '');
            $place_label   = function_exists('mv_normalize_geo_label')
                ? mv_normalize_geo_label($place_name_fr)
                : $place_name_fr;
            $current_geo   = ['type' => $try_level, 'slug' => sanitize_title($place_label)];
            $sections .= $this->render_section($try_level, (string) $place->{'name_' . $lang}, $term_id, $posts, $lang, $style, $current_geo);
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
        // Geo Tagger Core explicitly tags both 'post' and 'page' (e.g.
        // destination hub pages like /france/ carry the same country
        // tag as regular posts), and 'post_type' => 'post' below has not
        // reliably kept them out in practice — confirmed live (/france/,
        // a real page, appearing as a tile). get_post_type() after the
        // query is what actually guarantees only real posts render.
        // Over-fetch a bit so filtering a few pages out doesn't leave
        // fewer than $limit tiles.
        $query = new \WP_Query([
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'tag__in'             => [$term_id],
            'post__not_in'        => [$exclude_post_id],
            'posts_per_page'      => $limit + 5,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ]);

        $posts = array_filter(
            $query->posts,
            static fn( $post ) => 'post' === get_post_type( $post )
        );

        return array_slice( array_values( $posts ), 0, $limit );
    }

    /**
     * Total published 'post'-type count for a term — used to decide
     * whether the country level is worth a tiles section in
     * render_full() (a handful of tiles next to hundreds of country
     * posts would feel arbitrary). Deliberately a real count query
     * rather than get_term()->count, which would also include any
     * geo-tagged pages.
     */
    private function count_posts_for_term(int $term_id): int {
        $query = new \WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'tag__in'        => [$term_id],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        return (int) $query->found_posts;
    }

    private function render_section(string $level, string $place_name, int $term_id, array $posts, string $lang, string $style, ?array $current_geo = null): string {
        if (empty($posts)) {
            return '';
        }

        $strings    = self::STRINGS[$lang] ?? self::STRINGS['fr'];
        $is_cta     = 'cta' === $style;
        $is_compact = 'compact' === $style;

        $heading_format = $is_cta
            ? ($strings['cta_heading'][$level] ?? $strings['heading'])
            : $strings['heading'];
        $heading = sprintf($heading_format, $place_name);

        $items = '';
        foreach ($posts as $post) {
            $items .= $is_compact ? $this->render_list_item($post) : $this->render_tile($post, $current_geo);
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

    private function render_tile(\WP_Post $post, ?array $current_geo = null): string {
        $image  = get_the_post_thumbnail($post, 'medium_large', ['class' => 'geo-related__image', 'alt' => '']);
        $badges = '';
        if ( function_exists('mv_tile_badges') ) {
            $badge_args = ['context' => 'article_related', 'limit' => 1];
            if ($current_geo) {
                $badge_args['current_geo'] = $current_geo;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $badges = mv_tile_badges($post->ID, $badge_args);
        }
        return sprintf(
            '<div class="geo-related__tile">%s%s<span class="geo-related__title"><a class="geo-related__link" href="%s">%s</a></span></div>',
            $image ?: '',
            $badges,
            esc_url(get_permalink($post)),
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
        // Grid is single-column by default (mobile), 3 fixed columns from
        // 700px up — matches the theme's own .mv-grid breakpoint
        // convention, and gives ~300px tiles at a 960px desktop content
        // width (3 columns, 2 gaps), so the default limit=6 renders as
        // 2 rows of 3. .geo-related__image uses aspect-ratio instead of a
        // fixed pixel height so it scales proportionally with that wider
        // tile width rather than looking stretched.
        return '.geo-related{margin:2em 0}'
             . '.geo-related__heading{margin:0 0 .75em}'
             . '.geo-related__grid{display:grid;grid-template-columns:1fr;gap:1em;margin:0}'
             . '@media (min-width:700px){.geo-related__grid{grid-template-columns:repeat(3,minmax(0,1fr))}}'
             . '.geo-related__tile{display:block;border-radius:8px;overflow:hidden;background:#f7f7f7;position:relative;isolation:isolate}'
             . '.geo-related__image{display:block;width:100%;aspect-ratio:3/2;object-fit:cover}'
             . '.geo-related__title{display:block;padding:.6em .75em;font-size:.9em;line-height:1.3}'
             . '.geo-related__link{color:inherit;text-decoration:none}'
             . '.geo-related__link::after{content:\'\';position:absolute;inset:0;z-index:0}'
             . '.geo-related__list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5em}'
             . '.geo-related__list-item a{text-decoration:none}'
             . '.geo-related__list-item a:hover{text-decoration:underline}'
             . '.geo-related__more{margin:1em 0 0;font-size:.9em}'
             . '.geo-related__tile .mv-tile__badges{position:relative;z-index:1;padding:.45em .75em .1em;gap:.3rem;margin:0}'
             . '.geo-related__tile .mv-badge{min-height:1.3rem;padding:.1rem .48rem;font-size:.72rem}';
    }
}
