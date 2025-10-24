<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BO_CP_Shortcodes {
    public static function init() {
        add_shortcode('city_persona_form', [__CLASS__, 'form']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function assets() {
        wp_register_script('bo-cp-form', plugins_url('../assets/form.js', __FILE__), [], '1.0.0', true);
        wp_register_style('bo-cp-form', plugins_url('../assets/form.css', __FILE__), [], '1.0.0');
    }

    public static function form($atts = [], $content = '') {
        wp_enqueue_script('bo-cp-form');
        wp_enqueue_style('bo-cp-form');
        ob_start(); ?>
        <div class="bo-cp-widget">
          <form id="bo-cp-form">
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
              <label>Name (optional)</label>
              <input type="text" name="person_name" placeholder="Your name">
            </div>
            <div class="row">
              <label>Gender (optional)</label>
              <input type="text" name="gender" placeholder="male / female / ...">
            </div>
            <div class="row">
              <label>Email (optional)</label>
              <input type="email" name="email" placeholder="you@example.com">
            </div>
            <div class="row">
              <button type="submit">Compute Persona</button>
            </div>
          </form>

          <div id="bo-cp-result" style="display:none;">
            <div class="tagline"><strong>Persona:</strong> <span id="bo-cp-name"></span></div>
            <div id="bo-cp-overview"></div>
            <div class="row">
              <label>Load section by key</label>
              <input type="text" id="bo-cp-section-key" placeholder="love / career / health">
              <button id="bo-cp-load-section">Load Section</button>
            </div>
            <div id="bo-cp-section-html"></div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
BO_CP_Shortcodes::init();