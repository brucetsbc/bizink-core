<?php
/**
 * Admin-only REST API for scripted site set-up (tools/new_site.py).
 *
 * GET  /wp-json/bizink/v1/client-details          Current Client Details.
 * POST /wp-json/bizink/v1/client-details          Merge fields into Client Details (same sanitising as the form).
 * POST /wp-json/bizink/v1/region-pack             Apply the region pack for the current region.
 * GET  /wp-json/bizink/v1/status                  Plugin version, region and content counts.
 * POST /wp-json/bizink/v1/flush-elementor         Clear Elementor files and element cache (after scripted content).
 *
 * All routes need an administrator (Application Password over HTTPS).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {
		$admin = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			'bizink/v1',
			'/client-details',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $admin,
					'callback'            => function () {
						return rest_ensure_response( get_option( BZ_CORE_OPTION, array() ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $admin,
					'callback'            => function ( WP_REST_Request $request ) {
						$incoming = (array) $request->get_json_params();
						$unknown  = array_diff( array_keys( $incoming ), array_keys( bz_core_fields() ) );
						if ( $unknown ) {
							return new WP_Error( 'bz_unknown_fields', 'Unknown fields: ' . implode( ', ', $unknown ), array( 'status' => 400 ) );
						}
						$merged = array_merge( (array) get_option( BZ_CORE_OPTION, array() ), $incoming );
						update_option( BZ_CORE_OPTION, bz_core_sanitize( $merged ) );
						return rest_ensure_response( get_option( BZ_CORE_OPTION, array() ) );
					},
				),
			)
		);

		// Elementor → Tools → Clear Files & Data, for scripts that write page content directly (copy_site.py).
		register_rest_route(
			'bizink/v1',
			'/flush-elementor',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => function () {
					if ( ! class_exists( '\Elementor\Plugin' ) ) {
						return new WP_Error( 'bz_no_elementor', 'Elementor is not active.', array( 'status' => 400 ) );
					}
					\Elementor\Plugin::$instance->files_manager->clear_cache();
					delete_post_meta_by_key( '_elementor_element_cache' );
					return rest_ensure_response( array( 'ok' => true ) );
				},
			)
		);

		register_rest_route(
			'bizink/v1',
			'/region-pack',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => function () {
					$renamed = bz_core_apply_region_pack();
					$pages   = array();
					foreach ( bz_core_region_plan() as $item ) {
						$pages[ $item['key'] ] = array(
							'title' => get_the_title( $item['page']->ID ),
							'url'   => get_permalink( $item['page']->ID ),
						);
					}
					return rest_ensure_response(
						array(
							'region'  => bz_core_get( 'region' ),
							'renamed' => $renamed,
							'pages'   => $pages,
						)
					);
				},
			)
		);

		register_rest_route(
			'bizink/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function () {
					$count = function ( $type ) {
						$counts = wp_count_posts( $type );
						return isset( $counts->publish ) ? (int) $counts->publish : 0;
					};
					return rest_ensure_response(
						array(
							'version'     => BZ_CORE_VERSION,
							'region'      => bz_core_get( 'region' ),
							'firm_name'   => bz_core_get( 'firm_name' ),
							'resources'   => $count( 'bz_resource' ),
							'calculators' => $count( 'bz_calculator' ),
							'team'        => $count( 'bz_team' ),
							'posts'       => $count( 'post' ),
							'redirects'   => count( (array) get_option( 'bz_core_redirects', array() ) ),
							'indexing'    => (bool) get_option( 'blog_public' ),
						)
					);
				},
			)
		);
	}
);
