<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Geo_Attend_Task_Attachments {
    const MAX_BYTES = 10485760;
    const MAX_FILES = 5;
    const REST_NS = 'geo-attend/v1';

    public static function init() {
        self::ensure_table();
        remove_action( 'admin_post_geo_staff_create_task', array( 'Geo_Attend_Portal', 'create_task' ) );
        add_action( 'admin_post_geo_staff_create_task', array( __CLASS__, 'create_task_with_attachments' ), 10 );
        add_action( 'admin_footer', array( __CLASS__, 'admin_attachment_field' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ), 20 );
    }

    private static function table() { global $wpdb; return $wpdb->prefix . 'geo_attend_task_attachments'; }

    public static function ensure_table() {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $table = self::table(); $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,task_id bigint(20) unsigned NOT NULL,uploaded_by bigint(20) unsigned NOT NULL,original_name varchar(255) NOT NULL,stored_name varchar(255) NOT NULL,relative_path varchar(500) NOT NULL,mime_type varchar(100) NOT NULL,file_size bigint(20) unsigned NOT NULL,created_at datetime NOT NULL,PRIMARY KEY (id),KEY task_id (task_id),KEY uploaded_by (uploaded_by)) {$charset};" );
    }

    private static function allowed_mimes() {
        return array( 'jpg|jpeg|jpe'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','csv'=>'text/csv','txt'=>'text/plain','zip'=>'application/zip' );
    }
    private static function can_manage() { return current_user_can( 'manage_options' ); }
    private static function task_for_user( $task_id, $user_id ) { global $wpdb; $tasks = Geo_Attend_DB::tables()['tasks']; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tasks} WHERE id=%d AND assigned_user_id=%d", $task_id, $user_id ) ); }

    public static function create_task_with_attachments() {
        if ( ! self::can_manage() ) wp_die( 'Permission denied.' );
        Geo_Attend_Task_Guard::validate();
        global $wpdb; $table = Geo_Attend_DB::tables()['tasks'];
        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ); $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ); $project_id = absint( $_POST['project_id'] ?? 0 ); $assigned = absint( $_POST['assigned_user_id'] ?? 0 ); $priority = sanitize_key( $_POST['priority'] ?? 'medium' ); $deadline = sanitize_text_field( wp_unslash( $_POST['deadline'] ?? '' ) );
        if ( ! $title || ! $assigned ) wp_die( 'Task title and a valid staff assignee are required.' );
        if ( ! in_array( $priority, array('low','medium','high','urgent'), true ) ) $priority = 'medium';
        $ok = $wpdb->insert( $table, array('project_id'=>$project_id ?: null,'title'=>$title,'description'=>$description,'assigned_user_id'=>$assigned,'created_by'=>get_current_user_id(),'priority'=>$priority,'status'=>'todo','deadline'=>$deadline ?: null,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')), array('%d','%s','%s','%d','%d','%s','%s','%s','%s','%s') );
        if ( ! $ok ) wp_die( 'The task could not be created. Please try again.' );
        $task_id = (int) $wpdb->insert_id;
        if ( isset( $_FILES['geo_task_attachments'] ) ) self::store_files( $task_id, $_FILES['geo_task_attachments'], get_current_user_id() );
        wp_safe_redirect( admin_url( 'admin.php?page=geo-attend-tasks&geo_notice=Task+created' ) ); exit;
    }

    private static function store_files( $task_id, $files, $user_id ) {
        if ( ! isset( $files['name'] ) || ! is_array( $files['name'] ) ) return;
        $count = count( array_filter( $files['name'] ) ); if ( $count > self::MAX_FILES ) wp_die( 'You can attach a maximum of 5 files to one task.' );
        require_once ABSPATH . 'wp-admin/includes/file.php'; $allowed = self::allowed_mimes();
        foreach ( $files['name'] as $index => $original ) {
            if ( '' === $original ) continue;
            $size = isset($files['size'][$index]) ? (int)$files['size'][$index] : 0; if ( $size < 1 || $size > self::MAX_BYTES ) wp_die( 'Each task attachment must be 10 MB or smaller.' );
            $error = isset($files['error'][$index]) ? (int)$files['error'][$index] : UPLOAD_ERR_NO_FILE; if ( UPLOAD_ERR_OK !== $error ) wp_die( 'One of the task attachments could not be uploaded.' );
            $tmp = $files['tmp_name'][$index] ?? ''; if ( ! $tmp || ! is_uploaded_file($tmp) ) wp_die( 'Invalid task attachment upload.' );
            $type = wp_check_filetype_and_ext($tmp, $original, $allowed); if ( empty($type['type']) || empty($type['ext']) ) wp_die( 'Unsupported task attachment type. Use PDF, Word, Excel, PowerPoint, CSV, TXT, JPG, PNG, WEBP or ZIP.' );
            $result = self::move_private_file( array('name'=>sanitize_file_name($original),'tmp_name'=>$tmp), $allowed ); if ( is_wp_error($result) ) wp_die( esc_html($result->get_error_message()) );
            global $wpdb; $wpdb->insert(self::table(), array('task_id'=>$task_id,'uploaded_by'=>$user_id,'original_name'=>sanitize_text_field($original),'stored_name'=>$result['file'],'relative_path'=>$result['relative'],'mime_type'=>$type['type'],'file_size'=>$size,'created_at'=>current_time('mysql')), array('%d','%d','%s','%s','%s','%s','%d','%s'));
        }
    }

    private static function move_private_file( $file, $mimes ) {
        $upload = wp_upload_dir(); if ( ! empty($upload['error']) ) return new WP_Error('upload_dir',$upload['error']);
        $base = trailingslashit($upload['basedir']).'geo-attend-task-private'; if ( ! wp_mkdir_p($base) ) return new WP_Error('mkdir','Unable to create the private task attachment directory.');
        if ( ! file_exists($base.'/index.php') ) @file_put_contents($base.'/index.php',"<?php\n// Silence is golden.\n");
        if ( ! file_exists($base.'/.htaccess') ) @file_put_contents($base.'/.htaccess',"Options -Indexes\n<IfModule mod_authz_core.c>Require all denied</IfModule>\n<IfModule !mod_authz_core.c>Order allow,deny\nDeny from all</IfModule>\n");
        $name = wp_unique_filename($base,$file['name']); $target = trailingslashit($base).$name; if ( ! @move_uploaded_file($file['tmp_name'],$target) ) return new WP_Error('move','Unable to store the task attachment.');
        return array('file'=>$name,'relative'=>'geo-attend-task-private/'.$name);
    }

    public static function register_rest() {
        register_rest_route(self::REST_NS,'/staff/tasks/(?P<id>\d+)/attachments',array('methods'=>WP_REST_Server::READABLE,'callback'=>array(__CLASS__,'rest_list'),'permission_callback'=>array('Geo_Attend_Public','staff_permission')));
        register_rest_route(self::REST_NS,'/staff/tasks/(?P<task>\d+)/attachments/(?P<attachment>\d+)/download',array('methods'=>WP_REST_Server::READABLE,'callback'=>array(__CLASS__,'rest_download'),'permission_callback'=>array('Geo_Attend_Public','staff_permission')));
    }
    public static function rest_list(WP_REST_Request $request) { global $wpdb; $task_id=absint($request['id']); $task=self::task_for_user($task_id,get_current_user_id()); if(!$task&&!self::can_manage())return new WP_Error('forbidden','You do not have access to this task.',array('status'=>403)); $rows=$wpdb->get_results($wpdb->prepare('SELECT id,original_name,mime_type,file_size,created_at FROM '.self::table().' WHERE task_id=%d ORDER BY id ASC',$task_id),ARRAY_A); foreach($rows as &$row){$row['size_label']=size_format((int)$row['file_size']);$row['download_url']=rest_url(self::REST_NS.'/staff/tasks/'.$task_id.'/attachments/'.(int)$row['id'].'/download');} return rest_ensure_response($rows); }
    public static function rest_download(WP_REST_Request $request) { global $wpdb; $task_id=absint($request['task']); $attachment_id=absint($request['attachment']); $task=self::task_for_user($task_id,get_current_user_id()); if(!$task&&!self::can_manage())return new WP_Error('forbidden','You do not have access to this task.',array('status'=>403)); $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d AND task_id=%d',$attachment_id,$task_id)); if(!$row)return new WP_Error('not_found','Attachment not found.',array('status'=>404)); $upload=wp_upload_dir(); $path=trailingslashit($upload['basedir']).$row->relative_path; if(!is_readable($path))return new WP_Error('missing_file','Attachment file is unavailable.',array('status'=>404)); nocache_headers(); header('Content-Type: '.$row->mime_type); header('Content-Length: '.filesize($path)); header('Content-Disposition: attachment; filename="'.rawurlencode($row->original_name).'"'); readfile($path); exit; }

    public static function frontend_assets() { if(!is_singular())return; global $post; if(!$post||!has_shortcode($post->post_content,'geo_staff_portal'))return; wp_enqueue_script('geo-attend-task-attachments',GEO_ATTEND_URL.'assets/js/task-attachments.js',array(),GEO_ATTEND_VERSION,true); wp_localize_script('geo-attend-task-attachments','GeoAttendAttachments',array('api'=>rest_url(self::REST_NS),'nonce'=>wp_create_nonce('wp_rest'))); }
    public static function admin_attachment_field() { if(!current_user_can('manage_options')||empty($_GET['page'])||'geo-attend-tasks'!==sanitize_key($_GET['page']))return; echo '<script>(function(){function init(){var f=document.querySelector("form[action*=\\"admin-post.php\\"] input[name=action][value=geo_staff_create_task]");if(!f)return;var form=f.form;if(!form||form.dataset.geoAttachments)return;form.dataset.geoAttachments="1";form.enctype="multipart/form-data";var wrap=document.createElement("p");wrap.className="geo-task-attachments-field";wrap.innerHTML="<label><strong>Attachments</strong><br><input type=\"file\" name=\"geo_task_attachments[]\" multiple accept=\".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.jpg,.jpeg,.png,.webp,.zip\"><br><span>Up to 5 files · 10 MB maximum per file</span></label>";form.appendChild(wrap)}if(document.readyState!=="loading")init();else document.addEventListener("DOMContentLoaded",init)})();</script><style>.geo-task-attachments-field{margin:16px 0}.geo-task-attachments-field input[type=file]{margin-top:8px}.geo-task-attachments-field span{color:#646970;font-size:12px}</style>'; }
}
