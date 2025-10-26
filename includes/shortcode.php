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
        wp_register_script('bo-cp-form', plugins_url('../assets/form.js', __FILE__), [], '1.0.3', true);
        wp_register_style('bo-cp-form', plugins_url('../assets/form.css', __FILE__), [], '1.0.0');
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
        ob_start(); ?>
        <div class="bo-cp-widget">
          <form id="bo-cp-form" class="bo-cp-form">
            <input type="hidden" name="lang" value="<?php echo esc_attr($active_lang); ?>">
            <div class="row">
              <label>Country</label>
              <input type="text" name="country" required placeholder="Canada">
            </div>
            <div class="row">
              <label>City</label>
              <input type="text" name="city" required placeholder="Vancouver">
            </div>
            <div class="row">
              <label>Birth Date</label>
              <input type="date" name="birth_date" required>
            </div>
            <div class="row">
              <label>Hour Slot</label>
              <input type="text" name="hour_slot" placeholder="13-15">
            </div>
            <div class="row">
              <label>Name <span class="optional-badge">Optional</span></label>
              <input type="text" name="person_name" placeholder="Your name">
            </div>
            <div class="row">
              <label>Gender <span class="optional-badge">Optional</span></label>
              <input type="text" name="gender" placeholder="male / female / ...">
            </div>
            <div class="row">
              <label>Email <span class="optional-badge">Optional</span></label>
              <input type="email" name="email" placeholder="you@example.com">
            </div>
            <div class="row row--actions">
              <button type="submit">Compute Persona</button>
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
