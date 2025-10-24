<?php
// includes/bo-cp-write-through.php
// Hook into save_post_city_persona to "write-through" updates into the unified data file.

if ( ! defined('ABSPATH') ) { exit; }

require_once __DIR__ . '/bo-cp-io.php';

function bo_cp_collect_persona_dataset_from_post(int $post_id): array {
    $meta = get_post_meta($post_id, 'locales', true);
    if (!is_array($meta)) {
        return [];
    }
    $dataset = [];
    foreach ($meta as $lang => $locale) {
        if (!is_string($lang) || $lang === '') {
            continue;
        }
        $sections = [];
        if (isset($locale['sections']) && is_array($locale['sections'])) {
            foreach ($locale['sections'] as $secKey => $row) {
                $canonKey = ($secKey === 'overview') ? 'overview' : bo_cp_canon_key((string)$secKey);
                if ($canonKey === '') {
                    continue;
                }
                $title = isset($row['title']) ? (string)$row['title'] : '';
                $content = isset($row['content']) ? (string)$row['content'] : '';
                $sections[$canonKey] = ['title' => $title, 'content' => $content];
            }
        }
        if (!isset($sections['overview'])) {
            $sections['overview'] = ['title' => '', 'content' => ''];
        }
        $dataset[$lang] = [
            'displayTitle' => (string)($locale['displayTitle'] ?? ''),
            'sections'     => $sections,
        ];
    }
    return $dataset;
}

add_action('save_post_city_persona', function($post_id, $post){
    if ( wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;
    if ( ! isset($_POST['bo_cp_nonce']) || ! wp_verify_nonce($_POST['bo_cp_nonce'], 'bo_cp_save') ) return;

    $persona_key_raw = $_POST['bo_cp_persona_key'] ?? get_post_meta($post_id, 'persona_key', true) ?? get_the_title($post_id);
    $persona_key = bo_cp_canon_key((string)$persona_key_raw);
    if (!$persona_key) {
        return;
    }

    $dataset = bo_cp_collect_persona_dataset_from_post($post_id);
    if (empty($dataset)) {
        return;
    }

    $current = bo_cp_read_persona_file($persona_key);
    $expected = intval($current['version'] ?? 0);
    $ok = bo_cp_write_persona_dataset($persona_key, $dataset, $expected);
    if ( ! $ok ) {
        bo_cp_write_persona_dataset($persona_key, $dataset, null);
        add_filter('redirect_post_location', function($loc){
            return add_query_arg(['bo_cp_notice' => 'version_conflict'], $loc);
        });
    }
}, 30, 2);

// Admin notice for version conflict
add_action('admin_notices', function(){
    if ( isset($_GET['bo_cp_notice']) && $_GET['bo_cp_notice'] === 'version_conflict' ) {
        echo '<div class="notice notice-warning is-dismissible"><p>Persona file changed during your edit. Your changes were saved, but please double-check the latest content.</p></div>';
    }
});
