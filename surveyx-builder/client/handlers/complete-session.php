<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Complete_Session_Handler', false ) ) {
	/**
	 * Handles the /complete-session endpoint - marks the session 'completed'.
	 *
	 * @since 1.0.0
	 */
	class SurveyX_Complete_Session_Handler {

		/**
		 * Main handler for complete-session endpoint.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response indicating success or failure.
		 */
		public static function handle( WP_REST_Request $request ) {
			$body = SurveyX_Validation_Helper::validate_json_body( $request );
			if ( is_wp_error( $body ) ) {
				return SurveyX_Validation_Helper::error_response( $body );
			}

			$survey_id = SurveyX_Validation_Helper::validate_survey_id( $body );
			if ( is_wp_error( $survey_id ) ) {
				return SurveyX_Validation_Helper::error_response( $survey_id );
			}

			$respondent_id = SurveyX_Validation_Helper::validate_respondent_id( $body );
			if ( is_wp_error( $respondent_id ) ) {
				return SurveyX_Validation_Helper::error_response( $respondent_id );
			}

			// Rate limit, IP-only: respondent_id is self-issued, so a per-respondent
			// bucket would reset on every rotated UUID and protect nothing. After the
			// cheap validation above so junk writes no transient.
			$ip_rate_check = SurveyX_Validation_Helper::check_rate_limit( 'complete_session_ip', '', SurveyX_Validation_Helper::RATE_COMPLETE_SESSION_IP, SurveyX_Validation_Helper::RATE_WINDOW );
			if ( is_wp_error( $ip_rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $ip_rate_check );
			}

			$result = SurveyX_Session_Manager::complete_session( $survey_id, $respondent_id );

			return new WP_REST_Response(
				[
					'ok' => $result,
				],
				200
			);
		}
	}
}
