<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Makes the staff portal discoverable without requiring a shortcode to be added manually.
 */
class Geo_Attend_Portal_Access {
    const PAGE_OPTION = 'geo_attend_staff_portal_page_id';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'ensure_page' ), 20 );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
    }

    public static function ensure_page() {
        $page_id = absint( get_option( self::PAGE_OPTION, 0 ) );
        if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
            return $page_id;
        }

        $existing = get_page_by_path( 'staff-portal', OBJECT, 'page' );
        if ( $existing instanceof WP_Post ) {
            $page_id = (int) $existing->ID;
        } else {
            $page_id = wp_insert_post(
                array(
                    'post_title'   => 'Staff Portal',
                    'post_name'    => 'staff-portal',
                    'post_content' => '[geo_staff_portal]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ),
                true
            );
            if ( is_wp_error( $page_id ) ) {
                return 0;
            }
        }

        update_option( self::PAGE_OPTION, (int) $page_id, false );
        return (int) $page_id;
    }

    public static function portal_url() {
        $page_id = self::ensure_page();
        return $page_id ? get_permalink( $page_id ) : home_url( '/' );
    }

    public static function menu() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        add_submenu_page(
            'geo-attend',
            'Staff Portal',
            'Staff Portal',
            'manage_options',
            'geo-attend-staff-portal',
            array( __CLASS__, 'redirect_to_portal' )
        );
    }

    public static function redirect_to_portal() {
        wp_safe_redirect( self::portal_url() );
        exit;
    }
}
