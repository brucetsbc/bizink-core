<?php
/**
 * Social sharing buttons for blog posts: icon + link only, no counters, no third-party scripts.
 * [bz_share] shortcode, plus auto-injected after the content on every single post (skipped if the
 * shortcode is already in the content). Works on both Elementor and Bizink Blocks since it hooks
 * the_content.
 */

defined( 'ABSPATH' ) || exit;

function bz_core_share_networks() {
	return array(
		'facebook' => array(
			'label' => 'Facebook',
			'svg'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22 12a10 10 0 10-11.56 9.87v-6.99H7.9v-2.88h2.54V9.41c0-2.51 1.49-3.89 3.78-3.89 1.1 0 2.24.19 2.24.19v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.87h2.78l-.44 2.88h-2.34v6.99A10 10 0 0022 12z"/></svg>',
			'url'   => static function ( $url, $title ) {
				return 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url );
			},
		),
		'x'        => array(
			'label' => 'X',
			'svg'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.24 2.5h3.1l-6.78 7.75L22.5 21.5h-6.3l-4.94-6.46-5.65 6.46H2.5l7.25-8.3L2 2.5h6.46l4.46 5.9 5.32-5.9zm-1.09 17.2h1.72L7.93 4.19H6.08l11.07 15.51z"/></svg>',
			'url'   => static function ( $url, $title ) {
				return 'https://twitter.com/intent/tweet?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title );
			},
		),
		'linkedin' => array(
			'label' => 'LinkedIn',
			'svg'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.86 0-2.14 1.45-2.14 2.94v5.67H9.34V9h3.42v1.56h.05c.48-.9 1.64-1.85 3.38-1.85 3.61 0 4.28 2.38 4.28 5.47v6.27zM5.34 7.43a2.06 2.06 0 110-4.12 2.06 2.06 0 010 4.12zM7.12 20.45H3.56V9h3.56v11.45z"/></svg>',
			'url'   => static function ( $url, $title ) {
				return 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $url );
			},
		),
		'whatsapp' => array(
			'label' => 'WhatsApp',
			'svg'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.5 3.5A10 10 0 003.9 17.3L2 22l4.8-1.9A10 10 0 1020.5 3.5zm-8.5 15.8h0a8 8 0 01-4.1-1.1l-.3-.2-2.8 1.1 1.1-2.7-.2-.3A8 8 0 1112 19.3zm4.6-5.9c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1-.2.2-.6.8-.8 1-.1.2-.3.2-.5.1-1.3-.6-2.1-1.1-3-2.5-.2-.3 0-.5.1-.6.2-.2.4-.4.5-.6.1-.2.1-.4 0-.6-.1-.2-.6-1.4-.8-1.8-.2-.4-.4-.3-.6-.3h-.5c-.2 0-.5.1-.7.4-.2.3-.9 1-.9 2.3 0 1.3 1 2.6 1.1 2.8.1.2 1.9 2.9 4.6 4 .6.3 1.1.4 1.5.5.6.2 1.1.1 1.6.1.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3z"/></svg>',
			'url'   => static function ( $url, $title ) {
				return 'https://wa.me/?text=' . rawurlencode( $title . ' ' . $url );
			},
		),
		'email'    => array(
			'label' => 'Email',
			'svg'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 4h20v16H2V4zm2 2.4V6l8 7 8-7v.4l-8 7.4-8-7.4zm0 2.97V18h16V9.37l-7.4 6.47a1 1 0 01-1.2 0L4 9.37z"/></svg>',
			'url'   => static function ( $url, $title ) {
				return 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( $url );
			},
		),
	);
}

function bz_core_share_css() {
	return '.bz-share{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin:2rem 0}'
		. '.bz-share-label{font-weight:600;margin-right:.25rem}'
		. '.bz-share-btn{display:inline-flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:50%;background:#f1f1f1;color:#555;text-decoration:none;border:0;cursor:pointer;padding:0;transition:background .15s,color .15s}'
		. '.bz-share-btn svg{width:1.1rem;height:1.1rem}'
		. '.bz-share-btn:hover,.bz-share-btn:focus-visible{color:#fff}'
		. '.bz-share-facebook:hover{background:#1877f2}'
		. '.bz-share-x:hover{background:#000}'
		. '.bz-share-linkedin:hover{background:#0a66c2}'
		. '.bz-share-whatsapp:hover{background:#25d366}'
		. '.bz-share-email:hover,.bz-share-copy:hover{background:#555}'
		. '.bz-share-copy{position:relative}'
		. '.bz-share-copy.is-copied::after{content:"Copied!";position:absolute;bottom:130%;left:50%;transform:translateX(-50%);background:#333;color:#fff;font-size:.7rem;line-height:1;padding:.3rem .5rem;border-radius:.25rem;white-space:nowrap}';
}

function bz_core_share_js() {
	return "document.addEventListener('click',function(e){"
		. "var btn=e.target.closest('.bz-share-copy');if(!btn)return;e.preventDefault();"
		. "var url=btn.getAttribute('data-url');"
		. "function done(){btn.classList.add('is-copied');setTimeout(function(){btn.classList.remove('is-copied');},1500);}"
		. "if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done).catch(function(){});return;}"
		. "var ta=document.createElement('textarea');ta.value=url;ta.style.position='fixed';ta.style.opacity='0';document.body.appendChild(ta);ta.select();"
		. "try{document.execCommand('copy');done();}catch(e){}"
		. "document.body.removeChild(ta);"
		. "});";
}

/**
 * [bz_share] — share icons for the current post (or id="123"). networks="facebook,x,email" limits the set.
 */
function bz_core_share_bar( $post_id = 0, $networks = '' ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	if ( ! $post_id ) {
		return '';
	}
	$url   = get_permalink( $post_id );
	$title = get_the_title( $post_id );
	$all   = bz_core_share_networks();
	$wanted = $networks ? array_filter( array_map( 'trim', explode( ',', strtolower( $networks ) ) ) ) : array_keys( $all );

	wp_register_style( 'bz-share', false, array(), BZ_CORE_VERSION );
	wp_enqueue_style( 'bz-share' );
	static $printed = false;
	wp_add_inline_style( 'bz-share', $printed ? '' : bz_core_share_css() );

	$html = '<div class="bz-share" aria-label="Share this post"><span class="bz-share-label">Share:</span>';
	foreach ( $wanted as $slug ) {
		if ( ! isset( $all[ $slug ] ) ) {
			continue;
		}
		$network = $all[ $slug ];
		$html   .= sprintf(
			'<a class="bz-share-btn bz-share-%1$s" href="%2$s" target="_blank" rel="noopener noreferrer" aria-label="Share on %3$s">%4$s</a>',
			esc_attr( $slug ),
			esc_url( $network['url']( $url, $title ) ),
			esc_attr( $network['label'] ),
			$network['svg']
		);
	}
	$html .= sprintf(
		'<button type="button" class="bz-share-btn bz-share-copy" data-url="%1$s" aria-label="Copy link">%2$s</button>',
		esc_url( $url ),
		'<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10.6 13.4a3 3 0 010-4.2l3-3a3 3 0 114.2 4.2l-1.1 1.1a1 1 0 01-1.4-1.4l1.1-1.1a1 1 0 10-1.4-1.4l-3 3a1 1 0 000 1.4 1 1 0 01-1.4 1.4zm2.8-2.8a3 3 0 010 4.2l-3 3a3 3 0 11-4.2-4.2l1.1-1.1a1 1 0 011.4 1.4l-1.1 1.1a1 1 0 101.4 1.4l3-3a1 1 0 000-1.4 1 1 0 011.4-1.4z"/></svg>'
	);
	$html .= '</div>';

	if ( ! $printed ) {
		$html .= '<script>' . bz_core_share_js() . '</script>';
	}
	$printed = true;

	return $html;
}

add_shortcode(
	'bz_share',
	function ( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0, 'networks' => '' ), $atts, 'bz_share' );
		return bz_core_share_bar( absint( $atts['id'] ), $atts['networks'] );
	}
);

/**
 * Appended after the content on every single post, Elementor or Bizink Blocks, unless the post
 * already has [bz_share] / the rendered bar in it.
 */
add_filter(
	'the_content',
	function ( $content ) {
		if ( is_singular( 'post' ) && in_the_loop() && is_main_query() && false === strpos( $content, 'bz-share' ) ) {
			return $content . bz_core_share_bar();
		}
		return $content;
	},
	20
);
