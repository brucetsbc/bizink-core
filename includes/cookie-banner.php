<?php
/**
 * Cookie banner (Client Details → Cookie banner).
 *
 *   Off      no banner (default).
 *   notice   a short notice with an OK button; analytics runs as usual (common in AU, NZ, US, CA).
 *   consent  Accept / Reject with equal weight; the GA4 tag (marketing.php) waits until the visitor accepts
 *            (UK and Ireland: PECR / GDPR need consent before analytics cookies).
 *
 * The banner is in every page's HTML and shown by JavaScript only when there is no saved choice, so it works with
 * page caching (Varnish). The choice is a first-party cookie, bz_consent, for 180 days. Any link to #cookie-settings
 * (or the [bz_cookie_settings] shortcode) opens the banner again. Scripts that must wait for consent are printed as
 * <script type="text/plain" data-bz-consent="analytics"> and switched on after Accept.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bz_core_cookie_modes() {
	return array(
		''        => 'Off',
		'notice'  => 'Notice: tell visitors cookies are used (OK button)',
		'consent' => 'Ask for consent: Accept / Reject, analytics only after Accept (UK, Ireland)',
	);
}

function bz_core_cookie_mode() {
	$opts = get_option( BZ_CORE_OPTION, array() );
	$mode = isset( $opts['cookie_banner'] ) ? (string) $opts['cookie_banner'] : '';
	return array_key_exists( $mode, bz_core_cookie_modes() ) ? $mode : '';
}

/* True when analytics must wait for Accept (marketing.php prints the GA4 tag as text/plain then). */
function bz_core_cookie_needs_consent() {
	return 'consent' === bz_core_cookie_mode();
}

/* Wrap a <script> tag so it only runs after consent (when consent is needed); unchanged otherwise. */
function bz_core_cookie_gate( $html ) {
	if ( ! bz_core_cookie_needs_consent() ) {
		return $html;
	}
	return preg_replace( '/<script(?![^>]*\btype=)/i', '<script type="text/plain" data-bz-consent="analytics"', $html );
}

add_shortcode(
	'bz_cookie_settings',
	function ( $atts ) {
		$atts = shortcode_atts( array( 'text' => 'Cookie settings' ), $atts );
		return bz_core_cookie_mode() ? '<a href="#cookie-settings" class="bz-cookie-settings">' . esc_html( $atts['text'] ) . '</a>' : '';
	}
);

add_action(
	'wp_footer',
	function () {
		$mode = bz_core_cookie_mode();
		if ( ! $mode || is_admin() ) {
			return;
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->preview ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			return; // not inside the Elementor editor
		}
		$opts    = get_option( BZ_CORE_OPTION, array() );
		$custom  = isset( $opts['cookie_text'] ) ? trim( (string) $opts['cookie_text'] ) : '';
		$default = 'consent' === $mode
			? 'We\'d like to use analytics cookies to see how people use this site, so we can improve it. They are only set if you accept.'
			: 'This site uses cookies to understand how it\'s used and to improve it.';
		$text    = '' !== $custom ? $custom : $default;
		$privacy = get_privacy_policy_url();
		if ( ! $privacy ) {
			$page    = get_page_by_path( 'privacy-policy' );
			$privacy = $page ? get_permalink( $page ) : '';
		}
		?>
<div id="bz-cookie" class="bz-cookie" role="dialog" aria-live="polite" aria-label="Cookies" data-mode="<?php echo esc_attr( $mode ); ?>" hidden>
	<p class="bz-cookie__text"><?php echo esc_html( $text ); ?><?php if ( $privacy ) : ?> <a href="<?php echo esc_url( $privacy ); ?>">Privacy policy</a><?php endif; ?></p>
	<div class="bz-cookie__btns">
		<?php if ( 'consent' === $mode ) : ?>
			<button type="button" class="bz-cookie__btn" data-bz-choice="rejected">Reject</button>
			<button type="button" class="bz-cookie__btn" data-bz-choice="accepted">Accept</button>
		<?php else : ?>
			<button type="button" class="bz-cookie__btn" data-bz-choice="ok">OK</button>
		<?php endif; ?>
	</div>
</div>
<style>
.bz-cookie{position:fixed;left:16px;right:16px;bottom:16px;z-index:99999;max-width:720px;margin:0 auto;display:flex;align-items:center;gap:16px 24px;flex-wrap:wrap;
	padding:16px 20px;background:var(--color-surface,#fff);color:var(--color-text,#333);border:1px solid var(--color-border,#e3e3e3);
	border-radius:var(--radius-card,12px);box-shadow:0 10px 30px rgba(0,0,0,.14);font-family:var(--font-body,inherit);font-size:15px;line-height:1.5}
.bz-cookie[hidden]{display:none}
.bz-cookie__text{margin:0;flex:1 1 300px}
.bz-cookie__text a{color:var(--color-primary,#1e3a5f);text-decoration:underline}
.bz-cookie__btns{display:flex;gap:10px;flex:0 0 auto}
.bz-cookie__btn{cursor:pointer;font:inherit;font-weight:700;padding:10px 20px;border-radius:var(--radius-button,6px);
	border:2px solid var(--color-primary,#1e3a5f);background:var(--color-primary,#1e3a5f);color:var(--color-inverse,#fff)}
.bz-cookie[data-mode="consent"] .bz-cookie__btn[data-bz-choice="rejected"]{background:transparent;color:var(--color-primary,#1e3a5f)}
.bz-cookie__btn:focus-visible{outline:3px solid var(--color-accent,#e0a526);outline-offset:2px}
@media (max-width:600px){.bz-cookie{left:10px;right:10px;bottom:10px}.bz-cookie__btns{width:100%}.bz-cookie__btn{flex:1}}
</style>
<script>
(function () {
	var box = document.getElementById('bz-cookie');
	if (!box) return;
	var mode = box.getAttribute('data-mode');
	function read() { var m = document.cookie.match(/(?:^|;\s*)bz_consent=([^;]+)/); return m ? m[1] : ''; }
	function save(v) { document.cookie = 'bz_consent=' + v + ';path=/;max-age=' + (180 * 86400) + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : ''); }
	function run() { // switch on scripts that waited for consent
		document.querySelectorAll('script[type="text/plain"][data-bz-consent]').forEach(function (old) {
			var s = document.createElement('script');
			for (var i = 0; i < old.attributes.length; i++) { var a = old.attributes[i]; if (a.name !== 'type' && a.name !== 'data-bz-consent') s.setAttribute(a.name, a.value); }
			s.text = old.text; old.parentNode.replaceChild(s, old);
		});
	}
	function clearAnalytics() { // remove Google Analytics cookies on this domain and its parents
		var host = location.hostname.split('.'), domains = [''];
		for (var i = 0; i < host.length - 1; i++) domains.push(';domain=.' + host.slice(i).join('.'));
		document.cookie.split(';').forEach(function (c) {
			var name = c.split('=')[0].trim();
			if (/^_ga|^_gid|^_gat/.test(name)) domains.forEach(function (d) { document.cookie = name + '=;path=/;max-age=0' + d; });
		});
	}
	function show() { box.hidden = false; }
	var saved = read();
	if (mode === 'consent' && saved === 'accepted') run();
	if (!saved || (mode === 'consent' && saved === 'ok')) show();
	box.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-bz-choice]');
		if (!btn) return;
		var choice = btn.getAttribute('data-bz-choice'), before = read();
		save(choice);
		box.hidden = true;
		if (choice === 'accepted') run();
		if (choice === 'rejected') { clearAnalytics(); if (before === 'accepted') location.reload(); }
	});
	document.addEventListener('click', function (e) {
		var a = e.target.closest('a[href$="#cookie-settings"], .bz-cookie-settings');
		if (!a) return;
		e.preventDefault();
		show();
		var first = box.querySelector('button');
		if (first) first.focus();
	});
})();
</script>
		<?php
	},
	50
);
