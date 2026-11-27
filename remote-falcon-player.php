<?php
/**
 * Plugin Name:       Remote Falcon Player
 * Plugin URI:        https://remotefalcon.com
 * Description:       Now Playing, Up Next, and song voting/requesting from Remote Falcon on your WordPress site.
 * Version:           3.2.2
 * Author:            Johnathan Evans
 * License:           GPL-2.0+
 * Text Domain:       remote-falcon-player
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* If another copy of this plugin is installed (old folder, "-main" folder, ...),
 * remove it so this copy fully replaces it. Runs when a duplicate is already
 * loaded, and once after install. */
if ( defined( 'RFP_VERSION' ) || function_exists( 'rfp_get' ) || ! get_option( 'rfp_dedupe_done' ) ) {
    if ( file_exists( __DIR__ . '/includes/rfp-dedupe.php' ) ) {
        include __DIR__ . '/includes/rfp-dedupe.php';
    }
}

/* An older copy was already loaded in this request. It has been removed from disk
 * (if permissions allow) and will be gone on the next page load; don't redeclare. */
if ( defined( 'RFP_VERSION' ) || function_exists( 'rfp_get' ) ) {
    if ( empty( $GLOBALS['rfp_removed_dupes'] ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Remote Falcon Player:</strong> another copy of this plugin is active and could not be removed automatically. Delete the duplicate folder in wp-content/plugins.</p></div>';
        });
    }
    return;
}

define( 'RFP_VERSION',  '3.2.2' );
define( 'RFP_DIR',      plugin_dir_path( __FILE__ ) );
define( 'RFP_URL',      plugin_dir_url(  __FILE__ ) );

if ( ! file_exists( RFP_DIR . 'includes/rfp-core.php' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Remote Falcon Player:</strong> the <code>includes/rfp-core.php</code> file is missing. Re-upload the full plugin folder.</p></div>';
    });
    return;
}
require_once RFP_DIR . 'includes/rfp-core.php';
