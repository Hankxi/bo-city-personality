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
    $has_session = !empty($params['sessiontoken']);
    $cache_name = $has_session ? '' : 'bo_cp_places_' . md5($cache_key);
    if (!$has_session) {
        $cached = get_transient($cache_name);
        if (is_array($cached)) {
            return $cached;
        }
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
    if (($body['status'] ?? '') === 'OK' && !$has_session) {
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
    $normalizedCountry = bo_cp_normalize_country_input($country, $lang);
    $countryName = $normalizedCountry['display'] ?: ($normalizedCountry['name'] ?: $country);
    $countryCode = strtoupper($normalizedCountry['code'] ?? '');
    $query = trim($city . ', ' . $countryName);
    $result = null;
    $components = [];
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
                    'fields'   => 'geometry,address_component,name,formatted_address,place_id',
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
        $details_lang = $lang ?: 'en';
        if ($details_lang !== 'en') {
            $details_lang = 'en';
        }

        $details = bo_cp_google_places_request('details', [
            'place_id' => $place_id,
            'language' => $details_lang,
            'fields'   => 'geometry,address_component,name,formatted_address,place_id',
        ], 'details_' . $details_lang . '_' . $place_id);
        if (is_wp_error($details)) {
            return $details;
        }

        $details_status = (string) ($details['status'] ?? '');
        $details_error  = (string) ($details['error_message'] ?? '');
        if ($details_status === 'OK' && !empty($details['result']['geometry']['location'])) {
            $result = $details['result'];
            $components = $result['address_components'] ?? [];
        } else {
            $geo = bo_cp_google_geocode_request([
                'place_id' => $place_id,
                'language' => $details_lang,
            ], 'geo_place_' . $details_lang . '_' . $place_id);
            if (is_wp_error($geo)) {
                return $geo;
            }
            if (($geo['status'] ?? '') === 'OK' && !empty($geo['results'][0]['geometry']['location'])) {
                $result = $geo['results'][0];
                $components = $result['address_components'] ?? [];
                $used_geocode = true;
                $place_id = $result['place_id'] ?? $place_id;
            } else {
                $error_meta = [
                    'status'         => 404,
                    'details_status' => $details_status,
                    'geocode_status' => (string) ($geo['status'] ?? ''),
                ];
                if ($details_error !== '') {
                    $error_meta['details_error'] = $details_error;
                }
                if (!empty($geo['error_message'])) {
                    $error_meta['geocode_error'] = (string) $geo['error_message'];
                }
                return new WP_Error('place_not_found', 'Place details lookup failed.', $error_meta);
            }
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

    if ($countryCode === '' && $normalizedCountry['code'] !== '') {
        $countryCode = strtoupper($normalizedCountry['code']);
    }
    if ($parsedCountry === '' && $normalizedCountry['display'] !== '') {
        $parsedCountry = $normalizedCountry['display'];
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

function bo_cp_google_places_autocomplete(string $input, string $lang = 'en', string $country_code = '', string $session_token = '') {
    $lang = $lang ?: 'en';
    $params = [
        'input'    => $input,
        'language' => $lang,
        'types'    => '(cities)',
    ];
    $country_code = strtoupper(trim($country_code));
    if ($country_code !== '') {
        $params['components'] = 'country:' . strtolower($country_code);
    }
    $session_token = trim($session_token);
    if ($session_token !== '') {
        $params['sessiontoken'] = $session_token;
    }

    return bo_cp_google_places_request('autocomplete', $params, 'autocomplete_' . $lang . '_' . $country_code . '_' . $input);
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

function bo_cp_rest_place_suggestions(WP_REST_Request $req) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (bo_cp_rate_limited('places_' . $ip, 60, 60)) {
        return new WP_Error('rate_limited', 'Too many requests.', ['status' => 429]);
    }

    $input = sanitize_text_field($req->get_param('input'));
    $lang  = bo_cp_preferred_lang($req->get_param('lang'));
    $countryParam = sanitize_text_field($req->get_param('country'));
    $session_token = sanitize_text_field($req->get_param('session_token'));

    if (strlen($input) < 2) {
        return [
            'lang'        => $lang,
            'country'     => '',
            'predictions' => [],
        ];
    }

    $normalized = bo_cp_normalize_country_input($countryParam, $lang);
    $country_code = strtoupper($normalized['code'] ?? '');
    $country_name = trim($normalized['name'] ?: $normalized['display'] ?: $countryParam);

    $response = bo_cp_google_places_autocomplete($input, $lang, $country_code, $session_token);
    $predictions = [];
    $seenIds = [];
    $response_status = '';
    if (!is_wp_error($response)) {
        $response_status = (string) ($response['status'] ?? '');
        if ($response_status === 'OK') {
            foreach (($response['predictions'] ?? []) as $prediction) {
                if (!is_array($prediction)) {
                    continue;
                }
                $description = (string) ($prediction['description'] ?? '');
                if ($description === '') {
                    continue;
                }
                $placeId = (string) ($prediction['place_id'] ?? '');
                $predictions[] = [
                    'place_id'    => $placeId,
                    'description' => $description,
                    'matched_substrings' => $prediction['matched_substrings'] ?? [],
                    'terms'       => $prediction['terms'] ?? [],
                    'types'       => $prediction['types'] ?? [],
                ];
                if ($placeId !== '') {
                    $seenIds[$placeId] = true;
                }
            }
        }
    }

    $fallback_query = $input;
    $needsMore = count($predictions) < 5;
    if ($needsMore) {
        $country_suffix = $country_name;
        if ($country_suffix === '') {
            $country_suffix = $country_code;
        }
        if ($country_suffix !== '') {
            $fallback_query .= ', ' . $country_suffix;
        }

        $fallback = bo_cp_google_places_request('textsearch', [
            'query'    => $fallback_query,
            'language' => $lang,
            'type'     => 'locality',
        ], 'suggest_text_' . $lang . '_' . $country_code . '_' . $input);

        if (!is_wp_error($fallback) && ($fallback['status'] ?? '') === 'OK') {
            foreach (($fallback['results'] ?? []) as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $name = trim((string) ($result['name'] ?? ''));
                $address = trim((string) ($result['formatted_address'] ?? ''));
                $description = $name !== '' ? $name : $address;
                if ($description === '') {
                    continue;
                }
                $placeId = (string) ($result['place_id'] ?? '');
                if ($placeId !== '' && isset($seenIds[$placeId])) {
                    continue;
                }
                if ($name !== '' && $address !== '' && stripos($address, $name) === false) {
                    $description = $name . ', ' . $address;
                } elseif ($name !== '' && $address !== '' && stripos($address, $name) !== false) {
                    $description = $address;
                }
                $predictions[] = [
                    'place_id'    => $placeId,
                    'description' => $description,
                    'matched_substrings' => [],
                    'terms'       => [],
                    'types'       => $result['types'] ?? [],
                ];
                if ($placeId !== '') {
                    $seenIds[$placeId] = true;
                }
                if (count($predictions) >= 8) {
                    break;
                }
            }
        } elseif (is_wp_error($fallback)) {
            if (is_wp_error($response)) {
                return $fallback;
            }
            error_log('[bo-city-personality] City suggestion fallback failed: ' . $fallback->get_error_message());
        } elseif (($fallback['status'] ?? '') !== '') {
            error_log('[bo-city-personality] City suggestion fallback status ' . ($fallback['status'] ?? 'UNKNOWN'));
        }
    }

    if (count($predictions) < 5) {
        $geo_params = [
            'address'  => $fallback_query,
            'language' => $lang,
        ];
        if ($country_code !== '') {
            $geo_params['components'] = 'country:' . strtolower($country_code);
        }
        $geo = bo_cp_google_geocode_request($geo_params, 'suggest_geo_' . $lang . '_' . $country_code . '_' . $input);
        if (!is_wp_error($geo) && ($geo['status'] ?? '') === 'OK') {
            foreach (($geo['results'] ?? []) as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $address = trim((string) ($result['formatted_address'] ?? ''));
                if ($address === '') {
                    continue;
                }
                $placeId = (string) ($result['place_id'] ?? '');
                if ($placeId !== '' && isset($seenIds[$placeId])) {
                    continue;
                }
                $predictions[] = [
                    'place_id'    => $placeId,
                    'description' => $address,
                    'matched_substrings' => [],
                    'terms'       => [],
                    'types'       => $result['types'] ?? [],
                ];
                if ($placeId !== '') {
                    $seenIds[$placeId] = true;
                }
                if (count($predictions) >= 8) {
                    break;
                }
            }
        } elseif (is_wp_error($geo)) {
            if (is_wp_error($response)) {
                return $geo;
            }
            error_log('[bo-city-personality] City suggestion geocode failed: ' . $geo->get_error_message());
        } elseif (($geo['status'] ?? '') !== '') {
            error_log('[bo-city-personality] City suggestion geocode status ' . ($geo['status'] ?? 'UNKNOWN'));
        }
    }

    if (empty($predictions) && is_wp_error($response)) {
        return $response;
    }

    return [
        'lang'        => $lang,
        'country'     => $country_code,
        'predictions' => $predictions,
    ];
}

function bo_cp_rest_place_details(WP_REST_Request $req) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (bo_cp_rate_limited('place_details_' . $ip, 60, 60)) {
        return new WP_Error('rate_limited', 'Too many requests.', ['status' => 429]);
    }

    $place_id = sanitize_text_field($req->get_param('place_id'));
    if ($place_id === '') {
        return new WP_Error('bad_request', 'place_id is required.', ['status' => 400]);
    }

    $lang = bo_cp_preferred_lang($req->get_param('lang'), 'en');
    if ($lang === '') {
        $lang = 'en';
    }

    $place = bo_cp_lookup_place('', '', $place_id, 'en');
    if (is_wp_error($place)) {
        return $place;
    }

    return [
        'place_id'          => $place['place_id'],
        'city'              => $place['city'],
        'country'           => $place['country'],
        'country_code'      => $place['country_code'] ?? '',
        'formatted_address' => $place['formatted_address'],
        'name'              => $place['name'],
        'lat'               => $place['lat'],
        'lng'               => $place['lng'],
        'lang'              => $lang,
    ];
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
    $lang       = bo_cp_preferred_lang($req->get_param('lang'));
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
        $lang = bo_cp_preferred_lang('', 'en');
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

    $time_parts = bo_cp_parse_birth_time($hour_slot);
    $normalized_hour_slot = (string) ($time_parts['normalized'] ?? '11:59');
    $shi_index = isset($time_parts['shichen_index']) ? (int) $time_parts['shichen_index'] : 6;
    $tz_id = (string) ($tz['timeZoneId'] ?? 'UTC');
    $raw_off = intval($tz['rawOffset'] ?? 0);
    $dst_off = intval($tz['dstOffset'] ?? 0);

    $algo_result = Bo_City_Algorithm::calculate_persona([
        'birth_date'    => $birth_date,
        'hour_slot'     => $normalized_hour_slot,
        'shichen_index' => $shi_index,
        'lat'           => $lat,
        'lng'           => $lng,
        'time_zone_id'  => $tz_id,
        'raw_offset'    => $raw_off,
        'dst_offset'    => $dst_off,
    ]);

    if (!is_array($algo_result) || isset($algo_result['error'])) {
        return new WP_Error('server_error', 'Failed to calculate persona.', ['status' => 500]);
    }

    $persona_key = bo_cp_canon_key($algo_result['persona_key'] ?? '');
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
        'hour_slot'   => $normalized_hour_slot,
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
        'algorithm_result'  => $algo_result,
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
        'hour_slot'      => $algo_result['normalized_hour_slot'] ?? $normalized_hour_slot,
        'shichen_index'  => $algo_result['shichen_index'] ?? $shi_index,
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

    $requested_lang = $req->get_param('lang');
    $lang = bo_cp_preferred_lang($requested_lang ?: ($row['lang'] ?? ''));
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
            $lang    = bo_cp_preferred_lang($req->get_param('lang'));
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
            $lang    = bo_cp_preferred_lang($req->get_param('lang'));
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

    register_rest_route('bo/v1', '/places', [
        'methods'  => 'GET',
        'callback' => 'bo_cp_rest_place_suggestions',
        'permission_callback' => '__return_true',
        'args' => [
            'input'   => ['required' => true],
            'country' => ['required' => false],
            'lang'    => ['required' => false],
        ],
    ]);

    register_rest_route('bo/v1', '/place-details', [
        'methods'  => 'GET',
        'callback' => 'bo_cp_rest_place_details',
        'permission_callback' => '__return_true',
        'args' => [
            'place_id' => ['required' => true],
            'lang'     => ['required' => false],
        ],
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
            'lang'      => ['required' => false],
        ],
    ]);
});