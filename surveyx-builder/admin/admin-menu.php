<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Admin_Menu', false ) ) {
	class SurveyX_Admin_Menu {

		private static $instance;

		public const PREFIX = 'surveyx-';

		/** Upgrade/pricing URL reused for the free "Upgrade to Pro" CTA. */
		public const UPGRADE_URL = 'https://surveyx.co/pricing/';

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		protected function __construct() {
			add_action( 'admin_menu', [ $this, 'register_page_panel' ], 2900 );

			// Register the quick-nav submenu AFTER the parent menu, and — in Pro — after
			// Freemius rebuilds $submenu at WP_FS__LOWEST_PRIORITY (999999999). Free has no
			// Freemius, so the constant is absent and a small offset from the parent is used.
			$submenu_priority = defined( 'WP_FS__LOWEST_PRIORITY' ) ? WP_FS__LOWEST_PRIORITY + 1 : 2905;
			add_action( 'admin_menu', [ $this, 'register_submenu_items' ], $submenu_priority );

			add_filter( 'plugin_action_links_' . SURVEYX_BASENAME, [ $this, 'add_action_links' ] );

			// Global admin CSS: the Free-only upgrade CTAs live in the admin chrome
			// (menu + plugins page) shown on every screen, so this loads everywhere.
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_common_assets' ] );
		}

		/**
		 * Registers quick-navigation submenu items that deep-link into the hash-routed SPA.
		 *
		 * These are hash links to the SAME admin page, so they are pushed directly onto the
		 * global $submenu['surveyx'] array (3-element form: title, capability, URL) rather
		 * than registered as separate WP pages. The default parent-mirror item (slug
		 * 'surveyx') is dropped; any externally-added items (e.g. Freemius Account/Pricing)
		 * are preserved and kept after the nav. Runs late so it wins over Freemius.
		 *
		 * @return void
		 */
		public function register_submenu_items() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			global $submenu;

			// The parent menu is registered via add_menu_page() only, so WordPress
			// never seeds $submenu['surveyx'] (that happens only when an
			// add_submenu_page() is used). Initialise it so our nav items attach in
			// the free plugin too; in Pro, Freemius has already populated it.
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

			// Preserve externally-added items (e.g. Freemius), dropping the auto parent-mirror.
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
		 * Enqueue the global admin stylesheet on EVERY wp-admin page.
		 *
		 * Unlike admin_enqueue() (which loads the SPA bundle only on the SurveyX
		 * page), this small static stylesheet styles the upgrade CTAs that live in
		 * the WordPress admin chrome — the "Upgrade to Pro" submenu pill and the
		 * "Get SurveyX Pro" plugins-page link — which appear on all admin screens.
		 *
		 * @return void
		 */
		public function enqueue_common_assets() {
			$ver = SurveyX_Admin_Helpers::is_dev_mode() ? time() : SURVEYX_VERSION;
			wp_enqueue_style( self::PREFIX . 'common', SURVEYX_URL . 'admin/common.css', [], $ver );
		}

		/**
		 * Enqueues the necessary styles and scripts for the SurveyX Builder admin panel.
		 *
		 * It registers styles and scripts for the admin panel, including vendor and admin-specific files.
		 * It also localizes script data for use in the front-end, such as API URLs, nonces, and other settings.
		 *
		 * The localized data is passed to the JavaScript files, allowing them to interact with the WordPress backend.
		 * After registering and localizing the scripts, it enqueues the styles and scripts for the admin panel.
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
			$localize_data = apply_filters(
				'surveyx_admin_localize_data',
				[
					'apiUrl'      => esc_url_raw( rest_url( 'surveyx/v1/admin' ) ),
					'surveyXBase' => SURVEYX_HOST_BASE,
					'apiNonce'    => wp_create_nonce( 'wp_rest' ),
					'isRtl'       => is_rtl(),
					'isProMode'   => false,
					'version'     => SURVEYX_VERSION,
					'adminPage'   => admin_url( 'admin.php?page=surveyx' ),
				]
			);
			wp_localize_script(
				self::PREFIX . 'admin',
				'surveyxAdminConfigs',
				$localize_data
			);

			// Load JavaScript translations
			wp_set_script_translations( self::PREFIX . 'admin', 'surveyx-builder' );

			wp_enqueue_media();
			wp_enqueue_style( self::PREFIX . 'admin' );
			wp_enqueue_script( self::PREFIX . 'admin' );
		}

		/**
		 * Registers the SurveyX Builder page panel in the WordPress admin.
		 *
		 * The function also hooks the `load_assets` method to enqueue assets for the page panel.
		 *
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
		 * Registers the action to enqueue admin scripts and styles.
		 *
		 * This function hooks into the `admin_enqueue_scripts` action to enqueue the necessary
		 * scripts and styles for the WordPress admin panel. It calls the `admin_enqueue` method
		 * to handle the actual enqueueing of assets.
		 *
		 * @return void
		 */
		public function load_assets() {
			add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue' ] );
		}

		/**
		 * Returns the base64-encoded icon for the plugin.
		 *
		 * This function returns the plugin icon in the form of a base64-encoded PNG image string,
		 * which can be used for displaying the icon in the plugin interface.
		 *
		 */
		public function get_plugin_icon() {
			return 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2MDAgNjAwIj4NCjxnIGZpbGw9IiNhN2FhYWQiPg0KPHBhdGggZD0iTTAsMCBMMTAyLDAgTDExMCwxMSBMMTM5LDU1IEwxNjgsOTggTDE4OSwxMzAgTDIxNywxNzIgTDIxNywxNzQgTDIxOSwxNzQgTDIxOSwxNzcgTDIyMSwxNzcgTDIyMSwxODAgTDIyMywxODAgTDIyMywxODMgTDIyNSwxODMgTDI0NSwyMTMgTDI0NSwyMTggTDIzOSwyMjcgTDIzNywyMjcgTDIzNywyMzAgTDIzNSwyMzAgTDIzNSwyMzMgTDIzMywyMzMgTDIzMSwyMzggTDIxMywyNjUgTDE5NiwyOTEgTDE5NCwyOTEgTDE2NSwyNDcgTDE2MiwyNDMgTDE2MiwyNDEgTDE2MCwyNDEgTDE2MCwyMzggTDE1OCwyMzggTDE1OCwyMzUgTDE1NiwyMzUgTDEyNywxOTEgTDk4LDE0OCBMNjksMTA0IEw0MCw2MSBMMjUsMzggTDIxLDMyIEwyMSwzMCBMMTksMzAgTDE5LDI3IEwxNywyNyBMMTcsMjQgTDE1LDI0IEwwLDEgWiAiIHRyYW5zZm9ybT0idHJhbnNsYXRlKDc5LDUwKSIvPg0KPHBhdGggZD0iTTAsMCBMMjgsMCBMNTcsNDMgTDY3LDU4IEw2Nyw2MCBMNjksNjAgTDk4LDEwNCBMMTI3LDE0NyBMMTQ4LDE3OSBMMTQzLDE4OCBMMTE0LDIzMSBMODUsMjc1IEw2MywzMDggTDYxLDMwOCBMNjEsMzExIEw1OSwzMTEgTDU3LDMxNiBMMzIsMzUzIEwzMSwzNTQgTDIsMzU0IEw3LDM0NSBMMzYsMzAyIEw1MSwyNzkgTDgwLDIzNSBMMTA5LDE5MiBMMTE3LDE4MCBMMTEyLDE3MSBMODMsMTI4IEw1NCw4NCBMMjUsNDEgTDcsMTQgTDAsNCBaICIgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoMTA3LDE5MSkiLz4NCjxwYXRoIGQ9Ik0wLDAgTDEwMSwwIEw5OSw1IEw3MCw0OCBMNDEsOTIgTDE2LDEyOSBMLTEzLDE3MiBMLTE3LDE3OCBMLTE2LDE4MiBMMTMsMjI2IEwyNCwyNDIgTDUzLDI4NSBMODIsMzI5IEw5OCwzNTMgTDk4LDM1NCBMLTQsMzU0IEwtMjgsMzE4IEwtMjgsMzE2IEwtMzAsMzE2IEwtMzAsMzEzIEwtMzIsMzEzIEwtMzIsMzEwIEwtMzQsMzEwIEwtNjMsMjY2IEwtOTIsMjIzIEwtMTA2LDIwMSBMLTEyMCwxODAgTC0xMTgsMTc1IEwtODksMTMyIEwtNjAsODggTC00MCw1OCBMLTM4LDU4IEwtMzgsNTUgTC0zNiw1NSBMLTM0LDUwIEwtNSw3IFogIiB0cmFuc2Zvcm09InRyYW5zbGF0ZSg0MTMsMTkxKSIvPg0KPHBhdGggZD0iTTAsMCBMMiwwIEwzMSw0NCBMNDYsNjYgTDQ2LDY4IEw0OCw2OCBMNDgsNzEgTDUwLDcxIEw1MSw3NyBMMjIsMTIxIEwyMCwxMjQgTDE4LDEyNCBMMTgsMTI3IEwxNiwxMjcgTDE0LDEzMiBMNiwxNDQgTDUsMTQ1IEwtOTYsMTQ1IEwtOTQsMTQwIEwtNjUsOTcgTC00OSw3MiBMLTQ3LDY5IEwtNDUsNjkgTC00NSw2NiBMLTQzLDY2IEwtNDMsNjMgTC00MSw2MyBMLTM5LDU4IEwtMTAsMTUgWiAiIHRyYW5zZm9ybT0idHJhbnNsYXRlKDI3Myw0MDApIi8+DQo8L2c+DQo8L3N2Zz4=';
		}

		/**
		 * Renders the admin menu page for the plugin.
		 *
		 * Then, it includes the dashboard template file from the plugin's admin directory.
		 *
		 */
		public function render_menu_page() {
			include SURVEYX_PATH . 'admin/dashboard-template.php';
		}
	}
}

SurveyX_Admin_Menu::get_instance();
