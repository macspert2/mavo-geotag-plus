<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class AdminPage {

    private Core           $core;
    private BatchProcessor $batch;

    public function __construct(Core $core) {
        $this->core  = $core;
        $this->batch = new BatchProcessor($core);
    }

    public function init(): void {
        $this->batch->init();
        add_action('admin_menu',            [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_post_geo_tagger_save_settings', [$this, 'save_settings']);
        (new DuplicateTagManager())->init();
    }

    public function register_menu(): void {
        add_management_page(
            'Geo Tagger',
            'Geo Tagger',
            'manage_options',
            'geo-tagger',
            [$this, 'render_page']
        );
    }

    public function enqueue_scripts(string $hook): void {
        if ($hook !== 'tools_page_geo-tagger') {
            return;
        }
        wp_enqueue_script(
            'geo-tagger-batch',
            plugins_url('js/batch.js', __FILE__),
            [],
            GEO_TAGGER_VERSION,
            true
        );
        wp_localize_script('geo-tagger-batch', 'geoTagger', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('geo_tagger_nonce'),
        ]);
    }

    public function save_settings(): void {
        check_admin_referer('geo_tagger_save_settings');
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        $raw_countries = isset($_POST['region_countries']) && is_array($_POST['region_countries'])
            ? $_POST['region_countries']
            : [];

        $settings = [
            'user_agent'       => sanitize_text_field($_POST['user_agent'] ?? ''),
            'cache_days'       => absint($_POST['cache_days'] ?? 30),
            'rate_limit_ms'    => absint($_POST['rate_limit_ms'] ?? 1100),
            'continent_tags'   => !empty($_POST['continent_tags']),
            'min_depth'        => in_array($_POST['min_depth'] ?? '', ['country', 'region', 'county', 'city'], true)
                                    ? $_POST['min_depth']
                                    : 'city',
            'region_countries' => array_values(array_map('sanitize_key', $raw_countries)),
        ];

        update_option('geo_tagger_settings', $settings);
        wp_redirect(add_query_arg(['page' => 'geo-tagger', 'saved' => '1'], admin_url('tools.php')));
        exit;
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings       = $this->core->get_settings();
        $geo_mashup_ok  = class_exists('GeoMashupDB');
        $polylang_ok    = function_exists('pll_get_post_language');
        $post_count     = $this->core->get_geo_mashup_db()->count_posts_with_location();
        $saved          = !empty($_GET['saved']);

        ?>
        <div class="wrap">
            <h1>MaVo GeoTag Plus
                <a href="<?php echo esc_url(admin_url('tools.php?page=geo-tagger-duplicates')); ?>"
                   class="page-title-action">Duplicate Tag Manager</a>
            </h1>

            <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <!-- Status Panel -->
            <h2>Status</h2>
            <table class="widefat" style="max-width:600px">
                <tbody>
                    <tr>
                        <td><strong>Geo Mashup</strong></td>
                        <td><?php echo $geo_mashup_ok ? '<span style="color:green">&#10003; Active</span>' : '<span style="color:red">&#10007; Inactive</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Polylang</strong></td>
                        <td><?php echo $polylang_ok ? '<span style="color:green">&#10003; Active</span>' : '<span style="color:red">&#10007; Inactive</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Posts with locations</strong></td>
                        <td><?php echo esc_html($post_count); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Nominatim connectivity</strong></td>
                        <td>
                            <button type="button" id="gt-test-nominatim" class="button">Test connection</button>
                            <span id="gt-nominatim-result" style="margin-left:10px"></span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Settings -->
            <h2>Settings</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('geo_tagger_save_settings'); ?>
                <input type="hidden" name="action" value="geo_tagger_save_settings">
                <table class="form-table">
                    <tr>
                        <th><label for="gt-user-agent">User-Agent</label></th>
                        <td>
                            <input type="text" id="gt-user-agent" name="user_agent"
                                   value="<?php echo esc_attr($settings['user_agent']); ?>"
                                   class="regular-text"
                                   placeholder="GeoTagger/1.0 (your-site.com)">
                            <p class="description">Required by Nominatim ToS. Identifies your site.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gt-cache-days">Cache TTL (days)</label></th>
                        <td>
                            <input type="number" id="gt-cache-days" name="cache_days"
                                   value="<?php echo esc_attr($settings['cache_days']); ?>"
                                   min="1" max="365" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gt-rate-limit">Rate limit (ms)</label></th>
                        <td>
                            <input type="number" id="gt-rate-limit" name="rate_limit_ms"
                                   value="<?php echo esc_attr($settings['rate_limit_ms']); ?>"
                                   min="1000" max="5000" class="small-text">
                            <p class="description">Minimum milliseconds between Nominatim requests (min 1000).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Continent tags</th>
                        <td>
                            <label>
                                <input type="checkbox" name="continent_tags" value="1"
                                       <?php checked($settings['continent_tags']); ?>>
                                Enable continent-level tags
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gt-min-depth">Minimum depth</label></th>
                        <td>
                            <select id="gt-min-depth" name="min_depth">
                                <?php foreach (['country' => 'Country only', 'region' => '+ Region', 'county' => '+ County', 'city' => '+ City'] as $val => $label): ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php selected($settings['min_depth'], $val); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Breadcrumb region visibility</th>
                        <td>
                            <?php
                            global $wpdb;
                            $countries = $wpdb->get_results(
                                "SELECT DISTINCT country_code, name_fr, name_en, name_de
                                 FROM {$wpdb->prefix}geo_tagger_places
                                 WHERE level = 'country' AND country_code != ''
                                 ORDER BY name_fr"
                            );
                            $region_countries = $settings['region_countries'] ?? [];

                            if (empty($countries)):
                            ?>
                            <p class="description">No countries found yet — run the batch processor first.</p>
                            <?php else: ?>
                            <p class="description" style="margin-bottom:8px">
                                Show the region breadcrumb only for ticked countries.
                                Unticked countries go straight from country to city (if applicable).
                            </p>
                            <div style="max-height:220px;overflow-y:auto;border:1px solid #c3c4c7;
                                        padding:10px 14px;border-radius:3px;background:#fff">
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:2px 20px">
                            <?php foreach ($countries as $c):
                                $display = $c->name_fr ?: ($c->name_en ?: ($c->name_de ?: strtoupper($c->country_code)));
                                $checked = in_array($c->country_code, $region_countries, true) ? 'checked' : '';
                            ?>
                                <label style="display:block;margin-bottom:4px;break-inside:avoid">
                                    <input type="checkbox"
                                           name="region_countries[]"
                                           value="<?php echo esc_attr($c->country_code); ?>"
                                           <?php echo $checked; ?>>
                                    <?php echo esc_html($display); ?>
                                    <span style="color:#888;font-size:11px">(<?php echo esc_html(strtoupper($c->country_code)); ?>)</span>
                                </label>
                            <?php endforeach; ?>
                            </div><!-- /grid -->
                            </div><!-- /scroll -->
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <!-- Single Post Test -->
            <h2>Test: Single Post</h2>
            <p>Process one post by ID to verify tagging before running the full batch.</p>
            <p>
                <input type="number" id="gt-single-post-id" min="1" placeholder="Post ID"
                       style="width:120px" class="regular-text">
                <button type="button" id="gt-single-post-btn" class="button button-primary"
                        style="margin-left:6px"
                        <?php echo (!$geo_mashup_ok || !$polylang_ok) ? 'disabled' : ''; ?>>
                    Process Post
                </button>
                <button type="button" id="gt-single-force-btn" class="button"
                        style="margin-left:6px"
                        <?php echo (!$geo_mashup_ok || !$polylang_ok) ? 'disabled' : ''; ?>>
                    Force Reprocessing
                </button>
            </p>
            <div id="gt-single-result" style="display:none;margin-top:10px;padding:10px 14px;
                background:#fff;border:1px solid #c3c4c7;border-radius:3px;max-width:600px;
                line-height:1.7;font-size:13px">
            </div>

            <!-- Batch Processor -->
            <h2>Batch Processor</h2>
            <p>Tags all existing posts that have a Geo Mashup location. Safe to run multiple times — already-tagged posts are skipped.</p>

            <p>
                <button type="button" id="gt-run-batch" class="button button-primary"
                    <?php echo (!$geo_mashup_ok || !$polylang_ok) ? 'disabled' : ''; ?>>
                    Run Batch Processor
                </button>
                <button type="button" id="gt-clear-cache" class="button" style="margin-left:10px">
                    Clear Nominatim Cache
                </button>
            </p>

            <div id="gt-progress" style="display:none;max-width:600px;margin-top:15px">
                <div style="background:#ddd;border-radius:4px;height:20px;overflow:hidden">
                    <div id="gt-progress-bar" style="background:#0073aa;height:100%;width:0;transition:width 0.3s"></div>
                </div>
                <p id="gt-progress-text" style="margin:5px 0 0">0 / 0 posts processed</p>
            </div>

            <div id="gt-log" style="display:none;margin-top:15px;max-height:400px;overflow-y:auto;
                background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;
                padding:10px;border-radius:4px">
            </div>
        </div>
        <?php
    }
}
