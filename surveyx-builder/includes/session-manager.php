<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Session_Manager', false ) ) {
	/**
	 * Session Manager for SurveyX
	 *
	 * Handles real-time session tracking, progress updates, and session lifecycle.
	 * Sessions are tracked by respondent_id only.
	 * Respondent information (email, IP, user_agent, location) is now stored in surveyx_respondents table.
	 *
	 * IMPORTANT: All datetime values are stored in UTC via surveyx_get_utc_now()
	 * (WordPress core's current_time('mysql', true)) to ensure consistent time
	 * calculations across different server and MySQL timezones.
	 */
	class SurveyX_Session_Manager {

		/**
		 * Session timeout in minutes (10 minutes = drop-off)
		 */
		const SESSION_TIMEOUT_MINUTES = 10;

		/**
		 * Gets current UTC datetime string for database storage.
		 *
		 * Thin alias for surveyx_get_utc_now() — the single source of truth for
		 * "now". Kept for its many in-class callers; do not add logic here.
		 *
		 * @return string MySQL datetime format in UTC.
		 */
		public static function get_utc_now() {
			return surveyx_get_utc_now();
		}

		/**
		 * The activity cutoff that separates a live session from an abandoned one.
		 *
		 * A session whose last_activity_at is older than this has been abandoned. This
		 * is the one place SESSION_TIMEOUT_MINUTES becomes a datetime, so the sweep
		 * that writes 'dropped_off' and the analytics predicate that counts drop-offs
		 * without waiting for the sweep cannot disagree about where the line falls.
		 *
		 * @return string MySQL datetime in UTC.
		 */
		public static function get_stale_cutoff() {
			return gmdate( 'Y-m-d H:i:s', time() - ( self::SESSION_TIMEOUT_MINUTES * MINUTE_IN_SECONDS ) );
		}

		/**
		 * SQL matching an abandoned session: still 'active', silent since the cutoff.
		 *
		 * mark_stale_sessions_as_dropped() writes 'dropped_off' onto exactly the rows
		 * this matches, which is what lets analytics count the predicate instead of
		 * waiting for the hourly sweep to record its verdict.
		 *
		 * The cutoff is bound in here and the returned string carries no placeholder of
		 * its own, so a caller can interpolate it into its own query without disturbing
		 * that query's argument list.
		 *
		 * @param string $alias  Alias of surveyx_sessions in the caller's query, '' for none.
		 * @param string $cutoff Cutoff to reuse; defaults to get_stale_cutoff(). Pass one
		 *                       when two statements must measure the same instant.
		 * @return string SQL boolean expression.
		 */
		public static function stale_active_sql( $alias = '', $cutoff = '' ) {
			global $wpdb;

			$column = self::column_prefix( $alias );

			// $column is an alias chosen by our own callers and reduced to word
			// characters by column_prefix(); the only value in the expression is bound.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->prepare(
				"({$column}session_status = 'active' AND {$column}last_activity_at < %s)",
				'' === $cutoff ? self::get_stale_cutoff() : $cutoff
			);
		}

		/**
		 * SQL matching a drop-off, whether or not the sweep has run yet.
		 *
		 * Either the sweep has already written 'dropped_off', or the session still
		 * matches the predicate the sweep writes it from. The two branches cannot both
		 * hold for one row - a session is never 'dropped_off' and 'active' at once - so
		 * a SUM() over this expression counts each session exactly once. A session that
		 * dropped off and was then resumed is 'active' with a fresh last_activity_at and
		 * matches neither branch.
		 *
		 * @param string $alias Alias of surveyx_sessions in the caller's query, '' for none.
		 * @return string SQL boolean expression.
		 */
		public static function dropped_off_sql( $alias = '' ) {
			$column = self::column_prefix( $alias );

			return "({$column}session_status = 'dropped_off' OR " . self::stale_active_sql( $alias ) . ')';
		}

		/**
		 * Turns a table alias into a column prefix for the SQL helpers above.
		 *
		 * @param string $alias Table alias, or '' for an unaliased query.
		 * @return string The alias followed by a dot, or '' when there is none.
		 */
		private static function column_prefix( $alias ) {
			$alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );

			return '' === $alias ? '' : $alias . '.';
		}

		/**
		 * Creates a new session when user starts a survey.
		 * Respondent information is now stored in surveyx_respondents table.
		 *
		 * @param int    $survey_id      The ID of the survey.
		 * @param string $respondent_id  Unique identifier for the respondent.
		 * @param array  $question_order Array of question IDs in order.
		 * @param array  $request_data   IP address, user agent, location data.
		 *
		 * @return int|false Session ID on success, false on failure.
		 */
		public static function create_session( $survey_id, $respondent_id, $question_order, $request_data = [] ) {
			global $wpdb;

			$now             = self::get_utc_now();
			$total_questions = count( $question_order );

			// DATA MINIMISATION (free edition): ip_address, user_agent and location are
			// deliberately NOT stored. The columns exist because this table is shared
			// with Pro, which reads them; free has no SELECT against surveyx_respondents
			// anywhere, so storing a respondent's IP, browser string and location here
			// would be collecting personal data with no code path that can ever use it.
			// Pro passes the real values from its own copy of this method. If a future
			// free feature genuinely needs one of these, add the reader first.
			self::upsert_respondent_data(
				$respondent_id,
				$request_data['email'] ?? '',
				'',
				'',
				'',
				$now
			);

			$data = [
				'survey_id'           => $survey_id,
				'respondent_id'       => $respondent_id,
				'session_status'      => 'viewed',
				'current_question_id' => 0,
				'question_order'      => wp_json_encode( $question_order ),
				'total_questions'     => $total_questions,
				'progress_percentage' => 0.00,
				'started_at'          => $now,
				'last_activity_at'    => $now,
				'time_spent'          => 0,
			];

			$format = [ '%d', '%s', '%s', '%d', '%s', '%d', '%f', '%s', '%s', '%d' ];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->replace(
				$wpdb->prefix . 'surveyx_sessions',
				$data,
				$format
			);

			if ( false === $result ) {
				return false;
			}

			return $wpdb->insert_id;
		}

		/**
		 * Activates a session when user starts interacting with survey.
		 * Handles: viewed → active, dropped_off → active, completed+restart_pending → active.
		 * Deletes old votes when restart_pending = 1.
		 *
		 * @param int    $survey_id     The ID of the survey.
		 * @param string $respondent_id Unique identifier for the respondent.
		 *
		 * @return bool True on success, false on failure.
		 */
		public static function activate_session( $survey_id, $respondent_id ) {
			global $wpdb;

			$session = self::get_active_session( $survey_id, $respondent_id );
			if ( ! $session ) {
				return false;
			}

			$status = $session->session_status;

			if ( 'active' === $status ) {
				return false;
			}

			if ( 'completed' === $status && ! $session->restart_pending ) {
				return false;
			}

			if ( $session->restart_pending ) {
				SurveyX_Db::delete_all_responses_by_session( $session->id, $respondent_id );

				// A restart drops a whole session's answers in one step, so the tally /vote-results
				// serves is stale the moment this returns. Nothing else invalidates it on a
				// respondent path, so without this the respondent could reopen the results drawer
				// and still be shown the votes they just discarded.
				SurveyX_Db::flush_vote_cache( $survey_id );
			}

			$now = self::get_utc_now();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->update(
				$wpdb->prefix . 'surveyx_sessions',
				[
					'session_status'      => 'active',
					'restart_pending'     => 0,
					'current_question_id' => 0,
					'progress_percentage' => 0.00,
					'last_activity_at'    => $now,
				],
				[
					'survey_id'     => $survey_id,
					'respondent_id' => $respondent_id,
				],
				[ '%s', '%d', '%d', '%f', '%s' ],
				[ '%d', '%s' ]
			);
		}

		/**
		 * Reset an expired session to 'viewed' status for fresh start.
		 * Deletes old responses and resets session in minimal queries.
		 *
		 * @param int    $session_id    Session ID.
		 * @param string $respondent_id Respondent UUID.
		 * @return bool True on success, false on failure.
		 */
		public static function reset_expired_session( $session_id, $respondent_id ) {
			global $wpdb;

			SurveyX_Db::delete_all_responses_by_session( $session_id, $respondent_id );

			$now = self::get_utc_now();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->update(
				$wpdb->prefix . 'surveyx_sessions',
				[
					'session_status'      => 'viewed',
					'current_question_id' => 0,
					'progress_percentage' => 0,
					'completed_at'        => null,
					'restart_pending'     => 0,
					'last_activity_at'    => $now,
				],
				[
					'id' => $session_id,
				],
				[ '%s', '%d', '%d', '%s', '%d', '%s' ],
				[ '%d' ]
			);
		}

		/**
		 * Upserts respondent data into surveyx_respondents table.
		 * The respondents.total_surveys column is NOT maintained here, and is not
		 * maintained anywhere else either - the INSERT below writes a literal 0 and
		 * the ON DUPLICATE KEY UPDATE clause never mentions it. Anything that needs
		 * that number derives it from surveyx_sessions instead (see Pro's
		 * email-export handler). Do not add an increment without also backfilling:
		 * every row customers already hold reads 0.
		 *
		 * @param string $respondent_id Respondent UUID.
		 * @param string $email         Email address.
		 * @param string $ip_address    IP address.
		 * @param string $user_agent    User agent string.
		 * @param string $location      Location data.
		 * @param string $activity_time Time of activity (UTC).
		 * @param string $user_name     Optional name.
		 * @param string $phone         Optional phone.
		 * @param string $company       Optional company.
		 */
		protected static function upsert_respondent_data(
			$respondent_id,
			$email = '',
			$ip_address = '',
			$user_agent = '',
			$location = '',
			$activity_time = '',
			$user_name = '',
			$phone = '',
			$company = ''
		) {
			global $wpdb;

			if ( empty( $respondent_id ) ) {
				return;
			}

			$activity_time = $activity_time ?: self::get_utc_now();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}surveyx_respondents
					(respondent_id, user_name, email, phone, company, ip_address, user_agent, location, first_seen_at, last_seen_at, total_surveys)
					VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 0)
					ON DUPLICATE KEY UPDATE
						user_name = IF(VALUES(user_name) != '', VALUES(user_name), user_name),
						email = IF(VALUES(email) != '', VALUES(email), email),
						phone = IF(VALUES(phone) != '', VALUES(phone), phone),
						company = IF(VALUES(company) != '', VALUES(company), company),
						ip_address = IF(VALUES(ip_address) != '', VALUES(ip_address), ip_address),
						user_agent = IF(VALUES(user_agent) != '', VALUES(user_agent), user_agent),
						location = IF(VALUES(location) != '', VALUES(location), location),
						last_seen_at = VALUES(last_seen_at)",
					$respondent_id,
					$user_name,
					$email,
					$phone,
					$company,
					$ip_address,
					$user_agent,
					$location,
					$activity_time,
					$activity_time
				)
			);
		}

		/**
		 * Updates respondent contact information from contact_info question type.
		 *
		 * @param string $respondent_id Respondent UUID.
		 * @param array  $contact_data  Array containing name, email, phone, company.
		 */
		public static function update_contact_info( $respondent_id, $contact_data ) {
			if ( empty( $respondent_id ) || empty( $contact_data ) ) {
				return;
			}

			$user_name = sanitize_text_field( $contact_data['name'] ?? '' );
			$email     = sanitize_email( $contact_data['email'] ?? '' );
			$phone     = sanitize_text_field( $contact_data['phone'] ?? '' );
			$company   = sanitize_text_field( $contact_data['company'] ?? '' );

			self::upsert_respondent_data(
				$respondent_id,
				$email,
				'',
				'',
				'',
				self::get_utc_now(),
				$user_name,
				$phone,
				$company
			);

			if ( ! empty( $email ) ) {
				do_action( 'surveyx_respondent_email_added', $respondent_id );
			}
		}

		/**
		 * Updates session progress when user answers a question.
		 * Activates session if needed (viewed, dropped_off, or restart_pending).
		 *
		 * @param int    $survey_id      The ID of the survey.
		 * @param string $respondent_id  Unique identifier.
		 * @param int    $question_id    The current question ID.
		 * @param int    $answered_count Number of questions answered so far.
		 *
		 * @return bool True on success, false on failure.
		 */
		public static function update_session_progress( $survey_id, $respondent_id, $question_id, $answered_count, $session = null ) {
			global $wpdb;

			// Reuse the caller-supplied session; only fall back to a fetch when absent.
			if ( null === $session ) {
				$session = self::get_active_session( $survey_id, $respondent_id );
			}
			if ( ! $session ) {
				return false;
			}

			if ( 'active' !== $session->session_status ) {
				return false;
			}

			$now      = self::get_utc_now();
			$total    = (int) $session->total_questions;
			$progress = $total > 0 ? ( $answered_count / $total ) * 100 : 0;

			// time_spent is only consumed at completion (complete_session and
			// mark_stale_sessions_as_dropped both recompute it), so it is not
			// refreshed on every answer to avoid a MIN/MAX scan per /progress.
			$update_data = [
				'current_question_id' => $question_id,
				'progress_percentage' => $progress,
				'last_activity_at'    => $now,
			];

			$format = [ '%d', '%f', '%s' ];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->update(
				$wpdb->prefix . 'surveyx_sessions',
				$update_data,
				[
					'survey_id'     => $survey_id,
					'respondent_id' => $respondent_id,
				],
				$format,
				[ '%d', '%s' ]
			);
		}

		/**
		 * Marks a session as completed.
		 *
		 * The status read below is a check-then-act with a database round-trip
		 * (get_session_time_spent) sitting in the gap, so two concurrent completions
		 * - a double-submitted end screen, a retried POST, a second tab - both read
		 * 'active', both pass the check and both write. The UPDATE therefore repeats
		 * the status test as a predicate (session_status <> 'completed') so MySQL,
		 * not the PHP read, decides the single winner: the loser matches no rows and
		 * cannot overwrite completed_at / time_spent with its own later values. That
		 * is also what makes the endpoint safe for the client to retry.
		 *
		 * @param int    $survey_id     The ID of the survey.
		 * @param string $respondent_id Unique identifier.
		 *
		 * @return bool True when the session is completed (now or already), false on failure.
		 */
		public static function complete_session( $survey_id, $respondent_id ) {
			global $wpdb;

			$session = self::get_active_session( $survey_id, $respondent_id );
			if ( ! $session ) {
				return false;
			}

			if ( 'completed' === $session->session_status ) {
				return true;
			}

			$now        = self::get_utc_now();
			$time_spent = SurveyX_Db::get_session_time_spent( $session->id, $respondent_id );
			$table      = $wpdb->prefix . 'surveyx_sessions';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
						SET session_status = 'completed',
							progress_percentage = 100.00,
							completed_at = %s,
							last_activity_at = %s,
							time_spent = %d
						WHERE survey_id = %d
						AND respondent_id = %s
						AND session_status <> 'completed'",
					$now,
					$now,
					$time_spent,
					$survey_id,
					$respondent_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// The session is completed either way - by this call, or by the racer that
			// matched the row first and left this UPDATE with zero rows affected.
			return false !== $result;
		}

		/**
		 * Retrieves an active session for a respondent.
		 *
		 * @param int    $survey_id     The ID of the survey.
		 * @param string $respondent_id Unique identifier.
		 *
		 * @return object|null Session object or null if not found.
		 */
		public static function get_active_session( $survey_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, survey_id, respondent_id, session_status, restart_pending,
						current_question_id, question_order, total_questions, progress_percentage,
						started_at, last_activity_at, completed_at, time_spent
					FROM {$wpdb->prefix}surveyx_sessions
					WHERE survey_id = %d
					AND respondent_id = %s
					LIMIT 1",
					$survey_id,
					$respondent_id
				)
			);
		}

		/**
		 * Retrieves only the id + status of a session.
		 *
		 * Slim variant of get_active_session() for hot analytics paths
		 * (e.g. /question-seen) that need nothing beyond identity and status.
		 * restart_pending travels with the status because a 'completed' session
		 * that is pending a restart is still open to writes - /progress uses the
		 * same pair as its gate, and the two must not disagree.
		 *
		 * @param int    $survey_id     The ID of the survey.
		 * @param string $respondent_id Unique identifier.
		 *
		 * @return object|null Object with id + session_status + restart_pending, or null if not found.
		 */
		public static function get_session_id_status( $survey_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, session_status, restart_pending
					FROM {$wpdb->prefix}surveyx_sessions
					WHERE survey_id = %d
					AND respondent_id = %s
					LIMIT 1",
					$survey_id,
					$respondent_id
				)
			);
		}

		/**
		 * Marks stale sessions as dropped-off and sets restart_pending = 1.
		 * Called by cron job. Uses single UPDATE query with subquery for performance.
		 *
		 * @return int Number of sessions marked as dropped-off.
		 */
		public static function mark_stale_sessions_as_dropped() {
			global $wpdb;

			// One cutoff shared by both statements below, so the UPDATE cannot mark a
			// session the SELECT did not capture for cache invalidation. Both fragments
			// come from the expression analytics counts drop-offs with, so this sweep can
			// only ever write down what those figures already report.
			$cutoff        = self::get_stale_cutoff();
			$stale         = self::stale_active_sql( '', $cutoff );
			$stale_aliased = self::stale_active_sql( 'ss', $cutoff );

			// Surveys this sweep is about to change. Captured before the UPDATE, because
			// afterwards the rows no longer match session_status = 'active'. Served by
			// idx_status_activity (session_status, last_activity_at).
			// $stale carries its own bound cutoff and no remaining placeholder.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$swept_survey_ids = $wpdb->get_col(
				"SELECT DISTINCT survey_id
				FROM {$wpdb->prefix}surveyx_sessions
				WHERE {$stale}"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// $stale_aliased carries its own bound cutoff and no remaining placeholder.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $wpdb->query(
				"UPDATE {$wpdb->prefix}surveyx_sessions ss
				SET
					ss.session_status = 'dropped_off',
					ss.restart_pending = 1,
					ss.time_spent = GREATEST(
						COALESCE(
							(SELECT TIMESTAMPDIFF(SECOND, MIN(r.viewed_at), MAX(r.answered_at))
							 FROM {$wpdb->prefix}surveyx_responses r
							 WHERE r.session_id = ss.id AND r.response_status = 'answered'),
							0
						),
						0
					)
				WHERE {$stale_aliased}"
			);

			// The drop-off COUNTS do not move across this sweep — total_dropoffs and the per-question
			// breakdown are both derived from the same predicate the UPDATE above matches on, so the
			// divisor and the numerators change together and the breakdown cannot outrun the total.
			// What does move is time_spent, which the UPDATE recomputes and which feeds the cached
			// average_time_seconds, and the response rows the prune loop below deletes.
			//
			// Invalidated TWICE on purpose, because the prune loop below is slow: here, so the
			// recomputed time_spent is visible to the next read rather than waiting out the loop; and
			// again after it, because a read landing mid-prune would re-arm the cache from responses
			// the loop has not deleted yet, freezing inflated vote counts in for the length of the
			// cache. Both are a single DELETE against one option row.
			foreach ( (array) $swept_survey_ids as $swept_survey_id ) {
				SurveyX_Db::flush_analytics_cache( (int) $swept_survey_id );
			}

			return (int) $count;
		}
	}
}
