<?php
/**
 * Plugin Name:       Remote Falcon Player
 * Plugin URI:        https://remotefalcon.com
 * Description:       Now Playing, Up Next, and song voting/requesting from Remote Falcon on your WordPress site.
 * Version:           3.1.1
 * Author:            Your Light Show
 * License:           GPL-2.0+
 * Text Domain:       remote-falcon-player
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* Another copy of this plugin is already loaded (e.g. an old folder left installed).
 * Bail out with an admin notice instead of causing a fatal "cannot redeclare" error. */
if ( defined( 'RFP_VERSION' ) || function_exists( 'rfp_get' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Remote Falcon Player:</strong> two copies of this plugin are active. Deactivate and delete the duplicate on the Plugins page.</p></div>';
    });
    return;
}

define( 'RFP_VERSION',  '3.1.1' );
define( 'RFP_DIR',      plugin_dir_path( __FILE__ ) );
define( 'RFP_URL',      plugin_dir_url(  __FILE__ ) );

require_once RFP_DIR . 'includes/rfp-core.php';
