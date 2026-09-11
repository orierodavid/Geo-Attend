<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Geo_Attend_Public {
    public static function init() {
        add_shortcode( 'geo_attend', array( __CLASS__, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function assets() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'geo_attend' ) ) return;
        wp_enqueue_style( 'geo-attend-frontend', GEO_ATTEND_URL . 'assets/css/frontend.css', array(), GEO_ATTEND_VERSION );
        wp_enqueue_script( 'geo-attend-frontend', GEO_ATTEND_URL . 'assets/js/frontend.js', array(), GEO_ATTEND_VERSION, true );
        wp_add_inline_script( 'geo-attend-frontend', 'window.GeoAttend=' . wp_json_encode( array(
            'api' => esc_url_raw( rest_url( 'geo-attend/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ) ) . ';', 'before' );
    }

    public static function shortcode() {
        $settings = Geo_Attend_DB::settings();
        if ( empty( $settings['organization_name'] ) ) $settings['organization_name'] = get_bloginfo( 'name' );
        return '<div class="geo-attend" data-geo-attend><div class="geo-attend-loading">Loading attendance…</div></div>';
    }

    public static function routes() {
        register_rest_route( 'geo-attend/v1', '/config', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'config' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'geo-attend/v1', '/check-in', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'check_in' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function config() {
        global $wpdb;
        $t = Geo_Attend_DB::tables();
        $settings = Geo_Attend_DB::settings();
        $departments = $wpdb->get_results( "SELECT id, name FROM {$t['departments']} WHERE active = 1 ORDER BY name ASC", ARRAY_A );
        $members = $wpdb->get_results( "SELECT id, department_id, first_name, last_name FROM {$t['members']} WHERE active = 1 ORDER BY last_name ASC, first_name ASC", ARRAY_A );
        $locations = $wpdb->get_results( "SELECT id, name, latitude, longitude, radius_meters FROM {$t['locations']} WHERE active = 1 ORDER BY name ASC", ARRAY_A );
        $safe_members = array_map( function( $m ) {
            return array( 'id' => (int) $m['id'], 'department_id' => $m['department_id'] ? (int) $m['department_id'] : 0, 'name' => trim( $m['first_name'] . ' ' . $m['last_name'] ) );
        }, $members ?: array() );
        $safe_locations = array_map( function( $l ) {
            return array( 'id' => (int) $l['id'], 'name' => $l['name'], 'radius_meters' => (float) $l['radius_meters'] );
        }, $locations ?: array() );
        $now = Geo_Attend_DB::now( $settings['timezone'] );
        $day = $now->format( 'l' );
        $open = in_array( $day, (array) $settings['attendance_days'], true );
        $start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format('Y-m-d') . ' ' . $settings['start_time'], $now->getTimezone() );
        $end = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format('Y-m-d') . ' ' . $settings['end_time'], $now->getTimezone() );
        $open = $open && $start && $end && $now >= $start && $now <= $end;
        return rest_ensure_response( array(
            'organization_name' => $settings['organization_name'],
            'organization_logo' => esc_url_raw( $settings['organization_logo'] ),
            'departments' => array_map( function( $d ) { return array( 'id' => (int) $d['id'], 'name' => $d['name'] ); }, $departments ?: array() ),
            'members' => $safe_members,
            'locations' => $safe_locations,
            'registration_enabled' => ! empty( $settings['allow_registration'] ),
            'schedule' => array( 'days' => array_values( (array) $settings['attendance_days'] ), 'start' => $settings['start_time'], 'end' => $settings['end_time'], 'timezone' => $settings['timezone'] ),
            'open' => $open,
        ) );
    }

    public static function check_in( WP_REST_Request $request ) {
        global $wpdb;
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $rate_key = 'geo_attend_rate_' . md5( $ip );
        $attempts = (int) get_transient( $rate_key );
        if ( $attempts >= 12 ) return new WP_Error( 'rate_limited', 'Too many attempts. Please wait a minute and try again.', array( 'status' => 429 ) );
        set_transient( $rate_key, $attempts + 1, MINUTE_IN_SECONDS );

        $body = $request->get_json_params();
        $member_id = absint( $body['member_id'] ?? 0 );
        $location_id = absint( $body['location_id'] ?? 0 );
        $pin = preg_replace( '/\D/', '', (string) ( $body['pin'] ?? '' ) );
        $lat = isset( $body['lat'] ) ? (float) $body['lat'] : null;
        $lng = isset( $body['lng'] ) ? (float) $body['lng'] : null;
        $accuracy = isset( $body['accuracy'] ) ? (float) $body['accuracy'] : null;
        if ( ! $member_id || ! $location_id || ! preg_match( '/^\d{4}$/', $pin ) || ! is_finite( $lat ) || ! is_finite( $lng ) ) return new WP_Error( 'invalid_input', 'Select your department, name, location and enter your 4-digit PIN.', array( 'status' => 400 ) );
        if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) return new WP_Error( 'invalid_location', 'The location coordinates are invalid.', array( 'status' => 400 ) );

        $settings = Geo_Attend_DB::settings();
        $now = Geo_Attend_DB::now( $settings['timezone'] );
        $day = $now->format( 'l' );
        $days = array_map( 'strval', (array) $settings['attendance_days'] );
        if ( ! in_array( $day, $days, true ) ) return new WP_Error( 'closed', 'Attendance is not scheduled for today.', array( 'status' => 403 ) );
        $start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format('Y-m-d') . ' ' . $settings['start_time'], $now->getTimezone() );
        $end = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format('Y-m-d') . ' ' . $settings['end_time'], $now->getTimezone() );
        if ( ! $start || ! $end || $now < $start || $now > $end ) return new WP_Error( 'closed', 'Attendance check-in is currently closed.', array( 'status' => 403 ) );
        if ( $accuracy !== null && $accuracy > (float) $settings['max_accuracy'] ) return new WP_Error( 'accuracy', 'Your location accuracy is too low. Move to an open area and try again.', array( 'status' => 403 ) );

        $t = Geo_Attend_DB::tables();
        $member = $wpdb->get_row( $wpdb->prepare( "SELECT id, department_id, first_name, last_name, pin_hash FROM {$t['members']} WHERE id = %d AND active = 1 LIMIT 1", $member_id ), ARRAY_A );
        if ( ! $member || ! Geo_Attend_DB::verify_pin( $pin, $member['pin_hash'] ) ) return new WP_Error( 'invalid_pin', 'The name or PIN is incorrect.', array( 'status' => 401 ) );
        $location = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, latitude, longitude, radius_meters FROM {$t['locations']} WHERE id = %d AND active = 1 LIMIT 1", $location_id ), ARRAY_A );
        if ( ! $location ) return new WP_Error( 'location_unavailable', 'The selected attendance location is unavailable.', array( 'status' => 400 ) );
        $distance = self::distance( (float) $location['latitude'], (float) $location['longitude'], $lat, $lng );
        if ( $distance > (float) $location['radius_meters'] ) return new WP_Error( 'outside_geofence', sprintf( 'You are about %dm from %s. You must be within %dm.', round( $distance ), $location['name'], round( $location['radius_meters'] ) ), array( 'status' => 403 ) );

        $date = $now->format( 'Y-m-d' );
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['attendance']} WHERE member_id = %d AND attendance_date = %s LIMIT 1", $member_id, $date ) );
        if ( $existing ) return new WP_Error( 'already_checked_in', 'You have already checked in today.', array( 'status' => 409 ) );

        $late_after = (int) $settings['late_after'];
        $late_cutoff = $start ? $start->modify( '+' . max(0, $late_after) . ' minutes' ) : $now;
        $status = ( $late_after > 0 && $now > $late_cutoff ) ? 'late' : 'present';
        $ok = $wpdb->insert( $t['attendance'], array(
            'member_id' => $member_id,
            'department_id' => $member['department_id'] ? (int) $member['department_id'] : null,
            'location_id' => $location_id,
            'attendance_date' => $date,
            'checked_in_at' => $now->format( 'Y-m-d H:i:s' ),
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy' => $accuracy,
            'status' => $status,
        ), array( '%d','%d','%d','%s','%s','%f','%f','%f','%s' ) );
        if ( false === $ok ) return new WP_Error( 'record_failed', 'Unable to record attendance. Please try again.', array( 'status' => 500 ) );
        return rest_ensure_response( array( 'success' => true, 'name' => trim( $member['first_name'] . ' ' . $member['last_name'] ), 'status' => $status, 'time' => $now->format( 'H:i' ) ) );
    }

    private static function distance( $lat1, $lng1, $lat2, $lng2 ) {
        $earth = 6371000;
        $dlat = deg2rad( $lat2 - $lat1 );
        $dlng = deg2rad( $lng2 - $lng1 );
        $a = sin($dlat/2) * sin($dlat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng/2) * sin($dlng/2);
        return 2 * $earth * asin( min(1, sqrt($a)) );
    }
}
