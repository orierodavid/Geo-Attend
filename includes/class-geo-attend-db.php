<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Geo_Attend_DB {
    public static function tables() {
        global $wpdb;
        $p = $wpdb->prefix . 'geo_attend_';
        return array(
            'departments' => $p . 'departments',
            'members'     => $p . 'members',
            'locations'   => $p . 'locations',
            'attendance'  => $p . 'attendance',
        );
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::tables();
        $charset = $wpdb->get_charset_collate();
        $sql = array();
        $sql[] = "CREATE TABLE {$t['departments']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name),
            KEY active (active)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['members']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            department_id bigint(20) unsigned NULL,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            pin_hash varchar(255) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY department_id (department_id),
            KEY active (active),
            KEY name_lookup (last_name, first_name)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['locations']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            latitude decimal(10,7) NOT NULL,
            longitude decimal(10,7) NOT NULL,
            radius_meters decimal(10,2) NOT NULL DEFAULT 300,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY active (active)
        ) $charset;";
        $sql[] = "CREATE TABLE {$t['attendance']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            member_id bigint(20) unsigned NOT NULL,
            department_id bigint(20) unsigned NULL,
            location_id bigint(20) unsigned NULL,
            attendance_date date NOT NULL,
            checked_in_at datetime NOT NULL,
            latitude decimal(10,7) NOT NULL,
            longitude decimal(10,7) NOT NULL,
            accuracy decimal(10,2) NULL,
            status varchar(30) NOT NULL DEFAULT 'present',
            PRIMARY KEY  (id),
            UNIQUE KEY member_date (member_id, attendance_date),
            KEY attendance_date (attendance_date),
            KEY department_id (department_id),
            KEY location_id (location_id)
        ) $charset;";
        foreach ( $sql as $statement ) dbDelta( $statement );

        if ( false === get_option( 'geo_attend_settings', false ) ) {
            add_option( 'geo_attend_settings', self::default_settings() );
        }
        update_option( 'geo_attend_version', GEO_ATTEND_VERSION );
    }

    public static function default_settings() {
        return array(
            'organization_name' => get_bloginfo( 'name' ),
            'organization_logo' => '',
            'timezone' => wp_timezone_string() ?: 'UTC',
            'attendance_days' => array(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'late_after' => 15,
            'max_accuracy' => 100,
            'allow_registration' => false,
        );
    }

    public static function settings() {
        $settings = get_option( 'geo_attend_settings', array() );
        return wp_parse_args( is_array( $settings ) ? $settings : array(), self::default_settings() );
    }

    public static function now( $timezone = null ) {
        $tz = $timezone ?: self::settings()['timezone'];
        try { $zone = new DateTimeZone( $tz ); } catch ( Exception $e ) { $zone = wp_timezone(); }
        return new DateTimeImmutable( 'now', $zone );
    }

    public static function hash_pin( $pin ) { return wp_hash_password( (string) $pin ); }
    public static function verify_pin( $pin, $hash ) { return wp_check_password( (string) $pin, (string) $hash ); }

    public static function normalize_name( $value ) {
        $value = sanitize_text_field( wp_unslash( $value ) );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    public static function pin_is_valid( $pin ) {
        if ( ! preg_match( '/^\d{4}$/', (string) $pin ) ) return false;
        if ( count( array_unique( str_split( $pin ) ) ) < 4 ) return false;
        $digits = array_map( 'intval', str_split( $pin ) );
        $up = $down = true;
        for ( $i = 1; $i < 4; $i++ ) {
            $up = $up && $digits[$i] === $digits[$i-1] + 1;
            $down = $down && $digits[$i] === $digits[$i-1] - 1;
        }
        return ! $up && ! $down;
    }
}
