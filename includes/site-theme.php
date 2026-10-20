<?php
/**
 * Appearance → Site theme: switch the site between the Bizink themes (themes/theme-*.json) from wp-admin.
 *
 * Applying a theme does what tools/apply_theme.py does, on the site itself:
 *   1. updates the Elementor V4 global variables (colours, fonts, sizes, spacing, radii) by label, through Elementor's
 *      own "elementor/manage-global-variable" ability, so its validation and cache clearing run as usual;
 *   2. swaps the theme-* snippet in the kit's Custom CSS and makes sure signature-blocks is there;
 *   3. clears Elementor's CSS files so pages rebuild with the new values.
 * "Keep our brand colours" leaves every color-* variable alone. The previous values and CSS are saved first, so
 * "Undo" puts the site back exactly as it was (last five changes kept).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Theme snippet per theme file (themes/snippets/<snippet>.css). */
function bz_core_theme_snippets() {
	return array(
		'theme-03-ledger' => 'theme-ledger',
		'theme-04-lumen'  => 'theme-lumen',
		'theme-05-signal' => 'theme-signal',
		'theme-06-horizon' => 'theme-horizon',
	);
}

/* Bizink themes: slug => decoded JSON (name, description, tokens). Client files (client-*.json) are not offered. */
function bz_core_site_themes() {
	$out = array();
	foreach ( glob( dirname( __DIR__ ) . '/themes/theme-*.json' ) as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( is_array( $data ) && ! empty( $data['tokens'] ) ) {
			$out[ basename( $file, '.json' ) ] = $data;
		}
	}
	ksort( $out );
	return $out;
}

/* The kit's variables: label => [id, value]. Null when Elementor V4 variables aren't available. */
function bz_core_theme_variables() {
	if ( ! class_exists( '\Elementor\Plugin' ) || ! class_exists( '\Elementor\Modules\Variables\Services\Variables_Service' ) ) {
		return null;
	}
	$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
	if ( ! $kit ) {
		return null;
	}
	try {
		$service = new \Elementor\Modules\Variables\Services\Variables_Service(
			new \Elementor\Modules\Variables\Storage\Variables_Repository( $kit ),
			new \Elementor\Modules\Variables\Services\Batch_Operations\Batch_Processor()
		);
		$list    = $service->get_variables_list();
	} catch ( \Throwable $e ) {
		return null;
	}
	$out = array();
	foreach ( $list as $id => $v ) {
		if ( empty( $v['deleted'] ) && isset( $v['label'] ) ) {
			$out[ $v['label'] ] = array( 'id' => $id, 'value' => isset( $v['value'] ) ? $v['value'] : '' );
		}
	}
	return $out;
}

function bz_core_theme_kit() {
	return class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::$instance->kits_manager->get_active_kit() : null;
}

function bz_core_theme_kit_css() {
	$kit = bz_core_theme_kit();
	if ( ! $kit ) {
		return '';
	}
	$settings = $kit->get_meta( '_elementor_page_settings' );
	return is_array( $settings ) && isset( $settings['custom_css'] ) ? (string) $settings['custom_css'] : '';
}

/* Swap the theme snippet: remove every theme-* snippet, then add this theme's (if any) and signature-blocks. */
function bz_core_theme_css_for( $css, $slug ) {
	foreach ( bz_core_theme_snippets() as $name ) {
		$css = preg_replace( '#\n*' . preg_quote( "/* bz-snippet:$name */", '#' ) . '.*?' . preg_quote( "/* /bz-snippet:$name */", '#' ) . '#s', '', $css );
	}
	$snippets = bz_core_theme_snippets();
	$wanted   = array_filter( array( 'signature-blocks', isset( $snippets[ $slug ] ) ? $snippets[ $slug ] : '' ) );
	foreach ( $wanted as $name ) {
		$file = dirname( __DIR__ ) . '/themes/snippets/' . $name . '.css';
		if ( ! file_exists( $file ) ) {
			continue;
		}
		$start = "/* bz-snippet:$name */";
		$end   = "/* /bz-snippet:$name */";
		$css   = preg_replace( '#\n*' . preg_quote( $start, '#' ) . '.*?' . preg_quote( $end, '#' ) . '#s', '', $css );
		$css   = rtrim( $css ) . "\n\n" . $start . "\n" . trim( (string) file_get_contents( $file ) ) . "\n" . $end . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return $css;
}

/* Run Elementor's manage-global-variable ability with update operations (50 per call). */
function bz_core_theme_update_variables( $ops ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return new WP_Error( 'bz_theme_no_abilities', 'This WordPress has no Abilities API, which Elementor uses to save variables.' );
	}
	$ability = wp_get_ability( 'elementor/manage-global-variable' );
	if ( ! $ability ) {
		return new WP_Error( 'bz_theme_no_ability', 'Elementor\'s variable tools are not available (is the Elementor MCP/V4 feature on?).' );
	}
	$failed = array();
	foreach ( array_chunk( $ops, 50 ) as $chunk ) {
		$result = $ability->execute( array( 'operations' => $chunk ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( isset( $result['results'] ) ? (array) $result['results'] : array() as $row ) {
			if ( isset( $row['status'] ) && 'error' === $row['status'] ) {
				$i        = isset( $row['index'] ) ? (int) $row['index'] : -1;
				$failed[] = ( isset( $chunk[ $i ]['label'] ) ? $chunk[ $i ]['label'] : '?' ) . ': ' . ( isset( $row['message'] ) ? $row['message'] : 'error' );
			}
		}
	}
	return $failed ? new WP_Error( 'bz_theme_partial', 'Some values were not saved: ' . implode( '; ', $failed ) ) : true;
}

function bz_core_theme_flush() {
	if ( class_exists( '\Elementor\Plugin' ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	do_action( 'bz_core_theme_changed' );
}

/**
 * Apply a theme. Returns a summary string or WP_Error. $keep_colours leaves color-* variables as they are.
 * $hero ('slider' or 'single') also puts the theme's photo banner on the home page (themes with "hero": "image").
 */
function bz_core_apply_site_theme( $slug, $keep_colours, $hero = '' ) {
	$themes = bz_core_site_themes();
	if ( ! isset( $themes[ $slug ] ) ) {
		return new WP_Error( 'bz_theme_unknown', 'Unknown theme.' );
	}
	$vars = bz_core_theme_variables();
	$kit  = bz_core_theme_kit();
	if ( null === $vars || ! $kit ) {
		return new WP_Error( 'bz_theme_no_vars', 'Elementor V4 global variables are not available on this site.' );
	}

	$ops      = array();
	$previous = array();
	$missing  = array();
	foreach ( $themes[ $slug ]['tokens'] as $label => $value ) {
		if ( $keep_colours && 0 === strpos( $label, 'color-' ) ) {
			continue;
		}
		if ( ! isset( $vars[ $label ] ) ) {
			$missing[] = $label;
			continue;
		}
		if ( strtolower( trim( (string) $vars[ $label ]['value'] ) ) === strtolower( trim( (string) $value ) ) ) {
			continue;
		}
		$previous[ $label ] = $vars[ $label ]['value'];
		$ops[]              = array( 'action' => 'update', 'id' => $vars[ $label ]['id'], 'label' => $label, 'value' => $value );
	}

	$css_before = bz_core_theme_kit_css();
	$css_after  = bz_core_theme_css_for( $css_before, $slug );

	// Save the undo point before changing anything.
	$history = (array) get_option( 'bz_theme_history', array() );
	array_unshift(
		$history,
		array(
			'time'      => time(),
			'user'      => get_current_user_id(),
			'from'      => get_option( 'bz_site_theme', '' ),
			'to'        => $slug,
			'kept'      => (bool) $keep_colours,
			'variables' => $previous,
			'css'       => $css_before,
		)
	);
	update_option( 'bz_theme_history', array_slice( $history, 0, 5 ), false );

	if ( $ops ) {
		$saved = bz_core_theme_update_variables( $ops );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
	}
	if ( $css_after !== $css_before ) {
		$kit->update_settings( array( 'custom_css' => $css_after ) );
	}
	update_option( 'bz_site_theme', $slug, false );
	update_option( 'bz_site_theme_keep_colours', $keep_colours ? '1' : '', false );

	// Home banner (photo-banner themes): the page's data before the swap goes into the same undo point.
	$banner = '';
	$variants = function_exists( 'bz_core_hero_variants' ) ? bz_core_hero_variants() : array();
	if ( isset( $variants[ $hero ] ) && isset( $themes[ $slug ]['hero'] ) && 'image' === $themes[ $slug ]['hero'] ) {
		$swap = bz_core_hero_swap( $hero );
		if ( is_wp_error( $swap ) ) {
			$banner = ' The home banner was not added: ' . $swap->get_error_message();
		} else {
			$history                 = (array) get_option( 'bz_theme_history', array() );
			$history[0]['hero']      = $hero;
			$history[0]['page']      = $swap[0];
			$history[0]['page_data'] = $swap[1];
			update_option( 'bz_theme_history', $history, false );
			$banner = ' The home page now opens with the ' . $variants[ $hero ]['label'] . ': change its photos and wording in Elementor.';
		}
	}
	bz_core_theme_flush();

	return sprintf(
		'%s applied: %d value%s changed%s%s.%s',
		$themes[ $slug ]['name'],
		count( $ops ),
		1 === count( $ops ) ? '' : 's',
		$keep_colours ? ', brand colours kept' : '',
		$missing ? ' (not on this site: ' . implode( ', ', $missing ) . ')' : '',
		$banner
	);
}

/* Undo the most recent change. */
function bz_core_undo_site_theme() {
	$history = (array) get_option( 'bz_theme_history', array() );
	if ( ! $history ) {
		return new WP_Error( 'bz_theme_nothing', 'Nothing to undo.' );
	}
	$last = array_shift( $history );
	$vars = bz_core_theme_variables();
	$kit  = bz_core_theme_kit();
	if ( null === $vars || ! $kit ) {
		return new WP_Error( 'bz_theme_no_vars', 'Elementor V4 global variables are not available on this site.' );
	}
	$ops = array();
	foreach ( (array) $last['variables'] as $label => $value ) {
		if ( isset( $vars[ $label ] ) ) {
			$ops[] = array( 'action' => 'update', 'id' => $vars[ $label ]['id'], 'label' => $label, 'value' => $value );
		}
	}
	if ( $ops ) {
		$saved = bz_core_theme_update_variables( $ops );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
	}
	$kit->update_settings( array( 'custom_css' => (string) $last['css'] ) );
	if ( ! empty( $last['page'] ) && function_exists( 'bz_core_hero_restore' ) ) {
		bz_core_hero_restore( $last['page'], isset( $last['page_data'] ) ? $last['page_data'] : '' );
	}
	update_option( 'bz_theme_history', $history, false );
	update_option( 'bz_site_theme', (string) $last['from'], false );
	bz_core_theme_flush();
	return 'Undone: the site is back to how it was before ' . human_time_diff( $last['time'] ) . ' ago.';
}

/* ---------- Admin page ---------- */

add_action(
	'admin_menu',
	function () {
		add_theme_page( 'Site theme', 'Site theme', 'manage_options', 'bz-site-theme', 'bz_core_render_site_theme' );
	}
);

// Each card's sample uses its theme's heading font.
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'appearance_page_bz-site-theme' !== $hook ) {
			return;
		}
		$fonts = array();
		foreach ( bz_core_site_themes() as $t ) {
			$fonts[ $t['tokens']['font-heading'] ] = true;
		}
		$families = implode( '&', array_map( function ( $f ) {
			return 'family=' . str_replace( ' ', '+', $f ) . ':wght@600';
		}, array_keys( $fonts ) ) );
		wp_enqueue_style( 'bz-site-theme-fonts', 'https://fonts.googleapis.com/css2?' . $families . '&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
	}
);

add_action(
	'admin_post_bz_site_theme',
	function () {
		check_admin_referer( 'bz_site_theme' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$slug = isset( $_POST['theme'] ) ? sanitize_file_name( wp_unslash( $_POST['theme'] ) ) : '';
		$keep = ! empty( $_POST['keep_colours'] );
		$hero = isset( $_POST['hero'] ) ? sanitize_key( wp_unslash( $_POST['hero'] ) ) : '';
		$res  = 'undo' === $do ? bz_core_undo_site_theme() : bz_core_apply_site_theme( $slug, $keep, $hero );
		$msg  = is_wp_error( $res ) ? 'Not changed: ' . $res->get_error_message() : $res;
		wp_safe_redirect( add_query_arg( array( 'page' => 'bz-site-theme', 'bz_msg' => rawurlencode( $msg ), 'bz_ok' => is_wp_error( $res ) ? '0' : '1' ), admin_url( 'themes.php' ) ) );
		exit;
	}
);

function bz_core_render_site_theme() {
	$themes  = bz_core_site_themes();
	$current = (string) get_option( 'bz_site_theme', '' );
	$vars    = bz_core_theme_variables();
	$history = (array) get_option( 'bz_theme_history', array() );
	$msg     = isset( $_GET['bz_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bz_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$ok      = ! isset( $_GET['bz_ok'] ) || '1' === $_GET['bz_ok']; // phpcs:ignore WordPress.Security.NonceVerification
	$now     = function ( $label, $fallback ) use ( $vars ) {
		return isset( $vars[ $label ]['value'] ) && '' !== $vars[ $label ]['value'] ? $vars[ $label ]['value'] : $fallback;
	};
	?>
	<style>
		.bzt{max-width:1180px}
		.bzt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:18px;margin:18px 0}
		.bzt-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
		.bzt-card.current{border-color:#2271b1;box-shadow:0 0 0 2px #2271b1}
		.bzt-sample{padding:22px 20px;min-height:132px;display:flex;flex-direction:column;justify-content:space-between;gap:14px}
		.bzt-sample h3{margin:0;font-size:22px;line-height:1.15;color:inherit}
		.bzt-sample .bzt-btn{align-self:flex-start;padding:8px 16px;font-size:13px;font-weight:600}
		.bzt-thumb{display:block;width:100%;height:auto;aspect-ratio:16/10;object-fit:cover;object-position:top;background:#f0f0f1}
		.bzt-swatches{display:flex;height:10px}.bzt-swatches span{flex:1}
		.bzt-info{padding:14px 18px 18px;display:flex;flex-direction:column;gap:10px;flex:1}
		.bzt-info strong{font-size:14px}
		.bzt-info p{margin:0;color:#50575e;font-size:12.5px;line-height:1.5;flex:1}
		.bzt-tag{display:inline-block;font-size:11px;font-weight:600;background:#e7f1fb;color:#135e96;border-radius:999px;padding:2px 8px;margin-left:6px}
		.bzt-history{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:6px 18px 14px;margin-top:10px}
		.bzt-history li{margin:6px 0;color:#50575e}
	</style>
	<div class="wrap bzt">
		<h1>Site theme</h1>
		<p>Choose the look for this website. Colours, fonts, text sizes and corner rounding change across every page at once. Your content stays as it is.</p>
		<?php if ( $msg ) : ?>
			<div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>
		<?php if ( null === $vars ) : ?>
			<div class="notice notice-warning"><p>Elementor V4 global variables are not available on this site, so themes can't be applied here.</p></div>
		<?php endif; ?>

		<h2 style="margin-top:24px">Now</h2>
		<p>
			<?php
			echo esc_html(
				( $current && isset( $themes[ $current ] ) ? $themes[ $current ]['name'] . ( get_option( 'bz_site_theme_keep_colours' ) ? ' (with the firm\'s own colours)' : '' ) : 'The firm\'s own design (no Bizink theme applied from this page yet)' )
			);
			?>
			<span style="display:inline-flex;gap:4px;vertical-align:middle;margin-left:8px">
				<?php foreach ( array( 'color-primary', 'color-secondary', 'color-accent', 'color-heading', 'color-surface-alt' ) as $c ) : ?>
					<span title="<?php echo esc_attr( $c ); ?>" style="width:18px;height:18px;border-radius:4px;border:1px solid #dcdcde;background:<?php echo esc_attr( $now( $c, '#fff' ) ); ?>"></span>
				<?php endforeach; ?>
			</span>
		</p>

		<div class="bzt-grid">
			<?php
			foreach ( $themes as $slug => $t ) :
				$tk    = $t['tokens'];
				$radius = isset( $tk['radius-button'] ) ? $tk['radius-button'] : '6px';
				?>
				<div class="bzt-card<?php echo $slug === $current ? ' current' : ''; ?>">
					<?php if ( file_exists( dirname( __DIR__ ) . '/themes/thumbs/' . $slug . '.jpg' ) ) : ?>
					<a href="<?php echo esc_url( plugins_url( 'themes/thumbs/' . $slug . '.jpg', dirname( __FILE__ ) ) ); ?>" target="_blank" rel="noopener" title="Open a larger view">
						<img class="bzt-thumb" src="<?php echo esc_url( plugins_url( 'themes/thumbs/' . $slug . '.jpg', dirname( __FILE__ ) ) ); ?>" alt="<?php echo esc_attr( 'Home page in ' . $t['name'] ); ?>" width="720" height="450" loading="lazy">
					</a>
					<?php else : ?>
					<div class="bzt-sample" style="background:<?php echo esc_attr( $tk['color-surface-alt'] ); ?>;color:<?php echo esc_attr( $tk['color-heading'] ); ?>;font-family:'<?php echo esc_attr( $tk['font-heading'] ); ?>',system-ui,sans-serif">
						<h3>Numbers that move you forward</h3>
						<span class="bzt-btn" style="background:<?php echo esc_attr( $tk['color-primary'] ); ?>;color:#fff;border-radius:<?php echo esc_attr( $radius ); ?>">Book a call</span>
					</div>
					<?php endif; ?>
					<div class="bzt-swatches">
						<?php foreach ( array( 'color-primary', 'color-primary-dark', 'color-secondary', 'color-accent', 'color-heading' ) as $c ) : ?>
							<span style="background:<?php echo esc_attr( isset( $tk[ $c ] ) ? $tk[ $c ] : '#ccc' ); ?>"></span>
						<?php endforeach; ?>
					</div>
					<div class="bzt-info">
						<strong><?php echo esc_html( $t['name'] ); ?><?php echo $slug === $current ? '<span class="bzt-tag">In use</span>' : ''; ?></strong>
						<p><?php echo esc_html( isset( $t['description'] ) ? $t['description'] : '' ); ?></p>
						<p style="flex:0"><?php echo esc_html( $tk['font-heading'] . ' / ' . $tk['font-body'] . ', corners ' . ( isset( $tk['radius-card'] ) ? $tk['radius-card'] : '' ) ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Apply <?php echo esc_js( $t['name'] ); ?> to the whole site? You can undo it afterwards.');">
							<?php wp_nonce_field( 'bz_site_theme' ); ?>
							<input type="hidden" name="action" value="bz_site_theme">
							<input type="hidden" name="do" value="apply">
							<input type="hidden" name="theme" value="<?php echo esc_attr( $slug ); ?>">
							<label style="display:block;margin:0 0 10px"><input type="checkbox" name="keep_colours" value="1" checked> Keep the firm's brand colours</label>
							<?php if ( isset( $t['hero'] ) && 'image' === $t['hero'] ) : ?>
								<fieldset style="margin:0 0 12px;padding:8px 10px;border:1px solid #dcdcde;border-radius:8px">
									<legend style="font-weight:600;padding:0 4px">Home banner</legend>
									<label style="display:block"><input type="radio" name="hero" value="slider" checked> Photo slider (three photos)</label>
									<label style="display:block"><input type="radio" name="hero" value="stories"> Photo slider with a different message on each photo</label>
									<label style="display:block"><input type="radio" name="hero" value="single"> Single photo</label>
									<label style="display:block"><input type="radio" name="hero" value=""> Keep the current home hero</label>
									<span style="display:block;color:#646970;font-size:12px;margin-top:4px">Replaces the top section of the home page. Starter photos are added; change them in Elementor.</span>
								</fieldset>
							<?php endif; ?>
							<button class="button button-primary"<?php disabled( null === $vars ); ?>>Apply this theme</button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $history ) : ?>
			<div class="bzt-history">
				<h2>Recent changes</h2>
				<ul>
					<?php foreach ( $history as $h ) : ?>
						<li>
							<?php
							$who = get_userdata( (int) $h['user'] );
							echo esc_html(
								human_time_diff( $h['time'] ) . ' ago: ' . ( isset( $themes[ $h['to'] ] ) ? $themes[ $h['to'] ]['name'] : $h['to'] )
								. ( $h['kept'] ? ' (brand colours kept)' : '' ) . ( ! empty( $h['hero'] ) && function_exists( 'bz_core_hero_variants' ) && isset( bz_core_hero_variants()[ $h['hero'] ] ) ? ' with the home ' . bz_core_hero_variants()[ $h['hero'] ]['label'] : '' ) . ( $who ? ' by ' . $who->display_name : '' )
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Undo the most recent theme change?');">
					<?php wp_nonce_field( 'bz_site_theme' ); ?>
					<input type="hidden" name="action" value="bz_site_theme">
					<input type="hidden" name="do" value="undo">
					<button class="button">Undo the last change</button>
				</form>
			</div>
		<?php endif; ?>
		<p class="description" style="margin-top:18px">After a change, purge the Varnish cache and check a few pages in a private window. Horizon can add its home banner here; the other signature sections (Ledger, Lumen, Signal) are only on the master site for now.</p>
	</div>
	<?php
}
