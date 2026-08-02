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

			// Rate limit: burst protection on session creation / view inflation.
			$rate_check = SurveyX_Validation_Helper::check_rate_limit( 'init', $params['respondent_id'], 30, 60 );
			if ( is_wp_error( $rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $rate_check );
			}

			// Fast reject: a respondent_id was sent but is malformed (garbage/injection
			// attempt). Absent ids are left to init() so require_logged_in surveys still
			// work. Short-circuits the get_survey_init_data() JOIN for junk input while
			// matching the existing null-init response shape exactly.
			if ( ! empty( $params['respondent_id_invalid'] ) ) {
				return new WP_REST_Response(
					[
						'message' => esc_html__( 'Survey not found', 'surveyx-builder' ),
					],
					404
				);
			}

			// Process single survey
			$survey_result = static::init(
				$params['survey_id'],
				$params['respondent_id'],
				$params['captcha_token']
			);

			// Captcha or other validation failure - do NOT create the session
			if ( is_wp_error( $survey_result ) ) {
				return SurveyX_Validation_Helper::error_response( $survey_result );
			}

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

			// A non-empty raw value that sanitizes to '' is a garbage/injection
			// attempt (present-but-malformed). An absent id is NOT flagged here so
			// require_logged_in surveys still flow through init() as before.
			$respondent_id_invalid = ! empty( $respondent_id_raw ) && '' === $respondent_id;

			$captcha_token = sanitize_text_field( (string) $request->get_param( 'captcha_token' ) );

			return [
				'survey_id'             => $survey_id,
				'respondent_id'         => $respondent_id,
				'respondent_id_invalid' => $respondent_id_invalid,
				'captcha_token'         => $captcha_token,
			];
		}

		/**
		 * Process a single survey and build its response data.
		 *
		 * @param int    $survey_id     Survey ID.
		 * @param string $respondent_id Respondent UUID.
		 * @param string $captcha_token Captcha response token from the client.
		 * @return array|WP_Error|null Survey data array, WP_Error on captcha failure, or null if survey not found.
		 */
		protected static function init( $survey_id, $respondent_id, $captcha_token = '' ) {

			// Combined query
			$data = SurveyX_Db::get_survey_init_data( $survey_id, $respondent_id );

			if ( ! $data ) {
				return null;
			}

			$survey = $data['survey'];

			// Check authentication requirements
			$require_logged_in = rest_sanitize_boolean( $survey->settings['require_logged_in'] ?? false );

			$is_logged_in = is_user_logged_in();

			// The survey page HTML no longer embeds a per-user REST nonce (kept generic
			// so full-page caches are safe), so the first /init arrives with no
			// X-WP-Nonce header and WP's rest_cookie_check_errors() has reset the current
			// user to 0. For login-required surveys, re-derive the logged-in user straight
			// from the auth cookie for this read-only request. The authoritative, always
			// correct nonce for subsequent authenticated calls is returned in `rest_nonce`.
			if ( $require_logged_in && ! $is_logged_in ) {
				$cookie_user_id = wp_validate_auth_cookie( '', 'logged_in' );
				if ( $cookie_user_id ) {
					wp_set_current_user( $cookie_user_id );
					$is_logged_in = true;
				}
			}

			// Early return if authentication requirements are not met
			if ( $require_logged_in && ! $is_logged_in ) {
				return null; // User must be logged in but isn't
			}

			if ( ! $require_logged_in && empty( $respondent_id ) ) {
				return null; // Anonymous access requires valid respondent_id
			}

			// Get session
			$session = SurveyX_Session_Manager::get_active_session( $survey_id, $respondent_id );

			// Handle expired session - reset to fresh state, then re-read the row so
			// the in-memory session reflects the canonical reset without hand-patching
			// each column here (kept consistent with set_session_state()).
			if ( $session && 'expired' === $session->session_status ) {
				SurveyX_Session_Manager::reset_expired_session( $session->id, $respondent_id );
				$data['responses'] = [];
				$session           = SurveyX_Session_Manager::get_active_session( $survey_id, $respondent_id );
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
				// Fresh REST nonce for login-required surveys, delivered via this (never
				// page-cached) POST response so every logged-in user gets their own valid
				// nonce for later authenticated requests. Null for public surveys.
				'rest_nonce'          => $require_logged_in ? wp_create_nonce( 'wp_rest' ) : null,
			];

			// Create session if it doesn't exist
			if ( ! $session && ! empty( $data['questions'] ) ) {
				// Enforce captcha at session start. Captcha enablement/keys/secret live in the
				// GLOBAL plugin settings (the same source the front-end widget reads via the
				// shortcode), not in the per-survey settings row — so resolve from there or the
				// check silently no-ops and bots can POST straight to /init.
				$captcha_check = static::verify_captcha( SurveyX_Db::get_settings(), $captcha_token );
				if ( is_wp_error( $captcha_check ) ) {
					return $captcha_check;
				}

				$question_order = array_column( $data['questions'], 'id' );

				if ( ! empty( $question_order ) ) {
					$session_created = static::create_session( $survey_id, $respondent_id, $question_order );
					if ( $session_created ) {
						$response_data['session_status'] = 'viewed';
					}
				}
			}

			return $response_data;
		}

		/**
		 * Verify the captcha token when the survey has a captcha configured.
		 *
		 * Called at session start so bots cannot bypass the widget by POSTing
		 * directly to /init. When no captcha is enabled for the survey this
		 * is a no-op and returns true.
		 *
		 * @param array|object $settings      Full (unfiltered) survey settings.
		 * @param string       $captcha_token Captcha response token from the client.
		 * @return true|WP_Error True when passed or not required, WP_Error on failure.
		 */
		protected static function verify_captcha( $settings, $captcha_token ) {
			$active = SurveyX_Captcha_Helpers::get_active_captcha( $settings );

			if ( empty( $active['type'] ) || 'none' === $active['type'] ) {
				return true;
			}

			$token = sanitize_text_field( (string) $captcha_token );

			if ( '' === $token ) {
				return new WP_Error(
					'captcha_required',
					esc_html__( 'Please complete the captcha challenge.', 'surveyx-builder' ),
					[ 'status' => 403 ]
				);
			}

			$get = function ( $key ) use ( $settings ) {
				if ( is_object( $settings ) ) {
					return $settings->$key ?? '';
				}
				return is_array( $settings ) ? ( $settings[ $key ] ?? '' ) : '';
			};

			$verified = false;

			switch ( $active['type'] ) {
				case 'recaptcha_v2':
					$verified = SurveyX_Captcha_Helpers::verify_recaptcha_v2(
						$token,
						$get( 'recaptcha_v2_secret_key' )
					);
					break;
			}

			if ( ! $verified ) {
				return new WP_Error(
					'captcha_failed',
					esc_html__( 'Captcha verification failed. Please try again.', 'surveyx-builder' ),
					[ 'status' => 403 ]
				);
			}

			return true;
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

			// Responses are already scoped to this respondent by the
			// get_survey_init_data() query (WHERE respondent_id = %s), so no PHP
			// re-filter is needed — just normalise to sequential keys.
			$filtered = array_values( $survey_data['responses'] );

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
		 * @return bool True on success, false on failure.
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

			// Increment view count only on success
			if ( $session_id ) {
				SurveyX_Db::increment_view_count( $survey_id );
				return true;
			}

			return false;
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
