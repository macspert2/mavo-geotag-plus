<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class Core {

    /**
     * Single-event cron hook that does the actual tagging, one post per
     * event. See schedule_tagging() for why the work is deferred.
     */
    public const CRON_HOOK = 'geo_tagger_tag_post';

    /**
     * Seconds between the save and the scheduled run. Long enough for the
     * editor's several save requests to settle — and, importantly, for Geo
     * Mashup's own location write, which can land in a later request than the
     * save_post that queued this, so an immediate run risks reading a
     * location that isn't there yet.
     */
    private const CRON_DELAY = 30;

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

    /**
     * Geo term ids a post carried when the current request started, keyed by
     * post id. Captured on pre_post_update, consumed on save_post. See
     * remember_geo_tags() for why.
     */
    private array $preserved_geo_tags = [];

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
        add_action('pre_post_update',           [$this, 'remember_geo_tags'], 10, 1);
        add_action('save_post',                 [$this, 'on_save_post'], 20, 2);
        add_action('geo_mashup_location_saved', [$this, 'on_geo_mashup_location_saved'], 10, 3);
        add_action(self::CRON_HOOK,             [$this, 'run_scheduled_tagging'], 10, 1);
    }

    public function on_save_post(int $post_id, \WP_Post $post): void {
        if (wp_is_post_autosave($post_id))  return;
        if (wp_is_post_revision($post_id))  return;
        if (!$this->is_taggable($post))     return;

        $this->restore_geo_tags($post_id);
        $this->tag_now_or_schedule($post_id);
    }

    public function on_geo_mashup_location_saved(int $location_id, string $object_name, int $object_id): void {
        if ($object_name !== 'post') return;

        $post = get_post($object_id);
        if (!$post instanceof \WP_Post) return;
        if (!$this->is_taggable($post))  return;

        // Geo Mashup has just written the location row, so unlike save_post
        // this hook is guaranteed to see it — which is exactly what the fast
        // path needs to run inline.
        $this->tag_now_or_schedule($object_id);
    }

    /**
     * Records which geo tags the post has before this request can drop them.
     *
     * The classic editor round-trips the tag list through a
     * tax_input[post_tag] form field rendered when the edit screen loaded,
     * and wp_insert_post() applies it with $append = false — a replace, not
     * an add. Any geo tag attached after that render (by cron, or inline
     * during an earlier save) is therefore deleted by the next save, and a
     * stale edit screen keeps resubmitting the same tagless list, so the
     * tags never survive long enough to be drawn.
     *
     * pre_post_update fires inside wp_insert_post() before that tax_input
     * block, which makes it the last point at which the pre-request truth is
     * still readable. Working from term ids taken from the database — rather
     * than filtering the submitted names — also sidesteps Polylang, where
     * one name can exist as a different term per language.
     */
    public function remember_geo_tags(int $post_id): void {
        unset($this->preserved_geo_tags[$post_id]);

        // pre_post_update fires for every post type; get_post() still returns
        // the pre-update row here, so this is the cheap way to avoid three
        // lookups on every attachment and CPT write on the site.
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return;
        }
        if (!in_array($post->post_type, ['post', 'page'], true)) {
            return;
        }

        $term_ids = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'ids']);
        if (is_wp_error($term_ids) || !$term_ids) {
            return;
        }

        $geo = $this->place_repo->filter_geo_term_ids($term_ids);
        if ($geo) {
            $this->preserved_geo_tags[$post_id] = $geo;
        }
    }

    /**
     * Re-attaches any geo tag the save dropped.
     *
     * Appends, so it restores what was lost without disturbing tags the
     * editor legitimately added in the same request. A no-op when tax_input
     * was not submitted at all (an autosave, or the Tags box hidden via
     * Screen Options), because nothing was removed.
     *
     * This deliberately reinstates a geo tag removed by hand in the editor.
     * That matches what the plugin already does — attach_from_place_chain()
     * re-attaches the whole chain on every save — and the place to sever a
     * post from a location is its Geo Mashup location, not its tag list.
     */
    private function restore_geo_tags(int $post_id): void {
        if (empty($this->preserved_geo_tags[$post_id])) {
            return;
        }

        $preserved = $this->preserved_geo_tags[$post_id];
        unset($this->preserved_geo_tags[$post_id]);

        $current = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'ids']);
        $current = is_wp_error($current) ? [] : array_map('intval', $current);

        $missing = array_values(array_diff($preserved, $current));
        if (!$missing) {
            return;
        }

        wp_set_post_terms($post_id, $missing, 'post_tag', true);
    }

    /**
     * Shared eligibility test for both entry points and for the cron
     * callback, which re-runs it because the post can change during the
     * delay window.
     *
     * 'auto-draft' is the empty shell WordPress creates the moment you click
     * Add New. It fires save_post with no content and no location, so
     * queueing it only burns the event on a guaranteed no-op — and, because
     * schedule_tagging() treats a pending event as "already handled", that
     * wasted event used to suppress the queueing of the real save behind it.
     */
    private function is_taggable(\WP_Post $post): bool {
        if (in_array($post->post_status, ['auto-draft', 'trash'], true)) {
            return false;
        }

        return in_array($post->post_type, ['post', 'page'], true);
    }

    /**
     * Tags inline when that costs nothing, and defers only when it doesn't.
     *
     * Deferring exists to keep three blocking Nominatim requests (one per
     * language, 10s timeout each, ~1.1s of rate-limit sleep between them) out
     * of the editor's save request. That cost is real, but it belongs solely
     * to the slow path: once a coordinate is in the coord index, tagging is a
     * handful of primary-key lookups and one wp_set_post_terms() call, with no
     * HTTP at all. Deferring that bought nothing and cost the editor its only
     * feedback — the Tags metabox is rendered when the edit screen loads, so
     * terms attached by a later cron run stay invisible until a manual reload,
     * which reads exactly like tagging having silently failed.
     *
     * So: try the fast path here, and fall through to cron only for
     * coordinates that genuinely need geocoding.
     */
    private function tag_now_or_schedule(int $post_id): void {
        if ($this->try_immediate_tagging($post_id)) {
            // Nothing left for cron to do; drop any event queued by an
            // earlier save so it doesn't re-run the same work.
            $this->unschedule_tagging($post_id);
            return;
        }

        $this->schedule_tagging($post_id);
    }

    /**
     * Runs the no-HTTP tagging path, or reports that it isn't available.
     *
     * Returns false — leaving the caller to queue a cron event — when the
     * location or post language isn't readable yet (save_post can fire before
     * Geo Mashup writes its row) or when the coordinates have never been
     * geocoded, which is the one case that needs Nominatim.
     */
    private function try_immediate_tagging(int $post_id): bool {
        $ctx = $this->resolve_location($post_id);
        if (!$ctx) {
            return false;
        }

        $leaf_place_id = $this->place_repo->find_coord($ctx['hash']);
        if (!$leaf_place_id) {
            return false;
        }

        $this->tag_manager->attach_from_place_chain($post_id, $leaf_place_id, $ctx['lang']);
        $this->breadcrumb->sync_post_cache($post_id);

        return true;
    }

    /**
     * Queues one tagging run for $post_id.
     *
     * Deduplication happens across requests here (the old
     * GEO_TAGGER_PROCESSING constant only ever deduplicated within a single
     * PHP request, which is useless against the editor's separate REST
     * calls) — but only a genuinely *future* event counts as "already
     * handled". A timestamp in the past is not proof that a run is coming:
     * with DISABLE_WP_CRON set and a system cron that never fires, or after a
     * fatal in an earlier run, the entry sits in the cron array for ever, and
     * treating it as pending would silently suppress every later save's
     * retry. Such an event is cleared and re-queued instead, which also gets
     * past core's refusal to schedule a duplicate hook+args event within ten
     * minutes of an existing one.
     */
    private function schedule_tagging(int $post_id): void {
        $args = [$post_id];
        $next = wp_next_scheduled(self::CRON_HOOK, $args);

        if ($next && $next > time()) {
            return;
        }

        if ($next) {
            wp_unschedule_event($next, self::CRON_HOOK, $args);
        }

        $delay = (int) apply_filters('geo_tagger_cron_delay', self::CRON_DELAY, $post_id);

        wp_schedule_single_event(time() + $delay, self::CRON_HOOK, $args);
    }

    /**
     * Drops every queued tagging event for $post_id. Bounded rather than a
     * while loop: wp_unschedule_event() can fail, and spinning on an entry
     * that refuses to clear would hang the save request.
     */
    private function unschedule_tagging(int $post_id): void {
        $args = [$post_id];

        for ($i = 0; $i < 5; $i++) {
            $next = wp_next_scheduled(self::CRON_HOOK, $args);
            if (!$next) {
                return;
            }
            if (!wp_unschedule_event($next, self::CRON_HOOK, $args)) {
                return;
            }
        }
    }

    /**
     * Cron callback. The post may have been trashed, deleted or had its
     * location removed during the delay window, so the eligibility checks are
     * re-run here rather than trusted from scheduling time; tag_single_post()
     * itself already returns early when there is no location left to resolve.
     */
    public function run_scheduled_tagging(int $post_id): void {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) return;
        if (!$this->is_taggable($post))  return;

        $this->tag_single_post($post_id);
    }

    /**
     * Reads the three things every tagging path needs, or null if any of them
     * isn't available yet. Shared so the inline and cron paths derive the
     * coordinate hash identically — it is an md5 of the float-to-string form
     * of the coordinates, so two spellings of the same computation would index
     * the same spot under two different hashes.
     *
     * @return array{lang:string,lat:float,lng:float,hash:string}|null
     */
    private function resolve_location(int $post_id): ?array {
        $location = $this->geo_mashup_db->get_location_for_post($post_id);
        if (!$location || empty($location->lat) || empty($location->lng)) {
            return null;
        }

        $lang = $this->polylang->get_post_language($post_id);
        if (!$lang) {
            return null;
        }

        $lat = (float) $location->lat;
        $lng = (float) $location->lng;

        return [
            'lang' => $lang,
            'lat'  => $lat,
            'lng'  => $lng,
            'hash' => md5("{$lat},{$lng}"),
        ];
    }

    public function tag_single_post(int $post_id): array {
        $ctx = $this->resolve_location($post_id);
        if (!$ctx) {
            return [];
        }

        // Fast path: coordinates already resolved to a place node
        $leaf_place_id = $this->place_repo->find_coord($ctx['hash']);
        if ($leaf_place_id) {
            $summary = $this->tag_manager->attach_from_place_chain($post_id, $leaf_place_id, $ctx['lang']);
        } else {
            // Slow path: geocode (transient cache → Nominatim)
            $geo_data = $this->nominatim->reverse_geocode($ctx['lat'], $ctx['lng']);
            if (!$geo_data) {
                return [];
            }
            $summary = $this->tag_manager->apply_geo_tags($post_id, $geo_data, $ctx['lang'], $ctx['hash']);
        }

        // Only rewrites cached breadcrumb postmeta when the resolved location
        // actually changed — preserves any manual link edits otherwise.
        $this->breadcrumb->sync_post_cache($post_id);

        return $summary;
    }

    /**
     * Removes the coord_index entry for a post's location so the next call to
     * tag_single_post() takes the slow path and re-applies any missing tags.
     * Returns false when there is nothing to clear — no Geo Mashup location,
     * or no post language, which are the same two conditions that would make
     * the re-tag it is clearing for a no-op anyway.
     */
    public function clear_coord_for_post(int $post_id): bool {
        $ctx = $this->resolve_location($post_id);
        if (!$ctx) {
            return false;
        }
        $this->place_repo->delete_coord($ctx['hash']);
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
