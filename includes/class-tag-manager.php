<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class TagManager {

    private GeoHierarchy   $hierarchy;
    private PolylangBridge $polylang;
    private PlaceRepository $place_repo;

    public function __construct(GeoHierarchy $hierarchy, PolylangBridge $polylang, PlaceRepository $place_repo) {
        $this->hierarchy  = $hierarchy;
        $this->polylang   = $polylang;
        $this->place_repo = $place_repo;
    }

    /**
     * Slow path: builds/finds the full place hierarchy, creates missing WP terms,
     * stores the coord→place mapping, and attaches the post-language tags.
     *
     * @return array  ['added' => [...], 'skipped' => [...], 'errors' => [...]]
     */
    public function apply_geo_tags(
        int    $post_id,
        array  $nominatim_data,
        string $post_lang,
        string $lat_lng_hash = ''
    ): array {
        $languages = $this->polylang->get_active_languages();
        $summary   = ['added' => [], 'skipped' => [], 'errors' => []];

        $world_id = $this->place_repo->get_world_id();
        if ($world_id === null) {
            error_log('Geo Tagger: World root node missing — was the plugin deactivated/reactivated?');
            return $summary;
        }

        $level_map     = $this->build_level_name_map($nominatim_data, $languages);
        $parent_id     = $world_id;
        $leaf_place_id = null;

        foreach (['continent', 'country', 'region', 'county', 'city'] as $level) {
            if (!isset($level_map[$level])) {
                continue;
            }

            $names        = $level_map[$level]['names'];
            $country_code = $level_map[$level]['country_code'];

            // 1. Find or create the place node in the persistent hierarchy
            $place = $this->place_repo->find_place($parent_id, $level, $names);
            if (!$place) {
                $new_id = $this->place_repo->create_place([
                    'parent_id'    => $parent_id,
                    'level'        => $level,
                    'name_fr'      => $names['fr'] ?? null,
                    'name_en'      => $names['en'] ?? null,
                    'name_de'      => $names['de'] ?? null,
                    'country_code' => $country_code ?: null,
                ]);
                if (!$new_id) {
                    error_log("Geo Tagger: Could not create place node for level={$level}");
                    continue;
                }
                $place = $this->place_repo->get_place($new_id);
            }

            if (!$place) {
                continue;
            }

            // 2. Create WP terms for any language not yet linked on this place node
            $new_term_ids = [];
            foreach ($languages as $lang) {
                $name = $names[$lang] ?? null;
                if (!$name) {
                    continue;
                }
                $col = 'term_id_' . $lang;
                if (empty($place->$col)) {
                    $term_id = $this->find_or_create_term($name, $lang, $level, $country_code, $summary);
                    if ($term_id) {
                        $new_term_ids[$lang] = $term_id;
                    }
                }
            }

            if ($new_term_ids) {
                $this->place_repo->update_term_ids((int) $place->id, $new_term_ids);
            }

            // 3. Collect all term IDs (pre-existing + newly created) without a DB round-trip
            $all_term_ids = [];
            foreach ($languages as $lang) {
                $col = 'term_id_' . $lang;
                if (!empty($place->$col)) {
                    $all_term_ids[$lang] = (int) $place->$col;
                } elseif (isset($new_term_ids[$lang])) {
                    $all_term_ids[$lang] = $new_term_ids[$lang];
                }
            }

            // 4. Ensure all language variants are linked as Polylang translations
            if (count($all_term_ids) > 1) {
                $this->link_translations($all_term_ids);
            }

            // 5. Attach the post-language term to the post
            if (isset($all_term_ids[$post_lang])) {
                $this->attach_term($post_id, $all_term_ids[$post_lang], $names[$post_lang] ?? '', $summary);
            }

            $parent_id     = (int) $place->id;
            $leaf_place_id = (int) $place->id;
        }

        // 6. Persist the coord → leaf-place mapping so future calls take the fast path
        if ($lat_lng_hash && $leaf_place_id) {
            $this->place_repo->store_coord($lat_lng_hash, $leaf_place_id);
        }

        return $summary;
    }

    /**
     * Fast path: the coord was already resolved. Walk the stored place chain
     * and attach whichever terms are missing for the given language.
     *
     * @return array  ['added' => [...], 'skipped' => [...], 'errors' => [...]]
     */
    public function attach_from_place_chain(int $post_id, int $leaf_place_id, string $post_lang): array {
        $summary = ['added' => [], 'skipped' => [], 'errors' => []];
        $chain   = $this->place_repo->get_place_chain($leaf_place_id);

        foreach ($chain as $place) {
            $col = 'term_id_' . $post_lang;
            if (empty($place->$col)) {
                // Term not yet created for this language — log but don't abort
                error_log("Geo Tagger: No {$post_lang} term for place {$place->id} (level={$place->level})");
                continue;
            }
            $name_col = 'name_' . $post_lang;
            $this->attach_term($post_id, (int) $place->$col, $place->$name_col ?? '', $summary);
        }

        return $summary;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Reorganises the per-language tag lists into a level-keyed map.
     *
     * @return array  ['continent' => ['names' => [...], 'country_code' => '...'], ...]
     */
    private function build_level_name_map(array $nominatim_data, array $languages): array {
        $map = [];
        foreach ($languages as $lang) {
            foreach ($this->hierarchy->build_tag_list($nominatim_data, $lang) as $tag) {
                $level = $tag['level'];
                if (!isset($map[$level])) {
                    $map[$level] = ['names' => [], 'country_code' => $tag['country_code']];
                }
                $map[$level]['names'][$lang] = $tag['name'];
            }
        }
        return $map;
    }

    private function attach_term(int $post_id, int $term_id, string $name, array &$summary): void {
        if (has_term($term_id, 'post_tag', $post_id)) {
            $summary['skipped'][] = $name;
            return;
        }
        $result = wp_set_post_terms($post_id, [$term_id], 'post_tag', true);
        if (is_wp_error($result)) {
            $summary['errors'][] = $name;
            error_log("Geo Tagger: Failed to attach term {$term_id} to post {$post_id}: " . $result->get_error_message());
        } else {
            $summary['added'][] = $name;
        }
    }

    private function find_or_create_term(
        string $name,
        string $lang,
        string $level,
        string $country_code,
        array  &$summary
    ): ?int {
        $term_id = $this->find_term_by_name_and_lang($name, $lang, $level)
                ?? $this->find_term_by_normalised_name($name, $lang, $level);

        if (!$term_id) {
            $term_id = $this->create_term($name, $lang, $level, $country_code, $summary);
        }

        return $term_id;
    }

    private function find_term_by_name_and_lang(string $name, string $lang, string $level): ?int {
        $terms = get_terms([
            'taxonomy'   => 'post_tag',
            'name'       => $name,
            'hide_empty' => false,
            'fields'     => 'ids',
            'meta_query' => [[
                'key'   => 'geo_tagger_level',
                'value' => $level,
            ]],
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return null;
        }

        foreach ($terms as $id) {
            if ($this->polylang->get_term_language((int) $id) === $lang) {
                return (int) $id;
            }
        }

        return null;
    }

    private function find_term_by_normalised_name(string $name, string $lang, string $level): ?int {
        $term_ids = get_terms([
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
            'fields'     => 'ids',
            'meta_query' => [
                ['key' => 'geo_tagger_name_normalised', 'value' => $this->normalise($name)],
                ['key' => 'geo_tagger_level',           'value' => $level],
            ],
        ]);

        if (is_wp_error($term_ids) || empty($term_ids)) {
            return null;
        }

        foreach ($term_ids as $id) {
            if ($this->polylang->get_term_language((int) $id) === $lang) {
                return (int) $id;
            }
        }

        return null;
    }

    private function create_term(
        string $name,
        string $lang,
        string $level,
        string $country_code,
        array  &$summary
    ): ?int {
        $slug   = sanitize_title($name) . '-' . $lang;
        $result = wp_insert_term($name, 'post_tag', ['slug' => $slug]);

        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'term_exists') {
                $existing_id    = (int) $result->get_error_data();
                $existing_level = get_term_meta($existing_id, 'geo_tagger_level', true);

                if ($existing_level === $level || $existing_level === '') {
                    // Same level (or unmanaged term with no level) — genuinely the same entity.
                    return $existing_id;
                }

                // Different level with the same name (e.g. county "Ibiza" vs city "Ibiza"):
                // retry with a level-qualified slug to keep the two terms distinct.
                $slug   = sanitize_title($name) . '-' . $level . '-' . $lang;
                $result = wp_insert_term($name, 'post_tag', ['slug' => $slug]);

                if (is_wp_error($result)) {
                    if ($result->get_error_code() === 'term_exists') {
                        return (int) $result->get_error_data();
                    }
                    error_log('Geo Tagger: Failed to create term "' . $name . '" (' . $level . '/' . $lang . '): ' . $result->get_error_message());
                    $summary['errors'][] = $name;
                    return null;
                }
            } else {
                error_log('Geo Tagger: Failed to create term "' . $name . '" (' . $lang . '): ' . $result->get_error_message());
                $summary['errors'][] = $name;
                return null;
            }
        }

        $term_id = (int) $result['term_id'];
        $this->polylang->set_term_language($term_id, $lang);
        $this->store_term_meta($term_id, $level, $country_code, $lang, $name);

        return $term_id;
    }

    private function link_translations(array $term_ids_by_lang): void {
        $merged = $term_ids_by_lang;
        foreach ($term_ids_by_lang as $term_id) {
            foreach ($this->polylang->get_term_translations($term_id) as $existing_lang => $existing_id) {
                if (!isset($merged[$existing_lang])) {
                    $merged[$existing_lang] = $existing_id;
                }
            }
        }
        $this->polylang->save_term_translations($merged);
    }

    private function store_term_meta(int $term_id, string $level, string $cc, string $lang, string $name): void {
        update_term_meta($term_id, 'geo_tagger_level',           $level);
        update_term_meta($term_id, 'geo_tagger_country_code',    $cc);
        update_term_meta($term_id, 'geo_tagger_lang',            $lang);
        update_term_meta($term_id, 'geo_tagger_name_normalised', $this->normalise($name));
    }

    private function normalise(string $name): string {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        return mb_strtolower($ascii !== false ? $ascii : $name);
    }
}
