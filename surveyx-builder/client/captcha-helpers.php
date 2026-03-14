<?php
// Don't load directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Captcha_Helpers', false ) ) {
	class SurveyX_Captcha_Helpers {

		/**
		 * Determines which captcha type should be used based on settings.
		 *
		 * @param object|array $settings Settings object or array.
		 *
		 * }
		 */
		public static function get_active_captcha( $settings ) {
			// Check reCAPTCHA v2
			$recaptcha_enabled    = self::get_setting_value( $settings, 'recaptcha_v2_enabled' );
			$recaptcha_site_key   = self::get_setting_value( $settings, 'recaptcha_v2_site_key' );
			$recaptcha_secret_key = self::get_setting_value( $settings, 'recaptcha_v2_secret_key' );

			// reCAPTCHA v2 is available if enabled AND both keys are present
			if ( $recaptcha_enabled && ! empty( $recaptcha_site_key ) && ! empty( $recaptcha_secret_key ) ) {
				return [
					'type'     => 'recaptcha_v2',
					'site_key' => $recaptcha_site_key,
				];
			}

			return [
				'type'     => 'none',
				'site_key' => '',
			];
		}

		/**
		 * Helper to get setting value from object or array.
		 *
		 * @param object|array $settings Settings.
		 * @param string       $key Setting key.
		 *
		 * @return mixed Setting value or empty string.
		 */
		protected static function get_setting_value( $settings, $key ) {
			if ( is_object( $settings ) ) {
				return $settings->$key ?? '';
			}

			if ( is_array( $settings ) ) {
				return $settings[ $key ] ?? '';
			}

			return '';
		}

		/**
		 * Verifies reCAPTCHA v2 token with Google's API.
		 *
		 * @param string $token reCAPTCHA v2 response token.
		 * @param string $secret_key reCAPTCHA v2 secret key.
		 *
		 * @return bool True if verification successful, false otherwise.
		 */
		public static function verify_recaptcha_v2( $token, $secret_key ) {
			if ( empty( $secret_key ) || empty( $token ) ) {
				return false;
			}

			$remote_ip = '';
			if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
				$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			}

			$response = wp_remote_post(
				'https://www.google.com/recaptcha/api/siteverify',
				[
					'body' => [
						'secret'   => $secret_key,
						'response' => $token,
						'remoteip' => $remote_ip,
					],
				]
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$result = json_decode( wp_remote_retrieve_body( $response ), true );

			return ! empty( $result['success'] );
		}
	}
}
