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
 * When Mavo Hub Manager is active and the post has a primary *geographic*
 * hub, that hub leads the section as the first tile, marked with the site's
 * warm eyebrow ("Guide" / "Übersicht") the way Mavo For You marks its own hub
 * card. It is not the closest match — it is the page that says it owns this
 * article — so it is placed ahead of the ranking rather than scored into it.
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
            'hub_label'   => 'Guide',
        ],
        'en' => [
            'heading'     => 'More about %s',
            'cta_heading' => [
                'city'    => 'Plan your trip: %s',
                'region'  => 'In the same region: %s',
                'country' => 'In the same country: %s',
            ],
            'see_all'     => 'See all articles about %s',
            'hub_label'   => 'Guide',
        ],
        'de' => [
            'heading'     => 'Mehr über %s',
            'cta_heading' => [
                'city'    => 'Plant eure Reise: %s',
                'region'  => 'In der gleichen Region: %s',
                'country' => 'Im gleichen Land: %s',
            ],
            'see_all'     => 'Alle Artikel über %s ansehen',
            'hub_label'   => 'Übersicht',
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
        $hub           = $this->resolve_geo_hub($post_id, $lang);

        foreach ($levels_to_try as $try_level) {
            $place = $by_level[$try_level] ?? null;
            if (!$place) {
                continue;
            }

            $term_id = $this->term_id_for_place($place, $lang);
            if (!$term_id) {
                continue;
            }

            $posts = $this->query_related($term_id, $hub ? [$post_id, $hub->ID] : [$post_id], $limit);

            if ($level || count($posts) >= self::MIN_OTHERS) {
                // The hub takes a tile of its own, so one related post steps
                // aside: the section still shows $limit tiles in total and the
                // grid stays a clean 3 columns at the default limit=6. Trimmed
                // only after the threshold above, so the hub can never cost a
                // section the MIN_OTHERS bar it would otherwise have cleared.
                if ($hub) {
                    $posts = array_slice($posts, 0, max(1, $limit - 1));
                }

                $place_name_fr = (string) ($place->name_fr ?? '');
                $place_label   = function_exists('mv_normalize_geo_label')
                    ? mv_normalize_geo_label($place_name_fr)
                    : $place_name_fr;
                $current_geo   = ['type' => $try_level, 'slug' => sanitize_title($place_label)];
                return $this->render_section($try_level, (string) $place->{'name_' . $lang}, $term_id, $posts, $lang, $style, $current_geo, $hub);
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
        $hub      = $this->resolve_geo_hub($post_id, $lang);

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

            $posts = $this->query_related($term_id, $hub ? [$post_id, $hub->ID] : [$post_id], $limit);
            if (count($posts) < self::MIN_OTHERS) {
                continue;
            }
            if ($hub) {
                $posts = array_slice($posts, 0, max(1, $limit - 1));
            }

            $place_name_fr = (string) ($place->name_fr ?? '');
            $place_label   = function_exists('mv_normalize_geo_label')
                ? mv_normalize_geo_label($place_name_fr)
                : $place_name_fr;
            $current_geo   = ['type' => $try_level, 'slug' => sanitize_title($place_label)];
            $sections .= $this->render_section($try_level, (string) $place->{'name_' . $lang}, $term_id, $posts, $lang, $style, $current_geo, $hub);

            // Only the first section that renders carries the hub tile —
            // repeating "here is the guide that owns this article" under
            // every level would say the same thing three times.
            $hub = null;
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
    private function query_related(int $term_id, array $exclude_ids, int $limit): array {
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
            'post__not_in'        => array_values(array_filter($exclude_ids)),
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

    private function render_section(string $level, string $place_name, int $term_id, array $posts, string $lang, string $style, ?array $current_geo = null, ?\WP_Post $hub = null): string {
        // A hub on its own is still worth a section: it is the one link that
        // says "everything about this place lives here".
        if (empty($posts) && !$hub) {
            return '';
        }

        $strings    = self::STRINGS[$lang] ?? self::STRINGS['fr'];
        $is_cta     = 'cta' === $style;
        $is_compact = 'compact' === $style;

        $heading_format = $is_cta
            ? ($strings['cta_heading'][$level] ?? $strings['heading'])
            : $strings['heading'];

        // For country level, link the place name to its hub page if one exists in the current language.
        $hub_url      = 'country' === $level ? $this->country_hub_url( $current_geo, $lang ) : null;
        $place_markup = $hub_url
            ? '<a href="' . esc_url( $hub_url ) . '" class="geo-related__place-link">' . esc_html( $place_name ) . '</a>'
            : esc_html( $place_name );
        $heading_html = sprintf( esc_html( $heading_format ), $place_markup );

        $items      = '';
        $badge_seen = [];

        // The hub goes first: it is not the closest match, it is the page that
        // says it owns this article, and it answers "where does all of this
        // live?" before any single sibling can.
        if ($hub) {
            $items .= $is_compact
                ? $this->render_hub_list_item($hub, $lang)
                : $this->render_hub_tile($hub, $lang);
        }

        foreach ($posts as $post) {
            $items .= $is_compact ? $this->render_list_item($post) : $this->render_tile($post, $current_geo, $badge_seen);
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
            $heading_html,
            $list_tag,
            $list_class,
            $items,
            $list_tag,
            $see_all
        );
    }

    /**
     * Returns the permalink of the hub page for this country in the given language,
     * or null if no published page exists (avoids linking a DE/EN post to a French hub).
     *
     * Hub pages live at French slugs (e.g. /france/). For non-French languages,
     * Polylang is used to resolve the translated version; if no translation exists,
     * null is returned so the heading falls back to plain text.
     */
    private function country_hub_url( ?array $current_geo, string $lang = 'fr' ): ?string {
        $slug = $current_geo['slug'] ?? '';
        if ( '' === $slug ) {
            return null;
        }
        $page = get_page_by_path( $slug );
        if ( ! $page || 'publish' !== get_post_status( $page ) ) {
            return null;
        }
        // For non-French posts, require a Polylang translation in the current language.
        if ( 'fr' !== $lang && function_exists( 'pll_get_post' ) ) {
            $translated_id = (int) pll_get_post( $page->ID, $lang );
            if ( ! $translated_id || 'publish' !== get_post_status( $translated_id ) ) {
                return null;
            }
            return get_permalink( $translated_id ) ?: null;
        }
        return get_permalink( $page ) ?: null;
    }

    /**
     * The post's primary geographic hub as a WP_Post, or null.
     *
     * Mavo Hub Manager's procedural API is the contract — no hub meta is read
     * directly here. It stores relationships as raw meta and says plainly that
     * they "may be stale; validate before use", so what comes back is checked
     * three ways before a reader ever sees it: the hub still exists and is
     * published, it is still marked as a *geographic* hub, and it is in the
     * same language as the post. Cross-language links are never followed, not
     * even for a hub — a German post is not sent to a French guide.
     *
     * When Hub Manager is inactive this returns null and the sections render
     * exactly as they did before hubs existed.
     */
    private function resolve_geo_hub(int $post_id, string $lang): ?\WP_Post {
        if (!function_exists('mavo_get_primary_hub') || !function_exists('mavo_get_hub_type')) {
            return null;
        }

        $hub_id = (int) (mavo_get_primary_hub($post_id, 'geo') ?? 0);
        if (!$hub_id || $hub_id === $post_id) {
            return null;
        }

        $hub = get_post($hub_id);
        if (!$hub instanceof \WP_Post || 'publish' !== $hub->post_status) {
            return null;
        }

        if ('geo' !== mavo_get_hub_type($hub_id)) {
            return null;
        }

        if ($lang !== $this->get_lang($hub_id)) {
            return null;
        }

        return $hub;
    }

    /**
     * The label that marks a link as a hub — "Guide" (fr/en), "Übersicht"
     * (de). Taken from Mavo For You's config when that plugin is active so
     * the two blocks never drift apart in wording, with a local copy of the
     * same strings as the fallback.
     */
    private function hub_label(string $lang): string {
        if (class_exists('\MFY_Config') && method_exists('\MFY_Config', 'hub_labels')) {
            $labels = (array) \MFY_Config::hub_labels($lang);
            if (!empty($labels['geo'])) {
                return (string) $labels['geo'];
            }
        }

        $strings = self::STRINGS[$lang] ?? self::STRINGS['fr'];
        return (string) ($strings['hub_label'] ?? '');
    }

    /**
     * The hub tile. Same shape as a post tile — image, title, stretched link —
     * marked as a hub the way Mavo For You marks its own hub card: the warm
     * eyebrow above the title plus a hairline of emphasis down the left edge.
     * No badges: an editorial "this is the guide" and a row of status chips
     * are two different claims, and only the first one belongs here.
     */
    private function render_hub_tile(\WP_Post $hub, string $lang): string {
        $image = get_the_post_thumbnail($hub, 'medium_large', ['class' => 'geo-related__image', 'alt' => '']);
        $label = $this->hub_label($lang);

        return sprintf(
            '<div class="geo-related__tile geo-related__tile--hub">%s%s<span class="geo-related__title"><a class="geo-related__link" href="%s">%s</a></span></div>',
            $image ?: '',
            $label ? '<span class="geo-related__eyebrow">' . esc_html($label) . '</span>' : '',
            esc_url(get_permalink($hub)),
            esc_html(get_the_title($hub))
        );
    }

    /** The compact (text-link) equivalent, labelled the same way. */
    private function render_hub_list_item(\WP_Post $hub, string $lang): string {
        $label = $this->hub_label($lang);

        return sprintf(
            '<li class="geo-related__list-item geo-related__list-item--hub"><a href="%s">%s</a>%s</li>',
            esc_url(get_permalink($hub)),
            esc_html(get_the_title($hub)),
            $label ? '<span class="geo-related__hub-label">' . esc_html($label) . '</span>' : ''
        );
    }

    private function render_tile(\WP_Post $post, ?array $current_geo = null, array &$badge_seen = []): string {
        $image  = get_the_post_thumbnail($post, 'medium_large', ['class' => 'geo-related__image', 'alt' => '']);
        $badges = '';
        if ( function_exists('mv_get_tile_badges') ) {
            $badge_args = [
                'context'     => 'geo_hub',
                'limit'       => 2,
                'seen_labels' => $badge_seen,
            ];
            if ($current_geo) {
                $badge_args['current_geo'] = $current_geo;
            }
            $resolved = mv_get_tile_badges( $post->ID, $badge_args );
            mv_badges_update_seen( $badge_seen, $resolved );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $badges = mv_render_tile_badges( $resolved, $badge_args );
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
             . '.geo-related__tile{display:block;border-radius:var(--mv-tile-radius,14px);overflow:hidden;background:#fff;box-shadow:var(--mv-tile-shadow,0 8px 22px rgba(58,58,58,.08));position:relative;isolation:isolate;transition:transform 160ms ease,box-shadow 160ms ease}'
             . '.geo-related__tile:hover{transform:translateY(-2px);box-shadow:var(--mv-tile-shadow-hover,0 12px 30px rgba(58,58,58,.12))}'
             . '.geo-related__image{display:block;width:100%;aspect-ratio:3/2;object-fit:cover}'
             . '.geo-related__title{display:block;padding:.6em .75em;font-size:.9em;line-height:1.3}'
             . '.geo-related__link{color:inherit;text-decoration:none}'
             . '.geo-related__link::after{content:\'\';position:absolute;inset:0;z-index:0}'
             // Hub markers. The theme's own .mv-tile__eyebrow is the visual
             // vocabulary being reused here (warm brown, small, bold), but the
             // classes are local: mv-tiles.css is not enqueued on regular
             // posts, and this block has to look right on its own.
             . '.geo-related__tile--hub{box-shadow:var(--mv-tile-shadow,0 8px 22px rgba(58,58,58,.08)),inset 3px 0 0 var(--mv-color-warm,#886353)}'
             . '.geo-related__tile--hub:hover{box-shadow:var(--mv-tile-shadow-hover,0 12px 30px rgba(58,58,58,.12)),inset 3px 0 0 var(--mv-color-warm,#886353)}'
             . '.geo-related__eyebrow{display:block;padding:.6em .75em 0;color:var(--mv-color-warm,#886353);font-size:.78em;font-weight:700;letter-spacing:.01em;line-height:1.2}'
             . '.geo-related__eyebrow + .geo-related__title{padding-top:.25em}'
             . '.geo-related__hub-label{margin-left:.4em;font-size:.78em;font-weight:700;letter-spacing:.01em;color:var(--mv-color-warm,#886353);white-space:nowrap}'
             . '.geo-related__list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5em}'
             . '.geo-related__list-item a{text-decoration:none}'
             . '.geo-related__list-item a:hover{text-decoration:underline}'
             . '.geo-related__more{margin:1em 0 0;font-size:.9em}'
             . '.geo-related__place-link{color:inherit;text-decoration:underline;text-underline-offset:3px}'
             . '.geo-related__place-link:hover{opacity:.8}'
             . '.geo-related__tile .mv-tile__badges{position:relative;z-index:1;padding:.45em .75em .1em;gap:.3rem;margin:0}'
             . '.geo-related__tile .mv-badge{min-height:1.3rem;padding:.1rem .48rem;font-size:.72rem}';
    }
}
