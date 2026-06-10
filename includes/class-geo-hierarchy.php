<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class GeoHierarchy {

    private const LEVEL_KEYS = [
        'city'   => ['city', 'town', 'village', 'hamlet', 'municipality', 'district'],
        'county' => ['county', 'state_district', 'district', 'city_district'],
        'region' => ['state', 'province', 'region'],
    ];

    private array $continents;
    private array $countries;

    public function __construct() {
        $this->continents = require GEO_TAGGER_DIR . 'includes/data/continents.php';
        $this->countries  = require GEO_TAGGER_DIR . 'includes/data/countries.php';
    }

    /**
     * @param  array  $nominatim_data  Full output of NominatimClient::reverse_geocode() (all langs)
     * @param  string $lang            'fr' | 'en' | 'de'
     * @return array  Ordered list of ['level' => string, 'name' => string, 'country_code' => string]
     */
    public function build_tag_list(array $nominatim_data, string $lang): array {
        $raw = $nominatim_data[$lang] ?? null;
        if (!$raw || empty($raw['address'])) {
            return [];
        }

        $address = $raw['address'];
        $cc      = strtolower($address['country_code'] ?? '');

        $tags = [];

        // Continent (from lookup table)
        if (!empty($cc) && isset($this->continents[$cc][$lang])) {
            $tags[] = [
                'level'        => 'continent',
                'name'         => $this->continents[$cc][$lang],
                'country_code' => $cc,
            ];
        }

        // Country
        $country = $address['country'] ?? ($this->countries[$cc][$lang] ?? null);
        if ($country) {
            $tags[] = [
                'level'        => 'country',
                'name'         => $country,
                'country_code' => $cc,
            ];
        }

        // Region, county, city — in order from broad to narrow
        foreach (['region', 'county', 'city'] as $level) {
            $name = $this->extract_level($address, $level);
            if ($name) {
                $tags[] = [
                    'level'        => $level,
                    'name'         => $name,
                    'country_code' => $cc,
                ];
            }
        }

        return $tags;
    }

    private function extract_level(array $address, string $level): ?string {
        $keys = self::LEVEL_KEYS[$level] ?? [];
        foreach ($keys as $key) {
            if (!empty($address[$key])) {
                return $address[$key];
            }
        }
        return null;
    }
}
