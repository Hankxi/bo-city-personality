<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BO_CP_Shortcodes {
    public static function init() {
        add_shortcode('city_persona_form', [__CLASS__, 'form']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function assets() {
        wp_register_script('bo-cp-form', plugins_url('../assets/form.js', __FILE__), [], '1.1.1', true);
        wp_register_style('bo-cp-form', plugins_url('../assets/form.css', __FILE__), [], '1.1.0');
    }

    public static function form($atts = [], $content = '') {
        wp_enqueue_script('bo-cp-form');
        wp_enqueue_style('bo-cp-form');
        $current_lang = bo_cp_preferred_lang();
        $supported_langs = bo_cp_supported_language_codes();
        $strings = bo_cp_form_strings($current_lang);
        $countries = bo_cp_country_list($current_lang);
        wp_localize_script('bo-cp-form', 'boCpConfig', array(
            'lang'  => $current_lang,
            'langs' => array_values($supported_langs),
            'strings' => $strings,
            'countries' => array_values(array_map(function($country){
                return array(
                    'code'    => $country['code'],
                    'name'    => $country['name'],
                    'display' => $country['display'],
                    'label'   => $country['label'],
                );
            }, $countries)),
            'endpoints' => array(
                'places' => esc_url_raw(rest_url('bo/v1/places')),
            ),
        ));
        ob_start(); ?>
        <div class="bo-cp-widget">
          <form id="bo-cp-form" class="bo-cp-form" autocomplete="off">
            <input type="hidden" name="lang" value="<?php echo esc_attr($current_lang); ?>">
            <input type="hidden" name="place_id" id="bo-cp-place-id" value="">
            <div class="row">
              <label for="bo-cp-country"><?php echo esc_html($strings['country_label']); ?></label>
              <input type="text" id="bo-cp-country" name="country" required placeholder="<?php echo esc_attr($strings['country_placeholder']); ?>" list="bo-cp-country-options" autocomplete="off">
              <small class="hint"><?php echo esc_html($strings['country_helper']); ?></small>
              <datalist id="bo-cp-country-options">
                <?php
                $rendered = [];
                foreach ($countries as $country) {
                    $code = $country['code'];
                    $name = $country['name'];
                    $display = $country['display'];
                    $values = array_unique(array_filter([$display, $name, $code]));
                    foreach ($values as $value) {
                        $key = strtolower($value . '|' . $code);
                        if (isset($rendered[$key])) {
                            continue;
                        }
                        $rendered[$key] = true;
                        ?>
                        <option value="<?php echo esc_attr($value); ?>" data-code="<?php echo esc_attr($code); ?>" data-name="<?php echo esc_attr($name); ?>"></option>
                        <?php
                    }
                }
                ?>
              </datalist>
            </div>
            <div class="row city-row" id="bo-cp-city-row">
              <label for="bo-cp-city"><?php echo esc_html($strings['city_label']); ?></label>
              <input type="text" id="bo-cp-city" name="city" required placeholder="<?php echo esc_attr($strings['city_placeholder']); ?>" list="bo-cp-city-options" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="bo-cp-city-suggestions" aria-expanded="false">
              <small class="hint"><?php echo esc_html($strings['city_helper']); ?></small>
              <datalist id="bo-cp-city-options"></datalist>
              <div class="bo-cp-suggestions" id="bo-cp-city-suggestions" role="listbox" aria-label="<?php echo esc_attr($strings['city_label']); ?>"></div>
            </div>
            <div class="row">
              <label for="bo-cp-birth-date"><?php echo esc_html($strings['birth_label']); ?></label>
              <div class="input-group">
                <input type="date" id="bo-cp-birth-date" name="birth_date" required value="2000-01-01" placeholder="<?php echo esc_attr($strings['birth_placeholder']); ?>">
                <button type="button" id="bo-cp-toggle-date" class="link-button"><?php echo esc_html($strings['birth_toggle']); ?></button>
              </div>
              <small class="hint"><?php echo esc_html($strings['birth_helper']); ?></small>
            </div>
            <div class="row">
              <label for="bo-cp-hour-slot"><?php echo esc_html($strings['hour_label']); ?></label>
              <select id="bo-cp-hour-slot" name="hour_slot">
                <?php foreach ($strings['hour_slots'] as $value => $label) : ?>
                  <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
              <small class="hint"><?php echo esc_html($strings['hour_placeholder']); ?></small>
            </div>
            <div class="row">
              <label for="bo-cp-person-name"><?php echo esc_html($strings['name_label']); ?></label>
              <input type="text" id="bo-cp-person-name" name="person_name" placeholder="<?php echo esc_attr($strings['name_placeholder']); ?>">
            </div>
            <div class="row">
              <label for="bo-cp-gender"><?php echo esc_html($strings['gender_label']); ?></label>
              <select id="bo-cp-gender" name="gender">
                <?php foreach ($strings['gender_options'] as $value => $label) : ?>
                  <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
              <small class="hint"><?php echo esc_html($strings['gender_helper']); ?></small>
            </div>
            <div class="row">
              <label for="bo-cp-email"><?php echo esc_html($strings['email_label']); ?></label>
              <input type="email" id="bo-cp-email" name="email" placeholder="<?php echo esc_attr($strings['email_placeholder']); ?>">
            </div>
            <div class="row">
              <button type="submit" id="bo-cp-submit"><?php echo esc_html($strings['submit']); ?></button>
            </div>
          </form>

          <div id="bo-cp-result" class="bo-cp-result" style="display:none;">
            <div class="tagline"><strong id="bo-cp-result-title"><?php echo esc_html($strings['result_title']); ?></strong> <span id="bo-cp-name"></span></div>
            <div id="bo-cp-overview"></div>
            <div class="row">
              <label for="bo-cp-section-key"><?php echo esc_html($strings['section_label']); ?></label>
              <div class="input-group">
                <input type="text" id="bo-cp-section-key" placeholder="<?php echo esc_attr($strings['section_placeholder']); ?>">
                <button type="button" id="bo-cp-load-section"><?php echo esc_html($strings['section_button']); ?></button>
              </div>
            </div>
            <div id="bo-cp-section-html"></div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
BO_CP_Shortcodes::init();