<?php
/**
 * Plugin Name: Bizink Core
 * Description: Shared features for Bizink accountant sites: client details ([bz] shortcode), Resources, Calculators, Team, Partners, region packs, key tax dates, glossary and Gravity Forms tags.
 * Version:     0.28.2
 * Author:      Bizink
 * Text Domain: bizink-core
 * Update URI:  https://github.com/brucetsbc/bizink-core
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BZ_CORE_VERSION', '0.28.2' );
define( 'BZ_CORE_OPTION', 'bz_client_details' );

/*
 * The Bizink master site. It moved from elementor.bizinkonline.com to builder.aukume.com in October 2026; both names
 * count as the master, and requests to the master fall back to the old address while it still answers.
 */
if ( ! defined( 'BZ_MASTER_URL' ) ) {
	define( 'BZ_MASTER_URL', 'https://builder.aukume.com' );
}
if ( ! defined( 'BZ_MASTER_OLD_URL' ) ) {
	define( 'BZ_MASTER_OLD_URL', 'https://elementor.bizinkonline.com' );
}

/* Is this site the master (under either name)? */
function bz_core_is_master() {
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	return in_array( $host, array( strtolower( (string) wp_parse_url( BZ_MASTER_URL, PHP_URL_HOST ) ), strtolower( (string) wp_parse_url( BZ_MASTER_OLD_URL, PHP_URL_HOST ) ) ), true );
}

/* wp_remote_request to the master; if the new address fails, try the old one. */
function bz_core_master_request( $url, $args = array() ) {
	$response = wp_remote_request( $url, $args );
	$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	if ( ( 0 === $code || $code >= 500 || 404 === $code ) && 0 === strpos( $url, BZ_MASTER_URL ) && BZ_MASTER_OLD_URL ) {
		$retry = wp_remote_request( BZ_MASTER_OLD_URL . substr( $url, strlen( BZ_MASTER_URL ) ), $args );
		if ( ! is_wp_error( $retry ) && (int) wp_remote_retrieve_response_code( $retry ) < 500 ) {
			return $retry;
		}
	}
	return $response;
}

require_once __DIR__ . '/includes/content-types.php';
require_once __DIR__ . '/includes/regions.php';
require_once __DIR__ . '/includes/tax-dates.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/gravity-forms.php';
require_once __DIR__ . '/includes/glossary.php';
require_once __DIR__ . '/includes/partners.php';
require_once __DIR__ . '/includes/video-library.php';
require_once __DIR__ . '/includes/feedback.php';
require_once __DIR__ . '/includes/marketing.php';
require_once __DIR__ . '/includes/login.php';
require_once __DIR__ . '/includes/site-theme.php';
require_once __DIR__ . '/includes/theme-hero.php';
require_once __DIR__ . '/includes/business-guides.php';
require_once __DIR__ . '/includes/packages.php';
require_once __DIR__ . '/includes/lead-magnets.php';
require_once __DIR__ . '/includes/client-intake.php';
require_once __DIR__ . '/includes/cookie-banner.php';
require_once __DIR__ . '/includes/handover.php';
require_once __DIR__ . '/includes/blocks.php';
require_once __DIR__ . '/includes/updater.php';

/**
 * Client detail fields: key => [label, input type, help text].
 */
function bz_core_fields() {
	return array(
		'package'         => array( 'Package', 'package', 'Essentials, Pro or Plus. Decides which features this site shows (set on the master under Tools → Packages). Empty shows everything.' ),
		'firm_name'       => array( 'Firm name', 'text', 'Defaults to the Site Title when empty.' ),
		'descriptor'      => array( 'Descriptor', 'text', 'e.g. registered tax agents and accountants (used in “Prepared by {firm}, {descriptor}”).' ),
		'region'          => array( 'Region', 'region', 'Drives regional wording, tax terms and default compliance text.' ),
		'phone'           => array( 'Phone (display)', 'text', 'As it should appear, e.g. (02) 9000 0000' ),
		'email'           => array( 'Email', 'email', '' ),
		'address'         => array( 'Address', 'textarea', 'One line or multiple lines.' ),
		'suburb'          => array( 'Suburb / town', 'text', '' ),
		'city'            => array( 'City', 'text', '' ),
		'state'           => array( 'State / county / province', 'text', '' ),
		'postcode'        => array( 'Postcode / ZIP', 'text', 'Shown after the state in the full address.' ),
		'map_query'       => array( 'Map search', 'text', 'Optional. What the Contact page map searches for, e.g. the firm’s Google listing name. Empty uses the address; “none” hides the map.' ),
		'hours'           => array( 'Opening hours', 'text', 'e.g. Mon–Fri 9am–5pm' ),
		'software'        => array( 'Accounting software', 'text', 'Comma separated, e.g. Xero, MYOB, QuickBooks' ),
		'specialities'    => array( 'Specialities', 'text', 'Comma separated, e.g. tax planning, business start-ups' ),
		'offer_sentence'  => array( 'Offer sentence', 'textarea', 'e.g. We offer a free 30-minute consultation to talk through your plans.' ),
		'footer_blurb'    => array( 'Footer blurb', 'textarea', 'One or two sentences about the firm.' ),
		'booking_url'     => array( 'Booking URL', 'url', 'External booking link. Leave empty to use the Book a Call page.' ),
		'portal_url'      => array( 'Client portal URL', 'url', '' ),
		'compliance_note' => array( 'Compliance note', 'textarea', 'Leave empty to use the regional default.' ),
		'ga4_measurement_id' => array( 'GA4 measurement ID', 'text', 'G-XXXXXXXXXX. Adds the Google Analytics tag to every page (not for logged-in editors) and records form enquiries. The tag only starts once "Discourage search engines" is off (at launch), so it can be set during the build. Leave empty if the site already has a tag.' ),
		'ga4_property_id'    => array( 'GA4 property ID', 'text', 'Numbers only, from Google Analytics → Admin → Property details. Used by the Marketing page for visitors and downloads.' ),
		'search_console'     => array( 'Search Console property', 'text', 'e.g. sc-domain:firm.com.au or https://www.firm.com.au/. Used by the Marketing page for Google search clicks.' ),
		'cookie_banner'      => array( 'Cookie banner', 'cookie', 'UK and Ireland: "Ask for consent" (analytics waits for Accept). Elsewhere a notice is enough if the firm wants one. Visitors can reopen it from any link to #cookie-settings, e.g. the [bz_cookie_settings] shortcode in the footer.' ),
		'cookie_text'        => array( 'Cookie banner text', 'textarea', 'Optional. Leave empty for the standard wording. A link to the privacy policy is added after it.' ),
	);
}

function bz_core_regions() {
	return array(
		'AU' => 'Australia',
		'NZ' => 'New Zealand',
		'US' => 'United States',
		'CA' => 'Canada',
		'UK' => 'United Kingdom',
		'IE' => 'Ireland',
	);
}

/**
 * Default compliance notes per region. Placeholders only: each firm must confirm its own wording.
 */
function bz_core_default_compliance( $region ) {
	$notes = array(
		'AU' => 'Liability limited by a scheme approved under Professional Standards Legislation.',
		'NZ' => '',
		'US' => '',
		'CA' => '',
		'UK' => '',
		'IE' => '',
	);
	return isset( $notes[ $region ] ) ? $notes[ $region ] : '';
}

function bz_core_get( $field ) {
	$opts  = get_option( BZ_CORE_OPTION, array() );
	$value = isset( $opts[ $field ] ) ? trim( (string) $opts[ $field ] ) : '';

	switch ( $field ) {
		case 'firm_name':
			return '' !== $value ? $value : get_bloginfo( 'name' );
		case 'phone_href':
			$phone = bz_core_get( 'phone' );
			return '' !== $phone ? 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) : '';
		case 'email_href':
			$email = bz_core_get( 'email' );
			return '' !== $email ? 'mailto:' . $email : '';
		case 'booking_url':
			if ( '' !== $value ) {
				return $value;
			}
			$page = get_page_by_path( 'book-a-call' );
			return $page ? get_permalink( $page ) : '';
		case 'copyright':
			return '© ' . gmdate( 'Y' ) . ' ' . bz_core_get( 'firm_name' ) . '. All rights reserved.';
		case 'compliance_note':
			return '' !== $value ? $value : bz_core_default_compliance( bz_core_get( 'region' ) );
		case 'region':
			return '' !== $value ? $value : 'AU';
		case 'software':
			return '' !== $value ? bz_core_join_list( $value ) : 'leading cloud accounting packages';
		case 'specialities':
			return bz_core_join_list( $value );
		case 'offer_sentence':
			return '' !== $value ? $value : 'Contact ' . bz_core_get( 'firm_name' ) . ' to arrange a time to talk.';
		case 'contact_url':
			$page = get_page_by_path( 'contact' );
			return $page ? get_permalink( $page ) : home_url( '/' );
		case 'site_url':
			return untrailingslashit( home_url() );
		case 'page_url':
			return (string) get_permalink();
		case 'address_full':
			// Street address, then "suburb state" (or "city state" when no suburb) on its own line, skipping parts already typed into the address.
			$address = bz_core_get( 'address' );
			$extra   = array();
			$parts   = '' !== bz_core_get( 'suburb' ) ? array( 'suburb', 'state', 'postcode' ) : array( 'city', 'state', 'postcode' );
			foreach ( $parts as $part ) {
				$part_value = bz_core_get( $part );
				if ( '' !== $part_value && false === stripos( $address, $part_value ) && ! in_array( $part_value, $extra, true ) ) {
					$extra[] = $part_value;
				}
			}
			return trim( $address . ( $extra ? "\n" . implode( ' ', $extra ) : '' ) );
	}
	return $value;
}

/**
 * "Xero, MYOB, QuickBooks" → "Xero, MYOB and QuickBooks".
 */
function bz_core_join_list( $csv ) {
	$items = array_values( array_filter( array_map( 'trim', explode( ',', (string) $csv ) ) ) );
	if ( count( $items ) < 2 ) {
		return implode( '', $items );
	}
	$last = array_pop( $items );
	return implode( ', ', $items ) . ' and ' . $last;
}

/**
 * [bz field="phone"] — outputs one client detail. URL fields are escaped as URLs.
 */
add_shortcode(
	'bz',
	function ( $atts ) {
		$atts  = shortcode_atts( array( 'field' => '' ), $atts, 'bz' );
		$field = sanitize_key( $atts['field'] );
		$value = bz_core_get( $field );

		if ( in_array( $field, array( 'phone_href', 'email_href', 'booking_url', 'portal_url', 'contact_url', 'site_url', 'page_url' ), true ) ) {
			return esc_url( $value, array( 'http', 'https', 'tel', 'mailto' ) );
		}
		if ( in_array( $field, array( 'address', 'address_full', 'footer_blurb', 'compliance_note' ), true ) ) {
			return nl2br( esc_html( $value ) );
		}
		return esc_html( $value );
	}
);

/**
 * The search used for the office map: "Map search" when set (e.g. the firm's Google listing name),
 * otherwise the address fields plus the country. '' when there is no address or Map search is "none".
 */
function bz_core_map_query() {
	$query = bz_core_get( 'map_query' );
	if ( 'none' === strtolower( $query ) ) {
		return '';
	}
	if ( '' !== $query ) {
		return $query;
	}
	if ( '' === bz_core_get( 'address' ) ) {
		return '';
	}
	// Drop "Level 22", "Suite 3" etc.: Google matches every tenancy on that level and shows several pins.
	$address = array_filter(
		preg_split( '/\s*(?:,|[\r\n]+)\s*/', bz_core_get( 'address' ) ),
		function ( $segment ) {
			return '' !== $segment && ! preg_match( '/^(level|lvl|suite|ste|unit|floor|shop|office)\b/i', $segment );
		}
	);
	$regions = bz_core_regions();
	$parts   = array(
		implode( ', ', $address ),
		'' !== bz_core_get( 'suburb' ) ? bz_core_get( 'suburb' ) : bz_core_get( 'city' ),
		trim( bz_core_get( 'state' ) . ' ' . bz_core_get( 'postcode' ) ),
		isset( $regions[ bz_core_get( 'region' ) ] ) ? $regions[ bz_core_get( 'region' ) ] : '',
	);
	return preg_replace( '/\s*[\r\n]+\s*/', ', ', implode( ', ', array_filter( $parts ) ) );
}

/**
 * [bz_map height="400"] — embedded Google map of the office plus a "Get directions" link (no API key needed).
 * Outputs nothing without an address, so a section marked .bz-map-section hides itself (kit CSS).
 */
add_shortcode(
	'bz_map',
	function ( $atts ) {
		$atts  = shortcode_atts( array( 'height' => '400' ), $atts, 'bz_map' );
		$query = bz_core_map_query();
		if ( '' === $query ) {
			return '';
		}
		bz_core_map_script( max( 200, min( 800, (int) $atts['height'] ) ) );
		return sprintf(
			'<span class="bz-map" style="display:block;border-radius:var(--radius-lg,16px);overflow:hidden;border:1px solid var(--color-border,#dce3ec);line-height:0">'
			. '<iframe src="%1$s" title="%2$s" width="100%%" height="%3$d" style="display:block;width:100%%;border:0" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></span>'
			. '<a class="bz-map-link" href="%4$s" target="_blank" rel="noopener" style="display:inline-block;margin-top:var(--space-sm,12px)">Get directions →</a>',
			esc_url( 'https://www.google.com/maps?output=embed&q=' . rawurlencode( $query ) ),
			esc_attr( sprintf( 'Map showing the %s office', bz_core_get( 'firm_name' ) ) ),
			max( 200, min( 800, (int) $atts['height'] ) ),
			esc_url( 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $query ) )
		);
	}
);

/**
 * Elementor's atomic Paragraph widget strips the iframe (and classes/styles) from shortcode output, leaving only the
 * "Get directions" link. This footer script rebuilds the map above any such link that has lost its iframe, from the
 * link's own destination, so [bz_map] works in a Paragraph (Shortcode dynamic tag) and anywhere else.
 */
function bz_core_map_script( $height ) {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	wp_register_script( 'bz-map', false, array(), BZ_CORE_VERSION, true );
	wp_enqueue_script( 'bz-map' );
	wp_add_inline_script(
		'bz-map',
		'(function(){var h=' . (int) $height . ',t=' . wp_json_encode( sprintf( 'Map showing the %s office', bz_core_get( 'firm_name' ) ) ) . ';'
		. 'document.querySelectorAll(\'a[href^="https://www.google.com/maps/dir/?api=1"]\').forEach(function(a){'
		. 'var box=a.parentNode;if(!box||box.querySelector("iframe"))return;'
		. 'var q=new URL(a.href).searchParams.get("destination");if(!q)return;'
		. 'var w=document.createElement("span");w.className="bz-map";'
		. 'w.style.cssText="display:block;border-radius:var(--radius-lg,16px);overflow:hidden;border:1px solid var(--color-border,#dce3ec);line-height:0;margin-bottom:var(--space-sm,12px)";'
		. 'var f=document.createElement("iframe");f.src="https://www.google.com/maps?output=embed&q="+encodeURIComponent(q);f.title=t;f.height=h;f.loading="lazy";'
		. 'f.referrerPolicy="no-referrer-when-downgrade";f.allowFullscreen=true;f.style.cssText="display:block;width:100%;border:0";'
		. 'w.appendChild(f);box.insertBefore(w,a);box.style.display="block";box.style.width="100%";a.style.display="inline-block";});})();'
	);
}

/* ---------- Settings page: Settings → Client Details ---------- */

add_action(
	'admin_menu',
	function () {
		add_options_page( 'Client Details', 'Client Details', 'manage_options', 'bz-client-details', 'bz_core_render_page' );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'bz_client_details_group',
			BZ_CORE_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'bz_core_sanitize',
				'default'           => array(),
			)
		);
	}
);

function bz_core_sanitize( $input ) {
	$clean = array();
	foreach ( bz_core_fields() as $key => $def ) {
		$raw = isset( $input[ $key ] ) ? $input[ $key ] : '';
		switch ( $def[1] ) {
			case 'email':
				$clean[ $key ] = sanitize_email( $raw );
				break;
			case 'url':
				$clean[ $key ] = esc_url_raw( $raw );
				break;
			case 'textarea':
				$clean[ $key ] = sanitize_textarea_field( $raw );
				break;
			case 'region':
				$clean[ $key ] = array_key_exists( $raw, bz_core_regions() ) ? $raw : 'AU';
				break;
			case 'package':
				$clean[ $key ] = function_exists( 'bz_core_package_names' ) && array_key_exists( $raw, bz_core_package_names() ) ? $raw : '';
				break;
			case 'cookie':
				$clean[ $key ] = function_exists( 'bz_core_cookie_modes' ) && array_key_exists( $raw, bz_core_cookie_modes() ) ? $raw : '';
				break;
			default:
				$clean[ $key ] = sanitize_text_field( $raw );
		}
	}
	return $clean;
}

function bz_core_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$opts = get_option( BZ_CORE_OPTION, array() );
	?>
	<div class="wrap">
		<h1>Client Details</h1>
		<p>These details feed the header, footer and other templates across the site.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'bz_client_details_group' ); ?>
			<table class="form-table" role="presentation">
				<?php
				foreach ( bz_core_fields() as $key => $def ) :
					list( $label, $type, $help ) = $def;
					$name  = BZ_CORE_OPTION . '[' . $key . ']';
					$value = isset( $opts[ $key ] ) ? $opts[ $key ] : '';
					?>
					<tr>
						<th scope="row"><label for="bz-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<?php if ( 'textarea' === $type ) : ?>
								<textarea id="bz-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
							<?php elseif ( 'region' === $type ) : ?>
								<select id="bz-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>">
									<?php foreach ( bz_core_regions() as $code => $region_label ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $value ? $value : 'AU', $code ); ?>><?php echo esc_html( $region_label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'package' === $type ) : ?>
								<select id="bz-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>">
									<option value="">Not set (all features)</option>
									<?php foreach ( bz_core_package_names() as $code => $package_label ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $value, $code ); ?>><?php echo esc_html( $package_label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'cookie' === $type ) : ?>
								<select id="bz-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>">
									<?php foreach ( bz_core_cookie_modes() as $code => $mode_label ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $value, $code ); ?>><?php echo esc_html( $mode_label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input id="bz-<?php echo esc_attr( $key ); ?>" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
							<?php endif; ?>
							<?php if ( $help ) : ?>
								<p class="description"><?php echo esc_html( $help ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
