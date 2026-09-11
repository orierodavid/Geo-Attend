<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;
$prefix = $wpdb->prefix . 'geo_attend_';
$tables = array( 'tasks', 'projects', 'attendance', 'members', 'locations', 'departments' );
foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
}

$page_id = absint( get_option( 'geo_attend_staff_portal_page_id', 0 ) );
if ( $page_id ) {
    wp_delete_post( $page_id, true );
}

delete_option( 'geo_attend_staff_portal_page_id' );
delete_option( 'geo_attend_settings' );
delete_option( 'geo_attend_version' );
remove_role( 'geo_staff' );
