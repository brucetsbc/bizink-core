<?php
/**
 * Client agreements: signing links on the master (bizink-core 0.33.0).
 *
 * tools/agreement.py fills agreement/Bizink-Website-Services-Agreement.docx for a client, makes the PDF with Word and
 * uploads it here (POST /agreements). The client gets a private link /sign/?k=<token>: they read the PDF, type their
 * name and role, sign (drawn or typed) and tick to agree. The master records name, role, signature, time, IP, browser
 * and the PDF's SHA-256, and raises a ticket to production@ (BZ_INTAKE_EMAIL). agreement.py collect then stamps the
 * signature and a signing record into the PDF and uploads the signed copy (POST /agreements/<id>/signed), which the
 * master emails to the client and production@. Tools → Agreements lists them.
 *
 * Files live in uploads/bz-agreements/ under random 32-character names (never listed or linked publicly).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bz_core_agreements_enabled() {
	return function_exists( 'bz_core_is_master' ) && bz_core_is_master();
}

add_action(
	'init',
	function () {
		if ( ! bz_core_agreements_enabled() ) {
			return;
		}
		register_post_type(
			'bz_agreement',
			array(
				'labels'          => array( 'name' => 'Agreements', 'singular_name' => 'Agreement' ),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}
);

/* ---------- Helpers ---------- */

function bz_core_agreement_dir() {
	$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'bz-agreements';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return $dir;
}

function bz_core_agreement_save_pdf( $b64 ) {
	$data = base64_decode( (string) $b64, true );
	if ( ! $data || 0 !== strpos( $data, '%PDF' ) ) {
		return new WP_Error( 'bz_pdf', 'That is not a PDF.', array( 'status' => 400 ) );
	}
	$name = bin2hex( random_bytes( 16 ) ) . '.pdf';
	file_put_contents( bz_core_agreement_dir() . '/' . $name, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return array( 'file' => $name, 'hash' => hash( 'sha256', $data ) );
}

function bz_core_agreement_meta( $id ) {
	$m = json_decode( (string) get_post_meta( $id, 'bz_agreement', true ), true );
	return is_array( $m ) ? $m : array();
}

function bz_core_agreement_set( $id, array $changes ) {
	$m = array_merge( bz_core_agreement_meta( $id ), $changes );
	update_post_meta( $id, 'bz_agreement', wp_slash( wp_json_encode( $m ) ) );
	return $m;
}

function bz_core_agreement_by_token( $token ) {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	if ( strlen( $token ) < 20 ) {
		return null;
	}
	$posts = get_posts(
		array(
			'post_type'   => 'bz_agreement',
			'post_status' => 'any',
			'numberposts' => 1,
			'meta_key'    => 'bz_agreement_token_hash', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => hash( 'sha256', $token ), // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	return $posts ? $posts[0] : null;
}

function bz_core_agreement_link( $token ) {
	return home_url( '/sign/?k=' . rawurlencode( $token ) );
}

/* What the admin endpoints and agreement.py see. */
function bz_core_agreement_public( $post ) {
	$m = bz_core_agreement_meta( $post->ID );
	unset( $m['file'], $m['signed_file'] );
	return array( 'id' => $post->ID, 'firm' => $post->post_title, 'created' => $post->post_date_gmt ) + $m;
}

function bz_core_agreement_stream( $file, $download_name ) {
	$path = bz_core_agreement_dir() . '/' . basename( (string) $file );
	if ( ! $file || ! is_readable( $path ) ) {
		status_header( 404 );
		exit;
	}
	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $download_name ) . '"' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/* Ticket to production@ (same channel as client intake), email fallback. */
function bz_core_agreement_ticket( $subject, $body, $reply_to = '' ) {
	$to = defined( 'BZ_INTAKE_EMAIL' ) ? BZ_INTAKE_EMAIL : get_option( 'admin_email' );
	$ok = false;
	if ( defined( 'BZ_SUPPORT_ENDPOINT' ) && BZ_SUPPORT_ENDPOINT ) {
		$response = wp_remote_post(
			BZ_SUPPORT_ENDPOINT,
			array(
				'timeout' => 20,
				'body'    => array(
					'contactName'         => 'Bizink Production',
					'contactEmail'        => $to,
					'subject'             => $subject,
					'body'                => $body,
					'source'              => 'agreement',
					'wp_version'          => get_bloginfo( 'version' ),
					'bizink_core_version' => BZ_CORE_VERSION,
					'website'             => '',
				),
			)
		);
		$ok = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300;
	}
	if ( ! $ok ) {
		wp_mail( $to, $subject, $body, $reply_to ? array( 'Reply-To: ' . $reply_to ) : array() );
	}
}

function bz_core_agreement_client_ip() {
	foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $k ) {
		if ( ! empty( $_SERVER[ $k ] ) ) {
			$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) ) )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '';
}

/* ---------- REST ---------- */

add_action(
	'rest_api_init',
	function () {
		if ( ! bz_core_agreements_enabled() ) {
			return;
		}
		$admin = function () {
			return current_user_can( 'manage_options' );
		};

		// Create: {firm, slug, contact_name, contact_email, filename, pdf (base64)} → {id, link}.
		register_rest_route(
			'bizink/v1',
			'/agreements',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => function ( WP_REST_Request $r ) {
					$firm  = sanitize_text_field( (string) $r->get_param( 'firm' ) );
					$email = sanitize_email( (string) $r->get_param( 'contact_email' ) );
					if ( '' === $firm || ! is_email( $email ) ) {
						return new WP_Error( 'bz_agreement', 'firm and a valid contact_email are required.', array( 'status' => 400 ) );
					}
					$saved = bz_core_agreement_save_pdf( $r->get_param( 'pdf' ) );
					if ( is_wp_error( $saved ) ) {
						return $saved;
					}
					$id = wp_insert_post( array( 'post_type' => 'bz_agreement', 'post_status' => 'publish', 'post_title' => $firm ), true );
					if ( is_wp_error( $id ) ) {
						return $id;
					}
					$token = wp_generate_password( 32, false, false );
					update_post_meta( $id, 'bz_agreement_token_hash', hash( 'sha256', $token ) );
					bz_core_agreement_set(
						$id,
						array(
							'slug'          => sanitize_title( (string) $r->get_param( 'slug' ) ),
							'contact_name'  => sanitize_text_field( (string) $r->get_param( 'contact_name' ) ),
							'contact_email' => $email,
							'filename'      => sanitize_file_name( (string) ( $r->get_param( 'filename' ) ? $r->get_param( 'filename' ) : $firm . ' agreement.pdf' ) ),
							'file'          => $saved['file'],
							'hash'          => $saved['hash'],
							'status'        => 'sent',
						)
					);
					return array( 'id' => $id, 'link' => bz_core_agreement_link( $token ), 'hash' => $saved['hash'] );
				},
			)
		);

		// Details for agreement.py (status, signature record).
		register_rest_route(
			'bizink/v1',
			'/agreements/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function ( WP_REST_Request $r ) {
					$post = get_post( (int) $r['id'] );
					if ( ! $post || 'bz_agreement' !== $post->post_type ) {
						return new WP_Error( 'bz_agreement', 'No such agreement.', array( 'status' => 404 ) );
					}
					return bz_core_agreement_public( $post );
				},
			)
		);

		// Signed copy from agreement.py: {pdf (base64), send} → stored; emailed to the client and production@ when send.
		register_rest_route(
			'bizink/v1',
			'/agreements/(?P<id>\d+)/signed',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => function ( WP_REST_Request $r ) {
					$post = get_post( (int) $r['id'] );
					if ( ! $post || 'bz_agreement' !== $post->post_type ) {
						return new WP_Error( 'bz_agreement', 'No such agreement.', array( 'status' => 404 ) );
					}
					$m = bz_core_agreement_meta( $post->ID );
					if ( empty( $m['signature'] ) ) {
						return new WP_Error( 'bz_agreement', 'The client hasn\'t signed yet.', array( 'status' => 409 ) );
					}
					$saved = bz_core_agreement_save_pdf( $r->get_param( 'pdf' ) );
					if ( is_wp_error( $saved ) ) {
						return $saved;
					}
					$m    = bz_core_agreement_set( $post->ID, array( 'signed_file' => $saved['file'], 'signed_hash' => $saved['hash'], 'status' => 'complete', 'completed' => gmdate( 'c' ) ) );
					$sent = false;
					if ( $r->get_param( 'send' ) ) {
						$path    = bz_core_agreement_dir() . '/' . $saved['file'];
						$copy    = trailingslashit( get_temp_dir() ) . sanitize_file_name( preg_replace( '/\.pdf$/i', '', $m['filename'] ) . ' (signed).pdf' );
						copy( $path, $copy );
						$name    = $m['contact_name'] ? strtok( $m['contact_name'], ' ' ) : 'there';
						$to      = array( $m['contact_email'] );
						$cc      = defined( 'BZ_INTAKE_EMAIL' ) ? BZ_INTAKE_EMAIL : get_option( 'admin_email' );
						$sent    = wp_mail(
							$to,
							'Your signed Bizink agreement',
							"Hi {$name},\n\nThank you for signing. Your signed Bizink Website Services Agreement for {$post->post_title} is attached for your records.\n\nThe Bizink team",
							array( 'Cc: ' . $cc, 'Reply-To: ' . $cc ),
							array( $copy )
						);
						wp_delete_file( $copy );
						bz_core_agreement_set( $post->ID, array( 'emailed' => $sent ? gmdate( 'c' ) : '' ) );
					}
					return array( 'status' => 'complete', 'emailed' => (bool) $sent );
				},
			)
		);

		// Public, by token: what the signing page shows, the PDF itself, and the signature.
		register_rest_route(
			'bizink/v1',
			'/agreements/sign/(?P<token>[A-Za-z0-9]+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => function ( WP_REST_Request $r ) {
						$post = bz_core_agreement_by_token( $r['token'] );
						if ( ! $post ) {
							return new WP_Error( 'bz_agreement', 'This link is not valid. Please contact Bizink.', array( 'status' => 404 ) );
						}
						$m = bz_core_agreement_meta( $post->ID );
						return array(
							'firm'         => $post->post_title,
							'contact_name' => $m['contact_name'],
							'signed'       => ! empty( $m['signature'] ),
							'signed_at'    => ! empty( $m['signature']['signed_at'] ) ? $m['signature']['signed_at'] : '',
							'pdf'          => rest_url( 'bizink/v1/agreements/sign/' . rawurlencode( $r['token'] ) . '/pdf' ),
						);
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => function ( WP_REST_Request $r ) {
						$post = bz_core_agreement_by_token( $r['token'] );
						if ( ! $post ) {
							return new WP_Error( 'bz_agreement', 'This link is not valid. Please contact Bizink.', array( 'status' => 404 ) );
						}
						$m = bz_core_agreement_meta( $post->ID );
						if ( ! empty( $m['signature'] ) ) {
							return new WP_Error( 'bz_agreement', 'This agreement has already been signed.', array( 'status' => 409 ) );
						}
						$name  = sanitize_text_field( (string) $r->get_param( 'name' ) );
						$role  = sanitize_text_field( (string) $r->get_param( 'role' ) );
						$image = (string) $r->get_param( 'signature' );
						if ( '' === $name || '' === $role || ! $r->get_param( 'agree' ) ) {
							return new WP_Error( 'bz_agreement', 'Please enter your name and role and tick the box to agree.', array( 'status' => 400 ) );
						}
						if ( ! preg_match( '#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $image, $mm ) || strlen( $mm[1] ) > 400000 || ! base64_decode( $mm[1], true ) ) {
							return new WP_Error( 'bz_agreement', 'Please sign in the box (or type your signature).', array( 'status' => 400 ) );
						}
						$signature = array(
							'name'       => $name,
							'role'       => $role,
							'method'     => 'typed' === $r->get_param( 'method' ) ? 'typed' : 'drawn',
							'image'      => $image,
							'signed_at'  => gmdate( 'c' ),
							'ip'         => bz_core_agreement_client_ip(),
							'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 ) : '',
							'hash'       => $m['hash'],
						);
						bz_core_agreement_set( $post->ID, array( 'signature' => $signature, 'status' => 'signed' ) );
						bz_core_agreement_ticket(
							'Agreement signed: ' . $post->post_title,
							sprintf(
								"%s has signed the Bizink Website Services Agreement.\n\nSigned by: %s, %s\nAt: %s (UTC)\nFrom IP: %s\n\nNext: run\n  python C:\\www\\site-builder\\bizink-core\\tools\\agreement.py collect --slug %s\nto stamp the signature into the PDF and email the signed copy.\n\nTools → Agreements: %s",
								$post->post_title,
								$name,
								$role,
								$signature['signed_at'],
								$signature['ip'],
								$m['slug'],
								admin_url( 'tools.php?page=bz-agreements' )
							),
							$m['contact_email']
						);
						return array( 'signed' => true, 'signed_at' => $signature['signed_at'] );
					},
				),
			)
		);
	}
);

/* The PDF for the signing page, and the signing page itself (/sign/). */
add_action(
	'template_redirect',
	function () {
		if ( ! bz_core_agreements_enabled() ) {
			return;
		}
		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'sign' !== $path ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/assets/agreement/sign.html';
		if ( ! is_readable( $file ) ) {
			return;
		}
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		echo str_replace( 'data-api=""', 'data-api="' . esc_url( rest_url( 'bizink/v1/agreements/sign/' ) ) . '"', $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- static file.
		exit;
	},
	1
);

// The PDF goes through REST (so it works with pretty permalinks off) but is sent as a file, not JSON.
add_filter(
	'rest_pre_dispatch',
	function ( $result, $server, $request ) {
		if ( 'GET' !== $request->get_method() || ! preg_match( '#^/bizink/v1/agreements/sign/([A-Za-z0-9]+)/pdf$#', $request->get_route(), $m ) ) {
			return $result;
		}
		$post = bz_core_agreement_by_token( $m[1] );
		if ( ! $post ) {
			return new WP_Error( 'bz_agreement', 'This link is not valid.', array( 'status' => 404 ) );
		}
		$meta = bz_core_agreement_meta( $post->ID );
		bz_core_agreement_stream( ! empty( $meta['signed_file'] ) ? $meta['signed_file'] : $meta['file'], $meta['filename'] );
		return $result;
	},
	10,
	3
);

/* ---------- Admin: Tools → Agreements ---------- */

add_action(
	'admin_menu',
	function () {
		if ( bz_core_agreements_enabled() ) {
			add_management_page( 'Agreements', 'Agreements', 'manage_options', 'bz-agreements', 'bz_core_render_agreements' );
		}
	}
);

add_action(
	'admin_post_bz_agreement_file',
	function () {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'bz_agreement_file_' . $id ) ) {
			wp_die( 'Not allowed.' );
		}
		$m      = bz_core_agreement_meta( $id );
		$signed = ! empty( $_GET['signed'] ) && ! empty( $m['signed_file'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		bz_core_agreement_stream( $signed ? $m['signed_file'] : $m['file'], $signed ? preg_replace( '/\.pdf$/i', '', $m['filename'] ) . ' (signed).pdf' : $m['filename'] );
	}
);

function bz_core_render_agreements() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$posts  = get_posts( array( 'post_type' => 'bz_agreement', 'post_status' => 'any', 'numberposts' => 200 ) );
	$labels = array( 'sent' => 'Waiting for signature', 'signed' => 'Signed: stamp and send the copy', 'complete' => 'Complete' );
	echo '<div class="wrap"><h1>Agreements</h1><p>Made and uploaded by <code>tools\agreement.py make --slug &lt;client&gt;</code>; each has a private signing link (shown when it is made). When a client signs you get a ticket; then run <code>agreement.py collect --slug &lt;client&gt;</code>.</p>';
	echo '<table class="widefat striped"><thead><tr><th>Firm</th><th>Contact</th><th>Status</th><th>Created</th><th>Signed</th><th>Files</th></tr></thead><tbody>';
	if ( ! $posts ) {
		echo '<tr><td colspan="6">None yet.</td></tr>';
	}
	foreach ( $posts as $p ) {
		$m   = bz_core_agreement_meta( $p->ID );
		$sig = isset( $m['signature'] ) ? $m['signature'] : array();
		$url = function ( $signed ) use ( $p ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=bz_agreement_file&id=' . $p->ID . ( $signed ? '&signed=1' : '' ) ), 'bz_agreement_file_' . $p->ID );
		};
		echo '<tr><td><strong>' . esc_html( $p->post_title ) . '</strong></td><td>' . esc_html( trim( ( isset( $m['contact_name'] ) ? $m['contact_name'] : '' ) . ' <' . ( isset( $m['contact_email'] ) ? $m['contact_email'] : '' ) . '>' ) ) . '</td>';
		echo '<td>' . esc_html( isset( $labels[ $m['status'] ] ) ? $labels[ $m['status'] ] : $m['status'] ) . ( ! empty( $m['emailed'] ) ? '<br><span class="description">Signed copy emailed</span>' : '' ) . '</td>';
		echo '<td>' . esc_html( get_date_from_gmt( $p->post_date_gmt, 'j M Y' ) ) . '</td>';
		echo '<td>' . ( $sig ? esc_html( $sig['name'] . ', ' . $sig['role'] ) . '<br><span class="description">' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $sig['signed_at'] ) ), 'j M Y g:ia' ) . ' · ' . $sig['ip'] ) . '</span>' : '' ) . '</td>';
		echo '<td><a href="' . esc_url( $url( false ) ) . '">Agreement</a>' . ( ! empty( $m['signed_file'] ) ? ' · <a href="' . esc_url( $url( true ) ) . '">Signed copy</a>' : '' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}
