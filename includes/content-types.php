<?php
/**
 * Resources (PDF landing pages), Calculators (smartbizcalcs.com embeds) and Team members.
 *
 * Each item is one post; a single Elementor Theme Builder template renders all items of a type.
 * Meta is registered with show_in_rest so items can be created by script and read by Elementor's
 * "Post Custom Field" dynamic tag.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BZ_RESIZER_JS', 'https://smartbizcalcs.com/js/iframeResizer.min.js' );

/**
 * Meta fields per post type: key => [label, type, help].
 */
function bz_core_meta_fields() {
	return array(
		'bz_resource'   => array(
			'bz_pdf_url'        => array( 'PDF URL', 'url', 'Media Library URL of the PDF (upload it, then copy the file URL).' ),
			'bz_xlsx_url'       => array( 'Excel URL', 'url', 'Optional. Media Library URL of the Excel version.' ),
			'bz_region'         => array( 'Region', 'region', '' ),
			'bz_tool'           => array( 'Master tool key', 'text', 'e.g. growth-checklist — matches site-builder/masters.' ),
			'bz_master_version' => array( 'Master version', 'text', 'Version of the master this page was rendered from.' ),
			'bz_button_label'   => array( 'Button label', 'text', 'Defaults to “Download the PDF”.' ),
			'bz_helps_text'     => array( 'How we help', 'text', 'One or two sentences on how this firm helps with this topic. Customise per site.' ),
		),
		'bz_calculator' => array(
			'bz_calc_url'    => array( 'Calculator URL', 'url', 'e.g. https://smartbizcalcs.com/{client}/break-even-calculator/index.html' ),
			'bz_region'      => array( 'Region', 'region', '' ),
			'bz_calc_regions' => array( 'Only for regions', 'text', 'Region codes this calculator is for, e.g. AU or UK IE (tax calculators). Empty = every site. Other sites hide it.' ),
			'bz_calc_height' => array( 'Starting height (px)', 'number', 'Used until iframe-resizer adjusts the height. Default 900.' ),
		),
		'bz_team'       => array(
			'bz_role'           => array( 'Role', 'text', 'e.g. Director, Senior Accountant, Bookkeeper' ),
			'bz_qualifications' => array( 'Qualifications', 'text', 'e.g. CA, Registered Tax Agent, BCom' ),
			'bz_email'          => array( 'Email', 'email', 'Optional. Shown on the profile page.' ),
			'bz_phone'          => array( 'Direct phone', 'text', 'Optional.' ),
			'bz_linkedin'       => array( 'LinkedIn URL', 'url', 'Optional.' ),
		),
	);
}

add_action(
	'init',
	function () {
		$common = array(
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => false,
			'menu_position' => 21,
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ),
		);

		register_post_type(
			'bz_resource',
			$common + array(
				'labels'    => array(
					'name'          => 'Resources',
					'singular_name' => 'Resource',
					'add_new_item'  => 'Add New Resource',
					'edit_item'     => 'Edit Resource',
					'all_items'     => 'All Resources',
				),
				'menu_icon' => 'dashicons-media-document',
				'rewrite'   => array( 'slug' => 'downloads', 'with_front' => false ),
			)
		);

		register_post_type(
			'bz_calculator',
			$common + array(
				'labels'    => array(
					'name'          => 'Calculators',
					'singular_name' => 'Calculator',
					'add_new_item'  => 'Add New Calculator',
					'edit_item'     => 'Edit Calculator',
					'all_items'     => 'All Calculators',
				),
				'menu_icon' => 'dashicons-calculator',
				'rewrite'   => array( 'slug' => 'calculators', 'with_front' => false ),
			)
		);

		// Team: Title = name, Featured image = photo, Excerpt = short bio, content = full bio, Order = display order.
		register_post_type(
			'bz_team',
			array(
				'supports'  => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions', 'page-attributes' ),
				'labels'    => array(
					'name'          => 'Team',
					'singular_name' => 'Team member',
					'add_new_item'  => 'Add New Team Member',
					'edit_item'     => 'Edit Team Member',
					'all_items'     => 'All Team Members',
				),
				'menu_icon' => 'dashicons-groups',
				'rewrite'   => array( 'slug' => 'team', 'with_front' => false ),
			) + $common
		);

		// Resource type (Checklist, Template) so Downloads can list them separately.
		register_taxonomy(
			'bz_resource_type',
			'bz_resource',
			array(
				'labels'            => array(
					'name'          => 'Resource types',
					'singular_name' => 'Resource type',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => false,
			)
		);

		foreach ( bz_core_meta_fields() as $post_type => $fields ) {
			foreach ( $fields as $key => $def ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => 'url' === $def[1] ? 'esc_url_raw' : 'sanitize_text_field',
						'auth_callback'     => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		}
	}
);

/* Flush rewrite rules once after the post types first exist (and after version bumps). */
add_action(
	'init',
	function () {
		if ( get_option( 'bz_core_rewrite_version' ) !== BZ_CORE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'bz_core_rewrite_version', BZ_CORE_VERSION );
		}
	},
	99
);

/* ---------- Meta box ---------- */

add_action(
	'add_meta_boxes',
	function () {
		foreach ( array_keys( bz_core_meta_fields() ) as $post_type ) {
			add_meta_box( 'bz_details', 'Details', 'bz_core_render_meta_box', $post_type, 'normal', 'high' );
		}
	}
);

function bz_core_render_meta_box( $post ) {
	wp_nonce_field( 'bz_meta_save', 'bz_meta_nonce' );
	$fields = bz_core_meta_fields()[ $post->post_type ];
	echo '<table class="form-table" role="presentation">';
	foreach ( $fields as $key => $def ) {
		list( $label, $type, $help ) = $def;
		$value = get_post_meta( $post->ID, $key, true );
		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'region' === $type ) {
			$value = $value ? $value : bz_core_get( 'region' );
			echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
			foreach ( bz_core_regions() as $code => $region_label ) {
				echo '<option value="' . esc_attr( $code ) . '"' . selected( $value, $code, false ) . '>' . esc_html( $region_label ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" class="large-text">';
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}
	if ( 'bz_team' === $post->post_type ) {
		echo '</table><p class="description">Use the <strong>Title</strong> for the person’s name, the <strong>Featured image</strong> for their photo (square works best), the <strong>Excerpt</strong> for a one-line bio and <strong>Page Attributes → Order</strong> to set their position on the team page.</p>';
		return;
	}
	echo '</table><p class="description">Use the <strong>Excerpt</strong> for the intro shown under the title, and the <strong>Featured image</strong> for the cover.</p>';
}

add_action(
	'save_post',
	function ( $post_id, $post ) {
		$all = bz_core_meta_fields();
		if ( ! isset( $all[ $post->post_type ] ) || ! isset( $_POST['bz_meta_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bz_meta_nonce'] ) ), 'bz_meta_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( $all[ $post->post_type ] as $key => $def ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$raw   = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
			$value = 'url' === $def[1] ? esc_url_raw( $raw ) : sanitize_text_field( $raw );
			if ( 'region' === $def[1] && ! array_key_exists( $value, bz_core_regions() ) ) {
				$value = bz_core_get( 'region' );
			}
			update_post_meta( $post_id, $key, $value );
		}
	},
	10,
	2
);

/* ---------- Front end ---------- */

/**
 * [bz_calculator] — embeds the current calculator (or id="123") with iframe-resizer.
 */
function bz_core_calculator_embed( $post_id = 0 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$url     = get_post_meta( $post_id, 'bz_calc_url', true );
	if ( ! $url ) {
		return current_user_can( 'edit_post', $post_id ) ? '<p><em>Calculator URL not set.</em></p>' : '';
	}
	$height = absint( get_post_meta( $post_id, 'bz_calc_height', true ) );
	$height = $height ? $height : 900;
	$id     = 'bz-calc-' . $post_id;

	wp_enqueue_script( 'bz-iframe-resizer', BZ_RESIZER_JS, array(), null, true );
	wp_add_inline_script( 'bz-iframe-resizer', "iFrameResize({checkOrigin:false},'.bz-calc-frame');" );

	return sprintf(
		'<div class="bz-calc"><iframe id="%1$s" class="bz-calc-frame" src="%2$s" title="%3$s" scrolling="no" loading="lazy" style="width:1px;min-width:100%%;height:%4$dpx;border:0;display:block;"></iframe></div>',
		esc_attr( $id ),
		esc_url( $url ),
		esc_attr( get_the_title( $post_id ) ),
		$height
	);
}

add_shortcode(
	'bz_calculator',
	function ( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'bz_calculator' );
		return bz_core_calculator_embed( absint( $atts['id'] ) );
	}
);

/**
 * On single calculators, put the embed above the post content so the Elementor template only needs
 * one Post Content widget. Skipped if the content already contains [bz_calculator].
 */
add_filter(
	'the_content',
	function ( $content ) {
		if ( is_singular( 'bz_calculator' ) && in_the_loop() && is_main_query() && false === strpos( $content, 'bz-calc-frame' ) ) {
			return bz_core_calculator_embed() . $content;
		}
		return $content;
	},
	5
);

/**
 * [bz_pdf field="url|label|link"] — the current resource's PDF URL or button label (for Elementor dynamic tags).
 * slug="business-plan" reads another resource (any status), e.g. for a gated download page whose resource is unpublished.
 * field="link" outputs a ready-made link: [bz_pdf field=link slug=business-plan text="Download the template (PDF)"].
 */
add_shortcode(
	'bz_pdf',
	function ( $atts ) {
		$atts = shortcode_atts( array( 'field' => 'url', 'slug' => '', 'text' => '' ), $atts, 'bz_pdf' );
		$id   = get_the_ID();
		if ( '' !== $atts['slug'] ) {
			$found = get_posts(
				array(
					'post_type'   => 'bz_resource',
					'name'        => sanitize_title( $atts['slug'] ),
					'post_status' => array( 'publish', 'draft', 'private' ),
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			if ( ! $found ) {
				return '';
			}
			$id = $found[0];
		}
		if ( 'link' === $atts['field'] ) {
			$url = get_post_meta( $id, 'bz_pdf_url', true );
			if ( ! $url ) {
				return '';
			}
			$text = '' !== $atts['text'] ? $atts['text'] : 'Download the PDF';
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><strong>' . esc_html( $text ) . '</strong></a>';
		}
		if ( 'label' === $atts['field'] ) {
			$label = get_post_meta( $id, 'bz_button_label', true );
			return esc_html( $label ? $label : 'Download the PDF' );
		}
		if ( 'helps' === $atts['field'] ) {
			$helps = get_post_meta( $id, 'bz_helps_text', true );
			if ( ! $helps ) {
				$helps = bz_core_get( 'firm_name' ) . ' works with business owners to turn plans like these into numbers they can act on. ' . bz_core_get( 'offer_sentence' );
			}
			return esc_html( $helps );
		}
		if ( 'url' === $atts['field'] ) {
			return esc_url( get_post_meta( $id, 'bz_pdf_url', true ) );
		}
		return '';
	}
);

/**
 * [bz_team field="email|email_href|phone|phone_href|linkedin_href|linkedin_label"] — the current team member's
 * contact details for Elementor dynamic tags. Returns '' when the field is blank so the widget collapses.
 */
add_shortcode(
	'bz_team',
	function ( $atts ) {
		$atts  = shortcode_atts( array( 'field' => '' ), $atts, 'bz_team' );
		$id    = get_the_ID();
		$email = sanitize_email( get_post_meta( $id, 'bz_email', true ) );
		$phone = trim( (string) get_post_meta( $id, 'bz_phone', true ) );
		$li    = trim( (string) get_post_meta( $id, 'bz_linkedin', true ) );

		switch ( $atts['field'] ) {
			case 'email':
				return esc_html( $email );
			case 'email_href':
				return $email ? esc_url( 'mailto:' . $email, array( 'mailto' ) ) : '';
			case 'phone':
				return esc_html( $phone );
			case 'phone_href':
				return '' !== $phone ? esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ), array( 'tel' ) ) : '';
			case 'linkedin_href':
				return esc_url( $li );
			case 'linkedin_label':
				return '' !== $li ? 'LinkedIn profile' : '';
		}
		return '';
	}
);

/* Basic styling for the embed and the rendered resource sections (uses the kit's V4 tokens). */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_singular( array( 'bz_resource', 'bz_calculator' ) ) ) {
			return;
		}
		wp_register_style( 'bz-core', false, array(), BZ_CORE_VERSION );
		wp_enqueue_style( 'bz-core' );
		wp_add_inline_style(
			'bz-core',
			'.bz-calc{margin:0 0 var(--space-lg,48px)}' // Calculators draw their own border inside the iframe.
		);
	}
);

/*
 * Region-only calculators (tax: GST, VAT, sales tax). "Only for regions" lists the region codes a calculator belongs
 * to (e.g. "AU" or "UK IE"); empty = every site. Elsewhere it's left out of lists, menus, search and the sitemap,
 * and its page is "not found" (editors can still open it).
 */
function bz_core_calc_other_region_ids() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	global $wpdb;
	$region = strtoupper( bz_core_get( 'region' ) );
	$ids    = array();
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", 'bz_calc_regions' ) );
	foreach ( $rows as $r ) {
		$list = preg_split( '/[\s,]+/', strtoupper( $r->meta_value ), -1, PREG_SPLIT_NO_EMPTY );
		if ( $list && ! in_array( $region, $list, true ) ) {
			$ids[] = (int) $r->post_id;
		}
	}
	return $ids;
}

add_action(
	'pre_get_posts',
	function ( $q ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$types = (array) $q->get( 'post_type' );
		if ( ! in_array( 'bz_calculator', $types, true ) && ! in_array( 'any', $types, true ) && ! $q->is_search() ) {
			return;
		}
		$ids = bz_core_calc_other_region_ids();
		if ( $ids ) {
			$q->set( 'post__not_in', array_merge( (array) $q->get( 'post__not_in' ), $ids ) );
		}
	}
);
add_action(
	'template_redirect',
	function () {
		if ( is_singular( 'bz_calculator' ) && ! current_user_can( 'edit_pages' ) && in_array( (int) get_queried_object_id(), bz_core_calc_other_region_ids(), true ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	},
	5
);
add_filter(
	'wp_nav_menu_objects',
	function ( $items ) {
		$ids = bz_core_calc_other_region_ids();
		return $ids ? array_values( array_filter( $items, function ( $item ) use ( $ids ) {
			return ! ( 'bz_calculator' === $item->object && in_array( (int) $item->object_id, $ids, true ) );
		} ) ) : $items;
	}
);
