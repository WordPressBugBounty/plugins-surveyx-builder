<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Progress_Handler', false ) ) {
	/**
	 * Handles the /progress endpoint - creates responses and updates session progress.
	 *
	 * @since 1.0.0
	 */
	class SurveyX_Progress_Handler {

		/**
		 * Main handler for update_progress endpoint.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response indicating success or failure.
		 */
		public static function handle( WP_REST_Request $request ) {
			// Validate and parse request body
			$body = SurveyX_Validation_Helper::validate_json_body( $request );
			if ( is_wp_error( $body ) ) {
				return SurveyX_Validation_Helper::error_response( $body );
			}

			// Extract and validate IDs
			$survey_id = SurveyX_Validation_Helper::validate_survey_id( $body );
			if ( is_wp_error( $survey_id ) ) {
				return SurveyX_Validation_Helper::error_response( $survey_id );
			}

			$question_id = SurveyX_Validation_Helper::validate_question_id( $body );
			if ( is_wp_error( $question_id ) ) {
				return SurveyX_Validation_Helper::error_response( $question_id );
			}

			// Extract request data
			$answer_ids    = $body['answer_ids'] ?? [];
			$content       = (array) ( $body['content'] ?? [] );
			$respondent_id = SurveyX_Validation_Helper::sanitize_uuid( $body['respondent_id'] ?? '' );

			// Fail fast on a missing/malformed respondent_id before any DB work.
			// Legitimate clients always send a valid UUID, so their path is unchanged.
			if ( empty( $respondent_id ) ) {
				return SurveyX_Validation_Helper::error_response(
					SurveyX_Validation_Helper::validate_respondent_id( $body )
				);
			}

			// Rate limit: burst protection against vote stuffing.
			$rate_check = SurveyX_Validation_Helper::check_rate_limit( 'progress', (string) $respondent_id, 60, 60 );
			if ( is_wp_error( $rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $rate_check );
			}

			// Get question content for validation
			$question_content = SurveyX_Db::get_question_content( $question_id );
			$question_type    = $question_content['type'] ?? 'check_box';
			$is_required      = $question_content['is_required'] ?? false;

			// Validate response based on question type
			$validation_result = SurveyX_Response_Types::validate( $question_type, $is_required, $answer_ids, $content, $question_content );
			if ( is_wp_error( $validation_result ) ) {
				return SurveyX_Validation_Helper::error_response( $validation_result );
			}

			// Get active session (authentication already validated during /init)
			$session = SurveyX_Session_Manager::get_active_session( $survey_id, $respondent_id );
			if ( ! $session ) {
				return new WP_REST_Response(
					[
						'message' => esc_html__( 'Session not found. Please refresh and try again.', 'surveyx-builder' ),
					],
					400
				);
			}

			// Create response and update session
			$result = self::create_response_and_update_session(
				$session,
				$survey_id,
				$question_id,
				$answer_ids,
				$content,
				$respondent_id,
				$question_content
			);

			if ( is_wp_error( $result ) ) {
				return SurveyX_Validation_Helper::error_response( $result );
			}

			return new WP_REST_Response(
				[
					'ok'      => true,
					'message' => esc_html__( 'Progress saved successfully!', 'surveyx-builder' ),
				],
				200
			);
		}

		/**
		 * Persist a single answer for a question (the vote-dedup hot path).
		 *
		 * Three named sub-steps, in order:
		 *   1. load-viewed-at — a prior response always stored a non-empty viewed_at,
		 *      so null unambiguously means "no prior response".
		 *   2. clear-old — only when a prior response exists (re-vote / Next→Back /
		 *      edit): DELETE the old rows, keeping the original viewed_at so the
		 *      first-seen timestamp is preserved across the DELETE-then-INSERT.
		 *   3. insert — create_responses() writes with INSERT IGNORE; the UNIQUE key
		 *      unique_response (session_id, question_id, respondent_id, answer_id)
		 *      makes a concurrent identical /progress POST a harmless no-op instead of
		 *      a duplicate row or error (this replaced the old GET_LOCK named lock).
		 *
		 * Behaviour is intentionally identical to the previous inline block — this is
		 * an extraction for legibility only. Do not change the DELETE-then-INSERT +
		 * INSERT IGNORE semantics.
		 *
		 * @param int    $session_id      Session ID.
		 * @param int    $survey_id       Survey ID.
		 * @param int    $question_id     Question ID.
		 * @param array  $answer_ids      Validated answer IDs.
		 * @param array  $content         Response content.
		 * @param string $respondent_id   Respondent UUID.
		 * @param string $response_status Response status ('answered' | 'skipped_optional').
		 * @param string $question_type   Question type.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private static function persist_answer( $session_id, $survey_id, $question_id, $answer_ids, $content, $respondent_id, $response_status, $question_type ) {
			// 1. load-viewed-at.
			$viewed_at = SurveyX_Db::get_response_viewed_at( $session_id, $question_id, $respondent_id );

			// 2. clear-old (only when a prior response exists).
			if ( null !== $viewed_at ) {
				SurveyX_Db::delete_responses_by_question( $session_id, $question_id, $respondent_id );
			}

			// 3. insert (preserving viewed_at when a prior response existed).
			$result = SurveyX_Db::create_responses(
				$session_id,
				$survey_id,
				$question_id,
				$answer_ids,
				$content,
				$respondent_id,
				$response_status,
				$question_type,
				$viewed_at
			);

			if ( ! $result ) {
				return new WP_Error(
					'save_failed',
					esc_html__( 'Failed to save response.', 'surveyx-builder' ),
					[ 'status' => 500 ]
				);
			}

			return true;
		}

		/**
		 * Create response and update session progress.
		 *
		 * @param object $session       Session object.
		 * @param int    $survey_id     Survey ID.
		 * @param int    $question_id   Question ID.
		 * @param array  $answer_ids       Array of answer IDs.
		 * @param array  $content          Response content.
		 * @param string $respondent_id    Respondent UUID.
		 * @param array  $question_content Question content (passed to avoid duplicate query).
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private static function create_response_and_update_session(
			$session,
			$survey_id,
			$question_id,
			$answer_ids,
			$content,
			$respondent_id,
			$question_content
		) {
			$session_id = $session->id;

			// Validate answer IDs (reuse already-loaded question content to avoid a re-query)
			$answer_ids = SurveyX_Validation_Helper::validate_answer_ids( $answer_ids, $question_id, $question_content );

			// Use passed question_content to determine response status
			$question_type = $question_content['type'] ?? 'check_box';
			$is_required   = $question_content['is_required'] ?? false;

			// Determine response status
			$response_status = SurveyX_Response_Types::determine_status( $question_type, $is_required, $answer_ids, $content );

			// Persist the answer (load-viewed-at / clear-old / insert). Keeps the
			// DELETE-then-INSERT + INSERT IGNORE vote-dedup semantics.
			$persisted = self::persist_answer(
				$session_id,
				$survey_id,
				$question_id,
				$answer_ids,
				$content,
				$respondent_id,
				$response_status,
				$question_type
			);

			if ( is_wp_error( $persisted ) ) {
				return $persisted;
			}

			// Responses changed: invalidate the cached /vote-results aggregate for this survey.
			SurveyX_Db::flush_vote_cache( $survey_id );

			// If question type is contact_info, save contact data to respondents table
			if ( 'contact_info' === $question_type && ! empty( $content ) ) {
				SurveyX_Session_Manager::update_contact_info(
					$respondent_id,
					$content
				);
			}

			// Update session progress - count from responses table
			// Reuse the already-loaded $session to skip a redundant session fetch.
			$answered_count = SurveyX_Db::count_answered_questions( $session->id, $respondent_id );
			SurveyX_Session_Manager::update_session_progress(
				$survey_id,
				$respondent_id,
				$question_id,
				$answered_count,
				$session
			);

			// Note: Session completion is handled by /complete-session endpoint
			// called from frontend when navigating to closing page

			return true;
		}
	}
}
