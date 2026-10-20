<?php
/**
 * Branded login screen: a Bizink support bar across the top (knowledge base + "Create a ticket", which opens the
 * support form in a dialog), the client's logo (Site Identity) above the login box, and the site's own colours,
 * fonts and corner radius from the Elementor kit, so the page looks like the firm's site rather than WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BZ_SUPPORT_KB' ) ) {
	define( 'BZ_SUPPORT_KB', 'https://support.bizinkonline.com/' );
}
if ( ! defined( 'BZ_SUPPORT_FORM' ) ) {
	define( 'BZ_SUPPORT_FORM', 'https://admin.bizinkonline.com/support' );
}
// The script-embed form (no iframe) is the default; define BZ_SUPPORT_EMBED_JS as '' in wp-config to use the iframe.
if ( ! defined( 'BZ_SUPPORT_EMBED_JS' ) ) {
	define( 'BZ_SUPPORT_EMBED_JS', 'https://admin.bizinkonline.com/bizink-support-form.js' );
}
if ( ! defined( 'BZ_SUPPORT_ENDPOINT' ) ) {
	define( 'BZ_SUPPORT_ENDPOINT', 'https://knowing-oyster-284.convex.site/site-ticket' );
}

/* The embed script URL. The early placeholder address (/support/embed.js) maps to the real script. */
function bz_core_support_script() {
	$src = (string) BZ_SUPPORT_EMBED_JS;
	return preg_match( '#/support/embed\.js$#', $src ) ? 'https://admin.bizinkonline.com/bizink-support-form.js' : $src;
}

/* The site logo from Settings → General / Site Identity, or ''. */
function bz_core_login_logo() {
	$id = (int) get_theme_mod( 'custom_logo' );
	if ( ! $id ) {
		$id = (int) get_option( 'site_logo' );
	}
	return $id ? (string) wp_get_attachment_image_url( $id, 'medium_large' ) : '';
}

/*
 * The Elementor kit stylesheet (defines --color-primary etc.) and its file path. Elementor deletes these files when
 * its CSS is regenerated and rebuilds them on the next front-end view, so ask Elementor to build it when missing.
 */
function bz_core_login_kit_css() {
	$kit = (int) get_option( 'elementor_active_kit' );
	$up  = wp_upload_dir();
	$rel = 'elementor/css/post-' . $kit . '.css';
	if ( $kit && ! file_exists( $up['basedir'] . '/' . $rel ) && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
		try {
			\Elementor\Core\Files\CSS\Post::create( $kit )->update();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Fall back to the plain look below.
		}
	}
	if ( ! $kit || ! file_exists( $up['basedir'] . '/' . $rel ) ) {
		return null;
	}
	return array(
		'url'  => $up['baseurl'] . '/' . $rel,
		'path' => $up['basedir'] . '/' . $rel,
	);
}

/* Enqueue the kit stylesheet (colour, font and radius variables) and its Google Fonts, as {$prefix}-kit / -fonts. */
function bz_core_kit_assets( $prefix ) {
	$kit = bz_core_login_kit_css();
	if ( ! $kit ) {
		return;
	}
	wp_enqueue_style( $prefix . '-kit', $kit['url'], array(), (string) filemtime( $kit['path'] ) );
	preg_match_all( '/--font-(?:heading|body):\s*["\']?([^;"\'}]+)/', (string) file_get_contents( $kit['path'] ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$fonts = array_unique( array_filter( array_map( 'trim', $m[1] ) ) );
	if ( $fonts ) {
		$families = implode( '&', array_map( function ( $f ) {
			return 'family=' . str_replace( ' ', '+', $f ) . ':wght@400;500;600;700';
		}, $fonts ) );
		wp_enqueue_style( $prefix . '-fonts', 'https://fonts.googleapis.com/css2?' . $families . '&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
	}
}

add_action(
	'login_enqueue_scripts',
	function () {
		bz_core_kit_assets( 'bz-login' );
		$logo = bz_core_login_logo();
		$css  = ':root{--bzl-primary:var(--color-primary,#333B61);--bzl-primary-dark:var(--color-primary-dark,#252b49);--bzl-heading:var(--color-heading,#1d2327);'
			. '--bzl-text:var(--color-text,#3c434a);--bzl-muted:var(--color-text-muted,#646970);--bzl-bg:var(--color-surface-alt,#f4f5f7);--bzl-card:var(--color-surface,#fff);'
			. '--bzl-border:var(--color-border,#dcdcde);--bzl-radius:var(--radius-card,10px);--bzl-btn-radius:var(--radius-button,6px);--bzl-font:var(--font-body,system-ui),-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}'
			. 'body.login{background:var(--bzl-bg);font-family:var(--bzl-font);color:var(--bzl-text);display:flex;flex-direction:column;min-height:100vh}'
			// Bizink support bar.
			. '.bzl-bar{background:#333B61;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 24px;flex-wrap:wrap}'
			. '.bzl-kb-short{display:none}.bzl-bar img{height:28px;width:auto;display:block}'
			. '.bzl-bar nav{display:flex;align-items:center;gap:20px;font-size:14px}'
			. '.bzl-bar a{color:#fff;text-decoration:none}.bzl-bar a:hover,.bzl-bar a:focus{text-decoration:underline;color:#fff}'
			. '.bzl-bar .bzl-ticket{background:#FCB813;color:#1d2327;font-weight:600;padding:8px 16px;border-radius:4px;border:0;cursor:pointer;font:inherit;font-weight:600}'
			. '.bzl-bar .bzl-ticket:hover{background:#ffc93d;text-decoration:none}'
			// Login card.
			. '#login{width:400px;max-width:calc(100% - 32px);box-sizing:border-box;margin:7vh auto 32px;padding:28px 32px 24px;background:var(--bzl-card);border:1px solid var(--bzl-border);border-radius:var(--bzl-radius);box-shadow:0 10px 40px rgba(0,0,0,.07)}'
			. '#login h1 a{' . ( $logo ? 'background-image:url(' . esc_url( $logo ) . ');background-size:contain;background-position:center;width:100%;max-width:280px;height:80px;' : 'background:none;text-indent:0;width:auto;height:auto;font-family:var(--font-heading,system-ui),system-ui,sans-serif;font-size:26px;font-weight:600;color:var(--bzl-heading);' ) . 'margin:0 auto 24px}'
			. '.login form{background:none;border:0;box-shadow:none;padding:0;margin:0;overflow:visible}.login form::after{content:"";display:table;clear:both}#login h1{padding:0}'
			. '.login label{color:var(--bzl-heading);font-weight:500;font-size:14px}'
			. '.login input[type=text],.login input[type=password],.login input[type=email]{border:1px solid var(--bzl-border);border-radius:var(--bzl-btn-radius);padding:8px 12px;font-size:16px;box-shadow:none}'
			. '.login input[type=text]:focus,.login input[type=password]:focus,.login input[type=email]:focus{border-color:var(--bzl-primary);box-shadow:0 0 0 1px var(--bzl-primary)}'
			. '.login .button-primary{background:var(--bzl-primary);border-color:var(--bzl-primary);border-radius:var(--bzl-btn-radius);font-weight:600;padding:4px 22px;font-size:15px;min-height:40px;text-shadow:none;box-shadow:none}'
			. '.login .button-primary:hover,.login .button-primary:focus{background:var(--bzl-primary-dark);border-color:var(--bzl-primary-dark)}'
			. '.login .button.wp-hide-pw .dashicons{color:var(--bzl-primary)}'
			. 'input[type=checkbox]:checked::before{content:none}input[type=checkbox]{accent-color:var(--bzl-primary)}'
			. '.login #nav{text-align:center;padding:20px 0 0;margin:24px 0 0;border-top:1px solid var(--bzl-border);font-size:14px}.login #backtoblog{text-align:center;padding:0;margin:10px 0 0;font-size:14px}'
			. '.login #nav a,.login #backtoblog a{color:var(--bzl-muted)}.login #nav a:hover,.login #backtoblog a:hover{color:var(--bzl-primary)}'
			. '.login .message,.login .notice,.login .success{border-radius:var(--bzl-btn-radius);border-left-color:var(--bzl-primary)}'
			. '.login .language-switcher,.login .privacy-policy-page-link{display:none}'
			. bz_core_support_dialog_css()
			. '@media (max-width:600px){.bzl-bar{padding:10px 16px}.bzl-bar nav{gap:14px;font-size:13px}.bzl-bar .bzl-kb-long{display:none}.bzl-bar .bzl-kb-short{display:inline}#login{margin-top:24px;padding:28px 20px 20px}}';
		wp_register_style( 'bz-login', false, array(), BZ_CORE_VERSION );
		wp_enqueue_style( 'bz-login' );
		wp_add_inline_style( 'bz-login', $css );
	}
);

// The support bar, right after <body>.
add_action(
	'login_header',
	function () {
		$logo = plugins_url( 'assets/bizink-logo-white.png', dirname( __FILE__ ) );
		echo '<div class="bzl-bar" role="banner">';
		echo '<a href="https://www.bizinkonline.com/" target="_blank" rel="noopener"><img src="' . esc_url( $logo ) . '" alt="Bizink" width="93" height="28"></a>';
		echo '<nav aria-label="Bizink support"><a href="' . esc_url( BZ_SUPPORT_KB ) . '" target="_blank" rel="noopener"><span class="bzl-kb-long">Knowledge base</span><span class="bzl-kb-short">Help</span></a>';
		echo '<a class="bzl-ticket" href="' . esc_url( BZ_SUPPORT_FORM ) . '" target="_blank" rel="noopener" data-bzl-ticket>Create a ticket</a></nav></div>';
	}
);

/* CSS for the support dialog (login page and the Marketing assistant). */
function bz_core_support_dialog_css() {
	return '.bzl-dialog{margin:auto;inset:0;border:0;border-radius:12px;padding:0;width:min(720px,calc(100% - 32px));max-height:calc(100vh - 48px);box-shadow:0 20px 60px rgba(0,0,0,.3)}'
		. '.bzl-dialog::backdrop{background:rgba(20,24,40,.55)}'
		. '.bzl-dialog header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #dcdcde;font-weight:600;color:#1d2327}'
		. '.bzl-dialog header button{border:0;background:none;font-size:24px;line-height:1;cursor:pointer;color:#50575e;padding:4px 8px}'
		. '.bzl-body{padding:28px 0 0;background:#F7FBFB;overflow:auto;max-height:calc(100vh - 110px);box-sizing:border-box}'
		. '#bizink-support{padding:0 28px 28px}'
		. '.bzl-dialog iframe{display:block;width:100%;height:640px;max-height:calc(100vh - 134px);border:0}'
		. '.bzl-dialog *{box-sizing:border-box}';
}

/**
 * What the support form is told about this site, so the customer doesn't have to type it. Sent as query
 * parameters to the iframe (BZ_SUPPORT_FORM), or as data- attributes and window.BizinkSupport to a script embed
 * (BZ_SUPPORT_EMBED_JS, when defined).
 */
function bz_core_support_context( $source, $user = null ) {
	$ctx = array(
		'site_domain'         => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		'site_url'            => home_url( '/' ),
		'firm'                => bz_core_get( 'firm_name' ),
		'source'              => $source,
		'wp_version'          => get_bloginfo( 'version' ),
		'bizink_core_version' => BZ_CORE_VERSION,
	);
	if ( $user instanceof WP_User && $user->exists() ) {
		$ctx['contact_name']  = trim( $user->first_name . ' ' . $user->last_name ) ? trim( $user->first_name . ' ' . $user->last_name ) : $user->display_name;
		$ctx['contact_email'] = $user->user_email;
	}
	return $ctx;
}

/**
 * The support dialog markup and script. Any element with data-bzl-ticket on the page opens it; the form (script
 * embed, or the iframe when BZ_SUPPORT_EMBED_JS is '') loads on the first click.
 */
function bz_core_support_dialog( $ctx ) {
	$out = '<dialog class="bzl-dialog" id="bzl-dialog" aria-labelledby="bzl-dialog-title"><header><span id="bzl-dialog-title">Contact Bizink support</span>'
		. '<button type="button" aria-label="Close" data-bzl-close>&times;</button></header><div class="bzl-body">';
	if ( bz_core_support_script() ) {
		$ctx   = array( 'endpoint' => BZ_SUPPORT_ENDPOINT ) + $ctx;
		$attrs = '';
		foreach ( $ctx as $k => $v ) {
			$attrs .= ' data-' . str_replace( '_', '-', $k ) . '="' . esc_attr( $v ) . '"';
		}
		$out  .= '<div id="bizink-support"' . $attrs . ' data-src="' . esc_url( bz_core_support_script() ) . '"></div>';
		$load  = 'var t=document.getElementById("bizink-support");if(!t.getAttribute("data-loaded")){t.setAttribute("data-loaded","1");window.BizinkSupport=' . wp_json_encode( $ctx ) . ';var sc=document.createElement("script");sc.src=t.getAttribute("data-src");sc.async=true;document.body.appendChild(sc);}';
	} else {
		$out  .= '<iframe id="bizink-support-form" title="Contact support" data-src="' . esc_url( add_query_arg( array_map( 'rawurlencode', array( 'embed' => '1' ) + $ctx ), BZ_SUPPORT_FORM ) ) . '"></iframe>';
		$load  = 'var f=document.getElementById("bizink-support-form");if(!f.src)f.src=f.getAttribute("data-src");';
	}
	$out .= '</div></dialog>';
	$out .= '<script>(function(){var d=document.getElementById("bzl-dialog");if(!d||!d.showModal)return;'
		. 'document.querySelectorAll("[data-bzl-ticket]").forEach(function(b){b.addEventListener("click",function(e){e.preventDefault();' . $load . 'd.showModal();});});'
		. 'd.querySelector("[data-bzl-close]").addEventListener("click",function(){d.close();});'
		. 'd.addEventListener("click",function(e){if(e.target===d)d.close();});})();</script>';
	return $out;
}

// The support form dialog on the login page.
add_action(
	'login_footer',
	function () {
		echo bz_core_support_dialog( bz_core_support_context( 'login' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	}
);

add_filter(
	'login_headerurl',
	function () {
		return home_url( '/' );
	}
);

add_filter(
	'login_headertext',
	function () {
		return bz_core_get( 'firm_name' );
	}
);
