<?php
if ( ! defined('ABSPATH') ) { exit; }

require_once __DIR__ . '/bo-cp-io.php';

function bo_cp_persona_supported_languages(): array {
    return array(
        'en' => 'English',
        'zh' => 'Chinese',
    );
}

function bo_cp_get_persona_locales(int $post_id): array {
    $data = get_post_meta($post_id, 'locales', true);
    if (!is_array($data)) {
        $data = array();
    }
    foreach (bo_cp_persona_supported_languages() as $lang => $label) {
        if (!isset($data[$lang]) || !is_array($data[$lang])) {
            $data[$lang] = array();
        }
        if (!isset($data[$lang]['displayTitle'])) {
            $data[$lang]['displayTitle'] = '';
        }
        if (!isset($data[$lang]['sections']) || !is_array($data[$lang]['sections'])) {
            $data[$lang]['sections'] = array();
        }
        if (!isset($data[$lang]['sections']['overview']) || !is_array($data[$lang]['sections']['overview'])) {
            $data[$lang]['sections']['overview'] = array('title' => '', 'content' => '');
        } else {
            if (!isset($data[$lang]['sections']['overview']['title'])) {
                $data[$lang]['sections']['overview']['title'] = '';
            }
            if (!isset($data[$lang]['sections']['overview']['content'])) {
                $data[$lang]['sections']['overview']['content'] = '';
            }
        }
    }
    return $data;
}

function bo_cp_normalize_locale_submission(string $lang, array $input): array {
    $displayTitle = sanitize_text_field($input['displayTitle'] ?? '');
    $overview = wp_kses_post($input['overview'] ?? '');

    $sections = array();
    $sections['overview'] = array('title' => '', 'content' => $overview);

    if (!empty($input['sections']) && is_array($input['sections'])) {
        foreach ($input['sections'] as $row) {
            $key_raw = isset($row['key']) ? (string)$row['key'] : '';
            $key = $key_raw === 'overview' ? 'overview' : bo_cp_canon_key($key_raw);
            if ($key === 'overview') {
                continue;
            }
            $title = sanitize_text_field($row['title'] ?? '');
            $content = wp_kses_post($row['content'] ?? '');
            if ($key === '' && $title === '' && $content === '') {
                continue;
            }
            $sections[$key] = array(
                'title'   => $title,
                'content' => $content,
            );
        }
    }

    return array(
        'displayTitle' => $displayTitle,
        'sections'     => $sections,
    );
}

add_action('add_meta_boxes', function () {
    add_meta_box('bo_cp_persona', 'Persona Content', 'bo_cp_render_persona_box', 'city_persona', 'normal', 'high');
});

function bo_cp_render_persona_box(WP_Post $post): void {
    wp_nonce_field('bo_cp_save', 'bo_cp_nonce');
    wp_enqueue_editor();
    wp_enqueue_media();

    $persona_key = get_post_meta($post->ID, 'persona_key', true);
    if (! $persona_key) {
        $persona_key = bo_cp_canon_key(get_the_title($post));
    }

    $locales = bo_cp_get_persona_locales($post->ID);
    $languages = bo_cp_persona_supported_languages();

    echo '<p><label for="bo_cp_persona_key"><strong>Persona Key</strong></label>';
    echo '<input type="text" class="widefat" id="bo_cp_persona_key" name="bo_cp_persona_key" value="' . esc_attr($persona_key) . '" />';
    echo '<span class="description">Unique identifier written back to the PHP data file.</span></p>';

    foreach ($languages as $lang => $label) {
        $safe_lang = sanitize_key($lang);
        $locale = $locales[$lang];
        $display = $locale['displayTitle'] ?? '';
        $overview = $locale['sections']['overview']['content'] ?? '';

        $rows = array();
        foreach ($locale['sections'] as $key => $row) {
            if ($key === 'overview') {
                continue;
            }
            $rows[] = array(
                'key'     => $key,
                'title'   => $row['title'] ?? '',
                'content' => $row['content'] ?? '',
            );
        }
        if (empty($rows)) {
            $rows[] = array('key' => '', 'title' => '', 'content' => '');
        }

        echo '<div class="bo-cp-locale-block" data-lang="' . esc_attr($safe_lang) . '">';
        echo '<h3>' . esc_html($label) . ' (' . esc_html($safe_lang) . ')</h3>';
        echo '<p><label><strong>Display Title</strong></label>';
        echo '<input type="text" class="widefat" name="bo_cp_locales[' . esc_attr($safe_lang) . '][displayTitle]" value="' . esc_attr($display) . '" />';
        echo '</p>';

        echo '<p><label><strong>Overview</strong></label>';
        wp_editor($overview, 'bo_cp_overview_' . $safe_lang, array(
            'textarea_name' => 'bo_cp_locales[' . $safe_lang . '][overview]',
            'textarea_rows' => 8,
            'media_buttons' => true,
            'editor_class'  => 'bo-cp-wpeditor',
        ));
        echo '</p>';

        echo '<div class="bo-cp-sections" data-lang="' . esc_attr($safe_lang) . '">';
        $i = 0;
        foreach ($rows as $row) {
            $key   = $row['key'];
            $title = $row['title'];
            $content = $row['content'];
            $editor_id = 'bo_cp_section_' . $safe_lang . '_' . $i;
            echo '<div class="bo-cp-section-row" data-index="' . esc_attr((string)$i) . '">';
            echo '<div class="bo-cp-line"><span class="bo-cp-chip">' . esc_html($key !== '' ? $key : ('#' . $i)) . '</span><strong>Section</strong></div>';
            echo '<p><label>Key</label><input type="text" class="widefat" name="bo_cp_locales[' . esc_attr($safe_lang) . '][sections][' . esc_attr((string)$i) . '][key]" value="' . esc_attr($key) . '" /></p>';
            echo '<p><label>Title</label><input type="text" class="widefat" name="bo_cp_locales[' . esc_attr($safe_lang) . '][sections][' . esc_attr((string)$i) . '][title]" value="' . esc_attr($title) . '" /></p>';
            echo '<p><label>Content</label>';
            wp_editor($content, $editor_id, array(
                'textarea_name' => 'bo_cp_locales[' . $safe_lang . '][sections][' . $i . '][content]',
                'textarea_rows' => 8,
                'media_buttons' => true,
                'tinymce'       => true,
                'quicktags'     => true,
                'editor_class'  => 'bo-cp-wpeditor',
            ));
            echo '</p>';
            echo '<p><button type="button" class="button link-delete bo-cp-remove-section">Remove section</button></p>';
            echo '<hr /></div>';
            $i++;
        }
        echo '</div>';
        echo '<p><button type="button" class="button bo-cp-add-section" data-lang="' . esc_attr($safe_lang) . '">Add section</button></p>';
        echo '<p class="description">Leave all fields empty to drop a section. Sections use canonical keys when saved.</p>';
        echo '</div>';

        ob_start();
        ?>
        <div class="bo-cp-section-row" data-index="__INDEX__">
            <div class="bo-cp-line"><span class="bo-cp-chip">#__INDEX__</span><strong>Section</strong></div>
            <p><label>Key</label><input type="text" class="widefat" name="bo_cp_locales[<?php echo esc_attr($safe_lang); ?>][sections][__INDEX__][key]" value="" /></p>
            <p><label>Title</label><input type="text" class="widefat" name="bo_cp_locales[<?php echo esc_attr($safe_lang); ?>][sections][__INDEX__][title]" value="" /></p>
            <p><label>Content</label><textarea class="widefat wp-editor-area bo-cp-wpeditor" data-lang="<?php echo esc_attr($safe_lang); ?>" id="__EDITOR_ID__" rows="6" name="bo_cp_locales[<?php echo esc_attr($safe_lang); ?>][sections][__INDEX__][content]"></textarea></p>
            <p><button type="button" class="button link-delete bo-cp-remove-section">Remove section</button></p>
            <hr />
        </div>
        <?php
        $template = ob_get_clean();
        echo '<script type="text/template" id="bo-cp-template-' . esc_attr($safe_lang) . '">' . $template . '</script>';
    }

    static $printed_assets = false;
    if (! $printed_assets) {
        $printed_assets = true;
        echo '<style>
.bo-cp-locale-block{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin-bottom:18px}
.bo-cp-chip{display:inline-block;padding:2px 8px;border:1px solid #e5e7eb;border-radius:999px;font-size:12px;color:#555;background:#f8fafc}
.bo-cp-line{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.bo-cp-section-row{background:#f8fafc;border:1px solid #dbe3f0;border-radius:8px;padding:12px;margin-bottom:12px}
.bo-cp-section-row textarea:not(.wp-editor-area){font-family:monospace}
</style>';
        echo '<script>';
        echo <<<'JS'
(function(){
    function nextIndex(container){
        var rows = container.querySelectorAll('.bo-cp-section-row');
        var max = -1;
        rows.forEach(function(row){
            var idx = parseInt(row.getAttribute('data-index'), 10);
            if (!isNaN(idx) && idx > max) { max = idx; }
        });
        return max + 1;
    }
    function uniqueEditorId(lang, idx){
        return 'bo_cp_section_' + lang + '_' + idx + '_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    }
    function cloneSettings(obj){
        if (!obj || typeof obj !== 'object') { return obj; }
        if (window.jQuery && window.jQuery.extend) {
            return window.jQuery.extend(true, {}, obj);
        }
        var copy = {};
        Object.keys(obj).forEach(function(key){
            copy[key] = obj[key];
        });
        return copy;
    }
    function initEditor(row, lang){
        if (!window.wp || !wp.editor || !wp.editor.initialize) { return; }
        var textarea = row.querySelector('textarea.bo-cp-wpeditor');
        if (!textarea) { return; }
        var editorId = textarea.getAttribute('id');
        if (!editorId) {
            editorId = uniqueEditorId(lang, row.getAttribute('data-index') || 'new');
            textarea.setAttribute('id', editorId);
        }
        if (textarea.dataset.editorInitialized === '1') {
            return;
        }
        if (window.tinyMCE && window.tinyMCE.get(editorId)) {
            textarea.dataset.editorInitialized = '1';
            return;
        }
        if (window.QTags && window.QTags.getInstance && window.QTags.getInstance(editorId)) {
            textarea.dataset.editorInitialized = '1';
            return;
        }
        var settings = { mediaButtons: true };
        var baseId = 'bo_cp_overview_' + lang;
        if (window.tinyMCEPreInit && window.tinyMCEPreInit.mceInit && window.tinyMCEPreInit.mceInit[baseId]) {
            settings.tinymce = cloneSettings(window.tinyMCEPreInit.mceInit[baseId]);
            settings.tinymce.selector = '#' + editorId;
            if (settings.tinymce.body_class) {
                settings.tinymce.body_class = settings.tinymce.body_class.replace(baseId, editorId);
            }
        } else {
            settings.tinymce = true;
        }
        if (window.tinyMCEPreInit && window.tinyMCEPreInit.qtInit && window.tinyMCEPreInit.qtInit[baseId]) {
            settings.quicktags = cloneSettings(window.tinyMCEPreInit.qtInit[baseId]);
            settings.quicktags.id = editorId;
        } else {
            settings.quicktags = true;
        }
        wp.editor.initialize(editorId, settings);
        textarea.dataset.editorInitialized = '1';
    }
    function destroyEditor(row){
        if (!window.wp || !wp.editor || !wp.editor.remove) { return; }
        var textarea = row.querySelector('textarea.bo-cp-wpeditor');
        if (textarea && textarea.id) {
            try { wp.editor.remove(textarea.id); } catch (e) {}
        }
    }
    document.addEventListener('click', function(ev){
        if (ev.target.classList.contains('bo-cp-add-section')) {
            ev.preventDefault();
            var lang = ev.target.getAttribute('data-lang');
            var container = document.querySelector(".bo-cp-sections[data-lang='" + lang + "']");
            var tpl = document.getElementById('bo-cp-template-' + lang);
            if (!container || !tpl) { return; }
            var idx = nextIndex(container);
            var editorId = uniqueEditorId(lang, idx);
            var html = tpl.innerHTML.replace(/__INDEX__/g, idx).replace(/__EDITOR_ID__/g, editorId);
            container.insertAdjacentHTML('beforeend', html);
            var row = container.querySelector(".bo-cp-section-row[data-index='" + idx + "']");
            if (row) {
                initEditor(row, lang);
            }
        } else if (ev.target.classList.contains('bo-cp-remove-section')) {
            ev.preventDefault();
            var row = ev.target.closest('.bo-cp-section-row');
            if (row) {
                destroyEditor(row);
                row.parentNode.removeChild(row);
            }
        }
    });
    function bootstrapEditors(){
        document.querySelectorAll('.bo-cp-sections').forEach(function(container){
            var lang = container.getAttribute('data-lang');
            container.querySelectorAll('.bo-cp-section-row').forEach(function(row){
                initEditor(row, lang);
            });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrapEditors);
    } else {
        bootstrapEditors();
    }
})();
JS;
        echo '</script>';
    }
}

add_action('save_post_city_persona', function($post_id, $post){
    if ( wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ) { return; }
    if ( ! current_user_can('edit_post', $post_id) ) { return; }
    if ( ! isset($_POST['bo_cp_nonce']) || ! wp_verify_nonce($_POST['bo_cp_nonce'], 'bo_cp_save') ) { return; }

    $persona_key_raw = $_POST['bo_cp_persona_key'] ?? get_the_title($post_id);
    $persona_key = bo_cp_canon_key((string) $persona_key_raw);
    update_post_meta($post_id, 'persona_key', $persona_key);

    $locales_input = isset($_POST['bo_cp_locales']) && is_array($_POST['bo_cp_locales']) ? $_POST['bo_cp_locales'] : array();
    $locales_meta = array();

    foreach (bo_cp_persona_supported_languages() as $lang => $label) {
        $locale_raw = isset($locales_input[$lang]) && is_array($locales_input[$lang]) ? $locales_input[$lang] : array();
        $locales_meta[$lang] = bo_cp_normalize_locale_submission($lang, $locale_raw);
    }

    update_post_meta($post_id, 'locales', $locales_meta);
}, 10, 2);

add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=city_persona','Import from Data','Import from Data','edit_posts','bo-cp-sync','bo_cp_render_sync_page');
});

function bo_cp_render_sync_page() {
    if (! current_user_can('edit_posts')) wp_die('Insufficient permissions.');
    $synced = intval($_GET['synced'] ?? 0);
    $errors = intval($_GET['errors'] ?? 0);
    $notes  = sanitize_text_field($_GET['notes'] ?? '');

    echo '<div class="wrap"><h1>Import Personas from Data</h1>';
    if ($synced || $errors || $notes !== '') {
        echo '<div class="notice notice-info"><p><strong>Result:</strong> '
            . esc_html($synced) . ' processed, '
            . esc_html($errors) . ' failed'
            . ($notes !== '' ? (', notes: ' . esc_html($notes)) : '')
            . '.</p></div>';
    }

    echo '<p>Each <code>*.php</code> file becomes a single <code>city_persona</code> post with multilingual sections stored on that post.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('bo_cp_sync_run');
    echo '<input type="hidden" name="action" value="bo_cp_sync_run" />';
    echo '<label><input type="checkbox" name="skip_update_existing" value="1" /> Do not update existing personas (only create missing ones)</label>';
    echo '<p><button class="button button-primary">Run Import</button></p>';
    echo '</form></div>';
}

add_action('admin_post_bo_cp_sync_run', function () {
    if (! current_user_can('edit_posts')) wp_die('Insufficient permissions.');
    check_admin_referer('bo_cp_sync_run');

    $roots = bo_cp_persona_data_roots();

    $synced = 0; $errors = 0; $notes = array();
    $skip_update_existing = !empty($_POST['skip_update_existing']);

    foreach ($roots as $root) {
        if (! is_dir($root)) continue;
        foreach (glob($root . '*.php') as $file) {
            $data = @include $file;

            if (! is_array($data)) { $errors++; $notes[] = basename($file) . ': not array'; continue; }
            if (empty($data['name'])) { $errors++; $notes[] = basename($file) . ': no name'; continue; }
            $rawLocales = bo_cp_extract_dataset_locales($data);
            if (empty($rawLocales)) { $errors++; $notes[] = basename($file) . ': no locales[]'; continue; }

            $key_raw = (string) $data['name'];
            $persona_key = bo_cp_canon_key($key_raw);
            $dispMap = bo_cp_extract_display_titles($data);

            $locales_meta = array();
            foreach (bo_cp_persona_supported_languages() as $lang => $label) {
                $localeData = isset($rawLocales[$lang]) && is_array($rawLocales[$lang]) ? $rawLocales[$lang] : array();
                $sections_input = array();
                $overview_content = '';
                if (isset($localeData['sections']['overview']['content'])) {
                    $overview_content = (string) $localeData['sections']['overview']['content'];
                }
                if (!empty($localeData['sections']) && is_array($localeData['sections'])) {
                    foreach ($localeData['sections'] as $secKey => $secVal) {
                        if ($secKey === 'overview') { continue; }
                        $sections_input[] = array(
                            'key'     => is_string($secKey) ? $secKey : (string)$secKey,
                            'title'   => is_array($secVal) ? (string)($secVal['title'] ?? '') : '',
                            'content' => is_array($secVal) ? (string)($secVal['content'] ?? '') : (string)$secVal,
                        );
                    }
                }
                $displayTitle = (string)($dispMap[$lang] ?? ($localeData['displayTitle'] ?? ''));
                $locales_meta[$lang] = bo_cp_normalize_locale_submission($lang, array(
                    'displayTitle' => $displayTitle,
                    'overview'     => $overview_content,
                    'sections'     => $sections_input,
                ));
            }

            $post_id = bo_cp_find_persona_post_id($persona_key);
            $post_title = $locales_meta['en']['displayTitle'] ?: ($dispMap['en'] ?? $key_raw);
            if (! $post_title) {
                $post_title = $persona_key;
            }
            $overview_en = $locales_meta['en']['sections']['overview']['content'] ?? '';

            if ($post_id) {
                if ($skip_update_existing) {
                    $synced++;
                    continue;
                }
                $postarr = array(
                    'ID'           => $post_id,
                    'post_title'   => $post_title,
                    'post_content' => wp_kses_post($overview_en),
                );
                $ret = wp_update_post($postarr, true);
                if (is_wp_error($ret)) { $errors++; $notes[] = $persona_key . ': update failed ' . $ret->get_error_message(); continue; }
            } else {
                $postarr = array(
                    'post_type'    => 'city_persona',
                    'post_status'  => 'publish',
                    'post_title'   => $post_title,
                    'post_content' => wp_kses_post($overview_en),
                );
                $post_id = wp_insert_post($postarr, true);
                if (is_wp_error($post_id) || ! $post_id) { $errors++; $notes[] = $persona_key . ': insert failed'; continue; }
            }

            update_post_meta($post_id, 'persona_key', $persona_key);
            update_post_meta($post_id, 'locales', $locales_meta);
            $synced++;
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
