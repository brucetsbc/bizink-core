<?php
/**
 * Booking pop-up (Client Details → Booking link).
 *
 * When the booking link is a Calendly page, any link to #book (or to the booking link itself) opens the scheduler in a
 * pop-up on the same page instead of leaving the site. The pop-up's heading is the link's own text after "#book=",
 * e.g. #book=Book%20a%20tax%20planning%20appointment, so each page can say what the booking is about; a plain #book
 * or a link to the booking link uses the first #book= heading on the page, else "Book an appointment". Calendly's script loads on the first click only. With any other kind of booking link
 * (or none: the Book a Call page), #book links simply go there.
 *
 * The pop-up is in every page's HTML (cache-safe) and costs nothing until it's opened.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* The Calendly page address to embed, or '' when the booking link isn't a Calendly page. */
function bz_core_booking_embed_url() {
	$url  = function_exists( 'bz_core_get' ) ? trim( (string) bz_core_get( 'booking_url' ) ) : '';
	$host = $url ? strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) : '';
	return ( 'calendly.com' === $host || 'www.calendly.com' === $host ) ? $url : '';
}

add_action(
	'wp_footer',
	function () {
		if ( is_admin() ) {
			return;
		}
		$link  = function_exists( 'bz_core_get' ) ? trim( (string) bz_core_get( 'booking_url' ) ) : '';
		$embed = bz_core_booking_embed_url();
		if ( ! $link ) {
			return;
		}
		$cfg = array(
			'link'  => $link,
			'embed' => $embed ? add_query_arg( array( 'hide_gdpr_banner' => '1' ), $embed ) : '',
			'title' => 'Book an appointment',
		);
		if ( $embed ) {
			?>
<dialog class="bz-book" id="bz-book" aria-labelledby="bz-book-title">
	<div class="bz-book__head">
		<h2 class="bz-book__title" id="bz-book-title">Book an appointment</h2>
		<button type="button" class="bz-book__close" aria-label="Close" data-bz-book-close>&times;</button>
	</div>
	<div class="bz-book__body"><div class="bz-book__widget"></div></div>
</dialog>
<style>
.bz-book{width:min(1000px,calc(100% - 32px));max-width:none;height:min(860px,calc(100% - 32px));max-height:none;padding:0;border:0;border-radius:var(--radius-card,12px);background:var(--color-surface,#fff);color:var(--color-text,#222);box-shadow:0 24px 60px rgba(0,0,0,.3);overflow:hidden}
.bz-book[open]{display:flex;flex-direction:column}
.bz-book::backdrop{background:rgba(10,15,25,.6)}
.bz-book__head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 24px;border-bottom:1px solid var(--color-border,#e5e5e5)}
.bz-book__title{margin:0;font-size:clamp(1.25rem,2.4vw,1.6rem);line-height:1.25;color:var(--color-heading,inherit)}
.bz-book__close{flex:none;width:40px;height:40px;border:0;border-radius:50%;background:var(--color-surface-alt,#f3f3f3);color:var(--color-heading,#222);font-size:26px;line-height:1;cursor:pointer}
.bz-book__close:hover{filter:brightness(.95)}
.bz-book__body{flex:1;min-height:0;overflow:auto;-webkit-overflow-scrolling:touch}
.bz-book__widget{height:100%;min-height:660px}
.bz-book__widget iframe{display:block}
@media (max-width:767px){.bz-book{width:100%;height:100%;border-radius:0}.bz-book__head{padding:14px 16px}}
</style>
			<?php
		}
		?>
<script>
(function () {
	var cfg = <?php echo wp_json_encode( $cfg ); ?>;
	var dlg = document.getElementById('bz-book');
	var loaded = false;
	function sameLink(href) {
		return href && href.replace(/\/+$/, '') === cfg.link.replace(/\/+$/, '');
	}
	function open(title) {
		dlg.querySelector('.bz-book__title').textContent = title || cfg.title;
		if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
		if (loaded) { return; }
		loaded = true;
		var start = function () {
			window.Calendly.initInlineWidget({ url: cfg.embed, parentElement: dlg.querySelector('.bz-book__widget') });
		};
		if (window.Calendly) { start(); return; }
		var s = document.createElement('script');
		s.src = 'https://assets.calendly.com/assets/external/widget.js';
		s.async = true;
		s.onload = start;
		document.head.appendChild(s);
	}
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a[href]');
		if (!a) { return; }
		var href = a.getAttribute('href');
		var m = /^#book(?:=(.*))?$/.exec(href);
		if (!m && !(dlg && sameLink(href))) { return; }
		e.preventDefault();
		if (!dlg) { window.location.href = cfg.link; return; }
		var first = document.querySelector('a[href^="#book="]');
		var named = m && m[1] ? m : /^#book=(.+)$/.exec(first ? first.getAttribute('href') : '');
		open(named ? decodeURIComponent(named[1].replace(/\+/g, ' ')) : '');
	});
	if (dlg) {
		dlg.addEventListener('click', function (e) {
			if (e.target === dlg || e.target.closest('[data-bz-book-close]')) { dlg.close ? dlg.close() : dlg.removeAttribute('open'); }
		});
	}
}());
</script>
		<?php
	},
	30
);
