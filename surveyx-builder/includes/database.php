<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SurveyX_Db', false ) ) {
	class SurveyX_Db {

		/**
		 * Checks if a survey exists in the database by its ID.
		 *
		 * @param int $survey_id The ID of the survey to check.
		 *
		 * @return bool True if the survey exists, false otherwise.
		 */
		public static function survey_exists( $survey_id ) {
			global $wpdb;

			if ( empty( $survey_id ) ) {
				return false;
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$survey = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}surveyx_surveys WHERE id = %d LIMIT 1",
					$survey_id
				)
			);

			return ! empty( $survey );
		}

		/**
		 * Gets the minimal survey row needed to render the shortcode in a single query.
		 *
		 * Consolidates the previous per-call queries (survey_exists, get_survey_mode,
		 * get_survey_settings) into one row. Memoized per survey_id for the current
		 * request so repeated shortcode/enqueue passes reuse the same result.
		 *
		 * @param int $survey_id Survey ID.
		 *
		 * @return object|null Row with `id`, `s_mode`, `status` and decoded `settings`
		 *                     (array|null), or null if the survey does not exist.
		 */
		public static function get_survey_render_row( $survey_id ) {
			static $cache = [];

			$survey_id = absint( $survey_id );

			if ( empty( $survey_id ) ) {
				return null;
			}

			if ( array_key_exists( $survey_id, $cache ) ) {
				return $cache[ $survey_id ];
			}

			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, s_mode, settings, status FROM {$wpdb->prefix}surveyx_surveys WHERE id = %d LIMIT 1",
					$survey_id
				)
			);

			if ( ! is_null( $row ) ) {
				$row->settings = json_decode( $row->settings, true );
			}

			$cache[ $survey_id ] = $row;

			return $row;
		}

		/**
		 * Delete existing responses for a specific question when user returns and re-answers.
		 * Also decrements vote counts for previously selected answers.
		 *
		 * @param int    $session_id    The ID of the session.
		 * @param int    $question_id   The ID of the question.
		 * @param string $respondent_id The respondent UUID.
		 *
		 * @return bool Returns true if successful, false on failure.
		 */
		public static function delete_responses_by_question( $session_id, $question_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete(
				$wpdb->prefix . 'surveyx_responses',
				[
					'session_id'    => $session_id,
					'question_id'   => $question_id,
					'respondent_id' => $respondent_id,
				],
				[ '%d', '%d', '%s' ]
			);

			return false !== $deleted;
		}

		/**
		 * Delete all responses for a session (used when restarting survey).
		 *
		 * @param int    $session_id    Session ID.
		 * @param string $respondent_id Respondent UUID.
		 * @return bool True on success, false on failure.
		 */
		public static function delete_all_responses_by_session( $session_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete(
				$wpdb->prefix . 'surveyx_responses',
				[
					'session_id'    => $session_id,
					'respondent_id' => $respondent_id,
				],
				[ '%d', '%s' ]
			);

			return false !== $deleted;
		}

		/**
		 * Reset all sessions for a survey to allow revoting.
		 *
		 * Instead of deleting data, this resets the session state:
		 * - session_status: 'completed' -> 'expired'
		 * - completed_at: set to NULL
		 * - progress_percentage: reset to 0
		 * - current_question_id: reset to 0
		 *
		 * This keeps historical data for analytics while allowing new votes.
		 *
		 * @param int $survey_id The survey ID.
		 *
		 * @return int|false Number of rows updated, or false on error.
		 */
		public static function reset_sessions_for_survey( $survey_id ) {
			global $wpdb;

			// Only reset finished participations (completed / dropped_off). In-progress
			// ('active') and just-opened ('viewed') sessions are left untouched so a
			// respondent mid-survey is not yanked out.

			// Clear those sessions' responses up front (the feature's promise:
			// "clear all previous responses"), so every response-based analytic is
			// immediately consistent — no stale votes linger until each respondent returns.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE r FROM {$wpdb->prefix}surveyx_responses r
					INNER JOIN {$wpdb->prefix}surveyx_sessions s ON r.session_id = s.id
					WHERE s.survey_id = %d AND s.session_status IN ('completed', 'dropped_off')",
					$survey_id
				)
			);

			// Bulk 'expired' reset across many sessions. Kept as one UPDATE (rather than
			// routed through SurveyX_Session_Manager::set_session_state(), which writes a
			// single row) to avoid an N-query loop; the SET list is the canonical
			// 'expired' column set and deliberately leaves restart_pending / last_activity_at
			// untouched to preserve history.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}surveyx_sessions
					SET session_status = 'expired', completed_at = NULL, progress_percentage = 0, current_question_id = 0
					WHERE survey_id = %d AND session_status IN ('completed', 'dropped_off')",
					$survey_id
				)
			);

			// Invalidate the cached summary so analytics recompute without the just-reset
			// (now 'expired') sessions instead of serving stale numbers for up to 6h.
			delete_transient( 'surveyx_summary_' . $survey_id );

			// Flush the respondent-facing vote cache so /vote-results doesn't serve the
			// pre-reset counts for up to its 60s TTL.
			self::flush_vote_cache( $survey_id );

			return $result;
		}

		/**
		 * Check if survey questions/answers count has changed.
		 * Only triggers on add/delete, not on content edits.
		 *
		 * @param int   $survey_id     Survey ID.
		 * @param array $new_questions New questions array.
		 * @param array $new_answers   New answers array.
		 * @return bool True if count changed (added/deleted), false otherwise.
		 */
		public static function has_count_changed( $survey_id, $new_questions, $new_answers ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_question_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}surveyx_questions WHERE survey_id = %d",
					$survey_id
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_answer_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}surveyx_answers WHERE survey_id = %d",
					$survey_id
				)
			);

			$new_question_count = count( $new_questions );
			$new_answer_count   = count( $new_answers );

			return $new_question_count !== $existing_question_count
				|| $new_answer_count !== $existing_answer_count;
		}

		/**
		 * Creates a response record for a question.
		 *
		 * Response status values:
		 * - 'answered': Normal response (choice or text input)
		 * - 'skipped_optional': User skipped optional question
		 *
		 * Special answer_id values:
		 * - > 0: Regular choice answers (from DB)
		 * - 0: Other option
		 * - -2: text_input question
		 * - -3: contact_info question
		 * - -4: skipped_optional
		 * - -8: seen (question viewed, not answered yet)
		 *
		 * Note: Skip logic questions do NOT create response records.
		 *
		 * @param int         $session_id      The ID of the session.
		 * @param int         $survey_id       The ID of the survey.
		 * @param int         $question_id     The ID of the question.
		 * @param array       $answer_ids      Array of selected answer IDs (empty for text/skipped).
		 * @param mixed       $content         The content for text input questions.
		 * @param string      $respondent_id   The respondent identifier for tracking.
		 * @param string      $response_status Response status: 'answered' or 'skipped_optional'.
		 * @param string      $question_type   Question type for determining special answer_id.
		 * @param string|null $viewed_at       Timestamp when question was viewed (preserves from existing record).
		 *
		 * @return bool Returns true if successful, false on failure.
		 */
		public static function create_responses( $session_id, $survey_id, $question_id, $answer_ids, $content, $respondent_id, $response_status = 'answered', $question_type = 'check_box', $viewed_at = null ) {
			global $wpdb;

			$now = surveyx_get_utc_now();

			if ( empty( $viewed_at ) ) {
				$viewed_at = $now;
			}

			$valid_statuses = [ 'answered', 'skipped_optional' ];
			if ( ! in_array( $response_status, $valid_statuses, true ) ) {
				$response_status = 'answered';
			}

			// For text input, contact_info, or skipped (no answer_ids).
			if ( empty( $answer_ids ) ) {
				$response_content  = ! empty( $content ) ? wp_json_encode( $content ) : '';
				$special_answer_id = SurveyX_Response_Types::special_answer_id( $question_type, $response_status );
				$table             = $wpdb->prefix . 'surveyx_responses';

				// INSERT IGNORE: the unique_response key (session_id, question_id,
				// respondent_id, answer_id) makes a concurrent identical submit a harmless
				// no-op instead of a duplicate row or a duplicate-key error.
				// Table is a trusted $wpdb->prefix identifier; all values are bound via prepare().
				// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
				$result = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$table}
							(session_id, survey_id, question_id, answer_id, respondent_id, response_content, response_status, viewed_at, answered_at)
							VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s)",
						$session_id,
						$survey_id,
						$question_id,
						$special_answer_id,
						$respondent_id,
						$response_content,
						$response_status,
						$viewed_at,
						$now
					)
				);
				// phpcs:enable

				return false !== $result;
			}

			// For multiple choice questions with answer_ids (always 'answered' status).
			// Build a single multi-row INSERT to minimize round-trips.
			$placeholders = [];
			$values       = [];

			foreach ( $answer_ids as $answer_id ) {
				$response_content = '';
				if ( 0 === (int) $answer_id && ! empty( $content['other_text'] ) ) {
					$response_content = wp_json_encode( [ 'other_text' => $content['other_text'] ] );
				}

				$placeholders[] = '(%d, %d, %d, %d, %s, %s, %s, %s, %s)';
				array_push(
					$values,
					$session_id,
					$survey_id,
					$question_id,
					$answer_id,
					$respondent_id,
					$response_content,
					'answered',
					$viewed_at,
					$now
				);
			}

			$table = $wpdb->prefix . 'surveyx_responses';
			// INSERT IGNORE: with the unique_response key, a concurrent identical submit
			// (same session/question/respondent/answer_id) is skipped rather than
			// duplicated or erroring — the guarantee the removed named lock provided.
			$sql = "INSERT IGNORE INTO {$table}
				(session_id, survey_id, question_id, answer_id, respondent_id, response_content, response_status, viewed_at, answered_at)
				VALUES " . implode( ', ', $placeholders );

			// Table is a trusted $wpdb->prefix identifier; all values are bound via prepare().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

			return false !== $result;
		}

		/**
		 * Creates a "seen" response record when question is first viewed.
		 *
		 * @param int    $session_id    Session ID.
		 * @param int    $survey_id     Survey ID.
		 * @param int    $question_id   Question ID.
		 * @param string $respondent_id Respondent UUID.
		 * @param string $status        Response status: 'seen' or 'skipped_optional'.
		 * @param string $viewed_at     Timestamp when question was viewed.
		 * @return bool True on success, false on failure.
		 */
		public static function create_seen_response( $session_id, $survey_id, $question_id, $respondent_id, $status, $viewed_at ) {
			global $wpdb;

			$answer_id = ( 'seen' === $status ) ? SurveyX_Response_Types::SEEN : SurveyX_Response_Types::SKIPPED;
			$table     = $wpdb->prefix . 'surveyx_responses';

			// Idempotent insert: create the row only if no response yet exists for
			// this (session, question, respondent). This lets the /question-seen
			// handler drop its separate existence COUNT query, and guarantees a
			// single 'seen' row even when the respondent navigates back to an
			// already-viewed (or already-answered) question. INSERT IGNORE keeps a
			// concurrent second seen-insert (which passes NOT EXISTS but would then
			// hit the unique_response key) a harmless no-op instead of an error.
			// Table is a trusted $wpdb->prefix identifier; all values are bound via prepare().
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table}
						(session_id, survey_id, question_id, answer_id, respondent_id, response_content, response_status, viewed_at, answered_at)
					SELECT %d, %d, %d, %d, %s, '', %s, %s, %s FROM DUAL
					WHERE NOT EXISTS (
						SELECT 1 FROM {$table}
						WHERE session_id = %d AND question_id = %d AND respondent_id = %s
					)",
					$session_id,
					$survey_id,
					$question_id,
					$answer_id,
					$respondent_id,
					$status,
					$viewed_at,
					$viewed_at,
					$session_id,
					$question_id,
					$respondent_id
				)
			);
			// phpcs:enable

			return false !== $result;
		}

		/**
		 * Gets viewed_at timestamp from existing response for a question.
		 *
		 * @param int    $session_id    Session ID.
		 * @param int    $question_id   Question ID.
		 * @param string $respondent_id Respondent UUID.
		 * @return string|null Viewed_at timestamp or null if not found.
		 */
		public static function get_response_viewed_at( $session_id, $question_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT viewed_at FROM {$wpdb->prefix}surveyx_responses
                    WHERE session_id = %d AND question_id = %d AND respondent_id = %s
                    LIMIT 1",
					$session_id,
					$question_id,
					$respondent_id
				)
			);
		}

		/**
		 * Calculates time spent on survey from responses.
		 * Uses MIN(viewed_at) to MAX(answered_at) for accurate time tracking.
		 *
		 * @param int    $session_id    Session ID.
		 * @param string $respondent_id Respondent UUID.
		 * @return int Time spent in seconds, or 0 if no data.
		 */
		public static function get_session_time_spent( $session_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
                        MIN(viewed_at) as first_viewed,
                        MAX(answered_at) as last_answered
                    FROM {$wpdb->prefix}surveyx_responses
                    WHERE session_id = %d
                    AND respondent_id = %s
                    AND response_status = 'answered'",
					$session_id,
					$respondent_id
				)
			);

			if ( ! $result || ! $result->first_viewed || ! $result->last_answered ) {
				return 0;
			}

			// Stored values are always UTC; parse them as UTC explicitly instead of
			// relying on WP having set PHP's default timezone to UTC.
			$start = strtotime( $result->first_viewed . ' UTC' );
			$end   = strtotime( $result->last_answered . ' UTC' );

			return max( 0, $end - $start );
		}

		/**
		 * Counts distinct answered questions for a session.
		 *
		 * @param int    $session_id    Session ID.
		 * @param string $respondent_id Respondent UUID.
		 * @return int Number of answered questions.
		 */
		public static function count_answered_questions( $session_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT question_id) FROM {$wpdb->prefix}surveyx_responses
                    WHERE session_id = %d AND respondent_id = %s AND response_status = 'answered'",
					$session_id,
					$respondent_id
				)
			);
		}

		/**
		 * Retrieves and decodes the survey settings for a given survey ID.
		 *
		 * @param int $survey_id The ID of the survey to retrieve settings for.
		 *
		 * @return array|null Returns the decoded settings as an array on success, or null if not found.
		 */
		public static function get_survey_settings( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT settings FROM {$wpdb->prefix}surveyx_surveys WHERE id = %d",
					$survey_id
				)
			);

			if ( is_null( $result ) ) {
				return null;
			}

			return json_decode( $result, true );
		}

		/**
		 * Retrieves and decodes the JSON content of a specific question.
		 *
		 * @param int $question_id The ID of the question.
		 *
		 * @return array|null The decoded question content as an associative array, or null if not found.
		 */
		public static function get_question_content( $question_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT content FROM {$wpdb->prefix}surveyx_questions WHERE id = %d",
					$question_id
				)
			);

			if ( is_null( $result ) ) {
				return null;
			}

			return json_decode( $result, true );
		}

		/**
		 * Retrieves a published survey by its ID with non-empty content.
		 *
		 * @param int $survey_id The ID of the survey to retrieve.
		 *
		 * @return object|false The survey data as an object if found and valid, or false if not found.
		 */
		public static function get_published_survey_by_id( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, title, settings, content, survey_type
                    FROM {$wpdb->prefix}surveyx_surveys
                    WHERE id = %d
                    AND status = 'active'
                    AND content != ''
                    AND (s_mode IS NULL OR s_mode = 'basic')",
					$survey_id
				)
			);

			if ( is_null( $result ) || ! $result ) {
				return false;
			}

			$result->settings = json_decode( $result->settings, true );
			$result->content  = json_decode( $result->content, true );

			return $result;
		}

		// =====================================================================
		// Static /init payload cache
		// get_survey_init_data → get_survey_init_static → build_survey_init_static
		// → get_init_static_cache_key → flush_survey_init_cache. The static part is
		// shared by every visitor and cached across requests; it is invalidated on
		// every survey edit via the surveyx_survey_saved / surveyx_survey_deleted
		// actions (see the main plugin file's listener registration).
		// =====================================================================

		/**
		 * Get complete published survey data in optimized queries.
		 *
		 * Free renders a single-question poll, so only the first question and its
		 * answers are returned. The static part is served from the cross-request /init
		 * cache; per-respondent responses are always fetched live.
		 *
		 * @param int    $survey_id     Survey ID.
		 * @param string $respondent_id Optional respondent UUID for the caller's responses.
		 * @return array|null ['survey','questions','answers','responses'] or null when the
		 *                    survey is not renderable (missing, draft, empty, or non-basic s_mode).
		 */
		public static function get_survey_init_data( $survey_id, $respondent_id = '' ) {
			// Static part (survey + questions + answers), shared by every visitor and
			// served from cache. Kept separate from the per-respondent data below so the
			// cached payload never carries anything session/respondent-specific.
			$data = self::get_survey_init_static( $survey_id );

			if ( null === $data ) {
				return null;
			}

			// Per-respondent responses stay live on every request (never cached).
			$data['responses'] = [];

			if ( ! empty( $respondent_id ) ) {
				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$responses = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, question_id, answer_id, respondent_id, response_content, response_status
						FROM {$wpdb->prefix}surveyx_responses
						WHERE survey_id = %d AND respondent_id = %s",
						$survey_id,
						$respondent_id
					)
				);

				foreach ( $responses as $response ) {
					$response->content = json_decode( $response->response_content );
				}

				$data['responses'] = $responses;
			}

			return $data;
		}

		/** Static /init payload cache lifetime — a safety-net TTL; the payload is busted on every survey edit. */
		const INIT_STATIC_CACHE_TTL = HOUR_IN_SECONDS;

		/**
		 * Returns the visitor-independent part of the /init payload (survey settings,
		 * questions, answers), fully JSON-decoded and cached across requests.
		 *
		 * Identical for every visitor until an admin edits the survey, so a cache hit
		 * skips both the queries and the JSON decoding. Misses are never cached, so a
		 * draft that later publishes can never be pinned to a stale "not found".
		 *
		 * @param int $survey_id Survey ID.
		 * @return array|null Decoded ['survey','questions','answers'] structure, or null when not renderable.
		 */
		private static function get_survey_init_static( $survey_id ) {
			$cache_key = self::get_init_static_cache_key( $survey_id );
			$cached    = get_transient( $cache_key );

			// A valid payload is always an array carrying the 'survey' key; false (miss)
			// or anything unexpected falls through to a rebuild.
			if ( is_array( $cached ) && isset( $cached['survey'] ) ) {
				return $cached;
			}

			$static = self::build_survey_init_static( $survey_id );

			if ( null === $static ) {
				return null;
			}

			set_transient( $cache_key, $static, self::INIT_STATIC_CACHE_TTL );

			return $static;
		}

		/**
		 * Builds the static /init structure with split queries so the survey
		 * settings/content blobs are fetched (and decoded) exactly once instead of
		 * being duplicated across every answer row of the previous fan-out JOIN.
		 *
		 * Free: only the first question (single-question poll) and its answers.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array|null ['survey','questions','answers'] or null if the survey is not renderable.
		 */
		private static function build_survey_init_static( $survey_id ) {
			global $wpdb;

			// 1) Survey row — settings/content blobs fetched once.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$survey_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, title, settings, content, survey_type
					FROM {$wpdb->prefix}surveyx_surveys
					WHERE id = %d AND status = 'active' AND content != ''
						AND (s_mode IS NULL OR s_mode = 'basic')",
					$survey_id
				)
			);

			if ( ! $survey_row ) {
				return null;
			}

			$survey = (object) [
				'id'          => $survey_row->id,
				'title'       => $survey_row->title,
				'settings'    => json_decode( $survey_row->settings, true ),
				'content'     => json_decode( $survey_row->content, true ),
				'survey_type' => $survey_row->survey_type,
			];

			$questions = [];
			$answers   = [];

			// 2) First question only (free renders a single-question poll).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$question_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, content
					FROM {$wpdb->prefix}surveyx_questions
					WHERE survey_id = %d AND content != ''
					ORDER BY sorder ASC, id ASC
					LIMIT 1",
					$survey_id
				)
			);

			if ( $question_row ) {
				$questions[] = (object) [
					'id'      => $question_row->id,
					'content' => json_decode( $question_row->content, true ),
				];

				// 3) Answers for that question, in display order.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$answer_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, question_id, content
						FROM {$wpdb->prefix}surveyx_answers
						WHERE question_id = %d AND content != ''
						ORDER BY sorder ASC, id ASC",
						$question_row->id
					)
				);

				foreach ( $answer_rows as $answer_row ) {
					$answers[] = (object) [
						'id'          => $answer_row->id,
						'question_id' => $answer_row->question_id,
						'content'     => json_decode( $answer_row->content, true ),
					];
				}
			}

			// Swap every image URL for the admin-chosen crop size so the front end
			// downloads smaller files. Done once here, before the payload is cached.
			$image_size = self::get_frontend_image_size();
			if ( 'full' !== $image_size ) {
				self::resize_content_images( $survey->content, $image_size );
				foreach ( $questions as $question ) {
					self::resize_content_images( $question->content, $image_size );
				}
				foreach ( $answers as $answer ) {
					self::resize_content_images( $answer->content, $image_size );
				}
			}

			return [
				'survey'    => $survey,
				'questions' => $questions,
				'answers'   => $answers,
			];
		}

		/**
		 * Builds the transient key for a survey's cached static /init payload.
		 * The plugin version is baked in so a plugin update invalidates every
		 * survey's cached payload at once should the payload shape ever change.
		 *
		 * @param int $survey_id Survey ID.
		 * @return string Transient key.
		 */
		private static function get_init_static_cache_key( $survey_id ) {
			$version = defined( 'SURVEYX_PRO_VERSION' )
				? SURVEYX_PRO_VERSION
				: ( defined( 'SURVEYX_VERSION' ) ? SURVEYX_VERSION : '0' );

			// The chosen image size is baked in so changing it (a global setting)
			// invalidates every survey's cached payload — the URLs inside differ.
			$image_size = self::get_frontend_image_size();

			return 'surveyx_init_static_' . $version . '_' . $image_size . '_' . absint( $survey_id );
		}

		/**
		 * Returns the admin-chosen image size for front-end survey images.
		 * Defaults to 'full' (original image) for back-compat and when unset.
		 *
		 * @return string A registered image size name, or 'full'.
		 */
		public static function get_frontend_image_size() {
			$settings = get_option( 'surveyx_settings', [] );
			$size     = is_array( $settings ) && ! empty( $settings['frontend_image_size'] )
				? $settings['frontend_image_size']
				: 'full';

			return sanitize_key( $size );
		}

		/**
		 * Rewrites every image URL inside a content array to a given registered image
		 * size, in place. Walks the array recursively and, wherever it finds a paired
		 * `image_id` + `image_url`, replaces the URL (and image_w/image_h when present)
		 * with the sized version. If the size no longer exists (e.g. the theme that
		 * registered it was switched), wp_get_attachment_image_src() returns the full
		 * image, so rendering simply falls back to the original.
		 *
		 * @param array  $content Content array, modified by reference.
		 * @param string $size    Registered image size name.
		 * @return void
		 */
		private static function resize_content_images( &$content, $size ) {
			if ( ! is_array( $content ) ) {
				return;
			}

			$image_id = isset( $content['image_id'] ) ? (int) $content['image_id'] : 0;
			if ( $image_id > 0 && ! empty( $content['image_url'] ) ) {
				$src = wp_get_attachment_image_src( $image_id, $size );
				if ( is_array( $src ) && ! empty( $src[0] ) ) {
					$content['image_url'] = $src[0];
					if ( isset( $content['image_w'] ) ) {
						$content['image_w'] = (int) $src[1];
					}
					if ( isset( $content['image_h'] ) ) {
						$content['image_h'] = (int) $src[2];
					}
				}
			}

			foreach ( $content as &$value ) {
				if ( is_array( $value ) ) {
					self::resize_content_images( $value, $size );
				}
			}
			unset( $value );
		}

		/**
		 * Invalidates the cached static /init payload for a survey.
		 *
		 * MUST be called from every write path that changes the survey's
		 * settings/content or its questions/answers so /init never serves stale data.
		 *
		 * @param int $survey_id Survey ID.
		 * @return void
		 */
		public static function flush_survey_init_cache( $survey_id ) {
			$survey_id = absint( $survey_id );

			if ( ! $survey_id ) {
				return;
			}

			delete_transient( self::get_init_static_cache_key( $survey_id ) );
		}

		/**
		 * Retrieves the total number of votes (total_votes) for each answer
		 * grouped by question, for a given survey ID.
		 *
		 * Counts 'answered' status responses with answer_id >= 0 (regular answers + Other).
		 * Excludes special question types (text_input=-2, contact_info=-3, skipped=-4).
		 *
		 * @param int $survey_id The ID of the survey to retrieve vote data for.
		 *
		 * @return array|false An array of vote data grouped by question, or false if no data is found.
		 */
		public static function get_answer_total_vote( $survey_id ) {
			// Short-lived cache: the aggregate is a full GROUP BY scan and /vote-results
			// is hit by every results viewer. Invalidated on each /progress write via
			// flush_vote_cache(). An empty-array sentinel caches the "no data" case so it
			// does not re-run the scan on every miss.
			$cache_key = self::get_vote_cache_key( $survey_id );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return empty( $cached ) ? false : $cached;
			}

			// Respondent-facing count: include the "Other" choice (answer_id = 0).
			$query_result = self::count_votes_by_answer( $survey_id, true );

			if ( ! $query_result ) {
				set_transient( $cache_key, [], self::VOTE_CACHE_TTL );
				return false;
			}

			$grouped_total_votes = [];

			foreach ( $query_result as $row ) {
				$question_id = $row->question_id;
				$answer_id   = (int) $row->answer_id;
				$total_votes = (int) $row->votes;

				if ( ! isset( $grouped_total_votes[ $question_id ] ) ) {
					$grouped_total_votes[ $question_id ] = [];
				}

				$grouped_total_votes[ $question_id ][] = [
					'answer_id' => $answer_id,
					'votes'     => $total_votes,
				];
			}

			$result = array_values( $grouped_total_votes );
			set_transient( $cache_key, $result, self::VOTE_CACHE_TTL );

			return $result;
		}

		/**
		 * Aggregates 'answered' responses into per-answer vote counts for a survey.
		 *
		 * Single source of truth for the "votes per answer" GROUP BY used by both the
		 * respondent-facing /vote-results (get_answer_total_vote) and the admin summary
		 * (get_answer_votes). The only difference between the two callers is whether the
		 * "Other" choice (answer_id = 0) is counted, so it is a parameter here.
		 *
		 * @param int  $survey_id     Survey ID.
		 * @param bool $include_other Whether to include the "Other" choice (answer_id = 0).
		 * @return array List of rows with `question_id`, `answer_id`, `votes`.
		 */
		private static function count_votes_by_answer( $survey_id, $include_other ) {
			global $wpdb;

			// include_other=true keeps answer_id 0 (Other); false counts real answers only.
			$min_answer_id = $include_other ? 0 : 1;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.question_id, r.answer_id, COUNT(*) AS votes
					FROM {$wpdb->prefix}surveyx_responses AS r
					WHERE r.survey_id = %d AND r.answer_id >= %d AND r.response_status = 'answered'
					GROUP BY r.question_id, r.answer_id
					ORDER BY r.question_id, r.answer_id",
					$survey_id,
					$min_answer_id
				)
			);
		}

		/** Vote-results cache lifetime in seconds. */
		const VOTE_CACHE_TTL = 60;

		/**
		 * Builds the transient key for a survey's cached vote aggregate.
		 *
		 * @param int $survey_id Survey ID.
		 * @return string Transient key.
		 */
		private static function get_vote_cache_key( $survey_id ) {
			return 'surveyx_votes_' . absint( $survey_id );
		}

		/**
		 * Invalidates the cached /vote-results aggregate for a survey.
		 * Called from the /progress write path whenever responses change.
		 *
		 * @param int $survey_id Survey ID.
		 * @return void
		 */
		public static function flush_vote_cache( $survey_id ) {
			delete_transient( self::get_vote_cache_key( $survey_id ) );
		}

		/**
		 * Gets plugin settings.
		 *
		 * @return array Settings array with default structure if not found.
		 */
		public static function get_settings() {
			// NOTE: no `captcha_provider` default here on purpose. Its ABSENCE is the
			// signal for old installs so get_active_captcha() falls back to the legacy
			// `*_enabled` flags. A hard 'none' default would mask that and silently drop
			// captcha enforcement until the user re-saves. The admin UI backfills the
			// field for display and persists it on save.
			$default_settings = [
				'recaptcha_v2_enabled'    => false,
				'recaptcha_v2_site_key'   => '',
				'recaptcha_v2_secret_key' => '',
				'show_alphabet_labels'    => true,
				'frontend_image_size'     => 'full',
				'skip_question_cover_import' => true,
			];

			$settings = get_option( 'surveyx_settings', $default_settings );

			if ( ! is_array( $settings ) ) {
				return $default_settings;
			}

			return array_merge( $default_settings, $settings );
		}

		/**
		 * Updates plugin settings.
		 *
		 * @param array $data Settings data array.
		 *
		 * @return array|false Settings array on success, false on failure.
		 */
		public static function update_settings( $data ) {
			$result = update_option( 'surveyx_settings', $data );

			return $result ? $data : false;
		}

		/**
		 * Increments view count when survey is loaded.
		 * Updates the surveyx_summary table with total views.
		 *
		 * @param int $survey_id The ID of the survey.
		 *
		 * @return void
		 */
		public static function increment_view_count( $survey_id ) {
			global $wpdb;

			$utc_now = surveyx_get_utc_now();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}surveyx_summary
                    (survey_id, total_views, last_updated)
                    VALUES (%d, 1, %s)
                    ON DUPLICATE KEY UPDATE
                        total_views = total_views + 1,
                        last_updated = %s",
					$survey_id,
					$utc_now,
					$utc_now
				)
			);
		}

		// =====================================================================
		// SUMMARY / CRON DATABASE METHODS
		// =====================================================================

		/**
		 * Gets session statistics for a survey.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array Stats array with views, starts, completions, dropoffs, avg_time.
		 */
		public static function get_session_stats( $survey_id ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
                    s.total_views as views,
                    SUM(ss.session_status != 'viewed' AND ss.session_status != 'expired') as total,
                    SUM(ss.session_status = 'completed') as completions,
                    SUM(ss.session_status = 'dropped_off') as dropoffs,
                    AVG(CASE WHEN ss.session_status = 'completed' THEN ss.time_spent END) as avg_time
                FROM {$wpdb->prefix}surveyx_summary s
                LEFT JOIN {$wpdb->prefix}surveyx_sessions ss ON s.survey_id = ss.survey_id
                WHERE s.survey_id = %d
                GROUP BY s.survey_id",
					$survey_id
				)
			);

			return [
				'views'       => (int) ( $row->views ?? 0 ),
				'starts'      => (int) ( $row->total ?? 0 ),
				'completions' => (int) ( $row->completions ?? 0 ),
				'dropoffs'    => (int) ( $row->dropoffs ?? 0 ),
				'avg_time'    => (int) ( $row->avg_time ?? 0 ),
			];
		}

		/**
		 * Gets most common drop-off question ID.
		 *
		 * @param int $survey_id Survey ID.
		 * @return int|null Question ID or null.
		 */
		public static function get_most_common_dropoff( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT current_question_id
                FROM {$wpdb->prefix}surveyx_sessions
                WHERE survey_id = %d AND session_status = 'dropped_off' AND current_question_id > 0
                GROUP BY current_question_id
                ORDER BY COUNT(*) DESC
                LIMIT 1",
					$survey_id
				)
			);
		}

		/**
		 * Gets seen count per question from responses table.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array Associative array {question_id => count}.
		 */
		public static function get_question_seen_counts( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT question_id, COUNT(DISTINCT session_id) as count
                FROM {$wpdb->prefix}surveyx_responses
                WHERE survey_id = %d AND viewed_at IS NOT NULL
                GROUP BY question_id",
					$survey_id
				)
			);

			$counts = [];
			foreach ( $results as $row ) {
				$counts[ (string) $row->question_id ] = (int) $row->count;
			}

			return $counts;
		}

		/**
		 * Gets vote count per answer from responses.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array Associative array {answer_id => count}.
		 */
		public static function get_answer_votes( $survey_id ) {
			// Admin summary count: real answers only, excluding the "Other" choice
			// (answer_id = 0), which get_answers_for_summary() adds separately.
			$results = self::count_votes_by_answer( $survey_id, false );

			$votes = [];
			foreach ( $results as $row ) {
				$votes[ (string) $row->answer_id ] = (int) $row->votes;
			}

			return $votes;
		}

		/**
		 * Gets response count per question from responses.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array Associative array {question_id => count}.
		 */
		public static function get_response_count_by_question( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT question_id, COUNT(*) as response_count
                FROM {$wpdb->prefix}surveyx_responses
                WHERE survey_id = %d AND response_status = 'answered'
                GROUP BY question_id",
					$survey_id
				)
			);

			$counts = [];
			foreach ( $results as $row ) {
				$counts[ (string) $row->question_id ] = (int) $row->response_count;
			}

			return $counts;
		}

		/**
		 * Upserts summary data into database.
		 *
		 * @param int   $survey_id Survey ID.
		 * @param array $data      Summary data.
		 */
		public static function upsert_summary( $survey_id, $data ) {
			global $wpdb;

			$utc_now = surveyx_get_utc_now();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}surveyx_summary
                (survey_id, total_views, total_starts, total_completions, total_dropoffs,
                 completion_rate, dropoff_rate, average_time_seconds, most_common_dropoff_question_id,
                 question_seen_counts, answer_votes_json, response_count_by_question, last_updated)
                VALUES (%d, %d, %d, %d, %d, %f, %f, %d, %d, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    total_starts = VALUES(total_starts),
                    total_completions = VALUES(total_completions),
                    total_dropoffs = VALUES(total_dropoffs),
                    completion_rate = VALUES(completion_rate),
                    dropoff_rate = VALUES(dropoff_rate),
                    average_time_seconds = VALUES(average_time_seconds),
                    most_common_dropoff_question_id = VALUES(most_common_dropoff_question_id),
                    question_seen_counts = VALUES(question_seen_counts),
                    answer_votes_json = VALUES(answer_votes_json),
                    response_count_by_question = VALUES(response_count_by_question),
                    last_updated = %s",
					$survey_id,
					$data['total_views'],
					$data['total_starts'],
					$data['total_completions'],
					$data['total_dropoffs'],
					$data['completion_rate'],
					$data['dropoff_rate'],
					$data['average_time_seconds'],
					$data['most_common_dropoff_question_id'],
					$data['question_seen_counts'] ?? '{}',
					$data['answer_votes_json'] ?? '{}',
					$data['response_count_by_question'] ?? '{}',
					$utc_now,
					$utc_now
				)
			);
		}
	}
}
