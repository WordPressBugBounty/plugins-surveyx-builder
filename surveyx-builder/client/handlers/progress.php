<?php

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

			$answer_ids    = $body['answer_ids'] ?? [];
			$content       = (array) ( $body['content'] ?? [] );
			$respondent_id = SurveyX_Validation_Helper::sanitize_uuid( $body['respondent_id'] ?? '' );

			// answer_ids must be a LIST. The recursive sanitizer preserves scalars, so
			// "answer_ids":"x" would slip past the empty() required-answer check as if the
			// question were answered, then reach count() in validate_answer_ids(). Reject
			// the shape rather than coerce it; a real client never sends it.
			if ( ! is_array( $answer_ids ) ) {
				return SurveyX_Validation_Helper::error_response(
					new WP_Error(
						'invalid_answer_ids',
						esc_html__( 'Invalid answer selection', 'surveyx-builder' ),
						[ 'status' => 400 ]
					)
				);
			}

			// Fail fast before any DB work; a legitimate client always sends a valid UUID.
			if ( empty( $respondent_id ) ) {
				return SurveyX_Validation_Helper::error_response(
					SurveyX_Validation_Helper::validate_respondent_id( $body )
				);
			}

			// Burst protection against vote stuffing. The IP bucket runs FIRST and leaves
			// respondent_id out of its key, so minting a fresh UUID per request cannot buy
			// a fresh bucket (same pattern as /upload).
			$ip_rate_check = SurveyX_Validation_Helper::check_rate_limit( 'progress_ip', '', SurveyX_Validation_Helper::RATE_PROGRESS_IP, SurveyX_Validation_Helper::RATE_WINDOW );
			if ( is_wp_error( $ip_rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $ip_rate_check );
			}

			$rate_check = SurveyX_Validation_Helper::check_rate_limit( 'progress', (string) $respondent_id, SurveyX_Validation_Helper::RATE_PROGRESS, SurveyX_Validation_Helper::RATE_WINDOW );
			if ( is_wp_error( $rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $rate_check );
			}

			// Scoped to the survey in the request: a question_id belonging to another
			// survey, or to none, must be rejected here, or the ?? defaults below treat it
			// as an optional check_box and file an 'answered' row under the caller's
			// chosen survey_id.
			$question_content = SurveyX_Db::get_question_content( $question_id, $survey_id );
			if ( ! is_array( $question_content ) ) {
				return SurveyX_Validation_Helper::error_response(
					new WP_Error(
						'invalid_question_id',
						esc_html__( 'Invalid question ID', 'surveyx-builder' ),
						[ 'status' => 400 ]
					)
				);
			}

			$question_type = $question_content['type'] ?? 'check_box';
			$is_required   = $question_content['is_required'] ?? false;

			// Same malformed-shape class as answer_ids, one level down: every type except
			// matrix and file_upload reads its content values as scalars (trim(), string
			// casts), so "content":{"message":{"a":1}} reaches trim() as an array and
			// fatals. Those two (Pro types) are the only ones with a nested content shape.
			if ( ! in_array( $question_type, [ 'matrix', 'file_upload' ], true ) ) {
				foreach ( $content as $value ) {
					if ( is_array( $value ) ) {
						return SurveyX_Validation_Helper::error_response(
							new WP_Error(
								'invalid_content',
								esc_html__( 'Invalid answer content', 'surveyx-builder' ),
								[ 'status' => 400 ]
							)
						);
					}
				}
			}

			$validation_result = self::validate_response( $question_type, $is_required, $answer_ids, $content, $question_content );
			if ( is_wp_error( $validation_result ) ) {
				return SurveyX_Validation_Helper::error_response( $validation_result );
			}

			// Authorization was established at /init; this only re-reads it.
			$session = SurveyX_Session_Manager::get_active_session( $survey_id, $respondent_id );
			if ( ! $session ) {
				return new WP_REST_Response(
					[
						'message' => esc_html__( 'Session not found. Please refresh and try again.', 'surveyx-builder' ),
					],
					400
				);
			}

			/*
			 * A completed session is closed to further answers. Nothing legitimate posts
			 * here: the client awaits /progress before firing /complete-session, and a
			 * restarted session is returned to 'active' by /activate-session (which
			 * submitAnswer awaits) before its first answer - so restart_pending is the one
			 * status-is-'completed' case that MUST pass.
			 *
			 * This stops a replay (second tab, browser-retried POST, a submit after the
			 * end screen). Ignoring one is not safe: create_response_and_update_session()
			 * DELETEs the question's stored answers before re-inserting, and an empty
			 * answer list is filed as the skipped_optional sentinel, so a replay carrying
			 * no answers would ERASE the answer the respondent actually gave.
			 */
			if ( 'completed' === $session->session_status && empty( $session->restart_pending ) ) {
				return new WP_REST_Response(
					[
						'code'    => 'surveyx_session_completed',
						'message' => esc_html__( 'This survey session is already complete.', 'surveyx-builder' ),
					],
					409
				);
			}

			// Stamped BEFORE the write, deliberately: the client overlays this vote onto the cached
			// tally only while this stamp is newer than the tally's generated_at. Stamping after the
			// insert could outrun a tally that already counts the vote, and show it twice.
			$write_time = surveyx_get_utc_now();

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
					'ok'          => true,
					'message'     => esc_html__( 'Progress saved successfully!', 'surveyx-builder' ),
					'answered_at' => $write_time,
				],
				200
			);
		}

		/**
		 * Validate response based on question type.
		 *
		 * @param string $question_type    Question type.
		 * @param bool   $is_required      Whether question is required.
		 * @param array  $answer_ids       Array of answer IDs.
		 * @param array  $content          Response content.
		 * @param array  $question_content Question content with settings.
		 * @return true|WP_Error True if valid, WP_Error on failure.
		 */
		private static function validate_response( $question_type, $is_required, $answer_ids, &$content, $question_content = [] ) {
			if ( 'text_input' === $question_type ) {
				$validation = SurveyX_Validation_Helper::validate_text_input( $content, $is_required );

				if ( is_wp_error( $validation ) ) {
					return $validation;
				}

				// Normalize an omitted optional answer to a stored empty string.
				if ( ! $is_required && ( ! isset( $content['message'] ) || empty( trim( $content['message'] ) ) ) ) {
					$content = [ 'message' => '' ];
				}
			} elseif ( $is_required && empty( $answer_ids ) ) {
				return new WP_Error(
					'missing_answer',
					esc_html__( 'Please select an answer', 'surveyx-builder' ),
					[ 'status' => 400 ]
				);
			}

			return true;
		}

		/**
		 * Determine response status based on question type, required flag, and response data.
		 *
		 * @param string $question_type Question type (text_input, check_box, etc).
		 * @param bool   $is_required   Whether question is required.
		 * @param array  $answer_ids    Array of selected answer IDs.
		 * @param array  $content       Response content.
		 * @return string Response status: 'answered' or 'skipped_optional'.
		 */
		private static function determine_response_status( $question_type, $is_required, $answer_ids, $content ) {
			if ( $is_required ) {
				return 'answered';
			}

			if ( 'text_input' === $question_type ) {
				$message = trim( $content['message'] ?? '' );
				return empty( $message ) ? 'skipped_optional' : 'answered';
			}

			if ( 'contact_info' === $question_type ) {
				$has_data = false;
				foreach ( $content as $value ) {
					if ( ! empty( trim( (string) $value ) ) ) {
						$has_data = true;
						break;
					}
				}
				return $has_data ? 'answered' : 'skipped_optional';
			}

			if ( 'opinion_scale' === $question_type || 'rating' === $question_type ) {
				return ( isset( $content['value'] ) && null !== $content['value'] ) ? 'answered' : 'skipped_optional';
			}

			if ( 'date' === $question_type ) {
				$month = trim( $content['month'] ?? '' );
				$day   = trim( $content['day'] ?? '' );
				$year  = trim( $content['year'] ?? '' );
				return ( ! empty( $month ) && ! empty( $day ) && ! empty( $year ) ) ? 'answered' : 'skipped_optional';
			}

			return empty( $answer_ids ) ? 'skipped_optional' : 'answered';
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

			$submitted_ids = $answer_ids;
			$answer_ids    = SurveyX_Validation_Helper::validate_answer_ids( $answer_ids, $question_id, $question_content );

			// The ownership filter dropped EVERY named answer id: nothing is left to
			// store, and on a required question the insert below would file the "no
			// answer" sentinel as 'answered'. Refuse with a 400, as a foreign question_id
			// does, rather than a 200 that silently discards the answer. No legitimate
			// client gets here - "Other" posts id 0 (kept by the filter), the negative
			// sentinels are server-written and never sent back (prefill replays only
			// answer_id >= 0), and non-choice types post no ids. A PARTIAL drop still
			// stores what is valid, so one stale option does not fail the submission.
			if ( ! empty( $submitted_ids ) && empty( $answer_ids ) ) {
				return new WP_Error(
					'invalid_answer_ids',
					esc_html__( 'Invalid answer selection', 'surveyx-builder' ),
					[ 'status' => 400 ]
				);
			}

			$question_type = $question_content['type'] ?? 'check_box';
			$is_required   = $question_content['is_required'] ?? false;

			$response_status = self::determine_response_status( $question_type, $is_required, $answer_ids, $content );

			/*
			 * Dedup read-modify-write. On a MIGRATED install the DATABASE enforces it -
			 * UNIQUE unique_response (session_id, question_id, respondent_id, answer_id)
			 * plus the INSERT IGNORE in create_responses() make a racing identical POST a
			 * no-op - and the named lock below is never taken.
			 *
			 * NOT vestigial: an install upgraded from a build predating that key still
			 * needs it. New files do not create the key, because the step that adds it
			 * ([responses_unique_constraint]) ends in an ALTER and the runner's DDL gate
			 * refuses DDL on a REST request - which /progress is. So between the file swap
			 * and the first wp-admin render, cron tick or WP-CLI run there is no unique
			 * key, and without this lock two simultaneous identical POSTs both read "no
			 * prior response", both DELETE and both INSERT, counting the answer twice.
			 *
			 * It has a defined end of life: once [responses_unique_constraint] is recorded
			 * the condition is false forever (one autoloaded option read, no query, no
			 * lock). Delete this branch - with get_response_lock_name(), acquire_lock()
			 * and release_lock() - only when no supported upgrade path can start from a
			 * database without the key.
			 */
			$lock_name = surveyx_responses_unique_key_migrated()
				? ''
				: self::get_response_lock_name( $session_id, $question_id, $respondent_id );

			$lock_acquired = '' !== $lock_name && self::acquire_lock( $lock_name );

			try {
				// A row always stores a non-empty viewed_at, so null unambiguously means
				// "no prior response".
				$viewed_at = SurveyX_Db::get_response_viewed_at( $session_id, $question_id, $respondent_id );

				if ( null !== $viewed_at ) {
					// Already seen/answered (allow_return or editing mode). The original
					// viewed_at is carried over, so DELETE-then-INSERT preserves first-seen.
					SurveyX_Db::delete_responses_by_question( $session_id, $question_id, $respondent_id );
				}

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
			} finally {
				if ( $lock_acquired ) {
					self::release_lock( $lock_name );
				}
			}

			if ( ! $result ) {
				return new WP_Error(
					'save_failed',
					esc_html__( 'Failed to save response.', 'surveyx-builder' ),
					[ 'status' => 500 ]
				);
			}

			if ( 'contact_info' === $question_type && ! empty( $content ) ) {
				SurveyX_Session_Manager::update_contact_info(
					$respondent_id,
					$content
				);
			}

			// $session is passed on to skip a redundant session fetch.
			$answered_count = SurveyX_Db::count_answered_questions( $session->id, $respondent_id );
			SurveyX_Session_Manager::update_session_progress(
				$survey_id,
				$respondent_id,
				$question_id,
				$answered_count,
				$session
			);

			// Completion is NOT set here: /complete-session does it, fired by the client
			// when it navigates to the closing page.

			return true;
		}

		/**
		 * Build a MySQL named-lock key for the dedup critical section.
		 *
		 * MySQL lock names are capped at 64 chars; hashing the identifiers stays well
		 * under it and avoids collisions. Only ever called on an install where
		 * unique_response is still missing - see create_response_and_update_session()
		 * for why that state exists and when it ends.
		 *
		 * @param int    $session_id    Session ID.
		 * @param int    $question_id   Question ID.
		 * @param string $respondent_id Respondent UUID.
		 * @return string Named-lock key.
		 */
		private static function get_response_lock_name( $session_id, $question_id, $respondent_id ) {
			return 'sx_resp_' . md5( $session_id . '|' . $question_id . '|' . $respondent_id );
		}

		/**
		 * Acquire a short-lived MySQL named lock. Fails open (returns false) so a
		 * response is still stored even if the lock cannot be obtained.
		 *
		 * @param string $lock_name Lock key.
		 * @return bool True if the lock was acquired.
		 */
		private static function acquire_lock( $lock_name ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) );

			return '1' === (string) $got;
		}

		/**
		 * Release a previously acquired MySQL named lock.
		 *
		 * @param string $lock_name Lock key.
		 * @return void
		 */
		private static function release_lock( $lock_name ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}
