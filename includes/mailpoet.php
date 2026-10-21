<?php
/**
 * MailPoet (Plus): a newsletter template per site in the firm's own colours, logo, social links and details, and
 * MailPoet shortcodes that read the firm's details when each email is sent.
 *
 * Template: assets/mailpoet/newsletter.json is a MailPoet template designed on the master and turned into
 * placeholders by tools/mailpoet_template.py:
 *   {{bz_primary}} {{bz_primary_dark}} {{bz_secondary}} {{bz_accent}} {{bz_heading}} {{bz_text}} {{bz_surface_alt}}
 *   {{bz_logo}} {{bz_firm}} {{bz_phone}} {{bz_email}} {{bz_address}} {{bz_website}} {{bz_booking_url}}
 *   {{bz_facebook}} {{bz_linkedin}} {{bz_instagram}} {{bz_x}} {{bz_youtube}}
 * On a site with MailPoet active, bizink-core fills them in from the site (theme colours, Appearance → Site logo,
 * Client Details) and saves "<Firm> newsletter" in MailPoet's templates (Emails → Create → Your saved templates),
 * again whenever those values change. Social icons without an address are dropped. Newsletters already created keep
 * their own copy (MailPoet copies a template when a newsletter is made from it).
 *
 * Shortcodes (MailPoet editor: write them as text): [custom:bz_firm] [custom:bz_phone] [custom:bz_email]
 * [custom:bz_address] [custom:bz_website] [custom:bz_booking_url] [custom:bz_compliance].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bz_core_mailpoet_active() {
	return class_exists( '\MailPoet\Config\Env' ) || defined( 'MAILPOET_VERSION' );
}

/* ---------- Live shortcodes ---------- */

add_filter(
	'mailpoet_newsletter_shortcode',
	function ( $shortcode, $newsletter = null, $subscriber = null, $queue = null, $content = null, $preview = null ) {
		if ( ! preg_match( '/^\[custom:(bz_[a-z_]+)\]$/', (string) $shortcode, $m ) || ! function_exists( 'bz_core_get' ) ) {
			return $shortcode;
		}
		$map = array(
			'bz_firm'        => bz_core_get( 'firm_name' ),
			'bz_phone'       => bz_core_get( 'phone' ),
			'bz_email'       => bz_core_get( 'email' ),
			'bz_address'     => wp_strip_all_tags( str_replace( array( '<br>', '<br />' ), ', ', bz_core_get( 'address_full' ) ) ),
			'bz_website'     => home_url( '/' ),
			'bz_booking_url' => bz_core_get( 'booking_url' ),
			'bz_compliance'  => bz_core_get( 'compliance_note' ),
		);
		return isset( $map[ $m[1] ] ) ? esc_html( (string) $map[ $m[1] ] ) : $shortcode;
	},
	10,
	6
);

/* ---------- The firm's values for the template ---------- */

function bz_core_mailpoet_values() {
	$opts   = get_option( BZ_CORE_OPTION, array() );
	$colors = array();
	// Elementor builds: the kit's global variables; Standard builds: the block theme's palette.
	if ( function_exists( 'bz_core_theme_variables' ) && is_array( $vars = bz_core_theme_variables() ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
		foreach ( array( 'primary', 'primary-dark', 'secondary', 'accent', 'heading', 'text', 'surface-alt' ) as $k ) {
			if ( isset( $vars[ 'color-' . $k ]['value'] ) ) {
				$colors[ $k ] = $vars[ 'color-' . $k ]['value'];
			}
		}
	}
	if ( ! $colors && function_exists( 'wp_get_global_settings' ) ) {
		$map = array( 'accent-1' => 'primary', 'accent-2' => 'primary-dark', 'accent-3' => 'accent', 'contrast' => 'heading', 'body-text' => 'text', 'accent-5' => 'surface-alt' );
		foreach ( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) as $c ) {
			if ( isset( $c['slug'], $map[ $c['slug'] ] ) ) {
				$colors[ $map[ $c['slug'] ] ] = $c['color'];
			}
		}
	}
	$colors += array( 'primary' => '#1F3A5F', 'primary-dark' => '#142840', 'secondary' => '#2E7D6B', 'accent' => '#E0A526', 'heading' => '#14202E', 'text' => '#33404F', 'surface-alt' => '#F3F5F8' );
	$logo_id = (int) get_option( 'site_logo' ) ?: (int) get_theme_mod( 'custom_logo' );
	$get     = function ( $k ) use ( $opts ) {
		return isset( $opts[ $k ] ) ? trim( (string) $opts[ $k ] ) : '';
	};
	return array(
		'bz_primary'      => $colors['primary'],
		'bz_primary_dark' => $colors['primary-dark'],
		'bz_secondary'    => $colors['secondary'],
		'bz_accent'       => $colors['accent'],
		'bz_heading'      => $colors['heading'],
		'bz_text'         => $colors['text'],
		'bz_surface_alt'  => $colors['surface-alt'],
		'bz_logo'         => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '',
		'bz_firm'         => function_exists( 'bz_core_get' ) ? bz_core_get( 'firm_name' ) : get_bloginfo( 'name' ),
		'bz_phone'        => $get( 'phone' ),
		'bz_email'        => $get( 'email' ),
		'bz_address'      => function_exists( 'bz_core_get' ) ? wp_strip_all_tags( str_replace( array( '<br>', '<br />' ), ', ', bz_core_get( 'address_full' ) ) ) : '',
		'bz_website'      => home_url( '/' ),
		'bz_booking_url'  => function_exists( 'bz_core_get' ) ? bz_core_get( 'booking_url' ) : '',
		'bz_facebook'     => $get( 'social_facebook' ),
		'bz_linkedin'     => $get( 'social_linkedin' ),
		'bz_instagram'    => $get( 'social_instagram' ),
		'bz_x'            => $get( 'social_x' ),
		'bz_youtube'      => $get( 'social_youtube' ),
	);
}

/* Fill the placeholders in a decoded template body; drop social icons that have no address. */
function bz_core_mailpoet_fill( $node, $values ) {
	if ( is_string( $node ) ) {
		return preg_replace_callback(
			'/\{\{(bz_[a-z_]+)\}\}/',
			function ( $m ) use ( $values ) {
				return isset( $values[ $m[1] ] ) ? (string) $values[ $m[1] ] : '';
			},
			$node
		);
	}
	if ( ! is_array( $node ) ) {
		return $node;
	}
	if ( isset( $node['type'] ) && 'social' === $node['type'] && isset( $node['icons'] ) && is_array( $node['icons'] ) ) {
		$icons = array();
		foreach ( $node['icons'] as $icon ) {
			$filled = bz_core_mailpoet_fill( $icon, $values );
			if ( ! empty( $filled['link'] ) && ! preg_match( '#^https?://?$#', $filled['link'] ) ) {
				$icons[] = $filled;
			}
		}
		$node['icons'] = $icons;
		foreach ( $node as $k => $v ) {
			if ( 'icons' !== $k ) {
				$node[ $k ] = bz_core_mailpoet_fill( $v, $values );
			}
		}
		return $node;
	}
	foreach ( $node as $k => $v ) {
		$node[ $k ] = bz_core_mailpoet_fill( $v, $values );
	}
	return $node;
}

/* ---------- Save the firm's template in MailPoet ---------- */

function bz_core_mailpoet_sync( $force = false ) {
	if ( ! bz_core_mailpoet_active() ) {
		return 'MailPoet is not active.';
	}
	$file = dirname( __DIR__ ) . '/assets/mailpoet/newsletter.json';
	if ( ! file_exists( $file ) ) {
		return 'No newsletter template in this bizink-core yet.';
	}
	$tpl    = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$values = bz_core_mailpoet_values();
	$hash   = md5( wp_json_encode( $values ) . filemtime( $file ) . BZ_CORE_VERSION );
	if ( ! $force && get_option( 'bz_mailpoet_tpl_hash' ) === $hash ) {
		return 'Up to date.';
	}
	$body = bz_core_mailpoet_fill( isset( $tpl['body'] ) ? $tpl['body'] : array(), $values );
	$name = trim( $values['bz_firm'] . ' newsletter' );
	$id   = (int) get_option( 'bz_mailpoet_tpl_id' );
	$saved = bz_core_mailpoet_save_template( $id, $name, isset( $tpl['description'] ) ? (string) $tpl['description'] : 'In the firm\'s colours, logo and social links (Bizink).', $body, isset( $tpl['thumbnail'] ) ? (string) $tpl['thumbnail'] : '' );
	if ( is_wp_error( $saved ) ) {
		return $saved->get_error_message();
	}
	update_option( 'bz_mailpoet_tpl_id', (int) $saved, false );
	update_option( 'bz_mailpoet_tpl_hash', $hash, false );
	return 'Saved "' . $name . '" (template ' . (int) $saved . ').';
}

/* Create or update a saved template; MailPoet's own repository when available, else its table directly. */
function bz_core_mailpoet_save_template( $id, $name, $description, $body, $thumbnail ) {
	$data = array( 'name' => $name, 'description' => $description, 'categories' => '["saved","standard"]', 'body' => wp_json_encode( $body ), 'thumbnail' => $thumbnail, 'readonly' => false );
	if ( class_exists( '\MailPoet\DI\ContainerWrapper' ) && class_exists( '\MailPoet\NewsletterTemplates\NewsletterTemplatesRepository' ) ) {
		try {
			$repo = \MailPoet\DI\ContainerWrapper::getInstance()->get( \MailPoet\NewsletterTemplates\NewsletterTemplatesRepository::class );
			if ( $id ) {
				$data['id'] = $id;
				if ( ! $repo->findOneById( $id ) ) {
					unset( $data['id'] );
				}
			}
			$t = $repo->createOrUpdate( $data );
			return (int) $t->getId();
		} catch ( \Throwable $e ) { // fall through to the table
			update_option( 'bz_mailpoet_repo_error', $e->getMessage(), false );
		}
	}
	global $wpdb;
	$table = $wpdb->prefix . 'mailpoet_newsletter_templates';
	$cols  = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
	if ( ! $cols ) {
		return new WP_Error( 'bz_mailpoet_table', 'MailPoet\'s template table was not found.' );
	}
	$row = array_intersect_key(
		array( 'name' => $name, 'description' => $description, 'categories' => '["saved","standard"]', 'body' => wp_json_encode( $body ), 'thumbnail' => $thumbnail, 'readonly' => 0, 'updated_at' => current_time( 'mysql', true ) ),
		array_flip( $cols )
	);
	$exists = $id ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$table` WHERE id = %d", $id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
	if ( $exists ) {
		$wpdb->update( $table, $row, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $id;
	}
	if ( in_array( 'created_at', $cols, true ) ) {
		$row['created_at'] = current_time( 'mysql', true );
	}
	$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $wpdb->insert_id ? (int) $wpdb->insert_id : new WP_Error( 'bz_mailpoet_insert', 'Could not save the template: ' . $wpdb->last_error );
}

// When MailPoet is active: on admin visits (at most hourly), and straight after Client Details, the logo or the theme change.
add_action(
	'admin_init',
	function () {
		if ( ! bz_core_mailpoet_active() || ! current_user_can( 'manage_options' ) || get_transient( 'bz_mailpoet_checked' ) ) {
			return;
		}
		set_transient( 'bz_mailpoet_checked', 1, HOUR_IN_SECONDS );
		bz_core_mailpoet_sync();
	}
);
foreach ( array( 'update_option_' . BZ_CORE_OPTION, 'update_option_site_logo', 'bz_core_theme_changed' ) as $hook ) {
	add_action(
		$hook,
		function () {
			delete_transient( 'bz_mailpoet_checked' );
		}
	);
}
