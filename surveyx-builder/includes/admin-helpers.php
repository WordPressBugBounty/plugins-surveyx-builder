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
		 * Checks if reCAPTCHA v2 is available based on the presence of site key.
		 *
		 * @param object|array $settings Settings object or array.
		 *
		 * @return bool True if reCAPTCHA v2 is available (site key present).
		 */
		public static function is_recaptcha_v2_available( $settings ) {
			if ( is_object( $settings ) ) {
				return ! empty( $settings->recaptcha_v2_site_key );
			}

			if ( is_array( $settings ) ) {
				return ! empty( $settings['recaptcha_v2_site_key'] );
			}

			return false;
		}

		/**
		 * Validates settings for incomplete required fields.
		 * Only validates fields that have data - ignores enabled flags.
		 * This allows toggling features on/off without validation errors.
		 *
		 * @param array $settings Settings array to validate.
		 *
		 * @return array Array of validation errors (empty if valid).
		 */
		public static function validate_settings( $settings ) {
			$errors = [];

			if ( ! empty( $settings['recaptcha_v2_site_key'] ) && empty( $settings['recaptcha_v2_secret_key'] ) ) {
				$errors['recaptcha_v2_secret_key'] = esc_html__( 'reCAPTCHA v2 Secret Key is required when Site Key is provided.', 'surveyx-builder' );
			}

			if ( ! empty( $settings['recaptcha_v2_secret_key'] ) && empty( $settings['recaptcha_v2_site_key'] ) ) {
				$errors['recaptcha_v2_site_key'] = esc_html__( 'reCAPTCHA v2 Site Key is required when Secret Key is provided.', 'surveyx-builder' );
			}

			return $errors;
		}

		/**
		 * Gets default settings structure.
		 *
		 * @return array Default settings array.
		 */
		public static function get_default_settings() {
			return [
				'recaptcha_v2_enabled'       => false,
				'recaptcha_v2_site_key'      => '',
				'recaptcha_v2_secret_key'    => '',
				'turnstile_enabled'          => false,
				'turnstile_site_key'         => '',
				'turnstile_secret_key'       => '',
				'email_enabled'              => false,
				'email_address'              => '',
				'survey_voted_email_subject' => '',
				'survey_voted_email_title'   => '',
				'survey_voted_email_message' => '',
				'custom_themes'              => [],
				'enable_branding'            => false,
				'brand_logo_url'             => '',
				'brand_pre_text'             => '',
				'brand_link_url'             => '',
			];
		}

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
