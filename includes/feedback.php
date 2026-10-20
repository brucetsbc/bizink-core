<?php
/**
 * Client feedback (roadmap M3a): clients review the staging site through a private link, with no WordPress login.
 *
 * Flow: create a review link (Client feedback → Review links) and send it to the client. Opening it sets a cookie,
 * after which every page shows a "Suggest a change" bar: click an element, type the change, send. Clients can also
 * approve a page or the whole site. Each comment is a bz_feedback post recording the page, the clicked element's
 * Elementor ID, the document holding it (page, header/footer, or a shared component) and its ancestors.
 * tools/feedback.py pulls open items into site-builder/clients/<slug>/requests/, and replies/marks them done.
 *
 * Change requests (0.15): suggestions are saved but not announced. "Send to Bizink" in the bar sends the reviewer's
 * unsent items as ONE support ticket (BZ_SUPPORT_ENDPOINT, source "site-review"). A daily safety net sends client
 * items left unsent for 24 hours. If the ticket service fails, it emails BZ_SUPPORT_EMAIL when that is defined.
 *
 * Elementor Pro Notes was tried first: it only loads for users who can edit the page, so it can't serve clients.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BZ_FEEDBACK_COOKIE', 'bz_review' );

function bz_core_feedback_statuses() {
	return array(
		'open'     => 'Open',
		'done'     => 'Done',
		'declined' => 'Not doing',
	);
}

add_action(
	'init',
	function () {
		register_post_type(
			'bz_feedback',
			array(
				'labels'          => array(
					'name'          => 'Change requests',
					'singular_name' => 'Change request',
					'all_items'     => 'All change requests',
					'menu_name'     => 'Change requests',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_rest'    => false,
				'menu_position'   => 22,
				'menu_icon'       => 'dashicons-format-chat',
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}
);

/* ---------- Review links ---------- */

function bz_core_review_links() {
	return get_option( 'bz_review_links', array() );
}

/* The review link in use for this request (from ?bz_review= or the cookie), or null. */
function bz_core_reviewer() {
	static $found = false;
	if ( false !== $found ) {
		return $found;
	}
	$token = '';
	if ( isset( $_GET['bz_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential.
		$token = sanitize_text_field( wp_unslash( $_GET['bz_review'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	} elseif ( isset( $_COOKIE[ BZ_FEEDBACK_COOKIE ] ) ) {
		$token = sanitize_text_field( wp_unslash( $_COOKIE[ BZ_FEEDBACK_COOKIE ] ) );
	}
	$found = null;
	if ( $token ) {
		$links = bz_core_review_links();
		$key   = hash( 'sha256', $token );
		if ( isset( $links[ $key ] ) && empty( $links[ $key ]['revoked'] ) ) {
			$found = $links[ $key ] + array( 'key' => $key );
		}
	}
	return $found;
}

/* Opening a review link: remember it in a cookie (60 days) and drop the token from the address bar. */
add_action(
	'template_redirect',
	function () {
		if ( ! isset( $_GET['bz_review'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['bz_review'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( bz_core_reviewer() ) {
			setcookie( BZ_FEEDBACK_COOKIE, $token, time() + 60 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false ); // readable by the page loader
		}
		wp_safe_redirect( remove_query_arg( 'bz_review' ) );
		exit;
	}
);

/* Who can use the feedback bar: a review-link holder, or a logged-in editor (to see and test it). */
function bz_core_feedback_actor() {
	$reviewer = bz_core_reviewer();
	if ( $reviewer ) {
		return array(
			'type'  => 'client',
			'name'  => $reviewer['name'],
			'email' => $reviewer['email'],
			'key'   => $reviewer['key'],
		);
	}
	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		$u = wp_get_current_user();
		return array(
			'type'  => 'team',
			'name'  => $u->display_name,
			'email' => $u->user_email,
			'key'   => 'user:' . $u->ID,
		);
	}
	return null;
}

/* ---------- Storage helpers ---------- */

/* Set an item's status. Done / Not doing record when and by whom; reopening clears that. */
function bz_core_feedback_set_status( $id, $status, $by = '' ) {
	if ( ! isset( bz_core_feedback_statuses()[ $status ] ) ) {
		return;
	}
	$was = get_post_meta( $id, 'bz_status', true );
	update_post_meta( $id, 'bz_status', $status );
	if ( in_array( $status, array( 'done', 'declined' ), true ) ) {
		if ( $was !== $status || ! get_post_meta( $id, 'bz_closed_at', true ) ) {
			update_post_meta( $id, 'bz_closed_at', gmdate( 'c' ) );
			update_post_meta( $id, 'bz_closed_by', $by ? $by : wp_get_current_user()->display_name );
		}
	} else {
		delete_post_meta( $id, 'bz_closed_at' );
		delete_post_meta( $id, 'bz_closed_by' );
	}
}

/* "16 Oct 2026" in the site's timezone. */
function bz_core_feedback_date( $iso ) {
	return $iso ? wp_date( 'j M Y', strtotime( $iso ) ) : '';
}

function bz_core_feedback_item( $post ) {
	$post = get_post( $post );
	$meta = function ( $k ) use ( $post ) {
		return get_post_meta( $post->ID, $k, true );
	};
	return array(
		'id'         => $post->ID,
		'kind'       => $meta( 'bz_kind' ) ? $meta( 'bz_kind' ) : 'comment',
		'status'     => in_array( $meta( 'bz_status' ), array( 'done', 'declined' ), true ) ? $meta( 'bz_status' ) : 'open',
		'comment'    => $post->post_content,
		'page_id'    => (int) $meta( 'bz_page_id' ),
		'page_title' => get_the_title( (int) $meta( 'bz_page_id' ) ),
		'url'        => $meta( 'bz_url' ),
		'element'    => $meta( 'bz_element' ),
		'element_type' => $meta( 'bz_element_type' ),
		'document'   => (int) $meta( 'bz_document' ),
		'document_title' => (int) $meta( 'bz_document' ) && (int) $meta( 'bz_document' ) !== (int) $meta( 'bz_page_id' ) ? get_the_title( (int) $meta( 'bz_document' ) ) : '',
		'shared'     => (int) $meta( 'bz_document' ) && (int) $meta( 'bz_document' ) !== (int) $meta( 'bz_page_id' ),
		'instance'   => $meta( 'bz_instance' ),
		'path'       => (array) $meta( 'bz_path' ),
		'label'      => $meta( 'bz_label' ),
		'snippet'    => $meta( 'bz_snippet' ),
		'reviewer'   => $meta( 'bz_reviewer' ),
		'reviewer_email' => $meta( 'bz_reviewer_email' ),
		'replies'    => array_values( array_filter( (array) $meta( 'bz_replies' ), 'is_array' ) ),
		'created'    => get_post_time( 'c', true, $post ),
		'closed_at'  => $meta( 'bz_closed_at' ),
		'closed_on'  => bz_core_feedback_date( $meta( 'bz_closed_at' ) ),
		'closed_by'  => $meta( 'bz_closed_by' ),
		'sent_on'    => bz_core_feedback_date( $meta( 'bz_sent' ) ),
		'mine'       => ( $actor = bz_core_feedback_actor() ) && $actor['key'] === $meta( 'bz_reviewer_key' ),
	);
}

/* What a client may see: no emails. */
function bz_core_feedback_public( $item ) {
	unset( $item['reviewer_email'] );
	return $item;
}

/* ---------- REST: bizink/v1/feedback ---------- */

add_action(
	'rest_api_init',
	function () {
		$is_team = function () {
			return current_user_can( 'edit_posts' );
		};

		register_rest_route(
			'bizink/v1',
			'/feedback',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => function () use ( $is_team ) {
						return $is_team() || bz_core_reviewer();
					},
					'callback'            => function ( WP_REST_Request $r ) use ( $is_team ) {
						$meta = array();
						if ( $r['page_id'] ) {
							$meta[] = array( 'key' => 'bz_page_id', 'value' => (int) $r['page_id'] );
						}
						if ( $r['status'] ) {
							$meta[] = array( 'key' => 'bz_status', 'value' => array_map( 'sanitize_key', explode( ',', $r['status'] ) ), 'compare' => 'IN' );
						}
						$posts = get_posts(
							array(
								'post_type'   => 'bz_feedback',
								'post_status' => 'publish',
								'numberposts' => 500,
								'orderby'     => 'date',
								'order'       => 'ASC',
								'meta_query'  => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small list.
							)
						);
						$items = array_map( 'bz_core_feedback_item', $posts );
						return $is_team() ? $items : array_map( 'bz_core_feedback_public', $items );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => function () {
						return (bool) bz_core_feedback_actor();
					},
					'callback'            => 'bz_core_feedback_create',
				),
			)
		);

		register_rest_route(
			'bizink/v1',
			'/feedback/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return (bool) bz_core_feedback_actor();
				},
				'callback'            => function ( WP_REST_Request $r ) use ( $is_team ) {
					$post = get_post( (int) $r['id'] );
					if ( ! $post || 'bz_feedback' !== $post->post_type ) {
						return new WP_Error( 'not_found', 'No such feedback.', array( 'status' => 404 ) );
					}
					$actor = bz_core_feedback_actor();
					$reply = 'team' === $actor['type'] ? trim( sanitize_textarea_field( (string) $r['reply'] ) ) : '';
					if ( '' !== $reply ) {
						$replies   = (array) get_post_meta( $post->ID, 'bz_replies', true );
						$replies[] = array(
							'by'   => 'team' === $actor['type'] ? 'Bizink' : $actor['name'],
							'from' => $actor['type'],
							'text' => mb_substr( $reply, 0, 3000 ),
							'at'   => gmdate( 'c' ),
						);
						update_post_meta( $post->ID, 'bz_replies', array_values( array_filter( $replies ) ) );
					}
					if ( $r['status'] && $is_team() && isset( bz_core_feedback_statuses()[ $r['status'] ] ) ) {
						bz_core_feedback_set_status( $post->ID, sanitize_key( $r['status'] ), $actor['name'] );
					}
					return bz_core_feedback_item( $post );
				},
			)
		);
	}
);

/**
 * Which saved document holds the element. Components render without any marker on the front end, so the browser
 * can only report the page (or header/footer); look the element ID up in the stored Elementor data instead.
 * Returns document (post ID holding the element) and instance (the nearest ancestor that is in the page itself,
 * i.e. the component instance to override, when the element lives in a shared component).
 */
function bz_core_feedback_locate( $element, $path, $page_id, $reported ) {
	global $wpdb;
	$out = array(
		'document' => $reported ? $reported : $page_id,
		'instance' => '',
	);
	if ( '' === $element ) {
		return $out;
	}
	$needle = '"id":"' . $element . '"';
	$ids    = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one lookup per submitted comment.
		$wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_elementor_data' AND p.post_type <> 'revision' AND p.post_status IN ('publish','draft','private') AND pm.meta_value LIKE %s",
			'%' . $wpdb->esc_like( $needle ) . '%'
		)
	);
	$ids = array_map( 'intval', $ids );
	if ( ! $ids ) {
		return $out;
	}
	if ( in_array( $reported, $ids, true ) ) {
		$out['document'] = $reported;
	} elseif ( in_array( $page_id, $ids, true ) ) {
		$out['document'] = $page_id;
	} else {
		$out['document'] = $ids[0];
	}
	if ( $out['document'] !== $page_id && $page_id ) {
		$page_data = (string) get_post_meta( $page_id, '_elementor_data', true );
		foreach ( $path as $ancestor ) {
			if ( false !== strpos( $page_data, '"id":"' . $ancestor . '"' ) ) {
				$out['instance'] = $ancestor;
				break;
			}
		}
	}
	return $out;
}

function bz_core_feedback_create( WP_REST_Request $r ) {
	$actor = bz_core_feedback_actor();
	// Gentle flood guard: 100 items an hour per link or user.
	$rate_key = 'bz_fb_rate_' . md5( $actor['key'] );
	$count    = (int) get_transient( $rate_key );
	if ( $count >= 100 ) {
		return new WP_Error( 'rate_limited', 'That is a lot of feedback in one hour. Please try again a little later.', array( 'status' => 429 ) );
	}
	set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

	$kind    = in_array( $r['kind'], array( 'comment', 'approve_page', 'approve_site' ), true ) ? $r['kind'] : 'comment';
	$comment = trim( sanitize_textarea_field( (string) $r['comment'] ) );
	if ( 'comment' === $kind && '' === $comment ) {
		return new WP_Error( 'empty', 'Please type your suggestion.', array( 'status' => 400 ) );
	}
	$page_id = (int) $r['page_id'];
	$title   = 'comment' === $kind ? wp_trim_words( $comment, 10, '…' ) : ( 'approve_site' === $kind ? 'Approved: whole site' : 'Approved: ' . get_the_title( $page_id ) );
	$id      = wp_insert_post(
		array(
			'post_type'    => 'bz_feedback',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => mb_substr( $comment, 0, 5000 ),
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$path  = array_slice( array_map( 'sanitize_key', (array) $r['path'] ), 0, 30 );
	$where = bz_core_feedback_locate( sanitize_key( (string) $r['element'] ), $path, $page_id, (int) $r['document'] );
	$meta  = array(
		'bz_kind'           => $kind,
		'bz_status'         => 'comment' === $kind ? 'open' : 'done',
		'bz_page_id'        => $page_id,
		'bz_url'            => esc_url_raw( (string) $r['url'] ),
		'bz_element'        => sanitize_key( (string) $r['element'] ),
		'bz_element_type'   => sanitize_text_field( (string) $r['element_type'] ),
		'bz_document'       => $where['document'],
		'bz_instance'       => $where['instance'],
		'bz_path'           => $path,
		'bz_label'          => sanitize_text_field( mb_substr( (string) $r['label'], 0, 120 ) ),
		'bz_snippet'        => sanitize_text_field( mb_substr( (string) $r['snippet'], 0, 300 ) ),
		'bz_reviewer'       => $actor['name'],
		'bz_reviewer_email' => $actor['email'],
		'bz_from'           => $actor['type'],
		'bz_reviewer_key'   => $actor['key'],
	);
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return bz_core_feedback_item( $id );
}

/* ---------- Change requests: send to Bizink support ---------- */

if ( ! defined( 'BZ_SUPPORT_EMAIL' ) ) {
	define( 'BZ_SUPPORT_EMAIL', '' ); // Fallback if the ticket service can't be reached. Set in wp-config.
}

/* Items not yet sent. $key limits to one review link or user; $older_than (seconds) to items at least that old. */
function bz_core_change_unsent( $key = '', $older_than = 0, $from = '' ) {
	$meta = array(
		array( 'key' => 'bz_from', 'compare' => 'EXISTS' ), // Items from before 0.15 count as already handled.
		array( 'key' => 'bz_sent', 'compare' => 'NOT EXISTS' ),
	);
	if ( $key ) {
		$meta[] = array( 'key' => 'bz_reviewer_key', 'value' => $key );
	}
	if ( $from ) {
		$meta[] = array( 'key' => 'bz_from', 'value' => $from );
	}
	$args = array(
		'post_type'   => 'bz_feedback',
		'post_status' => 'publish',
		'numberposts' => 200,
		'orderby'     => 'date',
		'order'       => 'ASC',
		'meta_query'  => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small list.
	);
	if ( $older_than ) {
		$args['date_query'] = array( array( 'before' => gmdate( 'Y-m-d H:i:s', time() - $older_than ), 'column' => 'post_date_gmt' ) );
	}
	return get_posts( $args );
}

/* The ticket text for a batch of items from one person. */
function bz_core_change_ticket( $posts, $auto ) {
	$items    = array_map( 'bz_core_feedback_item', $posts );
	$first    = $items[0];
	$who      = $first['reviewer'] ? $first['reviewer'] : 'A reviewer';
	$domain   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$firm     = bz_core_get( 'firm_name' );
	$changes  = array_values( array_filter( $items, function ( $i ) { return 'comment' === $i['kind']; } ) );
	$approved = array_values( array_filter( $items, function ( $i ) { return 'comment' !== $i['kind']; } ) );
	$n        = count( $changes );
	$test     = 'team' === get_post_meta( $first['id'], 'bz_from', true ) ? '[Test from the Bizink team] ' : '';
	$what     = $n ? sprintf( '%d change%s', $n, 1 === $n ? '' : 's' ) : 'approvals';
	$subject  = $test . ( $auto
		? sprintf( 'Change requests (not yet sent): %s left %s on %s', $who, $what, $domain )
		: sprintf( 'Change requests: %s has requested %s to %s', $who, $what, $domain ) );

	$lines   = array();
	$lines[] = $auto
		? sprintf( '%s (%s) left these on %s but has not pressed "Send to Bizink". Sent automatically after 24 hours.', $who, $firm, $domain )
		: sprintf( '%s (%s) has sent these change requests for %s.', $who, $firm, $domain );
	$lines[] = '';
	foreach ( $changes as $i => $it ) {
		$where   = $it['page_title'] ? $it['page_title'] : $it['url'];
		$lines[] = sprintf( '%d. %s: "%s"', $i + 1, $where, $it['comment'] );
		if ( $it['snippet'] ) {
			$lines[] = '   On: "' . mb_substr( $it['snippet'], 0, 120 ) . '"';
		}
		if ( $it['shared'] ) {
			$lines[] = '   Note: this is in a shared part (' . ( $it['document_title'] ? $it['document_title'] : 'header, footer or component' ) . '), so a change may show on other pages.';
		}
		$lines[] = '   ' . $it['url'];
	}
	if ( $approved ) {
		$lines[] = '';
		$lines[] = 'Approved: ' . implode( '; ', array_map( function ( $i ) { return 'approve_site' === $i['kind'] ? 'the whole site' : ( $i['page_title'] ? $i['page_title'] : $i['url'] ); }, $approved ) ) . '.';
	}
	$lines[] = '';
	$lines[] = 'See them all in WordPress: ' . admin_url( 'edit.php?post_type=bz_feedback' );
	$lines[] = 'Pull them into the build folder: feedback.py --url ' . home_url() . ' pull --client <folder>';
	return array( 'subject' => $subject, 'body' => implode( "\n", $lines ), 'who' => $first );
}

/* Send one batch as a support ticket (email fallback). Marks the items sent on success. */
function bz_core_change_send( $posts, $auto = false ) {
	if ( ! $posts ) {
		return new WP_Error( 'bz_change_none', 'There is nothing new to send.' );
	}
	$t     = bz_core_change_ticket( $posts, $auto );
	$email = is_email( $t['who']['reviewer_email'] ) ? $t['who']['reviewer_email'] : ( is_email( bz_core_get( 'email' ) ) ? bz_core_get( 'email' ) : get_option( 'admin_email' ) );
	$ok    = false;
	$error = '';
	if ( defined( 'BZ_SUPPORT_ENDPOINT' ) && BZ_SUPPORT_ENDPOINT ) {
		$response = wp_remote_post(
			BZ_SUPPORT_ENDPOINT,
			array(
				'timeout' => 20,
				'body'    => array(
					'contactName'         => $t['who']['reviewer'] ? $t['who']['reviewer'] : bz_core_get( 'firm_name' ),
					'contactEmail'        => $email,
					'subject'             => mb_substr( $t['subject'], 0, 200 ),
					'body'                => $t['body'],
					'site_domain'         => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					'firm'                => bz_core_get( 'firm_name' ),
					'site_url'            => home_url( '/' ),
					'source'              => 'site-review',
					'wp_version'          => get_bloginfo( 'version' ),
					'bizink_core_version' => BZ_CORE_VERSION,
					'website'             => '',
				),
			)
		);
		$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		$ok   = $code >= 200 && $code < 300;
		if ( ! $ok ) {
			$json  = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
			$error = is_wp_error( $response ) ? $response->get_error_message() : ( isset( $json['error'] ) ? $json['error'] : 'HTTP ' . $code );
		}
	}
	if ( ! $ok && BZ_SUPPORT_EMAIL ) {
		$ok = wp_mail( BZ_SUPPORT_EMAIL, $t['subject'], $t['body'], array( 'Reply-To: ' . $email ) );
	}
	if ( ! $ok ) {
		return new WP_Error( 'bz_change_failed', 'Your changes are saved but could not be sent just now' . ( $error ? ' (' . $error . ')' : '' ) . '. Please try again shortly.' );
	}
	foreach ( $posts as $p ) {
		update_post_meta( $p->ID, 'bz_sent', gmdate( 'c' ) );
		if ( $auto ) {
			update_post_meta( $p->ID, 'bz_sent_auto', 1 );
		}
	}
	return count( $posts );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bizink/v1',
			'/feedback/send',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return (bool) bz_core_feedback_actor();
				},
				'callback'            => function () {
					$actor = bz_core_feedback_actor();
					$guard = 'bz_change_send_' . md5( $actor['key'] );
					if ( get_transient( $guard ) ) {
						return new WP_Error( 'rate_limited', 'Just sent. Please wait a minute before sending again.', array( 'status' => 429 ) );
					}
					$result = bz_core_change_send( bz_core_change_unsent( $actor['key'] ) );
					if ( is_wp_error( $result ) ) {
						$result->add_data( array( 'status' => 'bz_change_none' === $result->get_error_code() ? 400 : 502 ) );
						return $result;
					}
					set_transient( $guard, 1, MINUTE_IN_SECONDS );
					return array( 'sent' => $result );
				},
			)
		);
	}
);

/* Daily safety net: client items unsent for 24 hours go in one ticket per reviewer. */
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'bz_change_requests_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bz_change_requests_daily' );
		}
	}
);

add_action(
	'bz_change_requests_daily',
	function () {
		$groups = array();
		foreach ( bz_core_change_unsent( '', DAY_IN_SECONDS, 'client' ) as $p ) {
			$groups[ (string) get_post_meta( $p->ID, 'bz_reviewer_key', true ) ][] = $p;
		}
		foreach ( $groups as $posts ) {
			// Include anything newer from the same person so the ticket is complete.
			$key = (string) get_post_meta( $posts[0]->ID, 'bz_reviewer_key', true );
			bz_core_change_send( $key ? bz_core_change_unsent( $key ) : $posts, true );
		}
	}
);

/* ---------- Front end: the feedback bar ---------- */

/*
 * Pages are cached (Varnish, Breeze, Cloudflare), so the page itself only carries a tiny loader that is the same for
 * everyone. It checks for the review cookie in the browser and only then fetches the bar from the (uncached) API.
 * Logged-in team members get the loader with a REST nonce; their pages are never cached.
 */
add_action(
	'wp_footer',
	function () {
		if ( is_admin() || isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$team  = is_user_logged_in() && current_user_can( 'edit_posts' );
		$boot  = esc_url_raw( rest_url( 'bizink/v1/feedback/boot' ) );
		$nonce = $team ? wp_create_nonce( 'wp_rest' ) : '';
		echo '<script>(function(){var team=' . ( $team ? 'true' : 'false' ) . ',nonce=' . wp_json_encode( $nonce ) . ';'
			. 'if(!team&&!/(?:^|; )' . BZ_FEEDBACK_COOKIE . '=/.test(document.cookie))return;'
			. 'var m=document.body.className.match(/(?:page-id|postid)-(\d+)/);'
			. 'fetch(' . wp_json_encode( $boot ) . '+"?cb="+Date.now(),{credentials:"same-origin",headers:nonce?{"X-WP-Nonce":nonce}:{}})'
			. '.then(function(r){return r.ok?r.json():null;}).then(function(b){if(!b)return;'
			. 'var s=document.createElement("style");s.textContent=b.css;document.head.appendChild(s);'
			. 'b.config.pageId=m?+m[1]:0;b.config.nonce=nonce;window.bzFeedback=b.config;'
			. 'var j=document.createElement("script");j.textContent=b.js;document.body.appendChild(j);}).catch(function(){});})();</script>';
	},
	100
);

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bizink/v1',
			'/feedback/boot',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return (bool) bz_core_feedback_actor();
				},
				'callback'            => function () {
					$actor    = bz_core_feedback_actor();
					$response = rest_ensure_response(
						array(
							'config' => array(
								'rest'   => esc_url_raw( rest_url( 'bizink/v1/feedback' ) ),
								'name'   => $actor['name'],
								'isTeam' => 'team' === $actor['type'],
								'unsent' => count( bz_core_change_unsent( $actor['key'] ) ),
							),
							'css'    => bz_core_feedback_css(),
							'js'     => bz_core_feedback_js(),
						)
					);
					foreach ( wp_get_nocache_headers() as $k => $v ) {
						$response->header( $k, $v );
					}
					return $response;
				},
			)
		);
	}
);

/* Admin bar on the front end: swap Elementor Notes (editors only) for "View your feedback summary". */
add_action(
	'admin_bar_menu',
	function ( $bar ) {
		if ( is_admin() || ! bz_core_feedback_actor() ) {
			return;
		}
		foreach ( (array) $bar->get_nodes() as $node ) {
			if ( false !== stripos( $node->id, 'note' ) ) {
				$bar->remove_node( $node->id );
			}
		}
		$bar->add_node(
			array(
				'id'    => 'bz-feedback-summary',
				'title' => 'View your feedback summary',
				'href'  => '#bzfb-summary',
			)
		);
	},
	999
);

// Elementor builds its front-end admin bar (Edit with Elementor, Notes) from this config in JavaScript.
add_filter(
	'elementor/frontend/admin_bar/settings',
	function ( $config ) {
		if ( is_array( $config ) && bz_core_feedback_actor() ) {
			foreach ( array_keys( $config ) as $k ) {
				if ( false !== stripos( (string) $k, 'note' ) ) {
					unset( $config[ $k ] );
				}
			}
		}
		return $config;
	},
	9999
);

/* Never let a page rendered for a reviewer be cached. */
add_action(
	'template_redirect',
	function () {
		if ( bz_core_reviewer() ) {
			nocache_headers();
		}
	},
	1
);

function bz_core_feedback_css() {
	return '#bzfb-bar{position:fixed;right:16px;bottom:16px;z-index:999999;display:flex;gap:8px;font:600 14px/1.2 system-ui,sans-serif}'
		. '#bzfb-bar button,.bzfb-pop button{border:0;border-radius:6px;padding:10px 14px;cursor:pointer;font:inherit;box-shadow:0 2px 10px rgba(0,0,0,.2)}'
		. '#bzfb-bar .bzfb-main{background:#1d4ed8;color:#fff}#bzfb-bar .bzfb-alt{background:#fff;color:#111}'
		. '#bzfb-bar .bzfb-main[aria-pressed=true]{background:#b91c1c}#bzfb-bar .bzfb-sendall{background:#15803d;color:#fff}'
		. '.bzfb-hl{outline:3px solid #1d4ed8!important;outline-offset:2px;cursor:crosshair!important}'
		. 'body.bzfb-picking a,body.bzfb-picking button{cursor:crosshair!important}'
		. '.bzfb-pin{position:absolute;z-index:999998;width:26px;height:26px;border-radius:50%;background:#1d4ed8;color:#fff;font:700 13px/26px system-ui,sans-serif;text-align:center;cursor:pointer;box-shadow:0 1px 6px rgba(0,0,0,.35)}'
		. '.bzfb-pin.done{background:#15803d}.bzfb-pin.declined{background:#6b7280}'
		. '.bzfb-pop{position:absolute;z-index:1000000;width:min(340px,calc(100vw - 32px));background:#fff;color:#111;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.3);padding:14px;font:14px/1.45 system-ui,sans-serif;text-align:left}'
		. '.bzfb-pop textarea{width:100%;min-height:90px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:8px;font:inherit;margin:6px 0 10px}'
		. '.bzfb-pop .bzfb-row{display:flex;gap:8px;justify-content:flex-end}.bzfb-pop .bzfb-send{background:#1d4ed8;color:#fff}.bzfb-pop .bzfb-cancel{background:#e5e7eb;color:#111;box-shadow:none}'
		. '.bzfb-pop .bzfb-meta{color:#64748b;font-size:12px}.bzfb-pop .bzfb-reply{border-left:3px solid #cbd5e1;padding-left:8px;margin:8px 0}'
		. '#bzfb-key{position:fixed;right:16px;bottom:66px;z-index:999999;background:#fff;color:#111;border-radius:8px;box-shadow:0 4px 18px rgba(0,0,0,.22);padding:10px 12px 10px 12px;font:13px/1.4 system-ui,sans-serif;min-width:190px}'
		. '#bzfb-key strong{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#475569;margin:0 22px 6px 0}'
		. '#bzfb-key ul{list-style:none;margin:0;padding:0;display:grid;gap:5px}#bzfb-key li{display:flex;align-items:center;gap:8px;margin:0}'
		. '#bzfb-key i{display:inline-block;width:14px;height:14px;border-radius:50%;flex:none}'
		. '#bzfb-key .bzfb-x{position:absolute;top:4px;right:6px;border:0;background:none;box-shadow:none;padding:2px 6px;font:16px/1 system-ui,sans-serif;color:#64748b;cursor:pointer}'
		. '#bzfb-bar .bzfb-help{background:#fff;color:#111;padding:10px 12px}'
		. '.bzfb-sum{position:fixed!important;right:16px;bottom:72px;top:auto;left:auto;width:min(420px,calc(100vw - 32px));max-height:calc(100vh - 140px);overflow:auto}'
		. '.bzfb-sum-page{margin:12px 0 4px;font-weight:700}.bzfb-sum-page a{color:#111;text-decoration:underline}'
		. '.bzfb-sum-item{border-left:3px solid #e2e8f0;padding:4px 0 6px 10px;margin:0 0 6px}'
		. '.bzfb-sum-st{display:inline-block;color:#fff;font-size:11px;font-weight:700;border-radius:999px;padding:1px 8px;margin:0 0 3px}'
		. '#bzfb-toast{position:fixed;left:50%;bottom:72px;transform:translateX(-50%);z-index:1000001;background:#111;color:#fff;padding:10px 16px;border-radius:6px;font:14px system-ui,sans-serif}';
}

function bz_core_feedback_js() {
	return <<<'JS'
(function(){
var C=window.bzFeedback,items=[],picking=false,hl=null,pop=null;
function api(path,method,body){var h={'Content-Type':'application/json'};if(C.nonce)h['X-WP-Nonce']=C.nonce;
 return fetch(C.rest+path,{method:method||'GET',credentials:'same-origin',headers:h,body:body?JSON.stringify(body):undefined}).then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error(j.message||'Something went wrong');return j;});});}
function el(tag,cls,txt){var e=document.createElement(tag);if(cls)e.className=cls;if(txt!=null)e.textContent=txt;return e;}
function toast(t){var x=el('div',null,t);x.id='bzfb-toast';document.body.appendChild(x);setTimeout(function(){x.remove();},3500);}
function target(node){while(node&&node!==document.body){if(node.closest&&node.closest('#bzfb-bar,.bzfb-pop,.bzfb-pin'))return null;if(node.dataset&&node.dataset.id)return node;node=node.parentElement;}return null;}
function describe(t){var path=[],n=t.parentElement,doc=t.closest('[data-elementor-id]');
 while(n&&n!==document.body){if(n.dataset&&n.dataset.id)path.push(n.dataset.id);n=n.parentElement;}
 var type=t.dataset.element_type||t.dataset.eType||t.tagName.toLowerCase(),txt=(t.innerText||t.getAttribute('alt')||'').replace(/\s+/g,' ').trim();
 var img=t.tagName==='IMG'?t:t.querySelector('img');
 return {element:t.dataset.id,element_type:type,document:doc?+doc.dataset.elementorId:0,path:path,
  label:(t.tagName.toLowerCase()+' '+type).trim(),snippet:txt?txt.slice(0,300):(img?('image: '+(img.getAttribute('alt')||img.src.split('/').pop())):'')};}
function closePop(){if(pop){pop.remove();pop=null;}}
function place(box,t){var r=t.getBoundingClientRect();box.style.top=(window.scrollY+Math.max(r.top,0)+Math.min(r.height,40)+8)+'px';
 box.style.left=Math.max(16,Math.min(window.scrollX+r.left,window.scrollX+document.documentElement.clientWidth-356))+'px';}
function compose(t){closePop();var d=describe(t);pop=el('div','bzfb-pop');
 pop.appendChild(el('strong',null,'Suggest a change'));pop.appendChild(el('div','bzfb-meta',d.snippet?('On: "'+d.snippet.slice(0,80)+(d.snippet.length>80?'…"':'"')):'On the highlighted area'));
 var ta=el('textarea');ta.placeholder='What should change? e.g. new wording, a different photo, move this up…';pop.appendChild(ta);
 var row=el('div','bzfb-row'),cancel=el('button','bzfb-cancel','Cancel'),send=el('button','bzfb-send','Send');row.appendChild(cancel);row.appendChild(send);pop.appendChild(row);
 cancel.onclick=closePop;send.onclick=function(){if(!ta.value.trim()){ta.focus();return;}send.disabled=true;send.textContent='Sending…';
  var body=Object.assign({kind:'comment',comment:ta.value,page_id:C.pageId,url:location.href.split('#')[0]},d);
  api('','POST',body).then(function(it){items.push(it);closePop();pins();unsent(1);toast('Saved. Send your changes to Bizink when you have finished.');}).catch(function(e){send.disabled=false;send.textContent='Send';toast(e.message);});};
 document.body.appendChild(pop);place(pop,t);ta.focus();}
function view(it,t){closePop();pop=el('div','bzfb-pop');var st=({open:'Waiting for Bizink',done:'Done',declined:'Not doing'}[it.status]||it.status)+(it.closed_on&&(it.status==='done'||it.status==='declined')?' '+it.closed_on:'');
 pop.appendChild(el('div','bzfb-meta',it.reviewer+' · '+st));pop.appendChild(el('p',null,it.comment));
 (it.replies||[]).forEach(function(r){var x=el('div','bzfb-reply');x.appendChild(el('div','bzfb-meta',r.by));x.appendChild(el('div',null,r.text));pop.appendChild(x);});
 var row=el('div','bzfb-row'),close=el('button','bzfb-cancel','Close');row.appendChild(close);pop.appendChild(row);close.onclick=closePop;
 document.body.appendChild(pop);if(t)place(pop,t);else{pop.style.position='fixed';pop.style.right='16px';pop.style.bottom='72px';}}
function pins(){document.querySelectorAll('.bzfb-pin').forEach(function(p){p.remove();});var n=0;
 items.forEach(function(it){if(it.kind!=='comment')return;n++;var t=it.element&&document.querySelector('[data-id="'+it.element+'"]');var p=el('div','bzfb-pin '+it.status,String(n));p.title=it.comment;
  if(t&&t.getClientRects().length){var r=t.getBoundingClientRect();p.style.top=(window.scrollY+r.top-10)+'px';p.style.left=(window.scrollX+r.left-10)+'px';}else{p.style.position='fixed';p.style.left='16px';p.style.top=(80+n*32)+'px';}
  p.onclick=function(e){e.stopPropagation();view(it,t);};document.body.appendChild(p);});
 count.textContent='Summary · '+items.filter(function(i){return i.kind==='comment'&&i.status!=='done'&&i.status!=='declined';}).length+' open here';
 if(n&&key.hidden&&!keyClosed())key.hidden=false;}
function setPicking(on){picking=on;main.setAttribute('aria-pressed',on);main.textContent=on?'Click the part to change (Esc to stop)':'Suggest a change';document.body.classList.toggle('bzfb-picking',on);if(!on&&hl){hl.classList.remove('bzfb-hl');hl=null;}}
document.addEventListener('mouseover',function(e){if(!picking)return;var t=target(e.target);if(hl&&hl!==t)hl.classList.remove('bzfb-hl');hl=t;if(t)t.classList.add('bzfb-hl');},true);
document.addEventListener('click',function(e){if(!picking)return;var t=target(e.target);if(!t)return;e.preventDefault();e.stopPropagation();setPicking(false);compose(t);},true);
document.addEventListener('keydown',function(e){if(e.key==='Escape'){setPicking(false);closePop();}});
var bar=el('div');bar.id='bzfb-bar';var count=el('button','bzfb-alt','Summary'),approve=el('button','bzfb-alt','Approve page'),main=el('button','bzfb-main','Suggest a change');
var key=el('div');key.id='bzfb-key';key.setAttribute('role','note');key.setAttribute('aria-label','What the pin colours mean');key.hidden=true;
key.appendChild(el('strong',null,'Pin colours'));var kl=el('ul');
[['#1d4ed8','Waiting for Bizink'],['#15803d','Done'],['#6b7280','Not doing']].forEach(function(r){var li=el('li'),dot=el('i');dot.style.background=r[0];li.appendChild(dot);li.appendChild(el('span',null,r[1]));kl.appendChild(li);});
key.appendChild(kl);var kx=el('button','bzfb-x','×');kx.setAttribute('aria-label','Hide the colour key');key.appendChild(kx);document.body.appendChild(key);
function keyClosed(){try{return sessionStorage.getItem('bzfbKey')==='0';}catch(e){return false;}}
function showKey(on){key.hidden=!on;try{sessionStorage.setItem('bzfbKey',on?'1':'0');}catch(e){}}
kx.onclick=function(){showKey(false);};
var help=el('button','bzfb-help','?');help.title='What the pin colours mean';help.setAttribute('aria-label','What the pin colours mean');help.onclick=function(){showKey(key.hidden);};
var sendAll=el('button','bzfb-sendall','');bar.appendChild(help);bar.appendChild(count);bar.appendChild(approve);bar.appendChild(main);bar.appendChild(sendAll);document.body.appendChild(bar);
function unsent(add){C.unsent=(C.unsent||0)+(add||0);sendAll.hidden=!C.unsent;sendAll.textContent='Send '+C.unsent+' to Bizink';sendAll.title='Send your change requests to the Bizink team';}
unsent(0);
sendAll.onclick=function(){if(!confirm('Send your '+C.unsent+' change request'+(C.unsent===1?'':'s')+' to Bizink now? You can keep adding more afterwards.'))return;sendAll.disabled=true;sendAll.textContent='Sending…';
 api('/send','POST',{}).then(function(r){C.unsent=0;unsent(0);sendAll.disabled=false;toast('Sent to Bizink. We will be in touch.');}).catch(function(e){sendAll.disabled=false;unsent(0);toast(e.message);});};
main.onclick=function(){closePop();setPicking(!picking);};
function summary(){closePop();var box=el('div','bzfb-pop bzfb-sum');pop=box;box.appendChild(el('strong',null,'Your feedback summary'));var body=el('div',null,'Loading…');box.appendChild(body);
 var row=el('div','bzfb-row'),close=el('button','bzfb-cancel','Close');row.appendChild(close);box.appendChild(row);close.onclick=closePop;document.body.appendChild(box);
 api('').then(function(all){var list=C.isTeam?all:all.filter(function(i){return i.mine;});body.textContent='';
  if(!list.length){body.appendChild(el('p','bzfb-meta','You have not left any feedback yet. Use Suggest a change to add some.'));return;}
  var by={},order=[];list.forEach(function(i){var k=i.page_title||i.url||'Whole site';if(!by[k]){by[k]={url:i.url,items:[]};order.push(k);}by[k].items.push(i);});
  var ST={open:['Waiting for Bizink','#1d4ed8'],done:['Done','#15803d'],declined:['Not doing','#6b7280']};
  var n=list.filter(function(i){return i.kind==='comment';}),o=n.filter(function(i){return i.status==='open';}).length;
  body.appendChild(el('p','bzfb-meta',n.length+' change request'+(n.length===1?'':'s')+': '+o+' waiting, '+(n.length-o)+' closed.'+(C.unsent?' '+C.unsent+' not sent to Bizink yet.':'')));
  order.forEach(function(k){var g=by[k],h=el('div','bzfb-sum-page');var a=el('a',null,k);a.href=g.url||'#';h.appendChild(a);body.appendChild(h);
   g.items.forEach(function(it){var r=el('div','bzfb-sum-item');
    if(it.kind!=='comment'){r.appendChild(el('span','bzfb-meta',it.kind==='approve_site'?'You approved the whole site':'You approved this page'));body.appendChild(r);return;}
    var st=ST[it.status]||ST.open,p=el('span','bzfb-sum-st',st[0]+(it.closed_on&&it.status!=='open'?' '+it.closed_on:''));p.style.background=st[1];
    r.appendChild(p);r.appendChild(el('div',null,it.comment.length>140?it.comment.slice(0,140)+'…':it.comment));
    r.appendChild(el('div','bzfb-meta',(C.isTeam?it.reviewer+' · ':'')+(it.sent_on?'Sent to Bizink '+it.sent_on:'Not sent yet')));body.appendChild(r);});});
 }).catch(function(e){body.textContent=e.message;});}
count.title='View your feedback summary';count.onclick=summary;
document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('#wp-admin-bar-bz-feedback-summary a');if(!a)return;e.preventDefault();summary();});
approve.onclick=function(){if(!confirm('Approve this page as it is now? You can still send suggestions afterwards.'))return;api('','POST',{kind:'approve_page',page_id:C.pageId,url:location.href.split('#')[0]}).then(function(){approve.textContent='Page approved ✓';approve.disabled=true;unsent(1);toast('Thanks, this page is approved. Send it to Bizink with your changes.');}).catch(function(e){toast(e.message);});};
window.addEventListener('resize',function(){if(items.length)pins();});
api('?page_id='+C.pageId).then(function(list){items=list;pins();if(list.some(function(i){return i.kind==='approve_page';})){approve.textContent='Page approved ✓';approve.disabled=true;}}).catch(function(){});
})();
JS;
}

/* ---------- Admin: list columns and review links ---------- */

add_filter(
	'manage_bz_feedback_posts_columns',
	function ( $cols ) {
		return array(
			'cb'          => $cols['cb'],
			'title'       => 'Feedback',
			'bz_status'   => 'Status',
			'bz_page'     => 'Page',
			'bz_reviewer' => 'From',
			'bz_sent'     => 'Sent to support',
			'date'        => 'Date',
		);
	}
);

add_action(
	'manage_bz_feedback_posts_custom_column',
	function ( $col, $id ) {
		$item = bz_core_feedback_item( $id );
		if ( 'bz_status' === $col ) {
			if ( 'comment' !== $item['kind'] ) {
				echo esc_html( 'Approval' );
			} else {
				$label = bz_core_feedback_statuses()[ $item['status'] ];
				$color = array( 'open' => '#1d4ed8', 'done' => '#15803d', 'declined' => '#6b7280' )[ $item['status'] ];
				echo '<strong style="color:' . esc_attr( $color ) . '">' . esc_html( $label ) . '</strong>';
				if ( $item['closed_on'] ) {
					echo '<br><span style="color:#646970">' . esc_html( $item['closed_on'] . ( $item['closed_by'] ? ' by ' . $item['closed_by'] : '' ) ) . '</span>';
				}
			}
		} elseif ( 'bz_page' === $col && $item['url'] ) {
			echo '<a href="' . esc_url( $item['url'] ) . '" target="_blank">' . esc_html( $item['page_title'] ? $item['page_title'] : $item['url'] ) . '</a>';
		} elseif ( 'bz_reviewer' === $col ) {
			echo esc_html( $item['reviewer'] );
		} elseif ( 'bz_sent' === $col ) {
			$sent = get_post_meta( $id, 'bz_sent', true );
			$from = get_post_meta( $id, 'bz_from', true );
			echo esc_html( $sent ? substr( $sent, 0, 10 ) . ( get_post_meta( $id, 'bz_sent_auto', true ) ? ' (automatic)' : '' ) : ( $from ? 'Not yet' : '–' ) );
		}
	},
	10,
	2
);

/* Mark done / reopen from the list: row links and bulk actions. */
add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( 'bz_feedback' !== $post->post_type || 'comment' !== ( get_post_meta( $post->ID, 'bz_kind', true ) ?: 'comment' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$status = get_post_meta( $post->ID, 'bz_status', true ) ?: 'open';
		$link   = function ( $to, $label ) use ( $post ) {
			return '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bz_feedback_status&id=' . $post->ID . '&to=' . $to ), 'bz_feedback_status_' . $post->ID ) ) . '">' . esc_html( $label ) . '</a>';
		};
		$new = in_array( $status, array( 'done', 'declined' ), true ) ? array( 'bz_reopen' => $link( 'open', 'Reopen' ) ) : array( 'bz_done' => $link( 'done', 'Mark done' ), 'bz_declined' => $link( 'declined', 'Not doing' ) );
		unset( $actions['inline hide-if-no-js'] );
		return $new + $actions;
	},
	10,
	2
);

add_action(
	'admin_post_bz_feedback_status',
	function () {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'bz_feedback_status_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) || 'bz_feedback' !== get_post_type( $id ) ) {
			wp_die( 'Not allowed.' );
		}
		bz_core_feedback_set_status( $id, isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : '' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=bz_feedback' ) );
		exit;
	}
);

add_filter(
	'bulk_actions-edit-bz_feedback',
	function ( $actions ) {
		return array( 'bz_done' => 'Mark done', 'bz_declined' => 'Mark not doing', 'bz_open' => 'Reopen' ) + $actions;
	}
);

add_filter(
	'handle_bulk_actions-edit-bz_feedback',
	function ( $redirect, $action, $ids ) {
		$map = array( 'bz_done' => 'done', 'bz_declined' => 'declined', 'bz_open' => 'open' );
		if ( ! isset( $map[ $action ] ) ) {
			return $redirect;
		}
		foreach ( $ids as $id ) {
			if ( current_user_can( 'edit_post', $id ) && 'comment' === ( get_post_meta( $id, 'bz_kind', true ) ?: 'comment' ) ) {
				bz_core_feedback_set_status( (int) $id, $map[ $action ] );
			}
		}
		return $redirect;
	},
	10,
	3
);

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'edit.php?post_type=bz_feedback', 'Review links', 'Review links', 'manage_options', 'bz-review-links', 'bz_core_render_review_links' );
	}
);

add_action(
	'admin_post_bz_review_links',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'bz_review_links' );
		$links = bz_core_review_links();
		$new   = '';
		if ( isset( $_POST['revoke'] ) ) {
			$key = sanitize_key( wp_unslash( $_POST['revoke'] ) );
			if ( isset( $links[ $key ] ) ) {
				$links[ $key ]['revoked'] = gmdate( 'c' );
			}
		} else {
			$token                               = wp_generate_password( 32, false );
			$links[ hash( 'sha256', $token ) ] = array(
				'name'    => sanitize_text_field( wp_unslash( isset( $_POST['name'] ) ? $_POST['name'] : '' ) ),
				'email'   => sanitize_email( wp_unslash( isset( $_POST['email'] ) ? $_POST['email'] : '' ) ),
				'created' => gmdate( 'c' ),
			);
			$new = add_query_arg( 'bz_review', $token, home_url( '/' ) );
		}
		update_option( 'bz_review_links', $links, false );
		set_transient( 'bz_review_new_link_' . get_current_user_id(), $new, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=bz_feedback&page=bz-review-links' ) );
		exit;
	}
);

function bz_core_render_review_links() {
	$new = get_transient( 'bz_review_new_link_' . get_current_user_id() );
	delete_transient( 'bz_review_new_link_' . get_current_user_id() );
	$form = function ( $inner ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'bz_review_links', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="bz_review_links">' . $inner . '</form>';
	};
	echo '<div class="wrap"><h1>Review links</h1><p>Send a client their own link. Opening it lets them suggest changes on any page and approve pages, without a WordPress login. It works for 60 days on their browser; revoke it at any time.</p>';
	if ( $new ) {
		echo '<div class="notice notice-success"><p><strong>New review link (copy it now, it is shown once):</strong><br><input type="text" readonly style="width:100%" value="' . esc_attr( $new ) . '" onclick="this.select()"></p></div>';
	}
	echo $form( '<p><input name="name" placeholder="Client name" required> <input name="email" type="email" placeholder="Client email (optional)"> <button class="button button-primary">Create review link</button></p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Client</th><th>Email</th><th>Created</th><th></th></tr></thead><tbody>';
	foreach ( array_reverse( bz_core_review_links(), true ) as $key => $l ) {
		echo '<tr><td>' . esc_html( $l['name'] ) . '</td><td>' . esc_html( $l['email'] ) . '</td><td>' . esc_html( substr( $l['created'], 0, 10 ) ) . '</td><td>'
			. ( empty( $l['revoked'] ) ? $form( '<input type="hidden" name="revoke" value="' . esc_attr( $key ) . '"><button class="button">Revoke</button>' ) : 'Revoked ' . esc_html( substr( $l['revoked'], 0, 10 ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/* Feedback detail screen: show where it was left and the conversation, read-only (replies go through the API/tool). */
add_action(
	'add_meta_boxes_bz_feedback',
	function () {
		add_meta_box(
			'bz_feedback_detail',
			'Where and conversation',
			function ( $post ) {
				$it = bz_core_feedback_item( $post );
				echo '<p><strong>Page:</strong> <a href="' . esc_url( $it['url'] ) . '" target="_blank">' . esc_html( $it['page_title'] ? $it['page_title'] : $it['url'] ) . '</a><br><strong>Element:</strong> ' . esc_html( $it['element'] . ' (' . $it['element_type'] . ')' )
					. ( $it['document'] && $it['document'] !== $it['page_id'] ? ' <em>inside shared document ' . (int) $it['document'] . ': a change may affect other pages</em>' : '' )
					. '<br><strong>Status:</strong> ' . esc_html( bz_core_feedback_statuses()[ $it['status'] ] . ( $it['closed_on'] ? ' ' . $it['closed_on'] . ( $it['closed_by'] ? ' by ' . $it['closed_by'] : '' ) : '' ) )
					. '<br><strong>Text there:</strong> ' . esc_html( $it['snippet'] ) . '<br><strong>From:</strong> ' . esc_html( $it['reviewer'] . ( $it['reviewer_email'] ? ' <' . $it['reviewer_email'] . '>' : '' ) ) . '</p>';
				foreach ( $it['replies'] as $r ) {
					echo '<p style="border-left:3px solid #ccd0d4;padding-left:8px"><strong>' . esc_html( $r['by'] ) . '</strong> ' . esc_html( substr( $r['at'], 0, 16 ) ) . '<br>' . esc_html( $r['text'] ) . '</p>';
				}
			},
			'bz_feedback',
			'normal',
			'high'
		);
	}
);

/* 0.15.3: the "question" status was retired; move any such items back to open. */
add_action(
	'admin_init',
	function () {
		if ( get_option( 'bz_feedback_no_question' ) ) {
			return;
		}
		foreach ( get_posts( array( 'post_type' => 'bz_feedback', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => 'bz_status', 'meta_value' => 'question' ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			update_post_meta( $id, 'bz_status', 'open' );
		}
		update_option( 'bz_feedback_no_question', 1 );
	}
);

/* One-time tidy-up of the Elementor Notes reviewer experiment (0.9.7-0.9.9). The role is left for the admin to remove. */
add_action(
	'admin_init',
	function () {
		if ( false !== get_option( 'bz_reviewer_log', false ) || false !== get_option( 'bz_reviewer_caps_hash', false ) ) {
			delete_option( 'bz_reviewer_log' );
			delete_option( 'bz_reviewer_caps_hash' );
		}
	}
);
