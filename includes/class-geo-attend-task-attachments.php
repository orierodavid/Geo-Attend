<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Geo_Attend_Task_Attachments {
    const MAX_BYTES = 10485760;
    const MAX_FILES = 5;
    const REST_NS = 'geo-attend/v1';

    public static function init() {
        self::ensure_table();
        // Task creation is owned by Geo_Attend_Management. Keeping a second
        // admin-post handler here would create duplicate tasks.
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ), 20 );
    }

    private static function table() { global $wpdb; return $wpdb->prefix . 'geo_attend_task_attachments'; }

    public static function ensure_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,task_id bigint(20) unsigned NOT NULL,uploaded_by bigint(20) unsigned NOT NULL,original_name varchar(255) NOT NULL,stored_name varchar(255) NOT NULL,relative_path varchar(500) NOT NULL,mime_type varchar(100) NOT NULL,file_size bigint(20) unsigned NOT NULL,created_at datetime NOT NULL,PRIMARY KEY (id),KEY task_id (task_id),KEY uploaded_by (uploaded_by)) {$charset};" );
    }

    private static function task_for_user( $task_id, $user_id ) {
        global $wpdb;
        $tasks = Geo_Attend_DB::tables()['tasks'];
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tasks} WHERE id=%d AND assigned_user_id=%d", $task_id, $user_id ) );
    }

    public static function register_rest() {
        register_rest_route( self::REST_NS, '/staff/tasks/(?P<id>\d+)/attachments', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'rest_list' ),
            'permission_callback' => array( 'Geo_Attend_Public', 'staff_permission' ),
        ) );
        register_rest_route( self::REST_NS, '/staff/tasks/(?P<task>\d+)/attachments/(?P<attachment>\d+)/download', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'rest_download' ),
            'permission_callback' => array( 'Geo_Attend_Public', 'staff_permission' ),
        ) );
    }

    public static function rest_list( WP_REST_Request $request ) {
        global $wpdb;
        $task_id = absint( $request['id'] );
        $task = self::task_for_user( $task_id, get_current_user_id() );
        if ( ! $task && ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'You do not have access to this task.', array( 'status' => 403 ) );
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,original_name,mime_type,file_size,created_at FROM ' . self::table() . ' WHERE task_id=%d ORDER BY id ASC', $task_id ), ARRAY_A );
        foreach ( $rows as &$row ) {
            $row['size_label'] = size_format( (int) $row['file_size'] );
            $row['download_url'] = rest_url( self::REST_NS . '/staff/tasks/' . $task_id . '/attachments/' . (int) $row['id'] . '/download' );
        }
        return rest_ensure_response( $rows );
    }

    public static function rest_download( WP_REST_Request $request ) {
        global $wpdb;
        $task_id = absint( $request['task'] );
        $attachment_id = absint( $request['attachment'] );
        $task = self::task_for_user( $task_id, get_current_user_id() );
        if ( ! $task && ! current_user_can( 'manage_options' ) ) return new WP_Error( 'forbidden', 'You do not have access to this task.', array( 'status' => 403 ) );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d AND task_id=%d', $attachment_id, $task_id ) );
        if ( ! $row ) return new WP_Error( 'not_found', 'Attachment not found.', array( 'status' => 404 ) );
        $upload = wp_upload_dir();
        $path = trailingslashit( $upload['basedir'] ) . $row->relative_path;
        if ( ! is_readable( $path ) ) return new WP_Error( 'missing_file', 'Attachment file is unavailable.', array( 'status' => 404 ) );
        nocache_headers();
        header( 'Content-Type: ' . $row->mime_type );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $row->original_name ) . '"' );
        readfile( $path );
        exit;
    }

    public static function frontend_assets() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'geo_staff_portal' ) ) return;
        wp_enqueue_style( 'geo-attend-task-attachments', GEO_ATTEND_URL . 'assets/css/task-attachments.css', array(), GEO_ATTEND_VERSION );
        wp_enqueue_script( 'geo-attend-task-attachments', GEO_ATTEND_URL . 'assets/js/task-attachments.js', array(), GEO_ATTEND_VERSION, true );
        wp_localize_script( 'geo-attend-task-attachments', 'GeoAttendAttachments', array( 'api' => rest_url( self::REST_NS ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
    }
}
