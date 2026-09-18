<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SurveyX_Request_Helper', false ) ) {
	/**
	 * Helper class for resolving the visitor's IP address and, through the surveyx_get_location
	 * filter, their location. The IP itself is never stored - see get_request_data().
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

			$trust_proxy_headers = apply_filters( 'surveyx_trust_proxy_headers', false );

			if ( $trust_proxy_headers ) {
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

			if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
				$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

				if ( filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
					return $ip_address;
				}
			}

			return '';
		}

		/**
		 * Gets location data for the given IP address.
		 *
		 * Ships with no provider, so the return is empty unless a site hooks
		 * surveyx_get_location. The IP is resolved only to hand to that filter, never stored.
		 *
		 * @param string $ip_address The IP address to get location for.
		 *
		 * @return array Location data array with keys: country, region, city.
		 */
		public static function get_location( $ip_address = '' ) {
			if ( empty( $ip_address ) ) {
				$ip_address = self::get_ip_address();
			}

			$location = [
				'country' => '',
				'region'  => '',
				'city'    => '',
			];

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
		 * Collects location only, and only when the site hooks surveyx_get_location to supply a
		 * geolocation provider. ip_address and user_agent come back empty on purpose: no query
		 * in either edition SELECTs those columns, so filling them would store a respondent's IP
		 * and browser string that nothing can read. Both keys stay in the array because
		 * SurveyX_Session_Manager::create_session() writes all three columns, and its upsert
		 * leaves a column untouched when the incoming value is '' - values an older build
		 * already stored are preserved, not cleared.
		 *
		 * @return array Associative array with ip_address, user_agent, and location keys.
		 */
		public static function get_request_data() {
			$location = self::get_location();

			// Encoded only once a provider resolved something, or every row would carry a
			// constant {"country":"","region":"","city":""}.
			return [
				'ip_address' => '',
				'user_agent' => '',
				'location'   => ( is_array( $location ) && array_filter( $location ) ) ? wp_json_encode( $location ) : '',
			];
		}
	}
}
