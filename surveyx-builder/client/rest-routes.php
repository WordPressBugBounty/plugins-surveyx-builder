<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_API_Handler', false ) ) {
	class SurveyX_API_Handler {

		private static $instance;

		public const ROUTE_NAMESPACE = SURVEYX_REST_NAMESPACE;

		/**
		 * Per-IP budgets charged ONLY to requests this gate refuses.
		 *
		 * An open survey charges neither bucket, so a respondent is never double-counted
		 * against the handlers' own limiters.
		 *
		 * Tier 1 (CLOSED_GATE_LIMIT), keyed per (IP, survey): a flood aimed at a closed
		 * survey must not 429 an open survey taken from the same IP - get_client_ip()
		 * reads REMOTE_ADDR only, so a whole NAT shares one bucket. Its key space must
		 * stay bounded by the surveys the SITE OWNER created, never by what a caller
		 * sends: survey_id is caller-supplied and set_transient() mints two wp_options
		 * rows per new key, which WP only reaps on the twice-daily
		 * delete_expired_transients sweep. Every id naming no row therefore shares
		 * CLOSED_GATE_UNKNOWN_BUCKET - four rows in total, however many ids are tried.
		 *
		 * Tier 2 (CLOSED_GATE_IP_LIMIT), keyed per IP across all surveys: backstop
		 * against walking survey ids, which tier 1 cannot bound because every fresh id
		 * starts with an empty bucket. Being site-wide it DOES refuse open surveys from
		 * that IP once saturated; at 10x tier 1 that takes 10 distinct closed or unknown
		 * ids at full budget in one window, a shape honest traffic does not produce.
		 *
		 * Both are FIXED windows (SurveyX_Validation_Helper::check_rate_limit), NOT
		 * derived from the honest-traffic arithmetic that sizes the RATE_* ceilings
		 * there, because honest traffic never charges them. A fixed window admits up to
		 * 2x the limit across a boundary; at 120 that is 4 req/s for one survey from one
		 * IP, far below what the refusal path costs to serve.
		 */
		public const CLOSED_GATE_LIMIT    = 120;
		public const CLOSED_GATE_IP_LIMIT = 1200;
		public const CLOSED_GATE_WINDOW   = 60;

		/**
		 * Tier-1 bucket every survey id that names no row is charged to.
		 *
		 * Deliberately a fixed string: it is the one part of the key space an
		 * unauthenticated caller could otherwise choose. Real surveys keep their own
		 * buckets, so tier 1's isolation is untouched.
		 */
		public const CLOSED_GATE_UNKNOWN_BUCKET = 'survey_closed_ip_unknown';

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
		 * Permission callback shared by every public survey route.
		 *
		 * These endpoints are intentionally reachable by anonymous visitors - surveys are
		 * embedded on public pages via [surveyx id="X"] - but "public" is not the same as
		 * "unauthorized", so they are NOT registered with '__return_true'. The
		 * survey-level gate lives here, in one named callback covering every public route
		 * at once: the survey must be active and renderable by the free engine
		 * (is_survey_open).
		 *
		 * The respondent-scoped half stays in the handlers, which enforce it already:
		 * session must exist and be valid (SurveyX_Session_Manager::get_active_session),
		 * and all IDs are sanitized (absint for survey_id, UUID validation for
		 * respondent_id).
		 *
		 * When no survey_id can be resolved this callback stands aside, so a request that
		 * omits it still gets the handler's own 400 rather than a bare 403.
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 * @return true|WP_Error True when allowed, WP_Error (403) when the survey is
		 *                       not open to respondents, WP_Error (400) when the
		 *                       request names two different surveys, WP_Error (429)
		 *                       when this IP has burnt a refusal budget.
		 */
		public function check_public_permission( WP_REST_Request $request ) {
			$survey_id = self::resolve_survey_id( $request );

			// Body and params name different surveys (see resolve_survey_id): refuse
			// before any DB work. Uncharged - the budgets measure pressure against one
			// closed survey, and this request resolved to none.
			if ( is_wp_error( $survey_id ) ) {
				return $survey_id;
			}

			// No usable survey_id: leave the handler to answer with its own 400.
			if ( empty( $survey_id ) ) {
				return true;
			}

			// Peek BEFORE the status lookup: an IP already over budget is answered 429
			// without paying for is_survey_open()'s deliberately uncached query. Peeking
			// consumes nothing, so an open survey is never charged here. It must follow
			// resolve_survey_id(), since tier 1 is keyed per survey.
			$flood = self::check_refusal_budget( $survey_id, false );
			if ( is_wp_error( $flood ) ) {
				return $flood;
			}

			if ( self::is_survey_open_once( $survey_id ) ) {
				return true;
			}

			// 403, not 404: the id is public in the embedding page, so hiding existence
			// buys nothing, and the shortcode already renders a closed/unavailable state
			// for the same condition.
			return new WP_Error(
				'surveyx_survey_closed',
				esc_html__( 'This survey is not available.', 'surveyx-builder' ),
				[ 'status' => 403 ]
			);
		}

		/**
		 * is_survey_open(), memoized for the current request, charging the refusal
		 * budget the first time a survey is found closed.
		 *
		 * WP core runs every permission_callback a SECOND time from
		 * rest_send_allow_header() on rest_post_dispatch, so without this memo both the
		 * status query and the charge below happen twice per request.
		 *
		 * Request-scoped only: is_survey_open() itself stays uncached so that
		 * unpublishing a survey takes effect immediately.
		 *
		 * @param int $survey_id Survey ID.
		 * @return bool True when the survey is still open to respondents.
		 */
		protected static function is_survey_open_once( $survey_id ) {
			static $checked = [];

			if ( isset( $checked[ $survey_id ] ) ) {
				return $checked[ $survey_id ];
			}

			$checked[ $survey_id ] = SurveyX_Db::is_survey_open( $survey_id );

			if ( ! $checked[ $survey_id ] ) {
				// Charge both budgets: a refusal never reaches the handlers' own rate
				// limiters, so without this any draft, closed or expired id is an
				// unauthenticated, unbounded load generator. IP is the only meaningful
				// key here - respondent_id is attacker-minted, not yet validated, and a
				// fresh UUID would reset a per-respondent bucket. The memo above keeps
				// this to one charge despite core's double dispatch.
				self::check_refusal_budget( $survey_id, true );
			}

			return $checked[ $survey_id ];
		}

		/**
		 * Tier-1 bucket a refusal is CHARGED to.
		 *
		 * A survey that exists gets its own bucket; everything else shares one, so the
		 * key space is the site owner's ids plus one - bounded by the site's data
		 * rather than by what the request asked for.
		 *
		 * @param int $survey_id Survey the request named.
		 * @return string Rate-limit bucket name.
		 */
		protected static function refusal_bucket( $survey_id ) {
			return self::survey_exists_once( $survey_id )
				? 'survey_closed_ip_' . $survey_id
				: self::CLOSED_GATE_UNKNOWN_BUCKET;
		}

		/**
		 * Whether a survey id names a row that exists at all, memoized per request.
		 *
		 * Reached only on the refusal path - an OPEN survey returns before this - so an
		 * honest respondent never pays for it, and core's double dispatch costs one read.
		 * Existence, not visibility: a draft, expired or otherwise closed survey is still
		 * a real row and still deserves its own rate-limit bucket.
		 *
		 * @param int $survey_id Survey ID (already absint()ed by resolve_survey_id).
		 * @return bool True when the id names a real survey row.
		 */
		protected static function survey_exists_once( $survey_id ) {
			global $wpdb;

			static $checked = [];

			if ( isset( $checked[ $survey_id ] ) ) {
				return $checked[ $survey_id ];
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$checked[ $survey_id ] = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}surveyx_surveys WHERE id = %d LIMIT 1",
					$survey_id
				)
			);

			return $checked[ $survey_id ];
		}

		/**
		 * Peeks at - or charges - both refusal budgets for one survey.
		 *
		 * Tier 1 is scoped to the survey the request named, tier 2 to the IP across all
		 * surveys; see the constants for why both are needed. Callers peek first
		 * ($consume = false) and charge only after the survey is found closed, so an
		 * open survey never leaves a mark on either bucket.
		 *
		 * Peek and charge MUST resolve tier 1's bucket the same way, through
		 * refusal_bucket(): the counter that is read has to be the counter that is
		 * written, or a flood of unknown ids peeks a bucket nobody ever charges, reads
		 * empty forever and is bounded by neither tier.
		 *
		 * The price is that the peek pays survey_exists_once()'s primary-key lookup on
		 * every request that resolved a survey id, open ones included - one memoized
		 * query. A saturated bucket still answers 429 before is_survey_open()'s
		 * deliberately uncached query, which is the property this tier exists for. A real
		 * survey, open or closed, resolves to its OWN bucket at both ends, so the shared
		 * unknown bucket saturating cannot refuse an open survey from the same IP; only
		 * tier 2's far higher ceiling can.
		 *
		 * @param int  $survey_id Survey the request named.
		 * @param bool $consume   True to charge the budgets, false to read them only.
		 * @return true|WP_Error True when under both limits, WP_Error (429) otherwise.
		 */
		protected static function check_refusal_budget( $survey_id, $consume ) {
			$bucket = self::refusal_bucket( $survey_id );

			$per_survey = SurveyX_Validation_Helper::check_rate_limit( $bucket, '', self::CLOSED_GATE_LIMIT, self::CLOSED_GATE_WINDOW, $consume );

			// Tier 2 must be read - and charged - even when tier 1 has already refused:
			// returning early on a tier-1 error freezes the site-wide backstop at the
			// count it held when tier 1 saturated, so it never reaches its own ceiling.
			$per_ip = SurveyX_Validation_Helper::check_rate_limit( 'survey_closed_ip', '', self::CLOSED_GATE_IP_LIMIT, self::CLOSED_GATE_WINDOW, $consume );

			return is_wp_error( $per_survey ) ? $per_survey : $per_ip;
		}

		/**
		 * Resolves the survey_id a public request refers to.
		 *
		 * ORDER MATTERS: the RAW JSON body is read FIRST, because that is what the
		 * handlers read (SurveyX_Validation_Helper::validate_json_body).
		 * WP_REST_Request::get_param() consults the JSON body only when the request
		 * carries a JSON content type (get_parameter_order()), so params-first let a
		 * request drop that header, name an OPEN survey in the query string, be
		 * authorized against it, and have the handler act on the CLOSED survey in its
		 * body. /progress and /question-seen have no survey-status check of their own, so
		 * answers kept being WRITTEN after a survey expired or was reverted to draft.
		 *
		 * get_param() stays as the fallback for requests whose survey_id is not in a JSON
		 * body at all - a multipart /upload posts its fields as form data.
		 *
		 * When body and params BOTH name a survey and the two differ, the request is
		 * refused rather than resolved: matching precedence already keeps gate and
		 * handler in step, but an explicit refusal cannot silently drift apart if either
		 * side's parsing changes.
		 *
		 * The body decode is shared with the handlers' own, so this costs no extra
		 * json_decode() (see get_raw_json_body()).
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 * @return int|WP_Error Survey ID, 0 when the request names none, or WP_Error
		 *                      (400) when body and params name different surveys.
		 */
		protected static function resolve_survey_id( WP_REST_Request $request ) {
			$body     = SurveyX_Validation_Helper::get_raw_json_body( $request );
			$in_body  = is_array( $body ) ? absint( $body['survey_id'] ?? 0 ) : 0;
			$in_param = absint( $request->get_param( 'survey_id' ) );

			if ( ! empty( $in_body ) && ! empty( $in_param ) && $in_body !== $in_param ) {
				return new WP_Error(
					'surveyx_survey_id_mismatch',
					esc_html__( 'Invalid data', 'surveyx-builder' ),
					[ 'status' => 400 ]
				);
			}

			return ! empty( $in_body ) ? $in_body : $in_param;
		}

		/**
		 * Register REST API routes for survey frontend.
		 *
		 * Every route authorizes through check_public_permission() (survey must be
		 * open) plus the handler's own session/ID validation.
		 *
		 * @since 1.0.0
		 */
		public function register_rest_routes() {
			$permission = [ $this, 'check_public_permission' ];

			// POST, not GET, to keep the personalized response (the visitor's own votes)
			// out of page caches.
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/init',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'init_survey' ],
					'permission_callback' => $permission,
					'args'                => [
						'survey_id'     => [
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
						'respondent_id' => [
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'captcha_token' => [
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				]
			);

			// Requires the session created by /init.
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/progress',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'update_progress' ],
					'permission_callback' => $permission,
				]
			);

			// Additionally gated in the callback on the survey's view_votes_in_results
			// setting (403) - vote counts are not public unless the owner opted in.
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/vote-results',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'get_answer_total_votes' ],
					'permission_callback' => $permission,
				]
			);

			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/question-seen',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'track_question_seen' ],
					'permission_callback' => $permission,
				]
			);

			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/activate-session',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'activate_session' ],
					'permission_callback' => $permission,
				]
			);

			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/complete-session',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'complete_session' ],
					'permission_callback' => $permission,
				]
			);
		}
	}
}

SurveyX_API_Handler::get_instance();
