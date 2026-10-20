<?php
/**
 * Region packs: country-specific tax terms and page names.
 *
 * - [bz_t]We lodge your {sales_tax_return} with {tax_authority}.[/bz_t] swaps {tokens} for the current
 *   region's terms (and any Client Details field, e.g. {firm_name}). Changing Region in Client Details
 *   updates every page that uses it.
 * - Tools → Region Pack renames the region-specific pages (and their slugs) for the current region.
 *
 * Terms are deliberately plain-English and general. Check them with the firm before launch.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Terms per region. Keys are used as {tokens}; *_service keys double as page titles.
 */
function bz_core_region_terms() {
	return array(
		'AU' => array(
			'lodge'                 => 'lodge',
			'lodged'                => 'lodged',
			'lodging'               => 'lodging',
			'lodgement'             => 'lodgement',
			'sales_tax'             => 'GST',
			'sales_tax_return'      => 'BAS',
			'sales_tax_service'     => 'GST & BAS',
			'tax_authority'         => 'the ATO',
			'tax_authority_short'   => 'ATO',
			'personal_tax_service'  => 'Individual Tax Returns',
			'retirement_service'    => 'Super & SMSF',
			'retirement_savings'    => 'super',
			'self_managed_fund'     => 'self-managed super fund (SMSF)',
			'payroll_reporting'     => 'Single Touch Payroll reporting',
			'employer_contribution' => 'super guarantee contributions',
			'year_end'              => 'end of financial year',
			'business_number'       => 'ABN',
		),
		'NZ' => array(
			'lodge'                 => 'file',
			'lodged'                => 'filed',
			'lodging'               => 'filing',
			'lodgement'             => 'filing',
			'sales_tax'             => 'GST',
			'sales_tax_return'      => 'GST return',
			'sales_tax_service'     => 'GST Returns',
			'tax_authority'         => 'Inland Revenue',
			'tax_authority_short'   => 'IRD',
			'personal_tax_service'  => 'Personal Tax',
			'retirement_service'    => 'KiwiSaver & Retirement',
			'retirement_savings'    => 'KiwiSaver',
			'self_managed_fund'     => 'retirement savings scheme',
			'payroll_reporting'     => 'payday filing',
			'employer_contribution' => 'KiwiSaver employer contributions',
			'year_end'              => 'end of financial year',
			'business_number'       => 'NZBN',
		),
		'UK' => array(
			'lodge'                 => 'file',
			'lodged'                => 'filed',
			'lodging'               => 'filing',
			'lodgement'             => 'filing',
			'sales_tax'             => 'VAT',
			'sales_tax_return'      => 'VAT return',
			'sales_tax_service'     => 'VAT Returns',
			'tax_authority'         => 'HMRC',
			'tax_authority_short'   => 'HMRC',
			'personal_tax_service'  => 'Self Assessment',
			'retirement_service'    => 'Pensions & Retirement',
			'retirement_savings'    => 'pension',
			'self_managed_fund'     => 'SIPP or SSAS',
			'payroll_reporting'     => 'RTI submissions',
			'employer_contribution' => 'auto-enrolment pension contributions',
			'year_end'              => 'end of the tax year',
			'business_number'       => 'UTR',
		),
		'IE' => array(
			'lodge'                 => 'file',
			'lodged'                => 'filed',
			'lodging'               => 'filing',
			'lodgement'             => 'filing',
			'sales_tax'             => 'VAT',
			'sales_tax_return'      => 'VAT return',
			'sales_tax_service'     => 'VAT Returns',
			'tax_authority'         => 'Revenue',
			'tax_authority_short'   => 'Revenue',
			'personal_tax_service'  => 'Income Tax Returns',
			'retirement_service'    => 'Pensions & Retirement',
			'retirement_savings'    => 'pension',
			'self_managed_fund'     => 'self-administered pension',
			'payroll_reporting'     => 'PAYE Modernisation reporting',
			'employer_contribution' => 'employer pension contributions',
			'year_end'              => 'end of the tax year',
			'business_number'       => 'tax reference number',
		),
		'US' => array(
			'lodge'                 => 'file',
			'lodged'                => 'filed',
			'lodging'               => 'filing',
			'lodgement'             => 'filing',
			'sales_tax'             => 'sales tax',
			'sales_tax_return'      => 'sales tax return',
			'sales_tax_service'     => 'Sales Tax',
			'tax_authority'         => 'the IRS and your state tax agency',
			'tax_authority_short'   => 'IRS',
			'personal_tax_service'  => 'Individual Tax',
			'retirement_service'    => 'Retirement Planning',
			'retirement_savings'    => '401(k) and IRA savings',
			'self_managed_fund'     => 'solo 401(k)',
			'payroll_reporting'     => 'payroll tax filings',
			'employer_contribution' => 'employer payroll taxes',
			'year_end'              => 'end of the tax year',
			'business_number'       => 'EIN',
		),
		'CA' => array(
			'lodge'                 => 'file',
			'lodged'                => 'filed',
			'lodging'               => 'filing',
			'lodgement'             => 'filing',
			'sales_tax'             => 'GST/HST',
			'sales_tax_return'      => 'GST/HST return',
			'sales_tax_service'     => 'GST/HST Returns',
			'tax_authority'         => 'the CRA',
			'tax_authority_short'   => 'CRA',
			'personal_tax_service'  => 'Personal Tax',
			'retirement_service'    => 'Retirement Planning',
			'retirement_savings'    => 'RRSP and TFSA savings',
			'self_managed_fund'     => 'individual pension plan',
			'payroll_reporting'     => 'payroll remittances and T4 slips',
			'employer_contribution' => 'CPP and EI contributions',
			'year_end'              => 'end of the tax year',
			'business_number'       => 'Business Number',
		),
	);
}

function bz_core_term( $key ) {
	$terms  = bz_core_region_terms();
	$region = bz_core_get( 'region' );
	$set    = isset( $terms[ $region ] ) ? $terms[ $region ] : $terms['AU'];
	return isset( $set[ $key ] ) ? $set[ $key ] : null;
}

/**
 * Replace {tokens} in text with region terms, then Client Details fields. Unknown tokens are left as-is.
 */
function bz_core_fill_tokens( $text ) {
	return preg_replace_callback(
		'/\{([a-z_]+)\}/',
		function ( $m ) {
			$term = bz_core_term( $m[1] );
			if ( null !== $term ) {
				return esc_html( $term );
			}
			$value = bz_core_get( $m[1] );
			return '' !== $value ? esc_html( $value ) : $m[0];
		},
		$text
	);
}

/* [bz_t]Text with {tokens}[/bz_t] — also accepts [bz_t key="sales_tax"] for a single term. */
add_shortcode(
	'bz_t',
	function ( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'key' => '' ), $atts, 'bz_t' );
		if ( '' !== $atts['key'] ) {
			return esc_html( (string) bz_core_term( sanitize_key( $atts['key'] ) ) );
		}
		// Content may hold simple markup (e.g. a <ul> checklist); keep safe HTML, escape term values.
		return wp_kses_post( bz_core_fill_tokens( (string) $content ) );
	}
);

/* ---------- Region-specific pages ---------- */

/**
 * Pages whose title depends on the region: page key => [original master slug, term key].
 * The page key is stored in _bz_page_key on first apply, so later applies find the page after a rename.
 */
function bz_core_region_pages() {
	return array(
		'sales_tax'    => array( 'sales-tax-compliance', 'sales_tax_service' ),
		'personal_tax' => array( 'personal-tax', 'personal_tax_service' ),
		'retirement'   => array( 'retirement-planning', 'retirement_service' ),
	);
}

function bz_core_find_region_page( $page_key, $original_slug ) {
	$found = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'meta_key'       => '_bz_page_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $page_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => 1,
		)
	);
	if ( $found ) {
		return $found[0];
	}
	$by_slug = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'name'           => $original_slug,
			'posts_per_page' => 1,
		)
	);
	return $by_slug ? $by_slug[0] : null;
}

/**
 * Planned changes for the current region: list of [page, new title, new slug].
 */
function bz_core_region_plan() {
	$plan = array();
	foreach ( bz_core_region_pages() as $page_key => $def ) {
		$page = bz_core_find_region_page( $page_key, $def[0] );
		if ( ! $page ) {
			continue;
		}
		$title  = bz_core_term( $def[1] );
		$plan[] = array(
			'key'           => $page_key,
			'page'          => $page,
			'title'         => $title,
			'slug'          => sanitize_title( $title ),
			'original_slug' => $def[0],
		);
	}
	return $plan;
}

/* ---------- 301 redirects from renamed pages ---------- */

define( 'BZ_REDIRECTS_OPTION', 'bz_core_redirects' );

function bz_core_url_path( $url ) {
	return trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
}

/**
 * Record the page's current URL path before it is renamed, so the old address 301s to the page.
 * Stored as path => page ID, so the redirect always follows the page's latest URL.
 */
function bz_core_remember_redirect( $page_id ) {
	bz_core_remember_path( bz_core_url_path( get_permalink( $page_id ) ), $page_id );
}

function bz_core_remember_path( $path, $page_id ) {
	if ( '' === $path ) {
		return;
	}
	$map          = get_option( BZ_REDIRECTS_OPTION, array() );
	$map[ $path ] = (int) $page_id;
	update_option( BZ_REDIRECTS_OPTION, $map, false );
}

add_action(
	'template_redirect',
	function () {
		if ( ! is_404() ) {
			return;
		}
		$map  = get_option( BZ_REDIRECTS_OPTION, array() );
		$path = bz_core_url_path( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared against stored paths.
		if ( '' === $path || ! isset( $map[ $path ] ) || 'publish' !== get_post_status( $map[ $path ] ) ) {
			return;
		}
		$target = get_permalink( $map[ $path ] );
		if ( bz_core_url_path( $target ) !== $path ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}
);

add_action(
	'admin_menu',
	function () {
		add_management_page( 'Region Pack', 'Region Pack', 'manage_options', 'bz-region-pack', 'bz_core_render_region_page' );
	}
);

add_action(
	'admin_post_bz_apply_region',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'bz_apply_region' );
		$done = bz_core_apply_region_pack();
		wp_safe_redirect( add_query_arg( array( 'page' => 'bz-region-pack', 'applied' => $done ), admin_url( 'tools.php' ) ) );
		exit;
	}
);

/**
 * Rename region-specific pages for the current region and record redirects. Returns pages renamed.
 */
function bz_core_apply_region_pack() {
	$done = 0;
	foreach ( bz_core_region_plan() as $item ) {
		update_post_meta( $item['page']->ID, '_bz_page_key', $item['key'] );
		// Always cover the page's original master URL (it may have been renamed before redirects existed).
		$parent_path = $item['page']->post_parent ? bz_core_url_path( get_permalink( $item['page']->post_parent ) ) : '';
		bz_core_remember_path( ltrim( $parent_path . '/' . $item['original_slug'], '/' ), $item['page']->ID );
		if ( $item['page']->post_title !== $item['title'] || $item['page']->post_name !== $item['slug'] ) {
			bz_core_remember_redirect( $item['page']->ID );
			wp_update_post(
				array(
					'ID'         => $item['page']->ID,
					'post_title' => $item['title'],
					'post_name'  => $item['slug'],
				)
			);
			++$done;
		}
	}
	// Calculators and resources record the region they were set up for.
	foreach ( get_posts( array( 'post_type' => 'bz_calculator', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any' ) ) as $calc_id ) {
		update_post_meta( $calc_id, 'bz_region', bz_core_get( 'region' ) );
	}
	return $done;
}

function bz_core_render_region_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$region  = bz_core_get( 'region' );
	$regions = bz_core_regions();
	$plan    = bz_core_region_plan();
	?>
	<div class="wrap">
		<h1>Region Pack</h1>
		<?php if ( isset( $_GET['applied'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success"><p><?php echo esc_html( absint( $_GET['applied'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?> page(s) renamed for <?php echo esc_html( $regions[ $region ] ); ?>.</p></div>
		<?php endif; ?>
		<p>Current region: <strong><?php echo esc_html( $regions[ $region ] ); ?></strong>. Change it in <a href="<?php echo esc_url( admin_url( 'options-general.php?page=bz-client-details' ) ); ?>">Settings → Client Details</a>.</p>
		<p>Text using <code>[bz_t]</code> already follows the region. Applying the pack also renames these pages and their URLs (menus and buttons keep working because they link to the page, not the URL):</p>
		<table class="widefat striped" style="max-width:820px">
			<thead><tr><th>Page</th><th>Current title / URL</th><th>New title / URL</th></tr></thead>
			<tbody>
			<?php foreach ( $plan as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item['key'] ); ?></td>
					<td><?php echo esc_html( $item['page']->post_title ); ?><br><code><?php echo esc_html( $item['page']->post_name ); ?></code></td>
					<td><strong><?php echo esc_html( $item['title'] ); ?></strong><br><code><?php echo esc_html( $item['slug'] ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
			<input type="hidden" name="action" value="bz_apply_region">
			<?php wp_nonce_field( 'bz_apply_region' ); ?>
			<?php submit_button( 'Apply region pack', 'primary', 'submit', false ); ?>
		</form>
		<?php
		$redirects = get_option( BZ_REDIRECTS_OPTION, array() );
		if ( $redirects ) :
			?>
			<h2 style="margin-top:32px">Redirects (301)</h2>
			<p>Old addresses of renamed pages forward to the page’s current address.</p>
			<table class="widefat striped" style="max-width:820px">
				<thead><tr><th>Old address</th><th>Goes to</th></tr></thead>
				<tbody>
				<?php foreach ( $redirects as $old_path => $page_id ) : ?>
					<tr><td><code>/<?php echo esc_html( $old_path ); ?>/</code></td><td><?php echo esc_html( get_the_title( $page_id ) ); ?> — <code><?php echo esc_html( '/' . bz_core_url_path( get_permalink( $page_id ) ) . '/' ); ?></code></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		if ( function_exists( 'bz_core_tax_dates_status_html' ) ) {
			echo bz_core_tax_dates_status_html(); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- escaped inside.
		}
		?>
		<h2 style="margin-top:32px">Terms for <?php echo esc_html( $regions[ $region ] ); ?></h2>
		<p>Use these in page text as <code>[bz_t]… {token} …[/bz_t]</code>.</p>
		<table class="widefat striped" style="max-width:820px">
			<tbody>
			<?php foreach ( bz_core_region_terms()[ $region ] as $key => $term ) : ?>
				<tr><td><code>{<?php echo esc_html( $key ); ?>}</code></td><td><?php echo esc_html( $term ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
