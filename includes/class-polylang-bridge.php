<?php

namespace GeoTagger;

defined('ABSPATH') || exit;

class PolylangBridge {

    public function get_post_language(int $post_id): ?string {
        if (!function_exists('pll_get_post_language')) {
            return null;
        }
        $lang = pll_get_post_language($post_id);
        return $lang ?: null;
    }

    public function get_term_language(int $term_id): ?string {
        if (!function_exists('pll_get_term_language')) {
            return null;
        }
        $lang = pll_get_term_language($term_id);
        return $lang ?: null;
    }

    public function set_term_language(int $term_id, string $lang): void {
        if (!function_exists('pll_set_term_language')) {
            return;
        }
        pll_set_term_language($term_id, $lang);
    }

    public function get_term_translations(int $term_id): array {
        if (!function_exists('pll_get_term_translations')) {
            return [];
        }
        $translations = pll_get_term_translations($term_id);
        return is_array($translations) ? $translations : [];
    }

    public function save_term_translations(array $translations): void {
        if (!function_exists('pll_save_term_translations')) {
            return;
        }
        pll_save_term_translations($translations);
    }

    public function get_active_languages(): array {
        if (!function_exists('pll_languages_list')) {
            return [];
        }
        $langs = pll_languages_list(['fields' => 'slug']);
        return is_array($langs) ? $langs : [];
    }
}
