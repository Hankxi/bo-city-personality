<?php
/**
 * helpers.php — utilities, storage, sync helpers (multilingual-aware)
 */
if ( ! defined('ABSPATH') ) { exit; }

// ---------- Language helpers ----------
if (!function_exists('bo_cp_supported_language_codes')) {
    function bo_cp_supported_language_codes(): array {
        if (function_exists('bo_cp_persona_supported_languages')) {
            $langs = bo_cp_persona_supported_languages();
            if (is_array($langs) && !empty($langs)) {
                return array_values(array_filter(array_map('sanitize_key', array_keys($langs))));
            }
        }

        return ['en', 'zh'];
    }
}

if (!function_exists('bo_cp_preferred_lang')) {
    function bo_cp_preferred_lang($requested = '', string $fallback = ''): string {
        $supported = bo_cp_supported_language_codes();
        if (empty($supported)) {
            $supported = ['en'];
        }
        $supported = array_values(array_unique(array_filter($supported)));
        $supported_map = array_fill_keys($supported, true);
        $fallback = $fallback !== '' ? $fallback : $supported[0];

        $candidates = [];
        if (is_string($requested) && $requested !== '') {
            $candidates[] = strtolower(trim($requested));
        }

        if (function_exists('determine_locale')) {
            $candidates[] = strtolower((string) determine_locale());
        }
        if (function_exists('get_user_locale')) {
            $candidates[] = strtolower((string) get_user_locale());
        }
        $candidates[] = strtolower((string) get_locale());
        $candidates[] = strtolower((string) get_bloginfo('language'));

        $accept = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        if ($accept !== '') {
            foreach (explode(',', $accept) as $piece) {
                $piece = strtolower(trim($piece));
                if ($piece !== '') {
                    $candidates[] = $piece;
                }
            }
        }

        foreach ($candidates as $cand) {
            if ($cand === '') {
                continue;
            }
            if (isset($supported_map[$cand])) {
                return $cand;
            }

            if (strpos($cand, '-') !== false) {
                $short = substr($cand, 0, strpos($cand, '-'));
                if (isset($supported_map[$short])) {
                    return $short;
                }
            }
            if (strpos($cand, '_') !== false) {
                $short = substr($cand, 0, strpos($cand, '_'));
                if (isset($supported_map[$short])) {
                    return $short;
                }
            }

            if (strlen($cand) >= 2) {
                $short = substr($cand, 0, 2);
                if (isset($supported_map[$short])) {
                    return $short;
                }
            }
        }

        return $fallback;
    }
}

if (!function_exists('bo_cp_mb_lower')) {
    function bo_cp_mb_lower(string $value): string {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

if (!function_exists('bo_cp_top_country_codes')) {
    function bo_cp_top_country_codes(): array {
        static $codes = null;
        if (is_array($codes)) {
            return $codes;
        }
        $codes = [
            'CN','IN','US','ID','PK','NG','BR','BD','RU','MX',
            'JP','ET','PH','EG','VN','CD','IR','TR','DE','TH',
            'GB','FR','IT','ZA','TZ','MM','KE','CO','ES','UG',
            'AR','DZ','SD','UA','IQ','AF','PL','CA','MA','SA',
            'UZ','PE','AO','MY','MZ','GH','YE','NP','VE','MG',
            'CM','CI','KR','SY','RO','KZ','MW','CL','ZM','GT',
            'EC','TD','SO','SN','KH','ZW','GN','RW','BJ','BI',
            'TN','BO','HT','BE','SS','DO','CZ','GR','JO','PT',
            'AZ','SE','HN','AE','HU','TJ','BY','AT','CH','IL',
            'PG','RS','TG','SL','LA','PY','BG','AU','NI','KG',
        ];
        return $codes;
    }
}

if (!function_exists('bo_cp_country_catalog')) {
    function bo_cp_country_catalog(): array {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $catalog = [];
        $path = '/usr/share/zoneinfo/iso3166.tab';
        if (is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }
                    $parts = preg_split('/\s+/', trim($line), 2);
                    if (count($parts) >= 2) {
                        $code = strtoupper(trim($parts[0]));
                        $name = trim($parts[1]);
                        if ($code !== '' && $name !== '') {
                            $catalog[$code] = ['name' => $name];
                        }
                    }
                }
            }
        }

        $top_names = [
            'CN' => 'China',
            'IN' => 'India',
            'US' => 'United States',
            'ID' => 'Indonesia',
            'PK' => 'Pakistan',
            'NG' => 'Nigeria',
            'BR' => 'Brazil',
            'BD' => 'Bangladesh',
            'RU' => 'Russia',
            'MX' => 'Mexico',
            'JP' => 'Japan',
            'ET' => 'Ethiopia',
            'PH' => 'Philippines',
            'EG' => 'Egypt',
            'VN' => 'Vietnam',
            'CD' => 'Democratic Republic of the Congo',
            'IR' => 'Iran',
            'TR' => 'Turkey',
            'DE' => 'Germany',
            'TH' => 'Thailand',
            'GB' => 'United Kingdom',
            'FR' => 'France',
            'IT' => 'Italy',
            'ZA' => 'South Africa',
            'TZ' => 'Tanzania',
            'MM' => 'Myanmar',
            'KE' => 'Kenya',
            'CO' => 'Colombia',
            'ES' => 'Spain',
            'UG' => 'Uganda',
            'AR' => 'Argentina',
            'DZ' => 'Algeria',
            'SD' => 'Sudan',
            'UA' => 'Ukraine',
            'IQ' => 'Iraq',
            'AF' => 'Afghanistan',
            'PL' => 'Poland',
            'CA' => 'Canada',
            'MA' => 'Morocco',
            'SA' => 'Saudi Arabia',
            'UZ' => 'Uzbekistan',
            'PE' => 'Peru',
            'AO' => 'Angola',
            'MY' => 'Malaysia',
            'MZ' => 'Mozambique',
            'GH' => 'Ghana',
            'YE' => 'Yemen',
            'NP' => 'Nepal',
            'VE' => 'Venezuela',
            'MG' => 'Madagascar',
            'CM' => 'Cameroon',
            'CI' => "Côte d'Ivoire",
            'KR' => 'South Korea',
            'SY' => 'Syria',
            'RO' => 'Romania',
            'KZ' => 'Kazakhstan',
            'MW' => 'Malawi',
            'CL' => 'Chile',
            'ZM' => 'Zambia',
            'GT' => 'Guatemala',
            'EC' => 'Ecuador',
            'TD' => 'Chad',
            'SO' => 'Somalia',
            'SN' => 'Senegal',
            'KH' => 'Cambodia',
            'ZW' => 'Zimbabwe',
            'GN' => 'Guinea',
            'RW' => 'Rwanda',
            'BJ' => 'Benin',
            'BI' => 'Burundi',
            'TN' => 'Tunisia',
            'BO' => 'Bolivia',
            'HT' => 'Haiti',
            'BE' => 'Belgium',
            'SS' => 'South Sudan',
            'DO' => 'Dominican Republic',
            'CZ' => 'Czech Republic',
            'GR' => 'Greece',
            'JO' => 'Jordan',
            'PT' => 'Portugal',
            'AZ' => 'Azerbaijan',
            'SE' => 'Sweden',
            'HN' => 'Honduras',
            'AE' => 'United Arab Emirates',
            'HU' => 'Hungary',
            'TJ' => 'Tajikistan',
            'BY' => 'Belarus',
            'AT' => 'Austria',
            'CH' => 'Switzerland',
            'IL' => 'Israel',
            'PG' => 'Papua New Guinea',
            'RS' => 'Serbia',
            'TG' => 'Togo',
            'SL' => 'Sierra Leone',
            'LA' => 'Laos',
            'PY' => 'Paraguay',
            'BG' => 'Bulgaria',
            'AU' => 'Australia',
            'NI' => 'Nicaragua',
            'KG' => 'Kyrgyzstan',
        ];

        if (empty($catalog)) {
            foreach (bo_cp_top_country_codes() as $code) {
                $name = $top_names[$code] ?? $code;
                $catalog[$code] = ['name' => $name];
            }
        } else {
            foreach (bo_cp_top_country_codes() as $code) {
                if (!isset($catalog[$code]) && isset($top_names[$code])) {
                    $catalog[$code] = ['name' => $top_names[$code]];
                }
            }
        }

        $cache = $catalog;
        return $catalog;
    }
}

if (!function_exists('bo_cp_country_display_name')) {
    function bo_cp_country_display_name(string $code, string $lang = 'en'): string {
        $code = strtoupper(trim($code));
        $catalog = bo_cp_country_catalog();
        $fallback = $catalog[$code]['name'] ?? $code;
        if (class_exists('Locale')) {
            try {
                $display = Locale::getDisplayRegion('-' . $code, $lang ?: 'en');
                if (is_string($display) && $display !== '') {
                    return $display;
                }
            } catch (Throwable $e) {
                // ignore and fallback
            }
        }
        return $fallback;
    }
}

if (!function_exists('bo_cp_country_list')) {
    function bo_cp_country_list(string $lang = 'en'): array {
        $catalog = bo_cp_country_catalog();
        $topCodes = array_fill_keys(bo_cp_top_country_codes(), true);
        $lang = $lang ?: 'en';
        $out = [];
        foreach ($catalog as $code => $meta) {
            if (!isset($topCodes[$code])) {
                continue;
            }
            $english = bo_cp_country_display_name($code, 'en');
            $display = bo_cp_country_display_name($code, $lang);
            $out[] = [
                'code'    => $code,
                'name'    => $english,
                'display' => $display,
                'label'   => $display,
            ];
        }
        $ordering = array_flip(bo_cp_top_country_codes());
        usort($out, function ($a, $b) use ($ordering) {
            $posA = $ordering[$a['code']] ?? PHP_INT_MAX;
            $posB = $ordering[$b['code']] ?? PHP_INT_MAX;
            if ($posA === $posB) {
                return strcasecmp($a['display'], $b['display']);
            }
            return $posA <=> $posB;
        });
        return $out;
    }
}

if (!function_exists('bo_cp_normalize_country_input')) {
    function bo_cp_normalize_country_input(string $input, string $lang = 'en'): array {
        $input = trim($input);
        if ($input === '') {
            return ['code' => '', 'name' => '', 'display' => ''];
        }

        $catalog = bo_cp_country_catalog();
        $upper = strtoupper($input);
        if (isset($catalog[$upper])) {
            return [
                'code'    => $upper,
                'name'    => bo_cp_country_display_name($upper, 'en'),
                'display' => bo_cp_country_display_name($upper, $lang),
            ];
        }

        $lower = bo_cp_mb_lower($input);
        foreach ($catalog as $code => $meta) {
            $english = bo_cp_mb_lower($meta['name'] ?? '');
            if ($english !== '' && $english === $lower) {
                return [
                    'code'    => $code,
                    'name'    => bo_cp_country_display_name($code, 'en'),
                    'display' => bo_cp_country_display_name($code, $lang),
                ];
            }
        }

        if (class_exists('Locale')) {
            $locales = array_unique(array_filter([$lang, 'en', 'zh']));
            foreach ($catalog as $code => $meta) {
                foreach ($locales as $loc) {
                    try {
                        $display = Locale::getDisplayRegion('-' . $code, $loc);
                    } catch (Throwable $e) {
                        $display = '';
                    }
                    if ($display !== '' && bo_cp_mb_lower($display) === $lower) {
                        return [
                            'code'    => $code,
                            'name'    => bo_cp_country_display_name($code, 'en'),
                            'display' => bo_cp_country_display_name($code, $lang),
                        ];
                    }
                }
            }
        }

        return [
            'code'    => '',
            'name'    => $input,
            'display' => $input,
        ];
    }
}

if (!function_exists('bo_cp_form_strings')) {
    function bo_cp_form_strings(string $lang = 'en'): array {
        $lang = $lang ?: 'en';
        $genderOptions = [
            ''        => 'Prefer not to say',
            'female'  => 'Female',
            'male'    => 'Male',
        ];

        $strings = [
            'country_label'        => 'Country or region',
            'country_placeholder'  => 'Start typing a country or enter its ISO code (e.g. Canada or CA)',
            'country_helper'       => 'You can enter the full country name or its two-letter ISO code.',
            'city_label'           => 'City',
            'city_placeholder'     => 'Start typing to search cities',
            'city_helper'          => 'Pick a suggestion to help us find the exact location.',
            'city_loading'         => 'Searching cities…',
            'city_no_results'      => 'No matches found. Try another spelling.',
            'birth_label'          => 'Birth date',
            'birth_helper'         => 'Pick a date or type it as YYYY-MM-DD.',
            'birth_placeholder'    => 'YYYY-MM-DD',
            'birth_toggle'         => 'Switch to manual entry',
            'birth_toggle_back'    => 'Switch to calendar',
            'hour_label'           => 'Birth time (24-hour)',
            'hour_placeholder'     => 'HH:MM',
            'hour_slots'           => [],
            'name_label'           => 'Name (optional)',
            'name_placeholder'     => 'Your name',
            'gender_label'         => 'Gender',
            'gender_helper'        => 'Optional, used for personalised notes.',
            'gender_placeholder'   => 'Select',
            'gender_options'       => $genderOptions,
            'email_label'          => 'Email (optional)',
            'email_placeholder'    => 'you@example.com',
            'submit'               => 'Begin Calculation',
            'section_label'        => 'Load section by key',
            'section_placeholder'  => 'love / career / health',
            'section_button'       => 'Load Section',
            'result_title'         => 'Persona:',
            'overview_empty'       => '(No overview)',
            'section_empty'        => '(empty)',
            'compute_first'        => 'Compute first.',
            'generic_error'        => 'Something went wrong, please try again.',
        ];

        if ($lang === 'zh') {
            $genderOptions = [
                ''        => '不透露',
                'female'  => '女性',
                'male'    => '男性',
            ];
            $strings = array_merge($strings, [
                'country_label'        => '国家/地区',
                'country_placeholder'  => '输入国家全名或两位代码（例如 Canada 或 CA）',
                'country_helper'       => '可以输入国家名称，或两位 ISO 代码。',
                'city_label'           => '城市',
                'city_placeholder'     => '输入城市名称快速搜索',
                'city_helper'          => '从建议列表中选择可以更快定位。',
                'city_loading'         => '正在搜索城市…',
                'city_no_results'      => '没有找到匹配的城市，请尝试其他写法。',
                'birth_label'          => '出生日期',
                'birth_helper'         => '可以直接输入 YYYY-MM-DD，或使用日期选择器。',
                'birth_placeholder'    => 'YYYY-MM-DD',
                'birth_toggle'         => '切换到手动输入',
                'birth_toggle_back'    => '切换到日历',
                'hour_label'           => '出生时间（24小时制）',
                'hour_placeholder'     => 'HH:MM',
                'name_label'           => '姓名（可选）',
                'name_placeholder'     => '你的名字',
                'gender_label'         => '性别',
                'gender_helper'        => '可选，仅用于个性化提示。',
                'gender_placeholder'   => '请选择',
                'email_label'          => '邮箱（可选）',
                'email_placeholder'    => 'you@example.com',
                'submit'               => '开始测算',
                'section_label'        => '按关键字加载段落',
                'section_placeholder'  => 'love / career / health',
                'section_button'       => '加载内容',
                'result_title'         => '人格：',
                'overview_empty'       => '（暂无简介）',
                'section_empty'        => '（暂无内容）',
                'compute_first'        => '请先计算。',
                'generic_error'        => '出错了，请稍后再试。',
            ]);
            $strings['gender_options'] = $genderOptions;
        } else {
            $strings['gender_options'] = $genderOptions;
        }

        return $strings;
    }
}

// ---------- Google API key helper ----------
if (!function_exists('bo_cp_google_api_key')) {
    function bo_cp_google_api_key(string $service = ''): string {
        $service = strtolower(trim($service));

        $filtered = apply_filters('bo_cp_google_api_key', '', $service);
        if (is_string($filtered) && $filtered !== '') {
            return trim($filtered);
        }

        $candidates = [];
        if ($service !== '') {
            $svcUpper = strtoupper($service);
            $candidates[] = 'GMP_SERVER_' . $svcUpper . '_API_KEY';
            $candidates[] = 'BO_CP_' . $svcUpper . '_API_KEY';
            if ($service === 'places') {
                $candidates[] = 'GMP_SERVER_PLACES_API_KEY';
            } elseif ($service === 'timezone') {
                $candidates[] = 'GMP_SERVER_TIMEZONE_API_KEY';
            }
        }

        $candidates = array_merge($candidates, [
            'GMP_SERVER_PLACES_API_KEY',
            'GMP_SERVER_GOOGLE_API_KEY',
            'GMP_SERVER_API_KEY',
            'GOOGLE_MAPS_SERVER_KEY',
            'GOOGLE_MAPS_API_KEY',
            'GOOGLE_API_KEY',
        ]);
        $candidates = array_values(array_unique(array_filter($candidates)));

        foreach ($candidates as $constant) {
            if (defined($constant)) {
                $value = constant($constant);
                if (is_string($value) && $value !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }
}

if (!function_exists('bo_cp_geo_distance_km')) {
    function bo_cp_geo_distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $radius = 6371.0; // km
        $lat1r = deg2rad($lat1);
        $lat2r = deg2rad($lat2);
        $deltaLat = $lat2r - $lat1r;
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2)
            + cos($lat1r) * cos($lat2r) * sin($deltaLng / 2) * sin($deltaLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $radius * $c;
    }
}

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

if (!function_exists('bo_cp_parse_birth_time')) {
    function bo_cp_parse_birth_time($slot): array {
        $slot = trim((string)$slot);
        $hour = null;
        $minute = null;

        if ($slot === '') {
            $hour = 11;
            $minute = 59;
        } elseif (preg_match('/^(\d{1,2}):([0-5]\d)$/', $slot, $m)) {
            $hour = intval($m[1]);
            $minute = intval($m[2]);
        } elseif (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $slot, $m)) {
            $a = max(0, min(23, intval($m[1])));
            $b = max(0, min(23, intval($m[2])));
            $avg = ($a + $b) / 2.0;
            $hour = (int) floor($avg);
            $minute = (int) round(($avg - $hour) * 60);
            if ($minute >= 60) {
                $minute -= 60;
                $hour += 1;
            }
        } elseif (preg_match('/^(\d{1,2})$/', $slot, $m)) {
            $hour = intval($m[1]);
            $minute = 0;
        }

        if ($hour === null || $minute === null) {
            $hour = 11;
            $minute = 59;
        }

        if ($minute < 0) {
            $minute = 0;
        } elseif ($minute > 59) {
            $hour += intdiv($minute, 60);
            $minute = $minute % 60;
        }
        $hour = ($hour % 24 + 24) % 24;

        $normalized = sprintf('%02d:%02d', $hour, $minute);
        $totalMinutes = ($hour * 60) + $minute;
        if ($totalMinutes >= 1380 || $totalMinutes < 60) {
            $shi = 0;
        } else {
            $shi = (int) floor(($totalMinutes - 60) / 120) + 1;
        }
        $shi = max(0, min(11, $shi));

        return [
            'hour'           => $hour,
            'minute'         => $minute,
            'normalized'     => $normalized,
            'shichen_index'  => $shi,
        ];
    }
}

if (!function_exists('bo_cp_parse_hour_slot')) {
    function bo_cp_parse_hour_slot($slot): int {
        $parsed = bo_cp_parse_birth_time($slot);
        return (int) $parsed['hour'];
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