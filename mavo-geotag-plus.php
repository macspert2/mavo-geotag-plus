<?php
/**
 * Plugin Name: MaVo GeoTag Plus
 * Description: Automatically adds multilingual geographic tags to posts with Geo Mashup locations.
 * Version: 1.0.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('GEO_TAGGER_DIR', plugin_dir_path(__FILE__));
define('GEO_TAGGER_VERSION', '1.0.2');

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
        'GeoTagger\\AdminPage'       => 'admin/class-admin-page.php',
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

    if (is_admin()) {
        (new GeoTagger\AdminPage($core))->init();
    }
});
