<?php
/**
 * Business guides from the Bizink content hub (bizinkcontent.com, post type business-content), imported into the
 * master like the partner video library. Editing happens on the hub only; the master keeps a copy.
 *
 * Master only (BZ_MARKETING_SERVICE host): a daily sync (and "Sync now" under Tools → Business guides) creates or
 * updates one bz_guide post per hub item, with its text, topics, type, regions and the hub's last-modified date, and
 * removes guides that are no longer on the hub. Guides are not public pages; they are the trusted library the
 * Marketing assistant writes from (bz_core_guides_catalogue / bz_core_guide_text), and can be offered to client sites later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BZ_GUIDES_HUB' ) ) {
	define( 'BZ_GUIDES_HUB', 'https://bizinkcontent.com' );
}
if ( ! defined( 'BZ_GUIDES_FRESH_MONTHS' ) ) {
	define( 'BZ_GUIDES_FRESH_MONTHS', 18 ); // Guides not updated on the hub for longer are flagged and left out of drafts.
}

function bz_core_guides_enabled() {
	return function_exists( 'bz_core_mkt_is_service' ) && bz_core_mkt_is_service();
}

add_action(
	'init',
	function () {
		if ( ! bz_core_guides_enabled() ) {
			return;
		}
		register_post_type(
			'bz_guide',
			array(
				'labels'          => array(
					'name'          => 'Business guides',
					'singular_name' => 'Business guide',
					'all_items'     => 'All business guides',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'menu_position'   => 23,
				'menu_icon'       => 'dashicons-book-alt',
				'supports'        => array( 'title', 'editor', 'excerpt' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ), // They come from the hub.
				'map_meta_cap'    => true,
			)
		);
		if ( ! wp_next_scheduled( 'bz_guides_sync' ) ) {
			wp_schedule_event( time() + 120, 'daily', 'bz_guides_sync' );
		}
	}
);

add_action(
	'bz_guides_sync',
	function () {
		if ( bz_core_guides_enabled() ) {
			bz_core_guides_sync();
		}
	}
);

function bz_core_guides_hub_get( $path ) {
	$response = wp_remote_get( BZ_GUIDES_HUB . '/wp-json/' . $path, array( 'timeout' => 30 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array( null, 0 );
	}
	return array( json_decode( wp_remote_retrieve_body( $response ), true ), (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' ) );
}

/* Term id => name for one hub taxonomy. */
function bz_core_guides_terms( $tax ) {
	list( $terms ) = bz_core_guides_hub_get( 'wp/v2/' . $tax . '?per_page=100&_fields=id,name' );
	$out = array();
	foreach ( is_array( $terms ) ? $terms : array() as $t ) {
		$out[ (int) $t['id'] ] = html_entity_decode( (string) $t['name'], ENT_QUOTES );
	}
	return $out;
}

/* Hub region names → our region codes. */
function bz_core_guides_region_code( $name ) {
	$map = array(
		'australia'      => 'AU',
		'new zealand'    => 'NZ',
		'uk'             => 'UK',
		'united kingdom' => 'UK',
		'ireland'        => 'IE',
		'united states'  => 'US',
		'canada'         => 'CA',
	);
	$key = strtolower( trim( $name ) );
	return isset( $map[ $key ] ) ? $map[ $key ] : strtoupper( $key );
}

/**
 * Pull every business guide from the hub. Returns a summary string, or a WP_Error when the hub can't be read
 * (nothing is deleted in that case).
 */
function bz_core_guides_sync() {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	$topics  = bz_core_guides_terms( 'business-article-topics' );
	$types   = bz_core_guides_terms( 'business-article-types' );
	$regions = bz_core_guides_terms( 'region' );
	if ( ! $regions ) {
		return new WP_Error( 'bz_guides_hub', 'Could not reach the content hub.' );
	}
	$fields = 'id,title,slug,link,modified_gmt,content,excerpt,business-article-topics,business-article-types,region';
	$items  = array();
	$page   = 1;
	do {
		list( $batch, $pages ) = bz_core_guides_hub_get( 'wp/v2/business-content?per_page=50&page=' . $page . '&_fields=' . $fields );
		if ( ! is_array( $batch ) ) {
			return new WP_Error( 'bz_guides_hub', 'The content hub stopped answering on page ' . $page . '. Nothing was removed.' );
		}
		$items = array_merge( $items, $batch );
		++$page;
	} while ( $page <= $pages );

	// Existing copies, by hub id.
	$existing = array();
	foreach ( get_posts( array( 'post_type' => 'bz_guide', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
		$existing[ (int) get_post_meta( $id, 'bz_hub_id', true ) ] = $id;
	}

	$added   = 0;
	$updated = 0;
	$seen    = array();
	foreach ( $items as $it ) {
		$hub_id          = (int) $it['id'];
		$seen[ $hub_id ] = true;
		$modified        = (string) $it['modified_gmt'];
		$post_id         = isset( $existing[ $hub_id ] ) ? $existing[ $hub_id ] : 0;
		if ( $post_id && get_post_meta( $post_id, 'bz_hub_modified', true ) === $modified ) {
			continue; // Unchanged on the hub.
		}
		$names = function ( $ids, $map ) {
			return array_values( array_filter( array_map( function ( $id ) use ( $map ) {
				return isset( $map[ (int) $id ] ) ? $map[ (int) $id ] : '';
			}, (array) $ids ) ) );
		};
		$data   = array(
			'post_type'    => 'bz_guide',
			'post_status'  => 'publish',
			'post_title'   => html_entity_decode( (string) $it['title']['rendered'], ENT_QUOTES ),
			'post_name'    => sanitize_title( (string) $it['slug'] ),
			'post_content' => wp_kses_post( (string) $it['content']['rendered'] ),
			'post_excerpt' => wp_trim_words( wp_strip_all_tags( html_entity_decode( (string) $it['excerpt']['rendered'], ENT_QUOTES ) ), 40, '…' ),
		);
		if ( $post_id ) {
			$data['ID'] = $post_id;
		}
		$post_id = wp_insert_post( $data, true );
		if ( is_wp_error( $post_id ) ) {
			continue;
		}
		$region_codes = array_values( array_unique( array_map( 'bz_core_guides_region_code', $names( $it['region'], $regions ) ) ) );
		update_post_meta( $post_id, 'bz_hub_id', $hub_id );
		update_post_meta( $post_id, 'bz_hub_link', esc_url_raw( (string) $it['link'] ) );
		update_post_meta( $post_id, 'bz_hub_modified', $modified );
		update_post_meta( $post_id, 'bz_guide_topics', $names( $it['business-article-topics'], $topics ) );
		update_post_meta( $post_id, 'bz_guide_type', implode( ', ', $names( $it['business-article-types'], $types ) ) );
		update_post_meta( $post_id, 'bz_guide_regions', $region_codes );
		update_post_meta( $post_id, 'bz_guide_words', str_word_count( wp_strip_all_tags( (string) $it['content']['rendered'] ) ) );
		isset( $existing[ $hub_id ] ) ? ++$updated : ++$added;
	}

	// Remove copies whose hub item has gone.
	$removed = 0;
	foreach ( $existing as $hub_id => $post_id ) {
		if ( ! isset( $seen[ $hub_id ] ) ) {
			wp_delete_post( $post_id, true );
			++$removed;
		}
	}
	$summary = sprintf( '%d guides on the hub: %d added, %d updated, %d removed.', count( $items ), $added, $updated, $removed );
	update_option( 'bz_guides_last_sync', array( 'time' => time(), 'summary' => $summary ), false );
	return $summary;
}

/* Is the guide current (updated on the hub within BZ_GUIDES_FRESH_MONTHS)? */
function bz_core_guide_is_fresh( $post_id ) {
	$m = strtotime( (string) get_post_meta( $post_id, 'bz_hub_modified', true ) . ' UTC' );
	return $m && $m > strtotime( '-' . (int) BZ_GUIDES_FRESH_MONTHS . ' months' );
}

/**
 * The library list Claude chooses from: current guides for a region, as [{id, title, topics, type, summary, updated}].
 */
function bz_core_guides_catalogue( $region = '' ) {
	$out = array();
	foreach ( get_posts( array( 'post_type' => 'bz_guide', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
		$regions = (array) get_post_meta( $p->ID, 'bz_guide_regions', true );
		if ( ( $region && $regions && ! in_array( strtoupper( $region ), $regions, true ) ) || ! bz_core_guide_is_fresh( $p->ID ) ) {
			continue;
		}
		$out[] = array(
			'id'      => $p->ID,
			'title'   => $p->post_title,
			'topics'  => (array) get_post_meta( $p->ID, 'bz_guide_topics', true ),
			'type'    => (string) get_post_meta( $p->ID, 'bz_guide_type', true ),
			'summary' => $p->post_excerpt,
			'updated' => substr( (string) get_post_meta( $p->ID, 'bz_hub_modified', true ), 0, 10 ),
		);
	}
	return $out;
}

/* One guide's plain text (for writing from), or ''. */
function bz_core_guide_text( $post_id ) {
	$p = get_post( $post_id );
	return ( $p && 'bz_guide' === $p->post_type ) ? trim( preg_replace( '/\n{3,}/', "\n\n", wp_strip_all_tags( str_replace( array( '</p>', '</li>', '</h2>', '</h3>' ), "\n", $p->post_content ) ) ) ) : '';
}

/* ---------- Admin ---------- */

add_filter(
	'manage_bz_guide_posts_columns',
	function ( $cols ) {
		return array(
			'cb'         => $cols['cb'],
			'title'      => 'Guide',
			'bz_topics'  => 'Topics',
			'bz_regions' => 'Regions',
			'bz_updated' => 'Updated on the hub',
		);
	}
);

add_action(
	'manage_bz_guide_posts_custom_column',
	function ( $col, $id ) {
		if ( 'bz_topics' === $col ) {
			echo esc_html( implode( ', ', (array) get_post_meta( $id, 'bz_guide_topics', true ) ) . ' · ' . get_post_meta( $id, 'bz_guide_type', true ) );
		} elseif ( 'bz_regions' === $col ) {
			echo esc_html( implode( ' ', (array) get_post_meta( $id, 'bz_guide_regions', true ) ) );
		} elseif ( 'bz_updated' === $col ) {
			$m = (string) get_post_meta( $id, 'bz_hub_modified', true );
			echo esc_html( $m ? wp_date( 'j M Y', strtotime( $m . ' UTC' ) ) : '' );
			if ( ! bz_core_guide_is_fresh( $id ) ) {
				echo '<br><span style="color:#b32d2e">' . esc_html( sprintf( 'Older than %d months: left out of drafts until updated on the hub', BZ_GUIDES_FRESH_MONTHS ) ) . '</span>';
			}
			$link = get_post_meta( $id, 'bz_hub_link', true );
			if ( $link ) {
				echo '<br><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">View on the hub</a>';
			}
		}
	},
	10,
	2
);

// Guides are edited on the hub: no inline edit, and a note on the edit screen.
add_filter(
	'post_row_actions',
	function ( $actions, $post ) {
		if ( 'bz_guide' === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	},
	10,
	2
);

add_action(
	'edit_form_top',
	function ( $post ) {
		if ( 'bz_guide' === $post->post_type ) {
			$link = get_post_meta( $post->ID, 'bz_hub_link', true );
			echo '<div class="notice notice-warning inline"><p>This guide comes from the content hub and is replaced by the daily sync. Edit it on the hub'
				. ( $link ? ': <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">open it there</a>' : '' ) . '.</p></div>';
		}
	}
);

add_action(
	'admin_menu',
	function () {
		if ( bz_core_guides_enabled() ) {
			add_submenu_page( 'edit.php?post_type=bz_guide', 'Sync with the hub', 'Sync with the hub', 'manage_options', 'bz-guides-sync', 'bz_core_render_guides_sync' );
		}
	}
);

add_action(
	'admin_post_bz_guides_sync',
	function () {
		check_admin_referer( 'bz_guides_sync' );
		if ( ! current_user_can( 'manage_options' ) || ! bz_core_guides_enabled() ) {
			wp_die( 'Not allowed.' );
		}
		$r = bz_core_guides_sync();
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'bz_guide', 'page' => 'bz-guides-sync', 'bz_msg' => rawurlencode( is_wp_error( $r ) ? 'Not synced: ' . $r->get_error_message() : $r ) ), admin_url( 'edit.php' ) ) );
		exit;
	}
);

function bz_core_render_guides_sync() {
	$last  = (array) get_option( 'bz_guides_last_sync', array() );
	$msg   = isset( $_GET['bz_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bz_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$count = wp_count_posts( 'bz_guide' );
	$stale = 0;
	foreach ( get_posts( array( 'post_type' => 'bz_guide', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
		$stale += bz_core_guide_is_fresh( $id ) ? 0 : 1;
	}
	echo '<div class="wrap"><h1>Business guides: sync with the hub</h1>';
	if ( $msg ) {
		echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
	}
	echo '<p>Guides are written and edited on <a href="' . esc_url( BZ_GUIDES_HUB ) . '" target="_blank" rel="noopener">the content hub</a> (Business Content). The master copies them once a day; the Marketing assistant writes from these copies, citing them.</p>';
	echo '<ul><li>' . esc_html( (int) $count->publish . ' guides on this site.' ) . '</li>';
	echo '<li>' . esc_html( $stale . sprintf( ' not updated on the hub for more than %d months: they are left out of drafts until someone updates them there.', BZ_GUIDES_FRESH_MONTHS ) ) . '</li>';
	echo '<li>' . esc_html( ! empty( $last['time'] ) ? 'Last sync ' . human_time_diff( $last['time'] ) . ' ago: ' . $last['summary'] : 'Not synced yet.' ) . '</li></ul>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'bz_guides_sync' );
	echo '<input type="hidden" name="action" value="bz_guides_sync"><button class="button button-primary">Sync now</button></form></div>';
}
