<?php
/**
 * Handover: turn a COPY of a Bizink site into a plain WordPress site the client can keep (no Elementor, no
 * bizink-core, no Bizink content). Driven by tools/handover.py, which first rewrites every kept page as standard
 * WordPress blocks, then calls:
 *
 *   POST /wp-json/bizink/v1/handover/finish   (administrator; refuses unless "Discourage search engines" is on)
 *     { "palette": {...}, "fonts": {"heading": "...", "body": "..."}, "menu": [...], "footer_links": [...],
 *       "deactivate": true }
 *
 * which: installs and activates Twenty Twenty-Five with the firm's colours and fonts, writes its header (logo +
 * menu) and footer (the firm's details, legal links), creates the WPForms Lite contact form and puts it where pages
 * have [bz_handover_form], turns [bz ...] fields in posts into plain text, moves Bizink-owned content (downloads,
 * calculators, partners, business guides) to the trash, and deactivates Elementor, Elementor Pro, Gravity Forms and
 * bizink-core. Nothing is permanently deleted: emptying the trash is a step for a person.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bizink/v1',
			'/handover/check',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function () {
					return array( 'is_copy' => '0' === (string) get_option( 'blog_public' ), 'home' => home_url( '/' ), 'version' => BZ_CORE_VERSION );
				},
			)
		);
		register_rest_route(
			'bizink/v1',
			'/handover/finish',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) && current_user_can( 'install_themes' );
				},
				'callback'            => 'bz_core_handover_finish',
			)
		);
	}
);

function bz_core_handover_finish( WP_REST_Request $r ) {
	if ( '0' !== (string) get_option( 'blog_public' ) ) {
		return new WP_Error( 'bz_handover_live', 'Handover only runs on a copy of the site with "Discourage search engines" on (Settings → Reading).', array( 'status' => 409 ) );
	}
	$p    = (array) $r->get_json_params();
	$log  = array();
	$opts = get_option( BZ_CORE_OPTION, array() );

	// 1. Twenty Twenty-Five, installed if needed.
	if ( ! wp_get_theme( 'twentytwentyfive' )->exists() ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$api = themes_api( 'theme_information', array( 'slug' => 'twentytwentyfive', 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$up = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$ok = $up->install( $api->download_link );
		if ( ! $ok || is_wp_error( $ok ) ) {
			return new WP_Error( 'bz_handover_theme', 'Could not install Twenty Twenty-Five.', array( 'status' => 500 ) );
		}
		$log[] = 'Installed Twenty Twenty-Five.';
	}
	switch_theme( 'twentytwentyfive' );
	$log[] = 'Theme: Twenty Twenty-Five.';
	// Converted pages carry their own header (with the title), so they use the theme's "Page No Title" template.
	foreach ( isset( $p['pages'] ) ? array_map( 'intval', (array) $p['pages'] ) : array() as $page_id ) {
		update_post_meta( $page_id, '_wp_page_template', 'page-no-title' );
	}

	// 2. Colours and fonts (the theme's own palette slugs, so its styles and patterns follow the firm's colours).
	$pal  = isset( $p['palette'] ) ? (array) $p['palette'] : array();
	$swatches = array();
	foreach ( array( 'base' => 'Base', 'contrast' => 'Contrast', 'accent-1' => 'Brand', 'accent-2' => 'Brand dark', 'accent-3' => 'Accent', 'accent-4' => 'Muted text', 'accent-5' => 'Light background', 'accent-6' => 'Border' ) as $slug => $name ) {
		if ( ! empty( $pal[ $slug ] ) ) {
			$swatches[] = array( 'slug' => $slug, 'name' => $name, 'color' => sanitize_hex_color( $pal[ $slug ] ) );
		}
	}
	// fonts: {heading, body, faces: {"Family": [{weight, style, src}]}}; faces load from Google's servers via the
	// theme's own font settings, so they keep working with no plugin.
	$fonts    = isset( $p['fonts'] ) ? (array) $p['fonts'] : array();
	$faces    = isset( $fonts['faces'] ) ? (array) $fonts['faces'] : array();
	$families = array();
	foreach ( array_unique( array_filter( array( isset( $fonts['heading'] ) ? $fonts['heading'] : '', isset( $fonts['body'] ) ? $fonts['body'] : '' ) ) ) as $f ) {
		$fam = array( 'slug' => sanitize_title( $f ), 'name' => $f, 'fontFamily' => '"' . $f . '", ' . ( preg_match( '/serif|playfair|garamond|lora|merriweather|fraunces|libre|crimson/i', $f ) && ! preg_match( '/sans/i', $f ) ? 'serif' : 'sans-serif' ) );
		if ( ! empty( $faces[ $f ] ) ) {
			$fam['fontFace'] = array();
			foreach ( (array) $faces[ $f ] as $face ) {
				$fam['fontFace'][] = array( 'fontFamily' => $f, 'fontWeight' => (string) $face['weight'], 'fontStyle' => $face['style'], 'fontDisplay' => 'swap', 'src' => array( esc_url_raw( $face['src'] ) ) );
			}
		}
		$families[] = $fam;
	}
	$user = array(
		'version'                     => 3,
		'isGlobalStylesUserThemeJSON' => true,
		'settings'                    => array(
			'color'      => array( 'palette' => array( 'theme' => $swatches ) ),
			'typography' => $families ? array( 'fontFamilies' => array( 'theme' => $families ) ) : new stdClass(),
		),
		'styles'                      => array(
			'color'    => array( 'background' => 'var(--wp--preset--color--base)', 'text' => 'var(--wp--preset--color--contrast)' ),
			'elements' => array(
				'button'  => array(
					'color'  => array( 'background' => 'var(--wp--preset--color--accent-1)', 'text' => 'var(--wp--preset--color--base)' ),
					'border' => array( 'radius' => '6px' ),
				),
				'link'    => array( 'color' => array( 'text' => 'var(--wp--preset--color--accent-1)' ) ),
				'heading' => new stdClass(), // headings follow the text colour (white on dark bands)
			),
		),
	);
	if ( ! empty( $fonts['heading'] ) ) {
		$user['styles']['elements']['heading'] = array();
		$user['styles']['elements']['heading']['typography'] = array( 'fontFamily' => 'var(--wp--preset--font-family--' . sanitize_title( $fonts['heading'] ) . ')' );
	}
	if ( ! empty( $fonts['body'] ) ) {
		$user['styles']['typography'] = array( 'fontFamily' => 'var(--wp--preset--font-family--' . sanitize_title( $fonts['body'] ) . ')' );
	}
	$gs_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
	if ( $gs_id ) {
		wp_update_post( array( 'ID' => $gs_id, 'post_content' => wp_slash( wp_json_encode( $user ) ) ) );
		$log[] = 'Colours and fonts set.';
	}

	// 3. Header (logo + menu) and footer (firm details + legal links) as the theme's template parts.
	$nav_id = bz_core_handover_navigation( isset( $p['menu'] ) ? (array) $p['menu'] : array() );
	$firm   = ! empty( $opts['firm_name'] ) ? $opts['firm_name'] : get_bloginfo( 'name' );
	$header = '<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->'
		. '<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">'
		. '<!-- wp:group {"align":"wide","layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} --><div class="wp-block-group alignwide">'
		. ( get_theme_mod( 'custom_logo' ) || get_option( 'site_logo' ) ? '<!-- wp:site-logo {"width":180} /-->' : '<!-- wp:site-title {"level":0} /-->' )
		. '<!-- wp:navigation {"ref":' . (int) $nav_id . ',"overlayBackgroundColor":"base","overlayTextColor":"contrast","layout":{"type":"flex","justifyContent":"right"}} /-->'
		. '</div><!-- /wp:group --></div><!-- /wp:group -->';
	$lines  = array_filter(
		array(
			esc_html( $firm ),
			esc_html( trim( implode( ', ', array_filter( array( isset( $opts['address'] ) ? $opts['address'] : '', trim( ( isset( $opts['suburb'] ) ? $opts['suburb'] : '' ) . ' ' . ( isset( $opts['state'] ) ? $opts['state'] : '' ) . ' ' . ( isset( $opts['postcode'] ) ? $opts['postcode'] : '' ) ) ) ) ) ) ),
			! empty( $opts['phone'] ) ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $opts['phone'] ) ) . '">' . esc_html( $opts['phone'] ) . '</a>' : '',
			! empty( $opts['email'] ) ? '<a href="mailto:' . esc_attr( $opts['email'] ) . '">' . esc_html( $opts['email'] ) . '</a>' : '',
		)
	);
	$links = array();
	foreach ( isset( $p['footer_links'] ) ? (array) $p['footer_links'] : array() as $l ) {
		if ( ! empty( $l['url'] ) && ! empty( $l['label'] ) ) {
			$links[] = '<a href="' . esc_url( $l['url'] ) . '">' . esc_html( $l['label'] ) . '</a>';
		}
	}
	$note   = ! empty( $opts['compliance_note'] ) ? $opts['compliance_note'] : ( function_exists( 'bz_core_default_compliance' ) ? bz_core_default_compliance( isset( $opts['region'] ) ? $opts['region'] : 'AU' ) : '' );
	$footer = '<!-- wp:group {"align":"full","backgroundColor":"accent-2","textColor":"base","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}},"elements":{"link":{"color":{"text":"var:preset|color|base"}}}},"layout":{"type":"constrained"}} -->'
		. '<div class="wp-block-group alignfull has-base-color has-accent-2-background-color has-text-color has-background has-link-color" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">'
		. '<!-- wp:paragraph --><p>' . implode( '<br>', $lines ) . '</p><!-- /wp:paragraph -->'
		. ( $links ? '<!-- wp:paragraph --><p>' . implode( ' &nbsp;·&nbsp; ', $links ) . '</p><!-- /wp:paragraph -->' : '' )
		. ( $note ? '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size">' . esc_html( $note ) . '</p><!-- /wp:paragraph -->' : '' )
		. '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size">© ' . esc_html( gmdate( 'Y' ) . ' ' . $firm ) . '</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';
	bz_core_handover_template_part( 'header', 'Header', 'header', $header );
	bz_core_handover_template_part( 'footer', 'Footer', 'footer', $footer );
	$log[] = 'Header and footer written (menu id ' . (int) $nav_id . ').';

	// 4. Contact form (WPForms Lite) where pages have [bz_handover_form].
	$form_id = bz_core_handover_form( ! empty( $opts['email'] ) ? $opts['email'] : get_option( 'admin_email' ) );
	if ( $form_id ) {
		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft' ), 'numberposts' => -1, 's' => 'bz_handover_form' ) );
		foreach ( $pages as $pg ) {
			$c = str_replace( '[bz_handover_form]', '[wpforms id="' . $form_id . '" title="false"]', $pg->post_content );
			wp_update_post( array( 'ID' => $pg->ID, 'post_content' => wp_slash( $c ) ) );
		}
		$log[] = 'Contact form (WPForms) id ' . $form_id . ' on ' . count( $pages ) . ' page(s).';
	} else {
		$log[] = 'WPForms Lite is not active: contact form not created.';
	}

	// 5. [bz] fields in posts → plain text (the shortcodes stop working once bizink-core is off).
	$n = 0;
	foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 's' => '[bz' ) ) as $post ) {
		$c = preg_replace_callback( '/\[bz\b[^\]]*\]/', function ( $m ) { return do_shortcode( $m[0] ); }, $post->post_content );
		if ( $c !== $post->post_content ) {
			wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( $c ) ) );
			$n++;
		}
	}
	$log[] = $n . ' post(s) had Bizink fields turned into text.';

	// 6. Bizink-owned content to the trash (attachments of those items too).
	$trashed = 0;
	foreach ( array( 'bz_resource', 'bz_calculator', 'bz_partner', 'bz_guide' ) as $type ) {
		foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			foreach ( get_attached_media( '', $id ) as $att ) {
				wp_delete_attachment( $att->ID, false ); // to the trash where MEDIA_TRASH is on; otherwise listed below
			}
			wp_trash_post( $id );
			$trashed++;
		}
	}
	$log[] = $trashed . ' Bizink items (downloads, calculators, partners, guides) moved to the trash.';

	// 7. Plugins that need Bizink's licences or connection.
	if ( ! empty( $p['deactivate'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$off = array();
		foreach ( get_option( 'active_plugins', array() ) as $file ) {
			if ( preg_match( '#^(elementor-pro|elementor|gravityforms|bizink-core)/#', $file ) ) {
				$off[] = $file;
			}
		}
		deactivate_plugins( $off, true );
		$log[] = 'Deactivated: ' . implode( ', ', $off );
	}
	return array( 'ok' => true, 'log' => $log, 'form_id' => $form_id, 'navigation_id' => $nav_id );
}

/* Navigation block markup from [{label, url, children}] → a wp_navigation post. */
function bz_core_handover_navigation( $items ) {
	$markup = function ( $items ) use ( &$markup ) {
		$out = '';
		foreach ( $items as $it ) {
			$label = isset( $it['label'] ) ? sanitize_text_field( $it['label'] ) : '';
			$url   = isset( $it['url'] ) ? esc_url_raw( $it['url'] ) : '';
			$attrs = wp_json_encode( array( 'label' => $label, 'url' => $url ? $url : '#', 'kind' => 'custom', 'isTopLevelLink' => true ) );
			if ( ! empty( $it['children'] ) ) {
				$out .= '<!-- wp:navigation-submenu ' . $attrs . ' -->' . $markup( $it['children'] ) . '<!-- /wp:navigation-submenu -->';
			} else {
				$out .= '<!-- wp:navigation-link ' . $attrs . ' /-->';
			}
		}
		return $out;
	};
	$args = array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Main menu', 'post_content' => wp_slash( $markup( $items ) ) );
	$prev = get_posts( array( 'post_type' => 'wp_navigation', 'title' => 'Main menu', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
	return $prev ? wp_update_post( array_merge( $args, array( 'ID' => $prev[0] ) ) ) : wp_insert_post( $args );  // re-runs reuse it
}

/* A customised template part for the active theme (what the Site Editor saves). */
function bz_core_handover_template_part( $slug, $title, $area, $content ) {
	$theme = get_stylesheet();
	$found = get_posts( array( 'post_type' => 'wp_template_part', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'tax_query' => array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => $theme ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$args  = array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => wp_slash( $content ) );
	$id    = $found ? wp_update_post( array_merge( $args, array( 'ID' => $found[0]->ID ) ) ) : wp_insert_post( $args );
	wp_set_object_terms( $id, $theme, 'wp_theme' );
	wp_set_object_terms( $id, $area, 'wp_template_part_area' );
	return $id;
}

/* A WPForms Lite contact form (name, email, phone, message) sending to $email. Returns the form id or 0. */
function bz_core_handover_form( $email ) {
	if ( ! function_exists( 'wpforms' ) ) {
		return 0;
	}
	$forms = method_exists( wpforms(), 'obj' ) ? wpforms()->obj( 'form' ) : ( isset( wpforms()->form ) ? wpforms()->form : null ); // WPForms 1.8+ / older
	if ( ! $forms ) {
		return 0;
	}
	$existing = get_posts( array( 'post_type' => 'wpforms', 'title' => 'Contact', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
	if ( $existing ) {
		return (int) $existing[0];
	}
	$id = $forms->add( 'Contact' );
	if ( ! $id ) {
		return 0;
	}
	$data = array(
		'id'       => $id,
		'field_id' => 4,
		'fields'   => array(
			'0' => array( 'id' => '0', 'type' => 'name', 'label' => 'Your name', 'format' => 'simple', 'required' => '1', 'size' => 'large' ),
			'1' => array( 'id' => '1', 'type' => 'email', 'label' => 'Email', 'required' => '1', 'size' => 'large' ),
			'2' => array( 'id' => '2', 'type' => 'text', 'label' => 'Phone', 'size' => 'large' ),
			'3' => array( 'id' => '3', 'type' => 'textarea', 'label' => 'How can we help?', 'required' => '1', 'size' => 'large' ),
		),
		'settings' => array(
			'form_title'             => 'Contact',
			'submit_text'            => 'Send',
			'submit_text_processing' => 'Sending…',
			'honeypot'               => '1',
			'notification_enable'    => '1',
			'notifications'          => array(
				'1' => array(
					'email'          => sanitize_email( $email ),
					'subject'        => 'New website enquiry',
					'sender_name'    => get_bloginfo( 'name' ),
					'sender_address' => '{admin_email}',
					'replyto'        => '{field_id="1"}',
					'message'        => '{all_fields}',
				),
			),
			'confirmations'          => array(
				'1' => array( 'type' => 'message', 'message' => '<p>Thanks, we have your message and will be in touch soon.</p>', 'message_scroll' => '1' ),
			),
		),
		'meta'     => array( 'template' => 'blank' ),
	);
	$forms->update( $id, $data );
	return (int) $id;
}

