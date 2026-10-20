<?php
/**
 * Software video libraries (Xero, MYOB, QuickBooks) from the Bizink content hub, checked and served by the master.
 *
 * Editing happens on the hub (bizinkcontent.com: xero-content, myob-content, quickbooks-content). The master site
 * syncs those libraries daily, checks every video (YouTube oEmbed) and guide link in hourly batches, and serves only
 * healthy items per software and region at /wp-json/bizink/v1/videos. Client sites fetch that feed twice a day and
 * keep the last good copy, so a video the vendor removes disappears from every site without touching them.
 *
 * Master admin: Tools → Video Library shows broken, stale and new items (with links to edit them on the hub), lets
 * you sync or check now, re-check a fixed item, mark stale items reviewed (they return after 12 months), and sends a weekly digest email when there's something to look at.
 *
 * [bz_video_library software="xero" view="latest" count="6" link="/services/cloud-accounting/xero/videos/"]
 * [bz_video_library software="xero" view="library"]
 * Outputs nothing when there are no videos, so a section marked .bz-video-section hides itself (kit CSS).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BZ_VIDEOS_SOURCE' ) ) {
	define( 'BZ_VIDEOS_SOURCE', BZ_MASTER_URL . '/wp-json/bizink/v1/videos' );
}
if ( ! defined( 'BZ_VIDEOS_HUB' ) ) {
	define( 'BZ_VIDEOS_HUB', 'https://bizinkcontent.com' );
}

/**
 * Libraries: key => [label, hub post type, hub topic taxonomy, guide link label].
 */
function bz_core_video_libraries() {
	return array(
		'xero'       => array( 'Xero', 'xero-content', 'xero-topics', 'Read the guide on Xero Central' ),
		'myob'       => array( 'MYOB', 'myob-content', 'myob-topics', 'Read the MYOB guide' ),
		'quickbooks' => array( 'QuickBooks', 'quickbooks-content', 'quickbooks-topics', 'Read the QuickBooks guide' ),
	);
}

/* Client Details region → hub region slug. */
function bz_core_video_region( $region ) {
	$map = array(
		'AU' => 'au',
		'NZ' => 'nz',
		'UK' => 'gb',
		'IE' => 'ie',
		'US' => 'us',
		'CA' => 'ca',
	);
	return isset( $map[ $region ] ) ? $map[ $region ] : 'au';
}

function bz_core_videos_is_source_site() {
	return bz_core_is_master() || wp_parse_url( (string) apply_filters( 'bz_videos_source', BZ_VIDEOS_SOURCE ), PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );
}

/* ---------- Master: sync from the hub ---------- */

function bz_core_videos_hub_get( $path ) {
	$response = wp_remote_get( BZ_VIDEOS_HUB . '/wp-json/' . $path, array( 'timeout' => 20 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array( null, 0 );
	}
	return array( json_decode( wp_remote_retrieve_body( $response ), true ), (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' ) );
}

/**
 * Pull every library from the hub into the bz_videos_library option. Returns an error message or ''.
 */
function bz_core_videos_sync() {
	list( $regions ) = bz_core_videos_hub_get( 'wp/v2/region?per_page=100&_fields=id,slug' );
	if ( ! is_array( $regions ) ) {
		return 'Could not reach the content hub.';
	}
	$region_slug = wp_list_pluck( $regions, 'slug', 'id' );
	$previous    = get_option( 'bz_videos_library', array() );
	$first_seen  = get_option( 'bz_videos_first_seen', array() );
	$library     = array(
		'generated' => gmdate( 'c' ),
		'libraries' => array(),
	);

	foreach ( bz_core_video_libraries() as $key => $def ) {
		list( $label, $type, $tax ) = $def;
		list( $topics ) = bz_core_videos_hub_get( 'wp/v2/' . $tax . '?per_page=100&_fields=id,name' );
		$items = array();
		$page  = 1;
		do {
			list( $batch, $pages ) = bz_core_videos_hub_get( 'wp/v2/' . $type . '?per_page=100&page=' . $page . '&_fields=id,slug,date,modified,title,content,excerpt,region,featured_media,' . $tax );
			if ( ! is_array( $batch ) ) {
				return 'Could not read the ' . $label . ' library from the content hub.';
			}
			$items = array_merge( $items, $batch );
			++$page;
		} while ( $page <= $pages );

		// Featured images, 100 at a time.
		$media_ids = array_filter( array_map( 'absint', wp_list_pluck( $items, 'featured_media' ) ) );
		$images    = array();
		foreach ( array_chunk( $media_ids, 100 ) as $chunk ) {
			list( $media ) = bz_core_videos_hub_get( 'wp/v2/media?per_page=100&include=' . implode( ',', $chunk ) . '&_fields=id,source_url,media_details' );
			foreach ( is_array( $media ) ? $media : array() as $m ) {
				$sizes             = isset( $m['media_details']['sizes'] ) ? $m['media_details']['sizes'] : array();
				$pick              = isset( $sizes['medium_large'] ) ? $sizes['medium_large'] : ( isset( $sizes['large'] ) ? $sizes['large'] : null );
				$images[ $m['id'] ] = $pick ? $pick['source_url'] : $m['source_url'];
			}
		}

		$out = array();
		foreach ( $items as $item ) {
			$html  = isset( $item['content']['rendered'] ) ? $item['content']['rendered'] : '';
			$video = preg_match( '~(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?v=)|youtu\.be/)([A-Za-z0-9_-]{11})~', $html, $vm ) ? $vm[1] : '';
			$guide = '';
			if ( preg_match_all( '~href="(https?://[^"]+)"~', $html, $lm ) ) {
				foreach ( $lm[1] as $href ) {
					if ( false === strpos( $href, 'youtube.com' ) && false === strpos( $href, 'youtu.be' ) ) {
						$guide = html_entity_decode( $href );
						break;
					}
				}
			}
			// Text for the card and the pop-up: the content without the video, links kept.
			$body = preg_replace( '~<iframe.*?</iframe>~is', '', $html );
			$body = wp_kses(
				$body,
				array(
					'p'      => array(),
					'ul'     => array(),
					'ol'     => array(),
					'li'     => array(),
					'strong' => array(),
					'em'     => array(),
					'br'     => array(),
					'h3'     => array(),
					'h4'     => array(),
					'a'      => array( 'href' => array() ),
				)
			);
			$body  = trim( preg_replace( '~<p>\s*(<a[^>]*>\s*Learn more\s*</a>)?\s*</p>~i', '', $body ) );
			$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) );
			$id    = $key . ':' . $item['id'];
			if ( ! isset( $first_seen[ $id ] ) ) {
				$first_seen[ $id ] = empty( $previous ) ? '' : gmdate( 'c' ); // '' = present at the first sync, not "new".
			}
			$out[] = array(
				'id'       => (int) $item['id'],
				'title'    => html_entity_decode( wp_strip_all_tags( $item['title']['rendered'] ), ENT_QUOTES ),
				'summary'  => wp_trim_words( $plain, 28, '…' ),
				'body'     => $body,
				'video'    => $video,
				'guide'    => esc_url_raw( $guide ),
				'image'    => isset( $images[ $item['featured_media'] ] ) ? $images[ $item['featured_media'] ] : '',
				'topics'   => array_map( 'intval', isset( $item[ $tax ] ) ? $item[ $tax ] : array() ),
				'regions'  => array_values( array_filter( array_map( function ( $rid ) use ( $region_slug ) {
					return isset( $region_slug[ $rid ] ) ? $region_slug[ $rid ] : null;
				}, isset( $item['region'] ) ? $item['region'] : array() ) ) ),
				'date'     => $item['date'],
				'modified' => $item['modified'],
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		$library['libraries'][ $key ] = array(
			'label'  => $label,
			'topics' => is_array( $topics ) ? wp_list_pluck( $topics, 'name', 'id' ) : array(),
			'items'  => $out,
		);
	}
	update_option( 'bz_videos_library', $library, false );
	update_option( 'bz_videos_first_seen', $first_seen, false );
	return '';
}

/* ---------- Master: health checks ---------- */

/**
 * Check one item's video and guide link. Returns ['video' => status, 'guide' => status, 'detail' => text].
 * Statuses: ok, removed, private, error (couldn't tell: treated as ok), redirect, broken, none.
 */
function bz_core_videos_check_item( $item ) {
	$result = array(
		'video'   => 'none',
		'guide'   => 'none',
		'detail'  => '',
		'checked' => time(),
	);
	if ( '' !== $item['video'] ) {
		$r    = wp_remote_get( 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( 'https://www.youtube.com/watch?v=' . $item['video'] ), array( 'timeout' => 10 ) );
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		if ( 200 === $code ) {
			$result['video'] = 'ok';
		} elseif ( in_array( $code, array( 401, 403 ), true ) ) {
			$result['video']  = 'private';
			$result['detail'] = 'Video is private or can no longer be embedded.';
		} elseif ( in_array( $code, array( 400, 404 ), true ) ) {
			$result['video']  = 'removed';
			$result['detail'] = 'Video has been removed from YouTube.';
		} else {
			$result['video'] = 'error';
		}
	}
	if ( '' !== $item['guide'] ) {
		$args = array(
			'timeout'     => 10,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (compatible; Bizink link check)',
		);
		$r    = wp_remote_head( $item['guide'], $args );
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		if ( in_array( $code, array( 403, 405 ), true ) ) {
			$r    = wp_remote_get( $item['guide'], $args ); // Some sites refuse HEAD.
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		}
		if ( $code >= 200 && $code < 400 ) {
			$result['guide'] = 'ok';
		} elseif ( in_array( $code, array( 404, 410 ), true ) ) {
			$result['guide']   = 'broken';
			$result['detail'] .= ( '' !== $result['detail'] ? ' ' : '' ) . 'Guide link returns ' . $code . '.';
		} else {
			$result['guide'] = 'error';
		}
	}
	return $result;
}

/**
 * Check the least recently checked items (re-checking each about once a week).
 */
function bz_core_videos_check_batch( $limit = 40, $max_age = WEEK_IN_SECONDS ) {
	$library = get_option( 'bz_videos_library', array() );
	$health  = get_option( 'bz_videos_health', array() );
	$queue   = array();
	foreach ( isset( $library['libraries'] ) ? $library['libraries'] : array() as $key => $lib ) {
		foreach ( $lib['items'] as $item ) {
			$id      = $key . ':' . $item['id'];
			$checked = isset( $health[ $id ]['checked'] ) ? $health[ $id ]['checked'] : 0;
			if ( time() - $checked >= $max_age ) {
				$queue[ $id ] = array( $checked, $item );
			}
		}
	}
	uasort(
		$queue,
		function ( $a, $b ) {
			return $a[0] - $b[0];
		}
	);
	foreach ( array_slice( $queue, 0, $limit, true ) as $id => $row ) {
		$health[ $id ] = bz_core_videos_check_item( $row[1] );
	}
	update_option( 'bz_videos_health', $health, false );
	update_option( 'bz_videos_checked_at', gmdate( 'c' ), false );
	return count( $queue ) - min( $limit, count( $queue ) );
}

function bz_core_videos_is_hidden( $health_row ) {
	return $health_row && ( in_array( $health_row['video'], array( 'removed', 'private' ), true ) );
}

/* Schedules (master only). */
add_action(
	'init',
	function () {
		if ( ! bz_core_videos_is_source_site() ) {
			return;
		}
		if ( ! wp_next_scheduled( 'bz_videos_sync' ) ) {
			wp_schedule_event( time() + 60, 'daily', 'bz_videos_sync' );
		}
		if ( ! wp_next_scheduled( 'bz_videos_check' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'bz_videos_check' );
		}
		if ( ! wp_next_scheduled( 'bz_videos_digest' ) ) {
			wp_schedule_event( strtotime( 'next monday 08:00' ), 'weekly', 'bz_videos_digest' );
		}
	}
);
add_action( 'bz_videos_sync', 'bz_core_videos_sync' );
add_action(
	'bz_videos_check',
	function () {
		bz_core_videos_check_batch();
	}
);

/* ---------- Master: feed for client sites ---------- */

/**
 * Healthy items for one library and region, plus the topics they use.
 */
function bz_core_videos_feed_local( $key, $region ) {
	$library = get_option( 'bz_videos_library', array() );
	$health  = get_option( 'bz_videos_health', array() );
	if ( empty( $library['libraries'][ $key ] ) ) {
		return null;
	}
	$lib    = $library['libraries'][ $key ];
	$items  = array();
	$topics = array();
	foreach ( $lib['items'] as $item ) {
		$h = isset( $health[ $key . ':' . $item['id'] ] ) ? $health[ $key . ':' . $item['id'] ] : null;
		if ( bz_core_videos_is_hidden( $h ) || ( $item['regions'] && ! in_array( $region, $item['regions'], true ) ) ) {
			continue;
		}
		if ( $h && 'broken' === $h['guide'] ) {
			$item['guide'] = '';
		}
		unset( $item['regions'] );
		$items[] = $item;
		foreach ( $item['topics'] as $t ) {
			$topics[ $t ] = isset( $lib['topics'][ $t ] ) ? $lib['topics'][ $t ] : '';
		}
	}
	return array(
		'generated' => $library['generated'],
		'label'     => $lib['label'],
		'topics'    => array_filter( $topics ),
		'items'     => $items,
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bizink/v1',
			'/videos',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'software' => array( 'sanitize_callback' => 'sanitize_key' ),
					'region'   => array( 'sanitize_callback' => 'sanitize_key' ),
				),
				'callback'            => function ( WP_REST_Request $request ) {
					$feed = bz_core_videos_feed_local( (string) $request['software'], (string) $request['region'] );
					return $feed ? rest_ensure_response( $feed ) : new WP_Error( 'bz_no_library', 'No such library.', array( 'status' => 404 ) );
				},
			)
		);
		// Admin: run a sync or a check batch now (same as the buttons on Tools → Video Library).
		register_rest_route(
			'bizink/v1',
			'/videos/(?P<do>sync|check)',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) && bz_core_videos_is_source_site();
				},
				'callback'            => function ( WP_REST_Request $request ) {
					if ( 'sync' === $request['do'] ) {
						$err = bz_core_videos_sync();
						return rest_ensure_response( array( 'ok' => '' === $err, 'message' => '' === $err ? 'synced' : $err ) );
					}
					$left = bz_core_videos_check_batch( (int) ( $request['limit'] ? $request['limit'] : 60 ) );
					return rest_ensure_response( array( 'ok' => true, 'remaining' => $left ) );
				},
			)
		);
	}
);

/* ---------- Every site: the feed in use ---------- */

/**
 * The library for this site's region: local on the master, otherwise the master's feed (cached 12 hours,
 * last good copy kept, retried after an hour on failure).
 */
function bz_core_videos_feed( $key ) {
	static $cache = array();
	$region = bz_core_video_region( bz_core_get( 'region' ) );
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	if ( bz_core_videos_is_source_site() ) {
		$cache[ $key ] = bz_core_videos_feed_local( $key, $region );
		return $cache[ $key ];
	}
	$opt  = 'bz_videos_' . $key . '_' . $region;
	$last = get_option( $opt, null );
	if ( false === get_transient( $opt . '_checked' ) ) {
		$url      = add_query_arg(
			array(
				'software' => $key,
				'region'   => $region,
			),
			(string) apply_filters( 'bz_videos_source', BZ_VIDEOS_SOURCE )
		);
		$response = bz_core_master_request( $url, array( 'timeout' => 10 ) );
		$body     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$ok       = 200 === $code && isset( $body['items'] );
		if ( $ok ) {
			$last = $body;
			update_option( $opt, $body, false );
			set_transient( $opt . '_checked', 1, 12 * HOUR_IN_SECONDS );
		} else {
			set_transient( $opt . '_checked', 1, HOUR_IN_SECONDS );
		}
		// Last fetch result per library, shown in Tools → Video Library on client sites.
		$log         = get_option( 'bz_videos_fetch_log', array() );
		$log[ $opt ] = array(
			'at'     => time(),
			'ok'     => $ok,
			'detail' => $ok ? count( $body['items'] ) . ' items'
				: ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code . ( isset( $body['message'] ) ? ': ' . $body['message'] : ( null === $body ? ' (not JSON: blocked or a challenge page?)' : '' ) ) ),
		);
		update_option( 'bz_videos_fetch_log', $log, false );
	}
	$cache[ $key ] = isset( $last['items'] ) ? $last : null;
	return $cache[ $key ];
}

/* ---------- Shortcode ---------- */

function bz_core_video_library_assets() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	$css  = '.bz-vl{display:block}'
		. '.bz-vl-tools{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:0 0 24px}'
		. '.bz-vl-tools input{flex:1 1 220px;min-height:44px;padding:0 14px;border:1px solid var(--color-border);font:inherit;font-size:1rem;color:var(--color-text);background:var(--color-surface,#fff)}'
		. '.bz-vl-topics{display:flex;flex-wrap:wrap;gap:8px}'
		. '.bz-vl-topics button{font:inherit;font-size:.875rem;padding:8px 14px;border:1px solid var(--color-border);background:var(--color-surface,#fff);color:var(--color-heading);cursor:pointer}'
		. '.bz-vl-topics button[aria-pressed=true]{background:var(--color-primary);border-color:var(--color-primary);color:var(--color-inverse,#fff)}'
		. '.bz-vl-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:var(--space-md,24px)}'
		. '@media (max-width:1024px){.bz-vl-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '@media (max-width:640px){.bz-vl-grid{grid-template-columns:1fr}.bz-vl-topics{flex-wrap:nowrap;overflow-x:auto;margin:0 -16px;padding:0 16px 4px;scrollbar-width:thin}.bz-vl-topics button{flex:0 0 auto;white-space:nowrap}}'
		. '.bz-vl-card{display:flex;flex-direction:column;background:var(--color-surface,#fff);border:1px solid var(--color-border);overflow:hidden}'
		. '.bz-vl-thumb{position:relative;display:block;aspect-ratio:16/9;background:var(--color-surface-alt) center/cover no-repeat;border:0;padding:0;width:100%;cursor:pointer}'
		. '.bz-vl-thumb .bz-vl-play{position:absolute;inset:auto auto 12px 12px;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:rgba(19,35,58,.85);color:#fff;font-size:.8rem;font-weight:600}'
		. '.bz-vl-body{display:flex;flex-direction:column;gap:8px;padding:16px 18px 18px;flex:1 1 auto}'
		. '.bz-vl-topic{font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--color-secondary,var(--color-primary))}'
		. '.bz-vl-title{font-family:var(--font-heading),sans-serif;font-weight:700;font-size:1.05rem;line-height:1.3;color:var(--color-heading)}'
		. '.bz-vl-summary{color:var(--color-text-muted);font-size:.925rem}'
		. '.bz-vl-open{margin-top:auto;align-self:flex-start;font:inherit;font-weight:600;color:var(--color-primary);background:none;border:0;padding:0;cursor:pointer;text-decoration:underline;text-decoration-color:var(--color-accent);text-underline-offset:3px}'
		. '.bz-vl-more{display:inline-block;margin-top:24px;font-weight:600;color:var(--color-primary)}'
		. '.bz-vl-empty{display:none;color:var(--color-text-muted)}'
		. 'dialog.bz-vl-dialog{width:min(920px,calc(100vw - 32px));max-height:calc(100vh - 32px);padding:0;border:0;background:var(--color-surface,#fff);color:var(--color-text)}'
		. 'dialog.bz-vl-dialog::backdrop{background:rgba(10,20,35,.7)}'
		. '.bz-vl-dialog .bz-vl-frame{aspect-ratio:16/9;background:#000}'
		. '.bz-vl-dialog iframe{width:100%;height:100%;border:0;display:block}'
		. '.bz-vl-dialog .bz-vl-text{padding:20px 24px 24px;display:grid;gap:10px}'
		. '.bz-vl-dialog .bz-vl-text a{color:var(--color-primary)}'
		. '.bz-vl-dialog .bz-vl-close{position:absolute;top:8px;right:8px;width:40px;height:40px;border:0;background:rgba(19,35,58,.85);color:#fff;font-size:1.4rem;cursor:pointer;z-index:2}';
	$js = '(function(){if(window.bzVL)return;window.bzVL=1;'
		. 'var d=document.createElement("dialog");d.className="bz-vl-dialog";d.innerHTML=\'<button type="button" class="bz-vl-close" aria-label="Close">×</button><span class="bz-vl-frame" style="display:block"></span><span class="bz-vl-text"></span>\';'
		. 'document.addEventListener("DOMContentLoaded",function(){document.body.appendChild(d);});'
		. 'function close(){d.querySelector(".bz-vl-frame").innerHTML="";d.close();}'
		. 'd.addEventListener("click",function(e){if(e.target===d||e.target.closest(".bz-vl-close"))close();});d.addEventListener("cancel",function(e){e.preventDefault();close();});'
		. 'document.addEventListener("click",function(e){var b=e.target.closest("[data-bz-vl]");if(!b)return;e.preventDefault();var c=b.closest(".bz-vl-card"),t=c.querySelector("template");'
		. 'var f=d.querySelector(".bz-vl-frame"),v=c.getAttribute("data-video");f.style.display=v?"block":"none";'
		. 'f.innerHTML=v?\'<iframe src="https://www.youtube-nocookie.com/embed/\'+v+\'?autoplay=1&rel=0" title="\'+c.getAttribute("data-title").replace(/"/g,"&quot;")+\'" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>\':"";'
		. 'd.querySelector(".bz-vl-text").innerHTML=t.innerHTML;d.showModal();});'
		. 'document.addEventListener("input",function(e){if(!e.target.matches(".bz-vl-tools input"))return;filter(e.target.closest(".bz-vl"));});'
		. 'document.addEventListener("click",function(e){var b=e.target.closest(".bz-vl-topics button");if(!b)return;var w=b.closest(".bz-vl");w.querySelectorAll(".bz-vl-topics button").forEach(function(x){x.setAttribute("aria-pressed",x===b?"true":"false");});filter(w);});'
		. 'function filter(w){var q=(w.querySelector(".bz-vl-tools input")||{value:""}).value.trim().toLowerCase(),p=w.querySelector(".bz-vl-topics [aria-pressed=true]"),tp=p?p.getAttribute("data-topic"):"",n=0;'
		. 'w.querySelectorAll(".bz-vl-card").forEach(function(c){var ok=(!tp||(" "+c.getAttribute("data-topics")+" ").indexOf(" "+tp+" ")>-1)&&(!q||c.textContent.toLowerCase().indexOf(q)>-1);c.style.display=ok?"":"none";if(ok)n++;});'
		. 'var em=w.querySelector(".bz-vl-empty");if(em)em.style.display=n?"none":"block";}})();';
	return '<style>' . $css . '</style><script>' . $js . '</script>';
}

add_shortcode(
	'bz_video_library',
	function ( $atts ) {
		$atts = shortcode_atts(
			array(
				'software' => 'xero',
				'view'     => 'latest',
				'count'    => '6',
				'link'     => '',
				'topic'    => '',
			),
			$atts,
			'bz_video_library'
		);
		$key  = sanitize_key( $atts['software'] );
		$libs = bz_core_video_libraries();
		$feed = isset( $libs[ $key ] ) ? bz_core_videos_feed( $key ) : null;
		if ( empty( $feed['items'] ) ) {
			return '';
		}
		$items = $feed['items'];
		if ( '' !== $atts['topic'] ) {
			$want  = strtolower( $atts['topic'] );
			$items = array_values(
				array_filter(
					$items,
					function ( $i ) use ( $feed, $want ) {
						foreach ( $i['topics'] as $t ) {
							if ( isset( $feed['topics'][ $t ] ) && strtolower( $feed['topics'][ $t ] ) === $want ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}
		$library = 'library' === $atts['view'];
		if ( ! $library ) {
			// Latest view: videos only, newest first.
			$items = array_slice( array_values( array_filter( $items, function ( $i ) {
				return '' !== $i['video'];
			} ) ), 0, max( 1, (int) $atts['count'] ) );
		}
		$guide_label = $libs[ $key ][3];
		$cards       = '';
		foreach ( $items as $item ) {
			$topic = '';
			foreach ( $item['topics'] as $t ) {
				if ( isset( $feed['topics'][ $t ] ) ) {
					$topic = $feed['topics'][ $t ];
					break;
				}
			}
			$image = '' !== $item['image'] ? $item['image'] : ( '' !== $item['video'] ? 'https://i.ytimg.com/vi/' . $item['video'] . '/hqdefault.jpg' : '' );
			$text  = '<strong class="bz-vl-title">' . esc_html( $item['title'] ) . '</strong>' . wp_kses_post( $item['body'] )
				. ( '' !== $item['guide'] ? '<a href="' . esc_url( $item['guide'] ) . '" target="_blank" rel="noopener">' . esc_html( $guide_label ) . ' →</a>' : '' );
			$cards .= sprintf(
				'<span class="bz-vl-card" data-video="%1$s" data-title="%2$s" data-topics="%3$s">'
				. '<button type="button" class="bz-vl-thumb" data-bz-vl style="background-image:url(\'%4$s\')" aria-label="%5$s"><span class="bz-vl-play">%6$s</span></button>'
				. '<span class="bz-vl-body">%7$s<span class="bz-vl-title">%2$s</span><span class="bz-vl-summary">%8$s</span>'
				. '<button type="button" class="bz-vl-open" data-bz-vl>%9$s</button></span><template>%10$s</template></span>',
				esc_attr( $item['video'] ),
				esc_html( $item['title'] ),
				esc_attr( implode( ' ', $item['topics'] ) ),
				esc_url( $image ),
				esc_attr( ( '' !== $item['video'] ? 'Watch: ' : 'Read: ' ) . $item['title'] ),
				'' !== $item['video'] ? '▶ Watch' : 'Guide',
				'' !== $topic ? '<span class="bz-vl-topic">' . esc_html( $topic ) . '</span>' : '',
				esc_html( $item['summary'] ),
				'' !== $item['video'] ? 'Watch the video' : 'Read the guide',
				$text
			);
		}
		$tools = '';
		if ( $library ) {
			$buttons = '<button type="button" aria-pressed="true" data-topic="">All topics</button>';
			foreach ( $feed['topics'] as $tid => $name ) {
				$buttons .= '<button type="button" aria-pressed="false" data-topic="' . esc_attr( $tid ) . '">' . esc_html( $name ) . '</button>';
			}
			$tools = '<span class="bz-vl-tools"><input type="search" placeholder="Search ' . esc_attr( $feed['label'] ) . ' videos and guides" aria-label="Search">'
				. '<span class="bz-vl-topics" role="group" aria-label="Topics">' . $buttons . '</span></span>';
		}
		$more = ( ! $library && '' !== $atts['link'] ) ? '<a class="bz-vl-more" href="' . esc_url( $atts['link'] ) . '">See all ' . esc_html( $feed['label'] ) . ' videos and guides →</a>' : '';
		return bz_core_video_library_assets() . '<span class="bz-vl">' . $tools . '<span class="bz-vl-grid">' . $cards . '</span>'
			. ( $library ? '<span class="bz-vl-empty">No matching videos or guides.</span>' : '' ) . $more . '</span>';
	}
);

/* ---------- Master admin: Tools → Video Library ---------- */

function bz_core_videos_report_rows() {
	$library    = get_option( 'bz_videos_library', array() );
	$health     = get_option( 'bz_videos_health', array() );
	$first_seen = get_option( 'bz_videos_first_seen', array() );
	$reviewed   = get_option( 'bz_videos_reviewed', array() );
	$summary    = array();
	$problems   = array();
	$new        = array();
	$stale      = array();
	foreach ( isset( $library['libraries'] ) ? $library['libraries'] : array() as $key => $lib ) {
		$type = bz_core_video_libraries()[ $key ][1];
		$s    = array(
			'label'     => $lib['label'],
			'items'     => count( $lib['items'] ),
			'videos'    => 0,
			'unchecked' => 0,
			'problems'  => 0,
			'stale'     => 0,
			'updated'   => '',
		);
		foreach ( $lib['items'] as $item ) {
			$id   = $key . ':' . $item['id'];
			$h    = isset( $health[ $id ] ) ? $health[ $id ] : null;
			$edit = BZ_VIDEOS_HUB . '/wp-admin/post.php?post=' . $item['id'] . '&action=edit';
			$s['videos']  += '' !== $item['video'] ? 1 : 0;
			$s['updated']  = max( $s['updated'], $item['modified'] );
			if ( ! $h ) {
				++$s['unchecked'];
			} elseif ( in_array( $h['video'], array( 'removed', 'private' ), true ) || 'broken' === $h['guide'] ) {
				++$s['problems'];
				$problems[] = array( $lib['label'], $item, $h, $edit, $id );
			}
			// Stale: not edited on the hub in 12 months, and not marked reviewed here in the last 12 months.
			$seen = max( strtotime( $item['modified'] ), isset( $reviewed[ $id ] ) ? (int) $reviewed[ $id ] : 0 );
			if ( $seen < strtotime( '-12 months' ) ) {
				++$s['stale'];
				$stale[] = array( $lib['label'], $item, $edit, $id, isset( $reviewed[ $id ] ) ? (int) $reviewed[ $id ] : 0 );
			}
			if ( ! empty( $first_seen[ $id ] ) && strtotime( $first_seen[ $id ] ) > strtotime( '-30 days' ) ) {
				$new[] = array( $lib['label'], $item, $edit );
			}
		}
		$summary[ $key ] = $s;
	}
	return array( $summary, $problems, $new, $stale );
}

add_action(
	'admin_menu',
	function () {
		add_management_page( 'Video Library', 'Video Library', 'manage_options', 'bz-video-library', bz_core_videos_is_source_site() ? 'bz_core_render_video_report' : 'bz_core_render_video_client_status' );
	}
);

add_action(
	'admin_post_bz_videos_action',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'bz_videos_action' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$msg = '';
		if ( 'sync' === $do ) {
			$err = bz_core_videos_sync();
			$msg = '' === $err ? 'Libraries synced from the content hub.' : $err;
		} elseif ( 'check' === $do ) {
			$left = bz_core_videos_check_batch( 60 );
			$msg  = 'Checked a batch of items. ' . ( $left ? $left . ' still due; the hourly check will continue.' : 'All items are up to date.' );
		} elseif ( 'review' === $do ) {
			$ids      = isset( $_POST['items'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['items'] ) ) : array();
			$reviewed = get_option( 'bz_videos_reviewed', array() );
			foreach ( $ids as $id ) {
				if ( preg_match( '/^[a-z]+:\d+$/', $id ) ) {
					$reviewed[ $id ] = time();
				}
			}
			update_option( 'bz_videos_reviewed', $reviewed, false );
			$msg = $ids ? sprintf( 'Marked %d item(s) as reviewed. They will come back in 12 months unless edited on the hub before then.', count( $ids ) ) : 'No items were selected.';
		} elseif ( 'recheck' === $do ) {
			$id      = isset( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : '';
			$library = get_option( 'bz_videos_library', array() );
			$health  = get_option( 'bz_videos_health', array() );
			list( $key, $item_id ) = array_pad( explode( ':', $id, 2 ), 2, '' );
			$msg = 'Item not found. Try Sync from hub now first.';
			foreach ( isset( $library['libraries'][ $key ]['items'] ) ? $library['libraries'][ $key ]['items'] : array() as $item ) {
				if ( (int) $item_id === $item['id'] ) {
					$health[ $id ] = bz_core_videos_check_item( $item );
					update_option( 'bz_videos_health', $health, false );
					$fixed = ! bz_core_videos_is_hidden( $health[ $id ] ) && 'broken' !== $health[ $id ]['guide'];
					$msg   = $item['title'] . ': ' . ( $fixed ? 'now working.' : 'still a problem (' . ( $health[ $id ]['detail'] ? $health[ $id ]['detail'] : 'see report' ) . '). If you changed the video on the hub, sync first.' );
					break;
				}
			}
		} elseif ( 'refresh' === $do ) {
			$region = bz_core_video_region( bz_core_get( 'region' ) );
			$parts  = array();
			foreach ( array_keys( bz_core_video_libraries() ) as $key ) {
				delete_transient( 'bz_videos_' . $key . '_' . $region . '_checked' );
				$feed    = bz_core_videos_feed( $key );
				$parts[] = $key . ': ' . ( $feed ? count( $feed['items'] ) . ' videos' : 'none' );
			}
			$msg = 'Fetched from the master. ' . implode( ', ', $parts ) . '. If pages still look empty, clear the page cache.';
		} elseif ( 'email' === $do ) {
			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			update_option( 'bz_videos_report_email', $email, false );
			$msg = 'Digest email saved.';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'bz-video-library', 'bz_msg' => rawurlencode( $msg ) ), admin_url( 'tools.php' ) ) );
		exit;
	}
);

/**
 * Client sites: where the videos come from and whether the last fetch from the master worked.
 */
function bz_core_render_video_client_status() {
	$region = bz_core_video_region( bz_core_get( 'region' ) );
	$log    = get_option( 'bz_videos_fetch_log', array() );
	echo '<div class="wrap"><h1>Video Library</h1>';
	if ( isset( $_GET['bz_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['bz_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	echo '<p>This site shows the <strong>' . esc_html( strtoupper( $region ) ) . '</strong> video libraries from <code>' . esc_html( (string) apply_filters( 'bz_videos_source', BZ_VIDEOS_SOURCE ) ) . '</code>, refreshed twice a day. The region comes from Settings → Client Details. Videos are edited on the content hub and checked by the master.</p>';
	echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Library</th><th>Videos on this site</th><th>Last fetch</th><th>Result</th></tr></thead><tbody>';
	foreach ( bz_core_video_libraries() as $key => $lib ) {
		$opt  = 'bz_videos_' . $key . '_' . $region;
		$last = get_option( $opt, null );
		$row  = isset( $log[ $opt ] ) ? $log[ $opt ] : null;
		echo '<tr><td>' . esc_html( $lib[0] ) . '</td><td>' . ( isset( $last['items'] ) ? count( $last['items'] ) : 0 ) . '</td><td>'
			. ( $row ? esc_html( human_time_diff( $row['at'] ) . ' ago' ) : 'never' ) . '</td><td>'
			. ( $row ? ( $row['ok'] ? '✓ ' : '<strong style="color:#b32d2e">✗</strong> ' ) . esc_html( $row['detail'] ) : '' ) . '</td></tr>';
	}
	echo '</tbody></table><p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'bz_videos_action', '_wpnonce', true, false )
		. '<input type="hidden" name="action" value="bz_videos_action"><input type="hidden" name="do" value="refresh"><button class="button button-primary">Refresh now</button></form>';
	echo '</p><p class="description">If a fetch fails with a 403 or "not JSON", the master\'s firewall (e.g. Cloudflare) is blocking this server: allow its IP address there.</p></div>';
}

function bz_core_render_video_report() {
	list( $summary, $problems, $new, $stale ) = bz_core_videos_report_rows();
	$library = get_option( 'bz_videos_library', array() );
	$form    = function ( $do, $label, $primary = false ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">'
			. wp_nonce_field( 'bz_videos_action', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="bz_videos_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
			. '<button class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	};
	echo '<div class="wrap"><h1>Video Library</h1>';
	if ( isset( $_GET['bz_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['bz_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	echo '<p>Edit videos on the <a href="' . esc_url( BZ_VIDEOS_HUB . '/wp-admin/' ) . '" target="_blank">content hub</a>. This site syncs the libraries daily, checks every video and guide link about once a week, and serves only working items to client sites. Removed or private videos are hidden automatically.</p>';
	echo '<p><strong>Last sync:</strong> ' . esc_html( isset( $library['generated'] ) ? get_date_from_gmt( $library['generated'], 'j M Y H:i' ) : 'never' )
		. ' &nbsp; <strong>Last check:</strong> ' . esc_html( get_option( 'bz_videos_checked_at' ) ? get_date_from_gmt( get_option( 'bz_videos_checked_at' ), 'j M Y H:i' ) : 'never' ) . '</p>';
	echo '<p>' . $form( 'sync', 'Sync from hub now', true ) . $form( 'check', 'Check a batch now' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above.

	echo '<h2>Summary</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Library</th><th>Items</th><th>With video</th><th>Problems</th><th>Not yet checked</th><th>Not updated in 12 months</th><th>Last edited on hub</th></tr></thead><tbody>';
	foreach ( $summary as $s ) {
		printf(
			'<tr><td><strong>%s</strong></td><td>%d</td><td>%d</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
			esc_html( $s['label'] ),
			(int) $s['items'],
			(int) $s['videos'],
			$s['problems'] ? '<strong style="color:#b32d2e">' . (int) $s['problems'] . '</strong>' : '0',
			(int) $s['unchecked'],
			(int) $s['stale'],
			esc_html( $s['updated'] ? mysql2date( 'j M Y', $s['updated'] ) : '' )
		);
	}
	echo '</tbody></table>';

	$links  = function ( $item ) {
		$out  = '' !== $item['video'] ? ' · <a href="https://www.youtube.com/watch?v=' . esc_attr( $item['video'] ) . '" target="_blank">Video</a>' : '';
		$out .= '' !== $item['guide'] ? ' · <a href="' . esc_url( $item['guide'] ) . '" target="_blank">Guide</a>' : '';
		return $out;
	};
	$hidden = function ( $do ) {
		return wp_nonce_field( 'bz_videos_action', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="bz_videos_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
	};

	// Problems: each row can be re-checked after fixing it on the hub.
	echo '<h2>Problems (' . count( $problems ) . ')</h2><p>Removed or private videos are hidden from client sites until fixed; broken guide links are dropped from the card. After fixing an item on the hub, use <strong>Sync from hub now</strong>, then <strong>Re-check now</strong>.</p>';
	if ( ! $problems ) {
		echo '<p><em>Nothing to show.</em></p>';
	} else {
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Library</th><th>Item</th><th>Issue</th><th></th><th></th></tr></thead><tbody>';
		foreach ( $problems as $r ) {
			$h = $r[2];
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td><a href="%4$s" target="_blank">Edit on hub</a>%5$s</td><td><form method="post" action="%6$s">%7$s<input type="hidden" name="item" value="%8$s"><button class="button button-small">Re-check now</button></form></td></tr>',
				esc_html( $r[0] ),
				esc_html( $r[1]['title'] ),
				esc_html( $h['detail'] ? $h['detail'] : 'Video ' . $h['video'] . ', guide ' . $h['guide'] ),
				esc_url( $r[3] ),
				$links( $r[1] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure.
				esc_url( admin_url( 'admin-post.php' ) ),
				$hidden( 'recheck' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above.
				esc_attr( $r[4] )
			);
		}
		echo '</tbody></table>';
	}

	// New items.
	echo '<h2>New in the last 30 days (' . count( $new ) . ')</h2><p>Items added to the hub recently: check they are filed under the right topic and regions.</p>';
	if ( ! $new ) {
		echo '<p><em>Nothing to show.</em></p>';
	} else {
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Library</th><th>Item</th><th>Added</th><th></th></tr></thead><tbody>';
		foreach ( $new as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" target="_blank">Edit on hub</a>%s</td></tr>',
				esc_html( $r[0] ),
				esc_html( $r[1]['title'] ),
				esc_html( mysql2date( 'j M Y', $r[1]['date'] ) ),
				esc_url( $r[2] ),
				$links( $r[1] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure.
			);
		}
		echo '</tbody></table>';
	}

	// Stale items: tick the ones you've checked and mark them reviewed.
	echo '<h2>Not updated in 12 months (' . count( $stale ) . ')</h2><p>Worth reviewing: the vendor may have newer videos or changed the steps. Tick the items you have checked and select <strong>Mark selected as reviewed</strong>. They drop off this list for 12 months; editing an item on the hub also resets it.</p>';
	if ( ! $stale ) {
		echo '<p><em>Nothing to show.</em></p>';
	} else {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . $hidden( 'review' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above.
		echo '<p><button class="button button-primary">Mark selected as reviewed</button></p>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><td class="check-column"><input type="checkbox" class="bz-select-all" aria-label="Select all"></td><th>Library</th><th>Item</th><th>Last edited on hub</th><th>Last reviewed</th><th></th></tr></thead><tbody>';
		foreach ( $stale as $r ) {
			printf(
				'<tr><th scope="row" class="check-column"><input type="checkbox" name="items[]" value="%1$s" aria-label="Select %3$s"></th><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td><a href="%6$s" target="_blank">Edit on hub</a>%7$s</td></tr>',
				esc_attr( $r[3] ),
				esc_html( $r[0] ),
				esc_html( $r[1]['title'] ),
				esc_html( mysql2date( 'j M Y', $r[1]['modified'] ) ),
				esc_html( $r[4] ? wp_date( 'j M Y', $r[4] ) : 'Never' ),
				esc_url( $r[2] ),
				$links( $r[1] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure.
			);
		}
		echo '</tbody></table><p><button class="button button-primary">Mark selected as reviewed</button></p></form>';
		echo '<script>document.querySelectorAll(".bz-select-all").forEach(function(a){a.addEventListener("change",function(){a.closest("table").querySelectorAll("tbody input[type=checkbox]").forEach(function(c){c.checked=a.checked;});});});</script>';
	}

	echo '<h2>Weekly digest</h2><p>Every Monday, an email summarising problems and new items is sent if there is anything to review.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'bz_videos_action', '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		. '<input type="hidden" name="action" value="bz_videos_action"><input type="hidden" name="do" value="email">'
		. '<input type="email" name="email" class="regular-text" value="' . esc_attr( get_option( 'bz_videos_report_email', get_option( 'admin_email' ) ) ) . '"> <button class="button">Save</button></form></div>';
}

/* Weekly digest: only when there is something to look at. */
add_action(
	'bz_videos_digest',
	function () {
		list( $summary, $problems, $new, $stale ) = bz_core_videos_report_rows();
		if ( ! $problems && ! $new ) {
			return;
		}
		$lines = array( 'Video Library weekly check', '' );
		foreach ( $summary as $s ) {
			$lines[] = sprintf( '%s: %d items, %d problems, %d not updated in 12 months', $s['label'], $s['items'], $s['problems'], $s['stale'] );
		}
		$lines[] = '';
		$lines[] = sprintf( '%d problem(s) and %d new item(s) to review.', count( $problems ), count( $new ) );
		foreach ( array_slice( $problems, 0, 20 ) as $p ) {
			$lines[] = '- ' . $p[0] . ': ' . $p[1]['title'] . ' (' . ( $p[2]['detail'] ? $p[2]['detail'] : 'needs checking' ) . ')';
		}
		$lines[] = '';
		$lines[] = 'Full report: ' . admin_url( 'tools.php?page=bz-video-library' );
		wp_mail( get_option( 'bz_videos_report_email', get_option( 'admin_email' ) ), 'Video Library: ' . count( $problems ) . ' problem(s), ' . count( $new ) . ' new', implode( "\n", $lines ) );
	}
);
