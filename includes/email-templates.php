<?php
/**
 * Marketing emails to copy (Marketing assistant page; package feature "email_templates").
 *
 * Ready-made emails (data/email-templates.json) with the firm's details and regional wording filled in, plus "Promote a
 * resource": pick a download or calculator and an audience to get an email pointing to it. Fixed wording, nothing is
 * sent or stored: the firm edits the email here, copies it and pastes it into whichever email platform it uses.
 * In the wording, {tokens} are filled in for them and [square brackets] are left for them to complete.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bz_core_em_enabled() {
	return ( ! function_exists( 'bz_core_mkt_enabled' ) || bz_core_mkt_enabled() ) && ( ! function_exists( 'bz_core_feature' ) || bz_core_feature( 'email_templates' ) );
}

/* The wording file. One-to-one approaches to strangers are hidden on Canadian sites (CASL). */
function bz_core_em_wording() {
	$raw  = file_get_contents( dirname( __DIR__ ) . '/data/email-templates.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$data = $raw ? json_decode( $raw, true ) : null;
	if ( ! is_array( $data ) ) {
		return array( 'groups' => array(), 'templates' => array(), 'promos' => array() );
	}
	$region            = bz_core_get( 'region' );
	$data['templates'] = array_values(
		array_filter(
			$data['templates'],
			function ( $t ) use ( $region ) {
				return empty( $t['cold'] ) || 'CA' !== $region;
			}
		)
	);
	return $data;
}

/* The next key date worth a reminder, e.g. "Q2 BAS (due 28 February)"; '' when there is none. */
function bz_core_em_next_deadline() {
	if ( function_exists( 'bz_core_feature' ) && ! bz_core_feature( 'key_dates' ) ) {
		return '';
	}
	$today = bz_core_tax_dates_today_ts();
	$next  = null;
	foreach ( bz_core_mkt_context()['tax_dates'] as $row ) {
		$ts = strtotime( $row['date'] . ' 00:00:00 UTC' );
		if ( $ts >= $today + 7 * DAY_IN_SECONDS && ! preg_match( '/^\s*monthly\b/i', $row['desc'] ) && ( ! $next || $ts < $next[0] ) ) {
			$next = array( $ts, $row );
		}
	}
	if ( ! $next ) {
		return '';
	}
	$what = trim( preg_split( '/\s+[—–-]\s+/u', $next[1]['desc'] )[0] );
	$what = preg_split( '/\.\s+/', $what )[0]; // First sentence only: it also goes in the subject line.
	$what = rtrim( preg_replace( '/\s+due\b.*$/i', '', $what ), '. ' );
	return $what . ' (due ' . gmdate( 'j F', $next[0] ) . ')';
}

/* The person's name for the sign-off; never a login or email address. */
function bz_core_em_sender() {
	$user = wp_get_current_user();
	$name = trim( $user->first_name . ' ' . $user->last_name );
	if ( '' === $name ) {
		$name = trim( (string) $user->display_name );
	}
	return ( '' === $name || false !== strpos( $name, '@' ) || $name === $user->user_login ) ? '[Your name]' : $name;
}

/* A resource's one-line summary, without the search-result style "Free checklist:" opening. */
function bz_core_em_about( $post ) {
	$text = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '' );
	$text = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) );
	$text = preg_replace( '/^free\b[^:.]{0,60}:\s*/i', '', $text );
	return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 ) : ucfirst( $text );
}

/* Downloads and calculators this site can promote. */
function bz_core_em_resources() {
	$out      = array( 'download' => array(), 'calculator' => array() );
	$features = function_exists( 'bz_core_feature' );
	$pages    = array();
	if ( function_exists( 'bz_core_lm_enabled' ) && bz_core_lm_enabled() ) {
		foreach ( bz_core_lm_list() as $lm ) {
			if ( 'publish' === get_post_status( (int) $lm['page_id'] ) ) {
				$pages[ (int) $lm['resource_id'] ] = get_permalink( (int) $lm['page_id'] );
			}
		}
	}
	if ( ! $features || bz_core_feature( 'templates' ) ) {
		foreach ( get_posts( array( 'post_type' => 'bz_resource', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
			$title = html_entity_decode( get_the_title( $p ), ENT_QUOTES );
			$kind  = 'resource';
			foreach ( array( 'checklist', 'template', 'forecast' ) as $word ) {
				if ( false !== stripos( $title, $word ) ) {
					$kind = $word;
				}
			}
			$out['download'][] = array(
				'title'    => $title,
				'about'    => bz_core_em_about( $p ),
				'kind'     => $kind,
				'url'      => get_permalink( $p ),
				'lead_url' => isset( $pages[ $p->ID ] ) ? $pages[ $p->ID ] : '',
				'can_lead' => (bool) ( get_post_meta( $p->ID, 'bz_pdf_url', true ) && function_exists( 'bz_core_lm_enabled' ) && bz_core_lm_enabled() ),
			);
		}
	}
	if ( ! $features || bz_core_feature( 'calculators' ) ) {
		$hidden = function_exists( 'bz_core_calc_other_region_ids' ) ? bz_core_calc_other_region_ids() : array();
		foreach ( get_posts( array( 'post_type' => 'bz_calculator', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post__not_in' => $hidden ?: array( 0 ) ) ) as $p ) {
			$out['calculator'][] = array(
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
				'about' => bz_core_em_about( $p ),
				'kind'  => 'calculator',
				'url'   => get_permalink( $p ),
			);
		}
	}
	return $out;
}

/* Everything the panel's script needs. Empty details stay as [placeholders] for the firm to complete. */
function bz_core_em_data() {
	$value = function ( $v, $label ) {
		$v = trim( (string) $v );
		return '' !== $v ? $v : '[' . $label . ']';
	};
	$firm   = bz_core_get( 'firm_name' );
	$phone  = bz_core_get( 'phone' );
	$sender = bz_core_em_sender();
	$vars   = array(
		'firm'          => $value( $firm, 'Firm name' ),
		'phone'         => $value( $phone, 'phone number' ),
		'email'         => $value( bz_core_get( 'email' ), 'email address' ),
		'website'       => home_url( '/' ),
		'booking_url'   => $value( bz_core_get( 'booking_url' ), 'booking link' ),
		'google_review' => $value( bz_core_get( 'google_review_url' ), 'Google review link' ),
		'facebook'      => $value( bz_core_get( 'social_facebook' ), 'Facebook page link' ),
		'year_end'      => (string) bz_core_term( 'year_end' ),
		'signoff'       => implode( "\n", array_filter( array( $sender, $firm, $phone ) ) ),
		'deadline'      => $value( bz_core_em_next_deadline(), 'date and what is due' ),
		'topic'         => '[Topic]',
		'service'       => '[service name]',
		'service_link'  => '[link to the service page]',
	);
	$wording = bz_core_em_wording();
	return array_merge( $wording, array( 'vars' => $vars, 'resources' => bz_core_em_resources() ) );
}

function bz_core_em_css() {
	return '
	.bzem{margin-top:22px;padding-bottom:22px;scroll-margin-top:48px}
	.bzem-tabs{display:flex;gap:6px;margin:16px 0 14px;border-bottom:1px solid var(--line)}
	.bzem-tab{background:none;border:0;border-bottom:3px solid transparent;padding:10px 12px;margin-bottom:-1px;font:600 13.5px/1 inherit;font-family:inherit;color:var(--mut);cursor:pointer}
	.bzem-tab[aria-selected=true]{color:var(--p);border-bottom-color:var(--p)}
	.bzem-grid{display:grid;grid-template-columns:270px minmax(0,1fr);gap:20px;align-items:start}
	.bzem-side{border:1px solid var(--line);border-radius:var(--r);padding:12px;background:#fbfbfd;max-height:760px;overflow:auto}
	.bzem-group{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--p);margin:14px 6px 6px}
	.bzem-group:first-child{margin-top:2px}
	.bzem-pick{display:block;width:100%;text-align:left;background:none;border:0;border-radius:8px;padding:8px;font:500 13px/1.35 inherit;font-family:inherit;color:var(--txt);cursor:pointer}
	.bzem-pick:hover{background:color-mix(in srgb,var(--p) 7%,#fff)}
	.bzem-pick[aria-current=true]{background:var(--p);color:#fff}
	.bzem-field{display:grid;gap:5px;margin:0 0 14px;font-size:13px}
	.bzem-field>label,.bzem-field>span,.bzem-side label{font-weight:600;color:var(--ink);font-size:13px}
	.bzem-side select{width:100%;max-width:none;min-height:38px;border:1px solid var(--line);border-radius:8px;margin:5px 0 14px}
	.bzem-side fieldset{border:0;padding:0;margin:0}.bzem-side legend{font-weight:600;color:var(--ink);font-size:13px;margin-bottom:6px}
	.bzem-side fieldset label{display:block;font-weight:400;margin:0 0 7px;color:var(--txt)}
	.bzem-row{display:flex;gap:8px;align-items:center}
	.bzem-row input{flex:1;min-width:0;min-height:38px;border:1px solid var(--line);border-radius:8px;padding:0 10px}
	.bzem-copy-mini{flex:none}
	.bzem-ideas{display:flex;gap:6px;flex-wrap:wrap}
	.bzem-idea{background:#f1f2f6;border:0;border-radius:999px;padding:5px 10px;font:500 12px/1.3 inherit;font-family:inherit;color:var(--txt);cursor:pointer;text-align:left}
	.bzem-idea:hover{background:color-mix(in srgb,var(--p) 12%,#fff)}
	.bzem-note{background:#fff8e6;border-radius:10px;padding:10px 14px;font-size:13px;line-height:1.5;margin:0 0 14px;color:#5e4700}
	.bzem-note a{color:inherit;font-weight:600}
	.bzem-pill{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;background:#fdecec;color:#a8251c;margin-right:6px}
	.bzem-body{border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;background:#fff;font-size:14px;line-height:1.6;color:var(--ink);min-height:220px}
	.bzem-body:focus{outline:2px solid color-mix(in srgb,var(--p) 40%,transparent);outline-offset:1px}
	.bzem-body p{margin:0 0 12px;font-size:14px}.bzem-body ul{list-style:disc;margin:0 0 12px 22px}.bzem-body li{margin:0 0 4px}
	.bzem-body mark{background:#fff0b8;color:inherit;border-radius:3px;padding:0 2px;cursor:pointer}
	.bzem-hint{font-size:12.5px;color:var(--mut);margin:6px 0 14px}
	.bzem-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
	.bzem-status{font-size:13px;color:#11743a;font-weight:600}.bzem-status.warn{color:#8a5a00}
	.bzem-empty{padding:12px 6px;color:var(--mut);font-size:13px}
	@media (max-width:900px){.bzem-grid{grid-template-columns:1fr}.bzem-side{max-height:300px}}
	';
}

/* The panel on the Marketing assistant page. */
function bz_core_em_panel() {
	if ( ! bz_core_em_enabled() ) {
		return '';
	}
	$json = wp_json_encode( bz_core_em_data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
	return '<style>' . bz_core_em_css() . '</style>'
		. '<div class="bzm-panel bzem" id="bzm-emails"><div class="bzm-head"><div><h2>Emails to copy</h2><p class="bzm-sub">'
		. esc_html( 'Choose an email, complete the highlighted parts, then copy it into whichever email platform you use. Nothing is sent or saved here.' )
		. '</p></div></div>'
		. '<div class="bzem-tabs" role="tablist"><button type="button" class="bzem-tab" role="tab" data-tab="templates" aria-selected="true">Ready-made emails</button>'
		. '<button type="button" class="bzem-tab" role="tab" data-tab="promote" aria-selected="false">Promote a resource</button></div>'
		. '<div class="bzem-grid"><div class="bzem-side" data-side></div><div>'
		. '<div class="bzem-note" data-note hidden></div>'
		. '<div class="bzem-field"><label for="bzem-subject">Subject</label><div class="bzem-row"><input id="bzem-subject" type="text"><button type="button" class="bzm-btn ghost bzem-copy-mini" data-copy="subject">Copy</button></div><div class="bzem-ideas" data-ideas></div></div>'
		. '<div class="bzem-field"><label for="bzem-preview">Preview line</label><div class="bzem-row"><input id="bzem-preview" type="text"><button type="button" class="bzm-btn ghost bzem-copy-mini" data-copy="preview">Copy</button></div></div>'
		. '<div class="bzem-field"><span id="bzem-body-label">Email</span><div class="bzem-body" data-body contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="bzem-body-label"></div></div>'
		. '<p class="bzem-hint">Click a highlighted part to type over it. Replace [First name] with your email platform\'s first-name field, or with the person\'s name. Choosing another email discards your changes.</p>'
		. '<div class="bzem-actions"><button type="button" class="bzm-btn primary" data-copy="html">Copy email</button><button type="button" class="bzm-btn ghost" data-copy="text">Copy as plain text</button><span class="bzem-status" data-status role="status"></span></div>'
		. '</div></div></div>'
		. '<script type="application/json" id="bzem-data">' . $json . '</script>'
		. '<script>' . bz_core_em_js() . '</script>';
}

function bz_core_em_js() {
	return <<<'JS'
(function () {
	var root = document.getElementById('bzm-emails');
	if (!root) { return; }
	var D = JSON.parse(document.getElementById('bzem-data').textContent);
	var q = function (s) { return root.querySelector(s); };
	var side = q('[data-side]'), body = q('[data-body]'), note = q('[data-note]'), ideas = q('[data-ideas]'), status = q('[data-status]');
	var subject = q('#bzem-subject'), preview = q('#bzem-preview');
	var hasPromos = D.resources.download.length + D.resources.calculator.length > 0;
	var tab = 'templates', currentId = '', promo = { type: D.resources.download.length ? 'download' : 'calculator', index: 0, audience: 'clients' };

	if (!hasPromos) { q('[data-tab=promote]').hidden = true; }

	function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
	function fill(s, extra) {
		return String(s).replace(/\{([a-z_]+)\}/g, function (m, k) {
			if (extra && extra[k] !== undefined) { return extra[k]; }
			return D.vars[k] !== undefined ? D.vars[k] : m;
		});
	}
	function inline(s) {
		return esc(s)
			.replace(/\[[^\]\n]+\]/g, '<mark>$&</mark>')
			.replace(/https?:\/\/[^\s<]+/g, function (u) {
				var end = u.match(/[.,;:!?)]+$/), tail = end ? end[0] : '', url = tail ? u.slice(0, -tail.length) : u;
				return '<a href="' + url + '">' + url + '</a>' + tail;
			});
	}
	function toHtml(text) {
		return text.split(/\n\s*\n/).map(function (block) {
			var lines = block.split('\n');
			if (lines.every(function (l) { return l.indexOf('- ') === 0; })) {
				return '<ul>' + lines.map(function (l) { return '<li>' + inline(l.slice(2)) + '</li>'; }).join('') + '</ul>';
			}
			return '<p>' + lines.map(inline).join('<br>') + '</p>';
		}).join('');
	}
	function setNote(html) { note.innerHTML = html; note.hidden = !html; }
	function show(subjects, previewText, bodyText, extra) {
		var list = subjects.map(function (s) { return fill(s, extra); });
		subject.value = list[0] || '';
		preview.value = fill(previewText || '', extra);
		ideas.innerHTML = '';
		list.slice(1).forEach(function (s) {
			var b = document.createElement('button');
			b.type = 'button'; b.className = 'bzem-idea'; b.textContent = s;
			b.addEventListener('click', function () { subject.value = s; });
			ideas.appendChild(b);
		});
		body.innerHTML = toHtml(fill(bodyText, extra));
		status.textContent = '';
	}

	function openTemplate(id, extra, extraNote) {
		var t = D.templates.filter(function (x) { return x.id === id; })[0];
		if (!t) { return; }
		currentId = id;
		show(t.subjects, t.preview, t.body, extra);
		setNote((t.cold ? '<span class="bzem-pill">One-to-one only</span>' : '') + esc(t.when) + (extraNote ? '<br><strong>What to cover:</strong> ' + esc(extraNote) : ''));
		side.querySelectorAll('.bzem-pick').forEach(function (b) { b.setAttribute('aria-current', b.dataset.id === id ? 'true' : 'false'); });
	}
	function drawTemplates() {
		var h = '';
		Object.keys(D.groups).forEach(function (g) {
			var items = D.templates.filter(function (t) { return t.group === g; });
			if (!items.length) { return; }
			h += '<div class="bzem-group">' + esc(D.groups[g]) + '</div>';
			items.forEach(function (t) { h += '<button type="button" class="bzem-pick" data-id="' + esc(t.id) + '">' + esc(t.title) + '</button>'; });
		});
		side.innerHTML = h;
		side.querySelectorAll('.bzem-pick').forEach(function (b) { b.addEventListener('click', function () { openTemplate(b.dataset.id); }); });
	}

	function openPromo() {
		var item = D.resources[promo.type][promo.index];
		if (!item) { return; }
		var p = D.promos[promo.type][promo.audience], link = item.url, msg = '';
		if (promo.type === 'download' && promo.audience === 'prospects') {
			if (item.lead_url) {
				link = item.lead_url;
				msg = 'This email links to your lead magnet page, so you collect each person\'s details when they download it.';
			} else if (item.can_lead) {
				msg = 'Tip: <a href="#bzm-lead-magnets">create a lead magnet</a> for this download first, and this email will link to it, so you collect each person\'s details. Then come back and choose it again.';
			}
		}
		if (promo.audience === 'prospects' && !msg) { msg = 'Only send to people who have agreed to hear from you, such as your mailing list.'; }
		else if (promo.audience === 'prospects') { msg += ' Only send to people who have agreed to hear from you, such as your mailing list.'; }
		show(p.subjects, p.preview, p.body, { title: item.title, about: item.about, kind: item.kind, link: link });
		setNote(msg);
	}
	function drawPromote() {
		var h = '';
		if (D.resources.download.length && D.resources.calculator.length) {
			h += '<label for="bzem-type">What to promote</label><select id="bzem-type"><option value="download">A download</option><option value="calculator">A calculator</option></select>';
		}
		h += '<label for="bzem-item">Choose one</label><select id="bzem-item"></select>';
		h += '<fieldset><legend>Who is it for?</legend><label><input type="radio" name="bzem-aud" value="clients"> Existing clients</label><label><input type="radio" name="bzem-aud" value="prospects"> Prospects and your mailing list</label></fieldset>';
		side.innerHTML = h;
		var type = side.querySelector('#bzem-type'), sel = side.querySelector('#bzem-item');
		function items() {
			sel.innerHTML = D.resources[promo.type].map(function (r, i) { return '<option value="' + i + '">' + esc(r.title) + '</option>'; }).join('');
			sel.value = promo.index;
		}
		if (type) {
			type.value = promo.type;
			type.addEventListener('change', function () { promo.type = type.value; promo.index = 0; items(); openPromo(); });
		}
		sel.addEventListener('change', function () { promo.index = +sel.value; openPromo(); });
		side.querySelectorAll('[name=bzem-aud]').forEach(function (r) {
			r.checked = r.value === promo.audience;
			r.addEventListener('change', function () { promo.audience = r.value; openPromo(); });
		});
		items();
		openPromo();
	}
	function setTab(name, skipOpen) {
		tab = name;
		root.querySelectorAll('.bzem-tab').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === name ? 'true' : 'false'); });
		if (name === 'promote') { drawPromote(); return; }
		drawTemplates();
		if (!skipOpen && D.templates.length) { openTemplate(currentId || D.templates[0].id); }
	}
	root.querySelectorAll('.bzem-tab').forEach(function (b) { b.addEventListener('click', function () { setTab(b.dataset.tab); }); });

	body.addEventListener('click', function (e) {
		if (e.target.tagName === 'MARK') {
			var r = document.createRange(); r.selectNodeContents(e.target);
			var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
		} else if (e.target.tagName === 'A') { e.preventDefault(); }
	});

	function asPlain(node) {
		var out = [];
		node.childNodes.forEach(function (c) {
			if (c.nodeType === 3) { if (c.textContent.trim()) { out.push(c.textContent.trim()); } return; }
			if (c.tagName === 'UL' || c.tagName === 'OL') {
				out.push(Array.prototype.map.call(c.children, function (li) { return '- ' + li.innerText.trim(); }).join('\n'));
			} else {
				var t = c.innerText.replace(/\n{2,}/g, '\n').trim();
				if (t) { out.push(t); }
			}
		});
		return out.join('\n\n');
	}
	function cleanHtml() {
		var copy = body.cloneNode(true);
		copy.querySelectorAll('mark').forEach(function (m) { m.replaceWith(document.createTextNode(m.textContent)); });
		return copy.innerHTML;
	}
	function report() {
		var seen = {}, found = (asPlain(body) + ' ' + subject.value).match(/\[[^\]\n]+\]/g) || [];
		found.forEach(function (f) { if (f !== '[First name]') { seen[f] = 1; } });
		var left = Object.keys(seen);
		status.className = 'bzem-status' + (left.length ? ' warn' : '');
		status.textContent = 'Copied.' + (left.length ? ' Still to complete: ' + left.join(', ') : '');
	}
	function fallbackHtml(html) {
		var d = document.createElement('div');
		d.contentEditable = 'true'; d.style.position = 'fixed'; d.style.left = '-9999px'; d.innerHTML = html;
		document.body.appendChild(d);
		var r = document.createRange(); r.selectNodeContents(d);
		var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
		var ok = document.execCommand('copy');
		s.removeAllRanges(); d.remove();
		return ok;
	}
	function fallbackText(text) {
		var t = document.createElement('textarea');
		t.value = text; t.style.position = 'fixed'; t.style.left = '-9999px';
		document.body.appendChild(t); t.select();
		var ok = document.execCommand('copy');
		t.remove();
		return ok;
	}
	function failed() { status.className = 'bzem-status warn'; status.textContent = 'Your browser blocked copying. Select the text and copy it by hand.'; }
	function copyHtml() {
		var html = cleanHtml(), text = asPlain(body);
		if (navigator.clipboard && window.ClipboardItem) {
			navigator.clipboard.write([new ClipboardItem({
				'text/html': new Blob([html], { type: 'text/html' }),
				'text/plain': new Blob([text], { type: 'text/plain' })
			})]).then(report, function () { if (fallbackHtml(html)) { report(); } else { failed(); } });
		} else if (fallbackHtml(html)) { report(); } else { failed(); }
	}
	function copyText(text, after) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(after, function () { if (fallbackText(text)) { after(); } else { failed(); } });
		} else if (fallbackText(text)) { after(); } else { failed(); }
	}
	function done(label) { return function () { status.className = 'bzem-status'; status.textContent = label + ' copied.'; }; }
	root.querySelectorAll('[data-copy]').forEach(function (b) {
		b.addEventListener('click', function () {
			var k = b.dataset.copy;
			if (k === 'html') { copyHtml(); }
			else if (k === 'text') { copyText(asPlain(body), report); }
			else if (k === 'subject') { copyText(subject.value, done('Subject')); }
			else { copyText(preview.value, done('Preview line')); }
		});
	});

	/* "Create email" on a 90-day plan item. */
	document.querySelectorAll('[data-bzem-plan]').forEach(function (b) {
		b.addEventListener('click', function () {
			var item = JSON.parse(b.getAttribute('data-bzem-plan'));
			var type = item.type === 'resource' ? 'download' : item.type, list = D.resources[type], found = -1;
			if (list && item.link) { list.forEach(function (r, i) { if (r.url === item.link) { found = i; } }); }
			if (found > -1) {
				promo = { type: type, index: found, audience: 'clients' };
				setTab('promote');
			} else {
				setTab('templates', true);
				if (item.type === 'deadline') { openTemplate('deadline', { deadline: item.topic }); }
				else if (item.type === 'service') { openTemplate('service', { service: item.topic, service_link: item.link || D.vars.website }); }
				else { openTemplate('topic', { topic: item.topic }, item.angle); }
			}
			root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		});
	});

	setTab('templates');
})();
JS;
}
