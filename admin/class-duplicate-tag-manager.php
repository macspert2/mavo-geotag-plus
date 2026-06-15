<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class DuplicateTagManager {

    public function init(): void {
        add_action('admin_menu',            [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_geo_tagger_dup_list',    [$this, 'ajax_list']);
        add_action('wp_ajax_geo_tagger_dup_details', [$this, 'ajax_details']);
        add_action('wp_ajax_geo_tagger_dup_merge',   [$this, 'ajax_merge']);
    }

    public function register_menu(): void {
        add_management_page(
            'Geo Tagger: Duplicate Tags',
            'Geo Tagger Duplicates',
            'manage_options',
            'geo-tagger-duplicates',
            [$this, 'render_page']
        );
    }

    public function enqueue_scripts(string $hook): void {
        if ($hook !== 'tools_page_geo-tagger-duplicates') {
            return;
        }
        wp_enqueue_script(
            'geo-tagger-duplicates',
            plugins_url('js/duplicate-tags.js', __FILE__),
            [],
            GEO_TAGGER_VERSION,
            true
        );
        wp_localize_script('geo-tagger-duplicates', 'geoTaggerDup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('geo_tagger_dup_nonce'),
        ]);
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Geo Tagger: Duplicate Tag Manager</h1>
            <p>
                Tags with the same name within the same Polylang language are listed below.
                Click a group to inspect all term data and optionally merge the two terms into one.
                &nbsp;<a href="<?php echo esc_url(admin_url('tools.php?page=geo-tagger')); ?>">&larr; Back to Geo Tagger</a>
            </p>

            <div id="gtd-loading" style="color:#888;margin-top:16px">Loading duplicates&hellip;</div>

            <div id="gtd-list-section" style="display:none;margin-top:16px">
                <h2 style="margin-top:0">Duplicate Tag Groups</h2>
                <div id="gtd-list"></div>
            </div>

            <div id="gtd-detail-section" style="display:none;margin-top:28px;border-top:1px solid #ddd;padding-top:20px">
                <h2 style="margin-top:0">Term Details &mdash; <span id="gtd-detail-title" style="font-style:italic"></span></h2>
                <div id="gtd-detail"></div>
            </div>

            <div id="gtd-merge-section" style="display:none;margin-top:28px;border-top:1px solid #ddd;padding-top:20px">
                <h2 style="margin-top:0">Merge</h2>
                <p>Select which term to keep. The other term will be deleted and all its posts reassigned to the kept term. <strong>This cannot be undone.</strong></p>
                <div id="gtd-merge-form"></div>
                <div id="gtd-merge-result" style="margin-top:14px"></div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: list all duplicate groups
    // -------------------------------------------------------------------------

    public function ajax_list(): void {
        check_ajax_referer('geo_tagger_dup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        global $wpdb;

        // Polylang stores term language via wp_term_relationships where object_id = term_id
        // (NOT term_taxonomy_id). LEFT JOIN so terms with no language assignment still appear.
        $rows = $wpdb->get_results(
            "SELECT t.name                                                           AS tag_name,
                    tl.lang                                                          AS pll_lang,
                    GROUP_CONCAT(t.term_id ORDER BY t.term_id ASC SEPARATOR ',')    AS term_ids,
                    COUNT(*)                                                         AS cnt
             FROM {$wpdb->terms} t
             JOIN {$wpdb->term_taxonomy} tt
                     ON tt.term_id  = t.term_id
                    AND tt.taxonomy = 'post_tag'
             LEFT JOIN (
                 SELECT tr.object_id AS term_id, t_lang.slug AS lang
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt_lang
                         ON tt_lang.term_taxonomy_id = tr.term_taxonomy_id
                        AND tt_lang.taxonomy = 'term_language'
                 JOIN {$wpdb->terms} t_lang ON t_lang.term_id = tt_lang.term_id
             ) tl ON tl.term_id = t.term_id
             GROUP BY t.name, tl.lang
             HAVING COUNT(*) > 1
             ORDER BY FIELD(tl.lang, 'pll_fr', 'pll_en', 'pll_de'), t.name"
        );

        $groups = [];
        foreach ($rows as $row) {
            $groups[] = [
                'name'     => $row->tag_name,
                'lang'     => $row->pll_lang,
                'term_ids' => array_map('intval', explode(',', $row->term_ids)),
                'count'    => (int) $row->cnt,
            ];
        }

        wp_send_json_success(['groups' => $groups]);
    }

    // -------------------------------------------------------------------------
    // AJAX: full details for a set of term_ids
    // -------------------------------------------------------------------------

    public function ajax_details(): void {
        check_ajax_referer('geo_tagger_dup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $raw_ids  = sanitize_text_field($_POST['term_ids'] ?? '');
        $term_ids = array_filter(array_map('intval', explode(',', $raw_ids)));
        if (empty($term_ids)) {
            wp_send_json_error(['message' => 'No term IDs provided']);
        }

        global $wpdb;

        $terms = [];
        foreach ($term_ids as $term_id) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT t.term_id, t.name, t.slug, t.term_group,
                        tt.term_taxonomy_id, tt.count
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt
                         ON tt.term_id  = t.term_id
                        AND tt.taxonomy = 'post_tag'
                 WHERE t.term_id = %d",
                $term_id
            ));
            if (!$row) {
                continue;
            }

            $meta = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value
                 FROM {$wpdb->termmeta}
                 WHERE term_id = %d
                 ORDER BY meta_key",
                $term_id
            ));

            $pll_lang = function_exists('pll_get_term_language')
                ? (pll_get_term_language($term_id) ?: null)
                : null;

            $translations = function_exists('pll_get_term_translations')
                ? (pll_get_term_translations($term_id) ?: [])
                : [];

            $places = $wpdb->get_results($wpdb->prepare(
                "SELECT id, parent_id, level, name_fr, name_en, name_de,
                        term_id_fr, term_id_en, term_id_de, country_code
                 FROM {$wpdb->prefix}geo_tagger_places
                 WHERE term_id_fr = %d OR term_id_en = %d OR term_id_de = %d
                 ORDER BY id",
                $term_id, $term_id, $term_id
            ));

            $terms[] = [
                'term_id'          => (int) $row->term_id,
                'name'             => $row->name,
                'slug'             => $row->slug,
                'term_group'       => (int) $row->term_group,
                'term_taxonomy_id' => (int) $row->term_taxonomy_id,
                'count'            => (int) $row->count,
                'meta'             => $meta,
                'pll_lang'         => $pll_lang,
                'translations'     => $translations,
                'places'           => $places,
            ];
        }

        wp_send_json_success(['terms' => $terms]);
    }

    // -------------------------------------------------------------------------
    // AJAX: merge drop_id into keep_id
    //
    // Database operations (in order):
    //
    // 1. wp_term_relationships — reassign posts
    //    INSERT IGNORE INTO wp_term_relationships (object_id, term_taxonomy_id, term_order)
    //      SELECT object_id, {keep_tt_id}, term_order
    //      FROM wp_term_relationships WHERE term_taxonomy_id = {drop_tt_id}
    //    INSERT IGNORE prevents duplicates when a post already carries both tags.
    //    Then: DELETE FROM wp_term_relationships WHERE term_taxonomy_id = {drop_tt_id}
    //
    // 2. wp_term_taxonomy — recount A, delete B's row
    //    wp_update_term_count({keep_tt_id}, 'post_tag') — accurate recount from actual relationships
    //    DELETE FROM wp_term_taxonomy WHERE term_taxonomy_id = {drop_tt_id}
    //
    // 3. wp_geo_tagger_places — replace B's term_id in all three language columns with A's term_id
    //    UPDATE wp_geo_tagger_places SET term_id_fr = {keep_id} WHERE term_id_fr = {drop_id}
    //    (repeated for term_id_en, term_id_de)
    //
    // 4. Polylang translation group — runs FIRST, before any row deletions, because
    //    pll_get_term_language() needs wp_term_taxonomy to be intact to resolve term_id.
    //    Two sub-cases:
    //    a) B is in a multi-language group AND A has no group of its own:
    //       $drop_transl[$drop_lang] = $keep_id; pll_save_term_translations($drop_transl)
    //       → A slides into B's language slot and inherits the cross-language connections.
    //    b) B is in a group AND A already has its own group:
    //       unset($drop_transl[$drop_lang]); pll_save_term_translations($drop_transl)
    //       → B is removed from its group; A's group is untouched.
    //    After the pll call, explicitly DELETE the two orphaned wp_term_relationships rows
    //    that pll_save_term_translations never removes: B's translation-group link and
    //    B's language assignment (both keyed on object_id = B.term_taxonomy_id).
    //
    // 5. wp_termmeta — copy missing geo_tagger_* meta from B to A, then delete all B meta
    //    For each meta_key starting with 'geo_tagger_' on B: if A has no value for that key,
    //    add_term_meta(keep_id, meta_key, meta_value, true)
    //    DELETE FROM wp_termmeta WHERE term_id = {drop_id}
    //
    // 6. wp_terms — delete B's row (safe only after all term_taxonomy rows for B are gone)
    //    First verify: SELECT COUNT(*) FROM wp_term_taxonomy WHERE term_id = {drop_id}
    //    If 0: DELETE FROM wp_terms WHERE term_id = {drop_id}
    //
    // 7. WordPress object cache
    //    clean_term_cache([keep_id, drop_id], 'post_tag')
    // -------------------------------------------------------------------------

    public function ajax_merge(): void {
        check_ajax_referer('geo_tagger_dup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $keep_id = absint($_POST['keep_id'] ?? 0);
        $drop_id = absint($_POST['drop_id'] ?? 0);

        if (!$keep_id || !$drop_id || $keep_id === $drop_id) {
            wp_send_json_error(['message' => 'Invalid term IDs.']);
        }

        global $wpdb;

        $keep_tt = $wpdb->get_row($wpdb->prepare(
            "SELECT term_taxonomy_id, count FROM {$wpdb->term_taxonomy}
             WHERE term_id = %d AND taxonomy = 'post_tag'",
            $keep_id
        ));
        $drop_tt = $wpdb->get_row($wpdb->prepare(
            "SELECT term_taxonomy_id, count FROM {$wpdb->term_taxonomy}
             WHERE term_id = %d AND taxonomy = 'post_tag'",
            $drop_id
        ));

        if (!$keep_tt || !$drop_tt) {
            wp_send_json_error(['message' => 'One or both terms not found in post_tag taxonomy.']);
        }

        $log = [];

        // --- Pre-flight: gather Polylang data while B's term_taxonomy row still exists.
        // pll_get_term_language() needs wp_term_taxonomy to resolve term_id → term_taxonomy_id,
        // so it must run before we delete that row in Step 2.

        $pll_ok      = function_exists('pll_get_term_language')
                    && function_exists('pll_get_term_translations')
                    && function_exists('pll_save_term_translations');
        $drop_lang   = $pll_ok ? (pll_get_term_language($drop_id) ?: null)   : null;
        $drop_transl = $pll_ok ? (pll_get_term_translations($drop_id) ?: []) : [];
        $keep_transl = $pll_ok ? (pll_get_term_translations($keep_id) ?: []) : [];

        // Find B's translation group term_taxonomy_id via raw DB so we can explicitly
        // remove the orphaned wp_term_relationships row that pll_save_term_translations
        // adds A to but never removes B from.
        $drop_group_tt_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT tr.term_taxonomy_id
             FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt
                     ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    AND tt.taxonomy = 'term_translations'
             WHERE tr.object_id = %d",
            $drop_tt->term_taxonomy_id
        ));

        // Step 4: Polylang translation group (runs BEFORE any row deletions)
        $drop_has_group = count($drop_transl) > 1;
        $keep_has_group = count($keep_transl) > 1;

        if ($pll_ok && $drop_lang && $drop_has_group) {
            if (!$keep_has_group) {
                // A has no group — slide A into B's language slot so A inherits the connections
                $drop_transl[$drop_lang] = $keep_id;
                pll_save_term_translations($drop_transl);
                $log[] = "Step 4 — Inserted kept term {$keep_id} into drop term's translation group as {$drop_lang}. Group: " . json_encode($drop_transl);
            } else {
                // A already has its own group — just remove B from B's group
                unset($drop_transl[$drop_lang]);
                if (!empty($drop_transl)) {
                    pll_save_term_translations($drop_transl);
                }
                $log[] = "Step 4 — Kept term {$keep_id} already has a group; removed {$drop_id} from drop group only.";
            }
        } elseif ($drop_lang) {
            $log[] = "Step 4 — Drop term {$drop_id} had no cross-language group; nothing to transfer.";
        } else {
            $log[] = "Step 4 — No Polylang language found for drop term {$drop_id}.";
        }

        // Explicitly delete B's orphaned wp_term_relationships entries.
        // pll_save_term_translations adds A's link to the group but never removes B's old link.
        // We also remove B's language assignment.
        if ($drop_group_tt_id) {
            $wpdb->delete($wpdb->term_relationships, [
                'object_id'        => $drop_tt->term_taxonomy_id,
                'term_taxonomy_id' => $drop_group_tt_id,
            ]);
            $log[] = "Step 4 — Deleted B's orphaned translation-group link (object_id={$drop_tt->term_taxonomy_id}, group_tt={$drop_group_tt_id}).";
        }
        $wpdb->query($wpdb->prepare(
            "DELETE tr FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt_lang
                     ON tt_lang.term_taxonomy_id = tr.term_taxonomy_id
                    AND tt_lang.taxonomy = 'term_language'
             WHERE tr.object_id = %d",
            $drop_tt->term_taxonomy_id
        ));
        $log[] = "Step 4 — Deleted B's language assignment from wp_term_relationships.";

        // Step 1: reassign post→tag relationships
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order)
             SELECT object_id, %d, term_order
             FROM {$wpdb->term_relationships}
             WHERE term_taxonomy_id = %d",
            $keep_tt->term_taxonomy_id,
            $drop_tt->term_taxonomy_id
        ));
        $moved = (int) $wpdb->rows_affected;
        $wpdb->delete($wpdb->term_relationships, ['term_taxonomy_id' => $drop_tt->term_taxonomy_id]);
        $log[] = "Step 1 — Reassigned {$moved} post relationship(s) from term {$drop_id} to {$keep_id}.";

        // Step 2: recount A from actual relationships, then delete B's taxonomy row
        wp_update_term_count([$keep_tt->term_taxonomy_id], 'post_tag');
        $new_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
            $keep_tt->term_taxonomy_id
        ));
        $wpdb->delete($wpdb->term_taxonomy, ['term_taxonomy_id' => $drop_tt->term_taxonomy_id]);
        $log[] = "Step 2 — Recounted term {$keep_id}: now {$new_count} post(s). Deleted term_taxonomy row for {$drop_id}.";

        // Step 3: update places table
        $places_updated = 0;
        foreach (['fr', 'en', 'de'] as $lang) {
            $col = "term_id_{$lang}";
            $n   = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}geo_tagger_places SET {$col} = %d WHERE {$col} = %d",
                $keep_id, $drop_id
            ));
            $places_updated += $n;
        }
        $log[] = "Step 3 — Updated geo_tagger_places: replaced {$drop_id} with {$keep_id} in {$places_updated} column occurrence(s).";

        // Step 5: copy missing geo_tagger_* meta from B to A, then delete all B meta
        $drop_meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d",
            $drop_id
        ));
        $copied = 0;
        foreach ($drop_meta as $m) {
            if (strpos($m->meta_key, 'geo_tagger_') === 0) {
                if (!get_term_meta($keep_id, $m->meta_key, true)) {
                    add_term_meta($keep_id, $m->meta_key, $m->meta_value, true);
                    $copied++;
                }
            }
        }
        $wpdb->delete($wpdb->termmeta, ['term_id' => $drop_id]);
        $log[] = "Step 5 — Copied {$copied} geo_tagger_* meta key(s) from {$drop_id} to {$keep_id}. Deleted all termmeta for {$drop_id}.";

        // Step 6: delete B's term row if it has no remaining taxonomy entries
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
            $drop_id
        ));
        if ($remaining === 0) {
            $wpdb->delete($wpdb->terms, ['term_id' => $drop_id]);
            $log[] = "Step 6 — Deleted wp_terms row for {$drop_id}.";
        } else {
            $log[] = "Step 6 — Skipped deleting wp_terms row for {$drop_id}: still referenced in {$remaining} other taxonomy row(s).";
        }

        // Step 7: clean WordPress object cache
        clean_term_cache([$keep_id, $drop_id], 'post_tag');
        $log[] = "Step 7 — Cleaned WordPress term cache for both IDs.";

        wp_send_json_success(['log' => $log, 'keep_id' => $keep_id, 'drop_id' => $drop_id]);
    }
}
