<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'surveyx_get_utc_now' ) ) {
	/**
	 * Gets current UTC datetime string for database storage.
	 *
	 * Single source of truth for "now" across SurveyX. `current_time( 'mysql', true )` is
	 * the exact core function that computes the GMT value stored in `*_gmt` DATETIME
	 * columns, so the value is PHP-computed in UTC and never derived from MySQL's session
	 * timezone. With plain DATETIME columns (stored verbatim, unlike TIMESTAMP) every stored
	 * time is timezone-independent; conversion to local happens only on display.
	 *
	 * @return string MySQL datetime format in UTC (Y-m-d H:i:s).
	 */
	function surveyx_get_utc_now() {
		return current_time( 'mysql', true );
	}
}
