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

        error_log('GeoTagger v' . GEO_TAGGER_VERSION . ' apply_geo_tags: post=' . $post_id . ' lang=' . $post_lang . ' levels=' . implode(',', array_keys($level_map)));

        foreach (['continent', 'country', 'region', 'city'] as $level) {
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
                error_log("GeoTagger [{$level}] CREATED place id=" . ($place->id ?? '?') . " names=" . json_encode($names));
            } else {
                error_log("GeoTagger [{$level}] FOUND place id={$place->id} term_id_fr={$place->term_id_fr} term_id_en={$place->term_id_en} term_id_de={$place->term_id_de}");
            }

            if (!$place) {
                continue;
            }

            // 2. Create WP terms for any language not yet linked on this place node.
            //    Also re-create if the stored term ID belongs to a different level
            //    (can happen when a same-name collision caused a wrong ID to be stored).
            $new_term_ids = [];
            foreach ($languages as $lang) {
                $name = $names[$lang] ?? null;
                if (!$name) {
                    continue;
                }
                $col       = 'term_id_' . $lang;
                $stored_id = empty($place->$col) ? 0 : (int) $place->$col;

                if ($stored_id && $this->resolve_term_level($stored_id) === $level) {
                    error_log("GeoTagger [{$level}/{$lang}] skipped — place already has valid term_id={$stored_id}");
                    continue;
                }

                if ($stored_id) {
                    $wrong_level = $this->resolve_term_level($stored_id);
                    error_log("GeoTagger [{$level}/{$lang}] CORRUPT stored term_id={$stored_id} belongs to level='{$wrong_level}' — re-creating");
                }

                $term_id = $this->find_or_create_term($name, $lang, $level, $country_code, $summary);
                error_log("GeoTagger [{$level}/{$lang}] find_or_create_term('{$name}') => " . ($term_id ?? 'null'));
                if ($term_id) {
                    $new_term_ids[$lang] = $term_id;
                }
            }

            if ($new_term_ids) {
                error_log("GeoTagger [{$level}] update_term_ids place={$place->id} ids=" . json_encode($new_term_ids));
                $this->place_repo->update_term_ids((int) $place->id, $new_term_ids);
            }

            // 3. Collect all term IDs (valid pre-existing + newly created) without a DB round-trip
            $all_term_ids = [];
            foreach ($languages as $lang) {
                $col       = 'term_id_' . $lang;
                $stored_id = empty($place->$col) ? 0 : (int) $place->$col;
                if ($stored_id && $this->resolve_term_level($stored_id) === $level) {
                    $all_term_ids[$lang] = $stored_id;
                } elseif (isset($new_term_ids[$lang])) {
                    $all_term_ids[$lang] = $new_term_ids[$lang];
                }
            }
            error_log("GeoTagger [{$level}] all_term_ids=" . json_encode($all_term_ids));

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
        $base_slug  = sanitize_title($name) . '-' . $lang;
        $level_slug = sanitize_title($name) . '-' . $level . '-' . $lang;

        // Use raw DB lookups throughout: Polylang filters get_term_by() to the current
        // post language, hiding EN/DE terms when processing a FR post (and vice versa).

        // 1. Level-qualified slug: definitive match when the same name exists at multiple levels.
        $term_id = $this->get_term_id_by_slug($level_slug);
        if ($term_id) {
            $this->ensure_term_meta($term_id, $level, $country_code, $lang, $name);
            return $term_id;
        }

        // 2. Simple lang-suffixed slug: valid only when level confirmed.
        $term_id = $this->get_term_id_by_slug($base_slug);
        if ($term_id && $this->resolve_term_level($term_id) === $level) {
            return $term_id;
        }

        // 3. Name + Polylang language: catches pre-existing tags whose slug has no
        //    language suffix (e.g. slug='italy' for name='Italy' lang='en').
        $term_id = $this->get_term_id_by_name_and_lang($name, $lang);
        if ($term_id) {
            $existing_level = $this->resolve_term_level($term_id);
            if (!$existing_level || $existing_level === $level) {
                error_log("GeoTagger [{$level}/{$lang}] find_or_create_term: found by name+lang (term_id={$term_id}, slug=" . get_term($term_id)->slug . ")");
                $this->ensure_term_meta($term_id, $level, $country_code, $lang, $name);
                return $term_id;
            }
        }

        return $this->create_term($name, $lang, $level, $country_code, $summary);
    }

    /**
     * Returns the authoritative level for a term: checks the places table first
     * (immune to corrupted term meta), then falls back to the geo_tagger_level meta.
     */
    private function resolve_term_level(int $term_id): string {
        return $this->place_repo->get_level_for_term_id($term_id)
            ?? (string) get_term_meta($term_id, 'geo_tagger_level', true);
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
            if ($result->get_error_code() !== 'term_exists') {
                error_log('Geo Tagger: Failed to create term "' . $name . '" (' . $lang . '): ' . $result->get_error_message());
                $summary['errors'][] = $name;
                return null;
            }

            $existing_id    = (int) $result->get_error_data();
            $existing_level = $this->resolve_term_level($existing_id);
            error_log("GeoTagger create_term '{$name}' ({$level}/{$lang}): slug='{$slug}' term_exists={$existing_id} resolved_level='{$existing_level}'");

            if ($existing_level === $level) {
                return $existing_id;
            }

            // Same name, different level (e.g. county "Ibiza" vs city "Ibiza").
            // wp_insert_term() refuses to create a term with the same name even with a
            // different slug, so we bypass its name-uniqueness check via direct DB insert.
            $level_slug = sanitize_title($name) . '-' . $level . '-' . $lang;
            error_log("GeoTagger create_term [{$level}/{$lang}] calling insert_term_direct slug='{$level_slug}'");
            $term_id    = $this->insert_term_direct($name, $level_slug);
            error_log("GeoTagger create_term [{$level}/{$lang}] insert_term_direct returned " . ($term_id ?? 'null'));
            if (!$term_id) {
                $summary['errors'][] = $name;
                return null;
            }
            $this->polylang->set_term_language($term_id, $lang);
            $this->store_term_meta($term_id, $level, $country_code, $lang, $name);
            return $term_id;
        }

        $term_id      = (int) $result['term_id'];
        $actual_level = $this->resolve_term_level($term_id);
        if ($actual_level && $actual_level !== $level) {
            // wp_insert_term() silently reused an existing term from a different level
            // (Polylang's language filter hid it from term_exists, so WordPress treated
            // the name as unique but then reused the matching slug via term-sharing).
            // Force a truly new term with the level-qualified slug.
            error_log("GeoTagger create_term [{$level}/{$lang}] wp_insert_term reused existing term {$term_id} (level='{$actual_level}') — using level-slug instead");
            $level_slug = sanitize_title($name) . '-' . $level . '-' . $lang;
            $term_id    = $this->insert_term_direct($name, $level_slug);
            if (!$term_id) {
                $summary['errors'][] = $name;
                return null;
            }
        }
        $this->polylang->set_term_language($term_id, $lang);
        $this->store_term_meta($term_id, $level, $country_code, $lang, $name);
        return $term_id;
    }

    /**
     * Looks up a post_tag term by slug using a raw DB query.
     *
     * get_term_by() is filtered by Polylang to the current post's language, so when
     * processing a French post it silently hides English and German terms. A direct
     * $wpdb query bypasses that filter and returns the term regardless of language.
     */
    private function get_term_id_by_slug(string $slug): ?int {
        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE t.slug = %s AND tt.taxonomy = 'post_tag'
                 LIMIT 1",
                $slug
            )
        );
        return $id ? (int) $id : null;
    }

    /**
     * Finds a post_tag by exact name within a specific Polylang language.
     * Catches pre-existing tags whose slug has no language suffix (e.g. slug='italy').
     * Polylang stores the language via wp_term_relationships where
     * object_id = term_taxonomy_id (post_tag) → term_language taxonomy.
     */
    private function get_term_id_by_name_and_lang(string $name, string $lang): ?int {
        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT t.term_id
                 FROM {$wpdb->terms} t
                 JOIN {$wpdb->term_taxonomy} tt
                         ON tt.term_id  = t.term_id
                        AND tt.taxonomy = 'post_tag'
                 JOIN {$wpdb->term_relationships} tr ON tr.object_id = tt.term_taxonomy_id
                 JOIN {$wpdb->term_taxonomy} tt_lang
                         ON tt_lang.term_taxonomy_id = tr.term_taxonomy_id
                        AND tt_lang.taxonomy = 'term_language'
                 JOIN {$wpdb->terms} t_lang
                         ON t_lang.term_id = tt_lang.term_id
                        AND t_lang.slug   = %s
                 WHERE t.name = %s
                 LIMIT 1",
                'pll_' . $lang,
                $name
            )
        );
        return $id ? (int) $id : null;
    }

    /**
     * Inserts a term directly into the DB, bypassing wp_insert_term()'s name-uniqueness
     * check. Used when two geographic levels share the same display name (e.g. county
     * "Ibiza" and city "Ibiza") and we need distinct WP terms for each.
     */
    private function insert_term_direct(string $name, string $slug): ?int {
        global $wpdb;

        // Guard: the slug may already exist from a previous partial run.
        // Use a raw query — get_term_by() is filtered by Polylang to the current language.
        $existing_id = $this->get_term_id_by_slug($slug);
        if ($existing_id) {
            return $existing_id;
        }

        $wpdb->insert($wpdb->terms, ['name' => $name, 'slug' => $slug, 'term_group' => 0]);
        if (!$wpdb->insert_id) {
            error_log('Geo Tagger: Direct term insert failed for slug=' . $slug . ': ' . $wpdb->last_error);
            return null;
        }
        $term_id = (int) $wpdb->insert_id;

        $wpdb->insert($wpdb->term_taxonomy, [
            'term_id'     => $term_id,
            'taxonomy'    => 'post_tag',
            'description' => '',
            'parent'      => 0,
            'count'       => 0,
        ]);

        clean_term_cache($term_id, 'post_tag', false);

        return $term_id;
    }

    private function ensure_term_meta(int $term_id, string $level, string $cc, string $lang, string $name): void {
        if (!get_term_meta($term_id, 'geo_tagger_level', true)) {
            $this->polylang->set_term_language($term_id, $lang);
            $this->store_term_meta($term_id, $level, $cc, $lang, $name);
        }
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
        // pll_set_term_language() creates a singleton term_translations group for each new
        // term. pll_save_term_translations() builds the combined group but does not always
        // remove the now-empty singletons. Clean them up here.
        $this->cleanup_singleton_translation_groups(array_values($term_ids_by_lang));
    }

    /**
     * Deletes orphaned singleton term_translations groups left behind by pll_set_term_language().
     * After pll_save_term_translations() consolidates terms into one group, any remaining
     * groups that reference only a single term are safe to remove.
     */
    private function cleanup_singleton_translation_groups(array $term_ids): void {
        global $wpdb;

        if (empty($term_ids)) {
            return;
        }

        // Get the post_tag term_taxonomy_ids for our terms
        $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
        $tt_ids       = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
                 WHERE term_id IN ($placeholders) AND taxonomy = 'post_tag'",
                ...$term_ids
            )
        );

        if (empty($tt_ids)) {
            return;
        }

        // Find all term_translations groups linked to any of our terms
        $ph2           = implode(',', array_fill(0, count($tt_ids), '%d'));
        $group_tt_ids  = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT tr.term_taxonomy_id
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt
                         ON tt.term_taxonomy_id = tr.term_taxonomy_id
                        AND tt.taxonomy = 'term_translations'
                 WHERE tr.object_id IN ($ph2)",
                ...$tt_ids
            )
        );

        foreach ($group_tt_ids as $group_tt_id) {
            $description = $wpdb->get_var($wpdb->prepare(
                "SELECT description FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                $group_tt_id
            ));

            $data = maybe_unserialize($description);

            // Only remove true singletons (exactly one entry in the serialised array)
            if (!is_array($data) || count($data) !== 1) {
                continue;
            }

            $group_term_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                $group_tt_id
            ));

            $wpdb->delete($wpdb->term_relationships, ['term_taxonomy_id' => $group_tt_id]);
            $wpdb->delete($wpdb->term_taxonomy,      ['term_taxonomy_id' => $group_tt_id]);

            if ($group_term_id) {
                $still_used = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
                    $group_term_id
                ));
                if ($still_used === 0) {
                    $wpdb->delete($wpdb->terms, ['term_id' => $group_term_id]);
                }
            }
        }
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
