<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Admin_Routes', false ) ) {
	class SurveyX_Admin_Routes {

		/**
		 * Singleton instance.
		 *
		 * @var SurveyX_Admin_Routes
		 */
		private static $instance;

		public const ROUTE_NAMESPACE = SURVEYX_REST_NAMESPACE;
		protected $api;

		/**
		 * Get the singleton instance.
		 *
		 * @return SurveyX_Admin_Routes
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
		public function __construct() {
			self::$instance = $this;
			$this->api      = SurveyX_Admin_API::get_instance();

			add_action( 'rest_api_init', [ $this, 'register_rest_routes' ], 10 );
		}

		/**
		 * Permission callback for every route in this file: 'manage_options'
		 * (administrator). None of these endpoints is public — no route here may
		 * swap this for __return_true.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
		 */
		public function check_manage_permission( WP_REST_Request $request ) {
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
		 * Registers the admin REST routes. Every one is admin-only: check_manage_permission()
		 * is the permission_callback throughout.
		 *
		 * @return void
		 */
		public function register_rest_routes() {
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/data',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'get_survey_editor_data' ],
					'permission_callback' => [ $this, 'check_manage_permission' ],
				]
			);

			// Lightweight header fields for the dashboard list, not the full editor payload.
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/header-info',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'get_survey_header_info' ],
					'permission_callback' => [ $this, 'check_manage_permission' ],
				]
			);

			// Returns the FULL survey row, not just its settings blob.
			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/settings',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'get_survey_settings' ],
					'permission_callback' => [ $this, 'check_manage_permission' ],
				]
			);

			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/list',
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this->api, 'get_surveys_paginated' ],
					'permission_callback' => [ $this, 'check_manage_permission' ],
				]
			);

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

			// Permanent — there is no trash step.
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

			// Toggles survey status (publish / unpublish) only.
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

			register_rest_route(
				self::ROUTE_NAMESPACE,
				'/admin/survey/update',
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this->api, 'update_survey' ],
					'permission_callback' => [ $this, 'check_manage_permission' ],
				]
			);

			// Outbound HTTP to surveyx.co.
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

			// Starts a tracked import; /admin/import/progress polls it.
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

			// Plugin-wide settings, not the per-survey settings above.
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

			// Feeds the Insights and Summary tabs.
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

			// Forces recalculation, bypassing the cached summary.
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

			// Writes to the revisions table, not to the survey row.
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

			// Reports whether a revision is newer than the loaded survey.
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

SurveyX_Admin_Routes::get_instance();
