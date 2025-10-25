<?php
// includes/rest.php
// REST endpoints for persona data, geo computation, and section retrieval.

if ( ! defined('ABSPATH') ) { exit; }

require_once __DIR__ . '/bo-cp-io.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/algorithm.php';

/** Utility: send caching headers and possibly 304 */
function bo_cp_send_cache_headers(string $persona_key) {
    $meta = bo_cp_persona_http_meta($persona_key);
    header('ETag: ' . $meta['etag']);
    header('Last-Modified: ' . $meta['lastmod']);
    header('Cache-Control: max-age=300, public');

    $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ( $inm === $meta['etag'] || $ims === $meta['lastmod'] ) {
        status_header(304);
        exit;
    }
}

function bo_cp_google_places_request(string $endpoint, array $params, string $cache_key) {
    $api_key = bo_cp_google_api_key('places');
    if (!$api_key) {
        return new WP_Error('config_error', 'Google Places API key not configured.', ['status' => 500]);
    }
    $cache_name = 'bo_cp_places_' . md5($cache_key);
    $cached = get_transient($cache_name);
    if (is_array($cached)) {
        return $cached;
    }
    $params['key'] = $api_key;
    $url = add_query_arg($params, 'https://maps.googleapis.com/maps/api/place/' . $endpoint . '/json');
    $resp = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($resp)) {
        return new WP_Error('remote_error', $resp->get_error_message(), ['status' => 502]);
    }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) {
        return new WP_Error('remote_error', 'Google Places API HTTP ' . $code, ['status' => 502]);
    }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body)) {
        return new WP_Error('remote_error', 'Invalid Google Places API response.', ['status' => 502]);
    }
    if (($body['status'] ?? '') === 'OK') {
        set_transient($cache_name, $body, HOUR_IN_SECONDS * 6);
    }
    return $body;
}

function bo_cp_google_geocode_request(array $params, string $cache_key) {
    $api_key = bo_cp_google_api_key('places');
    if (!$api_key) {
        return new WP_Error('config_error', 'Google API key not configured.', ['status' => 500]);
    }
    $cache_name = 'bo_cp_geocode_' . md5($cache_key);
    $cached = get_transient($cache_name);
    if (is_array($cached)) {
        return $cached;
    }
    $params['key'] = $api_key;
    $url = add_query_arg($params, 'https://maps.googleapis.com/maps/api/geocode/json');
    $resp = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($resp)) {
        return new WP_Error('remote_error', $resp->get_error_message(), ['status' => 502]);
    }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) {
        return new WP_Error('remote_error', 'Google Geocode API HTTP ' . $code, ['status' => 502]);
    }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body)) {
        return new WP_Error('remote_error', 'Invalid Google Geocode API response.', ['status' => 502]);
    }
    if (($body['status'] ?? '') === 'OK') {
        set_transient($cache_name, $body, HOUR_IN_SECONDS * 6);
    }
    return $body;
}

function bo_cp_lookup_place(string $city, string $country, string $place_id = '', string $lang = 'en') {
    $lang = $lang ?: 'en';
    $query = trim($city . ', ' . $country);
    $result = null;
    $components = [];
    $countryCode = '';
    $used_geocode = false;

    if ($place_id === '') {
        $text = bo_cp_google_places_request('textsearch', [
            'query'    => $query,
            'language' => $lang,
            'type'     => 'locality',
        ], 'text_' . $lang . '_' . $query);
        if (is_wp_error($text)) {
            return $text;
        }
        if (($text['status'] ?? '') === 'OK' && !empty($text['results'])) {
            $candidate = $text['results'][0];
            $place_id = $candidate['place_id'] ?? '';
            if ($place_id) {
                $details = bo_cp_google_places_request('details', [
                    'place_id' => $place_id,
                    'language' => $lang,
                    'fields'   => 'geometry/location,address_component,name,formatted_address,place_id',
                ], 'details_' . $lang . '_' . $place_id);
                if (!is_wp_error($details) && ($details['status'] ?? '') === 'OK' && !empty($details['result']['geometry']['location'])) {
                    $result = $details['result'];
                    $components = $result['address_components'] ?? [];
                } else if (is_wp_error($details)) {
                    return $details;
                } else {
                    $result = $candidate;
                }
            } else {
                $result = $candidate;
            }
        }
    } else {
        $details = bo_cp_google_places_request('details', [
            'place_id' => $place_id,
            'language' => $lang,
            'fields'   => 'geometry/location,address_component,name,formatted_address,place_id',
        ], 'details_' . $lang . '_' . $place_id);
        if (is_wp_error($details)) {
            return $details;
        }
        if (($details['status'] ?? '') === 'OK' && !empty($details['result']['geometry']['location'])) {
            $result = $details['result'];
            $components = $result['address_components'] ?? [];
        } else {
            return new WP_Error('place_not_found', 'Place details lookup failed.', ['status' => 404]);
        }
    }

    if (!$result) {
        $geo = bo_cp_google_geocode_request([
            'address'  => $query,
            'language' => $lang,
        ], 'geo_' . $lang . '_' . $query);
        if (is_wp_error($geo)) {
            return $geo;
        }
        if (($geo['status'] ?? '') !== 'OK' || empty($geo['results'][0]['geometry']['location'])) {
            return new WP_Error('place_not_found', 'Unable to resolve the provided city.', ['status' => 404]);
        }
        $result = $geo['results'][0];
        $components = $result['address_components'] ?? [];
        $used_geocode = true;
        $place_id = $result['place_id'] ?? $place_id;
    }

    $loc = $result['geometry']['location'];
    if (!is_array($loc)) {
        return new WP_Error('place_not_found', 'Place lookup missing geometry.', ['status' => 404]);
    }
    $parsedCountry = $country;
    $parsedCity = $city;
    foreach ($components as $comp) {
        $types = $comp['types'] ?? [];
        if (in_array('country', $types, true)) {
            $parsedCountry = $comp['long_name'];
            if (!empty($comp['short_name'])) {
                $countryCode = strtoupper($comp['short_name']);
            }
        }
        if (!$parsedCity && (in_array('locality', $types, true) || in_array('administrative_area_level_1', $types, true) || in_array('administrative_area_level_2', $types, true))) {
            $parsedCity = $comp['long_name'];
        }
    }

    return [
        'place_id'          => $result['place_id'] ?? $place_id,
        'lat'               => (float) ($loc['lat'] ?? 0),
        'lng'               => (float) ($loc['lng'] ?? 0),
        'country'           => $parsedCountry,
        'country_code'      => $countryCode,
        'city'              => $parsedCity,
        'name'              => $result['name'] ?? ($result['formatted_address'] ?? ($used_geocode ? $query : '')),
        'formatted_address' => $result['formatted_address'] ?? $query,
    ];
}

function bo_cp_google_timezone_request(float $lat, float $lng, int $timestamp) {
    $api_key = bo_cp_google_api_key('timezone');
    if ($api_key === '') {
        $api_key = bo_cp_google_api_key('places');
    }
    if (!$api_key) {
        return new WP_Error('config_error', 'Google API key not configured.', ['status' => 500]);
    }

    $query_args = [
        'location'  => $lat . ',' . $lng,
        'timestamp' => $timestamp,
        'key'       => $api_key,
    ];
    $url = add_query_arg($query_args, 'https://maps.googleapis.com/maps/api/timezone/json');
    $resp = wp_remote_get($url, ['timeout' => 15]);
    if (is_wp_error($resp)) {
        return new WP_Error('remote_error', $resp->get_error_message(), ['status' => 502]);
    }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) {
        return new WP_Error('remote_error', 'Google Timezone API HTTP ' . $code, ['status' => 502]);
    }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body)) {
        return new WP_Error('remote_error', 'Invalid Google Timezone API response.', ['status' => 502]);
    }
    if (($body['status'] ?? '') !== 'OK') {
        $log_args = $query_args;
        unset($log_args['key']);
        error_log('[bo-city-personality] Timezone API response ' . wp_json_encode([
            'request'  => $log_args,
            'response' => $body,
        ]));
        return new WP_Error(
            'timezone_error',
            'Timezone lookup failed: ' . ($body['status'] ?? 'UNKNOWN'),
            [
                'status'       => 502,
                'api_response' => $body,
            ]
        );
    }
    $body['source'] = 'google';
    return $body;
}

function bo_cp_timezone_offsets_from_zone(DateTimeZone $tz, int $timestamp): array {
    $now = new DateTime('@' . $timestamp);
    $currentOffset = (int) $tz->getOffset($now);
    $rawOffset = $currentOffset;
    $dstOffset = 0;

    $rangeStart = $timestamp - YEAR_IN_SECONDS;
    $rangeEnd   = $timestamp + YEAR_IN_SECONDS;
    $transitions = $tz->getTransitions($rangeStart, $rangeEnd);
    $lastStandard = null;
    foreach ($transitions as $transition) {
        if (($transition['ts'] ?? 0) > $timestamp) {
            break;
        }
        if (empty($transition['isdst'])) {
            $lastStandard = (int) ($transition['offset'] ?? $currentOffset);
        }
    }
    if ($lastStandard !== null) {
        $rawOffset = $lastStandard;
    }
    $dstOffset = $currentOffset - $rawOffset;
    if (abs($dstOffset) < 1) {
        $dstOffset = 0;
    }

    return [$rawOffset, $dstOffset];
}

function bo_cp_estimate_timezone_from_longitude(float $lng): array {
    $hours = (int) round($lng / 15.0);
    $rawOffset = $hours * HOUR_IN_SECONDS;

    return [
        'status'     => 'OK',
        'timeZoneId' => '',
        'rawOffset'  => $rawOffset,
        'dstOffset'  => 0,
        'source'     => 'longitude_estimate',
        'meta'       => [
            'hours' => $hours,
        ],
    ];
}

function bo_cp_infer_timezone_from_php(float $lat, float $lng, int $timestamp, array $context = []) {
    $countryCode = '';
    if (!empty($context['country_code'])) {
        $countryCode = strtoupper((string) $context['country_code']);
    }

    $candidates = [];
    if ($countryCode !== '') {
        try {
            $candidates = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countryCode);
        } catch (Exception $e) {
            $candidates = [];
        }
    }
    if (!$candidates) {
        $candidates = DateTimeZone::listIdentifiers();
    }

    $bestId = '';
    $bestDist = PHP_FLOAT_MAX;
    $bestTz = null;
    foreach ($candidates as $id) {
        try {
            $tz = new DateTimeZone($id);
        } catch (Exception $e) {
            continue;
        }
        $loc = $tz->getLocation();
        if (!is_array($loc) || !isset($loc['latitude'], $loc['longitude'])) {
            continue;
        }
        $dist = bo_cp_geo_distance_km($lat, $lng, (float) $loc['latitude'], (float) $loc['longitude']);
        if ($dist < $bestDist) {
            $bestDist = $dist;
            $bestId = $id;
            $bestTz = $tz;
        }
    }

    if (!$bestId || !$bestTz) {
        return new WP_Error('timezone_error', 'Unable to infer timezone from coordinates.', ['status' => 502]);
    }

    list($rawOffset, $dstOffset) = bo_cp_timezone_offsets_from_zone($bestTz, $timestamp);

    return [
        'status'     => 'OK',
        'timeZoneId' => $bestId,
        'rawOffset'  => $rawOffset,
        'dstOffset'  => $dstOffset,
        'source'     => 'php_fallback',
        'meta'       => [
            'distance_km'  => $bestDist,
            'country_code' => $countryCode,
        ],
    ];
}

function bo_cp_lookup_timezone(float $lat, float $lng, int $timestamp, array $context = []) {
    $cache_name = 'bo_cp_tz_' . md5($lat . '|' . $lng . '|' . $timestamp);
    $cached = get_transient($cache_name);
    if (is_array($cached)) {
        return $cached;
    }

    $google = bo_cp_google_timezone_request($lat, $lng, $timestamp);
    if (!is_wp_error($google)) {
        set_transient($cache_name, $google, HOUR_IN_SECONDS * 6);
        return $google;
    }

    $fallback = bo_cp_infer_timezone_from_php($lat, $lng, $timestamp, $context);
    if (!is_wp_error($fallback)) {
        error_log('[bo-city-personality] Timezone fallback used ' . wp_json_encode([
            'lat'     => $lat,
            'lng'     => $lng,
            'country' => $context['country_code'] ?? '',
            'reason'  => $google->get_error_code(),
        ]));
        set_transient($cache_name, $fallback, HOUR_IN_SECONDS * 6);
        return $fallback;
    }

    $estimate = bo_cp_estimate_timezone_from_longitude($lng);
    error_log('[bo-city-personality] Timezone longitude estimate used ' . wp_json_encode([
        'lat'     => $lat,
        'lng'     => $lng,
        'country' => $context['country_code'] ?? '',
        'reason'  => $google->get_error_code(),
    ]));
    set_transient($cache_name, $estimate, HOUR_IN_SECONDS * 6);
    return $estimate;
}

function bo_cp_rest_geo_callback(WP_REST_Request $req) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (bo_cp_rate_limited('geo_' . $ip, 30, 60)) {
        return new WP_Error('rate_limited', 'Too many requests.', ['status' => 429]);
    }

    $city       = sanitize_text_field($req->get_param('city'));
    $country    = sanitize_text_field($req->get_param('country'));
    $birth_date = sanitize_text_field($req->get_param('birth_date'));
    $hour_slot  = sanitize_text_field($req->get_param('hour_slot'));
    $lang       = sanitize_key($req->get_param('lang') ?: 'en');
    $email      = sanitize_email($req->get_param('email'));
    $person     = sanitize_text_field($req->get_param('person_name'));
    $gender     = sanitize_text_field($req->get_param('gender'));
    $place_id   = sanitize_text_field($req->get_param('place_id'));

    if ($city === '' || $country === '' || $birth_date === '') {
        return new WP_Error('bad_request', 'city, country and birth_date are required.', ['status' => 400]);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        return new WP_Error('bad_request', 'birth_date must be YYYY-MM-DD.', ['status' => 400]);
    }

    $supported_langs = function_exists('bo_cp_persona_supported_languages') ? bo_cp_persona_supported_languages() : ['en' => 'English'];
    if (!isset($supported_langs[$lang])) {
        $lang = 'en';
    }

    $place = bo_cp_lookup_place($city, $country, $place_id, $lang);
    if (is_wp_error($place)) {
        return $place;
    }
    $lat = (float) $place['lat'];
    $lng = (float) $place['lng'];

    $parts = explode('-', $birth_date);
    $timestamp = gmmktime(12, 0, 0, intval($parts[1]), intval($parts[2]), intval($parts[0]));
    $tz = bo_cp_lookup_timezone($lat, $lng, $timestamp, $place);
    if (is_wp_error($tz)) {
        return $tz;
    }

    $hour = bo_cp_parse_hour_slot($hour_slot);
    $tz_id = (string) ($tz['timeZoneId'] ?? 'UTC');
    $raw_off = intval($tz['rawOffset'] ?? 0);
    $dst_off = intval($tz['dstOffset'] ?? 0);

    $persona_key = bo_cp_canon_key(Lolo_Algorithm::calculate_persona($lat, $lng, $birth_date, $tz_id, $raw_off, $dst_off, $hour));
    $display_title = bo_cp_persona_display_title($persona_key, $lang);
    $sections = bo_cp_load_sections($persona_key, $lang);
    $overview = $sections['overview'] ?? ['title' => '', 'content' => ''];

    $result_id = bo_cp_store_result([
        'persona_key' => $persona_key,
        'lang'        => $lang,
        'country'     => $place['country'],
        'country_code'=> $place['country_code'] ?? '',
        'city'        => $place['city'],
        'birth_date'  => $birth_date,
        'hour_slot'   => $hour_slot,
        'lat'         => $lat,
        'lng'         => $lng,
        'tz_id'       => $tz_id,
        'raw_offset'  => $raw_off,
        'dst_offset'  => $dst_off,
        'email'       => $email,
        'person_name' => $person,
        'gender'      => $gender,
        'ip'          => $ip,
        'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : '',
    ], [
        'requested_city'    => $city,
        'requested_country' => $country,
        'place_id'          => $place['place_id'],
        'place_name'        => $place['name'],
        'formatted_address' => $place['formatted_address'],
        'timezone_raw'      => $tz,
        'timezone_source'   => $tz['source'] ?? 'google',
    ]);

    if ($result_id <= 0) {
        return new WP_Error('server_error', 'Failed to record result.', ['status' => 500]);
    }

    $token = bo_cp_sign_token($result_id, $persona_key);

    return [
        'result_id'      => $result_id,
        'token'          => $token,
        'persona_key'    => $persona_key,
        'name'           => $persona_key,
        'display_title'  => $display_title,
        'lang'           => $lang,
        'overview_title' => (string) ($overview['title'] ?? ''),
        'overview_html'  => (string) ($overview['content'] ?? ''),
        'sections'       => array_keys($sections),
        'place'          => $place,
    ];
}

function bo_cp_rest_result_callback(WP_REST_Request $req) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (bo_cp_rate_limited('result_' . $ip, 120, 60)) {
        return new WP_Error('rate_limited', 'Too many requests.', ['status' => 429]);
    }

    $result_id = intval($req->get_param('result_id'));
    $persona   = sanitize_key($req->get_param('name'));
    $token     = (string) $req->get_param('token');
    $section   = sanitize_key($req->get_param('section') ?: 'overview');

    if ($result_id <= 0 || $persona === '' || $token === '') {
        return new WP_Error('bad_request', 'result_id, name, token are required.', ['status' => 400]);
    }

    $verified = bo_cp_verify_token($token);
    if (!$verified) {
        return new WP_Error('invalid_token', 'Token is invalid or expired.', ['status' => 403]);
    }
    if (intval($verified['i'] ?? 0) !== $result_id) {
        return new WP_Error('invalid_token', 'Token does not match result.', ['status' => 403]);
    }
    if (bo_cp_canon_key($verified['p'] ?? '') !== $persona) {
        return new WP_Error('invalid_token', 'Token persona mismatch.', ['status' => 403]);
    }

    $row = bo_cp_get_result($result_id);
    if (!$row) {
        return new WP_Error('not_found', 'Result not found.', ['status' => 404]);
    }

    $lang = $row['lang'] ?: 'en';
    $sections = bo_cp_load_sections($persona, $lang);
    if (!isset($sections[$section])) {
        if ($section === 'overview') {
            $sections[$section] = ['title' => '', 'content' => ''];
        } else {
            return new WP_Error('not_found', 'Section not found.', ['status' => 404]);
        }
    }

    $rowSec = $sections[$section];
    return [
        'persona'        => $persona,
        'display_title'  => bo_cp_persona_display_title($persona, $lang),
        'lang'           => $lang,
        'section'        => $section,
        'title'          => (string) ($rowSec['title'] ?? ''),
        'html'           => (string) ($rowSec['content'] ?? ''),
    ];
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
            $keysStr = (string) $req->get_param('keys');
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

    register_rest_route('bo/v1', '/geo', [
        'methods'  => 'GET',
        'callback' => 'bo_cp_rest_geo_callback',
        'permission_callback' => '__return_true',
        'args' => [
            'city'       => ['required' => true],
            'country'    => ['required' => true],
            'birth_date' => ['required' => true],
        ],
    ]);

    register_rest_route('bo/v1', '/result', [
        'methods'  => 'GET',
        'callback' => 'bo_cp_rest_result_callback',
        'permission_callback' => '__return_true',
        'args' => [
            'result_id' => ['required' => true],
            'name'      => ['required' => true],
            'token'     => ['required' => true],
            'section'   => ['required' => false],
        ],
    ]);
});
