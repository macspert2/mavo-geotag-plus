<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class GeoMashupDB {

    public function get_location_for_post(int $post_id): ?object {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT l.*
                 FROM {$wpdb->prefix}geo_mashup_locations l
                 INNER JOIN {$wpdb->prefix}geo_mashup_location_relationships r ON l.id = r.location_id
                 WHERE r.object_name = 'post'
                   AND r.object_id = %d
                 LIMIT 1",
                $post_id
            )
        );

        return $row ?: null;
    }

    public function get_post_ids_with_location(int $offset = 0, int $limit = 50): array {
        global $wpdb;

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT r.object_id
                 FROM {$wpdb->prefix}geo_mashup_location_relationships r
                 INNER JOIN {$wpdb->prefix}geo_mashup_locations l ON l.id = r.location_id
                 WHERE r.object_name = 'post'
                   AND l.lat IS NOT NULL
                   AND l.lng IS NOT NULL
                 ORDER BY r.object_id ASC
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        return array_map('intval', $rows ?: []);
    }

    public function count_posts_with_location(): int {
        global $wpdb;

        $count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT r.object_id)
             FROM {$wpdb->prefix}geo_mashup_location_relationships r
             INNER JOIN {$wpdb->prefix}geo_mashup_locations l ON l.id = r.location_id
             WHERE r.object_name = 'post'
               AND l.lat IS NOT NULL
               AND l.lng IS NOT NULL"
        );

        return (int) $count;
    }
}
