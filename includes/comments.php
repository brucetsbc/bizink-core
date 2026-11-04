<?php
/**
 * Comments are off on every Bizink site (rule since 0.35.0): no comment areas, no new comments or pings, on any post type.
 *
 * Existing comments stay in the database but aren't shown. Works on Elementor and block sites and doesn't depend on the
 * per-post "allow comments" setting; those settings are also closed once per site so a later hand-over keeps them closed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'comments_open', '__return_false', 99 );
add_filter( 'pings_open', '__return_false', 99 );
add_filter( 'comments_array', '__return_empty_array', 99 );
add_filter( 'get_comments_number', '__return_zero', 99 );
add_filter( 'feed_links_show_comments_feed', '__return_false' );

/* Classic templates and Elementor's Post Comments widget. */
add_filter(
	'comments_template',
	function () {
		return __DIR__ . '/comments-off.php';
	},
	99
);

/* Block themes: the Comments block prints its heading even with comments closed. */
add_filter(
	'render_block',
	function ( $content, $block ) {
		$off = array( 'core/comments', 'core/post-comments', 'core/post-comments-form', 'core/post-comments-link', 'core/post-comments-count', 'core/comments-title', 'core/latest-comments' );
		return isset( $block['blockName'] ) && in_array( $block['blockName'], $off, true ) ? '' : $content;
	},
	10,
	2
);

/* Pingbacks. */
add_filter(
	'xmlrpc_methods',
	function ( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}
);
add_filter(
	'wp_headers',
	function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

add_action(
	'init',
	function () {
		foreach ( get_post_types() as $type ) {
			remove_post_type_support( $type, 'comments' );
			remove_post_type_support( $type, 'trackbacks' );
		}
		if ( get_option( 'bz_comments_closed' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->posts} SET comment_status = 'closed', ping_status = 'closed' WHERE comment_status <> 'closed' OR ping_status <> 'closed'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off, no user input.
		update_option( 'default_comment_status', 'closed' );
		update_option( 'default_ping_status', 'closed' );
		update_option( 'bz_comments_closed', 1 );
	},
	99
);

/* Nothing to manage in wp-admin. */
add_action(
	'admin_menu',
	function () {
		remove_menu_page( 'edit-comments.php' );
	},
	99
);
add_action(
	'admin_bar_menu',
	function ( $bar ) {
		$bar->remove_node( 'comments' );
	},
	99
);
