<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Enforces the staff member's assigned attendance location for the existing
 * authenticated check-in endpoint without changing the public route contract.
 */
class Geo_Attend_Staff_Location_Guard {
    public static function init() {
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_check_in' ), 5, 3 );
    }

    public static function guard_check_in( $result, WP_REST_Server $server, WP_REST_Request $request ) {
        if ( '/geo-attend/v1/staff/check-in' !== $request->get_route() || 'POST' !== strtoupper( $request->get_method() ) ) {
            return $result;
        }

        if ( ! is_user_logged_in() ) {
            return $result;
        }

        $user = wp_get_current_user();
        if ( ! $user || ! in_array( 'geo_staff', (array) $user->roles, true ) ) {
            return $result;
        }

        global $wpdb;
        $tables = Geo_Attend_DB::tables();
        $member = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, department_id, location_id FROM {$tables['members']} WHERE user_id=%d AND active=1 LIMIT 1",
            $user->ID
        ), ARRAY_A );

        if ( ! $member ) {
            return new WP_Error( 'member_link_missing', 'Your staff account is not linked to an active attendance profile. Contact an administrator.', array( 'status' => 409 ) );
        }
        if ( empty( $member['department_id'] ) ) {
            return new WP_Error( 'department_link_missing', 'Your staff account has no department assigned. Contact an administrator.', array( 'status' => 409 ) );
        }
        if ( empty( $member['location_id'] ) ) {
            return new WP_Error( 'location_link_missing', 'Your staff account has no attendance location assigned. Contact an administrator.', array( 'status' => 409 ) );
        }

        $body = $request->get_json_params();
        $lat = isset( $body['lat'] ) ? (float) $body['lat'] : null;
        $lng = isset( $body['lng'] ) ? (float) $body['lng'] : null;
        if ( ! is_finite( $lat ) || ! is_finite( $lng ) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
            return $result;
        }

        $location = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, latitude, longitude, radius_meters, active FROM {$tables['locations']} WHERE id=%d LIMIT 1",
            (int) $member['location_id']
        ), ARRAY_A );
        if ( ! $location || ! (int) $location['active'] ) {
            return new WP_Error( 'location_unavailable', 'Your assigned attendance location is unavailable. Contact an administrator.', array( 'status' => 409 ) );
        }

        $assigned_distance = self::distance( (float) $location['latitude'], (float) $location['longitude'], $lat, $lng );
        $assigned_radius = (float) $location['radius_meters'];
        $remaining = max( 0, (int) round( $assigned_distance - $assigned_radius ) );

        if ( $assigned_distance > $assigned_radius ) {
            return new WP_Error(
                'outside_geofence',
                sprintf( 'Clock in disapproved. You are about %dm from %s. Move approximately %dm closer to enter the attendance area.', round( $assigned_distance ), $location['name'], $remaining ),
                array( 'status' => 403, 'distance_meters' => round( $assigned_distance ), 'radius_meters' => round( $assigned_radius ), 'remaining_meters' => $remaining, 'location_id' => (int) $location['id'] )
            );
        }

        $active = $wpdb->get_results( "SELECT id, latitude, longitude, radius_meters FROM {$tables['locations']} WHERE active=1", ARRAY_A );
        foreach ( $active as $candidate ) {
            if ( (int) $candidate['id'] === (int) $location['id'] ) {
                continue;
            }
            $candidate_distance = self::distance( (float) $candidate['latitude'], (float) $candidate['longitude'], $lat, $lng );
            if ( $candidate_distance < $assigned_distance ) {
                return new WP_Error( 'wrong_attendance_location', sprintf( 'Clock in disapproved. Your account is assigned to %s. Please move closer to your assigned attendance location.', $location['name'] ), array( 'status' => 403, 'location_id' => (int) $location['id'], 'distance_meters' => round( $assigned_distance ), 'radius_meters' => round( $assigned_radius ), 'remaining_meters' => 0 ) );
            }
        }

        return $result;
    }

    private static function distance( $lat1, $lon1, $lat2, $lon2 ) {
        $earth = 6371000;
        $d_lat = deg2rad( $lat2 - $lat1 );
        $d_lon = deg2rad( $lon2 - $lon1 );
        $a = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lon / 2 ) ** 2;
        return $earth * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
    }
}
