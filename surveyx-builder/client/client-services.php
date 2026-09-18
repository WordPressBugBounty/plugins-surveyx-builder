<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Client_Services', false ) ) {
	/**
	 * Handles client-side REST API endpoints for surveys: /init creates the session,
	 * /progress then updates responses against it.
	 *
	 * @since 1.0.0
	 */
	class SurveyX_Client_Services {

		private static $instance;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		protected function __construct() {
			self::$instance = $this;

			$this->load_handlers();
		}

		/**
		 * Load handler classes.
		 */
		private function load_handlers() {
			require_once SURVEYX_PATH . 'client/handlers/validation.php';
			require_once SURVEYX_PATH . 'client/handlers/init.php';
			require_once SURVEYX_PATH . 'client/handlers/progress.php';
			require_once SURVEYX_PATH . 'client/handlers/question-seen.php';
		}

		/**
		 * Initializes survey(s) - loads data, creates the session, stores question order.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response with survey data.
		 */
		public function init_survey( WP_REST_Request $request ) {
			return SurveyX_Init_Handler::handle( $request );
		}

		/**
		 * Updates progress - creates the response row and updates the session.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response indicating success or failure.
		 */
		public function update_progress( WP_REST_Request $request ) {
			return SurveyX_Progress_Handler::handle( $request );
		}

		/**
		 * Gets total votes for answers in a survey.
		 *
		 * No votes yet - or a tally just reset via Allow Revote on Update - is a valid
		 * empty state, not an error: `data` is simply an empty array. `generated_at` is
		 * the raw UTC epoch the tally was computed at; `updated_text` renders that moment
		 * per RESPONSE rather than per cache build, so the line stays accurate as a
		 * cached tally ages. human_time_diff() defaults its second argument to time(),
		 * also UTC, so the pair agrees on any site timezone.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response with vote totals.
		 */
		public function get_answer_total_votes( WP_REST_Request $request ) {
			$body = SurveyX_Validation_Helper::validate_json_body( $request );
			if ( is_wp_error( $body ) ) {
				return SurveyX_Validation_Helper::error_response( $body );
			}

			$survey_id = SurveyX_Validation_Helper::validate_survey_id( $body );
			if ( is_wp_error( $survey_id ) ) {
				return SurveyX_Validation_Helper::error_response( $survey_id );
			}

			// Rate limit, IP-only: this route takes no respondent_id and the aggregate it
			// returns is public, so the IP bucket is the whole control. After survey_id
			// validation so junk writes no transient.
			$ip_rate_check = SurveyX_Validation_Helper::check_rate_limit( 'vote_results_ip', '', SurveyX_Validation_Helper::RATE_VOTE_RESULTS_IP, SurveyX_Validation_Helper::RATE_WINDOW );
			if ( is_wp_error( $ip_rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $ip_rate_check );
			}

			$survey = SurveyX_Db::get_published_survey_by_id( $survey_id );
			if ( empty( $survey ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey not found or not published.', 'surveyx-builder' ) ],
					404
				);
			}

			// Also require survey_type 'vote': the flag can be stale after a type change.
			$settings           = $survey->settings ?? [];
			$view_votes_enabled = $settings['view_votes_in_results'] ?? false;
			$is_vote_survey     = ( $survey->survey_type ?? '' ) === 'vote';
			if ( ! $view_votes_enabled || ! $is_vote_survey ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Vote results are not enabled for this survey.', 'surveyx-builder' ) ],
					403
				);
			}

			$votes = SurveyX_Db::get_answer_total_vote( $survey_id );

			return new WP_REST_Response(
				[
					'data'         => $votes['data'],
					'generated_at' => $votes['generated_at'],
					'updated_text' => sprintf(
						/* translators: %s: human-readable age of the figures, e.g. "3 hours". */
						esc_html__( 'Updated %s ago', 'surveyx-builder' ),
						human_time_diff( $votes['generated_at'] )
					),
				],
				200
			);
		}

		/**
		 * Tracks when a question is viewed (seen).
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response indicating success or failure.
		 */
		public function track_question_seen( WP_REST_Request $request ) {
			return SurveyX_Question_Seen_Handler::handle( $request );
		}

		/**
		 * Activates a 'viewed' session when the respondent starts interacting, moving
		 * session_status from 'viewed' to 'active'.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response indicating success or failure.
		 */
		public function activate_session( WP_REST_Request $request ) {
			$body = SurveyX_Validation_Helper::validate_json_body( $request );
			if ( is_wp_error( $body ) ) {
				return SurveyX_Validation_Helper::error_response( $body );
			}

			$survey_id = SurveyX_Validation_Helper::validate_survey_id( $body );
			if ( is_wp_error( $survey_id ) ) {
				return SurveyX_Validation_Helper::error_response( $survey_id );
			}

			$respondent_id = SurveyX_Validation_Helper::validate_respondent_id( $body );
			if ( is_wp_error( $respondent_id ) ) {
				return SurveyX_Validation_Helper::error_response( $respondent_id );
			}

			// Rate limit, IP-only: respondent_id is self-issued, so a per-respondent
			// bucket would reset on every rotated UUID and protect nothing. After the
			// cheap validation above so junk writes no transient.
			$ip_rate_check = SurveyX_Validation_Helper::check_rate_limit( 'activate_session_ip', '', SurveyX_Validation_Helper::RATE_ACTIVATE_SESSION_IP, SurveyX_Validation_Helper::RATE_WINDOW );
			if ( is_wp_error( $ip_rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $ip_rate_check );
			}

			$result = SurveyX_Session_Manager::activate_session( $survey_id, $respondent_id );

			if ( ! $result ) {
				// Already active, or no such session - neither is an error.
				return new WP_REST_Response(
					[
						'ok'        => true,
						'activated' => false,
					],
					200
				);
			}

			return new WP_REST_Response(
				[
					'ok'        => true,
					'activated' => true,
				],
				200
			);
		}

		/**
		 * Completes a session when the respondent finishes the survey.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response indicating success or failure.
		 */
		public function complete_session( WP_REST_Request $request ) {
			require_once SURVEYX_PATH . 'client/handlers/complete-session.php';
			return SurveyX_Complete_Session_Handler::handle( $request );
		}
	}
}
