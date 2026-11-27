<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/*
 * REST API base — all endpoints are under this URL.
 * Spec: https://app.swaggerhub.com/apis-docs/whitesoup12/RemoteFalcon/20250729.1
 *
 * GET  /showDetails           → preferences + sequences + votes + playingNow + playingNext
 * POST /addSequenceToQueue    → { sequence, viewerLatitude?, viewerLongitude? }
 * POST /voteForSequence       → { sequence, viewerLatitude?, viewerLongitude? }
 *
 * Auth: Bearer JWT signed HS256 with Secret Key.
 *       JWT payload MUST be: { "accessToken": "<your access token>" }
 */
define( 'RFP_API_BASE', 'https://remotefalcon.com/remote-falcon-external-api' );

/* ================================================================
 * HELPERS
 * ============================================================== */

function rfp_get( $key, $default = '' ) {
    $opts = get_option( 'rfp_settings', array() );
    return isset( $opts[$key] ) ? $opts[$key] : $default;
}

/**
 * Build a HS256 JWT.
 * IMPORTANT: Per the RF API spec the payload must be:
 *   { "accessToken": "<access_token>" }
 * NOT { "sub": "..." } as commonly assumed.
 */
function rfp_build_jwt( $access_token, $secret_key ) {
    $header  = rfp_b64u( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
    $payload = rfp_b64u( wp_json_encode( array( 'accessToken' => $access_token ) ) );
    $sig     = rfp_b64u( hash_hmac( 'sha256', "$header.$payload", $secret_key, true ) );
    return "$header.$payload.$sig";
}

function rfp_b64u( $d ) {
    return rtrim( strtr( base64_encode($d), '+/', '-_' ), '=' );
}

/**
 * Make a REST call to the RF External API.
 * Returns decoded JSON body or WP_Error.
 */
function rfp_rest( $method, $path, $body_arr = null ) {
    $jwt = rfp_build_jwt(
        sanitize_text_field( rfp_get('access_token') ),
        sanitize_text_field( rfp_get('secret_key') )
    );

    $args = array(
        'method'    => strtoupper($method),
        'headers'   => array(
            'Authorization' => 'Bearer ' . $jwt,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ),
        'timeout'   => 15,
        'sslverify' => true,
    );

    if ( $body_arr !== null ) {
        $args['body'] = wp_json_encode( $body_arr );
    }

    $response = wp_remote_request( RFP_API_BASE . $path, $args );

    if ( is_wp_error($response) ) return $response;

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ( $code === 401 ) {
        return new WP_Error('auth', 'Authentication failed. Check Access Token and Secret Key.' );
    }

    return array( 'code' => $code, 'data' => $data, 'body' => $body );
}

/* ================================================================
 * ADMIN MENU & SETTINGS
 * ============================================================== */

add_action( 'admin_menu', function() {
    add_options_page( 'Remote Falcon Player', 'Remote Falcon Player', 'manage_options', 'remote-falcon-player', 'rfp_settings_page' );
});

add_action( 'admin_init', function() {
    register_setting( 'rfp_group', 'rfp_settings', 'rfp_sanitize' );

    add_settings_section( 'rfp_conn', '🔗 Remote Falcon Connection', function() {
        echo '<p>Enter the credentials from your RF API email (RF Control Panel → Profile → Account → Request API Access).</p>';
    }, 'rfp' );
    add_settings_field( 'show_subdomain', 'Show Subdomain',  'rfp_f_subdomain', 'rfp', 'rfp_conn' );
    add_settings_field( 'access_token',  'API Access Token', 'rfp_f_token',     'rfp', 'rfp_conn' );
    add_settings_field( 'secret_key',    'API Secret Key',   'rfp_f_secret',    'rfp', 'rfp_conn' );

    add_settings_section( 'rfp_disp', '🎨 Display Settings', function() {
        echo '<p>Customize how the widget appears on your site.</p>';
    }, 'rfp' );
    add_settings_field( 'caption',          'Widget Caption',           'rfp_f_caption',     'rfp', 'rfp_disp' );
    add_settings_field( 'show_mode',        'Viewer Mode',              'rfp_f_mode',        'rfp', 'rfp_disp' );
    add_settings_field( 'poll_interval',    'Refresh Interval (sec)',   'rfp_f_poll',        'rfp', 'rfp_disp' );
    add_settings_field( 'off_air_image',    'Off-Air Image',            'rfp_f_offair',      'rfp', 'rfp_disp' );
    add_settings_field( 'accent_color',     'Accent Color',             'rfp_f_accent',      'rfp', 'rfp_disp' );
    add_settings_field( 'bg_color',         'Background Color',         'rfp_f_bg',          'rfp', 'rfp_disp' );
    add_settings_field( 'text_color',       'Text Color',               'rfp_f_text',        'rfp', 'rfp_disp' );
    add_settings_field( 'show_now_playing', 'Show Now Playing',         'rfp_f_show_now',    'rfp', 'rfp_disp' );
    add_settings_field( 'show_next_up',     'Show Next Up',             'rfp_f_show_next',   'rfp', 'rfp_disp' );
    add_settings_field( 'show_vote_list',   'Show Vote/Request List',   'rfp_f_show_list',   'rfp', 'rfp_disp' );

    add_settings_section( 'rfp_sc', '📋 Shortcode', function() {
        echo '<div style="background:#f0f0f0;border-left:4px solid #e74c3c;padding:12px 16px;font-family:monospace;font-size:13px;">';
        echo '<code>[remote_falcon_player]</code><br><br>';
        echo '<code>[remote_falcon_player mode="voting"]</code>&nbsp;&nbsp;<code>[remote_falcon_player mode="jukebox"]</code><br><br>';
        echo '<code>[remote_falcon_player accent="#ff6600" bg="#000000" text="#ffffff"]</code>';
        echo '</div>';
    }, 'rfp' );
});

function rfp_sanitize( $in ) {
    $c = array();
    $c['show_subdomain']    = sanitize_text_field( $in['show_subdomain']   ?? '' );
    $c['access_token']      = sanitize_text_field( $in['access_token']     ?? '' );
    $c['secret_key']        = sanitize_text_field( $in['secret_key']       ?? '' );
    $c['caption']           = sanitize_text_field( $in['caption']          ?? '' );
    $c['show_mode']         = in_array( $in['show_mode'] ?? '', array('voting','jukebox') ) ? $in['show_mode'] : 'voting';
    $c['poll_interval']     = max( 5, min(120, absint($in['poll_interval'] ?? 10)) );
    $c['off_air_image_id']  = absint( $in['off_air_image_id']  ?? 0 );
    $c['off_air_image_url'] = esc_url_raw( $in['off_air_image_url'] ?? '' );
    $c['accent_color']      = sanitize_hex_color( $in['accent_color'] ?? '#e74c3c' );
    $c['bg_color']          = sanitize_hex_color( $in['bg_color']     ?? '#1a1a2e' );
    $c['text_color']        = sanitize_hex_color( $in['text_color']   ?? '#ffffff' );
    $c['show_now_playing']  = !empty($in['show_now_playing']) ? 1 : 0;
    $c['show_next_up']      = !empty($in['show_next_up'])     ? 1 : 0;
    $c['show_vote_list']    = !empty($in['show_vote_list'])   ? 1 : 0;
    if ( $c['off_air_image_id'] ) {
        $u = wp_get_attachment_url($c['off_air_image_id']);
        if ($u) $c['off_air_image_url'] = $u;
    }
    return $c;
}

/* Field callbacks */
function rfp_f_caption() {
    $v = esc_attr(rfp_get('caption',''));
    echo '<input type="text" name="rfp_settings[caption]" value="'.$v.'" class="large-text" placeholder="e.g. 🎄 Christmas on the Hill — Request a Song!" />';
    echo '<p class="description">Shown above the widget as <code>&lt;div class="caption"&gt;&lt;h3&gt;…&lt;/h3&gt;&lt;/div&gt;</code>. Leave blank to hide.</p>';
}
function rfp_f_subdomain() {
    $v = esc_attr(rfp_get('show_subdomain'));
    echo '<input type="text" name="rfp_settings[show_subdomain]" value="'.$v.'" class="regular-text" placeholder="myshowname" />';
    echo '<p class="description">Subdomain prefix — e.g. <code>myshow</code> from <code>myshow.remotefalcon.com</code></p>';
}
function rfp_f_token() {
    $v = esc_attr(rfp_get('access_token'));
    echo '<input type="text" name="rfp_settings[access_token]" value="'.$v.'" class="large-text" placeholder="From RF API email" autocomplete="off" />';
    echo '<p class="description">The <strong>Access Token</strong> from your RF API email. Placed in the JWT payload as <code>{"accessToken":"…"}</code>.</p>';
}
function rfp_f_secret() {
    $v = esc_attr(rfp_get('secret_key'));
    echo '<input type="password" name="rfp_settings[secret_key]" value="'.$v.'" class="large-text" placeholder="From RF API email" autocomplete="new-password" />';
    echo '<p class="description">The <strong>Secret Key</strong> from your RF API email. Signs the JWT (HS256). Never sent to the browser.</p>';
}
function rfp_f_mode() {
    $v = rfp_get('show_mode','voting');
    echo '<select name="rfp_settings[show_mode]"><option value="voting"'.selected($v,'voting',false).'>Voting</option><option value="jukebox"'.selected($v,'jukebox',false).'>Jukebox</option></select>';
    echo '<p class="description">Overrides the mode from the API if needed. The API returns <code>preferences.viewerControlMode</code> automatically.</p>';
}
function rfp_f_poll() {
    $v = esc_attr(rfp_get('poll_interval',10));
    echo '<input type="number" name="rfp_settings[poll_interval]" value="'.$v.'" min="5" max="120" style="width:80px;" /> seconds';
}
function rfp_f_offair() {
    $id  = (int)rfp_get('off_air_image_id',0);
    $url = rfp_get('off_air_image_url','');
    ?>
    <input type="hidden" id="rfp_img_id"  name="rfp_settings[off_air_image_id]"  value="<?php echo esc_attr($id); ?>" />
    <input type="hidden" id="rfp_img_url" name="rfp_settings[off_air_image_url]" value="<?php echo esc_attr($url); ?>" />
    <div id="rfp_img_preview" <?php echo $url?'':'style="display:none;"'; ?>>
        <img id="rfp_img_thumb" src="<?php echo esc_url($url); ?>"
             style="max-width:240px;max-height:160px;display:block;margin-bottom:10px;border:1px solid #ddd;border-radius:6px;object-fit:contain;" />
    </div>
    <button type="button" class="button button-secondary" id="rfp_img_pick">📁 Select / Upload Image</button>
    &nbsp;
    <button type="button" class="button" id="rfp_img_clear" <?php echo $url?'':'style="display:none;"'; ?>>✕ Remove</button>
    <p class="description">Shown when <code>preferences.viewerControlEnabled</code> is <code>false</code>. Leave empty to use the built-in graphic.</p>
    <?php
}
function rfp_f_accent()    { $v=esc_attr(rfp_get('accent_color','#e74c3c')); echo '<input type="color" name="rfp_settings[accent_color]" value="'.$v.'" /> <code>'.$v.'</code>'; }
function rfp_f_bg()        { $v=esc_attr(rfp_get('bg_color','#1a1a2e'));     echo '<input type="color" name="rfp_settings[bg_color]"     value="'.$v.'" /> <code>'.$v.'</code>'; }
function rfp_f_text()      { $v=esc_attr(rfp_get('text_color','#ffffff'));   echo '<input type="color" name="rfp_settings[text_color]"   value="'.$v.'" /> <code>'.$v.'</code>'; }
function rfp_f_show_now()  { $v=rfp_get('show_now_playing',1); echo '<label><input type="checkbox" name="rfp_settings[show_now_playing]" value="1"'.checked($v,1,false).' /> Show currently playing song</label>'; }
function rfp_f_show_next() { $v=rfp_get('show_next_up',1);     echo '<label><input type="checkbox" name="rfp_settings[show_next_up]"     value="1"'.checked($v,1,false).' /> Show next song up</label>'; }
function rfp_f_show_list() { $v=rfp_get('show_vote_list',1);   echo '<label><input type="checkbox" name="rfp_settings[show_vote_list]"   value="1"'.checked($v,1,false).' /> Show song list for voting / requesting</label>'; }

/* Admin enqueue */
add_action( 'admin_enqueue_scripts', function($hook) {
    if ($hook !== 'settings_page_remote-falcon-player') return;
    wp_enqueue_media();
    wp_add_inline_script('jquery-core', <<<'JS'
jQuery(function($){
    var frame=null;
    $(document).on('click','#rfp_img_pick',function(e){
        e.preventDefault();
        if(frame){frame.open();return;}
        frame=wp.media({title:'Select Off-Air Image',button:{text:'Use This Image'},multiple:false,library:{type:'image'}});
        frame.on('select',function(){
            var a=frame.state().get('selection').first().toJSON();
            var p=(a.sizes&&a.sizes.medium)?a.sizes.medium.url:a.url;
            $('#rfp_img_id').val(a.id);$('#rfp_img_url').val(a.url);
            $('#rfp_img_thumb').attr('src',p);$('#rfp_img_preview').show();$('#rfp_img_clear').show();
        });
        frame.open();
    });
    $(document).on('click','#rfp_img_clear',function(e){
        e.preventDefault();
        $('#rfp_img_id,#rfp_img_url').val('');
        $('#rfp_img_thumb').attr('src','');$('#rfp_img_preview').hide();$(this).hide();
    });
});
JS);
});

/* Settings page */
function rfp_settings_page() {
    if (!current_user_can('manage_options')) return;
    $sub  = rfp_get('show_subdomain');
    $tok  = rfp_get('access_token');
    $sec  = rfp_get('secret_key');
    $has  = $sub && $tok && $sec;
    $nonce = wp_create_nonce('rfp_nonce');

    // Decode the JWT payload for display
    $jwt_payload_preview = '';
    if ($tok) {
        $jwt_payload_preview = wp_json_encode( array( 'accessToken' => $tok ) );
    }
    ?>
    <div class="wrap">
        <h1>🎄 Remote Falcon Player Settings</h1>
        <?php settings_errors('rfp_settings'); ?>

        <?php if ($has): ?>
        <div class="notice notice-success inline"><p>✅ Credentials saved. Use <code>[remote_falcon_player]</code> on any page or post.</p></div>
        <?php else: ?>
        <div class="notice notice-warning inline"><p>⚠️ Enter your Show Subdomain, API Access Token, and Secret Key below.</p></div>
        <?php endif; ?>

        <?php if ($has): ?>
        <!-- API INFO PANEL -->
        <div style="margin:16px 0;padding:16px 18px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:4px;font-size:13px;">
            <strong>🔌 API Connection Details</strong>
            <table style="margin-top:10px;border-collapse:collapse;width:100%;">
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;white-space:nowrap;width:180px;">API Base URL</td>
                    <td><code><?php echo esc_html(RFP_API_BASE); ?></code></td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">Show Details</td>
                    <td><code>GET <?php echo esc_html(RFP_API_BASE); ?>/showDetails</code></td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">Vote for Sequence</td>
                    <td><code>POST <?php echo esc_html(RFP_API_BASE); ?>/voteForSequence</code></td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">Add to Queue</td>
                    <td><code>POST <?php echo esc_html(RFP_API_BASE); ?>/addSequenceToQueue</code></td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">Auth Header</td>
                    <td><code>Authorization: Bearer &lt;JWT&gt;</code></td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">JWT Algorithm</td>
                    <td><code>HS256</code> — signed with your Secret Key</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">JWT Payload</td>
                    <td><code><?php echo esc_html($jwt_payload_preview); ?></code></td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">Off-Air Detection</td>
                    <td><code>preferences.viewerControlEnabled === false</code></td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">Mode Detection</td>
                    <td><code>preferences.viewerControlMode</code> (<code>"jukebox"</code> or <code>"voting"</code>)</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">Now Playing</td>
                    <td><code>playingNow</code> (string)</td>
                </tr>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">Up Next</td>
                    <td><code>playingNext</code> (string)</td>
                </tr>
                <tr>
                    <td style="padding:6px 16px 6px 0;color:#555;font-weight:600;">Vote Counts</td>
                    <td><code>votes[]</code> array — each item has <code>sequence.name</code> and <code>votes</code> count</td>
                </tr>
            </table>
        </div>

        <!-- DIAGNOSTICS PANEL -->
        <div style="margin:16px 0;padding:16px 18px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">
            <strong>🔧 Connection Test</strong><br><br>
            <button type="button" id="rfp-test" class="button button-primary">▶ Test Connection (GET /showDetails)</button>
            <span id="rfp-spin" style="display:none;margin-left:10px;">⏳ Connecting…</span>
            <div id="rfp-out" style="margin-top:12px;font-family:monospace;font-size:12px;white-space:pre-wrap;background:#f6f7f7;padding:10px;border-radius:3px;display:none;max-height:500px;overflow:auto;"></div>
        </div>
        <script>
        jQuery(function($){
            var nonce='<?php echo $nonce; ?>';
            $('#rfp-test').click(function(){
                $('#rfp-spin').show();$('#rfp-out').hide().text('');
                $.ajax({url:ajaxurl,type:'POST',dataType:'json',timeout:20000,
                    data:{action:'rfp_ajax',nonce:nonce,rf_action:'status'}})
                 .done(function(r){$('#rfp-out').text(JSON.stringify(r,null,2)).show();})
                 .fail(function(x){$('#rfp-out').text('HTTP '+x.status+'\n'+x.responseText).show();})
                 .always(function(){$('#rfp-spin').hide();});
            });
        });
        </script>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('rfp_group'); do_settings_sections('rfp'); submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

/* ================================================================
 * AJAX PROXY
 * All API calls are server-side — keys never reach the browser.
 * ============================================================== */

add_action('wp_ajax_rfp_ajax',        'rfp_ajax');
add_action('wp_ajax_nopriv_rfp_ajax', 'rfp_ajax');

function rfp_ajax() {
    if (!check_ajax_referer('rfp_nonce','nonce',false)) {
        wp_send_json_error(array('message'=>'Security check failed.'),403);
    }

    $tok    = sanitize_text_field(rfp_get('access_token'));
    $sec    = sanitize_text_field(rfp_get('secret_key'));
    $action = sanitize_text_field($_POST['rf_action'] ?? 'status');

    if (!$tok || !$sec) {
        wp_send_json_error(array('message'=>'Plugin not configured. Check Settings.'),400);
        return;
    }

    switch ($action) {

        /* ---- GET /showDetails ---- */
        case 'status':
            $r = rfp_rest('GET', '/showDetails');
            if (is_wp_error($r)) {
                wp_send_json_error(array('message'=>$r->get_error_message()),502);
                return;
            }
            if ($r['code'] === 401) {
                wp_send_json_error(array('message'=>'Authentication failed. Check Access Token and Secret Key.'),401);
                return;
            }
            if ($r['code'] !== 200) {
                wp_send_json_error(array('message'=>'API error HTTP '.$r['code'].': '.$r['body']),$r['code']);
                return;
            }
            wp_send_json_success($r['data']);
            return;

        /* ---- POST /voteForSequence ---- */
        case 'vote':
            $seq = sanitize_text_field($_POST['sequence_name'] ?? '');
            if (!$seq) { wp_send_json_error(array('message'=>'No sequence name.'),400); return; }

            $body = array('sequence' => $seq);
            // Pass lat/lng if provided by browser
            if (!empty($_POST['lat'])) $body['viewerLatitude']  = (float)$_POST['lat'];
            if (!empty($_POST['lng'])) $body['viewerLongitude'] = (float)$_POST['lng'];

            $r = rfp_rest('POST', '/voteForSequence', $body);
            if (is_wp_error($r)) { wp_send_json_error(array('message'=>$r->get_error_message()),502); return; }

            if ($r['code'] === 200) {
                wp_send_json_success(array('ok'=>true,'message'=>''));
            } elseif ($r['code'] === 202) {
                // 202 = accepted but with a reason code e.g. ALREADY_VOTED
                $msg = $r['data']['message'] ?? 'ALREADY_VOTED';
                wp_send_json_success(array('ok'=>false,'message'=>$msg));
            } else {
                wp_send_json_error(array('message'=>'Vote error HTTP '.$r['code']),500);
            }
            return;

        /* ---- POST /addSequenceToQueue ---- */
        case 'request':
            $seq = sanitize_text_field($_POST['sequence_name'] ?? '');
            if (!$seq) { wp_send_json_error(array('message'=>'No sequence name.'),400); return; }

            $body = array('sequence' => $seq);
            if (!empty($_POST['lat'])) $body['viewerLatitude']  = (float)$_POST['lat'];
            if (!empty($_POST['lng'])) $body['viewerLongitude'] = (float)$_POST['lng'];

            $r = rfp_rest('POST', '/addSequenceToQueue', $body);
            if (is_wp_error($r)) { wp_send_json_error(array('message'=>$r->get_error_message()),502); return; }

            if ($r['code'] === 200) {
                wp_send_json_success(array('ok'=>true,'message'=>''));
            } elseif ($r['code'] === 202) {
                $msg = $r['data']['message'] ?? 'QUEUE_FULL';
                wp_send_json_success(array('ok'=>false,'message'=>$msg));
            } else {
                wp_send_json_error(array('message'=>'Request error HTTP '.$r['code']),500);
            }
            return;

        default:
            wp_send_json_error(array('message'=>'Unknown action.'),400);
    }
}

/* ================================================================
 * FRONT-END ASSETS
 * ============================================================== */

add_action('wp_enqueue_scripts','rfp_assets');
function rfp_assets() {
    wp_enqueue_style( 'rfp-style',  RFP_URL.'assets/rfp-style.css',  array(), RFP_VERSION);
    wp_enqueue_script('rfp-script', RFP_URL.'assets/rfp-script.js', array('jquery'), RFP_VERSION, true);
    wp_localize_script('rfp-script','rfpData',array(
        'ajaxurl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('rfp_nonce'),
        'show_mode' => rfp_get('show_mode','voting'),
    ));
}

/* ================================================================
 * SHORTCODE [remote_falcon_player]
 * ============================================================== */

add_shortcode('remote_falcon_player','rfp_shortcode');
function rfp_shortcode($atts) {
    $opts = get_option('rfp_settings',array());
    $atts = shortcode_atts(array(
        'mode'   => $opts['show_mode']    ?? 'voting',
        'accent' => $opts['accent_color'] ?? '#e74c3c',
        'bg'     => $opts['bg_color']     ?? '#1a1a2e',
        'text'   => $opts['text_color']   ?? '#ffffff',
    ),$atts,'remote_falcon_player');

    $caption     = rfp_get('caption','');
    $show_now    = !empty($opts['show_now_playing']) ? 1 : 0;
    $show_next   = !empty($opts['show_next_up'])     ? 1 : 0;
    $show_list   = !empty($opts['show_vote_list'])   ? 1 : 0;
    $poll        = intval($opts['poll_interval'] ?? 10);
    $off_air_img = esc_url($opts['off_air_image_url'] ?? '');

    ob_start();
    if ($caption): ?>
<div class="caption"><h3><?php echo esc_html($caption); ?></h3><span class="hat"></span></div>
<?php endif; ?>
<div class="rfp-widget"
     data-mode="<?php echo esc_attr($atts['mode']); ?>"
     data-poll="<?php echo esc_attr($poll); ?>"
     data-show-now="<?php echo $show_now; ?>"
     data-show-next="<?php echo $show_next; ?>"
     data-show-list="<?php echo $show_list; ?>"
     data-off-air-img="<?php echo $off_air_img; ?>"
     style="--rfp-accent:<?php echo esc_attr($atts['accent']); ?>;--rfp-bg:<?php echo esc_attr($atts['bg']); ?>;--rfp-text:<?php echo esc_attr($atts['text']); ?>;">

    <div class="rfp-loading"><div class="rfp-spinner"></div><p>Connecting to the show…</p></div>

    <div class="rfp-off-air" style="display:none;">
        <div class="rfp-off-air-content">
            <div class="rfp-off-air-img-wrap"><img class="rfp-off-air-img" src="" alt="Off Air" /></div>
            <h2 class="rfp-off-air-title">🎄 Show is Off Air</h2>
            <p class="rfp-off-air-sub">Check back soon for the show!</p>
        </div>
    </div>

    <div class="rfp-live" style="display:none;">
        <div class="rfp-now-playing-wrap">
            <div class="rfp-now-playing">
                <div class="rfp-label">🎵 Now Playing</div>
                <div class="rfp-song-name rfp-now-name">—</div>
                <div class="rfp-song-artist rfp-now-artist"></div>
            </div>
        </div>
        <div class="rfp-next-up-wrap">
            <div class="rfp-next-up">
                <div class="rfp-label">⏭ Up Next</div>
                <div class="rfp-song-name rfp-next-name">—</div>
                <div class="rfp-song-artist rfp-next-artist"></div>
            </div>
        </div>
        <div class="rfp-list-wrap">
            <button type="button" class="rfp-open-modal">
                🎵 <?php echo rfp_get('show_mode','voting') === 'voting' ? 'Vote for a Song' : 'Request a Song'; ?>
            </button>
        </div>
        <div class="rfp-status" style="display:none;"></div>
    </div>

    <!-- Modal -->
    <div class="rfp-modal-overlay" style="display:none;" aria-modal="true" role="dialog">
        <div class="rfp-modal">
            <div class="rfp-modal-header">
                <span class="rfp-modal-title">
                    <span class="rfp-modal-title-voting"  style="display:none;">🗳 Vote for a Song</span>
                    <span class="rfp-modal-title-jukebox" style="display:none;">🎶 Request a Song</span>
                </span>
                <button type="button" class="rfp-modal-close" aria-label="Close">✕</button>
            </div>
            <div class="rfp-modal-search">
                <input type="text" class="rfp-search-input" placeholder="Search songs…" />
            </div>
            <div class="rfp-modal-list"></div>
            <div class="rfp-modal-status" style="display:none;"></div>
        </div>
    </div>

    <div class="rfp-error" style="display:none;">
        <p class="rfp-error-msg">⚠️ Unable to connect to Remote Falcon.</p>
        <p class="rfp-error-detail" style="font-size:.8rem;opacity:.7;"></p>
    </div>
</div>
<?php
    return ob_get_clean();
}
