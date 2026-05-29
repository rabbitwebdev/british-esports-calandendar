<?php
/**
 * Plugin Name:       British Esports Calandendar
 * Description:       Front end calendar for British Esports events, with backend event management, shortcode support, an ACF block, Eventbrite integration, British Arena sync, and single/archive templates, ticket/register buttons, event category archive filters, month/agenda view toggle, recurring events, and Google Sheets intake sync, and CSV/XLSX upload imports from Google Sheets exports.
 * Version:           1.17.2
 * Author:            PYork
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bef-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'BEF_CALENDAR_VERSION' ) ) {
    define( 'BEF_CALENDAR_VERSION', '1.17.2' );
}

if ( ! defined( 'BEF_CALENDAR_FILE' ) ) {
    define( 'BEF_CALENDAR_FILE', __FILE__ );
}

if ( ! defined( 'BEF_CALENDAR_PATH' ) ) {
    define( 'BEF_CALENDAR_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'BEF_CALENDAR_URL' ) ) {
    define( 'BEF_CALENDAR_URL', plugin_dir_url( __FILE__ ) );
}

require_once BEF_CALENDAR_PATH . 'includes/class-bef-calendar.php';

function bef_calendar_run() {
    $plugin = new BEF_Calendar();
    $plugin->run();

    return $plugin;
}

$GLOBALS['bef_calendar_plugin'] = bef_calendar_run();

function bef_calendar() {
    return isset( $GLOBALS['bef_calendar_plugin'] ) ? $GLOBALS['bef_calendar_plugin'] : null;
}

register_activation_hook( __FILE__, array( 'BEF_Calendar', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BEF_Calendar', 'deactivate' ) );
