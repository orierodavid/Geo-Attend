<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;
$prefix = $wpdb->prefix . 'geo_attend_';
$tables = array( 'attendance', 'members', 'locations', 'departments' );
foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
}
delete_option( 'geo_attend_settings' );
delete_option( 'geo_attend_version' );
