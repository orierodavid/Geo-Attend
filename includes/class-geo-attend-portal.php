<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Geo_Attend_Portal {
    public static function init() {
        add_shortcode( 'geo_staff_portal', array( __CLASS__, 'shortcode' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_nopriv_geo_staff_login', array( __CLASS__, 'login' ) );
        add_action( 'admin_post_geo_staff_login', array( __CLASS__, 'login' ) );
        add_action( 'admin_post_geo_staff_logout', array( __CLASS__, 'logout' ) );
        add_action( 'admin_post_geo_staff_task_status', array( __CLASS__, 'update_task_status' ) );
        add_action( 'admin_post_geo_staff_create_account', array( __CLASS__, 'create_account' ) );
        add_action( 'admin_post_geo_staff_create_project', array( __CLASS__, 'create_project' ) );
        add_action( 'admin_post_geo_staff_create_task', array( __CLASS__, 'create_task' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }

    private static function table( $name ) {
        $tables = Geo_Attend_DB::tables();
        return isset( $tables[ $name ] ) ? $tables[ $name ] : '';
    }

    private static function staff_user( $user_id = 0 ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) return false;
        $user = get_userdata( $user_id );
        if ( ! $user ) return false;
        return in_array( 'geo_staff', (array) $user->roles, true ) || user_can( $user_id, 'manage_options' ) ? $user : false;
    }

    public static function assets() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'geo_staff_portal' ) ) return;
        wp_enqueue_style( 'geo-attend-portal', GEO_ATTEND_URL . 'assets/css/staff-portal.css', array(), GEO_ATTEND_VERSION );
    }

    public static function menu() {
        add_submenu_page( 'geo-attend', 'Staff Accounts', 'Staff Accounts', 'manage_options', 'geo-attend-staff', array( __CLASS__, 'staff_page' ) );
        add_submenu_page( 'geo-attend', 'Projects & Tasks', 'Projects & Tasks', 'manage_options', 'geo-attend-tasks', array( __CLASS__, 'tasks_page' ) );
    }

    public static function create_account() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( 'geo_staff_create_account' );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $password = (string) ( $_POST['password'] ?? '' );
        if ( ! is_email( $email ) || ! $first || ! $last || strlen( $password ) < 8 ) wp_die( 'Enter a valid name, email and password of at least 8 characters.' );
        if ( email_exists( $email ) ) wp_die( 'A WordPress account already exists for this email.' );
        $login = sanitize_user( current( explode( '@', $email ) ), true );
        if ( ! $login ) $login = 'staff';
        $base = $login; $i = 1;
        while ( username_exists( $login ) ) { $login = $base . $i; $i++; }
        $user_id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $password, 'user_email' => $email, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ), 'role' => 'geo_staff' ) );
        if ( is_wp_error( $user_id ) ) wp_die( esc_html( $user_id->get_error_message() ) );
        $members = self::table( 'members' );
        global $wpdb;
        $wpdb->insert( $members, array( 'user_id' => $user_id, 'email' => $email, 'first_name' => $first, 'last_name' => $last, 'pin_hash' => '', 'active' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( '%d','%s','%s','%s','%s','%d','%s','%s' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=geo-attend-staff&geo_notice=Staff+account+created' ) ); exit;
    }

    public static function create_project() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( 'geo_staff_create_project' );
        global $wpdb; $table = self::table( 'projects' );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
        if ( ! $name ) wp_die( 'Project name is required.' );
        $wpdb->insert( $table, array( 'name' => $name, 'description' => $description, 'status' => 'active', 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( '%s','%s','%s','%d','%s','%s' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=geo-attend-tasks&geo_notice=Project+created' ) ); exit;
    }

    public static function create_task() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( 'geo_staff_create_task' );
        global $wpdb; $table = self::table( 'tasks' );
        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
        $project_id = absint( $_POST['project_id'] ?? 0 );
        $assigned = absint( $_POST['assigned_user_id'] ?? 0 );
        $priority = sanitize_key( $_POST['priority'] ?? 'medium' );
        $deadline = sanitize_text_field( wp_unslash( $_POST['deadline'] ?? '' ) );
        if ( ! $title || ! $assigned || ! get_userdata( $assigned ) ) wp_die( 'Task title and a valid staff assignee are required.' );
        if ( ! in_array( $priority, array( 'low','medium','high','urgent' ), true ) ) $priority = 'medium';
        $wpdb->insert( $table, array( 'project_id' => $project_id ?: null, 'title' => $title, 'description' => $description, 'assigned_user_id' => $assigned, 'created_by' => get_current_user_id(), 'priority' => $priority, 'status' => 'todo', 'deadline' => $deadline ?: null, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( '%d','%s','%s','%d','%d','%s','%s','%s','%s','%s' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=geo-attend-tasks&geo_notice=Task+created' ) ); exit;
    }

    public static function update_task_status() {
        $user = self::staff_user();
        if ( ! $user ) wp_die( 'Please sign in.' );
        check_admin_referer( 'geo_staff_task_status' );
        global $wpdb; $table = self::table( 'tasks' ); $id = absint( $_POST['task_id'] ?? 0 ); $status = sanitize_key( $_POST['status'] ?? '' );
        if ( ! in_array( $status, array( 'todo','in_progress','review','completed' ), true ) ) wp_die( 'Invalid task status.' );
        $task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND assigned_user_id=%d", $id, $user->ID ) );
        if ( ! $task ) wp_die( 'Task not found.' );
        $wpdb->update( $table, array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s','%s' ), array( '%d' ) );
        wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) ); exit;
    }

    public static function login() {
        check_admin_referer( 'geo_staff_login' );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $password = (string) ( $_POST['password'] ?? '' );
        $redirect = esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? home_url( '/' ) ) );
        $user = get_user_by( 'email', $email );
        if ( ! $user ) wp_safe_redirect( add_query_arg( 'geo_login_error', '1', $redirect ) ) && exit;
        $signed = wp_signon( array( 'user_login' => $user->user_login, 'user_password' => $password, 'remember' => true ), is_ssl() );
        if ( is_wp_error( $signed ) || ! self::staff_user( $signed->ID ) ) {
            if ( ! is_wp_error( $signed ) ) wp_logout();
            wp_safe_redirect( add_query_arg( 'geo_login_error', '1', $redirect ) ); exit;
        }
        wp_safe_redirect( $redirect ); exit;
    }

    public static function logout() {
        check_admin_referer( 'geo_staff_logout' ); wp_logout(); wp_safe_redirect( home_url( '/' ) ); exit;
    }

    public static function shortcode() {
        $user = self::staff_user();
        ob_start();
        if ( ! $user ) { self::login_view(); return ob_get_clean(); }
        global $wpdb; $tasks = self::table( 'tasks' ); $projects = self::table( 'projects' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.*, p.name project_name FROM {$tasks} t LEFT JOIN {$projects} p ON p.id=t.project_id WHERE t.assigned_user_id=%d ORDER BY (t.status='completed'), t.deadline IS NULL, t.deadline ASC, FIELD(t.priority,'urgent','high','medium','low')", $user->ID ), ARRAY_A );
        echo '<section class="geo-staff-portal"><div class="geo-staff-top"><div><span>STAFF PORTAL</span><h2>Welcome, ' . esc_html( $user->first_name ?: $user->display_name ) . '</h2><p>Manage your assigned work and attendance from one place.</p></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'geo_staff_logout', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="geo_staff_logout"><button class="geo-staff-link">Sign out</button></form></div>';
        echo '<div class="geo-staff-grid"><article><small>MY TASKS</small><strong>' . count( $rows ) . '</strong><span>Assigned to you</span></article><article><small>IN PROGRESS</small><strong>' . count( array_filter( $rows, function($r){ return 'in_progress' === $r['status']; } ) ) . '</strong><span>Currently active</span></article><article><small>COMPLETED</small><strong>' . count( array_filter( $rows, function($r){ return 'completed' === $r['status']; } ) ) . '</strong><span>Finished</span></article></div>';
        echo '<div class="geo-staff-panel"><div class="geo-staff-panel-head"><div><h3>My tasks</h3><p>Keep your work status up to date.</p></div></div>';
        if ( ! $rows ) echo '<div class="geo-staff-empty">No tasks have been assigned to you yet.</div>';
        foreach ( $rows as $task ) {
            $status_label = ucwords( str_replace( '_', ' ', $task['status'] ) );
            echo '<div class="geo-task"><div class="geo-task-main"><div class="geo-task-meta"><span>' . esc_html( $task['project_name'] ?: 'General task' ) . '</span><b class="priority-' . esc_attr( $task['priority'] ) . '">' . esc_html( ucfirst( $task['priority'] ) ) . '</b></div><h4>' . esc_html( $task['title'] ) . '</h4><p>' . esc_html( $task['description'] ) . '</p><small>' . ( $task['deadline'] ? 'Due ' . esc_html( wp_date( get_option('date_format'), strtotime( $task['deadline'] ) ) ) : 'No deadline' ) . '</small></div><form class="geo-task-status" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'geo_staff_task_status', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="geo_staff_task_status"><input type="hidden" name="task_id" value="' . esc_attr( $task['id'] ) . '"><label>Status<select name="status" onchange="this.form.submit()">'; foreach ( array( 'todo'=>'To Do','in_progress'=>'In Progress','review'=>'Review','completed'=>'Completed' ) as $key=>$label ) echo '<option value="' . esc_attr($key) . '" ' . selected($task['status'],$key,false) . '>' . esc_html($label) . '</option>'; echo '</select></label></form></div>';
        }
        echo '</div></section>';
        return ob_get_clean();
    }

    private static function login_view() {
        $redirect = is_singular() ? get_permalink() : home_url( '/' );
        echo '<section class="geo-staff-login"><div class="geo-login-card"><span class="geo-login-kicker">STAFF PORTAL</span><h2>Sign in to your workspace</h2><p>Use your work email and password to access your tasks and staff tools.</p>';
        if ( isset( $_GET['geo_login_error'] ) ) echo '<div class="geo-login-error">The email or password is incorrect.</div>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'geo_staff_login', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="geo_staff_login"><input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '"><label>Work email<input type="email" name="email" autocomplete="email" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button type="submit">Sign in</button><a href="' . esc_url( wp_lostpassword_url( $redirect ) ) . '">Forgot password?</a></form></div></section>';
    }

    public static function staff_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        global $wpdb; $members = self::table( 'members' ); $users = get_users( array( 'role' => 'geo_staff', 'orderby' => 'display_name', 'order' => 'ASC' ) );
        echo '<div class="wrap"><h1>Staff Accounts</h1><p>Create WordPress-powered staff accounts. Staff sign in with email and password; existing attendance data remains unchanged.</p><hr><h2>Create staff account</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:700px"><input type="hidden" name="action" value="geo_staff_create_account">' . wp_nonce_field('geo_staff_create_account','_wpnonce',true,false) . '<table class="form-table"><tr><th>First name</th><td><input class="regular-text" required name="first_name"></td></tr><tr><th>Last name</th><td><input class="regular-text" required name="last_name"></td></tr><tr><th>Work email</th><td><input class="regular-text" type="email" required name="email"></td></tr><tr><th>Temporary password</th><td><input class="regular-text" type="password" minlength="8" required name="password"></td></tr></table><p><button class="button button-primary">Create staff account</button></p></form><hr><h2>Staff directory</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Account</th></tr></thead><tbody>';
        foreach ( $users as $u ) echo '<tr><td>' . esc_html($u->display_name) . '</td><td>' . esc_html($u->user_email) . '</td><td>Active</td></tr>';
        echo '</tbody></table></div>';
    }

    public static function tasks_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        global $wpdb; $projects = self::table('projects'); $tasks = self::table('tasks'); $users = get_users(array('role'=>'geo_staff','orderby'=>'display_name','order'=>'ASC')); $project_rows=$wpdb->get_results("SELECT * FROM {$projects} ORDER BY name",ARRAY_A); $task_rows=$wpdb->get_results("SELECT t.*,p.name project_name,u.display_name assignee FROM {$tasks} t LEFT JOIN {$projects} p ON p.id=t.project_id LEFT JOIN {$wpdb->users} u ON u.ID=t.assigned_user_id ORDER BY t.created_at DESC",ARRAY_A);
        echo '<div class="wrap"><h1>Projects & Tasks</h1><p>Create projects and assign work to authenticated staff accounts.</p><hr><div style="display:flex;gap:40px;flex-wrap:wrap"><div style="min-width:360px;max-width:520px"><h2>New project</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="geo_staff_create_project">'.wp_nonce_field('geo_staff_create_project','_wpnonce',true,false).'<p><input class="regular-text" required name="name" placeholder="Project name"></p><p><textarea class="large-text" name="description" rows="3" placeholder="Project description"></textarea></p><button class="button button-primary">Create project</button></form></div><div style="min-width:360px;max-width:620px"><h2>New task</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="geo_staff_create_task">'.wp_nonce_field('geo_staff_create_task','_wpnonce',true,false).'<p><input class="regular-text" required name="title" placeholder="Task title"></p><p><textarea class="large-text" name="description" rows="3" placeholder="Task description"></textarea></p><p><select name="project_id"><option value="0">General task</option>';foreach($project_rows as $p)echo '<option value="'.esc_attr($p['id']).'">'.esc_html($p['name']).'</option>';echo '</select></p><p><select required name="assigned_user_id"><option value="">Assign to staff</option>';foreach($users as $u)echo '<option value="'.esc_attr($u->ID).'">'.esc_html($u->display_name).' — '.esc_html($u->user_email).'</option>';echo '</select></p><p><select name="priority"><option value="medium">Medium priority</option><option value="low">Low priority</option><option value="high">High priority</option><option value="urgent">Urgent</option></select> <input type="date" name="deadline"></p><button class="button button-primary">Create task</button></form></div></div><hr><h2>Task board</h2><table class="widefat striped"><thead><tr><th>Task</th><th>Project</th><th>Assigned to</th><th>Priority</th><th>Status</th><th>Deadline</th></tr></thead><tbody>';foreach($task_rows as $t)echo '<tr><td><strong>'.esc_html($t['title']).'</strong><br><span>'.esc_html($t['description']).'</span></td><td>'.esc_html($t['project_name']?:'General').'</td><td>'.esc_html($t['assignee']).'</td><td>'.esc_html(ucfirst($t['priority'])).'</td><td>'.esc_html(ucwords(str_replace('_',' ',$t['status']))).'</td><td>'.esc_html($t['deadline']?:'—').'</td></tr>';echo '</tbody></table></div>';
    }
}
