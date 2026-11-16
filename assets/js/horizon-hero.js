(function () {
	document.documentElement.classList.add('bz-js');

	function tag(children, name) {
		return Array.prototype.filter.call(children, function (el) { return el.tagName === name; });
	}

	function setup(section) {
		var aside = section.children[0];
		if (!aside || aside.tagName !== 'ASIDE') return;
		var slides = tag(aside.children, 'IMG');
		if (slides.length < 2) return;
		var inner = section.lastElementChild;
		var nav = inner && inner.lastElementChild;
		if (!nav || nav.tagName !== 'NAV') return;
		var dots = tag(nav.children, 'BUTTON');
		if (dots.length !== slides.length) return;
		var textHost = inner.firstElementChild;
		var texts = textHost ? tag(textHost.children, 'ARTICLE') : [];

		slides.forEach(function (img) { img.style.animation = 'none'; });
		texts.forEach(function (el) { el.style.animation = 'none'; });

		var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var current = 0;
		var timer = null;

		function show(i) {
			current = (i + slides.length) % slides.length;
			slides.forEach(function (img, idx) { img.style.opacity = idx === current ? '1' : '0'; });
			texts.forEach(function (el, idx) {
				el.style.opacity = idx === current ? '1' : '0';
				el.style.visibility = idx === current ? 'visible' : 'hidden';
			});
			dots.forEach(function (d, idx) { d.classList.toggle('is-active', idx === current); });
		}

		function restart() {
			if (timer) clearInterval(timer);
			if (!reduced) timer = setInterval(function () { show(current + 1); }, 6000);
		}

		dots.forEach(function (dot, idx) {
			dot.type = 'button';
			dot.addEventListener('click', function () { show(idx); restart(); });
		});

		show(0);
		restart();
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('section'), function (section) {
			var aside = section.children[0];
			if (aside && aside.tagName === 'ASIDE') setup(section);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
