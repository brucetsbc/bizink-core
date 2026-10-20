<?php
/**
 * Packages (Essentials, Pro, Plus): which features a site shows.
 *
 * The master holds the definitions (Tools → Packages): per package, an on/off switch per feature, limits and the
 * service level wording. Every site stores only its package name (Client Details → Package) and fetches the
 * definitions from the master twice a day (last good copy kept), so a change on the master reaches every site.
 * A site with no package set shows everything.
 *
 * When a feature is off: its shortcodes print nothing, its pages and single items return "not found" and drop out
 * of menus (they are hidden, not deleted, so an upgrade brings them straight back), the body gets a bz-no-<feature>
 * class (sections tagged bz-needs-<feature> hide), and its wp-admin menus are hidden from the Company admin role.
 * Without the Marketing assistant, the dashboard becomes "Your website": stats, support and news, but no plan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BZ_PACKAGES_SOURCE' ) ) {
	define( 'BZ_PACKAGES_SOURCE', BZ_MASTER_URL . '/wp-json/bizink/v1/packages' );
}

function bz_core_package_names() {
	return array(
		'essentials' => 'Essentials',
		'pro'        => 'Pro',
		'plus'       => 'Plus',
	);
}

/* Feature key => [label, help]. */
function bz_core_package_features() {
	return array(
		'marketing'       => array( 'Marketing assistant', '90-day plan, Draft post and the lead magnet builder. Without it the dashboard shows stats, support and news only.' ),
		'lead_magnets'    => array( 'Lead magnet builder', 'Turn a template or checklist into a landing page and form (on the Marketing assistant page). Limit below.' ),
		'glossary'        => array( 'Glossary', 'The business, accounting and payroll glossary page.' ),
		'calculators'     => array( 'Calculators', 'The Calculators page and every calculator.' ),
		'templates'       => array( 'Templates and checklists', 'The Downloads page and every template and checklist.' ),
		'partner_content' => array( 'Partner content', 'Xero, MYOB and QuickBooks video libraries.' ),
		'key_dates'       => array( 'Key dates', 'The Key Tax Dates page and tax date lists.' ),
	);
}

/* Build methods: how a package's sites are built. Key => [label, help]. */
function bz_core_build_methods() {
	return array(
		'blocks'    => array( 'Standard build', 'WordPress block editor and the Bizink block theme. No Elementor licence needed.' ),
		'elementor' => array( 'Built with Elementor', 'Elementor (V4) and the Bizink Elementor master, with all signature sections.' ),
	);
}

/* Default build method per package: Essentials standard, Pro and Plus Elementor. */
function bz_core_package_default_build( $key ) {
	return 'essentials' === $key ? 'blocks' : 'elementor';
}

/* Limits: key => [label, default]. */
function bz_core_package_limits() {
	return array(
		'lead_magnets' => array( 'Lead magnets the firm can build', 10 ),
	);
}

/* Starting definitions: every feature on in every package (to be refined on the master). */
function bz_core_package_defaults() {
	$out = array();
	foreach ( bz_core_package_names() as $key => $label ) {
		$out[ $key ] = array(
			'label'    => $label,
			'features' => array_fill_keys( array_keys( bz_core_package_features() ), true ),
			'limits'   => array_map( function ( $l ) { return $l[1]; }, bz_core_package_limits() ),
			'service'  => '',
			'build'    => bz_core_package_default_build( $key ),
			'plugins'  => array(), // plugin file => on (bz_core_package_plugins_apply switches them on each site)
		);
	}
	return $out;
}

function bz_core_packages_is_source() {
	return bz_core_is_master() || wp_parse_url( BZ_PACKAGES_SOURCE, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );
}

/* All package definitions: the master's own option, or the copy fetched from the master. */
function bz_core_packages() {
	static $defs = null;
	if ( null !== $defs ) {
		return $defs;
	}
	$base = bz_core_package_defaults();
	if ( bz_core_packages_is_source() ) {
		$saved = (array) get_option( 'bz_packages', array() );
	} else {
		$copy  = (array) get_option( 'bz_packages_remote', array() );
		$saved = isset( $copy['data'] ) ? (array) $copy['data'] : array();
		if ( empty( $copy['time'] ) || $copy['time'] < time() - ( empty( $copy['ok'] ) ? HOUR_IN_SECONDS : 3 * HOUR_IN_SECONDS ) ) {
			$saved = bz_core_packages_fetch( $copy );
		}
	}
	foreach ( $base as $key => $pkg ) {
		if ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) {
			$s                       = $saved[ $key ];
			$base[ $key ]['features'] = array_merge( $pkg['features'], array_map( 'boolval', isset( $s['features'] ) ? (array) $s['features'] : array() ) );
			$base[ $key ]['limits']   = array_merge( $pkg['limits'], array_map( 'intval', isset( $s['limits'] ) ? (array) $s['limits'] : array() ) );
			$base[ $key ]['service']  = isset( $s['service'] ) ? (string) $s['service'] : '';
			$base[ $key ]['build']    = isset( $s['build'] ) && isset( bz_core_build_methods()[ $s['build'] ] ) ? $s['build'] : $pkg['build'];
			$base[ $key ]['plugins']  = isset( $s['plugins'] ) && is_array( $s['plugins'] ) ? array_map( 'boolval', $s['plugins'] ) : array();
			$base[ $key ]['plugin_names'] = isset( $s['plugin_names'] ) && is_array( $s['plugin_names'] ) ? $s['plugin_names'] : array();
		}
	}
	$defs = $base;
	return $defs;
}

/* Fetch definitions from the master; keep the last good copy on failure. */
function bz_core_packages_fetch( $copy ) {
	$response = bz_core_master_request( BZ_PACKAGES_SOURCE, array( 'timeout' => 10 ) );
	$data     = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
	if ( is_array( $data ) ) {
		update_option( 'bz_packages_remote', array( 'time' => time(), 'ok' => 1, 'data' => $data ), false );
		return $data;
	}
	$old = isset( $copy['data'] ) ? (array) $copy['data'] : array();
	update_option( 'bz_packages_remote', array( 'time' => time(), 'ok' => 0, 'data' => $old ), false );
	return $old;
}

/* This site's package key, or '' when none is set. */
function bz_core_package() {
	$p = function_exists( 'bz_core_get' ) ? bz_core_get( 'package' ) : '';
	return isset( bz_core_package_names()[ $p ] ) ? $p : '';
}

/* Is a feature included for this site? No package set = everything. */
function bz_core_feature( $feature ) {
	$p = bz_core_package();
	if ( '' === $p ) {
		return true;
	}
	$defs = bz_core_packages();
	return ! isset( $defs[ $p ]['features'][ $feature ] ) || (bool) $defs[ $p ]['features'][ $feature ];
}

function bz_core_package_limit( $limit ) {
	$p    = bz_core_package();
	$defs = bz_core_packages();
	$def  = bz_core_package_limits();
	return '' === $p ? (int) $def[ $limit ][1] : (int) $defs[ $p ]['limits'][ $limit ];
}

/* Admins on a site: fetch the master's package table now (dashboard link "Check for package changes"). */
add_action(
	'admin_post_bz_packages_refresh',
	function () {
		check_admin_referer( 'bz_packages_refresh' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$ok = true;
		if ( ! bz_core_packages_is_source() ) {
			bz_core_packages_fetch( (array) get_option( 'bz_packages_remote', array() ) );
			$copy = (array) get_option( 'bz_packages_remote', array() );
			$ok   = ! empty( $copy['ok'] );
			delete_transient( 'bz_package_plugins_checked' );
			bz_core_package_plugins_apply();
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=bz-marketing' );
		wp_safe_redirect( add_query_arg( 'bz_msg', rawurlencode( $ok ? 'Package details updated from Bizink.' : 'Could not reach Bizink just now; the last copy is still in use.' ), $back ) );
		exit;
	}
);

function bz_core_packages_refresh_link() {
	return wp_nonce_url( admin_url( 'admin-post.php?action=bz_packages_refresh' ), 'bz_packages_refresh' );
}

/* ---------- Master: REST (public, no secrets) and Tools → Packages ---------- */

add_action(
	'rest_api_init',
	function () {
		if ( ! bz_core_packages_is_source() ) {
			return;
		}
		register_rest_route(
			'bizink/v1',
			'/packages',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					return rest_ensure_response( bz_core_packages() );
				},
			)
		);
	}
);

add_action(
	'admin_menu',
	function () {
		if ( bz_core_packages_is_source() ) {
			add_management_page( 'Packages', 'Packages', 'manage_options', 'bz-packages', 'bz_core_render_packages' );
		}
	}
);

add_action(
	'admin_post_bz_packages',
	function () {
		check_admin_referer( 'bz_packages' );
		if ( ! current_user_can( 'manage_options' ) || ! bz_core_packages_is_source() ) {
			wp_die( 'Not allowed.' );
		}
		$in  = isset( $_POST['pkg'] ) ? (array) wp_unslash( $_POST['pkg'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised below.
		$out = array();
		foreach ( bz_core_package_names() as $key => $label ) {
			$row           = isset( $in[ $key ] ) ? (array) $in[ $key ] : array();
			$out[ $key ]   = array(
				'features' => array(),
				'limits'   => array(),
				'service'  => isset( $_POST[ 'bz_service_' . $key ] ) ? wp_kses_post( wp_unslash( $_POST[ 'bz_service_' . $key ] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'build'    => isset( $row['build'] ) && isset( bz_core_build_methods()[ $row['build'] ] ) ? $row['build'] : bz_core_package_default_build( $key ),
				'plugins'  => array(),
				'plugin_names' => array(),
			);
			foreach ( bz_core_managed_plugins() as $file => $name ) {
				$out[ $key ]['plugins'][ $file ]      = ! empty( $row['plugins'][ md5( $file ) ] );
				$out[ $key ]['plugin_names'][ $file ] = $name;
			}
			foreach ( array_keys( bz_core_package_features() ) as $f ) {
				$out[ $key ]['features'][ $f ] = ! empty( $row['features'][ $f ] );
			}
			foreach ( array_keys( bz_core_package_limits() ) as $l ) {
				$out[ $key ]['limits'][ $l ] = isset( $row['limits'][ $l ] ) ? max( 0, (int) $row['limits'][ $l ] ) : 0;
			}
		}
		update_option( 'bz_packages', $out, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'bz-packages', 'bz_msg' => rawurlencode( 'Packages saved. Sites pick up the change within 3 hours, or straight away with Check for package changes on their dashboard.' ) ), admin_url( 'tools.php' ) ) );
		exit;
	}
);

function bz_core_render_packages() {
	$defs = bz_core_packages();
	$msg  = isset( $_GET['bz_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bz_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap"><h1>Packages</h1><p>What each package includes. Every site set to a package (Client Details → Package) follows this table; sites check for changes every 3 hours, or straight away with Check for package changes on their dashboard. A site with no package set shows everything.</p>';
	if ( $msg ) {
		echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'bz_packages' );
	echo '<input type="hidden" name="action" value="bz_packages"><table class="widefat striped" style="max-width:1000px"><thead><tr><th>Feature</th>';
	foreach ( bz_core_package_names() as $label ) {
		echo '<th style="text-align:center">' . esc_html( $label ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	echo '<tr><td><strong>Build method</strong><br><span class="description">How this package\'s sites are built. Standard build: block editor, no Elementor licence. Built with Elementor: Elementor and all signature sections. The Site builder shows the matching checklist.</span></td>';
	foreach ( array_keys( bz_core_package_names() ) as $key ) {
		echo '<td style="text-align:center"><select name="pkg[' . esc_attr( $key ) . '][build]" aria-label="' . esc_attr( 'Build method for ' . $defs[ $key ]['label'] ) . '">';
		foreach ( bz_core_build_methods() as $m => $info ) {
			echo '<option value="' . esc_attr( $m ) . '"' . selected( $defs[ $key ]['build'], $m, false ) . '>' . esc_html( $info[0] ) . '</option>';
		}
		echo '</select></td>';
	}
	echo '</tr>';
	foreach ( bz_core_package_features() as $f => $info ) {
		echo '<tr><td><strong>' . esc_html( $info[0] ) . '</strong><br><span class="description">' . esc_html( $info[1] ) . '</span></td>';
		foreach ( array_keys( bz_core_package_names() ) as $key ) {
			echo '<td style="text-align:center"><input type="checkbox" name="pkg[' . esc_attr( $key ) . '][features][' . esc_attr( $f ) . ']" value="1"' . checked( $defs[ $key ]['features'][ $f ], true, false ) . ' aria-label="' . esc_attr( $info[0] . ' in ' . $defs[ $key ]['label'] ) . '"></td>';
		}
		echo '</tr>';
	}
	foreach ( bz_core_package_limits() as $l => $info ) {
		echo '<tr><td><strong>' . esc_html( $info[0] ) . '</strong></td>';
		foreach ( array_keys( bz_core_package_names() ) as $key ) {
			echo '<td style="text-align:center"><input type="number" min="0" style="width:70px" name="pkg[' . esc_attr( $key ) . '][limits][' . esc_attr( $l ) . ']" value="' . esc_attr( $defs[ $key ]['limits'][ $l ] ) . '"></td>';
		}
		echo '</tr>';
	}
	$managed = bz_core_managed_plugins();
	if ( $managed ) {
		echo '<tr><td colspan="' . ( 1 + count( bz_core_package_names() ) ) . '" style="background:#f6f7f7"><strong>Plugins</strong><br><span class="description">Plugins installed on this master site. Ticked = switched on for that package\'s sites, unticked = switched off (deactivated, never deleted). Sites without a package, and this master, are left as they are.</span></td></tr>';
		foreach ( $managed as $file => $name ) {
			echo '<tr><td><strong>' . esc_html( $name ) . '</strong><br><span class="description"><code>' . esc_html( $file ) . '</code></span></td>';
			foreach ( array_keys( bz_core_package_names() ) as $key ) {
				$on = ! empty( $defs[ $key ]['plugins'][ $file ] );
				echo '<td style="text-align:center"><input type="checkbox" name="pkg[' . esc_attr( $key ) . '][plugins][' . esc_attr( md5( $file ) ) . ']" value="1"' . checked( $on, true, false ) . ' aria-label="' . esc_attr( $name . ' in ' . $defs[ $key ]['label'] ) . '"></td>';
			}
			echo '</tr>';
		}
	}
	echo '</tbody></table>';
	echo '<h2 style="margin-top:28px">Service level</h2><p class="description">Shown to the firm on their dashboard (under "Your service level") and in the Site builder. Headings, bold, lists and links are kept.</p>';
	foreach ( bz_core_package_names() as $key => $label ) {
		echo '<h3>' . esc_html( $label ) . '</h3><div style="max-width:1000px">';
		wp_editor(
			wpautop( $defs[ $key ]['service'] ),
			'bz_service_' . $key,
			array(
				'textarea_name' => 'bz_service_' . $key,
				'textarea_rows' => 12,
				'media_buttons' => false,
				'teeny'         => false,
				'tinymce'       => array( 'block_formats' => 'Paragraph=p;Heading 3=h3;Heading 4=h4', 'toolbar1' => 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo' ),
				'quicktags'     => array( 'buttons' => 'strong,em,ul,ol,li,link' ),
			)
		);
		echo '</div>';
	}
	submit_button( 'Save packages' );
	echo '</form></div>';
}

/* ---------- Switching features off on a site ---------- */

/* Shortcode → feature. */
function bz_core_feature_shortcodes() {
	return array(
		'bz_glossary'      => 'glossary',
		'bz_tax_dates'     => 'key_dates',
		'bz_video_library' => 'partner_content',
		'bz_calculator'    => 'calculators',
		'bz_pdf'           => 'templates',
	);
}

/* Pages that belong to a feature: the master's page IDs (clones keep them), plus any listed in bz_feature_pages. */
function bz_core_feature_pages() {
	$pages = array(
		'glossary'        => array( 472 ),
		'key_dates'       => array( 53 ),
		'calculators'     => array( 52 ),
		'templates'       => array( 51 ),
		'partner_content' => array( 591, 596, 601 ),
	);
	return apply_filters( 'bz_feature_pages', $pages );
}

/*
 * Sections and buttons on shared pages that belong to a feature, by Elementor element id (data-interaction-id; clones
 * of the master keep them): Home and Resources on the master.
 */
function bz_core_feature_elements() {
	return apply_filters(
		'bz_feature_elements',
		array(
			'templates'   => array( '7d07f750', '204ba870' ), // "Free resources" checklists section (component), Resources hero button.
			'calculators' => array( '58e2ffbf', '4d5e9899' ), // Resources: calculators section, hero button.
			'key_dates'   => array( '4929e741', '5a0d79ca' ), // Key dates strip on Home and Resources.
		)
	);
}

/* Page and post IDs hidden on this site, and post types switched off. */
function bz_core_hidden() {
	static $hidden = null;
	if ( null !== $hidden ) {
		return $hidden;
	}
	$hidden = array( 'ids' => array(), 'types' => array() );
	foreach ( bz_core_feature_pages() as $f => $ids ) {
		if ( ! bz_core_feature( $f ) ) {
			$hidden['ids'] = array_merge( $hidden['ids'], array_map( 'intval', $ids ) );
		}
	}
	if ( ! bz_core_feature( 'calculators' ) ) {
		$hidden['types'][] = 'bz_calculator';
	}
	if ( ! bz_core_feature( 'templates' ) ) {
		$hidden['types'][] = 'bz_resource';
	}
	return $hidden;
}

add_filter(
	'pre_do_shortcode_tag',
	function ( $out, $tag ) {
		$map = bz_core_feature_shortcodes();
		return ( isset( $map[ $tag ] ) && ! bz_core_feature( $map[ $tag ] ) ) ? '' : $out;
	},
	10,
	2
);

// Hidden pages and items answer "not found" to visitors (editors logged in can still open them).
add_action(
	'template_redirect',
	function () {
		if ( current_user_can( 'edit_pages' ) ) {
			return; // Bizink staff and editors can still open hidden pages; the Company admin role can't.
		}
		$h = bz_core_hidden();
		if ( ( $h['ids'] && is_page( $h['ids'] ) ) || ( $h['types'] && ( is_singular( $h['types'] ) || is_post_type_archive( $h['types'] ) ) ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	},
	5
);

// Drop hidden pages from menus.
add_filter(
	'wp_nav_menu_objects',
	function ( $items ) {
		$h = bz_core_hidden();
		if ( ! $h['ids'] && ! $h['types'] ) {
			return $items;
		}
		$drop = array();
		foreach ( $items as $item ) {
			if ( ( 'page' === $item->object && in_array( (int) $item->object_id, $h['ids'], true ) ) || in_array( $item->object, $h['types'], true ) || in_array( (int) $item->menu_item_parent, $drop, true ) ) {
				$drop[] = (int) $item->ID;
			}
		}
		return array_values( array_filter( $items, function ( $item ) use ( $drop ) {
			return ! in_array( (int) $item->ID, $drop, true );
		} ) );
	}
);

// Keep hidden pages out of the sitemap and search.
add_filter(
	'wp_sitemaps_posts_query_args',
	function ( $args, $post_type ) {
		$h = bz_core_hidden();
		if ( 'page' === $post_type && $h['ids'] ) {
			$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $h['ids'] );
		}
		return $args;
	},
	10,
	2
);
add_filter(
	'wp_sitemaps_post_types',
	function ( $types ) {
		foreach ( bz_core_hidden()['types'] as $t ) {
			unset( $types[ $t ] );
		}
		return $types;
	}
);
add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( ! is_admin() && $q->is_main_query() && $q->is_search() ) {
			$h = bz_core_hidden();
			if ( $h['ids'] ) {
				$q->set( 'post__not_in', $h['ids'] );
			}
		}
	}
);

// Body classes: bz-no-<feature> for each feature that is off; sections tagged bz-needs-<feature> hide.
add_filter(
	'body_class',
	function ( $classes ) {
		foreach ( array_keys( bz_core_package_features() ) as $f ) {
			if ( ! bz_core_feature( $f ) ) {
				$classes[] = 'bz-no-' . str_replace( '_', '-', $f );
			}
		}
		$p = bz_core_package();
		if ( $p ) {
			$classes[] = 'bz-package-' . $p;
		}
		return $classes;
	}
);
add_action(
	'wp_head',
	function () {
		$css = '';
		$els = bz_core_feature_elements();
		foreach ( array_keys( bz_core_package_features() ) as $f ) {
			if ( ! bz_core_feature( $f ) ) {
				$slug = str_replace( '_', '-', $f );
				$css .= '.bz-no-' . $slug . ' .bz-needs-' . $slug . '{display:none!important}';
				foreach ( isset( $els[ $f ] ) ? $els[ $f ] : array() as $id ) {
					$css .= '[data-interaction-id="' . preg_replace( '/[^0-9a-z]/', '', $id ) . '"]{display:none!important}';
				}
			}
		}
		if ( $css ) {
			echo '<style id="bz-package-css">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- built from fixed keys.
		}
	},
	20
);

// wp-admin: hide menus for switched-off features from the Company admin role.
add_action(
	'admin_menu',
	function () {
		if ( ! function_exists( 'bz_core_mkt_is_client_user' ) || ! bz_core_mkt_is_client_user() ) {
			return;
		}
		$h = bz_core_hidden();
		foreach ( $h['types'] as $t ) {
			remove_menu_page( 'edit.php?post_type=' . $t );
		}
	},
	1000
);


/* ---------- Plugins by package ---------- */

/* Plugins the master can assign to packages: everything installed except the platform itself. */
function bz_core_managed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		if ( preg_match( '#^(bizink-core|elementor|elementor-pro|gravityforms|duplicator|akismet|hello\.php)(/|$)#', $file ) ) {
			continue;
		}
		$out[ $file ] = $data['Name'];
	}
	return $out;
}

/**
 * Switch this site's package plugins on or off to match the package (never on the master or a site without a
 * package). Plugins the package lists but that aren't installed here are remembered for the dashboard.
 * Returns a short log.
 */
function bz_core_package_plugins_apply() {
	if ( bz_core_packages_is_source() || '' === bz_core_package() ) {
		return array();
	}
	$defs = bz_core_packages();
	$want = isset( $defs[ bz_core_package() ]['plugins'] ) ? (array) $defs[ bz_core_package() ]['plugins'] : array();
	if ( ! $want ) {
		return array();
	}
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$installed = get_plugins();
	$names     = isset( $defs[ bz_core_package() ]['plugin_names'] ) ? (array) $defs[ bz_core_package() ]['plugin_names'] : array();
	$log       = array();
	$missing   = array();
	foreach ( $want as $file => $on ) {
		if ( ! isset( $installed[ $file ] ) ) {
			if ( $on ) {
				$missing[ $file ] = isset( $names[ $file ] ) ? $names[ $file ] : $file;
			}
			continue;
		}
		$active = is_plugin_active( $file );
		if ( $on && ! $active ) {
			$r     = activate_plugin( $file, '', false, true );
			$log[] = is_wp_error( $r ) ? 'Could not switch on ' . $installed[ $file ]['Name'] . ': ' . $r->get_error_message() : 'Switched on ' . $installed[ $file ]['Name'];
		} elseif ( ! $on && $active ) {
			deactivate_plugins( $file, true );
			$log[] = 'Switched off ' . $installed[ $file ]['Name'];
		}
	}
	update_option( 'bz_package_plugins_missing', $missing, false );
	if ( $log ) {
		update_option( 'bz_package_plugins_log', array( 'time' => time(), 'log' => $log ), false );
	}
	return $log;
}

// In wp-admin (at most every 10 minutes) and twice a day in the background.
add_action(
	'admin_init',
	function () {
		if ( ! current_user_can( 'activate_plugins' ) || get_transient( 'bz_package_plugins_checked' ) ) {
			return;
		}
		set_transient( 'bz_package_plugins_checked', 1, 10 * MINUTE_IN_SECONDS );
		bz_core_package_plugins_apply();
	}
);
add_action( 'bz_core_package_plugins_cron', 'bz_core_package_plugins_apply' );
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'bz_core_package_plugins_cron' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'bz_core_package_plugins_cron' );
		}
	}
);
// A package change in Client Details applies straight away.
add_action(
	'update_option_' . BZ_CORE_OPTION,
	function ( $old, $new ) {
		$o = is_array( $old ) && isset( $old['package'] ) ? $old['package'] : '';
		$n = is_array( $new ) && isset( $new['package'] ) ? $new['package'] : '';
		if ( $o !== $n ) {
			delete_transient( 'bz_package_plugins_checked' );
		}
	},
	10,
	2
);

/* For the dashboard: package plugins this site should have but doesn't. */
function bz_core_package_plugins_missing() {
	return (array) get_option( 'bz_package_plugins_missing', array() );
}
