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
        add_action('wp_ajax_geo_tagger_batch_run',   [$this, 'ajax_run_batch']);
        add_action('wp_ajax_geo_tagger_batch_count', [$this, 'ajax_get_count']);
        add_action('wp_ajax_geo_tagger_clear_cache', [$this, 'ajax_clear_cache']);
        add_action('wp_ajax_geo_tagger_test_nominatim', [$this, 'ajax_test_nominatim']);
    }

    public function ajax_get_count(): void {
        check_ajax_referer('geo_tagger_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }

        $total = $this->core->get_geo_mashup_db()->count_posts_with_location();
        wp_send_json_success(['total' => $total]);
    }

    public function ajax_run_batch(): void {
        check_ajax_referer('geo_tagger_nonce');
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
        check_ajax_referer('geo_tagger_nonce');
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

    public function ajax_test_nominatim(): void {
        check_ajax_referer('geo_tagger_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }

        // Paris: known-good test coordinates
        $result = $this->core->get_nominatim()->reverse_geocode(48.8566, 2.3522);

        if ($result) {
            wp_send_json_success(['message' => 'Nominatim reachable. Paris: ' . ($result['en']['address']['country'] ?? '(no country)')]);
        } else {
            wp_send_json_error(['message' => 'Nominatim request failed. Check error_log for details.']);
        }
    }
}
