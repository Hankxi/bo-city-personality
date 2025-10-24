<?php
/**
 * helpers.php — utilities, storage, sync helpers (multilingual-aware)
 */
if ( ! defined('ABSPATH') ) { exit; }

// ---------- JSON response ----------
if (!function_exists('bo_cp_json_response')) {
    function bo_cp_json_response($data, $status = 200) {
        return new WP_REST_Response($data, $status);
    }
}

// ---------- Simple rate limit ----------
if (!function_exists('bo_cp_rate_limited')) {
    /**
     * @param string $key unique bucket (e.g. 'geo_'.ip)
     * @param int $limit max requests
     * @param int $window seconds
     */
    function bo_cp_rate_limited($key, $limit = 60, $window = 60) {
        $tkey = 'bo_cp_rl_' . md5($key);
        $bucket = get_transient($tkey);
        if (!is_array($bucket)) {
            $bucket = ['count' => 1, 'start' => time()];
            set_transient($tkey, $bucket, $window);
            return false;
        }
        if (time() - intval($bucket['start']) >= $window) {
            $bucket = ['count' => 1, 'start' => time()];
            set_transient($tkey, $bucket, $window);
            return false;
        }
        $bucket['count'] = intval($bucket['count']) + 1;
        set_transient($tkey, $bucket, $window);
        return $bucket['count'] > $limit;
    }
}

// ---------- Token sign/verify (HMAC-SHA256) ----------
if (!function_exists('bo_cp_secret_key')) {
    function bo_cp_secret_key() {
        if (defined('AUTH_SALT') && AUTH_SALT) return AUTH_SALT;
        if (defined('AUTH_KEY') && AUTH_KEY) return AUTH_KEY;
        return wp_salt('auth');
    }
}

if (!function_exists('bo_cp_sign_token')) {
    function bo_cp_sign_token($result_id, $persona_key) {
        $payload = [ 'i' => intval($result_id), 'p' => (string)$persona_key, 't' => time() ];
        $json = wp_json_encode($payload);
        $sig  = hash_hmac('sha256', $json, bo_cp_secret_key());
        $payload['s'] = $sig;
        return base64_encode(wp_json_encode($payload));
    }
}

if (!function_exists('bo_cp_verify_token')) {
    function bo_cp_verify_token($token, $ttl = 86400) { // 24h
        $raw = base64_decode($token, true);
        if ($raw === false) return false;
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['i']) || empty($data['p']) || empty($data['t']) || empty($data['s'])) return false;
        $sig = $data['s']; unset($data['s']);
        $json = wp_json_encode($data);
        $calc = hash_hmac('sha256', $json, bo_cp_secret_key());
        if (!hash_equals($calc, $sig)) return false;
        if ($ttl > 0 && (time() - intval($data['t']) > $ttl)) return false;
        return $data;
    }
}

// ---------- Display title fallback ----------
if (!function_exists('bo_cp_display_title_from_key')) {
    function bo_cp_display_title_from_key(string $key): string {
        $title = str_replace('_', ' ', $key);
        return preg_replace('/\s+/', ' ', trim($title));
    }
}

// ---------- Uploads data dir utilities ----------
if (!function_exists('bo_cp_uploads_data_dir')) {
    function bo_cp_uploads_data_dir(): string {
        $wp_upload = wp_upload_dir();
        $dir = trailingslashit($wp_upload['basedir']) . 'bo-city-personality/data/';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        return $dir;
    }
}
if (!function_exists('bo_cp_persona_upload_path')) {
    function bo_cp_persona_upload_path(string $name): string {
        $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        return bo_cp_uploads_data_dir() . $safe . '.php';
    }
}

// ---------- Parse a persona PHP file (preserve multilingual) ----------
if (!function_exists('bo_cp_parse_persona_file')) {
    function bo_cp_parse_persona_file(string $file): array {
        if (!file_exists($file)) return [];
        $data = include $file;
        if (!is_array($data)) return [];
        $name = $data['name'] ?? basename($file, '.php');
        $display = $data['displayTitle'] ?? bo_cp_display_title_from_key($name);
        $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];

        $norm = [];
        foreach ($sections as $k => $sec) {
            $key = is_string($k) ? $k : (string)$k;
            $title = $sec['title'] ?? '';
            $content = $sec['content'] ?? null;
            if (is_array($content)) {
                $zh = (string)($content['zh'] ?? '');
                $en = (string)($content['en'] ?? '');
                if (strpos($zh,'&lt;')!==false) $zh = html_entity_decode($zh, ENT_QUOTES|ENT_HTML5, 'UTF-8');
                if (strpos($en,'&lt;')!==false) $en = html_entity_decode($en, ENT_QUOTES|ENT_HTML5, 'UTF-8');
                $norm[$key] = [ 'title'=>$title, 'content'=>['zh'=>$zh,'en'=>$en] ];
            } else {
                $html = (string)($sec['html'] ?? '');
                if (strpos($html,'&lt;')!==false) $html = html_entity_decode($html, ENT_QUOTES|ENT_HTML5, 'UTF-8');
                $norm[$key] = [ 'title'=>$title, 'html'=>$html ];
            }
        }
        return [ 'name'=>$name, 'displayTitle'=>$display, 'sections'=>$norm ];
    }
}

// ---------- Create/Update a city_persona post from parsed array ----------
if (!function_exists('bo_cp_upsert_persona_post')) {
    function bo_cp_upsert_persona_post(array $parsed): int {
        if (empty($parsed['name'])) return 0;
        $name = $parsed['name'];
        $post = get_page_by_title($name, OBJECT, 'city_persona');

        // overview prefer en then zh
        $ov_en = '';
        $ov_zh = '';
        if (!empty($parsed['sections']['overview']['content']) && is_array($parsed['sections']['overview']['content'])) {
            $ov_en = $parsed['sections']['overview']['content']['en'] ?? '';
            $ov_zh = $parsed['sections']['overview']['content']['zh'] ?? '';
        } else if (!empty($parsed['sections']['overview']['html'])) {
            $ov_en = $parsed['sections']['overview']['html'];
        }
        $content = wp_kses_post($ov_en);

        $postarr = [
            'post_title'   => $name,
            'post_type'    => 'city_persona',
            'post_status'  => 'publish',
            'post_content' => $content,
        ];
        if ($post && $post instanceof WP_Post) {
            $postarr['ID'] = $post->ID;
            $post_id = wp_update_post($postarr, true);
        } else {
            $post_id = wp_insert_post($postarr, true);
        }
        if (is_wp_error($post_id) || !$post_id) return 0;

        // store overview_i18n
        update_post_meta($post_id, 'overview_i18n', ['en'=>$ov_en, 'zh'=>$ov_zh]);

        // sections meta (preserve multilingual, excluding overview)
        $sections_meta = [];
        foreach ($parsed['sections'] as $k => $sec) {
            if ($k === 'overview') continue;
            if (isset($sec['content']) && is_array($sec['content'])) {
                $sections_meta[] = [
                    'key'   => $k,
                    'title' => sanitize_text_field($sec['title'] ?? ''),
                    'content' => [
                        'zh' => wp_kses_post($sec['content']['zh'] ?? ''),
                        'en' => wp_kses_post($sec['content']['en'] ?? ''),
                    ],
                ];
            } else {
                $sections_meta[] = [
                    'key'   => $k,
                    'title' => sanitize_text_field($sec['title'] ?? ''),
                    'content' => wp_kses_post($sec['html'] ?? ''),
                ];
            }
        }
        update_post_meta($post_id, 'sections', $sections_meta);
        update_post_meta($post_id, 'displayTitle', $parsed['displayTitle'] ?? bo_cp_display_title_from_key($name));
        update_post_meta($post_id, 'persona_key', $name);

        return (int)$post_id;
    }
}

if (!function_exists('bo_cp_parse_hour_slot')) {
    function bo_cp_parse_hour_slot($slot): int {
        $slot = trim((string)$slot);
        if ($slot === '') {
            return 12;
        }
        if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $slot, $m)) {
            $a = max(0, min(23, intval($m[1])));
            $b = max(0, min(23, intval($m[2])));
            $mid = (int) round(($a + $b) / 2);
            return max(0, min(23, $mid));
        }
        if (preg_match('/^(\d{1,2})$/', $slot, $m)) {
            $h = intval($m[1]);
            if ($h < 0) { $h = 0; }
            if ($h > 23) { $h = $h % 24; }
            return $h;
        }
        return 12;
    }
}

if (!function_exists('bo_cp_results_table_name')) {
    function bo_cp_results_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'bo_cp_results';
    }
}

if (!function_exists('bo_cp_ensure_results_table')) {
    function bo_cp_ensure_results_table(): void {
        static $done = false;
        if ($done) { return; }
        global $wpdb;
        $table = bo_cp_results_table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            persona_key VARCHAR(191) NOT NULL,
            lang VARCHAR(20) NOT NULL DEFAULT 'en',
            country VARCHAR(191) DEFAULT '',
            city VARCHAR(191) DEFAULT '',
            birth_date DATE DEFAULT NULL,
            hour_slot VARCHAR(20) DEFAULT '',
            lat DOUBLE DEFAULT NULL,
            lng DOUBLE DEFAULT NULL,
            tz_id VARCHAR(191) DEFAULT '',
            raw_offset INT DEFAULT 0,
            dst_offset INT DEFAULT 0,
            email VARCHAR(191) DEFAULT '',
            person_name VARCHAR(191) DEFAULT '',
            gender VARCHAR(64) DEFAULT '',
            ip VARCHAR(100) DEFAULT '',
            user_agent TEXT NULL,
            meta LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY persona_key (persona_key),
            KEY created_at (created_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        $done = true;
    }
}

if (!function_exists('bo_cp_store_result')) {
    function bo_cp_store_result(array $data, array $meta = []): int {
        global $wpdb;
        bo_cp_ensure_results_table();
        $table = bo_cp_results_table_name();
        $row = [
            'created_at' => current_time('mysql', true),
            'persona_key'=> bo_cp_canon_key($data['persona_key'] ?? ''),
            'lang'       => strtolower($data['lang'] ?? 'en'),
            'country'    => (string)($data['country'] ?? ''),
            'city'       => (string)($data['city'] ?? ''),
            'birth_date' => ($data['birth_date'] ?? null) ?: null,
            'hour_slot'  => (string)($data['hour_slot'] ?? ''),
            'lat'        => isset($data['lat']) ? (float)$data['lat'] : null,
            'lng'        => isset($data['lng']) ? (float)$data['lng'] : null,
            'tz_id'      => (string)($data['tz_id'] ?? ''),
            'raw_offset' => isset($data['raw_offset']) ? intval($data['raw_offset']) : 0,
            'dst_offset' => isset($data['dst_offset']) ? intval($data['dst_offset']) : 0,
            'email'      => (string)($data['email'] ?? ''),
            'person_name'=> (string)($data['person_name'] ?? ''),
            'gender'     => (string)($data['gender'] ?? ''),
            'ip'         => (string)($data['ip'] ?? ''),
            'user_agent' => (string)($data['user_agent'] ?? ''),
            'meta'       => $meta ? wp_json_encode($meta) : null,
        ];
        $formats = [
            '%s','%s','%s','%s','%s','%s','%s','%f','%f','%s','%d','%d','%s','%s','%s','%s','%s','%s'
        ];
        $success = $wpdb->insert($table, $row, $formats);
        if ($success === false) {
            return 0;
        }
        return intval($wpdb->insert_id);
    }
}

if (!function_exists('bo_cp_get_result')) {
    function bo_cp_get_result(int $id): ?array {
        global $wpdb;
        if ($id <= 0) { return null; }
        bo_cp_ensure_results_table();
        $table = bo_cp_results_table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        if (!empty($row['meta'])) {
            $decoded = json_decode($row['meta'], true);
            if (is_array($decoded)) {
                $row['meta'] = $decoded;
            }
        } else {
            $row['meta'] = [];
        }
        return $row;
    }
}