<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Admin (City Persona) — unified multi-language + canonical keys
 */

add_action('add_meta_boxes', function () {
    add_meta_box('bo_cp_meta_key', 'Persona Identity', 'bo_cp_render_meta_key', 'city_persona', 'side', 'high');
    add_meta_box('bo_cp_sections', 'Sections (Single Language)', 'bo_cp_render_sections_box', 'city_persona', 'normal', 'high');
});

function bo_cp_is_child($post){ return intval($post->post_parent) > 0; }

function bo_cp_render_meta_key(WP_Post $post){
    wp_nonce_field('bo_cp_save','bo_cp_nonce');
    $is_child    = bo_cp_is_child($post);
    $persona_key = get_post_meta($post->ID, 'persona_key', true);
    if ( ! $persona_key ) {
        $persona_key = $is_child ? get_post_meta($post->post_parent, 'persona_key', true) : get_the_title($post);
    }
    $lang = get_post_meta($post->ID, 'lang', true);
    if ($lang !== 'zh' && $lang !== 'en') { $lang = $is_child ? 'en' : ''; }

    echo '<p><label>Persona Key</label><br/>';
    echo '<input type="text" class="widefat" name="bo_cp_persona_key" value="'.esc_attr($persona_key).'" '.($is_child?'':'').'/></p>';

    if ($is_child) {
        echo '<p><label>Language</label><br/>';
        echo '<select name="bo_cp_lang" class="widefat">';
        echo '<option value="en"'.selected($lang,'en',false).'>EN</option>';
        echo '<option value="zh"'.selected($lang,'zh',false).'>ZH</option>';
        echo '</select></p>';
        $sib = bo_cp_find_sibling_by_lang($post, $lang==='en' ? 'zh' : 'en');
        if ($sib) echo '<p><a class="button" href="'.esc_url(get_edit_post_link($sib)).'">Go to '.strtoupper($lang==='en'?'ZH':'EN').' page</a></p>';
    } else {
        echo '<p class="description">This is a <strong>parent</strong> persona (group). Create child pages for EN/ZH under this parent.</p>';
    }
}

function bo_cp_find_sibling_by_lang($post, $lang){
    $q = get_children(array(
        'post_parent' => $post->post_parent,
        'post_type'   => 'city_persona',
        'post_status' => 'any',
        'meta_query'  => array(array('key'=>'lang','value'=>$lang,'compare'=>'='))
    ));
    if (empty($q)) return 0;
    $ids = array_keys($q);
    foreach ($ids as $id) if ($id != $post->ID) return $id;
    return 0;
}

function bo_cp_render_sections_box(WP_Post $post){
    $is_child = bo_cp_is_child($post);
    $sections = get_post_meta($post->ID,'sections_single', true);
    if (!is_array($sections)) $sections = array();
    if (!$is_child) {
        echo '<p class="description">Sections are edited on language child pages (EN/ZH). Use the main editor above for a parent note if needed.</p>';
        return;
    }
    wp_enqueue_editor(); wp_enqueue_media();
    echo '<p class="description">Overview (of this language) uses the main editor above. Below are other sections for this language.</p>';
    echo '<div id="bo-cp-sections">';
    $i=0;
    foreach ($sections as $row){
        $key   = esc_attr($row['key'] ?? '');
        $title = esc_attr($row['title'] ?? '');
        $html  = $row['content'] ?? '';
        echo '<div class="bo-cp-row">';
        echo '<div class="bo-cp-line"><span class="bo-cp-chip">'.esc_html($key?:('#'.$i)).'</span><strong>'.esc_html($title?:'Section').'</strong></div>';
        echo '<p><label>Key</label><input class="widefat" name="bo_cp_sections['.$i.'][key]" value="'.$key.'" placeholder="career / love / health / relationship ..." /></p>';
        echo '<p><label>Title</label><input class="widefat" name="bo_cp_sections['.$i.'][title]" value="'.$title.'" /></p>';
        echo '<p><label>Content</label>';
        wp_editor($html, 'bo_cp_sections_'.$i.'_html', array('textarea_name'=>'bo_cp_sections['.$i.'][content]','media_buttons'=>true,'editor_height'=>240));
        echo '</p><hr/></div>';
        $i++;
    }
    if ($i===0){
        echo '<div class="bo-cp-row">';
        echo '<div class="bo-cp-line"><span class="bo-cp-chip">#0</span><strong>Section</strong></div>';
        echo '<p><label>Key</label><input class="widefat" name="bo_cp_sections[0][key]" value="" placeholder="career / love / health / relationship ..." /></p>';
        echo '<p><label>Title</label><input class="widefat" name="bo_cp_sections[0][title]" value="" /></p>';
        echo '<p><label>Content</label>';
        wp_editor('', 'bo_cp_sections_0_html', array('textarea_name'=>'bo_cp_sections[0][content]','media_buttons'=>true,'editor_height'=>240));
        echo '</p><hr/></div>';
    }
    echo '</div>';
    echo '<style>.bo-cp-row{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:12px}.bo-cp-line{display:flex;align-items:center;gap:8px;margin-bottom:8px}.bo-cp-chip{display:inline-block;padding:2px 8px;border:1px solid #e5e7eb;border-radius:999px;font-size:12px;color:#555;background:#f8fafc}</style>';
}

add_action('save_post_city_persona', function($post_id, $post){
    if ( wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;
    if ( ! isset($_POST['bo_cp_nonce']) || ! wp_verify_nonce($_POST['bo_cp_nonce'], 'bo_cp_save') ) return;

    // Canonicalize persona key when saving
    $persona_key_raw = $_POST['bo_cp_persona_key'] ?? get_the_title($post_id);
    if (function_exists('bo_cp_canon_key')) {
        $persona_key = bo_cp_canon_key($persona_key_raw);
    } else {
        $persona_key = sanitize_title($persona_key_raw);
    }
    update_post_meta($post_id,'persona_key',$persona_key);

    if (intval($post->post_parent) > 0) {
        $lang = sanitize_text_field($_POST['bo_cp_lang'] ?? 'en');
        if ($lang!=='en' && $lang!=='zh') $lang='en';
        update_post_meta($post_id,'lang',$lang);

        $rows = array();
        if (isset($_POST['bo_cp_sections']) && is_array($_POST['bo_cp_sections'])) {
            foreach ($_POST['bo_cp_sections'] as $r){
                $k = isset($r['key']) ? (function_exists('bo_cp_canon_key') ? bo_cp_canon_key($r['key']) : sanitize_key($r['key'])) : '';
                $t = sanitize_text_field($r['title'] ?? '');
                $c = wp_kses_post($r['content'] ?? '');
                if ($k==='' && $t==='' && $c==='') continue;
                $rows[] = array('key'=>$k,'title'=>$t,'content'=>$c);
            }
        }
        update_post_meta($post_id,'sections_single',$rows);
        // write-through handled by bo-cp-write-through.php (priority 20)
    }
}, 10, 2);

function bo_cp_collect_sections_for_lang($post_id){
    $sections = array();
    $overview = get_post_field('post_content', $post_id);
    $sections['overview'] = array('title'=>'', 'content'=> $overview);
    $meta = get_post_meta($post_id,'sections_single',true);
    if (is_array($meta)) {
        foreach ($meta as $row){
            $k = $row['key'] ?? ''; if ($k==='') continue;
            $sections[$k] = array('title'=>($row['title'] ?? ''), 'content'=>($row['content'] ?? ''));
        }
    }
    return $sections;
}

add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=city_persona','Import from Data','Import from Data','edit_posts','bo-cp-sync','bo_cp_render_sync_page');
});

function bo_cp_render_sync_page() {
    if (! current_user_can('edit_posts')) wp_die('Insufficient permissions.');
    $synced = intval($_GET['synced'] ?? 0);
    $errors = intval($_GET['errors'] ?? 0);
    $notes  = sanitize_text_field($_GET['notes'] ?? '');

    echo '<div class="wrap"><h1>Import Personas from Data (Unified)</h1>';
    if ($synced || $errors || $notes !== '') {
        echo '<div class="notice notice-info"><p><strong>Result:</strong> '
            . esc_html($synced) . ' synced, '
            . esc_html($errors) . ' failed'
            . ($notes !== '' ? (', notes: ' . esc_html($notes)) : '')
            . '.</p></div>';
    }

    echo '<p>Scans: <code>uploads/bo-city-personality/data/*.php</code> and <code>plugin_dir/data/*.php</code> for unified files.</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('bo_cp_sync_run');
    echo '<input type="hidden" name="action" value="bo_cp_sync_run" />';
    echo '<label><input type="checkbox" name="skip_update_existing" value="1" /> Do not update existing child pages (only create missing ones)</label>';
    echo '<p><button class="button button-primary">Run Import</button></p>';
    echo '</form></div>';
}

add_action('admin_post_bo_cp_sync_run', function () {
    if (! current_user_can('edit_posts')) wp_die('Insufficient permissions.');
    check_admin_referer('bo_cp_sync_run');

    $uploads = wp_upload_dir();
    $roots = array(
        trailingslashit($uploads['basedir']) . 'bo-city-personality/data/',
        trailingslashit(plugin_dir_path(__FILE__)) . '../data/'
    );

    $synced = 0; $errors = 0; $notes = array();
    $skip_update_existing = !empty($_POST['skip_update_existing']);

    foreach ($roots as $root) {
        if (! is_dir($root)) continue;
        foreach (glob($root . '*.php') as $file) {
            $data = @include $file;

            if (! is_array($data)) { $errors++; $notes[] = basename($file) . ': not array'; continue; }
            if (empty($data['name'])) { $errors++; $notes[] = basename($file) . ': no name'; continue; }
            if (! isset($data['locales']) || ! is_array($data['locales'])) { $errors++; $notes[] = basename($file) . ': no locales[]'; continue; }

            $key_raw = (string) $data['name'];
            $key     = function_exists('bo_cp_canon_key') ? bo_cp_canon_key($key_raw) : sanitize_title($key_raw);

            $parent_id = bo_cp_get_or_create_parent($key, $key);
            if (! $parent_id) { $errors++; $notes[] = $key . ': parent create failed'; continue; }

            $dispMap = is_array($data['displayTitle'] ?? null) ? $data['displayTitle'] : array();

            foreach ($data['locales'] as $lang => $localeData) {
                $lang = sanitize_text_field($lang);
                if ($lang !== 'en' && $lang !== 'zh') continue;

                $disp = (string) ($dispMap[$lang] ?? $key);
                $sections = array();
                if (isset($localeData['sections']) && is_array($localeData['sections'])) {
                    foreach ($localeData['sections'] as $k => $sec) {
                        $kk = function_exists('bo_cp_canon_key') ? bo_cp_canon_key((string)$k) : sanitize_key($k);
                        if (is_array($sec)) {
                            $sections[$kk] = array('title'=>(string)($sec['title'] ?? ''), 'content'=>(string)($sec['content'] ?? ''));
                        } else {
                            $sections[$kk] = array('title'=>'', 'content'=>(string)$sec);
                        }
                    }
                }

                $overview = (string)($sections['overview']['content'] ?? '');
                $rows = array();
                foreach ($sections as $k => $sec) {
                    if ($k === 'overview') continue;
                    $rows[] = array('key'=>$k, 'title'=>(string)($sec['title'] ?? ''), 'content'=>(string)($sec['content'] ?? ''));
                }

                $child_id = bo_cp_get_child_by_lang($parent_id, $lang);
                if ($child_id) {
                    if (! $skip_update_existing) {
                        $postarr = array('ID'=>$child_id, 'post_title'=>$disp, 'post_content'=>wp_kses_post($overview));
                        $ret = wp_update_post($postarr, true);
                        if (is_wp_error($ret)) { $errors++; $notes[] = $key . "[$lang]: update failed " . $ret->get_error_message(); continue; }
                        update_post_meta($child_id, 'persona_key', $key);
                        update_post_meta($child_id, 'lang', $lang);
                        update_post_meta($child_id, 'sections_single', $rows);
                        $synced++;
                    } else {
                        update_post_meta($child_id, 'persona_key', $key);
                        update_post_meta($child_id, 'lang', $lang);
                        if (! get_post_meta($child_id, 'sections_single', true)) {
                            update_post_meta($child_id, 'sections_single', $rows);
                        }
                        $synced++;
                    }
                } else {
                    $postarr = array(
                        'post_type'=>'city_persona','post_status'=>'publish','post_title'=>$disp,
                        'post_parent'=>$parent_id,'post_content'=>wp_kses_post($overview)
                    );
                    $child_id = wp_insert_post($postarr, true);
                    if (is_wp_error($child_id) || ! $child_id) { $errors++; $notes[] = $key . "[$lang]: insert failed"; continue; }
                    update_post_meta($child_id, 'persona_key', $key);
                    update_post_meta($child_id, 'lang', $lang);
                    update_post_meta($child_id, 'sections_single', $rows);
                    $synced++;
                }
            }
        }
    }

    $args = array(
        'post_type' => 'city_persona',
        'page'      => 'bo-cp-sync',
        'synced'    => $synced,
        'errors'    => $errors,
        'notes'     => substr(implode('; ', array_slice($notes, 0, 6)), 0, 300),
    );
    wp_safe_redirect( add_query_arg($args, admin_url('edit.php')) );
    exit;
});

function bo_cp_get_or_create_parent($persona_key, $unused) {
    $q = new WP_Query(array(
        'post_type'=>'city_persona',
        'post_status'=>array('publish','draft','pending','private'),
        'posts_per_page'=>1,
        'post_parent'=>0,
        'meta_query'=>array(array('key'=>'persona_key','value'=>$persona_key,'compare'=>'=')),
        'fields'=>'ids','no_found_rows'=>true,
    ));
    if (! empty($q->posts)) return intval($q->posts[0]);

    $postarr = array('post_type'=>'city_persona','post_status'=>'publish','post_title'=>$persona_key,'post_parent'=>0);
    $pid = wp_insert_post($postarr, true);
    if (is_wp_error($pid) || ! $pid) return 0;
    update_post_meta($pid, 'persona_key', $persona_key);
    return intval($pid);
}

function bo_cp_get_child_by_lang($parent_id, $lang) {
    $children = get_children(array('post_parent'=>$parent_id,'post_type'=>'city_persona','post_status'=>'any','fields'=>'ids'));
    if (empty($children)) return 0;
    foreach ($children as $cid) { if (get_post_meta($cid,'lang',true) === $lang) return intval($cid); }
    return 0;
}

