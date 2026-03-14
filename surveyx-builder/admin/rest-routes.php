<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Admin_Routes', false ) ) {
	class SurveyX_Admin_Routes {

		private static $instance;

		public const ROUTE_NAMESPACE = SURVEYX_REST_NAMESPACE;
		protected $api;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public function __construct() {
			self::$instance = $this;
			$this->api      = SurveyX_Admin_API::get_instance();

			add_action( 'rest_api_init', [ $this, 'register_rest_routes' ], 10 );
		}

		/**
		 * Permission callback to check if user can manage options.
		 * Verifies user has 'manage_options' capability (administrator role).
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
		 */
		public function check_manage_permission( WP_REST_Request $request ) {
			// Check user capability
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'rest_forbidden',
					esc_html__( 'Sorry, you are not allowed to access this resource.', 'surveyx-builder' ),
					[ 'status' => 403 ]
				);
			}

			return true;
		}

		/**
		 * Register all admin REST API routes.
		 *
		 * SECURITY NOTE: All admin endpoints use check_manage_permission() which verifies
		 * user has 'manage_options' capability (WordPress administrator role).
		 * These endpoints are NOT public - they require authenticated admin access.
		 *
		 * @return void
		 */
		public function register_rest_routes() {
			/**
			 * Get survey data for editor.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/data',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'get_survey_editor_data' ],
					'permission_callback' => [ $this, 'check_manage_permission' ],
				]
			);

			/**
			 * Get lightweight survey header info for dashboard.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/header-info',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'get_survey_header_info' ],
					'permission_callback' => [ $this, 'check_manage_permission' ],
				]
			);

			/**
			 * Get full survey data for settings page.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/settings',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'get_survey_settings' ],
					'permission_callback' => [ $this, 'check_manage_permission' ],
				]
			);

			/**
			 * Get paginated list of surveys for admin dashboard.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/list',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'get_surveys_paginated' ],
					'permission_callback' => [ $this, 'check_manage_permission' ],
				]
			);

			/**
			 * Create a new survey.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/create',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'create_survey' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Delete a survey permanently.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/delete',
				[
					[
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => [ $this->api, 'delete_survey' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Quick update survey status (publish/unpublish).
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/live',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'quick_update_survey' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Update survey content and settings.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/update',
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this->api, 'update_survey' ],
					'permission_callback' => [ $this, 'check_manage_permission' ],
				]
			);

			/**
			 * Fetch survey templates from remote server (surveyx.co).
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/template/all',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'fetch_remote_templates' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Start template import process with progress tracking.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/import/start',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'start_import' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Get import progress status by import_id.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/import/progress',
				[
					[
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => [ $this->api, 'get_import_progress' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Get plugin global settings.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/settings/get',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'get_settings' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Fetch documentation from remote server for help page.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/docs/all',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'fetch_remote_docs' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Fetch notifications from remote server for admin notification popup.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/notifications/all',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'fetch_remote_notifications' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Update plugin global settings.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/settings/update',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'update_settings' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Get survey analytics overview data (insights + summary tabs).
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/analytics/overview',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'get_survey_overview_data' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Force refresh/recalculate survey analytics summary.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/analytics/refresh',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'refresh_survey_analytics_data' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Get text responses for Response Summary.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/analytics/text-responses',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'get_text_responses' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Autosave survey data to revisions table.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/autosave',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'autosave_survey' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Check if newer autosave revision exists.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/autosave-status',
				[
					[
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => [ $this->api, 'get_autosave_status' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Restore survey from a specific revision.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/restore-revision',
				[
					[
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => [ $this->api, 'restore_revision' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);

			/**
			 * Get revision history list for a survey.
			 * Admin-only: Requires manage_options capability.
			 */
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/revisions',
				[
					[
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => [ $this->api, 'get_revisions' ],
						'permission_callback' => [ $this, 'check_manage_permission' ],
					],
				]
			);
		}
	}
}

/** load */
SurveyX_Admin_Routes::get_instance();
