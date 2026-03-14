<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'surveyx_get_utc_now' ) ) {
	/**
	 * Gets current UTC datetime string for database storage.
	 *
	 * All datetime values in SurveyX are stored in UTC to ensure consistent
	 * time calculations across different server timezones and prevent issues
	 * with timezone conversions, DST changes, and date arithmetic.
	 *
	 * @return string MySQL datetime format in UTC (Y-m-d H:i:s).
	 */
	function surveyx_get_utc_now() {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'surveyx_utc_to_local' ) ) {
	/**
	 * Converts UTC datetime to WordPress local timezone for display.
	 *
	 * This function converts UTC datetime (stored in database) to the timezone
	 * configured in WordPress settings (Settings > General > Timezone).
	 *
	 * @param string $utc_datetime UTC datetime string in MySQL format (Y-m-d H:i:s).
	 * @param string $format       Optional. PHP date format. Default 'Y-m-d H:i:s'.
	 * @return string Datetime string in local timezone with specified format.
	 */
	function surveyx_utc_to_local( $utc_datetime, $format = 'Y-m-d H:i:s' ) {
		if ( empty( $utc_datetime ) || '0000-00-00 00:00:00' === $utc_datetime ) {
			return '';
		}

		// Use WordPress function to convert from GMT/UTC to local time.
		return get_date_from_gmt( $utc_datetime, $format );
	}
}

if ( ! function_exists( 'surveyx_format_date_for_display' ) ) {
	/**
	 * Formats UTC datetime for display in WordPress admin using WordPress settings.
	 *
	 * Uses WordPress date and time format settings from Settings > General.
	 *
	 * @param string $utc_datetime UTC datetime string in MySQL format.
	 * @param bool   $include_time Whether to include time in the output. Default true.
	 * @return string Formatted datetime string in local timezone.
	 */
	function surveyx_format_date_for_display( $utc_datetime, $include_time = true ) {
		if ( empty( $utc_datetime ) || '0000-00-00 00:00:00' === $utc_datetime ) {
			return '';
		}

		$date_format = get_option( 'date_format', 'Y-m-d' );
		$time_format = get_option( 'time_format', 'H:i:s' );
		$format      = $include_time ? $date_format . ' ' . $time_format : $date_format;

		return surveyx_utc_to_local( $utc_datetime, $format );
	}
}

if ( ! function_exists( 'surveyx_convert_dates_to_local' ) ) {
	/**
	 * Converts UTC datetime fields to local timezone in array/object data.
	 *
	 * Automatically detects common date field names and converts them from UTC
	 * to WordPress local timezone. Useful for API responses.
	 *
	 * @param array|object $data Array or object containing datetime fields.
	 * @param array        $date_fields Optional. Field names to convert. Default common date fields.
	 * @return array|object Data with converted datetime fields.
	 */
	function surveyx_convert_dates_to_local( $data, $date_fields = null ) {
		if ( empty( $data ) ) {
			return $data;
		}

		// Default date fields to convert.
		if ( null === $date_fields ) {
			$date_fields = [
				'created_at',
				'updated_at',
				'updated_draft_at',
				'started_at',
				'last_activity_at',
				'completed_at',
				'answered_at',
				'first_seen_at',
				'last_seen_at',
				'last_updated',
				'synced_at',
			];
		}

		$is_object = is_object( $data );
		$work_data = $is_object ? (array) $data : $data;

		foreach ( $date_fields as $field ) {
			if ( isset( $work_data[ $field ] ) && ! empty( $work_data[ $field ] ) ) {
				$work_data[ $field ] = surveyx_utc_to_local( $work_data[ $field ] );
			}
		}

		return $is_object ? (object) $work_data : $work_data;
	}
}
