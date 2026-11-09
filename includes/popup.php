<?php
/**
 * Timed pop-ups from page sections.
 *
 * Give any section (or other element) the class "bz-popup" in Elementor and it leaves the page flow: a few seconds after
 * the page loads it opens as a pop-up instead (default 5 seconds; a class "bz-popup-delay-<seconds>" changes that).
 * Each pop-up shows at most once per browser session (sessionStorage, keyed by the element's id), so it doesn't come
 * back on the next page or a reload. Close with ×, Esc or a click outside. In the Elementor editor the section shows
 * in place, so it's edited like any other section. First used for Bouzios' Free Business Plan Template (2026-11-09).
 *
 * Costs nothing on pages without a bz-popup element (a small inline script that finds none and stops).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_footer',
	function () {
		if ( is_admin() ) {
			return;
		}
		?>
<style>
body:not(.elementor-editor-active) .bz-popup:not(.bz-popup--in){display:none!important}
.bz-pop{position:fixed;width:min(640px,calc(100% - 32px));max-width:none;max-height:calc(100% - 32px);padding:0;border:0;border-radius:var(--radius-card,12px);background:transparent;box-shadow:0 24px 60px rgba(0,0,0,.35);overflow:auto;overscroll-behavior:contain}
.bz-pop::backdrop{background:rgba(10,15,25,.6)}
.bz-pop .bz-popup{margin:0;width:100%;padding-block:clamp(40px,6vw,56px) clamp(28px,5vw,44px)}
.bz-pop .bz-container{max-width:none;padding-inline:clamp(20px,4vw,40px)}
dialog.bz-pop .bz-pop__close{position:absolute;top:12px;right:12px;z-index:3;display:flex;align-items:center;justify-content:center;width:40px;height:40px;min-height:0;margin:0;padding:0;border:0;border-radius:50%;background:rgba(255,255,255,.92);color:#222;font:400 26px/1 sans-serif;box-shadow:none;cursor:pointer}
dialog.bz-pop .bz-pop__close:hover,dialog.bz-pop .bz-pop__close:focus{background:#fff;color:#000}
dialog.bz-pop .bz-pop__close:focus-visible{outline:2px solid #fff;outline-offset:2px}
@media (max-width:767px){.bz-pop{width:calc(100% - 24px);max-height:calc(100% - 24px)}}
</style>
<script>
(function () {
	if (document.body.classList.contains('elementor-editor-active')) { return; }
	var els = document.querySelectorAll('.bz-popup');
	if (!els.length || !window.HTMLDialogElement) { return; }
	function seen(key) { try { return !!sessionStorage.getItem(key); } catch (e) { return false; } }
	function mark(key) { try { sessionStorage.setItem(key, '1'); } catch (e) {} }
	Array.prototype.forEach.call(els, function (el, i) {
		var key = 'bz-popup-' + (el.getAttribute('data-id') || el.id || i);
		if (seen(key)) { return; }
		var m = /(?:^|\s)bz-popup-delay-(\d+)(?:\s|$)/.exec(el.className);
		var delay = (m ? parseInt(m[1], 10) : 5) * 1000;
		function show() {
			if (seen(key)) { return; }
			if (document.querySelector('dialog[open]')) { setTimeout(show, 2000); return; } // e.g. the booking pop-up is open
			var d = document.createElement('dialog');
			d.className = 'bz-pop';
			var h = el.querySelector('h1,h2,h3');
			d.setAttribute('aria-label', h ? h.textContent.trim() : 'Pop-up');
			var x = document.createElement('button');
			x.type = 'button';
			x.className = 'bz-pop__close';
			x.setAttribute('aria-label', 'Close');
			x.innerHTML = '&times;';
			d.appendChild(x);
			el.classList.add('bz-popup--in');
			el.parentNode.insertBefore(d, el); // in place: Elementor's styles are scoped to the page wrapper
			d.appendChild(el);
			x.addEventListener('click', function () { d.close(); });
			d.addEventListener('click', function (e) { if (e.target === d) { d.close(); } });
			d.showModal();
			mark(key);
		}
		setTimeout(show, delay);
	});
}());
</script>
		<?php
	},
	31
);
