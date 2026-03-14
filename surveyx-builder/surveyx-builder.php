<?php

/**
 * Plugin Name:       SurveyX Builder
 * Description:       Create surveys, polls, quizzes, and feedback forms. Fast, lightweight, and optimized to boost responses and user engagement.
 * Plugin URI:        https://surveyx.co/
 * Author:            ThemeRuby
 * Tags:              poll, survey, quiz, form, feedback
 * License:           GPLv3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Version:           1.5.1
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
defined( 'SURVEYX_VERSION' ) || define( 'SURVEYX_VERSION', '1.5.1' );
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
		}

		/**
		 * Handles plugin activation for both single and multisite setups.
		 *
		 * @param bool $network Whether this is a network-wide activation (for multisite).
		 *
		 * @return void
		 */
		public function activation( $network ) {
			require_once SURVEYX_PATH . 'includes/db-migration.php';
			require_once SURVEYX_PATH . 'includes/cron-jobs.php';

			if ( is_multisite() && $network ) {
				$sites = get_sites();
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
		 * Handles plugin deactivation, such as cleaning up options.
		 *
		 * @return void
		 */
		public function deactivation() {
			require_once SURVEYX_PATH . 'includes/cron-jobs.php';
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

			// Load core helper files.
			require_once SURVEYX_PATH . 'includes/admin-helpers.php';
			require_once SURVEYX_PATH . 'includes/date-helper.php';
			require_once SURVEYX_PATH . 'includes/request-helper.php';
			require_once SURVEYX_PATH . 'includes/session-manager.php';
			require_once SURVEYX_PATH . 'includes/cron-jobs.php';
			require_once SURVEYX_PATH . 'includes/database.php';
			require_once SURVEYX_PATH . 'includes/revisions.php';

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

			if ( $this->is_admin_user_context() ) {
				// Load admin-only helper functions.
				require_once SURVEYX_PATH . 'admin/media-helpers.php';
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
