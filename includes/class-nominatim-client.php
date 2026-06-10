<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class NominatimClient {

    private const ENDPOINT   = 'https://nominatim.openstreetmap.org/reverse';
    private const TRANSIENT_PREFIX    = 'geo_tagger_nom_';
    private const RATE_LIMIT_TRANSIENT = 'geo_tagger_last_nominatim_request';

    private array $settings;

    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    public function reverse_geocode(float $lat, float $lng): ?array {
        $cache_key = self::TRANSIENT_PREFIX . md5("{$lat},{$lng}");
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $languages = ['fr', 'en', 'de'];
        $result    = [];

        foreach ($languages as $i => $lang) {
            if ($i > 0) {
                $this->rate_limit();
            }
            $data = $this->fetch($lat, $lng, $lang);
            $result[$lang] = $data;
        }

        $ttl = absint($this->settings['cache_days'] ?? 30) * DAY_IN_SECONDS;
        set_transient($cache_key, $result, $ttl);

        return $result;
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
            error_log("Geo Tagger: Nominatim returned HTTP {$code} for lang={$lang}");
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
