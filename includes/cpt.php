<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Register CPT: city_persona (single post per persona)
 */
function bo_cp_register_cpts() {
    $labels = array(
        'name'               => 'City Personas',
        'singular_name'      => 'City Persona',
        'menu_name'          => 'City Persona',
        'name_admin_bar'     => 'City Persona',
        'add_new'            => 'Add New',
        'add_new_item'       => 'Add New Persona',
        'new_item'           => 'New Persona',
        'edit_item'          => 'Edit Persona',
        'view_item'          => 'View Persona',
        'all_items'          => 'All Personas',
        'search_items'       => 'Search Personas',
        'parent_item_colon'  => 'Parent Persona:',
        'not_found'          => 'No personas found.'
    );
    register_post_type('city_persona', array(
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_rest'       => false,
        'hierarchical'       => false,
        'menu_position'      => 25,
        'menu_icon'          => 'dashicons-id',
        'supports'           => array('title','author','revisions'),
        'has_archive'        => false,
        'capability_type'    => 'post',
        'map_meta_cap'       => true,
        'capabilities'       => array(
            'create_posts' => 'do_not_allow',
        )
    ));
}

add_action('init', 'bo_cp_register_cpts');

/**
 * Admin list columns: Persona key + available locales summary
 */
add_filter('manage_edit-city_persona_columns', function($cols){
    $cols['persona_key'] = 'Persona Key';
    $cols['locales'] = 'Locales';
    return $cols;
});
add_action('manage_city_persona_posts_custom_column', function($col, $post_id){
    if ($col === 'persona_key') {
        $k = get_post_meta($post_id, 'persona_key', true);
        if (!$k) $k = get_the_title($post_id);
        echo esc_html($k);
    } elseif ($col === 'locales') {
        $locales = get_post_meta($post_id, 'locales', true);
        if (is_array($locales) && !empty($locales)) {
            $langs = array();
            foreach ($locales as $lang => $row) {
                if (!is_string($lang) || $lang === '') continue;
                $label = strtoupper($lang);
                $display = isset($row['displayTitle']) ? trim((string)$row['displayTitle']) : '';
                if ($display !== '') {
                    $label .= ' – ' . $display;
                }
                $langs[] = sprintf('<span class="bo-cp-locale">%s</span>', esc_html($label));
            }
            echo implode('<br/>', $langs);
        } else {
            echo '-';
        }
    }
}, 10, 2);