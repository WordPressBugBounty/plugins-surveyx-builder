<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Question_Seen_Handler', false ) ) {
	/**
	 * Handles the /question-seen endpoint, recording a 'seen' response row.
	 *
	 * @since 1.0.0
	 */
	class SurveyX_Question_Seen_Handler {

		/**
		 * Main handler for question-seen endpoint.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response indicating success or failure.
		 */
		public static function handle( WP_REST_Request $request ) {
			global $wpdb;

			$body = SurveyX_Validation_Helper::validate_json_body( $request );

			if ( is_wp_error( $body ) ) {
				return SurveyX_Validation_Helper::error_response( $body );
			}

			$survey_id = SurveyX_Validation_Helper::validate_survey_id( $body );
			if ( is_wp_error( $survey_id ) ) {
				return SurveyX_Validation_Helper::error_response( $survey_id );
			}

			$question_id = SurveyX_Validation_Helper::validate_question_id( $body );
			if ( is_wp_error( $question_id ) ) {
				return SurveyX_Validation_Helper::error_response( $question_id );
			}

			$respondent_id = SurveyX_Validation_Helper::sanitize_uuid( $body['respondent_id'] ?? '' );

			if ( empty( $respondent_id ) ) {
				return new WP_REST_Response(
					[
						'message' => esc_html__( 'Invalid respondent ID', 'surveyx-builder' ),
					],
					400
				);
			}

			// Burst protection on analytics writes. The IP bucket runs FIRST and leaves
			// respondent_id out of its key, so minting a fresh UUID per request cannot
			// buy a fresh bucket (same pattern as /upload).
			$ip_rate_check = SurveyX_Validation_Helper::check_rate_limit( 'question_seen_ip', '', SurveyX_Validation_Helper::RATE_QUESTION_SEEN_IP, SurveyX_Validation_Helper::RATE_WINDOW );
			if ( is_wp_error( $ip_rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $ip_rate_check );
			}

			$rate_check = SurveyX_Validation_Helper::check_rate_limit( 'question_seen', (string) $respondent_id, SurveyX_Validation_Helper::RATE_QUESTION_SEEN, SurveyX_Validation_Helper::RATE_WINDOW );
			if ( is_wp_error( $rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $rate_check );
			}

			// Scope the question to the request's survey, exactly as /progress does: a
			// question_id belonging to another survey, or to none, would otherwise file a
			// phantom 'seen' row under the caller's chosen survey_id and skew analytics.
			if ( ! is_array( SurveyX_Db::get_question_content( $question_id, $survey_id ) ) ) {
				return SurveyX_Validation_Helper::error_response(
					new WP_Error(
						'invalid_question_id',
						esc_html__( 'Invalid question ID', 'surveyx-builder' ),
						[ 'status' => 400 ]
					)
				);
			}

			/*
			 * Identity + status only; the analytics path needs nothing more. The
			 * 'completed' test MUST carry the same restart_pending exception /progress
			 * uses: after Start Again the session is still 'completed' with
			 * restart_pending = 1 while the respondent is already back on question 1.
			 * Testing status alone wrote nothing for that whole window, so
			 * current_question_id kept pointing at the previous run's last question and
			 * drop-off analytics blamed it.
			 */
			$session = SurveyX_Session_Manager::get_session_id_status( $survey_id, $respondent_id );
			if ( ! $session || ( 'completed' === $session->session_status && empty( $session->restart_pending ) ) ) {
				return new WP_REST_Response( [ 'ok' => true ], 200 );
			}

			$now = surveyx_get_utc_now();

			// Idempotent: create_seen_response inserts only when no response exists yet,
			// so navigating Back to an already-viewed or already-answered question neither
			// duplicates the row nor overwrites the answer. /progress promotes the status.
			SurveyX_Db::create_seen_response(
				$session->id,
				$survey_id,
				$question_id,
				$respondent_id,
				'seen',
				$now
			);

			// Always advance, even when the question was already seen (Back navigation):
			// mark_stale_sessions_as_dropped leaves current_question_id untouched and
			// get_most_common_dropoff() groups dropped_off sessions by it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- per-request session write, caching it would defeat the purpose.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}surveyx_sessions
				SET current_question_id = %d, last_activity_at = %s
				WHERE survey_id = %d AND respondent_id = %s",
					$question_id,
					$now,
					$survey_id,
					$respondent_id
				)
			);

			return new WP_REST_Response(
				[
					'ok' => true,
				],
				200
			);
		}
	}
}
