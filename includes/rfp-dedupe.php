<?php
/**
 * Removes any OTHER installed copy of this plugin (e.g. an old folder or a
 * "-main" folder from a GitHub zip) so this copy fully replaces it.
 * Settings live in the database (option "rfp_settings") and are not touched.
 *
 * Only folders directly inside wp-content/plugins whose main file declares
 * "Plugin Name: Remote Falcon Player" are removed; this copy's folder never is.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

( function () {
    $root = realpath( WP_PLUGIN_DIR );
    $self = realpath( dirname( __DIR__ ) );
    if ( ! $root || ! $self ) return;

    $rm = function ( $path ) use ( &$rm ) {
        if ( is_link( $path ) || is_file( $path ) ) return @unlink( $path );
        foreach ( (array) scandir( $path ) as $e ) {
            if ( $e === '.' || $e === '..' ) continue;
            $rm( $path . '/' . $e );
        }
        return @rmdir( $path );
    };

    $removed = array();
    $failed  = false;

    foreach ( (array) glob( $root . '/*/*.php' ) as $file ) {
        $dir = dirname( $file );
        if ( is_link( $dir ) || realpath( $dir ) === $self || realpath( dirname( $dir ) ) !== $root ) continue;

        $head = (string) file_get_contents( $file, false, null, 0, 8192 );
        if ( ! preg_match( '/^[ \t\/*#@]*Plugin Name:\s*Remote Falcon Player\s*$/mi', $head ) ) continue;

        // Deactivate (site and network level).
        $base   = basename( $dir ) . '/' . basename( $file );
        $active = (array) get_option( 'active_plugins', array() );
        $new    = array_values( array_diff( $active, array( $base ) ) );
        if ( $new !== $active ) update_option( 'active_plugins', $new );
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            $net = (array) get_site_option( 'active_sitewide_plugins', array() );
            if ( isset( $net[ $base ] ) ) { unset( $net[ $base ] ); update_site_option( 'active_sitewide_plugins', $net ); }
        }

        // Delete the folder.
        if ( $rm( $dir ) ) { $removed[] = basename( $dir ); } else { $failed = true; }
    }

    if ( $removed ) {
        $GLOBALS['rfp_removed_dupes'] = true;
        add_action( 'admin_notices', function () use ( $removed ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Remote Falcon Player:</strong> replaced the old copy ('
               . esc_html( implode( ', ', $removed ) ) . '). Your settings were kept.</p></div>';
        });
    }
    if ( ! $failed ) update_option( 'rfp_dedupe_done', 1 );
} )();
