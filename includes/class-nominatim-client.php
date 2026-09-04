<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class NominatimClient {

    private const ENDPOINT   = 'https://nominatim.openstreetmap.org/reverse';
    private const TRANSIENT_PREFIX    = 'geo_tagger_nom_';
    private const RATE_LIMIT_TRANSIENT = 'geo_tagger_last_nominatim_request';

    /**
     * How long an incomplete lookup stays cached.
     *
     * Nominatim is a free, best-effort service: it throttles, and it has
     * outages, and fetch() returns null for whatever it could not answer.
     * Those nulls used to be cached for the full cache_days alongside the
     * successful languages, so a single bad minute left a post untagged for a
     * month with no retry. Anything short of a complete answer is now kept
     * only long enough to avoid hammering a struggling endpoint.
     */
    private const FAILURE_TTL = 15 * MINUTE_IN_SECONDS;

    private array $settings;

    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    public function reverse_geocode(float $lat, float $lng): ?array {
        $cache_key = self::TRANSIENT_PREFIX . md5("{$lat},{$lng}");
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            // A cached [] is the "Nominatim gave us nothing" marker written
            // below; normalise it back to null for the caller.
            return $cached ?: null;
        }

        $languages = ['fr', 'en', 'de'];
        $result    = [];

        // Spacing is applied before every request, first one included: the
        // timestamp fetch() records outlives this call (see RATE_LIMIT
        // transient), so consecutive lookups — a batch run, or two cron events
        // firing back to back — would otherwise fire their opening request
        // with no gap at all and exceed Nominatim's 1 req/s policy.
        foreach ($languages as $lang) {
            $this->rate_limit();
            $data = $this->fetch($lat, $lng, $lang);
            $result[$lang] = $data;
        }

        $resolved = array_filter($result);

        if (!$resolved) {
            // Every language failed. Returning $result here would hand the
            // caller ['fr' => null, 'en' => null, 'de' => null] — a non-empty
            // array, so Core::tag_single_post()'s `if (!$geo_data)` check
            // passed and it ran a full tagging pass over empty data, tagging
            // nothing but still treating the location as resolved. Return
            // null so it bails, and keep the failure only briefly so the next
            // save re-tries instead of waiting out cache_days.
            set_transient($cache_key, [], self::FAILURE_TTL);
            return null;
        }

        // A partial answer is still worth using — it tags what it can — but it
        // must not be cached as if it were the final word on this coordinate.
        $complete = count($resolved) === count($languages);
        $ttl      = $complete
            ? absint($this->settings['cache_days'] ?? 30) * DAY_IN_SECONDS
            : self::FAILURE_TTL;

        set_transient($cache_key, $result, $ttl);

        return $result;
    }

    /**
     * Raw connectivity probe — returns ['code' => int, 'body' => string] without caching.
     * Used by the admin test button to surface the exact Nominatim response.
     */
    public function probe(float $lat, float $lng): array {
        $url = add_query_arg([
            'lat'            => $lat,
            'lon'            => $lng,
            'format'         => 'json',
            'addressdetails' => 1,
            'accept-language'=> 'en',
            'zoom'           => 18,
        ], self::ENDPOINT);

        $user_agent = $this->settings['user_agent'] ?? ('GeoTagger/1.0 (' . home_url() . ')');

        $response = wp_remote_get($url, [
            'timeout'    => 10,
            'user-agent' => $user_agent,
            'headers'    => ['Referer' => home_url()],
        ]);

        if (is_wp_error($response)) {
            return ['code' => 0, 'body' => $response->get_error_message()];
        }

        return [
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => wp_remote_retrieve_body($response),
        ];
    }

    private function fetch(float $lat, float $lng, string $lang): ?array {
        $url = add_query_arg([
            'lat'            => $lat,
            'lon'            => $lng,
            'format'         => 'json',
            'addressdetails' => 1,
            'namedetails'    => 1,
            'accept-language'=> $lang,
            'zoom'           => 18,
        ], self::ENDPOINT);

        $user_agent = $this->settings['user_agent'] ?? ('GeoTagger/1.0 (' . home_url() . ')');

        // 'user-agent' must be a top-level arg — setting it inside 'headers' is
        // silently ignored by WordPress's HTTP API, which manages its own UA header.
        $response = wp_remote_get($url, [
            'timeout'    => 10,
            'user-agent' => $user_agent,
            'headers'    => [
                'Referer' => home_url(),
            ],
        ]);

        set_transient(self::RATE_LIMIT_TRANSIENT, microtime(true), 60);

        if (is_wp_error($response)) {
            error_log('Geo Tagger: Nominatim request failed (' . $lang . '): ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            error_log("Geo Tagger: Nominatim returned HTTP {$code} for lang={$lang}. Body: {$body}");
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Geo Tagger: Nominatim returned invalid JSON for lang=' . $lang);
            return null;
        }

        if (isset($data['error'])) {
            error_log('Geo Tagger: Nominatim error for lang=' . $lang . ': ' . $data['error']);
            return null;
        }

        return $data;
    }

    /**
     * Sleeps until at least rate_limit_ms has passed since the last request
     * this site made to Nominatim. No-op when the timestamp has expired (the
     * transient lives 60s), so an isolated lookup never waits.
     */
    private function rate_limit(): void {
        $last = (float) get_transient(self::RATE_LIMIT_TRANSIENT);
        if (!$last) {
            return;
        }

        $delay_ms = absint($this->settings['rate_limit_ms'] ?? 1100);
        $elapsed_ms = (microtime(true) - $last) * 1000;

        if ($elapsed_ms < $delay_ms) {
            usleep((int)(($delay_ms - $elapsed_ms) * 1000));
        }
    }
}
