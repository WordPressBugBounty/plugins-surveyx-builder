<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SurveyX_Db', false ) ) {
	class SurveyX_Db {

		/**
		 * Longest session span that still counts as a response time, in seconds.
		 *
		 * `surveyx_sessions.time_spent` is not a stopwatch. complete_session() sets it from
		 * get_session_time_spent(), which is MIN(viewed_at) → MAX(answered_at) across the
		 * session's answered rows — wall clock, with no bound. A respondent who leaves the tab
		 * open is marked dropped_off after SESSION_TIMEOUT_MINUTES, but restart_pending lets them
		 * come back, and the span is then recomputed across the whole absence with nothing in the
		 * row recording that it happened.
		 *
		 * Averaging those spans produced a global "Avg Response Time" of 4h 15m on a real
		 * 455-session install; TWO sessions (one spanning 99 days, one 61) were responsible, and
		 * removing them put the same figure at 55 seconds — four tenths of one percent of the data
		 * moving the headline by 279x, and doing the same to a single survey's column. So the
		 * aggregates below measure only sessions that could plausibly BE one sitting. On that data
		 * the distribution holds nothing at all between 23 minutes (the longest genuine session)
		 * and 61 days, so the exact figure is not load-bearing and a generous one costs nothing.
		 * It is a DISPLAY bound only: no stored value is altered or discarded.
		 *
		 * time_spent = 0 is excluded for the opposite reason — it is what get_session_time_spent()
		 * returns when it could not measure at all (no answered row carrying both timestamps), so
		 * counting it as a zero-second response drags the mean the other way. 18% of completed
		 * sessions in that dataset are zeros.
		 *
		 * A survey with no completed session inside these bounds has no measurable response time,
		 * so the aggregates report NULL rather than 0 and the admin can show an em dash instead of
		 * a confident wrong number.
		 */
		const MAX_RESPONSE_TIME_SECONDS = 14400;

		/** Survey mode meaning "needs Pro to edit and to render". */
		const MODE_PRO = 'pro';

		/** Survey mode meaning "the basic engine can edit and render this survey". */
		const MODE_BASIC = 'basic';

		/**
		 * Question types the basic renderer is able to display.
		 *
		 * Mirrors `baseQuestionTypes` in the basic edition's
		 * resources/frontend/composables/question-type-loader.js. A type NOT listed marks the
		 * survey MODE_PRO (see classify_questions()), so a type added later is Pro-only by
		 * default rather than an empty question box. Add a type here only when the basic
		 * renderer can actually display it.
		 *
		 * @var string[]
		 */
		const BASIC_RENDERABLE_QUESTION_TYPES = [
			'check_box',
			'multi_check_box',
			'check_box_image',
			'multi_check_box_image',
			'select',
			'text_input',
			'yes_no',
		];

		/**
		 * Resolved mode per survey id for this request, so classify_survey() runs once per
		 * survey however many gates ask about it. Dropped by flush_survey_mode() on save.
		 *
		 * @var array<int,string>
		 */
		private static $mode_cache = [];

		/**
		 * THE basic/pro rule, applied to a set of questions.
		 *
		 * More than one question is MODE_PRO: the basic editor and renderer both hold exactly
		 * one, so opening a multi-question survey there and saving it would discard the
		 * rest. A single question is MODE_PRO too when its type is outside
		 * BASIC_RENDERABLE_QUESTION_TYPES — the same rule expressed for types, since the
		 * respondent would otherwise be shown an empty box. Everything else is MODE_BASIC.
		 *
		 * The rule runs in both directions: dropping the extra questions, or swapping the
		 * only question back to a basic type, returns the survey to MODE_BASIC.
		 *
		 * @param array $questions Question records: arrays or objects carrying a `content`
		 *                         member, itself either a decoded array or its raw JSON.
		 *
		 * @return string MODE_PRO or MODE_BASIC.
		 */
		public static function classify_questions( $questions ) {
			if ( ! is_array( $questions ) ) {
				return self::MODE_BASIC;
			}

			if ( count( $questions ) > 1 ) {
				return self::MODE_PRO;
			}

			foreach ( $questions as $question ) {
				if ( ! in_array( self::read_question_type( $question ), self::BASIC_RENDERABLE_QUESTION_TYPES, true ) ) {
					return self::MODE_PRO;
				}
			}

			return self::MODE_BASIC;
		}

		/**
		 * Reads a question record's type out of whichever shape the caller holds.
		 *
		 * A save posts questions whose `content` is already decoded; a row read back from
		 * surveyx_questions carries it as JSON. One reader covers both, so the rule above
		 * never has to know which side called it.
		 *
		 * @param mixed $question Question record.
		 *
		 * @return string The type, or '' when the record carries none.
		 */
		private static function read_question_type( $question ) {
			if ( is_object( $question ) ) {
				$question = get_object_vars( $question );
			}

			$content = is_array( $question ) ? ( $question['content'] ?? null ) : null;

			if ( is_string( $content ) ) {
				$content = json_decode( $content, true );
			}

			return is_array( $content ) ? (string) ( $content['type'] ?? '' ) : '';
		}

		/**
		 * Applies the rule to a survey by reading its stored questions.
		 *
		 * LIMIT 2 is what keeps this bounded: two rows already settle the count half of the
		 * rule whatever they contain, so at most two question blobs are ever loaded, however
		 * long the survey is.
		 *
		 * @param int $survey_id Survey ID.
		 *
		 * @return string MODE_PRO or MODE_BASIC.
		 */
		public static function classify_survey( $survey_id ) {
			global $wpdb;

			$survey_id = absint( $survey_id );

			if ( empty( $survey_id ) ) {
				return self::MODE_BASIC;
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT content FROM {$wpdb->prefix}surveyx_questions WHERE survey_id = %d LIMIT 2",
					$survey_id
				)
			);

			return self::classify_questions( (array) $rows );
		}

		/**
		 * A survey's mode: its stamp when that stamp means something, the rule run now when
		 * it does not.
		 *
		 * `s_mode` is a NOT NULL varchar, so any INSERT that omitted the column stored '' —
		 * a survey that has never been CLASSIFIED, not one classified as basic. The gates
		 * that used to read the column each guessed differently ('' counted as basic at
		 * some, as not-basic at others), so one survey could be editable at one door,
		 * refused at the next and a fatal error at a third. An unknown stamp is therefore
		 * resolved by running classify_survey() rather than guessed, and the migration step
		 * [stamp_survey_modes] writes the answer back so this stays a cold path.
		 *
		 * @param int         $survey_id Survey ID.
		 * @param string|null $s_mode    The stored stamp when the caller already holds the
		 *                               row, so no second read is needed. Null to read it here.
		 *
		 * @return string MODE_PRO or MODE_BASIC.
		 */
		public static function resolve_survey_mode( $survey_id, $s_mode = null ) {
			if ( self::MODE_PRO === $s_mode || self::MODE_BASIC === $s_mode ) {
				return $s_mode;
			}

			$survey_id = absint( $survey_id );

			if ( empty( $survey_id ) ) {
				return self::MODE_BASIC;
			}

			if ( isset( self::$mode_cache[ $survey_id ] ) ) {
				return self::$mode_cache[ $survey_id ];
			}

			// The caller held no row, so the stamp still has to be read before deciding
			// whether the rule needs running at all.
			if ( null === $s_mode ) {
				global $wpdb;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$stored = (string) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT s_mode FROM {$wpdb->prefix}surveyx_surveys WHERE id = %d",
						$survey_id
					)
				);

				if ( self::MODE_PRO === $stored || self::MODE_BASIC === $stored ) {
					self::$mode_cache[ $survey_id ] = $stored;

					return $stored;
				}
			}

			self::$mode_cache[ $survey_id ] = self::classify_survey( $survey_id );

			return self::$mode_cache[ $survey_id ];
		}

		/**
		 * THE reader predicate: is this survey Pro-only for the edition now running?
		 *
		 * Every gate asks this one question — the editor endpoints, the dashboard listing,
		 * the [surveyx] shortcode, the standalone survey page and the public /init and
		 * /progress permission check — so they cannot answer it differently for the same
		 * survey, which is the whole point of the helper.
		 *
		 * With Pro active the answer is always false: Pro edits and renders every survey,
		 * whatever its mode. That is why the copies of the shared files that carry this call
		 * behave identically to before in Pro.
		 *
		 * @param int         $survey_id Survey ID.
		 * @param string|null $s_mode    The stored stamp when the caller already holds the row.
		 *
		 * @return bool True when the running edition can neither edit nor render this survey.
		 */
		public static function survey_needs_pro( $survey_id, $s_mode = null ) {
			if ( defined( 'SURVEYX_PRO_VERSION' ) ) {
				return false;
			}

			return self::MODE_PRO === self::resolve_survey_mode( $survey_id, $s_mode );
		}

		/**
		 * Drops a survey's resolved mode so a later gate in the same request re-reads it.
		 *
		 * Called from the save path, which is the only thing that can change the answer
		 * while a request is running.
		 *
		 * @param int $survey_id Survey ID.
		 *
		 * @return void
		 */
		public static function flush_survey_mode( $survey_id ) {
			unset( self::$mode_cache[ absint( $survey_id ) ] );
		}

		/**
		 * THE writer: persists the mode so the gates read an answer instead of computing one.
		 *
		 * The rule itself lives in classify_questions(); this only stores what it returns.
		 * Every path that creates a survey or rewrites its questions has to call this, or the
		 * survey keeps the '' stamp create_survey() seeds - and an unstamped survey is one
		 * every gate re-classifies on every request.
		 *
		 * @param int        $survey_id Survey ID.
		 * @param array|null $questions The questions as the caller holds them, for a save that
		 *                              has them in hand. Null reads the rows back instead,
		 *                              which is what an import wants once they have landed.
		 *
		 * @return bool True when the stamp was written.
		 */
		public static function stamp_survey_mode( $survey_id, $questions = null ) {
			global $wpdb;

			$survey_id = absint( $survey_id );

			if ( empty( $survey_id ) ) {
				return false;
			}

			$s_mode = is_array( $questions )
				? self::classify_questions( $questions )
				: self::classify_survey( $survey_id );

			// A gate may already have resolved (and memoised) this survey earlier in the
			// request, from the questions as they were before this write.
			self::flush_survey_mode( $survey_id );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->update(
				$wpdb->prefix . 'surveyx_surveys',
				[ 's_mode' => $s_mode ],
				[ 'id' => $survey_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

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
		 * Gets the survey row needed to render the shortcode in a single query.
		 *
		 * Consolidates the previous per-call queries (survey_exists, get_survey_mode,
		 * get_survey_settings) into one row, memoized per survey_id for the current request so
		 * repeated shortcode/enqueue passes reuse the same result. `title` and `cover` are also
		 * selected: the shortcode does not need them, but the standalone survey page controller
		 * (SurveyX_Public_Page) builds the page title, canonical URL and social tags from them.
		 *
		 * @param int $survey_id Survey ID.
		 *
		 * @return object|null Row with `id`, `title`, `status`, `s_mode`, `cover`, decoded
		 *                     `settings` (array|null) and decoded `content` (array|null), plus
		 *                     `is_published` (bool: status active with non-empty content). Null
		 *                     if the survey does not exist.
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
					"SELECT id, title, settings, content, status, s_mode, cover FROM {$wpdb->prefix}surveyx_surveys WHERE id = %d LIMIT 1",
					$survey_id
				)
			);

			if ( ! is_null( $row ) ) {
				// Computed before the decode below, which replaces the raw JSON string.
				$row->is_published = ( 'active' === $row->status && '' !== (string) $row->content );
				$row->settings     = json_decode( $row->settings, true );
				$row->content      = json_decode( $row->content, true );
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}surveyx_sessions
					SET session_status = 'expired', completed_at = NULL, progress_percentage = 0, current_question_id = 0
					WHERE survey_id = %d AND session_status IN ('completed', 'dropped_off')",
					$survey_id
				)
			);

			// Invalidate the cached analytics so they re-measure without the just-reset
			// (now 'expired') sessions instead of serving stale numbers for the length
			// of the cache.
			self::flush_analytics_cache( $survey_id );

			// Flush the respondent-facing vote cache so /vote-results does not serve the pre-reset
			// counts: VOTE_CACHE_TTL is 12 HOURS, so without this flush a respondent could be shown
			// the discarded tally for the rest of the day.
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
				$special_answer_id = self::get_special_answer_id( $question_type, $response_status );
				$table             = $wpdb->prefix . 'surveyx_responses';

				// INSERT IGNORE: the unique_response key (session_id, question_id,
				// respondent_id, answer_id) makes a concurrent identical submit a harmless
				// no-op instead of a duplicate row or a duplicate-key error.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			// INSERT IGNORE, as above: with unique_response in place a concurrent identical submit is
			// skipped rather than duplicated or erroring — the guarantee the removed named lock gave.
			$sql = "INSERT IGNORE INTO {$table}
				(session_id, survey_id, question_id, answer_id, respondent_id, response_content, response_status, viewed_at, answered_at)
				VALUES " . implode( ', ', $placeholders );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

			return false !== $result;
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
			$table     = $wpdb->prefix . 'surveyx_responses';

			// Idempotent insert: create the row only if no response yet exists for this
			// (session, question, respondent). This lets the /question-seen handler drop its
			// separate existence COUNT query, and guarantees a single 'seen' row even when the
			// respondent navigates back to an already-viewed (or already-answered) question.
			// INSERT IGNORE keeps a concurrent second seen-insert (which passes NOT EXISTS but
			// would then hit the unique_response key) a harmless no-op instead of an error.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		 * @param int $survey_id   Optional. When greater than 0 the question must also
		 *                         belong to this survey, otherwise null is returned.
		 *                         Public write paths always pass it, so a foreign or
		 *                         nonexistent question_id is rejected by the caller
		 *                         instead of falling through to type defaults.
		 *
		 * @return array|null The decoded question content as an associative array, or null if not found.
		 */
		public static function get_question_content( $question_id, $survey_id = 0 ) {
			global $wpdb;

			if ( $survey_id > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT content FROM {$wpdb->prefix}surveyx_questions WHERE id = %d AND survey_id = %d",
						$question_id,
						$survey_id
					)
				);
			} else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT content FROM {$wpdb->prefix}surveyx_questions WHERE id = %d",
						$question_id
					)
				);
			}

			if ( is_null( $result ) ) {
				return null;
			}

			return json_decode( $result, true );
		}

		/**
		 * Returns which of the given answer IDs really belong to a question.
		 *
		 * Used by the public /progress path to drop answer IDs that were never
		 * offered for the submitted question. Zero and negative IDs are stripped
		 * before the query (0 is the virtual "Other" option and the negatives are
		 * server-side sentinels — neither is a surveyx_answers row).
		 *
		 * @param int   $question_id The question the answers must belong to.
		 * @param array $answer_ids  Answer IDs to check.
		 *
		 * @return array The subset of $answer_ids that exists on this question.
		 */
		public static function filter_answer_ids_by_question( $question_id, $answer_ids ) {
			global $wpdb;

			$answer_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $answer_ids ) ) ) );

			if ( empty( $answer_ids ) ) {
				return [];
			}

			$placeholders = implode( ', ', array_fill( 0, count( $answer_ids ), '%d' ) );

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}surveyx_answers WHERE question_id = %d AND id IN ({$placeholders})",
					array_merge( [ absint( $question_id ) ], $answer_ids )
				)
			);
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return array_map( 'absint', (array) $found );
		}

		/**
		 * Retrieves a published survey by its ID with non-empty content.
		 *
		 * The basic/pro test is no longer a WHERE clause: SQL can only compare the stored
		 * stamp, and an unstamped row has no stamp to compare. `s_mode` is selected instead
		 * and handed to survey_needs_pro(), which resolves that case by running the rule.
		 * It is dropped from the returned object again so callers see the shape they always did.
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
					"SELECT id, title, settings, content, survey_type, s_mode
                    FROM {$wpdb->prefix}surveyx_surveys
                    WHERE id = %d
                    AND status = 'active'
                    AND content != ''",
					$survey_id
				)
			);

			if ( is_null( $result ) || ! $result ) {
				return false;
			}

			if ( self::survey_needs_pro( $result->id, (string) $result->s_mode ) ) {
				return false;
			}

			unset( $result->s_mode );

			$result->settings = json_decode( $result->settings, true );
			$result->content  = json_decode( $result->content, true );

			return $result;
		}

		/**
		 * Whether a survey may currently be answered by the public.
		 *
		 * Survey-level authorization gate shared by EVERY public REST route: it is
		 * called once per request from SurveyX_API_Handler::check_public_permission()
		 * (client/rest-routes.php), so /init, /progress, /question-seen,
		 * /activate-session, /complete-session and /vote-results all reach the same
		 * answer instead of each re-deciding. The respondent-scoped checks (session,
		 * question ownership) stay in the individual handlers.
		 *
		 * Mirrors the publish rule of get_published_survey_by_id(): active status and
		 * a survey the basic engine may render. Cost: one primary-key read of two small
		 * columns, so the gate stays cheap on every public request. survey_needs_pro()
		 * adds a query only for a survey whose stamp is missing, which the migration
		 * step [stamp_survey_modes] leaves none of.
		 *
		 * @param int $survey_id The ID of the survey to check.
		 *
		 * @return bool True when the survey is active and renderable by the basic engine.
		 */
		public static function is_survey_open( $survey_id ) {
			global $wpdb;

			$survey_id = absint( $survey_id );

			if ( empty( $survey_id ) ) {
				return false;
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT status, s_mode FROM {$wpdb->prefix}surveyx_surveys
                    WHERE id = %d
                    LIMIT 1",
					$survey_id
				)
			);

			if ( is_null( $row ) || 'active' !== $row->status ) {
				return false;
			}

			return ! self::survey_needs_pro( $survey_id, (string) $row->s_mode );
		}

		// Static /init payload cache: get_survey_init_data → get_survey_init_static →
		// build_survey_init_static → get_init_static_cache_key → flush_survey_init_cache. The
		// static part is shared by every visitor and cached across requests; it is invalidated on
		// every survey edit via the surveyx_survey_saved / surveyx_survey_deleted actions (see the
		// main plugin file's listener registration).

		/**
		 * Get complete published survey data in optimized queries.
		 *
		 * The basic edition renders a single-question poll, so only the first question and its
		 * answers are returned. The static part is served from the cross-request /init
		 * cache; per-respondent responses are always fetched live.
		 *
		 * @param int    $survey_id     Survey ID.
		 * @param string $respondent_id Optional respondent UUID for the caller's responses.
		 * @return array|null ['survey','questions','answers','responses'] or null when the
		 *                    survey is not renderable (missing, draft, empty, or Pro-only by
		 *                    SurveyX_Db::survey_needs_pro()).
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
		 * Basic: only the first question (single-question poll) and its answers.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array|null ['survey','questions','answers'] or null if the survey is not renderable.
		 */
		private static function build_survey_init_static( $survey_id ) {
			global $wpdb;

			// 1) Survey row — settings/content blobs fetched once. The basic/pro test runs
			// in PHP below, because an unstamped row has no stamp for SQL to compare.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$survey_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, title, settings, content, survey_type, s_mode
					FROM {$wpdb->prefix}surveyx_surveys
					WHERE id = %d AND status = 'active' AND content != ''",
					$survey_id
				)
			);

			if ( ! $survey_row ) {
				return null;
			}

			if ( self::survey_needs_pro( $survey_row->id, (string) $survey_row->s_mode ) ) {
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

			// 2) First question only (basic renders a single-question poll).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$question_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, content
					FROM {$wpdb->prefix}surveyx_questions
					WHERE survey_id = %d AND content != ''
					ORDER BY sorder ASC
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
						ORDER BY sorder ASC",
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

			// Swap every image URL for the admin-chosen crop size so the front end downloads smaller
			// files. Done once here, before the payload is cached. Answers get their own size because
			// they render as thumbnails in a choice list, where a full-size download is pure waste;
			// the survey content (cover, closings, results) and the question header images are
			// displayed large, so they stay full-size. This splits only the DEFAULT — once an admin
			// picks a size explicitly, both resolvers return it and sizing is uniform again.
			$image_size        = self::get_frontend_image_size();
			$answer_image_size = self::get_frontend_answer_image_size();

			if ( 'full' !== $image_size ) {
				self::resize_content_images( $survey->content, $image_size );
				foreach ( $questions as $question ) {
					self::resize_content_images( $question->content, $image_size );
				}
			}

			if ( 'full' !== $answer_image_size ) {
				foreach ( $answers as $answer ) {
					self::resize_content_images( $answer->content, $answer_image_size );
				}
			}

			return [
				'survey'    => $survey,
				'questions' => $questions,
				'answers'   => $answers,
			];
		}

		/**
		 * Builds the transient key for a survey's cached static /init payload. The plugin version
		 * is baked in so an update that ever changes the payload shape invalidates every survey's
		 * cached payload at once.
		 *
		 * @param int $survey_id Survey ID.
		 * @return string Transient key.
		 */
		private static function get_init_static_cache_key( $survey_id ) {
			// Pro caches every question, basic only the first, so the edition must be
			// part of the key: the two share a version line and would otherwise collide
			// on one key, serving each other's payload after a plugin switch.
			$version = defined( 'SURVEYX_PRO_VERSION' )
				? 'pro_' . SURVEYX_PRO_VERSION
				: 'basic_' . ( defined( 'SURVEYX_VERSION' ) ? SURVEYX_VERSION : '0' );

			// The configured image size is baked in so changing it (a global setting)
			// invalidates every survey's cached payload — the URLs inside differ. The
			// RAW setting is used rather than a resolved size because the payload now
			// mixes two sizes; the raw value is the single input both are derived from,
			// so one key segment still covers every combination. 'auto' stands for "no
			// explicit choice", which is a different payload from an explicit 'full'.
			$image_size = self::get_configured_image_size();

			return 'surveyx_init_static_' . $version . '_' . ( '' === $image_size ? 'auto' : $image_size ) . '_' . absint( $survey_id );
		}

		/** Size used for images that render large: cover, closings, results, question headers. */
		const DEFAULT_IMAGE_SIZE = 'full';

		/** Size used for answer images, which render as thumbnails in a choice list. */
		const DEFAULT_ANSWER_IMAGE_SIZE = 'medium';

		/**
		 * Returns the image size the admin explicitly chose, or '' when they have
		 * expressed no preference (key absent or empty). The empty string is the
		 * "automatic" state and is what old installs — which never had the key —
		 * already look like, so no migration is involved.
		 *
		 * @return string A registered image size name, or '' for automatic.
		 */
		private static function get_configured_image_size() {
			$settings = get_option( 'surveyx_settings', [] );

			if ( ! is_array( $settings ) || empty( $settings['frontend_image_size'] ) ) {
				return '';
			}

			return sanitize_key( $settings['frontend_image_size'] );
		}

		/**
		 * Returns the image size for front-end survey images that render large —
		 * the cover, closing and result screens, and question header images.
		 * Defaults to 'full' (original image) for back-compat and when unset.
		 *
		 * @return string A registered image size name, or 'full'.
		 */
		public static function get_frontend_image_size() {
			$size = self::get_configured_image_size();

			return '' === $size ? self::DEFAULT_IMAGE_SIZE : $size;
		}

		/**
		 * Returns the image size for answer images. These render as thumbnails in a
		 * choice list, so they default to 'medium' rather than the original file. An
		 * explicit admin choice still wins and applies everywhere.
		 *
		 * @return string A registered image size name.
		 */
		public static function get_frontend_answer_image_size() {
			$size = self::get_configured_image_size();

			return '' === $size ? self::DEFAULT_ANSWER_IMAGE_SIZE : $size;
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
		 * Retrieves the total number of votes (total_votes) for each answer grouped by
		 * question, together with the moment the tally was computed.
		 *
		 * Counts 'answered' status responses with answer_id >= 0 (regular answers + Other).
		 * Excludes special question types (text_input=-2, contact_info=-3, skipped=-4).
		 *
		 * @param int $survey_id The ID of the survey to retrieve vote data for.
		 *
		 * @return array{data: array, generated_at: int} Vote data grouped by question -
		 *                                               an empty `data` means "computed,
		 *                                               no votes yet" - plus the raw UTC
		 *                                               epoch the tally was built at.
		 */
		public static function get_answer_total_vote( $survey_id ) {
			global $wpdb;

			// Long cache with an explicit invalidation path. The aggregate is a full GROUP BY scan
			// (226 ms at 180k responses) and /vote-results is hit by every results viewer.
			// VOTE_CACHE_TTL is a ceiling, NOT the freshness contract: freshness comes from the flush
			// the /progress write path performs, so the tally a respondent is shown always contains
			// their own vote. See flush_vote_cache().
			//
			// `data` carries the empty-array sentinel, which distinguishes "computed, no votes yet"
			// from "not cached", so a survey with no votes does not re-run the scan on every open.
			// `generated_at` is a raw time() UTC epoch handed straight to human_time_diff():
			// deliberately not a formatted string and not current_time( 'timestamp' ), either of which
			// would drift by the site's UTC offset.
			$cache_key = self::get_vote_cache_key( $survey_id );
			$cached    = get_transient( $cache_key );

			// A shape guard, not merely a hit test: an install upgrading with a warm cache still holds
			// the previous payload (a bare list, or [] for "no votes"), which has no timestamp. Treat
			// that as a miss and rebuild once.
			//
			// The age is then enforced here as well as by the transient. get_transient() reports a hit
			// whenever the value row outlives its _transient_timeout_ row, which several persistent
			// object caches produce under eviction; the payload would then be served for ever, with
			// the endpoint's "Updated N ago" label growing without bound beside it. A generated_at in
			// the future — clock skew, a restored database — is a miss too, not infinite freshness.
			if ( is_array( $cached ) && isset( $cached['data'] ) && isset( $cached['generated_at'] ) ) {
				$age = time() - (int) $cached['generated_at'];

				if ( $age >= 0 && $age < self::VOTE_CACHE_TTL ) {
					return $cached;
				}
			}

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
				return self::cache_vote_results( $cache_key, [] );
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

			return self::cache_vote_results( $cache_key, array_values( $grouped_total_votes ) );
		}

		/**
		 * Stamps a freshly computed vote tally and stores it under the vote cache key.
		 *
		 * @param string $cache_key Transient key from get_vote_cache_key().
		 * @param array  $data      Vote data grouped by question; empty for "no votes yet".
		 *
		 * @return array{data: array, generated_at: int} The payload that was cached.
		 */
		private static function cache_vote_results( $cache_key, $data ) {
			$payload = [
				'data'         => $data,
				'generated_at' => time(),
			];

			set_transient( $cache_key, $payload, self::VOTE_CACHE_TTL );

			return $payload;
		}

		/**
		 * Vote-results cache lifetime.
		 *
		 * A ceiling, not a freshness window: every write that moves the tally flushes
		 * the key, so this only bounds how long an unread, unchanged tally may sit. At
		 * 60 seconds a busy poll cleared the cache faster than it could ever be reused
		 * - the load the cache exists to absorb was the load that kept it cold.
		 */
		const VOTE_CACHE_TTL = 12 * HOUR_IN_SECONDS;

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
		 *
		 * Called from every path that moves the tally: the respondent's own /progress
		 * write, admin question/answer deletion, Allow Revote on Update, and the
		 * drop-off sweep. With VOTE_CACHE_TTL at 12 hours this flush IS the freshness
		 * contract - without it a voter would be served a tally that predates their
		 * own vote for the rest of the day.
		 *
		 * @param int $survey_id Survey ID.
		 * @return void
		 */
		public static function flush_vote_cache( $survey_id ) {
			delete_transient( self::get_vote_cache_key( $survey_id ) );
		}

		/**
		 * Invalidates the cached admin analytics for a survey.
		 *
		 * The one place the analytics cache keys are known outside the analytics class, so every
		 * path that changes a session or a response — completion, off-path pruning, the drop-off
		 * sweep, a reset, a session deletion — has a single call to make and cannot invalidate
		 * half of it.
		 *
		 * Both keys are deleted: `surveyx_summary_<id>` was the pre-2.0 six-hour recount gate and
		 * an install upgrading with a warm cache still holds it, and `surveyx_summary_snap_<id>`
		 * is the snapshot the admin screens actually read.
		 *
		 * The analytics class is loaded only in admin, REST and cron contexts, so the key is
		 * rebuilt inline when it is absent rather than assumed.
		 *
		 * @param int $survey_id Survey ID.
		 * @return void
		 */
		public static function flush_analytics_cache( $survey_id ) {
			$survey_id = absint( $survey_id );

			if ( $survey_id <= 0 ) {
				return;
			}

			delete_transient( 'surveyx_summary_' . $survey_id );
			delete_transient(
				class_exists( 'SurveyX_Analytics_Db' )
					? SurveyX_Analytics_Db::snapshot_key( $survey_id )
					: 'surveyx_summary_snap_' . $survey_id
			);
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
				'frontend_image_size'     => '',
				'default_cover_layout'    => 'stacked',
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
		 * update_option() returns false both on a genuine write failure and when the new value is
		 * identical to what is already stored (e.g. a save that only changes the separately-stored
		 * page_base option leaves this blob unchanged). Compare against the previously stored
		 * value so an unchanged save is still reported as a success.
		 *
		 * @param array $data Settings data array.
		 *
		 * @return array|false Settings array on success, false on failure.
		 */
		public static function update_settings( $data ) {
			$current = get_option( 'surveyx_settings' );
			$result  = update_option( 'surveyx_settings', $data );

			if ( ! $result && maybe_serialize( $data ) === maybe_serialize( $current ) ) {
				return $data;
			}

			return $result ? $data : false;
		}

		/**
		 * Increments view count when survey is loaded.
		 *
		 * Writes to surveyx_surveys.total_views once the [views_to_surveys] migration step is
		 * recorded, and to the surveyx_summary accumulator before that.
		 *
		 * The fallback is not defensive padding, it is the correctness of this method on a live
		 * site. This runs from /init, which is a REST route, and the runner's DDL gate refuses
		 * REST — so the migration cannot have happened on the request that first executes this
		 * code, and WordPress fires no activation hook on a plugin update either. On an install
		 * with DISABLE_WP_CRON, no system cron and an owner who never opens wp-admin, the column
		 * may not exist for a long time; assuming it would mean `Unknown column 'total_views'` on
		 * every survey view for exactly as long as that lasts.
		 *
		 * Keeping the summary upsert also keeps a rollback to a pre-2.0 build working: that build
		 * reads the accumulator out of surveyx_summary, and finds it there.
		 *
		 * @param int $survey_id The ID of the survey.
		 *
		 * @return void
		 */
		public static function increment_view_count( $survey_id ) {
			global $wpdb;

			if ( function_exists( 'surveyx_views_on_surveys_table' ) && surveyx_views_on_surveys_table() ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}surveyx_surveys
                        SET total_views = total_views + 1
                        WHERE id = %d",
						$survey_id
					)
				);

				return;
			}

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

		/**
		 * Gets session statistics for a survey.
		 *
		 * Everything but `views` is counted from surveyx_sessions. `views` is the accumulator, and
		 * which table holds it depends on whether the [views_to_surveys] migration step has been
		 * recorded — the same gate increment_view_count() writes through, so the read and the
		 * write can never be looking at different tables. Only the anchor table changes: both
		 * shapes are one row per survey joined to that survey's sessions.
		 *
		 * One deliberate difference in the post-migration shape. Anchored on surveyx_summary, a
		 * survey with NO summary row produced no result at all and every figure here read 0 even
		 * with live sessions in the table. Anchored on surveyx_surveys the row always exists, so
		 * such a survey now reports its real session counts against a view count of 0. That is the
		 * intended fix, not an accident of the rewrite.
		 *
		 * `avg_time` is bounded by MAX_RESPONSE_TIME_SECONDS and is NULL — not 0 — when the survey
		 * has no completed session inside that bound. See the constant for why an unbounded mean
		 * over this column is not a response time.
		 *
		 * `dropoffs` counts SurveyX_Session_Manager::dropped_off_sql(), not the stored status
		 * alone: an abandoned session is one the hourly sweep HAS marked or one it WOULD mark, so
		 * the figure no longer depends on when cron last ran.
		 *
		 * @param int $survey_id Survey ID.
		 * @return array Stats array with views, starts, completions, dropoffs, avg_time
		 *               (int|null).
		 */
		public static function get_session_stats( $survey_id ) {
			global $wpdb;

			if ( function_exists( 'surveyx_views_on_surveys_table' ) && surveyx_views_on_surveys_table() ) {
				$anchor    = $wpdb->prefix . 'surveyx_surveys';
				$anchor_id = 's.id';
			} else {
				$anchor    = $wpdb->prefix . 'surveyx_summary';
				$anchor_id = 's.survey_id';
			}

			// Drop-offs are counted from the predicate the hourly sweep writes down
			// rather than from the status it writes, so the figure is right on an install
			// whose cron never runs and unchanged on one whose cron does.
			$dropped_off = SurveyX_Session_Manager::dropped_off_sql( 'ss' );

			// $anchor and $anchor_id are literals chosen by the branch above, never
			// caller input; $dropped_off is a self-prepared fragment with its cutoff
			// already bound; $survey_id and the time bound are bound.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
                    s.total_views as views,
                    SUM(ss.session_status != 'viewed' AND ss.session_status != 'expired') as total,
                    SUM(ss.session_status = 'completed') as completions,
                    SUM({$dropped_off}) as dropoffs,
                    AVG(CASE WHEN ss.session_status = 'completed' AND ss.time_spent > 0 AND ss.time_spent <= %d THEN ss.time_spent END) as avg_time
                FROM {$anchor} s
                LEFT JOIN {$wpdb->prefix}surveyx_sessions ss ON {$anchor_id} = ss.survey_id
                WHERE {$anchor_id} = %d
                GROUP BY {$anchor_id}",
					self::MAX_RESPONSE_TIME_SECONDS,
					$survey_id
				)
			);

			// avg_time stays NULL when nothing was measurable: AVG() over no qualifying
			// row is NULL, and (int) NULL is 0 — the confident wrong number this
			// deliberately avoids.
			$avg_time = ( ! isset( $row->avg_time ) || null === $row->avg_time ) ? null : (int) $row->avg_time;

			return [
				'views'       => (int) ( $row->views ?? 0 ),
				'starts'      => (int) ( $row->total ?? 0 ),
				'completions' => (int) ( $row->completions ?? 0 ),
				'dropoffs'    => (int) ( $row->dropoffs ?? 0 ),
				'avg_time'    => $avg_time,
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

			$dropped_off = SurveyX_Session_Manager::dropped_off_sql();

            // $dropped_off is a self-prepared fragment with its cutoff already bound.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT current_question_id
                FROM {$wpdb->prefix}surveyx_sessions
                WHERE survey_id = %d AND {$dropped_off} AND current_question_id > 0
                GROUP BY current_question_id
                ORDER BY COUNT(*) DESC
                LIMIT 1",
					$survey_id
				)
			);
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
	}
}
