<?php
// includes/bo-cp-write-through.php
// Hook into save_post_city_persona to "write-through" updates into the unified data file.

if ( ! defined('ABSPATH') ) { exit; }

require_once __DIR__ . '/bo-cp-io.php';

/**
 * Build flat sections array for a child language page.
 * - overview: from post_content
 * - others: from sections_single meta
 */
function bo_cp_build_sections_from_post(int $post_id): array {
    $sections = [];
    $overview = get_post_field('post_content', $post_id);
    $sections['overview'] = ['title' => '', 'content' => (string)$overview];
    $meta = get_post_meta($post_id, 'sections_single', true);
    if (is_array($meta)) {
        foreach ($meta as $row) {
            $k = sanitize_key($row['key'] ?? '');
            if (!$k) continue;
            $sections[$k] = [
                'title'   => (string)($row['title'] ?? ''),
                'content' => (string)($row['content'] ?? ''),
            ];
        }
    }
    return $sections;
}

add_action('save_post_city_persona', function($post_id, $post){
    if ( wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;
    if ( ! isset($_POST['bo_cp_nonce']) || ! wp_verify_nonce($_POST['bo_cp_nonce'], 'bo_cp_save') ) return;

    $persona_key = sanitize_key( $_POST['bo_cp_persona_key'] ?? get_post_meta($post_id, 'persona_key', true) );
    if (!$persona_key) $persona_key = sanitize_key(get_the_title($post_id));

    // write-through only for language children
    if ( intval($post->post_parent) > 0 ) {
        $lang = sanitize_text_field($_POST['bo_cp_lang'] ?? get_post_meta($post_id,'lang',true) ?: 'en');
        if ($lang!=='en' && $lang!=='zh') $lang = 'en';

        $sections = bo_cp_build_sections_from_post($post_id);
        $displayTitle = get_the_title($post_id);

        // Try with optimistic version (read current)
        $current = bo_cp_read_persona_file($persona_key);
        $expected = intval($current['version'] ?? 0);
        $ok = bo_cp_write_persona_file($persona_key, $lang, $displayTitle, $sections, $expected);
        if ( ! $ok ) {
            // fallback: write anyway (last save wins), but add admin notice
            bo_cp_write_persona_file($persona_key, $lang, $displayTitle, $sections, null);
            add_filter('redirect_post_location', function($loc){
                return add_query_arg(['bo_cp_notice' => 'version_conflict'], $loc);
            });
        }
    }
}, 20, 2);

// Admin notice for version conflict
add_action('admin_notices', function(){
    if ( isset($_GET['bo_cp_notice']) && $_GET['bo_cp_notice'] === 'version_conflict' ) {
        echo '<div class="notice notice-warning is-dismissible"><p>Persona file changed during your edit. Your changes were saved, but please double-check the latest content.</p></div>';
    }
});