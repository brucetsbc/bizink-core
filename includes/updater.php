<?php
/**
 * Updates: every site gets new bizink-core versions through WordPress's own "Update now" (and auto-updates, if
 * switched on for the plugin).
 *
 *   GitHub (public repo BZ_CORE_REPO) ← releases made by tools/release.py
 *     ↑ the master reads the latest release (cached 30 minutes)
 *   GET  {master}/wp-json/bizink/v1/plugin/latest?site=<host>&version=<installed>
 *     ↑ each site asks the master (cached 6 hours; "Check again" on Dashboard → Updates asks straight away)
 *
 * The master logs each site's reported version (Tools → Plugin versions). The download itself comes from the
 * GitHub release's zip (bizink-core/ inside, as WordPress expects).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BZ_CORE_REPO' ) ) {
	define( 'BZ_CORE_REPO', 'brucetsbc/bizink-core' );
}

/* ---------- Master: the latest release, from GitHub ---------- */

function bz_core_latest_release() {
	$cached = get_transient( 'bz_core_latest_release' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$r = wp_remote_get(
		'https://api.github.com/repos/' . BZ_CORE_REPO . '/releases/latest',
		array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'bizink-core/' . BZ_CORE_VERSION ) )
	);
	if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
		$last = get_option( 'bz_core_latest_release_last', array() );
		set_transient( 'bz_core_latest_release', $last, 10 * MINUTE_IN_SECONDS ); // try again soon; keep the last good answer
		return $last;
	}
	$rel = json_decode( wp_remote_retrieve_body( $r ), true );
	$zip = '';
	foreach ( isset( $rel['assets'] ) ? (array) $rel['assets'] : array() as $a ) {
		if ( isset( $a['name'] ) && preg_match( '/^bizink-core.*\.zip$/', $a['name'] ) ) {
			$zip = $a['browser_download_url'];
		}
	}
	$out = array(
		'version'   => ltrim( (string) ( isset( $rel['tag_name'] ) ? $rel['tag_name'] : '' ), 'v' ),
		'download'  => $zip,
		'notes'     => isset( $rel['body'] ) ? (string) $rel['body'] : '',
		'published' => isset( $rel['published_at'] ) ? (string) $rel['published_at'] : '',
		'url'       => isset( $rel['html_url'] ) ? (string) $rel['html_url'] : '',
	);
	if ( $out['version'] && $zip ) {
		update_option( 'bz_core_latest_release_last', $out, false );
	}
	set_transient( 'bz_core_latest_release', $out, 30 * MINUTE_IN_SECONDS );
	return $out;
}

add_action(
	'rest_api_init',
	function () {
		if ( ! bz_core_is_master() ) {
			return;
		}
		register_rest_route(
			'bizink/v1',
			'/plugin/latest',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true', // public: version number and a public download link only
				'callback'            => function ( WP_REST_Request $r ) {
					$site    = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $r['site'] ) );
					$version = preg_replace( '/[^0-9.]/', '', (string) $r['version'] );
					if ( $site && false !== strpos( $site, '.' ) && $version && '0' !== $version ) { // real domains only
						$log          = (array) get_option( 'bz_core_site_versions', array() );
						$log          = array_filter( $log, function ( $row, $host ) { return false !== strpos( $host, '.' ); }, ARRAY_FILTER_USE_BOTH );
						$log[ $site ] = array( 'version' => $version, 'time' => time() );
						update_option( 'bz_core_site_versions', array_slice( $log, -500, null, true ), false );
					}
					return rest_ensure_response( bz_core_latest_release() );
				},
			)
		);
	}
);

add_action(
	'admin_menu',
	function () {
		if ( bz_core_is_master() ) {
			add_management_page( 'Plugin versions', 'Plugin versions', 'manage_options', 'bz-plugin-versions', 'bz_core_render_plugin_versions' );
		}
	}
);

function bz_core_render_plugin_versions() {
	if ( isset( $_GET['refresh'] ) && check_admin_referer( 'bz_versions_refresh' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		delete_transient( 'bz_core_latest_release' );
	}
	$latest = bz_core_latest_release();
	$log    = (array) get_option( 'bz_core_site_versions', array() );
	uasort( $log, function ( $a, $b ) { return $b['time'] - $a['time']; } );
	echo '<div class="wrap"><h1>Plugin versions</h1>';
	echo '<p>Latest release on GitHub (' . esc_html( BZ_CORE_REPO ) . '): <strong>' . esc_html( $latest ? $latest['version'] : 'unknown' ) . '</strong>'
		. ( ! empty( $latest['url'] ) ? ' · <a href="' . esc_url( $latest['url'] ) . '" target="_blank" rel="noopener">Release notes</a>' : '' )
		. ' · <a href="' . esc_url( wp_nonce_url( admin_url( 'tools.php?page=bz-plugin-versions&refresh=1' ), 'bz_versions_refresh' ) ) . '">Check GitHub now</a></p>';
	echo '<p class="description">Sites check every 6 hours and show "Update now" on their Plugins page when a newer release exists. Each site\'s version is recorded when it checks.</p>';
	echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Site</th><th>bizink-core</th><th>Last checked</th></tr></thead><tbody>';
	foreach ( $log as $site => $row ) {
		$behind = $latest && version_compare( $row['version'], $latest['version'], '<' );
		echo '<tr><td>' . esc_html( $site ) . '</td><td' . ( $behind ? ' style="color:#b45309;font-weight:600"' : '' ) . '>' . esc_html( $row['version'] ) . ( $behind ? ' (update waiting)' : '' ) . '</td><td>' . esc_html( human_time_diff( $row['time'] ) ) . ' ago</td></tr>';
	}
	if ( ! $log ) {
		echo '<tr><td colspan="3">No site has checked yet.</td></tr>';
	}
	echo '</tbody></table></div>';
}

/* ---------- Every site (the master too): WordPress's update check ---------- */

function bz_core_update_info( $force = false ) {
	$cached = get_transient( 'bz_core_update_info' );
	if ( ! $force && is_array( $cached ) ) {
		return $cached;
	}
	$url = BZ_MASTER_URL . '/wp-json/bizink/v1/plugin/latest?' . http_build_query(
		array( 'site' => wp_parse_url( home_url(), PHP_URL_HOST ), 'version' => BZ_CORE_VERSION )
	);
	$r    = bz_core_master_request( $url, array( 'timeout' => 10 ) );
	$info = is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ? array() : (array) json_decode( wp_remote_retrieve_body( $r ), true );
	set_transient( 'bz_core_update_info', $info, ( $info ? 6 : 1 ) * HOUR_IN_SECONDS );
	return $info;
}

function bz_core_plugin_file() {
	return plugin_basename( dirname( __DIR__ ) . '/bizink-core.php' );
}

add_filter(
	'pre_set_site_transient_update_plugins',
	function ( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$force = isset( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification -- Dashboard → Updates "Check again"
		$info  = bz_core_update_info( $force );
		$file  = bz_core_plugin_file();
		$item  = (object) array(
			'id'          => 'github.com/' . BZ_CORE_REPO,
			'slug'        => 'bizink-core',
			'plugin'      => $file,
			'new_version' => ! empty( $info['version'] ) ? $info['version'] : BZ_CORE_VERSION,
			'url'         => 'https://github.com/' . BZ_CORE_REPO,
			'package'     => ! empty( $info['download'] ) ? $info['download'] : '',
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => get_bloginfo( 'version' ),
		);
		if ( ! empty( $info['version'] ) && ! empty( $info['download'] ) && version_compare( $info['version'], BZ_CORE_VERSION, '>' ) ) {
			$transient->response[ $file ] = $item;
		} else {
			unset( $transient->response[ $file ] );
			$transient->no_update[ $file ] = $item; // lets the auto-updates toggle show for the plugin
		}
		return $transient;
	}
);

/* "View details" on the Plugins and Updates screens: the release notes. */
add_filter(
	'plugins_api',
	function ( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'bizink-core' !== $args->slug ) {
			return $result;
		}
		$info  = bz_core_update_info();
		$notes = ! empty( $info['notes'] ) ? $info['notes'] : 'No notes for this release.';
		$html  = wpautop( esc_html( $notes ) );
		$html  = preg_replace( '/^\s*[-*]\s+(.*)$/m', '<li>$1</li>', $html );
		return (object) array(
			'name'          => 'Bizink Core',
			'slug'          => 'bizink-core',
			'version'       => ! empty( $info['version'] ) ? $info['version'] : BZ_CORE_VERSION,
			'author'        => 'Bizink',
			'homepage'      => 'https://github.com/' . BZ_CORE_REPO,
			'download_link' => ! empty( $info['download'] ) ? $info['download'] : '',
			'last_updated'  => ! empty( $info['published'] ) ? $info['published'] : '',
			'sections'      => array( 'changelog' => $html ),
		);
	},
	10,
	3
);

/* After an update, forget the cached answer so the Plugins page is right straight away. */
add_action(
	'upgrader_process_complete',
	function ( $upgrader, $extra ) {
		if ( isset( $extra['type'], $extra['plugins'] ) && 'plugin' === $extra['type'] && in_array( bz_core_plugin_file(), (array) $extra['plugins'], true ) ) {
			delete_transient( 'bz_core_update_info' );
		}
	},
	10,
	2
);
