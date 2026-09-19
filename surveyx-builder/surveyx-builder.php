<?php

/**
 * Plugin Name:       SurveyX Builder
 * Description:       Build surveys, polls, quizzes and feedback forms in a visual editor. Unlimited surveys and responses, 7 question types, no coding.
 * Plugin URI:        https://surveyx.co/
 * Author:            ThemeRuby
 * Tags:              poll, survey, quiz, form, feedback
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Version:           2.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author URI:        https://themeruby.com/
 * Text Domain:       surveyx-builder
 * Domain Path:       /languages
 *
 * @package           surveyx-builder
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or any later version.
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */
defined( 'ABSPATH' ) || exit;

defined( 'SURVEYX_PATH' ) || define( 'SURVEYX_PATH', plugin_dir_path( __FILE__ ) );
defined( 'SURVEYX_VERSION' ) || define( 'SURVEYX_VERSION', '2.0.1' );
defined( 'SURVEYX_URL' ) || define( 'SURVEYX_URL', plugin_dir_url( __FILE__ ) );
defined( 'SURVEYX_BASENAME' ) || define( 'SURVEYX_BASENAME', plugin_basename( __FILE__ ) );
defined( 'SURVEYX_REST_NAMESPACE' ) || define( 'SURVEYX_REST_NAMESPACE', 'surveyx/v1' );
defined( 'SURVEYX_HOST_BASE' ) || define( 'SURVEYX_HOST_BASE', 'https://surveyx.co' );

// Declared by BOTH editions. Activating Pro while Free is still active loads both
// main files in one request, and without this guard the redeclaration is a fatal that
// WordPress reports only as "the plugin triggered a fatal error" - the exact path a
// customer takes when they upgrade. The body resolves the edition at call time, so
// whichever copy wins behaves correctly for the plugin that is actually running.
if ( ! function_exists( 'surveyx_pin_chunk_base_url' ) ) {
	/**
	 * Pin webpack's lazy-chunk base URL onto a registered script handle.
	 *
	 * `output.publicPath` is 'auto', so webpack derives chunk URLs from the location of
	 * the script that is executing. An optimiser that concatenates JS and re-serves it
	 * from its own cache directory - Autoptimize, WP Rocket, LiteSpeed, W3 Total Cache -
	 * points every dynamic import at a folder holding no chunks, and the respondent hits
	 * a ChunkLoadError on the first lazily-loaded question type.
	 *
	 * Attached at REGISTRATION with wp_add_inline_script( ..., 'before' ) so it travels
	 * with the handle wherever it is later enqueued, and is emitted immediately above
	 * the script tag. resources/shared/public-path.js reads it.
	 *
	 * @param string $handle Registered script handle.
	 * @return void
	 */
	function surveyx_pin_chunk_base_url( $handle ) {
		static $script = null;
		static $pinned = [];

		// wp_add_inline_script() APPENDS - it has no dedupe of its own - and
		// register_scripts() is reachable from the enqueue hook, the shortcode and the
		// standalone page, so without this the same assignment is printed once per call.
		if ( isset( $pinned[ $handle ] ) ) {
			return;
		}

		if ( null === $script ) {
			$base   = ( defined( 'SURVEYX_PRO_VERSION' ) ? SURVEYX_PRO_URL : SURVEYX_URL ) . 'assets/';
			$script = 'window.surveyxAssetsUrl=' . wp_json_encode( esc_url_raw( $base ) ) . ';';
		}

		$pinned[ $handle ] = wp_add_inline_script( $handle, $script, 'before' );
	}
}


if ( ! class_exists( 'SurveyX_Builder', false ) ) {
	/**
	 * Main SurveyX Builder class.
	 */
	class SurveyX_Builder {

		/**
		 * Singleton instance.
		 *
		 * @var SurveyX_Builder
		 */
		private static $instance;

		/**
		 * Disable object cloning.
		 *
		 * @return void
		 */
		public function __clone() {
		}

		/**
		 * Disable unserializing of the class.
		 *
		 * @return void
		 */
		public function __wakeup() {
		}

		/**
		 * Get the singleton instance.
		 *
		 * @return SurveyX_Builder
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				return new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor - sets up hooks.
		 *
		 * @return void
		 */
		public function __construct() {
			self::$instance = $this;

			register_activation_hook( __FILE__, [ $this, 'activation' ] );
			register_deactivation_hook( __FILE__, [ $this, 'deactivation' ] );
			add_action( 'plugins_loaded', [ $this, 'load' ], 10 );
		}

		/**
		 * Handles plugin activation for both single and multisite setups.
		 *
		 * @param bool $network Whether this is a network-wide activation (for multisite).
		 *
		 * @return void
		 */
		public function activation( $network ) {
			// Skip if Pro is active to avoid duplicate function declarations.
			if ( defined( 'SURVEYX_PRO_VERSION' ) ) {
				return;
			}

			require_once SURVEYX_PATH . 'includes/db-migration.php';
			require_once SURVEYX_PATH . 'includes/cron-jobs.php';

			if ( is_multisite() && $network ) {
				$sites = get_sites();
				foreach ( $sites as $site ) {
					switch_to_blog( (int) $site->blog_id );
					surveyx_create_database();
					surveyx_register_cron_events();
					$this->clear_rewrite_stamp();
					restore_current_blog();
				}

				return;
			}

			surveyx_create_database();
			surveyx_register_cron_events();
			$this->clear_rewrite_stamp();
		}

		/**
		 * Clears the survey-page rewrite stamp so the next request's
		 * SurveyX_Public_Page::maybe_flush_rules() performs exactly one flush.
		 *
		 * Otherwise a deactivate → change permalinks → reactivate cycle leaves the stamp
		 * matching a rule set WordPress already discarded, the flush is skipped, and every
		 * /survey/{id}/ URL 404s with no recovery path. Deleted by literal option name
		 * because SurveyX_Public_Page is required only from load() on 'plugins_loaded' and
		 * may not exist yet during activation.
		 *
		 * @return void
		 */
		private function clear_rewrite_stamp() {
			if ( class_exists( 'SurveyX_Public_Page', false ) ) {
				delete_option( SurveyX_Public_Page::REWRITE_OPTION );
			} else {
				delete_option( 'surveyx_rewrite_version' );
			}
		}

		/**
		 * Whether the current user is a logged-in administrator.
		 *
		 * Primes wp_get_current_user() first, so it is safe to call on 'plugins_loaded'.
		 *
		 * @return bool
		 */
		public function is_admin_user_context() {
			wp_get_current_user();

			return is_user_logged_in() && current_user_can( 'manage_options' );
		}

		/**
		 * Whether the current request is a REST API request.
		 *
		 * Usable at 'plugins_loaded', before REST_REQUEST is defined, by matching the REST
		 * prefix on the request URI. Gates the admin/REST bundle so it loads for the admin
		 * SPA and the public survey endpoints but not for plain front-end page loads.
		 *
		 * @return bool
		 */
		private function is_rest_request() {
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return true;
			}

			if ( ! empty( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}

			if ( empty( $_SERVER['REQUEST_URI'] ) || ! function_exists( 'rest_get_url_prefix' ) ) {
				return false;
			}

			$rest_prefix = trailingslashit( rest_get_url_prefix() );
			$request_uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );

			return false !== strpos( $request_uri, $rest_prefix );
		}

		/**
		 * Handles plugin deactivation, such as cleaning up options.
		 *
		 * Cron events and the stored rewrite rules are both per-blog, so a NETWORK
		 * deactivation has to walk every site; see clear_rewrite_rules() for what a
		 * missed blog is left holding.
		 *
		 * @param bool $network_deactivating Whether this is a network-wide deactivation.
		 *
		 * @return void
		 */
		public function deactivation( $network_deactivating = false ) {
			// Skip if Pro is active - Pro owns the cron events and the survey page
			// rewrite rules while it runs, so Free must not clear either.
			if ( defined( 'SURVEYX_PRO_VERSION' ) ) {
				return;
			}

			require_once SURVEYX_PATH . 'includes/cron-jobs.php';

			if ( is_multisite() && $network_deactivating ) {
				$sites = get_sites();
				foreach ( $sites as $site ) {
					switch_to_blog( (int) $site->blog_id );
					surveyx_unregister_cron_events();
					$this->clear_rewrite_rules();
					$this->clear_rewrite_stamp();
					restore_current_blog();
				}

				return;
			}

			surveyx_unregister_cron_events();

			$this->clear_rewrite_rules();
			$this->clear_rewrite_stamp();
		}

		/**
		 * Drops the stored rewrite rules so the survey page rules leave with the plugin.
		 *
		 * Otherwise the /{base}/{id}/{slug}/ rule outlives the plugin while `surveyx_id` is
		 * no longer a registered query var, so WordPress drops the value and resolves every
		 * published survey link to the blog home with a 200 instead of a 404. Deleted rather
		 * than flushed because 'init' already registered those rules on this request, so
		 * flush_rewrite_rules() would only store them again; an empty option makes WordPress
		 * rebuild on the next request, when this plugin registers none.
		 *
		 * @return void
		 */
		private function clear_rewrite_rules() {
			delete_option( 'rewrite_rules' );
		}

		/**
		 * Registers the plugin's own languages/ directory so bundled translations load.
		 *
		 * On `init`, not `plugins_loaded`: the locale is not settled until `init`
		 * (`determine_locale()` depends on the current user) and since WP 6.7 loading a text
		 * domain earlier raises _doing_it_wrong(). Without the call WordPress looks only in
		 * WP_LANG_DIR/plugins and never finds a .mo shipped inside this plugin.
		 *
		 * @return void
		 */
		public function load_textdomain() {
			load_plugin_textdomain( 'surveyx-builder', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}

		/**
		 * Loads the necessary plugin files based on the context (admin or frontend).
		 *
		 * @return void
		 */
		public function load() {

			if ( defined( 'SURVEYX_PRO_VERSION' ) ) {
				return;
			}

			// Below the Pro guard so the inert Free plugin never loads a text domain
			// Pro is already serving.
			add_action( 'init', [ $this, 'load_textdomain' ] );

			require_once SURVEYX_PATH . 'includes/db-migration.php';

			require_once SURVEYX_PATH . 'includes/admin-helpers.php';
			require_once SURVEYX_PATH . 'includes/date-helper.php';
			require_once SURVEYX_PATH . 'includes/request-helper.php';
			require_once SURVEYX_PATH . 'includes/session-manager.php';
			require_once SURVEYX_PATH . 'includes/cron-jobs.php';
			require_once SURVEYX_PATH . 'includes/database.php';
			require_once SURVEYX_PATH . 'includes/revisions.php';

			// Cache invalidation by semantic action: mutation sites fire
			// surveyx_survey_saved / surveyx_survey_deleted, the static /init cache
			// subscribes here ONCE. Subscribed from the always-loaded core rather than the
			// admin bundle below so it also fires on REST save paths.
			add_action( 'surveyx_survey_saved', [ 'SurveyX_Db', 'flush_survey_init_cache' ], 10, 1 );
			add_action( 'surveyx_survey_deleted', [ 'SurveyX_Db', 'flush_survey_init_cache' ], 10, 1 );

			// Admin + REST bundle: needed in wp-admin, on REST requests (admin SPA and the
			// public survey endpoints, since /init is REST), and during cron. A plain
			// front-end page load renders the shortcode from the includes below instead.
			if ( is_admin() || $this->is_rest_request() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				require_once SURVEYX_PATH . 'admin/admin-menu.php';
				require_once SURVEYX_PATH . 'admin/analytics-database.php';
				require_once SURVEYX_PATH . 'admin/database.php';
				require_once SURVEYX_PATH . 'admin/api-endpoints.php';
				require_once SURVEYX_PATH . 'admin/rest-routes.php';
				require_once SURVEYX_PATH . 'admin/hooks.php';
			}

			if ( $this->is_admin_user_context() ) {
				require_once SURVEYX_PATH . 'admin/media-helpers.php';

				// Registers admin_notices + an ajax handler on include.
				require_once SURVEYX_PATH . 'includes/review-notice.php';
			}

			require_once SURVEYX_PATH . 'client/helpers.php';
			require_once SURVEYX_PATH . 'client/captcha-helpers.php';
			require_once SURVEYX_PATH . 'client/client-services.php';
			require_once SURVEYX_PATH . 'client/rest-routes.php';
			require_once SURVEYX_PATH . 'client/form-shortcode.php';
			require_once SURVEYX_PATH . 'client/public-page.php';
		}
	}
}

SurveyX_Builder::get_instance();
