<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Production management layer for staff onboarding and task administration.
 * Owns the admin screens and the account-creation workflow so the portal
 * shortcode remains focused on the staff experience.
 */
class Geo_Attend_Management {
    const STAFF_PAGE = 'geo-attend-staff';
    const TASK_PAGE  = 'geo-attend-tasks';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'replace_menus' ), 99 );
        add_action( 'admin_post_geo_staff_create_account', array( __CLASS__, 'create_account' ), 1 );
        add_action( 'admin_post_geo_staff_create_task', array( __CLASS__, 'create_task' ), 1 );
        add_action( 'admin_init', array( __CLASS__, 'ensure_schema' ) );
    }

    private static function tables() {
        return Geo_Attend_DB::tables();
    }

    private static function guard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.' );
        }
    }

    public static function ensure_schema() {
        global $wpdb;
        $members = self::tables()['members'];
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$members}", 0 );
        if ( ! in_array( 'location_id', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$members} ADD COLUMN location_id bigint(20) unsigned NULL AFTER department_id" );
            $wpdb->query( "ALTER TABLE {$members} ADD KEY location_id (location_id)" );
        }
    }

    public static function replace_menus() {
        remove_action( 'admin_menu', array( 'Geo_Attend_Portal', 'menu' ), 10 );
        add_submenu_page( 'geo-attend', 'Staff Accounts', 'Staff Accounts', 'manage_options', self::STAFF_PAGE, array( __CLASS__, 'staff_page' ) );
        add_submenu_page( 'geo-attend', 'Projects & Tasks', 'Projects & Tasks', 'manage_options', self::TASK_PAGE, array( __CLASS__, 'tasks_page' ) );
    }

    public static function create_account() {
        self::guard();
        check_admin_referer( 'geo_staff_create_account' );

        $email       = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $first       = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last        = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $department  = absint( $_POST['department_id'] ?? 0 );
        $location    = absint( $_POST['location_id'] ?? 0 );
        $password    = (string) ( $_POST['password'] ?? '' );
        $send_email  = ! empty( $_POST['send_credentials'] );

        if ( ! is_email( $email ) || ! $first || ! $last || strlen( $password ) < 8 ) {
            wp_die( 'Enter a valid name, email, department, and password of at least 8 characters.' );
        }
        if ( email_exists( $email ) ) {
            wp_die( 'A WordPress account already exists for this email.' );
        }

        global $wpdb;
        $tables = self::tables();
        if ( $department ) {
            $valid_department = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['departments']} WHERE id=%d AND active=1", $department ) );
            if ( ! $valid_department ) wp_die( 'The selected department is not active.' );
        }
        if ( $location ) {
            $valid_location = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['locations']} WHERE id=%d AND active=1", $location ) );
            if ( ! $valid_location ) wp_die( 'The selected location is not active.' );
        }

        $login = sanitize_user( current( explode( '@', $email ) ), true );
        if ( ! $login ) $login = 'staff';
        $base = $login;
        $i = 1;
        while ( username_exists( $login ) ) {
            $login = $base . $i;
            $i++;
        }

        $user_id = wp_insert_user( array(
            'user_login'   => $login,
            'user_pass'    => $password,
            'user_email'   => $email,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim( $first . ' ' . $last ),
            'role'         => 'geo_staff',
        ) );
        if ( is_wp_error( $user_id ) ) wp_die( esc_html( $user_id->get_error_message() ) );

        $inserted = $wpdb->insert( $tables['members'], array(
            'user_id'       => $user_id,
            'email'         => $email,
            'department_id' => $department ?: null,
            'location_id'   => $location ?: null,
            'first_name'    => $first,
            'last_name'     => $last,
            'pin_hash'      => '',
            'active'        => 1,
            'created_at'    => current_time( 'mysql' ),
            'updated_at'    => current_time( 'mysql' ),
        ), array( '%d','%s','%d','%d','%s','%s','%s','%d','%s','%s' ) );

        if ( false === $inserted ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $user_id );
            wp_die( 'The staff attendance profile could not be created.' );
        }

        update_user_meta( $user_id, 'geo_attend_must_change_password', 1 );

        $mail_sent = false;
        if ( $send_email ) {
            $mail_sent = self::send_credentials_email( $user_id, $password );
        }

        $notice = $mail_sent ? 'Staff account created and credentials emailed.' : 'Staff account created. Credentials email was not sent.';
        wp_safe_redirect( add_query_arg( array( 'page' => self::STAFF_PAGE, 'geo_notice' => rawurlencode( $notice ) ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function send_credentials_email( $user_id, $password ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return false;
        $subject = sprintf( '%s — Your staff portal account', get_bloginfo( 'name' ) );
        $portal  = class_exists( 'Geo_Attend_Portal_Access' ) ? Geo_Attend_Portal_Access::portal_url() : home_url( '/' );
        $body = "Hello {$user->first_name},\n\nYour staff account has been created for " . get_bloginfo( 'name' ) . ".\n\nSign in here: {$portal}\nEmail: {$user->user_email}\nTemporary password: {$password}\n\nFor security, you should change this password after signing in.\n\nRegards,\n" . get_bloginfo( 'name' );
        return (bool) wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
    }

    public static function create_task() {
        self::guard();
        check_admin_referer( 'geo_staff_create_task' );
        Geo_Attend_Task_Guard::validate();

        global $wpdb;
        $tables      = self::tables();
        $title       = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
        $project_id  = absint( $_POST['project_id'] ?? 0 );
        $assigned    = absint( $_POST['assigned_user_id'] ?? 0 );
        $priority    = sanitize_key( $_POST['priority'] ?? 'medium' );
        $deadline    = sanitize_text_field( wp_unslash( $_POST['deadline'] ?? '' ) );

        if ( ! $title || ! $assigned ) wp_die( 'Task title and a valid staff assignee are required.' );
        if ( ! in_array( $priority, array( 'low', 'medium', 'high', 'urgent' ), true ) ) $priority = 'medium';

        $inserted = $wpdb->insert( $tables['tasks'], array(
            'project_id'       => $project_id ?: null,
            'title'            => $title,
            'description'      => $description,
            'assigned_user_id' => $assigned,
            'created_by'       => get_current_user_id(),
            'priority'         => $priority,
            'status'           => 'todo',
            'deadline'         => $deadline ?: null,
            'created_at'       => current_time( 'mysql' ),
            'updated_at'       => current_time( 'mysql' ),
        ), array( '%d','%s','%s','%d','%d','%s','%s','%s','%s','%s' ) );

        if ( false === $inserted ) wp_die( 'The task could not be created. Please try again.' );
        $task_id = (int) $wpdb->insert_id;

        $attachments = isset( $_FILES['geo_task_attachments'] ) ? $_FILES['geo_task_attachments'] : null;
        if ( $attachments ) {
            $result = self::store_attachments( $task_id, $attachments );
            if ( is_wp_error( $result ) ) {
                self::delete_task_and_attachments( $task_id );
                wp_die( esc_html( $result->get_error_message() ) );
            }
        }

        self::send_task_email( $assigned, $task_id );
        wp_safe_redirect( add_query_arg( array( 'page' => self::TASK_PAGE, 'geo_notice' => rawurlencode( 'Task created and assigned successfully.' ) ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function store_attachments( $task_id, $files ) {
        if ( empty( $files['name'] ) || ! is_array( $files['name'] ) ) return true;
        $names = array_filter( $files['name'] );
        if ( count( $names ) > 5 ) return new WP_Error( 'too_many', 'You can attach a maximum of 5 files to one task.' );

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $allowed = array( 'jpg|jpeg|jpe'=>'image/jpeg','png'=>'image/png','webp'=>'image/png','pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','csv'=>'text/csv','txt'=>'text/plain','zip'=>'application/zip' );
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) return new WP_Error( 'upload_dir', $upload['error'] );
        $base = trailingslashit( $upload['basedir'] ) . 'geo-attend-task-private';
        if ( ! wp_mkdir_p( $base ) ) return new WP_Error( 'mkdir', 'Unable to create the private task attachment directory.' );
        if ( ! file_exists( $base . '/index.php' ) ) @file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" );
        if ( ! file_exists( $base . '/.htaccess' ) ) @file_put_contents( $base . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>Require all denied</IfModule>\n<IfModule !mod_authz_core.c>Order allow,deny\nDeny from all</IfModule>\n" );

        $prepared = array();
        foreach ( $files['name'] as $i => $original ) {
            if ( '' === $original ) continue;
            $size  = isset( $files['size'][$i] ) ? (int) $files['size'][$i] : 0;
            $error = isset( $files['error'][$i] ) ? (int) $files['error'][$i] : UPLOAD_ERR_NO_FILE;
            $tmp   = $files['tmp_name'][$i] ?? '';
            if ( $size < 1 || $size > 10485760 ) return new WP_Error( 'size', 'Each task attachment must be 10 MB or smaller.' );
            if ( UPLOAD_ERR_OK !== $error || ! $tmp || ! is_uploaded_file( $tmp ) ) return new WP_Error( 'upload', 'One of the task attachments could not be uploaded.' );
            $type = wp_check_filetype_and_ext( $tmp, $original, $allowed );
            if ( empty( $type['type'] ) || empty( $type['ext'] ) ) return new WP_Error( 'type', 'Unsupported task attachment type.' );
            $prepared[] = array( 'original' => sanitize_text_field( $original ), 'size' => $size, 'tmp' => $tmp, 'type' => $type );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'geo_attend_task_attachments';
        $moved = array();
        foreach ( $prepared as $file ) {
            $name   = wp_unique_filename( $base, sanitize_file_name( $file['original'] ) );
            $target = trailingslashit( $base ) . $name;
            if ( ! @move_uploaded_file( $file['tmp'], $target ) ) {
                foreach ( $moved as $path ) @unlink( $path );
                return new WP_Error( 'move', 'Unable to store one of the task attachments.' );
            }
            $moved[] = $target;
            $ok = $wpdb->insert( $table, array( 'task_id'=>$task_id, 'uploaded_by'=>get_current_user_id(), 'original_name'=>$file['original'], 'stored_name'=>$name, 'relative_path'=>'geo-attend-task-private/'.$name, 'mime_type'=>$file['type']['type'], 'file_size'=>$file['size'], 'created_at'=>current_time('mysql') ), array('%d','%d','%s','%s','%s','%s','%d','%s') );
            if ( false === $ok ) {
                foreach ( $moved as $path ) @unlink( $path );
                return new WP_Error( 'db', 'Unable to record the task attachment.' );
            }
        }
        return true;
    }

    private static function delete_task_and_attachments( $task_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'geo_attend_task_attachments';
        $upload = wp_upload_dir();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT relative_path FROM {$table} WHERE task_id=%d", $task_id ) );
        foreach ( $rows as $row ) @unlink( trailingslashit( $upload['basedir'] ) . $row->relative_path );
        $wpdb->delete( $table, array( 'task_id' => $task_id ), array( '%d' ) );
        $wpdb->delete( self::tables()['tasks'], array( 'id' => $task_id ), array( '%d' ) );
    }

    private static function send_task_email( $user_id, $task_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return false;
        global $wpdb;
        $tables = self::tables();
        $task = $wpdb->get_row( $wpdb->prepare( "SELECT t.*, p.name project_name FROM {$tables['tasks']} t LEFT JOIN {$tables['projects']} p ON p.id=t.project_id WHERE t.id=%d", $task_id ) );
        if ( ! $task ) return false;
        $portal = class_exists( 'Geo_Attend_Portal_Access' ) ? Geo_Attend_Portal_Access::portal_url() : home_url( '/' );
        $subject = sprintf( '%s — New task assigned: %s', get_bloginfo('name'), $task->title );
        $body = "Hello {$user->first_name},\n\nA new task has been assigned to you.\n\nTask: {$task->title}\nProject: " . ( $task->project_name ?: 'General' ) . "\nPriority: " . ucfirst($task->priority) . "\nDeadline: " . ( $task->deadline ?: 'No deadline' ) . "\n\n{$task->description}\n\nOpen your staff portal: {$portal}\n\nRegards,\n" . get_bloginfo('name');
        return (bool) wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
    }

    public static function staff_page() {
        self::guard();
        global $wpdb;
        $t = self::tables();
        $departments = $wpdb->get_results( "SELECT id,name FROM {$t['departments']} WHERE active=1 ORDER BY name", ARRAY_A );
        $locations   = $wpdb->get_results( "SELECT id,name FROM {$t['locations']} WHERE active=1 ORDER BY name", ARRAY_A );
        $users       = get_users( array( 'role' => 'geo_staff', 'orderby' => 'display_name', 'order' => 'ASC' ) );
        echo '<div class="wrap"><h1>Staff Accounts</h1><p>Create authenticated staff accounts, assign department and location, and email their credentials.</p>';
        if ( isset($_GET['geo_notice']) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( rawurldecode( wp_unslash($_GET['geo_notice']) ) ) . '</p></div>';
        echo '<div class="card" style="max-width:900px;padding:20px"><h2>Create staff account</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="geo_staff_create_account">' . wp_nonce_field('geo_staff_create_account','_wpnonce',true,false) . '<table class="form-table"><tr><th>First name</th><td><input class="regular-text" required name="first_name"></td></tr><tr><th>Last name</th><td><input class="regular-text" required name="last_name"></td></tr><tr><th>Work email</th><td><input class="regular-text" type="email" required name="email"></td></tr><tr><th>Department</th><td><select name="department_id"><option value="0">Select department</option>'; foreach($departments as $d) echo '<option value="'.esc_attr($d['id']).'">'.esc_html($d['name']).'</option>'; echo '</select></td></tr><tr><th>Assigned location</th><td><select name="location_id"><option value="0">Select location</option>'; foreach($locations as $l) echo '<option value="'.esc_attr($l['id']).'">'.esc_html($l['name']).'</option>'; echo '</select></td></tr><tr><th>Temporary password</th><td><input class="regular-text" type="password" minlength="8" required name="password"></td></tr><tr><th>Credentials email</th><td><label><input type="checkbox" name="send_credentials" value="1" checked> Email login credentials to the staff member</label></td></tr></table><p><button class="button button-primary">Create staff account</button></p></form></div><hr><h2>Staff directory</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Department</th><th>Location</th><th>Account</th></tr></thead><tbody>';
        foreach($users as $u){ $m=$wpdb->get_row($wpdb->prepare("SELECT m.*,d.name department_name,l.name location_name FROM {$t['members']} m LEFT JOIN {$t['departments']} d ON d.id=m.department_id LEFT JOIN {$t['locations']} l ON l.id=m.location_id WHERE m.user_id=%d",$u->ID)); echo '<tr><td>'.esc_html($u->display_name).'</td><td>'.esc_html($u->user_email).'</td><td>'.esc_html($m->department_name??'Unassigned').'</td><td>'.esc_html($m->location_name??'Unassigned').'</td><td>Active</td></tr>'; }
        echo '</tbody></table></div>';
    }

    public static function tasks_page() {
        self::guard();
        global $wpdb;
        $t = self::tables();
        $projects = $wpdb->get_results("SELECT * FROM {$t['projects']} WHERE status='active' ORDER BY name", ARRAY_A);
        $users = get_users(array('role'=>'geo_staff','orderby'=>'display_name','order'=>'ASC'));
        $tasks = $wpdb->get_results("SELECT t.*,p.name project_name,u.display_name assignee FROM {$t['tasks']} t LEFT JOIN {$t['projects']} p ON p.id=t.project_id LEFT JOIN {$wpdb->users} u ON u.ID=t.assigned_user_id ORDER BY t.created_at DESC",ARRAY_A);
        echo '<div class="wrap"><h1>Projects & Tasks</h1><p>Create projects, assign tasks, attach files, and notify staff.</p>';
        if(isset($_GET['geo_notice'])) echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(rawurldecode(wp_unslash($_GET['geo_notice']))).'</p></div>';
        echo '<div style="display:grid;grid-template-columns:minmax(300px,1fr) minmax(400px,1.5fr);gap:24px;align-items:start"><div class="card" style="padding:20px"><h2>New project</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="geo_staff_create_project">'.wp_nonce_field('geo_staff_create_project','_wpnonce',true,false).'<p><input class="regular-text" required name="name" placeholder="Project name"></p><p><textarea class="large-text" name="description" rows="4" placeholder="Project description"></textarea></p><button class="button button-primary">Create project</button></form></div><div class="card" style="padding:20px"><h2>New task</h2><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="geo_staff_create_task">'.wp_nonce_field('geo_staff_create_task','_wpnonce',true,false).'<p><input class="large-text" required name="title" placeholder="Task title"></p><p><textarea class="large-text" name="description" rows="5" placeholder="Task description"></textarea></p><p><select name="project_id"><option value="0">General task</option>';foreach($projects as $p)echo '<option value="'.esc_attr($p['id']).'">'.esc_html($p['name']).'</option>';echo '</select></p><p><select required name="assigned_user_id"><option value="">Assign to staff</option>';foreach($users as $u)echo '<option value="'.esc_attr($u->ID).'">'.esc_html($u->display_name).' — '.esc_html($u->user_email).'</option>';echo '</select></p><p><select name="priority"><option value="medium">Medium priority</option><option value="low">Low priority</option><option value="high">High priority</option><option value="urgent">Urgent</option></select> <input type="date" name="deadline"></p><p><label><strong>Attachments</strong><br><input type="file" name="geo_task_attachments[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.jpg,.jpeg,.png,.webp,.zip"><br><small>Up to 5 files · 10 MB maximum per file</small></label></p><p><button class="button button-primary button-large">Create task & assign</button></p></form></div></div><hr><h2>Task board</h2><table class="widefat striped"><thead><tr><th>Task</th><th>Project</th><th>Assigned to</th><th>Priority</th><th>Status</th><th>Deadline</th></tr></thead><tbody>';
        foreach($tasks as $task){$attachments=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}geo_attend_task_attachments WHERE task_id=%d",$task['id']));echo '<tr><td><strong>'.esc_html($task['title']).'</strong><br><span>'.esc_html($task['description']).'</span>'.($attachments?' <small>📎 '.esc_html($attachments).' attachment(s)</small>':'').'</td><td>'.esc_html($task['project_name']?:'General').'</td><td>'.esc_html($task['assignee']?:'—').'</td><td>'.esc_html(ucfirst($task['priority'])).'</td><td>'.esc_html(ucwords(str_replace('_',' ',$task['status']))).'</td><td>'.esc_html($task['deadline']?:'—').'</td></tr>';}
        echo '</tbody></table></div>';
    }
}
