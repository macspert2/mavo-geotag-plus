<?php
/**
 * Plugin Name: MaVo GeoTag Plus
 * Description: Automatically adds multilingual geographic tags to posts with Geo Mashup locations.
 * Version: 1.0.22
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('GEO_TAGGER_DIR', plugin_dir_path(__FILE__));
define('GEO_TAGGER_VERSION', '1.0.22');

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

    $breadcrumb = new GeoTagger\GeoBreadcrumb(new GeoTagger\PlaceRepository());
    $breadcrumb->init();

    // Store instance so the global helper function can access it
    $GLOBALS['geo_tagger_breadcrumb'] = $breadcrumb;

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
 * Usage in templates:   echo geo_tagger_breadcrumb( get_the_ID() );
 * Usage as shortcode:   [geo_breadcrumb]  or  [geo_breadcrumb post_id="123"]
 *
 * @param int $post_id  Post ID. Defaults to the current post in the loop.
 * @return string       HTML <nav> string, or empty string if no geo data.
 */
function geo_tagger_breadcrumb(int $post_id = 0): string {
    $instance = $GLOBALS['geo_tagger_breadcrumb'] ?? null;
    return $instance ? $instance->render($post_id) : '';
}
