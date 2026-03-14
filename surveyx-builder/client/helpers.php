<?php

/** Prevent direct access */
defined( 'ABSPATH' ) || exit;

/**
 * Recursively validate and sanitize input data from client REST requests.
 *
 * This function sanitizes all strings as PLAIN TEXT ONLY (no HTML allowed),
 * preserves integers, floats, and booleans, and recursively processes arrays.
 * It follows WordPress security best practices for client-submitted data.
 *
 * @param mixed $data Input data to validate (can be array, string, int, float, bool).
 * @return mixed Validated and sanitized data.
 */
if ( ! function_exists( 'surveyx_client_recursive_sanitize' ) ) {
	function surveyx_client_recursive_sanitize( $data ) {
		// Map string representations of booleans
		static $bool_map = [
			'true'  => true,
			'false' => false,
		];

		// Handle arrays recursively
		if ( is_array( $data ) ) {
			$validated = [];
			foreach ( $data as $key => $value ) {
				// Sanitize array keys
				$sanitized_key               = sanitize_key( $key );
				$validated[ $sanitized_key ] = surveyx_client_recursive_sanitize( $value );
			}
			return $validated;
		}

		// Handle strings - PLAIN TEXT ONLY, no HTML
		if ( is_string( $data ) ) {
			// Strip all HTML tags and sanitize as plain text
			$sanitized = sanitize_textarea_field( wp_unslash( $data ) );
			$lower     = strtolower( trim( $sanitized ) );

			// Convert string booleans to actual booleans
			return $bool_map[ $lower ] ?? $sanitized;
		}

		// Preserve integers and floats as-is
		if ( is_int( $data ) || is_float( $data ) ) {
			return $data;
		}

		// Preserve booleans as-is
		if ( is_bool( $data ) ) {
			return $data;
		}

		// Null values pass through
		if ( is_null( $data ) ) {
			return null;
		}

		// For any other type, convert to string and sanitize as plain text
		return sanitize_text_field( (string) $data );
	}
}

/**
 * Renders a notification card for users.
 * Used for expired polls, login requirements, restrictions, etc.
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
