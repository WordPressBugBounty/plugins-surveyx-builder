<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_API_Handler', false ) ) {
	class SurveyX_API_Handler {

		private static $instance;

		public const ROUTE_NAMESPACE = SURVEYX_REST_NAMESPACE;
		protected $api;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		protected function __construct() {
			self::$instance = $this;
			$this->api      = SurveyX_Client_Services::get_instance();

			add_action( 'rest_api_init', [ $this, 'register_rest_routes' ], 11 );
		}

		/**
		 * Register REST API routes for survey frontend.
		 *
		 * SECURITY NOTE: These endpoints use '__return_true' for permission_callback
		 * because they are intentionally public. Surveys are embedded on public pages
		 * via shortcode [surveyx id="X"] and must be accessible to anonymous visitors.
		 *
		 * Authorization is enforced through:
		 * 1. Survey must have 'active' status (get_published_survey_by_id)
		 * 2. Session must exist and be valid (SurveyX_Session_Manager::get_active_session)
		 * 3. All IDs are sanitized (absint for survey_id, UUID validation for respondent_id)
		 *
		 * @since 1.0.0
		 */
		public function register_rest_routes() {
			// POST /init - Intentionally public for shortcode embeds
			// Uses POST to prevent caching (personalized response with user's votes)
			// Authorization: Survey must be 'active' status
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/init',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'init_survey' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'survey_id'     => [
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
						'respondent_id' => [
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				]
			);

			// POST /progress - Intentionally public for anonymous responses
			// Authorization: Requires valid session from /init
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/progress',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'update_progress' ],
					'permission_callback' => '__return_true',
				]
			);

			// POST /vote-results - Intentionally public for displaying poll results on frontend
			// Authorization enforced in callback:
			// 1. Survey must be published ('active' status) - returns 404 if not
			// 2. Survey must have 'view_votes_in_results' setting enabled - returns 403 if not
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/vote-results',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'get_answer_total_votes' ],
					'permission_callback' => '__return_true',
				]
			);

			// POST /question-seen - Intentionally public for analytics tracking
			// Authorization: Requires valid session
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/question-seen',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'track_question_seen' ],
					'permission_callback' => '__return_true',
				]
			);

			// POST /activate-session - Intentionally public for session management
			// Authorization: Requires valid survey_id
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/activate-session',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'activate_session' ],
					'permission_callback' => '__return_true',
				]
			);

			// POST /complete-session - Intentionally public for session completion
			// Authorization: Requires valid session
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/complete-session',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'complete_session' ],
					'permission_callback' => '__return_true',
				]
			);
		}
	}
}

/** load */
SurveyX_API_Handler::get_instance();
