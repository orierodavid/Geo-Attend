<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Geo_Attend_Public {
    public static function init() { add_shortcode( 'geo_attend', array( __CLASS__, 'shortcode' ) ); add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) ); add_action( 'rest_api_init', array( __CLASS__, 'routes' ) ); }
    public static function assets() { if ( ! is_singular() ) return; global $post; if ( ! $post || ! has_shortcode( $post->post_content, 'geo_attend' ) ) return; wp_enqueue_style( 'geo-attend-frontend', GEO_ATTEND_URL . 'assets/css/frontend.css', array(), GEO_ATTEND_VERSION ); wp_enqueue_script( 'geo-attend-frontend', GEO_ATTEND_URL . 'assets/js/frontend.js', array(), GEO_ATTEND_VERSION, true ); wp_add_inline_script( 'geo-attend-frontend', 'window.GeoAttend=' . wp_json_encode( array( 'api' => esc_url_raw( rest_url( 'geo-attend/v1' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ) . ';', 'before' ); }
    public static function shortcode() { return '<div class="geo-attend" data-geo-attend><div class="geo-attend-loading">Loading attendance…</div></div>'; }
    public static function routes() {
        register_rest_route( 'geo-attend/v1', '/config', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'config' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( 'geo-attend/v1', '/check-in', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'check_in' ), 'permission_callback' => '__return_true' ) );
    }
    public static function config() {
        global $wpdb; $t = Geo_Attend_DB::tables(); $settings = Geo_Attend_DB::settings();
        $locations = $wpdb->get_results( "SELECT id, name, radius_meters FROM {$t['locations']} WHERE active = 1 ORDER BY name ASC", ARRAY_A );
        $safe_locations = array_map( function( $l ) { return array( 'id' => (int) $l['id'], 'name' => $l['name'], 'radius_meters' => (float) $l['radius_meters'] ); }, $locations ?: array() );
        $now = Geo_Attend_DB::now( $settings['timezone'] ); $day = $now->format( 'l' ); $days = array_values( (array) $settings['attendance_days'] );
        $start_time = Geo_Attend_DB::normalize_time( $settings['start_time'], '09:00' ); $end_time = Geo_Attend_DB::normalize_time( $settings['end_time'], '17:00' );
        $start = Geo_Attend_DB::schedule_time( $now->format('Y-m-d'), $start_time, $now->getTimezone()->getName() ); $end = Geo_Attend_DB::schedule_time( $now->format('Y-m-d'), $end_time, $now->getTimezone()->getName() );
        $open = in_array( $day, $days, true ) && $start && $end && $now >= $start && $now <= $end;
        return rest_ensure_response( array( 'organization_name' => $settings['organization_name'], 'organization_logo' => esc_url_raw( $settings['organization_logo'] ), 'locations' => $safe_locations, 'schedule' => array( 'days' => $days, 'start' => $start_time, 'end' => $end_time, 'timezone' => $settings['timezone'] ), 'open' => $open ) );
    }
    public static function check_in( WP_REST_Request $request ) {
        global $wpdb; $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown'; $rate_key = 'geo_attend_rate_' . md5( $ip ); $attempts = (int) get_transient( $rate_key ); if ( $attempts >= 12 ) return new WP_Error( 'rate_limited', 'Too many attempts. Please wait a minute and try again.', array( 'status' => 429 ) ); set_transient( $rate_key, $attempts + 1, MINUTE_IN_SECONDS );
        $body = $request->get_json_params(); $member_id = absint( $body['member_id'] ?? 0 ); $member_name = sanitize_text_field( $body['member_name'] ?? '' ); $location_id = absint( $body['location_id'] ?? 0 ); $pin = preg_replace( '/\D/', '', (string) ( $body['pin'] ?? '' ) ); $lat = isset( $body['lat'] ) ? (float) $body['lat'] : null; $lng = isset( $body['lng'] ) ? (float) $body['lng'] : null; $accuracy = isset( $body['accuracy'] ) ? (float) $body['accuracy'] : null;
        if ( ! $location_id || ! preg_match( '/^\d{4}$/', $pin ) || ( ! $member_id && $member_name === '' ) || ! is_finite( $lat ) || ! is_finite( $lng ) ) return new WP_Error( 'invalid_input', 'Enter your name, 4-digit PIN and select an attendance location.', array( 'status' => 400 ) );
        if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) return new WP_Error( 'invalid_location', 'The location coordinates are invalid.', array( 'status' => 400 ) );
        if ( $accuracy !== null && ( ! is_finite( $accuracy ) || $accuracy < 0 ) ) return new WP_Error( 'invalid_accuracy', 'The location accuracy value is invalid.', array( 'status' => 400 ) );
        $settings = Geo_Attend_DB::settings(); $now = Geo_Attend_DB::now( $settings['timezone'] ); $day = $now->format( 'l' ); $days = array_map( 'strval', (array) $settings['attendance_days'] ); if ( ! in_array( $day, $days, true ) ) return new WP_Error( 'closed', 'Attendance is not scheduled for today.', array( 'status' => 403 ) );
        $start_time = Geo_Attend_DB::normalize_time( $settings['start_time'], '09:00' ); $end_time = Geo_Attend_DB::normalize_time( $settings['end_time'], '17:00' ); $start = Geo_Attend_DB::schedule_time( $now->format('Y-m-d'), $start_time, $now->getTimezone()->getName() ); $end = Geo_Attend_DB::schedule_time( $now->format('Y-m-d'), $end_time, $now->getTimezone()->getName() ); if ( ! $start || ! $end || $now < $start || $now > $end ) return new WP_Error( 'closed', 'Attendance check-in is currently closed.', array( 'status' => 403 ) );
        if ( $accuracy !== null && $accuracy > (float) $settings['max_accuracy'] ) return new WP_Error( 'accuracy', 'Your location accuracy is too low. Move to an open area and try again.', array( 'status' => 403 ) );
        $t = Geo_Attend_DB::tables();
        if ( $member_id ) {
            $members = $wpdb->get_results( $wpdb->prepare( "SELECT id, department_id, first_name, last_name, pin_hash FROM {$t['members']} WHERE id = %d AND active = 1 LIMIT 1", $member_id ), ARRAY_A );
        } else {
            $normalized_name = strtolower( trim( preg_replace( '/\s+/', ' ', $member_name ) ) );
            $members = $wpdb->get_results( "SELECT id, department_id, first_name, last_name, pin_hash FROM {$t['members']} WHERE active = 1 ORDER BY id ASC", ARRAY_A );
            $members = array_values( array_filter( $members ?: array(), function( $candidate ) use ( $normalized_name ) { return strtolower( trim( preg_replace( '/\s+/', ' ', $candidate['first_name'] . ' ' . $candidate['last_name'] ) ) ) === $normalized_name; } ) );
        }
        $member = null;
        foreach ( $members ?: array() as $candidate ) { if ( Geo_Attend_DB::verify_pin( $pin, $candidate['pin_hash'] ) ) { $member = $candidate; break; } }
        if ( ! $member ) return new WP_Error( 'invalid_pin', 'The name or PIN is incorrect.', array( 'status' => 401 ) );
        $member_rate_key = 'geo_attend_member_rate_' . (int) $member['id']; $member_attempts = (int) get_transient( $member_rate_key ); if ( $member_attempts >= 5 ) return new WP_Error( 'member_rate_limited', 'Too many attempts for this member. Please wait a minute and try again.', array( 'status' => 429 ) ); set_transient( $member_rate_key, $member_attempts + 1, MINUTE_IN_SECONDS );
        $location = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, latitude, longitude, radius_meters FROM {$t['locations']} WHERE id = %d AND active = 1 LIMIT 1", $location_id ), ARRAY_A ); if ( ! $location ) return new WP_Error( 'location_unavailable', 'The selected attendance location is unavailable.', array( 'status' => 400 ) ); $distance = self::distance( (float) $location['latitude'], (float) $location['longitude'], $lat, $lng ); if ( $distance > (float) $location['radius_meters'] ) return new WP_Error( 'outside_geofence', sprintf( 'You are about %dm from %s. You must be within %dm.', round( $distance ), $location['name'], round( $location['radius_meters'] ) ), array( 'status' => 403 ) );
        $date = $now->format( 'Y-m-d' ); $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['attendance']} WHERE member_id = %d AND attendance_date = %s LIMIT 1", $member['id'], $date ) ); if ( $existing ) return new WP_Error( 'already_checked_in', 'You have already checked in today.', array( 'status' => 409 ) );
        $late_after = (int) $settings['late_after']; $late_cutoff = $start ? $start->modify( '+' . max(0, $late_after) . ' minutes' ) : $now; $status = ( $late_after > 0 && $now > $late_cutoff ) ? 'late' : 'present';
        $ok = $wpdb->insert( $t['attendance'], array( 'member_id' => (int) $member['id'], 'department_id' => $member['department_id'] ? (int) $member['department_id'] : null, 'location_id' => $location_id, 'attendance_date' => $date, 'checked_in_at' => $now->format( 'Y-m-d H:i:s' ), 'latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy, 'status' => $status ), array( '%d','%d','%d','%s','%s','%f','%f','%f','%s' ) );
        if ( false === $ok ) { $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['attendance']} WHERE member_id = %d AND attendance_date = %s LIMIT 1", $member['id'], $date ) ); if ( $existing ) return new WP_Error( 'already_checked_in', 'You have already checked in today.', array( 'status' => 409 ) ); return new WP_Error( 'record_failed', 'Unable to record attendance. Please try again.', array( 'status' => 500 ) ); }
        return rest_ensure_response( array( 'success' => true, 'name' => trim( $member['first_name'] . ' ' . $member['last_name'] ), 'status' => $status, 'time' => $now->format( 'H:i' ) ) );
    }
    private static function distance( $lat1, $lng1, $lat2, $lng2 ) { $earth = 6371000; $dlat = deg2rad( $lat2 - $lat1 ); $dlng = deg2rad( $lng2 - $lng1 ); $a = sin($dlat/2) * sin($dlat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng/2) * sin($dlng/2); return 2 * $earth * asin( min(1, sqrt($a)) ); }
}
