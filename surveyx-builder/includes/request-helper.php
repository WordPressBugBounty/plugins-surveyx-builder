<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SurveyX_Request_Helper', false ) ) {
	/**
	 * Helper class for collecting request data like IP address, user agent, and location.
	 */
	class SurveyX_Request_Helper {

		/**
		 * Gets the user's IP address.
		 *
		 * By default only uses REMOTE_ADDR for security. Proxy headers can be spoofed.
		 * Use 'surveyx_trusted_proxy_headers' filter to enable proxy headers if behind a trusted proxy.
		 *
		 * @return string The user's IP address or empty string if not available.
		 */
		public static function get_ip_address() {
			$ip_address = '';

			// Default: Only trust REMOTE_ADDR (secure)
			// Enable proxy headers only if behind a trusted load balancer/proxy
			$trust_proxy_headers = apply_filters( 'surveyx_trust_proxy_headers', false );

			if ( $trust_proxy_headers ) {
				// Check proxy headers only if explicitly enabled
				$proxy_headers = [
					'HTTP_X_FORWARDED_FOR',
					'HTTP_X_REAL_IP',
					'HTTP_CLIENT_IP',
				];

				foreach ( $proxy_headers as $header ) {
					if ( ! empty( $_SERVER[ $header ] ) ) {
						$ip_address = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

						// Handle multiple IPs (take the first one - original client IP)
						if ( false !== strpos( $ip_address, ',' ) ) {
							$ip_list    = explode( ',', $ip_address );
							$ip_address = trim( $ip_list[0] );
						}

						if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
							return $ip_address;
						}

						$ip_address = '';
					}
				}
			}

			// Always fall back to REMOTE_ADDR
			if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
				$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

				if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
					return $ip_address;
				}
			}

			return '';
		}

		/**
		 * Gets the user's user agent string.
		 *
		 * @return string The user agent string or empty string if not available.
		 */
		public static function get_user_agent() {
			if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
			}

			return '';
		}

		/**
		 * Gets location data for the given IP address.
		 * This is a placeholder - implement your own geolocation service.
		 *
		 * @param string $ip_address The IP address to get location for.
		 *
		 * @return array Location data array with keys: country, region, city.
		 */
		public static function get_location( $ip_address = '' ) {
			if ( empty( $ip_address ) ) {
				$ip_address = self::get_ip_address();
			}

			// Default empty location.
			$location = [
				'country' => '',
				'region'  => '',
				'city'    => '',
			];

			// Skip for local IPs.
			if ( empty( $ip_address ) || self::is_local_ip( $ip_address ) ) {
				return $location;
			}

			/**
			 * Filter to allow custom geolocation implementation.
			 *
			 * @param array  $location    Default location data.
			 * @param string $ip_address  The IP address to geolocate.
			 */
			return apply_filters( 'surveyx_get_location', $location, $ip_address );
		}

		/**
		 * Checks if an IP address is a local/private IP.
		 *
		 * @param string $ip_address The IP address to check.
		 *
		 * @return bool True if local IP, false otherwise.
		 */
		private static function is_local_ip( $ip_address ) {
			if ( empty( $ip_address ) ) {
				return true;
			}

			// Check for localhost.
			if ( '127.0.0.1' === $ip_address || '::1' === $ip_address ) {
				return true;
			}

			// Check for private IP ranges.
			return ! filter_var(
				$ip_address,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		/**
		 * Gets all request data in a single call.
		 *
		 * @return array Associative array with ip_address, user_agent, and location keys.
		 */
		public static function get_request_data() {
			$ip_address = self::get_ip_address();
			$user_agent = self::get_user_agent();
			$location   = self::get_location( $ip_address );

			return [
				'ip_address' => $ip_address,
				'user_agent' => $user_agent,
				'location'   => wp_json_encode( $location ),
			];
		}
	}
}
