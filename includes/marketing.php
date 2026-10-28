<?php
/**
 * Marketing Assistant (roadmap M5, phase 1): a Marketing hub in wp-admin with four numbers and a 90-day plan.
 *
 * Client sites
 *   Marketing (wp-admin menu, and the dashboard for the "Company admin" role) shows visitors, enquiries, downloads and
 *   Google search clicks for the last 28 days against the 28 before, and a timeline of ideas built from the site's
 *   own tax dates, downloads, calculators and services. "Draft post" writes a WordPress draft for an idea; email
 *   and ads are shown as coming soon. Nothing is published or sent outside the site.
 *
 * The master (BZ_MARKETING_SERVICE) is the central service
 *   It holds the Google service account and the Claude API key, and keeps a register of connected sites
 *   (Tools → Marketing sites), each with its own key, GA4 property and Search Console property. Client sites call
 *   /wp-json/bizink/v1/marketing/{stats,plan,draft} with X-Bizink-Site and X-Bizink-Key headers.
 *   wp-config.php on the master (both optional; never in the plugin or the database):
 *     define( 'BZ_GOOGLE_SA_KEY', '/home/<account>/private/bizink-ga.json' );   service account JSON, outside the webroot
 *     define( 'BZ_ANTHROPIC_API_KEY', '...' );                                  without it plans and drafts use the rules below
 *
 * Without a Claude key (or when the service can't be reached) the plan comes from bz_core_mkt_rule_plan() and drafts
 * from a template, so the hub always works. Connect a site with tools/marketing.py.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BZ_MARKETING_SERVICE' ) ) {
	define( 'BZ_MARKETING_SERVICE', BZ_MASTER_URL );
}
if ( ! defined( 'BZ_MARKETING_MODEL' ) ) {
	define( 'BZ_MARKETING_MODEL', 'claude-opus-5-5' );
}

function bz_core_mkt_is_service() {
	return bz_core_is_master() || wp_parse_url( BZ_MARKETING_SERVICE, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );
}

function bz_core_mkt_types() {
	return array(
		'deadline'   => 'Tax date',
		'seasonal'   => 'Seasonal',
		'resource'   => 'Download',
		'calculator' => 'Calculator',
		'service'    => 'Service',
		'news'       => 'Idea',
	);
}

/* ---------- Context: what the site knows about itself ---------- */

function bz_core_mkt_context() {
	$region  = bz_core_get( 'region' );
	$regions = bz_core_regions();
	$today   = bz_core_tax_dates_today_ts();
	$ctx     = array(
		'firm'         => bz_core_get( 'firm_name' ),
		'descriptor'   => bz_core_get( 'descriptor' ),
		'region'       => $region,
		'region_name'  => isset( $regions[ $region ] ) ? $regions[ $region ] : $region,
		'city'         => bz_core_get( 'city' ),
		'specialities' => bz_core_get( 'specialities' ),
		'software'     => bz_core_get( 'software' ),
		'offer'        => bz_core_get( 'offer_sentence' ),
		'booking_url'  => bz_core_get( 'booking_url' ),
		'site_url'     => home_url( '/' ),
		'today'        => gmdate( 'Y-m-d', $today ),
		'tax_dates'    => array(),
		'resources'    => array(),
		'calculators'  => array(),
		'services'     => array(),
		'recent_posts' => array(),
	);

	$country = bz_core_tax_dates_country( $region );
	if ( $country ) {
		foreach ( bz_core_tax_dates_rolling( $country, 4 ) as $month ) {
			foreach ( $month['rows'] as $row ) {
				$ts = bz_core_tax_dates_row_ts( $month, $row );
				if ( $ts && $ts > $today && $ts <= $today + 100 * DAY_IN_SECONDS ) {
					$ctx['tax_dates'][] = array(
						'date'  => gmdate( 'Y-m-d', $ts ),
						'label' => $row['label'],
						'desc'  => $row['desc'],
					);
				}
			}
		}
	}

	foreach ( array( 'bz_resource' => 'resources', 'bz_calculator' => 'calculators' ) as $type => $key ) {
		foreach ( get_posts( array( 'post_type' => $type, 'numberposts' => 40, 'orderby' => 'modified' ) ) as $p ) {
			$ctx[ $key ][] = array(
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
				'url'   => get_permalink( $p ),
				'about' => wp_trim_words( $p->post_excerpt ? $p->post_excerpt : wp_strip_all_tags( $p->post_content ), 30, '' ),
			);
		}
	}

	$services = get_page_by_path( 'services' );
	if ( $services ) {
		foreach ( get_pages( array( 'parent' => $services->ID, 'sort_column' => 'menu_order' ) ) as $p ) {
			$ctx['services'][] = array(
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
				'url'   => get_permalink( $p ),
			);
		}
	}

	foreach ( get_posts( array( 'numberposts' => 12, 'post_status' => array( 'publish', 'draft', 'future' ) ) ) as $p ) {
		$ctx['recent_posts'][] = array(
			'title'  => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
			'date'   => get_the_date( 'Y-m-d', $p ),
			'status' => $p->post_status,
		);
	}
	return $ctx;
}

/* ---------- Rule-based plan (no Claude key, or the service is unreachable) ---------- */

function bz_core_mkt_seasons( $region ) {
	$holiday = array( '12-01', 'Before the holiday break: cash flow and closing dates', 'December is busy and cash gets tight over the break. Clients like knowing when you close and what to sort out first.' );
	$all     = array(
		'AU' => array(
			array( '06-01', 'End of financial year checklist', 'The financial year ends on 30 June. A checklist post is one of the most-read pieces an accountant can publish in June.' ),
			array( '07-03', 'New financial year: get your records ready for tax time', 'Tax time starts in July. Tell clients what to gather and how to get their return in early.' ),
			array( '01-15', 'Planning the year ahead for your business', 'January is when owners set goals. A planning post invites advisory conversations.' ),
			$holiday,
		),
		'NZ' => array(
			array( '03-01', 'End of tax year (31 March) checklist', 'The tax year ends on 31 March. Remind clients what to do before then.' ),
			array( '04-03', 'New tax year: what changes on 1 April', 'Rates and thresholds often change on 1 April. A short update shows you are on top of it.' ),
			array( '01-15', 'Planning the year ahead for your business', 'January is when owners set goals. A planning post invites advisory conversations.' ),
			$holiday,
		),
		'UK' => array(
			array( '03-10', 'Tax year end (5 April) checklist', 'The tax year ends on 5 April. Allowances not used by then are lost, so this is a timely reminder.' ),
			array( '04-08', 'New tax year: rates and allowances that changed', 'A new tax year starts on 6 April. Summarise what changed for your clients.' ),
			array( '01-03', 'Self Assessment: last call before 31 January', 'The 31 January deadline drives a lot of searches in early January.' ),
			$holiday,
		),
		'IE' => array(
			array( '10-01', 'Pay and file: what to do before the October deadline', 'The self-assessed pay and file deadline is in late October or November.' ),
			array( '01-15', 'Planning the year ahead for your business', 'January is when owners set goals. A planning post invites advisory conversations.' ),
			$holiday,
		),
		'US' => array(
			array( '02-01', 'Tax season: the documents to gather now', 'Tax season opens in late January. Clients search for what to bring.' ),
			array( '11-15', 'Year-end tax moves to make before 31 December', 'Most year-end planning has to happen before 31 December.' ),
			$holiday,
		),
		'CA' => array(
			array( '02-15', 'Tax season checklist before the 30 April deadline', 'Personal returns are due on 30 April. Clients search for checklists in February and March.' ),
			array( '11-15', 'Year-end tax planning before 31 December', 'RRSP, expenses and payroll moves need to happen before year end.' ),
			$holiday,
		),
	);
	return isset( $all[ $region ] ) ? $all[ $region ] : $all['AU'];
}

function bz_core_mkt_item( $date_ts, $type, $title, $why, $angle, $link = '' ) {
	return array(
		'date'  => gmdate( 'Y-m-d', $date_ts ),
		'type'  => $type,
		'title' => $title,
		'why'   => $why,
		'angle' => $angle,
		'link'  => $link,
	);
}

function bz_core_mkt_rule_plan( $ctx ) {
	$today = strtotime( $ctx['today'] . ' 00:00:00 UTC' );
	$end   = $today + 90 * DAY_IN_SECONDS;
	$firm  = $ctx['firm'];
	$items = array();
	$soon  = function ( $ts ) use ( $today ) {
		return max( $ts, $today + DAY_IN_SECONDS );
	};

	// Tax dates: up to two per month, different labels, skipping the routine monthly lodgements.
	// Deadlines on the same day share one reminder.
	$per_month = array();
	$by_date   = array();
	foreach ( $ctx['tax_dates'] as $row ) {
		$ts = strtotime( $row['date'] . ' 00:00:00 UTC' );
		if ( $ts < $today + 7 * DAY_IN_SECONDS || $ts > $end || preg_match( '/^\s*monthly\b/i', $row['desc'] ) ) {
			continue;
		}
		$month = substr( $row['date'], 0, 7 );
		if ( isset( $per_month[ $month ][ $row['label'] ] ) || count( isset( $per_month[ $month ] ) ? $per_month[ $month ] : array() ) >= 2 ) {
			continue;
		}
		$per_month[ $month ][ $row['label'] ] = true;
		$by_date[ $row['date'] ][]            = $row;
	}
	foreach ( $by_date as $date => $rows ) {
		$ts     = strtotime( $date . ' 00:00:00 UTC' );
		$nice   = gmdate( 'j F', $ts );
		$shorts = array();
		$descs  = array();
		foreach ( $rows as $row ) {
			$shorts[] = trim( preg_split( '/\s+[—–-]\s+/u', $row['desc'] )[0] );
			$descs[]  = rtrim( $row['desc'], '.' );
		}
		$items[] = bz_core_mkt_item(
			$soon( $ts - 14 * DAY_IN_SECONDS ),
			'deadline',
			1 === count( $shorts ) ? sprintf( 'Reminder: %s (%s)', $shorts[0], $nice ) : sprintf( 'Due %s: %s', $nice, implode( ' and ', preg_replace( '/\s+due\b.*$/i', '', $shorts ) ) ),
			sprintf( '%s, due %s. A reminder two weeks out gives clients time to act and brings them back to your site.', implode( '; ', $descs ), $nice ),
			sprintf( 'Who it applies to, what to have ready before %s, and how %s can take care of it.', $nice, $firm )
		);
	}

	// Seasonal moments.
	foreach ( bz_core_mkt_seasons( $ctx['region'] ) as $season ) {
		foreach ( array( 0, 1 ) as $add ) {
			$ts = strtotime( ( gmdate( 'Y', $today ) + $add ) . '-' . $season[0] . ' 00:00:00 UTC' );
			if ( $ts >= $today && $ts <= $end ) {
				$items[] = bz_core_mkt_item( $ts, 'seasonal', $season[1], $season[2], sprintf( 'Practical steps in plain language, then an invitation to talk to %s.', $firm ) );
			}
		}
	}

	// One download a month, one calculator, one service spotlight a month. Rotate by week so a refresh shuffles them.
	$seed = (int) gmdate( 'W', $today );
	$pick = function ( $list, $n ) use ( $seed ) {
		if ( ! $list ) {
			return array();
		}
		$out = array();
		for ( $i = 0; $i < min( $n, count( $list ) ); $i++ ) {
			$out[] = $list[ ( $seed + $i ) % count( $list ) ];
		}
		return $out;
	};
	foreach ( $pick( $ctx['resources'], 3 ) as $i => $r ) {
		$items[] = bz_core_mkt_item(
			$today + ( 10 + 30 * $i ) * DAY_IN_SECONDS,
			'resource',
			sprintf( 'Share the "%s" download', $r['title'] ),
			'Your downloads are useful on their own. A short post that points to one brings visitors to the site and shows what you know.',
			sprintf( 'Who the %s is for, two or three tips from it, and a link to download it.', $r['title'] ),
			$r['url']
		);
	}
	foreach ( $pick( $ctx['calculators'], 1 ) as $c ) {
		$items[] = bz_core_mkt_item(
			$today + 35 * DAY_IN_SECONDS,
			'calculator',
			sprintf( 'Try it: the %s', $c['title'] ),
			'Calculators get shared and bookmarked. Point people to one with a worked example.',
			'A quick worked example with round numbers, what the result means, and when to ask for advice.',
			$c['url']
		);
	}
	foreach ( $pick( $ctx['services'], 3 ) as $i => $s ) {
		$items[] = bz_core_mkt_item(
			$today + ( 4 + 30 * $i ) * DAY_IN_SECONDS,
			'service',
			sprintf( 'Spotlight: %s', $s['title'] ),
			'A post about one service answers the questions people search for before they get in touch.',
			sprintf( 'The problem it solves, what working with %s looks like, and a clear next step.', $firm ),
			$s['url']
		);
	}

	usort(
		$items,
		function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		}
	);
	return array_slice( $items, 0, 14 );
}

/* ---------- Template draft (no Claude key) ---------- */

/**
 * A plan idea's title is written for the accountant ("Share the "Tax Checklist" download", "Spotlight: Business
 * Tax", "Reminder: ..."). Turn it into a title for readers of the post.
 */
function bz_core_mkt_post_title( $title ) {
	$t = trim( $title );
	$t = preg_replace( '/^(share the|share our|share|spotlight:|try it:\s*the|try it:|reminder:|promote the|promote)\s+/i', '', $t );
	$t = preg_replace( '/^["“”\']+|["“”\']+$/u', '', trim( $t ) );
	$t = preg_replace( '/["“”\']+\s+(download|calculator)$/iu', '', $t );
	$t = preg_replace( '/^["“”\']+/u', '', $t );
	$t = trim( $t );
	return '' !== $t ? ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 ) : ucfirst( $t ) ) : $title;
}

function bz_core_mkt_template_draft( $item, $ctx ) {
	$p    = function ( $text ) {
		return "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->\n\n";
	};
	$h    = function ( $text ) {
		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $text ) . "</h2>\n<!-- /wp:heading -->\n\n";
	};
	$html = $p( '[Opening: one or two sentences on why this matters right now.] ' . $item['why'] )
		. $h( 'What you need to know' )
		. $p( '[Key points. ' . $item['angle'] . ']' )
		. $h( 'How ' . $ctx['firm'] . ' can help' )
		. $p( '[How you help with this, in a sentence or two.]' . ( $ctx['offer'] ? ' ' . $ctx['offer'] : '' ) );
	if ( $item['link'] ) {
		$html .= "<!-- wp:paragraph -->\n<p><a href=\"" . esc_url( $item['link'] ) . '">' . esc_html( 'Find out more' ) . "</a></p>\n<!-- /wp:paragraph -->\n\n";
	}
	$html .= "<!-- wp:paragraph -->\n<p><a href=\"" . esc_url( $ctx['booking_url'] ) . '">' . esc_html( 'Book a time to talk' ) . "</a></p>\n<!-- /wp:paragraph -->\n";
	return array(
		'title'   => $item['title'],
		'excerpt' => $item['why'],
		'html'    => $html,
		'source'  => 'template',
	);
}

/* ---------- Claude (service only) ---------- */

function bz_core_mkt_claude( $system, $user, $schema, $max_tokens = 16000 ) {
	if ( ! defined( 'BZ_ANTHROPIC_API_KEY' ) || ! BZ_ANTHROPIC_API_KEY ) {
		return new WP_Error( 'bz_mkt_no_key', 'No Claude API key on the service.' );
	}
	$body    = array(
		'model'         => BZ_MARKETING_MODEL,
		'max_tokens'    => $max_tokens,
		'system'        => $system,
		'messages'      => array( array( 'role' => 'user', 'content' => $user ) ),
		'output_config' => array(
			'effort' => 'medium',
			'format' => array( 'type' => 'json_schema', 'schema' => $schema ),
		),
	);
	$headers = array(
		'content-type'      => 'application/json',
		'x-api-key'         => BZ_ANTHROPIC_API_KEY,
		'anthropic-version' => '2023-06-01',
	);
	// Server-side refusal fallback: the API re-runs a declined request on another model inside the same call.
	if ( in_array( BZ_MARKETING_MODEL, array( 'claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5' ), true ) ) {
		$body['fallbacks']          = 'default';
		$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	$response = wp_remote_post(
		'https://api.anthropic.com/v1/messages',
		array(
			'timeout' => 200,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $code ) {
		$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
		return new WP_Error( 'bz_mkt_claude_http', 'Claude API: ' . $msg );
	}

	$usage = get_option( 'bz_mkt_usage', array() );
	$month = gmdate( 'Y-m' );
	foreach ( array( 'input_tokens', 'output_tokens' ) as $k ) {
		$usage[ $month ][ $k ] = ( isset( $usage[ $month ][ $k ] ) ? $usage[ $month ][ $k ] : 0 ) + ( isset( $data['usage'][ $k ] ) ? (int) $data['usage'][ $k ] : 0 );
	}
	$usage[ $month ]['calls'] = ( isset( $usage[ $month ]['calls'] ) ? $usage[ $month ]['calls'] : 0 ) + 1;
	update_option( 'bz_mkt_usage', array_slice( $usage, -12, null, true ), false );

	$stop = isset( $data['stop_reason'] ) ? $data['stop_reason'] : '';
	if ( 'refusal' === $stop || 'max_tokens' === $stop ) {
		return new WP_Error( 'bz_mkt_claude_stop', 'Claude stopped early (' . $stop . ').' );
	}
	$text = '';
	foreach ( isset( $data['content'] ) ? $data['content'] : array() as $block ) {
		if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
			$text .= $block['text'];
		}
	}
	$json = json_decode( $text, true );
	return is_array( $json ) ? $json : new WP_Error( 'bz_mkt_claude_json', 'Claude returned something that is not JSON.' );
}

function bz_core_mkt_style_rules( $ctx ) {
	$spelling = in_array( $ctx['region'], array( 'US' ), true ) ? 'American' : ( 'CA' === $ctx['region'] ? 'Canadian' : 'British/' . $ctx['region_name'] );
	return 'Write for small business owners in ' . $ctx['region_name'] . ', in ' . $spelling . ' English. Plain, warm and specific; no hype, no clichés, '
		. 'no exclamation marks, and avoid em dashes. Never invent figures, rates, thresholds or deadlines: use only dates and facts given in the context, '
		. 'and where a fact is needed but not given, write a bracketed note for the accountant to fill in, like [check current rate].';
}

function bz_core_mkt_claude_plan( $ctx, $rules ) {
	$string = array( 'type' => 'string' );
	$schema = array(
		'type'                 => 'object',
		'properties'           => array(
			'items' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'properties'           => array(
						'date'  => $string,
						'type'  => array(
							'type' => 'string',
							'enum' => array_keys( bz_core_mkt_types() ),
						),
						'title' => $string,
						'why'   => $string,
						'angle' => $string,
						'link'  => $string,
					),
					'required'             => array( 'date', 'type', 'title', 'why', 'angle', 'link' ),
					'additionalProperties' => false,
				),
			),
		),
		'required'             => array( 'items' ),
		'additionalProperties' => false,
	);
	$system = 'You plan content marketing for an accounting or bookkeeping firm\'s website. ' . bz_core_mkt_style_rules( $ctx );
	$user   = "Plan the next 90 days of website posts for this firm, starting from today's date in the context.\n\n"
		. "- 10 to 14 items, dated YYYY-MM-DD between today and 90 days from now, spread across the period (roughly one a week).\n"
		. "- Use the firm's real tax dates (publish about two weeks before each deadline), its downloads and calculators (link to them), its services, and seasonal moments for the region. Pick the deadlines that matter most to small businesses, not routine monthly ones.\n"
		. "- Skip topics the recent posts already cover.\n"
		. "- title: a post title a client would click. why: one or two sentences for the accountant on why this is worth doing now. angle: what the post should cover. link: the most relevant URL from the context, or an empty string.\n"
		. "- The starting ideas were made by simple rules; keep, improve or replace them.\n\n"
		. "<context>\n" . wp_json_encode( $ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n</context>\n\n"
		. "<starting_ideas>\n" . wp_json_encode( $rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n</starting_ideas>";
	$out    = bz_core_mkt_claude( $system, $user, $schema );
	if ( is_wp_error( $out ) ) {
		return $out;
	}
	$items = array();
	foreach ( $out['items'] as $it ) {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $it['date'] ) ) {
			$items[] = $it;
		}
	}
	usort(
		$items,
		function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		}
	);
	return $items ? $items : new WP_Error( 'bz_mkt_empty', 'Claude returned no usable items.' );
}

function bz_core_mkt_claude_draft( $item, $ctx ) {
	$string = array( 'type' => 'string' );
	$schema = array(
		'type'                 => 'object',
		'properties'           => array(
			'title'   => $string,
			'excerpt' => $string,
			'html'    => $string,
		),
		'required'             => array( 'title', 'excerpt', 'html' ),
		'additionalProperties' => false,
	);
	$system = 'You write blog posts for an accounting or bookkeeping firm\'s website, in the firm\'s voice ("we"). ' . bz_core_mkt_style_rules( $ctx );
	$user   = "Write a blog post for this idea.\n\n"
		. "- 450 to 700 words. html: simple HTML using only <p>, <h2>, <ul>, <li>, <strong> and <a>; no <h1>, no inline styles.\n"
		. "- Open with why it matters now, give practical steps, then a short section on how the firm helps, and end with a link to book a time (booking_url).\n"
		. "- Link to the idea's link if it has one.\n"
		. "- excerpt: one sentence for the post listing.\n\n"
		. "<idea>\n" . wp_json_encode( $item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n</idea>\n\n"
		. "<context>\n" . wp_json_encode( $ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n</context>";
	$out    = bz_core_mkt_claude( $system, $user, $schema );
	if ( is_wp_error( $out ) ) {
		return $out;
	}
	$out['html']   = wp_kses_post( $out['html'] );
	$out['source'] = 'claude';
	return $out;
}

/* ---------- Google (service only): service account → GA4 Data API + Search Console ---------- */

function bz_core_mkt_b64url( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

function bz_core_mkt_google_account() {
	if ( ! defined( 'BZ_GOOGLE_SA_KEY' ) || ! is_readable( BZ_GOOGLE_SA_KEY ) ) {
		return null;
	}
	$sa = json_decode( (string) file_get_contents( BZ_GOOGLE_SA_KEY ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return ( is_array( $sa ) && ! empty( $sa['private_key'] ) && ! empty( $sa['client_email'] ) ) ? $sa : null;
}

/* Why the service account key can't be used, in plain words, or '' when it can. Never shows the key itself. */
function bz_core_mkt_google_problem() {
	if ( ! defined( 'BZ_GOOGLE_SA_KEY' ) ) {
		return 'BZ_GOOGLE_SA_KEY is not defined. Check the line is in wp-config.php above "That\'s all, stop editing!" (anything below the wp-settings.php line runs too late).';
	}
	$path = (string) BZ_GOOGLE_SA_KEY;
	$base = (string) ini_get( 'open_basedir' );
	if ( $base ) {
		$allowed = false;
		foreach ( explode( PATH_SEPARATOR, $base ) as $dir ) {
			if ( '' !== $dir && 0 === strpos( $path, rtrim( $dir, '/' ) ) ) {
				$allowed = true;
			}
		}
		if ( ! $allowed ) {
			return 'PHP is only allowed to read files under: ' . $base . '. The key at ' . $path . ' is outside those folders.';
		}
	}
	if ( ! file_exists( $path ) ) {
		return 'No file at ' . $path . '. Check the folder and file name (the WordPress folder is ' . ABSPATH . ').';
	}
	if ( ! is_readable( $path ) ) {
		return 'The file at ' . $path . ' exists but PHP cannot read it. Set its permissions to 640 or 644.';
	}
	$sa = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! is_array( $sa ) ) {
		return 'The file at ' . $path . ' is not valid JSON. Upload the key file again, unchanged.';
	}
	if ( empty( $sa['private_key'] ) || empty( $sa['client_email'] ) ) {
		return 'The file at ' . $path . ' is JSON but not a service account key (no client_email/private_key).';
	}
	if ( ! function_exists( 'openssl_sign' ) ) {
		return 'PHP has no OpenSSL extension, which is needed to sign Google requests.';
	}
	return '';
}

function bz_core_mkt_google_token() {
	$cached = get_transient( 'bz_mkt_google_token' );
	if ( $cached ) {
		return $cached;
	}
	$sa = bz_core_mkt_google_account();
	if ( ! $sa ) {
		return new WP_Error( 'bz_mkt_no_google', 'No Google service account on the service.' );
	}
	$now    = time();
	$claims = array(
		'iss'   => $sa['client_email'],
		'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
		'aud'   => 'https://oauth2.googleapis.com/token',
		'iat'   => $now,
		'exp'   => $now + 3600,
	);
	$input  = bz_core_mkt_b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) . '.' . bz_core_mkt_b64url( wp_json_encode( $claims ) );
	$sig    = '';
	if ( ! openssl_sign( $input, $sig, $sa['private_key'], 'sha256WithRSAEncryption' ) ) {
		return new WP_Error( 'bz_mkt_sign', 'Could not sign the Google token request.' );
	}
	$response = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 20,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $input . '.' . bz_core_mkt_b64url( $sig ),
			),
		)
	);
	$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['access_token'] ) ) {
		return new WP_Error( 'bz_mkt_google_token', 'Google refused the token request.' );
	}
	set_transient( 'bz_mkt_google_token', $data['access_token'], 50 * MINUTE_IN_SECONDS );
	return $data['access_token'];
}

function bz_core_mkt_google_post( $url, $body ) {
	$token = bz_core_mkt_google_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'bz_mkt_google', isset( $data['error']['message'] ) ? $data['error']['message'] : 'Google API error' );
	}
	return $data;
}

/* Two 28-day windows ending yesterday (Search Console lags, so its windows end three days ago). */
function bz_core_mkt_windows( $lag_days = 1 ) {
	$end = time() - $lag_days * DAY_IN_SECONDS;
	return array(
		array( gmdate( 'Y-m-d', $end - 27 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $end ) ),
		array( gmdate( 'Y-m-d', $end - 55 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $end - 28 * DAY_IN_SECONDS ) ),
	);
}

function bz_core_mkt_service_stats( $site ) {
	$out = array(
		'visitors'      => null,
		'downloads'     => null,
		'leads'         => null,
		'search_clicks' => null,
		'notes'         => array(),
	);
	if ( ! empty( $site['ga4'] ) ) {
		$prop = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $site['ga4'] ) . ':runReport';
		foreach ( bz_core_mkt_windows() as $i => $w ) {
			$range = array( array( 'startDate' => $w[0], 'endDate' => $w[1] ) );
			$users = bz_core_mkt_google_post( $prop, array( 'dateRanges' => $range, 'metrics' => array( array( 'name' => 'activeUsers' ) ) ) );
			if ( is_wp_error( $users ) ) {
				$out['notes'][] = 'GA4: ' . $users->get_error_message();
				break;
			}
			$out['visitors'][ $i ] = isset( $users['rows'][0]['metricValues'][0]['value'] ) ? (int) $users['rows'][0]['metricValues'][0]['value'] : 0;
			$events                 = bz_core_mkt_google_post(
				$prop,
				array(
					'dateRanges'      => $range,
					'dimensions'      => array( array( 'name' => 'eventName' ) ),
					'metrics'         => array( array( 'name' => 'eventCount' ) ),
					'dimensionFilter' => array(
						'filter' => array(
							'fieldName'    => 'eventName',
							'inListFilter' => array( 'values' => array( 'file_download', 'generate_lead' ) ),
						),
					),
				)
			);
			$out['downloads'][ $i ] = 0;
			$out['leads'][ $i ]     = 0;
			foreach ( is_wp_error( $events ) || empty( $events['rows'] ) ? array() : $events['rows'] as $row ) {
				$key                 = 'file_download' === $row['dimensionValues'][0]['value'] ? 'downloads' : 'leads';
				$out[ $key ][ $i ] = (int) $row['metricValues'][0]['value'];
			}
		}
	} else {
		$out['notes'][] = 'No GA4 property set for this site.';
	}
	if ( ! empty( $site['gsc'] ) ) {
		$url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site['gsc'] ) . '/searchAnalytics/query';
		foreach ( bz_core_mkt_windows( 3 ) as $i => $w ) {
			$sc = bz_core_mkt_google_post( $url, array( 'startDate' => $w[0], 'endDate' => $w[1] ) );
			if ( is_wp_error( $sc ) ) {
				$out['notes'][] = 'Search Console: ' . $sc->get_error_message();
				break;
			}
			$out['search_clicks'][ $i ] = isset( $sc['rows'][0]['clicks'] ) ? (int) $sc['rows'][0]['clicks'] : 0;
		}
	} else {
		$out['notes'][] = 'No Search Console property set for this site.';
	}
	return $out;
}

/* ---------- Service: site register and REST routes ---------- */

function bz_core_mkt_sites() {
	return (array) get_option( 'bz_mkt_sites', array() );
}

function bz_core_mkt_key_hash( $key ) {
	return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
}

/* The calling site, from its X-Bizink-Site / X-Bizink-Key headers, or a WP_Error. */
function bz_core_mkt_caller( WP_REST_Request $r ) {
	$host  = strtolower( (string) $r->get_header( 'x_bizink_site' ) );
	$key   = (string) $r->get_header( 'x_bizink_key' );
	$sites = bz_core_mkt_sites();
	if ( '' === $host || '' === $key || empty( $sites[ $host ]['hash'] ) || ! hash_equals( $sites[ $host ]['hash'], bz_core_mkt_key_hash( $key ) ) ) {
		return new WP_Error( 'bz_mkt_auth', 'Unknown site or wrong key.', array( 'status' => 401 ) );
	}
	$sites[ $host ]['last_seen'] = time();
	update_option( 'bz_mkt_sites', $sites, false );
	return array( 'host' => $host ) + $sites[ $host ];
}

/* At most $limit calls of a kind per site per day. */
function bz_core_mkt_allow( $host, $kind, $limit ) {
	$key   = 'bz_mkt_n_' . md5( $host . $kind . gmdate( 'Ymd' ) );
	$count = (int) get_transient( $key );
	if ( $count >= $limit ) {
		return false;
	}
	set_transient( $key, $count + 1, DAY_IN_SECONDS );
	return true;
}

function bz_core_mkt_ctx_defaults( $ctx ) {
	$ctx = wp_parse_args(
		(array) $ctx,
		array( 'firm' => 'the firm', 'region' => 'AU', 'region_name' => 'Australia', 'offer' => '', 'booking_url' => '', 'today' => gmdate( 'Y-m-d' ) )
	);
	foreach ( array( 'tax_dates', 'resources', 'calculators', 'services', 'recent_posts' ) as $k ) {
		$ctx[ $k ] = isset( $ctx[ $k ] ) && is_array( $ctx[ $k ] ) ? array_values( array_filter( $ctx[ $k ], 'is_array' ) ) : array();
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ctx['today'] ) ) {
		$ctx['today'] = gmdate( 'Y-m-d' );
	}
	return $ctx;
}

function bz_core_mkt_service_plan( $host, $ctx ) {
	$ctx   = bz_core_mkt_ctx_defaults( $ctx );
	$rules = bz_core_mkt_rule_plan( $ctx );
	if ( ! bz_core_mkt_allow( $host, 'plan', 10 ) ) {
		return array( 'items' => $rules, 'source' => 'rules', 'note' => 'Daily limit for Claude plans reached; showing the rule-based plan.' );
	}
	$items = bz_core_mkt_claude_plan( $ctx, $rules );
	if ( is_wp_error( $items ) ) {
		return array( 'items' => $rules, 'source' => 'rules', 'note' => $items->get_error_message() );
	}
	return array( 'items' => $items, 'source' => 'claude', 'note' => '' );
}

function bz_core_mkt_service_draft( $host, $ctx, $item ) {
	$ctx = bz_core_mkt_ctx_defaults( $ctx );
	if ( ! bz_core_mkt_allow( $host, 'draft', 30 ) ) {
		return bz_core_mkt_template_draft( $item, $ctx ) + array( 'note' => 'Daily limit for Claude drafts reached; this is the template.' );
	}
	$draft = bz_core_mkt_claude_draft( $item, $ctx );
	return is_wp_error( $draft ) ? bz_core_mkt_template_draft( $item, $ctx ) + array( 'note' => $draft->get_error_message() ) : $draft;
}

function bz_core_mkt_service_cached_stats( $host, $site ) {
	$key   = 'bz_mkt_stats_' . md5( $host );
	$stats = get_transient( $key );
	if ( false === $stats ) {
		$stats            = bz_core_mkt_service_stats( $site );
		$stats['updated'] = time();
		set_transient( $key, $stats, 6 * HOUR_IN_SECONDS );
	}
	return $stats;
}

function bz_core_mkt_clean_item( $raw ) {
	$item = array();
	foreach ( array( 'date', 'type', 'title', 'why', 'angle', 'link' ) as $k ) {
		$item[ $k ] = isset( $raw[ $k ] ) ? sanitize_text_field( (string) $raw[ $k ] ) : '';
	}
	$item['link'] = esc_url_raw( $item['link'] );
	return $item;
}

add_action(
	'rest_api_init',
	function () {
		if ( ! bz_core_mkt_is_service() ) {
			return;
		}
		$site_route = function ( $callback ) {
			return array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // Checked per request by bz_core_mkt_caller().
				'callback'            => function ( WP_REST_Request $r ) use ( $callback ) {
					$site = bz_core_mkt_caller( $r );
					return is_wp_error( $site ) ? $site : rest_ensure_response( $callback( $site, $r ) );
				},
			);
		};
		register_rest_route(
			'bizink/v1',
			'/marketing/stats',
			$site_route(
				function ( $site, WP_REST_Request $r ) {
					$site = bz_core_mkt_adopt_props( $site, (string) $r->get_param( 'ga4' ), (string) $r->get_param( 'gsc' ) );
					return bz_core_mkt_service_cached_stats( $site['host'], $site );
				}
			)
		);
		register_rest_route(
			'bizink/v1',
			'/marketing/plan',
			$site_route(
				function ( $site, WP_REST_Request $r ) {
					return bz_core_mkt_service_plan( $site['host'], (array) $r->get_param( 'context' ) );
				}
			)
		);
		register_rest_route(
			'bizink/v1',
			'/marketing/draft',
			$site_route(
				function ( $site, WP_REST_Request $r ) {
					return bz_core_mkt_service_draft( $site['host'], (array) $r->get_param( 'context' ), bz_core_mkt_clean_item( (array) $r->get_param( 'item' ) ) );
				}
			)
		);
		// Register a site (or rotate its key) and set its Google properties. Returns the key once.
		register_rest_route(
			'bizink/v1',
			'/marketing/sites',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'callback'            => function () {
						$out = array();
						foreach ( bz_core_mkt_sites() as $host => $s ) {
							unset( $s['hash'] );
							$out[ $host ] = $s;
						}
						return rest_ensure_response( $out );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'callback'            => function ( WP_REST_Request $r ) {
						$host = strtolower( (string) wp_parse_url( 'https://' . preg_replace( '#^https?://#', '', (string) $r->get_param( 'host' ) ), PHP_URL_HOST ) );
						if ( ! $host ) {
							return new WP_Error( 'bz_mkt_host', 'host is required', array( 'status' => 400 ) );
						}
						$sites = bz_core_mkt_sites();
						$site  = isset( $sites[ $host ] ) ? $sites[ $host ] : array( 'added' => time() );
						foreach ( array( 'label', 'ga4', 'gsc' ) as $k ) {
							if ( null !== $r->get_param( $k ) ) {
								$site[ $k ] = sanitize_text_field( (string) $r->get_param( $k ) );
							}
						}
						$key = '';
						if ( empty( $site['hash'] ) || $r->get_param( 'rotate' ) ) {
							$key          = wp_generate_password( 40, false );
							$site['hash'] = bz_core_mkt_key_hash( $key );
						}
						$sites[ $host ] = $site;
						update_option( 'bz_mkt_sites', $sites, false );
						delete_transient( 'bz_mkt_stats_' . md5( $host ) );
						return rest_ensure_response( array( 'host' => $host, 'key' => $key ) );
					},
				),
			)
		);
	}
);

/* ---------- Client: calls to the service (direct on the master itself) ---------- */

/**
 * A site's GA4 property and Search Console property, as entered in its Client Details. The service takes them the
 * first time it sees them (trust on first use); after that only Tools → Marketing sites on the master changes them,
 * so a site can't point itself at another firm's analytics.
 */
function bz_core_mkt_adopt_props( $site, $ga4, $gsc ) {
	$changed  = false;
	$ga4      = preg_replace( '/\D/', '', $ga4 );
	$gsc      = trim( sanitize_text_field( $gsc ) );
	$gsc_ok   = '#^(sc-domain:[a-z0-9.-]+|https?://\S+)$#i';
	$reported = array( 'site_ga4' => $ga4, 'site_gsc' => $gsc );
	// Empty, or not a valid value (e.g. a typo such as "https;//"): nothing to protect, so take the site's.
	if ( ( empty( $site['ga4'] ) || ! ctype_digit( (string) $site['ga4'] ) ) && '' !== $ga4 ) {
		$site['ga4'] = $ga4;
		$changed     = true;
	}
	if ( ( empty( $site['gsc'] ) || ! preg_match( $gsc_ok, $site['gsc'] ) ) && preg_match( $gsc_ok, $gsc ) ) {
		$site['gsc'] = $gsc;
		$changed     = true;
	}
	// Remember what the site says, so Tools → Marketing sites can flag a difference to review.
	$sites = bz_core_mkt_sites();
	if ( isset( $sites[ $site['host'] ] ) && array_intersect_key( $sites[ $site['host'] ], $reported ) !== $reported ) {
		$sites[ $site['host'] ] = $reported + $sites[ $site['host'] ];
		update_option( 'bz_mkt_sites', $sites, false );
	}
	if ( $changed ) {
		$sites = bz_core_mkt_sites();
		$host  = $site['host'];
		if ( isset( $sites[ $host ] ) || bz_core_mkt_is_service() ) {
			$stored         = isset( $sites[ $host ] ) ? $sites[ $host ] : array( 'added' => time(), 'label' => bz_core_get( 'firm_name' ) );
			$stored['ga4']  = isset( $site['ga4'] ) ? $site['ga4'] : '';
			$stored['gsc']  = isset( $site['gsc'] ) ? $site['gsc'] : '';
			$sites[ $host ] = $stored;
			update_option( 'bz_mkt_sites', $sites, false );
		}
		delete_transient( 'bz_mkt_stats_' . md5( $host ) );
	}
	return $site;
}

/* The site key (Marketing → Connection) plus the GA4 measurement ID from Client Details. */
function bz_core_mkt_settings() {
	$s   = wp_parse_args( (array) get_option( 'bz_mkt_settings', array() ), array( 'key' => '', 'ga4_id' => '' ) );
	$tag = strtoupper( bz_core_get( 'ga4_measurement_id' ) );
	if ( preg_match( '/^G-[A-Z0-9]+$/', $tag ) ) {
		$s['ga4_id'] = $tag;
	}
	return $s;
}

/* Save a GA4 measurement ID into Client Details (empty or invalid clears it). */
function bz_core_mkt_set_tag( $value ) {
	$value = strtoupper( trim( (string) $value ) );
	$opts  = (array) get_option( BZ_CORE_OPTION, array() );
	$opts['ga4_measurement_id'] = preg_match( '/^G-[A-Z0-9]+$/', $value ) ? $value : '';
	update_option( BZ_CORE_OPTION, bz_core_sanitize( $opts ) );
	$s           = (array) get_option( 'bz_mkt_settings', array() );
	$s['ga4_id'] = '';
	update_option( 'bz_mkt_settings', $s, false );
	return $opts['ga4_measurement_id'];
}

function bz_core_mkt_call( $route, $body = array() ) {
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( 'stats' === $route ) {
		$body += array( 'ga4' => bz_core_get( 'ga4_property_id' ), 'gsc' => bz_core_get( 'search_console' ) );
	}
	if ( bz_core_mkt_is_service() ) {
		$sites = bz_core_mkt_sites();
		$site  = array( 'host' => $host ) + ( isset( $sites[ $host ] ) ? $sites[ $host ] : array() );
		switch ( $route ) {
			case 'stats':
				$site = bz_core_mkt_adopt_props( $site, $body['ga4'], $body['gsc'] );
				return bz_core_mkt_service_cached_stats( $host, $site );
			case 'plan':
				return bz_core_mkt_service_plan( $host, $body['context'] );
			case 'draft':
				return bz_core_mkt_service_draft( $host, $body['context'], $body['item'] );
		}
	}
	$key = bz_core_mkt_settings()['key'];
	if ( '' === $key ) {
		return new WP_Error( 'bz_mkt_not_connected', 'This site is not connected to the Bizink marketing service yet.' );
	}
	$response = bz_core_master_request(
		BZ_MARKETING_SERVICE . '/wp-json/bizink/v1/marketing/' . $route,
		array(
			'method'  => 'POST',
			'timeout' => 'stats' === $route ? 45 : 220,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'X-Bizink-Site' => $host,
				'X-Bizink-Key'  => $key,
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ) {
		return new WP_Error( 'bz_mkt_service', isset( $data['message'] ) ? $data['message'] : 'The marketing service did not answer.' );
	}
	return $data;
}

/* Local enquiry count from Gravity Forms (spam and trash excluded), for both windows. */
function bz_core_mkt_local_enquiries() {
	if ( ! class_exists( 'GFAPI' ) ) {
		return null;
	}
	$out = array();
	foreach ( bz_core_mkt_windows() as $i => $w ) {
		$out[ $i ] = (int) GFAPI::count_entries(
			0,
			array(
				'status'     => 'active',
				'start_date' => $w[0],
				'end_date'   => $w[1],
			)
		);
	}
	return $out;
}

function bz_core_mkt_stats( $refresh = false ) {
	$stats = get_transient( 'bz_mkt_stats' );
	if ( false === $stats || $refresh ) {
		$remote = bz_core_mkt_call( 'stats' );
		$stats  = is_wp_error( $remote ) ? array( 'notes' => array( $remote->get_error_message() ) ) : $remote;
		$local  = bz_core_mkt_local_enquiries();
		if ( null !== $local ) {
			$stats['leads'] = $local;
		}
		$stats['fetched'] = time();
		set_transient( 'bz_mkt_stats', $stats, is_wp_error( $remote ) ? HOUR_IN_SECONDS : 6 * HOUR_IN_SECONDS );
	}
	return $stats;
}

function bz_core_mkt_plan() {
	return wp_parse_args( (array) get_option( 'bz_mkt_plan', array() ), array( 'items' => array(), 'source' => '', 'note' => '', 'generated' => 0 ) );
}

function bz_core_mkt_save_plan( $result ) {
	$items = array();
	foreach ( (array) $result['items'] as $raw ) {
		$item       = bz_core_mkt_clean_item( $raw );
		$item['id'] = substr( md5( $item['date'] . $item['title'] ), 0, 10 );
		$items[]    = $item;
	}
	// Keep drafts made from the old plan that are still upcoming, so a refresh never loses work.
	foreach ( bz_core_mkt_plan()['items'] as $old ) {
		if ( ! empty( $old['post_id'] ) && get_post( $old['post_id'] ) && $old['date'] >= gmdate( 'Y-m-d', time() - 14 * DAY_IN_SECONDS ) ) {
			$items[] = $old;
		}
	}
	usort(
		$items,
		function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		}
	);
	update_option(
		'bz_mkt_plan',
		array(
			'items'     => $items,
			'source'    => $result['source'],
			'note'      => isset( $result['note'] ) ? $result['note'] : '',
			'generated' => time(),
		),
		false
	);
}

function bz_core_mkt_refresh_plan() {
	$ctx    = bz_core_mkt_context();
	$result = bz_core_mkt_call( 'plan', array( 'context' => $ctx ) );
	if ( is_wp_error( $result ) || empty( $result['items'] ) ) {
		$result = array(
			'items'  => bz_core_mkt_rule_plan( $ctx ),
			'source' => 'rules',
			'note'   => is_wp_error( $result ) ? $result->get_error_message() : '',
		);
	}
	bz_core_mkt_save_plan( $result );
}

/* ---------- Client: role, menu, dashboard ---------- */

add_action(
	'admin_init',
	function () {
		if ( '2' === get_option( 'bz_mkt_roles' ) ) {
			return;
		}
		remove_role( 'bz_client' ); // Re-created to rename it; users keep the role.
		add_role(
			'bz_client',
			'Company admin',
			array(
				'read'                   => true,
				'edit_posts'             => true,
				'publish_posts'          => true,
				'edit_published_posts'   => true,
				'delete_posts'           => true,
				'upload_files'           => true,
				'bz_marketing'           => true,
			)
		);
		foreach ( array( 'administrator', 'editor' ) as $name ) {
			$role = get_role( $name );
			if ( $role ) {
				$role->add_cap( 'bz_marketing' );
			}
		}
		update_option( 'bz_mkt_roles', '2' );
	}
);

function bz_core_mkt_is_client_user() {
	$user = wp_get_current_user();
	return $user && in_array( 'bz_client', (array) $user->roles, true );
}

add_action(
	'admin_menu',
	function () {
		$title = bz_core_mkt_enabled() ? 'Marketing assistant' : 'Your website';
		add_menu_page( $title, $title, 'bz_marketing', 'bz-marketing', 'bz_core_render_marketing', 'dashicons-chart-line', 2 );
		if ( bz_core_mkt_is_client_user() ) {
			foreach ( array( 'index.php', 'edit-comments.php', 'tools.php', 'edit.php?post_type=bz_resource', 'edit.php?post_type=bz_calculator', 'edit.php?post_type=bz_team', 'edit.php?post_type=bz_feedback', 'edit.php?post_type=elementor_library', 'elementor' ) as $slug ) {
				remove_menu_page( $slug );
			}
		}
	},
	999
);

// The Marketing assistant is the Company admin role's dashboard.
add_action(
	'load-index.php',
	function () {
		if ( bz_core_mkt_is_client_user() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bz-marketing' ) );
			exit;
		}
	}
);
add_filter(
	'login_redirect',
	function ( $to, $requested, $user ) {
		return ( $user instanceof WP_User && in_array( 'bz_client', (array) $user->roles, true ) && admin_url() === $to ) ? admin_url( 'admin.php?page=bz-marketing' ) : $to;
	},
	10,
	3
);

/* ---------- Client: actions ---------- */

function bz_core_mkt_back( $msg = '' ) {
	wp_safe_redirect( add_query_arg( array( 'page' => 'bz-marketing', 'bz_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action(
	'admin_post_bz_mkt_refresh',
	function () {
		if ( ! bz_core_mkt_enabled() ) {
			wp_die( 'The Marketing assistant is not included in this site\'s package.' );
		}
		check_admin_referer( 'bz_mkt_refresh' );
		if ( ! current_user_can( 'bz_marketing' ) ) {
			wp_die( 'Not allowed.' );
		}
		bz_core_mkt_refresh_plan();
		bz_core_mkt_back( 'Plan refreshed.' );
	}
);

add_action(
	'admin_post_bz_mkt_stats',
	function () {
		check_admin_referer( 'bz_mkt_stats' );
		if ( ! current_user_can( 'bz_marketing' ) ) {
			wp_die( 'Not allowed.' );
		}
		bz_core_mkt_stats( true );
		bz_core_mkt_back( 'Numbers refreshed.' );
	}
);

add_action(
	'admin_post_bz_mkt_draft',
	function () {
		if ( ! bz_core_mkt_enabled() ) {
			wp_die( 'The Marketing assistant is not included in this site\'s package.' );
		}
		$id = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
		check_admin_referer( 'bz_mkt_draft_' . $id );
		if ( ! current_user_can( 'bz_marketing' ) || ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Not allowed.' );
		}
		$plan = bz_core_mkt_plan();
		foreach ( $plan['items'] as $i => $item ) {
			if ( $item['id'] !== $id ) {
				continue;
			}
			if ( ! empty( $item['post_id'] ) && get_post( $item['post_id'] ) ) {
				wp_safe_redirect( get_edit_post_link( $item['post_id'], 'raw' ) );
				exit;
			}
			$ctx   = bz_core_mkt_context();
			$draft = bz_core_mkt_call( 'draft', array( 'context' => $ctx, 'item' => $item ) );
			if ( is_wp_error( $draft ) || empty( $draft['html'] ) ) {
				$draft = bz_core_mkt_template_draft( $item, $ctx );
			}
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_author'  => get_current_user_id(),
					'post_title'   => bz_core_mkt_post_title( sanitize_text_field( $draft['title'] ) ),
					'post_excerpt' => sanitize_text_field( $draft['excerpt'] ),
					'post_content' => wp_kses_post( $draft['html'] ),
					'meta_input'   => array(
						'bz_mkt_item'   => $item['id'],
						'bz_mkt_source' => isset( $draft['source'] ) ? $draft['source'] : 'template',
					),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				bz_core_mkt_back( 'Could not create the draft: ' . $post_id->get_error_message() );
			}
			$plan['items'][ $i ]['post_id'] = $post_id;
			update_option( 'bz_mkt_plan', $plan, false );
			wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
			exit;
		}
		bz_core_mkt_back( 'That idea is no longer in the plan.' );
	}
);

add_action(
	'admin_post_bz_mkt_settings',
	function () {
		check_admin_referer( 'bz_mkt_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$s   = (array) get_option( 'bz_mkt_settings', array() );
		$key = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
		if ( '' !== $key ) {
			$s['key'] = $key;
			update_option( 'bz_mkt_settings', $s, false );
		}
		delete_transient( 'bz_mkt_stats' );
		bz_core_mkt_back( 'Settings saved.' );
	}
);

// Scripted connection (tools/marketing.py): POST {key, ga4_id}. GET returns whether a key is set and a stats check.
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bizink/v1',
			'/marketing/connect',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'callback'            => function ( WP_REST_Request $r ) {
						if ( $r->get_param( 'key' ) ) {
							$raw        = (array) get_option( 'bz_mkt_settings', array() );
							$raw['key'] = sanitize_text_field( (string) $r->get_param( 'key' ) );
							update_option( 'bz_mkt_settings', $raw, false );
						}
						if ( null !== $r->get_param( 'ga4_id' ) ) {
							bz_core_mkt_set_tag( $r->get_param( 'ga4_id' ) );
						}
						delete_transient( 'bz_mkt_stats' );
						$s = bz_core_mkt_settings();
						return rest_ensure_response( array( 'connected' => '' !== $s['key'], 'ga4_id' => $s['ga4_id'] ) );
					},
				),
				array(
					'methods'             => 'GET',
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'callback'            => function () {
						$s    = bz_core_mkt_settings();
						$plan = bz_core_mkt_plan();
						return rest_ensure_response(
							array(
								'service'   => BZ_MARKETING_SERVICE,
								'connected' => '' !== $s['key'] || bz_core_mkt_is_service(),
								'ga4_id'    => $s['ga4_id'],
								'stats'     => bz_core_mkt_stats( true ),
								'plan'      => array( 'source' => $plan['source'], 'items' => count( $plan['items'] ), 'generated' => $plan['generated'] ? gmdate( 'c', $plan['generated'] ) : null ),
							)
						);
					},
				),
			)
		);
	}
);

/* ---------- Front end: GA4 tag (when set) with an enquiry event for Gravity Forms ---------- */

/*
 * The GA4 measurement ID to tag pages with, or ''. The tag stays off while "Discourage search engines" is on, so a
 * staging site can hold the live property's ID from set-up without its test visits counting; it starts at launch.
 */
function bz_core_ga4_tag_id() {
	$id = bz_core_mkt_settings()['ga4_id'];
	return ( $id && '1' === (string) get_option( 'blog_public' ) && apply_filters( 'bz_ga4_tag_active', true ) ) ? $id : '';
}

add_action(
	'wp_head',
	function () {
		$id = bz_core_ga4_tag_id();
		if ( ! $id || current_user_can( 'edit_posts' ) ) {
			return;
		}
		// With the cookie banner on "Ask for consent" these print as text/plain and only run after Accept.
		$gate = function_exists( 'bz_core_cookie_gate' ) ? 'bz_core_cookie_gate' : function ( $h ) { return $h; };
		echo $gate( "<script async src=\"https://www.googletagmanager.com/gtag/js?id=" . esc_attr( $id ) . "\"></script>\n" ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $gate( "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js( $id ) . "');"
			. "if(window.jQuery){jQuery(document).on('gform_confirmation_loaded',function(e,id){gtag('event','generate_lead',{form_id:id});});}</script>\n" ); // phpcs:ignore WordPress.Security.EscapeOutput
	},
	5
);

// Non-AJAX Gravity Forms confirmations: fire the lead event on the confirmation page load.
add_filter(
	'gform_confirmation',
	function ( $confirmation, $form ) {
		if ( is_string( $confirmation ) && bz_core_ga4_tag_id() ) {
			$confirmation .= "<script>if(window.gtag){gtag('event','generate_lead',{form_id:" . (int) $form['id'] . '});}</script>';
		}
		return $confirmation;
	},
	10,
	2
);

/* ---------- Client: the hub page ---------- */

function bz_core_mkt_card( $label, $icon, $pair, $help ) {
	echo '<div class="bzm-card"><div class="bzm-card-top"><span class="bzm-icon dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span><span class="bzm-label">' . esc_html( $label ) . '</span></div>';
	if ( ! is_array( $pair ) || ! isset( $pair[0] ) ) {
		echo '<div class="bzm-num bzm-none">–</div><div class="bzm-delta">' . esc_html( $help ) . '</div></div>';
		return;
	}
	$now  = (int) $pair[0];
	$prev = isset( $pair[1] ) ? (int) $pair[1] : 0;
	echo '<div class="bzm-num">' . esc_html( number_format_i18n( $now ) ) . '</div>';
	if ( $prev > 0 ) {
		$pct = round( ( $now - $prev ) / $prev * 100 );
		$cls = $pct > 0 ? 'up' : ( $pct < 0 ? 'down' : 'flat' );
		echo '<div class="bzm-delta"><span class="bzm-pill ' . esc_attr( $cls ) . '">' . esc_html( ( $pct > 0 ? '▲ ' : ( $pct < 0 ? '▼ ' : '' ) ) . abs( $pct ) . '%' ) . '</span> vs previous 28 days</div>';
	} else {
		echo '<div class="bzm-delta">' . esc_html( 'Previous 28 days: ' . number_format_i18n( $prev ) ) . '</div>';
	}
	echo '</div>';
}

/* The site's own colours and fonts (Elementor kit) on the Marketing assistant page. */
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'toplevel_page_bz-marketing' === $hook && function_exists( 'bz_core_kit_assets' ) ) {
			bz_core_kit_assets( 'bz-mkt' );
		}
	}
);

if ( ! defined( 'BZ_NEWS_FEED' ) ) {
	define( 'BZ_NEWS_FEED', 'https://www.bizinkonline.com/feed/' );
}
if ( ! defined( 'BZ_NEWS_SITE' ) ) {
	define( 'BZ_NEWS_SITE', 'https://www.bizinkonline.com/blog/' );
}

/* Latest Bizink blog posts for the Marketing assistant: [{title, link, date, excerpt}], cached 12 hours. */
function bz_core_mkt_news( $count = 4 ) {
	$cached = get_transient( 'bz_mkt_news' );
	if ( is_array( $cached ) ) {
		return array_slice( $cached, 0, $count );
	}
	include_once ABSPATH . WPINC . '/feed.php';
	$feed = fetch_feed( BZ_NEWS_FEED );
	$out  = array();
	if ( ! is_wp_error( $feed ) ) {
		foreach ( $feed->get_items( 0, 8 ) as $item ) {
			$text  = wp_strip_all_tags( (string) $item->get_description() );
			$text  = preg_replace( '/\s*(Read More\.\.\..*|The post .* appeared first on .*)$/s', '', $text );
			$out[] = array(
				'title'   => html_entity_decode( (string) $item->get_title(), ENT_QUOTES ),
				'link'    => esc_url_raw( (string) $item->get_permalink() ),
				'date'    => (string) $item->get_date( 'j M Y' ),
				'excerpt' => wp_trim_words( html_entity_decode( $text, ENT_QUOTES ), 22, '…' ),
			);
		}
	}
	set_transient( 'bz_mkt_news', $out, $out ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return array_slice( $out, 0, $count );
}

/* Is the Marketing assistant (plan, drafts, lead magnets) in this site's package? Stats show either way. */
function bz_core_mkt_enabled() {
	return ( ! function_exists( 'bz_core_feature' ) || bz_core_feature( 'marketing' ) );
}

function bz_core_mkt_css() {
	return '
	#wpcontent,#wpbody-content{background:#F4F5F9}
	.bzm{--p:var(--color-primary,#333B61);--pd:var(--color-primary-dark,#252b49);--acc:var(--color-accent,#FCB813);--ink:#141826;--txt:#4a5068;--mut:#7a8099;--line:#e6e8f0;--card:#fff;--r:14px;
		max-width:1120px;margin:0;padding:24px 24px 48px 4px;font-family:var(--font-body,system-ui),-apple-system,"Segoe UI",sans-serif;color:var(--txt)}
	.bzm *{box-sizing:border-box}
	.bzm h1,.bzm h2,.bzm h3{font-family:var(--font-heading,system-ui),system-ui,sans-serif;color:var(--ink);letter-spacing:-.01em}
	.bzm-hero{position:relative;overflow:hidden;border-radius:20px;padding:30px 32px;margin:0 0 22px;color:#fff;
		background:radial-gradient(120% 140% at 100% 0%,color-mix(in srgb,var(--p) 55%,#fff 0%) 0%,var(--p) 45%,var(--pd) 100%)}
	.bzm-hero::after{content:"";position:absolute;right:-60px;top:-60px;width:240px;height:240px;border-radius:50%;background:color-mix(in srgb,#fff 10%,transparent)}
	.bzm-hero-row{position:relative;z-index:1;display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap}
	.bzm-hero h2{color:#fff;font-size:30px;line-height:1.15;margin:0 0 6px;padding:0;font-weight:700}
	.bzm-hero p{margin:0;color:color-mix(in srgb,#fff 82%,transparent);font-size:15px;max-width:52ch}
	.bzm-eyebrow{display:inline-block;font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:color-mix(in srgb,#fff 70%,transparent);margin:0 0 8px}
	.bzm-help{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
	.bzm-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;font:600 13px/1 inherit;font-family:inherit;padding:10px 16px;border-radius:999px;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:background .15s,border-color .15s,color .15s;white-space:nowrap}
	.bzm-btn:focus-visible{outline:2px solid var(--acc);outline-offset:2px}
	.bzm-btn.primary{background:var(--p);color:#fff}.bzm-btn.primary:hover{background:var(--pd);color:#fff}
	.bzm-btn.ghost{background:transparent;color:var(--p);border-color:color-mix(in srgb,var(--p) 30%,transparent)}.bzm-btn.ghost:hover{background:color-mix(in srgb,var(--p) 8%,transparent);color:var(--p)}
	.bzm-btn.light{background:color-mix(in srgb,#fff 14%,transparent);color:#fff;border-color:color-mix(in srgb,#fff 35%,transparent)}.bzm-btn.light:hover{background:color-mix(in srgb,#fff 24%,transparent);color:#fff}
	.bzm-btn.accent{background:var(--acc);color:#141826}.bzm-btn.accent:hover{filter:brightness(1.06);color:#141826}
	.bzm-btn.link{background:none;border:0;padding:0;color:var(--p);font-weight:600}.bzm-btn.link:hover{text-decoration:underline}
	.bzm-btn[disabled]{background:#f1f2f6;color:#a3a8bb;border-color:transparent;cursor:default}
	.bzm-inline{display:inline}
	.bzm-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:0 0 10px}
	.bzm-card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:18px 18px 16px;box-shadow:0 1px 2px rgba(20,24,38,.04)}
	.bzm-card-top{display:flex;align-items:center;gap:10px}
	.bzm-icon{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:18px;background:color-mix(in srgb,var(--p) 10%,#fff);color:var(--p)}
	.bzm-label{font-size:13px;font-weight:600;color:var(--txt)}
	.bzm-num{font-family:var(--font-heading,system-ui),system-ui,sans-serif;font-size:36px;font-weight:700;line-height:1;margin:16px 0 8px;color:var(--ink);letter-spacing:-.02em}
	.bzm-none{color:#c3c7d4}
	.bzm-delta{font-size:12px;color:var(--mut)}
	.bzm-pill{display:inline-block;font-weight:700;padding:3px 8px;border-radius:999px;margin-right:4px;background:#f1f2f6;color:var(--txt)}
	.bzm-pill.up{background:#e3f6ea;color:#11743a}.bzm-pill.down{background:#fde8e8;color:#b42318}
	.bzm-meta{font-size:12px;color:var(--mut);margin:0 0 30px;display:flex;gap:14px;align-items:center;flex-wrap:wrap}
	.bzm-meta .bzm-btn.link{font-size:12px}
	.bzm-panel{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:24px 26px 10px}
	.bzm-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin:0 0 4px;flex-wrap:wrap}
	.bzm-head h2{margin:0;font-size:22px;font-weight:700}
	.bzm-sub{color:var(--mut);margin:6px 0 0;font-size:13px}
	.bzm-month{display:flex;align-items:center;gap:10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--p);margin:26px 0 12px}
	.bzm-month::after{content:"";flex:1;height:1px;background:var(--line)}
	.bzm-list{position:relative;padding-left:22px}
	.bzm-list::before{content:"";position:absolute;left:6px;top:8px;bottom:8px;width:2px;background:color-mix(in srgb,var(--p) 14%,#fff);border-radius:2px}
	.bzm-item{position:relative;display:grid;grid-template-columns:78px 1fr 168px;gap:18px;align-items:start;background:#fbfbfd;border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;margin:0 0 12px;transition:border-color .15s,box-shadow .15s}
	.bzm-item:hover{border-color:color-mix(in srgb,var(--p) 35%,var(--line));box-shadow:0 6px 20px rgba(20,24,38,.06);background:#fff}
	.bzm-item::before{content:"";position:absolute;left:-21px;top:22px;width:12px;height:12px;border-radius:50%;background:#fff;border:3px solid var(--p)}
	.bzm-date{font-family:var(--font-heading,system-ui),system-ui,sans-serif;font-size:22px;font-weight:700;color:var(--ink);line-height:1}
	.bzm-date span{display:block;font-family:var(--font-body,system-ui),system-ui,sans-serif;font-size:12px;font-weight:500;color:var(--mut);margin-top:6px}
	.bzm-item h3{margin:6px 0 4px;font-size:16px;line-height:1.35;font-weight:700}
	.bzm-item p{margin:4px 0;color:var(--txt);font-size:13.5px;line-height:1.55}
	.bzm-item details{font-size:13px;color:var(--mut);margin-top:6px}
	.bzm-item summary{cursor:pointer;color:var(--p);font-weight:600}
	.bzm-badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.02em;padding:3px 9px;border-radius:999px;background:#eef0f5;color:var(--txt)}
	.bzm-badge.deadline{background:#fdecec;color:#a8251c}.bzm-badge.seasonal{background:#fff5d6;color:#7a5600}.bzm-badge.resource,.bzm-badge.calculator{background:#e3f6ea;color:#11743a}.bzm-badge.service{background:color-mix(in srgb,var(--p) 10%,#fff);color:var(--p)}
	.bzm-actions{display:grid;grid-template-columns:1fr 1fr;gap:6px}
	.bzm-actions>form,.bzm-actions>a,.bzm-soon{grid-column:1/-1}
	.bzm-actions .bzm-btn{width:100%}
	.bzm-actions .bzm-btn[disabled]{padding:8px 6px;font-size:12px}
	.bzm-soon{font-size:11px;color:#a3a8bb;text-align:center;margin-top:2px}
	.bzm-empty{padding:20px 0;color:var(--mut)}
	.bzm-lm{margin-top:22px;padding-bottom:22px}
	.bzm-lm-list{display:grid;gap:10px;margin:14px 0 4px}
	.bzm-lm-item{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;background:#fbfbfd;border:1px solid var(--line);border-radius:var(--r);padding:14px 16px}
	.bzm-lm-item strong{display:block;color:var(--ink);font-size:15px}.bzm-lm-item span{font-size:12.5px;color:var(--mut)}
	.bzm-lm-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
	.bzm-lm-new{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px;font-size:13.5px}
	.bzm-lm-new select{min-height:38px;border:1px solid var(--line);border-radius:8px;padding:0 10px;max-width:420px}
	.bzm-plan-box{margin-top:22px;background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:16px 20px;display:grid;gap:4px}
	.bzm-plan-box strong{font-size:18px;color:var(--ink)}
	.bzm-plan-box p{margin:8px 0 0;font-size:13.5px;line-height:1.6}
	.bzm-plan-box summary{cursor:pointer;color:var(--p);font-weight:600;font-size:13px}
	.bzm-service{font-size:13.5px;line-height:1.6;margin-top:8px}.bzm-service h3,.bzm-service h4{font-size:14px;margin:14px 0 4px;color:var(--ink)}.bzm-service ul,.bzm-service ol{margin:4px 0 8px 20px}.bzm-service ul{list-style:disc}.bzm-service p{margin:6px 0}
	.bzm-news{margin-top:28px;scroll-margin-top:48px}
	html{scroll-behavior:smooth}
	.bzm-news-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:12px}
	.bzm-news-card{display:flex;flex-direction:column;gap:6px;background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:16px;text-decoration:none;color:var(--txt);transition:border-color .15s,box-shadow .15s}
	.bzm-news-card:hover{border-color:color-mix(in srgb,var(--p) 35%,var(--line));box-shadow:0 6px 20px rgba(20,24,38,.06);color:var(--txt)}
	.bzm-news-card strong{font-family:var(--font-heading,system-ui),system-ui,sans-serif;color:var(--ink);font-size:15px;line-height:1.3}
	.bzm-news-card span:last-child{font-size:13px;line-height:1.5;color:var(--mut)}
	.bzm-news-date{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--p)}
	@media (max-width:1000px){.bzm-news-grid{grid-template-columns:1fr 1fr}}
	@media (max-width:600px){.bzm-news-grid{grid-template-columns:1fr}}
	.bzm-settings{margin-top:28px;background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:4px 20px}
	.bzm-settings summary{padding:14px 0;cursor:pointer;font-weight:600;color:var(--ink)}
	.bzm .notice{border-radius:10px}
	@media (max-width:1000px){.bzm-cards{grid-template-columns:1fr 1fr}.bzm-item{grid-template-columns:1fr}.bzm-actions{grid-template-columns:auto auto auto;justify-content:start}.bzm-actions>form,.bzm-actions>a{grid-column:auto}.bzm-actions .bzm-btn{width:auto}}
	@media (max-width:600px){.bzm{padding:12px 0 32px}.bzm-hero{padding:24px 20px}.bzm-cards{grid-template-columns:1fr}.bzm-panel{padding:18px 16px 6px}}
	';
}

function bz_core_render_marketing() {
	$plan  = bz_core_mkt_plan();
	if ( ! $plan['items'] && ! $plan['generated'] ) {
		bz_core_mkt_save_plan( array( 'items' => bz_core_mkt_rule_plan( bz_core_mkt_context() ), 'source' => 'rules' ) );
		$plan = bz_core_mkt_plan();
	}
	$stats = bz_core_mkt_stats();
	$types = bz_core_mkt_types();
	$msg   = isset( $_GET['bz_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bz_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$form  = function ( $action, $label, $class = 'bzm-btn ghost', $extra = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bzm-inline">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">' . $extra; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	};
	echo '<style>' . bz_core_mkt_css() . bz_core_support_dialog_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS.
	?>
	<div class="wrap bzm">
		<h1 class="screen-reader-text">Marketing assistant</h1>
		<div class="bzm-hero">
			<div class="bzm-hero-row">
				<div>
					<span class="bzm-eyebrow"><?php echo esc_html( bz_core_get( 'firm_name' ) ); ?></span>
					<h2><?php echo esc_html( bz_core_mkt_enabled() ? 'Marketing assistant' : 'Your website' ); ?></h2>
					<p><?php echo esc_html( bz_core_mkt_enabled() ? 'How your website is doing, and ideas for what to post next.' : 'How your website is doing, plus help and news from Bizink.' ); ?></p>
				</div>
				<div class="bzm-help">
					<a class="bzm-btn light" href="<?php echo esc_url( BZ_SUPPORT_KB ); ?>" target="_blank" rel="noopener">Knowledge base</a>
					<?php if ( bz_core_mkt_news() ) : ?>
						<a class="bzm-btn light" href="#bzm-news">Bizink latest news</a>
					<?php endif; ?>
					<a class="bzm-btn accent" href="<?php echo esc_url( BZ_SUPPORT_FORM ); ?>" target="_blank" rel="noopener" data-bzl-ticket>Create a ticket</a>
				</div>
			</div>
		</div>
		<?php if ( $msg ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>

		<div class="bzm-cards">
			<?php
			bz_core_mkt_card( 'Visitors', 'groups', isset( $stats['visitors'] ) ? $stats['visitors'] : null, 'Shows once Google Analytics is connected.' );
			bz_core_mkt_card( 'Enquiries', 'email-alt', isset( $stats['leads'] ) ? $stats['leads'] : null, 'Form enquiries from the website.' );
			bz_core_mkt_card( 'Downloads', 'download', isset( $stats['downloads'] ) ? $stats['downloads'] : null, 'Shows once Google Analytics is connected.' );
			bz_core_mkt_card( 'Google search clicks', 'search', isset( $stats['search_clicks'] ) ? $stats['search_clicks'] : null, 'Shows once Search Console is connected.' );
			?>
		</div>
		<div class="bzm-meta">
			<span>Last 28 days. <?php echo ! empty( $stats['fetched'] ) ? esc_html( 'Updated ' . human_time_diff( $stats['fetched'] ) . ' ago.' ) : ''; ?></span>
			<?php $form( 'bz_mkt_stats', 'Refresh numbers', 'bzm-btn link' ); ?>
			<?php if ( current_user_can( 'manage_options' ) && ! empty( $stats['notes'] ) ) : ?>
				<span><?php echo esc_html( implode( ' ', array_unique( $stats['notes'] ) ) ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( bz_core_mkt_enabled() ) : ?>
		<div class="bzm-panel">
			<div class="bzm-head">
				<div>
					<h2>Your next 90 days</h2>
					<p class="bzm-sub">
						<?php
						echo esc_html(
							( 'claude' === $plan['source'] ? 'Suggested by Claude from your tax dates, downloads, calculators and services.' : 'Built from your tax dates, downloads, calculators and services.' )
							. ( $plan['generated'] ? ' Updated ' . human_time_diff( $plan['generated'] ) . ' ago.' : '' )
						);
						?>
					</p>
				</div>
				<?php $form( 'bz_mkt_refresh', 'Refresh plan' ); ?>
			</div>
			<?php
			if ( current_user_can( 'manage_options' ) && $plan['note'] ) {
				echo '<p class="bzm-sub">' . esc_html( 'Note: ' . $plan['note'] ) . '</p>';
			}
			$current = '';
			$open    = false;
			foreach ( $plan['items'] as $item ) {
				$ts    = strtotime( $item['date'] . ' 00:00:00 UTC' );
				$month = gmdate( 'F Y', $ts );
				if ( $month !== $current ) {
					echo ( $open ? '</div>' : '' ) . '<div class="bzm-month">' . esc_html( $month ) . '</div><div class="bzm-list">';
					$current = $month;
					$open    = true;
				}
				$type = isset( $types[ $item['type'] ] ) ? $item['type'] : 'news';
				echo '<div class="bzm-item"><div class="bzm-date">' . esc_html( gmdate( 'j M', $ts ) ) . '<span>' . esc_html( gmdate( 'l', $ts ) ) . '</span></div><div>';
				echo '<span class="bzm-badge ' . esc_attr( $type ) . '">' . esc_html( $types[ $type ] ) . '</span>';
				echo '<h3>' . esc_html( $item['title'] ) . '</h3><p>' . esc_html( $item['why'] ) . '</p>';
				echo '<details><summary>What to cover</summary><p>' . esc_html( $item['angle'] ) . '</p>';
				if ( $item['link'] ) {
					echo '<p><a href="' . esc_url( $item['link'] ) . '" target="_blank" rel="noopener">' . esc_html( 'Related page' ) . '</a></p>';
				}
				echo '</details></div><div class="bzm-actions">';
				if ( ! empty( $item['post_id'] ) && get_post( $item['post_id'] ) ) {
					echo '<a class="bzm-btn primary" href="' . esc_url( get_edit_post_link( $item['post_id'] ) ) . '">' . esc_html( 'publish' === get_post_status( $item['post_id'] ) ? 'View post' : 'Open draft' ) . '</a>';
				} else {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="this.querySelector(\'button\').disabled=true;this.querySelector(\'button\').textContent=\'Writing…\'">';
					wp_nonce_field( 'bz_mkt_draft_' . $item['id'] );
					echo '<input type="hidden" name="action" value="bz_mkt_draft"><input type="hidden" name="item" value="' . esc_attr( $item['id'] ) . '">';
					echo '<button class="bzm-btn primary" style="width:100%">Draft post</button></form>';
				}
				echo '<button class="bzm-btn" disabled>Create email</button><button class="bzm-btn" disabled>Make ad</button><div class="bzm-soon">Email and ads: coming soon</div>';
				echo '</div></div>';
			}
			echo $open ? '</div>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( ! $plan['items'] ) {
				echo '<p class="bzm-empty">No ideas yet. Add some downloads or services, then refresh the plan.</p>';
			}
			?>
		</div>
		<?php echo function_exists( 'bz_core_lm_panel' ) ? bz_core_lm_panel() : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		<?php endif; ?>
		<?php
		$pkg = function_exists( 'bz_core_package' ) ? bz_core_package() : '';
		if ( ! $pkg && function_exists( 'bz_core_package' ) && current_user_can( 'manage_options' ) ) {
			echo '<div class="bzm-plan-box"><span class="bzm-eyebrow" style="color:var(--mut)">Package (admins only)</span><strong>Not set: this site shows every feature</strong>'
				. '<p>Choose Essentials, Pro or Plus in <a href="' . esc_url( admin_url( 'options-general.php?page=bz-client-details' ) ) . '">Settings → Client Details</a>.</p>'
				. ( function_exists( 'bz_core_package_plugins_missing' ) && bz_core_package_plugins_missing() ? '<p style="color:#b45309">Not installed on this site yet: ' . esc_html( implode( ', ', bz_core_package_plugins_missing() ) ) . '. Ask Bizink to add ' . ( count( bz_core_package_plugins_missing() ) > 1 ? 'them' : 'it' ) . '.</p>' : '' )
				. ( ! bz_core_packages_is_source() ? '<a class="bzm-btn link" style="font-size:12px;justify-self:start" href="' . esc_url( bz_core_packages_refresh_link() ) . '">Check for package changes</a>' : '<p>This is the master: packages are edited under Tools → Packages.</p>' ) . '</div>';
		}
		if ( $pkg ) {
			$defs = bz_core_packages();
			echo '<div class="bzm-plan-box"><span class="bzm-eyebrow" style="color:var(--mut)">Your package</span><strong>' . esc_html( $defs[ $pkg ]['label'] ) . '</strong>'
				. ( $defs[ $pkg ]['service'] ? '<details><summary>Your service level</summary><div class="bzm-service">' . wp_kses_post( wpautop( $defs[ $pkg ]['service'] ) ) . '</div></details>' : '' )
				. ( current_user_can( 'manage_options' ) && ! bz_core_packages_is_source() ? '<a class="bzm-btn link" style="font-size:12px;justify-self:start" href="' . esc_url( bz_core_packages_refresh_link() ) . '">Check for package changes</a>' : '' ) . '</div>';
		}
		$news = bz_core_mkt_news();
		if ( $news ) :
			?>
			<div class="bzm-news" id="bzm-news">
				<div class="bzm-head"><div><h2>News from Bizink</h2><p class="bzm-sub">Updates and tips from the Bizink team.</p></div>
					<a class="bzm-btn ghost" href="<?php echo esc_url( BZ_NEWS_SITE ); ?>" target="_blank" rel="noopener">All news ↗</a></div>
				<div class="bzm-news-grid">
					<?php foreach ( $news as $n ) : ?>
						<a class="bzm-news-card" href="<?php echo esc_url( $n['link'] ); ?>" target="_blank" rel="noopener">
							<span class="bzm-news-date"><?php echo esc_html( $n['date'] ); ?></span>
							<strong><?php echo esc_html( $n['title'] ); ?></strong>
							<span><?php echo esc_html( $n['excerpt'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		endif;
		if ( current_user_can( 'manage_options' ) ) :
			$s = bz_core_mkt_settings();
			?>
			<details class="bzm-settings">
				<summary>Connection (admins only)</summary>
				<p>
					<?php
					if ( bz_core_mkt_is_service() ) {
						echo esc_html( 'This site is the marketing service. Sites are listed under Tools → Marketing sites.' );
					} else {
						echo esc_html( 'Service: ' . BZ_MARKETING_SERVICE . '. ' . ( $s['key'] ? 'Site key is set.' : 'No site key yet: run tools/marketing.py connect, or paste the key from Tools → Marketing sites on the master.' ) );
					}
					?>
				</p>
				<p><?php echo esc_html( 'Google Analytics and Search Console are set in Settings → Client Details (GA4 tag: ' . ( $s['ga4_id'] ? $s['ga4_id'] . ( bz_core_ga4_tag_id() ? ' (on)' : ' (starts at launch, when search engines are allowed)' ) : 'none' ) . ', property: ' . ( bz_core_get( 'ga4_property_id' ) ? bz_core_get( 'ga4_property_id' ) : 'none' ) . ', Search Console: ' . ( bz_core_get( 'search_console' ) ? bz_core_get( 'search_console' ) : 'none' ) . ').' ); ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=bz-client-details' ) ); ?>">Edit</a></p>
				<?php if ( ! bz_core_mkt_is_service() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'bz_mkt_settings' ); ?>
					<input type="hidden" name="action" value="bz_mkt_settings">
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="bzm-key">Site key</label></th>
							<td><input id="bzm-key" name="key" type="password" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $s['key'] ? 'Set (leave empty to keep)' : '' ); ?>"></td></tr>
					</table>
					<?php submit_button( 'Save key' ); ?>
				</form>
				<?php endif; ?>
			</details>
		<?php endif; ?>
	</div>
	<?php
	echo bz_core_support_dialog( bz_core_support_context( 'dashboard', wp_get_current_user() ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
}

/* ---------- Service: Tools → Marketing sites ---------- */

add_action(
	'admin_menu',
	function () {
		if ( bz_core_mkt_is_service() ) {
			add_management_page( 'Marketing sites', 'Marketing sites', 'manage_options', 'bz-marketing-sites', 'bz_core_render_marketing_sites' );
		}
	}
);

add_action(
	'admin_post_bz_mkt_site',
	function () {
		check_admin_referer( 'bz_mkt_site' );
		if ( ! current_user_can( 'manage_options' ) || ! bz_core_mkt_is_service() ) {
			wp_die( 'Not allowed.' );
		}
		$host  = strtolower( (string) wp_parse_url( 'https://' . preg_replace( '#^https?://#', '', sanitize_text_field( wp_unslash( isset( $_POST['host'] ) ? $_POST['host'] : '' ) ) ), PHP_URL_HOST ) );
		$sites = bz_core_mkt_sites();
		$note  = '';
		if ( $host ) {
			if ( ! empty( $_POST['remove'] ) ) {
				unset( $sites[ $host ] );
				$note = 'Removed ' . $host . '.';
			} else {
				$site = isset( $sites[ $host ] ) ? $sites[ $host ] : array( 'added' => time() );
				foreach ( array( 'label', 'ga4', 'gsc' ) as $k ) {
					$site[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
				}
				if ( empty( $site['hash'] ) || ! empty( $_POST['rotate'] ) ) {
					$key          = wp_generate_password( 40, false );
					$site['hash'] = bz_core_mkt_key_hash( $key );
					set_transient( 'bz_mkt_new_key_' . get_current_user_id(), array( $host, $key ), 10 * MINUTE_IN_SECONDS );
				}
				$sites[ $host ] = $site;
				$note           = 'Saved ' . $host . '.';
			}
			delete_transient( 'bz_mkt_stats_' . md5( $host ) );
			update_option( 'bz_mkt_sites', $sites, false );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'bz-marketing-sites', 'bz_msg' => rawurlencode( $note ) ), admin_url( 'tools.php' ) ) );
		exit;
	}
);

function bz_core_render_marketing_sites() {
	$sites = bz_core_mkt_sites();
	$sa    = bz_core_mkt_google_account();
	$new   = get_transient( 'bz_mkt_new_key_' . get_current_user_id() );
	delete_transient( 'bz_mkt_new_key_' . get_current_user_id() );
	$usage = get_option( 'bz_mkt_usage', array() );
	$msg   = isset( $_GET['bz_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bz_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	echo '<div class="wrap"><h1>Marketing sites</h1>';
	if ( $msg ) {
		echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
	}
	if ( $new ) {
		echo '<div class="notice notice-warning"><p>' . esc_html( 'Key for ' . $new[0] . ' (shown once; paste it into Marketing → Connection on that site):' ) . '</p><p><input type="text" readonly class="large-text code" value="' . esc_attr( $new[1] ) . '" onclick="this.select()"></p></div>';
	}
	echo '<h2>Service set-up</h2><ul>';
	echo '<li>' . esc_html( 'Google service account: ' . ( $sa ? $sa['client_email'] . ' (add this address as a Viewer on each GA4 property, and as a user on each Search Console property)' : 'not working. ' . bz_core_mkt_google_problem() ) ) . '</li>';
	echo '<li>' . esc_html( 'Claude: ' . ( defined( 'BZ_ANTHROPIC_API_KEY' ) && BZ_ANTHROPIC_API_KEY ? 'key set, model ' . BZ_MARKETING_MODEL : 'no key (BZ_ANTHROPIC_API_KEY in wp-config.php); plans and drafts use the built-in rules' ) ) . '</li>';
	foreach ( array_reverse( $usage, true ) as $month => $u ) {
		echo '<li>' . esc_html( sprintf( 'Claude use %s: %d calls, %s input and %s output tokens', $month, $u['calls'], number_format_i18n( $u['input_tokens'] ), number_format_i18n( $u['output_tokens'] ) ) ) . '</li>';
	}
	echo '</ul><h2>Connected sites</h2><table class="widefat striped"><thead><tr><th>Site</th><th>Label</th><th>GA4 property ID</th><th>Search Console property</th><th>Last call</th><th></th></tr></thead><tbody>';
	$forms    = '';
	$n        = 0;
	$row_form = function ( $host, $s ) use ( &$forms, &$n ) {
		$f      = 'bzms-' . ( ++$n );
		$forms .= '<form id="' . $f . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'bz_mkt_site', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="bz_mkt_site">' . ( $host ? '<input type="hidden" name="host" value="' . esc_attr( $host ) . '">' : '' ) . '</form>';
		echo '<tr><td>' . ( $host ? '<strong>' . esc_html( $host ) . '</strong>' : '<input form="' . esc_attr( $f ) . '" name="host" placeholder="firm.myfirmspage.com" class="regular-text">' ) . '</td>';
		foreach ( array( 'label' => 'Firm name', 'ga4' => '123456789', 'gsc' => 'sc-domain:firm.com.au' ) as $k => $ph ) {
			$theirs = isset( $s[ 'site_' . $k ] ) ? (string) $s[ 'site_' . $k ] : '';
			$mine   = isset( $s[ $k ] ) ? (string) $s[ $k ] : '';
			echo '<td><input form="' . esc_attr( $f ) . '" name="' . esc_attr( $k ) . '" value="' . esc_attr( $mine ) . '" placeholder="' . esc_attr( $ph ) . '">';
			if ( '' !== $theirs && $theirs !== $mine ) { // The site's Client Details say something else: copy it in and Save if it's right.
				echo '<br><span class="description" style="color:#b45309">Site\'s Client Details: <code>' . esc_html( $theirs ) . '</code></span>';
			}
			echo '</td>';
		}
		echo '<td>' . esc_html( ! empty( $s['last_seen'] ) ? human_time_diff( $s['last_seen'] ) . ' ago' : '–' ) . '</td><td>';
		echo '<button form="' . esc_attr( $f ) . '" class="button button-primary">' . ( $host ? 'Save' : 'Add site' ) . '</button> ';
		if ( $host ) {
			echo '<label><input form="' . esc_attr( $f ) . '" type="checkbox" name="rotate" value="1"> new key</label> <label><input form="' . esc_attr( $f ) . '" type="checkbox" name="remove" value="1"> remove</label>';
		}
		echo '</td></tr>';
	};
	foreach ( $sites as $host => $s ) {
		$row_form( $host, $s );
	}
	$row_form( '', array() );
	echo $forms; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
	echo '</tbody></table><p class="description">Adding a site shows its key once. tools/marketing.py connect does the same and copies the key to the site for you.</p></div>';
}
