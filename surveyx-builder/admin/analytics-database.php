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
		 * How long a measured analytics snapshot is served before it is measured again.
		 *
		 * Short on purpose: every miss re-measures from surveyx_sessions and surveyx_responses,
		 * so the window only absorbs an admin's hand-made burst (switching tabs, back, reload).
		 * Staleness remains - the Responses tab pages live from the database, so a cached
		 * "Answered: 120" can still sit beside a list of 123.
		 */
		const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

		/**
		 * Transient key holding the analytics snapshot for one survey.
		 *
		 * Deleted by name, never by prefix sweep: SurveyX_Db::flush_analytics_cache() is the ONE
		 * invalidation path and it deletes this exact key, so keep it in step with any rename.
		 * Its callers are the paths that change what the snapshot counted - the stale-session
		 * drop-off sweep, the per-survey session reset and the admin response deletes, plus, in
		 * Pro only, session completion and the off-path response prune.
		 *
		 * No save path flushes it, in either edition: `surveyx_survey_saved` has exactly one
		 * listener and it flushes the static /init cache, not this snapshot, so editing a survey
		 * leaves the snapshot standing until CACHE_TTL expires.
		 *
		 * @param int $survey_id Survey ID.
		 * @return string Transient key.
		 */
		public static function snapshot_key( int $survey_id ) {
			// Edition-segmented: Free and Pro measure different maps into this payload
			// (matrix_counts, dropoff_by_question, scale_responses), snapshot_is_fresh()
			// only checks the stamp, and nothing flushes on an edition switch — so a shared
			// key serves the other edition's shape and renders grids of zeros.
			return 'surveyx_summary_snap_' . SurveyX_Db::get_cache_edition_segment() . '_' . $survey_id;
		}

		/**
		 * Get survey overview data for Insights + Summary tabs.
		 *
		 * Every number in the payload comes from ONE snapshot, and figures read against each
		 * other come from one statement (get_participation_counts() measures seen and answered
		 * together), so a rate can never mix a cached numerator with a live denominator.
		 * Question and answer text is still read per request, so the snapshot never freezes a
		 * title.
		 *
		 * @param int  $survey_id Survey ID.
		 * @param bool $force     Re-measure and rebuild the snapshot, ignoring the cache.
		 * @return array|false Overview data or false on failure.
		 */
		public static function get_survey_overview( int $survey_id, bool $force = false ) {
			if ( empty( $survey_id ) ) {
				return false;
			}

			return self::hydrate_overview( $survey_id, self::get_snapshot( $survey_id, $force ) );
		}

		/**
		 * Get the current analytics snapshot for a survey, building one if needed.
		 *
		 * The single entry point every analytics screen reads its numbers through, so two tabs
		 * opened seconds apart cannot disagree.
		 *
		 * The cached value's age is re-checked against its own stamp rather than trusted to the
		 * transient: get_transient() reports a hit whenever the value row outlives its
		 * _transient_timeout_ row, which several persistent object caches produce under eviction,
		 * and a snapshot served past that point would never expire. Same guard as
		 * SurveyX_Db::get_answer_total_vote().
		 *
		 * @param int  $survey_id Survey ID.
		 * @param bool $force     Re-measure and rebuild, ignoring the cache.
		 * @return array Snapshot of aggregates.
		 */
		public static function get_snapshot( int $survey_id, bool $force = false ) {
			if ( ! $force ) {
				$snapshot = get_transient( self::snapshot_key( $survey_id ) );

				// is_array() is also the pre-2.0 upgrade guard: this key used to hold an integer
				// recount timestamp. Anything that is not a snapshot is simply re-measured.
				if ( is_array( $snapshot ) && self::snapshot_is_fresh( $snapshot ) ) {
					return $snapshot;
				}
			}

			$snapshot = self::build_snapshot( $survey_id );
			set_transient( self::snapshot_key( $survey_id ), $snapshot, self::CACHE_TTL );

			return $snapshot;
		}

		/**
		 * Whether a cached snapshot is still inside CACHE_TTL by its own stamp.
		 *
		 * measure_summary() stamps summary.last_updated in the same pass that measures the
		 * figures, so the age is already in the payload and needs no extra storage. A snapshot
		 * carrying no stamp (an earlier 2.0 build) or one stamped in the future (clock skew, a
		 * restored database) is re-measured once.
		 *
		 * @param array $snapshot Snapshot read back from the transient.
		 * @return bool True when the snapshot was measured within the last CACHE_TTL.
		 */
		private static function snapshot_is_fresh( array $snapshot ) {
			$stamp = $snapshot['summary']['last_updated'] ?? '';

			if ( ! is_string( $stamp ) || '' === $stamp ) {
				return false;
			}

			// surveyx_get_utc_now() writes 'Y-m-d H:i:s' in UTC; naming the zone here
			// makes the read independent of the process default timezone.
			$measured_at = strtotime( $stamp . ' UTC' );

			if ( ! $measured_at ) {
				return false;
			}

			$age = time() - $measured_at;

			return $age >= 0 && $age < self::CACHE_TTL;
		}

		/**
		 * Measure every analytics aggregate for one survey in a single pass.
		 *
		 * Measured from surveyx_sessions and surveyx_responses, not read out of surveyx_summary:
		 * that table is a cache of these same aggregates, so reading it showed whatever the last
		 * recount happened to store. Stores pure numbers - no titles, no translated strings - so
		 * nothing structural can go stale here.
		 *
		 * "One pass" is per aggregate, not across them: these queries run in sequence and a write
		 * can land between two of them, so any two figures a screen divides or compares must be
		 * measured by ONE query - see get_participation_counts().
		 *
		 * @param int $survey_id Survey ID.
		 * @return array Snapshot of aggregates.
		 */
		protected static function build_snapshot( int $survey_id ) {
			$text_response_counts = self::get_text_response_counts( $survey_id );

			// The virtual "Other" answer is the answer_id = 0 bucket that
			// get_text_response_counts() has already counted - same predicate, same
			// GROUP BY. Reading it from there retires a second identical query.
			$other_votes_by_question = [];
			foreach ( $text_response_counts as $qid => $by_type ) {
				if ( ! empty( $by_type['other'] ) ) {
					$other_votes_by_question[ (string) $qid ] = (int) $by_type['other'];
				}
			}

			// seen and answered are the two halves of one ratio, so they come from ONE statement:
			// assembling them from two reads once rendered a response rate above 100%.
			$participation = self::get_participation_counts( $survey_id );

			$answer_votes   = SurveyX_Db::get_answer_votes( $survey_id );
			$response_count = SurveyX_Db::get_response_count_by_question( $survey_id );

			$snapshot = [
				'summary'                    => self::measure_summary( $survey_id, $participation['seen'], $answer_votes, $response_count ),
				'seen_count_by_question'     => $participation['seen'],
				'response_count_by_question' => $response_count,
				'answer_votes'               => $answer_votes,
				'other_votes_by_question'    => $other_votes_by_question,
				'text_response_counts'       => $text_response_counts,
				'answered_count_by_question' => $participation['answered'],
				'dropoff_by_question'        => self::get_dropoff_by_question( $survey_id ),
				'scale_responses'            => self::get_scale_responses( $survey_id ),
			];

			/**
			 * Filter the analytics snapshot. Pro attaches the aggregates the base
			 * measurement does not cover. Anything added here is measured in the same
			 * pass as the summary figures - and must be numbers only, never labels.
			 *
			 * @param array $snapshot  Snapshot of aggregates.
			 * @param int   $survey_id Survey ID.
			 */
			return apply_filters( 'surveyx_survey_overview_snapshot', $snapshot, $survey_id );
		}

		/**
		 * Measure the survey-level figures the Insights tab renders.
		 *
		 * Reproduces, deliberately and exactly, the definitions surveyx_summary stored, by calling
		 * the same SurveyX_Db methods the recount used rather than rewriting their SQL: getting
		 * one subtly wrong changes what a number MEANS after an upgrade.
		 *
		 * - total_starts is SUM(status != 'viewed' AND != 'expired'), not a session count.
		 * - total_dropoffs counts abandoned sessions, not starts - completions, and counts
		 *   SurveyX_Session_Manager::dropped_off_sql() rather than the stored status alone, so it
		 *   does not read 0 on an install whose cron never runs. Identical once the sweep has run.
		 * - average_time_seconds is AVG(time_spent) over COMPLETED sessions, truncated, and - the
		 *   one definition deliberately NOT preserved - bounded to plausible sittings (see
		 *   SurveyX_Db::MAX_RESPONSE_TIME_SECONDS), NULL when nothing qualifies. The stored column
		 *   averaged an unbounded wall-clock span, so a single resumed abandonment reported a
		 *   survey's response time in days.
		 * - most_common_dropoff_question_id has no tiebreak, so ties may resolve to a different
		 *   question id than a previous measurement chose. Inherent to the definition.
		 *
		 * `question_seen_counts`, `answer_votes_json` and `response_count_by_question` stay JSON
		 * strings because that is the shape the payload has always had; the zeros are stripped
		 * from the seen counts because the stored query grouped rows that HAVE a viewed_at, so a
		 * question with none was absent, not zero.
		 *
		 * @param int   $survey_id      Survey ID.
		 * @param array $seen_counts    {question_id => distinct sessions that saw it}.
		 * @param array $answer_votes   {answer_id => votes}.
		 * @param array $response_count {question_id => answered response rows}.
		 * @return array Summary figures, keyed exactly as the payload has always been.
		 */
		private static function measure_summary( int $survey_id, array $seen_counts, array $answer_votes, array $response_count ) {
			$stats = SurveyX_Db::get_session_stats( $survey_id );

			$completion_rate = $stats['starts'] > 0 ? ( $stats['completions'] / $stats['starts'] ) * 100 : 0;
			$dropoff_rate    = $stats['starts'] > 0 ? ( $stats['dropoffs'] / $stats['starts'] ) * 100 : 0;

			$most_common_dropoff = SurveyX_Db::get_most_common_dropoff( $survey_id );

			return [
				'total_views'                     => $stats['views'],
				'total_starts'                    => $stats['starts'],
				'total_completions'               => $stats['completions'],
				'total_dropoffs'                  => $stats['dropoffs'],
				// Two places because the figure lived in a DECIMAL(5,2) column and everything
				// downstream was written against that precision.
				'completion_rate'                 => round( $completion_rate, 2 ),
				'dropoff_rate'                    => round( $dropoff_rate, 2 ),
				'average_time_seconds'            => $stats['avg_time'],
				'most_common_dropoff_question_id' => null === $most_common_dropoff ? null : (int) $most_common_dropoff,
				'question_seen_counts'            => wp_json_encode( array_filter( $seen_counts ) ),
				'answer_votes_json'               => wp_json_encode( $answer_votes ),
				'response_count_by_question'      => wp_json_encode( $response_count ),
				// The moment this snapshot was measured; nothing recounts any more.
				'last_updated'                    => surveyx_get_utc_now(),
			];
		}

		/**
		 * Build the overview payload from a snapshot plus current question/answer text.
		 *
		 * @param int   $survey_id Survey ID.
		 * @param array $snapshot  Snapshot produced by build_snapshot().
		 * @return array Overview data.
		 */
		protected static function hydrate_overview( int $survey_id, array $snapshot ) {
			$questions = self::get_questions( $survey_id );

			$seen_count_by_question = isset( $snapshot['seen_count_by_question'] ) && is_array( $snapshot['seen_count_by_question'] )
				? $snapshot['seen_count_by_question']
				: [];

			foreach ( $questions as $q ) {
				$qid = (string) $q->id;
				if ( ! isset( $seen_count_by_question[ $qid ] ) ) {
					$seen_count_by_question[ $qid ] = 0;
				}
			}

			// summary.last_updated ships as a UTC timestamp; the client converts and displays it.
			$data = [
				'questions'                  => $questions,
				'answers'                    => self::build_answers( $survey_id, $snapshot ),
				'summary'                    => (object) ( $snapshot['summary'] ?? [] ),
				'seen_count_by_question'     => $seen_count_by_question,
				'dropoff_by_question'        => $snapshot['dropoff_by_question'] ?? [],
				'response_count_by_question' => $snapshot['response_count_by_question'] ?? [],
				'answered_count_by_question' => $snapshot['answered_count_by_question'] ?? [],
				'scale_responses'            => $snapshot['scale_responses'] ?? [],
				'text_response_counts'       => $snapshot['text_response_counts'] ?? [],
			];

			/**
			 * Filter survey overview data. Allows Pro to extend with additional data.
			 *
			 * @param array $data      Overview data.
			 * @param int   $survey_id Survey ID.
			 * @param array $snapshot  Snapshot the payload was built from.
			 */
			return apply_filters( 'surveyx_survey_overview_data', $data, $survey_id, $snapshot );
		}

		/**
		 * Re-measure a survey and return the updated overview.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array|false Updated overview data or false on failure.
		 */
		public static function refresh_survey_summary( int $survey_id ) {
			if ( empty( $survey_id ) ) {
				return false;
			}

			// Forcing re-measures AND re-arms the cache in one step; skipping either leaves the
			// next request to measure the whole survey again.
			return self::get_survey_overview( $survey_id, true );
		}

		/**
		 * Get questions for a survey, in the author's order.
		 *
		 * The ORDER BY is load-bearing: the analytics screens read array position as the
		 * question number and as progress through the survey, so an id-ordered result
		 * mislabels every question after an insert or a drag-reorder.
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
					WHERE survey_id = %d
					ORDER BY sorder ASC, id ASC",
					$survey_id
				)
			);

			if ( empty( $questions ) ) {
				return [];
			}

			foreach ( $questions as $question ) {
				$full_content      = json_decode( $question->content, true );
				$question->content = [
					'title'             => $full_content['title'] ?? '',
					'type'              => $full_content['type'] ?? 'check_box',
					'image_url'         => $full_content['image_url'] ?? '',
					'show_other_option' => ! empty( $full_content['show_other_option'] ),
					'is_required'       => ! empty( $full_content['is_required'] ),
				];
			}

			return $questions;
		}

		/**
		 * Build the Summary tab answer list: current answer text joined to snapshot votes.
		 *
		 * The votes come from the snapshot (so they agree with the seen counts they are
		 * shown against); the titles and images are read now (so an edited answer shows
		 * immediately). The virtual "Other" answer is built here rather than cached
		 * because its label is translated and the snapshot must stay locale-free.
		 *
		 * @param int   $survey_id Survey ID.
		 * @param array $snapshot  Snapshot produced by build_snapshot().
		 * @return array Answers array with vote counts from the snapshot.
		 */
		private static function build_answers( int $survey_id, array $snapshot ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$answers = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.id, a.question_id, a.content
					FROM {$wpdb->prefix}surveyx_answers a
					WHERE a.survey_id = %d
					ORDER BY a.question_id ASC, a.sorder ASC, a.id ASC",
					$survey_id
				)
			);

			if ( empty( $answers ) ) {
				$answers = [];
			}

			$votes_map = ( isset( $snapshot['answer_votes'] ) && is_array( $snapshot['answer_votes'] ) )
				? $snapshot['answer_votes']
				: [];

			foreach ( $answers as $answer ) {
				$full_content        = json_decode( $answer->content, true );
				$answer->content     = [
					'title'     => $full_content['title'] ?? '',
					'image_url' => $full_content['image_url'] ?? '',
				];
				$answer->total_votes = (int) ( $votes_map[ (string) $answer->id ] ?? 0 );
			}

			// Virtual "Other" answer (answer_id = 0) per question, from the snapshot.
			$other_votes = ( isset( $snapshot['other_votes_by_question'] ) && is_array( $snapshot['other_votes_by_question'] ) )
				? $snapshot['other_votes_by_question']
				: [];

			foreach ( $other_votes as $question_id => $vote_count ) {
				if ( $vote_count <= 0 ) {
					continue;
				}

				$answers[] = (object) [
					'id'          => 0,
					'question_id' => (int) $question_id,
					'content'     => [
						'title'           => esc_html__( 'Other', 'surveyx-builder' ),
						'image_url'       => '',
						'is_other_option' => true,
					],
					'total_votes' => (int) $vote_count,
				];
			}

			return $answers;
		}

		/**
		 * Count, per question, the DISTINCT respondents who SAW it and who ANSWERED it - the
		 * denominator and numerator of the response rate, in one statement.
		 *
		 * One statement is one instant: a /progress write landed before this read and is in both
		 * counts, or after it and is in neither, never in one only. Counting distinct respondents
		 * rather than summing answer votes is what stops a multi-select or matrix question
		 * counting one respondent several times.
		 *
		 * It does NOT guarantee answered <= seen: the two predicates are independent columns, not
		 * nested sets. Rows this plugin writes always carry a viewed_at
		 * (SurveyX_Db::create_responses() defaults it to now), but surveyx_fix_viewed_at_datetime()
		 * nulls unparseable legacy values without exempting response_status = 'answered', so an
		 * upgraded install can hold answered rows that count as answered and not as seen. The
		 * rendered ratio is clamped for exactly that case.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array { seen: {question_id => count}, answered: {question_id => count} }
		 */
		protected static function get_participation_counts( int $survey_id ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT question_id,
                        COUNT(DISTINCT CASE WHEN viewed_at IS NOT NULL THEN session_id END) as seen_count,
                        COUNT(DISTINCT CASE WHEN response_status = 'answered' THEN session_id END) as answered_count
                    FROM {$wpdb->prefix}surveyx_responses
                    WHERE survey_id = %d
                    GROUP BY question_id",
					$survey_id
				)
			);

			$counts = [
				'seen'     => [],
				'answered' => [],
			];

			foreach ( $results as $row ) {
				$counts['seen'][ (string) $row->question_id ]     = (int) $row->seen_count;
				$counts['answered'][ (string) $row->question_id ] = (int) $row->answered_count;
			}

			return $counts;
		}

		/**
		 * Get drop-off count per question. Base returns an empty array; Pro measures it.
		 *
		 * Must stay declared in Free: build_snapshot() calls it unconditionally, and deleting it
		 * once made every Free analytics request fatal on an undefined method.
		 *
		 * @param int $_survey_id Survey ID (used by the Pro implementation).
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

			$counts = [];
			foreach ( $results as $row ) {
				$qid  = (string) $row->question_id;
				$type = $type_map[ (int) $row->answer_id ] ?? null;

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

			$type_map = [
				-2 => 'text_input',
				0  => 'other',
			];
			$type_map = apply_filters( 'surveyx_text_response_type_map', $type_map );

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

			$type_map = [
				-2 => 'text_input',
				0  => 'other',
			];
			$type_map = apply_filters( 'surveyx_text_response_type_map', $type_map );

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
