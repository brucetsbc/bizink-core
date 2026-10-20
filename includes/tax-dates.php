<?php
/**
 * Key tax dates, from the Tax Dates Generator (C:\www\Tax-dates-generator → node export.js).
 *
 * [bz_tax_dates]                      Rolling view: this month plus the next 11, across tax years.
 * [bz_tax_dates view="upcoming" count="3"]  The next few deadlines, for a Home or Resources strip.
 * [bz_tax_dates view="year" year="current|next|2027"]  One whole tax year.
 *
 * Data source: every site fetches the dataset from a central site (the master by default) twice a day,
 * keeps the last good copy, and falls back to the copy bundled in data/tax-dates.json. The newest of
 * those (by "generated" date) wins, so updating the master's plugin updates every client site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BZ_TAX_DATES_SOURCE' ) ) {
	define( 'BZ_TAX_DATES_SOURCE', BZ_MASTER_URL . '/wp-json/bizink/v1/tax-dates' );
}

/* ---------- Data: bundled, central and cached ---------- */

function bz_core_tax_dates_bundled() {
	static $data = null;
	if ( null === $data ) {
		$file = dirname( __DIR__ ) . '/data/tax-dates.json';
		$data = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		$data = is_array( $data ) ? $data : array();
	}
	return $data;
}

function bz_core_tax_dates_valid( $data ) {
	return is_array( $data ) && ! empty( $data['countries'] ) && ! empty( $data['generated'] );
}

function bz_core_tax_dates_source() {
	return (string) apply_filters( 'bz_tax_dates_source', BZ_TAX_DATES_SOURCE );
}

function bz_core_tax_dates_is_source_site() {
	return bz_core_is_master() || wp_parse_url( bz_core_tax_dates_source(), PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );
}

/**
 * Last good copy from the central source, refreshed at most every 12 hours (1 hour after a failure).
 */
function bz_core_tax_dates_remote() {
	if ( bz_core_tax_dates_is_source_site() ) {
		return null;
	}
	$last = get_option( 'bz_tax_dates_remote', null );
	if ( false === get_transient( 'bz_tax_dates_checked' ) ) {
		$response = bz_core_master_request( bz_core_tax_dates_source(), array( 'timeout' => 5 ) );
		$body     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === wp_remote_retrieve_response_code( $response ) && bz_core_tax_dates_valid( $body ) ) {
			$last = $body;
			update_option( 'bz_tax_dates_remote', $body, false );
			update_option( 'bz_tax_dates_fetched', current_time( 'mysql' ), false );
			set_transient( 'bz_tax_dates_checked', 1, 12 * HOUR_IN_SECONDS );
		} else {
			set_transient( 'bz_tax_dates_checked', 1, HOUR_IN_SECONDS );
		}
	}
	return bz_core_tax_dates_valid( $last ) ? $last : null;
}

/**
 * The dataset in use: the newer of the central copy and the bundled copy.
 */
function bz_core_tax_dates_data() {
	static $data = null;
	if ( null === $data ) {
		$bundled = bz_core_tax_dates_bundled();
		$remote  = bz_core_tax_dates_remote();
		$data    = ( $remote && ( ! bz_core_tax_dates_valid( $bundled ) || $remote['generated'] > $bundled['generated'] ) ) ? $remote : $bundled;
	}
	return $data;
}

/* The central endpoint: any site running the plugin can serve its bundled dataset. */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'bizink/v1',
			'/tax-dates',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					return rest_ensure_response( bz_core_tax_dates_bundled() );
				},
			)
		);
	}
);

/* ---------- Dates ---------- */

/* Region (Client Details) → generator country key. Ireland has no generator data yet. */
function bz_core_tax_dates_country( $region ) {
	$map = array(
		'AU' => 'au',
		'NZ' => 'nz',
		'UK' => 'uk',
		'US' => 'us',
		'CA' => 'ca',
	);
	return isset( $map[ $region ] ) ? $map[ $region ] : null;
}

/**
 * The tax year (numbered by the year it starts in) that a date falls in.
 */
function bz_core_tax_year_for( $country, $y, $md ) {
	$start = array(
		'au' => '0701', // 1 July
		'nz' => '0401', // 1 April
		'uk' => '0406', // 6 April
	);
	return ( isset( $start[ $country ] ) && $md < $start[ $country ] ) ? $y - 1 : $y;
}

function bz_core_current_tax_year( $country ) {
	$now = current_datetime();
	return bz_core_tax_year_for( $country, (int) $now->format( 'Y' ), $now->format( 'md' ) );
}

/**
 * Month entries for the next $count months from this month. Each calendar month is taken from the tax
 * year it belongs to, because neighbouring years repeat boundary months with slightly different wording.
 */
function bz_core_tax_dates_rolling( $country, $count ) {
	$years  = bz_core_tax_dates_data()['countries'][ $country ]['years'];
	$now    = current_datetime();
	$y      = (int) $now->format( 'Y' );
	$m      = (int) $now->format( 'n' );
	$months = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$owner = (string) bz_core_tax_year_for( $country, $y, sprintf( '%02d01', $m ) );
		$entry = null;
		$from  = null;
		foreach ( array_merge( array( $owner ), array_keys( $years ) ) as $ty ) {
			foreach ( isset( $years[ $ty ]['months'] ) ? $years[ $ty ]['months'] : array() as $month ) {
				if ( (int) $month['y'] === $y && (int) $month['m'] === $m ) {
					$entry = $month;
					$from  = (string) $ty;
					break 2;
				}
			}
		}
		if ( $entry ) {
			$entry['tax_year'] = $from;
			$months[]          = $entry;
		}
		if ( 13 === ++$m ) {
			$m = 1;
			++$y;
		}
	}
	return $months;
}

function bz_core_tax_dates_row_ts( $month, $row ) {
	return $row['day'] ? gmmktime( 0, 0, 0, (int) $month['m'], (int) $row['day'], (int) $month['y'] ) : null;
}

function bz_core_tax_dates_today_ts() {
	$now = current_datetime();
	return gmmktime( 0, 0, 0, (int) $now->format( 'n' ), (int) $now->format( 'j' ), (int) $now->format( 'Y' ) );
}

/* ---------- Rendering ---------- */

function bz_core_tax_dates_enqueue( $tag_css ) {
	wp_register_style( 'bz-tax-dates', false, array(), BZ_CORE_VERSION );
	wp_enqueue_style( 'bz-tax-dates' );
	static $done = false;
	wp_add_inline_style( 'bz-tax-dates', ( $done ? '' : bz_core_tax_dates_css() ) . $tag_css );
	$done = true;
}

function bz_core_tax_dates_row_html( $row, $past ) {
	return '<tr' . ( $past ? ' class="is-past"' : '' ) . '><th scope="row"><span class="date-col">' . esc_html( $row['date'] ) . '</span> '
		. bz_core_tax_dates_desc( $row['desc'] )
		. ' <span class="' . esc_attr( $row['tag'] ) . '">' . esc_html( $row['label'] ) . '</span>'
		. ( $row['note'] ? '<span class="note-warn">⚠ ' . esc_html( bz_core_tax_dates_text( $row['note'] ) ) . '</span>' : '' )
		. '</th></tr>';
}

/**
 * A row's description, with the generator's spaced em dash ("BAS due — lodge and pay") written as a colon.
 */
function bz_core_tax_dates_desc( $desc ) {
	return wp_kses_post( bz_core_tax_dates_text( $desc ) );
}

/**
 * Generator text with its spaced em dashes written as colons (headings, notes, warnings).
 */
function bz_core_tax_dates_text( $text ) {
	return str_replace( ' — ', ': ', (string) $text );
}

add_shortcode(
	'bz_tax_dates',
	function ( $atts ) {
		$atts    = shortcode_atts(
			array(
				'view'   => 'rolling',
				'months' => 12,
				'count'  => 3,
				'year'   => 'current',
				'link'   => '',
			),
			$atts,
			'bz_tax_dates'
		);
		$region  = bz_core_get( 'region' );
		$country = bz_core_tax_dates_country( $region );
		$data    = bz_core_tax_dates_data();

		if ( ! $country || empty( $data['countries'][ $country ]['years'] ) ) {
			$regions = bz_core_regions();
			return '<span class="bz-tax-dates-missing">Key dates for ' . esc_html( isset( $regions[ $region ] ) ? $regions[ $region ] : $region ) . ' are not available yet. Please contact us for the deadlines that apply to you.</span>';
		}
		$years = $data['countries'][ $country ]['years'];

		/* Whole tax year (the generator's own layout). */
		if ( 'year' === $atts['view'] ) {
			$year = bz_core_current_tax_year( $country );
			if ( 'next' === $atts['year'] ) {
				++$year;
			} elseif ( ctype_digit( (string) $atts['year'] ) ) {
				$year = (int) $atts['year'];
			}
			$available = array_map( 'intval', array_keys( $years ) );
			if ( ! in_array( $year, $available, true ) ) {
				$year = $year > max( $available ) ? max( $available ) : min( $available );
			}
			$entry = $years[ (string) $year ];
			bz_core_tax_dates_enqueue( $entry['tag_css'] );
			return '<span class="bz-tax-dates"><span class="bz-tax-dates-title">' . esc_html( bz_core_tax_dates_text( $entry['title'] ) ) . '</span>' . bz_core_tax_dates_text( $entry['body'] ) . '</span>';
		}

		$months = bz_core_tax_dates_rolling( $country, max( 1, min( 24, (int) $atts['months'] ) ) );
		if ( ! $months ) {
			return '<span class="bz-tax-dates-missing">Key dates for the coming months have not been published yet. Please contact us for the deadlines that apply to you.</span>';
		}
		$used    = array_unique( wp_list_pluck( $months, 'tax_year' ) );
		$tag_css = implode( '', array_unique( array_map( function ( $ty ) use ( $years ) { return $years[ $ty ]['tag_css']; }, $used ) ) );
		bz_core_tax_dates_enqueue( $tag_css );
		$today = bz_core_tax_dates_today_ts();

		/* Next few deadlines. */
		if ( 'upcoming' === $atts['view'] ) {
			$items = array();
			foreach ( $months as $month ) {
				foreach ( $month['rows'] as $row ) {
					$ts = bz_core_tax_dates_row_ts( $month, $row );
					if ( $ts && $ts >= $today ) {
						$items[] = '<span class="bz-upcoming-item"><span class="bz-upcoming-date">' . esc_html( gmdate( 'j M', $ts ) ) . '</span><span class="bz-upcoming-desc">' . bz_core_tax_dates_desc( $row['desc'] ) . ' <span class="' . esc_attr( $row['tag'] ) . '">' . esc_html( $row['label'] ) . '</span></span></span>';
					}
					if ( count( $items ) >= (int) $atts['count'] ) {
						break 2;
					}
				}
			}
			$link = $atts['link'] ? '<a class="bz-upcoming-link" href="' . esc_url( $atts['link'] ) . '">See all key dates →</a>' : '';
			return '<span class="bz-tax-dates bz-upcoming">' . implode( '', $items ) . $link . '</span>';
		}

		/* Rolling 12 months. */
		$first   = reset( $months );
		$last    = end( $months );
		$current = $years[ $first['tax_year'] ];
		$notices = array_filter( array_unique( array_map( function ( $ty ) use ( $years ) { return $years[ $ty ]['notice']; }, $used ) ) );
		$html    = '<span class="bz-tax-dates" data-country="' . esc_attr( $country ) . '">';
		$html   .= '<span class="bz-tax-dates-title">Key dates: ' . esc_html( gmdate( 'F Y', gmmktime( 0, 0, 0, $first['m'], 1, $first['y'] ) ) . ' to ' . gmdate( 'F Y', gmmktime( 0, 0, 0, $last['m'], 1, $last['y'] ) ) ) . '</span>';
		$html   .= '<p class="subtitle">' . wp_kses_post( $current['subtitle'] ) . '</p>';
		foreach ( $notices as $notice ) {
			$html .= '<div class="payday-notice">' . wp_kses_post( $notice ) . '</div>';
		}
		foreach ( $months as $month ) {
			$html .= '<h3 class="month-head">' . esc_html( bz_core_tax_dates_text( $month['heading'] ) ) . '</h3><table class="deadline-table"><tbody>';
			foreach ( $month['rows'] as $row ) {
				$ts    = bz_core_tax_dates_row_ts( $month, $row );
				$html .= bz_core_tax_dates_row_html( $row, $ts && $ts < $today );
			}
			$html .= '</tbody></table>';
		}
		if ( $current['notes'] ) {
			$html .= '<p class="notes-text">' . wp_kses_post( bz_core_tax_dates_text( $current['notes'] ) ) . '</p>';
		}
		return $html . '</span>';
	}
);

/* Status for Tools → Region Pack. */
function bz_core_tax_dates_status_html() {
	$data    = bz_core_tax_dates_data();
	$bundled = bz_core_tax_dates_bundled();
	$remote  = get_option( 'bz_tax_dates_remote', null );
	if ( bz_core_tax_dates_is_source_site() ) {
		$in_use = 'bundled copy (this site is the central source)';
	} elseif ( ! bz_core_tax_dates_valid( $remote ) ) {
		$in_use = 'bundled copy (central source not reached yet)';
	} elseif ( $data === $remote ) {
		$in_use = 'central copy';
	} else {
		$in_use = 'bundled copy (newer than the central copy)';
	}
	$country = bz_core_tax_dates_country( bz_core_get( 'region' ) );
	$rows    = array(
		'Data in use'        => $in_use,
		'Generated'          => isset( $data['generated'] ) ? $data['generated'] : '—',
		'Central source'     => bz_core_tax_dates_source(),
		'Last fetched'       => get_option( 'bz_tax_dates_fetched', 'never' ),
		'Years for region'   => $country && isset( $data['countries'][ $country ] ) ? implode( ', ', array_keys( $data['countries'][ $country ]['years'] ) ) : 'none (region not covered)',
	);
	$html = '<h2 style="margin-top:32px">Key tax dates data</h2><table class="widefat striped" style="max-width:820px"><tbody>';
	foreach ( $rows as $label => $value ) {
		$html .= '<tr><th style="width:180px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
	return $html . '</tbody></table>';
}

/* Generator markup restyled with the kit's V4 tokens. */
function bz_core_tax_dates_css() {
	return '
.bz-tax-dates{display:block;font-family:var(--font-body),sans-serif;color:var(--color-text)}
.bz-tax-dates-title{display:block;font-family:var(--font-heading),sans-serif;font-size:var(--text-h3);font-weight:700;line-height:1.25;color:var(--color-heading);margin:0 0 8px}
.bz-tax-dates .subtitle{color:var(--color-text-muted);font-size:.95rem;margin:0 0 24px}
.bz-tax-dates a{color:var(--color-primary);text-decoration:underline;text-decoration-color:var(--color-accent);text-underline-offset:3px}
.bz-tax-dates .payday-notice{background:#FFF7E3;border:1px solid #F3DFA6;border-radius:var(--radius-card,8px);padding:12px 16px;font-size:.95rem;margin:0 0 16px;color:var(--color-heading)}
.bz-tax-dates .month-head{display:block;margin:32px 0 0;padding:10px 16px;background:var(--color-primary);color:var(--color-inverse);font-family:var(--font-heading),sans-serif;font-size:1rem;font-weight:700;border-radius:0}
.bz-tax-dates .deadline-table{width:100%;border-collapse:collapse;border:1px solid var(--color-border);border-top:0;margin:0 0 8px;background:var(--color-surface)}
.bz-tax-dates .deadline-table th{font-weight:400;text-align:left;padding:12px 16px;border-top:1px solid var(--color-border);font-size:.95rem;line-height:1.55;vertical-align:top}
.bz-tax-dates .deadline-table tr:first-child th{border-top:0}
.bz-tax-dates .deadline-table tr.is-past th{opacity:.5}
.bz-tax-dates .date-col{display:inline-block;min-width:72px;font-weight:700;color:var(--color-primary);white-space:nowrap}
.bz-tax-dates .note-warn{display:inline-block;font-size:.78rem;color:#7a5a00;background:#FFF7E3;border-radius:0;padding:1px 6px;margin-left:6px}
.bz-tax-dates .notes-text{margin-top:24px;color:var(--color-text-muted);font-size:.85rem}
.bz-tax-dates-missing{display:block;padding:16px 18px;background:#FFF7E3;border:1px solid #F3DFA6;border-radius:var(--radius-card,8px)}
.bz-upcoming{display:grid;gap:12px}
.bz-upcoming-item{display:flex;gap:16px;align-items:flex-start;padding:14px 16px;background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius-card,8px)}
.bz-upcoming-date{flex:0 0 64px;font-family:var(--font-heading),sans-serif;font-weight:800;color:var(--color-primary);white-space:nowrap}
.bz-upcoming-desc{font-size:.95rem;line-height:1.5}
.bz-upcoming-link{font-weight:600}
@media (max-width:767px){.bz-tax-dates .date-col{display:block;margin-bottom:2px}}
';
}
