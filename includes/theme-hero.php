<?php
/**
 * Photo-banner home hero for themes that have one (theme JSON "hero": "image", e.g. Horizon).
 *
 * Appearance → Site theme offers "Use the home banner" on those themes. Applying with it:
 *   1. copies the starter photos from the master into this site's media library (once);
 *   2. builds the chosen component if missing: HORIZON-HERO-01 (one photo), HORIZON-HERO-02 (three-photo slider) or
 *      HORIZON-HERO-03 (three photos, each with its own pill, headline, text and buttons);
 *   3. puts the chosen one at the top of the home page, replacing the home hero component that was there.
 * Undo puts the home page back exactly as it was (its Elementor data is kept in the theme history).
 * The look (shade, accent, slider timing) is in themes/snippets/signature-blocks.css and matches the block by
 * structure: a <section> whose first child is an <aside> holding the photos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bz_core_hero_photos_list() {
	$base = ( defined( 'BZ_MASTER_URL' ) ? BZ_MASTER_URL : 'https://builder.aukume.com' ) . '/wp-content/uploads/2026/10/';
	return array(
		'horizon-hero-office'  => array( $base . 'horizon-hero-office.jpg', 'Bright open-plan office meeting room' ),
		'horizon-hero-clients' => array( $base . 'horizon-hero-clients.jpg', 'Two colleagues celebrating at a desk' ),
		'horizon-hero-numbers' => array( $base . 'horizon-hero-numbers.jpg', 'Laptop showing financial charts' ),
	);
}

/* Run an Elementor ability; WP_Error when it isn't there or fails. */
function bz_core_hero_ability( $name, $input ) {
	if ( ! function_exists( 'wp_get_ability' ) || ! ( $ability = wp_get_ability( $name ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
		return new WP_Error( 'bz_hero_ability', 'Elementor\'s ' . $name . ' tool is not available on this site.' );
	}
	$result = $ability->execute( $input );
	if ( is_object( $result ) && ! is_wp_error( $result ) ) {
		$result = json_decode( wp_json_encode( $result ), true );
	}
	return $result;
}

/* Attachment ids of the three starter photos on this site (copied from the master the first time). */
function bz_core_hero_photo_ids() {
	$ids = array();
	foreach ( bz_core_hero_photos_list() as $name => $photo ) {
		$found = get_posts( array( 'post_type' => 'attachment', 'name' => $name, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'inherit' ) );
		if ( $found ) {
			$ids[] = (int) $found[0];
			continue;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_sideload_image( $photo[0], 0, $photo[1], 'id' );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'bz_hero_photo', 'Couldn\'t copy the banner photo ' . $name . ' from the master: ' . $id->get_error_message() );
		}
		wp_update_post( array( 'ID' => $id, 'post_name' => $name ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $photo[1] );
		$ids[] = (int) $id;
	}
	return $ids;
}

/* Banner variants: component title, photos, and whether each photo has its own text. */
function bz_core_hero_variants() {
	return array(
		'single'  => array( 'title' => 'HORIZON-HERO-01', 'slides' => 1, 'stories' => false, 'label' => 'photo banner' ),
		'slider'  => array( 'title' => 'HORIZON-HERO-02 (slider)', 'slides' => 3, 'stories' => false, 'label' => 'photo slider' ),
		'stories' => array( 'title' => 'HORIZON-HERO-03 (slider with a message per photo)', 'slides' => 3, 'stories' => true, 'label' => 'photo slider with a message per photo' ),
	);
}

/* Default wording per slide (slide 1 also used by the single banner and the plain slider). */
function bz_core_hero_copy() {
	return array(
		array( 'pill' => array( 'name' => 'shortcode', 'settings' => array( 'shortcode' => '[bz field=descriptor]' ) ), 'title' => 'Numbers you can trust. <span>Advice that moves you forward.</span>', 'lead' => 'Tax, accounts and business advice from a local team that knows your industry and picks up the phone.', 'btn1' => 'Book a free call →', 'btn2' => 'Our services' ),
		array( 'pill' => 'For growing businesses', 'title' => 'Plan ahead, <span>not just at tax time.</span>', 'lead' => 'Cash flow forecasts, budgets and regular check-ins, so you always know where you stand and what comes next.', 'btn1' => 'Talk to an adviser →', 'btn2' => 'How we help' ),
		array( 'pill' => 'Cloud accounting', 'title' => 'Your books, <span>always up to date.</span>', 'lead' => 'We set up and look after your accounting software, so your numbers are ready whenever you need them.', 'btn1' => 'Book a free call →', 'btn2' => 'Our services' ),
	);
}

/* The component id for a variant (single, slider, stories), building it if this site doesn't have it yet. */
function bz_core_hero_component( $variant ) {
	$variants = bz_core_hero_variants();
	if ( ! isset( $variants[ $variant ] ) ) {
		return new WP_Error( 'bz_hero_variant', 'Unknown banner type.' );
	}
	$v     = $variants[ $variant ];
	$title = $v['title'];
	$list  = bz_core_hero_ability( 'elementor/list-components', array() );
	if ( is_wp_error( $list ) ) {
		return $list;
	}
	foreach ( isset( $list['components'] ) ? (array) $list['components'] : array() as $c ) {
		if ( isset( $c['name'] ) && $title === $c['name'] ) {
			return (int) $c['id'];
		}
	}
	$photos = bz_core_hero_photo_ids();
	if ( is_wp_error( $photos ) ) {
		return $photos;
	}
	$p        = array( 'single' => 'hz', 'slider' => 'hzs', 'stories' => 'hzm' )[ $variant ];
	$services = get_page_by_path( 'services' );
	$svc_link = array( 'destination' => $services ? array( 'id' => $services->ID, 'label' => 'Services' ) : home_url( '/services/' ) );
	$booking  = array( 'destination' => array( 'name' => 'shortcode', 'settings' => array( 'shortcode' => '[bz field=booking_url]' ) ) );
	$copy     = bz_core_hero_copy();
	$style    = array(
		$p            => 'position: relative; padding: 0px; min-height: 640px; display: flex; align-items: center; overflow: hidden; background-color: var(--color-primary-dark); @media(--mobile) { min-height: 600px; }',
		$p . '-media' => 'position: absolute; top: 0px; left: 0px; right: 0px; bottom: 0px; padding: 0px; z-index: 0;',
		$p . '-shade' => 'position: absolute; top: 0px; left: 0px; right: 0px; bottom: 0px; padding: 0px; z-index: 1; background-color: rgba(20, 33, 94, 0.62);',
		$p . '-in'    => 'position: relative; z-index: 2; width: 100%; max-width: var(--container-max); margin: 0px auto; padding: var(--space-section) var(--space-md);',
	);
	$config   = array( $p => array( 'tag' => 'section' ), $p . '-media' => array( 'tag' => 'aside' ) );
	$props    = array();
	$text_xml = '';
	$blocks   = $v['stories'] ? 3 : 1;
	for ( $b = 1; $b <= $blocks; $b++ ) {
		$q     = $v['stories'] ? $p . '-s' . $b : $p;   // element prefix for this text block
		$grp   = $v['stories'] ? 'Slide ' . $b : 'Text';
		$key   = $v['stories'] ? 's' . $b . '_' : '';
		$c     = $copy[ $b - 1 ];
		$text_xml .= '<e-div-block configuration-id="' . $q . '-text"><e-paragraph configuration-id="' . $q . '-pill"></e-paragraph><e-heading configuration-id="' . $q . '-title"></e-heading>'
			. '<e-paragraph configuration-id="' . $q . '-lead"></e-paragraph><e-div-block configuration-id="' . $q . '-btns"><e-button configuration-id="' . $q . '-btn1"></e-button>'
			. '<e-button configuration-id="' . $q . '-btn2"></e-button></e-div-block></e-div-block>';
		$style[ $q . '-text' ]  = 'padding: 0px; display: flex; flex-direction: column; gap: var(--space-md); align-items: flex-start; max-width: 680px;' . ( $b > 1 ? ' opacity: 0; visibility: hidden;' : '' );
		$style[ $q . '-pill' ]  = 'font-weight: 600; font-size: var(--text-small); color: var(--color-inverse); padding: 7px 14px; margin: 0px; border-radius: 999px; background-color: rgba(255,255,255,0.16);';
		$style[ $q . '-title' ] = 'font-weight: 800; font-size: var(--text-h1); color: var(--color-inverse); line-height: 1.06; margin: 0px;';
		$style[ $q . '-lead' ]  = 'max-width: 40ch; font-size: 1.1875rem; color: var(--color-inverse); margin: 0px; opacity: 90%;';
		$style[ $q . '-btns' ]  = 'padding: 0px; display: flex; gap: 12px; flex-wrap: wrap;';
		$style[ $q . '-btn1' ]  = 'font-weight: 700; color: var(--color-heading); padding: 14px 26px; border-radius: var(--radius-button); background-color: var(--color-accent);';
		$style[ $q . '-btn2' ]  = 'font-weight: 700; color: var(--color-inverse); padding: 14px 26px; border-radius: var(--radius-button); border-width: 1px; border-color: rgba(255,255,255,0.45); border-style: solid; background-color: rgba(255,255,255,0.12);';
		$config[ $q . '-text' ]  = array( 'tag' => $v['stories'] ? 'article' : 'div' );
		$config[ $q . '-pill' ]  = array( 'paragraph' => $c['pill'] );
		$config[ $q . '-title' ] = array( 'tag' => 1 === $b ? 'h1' : 'h2', 'title' => $c['title'] ); // one h1 per page
		$config[ $q . '-lead' ]  = array( 'paragraph' => $c['lead'] );
		$config[ $q . '-btn1' ]  = array( 'text' => $c['btn1'], 'link' => $booking );
		$config[ $q . '-btn2' ]  = array( 'text' => $c['btn2'], 'link' => $svc_link );
		$props[ $key . 'pill' ]      = array( 'target' => $q . '-pill', 'prop_key' => 'paragraph', 'label' => 'Pill text', 'group' => $grp );
		$props[ $key . 'heading' ]   = array( 'target' => $q . '-title', 'prop_key' => 'title', 'label' => 'Headline', 'group' => $grp );
		$props[ $key . 'lead' ]      = array( 'target' => $q . '-lead', 'prop_key' => 'paragraph', 'label' => 'Lead text', 'group' => $grp );
		$props[ $key . 'btn1_text' ] = array( 'target' => $q . '-btn1', 'prop_key' => 'text', 'label' => 'Button 1 text', 'group' => $grp );
		$props[ $key . 'btn1_link' ] = array( 'target' => $q . '-btn1', 'prop_key' => 'link', 'label' => 'Button 1 link', 'group' => $grp );
		$props[ $key . 'btn2_text' ] = array( 'target' => $q . '-btn2', 'prop_key' => 'text', 'label' => 'Button 2 text', 'group' => $grp );
		$props[ $key . 'btn2_link' ] = array( 'target' => $q . '-btn2', 'prop_key' => 'link', 'label' => 'Button 2 link', 'group' => $grp );
	}
	if ( $v['stories'] ) {  // the three text blocks share one spot (CSS stacks and fades them with the photos)
		$text_xml           = '<e-div-block configuration-id="' . $p . '-stack">' . $text_xml . '</e-div-block>';
		$style[ $p . '-stack' ] = 'padding: 0px; display: grid;';
	}
	$slide_xml = '';
	for ( $i = 1; $i <= $v['slides']; $i++ ) {
		$k             = $p . '-slide' . $i;
		$slide_xml    .= '<e-image configuration-id="' . $k . '"></e-image>';
		$config[ $k ]  = array( 'image' => array( 'src' => array( 'id' => $photos[ $i - 1 ] ), 'size' => 'full' ) );
		$style[ $k ]   = 'position: absolute; top: 0px; left: 0px; width: 100%; height: 100%; object-fit: cover;' . ( $i > 1 ? ' opacity: 0;' : '' );
		$img_key       = 1 === $v['slides'] ? 'image' : ( $v['stories'] ? 's' . $i . '_image' : 'image' . $i );
		$props[ $img_key ] = array(
			'target'   => $k,
			'prop_key' => 'image',
			'label'    => 1 === $v['slides'] ? 'Background image' : ( $v['stories'] ? 'Photo' : 'Slide ' . $i . ' image' ),
			'group'    => 1 === $v['slides'] ? 'Image' : ( $v['stories'] ? 'Slide ' . $i : 'Slides' ),
		);
	}
	$xml  = '<e-div-block configuration-id="' . $p . '"><e-div-block configuration-id="' . $p . '-media">' . $slide_xml . '</e-div-block>'
		. '<e-div-block configuration-id="' . $p . '-shade"></e-div-block><e-div-block configuration-id="' . $p . '-in">' . $text_xml . '</e-div-block></e-div-block>';
	$made = bz_core_hero_ability(
		'elementor/manage-component',
		array( 'action' => 'create', 'title' => $title, 'xml_structure' => $xml, 'element_config' => $config, 'style' => $style, 'overridable_props' => $props )
	);
	if ( is_wp_error( $made ) ) {
		return $made;
	}
	return isset( $made['component_id'] ) ? (int) $made['component_id'] : new WP_Error( 'bz_hero_component', 'Elementor didn\'t create the banner component.' );
}

/**
 * Put the banner at the top of the home page. Returns array( page id, Elementor data before ) for Undo, or WP_Error.
 * The first block is replaced when it is a component (the standard home hero, or an earlier banner); anything else
 * stays and the banner goes above it.
 */
function bz_core_hero_swap( $variant ) {
	$home = (int) get_option( 'page_on_front' );
	if ( ! $home ) {
		return new WP_Error( 'bz_hero_home', 'This site has no static home page to put the banner on.' );
	}
	$component = bz_core_hero_component( $variant );
	if ( is_wp_error( $component ) ) {
		return $component;
	}
	$before = (string) get_post_meta( $home, '_elementor_data', true );
	$tree   = bz_core_hero_ability( 'elementor/get-page-structure', array( 'post_id' => $home ) );
	if ( is_wp_error( $tree ) ) {
		return $tree;
	}
	$roots = isset( $tree['elements'] ) ? (array) $tree['elements'] : array();
	if ( 1 === count( $roots ) && 'e-div-block' === $roots[0]['elType'] && ! empty( $roots[0]['elements'] ) ) {
		$parent   = $roots[0]['id'];
		$children = $roots[0]['elements'];
	} else {
		$parent   = 'document';
		$children = $roots;
	}
	$built = bz_core_hero_ability(
		'elementor/build-composition',
		array(
			'post_id'        => $home,
			'parent_id'      => $parent,
			'xml_structure'  => '<e-component configuration-id="Home banner"/>',
			'element_config' => array( 'Home banner' => array( 'component_id' => $component ) ),
		)
	);
	if ( is_wp_error( $built ) ) {
		return $built;
	}
	$new_id = isset( $built['root_element_ids'][0] ) ? $built['root_element_ids'][0] : '';
	if ( ! $new_id ) {
		return new WP_Error( 'bz_hero_build', 'Elementor didn\'t add the banner to the home page.' );
	}
	$ops = array( array( 'action' => 'move', 'element_id' => $new_id, 'new_parent_id' => $parent, 'index' => 0 ) );
	if ( $children && isset( $children[0]['widgetType'] ) && 'e-component' === $children[0]['widgetType'] ) {
		$ops[] = array( 'action' => 'delete', 'element_id' => $children[0]['id'] );
	}
	$moved = bz_core_hero_ability( 'elementor/manage-elements', array( 'post_id' => $home, 'operations' => $ops ) );
	if ( is_wp_error( $moved ) ) {
		return $moved;
	}
	$published = bz_core_hero_ability( 'elementor/publish-document', array( 'post_id' => $home ) );
	if ( is_wp_error( $published ) ) {
		return $published;
	}
	return array( $home, $before );
}

/* Undo: the home page's Elementor data as it was. */
function bz_core_hero_restore( $page, $data ) {
	if ( $page && '' !== (string) $data ) {
		update_post_meta( (int) $page, '_elementor_data', wp_slash( (string) $data ) );
		delete_post_meta( (int) $page, '_elementor_element_cache' );
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			\Elementor\Core\Files\CSS\Post::create( (int) $page )->delete();
		}
	}
}
