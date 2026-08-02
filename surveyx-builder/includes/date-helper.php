<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'surveyx_get_utc_now' ) ) {
	/**
	 * Gets current UTC datetime string for database storage.
	 *
	 * Single source of truth for "now" across SurveyX. Uses WordPress core's own
	 * `current_time( 'mysql', true )` — the exact function core uses to compute the
	 * GMT value it stores in `*_gmt` DATETIME columns (wp_insert_post etc.) — so the
	 * value is PHP-computed in UTC, never derived from MySQL's session timezone.
	 * Combined with plain DATETIME columns (which MySQL stores verbatim, unlike
	 * TIMESTAMP), this keeps every stored time timezone-independent regardless of the
	 * server's or MySQL's @@time_zone. All SurveyX datetimes are stored in UTC and
	 * converted to the viewer's local time only on display.
	 *
	 * @return string MySQL datetime format in UTC (Y-m-d H:i:s).
	 */
	function surveyx_get_utc_now() {
		return current_time( 'mysql', true );
	}
}
