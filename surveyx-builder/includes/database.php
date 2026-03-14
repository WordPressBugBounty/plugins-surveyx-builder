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
		 * Gets the survey mode (basic or pro).
		 *
		 * @param int $survey_id Survey ID.
		 * @return string|null Survey mode ('basic' or 'pro') or null if not found.
		 */
		public static function get_survey_mode( $survey_id ) {
			global $wpdb;

			if ( empty( $survey_id ) ) {
				return null;
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT s_mode FROM {$wpdb->prefix}surveyx_surveys WHERE id = %d LIMIT 1",
					$survey_id
				)
			);
		}

		/**
		 * Checks if responses exist for a specific question in a session.
		 * Used to determine if we need to delete old responses before creating new ones.
		 *
		 * @param int    $session_id    The ID of the session.
		 * @param int    $question_id   The ID of the question.
		 * @param string $respondent_id The respondent UUID.
		 *
		 * @return bool True if responses exist, false otherwise.
		 */
		public static function has_responses_for_question( $session_id, $question_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}surveyx_responses
                    WHERE session_id = %d AND question_id = %d AND respondent_id = %s",
					$session_id,
					$question_id,
					$respondent_id
				)
			);

			return $count > 0;
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

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->update(
				$wpdb->prefix . 'surveyx_sessions',
				[
					'session_status'      => 'expired',
					'completed_at'        => null,
					'progress_percentage' => 0,
					'current_question_id' => 0,
				],
				[ 'survey_id' => $survey_id ],
				[ '%s', '%s', '%f', '%d' ],
				[ '%d' ]
			);
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
				$special_answer_id = self::get_special_answer_id( $question_type, $response_status );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->insert(
					$wpdb->prefix . 'surveyx_responses',
					[
						'session_id'       => $session_id,
						'survey_id'        => $survey_id,
						'question_id'      => $question_id,
						'answer_id'        => $special_answer_id,
						'respondent_id'    => $respondent_id,
						'response_content' => $response_content,
						'response_status'  => $response_status,
						'viewed_at'        => $viewed_at,
						'answered_at'      => $now,
					],
					[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
				);

				return false !== $result;
			}

			// For multiple choice questions with answer_ids (always 'answered' status).
			foreach ( $answer_ids as $answer_id ) {
				$response_content = '';
				if ( 0 === (int) $answer_id && ! empty( $content['other_text'] ) ) {
					$response_content = wp_json_encode( [ 'other_text' => $content['other_text'] ] );
				}

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->insert(
					$wpdb->prefix . 'surveyx_responses',
					[
						'session_id'       => $session_id,
						'survey_id'        => $survey_id,
						'question_id'      => $question_id,
						'answer_id'        => $answer_id,
						'respondent_id'    => $respondent_id,
						'response_content' => $response_content,
						'response_status'  => 'answered',
						'viewed_at'        => $viewed_at,
						'answered_at'      => $now,
					],
					[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
				);

				if ( false === $result ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Gets special answer_id based on question type and response status.
		 *
		 * @param string $question_type   Question type.
		 * @param string $response_status Response status.
		 * @return int Special answer_id value.
		 */
		private static function get_special_answer_id( $question_type, $response_status ) {
			if ( 'seen' === $response_status ) {
				return -8;
			}

			if ( 'skipped_optional' === $response_status ) {
				return -4;
			}

			switch ( $question_type ) {
				case 'text_input':
					return -2;
				case 'contact_info':
					return -3;
				case 'opinion_scale':
					return -5;
				case 'rating':
					return -6;
				case 'date':
					return -7;
				default:
					return -4;
			}
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

			$answer_id = ( 'seen' === $status ) ? -8 : -4;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert(
				$wpdb->prefix . 'surveyx_responses',
				[
					'session_id'       => $session_id,
					'survey_id'        => $survey_id,
					'question_id'      => $question_id,
					'answer_id'        => $answer_id,
					'respondent_id'    => $respondent_id,
					'response_content' => '',
					'response_status'  => $status,
					'viewed_at'        => $viewed_at,
					'answered_at'      => $viewed_at,
				],
				[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
			);

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

			$start = strtotime( $result->first_viewed );
			$end   = strtotime( $result->last_answered );

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

		/**
		 * Get published survey data (questions, answers, responses) by survey ID.
		 * OPTIMIZED: Only loads responses for specific respondent instead of all responses.
		 *
		 * @param int    $survey_id     Survey ID to fetch data for.
		 * @param string $respondent_id Optional. Respondent UUID to filter responses (default: empty = no responses).
		 *
		 * @return array {
		 *     @type array $questions List of question objects with decoded content.
		 *     @type array $answers List of answer objects with decoded content.
		 *     @type array $responses List of response objects with decoded content (only for respondent).
		 * }
		 */
		public static function get_published_survey_data_by_id( $survey_id, $respondent_id = '' ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$question = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, content
                    FROM {$wpdb->prefix}surveyx_questions
                    WHERE survey_id = %d AND content != '' ORDER BY sorder ASC",
					$survey_id
				)
			);

			$questions = [];
			if ( ! empty( $question ) ) {
				$question->content = json_decode( $question->content, true );
				$questions         = [ $question ];
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$answers = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, question_id, content
                    FROM {$wpdb->prefix}surveyx_answers
                    WHERE survey_id = %d AND content != '' ORDER BY sorder ASC",
					$survey_id
				)
			);

			if ( ! empty( $answers ) ) {
				foreach ( $answers as $answer ) {
					$answer->content = json_decode( $answer->content, true );
				}
			}

			if ( ! empty( $questions ) && ! empty( $answers ) ) {
				$first_question_id = $questions[0]->id;
				$answers           = array_filter(
					$answers,
					function ( $answer ) use ( $first_question_id ) {
						return intval( $answer->question_id ) === intval( $first_question_id );
					}
				);
				$answers           = array_values( $answers );
			}

			$responses = [];
			if ( ! empty( $respondent_id ) ) {
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

				if ( ! empty( $responses ) ) {
					foreach ( $responses as $response ) {
						$response->content = json_decode( $response->response_content );
					}
				}
			}

			return [
				'questions' => $questions,
				'answers'   => $answers,
				'responses' => $responses,
			];
		}

		/**
		 * Get complete published survey data in optimized queries.
		 * Fsingle question poll
		 *
		 * @param int    $survey_id     Survey ID.
		 * @param string $respondent_id Optional respondent UUID for responses.
		 * @return array|null Survey data array or null if not found.
		 */
		public static function get_survey_init_data( $survey_id, $respondent_id = '' ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						s.id AS s_id, s.title AS s_title, s.settings AS s_settings,
						s.content AS s_content, s.survey_type AS s_type,
						q.id AS q_id, q.content AS q_content,
						a.id AS a_id, a.question_id AS a_qid, a.content AS a_content
					FROM {$wpdb->prefix}surveyx_surveys s
					LEFT JOIN (
						SELECT id, survey_id, content, sorder
						FROM {$wpdb->prefix}surveyx_questions
						WHERE survey_id = %d AND content != ''
						ORDER BY sorder ASC
						LIMIT 1
					) q ON q.survey_id = s.id
					LEFT JOIN {$wpdb->prefix}surveyx_answers a
						ON a.question_id = q.id AND a.content != ''
					WHERE s.id = %d AND s.status = 'active' AND s.content != ''
						AND (s.s_mode IS NULL OR s.s_mode = 'basic')
					ORDER BY a.sorder ASC",
					$survey_id,
					$survey_id
				)
			);

			if ( empty( $rows ) ) {
				return null;
			}

			$first  = $rows[0];
			$survey = (object) [
				'id'          => $first->s_id,
				'title'       => $first->s_title,
				'settings'    => json_decode( $first->s_settings, true ),
				'content'     => json_decode( $first->s_content, true ),
				'survey_type' => $first->s_type,
			];

			$questions = [];
			$answers   = [];

			if ( $first->q_id ) {
				$questions[] = (object) [
					'id'      => $first->q_id,
					'content' => json_decode( $first->q_content, true ),
				];

				foreach ( $rows as $row ) {
					if ( $row->a_id ) {
						$answers[] = (object) [
							'id'          => $row->a_id,
							'question_id' => $row->a_qid,
							'content'     => json_decode( $row->a_content, true ),
						];
					}
				}
			}

			$responses = [];
			if ( ! empty( $respondent_id ) ) {
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
			}

			return [
				'survey'    => $survey,
				'questions' => $questions,
				'answers'   => $answers,
				'responses' => $responses,
			];
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
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$query_result = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
                        r.question_id,
                        r.answer_id,
                        COUNT(*) AS total_votes
                    FROM {$wpdb->prefix}surveyx_responses AS r
                    WHERE r.survey_id = %d AND r.answer_id >= 0 AND r.response_status = 'answered'
                    GROUP BY r.question_id, r.answer_id
                    ORDER BY r.question_id, r.answer_id",
					$survey_id
				)
			);

			if ( ! $query_result ) {
				return false;
			}

			$grouped_total_votes = [];

			foreach ( $query_result as $row ) {
				$question_id = $row->question_id;
				$answer_id   = (int) $row->answer_id;
				$total_votes = (int) $row->total_votes;

				if ( ! isset( $grouped_total_votes[ $question_id ] ) ) {
					$grouped_total_votes[ $question_id ] = [];
				}

				$grouped_total_votes[ $question_id ][] = [
					'answer_id' => $answer_id,
					'votes'     => $total_votes,
				];
			}

			return array_values( $grouped_total_votes );
		}

		/**
		 * Gets plugin settings.
		 *
		 * @return array Settings array with default structure if not found.
		 */
		public static function get_settings() {
			$default_settings = [
				'recaptcha_v2_enabled'    => false,
				'recaptcha_v2_site_key'   => '',
				'recaptcha_v2_secret_key' => '',
				'show_alphabet_labels'    => true,
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
		 * Retrieves all answers associated with a specific survey ID.
		 *
		 * @param int $survey_id The ID of the survey to retrieve answers for.
		 *
		 * @return array|null Array of result objects containing answer data, or null on failure.
		 */
		public static function get_answers_by_survey_id( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT answers.*
                    FROM {$wpdb->prefix}surveyx_answers AS answers
                    LEFT JOIN {$wpdb->prefix}surveyx_questions AS questions ON answers.question_id = questions.id
                    WHERE questions.survey_id = %d",
					$survey_id
				)
			);
		}

		/**
		 * Gets survey settings by survey ID.
		 *
		 * @param int $survey_id The ID of the survey.
		 *
		 * @return string|null Survey settings JSON string or null.
		 */
		public static function get_survey_settings_by_id( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT settings FROM {$wpdb->prefix}surveyx_surveys WHERE id = %d",
					$survey_id
				)
			);
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
		 * Gets all survey IDs.
		 *
		 * @return array Array of survey IDs.
		 */
		public static function get_all_survey_ids() {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Static query
			return $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}surveyx_surveys" );
		}

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
                    SUM(ss.session_status != 'viewed') as total,
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
		 * Gets existing views count from summary table.
		 *
		 * @param int $survey_id Survey ID.
		 * @return int Views count.
		 */
		public static function get_existing_views( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT total_views FROM {$wpdb->prefix}surveyx_summary WHERE survey_id = %d",
					$survey_id
				)
			);
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
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT answer_id, COUNT(*) as vote_count
                FROM {$wpdb->prefix}surveyx_responses
                WHERE survey_id = %d AND answer_id > 0 AND response_status = 'answered'
                GROUP BY answer_id",
					$survey_id
				)
			);

			$votes = [];
			foreach ( $results as $row ) {
				$votes[ (string) $row->answer_id ] = (int) $row->vote_count;
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
