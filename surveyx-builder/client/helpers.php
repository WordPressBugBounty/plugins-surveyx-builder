<?php

defined( 'ABSPATH' ) || exit;

/**
 * Recursively validate and sanitize input data from client REST requests.
 *
 * The sanitization boundary for unauthenticated client input: strings become PLAIN
 * TEXT ONLY (no HTML), array keys are passed through sanitize_key(), and the strings
 * "true"/"false" are coerced to real booleans. Ints, floats, bools and null pass
 * through unchanged.
 *
 * @param mixed $data Input data to validate (can be array, string, int, float, bool).
 * @return mixed Validated and sanitized data.
 */
if ( ! function_exists( 'surveyx_client_recursive_sanitize' ) ) {
	function surveyx_client_recursive_sanitize( $data ) {
		static $bool_map = [
			'true'  => true,
			'false' => false,
		];

		if ( is_array( $data ) ) {
			$validated = [];
			foreach ( $data as $key => $value ) {
				$sanitized_key               = sanitize_key( $key );
				$validated[ $sanitized_key ] = surveyx_client_recursive_sanitize( $value );
			}
			return $validated;
		}

		if ( is_string( $data ) ) {
			$sanitized = sanitize_textarea_field( wp_unslash( $data ) );
			$lower     = strtolower( trim( $sanitized ) );

			return $bool_map[ $lower ] ?? $sanitized;
		}

		if ( is_int( $data ) || is_float( $data ) ) {
			return $data;
		}

		if ( is_bool( $data ) ) {
			return $data;
		}

		if ( is_null( $data ) ) {
			return null;
		}

		return sanitize_text_field( (string) $data );
	}
}

/**
 * Renders a notification card - expired polls, login requirements, restrictions.
 *
 * @param string $title   Notification title.
 * @param string $message Notification message.
 * @return string HTML output.
 */
if ( ! function_exists( 'surveyx_render_notification' ) ) {
	function surveyx_render_notification( $title, $message ) {
		$output  = '<div class="survey-restrict-card">';
		$output .= '<div class="survey-restrict-header">';
		$output .= '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="#1D4ED8" viewBox="0 0 24 24">';
		$output .= '<path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm1 17h-2v-2h2v2zm0-4h-2V7h2v6z"/>';
		$output .= '</svg>';
		$output .= '<span>' . esc_html( $title ) . '</span>';
		$output .= '</div>';
		$output .= '<div class="survey-restrict-body">';
		$output .= '<p>' . esc_html( $message ) . '</p>';
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}
}
