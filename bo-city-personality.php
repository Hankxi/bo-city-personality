<?php
/**
 * Plugin Name: Bo City Personality
 * Description: 根据城市地理位置计算人格结果并输出对应内容（后端私有算法 + 按需返回 section）。
 * Version: 1.0.0
 * Author: Bo
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Constants (read-only from wp-config.php)
if ( ! defined('GMP_SERVER_PLACES_API_KEY') ) {
    // You should define GMP_SERVER_PLACES_API_KEY in wp-config.php
}
if ( ! defined('LOLO_HMAC_SECRET') ) {
    // Optional: define LOLO_HMAC_SECRET in wp-config.php; fallback to AUTH_SALT
}

// Autoload includes
require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/algorithm.php';
require_once plugin_dir_path(__FILE__) . 'includes/cpt.php';

// 新增：文件 I/O 与写穿逻辑
require_once plugin_dir_path(__FILE__) . 'includes/bo-cp-io.php';
require_once plugin_dir_path(__FILE__) . 'includes/bo-cp-write-through.php';

// 替换版 REST
require_once plugin_dir_path(__FILE__) . 'includes/rest.php';
require_once plugin_dir_path(__FILE__) . 'includes/shortcode.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin.php';

// Init plugin dirs (kept from your original scaffold)
add_action('init', function() {
    $plugin_dir = plugin_dir_path(__FILE__);
    if (!file_exists($plugin_dir . 'includes')) { @mkdir($plugin_dir . 'includes', 0755, true); }
    if (!file_exists($plugin_dir . 'data')) { @mkdir($plugin_dir . 'data', 0755, true); }
});

register_activation_hook(__FILE__, function(){
    // Flush rewrite for REST and CPTs
    bo_cp_register_cpts();
    if (function_exists('bo_cp_ensure_results_table')) {
        bo_cp_ensure_results_table();
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function(){
    flush_rewrite_rules();
});



// 列出可用的人格文件名（data/*.php）
add_action('rest_api_init', function () {
    register_rest_route('bo/v1', '/names', [
        'methods'  => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $base_dir = plugin_dir_path(__FILE__) . 'data/';
            if (!is_dir($base_dir)) return [];

            // 允许按语言过滤：?lang=zh|en（可选）
            $lang = sanitize_text_field($request->get_param('lang'));
            if (!in_array($lang, ['zh','en'], true)) $lang = null;

            $files = glob($base_dir . '*.php') ?: [];
            $list  = [];
            foreach ($files as $file) {
                $arr = include $file;
                if (is_array($arr)) {
                    $name = $arr['name'] ?? pathinfo($file, PATHINFO_FILENAME);
                    // 取显示名（多语言兼容）
                    $displayTitle = $arr['displayTitle'] ?? $name;
                    if (!empty($arr['locale']) && is_array($arr['locale']) && $lang && !empty($arr['locale'][$lang])) {
                        $displayTitle = $arr['locale'][$lang];
                    }
                    $list[] = [
                        'name'         => $name,
                        'displayTitle' => $displayTitle,
                        'file'         => basename($file),
                    ];
                }
            }

            // 按显示名排序
            usort($list, fn($a,$b) => strcmp($a['displayTitle'],$b['displayTitle']));
            return $list;
        }
    ]);
});
