<?php
// includes/bo-cp-io.php  (canonical-key edition)
if ( ! defined('ABSPATH') ) { exit; }

/** Make a canonical persona key: lowercase, non-alnum -> underscore, trim underscores */
if (!function_exists('bo_cp_canon_key')) {
function bo_cp_canon_key(string $s): string {
    $s = strtolower($s);
    $s = str_replace('–', '-', $s);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    return $s ?: 'persona';
}}

function bo_cp_persona_data_roots(): array {
    $roots = array();

    $plugin_root = trailingslashit(plugin_dir_path(__FILE__)) . '../data/';
    $roots[] = $plugin_root;

    $uploads = wp_upload_dir();
    if (!empty($uploads['basedir'])) {
        $roots[] = trailingslashit($uploads['basedir']) . 'bo-city-personality/data/';
    }

    return array_values(array_unique($roots));
}

function bo_cp_persona_data_dir(): string {
    $roots = bo_cp_persona_data_roots();
    $dir = end($roots);
    if (! $dir) {
        $dir = trailingslashit(WP_CONTENT_DIR) . 'uploads/bo-city-personality/data/';
    }
    if ( ! wp_mkdir_p($dir) ) {}
    return $dir;
}

function bo_cp_persona_locate_existing_file(string $key): string {
    $key = bo_cp_canon_key($key);
    foreach (bo_cp_persona_data_roots() as $root) {
        $candidate = $root . $key . '.php';
        if (file_exists($candidate)) {
            return $candidate;
        }
    }
    return '';
}

function bo_cp_persona_file_path(string $key): string {
    $key = bo_cp_canon_key($key);
    $existing = bo_cp_persona_locate_existing_file($key);
    if ($existing) {
        return $existing;
    }
    return bo_cp_persona_data_dir() . $key . '.php';
}

function bo_cp_find_persona_post_id(string $persona_key): int {
    $persona_key = bo_cp_canon_key($persona_key);
    $q = new WP_Query(array(
        'post_type'      => 'city_persona',
        'post_status'    => array('publish','draft','pending','private'),
        'posts_per_page' => 1,
        'meta_query'     => array(array('key' => 'persona_key', 'value' => $persona_key, 'compare' => '=')),
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));
    if (!empty($q->posts)) {
        return intval($q->posts[0]);
    }
    return 0;
}

/** Read unified multi-language persona file; return array or default shell */
function bo_cp_read_persona_file(string $key): array {
    $file = bo_cp_persona_locate_existing_file($key);
    if ( $file && file_exists($file) ) {
        $data = include $file;
        if ( is_array($data) ) return $data;
    }
    $key = bo_cp_canon_key($key);
    return [
        'name' => $key,
        'displayTitle' => [],
        'locales' => [],
        'version' => 0,
    ];
}

function bo_cp_normalize_lang_key($lang): string {
    if (!is_string($lang) && !is_numeric($lang)) {
        return '';
    }
    $lang = strtolower(trim((string) $lang));
    if ($lang === '') {
        return '';
    }
    if (preg_match('/^([a-z]{2})/i', $lang, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

function bo_cp_extract_dataset_locales(array $data): array {
    if (isset($data['locales']) && is_array($data['locales'])) {
        return $data['locales'];
    }
    return array();
}

function bo_cp_extract_display_titles(array $data): array {
    $titles = array();
    if (isset($data['displayTitle']) && is_array($data['displayTitle'])) {
        foreach ($data['displayTitle'] as $lang => $value) {
            $langKey = bo_cp_normalize_lang_key($lang);
            if ($langKey === '') {
                continue;
            }
            $titles[$langKey] = is_scalar($value) ? (string) $value : '';
        }
    }

    foreach ($data as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (preg_match('/^(?:display[_-]?title|title)_([a-z]{2})$/i', $key, $m)) {
            $lang = strtolower($m[1]);
            $titles[$lang] = is_scalar($value) ? (string) $value : '';
        }
    }

    return $titles;
}

function bo_cp_persona_display_title(string $key, string $lang = 'en'): string {
    $lang = strtolower($lang);
    $data = bo_cp_read_persona_file($key);
    if (!empty($data['displayTitle']) && is_array($data['displayTitle'])) {
        if (!empty($data['displayTitle'][$lang])) {
            return (string) $data['displayTitle'][$lang];
        }
        if (!empty($data['displayTitle']['en'])) {
            return (string) $data['displayTitle']['en'];
        }
        $first = reset($data['displayTitle']);
        if (is_string($first) && $first !== '') {
            return $first;
        }
    }

    if (!empty($data['locales']) && is_array($data['locales'])) {
        if (!empty($data['locales'][$lang]['displayTitle'])) {
            return (string) $data['locales'][$lang]['displayTitle'];
        }
        if (!empty($data['locales']['en']['displayTitle'])) {
            return (string) $data['locales']['en']['displayTitle'];
        }
    }

    return function_exists('bo_cp_display_title_from_key')
        ? bo_cp_display_title_from_key($key)
        : $key;
}

/**
 * Write (or update) unified multi-language persona file.
 * - $key canonicalized internally
 * - atomic write via temp + rename
 * - optimistic concurrency via $expectedVersion (optional)
 */
function bo_cp_write_persona_dataset(string $key, array $locales, ?int $expectedVersion = null): bool {
    $key  = bo_cp_canon_key($key);

    $data = bo_cp_read_persona_file($key);
    if ($expectedVersion !== null && isset($data['version']) && intval($data['version']) !== intval($expectedVersion)) {
        return false;
    }

    $display = [];
    $normalizedLocales = [];
    foreach ($locales as $lang => $locale) {
        $langKey = is_string($lang) ? strtolower($lang) : '';
        if (!preg_match('/^[a-z_\\-]{2,10}$/', $langKey)) {
            continue;
        }

        $display[$langKey] = (string)($locale['displayTitle'] ?? '');

        $sections = [];
        if (isset($locale['sections']) && is_array($locale['sections'])) {
            foreach ($locale['sections'] as $secKey => $secVal) {
                $canonKey = ($secKey === 'overview') ? 'overview' : bo_cp_canon_key((string)$secKey);
                if ($canonKey === '') {
                    continue;
                }
                $title = isset($secVal['title']) ? (string)$secVal['title'] : '';
                $content = isset($secVal['content']) ? (string)$secVal['content'] : '';
                $sections[$canonKey] = ['title' => $title, 'content' => $content];
            }
        }
        if (!isset($sections['overview'])) {
            $sections['overview'] = ['title' => '', 'content' => ''];
        }
        $normalizedLocales[$langKey] = ['sections' => $sections];
    }

    $data['name'] = $key;
    $data['displayTitle'] = $display;
    $data['locales'] = [];
    foreach ($normalizedLocales as $langKey => $localeRow) {
        $data['locales'][$langKey] = $localeRow;
    }
    $data['version'] = intval($data['version'] ?? 0) + 1;

    $export = var_export($data, true);
    $php = "<?php\nreturn " . $export . ";\n";

    $file = bo_cp_persona_file_path($key);
    $dir  = trailingslashit(dirname($file));
    if ( ! wp_mkdir_p($dir) ) return false;

    $tmp  = $dir . $key . '.' . ( function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('', true) ) . '.tmp';

    $bytes = @file_put_contents($tmp, $php, LOCK_EX);
    if ($bytes === false) { @unlink($tmp); return false; }
    @chmod($tmp, 0644);
    $ok = @rename($tmp, $file);
    if ( ! $ok ) { @unlink($tmp); return false; }
    clearstatcache(true, $file);

    // purge transients for this persona
    global $wpdb;
    if ($wpdb) {
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_bo_cp_sections_' . $key . '_') . '%'
        ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_timeout_bo_cp_sections_' . $key . '_') . '%'
        ) );
    }
    return true;
}

/**
 * Ensure section arrays expose sanitized aliases for backwards compatibility.
 */
function bo_cp_sections_with_aliases(array $sections): array {
    $aliases = $sections;

    foreach ($sections as $rawKey => $row) {
        if (!is_string($rawKey) || $rawKey === '') {
            continue;
        }

        $alias = ($rawKey === 'overview') ? 'overview' : bo_cp_canon_key($rawKey);
        if ($alias === '' || $alias === $rawKey) {
            continue;
        }

        if (!array_key_exists($alias, $aliases)) {
            $aliases[$alias] = $row;
        }
    }

    return $aliases;
}

/** Load sections for persona+lang with transient caching; fallback to CPT if file missing */
function bo_cp_load_sections(string $key, string $lang): array {
    $key  = bo_cp_canon_key($key);
    $lang = preg_match('/^[a-z_\\-]{2,10}$/i', $lang) ? $lang : 'en';

    $file = bo_cp_persona_file_path($key);
    if ( file_exists($file) ) {
        $cache_key = "bo_cp_sections_{$key}_{$lang}";
        $cached = get_transient($cache_key);
        $mtime = @filemtime($file) ?: 0;
        if ( is_array($cached) && isset($cached['_mtime']) && intval($cached['_mtime']) === $mtime ) {
            return $cached['sections'];
        }
        $data = include $file;
        $sections = $data['locales'][$lang]['sections'] ?? [];
        if (!is_array($sections)) {
            $sections = [];
        }
        $sections = bo_cp_sections_with_aliases($sections);
        set_transient($cache_key, ['_mtime'=>$mtime, 'sections'=>$sections], HOUR_IN_SECONDS);
        return $sections;
    }

    // Fallback to CPT meta
    $sections = [];
    $post_id = bo_cp_find_persona_post_id($key);
    if (!$post_id) {
        $post = get_page_by_title($key, OBJECT, 'city_persona');
        if ($post instanceof WP_Post) {
            $post_id = intval($post->ID);
        }
    }
    if ($post_id) {
        $locales = get_post_meta($post_id, 'locales', true);
        if (isset($locales[$lang]['sections']) && is_array($locales[$lang]['sections'])) {
            foreach ($locales[$lang]['sections'] as $secKey => $row) {
                $canonKey = ($secKey === 'overview') ? 'overview' : bo_cp_canon_key((string)$secKey);
                if ($canonKey === '') {
                    continue;
                }
                $title = isset($row['title']) ? (string)$row['title'] : '';
                $content = isset($row['content']) ? (string)$row['content'] : '';
                $sections[$canonKey] = ['title' => $title, 'content' => $content];
            }
        }
    }
    if (!isset($sections['overview'])) {
        $sections['overview'] = ['title' => '', 'content' => ''];
    }

    return bo_cp_sections_with_aliases($sections);
}

/** Build ETag/Last-Modified */
function bo_cp_persona_http_meta(string $key): array {
    $file = bo_cp_persona_locate_existing_file($key);
    if ( $file && file_exists($file) ) {
        $mtime = @filemtime($file) ?: time();
        return ['etag' => '"' . md5(bo_cp_canon_key($key) . '|' . $mtime) . '"', 'lastmod' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT'];
    }
    return ['etag' => '"' . md5(bo_cp_canon_key($key) . '|cpt') . '"', 'lastmod' => gmdate('D, d M Y H:i:s', time()) . ' GMT'];
}

