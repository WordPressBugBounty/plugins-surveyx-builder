<?php

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
		 * Concrete full-page cover layouts the client can render.
		 *
		 * 'stacked' (image above content), 'split_left' / 'split_right' (image beside
		 * it), 'fullscreen' (full-bleed background BEHIND the content).
		 *
		 * Every layout but 'stacked' needs `content.image_url`, and the client degrades
		 * to 'stacked' when the survey has no cover image. That gate lives on the
		 * FRONT-END on purpose, so this payload keeps reporting the author's actual
		 * choice rather than a substituted one.
		 *
		 * @var string[]
		 */
		const COVER_LAYOUTS = [ 'stacked', 'split_left', 'split_right', 'fullscreen' ];

		/**
		 * Estimated-completion-time bounds and the fallback amount. The admin editor
		 * clamps the input to the same range, so anything outside it can only reach
		 * the database through a hand edit or an import.
		 */
		const COVER_TIME_MIN     = 1;
		const COVER_TIME_MAX     = 999;
		const COVER_TIME_DEFAULT = 30;

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
			$params = static::parse_request_params( $request );

			// Fast reject for a respondent_id that was SENT but is malformed; an absent id
			// is left to init() so require_logged_in surveys still work. Skips the
			// get_survey_init_data() JOIN while matching init()'s null response shape
			// exactly. Must stay BEFORE the rate limiter, so junk cannot make us write
			// limiter transients.
			if ( ! empty( $params['respondent_id_invalid'] ) ) {
				return new WP_REST_Response(
					[
						'message' => esc_html__( 'Survey not found', 'surveyx-builder' ),
					],
					404
				);
			}

			// Burst protection on session creation / view inflation. The IP bucket runs
			// FIRST and leaves respondent_id out of its key, so minting a fresh UUID per
			// request cannot buy a fresh bucket (same pattern as /upload).
			$ip_rate_check = SurveyX_Validation_Helper::check_rate_limit( 'init_ip', '', SurveyX_Validation_Helper::RATE_INIT_IP, SurveyX_Validation_Helper::RATE_WINDOW );
			if ( is_wp_error( $ip_rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $ip_rate_check );
			}

			$rate_check = SurveyX_Validation_Helper::check_rate_limit( 'init', $params['respondent_id'], SurveyX_Validation_Helper::RATE_INIT, SurveyX_Validation_Helper::RATE_WINDOW );
			if ( is_wp_error( $rate_check ) ) {
				return SurveyX_Validation_Helper::error_response( $rate_check );
			}

			$survey_result = static::init(
				$params['survey_id'],
				$params['respondent_id'],
				$params['captcha_token']
			);

			// A captcha or validation failure must NOT create the session.
			if ( is_wp_error( $survey_result ) ) {
				return SurveyX_Validation_Helper::error_response( $survey_result );
			}

			if ( null === $survey_result ) {
				return new WP_REST_Response(
					[
						'message' => esc_html__( 'Survey not found', 'surveyx-builder' ),
					],
					404
				);
			}

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

			// A non-empty raw value that sanitizes to '' is present-but-malformed. An
			// ABSENT id is deliberately not flagged, so require_logged_in surveys still
			// flow through init().
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

			$data = SurveyX_Db::get_survey_init_data( $survey_id, $respondent_id );

			if ( ! $data ) {
				return null;
			}

			$survey = $data['survey'];

			$require_logged_in = rest_sanitize_boolean( $survey->settings['require_logged_in'] ?? false );

			$is_logged_in = is_user_logged_in();

			// The page HTML embeds no per-user REST nonce (kept generic so full-page
			// caches are safe), so the first /init arrives without X-WP-Nonce and
			// rest_cookie_check_errors() has already reset the current user to 0. For
			// login-required surveys, re-derive the user from the auth cookie for this
			// read-only request; `rest_nonce` below carries the real nonce for later
			// authenticated calls.
			if ( $require_logged_in && ! $is_logged_in ) {
				$cookie_user_id = wp_validate_auth_cookie( '', 'logged_in' );
				if ( $cookie_user_id ) {
					wp_set_current_user( $cookie_user_id );
					$is_logged_in = true;
				}
			}

			if ( $require_logged_in && ! $is_logged_in ) {
				return null;
			}

			if ( ! $require_logged_in && empty( $respondent_id ) ) {
				return null;
			}

			$session = SurveyX_Session_Manager::get_active_session( $survey_id, $respondent_id );

			if ( $session && 'expired' === $session->session_status ) {
				SurveyX_Session_Manager::reset_expired_session( $session->id, $respondent_id );
				$data['responses']            = [];
				$session->session_status      = 'viewed';
				$session->current_question_id = 0;
				$session->progress_percentage = 0;
				$session->restart_pending     = 0;
			}

			$voted_data = static::get_voted_data( $data, $respondent_id );

			$frontend_settings = static::build_frontend_settings( $survey );

			$session_status = $session ? $session->session_status : null;

			$response_data = [
				'settings'            => $frontend_settings,
				'questions'           => $data['questions'] ?? [],
				'answers'             => $data['answers'] ?? [],
				'votes'               => $voted_data,
				'session_status'      => $session_status,
				'restart_pending'     => $session ? (bool) $session->restart_pending : false,
				'current_question_id' => $session ? (int) $session->current_question_id : 0,
				// Delivered via this POST response, which is never page-cached, so every
				// logged-in user gets their own nonce. NULL on public surveys - no code
				// path may assume a nonce exists there.
				'rest_nonce'          => $require_logged_in ? wp_create_nonce( 'wp_rest' ) : null,
			];

			if ( ! $session && ! empty( $data['questions'] ) ) {
				// Captcha is enforced at session start. Its enablement/keys/secret live in
				// the GLOBAL plugin settings - the same source the front-end widget reads -
				// NOT in the per-survey settings row; resolving from the wrong one makes the
				// check silently no-op and lets bots POST straight to /init.
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
		 * Called at session start so bots cannot bypass the widget by POSTing straight
		 * to /init. A no-op returning true when no captcha is enabled.
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
		 * Get voted data for the current respondent.
		 *
		 * Scoped by respondent_id alone, regardless of authentication status, and
		 * stripped of sensitive fields before it reaches the client.
		 *
		 * @param array  $survey_data   Survey data containing responses.
		 * @param string $respondent_id Respondent UUID.
		 * @return array Filtered voted data with sensitive fields removed.
		 */
		protected static function get_voted_data( $survey_data, $respondent_id ) {
			if ( empty( $respondent_id ) ) {
				return [];
			}

			// Already scoped to this respondent by get_survey_init_data()'s
			// WHERE respondent_id = %s, so this only normalises to sequential keys.
			$filtered = array_values( $survey_data['responses'] );

			// Drop respondent_id only; response_content is needed to restore answers.
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
					'cover_layout'           => static::resolve_cover_layout( $content, $global_settings ),
					'show_cover_time'        => (bool) ( $content['show_cover_time'] ?? false ),
					'cover_time_value'       => static::resolve_cover_time_value( $content ),
					'cover_time_unit'        => in_array( $content['cover_time_unit'] ?? '', [ 'sec', 'min' ], true )
						? $content['cover_time_unit']
						: 'sec',
					'closings'               => static::sanitize_closings( $content['closings'] ?? [] ),
					'results'                => static::sanitize_results( $content['results'] ?? [] ),
					'yes_cover'              => $content['yes_cover'] ?? false,
					'yes_results'            => $content['yes_results'] ?? false,
					'start_button_title'     => esc_html( $content['start_button_title'] ?? 'Start' ),
				],
			];
		}

		/**
		 * Resolves the concrete full-page cover layout the client should render.
		 *
		 * The payload always carries one of self::COVER_LAYOUTS, so the front-end never
		 * has to interpret the 'default' sentinel itself.
		 *
		 * @param array $content         Survey content JSON (may be missing the key).
		 * @param array $global_settings Global plugin settings blob.
		 * @return string One of self::COVER_LAYOUTS; 'stacked' whenever nothing else applies.
		 */
		protected static function resolve_cover_layout( $content, $global_settings ) {
			$stored = $content['cover_layout'] ?? '';

			// An explicit concrete layout always wins.
			if ( in_array( $stored, self::COVER_LAYOUTS, true ) ) {
				return $stored;
			}

			// BACKWARD COMPATIBILITY: 'default' is an explicit opt-in and the ONLY value
			// that follows the global default. Surveys saved before this feature carry no
			// `cover_layout` key and must keep rendering stacked even after the owner
			// picks a non-stacked global default - never treat an absent or unrecognised
			// value as 'default'.
			if ( 'default' !== $stored ) {
				return 'stacked';
			}

			// The settings blob has no server-side allowlist, so clamp on consumption.
			$global = $global_settings['default_cover_layout'] ?? '';

			return in_array( $global, self::COVER_LAYOUTS, true ) ? $global : 'stacked';
		}

		/**
		 * Resolves the estimated-completion-time amount rendered under the cover CTA.
		 *
		 * An ABSENT key (saved before the feature, or never touched) and a PRESENT but
		 * unusable one both land on COVER_TIME_DEFAULT. Only a numeric value inside
		 * COVER_TIME_MIN..COVER_TIME_MAX after an int cast is honoured - clamping instead
		 * would fold blank, 0 and non-numeric to 1 ("Takes 1 sec") and absint() would
		 * mirror a negative (-5 rendering as "Takes 5 sec"), making junk indistinguishable
		 * from a real choice. is_numeric() rejects '', 'abc', arrays and booleans; the
		 * explicit (int) cast keeps the sign absint() would drop.
		 *
		 * @param array $content Survey content JSON (may be missing the key).
		 * @return int Amount between COVER_TIME_MIN and COVER_TIME_MAX.
		 */
		protected static function resolve_cover_time_value( $content ) {
			if ( ! isset( $content['cover_time_value'] ) || ! is_numeric( $content['cover_time_value'] ) ) {
				return self::COVER_TIME_DEFAULT;
			}

			$value = (int) $content['cover_time_value'];

			if ( $value < self::COVER_TIME_MIN || $value > self::COVER_TIME_MAX ) {
				return self::COVER_TIME_DEFAULT;
			}

			return $value;
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
			// SurveyX_Request_Helper::get_request_data() is deliberately NOT called: Free never
			// SELECTs from surveyx_respondents, so create_session() stores empty strings for
			// ip_address, user_agent and location rather than collect what nothing can read.
			$session_id = SurveyX_Session_Manager::create_session(
				$survey_id,
				$respondent_id,
				$question_order,
				[]
			);

			// The view count moves only when a session was really created.
			if ( $session_id ) {
				SurveyX_Db::increment_view_count( $survey_id );
				return true;
			}

			return false;
		}

		/**
		 * Sanitize closings array for output.
		 *
		 * `id` is a UUID string, not an int: ClosingPage.vue resolves a skip-logic jump by
		 * matching it, so dropping it sends every jump to closing #0.
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
					'id'          => sanitize_text_field( $closing['id'] ?? '' ),
					'title'       => wp_kses( $closing['title'] ?? '', $allowed_html ),
					'description' => wp_kses( $closing['description'] ?? '', $allowed_html ),
					'image_url'   => esc_url( $closing['image_url'] ?? '' ),
					'image_w'     => absint( $closing['image_w'] ?? 0 ),
					'image_h'     => absint( $closing['image_h'] ?? 0 ),
					'image_alt'   => esc_attr( $closing['image_alt'] ?? '' ),
					'enable_cta'  => ! empty( $closing['enable_cta'] ),
					'cta_label'   => sanitize_text_field( $closing['cta_label'] ?? '' ),
					'cta_url'     => esc_url_raw( $closing['cta_url'] ?? '' ),
					'cta_new_tab' => (bool) ( $closing['cta_new_tab'] ?? true ),
				];
			}

			return $sanitized;
		}

		/**
		 * Sanitize results array for output.
		 *
		 * `id` is a UUID string, not an int: result-helpers.js resolves a skip-logic jump by
		 * matching it, so dropping it sends every jump to result #0.
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
					'id'          => sanitize_text_field( $result['id'] ?? '' ),
					'title'       => wp_kses( $result['title'] ?? '', $allowed_html ),
					'description' => wp_kses( $result['description'] ?? '', $allowed_html ),
					'image_url'   => esc_url( $result['image_url'] ?? '' ),
					'image_w'     => absint( $result['image_w'] ?? 0 ),
					'image_h'     => absint( $result['image_h'] ?? 0 ),
					'image_alt'   => esc_attr( $result['image_alt'] ?? '' ),
					'enable_cta'  => ! empty( $result['enable_cta'] ),
					'cta_label'   => sanitize_text_field( $result['cta_label'] ?? '' ),
					'cta_url'     => esc_url_raw( $result['cta_url'] ?? '' ),
					'cta_new_tab' => (bool) ( $result['cta_new_tab'] ?? true ),
				];
			}

			return $sanitized;
		}
	}
}
