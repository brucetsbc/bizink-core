<?php
/**
 * Client site health: update status, automatic-update policy, "Update now" from the master, and uptime checks.
 *
 * Every site:
 *   - reports to the master (POST {master}/wp-json/bizink/v1/health/report) twice a day, after any update and when
 *     the master asks: WordPress, PHP, bizink-core, each plugin/theme with any waiting update, automatic update
 *     results, firm name, package. Reports are signed (HMAC-SHA256) with a key the site makes for itself and
 *     registers with the master on its first report (a different key for a known site is refused until it's
 *     reset on the master);
 *   - follows the master's automatic-update policy (returned with each report);
 *   - accepts "update" and "report" commands from the master (POST /wp-json/bizink/v1/health/command) only when
 *     signed with its key and less than 5 minutes old.
 * The master: Tools → Client site health (status, uptime, SSL, updates, Update now / Update all, policy), uptime checks of
 * every site every 5 minutes (all in parallel; SSL once a day), alerts (support ticket, else email) after two
 * failed checks in a row and on recovery. For dependable 5-minute checks, add a Cloudways cron job that opens
 * {master}/wp-cron.php every 5 minutes (WordPress cron otherwise only runs when someone visits the master).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BZ_ALERT_EMAIL' ) ) {
	define( 'BZ_ALERT_EMAIL', 'production@bizinkonline.com' ); // uptime alerts: raised as (and email fallback to)
}

/* ---------- Shared ---------- */

function bz_core_health_sign( $key, $ts, $body ) {
	return hash_hmac( 'sha256', $ts . '.' . $body, $key );
}

function bz_core_health_host() {
	return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}

/* ---------- Every site: report, policy, commands ---------- */

function bz_core_health_key() {
	$key = get_option( 'bz_health_key' );
	if ( ! $key ) {
		$key = wp_generate_password( 48, false );
		update_option( 'bz_health_key', $key, false );
	}
	return $key;
}

/* What this site reports. */
function bz_core_health_data() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	require_once ABSPATH . 'wp-admin/includes/update.php';
	wp_update_plugins();
	wp_update_themes();
	$up_p    = get_site_transient( 'update_plugins' );
	$up_t    = get_site_transient( 'update_themes' );
	$auto_p  = (array) get_site_option( 'auto_update_plugins', array() );
	$plugins = array();
	foreach ( get_plugins() as $file => $d ) {
		$new       = isset( $up_p->response[ $file ]->new_version ) ? $up_p->response[ $file ]->new_version : '';
		$plugins[] = array(
			'file'   => $file,
			'name'   => $d['Name'],
			'ver'    => $d['Version'],
			'new'    => $new,
			'active' => is_plugin_active( $file ),
			'auto'   => in_array( $file, $auto_p, true ),
		);
	}
	$themes = array();
	foreach ( wp_get_themes() as $slug => $t ) {
		$themes[] = array(
			'slug'   => $slug,
			'name'   => $t->get( 'Name' ),
			'ver'    => $t->get( 'Version' ),
			'new'    => isset( $up_t->response[ $slug ]['new_version'] ) ? $up_t->response[ $slug ]['new_version'] : '',
			'active' => get_stylesheet() === $slug || get_template() === $slug,
		);
	}
	$core     = get_core_updates();
	$core_new = '';
	foreach ( (array) $core as $c ) {
		if ( isset( $c->response ) && 'upgrade' === $c->response ) {
			$core_new = $c->current;
			break;
		}
	}
	return array(
		'site'     => bz_core_health_host(),
		'home'     => home_url( '/' ),
		'firm'     => function_exists( 'bz_core_get' ) ? bz_core_get( 'firm_name' ) : get_bloginfo( 'name' ),
		'package'  => function_exists( 'bz_core_package' ) ? bz_core_package() : '',
		'wp'       => get_bloginfo( 'version' ),
		'wp_new'   => $core_new,
		'php'      => PHP_VERSION,
		'core'     => BZ_CORE_VERSION,
		'plugins'  => $plugins,
		'themes'   => $themes,
		'auto_log' => (array) get_option( 'bz_health_auto_log', array() ),
		'live'     => '1' === (string) get_option( 'blog_public' ),
		'time'     => time(),
	);
}

/* Send a report; stores the policy the master answers with. */
function bz_core_health_report() {
	if ( function_exists( 'bz_core_is_master' ) && bz_core_is_master() ) {
		bz_core_health_store( bz_core_health_data() ); // the master records itself directly
		return true;
	}
	$body = wp_json_encode( array( 'data' => bz_core_health_data(), 'key' => bz_core_health_key() ) );
	$ts   = (string) time();
	$r    = bz_core_master_request(
		BZ_MASTER_URL . '/wp-json/bizink/v1/health/report',
		array(
			'method'  => 'POST',
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json', 'X-Bz-Time' => $ts, 'X-Bz-Sig' => bz_core_health_sign( bz_core_health_key(), $ts, $body ) ),
			'body'    => $body,
		)
	);
	if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
		update_option( 'bz_health_last_error', is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_body( $r ), false );
		return false;
	}
	$res = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( isset( $res['policy'] ) && is_array( $res['policy'] ) ) {
		update_option( 'bz_health_policy', $res['policy'], false );
	}
	delete_option( 'bz_health_last_error' );
	update_option( 'bz_health_reported', time(), false );
	return true;
}

add_action( 'bz_core_health_cron', 'bz_core_health_report' );
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'bz_core_health_cron' ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', 'bz_core_health_cron' );
		}
	}
);
// After any update: report soon (in the background, so the update screen isn't slowed).
add_action(
	'upgrader_process_complete',
	function () {
		if ( ! wp_next_scheduled( 'bz_core_health_cron_once' ) ) {
			wp_schedule_single_event( time() + 60, 'bz_core_health_cron_once' );
		}
	}
);
add_action( 'bz_core_health_cron_once', 'bz_core_health_report' );

// Automatic update results (kept for the report).
add_action(
	'automatic_updates_complete',
	function ( $results ) {
		$log = array();
		foreach ( (array) $results as $type => $items ) {
			foreach ( (array) $items as $it ) {
				$name  = isset( $it->name ) ? $it->name : ( isset( $it->item->slug ) ? $it->item->slug : $type );
				$log[] = array( 'type' => $type, 'name' => wp_strip_all_tags( (string) $name ), 'ok' => ! is_wp_error( $it->result ) && false !== $it->result, 'time' => time() );
			}
		}
		$old = (array) get_option( 'bz_health_auto_log', array() );
		update_option( 'bz_health_auto_log', array_slice( array_merge( $log, $old ), 0, 30 ), false );
		bz_core_health_report();
	}
);

/* The master's automatic-update policy: off | patch | all, exclude list, WordPress minor releases. */
function bz_core_health_policy() {
	$p = bz_core_is_master() ? bz_core_health_master_policy() : (array) get_option( 'bz_health_policy', array() );
	return wp_parse_args( $p, array( 'plugins' => 'off', 'exclude' => array(), 'core_minor' => true, 'themes' => 'off' ) );
}

function bz_core_health_patch_only( $current, $new ) {
	$a = explode( '.', (string) $current );
	$b = explode( '.', (string) $new );
	return isset( $a[0], $a[1], $b[0], $b[1] ) && $a[0] === $b[0] && $a[1] === $b[1];
}

add_filter(
	'auto_update_plugin',
	function ( $update, $item ) {
		$p = bz_core_health_policy();
		if ( 'off' === $p['plugins'] || empty( $item->plugin ) ) {
			return $update; // the site's own per-plugin choices
		}
		$slug = dirname( $item->plugin );
		if ( in_array( $slug, (array) $p['exclude'], true ) || in_array( $item->plugin, (array) $p['exclude'], true ) ) {
			return false;
		}
		if ( 'all' === $p['plugins'] ) {
			return true;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		$cur = isset( $all[ $item->plugin ]['Version'] ) ? $all[ $item->plugin ]['Version'] : '';
		return bz_core_health_patch_only( $cur, isset( $item->new_version ) ? $item->new_version : '' );
	},
	20,
	2
);
add_filter(
	'auto_update_theme',
	function ( $update, $item ) {
		$p = bz_core_health_policy();
		return 'all' === $p['themes'] ? true : $update;
	},
	20,
	2
);
add_filter(
	'allow_minor_auto_core_updates',
	function ( $allow ) {
		$p = bz_core_health_policy();
		return ! empty( $p['core_minor'] ) ? true : $allow;
	}
);

/* Signed commands from the master: "report" and "update". */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bizink/v1',
			'/health/command',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // checked by signature below
				'callback'            => 'bz_core_health_command',
			)
		);
	}
);

function bz_core_health_command( WP_REST_Request $r ) {
	$ts   = (string) $r->get_header( 'x_bz_time' );
	$sig  = (string) $r->get_header( 'x_bz_sig' );
	$body = (string) $r->get_body();
	$key  = get_option( 'bz_health_key' );
	if ( ! $key || ! $ts || abs( time() - (int) $ts ) > 300 || ! hash_equals( bz_core_health_sign( $key, $ts, $body ), $sig ) ) {
		return new WP_Error( 'bz_health_sig', 'Not signed by the Bizink master.', array( 'status' => 403 ) );
	}
	$p = json_decode( $body, true );
	if ( isset( $p['action'] ) && 'update' === $p['action'] ) {
		@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$out = bz_core_health_run_updates( isset( $p['items'] ) ? (array) $p['items'] : array() );
		bz_core_health_report();
		return array( 'ok' => true, 'results' => $out );
	}
	bz_core_health_report();
	return array( 'ok' => true );
}

/* Update plugins (all with an update, or the listed plugin files) and themes; returns per-item results. */
function bz_core_health_run_updates( $items ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	wp_update_plugins();
	wp_update_themes();
	$up_p   = get_site_transient( 'update_plugins' );
	$up_t   = get_site_transient( 'update_themes' );
	$p_list = array_keys( isset( $up_p->response ) ? (array) $up_p->response : array() );
	if ( $items ) {
		$p_list = array_values( array_intersect( $p_list, $items ) );
	}
	$out = array();
	if ( $p_list ) {
		$active = array_filter( $p_list, 'is_plugin_active' );
		$res    = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->bulk_upgrade( $p_list );
		foreach ( (array) $res as $file => $r ) {
			$out[] = array( 'item' => $file, 'ok' => ! is_wp_error( $r ) && false !== $r && null !== $r );
		}
		// bulk_upgrade keeps plugins active; make sure, as a failed update can leave one off.
		foreach ( $active as $file ) {
			if ( ! is_plugin_active( $file ) ) {
				activate_plugin( $file, '', false, true );
			}
		}
	}
	$t_list = array_keys( isset( $up_t->response ) ? (array) $up_t->response : array() );
	if ( $t_list && ! $items ) {
		$res = ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->bulk_upgrade( $t_list );
		foreach ( (array) $res as $slug => $r ) {
			$out[] = array( 'item' => 'theme:' . $slug, 'ok' => ! is_wp_error( $r ) && false !== $r && null !== $r );
		}
	}
	return $out;
}

/* ---------- Master: receive reports, uptime, page ---------- */

function bz_core_health_master_policy() {
	return wp_parse_args( (array) get_option( 'bz_health_master_policy', array() ), array( 'plugins' => 'off', 'exclude' => array( 'elementor', 'elementor-pro' ), 'core_minor' => true, 'themes' => 'off' ) );
}

function bz_core_health_index() {
	return (array) get_option( 'bz_health_index', array() );
}

function bz_core_health_get( $host ) {
	return (array) get_option( 'bz_health_site_' . md5( $host ), array() );
}

function bz_core_health_put( $host, $row ) {
	update_option( 'bz_health_site_' . md5( $host ), $row, false );
	$idx = bz_core_health_index();
	if ( ! in_array( $host, $idx, true ) ) {
		$idx[] = $host;
		sort( $idx );
		update_option( 'bz_health_index', $idx, false );
	}
}

function bz_core_health_store( $data, $key = null ) {
	$host = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $data['site'] ) );
	$row  = bz_core_health_get( $host );
	if ( null !== $key ) {
		$row['key'] = $key;
	}
	$row['report']   = $data;
	$row['reported'] = time();
	bz_core_health_put( $host, $row );
}

add_action(
	'rest_api_init',
	function () {
		if ( ! bz_core_is_master() ) {
			return;
		}
		register_rest_route(
			'bizink/v1',
			'/health/report',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // checked by signature below
				'callback'            => function ( WP_REST_Request $r ) {
					$body = (string) $r->get_body();
					$p    = json_decode( $body, true );
					$data = isset( $p['data'] ) && is_array( $p['data'] ) ? $p['data'] : array();
					$key  = isset( $p['key'] ) ? (string) $p['key'] : '';
					$host = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', isset( $data['site'] ) ? (string) $data['site'] : '' ) );
					if ( ! $host || false === strpos( $host, '.' ) || strlen( $key ) < 32 || strlen( $body ) > 400000 ) {
						return new WP_Error( 'bz_health_bad', 'Bad report.', array( 'status' => 400 ) );
					}
					$ts  = (string) $r->get_header( 'x_bz_time' );
					$sig = (string) $r->get_header( 'x_bz_sig' );
					if ( ! $ts || abs( time() - (int) $ts ) > 300 || ! hash_equals( bz_core_health_sign( $key, $ts, $body ), $sig ) ) {
						return new WP_Error( 'bz_health_sig', 'Bad signature.', array( 'status' => 403 ) );
					}
					$known = bz_core_health_get( $host );
					if ( ! empty( $known['key'] ) && ! hash_equals( $known['key'], $key ) ) {
						return new WP_Error( 'bz_health_key', 'This site is registered with a different key. Reset it on the master (Tools → Client site health).', array( 'status' => 409 ) );
					}
					bz_core_health_store( $data, $key );
					return array( 'ok' => true, 'policy' => bz_core_health_master_policy() );
				},
			)
		);
	}
);

/* A signed command to a site. */
function bz_core_health_send( $host, $payload, $timeout = 60 ) {
	if ( bz_core_health_host() === $host ) { // the master itself: run here
		$out = 'update' === ( $payload['action'] ?? '' ) ? bz_core_health_run_updates( array() ) : array();
		bz_core_health_report();
		return array( 'ok' => true, 'results' => $out );
	}
	$row = bz_core_health_get( $host );
	if ( empty( $row['key'] ) ) {
		return new WP_Error( 'bz_health_nokey', 'This site has not reported yet.' );
	}
	$home = ! empty( $row['report']['home'] ) ? $row['report']['home'] : 'https://' . $host . '/';
	$body = wp_json_encode( $payload );
	$ts   = (string) time();
	$r    = wp_remote_post(
		trailingslashit( $home ) . 'wp-json/bizink/v1/health/command',
		array( 'timeout' => $timeout, 'headers' => array( 'Content-Type' => 'application/json', 'X-Bz-Time' => $ts, 'X-Bz-Sig' => bz_core_health_sign( $row['key'], $ts, $body ) ), 'body' => $body )
	);
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$res = json_decode( wp_remote_retrieve_body( $r ), true );
	return 200 === wp_remote_retrieve_response_code( $r ) ? $res : new WP_Error( 'bz_health_cmd', isset( $res['message'] ) ? $res['message'] : 'The site answered ' . wp_remote_retrieve_response_code( $r ) . '.' );
}

/* ---------- Uptime (master) ---------- */

add_filter(
	'cron_schedules',
	function ( $s ) {
		$s['bz_five_minutes'] = array( 'interval' => 300, 'display' => 'Every 5 minutes (Bizink uptime)' );
		return $s;
	}
);
add_action(
	'init',
	function () {
		if ( bz_core_is_master() && ! wp_next_scheduled( 'bz_core_uptime_cron' ) ) {
			wp_schedule_event( time() + 60, 'bz_five_minutes', 'bz_core_uptime_cron' );
		}
	}
);
add_action( 'bz_core_uptime_cron', 'bz_core_uptime_run' );

function bz_core_uptime_run() {
	if ( ! bz_core_is_master() ) {
		return;
	}
	$hosts = bz_core_health_index();
	if ( ! $hosts ) {
		return;
	}
	$reqs = array();
	foreach ( $hosts as $h ) {
		$row = bz_core_health_get( $h );
		if ( ! empty( $row['paused'] ) ) {
			continue;
		}
		$url         = ! empty( $row['report']['home'] ) ? $row['report']['home'] : 'https://' . $h . '/';
		$reqs[ $h ]  = array( 'url' => add_query_arg( 'bz_uptime', time(), $url ), 'type' => 'GET', 'headers' => array( 'User-Agent' => 'Bizink uptime check' ) );
	}
	$res = class_exists( '\WpOrg\Requests\Requests' )
		? \WpOrg\Requests\Requests::request_multiple( $reqs, array( 'timeout' => 20, 'connect_timeout' => 10, 'follow_redirects' => true, 'redirects' => 3 ) )
		: Requests::request_multiple( $reqs, array( 'timeout' => 20, 'connect_timeout' => 10 ) ); // older WordPress
	foreach ( $res as $h => $r ) {
		$row  = bz_core_health_get( $h );
		$up   = $row['uptime'] ?? array( 'status' => 'unknown', 'fails' => 0, 'checks' => array() );
		$ok   = false;
		$why  = '';
		if ( $r instanceof \Exception ) {
			$why = $r->getMessage();
		} else {
			$code = (int) $r->status_code;
			$firm = ! empty( $row['report']['firm'] ) ? html_entity_decode( $row['report']['firm'], ENT_QUOTES ) : '';
			if ( $code >= 400 ) {
				$why = 'HTTP ' . $code;
			} elseif ( $firm && false === stripos( html_entity_decode( (string) $r->body, ENT_QUOTES ), $firm ) && false === stripos( (string) $r->body, esc_html( $firm ) ) ) {
				$why = 'Page loaded but the firm name is missing (error page?)';
			} else {
				$ok = true;
			}
		}
		$up['checks'][] = array( time(), $ok ? 1 : 0 );
		$up['checks']   = array_values( array_filter( $up['checks'], function ( $c ) { return $c[0] > time() - DAY_IN_SECONDS; } ) );
		$up['last']     = time();
		$up['why']      = $why;
		if ( $ok ) {
			if ( 'down' === $up['status'] && ! empty( $up['alerted'] ) ) {
				bz_core_uptime_alert( $h, true, 'Back up after ' . human_time_diff( $up['since'] ) . '.' );
			}
			if ( 'up' !== $up['status'] ) {
				$up['since'] = time();
			}
			$up['status']  = 'up';
			$up['fails']   = 0;
			$up['alerted'] = false;
		} else {
			$up['fails'] = (int) $up['fails'] + 1;
			if ( 'down' !== $up['status'] && $up['fails'] >= 2 ) {
				$up['status'] = 'down';
				$up['since']  = time();
			}
			if ( 'down' === $up['status'] && empty( $up['alerted'] ) ) {
				bz_core_uptime_alert( $h, false, $why );
				$up['alerted'] = true;
			}
		}
		$row['uptime'] = $up;
		bz_core_health_put( $h, $row );
	}
	// SSL expiry, once a day.
	if ( ! get_transient( 'bz_ssl_checked' ) ) {
		set_transient( 'bz_ssl_checked', 1, DAY_IN_SECONDS );
		foreach ( array_keys( $reqs ) as $h ) {
			$days = bz_core_ssl_days( $h );
			$row  = bz_core_health_get( $h );
			$row['ssl_days'] = $days;
			bz_core_health_put( $h, $row );
		}
	}
}

function bz_core_ssl_days( $host ) {
	if ( ! function_exists( 'openssl_x509_parse' ) ) {
		return null;
	}
	$ctx = stream_context_create( array( 'ssl' => array( 'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host ) ) );
	$c   = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $c ) {
		return null;
	}
	$params = stream_context_get_params( $c );
	fclose( $c ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$cert = isset( $params['options']['ssl']['peer_certificate'] ) ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ) : null;
	return $cert && isset( $cert['validTo_time_t'] ) ? (int) floor( ( $cert['validTo_time_t'] - time() ) / DAY_IN_SECONDS ) : null;
}

/* Down / back up: a support ticket (as BZ_ALERT_EMAIL), else an email. */
function bz_core_uptime_alert( $host, $recovered, $detail ) {
	$subject = ( $recovered ? 'Site back up: ' : 'Site down: ' ) . $host;
	$body    = $subject . "\n\n" . $detail . "\n\nTools → Client site health on the master: " . admin_url( 'tools.php?page=bz-site-health' );
	$ok      = false;
	if ( defined( 'BZ_SUPPORT_ENDPOINT' ) && BZ_SUPPORT_ENDPOINT ) {
		$r  = wp_remote_post(
			BZ_SUPPORT_ENDPOINT,
			array(
				'timeout' => 20,
				'body'    => array( 'contactName' => 'Bizink uptime', 'contactEmail' => BZ_ALERT_EMAIL, 'subject' => $subject, 'body' => $body, 'site_domain' => $host, 'firm' => '', 'site_url' => 'https://' . $host . '/', 'source' => 'uptime', 'wp_version' => get_bloginfo( 'version' ), 'bizink_core_version' => BZ_CORE_VERSION, 'website' => '' ),
			)
		);
		$ok = ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 300;
	}
	if ( ! $ok ) {
		wp_mail( BZ_ALERT_EMAIL, $subject, $body );
	}
}

/* ---------- Master: Tools → Client site health ---------- */

add_action(
	'admin_menu',
	function () {
		if ( bz_core_is_master() ) {
			add_management_page( 'Client site health', 'Client site health', 'manage_options', 'bz-site-health', 'bz_core_render_site_health' );
		}
	}
);

add_action(
	'admin_post_bz_health_policy',
	function () {
		check_admin_referer( 'bz_health_policy' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$plugins = isset( $_POST['plugins'] ) && in_array( $_POST['plugins'], array( 'off', 'patch', 'all' ), true ) ? sanitize_key( $_POST['plugins'] ) : 'off';
		$exclude = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/[\s,]+/', (string) wp_unslash( isset( $_POST['exclude'] ) ? $_POST['exclude'] : '' ) ) ) ) );
		update_option( 'bz_health_master_policy', array( 'plugins' => $plugins, 'exclude' => $exclude, 'core_minor' => ! empty( $_POST['core_minor'] ), 'themes' => ! empty( $_POST['themes'] ) ? 'all' : 'off' ), false );
		wp_safe_redirect( add_query_arg( 'bz_msg', rawurlencode( 'Policy saved. Sites follow it from their next report (within 12 hours).' ), admin_url( 'tools.php?page=bz-site-health' ) ) );
		exit;
	}
);

add_action(
	'admin_post_bz_health_reset',
	function () {
		$host = isset( $_GET['host'] ) ? sanitize_text_field( wp_unslash( $_GET['host'] ) ) : '';
		check_admin_referer( 'bz_health_reset_' . $host );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$row = bz_core_health_get( $host );
		unset( $row['key'] );
		bz_core_health_put( $host, $row );
		wp_safe_redirect( add_query_arg( 'bz_msg', rawurlencode( 'Key reset for ' . $host . ': its next report registers a new one.' ), admin_url( 'tools.php?page=bz-site-health' ) ) );
		exit;
	}
);

add_action(
	'admin_post_bz_health_remove',
	function () {
		$host = isset( $_GET['host'] ) ? sanitize_text_field( wp_unslash( $_GET['host'] ) ) : '';
		check_admin_referer( 'bz_health_remove_' . $host );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		delete_option( 'bz_health_site_' . md5( $host ) );
		update_option( 'bz_health_index', array_values( array_diff( bz_core_health_index(), array( $host ) ) ), false );
		wp_safe_redirect( add_query_arg( 'bz_msg', rawurlencode( $host . ' removed (it comes back if the site reports again).' ), admin_url( 'tools.php?page=bz-site-health' ) ) );
		exit;
	}
);

// Update now / check now, one site per request (the page's script calls these in turn for "Update all").
add_action(
	'wp_ajax_bz_health_action',
	function () {
		check_ajax_referer( 'bz_health_action' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Not allowed.' );
		}
		$host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
		$do   = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		if ( 'uptime' === $do ) {
			bz_core_uptime_run();
			wp_send_json_success( 'Checked.' );
		}
		$res = bz_core_health_send( $host, array( 'action' => 'update' === $do ? 'update' : 'report' ), 'update' === $do ? 300 : 30 );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( $res->get_error_message() );
		}
		$failed = array_filter( isset( $res['results'] ) ? (array) $res['results'] : array(), function ( $x ) { return empty( $x['ok'] ); } );
		wp_send_json_success( 'update' === $do ? ( count( (array) ( $res['results'] ?? array() ) ) . ' updated' . ( $failed ? ', ' . count( $failed ) . ' failed' : '' ) ) : 'Report received.' );
	}
);

function bz_core_render_site_health() {
	$msg    = isset( $_GET['bz_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bz_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$policy = bz_core_health_master_policy();
	$latest = function_exists( 'bz_core_latest_release' ) ? bz_core_latest_release() : array();
	$rows   = array();
	foreach ( bz_core_health_index() as $h ) {
		$rows[ $h ] = bz_core_health_get( $h );
	}
	$down = $pending_sites = 0;
	foreach ( $rows as $r ) {
		$down += ( isset( $r['uptime']['status'] ) && 'down' === $r['uptime']['status'] ) ? 1 : 0;
		$pending_sites += bz_core_health_pending( $r ) ? 1 : 0;
	}
	?>
	<div class="wrap">
		<h1>Client site health</h1>
		<?php if ( $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
		<p><strong><?php echo count( $rows ); ?></strong> sites · <strong style="color:<?php echo $down ? '#b32d2e' : '#00a32a'; ?>"><?php echo (int) $down; ?> down</strong> · <strong><?php echo (int) $pending_sites; ?></strong> with updates waiting.
			<button type="button" class="button" id="bzh-uptime">Check uptime now</button>
			<button type="button" class="button button-primary" id="bzh-all"<?php disabled( ! $pending_sites ); ?>>Update all sites with updates waiting</button>
			<span id="bzh-progress" style="margin-left:8px"></span></p>
		<table class="widefat striped" style="max-width:1400px">
			<thead><tr><th>Site</th><th>Status</th><th>Uptime 24h</th><th>SSL</th><th>bizink-core</th><th>Package</th><th>Updates waiting</th><th>Last report</th><th></th></tr></thead>
			<tbody>
			<?php
			if ( ! $rows ) {
				echo '<tr><td colspan="9">No site has reported yet. Sites report twice a day once they run bizink-core 0.29.0 or later.</td></tr>';
			}
			foreach ( $rows as $h => $r ) :
				$rep     = isset( $r['report'] ) ? $r['report'] : array();
				$up      = isset( $r['uptime'] ) ? $r['uptime'] : array();
				$checks  = isset( $up['checks'] ) ? (array) $up['checks'] : array();
				$pct     = $checks ? round( 100 * array_sum( array_column( $checks, 1 ) ) / count( $checks ), 1 ) : null;
				$pending = bz_core_health_pending( $r );
				$status  = isset( $up['status'] ) ? $up['status'] : 'unknown';
				$color   = array( 'up' => '#00a32a', 'down' => '#b32d2e' )[ $status ] ?? '#646970';
				$ssl     = isset( $r['ssl_days'] ) ? $r['ssl_days'] : null;
				$behind  = ! empty( $latest['version'] ) && ! empty( $rep['core'] ) && version_compare( $rep['core'], $latest['version'], '<' );
				?>
				<tr data-host="<?php echo esc_attr( $h ); ?>">
					<td><a href="<?php echo esc_url( isset( $rep['home'] ) ? $rep['home'] : 'https://' . $h . '/' ); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html( $h ); ?></strong></a><br><span class="description"><?php echo esc_html( isset( $rep['firm'] ) ? $rep['firm'] : '' ); ?><?php echo empty( $rep['live'] ) ? ' · staging' : ''; ?></span></td>
					<td><strong style="color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></strong><?php echo ! empty( $up['since'] ) ? '<br><span class="description">since ' . esc_html( human_time_diff( $up['since'] ) ) . '</span>' : ''; ?><?php echo ! empty( $up['why'] ) ? '<br><span class="description" style="color:#b32d2e">' . esc_html( $up['why'] ) . '</span>' : ''; ?></td>
					<td><?php echo null === $pct ? '—' : esc_html( $pct . '%' ); ?></td>
					<td<?php echo null !== $ssl && $ssl < 14 ? ' style="color:#b32d2e;font-weight:600"' : ''; ?>><?php echo null === $ssl ? '—' : esc_html( $ssl . ' days' ); ?></td>
					<td<?php echo $behind ? ' style="color:#b45309;font-weight:600"' : ''; ?>><?php echo esc_html( isset( $rep['core'] ) ? $rep['core'] : '' ); ?></td>
					<td><?php echo esc_html( ! empty( $rep['package'] ) ? ucfirst( $rep['package'] ) : '—' ); ?></td>
					<td><?php
					if ( $pending ) {
						echo '<details><summary style="cursor:pointer;color:#b45309;font-weight:600">' . count( $pending ) . ' waiting</summary><ul style="margin:6px 0 0 16px;list-style:disc">';
						foreach ( $pending as $p ) {
							echo '<li>' . esc_html( $p ) . '</li>';
						}
						echo '</ul></details>';
					} else {
						echo isset( $rep['plugins'] ) ? '<span style="color:#00a32a">Up to date</span>' : '—';
					}
					?></td>
					<td><?php echo ! empty( $r['reported'] ) ? esc_html( human_time_diff( $r['reported'] ) . ' ago' ) : '—'; ?></td>
					<td style="white-space:nowrap">
						<?php if ( $pending ) : ?><button type="button" class="button button-small bzh-do" data-do="update">Update now</button><?php endif; ?>
						<button type="button" class="button button-small bzh-do" data-do="report">Refresh</button>
						<span class="bzh-res"></span>
						<br><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bz_health_reset&host=' . rawurlencode( $h ) ), 'bz_health_reset_' . $h ) ); ?>" class="description">Reset key</a> ·
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bz_health_remove&host=' . rawurlencode( $h ) ), 'bz_health_remove_' . $h ) ); ?>" class="description" onclick="return confirm('Remove <?php echo esc_js( $h ); ?> from Client site health?');">Remove</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2 style="margin-top:28px">Automatic updates (all sites)</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'bz_health_policy' ); ?><input type="hidden" name="action" value="bz_health_policy">
			<table class="form-table" role="presentation">
				<tr><th>Plugins</th><td>
					<label><input type="radio" name="plugins" value="off" <?php checked( $policy['plugins'], 'off' ); ?>> Off: each site's own settings (update with Update now)</label><br>
					<label><input type="radio" name="plugins" value="patch" <?php checked( $policy['plugins'], 'patch' ); ?>> Patch releases only (e.g. 2.3.1 → 2.3.2): fixes and security, rarely breaking</label><br>
					<label><input type="radio" name="plugins" value="all" <?php checked( $policy['plugins'], 'all' ); ?>> All updates</label>
				</td></tr>
				<tr><th><label for="bzh-ex">Never auto-update</label></th><td><input id="bzh-ex" name="exclude" class="large-text" value="<?php echo esc_attr( implode( ', ', (array) $policy['exclude'] ) ); ?>"><p class="description">Plugin folder names, comma separated (e.g. elementor, elementor-pro). Update these with Update now after checking on a test site.</p></td></tr>
				<tr><th>Themes</th><td><label><input type="checkbox" name="themes" value="1" <?php checked( $policy['themes'], 'all' ); ?>> Auto-update themes</label></td></tr>
				<tr><th>WordPress</th><td><label><input type="checkbox" name="core_minor" value="1" <?php checked( ! empty( $policy['core_minor'] ) ); ?>> Minor and security releases (e.g. 6.8.1 → 6.8.2)</label></td></tr>
			</table>
			<?php submit_button( 'Save policy' ); ?>
		</form>
		<p class="description">Uptime: every site is checked every 5 minutes (the page must load and show the firm's name); two failures in a row raise a ticket to <?php echo esc_html( BZ_ALERT_EMAIL ); ?>, and again when it's back. For dependable timing add a Cloudways cron job opening <code><?php echo esc_html( site_url( '/wp-cron.php' ) ); ?></code> every 5 minutes. Keep one external monitor (e.g. UptimeRobot) on this master site itself.</p>
	</div>
	<script>
	(function () {
		var nonce = <?php echo wp_json_encode( wp_create_nonce( 'bz_health_action' ) ); ?>, ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		function act(host, what) {
			var f = new FormData(); f.append('action', 'bz_health_action'); f.append('_ajax_nonce', nonce); f.append('host', host); f.append('do', what);
			return fetch(ajax, { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
		}
		document.querySelectorAll('.bzh-do').forEach(function (b) {
			b.addEventListener('click', function () {
				var tr = b.closest('tr'), out = tr.querySelector('.bzh-res');
				b.disabled = true; out.textContent = b.dataset.do === 'update' ? ' Updating…' : ' Asking…';
				act(tr.dataset.host, b.dataset.do).then(function (j) { out.textContent = ' ' + (j.success ? j.data : 'Failed: ' + j.data); if (j.success) setTimeout(function () { location.reload(); }, 1500); b.disabled = false; });
			});
		});
		var all = document.getElementById('bzh-all');
		all && all.addEventListener('click', function () {
			var rows = [].slice.call(document.querySelectorAll('tr[data-host]')).filter(function (tr) { return tr.querySelector('.bzh-do[data-do=update]'); });
			if (!rows.length || !confirm('Update ' + rows.length + ' site(s), one at a time?')) return;
			all.disabled = true; var prog = document.getElementById('bzh-progress'), i = 0;
			(function next() {
				if (i >= rows.length) { prog.textContent = 'Done.'; setTimeout(function () { location.reload(); }, 1500); return; }
				var tr = rows[i++], out = tr.querySelector('.bzh-res');
				prog.textContent = 'Updating ' + tr.dataset.host + ' (' + i + ' of ' + rows.length + ')…'; out.textContent = ' Updating…';
				act(tr.dataset.host, 'update').then(function (j) { out.textContent = ' ' + (j.success ? j.data : 'Failed: ' + j.data); next(); }).catch(function () { out.textContent = ' Failed'; next(); });
			})();
		});
		var up = document.getElementById('bzh-uptime');
		up && up.addEventListener('click', function () { up.disabled = true; up.textContent = 'Checking…'; act('', 'uptime').then(function () { location.reload(); }); });
	})();
	</script>
	<?php
}

/* Pending updates of a site row: "Name 1.2 → 1.3" lines. */
function bz_core_health_pending( $row ) {
	$rep = isset( $row['report'] ) ? $row['report'] : array();
	$out = array();
	if ( ! empty( $rep['wp_new'] ) ) {
		$out[] = 'WordPress ' . $rep['wp'] . ' → ' . $rep['wp_new'];
	}
	foreach ( array( 'plugins', 'themes' ) as $k ) {
		foreach ( isset( $rep[ $k ] ) ? (array) $rep[ $k ] : array() as $p ) {
			if ( ! empty( $p['new'] ) && version_compare( $p['new'], $p['ver'], '>' ) ) {
				$out[] = $p['name'] . ' ' . $p['ver'] . ' → ' . $p['new'] . ( 'themes' === $k ? ' (theme)' : '' );
			}
		}
	}
	return $out;
}
