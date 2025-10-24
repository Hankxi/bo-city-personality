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

function bo_cp_persona_data_dir(): string {
    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'bo-city-personality/data/';
    if ( ! wp_mkdir_p($dir) ) {}
    return $dir;
}

function bo_cp_persona_file_path(string $key): string {
    $key = bo_cp_canon_key($key);
    return bo_cp_persona_data_dir() . $key . '.php';
}

/** Read unified multi-language persona file; return array or default shell */
function bo_cp_read_persona_file(string $key): array {
    $file = bo_cp_persona_file_path($key);
    if ( file_exists($file) ) {
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

/**
 * Write (or update) unified multi-language persona file.
 * - $key canonicalized internally
 * - atomic write via temp + rename
 * - optimistic concurrency via $expectedVersion (optional)
 */
function bo_cp_write_persona_file(string $key, string $lang, string $displayTitleLang, array $sections, ?int $expectedVersion = null): bool {
    $key  = bo_cp_canon_key($key);
    $lang = preg_match('/^[a-z_\\-]{2,10}$/i', $lang) ? $lang : 'en';

    $data = bo_cp_read_persona_file($key);
    if ($expectedVersion !== null && isset($data['version']) && intval($data['version']) !== intval($expectedVersion)) {
        return false;
    }

    // normalize sections
    $norm = [];
    foreach ($sections as $k => $row) {
        $k = bo_cp_canon_key((string)$k);
        $title = isset($row['title']) ? (string)$row['title'] : '';
        $content = isset($row['content']) ? (string)$row['content'] : '';
        $norm[$k] = ['title' => $title, 'content' => $content];
    }

    $data['name'] = $key;
    if (!isset($data['displayTitle']) || !is_array($data['displayTitle'])) $data['displayTitle'] = [];
    $data['displayTitle'][$lang] = $displayTitleLang;

    if (!isset($data['locales']) || !is_array($data['locales'])) $data['locales'] = [];
    if (!isset($data['locales'][$lang]) || !is_array($data['locales'][$lang])) $data['locales'][$lang] = [];
    $data['locales'][$lang]['sections'] = $norm;
    $data['version'] = intval($data['version'] ?? 0) + 1;

    $export = var_export($data, true);
    $php = "<?php\nreturn " . $export . ";\n";

    $dir = bo_cp_persona_data_dir();
    if ( ! wp_mkdir_p($dir) ) return false;

    $file = bo_cp_persona_file_path($key);
    $tmp  = $file . '.' . ( function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('', true) ) . '.tmp';

    $bytes = @file_put_contents($tmp, $php, LOCK_EX);
    if ($bytes === false) return false;
    @chmod($tmp, 0644);
    $ok = @rename($tmp, $file);
    if ( ! $ok ) { @unlink($tmp); return false; }

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
        if (!is_array($sections)) $sections = [];
        set_transient($cache_key, ['_mtime'=>$mtime, 'sections'=>$sections], HOUR_IN_SECONDS);
        return $sections;
    }

    // Fallback to CPT
    $sections = [];
    $parent = get_page_by_title($key, OBJECT, 'city_persona');
    if ($parent instanceof WP_Post) {
        $children = get_children([
            'post_parent' => $parent->ID,
            'post_type'   => 'city_persona',
            'post_status' => 'any',
            'numberposts' => -1,
        ]);
        foreach ($children as $cid => $cp) {
            $cl = get_post_meta($cid,'lang',true);
            if ($cl === $lang) {
                $overview = get_post_field('post_content', $cid);
                $sections['overview'] = ['title'=>'', 'content'=>$overview];
                $meta = get_post_meta($cid,'sections_single',true);
                if (is_array($meta)) {
                    foreach ($meta as $row){
                        $k = bo_cp_canon_key($row['key'] ?? '');
                        if (!$k) continue;
                        $sections[$k] = [
                            'title'   => (string)($row['title'] ?? ''),
                            'content' => (string)($row['content'] ?? ''),
                        ];
                    }
                }
                break;
            }
        }
    }
    return $sections;
}

/** Build ETag/Last-Modified */
function bo_cp_persona_http_meta(string $key): array {
    $file = bo_cp_persona_file_path($key);
    if ( file_exists($file) ) {
        $mtime = @filemtime($file) ?: time();
        return ['etag' => '"' . md5(bo_cp_canon_key($key) . '|' . $mtime) . '"', 'lastmod' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT'];
    }
    return ['etag' => '"' . md5(bo_cp_canon_key($key) . '|cpt') . '"', 'lastmod' => gmdate('D, d M Y H:i:s', time()) . ' GMT'];
}

