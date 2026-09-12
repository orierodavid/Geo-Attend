<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;
$prefix = $wpdb->prefix . 'geo_attend_';
$tables = array( 'task_attachments', 'tasks', 'projects', 'attendance', 'members', 'locations', 'departments' );
foreach ( $tables as $table ) { $wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" ); }

$upload = wp_upload_dir();
$private_dir = trailingslashit( $upload['basedir'] ) . 'geo-attend-task-private';
if ( is_dir( $private_dir ) ) {
    $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $private_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $iterator as $item ) { $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); }
    @rmdir( $private_dir );
}

$page_id = absint( get_option( 'geo_attend_staff_portal_page_id', 0 ) );
if ( $page_id ) { wp_delete_post( $page_id, true ); }

delete_option( 'geo_attend_staff_portal_page_id' );
delete_option( 'geo_attend_settings' );
delete_option( 'geo_attend_version' );
remove_role( 'geo_staff' );
