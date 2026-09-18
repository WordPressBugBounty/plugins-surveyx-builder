<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Validation_Helper', false ) ) {
	/**
	 * Helper class for common validation and sanitization logic.
	 *
	 * @since 1.0.0
	 */
	class SurveyX_Validation_Helper {

		/**
		 * Sanitizes and validates a UUID v4 string.
		 *
		 * @param string|int $uuid The UUID string to sanitize.
		 * @return string|int The sanitized UUID if valid, 0 if input is 0, empty string otherwise.
		 */
		public static function sanitize_uuid( $uuid ) {
			if ( 0 === $uuid || '0' === $uuid ) {
				return 0;
			}

			$uuid = trim( (string) $uuid );

			if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid ) ) {
				return strtolower( $uuid );
			}

			return '';
		}

		/**
		 * Decodes the request's RAW body as JSON, at most once per request.
		 *
		 * WP_REST_Request's own JSON parse cannot be reused: parse_json_params() returns
		 * early unless the request carries a JSON content type, so get_json_params() is
		 * empty for exactly the requests that reach this plugin with a JSON body under
		 * some other content type. Reading the raw body regardless is what lets the
		 * permission gate and the handlers resolve the SAME survey_id - when they
		 * disagreed, the gate authorized one survey while the handler acted on another.
		 *
		 * Memoized per request object so the gate and the handler share one
		 * json_decode() - WP core runs every permission_callback TWICE
		 * (rest_send_allow_header). The request object is stored BESIDE its body so PHP
		 * cannot recycle its spl_object_id() for a different object while the entry is
		 * live. A body that is not a JSON array/object is memoized as null, so a
		 * malformed body is not re-decoded on the second pass either.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return array|null Decoded body, or null when the body is not a JSON array/object.
		 */
		public static function get_raw_json_body( WP_REST_Request $request ) {
			static $memo = [];

			$key = spl_object_id( $request );

			if ( ! isset( $memo[ $key ] ) ) {
				$decoded = json_decode( $request->get_body(), true );

				$memo[ $key ] = [
					'request' => $request,
					'body'    => is_array( $decoded ) ? $decoded : null,
				];
			}

			return $memo[ $key ]['body'];
		}

		/**
		 * Validates request body as JSON array.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return array|WP_Error Array of data or WP_Error on failure.
		 */
		public static function validate_json_body( WP_REST_Request $request ) {
			$body = self::get_raw_json_body( $request );

			if ( null === $body ) {
				return new WP_Error( 'invalid_data', esc_html__( 'Invalid data', 'surveyx-builder' ), [ 'status' => 400 ] );
			}

			return surveyx_client_recursive_sanitize( $body );
		}

		/**
		 * Keeps only the answer IDs that really belong to the question, then limits
		 * the result to the question's maximum_votes setting.
		 *
		 * ID 0 is the virtual "Other" option - never a surveyx_answers row, so it is
		 * passed through instead of looked up. Negative IDs are server-side sentinels
		 * written by SurveyX_Db::create_responses() for the non-choice types (text_input,
		 * contact_info, opinion_scale, rating, date - plus Pro's file_upload and matrix);
		 * a client never submits them, so they are dropped. Those types post an empty
		 * answer_ids array and return here before any query.
		 *
		 * @param array      $answer_ids       Array of answer IDs to validate.
		 * @param int        $question_id      The question ID (already scoped to its survey by the caller).
		 * @param array|null $question_content Already-loaded question content; re-queried only when null.
		 * @return array Validated and limited answer IDs.
		 */
		public static function validate_answer_ids( $answer_ids, $question_id, $question_content = null ) {
			if ( ! is_array( $answer_ids ) || empty( $answer_ids ) ) {
				return [];
			}

			if ( ! is_array( $question_content ) ) {
				$question_content = SurveyX_Db::get_question_content( $question_id );
			}

			// Drop every ID the question does not own, keeping the request's own order
			// so the maximum_votes slice below behaves exactly as it did before.
			$requested = array_values( array_unique( array_map( 'intval', $answer_ids ) ) );
			$owned     = SurveyX_Db::filter_answer_ids_by_question( $question_id, $requested );

			$answer_ids = array_values(
				array_filter(
					$requested,
					static function ( $id ) use ( $owned ) {
						return 0 === $id || in_array( $id, $owned, true );
					}
				)
			);

			if ( empty( $answer_ids ) ) {
				return [];
			}

			$maximum_votes = absint( $question_content['maximum_votes'] ?? 1 );
			// A stored '', 0, or '0' passes the ?? guard but yields 0 → without this
			// clamp array_slice would drop every answer. Mirror the frontend, which
			// uniformly uses `maximum_votes || 1` (0/empty = single-select).
			if ( $maximum_votes < 1 ) {
				$maximum_votes = 1;
			}

			if ( count( $answer_ids ) <= $maximum_votes ) {
				return $answer_ids;
			}

			return array_slice( $answer_ids, 0, $maximum_votes );
		}

		/**
		 * Validates survey ID from request data.
		 *
		 * @param array $data Request data array.
		 * @return int|WP_Error Survey ID or WP_Error on failure.
		 */
		public static function validate_survey_id( $data ) {
			$survey_id = absint( $data['survey_id'] ?? 0 );

			if ( empty( $survey_id ) ) {
				return new WP_Error( 'invalid_survey_id', esc_html__( 'Invalid survey ID', 'surveyx-builder' ), [ 'status' => 400 ] );
			}

			return $survey_id;
		}

		/**
		 * Validates question ID from request data.
		 *
		 * @param array $data Request data array.
		 * @return int|WP_Error Question ID or WP_Error on failure.
		 */
		public static function validate_question_id( $data ) {
			$question_id = absint( $data['question_id'] ?? 0 );

			if ( empty( $question_id ) ) {
				return new WP_Error( 'invalid_question_id', esc_html__( 'Invalid question ID', 'surveyx-builder' ), [ 'status' => 400 ] );
			}

			return $question_id;
		}

		/**
		 * Validates respondent ID from request data.
		 *
		 * @param array $data Request data array.
		 * @return string|WP_Error Respondent ID or WP_Error on failure.
		 */
		public static function validate_respondent_id( $data ) {
			$respondent_id = self::sanitize_uuid( $data['respondent_id'] ?? '' );

			if ( empty( $respondent_id ) ) {
				return new WP_Error( 'invalid_respondent_id', esc_html__( 'Invalid respondent ID', 'surveyx-builder' ), [ 'status' => 400 ] );
			}

			return $respondent_id;
		}

		/**
		 * Validates text input responses.
		 *
		 * @param array $content     Response content.
		 * @param bool  $is_required Whether the field is required.
		 * @return true|WP_Error True if valid, WP_Error on failure.
		 */
		public static function validate_text_input( $content, $is_required ) {
			$has_message = isset( $content['message'] ) && ! empty( trim( $content['message'] ) );

			if ( $is_required && ! $has_message ) {
				return new WP_Error(
					'required_field',
					esc_html__( 'Please input your answer', 'surveyx-builder' ),
					[ 'status' => 400 ]
				);
			}

			return true;
		}

		/**
		 * Length of every public rate-limit window, in seconds.
		 *
		 * One shared window keeps the ceilings comparable: each limit below reads as
		 * "requests per minute", which is also the unit the derivation is stated in.
		 */
		public const RATE_WINDOW = 60;

		/**
		 * Per-endpoint ceilings for the public rate limiter.
		 *
		 * Deliberately NOT round numbers: each is the worst case a LEGITIMATE respondent
		 * can produce inside one RATE_WINDOW, times a margin. The arithmetic is recorded
		 * so that changing a limit means redoing the derivation, not nudging a number.
		 *
		 * Per RESPONDENT, in 60 s:
		 *   RATE_PROGRESS       The client serialises submits - survey-submission.js
		 *                       holds isAnswerVoting for the whole round trip and
		 *                       releases 400 ms after success (300 ms transition +
		 *                       100 ms buffer) - so even at zero latency a browser
		 *                       cannot exceed 2.5/s = 150. Back-navigation does not
		 *                       raise that rate; Next re-submits through the same
		 *                       chokepoint. x2 = 300, covering a mid-survey reload (the
		 *                       bucket is keyed by respondent_id and survives it) and a
		 *                       timed-out submit retried.
		 *   RATE_QUESTION_SEEN  Fires on every change of current_question_ID - forward,
		 *                       Back and re-forward views alike - behind a 500 ms
		 *                       trailing debounce (question-tracking.js), so 2/s = 120
		 *                       is a hard client-side ceiling. x2 = 240.
		 *   RATE_INIT           One per page load; a respondent reloading a stalled page
		 *                       plausibly manages 1/s = 60. x2 = 120.
		 *
		 * Per IP, in 60 s. get_client_ip() reads REMOTE_ADDR and nothing else, on
		 * purpose: every other candidate (X-Forwarded-For, X-Real-IP, CF-Connecting-IP)
		 * is caller-supplied, so trusting one would let one attacker mint a fresh bucket
		 * per request. The consequence must be sized for, not wished away - behind NAT or
		 * CGNAT an office, campus or mobile carrier shares one bucket, and behind a proxy
		 * or CDN that does NOT rewrite REMOTE_ADDR (nginx without set_real_ip_from,
		 * Cloudflare without the visitor-IP restore, some managed hosts' balancers) EVERY
		 * visitor shares one, so these stop being per-visitor budgets and become
		 * SITE-WIDE totals. They are therefore sized as site-wide concurrency ceilings:
		 * 200 concurrent respondents, past which a self-hosted install runs out of PHP
		 * workers before it runs out of budget. A respondent who reads before answering
		 * averages one answer per ~8 s, so the SUSTAINED (not peak) rates are ~10
		 * /progress and ~15 /question-seen per respondent per minute.
		 *   RATE_PROGRESS_IP       200 x 10 = 2000, x1.5 = 3000
		 *   RATE_QUESTION_SEEN_IP  200 x 15 = 3000, x1.5 = 4500
		 *   RATE_INIT_IP           200 page loads per minute, x3 = 600
		 *   RATE_ACTIVATE_SESSION_IP, RATE_COMPLETE_SESSION_IP, RATE_VOTE_RESULTS_IP,
		 *   RATE_START_AGAIN_IP    one call per session start, completion, drawer open
		 *                          or restart: 200 x 3 = 600 each
		 *
		 * RATE_PROGRESS_IP does NOT bound table growth, so raising it is safe: /progress
		 * refuses any respondent without an active session and only /init creates
		 * sessions, so new sessions, respondents and response rows are gated by
		 * RATE_INIT_IP. What it bounds is repeated writes against sessions that already
		 * exist, and those are deduplicated by the unique_response key.
		 *
		 * Two budgets are deliberately NOT derived here: the closed-survey refusal
		 * budgets (SurveyX_API_Handler::CLOSED_GATE_*), which only ever charge refused
		 * requests, and the Pro upload budgets (SurveyX_File_Upload_Handler::PER_IP_*),
		 * which meter disk rather than CPU and are bounded again by the per-survey file
		 * and byte caps.
		 */
		public const RATE_INIT                = 120;
		public const RATE_INIT_IP             = 600;
		public const RATE_PROGRESS            = 300;
		public const RATE_PROGRESS_IP         = 3000;
		public const RATE_QUESTION_SEEN       = 240;
		public const RATE_QUESTION_SEEN_IP    = 4500;
		public const RATE_ACTIVATE_SESSION_IP = 600;
		public const RATE_COMPLETE_SESSION_IP = 600;
		public const RATE_VOTE_RESULTS_IP     = 600;
		public const RATE_START_AGAIN_IP      = 600;

		/**
		 * Resolve the client IP for rate limiting.
		 *
		 * @return string Sanitized IP address, or empty string when unavailable.
		 */
		public static function get_client_ip() {
			if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
				return '';
			}

			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

			return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}

		/**
		 * Lightweight per-respondent/per-IP rate limiter for public endpoints.
		 *
		 * FIXED window, not sliding: it opens on the first charged request and closes
		 * $window seconds later, whatever happens in between.
		 *
		 * That MUST be enforced in the stored VALUE - the transient's expiry cannot do
		 * it. set_transient() rewrites _transient_timeout_* to time() + $window on every
		 * write, so a counter whose window was its own expiry cleared after $window of
		 * SILENCE rather than $window after the first request: a respondent who kept
		 * answering never let the bucket lapse, turning "60 per minute" into "60 per
		 * survey session" and refusing a real person on their 61st answer. The window
		 * start is therefore stored beside the count and evaluated on read; the expiry is
		 * only a garbage-collection lease, and re-arming it is harmless.
		 *
		 * A fixed window admits up to 2 x $limit across a boundary - accepted. The
		 * alternative, a sliding log, keeps one timestamp per request, so an IP bucket
		 * would hold thousands of entries in a wp_options row rewritten on every public
		 * request, on exactly the installs (no object cache) that can least afford it.
		 * These budgets exist to stop a runaway client and a crude flood, not to
		 * apportion a fair share.
		 *
		 * Write cost: at most $limit writes per window, since the over-limit branch
		 * returns before writing, so a sustained burst adds none. On plain installs each
		 * under-limit write is a wp_options upsert; a persistent object cache
		 * (Redis/Memcached) makes them in-memory and is the recommended fix.
		 *
		 * Pass $consume = false to read the bucket without charging it - for a caller
		 * that wants to know whether this IP is already over the limit before doing
		 * expensive work, and will charge the bucket itself later.
		 *
		 * @param string $bucket        Endpoint bucket name (e.g. 'init', 'progress').
		 * @param string $respondent_id Respondent UUID ('' when not yet assigned).
		 * @param int    $limit         Max requests allowed within the window.
		 * @param int    $window        Window length in seconds.
		 * @param bool   $consume       Whether to count this request against the bucket.
		 * @return true|WP_Error True if under the limit, WP_Error (429) if exceeded.
		 */
		public static function check_rate_limit( $bucket, $respondent_id = '', $limit = 30, $window = 60, $consume = true ) {
			$ip     = self::get_client_ip();
			$key    = 'sx_rl_' . md5( $bucket . '|' . (string) $respondent_id . '|' . $ip );
			$window = max( 1, (int) $window );
			$now    = time();

			$entry = get_transient( $key );
			$count = 0;
			$start = $now;

			if ( is_array( $entry ) && isset( $entry['c'], $entry['s'] ) ) {
				// A window that has run its course starts over, whatever lease the row has.
				if ( $now - (int) $entry['s'] < $window ) {
					$count = (int) $entry['c'];
					$start = (int) $entry['s'];
				}
			} elseif ( is_numeric( $entry ) ) {
				// Bare counter left in the store by the pre-fixed-window build across an
				// upgrade. Carried into a window opening now; self-heals within one window.
				$count = (int) $entry;
			}

			// Over the limit: bail before writing, so a sustained burst adds no writes.
			if ( $count >= $limit ) {
				return new WP_Error(
					'rate_limited',
					esc_html__( 'Too many requests. Please slow down and try again shortly.', 'surveyx-builder' ),
					[ 'status' => 429 ]
				);
			}

			if ( $consume ) {
				// The TTL is a lease, not the window: always >= the time left in the
				// window, so a row is never collected while its window is still open, and
				// re-arming it on each write cannot slide anything.
				set_transient( $key, [ 'c' => $count + 1, 's' => $start ], $window );
			}

			return true;
		}

		/**
		 * Creates standard error response.
		 *
		 * @param WP_Error $error The WP_Error object.
		 * @return WP_REST_Response Error response.
		 */
		public static function error_response( $error ) {
			$status     = 400;
			$error_data = $error->get_error_data();

			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = $error_data['status'];
			}

			return new WP_REST_Response(
				[ 'message' => $error->get_error_message() ],
				$status
			);
		}
	}
}
