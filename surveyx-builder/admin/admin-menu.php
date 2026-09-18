<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Admin_Menu', false ) ) {
	class SurveyX_Admin_Menu {

		/**
		 * Singleton instance.
		 *
		 * @var SurveyX_Admin_Menu
		 */
		private static $instance;

		public const PREFIX = 'surveyx-';

		/** Upgrade/pricing URL reused for the free "Upgrade to Pro" CTA. */
		public const UPGRADE_URL = 'https://surveyx.co/pricing/';

		/** Screen the plugin's SPA renders on; the only one the notice below shows on. */
		private const PANEL_SCREEN_ID = 'toplevel_page_surveyx';

		/** Set once the "surveys need Pro" notice has been dismissed, for good. */
		private const PRO_NOTICE_OPTION = 'surveyx_pro_surveys_notice_dismissed';

		/** Caches the survey count that notice reports. */
		private const PRO_NOTICE_TRANSIENT = 'surveyx_pro_surveys_count';

		/** Query arg the notice's dismiss link carries back. */
		private const PRO_NOTICE_ARG = 'surveyx-dismiss-pro-notice';

		/**
		 * Get the singleton instance.
		 *
		 * @return SurveyX_Admin_Menu
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor - registers the class's hooks.
		 */
		protected function __construct() {
			add_action( 'admin_menu', [ $this, 'register_page_panel' ], 2900 );

			// Register the quick-nav submenu AFTER the parent menu, and — in Pro — after
			// Freemius rebuilds $submenu at WP_FS__LOWEST_PRIORITY (999999999). Free has no
			// Freemius, so the constant is absent and a small offset from the parent is used.
			$submenu_priority = defined( 'WP_FS__LOWEST_PRIORITY' ) ? WP_FS__LOWEST_PRIORITY + 1 : 2905;
			add_action( 'admin_menu', [ $this, 'register_submenu_items' ], $submenu_priority );

			add_filter( 'plugin_action_links_' . SURVEYX_BASENAME, [ $this, 'add_action_links' ] );

			// Unconditional: styles admin-chrome CTAs, see enqueue_common_assets().
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_common_assets' ] );

			// Free-only: only this edition can be the one unable to render a Pro-mode
			// survey, so Pro has no copy of any of it.
			add_action( 'admin_init', [ $this, 'handle_pro_surveys_dismiss' ] );
			add_action( 'admin_notices', [ $this, 'render_pro_surveys_notice' ] );
			add_action( 'surveyx_survey_saved', [ __CLASS__, 'flush_pro_surveys_count' ] );
			add_action( 'surveyx_survey_deleted', [ __CLASS__, 'flush_pro_surveys_count' ] );
		}

		/**
		 * Registers quick-navigation submenu items that deep-link into the hash-routed SPA.
		 *
		 * Hash links to the SAME admin page, so they are pushed straight onto global
		 * $submenu['surveyx'] (3-element form: title, capability, URL) rather than registered
		 * as separate WP pages. The parent-mirror item (slug 'surveyx') is dropped; any
		 * externally-added items (e.g. Freemius Account/Pricing) are kept after the nav.
		 *
		 * @return void
		 */
		public function register_submenu_items() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			global $submenu;

			// add_menu_page() alone never seeds $submenu['surveyx'] — only add_submenu_page()
			// does — so initialise it or the nav items have nothing to attach to. In Pro,
			// Freemius has already populated it.
			if ( ! isset( $submenu['surveyx'] ) ) {
				$submenu['surveyx'] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}

			$base  = 'admin.php?page=surveyx';
			$items = [
				[ esc_html__( 'Dashboard', 'surveyx-builder' ), $base . '#/' ],
				[ esc_html__( 'Surveys', 'surveyx-builder' ), $base . '#/?scroll=surveys' ],
				[ esc_html__( 'Templates', 'surveyx-builder' ), $base . '#/templates' ],
				[ esc_html__( 'Analytics', 'surveyx-builder' ), $base . '#/analytics' ],
				[ esc_html__( 'Themes', 'surveyx-builder' ), $base . '#/themes' ],
				[ esc_html__( 'Settings', 'surveyx-builder' ), $base . '#/settings' ],
				[ esc_html__( 'Import / Export', 'surveyx-builder' ), $base . '#/import-export' ],
				[ esc_html__( 'Helps', 'surveyx-builder' ), $base . '#/helps' ],
			];

			// Slug 'surveyx' is WP's auto parent-mirror item; anything else is external.
			$preserved = [];
			foreach ( $submenu['surveyx'] as $meta ) {
				if ( isset( $meta[2] ) && 'surveyx' === $meta[2] ) {
					continue;
				}
				$preserved[] = $meta;
			}

			$new = [];
			foreach ( $items as $item ) {
				$new[] = [ $item[0], 'manage_options', $item[1] ];
			}

			// Keep any external items (Freemius Account/Pricing) after the nav.
			foreach ( $preserved as $meta ) {
				$new[] = $meta;
			}

			// Free-only: "Upgrade to Pro" CTA (omitted in Pro); styled as a pink pill via CSS.
			if ( ! defined( 'SURVEYX_PRO_VERSION' ) ) {
				$new[] = [ esc_html__( 'Upgrade', 'surveyx-builder' ), 'manage_options', self::UPGRADE_URL ];
			}

			$submenu['surveyx'] = $new; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		/**
		 * Add action links to the plugins page.
		 *
		 * @param array $links Existing action links.
		 * @return array Modified action links.
		 */
		public function add_action_links( $links ) {
			if ( ! defined( 'SURVEYX_PRO_VERSION' ) ) {
				$links[] = '<a href="https://surveyx.co/pricing/" target="_blank" class="surveyx-get-pro">' . esc_html__( 'Get SurveyX Pro', 'surveyx-builder' ) . '</a>';
			}

			return $links;
		}

		/**
		 * How many published surveys this install cannot display.
		 *
		 * A survey saved in Pro mode needs Pro to render: with only the free plugin active
		 * its standalone page 404s and its shortcode shows nothing, so a downgraded site
		 * loses those surveys with no sign of it anywhere. Only `active` surveys count —
		 * a draft is not published and nobody is missing it.
		 *
		 * Measured on read behind a 12-hour transient that flush_pro_surveys_count() drops
		 * on every survey save/delete, so the notice below never counts rows per page load.
		 *
		 * @return int
		 */
		public static function get_pro_surveys_count() {
			$cached = get_transient( self::PRO_NOTICE_TRANSIENT );

			if ( false !== $cached ) {
				return (int) $cached;
			}

			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached in the transient below.
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}surveyx_surveys WHERE s_mode = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from $wpdb->prefix.
					SurveyX_Db::MODE_PRO,
					'active'
				)
			);

			/*
			 * Counting the stamp alone undercounts: an active survey whose s_mode is neither
			 * 'pro' nor 'basic' has never been classified, and most of those are
			 * multi-question, so the notice would stay silent about exactly the surveys the
			 * owner has stopped being able to show. Those rows are resolved through the same
			 * predicate every gate uses, so the number matches what the site actually refuses.
			 *
			 * Bounded by the unstamped set, which the migration step [stamp_survey_modes]
			 * empties; after it runs this loop has nothing to iterate.
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached in the transient below.
			$unclassified = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}surveyx_surveys WHERE s_mode NOT IN ( %s, %s ) AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from $wpdb->prefix.
					SurveyX_Db::MODE_PRO,
					SurveyX_Db::MODE_BASIC,
					'active'
				)
			);

			foreach ( (array) $unclassified as $unclassified_id ) {
				if ( SurveyX_Db::MODE_PRO === SurveyX_Db::classify_survey( $unclassified_id ) ) {
					++$count;
				}
			}

			set_transient( self::PRO_NOTICE_TRANSIENT, $count, 12 * HOUR_IN_SECONDS );

			return $count;
		}

		/**
		 * Drops the cached count so the next plugin screen recounts.
		 *
		 * Hooked to surveyx_survey_saved/deleted, so the notice stops the moment the
		 * count reaches zero.
		 *
		 * @return void
		 */
		public static function flush_pro_surveys_count() {
			delete_transient( self::PRO_NOTICE_TRANSIENT );
		}

		/**
		 * Tells the site owner that some of their surveys need Pro to display.
		 *
		 * Shown on this plugin's own screen only, and only to someone who can act on it.
		 * The screen check runs before anything touches the database, so every other admin
		 * page pays one comparison and no query.
		 *
		 * Deliberately NOT `is-dismissible`: core's X hides the notice for that page view
		 * only, and the condition lasts until Pro comes back, so it would return on the
		 * next load and read as broken. The dismiss link persists the choice instead.
		 *
		 * @return void
		 */
		public function render_pro_surveys_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			if ( is_null( $screen ) || self::PANEL_SCREEN_ID !== $screen->id ) {
				return;
			}

			if ( get_option( self::PRO_NOTICE_OPTION ) ) {
				return;
			}

			$count = self::get_pro_surveys_count();

			if ( $count < 1 ) {
				return;
			}

			$dismiss_url = wp_nonce_url(
				add_query_arg( self::PRO_NOTICE_ARG, 1, admin_url( 'admin.php?page=surveyx' ) ),
				self::PRO_NOTICE_ARG
			);

			$message = sprintf(
				/* translators: %d: number of published surveys that need SurveyX Builder Pro. */
				_n(
					'SurveyX Builder: %d published survey was built with Pro features and needs SurveyX Builder Pro to display. Until Pro is active, its page and shortcode show nothing to visitors.',
					'SurveyX Builder: %d published surveys were built with Pro features and need SurveyX Builder Pro to display. Until Pro is active, their pages and shortcodes show nothing to visitors.',
					$count,
					'surveyx-builder'
				),
				$count
			);

			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s" target="_blank" rel="noopener">%3$s</a></p><p><a href="%4$s">%5$s</a></p></div>',
				esc_html( $message ),
				esc_url( self::UPGRADE_URL ),
				esc_html__( 'Get SurveyX Pro', 'surveyx-builder' ),
				esc_url( $dismiss_url ),
				esc_html__( 'Dismiss this notice', 'surveyx-builder' )
			);
		}

		/**
		 * Records the dismissal of the notice above and returns to a clean URL.
		 *
		 * Stored non-autoloaded: the only screen that reads it is the one the notice
		 * renders on.
		 *
		 * @return void
		 */
		public function handle_pro_surveys_dismiss() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked below, once this is known to be our own request.
			if ( ! isset( $_GET[ self::PRO_NOTICE_ARG ] ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			check_admin_referer( self::PRO_NOTICE_ARG );

			update_option( self::PRO_NOTICE_OPTION, 1, false );

			wp_safe_redirect( admin_url( 'admin.php?page=surveyx' ) );
			exit;
		}

		/**
		 * Enqueue the global admin stylesheet on EVERY wp-admin page.
		 *
		 * It styles the Free-only upgrade CTAs living in the WordPress admin chrome — the
		 * "Upgrade to Pro" submenu pill and the "Get SurveyX Pro" plugins-page link — which
		 * appear on all admin screens, unlike the SPA bundle admin_enqueue() loads on the
		 * SurveyX page alone.
		 *
		 * @return void
		 */
		public function enqueue_common_assets() {
			$ver = SurveyX_Admin_Helpers::is_dev_mode() ? time() : SURVEYX_VERSION;
			wp_enqueue_style( self::PREFIX . 'common', SURVEYX_URL . 'admin/common.css', [], $ver );
		}

		/**
		 * Registers, localizes and enqueues the admin SPA bundle on the SurveyX screen.
		 */
		public function admin_enqueue() {
			$ver = SurveyX_Admin_Helpers::is_dev_mode() ? time() : SURVEYX_VERSION;
			wp_register_style( self::PREFIX . 'vendor-admin', SURVEYX_URL . 'assets/vendor-admin/style.min.css', [], $ver );
			wp_register_style(
				self::PREFIX . 'admin',
				SURVEYX_URL . 'assets/admin/style.min.css',
				[
					self::PREFIX . 'vendor-admin',
				],
				$ver
			);

			wp_register_script( self::PREFIX . 'vendor-admin', SURVEYX_URL . 'assets/vendor-admin/bundle.js', [], $ver, true );
			wp_register_script( self::PREFIX . 'admin', SURVEYX_URL . 'assets/admin/bundle.js', [ 'wp-tinymce', 'wp-i18n' ,  self::PREFIX . 'vendor-admin' ], $ver, true );
			surveyx_pin_chunk_base_url( self::PREFIX . 'admin' );
			$localize_data = apply_filters(
				'surveyx_admin_localize_data',
				[
					'apiUrl'      => esc_url_raw( rest_url( 'surveyx/v1/admin' ) ),
					'surveyXBase' => SURVEYX_HOST_BASE,
					'apiNonce'    => wp_create_nonce( 'wp_rest' ),
					'isRtl'       => is_rtl(),
					'isProMode'   => false,
					// Site-wide standalone-page switch, bootstrapped here rather than shipped
					// with each survey: a survey payload the client posts straight back would
					// persist it into that survey's own settings blob. Gated on the serving
					// class so a partial install never offers a link that can only 404.
					// wp_localize_script() stringifies, hence '1' / '0'.
					'pageEnabled' => class_exists( 'SurveyX_Public_Page' ) && SurveyX_Public_Page::is_enabled() ? '1' : '0',
					// Only the slice the SPA needs BEFORE Settings.vue fetches (which replaces
					// this object wholesale on mount): the editor labels its "Default"
					// cover-layout from `default_cover_layout`. Deliberately NOT the whole
					// blob — that stamps `recaptcha_v2_secret_key` into the HTML of every
					// admin screen. Add a key only when a screen outside Settings reads it.
					'settings'    => [
						'default_cover_layout' => SurveyX_Admin_Db::get_settings()['default_cover_layout'] ?? 'stacked',
					],
					'version'     => SURVEYX_VERSION,
					'adminPage'   => admin_url( 'admin.php?page=surveyx' ),
				]
			);
			wp_localize_script(
				self::PREFIX . 'admin',
				'surveyxAdminConfigs',
				$localize_data
			);

			// The 3rd argument is required: without it WordPress only looks in
			// WP_LANG_DIR/plugins, where nothing but a wordpress.org language pack lands,
			// so the .json files shipped in this plugin's languages/ would never be read.
			wp_set_script_translations( self::PREFIX . 'admin', 'surveyx-builder', SURVEYX_PATH . 'languages' );

			wp_enqueue_media();
			wp_enqueue_style( self::PREFIX . 'admin' );
			wp_enqueue_script( self::PREFIX . 'admin' );
		}

		/**
		 * Registers the plugin's top-level admin page and hooks its asset loading.
		 */
		public function register_page_panel() {
			$panel_hook_suffix = add_menu_page(
				esc_html__( 'SurveyX', 'surveyx-builder' ),
				esc_html__( 'SurveyX', 'surveyx-builder' ),
				'manage_options',
				'surveyx',
				[ $this, 'render_menu_page' ],
				'data:image/svg+xml;base64,' . $this->get_plugin_icon(),
				50 // Position above the Appearance menu (WP core Appearance is 60).
			);

			add_action( 'load-' . $panel_hook_suffix, [ $this, 'load_assets' ] );
		}

		/**
		 * Hooks admin_enqueue(). Called from load-{screen}, so the SPA bundle is enqueued
		 * on the SurveyX screen only.
		 *
		 * @return void
		 */
		public function load_assets() {
			add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue' ] );
		}

		/**
		 * Returns the base64-encoded SVG used as the admin menu icon.
		 */
		public function get_plugin_icon() {
			return 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2MDAgNjAwIj4NCjxnIGZpbGw9IiNhN2FhYWQiPg0KPHBhdGggZD0iTTAsMCBMMTAyLDAgTDExMCwxMSBMMTM5LDU1IEwxNjgsOTggTDE4OSwxMzAgTDIxNywxNzIgTDIxNywxNzQgTDIxOSwxNzQgTDIxOSwxNzcgTDIyMSwxNzcgTDIyMSwxODAgTDIyMywxODAgTDIyMywxODMgTDIyNSwxODMgTDI0NSwyMTMgTDI0NSwyMTggTDIzOSwyMjcgTDIzNywyMjcgTDIzNywyMzAgTDIzNSwyMzAgTDIzNSwyMzMgTDIzMywyMzMgTDIzMSwyMzggTDIxMywyNjUgTDE5NiwyOTEgTDE5NCwyOTEgTDE2NSwyNDcgTDE2MiwyNDMgTDE2MiwyNDEgTDE2MCwyNDEgTDE2MCwyMzggTDE1OCwyMzggTDE1OCwyMzUgTDE1NiwyMzUgTDEyNywxOTEgTDk4LDE0OCBMNjksMTA0IEw0MCw2MSBMMjUsMzggTDIxLDMyIEwyMSwzMCBMMTksMzAgTDE5LDI3IEwxNywyNyBMMTcsMjQgTDE1LDI0IEwwLDEgWiAiIHRyYW5zZm9ybT0idHJhbnNsYXRlKDc5LDUwKSIvPg0KPHBhdGggZD0iTTAsMCBMMjgsMCBMNTcsNDMgTDY3LDU4IEw2Nyw2MCBMNjksNjAgTDk4LDEwNCBMMTI3LDE0NyBMMTQ4LDE3OSBMMTQzLDE4OCBMMTE0LDIzMSBMODUsMjc1IEw2MywzMDggTDYxLDMwOCBMNjEsMzExIEw1OSwzMTEgTDU3LDMxNiBMMzIsMzUzIEwzMSwzNTQgTDIsMzU0IEw3LDM0NSBMMzYsMzAyIEw1MSwyNzkgTDgwLDIzNSBMMTA5LDE5MiBMMTE3LDE4MCBMMTEyLDE3MSBMODMsMTI4IEw1NCw4NCBMMjUsNDEgTDcsMTQgTDAsNCBaICIgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoMTA3LDE5MSkiLz4NCjxwYXRoIGQ9Ik0wLDAgTDEwMSwwIEw5OSw1IEw3MCw0OCBMNDEsOTIgTDE2LDEyOSBMLTEzLDE3MiBMLTE3LDE3OCBMLTE2LDE4MiBMMTMsMjI2IEwyNCwyNDIgTDUzLDI4NSBMODIsMzI5IEw5OCwzNTMgTDk4LDM1NCBMLTQsMzU0IEwtMjgsMzE4IEwtMjgsMzE2IEwtMzAsMzE2IEwtMzAsMzEzIEwtMzIsMzEzIEwtMzIsMzEwIEwtMzQsMzEwIEwtNjMsMjY2IEwtOTIsMjIzIEwtMTA2LDIwMSBMLTEyMCwxODAgTC0xMTgsMTc1IEwtODksMTMyIEwtNjAsODggTC00MCw1OCBMLTM4LDU4IEwtMzgsNTUgTC0zNiw1NSBMLTM0LDUwIEwtNSw3IFogIiB0cmFuc2Zvcm09InRyYW5zbGF0ZSg0MTMsMTkxKSIvPg0KPHBhdGggZD0iTTAsMCBMMiwwIEwzMSw0NCBMNDYsNjYgTDQ2LDY4IEw0OCw2OCBMNDgsNzEgTDUwLDcxIEw1MSw3NyBMMjIsMTIxIEwyMCwxMjQgTDE4LDEyNCBMMTgsMTI3IEwxNiwxMjcgTDE0LDEzMiBMNiwxNDQgTDUsMTQ1IEwtOTYsMTQ1IEwtOTQsMTQwIEwtNjUsOTcgTC00OSw3MiBMLTQ3LDY5IEwtNDUsNjkgTC00NSw2NiBMLTQzLDY2IEwtNDMsNjMgTC00MSw2MyBMLTM5LDU4IEwtMTAsMTUgWiAiIHRyYW5zZm9ybT0idHJhbnNsYXRlKDI3Myw0MDApIi8+DQo8L2c+DQo8L3N2Zz4=';
		}

		/**
		 * Renders the admin page from the dashboard template.
		 */
		public function render_menu_page() {
			include SURVEYX_PATH . 'admin/dashboard-template.php';
		}
	}
}

SurveyX_Admin_Menu::get_instance();
