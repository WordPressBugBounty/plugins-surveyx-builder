<?php

/**
 * SurveyX shortcode handler for [surveyx id="X"].
 *
 * Renders a generic, cache-safe placeholder; the Vue client fetches the survey
 * over the REST API after captcha verification.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Shortcode_Handler', false ) ) {
	class SurveyX_Shortcode_Handler {

		private static $instance;
		public const PREFIX = 'surveyx-';

		/** @var string Access gate results returned by get_access_gate(). */
		public const GATE_PASS           = '';
		public const GATE_NOT_FOUND      = 'not_found';
		public const GATE_NOT_AVAILABLE  = 'not_available';
		public const GATE_NOT_PUBLISHED  = 'not_published';
		public const GATE_LOGIN_REQUIRED = 'login_required';

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		protected function __construct() {
			self::$instance = $this;

			add_action( 'wp_enqueue_scripts', [ $this, 'register_scripts' ] );
			add_filter( 'do_shortcode_tag', [ $this, 'maybe_enqueue' ], 10, 3 );
			add_shortcode( 'surveyx', [ $this, 'render_survey' ] );
		}

		/**
		 * Registers client-side styles and scripts.
		 *
		 * @return void
		 */
		public function register_scripts() {
			wp_register_style( self::PREFIX . 'client', SURVEYX_URL . 'assets/client/style.min.css', [], SURVEYX_VERSION );

			// Captcha scripts are loaded dynamically by the Vue client, not registered here.
			wp_register_script( self::PREFIX . 'vendor-client', SURVEYX_URL . 'assets/vendor-client/bundle.js', [], SURVEYX_VERSION, true );
			wp_register_script( self::PREFIX . 'client', SURVEYX_URL . 'assets/client/bundle.js', [ 'wp-i18n', self::PREFIX . 'vendor-client' ], SURVEYX_VERSION, true );
			surveyx_pin_chunk_base_url( self::PREFIX . 'client' );

			// The 3rd argument is required: without it WordPress only looks in
			// WP_LANG_DIR/plugins, where nothing but a wordpress.org language pack lands,
			// so the .json files shipped in this plugin's languages/ are never read.
			wp_set_script_translations( self::PREFIX . 'client', 'surveyx-builder', SURVEYX_PATH . 'languages' );
		}

		/**
		 * Maybe enqueue scripts when shortcode is detected.
		 *
		 * @param mixed  $output Shortcode output.
		 * @param string $tag    Shortcode tag.
		 * @param array  $attr   Shortcode attributes.
		 *
		 * @return mixed
		 */
		public function maybe_enqueue( $output, $tag, $attr ) {
			if ( 'surveyx' !== $tag ) {
				return $output;
			}

			self::enqueue_client_assets();

			return $output;
		}

		/**
		 * Registers, configures and enqueues the client bundle.
		 * Shared by the [surveyx] shortcode and the standalone survey page, so both
		 * render modes boot the exact same client with the exact same config.
		 *
		 * @return void
		 */
		public static function enqueue_client_assets() {
			$handler = self::get_instance();

			if ( ! wp_script_is( self::PREFIX . 'client', 'registered' ) ) {
				$handler->register_scripts();
			}

			// wp_localize_script() APPENDS. Called twice for one handle it prints the
			// whole object twice and the second assignment silently wins, so this must
			// run once per request no matter how many [surveyx] shortcodes a page holds.
			//
			// 'done' is the wrong test and was the wrong test before: it only turns true
			// once the script has been PRINTED, which happens in wp_footer - long after
			// two shortcodes in the_content have both been through here. Asking whether
			// the handle already carries data is the question that actually matters.
			//
			// The enqueue calls stay outside the guard: they are idempotent, and a return
			// here would skip them on the second shortcode.
			$already_localized = false !== wp_scripts()->get_data( self::PREFIX . 'client', 'data' );

			if ( ! $already_localized ) {

				$settings     = SurveyX_Db::get_settings();
				$captcha_info = SurveyX_Captcha_Helpers::get_active_captcha( $settings );

				// The REST nonce is deliberately NOT embedded: a per-user nonce baked into
				// shortcode HTML is served to everyone under full-page caching. Login-required
				// surveys get a fresh one from the POST /init response (`rest_nonce`), which is
				// never page-cached, keeping this markup generic.
				$config = [
					'apiUrl'         => esc_url_raw( rest_url( 'surveyx/v1' ) ),
					'captchaType'    => $captcha_info['type'] ?? 'none',
					'captchaSiteKey' => $captcha_info['site_key'] ?? '',
				];

				wp_localize_script( self::PREFIX . 'client', 'surveyxConfigs', $config );
			}

			wp_enqueue_style( self::PREFIX . 'client' );
			wp_enqueue_script( self::PREFIX . 'client' );
		}

		/**
		 * Enqueues the client stylesheet on its own.
		 * For pages that render a notice instead of booting the survey app, so the
		 * notice is styled without shipping the bundle it does not use.
		 *
		 * @return void
		 */
		public static function enqueue_client_style() {
			if ( ! wp_style_is( self::PREFIX . 'client', 'registered' ) ) {
				self::get_instance()->register_scripts();
			}

			wp_enqueue_style( self::PREFIX . 'client' );
		}

		/**
		 * Renders the survey mount point plus its branded loader.
		 * Shared by the shortcode and the standalone survey page; the mode only
		 * decides which container classes are emitted.
		 *
		 * @param int|string $survey_id Survey ID.
		 * @param string     $size      Size key, shortcode mode only.
		 * @param string     $mode      'shortcode' or 'fullpage'.
		 *
		 * @return string HTML output.
		 */
		public static function render_survey_container( $survey_id, $size = 'l', $mode = 'shortcode' ) {
			$render_row    = SurveyX_Db::get_survey_render_row( $survey_id );
			$survey_config = is_array( $render_row->settings ?? null ) ? $render_row->settings : [];

			$theme = sanitize_key( $survey_config['theme'] ?? 'normal' );

			$classes = [ 'surveyx' ];

			if ( 'fullpage' === $mode ) {
				$classes[] = 'surveyx-page';
				$classes[] = 'survey-theme-' . $theme;
			} else {
				$classes[] = 'surveyx-shortcode';
				$classes[] = 'survey-theme-' . $theme;
				$classes[] = 'survey-size-' . sanitize_key( $size );
			}

			// dir="ltr" is deliberate: the survey stylesheet positions with physical
			// left/right throughout, so inheriting dir="rtl" flips the text and the flex
			// order while every explicit offset stays put — a mixed layout worse than
			// either direction done properly. Pinned until the stylesheet uses logical
			// properties; RTL script still shapes and reads correctly, only the block
			// direction does not mirror.
			$output  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" dir="ltr" data-survey-id="' . esc_attr( $survey_id ) . '">';
			$output .= self::render_loader();

			// Scripting disabled: the mount never happens, so hide the branded loader (an
			// animated bar above a "needs JavaScript" line reads as a broken page) and say
			// why instead. The CSS must be inline because it applies without the bundle
			// ever running, and every --sx-* variable, the font included, comes from it.
			$output .= '<noscript>';
			$output .= '<style>.surveyx .sx-loader{display:none}.surveyx-page{font-family:system-ui,sans-serif}</style>';
			$output .= '<div class="surveyx-notice"><p class="surveyx-notice-message">' . esc_html__( 'This survey needs JavaScript to run. Please enable JavaScript in your browser, then reload this page.', 'surveyx-builder' ) . '</p></div>';
			$output .= '</noscript>';
			$output .= '</div>';

			return $output;
		}

		/**
		 * Renders the survey shortcode.
		 *
		 * @param array $atts Shortcode attributes.
		 *
		 * @return string HTML output.
		 */
		public function render_survey( $atts ) {
			$survey_id = ! empty( $atts['id'] ) ? esc_attr( $atts['id'] ) : '';

			$size        = ! empty( $atts['size'] ) ? sanitize_key( $atts['size'] ) : 'l';
			$valid_sizes = [ 'xs', 's', 'm', 'l', 'xl' ];
			if ( ! in_array( $size, $valid_sizes, true ) ) {
				$size = 'l';
			}

			if ( empty( $survey_id ) ) {
				if ( current_user_can( 'manage_options' ) ) {
					return self::render_notice(
						esc_html__( 'Survey ID Missing', 'surveyx-builder' ),
						esc_html__( 'The shortcode is missing a survey ID. Use format: [surveyx id="123"]', 'surveyx-builder' )
					);
				}

				return '';
			}

			// Memoized: one fetch covers existence, mode and settings.
			$render_row = SurveyX_Db::get_survey_render_row( $survey_id );
			$gate       = self::get_access_gate( $render_row );

			if ( self::GATE_NOT_FOUND === $gate ) {
				if ( current_user_can( 'manage_options' ) ) {
					return self::render_notice(
						esc_html__( 'Survey Not Found', 'surveyx-builder' ),
						sprintf(
							// translators: %s is the survey ID number.
							esc_html__( 'Survey ID %s does not exist or may only be available in the Pro version.', 'surveyx-builder' ),
							$survey_id
						)
					);
				}

				return '';
			}

			if ( SurveyX_Db::survey_needs_pro( $render_row->id, $render_row->s_mode ) ) {
				if ( current_user_can( 'manage_options' ) ) {
					return self::render_notice(
						esc_html__( 'Pro Survey', 'surveyx-builder' ),
						esc_html__( 'This survey uses Pro features. Please activate SurveyX Pro to display it.', 'surveyx-builder' )
					) . $this->render_admin_edit_links( $survey_id );
				}

				// The free engine cannot render a Pro-mode survey at all: is_survey_open()
				// and get_survey_init_data() ask survey_needs_pro() the same question, so
				// /init answers 404 whatever else is true of this row. Returning now also
				// stops the gates below promising something — "log in and you may take
				// this" — that logging in could never deliver.
				return '';
			}

			if ( self::GATE_NOT_AVAILABLE === $gate ) {
				if ( current_user_can( 'manage_options' ) ) {
					return self::render_notice(
						esc_html__( 'Survey Not Available', 'surveyx-builder' ),
						sprintf(
							// translators: %s is the survey ID number.
							esc_html__( 'Survey ID %s exists but settings could not be loaded. This survey may only be available in the Pro version.', 'surveyx-builder' ),
							$survey_id
						)
					) . $this->render_admin_edit_links( $survey_id );
				}

				return '';
			}

			// Only reached by a logged-out visitor. Without this the mount point is
			// emitted, /init answers 404 to anyone not signed in and the client removes
			// the container — blank space where the survey was, and no way to know why.
			if ( self::GATE_LOGIN_REQUIRED === $gate ) {
				// Back to the post the shortcode sits in, not wp-admin, where a bare
				// wp_login_url() lands a subscriber. The permalink is identical for every
				// visitor, so a full-page cache can hold this markup safely.
				$return_to = get_permalink();

				return self::render_login_required_notice( $return_to ? $return_to : home_url( '/' ) );
			}

			$output = self::render_survey_container( $survey_id, $size, 'shortcode' );

			$output .= $this->render_admin_edit_links( $survey_id );

			return $output;
		}

		/**
		 * Evaluates the access gates that decide whether a survey may be answered.
		 *
		 * Single source of truth for the [surveyx] shortcode and the standalone
		 * survey page, so both render paths agree on who may take a survey.
		 *
		 * @param object|null $render_row Row from SurveyX_Db::get_survey_render_row().
		 *
		 * @return string One of the GATE_* constants; GATE_PASS when the survey
		 *                may be rendered.
		 */
		public static function get_access_gate( $render_row ) {
			if ( is_null( $render_row ) ) {
				return self::GATE_NOT_FOUND;
			}

			if ( is_null( $render_row->settings ) ) {
				return self::GATE_NOT_AVAILABLE;
			}

			// Covers an unpublished status and an empty question set alike. Checked LAST
			// so the two gates above keep exactly the rows they had, and deliberately left
			// unhandled by render_survey(): the shortcode has always rendered drafts and
			// empty surveys for whoever embedded them; only the standalone page refuses them.
			if ( empty( $render_row->is_published ) ) {
				return self::GATE_NOT_PUBLISHED;
			}

			$settings = is_array( $render_row->settings ?? null ) ? $render_row->settings : [];

			// The free engine honours "Login Required to Vote" — /init already refuses a
			// logged-out visitor — so the renderer must say so, or the refusal reaches them
			// as an empty container. There is deliberately NO GATE_EXPIRED beside it: free
			// has no closing-date UI and its is_survey_open() enforces none, so a
			// renderer-only expiry would hide a survey the free API still accepts answers for.
			if ( self::should_require_login( ! empty( $settings['require_logged_in'] ) ) ) {
				return self::GATE_LOGIN_REQUIRED;
			}

			return self::GATE_PASS;
		}

		/**
		 * Renders the notice shown to a logged-out visitor on a login-only survey.
		 * Shared by the shortcode and the standalone survey page, so both render
		 * paths tell the visitor the same thing AND offer the same way out of it.
		 *
		 * Telling somebody to log in without giving them a link is a dead end, so the
		 * link markup lives here once rather than in each caller.
		 *
		 * @param string $login_redirect Where to send the visitor after they log in.
		 *                               Empty renders the card with no link, which is
		 *                               what a caller that has no URL to return to
		 *                               should get.
		 * @return string HTML output.
		 */
		public static function render_login_required_notice( $login_redirect = '' ) {
			$card = surveyx_render_notification(
				esc_html__( 'Login Required to Vote', 'surveyx-builder' ),
				esc_html__( 'Please log in to your account before voting on this survey. This ensures that each vote is counted fairly and securely.', 'surveyx-builder' )
			);

			if ( '' === (string) $login_redirect ) {
				return $card;
			}

			return '<div class="sx-page-notice">'
				. $card
				. '<a class="sx-page-notice__login" href="' . esc_url( wp_login_url( $login_redirect ) ) . '">'
				. esc_html__( 'Log in', 'surveyx-builder' )
				. '</a>'
				. '</div>';
		}

		/**
		 * Checks if login is required and the visitor is not logged in.
		 *
		 * @param bool $is_login_required Whether login is required.
		 *
		 * @return bool True if the login notice should be shown.
		 */
		private static function should_require_login( $is_login_required ) {
			return ! is_user_logged_in() && $is_login_required;
		}

		/**
		 * Public accessor for the administrator edit links, used by the standalone
		 * survey page which cannot reach the protected renderer.
		 *
		 * @param int $survey_id Survey ID.
		 * @return string HTML output for edit links.
		 */
		public function public_admin_edit_links( $survey_id ) {
			return $this->render_admin_edit_links( $survey_id );
		}

		/**
		 * Renders edit links for administrators.
		 *
		 * @param int $survey_id Survey ID.
		 * @return string HTML output for edit links.
		 */
		protected function render_admin_edit_links( $survey_id ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return '';
			}

			$edit_url      = admin_url( 'admin.php?page=surveyx#/survey/' . $survey_id . '/general' );
			$analytics_url = admin_url( 'admin.php?page=surveyx#/survey/' . $survey_id . '/analytics' );

			$output  = '<div class="surveyx-edit-links">';
			$output .= '<a href="' . esc_url( $analytics_url ) . '" target="_blank" rel="noopener" class="surveyx-edit-btn surveyx-edit-btn--icon" title="' . esc_attr__( 'View Analytics', 'surveyx-builder' ) . '" aria-label="' . esc_attr__( 'View Analytics', 'surveyx-builder' ) . '">';
			$output .= '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>';
			$output .= '</a>';
			$output .= '<a href="' . esc_url( $edit_url ) . '" target="_blank" rel="noopener" class="surveyx-edit-btn">';
			$output .= '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
			$output .= '<span>' . esc_html__( 'Edit Survey', 'surveyx-builder' ) . '</span>';
			$output .= '</a>';
			$output .= '</div>';

			return $output;
		}

		/**
		 * Renders a notice card for survey errors.
		 *
		 * @param string $title Notice title.
		 * @param string $message Notice message.
		 * @return string Notice HTML.
		 */
		protected static function render_notice( $title, $message ) {
			return sprintf(
				'<div class="surveyx-notice">
                    <div class="surveyx-notice-content">
                        <svg class="surveyx-notice-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 17V11"></path>
                            <path d="M22 12C22 16.714 22 19.0711 20.5355 20.5355C19.0711 22 16.714 22 12 22C7.28595 22 4.92893 22 3.46447 20.5355C2 19.0711 2 16.714 2 12C2 7.28595 2 4.92893 3.46447 3.46447C4.92893 2 7.28595 2 12 2C16.714 2 19.0711 2 20.5355 3.46447C21.5093 4.43821 21.8356 5.80655 21.9449 8"></path>
                            <path d="M12 8H12.0001"></path>
                        </svg>
                        <div class="surveyx-notice-body">
                            <h4 class="surveyx-notice-title">%s</h4>
                            <p class="surveyx-notice-message">%s</p>
                        </div>
                    </div>
                </div>',
				esc_html( $title ),
				esc_html( $message )
			);
		}

		/**
		 * Renders branded loading screen with logo and progress bar.
		 * Displays while survey data is being fetched.
		 *
		 * @param string $brand_logo_url Optional custom brand logo URL
		 * @return string Loader HTML
		 */
		protected static function render_loader( $brand_logo_url = '' ) {
			$output  = '<div class="sx-loader">';
			$output .= '<div class="sx-loader__content">';
			$output .= '<div class="sx-loader__logo">' . self::get_logo_html( $brand_logo_url ) . '</div>';
			$output .= '<div class="sx-loader__progress"><div class="sx-loader__bar"></div></div>';
			$output .= '</div>';
			$output .= '</div>';

			return $output;
		}

		/**
		 * Returns logo HTML (custom image or default SVG).
		 *
		 * @param string $logo_url Optional custom logo URL.
		 * @return string Logo HTML.
		 */
		protected static function get_logo_html( $logo_url = '' ) {
			if ( $logo_url ) {
				return '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr__( 'Logo', 'surveyx-builder' ) . '" />';
			}

			$output  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 450" fill="currentColor" width="120">';
			$output .= '<path d="M0,0 L21,0 L35,3 L45,7 L54,14 L63,23 L64,26 L51,36 L38,46 L35,46 L29,38 L20,33 L16,32 L5,32 L-3,35 L-6,41 L-5,48 L0,52 L15,57 L36,64 L47,70 L56,78 L61,86 L64,96 L64,113 L60,125 L54,133 L46,141 L35,147 L23,151 L18,152 L-3,152 L-18,149 L-30,144 L-40,138 L-52,128 L-52,125 L-44,118 L-31,106 L-26,103 L-18,111 L-6,118 L3,120 L11,120 L20,117 L24,113 L25,111 L25,101 L21,97 L11,93 L-14,86 L-28,79 L-36,72 L-41,64 L-44,53 L-44,37 L-41,27 L-34,16 L-23,7 L-10,2 Z " transform="translate(60,205)"/>';
			$output .= '<path d="M0,0 L12,0 L12,3 L48,3 L52,13 L74,76 L81,97 L83,104 L89,83 L106,35 L117,4 L118,3 L161,3 L160,9 L141,55 L124,96 L104,144 L101,149 L64,149 L58,137 L39,91 L21,48 L13,29 L12,36 L-4,38 L-14,42 L-21,47 L-27,56 L-30,65 L-31,148 L-32,149 L-69,149 L-70,148 L-70,4 L-69,3 L-33,3 L-32,4 L-31,23 L-25,13 L-16,5 L-5,1 Z " transform="translate(346,205)"/>';
			$output .= '<path d="M0,0 L20,0 L34,3 L46,8 L57,16 L63,21 L71,31 L79,47 L82,56 L84,67 L84,84 L83,90 L-24,91 L-20,101 L-15,108 L-6,114 L4,117 L17,117 L30,113 L39,106 L45,99 L50,101 L61,110 L75,120 L73,125 L62,136 L49,144 L36,149 L21,152 L0,152 L-17,148 L-31,141 L-41,133 L-49,125 L-57,112 L-62,99 L-65,82 L-65,71 L-62,54 L-57,41 L-48,27 L-36,15 L-25,8 L-13,3 Z M3,33 L-8,37 L-16,43 L-23,54 L-25,61 L45,61 L42,52 L36,44 L28,37 L21,34 L16,33 Z " transform="translate(555,205)"/>';
			$output .= '<path d="M0,0 L37,0 L38,1 L39,88 L41,98 L45,106 L51,111 L59,114 L73,114 L82,110 L90,103 L94,93 L95,87 L96,1 L97,0 L133,0 L134,1 L134,145 L133,146 L98,146 L97,145 L96,128 L92,134 L87,139 L81,144 L69,148 L62,149 L49,149 L35,146 L22,139 L15,133 L6,120 L1,106 L0,100 Z " transform="translate(129,208)"/>';
			$output .= '<path d="M0,0 L45,0 L49,10 L77,93 L79,101 L87,75 L110,7 L113,0 L157,0 L158,2 L138,50 L123,86 L106,127 L92,160 L85,173 L77,183 L73,188 L63,196 L51,201 L42,203 L14,203 L5,201 L4,199 L9,167 L10,164 L19,165 L32,165 L41,162 L48,156 L54,145 L55,136 L35,88 L18,47 L0,4 Z " transform="translate(623,208)"/>';
			$output .= '<path d="M0,0 L62,0 L69,10 L77,22 L106,65 L120,86 L130,101 L138,113 L148,128 L148,132 L138,148 L130,159 L120,175 L116,175 L87,131 L80,121 L72,109 L62,94 L54,82 L25,39 L0,1 Z " transform="translate(731,52)"/>';
			$output .= '<path d="M0,0 L17,0 L25,11 L33,23 L43,38 L51,50 L61,65 L87,104 L90,109 L74,133 L64,148 L54,163 L46,175 L36,190 L20,214 L1,214 L6,205 L13,195 L23,180 L33,165 L62,121 L69,111 L70,108 L41,64 L33,53 L23,37 L15,26 L8,15 L-1,2 Z " transform="translate(748,137)"/>';
			$output .= '<path d="M0,0 L61,0 L59,5 L39,35 L29,50 L21,62 L11,77 L1,92 L-7,104 L-10,109 L-2,121 L8,136 L16,148 L45,191 L59,212 L59,214 L-3,214 L-13,199 L-42,156 L-56,135 L-73,109 L-67,99 L-57,84 L-49,72 L-20,29 L-2,2 Z " transform="translate(933,137)"/>';
			$output .= '<path d="M0,0 L2,0 L12,15 L22,30 L30,42 L32,47 L25,57 L17,69 L5,87 L4,88 L-58,88 L-53,79 L-46,69 L-38,57 L-10,15 Z " transform="translate(848,263)"/>';
			$output .= '</svg>';

			return $output;
		}
	}
}

SurveyX_Shortcode_Handler::get_instance();
