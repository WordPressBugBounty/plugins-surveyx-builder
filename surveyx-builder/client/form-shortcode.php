<?php

/**
 * SurveyX Shortcode Handler - Refactored
 *
 * Handles [surveyx id="X"] shortcode rendering.
 * New approach:
 * - Renders minimal HTML placeholder with captcha (if enabled)
 * - Vue app fetches survey data via REST API after captcha verification
 * - Avoids caching issues with wp_create_nonce
 *
 */

// Don't load directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Shortcode_Handler', false ) ) {
	class SurveyX_Shortcode_Handler {

		private static $instance;
		public const PREFIX = 'surveyx-';

		/**
		 * Stores survey IDs found on the page for batch fetching
		 *
		 * @var array
		 */
		private static $survey_ids = [];

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
		 * Only loads ONE captcha script based on priority (Turnstile > reCAPTCHA v2).
		 *
		 * @return void
		 */
		public function register_scripts() {
			// Register styles
			wp_register_style( self::PREFIX . 'client', SURVEYX_URL . 'assets/client/style.min.css', [], SURVEYX_VERSION );

			// Register scripts (captcha scripts are loaded dynamically in Vue component)
			wp_register_script( self::PREFIX . 'vendor', SURVEYX_URL . 'assets/vendor/bundle.js', [], SURVEYX_VERSION, true );
			wp_register_script( self::PREFIX . 'client', SURVEYX_URL . 'assets/client/bundle.js', [ 'wp-i18n', self::PREFIX . 'vendor' ], SURVEYX_VERSION, true );

			// Load JavaScript translations
			wp_set_script_translations( self::PREFIX . 'client', 'surveyx-builder' );
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

			if ( ! wp_script_is( self::PREFIX . 'client', 'registered' ) ) {
				$this->register_scripts();
			}

			// Track survey ID for multi-shortcode pages
			$survey_id = absint( $attr['id'] ?? 0 );

			if ( $survey_id && ! in_array( $survey_id, self::$survey_ids, true ) ) {
				self::$survey_ids[] = $survey_id;
			}

			// Only configure once (wp_localize_script can only be called once per script handle)
			if ( ! wp_script_is( self::PREFIX . 'client', 'done' ) ) {
				// Get captcha info
				$settings     = SurveyX_Db::get_settings();
				$captcha_info = SurveyX_Captcha_Helpers::get_active_captcha( $settings );

				// Check if ANY survey on page requires login
				$nonce = $this->get_rest_nonce_for_page();

				$config = [
					'apiUrl'         => esc_url_raw( rest_url( 'surveyx/v1' ) ),
					'captchaType'    => $captcha_info['type'] ?? 'none',
					'captchaSiteKey' => $captcha_info['site_key'] ?? '',
					'restNonce'      => $nonce,
				];

				wp_localize_script( self::PREFIX . 'client', 'surveyxConfigs', $config );

				// Enqueue scripts only once
				wp_enqueue_style( self::PREFIX . 'client' );
				wp_enqueue_script( self::PREFIX . 'client' );
			}

			return $output;
		}

		/**
		 * Get REST API nonce if ANY survey on page requires login.
		 * Checks all tracked survey IDs and generates nonce if at least one requires authentication.
		 *
		 * @return string|null Nonce if any survey requires login, null otherwise.
		 */
		private function get_rest_nonce_for_page() {
			if ( empty( self::$survey_ids ) ) {
				return null;
			}

			// Check if ANY survey on the page requires login
			foreach ( self::$survey_ids as $survey_id ) {
				$survey_settings = SurveyX_Db::get_survey_settings( $survey_id );

				if ( is_null( $survey_settings ) ) {
					continue;
				}

				$require_logged_in = ! empty( $survey_settings['require_logged_in'] );

				if ( $require_logged_in ) {
					// At least one survey requires login, generate nonce
					return wp_create_nonce( 'wp_rest' );
				}
			}

			return null;
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

			// Extract and validate size attribute
			$size        = ! empty( $atts['size'] ) ? sanitize_key( $atts['size'] ) : 'l';
			$valid_sizes = [ 'xs', 's', 'm', 'l', 'xl' ];
			if ( ! in_array( $size, $valid_sizes, true ) ) {
				$size = 'l';
			}
			$size_class = 'survey-size-' . $size;

			if ( empty( $survey_id ) ) {
				if ( current_user_can( 'manage_options' ) ) {
					return self::render_notice(
						esc_html__( 'Survey ID Missing', 'surveyx-builder' ),
						esc_html__( 'The shortcode is missing a survey ID. Use format: [surveyx id="123"]', 'surveyx-builder' )
					);
				}

				return '';
			}

			// Check if survey exists
			if ( ! SurveyX_Db::survey_exists( $survey_id ) ) {
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

			// Check if pro survey but free version is active
			$survey_mode = SurveyX_Db::get_survey_mode( $survey_id );
			if ( 'pro' === $survey_mode && current_user_can( 'manage_options' ) ) {
				return self::render_notice(
					esc_html__( 'Pro Survey', 'surveyx-builder' ),
					esc_html__( 'This survey uses Pro features. Please activate SurveyX Pro to display it.', 'surveyx-builder' )
				) . $this->render_admin_edit_links( $survey_id );
			}

			// Get survey settings to determine theme
			$survey_settings = SurveyX_Db::get_survey_settings( $survey_id );

			// Check if survey settings could be retrieved
			if ( is_null( $survey_settings ) ) {
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

			$theme       = sanitize_key( $survey_settings['theme'] ?? 'normal' );
			$theme_class = 'survey-theme-' . $theme;

			// Render placeholder with branded loader - Vue will replace this after loading
			$output  = '<div class="surveyx surveyx-shortcode ' . esc_attr( $theme_class ) . ' ' . esc_attr( $size_class ) . '" data-survey-id="' . esc_attr( $survey_id ) . '">';
			$output .= self::render_loader();
			$output .= '</div>';

			// Add edit links for admins
			$output .= $this->render_admin_edit_links( $survey_id );

			return $output;
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

			$edit_url = admin_url( 'admin.php?page=surveyx#/survey/' . $survey_id . '/general' );

			$output  = '<div class="surveyx-edit-links">';
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
                        <svg class="surveyx-notice-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
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
