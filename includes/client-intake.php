<?php
/**
 * Client intake (master only): a private link per new firm to a friendly form where they enter their own details,
 * so the Site builder doesn't have to be filled in by hand.
 *
 *   Client intake → New intake link: firm name, contact, current website → a link /welcome/?k=<token> to send them.
 *   The firm fills in the form (saved as they go, uploads for logo, team photos and partner logos) and submits.
 *   On submit a support ticket goes to Bizink (same channel as Change requests) and the entry shows "Submitted".
 *   Download JSON → in the Site builder, New site → Import intake.
 *
 * The form is a self-contained page (assets/intake/index.html) talking to /wp-json/bizink/v1/intake/<token>. The
 * master serves it at /welcome/; the same file can be put on another domain later (set data-api on its <body>), as the
 * API answers any origin. Uploaded files stay in the master's media library; the JSON links to them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bz_core_intake_enabled() {
	return function_exists( 'bz_core_is_master' ) && bz_core_is_master();
}

add_action(
	'init',
	function () {
		if ( ! bz_core_intake_enabled() ) {
			return;
		}
		register_post_type(
			'bz_intake',
			array(
				'labels'          => array(
					'name'          => 'Client intake',
					'singular_name' => 'Client intake',
					'all_items'     => 'All intakes',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'menu_position'   => 24,
				'menu_icon'       => 'dashicons-id-alt',
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}
);

/* ---------- Helpers ---------- */

function bz_core_intake_by_token( $token ) {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	if ( strlen( $token ) < 20 ) {
		return null;
	}
	$posts = get_posts(
		array(
			'post_type'   => 'bz_intake',
			'post_status' => 'any',
			'numberposts' => 1,
			'meta_key'    => 'bz_intake_token_hash', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => hash( 'sha256', $token ), // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	return $posts ? $posts[0] : null;
}

function bz_core_intake_data( $post_id ) {
	$d = json_decode( (string) get_post_meta( $post_id, 'bz_intake_data', true ), true );
	return is_array( $d ) ? $d : array();
}

function bz_core_intake_status( $post_id ) {
	$s = (string) get_post_meta( $post_id, 'bz_intake_status', true );
	return $s ? $s : 'invited';
}

function bz_core_intake_url( $token ) {
	return home_url( '/welcome/?k=' . rawurlencode( $token ) );
}

/* Options the form offers: partners (the master's list + Sage/Reckon) and the themes with thumbnails. */
function bz_core_intake_options() {
	$partners = array();
	foreach ( get_posts( array( 'post_type' => 'bz_partner', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
		$types      = wp_get_object_terms( $p->ID, 'bz_partner_type', array( 'fields' => 'names' ) );
		$partners[] = array(
			'name' => html_entity_decode( $p->post_title, ENT_QUOTES ),
			'type' => ( ! is_wp_error( $types ) && $types ) ? $types[0] : 'Other',
		);
	}
	foreach ( array( 'Sage', 'Reckon' ) as $n ) {
		$partners[] = array( 'name' => $n, 'type' => 'Software' );
	}
	$themes = array();
	if ( function_exists( 'bz_core_site_themes' ) ) {
		foreach ( bz_core_site_themes() as $slug => $t ) {
			$themes[] = array(
				'slug'  => $slug,
				'name'  => preg_replace( '/^Theme \d+\s*[—–-]\s*/u', '', $t['name'] ),
				'about' => isset( $t['description'] ) ? wp_trim_words( preg_replace( '/\s*Signature blocks:.*$/s', '', $t['description'] ), 16, '…' ) : '',
				'thumb' => file_exists( dirname( __DIR__ ) . '/themes/thumbs/' . $slug . '.jpg' ) ? plugins_url( 'themes/thumbs/' . $slug . '.jpg', dirname( __FILE__ ) ) : '',
			);
		}
	}
	return array( 'partners' => $partners, 'themes' => $themes, 'regions' => bz_core_regions() );
}

/* Keep only plain values, to a sensible size: strings (trimmed), lists and small objects. */
function bz_core_intake_clean( $value, $depth = 0 ) {
	if ( $depth > 4 ) {
		return null;
	}
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( array_slice( $value, 0, 100, true ) as $k => $v ) {
			$out[ is_int( $k ) ? $k : sanitize_key( $k ) ] = bz_core_intake_clean( $v, $depth + 1 );
		}
		return $out;
	}
	if ( is_bool( $value ) || is_int( $value ) ) {
		return $value;
	}
	return mb_substr( sanitize_textarea_field( (string) $value ), 0, 4000 );
}

/* ---------- Public API (token in the URL; any origin, so the form can live on another domain later) ---------- */

add_action(
	'rest_api_init',
	function () {
		if ( ! bz_core_intake_enabled() ) {
			return;
		}
		$load = function ( WP_REST_Request $r ) {
			$post = bz_core_intake_by_token( $r['token'] );
			if ( ! $post ) {
				return new WP_Error( 'bz_intake_link', 'This link isn\'t valid any more. Please ask Bizink for a new one.', array( 'status' => 404 ) );
			}
			return $post;
		};
		register_rest_route(
			'bizink/v1',
			'/intake/(?P<token>[A-Za-z0-9]+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => function ( WP_REST_Request $r ) use ( $load ) {
						$post = $load( $r );
						if ( is_wp_error( $post ) ) {
							return $post;
						}
						return array(
							'firm'    => $post->post_title,
							'status'  => bz_core_intake_status( $post->ID ),
							'data'    => (object) bz_core_intake_data( $post->ID ),
							'options' => bz_core_intake_options(),
						);
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => function ( WP_REST_Request $r ) use ( $load ) {
						$post = $load( $r );
						if ( is_wp_error( $post ) ) {
							return $post;
						}
						if ( 'submitted' === bz_core_intake_status( $post->ID ) ) {
							return new WP_Error( 'bz_intake_done', 'These details have already been sent to Bizink. Contact us to change anything.', array( 'status' => 409 ) );
						}
						$data = bz_core_intake_clean( (array) $r->get_param( 'data' ) );
						if ( strlen( wp_json_encode( $data ) ) > 200000 ) {
							return new WP_Error( 'bz_intake_size', 'That is too much text to save.', array( 'status' => 413 ) );
						}
						update_post_meta( $post->ID, 'bz_intake_data', wp_slash( wp_json_encode( $data ) ) );
						update_post_meta( $post->ID, 'bz_intake_saved', time() );
						if ( 'invited' === bz_core_intake_status( $post->ID ) ) {
							update_post_meta( $post->ID, 'bz_intake_status', 'in_progress' );
						}
						if ( $r['submit'] ) {
							update_post_meta( $post->ID, 'bz_intake_status', 'submitted' );
							update_post_meta( $post->ID, 'bz_intake_submitted', time() );
							bz_core_intake_notify( $post );
						}
						return array( 'ok' => true, 'status' => bz_core_intake_status( $post->ID ) );
					},
				),
			)
		);
		register_rest_route(
			'bizink/v1',
			'/intake/(?P<token>[A-Za-z0-9]+)/upload',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $r ) use ( $load ) {
					$post = $load( $r );
					if ( is_wp_error( $post ) ) {
						return $post;
					}
					$count = (int) get_post_meta( $post->ID, 'bz_intake_uploads', true );
					if ( $count >= 40 ) {
						return new WP_Error( 'bz_intake_uploads', 'That is the most files this form can take. Email any others to Bizink.', array( 'status' => 429 ) );
					}
					$files = $r->get_file_params();
					if ( empty( $files['file'] ) || ! empty( $files['file']['error'] ) ) {
						return new WP_Error( 'bz_intake_file', 'No file came through. Please try again.', array( 'status' => 400 ) );
					}
					$f    = $files['file'];
					$type = wp_check_filetype_and_ext( $f['tmp_name'], $f['name'], array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' ) );
					if ( empty( $type['type'] ) || (int) $f['size'] > 8 * MB_IN_BYTES ) {
						return new WP_Error( 'bz_intake_type', 'Please upload a JPG, PNG, WebP or GIF image under 8 MB (SVG logos can be emailed to Bizink).', array( 'status' => 415 ) );
					}
					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';
					$f['name'] = sanitize_title( $post->post_title ) . '-' . sanitize_file_name( $f['name'] );
					$id        = media_handle_sideload( $f, $post->ID, sanitize_text_field( (string) $r['label'] ) );
					if ( is_wp_error( $id ) ) {
						return $id;
					}
					update_post_meta( $post->ID, 'bz_intake_uploads', $count + 1 );
					return array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) );
				},
			)
		);
	}
);

// Let the form page call the intake API from another domain.
add_filter(
	'rest_pre_serve_request',
	function ( $served, $result, $request ) {
		if ( 0 === strpos( $request->get_route(), '/bizink/v1/intake/' ) ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type' );
		}
		return $served;
	},
	10,
	3
);

if ( ! defined( 'BZ_INTAKE_EMAIL' ) ) {
	define( 'BZ_INTAKE_EMAIL', 'production@bizinkonline.com' ); // Intake tickets are raised as (and fall back to) the production inbox.
}

/* Ticket to Bizink support when a firm submits, from the production inbox (email fallback to the same inbox). */
function bz_core_intake_notify( $post ) {
	$d       = bz_core_intake_data( $post->ID );
	$firm    = ! empty( $d['firm'] ) ? $d['firm'] : $post->post_title;
	$contact = trim( ( isset( $d['contact_name'] ) ? $d['contact_name'] : '' ) );
	$email   = isset( $d['contact_email'] ) && is_email( $d['contact_email'] ) ? $d['contact_email'] : ( isset( $d['email'] ) && is_email( $d['email'] ) ? $d['email'] : get_option( 'admin_email' ) );
	$team    = isset( $d['team'] ) && is_array( $d['team'] ) ? count( $d['team'] ) : 0;
	$body    = sprintf( "%s has filled in their website details.\n\nWebsite: %s\nContact: %s %s\nTeam members: %d\nPreferred look: %s\n\nDownload the JSON for the Site builder (New site → Import intake):\n%s",
		$firm,
		isset( $d['old'] ) ? $d['old'] : '',
		$contact,
		'<' . $email . '>',
		$team,
		isset( $d['theme'] ) ? $d['theme'] : 'no preference',
		admin_url( 'edit.php?post_type=bz_intake' )
	);
	$subject = 'Client intake ready: ' . $firm;
	$ok      = false;
	if ( defined( 'BZ_SUPPORT_ENDPOINT' ) && BZ_SUPPORT_ENDPOINT ) {
		$response = wp_remote_post(
			BZ_SUPPORT_ENDPOINT,
			array(
				'timeout' => 20,
				'body'    => array(
					'contactName'         => 'Bizink Production',
					'contactEmail'        => BZ_INTAKE_EMAIL,
					'subject'             => $subject,
					'body'                => $body,
					'site_domain'         => isset( $d['old'] ) ? (string) wp_parse_url( ( false === strpos( $d['old'], '://' ) ? 'https://' : '' ) . $d['old'], PHP_URL_HOST ) : '',
					'firm'                => $firm,
					'site_url'            => isset( $d['old'] ) ? $d['old'] : '',
					'source'              => 'client-intake',
					'wp_version'          => get_bloginfo( 'version' ),
					'bizink_core_version' => BZ_CORE_VERSION,
					'website'             => '',
				),
			)
		);
		$ok = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300;
	}
	if ( ! $ok ) {
		wp_mail( BZ_INTAKE_EMAIL, $subject, $body, array( 'Reply-To: ' . $email ) );
	}
}

/* The JSON for the Site builder: tool "details" keys, plus files and extras. */
function bz_core_intake_export( $post ) {
	$d    = bz_core_intake_data( $post->ID );
	$list = function ( $v ) {
		return is_array( $v ) ? array_values( array_filter( array_map( 'trim', $v ) ) ) : array_values( array_filter( array_map( 'trim', explode( ',', (string) $v ) ) ) );
	};
	$details = array();
	foreach ( array( 'firm', 'descriptor', 'country', 'founded', 'street', 'suburb', 'city', 'region', 'region_code', 'postcode', 'phone', 'phone_intl', 'email', 'hours', 'map_query', 'offer', 'offer_sentence', 'booking_url', 'portal_url', 'footer_blurb', 'old', 'live', 'theme', 'ga4_measurement_id', 'ga4_property_id', 'search_console', 'contact_name', 'contact_email', 'contact_role', 'legal_name', 'company_number', 'billing_email' ) as $k ) {
		if ( isset( $d[ $k ] ) && '' !== $d[ $k ] ) {
			$details[ $k ] = $d[ $k ];
		}
	}
	foreach ( array( 'specialities', 'areas' ) as $k ) {
		if ( ! empty( $d[ $k ] ) ) {
			$details[ $k ] = $list( $d[ $k ] );
		}
	}
	if ( ! empty( $d['partners'] ) ) {
		$details['partners'] = $list( $d['partners'] );
	}
	if ( ! empty( $d['extra_partners'] ) && is_array( $d['extra_partners'] ) ) {
		$details['extra_partners'] = array_values( array_filter( $d['extra_partners'], function ( $x ) { return ! empty( $x['name'] ); } ) );
	}
	if ( ! empty( $d['team'] ) && is_array( $d['team'] ) ) {
		$details['team'] = array_values( array_filter( $d['team'], function ( $x ) { return ! empty( $x['name'] ); } ) );
	}
	$details['notes'] = trim( 'From the client intake form, submitted ' . ( get_post_meta( $post->ID, 'bz_intake_submitted', true ) ? wp_date( 'j M Y', (int) get_post_meta( $post->ID, 'bz_intake_submitted', true ) ) : '(not yet submitted)' ) . '.'
		. ( ! empty( $d['notes'] ) ? "\n" . $d['notes'] : '' ) );
	return array(
		'bizink_intake' => 1,
		'exported'      => gmdate( 'c' ),
		'status'        => bz_core_intake_status( $post->ID ),
		'details'       => $details,
		'files'         => array(
			'logo'    => isset( $d['logo'] ) ? $d['logo'] : '',
			'favicon' => isset( $d['favicon'] ) ? $d['favicon'] : '',
		),
		'testimonials'  => isset( $d['testimonials'] ) && is_array( $d['testimonials'] ) ? array_values( array_filter( $d['testimonials'], function ( $x ) { return ! empty( $x['quote'] ); } ) ) : array(),
		'social'        => (object) ( isset( $d['social'] ) && is_array( $d['social'] ) ? $d['social'] : array() ),
		'brand_colours' => isset( $d['brand_colours'] ) ? $d['brand_colours'] : '',
	);
}

/* ---------- The form page: /welcome/ on the master ---------- */

add_action(
	'template_redirect',
	function () {
		if ( ! bz_core_intake_enabled() ) {
			return;
		}
		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'welcome' !== $path ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/assets/intake/index.html';
		if ( ! is_readable( $file ) ) {
			return;
		}
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		echo str_replace( 'data-api=""', 'data-api="' . esc_url( rest_url( 'bizink/v1/intake/' ) ) . '"', $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- static file.
		exit;
	},
	1
);

/* ---------- Admin: new links, list, download ---------- */

add_action(
	'admin_menu',
	function () {
		if ( bz_core_intake_enabled() ) {
			add_submenu_page( 'edit.php?post_type=bz_intake', 'New intake link', 'New intake link', 'manage_options', 'bz-intake-new', 'bz_core_render_intake_new' );
		}
	}
);

function bz_core_render_intake_new() {
	$new = get_transient( 'bz_intake_new_' . get_current_user_id() );
	delete_transient( 'bz_intake_new_' . get_current_user_id() );
	echo '<div class="wrap"><h1>New intake link</h1><p>Creates a private link for a new client to enter their own details. Send it to them; you\'ll get a support ticket when they submit.</p>';
	if ( $new ) {
		echo '<div class="notice notice-success"><p><strong>Link for ' . esc_html( $new[0] ) . ':</strong></p><p><input type="text" readonly class="large-text code" value="' . esc_attr( $new[1] ) . '" onclick="this.select()"></p><p>It is shown again on the intake\'s row under Client intake.</p></div>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'bz_intake_new' );
	echo '<input type="hidden" name="action" value="bz_intake_new"><table class="form-table" role="presentation">'
		. '<tr><th><label for="bzi-firm">Firm name</label></th><td><input id="bzi-firm" name="firm" class="regular-text" required></td></tr>'
		. '<tr><th><label for="bzi-contact">Contact name</label></th><td><input id="bzi-contact" name="contact_name" class="regular-text"></td></tr>'
		. '<tr><th><label for="bzi-email">Contact email</label></th><td><input id="bzi-email" name="contact_email" type="email" class="regular-text"></td></tr>'
		. '<tr><th><label for="bzi-old">Current website</label></th><td><input id="bzi-old" name="old" type="url" class="regular-text" placeholder="https://"></td></tr>'
		. '<tr><th><label for="bzi-country">Country</label></th><td><select id="bzi-country" name="country">';
	foreach ( bz_core_regions() as $code => $label ) {
		echo '<option value="' . esc_attr( strtolower( $code ) ) . '">' . esc_html( $label ) . '</option>';
	}
	echo '</select></td></tr></table>';
	submit_button( 'Create link' );
	echo '</form></div>';
}

add_action(
	'admin_post_bz_intake_new',
	function () {
		check_admin_referer( 'bz_intake_new' );
		if ( ! current_user_can( 'manage_options' ) || ! bz_core_intake_enabled() ) {
			wp_die( 'Not allowed.' );
		}
		$firm  = sanitize_text_field( wp_unslash( isset( $_POST['firm'] ) ? $_POST['firm'] : '' ) );
		$token = wp_generate_password( 32, false );
		$id    = wp_insert_post( array( 'post_type' => 'bz_intake', 'post_status' => 'publish', 'post_title' => $firm ? $firm : 'New client' ) );
		$data  = array( 'firm' => $firm );
		foreach ( array( 'contact_name', 'contact_email', 'old', 'country' ) as $k ) {
			if ( ! empty( $_POST[ $k ] ) ) {
				$data[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}
		update_post_meta( $id, 'bz_intake_token_hash', hash( 'sha256', $token ) );
		update_post_meta( $id, 'bz_intake_link', bz_core_intake_url( $token ) ); // Kept so the link can be copied again; the hash is what's checked.
		update_post_meta( $id, 'bz_intake_status', 'invited' );
		update_post_meta( $id, 'bz_intake_data', wp_slash( wp_json_encode( $data ) ) );
		set_transient( 'bz_intake_new_' . get_current_user_id(), array( $firm, bz_core_intake_url( $token ) ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=bz_intake&page=bz-intake-new' ) );
		exit;
	}
);

add_action(
	'admin_post_bz_intake_json',
	function () {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'bz_intake_json_' . $id );
		if ( ! current_user_can( 'manage_options' ) || 'bz_intake' !== get_post_type( $id ) ) {
			wp_die( 'Not allowed.' );
		}
		$post = get_post( $id );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="intake-' . sanitize_title( $post->post_title ) . '.json"' );
		echo wp_json_encode( bz_core_intake_export( $post ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
);

add_action(
	'admin_post_bz_intake_reopen',
	function () {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'bz_intake_reopen_' . $id );
		if ( ! current_user_can( 'manage_options' ) || 'bz_intake' !== get_post_type( $id ) ) {
			wp_die( 'Not allowed.' );
		}
		update_post_meta( $id, 'bz_intake_status', 'in_progress' );
		wp_safe_redirect( admin_url( 'edit.php?post_type=bz_intake' ) );
		exit;
	}
);

add_filter(
	'manage_bz_intake_posts_columns',
	function ( $cols ) {
		return array(
			'cb'         => $cols['cb'],
			'title'      => 'Firm',
			'bzi_status' => 'Status',
			'bzi_link'   => 'Link to send',
			'bzi_get'    => 'For the Site builder',
		);
	}
);

add_action(
	'manage_bz_intake_posts_custom_column',
	function ( $col, $id ) {
		if ( 'bzi_status' === $col ) {
			$s     = bz_core_intake_status( $id );
			$label = array( 'invited' => 'Link sent, not started', 'in_progress' => 'In progress', 'submitted' => 'Submitted' )[ $s ];
			$when  = (int) get_post_meta( $id, 'submitted' === $s ? 'bz_intake_submitted' : 'bz_intake_saved', true );
			echo '<strong style="color:' . esc_attr( 'submitted' === $s ? '#15803d' : ( 'in_progress' === $s ? '#b45309' : '#646970' ) ) . '">' . esc_html( $label ) . '</strong>'
				. ( $when ? '<br><span style="color:#646970">' . esc_html( wp_date( 'j M Y, g:ia', $when ) ) . '</span>' : '' );
		} elseif ( 'bzi_link' === $col ) {
			$link = (string) get_post_meta( $id, 'bz_intake_link', true );
			echo $link ? '<input type="text" readonly class="code" style="width:100%" value="' . esc_attr( $link ) . '" onclick="this.select()">' : '';
		} elseif ( 'bzi_get' === $col ) {
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bz_intake_json&id=' . $id ), 'bz_intake_json_' . $id ) ) . '">Download JSON</a>';
			if ( 'submitted' === bz_core_intake_status( $id ) ) {
				echo ' <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bz_intake_reopen&id=' . $id ), 'bz_intake_reopen_' . $id ) ) . '">Let them edit again</a>';
			}
		}
	},
	10,
	2
);
