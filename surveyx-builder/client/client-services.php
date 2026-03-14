<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Client_Services', false ) ) {
	/**
	 * Handles client-side REST API endpoints for surveys.
	 * New architecture: init creates session, progress updates responses.
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

			// Load handler classes
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
		 * Initializes survey(s) - loads data + creates session + stores question order.
		 * Delegates to SurveyX_Init_Handler.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response with survey data.
		 */
		public function init_survey( WP_REST_Request $request ) {
			return SurveyX_Init_Handler::handle( $request );
		}

		/**
		 * Updates progress - creates response + updates session.
		 * Delegates to SurveyX_Progress_Handler.
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

			// Verify survey is published before returning votes
			$survey = SurveyX_Db::get_published_survey_by_id( $survey_id );
			if ( empty( $survey ) ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Survey not found or not published.', 'surveyx-builder' ) ],
					404
				);
			}

			// Check if view votes in results is enabled for this survey
			$settings           = $survey->settings ?? [];
			$view_votes_enabled = $settings['view_votes_in_results'] ?? false;
			if ( ! $view_votes_enabled ) {
				return new WP_REST_Response(
					[ 'message' => esc_html__( 'Vote results are not enabled for this survey.', 'surveyx-builder' ) ],
					403
				);
			}

			$result = SurveyX_Db::get_answer_total_vote( $survey_id );

			if ( ! $result ) {
				return new WP_REST_Response(
					[
						'message' => esc_html__( 'Failed to get total votes', 'surveyx-builder' ),
					],
					500
				);
			}

			return new WP_REST_Response( [ 'data' => $result ], 200 );
		}

		/**
		 * Tracks when question is viewed (seen).
		 * Delegates to SurveyX_Question_Seen_Handler.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response indicating success or failure.
		 */
		public function track_question_seen( WP_REST_Request $request ) {
			return SurveyX_Question_Seen_Handler::handle( $request );
		}

		/**
		 * Activates a 'viewed' session when user starts interacting.
		 * Changes session_status from 'viewed' to 'active'.
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

			$result = SurveyX_Session_Manager::activate_session( $survey_id, $respondent_id );

			if ( ! $result ) {
				// Session may already be active or doesn't exist - not an error
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
		 * Completes a session when user finishes survey.
		 * Delegates to SurveyX_Complete_Session_Handler.
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
