<?php
/**
 * Plugin Name: Geo-Attend
 * Plugin URI: https://github.com/orierodavid/Geo-Attend
 * Description: Configurable geofenced attendance with authenticated staff accounts, projects and tasks.
 * Version: 0.3.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Deotech Web Technologies
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: geo-attend
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'GEO_ATTEND_VERSION', '0.3.0' );
define( 'GEO_ATTEND_FILE', __FILE__ );
define( 'GEO_ATTEND_DIR', plugin_dir_path( __FILE__ ) );
define( 'GEO_ATTEND_URL', plugin_dir_url( __FILE__ ) );
require_once GEO_ATTEND_DIR . 'includes/class-geo-attend-db.php';
require_once GEO_ATTEND_DIR . 'includes/class-geo-attend-public.php';
require_once GEO_ATTEND_DIR . 'includes/class-geo-attend-admin.php';
require_once GEO_ATTEND_DIR . 'includes/class-geo-attend-portal.php';
require_once GEO_ATTEND_DIR . 'includes/class-geo-attend-portal-access.php';
register_activation_hook( __FILE__, array( 'Geo_Attend_DB', 'activate' ) );
add_action( 'plugins_loaded', function() {
    Geo_Attend_DB::maybe_upgrade();
    Geo_Attend_Public::init();
    Geo_Attend_Portal::init();
    Geo_Attend_Portal_Access::init();
    if ( is_admin() ) { Geo_Attend_Admin::init(); }
} );
