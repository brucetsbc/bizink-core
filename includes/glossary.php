<?php
/**
 * [bz_glossary] — the shared business and accounting glossary (data/glossary.json), localised to the site's Region:
 * region-only terms are filtered, local variants replace the term or definition, and {tokens} are filled from the
 * region pack and Client Details. Edit tools/build_glossary.py, regenerate the JSON and update the plugin.
 * Payroll terms (section "payroll") show under a Payroll tab; [bz_glossary section="payroll"] or section="business"
 * shows one section only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Glossary entries for the current region: [ ['term' => ..., 'def' => ...], ... ] sorted by term.
 */
function bz_core_glossary_terms() {
	static $terms = null;
	if ( null !== $terms ) {
		return $terms;
	}
	$terms = array();
	$file  = dirname( __DIR__ ) . '/data/glossary.json';
	$data  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	if ( empty( $data['terms'] ) ) {
		return $terms;
	}
	$region = bz_core_get( 'region' );
	foreach ( $data['terms'] as $entry ) {
		if ( ! empty( $entry['regions'] ) && ! in_array( $region, $entry['regions'], true ) ) {
			continue;
		}
		$local   = isset( $entry['variants'][ $region ] ) ? $entry['variants'][ $region ] : array();
		$term    = bz_core_fill_tokens( isset( $local['term'] ) ? $local['term'] : $entry['term'] );
		$terms[] = array(
			'section' => isset( $entry['section'] ) ? $entry['section'] : 'business',
			'term'    => $term,
			'def'     => bz_core_fill_tokens( isset( $local['def'] ) ? $local['def'] : $entry['def'] ),
		);
	}
	usort(
		$terms,
		function ( $a, $b ) {
			return strcasecmp( $a['term'], $b['term'] );
		}
	);
	return $terms;
}

add_shortcode(
	'bz_glossary',
	function ( $atts ) {
		$atts     = shortcode_atts( array( 'section' => '' ), $atts, 'bz_glossary' );
		$only     = in_array( $atts['section'], array( 'business', 'payroll' ), true ) ? $atts['section'] : '';
		$groups   = array();
		$sections = array();
		foreach ( bz_core_glossary_terms() as $entry ) {
			if ( $only && $entry['section'] !== $only ) {
				continue;
			}
			$sections[ $entry['section'] ] = true;
			$letter              = strtoupper( substr( remove_accents( $entry['term'] ), 0, 1 ) );
			$letter              = ctype_alpha( $letter ) ? $letter : '#';
			$groups[ $letter ][] = $entry;
		}
		if ( ! $groups ) {
			return '';
		}
		$nav  = '';
		$body = '';
		foreach ( $groups as $letter => $entries ) {
			$id    = 'glossary-' . strtolower( '#' === $letter ? 'other' : $letter );
			$nav  .= '<a href="#' . esc_attr( $id ) . '">' . esc_html( $letter ) . '</a>';
			$body .= '<span class="bz-glossary-group" id="' . esc_attr( $id ) . '"><span class="bz-glossary-letter" aria-hidden="true">' . esc_html( $letter ) . '</span><dl>';
			foreach ( $entries as $entry ) {
				$body .= '<span class="bz-glossary-item" data-section="' . esc_attr( $entry['section'] ) . '"><dt>' . esc_html( $entry['term'] )
					. ( 'payroll' === $entry['section'] && ! $only ? ' <span class="bz-glossary-tag">Payroll</span>' : '' ) . '</dt><dd>' . esc_html( $entry['def'] ) . '</dd></span>';
			}
			$body .= '</dl></span>';
		}
		$css = '<style>'
			. '.bz-glossary{display:block}'
			. '.bz-glossary-tools{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:center;margin:0 0 32px;position:sticky;top:0;z-index:2;background:var(--color-surface,#fff);padding:12px 0;border-bottom:1px solid var(--color-border)}'
			. '.bz-glossary-tools input{flex:1 1 220px;min-height:44px;padding:0 14px;border:1px solid var(--color-border);font:inherit;font-size:1rem;color:var(--color-text);background:var(--color-surface,#fff)}'
			. '.bz-glossary-nav{display:flex;flex-wrap:wrap;gap:4px}'
			. '.bz-glossary-nav a{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;font-weight:700;color:var(--color-primary);text-decoration:none}'
			. '.bz-glossary-nav a:hover{background:var(--color-surface-alt)}'
			. '.bz-glossary-group{display:grid;grid-template-columns:64px 1fr;gap:16px;padding:24px 0;border-bottom:1px solid var(--color-border);scroll-margin-top:96px}'
			. '.bz-glossary-letter{font-family:var(--font-heading),sans-serif;font-size:2rem;font-weight:700;line-height:1;color:var(--color-primary)}'
			. '.bz-glossary dl{margin:0;display:grid;gap:16px}'
			. '.bz-glossary-item{display:block}'
			. '.bz-glossary dt{font-weight:700;color:var(--color-heading)}'
			. '.bz-glossary dd{margin:4px 0 0;color:var(--color-text)}'
			. '.bz-glossary-empty{display:none;color:var(--color-text-muted)}'
			. '.bz-glossary-tabs{display:flex;flex-wrap:wrap;gap:6px;flex-basis:100%}'
			. '.bz-glossary-tabs button{border:1px solid var(--color-border);background:var(--color-surface,#fff);color:var(--color-text);padding:6px 14px;font:inherit;font-size:.9375rem;cursor:pointer;border-radius:var(--radius-button,0)}'
			. '.bz-glossary-tabs button[aria-pressed=true]{background:var(--color-primary);border-color:var(--color-primary);color:var(--color-inverse,#fff)}'
			. '.bz-glossary-tag{display:inline-block;font-size:.75rem;font-weight:600;padding:1px 8px;margin-left:6px;vertical-align:2px;border-radius:999px;background:var(--color-surface-alt);color:var(--color-text-muted)}'
			. '@media (max-width:600px){.bz-glossary-group{grid-template-columns:1fr;gap:8px}}'
			. '</style>';
		$js = '<script>(function(){var g=document.currentScript.closest(".bz-glossary");if(!g)return;var q=g.querySelector("input"),sec="";'
			. 'function run(){var v=q.value.trim().toLowerCase(),any=false;g.querySelectorAll(".bz-glossary-group").forEach(function(gr){var shown=0;'
			. 'gr.querySelectorAll(".bz-glossary-item").forEach(function(it){var hit=(!v||it.textContent.toLowerCase().indexOf(v)>-1)&&(!sec||it.getAttribute("data-section")===sec);it.style.display=hit?"":"none";if(hit)shown++;});'
			. 'gr.style.display=shown?"":"none";var a=g.querySelector(\'.bz-glossary-nav a[href="#\'+gr.id+\'"]\');if(a)a.style.display=shown?"":"none";if(shown)any=true;});g.querySelector(".bz-glossary-empty").style.display=any?"none":"block";}'
			. 'q.addEventListener("input",run);g.querySelectorAll(".bz-glossary-tabs button").forEach(function(b){b.addEventListener("click",function(){sec=b.getAttribute("data-section");'
			. 'g.querySelectorAll(".bz-glossary-tabs button").forEach(function(x){x.setAttribute("aria-pressed",x===b?"true":"false");});run();});});})();</script>';
		$tabs = ( ! $only && count( $sections ) > 1 ) ? '<span class="bz-glossary-tabs" role="group" aria-label="Show terms">'
			. '<button type="button" data-section="" aria-pressed="true">All terms</button>'
			. '<button type="button" data-section="business" aria-pressed="false">Business and accounting</button>'
			. '<button type="button" data-section="payroll" aria-pressed="false">Payroll</button></span>' : '';
		return $css . '<span class="bz-glossary"><span class="bz-glossary-tools">' . $tabs . '<input type="search" placeholder="Search the glossary" aria-label="Search the glossary">'
			. '<span class="bz-glossary-nav" role="navigation" aria-label="Glossary letters">' . $nav . '</span></span>'
			. $body . '<span class="bz-glossary-empty">No matching terms.</span>' . $js . '</span>';
	}
);
