<?php
/**
 * Old-site redirects into the Redirection plugin (bizink-core 0.32.0).
 *
 * POST /wp-json/bizink/v1/redirects  {rows: [{source, target}], dry_run?}
 *   Adds 301 redirects through Redirection's own classes (Red_Group / Red_Item, Redirection 5.x) into the group
 *   "Old site (Bizink)". Skips rows that already exist, rows whose source is a live page on this site (the redirect
 *   would hide it) and rows that aren't site paths. Safe to run again. tools/redirects.py and new_site.py call it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BZ_REDIRECT_GROUP = 'Old site (Bizink)';

function bz_core_redirect_norm( $path ) {
	$path = strtolower( trim( (string) $path ) );
	return '/' === $path ? '/' : untrailingslashit( $path );
}

function bz_core_redirect_group_id( $create ) {
	global $wpdb;
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}redirection_groups WHERE name = %s ORDER BY id LIMIT 1", BZ_REDIRECT_GROUP ) );
	if ( ! $id && $create ) {
		$group = Red_Group::create( BZ_REDIRECT_GROUP, 1 ); // 1 = Redirection's WordPress module.
		$id    = $group ? (int) $group->get_id() : 0;
	}
	return $id;
}

function bz_core_redirects_import( array $rows, $dry_run ) {
	global $wpdb;
	if ( ! class_exists( 'Red_Item' ) || ! class_exists( 'Red_Group' ) ) {
		return new WP_Error( 'bz_no_redirection', 'The Redirection plugin is not active on this site.', array( 'status' => 409 ) );
	}
	$existing = array_flip( array_map( 'bz_core_redirect_norm', (array) $wpdb->get_col( "SELECT url FROM {$wpdb->prefix}redirection_items" ) ) );
	$group    = $dry_run ? bz_core_redirect_group_id( false ) : bz_core_redirect_group_id( true );
	if ( ! $dry_run && ! $group ) {
		return new WP_Error( 'bz_redirect_group', 'Could not create the Redirection group.', array( 'status' => 500 ) );
	}
	$out = array( 'added' => array(), 'exists' => array(), 'live_page' => array(), 'invalid' => array(), 'group' => BZ_REDIRECT_GROUP );
	foreach ( $rows as $row ) {
		$source = isset( $row['source'] ) ? trim( (string) $row['source'] ) : '';
		$target = isset( $row['target'] ) ? trim( (string) $row['target'] ) : '';
		$key    = bz_core_redirect_norm( $source );
		if ( '' === $source || '/' !== $source[0] || '' === $target || bz_core_redirect_norm( $target ) === $key || ( '/' !== $target[0] && ! wp_http_validate_url( $target ) ) ) {
			$out['invalid'][] = $source . ' -> ' . $target;
			continue;
		}
		if ( isset( $existing[ $key ] ) ) {
			$out['exists'][] = $source;
			continue;
		}
		if ( '/' !== $key && url_to_postid( home_url( $source ) ) ) {
			$out['live_page'][] = $source;
			continue;
		}
		if ( ! $dry_run ) {
			$item = Red_Item::create(
				array(
					'url'         => $source,
					'action_data' => array( 'url' => $target ),
					'action_type' => 'url',
					'action_code' => 301,
					'match_type'  => 'url',
					'group_id'    => $group,
					'regex'       => false,
				)
			);
			if ( is_wp_error( $item ) ) {
				$out['invalid'][] = $source . ' -> ' . $target . ' (' . $item->get_error_message() . ')';
				continue;
			}
		}
		$existing[ $key ] = true;
		$out['added'][]   = $source . ' -> ' . $target;
	}
	return $out;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bizink/v1',
			'/redirects',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function ( WP_REST_Request $r ) {
					$rows = $r->get_param( 'rows' );
					if ( ! is_array( $rows ) ) {
						return new WP_Error( 'bz_rows', 'rows must be a list of {source, target}.', array( 'status' => 400 ) );
					}
					return bz_core_redirects_import( $rows, (bool) $r->get_param( 'dry_run' ) );
				},
			)
		);
	}
);
