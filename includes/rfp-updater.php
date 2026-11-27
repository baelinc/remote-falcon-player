<?php
/**
 * GitHub updater — lets WordPress update this plugin from
 * https://github.com/baelinc/remote-falcon-player (main branch).
 *
 * An update is offered when the "Version:" header of remote-falcon-player.php
 * on the main branch is higher than the installed version. Bump the version
 * in the plugin header (and RFP_VERSION) whenever you push a change.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'RFP_GH_REPO',   'baelinc/remote-falcon-player' );
define( 'RFP_GH_BRANCH', 'main' );
define( 'RFP_GH_ZIP',    'https://github.com/' . RFP_GH_REPO . '/archive/refs/heads/' . RFP_GH_BRANCH . '.zip' );
define( 'RFP_GH_RAW',    'https://raw.githubusercontent.com/' . RFP_GH_REPO . '/' . RFP_GH_BRANCH . '/remote-falcon-player.php' );

/** Plugin basename of the running copy, e.g. "remote-falcon-player/remote-falcon-player.php". */
function rfp_basename() {
    return plugin_basename( RFP_DIR . 'remote-falcon-player.php' );
}

/** Latest version string on GitHub (cached 6 hours), or '' on failure. */
function rfp_remote_version( $force = false ) {
    $key = 'rfp_remote_version';
    if ( ! $force ) {
        $cached = get_transient( $key );
        if ( $cached !== false ) return $cached;
    }
    $res = wp_remote_get( RFP_GH_RAW, array( 'timeout' => 10 ) );
    $ver = '';
    if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
        if ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9A-Za-z.\-]*)/mi', wp_remote_retrieve_body( $res ), $m ) ) {
            $ver = $m[1];
        }
    }
    // Cache failures briefly so a GitHub outage doesn't slow every admin page.
    set_transient( $key, $ver, $ver ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
    return $ver;
}

/* Tell WordPress an update exists. */
add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
    if ( ! is_object( $transient ) ) return $transient;

    $remote = rfp_remote_version( true );
    if ( ! $remote ) return $transient;

    $item = (object) array(
        'id'          => 'github.com/' . RFP_GH_REPO,
        'slug'        => dirname( rfp_basename() ),
        'plugin'      => rfp_basename(),
        'new_version' => $remote,
        'url'         => 'https://github.com/' . RFP_GH_REPO,
        'package'     => RFP_GH_ZIP,
    );

    if ( version_compare( $remote, RFP_VERSION, '>' ) ) {
        $transient->response[ rfp_basename() ] = $item;
    } else {
        $transient->no_update[ rfp_basename() ] = $item;
    }
    return $transient;
});

/* "View details" popup. */
add_filter( 'plugins_api', function ( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== dirname( rfp_basename() ) ) {
        return $result;
    }
    return (object) array(
        'name'          => 'Remote Falcon Player',
        'slug'          => $args->slug,
        'version'       => rfp_remote_version() ?: RFP_VERSION,
        'author'        => 'Johnathan Evans',
        'homepage'      => 'https://github.com/' . RFP_GH_REPO,
        'download_link' => RFP_GH_ZIP,
        'sections'      => array( 'description' => 'Now Playing, Up Next, and song voting/requesting from Remote Falcon. Updates are delivered from GitHub.' ),
    );
}, 10, 3 );

/*
 * GitHub's zip unpacks to "remote-falcon-player-main/". Rename it to the folder
 * the plugin is currently installed in, so the update replaces it in place
 * instead of creating a duplicate copy.
 */
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra ) {
    global $wp_filesystem;
    if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== rfp_basename() ) return $source;

    $wanted = trailingslashit( $remote_source ) . dirname( rfp_basename() ) . '/';
    if ( trailingslashit( $source ) === $wanted ) return $source;

    if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ), true ) ) {
        return $wanted;
    }
    return new WP_Error( 'rfp_rename_failed', 'Remote Falcon Player: could not prepare the update folder.' );
}, 10, 4 );

/* Refresh the cached remote version after an update completes. */
add_action( 'upgrader_process_complete', function ( $upgrader, $options ) {
    if ( ( $options['type'] ?? '' ) === 'plugin' ) delete_transient( 'rfp_remote_version' );
}, 10, 2 );

/* "Check for updates" link on the Plugins page. */
add_filter( 'plugin_action_links_' . rfp_basename(), function ( $links ) {
    $url = wp_nonce_url( admin_url( 'plugins.php?rfp_check_update=1' ), 'rfp_check_update' );
    $links[] = '<a href="' . esc_url( $url ) . '">Check for updates</a>';
    return $links;
});
add_action( 'admin_init', function () {
    if ( empty( $_GET['rfp_check_update'] ) || ! current_user_can( 'update_plugins' ) ) return;
    check_admin_referer( 'rfp_check_update' );
    delete_transient( 'rfp_remote_version' );
    delete_site_transient( 'update_plugins' );
    wp_safe_redirect( admin_url( 'plugins.php?rfp_checked=1' ) );
    exit;
});
add_action( 'admin_notices', function () {
    if ( empty( $_GET['rfp_checked'] ) ) return;
    echo '<div class="notice notice-info is-dismissible"><p>Remote Falcon Player: checked GitHub for updates.</p></div>';
});
