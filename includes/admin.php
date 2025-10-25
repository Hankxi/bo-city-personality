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

if (is_admin()) {
    if (!class_exists('WP_List_Table')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
    }

    class BO_CP_Results_List_Table extends WP_List_Table {
        protected int $deleted = 0;

        public function __construct() {
            parent::__construct([
                'plural'   => 'bo_cp_results',
                'singular' => 'bo_cp_result',
                'ajax'     => false,
            ]);
        }

        public function get_deleted_count(): int {
            return $this->deleted;
        }

        public function get_columns(): array {
            return [
                'cb'          => '<input type="checkbox" />',
                'created_at'  => __('Submitted', 'bo-city-personality'),
                'persona'     => __('Persona', 'bo-city-personality'),
                'lang'        => __('Language', 'bo-city-personality'),
                'location'    => __('Location', 'bo-city-personality'),
                'birth_date'  => __('Birth Date', 'bo-city-personality'),
                'hour_slot'   => __('Hour Slot', 'bo-city-personality'),
                'person_name' => __('Name', 'bo-city-personality'),
                'gender'      => __('Gender', 'bo-city-personality'),
                'email'       => __('Email', 'bo-city-personality'),
                'ip'          => __('IP Address', 'bo-city-personality'),
            ];
        }

        protected function get_sortable_columns(): array {
            return [
                'created_at'  => ['created_at', true],
                'persona'     => ['persona_key', false],
                'lang'        => ['lang', false],
                'birth_date'  => ['birth_date', false],
                'hour_slot'   => ['hour_slot', false],
                'person_name' => ['person_name', false],
                'gender'      => ['gender', false],
                'email'       => ['email', false],
                'ip'          => ['ip', false],
            ];
        }

        protected function column_cb($item): string {
            return '<input type="checkbox" name="result_id[]" value="' . esc_attr((string) $item['id']) . '" />';
        }

        protected function column_created_at($item): string {
            $gmt = (string) ($item['created_at'] ?? '');
            if ($gmt === '') {
                return '&mdash;';
            }

            $ts_gmt = strtotime($gmt . ' UTC');
            if (! $ts_gmt) {
                return esc_html($gmt);
            }

            $local = get_date_from_gmt($gmt, get_option('date_format') . ' ' . get_option('time_format'));
            $diff  = human_time_diff($ts_gmt, current_time('timestamp', true));

            return sprintf(
                '%s<br /><span class="description">%s</span>',
                esc_html($local),
                esc_html(sprintf(__('~ %s ago', 'bo-city-personality'), $diff))
            );
        }

        protected function column_persona($item): string {
            $lang = sanitize_key($item['lang'] ?? '');
            $persona_raw = (string) ($item['persona_key'] ?? '');
            $persona_key = $persona_raw !== '' ? bo_cp_canon_key($persona_raw) : '';

            $title = $persona_key !== '' ? bo_cp_persona_display_title($persona_key, $lang ?: 'en') : '';
            if ($title === '' && $persona_key !== '') {
                $title = $persona_key;
            }

            $actions = [];
            if ($persona_key !== '') {
                $post_id = bo_cp_find_persona_post_id($persona_key);
                if ($post_id) {
                    $actions['edit'] = '<a href="' . esc_url(get_edit_post_link($post_id)) . '">' . esc_html__('Edit Persona', 'bo-city-personality') . '</a>';
                }
            }

            $title_html = $title !== '' ? '<strong>' . esc_html($title) . '</strong>' : '&mdash;';
            $key_html   = $persona_key !== '' ? '<span class="description">' . esc_html($persona_key) . '</span>' : '';

            return $title_html . ($key_html ? '<br />' . $key_html : '') . $this->row_actions($actions);
        }

        protected function column_lang($item): string {
            $lang = strtoupper(sanitize_key($item['lang'] ?? ''));
            return $lang !== '' ? esc_html($lang) : '&mdash;';
        }

        protected function column_location($item): string {
            $parts = [];
            $city = (string) ($item['city'] ?? '');
            $country = (string) ($item['country'] ?? '');
            if ($city !== '') {
                $parts[] = $city;
            }
            if ($country !== '') {
                $parts[] = $country;
            }
            $out = $parts ? esc_html(implode(', ', $parts)) : '&mdash;';
            $lat = isset($item['lat']) ? floatval($item['lat']) : null;
            $lng = isset($item['lng']) ? floatval($item['lng']) : null;
            if ($lat !== null && $lng !== null && ($lat !== 0.0 || $lng !== 0.0)) {
                $out .= '<br /><span class="description">' . esc_html(round($lat, 4) . ', ' . round($lng, 4)) . '</span>';
            }
            return $out;
        }

        protected function column_birth_date($item): string {
            $date = (string) ($item['birth_date'] ?? '');
            if ($date === '') {
                return '&mdash;';
            }
            $ts = strtotime($date);
            if ($ts) {
                $formatted = date_i18n(get_option('date_format'), $ts);
                return esc_html($formatted);
            }
            return esc_html($date);
        }

        protected function column_hour_slot($item): string {
            $slot = (string) ($item['hour_slot'] ?? '');
            $tz   = (string) ($item['tz_id'] ?? '');
            $raw  = isset($item['raw_offset']) ? intval($item['raw_offset']) : 0;
            $dst  = isset($item['dst_offset']) ? intval($item['dst_offset']) : 0;

            if ($slot === '') {
                $slot_html = '&mdash;';
            } else {
                $slot_html = esc_html($slot);
            }

            $tz_bits = [];
            if ($tz !== '') {
                $tz_bits[] = $tz;
            }
            if ($raw !== 0 || $dst !== 0) {
                $offset = $raw / 3600;
                $tz_bits[] = sprintf('UTC %+0.1f%s', $offset, $dst ? (' (DST +' . ($dst / 3600) . ')') : '');
            }

            if (!empty($tz_bits)) {
                $slot_html .= '<br /><span class="description">' . esc_html(implode(' • ', $tz_bits)) . '</span>';
            }

            return $slot_html;
        }

        protected function column_person_name($item): string {
            $name = (string) ($item['person_name'] ?? '');
            if ($name === '') {
                return '&mdash;';
            }
            return esc_html($name);
        }

        protected function column_gender($item): string {
            $gender = (string) ($item['gender'] ?? '');
            if ($gender === '') {
                return '&mdash;';
            }
            return esc_html(ucwords(strtolower($gender)));
        }

        protected function column_email($item): string {
            $email = (string) ($item['email'] ?? '');
            if ($email === '') {
                return '&mdash;';
            }
            $safe = esc_attr($email);
            return '<a href="mailto:' . $safe . '">' . esc_html($email) . '</a>';
        }

        protected function column_ip($item): string {
            $ip = (string) ($item['ip'] ?? '');
            if ($ip === '') {
                return '&mdash;';
            }
            return esc_html($ip);
        }

        protected function column_default($item, $column_name) {
            return isset($item[$column_name]) ? esc_html((string) $item[$column_name]) : '&mdash;';
        }

        protected function get_bulk_actions(): array {
            return [
                'delete' => __('Delete', 'bo-city-personality'),
            ];
        }

        public function prepare_items(): void {
            $this->process_bulk_action();

            $columns  = $this->get_columns();
            $hidden   = [];
            $sortable = $this->get_sortable_columns();
            $this->_column_headers = [$columns, $hidden, $sortable];

            global $wpdb;
            bo_cp_ensure_results_table();
            $table = bo_cp_results_table_name();

            $per_page = 20;
            $current_page = max(1, $this->get_pagenum());
            $offset = ($current_page - 1) * $per_page;

            $search = isset($_REQUEST['s']) ? trim((string) wp_unslash($_REQUEST['s'])) : '';
            $orderby_req = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'created_at';
            $order_req = isset($_REQUEST['order']) ? strtoupper(sanitize_text_field($_REQUEST['order'])) : 'DESC';
            $allowed_orderby = [
                'created_at'  => 'created_at',
                'persona_key' => 'persona_key',
                'lang'        => 'lang',
                'birth_date'  => 'birth_date',
                'hour_slot'   => 'hour_slot',
                'person_name' => 'person_name',
                'gender'      => 'gender',
                'email'       => 'email',
                'ip'          => 'ip',
            ];
            $orderby = $allowed_orderby[$orderby_req] ?? 'created_at';
            $order = $order_req === 'ASC' ? 'ASC' : 'DESC';

            $where = 'WHERE 1=1';
            $params = [];
            if ($search !== '') {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $where .= ' AND (persona_key LIKE %s OR country LIKE %s OR city LIKE %s OR email LIKE %s OR person_name LIKE %s OR ip LIKE %s)';
                $params = array_fill(0, 6, $like);
            }

            $count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
            if ($params) {
                $total_items = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
            } else {
                $total_items = (int) $wpdb->get_var($count_sql);
            }

            $items_sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
            $items_params = $params;
            $items_params[] = $per_page;
            $items_params[] = $offset;
            $prepared_items_sql = $wpdb->prepare($items_sql, ...$items_params);
            $this->items = $wpdb->get_results($prepared_items_sql, ARRAY_A);

            $this->set_pagination_args([
                'total_items' => $total_items,
                'per_page'    => $per_page,
                'total_pages' => $per_page > 0 ? (int) ceil($total_items / $per_page) : 0,
            ]);
        }

        public function process_bulk_action(): void {
            if ('delete' !== $this->current_action()) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            check_admin_referer('bo_cp_results_bulk_action');

            $ids = isset($_REQUEST['result_id']) ? (array) $_REQUEST['result_id'] : [];
            $ids = array_filter(array_map('intval', $ids));
            if (empty($ids)) {
                return;
            }

            global $wpdb;
            bo_cp_ensure_results_table();
            $table = bo_cp_results_table_name();
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $sql = "DELETE FROM {$table} WHERE id IN ($placeholders)";
            $deleted = $wpdb->query($wpdb->prepare($sql, ...$ids));
            if ($deleted > 0) {
                $this->deleted = (int) $deleted;
            }
        }
    }
}

function bo_cp_render_results_page() {
    if (! current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions.', 'bo-city-personality'));
    }

    $list_table = new BO_CP_Results_List_Table();
    $list_table->prepare_items();

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Persona Submissions', 'bo-city-personality') . '</h1>';

    if ($list_table->get_deleted_count() > 0) {
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(sprintf(_n('%d submission deleted.', '%d submissions deleted.', $list_table->get_deleted_count(), 'bo-city-personality'), $list_table->get_deleted_count()))
        );
    }

    echo '<form method="get">';
    echo '<input type="hidden" name="post_type" value="city_persona" />';
    echo '<input type="hidden" name="page" value="bo-cp-results" />';
    $list_table->search_box(__('Search submissions', 'bo-city-personality'), 'bo-cp-results');
    echo '</form>';

    echo '<form method="post">';
    echo '<input type="hidden" name="post_type" value="city_persona" />';
    echo '<input type="hidden" name="page" value="bo-cp-results" />';
    if (!empty($_REQUEST['s'])) {
        echo '<input type="hidden" name="s" value="' . esc_attr((string) wp_unslash($_REQUEST['s'])) . '" />';
    }
    wp_nonce_field('bo_cp_results_bulk_action');
    $list_table->display();
    echo '</form>';
    echo '</div>';
}

add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=city_persona','Persona Submissions','Persona Submissions','manage_options','bo-cp-results','bo_cp_render_results_page');
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
    $processed_keys = array();
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
            if ($persona_key !== '') {
                $processed_keys[] = $persona_key;
            }
            $dispMap = bo_cp_extract_display_titles($data);

            $locales_meta = array();
            foreach (bo_cp_persona_supported_languages() as $lang => $label) {
                $localeData = isset($rawLocales[$lang]) && is_array($rawLocales[$lang]) ? $rawLocales[$lang] : array();

                $sections_input = array();
                $overview_content = '';

                $sections_source = array();
                if (isset($localeData['sections']) && is_array($localeData['sections'])) {
                    $sections_source = $localeData['sections'];
                }

                if (!empty($sections_source) && is_array($sections_source)) {
                    foreach ($sections_source as $secKey => $secVal) {
                        $rawKey = is_string($secKey) ? $secKey : '';
                        $canonKey = $rawKey === 'overview' ? 'overview' : ($rawKey !== '' ? bo_cp_canon_key($rawKey) : '');

                        $title = '';
                        $content = '';
                        if (is_array($secVal)) {
                            if (isset($secVal['title']) && is_scalar($secVal['title'])) {
                                $title = (string) $secVal['title'];
                            }
                            if (isset($secVal['content']) && is_scalar($secVal['content'])) {
                                $content = (string) $secVal['content'];
                            }
                        } elseif (is_scalar($secVal)) {
                            $content = (string) $secVal;
                        }

                        if ($canonKey === 'overview') {
                            if ($content !== '') {
                                $overview_content = $content;
                            }
                            continue;
                        }

                        if ($canonKey === '') {
                            continue;
                        }

                        $sections_input[] = array(
                            'key'     => $canonKey,
                            'title'   => $title,
                            'content' => $content,
                        );
                    }
                }

                if ($overview_content === '' && isset($localeData['overview']) && is_scalar($localeData['overview'])) {
                    $overview_content = (string) $localeData['overview'];
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

    $processed_keys = array_values(array_unique(array_filter($processed_keys)));

    $removed_posts = 0;
    $existing_posts = get_posts(array(
        'post_type'      => 'city_persona',
        'post_status'    => array('publish','draft','pending','private'),
        'numberposts'    => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));
    foreach ($existing_posts as $post_id) {
        $existing_key = bo_cp_canon_key((string) get_post_meta($post_id, 'persona_key', true));
        if ($existing_key === '' || in_array($existing_key, $processed_keys, true)) {
            continue;
        }
        wp_delete_post($post_id, true);
        $removed_posts++;
    }

    $removed_files = 0;
    foreach (bo_cp_persona_data_roots() as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $files = glob($root . '*.php');
        if (!is_array($files)) {
            continue;
        }
        foreach ($files as $file) {
            $file_key = bo_cp_canon_key(basename($file, '.php'));
            if ($file_key === '' || in_array($file_key, $processed_keys, true)) {
                continue;
            }
            if (@unlink($file)) {
                $removed_files++;
            }
        }
    }

    if ($removed_posts > 0) {
        $notes[] = $removed_posts . ' old personas removed';
    }
    if ($removed_files > 0) {
        $notes[] = $removed_files . ' orphaned data files deleted';
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
