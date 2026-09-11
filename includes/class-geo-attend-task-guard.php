<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Server-side invariants for administrator-created tasks.
 * Runs before the task handler so forged assignee IDs cannot bypass
 * the staff-account boundary.
 */
class Geo_Attend_Task_Guard {
    public static function init() {
        add_action( 'admin_post_geo_staff_create_task', array( __CLASS__, 'validate' ), 1 );
    }

    private static function table( $name ) {
        $tables = Geo_Attend_DB::tables();
        return isset( $tables[ $name ] ) ? $tables[ $name ] : '';
    }

    public static function validate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.' );
        }
        check_admin_referer( 'geo_staff_create_task' );

        $assigned = absint( $_POST['assigned_user_id'] ?? 0 );
        if ( ! $assigned ) {
            wp_die( 'Select an active staff member.' );
        }

        $user = get_userdata( $assigned );
        if ( ! $user || ! in_array( 'geo_staff', (array) $user->roles, true ) || 0 !== (int) $user->user_status ) {
            wp_die( 'The selected assignee is not a valid staff account.' );
        }

        global $wpdb;
        $members = self::table( 'members' );
        $member = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, active FROM {$members} WHERE user_id=%d LIMIT 1",
            $assigned
        ) );

        if ( ! $member || ! (int) $member->active ) {
            wp_die( 'The selected staff account is not linked to an active attendance profile.' );
        }

        $project_id = absint( $_POST['project_id'] ?? 0 );
        if ( $project_id ) {
            $projects = self::table( 'projects' );
            $project = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, status FROM {$projects} WHERE id=%d LIMIT 1",
                $project_id
            ) );
            if ( ! $project || 'active' !== $project->status ) {
                wp_die( 'The selected project is not active.' );
            }
        }

        $deadline = sanitize_text_field( wp_unslash( $_POST['deadline'] ?? '' ) );
        if ( $deadline && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $deadline ) ) {
            wp_die( 'Enter a valid task deadline.' );
        }
    }
}
