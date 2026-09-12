<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Geo_Attend_Public {
    public static function init() { add_shortcode( 'geo_attend', array( __CLASS__, 'shortcode' ) ); add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) ); add_action( 'rest_api_init', array( __CLASS__, 'routes' ) ); }
    public static function assets() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! $post ) return;
        $has_attendance = has_shortcode( $post->post_content, 'geo_attend' );
        $has_staff_portal = has_shortcode( $post->post_content, 'geo_staff_portal' );
        if ( ! $has_attendance && ! $has_staff_portal ) return;
        if ( $has_attendance ) {
            wp_enqueue_style( 'geo-attend-frontend', GEO_ATTEND_URL . 'assets/css/frontend.css', array(), GEO_ATTEND_VERSION );
            wp_enqueue_script( 'geo-attend-frontend', GEO_ATTEND_URL . 'assets/js/frontend.js', array(), GEO_ATTEND_VERSION, true );
        }
        if ( $has_staff_portal && is_user_logged_in() ) {
            wp_enqueue_script( 'geo-attend-staff-attendance', GEO_ATTEND_URL . 'assets/js/staff-attendance.js', array(), GEO_ATTEND_VERSION, true );
            wp_add_inline_script( 'geo-attend-staff-attendance', 'window.GeoAttendStaff=' . wp_json_encode( array( 'api' => esc_url_raw( rest_url( 'geo-attend/v1' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ) . ';', 'before' );
        }
        if ( $has_attendance ) wp_add_inline_script( 'geo-attend-frontend', 'window.GeoAttend=' . wp_json_encode( array( 'api' => esc_url_raw( rest_url( 'geo-attend/v1' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) ) . ';', 'before' );
    }
    public static function shortcode() { return '<div class="geo-attend" data-geo-attend><div class="geo-attend-loading">Loading attendance…</div></div>'; }
    public static function routes() {
        register_rest_route( 'geo-attend/v1', '/config', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'config' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( 'geo-attend/v1', '/check-in', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'check_in' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( 'geo-attend/v1', '/staff/attendance-status', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'staff_attendance_status' ), 'permission_callback' => array( __CLASS__, 'staff_permission' ) ) );
        register_rest_route( 'geo-attend/v1', '/staff/check-in', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'staff_check_in' ), 'permission_callback' => array( __CLASS__, 'staff_permission' ) ) );
    }
    public static function staff_permission() {
        if ( ! is_user_logged_in() ) return new WP_Error( 'not_authenticated', 'Please sign in before checking in.', array( 'status' => 401 ) );
        $user = wp_get_current_user();
        if ( ! $user || ( ! in_array( 'geo_staff', (array) $user->roles, true ) && ! user_can( $user->ID, 'manage_options' ) ) ) return new WP_Error( 'forbidden', 'Your account is not authorized for staff attendance.', array( 'status' => 403 ) );
        return true;
    }
    private static function staff_member( $user_id ) {
        global $wpdb;
        $t = Geo_Attend_DB::tables();
        return $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, department_id, first_name, last_name, active FROM {$t['members']} WHERE user_id = %d AND active = 1 LIMIT 1", $user_id ), ARRAY_A );
    }
    private static function attendance_window( $settings ) {
        $now = Geo_Attend_DB::now( $settings['timezone'] );
        $day = $now->format( 'l' );
        $days = array_map( 'strval', (array) $settings['attendance_days'] );
        $start_time = Geo_Attend_DB::normalize_time( $settings['start_time'], '09:00' );
        $end_time = Geo_Attend_DB::normalize_time( $settings['end_time'], '17:00' );
        $start = Geo_Attend_DB::schedule_time( $now->format('Y-m-d'), $start_time, $now->getTimezone()->getName() );
        $end = Geo_Attend_DB::schedule_time( $now->format('Y-m-d'), $end_time, $now->getTimezone()->getName() );
        return array( 'now' => $now, 'day' => $day, 'days' => $days, 'start' => $start, 'end' => $end, 'start_time' => $start_time, 'end_time' => $end_time, 'open' => in_array( $day, $days, true ) && $start && $end && $now >= $start && $now <= $end );
    }
    public static function config() {
        $settings = Geo_Attend_DB::settings(); $window = self::attendance_window( $settings );
        return rest_ensure_response( array( 'organization_logo' => esc_url_raw( $settings['organization_logo'] ), 'schedule' => array( 'days' => $window['days'], 'start' => $window['start_time'], 'end' => $window['end_time'], 'timezone' => $settings['timezone'] ), 'open' => $window['open'] ) );
    }
    public static function staff_attendance_status() {
        global $wpdb;
        $user = wp_get_current_user(); $member = self::staff_member( $user->ID );
        if ( ! $member ) return new WP_Error( 'member_link_missing', 'Your staff account is not linked to an active attendance profile. Contact an administrator.', array( 'status' => 409 ) );
        $settings = Geo_Attend_DB::settings(); $window = self::attendance_window( $settings ); $t = Geo_Attend_DB::tables(); $date = $window['now']->format( 'Y-m-d' );
        $record = $wpdb->get_row( $wpdb->prepare( "SELECT a.checked_in_at, a.status, a.location_id, l.name location_name FROM {$t['attendance']} a LEFT JOIN {$t['locations']} l ON l.id = a.location_id WHERE a.member_id = %d AND a.attendance_date = %s LIMIT 1", $member['id'], $date ), ARRAY_A );
        return rest_ensure_response( array( 'member' => array( 'name' => trim( $member['first_name'] . ' ' . $member['last_name'] ), 'department_id' => $member['department_id'] ? (int) $member['department_id'] : null ), 'attendance' => $record ? array( 'checked_in' => true, 'time' => wp_date( 'H:i', strtotime( $record['checked_in_at'] ), $window['now']->getTimezone() ), 'status' => $record['status'], 'location' => $record['location_name'] ) : array( 'checked_in' => false ), 'schedule' => array( 'open' => $window['open'], 'start' => $window['start_time'], 'end' => $window['end_time'], 'timezone' => $settings['timezone'] ) ) );
    }
    public static function staff_check_in( WP_REST_Request $request ) {
        global $wpdb;
        $user = wp_get_current_user(); $member = self::staff_member( $user->ID );
        if ( ! $member ) return new WP_Error( 'member_link_missing', 'Your staff account is not linked to an active attendance profile. Contact an administrator.', array( 'status' => 409 ) );
        $body = $request->get_json_params(); $lat = isset( $body['lat'] ) ? (float) $body['lat'] : null; $lng = isset( $body['lng'] ) ? (float) $body['lng'] : null; $accuracy = isset( $body['accuracy'] ) ? (float) $body['accuracy'] : null;
        if ( ! is_finite( $lat ) || ! is_finite( $lng ) ) return new WP_Error( 'invalid_location', 'Your browser did not provide a valid location. Please allow location access and try again.', array( 'status' => 400 ) );
        if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) return new WP_Error( 'invalid_location', 'The location coordinates are invalid.', array( 'status' => 400 ) );
        if ( $accuracy !== null && ( ! is_finite( $accuracy ) || $accuracy < 0 ) ) return new WP_Error( 'invalid_accuracy', 'The location accuracy value is invalid.', array( 'status' => 400 ) );
        $rate_key = 'geo_attend_staff_rate_' . (int) $user->ID; $attempts = (int) get_transient( $rate_key ); if ( $attempts >= 8 ) return new WP_Error( 'rate_limited', 'Too many location attempts. Please wait a minute and try again.', array( 'status' => 429 ) ); set_transient( $rate_key, $attempts + 1, MINUTE_IN_SECONDS );
        $settings = Geo_Attend_DB::settings(); $window = self::attendance_window( $settings );
        if ( ! in_array( $window['day'], $window['days'], true ) ) return new WP_Error( 'closed', 'Attendance is not scheduled for today.', array( 'status' => 403 ) );
        if ( ! $window['start'] || ! $window['end'] || $window['now'] < $window['start'] || $window['now'] > $window['end'] ) return new WP_Error( 'closed', 'Attendance check-in is currently closed.', array( 'status' => 403 ) );
        if ( $accuracy !== null && $accuracy > (float) $settings['max_accuracy'] ) return new WP_Error( 'accuracy', sprintf( 'Your location accuracy is too low (±%dm). Move to an open area and try again.', round( $accuracy ) ), array( 'status' => 403 ) );
        $t = Geo_Attend_DB::tables();
        $locations = $wpdb->get_results( "SELECT id, name, latitude, longitude, radius_meters FROM {$t['locations']} WHERE active = 1 ORDER BY id ASC", ARRAY_A );
        if ( ! $locations ) return new WP_Error( 'location_unavailable', 'No active attendance location is configured.', array( 'status' => 500 ) );
        $location = null; $distance = PHP_FLOAT_MAX;
        foreach ( $locations as $candidate_location ) { $candidate_distance = self::distance( (float) $candidate_location['latitude'], (float) $candidate_location['longitude'], $lat, $lng ); if ( $candidate_distance < $distance ) { $distance = $candidate_distance; $location = $candidate_location; } }
        if ( ! $location || $distance > (float) $location['radius_meters'] ) { $nearest_name = $location ? $location['name'] : 'the attendance area'; $nearest_radius = $location ? round( (float) $location['radius_meters'] ) : 0; return new WP_Error( 'outside_geofence', sprintf( 'Clock in disapproved. You are about %dm from %s. You must be within %dm.', round( $distance ), $nearest_name, $nearest_radius ), array( 'status' => 403, 'distance_meters' => round( $distance ), 'radius_meters' => $nearest_radius ) ); }
        $date = $window['now']->format( 'Y-m-d' ); $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['attendance']} WHERE member_id = %d AND attendance_date = %s LIMIT 1", $member['id'], $date ) ); if ( $existing ) return new WP_Error( 'already_checked_in', 'Clock in already approved for today.', array( 'status' => 409 ) );
        $late_after = (int) $settings['late_after']; $late_cutoff = $window['start'] ? $window['start']->modify( '+' . max(0, $late_after) . ' minutes' ) : $window['now']; $status = ( $late_after > 0 && $window['now'] > $late_cutoff ) ? 'late' : 'present';
        $ok = $wpdb->insert( $t['attendance'], array( 'member_id' => (int) $member['id'], 'department_id' => $member['department_id'] ? (int) $member['department_id'] : null, 'location_id' => (int) $location['id'], 'attendance_date' => $date, 'checked_in_at' => $window['now']->format( 'Y-m-d H:i:s' ), 'latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy, 'status' => $status ), array( '%d','%d','%d','%s','%s','%f','%f','%f','%s' ) );
        if ( false === $ok ) { $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['attendance']} WHERE member_id = %d AND attendance_date = %s LIMIT 1", $member['id'], $date ) ); if ( $existing ) return new WP_Error( 'already_checked_in', 'Clock in already approved for today.', array( 'status' => 409 ) ); return new WP_Error( 'record_failed', 'Unable to record attendance. Please try again.', array( 'status' => 500 ) ); }
        return rest_ensure_response( array( 'success' => true, 'approved' => true, 'name' => trim( $member['first_name'] . ' ' . $member['last_name'] ), 'status' => $status, 'time' => $window['now']->format( 'H:i' ), 'location' => $location['name'], 'distance_meters' => round( $distance ), 'radius_meters' => round( (float) $location['radius_meters'] ) ) );
    }
    public static function check_in( WP_REST_Request $request ) {
        global $wpdb; $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown'; $rate_key = 'geo_attend_rate_' . md5( $ip ); $attempts = (int) get_transient( $rate_key ); if ( $attempts >= 12 ) return new WP_Error( 'rate_limited', 'Too many attempts. Please wait a minute and try again.', array( 'status' => 429 ) ); set_transient( $rate_key, $attempts + 1, MINUTE_IN_SECONDS );
        $body = $request->get_json_params(); $member_id = absint( $body['member_id'] ?? 0 ); $member_name = Geo_Attend_DB::normalize_name( $body['member_name'] ?? '' ); $pin = preg_replace( '/\D/', '', (string) ( $body['pin'] ?? '' ) ); $lat = isset( $body['lat'] ) ? (float) $body['lat'] : null; $lng = isset( $body['lng'] ) ? (float) $body['lng'] : null; $accuracy = isset( $body['accuracy'] ) ? (float) $body['accuracy'] : null;
        if ( ! preg_match( '/^\d{4}$/', $pin ) || ( ! $member_id && $member_name === '' ) || ! is_finite( $lat ) || ! is_finite( $lng ) ) return new WP_Error( 'invalid_input', 'Enter your name and 4-digit PIN. Your location is verified automatically.', array( 'status' => 400 ) );
        if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) return new WP_Error( 'invalid_location', 'The location coordinates are invalid.', array( 'status' => 400 ) );
        if ( $accuracy !== null && ( ! is_finite( $accuracy ) || $accuracy < 0 ) ) return new WP_Error( 'invalid_accuracy', 'The location accuracy value is invalid.', array( 'status' => 400 ) );
        $settings = Geo_Attend_DB::settings(); $window = self::attendance_window( $settings ); if ( ! in_array( $window['day'], $window['days'], true ) ) return new WP_Error( 'closed', 'Attendance is not scheduled for today.', array( 'status' => 403 ) );
        if ( ! $window['start'] || ! $window['end'] || $window['now'] < $window['start'] || $window['now'] > $window['end'] ) return new WP_Error( 'closed', 'Attendance check-in is currently closed.', array( 'status' => 403 ) );
        if ( $accuracy !== null && $accuracy > (float) $settings['max_accuracy'] ) return new WP_Error( 'accuracy', 'Your location accuracy is too low. Move to an open area and try again.', array( 'status' => 403 ) );
        $t = Geo_Attend_DB::tables();
        if ( $member_id ) $members = $wpdb->get_results( $wpdb->prepare( "SELECT id, department_id, first_name, last_name, pin_hash FROM {$t['members']} WHERE id = %d AND active = 1 LIMIT 1", $member_id ), ARRAY_A ); else { $normalized_name = strtolower( trim( preg_replace( '/\s+/', ' ', $member_name ) ) ); $members = $wpdb->get_results( "SELECT id, department_id, first_name, last_name, pin_hash FROM {$t['members']} WHERE active = 1 ORDER BY id ASC", ARRAY_A ); $members = array_values( array_filter( $members ?: array(), function( $candidate ) use ( $normalized_name ) { return strtolower( trim( preg_replace( '/\s+/', ' ', $candidate['first_name'] . ' ' . $candidate['last_name'] ) ) ) === $normalized_name; } ) ); }
        $member = null; foreach ( $members ?: array() as $candidate ) { if ( Geo_Attend_DB::verify_pin( $pin, $candidate['pin_hash'] ) ) { $member = $candidate; break; } }
        if ( ! $member ) return new WP_Error( 'invalid_pin', 'The name or PIN is incorrect.', array( 'status' => 401 ) );
        $member_rate_key = 'geo_attend_member_rate_' . (int) $member['id']; $member_attempts = (int) get_transient( $member_rate_key ); if ( $member_attempts >= 5 ) return new WP_Error( 'member_rate_limited', 'Too many attempts for this member. Please wait a minute and try again.', array( 'status' => 429 ) ); set_transient( $member_rate_key, $member_attempts + 1, MINUTE_IN_SECONDS );
        $locations = $wpdb->get_results( "SELECT id, name, latitude, longitude, radius_meters FROM {$t['locations']} WHERE active = 1 ORDER BY id ASC", ARRAY_A ); if ( ! $locations ) return new WP_Error( 'location_unavailable', 'No active attendance location is configured.', array( 'status' => 500 ) );
        $location = null; $distance = PHP_FLOAT_MAX; foreach ( $locations as $candidate_location ) { $candidate_distance = self::distance( (float) $candidate_location['latitude'], (float) $candidate_location['longitude'], $lat, $lng ); if ( $candidate_distance < $distance ) { $distance = $candidate_distance; $location = $candidate_location; } }
        if ( ! $location || $distance > (float) $location['radius_meters'] ) { $nearest_name = $location ? $location['name'] : 'the attendance area'; $nearest_radius = $location ? round( (float) $location['radius_meters'] ) : 0; return new WP_Error( 'outside_geofence', sprintf( 'You are about %dm from %s. You must be within %dm.', round( $distance ), $nearest_name, $nearest_radius ), array( 'status' => 403 ) ); }
        $date = $window['now']->format( 'Y-m-d' ); $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['attendance']} WHERE member_id = %d AND attendance_date = %s LIMIT 1", $member['id'], $date ) ); if ( $existing ) return new WP_Error( 'already_checked_in', 'You have already checked in today.', array( 'status' => 409 ) );
        $late_after = (int) $settings['late_after']; $late_cutoff = $window['start'] ? $window['start']->modify( '+' . max(0, $late_after) . ' minutes' ) : $window['now']; $status = ( $late_after > 0 && $window['now'] > $late_cutoff ) ? 'late' : 'present';
        $ok = $wpdb->insert( $t['attendance'], array( 'member_id' => (int) $member['id'], 'department_id' => $member['department_id'] ? (int) $member['department_id'] : null, 'location_id' => (int) $location['id'], 'attendance_date' => $date, 'checked_in_at' => $window['now']->format( 'Y-m-d H:i:s' ), 'latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy, 'status' => $status ), array( '%d','%d','%d','%s','%s','%f','%f','%f','%s' ) );
        if ( false === $ok ) { $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['attendance']} WHERE member_id = %d AND attendance_date = %s LIMIT 1", $member['id'], $date ) ); if ( $existing ) return new WP_Error( 'already_checked_in', 'You have already checked in today.', array( 'status' => 409 ) ); return new WP_Error( 'record_failed', 'Unable to record attendance. Please try again.', array( 'status' => 500 ) ); }
        return rest_ensure_response( array( 'success' => true, 'name' => trim( $member['first_name'] . ' ' . $member['last_name'] ), 'status' => $status, 'time' => $window['now']->format( 'H:i' ) ) );
    }
    private static function distance( $lat1, $lng1, $lat2, $lng2 ) { $earth = 6371000; $dlat = deg2rad( $lat2 - $lat1 ); $dlng = deg2rad( $lng2 - $lng1 ); $a = sin($dlat/2) * sin($dlat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng/2) * sin($dlng/2); return 2 * $earth * asin( min(1, sqrt($a)) ); }
}
