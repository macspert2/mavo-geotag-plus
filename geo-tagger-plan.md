# Plugin Implementation Plan: `mavo-geotag-plus`

> **Purpose:** Automatically add multilingual geographic tags (continent, country, region,
> county, city) to WordPress posts that have a Geo Mashup location defined. Tags are created
> in the language of each post (via Polylang), linked as translations across languages, and
> stored with a GeoNames place-ID as term meta for future use. Uses standard `post_tag`
> taxonomy. Works retroactively via an admin batch processor, and automatically on every
> subsequent post save.
>
> **Target site stack:** WordPress + GeneratePress + Polylang + Geo Mashup + Swift Performance
> + Autoptimize. Languages: `fr`, `en`, `de`.

---

## 1. Prerequisites & assumptions

- Geo Mashup ≥ 1.9 is active; its class `GeoMashupDB` is available.
- Polylang (free) is active; its helper functions are available.
- The `wp_geo_mashup_locations` table has `lat`, `lng`, `city`, `country_code`, and
  `address` populated for all target posts (verify with
  `SELECT id, lat, lng, city, country_code FROM wp_geo_mashup_locations LIMIT 20`
  before running batch).
- External HTTP requests to `nominatim.openstreetmap.org` are allowed from the server.
- PHP ≥ 7.4, WordPress ≥ 6.0.

---

## 2. File structure

```
mavo-geotag-plus/
├── mavo-geotag-plus.php                  Main plugin file, registers hooks
├── includes/
│   ├── class-geo-tagger-core.php     Orchestrator: coordinates all classes
│   ├── class-geo-mashup-db.php       Read Geo Mashup location data
│   ├── class-nominatim-client.php    Nominatim API + transient cache
│   ├── class-geo-hierarchy.php       Build tag hierarchy from Nominatim response
│   ├── class-tag-manager.php         Create / find / link post_tag terms via Polylang
│   ├── class-polylang-bridge.php     Thin wrapper around Polylang functions
│   ├── class-batch-processor.php     Process all existing posts in chunks
│   └── data/
│       ├── continents.php            country_code → continent name (FR/EN/DE)
│       └── countries.php             country_code → country name (FR/EN/DE) — fallback only
├── admin/
│   ├── class-admin-page.php          Settings + batch processor UI
│   └── js/
│       └── batch.js                  AJAX progress bar logic
├── languages/                        (empty — plugin UI strings are minimal)
└── readme.txt
```

---

## 3. Main plugin file (`mavo-geotag-plus.php`)

```php
/**
 * Plugin Name: MaVo GeoTag Plus
 * Description: Automatically adds multilingual geographic tags to posts with Geo Mashup locations.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('GEO_TAGGER_DIR', plugin_dir_path(__FILE__));
define('GEO_TAGGER_VERSION', '1.0.0');

// Autoload all classes
spl_autoload_register(function (string $class): void {
    $map = [
        'GeoTagger\\Core'            => 'includes/class-geo-tagger-core.php',
        'GeoTagger\\GeoMashupDB'     => 'includes/class-geo-mashup-db.php',
        'GeoTagger\\NominatimClient' => 'includes/class-nominatim-client.php',
        'GeoTagger\\GeoHierarchy'    => 'includes/class-geo-hierarchy.php',
        'GeoTagger\\TagManager'      => 'includes/class-tag-manager.php',
        'GeoTagger\\PolylangBridge'  => 'includes/class-polylang-bridge.php',
        'GeoTagger\\BatchProcessor'  => 'includes/class-batch-processor.php',
        'GeoTagger\\AdminPage'       => 'admin/class-admin-page.php',
    ];
    if (isset($map[$class])) {
        require_once GEO_TAGGER_DIR . $map[$class];
    }
});

add_action('plugins_loaded', function (): void {
    if (!class_exists('GeoMashupDB') || !function_exists('pll_get_post_language')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>Geo Tagger requires both <strong>Geo Mashup</strong> and <strong>Polylang</strong> to be active.</p></div>';
        });
        return;
    }

    $core = new GeoTagger\Core();
    $core->init();

    if (is_admin()) {
        (new GeoTagger\AdminPage($core))->init();
    }
});
```

---

## 4. Class: `GeoMashupDB` (`includes/class-geo-mashup-db.php`)

Reads location data from Geo Mashup tables directly (avoids depending on Geo Mashup's
internal API which may change).

### Public methods

```php
/**
 * Returns location row for a post, or null if none.
 *
 * @return object|null  Properties: id, lat, lng, address, city, province_code,
 *                      country_code, admin_code, sub_admin_code, locality_name, postal_code
 */
public function get_location_for_post(int $post_id): ?object

/**
 * Returns all post IDs that have a Geo Mashup location.
 * Supports pagination for batch processing.
 *
 * @return int[]
 */
public function get_post_ids_with_location(int $offset = 0, int $limit = 50): array

/**
 * Total count of posts with a location (for progress calculation).
 */
public function count_posts_with_location(): int
```

### Implementation notes

- Query `wp_geo_mashup_location_relationships` joined to `wp_geo_mashup_locations`.
- `object_name` column = `'post'` for regular posts.
- Use `$wpdb->prepare()` throughout.
- Do **not** call `GeoMashupDB::` static methods directly — that class may not expose
  the fields we need reliably across versions.

```sql
SELECT l.*
FROM {prefix}geo_mashup_locations l
INNER JOIN {prefix}geo_mashup_location_relationships r ON l.id = r.location_id
WHERE r.object_name = 'post'
  AND r.object_id = %d
LIMIT 1
```

---

## 5. Class: `NominatimClient` (`includes/class-nominatim-client.php`)

Calls Nominatim's reverse-geocoding endpoint and caches results as WordPress transients.

### Configuration constants (set via plugin settings, with defaults)

| Constant | Default | Notes |
|---|---|---|
| `GEO_TAGGER_USER_AGENT` | `'GeoTagger/1.0 (WordPress plugin)'` | Required by Nominatim ToS |
| `GEO_TAGGER_CACHE_DAYS` | `30` | Transient TTL |
| `GEO_TAGGER_RATE_LIMIT_MS` | `1100` | Milliseconds between uncached requests |

### Cache key

`geo_tagger_nom_` + `md5("{$lat},{$lng}")` — stored as a WordPress transient.

### Public methods

```php
/**
 * Returns a normalised location array for the given coordinates.
 * Calls Nominatim only on cache miss; respects 1 req/sec rate limit.
 *
 * @return array|null  See §5.1 "Return structure" below
 */
public function reverse_geocode(float $lat, float $lng): ?array
```

### 5.1 Nominatim request

```
GET https://nominatim.openstreetmap.org/reverse
    ?lat={lat}
    &lon={lng}
    &format=json
    &addressdetails=1
    &namedetails=1
    &accept-language=fr,en,de
    &zoom=18
```

Headers:
```
User-Agent: {GEO_TAGGER_USER_AGENT}
Referer: {home_url()}
```

Use `wp_remote_get()` with a 10-second timeout.

### 5.2 Return structure (after normalisation)

The raw response mixes language-specific names in `namedetails` for the pinpoint location,
and address component names (in a single language — Nominatim picks the best available) in
`address`. We call the endpoint **three times** (once per language) to reliably get each
address level in each language. All three calls are made on the first cache miss, then cached
together.

```php
// Stored in transient:
[
  'fr' => [
    'continent'  => 'Europe',           // from lookup table
    'country'    => 'France',
    'region'     => 'Normandie',        // state / province
    'county'     => 'Calvados',         // county / department
    'city'       => 'Bayeux',           // city / town / village
    'country_code' => 'fr',             // ISO 3166-1 alpha-2, always lowercase
  ],
  'en' => [ ... ],
  'de' => [ ... ],
]
```

Any level that Nominatim does not return for a given location is `null` and will be skipped.

### 5.3 Three-call strategy

Make three sequential calls: `accept-language=fr`, `accept-language=en`, `accept-language=de`.
Sleep `GEO_TAGGER_RATE_LIMIT_MS / 1000` seconds between calls. On error/timeout for any
language, store `null` values for that language's levels (do not abort entirely).

### 5.4 Rate limiting

Track the last Nominatim request time in a transient `geo_tagger_last_nominatim_request`.
Before each call, check elapsed time and sleep the deficit. This works correctly across
separate AJAX requests during batch processing.

### 5.5 Error handling

- HTTP errors (non-200) → log to error_log, return `null`.
- Malformed JSON → log, return `null`.
- Nominatim `error` key in response body → log, return `null`.
- All errors are non-fatal: the post is skipped gracefully.

---

## 6. Class: `GeoHierarchy` (`includes/class-geo-hierarchy.php`)

Extracts and normalises the address hierarchy from a Nominatim `address` object.

### 6.1 Address key mapping

Nominatim returns address components under varying keys depending on the country. Map
them to our five levels using a priority list:

```php
const LEVEL_KEYS = [
    'city'   => ['city', 'town', 'village', 'hamlet', 'municipality', 'district'],
    'county' => ['county', 'state_district', 'district', 'city_district'],
    'region' => ['state', 'province', 'region', 'state_district'],
    // 'country' and 'country_code' are direct keys
];
```

For each level, iterate the priority list and return the first non-empty value found.

### 6.2 Continent lookup

File `includes/data/continents.php` returns an array keyed by ISO 3166-1 alpha-2
country code:

```php
return [
    // Format: 'country_code' => ['fr' => '...', 'en' => '...', 'de' => '...']
    'fr' => ['fr' => 'Europe',        'en' => 'Europe',        'de' => 'Europa'],
    'de' => ['fr' => 'Europe',        'en' => 'Europe',        'de' => 'Europa'],
    'gb' => ['fr' => 'Europe',        'en' => 'Europe',        'de' => 'Europa'],
    'us' => ['fr' => 'Amérique du Nord','en' => 'North America','de' => 'Nordamerika'],
    'ca' => ['fr' => 'Amérique du Nord','en' => 'North America','de' => 'Nordamerika'],
    'mx' => ['fr' => 'Amérique du Nord','en' => 'North America','de' => 'Nordamerika'],
    'br' => ['fr' => 'Amérique du Sud','en' => 'South America','de' => 'Südamerika'],
    'ar' => ['fr' => 'Amérique du Sud','en' => 'South America','de' => 'Südamerika'],
    'jp' => ['fr' => 'Asie',          'en' => 'Asia',          'de' => 'Asien'],
    'cn' => ['fr' => 'Asie',          'en' => 'Asia',          'de' => 'Asien'],
    'in' => ['fr' => 'Asie',          'en' => 'Asia',          'de' => 'Asien'],
    'au' => ['fr' => 'Océanie',       'en' => 'Oceania',       'de' => 'Ozeanien'],
    'nz' => ['fr' => 'Océanie',       'en' => 'Oceania',       'de' => 'Ozeanien'],
    'za' => ['fr' => 'Afrique',       'en' => 'Africa',        'de' => 'Afrika'],
    'eg' => ['fr' => 'Afrique',       'en' => 'Africa',        'de' => 'Afrika'],
    'ma' => ['fr' => 'Afrique',       'en' => 'Africa',        'de' => 'Afrika'],
    // ... complete list — include all ~195 country codes
];
```

**Important:** complete this table with all country codes before release. Group by
continent to make it easy to verify.

### 6.3 Country fallback

File `includes/data/countries.php` provides country names as a fallback if Nominatim
returns an unexpected result. Keyed by ISO code:

```php
return [
    'fr' => ['fr' => 'France',      'en' => 'France',      'de' => 'Frankreich'],
    'de' => ['fr' => 'Allemagne',   'en' => 'Germany',     'de' => 'Deutschland'],
    'gb' => ['fr' => 'Royaume-Uni', 'en' => 'United Kingdom', 'de' => 'Vereinigtes Königreich'],
    'es' => ['fr' => 'Espagne',     'en' => 'Spain',       'de' => 'Spanien'],
    'it' => ['fr' => 'Italie',      'en' => 'Italy',       'de' => 'Italien'],
    // ... complete list
];
```

### 6.4 Public method

```php
/**
 * Returns a hierarchy array ready for TagManager.
 *
 * @param  array  $nominatim_data  Output of NominatimClient::reverse_geocode()
 * @param  string $lang            'fr' | 'en' | 'de'
 * @return array  Ordered list of ['level' => string, 'name' => string] where level is
 *                one of: continent, country, region, county, city.
 *                Levels with no name are omitted.
 */
public function build_tag_list(array $nominatim_data, string $lang): array
```

---

## 7. Class: `PolylangBridge` (`includes/class-polylang-bridge.php`)

Thin wrapper around Polylang's global functions — makes them mockable in tests and
centralises the `function_exists()` guards.

### Public methods

```php
public function get_post_language(int $post_id): ?string  // 'fr', 'en', 'de', or null

public function get_term_language(int $term_id): ?string

public function set_term_language(int $term_id, string $lang): void

public function get_term_translations(int $term_id): array  // ['fr' => id, 'en' => id, ...]

public function save_term_translations(array $translations): void // ['fr' => id, 'en' => id, ...]

/**
 * Returns all active language slugs configured in Polylang.
 * @return string[]  e.g. ['fr', 'en', 'de']
 */
public function get_active_languages(): array
```

### Implementation notes

- `get_active_languages()` calls `pll_languages_list(['fields' => 'slug'])`. Do NOT
  hardcode `['fr', 'en', 'de']` — this allows the plugin to work if a language is
  added or removed.
- All methods check `function_exists()` and return safe defaults if Polylang is
  unexpectedly unavailable.

---

## 8. Class: `TagManager` (`includes/class-tag-manager.php`)

Creates, finds, and links `post_tag` terms across languages, then attaches them to posts.

### 8.1 Tag-finding strategy (in order)

For a given `(name, lang)` pair:

1. Search `post_tag` for a term whose name matches **case-insensitively** AND whose
   Polylang language matches `$lang`.
2. If not found, search by `geo_tagger_name_normalised` term meta (the accent-stripped
   lowercase name) to catch minor spelling variants already in the database.
3. If still not found, **create** the tag.

**Never merge two terms automatically.** If a name collision is found with a different
language, create the new tag anyway (WordPress will append a numeric suffix to the slug).

### 8.2 Creating a tag

```php
$result = wp_insert_term(
    $name,
    'post_tag',
    ['slug' => $this->build_slug($name, $lang)]  // e.g. "france-fr", "france-en"
);
```

- Always include the language suffix in the slug to avoid collisions.
- After creation, call `PolylangBridge::set_term_language($term_id, $lang)`.
- Store term meta immediately (see §8.4).

### 8.3 Linking translations

After ensuring all language variants of a geographic name exist (creating them if needed),
build a translation group:

```php
$translations = [
    'fr' => $fr_term_id,
    'en' => $en_term_id,
    'de' => $de_term_id,
];
$polylang->save_term_translations($translations);
```

Only include languages for which a term was found/created. Never overwrite an existing
translation link with a null value.

**Merge strategy:** Before calling `save_term_translations`, fetch any existing translation
group for each term and merge arrays, so existing links are not disrupted.

### 8.4 Term meta stored on each geo tag

```php
update_term_meta($term_id, 'geo_tagger_level',      $level);   // continent|country|region|county|city
update_term_meta($term_id, 'geo_tagger_country_code', $cc);    // ISO 3166-1 alpha-2
update_term_meta($term_id, 'geo_tagger_lang',        $lang);   // fr|en|de
update_term_meta($term_id, 'geo_tagger_name_normalised',
    mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name)));  // for fuzzy matching
```

GeoNames ID is **not** available from Nominatim (it returns OSM IDs). Leave a placeholder:
```php
update_term_meta($term_id, 'geo_tagger_osm_id',  $osm_id ?? '');   // from Nominatim `osm_id`
update_term_meta($term_id, 'geo_tagger_osm_type', $osm_type ?? ''); // node|way|relation
```
This is sufficient for future enrichment with GeoNames if desired.

### 8.5 Attaching tags to a post

```php
// Append only — never remove existing tags
wp_set_post_terms($post_id, [$term_id], 'post_tag', true /* append */);
```

Before attaching, check `has_term($term_id, 'post_tag', $post_id)` — skip if already set.

### 8.6 Public method

```php
/**
 * Ensures all geographic tags for a given hierarchy + all languages exist, are
 * translation-linked, and are attached to the post.
 *
 * @param int    $post_id
 * @param array  $nominatim_data  Full output of NominatimClient::reverse_geocode()
 *                                 (all languages)
 * @param string $post_lang       Language of the post ('fr'|'en'|'de')
 * @return array  Summary ['added' => [...tag names...], 'skipped' => [...], 'errors' => [...]]
 */
public function apply_geo_tags(int $post_id, array $nominatim_data, string $post_lang): array
```

**Important:** This method processes ALL levels × ALL languages even though the post only
gets tags for `$post_lang`. The reason: it creates translation-linked counterparts in all
languages so that when another post in `'en'` covers the same location, the French term
is already there. On the post itself, only attach the level terms in `$post_lang`.

---

## 9. Class: `GeoTaggerCore` (`includes/class-geo-tagger-core.php`)

Orchestrates all other classes and owns the `save_post` hook.

### 9.1 Hook registration

```php
public function init(): void {
    add_action('save_post', [$this, 'on_save_post'], 20, 2);
}
```

### 9.2 `on_save_post` handler

```php
public function on_save_post(int $post_id, WP_Post $post): void {
    // 1. Skip autosaves, revisions, trash, non-public post types
    if (wp_is_post_autosave($post_id)) return;
    if (wp_is_post_revision($post_id))  return;
    if ($post->post_status === 'trash') return;
    if (!in_array($post->post_type, ['post', 'page'])) return;

    // 2. Avoid infinite loops if wp_set_post_terms triggers save_post
    if (defined('GEO_TAGGER_PROCESSING')) return;
    define('GEO_TAGGER_PROCESSING', true);

    // 3. Get Geo Mashup location
    $location = $this->geo_mashup_db->get_location_for_post($post_id);
    if (!$location || empty($location->lat) || empty($location->lng)) return;

    // 4. Get post language
    $lang = $this->polylang->get_post_language($post_id);
    if (!$lang) return;

    // 5. Reverse geocode (cached)
    $geo_data = $this->nominatim->reverse_geocode((float)$location->lat, (float)$location->lng);
    if (!$geo_data) return;

    // 6. Apply tags
    $this->tag_manager->apply_geo_tags($post_id, $geo_data, $lang);
}
```

### 9.3 `tag_single_post` (public, for batch processor)

Same logic as `on_save_post` but called directly with a post ID. Returns the summary
array from `TagManager::apply_geo_tags()`.

---

## 10. Class: `BatchProcessor` (`includes/class-batch-processor.php`)

Processes all existing posts with Geo Mashup locations in AJAX-driven chunks.

### 10.1 AJAX endpoints

Register two actions (both require `manage_options` capability):

```php
add_action('wp_ajax_geo_tagger_batch_run',   [$this, 'ajax_run_batch']);
add_action('wp_ajax_geo_tagger_batch_count', [$this, 'ajax_get_count']);
```

### 10.2 `ajax_get_count`

Returns `{ "total": N }` — the number of posts to process.

### 10.3 `ajax_run_batch`

Accepts `$_POST['offset']` (integer, default 0). Processes up to `BATCH_SIZE = 10` posts.

Returns:
```json
{
  "processed": 10,
  "offset":    10,
  "total":     342,
  "done":      false,
  "log": [
    { "post_id": 123, "title": "...", "added": ["France", "Normandie"], "skipped": ["Europe"], "errors": [] },
    ...
  ]
}
```

When `offset + processed >= total`, set `"done": true`.

### 10.4 Rate limiting in batch

`NominatimClient` handles rate limiting internally. The batch processor does not need
to add additional delays — it simply calls `GeoTaggerCore::tag_single_post()` in a loop.

### 10.5 Re-entrancy

The batch is safe to restart from any offset. Running it twice on the same posts is
harmless (tags already present are skipped).

---

## 11. Admin page (`admin/class-admin-page.php`)

Register under **Tools → Geo Tagger**.

### 11.1 Sections

**Status panel**
- Geo Mashup: active / inactive
- Polylang: active / inactive
- Nominatim connectivity: button to send a test request and display the result
- Posts with locations: N total (from `GeoMashupDB::count_posts_with_location()`)

**Settings**
- User-Agent string (text field, default `GeoTagger/1.0 ({site_url})`)
- Transient cache TTL in days (number, default 30)
- Enable continent tags (checkbox, default yes)
- Minimum depth to tag (select: Country only / + Region / + County / + City, default City)
- Save button

**Batch processor**
- Brief explanation of what it does
- "Run Batch Processor" button (disabled if Geo Mashup or Polylang inactive)
- Progress bar (hidden until started): `X / N posts processed`
- Scrollable log area (live-updated via AJAX)
- "Clear Nominatim Cache" button (deletes all `geo_tagger_nom_*` transients)

### 11.2 `admin/js/batch.js`

Vanilla JS (no jQuery dependency for future-proofing, though jQuery is available).

Algorithm:
```javascript
let offset = 0;
let total  = 0;

async function runBatch() {
  // 1. Get total
  const count = await post('geo_tagger_batch_count', {});
  total = count.total;
  updateProgress(0, total);

  // 2. Loop until done
  while (offset < total) {
    const result = await post('geo_tagger_batch_run', { offset });
    offset += result.processed;
    updateProgress(offset, total);
    appendLog(result.log);
    if (result.done) break;
  }
  showDone();
}
```

The `post()` helper uses `fetch()` with WordPress nonce verification.

---

## 12. Settings storage

Use a single WordPress option `geo_tagger_settings` (array), with `get_option` /
`update_option`. Sanitise all values on save. Provide sensible defaults via
`get_option('geo_tagger_settings', GeoTaggerCore::DEFAULT_SETTINGS)`.

---

## 13. Data files

### `includes/data/continents.php`

Must cover **all** ISO 3166-1 alpha-2 codes (use the full official list — 249 codes
including territories). Group by continent in comments for easy review. Return a PHP
array at the bottom of the file.

Use UN M.49 standard continent groupings:
- Africa (54 countries)
- Antarctica (1)
- Asia (47)
- Europe (44)
- North America (23)
- Oceania (14)
- South America (12)

Special cases:
- `ru` (Russia) → Europe (geographically spans both; Europe is conventional)
- `tr` (Turkey/Türkiye) → Europe (conventional for travel blogs)
- `cy` (Cyprus) → Europe (EU member)
- `az`, `ge`, `am` → Europe (conventional)

### `includes/data/countries.php`

Same structure; only needed as fallback when Nominatim returns an unexpected country name.
Covers the ~60 most-visited countries; the rest can be added on demand.

---

## 14. Hook: Geo Mashup location save

Geo Mashup fires a custom action when a location is saved:
`do_action('geo_mashup_location_saved', $location_id, $object_name, $object_id)`.

Register a handler in `GeoTaggerCore::init()`:

```php
add_action('geo_mashup_location_saved', [$this, 'on_geo_mashup_location_saved'], 10, 3);
```

```php
public function on_geo_mashup_location_saved(int $location_id, string $object_name, int $object_id): void {
    if ($object_name !== 'post') return;
    $this->tag_single_post($object_id);
}
```

This hook is more targeted than `save_post` (fires only when a location actually changes).
Keep both hooks — `geo_mashup_location_saved` handles the "just added a location" case;
`save_post` handles cases where the post is saved after a location is already set.

Use the `GEO_TAGGER_PROCESSING` constant guard in both to prevent double-processing.

---

## 15. Error handling & logging

- All errors go to `error_log("Geo Tagger: {message}")`. No exceptions bubble to the user.
- Failed Nominatim calls are logged but do not abort tagging for other levels.
- If Nominatim is completely unreachable (e.g. server firewall): the batch processor logs
  each failure and continues; the `save_post` hook silently returns.
- Term creation failures (e.g. slug collision WordPress can't resolve) are caught, logged,
  and skipped.

---

## 16. Security

- All AJAX handlers verify `check_ajax_referer('geo_tagger_nonce')` and
  `current_user_can('manage_options')`.
- All DB queries use `$wpdb->prepare()`.
- Settings are sanitised on save (`sanitize_text_field`, `absint`, etc.).
- Output in admin page is escaped with `esc_html()` / `esc_attr()`.

---

## 17. Implementation order (suggested for Claude Code)

Work through the files in this order to enable incremental testing:

1. `mavo-geotag-plus.php` — skeleton with autoloader and dependency check
2. `includes/class-polylang-bridge.php` — no external dependencies
3. `includes/class-geo-mashup-db.php` — verify with raw DB query output
4. `includes/data/continents.php` + `includes/data/countries.php` — data entry
5. `includes/class-nominatim-client.php` — test with a known lat/lng (e.g. Paris: 48.8566, 2.3522)
6. `includes/class-geo-hierarchy.php` — unit-testable, pure functions
7. `includes/class-tag-manager.php` — most complex; test in isolation on a staging site
8. `includes/class-geo-tagger-core.php` — wire everything together
9. `admin/class-admin-page.php` + `admin/js/batch.js` — UI layer
10. `includes/class-batch-processor.php` — depends on admin page for AJAX registration

---

## 18. Testing checklist

Before running on the live site:

- [ ] Verify `wp_geo_mashup_locations` has populated `lat`, `lng`, `city`, `country_code`
      for a sample of posts.
- [ ] Test `NominatimClient::reverse_geocode()` with Paris coordinates; confirm all 3
      language variants are cached after first call.
- [ ] Test `TagManager::apply_geo_tags()` on a single post; verify tags created,
      Polylang language set, translation links created.
- [ ] Run batch processor on staging with ~20 posts; inspect tags and Polylang translation
      group in wp-admin.
- [ ] Save a post on staging with an existing location; confirm no duplicate tags.
- [ ] Add a new location to a post and save; confirm new geo tags appear.
- [ ] Verify "Clear Cache" button removes all `geo_tagger_nom_*` transients.
- [ ] Test with a location that Nominatim returns no city for (e.g. remote rural area);
      confirm graceful degradation.
- [ ] Run batch processor a second time; confirm all items logged as "skipped".

---

## 19. Known limitations & future improvements

- Nominatim public instance ToS prohibits bulk/automated use for commercial projects.
  For high-volume sites, self-host Nominatim or switch to a paid geocoding API. The
  `NominatimClient` class should be designed so the endpoint URL is configurable.
- Continent is derived from country code via a lookup table, not from Nominatim.
  This is intentional (Nominatim does not reliably return continent in the address object).
- City/region/county naming is whatever Nominatim returns in that language, which may
  occasionally differ from the "canonical" local name. Manual correction via the standard
  WordPress term edit screen is always available.
- This plugin does not retroactively link tags that were created manually before the plugin
  was installed — if a French tag "France" and English tag "France" already exist as
  separate (unlinked) terms, the plugin will find the correct one for each language and
  link them when it first processes a bilingual location. No manual intervention needed.
