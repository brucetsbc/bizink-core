<?php
/**
 * Lead magnet builder (Marketing assistant page; package feature "lead_magnets" with a limit).
 *
 * The firm picks one of its templates or checklists (bz_resource) and clicks Create. The plugin makes:
 *   - a Gravity Form "Lead magnet: <title>" (name, email, consent, plus a hidden field naming the download) that emails
 *     the visitor the download link, notifies the firm (Client Details email) and shows the link straight away;
 *   - a landing page /free-<resource>/ that shows the download's cover, intro and the form ([bz_lead_magnet id=…]),
 *     in the site's own colours and fonts.
 * Leads are ordinary Gravity Forms entries (so they also count as enquiries on the dashboard). Removing a lead magnet
 * moves its page to the bin and switches its form off; entries are kept.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bz_core_lm_enabled() {
	return ( ! function_exists( 'bz_core_mkt_enabled' ) || bz_core_mkt_enabled() ) && ( ! function_exists( 'bz_core_feature' ) || bz_core_feature( 'lead_magnets' ) );
}

function bz_core_lm_list() {
	return (array) get_option( 'bz_lead_magnets', array() );
}

function bz_core_lm_limit() {
	return function_exists( 'bz_core_package_limit' ) ? bz_core_package_limit( 'lead_magnets' ) : 10;
}

/* The form definition for one download. */
function bz_core_lm_form( $resource ) {
	$title = get_the_title( $resource );
	$pdf   = (string) get_post_meta( $resource->ID, 'bz_pdf_url', true );
	$field = function ( $f ) {
		return array_merge(
			array(
				'adminLabel'   => '',
				'isRequired'   => false,
				'size'         => 'large',
				'visibility'   => 'visible',
				'inputs'       => null,
				'description'  => '',
				'placeholder'  => '',
				'cssClass'     => '',
				'defaultValue' => '',
				'choices'      => '',
				'pageNumber'   => 1,
			),
			$f
		);
	};
	$button = '<p><a href="' . esc_url( $pdf ) . '" style="display:inline-block;padding:12px 20px;background:#1f3a5f;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">Download ' . esc_html( $title ) . '</a></p>';
	return array(
		'title'                => 'Lead magnet: ' . $title,
		'description'          => '',
		'labelPlacement'       => 'top_label',
		'descriptionPlacement' => 'below',
		'cssClass'             => 'bz-lead-form',
		'enableHoneypot'       => true, // Same as the shared forms; WP Armour adds its own trap on top.
		'button'               => array( 'type' => 'text', 'text' => 'Send me the ' . ( stripos( $title, 'checklist' ) !== false ? 'checklist' : 'download' ) ),
		'fields'               => array(
			$field( array( 'type' => 'text', 'id' => 1, 'label' => 'Your name', 'isRequired' => true, 'autocompleteAttribute' => 'name', 'enableAutocomplete' => true ) ),
			$field( array( 'type' => 'email', 'id' => 2, 'label' => 'Email', 'isRequired' => true, 'autocompleteAttribute' => 'email', 'enableAutocomplete' => true ) ),
			$field(
				array(
					'type'       => 'checkbox',
					'id'         => 3,
					'label'      => 'Consent',
					'isRequired' => true,
					'choices'    => array( array( 'text' => 'Email me the ' . $title . ' and occasional updates from ' . bz_core_get( 'firm_name' ) . '. I can unsubscribe at any time.', 'value' => 'yes', 'isSelected' => false ) ),
					'inputs'     => array( array( 'id' => '3.1', 'label' => 'Email me the ' . $title . ' and occasional updates. I can unsubscribe at any time.', 'name' => '' ) ),
					'labelPlacement' => 'hidden_label',
				)
			),
			$field( array( 'type' => 'hidden', 'id' => 4, 'label' => 'Download', 'defaultValue' => $title ) ),
		),
		'notifications'        => array(
			'bzlmuser'  => array(
				'id'       => 'bzlmuser',
				'name'     => 'Download link to visitor',
				'isActive' => true,
				'service'  => 'wordpress',
				'event'    => 'form_submission',
				'toType'   => 'field',
				'to'       => '2',
				'toField'  => '2',
				'subject'  => 'Your ' . $title . ' from {bz:firm_name}',
				'message'  => 'Hi {Your name:1},<br><br>Thanks for your interest. Here is your copy of the ' . esc_html( $title ) . ':<br>' . $button . 'If you would like to talk it through, just reply to this email or call us on {bz:phone}.<br><br>{bz:firm_name}',
				'from'     => '{admin_email}',
				'fromName' => '{bz:firm_name}',
				'replyTo'  => '{bz:email}',
			),
			'bzlmadmin' => array(
				'id'       => 'bzlmadmin',
				'name'     => 'New lead to the firm',
				'isActive' => true,
				'service'  => 'wordpress',
				'event'    => 'form_submission',
				'toType'   => 'email',
				'to'       => '{bz:email}',
				'toEmail'  => '{bz:email}',
				'subject'  => 'New lead: {Your name:1} downloaded the ' . $title,
				'message'  => '{all_fields}',
				'from'     => '{admin_email}',
				'fromName' => '{bz:firm_name} website',
				'replyTo'  => '{Email:2}',
			),
		),
		'confirmations'        => array(
			'bzlmok' => array(
				'id'        => 'bzlmok',
				'name'      => 'Default Confirmation',
				'isDefault' => true,
				'type'      => 'message',
				'message'   => '<strong>Thanks {Your name:1}, your ' . esc_html( $title ) . ' is ready.</strong>' . $button . 'We have also emailed you the link.',
			),
		),
		'is_active'            => true,
	);
}

/* Create a lead magnet for a download. Returns the new entry or a WP_Error. */
function bz_core_lm_create( $resource_id ) {
	if ( ! bz_core_lm_enabled() ) {
		return new WP_Error( 'bz_lm_off', 'Lead magnets are not included in this site\'s package.' );
	}
	if ( ! class_exists( 'GFAPI' ) ) {
		return new WP_Error( 'bz_lm_gf', 'Gravity Forms is needed for lead magnets.' );
	}
	$list = bz_core_lm_list();
	if ( count( $list ) >= bz_core_lm_limit() ) {
		return new WP_Error( 'bz_lm_limit', sprintf( 'Your package includes %d lead magnet%s. Remove one to make another, or ask Bizink about upgrading.', bz_core_lm_limit(), 1 === bz_core_lm_limit() ? '' : 's' ) );
	}
	$resource = get_post( (int) $resource_id );
	if ( ! $resource || 'bz_resource' !== $resource->post_type || 'publish' !== $resource->post_status ) {
		return new WP_Error( 'bz_lm_resource', 'Choose one of your published templates or checklists.' );
	}
	foreach ( $list as $lm ) {
		if ( (int) $lm['resource_id'] === $resource->ID ) {
			return new WP_Error( 'bz_lm_exists', 'There is already a lead magnet for this download.' );
		}
	}
	if ( ! get_post_meta( $resource->ID, 'bz_pdf_url', true ) ) {
		return new WP_Error( 'bz_lm_file', 'This download has no file attached yet.' );
	}
	$form_id = GFAPI::add_form( bz_core_lm_form( $resource ) );
	if ( is_wp_error( $form_id ) ) {
		return $form_id;
	}
	$id      = 'lm' . substr( md5( $resource->ID . microtime() ), 0, 8 );
	$title   = get_the_title( $resource );
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => ( 0 === stripos( $title, 'free ' ) ? '' : 'Free ' ) . $title,
			'post_name'    => 'free-' . $resource->post_name,
			'post_content' => '<!-- wp:shortcode -->[bz_lead_magnet id="' . $id . '"]<!-- /wp:shortcode -->',
			'post_author'  => get_current_user_id(),
			'meta_input'   => array( 'bz_lead_magnet' => $id ),
		),
		true
	);
	if ( is_wp_error( $page_id ) ) {
		GFAPI::delete_form( $form_id );
		return $page_id;
	}
	$entry       = array(
		'id'          => $id,
		'resource_id' => $resource->ID,
		'form_id'     => (int) $form_id,
		'page_id'     => (int) $page_id,
		'title'       => $title,
		'created'     => time(),
		'by'          => get_current_user_id(),
	);
	$list[ $id ] = $entry;
	update_option( 'bz_lead_magnets', $list, false );
	return $entry;
}

function bz_core_lm_remove( $id ) {
	$list = bz_core_lm_list();
	if ( ! isset( $list[ $id ] ) ) {
		return;
	}
	wp_trash_post( (int) $list[ $id ]['page_id'] );
	if ( class_exists( 'GFAPI' ) ) {
		GFAPI::update_form_property( (int) $list[ $id ]['form_id'], 'is_active', 0 );
	}
	unset( $list[ $id ] );
	update_option( 'bz_lead_magnets', $list, false );
}

/* ---------- Landing page ---------- */

add_shortcode(
	'bz_lead_magnet',
	function ( $atts ) {
		$atts = shortcode_atts( array( 'id' => '' ), $atts, 'bz_lead_magnet' );
		$list = bz_core_lm_list();
		if ( ! isset( $list[ $atts['id'] ] ) || ! function_exists( 'gravity_form' ) ) {
			return '';
		}
		$lm       = $list[ $atts['id'] ];
		$resource = get_post( (int) $lm['resource_id'] );
		if ( ! $resource ) {
			return '';
		}
		$intro = $resource->post_excerpt ? $resource->post_excerpt : wp_trim_words( wp_strip_all_tags( $resource->post_content ), 45, '…' );
		$helps = (string) get_post_meta( $resource->ID, 'bz_helps_text', true );
		$cover = get_the_post_thumbnail_url( $resource, 'large' );
		$kind  = stripos( $resource->post_title, 'checklist' ) !== false ? 'Free checklist' : 'Free download';
		$form  = gravity_form( (int) $lm['form_id'], false, false, false, null, true, 0, false );
		$css   = '<style>'
			. '.bzlm{max-width:1160px;margin:0 auto;padding:clamp(32px,6vw,80px) 20px;display:grid;grid-template-columns:1.1fr .9fr;gap:clamp(28px,5vw,64px);align-items:start}'
			. '.bzlm-eyebrow{display:inline-block;font-size:.8125rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--color-primary)}'
			. '.bzlm h1{font-family:var(--font-heading),sans-serif;color:var(--color-heading);font-size:var(--text-h1,clamp(2rem,1.5rem+2vw,3rem));line-height:1.1;margin:10px 0 16px}'
			. '.bzlm-intro{font-size:1.125rem;color:var(--color-text);line-height:1.6;margin:0 0 18px}'
			. '.bzlm-helps{border-left:3px solid var(--color-accent,var(--color-primary));padding:4px 0 4px 16px;color:var(--color-text);margin:0 0 18px}'
			. '.bzlm-by{font-size:.9375rem;color:var(--color-text-muted)}'
			. '.bzlm-card{background:var(--color-surface,#fff);border:1px solid var(--color-border,#dde2e8);border-radius:var(--radius-card,12px);padding:24px;box-shadow:0 12px 40px rgba(20,32,46,.08)}'
			. '.bzlm-cover{display:block;width:100%;height:auto;border-radius:calc(var(--radius-card,12px) - 4px);margin:0 0 18px;background:var(--color-surface-alt)}'
			. '.bzlm-card h2{font-family:var(--font-heading),sans-serif;font-size:1.25rem;margin:0 0 6px;color:var(--color-heading)}'
			. '.bzlm-card p.bzlm-note{margin:0 0 12px;color:var(--color-text-muted);font-size:.9375rem}'
			. '.bzlm .gform_wrapper .gform_button{background:var(--color-primary)!important;color:#fff!important;border:0!important;border-radius:var(--radius-button,6px)!important;padding:14px 22px!important;font-weight:600!important;width:100%}'
			. '@media (max-width:860px){.bzlm{grid-template-columns:1fr}}'
			. '</style>';
		return $css . '<section class="bzlm"><div><span class="bzlm-eyebrow">' . esc_html( $kind ) . '</span><h1>' . esc_html( $resource->post_title ) . '</h1>'
			. '<p class="bzlm-intro">' . esc_html( $intro ) . '</p>'
			. ( $helps ? '<p class="bzlm-helps">' . esc_html( $helps ) . '</p>' : '' )
			. '<p class="bzlm-by">' . esc_html( 'Prepared by ' . bz_core_get( 'firm_name' ) . ( bz_core_get( 'descriptor' ) ? ', ' . bz_core_get( 'descriptor' ) : '' ) . '.' ) . '</p></div>'
			. '<div class="bzlm-card">' . ( $cover ? '<img class="bzlm-cover" src="' . esc_url( $cover ) . '" alt="' . esc_attr( 'Cover of the ' . $resource->post_title ) . '" loading="lazy">' : '' )
			. '<h2>Get your free copy</h2><p class="bzlm-note">We will email it to you straight away.</p>' . $form . '</div></section>';
	}
);

// The landing page shows its own heading, so hide the theme's page title on it.
add_filter(
	'hello_elementor_page_title',
	function ( $show ) {
		return ( is_page() && get_post_meta( get_the_ID(), 'bz_lead_magnet', true ) ) ? false : $show;
	}
);

/* ---------- Marketing assistant: the builder panel ---------- */

function bz_core_lm_panel() {
	if ( ! bz_core_lm_enabled() ) {
		return '';
	}
	$list  = bz_core_lm_list();
	$limit = bz_core_lm_limit();
	$used  = array_map( function ( $lm ) { return (int) $lm['resource_id']; }, $list );
	$h     = '<div class="bzm-panel bzm-lm" id="bzm-lead-magnets"><div class="bzm-head"><div><h2>Lead magnets</h2><p class="bzm-sub">'
		. esc_html( 'Turn one of your checklists or templates into a landing page with a sign-up form. Visitors get the download by email; you get their details. ' . count( $list ) . ' of ' . $limit . ' used.' ) . '</p></div></div>';
	if ( ! class_exists( 'GFAPI' ) ) {
		return $h . '<p class="bzm-empty">Gravity Forms is needed for lead magnets. Ask Bizink to switch it on.</p></div>';
	}
	if ( $list ) {
		$h .= '<div class="bzm-lm-list">';
		foreach ( $list as $lm ) {
			$leads = (int) GFAPI::count_entries( (int) $lm['form_id'], array( 'status' => 'active' ) );
			$url   = get_permalink( (int) $lm['page_id'] );
			$h    .= '<div class="bzm-lm-item"><div><strong>' . esc_html( $lm['title'] ) . '</strong><span>' . esc_html( $leads . ' lead' . ( 1 === $leads ? '' : 's' ) . ' · created ' . wp_date( 'j M Y', (int) $lm['created'] ) ) . '</span></div><div class="bzm-lm-actions">'
				. ( $url ? '<a class="bzm-btn ghost" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">View page</a>' : '' )
				. ( current_user_can( 'gravityforms_view_entries' ) ? '<a class="bzm-btn ghost" href="' . esc_url( admin_url( 'admin.php?page=gf_entries&id=' . (int) $lm['form_id'] ) ) . '">See leads</a>' : '' )
				. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bzm-inline" onsubmit="return confirm(\'Remove this lead magnet? Its page goes to the bin and the form is switched off. Leads already collected are kept.\');">'
				. wp_nonce_field( 'bz_lm_remove', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="bz_lm_remove"><input type="hidden" name="lm" value="' . esc_attr( $lm['id'] ) . '"><button class="bzm-btn link">Remove</button></form></div></div>';
		}
		$h .= '</div>';
	}
	$choices = get_posts( array( 'post_type' => 'bz_resource', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post__not_in' => $used ?: array( 0 ) ) );
	if ( count( $list ) >= $limit ) {
		$h .= '<p class="bzm-sub" style="margin-top:12px">' . esc_html( 'You have used all ' . $limit . ' lead magnets in your package. Remove one to make another.' ) . '</p>';
	} elseif ( $choices ) {
		$h .= '<form class="bzm-lm-new" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'bz_lm_create', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="bz_lm_create"><label for="bzm-lm-resource">Make a lead magnet from</label><select id="bzm-lm-resource" name="resource">';
		foreach ( $choices as $c ) {
			$h .= '<option value="' . (int) $c->ID . '"' . ( get_post_meta( $c->ID, 'bz_pdf_url', true ) ? '' : ' disabled' ) . '>' . esc_html( $c->post_title . ( get_post_meta( $c->ID, 'bz_pdf_url', true ) ? '' : ' (no file)' ) ) . '</option>';
		}
		$h .= '</select><button class="bzm-btn primary" onclick="this.textContent=\'Creating…\'">Create lead magnet</button></form>';
	} else {
		$h .= '<p class="bzm-sub" style="margin-top:12px">No templates or checklists to use yet.</p>';
	}
	return $h . '</div>';
}

add_action(
	'admin_post_bz_lm_create',
	function () {
		check_admin_referer( 'bz_lm_create' );
		if ( ! current_user_can( 'bz_marketing' ) ) {
			wp_die( 'Not allowed.' );
		}
		$r   = bz_core_lm_create( isset( $_POST['resource'] ) ? (int) $_POST['resource'] : 0 );
		$msg = is_wp_error( $r ) ? 'Not created: ' . $r->get_error_message() : 'Lead magnet created: ' . get_permalink( $r['page_id'] );
		wp_safe_redirect( add_query_arg( array( 'page' => 'bz-marketing', 'bz_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) . '#bzm-lead-magnets' );
		exit;
	}
);

add_action(
	'admin_post_bz_lm_remove',
	function () {
		check_admin_referer( 'bz_lm_remove' );
		if ( ! current_user_can( 'bz_marketing' ) ) {
			wp_die( 'Not allowed.' );
		}
		bz_core_lm_remove( isset( $_POST['lm'] ) ? sanitize_key( wp_unslash( $_POST['lm'] ) ) : '' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'bz-marketing', 'bz_msg' => rawurlencode( 'Lead magnet removed. Its leads are still in Forms → Entries.' ) ), admin_url( 'admin.php' ) ) . '#bzm-lead-magnets' );
		exit;
	}
);

// Company admins can see the leads (Gravity Forms entries).
add_action(
	'admin_init',
	function () {
		if ( '1' === get_option( 'bz_lm_caps' ) ) {
			return;
		}
		$role = get_role( 'bz_client' );
		if ( $role ) {
			$role->add_cap( 'gravityforms_view_entries' );
			$role->add_cap( 'gravityforms_export_entries' );
		}
		update_option( 'bz_lm_caps', '1' );
	}
);
