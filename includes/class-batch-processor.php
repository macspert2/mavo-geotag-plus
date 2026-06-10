<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class BatchProcessor {

    private const BATCH_SIZE = 10;

    private Core $core;

    public function __construct(Core $core) {
        $this->core = $core;
    }

    public function init(): void {
        add_action('wp_ajax_geo_tagger_batch_run',      [$this, 'ajax_run_batch']);
        add_action('wp_ajax_geo_tagger_batch_count',    [$this, 'ajax_get_count']);
        add_action('wp_ajax_geo_tagger_clear_cache',    [$this, 'ajax_clear_cache']);
        add_action('wp_ajax_geo_tagger_test_nominatim', [$this, 'ajax_test_nominatim']);
        add_action('wp_ajax_geo_tagger_process_single', [$this, 'ajax_process_single']);
    }

    public function ajax_get_count(): void {
        check_ajax_referer('geo_tagger_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }

        $total = $this->core->get_geo_mashup_db()->count_posts_with_location();
        wp_send_json_success(['total' => $total]);
    }

    public function ajax_run_batch(): void {
        check_ajax_referer('geo_tagger_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }

        $offset = absint($_POST['offset'] ?? 0);
        $db     = $this->core->get_geo_mashup_db();
        $total  = $db->count_posts_with_location();
        $ids    = $db->get_post_ids_with_location($offset, self::BATCH_SIZE);
        $log    = [];

        foreach ($ids as $post_id) {
            $post    = get_post($post_id);
            $summary = $this->core->tag_single_post($post_id);
            $log[]   = [
                'post_id' => $post_id,
                'title'   => $post ? get_the_title($post) : "Post #{$post_id}",
                'added'   => $summary['added']   ?? [],
                'skipped' => $summary['skipped'] ?? [],
                'errors'  => $summary['errors']  ?? [],
            ];
        }

        $processed = count($ids);
        $new_offset = $offset + $processed;

        wp_send_json_success([
            'processed' => $processed,
            'offset'    => $new_offset,
            'total'     => $total,
            'done'      => $new_offset >= $total || $processed === 0,
            'log'       => $log,
        ]);
    }

    public function ajax_clear_cache(): void {
        check_ajax_referer('geo_tagger_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }

        global $wpdb;
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_geo_tagger_nom_%'
                OR option_name LIKE '_transient_timeout_geo_tagger_nom_%'"
        );

        wp_send_json_success(['deleted' => (int)$deleted]);
    }

    public function ajax_process_single(): void {
        check_ajax_referer('geo_tagger_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => 'Please enter a valid post ID.']);
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => "No post found with ID {$post_id}."]);
            return;
        }

        // Provide specific feedback for each failure mode rather than a silent empty result
        $location = $this->core->get_geo_mashup_db()->get_location_for_post($post_id);
        if (!$location || empty($location->lat) || empty($location->lng)) {
            wp_send_json_success([
                'post_id' => $post_id,
                'title'   => get_the_title($post),
                'note'    => 'No Geo Mashup location found for this post.',
                'added' => [], 'skipped' => [], 'errors' => [],
            ]);
            return;
        }

        $lang = function_exists('pll_get_post_language') ? pll_get_post_language($post_id) : null;
        if (!$lang) {
            wp_send_json_success([
                'post_id'  => $post_id,
                'title'    => get_the_title($post),
                'location' => ['lat' => $location->lat, 'lng' => $location->lng, 'city' => $location->city ?? ''],
                'note'     => 'No Polylang language assigned to this post.',
                'added' => [], 'skipped' => [], 'errors' => [],
            ]);
            return;
        }

        $summary = $this->core->tag_single_post($post_id);

        wp_send_json_success([
            'post_id'  => $post_id,
            'title'    => get_the_title($post),
            'lang'     => $lang,
            'location' => ['lat' => $location->lat, 'lng' => $location->lng, 'city' => $location->city ?? ''],
            'note'     => null,
            'added'    => $summary['added']   ?? [],
            'skipped'  => $summary['skipped'] ?? [],
            'errors'   => $summary['errors']  ?? [],
        ]);
    }

    public function ajax_test_nominatim(): void {
        check_ajax_referer('geo_tagger_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }

        // Paris: known-good test coordinates — use probe() so we see the raw response
        $probe = $this->core->get_nominatim()->probe(48.8566, 2.3522);

        if ($probe['code'] === 200) {
            $data    = json_decode($probe['body'], true);
            $country = $data['address']['country'] ?? '(no country in response)';
            wp_send_json_success(['message' => "HTTP 200 — Paris resolved as: {$country}"]);
        } else {
            $preview = mb_substr(strip_tags($probe['body']), 0, 300);
            wp_send_json_error(['message' => "HTTP {$probe['code']} — {$preview}"]);
        }
    }
}
