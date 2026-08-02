<?php

/**
 * Plugin Name:       SurveyX Builder
 * Description:       Create surveys, polls, quizzes, and feedback forms. Fast, lightweight, and optimized to boost responses and user engagement.
 * Plugin URI:        https://surveyx.co/
 * Author:            ThemeRuby
 * Tags:              poll, survey, quiz, form, feedback
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Version:           1.7.0
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
defined( 'SURVEYX_VERSION' ) || define( 'SURVEYX_VERSION', '1.7.0' );
defined( 'SURVEYX_URL' ) || define( 'SURVEYX_URL', plugin_dir_url( __FILE__ ) );
defined( 'SURVEYX_BASENAME' ) || define( 'SURVEYX_BASENAME', plugin_basename( __FILE__ ) );
defined( 'SURVEYX_REST_NAMESPACE' ) || define( 'SURVEYX_REST_NAMESPACE', 'surveyx/v1' );
defined( 'SURVEYX_HOST_BASE' ) || define( 'SURVEYX_HOST_BASE', 'https://surveyx.co' );

if ( ! class_exists( 'SurveyX_Builder', false ) ) {
	class SurveyX_Builder {

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

		public static function get_instance() {
			if ( null === self::$instance ) {
				return new self();
			}

			return self::$instance;
		}

		public function __construct() {
			self::$instance = $this;

			// Activation hooks.
			register_activation_hook( __FILE__, [ $this, 'activation' ] );
			register_deactivation_hook( __FILE__, [ $this, 'deactivation' ] );
			add_action( 'plugins_loaded', [ $this, 'load' ], 10 );

			// Provision tables on newly created multisite sub-sites (always listening,
			// not tied to activation, so a network-active plugin covers sites added later).
			add_action( 'wp_initialize_site', [ $this, 'initialize_new_site' ], 10, 1 );
		}

		/**
		 * Provisions SurveyX tables (and cron) on a newly created multisite sub-site.
		 *
		 * Fires on every new-site creation, so a network-active plugin picks up sites
		 * added after network activation. Mirrors the free activation path (create
		 * tables + register cron). Stands down when Pro is active.
		 *
		 * @param WP_Site $new_site The newly created site object.
		 *
		 * @return void
		 */
		public function initialize_new_site( $new_site ) {
			// Skip if Pro is active to avoid duplicate function declarations.
			if ( defined( 'SURVEYX_PRO_VERSION' ) ) {
				return;
			}

			// Only provision when this plugin is network-active.
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			if ( ! is_plugin_active_for_network( SURVEYX_BASENAME ) ) {
				return;
			}

			switch_to_blog( (int) $new_site->blog_id );

			try {
				require_once SURVEYX_PATH . 'includes/db-migration.php';
				require_once SURVEYX_PATH . 'includes/cron-jobs.php';
				surveyx_create_database();
				surveyx_register_cron_events();
			} catch ( \Throwable $e ) {
				// A failure on one site must not fatal the request that created it.
			} finally {
				restore_current_blog();
			}
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
				// number=0 → no cap (default is 100); skip archived/deleted/spam sites.
				$sites = get_sites(
					[
						'number'   => 0,
						'archived' => 0,
						'deleted'  => 0,
						'spam'     => 0,
					]
				);
				foreach ( $sites as $site ) {
					switch_to_blog( (int) $site->blog_id );
					surveyx_create_database();
					surveyx_register_cron_events();
					restore_current_blog();
				}

				return;
			}

			surveyx_create_database();
			surveyx_register_cron_events();
		}

		/**
		 * Determine if the current context is admin-related and the user is logged in with admin privileges.
		 * Safe to use in 'plugins_loaded' hook.
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
		 * Usable at 'plugins_loaded' (before REST_REQUEST is defined) by matching the
		 * REST route/prefix on the request URI. Used to load the admin/REST bundle for
		 * REST calls (admin SPA + public survey endpoints) while keeping it off plain
		 * front-end page loads.
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
		 * @param bool $network_wide Whether this is a network-wide deactivation (for multisite).
		 *
		 * @return void
		 */
		public function deactivation( $network_wide = false ) {
			// Skip if Pro is active - Pro handles its own cron cleanup.
			if ( defined( 'SURVEYX_PRO_VERSION' ) ) {
				return;
			}

			require_once SURVEYX_PATH . 'includes/cron-jobs.php';

			if ( is_multisite() && $network_wide ) {
				// number=0 → no cap (default is 100); skip archived/deleted/spam sites.
				$sites = get_sites(
					[
						'number'   => 0,
						'archived' => 0,
						'deleted'  => 0,
						'spam'     => 0,
					]
				);
				foreach ( $sites as $site ) {
					switch_to_blog( (int) $site->blog_id );
					surveyx_unregister_cron_events();
					restore_current_blog();
				}

				return;
			}

			surveyx_unregister_cron_events();
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

			// Run database migrations if needed.
			require_once SURVEYX_PATH . 'includes/db-migration.php';

			// Safety-net self-heal: ensure tables exist on a site that never ran the
			// activation hook (e.g. an imported, restored, or cloned multisite sub-site).
			// Gated on a per-site autoloaded flag → a single option read per request once
			// set. surveyx_create_database() is CREATE IF NOT EXISTS + marks migration
			// steps done, so this is a no-op where the schema already exists.
			if ( ! get_option( 'surveyx_db_installed' ) ) {
				surveyx_create_database();
				update_option( 'surveyx_db_installed', SURVEYX_VERSION, true );
			}

			// Load core helper files.
			require_once SURVEYX_PATH . 'includes/admin-helpers.php';
			require_once SURVEYX_PATH . 'includes/date-helper.php';
			require_once SURVEYX_PATH . 'includes/request-helper.php';
			require_once SURVEYX_PATH . 'includes/session-manager.php';
			require_once SURVEYX_PATH . 'includes/cron-jobs.php';
			require_once SURVEYX_PATH . 'includes/response-types.php';
			require_once SURVEYX_PATH . 'includes/database.php';
			require_once SURVEYX_PATH . 'includes/revisions.php';

			// Cache invalidation via semantic actions (big-plugin pattern): mutation
			// sites fire surveyx_survey_saved / surveyx_survey_deleted; the static /init
			// cache subscribes here ONCE. Registered in the always-loaded core (not the
			// deferred admin bundle) so it also fires on REST save paths. SurveyX_Db is
			// already required above.
			add_action( 'surveyx_survey_saved', [ 'SurveyX_Db', 'flush_survey_init_cache' ], 10, 1 );
			add_action( 'surveyx_survey_deleted', [ 'SurveyX_Db', 'flush_survey_init_cache' ], 10, 1 );

			// Admin + REST bundle: only needed in wp-admin, on REST requests (admin SPA
			// and public survey endpoints, since /init is a REST request), or during
			// cron. Skipped on plain front-end page loads where the shortcode renders
			// from the core + client includes below.
			if ( is_admin() || $this->is_rest_request() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				// Load admin menu panel UI.
				require_once SURVEYX_PATH . 'admin/admin-menu.php';

				// Load analytics database class with caching.
				require_once SURVEYX_PATH . 'admin/analytics-database.php';
				require_once SURVEYX_PATH . 'admin/analytics-handler.php';

				// Load admin database class (needed for REST API).
				require_once SURVEYX_PATH . 'admin/database.php';

				// Load API endpoints and REST routes (permissions handled at route level).
				require_once SURVEYX_PATH . 'admin/api-endpoints.php';
				require_once SURVEYX_PATH . 'admin/rest-routes.php';

				// Load hooks (allow revote on update, etc.)
				require_once SURVEYX_PATH . 'admin/hooks.php';
			}

			if ( $this->is_admin_user_context() ) {
				// Load admin-only helper functions.
				require_once SURVEYX_PATH . 'admin/media-helpers.php';

				// Review-request admin notice (registers admin_notices + ajax handler).
				require_once SURVEYX_PATH . 'includes/review-notice.php';
			}

			// Load client helper functions.
			require_once SURVEYX_PATH . 'client/helpers.php';
			require_once SURVEYX_PATH . 'client/captcha-helpers.php';

			// Load data repository and client-side logic.
			require_once SURVEYX_PATH . 'client/client-services.php';

			// Load REST API functionality.
			require_once SURVEYX_PATH . 'client/rest-routes.php';

			// Register the feedback form shortcode.
			require_once SURVEYX_PATH . 'client/form-shortcode.php';
		}
	}
}

SurveyX_Builder::get_instance();
