<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Register CPT: city_persona (hierarchical => parent = persona key, children = language pages)
 */
add_action('init', function(){
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
        'hierarchical'       => true,
        'menu_position'      => 25,
        'menu_icon'          => 'dashicons-id',
        'supports'           => array('title','editor','author','revisions'),
        'has_archive'        => false,
        'capability_type'    => 'post'
    ));
});

/**
 * Admin list columns: Parent key + Language + Children status
 */
add_filter('manage_edit-city_persona_columns', function($cols){
    $cols['persona_key'] = 'Persona Key';
    $cols['lang'] = 'Lang';
    $cols['children'] = 'Children';
    return $cols;
});
add_action('manage_city_persona_posts_custom_column', function($col, $post_id){
    if ($col === 'persona_key') {
        $k = get_post_meta($post_id, 'persona_key', true);
        if (!$k) $k = get_the_title($post_id);
        echo esc_html($k);
    } elseif ($col === 'lang') {
        $lang = get_post_meta($post_id, 'lang', true);
        if ($lang) {
            echo '<span class="dashicons dashicons-translation"></span> ' . esc_html(strtoupper($lang));
        } else {
            echo '-';
        }
    } elseif ($col === 'children') {
        $kids = get_children(array('post_parent'=>$post_id,'post_type'=>'city_persona','post_status'=>'any','fields'=>'ids'));
        if (!empty($kids)) {
            $langs = array();
            foreach ($kids as $cid) { $langs[] = strtoupper(get_post_meta($cid,'lang',true) ?: '?'); }
            echo implode(' / ', array_unique($langs));
        } else {
            echo '-';
        }
    }
}, 10, 2);