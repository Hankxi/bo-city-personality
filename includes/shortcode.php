<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BO_CP_Shortcodes {
    public static function init() {
        add_shortcode('city_persona_form', [__CLASS__, 'form']);
        add_shortcode('city_persona_section', [__CLASS__, 'section']);
        add_filter('the_content', [__CLASS__, 'expand_section_shortcodes'], 9);
        add_filter('widget_text_content', [__CLASS__, 'expand_section_shortcodes'], 9);
        add_filter('widget_text', [__CLASS__, 'expand_section_shortcodes'], 9);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function assets() {
        wp_register_script('bo-cp-form', plugins_url('../assets/form.js', __FILE__), [], '1.1.0', true);
        wp_register_style('bo-cp-form', plugins_url('../assets/form.css', __FILE__), [], '1.1.0');
    }

    public static function expand_section_shortcodes($content) {
        if (!is_string($content) || $content === '') {
            return $content;
        }

        $pattern = '/\[city_persona_([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)(\s[^\]]*)?\]/';
        $content = preg_replace_callback($pattern, function ($matches) {
            $persona = bo_cp_canon_key($matches[1]);
            $sectionRaw = $matches[2];
            $section = ($sectionRaw === 'overview') ? 'overview' : bo_cp_canon_key($sectionRaw);
            $extra   = isset($matches[3]) ? trim($matches[3]) : '';
            if ($extra !== '') {
                $extra = ' ' . $extra;
            }
            return '[city_persona_section persona="' . $persona . '" key="' . $section . '"' . $extra . ']';
        }, $content);

        return $content;
    }

    public static function form($atts = [], $content = '') {
        wp_enqueue_script('bo-cp-form');
        wp_enqueue_style('bo-cp-form');
        $active_lang = bo_cp_preferred_lang('', 'en');
        $script_data = [
            'restRoot' => esc_url_raw(rest_url('bo/v1/')),
            'lang'     => strtolower(bo_cp_preferred_lang($active_lang, 'en')),
            'placesKey'=> bo_cp_google_api_key('places'),
        ];
        $script_data['debug'] = (bool) apply_filters('bo_cp_form_debug', defined('WP_DEBUG') && WP_DEBUG);
        wp_localize_script('bo-cp-form', 'boCPData', $script_data);
        $lang_key = strtolower(bo_cp_preferred_lang($active_lang, 'en'));
        $is_chinese = strpos($lang_key, 'zh') === 0;

        $location_label = $is_chinese ? '城市 / 地点' : __('City or Place', 'bo-city-personality');
        $location_placeholder = $is_chinese ? '请输入城市或地点' : __('Start typing a city or place', 'bo-city-personality');
        $location_clear_label = $is_chinese ? '清除' : __('Clear', 'bo-city-personality');
        $location_error = $is_chinese ? '请选择列表中的城市或地点。' : __('Please select a city from the suggestions.', 'bo-city-personality');
        $location_loading = $is_chinese ? '正在搜索…' : __('Searching…', 'bo-city-personality');
        $location_no_results = $is_chinese ? '未找到匹配的地点。' : __('No matching places found.', 'bo-city-personality');
        $location_fetch_error = $is_chinese ? '无法获取推荐，请稍后重试。' : __('Unable to load suggestions. Please try again.', 'bo-city-personality');

        $optional_label = $is_chinese ? '选填' : __('Optional', 'bo-city-personality');

        $manual_entry_label = $is_chinese ? '手动输入' : __('Manual Entry', 'bo-city-personality');
        $manual_picker_label = $is_chinese ? '使用日期选择' : __('Use Date Picker', 'bo-city-personality');

        $hour_slot_label = $is_chinese ? '时辰' : __('Hour Slot', 'bo-city-personality');
        $hour_slots = [
            '23-01' => $is_chinese ? '23:00-01:00（子时）' : __('23:00-01:00 (Zi Hour)', 'bo-city-personality'),
            '01-03' => $is_chinese ? '01:00-03:00（丑时）' : __('01:00-03:00 (Chou Hour)', 'bo-city-personality'),
            '03-05' => $is_chinese ? '03:00-05:00（寅时）' : __('03:00-05:00 (Yin Hour)', 'bo-city-personality'),
            '05-07' => $is_chinese ? '05:00-07:00（卯时）' : __('05:00-07:00 (Mao Hour)', 'bo-city-personality'),
            '07-09' => $is_chinese ? '07:00-09:00（辰时）' : __('07:00-09:00 (Chen Hour)', 'bo-city-personality'),
            '09-11' => $is_chinese ? '09:00-11:00（巳时）' : __('09:00-11:00 (Si Hour)', 'bo-city-personality'),
            '11-13' => $is_chinese ? '11:00-13:00（午时）' : __('11:00-13:00 (Wu Hour)', 'bo-city-personality'),
            '13-15' => $is_chinese ? '13:00-15:00（未时）' : __('13:00-15:00 (Wei Hour)', 'bo-city-personality'),
            '15-17' => $is_chinese ? '15:00-17:00（申时）' : __('15:00-17:00 (Shen Hour)', 'bo-city-personality'),
            '17-19' => $is_chinese ? '17:00-19:00（酉时）' : __('17:00-19:00 (You Hour)', 'bo-city-personality'),
            '19-21' => $is_chinese ? '19:00-21:00（戌时）' : __('19:00-21:00 (Xu Hour)', 'bo-city-personality'),
            '21-23' => $is_chinese ? '21:00-23:00（亥时）' : __('21:00-23:00 (Hai Hour)', 'bo-city-personality'),
        ];

        $gender_label = $is_chinese ? '性别' : __('Gender', 'bo-city-personality');
        $gender_options = [
            ['value' => 'prefer_not', 'label' => $is_chinese ? '不便透露' : __('Prefer not to say', 'bo-city-personality')],
            ['value' => 'female', 'label' => $is_chinese ? '女' : __('Female', 'bo-city-personality')],
            ['value' => 'male', 'label' => $is_chinese ? '男' : __('Male', 'bo-city-personality')],
        ];

        $name_label = $is_chinese ? '姓名' : __('Name', 'bo-city-personality');
        $name_placeholder = $is_chinese ? '您的名字' : __('Your name', 'bo-city-personality');
        $email_label = $is_chinese ? '邮箱' : __('Email', 'bo-city-personality');
        $email_placeholder = $is_chinese ? 'you@example.com' : 'you@example.com';

        $submit_label = $is_chinese ? '生成城市性格' : __('Compute Persona', 'bo-city-personality');
        if (function_exists('wp_unique_id')) {
            $location_list_id = wp_unique_id('bo-cp-location-list-');
        } else {
            $location_list_id = 'bo-cp-location-list-' . uniqid();
        }

        ob_start(); ?>
        <div class="bo-cp-widget">
          <form id="bo-cp-form" class="bo-cp-form">
            <input type="hidden" name="lang" value="<?php echo esc_attr($active_lang); ?>">
            <div class="row">
              <label for="bo-cp-location"><?php echo esc_html($location_label); ?></label>
              <div class="bo-cp-location" data-location-wrapper>
                <input type="search" id="bo-cp-location" class="bo-cp-location__input" required autocomplete="off" placeholder="<?php echo esc_attr($location_placeholder); ?>" data-location-input data-error-select="<?php echo esc_attr($location_error); ?>" data-loading-label="<?php echo esc_attr($location_loading); ?>" data-no-results="<?php echo esc_attr($location_no_results); ?>" data-fetch-error="<?php echo esc_attr($location_fetch_error); ?>" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="<?php echo esc_attr($location_list_id); ?>">
                <input type="hidden" name="city" value="">
                <input type="hidden" name="country" value="">
                <input type="hidden" name="place_id" value="">
                <div class="bo-cp-location__status" data-location-status hidden></div>
                <ul class="bo-cp-location__suggestions" data-location-suggestions hidden role="listbox" id="<?php echo esc_attr($location_list_id); ?>"></ul>
                <button type="button" class="bo-cp-location__clear" data-location-clear aria-label="<?php echo esc_attr($location_clear_label); ?>" hidden><?php echo esc_html($location_clear_label); ?></button>
              </div>
            </div>
            <div class="row">
              <label for="bo-cp-birth-date"><?php esc_html_e('Birth Date', 'bo-city-personality'); ?></label>
              <div class="bo-cp-date">
                <input type="date" id="bo-cp-birth-date" name="birth_date" value="2000-01-01" required data-date-input>
                <button type="button" class="bo-cp-date__toggle" data-date-toggle data-picker-label="<?php echo esc_attr($manual_picker_label); ?>" data-manual-label="<?php echo esc_attr($manual_entry_label); ?>"><?php echo esc_html($manual_entry_label); ?></button>
              </div>
            </div>
            <div class="row">
              <label for="bo-cp-hour-slot"><?php echo esc_html($hour_slot_label); ?></label>
              <select id="bo-cp-hour-slot" name="hour_slot">
                <option value=""><?php echo esc_html($is_chinese ? '请选择（可选）' : __('Select (optional)', 'bo-city-personality')); ?></option>
                <?php foreach ($hour_slots as $value => $label) : ?>
                  <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="row">
              <label for="bo-cp-name"><?php echo esc_html($name_label); ?> <span class="optional-badge"><?php echo esc_html($optional_label); ?></span></label>
              <input type="text" id="bo-cp-name" name="person_name" placeholder="<?php echo esc_attr($name_placeholder); ?>">
            </div>
            <div class="row">
              <label for="bo-cp-gender"><?php echo esc_html($gender_label); ?> <span class="optional-badge"><?php echo esc_html($optional_label); ?></span></label>
              <select id="bo-cp-gender" name="gender">
                <?php foreach ($gender_options as $option) : ?>
                  <option value="<?php echo esc_attr($option['value']); ?>"><?php echo esc_html($option['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="row">
              <label for="bo-cp-email"><?php echo esc_html($email_label); ?> <span class="optional-badge"><?php echo esc_html($optional_label); ?></span></label>
              <input type="email" id="bo-cp-email" name="email" placeholder="<?php echo esc_attr($email_placeholder); ?>">
            </div>
            <div class="row row--actions">
              <button type="submit"><?php echo esc_html($submit_label); ?></button>
            </div>
          </form>

          <div id="bo-cp-result" style="display:none;">
            <div class="tagline"><strong>Persona:</strong> <span id="bo-cp-name"></span></div>
            <div id="bo-cp-overview"></div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function section($atts = [], $content = '', $tag = '') {
        $atts = shortcode_atts([
            'persona'  => '',
            'key'      => '',
            'lang'     => '',
            'fallback' => 'en',
            'show_title' => 'yes',
        ], $atts, $tag);

        $personaRaw = trim((string) $atts['persona']);
        $persona = $personaRaw;
        $sectionKey = trim((string) $atts['key']);
        if ($sectionKey === '') {
            return '';
        }

        $lang = trim((string) $atts['lang']);
        if ($lang === '') {
            $lang = bo_cp_preferred_lang();
        }
        $lang = bo_cp_preferred_lang($lang);

        $fallback = trim((string) $atts['fallback']);
        if ($fallback === '') {
            $fallback = 'en';
        }

        $normalizedSection = ($sectionKey === 'overview') ? 'overview' : bo_cp_canon_key($sectionKey);
        if ($normalizedSection === '') {
            return '';
        }

        $isDynamicPersona = false;
        if ($persona === '' || strtolower($personaRaw) === 'name') {
            $isDynamicPersona = true;
        }

        if ($isDynamicPersona) {
            wp_enqueue_script('bo-cp-form');
            wp_enqueue_style('bo-cp-form');

            $showTitle = strtolower($atts['show_title']);
            $class = 'bo-cp-section-output bo-cp-section-' . sanitize_html_class($normalizedSection) . ' bo-cp-section-dynamic';
            $html  = '<div class="' . esc_attr($class) . '"'
                . ' data-bo-cp-dynamic="1"'
                . ' data-section="' . esc_attr($normalizedSection) . '"'
                . ' data-lang="' . esc_attr($lang) . '"'
                . ' data-fallback="' . esc_attr($fallback) . '"'
                . ' data-show-title="' . esc_attr($showTitle) . '"></div>';
            return $html;
        }

        $personaKey = bo_cp_canon_key($persona);
        $resolvedLang = $lang;

        $sections = bo_cp_load_sections($personaKey, $lang);
        $section = $sections[$normalizedSection] ?? null;

        $contentHtml = '';
        $title = '';
        if (is_array($section)) {
            $title = isset($section['title']) ? (string) $section['title'] : '';
            $contentHtml = isset($section['content']) ? (string) $section['content'] : '';
        }

        if ($contentHtml === '' && $fallback !== '' && $fallback !== $lang) {
            $fallbackSections = bo_cp_load_sections($personaKey, $fallback);
            $fallbackSection = $fallbackSections[$normalizedSection] ?? null;
            if (is_array($fallbackSection)) {
                $title = isset($fallbackSection['title']) ? (string) $fallbackSection['title'] : $title;
                $contentHtml = isset($fallbackSection['content']) ? (string) $fallbackSection['content'] : $contentHtml;
                if ($contentHtml !== '') {
                    $resolvedLang = $fallback;
                }
            }
        }

        if ($contentHtml === '') {
            return '';
        }

        $contentHtml = apply_filters('bo_cp_persona_section_content', $contentHtml, $personaKey, $normalizedSection, $resolvedLang);
        $contentHtml = do_shortcode($contentHtml);

        $class = 'bo-cp-section-output bo-cp-section-' . sanitize_html_class($normalizedSection);
        $html  = '<div class="' . esc_attr($class) . '" data-persona="' . esc_attr($personaKey) . '" data-lang="' . esc_attr($resolvedLang) . '">';
        if (strtolower($atts['show_title']) !== 'no' && $title !== '') {
            $html .= '<h3 class="bo-cp-section-title">' . esc_html($title) . '</h3>';
        }
        $html .= wp_kses_post($contentHtml);
        $html .= '</div>';
        return $html;
    }
}
BO_CP_Shortcodes::init();
