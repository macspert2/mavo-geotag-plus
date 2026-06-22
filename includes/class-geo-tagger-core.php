<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class Core {

    public const DEFAULT_SETTINGS = [
        'user_agent'         => '',
        'cache_days'         => 30,
        'rate_limit_ms'      => 1100,
        'continent_tags'     => true,
        'min_depth'          => 'city',
        'region_countries'   => [],
    ];

    private GeoMashupDB     $geo_mashup_db;
    private NominatimClient $nominatim;
    private GeoHierarchy    $geo_hierarchy;
    private TagManager      $tag_manager;
    private PolylangBridge  $polylang;
    private PlaceRepository $place_repo;
    private GeoBreadcrumb   $breadcrumb;
    private array           $settings;

    public function __construct() {
        $saved          = get_option('geo_tagger_settings', []);
        $this->settings = wp_parse_args($saved, self::DEFAULT_SETTINGS);

        if (empty($this->settings['user_agent'])) {
            $this->settings['user_agent'] = 'GeoTagger/1.0 (' . home_url() . ')';
        }

        $this->polylang      = new PolylangBridge();
        $this->geo_mashup_db = new GeoMashupDB();
        $this->nominatim     = new NominatimClient($this->settings);
        $this->geo_hierarchy = new GeoHierarchy();
        $this->place_repo    = new PlaceRepository();
        $this->tag_manager   = new TagManager($this->geo_hierarchy, $this->polylang, $this->place_repo);
        $this->breadcrumb    = new GeoBreadcrumb($this->place_repo);
    }

    public function init(): void {
        add_action('save_post',                 [$this, 'on_save_post'], 20, 2);
        add_action('geo_mashup_location_saved', [$this, 'on_geo_mashup_location_saved'], 10, 3);
    }

    public function on_save_post(int $post_id, \WP_Post $post): void {
        if (wp_is_post_autosave($post_id))  return;
        if (wp_is_post_revision($post_id))  return;
        if ($post->post_status === 'trash') return;
        if (!in_array($post->post_type, ['post', 'page'], true)) return;
        if (defined('GEO_TAGGER_PROCESSING')) return;

        define('GEO_TAGGER_PROCESSING', true);
        $this->tag_single_post($post_id);
    }

    public function on_geo_mashup_location_saved(int $location_id, string $object_name, int $object_id): void {
        if ($object_name !== 'post') return;
        if (defined('GEO_TAGGER_PROCESSING')) return;

        define('GEO_TAGGER_PROCESSING', true);
        $this->tag_single_post($object_id);
    }

    public function tag_single_post(int $post_id): array {
        $location = $this->geo_mashup_db->get_location_for_post($post_id);
        if (!$location || empty($location->lat) || empty($location->lng)) {
            return [];
        }

        $lang = $this->polylang->get_post_language($post_id);
        if (!$lang) {
            return [];
        }

        $lat  = (float) $location->lat;
        $lng  = (float) $location->lng;
        $hash = md5("{$lat},{$lng}");

        // Fast path: coordinates already resolved to a place node
        $leaf_place_id = $this->place_repo->find_coord($hash);
        if ($leaf_place_id) {
            $summary = $this->tag_manager->attach_from_place_chain($post_id, $leaf_place_id, $lang);
        } else {
            // Slow path: geocode (transient cache → Nominatim)
            $geo_data = $this->nominatim->reverse_geocode($lat, $lng);
            if (!$geo_data) {
                return [];
            }
            $summary = $this->tag_manager->apply_geo_tags($post_id, $geo_data, $lang, $hash);
        }

        // Only rewrites cached breadcrumb postmeta when the resolved location
        // actually changed — preserves any manual link edits otherwise.
        $this->breadcrumb->sync_post_cache($post_id);

        return $summary;
    }

    /**
     * Removes the coord_index entry for a post's location so the next call to
     * tag_single_post() takes the slow path and re-applies any missing tags.
     * Returns false if the post has no Geo Mashup location.
     */
    public function clear_coord_for_post(int $post_id): bool {
        $location = $this->geo_mashup_db->get_location_for_post($post_id);
        if (!$location || empty($location->lat) || empty($location->lng)) {
            return false;
        }
        $hash = md5((float) $location->lat . ',' . (float) $location->lng);
        $this->place_repo->delete_coord($hash);
        return true;
    }

    public function get_geo_mashup_db(): GeoMashupDB {
        return $this->geo_mashup_db;
    }

    public function get_nominatim(): NominatimClient {
        return $this->nominatim;
    }

    public function get_settings(): array {
        return $this->settings;
    }

    public function get_place_repo(): PlaceRepository {
        return $this->place_repo;
    }
}
