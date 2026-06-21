<?php
/**
 * Plugin Name: MaVo GeoTag Plus
 * Description: Automatically adds multilingual geographic tags to posts with Geo Mashup locations.
 * Version: 1.0.30
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('GEO_TAGGER_DIR', plugin_dir_path(__FILE__));
define('GEO_TAGGER_VERSION', '1.0.30');

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
        'GeoTagger\\RelatedPosts'         => 'includes/class-related-posts.php',
        'GeoTagger\\AdminPage'            => 'admin/class-admin-page.php',
        'GeoTagger\\DuplicateTagManager'  => 'admin/class-duplicate-tag-manager.php',
    ];
    if (isset($map[$class])) {
        require_once GEO_TAGGER_DIR . $map[$class];
    }
});

register_activation_hook(__FILE__, function (): void {
    GeoTagger\PlaceRepository::install();
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

    $related_posts = new GeoTagger\RelatedPosts($place_repo);
    $related_posts->init();

    // Store instances so the global helper functions can access them
    $GLOBALS['geo_tagger_breadcrumb']    = $breadcrumb;
    $GLOBALS['geo_tagger_related_posts'] = $related_posts;

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
 * Returns "other articles from the same place" tiles for a post — a CTA
 * to help the reader prepare their trip. Auto-cascades city → region →
 * country, showing the first level with at least 3 other posts, unless
 * $level forces a specific one.
 *
 * Usage in templates (e.g. content-single.php, for every geo-tagged post
 * automatically — no shortcode needed in the post content):
 *     echo geo_tagger_related_posts( get_the_ID() );
 *     echo geo_tagger_related_posts( get_the_ID(), 'city', 'cta' );
 *
 * Usage as shortcode:
 *     [geo_related]
 *     [geo_related level="city" style="cta" limit="4"]
 *
 * @param int         $post_id  Post ID. Defaults to the current post in the loop.
 * @param string|null $level    'city' | 'region' | 'country', or null to auto-cascade.
 * @param string      $style    'plain' (default) | 'cta' | 'compact' (text links, no images).
 * @param int         $limit    Max number of related posts to show.
 * @return string     HTML, or empty string if there's nothing to show.
 */
function geo_tagger_related_posts(int $post_id = 0, ?string $level = null, string $style = 'plain', int $limit = 6): string {
    $instance = $GLOBALS['geo_tagger_related_posts'] ?? null;
    return $instance ? $instance->render($post_id, $level, $style, $limit) : '';
}

/**
 * Same as geo_tagger_related_posts(), but stacks a separate section for
 * every level (city, region, country) that has at least 3 other posts,
 * most specific first, instead of stopping at the first one that qualifies.
 *
 * Usage in templates:   echo geo_tagger_related_posts_full( get_the_ID() );
 * Usage as shortcode:   [geo_related_full]  or  [geo_related_full style="cta"]
 *
 * @param int    $post_id  Post ID. Defaults to the current post in the loop.
 * @param string $style    'plain' (default) | 'cta' | 'compact'.
 * @param int    $limit    Max number of related posts per section.
 * @return string HTML, or empty string if there's nothing to show.
 */
function geo_tagger_related_posts_full(int $post_id = 0, string $style = 'plain', int $limit = 6): string {
    $instance = $GLOBALS['geo_tagger_related_posts'] ?? null;
    return $instance ? $instance->render_full($post_id, $style, $limit) : '';
}
