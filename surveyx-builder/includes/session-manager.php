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
	 * IMPORTANT: All datetime values are stored in UTC using gmdate('Y-m-d H:i:s')
	 * to ensure consistent time calculations across different server timezones.
	 */
	class SurveyX_Session_Manager {

		/**
		 * Session timeout in minutes (10 minutes = drop-off)
		 */
		const SESSION_TIMEOUT_MINUTES = 10;

		/**
		 * Gets current UTC datetime string for database storage.
		 * Wrapper for centralized date helper function.
		 *
		 * @return string MySQL datetime format in UTC.
		 */
		protected static function get_utc_now() {
			return surveyx_get_utc_now();
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

			self::upsert_respondent_data(
				$respondent_id,
				$request_data['email'] ?? '',
				$request_data['ip_address'] ?? '',
				$request_data['user_agent'] ?? '',
				$request_data['location'] ?? '',
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
		 * Does NOT increment total_surveys (that happens when session completes).
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
		public static function update_session_progress( $survey_id, $respondent_id, $question_id, $answered_count ) {
			global $wpdb;

			$session = self::get_active_session( $survey_id, $respondent_id );
			if ( ! $session ) {
				return false;
			}

			if ( 'active' !== $session->session_status ) {
				return false;
			}

			$now        = self::get_utc_now();
			$total      = (int) $session->total_questions;
			$progress   = $total > 0 ? ( $answered_count / $total ) * 100 : 0;
			$time_spent = SurveyX_Db::get_session_time_spent( $session->id, $respondent_id );

			$update_data = [
				'current_question_id' => $question_id,
				'progress_percentage' => $progress,
				'last_activity_at'    => $now,
				'time_spent'          => $time_spent,
			];

			$format = [ '%d', '%f', '%s', '%d' ];

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
		 * @param int    $survey_id     The ID of the survey.
		 * @param string $respondent_id Unique identifier.
		 *
		 * @return bool True on success, false on failure.
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

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$wpdb->prefix . 'surveyx_sessions',
				[
					'session_status'      => 'completed',
					'progress_percentage' => 100.00,
					'completed_at'        => $now,
					'last_activity_at'    => $now,
					'time_spent'          => $time_spent,
				],
				[
					'survey_id'     => $survey_id,
					'respondent_id' => $respondent_id,
				],
				[ '%s', '%f', '%s', '%s', '%d' ],
				[ '%d', '%s' ]
			);

			return false !== $result;
		}

		/**
		 * Reset session progress to 0 (used when starting editing mode after deleting all responses).
		 *
		 * @param int    $survey_id     Survey ID.
		 * @param string $respondent_id Respondent UUID.
		 * @return bool True on success, false on failure.
		 */
		public static function reset_session_progress( $survey_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->update(
				$wpdb->prefix . 'surveyx_sessions',
				[
					'progress_percentage' => 0.00,
					'last_activity_at'    => self::get_utc_now(),
				],
				[
					'survey_id'     => $survey_id,
					'respondent_id' => $respondent_id,
				],
				[ '%f', '%s' ],
				[ '%d', '%s' ]
			);
		}

		/**
		 * Updates last activity timestamp to prevent timeout.
		 * Activates session if needed (viewed or restart_pending).
		 *
		 * @param int    $survey_id     The ID of the survey.
		 * @param string $respondent_id Unique identifier.
		 *
		 * @return bool True on success, false on failure.
		 */
		public static function update_activity( $survey_id, $respondent_id ) {
			global $wpdb;

			$session = self::get_active_session( $survey_id, $respondent_id );
			if ( ! $session ) {
				return false;
			}

			if ( 'active' !== $session->session_status ) {
				return false;
			}

			$update_data = [
				'last_activity_at' => self::get_utc_now(),
			];

			$format = [ '%s' ];

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
		 * Checks if a session exists (any status).
		 *
		 * @param int    $survey_id     The ID of the survey.
		 * @param string $respondent_id Unique identifier.
		 *
		 * @return bool True if session exists, false otherwise.
		 */
		public static function session_exists( $survey_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}surveyx_sessions
					WHERE survey_id = %d
					AND respondent_id = %s",
					$survey_id,
					$respondent_id
				)
			);

			return (int) $count > 0;
		}

		/**
		 * Deletes a session and all associated responses.
		 * Used when user wants to retry a survey.
		 *
		 * @param int    $survey_id     The ID of the survey.
		 * @param string $respondent_id Unique identifier.
		 *
		 * @return bool True on success, false on failure.
		 */
		public static function delete_session( $survey_id, $respondent_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$wpdb->prefix . 'surveyx_responses',
				[
					'survey_id'     => $survey_id,
					'respondent_id' => $respondent_id,
				],
				[ '%d', '%s' ]
			);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->delete(
				$wpdb->prefix . 'surveyx_sessions',
				[
					'survey_id'     => $survey_id,
					'respondent_id' => $respondent_id,
				],
				[ '%d', '%s' ]
			);
		}

		/**
		 * Retrieves all stale sessions (inactive for more than SESSION_TIMEOUT_MINUTES).
		 * Uses PHP gmdate() for consistent UTC time comparison.
		 *
		 * @return array Array of session objects.
		 */
		public static function get_stale_sessions() {
			global $wpdb;

			$timeout_minutes = self::SESSION_TIMEOUT_MINUTES;

			$utc_now = surveyx_get_utc_now();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, respondent_id
					FROM {$wpdb->prefix}surveyx_sessions
					WHERE session_status = 'active'
					AND last_activity_at < DATE_SUB(%s, INTERVAL %d MINUTE)",
					$utc_now,
					$timeout_minutes
				)
			);
		}

		/**
		 * Marks stale sessions as dropped-off and sets restart_pending = 1.
		 * Called by cron job.
		 *
		 * @return int Number of sessions marked as dropped-off.
		 */
		public static function mark_stale_sessions_as_dropped() {
			global $wpdb;

			$stale_sessions = self::get_stale_sessions();
			$count          = 0;

			foreach ( $stale_sessions as $session ) {
				$time_spent = SurveyX_Db::get_session_time_spent( $session->id, $session->respondent_id );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update(
					$wpdb->prefix . 'surveyx_sessions',
					[
						'session_status'  => 'dropped_off',
						'restart_pending' => 1,
						'time_spent'      => $time_spent,
					],
					[ 'id' => $session->id ],
					[ '%s', '%d', '%d' ],
					[ '%d' ]
				);

				if ( false !== $result ) {
					++$count;
				}
			}

			return $count;
		}
	}
}
