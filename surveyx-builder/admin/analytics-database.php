<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Analytics_Db', false ) ) {
	/**
	 * Analytics Database Class
	 *
	 * @since 1.0.0
	 */
	class SurveyX_Analytics_Db {

		/**
		 * Get survey overview data for Insights + Summary tabs.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array|false Overview data or false on failure.
		 */
		public static function get_survey_overview( int $survey_id ) {
			if ( empty( $survey_id ) ) {
				return false;
			}

			$summary         = self::get_summary_full( $survey_id );
			$questions       = self::get_questions( $survey_id );
			$answers         = self::get_answers_for_summary( $survey_id, $summary );
			$scale_responses = self::get_scale_responses( $survey_id );

			// Parse JSON fields from summary
			$seen_count_by_question = [];
			if ( ! empty( $summary->question_seen_counts ) ) {
				$seen_count_by_question = json_decode( $summary->question_seen_counts, true ) ?: [];
			}

			// Ensure all questions have a count
			foreach ( $questions as $q ) {
				$qid = (string) $q->id;
				if ( ! isset( $seen_count_by_question[ $qid ] ) ) {
					$seen_count_by_question[ $qid ] = 0;
				}
			}

			$dropoff_by_question = self::get_dropoff_by_question( $survey_id );

			// Parse response count by question from summary
			$response_count_by_question = [];
			if ( ! empty( $summary->response_count_by_question ) ) {
				$response_count_by_question = json_decode( $summary->response_count_by_question, true ) ?: [];
			}

			// Send last_updated as UTC timestamp for JS to calculate cache expiry
			// JS handles timezone conversion and display consistently

			// Get text response counts (counts only, no content - for fast initial load)
			$text_response_counts = self::get_text_response_counts( $survey_id );

			$data = [
				'questions'                  => $questions,
				'answers'                    => $answers,
				'summary'                    => $summary,
				'seen_count_by_question'     => $seen_count_by_question,
				'dropoff_by_question'        => $dropoff_by_question,
				'response_count_by_question' => $response_count_by_question,
				'scale_responses'            => $scale_responses,
				'text_response_counts'       => $text_response_counts,
			];

			/**
			 * Filter survey overview data. Allows Pro to extend with additional data.
			 *
			 * @param array $data      Overview data.
			 * @param int   $survey_id Survey ID.
			 */
			return apply_filters( 'surveyx_survey_overview_data', $data, $survey_id );
		}

		/**
		 * Refresh survey summary and return updated overview.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array|false Updated overview data or false on failure.
		 */
		public static function refresh_survey_summary( int $survey_id ) {
			if ( empty( $survey_id ) ) {
				return false;
			}

			// Call cron function to recalculate
			if ( function_exists( 'surveyx_update_summary' ) ) {
				surveyx_update_summary( $survey_id );
			}

			return self::get_survey_overview( $survey_id );
		}

		/**
		 * Get questions for a survey
		 *
		 * @param int $survey_id Survey ID.
		 * @return array Questions array with minimal content.
		 */
		protected static function get_questions( int $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$questions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, content FROM {$wpdb->prefix}surveyx_questions
					WHERE survey_id = %d",
					$survey_id
				)
			);

			if ( empty( $questions ) ) {
				return [];
			}

			// Only return needed fields for analytics
			foreach ( $questions as $question ) {
				$full_content      = json_decode( $question->content, true );
				$question->content = [
					'title'             => $full_content['title'] ?? '',
					'type'              => $full_content['type'] ?? 'check_box',
					'image_url'         => $full_content['image_url'] ?? '',
					'show_other_option' => ! empty( $full_content['show_other_option'] ),
				];
			}

			return $questions;
		}

		/**
		 * Get full summary with JSON fields for a survey.
		 *
		 * @param int $survey_id Survey ID.
		 * @return object Summary object with all fields.
		 */
		private static function get_summary_full( int $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$summary = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT total_views, total_starts, total_completions, total_dropoffs,
						completion_rate, dropoff_rate, average_time_seconds,
						most_common_dropoff_question_id, question_seen_counts, answer_votes_json,
						response_count_by_question, last_updated
					FROM {$wpdb->prefix}surveyx_summary
					WHERE survey_id = %d",
					$survey_id
				)
			);

			if ( empty( $summary ) ) {
				return (object) [
					'total_views'                     => 0,
					'total_starts'                    => 0,
					'total_completions'               => 0,
					'total_dropoffs'                  => 0,
					'completion_rate'                 => 0,
					'dropoff_rate'                    => 0,
					'average_time_seconds'            => 0,
					'most_common_dropoff_question_id' => null,
					'question_seen_counts'            => '{}',
					'answer_votes_json'               => '{}',
					'response_count_by_question'      => '{}',
					'last_updated'                    => null,
				];
			}

			return $summary;
		}

		/**
		 * Get answers for Summary tab using votes from summary JSON.
		 * Also includes virtual "Other" answer counts for questions with show_other_option.
		 *
		 * @param int    $survey_id Survey ID.
		 * @param object $summary   Summary object with answer_votes_json.
		 * @return array Answers array with vote counts from summary.
		 */
		private static function get_answers_for_summary( int $survey_id, $summary ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$answers = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.id, a.question_id, a.content
					FROM {$wpdb->prefix}surveyx_answers a
					WHERE a.survey_id = %d",
					$survey_id
				)
			);

			if ( empty( $answers ) ) {
				$answers = [];
			}

			// Parse votes from summary JSON
			$votes_map = [];
			if ( ! empty( $summary->answer_votes_json ) ) {
				$votes_map = json_decode( $summary->answer_votes_json, true ) ?: [];
			}

			foreach ( $answers as $answer ) {
				$full_content        = json_decode( $answer->content, true );
				$answer->content     = [
					'title'     => $full_content['title'] ?? '',
					'image_url' => $full_content['image_url'] ?? '',
				];
				$answer->total_votes = (int) ( $votes_map[ (string) $answer->id ] ?? 0 );
			}

			// Get "Other" votes count (answer_id = 0) grouped by question
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$other_votes = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT question_id, COUNT(*) as vote_count
					FROM {$wpdb->prefix}surveyx_responses
					WHERE survey_id = %d AND answer_id = 0 AND response_status = 'answered'
					GROUP BY question_id",
					$survey_id
				)
			);

			// Add virtual "Other" answer for each question with Other votes
			foreach ( $other_votes as $row ) {
				$answers[] = (object) [
					'id'          => 0,
					'question_id' => $row->question_id,
					'content'     => [
						'title'           => esc_html__( 'Other', 'surveyx-builder' ),
						'image_url'       => '',
						'is_other_option' => true,
					],
					'total_votes' => (int) $row->vote_count,
				];
			}

			return $answers;
		}

		/**
		 * Get drop-off count per question.
		 * Base implementation returns empty array. Extended via filter.
		 *
		 * @param int $_survey_id Survey ID (used by extended implementation).
		 * @return array Drop-off count by question ID.
		 */
		protected static function get_dropoff_by_question( int $_survey_id ) {
			return [];
		}

		/**
		 * Get scale/rating responses.
		 * Base implementation returns empty array. Extended via filter.
		 *
		 * @param int $_survey_id Survey ID (used by extended implementation).
		 * @return array Scale responses grouped by question_id with stats.
		 */
		protected static function get_scale_responses( int $_survey_id ) {
			return [];
		}

		/**
		 * Get text response counts per question for overview (counts only, no content).
		 * Base types: text_input (-2) and other (0). Extendable via filter.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array { question_id => { type => count } }
		 */
		public static function get_text_response_counts( int $survey_id ) {
			global $wpdb;

			if ( empty( $survey_id ) ) {
				return [];
			}

			// Base type map (extendable via filter)
			$type_map = [
				-2 => 'text_input',
				0  => 'other',
			];
			$type_map = apply_filters( 'surveyx_text_response_type_map', $type_map );

			$answer_ids   = array_keys( $type_map );
			$placeholders = implode( ',', array_fill( 0, count( $answer_ids ), '%d' ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT question_id, answer_id, COUNT(*) as count
                     FROM {$wpdb->prefix}surveyx_responses
                     WHERE survey_id = %d
                       AND answer_id IN ({$placeholders})
                       AND response_status = 'answered'
                     GROUP BY question_id, answer_id",
					array_merge( [ $survey_id ], $answer_ids )
				)
			);

			// Transform to { question_id => { type => count } }
			$counts = [];
			foreach ( $results as $row ) {
				$qid  = (string) $row->question_id;
				$type = $type_map[ (int) $row->answer_id ] ?? null;

				// Skip unknown types (only return mapped types)
				if ( null === $type ) {
					continue;
				}

				if ( ! isset( $counts[ $qid ] ) ) {
					$counts[ $qid ] = [];
				}
				$counts[ $qid ][ $type ] = (int) $row->count;
			}

			return $counts;
		}

		/**
		 * Get text-based responses with pagination for drawer.
		 * Base types: text_input (-2), other (0). Extendable via filter.
		 *
		 * @param int    $survey_id   Survey ID.
		 * @param int    $question_id Question ID.
		 * @param string $answer_type Type of response (text_input, other, etc.).
		 * @param int    $offset      Pagination offset.
		 * @param int    $limit       Max responses to return.
		 * @return array Array of response objects.
		 */
		public static function get_text_responses(
			int $survey_id,
			int $question_id,
			string $answer_type = 'text_input',
			int $offset = 0,
			int $limit = 50
		) {
			global $wpdb;

			if ( empty( $survey_id ) || empty( $question_id ) ) {
				return [];
			}

			// Base type map: answer_id => type_name (extendable via filter)
			$type_map = [
				-2 => 'text_input',
				0  => 'other',
			];
			$type_map = apply_filters( 'surveyx_text_response_type_map', $type_map );

			// Find answer_id for the requested type
			$answer_id = array_search( $answer_type, $type_map, true );

			if ( false === $answer_id ) {
				return [];
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, question_id, response_content, answered_at
                     FROM {$wpdb->prefix}surveyx_responses
                     WHERE survey_id = %d AND question_id = %d
                       AND answer_id = %d AND response_status = 'answered'
                     ORDER BY answered_at DESC
                     LIMIT %d OFFSET %d",
					$survey_id,
					$question_id,
					$answer_id,
					$limit,
					$offset
				)
			);
		}

		/**
		 * Get total count of text responses for pagination.
		 *
		 * @param int    $survey_id   Survey ID.
		 * @param int    $question_id Question ID.
		 * @param string $answer_type Type of response.
		 * @return int Total count.
		 */
		public static function get_text_responses_count(
			int $survey_id,
			int $question_id,
			string $answer_type = 'text_input'
		) {
			global $wpdb;

			if ( empty( $survey_id ) || empty( $question_id ) ) {
				return 0;
			}

			// Base type map: answer_id => type_name (extendable via filter)
			$type_map = [
				-2 => 'text_input',
				0  => 'other',
			];
			$type_map = apply_filters( 'surveyx_text_response_type_map', $type_map );

			// Find answer_id for the requested type
			$answer_id = array_search( $answer_type, $type_map, true );

			if ( false === $answer_id ) {
				return 0;
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
                     FROM {$wpdb->prefix}surveyx_responses
                     WHERE survey_id = %d AND question_id = %d
                       AND answer_id = %d AND response_status = 'answered'",
					$survey_id,
					$question_id,
					$answer_id
				)
			);
		}
	}
}
