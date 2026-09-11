<?php
/**
 * Plugin Name: MaVo GeoTag Plus
 * Description: Automatically adds multilingual geographic tags to posts with Geo Mashup locations.
 * Version: 1.0.41
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('GEO_TAGGER_DIR', plugin_dir_path(__FILE__));
define('GEO_TAGGER_VERSION', '1.0.41');

spl_autoload_register(function (string $class): void {
    $map = [
        'GeoTagger\\Core'            => 'includes/class-geo-tagger-core.php',
        'GeoTagger\\GeoMashupDB'     => 'includes/class-geo-mashup-db.php',
        'GeoTagger\\NominatimClient' => 'includes/class-nominatim-client.php',
        'GeoTagger\\GeoHierarchy'    => 'includes/class-geo-hierarchy.php',
        'GeoTagger\\TagManager'      => 'includes/class-tag-manager.php',
        'GeoTagger\\PolylangBridge'  => 'includes/class-polylang-bridge.php',
        'GeoTagger\\BatchProcessor'  => 'includes/class-batch-processor.php',
        'GeoTagger\\PlaceRepository' => 'includes/class-place-repository.php',
        'GeoTagger\\GeoBreadcrumb'        => 'includes/class-geo-breadcrumb.php',
        'GeoTagger\\SearchHierarchy'      => 'includes/class-search-hierarchy.php',
        'GeoTagger\\AdminPage'            => 'admin/class-admin-page.php',
        'GeoTagger\\DuplicateTagManager'  => 'admin/class-duplicate-tag-manager.php',
        'GeoTagger\\PlaceEditor'          => 'admin/class-place-editor.php',
    ];
    if (isset($map[$class])) {
        require_once GEO_TAGGER_DIR . $map[$class];
    }
});

register_activation_hook(__FILE__, function (): void {
    GeoTagger\PlaceRepository::install();
});

// Tagging runs on a single-event cron hook (see Core::schedule_tagging()), so
// any post saved shortly before deactivation would otherwise leave a pending
// event pointing at a hook nothing listens to any more.
register_deactivation_hook(__FILE__, function (): void {
    wp_unschedule_hook(GeoTagger\Core::CRON_HOOK);
});

add_action('plugins_loaded', function (): void {
    if (!class_exists('GeoMashupDB') || !function_exists('pll_get_post_language')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>Geo Tagger requires both <strong>Geo Mashup</strong> and <strong>Polylang</strong> to be active.</p></div>';
        });
        return;
    }

    $core = new GeoTagger\Core();
    $core->init();

    $place_repo = new GeoTagger\PlaceRepository();

    $breadcrumb = new GeoTagger\GeoBreadcrumb($place_repo);
    $breadcrumb->init();

    $search_hierarchy = new GeoTagger\SearchHierarchy($place_repo);
    $search_hierarchy->init();

    // Store instances so the global helper functions can access them
    $GLOBALS['geo_tagger_breadcrumb']       = $breadcrumb;
    $GLOBALS['geo_tagger_search_hierarchy'] = $search_hierarchy;

    if (is_admin()) {
        (new GeoTagger\AdminPage($core))->init();
    }
});

/**
 * Returns the geographic breadcrumb HTML for a post.
 *
 * Displays: [globe icon] › Continent › Country › Region › City
 * The globe links to the travel index page.
 * Continent/Country/Region link to their tag archive pages.
 * City is only included when more than one post shares that city tag.
 *
 * The HTML (and the JSON-LD output in <head>) is read verbatim from the
 * '_geo_breadcrumb_html' / '_geo_breadcrumb_json' postmeta — it is computed
 * once when the post is geo-tagged (see Core::tag_single_post()) and only
 * recomputed if the resolved location later changes. Edit those postmeta
 * values directly to override individual links without losing them on rerun.
 *
 * Usage in templates:   echo geo_tagger_breadcrumb( get_the_ID() );
 * Usage as shortcode:   [geo_breadcrumb]  or  [geo_breadcrumb post_id="123"]
 *
 * @param int $post_id  Post ID. Defaults to the current post in the loop.
 * @return string       HTML <nav> string, or empty string if no cached breadcrumb.
 */
function geo_tagger_breadcrumb(int $post_id = 0): string {
    $instance = $GLOBALS['geo_tagger_breadcrumb'] ?? null;
    return $instance ? $instance->render($post_id) : '';
}

/**
 * Returns the geographic breadcrumb HTML for a post_tag archive page, when that
 * tag corresponds to a node in the geo_tagger_places hierarchy (continent, country,
 * region or city tags created by this plugin). Returns '' for ordinary tags.
 *
 * Displays: Home › [globe icon] › Continent › Country › Region › City(current)
 * The tag currently being viewed is shown as plain (non-linked) text; every
 * ancestor above it links to its own tag archive.
 *
 * The HTML (and the JSON-LD output in <head>) is cached in termmeta on first view
 * and read verbatim after that (see GeoBreadcrumb::sync_term_cache()). Edit the
 * '_geo_breadcrumb_html' / '_geo_breadcrumb_json' termmeta directly on the term to
 * override individual links without losing them on the next view.
 *
 * Usage in a taxonomy/archive template:
 *     echo geo_tagger_term_breadcrumb( get_queried_object_id() );
 * or with no args, inside the tag archive loop, it defaults to the queried term.
 *
 * @param int $term_id  post_tag term ID. Defaults to the currently queried term.
 * @return string       HTML <nav> string, or empty string if this tag isn't a geo place.
 */
function geo_tagger_term_breadcrumb(int $term_id = 0): string {
    $instance = $GLOBALS['geo_tagger_breadcrumb'] ?? null;
    return $instance ? $instance->render_term($term_id) : '';
}

/**
 * "Other articles from the same place" moved to the mavo-for-you plugin.
 *
 * The block it rendered has been replaced by that plugin's [geo_related],
 * which scores the same material — hub, geographic siblings, travel-finder
 * siblings — with the recommendation engine rather than a tag query, and is
 * swapped for a personalized block once the visitor has read enough.
 *
 * mavo-for-you registers [geo_related] and keeps geo_tagger_related_posts() as
 * a shim, so existing post content and any template call still work.
 *
 * This plugin had come to depend on mavo-for-you's hub labels to keep the two
 * blocks' wording in step; the move removes that inversion. Place data stays
 * here, scoring lives there.
 */

/**
 * For the search results page: if the search term matches a known
 * place name (a LIKE match against name_{lang} in geo_tagger_places),
 * returns links to articles in that place's city, region, and country
 * — useful since the literal search text might not appear in any
 * post's title/content at all (e.g. searching "Londres" finds the
 * city tag archive even for posts that never spell the city name out).
 *
 * Requires at least 3 characters in $term; returns '' below that, if
 * no place matches, or if none of the matched place's levels have
 * more than 1 post to link to.
 *
 * Usage: echo geo_tagger_search_hierarchy( get_search_query(), $lang );
 *
 * @param string $term Free-text search term.
 * @param string $lang 'fr' | 'en' | 'de'.
 * @return string HTML, or empty string if there's nothing to show.
 */
function geo_tagger_search_hierarchy(string $term, string $lang): string {
    $instance = $GLOBALS['geo_tagger_search_hierarchy'] ?? null;
    return $instance ? $instance->render($term, $lang) : '';
}
