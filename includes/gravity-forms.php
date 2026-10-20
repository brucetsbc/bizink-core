<?php
/**
 * Gravity Forms integration: per-site merge tags from Client Details, theme-matched styling and button classes.
 *
 * Merge tags (usable in notifications, confirmations and field defaults):
 *   {bz:email} {bz:firm_name} {bz:phone} … any Client Details field
 *   {bz_pdf:business-plan}  URL of a resource's current PDF (works while the resource is unpublished, e.g. a gated download)
 *
 * The shared forms live in forms/bizink-forms.json (Forms → Import/Export). Import them in that order on a fresh site so the
 * IDs match the pages: 1 Contact, 2 Careers application, 3 Business plan download.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current PDF URL of a resource by slug (any status), or ''.
 */
function bz_core_resource_pdf_url( $slug ) {
	$found = get_posts(
		array(
			'post_type'   => 'bz_resource',
			'name'        => sanitize_title( $slug ),
			'post_status' => array( 'publish', 'draft', 'private' ),
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	return $found ? (string) get_post_meta( $found[0], 'bz_pdf_url', true ) : '';
}

add_filter(
	'gform_replace_merge_tags',
	function ( $text, $form, $entry, $url_encode, $esc_html ) {
		if ( false === strpos( (string) $text, '{bz' ) ) {
			return $text;
		}
		$text = preg_replace_callback(
			'/\{bz:([a-z_]+)\}/',
			function ( $m ) use ( $esc_html ) {
				$value = bz_core_get( $m[1] );
				return $esc_html ? esc_html( $value ) : $value;
			},
			$text
		);
		return preg_replace_callback(
			'/\{bz_pdf:([a-z0-9-]+)\}/',
			function ( $m ) {
				return esc_url( bz_core_resource_pdf_url( $m[1] ) );
			},
			$text
		);
	},
	10,
	5
);

/* List the Client Details tags in the merge tag picker. */
add_filter(
	'gform_custom_merge_tags',
	function ( $tags ) {
		foreach ( array( 'firm_name', 'email', 'phone', 'address_full', 'offer_sentence' ) as $field ) {
			$tags[] = array(
				'label' => 'Client Details: ' . $field,
				'tag'   => '{bz:' . $field . '}',
			);
		}
		$tags[] = array(
			'label' => 'Business plan PDF URL',
			'tag'   => '{bz_pdf:business-plan}',
		);
		return $tags;
	}
);

/* Submit buttons use the theme's button classes. */
add_filter(
	'gform_submit_button',
	function ( $button ) {
		return preg_replace( "/class='([^']*)'/", "class='$1 bz-btn bz-btn-accent'", $button, 1 );
	},
	10,
	1
);

/* Map the Orbital form theme onto the site's design tokens, so forms follow the active theme. */
add_action(
	'wp_head',
	function () {
		if ( ! class_exists( 'GFForms' ) ) {
			return;
		}
		echo '<style id="bz-gravity-forms">'
			// Gravity Forms sets its theme variables per form (ID selector), so the overrides need !important.
			. '.gform_wrapper.gform-theme--orbital{--gf-color-primary:var(--color-primary)!important;--gf-color-primary-contrast:var(--color-inverse,#fff)!important;'
			. '--gf-ctrl-label-color-primary:var(--color-heading)!important;--gf-ctrl-label-color-secondary:var(--color-text)!important;font-family:var(--font-body),sans-serif}'
			// Fields: the theme's border, corners and 48px height (16px text stops iOS zooming).
			. '.gform_wrapper.gform-theme--orbital .gfield input:is([type=text],[type=email],[type=tel],[type=url],[type=number]),'
			. '.gform_wrapper.gform-theme--orbital .gfield textarea,.gform_wrapper.gform-theme--orbital .gfield select{border:1px solid var(--color-border)!important;'
			. 'border-radius:var(--radius-button,8px)!important;min-height:48px;padding-inline:14px;font-size:1rem;font-family:var(--font-body),sans-serif;color:var(--color-text);background:var(--color-surface,#fff)}'
			. '.gform_wrapper.gform-theme--orbital .gfield textarea{padding-block:12px}'
			. '.gform_wrapper.gform-theme--orbital .gfield :is(input,textarea,select):focus{border-color:var(--color-primary)!important;'
			. 'outline:2px solid color-mix(in srgb,var(--color-primary) 25%,transparent)!important;outline-offset:1px}'
			. '.gform_wrapper.gform-theme--orbital .gfield_label{font-size:.95rem;font-weight:600;color:var(--color-heading)}'
			// Submit button: the theme's accent button.
			. '.gform_wrapper.gform-theme--orbital :is(.gform_footer,.gform_page_footer) .gform_button{background:var(--color-accent)!important;color:var(--color-heading)!important;'
			. 'border:2px solid var(--color-accent)!important;border-radius:var(--radius-button,8px)!important;padding:14px 26px!important;min-height:51px;'
			. 'font-family:var(--font-body),sans-serif!important;font-size:1rem!important;font-weight:600!important;line-height:1.2!important;box-shadow:none!important;cursor:pointer}'
			. '.gform_wrapper.gform-theme--orbital :is(.gform_footer,.gform_page_footer) .gform_button:hover{filter:brightness(.95)}'
			. '.gform_wrapper .gform_heading{display:none}'
			. '.gform_wrapper .gform_footer{margin-top:var(--space-sm,16px)}'
			. '.gform_wrapper .bz-form-note{font-size:.85rem;color:var(--color-text-muted)}'
			. '.gform_wrapper .bz-form-note a,.gform_confirmation_message a{color:var(--color-primary);text-decoration:underline;text-decoration-color:var(--color-accent);text-underline-offset:3px}'
			. '.gform_confirmation_message{padding:16px 18px;background:var(--color-surface-alt);border:1px solid var(--color-border);color:var(--color-heading)}'
			. '</style>';
	},
	20
);
