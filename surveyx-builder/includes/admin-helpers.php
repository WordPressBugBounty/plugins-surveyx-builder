<?php

// Don't load directly.
defined( 'ABSPATH' ) || exit;

/**
 * Unified Settings Helpers for SurveyX
 */
if ( ! class_exists( 'SurveyX_Admin_Helpers', false ) ) {
	class SurveyX_Admin_Helpers {

		public static $allowed_html = [
			'a'      => [
				'href'   => [],
				'title'  => [],
				'rel'    => [],
				'target' => [],
			],
			'p'      => [],
			'strong' => [],
			'em'     => [],
			'u'      => [],
			'ul'     => [],
			'ol'     => [],
			'li'     => [],
			'span'   => [
				'class'           => [],
				'data-id'         => [],
				'data-value'      => [],
				'data-denotation' => [],
			],
			'br'     => [],
			'div'    => [
				'class' => [],
			],
		];

		/**
		 * Recursively validate and sanitize input data.
		 *
		 * @param mixed $data Input data to validate.
		 *
		 * @return mixed Validated and sanitized data.
		 */
		public static function recursive_sanitize( $data ) {
			static $bool_map = [
				'true'  => true,
				'false' => false,
			];

			if ( is_array( $data ) ) {
				$validated = [];

				foreach ( $data as $key => $value ) {
					if ( 'survey_type' === $key ) {
						$validated[ $key ] = self::validate_type( $value );
						continue;
					}

					if ( 'status' === $key ) {
						$validated[ $key ] = self::validate_status( $value );
						continue;
					}

					// URL settings are rendered on the public client — run them through
					// sanitize_url (esc_url_raw + javascript: block) instead of wp_kses,
					// which leaves a bare "javascript:" string untouched.
					if ( 'custom_brand_link' === $key || 'custom_brand_logo' === $key ) {
						$validated[ $key ] = is_string( $value ) ? self::sanitize_url( $value ) : '';
						continue;
					}

					if ( 'error' === $key ) {
						continue;
					}

					$validated[ $key ] = self::recursive_sanitize( $value );
				}

				return $validated;
			}

			if ( is_string( $data ) ) {
				$val = wp_kses( wp_unslash( $data ), self::$allowed_html );
				// Remove zero-width no-break space (U+FEFF) characters inserted by quill-mention.
				$val   = str_replace( "\xEF\xBB\xBF", '', $val );
				$lower = strtolower( $val );

				return $bool_map[ $lower ] ?? $val;
			}

			if ( is_int( $data ) || is_float( $data ) ) {
				return $data;
			}

			if ( is_bool( $data ) ) {
				return (bool) $data;
			}

			return wp_kses( wp_unslash( $data ), self::$allowed_html );
		}

		/**
		 * Validate survey type against allowed values.
		 *
		 * @param string $survey_type The survey type to validate.
		 *
		 * @return string Valid survey type (defaults to 'vote').
		 */
		public static function validate_type( $survey_type ) {
			$allowed_types = [
				'vote',
				'survey',
				'trivia',
				'personality',
			];

			return in_array( trim( $survey_type ), $allowed_types, true ) ? $survey_type : 'vote';
		}

		/**
		 * Validate survey status against allowed values.
		 *
		 * @param string $status The survey status to validate.
		 *
		 * @return string Valid survey status (defaults to 'inactive').
		 */
		public static function validate_status( $status ) {
			$allowed_types = [
				'inactive',
				'active',
				'trash',
			];

			return in_array( trim( $status ), $allowed_types, true ) ? $status : 'inactive';
		}

		/**
		 * Check if ID is temporary (client-side generated).
		 *
		 * @param string|int $id The ID to check.
		 *
		 * @return bool True if temporary ID.
		 */
		public static function is_temp_id( $id ) {
			$id = (string) $id;

			return 'temp-' === substr( $id, 0, 5 );
		}

		/**
		 * Sanitize URL to prevent javascript: protocol attacks.
		 *
		 * @param string $url The URL to sanitize.
		 *
		 * @return string Sanitized URL or empty string if invalid.
		 */
		public static function sanitize_url( $url ) {
			if ( empty( $url ) ) {
				return '';
			}

			// Use WordPress esc_url_raw for initial sanitization
			$url = esc_url_raw( $url );

			// Block javascript: protocol (case-insensitive)
			if ( preg_match( '/^javascript:/i', $url ) ) {
				return '';
			}

			return $url;
		}

		/**
		 * Check if development mode is enabled.
		 *
		 * @return bool True if WP_DEBUG is on and SURVEYX_DEV_MODE is defined.
		 */
		public static function is_dev_mode() {
			return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'SURVEYX_DEV_MODE' );
		}

		/**
		 * Get standard request arguments for remote API calls.
		 *
		 * Includes custom User-Agent header and SSL settings for development.
		 *
		 * @param bool $with_auth Whether to include Authorization header with license key.
		 * @param array $args Additional arguments to merge.
		 * @return array Request arguments for wp_remote_post/wp_remote_get.
		 */
		public static function get_remote_request_args( $with_auth = false, $args = [] ) {
			$version = defined( 'SURVEYX_VERSION' ) ? SURVEYX_VERSION : '1.0.0';

			$default_args = [
				'timeout'    => 60,
				'user-agent' => 'SurveyX/' . $version,
				'headers'    => [
					'Content-Type' => 'application/json',
				],
			];

			// Add Authorization header with license key if enabled
			if ( $with_auth ) {
				$license_key = '';
				if ( function_exists( 'surveyx_get_license_key' ) ) {
					$license_key = surveyx_get_license_key();
				}
				if ( $license_key ) {
					$default_args['headers']['Authorization'] = 'Bearer ' . $license_key;
				}
			}

			// Disable SSL verification in dev mode (Local Sites often has SSL issues)
			if ( self::is_dev_mode() ) {
				$default_args['sslverify'] = false;
			}

			// Merge headers separately to avoid overwriting
			if ( ! empty( $args['headers'] ) ) {
				$args['headers'] = array_merge( $default_args['headers'], $args['headers'] );
			}

			return array_merge( $default_args, $args );
		}
	}
}
