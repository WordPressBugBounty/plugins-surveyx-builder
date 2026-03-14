<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Init_Handler', false ) ) {
	/**
	 * Handles the /init endpoint - loads single survey data and creates session.
	 * Each request processes one survey to optimize performance.
	 *
	 * @since 1.0.0
	 */
	class SurveyX_Init_Handler {

		/**
		 * Allowed HTML tags for wp_kses sanitization.
		 *
		 * @var array
		 */
		private static $allowed_html = [
			'a'      => [
				'href'   => [],
				'title'  => [],
				'rel'    => [],
				'target' => [],
			],
			'p'      => [],
			'strong' => [],
			'em'     => [],
			'u'      => [],
			'ul'     => [],
			'ol'     => [],
			'li'     => [],
			'span'   => [
				'class'           => [],
				'data-id'         => [],
				'data-value'      => [],
				'data-denotation' => [],
			],
			'br'     => [],
			'div'    => [
				'class' => [],
			],
		];

		/**
		 * Main handler for init_survey endpoint.
		 * Processes a single survey request.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return WP_REST_Response Response with single survey data or 404 error.
		 */
		public static function handle( WP_REST_Request $request ) {
			// Parse request parameters
			$params = static::parse_request_params( $request );

			// Process single survey
			$survey_result = static::init(
				$params['survey_id'],
				$params['respondent_id']
			);

			// If survey not found, return error
			if ( null === $survey_result ) {
				return new WP_REST_Response(
					[
						'message' => esc_html__( 'Survey not found', 'surveyx-builder' ),
					],
					404
				);
			}

			// Return survey data directly without wrapper
			return new WP_REST_Response( $survey_result, 200 );
		}

		/**
		 * Parse and validate request parameters.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return array Parsed parameters.
		 */
		protected static function parse_request_params( WP_REST_Request $request ) {
			$survey_id = absint( $request->get_param( 'survey_id' ) );

			$respondent_id_raw = $request->get_param( 'respondent_id' );
			$respondent_id     = $respondent_id_raw ? SurveyX_Validation_Helper::sanitize_uuid( $respondent_id_raw ) : '';

			return [
				'survey_id'     => $survey_id,
				'respondent_id' => $respondent_id,
			];
		}

		/**
		 * Process a single survey and build its response data.
		 *
		 * @param int    $survey_id     Survey ID.
		 * @param string $respondent_id Respondent UUID.
		 * @return array|null Survey data array or null if survey not found.
		 */
		protected static function init( $survey_id, $respondent_id ) {

			// Combined query
			$data = SurveyX_Db::get_survey_init_data( $survey_id, $respondent_id );

			if ( ! $data ) {
				return null;
			}

			$survey = $data['survey'];

			// Check authentication requirements
			$require_logged_in = rest_sanitize_boolean( $survey->settings['require_logged_in'] ?? false );

			// Early return if authentication requirements are not met
			if ( $require_logged_in && ! is_user_logged_in() ) {
				return null; // User must be logged in but isn't
			}

			if ( ! $require_logged_in && empty( $respondent_id ) ) {
				return null; // Anonymous access requires valid respondent_id
			}

			// Get session
			$session = SurveyX_Session_Manager::get_active_session( $survey_id, $respondent_id );

			// Handle expired session - reset to fresh state
			if ( $session && 'expired' === $session->session_status ) {
				SurveyX_Session_Manager::reset_expired_session( $session->id, $respondent_id );
				$data['responses']            = [];
				$session->session_status      = 'viewed';
				$session->current_question_id = 0;
				$session->progress_percentage = 0;
				$session->restart_pending     = 0;
			}

			// Get voted data based on respondent_id
			$voted_data = static::get_voted_data( $data, $respondent_id );

			// Build frontend settings
			$frontend_settings = static::build_frontend_settings( $survey );

			$session_status = $session ? $session->session_status : null;

			// Prepare response data
			$response_data = [
				'settings'            => $frontend_settings,
				'questions'           => $data['questions'] ?? [],
				'answers'             => $data['answers'] ?? [],
				'votes'               => $voted_data,
				'session_status'      => $session_status,
				'restart_pending'     => $session ? (bool) $session->restart_pending : false,
				'current_question_id' => $session ? (int) $session->current_question_id : 0,
			];

			// Create session if it doesn't exist
			if ( ! $session && ! empty( $data['questions'] ) ) {
				$question_order = array_column( $data['questions'], 'id' );

				if ( ! empty( $question_order ) ) {
					static::create_session( $survey_id, $respondent_id, $question_order );
				}
			}

			return $response_data;
		}

		/**
		 * Get voted data for current respondent.
		 * Uses only respondent_id to filter responses, regardless of authentication status.
		 * Removes sensitive fields before returning to client.
		 *
		 * @param array  $survey_data   Survey data containing responses.
		 * @param string $respondent_id Respondent UUID.
		 * @return array Filtered voted data with sensitive fields removed.
		 */
		protected static function get_voted_data( $survey_data, $respondent_id ) {
			if ( empty( $respondent_id ) ) {
				return [];
			}

			$filtered = array_values(
				array_filter(
					$survey_data['responses'],
					function ( $data ) use ( $respondent_id ) {
						return $data->respondent_id === $respondent_id;
					}
				)
			);

			// Remove only respondent_id (sensitive), keep response_content for restoring answers
			foreach ( $filtered as $vote ) {
				unset( $vote->respondent_id );
			}

			return $filtered;
		}

		/**
		 * Build frontend settings object with only necessary fields.
		 *
		 * @param object $survey Survey object.
		 * @return array Frontend settings.
		 */
		protected static function build_frontend_settings( $survey ) {
			// Get global settings for features like animation
			$global_settings = get_option( 'surveyx_settings', [] );

			$settings = $survey->settings;
			$content  = $survey->content;

			$allowed_html = self::$allowed_html;

			return [
				'id'                    => $survey->id,
				'title'                 => wp_kses( $survey->title, $allowed_html ),
				'survey_type'           => $survey->survey_type,
				'theme'                 => $settings['theme'] ?? 'normal',
				'require_logged_in'     => $settings['require_logged_in'] ?? false,
				'expiration_time'       => $settings['expiration_time'] ?? null,
				'skip_submit_button'    => $settings['skip_submit_button'] ?? false,
				'view_votes_in_results' => $settings['view_votes_in_results'] ?? false,
				'navigation_bar'        => $settings['navigation_bar'] ?? 'question_number',
				'allow_return'          => $settings['allow_return'] ?? false,
				'show_start_again'      => $settings['show_start_again'] ?? false,
				'show_footer_branding'  => $settings['show_footer_branding'] ?? true,
				'show_correctness'      => $settings['show_correctness'] ?? true,
				'include_timer'         => $settings['include_timer'] ?? false,
				'animation_type'        => $global_settings['animation_type'] ?? 'fade',
				'show_alphabet_labels'  => $global_settings['show_alphabet_labels'] ?? true,
				'yes_cover'             => $content['yes_cover'] ?? false,
				'yes_results'           => $content['yes_results'] ?? false,
				'start_button_title'    => esc_html( $content['start_button_title'] ?? 'Start' ),
				'content'               => [
					'cover_content'          => wp_kses( $content['cover_content'] ?? '', $allowed_html ),
					'show_cover_description' => $content['show_cover_description'] ?? false,
					'image_url'              => esc_url( $content['image_url'] ?? '' ),
					'image_w'                => absint( $content['image_w'] ?? 0 ),
					'image_h'                => absint( $content['image_h'] ?? 0 ),
					'image_alt'              => esc_attr( $content['image_alt'] ?? '' ),
					'closings'               => static::sanitize_closings( $content['closings'] ?? [] ),
					'results'                => static::sanitize_results( $content['results'] ?? [] ),
					'yes_cover'              => $content['yes_cover'] ?? false,
					'yes_results'            => $content['yes_results'] ?? false,
					'start_button_title'     => esc_html( $content['start_button_title'] ?? 'Start' ),
				],
			];
		}

		/**
		 * Create session if it doesn't exist.
		 *
		 * @param int    $survey_id      Survey ID.
		 * @param string $respondent_id  Respondent UUID.
		 * @param array  $question_order Array of question IDs.
		 * @return void
		 */
		protected static function create_session( $survey_id, $respondent_id, $question_order ) {
			// Get request data
			$request_data = SurveyX_Request_Helper::get_request_data();

			// Create session
			$session_id = SurveyX_Session_Manager::create_session(
				$survey_id,
				$respondent_id,
				$question_order,
				$request_data
			);

			// Increment view count
			if ( $session_id ) {
				SurveyX_Db::increment_view_count( $survey_id );
			}
		}

		/**
		 * Sanitize closings array for output.
		 *
		 * @param array $closings Closings data array.
		 * @return array Sanitized closings array.
		 */
		protected static function sanitize_closings( $closings ) {
			if ( empty( $closings ) || ! is_array( $closings ) ) {
				return [];
			}

			$allowed_html = self::$allowed_html;
			$sanitized    = [];

			foreach ( $closings as $closing ) {
				$sanitized[] = [
					'title'       => wp_kses( $closing['title'] ?? '', $allowed_html ),
					'description' => wp_kses( $closing['description'] ?? '', $allowed_html ),
					'image_url'   => esc_url( $closing['image_url'] ?? '' ),
					'image_w'     => absint( $closing['image_w'] ?? 0 ),
					'image_h'     => absint( $closing['image_h'] ?? 0 ),
					'image_alt'   => esc_attr( $closing['image_alt'] ?? '' ),
				];
			}

			return $sanitized;
		}

		/**
		 * Sanitize results array for output.
		 *
		 * @param array $results Results data array.
		 * @return array Sanitized results array.
		 */
		protected static function sanitize_results( $results ) {
			if ( empty( $results ) || ! is_array( $results ) ) {
				return [];
			}

			$allowed_html = self::$allowed_html;
			$sanitized    = [];

			foreach ( $results as $result ) {
				$sanitized[] = [
					'title'       => wp_kses( $result['title'] ?? '', $allowed_html ),
					'description' => wp_kses( $result['description'] ?? '', $allowed_html ),
					'image_url'   => esc_url( $result['image_url'] ?? '' ),
					'image_w'     => absint( $result['image_w'] ?? 0 ),
					'image_h'     => absint( $result['image_h'] ?? 0 ),
					'image_alt'   => esc_attr( $result['image_alt'] ?? '' ),
				];
			}

			return $sanitized;
		}
	}
}
