<?php
/**
 * Partners: software, professional bodies and other firms the practice works with.
 *
 * One post per partner: Title = name, Featured image = logo (or Logo URL), Excerpt = one-line card text,
 * Page Attributes → Order = position. Two switches decide where a partner appears:
 *   "Show in logo areas" → [bz_partners] logo strips (home page, Cloud Accounting, ...)
 *   "Landing page"       → the partner gets a card linking to that page ([bz_partners show=cards])
 * and its logo links there too. Partner type (Software, Professional body, ...) lets a page show one group.
 *
 * Elementor: use an e-collection-loop with source "bz_partner", filtered by the hidden Partner display taxonomy
 * (terms logo-area / landing-page, kept in sync with the two switches) and optionally Partner type. Logo = Featured
 * image, link = custom field bz_link_url (computed: landing page URL, else partner website).
 * The [bz_partners] shortcode is for non-Elementor output only: Elementor's Paragraph widget strips its HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Partner meta: key => [label, type, help]. Types: text, url, checkbox, page.
 */
function bz_core_partner_fields() {
	return array(
		'bz_show_logo'    => array( 'Show in logo areas', 'checkbox', 'Show this logo in the partner logo strips (home page, Cloud Accounting, ...).' ),
		'bz_landing_page' => array( 'Landing page', 'page', 'Optional. The page about this partner, e.g. Services → Cloud Accounting → Xero. Partners with a landing page get a card and a linked logo.' ),
		'bz_logo_url'     => array( 'Logo URL', 'url', 'Used when there is no Featured image. SVG or a transparent PNG, at least 300px wide.' ),
		'bz_partner_url'  => array( 'Partner website', 'url', 'Optional. Logo links here (new tab) when there is no landing page.' ),
		'bz_credential'   => array( 'Our credential', 'text', 'Optional, e.g. Xero Certified Advisors, MYOB Partner. Shown on cards and via [bz_partner field=credential].' ),
	);
}

add_action(
	'init',
	function () {
		register_post_type(
			'bz_partner',
			array(
				'labels'              => array(
					'name'          => 'Partners',
					'singular_name' => 'Partner',
					'add_new_item'  => 'Add New Partner',
					'edit_item'     => 'Edit Partner',
					'all_items'     => 'All Partners',
				),
				'public'              => true, // so Elementor loops list it as a source; no single pages or archive:
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'show_ui'             => true,
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'menu_position'       => 21,
				'menu_icon'           => 'dashicons-awards',
				'supports'            => array( 'title', 'excerpt', 'thumbnail', 'custom-fields', 'page-attributes' ),
			)
		);

		register_taxonomy(
			'bz_partner_type',
			'bz_partner',
			array(
				'labels'            => array(
					'name'          => 'Partner types',
					'singular_name' => 'Partner type',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => false,
			)
		);

		// Where a partner shows, for Elementor loop filters. Not edited by hand: set from the two switches.
		register_taxonomy(
			'bz_partner_display',
			'bz_partner',
			array(
				'labels'             => array(
					'name'          => 'Partner display',
					'singular_name' => 'Partner display',
				),
				'public'             => true,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_rest'       => true,
				'hierarchical'       => false,
				'rewrite'            => false,
			)
		);

		foreach ( bz_core_partner_fields() as $key => $def ) {
			register_post_meta(
				'bz_partner',
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
);

/* ---------- Admin ---------- */

add_action(
	'add_meta_boxes',
	function () {
		add_meta_box( 'bz_partner_details', 'Partner details', 'bz_core_render_partner_box', 'bz_partner', 'normal', 'high' );
	}
);

function bz_core_render_partner_box( $post ) {
	wp_nonce_field( 'bz_partner_save', 'bz_partner_nonce' );
	echo '<table class="form-table" role="presentation">';
	foreach ( bz_core_partner_fields() as $key => $def ) {
		list( $label, $type, $help ) = $def;
		$value = get_post_meta( $post->ID, $key, true );
		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'checkbox' === $type ) {
			echo '<label><input id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="checkbox" value="1"' . checked( $value, '1', false ) . '> Yes</label>';
		} elseif ( 'page' === $type ) {
			wp_dropdown_pages(
				array(
					'name'              => esc_attr( $key ),
					'id'                => esc_attr( $key ),
					'selected'          => absint( $value ),
					'show_option_none'  => '— No landing page —',
					'option_none_value' => '',
					'post_status'       => array( 'publish', 'draft' ),
				)
			);
		} else {
			echo '<input id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" class="large-text">';
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}
	echo '</table><p class="description">Use the <strong>Featured image</strong> for the logo, the <strong>Excerpt</strong> for the one-line text on its card, <strong>Partner types</strong> to group partners (e.g. Software) and <strong>Page Attributes → Order</strong> for the position.</p>';
}

add_action(
	'save_post_bz_partner',
	function ( $post_id ) {
		if ( ! isset( $_POST['bz_partner_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bz_partner_nonce'] ) ), 'bz_partner_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( bz_core_partner_fields() as $key => $def ) {
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
			switch ( $def[1] ) {
				case 'checkbox':
					$value = '1' === $raw ? '1' : '';
					break;
				case 'page':
					$value = absint( $raw ) ? (string) absint( $raw ) : '';
					break;
				case 'url':
					$value = esc_url_raw( $raw );
					break;
				default:
					$value = sanitize_text_field( $raw );
			}
			update_post_meta( $post_id, $key, $value );
		}
		bz_core_sync_partner_display( $post_id );
	}
);

/**
 * Display terms (logo-area, landing-page) from the two switches, for Elementor loop filters.
 */
function bz_core_partner_display_terms() {
	return array(
		'logo-area'    => 'Logo areas',
		'landing-page' => 'Has landing page',
	);
}

function bz_core_sync_partner_display( $post_id ) {
	if ( 'bz_partner' !== get_post_type( $post_id ) ) {
		return;
	}
	$ids = array();
	foreach ( bz_core_partner_display_terms() as $slug => $name ) {
		$term = term_exists( $slug, 'bz_partner_display' );
		if ( ! $term ) {
			$term = wp_insert_term( $name, 'bz_partner_display', array( 'slug' => $slug ) );
		}
		if ( is_wp_error( $term ) ) {
			continue;
		}
		$on = 'logo-area' === $slug
			? '1' === get_post_meta( $post_id, 'bz_show_logo', true )
			: absint( get_post_meta( $post_id, 'bz_landing_page', true ) ) > 0;
		if ( $on ) {
			$ids[] = (int) $term['term_id'];
		}
	}
	wp_set_object_terms( $post_id, $ids, 'bz_partner_display' );
}

/* Keep the terms right when the switches change through the REST API or a script, too. */
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $bz_hook ) {
	add_action(
		$bz_hook,
		function ( $meta_id, $post_id, $meta_key ) {
			if ( in_array( $meta_key, array( 'bz_show_logo', 'bz_landing_page' ), true ) ) {
				bz_core_sync_partner_display( $post_id );
			}
		},
		10,
		3
	);
}

/**
 * bz_link_url (read-only, computed): the landing page URL when it is published, else the partner website.
 * Elementor's Post Custom Field tag reads it for logo and card links.
 */
add_filter(
	'get_post_metadata',
	function ( $value, $post_id, $meta_key ) {
		if ( 'bz_link_url' !== $meta_key || 'bz_partner' !== get_post_type( $post_id ) ) {
			return $value;
		}
		list( $url ) = bz_core_partner_link( get_post( $post_id ) );
		return array( $url );
	},
	10,
	3
);

/* Admin list: show the two switches at a glance. */
add_filter(
	'manage_bz_partner_posts_columns',
	function ( $columns ) {
		$columns['bz_show_logo']    = 'Logo areas';
		$columns['bz_landing_page'] = 'Landing page';
		return $columns;
	}
);

add_action(
	'manage_bz_partner_posts_custom_column',
	function ( $column, $post_id ) {
		if ( 'bz_show_logo' === $column ) {
			echo get_post_meta( $post_id, 'bz_show_logo', true ) ? '✓' : '—';
		}
		if ( 'bz_landing_page' === $column ) {
			$page = absint( get_post_meta( $post_id, 'bz_landing_page', true ) );
			echo $page ? '<a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>' : '—';
		}
	},
	10,
	2
);

/* ---------- Front end ---------- */

/**
 * Partners for a shortcode: published, ordered, optionally one type, filtered by show (logos|cards).
 */
function bz_core_partners( $show, $type = '' ) {
	$args = array(
		'post_type'   => 'bz_partner',
		'post_status' => 'publish',
		'numberposts' => 50,
		'orderby'     => array(
			'menu_order' => 'ASC',
			'title'      => 'ASC',
		),
	);
	if ( '' !== $type ) {
		$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- small list.
			array(
				'taxonomy' => 'bz_partner_type',
				'field'    => 'slug',
				'terms'    => array_map( 'sanitize_title', explode( ',', $type ) ),
			),
		);
	}
	$key                = 'cards' === $show ? 'bz_landing_page' : 'bz_show_logo';
	$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small list.
		array(
			'key'     => $key,
			'value'   => array( '', '0' ),
			'compare' => 'NOT IN',
		),
	);
	return get_posts( $args );
}

function bz_core_partner_logo( $post, $height ) {
	$alt = get_the_title( $post );
	$url = '';
	$tid = get_post_thumbnail_id( $post );
	if ( $tid ) {
		$src = wp_get_attachment_image_src( $tid, 'medium' );
		$url = $src ? $src[0] : '';
	}
	if ( '' === $url ) {
		$url = get_post_meta( $post->ID, 'bz_logo_url', true );
	}
	if ( '' === $url ) {
		return '<span class="bz-partner-name">' . esc_html( $alt ) . '</span>';
	}
	return sprintf( '<img src="%1$s" alt="%2$s" style="height:%3$dpx" loading="lazy" decoding="async">', esc_url( $url ), esc_attr( $alt ), $height );
}

function bz_core_partner_link( $post ) {
	$page = absint( get_post_meta( $post->ID, 'bz_landing_page', true ) );
	if ( $page && 'publish' === get_post_status( $page ) ) {
		return array( get_permalink( $page ), false );
	}
	$site = get_post_meta( $post->ID, 'bz_partner_url', true );
	return '' !== $site ? array( $site, true ) : array( '', false );
}

function bz_core_partner_styles() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	return '<style>'
		. '.bz-partners{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:24px 48px}'
		. '.bz-partners a,.bz-partners>span{display:inline-flex;align-items:center;transition:opacity .2s}'
		. '.bz-partners a:hover{opacity:.75}'
		. '.bz-partners img{width:auto;max-width:180px;display:block}'
		. '.bz-partner-name{font-weight:700;color:var(--color-heading,#1b2a41)}'
		. '.bz-partner-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--space-md,24px)}'
		. '.bz-partner-card{display:flex;flex-direction:column;gap:12px;padding:var(--space-md,24px);background:var(--color-surface,#fff);border:1px solid var(--color-border,#e3e7ee);border-radius:var(--radius-md,12px);text-decoration:none;color:inherit;transition:border-color .2s}'
		. '.bz-partner-card:hover{border-color:var(--color-primary,#1f3a5f)}'
		. '.bz-partner-card .bz-partner-logo{height:56px;display:flex;align-items:center}'
		. '.bz-partner-card img{width:auto;max-width:160px;display:block}'
		. '.bz-partner-card h3{margin:0;font-size:var(--text-h4,1.25rem)}'
		. '.bz-partner-card p{margin:0;color:var(--color-text-muted,#5b6778)}'
		. '.bz-partner-card .bz-partner-text{flex:1 1 auto}'
		. '.bz-partner-card .bz-partner-more{font-weight:600;color:var(--color-primary,#1f3a5f)}'
		. '@media(max-width:767px){.bz-partners{gap:20px 32px}}'
		. '</style>';
}

/**
 * [bz_partners] — partner logos or cards.
 *   show="logos" (default): partners ticked "Show in logo areas"; logos link to the landing page (or website).
 *   show="cards": partners with a landing page: logo, name, excerpt, credential, "Learn more →".
 *   type="software" limits to one Partner type (comma separated for several). height="48" sets the logo height.
 * Outputs nothing when no partner matches.
 */
add_shortcode(
	'bz_partners',
	function ( $atts ) {
		$atts     = shortcode_atts(
			array(
				'show'   => 'logos',
				'type'   => '',
				'height' => '48',
			),
			$atts,
			'bz_partners'
		);
		$show     = 'cards' === $atts['show'] ? 'cards' : 'logos';
		$height   = max( 24, min( 120, (int) $atts['height'] ) );
		$partners = bz_core_partners( $show, $atts['type'] );
		if ( ! $partners ) {
			return '';
		}

		$out = '';
		foreach ( $partners as $p ) {
			list( $url, $external ) = bz_core_partner_link( $p );
			if ( 'cards' === $show ) {
				$credential = get_post_meta( $p->ID, 'bz_credential', true );
				$out       .= sprintf(
					'<a class="bz-partner-card" href="%1$s"><span class="bz-partner-logo">%2$s</span><h3>%3$s</h3><p class="bz-partner-text">%4$s</p>%5$s<p class="bz-partner-more">Learn more →</p></a>',
					esc_url( $url ),
					bz_core_partner_logo( $p, min( $height, 56 ) ),
					esc_html( get_the_title( $p ) ),
					esc_html( get_the_excerpt( $p ) ),
					'' !== $credential ? '<p><strong>' . esc_html( $credential ) . '</strong></p>' : ''
				);
				continue;
			}
			$logo = bz_core_partner_logo( $p, $height );
			if ( '' === $url ) {
				$out .= '<span>' . $logo . '</span>';
			} else {
				$out .= sprintf(
					'<a href="%1$s"%2$s>%3$s</a>',
					esc_url( $url ),
					$external ? ' target="_blank" rel="noopener"' : '',
					$logo
				);
			}
		}
		$class = 'cards' === $show ? 'bz-partner-cards' : 'bz-partners';
		return bz_core_partner_styles() . '<span class="' . $class . '" style="display:' . ( 'cards' === $show ? 'grid' : 'flex' ) . '">' . $out . '</span>';
	}
);

/**
 * [bz_partner field="credential|name"] — the partner whose landing page is the current page (or slug="xero").
 * Returns '' when nothing matches, so the widget collapses.
 */
add_shortcode(
	'bz_partner',
	function ( $atts ) {
		$atts = shortcode_atts(
			array(
				'field' => 'credential',
				'slug'  => '',
			),
			$atts,
			'bz_partner'
		);
		$args = array(
			'post_type'   => 'bz_partner',
			'post_status' => 'publish',
			'numberposts' => 1,
		);
		if ( '' !== $atts['slug'] ) {
			$args['name'] = sanitize_title( $atts['slug'] );
		} else {
			$args['meta_key']   = 'bz_landing_page'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = (string) get_queried_object_id(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}
		$found = get_posts( $args );
		if ( ! $found ) {
			return '';
		}
		if ( 'name' === $atts['field'] ) {
			return esc_html( get_the_title( $found[0] ) );
		}
		return esc_html( get_post_meta( $found[0]->ID, 'bz_credential', true ) );
	}
);

/* ---------- Starter partners ---------- */

/**
 * Once per site: add Xero, MYOB and QuickBooks (type Software, shown in logo areas) when there are no partners yet,
 * linking each to services/cloud-accounting/{slug} when that page exists. Edit or delete them freely afterwards.
 */
add_action(
	'admin_init',
	function () {
		if ( get_option( 'bz_core_partners_seeded' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		update_option( 'bz_core_partners_seeded', BZ_CORE_VERSION );
		if ( get_posts( array( 'post_type' => 'bz_partner', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) ) ) {
			return;
		}
		$term = term_exists( 'software', 'bz_partner_type' );
		if ( ! $term ) {
			$term = wp_insert_term( 'Software', 'bz_partner_type', array( 'slug' => 'software' ) );
		}
		$term_id = is_array( $term ) ? (int) $term['term_id'] : 0;
		$assets  = 'https://bizink-web-assets.s3.ap-southeast-2.amazonaws.com/';
		$starter = array(
			array( 'xero', 'Xero', 'xero-logo-trans.png', 'https://www.xero.com/', 'Setup, moving from other software, training, payroll and BAS in Xero.' ),
			array( 'myob', 'MYOB', 'myob/myob-logo.png', 'https://www.myob.com/', 'From Solo to AccountRight: setup, upgrades, training, payroll and BAS.' ),
			array( 'quickbooks', 'QuickBooks', 'quickbooks/QB-150x150.png', 'https://quickbooks.intuit.com/', 'Setup, moving from MYOB or Reckon, training, payroll and BAS in QuickBooks.' ),
		);
		foreach ( $starter as $i => $s ) {
			list( $slug, $name, $logo, $site, $excerpt ) = $s;
			$page = get_page_by_path( 'services/cloud-accounting/' . $slug );
			$id   = wp_insert_post(
				array(
					'post_type'    => 'bz_partner',
					'post_status'  => 'publish',
					'post_title'   => $name,
					'post_name'    => $slug,
					'post_excerpt' => $excerpt,
					'menu_order'   => $i + 1,
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, 'bz_show_logo', '1' );
			update_post_meta( $id, 'bz_logo_url', $assets . $logo );
			update_post_meta( $id, 'bz_partner_url', $site );
			update_post_meta( $id, 'bz_landing_page', $page ? (string) $page->ID : '' );
			if ( $term_id ) {
				wp_set_object_terms( $id, array( $term_id ), 'bz_partner_type' );
			}
		}
	}
);

/**
 * On each plugin version: sync display terms for every partner, and copy Logo URLs into the Media Library
 * as Featured images where a partner has none (Elementor loops show the Featured image).
 */
add_action(
	'admin_init',
	function () {
		if ( get_option( 'bz_core_partners_version' ) === BZ_CORE_VERSION || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		update_option( 'bz_core_partners_version', BZ_CORE_VERSION );
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ids = get_posts( array( 'post_type' => 'bz_partner', 'post_status' => 'any', 'numberposts' => 100, 'fields' => 'ids' ) );
		foreach ( $ids as $id ) {
			bz_core_sync_partner_display( $id );
			$logo = get_post_meta( $id, 'bz_logo_url', true );
			if ( '' === $logo || has_post_thumbnail( $id ) ) {
				continue;
			}
			$attachment = media_sideload_image( $logo, $id, get_the_title( $id ) . ' logo', 'id' );
			if ( ! is_wp_error( $attachment ) ) {
				update_post_meta( $attachment, '_wp_attachment_image_alt', get_the_title( $id ) );
				set_post_thumbnail( $id, $attachment );
			}
		}
	},
	20
);
