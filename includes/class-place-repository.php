<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class PlaceRepository {

    private const LEVEL_ORDER = ['continent' => 1, 'country' => 2, 'region' => 3, 'city' => 4];
    private const ALLOWED_LANGS = ['fr', 'en', 'de'];

    /**
     * Schema version. Bump whenever install()'s CREATE TABLE changes, so that
     * maybe_upgrade() re-runs dbDelta on sites that are already installed.
     *
     * 2 — term_id_fr/en/de indexes.
     */
    public const DB_VERSION = 2;

    private const DB_VERSION_OPTION = 'geo_tagger_db_version';

    private ?int $world_id = null;

    /**
     * Applies schema changes to an install that already has the tables.
     *
     * install() only ever ran from register_activation_hook, so a table created
     * by an earlier version kept that version's shape for ever: the indexes
     * added in version 2 would have reached new installs only. dbDelta is
     * idempotent, so the upgrade is simply "run it again" — gated on a stored
     * version number so it costs one option read per page load and nothing
     * else.
     */
    public static function maybe_upgrade(): void {
        if ((int) get_option(self::DB_VERSION_OPTION, 0) === self::DB_VERSION) {
            return;
        }

        self::install();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        // The term_id_* indexes carry more traffic than anything else here.
        // Inside this plugin they serve get_place_by_term_id(),
        // get_level_for_term_id(), filter_geo_term_ids() (every post save) and
        // get_chain_for_post(). Outside it, mavo-for-you joins this table on
        // term_id_{lang} twice per recommendation request. All of that was a
        // full scan until these existed.
        dbDelta("CREATE TABLE {$wpdb->prefix}geo_tagger_places (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id    BIGINT UNSIGNED NULL DEFAULT NULL,
            level        VARCHAR(20) NOT NULL,
            name_fr      VARCHAR(255) NULL DEFAULT NULL,
            name_en      VARCHAR(255) NULL DEFAULT NULL,
            name_de      VARCHAR(255) NULL DEFAULT NULL,
            term_id_fr   BIGINT UNSIGNED NULL DEFAULT NULL,
            term_id_en   BIGINT UNSIGNED NULL DEFAULT NULL,
            term_id_de   BIGINT UNSIGNED NULL DEFAULT NULL,
            country_code VARCHAR(2) NULL DEFAULT NULL,
            osm_id       BIGINT NULL DEFAULT NULL,
            osm_type     VARCHAR(10) NULL DEFAULT NULL,
            created_at   DATETIME NOT NULL,
            updated_at   DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY parent_level (parent_id, level),
            KEY country_code (country_code),
            KEY term_id_fr (term_id_fr),
            KEY term_id_en (term_id_en),
            KEY term_id_de (term_id_de)
        ) $charset_collate;");

        dbDelta("CREATE TABLE {$wpdb->prefix}geo_tagger_coord_index (
            lat_lng_hash CHAR(32) NOT NULL,
            place_id     BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (lat_lng_hash),
            KEY place_id (place_id)
        ) $charset_collate;");

        // Seed world root (no corresponding tag — exists only as tree root)
        $exists = $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}geo_tagger_places WHERE level = 'world' LIMIT 1"
        );
        if (!$exists) {
            $wpdb->insert(
                "{$wpdb->prefix}geo_tagger_places",
                [
                    'parent_id'  => null,
                    'level'      => 'world',
                    'name_fr'    => 'Monde',
                    'name_en'    => 'World',
                    'name_de'    => 'Welt',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        }
    }

    public function get_world_id(): ?int {
        if ($this->world_id !== null) {
            return $this->world_id;
        }
        global $wpdb;
        $id = $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}geo_tagger_places WHERE level = 'world' LIMIT 1"
        );
        $this->world_id = $id ? (int) $id : null;
        return $this->world_id;
    }

    /**
     * Finds a place by parent + level + any name match.
     * Builds the WHERE clause dynamically to avoid matching empty strings.
     */
    public function find_place(int $parent_id, string $level, array $names): ?object {
        global $wpdb;

        $conditions = [];
        $params     = [$parent_id, $level];

        foreach (['fr', 'en', 'de'] as $lang) {
            $name = $names[$lang] ?? '';
            if ($name !== '') {
                $conditions[] = "name_{$lang} = %s";
                $params[]     = $name;
            }
        }

        if (empty($conditions)) {
            return null;
        }

        $where_names = '(' . implode(' OR ', $conditions) . ')';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}geo_tagger_places
                 WHERE parent_id = %d AND level = %s AND {$where_names}
                 LIMIT 1",
                ...$params
            )
        ) ?: null;
    }

    public function get_place(int $place_id): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}geo_tagger_places WHERE id = %d",
                $place_id
            )
        ) ?: null;
    }

    public function create_place(array $data): ?int {
        global $wpdb;
        $now            = current_time('mysql');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        if ($wpdb->insert("{$wpdb->prefix}geo_tagger_places", $data) === false) {
            error_log('Geo Tagger: Failed to create place: ' . $wpdb->last_error);
            return null;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Updates only the term_id columns that are provided; never nullifies existing ones.
     */
    public function update_term_ids(int $place_id, array $term_ids_by_lang): void {
        if (empty($term_ids_by_lang)) {
            return;
        }
        global $wpdb;
        $updates = ['updated_at' => current_time('mysql')];
        foreach ($term_ids_by_lang as $lang => $term_id) {
            $updates['term_id_' . $lang] = (int) $term_id;
        }
        $wpdb->update("{$wpdb->prefix}geo_tagger_places", $updates, ['id' => $place_id]);
    }

    /**
     * Returns the place chain from continent down to the given leaf node.
     * The world root is excluded (it has no corresponding tag).
     *
     * @return object[]  Ordered continent → leaf
     */
    public function get_place_chain(int $leaf_place_id): array {
        $chain      = [];
        $current_id = $leaf_place_id;

        for ($depth = 0; $depth < 6; $depth++) {
            $place = $this->get_place($current_id);
            if (!$place || $place->level === 'world') {
                break;
            }
            array_unshift($chain, $place); // prepend so continent ends up first
            if ($place->parent_id === null) {
                break;
            }
            $current_id = (int) $place->parent_id;
        }

        return $chain;
    }

    /**
     * A place and every place beneath it.
     *
     * Walked level by level rather than with a recursive CTE: the tree is four
     * deep (continent → country → region → city), so this is at most four
     * queries and works on MySQL 5.7 as well as 8. The depth cap is a guard
     * against a parent cycle, which the Place Editor's level rules should make
     * impossible but which a hand-edited row could still produce.
     *
     * @return int[] $place_id first, then its descendants.
     */
    public function get_subtree_ids(int $place_id): array {
        if ($place_id <= 0) {
            return [];
        }

        global $wpdb;
        $table = "{$wpdb->prefix}geo_tagger_places";

        $all      = [$place_id];
        $frontier = [$place_id];

        for ($depth = 0; $depth < 5 && $frontier; $depth++) {
            $placeholders = implode(',', array_fill(0, count($frontier), '%d'));

            $children = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE parent_id IN ($placeholders)",
                    ...$frontier
                )
            );

            $children = array_diff(array_map('intval', $children ?: []), $all);
            $all      = array_merge($all, $children);
            $frontier = array_values($children);
        }

        return array_values(array_unique($all));
    }

    /**
     * Every post_tag term id attached to these places, all languages.
     *
     * @param int[] $place_ids
     * @return int[]
     */
    public function get_term_ids_for_places(array $place_ids): array {
        $place_ids = array_values(array_unique(array_filter(array_map('intval', $place_ids))));
        if (!$place_ids) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($place_ids), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id_fr, term_id_en, term_id_de
                   FROM {$wpdb->prefix}geo_tagger_places
                  WHERE id IN ($placeholders)",
                ...$place_ids
            ),
            ARRAY_A
        );

        $term_ids = [];
        foreach ($rows ?: [] as $row) {
            foreach ($row as $id) {
                if ((int) $id) {
                    $term_ids[(int) $id] = true;
                }
            }
        }

        return array_keys($term_ids);
    }

    /**
     * Narrows a list of post_tag term IDs to the ones this plugin owns —
     * those referenced by any language column of geo_tagger_places.
     *
     * Language-agnostic on purpose: it answers "did the geo-tagger attach
     * this term?", which must stay true even for a term whose language no
     * longer matches the post it is on.
     *
     * @param int[] $term_ids
     * @return int[] The subset that are geo place terms.
     */
    public function filter_geo_term_ids(array $term_ids): array {
        $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
        if (!$term_ids) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
        $table        = "{$wpdb->prefix}geo_tagger_places";

        // One statement across all three language columns. This ran as three
        // separate queries, and it is called from pre_post_update — so every
        // post and page save on the site paid for three round trips where one
        // does. The rows come back as place rows; the term ids wanted are
        // whichever of their three columns were actually asked for.
        $where = [];
        $args  = [];
        foreach (self::ALLOWED_LANGS as $lang) {
            $where[] = "term_id_{$lang} IN ($placeholders)";
            $args    = array_merge($args, $term_ids);
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id_fr, term_id_en, term_id_de FROM {$table}
                 WHERE " . implode(' OR ', $where),
                ...$args
            ),
            ARRAY_A
        );

        $asked = array_flip($term_ids);
        $found = [];
        foreach ($rows ?: [] as $row) {
            foreach ($row as $id) {
                $id = (int) $id;
                if ($id && isset($asked[$id])) {
                    $found[$id] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Resolves a post to its full geographic place chain (continent → leaf),
     * via whichever of its post_tag terms is the deepest geo tag it carries
     * (city > region > country > continent). Moved here from GeoBreadcrumb
     * (where it was private) so other callers can share the exact same
     * "post → place" resolution rather than duplicating the query.
     *
     * @return object[] Ordered continent → leaf, or [] if the post has no geo tags.
     */
    public function get_chain_for_post(int $post_id, string $lang): array {
        if (!in_array($lang, self::ALLOWED_LANGS, true)) {
            return [];
        }

        global $wpdb;

        $term_ids = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'ids']);
        if (empty($term_ids) || is_wp_error($term_ids)) {
            return [];
        }

        $col          = 'term_id_' . $lang;
        $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));

        $places = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}geo_tagger_places WHERE {$col} IN ($placeholders)",
                ...$term_ids
            )
        );

        if (empty($places)) {
            return [];
        }

        $leaf      = null;
        $max_depth = 0;
        foreach ($places as $place) {
            $depth = self::LEVEL_ORDER[$place->level] ?? 0;
            if ($depth > $max_depth) {
                $max_depth = $depth;
                $leaf      = $place;
            }
        }

        return $leaf ? $this->get_place_chain((int) $leaf->id) : [];
    }

    /**
     * Finds the best-matching place for a free-text term, via a LIKE
     * match against name_{lang} — for the search-page "geographic
     * hierarchy" feature (resolve what a visitor typed to a known
     * place, e.g. "Londres" → the city place named "Londres").
     *
     * Ordered by shortest matching name first: a shorter name_{lang}
     * containing the term is a tighter match than a longer one that
     * merely happens to contain it as a substring (and naturally
     * prefers an exact match when one exists). Pure data lookup — no
     * minimum term length or other business rules here; that's the
     * caller's job.
     */
    public function find_place_by_name(string $term, string $lang): ?object {
        $term = trim($term);
        if (!in_array($lang, self::ALLOWED_LANGS, true) || '' === $term) {
            return null;
        }

        global $wpdb;
        $col = 'name_' . $lang;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}geo_tagger_places
                 WHERE {$col} LIKE %s
                 ORDER BY CHAR_LENGTH({$col}) ASC
                 LIMIT 1",
                '%' . $wpdb->esc_like($term) . '%'
            )
        ) ?: null;
    }

    public function find_coord(string $lat_lng_hash): ?int {
        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT place_id FROM {$wpdb->prefix}geo_tagger_coord_index WHERE lat_lng_hash = %s",
                $lat_lng_hash
            )
        );
        return $id ? (int) $id : null;
    }

    public function store_coord(string $lat_lng_hash, int $place_id): void {
        global $wpdb;
        $wpdb->replace(
            "{$wpdb->prefix}geo_tagger_coord_index",
            ['lat_lng_hash' => $lat_lng_hash, 'place_id' => $place_id]
        );
    }

    public function delete_coord(string $lat_lng_hash): void {
        global $wpdb;
        $wpdb->delete(
            "{$wpdb->prefix}geo_tagger_coord_index",
            ['lat_lng_hash' => $lat_lng_hash]
        );
    }

    /**
     * Returns the level ('continent','country','region','county','city') stored for a term ID
     * in our places table, or null if not found. This is the authoritative source of truth
     * and takes precedence over geo_tagger_level term meta (which can be corrupted).
     */
    public function get_level_for_term_id(int $term_id): ?string {
        global $wpdb;
        $level = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT level FROM {$wpdb->prefix}geo_tagger_places
                 WHERE term_id_fr = %d OR term_id_en = %d OR term_id_de = %d
                 ORDER BY id ASC
                 LIMIT 1",
                $term_id, $term_id, $term_id
            )
        );
        return $level ?: null;
    }

    /**
     * Returns every place row except the singleton 'world' root, for the Place
     * Editor admin screen (list + parent-picker candidates).
     *
     * @return object[]
     */
    public function get_all_places(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}geo_tagger_places WHERE level != 'world' ORDER BY level, name_fr"
        );
    }

    /**
     * Updates the editable columns of a place row (level, parent_id, country_code,
     * names) for the Place Editor admin screen. term_id_* columns are deliberately
     * not editable here — they're managed by TagManager's term find/create logic.
     */
    public function update_place(int $place_id, array $data): bool {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        return $wpdb->update("{$wpdb->prefix}geo_tagger_places", $data, ['id' => $place_id]) !== false;
    }

    /**
     * Returns the full place row whose term_id_fr/en/de matches the given term ID,
     * or null if this term doesn't correspond to a node in the hierarchy.
     */
    public function get_place_by_term_id(int $term_id): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}geo_tagger_places
                 WHERE term_id_fr = %d OR term_id_en = %d OR term_id_de = %d
                 ORDER BY id ASC
                 LIMIT 1",
                $term_id, $term_id, $term_id
            )
        ) ?: null;
    }
}
