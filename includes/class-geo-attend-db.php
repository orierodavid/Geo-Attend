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
            'projects'    => $p . 'projects',
            'tasks'       => $p . 'tasks',
        );
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::tables();
        $charset = $wpdb->get_charset_collate();
        $sql = array();
        $sql[] = "CREATE TABLE {$t['departments']} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,name varchar(150) NOT NULL,active tinyint(1) NOT NULL DEFAULT 1,created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY (id),UNIQUE KEY name (name),KEY active (active)) $charset;";
        $sql[] = "CREATE TABLE {$t['members']} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,user_id bigint(20) unsigned NULL,email varchar(190) NULL,department_id bigint(20) unsigned NULL,first_name varchar(100) NOT NULL,last_name varchar(100) NOT NULL,pin_hash varchar(255) NOT NULL DEFAULT '',active tinyint(1) NOT NULL DEFAULT 1,created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY (id),UNIQUE KEY user_id (user_id),KEY email (email),KEY department_id (department_id),KEY active (active),KEY name_lookup (last_name, first_name)) $charset;";
        $sql[] = "CREATE TABLE {$t['locations']} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,name varchar(150) NOT NULL,latitude decimal(10,7) NOT NULL,longitude decimal(10,7) NOT NULL,radius_meters decimal(10,2) NOT NULL DEFAULT 300,active tinyint(1) NOT NULL DEFAULT 1,created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY (id),KEY active (active)) $charset;";
        $sql[] = "CREATE TABLE {$t['attendance']} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,member_id bigint(20) unsigned NOT NULL,department_id bigint(20) unsigned NULL,location_id bigint(20) unsigned NULL,attendance_date date NOT NULL,checked_in_at datetime NOT NULL,latitude decimal(10,7) NOT NULL,longitude decimal(10,7) NOT NULL,accuracy decimal(10,2) NULL,status varchar(30) NOT NULL DEFAULT 'present',PRIMARY KEY (id),UNIQUE KEY member_date (member_id, attendance_date),KEY attendance_date (attendance_date),KEY department_id (department_id),KEY location_id (location_id)) $charset;";
        $sql[] = "CREATE TABLE {$t['projects']} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,name varchar(200) NOT NULL,description text NULL,status varchar(30) NOT NULL DEFAULT 'active',created_by bigint(20) unsigned NOT NULL,created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY (id),KEY status (status),KEY created_by (created_by)) $charset;";
        $sql[] = "CREATE TABLE {$t['tasks']} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,project_id bigint(20) unsigned NULL,title varchar(255) NOT NULL,description text NULL,assigned_user_id bigint(20) unsigned NOT NULL,created_by bigint(20) unsigned NOT NULL,priority varchar(20) NOT NULL DEFAULT 'medium',status varchar(30) NOT NULL DEFAULT 'todo',deadline date NULL,created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY (id),KEY project_id (project_id),KEY assigned_user_id (assigned_user_id),KEY status (status),KEY deadline (deadline)) $charset;";
        foreach ( $sql as $statement ) { dbDelta( $statement ); }
        if ( false === get_option( 'geo_attend_settings', false ) ) add_option( 'geo_attend_settings', self::default_settings() );
        if ( ! get_role( 'geo_staff' ) ) add_role( 'geo_staff', 'Geo Staff', array( 'read' => true ) );
        update_option( 'geo_attend_version', GEO_ATTEND_VERSION );
    }

    public static function maybe_upgrade() {
        $installed = get_option( 'geo_attend_version', '' );
        if ( version_compare( (string) $installed, GEO_ATTEND_VERSION, '<' ) ) self::activate();
        elseif ( ! get_role( 'geo_staff' ) ) add_role( 'geo_staff', 'Geo Staff', array( 'read' => true ) );
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
        );
    }
    public static function settings() { $settings = get_option( 'geo_attend_settings', array() ); return wp_parse_args( is_array( $settings ) ? $settings : array(), self::default_settings() ); }
    public static function now( $timezone = null ) { $tz = $timezone ?: self::settings()['timezone']; try { $zone = new DateTimeZone( $tz ); } catch ( Exception $e ) { $zone = wp_timezone(); } return new DateTimeImmutable( 'now', $zone ); }
    public static function normalize_time( $value, $fallback = '00:00' ) { $value = trim( (string) $value ); $formats = array( 'H:i', 'H:i:s', 'g:i A', 'g:i a', 'h:i A', 'h:i a', 'g A', 'g a', 'h A', 'h a' ); foreach ( $formats as $format ) { $dt = DateTimeImmutable::createFromFormat( '!' . $format, $value ); $errors = DateTimeImmutable::getLastErrors(); $has_errors = is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ); if ( $dt && ! $has_errors ) return $dt->format( 'H:i' ); } return $fallback; }
    public static function schedule_time( $date, $value, $timezone ) { $normalized = self::normalize_time( $value ); try { $zone = new DateTimeZone( $timezone ); } catch ( Exception $e ) { $zone = wp_timezone(); } return DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $normalized, $zone ); }
    public static function hash_pin( $pin ) { return wp_hash_password( (string) $pin ); }
    public static function verify_pin( $pin, $hash ) { return wp_check_password( (string) $pin, (string) $hash ); }
    public static function normalize_name( $value ) { $value = sanitize_text_field( wp_unslash( $value ) ); return trim( preg_replace( '/\s+/', ' ', $value ) ); }
    public static function pin_is_valid( $pin ) { if ( ! preg_match( '/^\d{4}$/', (string) $pin ) ) return false; if ( count( array_unique( str_split( $pin ) ) ) < 4 ) return false; $digits = array_map( 'intval', str_split( $pin ) ); $up = $down = true; for ( $i = 1; $i < 4; $i++ ) { $up = $up && $digits[$i] === $digits[$i-1] + 1; $down = $down && $digits[$i] === $digits[$i-1] - 1; } return ! $up && ! $down; }
}
