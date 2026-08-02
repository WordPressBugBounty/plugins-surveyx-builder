<?php
/**
 * Review-request admin notice (WP-core style, big-plugin pattern).
 *
 * Shows a dismissible "leave us a review" notice to admins once the install is
 * genuinely engaged — never on fresh installs. Trigger: installed >= 7 days ago
 * AND >= 30 collected responses. Three actions (Rate / Already did / Maybe
 * later) persist in the `surveyx_review_notice` option; "Maybe later" and the
 * native dismiss (X) snooze for 14 days, the others hide it for good.
 *
 * Assets are registered on admin_enqueue_scripts (WP-proper: no raw inline
 * <script>/<style> in the notice markup); the AJAX handler is nonce- and
 * capability-guarded.
 *
 * @package SurveyX_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SURVEYX_REVIEW_MIN_DAYS' ) ) {
	define( 'SURVEYX_REVIEW_MIN_DAYS', 7 );
}
if ( ! defined( 'SURVEYX_REVIEW_MIN_RESPONSES' ) ) {
	define( 'SURVEYX_REVIEW_MIN_RESPONSES', 30 );
}
if ( ! defined( 'SURVEYX_REVIEW_SNOOZE_DAYS' ) ) {
	define( 'SURVEYX_REVIEW_SNOOZE_DAYS', 14 );
}
if ( ! defined( 'SURVEYX_REVIEW_URL' ) ) {
	define( 'SURVEYX_REVIEW_URL', 'https://wordpress.org/support/plugin/surveyx-builder/reviews/#new-post' );
}

/**
 * Install timestamp for the review trigger. Backfilled once from the oldest
 * survey's created_at (fair to existing installs), falling back to "now" for a
 * brand-new site with no surveys yet. Stored non-autoloaded.
 *
 * @return int Unix timestamp.
 */
if ( ! function_exists( 'surveyx_review_get_install_time' ) ) {
	function surveyx_review_get_install_time() {
		$stored = get_option( 'surveyx_installed_at' );
		if ( ! empty( $stored ) ) {
			return (int) $stored;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off backfill, result is cached in the option below.
		$oldest = $wpdb->get_var( "SELECT MIN(created_at) FROM {$wpdb->prefix}surveyx_surveys" );
		// created_at is stored in UTC; parse explicitly as UTC (don't rely on PHP's default tz).
		$time = $oldest ? (int) strtotime( $oldest . ' UTC' ) : time();

		add_option( 'surveyx_installed_at', $time, '', 'no' );

		return $time;
	}
}

/**
 * Whether the install has reached the response threshold. Counts up to the
 * threshold only (cheap on large tables) and caches the eligible flag for 12h.
 *
 * @return bool
 */
if ( ! function_exists( 'surveyx_review_has_enough_responses' ) ) {
	function surveyx_review_has_enough_responses() {
		$cached = get_transient( 'surveyx_review_responses_ok' );
		if ( '1' === $cached ) {
			return true;
		}

		global $wpdb;
		$threshold = (int) SURVEYX_REVIEW_MIN_RESPONSES;

		// Count at most $threshold rows; == threshold means we have enough.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached in the transient below.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT id FROM {$wpdb->prefix}surveyx_responses LIMIT %d ) t", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$threshold
			)
		);

		$ok = ( $count >= $threshold );
		if ( $ok ) {
			set_transient( 'surveyx_review_responses_ok', '1', 12 * HOUR_IN_SECONDS );
		}

		return $ok;
	}
}

/**
 * Whether to render the review notice on the current request.
 *
 * @return bool
 */
if ( ! function_exists( 'surveyx_review_should_show' ) ) {
	function surveyx_review_should_show() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$state = get_option( 'surveyx_review_notice', array() );
		$state = is_array( $state ) ? $state : array();

		// Permanently hidden (rated or "already did").
		if ( ! empty( $state['state'] ) && 'dismissed' === $state['state'] ) {
			return false;
		}

		// Snoozed and still within the snooze window.
		if ( ! empty( $state['snooze_until'] ) && time() < (int) $state['snooze_until'] ) {
			return false;
		}

		// Engagement gate: installed long enough AND enough responses.
		$age_ok = ( time() - surveyx_review_get_install_time() ) >= ( SURVEYX_REVIEW_MIN_DAYS * DAY_IN_SECONDS );
		if ( ! $age_ok ) {
			return false;
		}

		return surveyx_review_has_enough_responses();
	}
}

/**
 * Registers and enqueues the notice's CSS and JS (only when the notice will
 * render). Runs on admin_enqueue_scripts so the assets go through the standard
 * dependency queue. Both files live next to this PHP under includes/ (outside
 * the webpack `assets/` build output, which is wiped on every build), are
 * located via plugins_url() relative to __FILE__ so the URL is correct in both
 * the free and pro plugin, and are versioned by filemtime() for cache-busting.
 *
 * @return void
 */
if ( ! function_exists( 'surveyx_review_enqueue_assets' ) ) {
	function surveyx_review_enqueue_assets() {
		if ( ! surveyx_review_should_show() ) {
			return;
		}

		$handle   = 'surveyx-review-notice';
		$css_path = __DIR__ . '/review-notice.css';
		$js_path  = __DIR__ . '/review-notice.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : false;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : false;

		wp_enqueue_style( $handle, plugins_url( 'review-notice.css', __FILE__ ), array(), $css_ver );

		wp_enqueue_script( $handle, plugins_url( 'review-notice.js', __FILE__ ), array(), $js_ver, true );
		wp_localize_script(
			$handle,
			'surveyxReviewData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'surveyx_review_notice' ),
			)
		);
	}
}
add_action( 'admin_enqueue_scripts', 'surveyx_review_enqueue_assets' );

/**
 * Renders the review-request notice markup.
 *
 * @return void
 */
if ( ! function_exists( 'surveyx_review_render_notice' ) ) {
	function surveyx_review_render_notice() {
		if ( ! surveyx_review_should_show() ) {
			return;
		}

		// SurveyX logo — the "X" glyph cropped from the brand wordmark, recoloured
		// via currentColor. Static, developer-authored markup (no dynamic data), so
		// it is printed verbatim; wp_kses would strip the case-sensitive viewBox.
		$logo_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="738 46 262 312" width="24" height="26" aria-hidden="true" focusable="false"><g fill="currentColor"><path d="M0,0 L62,0 L69,10 L77,22 L106,65 L120,86 L130,101 L138,113 L148,128 L148,132 L138,148 L130,159 L120,175 L116,175 L87,131 L80,121 L72,109 L62,94 L54,82 L25,39 L0,1 Z " transform="translate(731,52)"/><path d="M0,0 L17,0 L25,11 L33,23 L43,38 L51,50 L61,65 L87,104 L90,109 L74,133 L64,148 L54,163 L46,175 L36,190 L20,214 L1,214 L6,205 L13,195 L23,180 L33,165 L62,121 L69,111 L70,108 L41,64 L33,53 L23,37 L15,26 L8,15 L-1,2 Z " transform="translate(748,137)"/><path d="M0,0 L61,0 L59,5 L39,35 L29,50 L21,62 L11,77 L1,92 L-7,104 L-10,109 L-2,121 L8,136 L16,148 L45,191 L59,212 L59,214 L-3,214 L-13,199 L-42,156 L-56,135 L-73,109 L-67,99 L-57,84 L-49,72 L-20,29 L-2,2 Z " transform="translate(933,137)"/><path d="M0,0 L2,0 L12,15 L22,30 L30,42 L32,47 L25,57 L17,69 L5,87 L4,88 L-58,88 L-53,79 L-46,69 L-38,57 L-10,15 Z " transform="translate(848,263)"/></g></svg>';

		// Static star icon (developer-authored SVG, no dynamic data) shown on the CTA.
		$star_svg = '<svg class="surveyx-review__btn-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>';
		?>
		<div class="notice notice-info is-dismissible surveyx-review-notice" data-surveyx-review>
			<div class="surveyx-review__inner">
				<div class="surveyx-review__logo">
					<?php echo $logo_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static developer-authored SVG, no dynamic data. ?>
				</div>
				<div class="surveyx-review__body">
					<h3 class="surveyx-review__title"><?php esc_html_e( 'Enjoying SurveyX Builder?', 'surveyx-builder' ); ?></h3>
					<p class="surveyx-review__text">
						<?php esc_html_e( "You've collected a good number of responses with SurveyX. If it's been helpful, a quick review would mean a lot and helps us keep improving. Thanks so much!", 'surveyx-builder' ); ?>
					</p>
					<div class="surveyx-review__actions">
						<a href="<?php echo esc_url( SURVEYX_REVIEW_URL ); ?>" class="button button-primary surveyx-review__btn" target="_blank" rel="noopener noreferrer" data-surveyx-review-action="dismiss">
							<?php echo $star_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static developer-authored SVG, no dynamic data. ?>
							<span><?php esc_html_e( 'Rate SurveyX Builder', 'surveyx-builder' ); ?></span>
						</a>
						<a href="#" class="surveyx-review__link" data-surveyx-review-action="dismiss"><?php esc_html_e( 'I already did', 'surveyx-builder' ); ?></a>
						<a href="#" class="surveyx-review__link surveyx-review__link--muted" data-surveyx-review-action="later"><?php esc_html_e( 'Maybe later', 'surveyx-builder' ); ?></a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
add_action( 'admin_notices', 'surveyx_review_render_notice' );

/**
 * Persists the user's choice for the review notice.
 *  - rate / dismiss -> hidden permanently
 *  - later          -> snoozed for SURVEYX_REVIEW_SNOOZE_DAYS
 *
 * @return void
 */
if ( ! function_exists( 'surveyx_review_ajax' ) ) {
	function surveyx_review_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '', 403 );
		}
		check_ajax_referer( 'surveyx_review_notice', 'nonce' );

		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';

		if ( 'later' === $choice ) {
			update_option(
				'surveyx_review_notice',
				array(
					'state'        => 'snoozed',
					'snooze_until' => time() + ( SURVEYX_REVIEW_SNOOZE_DAYS * DAY_IN_SECONDS ),
				),
				false
			);
		} else {
			// "dismiss" (rated or already did) — hide for good.
			update_option( 'surveyx_review_notice', array( 'state' => 'dismissed' ), false );
		}

		wp_send_json_success();
	}
}
add_action( 'wp_ajax_surveyx_review_action', 'surveyx_review_ajax' );
