<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Captcha_Helpers', false ) ) {
	class SurveyX_Captcha_Helpers {

		/**
		 * Captcha providers supported by the FREE plugin.
		 *
		 * Free supports reCAPTCHA v2 only, and must never resolve to reCAPTCHA v3 or
		 * Turnstile even when `captcha_provider` asks for them.
		 *
		 * @return string[] Supported provider identifiers.
		 */
		protected static function get_supported_providers() {
			return [ 'recaptcha_v2' ];
		}

		/**
		 * Determines which captcha type should be used based on settings.
		 *
		 * Resolution order:
		 * 1. `captcha_provider`, the source of truth — the provider must be supported by
		 *    THIS version AND have both keys.
		 * 2. Back-compat for old installs where it is unset/empty: the legacy
		 *    `*_enabled` + keys priority logic.
		 *
		 * @param object|array $settings Settings object or array.
		 *
		 * @return array {
		 *     @type string $type     Captcha type: 'none' or 'recaptcha_v2'.
		 *     @type string $site_key Site key for the selected captcha.
		 * }
		 */
		public static function get_active_captcha( $settings ) {
			$provider = trim( (string) self::get_setting_value( $settings, 'captcha_provider' ) );

			if ( '' !== $provider ) {
				return self::resolve_provider( $settings, $provider );
			}

			return self::resolve_legacy_captcha( $settings );
		}

		/**
		 * Resolves a captcha from the explicit `captcha_provider` setting.
		 *
		 * @param object|array $settings Settings.
		 * @param string       $provider Requested provider identifier.
		 *
		 * @return array Resolved captcha array (type + site_key).
		 */
		protected static function resolve_provider( $settings, $provider ) {
			$none = [
				'type'     => 'none',
				'site_key' => '',
			];

			if ( ! in_array( $provider, self::get_supported_providers(), true ) ) {
				return $none;
			}

			$site_key   = self::get_setting_value( $settings, $provider . '_site_key' );
			$secret_key = self::get_setting_value( $settings, $provider . '_secret_key' );

			if ( empty( $site_key ) || empty( $secret_key ) ) {
				return $none;
			}

			return [
				'type'     => $provider,
				'site_key' => $site_key,
			];
		}

		/**
		 * Legacy resolver based on the `*_enabled` flags (backward compat).
		 *
		 * @param object|array $settings Settings.
		 *
		 * @return array Resolved captcha array (type + site_key).
		 */
		protected static function resolve_legacy_captcha( $settings ) {
			$recaptcha_enabled    = self::get_setting_value( $settings, 'recaptcha_v2_enabled' );
			$recaptcha_site_key   = self::get_setting_value( $settings, 'recaptcha_v2_site_key' );
			$recaptcha_secret_key = self::get_setting_value( $settings, 'recaptcha_v2_secret_key' );

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
		 * Reads a setting from either an object or an array shape.
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

			// Validated, not merely sanitised - matching the v3 and Turnstile verifiers.
			// A malformed REMOTE_ADDR is sent as empty rather than forwarded to the
			// provider, which is the only value they accept besides a real address.
			$remote_ip = '';
			if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					$remote_ip = $ip;
				}
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
