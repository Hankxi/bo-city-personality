<?php
// includes/rest.php (replacement)
// REST endpoints for fetching sections from unified files (with fallback to CPT).

if ( ! defined('ABSPATH') ) { exit; }

require_once __DIR__ . '/bo-cp-io.php';

/** Utility: send caching headers and possibly 304 */
function bo_cp_send_cache_headers(string $persona_key) {
    $meta = bo_cp_persona_http_meta($persona_key);
    header('ETag: ' . $meta['etag']);
    header('Last-Modified: ' . $meta['lastmod']);
    header('Cache-Control: max-age=300, public'); // 5 min

    $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ( $inm === $meta['etag'] || $ims === $meta['lastmod'] ) {
        status_header(304);
        exit;
    }
}

add_action('rest_api_init', function(){
    register_rest_route('bo/v1', '/section', [
        'methods'  => 'GET',
        'callback' => function(WP_REST_Request $req){
            $persona = sanitize_key($req->get_param('persona'));
            $lang    = sanitize_text_field($req->get_param('lang') ?: 'en');
            $key     = sanitize_key($req->get_param('key'));

            if (!$persona || !$key) {
                return new WP_Error('bad_request', 'Missing persona or key', ['status'=>400]);
            }

            bo_cp_send_cache_headers($persona);

            $sections = bo_cp_load_sections($persona, $lang);
            $one = $sections[$key] ?? ['title'=>'', 'content'=>''];
            return [
                'persona' => $persona,
                'lang'    => $lang,
                'key'     => $key,
                'title'   => (string)($one['title'] ?? ''),
                'content_html' => (string)($one['content'] ?? ''),
                'version' => 1,
            ];
        },
        'permission_callback' => '__return_true',
        'args' => [
            'persona' => ['required'=>true],
            'lang'    => ['required'=>false],
            'key'     => ['required'=>true],
        ]
    ]);

    register_rest_route('bo/v1', '/sections', [
        'methods'  => 'GET',
        'callback' => function(WP_REST_Request $req){
            $persona = sanitize_key($req->get_param('persona'));
            $lang    = sanitize_text_field($req->get_param('lang') ?: 'en');
            $keysStr = (string) $req->get_param('keys'); // comma-separated
            $keys = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $keysStr))));

            if (!$persona) {
                return new WP_Error('bad_request', 'Missing persona', ['status'=>400]);
            }

            bo_cp_send_cache_headers($persona);

            $sections = bo_cp_load_sections($persona, $lang);
            $out = [];
            if ($keys) {
                foreach ($keys as $k) {
                    $row = $sections[$k] ?? ['title'=>'', 'content'=>''];
                    $out[$k] = ['title'=>(string)($row['title'] ?? ''), 'content_html'=>(string)($row['content'] ?? '')];
                }
            } else {
                foreach ($sections as $k => $row) {
                    $out[$k] = ['title'=>(string)($row['title'] ?? ''), 'content_html'=>(string)($row['content'] ?? '')];
                }
            }

            return [
                'persona' => $persona,
                'lang'    => $lang,
                'sections'=> $out,
                'version' => 1,
            ];
        },
        'permission_callback' => '__return_true',
        'args' => [
            'persona' => ['required'=>true],
            'lang'    => ['required'=>false],
            'keys'    => ['required'=>false],
        ]
    ]);
});